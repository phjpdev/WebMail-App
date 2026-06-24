<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\HtmlSanitizer;
use App\Services\AliasService;
use App\Services\FilterService;
use App\Services\FolderCache;
use App\Services\ImapService;
use App\Services\MailCacheService;
use App\Services\SmtpService;

class ComposeController
{
    private const DRAFT_KEY = '_compose_draft';
    private const FORWARD_KEY = '_forward_attachments';
    private const MAX_ATTACHMENTS = 5;
    private const MAX_ATTACHMENT_BYTES = 10485760;

    public function compose(): void
    {
        requireAuth();
        releaseSessionLock();
        $this->showForm('compose', $this->defaultComposeData());
    }

    public function reply(): void
    {
        requireAuth();
        releaseSessionLock();
        $this->loadMessageForm('reply');
    }

    public function replyAll(): void
    {
        requireAuth();
        releaseSessionLock();
        $this->loadMessageForm('reply-all');
    }

    public function forward(): void
    {
        requireAuth();
        releaseSessionLock();
        $this->loadMessageForm('forward');
    }

    public function saveDraft(): void
    {
        requireAuth();
        verify_csrf_or_fail();
        releaseSessionLock();

        $fromEmail = trim($_POST['from_email'] ?? config('mail')['mailbox_email']);
        $to = trim($_POST['to'] ?? '');
        $cc = trim($_POST['cc'] ?? '');
        $subject = trim($_POST['subject'] ?? '');
        $body = trim($_POST['body'] ?? '');
        $bodyHtml = trim($_POST['body_html'] ?? '');

        $raw = $this->buildDraftRaw($fromEmail, $to, $cc, $subject, $body, $bodyHtml);

        $imap = new ImapService();
        if (!$imap->connect()) {
            $this->composeFail('Could not save draft: ' . $imap->getLastError());
        }

        $draftFolder = $this->resolveDraftsFolder();
        if ($imap->appendMessage($draftFolder, $raw)) {
            FolderCache::refreshPaths([$draftFolder]);
            if (wants_json()) {
                json_response([
                    'ok' => true,
                    'message' => 'Draft saved.',
                    'unread_counts' => FolderCache::load(skipUnreadRefresh: true)['unread_counts'] ?? [],
                ]);
            }
            flash('success', 'Draft saved.');
        } else {
            $this->composeFail('Could not save draft: ' . $imap->getLastError());
        }

        redirect('compose');
    }

    public function send(): void
    {
        requireAuth();
        verify_csrf_or_fail();
        releaseSessionLock();

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
        $returnFolder = decode_folder_path($_POST['return_folder'] ?? '');
        $draftUid = (int) ($_POST['draft_uid'] ?? 0);

        if ($folderPath !== '') {
            assert_folder_access($folderPath);
        }

        $body = $this->appendSignature($body);
        if ($bodyHtml !== '') {
            $bodyHtml = $this->appendSignatureHtml($bodyHtml);
        }

        $draft = compact('to', 'cc', 'bcc', 'subject', 'body', 'fromEmail', 'mode', 'folderPath', 'uid');
        $draft['body_html'] = $bodyHtml;

        if ($subject === '' || ($body === '' && $bodyHtml === '')) {
            $this->composeFail('Subject and body are required.', $draft, $mode, $folderPath, $uid);
        }

        $toParsed = parse_email_list($to);
        if ($toParsed['valid'] === []) {
            $this->composeFail('At least one valid To address is required.', $draft, $mode, $folderPath, $uid);
        }

        foreach ([['Cc', $cc], ['Bcc', $bcc]] as [$label, $value]) {
            $parsed = parse_email_list($value);
            if ($parsed['invalid'] !== []) {
                $this->composeFail("Invalid {$label} address: " . implode(', ', $parsed['invalid']), $draft, $mode, $folderPath, $uid);
            }
        }

        $aliasService = new AliasService();
        if (!$this->isValidFrom($aliasService, $fromEmail)) {
            $this->composeFail('Invalid send-as address.', $draft, $mode, $folderPath, $uid);
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
            $this->composeFail($attachments['error'], $draft, $mode, $folderPath, $uid);
        }
        if ($attachments['files'] !== []) {
            $options['attachments'] = $attachments['files'];
        }

        // Re-attach the original attachments when forwarding.
        if ($mode === 'forward') {
            $forwarded = $this->collectForwardAttachments();
            if ($forwarded !== []) {
                $options['raw_attachments'] = $forwarded;
            }
        }

        $smtp = new SmtpService();
        if ($smtp->send($options)) {
            unset($_SESSION[self::DRAFT_KEY], $_SESSION[self::FORWARD_KEY]);

            $sentFolder = $this->resolveSentFolder();
            $sentMime = $smtp->getLastMime();
            $contextFolder = compose_context_folder($returnFolder, $folderPath, $fromEmail);
            $unreadCounts = FolderCache::load(skipUnreadRefresh: true)['unread_counts'] ?? [];

            $afterSend = function () use ($fromEmail, $returnFolder, $folderPath, $sentFolder, $sentMime): void {
                $this->deleteSourceDraftIfRequested();
                $this->saveToSent($sentFolder, $sentMime);
                $this->syncMailboxAfterSend($fromEmail, $returnFolder, $folderPath, $sentFolder);
            };

            if (wants_json()) {
                json_response_then([
                    'ok' => true,
                    'message' => 'Email sent successfully.',
                    'return_folder' => $contextFolder !== ''
                        ? encode_folder_path($contextFolder)
                        : '',
                    'draft_uid' => $draftUid > 0 ? $draftUid : null,
                    'unread_counts' => $unreadCounts,
                ], $afterSend);
            }

            flash('success', 'Email sent successfully.');
            $redirectFolder = $contextFolder !== '' ? $contextFolder : $sentFolder;
            redirect_then('folder/' . encode_folder_path($redirectFolder), $afterSend);
        }

        $this->composeFail($this->friendlySendError($smtp->getLastError()), $draft, $mode, $folderPath, $uid);
    }

