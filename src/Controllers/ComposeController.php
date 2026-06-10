<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\HtmlSanitizer;
use App\Services\AliasService;
use App\Services\FolderCache;
use App\Services\ImapService;
use App\Services\SmtpService;

class ComposeController
{
    private const DRAFT_KEY = '_compose_draft';
    private const MAX_ATTACHMENTS = 5;
    private const MAX_ATTACHMENT_BYTES = 10485760;

    public function compose(): void
    {
        requireAuth();
        $this->showForm('compose', $this->defaultComposeData());
    }

    public function reply(): void
    {
        requireAuth();
        $this->loadMessageForm('reply');
    }

    public function replyAll(): void
    {
        requireAuth();
        $this->loadMessageForm('reply-all');
    }

    public function forward(): void
    {
        requireAuth();
        $this->loadMessageForm('forward');
    }

    public function saveDraft(): void
    {
        requireAuth();
        verify_csrf_or_fail();

        $fromEmail = trim($_POST['from_email'] ?? config('mail')['mailbox_email']);
        $to = trim($_POST['to'] ?? '');
        $cc = trim($_POST['cc'] ?? '');
        $subject = trim($_POST['subject'] ?? '');
        $body = trim($_POST['body'] ?? '');
        $bodyHtml = trim($_POST['body_html'] ?? '');

        $raw = $this->buildDraftRaw($fromEmail, $to, $cc, $subject, $body, $bodyHtml);

        $imap = new ImapService();
        if (!$imap->connect()) {
            flash('error', 'Could not save draft: ' . $imap->getLastError());
            redirect('compose');
        }

        $draftFolder = $this->resolveDraftsFolder();
        if ($imap->appendMessage($draftFolder, $raw)) {
            flash('success', 'Draft saved.');
        } else {
            flash('error', 'Could not save draft: ' . $imap->getLastError());
        }

        redirect('compose');
    }

    public function send(): void
    {
        requireAuth();
        verify_csrf_or_fail();

        $mode = $_POST['mode'] ?? 'compose';
        $to = trim($_POST['to'] ?? '');
        $cc = trim($_POST['cc'] ?? '');
        $bcc = trim($_POST['bcc'] ?? '');
        $subject = trim($_POST['subject'] ?? '');
        $body = trim($_POST['body'] ?? '');
        $bodyHtml = trim($_POST['body_html'] ?? '');
        $fromEmail = trim($_POST['from_email'] ?? config('mail')['mailbox_email']);
        $folderPath = decode_folder_path($_POST['folder'] ?? '');
        $uid = (int) ($_POST['uid'] ?? 0);

        $body = $this->appendSignature($body);
        if ($bodyHtml !== '') {
            $bodyHtml = $this->appendSignatureHtml($bodyHtml);
        }

        $draft = compact('to', 'cc', 'bcc', 'subject', 'body', 'fromEmail', 'mode', 'folderPath', 'uid');
        $draft['body_html'] = $bodyHtml;

        if ($subject === '' || ($body === '' && $bodyHtml === '')) {
            flash('error', 'Subject and body are required.');
            $this->saveDraftSession($draft, $mode, $folderPath, $uid);
        }

        $toParsed = parse_email_list($to);
        if ($toParsed['valid'] === []) {
            flash('error', 'At least one valid To address is required.');
            $this->saveDraftSession($draft, $mode, $folderPath, $uid);
        }

        foreach ([['Cc', $cc], ['Bcc', $bcc]] as [$label, $value]) {
            $parsed = parse_email_list($value);
            if ($parsed['invalid'] !== []) {
                flash('error', "Invalid {$label} address: " . implode(', ', $parsed['invalid']));
                $this->saveDraftSession($draft, $mode, $folderPath, $uid);
            }
        }

        $aliasService = new AliasService();
        if (!$this->isValidFrom($aliasService, $fromEmail)) {
            flash('error', 'Invalid send-as address.');
            $this->saveDraftSession($draft, $mode, $folderPath, $uid);
        }

        $plainBody = $body !== '' ? $body : strip_tags($bodyHtml);
        $options = [
            'to' => implode(', ', $toParsed['valid']),
            'subject' => $subject,
            'body' => $plainBody,
            'from' => $fromEmail,
            'from_name' => $aliasService->getDisplayName($fromEmail),
        ];

        $ccParsed = parse_email_list($cc);
        if ($ccParsed['valid'] !== []) {
            $options['cc'] = implode(', ', $ccParsed['valid']);
        }

        $bccParsed = parse_email_list($bcc);
        if ($bccParsed['valid'] !== []) {
            $options['bcc'] = implode(', ', $bccParsed['valid']);
        }

        if ($bodyHtml !== '') {
            $options['html_body'] = HtmlSanitizer::sanitize($bodyHtml);
        }

        if (in_array($mode, ['reply', 'reply-all'], true) && $folderPath !== '' && $uid > 0) {
            $this->attachReplyHeaders($options, $folderPath, $uid);
        }

        $attachments = $this->collectAttachments();
        if ($attachments['error'] !== null) {
            flash('error', $attachments['error']);
            $this->saveDraftSession($draft, $mode, $folderPath, $uid);
        }
        if ($attachments['files'] !== []) {
            $options['attachments'] = $attachments['files'];
        }

        $smtp = new SmtpService();
        if ($smtp->send($options)) {
            unset($_SESSION[self::DRAFT_KEY]);
            flash('success', 'Email sent successfully.');
            redirect('folder/' . encode_folder_path('INBOX.Sent'));
        }

        flash('error', 'Failed to send email: ' . $smtp->getLastError());
        $this->saveDraftSession($draft, $mode, $folderPath, $uid);
    }

