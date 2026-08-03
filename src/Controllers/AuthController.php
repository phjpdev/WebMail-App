<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;

class AuthController
{
    public function showLogin(): void
    {
        if (Auth::isLoggedIn()) {
            redirect('');
        }

        $notice = null;
        if (($_GET['reason'] ?? '') === 'idle') {
            $hours = max(1, (int) round(((int) config('app')['session_lifetime']) / 3600));
            $notice = 'You were signed out after ' . $hours . ' hour' . ($hours === 1 ? '' : 's')
                . ' of inactivity. Please sign in again.';
        }

        view('login', [
            'title' => 'Login',
            'error' => flash('error'),
            'success' => $notice,
        ]);
    }

    public function login(): void
    {
        verify_csrf_or_fail();

        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username === '' || $password === '') {
            flash('error', 'Username and password are required.');
            redirect('login');
        }

        if (\App\Services\LoginRateLimit::isBlocked(client_ip(), $username)) {
            flash('error', 'Too many failed attempts. Please try again in 15 minutes.');
            redirect('login');
        }

        if (!Auth::login($username, $password)) {
            // Always show a generic message so we don't leak whether the username
            // exists or reveal server/config details to an attacker.
            $dbName = env('DB_NAME', '');
            if ($dbName === '' || $dbName === 'dj_webmail') {
                app_log('Login failed: DB_NAME not configured (.env missing or default).');
            }
            flash('error', 'Invalid username or password.');
            redirect('login');
        }

        if (Auth::mustChangePassword()) {
            redirect('change-password');
        }

        redirect('');
    }

    public function logout(): void
    {
        // Logout must ALWAYS succeed. Verifying CSRF here used to throw a 403
        // "Invalid security token" page whenever the token was stale (tab left open,
        // or the session was garbage-collected) — leaving the user stuck, unable to
        // sign out. A forced logout via CSRF is low-risk and the user is asking to
        // log out anyway, so we log out regardless of the token.
        $idle = ($_POST['reason'] ?? '') === 'idle';
        Auth::logout();
        // Clear the session cookie + client storage so a Back navigation can't reuse
        // an authenticated page. We deliberately do NOT clear "cache": that evicted
        // the whole HTTP cache and forced app.js/app.css (~600KB) to re-download on
        // the login page, which made logout feel slow.
        header('Clear-Site-Data: "cookies", "storage"');
        if ($idle) {
            // The login page explains the auto sign-out from the reason flag.
            redirect('login?reason=idle');
        }
        flash('success', 'You have been logged out.');
        redirect('login');
    }
}
