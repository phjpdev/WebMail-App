<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth;
use App\Database;

class FilterService
{
    private const SESSION_STATS_KEY = '_last_filter_stats';

    /**
     * Guess destination folders from outgoing To/Cc/Bcc using active "to" rules.
     * Used to show sidebar badges immediately after compose send.
     *
     * @return list<string>
     */
    public static function predictDestinationPaths(
        string $to,
        string $cc = '',
        string $bcc = '',
        string $fromEmail = '',
    ): array {
        $fromEmail = strtolower(trim($fromEmail));
        $emails = [];
        foreach ([$to, $cc, $bcc] as $list) {
            foreach (parse_email_list($list)['valid'] as $email) {
                $email = strtolower($email);
                if ($fromEmail !== '' && strcasecmp($email, $fromEmail) === 0) {
                    continue;
                }
                $emails[$email] = true;
            }
        }

        if ($emails === []) {
            return [];
        }

        $rules = Database::query(
            "SELECT r.condition_operator, r.condition_value, f.imap_path
             FROM filter_rules r
             INNER JOIN folders f ON r.target_folder_id = f.id
             WHERE r.active = 1 AND f.active = 1 AND r.condition_field = 'to'
             ORDER BY FIELD(r.rule_type, 'spam', 'company', 'employee', 'client'), r.priority ASC, r.id ASC"
        )->fetchAll();

        $paths = [];
        foreach ($rules as $rule) {
            $path = (string) ($rule['imap_path'] ?? '');
            if ($path === '' || isset($paths[$path])) {
                continue;
            }

            $needle = (string) ($rule['condition_value'] ?? '');
            $operator = (string) ($rule['condition_operator'] ?? 'contains');
            $needleLower = strtolower($needle);
            $isEmail = str_contains($needle, '@');

            foreach (array_keys($emails) as $email) {
                $matched = ($isEmail && $operator === 'contains' && strcasecmp($email, $needle) === 0)
                    || match ($operator) {
                        'equals' => strcasecmp($email, $needle) === 0,
                        'contains' => str_contains($email, $needleLower),
                        'starts_with' => str_starts_with($email, $needleLower),
                        'ends_with' => str_ends_with($email, $needleLower),
                        default => false,
                    };

                if ($matched) {
                    $paths[FolderCache::resolvePath(employee_messages_imap_path($path))] = true;
                    break;
                }
            }
        }

        foreach (array_keys($emails) as $email) {
            $aliasFolder = folder_for_alias_email($email);
            if ($aliasFolder === null) {
                continue;
            }
            $resolved = FolderCache::resolvePath(employee_messages_imap_path($aliasFolder));
            if ($resolved !== '') {
                $paths[$resolved] = true;
            }
        }

        $result = array_keys($paths);
        $senderFolder = folder_for_alias_email($fromEmail);
        if ($senderFolder === null) {
            return $result;
        }

        $resolvedSender = FolderCache::resolvePath($senderFolder);

        return array_values(array_filter(
            $result,
            static fn (string $path): bool => FolderCache::resolvePath($path) !== $resolvedSender
        ));
    }

    private static function bumpUnreadIfNotPending(string $path): void
    {
        $path = FolderCache::resolvePath($path);
        if ($path === '' || sender_suppresses_dest_folder_badge($path)) {
            return;
        }

        $pendingResolved = array_map(
            static fn (string $p): string => FolderCache::resolvePath($p),
            FolderCache::getPendingBadgePaths()
        );
        if (in_array($path, $pendingResolved, true)) {
            flush_session_for_poll();

            return;
        }

        FolderCache::bumpUnread($path, 1);
        flush_session_for_poll();
    }

    /**
     * Run filter immediately before listing mail (skips the 60s throttle) so
     * routed messages are moved out of INBOX before the user sees them.
     *
     * @return array{processed: int, moved: int, errors: list<string>, duration_ms: int, done?: bool}|null
     */
    public static function runBeforeMailList(): ?array
    {
        return self::runBackground(true);
    }

    /**
     * Keep user-moved mail in the filter inbox instead of auto-routing it again.
     *
     * @param list<int> $uids
     */
    public static function preserveManualInboxPlacement(ImapService $imap, string $inboxPath, array $uids): void
    {
        $inboxPath = FolderCache::resolvePath($inboxPath);
        if ($inboxPath === '') {
            return;
        }

        $service = new self();
        foreach (array_values(array_unique(array_filter(array_map('intval', $uids)))) as $uid) {
            if ($uid <= 0) {
                continue;
            }
            $headers = $imap->fetchFilterHeaders($inboxPath, $uid);
            $service->markProcessed($uid, $inboxPath, $headers['message_id'] ?? null);
        }
    }

