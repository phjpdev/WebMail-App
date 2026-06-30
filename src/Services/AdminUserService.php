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
            'SELECT u.id, u.name, u.username, u.role, u.active, u.created_at,
                    (SELECT a.email
                     FROM aliases a
                     WHERE a.user_id = u.id AND a.active = 1
                     ORDER BY a.id
                     LIMIT 1) AS alias_email,
                    (SELECT f.display_name
                     FROM folders f
                     WHERE f.linked_user_id = u.id AND f.folder_type = \'employee\'
                     ORDER BY f.id
                     LIMIT 1) AS folder_name
             FROM users u
             ORDER BY u.role DESC, u.name'
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
        $role = $data['role'] ?? 'employee';

        // Guard against duplicates up-front so we can return a friendly message
        // instead of a raw integrity-constraint error.
        if (Database::fetchOne('SELECT id FROM users WHERE username = ? LIMIT 1', [$data['username']]) !== null) {
            throw new \RuntimeException('That username is already taken.');
        }
        if (
            $role === 'employee'
            && !empty($data['alias_email'])
            && Database::fetchOne('SELECT id FROM aliases WHERE email = ? LIMIT 1', [$data['alias_email']]) !== null
        ) {
            throw new \RuntimeException('That email address is already assigned to another user.');
        }

        $passwordHash = password_hash($data['password'], PASSWORD_BCRYPT);
        $mustChange = (int) ($data['must_change_password'] ?? 1);

        // User + folder/alias/rule provisioning is all-or-nothing: if IMAP folder
        // creation fails we roll the whole thing back rather than leave a half
        // provisioned account.
        return Database::transaction(function () use ($data, $role, $passwordHash, $mustChange): int {
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

            if ($role === 'employee') {
                $aliasEmail = trim($data['alias_email'] ?? '');
                $folderName = trim($data['folder_name'] ?? '');
                if ($aliasEmail === '' || $folderName === '') {
                    throw new \RuntimeException('Email and folder name are required for employee accounts.');
                }
                $this->provisionEmployeeMailbox(
                    $userId,
                    $data['name'],
                    $aliasEmail,
                    $folderName,
                    $data['username']
                );
            }

            return $userId;
        });
    }

    /**
     * Idempotently ensure an employee has a personal folder, send-as alias, and routing rule.
     * Safe to call repeatedly (used for onboarding and for backfilling existing users).
     *
     * @return bool true if anything new was created
     */
    public function provisionEmployeeMailbox(
        int $userId,
        string $displayName,
        string $aliasEmail,
        ?string $folderName,
        string $username
    ): bool {
        $created = false;

        $folderName = ($folderName !== null && trim($folderName) !== '') ? trim($folderName) : $username;
        $safeFolder = preg_replace('/[^a-zA-Z0-9_-]/', '', $folderName);
        if ($safeFolder === '') {
            throw new \RuntimeException('Invalid folder name.');
        }
        $imapPath = 'INBOX.' . $safeFolder;

        $existingFolder = Database::fetchOne(
            'SELECT id FROM folders WHERE linked_user_id = ? OR imap_path = ? LIMIT 1',
            [$userId, $imapPath]
        );

        if ($existingFolder !== null) {
            $folderId = (int) $existingFolder['id'];
        } else {
            $folderId = (new AdminFolderService())->insertFolder([
                'imap_path' => $imapPath,
                'display_name' => $folderName,
                'folder_type' => 'employee',
                'linked_user_id' => $userId,
            ]);
            $created = true;
        }

        $existingAlias = Database::fetchOne(
            'SELECT id FROM aliases WHERE user_id = ? OR email = ? LIMIT 1',
            [$userId, $aliasEmail]
        );
        if ($existingAlias === null) {
            (new AdminAliasService())->create([
                'email' => $aliasEmail,
                'display_name' => $displayName,
                'user_id' => $userId,
                'default_folder_id' => $folderId,
                'active' => 1,
            ]);
            $created = true;
        }

        $existingRule = Database::fetchOne(
            "SELECT id, target_folder_id FROM filter_rules WHERE condition_field = 'to' AND condition_value = ? LIMIT 1",
            [$aliasEmail]
        );
        if ($existingRule === null) {
            (new AdminRuleService())->create([
                'name' => 'Route ' . $aliasEmail,
                'priority' => 40,
                'rule_type' => 'employee',
                'condition_field' => 'to',
                'condition_operator' => 'contains',
                'condition_value' => $aliasEmail,
                'target_folder_id' => $folderId,
                'active' => 1,
            ]);
            $created = true;
        } elseif ((int) ($existingRule['target_folder_id'] ?? 0) !== $folderId) {
            Database::query(
                'UPDATE filter_rules SET target_folder_id = ?, active = 1 WHERE id = ?',
                [$folderId, (int) $existingRule['id']]
            );
            $created = true;
        }

        FilterService::clearProcessed((string) (config('app')['filter_source_folder'] ?? 'INBOX'));

        if ($this->provisionEmployeeSubfolders($imapPath, $userId)) {
            $created = true;
        }

        $this->migrateEmployeeFolderToMessagesInbox($imapPath, $folderId);

        return $created;
    }

    private function migrateEmployeeFolderToMessagesInbox(string $rootPath, int $folderId): void
    {
        migrate_employee_folder_to_messages_inbox($rootPath, $folderId);
    }

    /**
     * Create personal Sent/Drafts/Archive/Junk/Spam/Trash folders under an employee mailbox.
     *
     * @return bool true if anything new was created
     */
    private function provisionEmployeeSubfolders(string $imapPath, int $userId): bool
    {
        $created = false;
        $folderService = new AdminFolderService();
        $subfolders = [
            ['suffix' => 'Sent', 'display_name' => 'Sent', 'folder_type' => 'sent'],
            ['suffix' => 'Drafts', 'display_name' => 'Drafts', 'folder_type' => 'other'],
            ['suffix' => 'Archive', 'display_name' => 'Archive', 'folder_type' => 'other'],
            ['suffix' => 'Junk', 'display_name' => 'Junk', 'folder_type' => 'spam'],
            ['suffix' => 'Trash', 'display_name' => 'Trash', 'folder_type' => 'trash'],
        ];

        foreach ($subfolders as $subfolder) {
            $subPath = $imapPath . '.' . $subfolder['suffix'];
            $existing = Database::fetchOne(
                'SELECT id FROM folders WHERE imap_path = ? LIMIT 1',
                [$subPath]
            );
            if ($existing !== null) {
                continue;
            }

            $folderService->insertFolder([
                'imap_path' => $subPath,
                'display_name' => $subfolder['display_name'],
                'folder_type' => $subfolder['folder_type'],
                'linked_user_id' => $userId,
            ]);
            $created = true;
        }

        if ($created) {
            (new FolderCache())->clear();
        }

        return $created;
    }

    /**
     * Provision folder/alias/rule for any employee that is missing them.
     * When a user has no alias yet, the address is derived as username@<mailbox-domain>.
     *
     * @return array{provisioned: int, skipped: int, users: list<string>}
     */
    public function backfillEmployees(): array
    {
        $mailbox = (string) (config('mail')['mailbox_email'] ?? '');
        $domain = str_contains($mailbox, '@') ? substr($mailbox, strpos($mailbox, '@') + 1) : '';

        $employees = Database::query(
            "SELECT id, name, username FROM users WHERE role = 'employee' AND active = 1"
        )->fetchAll();

        $provisioned = 0;
        $skipped = 0;
        $names = [];

        foreach ($employees as $emp) {
            $userId = (int) $emp['id'];

            $existingAlias = Database::fetchOne(
                'SELECT email FROM aliases WHERE user_id = ? AND active = 1 LIMIT 1',
                [$userId]
            );

            $aliasEmail = ($existingAlias !== null && !empty($existingAlias['email']))
                ? $existingAlias['email']
                : ($domain !== '' ? $emp['username'] . '@' . $domain : '');

            if ($aliasEmail === '') {
                $skipped++;
                continue;
            }

            if ($this->provisionEmployeeMailbox($userId, $emp['name'], $aliasEmail, null, $emp['username'])) {
                $provisioned++;
                $names[] = $emp['username'];
            } else {
                $skipped++;
            }
        }

        return ['provisioned' => $provisioned, 'skipped' => $skipped, 'users' => $names];
    }

    /**
     * @param array{name?: string, username?: string, password?: string, role?: string, active?: int, alias_email?: string, folder_name?: string} $data
     */
    public function update(int $id, array $data): void
    {
        $existing = $this->find($id);
        if ($existing === null) {
            throw new \RuntimeException('User not found.');
        }

        if ($existing['role'] === 'admin') {
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

        if (($data['role'] ?? '') === 'employee' && !empty($data['alias_email'])) {
            $this->syncEmployeeAlias($id, $data['name'], $data['username'], trim($data['alias_email']));
        }

        if (($data['role'] ?? '') === 'employee' && ($data['folder_name'] ?? '') !== '') {
            $this->syncEmployeeFolder($id, trim($data['folder_name']));
        }
    }

    /**
     * Keep the employee's linked folder in sync when renamed from the user edit form.
     */
    private function syncEmployeeFolder(int $userId, string $folderName): void
    {
        $folderName = trim($folderName);
        if ($folderName === '') {
            throw new \RuntimeException('Folder name is required for employee accounts.');
        }

        $safeFolder = preg_replace('/[^a-zA-Z0-9_-]/', '', $folderName);
        if ($safeFolder === '') {
            throw new \RuntimeException('Invalid folder name.');
        }
        $newImapPath = 'INBOX.' . $safeFolder;

        $folder = Database::fetchOne(
            "SELECT id, imap_path, display_name FROM folders WHERE linked_user_id = ? AND folder_type = 'employee' ORDER BY id LIMIT 1",
            [$userId]
        );

        if ($folder === null) {
            $user = $this->find($userId);
            if ($user === null) {
                throw new \RuntimeException('User not found.');
            }
            $alias = Database::fetchOne(
                'SELECT email FROM aliases WHERE user_id = ? ORDER BY id LIMIT 1',
                [$userId]
            );
            $aliasEmail = (string) ($alias['email'] ?? '');
            if ($aliasEmail === '') {
                throw new \RuntimeException('Employee has no email alias; cannot create folder.');
            }
            $this->provisionEmployeeMailbox($userId, $user['name'], $aliasEmail, $folderName, $user['username']);

            return;
        }

        $folderId = (int) $folder['id'];
        $oldImapPath = (string) $folder['imap_path'];
        $oldDisplayName = (string) $folder['display_name'];

        if ($oldImapPath === $newImapPath && $oldDisplayName === $folderName) {
            return;
        }

        $conflict = Database::fetchOne(
            'SELECT id FROM folders WHERE imap_path = ? AND id != ? LIMIT 1',
            [$newImapPath, $folderId]
        );
        if ($conflict !== null) {
            throw new \RuntimeException('A folder with that name already exists. Choose a different folder name.');
        }

        Database::transaction(function () use ($folderId, $oldImapPath, $newImapPath, $folderName): void {
            if ($oldImapPath !== $newImapPath) {
                $imap = new ImapService();
                if (!$imap->connect()) {
                    throw new \RuntimeException('Could not connect to the mail server to rename the folder.');
                }
                if ($imap->folderExistsOnServer($oldImapPath)) {
                    if (!$imap->renameFolder($oldImapPath, $newImapPath)) {
                        throw new \RuntimeException(
                            'Could not rename the folder on the mail server: ' . $imap->getLastError()
                        );
                    }
                } elseif (!$imap->folderExistsOnServer($newImapPath) && !$imap->createFolder($newImapPath)) {
                    throw new \RuntimeException(
                        'Could not create the folder on the mail server: ' . $imap->getLastError()
                    );
                }
                MailCacheService::renameFolderPath($oldImapPath, $newImapPath);
            }

            Database::query(
                'UPDATE folders SET imap_path = ?, display_name = ? WHERE id = ?',
                [$newImapPath, $folderName, $folderId]
            );
        });

        (new FolderCache())->clear();
    }

    /**
     * Keep the employee's send-as alias and routing rule in sync when their
     * email or display name changes from the user edit form.
     */
    private function syncEmployeeAlias(int $userId, string $displayName, string $username, string $newEmail): void
    {
        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Invalid email address.');
        }

        $taken = Database::fetchOne(
            'SELECT id FROM aliases WHERE email = ? AND (user_id IS NULL OR user_id != ?) LIMIT 1',
            [$newEmail, $userId]
        );
        if ($taken !== null) {
            throw new \RuntimeException('That email address is already assigned to another user.');
        }

        $alias = Database::fetchOne(
            'SELECT id, email FROM aliases WHERE user_id = ? ORDER BY id LIMIT 1',
            [$userId]
        );

        if ($alias === null) {
            $this->provisionEmployeeMailbox($userId, $displayName, $newEmail, null, $username);

            return;
        }

        $oldEmail = (string) $alias['email'];
        $aliasId = (int) $alias['id'];

        Database::transaction(function () use ($aliasId, $oldEmail, $newEmail, $displayName): void {
            Database::query(
                'UPDATE aliases SET email = ?, display_name = ? WHERE id = ?',
                [$newEmail, $displayName, $aliasId]
            );

            if (strcasecmp($oldEmail, $newEmail) !== 0) {
                Database::query(
                    "UPDATE filter_rules
                     SET name = ?, condition_value = ?
                     WHERE condition_field = 'to' AND condition_value = ?",
                    ['Route ' . $newEmail, $newEmail, $oldEmail]
                );
            }
        });
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

    /**
     * @return list<int>
     */
    private function collectUserFolderIds(int $userId): array
    {
        $ids = [];

        $linked = Database::query(
            'SELECT id FROM folders WHERE linked_user_id = ?',
            [$userId]
        )->fetchAll();
        foreach ($linked as $row) {
            $ids[] = (int) $row['id'];
        }

        $viaAlias = Database::query(
            'SELECT DISTINCT default_folder_id AS id
             FROM aliases
             WHERE user_id = ? AND default_folder_id IS NOT NULL',
            [$userId]
        )->fetchAll();
        foreach ($viaAlias as $row) {
            $ids[] = (int) $row['id'];
        }

        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }

    /**
     * Permanently remove an employee account and clean up their alias, rules, and folders.
     * Admins and the currently logged-in user cannot be deleted.
     */
    public function delete(int $id, int $actingUserId = 0): bool
    {
        $user = $this->find($id);
        if ($user === null || $user['role'] === 'admin') {
            return false;
        }
        if ($actingUserId > 0 && $id === $actingUserId) {
            return false;
        }

        $emails = mail_user_emails($id);
        $folderService = new AdminFolderService();
        $mailboxRoots = $folderService->mailboxRootsForUser($id);

        Database::transaction(function () use ($id, $emails, $mailboxRoots, $folderService): void {
            $aliases = Database::query(
                'SELECT id, email FROM aliases WHERE user_id = ?',
                [$id]
            )->fetchAll();

            foreach ($aliases as $alias) {
                Database::query(
                    "DELETE FROM filter_rules WHERE condition_field = 'to' AND condition_value = ?",
                    [$alias['email']]
                );
                Database::query('DELETE FROM aliases WHERE id = ?', [(int) $alias['id']]);
            }

            MailCacheService::purgeMessagesForUser($id, $emails, $mailboxRoots);
            $folderService->purgeUserMailboxTree($id);

            Database::query('DELETE FROM users WHERE id = ? AND role != \'admin\'', [$id]);
        });

        (new FolderCache())->clear();

        return true;
    }
}
