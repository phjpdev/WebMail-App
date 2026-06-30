<?php

declare(strict_types=1);

namespace App\Services;

class ImapService
{
    private $connection = null;

    /**
     * A single authenticated IMAP connection is reused by every ImapService
     * instance within one request. Repeatedly calling imap_open() previously
     * caused slow page loads and "Too many login failures" throttling.
     */
    private static $sharedConnection = null;
    private static bool $shutdownRegistered = false;

    private string $lastError = '';

    public function connect(): bool
    {
        if ($this->connection !== null) {
            return true;
        }

        if (self::$sharedConnection !== null) {
            if (@imap_ping(self::$sharedConnection)) {
                $this->connection = self::$sharedConnection;

                return true;
            }

            // Stale/dropped connection — discard and reconnect below.
            @imap_close(self::$sharedConnection);
            self::$sharedConnection = null;
        }

        if (!function_exists('imap_open')) {
            $this->lastError = 'PHP IMAP extension is not enabled. Enable extension=imap in php.ini.';
            app_log($this->lastError);

            return false;
        }

        $config = config('mail');
        $mailbox = $config['mailbox_email'];
        $password = $config['mailbox_password'];

        if ($mailbox === '' || $password === '') {
            $this->lastError = 'Mailbox credentials are not configured in .env';
            app_log($this->lastError);

            return false;
        }

        imap_errors();
        imap_alerts();

        // Bound connect/read/write so a throttled mail server fails fast instead
        // of hanging the request (and exhausting Apache/PHP workers, which makes
        // unrelated folder opens appear frozen).
        if (function_exists('imap_timeout')) {
            $openTimeout = (int) ($config['imap']['open_timeout'] ?? 8);
            $ioTimeout = (int) ($config['imap']['io_timeout'] ?? 12);
            @imap_timeout(IMAP_OPENTIMEOUT, max(1, $openTimeout));
            @imap_timeout(IMAP_READTIMEOUT, max(1, $ioTimeout));
            @imap_timeout(IMAP_WRITETIMEOUT, max(1, $ioTimeout));
            @imap_timeout(IMAP_CLOSETIMEOUT, max(1, $openTimeout));
        }

        // Skip slow SASL negotiation (GSSAPI/NTLM) and go straight to LOGIN —
        // this can cut 1–2 seconds off every connection on some mail hosts.
        $connection = @imap_open(
            $this->getMailboxString() . 'INBOX',
            $mailbox,
            $password,
            0,
            1,
            ['DISABLE_AUTHENTICATOR' => ['GSSAPI', 'NTLM']]
        );

        if ($connection === false) {
            $errors = imap_errors() ?: [];
            $this->lastError = 'IMAP connection failed: ' . implode('; ', $errors);
            app_log($this->lastError);

            return false;
        }

        $this->connection = $connection;
        self::$sharedConnection = $connection;
        self::registerShutdown();

        return true;
    }

    private static function registerShutdown(): void
    {
        if (self::$shutdownRegistered) {
            return;
        }

        self::$shutdownRegistered = true;
        register_shutdown_function([self::class, 'closeShared']);
    }

    public static function closeShared(): void
    {
        if (self::$sharedConnection !== null) {
            imap_errors();
            imap_alerts();
            @imap_close(self::$sharedConnection);
            self::$sharedConnection = null;
        }
    }

    public function getLastError(): string
    {
        return $this->lastError;
    }