    /**
     * Mark relocated index rows so the next inbox filter pass leaves them alone.
     *
     * @param list<int> $uids
     */
    public static function preserveManualInboxPlacementFromIndex(string $inboxPath, array $uids): void
    {
        $inboxPath = FolderCache::resolvePath($inboxPath);
        if ($inboxPath === '') {
            return;
        }

        $service = new self();
        foreach (array_values(array_unique(array_filter(array_map('intval', $uids)))) as $uid) {
            if ($uid <= 0) {
                continue;
            }
            $row = Database::fetchOne(
                'SELECT message_id FROM mail_index WHERE folder_path = ? AND imap_uid = ? LIMIT 1',
                [$inboxPath, $uid]
            );
            $service->markProcessed($uid, $inboxPath, $row['message_id'] ?? null);
        }
    }

    /**
     *
     * Uses a file lock so only one pass runs at a time, and a minimum interval
     * so opening mail repeatedly does not hammer IMAP. Set $force true for
     * admin "Sync now" (skips the interval).
     *
     * @return array{processed: int, moved: int, errors: list<string>, duration_ms: int, done?: bool}|null
     */
    public static function runBackground(bool $force = false, ?int $maxRuntimeSeconds = null): ?array
    {
        $app = config('app');
        $minInterval = (int) ($app['filter_min_interval'] ?? 60);
        $maxRuntime = $maxRuntimeSeconds ?? (int) ($app['filter_max_runtime'] ?? 20);
        $batchLimit = (int) ($app['filter_batch_limit'] ?? 500);

        $state = self::readState();
        if (!$force && ($state['last_run'] ?? 0) + $minInterval > time()) {
            return null;
        }

        $lock = @fopen(self::lockFile(), 'c');
        if ($lock === false) {
            return null;
        }

        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return null;
        }

        try {
            $service = new self();
            $totals = ['processed' => 0, 'moved' => 0, 'errors' => [], 'duration_ms' => 0];
            $start = microtime(true);
            $pathsToRefresh = [];

            do {
                $batch = $service->run();
                $totals['processed'] += $batch['processed'];
                $totals['moved'] += $batch['moved'];
                $totals['errors'] = array_merge($totals['errors'], $batch['errors']);

                foreach ($batch['refresh_unread_paths'] ?? [] as $path) {
                    $pathsToRefresh[$path] = true;
                }

                if ($batch['processed'] < $batchLimit) {
                    break;
                }
            } while ((microtime(true) - $start) < $maxRuntime);

            if ($pathsToRefresh !== []) {
                $pathList = array_keys($pathsToRefresh);

                if ($totals['moved'] > 0) {
                    $imap = new ImapService();
                    if ($imap->connect()) {
                        foreach ($pathList as $path) {
                            try {
                                MailCacheService::syncFolderHeaders($imap, $path);
                                MailCacheService::reconcileBadgeFromIndex($path);
                            } catch (\Throwable $e) {
                                app_log('Mail cache sync after filter failed for ' . $path . ': ' . $e->getMessage());
                            }
                        }
                        reconcile_alias_self_sent_echoes($imap, $pathList);
                    }
                }

                // Per-folder header sync + reconcile already updated badges; skip
                // a second IMAP STATUS sweep here.
            }

            $totals['duration_ms'] = (int) round((microtime(true) - $start) * 1000);
            $totals['done'] = true;
            if ($pathsToRefresh !== []) {
                $totals['refresh_paths'] = array_keys($pathsToRefresh);
            }

            self::writeState($totals);

            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION[self::SESSION_STATS_KEY] = $totals;
            }

            flush_session_for_poll();

            if (
                session_status() === PHP_SESSION_ACTIVE
                && !$force
                && ($totals['duration_ms'] > 2000 || $totals['moved'] > 0)
            ) {
                flash(
                    'success',
                    sprintf('Organized %d message(s), %d moved to folders.', $totals['processed'], $totals['moved'])
                );
            }

