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

    public function find(int $id): ?array
    {
        $row = Database::fetchOne('SELECT * FROM folders WHERE id = ?', [$id]);

        return $row;
    }

    /**
     * @param array{display_name: string, folder_type: string, imap_path?: string, create_rule?: bool, rule_field?: string, rule_operator?: string, rule_value?: string} $data
     */
    public function createClientFolder(array $data): int
    {
        $imapPath = $data['imap_path'] ?? ('INBOX.' . preg_replace('/[^a-zA-Z0-9_-]/', '', $data['display_name']));

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

    /**
     * @param array{imap_path: string, display_name: string, folder_type: string, linked_user_id?: int|null} $data
     */
    public function insertFolder(array $data): int
    {
        // Create the folder on the IMAP server first; only persist the DB row
        // once the mailbox actually exists so we never reference a phantom folder.
        $imap = new ImapService();
        if (!$imap->connect()) {
            throw new \RuntimeException('Could not connect to the mail server to create the folder.');
        }
        if (!$imap->folderExistsOnServer($data['imap_path']) && !$imap->createFolder($data['imap_path'])) {
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

        (new FolderCache())->clear();

        return (int) Database::connection()->lastInsertId();
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
     * Whether an admin may delete this folder (employee/client user folders only).
     *
     * @param array<string, mixed>|null $folder
     */
    public function isDeletable(?array $folder): bool
    {
        if ($folder === null) {
            return false;
        }

        return in_array((string) ($folder['folder_type'] ?? ''), ['employee', 'client'], true);
    }

    /**
     * Remove a folder from the IMAP server (best effort) and the DB registry.
     * Filter rules targeting the folder are removed via ON DELETE CASCADE.
     */
    public function delete(int $id): void
    {
        $folder = $this->find($id);
        if ($folder === null) {
            return;
        }

        if (!$this->isDeletable($folder)) {
            throw new \RuntimeException(
                'Only user folders (employee or client) can be deleted. Inbox, Sent, Drafts, Trash, Spam, and other system folders are protected.'
            );
        }

        if (($folder['folder_type'] ?? '') === 'employee') {
            $linkedUserId = (int) ($folder['linked_user_id'] ?? 0);
            $this->purgeMailboxSubtree(
                (string) $folder['imap_path'],
                $linkedUserId > 0 ? $linkedUserId : null
            );
        } else {
            $this->removeFolderRecord($folder);
        }

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

        $this->removeFolderRecord($folder);
    }

    /**
     * Remove an employee's entire mailbox tree from IMAP and the folder registry.
     */
    public function purgeUserMailboxTree(int $userId): void
    {
        $roots = Database::query(
            "SELECT imap_path FROM folders WHERE linked_user_id = ? AND folder_type = 'employee'",
            [$userId]
        )->fetchAll();

        if ($roots === []) {
            $roots = Database::query(
                'SELECT imap_path FROM folders WHERE linked_user_id = ? ORDER BY LENGTH(imap_path) ASC',
                [$userId]
            )->fetchAll();
        }

        $rootPaths = [];
        foreach ($roots as $row) {
            $path = trim((string) ($row['imap_path'] ?? ''));
            if ($path !== '') {
                $rootPaths[] = $path;
            }
        }

        if ($rootPaths === []) {
            Database::query('DELETE FROM folders WHERE linked_user_id = ?', [$userId]);

            return;
        }

        foreach ($rootPaths as $root) {
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
        $connected = $imap->connect();
        $serverPaths = [];

        if ($connected) {
            foreach ($imap->listFolders() as $folder) {
                $serverPaths[] = (string) ($folder['path'] ?? '');
            }
        }

        $pathsToRemove = [$root => true];
        $prefix = $root . '.';
        foreach ($serverPaths as $serverPath) {
            if (
                strcasecmp($serverPath, $root) === 0
                || strncasecmp($serverPath, $prefix, strlen($prefix)) === 0
            ) {
                $pathsToRemove[$serverPath] = true;
            }
        }

        $deleteOrder = array_keys($pathsToRemove);
        usort(
            $deleteOrder,
            static fn (string $a, string $b): int => substr_count($b, '.') <=> substr_count($a, '.')
        );

        foreach ($deleteOrder as $path) {
            if ($connected && $imap->folderExistsOnServer($path)) {
                if (!$imap->deleteFolder($path)) {
                    app_log('IMAP folder delete failed for ' . $path . ': ' . $imap->getLastError());
                }
            }
            MailCacheService::purgeFolder($path);
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
     * @param array<string, mixed> $folder
     */
    private function removeFolderRecord(array $folder): void
    {
        $id = (int) $folder['id'];
        $imapPath = (string) $folder['imap_path'];

        $imap = new ImapService();
        if ($imap->connect() && $imap->folderExistsOnServer($imapPath)) {
            if (!$imap->deleteFolder($imapPath)) {
                app_log('IMAP folder delete failed for ' . $imapPath . ': ' . $imap->getLastError());
            }
        }

        MailCacheService::purgeFolder($imapPath);

        Database::query('UPDATE aliases SET default_folder_id = NULL WHERE default_folder_id = ?', [$id]);
        Database::query('DELETE FROM folders WHERE id = ?', [$id]);
        (new FolderCache())->clear();
    }
}
