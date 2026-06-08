<?php

declare(strict_types=1);

namespace App\Services;

class FolderCache
{
    private const SESSION_KEY = '_folder_cache';
    private const TTL = 300;

    /**
     * @return list<array{path: string, name: string, delimiter: string}>|null
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

        return $cached['folders'];
    }

    /**
     * @param list<array{path: string, name: string, delimiter: string}> $folders
     */
    public function set(array $folders): void
    {
        $_SESSION[self::SESSION_KEY] = [
            'expires' => time() + self::TTL,
            'folders' => $folders,
        ];
    }

    public function clear(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }

    /**
     * @return array{folders: list<array{path: string, name: string, delimiter: string}>, connected: bool, error: string}
     */
    public static function load(bool $refresh = false): array
    {
        $cache = new self();

        if (!$refresh) {
            $cached = $cache->get();
            if ($cached !== null) {
                return ['folders' => $cached, 'connected' => true, 'error' => ''];
            }
        }

        $imap = new ImapService();
        if (!$imap->connect()) {
            return ['folders' => [], 'connected' => false, 'error' => $imap->getLastError()];
        }

        $folders = $imap->listFolders();
        $cache->set($folders);

        return ['folders' => $folders, 'connected' => true, 'error' => ''];
    }
}
