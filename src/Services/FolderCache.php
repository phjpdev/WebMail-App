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
     * Max IMAP STATUS calls per badge refresh. With very large registries
     * (hundreds of imported folders) one STATUS per folder per refresh made
     * login and every 90s refresh block for minutes. System folders and folders
     * currently showing a badge always refresh; the rest rotate through a
     * session cursor so every badge still converges over a few cycles. The
     * DB-only reconcile (light polls) keeps active folders accurate in between.
     */
    private const STATUS_BUDGET = 25;
    private const STATUS_CURSOR_KEY = '_badge_status_cursor';

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

    /** @var list<string>|null */
    private static ?array $employeeRootsCache = null;

    public function clear(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
        self::$registryCache = null;
        self::$pathResolveCache = null;
        self::$employeeRootsCache = null;
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

        // A huge registry (hundreds of imported folders with no index data yet)
        // funnels ALL its folders here via the badge reconcile — budget it like
        // every other STATUS refresh so one poll can't fire hundreds of IMAP
        // calls. Small explicit lists (after-action refreshes) pass unchanged.
        $paths = (new self())->statusBudgetPaths(
            $paths,
            (array) ($_SESSION[self::SESSION_KEY]['unread_counts'] ?? [])
        );

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

            $sessionUnread = (int) ($_SESSION[self::SESSION_KEY]['unread_counts'][$path] ?? 0);

            if (
                self::isPendingBadgePath($path)
                || mail_post_send_preview_pending($path)
                || MailCacheService::badgeAheadOfIndex($path)
            ) {
                $_SESSION[self::SESSION_KEY]['unread_counts'][$path] = max(0, $sessionUnread);
                continue;
            }

            if (folder_badge_uses_index_truth($path) || MailCacheService::usesPerUserRead($path)) {
                $_SESSION[self::SESSION_KEY]['unread_counts'][$path] = MailCacheService::sidebarBadgeCount(
                    $path,
                    $sessionUnread
                );
                continue;
            }

            $imapUnread = (int) $imapUnread;
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
                $_SESSION[self::SESSION_KEY]['unread_counts'][$path] = MailCacheService::mergeBadgeWithSession(
                    $path,
                    $indexUnread,
                    max($imapUnread, $sessionUnread)
                );
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

    public static function isPendingBadgePath(string $path): bool
    {
        $path = self::canonicalUnreadPath($path);
        if ($path === '') {
            return false;
        }

        foreach (self::getPendingBadgePaths() as $pending) {
            if (strcasecmp($pending, $path) === 0) {
                return true;
            }
        }

        return false;
    }

    public static function clearPendingBadgePaths(): void
    {
        ensure_session_writable();
        unset($_SESSION[self::PENDING_BADGE_PATHS_KEY]);
    }

    public static function clearPendingBadgePath(string $path): void
    {
        $path = self::canonicalUnreadPath($path);
        if ($path === '') {
            return;
        }

        ensure_session_writable();
        $pending = $_SESSION[self::PENDING_BADGE_PATHS_KEY] ?? null;
        if (!is_array($pending)) {
            return;
        }

        $paths = array_values(array_filter(
            (array) ($pending['paths'] ?? []),
            static fn (string $p): bool => self::canonicalUnreadPath($p) !== $path
        ));

        if ($paths === []) {
            unset($_SESSION[self::PENDING_BADGE_PATHS_KEY]);
        } else {
            $_SESSION[self::PENDING_BADGE_PATHS_KEY] = [
                'paths' => $paths,
                'until' => (int) ($pending['until'] ?? time() + 120),
            ];
        }
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

        if ($messageId === '' || $paths === []) {
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
        self::appendPendingFilterRouteFile($messageId, $paths);
    }

    /**
     * @param list<string> $paths
     */
    private static function appendPendingFilterRouteFile(string $messageId, array $paths): void
    {
        $routes = self::readPendingFilterRoutesFile();
        $routes[] = [
            'message_id' => $messageId,
            'paths' => $paths,
            'until' => time() + 300,
        ];
        self::writePendingFilterRoutesFile($routes);
    }

    /**
     * @return list<array{message_id: string, paths: list<string>, until: int}>
     */
    private static function readPendingFilterRoutesFile(): array
    {
        $path = self::pendingFilterRoutesFile();
        if (!is_readable($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param list<array{message_id: string, paths: list<string>, until: int}> $routes
     */
    private static function writePendingFilterRoutesFile(array $routes): void
    {
        $now = time();
        $routes = array_values(array_filter(
            $routes,
            static fn (array $entry): bool => $now <= (int) ($entry['until'] ?? 0)
        ));

        @file_put_contents(
            self::pendingFilterRoutesFile(),
            json_encode($routes) ?: '[]',
            LOCK_EX
        );
    }

    private static function pendingFilterRoutesFile(): string
    {
        $logPath = (string) (config('app')['log_path'] ?? '');
        $dir = $logPath !== ''
            ? dirname(dirname($logPath))
            : dirname(__DIR__, 2) . '/storage';

        return $dir . '/pending_filter_routes.json';
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

        $claimed = self::claimPendingFilterRouteFromList(
            is_array($_SESSION[self::PENDING_FILTER_ROUTES_KEY] ?? null)
                ? $_SESSION[self::PENDING_FILTER_ROUTES_KEY]
                : [],
            $messageId
        );

        if ($claimed !== null) {
            ensure_session_writable();
            $remaining = self::removePendingFilterRouteFromList(
                $_SESSION[self::PENDING_FILTER_ROUTES_KEY] ?? [],
                $messageId
            );
            if ($remaining === []) {
                unset($_SESSION[self::PENDING_FILTER_ROUTES_KEY]);
            } else {
                $_SESSION[self::PENDING_FILTER_ROUTES_KEY] = $remaining;
            }

            self::removePendingFilterRouteFromFile($messageId);

            return $claimed;
        }

        $fileRoutes = self::readPendingFilterRoutesFile();
        $claimed = self::claimPendingFilterRouteFromList($fileRoutes, $messageId);
        if ($claimed === null) {
            return null;
        }

        self::writePendingFilterRoutesFile(
            self::removePendingFilterRouteFromList($fileRoutes, $messageId)
        );

        return $claimed;
    }

    /**
     * @param list<array<string, mixed>> $routes
     * @return list<string>|null
     */
    private static function claimPendingFilterRouteFromList(array $routes, string $messageId): ?array
    {
        $now = time();

        foreach ($routes as $entry) {
            if (!is_array($entry) || $now > (int) ($entry['until'] ?? 0)) {
                continue;
            }

            if (normalize_message_id((string) ($entry['message_id'] ?? '')) === $messageId) {
                $paths = array_values(array_filter(
                    (array) ($entry['paths'] ?? []),
                    static fn (string $p): bool => $p !== ''
                ));

                return $paths !== [] ? $paths : null;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $routes
     * @return list<array<string, mixed>>
     */
    private static function removePendingFilterRouteFromList(array $routes, string $messageId): array
    {
        $remaining = [];
        $now = time();

        foreach ($routes as $entry) {
            if (!is_array($entry) || $now > (int) ($entry['until'] ?? 0)) {
                continue;
            }

            if (normalize_message_id((string) ($entry['message_id'] ?? '')) === $messageId) {
                continue;
            }

            $remaining[] = $entry;
        }

        return $remaining;
    }

    private static function removePendingFilterRouteFromFile(string $messageId): void
    {
        $routes = self::readPendingFilterRoutesFile();
        $remaining = self::removePendingFilterRouteFromList($routes, $messageId);
        self::writePendingFilterRoutesFile($remaining);
    }

    /**
     * Unread counts for every sidebar folder (including zeros).
     *
     * @return array<string, int>
     */
    public static function sidebarUnreadCounts(): array
    {
        return self::sidebarUnreadCountsByMode('full');
    }

    /**
     * Sidebar badges from session only — no per-folder DB/IMAP (safe on send/fragment).
     *
     * @return array<string, int>
     */
    public static function sidebarUnreadCountsSessionOnly(): array
    {
        return self::sidebarUnreadCountsByMode('session');
    }

    /**
     * Fast sidebar badges for polling — session + index truth where required.
     *
     * @return array<string, int>
     */
    public static function sidebarUnreadCountsFromSession(): array
    {
        return self::sidebarUnreadCountsByMode('hybrid');
    }

    /**
     * Unified sidebar badge reader.
     *
     * @param 'session'|'hybrid'|'full' $mode
     * @return array<string, int>
     */
    public static function sidebarUnreadCountsByMode(string $mode = 'full'): array
    {
        $folderData = self::load(skipUnreadRefresh: true);
        $session = $mode === 'session'
            ? self::normalizeUnreadCounts($folderData['unread_counts'] ?? [])
            : ($folderData['unread_counts'] ?? []);
        $result = [];
        $applyAdminSuppress = $mode !== 'full';

        foreach ($folderData['folders'] ?? [] as $folder) {
            $path = (string) ($folder['path'] ?? '');
            if ($path === '' || !folder_shows_unread_badge($path)) {
                continue;
            }

            $sessionCount = self::sessionUnreadForSidebarPath($path, $session);
            $messagesPath = employee_messages_imap_path($path);
            if (strcasecmp($messagesPath, $path) !== 0) {
                $sessionCount = max($sessionCount, self::sessionUnreadForSidebarPath($messagesPath, $session));
            }

            if ($mode === 'session') {
                $count = $sessionCount;
            } elseif (folder_badge_uses_index_truth($path)) {
                $count = MailCacheService::sidebarBadgeCount($path, $sessionCount);
            } elseif ($mode === 'hybrid') {
                $count = $sessionCount;
            } else {
                $count = $sessionCount > 0
                    ? $sessionCount
                    : MailCacheService::sidebarBadgeCount($path, $sessionCount);
            }

            if ($applyAdminSuppress && mail_admin_outbound_suppresses_sidebar_badge($path)) {
                $count = 0;
            }

            $result[$path] = $count;
            if (strcasecmp($messagesPath, $path) !== 0) {
                $result[$messagesPath] = $count;
            }
        }

        return $result;
    }

    private static function canonicalUnreadPath(string $path): string
    {
        $resolved = self::resolvePath($path);

        return self::resolvePath(employee_messages_imap_path($resolved));
    }

    /**
     * Raw session unread count for one folder (no load/filter/DB — safe during FolderCache::load).
     */
    public static function sessionUnreadCountRaw(string $path): int
    {
        $path = self::canonicalUnreadPath($path);
        if ($path === '' || !folder_shows_unread_badge($path)) {
            return 0;
        }

        $counts = $_SESSION[self::SESSION_KEY]['unread_counts'] ?? [];
        if (!is_array($counts)) {
            return 0;
        }

        $count = (int) ($counts[$path] ?? 0);
        $messagesPath = employee_messages_imap_path($path);
        if (strcasecmp($messagesPath, $path) !== 0) {
            $count = max($count, (int) ($counts[$messagesPath] ?? 0));
        }

        return max(0, $count);
    }

    /**
     * Read a sidebar badge from session counts (no per-folder DB/IMAP recompute).
     *
     * @param array<string, int> $session
     */
    private static function sessionUnreadForSidebarPath(string $path, array $session): int
    {
        if ($path === '' || !folder_shows_unread_badge($path)) {
            return 0;
        }

        $count = (int) ($session[$path] ?? 0);
        $messagesPath = employee_messages_imap_path($path);
        if (strcasecmp($messagesPath, $path) !== 0) {
            $count = max($count, (int) ($session[$messagesPath] ?? 0));
        }

        if (
            $count <= 0
            && !mail_admin_outbound_suppresses_sidebar_badge($path)
            && !mail_admin_outbound_suppresses_sidebar_badge($messagesPath)
            && (
                self::isPendingBadgePath($path)
                || self::isPendingBadgePath($messagesPath)
                || mail_post_send_preview_inflates_sidebar_badge($path)
                || mail_post_send_preview_inflates_sidebar_badge($messagesPath)
            )
        ) {
            $count = 1;
        }

        return max(0, $count);
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
            $canonical = self::resolvePath(employee_messages_imap_path((string) $path));
            if ($canonical === '' || !folder_shows_unread_badge($canonical)) {
                continue;
            }
            $count = (int) $count;
            if (!array_key_exists($canonical, $normalized)) {
                $normalized[$canonical] = $count;
                continue;
            }
            $normalized[$canonical] = max($normalized[$canonical], $count);
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

        if (Auth::user() !== null && empty($_SESSION['_mail_registry_defaults'])) {
            if ((new SystemBootstrapService())->ensureRegistryDefaults()) {
                (new self())->clear();
            }
            $_SESSION['_mail_registry_defaults'] = true;
        }

        if (!$refresh) {
            $cached = $cache->get();
            if ($cached !== null) {
                $session = $_SESSION[self::SESSION_KEY] ?? [];
                if (!$skipUnreadRefresh && time() > (int) ($session['unread_expires'] ?? 0)) {
                    perf_mark('fc_unread_refresh_start');
                    $cached = $cache->refreshUnread($cached);
                    perf_mark('fc_unread_refresh_done');
                }

                return $cache->filterForUser($cached);
            }
        }

        perf_mark('fc_cold_load_start');
        $imap = new ImapService();
        if (!$imap->connect()) {
            perf_mark('fc_cold_imap_connect_FAILED');
            return [
                'folders' => [],
                'unread_counts' => [],
                'connected' => false,
                'error' => $imap->getLastError(),
            ];
        }
        perf_mark('fc_cold_imap_connected');

        $folders = $imap->listFolders();
        perf_mark('fc_cold_list_done');
        $paths = array_column($folders, 'path');
        $unreadCounts = array_fill_keys($paths, 0);
        if (!$skipUnreadRefresh) {
            $statusPaths = $cache->statusBudgetPaths($paths);
            $unreadCounts = array_merge($unreadCounts, $imap->getFolderBadgeCounts($statusPaths));
            perf_mark('fc_cold_status_done');
        }
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
        $paths = $this->statusBudgetPaths($paths, $data['unread_counts'] ?? []);
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
        // Shared mailbox: every user sees every folder, so refresh all badges.
        return $allPaths;
    }

    /**
     * Cap how many folders get a live IMAP STATUS this request (see
     * STATUS_BUDGET). Priority folders — INBOX, system folders, anything
     * currently showing a badge — always make the cut so visible badges can
     * appear and clear; the remainder rotate via a session cursor so every
     * folder is eventually refreshed.
     *
     * @param list<string> $paths
     * @param array<string, int> $currentCounts
     * @return list<string>
     */
    private function statusBudgetPaths(array $paths, array $currentCounts = []): array
    {
        if (count($paths) <= self::STATUS_BUDGET) {
            return $paths;
        }

        $priority = [];
        $rest = [];
        foreach ($paths as $path) {
            $isPriority = strcasecmp($path, 'INBOX') === 0
                || system_folder_bucket_for_path($path) !== null
                || (int) ($currentCounts[$path] ?? 0) > 0;
            if ($isPriority) {
                $priority[] = $path;
            } else {
                $rest[] = $path;
            }
        }

        $selected = array_slice($priority, 0, self::STATUS_BUDGET);

        $budget = self::STATUS_BUDGET - count($selected);
        $restTotal = count($rest);
        if ($budget > 0 && $restTotal > 0) {
            $take = min($budget, $restTotal);
            $cursor = (int) ($_SESSION[self::STATUS_CURSOR_KEY] ?? 0) % $restTotal;
            for ($i = 0; $i < $take; $i++) {
                $selected[] = $rest[($cursor + $i) % $restTotal];
            }
            $_SESSION[self::STATUS_CURSOR_KEY] = ($cursor + $take) % $restTotal;
        }

        return $selected;
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
        // IMAP is the source of truth: a folder may be opened if it actually
        // exists on the mail server — not only if it was registered in the DB.
        if (!$cache->serverHasPath($path)) {
            return false;
        }

        // Shared mailbox: any authenticated user may open any folder that exists
        // on the server (verified above). Admin-only controls are gated separately
        // via requireAdmin() on the /admin routes.
        return true;
    }

    /**
     * @param array{folders: list<array{path: string, name: string, delimiter: string}>, unread_counts: array<string, int>, connected: bool, error: string} $data
     * @return array{folders: list<array{path: string, name: string, delimiter: string}>, unread_counts: array<string, int>, connected: bool, error: string}
     */
    private function filterForUser(array $data): array
    {
        $data = $this->enrichFromRegistry($data);

        $user = Auth::user();
        if ($user === null) {
            return $data;
        }

        // Shared mailbox: every authenticated user — admin and employees alike —
        // sees the same folders. Employees were previously scoped to their own
        // INBOX.<name> subtree; the client requires one shared mailbox for all,
        // so everyone now gets the same shared folder view.
        return $this->filterAdminFolders($data);
    }

    /**
     * Sidebar badge for one folder path (session + index truth when applicable).
     *
     * @param array<string, int> $session
     */
    private static function sidebarUnreadForFolderPath(string $path, array $session): int
    {
        $sessionCount = self::sessionUnreadForSidebarPath($path, $session);
        $messagesPath = employee_messages_imap_path($path);
        if (strcasecmp($messagesPath, $path) !== 0) {
            $sessionCount = max($sessionCount, self::sessionUnreadForSidebarPath($messagesPath, $session));
        }

        if (!folder_badge_uses_index_truth($path)) {
            return $sessionCount;
        }

        return MailCacheService::sidebarBadgeCount($path, $sessionCount);
    }

    /**
     * @param array{folders: list<array{path: string, name: string, delimiter: string}>, unread_counts: array<string, int>, connected: bool, error: string} $data
     * @return array{folders: list<array{path: string, name: string, delimiter: string}>, unread_counts: array<string, int>, connected: bool, error: string}
     */
    private function dedupeSidebarFoldersByMailboxRoot(array $data): array
    {
        $filtered = [];
        $counts = $data['unread_counts'] ?? [];
        $newCounts = [];
        $seen = [];

        foreach ($data['folders'] as $folder) {
            $path = (string) ($folder['path'] ?? '');
            if ($path === '') {
                continue;
            }

            $key = sidebar_mailbox_root_key($path);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $root = employee_mailbox_root_prefix(self::resolvePath($path));
            $displayPath = $root !== '' ? self::resolvePath($root) : $path;
            $filtered[] = [
                'path' => $displayPath,
                'name' => (string) ($folder['name'] ?? preg_replace('/^INBOX\./i', '', $displayPath)),
                'delimiter' => (string) ($folder['delimiter'] ?? '.'),
            ];
            $newCounts[$displayPath] = max(
                (int) ($counts[$path] ?? 0),
                (int) ($counts[$displayPath] ?? 0),
            );
            $messagesPath = employee_messages_imap_path($displayPath);
            if (strcasecmp($messagesPath, $displayPath) !== 0) {
                $newCounts[$messagesPath] = $newCounts[$displayPath];
            }
        }

        $data['folders'] = $filtered;
        $data['unread_counts'] = $newCounts;

        return $data;
    }

    /**
     * Employee sidebar: own system folders in primary nav; correspondent mailboxes
     * as flat entries under Folders (like admin), without nested Sent/Drafts/etc.
     *
     * @param array{folders: list<array{path: string, name: string, delimiter: string}>, unread_counts: array<string, int>, connected: bool, error: string} $data
     * @return array{folders: list<array{path: string, name: string, delimiter: string}>, unread_counts: array<string, int>, connected: bool, error: string}
     */
    private function filterEmployeeSidebarFolders(array $data, ?string $ownPrefix): array
    {
        if ($ownPrefix === null || $ownPrefix === '') {
            return $data;
        }

        $ownRoot = employee_mailbox_root_prefix($ownPrefix);
        $correspondentRoots = [];
        foreach (employee_correspondent_folder_paths() as $corrPath) {
            $root = employee_mailbox_root_prefix(self::resolvePath($corrPath));
            if ($root !== '') {
                $correspondentRoots[strtoupper($root)] = self::resolvePath($root);
            }
        }

        $filtered = [];
        $counts = [];

        foreach ($data['folders'] as $folder) {
            $path = (string) ($folder['path'] ?? '');
            if ($path === '' || $this->shouldHideEmployeeSidebarFolder($path, $ownRoot, $correspondentRoots)) {
                continue;
            }

            $filtered[] = $folder;
            $counts[$path] = (int) ($data['unread_counts'][$path] ?? 0);
        }

        $data['folders'] = $filtered;
        $data['unread_counts'] = $counts;

        return $data;
    }

    /**
     * @param array<string, string> $correspondentRoots upper(root) => root
     */
    private function shouldHideEmployeeSidebarFolder(string $path, string $ownRoot, array $correspondentRoots): bool
    {
        $path = self::resolvePath($path);

        if (employee_path_under_mailbox_root($path, $ownRoot)) {
            if (employee_is_mailbox_container($path)) {
                return true;
            }

            $messagesPath = employee_messages_imap_path($ownRoot);
            if (
                $messagesPath !== ''
                && strcasecmp($messagesPath, $ownRoot) !== 0
                && strcasecmp($path, $ownRoot) === 0
            ) {
                return true;
            }

            return false;
        }

        foreach ($correspondentRoots as $corrRoot) {
            if (!employee_path_under_mailbox_root($path, $corrRoot)) {
                continue;
            }

            return strcasecmp($path, $corrRoot) !== 0;
        }

        return false;
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
        $registry = $this->registeredActiveFolders();
        $orphanPrefixes = $this->orphanedEmployeeFolderPrefixes();
        $filtered = [];
        $counts = [];

        foreach ($data['folders'] as $folder) {
            $path = $folder['path'];
            if ($this->isEmployeeMailboxContainer($path, $employeeRoots)) {
                continue;
            }
            if ($this->isEmployeePersonalSubfolder($path, $employeeRoots)) {
                continue;
            }
            if (is_nested_employee_system_subfolder($path)) {
                continue;
            }
            // Only hide UNREGISTERED leftovers under an orphaned employee prefix.
            // A folder that is itself a registered, active mailbox — e.g. a custom
            // subfolder the admin created under an employee (INBOX.Employee-Email.Test-1)
            // — must always show, even if its employee container has no user/alias.
            if (
                !$this->matchesRegisteredMailbox($path, $registry)
                && $this->isUnderOrphanedEmployeePrefix($path, $orphanPrefixes)
            ) {
                continue;
            }
            if ($this->isUnregisteredInboxOrphan($path, $registry)) {
                continue;
            }

            $filtered[] = $folder;
            $counts[$path] = self::sidebarUnreadForFolderPath($path, $data['unread_counts'] ?? []);
            $messagesPath = employee_messages_imap_path($path);
            if (strcasecmp($messagesPath, $path) !== 0) {
                $counts[$messagesPath] = $counts[$path];
            }
        }

        $data['folders'] = $filtered;
        $data['unread_counts'] = $counts;

        ensure_session_writable();
        if (isset($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY]['unread_counts'] = self::normalizeUnreadCounts(
                self::sanitizeUnreadCounts($counts)
            );
        }

        return $this->finalizeAdminEmployeeFolderSidebar($data);
    }

    /**
     * One flat sidebar entry per registered employee mailbox (INBOX.Erik), even when
     * IMAP stores mail in INBOX.Erik.Inbox and the registry row uses either path.
     *
     * @param array{folders: list<array{path: string, name: string, delimiter: string}>, unread_counts: array<string, int>, connected: bool, error: string} $data
     * @return array{folders: list<array{path: string, name: string, delimiter: string}>, unread_counts: array<string, int>, connected: bool, error: string}
     */
    private function finalizeAdminEmployeeFolderSidebar(array $data): array
    {
        $registryEmployees = $this->registeredEmployeeMailboxRoots();

        if ($registryEmployees === []) {
            return $data;
        }

        $filtered = [];
        $counts = $data['unread_counts'] ?? [];
        $newCounts = [];
        $seenEmployeeRoots = [];

        foreach ($data['folders'] as $folder) {
            $path = (string) ($folder['path'] ?? '');
            if ($path === '') {
                continue;
            }

            $root = employee_mailbox_root_prefix($path);
            $upperRoot = strtoupper($root);

            if (isset($registryEmployees[$upperRoot])) {
                if (isset($seenEmployeeRoots[$upperRoot])) {
                    continue;
                }

                $seenEmployeeRoots[$upperRoot] = true;
                $displayPath = self::resolvePath($registryEmployees[$upperRoot]['path']);
                $filtered[] = [
                    'path' => $displayPath,
                    'name' => $registryEmployees[$upperRoot]['display_name'],
                    'delimiter' => (string) ($folder['delimiter'] ?? '.'),
                    'created_at' => (string) ($registryEmployees[$upperRoot]['created_at'] ?? ''),
                ];

                $messagesPath = employee_messages_imap_path($displayPath);
                $count = max(
                    (int) ($counts[$path] ?? 0),
                    (int) ($counts[$displayPath] ?? 0),
                    (int) ($counts[$messagesPath] ?? 0),
                );
                $newCounts[$displayPath] = $count;
                if (strcasecmp($messagesPath, $displayPath) !== 0) {
                    $newCounts[$messagesPath] = $count;
                }

                continue;
            }

            $filtered[] = $folder;
            $newCounts[$path] = (int) ($counts[$path] ?? 0);
        }

        foreach ($registryEmployees as $upperRoot => $info) {
            if (isset($seenEmployeeRoots[$upperRoot])) {
                continue;
            }

            $displayPath = self::resolvePath($info['path']);
            $filtered[] = [
                'path' => $displayPath,
                'name' => $info['display_name'],
                'delimiter' => '.',
                'created_at' => (string) ($info['created_at'] ?? ''),
            ];

            $messagesPath = employee_messages_imap_path($displayPath);
            $count = max(
                (int) ($counts[$displayPath] ?? 0),
                (int) ($counts[$messagesPath] ?? 0),
            );
            $newCounts[$displayPath] = $count;
            if (strcasecmp($messagesPath, $displayPath) !== 0) {
                $newCounts[$messagesPath] = $count;
            }
        }

        $data['folders'] = $filtered;
        $data['unread_counts'] = $newCounts;

        return $data;
    }

    /**
     * @return array<string, array{path: string, display_name: string}>
     */
    private function registeredEmployeeMailboxRoots(): array
    {
        $roots = [];

        try {
            $employees = Database::query(
                "SELECT u.id, u.name, u.username, f.imap_path, f.display_name, f.created_at
                 FROM users u
                 LEFT JOIN folders f ON f.linked_user_id = u.id AND f.active = 1 AND f.folder_type = 'employee'
                 WHERE u.role = 'employee' AND u.active = 1"
            )->fetchAll();

            foreach ($employees as $row) {
                $imapPath = trim((string) ($row['imap_path'] ?? ''));
                if ($imapPath === '') {
                    $safeFolder = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($row['username'] ?? ''));
                    if ($safeFolder === '') {
                        continue;
                    }
                    $imapPath = 'INBOX.' . $safeFolder;
                }

                $root = employee_mailbox_root_prefix(self::resolvePath($imapPath));
                if ($root === '') {
                    continue;
                }

                $displayName = trim((string) ($row['display_name'] ?? ''));
                if ($displayName === '') {
                    $displayName = trim((string) ($row['name'] ?? ''));
                }
                if ($displayName === '') {
                    $displayName = preg_replace('/^INBOX\./i', '', $root);
                }

                $roots[strtoupper($root)] = [
                    'path' => $root,
                    'display_name' => $displayName,
                    'created_at' => (string) ($row['created_at'] ?? ''),
                ];
            }

            $aliasRows = Database::query(
                "SELECT f.imap_path, f.display_name, f.created_at
                 FROM folders f
                 WHERE f.active = 1 AND f.folder_type = 'employee'
                   AND (f.linked_user_id IS NULL OR NOT EXISTS (
                       SELECT 1 FROM users u
                       WHERE u.id = f.linked_user_id AND u.role = 'employee' AND u.active = 1
                   ))"
            )->fetchAll();

            foreach ($aliasRows as $row) {
                $root = employee_mailbox_root_prefix(self::resolvePath((string) ($row['imap_path'] ?? '')));
                if ($root === '') {
                    continue;
                }

                $upper = strtoupper($root);
                if (isset($roots[$upper])) {
                    continue;
                }

                $roots[$upper] = [
                    'path' => $root,
                    'display_name' => (string) ($row['display_name'] ?? preg_replace('/^INBOX\./i', '', $root)),
                    'created_at' => (string) ($row['created_at'] ?? ''),
                ];
            }
        } catch (\Throwable) {
            // ignore
        }

        return $roots;
    }

    /**
     * Employee folders with no active linked user (left behind after account delete).
     * Alias-backed mailboxes (e.g. Support) are excluded — they intentionally have no user.
     *
     * @return list<string>
     */
    private function orphanedEmployeeFolderPrefixes(): array
    {
        $prefixes = [];

        try {
            $rows = Database::query(
                "SELECT f.imap_path
                 FROM folders f
                 LEFT JOIN users u ON u.id = f.linked_user_id AND u.active = 1
                 WHERE f.folder_type = 'employee'
                   AND f.active = 1
                   AND (f.linked_user_id IS NULL OR u.id IS NULL)
                   AND NOT EXISTS (
                       SELECT 1 FROM aliases a
                       WHERE a.default_folder_id = f.id AND a.active = 1
                   )"
            )->fetchAll();

            foreach ($rows as $row) {
                $path = trim((string) ($row['imap_path'] ?? ''));
                if ($path !== '') {
                    $prefixes[] = $path;
                }
            }
        } catch (\Throwable) {
            // ignore
        }

        return $prefixes;
    }

    /**
     * @param list<string> $orphanPrefixes
     */
    private function isUnderOrphanedEmployeePrefix(string $path, array $orphanPrefixes): bool
    {
        foreach ($orphanPrefixes as $prefix) {
            $prefix = rtrim($prefix, '.');
            if (strcasecmp($path, $prefix) === 0) {
                return true;
            }

            $withDot = $prefix . '.';
            if (
                strlen($path) > strlen($withDot)
                && strncasecmp($path, $withDot, strlen($withDot)) === 0
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Hide custom INBOX.* folders that exist on IMAP but were removed from the
     * admin registry (e.g. after deleting an employee account).
     *
     * @param array<string, array{path: string, display_name: string}> $registry
     */
    /**
     * Whether $path is (or lives under) a folder that is registered and active —
     * i.e. an intentional mailbox, not a stray IMAP leftover.
     *
     * @param array<int|string, array<string, mixed>> $registry
     */
    private function matchesRegisteredMailbox(string $path, array $registry): bool
    {
        foreach ($registry as $key => $entry) {
            $regPath = (string) ($entry['path'] ?? $key);
            if ($this->pathMatchesRegistryMailbox($path, $regPath)) {
                return true;
            }
        }

        return false;
    }

    private function isUnregisteredInboxOrphan(string $path, array $registry): bool
    {
        if ($this->matchesRegisteredMailbox($path, $registry)) {
            return false;
        }

        if (system_folder_bucket_for_path($path) !== null) {
            return false;
        }

        if (strcasecmp($path, 'INBOX') === 0) {
            return false;
        }

        if (!str_starts_with(strtoupper($path), 'INBOX.')) {
            return false;
        }

        return true;
    }

    private function pathMatchesRegistryMailbox(string $path, string $registryPath): bool
    {
        $path = self::resolvePath($path);
        $registryPath = self::resolvePath($registryPath);
        if ($path === '' || $registryPath === '') {
            return false;
        }

        if (strcasecmp($path, $registryPath) === 0) {
            return true;
        }

        return strcasecmp(
            employee_mailbox_root_prefix($path),
            employee_mailbox_root_prefix($registryPath),
        ) === 0;
    }

    /**
     * @return list<string>
     */
    private function employeeMailboxRoots(): array
    {
        if (self::$employeeRootsCache !== null) {
            return self::$employeeRootsCache;
        }

        $roots = [];
        try {
            $rows = Database::query(
                "SELECT imap_path FROM folders WHERE folder_type = 'employee' AND active = 1"
            )->fetchAll();
            foreach ($rows as $row) {
                $root = employee_mailbox_root_prefix((string) ($row['imap_path'] ?? ''));
                if ($root !== '') {
                    $roots[strtoupper($root)] = $root;
                }
            }
        } catch (\Throwable) {
            // ignore
        }

        self::$employeeRootsCache = array_values($roots);

        return self::$employeeRootsCache;
    }

    /**
     * @param list<string> $employeeRoots
     */
    private function isEmployeePersonalSubfolder(string $path, array $employeeRoots): bool
    {
        if (is_nested_employee_system_subfolder($path)) {
            return true;
        }

        $systemLeaves = ['sent', 'drafts', 'draft', 'archive', 'junk', 'spam', 'trash'];

        foreach ($employeeRoots as $root) {
            $prefix = rtrim(employee_mailbox_root_prefix($root), '.') . '.';
            if (
                strlen($path) <= strlen($prefix)
                || strncasecmp($path, $prefix, strlen($prefix)) !== 0
            ) {
                continue;
            }

            $leaf = strtolower(substr($path, strlen($prefix)));
            if (in_array($leaf, $systemLeaves, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Hide INBOX.Name container mailboxes when employee mail lives in INBOX.Name.Inbox.
     *
     * @param list<string> $employeeRoots
     */
    private function isEmployeeMailboxContainer(string $path, array $employeeRoots): bool
    {
        foreach ($employeeRoots as $root) {
            $container = employee_mailbox_root_prefix($root);
            if (strcasecmp($path, $container) === 0 && strcasecmp($root, $container) !== 0) {
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
                return employee_mailbox_root_prefix((string) $linked['imap_path']);
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
                'SELECT imap_path, display_name, created_at FROM folders WHERE active = 1'
            )->fetchAll();
            foreach ($rows as $row) {
                $path = (string) $row['imap_path'];
                $registry[strtoupper($path)] = [
                    'path' => $path,
                    'display_name' => (string) $row['display_name'],
                    'created_at' => (string) ($row['created_at'] ?? ''),
                ];
            }
        } catch (\Throwable $e) {
            app_log('Folder registry load failed: ' . $e->getMessage());
        }

        self::$registryCache = $registry;

        return $registry;
    }

    /**
     * True when the folder exists on the IMAP server (case-insensitive). Uses
     * the cached folder list, falling back to a live server check so a folder
     * created earlier in the same request is reachable before the cache warms.
     */
    private function serverHasPath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        self::warmPathResolveCache();
        if (isset(self::$pathResolveCache[strtoupper($path)])) {
            return true;
        }

        $imap = new ImapService();

        return $imap->connect() && $imap->folderExistsOnServer($path);
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
     * IMAP is the source of truth for which folders exist. Keep every folder the
     * server reports and only *enrich* it from the admin registry (a friendlier
     * display name when one was configured). Folders that exist on the server but
     * were never registered still appear — this is what makes the webmail
     * auto-discover the real mailbox tree without any manual folder setup.
     *
     * @param array{folders: list<array{path: string, name: string, delimiter: string}>, unread_counts: array<string, int>, connected: bool, error: string} $data
     * @return array{folders: list<array{path: string, name: string, delimiter: string}>, unread_counts: array<string, int>, connected: bool, error: string}
     */
    private function enrichFromRegistry(array $data): array
    {
        $registry = $this->registeredActiveFolders();

        $folders = [];
        $counts = [];
        $seen = [];

        foreach ($data['folders'] as $folder) {
            $imapPath = (string) ($folder['path'] ?? '');
            if ($imapPath === '') {
                continue;
            }

            $key = strtoupper($imapPath);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            // Registry supplies a nicer label and the creation time (so the
            // sidebar can order folders by when they were made, like the admin
            // table); the exact IMAP path is kept for open/list/move operations.
            if (isset($registry[$key])) {
                if ((string) ($registry[$key]['display_name'] ?? '') !== '') {
                    $folder['name'] = (string) $registry[$key]['display_name'];
                }
                $folder['created_at'] = (string) ($registry[$key]['created_at'] ?? '');
            }

            $folders[] = $folder;
            $counts[$imapPath] = (int) ($data['unread_counts'][$imapPath] ?? 0);
        }

        $data['folders'] = $folders;
        $data['unread_counts'] = self::sanitizeUnreadCounts($counts);

        return $data;
    }
}
