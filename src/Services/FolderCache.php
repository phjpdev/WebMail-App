<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth;
use App\Database;

class FolderCache
{
    private const SESSION_KEY = '_folder_cache';
    private const TTL = 300;
    private const UNREAD_TTL = 60;

    /**
     * @return array{folders: list<array{path: string, name: string, delimiter: string}>, unread_counts: array<string, int>, connected: bool, error: string}|null
     */
    public function get(): ?array
    {
        $cached = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($cached) || !isset($cached['expires'], $cached['folders'])) {
            return null;
        }

        if (time() > (int) $cached['expires']) {
            unset($_SESSION[self::SESSION_KEY]);

            return null;
        }

        return [
            'folders' => $cached['folders'],
            'unread_counts' => $cached['unread_counts'] ?? [],
            'connected' => true,
            'error' => '',
        ];
    }

    /**
     * @param list<array{path: string, name: string, delimiter: string}> $folders
     * @param array<string, int> $unreadCounts
     */
    public function set(array $folders, array $unreadCounts = []): void
    {
        $_SESSION[self::SESSION_KEY] = [
            'expires' => time() + self::TTL,
            'folders' => $folders,
            'unread_counts' => $unreadCounts,
            'unread_expires' => time() + self::UNREAD_TTL,
        ];
    }

    public function clear(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }

    /**
     * @return array{folders: list<array{path: string, name: string, delimiter: string}>, unread_counts: array<string, int>, connected: bool, error: string}
     */
    public static function load(bool $refresh = false): array
    {
        $cache = new self();

        if (!$refresh) {
            $cached = $cache->get();
            if ($cached !== null) {
                $session = $_SESSION[self::SESSION_KEY] ?? [];
                if (time() > (int) ($session['unread_expires'] ?? 0)) {
                    $cached = $cache->refreshUnread($cached);
                }

                return $cache->filterForUser($cached);
            }
        }

        $imap = new ImapService();
        if (!$imap->connect()) {
            return [
                'folders' => [],
                'unread_counts' => [],
                'connected' => false,
                'error' => $imap->getLastError(),
            ];
        }

        $folders = $imap->listFolders();
        $paths = array_column($folders, 'path');
        $unreadCounts = $imap->getFolderUnreadCounts($paths);
        $cache->set($folders, $unreadCounts);

        $result = [
            'folders' => $folders,
            'unread_counts' => $unreadCounts,
            'connected' => true,
            'error' => '',
        ];

        return $cache->filterForUser($result);
    }

    /**
     * @param array{folders: list<array{path: string, name: string, delimiter: string}>, unread_counts: array<string, int>, connected: bool, error: string} $data
     * @return array{folders: list<array{path: string, name: string, delimiter: string}>, unread_counts: array<string, int>, connected: bool, error: string}
     */
    private function refreshUnread(array $data): array
    {
        $imap = new ImapService();
        if (!$imap->connect()) {
            return $data;
        }

        $paths = array_column($data['folders'], 'path');
        $data['unread_counts'] = $imap->getFolderUnreadCounts($paths);

        if (isset($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY]['unread_counts'] = $data['unread_counts'];
            $_SESSION[self::SESSION_KEY]['unread_expires'] = time() + self::UNREAD_TTL;
        }

        return $data;
    }

    /**
     * @param array{folders: list<array{path: string, name: string, delimiter: string}>, unread_counts: array<string, int>, connected: bool, error: string} $data
     * @return array{folders: list<array{path: string, name: string, delimiter: string}>, unread_counts: array<string, int>, connected: bool, error: string}
     */
    private function filterForUser(array $data): array
    {
        $user = Auth::user();
        if ($user === null || $user['role'] === 'admin') {
            return $data;
        }

        $allowedPaths = $this->employeeAllowedPaths((int) $user['id']);
        $filtered = [];
        $counts = [];

        foreach ($data['folders'] as $folder) {
            if ($this->isEmployeeFolderAllowed($folder['path'], $allowedPaths)) {
                $filtered[] = $folder;
                $counts[$folder['path']] = $data['unread_counts'][$folder['path']] ?? 0;
            }
        }

        $data['folders'] = $filtered;
        $data['unread_counts'] = $counts;

        return $data;
    }

    /**
     * @return list<string>
     */
    private function employeeAllowedPaths(int $userId): array
    {
        $paths = ['INBOX'];
        $types = ['sent', 'draft', 'trash', 'spam', 'junk'];

        try {
            $linked = Database::fetchOne(
                'SELECT imap_path FROM folders WHERE linked_user_id = ? AND active = 1 LIMIT 1',
                [$userId]
            );
            if ($linked !== null) {
                $paths[] = $linked['imap_path'];
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return $paths;
    }

    /**
     * @param list<string> $allowedPaths
     */
    private function isEmployeeFolderAllowed(string $path, array $allowedPaths): bool
    {
        if (in_array($path, $allowedPaths, true)) {
            return true;
        }

        $lower = strtolower($path);
        foreach (['sent', 'draft', 'trash', 'spam', 'junk'] as $type) {
            if (str_contains($lower, $type)) {
                return true;
            }
        }

        return false;
    }
}