    /**
     * Translate raw SMTP errors into actionable, user-friendly messages. The
     * underlying error is still logged by SmtpService for diagnostics.
     */
    private function friendlySendError(string $error): string
    {
        $lower = strtolower($error);

        if (str_contains($lower, 'spam') || str_contains($lower, '550')) {
            return 'The mail server rejected this message as spam. Try editing the subject and message text (remove spammy wording or quoted spam), then send again.';
        }

        if (str_contains($lower, 'authenticat') || str_contains($lower, '535')) {
            return 'Could not sign in to the mail server. Please check the mailbox credentials in the server settings.';
        }

        if (
            str_contains($lower, 'connect')
            || str_contains($lower, 'timed out')
            || str_contains($lower, 'timeout')
        ) {
            return 'Could not reach the mail server. Please check your connection and try again.';
        }

        if (str_contains($lower, 'recipient') || str_contains($lower, '5.1.1') || str_contains($lower, '550 5.1.1')) {
            return 'The recipient address was rejected by the mail server. Please check the To/Cc addresses.';
        }

        return 'Failed to send email. ' . ($error !== '' ? 'Mail server said: ' . $error : 'Please try again.');
    }

    private function loadMessageForm(string $mode): void
    {
        $folderPath = mail_folder_path((string) ($_GET['folder'] ?? ''));
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
        assert_folder_access($folderPath);

        $message = MailCacheService::getBody($folderPath, $uid);
        if ($message === null) {
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
            MailCacheService::saveBody($folderPath, $message);
        }

        $aliasService = new AliasService();
        $quoted = $this->buildQuotedBody($message);
        // Default the From to the alias the message was received on, falling back
        // to the logged-in user's own alias when nothing matches.
        $replyFrom = $aliasService->resolveReplyAlias($message['delivered_to'] ?? null, $message['to'] ?? null)
            ?? $aliasService->userAlias(Auth::user()['id'] ?? null);

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

        $subject = $this->cleanSubject($message['subject'] ?? '');
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

        // Remember the original message's attachments so they can be re-attached
        // when the forward is actually sent.
        $this->rememberForwardAttachments($folderPath, $uid, $message['attachments'] ?? []);

        $this->showForm('forward', [
            'to' => '',
            'cc' => '',
            'bcc' => '',
            'subject' => $subject,
            'body' => $forwardHeader . ($message['plain'] ?? strip_tags($message['html'] ?? '')),
            'from_email' => $replyFrom,
            'folderPath' => $folderPath,
            'uid' => $uid,
        ]);
    }