            return $totals;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
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
        self::resetThrottle();
    }

    public static function resetThrottle(): void
    {
        $state = self::readState();
        $state['last_run'] = 0;
        @file_put_contents(self::stateFile(), json_encode($state));
    }

    /**
     * @return array{processed: int, moved: int, errors: list<string>, duration_ms: int}|null
     */
    public static function lastStats(): ?array
    {
        $stats = $_SESSION[self::SESSION_STATS_KEY] ?? null;
        if (is_array($stats)) {
            return $stats;
        }

        $state = self::readState();

        return is_array($state['last_stats'] ?? null) ? $state['last_stats'] : null;
    }

    /**
     * @return array{last_run: int, last_stats: array<string, mixed>|null}
     */
    private static function readState(): array
    {
        $path = self::stateFile();
        if (!is_readable($path)) {
            return ['last_run' => 0, 'last_stats' => null];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : ['last_run' => 0, 'last_stats' => null];
    }

    /**
     * @param array{processed: int, moved: int, errors: list<string>, duration_ms: int} $stats
     */
    private static function writeState(array $stats): void
    {
        $payload = [
            'last_run' => time(),
            'last_stats' => $stats,
        ];

        @file_put_contents(
            self::stateFile(),
            json_encode($payload) ?: '{}',
            LOCK_EX
        );
    }

    private static function storageDir(): string
    {
        return dirname(dirname(config('app')['log_path']));
    }

    private static function lockFile(): string
    {
        return self::storageDir() . '/filter.lock';
    }

    private static function stateFile(): string
    {
        return self::storageDir() . '/filter_state.json';
    }

    /**
     * @return array{processed: int, moved: int, errors: list<string>, duration_ms: int}
     */
    public function run(): array
    {
        $start = microtime(true);
        $result = ['processed' => 0, 'moved' => 0, 'errors' => [], 'duration_ms' => 0];

        $rules = $this->loadRules();

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
        $refreshUnreadPaths = [];

        foreach ($candidates as $uid) {
            $headers = $imap->fetchFilterHeaders($sourceFolder, $uid);
            if ($headers === null) {
                continue;
            }

            $body = null;
            $matchedRules = $this->matchRules($imap, $sourceFolder, $uid, $headers, $rules, $needsBody, $body);
            $pendingPaths = FolderCache::claimPendingFilterRoute($headers['message_id'] ?? null);
            $targetPaths = $this->resolveTargetPaths($matchedRules, $pendingPaths);

            if ($targetPaths !== []) {
                $primaryRule = $targetPaths[0];
                $primaryPath = employee_messages_imap_path((string) $primaryRule['imap_path']);
                $extraPaths = array_slice($targetPaths, 1);

                if ($extraPaths === []) {
                    if ($imap->moveMessage($sourceFolder, $uid, $primaryPath)) {
                        $result['moved']++;
                        $refreshUnreadPaths[$sourceFolder] = true;
                        $refreshUnreadPaths[$primaryPath] = true;
                        self::bumpUnreadIfNotPending($primaryPath);
                        $this->logFilterMove($userId, $uid, $primaryPath, (string) ($primaryRule['name'] ?? ''));
                        $this->markProcessed($uid, $sourceFolder, $headers['message_id'] ?? null);
                    } else {
                        $result['errors'][] = 'UID ' . $uid . ': ' . $imap->getLastError();
                    }
                } else {
                    $raw = $imap->fetchRawMessage($sourceFolder, $uid);
                    if ($raw === null) {
                        $result['errors'][] = 'UID ' . $uid . ': could not read message for multi-folder routing';
                    } else {
                        $delivered = 0;
                        foreach ($targetPaths as $rule) {
                            $destPath = employee_messages_imap_path((string) ($rule['imap_path'] ?? ''));
                            if ($destPath === '') {
                                continue;
                            }
                            if ($imap->appendMessage($destPath, $raw)) {
                                $delivered++;
                                $refreshUnreadPaths[$destPath] = true;
                                self::bumpUnreadIfNotPending($destPath);
                                $this->logFilterMove($userId, $uid, $destPath, (string) ($rule['name'] ?? ''));
                            } else {
                                $result['errors'][] = 'Deliver to ' . $destPath . ': ' . $imap->getLastError();
                                app_log('Filter deliver to ' . $destPath . ' failed: ' . $imap->getLastError());
                            }
                        }

                        if ($delivered > 0) {
                            if ($imap->deleteMessage($sourceFolder, $uid)) {
                                MailCacheService::removeMessage($sourceFolder, $uid);
                                $refreshUnreadPaths[$sourceFolder] = true;
                            }
                            $result['moved'] += $delivered;
                            $this->markProcessed($uid, $sourceFolder, $headers['message_id'] ?? null);
                        }
                    }
                }
            } else {
                $this->markProcessed($uid, $sourceFolder, $headers['message_id'] ?? null);
            }

            $result['processed']++;
        }

        if ($refreshUnreadPaths !== []) {
            $result['refresh_unread_paths'] = array_keys($refreshUnreadPaths);
        }

        $result['duration_ms'] = (int) round((microtime(true) - $start) * 1000);

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $matchedRules
     * @param list<string>|null $pendingPaths
     * @return list<array<string, mixed>>
     */
    private function resolveTargetPaths(array $matchedRules, ?array $pendingPaths): array
    {
        if ($pendingPaths !== null && $pendingPaths !== []) {
            $paths = [];
            foreach ($pendingPaths as $path) {
                $path = (string) $path;
                if ($path !== '') {
                    $paths[$path] = ['imap_path' => $path, 'name' => 'compose-route'];
                }
            }

            return array_values($paths);
        }

        if ($matchedRules === []) {
            return [];
        }

        $targetPaths = [];
        foreach ($matchedRules as $rule) {
            $path = (string) ($rule['imap_path'] ?? '');
            if ($path !== '') {
                $targetPaths[$path] = $rule;
            }
        }

        return array_values($targetPaths);
    }

    /**
     * Resolve filter rules for one message. Recipient (To) rules may match
     * multiple folders when several aliases appear on the same message; all
     * other rule types keep first-match-wins behaviour.
     *
     * @param list<array<string, mixed>> $rules
     * @return list<array<string, mixed>>
     */
    private function matchRules(
        ImapService $imap,
        string $sourceFolder,
        int $uid,
        array $headers,
        array $rules,
        bool $needsBody,
        ?string &$body,
    ): array {
        $toMatches = [];

        foreach ($rules as $rule) {
            if (($rule['condition_field'] ?? '') === 'body' && $needsBody && $body === null) {
                $body = $imap->fetchFilterBody($sourceFolder, $uid);
            }

            if (!RuleMatcher::matchesForDelivery($headers, $body, $rule)) {
                continue;
            }

            $type = (string) ($rule['rule_type'] ?? '');
            $field = (string) ($rule['condition_field'] ?? '');

            if ($type === 'spam') {
                return [$rule];
            }

            if ($field === 'to') {
                $path = (string) ($rule['imap_path'] ?? '');
                if ($path !== '' && !isset($toMatches[$path])) {
                    $toMatches[$path] = $rule;
                }
                continue;
            }

            return [$rule];
        }

        foreach ($this->aliasRouteTargets($headers) as $path => $label) {
            if (!isset($toMatches[$path])) {
                $toMatches[$path] = ['imap_path' => $path, 'name' => $label];
            }
        }

        if ($toMatches !== []) {
            $this->dropSharedMailboxCatchAllWhenEmployeeAliasMatches($headers, $toMatches);
        }

        return array_values($toMatches);
    }

    /**
     * Route mail to employee folders by send-as alias even when filter rules are
     * missing or the MTA rewrites the visible To line to the shared mailbox.
     *
     * @param array<string, string|null> $headers
     * @return array<string, string> imap_path => rule label
     */
    private function aliasRouteTargets(array $headers): array
    {
        $from = strtolower(trim((string) ($headers['from'] ?? '')));
        $targets = [];

        foreach (RuleMatcher::recipientAddressesFromHeaders($headers) as $email) {
            $email = strtolower(trim($email));
            if ($email === '' || ($from !== '' && strcasecmp($email, $from) === 0)) {
                continue;
            }

            $folder = folder_for_alias_email($email);
            if ($folder === null) {
                continue;
            }

            $path = employee_messages_imap_path(FolderCache::resolvePath($folder));
            if ($path !== '') {
                $targets[$path] = 'alias:' . $email;
            }
        }

        return $targets;
    }

    /**
     * Internal sends from the shared mailbox often echo into INBOX with To:
     * support@… even when compose addressed an employee alias. Employee alias
     * routing must win over the shared catch-all rule in that case.
     *
     * @param array<string, string|null> $headers
     * @param array<string, array<string, mixed>> $toMatches
     */
    private function dropSharedMailboxCatchAllWhenEmployeeAliasMatches(
        array $headers,
        array &$toMatches,
    ): void {
        $aliasTargets = $this->aliasRouteTargets($headers);
        if ($aliasTargets === []) {
            return;
        }

        $shared = strtolower(trim((string) (config('mail')['mailbox_email'] ?? '')));
        $from = strtolower(trim((string) ($headers['from'] ?? '')));
        if ($shared === '' || $from !== $shared) {
            return;
        }

        $supportFolder = folder_for_alias_email($shared);
        if ($supportFolder === null) {
            return;
        }

        $supportPath = FolderCache::resolvePath($supportFolder);
        if ($supportPath !== '' && isset($toMatches[$supportPath])) {
            unset($toMatches[$supportPath]);
        }
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
