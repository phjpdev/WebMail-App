<?php

declare(strict_types=1);

namespace App;

use App\Services\LoginRateLimit;

class Auth
{
    private const SESSION_USER_KEY = 'auth_user';
    private const SESSION_LAST_ACTIVITY = 'auth_last_activity';

    public static function login(string $username, string $password): bool
    {
        $ip = client_ip();

        if (LoginRateLimit::isBlocked($ip, $username)) {
            app_log('Login blocked (rate limit): ' . $username . ' from ' . $ip);

            return false;
        }

        try {
            $columns = 'id, name, username, password_hash, role, active';
            if (schema_has_column('users', 'must_change_password')) {
                $columns .= ', must_change_password';
            }
            if (schema_has_column('users', 'signature')) {
                $columns .= ', signature';
            }
            if (schema_has_column('users', 'preferences')) {
                $columns .= ', preferences';
            }

            $user = Database::fetchOne(
                "SELECT {$columns} FROM users WHERE username = ? LIMIT 1",
                [$username]
            );
        } catch (\Throwable $e) {
            app_log('Login database error: ' . $e->getMessage());

            return false;
        }

        if ($user === null || !(int) $user['active']) {
            LoginRateLimit::recordFailure($ip, $username);

            return false;
        }

        if (!password_verify($password, $user['password_hash'])) {
            LoginRateLimit::recordFailure($ip, $username);

            return false;
        }

        LoginRateLimit::clear($ip, $username);

        session_regenerate_id(true);

        // Rotate the CSRF token on privilege change so a token captured before
        // login can't be replayed against the authenticated session.
        unset($_SESSION['_csrf_token']);

        $_SESSION[self::SESSION_USER_KEY] = [
            'id' => (int) $user['id'],
            'name' => $user['name'],
            'username' => $user['username'],
            'role' => $user['role'],
            'must_change_password' => (int) ($user['must_change_password'] ?? 0),
            'signature' => $user['signature'] ?? null,
            'preferences' => $user['preferences'] ?? null,
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

        unset($_SESSION['_last_filter_stats']);

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

    /**
     * Verify the logged-in account is still active. Checked once per request so
     * an admin disabling a user takes effect immediately instead of lasting
     * until their session expires.
     */
    public static function ensureActive(): bool
    {
        static $checked = null;
        if ($checked !== null) {
            return $checked;
        }

        $current = self::user();
        if ($current === null) {
            return $checked = false;
        }

        try {
            $row = Database::fetchOne('SELECT active FROM users WHERE id = ? LIMIT 1', [$current['id']]);
        } catch (\Throwable $e) {
            // On a transient DB error, don't lock the user out.
            return $checked = true;
        }

        return $checked = ($row !== null && (int) $row['active'] === 1);
    }

    public static function mustChangePassword(): bool
    {
        $user = self::user();

        return $user !== null && !empty($user['must_change_password']);
    }

    public static function enforcePasswordChange(): void
    {
        if (!self::mustChangePassword()) {
            return;
        }

        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

        if (str_contains($uri, 'change-password') || str_contains($uri, 'logout')) {
            return;
        }

        redirect('change-password');
    }

    public static function refreshUser(): void
    {
        $current = self::user();
        if ($current === null) {
            return;
        }

        try {
            $columns = 'id, name, username, role';
            if (schema_has_column('users', 'must_change_password')) {
                $columns .= ', must_change_password';
            }
            if (schema_has_column('users', 'signature')) {
                $columns .= ', signature';
            }
            if (schema_has_column('users', 'preferences')) {
                $columns .= ', preferences';
            }

            $user = Database::fetchOne(
                "SELECT {$columns} FROM users WHERE id = ? LIMIT 1",
                [$current['id']]
            );
        } catch (\Throwable $e) {
            return;
        }

        if ($user === null) {
            return;
        }

        $_SESSION[self::SESSION_USER_KEY] = [
            'id' => (int) $user['id'],
            'name' => $user['name'],
            'username' => $user['username'],
            'role' => $user['role'],
            'must_change_password' => (int) ($user['must_change_password'] ?? 0),
            'signature' => $user['signature'] ?? null,
            'preferences' => $user['preferences'] ?? null,
        ];
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