    public function editDraft(): void
    {
        requireAuth();
        releaseSessionLock();

        $folderPath = decode_folder_path($_GET['folder'] ?? '');
        $uid = (int) ($_GET['uid'] ?? 0);

        if ($folderPath === '' || $uid <= 0) {
            flash('error', 'Draft not specified.');
            redirect('compose');
        }
        assert_folder_access($folderPath);

        $imap = new ImapService();
        if (!$imap->connect()) {
            flash('error', $imap->getLastError());
            redirect('folder/' . encode_folder_path($folderPath));
        }

        $message = $imap->getMessageByUid($folderPath, $uid);
        if ($message === null) {
            flash('error', 'Draft not found.');
            redirect('folder/' . encode_folder_path($folderPath));
        }

        $this->showForm('compose', [
            'to' => $message['to'] ?? '',
            'cc' => $message['cc'] ?? '',
            'bcc' => '',
            'subject' => $message['subject'] ?? '',
            'body' => $message['plain'] ?? '',
            'body_html' => $message['html'] ?? '',
            'from_email' => (new AliasService())->userAlias(Auth::user()['id'] ?? null),
            // Carry the source draft so it can be removed once re-sent.
            'draftFolder' => $folderPath,
            'draftUid' => $uid,
        ]);
    }

    /**
     * @param list<array{id: string, filename: string, size: int, mime: string}> $attachments
     */
    private function rememberForwardAttachments(string $folderPath, int $uid, array $attachments): void
    {
        if ($attachments === []) {
            unset($_SESSION[self::FORWARD_KEY]);

            return;
        }

        $_SESSION[self::FORWARD_KEY] = [
            'folder' => $folderPath,
            'uid' => $uid,
            'parts' => array_map(fn ($a) => [
                'id' => $a['id'],
                'filename' => $a['filename'],
                'mime' => $a['mime'],
            ], $attachments),
        ];
    }

    /**
     * Fetch any remembered forwarded attachments from IMAP for re-sending.
     *
     * @return list<array{content: string, name: string, mime: string}>
     */
    private function collectForwardAttachments(): array
    {
        $ref = $_SESSION[self::FORWARD_KEY] ?? null;
        if (!is_array($ref) || empty($ref['parts'])) {
            return [];
        }

        $imap = new ImapService();
        if (!$imap->connect()) {
            return [];
        }

        $out = [];
        foreach ($ref['parts'] as $part) {
            $attachment = $imap->getAttachment($ref['folder'], (int) $ref['uid'], (string) $part['id']);
            if ($attachment === null) {
                continue;
            }
            $out[] = [
                'content' => $attachment['content'],
                'name' => $part['filename'] ?? ($attachment['filename'] ?? 'attachment'),
                'mime' => $part['mime'] ?? ($attachment['mime'] ?? 'application/octet-stream'),
            ];
        }

        return $out;
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
        $subject = $this->cleanSubject($subject);

        return preg_match('/^Re:\s/i', $subject) ? $subject : 'Re: ' . $subject;
    }

    /**
     * Strip spam/scanner tags the mail server prepends to inbound subjects
     * (e.g. "***SPAM***", "[SPAM]", "[BULK]"). Carrying these into a reply or
     * forward subject causes the outbound spam filter to reject the message
     * with a 550 ("classified as SPAM"), so we remove them before composing.
     */
    private function cleanSubject(string $subject): string
    {
        $subject = trim($subject);

        do {
            $before = $subject;
            $subject = preg_replace(
                '/^\s*(\*{2,}\s*[A-Z]+\s*\*{2,}|\[(?:SPAM|BULK|SUSPECTED SPAM|VIRUS)\])\s*/i',
                '',
                $subject
            ) ?? $subject;
            $subject = trim($subject);
        } while ($subject !== $before);

        return $subject;
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
            'from_email' => (new AliasService())->userAlias(Auth::user()['id'] ?? null),
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
        $folderData = $this->isEmbedRequest()
            ? ['folders' => [], 'unread_counts' => []]
            : FolderCache::load(skipUnreadRefresh: true);
        $returnFolder = decode_folder_path($_GET['return_folder'] ?? '');
        if ($returnFolder === '') {
            $returnFolder = compose_context_folder(
                '',
                (string) ($defaults['folderPath'] ?? ''),
                (string) ($defaults['from_email'] ?? '')
            );
        }

        $viewData = array_merge([
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
            'draftFolder' => $defaults['draftFolder'] ?? '',
            'draftUid' => $defaults['draftUid'] ?? 0,
            'returnFolder' => $returnFolder !== '' ? encode_folder_path($returnFolder) : '',
            'forwardedAttachments' => ($mode === 'forward' && isset($_SESSION[self::FORWARD_KEY]['parts']))
                ? $_SESSION[self::FORWARD_KEY]['parts']
                : [],
            'folders' => $folderData['folders'],
            'unreadCounts' => $folderData['unread_counts'] ?? [],
            'activeFolder' => null,
            'success' => flash('success'),
            'error' => flash('error'),
            'embed' => $this->isEmbedRequest(),
        ], [
            'authUser' => Auth::user(),
            'prefs' => user_preferences(),
        ]);

        if ($this->isEmbedRequest()) {
            view('mail/compose-embed', $viewData);
            return;
        }

        view('mail/compose', $viewData);
    }

