<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth;
use App\Database;

class MailCacheService
{
    /** @var array<string, string> lowercase path => mail_index folder_path */
    private static array $indexFolderPathCache = [];

    /** @var array<string, int|null> uppercase path => linked_user_id */
    private static array $linkedUserCache = [];

    /** @var array{link: array<string, int>, shared: array<string, bool>}|null */
    private static ?array $registryMaps = null;

    /** @var array<string, string>|null lowercase folder_path => canonical index path */
    private static ?array $indexPathMap = null;

    /** @var array<string, true>|null uppercase synced folder paths (see syncedFolderPathSet) */
    private static ?array $syncedPathSet = null;

    /** @var array<string, int>|null lowercase folder_path => unseen count (see unseenCountMap) */
    private static ?array $unseenCountMap = null;

    /** @var array<string, bool> memo for badgeUsesIndexTruth, keyed by lowercase resolved path */
    private static array $badgeTruthMemo = [];

    /**
     * Forget the per-request registry/index maps (after registry mutations).
     */
    public static function resetRuntimeMaps(): void
    {
        self::$registryMaps = null;
        self::$indexPathMap = null;
        self::$syncedPathSet = null;
        self::$unseenCountMap = null;
        self::$badgeTruthMemo = [];
        // These derive from the registry / index paths too — a registry
        // mutation must not leave them serving pre-mutation resolutions.
        self::$indexFolderPathCache = [];
        self::$linkedUserCache = [];
    }

    /**
     * Forget the sync-state derived maps after a mail_sync_state write, so a
     * folder synced within this request immediately reads as "has data".
     */
    private static function resetSyncStateMaps(): void
    {
        self::$syncedPathSet = null;
        self::$indexPathMap = null;
        self::$unseenCountMap = null;
        // badgeUsesIndexTruth's hasFolderData clause flips when a folder gets
        // synced mid-request; indexFolderPath fallbacks may become resolvable.
        self::$badgeTruthMemo = [];
        self::$indexFolderPathCache = [];
    }

    /**
     * Forget the unseen-count map after any mail_index write, so badges
     * recomputed later in the same request see the new counts.
     */
    private static function resetUnseenCountMap(): void
    {
        self::$unseenCountMap = null;
    }

    /**
     * ONE query for every folder's unseen count instead of a COUNT(*) per
     * folder — the sidebar/badge loops call countUnseenInIndex() per folder,
     * which at ~1000 registered folders meant ~1000+ COUNT queries per request.
     *
     * @return array<string, int> lowercase folder_path => unseen count
     */
    private static function unseenCountMap(): array
    {
        if (self::$unseenCountMap !== null) {
            return self::$unseenCountMap;
        }

        $map = [];
        try {
            foreach (Database::query(
                // backfilled = 0: history-backfilled old unread never counts
                // toward sidebar badges (user decision) — and the covering
                // idx_mail_index_badge keeps this an index-only range scan at
                // millions of backfilled rows.
                'SELECT folder_path, COUNT(*) AS c FROM mail_index WHERE seen = 0 AND backfilled = 0 GROUP BY folder_path'
            )->fetchAll() as $row) {
                $path = strtolower((string) ($row['folder_path'] ?? ''));
                if ($path !== '') {
                    $map[$path] = (int) ($row['c'] ?? 0);
                }
            }
        } catch (\Throwable) {
            // DB unavailable — no counts (matches the old per-query failure mode).
        }

        return self::$unseenCountMap = $map;
    }

    /**
     * ONE query for the whole employee-folder registry instead of per-folder
     * LOWER(imap_path) lookups. With a huge imported registry (~1000 folders)
     * the per-folder query families (indexFolderPath, linkedUserId,
     * isSharedEmployeeMailbox — each 1-6 queries per miss, called for every
     * folder on every FolderCache::load) added up to thousands of table scans
     * per request and made EVERY page take 10s+.
     *
     * @return array{link: array<string, int>, shared: array<string, bool>}
     */
    private static function registryMaps(): array
    {
        if (self::$registryMaps !== null) {
            return self::$registryMaps;
        }

        $maps = ['link' => [], 'shared' => []];
        try {
            $rows = Database::query(
                "SELECT imap_path, linked_user_id FROM folders WHERE active = 1 AND folder_type = 'employee'"
            )->fetchAll();
            foreach ($rows as $row) {
                $path = strtolower((string) ($row['imap_path'] ?? ''));
                if ($path === '') {
                    continue;
                }
                if (!empty($row['linked_user_id'])) {
                    $maps['link'][$path] = (int) $row['linked_user_id'];
                } else {
                    $maps['shared'][$path] = true;
                }
            }
        } catch (\Throwable) {
            // DB unavailable — behave like "no employee folders" (matches the
            // old per-query catch blocks).
        }

        return self::$registryMaps = $maps;
    }

    /**
     * ONE query per table for every indexed folder path (mail_sync_state has a
     * row per synced folder, mail_index a handful of distinct paths) instead of
     * six LOWER(folder_path) scans per never-indexed folder.
     *
     * @return array<string, string>
     */
    private static function indexPathMap(): array
    {
        if (self::$indexPathMap !== null) {
            return self::$indexPathMap;
        }

        $map = [];
        try {
            foreach (Database::query('SELECT folder_path FROM mail_sync_state')->fetchAll() as $row) {
                $path = (string) ($row['folder_path'] ?? '');
                if ($path !== '' && !isset($map[strtolower($path)])) {
                    $map[strtolower($path)] = $path;
                }
            }
            foreach (Database::query('SELECT DISTINCT folder_path FROM mail_index')->fetchAll() as $row) {
                $path = (string) ($row['folder_path'] ?? '');
                if ($path !== '' && !isset($map[strtolower($path)])) {
                    $map[strtolower($path)] = $path;
                }
            }
        } catch (\Throwable) {
            // DB unavailable — no indexed folders visible (same as old behavior).
        }

        return self::$indexPathMap = $map;
    }

    /**
     * Folder path as stored in mail_index / mail_sync_state (case-insensitive lookup).
     */
    public static function indexFolderPath(string $folderPath): string
    {
        if ($folderPath === '') {
            return '';
        }

        $lookup = strtolower($folderPath);
        if (isset(self::$indexFolderPathCache[$lookup])) {
            return self::$indexFolderPathCache[$lookup];
        }

        $resolved = FolderCache::resolvePath($folderPath);
        $messagesPath = employee_messages_imap_path($resolved);

        // For a shared/linked mailbox ROOT (e.g. INBOX.support, INBOX.Jean) the mail
        // lives in its messages subfolder (…​.Inbox), so canonicalise to that first.
        // Otherwise a spurious/empty parent sync_state row would win and the badge,
        // list and read state would all read an empty index. (Linked inboxes already
        // worked because their parent had no own row; this makes shared mailboxes
        // behave the same.) Normal folders map to themselves.
        $candidates = [];
        if ($messagesPath !== '' && strcasecmp($messagesPath, $resolved) !== 0) {
            $candidates[] = $messagesPath;
        }
        $candidates[] = $folderPath;
        $candidates[] = $resolved;

        $map = self::indexPathMap();
        foreach ($candidates as $candidate) {
            $canonical = $map[strtolower($candidate)] ?? '';
            if ($canonical !== '') {
                self::$indexFolderPathCache[$lookup] = $canonical;

                return $canonical;
            }
        }

        self::$indexFolderPathCache[$lookup] = $resolved;

        return $resolved;
    }

    public static function linkedUserId(string $folderPath): ?int
    {
        $folderPath = FolderCache::resolvePath($folderPath);
        if ($folderPath === '') {
            return null;
        }

        $key = strtoupper($folderPath);
        if (array_key_exists($key, self::$linkedUserCache)) {
            return self::$linkedUserCache[$key];
        }

        $candidates = [$folderPath];
        $root = employee_mailbox_root_prefix($folderPath);
        if ($root !== '' && strcasecmp($root, $folderPath) !== 0) {
            $candidates[] = $root;
        }
        $messagesPath = employee_messages_imap_path($folderPath);
        if ($messagesPath !== '' && strcasecmp($messagesPath, $folderPath) !== 0) {
            $candidates[] = $messagesPath;
        }

        $maps = self::registryMaps();
        $linkedId = null;
        foreach (array_values(array_unique($candidates)) as $candidate) {
            $id = $maps['link'][strtolower($candidate)] ?? null;
            if ($id !== null) {
                $linkedId = $id;
                break;
            }
        }
        self::$linkedUserCache[$key] = $linkedId;

        return self::$linkedUserCache[$key];
    }

    public static function usesPerUserRead(string $folderPath): bool
    {
        return self::linkedUserId($folderPath) !== null;
    }

    /**
     * Only the linked employee's reads/writes update IMAP and mail_index.seen.
     */
    public static function readUpdatesImapState(string $folderPath, ?array $user = null): bool
    {
        $user = $user ?? Auth::user();
        if ($user === null) {
            return true;
        }

        $userId = (int) ($user['id'] ?? 0);
        if (
            $userId > 0
            && ($user['role'] ?? '') === 'employee'
            && self::sharedMailboxUsesPerUserSeen($folderPath, $userId)
        ) {
            return false;
        }

        if (!self::usesPerUserRead($folderPath)) {
            return true;
        }

        $linkedId = self::linkedUserId($folderPath);

        return $linkedId !== null && $userId === $linkedId;
    }

    public static function viewerIsAdmin(?array $user = null): bool
    {
        $user = $user ?? Auth::user();

        return $user !== null && ($user['role'] ?? '') === 'admin';
    }


    public static function countRawUnseenInIndex(string $folderPath): int
    {
        $folderPath = self::indexFolderPath($folderPath);
        if ($folderPath === '') {
            return 0;
        }

        $row = Database::fetchOne(
            'SELECT COUNT(*) AS c FROM mail_index WHERE folder_path = ? AND seen = 0',
            [$folderPath]
        );

        return (int) ($row['c'] ?? 0);
    }

    /**
     * One-time repair for outbound copies wrongly marked seen on IMAP before
     * per-user read: employee gets mail_user_read, index stays unseen for admin.
     */
    public static function repairEmployeeCorrespondentOutboundForAdmin(int $employeeUserId): void
    {
        static $done = [];
        if ($employeeUserId <= 0 || isset($done[$employeeUserId])) {
            return;
        }
        $done[$employeeUserId] = true;

        $ownInbox = self::indexFolderPath(
            employee_linked_inbox_path(['id' => $employeeUserId, 'role' => 'employee']) ?? ''
        );
        $emails = mail_user_emails($employeeUserId);
        if ($ownInbox === '' || $emails === []) {
            return;
        }

        $fromClauses = [];
        $params = [$ownInbox];
        foreach ($emails as $email) {
            $fromClauses[] = 'LOWER(i.from_addr) LIKE ?';
            $params[] = '%' . strtolower($email) . '%';
        }
        $params[] = $employeeUserId;

        try {
            $rows = Database::query(
                'SELECT i.folder_path, i.imap_uid
                 FROM mail_index i
                 INNER JOIN folders f
                    ON LOWER(f.imap_path) = LOWER(i.folder_path)
                   AND f.active = 1
                   AND f.folder_type = \'employee\'
                 WHERE LOWER(i.folder_path) != LOWER(?)
                   AND (' . implode(' OR ', $fromClauses) . ')
                   AND i.seen = 1
                   AND NOT EXISTS (
                       SELECT 1 FROM mail_user_read r
                       WHERE r.user_id = ?
                         AND r.folder_path = i.folder_path
                         AND r.imap_uid = i.imap_uid
                   )
                 ORDER BY i.msg_date DESC
                 LIMIT 50',
                $params
            )->fetchAll();
        } catch (\Throwable) {
            return;
        }

        foreach ($rows as $row) {
            $path = (string) ($row['folder_path'] ?? '');
            $uid = (int) ($row['imap_uid'] ?? 0);
            if ($path === '' || $uid <= 0) {
                continue;
            }

            self::markReadForUser($path, $uid, $employeeUserId);
            Database::query(
                'UPDATE mail_index SET seen = 0 WHERE folder_path = ? AND imap_uid = ?',
                [$path, $uid]
            );
            self::resetUnseenCountMap();
        }
    }

    /**
     * Outbound copies in other employee folders (Support, etc.) awaiting admin review.
     */
    public static function countUnseenEmployeeOutboundInCorrespondentFolders(int $employeeUserId, ?int $viewerId = null): int
    {
        if ($employeeUserId <= 0) {
            return 0;
        }

        $viewerId = $viewerId ?? (int) (Auth::user()['id'] ?? 0);
        if ($viewerId <= 0) {
            return 0;
        }

        $ownInbox = self::indexFolderPath(
            employee_linked_inbox_path(['id' => $employeeUserId, 'role' => 'employee']) ?? ''
        );
        $emails = mail_user_emails($employeeUserId);
        if ($ownInbox === '' || $emails === []) {
            return 0;
        }

        $fromClauses = [];
        $params = [$ownInbox];
        foreach ($emails as $email) {
            $fromClauses[] = 'LOWER(i.from_addr) LIKE ?';
            $params[] = '%' . strtolower($email) . '%';
        }
        $params[] = $viewerId;

        try {
            $row = Database::fetchOne(
                'SELECT COUNT(*) AS c
                 FROM mail_index i
                 INNER JOIN folders f
                    ON LOWER(f.imap_path) = LOWER(i.folder_path)
                   AND f.active = 1
                   AND f.folder_type = \'employee\'
                 WHERE LOWER(i.folder_path) != LOWER(?)
                   AND (' . implode(' OR ', $fromClauses) . ')
                   AND NOT EXISTS (
                       SELECT 1 FROM mail_user_read r
                       WHERE r.user_id = ?
                         AND r.folder_path = i.folder_path
                         AND r.imap_uid = i.imap_uid
                   )',
                $params
            );
        } catch (\Throwable) {
            return 0;
        }

        return (int) ($row['c'] ?? 0);
    }