    /**
     * @return list<array{path: string, name: string, delimiter: string}>
     */
    public function listFolders(): array
    {
        if (!$this->ensureConnected()) {
            return [];
        }

        $mailboxString = $this->getMailboxString();
        // imap_getmailboxes exposes the server's real hierarchy delimiter, which
        // can differ from "." on some IMAP servers.
        $mailboxes = imap_getmailboxes($this->connection, $mailboxString, '*') ?: [];
        $result = [];

        foreach ($mailboxes as $mailbox) {
            $path = $this->decodeFolderName($mailbox->name ?? '');
            if ($path === '') {
                continue;
            }
            $name = $path === 'INBOX' ? 'Inbox' : $path;

            $result[] = [
                'path' => $path,
                'name' => $name,
                'delimiter' => $mailbox->delimiter ?? '.',
            ];
        }

        usort($result, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return $result;
    }

    public function openFolder(string $path): bool
    {
        if (!$this->ensureConnected()) {
            return false;
        }

        $reopened = @imap_reopen($this->connection, $this->getMailboxString() . $this->encodeFolderPath($path));

        if (!$reopened) {
            $errors = imap_errors() ?: [];
            imap_alerts();
            $this->lastError = 'Failed to open folder: ' . implode('; ', $errors);

            return false;
        }

        return true;
    }

    public function getMessageCount(string $path): int
    {
        if (!$this->openFolder($path)) {
            return 0;
        }

        return imap_num_msg($this->connection) ?: 0;
    }

    /**
     * @return array{messages: list<array{uid: int, from: string, subject: string, date: string, seen: bool, size: int}>, total: int, page: int, per_page: int, total_pages: int}
     */
    public function listMessages(string $path, int $page = 1, int $perPage = 50): array
    {
        $empty = [
            'messages' => [],
            'total' => 0,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => 0,
        ];

        if (!$this->openFolder($path)) {
            return $empty;
        }

        $total = imap_num_msg($this->connection) ?: 0;
        if ($total === 0) {
            return $empty;
        }

        $totalPages = (int) max(1, ceil($total / $perPage));
        $page = max(1, min($page, $totalPages));

        $end = $total - ($page - 1) * $perPage;
        $start = max(1, $end - $perPage + 1);

        $overview = imap_fetch_overview($this->connection, "$start:$end");
        if ($overview === false) {
            return $empty;
        }

        $overview = array_reverse($overview);
        $messages = [];

        foreach ($overview as $row) {
            $uid = (int) ($row->uid ?? 0);
            $messages[] = [
                'uid' => $uid,
                'from' => isset($row->from) ? $this->decodeMimeHeader($row->from) : '',
                'to' => isset($row->to) ? $this->decodeMimeHeader($row->to) : '',
                'subject' => isset($row->subject) ? $this->decodeMimeHeader($row->subject) : '(no subject)',
                'date' => $row->date ?? '',
                'seen' => (bool) ($row->seen ?? false),
                'flagged' => (bool) ($row->flagged ?? false),
                'size' => (int) ($row->size ?? 0),
                'has_attachment' => false,
            ];
        }

        return [
            'messages' => $messages,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getMessageByUid(string $path, int $uid): ?array
    {
        if (!$this->openFolder($path)) {
            return null;
        }

        $msgno = imap_msgno($this->connection, $uid);
        if ($msgno === 0) {
            $this->lastError = 'Message not found.';
            return null;
        }

        $header = imap_headerinfo($this->connection, $msgno);
        if ($header === false) {
            return null;
        }

        $rawHeader = imap_fetchheader($this->connection, $msgno) ?: '';
        $body = $this->fetchBody($path, $uid, $msgno);

        $from = '';
        if (isset($header->from[0])) {
            $from = $header->from[0]->mailbox . '@' . $header->from[0]->host;
        }
        $sendAs = $this->extractHeaderValue($rawHeader, 'X-DJ-Send-As');
        if ($sendAs !== null && $sendAs !== '') {
            if (preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $sendAs, $matches)) {
                $from = $matches[0];
            }
        }

        $to = $this->formatAddressList($header->to ?? []);
        $cc = $this->formatAddressList($header->cc ?? []);

        $messageId = $this->extractHeaderValue($rawHeader, 'Message-ID');

        $unseen = (($header->Unseen ?? '') === 'U') || (($header->Recent ?? '') === 'N');

        return [
            'uid' => $uid,
            'msgno' => $msgno,
            'seen' => !$unseen,
            'from' => $from,
            'to' => $to,
            'cc' => $cc,
            'subject' => isset($header->subject) ? $this->decodeMimeHeader($header->subject) : '',
            'date' => $header->date ?? '',
            'delivered_to' => $this->extractHeaderValue($rawHeader, 'Delivered-To')
                ?? $this->extractHeaderValue($rawHeader, 'X-Original-To'),
            'message_id' => $messageId,
            'html' => $body['html'],
            'plain' => $body['plain'],
            'attachments' => $body['attachments'],
        ];
    }

    /**
     * @return array{seen: bool, flagged: bool}|null
     */
    public function getMessageOverviewByUid(string $path, int $uid): ?array
    {
        if (!$this->openFolder($path)) {
            return null;
        }

        $overview = imap_fetch_overview($this->connection, (string) $uid, FT_UID);
        if ($overview === false || $overview === []) {
            return null;
        }

        $row = $overview[0];

        return [
            'seen' => (bool) ($row->seen ?? false),
            'flagged' => (bool) ($row->flagged ?? false),
        ];
    }

    /**
     * @return array{html: string|null, plain: string|null, attachments: list<array{id: string, filename: string, size: int, mime: string}>}
     */
    public function fetchBody(string $path, int $uid, ?int $msgno = null): array
    {
        $result = ['html' => null, 'plain' => null, 'attachments' => [], 'inline' => []];

        if ($msgno === null) {
            if (!$this->openFolder($path)) {
                return $result;
            }

            $msgno = imap_msgno($this->connection, $uid);
            if ($msgno === 0) {
                return $result;
            }
        }

        $structure = imap_fetchstructure($this->connection, $msgno);
        if ($structure === false) {
            return $result;
        }

        $this->parseStructure($msgno, $structure, '', $result);

        // Rewrite inline cid: references in HTML to point at the attachment
        // endpoint so embedded images render in the browser.
        if ($result['html'] !== null && $result['inline'] !== []) {
            $result['html'] = $this->rewriteCidReferences($result['html'], $result['inline'], $path, $uid);
        }

        return $result;
    }

    /**
     * @param array<string, string> $inline contentId => part section
     */
    private function rewriteCidReferences(string $html, array $inline, string $path, int $uid): string
    {
        foreach ($inline as $cid => $section) {
            if ($cid === '') {
                continue;
            }

            $src = url('attachment')
                . '?folder=' . encode_folder_path($path)
                . '&uid=' . $uid
                . '&part=' . rawurlencode($section)
                . '&disposition=inline';

            $html = str_ireplace(
                ['cid:' . $cid, 'cid:%3C' . $cid . '%3E'],
                $src,
                $html
            );
        }

        return $html;
    }

    /**
     * @return array{content: string, filename: string, mime: string}|null
     */
    public function getAttachment(string $path, int $uid, string $partId): ?array
    {
        if (!$this->openFolder($path)) {
            return null;
        }

        $msgno = imap_msgno($this->connection, $uid);
        if ($msgno === 0) {
            return null;
        }

        $structure = imap_fetchstructure($this->connection, $msgno);
        if ($structure === false) {
            return null;
        }

        $part = $this->findPart($structure, $partId);
        if ($part === null) {
            return null;
        }

        $section = $partId === '0' ? '1' : $partId;
        $body = imap_fetchbody($this->connection, $msgno, $section);
        if ($body === false) {
            return null;
        }

        $content = $this->decodePartBody($body, $part->encoding ?? 0);
        $filename = $this->getPartFilename($part) ?? 'attachment';

        return [
            'content' => $content,
            'filename' => $filename,
            'mime' => $this->getPartMime($part),
        ];
    }

    public function moveMessage(string $fromPath, int $uid, string $toPath): bool
    {
        $fromPath = FolderCache::resolvePath($fromPath);
        $toPath = employee_messages_imap_path(FolderCache::resolvePath($toPath));

        if (!$this->openFolder($fromPath)) {
            return false;
        }

        $wasSeen = $this->isSeen($fromPath, $uid);

        imap_errors();
        imap_alerts();

        $mailboxString = $this->getMailboxString();
        $target = $mailboxString . $this->encodeFolderPath($toPath);

        $moved = @imap_mail_move($this->connection, (string) $uid, $target, CP_UID);

        if (!$moved) {
            imap_errors();
            imap_alerts();
            $moved = $this->moveMessageCopyDelete($fromPath, $uid, $toPath, $wasSeen);
        } else {
            imap_expunge($this->connection);
            if ($wasSeen) {
                $this->markLastMessageSeen($toPath);
            }
        }

        if (!$moved) {
            $errors = imap_errors() ?: [];
            $this->lastError = 'Failed to move message: ' . implode('; ', $errors);
            app_log($this->lastError . ' (' . $fromPath . ' → ' . $toPath . ')');

            return false;
        }

        return true;
    }

    public function deleteMessage(string $folderPath, int $uid): bool
    {
        if (!$this->openFolder($folderPath)) {
            return false;
        }

        if (!@imap_delete($this->connection, (string) $uid, FT_UID)) {
            $errors = imap_errors() ?: [];
            $this->lastError = 'Failed to delete message: ' . implode('; ', $errors);
            app_log($this->lastError);

            return false;
        }

        imap_expunge($this->connection);

        if (imap_msgno($this->connection, $uid) !== 0) {
            $this->lastError = 'Message still present after delete.';
            app_log($this->lastError);

            return false;
        }

        return true;
    }

    /**
     * Delete multiple messages in one folder (single expunge).
     *
     * @param list<int> $uids
     * @return array{deleted: int, errors: int, uids: list<int>}
     */
    public function deleteMessages(string $folderPath, array $uids): array
    {
        $uids = array_values(array_unique(array_filter(array_map('intval', $uids), static fn (int $u): bool => $u > 0)));
        if ($uids === [] || !$this->openFolder($folderPath)) {
            return ['deleted' => 0, 'errors' => count($uids), 'uids' => []];
        }

        $deleted = [];
        $failed = [];

        foreach (array_chunk($uids, 100) as $chunk) {
            $list = implode(',', $chunk);
            if (@imap_delete($this->connection, $list, FT_UID)) {
                foreach ($chunk as $uid) {
                    $deleted[] = $uid;
                }
                continue;
            }

            imap_errors();

            foreach ($chunk as $uid) {
                if (@imap_delete($this->connection, (string) $uid, FT_UID)) {
                    $deleted[] = $uid;
                } else {
                    imap_errors();
                    $failed[] = $uid;
                }
            }
        }

        if ($deleted !== []) {
            imap_expunge($this->connection);
        }

        return [
            'deleted' => count($deleted),
            'errors' => count($failed),
            'uids' => $deleted,
        ];
    }

    /**
     * Move multiple messages to another folder (batched, single expunge).
     *
     * @param list<int> $uids
     * @return array{moved: int, errors: int, uids: list<int>}
     */
    public function moveMessages(string $fromPath, array $uids, string $toPath): array
    {
        $fromPath = FolderCache::resolvePath(employee_messages_imap_path($fromPath));
        $toPath = FolderCache::resolvePath(employee_messages_imap_path($toPath));
        $uids = array_values(array_unique(array_filter(array_map('intval', $uids), static fn (int $u): bool => $u > 0)));
        if ($uids === [] || !$this->openFolder($fromPath)) {
            return ['moved' => 0, 'errors' => count($uids), 'uids' => []];
        }

        $mailboxString = $this->getMailboxString();
        $target = $mailboxString . $this->encodeFolderPath($toPath);
        $moved = [];
        $failed = [];

        foreach (array_chunk($uids, 50) as $chunk) {
            $list = implode(',', $chunk);
            if (@imap_mail_move($this->connection, $list, $target, CP_UID)) {
                foreach ($chunk as $uid) {
                    $moved[] = $uid;
                }
                continue;
            }

            imap_errors();

            foreach ($chunk as $uid) {
                imap_errors();
                if (@imap_mail_move($this->connection, (string) $uid, $target, CP_UID)) {
                    $moved[] = $uid;
                    continue;
                }

                imap_errors();
                $wasSeen = $this->isSeen($fromPath, $uid);
                if ($this->moveMessageCopyDelete($fromPath, $uid, $toPath, $wasSeen)) {
                    $moved[] = $uid;
                } else {
                    $failed[] = $uid;
                }
            }
        }

        if ($moved !== []) {
            imap_expunge($this->connection);
        }

        return [
            'moved' => count($moved),
            'errors' => count($failed),
            'uids' => $moved,
        ];
    }

    public function isSeen(string $path, int $uid): bool
    {
        if (!$this->openFolder($path)) {
            return false;
        }

        $overview = imap_fetch_overview($this->connection, (string) $uid, FT_UID);

        return $overview !== false && !empty($overview[0]->seen);
    }

    public function markSeen(string $path, int $uid): void
    {
        if (!$this->openFolder($path)) {
            return;
        }

        imap_setflag_full($this->connection, (string) $uid, '\\Seen', ST_UID);
    }

    public function markUnseen(string $path, int $uid): void
    {
        if (!$this->openFolder($path)) {
            return;
        }

        imap_clearflag_full($this->connection, (string) $uid, '\\Seen', ST_UID);
    }

    public function markFlagged(string $path, int $uid): void
    {
        if (!$this->openFolder($path)) {
            return;
        }

        imap_setflag_full($this->connection, (string) $uid, '\\Flagged', ST_UID);
    }

    public function markUnflagged(string $path, int $uid): void
    {
        if (!$this->openFolder($path)) {
            return;
        }

        imap_clearflag_full($this->connection, (string) $uid, '\\Flagged', ST_UID);
    }

    /**
     * @param list<int> $uids
     */
    public function markSeenBulk(string $path, array $uids): void
    {
        if ($uids === [] || !$this->openFolder($path)) {
            return;
        }

        imap_setflag_full($this->connection, implode(',', $uids), '\\Seen', ST_UID);
    }

    /**
     * @param list<int> $uids
     */
    public function markUnseenBulk(string $path, array $uids): void
    {
        if ($uids === [] || !$this->openFolder($path)) {
            return;
        }

        imap_clearflag_full($this->connection, implode(',', $uids), '\\Seen', ST_UID);
    }

    /**
     * @param list<int> $uids
     */
    public function markFlaggedBulk(string $path, array $uids): void
    {
        if ($uids === [] || !$this->openFolder($path)) {
            return;
        }

        imap_setflag_full($this->connection, implode(',', $uids), '\\Flagged', ST_UID);
    }

    /**
     * @param list<int> $uids
     */
    public function markUnflaggedBulk(string $path, array $uids): void
    {
        if ($uids === [] || !$this->openFolder($path)) {
            return;
        }

        imap_clearflag_full($this->connection, implode(',', $uids), '\\Flagged', ST_UID);
    }

    public function messageExists(string $path, int $uid): bool
    {
        if (!$this->openFolder($path)) {
            return false;
        }

        return imap_msgno($this->connection, $uid) !== 0;
    }

    /**
     * @param list<string> $paths
     * @return array<string, int>
     */
    public function getFolderUnreadCounts(array $paths): array
    {
        if (!$this->ensureConnected()) {
            return [];
        }

        $mailboxString = $this->getMailboxString();
        $counts = [];
        $statusTargets = [];

        foreach ($paths as $path) {
            if (employee_is_mailbox_container($path)) {
                $counts[$path] = 0;
                continue;
            }

            $statusPath = FolderCache::resolvePath(employee_messages_imap_path($path));
            if ($statusPath === '') {
                $counts[$path] = 0;
                continue;
            }

            $statusTargets[$statusPath][$path] = true;
        }

        foreach ($statusTargets as $statusPath => $originalPaths) {
            $status = @imap_status(
                $this->connection,
                $mailboxString . $this->encodeFolderPath($statusPath),
                SA_UNSEEN
            );
            $unseen = $status !== false ? (int) ($status->unseen ?? 0) : 0;

            foreach (array_keys($originalPaths) as $originalPath) {
                $counts[$originalPath] = $unseen;
            }
        }

        return $counts;
    }

    /**
     * Sidebar badge source from IMAP: unread for most folders, total messages for Drafts.
     *
     * @param list<string> $paths
     * @return array<string, int>
     */
    public function getFolderBadgeCounts(array $paths): array
    {
        if (!$this->ensureConnected()) {
            return [];
        }

        $paths = array_values(array_unique(array_filter($paths)));
        if ($paths === []) {
            return [];
        }

        $counts = $this->getFolderUnreadCounts($paths);
        $draftPaths = array_values(array_filter(
            $paths,
            static fn (string $path): bool => folder_uses_draft_badge($path)
        ));

        if ($draftPaths === []) {
            return $counts;
        }

        foreach ($this->getFolderMessageCounts($draftPaths) as $path => $total) {
            $counts[$path] = $total;
        }

        return $counts;
    }

    /**
     * @param list<string> $paths
     * @return array<string, int>
     */
    public function getFolderMessageCounts(array $paths): array
    {
        if (!$this->ensureConnected()) {
            return [];
        }

        $mailboxString = $this->getMailboxString();
        $counts = [];

        foreach ($paths as $path) {
            $status = @imap_status(
                $this->connection,
                $mailboxString . $this->encodeFolderPath($path),
                SA_MESSAGES
            );
            $counts[$path] = $status !== false ? (int) ($status->messages ?? 0) : 0;
        }

        return $counts;
    }

    /**
     * @return array{messages: list<array{uid: int, from: string, subject: string, date: string, seen: bool, size: int}>, total: int, page: int, per_page: int, total_pages: int}
     */
    public function searchMessages(string $path, string $query, int $page = 1, int $perPage = 50): array
    {
        $empty = [
            'messages' => [],
            'total' => 0,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => 0,
        ];

        if (!$this->openFolder($path) || trim($query) === '') {
            return $empty;
        }

        $uids = $this->searchUids($path, trim($query));

        if ($uids === []) {
            return $empty;
        }

        $uids = array_values(array_reverse($uids));
        $total = count($uids);
        $totalPages = (int) max(1, ceil($total / $perPage));
        $page = max(1, min($page, $totalPages));
        $offset = ($page - 1) * $perPage;
        $pageUids = array_slice($uids, $offset, $perPage);

        return [
            'messages' => $this->overviewForUids($pageUids),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
        ];
    }

    /**
     * Search across multiple folders and merge results by date.
     *
     * @param list<string> $folderPaths
     * @return array{messages: list<array<string, mixed>>, total: int, page: int, per_page: int, total_pages: int}
     */
    public function searchAllMessages(array $folderPaths, string $query, int $page = 1, int $perPage = 50): array
    {
        $empty = [
            'messages' => [],
            'total' => 0,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => 0,
        ];

        $query = trim($query);
        if ($query === '' || $folderPaths === []) {
            return $empty;
        }

        $hits = [];
        foreach ($folderPaths as $path) {
            if ($path === '') {
                continue;
            }
            if (!$this->openFolder($path)) {
                continue;
            }

            $uids = $this->searchUids($path, $query);
            if ($uids === []) {
                continue;
            }

            foreach (array_chunk($uids, 50) as $chunk) {
                $overview = @imap_fetch_overview($this->connection, implode(',', $chunk), FT_UID);
                if (!is_array($overview)) {
                    continue;
                }

                foreach ($overview as $row) {
                    $uid = (int) ($row->uid ?? 0);
                    if ($uid <= 0 || mail_is_uid_removed($path, $uid)) {
                        continue;
                    }

                    $hits[] = [
                        'folder' => $path,
                        'uid' => $uid,
                        'date' => strtotime((string) ($row->date ?? '')) ?: 0,
                    ];
                }
            }
        }

        if ($hits === []) {
            return $empty;
        }

        usort($hits, static fn (array $a, array $b): int => $b['date'] <=> $a['date'] ?: $b['uid'] <=> $a['uid']);

        $total = count($hits);
        $totalPages = (int) max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $totalPages));
        $offset = ($page - 1) * $perPage;
        $pageHits = array_slice($hits, $offset, $perPage);

        $byFolder = [];
        foreach ($pageHits as $hit) {
            $byFolder[$hit['folder']][] = $hit['uid'];
        }

        $messages = [];
        foreach ($byFolder as $folderPath => $uids) {
            if (!$this->openFolder($folderPath)) {
                continue;
            }

            foreach ($this->overviewForUids($uids) as $msg) {
                $uid = (int) ($msg['uid'] ?? 0);
                if ($uid <= 0) {
                    continue;
                }

                $msg['_folder_path'] = $folderPath;
                $filtered = employee_filter_correspondent_list($folderPath, [
                    'messages' => [$msg],
                    'total' => 1,
                    'page' => 1,
                    'per_page' => 1,
                    'total_pages' => 1,
                ]);
                $filtered = employee_filter_own_inbox_list($folderPath, $filtered);
                if (($filtered['messages'][0] ?? null) !== null) {
                    $messages[] = $filtered['messages'][0];
                }
            }
        }

        usort($messages, static fn (array $a, array $b): int => strtotime((string) ($b['date'] ?? '')) <=> strtotime((string) ($a['date'] ?? '')));

        return [
            'messages' => $messages,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
        ];
    }

