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
             ORDER BY f.folder_type, f.display_name'
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
        $imap = new ImapService();
        if ($imap->connect() && !$imap->folderExistsOnServer($data['imap_path'])) {
            $imap->createFolder($data['imap_path']);
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
}
