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

        $this->removeFolderRecord($folder);
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