    /**
     * @return list<int>
     */
    private function searchUids(string $path, string $query): array
    {
        $escaped = str_replace(['"', '\\'], ['', ''], $query);
        $uidSet = [];

        $criteriaList = [
            'SUBJECT "' . $escaped . '"',
            'FROM "' . $escaped . '"',
            'TEXT "' . $escaped . '"',
        ];

        foreach ($criteriaList as $criteria) {
            $result = @imap_search($this->connection, $criteria, SE_UID, 'UTF-8');
            if (is_array($result)) {
                foreach ($result as $uid) {
                    $uidSet[(int) $uid] = true;
                }
            }
        }

        if ($uidSet !== []) {
            return array_keys($uidSet);
        }

        return $this->searchUidsLocal($path, $query);
    }

    /**
     * @return list<int>
     */
    private function searchUidsLocal(string $path, string $query): array
    {
        $needle = strtolower($query);
        $total = imap_num_msg($this->connection) ?: 0;
        if ($total === 0) {
            return [];
        }

        $overview = imap_fetch_overview($this->connection, "1:{$total}");
        if ($overview === false) {
            return [];
        }

        $uids = [];
        foreach ($overview as $row) {
            $from = isset($row->from) ? strtolower($this->decodeMimeHeader($row->from)) : '';
            $subject = isset($row->subject) ? strtolower($this->decodeMimeHeader($row->subject)) : '';
            if (str_contains($from, $needle) || str_contains($subject, $needle)) {
                $uids[] = (int) ($row->uid ?? 0);
            }
        }

        return array_values(array_filter($uids, fn ($u) => $u > 0));
    }

