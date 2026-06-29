<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth;
use App\Database;

class FolderCache
{
    private const SESSION_KEY = '_folder_cache';
    private const PENDING_BADGE_PATHS_KEY = '_pending_badge_paths';
    private const PENDING_FILTER_ROUTES_KEY = '_pending_filter_routes';
    private const UNREAD_TTL = 90;

    /**
     * @return array{folders: list<array{path: string, name: string, delimiter: string}>, unread_counts: array<string, int>, connected: bool, error: string}|null
     */
    public function get(): ?array
    {
        $cached = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($cached) || !isset($cached['folders'])) {
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
            'folders' => $folders,
            'unread_counts' => self::sanitizeUnreadCounts($unreadCounts),
            'unread_expires' => time() + self::UNREAD_TTL,
        ];
        self::$pathResolveCache = null;
    }

    /** @var array<string, string>|null uppercase path => canonical IMAP path */
    private static ?array $pathResolveCache = null;

    public function clear(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
        self::$registryCache = null;
        self::$pathResolveCache = null;
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
        ensure_session_writable();
        self::ensureCache();
        $key = self::SESSION_KEY;
        $path = self::canonicalUnreadPath($path);

        if (!isset($_SESSION[$key]['unread_counts']) || !is_array($_SESSION[$key]['unread_counts'])) {
            $_SESSION[$key]['unread_counts'] = [];
        }

        if (!folder_shows_unread_badge($path)) {
            $_SESSION[$key]['unread_counts'][$path] = 0;
        } elseif ($delta !== 0) {
            $current = (int) ($_SESSION[$key]['unread_counts'][$path] ?? 0);
            $_SESSION[$key]['unread_counts'][$path] = max(0, $current + $delta);
            if ($delta > 0) {
                self::queuePendingBadgePath($path);
            }
        }

        $_SESSION[$key]['unread_counts'] = self::normalizeUnreadCounts($_SESSION[$key]['unread_counts']);

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

        ensure_session_writable();
        self::ensureCache();
        if (!isset($_SESSION[self::SESSION_KEY]['unread_counts'])) {
            return;
        }

        $paths = array_values(array_unique(array_filter(array_map(
            static fn (string $path): string => self::canonicalUnreadPath($path),
            $paths
        ))));
        if ($paths === []) {
            return;
        }

        releaseSessionLock();

        $imap = new ImapService();
        if (!$imap->connect()) {
            return;
        }

        $imapCounts = $imap->getFolderBadgeCounts($paths);

        ensure_session_writable();
        self::ensureCache();
        if (!isset($_SESSION[self::SESSION_KEY]['unread_counts'])) {
            return;
        }

        foreach ($imapCounts as $path => $imapUnread) {
            $path = self::canonicalUnreadPath($path);
            if (!folder_shows_unread_badge($path)) {
                $_SESSION[self::SESSION_KEY]['unread_counts'][$path] = 0;
                continue;
            }

            $imapUnread = (int) $imapUnread;
            $sessionUnread = (int) ($_SESSION[self::SESSION_KEY]['unread_counts'][$path] ?? 0);
            $indexUnread = 0;

            if (MailCacheService::hasFolderData($path) && !MailCacheService::badgeAheadOfIndex($path)) {
                MailCacheService::syncBadgeFromIndex($path);
                $indexUnread = MailCacheService::countBadgeFromIndex($path);
            } elseif (MailCacheService::hasFolderData($path)) {
                $indexUnread = MailCacheService::countBadgeFromIndex($path);
            }

            if (folder_uses_draft_badge($path) && MailCacheService::hasFolderData($path)) {
                $_SESSION[self::SESSION_KEY]['unread_counts'][$path] = $indexUnread;
            } else {
                $_SESSION[self::SESSION_KEY]['unread_counts'][$path] = max($imapUnread, $indexUnread, $sessionUnread);
            }
        }

        if (isset($_SESSION[self::SESSION_KEY]['unread_counts'])) {
            $_SESSION[self::SESSION_KEY]['unread_counts'] = self::normalizeUnreadCounts(
                $_SESSION[self::SESSION_KEY]['unread_counts']
            );
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

        ensure_session_writable();
        $path = self::canonicalUnreadPath($path);
        self::ensureCache();
        if (isset($_SESSION[self::SESSION_KEY]['unread_counts'])) {
            $_SESSION[self::SESSION_KEY]['unread_counts'][$path] = folder_shows_unread_badge($path)
                ? max(0, $count)
                : 0;
            $_SESSION[self::SESSION_KEY]['unread_counts'] = self::normalizeUnreadCounts(
                $_SESSION[self::SESSION_KEY]['unread_counts']
            );
        }
    }

    /**
     * Folders that should show badges soon after a send/filter delivery.
     *
     * @param list<string> $paths
     */
    public static function setPendingBadgePaths(array $paths): void
    {
        $paths = array_values(array_unique(array_filter($paths, static fn (string $p): bool => $p !== '' && folder_shows_unread_badge($p))));
        if ($paths === []) {
            return;
        }

        ensure_session_writable();
        $_SESSION[self::PENDING_BADGE_PATHS_KEY] = [
            'paths' => $paths,
            'until' => time() + 120,
        ];
    }

    public static function queuePendingBadgePath(string $path): void
    {
        $path = self::canonicalUnreadPath($path);
        if ($path === '' || !folder_shows_unread_badge($path)) {
            return;
        }

        ensure_session_writable();
        $pending = $_SESSION[self::PENDING_BADGE_PATHS_KEY] ?? null;
        $paths = is_array($pending) ? (array) ($pending['paths'] ?? []) : [];
        $paths[] = $path;
        $_SESSION[self::PENDING_BADGE_PATHS_KEY] = [
            'paths' => array_values(array_unique($paths)),
            'until' => time() + 120,
        ];
    }

    /**
     * @return list<string>
     */
    public static function getPendingBadgePaths(): array
    {
        $pending = $_SESSION[self::PENDING_BADGE_PATHS_KEY] ?? null;
        if (!is_array($pending)) {
            return [];
        }

        if (time() > (int) ($pending['until'] ?? 0)) {
            unset($_SESSION[self::PENDING_BADGE_PATHS_KEY]);

            return [];
        }

        return array_values(array_filter(
            (array) ($pending['paths'] ?? []),
            static fn (string $p): bool => $p !== '' && folder_shows_unread_badge($p)
        ));
    }

    public static function clearPendingBadgePaths(): void
    {
        ensure_session_writable();
        unset($_SESSION[self::PENDING_BADGE_PATHS_KEY]);
    }

    /**
     * Queue multi-folder delivery for a just-sent message when Bcc recipients
     * are stripped from the inbox copy but compose already knows all targets.
     *
     * @param list<string> $paths
     */
    public static function queuePendingFilterRoute(string $messageId, array $paths): void
    {
        $messageId = normalize_message_id($messageId);
        $paths = array_values(array_unique(array_filter(array_map(
            static fn (string $p): string => self::resolvePath($p),
            $paths
        ), static fn (string $p): bool => $p !== '')));

        if ($messageId === '' || count($paths) < 2) {
            return;
        }

        ensure_session_writable();
        $routes = $_SESSION[self::PENDING_FILTER_ROUTES_KEY] ?? [];
        if (!is_array($routes)) {
            $routes = [];
        }

        $routes[] = [
            'message_id' => $messageId,
            'paths' => $paths,
            'until' => time() + 300,
        ];
        $_SESSION[self::PENDING_FILTER_ROUTES_KEY] = $routes;
    }

    /**
     * @return list<string>|null
     */
    public static function claimPendingFilterRoute(?string $messageId): ?array
    {
        $messageId = normalize_message_id($messageId);
        if ($messageId === '') {
            return null;
        }

        $pending = $_SESSION[self::PENDING_FILTER_ROUTES_KEY] ?? null;
        if (!is_array($pending)) {
            return null;
        }

        $now = time();
        $remaining = [];
        $claimed = null;

        foreach ($pending as $entry) {
            if (!is_array($entry) || $now > (int) ($entry['until'] ?? 0)) {
                continue;
            }

            if (normalize_message_id((string) ($entry['message_id'] ?? '')) === $messageId) {
                $claimed = array_values(array_filter(
                    (array) ($entry['paths'] ?? []),
                    static fn (string $p): bool => $p !== ''
                ));
                continue;
            }

            $remaining[] = $entry;
        }

        if ($claimed === null || count($claimed) < 2) {
            return null;
        }

        ensure_session_writable();
        if ($remaining === []) {
            unset($_SESSION[self::PENDING_FILTER_ROUTES_KEY]);
        } else {
            $_SESSION[self::PENDING_FILTER_ROUTES_KEY] = $remaining;
        }

        return $claimed;
    }

    /**
     * Unread counts for every sidebar folder (including zeros).
     *
     * @return array<string, int>
     */
    public static function sidebarUnreadCounts(): array
    {
        $folderData = self::load(skipUnreadRefresh: true);
        $session = $folderData['unread_counts'] ?? [];
        $result = [];

        foreach ($folderData['folders'] ?? [] as $folder) {
            $path = (string) ($folder['path'] ?? '');
            if ($path !== '' && folder_shows_unread_badge($path)) {
                $result[$path] = (int) ($session[$path] ?? 0);
            }
        }

        return $result;
    }

    private static function canonicalUnreadPath(string $path): string
    {
        return self::resolvePath($path);
    }

    /**
     * Collapse duplicate unread-count keys that differ only by mailbox path casing.
     *
     * @param array<string, int> $counts
     * @return array<string, int>
     */
    private static function normalizeUnreadCounts(array $counts): array
    {
        self::warmPathResolveCache();
        $normalized = [];

        foreach ($counts as $path => $count) {
            $canonical = self::resolvePath((string) $path);
            if ($canonical === '' || !folder_shows_unread_badge($canonical)) {
                continue;
            }
            if ($path === $canonical || !array_key_exists($canonical, $normalized)) {
                $normalized[$canonical] = (int) $count;
            }
        }

        return $normalized;
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
        $unreadCounts = $imap->getFolderBadgeCounts($paths);
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

        $imapCounts = $imap->getFolderBadgeCounts($paths);
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
        $counts = self::normalizeUnreadCounts($counts);
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

        $prefix = $this->employeeMailboxPrefix((int) $user['id']);

        return array_values(array_filter(
            $allPaths,
            fn (string $path) => $this->isEmployeeFolderAllowed($path, $prefix)
        ));
    }

    /**
     * Authorization check for direct folder access (read, attachment, move, etc.).
     * Only folders in the admin registry may be opened. Employees may only open
     * their personal mailbox (linked inbox and subfolders under it).
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

        $prefix = $cache->employeeMailboxPrefix((int) $user['id']);

        return $cache->isEmployeeFolderAllowed($path, $prefix);
    }

    /**
     * @param array{folders: list<array{path: string, name: string, delimiter: string}>, unread_counts: array<string, int>, connected: bool, error: string} $data
     * @return array{folders: list<array{path: string, name: string, delimiter: string}>, unread_counts: array<string, int>, connected: bool, error: string}
     */
    private function filterForUser(array $data): array
    {
        $data = $this->filterByRegistry($data);

        $user = Auth::user();
        if ($user === null) {
            return $data;
        }

        if ($user['role'] === 'admin') {
            return $this->filterAdminFolders($data);
        }

        $prefix = $this->employeeMailboxPrefix((int) $user['id']);
        $filtered = [];
        $counts = [];

        foreach ($data['folders'] as $folder) {
            if ($this->isEmployeeFolderAllowed($folder['path'], $prefix)) {
                $filtered[] = $folder;
                $counts[$folder['path']] = $data['unread_counts'][$folder['path']] ?? 0;
            }
        }

        $existing = [];
        foreach ($filtered as $folder) {
            $existing[strtoupper((string) $folder['path'])] = true;
        }

        foreach ($data['folders'] as $folder) {
            $path = (string) ($folder['path'] ?? '');
            if ($path === '' || isset($existing[strtoupper($path)])) {
                continue;
            }
            if (!employee_can_access_correspondent_folder($path)) {
                continue;
            }
            $filtered[] = $folder;
            $counts[$path] = $data['unread_counts'][$path] ?? 0;
            $existing[strtoupper($path)] = true;
        }

        foreach (employee_correspondent_folder_paths() as $corrPath) {
            if ($corrPath === '' || isset($existing[strtoupper($corrPath)])) {
                continue;
            }
            $meta = folder_registry_meta($corrPath);
            if ($meta === null) {
                continue;
            }
            $resolved = self::resolvePath($meta['path']);
            $filtered[] = [
                'path' => $resolved,
                'name' => $meta['name'],
                'delimiter' => '.',
            ];
            $counts[$resolved] = $data['unread_counts'][$resolved]
                ?? $data['unread_counts'][$meta['path']]
                ?? 0;
            $existing[strtoupper($resolved)] = true;
        }

        $data['folders'] = $filtered;
        $data['unread_counts'] = $counts;

        return $data;
    }

    /**
     * Admin sidebar: shared system folders + employee inboxes, not per-user Sent/Drafts/etc.
     *
     * @param array{folders: list<array{path: string, name: string, delimiter: string}>, unread_counts: array<string, int>, connected: bool, error: string} $data
     * @return array{folders: list<array{path: string, name: string, delimiter: string}>, unread_counts: array<string, int>, connected: bool, error: string}
     */
    private function filterAdminFolders(array $data): array
    {
        $employeeRoots = $this->employeeMailboxRoots();
        $filtered = [];
        $counts = [];

        foreach ($data['folders'] as $folder) {
            $path = $folder['path'];
            if ($this->isEmployeePersonalSubfolder($path, $employeeRoots)) {
                continue;
            }

            $filtered[] = $folder;
            $counts[$path] = $data['unread_counts'][$path] ?? 0;
        }

        $data['folders'] = $filtered;
        $data['unread_counts'] = $counts;

        return $data;
    }

    /**
     * @return list<string>
     */
    private function employeeMailboxRoots(): array
    {
        static $roots = null;
        if ($roots !== null) {
            return $roots;
        }

        $roots = [];
        try {
            $rows = Database::query(
                "SELECT imap_path FROM folders WHERE folder_type = 'employee' AND linked_user_id IS NOT NULL AND active = 1"
            )->fetchAll();
            foreach ($rows as $row) {
                $path = (string) ($row['imap_path'] ?? '');
                if ($path !== '') {
                    $roots[] = $path;
                }
            }
        } catch (\Throwable) {
            // ignore
        }

        return $roots;
    }

    /**
     * @param list<string> $employeeRoots
     */
    private function isEmployeePersonalSubfolder(string $path, array $employeeRoots): bool
    {
        foreach ($employeeRoots as $root) {
            if ($this->isWithinEmployeeMailbox($path, $root, subfoldersOnly: true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Case-insensitive mailbox path match (IMAP may use INBOX.user while DB has INBOX.User).
     */
    private function isWithinEmployeeMailbox(string $path, string $root, bool $subfoldersOnly = false): bool
    {
        if ($path === '' || $root === '') {
            return false;
        }

        if (strcasecmp($path, $root) === 0) {
            return !$subfoldersOnly;
        }

        $prefix = rtrim($root, '.') . '.';

        return strlen($path) > strlen($prefix)
            && strncasecmp($path, $prefix, strlen($prefix)) === 0;
    }

    /**
     * Personal mailbox root for an employee (e.g. INBOX.Jean).
     */
    private function employeeMailboxPrefix(int $userId): ?string
    {
        try {
            $linked = Database::fetchOne(
                "SELECT imap_path FROM folders WHERE linked_user_id = ? AND folder_type = 'employee' AND active = 1 LIMIT 1",
                [$userId]
            );
            if ($linked !== null && !empty($linked['imap_path'])) {
                return (string) $linked['imap_path'];
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return null;
    }

    private function isEmployeeFolderAllowed(string $path, ?string $mailboxPrefix): bool
    {
        if ($mailboxPrefix === null || $mailboxPrefix === '') {
            return false;
        }

        if ($this->isWithinEmployeeMailbox($path, $mailboxPrefix)) {
            return true;
        }

        return employee_can_access_correspondent_folder($path);
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
     * Map a folder path (from URL, registry, or sidebar) to the exact path
     * returned by the IMAP server. Case-insensitive — fixes e.g. INBOX.Spam vs INBOX.spam.
     * Uses the session folder list (no extra IMAP round-trip on normal navigation).
     */
    public static function resolvePath(string $path): string
    {
        if ($path === '') {
            return '';
        }

        $upper = strtoupper($path);
        self::warmPathResolveCache();

        if (isset(self::$pathResolveCache[$upper])) {
            return self::$pathResolveCache[$upper];
        }

        // Unknown path — return as-is (open will fail gracefully).
        self::$pathResolveCache[$upper] = $path;

        return $path;
    }

    private static function warmPathResolveCache(): void
    {
        if (self::$pathResolveCache !== null) {
            return;
        }

        self::$pathResolveCache = [];

        $cache = new self();
        $cached = $cache->get();
        if ($cached !== null) {
            self::indexFolderPaths($cached['folders']);

            return;
        }

        $data = $cache->load(skipUnreadRefresh: true);
        self::indexFolderPaths($data['folders']);
    }

    /**
     * @param list<array{path: string, name?: string, delimiter?: string}> $folders
     */
    private static function indexFolderPaths(array $folders): void
    {
        foreach ($folders as $folder) {
            $imapPath = (string) ($folder['path'] ?? '');
            if ($imapPath === '') {
                continue;
            }
            self::$pathResolveCache ??= [];
            self::$pathResolveCache[strtoupper($imapPath)] = $imapPath;
        }

        // Registry aliases (e.g. INBOX.Spam in DB → INBOX.spam on server).
        try {
            $rows = Database::query(
                'SELECT imap_path FROM folders WHERE active = 1'
            )->fetchAll();
            foreach ($rows as $row) {
                $registryPath = (string) ($row['imap_path'] ?? '');
                if ($registryPath === '') {
                    continue;
                }
                $key = strtoupper($registryPath);
                if (isset(self::$pathResolveCache[$key])) {
                    continue;
                }
                foreach (self::$pathResolveCache as $canonical) {
                    if (strtoupper($canonical) === $key) {
                        self::$pathResolveCache[$key] = $canonical;
                        break;
                    }
                }
            }
        } catch (\Throwable) {
            // ignore
        }
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
        $seen = [];

        foreach ($data['folders'] as $folder) {
            $imapPath = (string) $folder['path'];
            $key = strtoupper($imapPath);
            if (!isset($registry[$key]) || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $entry = $registry[$key];
            // Keep the exact IMAP path for open/list operations; registry only supplies the label.
            $folder['name'] = $entry['display_name'];
            $filtered[] = $folder;
            $counts[$imapPath] = (int) (
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
