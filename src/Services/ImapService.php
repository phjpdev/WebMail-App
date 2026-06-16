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
        $folders = imap_list($this->connection, $mailboxString, '*') ?: [];
        $result = [];

        foreach ($folders as $folder) {
            $path = $this->decodeFolderName($folder);
            $name = $path === 'INBOX' ? 'Inbox' : $path;

            $result[] = [
                'path' => $path,
                'name' => $name,
                'delimiter' => '.',
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
            $messages[] = [
                'uid' => (int) ($row->uid ?? 0),
                'from' => isset($row->from) ? $this->decodeMimeHeader($row->from) : '',
                'subject' => isset($row->subject) ? $this->decodeMimeHeader($row->subject) : '(no subject)',
                'date' => $row->date ?? '',
                'seen' => (bool) ($row->seen ?? false),
                'flagged' => (bool) ($row->flagged ?? false),
                'size' => (int) ($row->size ?? 0),
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
        $body = $this->fetchBody($path, $uid);

        $from = '';
        if (isset($header->from[0])) {
            $from = $header->from[0]->mailbox . '@' . $header->from[0]->host;
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
     * @return array{html: string|null, plain: string|null, attachments: list<array{id: string, filename: string, size: int, mime: string}>}
     */
    public function fetchBody(string $path, int $uid): array
    {
        $result = ['html' => null, 'plain' => null, 'attachments' => []];

        if (!$this->openFolder($path)) {
            return $result;
        }

        $msgno = imap_msgno($this->connection, $uid);
        if ($msgno === 0) {
            return $result;
        }

        $structure = imap_fetchstructure($this->connection, $msgno);
        if ($structure === false) {
            return $result;
        }

        $this->parseStructure($msgno, $structure, '', $result);

        return $result;
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
        if (!$this->openFolder($fromPath)) {
            return false;
        }

        $wasSeen = $this->isSeen($fromPath, $uid);

        $mailboxString = $this->getMailboxString();
        $target = $mailboxString . $this->encodeFolderPath($toPath);

        $moved = @imap_mail_move($this->connection, (string) $uid, $target, CP_UID);

        if (!$moved) {
            $moved = $this->moveMessageCopyDelete($fromPath, $uid, $toPath, $wasSeen);
        } else {
            imap_expunge($this->connection);
            if ($wasSeen) {
                $this->markSeen($toPath, $uid);
            }
        }

        if (!$moved) {
            $errors = imap_errors() ?: [];
            $this->lastError = 'Failed to move message: ' . implode('; ', $errors);
            app_log($this->lastError);

            return false;
        }

        return true;
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

        foreach ($paths as $path) {
            $status = @imap_status(
                $this->connection,
                $mailboxString . $this->encodeFolderPath($path),
                SA_UNSEEN
            );
            $counts[$path] = $status !== false ? (int) ($status->unseen ?? 0) : 0;
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
            ];
        }

        return $messages;
    }

    public function appendMessage(string $folderPath, string $rawMessage, ?string $flags = null): bool
    {
        if (!$this->ensureConnected()) {
            return false;
        }

        $mailbox = $this->getMailboxString() . $this->encodeFolderPath($folderPath);

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

        $to = '';
        if (isset($header->to[0])) {
            $to = $header->to[0]->mailbox . '@' . $header->to[0]->host;
        }

        return [
            'from' => $from,
            'to' => $to,
            'subject' => isset($header->subject) ? $this->decodeMimeHeader($header->subject) : '',
            'delivered_to' => $this->extractHeaderValue($rawHeader, 'Delivered-To'),
            'x_original_to' => $this->extractHeaderValue($rawHeader, 'X-Original-To'),
            'message_id' => $this->extractHeaderValue($rawHeader, 'Message-ID'),
        ];
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
            return true;
        }

        $errors = imap_errors() ?: [];
        $this->lastError = 'Failed to create folder: ' . implode('; ', $errors);
        app_log($this->lastError);

        return false;
    }

    public function folderExistsOnServer(string $path): bool
    {
        foreach ($this->listFolders() as $folder) {
            if ($folder['path'] === $path) {
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

        $header = imap_fetchheader($this->connection, $msgno);
        $body = imap_body($this->connection, $msgno);
        if ($header === false || $body === false) {
            return false;
        }

        $raw = $header . $body;
        $target = $this->getMailboxString() . $this->encodeFolderPath($toPath);

        if (!@imap_append($this->connection, $target, $raw)) {
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
            foreach ($structure->parts as $index => $subPart) {
                $subId = $partId === '' ? (string) ($index + 1) : $partId . '.' . ($index + 1);
                $this->parseStructure($msgno, $subPart, $subId, $result);
            }
            return;
        }

        $mime = $this->getPartMime($structure);
        $section = $partId === '' ? '1' : $partId;
        $body = imap_fetchbody($this->connection, $msgno, $section);

        if ($body === false) {
            return;
        }

        $decoded = $this->decodePartBody($body, $structure->encoding ?? 0);
        $filename = $this->getPartFilename($structure);
        $disposition = strtolower($structure->disposition ?? '');

        if ($filename !== null || $disposition === 'attachment') {
            $result['attachments'][] = [
                'id' => $section,
                'filename' => $filename ?? 'attachment',
                'size' => strlen($decoded),
                'mime' => $mime,
            ];
            return;
        }

        if (str_starts_with($mime, 'text/html') && $result['html'] === null) {
            $result['html'] = $decoded;
        } elseif (str_starts_with($mime, 'text/plain') && $result['plain'] === null) {
            $result['plain'] = $decoded;
        }
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
            ENCBASE64 => base64_decode($body) ?: $body,
            ENCQUOTEDPRINTABLE => quoted_printable_decode($body),
            default => $body,
        };
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
        if (preg_match('/^' . preg_quote($headerName, '/') . ':\s*(.+)$/im', $rawHeader, $matches)) {
            return trim($matches[1]);
        }

        return null;
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