    private function isEmbedRequest(): bool
    {
        return ($_GET['embed'] ?? '') === '1';
    }

    /**
     * @param array<string, mixed> $draft
     */
    private function composeFail(string $message, ?array $draft = null, string $mode = 'compose', string $folderPath = '', int $uid = 0): never
    {
        if (wants_json()) {
            json_response(['ok' => false, 'error' => $message], 422);
        }

        flash('error', $message);
        if ($draft !== null) {
            $this->saveDraftSession($draft, $mode, $folderPath, $uid);
        }

        redirect('compose');
    }

    /**
     * @param array<string, mixed> $message
     */
    private function buildQuotedBody(array $message): string
    {
        $plain = rtrim($message['plain'] ?? strip_tags($message['html'] ?? ''));
        $lines = explode("\n", $plain);
        $quoted = array_map(fn ($line) => '> ' . $line, $lines);
        while ($quoted !== [] && trim($quoted[array_key_last($quoted)], '> ') === '') {
            array_pop($quoted);
        }

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

        // Only the Message-ID is needed here, so avoid downloading/parsing the
        // whole message body again.
        $headers = $imap->fetchFilterHeaders($folderPath, $uid);
        if ($headers !== null && !empty($headers['message_id'])) {
            $options['in_reply_to'] = $headers['message_id'];
            $options['references'] = $headers['message_id'];
        }
    }