    private function loadMessageForm(string $mode): void
    {
        $folderPath = decode_folder_path($_GET['folder'] ?? '');
        $uid = (int) ($_GET['uid'] ?? 0);

        $draft = $this->peekDraft();
        if ($draft !== null && ($draft['mode'] ?? '') === $mode) {
            $this->showForm($mode, $draft);
            return;
        }

        if ($folderPath === '' || $uid <= 0) {
            flash('error', 'Message not specified.');
            redirect('folder/' . encode_folder_path('INBOX'));
        }

        $imap = new ImapService();
        if (!$imap->connect()) {
            flash('error', $imap->getLastError());
            redirect('folder/' . encode_folder_path($folderPath));
        }

        $message = $imap->getMessageByUid($folderPath, $uid);
        if ($message === null) {
            flash('error', 'Message not found.');
            redirect('folder/' . encode_folder_path($folderPath));
        }

        $aliasService = new AliasService();
        $quoted = $this->buildQuotedBody($message);
        $replyFrom = $aliasService->resolveReplyAlias(
            $message['delivered_to'] ?? null,
            $message['to'] ?? null
        );

        if ($mode === 'reply') {
            $subject = $this->replySubject($message['subject'] ?? '');
            $this->showForm('reply', [
                'to' => $message['from'] ?? '',
                'cc' => '',
                'bcc' => '',
                'subject' => $subject,
                'body' => "\n\n" . $quoted,
                'from_email' => $replyFrom,
                'folderPath' => $folderPath,
                'uid' => $uid,
            ]);
            return;
        }

        if ($mode === 'reply-all') {
            $subject = $this->replySubject($message['subject'] ?? '');
            $cc = $this->buildReplyAllCc($message, $replyFrom);
            $this->showForm('reply-all', [
                'to' => $message['from'] ?? '',
                'cc' => $cc,
                'bcc' => '',
                'subject' => $subject,
                'body' => "\n\n" . $quoted,
                'from_email' => $replyFrom,
                'folderPath' => $folderPath,
                'uid' => $uid,
            ]);
            return;
        }

        $subject = $message['subject'] ?? '';
        if (!preg_match('/^Fwd:\s/i', $subject)) {
            $subject = 'Fwd: ' . $subject;
        }

        $forwardHeader = sprintf(
            "---------- Forwarded message ----------\nFrom: %s\nDate: %s\nSubject: %s\nTo: %s\n\n",
            $message['from'] ?? '',
            $message['date'] ?? '',
            $message['subject'] ?? '',
            $message['to'] ?? ''
        );

        $this->showForm('forward', [
            'to' => '',
            'cc' => '',
            'bcc' => '',
            'subject' => $subject,
            'body' => $forwardHeader . ($message['plain'] ?? strip_tags($message['html'] ?? '')),
            'from_email' => config('mail')['mailbox_email'],
            'folderPath' => $folderPath,
            'uid' => $uid,
        ]);
    }