    /**
     * Employee sent into a correspondent folder: per-user read for them, unseen for admin.
     */
    public static function markEmployeeCorrespondentOutbound(
        string $folderPath,
        int $employeeUserId,
        string $fromEmail,
        ?string $sentMessageId = null,
        int $limit = 10,
    ): void {
        $folderPath = self::indexFolderPath($folderPath);
        if ($folderPath === '' || $employeeUserId <= 0) {
            return;
        }

        $emails = mail_user_emails($employeeUserId);
        $fromEmail = strtolower(trim($fromEmail));
        if ($fromEmail !== '' && !in_array($fromEmail, $emails, true)) {
            $emails[] = $fromEmail;
        }
        if ($emails === []) {
            return;
        }

        $fromClauses = [];
        $params = [$folderPath];
        foreach ($emails as $email) {
            $fromClauses[] = 'LOWER(i.from_addr) LIKE ?';
            $params[] = '%' . strtolower($email) . '%';
        }

        try {
            $rows = Database::query(
                'SELECT i.imap_uid, b.message_id
                 FROM mail_index i
                 LEFT JOIN mail_bodies b
                    ON b.folder_path = i.folder_path AND b.imap_uid = i.imap_uid
                 WHERE i.folder_path = ? AND (' . implode(' OR ', $fromClauses) . ')
                 ORDER BY i.msg_date DESC, i.imap_uid DESC
                 LIMIT ' . (int) max(1, $limit),
                $params
            )->fetchAll();
        } catch (\Throwable) {
            return;
        }

        $normalizedId = normalize_message_id($sentMessageId);

        foreach ($rows as $row) {
            $uid = (int) ($row['imap_uid'] ?? 0);
            if ($uid <= 0) {
                continue;
            }
            if ($normalizedId !== '') {
                $msgId = normalize_message_id((string) ($row['message_id'] ?? ''));
                if ($msgId === '' || $msgId !== $normalizedId) {
                    continue;
                }
            }

            self::markReadForUser($folderPath, $uid, $employeeUserId);
            Database::query(
                'UPDATE mail_index SET seen = 0 WHERE folder_path = ? AND imap_uid = ?',
                [$folderPath, $uid]
            );
            self::resetUnseenCountMap();
        }
    }

    public static function reconcileAdminOutboundToEmployeeInbox(
        string $folderPath,
        int $adminUserId,
        string $fromEmail,
        ?string $sentMessageId = null,
        int $limit = 5,
    ): void {
        $folderPath = self::indexFolderPath($folderPath);
        if ($folderPath === '' || $adminUserId <= 0) {
            return;
        }

        $fromEmail = strtolower(trim($fromEmail));
        if ($fromEmail === '') {
            return;
        }

        $params = [$folderPath, '%' . $fromEmail . '%'];

        try {
            $rows = Database::query(
                'SELECT i.imap_uid, b.message_id
                 FROM mail_index i
                 LEFT JOIN mail_bodies b
                    ON b.folder_path = i.folder_path AND b.imap_uid = i.imap_uid
                 WHERE i.folder_path = ? AND LOWER(i.from_addr) LIKE ?
                 ORDER BY i.msg_date DESC, i.imap_uid DESC
                 LIMIT ' . (int) max(1, $limit),
                $params
            )->fetchAll();
        } catch (\Throwable) {
            return;
        }

        $normalizedId = normalize_message_id($sentMessageId);

        foreach ($rows as $row) {
            $uid = (int) ($row['imap_uid'] ?? 0);
            if ($uid <= 0) {
                continue;
            }
            if ($normalizedId !== '') {
                $msgId = normalize_message_id((string) ($row['message_id'] ?? ''));
                if ($msgId === '' || $msgId !== $normalizedId) {
                    continue;
                }
            }

            self::markReadForUser($folderPath, $uid, $adminUserId);
        }
    }


    /**
     * Folder paths that may store per-user read flags for one mailbox
     * (canonical index path plus legacy root / .Inbox variants).
     *
     * @return list<string>
     */
    public static function userReadFolderPaths(string $folderPath): array
    {
        $canonical = self::indexFolderPath($folderPath);
        if ($canonical === '') {
            return [];
        }

        $paths = [$canonical];
        $resolved = FolderCache::resolvePath($canonical);
        $root = employee_mailbox_root_prefix($resolved);
        if ($root !== '' && strcasecmp($root, $canonical) !== 0) {
            $indexedRoot = self::indexFolderPath($root);
            if ($indexedRoot !== '' && strcasecmp($indexedRoot, $canonical) !== 0) {
                $paths[] = $indexedRoot;
            }
        }

        $messagesPath = employee_messages_imap_path($resolved);
        if ($messagesPath !== '' && strcasecmp($messagesPath, $canonical) !== 0) {
            $indexedMessages = self::indexFolderPath($messagesPath);
            if ($indexedMessages !== '' && strcasecmp($indexedMessages, $canonical) !== 0) {
                $paths[] = $indexedMessages;
            }
        }

        return array_values(array_unique(array_filter($paths)));
    }



    public static function markReadForUser(string $folderPath, int $uid, ?int $userId = null): void
    {
        $userId = $userId ?? (int) (Auth::user()['id'] ?? 0);
        if ($userId <= 0 || $uid <= 0) {
            return;
        }

        $folderPath = self::indexFolderPath($folderPath);
        Database::query(
            'INSERT INTO mail_user_read (user_id, folder_path, imap_uid, read_at)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE read_at = NOW()',
            [$userId, $folderPath, $uid]
        );
    }

    public static function markUnreadForUser(string $folderPath, int $uid, ?int $userId = null): void
    {
        $userId = $userId ?? (int) (Auth::user()['id'] ?? 0);
        if ($userId <= 0 || $uid <= 0) {
            return;
        }

        $folderPath = self::indexFolderPath($folderPath);
        Database::query(
            'DELETE FROM mail_user_read WHERE user_id = ? AND folder_path = ? AND imap_uid = ?',
            [$userId, $folderPath, $uid]
        );
    }

    public static function effectiveSeen(string $folderPath, int $uid, ?int $userId = null): bool
    {
        // Plain per-folder model: read state is the message's own IMAP \Seen flag
        // (mirrored in mail_index.seen). No per-user read tracking.
        return (bool) self::indexSeenState(self::indexFolderPath($folderPath), $uid);
    }

    /**
     * Employees reading a shared correspondent mailbox (e.g. Support) track seen
     * state per user — IMAP \\Seen on outbound copies must not hide admin replies.
     */
    public static function sharedMailboxUsesPerUserSeen(string $folderPath, ?int $userId = null): bool
    {
        $userId = $userId ?? (int) (Auth::user()['id'] ?? 0);
        if ($userId <= 0 || !self::isSharedEmployeeMailbox($folderPath)) {
            return false;
        }

        $user = Auth::user();
        if ($user !== null && (int) ($user['id'] ?? 0) === $userId) {
            return ($user['role'] ?? '') === 'employee'
                && employee_is_correspondent_folder($folderPath);
        }

        try {
            $row = Database::fetchOne(
                'SELECT role FROM users WHERE id = ? AND active = 1 LIMIT 1',
                [$userId]
            );
        } catch (\Throwable) {
            return false;
        }

        return $row !== null
            && ($row['role'] ?? '') === 'employee'
            && employee_is_correspondent_folder($folderPath);
    }


