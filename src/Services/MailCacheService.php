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

        foreach (['mail_sync_state', 'mail_index'] as $table) {
            $row = Database::fetchOne(
                "SELECT folder_path FROM {$table} WHERE LOWER(folder_path) = LOWER(?) LIMIT 1",
                [$lookup]
            );
            if ($row !== null && ($row['folder_path'] ?? '') !== '') {
                self::$indexFolderPathCache[$lookup] = (string) $row['folder_path'];

                return self::$indexFolderPathCache[$lookup];
            }
        }

        $resolved = FolderCache::resolvePath($folderPath);
        $messagesPath = employee_messages_imap_path($resolved);
        if ($messagesPath !== '' && strcasecmp($messagesPath, $resolved) !== 0) {
            foreach (['mail_sync_state', 'mail_index'] as $table) {
                $row = Database::fetchOne(
                    "SELECT folder_path FROM {$table} WHERE LOWER(folder_path) = LOWER(?) LIMIT 1",
                    [$messagesPath]
                );
                if ($row !== null && ($row['folder_path'] ?? '') !== '') {
                    $canonical = (string) $row['folder_path'];
                    self::$indexFolderPathCache[$lookup] = $canonical;
                    self::$indexFolderPathCache[strtolower($messagesPath)] = $canonical;

                    return $canonical;
                }
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

        try {
            $linkedId = null;
            foreach (array_values(array_unique($candidates)) as $candidate) {
                $row = Database::fetchOne(
                    "SELECT linked_user_id FROM folders
                     WHERE active = 1 AND folder_type = 'employee' AND linked_user_id IS NOT NULL
                     AND LOWER(imap_path) = LOWER(?)
                     LIMIT 1",
                    [$candidate]
                );
                if ($row !== null && !empty($row['linked_user_id'])) {
                    $linkedId = (int) $row['linked_user_id'];
                    break;
                }
            }
            self::$linkedUserCache[$key] = $linkedId;
        } catch (\Throwable) {
            self::$linkedUserCache[$key] = null;
        }

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

    /**
     * Admin badge on an employee inbox: unseen inbound in that folder only.
     * Outbound copies in other employee folders badge those folders, not this one.
     */
    public static function countAdminEmployeeInboxBadge(string $folderPath): int
    {
        $folderPath = self::indexFolderPath($folderPath);
        $linkedId = self::linkedUserId($folderPath);
        if ($linkedId === null) {
            return self::countRawUnseenInIndex($folderPath);
        }

        $viewerId = (int) (Auth::user()['id'] ?? 0);
        if ($viewerId <= 0) {
            return 0;
        }

        $truth = self::countUnseenForUser($viewerId, $folderPath);

        return $truth;
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
     * Employee's own inbox copy of mail they sent elsewhere: read for them, unseen for admin.
     */
    public static function reconcileSenderInboxOutboundCopy(
        string $folderPath,
        int $employeeUserId,
        string $fromEmail,
        ?string $sentMessageId = null,
        int $limit = 5,
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
            self::updateIndexSeen($folderPath, $uid, true);
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

    /**
     * Per-user read flags for a page of messages (one query instead of N).
     *
     * @param list<int> $uids
     * @return array<int, true>
     */
    public static function batchUserReadState(string $folderPath, array $uids, ?int $userId = null): array
    {
        $userId = $userId ?? (int) (Auth::user()['id'] ?? 0);
        $folderPaths = self::userReadFolderPaths($folderPath);
        $uids = array_values(array_unique(array_filter(array_map('intval', $uids), static fn (int $uid): bool => $uid > 0)));
        if ($userId <= 0 || $folderPaths === [] || $uids === []) {
            return [];
        }

        $uidPlaceholders = implode(',', array_fill(0, count($uids), '?'));
        $pathPlaceholders = implode(',', array_fill(0, count($folderPaths), '?'));
        $params = array_merge([$userId], $folderPaths, $uids);

        try {
            $rows = Database::query(
                "SELECT imap_uid FROM mail_user_read
                 WHERE user_id = ?
                   AND folder_path IN ({$pathPlaceholders})
                   AND imap_uid IN ({$uidPlaceholders})",
                $params
            )->fetchAll();
        } catch (\Throwable) {
            return [];
        }

        $read = [];
        foreach ($rows as $row) {
            $uid = (int) ($row['imap_uid'] ?? 0);
            if ($uid > 0) {
                $read[$uid] = true;
            }
        }

        return $read;
    }

    public static function userHasRead(int $userId, string $folderPath, int $uid): bool
    {
        if ($userId <= 0 || $uid <= 0) {
            return false;
        }

        $folderPaths = self::userReadFolderPaths($folderPath);
        if ($folderPaths === []) {
            return false;
        }

        $placeholders = implode(',', array_fill(0, count($folderPaths), '?'));
        $params = array_merge([$userId, $uid], $folderPaths);

        try {
            $row = Database::fetchOne(
                "SELECT 1 FROM mail_user_read
                 WHERE user_id = ? AND imap_uid = ? AND folder_path IN ({$placeholders})
                 LIMIT 1",
                $params
            );
        } catch (\Throwable) {
            return false;
        }

        return $row !== null;
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
        $userId = $userId ?? (int) (Auth::user()['id'] ?? 0);
        $folderPath = self::indexFolderPath($folderPath);

        if (self::usesPerUserRead($folderPath) && $userId > 0) {
            $linkedId = self::linkedUserId($folderPath);
            if ($linkedId !== null && $userId !== $linkedId && self::viewerIsAdmin()) {
                if (self::userHasRead($userId, $folderPath, $uid)) {
                    return true;
                }

                $indexSeen = self::indexSeenState($folderPath, $uid);

                return $indexSeen === null ? false : $indexSeen;
            }

            return self::userHasRead($userId, $folderPath, $uid);
        }

        if ($userId > 0 && self::sharedMailboxUsesPerUserSeen($folderPath, $userId)) {
            return self::userHasRead($userId, $folderPath, $uid);
        }

        return (bool) self::indexSeenState($folderPath, $uid);
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

    public static function countUnseenForUser(int $userId, string $folderPath): int
    {
        if ($userId <= 0) {
            return 0;
        }

        $folderPath = self::indexFolderPath($folderPath);
        if (mail_should_group_list_by_thread($folderPath)) {
            return self::countThreadGroupedUnseenForUser($userId, $folderPath);
        }

        $removed = mail_removed_uids_for_folder($folderPath);

        try {
            $rows = Database::query(
                'SELECT i.imap_uid, i.from_addr, i.subject, i.msg_date
                 FROM mail_index i
                 WHERE i.folder_path = ?
                   AND NOT EXISTS (
                       SELECT 1 FROM mail_user_read r
                       WHERE r.user_id = ? AND r.folder_path = i.folder_path AND r.imap_uid = i.imap_uid
                   )',
                [$folderPath, $userId]
            )->fetchAll();
        } catch (\Throwable) {
            return 0;
        }

        $count = 0;
        foreach ($rows as $row) {
            $uid = (int) ($row['imap_uid'] ?? 0);
            if ($uid <= 0 || isset($removed[$uid])) {
                continue;
            }
            if (employee_is_own_inbox_folder($folderPath)) {
                $msg = [
                    'from' => (string) ($row['from_addr'] ?? ''),
                    'subject' => (string) ($row['subject'] ?? ''),
                    'date' => (string) ($row['msg_date'] ?? ''),
                ];
                if (employee_should_hide_inbox_correspondent_message($msg)) {
                    continue;
                }
            } elseif (
                self::viewerIsAdmin(['id' => $userId, 'role' => 'admin'])
                && mail_linked_user_id_for_inbox($folderPath) !== null
            ) {
                $msg = [
                    'from' => (string) ($row['from_addr'] ?? ''),
                    'subject' => (string) ($row['subject'] ?? ''),
                    'date' => (string) ($row['msg_date'] ?? ''),
                ];
                if (admin_should_hide_employee_inbox_correspondent_message($msg)) {
                    continue;
                }
            }
            $linkedEmployeeId = mail_linked_user_id_for_inbox($folderPath);
            if ($linkedEmployeeId !== null && !mail_employee_inbox_row_counts_for_badge($folderPath, $row, $linkedEmployeeId)) {
                continue;
            }
            $count++;
        }

        return $count;
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
                    b.message_id
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

        $uids = array_values(array_unique(array_filter(array_map(
            static fn (array $row): int => (int) ($row['imap_uid'] ?? 0),
            $rows
        ), static fn (int $uid): bool => $uid > 0)));
        $viewerId = (int) (Auth::user()['id'] ?? 0);
        $readMap = self::usesPerUserRead($folderPath)
            || ($viewerId > 0 && self::sharedMailboxUsesPerUserSeen($folderPath, $viewerId))
            ? self::batchUserReadState($folderPath, $uids, $viewerId)
            : null;

        $messages = [];
        foreach ($rows as $row) {
            $messages[] = self::indexRowToMessage($row, $folderPath, $readMap);
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

    public static function hasFolderData(string $folderPath): bool
    {
        $row = Database::fetchOne(
            'SELECT headers_cached, imap_total, last_sync_at
             FROM mail_sync_state WHERE folder_path = ? LIMIT 1',
            [$folderPath]
        );

        if ($row === null || empty($row['last_sync_at'])) {
            return false;
        }

        return (int) ($row['headers_cached'] ?? 0) > 0
            || (int) ($row['imap_total'] ?? 0) === 0;
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

        try {
            foreach (array_values(array_unique($candidates)) as $candidate) {
                $row = Database::fetchOne(
                    'SELECT folder_type, linked_user_id FROM folders
                     WHERE active = 1 AND LOWER(imap_path) = LOWER(?)
                     LIMIT 1',
                    [$candidate]
                );
                if ($row !== null
                    && ($row['folder_type'] ?? '') === 'employee'
                    && ($row['linked_user_id'] ?? null) === null) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }

    /**
     * Admin unread in a shared employee folder — one count per thread, aligned with list rows.
     */
    public static function countSharedEmployeeMailboxUnseen(string $folderPath): int
    {
        $folderPath = self::indexFolderPath($folderPath);

        if (!self::viewerIsAdmin()) {
            $privacyEmails = employee_correspondent_privacy_emails($folderPath);
            if ($privacyEmails !== null) {
                return self::countCorrespondentUnseenWithReplies($folderPath, $privacyEmails);
            }

            $rows = Database::query(
                'SELECT imap_uid, from_addr FROM mail_index WHERE folder_path = ? AND seen = 0',
                [$folderPath]
            )->fetchAll();

            $count = 0;
            $viewerId = (int) (Auth::user()['id'] ?? 0);
            foreach ($rows as $row) {
                $uid = (int) ($row['imap_uid'] ?? 0);
                if ($uid <= 0) {
                    continue;
                }
                $msg = ['from' => (string) ($row['from_addr'] ?? '')];
                if (mail_is_shared_mailbox_alias_sent_echo($folderPath, $msg)) {
                    if ($viewerId > 0 && !self::userHasRead($viewerId, $folderPath, $uid)) {
                        $count++;
                    }
                    continue;
                }
                if (mail_is_employee_outbound_echo($folderPath, $uid, (string) ($row['from_addr'] ?? ''))) {
                    continue;
                }
                $count++;
            }

            return $count;
        }

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
            $msg = self::messageFromIndexRow($row, $folderPath);
            $key = mail_normalize_thread_subject((string) ($msg['subject'] ?? ''));
            if ($key === '') {
                $key = 'uid:' . (int) ($msg['uid'] ?? 0);
            }

            $msgTs = strtotime((string) ($msg['date'] ?? '')) ?: 0;
            $existingTs = isset($groups[$key])
                ? (strtotime((string) ($groups[$key]['date'] ?? '')) ?: 0)
                : -1;
            if (
                !isset($groups[$key])
                || $msgTs > $existingTs
                || ($msgTs === $existingTs && (int) ($msg['uid'] ?? 0) > (int) ($groups[$key]['uid'] ?? 0))
            ) {
                $groups[$key] = $msg;
            }
        }

        $viewerId = (int) (Auth::user()['id'] ?? 0);
        $count = 0;
        foreach ($groups as $msg) {
            $uid = (int) ($msg['uid'] ?? 0);
            if ($uid <= 0) {
                continue;
            }
            if (mail_is_shared_mailbox_alias_sent_echo($folderPath, $msg)) {
                continue;
            }
            if (mail_is_employee_inbound_to_shared_mailbox($folderPath, $msg)) {
                if ($viewerId > 0 && !self::userHasRead($viewerId, $folderPath, $uid)) {
                    $count++;
                }
                continue;
            }
            if (!self::effectiveSeen($folderPath, $uid, $viewerId)) {
                $count++;
            }
        }

        return $count;
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

        $rows = Database::query(
            'SELECT imap_uid FROM mail_index WHERE folder_path = ?',
            [$folderPath]
        )->fetchAll();
        if ($rows === []) {
            return 0;
        }

        $count = 0;
        foreach ($rows as $row) {
            $uid = (int) ($row['imap_uid'] ?? 0);
            if ($uid > 0 && !isset($removed[$uid])) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Sidebar badge is lower than the indexed unseen count.
     */
    public static function badgeBehindIndex(string $folderPath): bool
    {
        $folderPath = FolderCache::resolvePath($folderPath);
        if ($folderPath === '' || !folder_shows_unread_badge($folderPath)) {
            return false;
        }

        $session = FolderCache::sessionUnreadCountRaw($folderPath);
        $privacyEmails = employee_correspondent_privacy_emails($folderPath);
        if ($privacyEmails !== null) {
            return self::countCorrespondentUnseenWithReplies($folderPath, $privacyEmails) > $session;
        }

        if (self::usesPerUserRead($folderPath)) {
            $linkedId = self::linkedUserId($folderPath);
            $viewerId = (int) (Auth::user()['id'] ?? 0);
            if ($linkedId !== null && $viewerId === $linkedId) {
                return self::countUnseenForUser($linkedId, $folderPath) > $session;
            }
            if ($linkedId !== null && self::viewerIsAdmin()) {
                return self::countAdminEmployeeInboxBadge($folderPath) > $session;
            }
        }

        if (!self::hasFolderData($folderPath)) {
            return false;
        }

        return self::countBadgeFromIndex($folderPath) > $session;
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

        if (mail_admin_outbound_suppresses_sidebar_badge($folderPath)) {
            return 0;
        }

        if ($sessionCount === null) {
            $sessionCount = FolderCache::sessionUnreadCountRaw($folderPath);
        }

        $privacyEmails = employee_correspondent_privacy_emails($folderPath);
        if ($privacyEmails !== null) {
            if (!self::viewerIsAdmin() && employee_is_correspondent_folder($folderPath)) {
                return mail_correspondent_folder_badge_count($folderPath);
            }

            return self::countCorrespondentUnseenWithReplies($folderPath, $privacyEmails);
        }

        if (self::usesPerUserRead($folderPath)) {
            $linkedId = self::linkedUserId($folderPath);
            $viewerId = (int) (Auth::user()['id'] ?? 0);
            if ($linkedId !== null && $viewerId === $linkedId) {
                return self::countUnseenForUser($linkedId, $folderPath);
            }
            if ($linkedId !== null && self::viewerIsAdmin()) {
                $indexed = self::countAdminEmployeeInboxBadge($folderPath);
                if (
                    FolderCache::isPendingBadgePath($folderPath)
                    || admin_employee_inbox_preview_inflates_badge($folderPath)
                ) {
                    return max($indexed, $sessionCount);
                }

                return $indexed;
            }
        }

        if (folder_uses_draft_badge($folderPath) && self::hasFolderData($folderPath)) {
            return self::countBadgeFromIndex($folderPath);
        }

        if (self::hasFolderData($folderPath)) {
            return self::mergeBadgeWithSession(
                $folderPath,
                self::countBadgeFromIndex($folderPath),
                $sessionCount
            );
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
        if (
            self::isStale($folderPath)
            || self::imapTotalDrifted($folderPath, $imapTotal)
            || self::badgeAheadOfIndex($folderPath)
        ) {
            self::syncFolderHeaders($imap, $folderPath);
        }
    }

    /**
     * @return array{folder_path: string, imap_total: int, headers_cached: int, last_sync_at: string|null}|null
     */
    public static function getSyncState(string $folderPath): ?array
    {
        $folderPath = self::indexFolderPath($folderPath);

        return Database::fetchOne(
            'SELECT folder_path, imap_total, headers_cached, last_sync_at FROM mail_sync_state WHERE folder_path = ?',
            [$folderPath]
        );
    }

    /**
     * Pull recent headers from IMAP into mail_index.
     */
    public static function syncFolderHeaders(ImapService $imap, string $folderPath, ?int $limit = null): int
    {
        $limit = $limit ?? (int) (config('app')['mail_cache_header_limit'] ?? 200);
        $list = $imap->listMessages($folderPath, 1, $limit);
        self::upsertIndexMessages($folderPath, $list['messages'], (int) $list['total']);

        mail_reconcile_correspondent_badges_for_linked_inbox($folderPath);

        return count($list['messages']);
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

        return count($list['messages']);
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    public static function upsertIndexMessages(string $folderPath, array $messages, int $imapTotal): void
    {
        if ($messages === []) {
            self::touchSyncState($folderPath, $imapTotal, 0);

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

        Database::query(
            'INSERT INTO mail_index
                (folder_path, imap_uid, from_addr, to_addrs, cc_addrs, subject, msg_date, seen, flagged, has_attachment, size, synced_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                from_addr = VALUES(from_addr),
                to_addrs = COALESCE(NULLIF(VALUES(to_addrs), \'\'), to_addrs),
                cc_addrs = COALESCE(NULLIF(VALUES(cc_addrs), \'\'), cc_addrs),
                subject = VALUES(subject),
                msg_date = VALUES(msg_date),
                seen = GREATEST(seen, VALUES(seen)),
                flagged = GREATEST(flagged, VALUES(flagged)),
                has_attachment = VALUES(has_attachment),
                size = VALUES(size),
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
            ]
        );
    }

    /**
     * @return array<string, mixed>|null Message shape for loadMessageContext / pane.
     */
    public static function getBody(string $folderPath, int $uid): ?array
    {
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
            'seen' => self::effectiveSeen($folderPath, $uid),
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

        foreach ($missing as $uid) {
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

            if (!$light
                || employee_is_correspondent_folder($folderPath)
                || self::isSharedEmployeeMailbox($folderPath)
                || mail_linked_user_id_for_inbox($folderPath) !== null) {
                mail_enrich_list_with_thread_preview($folderPath, $msg);
            }
            mail_note_correspondent_from_list_message($msg);
        }
        unset($msg);

        if (mail_linked_user_id_for_inbox($folderPath) !== null
            || employee_is_correspondent_folder($folderPath)
            || \App\Services\MailCacheService::isSharedEmployeeMailbox($folderPath)) {
            $messages = mail_resort_list_by_message_date($messages);
        }

        return $messages;
    }

    public static function updateIndexSeen(string $folderPath, int $uid, bool $seen): void
    {
        Database::query(
            'UPDATE mail_index SET seen = ?, synced_at = NOW() WHERE folder_path = ? AND imap_uid = ?',
            [$seen ? 1 : 0, $folderPath, $uid]
        );
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
    public static function updateIndexSeenBulk(string $folderPath, array $uids, bool $seen): void
    {
        if ($uids === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($uids), '?'));
        $params = array_merge([$seen ? 1 : 0, $folderPath], $uids);
        Database::query(
            "UPDATE mail_index SET seen = ?, synced_at = NOW() WHERE folder_path = ? AND imap_uid IN ({$placeholders})",
            $params
        );
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
        $folderPath = self::indexFolderPath($folderPath);
        $privacyEmails = employee_correspondent_privacy_emails($folderPath);
        if ($privacyEmails !== null) {
            return self::countCorrespondentUnseenWithReplies($folderPath, $privacyEmails);
        }

        if (self::viewerIsAdmin() && self::isSharedEmployeeMailbox($folderPath)) {
            return self::countSharedEmployeeMailboxUnseen($folderPath);
        }

        if (self::usesPerUserRead($folderPath)) {
            $linkedId = self::linkedUserId($folderPath);
            $viewerId = (int) (Auth::user()['id'] ?? 0);
            if ($linkedId !== null && $viewerId === $linkedId) {
                return self::countUnseenForUser($linkedId, $folderPath);
            }
            if ($linkedId !== null && self::viewerIsAdmin()) {
                return self::countAdminEmployeeInboxBadge($folderPath);
            }
        }

        $row = Database::fetchOne(
            'SELECT COUNT(*) AS c FROM mail_index WHERE folder_path = ? AND seen = 0',
            [$folderPath]
        );

        return (int) ($row['c'] ?? 0);
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

        // Correspondent folders: the badge must reflect only mail the viewer is a
        // party to, never the whole-folder IMAP unseen count. Set it exactly.
        $privacyEmails = employee_correspondent_privacy_emails($folderPath);
        if ($privacyEmails !== null) {
            $truth = self::countCorrespondentUnseenWithReplies($folderPath, $privacyEmails);
            FolderCache::setUnreadCount($folderPath, $truth);

            return $truth;
        }

        if (self::usesPerUserRead($folderPath)) {
            $linkedId = self::linkedUserId($folderPath);
            $viewerId = (int) (Auth::user()['id'] ?? 0);
            if ($linkedId !== null && $viewerId === $linkedId) {
                $truth = self::countUnseenForUser($linkedId, $folderPath);
                FolderCache::setUnreadCount($folderPath, $truth);

                return $truth;
            }
            if ($linkedId !== null && self::viewerIsAdmin()) {
                $session = FolderCache::sessionUnreadCountRaw($folderPath);
                $truth = self::countAdminEmployeeInboxBadge($folderPath);

                $pageUnread = null;
                if ($pageMessages !== null && !folder_uses_draft_badge($folderPath)) {
                    $pageUnread = 0;
                    foreach ($pageMessages as $msg) {
                        if (!is_array($msg) || !empty($msg['seen'])) {
                            continue;
                        }
                        if (admin_should_hide_employee_inbox_correspondent_message($msg)) {
                            continue;
                        }
                        $pageUnread++;
                    }
                }

                $allowSessionInflate = FolderCache::isPendingBadgePath($folderPath)
                    || admin_employee_inbox_preview_inflates_badge($folderPath);
                if ($pageUnread !== null && $pageUnread === 0 && $truth === 0) {
                    $allowSessionInflate = false;
                }

                if ($allowSessionInflate) {
                    $truth = max($truth, $session);
                }

                FolderCache::setUnreadCount($folderPath, $truth);

                return $truth;
            }
        }

        $session = FolderCache::sessionUnreadCountRaw($folderPath);

        $pageUnread = 0;
        if ($pageMessages !== null && !folder_uses_draft_badge($folderPath)) {
            foreach ($pageMessages as $msg) {
                if (!is_array($msg) || !empty($msg['seen'])) {
                    continue;
                }
                if (
                    self::viewerIsAdmin()
                    && self::isSharedEmployeeMailbox($folderPath)
                    && mail_is_shared_mailbox_alias_sent_echo($folderPath, $msg)
                ) {
                    continue;
                }
                if (
                    self::viewerIsAdmin()
                    && mail_linked_user_id_for_inbox($folderPath) !== null
                    && admin_should_hide_employee_inbox_correspondent_message($msg)
                ) {
                    continue;
                }
                $pageUnread++;
            }
        }

        if (!self::hasFolderData($folderPath)) {
            if ($pageMessages !== null && $pageUnread > 0 && !folder_uses_draft_badge($folderPath)) {
                $truth = max($session, $pageUnread);
                if ($truth !== $session) {
                    FolderCache::setUnreadCount($folderPath, $truth);
                }

                return $truth;
            }

            if ($pageMessages !== null && $pageUnread === 0 && $session > 0 && !folder_uses_draft_badge($folderPath)) {
                if (self::hasFolderData($folderPath) && self::countBadgeFromIndex($folderPath) === 0) {
                    FolderCache::setUnreadCount($folderPath, 0);

                    return 0;
                }

                // Keep the session badge until the folder is indexed — do not
                // clear optimistic/post-delivery counts while cache is empty.
                return $session;
            }

            return $session;
        }

        if (folder_uses_draft_badge($folderPath)) {
            self::reconcileSyncStateFromIndex($folderPath);
            $truth = self::countBadgeFromIndex($folderPath);
            if ($truth !== $session) {
                FolderCache::setUnreadCount($folderPath, $truth);

                return $truth;
            }

            return $session;
        }

        if (self::syncBadgeFromIndex($folderPath)) {
            return FolderCache::sessionUnreadCountRaw($folderPath);
        }

        $indexUnread = self::countBadgeFromIndex($folderPath);
        $truth = self::mergeBadgeWithSession($folderPath, max($indexUnread, $pageUnread), $session);

        if ($truth !== $session) {
            FolderCache::setUnreadCount($folderPath, $truth);

            return $truth;
        }

        return $session;
    }

    /**
     * Clear phantom sidebar badges for every folder we have indexed locally.
     */
    public static function reconcileAllIndexedBadges(): void
    {
        foreach (FolderCache::load(skipUnreadRefresh: true)['folders'] as $folder) {
            $path = (string) ($folder['path'] ?? '');
            if ($path === '' || !self::hasFolderData($path)) {
                continue;
            }
            self::reconcileBadgeFromIndex($path);
        }
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
    public static function reconcileFolderBadge(ImapService $imap, string $folderPath): int
    {
        self::refreshHeadersIfNeeded($imap, $folderPath);

        foreach ($imap->getUnseenUids($folderPath) as $uid) {
            $uid = (int) $uid;
            if ($imap->isSeen($folderPath, $uid)) {
                // Phantom UNSEEN — overview says read; clear stale server flag.
                $imap->markSeen($folderPath, $uid);
                self::updateIndexSeen($folderPath, $uid, true);
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

    /** @deprecated Use reconcileFolderBadge() */
    public static function alignFolderSeenFromImap(ImapService $imap, string $folderPath): int
    {
        return self::reconcileFolderBadge($imap, $folderPath);
    }

    public static function removeMessage(string $folderPath, int $uid): void
    {
        $folderPath = self::indexFolderPath($folderPath);
        Database::query('DELETE FROM mail_index WHERE folder_path = ? AND imap_uid = ?', [$folderPath, $uid]);
        Database::query('DELETE FROM mail_bodies WHERE folder_path = ? AND imap_uid = ?', [$folderPath, $uid]);
        self::reconcileSyncStateFromIndex($folderPath);
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
        $params = array_merge([$toPath, $fromPath], $uids);

        $indexMoved = Database::query(
            "UPDATE mail_index SET folder_path = ?, seen = 1 WHERE folder_path = ? AND imap_uid IN ({$placeholders})",
            $params
        )->rowCount();

        Database::query(
            "UPDATE mail_bodies SET folder_path = ? WHERE folder_path = ? AND imap_uid IN ({$placeholders})",
            $params
        );

        if ($indexMoved > 0) {
            self::reconcileSyncStateFromIndex($fromPath);
            $indexed = self::countListableMessagesInIndex($toPath);
            self::touchSyncState($toPath, max($indexed, $indexMoved), $indexed);
        }

        return $indexMoved;
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
        $params = array_merge($accessiblePaths, [$like, $like, $like, $like]);

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

        if (self::usesPerUserRead($folderPath)) {
            $viewerId = $viewerId ?? (int) (Auth::user()['id'] ?? 0);
            $linkedId = self::linkedUserId($folderPath);
            if ($linkedId !== null && $viewerId !== $linkedId && self::viewerIsAdmin()) {
                $seen = ($readMap !== null && isset($readMap[$uid])) || (bool) ($row['seen'] ?? false);
            } else {
                $seen = $readMap !== null
                    ? isset($readMap[$uid])
                    : self::effectiveSeen($folderPath, $uid, $viewerId);
            }
        } else {
            $viewerId = $viewerId ?? (int) (Auth::user()['id'] ?? 0);
            if (self::sharedMailboxUsesPerUserSeen($folderPath, $viewerId)) {
                $seen = $readMap !== null
                    ? isset($readMap[$uid])
                    : self::effectiveSeen($folderPath, $uid, $viewerId);
            } else {
                $seen = (bool) ($row['seen'] ?? false);
            }
        }

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
    }

    private static function parseMsgDate(mixed $date): ?string
    {
        $dt = to_app_datetime($date);

        return $dt?->format('Y-m-d H:i:s');
    }
}
