<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;

class MailCacheService
{
    /**
     * @return array{messages: list<array<string, mixed>>, total: int, page: int, per_page: int, total_pages: int, from_cache: bool}|null
     */
    public static function listFromCache(string $folderPath, int $page, int $perPage): ?array
    {
        if (!self::hasFolderData($folderPath)) {
            return null;
        }

        $state = self::getSyncState($folderPath);
        $total = (int) ($state['imap_total'] ?? 0);
        if ($total <= 0) {
            $row = Database::fetchOne(
                'SELECT COUNT(*) AS c FROM mail_index WHERE folder_path = ?',
                [$folderPath]
            );
            $total = (int) ($row['c'] ?? 0);
        }

        if ($total === 0) {
            return null;
        }

        $totalPages = (int) max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $totalPages));
        $offset = ($page - 1) * $perPage;

        $rows = Database::query(
            'SELECT imap_uid, from_addr, subject, msg_date, seen, flagged, has_attachment, size
             FROM mail_index
             WHERE folder_path = ?
             ORDER BY msg_date DESC, imap_uid DESC
             LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
            [$folderPath]
        )->fetchAll();

        if ($rows === [] && $offset > 0) {
            return null;
        }

        $messages = [];
        foreach ($rows as $row) {
            $messages[] = self::indexRowToMessage($row);
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

    public static function hasFolderData(string $folderPath): bool
    {
        $row = Database::fetchOne(
            'SELECT 1 FROM mail_sync_state WHERE folder_path = ? AND headers_cached > 0 LIMIT 1',
            [$folderPath]
        );

        return $row !== null;
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
    }

    public static function invalidateFolder(string $folderPath): void
    {
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

    /**
     * Sidebar badge shows unread on IMAP but the cached list has not caught up yet.
     */
    public static function badgeAheadOfIndex(string $folderPath): bool
    {
        $folderData = FolderCache::load(skipUnreadRefresh: true);
        $badge = (int) ($folderData['unread_counts'][$folderPath] ?? 0);
        if ($badge <= 0) {
            return false;
        }

        if (!self::hasFolderData($folderPath)) {
            return true;
        }

        return self::countUnseenInIndex($folderPath) < $badge;
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

        self::touchSyncState($folderPath, $imapTotal, count($messages));
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

        Database::query(
            'INSERT INTO mail_index
                (folder_path, imap_uid, from_addr, subject, msg_date, seen, flagged, has_attachment, size, synced_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                from_addr = VALUES(from_addr),
                subject = VALUES(subject),
                msg_date = VALUES(msg_date),
                seen = VALUES(seen),
                flagged = VALUES(flagged),
                has_attachment = VALUES(has_attachment),
                size = VALUES(size),
                synced_at = NOW()',
            [
                $folderPath,
                $uid,
                (string) ($msg['from'] ?? ''),
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
            'seen' => $index !== null ? (bool) $index['seen'] : true,
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
        $row = Database::fetchOne(
            'SELECT COUNT(*) AS c FROM mail_index WHERE folder_path = ? AND seen = 0',
            [$folderPath]
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
        if (!folder_shows_unread_badge($folderPath)) {
            FolderCache::setUnreadCount($folderPath, 0);

            return 0;
        }

        $session = (int) (FolderCache::load(skipUnreadRefresh: true)['unread_counts'][$folderPath] ?? 0);

        $pageUnread = 0;
        if ($pageMessages !== null) {
            foreach ($pageMessages as $msg) {
                if (empty($msg['seen'])) {
                    $pageUnread++;
                }
            }
        }

        if (!self::hasFolderData($folderPath)) {
            return $session;
        }

        if (self::syncBadgeFromIndex($folderPath)) {
            return self::countUnseenInIndex($folderPath);
        }

        $indexUnread = self::countUnseenInIndex($folderPath);

        if ($pageUnread === 0 && $indexUnread === 0 && $session > 0) {
            FolderCache::setUnreadCount($folderPath, 0);

            return 0;
        }

        $truth = max($indexUnread, $pageUnread);
        if ($truth > $session) {
            FolderCache::setUnreadCount($folderPath, $truth);

            return $truth;
        }

        if ($session > 0 && $truth === 0 && $pageMessages !== null) {
            FolderCache::setUnreadCount($folderPath, 0);

            return 0;
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
            return false;
        }

        FolderCache::setUnreadCount($folderPath, self::countUnseenInIndex($folderPath));

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
            return self::countUnseenInIndex($folderPath);
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
        Database::query('DELETE FROM mail_index WHERE folder_path = ? AND imap_uid = ?', [$folderPath, $uid]);
        Database::query('DELETE FROM mail_bodies WHERE folder_path = ? AND imap_uid = ?', [$folderPath, $uid]);
    }

    /**
     * @param list<int> $uids
     */
    public static function removeMessages(string $folderPath, array $uids): void
    {
        $uids = array_values(array_unique(array_filter(array_map('intval', $uids), static fn (int $u): bool => $u > 0)));
        if ($uids === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($uids), '?'));
        $params = array_merge([$folderPath], $uids);
        Database::query(
            "DELETE FROM mail_index WHERE folder_path = ? AND imap_uid IN ({$placeholders})",
            $params
        );
        Database::query(
            "DELETE FROM mail_bodies WHERE folder_path = ? AND imap_uid IN ({$placeholders})",
            $params
        );
    }

    /**
     * UIDs from the local header cache (fast path for bulk select-all).
     *
     * @return list<int>
     */
    public static function folderMessageUids(string $folderPath, string $searchQuery = ''): array
    {
        if ($folderPath === '') {
            return [];
        }

        $query = trim($searchQuery);
        if ($query !== '') {
            $like = '%' . $query . '%';
            $rows = Database::fetchAll(
                'SELECT imap_uid FROM mail_index
                 WHERE folder_path = ?
                   AND (subject LIKE ? OR from_addr LIKE ?)
                 ORDER BY msg_date DESC',
                [$folderPath, $like, $like]
            );
        } else {
            $rows = Database::fetchAll(
                'SELECT imap_uid FROM mail_index WHERE folder_path = ? ORDER BY msg_date DESC',
                [$folderPath]
            );
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
    private static function indexRowToMessage(array $row): array
    {
        return [
            'uid' => (int) $row['imap_uid'],
            'from' => (string) ($row['from_addr'] ?? ''),
            'subject' => (string) ($row['subject'] ?? '(no subject)'),
            'date' => $row['msg_date'] ?? '',
            'seen' => (bool) ($row['seen'] ?? false),
            'flagged' => (bool) ($row['flagged'] ?? false),
            'has_attachment' => (bool) ($row['has_attachment'] ?? false),
            'size' => (int) ($row['size'] ?? 0),
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
