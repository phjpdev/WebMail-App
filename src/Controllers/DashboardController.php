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
        requireAdmin();

        // Instant page: use session folder cache — live IMAP test runs via AJAX.
        $folderData = FolderCache::load(skipUnreadRefresh: true);
        $lastCheck = $_SESSION['_imap_status_check'] ?? null;

        view('status', [
            'title' => 'Connection Status',
            'user' => Auth::user(),
            'authUser' => Auth::user(),
            'sessionUser' => Auth::user(),
            'imapConnected' => (bool) ($lastCheck['connected'] ?? $folderData['connected']),
            'imapError' => (string) ($lastCheck['error'] ?? $folderData['error'] ?? ''),
            'folderCount' => (int) ($lastCheck['folder_count'] ?? count($folderData['folders'])),
            'folders' => $folderData['folders'],
            'sidebarFolders' => $folderData['folders'],
            'unreadCounts' => $folderData['unread_counts'] ?? [],
            'activeFolder' => '__status__',
            'lastCheckedAt' => isset($lastCheck['at']) ? (int) $lastCheck['at'] : null,
            'lastCheckMs' => isset($lastCheck['ms']) ? (int) $lastCheck['ms'] : null,
            'success' => flash('success'),
            'error' => flash('error'),
            'prefs' => user_preferences(),
        ]);
    }

    public function statusCheck(): void
    {
        requireAdmin();

        $start = microtime(true);
        $folderData = FolderCache::load(refresh: true);
        $durationMs = (int) round((microtime(true) - $start) * 1000);

        $_SESSION['_imap_status_check'] = [
            'at' => time(),
            'connected' => $folderData['connected'],
            'error' => $folderData['error'],
            'folder_count' => count($folderData['folders']),
            'ms' => $durationMs,
        ];

        releaseSessionLock();

        json_response([
            'ok' => true,
            'imap_connected' => $folderData['connected'],
            'imap_error' => $folderData['error'],
            'folder_count' => count($folderData['folders']),
            'duration_ms' => $durationMs,
            'checked_at' => date('g:i:s A'),
            'unread_counts' => $folderData['unread_counts'] ?? [],
        ]);
    }

    public function sendTestEmail(): void
    {
        requireAdmin();
        verify_csrf_or_fail();

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
