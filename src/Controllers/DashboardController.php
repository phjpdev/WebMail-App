<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Services\ImapService;
use App\Services\SmtpService;

class DashboardController
{
    public function index(): void
    {
        requireAuth();

        $imap = new ImapService();
        $imapConnected = $imap->connect();
        $imapError = $imap->getLastError();
        $folders = [];
        $sampleHeaders = null;

        if ($imapConnected) {
            $folders = $imap->listFolders();

            foreach ($folders as &$folder) {
                $folder['count'] = $imap->getMessageCount($folder['path']);
            }
            unset($folder);

            $inboxCount = $imap->getMessageCount('INBOX');
            if ($inboxCount > 0) {
                $sampleHeaders = $imap->fetchMessageHeaders('INBOX', $inboxCount);
            }
        }

        view('dashboard', [
            'title' => 'Dashboard',
            'user' => Auth::user(),
            'imapConnected' => $imapConnected,
            'imapError' => $imapError,
            'folders' => $folders,
            'sampleHeaders' => $sampleHeaders,
            'success' => flash('success'),
            'error' => flash('error'),
        ]);
    }

    public function sendTestEmail(): void
    {
        requireAdmin();

        $config = config('mail');
        $user = Auth::user();
        $to = $config['test_email_to'] !== ''
            ? $config['test_email_to']
            : $config['mailbox_email'];

        $smtp = new SmtpService();

        if ($smtp->sendTest($to)) {
            flash('success', 'Test email sent successfully to ' . $to . '.');
        } else {
            flash('error', 'Failed to send test email: ' . $smtp->getLastError());
        }

        redirect('');
    }
}
