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

    public static function updateIndexFlagged(string $folderPath, int $uid, bool $flagged): void
    {
        Database::query(
            'UPDATE mail_index SET flagged = ?, synced_at = NOW() WHERE folder_path = ? AND imap_uid = ?',
            [$flagged ? 1 : 0, $folderPath, $uid]
        );
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
        foreach ($uids as $uid) {
            self::removeMessage($folderPath, (int) $uid);
        }
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
        if ($date === null || $date === '') {
            return null;
        }

        $ts = is_numeric($date) ? (int) $date : strtotime((string) $date);
        if ($ts === false || $ts <= 0) {
            return null;
        }

        return date('Y-m-d H:i:s', $ts);
    }
}
