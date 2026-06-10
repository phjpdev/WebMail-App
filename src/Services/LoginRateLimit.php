<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;

class LoginRateLimit
{
    private const MAX_ATTEMPTS = 5;
    private const WINDOW_SECONDS = 900;

    public static function isBlocked(string $ip, string $username): bool
    {
        return self::countRecent($ip, $username) >= self::MAX_ATTEMPTS;
    }

    public static function recordFailure(string $ip, string $username): void
    {
        try {
            Database::query(
                'INSERT INTO login_attempts (ip_address, username) VALUES (?, ?)',
                [$ip, $username]
            );
        } catch (\Throwable $e) {
            app_log('Login rate limit record failed: ' . $e->getMessage());
        }
    }

    public static function clear(string $ip, string $username): void
    {
        try {
            Database::query(
                'DELETE FROM login_attempts WHERE ip_address = ? AND username = ?',
                [$ip, $username]
            );
        } catch (\Throwable $e) {
            app_log('Login rate limit clear failed: ' . $e->getMessage());
        }
    }

    private static function countRecent(string $ip, string $username): int
    {
        try {
            $row = Database::fetchOne(
                'SELECT COUNT(*) AS c FROM login_attempts
                 WHERE attempted_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
                 AND (ip_address = ? OR username = ?)',
                [self::WINDOW_SECONDS, $ip, $username]
            );

            return (int) ($row['c'] ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
