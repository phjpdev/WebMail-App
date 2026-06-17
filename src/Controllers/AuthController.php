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

        view('login', [
            'title' => 'Login',
            'error' => flash('error'),
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
        verify_csrf_or_fail();
        Auth::logout();
        // Clear bfcache/storage so pressing Back can't reveal cached authenticated
        // pages after logout (we deliberately allow bfcache for speed otherwise).
        header('Clear-Site-Data: "cache", "cookies", "storage"');
        flash('success', 'You have been logged out.');
        redirect('login');
    }
}
