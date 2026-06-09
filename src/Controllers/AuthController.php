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
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username === '' || $password === '') {
            flash('error', 'Username and password are required.');
            redirect('login');
        }

        if (!Auth::login($username, $password)) {
            $dbName = env('DB_NAME', '');
            if ($dbName === '' || $dbName === 'dj_webmail') {
                flash('error', 'Server error: .env file missing. On the server the file must be named .env (not .env.production).');
            } else {
                flash('error', 'Login failed. If the password is correct, check database credentials in .env (see check-setup.php on the server).');
            }
            redirect('login');
        }

        redirect('');
    }

    public function logout(): void
    {
        Auth::logout();
        flash('success', 'You have been logged out.');
        redirect('login');
    }
}
