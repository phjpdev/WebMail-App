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
     * Parent-folder choices for creating a subfolder — the same admin-managed
     * folder tree the "Show under" dropdown and the admin folders table use
     * (subfolders nested under their parent), instead of dumping every system and
     * per-employee subfolder.
     *
     * @return list<array{id: int, label: string, imap_path: string, depth: int}>
     */
    public function listParentChoices(): array
    {
        return $this->listGroupParentChoices();
    }

    /**
     * Folders that can act as a sidebar group container ("Show under"): the
     * admin-created client/company group folders AND employee mailboxes (which can
     * hold subfolders), so any user folder can be nested under another for sidebar
     * organisation. Excluded are the shared system folders (Inbox, Sent, Drafts,
     * Archive, Junk, Trash, Spam), the folder being edited, and its own grouped
     * descendants (loop guard). Each employee mailbox is offered once — its two
     * INBOX.x / INBOX.x.Inbox rows are collapsed.
     *
     * @return list<array{id: int, label: string, imap_path: string, depth: int}>
     */
    public function listGroupParentChoices(int $excludeId = 0): array
    {
        $blocked = $excludeId > 0 ? $this->displayDescendantIds($excludeId) : [];
        if ($excludeId > 0) {
            $blocked[$excludeId] = true;
        }

        // Use the SAME tree the admin folders table and sidebar render — it
        // collapses each employee mailbox to one row AND applies the sidebar
        // "Show under" grouping (display_parent_id), so a folder nested under
        // another (e.g. an employee under "Employees") is indented correctly here
        // too, instead of a flat imap-path-only tree.
        $tree = partition_admin_folders_for_display($this->listAll())['custom_tree'] ?? [];

        $options = [];
        $flatten = static function (array $nodes, int $depth) use (&$flatten, &$options, $blocked): void {
            foreach ($nodes as $node) {
                $folder = $node['folder'] ?? [];
                $id = (int) ($folder['id'] ?? 0);
                // Skip the folder being edited and its whole grouped subtree
                // (loop guard) — including their nested children.
                if ($id <= 0 || isset($blocked[$id])) {
                    continue;
                }
                $options[] = [
                    'id' => $id,
                    'label' => (string) ($folder['display_name'] ?? ''),
                    'imap_path' => (string) ($folder['imap_path'] ?? ''),
                    'depth' => $depth,
                ];
                $flatten($node['children'] ?? [], $depth + 1);
            }
        };
        $flatten($tree, 0);

        return $options;
    }

    /**
     * IDs of folders grouped (directly or transitively) under the given folder.
     *
     * @return array<int, true>
     */
    private function displayDescendantIds(int $rootId): array
    {
        $children = [];
        foreach (Database::query('SELECT id, display_parent_id FROM folders')->fetchAll() as $row) {
            $parent = $row['display_parent_id'];
            if ($parent === null) {
                continue;
            }
            $children[(int) $parent][] = (int) $row['id'];
        }

        $descendants = [];
        $stack = [$rootId];
        while ($stack !== []) {
            $current = array_pop($stack);
            foreach ($children[$current] ?? [] as $childId) {
                if (!isset($descendants[$childId])) {
                    $descendants[$childId] = true;
                    $stack[] = $childId;
                }
            }
        }

        return $descendants;
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
     * Scan the mail server and register every folder that is not in the
     * registry yet. Existing rows are never touched (unique key on imap_path),
     * so re-running is always safe. Returns the imported display names.
     *
     * @return array{imported: int, names: list<string>}
     */
    public function importServerFolders(): array
    {
        $imap = new ImapService();
        if (!$imap->connect()) {
            throw new \RuntimeException(
                'Could not connect to the mail server: ' . ($imap->getLastError() ?: 'connection failed')
            );
        }

        $serverFolders = $imap->listFolders(true);
        if ($serverFolders === []) {
            throw new \RuntimeException('The mail server returned no folders.');
        }

        $result = $this->registerUnregisteredFolders($serverFolders);
        if ($result['imported'] > 0) {
            (new FolderCache())->clear();
        }

        return $result;
    }

    /**
     * Register any server folders not already in the registry. Pass a folder list
     * you already fetched (no IMAP call here). Shared by the manual "Import from
     * mail server" button and the automatic sidebar folder sync. Does NOT clear
     * the folder cache — the caller decides.
     *
     * @param list<array{path?: string, delimiter?: string}> $serverFolders
     * @return array{imported: int, names: list<string>}
     */
    public function registerUnregisteredFolders(array $serverFolders): array
    {
        // Compare against ALL registered paths (active or not) — imap_path is unique.
        $registered = [];
        foreach (Database::query('SELECT imap_path FROM folders')->fetchAll() as $row) {
            $registered[strtolower((string) $row['imap_path'])] = true;
        }

        $names = [];
        foreach ($serverFolders as $folder) {
            $path = (string) ($folder['path'] ?? '');
            if ($path === '' || isset($registered[strtolower($path)])) {
                continue;
            }

            $delimiter = ($folder['delimiter'] ?? '.') !== '' ? $folder['delimiter'] : '.';
            $segments = explode($delimiter, $path);
            $leaf = (string) end($segments);
            // Mailbox paths use hyphens where display names had spaces
            // (e.g. INBOX.John-Tran <-> "John Tran") — reverse that for display.
            $display = trim(str_replace(['-', '_'], ' ', $leaf));
            if ($display === '') {
                $display = $leaf !== '' ? $leaf : $path;
            }

            // INSERT IGNORE: this now runs from every session's background poll on
            // a shared mailbox, so two polls can race to register the same new
            // folder — the loser must be a harmless no-op, not a duplicate-key crash.
            $stmt = Database::query(
                'INSERT IGNORE INTO folders (imap_path, display_name, folder_type, active) VALUES (?, ?, ?, 1)',
                [$path, $display, $this->inferFolderType($path)]
            );
            $registered[strtolower($path)] = true;
            if ($stmt->rowCount() > 0) { // 0 = a concurrent poll already inserted it
                $names[] = $display;
            }
        }

        return ['imported' => count($names), 'names' => $names];
    }

    /**
     * Map a mailbox path onto the registry's folder_type enum, reusing the
     * same name-based detection the sidebar icons use.
     */
    private function inferFolderType(string $path): string
    {
        return match (folder_icon_type($path)) {
            'inbox' => 'inbox',
            'sent' => 'sent',
            'spam' => 'spam',
            'trash' => 'trash',
            // drafts/archive have no enum value of their own — seed data uses 'other'.
            'draft', 'archive' => 'other',
            default => 'client',
        };
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
     * @param array{display_name?: string, folder_type?: string, active?: int, display_parent_id?: int|null} $data
     */
    public function update(int $id, array $data): void
    {
        $displayParentId = null;
        if (array_key_exists('display_parent_id', $data)) {
            $displayParentId = (int) $data['display_parent_id'] > 0 ? (int) $data['display_parent_id'] : null;
            // Never allow a folder to be grouped under itself or one of its own
            // grouped descendants (that would make the sidebar tree recurse).
            if ($displayParentId === $id || ($displayParentId !== null && isset($this->displayDescendantIds($id)[$displayParentId]))) {
                $displayParentId = null;
            }
        }

        Database::query(
            'UPDATE folders SET display_name = ?, folder_type = ?, active = ?, display_parent_id = ? WHERE id = ?',
            [
                $data['display_name'],
                $data['folder_type'],
                (int) ($data['active'] ?? 1),
                $displayParentId,
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

        // A folder tied to a user account (employee mailbox) is owned by that
        // account, not the folder list. Deleting it here doesn't stick — while
        // the user exists the mailbox re-provisions on the next IMAP sync and the
        // folder reappears (the "it came back" the client hit). The only correct
        // removal is deleting the user (which cascades). Lock it down so it can't
        // be deleted, or accidentally deleted, from here.
        if ((int) ($folder['linked_user_id'] ?? 0) > 0) {
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
     * Delete a single folder while PRESERVING its subfolders — each direct
     * subfolder (with its own subtree and messages) is moved up one level to the
     * deleted folder's parent, then the target folder itself is removed. Only the
     * target folder's own messages are discarded.
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

        $path = rtrim((string) $folder['imap_path'], '.');
        $parentPath = $this->parentFolderPath($path); // '' means top level (under INBOX root)

        $imap = new ImapService();
        if (!$imap->connect()) {
            throw new \RuntimeException('Could not connect to the mail server to delete the folder.');
        }
        ImapService::invalidateFolderListCache();

        // Move every DIRECT child up one level (renaming a mailbox carries its
        // whole subtree + messages on the server), fixing the registry paths too.
        foreach ($this->directChildPaths($path, $imap) as $childPath) {
            $leaf = substr($childPath, strlen($path) + 1);
            $desired = ($parentPath === '' ? 'INBOX' : $parentPath) . '.' . $leaf;
            $newChildPath = $this->uniqueFolderPath($desired, $imap);
            if (!$imap->renameFolder($childPath, $newChildPath)) {
                throw new \RuntimeException(
                    'Could not move subfolder "' . $childPath . '" up a level: ' . $imap->getLastError()
                );
            }
            $this->reparentRegistryPaths($childPath, $newChildPath);
            ImapService::invalidateFolderListCache();
        }

        // Anything grouped under this folder in the sidebar moves up to its group.
        Database::query(
            'UPDATE folders SET display_parent_id = ? WHERE display_parent_id = ?',
            [($folder['display_parent_id'] ?? null) ?: null, $id]
        );

        // Now delete just the target folder (it no longer has subfolders).
        if (!$imap->deleteFolder($path)) {
            throw new \RuntimeException(
                'Could not delete the folder on the mail server: ' . $imap->getLastError()
            );
        }
        MailCacheService::purgeFolder($path);
        Database::query('UPDATE aliases SET default_folder_id = NULL WHERE default_folder_id = ?', [$id]);
        Database::query('DELETE FROM folders WHERE id = ?', [$id]);

        (new FolderCache())->clear();
    }

    /** Parent IMAP path, or '' for a top-level folder (INBOX.Foo -> '', INBOX.A.B -> INBOX.A). */
    private function parentFolderPath(string $path): string
    {
        $pos = strrpos($path, '.');
        if ($pos === false) {
            return '';
        }
        $parent = substr($path, 0, $pos);

        return strcasecmp($parent, 'INBOX') === 0 ? '' : $parent;
    }

    /**
     * Direct child folder paths (one level under $path), from the live server list
     * unioned with the registry so nothing is missed.
     *
     * @return list<string>
     */
    private function directChildPaths(string $path, ImapService $imap): array
    {
        $prefix = $path . '.';
        $found = [];

        foreach ($imap->listFolders() as $folder) {
            $p = (string) ($folder['path'] ?? '');
            if (strncmp($p, $prefix, strlen($prefix)) === 0 && strpos(substr($p, strlen($prefix)), '.') === false) {
                $found[$p] = true;
            }
        }
        foreach (Database::query('SELECT imap_path FROM folders WHERE imap_path LIKE ?', [$prefix . '%'])->fetchAll() as $row) {
            $p = trim((string) ($row['imap_path'] ?? ''));
            if ($p !== '' && strncmp($p, $prefix, strlen($prefix)) === 0 && strpos(substr($p, strlen($prefix)), '.') === false) {
                $found[$p] = true;
            }
        }

        return array_keys($found);
    }

    /** Append -2, -3… if the desired path already exists on the server. */
    private function uniqueFolderPath(string $desired, ImapService $imap): string
    {
        if (!$imap->folderExistsOnServer($desired)) {
            return $desired;
        }
        for ($i = 2; $i < 100; $i++) {
            $candidate = $desired . '-' . $i;
            if (!$imap->folderExistsOnServer($candidate)) {
                return $candidate;
            }
        }

        return $desired . '-' . uniqid();
    }

    /** Rewrite registry imap_path for a moved subtree ($oldPrefix and everything under it). */
    private function reparentRegistryPaths(string $oldPrefix, string $newPrefix): void
    {
        $rows = Database::query(
            'SELECT id, imap_path FROM folders WHERE imap_path = ? OR imap_path LIKE ?',
            [$oldPrefix, $oldPrefix . '.%']
        )->fetchAll();

        foreach ($rows as $row) {
            $old = (string) $row['imap_path'];
            $new = $newPrefix . substr($old, strlen($oldPrefix));
            Database::query('UPDATE folders SET imap_path = ? WHERE id = ?', [$new, (int) $row['id']]);
        }
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

        // Drop any stale cached folder list so the subtree is collected and gated
        // against the LIVE server state, not a snapshot from earlier this request.
        ImapService::invalidateFolderListCache();

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
