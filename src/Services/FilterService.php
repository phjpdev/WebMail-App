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
     * Forget which messages have been filtered so they are re-evaluated against
     * the current rule set on the next pass. Called when rules, users, or
     * aliases change (new routing should apply to already-seen mail).
     */
    public static function clearProcessed(?string $folderPath = null): void
    {
        try {
            if ($folderPath === null) {
                Database::query('DELETE FROM processed_messages');
            } else {
                Database::query('DELETE FROM processed_messages WHERE folder_path = ?', [$folderPath]);
            }
        } catch (\Throwable $e) {
            app_log('Failed to clear processed_messages: ' . $e->getMessage());
        }
    }

    /**
     * Clear processed tracking for the configured filter source folder and reset
     * the session flag so the next page load re-runs a full filter pass.
     */
    public static function reprocess(): void
    {
        self::clearProcessed(config('app')['filter_source_folder']);
        self::clearSessionFlag();
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

        // Page through the inbox in growing windows until we have a full batch of
        // unprocessed messages or we run out of mail. A small window can be fully
        // "already processed", which previously starved older unrouted mail.
        $candidates = [];
        $window = $batchLimit * 3;
        $maxWindow = max($window, $batchLimit * 20);

        while (true) {
            $allUids = $imap->getFolderUids($sourceFolder, $window);

            $candidates = [];
            foreach ($allUids as $uid) {
                if (!isset($processedUids[$uid])) {
                    $candidates[] = $uid;
                    if (count($candidates) >= $batchLimit) {
                        break;
                    }
                }
            }

            if (
                count($candidates) >= $batchLimit
                || count($allUids) < $window
                || $window >= $maxWindow
            ) {
                break;
            }

            $window += $batchLimit * 3;
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
                    // Only record as processed once the move actually succeeded.
                    $this->markProcessed($uid, $sourceFolder, $headers['message_id'] ?? null);
                } else {
                    // Move failed (transient IMAP error): leave the UID unprocessed
                    // so it is retried on the next filter pass instead of being lost.
                    $result['errors'][] = 'UID ' . $uid . ': ' . $imap->getLastError();
                }
            } else {
                // No rule matched: mark processed so we don't rescan it every pass.
                // clearProcessed() is called when rules/users/aliases change so this
                // mail is re-evaluated against the new rule set.
                $this->markProcessed($uid, $sourceFolder, $headers['message_id'] ?? null);
            }

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
        // Enforce a deterministic precedence so a spam rule always wins over a
        // company rule, which wins over an employee rule, which wins over a
        // client rule, with priority/id as the tie-breaker within each type.
        return Database::query(
            "SELECT r.*, f.imap_path
             FROM filter_rules r
             INNER JOIN folders f ON r.target_folder_id = f.id
             WHERE r.active = 1 AND f.active = 1
             ORDER BY FIELD(r.rule_type, 'spam', 'company', 'employee', 'client'), r.priority ASC, r.id ASC"
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
