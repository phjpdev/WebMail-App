<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;

class AliasService
{
    /** @var list<array{id: int, email: string, display_name: string}>|null */
    private static ?array $activeCache = null;

    /**
     * Aliases available in compose: all for admin, only the logged-in user's for employees.
     *
     * @param array<string, mixed>|null $user
     * @return list<array{id: int, email: string, display_name: string}>
     */
    public function listForCompose(?array $user): array
    {
        if ($user !== null && ($user['role'] ?? '') === 'employee') {
            $userId = (int) ($user['id'] ?? 0);
            if ($userId <= 0) {
                return [];
            }

            $row = Database::fetchOne(
                'SELECT id, email, display_name FROM aliases WHERE user_id = ? AND active = 1 ORDER BY id LIMIT 1',
                [$userId]
            );
            if ($row !== null && !empty($row['email'])) {
                return [[
                    'id' => (int) $row['id'],
                    'email' => (string) $row['email'],
                    'display_name' => (string) ($row['display_name'] ?? $row['email']),
                ]];
            }

            $email = $this->userAlias($userId);
            if ($email === '') {
                return [];
            }

            return [[
                'id' => 0,
                'email' => $email,
                'display_name' => (string) ($user['name'] ?? $email),
            ]];
        }

        return $this->listActive();
    }

    public function listActive(): array
    {
        if (self::$activeCache !== null) {
            return self::$activeCache;
        }

        $rows = Database::query(
            'SELECT id, email, display_name FROM aliases WHERE active = 1 ORDER BY display_name'
        )->fetchAll();

        if ($rows !== []) {
            self::$activeCache = array_map(fn ($row) => [
                'id' => (int) $row['id'],
                'email' => $row['email'],
                'display_name' => $row['display_name'],
            ], $rows);

            return self::$activeCache;
        }

        $mailbox = config('mail')['mailbox_email'];
        if ($mailbox === '') {
            self::$activeCache = [];

            return self::$activeCache;
        }

        self::$activeCache = [[
            'id' => 0,
            'email' => $mailbox,
            'display_name' => config('app')['name'],
        ]];

        return self::$activeCache;
    }

    public static function clearCache(): void
    {
        self::$activeCache = null;
    }

    /**
     * Resolve the send-as identity for a specific logged-in user.
     * Falls back to the shared mailbox address when the user has no active alias.
     */
    public function userAlias(?int $userId): string
    {
        if ($userId !== null && $userId > 0) {
            $row = Database::fetchOne(
                'SELECT email FROM aliases WHERE user_id = ? AND active = 1 ORDER BY id LIMIT 1',
                [$userId]
            );
            if ($row !== null && !empty($row['email'])) {
                return $row['email'];
            }
        }

        return config('mail')['mailbox_email'];
    }

    /**
     * Resolve which configured alias a message was received on.
     *
     * We check the visible To/Cc recipients BEFORE the envelope Delivered-To,
     * and prefer a personal alias over the shared mailbox. The shared mailbox
     * is the delivery target for every message (it always appears in
     * Delivered-To), so without this it would always win and a reply to mail
     * addressed to ankeshv@ would incorrectly send as support@.
     *
     * Returns null when none of our aliases match so the caller can fall back
     * (e.g. to the logged-in user's own alias).
     */
    public function resolveReplyAlias(?string $deliveredTo, ?string $to): ?string
    {
        $shared = strtolower(trim((string) (config('mail')['mailbox_email'] ?? '')));
        $candidates = array_merge($this->extractEmails($to), $this->extractEmails($deliveredTo));
        $aliases = $this->listActive();

        $sharedMatch = null;
        foreach ($candidates as $email) {
            foreach ($aliases as $alias) {
                if (strcasecmp($alias['email'], $email) !== 0) {
                    continue;
                }
                if ($shared !== '' && strcasecmp($alias['email'], $shared) === 0) {
                    $sharedMatch = $alias['email'];
                    continue 2;
                }
                return $alias['email'];
            }
        }

        return $sharedMatch;
    }

    public function resolveAllowedFrom(?string $preferred, ?int $userId = null): string
    {
        $email = $this->normalizeEmail($preferred);
        if ($email !== '') {
            foreach ($this->listActive() as $alias) {
                if (strcasecmp($alias['email'], $email) === 0) {
                    return $alias['email'];
                }
            }
        }

        return $this->userAlias($userId);
    }

    public function getDisplayName(string $email): string
    {
        foreach ($this->listActive() as $alias) {
            if (strcasecmp($alias['email'], $email) === 0) {
                return $alias['display_name'];
            }
        }

        return config('app')['name'];
    }

    /**
     * @return list<string>
     */
    private function extractEmails(?string $header): array
    {
        if ($header === null || $header === '') {
            return [];
        }

        preg_match_all('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $header, $matches);

        return array_unique($matches[0] ?? []);
    }

    private function normalizeEmail(?string $value): string
    {
        $emails = $this->extractEmails($value);

        return $emails[0] ?? '';
    }
}
