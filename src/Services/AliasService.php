<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;

class AliasService
{
    /**
     * @return list<array{id: int, email: string, display_name: string}>
     */
    public function listActive(): array
    {
        $rows = Database::query(
            'SELECT id, email, display_name FROM aliases WHERE active = 1 ORDER BY display_name'
        )->fetchAll();

        if ($rows !== []) {
            return array_map(fn ($row) => [
                'id' => (int) $row['id'],
                'email' => $row['email'],
                'display_name' => $row['display_name'],
            ], $rows);
        }

        $mailbox = config('mail')['mailbox_email'];
        if ($mailbox === '') {
            return [];
        }

        return [[
            'id' => 0,
            'email' => $mailbox,
            'display_name' => config('app')['name'],
        ]];
    }

    public function resolveReplyAlias(?string $deliveredTo, ?string $to): string
    {
        $candidates = $this->extractEmails($deliveredTo) + $this->extractEmails($to);
        $aliases = $this->listActive();

        foreach ($candidates as $email) {
            foreach ($aliases as $alias) {
                if (strcasecmp($alias['email'], $email) === 0) {
                    return $alias['email'];
                }
            }
        }

        return config('mail')['mailbox_email'];
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
}
