<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth;
use App\Database;

class FilterService
{
    private const SESSION_RAN_KEY = '_filter_ran';
    private const SESSION_STATS_KEY = '_last_filter_stats';

    /**
     * @return array{processed: int, moved: int, errors: list<string>, duration_ms: int, done?: bool}|null
     */
    public static function runIfNeeded(bool $force = false): ?array
    {
        if (!$force && isset($_SESSION[self::SESSION_RAN_KEY])) {
            return null;
        }

        $service = new self();
        $result = $service->run();
        $result['done'] = true;

        $_SESSION[self::SESSION_RAN_KEY] = time();
        $_SESSION[self::SESSION_STATS_KEY] = $result;
        unset($_SESSION['_filter_pending']);

        if (!$force && ($result['duration_ms'] > 2000 || $result['moved'] > 0)) {
            flash(
                'success',
                sprintf('Organized %d message(s), %d moved to folders.', $result['processed'], $result['moved'])
            );
        }

        return $result;
    }

    public static function clearSessionFlag(): void
    {
        unset($_SESSION[self::SESSION_RAN_KEY], $_SESSION[self::SESSION_STATS_KEY], $_SESSION['_filter_pending']);
    }

    /**
     * @return array{processed: int, moved: int, errors: list<string>, duration_ms: int}|null
     */
    public static function lastStats(): ?array
    {
        $stats = $_SESSION[self::SESSION_STATS_KEY] ?? null;

        return is_array($stats) ? $stats : null;
    }

    /**
     * @return array{processed: int, moved: int, errors: list<string>, duration_ms: int}
     */
    public function run(): array
    {
        $start = microtime(true);
        $result = ['processed' => 0, 'moved' => 0, 'errors' => [], 'duration_ms' => 0];

        $rules = $this->loadRules();
        if ($rules === []) {
            $result['duration_ms'] = (int) round((microtime(true) - $start) * 1000);
            return $result;
        }

        $sourceFolder = config('app')['filter_source_folder'];
        $batchLimit = config('app')['filter_batch_limit'];
        $needsBody = $this->rulesNeedBody($rules);
        $processedUids = $this->loadProcessedUids($sourceFolder);

        $imap = new ImapService();
        if (!$imap->connect()) {
            $result['errors'][] = $imap->getLastError();
            $result['duration_ms'] = (int) round((microtime(true) - $start) * 1000);
            return $result;
        }

        $allUids = $imap->getFolderUids($sourceFolder, $batchLimit * 3);
        $candidates = [];
        foreach ($allUids as $uid) {
            if (!isset($processedUids[$uid])) {
                $candidates[] = $uid;
            }
            if (count($candidates) >= $batchLimit) {
                break;
            }
        }

        $userId = Auth::user()['id'] ?? null;

        foreach ($candidates as $uid) {
            $headers = $imap->fetchFilterHeaders($sourceFolder, $uid);
            if ($headers === null) {
                continue;
            }

            $body = null;
            $matchedRule = null;

            foreach ($rules as $rule) {
                if (($rule['condition_field'] ?? '') === 'body' && $needsBody && $body === null) {
                    $body = $imap->fetchFilterBody($sourceFolder, $uid);
                }

                if (RuleMatcher::matches($headers, $body, $rule)) {
                    $matchedRule = $rule;
                    break;
                }
            }

            if ($matchedRule !== null) {
                $targetPath = $matchedRule['imap_path'];
                if ($imap->moveMessage($sourceFolder, $uid, $targetPath)) {
                    $result['moved']++;
                    $this->logFilterMove($userId, $uid, $targetPath, $matchedRule['name']);
                } else {
                    $result['errors'][] = 'UID ' . $uid . ': ' . $imap->getLastError();
                }
            }

            $this->markProcessed($uid, $sourceFolder, $headers['message_id'] ?? null);
            $result['processed']++;
        }

        $result['duration_ms'] = (int) round((microtime(true) - $start) * 1000);

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadRules(): array
    {
        return Database::query(
            'SELECT r.*, f.imap_path
             FROM filter_rules r
             INNER JOIN folders f ON r.target_folder_id = f.id
             WHERE r.active = 1 AND f.active = 1
             ORDER BY r.priority ASC, r.id ASC'
        )->fetchAll();
    }

    /**
     * @param list<array<string, mixed>> $rules
     */
    private function rulesNeedBody(array $rules): bool
    {
        foreach ($rules as $rule) {
            if (($rule['condition_field'] ?? '') === 'body') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, true>
     */
    private function loadProcessedUids(string $folderPath): array
    {
        $rows = Database::query(
            'SELECT imap_uid FROM processed_messages WHERE folder_path = ?',
            [$folderPath]
        )->fetchAll();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['imap_uid']] = true;
        }

        return $map;
    }

    private function markProcessed(int $uid, string $folderPath, ?string $messageId): void
    {
        try {
            Database::query(
                'INSERT IGNORE INTO processed_messages (imap_uid, folder_path, message_id) VALUES (?, ?, ?)',
                [$uid, $folderPath, $messageId]
            );
        } catch (\Throwable $e) {
            app_log('Failed to mark processed UID ' . $uid . ': ' . $e->getMessage());
        }
    }

    private function logFilterMove(?int $userId, int $uid, string $targetPath, string $ruleName): void
    {
        try {
            Database::query(
                'INSERT INTO audit_log (user_id, action, details) VALUES (?, ?, ?)',
                [$userId, 'filter_move', "UID $uid → $targetPath (rule: $ruleName)"]
            );
        } catch (\Throwable $e) {
            app_log('Audit log failed on filter_move: ' . $e->getMessage());
        }
    }
}
