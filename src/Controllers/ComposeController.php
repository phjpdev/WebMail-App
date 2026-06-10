<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Services\AliasService;
use App\Services\FolderCache;
use App\Services\ImapService;
use App\Services\SmtpService;

class ComposeController
{
    private const DRAFT_KEY = '_compose_draft';

    public function compose(): void
    {
        requireAuth();
        $this->showForm('compose', [
            'to' => '',
            'cc' => '',
            'bcc' => '',
            'subject' => '',
            'body' => '',
            'from_email' => config('mail')['mailbox_email'],
        ]);
    }

    public function reply(): void
    {
        requireAuth();
        $this->loadMessageForm('reply');
    }

    public function forward(): void
    {
        requireAuth();
        $this->loadMessageForm('forward');
    }

    public function send(): void
    {
        requireAuth();

        $mode = $_POST['mode'] ?? 'compose';
        $to = trim($_POST['to'] ?? '');
        $cc = trim($_POST['cc'] ?? '');
        $bcc = trim($_POST['bcc'] ?? '');
        $subject = trim($_POST['subject'] ?? '');
        $body = trim($_POST['body'] ?? '');
        $fromEmail = trim($_POST['from_email'] ?? config('mail')['mailbox_email']);
        $folderPath = decode_folder_path($_POST['folder'] ?? '');
        $uid = (int) ($_POST['uid'] ?? 0);

        $draft = [
            'to' => $to,
            'cc' => $cc,
            'bcc' => $bcc,
            'subject' => $subject,
            'body' => $body,
            'from_email' => $fromEmail,
            'mode' => $mode,
            'folderPath' => $folderPath,
            'uid' => $uid,
        ];

        if ($subject === '' || $body === '') {
            flash('error', 'Subject and body are required.');
            $this->saveDraft($draft);
            $this->redirectBack($mode, $folderPath, $uid);
        }

        $toParsed = parse_email_list($to);
        if ($toParsed['valid'] === []) {
            flash('error', 'At least one valid To address is required.');
            $this->saveDraft($draft);
            $this->redirectBack($mode, $folderPath, $uid);
        }

        if ($toParsed['invalid'] !== []) {
            flash('error', 'Invalid To address: ' . implode(', ', $toParsed['invalid']));
            $this->saveDraft($draft);
            $this->redirectBack($mode, $folderPath, $uid);
        }

        $ccParsed = parse_email_list($cc);
        if ($ccParsed['invalid'] !== []) {
            flash('error', 'Invalid Cc address: ' . implode(', ', $ccParsed['invalid']));
            $this->saveDraft($draft);
            $this->redirectBack($mode, $folderPath, $uid);
        }

        $bccParsed = parse_email_list($bcc);
        if ($bccParsed['invalid'] !== []) {
            flash('error', 'Invalid Bcc address: ' . implode(', ', $bccParsed['invalid']));
            $this->saveDraft($draft);
            $this->redirectBack($mode, $folderPath, $uid);
        }

        $aliasService = new AliasService();
        $validFrom = false;
        foreach ($aliasService->listActive() as $alias) {
            if (strcasecmp($alias['email'], $fromEmail) === 0) {
                $validFrom = true;
                break;
            }
        }

        if (!$validFrom) {
            flash('error', 'Invalid send-as address.');
            $this->saveDraft($draft);
            $this->redirectBack($mode, $folderPath, $uid);
        }

        $options = [
            'to' => implode(', ', $toParsed['valid']),
            'subject' => $subject,
            'body' => $body,
            'from' => $fromEmail,
            'from_name' => $aliasService->getDisplayName($fromEmail),
        ];

        if ($ccParsed['valid'] !== []) {
            $options['cc'] = implode(', ', $ccParsed['valid']);
        }

        if ($bccParsed['valid'] !== []) {
            $options['bcc'] = implode(', ', $bccParsed['valid']);
        }

        if ($mode === 'reply' && $folderPath !== '' && $uid > 0) {
            $imap = new ImapService();
            if ($imap->connect()) {
                $message = $imap->getMessageByUid($folderPath, $uid);
                if ($message !== null && !empty($message['message_id'])) {
                    $options['in_reply_to'] = $message['message_id'];
                    $options['references'] = $message['message_id'];
                }
            }
        }

        $smtp = new SmtpService();
        if ($smtp->send($options)) {
            unset($_SESSION[self::DRAFT_KEY]);
            flash('success', 'Email sent successfully.');
            redirect('folder/' . encode_folder_path('INBOX.Sent'));
        }

        flash('error', 'Failed to send email: ' . $smtp->getLastError());
        $this->saveDraft($draft);
        $this->redirectBack($mode, $folderPath, $uid);
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

        if ($mode === 'reply') {
            $subject = $message['subject'] ?? '';
            if (!preg_match('/^Re:\s/i', $subject)) {
                $subject = 'Re: ' . $subject;
            }

            $this->showForm('reply', [
                'to' => $message['from'] ?? '',
                'cc' => '',
                'bcc' => '',
                'subject' => $subject,
                'body' => "\n\n" . $quoted,
                'from_email' => $aliasService->resolveReplyAlias(
                    $message['delivered_to'] ?? null,
                    $message['to'] ?? null
                ),
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
     * @param array<string, mixed> $defaults
     */
    private function showForm(string $mode, array $defaults): void
    {
        $draft = $this->pullDraft();
        if ($draft !== null && ($draft['mode'] ?? '') === $mode) {
            $defaults = array_merge($defaults, $draft);
        }

        $aliasService = new AliasService();

        view('mail/compose', [
            'title' => ucfirst($mode),
            'mode' => $mode,
            'user' => Auth::user(),
            'aliases' => $aliasService->listActive(),
            'to' => $defaults['to'] ?? '',
            'cc' => $defaults['cc'] ?? '',
            'bcc' => $defaults['bcc'] ?? '',
            'subject' => $defaults['subject'] ?? '',
            'body' => $defaults['body'] ?? '',
            'from_email' => $defaults['from_email'] ?? config('mail')['mailbox_email'],
            'folderPath' => $defaults['folderPath'] ?? '',
            'uid' => $defaults['uid'] ?? 0,
            'folders' => $this->loadFolders(),
            'activeFolder' => null,
            'success' => flash('success'),
            'error' => flash('error'),
        ]);
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

    /**
     * @return list<array{path: string, name: string}>
     */
    private function loadFolders(): array
    {
        return FolderCache::load()['folders'];
    }

    /**
     * @param array<string, mixed> $draft
     */
    private function saveDraft(array $draft): void
    {
        $_SESSION[self::DRAFT_KEY] = $draft;
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

        if ($mode === 'forward') {
            redirect('compose/forward?folder=' . encode_folder_path($folderPath) . '&uid=' . $uid);
        }

        redirect('compose');
    }
}