    /**
     * @param array<string, mixed> $message
     */
    private function buildReplyAllCc(array $message, string $replyFrom): string
    {
        $aliasService = new AliasService();
        $ownEmails = array_map(
            fn ($a) => strtolower($a['email']),
            $aliasService->listActive()
        );
        $ownEmails[] = strtolower(config('mail')['mailbox_email']);
        $ownEmails[] = strtolower($replyFrom);

        $recipients = [];
        foreach (parse_email_list(($message['to'] ?? '') . ',' . ($message['cc'] ?? ''))['valid'] as $email) {
            if (!in_array(strtolower($email), $ownEmails, true)) {
                $recipients[] = $email;
            }
        }

        return implode(', ', array_unique($recipients));
    }

    private function replySubject(string $subject): string
    {
        return preg_match('/^Re:\s/i', $subject) ? $subject : 'Re: ' . $subject;
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultComposeData(): array
    {
        return [
            'to' => '',
            'cc' => '',
            'bcc' => '',
            'subject' => '',
            'body' => '',
            'body_html' => '',
            'from_email' => config('mail')['mailbox_email'],
        ];
    }

    /**
     * @param array<string, mixed> $defaults
     */
    private function showForm(string $mode, array $defaults): void
    {
        $draft = $this->pullDraft();
        if ($draft !== null && ($draft['mode'] ?? '') === $mode) {
            $defaults = array_merge($defaults, $draft);
        }

        $aliasService = new AliasService();
        $folderData = FolderCache::load();

        view('mail/compose', array_merge([
            'title' => ucfirst(str_replace('-', ' ', $mode)),
            'mode' => $mode,
            'user' => Auth::user(),
            'aliases' => $aliasService->listActive(),
            'to' => $defaults['to'] ?? '',
            'cc' => $defaults['cc'] ?? '',
            'bcc' => $defaults['bcc'] ?? '',
            'subject' => $defaults['subject'] ?? '',
            'body' => $defaults['body'] ?? '',
            'body_html' => $defaults['body_html'] ?? '',
            'from_email' => $defaults['from_email'] ?? config('mail')['mailbox_email'],
            'folderPath' => $defaults['folderPath'] ?? '',
            'uid' => $defaults['uid'] ?? 0,
            'folders' => $folderData['folders'],
            'unreadCounts' => $folderData['unread_counts'] ?? [],
            'activeFolder' => null,
            'success' => flash('success'),
            'error' => flash('error'),
        ], [
            'authUser' => Auth::user(),
            'prefs' => user_preferences(),
        ]));
    }

    /**
     * @param array<string, mixed> $message
     */
    private function buildQuotedBody(array $message): string
    {
        $plain = $message['plain'] ?? strip_tags($message['html'] ?? '');
        $lines = explode("\n", $plain);
        $quoted = array_map(fn ($line) => '> ' . $line, $lines);

        return sprintf(
            "On %s, %s wrote:\n%s",
            $message['date'] ?? '',
            $message['from'] ?? '',
            implode("\n", $quoted)
        );
    }

    private function appendSignature(string $body): string
    {
        $user = Auth::user();
        $sig = trim($user['signature'] ?? '');
        if ($sig === '') {
            return $body;
        }

        return rtrim($body) . "\n\n--\n" . $sig;
    }

    private function appendSignatureHtml(string $html): string
    {
        $user = Auth::user();
        $sig = trim($user['signature'] ?? '');
        if ($sig === '') {
            return $html;
        }

        return rtrim($html) . '<br><br>--<br>' . nl2br(e($sig));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function attachReplyHeaders(array &$options, string $folderPath, int $uid): void
    {
        $imap = new ImapService();
        if (!$imap->connect()) {
            return;
        }

        $message = $imap->getMessageByUid($folderPath, $uid);
        if ($message !== null && !empty($message['message_id'])) {
            $options['in_reply_to'] = $message['message_id'];
            $options['references'] = $message['message_id'];
        }
    }

    private function isValidFrom(AliasService $aliasService, string $fromEmail): bool
    {
        foreach ($aliasService->listActive() as $alias) {
            if (strcasecmp($alias['email'], $fromEmail) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{files: list<array{path: string, name: string}>, error: string|null}
     */
    private function collectAttachments(): array
    {
        $files = [];
        $uploads = $_FILES['attachments'] ?? null;
        if ($uploads === null || !isset($uploads['name']) || !is_array($uploads['name'])) {
            return ['files' => [], 'error' => null];
        }

        $count = 0;
        foreach ($uploads['name'] as $i => $name) {
            if ($name === '' || ($uploads['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if (($uploads['error'][$i] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                return ['files' => [], 'error' => 'Attachment upload failed.'];
            }
            if (($uploads['size'][$i] ?? 0) > self::MAX_ATTACHMENT_BYTES) {
                return ['files' => [], 'error' => 'Each attachment must be under 10 MB.'];
            }
            $count++;
            if ($count > self::MAX_ATTACHMENTS) {
                return ['files' => [], 'error' => 'Maximum ' . self::MAX_ATTACHMENTS . ' attachments allowed.'];
            }
            $files[] = [
                'path' => $uploads['tmp_name'][$i],
                'name' => $name,
            ];
        }

        return ['files' => $files, 'error' => null];
    }

    private function buildDraftRaw(
        string $from,
        string $to,
        string $cc,
        string $subject,
        string $body,
        string $bodyHtml
    ): string {
        $date = date('r');
        $msgId = '<draft.' . bin2hex(random_bytes(8)) . '@webmail.local>';
        $headers = "From: {$from}\r\n";
        if ($to !== '') {
            $headers .= "To: {$to}\r\n";
        }
        if ($cc !== '') {
            $headers .= "Cc: {$cc}\r\n";
        }
        $headers .= "Subject: {$subject}\r\n";
        $headers .= "Date: {$date}\r\n";
        $headers .= "Message-ID: {$msgId}\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $content = $bodyHtml !== '' ? $bodyHtml : $body;
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
        $headers .= $content;

        return $headers;
    }

    private function resolveDraftsFolder(): string
    {
        foreach (FolderCache::load()['folders'] as $folder) {
            if (str_contains(strtolower($folder['path']), 'draft')) {
                return $folder['path'];
            }
        }

        return 'INBOX.Drafts';
    }

    /**
     * @param array<string, mixed> $draft
     */
    private function saveDraftSession(array $draft, string $mode, string $folderPath, int $uid): void
    {
        $draft['mode'] = $mode;
        $draft['folderPath'] = $folderPath;
        $draft['uid'] = $uid;
        $_SESSION[self::DRAFT_KEY] = $draft;
        $this->redirectBack($mode, $folderPath, $uid);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function peekDraft(): ?array
    {
        $draft = $_SESSION[self::DRAFT_KEY] ?? null;

        return is_array($draft) ? $draft : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function pullDraft(): ?array
    {
        $draft = $this->peekDraft();
        unset($_SESSION[self::DRAFT_KEY]);

        return $draft;
    }

    private function redirectBack(string $mode, string $folderPath, int $uid): never
    {
        if ($mode === 'reply') {
            redirect('compose/reply?folder=' . encode_folder_path($folderPath) . '&uid=' . $uid);
        }

        if ($mode === 'reply-all') {
            redirect('compose/reply-all?folder=' . encode_folder_path($folderPath) . '&uid=' . $uid);
        }

        if ($mode === 'forward') {
            redirect('compose/forward?folder=' . encode_folder_path($folderPath) . '&uid=' . $uid);
        }

        redirect('compose');
    }
}