    private function isValidFrom(AliasService $aliasService, string $fromEmail): bool
    {
        // The user's own alias is always allowed.
        if (strcasecmp($aliasService->userAlias(Auth::user()['id'] ?? null), $fromEmail) === 0) {
            return true;
        }

        // The shared mailbox address is always allowed.
        if (strcasecmp(config('mail')['mailbox_email'], $fromEmail) === 0) {
            return true;
        }

        // Only admins may send as any other configured alias; employees must not
        // be able to impersonate another person's address.
        $user = Auth::user();
        if ($user !== null && ($user['role'] ?? '') === 'admin') {
            foreach ($aliasService->listActive() as $alias) {
                if (strcasecmp($alias['email'], $fromEmail) === 0) {
                    return true;
                }
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
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

        $headers = "From: {$from}\r\n";
        if ($to !== '') {
            $headers .= "To: {$to}\r\n";
        }
        if ($cc !== '') {
            $headers .= "Cc: {$cc}\r\n";
        }
        $headers .= "Subject: {$encodedSubject}\r\n";
        $headers .= "Date: {$date}\r\n";
        $headers .= "Message-ID: {$msgId}\r\n";
        $headers .= "MIME-Version: 1.0\r\n";

        // Save HTML drafts as multipart/alternative so they re-open with full
        // formatting (plus a plain-text fallback), not as raw markup text.
        if ($bodyHtml !== '') {
            $boundary = 'draft-' . bin2hex(random_bytes(8));
            $plain = $body !== '' ? $body : trim(strip_tags($bodyHtml));
            $sanitizedHtml = HtmlSanitizer::sanitize($bodyHtml);

            $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n\r\n";
            $headers .= "--{$boundary}\r\n";
            $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $headers .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $headers .= chunk_split(base64_encode($plain)) . "\r\n";
            $headers .= "--{$boundary}\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            $headers .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $headers .= chunk_split(base64_encode($sanitizedHtml)) . "\r\n";
            $headers .= "--{$boundary}--\r\n";

            return $headers;
        }

        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $headers .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $headers .= chunk_split(base64_encode($body));

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

    private function resolveSentFolder(): string
    {
        foreach (FolderCache::load(skipUnreadRefresh: true)['folders'] as $folder) {
            if (str_contains(strtolower($folder['path']), 'sent')) {
                return $folder['path'];
            }
        }

        return 'INBOX.Sent';
    }

    /**
     * Save a copy of an outgoing message to the Sent folder (best effort).
     */
    private function saveToSent(string $sentFolder, string $mime): void
    {
        if ($mime === '') {
            return;
        }

        $imap = new ImapService();
        if (!$imap->connect()) {
            return;
        }

        if (!$imap->appendMessage($sentFolder, $mime, '\\Seen')) {
            app_log('Could not save sent copy: ' . $imap->getLastError());
        }
    }

    /**
     * Post-send sync (runs in background after the client gets a response).
     *
     * @return array{context_folder: string, sent_folder: string, unread_counts: array<string, int>}
     */
    private function syncMailboxAfterSend(string $fromEmail, string $returnFolder, string $folderPath, string $sentFolder): array
    {
        $contextFolder = compose_context_folder($returnFolder, $folderPath, $fromEmail);
        $inbox = (string) (config('app')['filter_source_folder'] ?? 'INBOX');
        $headerLimit = (int) (config('app')['mail_cache_post_send_limit'] ?? 30);

        $filterResult = FilterService::runBackground(true, 15);
        $routedPaths = is_array($filterResult['refresh_paths'] ?? null)
            ? $filterResult['refresh_paths']
            : [];

        $imap = new ImapService();
        if ($imap->connect()) {
            // Only clear self-sent copies from the filter inbox — not employee
            // folders where routed mail should stay unread for the recipient.
            $imap->clearRecentSelfSentCopies([$inbox], $fromEmail, 6);

            $replyUid = (int) ($_POST['uid'] ?? 0);
            $mode = (string) ($_POST['mode'] ?? '');
            if (
                in_array($mode, ['reply', 'reply-all'], true)
                && $folderPath !== ''
                && $replyUid > 0
                && FolderCache::canAccess($folderPath)
            ) {
                $imap->markSeen($folderPath, $replyUid);
                MailCacheService::updateIndexSeen($folderPath, $replyUid, true);
            }

            $pathsToSync = array_values(array_unique(array_filter(array_merge(
                [$inbox, $contextFolder, $sentFolder],
                $routedPaths
            ))));

            foreach ($pathsToSync as $path) {
                if ($path === $contextFolder && $contextFolder !== '') {
                    $imap->removeDuplicateDeliveries($contextFolder, 8);
                }
                try {
                    MailCacheService::syncFolderHeaders($imap, $path, $headerLimit);
                    MailCacheService::reconcileBadgeFromIndex($path);
                } catch (\Throwable $e) {
                    app_log('Post-send cache sync failed for ' . $path . ': ' . $e->getMessage());
                }
            }
        }

        return [
            'context_folder' => $contextFolder,
            'sent_folder' => $sentFolder,
            'unread_counts' => FolderCache::load(skipUnreadRefresh: true)['unread_counts'] ?? [],
        ];
    }

    private function deleteSourceDraftIfRequested(): void
    {
        $draftFolder = decode_folder_path($_POST['draft_folder'] ?? '');
        $draftUid = (int) ($_POST['draft_uid'] ?? 0);

        if ($draftFolder === '' || $draftUid <= 0 || !FolderCache::canAccess($draftFolder)) {
            return;
        }

        $imap = new ImapService();
        if ($imap->connect()) {
            $imap->moveMessage($draftFolder, $draftUid, trash_folder_path());
        }
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
        $embed = $this->isEmbedRequest() ? '&embed=1' : '';
        $return = $_GET['return_folder'] ?? '';
        $returnQuery = $return !== '' ? '&return_folder=' . urlencode($return) : '';

        if ($mode === 'reply') {
            redirect('compose/reply?folder=' . encode_folder_path($folderPath) . '&uid=' . $uid . $embed . $returnQuery);
        }

        if ($mode === 'reply-all') {
            redirect('compose/reply-all?folder=' . encode_folder_path($folderPath) . '&uid=' . $uid . $embed . $returnQuery);
        }

        if ($mode === 'forward') {
            redirect('compose/forward?folder=' . encode_folder_path($folderPath) . '&uid=' . $uid . $embed . $returnQuery);
        }

        redirect('compose' . ($embed !== '' ? '?embed=1' . $returnQuery : ''));
    }
}
