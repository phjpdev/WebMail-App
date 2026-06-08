<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Services\ImapService;
use App\Services\SmtpService;

class DashboardController
{
    public function status(): void
    {
        requireAuth();

        $imap = new ImapService();
        $imapConnected = $imap->connect();
        $imapError = $imap->getLastError();
        $folderCount = 0;

        if ($imapConnected) {
            $folderCount = count($imap->listFolders());
        }

        view('status', [
            'title' => 'Connection Status',
            'user' => Auth::user(),
            'imapConnected' => $imapConnected,
            'imapError' => $imapError,
            'folderCount' => $folderCount,
            'folders' => $imapConnected ? $imap->listFolders() : [],
            'activeFolder' => null,
            'success' => flash('success'),
            'error' => flash('error'),
        ]);
    }

    public function sendTestEmail(): void
    {
        requireAdmin();

        $config = config('mail');
        $to = $config['test_email_to'] !== ''
            ? $config['test_email_to']
            : $config['mailbox_email'];

        $smtp = new SmtpService();

        if ($smtp->sendTest($to)) {
            flash('success', 'Test email sent successfully to ' . $to . '.');
        } else {
            flash('error', 'Failed to send test email: ' . $smtp->getLastError());
        }

        redirect('status');
    }
}