    /**
     * @param list<int> $uids
     * @return list<array{uid: int, from: string, subject: string, date: string, seen: bool, size: int}>
     */
    private function overviewForUids(array $uids): array
    {
        $messages = [];
        foreach ($uids as $uid) {
            $overview = imap_fetch_overview($this->connection, (string) $uid, FT_UID);
            if ($overview === false || !isset($overview[0])) {
                continue;
            }
            $row = $overview[0];
            $messages[] = [
                'uid' => (int) $uid,
                'from' => isset($row->from) ? $this->decodeMimeHeader($row->from) : '',
                'subject' => isset($row->subject) ? $this->decodeMimeHeader($row->subject) : '(no subject)',
                'date' => $row->date ?? '',
                'seen' => (bool) ($row->seen ?? false),
                'flagged' => (bool) ($row->flagged ?? false),
                'size' => (int) ($row->size ?? 0),
                'has_attachment' => false,
            ];
        }

        return $messages;
    }

    public function uidHasAttachment(int $uid): bool
    {
        if ($uid <= 0 || $this->connection === null) {
            return false;
        }

        $structure = @imap_fetchstructure($this->connection, $uid, FT_UID);
        if ($structure === false) {
            return false;
        }

        return $this->structureHasAttachment($structure);
    }

    /**
     * Check attachment presence for a page of UIDs (deferred from list load).
     *
     * @param list<int> $uids
     * @return array<int, bool>
     */
    public function batchHasAttachments(array $uids): array
    {
        $result = [];
        foreach ($uids as $uid) {
            $uid = (int) $uid;
            if ($uid <= 0) {
                continue;
            }
            $result[$uid] = $this->uidHasAttachment($uid);
        }

        return $result;
    }

    private function structureHasAttachment(object $structure, string $partId = ''): bool
    {
        if (isset($structure->parts) && is_array($structure->parts)) {
            if (($structure->type ?? -1) === TYPEMESSAGE && $partId !== '') {
                return true;
            }

            foreach ($structure->parts as $index => $subPart) {
                $id = $partId === '' ? (string) ($index + 1) : $partId . '.' . ($index + 1);
                if ($this->structureHasAttachment($subPart, $id)) {
                    return true;
                }
            }

            return false;
        }

        $filename = $this->getPartFilename($structure);
        $disposition = strtolower($structure->disposition ?? '');

        if ($disposition === 'attachment') {
            return true;
        }

        return $filename !== null && $disposition !== 'inline';
    }

