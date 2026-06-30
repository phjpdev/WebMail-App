<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;

class AdminFolderService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function listAll(): array
    {
        return Database::query(
            'SELECT f.*, u.name AS linked_user_name
             FROM folders f
             LEFT JOIN users u ON f.linked_user_id = u.id
             ORDER BY CASE f.folder_type
                 WHEN \'inbox\' THEN 1
                 WHEN \'sent\' THEN 2
                 WHEN \'other\' THEN 3
                 WHEN \'spam\' THEN 4
                 WHEN \'trash\' THEN 5
                 WHEN \'employee\' THEN 6
                 WHEN \'client\' THEN 7
                 ELSE 8
             END,
             f.display_name'
        )->fetchAll();
    }

    /**
     * @return list<array{id: int, label: string, imap_path: string}>
     */
    public function listParentChoices(): array
    {
        return admin_folder_parent_options($this->listAll());
    }

    public function find(int $id): ?array
    {
        $row = Database::fetchOne('SELECT * FROM folders WHERE id = ?', [$id]);

        return $row;
    }

    /**
     * @param array{display_name: string, folder_type: string, parent_folder_id?: int|null, imap_path?: string|null, create_rule?: bool, rule_field?: string, rule_operator?: string, rule_value?: string} $data
     */
    public function createClientFolder(array $data): int
    {
        $imapPath = $this->resolveImapPath(
            $data['display_name'],
            isset($data['parent_folder_id']) ? (int) $data['parent_folder_id'] : 0,
            $data['imap_path'] ?? null
        );

        $existing = Database::fetchOne('SELECT id FROM folders WHERE imap_path = ? LIMIT 1', [$imapPath]);
        if ($existing !== null) {
            throw new \RuntimeException('A folder with that name already exists' . (
                isset($data['parent_folder_id']) && (int) $data['parent_folder_id'] > 0
                    ? ' under the selected parent folder.'
                    : '.'
            ));
        }

        $folderId = $this->insertFolder([
            'imap_path' => $imapPath,
            'display_name' => $data['display_name'],
            'folder_type' => $data['folder_type'] ?? 'client',
            'linked_user_id' => null,
        ]);

        if (!empty($data['create_rule']) && !empty($data['rule_value'])) {
            (new AdminRuleService())->create([
                'name' => 'Route to ' . $data['display_name'],
                'priority' => 50,
                'rule_type' => $data['folder_type'] === 'client' ? 'client' : 'company',
                'condition_field' => $data['rule_field'] ?? 'subject',
                'condition_operator' => $data['rule_operator'] ?? 'contains',
                'condition_value' => $data['rule_value'],
                'target_folder_id' => $folderId,
            ]);
        }

        return $folderId;
    }

    public function resolveImapPath(string $displayName, int $parentFolderId = 0, ?string $explicitPath = null): string
    {
        $explicitPath = trim((string) $explicitPath);
        if ($explicitPath !== '') {
            return $explicitPath;
        }

        $parentPath = null;
        if ($parentFolderId > 0) {
            $parent = $this->find($parentFolderId);
            if ($parent === null) {
                throw new \RuntimeException('Parent folder not found.');
            }
            $parentPath = (string) $parent['imap_path'];
        }

        $imapPath = build_imap_folder_path($displayName, $parentPath);
        if ($imapPath === '') {
            throw new \RuntimeException(
                'Folder name is invalid. Use letters, numbers, spaces, hyphens, or underscores.'
            );
        }

        return $imapPath;
    }

    /**
     * @param list<array{imap_path: string, display_name: string, folder_type: string, linked_user_id?: int|null}> $folders
     * @return list<int>
     */
    public function insertFolders(array $folders, bool $clearFolderCache = true): array
    {
        if ($folders === []) {
            return [];
        }

        $imap = new ImapService();
        if (!$imap->connect()) {
            throw new \RuntimeException('Could not connect to the mail server to create the folder.');
        }

        $ids = [];
        foreach ($folders as $data) {
            if (!$imap->ensureFolderPath($data['imap_path'])) {
                throw new \RuntimeException('Could not create the folder on the mail server: ' . $imap->getLastError());
            }

            Database::query(
                'INSERT INTO folders (imap_path, display_name, folder_type, linked_user_id, active) VALUES (?, ?, ?, ?, 1)',
                [
                    $data['imap_path'],
                    $data['display_name'],
                    $data['folder_type'],
                    $data['linked_user_id'] ?? null,
                ]
            );
            $ids[] = (int) Database::connection()->lastInsertId();
        }

        if ($clearFolderCache) {
            (new FolderCache())->clear();
        }

        return $ids;
    }

    /**
     * @param array{imap_path: string, display_name: string, folder_type: string, linked_user_id?: int|null} $data
     */
    public function insertFolder(array $data, bool $clearFolderCache = true): int
    {
        $ids = $this->insertFolders([$data], $clearFolderCache);
        if ($ids === []) {
            throw new \RuntimeException('Could not create the folder.');
        }

        return $ids[0];
    }

    /**
     * Create Sent/Drafts/Archive/Junk/Trash under a mailbox root when missing.
     *
     * @return bool true if anything new was created
     */
    public function provisionStandardSubfolders(string $imapPath, ?int $linkedUserId = null, bool $clearFolderCache = true): bool
    {
        $subfolders = [
            ['suffix' => 'Sent', 'display_name' => 'Sent', 'folder_type' => 'sent'],
            ['suffix' => 'Drafts', 'display_name' => 'Drafts', 'folder_type' => 'other'],
            ['suffix' => 'Archive', 'display_name' => 'Archive', 'folder_type' => 'other'],
            ['suffix' => 'Junk', 'display_name' => 'Junk', 'folder_type' => 'spam'],
            ['suffix' => 'Trash', 'display_name' => 'Trash', 'folder_type' => 'trash'],
        ];

        $paths = [];
        foreach ($subfolders as $subfolder) {
            $paths[] = $imapPath . '.' . $subfolder['suffix'];
        }

        $existingPaths = [];
        if ($paths !== []) {
            $placeholders = implode(',', array_fill(0, count($paths), '?'));
            $rows = Database::query(
                'SELECT imap_path FROM folders WHERE imap_path IN (' . $placeholders . ')',
                $paths
            )->fetchAll();
            foreach ($rows as $row) {
                $path = trim((string) ($row['imap_path'] ?? ''));
                if ($path !== '') {
                    $existingPaths[strtoupper($path)] = true;
                }
            }
        }

        $toInsert = [];
        foreach ($subfolders as $subfolder) {
            $subPath = $imapPath . '.' . $subfolder['suffix'];
            if (isset($existingPaths[strtoupper($subPath)])) {
                continue;
            }

            $toInsert[] = [
                'imap_path' => $subPath,
                'display_name' => $subfolder['display_name'],
                'folder_type' => $subfolder['folder_type'],
                'linked_user_id' => $linkedUserId,
            ];
        }

        if ($toInsert === []) {
            return false;
        }

        $this->insertFolders($toInsert, $clearFolderCache);

        return true;
    }

    /**
     * @param array{display_name?: string, folder_type?: string, active?: int} $data
     */
    public function update(int $id, array $data): void
    {
        Database::query(
            'UPDATE folders SET display_name = ?, folder_type = ?, active = ? WHERE id = ?',
            [
                $data['display_name'],
                $data['folder_type'],
                (int) ($data['active'] ?? 1),
                $id,
            ]
        );
        (new FolderCache())->clear();
    }

    /**
     * Whether an admin may delete this folder.
     *
     * @param array<string, mixed>|null $folder
     */
    public function isDeletable(?array $folder): bool
    {
        if ($folder === null) {
            return false;
        }

        $type = (string) ($folder['folder_type'] ?? '');
        if (in_array($type, ['inbox', 'sent', 'other', 'spam', 'trash', 'system'], true)) {
            return false;
        }

        $path = strtoupper(trim((string) ($folder['imap_path'] ?? '')));
        if ($path === 'INBOX') {
            return false;
        }

        if (admin_folder_primary_bucket($folder) !== null) {
            return false;
        }

        return true;
    }

    /**
     * Remove a folder subtree from IMAP, cache, and the DB registry.
     * Filter rules targeting removed folders are dropped via ON DELETE CASCADE.
     */
    public function delete(int $id): void
    {
        $folder = $this->find($id);
        if ($folder === null) {
            return;
        }

        if (!$this->isDeletable($folder)) {
            throw new \RuntimeException(
                'This folder cannot be deleted. Inbox, Sent, Drafts, Trash, Spam, and other system folders are protected.'
            );
        }

        $linkedUserId = null;
        if (($folder['folder_type'] ?? '') === 'employee') {
            $uid = (int) ($folder['linked_user_id'] ?? 0);
            $linkedUserId = $uid > 0 ? $uid : null;
        }

        $this->purgeMailboxSubtree((string) $folder['imap_path'], $linkedUserId);
        (new FolderCache())->clear();
    }

    /**
     * Delete an employee/client folder when removing a user account.
     */
    public function deleteUserFolder(int $id, int $userId): void
    {
        $folder = $this->find($id);
        if ($folder === null) {
            return;
        }

        if (!in_array((string) ($folder['folder_type'] ?? ''), ['employee', 'client'], true)) {
            return;
        }

        $linkedUserId = (int) ($folder['linked_user_id'] ?? 0);
        if ($linkedUserId > 0 && $linkedUserId !== $userId) {
            return;
        }

        $this->purgeMailboxSubtree((string) $folder['imap_path'], $userId);
        (new FolderCache())->clear();
    }

    /**
     * Employee mailbox roots (INBOX.Name) for a user — used when purging mail.
     *
     * @return list<string>
     */
    public function mailboxRootsForUser(int $userId): array
    {
        $roots = [];
        $rows = Database::query(
            'SELECT imap_path FROM folders WHERE linked_user_id = ?',
            [$userId]
        )->fetchAll();

        foreach ($rows as $row) {
            $path = trim((string) ($row['imap_path'] ?? ''));
            if ($path === '') {
                continue;
            }
            $root = employee_mailbox_root_prefix(FolderCache::resolvePath($path));
            if ($root !== '') {
                $roots[strtoupper($root)] = $root;
            }
        }

        return array_values($roots);
    }

    /**
     * Remove an employee's entire mailbox tree from IMAP and the folder registry.
     */
    public function purgeUserMailboxTree(int $userId): void
    {
        $rows = Database::query(
            "SELECT imap_path FROM folders WHERE linked_user_id = ? AND folder_type = 'employee'",
            [$userId]
        )->fetchAll();

        if ($rows === []) {
            $rows = Database::query(
                'SELECT imap_path FROM folders WHERE linked_user_id = ? ORDER BY LENGTH(imap_path) ASC',
                [$userId]
            )->fetchAll();
        }

        $rootPaths = [];
        foreach ($rows as $row) {
            $path = trim((string) ($row['imap_path'] ?? ''));
            if ($path === '') {
                continue;
            }
            $root = employee_mailbox_root_prefix(FolderCache::resolvePath($path));
            if ($root !== '') {
                $rootPaths[strtoupper($root)] = $root;
            }
        }

        if ($rootPaths === []) {
            $userRow = Database::fetchOne('SELECT username FROM users WHERE id = ?', [$userId]);
            $username = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($userRow['username'] ?? ''));
            if ($username !== '') {
                $candidate = 'INBOX.' . $username;
                $imap = new ImapService();
                if ($imap->connect()) {
                    foreach ($imap->listFolders() as $folder) {
                        $serverPath = FolderCache::resolvePath((string) ($folder['path'] ?? ''));
                        if ($serverPath === '') {
                            continue;
                        }
                        if (strcasecmp(employee_mailbox_root_prefix($serverPath), $candidate) === 0) {
                            $rootPaths[strtoupper($candidate)] = $candidate;
                        }
                    }
                }
            }
        }

        if ($rootPaths === []) {
            Database::query('DELETE FROM folders WHERE linked_user_id = ?', [$userId]);
            Database::query('DELETE FROM mail_user_read WHERE user_id = ?', [$userId]);

            return;
        }

        foreach (array_values($rootPaths) as $root) {
            $this->purgeMailboxSubtree($root, $userId);
        }
    }

    /**
     * Remove employee mailboxes left in the registry after accounts were deleted.
     *
     * @return int Number of mailbox trees removed
     */
    public function purgeOrphanedEmployeeMailboxes(): int
    {
        $rows = Database::query(
            "SELECT f.id, f.imap_path
             FROM folders f
             LEFT JOIN users u ON u.id = f.linked_user_id AND u.active = 1
             WHERE f.folder_type = 'employee'
               AND f.active = 1
               AND (f.linked_user_id IS NULL OR u.id IS NULL)
             ORDER BY LENGTH(f.imap_path) ASC"
        )->fetchAll();

        $removed = 0;
        foreach ($rows as $row) {
            $path = trim((string) ($row['imap_path'] ?? ''));
            if ($path === '') {
                continue;
            }

            $segments = explode('.', $path);
            if (count($segments) !== 2 || strcasecmp($segments[0], 'INBOX') !== 0) {
                continue;
            }
            if (system_folder_bucket_for_leaf($segments[1]) !== null) {
                continue;
            }

            $this->purgeMailboxSubtree($path);
            $removed++;
        }

        if ($removed > 0) {
            (new FolderCache())->clear();
        }

        return $removed;
    }

    /**
     * Delete a mailbox root and every descendant from IMAP, cache, and the DB registry.
     */
    public function purgeMailboxSubtree(string $rootPath, ?int $linkedUserId = null): void
    {
        $root = rtrim($rootPath, '.');
        if ($root === '') {
            return;
        }

        $imap = new ImapService();
        if (!$imap->connect()) {
            throw new \RuntimeException('Could not connect to the mail server to delete the folder.');
        }

        $pathsToRemove = $this->collectSubtreePaths($root, $imap);
        $deleteOrder = array_keys($pathsToRemove);
        usort(
            $deleteOrder,
            static fn (string $a, string $b): int => substr_count($b, '.') <=> substr_count($a, '.')
        );

        $serverPaths = $imap->serverFolderLookup();

        $failures = [];
        foreach ($deleteOrder as $path) {
            if (!isset($serverPaths[strtoupper($path)])) {
                continue;
            }
            if (!$imap->deleteFolder($path)) {
                $error = $imap->getLastError();
                if ($error !== '' && self::isBenignFolderDeleteError($error)) {
                    app_log('IMAP folder delete skipped (already gone): ' . $path . ' — ' . $error);
                    continue;
                }
                $failures[] = $path . ': ' . $error;
            }
        }

        if ($failures !== []) {
            throw new \RuntimeException(
                'Could not delete the folder on the mail server. '
                . implode(' ', $failures)
                . ' Empty the folder or delete its subfolders first.'
            );
        }

        foreach ($deleteOrder as $path) {
            MailCacheService::purgeFolder($path);
        }

        $prefix = $root . '.';
        $folderIds = Database::query(
            'SELECT id FROM folders WHERE imap_path = ? OR imap_path LIKE ?',
            [$root, $prefix . '%']
        )->fetchAll();
        $folderIdList = array_values(array_filter(array_map(
            static fn (array $row): int => (int) ($row['id'] ?? 0),
            $folderIds
        ), static fn (int $id): bool => $id > 0));
        if ($folderIdList !== []) {
            $placeholders = implode(',', array_fill(0, count($folderIdList), '?'));
            Database::query(
                'UPDATE aliases SET default_folder_id = NULL WHERE default_folder_id IN (' . $placeholders . ')',
                $folderIdList
            );
        }

        if ($linkedUserId !== null && $linkedUserId > 0) {
            Database::query(
                'DELETE FROM folders WHERE linked_user_id = ? OR imap_path = ? OR imap_path LIKE ?',
                [$linkedUserId, $root, $prefix . '%']
            );
        } else {
            Database::query(
                'DELETE FROM folders WHERE imap_path = ? OR imap_path LIKE ?',
                [$root, $prefix . '%']
            );
        }
    }

    /**
     * @return array<string, true>
     */
    private function collectSubtreePaths(string $root, ImapService $imap): array
    {
        $pathsToRemove = [$root => true];
        $prefix = $root . '.';

        foreach ($imap->listFolders() as $folder) {
            $serverPath = (string) ($folder['path'] ?? '');
            if (
                strcasecmp($serverPath, $root) === 0
                || strncasecmp($serverPath, $prefix, strlen($prefix)) === 0
            ) {
                $pathsToRemove[$serverPath] = true;
            }
        }

        $dbRows = Database::query(
            'SELECT imap_path FROM folders WHERE imap_path = ? OR imap_path LIKE ?',
            [$root, $prefix . '%']
        )->fetchAll();
        foreach ($dbRows as $row) {
            $path = trim((string) ($row['imap_path'] ?? ''));
            if ($path !== '') {
                $pathsToRemove[$path] = true;
            }
        }

        return $pathsToRemove;
    }

    private static function isBenignFolderDeleteError(string $error): bool
    {
        $lower = strtolower($error);

        return str_contains($lower, 'nonexistent')
            || str_contains($lower, "doesn't exist")
            || str_contains($lower, 'does not exist')
            || str_contains($lower, 'no such mailbox');
    }
}
