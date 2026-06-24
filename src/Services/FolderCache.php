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
            'unread_counts' => self::sanitizeUnreadCounts($unreadCounts),
            'unread_expires' => time() + self::UNREAD_TTL,
        ];
    }

    public function clear(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
        self::$registryCache = null;
    }

    /** @var array<string, array{path: string, display_name: string}>|null */
    private static ?array $registryCache = null;

    /**
     * Adjust a single folder's cached unread count in place, without throwing
     * away the (rarely-changing) folder list or hitting the IMAP server. This
     * keeps message actions fast: no re-list, no per-folder imap_status calls.
     *
     * @return array<string, int> The full (unfiltered) unread-count map, or [] if no cache.
     */
    public static function bumpUnread(string $path, int $delta): array
    {
        $key = self::SESSION_KEY;
        if (!isset($_SESSION[$key]['unread_counts']) || !is_array($_SESSION[$key]['unread_counts'])) {
            return [];
        }

        if (!folder_shows_unread_badge($path)) {
            $_SESSION[$key]['unread_counts'][$path] = 0;
        } else {
            $current = (int) ($_SESSION[$key]['unread_counts'][$path] ?? 0);
            $_SESSION[$key]['unread_counts'][$path] = max(0, $current + $delta);
        }

        return self::sanitizeUnreadCounts($_SESSION[$key]['unread_counts']);
    }

    /**
     * Force only the unread counts to be recomputed on next load (keeps the
     * cached folder list). Cheaper than clear() for bulk operations.
     */
    public static function invalidateUnread(): void
    {
        if (isset($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY]['unread_expires'] = 0;
        }
    }

    /**
     * Re-fetch unread counts from IMAP for specific folders and patch the
     * session cache. Used after filter moves so sidebar badges stay accurate
     * without a full folder re-list or status sweep of every mailbox.
     *
     * @param list<string> $paths
     */
    public static function refreshPaths(array $paths): void
    {
        $paths = array_values(array_unique(array_filter($paths)));
        if ($paths === []) {
            return;
        }

        self::ensureCache();
        if (!isset($_SESSION[self::SESSION_KEY]['unread_counts'])) {
            return;
        }

        $imap = new ImapService();
        if (!$imap->connect()) {
            return;
        }

        foreach ($imap->getFolderUnreadCounts($paths) as $path => $count) {
            $_SESSION[self::SESSION_KEY]['unread_counts'][$path] = folder_shows_unread_badge($path)
                ? $count
                : 0;
        }
    }

    /**
     * Patch a single folder's cached unread count (e.g. from mail_index reconciliation).
     */
    public static function setUnreadCount(string $path, int $count): void
    {
        if ($path === '') {
            return;
        }

        self::ensureCache();
        if (isset($_SESSION[self::SESSION_KEY]['unread_counts'])) {
            $_SESSION[self::SESSION_KEY]['unread_counts'][$path] = folder_shows_unread_badge($path)
                ? max(0, $count)
                : 0;
        }
    }

    /**
     * Refresh sidebar badge counts for the folders the user is looking at plus
     * the filter inbox. Cheap (1–2 IMAP status calls) and keeps badges accurate
     * after filter moves even when the throttle skips another filter pass.
     *
     * @param string ...$paths
     */
    public static function syncUnreadBadges(string ...$paths): void
    {
        $inbox = (string) (config('app')['filter_source_folder'] ?? 'INBOX');
        $all = array_unique(array_merge($paths, [$inbox]));

        self::refreshPaths($all);
    }

    private static function ensureCache(): void
    {
        if (!isset($_SESSION[self::SESSION_KEY]['unread_counts'])) {
            self::load(skipUnreadRefresh: true);
        }
    }

    /**
     * @return array{folders: list<array{path: string, name: string, delimiter: string}>, unread_counts: array<string, int>, connected: bool, error: string}
     */
    public static function load(bool $refresh = false, bool $skipUnreadRefresh = false): array
    {
        $cache = new self();

        if (!$refresh) {
            $cached = $cache->get();
            if ($cached !== null) {
                $session = $_SESSION[self::SESSION_KEY] ?? [];
                if (!$skipUnreadRefresh && time() > (int) ($session['unread_expires'] ?? 0)) {
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

        $paths = $this->pathsToRefreshUnread(array_column($data['folders'], 'path'));
        if ($paths === []) {
            return $data;
        }

        $imapCounts = $imap->getFolderUnreadCounts($paths);
        $data['unread_counts'] = array_merge(
            $data['unread_counts'] ?? [],
            $imapCounts
        );

        if (isset($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY]['unread_counts'] = self::sanitizeUnreadCounts($data['unread_counts']);
            $_SESSION[self::SESSION_KEY]['unread_expires'] = time() + self::UNREAD_TTL;
        }

        return $data;
    }

    /**
     * @param array<string, int> $counts
     * @return array<string, int>
     */
    private static function sanitizeUnreadCounts(array $counts): array
    {
        foreach ($counts as $path => $count) {
            if (!folder_shows_unread_badge($path)) {
                $counts[$path] = 0;
            }
        }

        return $counts;
    }

    /**
     * Employees only need unread counts for folders they can open; admins need all.
     *
     * @param list<string> $allPaths
     * @return list<string>
     */
    private function pathsToRefreshUnread(array $allPaths): array
    {
        $user = Auth::user();
        if ($user === null || ($user['role'] ?? '') === 'admin') {
            return $allPaths;
        }

        $allowed = $this->employeeAllowedPaths((int) $user['id']);

        return array_values(array_filter(
            $allPaths,
            fn (string $path) => $this->isEmployeeFolderAllowed($path, $allowed)
        ));
    }

    /**
     * Authorization check for direct folder access (read, attachment, move, etc.).
     * Only folders in the admin registry may be opened. Employees may additionally
     * only access INBOX, standard shared folders, and their own linked folder.
     */
    public static function canAccess(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        $user = Auth::user();
        if ($user === null) {
            return false;
        }

        $cache = new self();
        if (!$cache->isRegisteredActivePath($path)) {
            return false;
        }

        if (($user['role'] ?? '') === 'admin') {
            return true;
        }

        $allowedPaths = $cache->employeeAllowedPaths((int) $user['id']);

        return $cache->isEmployeeFolderAllowed($path, $allowedPaths);
    }

    /**
     * @param array{folders: list<array{path: string, name: string, delimiter: string}>, unread_counts: array<string, int>, connected: bool, error: string} $data
     * @return array{folders: list<array{path: string, name: string, delimiter: string}>, unread_counts: array<string, int>, connected: bool, error: string}
     */
    private function filterForUser(array $data): array
    {
        $data = $this->filterByRegistry($data);

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
        $paths = [];
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

    /**
     * Active folders from the admin registry (source of truth for the sidebar).
     *
     * @return array<string, array{path: string, display_name: string}>
     */
    private function registeredActiveFolders(): array
    {
        if (self::$registryCache !== null) {
            return self::$registryCache;
        }

        $registry = [];
        try {
            $rows = Database::query(
                'SELECT imap_path, display_name FROM folders WHERE active = 1'
            )->fetchAll();
            foreach ($rows as $row) {
                $path = (string) $row['imap_path'];
                $registry[strtoupper($path)] = [
                    'path' => $path,
                    'display_name' => (string) $row['display_name'],
                ];
            }
        } catch (\Throwable $e) {
            app_log('Folder registry load failed: ' . $e->getMessage());
        }

        self::$registryCache = $registry;

        return $registry;
    }

    private function isRegisteredActivePath(string $path): bool
    {
        return isset($this->registeredActiveFolders()[strtoupper($path)]);
    }

    /**
     * Keep only mailboxes that exist in the admin folder registry and still
     * exist on the IMAP server. Orphan IMAP folders (e.g. after admin delete)
     * must not appear in the sidebar.
     *
     * @param array{folders: list<array{path: string, name: string, delimiter: string}>, unread_counts: array<string, int>, connected: bool, error: string} $data
     * @return array{folders: list<array{path: string, name: string, delimiter: string}>, unread_counts: array<string, int>, connected: bool, error: string}
     */
    private function filterByRegistry(array $data): array
    {
        $registry = $this->registeredActiveFolders();
        if ($registry === []) {
            $data['folders'] = [];
            $data['unread_counts'] = [];

            return $data;
        }

        $filtered = [];
        $counts = [];

        foreach ($data['folders'] as $folder) {
            $imapPath = (string) $folder['path'];
            $key = strtoupper($imapPath);
            if (!isset($registry[$key])) {
                continue;
            }

            $entry = $registry[$key];
            $folder['path'] = $entry['path'];
            $folder['name'] = $entry['display_name'];
            $filtered[] = $folder;
            $counts[$entry['path']] = (int) (
                $data['unread_counts'][$imapPath]
                ?? $data['unread_counts'][$entry['path']]
                ?? 0
            );
        }

        $data['folders'] = $filtered;
        $data['unread_counts'] = self::sanitizeUnreadCounts($counts);

        return $data;
    }
}
