<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;

class AdminUserService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function listAll(): array
    {
        return Database::query(
            'SELECT id, name, username, role, active, created_at FROM users ORDER BY role DESC, name'
        )->fetchAll();
    }

    public function find(int $id): ?array
    {
        return Database::fetchOne(
            'SELECT id, name, username, role, active FROM users WHERE id = ?',
            [$id]
        );
    }

    /**
     * @param array{name: string, username: string, password: string, role: string, alias_email?: string, folder_name?: string} $data
     */
    public function createEmployee(array $data): int
    {
        $passwordHash = password_hash($data['password'], PASSWORD_BCRYPT);
        $role = $data['role'] ?? 'employee';

        Database::query(
            'INSERT INTO users (name, username, password_hash, role, active) VALUES (?, ?, ?, ?, 1)',
            [$data['name'], $data['username'], $passwordHash, $role]
        );

        $userId = (int) Database::connection()->lastInsertId();

        if ($role === 'employee' && !empty($data['alias_email'])) {
            $folderName = $data['folder_name'] ?? $data['username'];
            $imapPath = 'INBOX.' . preg_replace('/[^a-zA-Z0-9_-]/', '', $folderName);

            $folderService = new AdminFolderService();
            $folderId = $folderService->insertFolder([
                'imap_path' => $imapPath,
                'display_name' => $folderName,
                'folder_type' => 'employee',
                'linked_user_id' => $userId,
            ]);

            (new AdminAliasService())->create([
                'email' => $data['alias_email'],
                'display_name' => $data['name'],
                'user_id' => $userId,
                'default_folder_id' => $folderId,
                'active' => 1,
            ]);

            (new AdminRuleService())->create([
                'name' => 'Route ' . $data['alias_email'],
                'priority' => 40,
                'rule_type' => 'employee',
                'condition_field' => 'to',
                'condition_operator' => 'equals',
                'condition_value' => $data['alias_email'],
                'target_folder_id' => $folderId,
                'active' => 1,
            ]);
        }

        return $userId;
    }

    /**
     * @param array{name?: string, username?: string, password?: string, role?: string, active?: int} $data
     */
    public function update(int $id, array $data): void
    {
        if (!empty($data['password'])) {
            Database::query(
                'UPDATE users SET name = ?, username = ?, password_hash = ?, role = ?, active = ? WHERE id = ?',
                [
                    $data['name'],
                    $data['username'],
                    password_hash($data['password'], PASSWORD_BCRYPT),
                    $data['role'],
                    (int) ($data['active'] ?? 1),
                    $id,
                ]
            );
        } else {
            Database::query(
                'UPDATE users SET name = ?, username = ?, role = ?, active = ? WHERE id = ?',
                [
                    $data['name'],
                    $data['username'],
                    $data['role'],
                    (int) ($data['active'] ?? 1),
                    $id,
                ]
            );
        }
    }

    public function disable(int $id): void
    {
        Database::query('UPDATE users SET active = 0 WHERE id = ?', [$id]);
        Database::query(
            'UPDATE filter_rules r
             INNER JOIN aliases a ON r.condition_value = a.email
             SET r.active = 0
             WHERE a.user_id = ? AND r.condition_field = \'to\'',
            [$id]
        );
    }
}
