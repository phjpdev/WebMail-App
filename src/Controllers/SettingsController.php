<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Database;
use App\Services\FolderCache;

class SettingsController
{
    public function changePasswordForm(): void
    {
        if (!Auth::isLoggedIn()) {
            redirect('login');
        }

        view('change-password', [
            'title' => 'Change password',
            'error' => flash('error'),
            'success' => flash('success'),
            'required' => Auth::mustChangePassword(),
        ]);
    }

    public function changePassword(): void
    {
        if (!Auth::isLoggedIn()) {
            redirect('login');
        }

        verify_csrf_or_fail();

        // Trimmed to match the login side (see AuthController::login) — pasted
        // trailing whitespace otherwise creates passwords nobody can type.
        $current = trim($_POST['current_password'] ?? '');
        $new = trim($_POST['new_password'] ?? '');
        $confirm = trim($_POST['confirm_password'] ?? '');
        $user = Auth::user();

        if ($new === '' || strlen($new) < 8) {
            flash('error', 'New password must be at least 8 characters.');
            redirect('change-password');
        }

        if ($new !== $confirm) {
            flash('error', 'New passwords do not match.');
            redirect('change-password');
        }

        $row = Database::fetchOne(
            'SELECT password_hash, must_change_password FROM users WHERE id = ?',
            [$user['id']]
        );

        if ($row === null) {
            flash('error', 'User not found.');
            redirect('change-password');
        }

        if (!(int) $row['must_change_password'] && !password_verify($current, $row['password_hash'])) {
            flash('error', 'Current password is incorrect.');
            redirect('change-password');
        }

        Database::query(
            'UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?',
            [password_hash($new, PASSWORD_BCRYPT), $user['id']]
        );

        Auth::refreshUser();
        flash('success', 'Password updated successfully.');
        redirect('');
    }

    public function index(): void
    {
        requireAuth();
        $user = Auth::user();
        $prefs = user_preferences($user);

        view('settings/index', [
            'title' => 'Settings',
            'user' => $user,
            'prefs' => $prefs,
            'signature' => $user['signature'] ?? '',
            'success' => flash('success'),
            'error' => flash('error'),
        ]);
    }

    public function update(): void
    {
        requireAuth();
        verify_csrf_or_fail();

        $user = Auth::user();
        $name = trim($_POST['name'] ?? '');
        $signature = trim($_POST['signature'] ?? '');
        $prefs = [
            'poll_interval' => max(15, min(300, (int) ($_POST['poll_interval'] ?? 30))),
            'sound_enabled' => isset($_POST['sound_enabled']),
            'notify_enabled' => isset($_POST['notify_enabled']),
            'theme' => in_array($_POST['theme'] ?? 'light', ['light', 'dark', 'auto'], true)
                ? $_POST['theme']
                : 'light',
        ];

        if ($name === '') {
            flash('error', 'Name is required.');
            redirect('settings');
        }

        Database::query(
            'UPDATE users SET name = ?, signature = ?, preferences = ? WHERE id = ?',
            [$name, $signature !== '' ? $signature : null, json_encode($prefs), $user['id']]
        );

        Auth::refreshUser();
        flash('success', 'Settings saved.');
        redirect('settings');
    }

    private function render(string $view, array $data): void
    {
        $folderData = FolderCache::load(skipUnreadRefresh: true);
        $data['user'] = Auth::user();
        $data['authUser'] = Auth::user();
        $data['folders'] = $folderData['folders'];
        $data['activeFolder'] = '';
        $data['unreadCounts'] = $folderData['unread_counts'] ?? [];
        $data['success'] = flash('success');
        $data['error'] = flash('error');
        $data['prefs'] = user_preferences();

        ob_start();
        view($view, $data);
        $content = ob_get_clean();

        view('layout-standalone', array_merge($data, ['content' => $content]));
    }
}
