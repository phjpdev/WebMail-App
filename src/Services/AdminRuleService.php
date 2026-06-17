<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;

class AdminRuleService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function listAll(?string $type = null): array
    {
        $sql = 'SELECT r.*, f.display_name AS folder_name, f.imap_path
             FROM filter_rules r
             INNER JOIN folders f ON r.target_folder_id = f.id';
        $params = [];

        if ($type !== null && $type !== '') {
            $sql .= ' WHERE r.rule_type = ?';
            $params[] = $type;
        }

        $sql .= ' ORDER BY r.priority ASC, r.id ASC';

        return Database::query($sql, $params)->fetchAll();
    }

    /**
     * @param list<array{id: int, priority: int}> $order
     */
    public function reorder(array $order): void
    {
        Database::transaction(function () use ($order): void {
            foreach ($order as $item) {
                Database::query(
                    'UPDATE filter_rules SET priority = ? WHERE id = ?',
                    [(int) $item['priority'], (int) $item['id']]
                );
            }
        });
    }

    public function find(int $id): ?array
    {
        return Database::fetchOne('SELECT * FROM filter_rules WHERE id = ?', [$id]);
    }

    public function delete(int $id): void
    {
        Database::query('DELETE FROM filter_rules WHERE id = ?', [$id]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        Database::query(
            'INSERT INTO filter_rules (name, priority, active, rule_type, condition_field, condition_operator, condition_value, target_folder_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $data['name'],
                (int) $data['priority'],
                (int) ($data['active'] ?? 1),
                $data['rule_type'],
                $data['condition_field'],
                $data['condition_operator'],
                $data['condition_value'],
                (int) $data['target_folder_id'],
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
            'UPDATE filter_rules SET name = ?, priority = ?, active = ?, rule_type = ?,
             condition_field = ?, condition_operator = ?, condition_value = ?, target_folder_id = ?
             WHERE id = ?',
            [
                $data['name'],
                (int) $data['priority'],
                (int) ($data['active'] ?? 1),
                $data['rule_type'],
                $data['condition_field'],
                $data['condition_operator'],
                $data['condition_value'],
                (int) $data['target_folder_id'],
                $id,
            ]
        );
    }

    public function toggle(int $id): void
    {
        Database::query(
            'UPDATE filter_rules SET active = IF(active = 1, 0, 1) WHERE id = ?',
            [$id]
        );
    }
}
