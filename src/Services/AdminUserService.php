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
        $columns = 'id, name, username, role, active';
        if (schema_has_column('users', 'must_change_password')) {
            $columns .= ', must_change_password';
        }

        $row = Database::fetchOne(
            "SELECT {$columns} FROM users WHERE id = ?",
            [$id]
        );

        if ($row !== null && !array_key_exists('must_change_password', $row)) {
            $row['must_change_password'] = 0;
        }

        return $row;
    }

    /**
     * @param array{name: string, username: string, password: string, role: string, alias_email?: string, folder_name?: string} $data
     */
    public function createEmployee(array $data): int
    {
        $passwordHash = password_hash($data['password'], PASSWORD_BCRYPT);
        $role = $data['role'] ?? 'employee';
        $mustChange = (int) ($data['must_change_password'] ?? 1);

        if (schema_has_column('users', 'must_change_password')) {
            Database::query(
                'INSERT INTO users (name, username, password_hash, role, active, must_change_password) VALUES (?, ?, ?, ?, 1, ?)',
                [$data['name'], $data['username'], $passwordHash, $role, $mustChange]
            );
        } else {
            Database::query(
                'INSERT INTO users (name, username, password_hash, role, active) VALUES (?, ?, ?, ?, 1)',
                [$data['name'], $data['username'], $passwordHash, $role]
            );
        }

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
        $existing = $this->find($id);
        if ($existing !== null && $existing['role'] === 'admin') {
            $data['active'] = 1;
            $data['role'] = 'admin';
        }

        $mustChange = isset($data['must_change_password']) ? (int) $data['must_change_password'] : null;
        $hasMustChangeColumn = schema_has_column('users', 'must_change_password');

        if (!empty($data['password'])) {
            $sql = 'UPDATE users SET name = ?, username = ?, password_hash = ?, role = ?, active = ?';
            $params = [
                $data['name'],
                $data['username'],
                password_hash($data['password'], PASSWORD_BCRYPT),
                $data['role'],
                (int) ($data['active'] ?? 1),
            ];
            if ($hasMustChangeColumn && $mustChange !== null) {
                $sql .= ', must_change_password = ?';
                $params[] = $mustChange;
            }
            $sql .= ' WHERE id = ?';
            $params[] = $id;
            Database::query($sql, $params);
        } else {
            $sql = 'UPDATE users SET name = ?, username = ?, role = ?, active = ?';
            $params = [
                $data['name'],
                $data['username'],
                $data['role'],
                (int) ($data['active'] ?? 1),
            ];
            if ($hasMustChangeColumn && $mustChange !== null) {
                $sql .= ', must_change_password = ?';
                $params[] = $mustChange;
            }
            $sql .= ' WHERE id = ?';
            $params[] = $id;
            Database::query($sql, $params);
        }
    }

    public function disable(int $id): bool
    {
        $user = $this->find($id);
        if ($user === null || $user['role'] === 'admin') {
            return false;
        }

        Database::query('UPDATE users SET active = 0 WHERE id = ? AND role != \'admin\'', [$id]);
        Database::query(
            'UPDATE filter_rules r
             INNER JOIN aliases a ON r.condition_value = a.email
             SET r.active = 0
             WHERE a.user_id = ? AND r.condition_field = \'to\'',
            [$id]
        );

        return true;
    }
}