    /**
     * Thread-grouped unread count for linked employee inboxes — aligned with the list.
     */
    public static function countThreadGroupedUnseenForUser(int $userId, string $folderPath): int
    {
        if ($userId <= 0) {
            return 0;
        }

        $folderPath = self::indexFolderPath($folderPath);
        $removed = mail_removed_uids_for_folder($folderPath);

        try {
            $rows = Database::query(
                'SELECT i.imap_uid, i.from_addr, i.subject, i.msg_date, i.seen, i.flagged, i.has_attachment, i.size,
                        COALESCE(i.to_addrs, \'\') AS to_addrs,
                        COALESCE(i.cc_addrs, \'\') AS cc_addrs
                 FROM mail_index i
                 WHERE i.folder_path = ?',
                [$folderPath]
            )->fetchAll();
        } catch (\Throwable) {
            return 0;
        }

        $groups = [];
        foreach ($rows as $row) {
            $uid = (int) ($row['imap_uid'] ?? 0);
            if ($uid <= 0 || isset($removed[$uid])) {
                continue;
            }
            if (employee_is_own_inbox_folder($folderPath)) {
                $check = [
                    'from' => (string) ($row['from_addr'] ?? ''),
                    'subject' => (string) ($row['subject'] ?? ''),
                    'date' => (string) ($row['msg_date'] ?? ''),
                ];
                if (employee_should_hide_inbox_correspondent_message($check)) {
                    continue;
                }
            } elseif (
                self::viewerIsAdmin(['id' => $userId, 'role' => 'admin'])
                && mail_linked_user_id_for_inbox($folderPath) !== null
            ) {
                $check = [
                    'from' => (string) ($row['from_addr'] ?? ''),
                    'subject' => (string) ($row['subject'] ?? ''),
                    'date' => (string) ($row['msg_date'] ?? ''),
                ];
                if (admin_should_hide_employee_inbox_correspondent_message($check)) {
                    continue;
                }
            }

            $key = mail_normalize_thread_subject((string) ($row['subject'] ?? ''));
            if ($key === '') {
                $key = 'uid:' . $uid;
            }

            $msgTs = strtotime((string) ($row['msg_date'] ?? '')) ?: 0;
            $existingTs = isset($groups[$key])
                ? (strtotime((string) ($groups[$key]['msg_date'] ?? '')) ?: 0)
                : -1;
            if (
                !isset($groups[$key])
                || $msgTs > $existingTs
                || ($msgTs === $existingTs && $uid > (int) ($groups[$key]['imap_uid'] ?? 0))
            ) {
                $groups[$key] = $row;
            }
        }

        $count = 0;
        $groupRows = array_values($groups);

        foreach ($groupRows as $row) {
            $uid = (int) ($row['imap_uid'] ?? 0);
            if ($uid <= 0) {
                continue;
            }

            if (!self::effectiveSeen($folderPath, $uid, $userId)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return array{messages: list<array<string, mixed>>, total: int, page: int, per_page: int, total_pages: int, from_cache: bool}|null
     */
    public static function listFromCache(string $folderPath, int $page, int $perPage): ?array
    {
        $folderPath = self::indexFolderPath($folderPath);
        $state = self::getSyncState($folderPath);
        $indexed = self::countListableMessagesInIndex($folderPath);

        if ($state === null || empty($state['last_sync_at'])) {
            if ($indexed <= 0) {
                return null;
            }

            $total = $indexed;
            $totalPages = (int) max(1, (int) ceil($total / $perPage));
            $page = max(1, min($page, $totalPages));
            $offset = ($page - 1) * $perPage;
        } else {
            $total = (int) ($state['imap_total'] ?? 0);
            if ($indexed > 0 || self::hasFolderData($folderPath)) {
                $total = $indexed;
            } elseif ($total <= 0) {
                $total = $indexed;
            }

            if ($total === 0) {
                return mail_filter_removed_messages($folderPath, [
                    'messages' => [],
                    'total' => 0,
                    'page' => 1,
                    'per_page' => $perPage,
                    'total_pages' => 0,
                    'from_cache' => true,
                ]);
            }

            $totalPages = (int) max(1, (int) ceil($total / $perPage));
            $page = max(1, min($page, $totalPages));
            $offset = ($page - 1) * $perPage;
        }

        $rows = Database::query(
            'SELECT i.imap_uid, i.from_addr, i.subject, i.msg_date, i.seen, i.flagged, i.has_attachment, i.size,
                    COALESCE(NULLIF(i.to_addrs, \'\'), b.to_addrs) AS to_addrs,
                    COALESCE(NULLIF(i.cc_addrs, \'\'), b.cc_addrs) AS cc_addrs,
                    COALESCE(NULLIF(i.message_id, \'\'), b.message_id) AS message_id,
                    i.in_reply_to, i.references_ids
             FROM mail_index i
             LEFT JOIN mail_bodies b
                ON b.folder_path = i.folder_path AND b.imap_uid = i.imap_uid
             WHERE i.folder_path = ?
             ORDER BY i.msg_date DESC, i.imap_uid DESC
             LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
            [$folderPath]
        )->fetchAll();

        if ($rows === [] && $offset > 0) {
            return null;
        }

        // Plain per-folder model: `seen` comes straight from mail_index.seen
        // (each row's own IMAP \Seen); no per-user read map.
        $messages = [];
        foreach ($rows as $row) {
            $messages[] = self::indexRowToMessage($row, $folderPath);
        }

        return mail_filter_removed_messages($folderPath, [
            'messages' => $messages,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
            'from_cache' => true,
        ]);
    }

    /**
     * Conversation-aware cache read for FOLDER VIEWS. When the folder groups by
     * thread (Gmail-style), SQL LIMIT/OFFSET pagination is wrong (a page of 25
     * messages is not 25 conversations) — so fetch the FULL index window
     * (≤ mail_cache_header_limit rows, one cheap query), tag it `window_full`,
     * and let the list pipeline group first and slice to the requested page
     * after. Non-grouping folders defer to plain listFromCache unchanged.
     *
     * @return array{messages: list<array<string, mixed>>, total: int, page: int, per_page: int, total_pages: int, from_cache: bool, window_full?: bool}|null
     */
    public static function listConversationWindow(string $folderPath, int $page, int $perPage): ?array
    {
        if (!mail_should_group_list_by_thread($folderPath)) {
            return self::listFromCache($folderPath, $page, $perPage);
        }

        $windowSize = max((int) (config('app')['mail_cache_header_limit'] ?? 200), $perPage);
        $list = self::listFromCache($folderPath, 1, $windowSize);
        if ($list === null) {
            return null;
        }

        $list['page'] = max(1, $page);
        $list['per_page'] = max(1, $perPage);
        $list['window_full'] = true;

        return $list;
    }

    /**
     * Bulk variant of hasFolderData(): ONE query for the whole registry.
     * With a huge imported registry (~1000 folders) calling hasFolderData()
     * per folder per badge poll meant thousands of queries per request and
     * pegged the server CPU. Returns upper-cased folder paths that have sync
     * data, memoized per request.
     *
     * @return array<string, true>
     */
    public static function syncedFolderPathSet(): array
    {
        if (self::$syncedPathSet !== null) {
            return self::$syncedPathSet;
        }

        $set = [];
        try {
            foreach (Database::query(
                'SELECT folder_path, headers_cached, imap_total, last_sync_at FROM mail_sync_state'
            )->fetchAll() as $row) {
                if (empty($row['last_sync_at'])) {
                    continue;
                }
                if ((int) ($row['headers_cached'] ?? 0) > 0 || (int) ($row['imap_total'] ?? 0) === 0) {
                    $set[strtoupper((string) $row['folder_path'])] = true;
                }
            }
        } catch (\Throwable) {
            // DB unavailable — treat as no synced folders.
        }

        return self::$syncedPathSet = $set;
    }

    /**
     * True when an ACTIVE employee-type registry row exists for this exact
     * path (case-insensitive). Batched registry map — no SQL per call.
     */
    public static function isEmployeeRegistryPath(string $path): bool
    {
        if ($path === '') {
            return false;
        }
        $maps = self::registryMaps();
        $key = strtolower($path);

        return isset($maps['link'][$key]) || isset($maps['shared'][$key]);
    }

    /**
     * Memoized implementation behind folder_badge_uses_index_truth() (helpers).
     * Semantics identical to the original helper; memo cleared by both
     * resetRuntimeMaps() (registry mutations) and resetSyncStateMaps() (a
     * folder synced mid-request flips the hasFolderData clause).
     */
    public static function badgeUsesIndexTruth(string $folderPath): bool
    {
        $folderPath = FolderCache::resolvePath($folderPath);
        if ($folderPath === '') {
            return false;
        }

        $key = strtolower($folderPath);
        if (isset(self::$badgeTruthMemo[$key])) {
            return self::$badgeTruthMemo[$key];
        }

        $result = false;
        if (employee_correspondent_privacy_emails($folderPath) !== null) {
            $result = true;
        } elseif (self::isSharedEmployeeMailbox($folderPath)) {
            $result = true;
        } elseif (self::usesPerUserRead($folderPath)) {
            $result = true;
        } elseif (
            // Plain per-folder model: any badge-showing folder that is already
            // indexed uses its mail_index count (seen=0) as the badge truth —
            // before a folder is indexed we fall back to the IMAP/session count.
            folder_shows_unread_badge($folderPath)
            && self::hasFolderData(self::indexFolderPath($folderPath))
        ) {
            $result = true;
        }

        return self::$badgeTruthMemo[$key] = $result;
    }

    /**
     * hasFolderData() against a prefetched syncedFolderPathSet() — no SQL.
     */
    public static function hasFolderDataInSet(string $folderPath, array $set): bool
    {
        $indexPath = self::indexFolderPath(FolderCache::resolvePath($folderPath));

        return isset($set[strtoupper($indexPath)]);
    }

    public static function hasFolderData(string $folderPath): bool
    {
        // Sync state is stored under the resolved index path (e.g. the shared
        // "INBOX.support" node keeps its data under "INBOX.support.Inbox"), so
        // resolve here too — otherwise callers passing the raw node path get a
        // false "cold" result and the UI shows a needless "Loading…" spinner.
        // Reads the batched syncedFolderPathSet (one query per request) — this
        // is called per folder on every sidebar/badge pass, and a per-call SQL
        // lookup at ~1000 registered folders was seconds of DB churn per page.
        $folderPath = self::indexFolderPath(FolderCache::resolvePath($folderPath));

        return isset(self::syncedFolderPathSet()[strtoupper($folderPath)]);
    }

    /**
     * Fully indexed folders use mail_index for unread badges — not stale IMAP/session.
     */
    public static function indexUnreadBadgeIsAuthoritative(string $folderPath): bool
    {
        if (!self::hasFolderData($folderPath) || folder_uses_draft_badge($folderPath)) {
            return false;
        }

        $folderPath = self::indexFolderPath($folderPath);
        if (FolderCache::isPendingBadgePath($folderPath)) {
            return false;
        }
        if (mail_get_post_send_preview($folderPath) !== null) {
            return false;
        }

        if (folder_badge_uses_index_truth($folderPath)) {
            return self::countMessagesInIndex($folderPath) > 0;
        }

        $state = self::getSyncState($folderPath);
        $imapTotal = (int) ($state['imap_total'] ?? 0);
        if ($imapTotal <= 0) {
            $session = FolderCache::sessionUnreadCountRaw($folderPath);
            if ($session > 0 || self::badgeAheadOfIndex($folderPath)) {
                return false;
            }

            return true;
        }

        return self::countMessagesInIndex($folderPath) >= $imapTotal;
    }

    public static function mergeBadgeWithSession(string $folderPath, int $indexUnread, int $sessionCount): int
    {
        if (self::indexUnreadBadgeIsAuthoritative($folderPath)) {
            return max(0, $indexUnread);
        }

        return max($indexUnread, $sessionCount);
    }

    /**
     * Shared employee mailbox (e.g. Support) — not linked to a single user's inbox.
     */
    public static function isSharedEmployeeMailbox(string $folderPath): bool
    {
        $folderPath = self::indexFolderPath($folderPath);
        if ($folderPath === '') {
            return false;
        }

        $candidates = [$folderPath];
        $root = employee_mailbox_root_prefix($folderPath);
        if ($root !== '' && strcasecmp($root, $folderPath) !== 0) {
            $candidates[] = $root;
        }
        $messagesPath = employee_messages_imap_path($folderPath);
        if ($messagesPath !== '' && strcasecmp($messagesPath, $folderPath) !== 0) {
            $candidates[] = $messagesPath;
        }

        $maps = self::registryMaps();
        foreach (array_values(array_unique($candidates)) as $candidate) {
            if (isset($maps['shared'][strtolower($candidate)])) {
                return true;
            }
        }

        return false;
    }


    /** Drop all cached mail for a folder (after admin deletes the mailbox). */
    public static function purgeFolder(string $folderPath): void
    {
        if ($folderPath === '') {
            return;
        }

        Database::query('DELETE FROM mail_index WHERE folder_path = ?', [$folderPath]);
        Database::query('DELETE FROM mail_bodies WHERE folder_path = ?', [$folderPath]);
        Database::query('DELETE FROM mail_sync_state WHERE folder_path = ?', [$folderPath]);
        Database::query('DELETE FROM mail_user_read WHERE folder_path = ?', [$folderPath]);
        self::resetSyncStateMaps();
    }

    /**
     * Remove every indexed/IMAP copy of mail tied to a user outside their own mailbox tree.
     *
     * @param list<string> $emails lowercase addresses (aliases + primary)
     * @param list<string> $mailboxRoots INBOX.Name roots left to purgeUserMailboxTree
     */
    public static function purgeMessagesForUser(int $userId, array $emails, array $mailboxRoots = []): void
    {
        $emails = array_values(array_unique(array_filter(array_map(
            static fn (string $email): string => strtolower(trim($email)),
            $emails
        ), static fn (string $email): bool => $email !== '')));

        if ($userId > 0) {
            Database::query('DELETE FROM mail_user_read WHERE user_id = ?', [$userId]);
        }

        if ($emails === []) {
            return;
        }

        $matchClauses = [];
        $params = [];
        foreach ($emails as $email) {
            $like = '%' . $email . '%';
            $matchClauses[] = '(LOWER(from_addr) LIKE ? OR LOWER(COALESCE(to_addrs, \'\')) LIKE ? OR LOWER(COALESCE(cc_addrs, \'\')) LIKE ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $excludeClauses = [];
        $excludeParams = [];
        foreach ($mailboxRoots as $root) {
            $root = FolderCache::resolvePath(rtrim($root, '.'));
            if ($root === '') {
                continue;
            }
            $excludeClauses[] = '(LOWER(folder_path) <> LOWER(?) AND LOWER(folder_path) NOT LIKE LOWER(?))';
            $excludeParams[] = $root;
            $excludeParams[] = $root . '.%';
        }

        $where = '(' . implode(' OR ', $matchClauses) . ')';
        if ($excludeClauses !== []) {
            $where .= ' AND ' . implode(' AND ', $excludeClauses);
        }

        try {
            $rows = Database::query(
                "SELECT folder_path, imap_uid FROM mail_index WHERE {$where}",
                array_merge($params, $excludeParams)
            )->fetchAll();
        } catch (\Throwable) {
            return;
        }

        /** @var array<string, list<int>> $byFolder */
        $byFolder = [];
        foreach ($rows as $row) {
            $folderPath = trim((string) ($row['folder_path'] ?? ''));
            $uid = (int) ($row['imap_uid'] ?? 0);
            if ($folderPath === '' || $uid <= 0) {
                continue;
            }
            $byFolder[$folderPath][] = $uid;
        }

        if ($byFolder === []) {
            return;
        }

        $imap = new ImapService();
        $connected = $imap->connect();

        foreach ($byFolder as $folderPath => $uids) {
            $uids = array_values(array_unique(array_filter(
                array_map('intval', $uids),
                static fn (int $uid): bool => $uid > 0
            )));
            if ($uids === []) {
                continue;
            }

            $resolved = FolderCache::resolvePath($folderPath);
            if ($connected) {
                $imap->deleteMessages($resolved, $uids);
            }
            self::removeMessages($folderPath, $uids);
        }
    }

    /** Move cached mail rows when an IMAP folder is renamed. */
    public static function renameFolderPath(string $oldPath, string $newPath): void
    {
        if ($oldPath === '' || $newPath === '' || $oldPath === $newPath) {
            return;
        }

        Database::query('UPDATE mail_index SET folder_path = ? WHERE folder_path = ?', [$newPath, $oldPath]);
        Database::query('UPDATE mail_bodies SET folder_path = ? WHERE folder_path = ?', [$newPath, $oldPath]);
        Database::query('UPDATE mail_sync_state SET folder_path = ? WHERE folder_path = ?', [$newPath, $oldPath]);
        self::resetSyncStateMaps();
    }

    public static function invalidateFolder(string $folderPath): void
    {
        $folderPath = self::indexFolderPath($folderPath);
        if ($folderPath === '') {
            return;
        }

        Database::query(
            'UPDATE mail_sync_state SET last_sync_at = NULL WHERE folder_path = ?',
            [$folderPath]
        );
        self::resetSyncStateMaps();
    }

    public static function isStale(string $folderPath): bool
    {
        $state = self::getSyncState($folderPath);
        if ($state === null || empty($state['last_sync_at'])) {
            return true;
        }

        $ttl = (int) (config('app')['mail_cache_ttl'] ?? 120);

        return strtotime((string) $state['last_sync_at']) + $ttl < time();
    }

    /**
     * True when the server has a different message count than our last index sync
     * (e.g. after filter moved mail into this folder).
     */
    public static function imapTotalDrifted(string $folderPath, int $imapTotal): bool
    {
        $state = self::getSyncState($folderPath);
        if ($state === null) {
            return $imapTotal > 0;
        }

        return (int) ($state['imap_total'] ?? 0) !== $imapTotal;
    }

    public static function countMessagesInIndex(string $folderPath): int
    {
        $row = Database::fetchOne(
            'SELECT COUNT(*) AS c FROM mail_index WHERE folder_path = ?',
            [$folderPath]
        );

        return (int) ($row['c'] ?? 0);
    }

    /** Message count in the index excluding session tombstones (pending deletes). */
    public static function countListableMessagesInIndex(string $folderPath): int
    {
        $folderPath = self::indexFolderPath($folderPath);
        if ($folderPath === '') {
            return 0;
        }

        $removed = mail_removed_uids_for_folder($folderPath);
        if ($removed === []) {
            return self::countMessagesInIndex($folderPath);
        }

        // Count only the (small, session-scoped) tombstone uids that actually
        // exist in the index instead of materializing every row — with the
        // full-history backfill a folder can hold tens of thousands of rows.
        $tombstoneUids = array_values(array_filter(array_map('intval', array_keys($removed))));
        $present = 0;
        if ($tombstoneUids !== []) {
            $placeholders = implode(',', array_fill(0, count($tombstoneUids), '?'));
            $row = Database::fetchOne(
                "SELECT COUNT(*) AS c FROM mail_index WHERE folder_path = ? AND imap_uid IN ({$placeholders})",
                array_merge([$folderPath], $tombstoneUids)
            );
            $present = (int) ($row['c'] ?? 0);
        }

        return max(0, self::countMessagesInIndex($folderPath) - $present);
    }

    /**
     * Authoritative sidebar badge for a folder (privacy-aware, index + IMAP).
     */
    public static function sidebarBadgeCount(string $folderPath, ?int $sessionCount = null): int
    {
        $folderPath = FolderCache::resolvePath($folderPath);
        if ($folderPath === '' || !folder_shows_unread_badge($folderPath)) {
            return 0;
        }

        if ($sessionCount === null) {
            $sessionCount = FolderCache::sessionUnreadCountRaw($folderPath);
        }

        // Plain per-folder model: unread badge = the folder's own unseen count
        // (Drafts show total drafts). When the folder is not indexed yet, trust the
        // session value (populated from the IMAP STATUS UNSEEN refresh). Check the
        // index path, not the raw sidebar node: a shared/linked ROOT (INBOX.Jean,
        // INBOX.support) has no sync_state of its own — its mail lives in the .Inbox
        // subfolder — so checking the parent would fall back to a stale session value.
        $indexPath = self::indexFolderPath($folderPath);
        if (folder_uses_draft_badge($folderPath) && self::hasFolderData($indexPath)) {
            return self::countBadgeFromIndex($folderPath);
        }

        if (self::hasFolderData($indexPath)) {
            return self::countUnseenInIndex($folderPath);
        }

        return $sessionCount;
    }

    /** Sidebar badge value from mail_index (unread or draft total). */
    public static function countBadgeFromIndex(string $folderPath): int
    {
        if (folder_uses_draft_badge($folderPath)) {
            $indexed = self::countMessagesInIndex($folderPath);
            if (self::hasFolderData($folderPath) || $indexed > 0) {
                return $indexed;
            }

            $state = self::getSyncState($folderPath);

            return (int) ($state['imap_total'] ?? 0);
        }

        return self::countUnseenInIndex($folderPath);
    }

    /**
     * Sidebar badge shows unread on IMAP but the cached list has not caught up yet.
     */
    public static function badgeAheadOfIndex(string $folderPath): bool
    {
        $folderPath = FolderCache::resolvePath($folderPath);
        if ($folderPath === '') {
            return false;
        }

        $badge = FolderCache::sessionUnreadCountRaw($folderPath);
        if ($badge <= 0) {
            return false;
        }

        if (!self::hasFolderData($folderPath)) {
            return true;
        }

        return self::countBadgeFromIndex($folderPath) < $badge;
    }

    /**
     * Refresh the MySQL header index when stale, counts drift, or badges are ahead of the list.
     */
    public static function refreshHeadersIfNeeded(ImapService $imap, string $folderPath): void
    {
        $imapTotal = $imap->getMessageCount($folderPath);
        if ($imapTotal > 0) {
            self::recordServerTotal($folderPath, $imapTotal);
        }
        if (
            self::isStale($folderPath)
            || self::imapTotalDrifted($folderPath, $imapTotal)
            || self::badgeAheadOfIndex($folderPath)
        ) {
            perf_mark('header_sync_start:' . $folderPath);
            self::syncFolderHeaders($imap, $folderPath);
            perf_mark('header_sync_done:' . $folderPath);
        }
    }

    /**
     * @return array{folder_path: string, imap_total: int, headers_cached: int, last_sync_at: string|null, server_total: int|null, oldest_uid: int|null, backfill_done: int}|null
     */
    public static function getSyncState(string $folderPath): ?array
    {
        $folderPath = self::indexFolderPath($folderPath);

        return Database::fetchOne(
            'SELECT folder_path, imap_total, headers_cached, last_sync_at, server_total, oldest_uid, backfill_done
             FROM mail_sync_state WHERE folder_path = ?',
            [$folderPath]
        );
    }

    /**
     * Persist the TRUE server message count (imap_num_msg). Deliberately does
     * NOT touch imap_total (badge code depends on its local-row-count
     * semantics) or last_sync_at (would suppress TTL refreshes). Folders whose
     * whole history fits the index self-mark backfill_done — so all small
     * (e.g. Hostinger) folders never trigger deep-fetch machinery.
     */
    public static function recordServerTotal(string $folderPath, int $serverTotal): void
    {
        $folderPath = self::indexFolderPath($folderPath);
        if ($folderPath === '' || $serverTotal < 0) {
            return;
        }

        $current = self::getSyncState($folderPath);
        $indexed = self::countMessagesInIndex($folderPath);
        $done = $serverTotal <= $indexed ? 1 : null;
        if (
            $current !== null
            && (int) ($current['server_total'] ?? -1) === $serverTotal
            && ($done === null || (int) ($current['backfill_done'] ?? 0) === $done)
        ) {
            return; // unchanged — skip the write (and the map resets)
        }

        Database::query(
            'INSERT INTO mail_sync_state (folder_path, server_total, backfill_done)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE
                server_total = VALUES(server_total),
                backfill_done = GREATEST(backfill_done, VALUES(backfill_done))',
            [$folderPath, $serverTotal, $done ?? 0]
        );
        self::resetSyncStateMaps();
    }

    /**
     * Persist the backfill cursor (lowest indexed UID) and optionally mark the
     * folder's history fully indexed.
     */
    public static function setBackfillWatermark(string $folderPath, int $oldestUid, ?bool $done = null): void
    {
        $folderPath = self::indexFolderPath($folderPath);
        if ($folderPath === '' || $oldestUid <= 0) {
            return;
        }

        Database::query(
            'INSERT INTO mail_sync_state (folder_path, oldest_uid, backfill_done)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE
                oldest_uid = LEAST(COALESCE(oldest_uid, VALUES(oldest_uid)), VALUES(oldest_uid)),
                backfill_done = GREATEST(backfill_done, VALUES(backfill_done))',
            [$folderPath, $oldestUid, $done ? 1 : 0]
        );
        self::resetSyncStateMaps();
    }

    /**
     * Pull recent headers from IMAP into mail_index.
     */
    public static function syncFolderHeaders(ImapService $imap, string $folderPath, ?int $limit = null): int
    {
        $folderPath = self::indexFolderPath(FolderCache::resolvePath($folderPath));
        if ($folderPath === '') {
            return 0;
        }

        $limit = $limit ?? (int) (config('app')['mail_cache_header_limit'] ?? 200);
        $list = $imap->listMessages($folderPath, 1, $limit);
        if (!empty($list['failed'])) {
            // The IMAP call broke (flaky host) — do NOT record a "successful"
            // zero-message sync, or the folder renders empty for the cache TTL.
            return 0;
        }
        $imapTotal = (int) ($list['total'] ?? 0);
        $messages = $list['messages'];

        self::upsertIndexMessages($folderPath, $messages, $imapTotal);
        if ($imapTotal >= 0) {
            // True server count for the "N of M synced" display + deep paging.
            self::recordServerTotal($folderPath, $imapTotal);
        }

        $serverUids = [];
        foreach ($messages as $msg) {
            $uid = (int) ($msg['uid'] ?? 0);
            if ($uid > 0) {
                $serverUids[] = $uid;
            }
        }

        // A message still present on the server must not stay optimistically
        // hidden by a stale removed-tombstone; clearing it here keeps the list ==
        // index == badge (the tombstone survives only for genuinely-gone UIDs,
        // which pruneIndexUidsNotIn then drops from the index).
        if ($serverUids !== []) {
            mail_clear_removed_uids($folderPath, $serverUids);
        }

        if ($imapTotal > 0 && $imapTotal <= $limit && $serverUids !== []) {
            self::pruneIndexUidsNotIn($folderPath, $serverUids);
        }

        mail_reconcile_correspondent_badges_for_linked_inbox($folderPath);

        return count($messages);
    }

    /**
     * Rebuild destination cache after an IMAP move. Clears stale UID tombstones
     * (IMAP reuses UIDs) and drops optimistically relocated rows whose UIDs no
     * longer exist in the target mailbox.
     *
     * @param list<int> $staleUids Source-folder UIDs that may have been relocated optimistically
     */
    public static function resyncFolderAfterMove(
        ImapService $imap,
        string $folderPath,
        array $staleUids = [],
        ?int $limit = null,
    ): int {
        $folderPath = self::indexFolderPath(FolderCache::resolvePath($folderPath));
        if ($folderPath === '') {
            return 0;
        }

        $limit = $limit ?? (int) (config('app')['mail_cache_header_limit'] ?? 200);
        $list = $imap->listMessages($folderPath, 1, $limit);
        if (!empty($list['failed'])) {
            // Broken IMAP call — don't rebuild the cache from a false empty.
            return 0;
        }
        $serverUids = [];

        foreach ($list['messages'] as $msg) {
            $uid = (int) ($msg['uid'] ?? 0);
            if ($uid <= 0) {
                continue;
            }
            $serverUids[] = $uid;
            mail_clear_removed_uids($folderPath, [$uid]);
            self::upsertIndexRow($folderPath, $msg);
        }

        $drop = [];
        foreach ($staleUids as $uid) {
            $uid = (int) $uid;
            if ($uid > 0 && !in_array($uid, $serverUids, true)) {
                $drop[] = $uid;
            }
        }
        if ($drop !== []) {
            self::removeMessages($folderPath, $drop);
        }

        $indexed = self::countListableMessagesInIndex($folderPath);
        self::touchSyncState($folderPath, max((int) ($list['total'] ?? 0), $indexed), $indexed);
        self::recordServerTotal($folderPath, (int) ($list['total'] ?? 0));

        return count($list['messages']);
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    public static function upsertIndexMessages(string $folderPath, array $messages, int $imapTotal): void
    {
        $folderPath = self::indexFolderPath(FolderCache::resolvePath($folderPath));
        if ($folderPath === '') {
            return;
        }

        if ($messages === []) {
            if ($imapTotal <= 0) {
                Database::query('DELETE FROM mail_index WHERE folder_path = ?', [$folderPath]);
                Database::query('DELETE FROM mail_bodies WHERE folder_path = ?', [$folderPath]);
            }
            self::touchSyncState($folderPath, max(0, $imapTotal), 0);

            return;
        }

        foreach ($messages as $msg) {
            self::upsertIndexRow($folderPath, $msg);
        }

        $indexed = self::countListableMessagesInIndex($folderPath);
        self::touchSyncState($folderPath, $indexed, $indexed);
    }

    /**
     * @param array<string, mixed> $msg
     */
    public static function upsertIndexRow(string $folderPath, array $msg): void
    {
        $uid = (int) ($msg['uid'] ?? 0);
        if ($uid <= 0) {
            return;
        }

        if (mail_is_uid_removed($folderPath, $uid)) {
            return;
        }

        // Keep any recipients we already have when an overview row omits them, so
        // correspondent-folder privacy matching doesn't regress between syncs.
        $to = (string) ($msg['to'] ?? '');
        $cc = (string) ($msg['cc'] ?? '');

        // seen uses GREATEST(seen, VALUES(seen)) below so a background re-sync never
        // downgrades a read message: a read here marks IMAP \Seen with a DEFERRED
        // push, and a sync that runs before that push lands would otherwise flip
        // seen 1->0 and the unread badge would reappear. A deliberate mark-unread
        // sets the shared index seen=0 directly, so read reliability wins here.

        // Reply-chain headers for Gmail-style threading. Stored (when present) so
        // mail_build_correspondent_conversation_thread can group by the actual
        // reply relationship instead of by subject. COALESCE-keep on re-sync so an
        // overview that omits them doesn't wipe values we already have.
        $messageId = mail_normalize_thread_id((string) ($msg['message_id'] ?? ''));
        $inReplyTo = mail_normalize_thread_id((string) ($msg['in_reply_to'] ?? ''));
        $references = trim((string) ($msg['references'] ?? ''));

        Database::query(
            'INSERT INTO mail_index
                (folder_path, imap_uid, from_addr, to_addrs, cc_addrs, subject, msg_date, seen, backfilled, flagged, has_attachment, size, message_id, in_reply_to, references_ids, synced_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                from_addr = VALUES(from_addr),
                to_addrs = COALESCE(NULLIF(VALUES(to_addrs), \'\'), to_addrs),
                cc_addrs = COALESCE(NULLIF(VALUES(cc_addrs), \'\'), cc_addrs),
                subject = VALUES(subject),
                msg_date = VALUES(msg_date),
                seen = GREATEST(seen, VALUES(seen)),
                backfilled = LEAST(backfilled, VALUES(backfilled)),
                flagged = VALUES(flagged),
                has_attachment = VALUES(has_attachment),
                size = VALUES(size),
                message_id = COALESCE(NULLIF(VALUES(message_id), \'\'), message_id),
                in_reply_to = COALESCE(NULLIF(VALUES(in_reply_to), \'\'), in_reply_to),
                references_ids = COALESCE(NULLIF(VALUES(references_ids), \'\'), references_ids),
                synced_at = NOW()',
            [
                $folderPath,
                $uid,
                (string) ($msg['from'] ?? ''),
                $to !== '' ? $to : null,
                $cc !== '' ? $cc : null,
                (string) ($msg['subject'] ?? '(no subject)'),
                self::parseMsgDate($msg['date'] ?? null),
                !empty($msg['seen']) ? 1 : 0,
                !empty($msg['flagged']) ? 1 : 0,
                !empty($msg['has_attachment']) ? 1 : 0,
                (int) ($msg['size'] ?? 0),
                $messageId !== '' ? $messageId : null,
                $inReplyTo !== '' ? $inReplyTo : null,
                $references !== '' ? $references : null,
            ]
        );
        self::resetUnseenCountMap();
    }

    /**
     * Multi-row variant of upsertIndexRow for the history backfill — identical
     * merge semantics plus the `backfilled` marker (LEAST-merged so a row ever
     * touched by the normal recent-window sync stays 0 / badge-countable).
     * Calls resetUnseenCountMap ONCE at the end instead of per row.
     *
     * @param list<array<string, mixed>> $messages
     * @return int rows written
     */
    public static function upsertIndexRowsBulk(string $folderPath, array $messages, bool $backfilled): int
    {
        $folderPath = self::indexFolderPath(FolderCache::resolvePath($folderPath));
        if ($folderPath === '' || $messages === []) {
            return 0;
        }

        $flag = $backfilled ? 1 : 0;
        $written = 0;
        foreach (array_chunk($messages, 100) as $chunk) {
            $groups = [];
            $params = [];
            foreach ($chunk as $msg) {
                $uid = (int) ($msg['uid'] ?? 0);
                if ($uid <= 0 || mail_is_uid_removed($folderPath, $uid)) {
                    continue;
                }
                $to = (string) ($msg['to'] ?? '');
                $cc = (string) ($msg['cc'] ?? '');
                $messageId = mail_normalize_thread_id((string) ($msg['message_id'] ?? ''));
                $inReplyTo = mail_normalize_thread_id((string) ($msg['in_reply_to'] ?? ''));
                $references = trim((string) ($msg['references'] ?? ''));

                $groups[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())';
                array_push(
                    $params,
                    $folderPath,
                    $uid,
                    (string) ($msg['from'] ?? ''),
                    $to !== '' ? $to : null,
                    $cc !== '' ? $cc : null,
                    (string) ($msg['subject'] ?? '(no subject)'),
                    self::parseMsgDate($msg['date'] ?? null),
                    !empty($msg['seen']) ? 1 : 0,
                    $flag,
                    !empty($msg['flagged']) ? 1 : 0,
                    !empty($msg['has_attachment']) ? 1 : 0,
                    (int) ($msg['size'] ?? 0),
                    $messageId !== '' ? $messageId : null,
                    $inReplyTo !== '' ? $inReplyTo : null,
                    $references !== '' ? $references : null,
                );
            }
            if ($groups === []) {
                continue;
            }

            Database::query(
                'INSERT INTO mail_index
                    (folder_path, imap_uid, from_addr, to_addrs, cc_addrs, subject, msg_date, seen, backfilled, flagged, has_attachment, size, message_id, in_reply_to, references_ids, synced_at)
                 VALUES ' . implode(', ', $groups) . '
                 ON DUPLICATE KEY UPDATE
                    from_addr = VALUES(from_addr),
                    to_addrs = COALESCE(NULLIF(VALUES(to_addrs), \'\'), to_addrs),
                    cc_addrs = COALESCE(NULLIF(VALUES(cc_addrs), \'\'), cc_addrs),
                    subject = VALUES(subject),
                    msg_date = VALUES(msg_date),
                    seen = GREATEST(seen, VALUES(seen)),
                    backfilled = LEAST(backfilled, VALUES(backfilled)),
                    flagged = VALUES(flagged),
                    has_attachment = VALUES(has_attachment),
                    size = VALUES(size),
                    message_id = COALESCE(NULLIF(VALUES(message_id), \'\'), message_id),
                    in_reply_to = COALESCE(NULLIF(VALUES(in_reply_to), \'\'), in_reply_to),
                    references_ids = COALESCE(NULLIF(VALUES(references_ids), \'\'), references_ids),
                    synced_at = NOW()',
                $params
            );
            $written += count($groups);
        }

        if ($written > 0) {
            self::resetUnseenCountMap();
        }

        return $written;
    }

    /**
     * One time-budgeted batch of the full-history import. Walks every IMAP
     * folder; for each not-yet-complete folder pulls older-message chunks via
     * the UID watermark cursor until the budget is spent. Fully resumable —
     * all progress persists in mail_sync_state. Used by the background worker
     * chain and (optionally) the CLI.
     *
     * @return array{complete: bool, folders_done: int, folders_total: int, rows_added: int, current_folder: string}
     */
    public static function historyImportBatch(ImapService $imap, float $budgetSeconds = 20.0): array
    {
        $paths = array_column($imap->listFolders(true), 'path');
        $result = [
            'complete' => false,
            'folders_done' => 0,
            'folders_total' => count($paths),
            'rows_added' => 0,
            'current_folder' => '',
        ];
        if ($paths === []) {
            return $result;
        }

        // One query for every folder's backfill state.
        $stateByPath = [];
        foreach (Database::query(
            'SELECT folder_path, oldest_uid, backfill_done, last_sync_at FROM mail_sync_state'
        )->fetchAll() as $row) {
            $stateByPath[strtolower((string) $row['folder_path'])] = $row;
        }

        $chunk = max(25, (int) (config('app')['deep_page_chunk'] ?? 200));
        $deadline = microtime(true) + max(2.0, $budgetSeconds);

        foreach ($paths as $path) {
            $indexPath = self::indexFolderPath($path);
            $state = $stateByPath[strtolower($indexPath)] ?? null;
            if ($state !== null && !empty($state['backfill_done'])) {
                $result['folders_done']++;
                continue;
            }
            if (microtime(true) >= $deadline) {
                return $result;
            }

            $result['current_folder'] = $path;

            // Never-synced folder: pull the recent window first (normal
            // badge-correct sync), then continue below.
            if ($state === null || empty($state['last_sync_at'])) {
                self::syncFolderHeaders($imap, $path);
            }

            $serverTotal = $imap->getMessageCount($path);
            self::recordServerTotal($path, max(0, $serverTotal));
            $fresh = self::getSyncState($path);
            if (!empty($fresh['backfill_done'])) {
                $result['folders_done']++;
                continue;
            }

            $oldest = (int) ($fresh['oldest_uid'] ?? 0);
            if ($oldest <= 0) {
                $row = Database::fetchOne(
                    'SELECT MIN(imap_uid) AS u FROM mail_index WHERE folder_path = ?',
                    [$indexPath]
                );
                $oldest = (int) ($row['u'] ?? 0);
            }
            if ($oldest <= 0) {
                continue; // empty/unindexable — skip this pass
            }

            while (microtime(true) < $deadline) {
                $res = $imap->listMessagesBeforeUid($path, $oldest, $chunk);
                if (!empty($res['failed'])) {
                    break; // flaky chunk — the next batch retries this folder
                }
                if (!empty($res['exhausted']) || $res['messages'] === []) {
                    self::setBackfillWatermark($path, $oldest, done: true);
                    $result['folders_done']++;
                    break;
                }

                $result['rows_added'] += self::upsertIndexRowsBulk($path, $res['messages'], backfilled: true);

                $chunkMin = $oldest;
                foreach ($res['messages'] as $msg) {
                    $uid = (int) ($msg['uid'] ?? 0);
                    if ($uid > 0 && $uid < $chunkMin) {
                        $chunkMin = $uid;
                    }
                }
                if ($chunkMin >= $oldest) {
                    break; // no progress — avoid a spin
                }
                $oldest = $chunkMin;
                self::setBackfillWatermark($path, $oldest);
            }
        }

        $result['complete'] = $result['folders_done'] >= $result['folders_total'];

        return $result;
    }

    /**
     * Extend the index older-ward until the requested page has rows to show
     * (or the per-request chunk budget is spent). The loop condition is on ROW
     * COUNT, not date, so requested-offset coverage is guaranteed even when
     * msg_date order diverges from UID order (imported/moved mail).
     *
     * @return bool true when any rows were added
     */
    public static function extendIndexForDeepPage(ImapService $imap, string $folderPath, int $page, int $perPage): bool
    {
        $folderPath = self::indexFolderPath(FolderCache::resolvePath($folderPath));
        if ($folderPath === '' || $page < 1) {
            return false;
        }

        $state = self::getSyncState($folderPath);
        if (!empty($state['backfill_done'])) {
            return false;
        }

        $chunk = max(25, (int) (config('app')['deep_page_chunk'] ?? 200));
        $maxChunks = max(1, (int) (config('app')['deep_page_max_chunks'] ?? 3));
        $needed = $page * max(1, $perPage);

        $oldest = (int) ($state['oldest_uid'] ?? 0);
        if ($oldest <= 0) {
            $row = Database::fetchOne(
                'SELECT MIN(imap_uid) AS u FROM mail_index WHERE folder_path = ?',
                [$folderPath]
            );
            $oldest = (int) ($row['u'] ?? 0);
        }
        if ($oldest <= 0) {
            // Folder never synced at all — the normal recent-window sync must
            // run first (deep paging past an empty index is meaningless).
            return false;
        }

        $added = false;
        for ($i = 0; $i < $maxChunks; $i++) {
            if (self::countMessagesInIndex($folderPath) >= $needed) {
                break;
            }

            perf_mark('deep_backfill_chunk_start:' . $folderPath);
            $res = $imap->listMessagesBeforeUid($folderPath, $oldest, $chunk);
            perf_mark('deep_backfill_chunk_done:' . $folderPath);

            if (!empty($res['failed'])) {
                break; // serve what we have; a later visit retries
            }
            if (!empty($res['exhausted']) || $res['messages'] === []) {
                self::setBackfillWatermark($folderPath, $oldest, done: true);
                break;
            }

            self::upsertIndexRowsBulk($folderPath, $res['messages'], backfilled: true);
            $added = true;

            $chunkMin = $oldest;
            foreach ($res['messages'] as $msg) {
                $uid = (int) ($msg['uid'] ?? 0);
                if ($uid > 0 && $uid < $chunkMin) {
                    $chunkMin = $uid;
                }
            }
            if ($chunkMin >= $oldest) {
                break; // no progress — avoid a spin
            }
            $oldest = $chunkMin;
            self::setBackfillWatermark($folderPath, $oldest);
        }

        return $added;
    }

    /**
     * @return array<string, mixed>|null Message shape for loadMessageContext / pane.
     */
    public static function getBody(string $folderPath, int $uid): ?array
    {
        $folderPath = self::indexFolderPath(FolderCache::resolvePath($folderPath));
        if ($folderPath === '' || $uid <= 0) {
            return null;
        }

        $row = Database::fetchOne(
            'SELECT * FROM mail_bodies WHERE folder_path = ? AND imap_uid = ?',
            [$folderPath, $uid]
        );

        if ($row === null) {
            return null;
        }

        $attachments = json_decode((string) ($row['attachments_json'] ?? '[]'), true);

        $index = Database::fetchOne(
            'SELECT seen, flagged FROM mail_index WHERE folder_path = ? AND imap_uid = ?',
            [$folderPath, $uid]
        );

        return [
            'uid' => $uid,
            'from' => (string) ($row['from_addr'] ?? ''),
            'to' => (string) ($row['to_addrs'] ?? ''),
            'cc' => (string) ($row['cc_addrs'] ?? ''),
            'subject' => (string) ($row['subject'] ?? ''),
            'date' => $row['msg_date'] ?? '',
            'delivered_to' => $row['delivered_to'] ?? null,
            'message_id' => $row['message_id'] ?? null,
            'html' => $row['html_body'],
            'plain' => $row['plain_body'],
            'attachments' => is_array($attachments) ? $attachments : [],
            // Same truth effectiveSeen() reads — from the index row already
            // fetched above, saving a third query per call (getBody runs in
            // loops inside the thread builder).
            'seen' => $index !== null && (bool) $index['seen'],
            'flagged' => $index !== null ? (bool) $index['flagged'] : false,
        ];
    }

    /**
     * @param array<string, mixed> $message
     */
    public static function saveBody(string $folderPath, array $message): void
    {
        $uid = (int) ($message['uid'] ?? 0);
        if ($uid <= 0) {
            return;
        }

        $folderPath = self::indexFolderPath(FolderCache::resolvePath($folderPath));
        if ($folderPath === '') {
            return;
        }

        $attachmentsJson = json_encode($message['attachments'] ?? [], JSON_UNESCAPED_UNICODE) ?: '[]';

        Database::query(
            'INSERT INTO mail_bodies
                (folder_path, imap_uid, from_addr, to_addrs, cc_addrs, subject, msg_date,
                 delivered_to, message_id, html_body, plain_body, attachments_json, cached_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                from_addr = VALUES(from_addr),
                to_addrs = VALUES(to_addrs),
                cc_addrs = VALUES(cc_addrs),
                subject = VALUES(subject),
                msg_date = VALUES(msg_date),
                delivered_to = VALUES(delivered_to),
                message_id = VALUES(message_id),
                html_body = VALUES(html_body),
                plain_body = VALUES(plain_body),
                attachments_json = VALUES(attachments_json),
                cached_at = NOW()',
            [
                $folderPath,
                $uid,
                (string) ($message['from'] ?? ''),
                (string) ($message['to'] ?? ''),
                (string) ($message['cc'] ?? ''),
                (string) ($message['subject'] ?? ''),
                self::parseMsgDate($message['date'] ?? null),
                $message['delivered_to'] ?? null,
                $message['message_id'] ?? null,
                $message['html'] ?? null,
                $message['plain'] ?? null,
                $attachmentsJson,
            ]
        );

        self::upsertIndexRow($folderPath, $message);
    }

    /**
     * List preview snippets for visible rows — cache first, then light IMAP fetch.
     *
     * @param list<int> $uids
     * @return array<int, string>
     */
    public static function resolveSnippetsForUids(ImapService $imap, string $folderPath, array $uids, int $limit = 20): array
    {
        $uids = array_values(array_unique(array_filter(array_map('intval', $uids), static fn (int $u): bool => $u > 0)));
        $uids = array_slice($uids, 0, $limit);
        if ($uids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($uids), '?'));
        $params = array_merge([$folderPath], $uids);
        $rows = Database::query(
            "SELECT imap_uid, plain_body, html_body FROM mail_bodies WHERE folder_path = ? AND imap_uid IN ({$placeholders})",
            $params
        )->fetchAll();

        $byUid = [];
        foreach ($rows as $row) {
            $byUid[(int) $row['imap_uid']] = $row;
        }

        $result = [];
        $missing = [];

        foreach ($uids as $uid) {
            $body = $byUid[$uid] ?? null;
            if ($body !== null) {
                $snippet = mail_list_snippet($body['plain_body'] ?? null, $body['html_body'] ?? null);
                if ($snippet !== '') {
                    $result[$uid] = $snippet;
                    continue;
                }
            }
            $missing[] = $uid;
        }

        if ($missing === [] || !$imap->openFolder($folderPath)) {
            return $result;
        }

        // Cap the per-request IMAP fetches: each uncached body is a full fetch
        // (~0.5s against a remote host), and 20 of them serially blocked this
        // endpoint for 10s+. Rows left without a snippet stay empty in the DOM,
        // so the next enrichment pass requests exactly the still-missing ones.
        foreach (array_slice($missing, 0, 6) as $uid) {
            $message = $imap->getMessageByUid($folderPath, $uid);
            if ($message === null) {
                continue;
            }

            self::saveBody($folderPath, $message);
            $snippet = mail_list_snippet($message['plain'] ?? null, $message['html'] ?? null);
            if ($snippet !== '') {
                $result[$uid] = $snippet;
            }
        }

        return $result;
    }

    /**
     * Add list snippets / draft recipients from cached bodies when missing.
     *
     * @param list<array<string, mixed>> $messages
     * @return list<array<string, mixed>>
     */
    public static function enrichListMessages(string $folderPath, array $messages, bool $light = false): array
    {
        if ($messages === []) {
            return $messages;
        }

        $uids = array_values(array_unique(array_filter(array_map(
            static fn (array $m): int => (int) ($m['uid'] ?? 0),
            $messages
        ), static fn (int $u): bool => $u > 0)));
        if ($uids === []) {
            return $messages;
        }

        $placeholders = implode(',', array_fill(0, count($uids), '?'));
        $params = array_merge([$folderPath], $uids);
        $rows = Database::query(
            "SELECT imap_uid, plain_body, html_body, to_addrs
             FROM mail_bodies
             WHERE folder_path = ? AND imap_uid IN ({$placeholders})",
            $params
        )->fetchAll();

        $bodies = [];
        foreach ($rows as $row) {
            $bodies[(int) $row['imap_uid']] = $row;
        }

        $isDraft = is_draft_folder($folderPath);
        foreach ($messages as &$msg) {
            $uid = (int) ($msg['uid'] ?? 0);
            if ($uid <= 0) {
                continue;
            }

            // Plain per-folder model: only fill display snippet (and draft "to").
            // NO correspondent/thread-preview enrichment — that overrode a row's
            // `seen` with per-viewer correspondent read-state, making a viewer's
            // own-alias message look read while the index says unseen.
            if (empty($msg['snippet'])) {
                $body = $bodies[$uid] ?? null;
                if ($body !== null) {
                    $msg['snippet'] = mail_list_snippet($body['plain_body'] ?? null, $body['html_body'] ?? null);
                    if ($isDraft && !empty($body['to_addrs'])) {
                        $msg['to'] = (string) $body['to_addrs'];
                        $msg['list_from'] = format_mail_from((string) $body['to_addrs']);
                    }
                }
            }
        }
        unset($msg);

        return $messages;
    }

    /**
     * $clearBackfilled: pass true ONLY from an explicit user mark-unread action
     * — a human-touched old message should count in sidebar badges again. The
     * badge-reconcile phantom-sync path must leave `backfilled` untouched, or
     * every backfilled old unread would leak into the badges it was floored
     * out of.
     */
    public static function updateIndexSeen(string $folderPath, int $uid, bool $seen, bool $clearBackfilled = false): void
    {
        $backfilledSql = $clearBackfilled ? ', backfilled = 0' : '';
        Database::query(
            "UPDATE mail_index SET seen = ?, synced_at = NOW(){$backfilledSql} WHERE folder_path = ? AND imap_uid = ?",
            [$seen ? 1 : 0, $folderPath, $uid]
        );
        self::resetUnseenCountMap();
    }

    public static function indexSeenState(string $folderPath, int $uid): ?bool
    {
        $row = Database::fetchOne(
            'SELECT seen FROM mail_index WHERE folder_path = ? AND imap_uid = ?',
            [$folderPath, $uid]
        );

        return $row !== null ? (bool) $row['seen'] : null;
    }

    public static function messageInIndex(string $folderPath, int $uid): bool
    {
        if ($uid <= 0) {
            return false;
        }

        $folderPath = self::indexFolderPath($folderPath);
        if ($folderPath === '') {
            return false;
        }

        $row = Database::fetchOne(
            'SELECT 1 FROM mail_index WHERE folder_path = ? AND imap_uid = ? LIMIT 1',
            [$folderPath, $uid]
        );

        return $row !== null;
    }

    public static function updateIndexFlagged(string $folderPath, int $uid, bool $flagged): void
    {
        Database::query(
            'UPDATE mail_index SET flagged = ?, synced_at = NOW() WHERE folder_path = ? AND imap_uid = ?',
            [$flagged ? 1 : 0, $folderPath, $uid]
        );
    }

    /**
     * @param list<int> $uids
     */
    public static function updateIndexSeenBulk(string $folderPath, array $uids, bool $seen, bool $clearBackfilled = false): void
    {
        if ($uids === []) {
            return;
        }

        $backfilledSql = $clearBackfilled ? ', backfilled = 0' : '';
        $placeholders = implode(',', array_fill(0, count($uids), '?'));
        $params = array_merge([$seen ? 1 : 0, $folderPath], $uids);
        Database::query(
            "UPDATE mail_index SET seen = ?, synced_at = NOW(){$backfilledSql} WHERE folder_path = ? AND imap_uid IN ({$placeholders})",
            $params
        );
        self::resetUnseenCountMap();
    }

    /**
     * @param list<int> $uids
     */
    public static function updateIndexFlaggedBulk(string $folderPath, array $uids, bool $flagged): void
    {
        if ($uids === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($uids), '?'));
        $params = array_merge([$flagged ? 1 : 0, $folderPath], $uids);
        Database::query(
            "UPDATE mail_index SET flagged = ?, synced_at = NOW() WHERE folder_path = ? AND imap_uid IN ({$placeholders})",
            $params
        );
    }

    /**
     * @param list<int> $uids
     */
    public static function countUnreadAmongUids(string $folderPath, array $uids): int
    {
        if ($uids === []) {
            return 0;
        }

        if (self::usesPerUserRead($folderPath)) {
            $count = 0;
            foreach ($uids as $uid) {
                if (!self::effectiveSeen($folderPath, (int) $uid)) {
                    $count++;
                }
            }

            return $count;
        }

        $placeholders = implode(',', array_fill(0, count($uids), '?'));
        $params = array_merge([$folderPath], $uids);
        $row = Database::fetchOne(
            "SELECT COUNT(*) AS c FROM mail_index WHERE folder_path = ? AND seen = 0 AND imap_uid IN ({$placeholders})",
            $params
        );

        return (int) ($row['c'] ?? 0);
    }

    /**
     * @param list<int> $uids
     */
    public static function countSeenAmongUids(string $folderPath, array $uids): int
    {
        if ($uids === []) {
            return 0;
        }

        if (self::usesPerUserRead($folderPath)) {
            $count = 0;
            foreach ($uids as $uid) {
                if (self::effectiveSeen($folderPath, (int) $uid)) {
                    $count++;
                }
            }

            return $count;
        }

        $placeholders = implode(',', array_fill(0, count($uids), '?'));
        $params = array_merge([$folderPath], $uids);
        $row = Database::fetchOne(
            "SELECT COUNT(*) AS c FROM mail_index WHERE folder_path = ? AND seen = 1 AND imap_uid IN ({$placeholders})",
            $params
        );

        return (int) ($row['c'] ?? 0);
    }

    /**
     * Unread messages in the local index for a folder.
     */
    public static function countUnseenInIndex(string $folderPath): int
    {
        // Plain per-folder model: a folder's unread badge is simply the number of
        // its own unseen messages (IMAP \Seen mirrored in mail_index.seen). No
        // cross-folder merge, no per-user read, no thread-based seen — so the
        // badge always equals the unread blue bars in the folder's list.
        // Reads the batched unseenCountMap (one GROUP BY query per request)
        // instead of a COUNT(*) per call — this runs per folder in the badge loops.
        $folderPath = self::indexFolderPath($folderPath);

        return self::unseenCountMap()[strtolower($folderPath)] ?? 0;
    }

    /**
     * Unread in a correspondent folder: visible rows plus inbound replies in the
     * viewer's own mailbox that belong to the same conversation thread.
     *
     * @param list<string> $emails lowercase participant addresses
     */
    public static function countCorrespondentUnseenWithReplies(string $folderPath, array $emails): int
    {
        if ($emails === []) {
            return 0;
        }

        $folderPath = mail_correspondent_messages_folder_path($folderPath);
        $folderPath = self::indexFolderPath($folderPath);
        $viewerId = (int) (Auth::user()['id'] ?? 0);
        if ($viewerId <= 0) {
            return 0;
        }

        [$privacyClauses, $privacyParams] = correspondent_folder_badge_sql_clauses($folderPath, $emails);
        if ($privacyClauses === []) {
            return 0;
        }

        $params = array_merge([$folderPath], $privacyParams);

        try {
            $rows = Database::query(
                'SELECT i.imap_uid, i.from_addr, i.subject, i.msg_date, i.seen, i.flagged, i.has_attachment, i.size,
                        COALESCE(i.to_addrs, \'\') AS to_addrs,
                        COALESCE(i.cc_addrs, \'\') AS cc_addrs
                 FROM mail_index i
                 WHERE i.folder_path = ? AND (' . implode(' OR ', $privacyClauses) . ')
                 ORDER BY i.msg_date DESC',
                $params
            )->fetchAll();
        } catch (\Throwable) {
            return 0;
        }

        $groups = [];
        foreach ($rows as $row) {
            $msg = self::messageFromIndexRow($row, $folderPath);
            $uid = (int) ($msg['uid'] ?? 0);
            if ($uid <= 0) {
                continue;
            }

            $key = mail_normalize_thread_subject((string) ($msg['subject'] ?? ''));
            if ($key === '') {
                $key = 'uid:' . $uid;
            }

            $msgTs = strtotime((string) ($msg['date'] ?? '')) ?: 0;
            $existingTs = isset($groups[$key])
                ? (strtotime((string) ($groups[$key]['date'] ?? '')) ?: 0)
                : -1;
            if (
                !isset($groups[$key])
                || $msgTs > $existingTs
                || ($msgTs === $existingTs && $uid > (int) ($groups[$key]['uid'] ?? 0))
            ) {
                $groups[$key] = $msg;
            }
        }

        $unreadThreads = [];
        $countedInboxKeys = [];

        foreach ($groups as $threadKey => $msg) {
            $uid = (int) ($msg['uid'] ?? 0);
            if ($uid <= 0) {
                continue;
            }

            if (mail_is_sent_by_user((string) ($msg['from'] ?? ''), $viewerId)) {
                $inbound = mail_find_correspondent_inbound_replies($folderPath, $uid, $msg);
                if ($inbound === []) {
                    continue;
                }
                $latest = $inbound[count($inbound) - 1];
                $replyFolder = (string) ($latest['folder_path'] ?? '');
                $replyUid = (int) ($latest['imap_uid'] ?? 0);
                if ($replyFolder !== '' && $replyUid > 0 && !self::effectiveSeen($replyFolder, $replyUid, $viewerId)) {
                    $unreadThreads[$threadKey] = true;
                    $countedInboxKeys[strtolower($replyFolder) . '|' . $replyUid] = true;
                }
                continue;
            }

            if (mail_is_shared_mailbox_alias_sent_echo($folderPath, $msg)) {
                if (self::viewerIsAdmin()) {
                    continue;
                }
                if (!self::effectiveSeen($folderPath, $uid, $viewerId)) {
                    $unreadThreads[$threadKey] = true;
                }
                continue;
            }

            if (mail_is_employee_outbound_echo($folderPath, $uid, (string) ($msg['from'] ?? ''))) {
                continue;
            }

            if (!self::effectiveSeen($folderPath, $uid, $viewerId)) {
                $unreadThreads[$threadKey] = true;
            }
        }

        // Employee correspondent folders: align badge with list rows — unread when the
        // latest inbound Support reply in this folder is unread (not only inbox copies).
        if (!self::viewerIsAdmin() && employee_is_correspondent_folder($folderPath)) {
            foreach (array_keys($groups) as $threadKey) {
                if (mail_correspondent_support_thread_read_for_employee($folderPath, (string) $threadKey)) {
                    unset($unreadThreads[$threadKey]);
                } else {
                    $unreadThreads[$threadKey] = true;
                }
            }
        }

        $ownInbox = employee_linked_inbox_path();
        if ($ownInbox === null || $ownInbox === '') {
            return count($unreadThreads);
        }

        $corrFolder = FolderCache::resolvePath($folderPath);
        $aliasEmail = alias_email_for_folder($corrFolder);
        if ($aliasEmail === null || trim($aliasEmail) === '') {
            $root = employee_mailbox_root_prefix($corrFolder);
            if ($root !== '') {
                $aliasEmail = alias_email_for_folder($root);
            }
        }
        if ($aliasEmail === null || trim($aliasEmail) === '') {
            return count($unreadThreads);
        }

        $like = '%' . strtolower(trim($aliasEmail)) . '%';
        $inboxPaths = employee_inbox_index_paths();
        if ($inboxPaths === []) {
            $inboxPaths = [FolderCache::resolvePath(employee_messages_imap_path($ownInbox))];
        }

        $inboxByThread = [];
        foreach ($inboxPaths as $ownInboxPath) {
            try {
                $inboxRows = Database::query(
                    'SELECT i.imap_uid, i.from_addr, i.subject, i.msg_date,
                            COALESCE(i.to_addrs, \'\') AS to_addrs,
                            COALESCE(i.cc_addrs, \'\') AS cc_addrs
                     FROM mail_index i
                     WHERE i.folder_path = ? AND LOWER(i.from_addr) LIKE ?
                     ORDER BY i.msg_date DESC',
                    [$ownInboxPath, $like]
                )->fetchAll();
            } catch (\Throwable) {
                continue;
            }

            foreach ($inboxRows as $row) {
                $uid = (int) ($row['imap_uid'] ?? 0);
                if ($uid <= 0) {
                    continue;
                }

                $threadKey = mail_normalize_thread_subject((string) ($row['subject'] ?? ''));
                if ($threadKey === '') {
                    $threadKey = 'uid:' . $uid;
                }

                if (isset($unreadThreads[$threadKey])) {
                    continue;
                }

                $inboxKey = strtolower($ownInboxPath) . '|' . $uid;
                if (isset($countedInboxKeys[$inboxKey])) {
                    continue;
                }

                $msg = [
                    'from' => (string) ($row['from_addr'] ?? ''),
                    'to' => (string) ($row['to_addrs'] ?? ''),
                    'cc' => (string) ($row['cc_addrs'] ?? ''),
                ];
                if (!mail_counts_as_correspondent_inbox_inbound($msg, $emails)) {
                    continue;
                }
                if (mail_is_sent_by_user((string) ($msg['from'] ?? ''), $viewerId)) {
                    continue;
                }
                if (!employee_should_hide_inbox_correspondent_message($msg)) {
                    continue;
                }

                if (mail_support_folder_has_thread_key($folderPath, (string) $threadKey)) {
                    continue;
                }

                $msgTs = strtotime((string) ($row['msg_date'] ?? '')) ?: 0;
                $existingTs = isset($inboxByThread[$threadKey])
                    ? (strtotime((string) ($inboxByThread[$threadKey]['msg_date'] ?? '')) ?: 0)
                    : -1;
                if (
                    !isset($inboxByThread[$threadKey])
                    || $msgTs > $existingTs
                    || ($msgTs === $existingTs && $uid > (int) ($inboxByThread[$threadKey]['imap_uid'] ?? 0))
                ) {
                    $inboxByThread[$threadKey] = $row + ['folder_path' => $ownInboxPath];
                }
            }
        }

        foreach ($inboxByThread as $threadKey => $row) {
            if (isset($unreadThreads[$threadKey])) {
                continue;
            }

            if (mail_correspondent_support_thread_read_for_employee($folderPath, (string) $threadKey)) {
                continue;
            }

            $uid = (int) ($row['imap_uid'] ?? 0);
            $inboxPath = (string) ($row['folder_path'] ?? $ownInbox);
            if ($uid > 0 && !self::effectiveSeen($inboxPath, $uid, $viewerId)) {
                $unreadThreads[$threadKey] = true;
            }
        }

        return count($unreadThreads);
    }

    /**
     * Unread count restricted to messages the current employee is a party to.
     * Used for correspondent-folder badges so they never show counts for mail
     * the viewer cannot see.
     *
     * @param list<string> $emails lowercase participant addresses
     */
    public static function countVisibleUnseenInIndex(string $folderPath, array $emails): int
    {
        if ($emails === []) {
            return 0;
        }

        $folderPath = self::indexFolderPath($folderPath);
        $clauses = [];
        $params = [$folderPath];
        foreach ($emails as $email) {
            $like = '%' . strtolower($email) . '%';
            $clauses[] = '(LOWER(from_addr) LIKE ? OR LOWER(COALESCE(to_addrs, \'\')) LIKE ? OR LOWER(COALESCE(cc_addrs, \'\')) LIKE ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $row = Database::fetchOne(
            'SELECT COUNT(*) AS c FROM mail_index
             WHERE folder_path = ? AND seen = 0 AND (' . implode(' OR ', $clauses) . ')',
            $params
        );

        return (int) ($row['c'] ?? 0);
    }

    /**
     * Align session badge with mail_index (and optional visible page), without IMAP.
     * Fixes phantom badges (badge but no unread rows) and missing badges.
     *
     * @param list<array<string, mixed>>|null $pageMessages
     */
    public static function reconcileBadgeFromIndex(string $folderPath, ?array $pageMessages = null): int
    {
        $folderPath = FolderCache::resolvePath($folderPath);

        if (!folder_shows_unread_badge($folderPath)) {
            FolderCache::setUnreadCount($folderPath, 0);

            return 0;
        }

        // Plain per-folder model: the badge is the folder's own unseen count
        // (Drafts: total drafts). Once the folder is indexed that is the exact
        // truth; before it is indexed, fall back to the current page's unseen rows
        // or the session value (from the IMAP STATUS refresh). Check the index path,
        // not the raw sidebar node: a shared/linked ROOT (INBOX.Jean, INBOX.support)
        // has no sync_state of its own, so checking the parent would wrongly fall to
        // the max(session,page) branch and keep a stale value.
        if (self::hasFolderData(self::indexFolderPath($folderPath))) {
            if (folder_uses_draft_badge($folderPath)) {
                self::reconcileSyncStateFromIndex($folderPath);
            }
            $truth = self::countBadgeFromIndex($folderPath);
            FolderCache::setUnreadCount($folderPath, $truth);

            return $truth;
        }

        $session = FolderCache::sessionUnreadCountRaw($folderPath);
        if ($pageMessages !== null && !folder_uses_draft_badge($folderPath)) {
            $pageUnread = 0;
            foreach ($pageMessages as $msg) {
                if (is_array($msg) && empty($msg['seen'])) {
                    $pageUnread++;
                }
            }
            $truth = max($session, $pageUnread);
            if ($truth !== $session) {
                FolderCache::setUnreadCount($folderPath, $truth);
            }

            return $truth;
        }

        return $session;
    }

    /**
     * When the folder is fully indexed, align the sidebar badge with the list
     * (avoids stale IMAP counts when cache and server flags disagree).
     */
    public static function syncBadgeFromIndex(string $folderPath): bool
    {
        if (!self::hasFolderData($folderPath)) {
            return false;
        }

        $state = self::getSyncState($folderPath);
        $imapTotal = (int) ($state['imap_total'] ?? 0);
        $row = Database::fetchOne(
            'SELECT COUNT(*) AS c FROM mail_index WHERE folder_path = ?',
            [$folderPath]
        );
        $indexed = (int) ($row['c'] ?? 0);

        if ($imapTotal > 0 && $indexed < $imapTotal) {
            if (folder_uses_draft_badge($folderPath)) {
                self::reconcileSyncStateFromIndex($folderPath);
                $imapTotal = $indexed;
            } else {
                return false;
            }
        }

        $session = FolderCache::sessionUnreadCountRaw($folderPath);
        $indexUnread = self::countBadgeFromIndex($folderPath);
        $truth = folder_uses_draft_badge($folderPath)
            ? $indexUnread
            : self::mergeBadgeWithSession($folderPath, $indexUnread, $session);
        FolderCache::setUnreadCount($folderPath, $truth);

        return true;
    }

    /**
     * Align mail_index \\Seen flags with IMAP, clear phantom UNSEEN entries on
     * hosts where STATUS/SEARCH disagree with per-message flags, and set the
     * sidebar badge from the index when the folder is fully cached.
     */
    /** Bound for the per-uid reconcile loop below — one explicit refresh on a
     *  folder with thousands of server-side unread must not do thousands of
     *  IMAP round-trips. Newest-first slice; older unread reconcile lazily. */
    private const MAX_BADGE_RECONCILE_UIDS = 300;

    public static function reconcileFolderBadge(ImapService $imap, string $folderPath): int
    {
        self::refreshHeadersIfNeeded($imap, $folderPath);

        $unseenUids = $imap->getUnseenUids($folderPath);
        if (count($unseenUids) > self::MAX_BADGE_RECONCILE_UIDS) {
            $unseenUids = array_slice($unseenUids, 0, self::MAX_BADGE_RECONCILE_UIDS);
        }

        foreach ($unseenUids as $uid) {
            $uid = (int) $uid;
            if ($imap->isSeen($folderPath, $uid)) {
                // Phantom UNSEEN — overview says read; clear stale server flag.
                $imap->markSeen($folderPath, $uid);
                self::updateIndexSeen($folderPath, $uid, true);
            } elseif (self::effectiveSeen($folderPath, $uid)) {
                // Our shared index already has it read (e.g. a read whose deferred
                // IMAP \Seen push hasn't landed yet). Push the read to IMAP instead
                // of reverting the index — otherwise the unread badge reappears.
                $imap->markSeen($folderPath, $uid);
            } else {
                self::updateIndexSeen($folderPath, $uid, false);
            }
        }

        if (self::syncBadgeFromIndex($folderPath)) {
            return self::countBadgeFromIndex($folderPath);
        }

        FolderCache::refreshPaths([$folderPath]);
        $counts = FolderCache::load(skipUnreadRefresh: true)['unread_counts'] ?? [];

        return (int) ($counts[$folderPath] ?? 0);
    }

    public static function removeMessage(string $folderPath, int $uid): void
    {
        $folderPath = self::indexFolderPath($folderPath);
        Database::query('DELETE FROM mail_index WHERE folder_path = ? AND imap_uid = ?', [$folderPath, $uid]);
        Database::query('DELETE FROM mail_bodies WHERE folder_path = ? AND imap_uid = ?', [$folderPath, $uid]);
        self::resetUnseenCountMap();
        self::reconcileSyncStateFromIndex($folderPath);
    }

    /**
     * Drop indexed rows whose UIDs are no longer on the server (after a full header sync).
     *
     * @param list<int> $serverUids
     */
    public static function pruneIndexUidsNotIn(string $folderPath, array $serverUids): void
    {
        $folderPath = self::indexFolderPath($folderPath);
        if ($folderPath === '' || $serverUids === []) {
            return;
        }

        $rows = Database::query(
            'SELECT imap_uid FROM mail_index WHERE folder_path = ?',
            [$folderPath]
        )->fetchAll();

        $keep = array_fill_keys($serverUids, true);
        $drop = [];
        foreach ($rows as $row) {
            $uid = (int) ($row['imap_uid'] ?? 0);
            if ($uid > 0 && !isset($keep[$uid])) {
                $drop[] = $uid;
            }
        }

        if ($drop !== []) {
            self::removeMessages($folderPath, $drop);
        }
    }

    /**
     * @param list<int> $uids
     */
    public static function removeMessages(string $folderPath, array $uids): void
    {
        $resolved = FolderCache::resolvePath($folderPath);
        $indexPath = self::indexFolderPath($resolved);
        $uids = array_values(array_unique(array_filter(array_map('intval', $uids), static fn (int $u): bool => $u > 0)));
        if ($uids === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($uids), '?'));
        $paths = array_values(array_unique(array_filter([$indexPath, $resolved, $folderPath])));

        foreach ($paths as $path) {
            $params = array_merge([$path], $uids);
            Database::query(
                "DELETE FROM mail_index WHERE folder_path = ? AND imap_uid IN ({$placeholders})",
                $params
            );
            Database::query(
                "DELETE FROM mail_bodies WHERE folder_path = ? AND imap_uid IN ({$placeholders})",
                $params
            );
            Database::query(
                "DELETE FROM mail_user_read WHERE folder_path = ? AND imap_uid IN ({$placeholders})",
                $params
            );
        }

        $lookup = strtolower($indexPath !== '' ? $indexPath : $resolved);
        if ($lookup !== '') {
            $params = array_merge([$lookup], $uids);
            Database::query(
                "DELETE FROM mail_index WHERE LOWER(folder_path) = ? AND imap_uid IN ({$placeholders})",
                $params
            );
            Database::query(
                "DELETE FROM mail_bodies WHERE LOWER(folder_path) = ? AND imap_uid IN ({$placeholders})",
                $params
            );
            Database::query(
                "DELETE FROM mail_user_read WHERE LOWER(folder_path) = ? AND imap_uid IN ({$placeholders})",
                $params
            );
        }

        self::resetUnseenCountMap();
        self::reconcileSyncStateFromIndex($indexPath !== '' ? $indexPath : $resolved);
    }

    /**
     * Optimistically move cached headers/bodies to the destination folder.
     *
     * @param list<int> $uids
     */
    public static function relocateCachedMessages(string $fromPath, string $toPath, array $uids): int
    {
        $fromPath = self::indexFolderPath(FolderCache::resolvePath($fromPath));
        $toPath = self::indexFolderPath(FolderCache::resolvePath($toPath));
        $uids = array_values(array_unique(array_filter(array_map('intval', $uids), static fn (int $u): bool => $u > 0)));
        if ($fromPath === '' || $toPath === '' || $uids === [] || strcasecmp($fromPath, $toPath) === 0) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($uids), '?'));
        $params = array_merge([$fromPath], $uids);

        // Drop the messages from the SOURCE folder's cache. We deliberately do NOT
        // relocate the rows into the target keeping the source UID: an IMAP move
        // assigns a brand-new UID in the destination, so the source UID is wrong
        // there — and reusing it violates the unique (folder_path, imap_uid) key
        // whenever that UID already exists in the target (extremely common, since
        // IMAP UIDs start at 1 per folder). That collision previously threw and
        // aborted the whole move. The background resyncFolderAfterMove() repopulates
        // the target with the real server UIDs, so no optimistic insert is needed.
        $indexRemoved = Database::query(
            "DELETE FROM mail_index WHERE folder_path = ? AND imap_uid IN ({$placeholders})",
            $params
        )->rowCount();

        Database::query(
            "DELETE FROM mail_bodies WHERE folder_path = ? AND imap_uid IN ({$placeholders})",
            $params
        );

        if ($indexRemoved > 0) {
            self::resetUnseenCountMap();
            self::reconcileSyncStateFromIndex($fromPath);
        }

        return $indexRemoved;
    }

    /** Keep mail_sync_state totals aligned after local index removals. */
    public static function reconcileSyncStateFromIndex(string $folderPath): void
    {
        $state = self::getSyncState($folderPath);
        if ($state === null) {
            return;
        }

        $listable = self::countListableMessagesInIndex($folderPath);
        Database::query(
            'UPDATE mail_sync_state
             SET imap_total = ?, headers_cached = LEAST(COALESCE(headers_cached, 0), ?)
             WHERE folder_path = ?',
            [$listable, $listable, $folderPath]
        );
        self::resetSyncStateMaps();
    }

    public static function messageIdForUid(string $folderPath, int $uid): ?string
    {
        if ($folderPath === '' || $uid <= 0) {
            return null;
        }

        try {
            $row = Database::query(
                'SELECT message_id FROM mail_bodies WHERE folder_path = ? AND imap_uid = ? LIMIT 1',
                [$folderPath, $uid]
            )->fetch();
        } catch (\Throwable) {
            return null;
        }

        $id = trim((string) ($row['message_id'] ?? ''));

        return $id !== '' ? $id : null;
    }

    /**
     * @return list<array{folder_path: string, imap_uid: int}>
     */
    public static function copiesByMessageId(string $messageId): array
    {
        $messageId = trim($messageId);
        if ($messageId === '') {
            return [];
        }

        try {
            $rows = Database::query(
                'SELECT folder_path, imap_uid FROM mail_bodies WHERE message_id = ?',
                [$messageId]
            )->fetchAll();
        } catch (\Throwable) {
            return [];
        }

        $copies = [];
        foreach ($rows as $row) {
            $path = (string) ($row['folder_path'] ?? '');
            $uid = (int) ($row['imap_uid'] ?? 0);
            if ($path !== '' && $uid > 0) {
                $copies[] = ['folder_path' => $path, 'imap_uid' => $uid];
            }
        }

        return $copies;
    }

    /**
     * Search messages across all accessible folders (local cache).
     *
     * @return array{messages: list<array<string, mixed>>, total: int, page: int, per_page: int, total_pages: int, from_cache: bool}
     */
    public static function searchAllMessages(string $query, int $page, int $perPage): array
    {
        $query = trim($query);
        $empty = [
            'messages' => [],
            'total' => 0,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => 0,
            'from_cache' => true,
        ];

        if ($query === '') {
            return $empty;
        }

        $folderData = FolderCache::load(skipUnreadRefresh: true);
        $accessiblePaths = [];
        foreach ($folderData['folders'] as $folder) {
            $path = (string) ($folder['path'] ?? '');
            if ($path !== '' && FolderCache::canAccess($path)) {
                $accessiblePaths[] = self::indexFolderPath($path);
            }
        }

        if ($accessiblePaths === []) {
            return $empty;
        }

        $like = '%' . $query . '%';
        $placeholders = implode(',', array_fill(0, count($accessiblePaths), '?'));
        $params = array_merge($accessiblePaths, [$like, $like, $like, $like, $like]);

        $countRow = Database::fetchOne(
            "SELECT COUNT(*) AS c
             FROM mail_index i
             LEFT JOIN mail_bodies b
                ON b.folder_path = i.folder_path AND b.imap_uid = i.imap_uid
             WHERE i.folder_path IN ({$placeholders})
               AND (
                    i.subject LIKE ?
                    OR i.from_addr LIKE ?
                    OR i.to_addrs LIKE ?
                    OR i.cc_addrs LIKE ?
                    OR b.plain_body LIKE ?
               )",
            $params
        );
        $total = (int) ($countRow['c'] ?? 0);

        if ($total === 0) {
            return $empty;
        }

        $totalPages = (int) max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $totalPages));
        $offset = ($page - 1) * $perPage;

        $rows = Database::query(
            "SELECT i.folder_path, i.imap_uid, i.from_addr, i.subject, i.msg_date, i.seen, i.flagged,
                    i.has_attachment, i.size,
                    COALESCE(NULLIF(i.to_addrs, ''), b.to_addrs) AS to_addrs,
                    COALESCE(NULLIF(i.cc_addrs, ''), b.cc_addrs) AS cc_addrs,
                    b.plain_body, b.html_body
             FROM mail_index i
             LEFT JOIN mail_bodies b
                ON b.folder_path = i.folder_path AND b.imap_uid = i.imap_uid
             WHERE i.folder_path IN ({$placeholders})
               AND (
                    i.subject LIKE ?
                    OR i.from_addr LIKE ?
                    OR i.to_addrs LIKE ?
                    OR i.cc_addrs LIKE ?
                    OR b.plain_body LIKE ?
               )
             ORDER BY i.msg_date DESC, i.imap_uid DESC
             LIMIT " . (int) $perPage . ' OFFSET ' . (int) $offset,
            $params
        )->fetchAll();

        $messages = [];
        foreach ($rows as $row) {
            $folderPath = (string) ($row['folder_path'] ?? '');
            if ($folderPath === '' || mail_is_uid_removed($folderPath, (int) ($row['imap_uid'] ?? 0))) {
                continue;
            }

            $msg = self::indexRowToMessage($row, $folderPath);
            $msg['_folder_path'] = $folderPath;
            // Raw body text for search-context snippets (avoids a per-row re-query).
            $msg['_plain'] = (string) ($row['plain_body'] ?? '');

            $filtered = employee_filter_correspondent_list($folderPath, [
                'messages' => [$msg],
                'total' => 1,
                'page' => 1,
                'per_page' => 1,
                'total_pages' => 1,
            ]);
            $filtered = employee_filter_own_inbox_list($folderPath, $filtered);

            if (($filtered['messages'][0] ?? null) === null) {
                continue;
            }

            $messages[] = $filtered['messages'][0];
        }

        return [
            'messages' => $messages,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
            'from_cache' => true,
        ];
    }

    /**
     * UIDs from the local header cache (fast path for bulk select-all).
     *
     * @return list<int>
     */
    public static function folderMessageUids(string $folderPath, string $searchQuery = ''): array
    {
        $folderPath = self::indexFolderPath($folderPath);
        if ($folderPath === '') {
            return [];
        }

        $query = trim($searchQuery);
        if ($query !== '') {
            $like = '%' . $query . '%';
            $rows = Database::query(
                'SELECT imap_uid FROM mail_index
                 WHERE folder_path = ?
                   AND (subject LIKE ? OR from_addr LIKE ?)
                 ORDER BY msg_date DESC',
                [$folderPath, $like, $like]
            )->fetchAll();
        } else {
            $rows = Database::query(
                'SELECT imap_uid FROM mail_index WHERE folder_path = ? ORDER BY msg_date DESC',
                [$folderPath]
            )->fetchAll();
        }

        if ($rows === []) {
            return [];
        }

        return array_map(static fn (array $row): int => (int) $row['imap_uid'], $rows);
    }

    /**
     * Warm common folders after login (no cron).
     *
     * @param list<string> $folderPaths
     * @return array<string, int> folder => headers synced
     */
    public static function bootstrapSync(ImapService $imap, array $folderPaths): array
    {
        $limit = (int) (config('app')['mail_cache_bootstrap_limit'] ?? 150);
        $result = [];

        foreach ($folderPaths as $path) {
            if ($path === '' || !FolderCache::canAccess($path)) {
                continue;
            }
            if (!self::isStale($path) && self::hasFolderData($path)) {
                $result[$path] = (int) (self::getSyncState($path)['headers_cached'] ?? 0);
                continue;
            }
            try {
                $result[$path] = self::syncFolderHeaders($imap, $path, $limit);
            } catch (\Throwable $e) {
                app_log('Mail cache bootstrap failed for ' . $path . ': ' . $e->getMessage());
                $result[$path] = 0;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function messageFromIndexRow(array $row, string $folderPath): array
    {
        return self::indexRowToMessage($row, $folderPath);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function indexRowToMessage(array $row, string $folderPath, ?array $readMap = null, ?int $viewerId = null): array
    {
        $from = (string) ($row['from_addr'] ?? '');
        $to = (string) ($row['to_addrs'] ?? '');
        $snippet = mail_list_snippet($row['plain_body'] ?? null, $row['html_body'] ?? null);
        $isDraft = is_draft_folder($folderPath);
        $listFrom = ($isDraft && $to !== '') ? format_mail_from($to) : format_mail_from($from);
        $uid = (int) ($row['imap_uid'] ?? 0);

        // Plain per-folder model: read state is the folder's own IMAP \Seen,
        // mirrored in mail_index.seen. No per-user / shared per-viewer override.
        $seen = (bool) ($row['seen'] ?? false);

        return [
            'uid' => $uid,
            'from' => $from,
            'to' => $to,
            'cc' => (string) ($row['cc_addrs'] ?? ''),
            'list_from' => $listFrom,
            'snippet' => $snippet,
            'subject' => (string) ($row['subject'] ?? '(no subject)'),
            'date' => $row['msg_date'] ?? '',
            'sort_date' => $row['msg_date'] ?? '',
            'seen' => $seen,
            'flagged' => (bool) ($row['flagged'] ?? false),
            'has_attachment' => (bool) ($row['has_attachment'] ?? false),
            'size' => (int) ($row['size'] ?? 0),
            'message_id' => (string) ($row['message_id'] ?? ''),
            // Reference headers feed the conversation grouper (thread chains).
            'in_reply_to' => (string) ($row['in_reply_to'] ?? ''),
            'references_ids' => (string) ($row['references_ids'] ?? ''),
        ];
    }

    private static function touchSyncState(string $folderPath, int $imapTotal, int $headersCached): void
    {
        Database::query(
            'INSERT INTO mail_sync_state (folder_path, imap_total, headers_cached, last_sync_at)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                imap_total = VALUES(imap_total),
                headers_cached = GREATEST(headers_cached, VALUES(headers_cached)),
                last_sync_at = NOW()',
            [$folderPath, max(0, $imapTotal), max(0, $headersCached)]
        );
        self::resetSyncStateMaps();
    }

    private static function parseMsgDate(mixed $date): ?string
    {
        $dt = to_app_datetime($date);

        return $dt?->format('Y-m-d H:i:s');
    }
}
