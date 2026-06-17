<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;

class AdminAliasService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function listAll(): array
    {
        return Database::query(
            'SELECT a.*, u.name AS user_name, f.display_name AS folder_name
             FROM aliases a
             LEFT JOIN users u ON a.user_id = u.id
             LEFT JOIN folders f ON a.default_folder_id = f.id
             ORDER BY a.display_name'
        )->fetchAll();
    }

    public function find(int $id): ?array
    {
        return Database::fetchOne('SELECT * FROM aliases WHERE id = ?', [$id]);
    }

    public function findByEmail(string $email): ?array
    {
        return Database::fetchOne('SELECT * FROM aliases WHERE email = ? LIMIT 1', [$email]);
    }

    public function delete(int $id): void
    {
        Database::query('DELETE FROM aliases WHERE id = ?', [$id]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        Database::query(
            'INSERT INTO aliases (email, display_name, user_id, default_folder_id, active) VALUES (?, ?, ?, ?, ?)',
            [
                $data['email'],
                $data['display_name'],
                $data['user_id'] ?: null,
                $data['default_folder_id'] ?: null,
                (int) ($data['active'] ?? 1),
            ]
        );

        return (int) Database::connection()->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        Database::query(
            'UPDATE aliases SET email = ?, display_name = ?, user_id = ?, default_folder_id = ?, active = ? WHERE id = ?',
            [
                $data['email'],
                $data['display_name'],
                $data['user_id'] ?: null,
                $data['default_folder_id'] ?: null,
                (int) ($data['active'] ?? 1),
                $id,
            ]
        );
    }
}
