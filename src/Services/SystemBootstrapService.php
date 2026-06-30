<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;

/**
 * Idempotent mail onboarding: Support mailbox plus any missing employee folders,
 * aliases, and routing rules (no code changes when staff are added in Admin).
 */
class SystemBootstrapService
{
    private static bool $ranThisRequest = false;
    private static bool $lastChanged = false;

    /**
     * Fast DB-only registry rows so Support appears in the sidebar without IMAP.
     *
     * @return bool true when anything was inserted or updated
     */
    public function ensureRegistryDefaults(): bool
    {
        $mailboxEmail = strtolower(trim((string) (config('mail')['mailbox_email'] ?? '')));
        if ($mailboxEmail === '' || !str_contains($mailboxEmail, '@')) {
            return false;
        }

        [$localPart] = explode('@', $mailboxEmail, 2);
        $safeSlug = preg_replace('/[^a-zA-Z0-9_-]/', '', $localPart);
        if ($safeSlug === '') {
            return false;
        }

        $imapPath = 'INBOX.' . $safeSlug;
        $displayName = strcasecmp($localPart, 'support') === 0 ? 'Support' : ucfirst($localPart);
        $changed = false;

        $folderRow = Database::fetchOne(
            'SELECT id, imap_path FROM folders WHERE imap_path = ? OR LOWER(imap_path) = LOWER(?) LIMIT 1',
            [$imapPath, $imapPath]
        );

        if ($folderRow !== null) {
            $folderId = (int) $folderRow['id'];
        } else {
            Database::query(
                'INSERT INTO folders (imap_path, display_name, folder_type, linked_user_id, active) VALUES (?, ?, ?, NULL, 1)',
                [$imapPath, $displayName, 'employee']
            );
            $folderId = (int) Database::connection()->lastInsertId();
            $changed = true;
        }

        $aliasService = new AdminAliasService();
        $existingAlias = $aliasService->findByEmail($mailboxEmail);
        if ($existingAlias === null) {
            $aliasService->create([
                'email' => $mailboxEmail,
                'display_name' => $displayName,
                'user_id' => null,
                'default_folder_id' => $folderId,
                'active' => 1,
            ]);
            $changed = true;
        } elseif ((int) ($existingAlias['default_folder_id'] ?? 0) !== $folderId) {
            $aliasService->update((int) $existingAlias['id'], [
                'email' => $mailboxEmail,
                'display_name' => (string) ($existingAlias['display_name'] ?? $displayName),
                'user_id' => $existingAlias['user_id'] ?? null,
                'default_folder_id' => $folderId,
                'active' => (int) ($existingAlias['active'] ?? 1),
            ]);
            $changed = true;
        }

        $existingRule = Database::fetchOne(
            "SELECT id, target_folder_id FROM filter_rules
             WHERE condition_field = 'to' AND LOWER(condition_value) = LOWER(?)
             LIMIT 1",
            [$mailboxEmail]
        );

        if ($existingRule === null) {
            (new AdminRuleService())->create([
                'name' => 'Route ' . $displayName . ' alias',
                'priority' => 30,
                'rule_type' => 'employee',
                'condition_field' => 'to',
                'condition_operator' => 'contains',
                'condition_value' => $mailboxEmail,
                'target_folder_id' => $folderId,
                'active' => 1,
            ]);
            $changed = true;
        } elseif ((int) ($existingRule['target_folder_id'] ?? 0) !== $folderId) {
            Database::query(
                'UPDATE filter_rules SET target_folder_id = ?, active = 1 WHERE id = ?',
                [$folderId, (int) $existingRule['id']]
            );
            $changed = true;
        }

        return $changed;
    }

    /**
     * Full onboarding: IMAP folders, employee backfill, and filter reprocess.
     * Run from the async mail bootstrap — not during login or folder cache loads.
     *
     * @return bool true when anything was created or updated
     */
    public function ensureDefaults(): bool
    {
        if (self::$ranThisRequest) {
            return self::$lastChanged;
        }
        self::$ranThisRequest = true;

        $changed = $this->ensureSupportMailbox();
        $backfill = (new AdminUserService())->backfillEmployees();
        if (($backfill['provisioned'] ?? 0) > 0) {
            $changed = true;
        }

        if ($changed) {
            FilterService::reprocess();
            (new FolderCache())->clear();
        }

        self::$lastChanged = $changed;

        return $changed;
    }

