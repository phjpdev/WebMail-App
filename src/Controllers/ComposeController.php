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
    public function compose(): void
    {
        requireAuth();
        $this->showForm('compose', [
            'to' => '',
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
        $subject = trim($_POST['subject'] ?? '');
        $body = trim($_POST['body'] ?? '');
        $fromEmail = trim($_POST['from_email'] ?? config('mail')['mailbox_email']);
        $folderPath = decode_folder_path($_POST['folder'] ?? '');
        $uid = (int) ($_POST['uid'] ?? 0);

        if ($to === '' || $subject === '' || $body === '') {
            flash('error', 'To, subject, and body are required.');
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
            $this->redirectBack($mode, $folderPath, $uid);
        }

        $options = [
            'to' => $to,
            'subject' => $subject,
            'body' => $body,
            'from' => $fromEmail,
            'from_name' => $aliasService->getDisplayName($fromEmail),
        ];

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
            flash('success', 'Email sent successfully.');
            redirect('folder/' . encode_folder_path('INBOX.Sent'));
        }

        flash('error', 'Failed to send email: ' . $smtp->getLastError());
        $this->redirectBack($mode, $folderPath, $uid);
    }

    private function loadMessageForm(string $mode): void
    {
        $folderPath = decode_folder_path($_GET['folder'] ?? '');
        $uid = (int) ($_GET['uid'] ?? 0);

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
        $aliasService = new AliasService();

        view('mail/compose', [
            'title' => ucfirst($mode),
            'mode' => $mode,
            'user' => Auth::user(),
            'aliases' => $aliasService->listActive(),
            'to' => $defaults['to'] ?? '',
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
