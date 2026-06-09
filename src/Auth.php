<?php

declare(strict_types=1);

namespace App;

class Auth
{
    private const SESSION_USER_KEY = 'auth_user';
    private const SESSION_LAST_ACTIVITY = 'auth_last_activity';

    public static function login(string $username, string $password): bool
    {
        try {
            $user = Database::fetchOne(
                'SELECT id, name, username, password_hash, role, active FROM users WHERE username = ? LIMIT 1',
                [$username]
            );
        } catch (\Throwable $e) {
            app_log('Login database error: ' . $e->getMessage());
            return false;
        }

        if ($user === null || !(int) $user['active']) {
            return false;
        }

        if (!password_verify($password, $user['password_hash'])) {
            return false;
        }

        session_regenerate_id(true);

        $_SESSION[self::SESSION_USER_KEY] = [
            'id' => (int) $user['id'],
            'name' => $user['name'],
            'username' => $user['username'],
            'role' => $user['role'],
        ];
        $_SESSION[self::SESSION_LAST_ACTIVITY] = time();

        try {
            Database::query(
                'INSERT INTO audit_log (user_id, action, details) VALUES (?, ?, ?)',
                [(int) $user['id'], 'login', 'User logged in']
            );
        } catch (\Throwable $e) {
            app_log('Audit log failed on login: ' . $e->getMessage());
        }

        return true;
    }

    public static function logout(): void
    {
        // Read session directly — user() calls checkSessionTimeout() which calls logout().
        $userId = $_SESSION[self::SESSION_USER_KEY]['id'] ?? null;

        if ($userId !== null) {
            try {
                Database::query(
                    'INSERT INTO audit_log (user_id, action, details) VALUES (?, ?, ?)',
                    [$userId, 'logout', 'User logged out']
                );
            } catch (\Throwable $e) {
                app_log('Audit log failed on logout: ' . $e->getMessage());
            }
        }

        unset($_SESSION['_filter_ran'], $_SESSION['_last_filter_stats']);

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                (bool) $params['secure'],
                (bool) $params['httponly']
            );
        }

        session_destroy();
    }

    public static function user(): ?array
    {
        self::checkSessionTimeout();

        return $_SESSION[self::SESSION_USER_KEY] ?? null;
    }

    public static function isLoggedIn(): bool
    {
        return self::user() !== null;
    }

    public static function isAdmin(): bool
    {
        $user = self::user();

        return $user !== null && $user['role'] === 'admin';
    }

    private static function checkSessionTimeout(): void
    {
        if (!isset($_SESSION[self::SESSION_USER_KEY])) {
            return;
        }

        $lifetime = config('app')['session_lifetime'];
        $lastActivity = $_SESSION[self::SESSION_LAST_ACTIVITY] ?? time();

        if (time() - $lastActivity > $lifetime) {
            self::logout();
            return;
        }

        $_SESSION[self::SESSION_LAST_ACTIVITY] = time();
    }
}