    private function ensureSupportMailbox(): bool
    {
        $mailboxEmail = strtolower(trim((string) (config('mail')['mailbox_email'] ?? '')));
        if ($mailboxEmail === '' || !str_contains($mailboxEmail, '@')) {
            return false;
        }

        [$localPart] = explode('@', $mailboxEmail, 2);
        $safeSlug = preg_replace('/[^a-zA-Z0-9_-]/', '', $localPart);
        if ($safeSlug === '') {
            return false;
        }

        $imapPath = 'INBOX.' . $safeSlug;
        $displayName = strcasecmp($localPart, 'support') === 0 ? 'Support' : ucfirst($localPart);

        $folderService = new AdminFolderService();
        $aliasService = new AdminAliasService();
        $ruleService = new AdminRuleService();
        $imap = new ImapService();
        $changed = false;

        $folderRow = Database::fetchOne(
            'SELECT id, imap_path FROM folders WHERE imap_path = ? OR LOWER(imap_path) = LOWER(?) LIMIT 1',
            [$imapPath, $imapPath]
        );

        if ($folderRow !== null) {
            $folderId = (int) $folderRow['id'];
            $imapPath = (string) $folderRow['imap_path'];
            if ($imap->connect() && !$imap->folderExistsOnServer($imapPath)) {
                if ($imap->ensureFolderPath($imapPath)) {
                    $changed = true;
                }
            }
        } else {
            $folderId = $folderService->insertFolder([
                'imap_path' => $imapPath,
                'display_name' => $displayName,
                'folder_type' => 'employee',
                'linked_user_id' => null,
            ], false);
            $changed = true;
        }

        if ($folderService->provisionStandardSubfolders($imapPath, null, false)) {
            $changed = true;
        }

        $pathBefore = Database::fetchOne('SELECT imap_path FROM folders WHERE id = ?', [$folderId]);
        migrate_employee_folder_to_messages_inbox($imapPath, $folderId);
        $pathAfter = Database::fetchOne('SELECT imap_path FROM folders WHERE id = ?', [$folderId]);
        if (
            $pathBefore !== null
            && $pathAfter !== null
            && (string) ($pathBefore['imap_path'] ?? '') !== (string) ($pathAfter['imap_path'] ?? '')
        ) {
            $changed = true;
        }

        $existingAlias = $aliasService->findByEmail($mailboxEmail);
        if ($existingAlias === null) {
            $aliasService->create([
                'email' => $mailboxEmail,
                'display_name' => $displayName,
                'user_id' => null,
                'default_folder_id' => $folderId,
                'active' => 1,
            ]);
            $changed = true;
        } elseif ((int) ($existingAlias['default_folder_id'] ?? 0) !== $folderId) {
            $aliasService->update((int) $existingAlias['id'], [
                'email' => $mailboxEmail,
                'display_name' => (string) ($existingAlias['display_name'] ?? $displayName),
                'user_id' => $existingAlias['user_id'] ?? null,
                'default_folder_id' => $folderId,
                'active' => (int) ($existingAlias['active'] ?? 1),
            ]);
            $changed = true;
        }

        $existingRule = Database::fetchOne(
            "SELECT id, target_folder_id FROM filter_rules
             WHERE condition_field = 'to' AND LOWER(condition_value) = LOWER(?)
             LIMIT 1",
            [$mailboxEmail]
        );

        if ($existingRule === null) {
            $ruleService->create([
                'name' => 'Route ' . $displayName . ' alias',
                'priority' => 30,
                'rule_type' => 'employee',
                'condition_field' => 'to',
                'condition_operator' => 'contains',
                'condition_value' => $mailboxEmail,
                'target_folder_id' => $folderId,
                'active' => 1,
            ]);
            $changed = true;
        } elseif ((int) ($existingRule['target_folder_id'] ?? 0) !== $folderId) {
            Database::query(
                'UPDATE filter_rules SET target_folder_id = ?, active = 1 WHERE id = ?',
                [$folderId, (int) $existingRule['id']]
            );
            $changed = true;
        }

        if ($changed) {
            FilterService::clearProcessed((string) (config('app')['filter_source_folder'] ?? 'INBOX'));
            (new FolderCache())->clear();
        }

        return $changed;
    }
}