    public function appendMessage(string $folderPath, string $rawMessage, ?string $flags = null): bool
    {
        if (!$this->ensureConnected()) {
            return false;
        }

        $folderPath = employee_messages_imap_path(FolderCache::resolvePath($folderPath));
        $mailbox = $this->getMailboxString() . $this->encodeFolderPath($folderPath);
        $rawMessage = $this->normalizeRawMessage($rawMessage);

        $appended = $flags !== null && $flags !== ''
            ? @imap_append($this->connection, $mailbox, $rawMessage, $flags)
            : @imap_append($this->connection, $mailbox, $rawMessage);

        if ($appended) {
            return true;
        }

        $errors = imap_errors() ?: [];
        $this->lastError = 'Failed to save draft: ' . implode('; ', $errors);

        return false;
    }

    /**
     * Copy a message to another folder on the same mailbox (UID preserved).
     */
    public function copyMessageByUid(string $fromPath, int $uid, string $toPath): bool
    {
        if (!$this->openFolder($fromPath)) {
            return false;
        }

        $target = $this->getMailboxString() . $this->encodeFolderPath($toPath);
        if (@imap_mail_copy($this->connection, (string) $uid, $target, CP_UID)) {
            return true;
        }

        $errors = imap_errors() ?: [];
        $this->lastError = 'Failed to copy message: ' . implode('; ', $errors);
        app_log($this->lastError);

        return false;
    }

    /**
     * Copy to another folder; fall back to append when the host disallows COPY.
     */
    public function copyMessageWithFallback(string $fromPath, int $uid, string $toPath): bool
    {
        if ($this->copyMessageByUid($fromPath, $uid, $toPath)) {
            return true;
        }

        $raw = $this->fetchRawMessage($fromPath, $uid);
        if ($raw === null) {
            return false;
        }

        if ($this->appendMessage($toPath, $raw)) {
            return true;
        }

        app_log('Copy fallback append failed for ' . $toPath . ': ' . $this->getLastError());

        return false;
    }

