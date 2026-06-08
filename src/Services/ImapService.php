<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

class ImapService
{
    private $connection = null;
    private string $lastError = '';

    public function connect(): bool
    {
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

        $flags = '/imap';
        $imap = $config['imap'];

        if ($imap['encryption'] === 'ssl') {
            $flags .= '/ssl';
        }

        $flags .= $imap['validate_cert'] ? '/validate-cert' : '/novalidate-cert';

        $mailboxString = sprintf('{%s:%d%s}', $imap['host'], $imap['port'], $flags);

        imap_errors();
        imap_alerts();

        $connection = @imap_open($mailboxString . 'INBOX', $mailbox, $password, 0, 1);

        if ($connection === false) {
            $errors = imap_errors() ?: [];
            $this->lastError = 'IMAP connection failed: ' . implode('; ', $errors);
            app_log($this->lastError);

            return false;
        }

        $this->connection = $connection;

        return true;
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

        $config = config('mail');
        $imap = $config['imap'];
        $flags = '/imap' . ($imap['encryption'] === 'ssl' ? '/ssl' : '');
        $flags .= $imap['validate_cert'] ? '/validate-cert' : '/novalidate-cert';
        $mailboxString = sprintf('{%s:%d%s}', $imap['host'], $imap['port'], $flags);

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

        $config = config('mail');
        $mailbox = $config['mailbox_email'];
        $password = $config['mailbox_password'];
        $imap = $config['imap'];
        $flags = '/imap' . ($imap['encryption'] === 'ssl' ? '/ssl' : '');
        $flags .= $imap['validate_cert'] ? '/validate-cert' : '/novalidate-cert';
        $mailboxString = sprintf('{%s:%d%s}', $imap['host'], $imap['port'], $flags);

        $reopened = @imap_reopen($this->connection, $mailboxString . $this->encodeFolderPath($path));

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
        if ($this->connection instanceof \IMAP\Connection) {
            imap_close($this->connection);
            $this->connection = null;
        }
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    private function ensureConnected(): bool
    {
        if ($this->connection !== null) {
            return true;
        }

        return $this->connect();
    }

    private function decodeFolderName(string $folder): string
    {
        $config = config('mail');
        $imap = $config['imap'];
        $flags = '/imap' . ($imap['encryption'] === 'ssl' ? '/ssl' : '');
        $flags .= $imap['validate_cert'] ? '/validate-cert' : '/novalidate-cert';
        $mailboxString = sprintf('{%s:%d%s}', $imap['host'], $imap['port'], $flags);

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
}
