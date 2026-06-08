<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Services\FolderCache;
use App\Services\SmtpService;

class DashboardController
{
    public function status(): void
    {
        requireAuth();

        $folderData = FolderCache::load(refresh: true);
        $imapConnected = $folderData['connected'];
        $imapError = $folderData['error'];
        $folders = $folderData['folders'];

        view('status', [
            'title' => 'Connection Status',
            'user' => Auth::user(),
            'imapConnected' => $imapConnected,
            'imapError' => $imapError,
            'folderCount' => count($folders),
            'folders' => $folders,
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