    private function normalizeRawMessage(string $raw): string
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);

        return str_replace("\n", "\r\n", $raw);
    }

    /**
     * Fetch the full RFC822 source for a message (headers + body, \\Seen unchanged).
     */
    public function fetchRawMessage(string $folderPath, int $uid): ?string
    {
        if (!$this->openFolder($folderPath)) {
            return null;
        }

        $msgno = imap_msgno($this->connection, $uid);
        if ($msgno === 0) {
            return null;
        }

        $header = @imap_fetchheader($this->connection, $msgno);
        $body = @imap_body($this->connection, $msgno, FT_PEEK);
        if ($header === false || $body === false) {
            return null;
        }

        return $header . $body;
    }

    /**
     * @return list<int>
     */
    public function getUnseenUids(string $path, int $limit = 500): array
    {
        if (!$this->openFolder($path)) {
            return [];
        }

        $result = @imap_search($this->connection, 'UNSEEN', SE_UID);
        if (!is_array($result)) {
            return [];
        }

        $uids = array_values(array_map('intval', $result));
        if (count($uids) > $limit) {
            $uids = array_slice($uids, 0, $limit);
        }

        return $uids;
    }

    /**
     * Some hosts duplicate just-sent mail into INBOX (or a working folder) as
     * unread. Mark those as read so sidebar badges stay accurate.
     *
     * @param list<string> $paths
     * @param string|list<string> $fromEmails
     */
    public function clearRecentSelfSentCopies(array $paths, string|array $fromEmails, int $limit = 12): void
    {
        foreach (array_values(array_unique(array_filter($paths))) as $path) {
            $this->suppressInboundEchoOfSentMessage($path, $fromEmails, $limit);
        }
    }

    /**
     * Outbound mail often echoes into the filter inbox as an unread copy. After
     * send/reply the filter routes real mail to employee folders — this clears
     * the leftover inbox echo so Inbox badges stay at zero.
     *
     * @param string|list<string> $fromEmails
     */
    public function suppressInboundEchoOfSentMessage(
        string $inboxPath,
        string|array $fromEmails,
        int $limit = 20,
        ?string $sentMessageId = null,
        bool $includeSharedMailbox = true,
    ): void {
        if ($inboxPath === '' || !$this->openFolder($inboxPath)) {
            return;
        }

        $normalizedId = normalize_message_id($sentMessageId);

        $needles = [];
        foreach ((array) $fromEmails as $email) {
            $email = strtolower(trim($email));
            if ($email !== '') {
                $needles[] = $email;
            }
        }

        if ($includeSharedMailbox) {
            $mailbox = strtolower(trim((string) (config('mail')['mailbox_email'] ?? '')));
            if ($mailbox !== '' && !in_array($mailbox, $needles, true)) {
                $needles[] = $mailbox;
            }
        }

        if ($needles === [] && $normalizedId === '') {
            return;
        }

        foreach (array_reverse($this->getFolderUids($inboxPath, $limit)) as $uid) {
            $uid = (int) $uid;
            if ($this->isSeen($inboxPath, $uid)) {
                continue;
            }

            $headers = $this->fetchFilterHeaders($inboxPath, $uid);
            if ($headers === null) {
                continue;
            }

            $msgId = normalize_message_id($headers['message_id'] ?? '');
            $fromMatch = false;
            foreach ($needles as $needle) {
                if (str_contains(strtolower($headers['from'] ?? ''), $needle)) {
                    $fromMatch = true;
                    break;
                }
            }

            $idMatch = $normalizedId !== '' && $msgId !== '' && $msgId === $normalizedId;

            if (!$idMatch && !$fromMatch) {
                continue;
            }

            if ($idMatch && $this->deleteMessage($inboxPath, $uid)) {
                MailCacheService::removeMessage($inboxPath, $uid);
                continue;
            }

            $this->markSeen($inboxPath, $uid);
            MailCacheService::updateIndexSeen($inboxPath, $uid, true);
        }
    }

    /**
     * @deprecated Use clearRecentSelfSentCopies()
     */
    public function clearRecentSentCopiesFromInbox(string $inboxPath, string $fromEmail): void
    {
        $this->clearRecentSelfSentCopies([$inboxPath], $fromEmail);
    }

    /**
     * Remove duplicate deliveries (same Message-ID or same from/subject/minute).
     *
     * @return int Number of messages deleted
     */
    public function removeDuplicateDeliveries(string $path, int $limit = 30): int
    {
        if (!$this->openFolder($path)) {
            return 0;
        }

        $uids = $this->getFolderUids($path, $limit);
        if ($uids === []) {
            return 0;
        }

        $byMessageId = [];
        $byFingerprint = [];

        foreach ($uids as $uid) {
            $uid = (int) $uid;
            $headers = $this->fetchFilterHeaders($path, $uid);
            if ($headers === null) {
                continue;
            }

            $seen = !empty($headers['seen']);
            $entry = ['uid' => $uid, 'seen' => $seen];

            $messageId = strtolower(trim($headers['message_id'] ?? ''));
            if ($messageId !== '') {
                $byMessageId[$messageId][] = $entry;
            }

            $from = strtolower(trim($headers['from'] ?? ''));
            $subject = strtolower(trim($headers['subject'] ?? ''));
            $dateKey = $this->headerMinuteKey($headers['date'] ?? '');
            if ($from !== '' && $subject !== '' && $dateKey !== '') {
                $byFingerprint[$from . '|' . $subject . '|' . $dateKey][] = $entry;
            }
        }

        $toDelete = [];
        foreach ([$byMessageId, $byFingerprint] as $groups) {
            foreach ($groups as $entries) {
                if (count($entries) < 2) {
                    continue;
                }
                $toDelete = array_merge($toDelete, $this->duplicateUidsToRemove($entries));
            }
        }

        $removed = 0;
        foreach (array_unique($toDelete) as $uid) {
            if ($this->deleteMessage($path, $uid)) {
                MailCacheService::removeMessage($path, $uid);
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * @param list<array{uid: int, seen: bool}> $entries
     * @return list<int>
     */
    private function duplicateUidsToRemove(array $entries): array
    {
        usort($entries, static function (array $a, array $b): int {
            if ($a['seen'] !== $b['seen']) {
                return $b['seen'] <=> $a['seen'];
            }

            return $a['uid'] <=> $b['uid'];
        });

        array_shift($entries);

        return array_map(static fn (array $e): int => $e['uid'], $entries);
    }

    private function headerMinuteKey(string $date): string
    {
        if ($date === '') {
            return '';
        }

        $ts = strtotime($date);

        return $ts !== false ? date('Y-m-d H:i', $ts) : '';
    }

    /**
     * @return list<int>
     */
    public function getFolderUids(string $path, int $limit = 400): array
    {
        if (!$this->openFolder($path)) {
            return [];
        }

        $total = imap_num_msg($this->connection) ?: 0;
        if ($total === 0) {
            return [];
        }

        $start = max(1, $total - $limit + 1);
        $overview = imap_fetch_overview($this->connection, "$start:$total");
        if ($overview === false) {
            return [];
        }

        $uids = [];
        foreach (array_reverse($overview) as $row) {
            if (isset($row->uid)) {
                $uids[] = (int) $row->uid;
            }
        }

        return $uids;
    }

    /**
     * All message UIDs in a folder, optionally limited to a search query.
     *
     * @return list<int>
     */
    public function allMessageUids(string $path, string $searchQuery = ''): array
    {
        if (!$this->openFolder($path)) {
            return [];
        }

        if (trim($searchQuery) !== '') {
            return $this->searchUids($path, trim($searchQuery));
        }

        $result = @imap_search($this->connection, 'ALL', SE_UID);
        if (is_array($result) && $result !== []) {
            $uids = array_values(array_map('intval', $result));
            rsort($uids, SORT_NUMERIC);

            return $uids;
        }

        imap_errors();

        $total = imap_num_msg($this->connection) ?: 0;
        if ($total === 0) {
            return [];
        }

        $overview = imap_fetch_overview($this->connection, "1:$total");
        if ($overview === false) {
            return [];
        }

        $uids = [];
        foreach (array_reverse($overview) as $row) {
            if (isset($row->uid)) {
                $uids[] = (int) $row->uid;
            }
        }

        return $uids;
    }

    /**
     * @return array<string, string|null>|null
     */
    public function fetchFilterHeaders(string $path, int $uid): ?array
    {
        if (!$this->openFolder($path)) {
            return null;
        }

        $msgno = imap_msgno($this->connection, $uid);
        if ($msgno === 0) {
            return null;
        }

        $header = imap_headerinfo($this->connection, $msgno);
        if ($header === false) {
            return null;
        }

        $rawHeader = imap_fetchheader($this->connection, $msgno) ?: '';

        $from = '';
        if (isset($header->from[0])) {
            $from = $header->from[0]->mailbox . '@' . $header->from[0]->host;
        }

        // Capture every recipient (To + Cc), not just the first, so filter rules
        // still match when a message is addressed to several people.
        $to = trim(
            $this->joinAddresses($header->to ?? []) . ' ' . $this->joinAddresses($header->cc ?? [])
        );

        $bcc = trim($this->joinAddresses($header->bcc ?? []));
        if ($bcc === '') {
            $bcc = trim((string) ($this->extractHeaderValue($rawHeader, 'Bcc') ?? ''));
        }

        $unseen = (($header->Unseen ?? '') === 'U') || (($header->Recent ?? '') === 'N');

        return [
            'from' => $from,
            'to' => $to,
            'bcc' => $bcc,
            'subject' => isset($header->subject) ? $this->decodeMimeHeader($header->subject) : '',
            'date' => $header->date ?? '',
            'seen' => !$unseen,
            'delivered_to' => $this->extractHeaderValue($rawHeader, 'Delivered-To'),
            'x_original_to' => $this->extractHeaderValue($rawHeader, 'X-Original-To'),
            'envelope_recipients' => $this->collectEnvelopeRecipients($rawHeader),
            'message_id' => $this->extractHeaderValue($rawHeader, 'Message-ID'),
        ];
    }

    /**
     * @param array<int, object> $addresses
     */
    private function joinAddresses(array $addresses): string
    {
        $emails = [];
        foreach ($addresses as $addr) {
            if (isset($addr->mailbox, $addr->host) && $addr->host !== '') {
                $emails[] = $addr->mailbox . '@' . $addr->host;
            }
        }

        return implode(' ', $emails);
    }

    public function fetchFilterBody(string $path, int $uid): string
    {
        $body = $this->fetchBody($path, $uid);

        return $body['plain'] ?? strip_tags($body['html'] ?? '');
    }

    public function createFolder(string $path): bool
    {
        if (!$this->ensureConnected()) {
            return false;
        }

        $mailbox = $this->getMailboxString() . $this->encodeFolderPath($path);

        if (@imap_createmailbox($this->connection, $mailbox)) {
            // Subscribe so the folder is listed by clients that honor LSUB
            // (e.g. Apple Mail) — otherwise a created folder appears "missing".
            @imap_subscribe($this->connection, $mailbox);

            return true;
        }

        $errors = imap_errors() ?: [];
        $this->lastError = 'Failed to create folder: ' . implode('; ', $errors);
        app_log($this->lastError);

        return false;
    }

    /**
     * Create a folder and every missing ancestor (e.g. INBOX.F1 before INBOX.F1.Sub).
     */
    public function ensureFolderPath(string $path): bool
    {
        if (!$this->ensureConnected()) {
            return false;
        }

        $path = trim($path);
        if ($path === '') {
            $this->lastError = 'Folder path is empty.';

            return false;
        }

        if ($this->folderExistsOnServer($path)) {
            return true;
        }

        $delimiter = '.';
        $segments = explode($delimiter, $path);
        if (count($segments) < 2) {
            $this->lastError = 'Folder path must be under INBOX.';

            return false;
        }

        $built = $segments[0];
        for ($i = 1, $count = count($segments); $i < $count; $i++) {
            $built = $built . $delimiter . $segments[$i];
            if ($this->folderExistsOnServer($built)) {
                continue;
            }
            if (!$this->createFolder($built)) {
                return false;
            }
        }

        return true;
    }

    public function renameFolder(string $oldPath, string $newPath): bool
    {
        if (!$this->ensureConnected()) {
            return false;
        }

        if ($oldPath === $newPath) {
            return true;
        }

        if (!$this->folderExistsOnServer($oldPath)) {
            $this->lastError = 'Source folder does not exist on the mail server.';

            return false;
        }

        if ($this->folderExistsOnServer($newPath)) {
            $this->lastError = 'A folder with that name already exists on the mail server.';

            return false;
        }

        $oldMailbox = $this->getMailboxString() . $this->encodeFolderPath($oldPath);
        $newMailbox = $this->getMailboxString() . $this->encodeFolderPath($newPath);

        if (@imap_renamemailbox($this->connection, $oldMailbox, $newMailbox)) {
            // Keep the renamed folder subscribed so it stays listed in clients.
            @imap_unsubscribe($this->connection, $oldMailbox);
            @imap_subscribe($this->connection, $newMailbox);

            return true;
        }

        $errors = imap_errors() ?: [];
        $this->lastError = 'Failed to rename folder: ' . implode('; ', $errors);
        app_log($this->lastError);

        return false;
    }

    /**
     * Remove a mailbox folder from the IMAP server. Some servers require the
     * folder to be empty; failures are logged but callers may still drop the
     * DB registry row.
     */
    public function deleteFolder(string $path): bool
    {
        if (!$this->ensureConnected()) {
            return false;
        }

        if (!$this->folderExistsOnServer($path)) {
            return true;
        }

        if (!$this->emptyFolder($path)) {
            app_log('IMAP folder empty failed for ' . $path . ': ' . $this->getLastError());
        }

        $mailbox = $this->getMailboxString() . $this->encodeFolderPath($path);

        if (@imap_deletemailbox($this->connection, $mailbox)) {
            return true;
        }

        $errors = imap_errors() ?: [];
        $this->lastError = 'Failed to delete folder: ' . implode('; ', $errors);
        app_log($this->lastError);

        return false;
    }

    /**
     * Delete every message in a folder (required by some servers before imap_deletemailbox).
     */
    public function emptyFolder(string $path): bool
    {
        if (!$this->ensureConnected()) {
            return false;
        }

        if (!$this->folderExistsOnServer($path)) {
            return true;
        }

        for ($pass = 0; $pass < 50; $pass++) {
            $uids = $this->allMessageUids($path);
            if ($uids === []) {
                return true;
            }

            $result = $this->deleteMessages($path, $uids);
            if ($result['deleted'] === 0) {
                return false;
            }
        }

        return $this->allMessageUids($path) === [];
    }

    public function folderExistsOnServer(string $path): bool
    {
        $resolved = FolderCache::resolvePath($path);

        foreach ($this->listFolders() as $folder) {
            $serverPath = (string) ($folder['path'] ?? '');
            if ($serverPath === $resolved || strcasecmp($serverPath, $path) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string|null>
     */
    public function fetchMessageHeaders(string $path, int $msgNumber): array
    {
        if (!$this->openFolder($path)) {
            return [];
        }

        $header = imap_headerinfo($this->connection, $msgNumber);

        if ($header === false) {
            return [];
        }

        $rawHeader = imap_fetchheader($this->connection, $msgNumber) ?: '';

        return [
            'uid' => (string) imap_uid($this->connection, $msgNumber),
            'from' => isset($header->from[0])
                ? ($header->from[0]->mailbox . '@' . $header->from[0]->host)
                : null,
            'to' => isset($header->to[0])
                ? ($header->to[0]->mailbox . '@' . $header->to[0]->host)
                : null,
            'subject' => isset($header->subject) ? $this->decodeMimeHeader($header->subject) : null,
            'date' => $header->date ?? null,
            'delivered_to' => $this->extractHeaderValue($rawHeader, 'Delivered-To')
                ?? $this->extractHeaderValue($rawHeader, 'X-Original-To'),
        ];
    }

    public function disconnect(): void
    {
        // The connection is shared across instances for the whole request, so
        // detach only this instance's reference. The real close happens at
        // shutdown via closeShared().
        $this->connection = null;
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    private function moveMessageCopyDelete(string $fromPath, int $uid, string $toPath, bool $wasSeen): bool
    {
        if (!$this->openFolder($fromPath)) {
            return false;
        }

        $msgno = imap_msgno($this->connection, $uid);
        if ($msgno === 0) {
            return false;
        }

        // Fetch the complete raw message (full header + raw MIME body) without
        // altering the \Seen flag, so the copied message keeps every part,
        // boundary and encoding intact.
        $header = imap_fetchheader($this->connection, $msgno);
        $body = imap_body($this->connection, $msgno, FT_PEEK);
        if ($header === false || $body === false) {
            return false;
        }

        $raw = $header . $body;
        $target = $this->getMailboxString() . $this->encodeFolderPath($toPath);

        imap_errors();
        imap_alerts();

        if (!@imap_append($this->connection, $target, $raw)) {
            $errors = imap_errors() ?: [];
            $this->lastError = 'Failed to append message to ' . $toPath . ': ' . implode('; ', $errors);
            app_log($this->lastError);

            return false;
        }

        if (!$this->openFolder($fromPath)) {
            return false;
        }

        if (!imap_delete($this->connection, (string) $uid, FT_UID)) {
            $this->lastError = 'Failed to delete message from source folder after copy.';

            return false;
        }

        imap_expunge($this->connection);

        if (imap_msgno($this->connection, $uid) !== 0) {
            $this->lastError = 'Message still present in source folder after move.';

            return false;
        }

        if ($wasSeen) {
            $this->markLastMessageSeen($toPath);
        }

        return true;
    }

    private function markLastMessageSeen(string $path): void
    {
        if (!$this->openFolder($path)) {
            return;
        }

        $total = imap_num_msg($this->connection) ?: 0;
        if ($total === 0) {
            return;
        }

        $overview = imap_fetch_overview($this->connection, (string) $total);
        if ($overview === false || empty($overview[0]->uid)) {
            return;
        }

        imap_setflag_full($this->connection, (string) (int) $overview[0]->uid, '\\Seen', ST_UID);
    }

    private function ensureConnected(): bool
    {
        if ($this->connection !== null) {
            return true;
        }

        return $this->connect();
    }

    private function getMailboxString(): string
    {
        $config = config('mail');
        $imap = $config['imap'];
        $flags = '/imap';

        if ($imap['encryption'] === 'ssl') {
            $flags .= '/ssl';
        }

        $flags .= $imap['validate_cert'] ? '/validate-cert' : '/novalidate-cert';

        return sprintf('{%s:%d%s}', $imap['host'], $imap['port'], $flags);
    }

    private function decodeFolderName(string $folder): string
    {
        $mailboxString = $this->getMailboxString();

        if (str_starts_with($folder, $mailboxString)) {
            $folder = substr($folder, strlen($mailboxString));
        }

        return imap_utf7_decode($folder);
    }

    private function encodeFolderPath(string $path): string
    {
        if ($path === 'INBOX' || $path === '') {
            return 'INBOX';
        }

        return imap_utf7_encode($path);
    }

    /**
     * @param array{html: string|null, plain: string|null, attachments: list<array{id: string, filename: string, size: int, mime: string}>} $result
     */
    private function parseStructure(int $msgno, object $structure, string $partId, array &$result): void
    {
        if (isset($structure->parts) && is_array($structure->parts)) {
            // Embedded messages (message/rfc822) are surfaced as a single
            // downloadable attachment rather than having their inner parts
            // flattened into the parent message body.
            if (($structure->type ?? -1) === TYPEMESSAGE && $partId !== '') {
                $result['attachments'][] = [
                    'id' => $partId,
                    'filename' => $this->getPartFilename($structure) ?? 'forwarded-message.eml',
                    'size' => 0,
                    'mime' => 'message/rfc822',
                ];
                return;
            }

            foreach ($structure->parts as $index => $subPart) {
                $subId = $partId === '' ? (string) ($index + 1) : $partId . '.' . ($index + 1);
                $this->parseStructure($msgno, $subPart, $subId, $result);
            }
            return;
        }

        $mime = $this->getPartMime($structure);
        $section = $partId === '' ? '1' : $partId;
        $filename = $this->getPartFilename($structure);
        $disposition = strtolower($structure->disposition ?? '');

        // Record any part carrying a Content-ID so HTML cid: references can be
        // rewritten to a viewable URL (inline images, etc.).
        $contentId = (!empty($structure->ifid) && isset($structure->id))
            ? trim((string) $structure->id, '<>')
            : null;
        if ($contentId !== null && $contentId !== '') {
            $result['inline'][$contentId] = $section;
        }

        $isInlineImage = $contentId !== null && str_starts_with($mime, 'image/');

        if ($isInlineImage) {
            return;
        }

        if ($filename !== null || $disposition === 'attachment') {
            $size = (int) ($structure->bytes ?? 0);
            if ($size <= 0 && isset($structure->size)) {
                $size = (int) $structure->size;
            }

            $result['attachments'][] = [
                'id' => $section,
                'filename' => $filename ?? 'attachment',
                'size' => $size,
                'mime' => $mime,
            ];
            return;
        }

        $body = imap_fetchbody($this->connection, $msgno, $section, FT_PEEK);
        if ($body === false) {
            return;
        }

        $decoded = $this->decodePartBody($body, $structure->encoding ?? 0);

        if (str_starts_with($mime, 'text/html') && $result['html'] === null) {
            $result['html'] = $this->toUtf8($decoded, $this->getPartCharset($structure));
        } elseif (str_starts_with($mime, 'text/plain') && $result['plain'] === null) {
            $result['plain'] = $this->toUtf8($decoded, $this->getPartCharset($structure));
        }
    }

    private function getPartCharset(object $part): ?string
    {
        if (isset($part->parameters) && is_array($part->parameters)) {
            foreach ($part->parameters as $param) {
                if (strtolower($param->attribute ?? '') === 'charset') {
                    return (string) $param->value;
                }
            }
        }

        return null;
    }

    /**
     * Convert a decoded text part to UTF-8 based on its declared charset.
     */
    private function toUtf8(string $text, ?string $charset): string
    {
        $charset = $charset !== null ? strtoupper(trim($charset)) : '';
        if ($text === '' || $charset === '' || in_array($charset, ['UTF-8', 'US-ASCII', 'ASCII'], true)) {
            return $text;
        }

        try {
            $converted = mb_convert_encoding($text, 'UTF-8', $charset);
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        } catch (\Throwable) {
            // Unknown/unsupported charset for mbstring — fall back to iconv.
        }

        $alt = @iconv($charset, 'UTF-8//TRANSLIT//IGNORE', $text);

        return $alt !== false ? $alt : $text;
    }

    private function findPart(object $structure, string $partId): ?object
    {
        if ($partId === '0' || $partId === '1') {
            return $structure;
        }

        $indices = explode('.', $partId);
        $current = $structure;

        foreach ($indices as $index) {
            $i = (int) $index - 1;
            if (!isset($current->parts[$i])) {
                return null;
            }
            $current = $current->parts[$i];
        }

        return $current;
    }

    private function getPartMime(object $part): string
    {
        $primary = $this->getPartTypeName($part->type ?? 0);
        $subtype = strtolower($part->subtype ?? 'octet-stream');

        return $primary . '/' . $subtype;
    }

    private function getPartTypeName(int $type): string
    {
        return match ($type) {
            TYPETEXT => 'text',
            TYPEMULTIPART => 'multipart',
            TYPEMESSAGE => 'message',
            TYPEAPPLICATION => 'application',
            TYPEAUDIO => 'audio',
            TYPEIMAGE => 'image',
            TYPEVIDEO => 'video',
            default => 'application',
        };
    }

    private function getPartFilename(object $part): ?string
    {
        if (isset($part->dparameters)) {
            foreach ($part->dparameters as $param) {
                if (strtolower($param->attribute ?? '') === 'filename') {
                    return $this->decodeMimeHeader($param->value);
                }
            }
        }

        if (isset($part->parameters)) {
            foreach ($part->parameters as $param) {
                if (strtolower($param->attribute ?? '') === 'name') {
                    return $this->decodeMimeHeader($param->value);
                }
            }
        }

        return null;
    }

    private function decodePartBody(string $body, int $encoding): string
    {
        return match ($encoding) {
            ENCBASE64 => $this->decodeBase64Body($body),
            ENCQUOTEDPRINTABLE => quoted_printable_decode($body),
            default => $body,
        };
    }

    private function decodeBase64Body(string $body): string
    {
        if (function_exists('imap_base64')) {
            $decoded = @imap_base64($body);
            if (is_string($decoded) && $decoded !== '') {
                return $decoded;
            }
        }

        $clean = preg_replace('/\s+/', '', $body) ?? $body;
        $decoded = base64_decode($clean, true);

        return $decoded !== false ? $decoded : $body;
    }

    private function decodeMimeHeader(string $value): string
    {
        $decoded = imap_mime_header_decode($value);
        if (!is_array($decoded)) {
            return $value;
        }

        $result = '';
        foreach ($decoded as $part) {
            $result .= $part->text;
        }

        return $result;
    }

    private function extractHeaderValue(string $rawHeader, string $headerName): ?string
    {
        $values = $this->extractAllHeaderValues($rawHeader, $headerName);

        return $values[0] ?? null;
    }

    /**
     * @return list<string>
     */
    private function extractAllHeaderValues(string $rawHeader, string $headerName): array
    {
        // Unfold folded headers first: per RFC 5322 a CRLF (or LF) followed by
        // whitespace is a continuation of the previous header line.
        $unfolded = preg_replace('/\r?\n[ \t]+/', ' ', $rawHeader) ?? $rawHeader;

        if (!preg_match_all('/^' . preg_quote($headerName, '/') . ':\s*(.+)$/im', $unfolded, $matches)) {
            return [];
        }

        $values = [];
        foreach ($matches[1] as $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    private function collectEnvelopeRecipients(string $rawHeader): string
    {
        $parts = [];
        foreach (['Delivered-To', 'X-Original-To', 'Envelope-To', 'X-Envelope-To', 'Apparently-To'] as $name) {
            foreach ($this->extractAllHeaderValues($rawHeader, $name) as $value) {
                $parts[] = $value;
            }
        }

        return implode(' ', $parts);
    }

    /**
     * @param list<object>|array<int, object> $addresses
     */
    private function formatAddressList(array $addresses): string
    {
        $parts = [];
        foreach ($addresses as $addr) {
            if (!isset($addr->mailbox, $addr->host)) {
                continue;
            }
            $email = $addr->mailbox . '@' . $addr->host;
            $personal = isset($addr->personal) ? $this->decodeMimeHeader($addr->personal) : '';
            $parts[] = $personal !== '' ? $personal . ' <' . $email . '>' : $email;
        }

        return implode(', ', $parts);
    }
}
