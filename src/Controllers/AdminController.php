<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Database;
use App\Services\AdminAliasService;
use App\Services\AdminFolderService;
use App\Services\AdminRuleService;
use App\Services\AdminUserService;
use App\Services\FilterService;
use App\Services\FolderCache;

class AdminController
{
    private AdminUserService $users;
    private AdminAliasService $aliases;
    private AdminFolderService $folders;
    private AdminRuleService $rules;

    public function __construct()
    {
        $this->users = new AdminUserService();
        $this->aliases = new AdminAliasService();
        $this->folders = new AdminFolderService();
        $this->rules = new AdminRuleService();
    }

    public function dashboard(): void
    {
        requireAdmin();

        $userCount = (int) Database::fetchOne('SELECT COUNT(*) AS c FROM users WHERE active = 1')['c'];
        $ruleCount = (int) Database::fetchOne('SELECT COUNT(*) AS c FROM filter_rules WHERE active = 1')['c'];
        $activeFolders = array_values(array_filter(
            $this->folders->listAll(),
            static fn (array $f): bool => (int) ($f['active'] ?? 0) === 1
        ));
        $folderCount = admin_display_folder_count($activeFolders);

        $this->render('admin/dashboard', [
            'title' => 'Admin',
            'userCount' => $userCount,
            'ruleCount' => $ruleCount,
            'folderCount' => $folderCount,
            'filterStats' => FilterService::lastStats(),
            'adminSection' => 'dashboard',
        ]);
    }

    public function auditIndex(): void
    {
        requireAdmin();
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $action = trim($_GET['action'] ?? '');
        $perPage = 50;
        $offset = ($page - 1) * $perPage;

        $where = '';
        $params = [];
        if ($action !== '') {
            $where = 'WHERE a.action = ?';
            $params[] = $action;
        }

        $total = (int) Database::fetchOne(
            "SELECT COUNT(*) AS c FROM audit_log a {$where}",
            $params
        )['c'];

        $rows = Database::query(
            "SELECT a.*, u.name AS user_name, u.username
             FROM audit_log a
             LEFT JOIN users u ON a.user_id = u.id
             {$where}
             ORDER BY a.created_at DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        )->fetchAll();

        $this->render('admin/audit/index', [
            'title' => 'Audit log',
            'entries' => $rows,
            'page' => $page,
            'totalPages' => (int) max(1, ceil($total / $perPage)),
            'totalMessages' => $total,
            'filterAction' => $action,
            'adminSection' => 'audit',
        ]);
    }

    public function sync(): void
    {
        requireAdmin();
        verify_csrf_or_fail();
        FilterService::resetThrottle();
        FilterService::runBackground(true);
        flash('success', 'Mail sync completed.');
        redirect('admin');
    }

    public function usersIndex(): void
    {
        requireAdmin();
        $this->render('admin/users/index', [
            'title' => 'Users',
            'users' => $this->users->listAll(),
            'adminSection' => 'users',
        ]);
    }

    public function usersCreate(): void
    {
        requireAdmin();
        $this->render('admin/users/form', [
            'title' => 'Add user',
            'editUser' => null,
            'groupParents' => $this->folders->listGroupParentChoices(),
            'adminSection' => 'users',
        ]);
    }

    public function usersStore(): void
    {
        requireAdmin();
        verify_csrf_or_fail();
        $data = $this->userFormData();
        if ($data['name'] === '' || $data['username'] === '' || $data['password'] === '') {
            flash('error', 'Name, username, and password are required.');
            redirect('admin/users/create');
        }

        if (($data['role'] ?? 'employee') === 'employee') {
            if (($data['alias_email'] ?? '') === '' || !filter_var($data['alias_email'], FILTER_VALIDATE_EMAIL)) {
                flash('error', 'A valid email address is required for employee accounts.');
                redirect('admin/users/create');
            }
            $folderName = trim($data['folder_name'] ?? '');
            if ($folderName === '') {
                flash('error', 'A folder name is required for employee accounts.');
                redirect('admin/users/create');
            }
            // Accept spaces just like the Add-folder form — they become hyphens
            // in the mailbox path (e.g. "John Tran" -> INBOX.John-Tran). Only fail
            // if nothing usable remains after normalising.
            $folderSegment = folder_imap_segment($folderName);
            if ($folderSegment === '') {
                flash('error', 'Folder name must contain at least one letter or number.');
                redirect('admin/users/create');
            }
            $imapPath = 'INBOX.' . $folderSegment;
            if (Database::fetchOne('SELECT id FROM folders WHERE imap_path = ? LIMIT 1', [$imapPath]) !== null) {
                flash('error', 'A folder with that name already exists. Choose a different folder name.');
                redirect('admin/users/create');
            }
        }

        try {
            $this->users->createEmployee($data);
        } catch (\Throwable $e) {
            app_log('User create failed: ' . $e->getMessage());
            flash('error', $e->getMessage() ?: 'Could not create the user.');
            redirect('admin/users/create');
        }

        if (($data['role'] ?? 'employee') === 'employee') {
            FilterService::reprocess();
            $this->audit('user_create', 'Created user ' . $data['username']);
            flash(
                'success',
                'User created with folder, alias, and filter rule. Existing Inbox mail will route on the next mailbox sync.'
            );
        } else {
            $this->audit('user_create', 'Created user ' . $data['username']);
            flash('success', 'User created.');
        }
        redirect('admin/users');
    }

    /**
     * @param array<string, string> $params
     */
    public function usersEdit(array $params): void
    {
        requireAdmin();
        $id = (int) ($params['id'] ?? 0);
        $user = $this->users->find($id);
        if ($user === null) {
            flash('error', 'User not found.');
            redirect('admin/users');
        }

        if ($user['role'] === 'employee') {
            $alias = Database::fetchOne(
                'SELECT email FROM aliases WHERE user_id = ? ORDER BY id LIMIT 1',
                [$id]
            );
            $user['alias_email'] = $alias['email'] ?? '';

            $folder = Database::fetchOne(
                "SELECT display_name FROM folders WHERE linked_user_id = ? AND folder_type = 'employee' ORDER BY id LIMIT 1",
                [$id]
            );
            $user['folder_name'] = $folder['display_name'] ?? '';
        }

        $this->render('admin/users/form', [
            'title' => 'Edit user',
            'editUser' => $user,
            'adminSection' => 'users',
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function usersUpdate(array $params): void
    {
        requireAdmin();
        verify_csrf_or_fail();
        $id = (int) ($params['id'] ?? 0);
        if ($this->users->find($id) === null) {
            flash('error', 'User not found.');
            redirect('admin/users');
        }
        $data = $this->userFormData();
        if ($data['name'] === '' || $data['username'] === '') {
            flash('error', 'Name and username are required.');
            redirect('admin/users/' . $id . '/edit');
        }
        $dupe = Database::fetchOne('SELECT id FROM users WHERE username = ? AND id != ? LIMIT 1', [$data['username'], $id]);
        if ($dupe !== null) {
            flash('error', 'That username is already taken.');
            redirect('admin/users/' . $id . '/edit');
        }

        if (($data['role'] ?? 'employee') === 'employee') {
            if (($data['alias_email'] ?? '') === '' || !filter_var($data['alias_email'], FILTER_VALIDATE_EMAIL)) {
                flash('error', 'A valid email address is required for employee accounts.');
                redirect('admin/users/' . $id . '/edit');
            }
            $folderName = trim($data['folder_name'] ?? '');
            if ($folderName === '') {
                flash('error', 'A folder name is required for employee accounts.');
                redirect('admin/users/' . $id . '/edit');
            }
            // Accept spaces (converted to hyphens) exactly like the Add-folder form.
            $folderSegment = folder_imap_segment($folderName);
            if ($folderSegment === '') {
                flash('error', 'Folder name must contain at least one letter or number.');
                redirect('admin/users/' . $id . '/edit');
            }
            $imapPath = 'INBOX.' . $folderSegment;
            $folderConflict = Database::fetchOne(
                'SELECT id FROM folders WHERE imap_path = ? AND (linked_user_id IS NULL OR linked_user_id != ?) LIMIT 1',
                [$imapPath, $id]
            );
            if ($folderConflict !== null) {
                flash('error', 'A folder with that name already exists. Choose a different folder name.');
                redirect('admin/users/' . $id . '/edit');
            }
        }

        try {
            $this->users->update($id, $data);
        } catch (\Throwable $e) {
            app_log('User update failed: ' . $e->getMessage());
            flash('error', $e->getMessage() ?: 'Could not update the user.');
            redirect('admin/users/' . $id . '/edit');
        }

        if (($data['role'] ?? 'employee') === 'employee') {
            FilterService::reprocess();
            FilterService::runBackground(true);
        }
        $this->audit('user_update', 'Updated user #' . $id);
        flash('success', 'User updated.');
        redirect('admin/users');
    }

    /**
     * @param array<string, string> $params
     */
    public function usersDisable(array $params): void
    {
        requireAdmin();
        verify_csrf_or_fail();
        $id = (int) ($params['id'] ?? 0);
        if (!$this->users->disable($id)) {
            flash('error', 'Admin accounts cannot be disabled.');
            redirect('admin/users');
        }
        $this->audit('user_disable', 'Disabled user #' . $id);
        flash('success', 'User disabled.');
        redirect('admin/users');
    }

    /**
     * @param array<string, string> $params
     */
    public function usersDelete(array $params): void
    {
        requireAdmin();
        verify_csrf_or_fail();
        $id = (int) ($params['id'] ?? 0);
        $actingId = (int) (Auth::user()['id'] ?? 0);

        if (!$this->users->delete($id, $actingId)) {
            flash('error', 'This user cannot be deleted (admin account or your own account).');
            redirect('admin/users');
        }

        FilterService::reprocess();
        $this->audit('user_delete', 'Deleted user #' . $id);
        flash('success', 'User, mailbox, and related messages deleted.');
        redirect('admin/users');
    }

    public function aliasesIndex(): void
    {
        requireAdmin();
        $this->render('admin/aliases/index', [
            'title' => 'Aliases',
            'aliases' => $this->aliases->listAll(),
            'adminSection' => 'aliases',
        ]);
    }

    public function aliasesCreate(): void
    {
        requireAdmin();
        $this->render('admin/aliases/form', [
            'title' => 'Add alias',
            'alias' => null,
            'users' => $this->users->listAll(),
            'folders' => $this->folders->listAll(),
            'adminSection' => 'aliases',
        ]);
    }

    public function aliasesStore(): void
    {
        requireAdmin();
        verify_csrf_or_fail();
        $data = $this->aliasFormData();
        if ($data['email'] === '' || $data['display_name'] === '') {
            flash('error', 'Email and display name are required.');
            redirect('admin/aliases/create');
        }
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Please enter a valid email address.');
            redirect('admin/aliases/create');
        }
        if ($this->aliases->findByEmail($data['email']) !== null) {
            flash('error', 'An alias with that email already exists.');
            redirect('admin/aliases/create');
        }
        $newId = $this->aliases->create($data);
        FilterService::reprocess();
        $this->audit('alias_create', 'Created alias #' . $newId . ' ' . $data['email']);
        flash('success', 'Alias created.');
        redirect('admin/aliases');
    }

    /**
     * @param array<string, string> $params
     */
    public function aliasesEdit(array $params): void
    {
        requireAdmin();
        $alias = $this->aliases->find((int) ($params['id'] ?? 0));
        if ($alias === null) {
            flash('error', 'Alias not found.');
            redirect('admin/aliases');
        }
        $this->render('admin/aliases/form', [
            'title' => 'Edit alias',
            'alias' => $alias,
            'users' => $this->users->listAll(),
            'folders' => $this->folders->listAll(),
            'adminSection' => 'aliases',
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function aliasesUpdate(array $params): void
    {
        requireAdmin();
        verify_csrf_or_fail();
        $id = (int) ($params['id'] ?? 0);
        if ($this->aliases->find($id) === null) {
            flash('error', 'Alias not found.');
            redirect('admin/aliases');
        }
        $data = $this->aliasFormData();
        if ($data['email'] === '' || !filter_var($data['email'], FILTER_VALIDATE_EMAIL) || $data['display_name'] === '') {
            flash('error', 'A valid email and display name are required.');
            redirect('admin/aliases/' . $id . '/edit');
        }
        $existing = $this->aliases->findByEmail($data['email']);
        if ($existing !== null && (int) $existing['id'] !== $id) {
            flash('error', 'Another alias already uses that email.');
            redirect('admin/aliases/' . $id . '/edit');
        }
        $this->aliases->update($id, $data);
        FilterService::reprocess();
        $this->audit('alias_update', 'Updated alias #' . $id);
        flash('success', 'Alias updated.');
        redirect('admin/aliases');
    }

    /**
     * @param array<string, string> $params
     */
    public function aliasesDelete(array $params): void
    {
        requireAdmin();
        verify_csrf_or_fail();
        $id = (int) ($params['id'] ?? 0);
        if ($this->aliases->find($id) === null) {
            flash('error', 'Alias not found.');
            redirect('admin/aliases');
        }
        $this->aliases->delete($id);
        FilterService::reprocess();
        $this->audit('alias_delete', 'Deleted alias #' . $id);
        flash('success', 'Alias deleted.');
        redirect('admin/aliases');
    }

    public function foldersIndex(): void
    {
        requireAdmin();
        $this->render('admin/folders/index', [
            'title' => 'Folders',
            'folders' => $this->folders->listAll(),
            'adminSection' => 'folders',
        ]);
    }

    public function foldersCreate(): void
    {
        requireAdmin();
        $this->render('admin/folders/form', [
            'title' => 'Add folder',
            'folder' => null,
            'parentFolders' => $this->folders->listParentChoices(),
            'adminSection' => 'folders',
        ]);
    }

    public function foldersStore(): void
    {
        requireAdmin();
        verify_csrf_or_fail();
        $displayName = trim($_POST['display_name'] ?? '');
        $folderType = $this->normalizeFolderType($_POST['folder_type'] ?? 'client');
        if ($displayName === '') {
            flash('error', 'Display name is required.');
            redirect('admin/folders/create');
        }

        try {
            $newId = $this->folders->createClientFolder([
                'display_name' => $displayName,
                'folder_type' => $folderType,
                'parent_folder_id' => (int) ($_POST['parent_folder_id'] ?? 0),
                'create_rule' => isset($_POST['create_rule']),
                'rule_field' => $_POST['rule_field'] ?? 'subject',
                'rule_operator' => $_POST['rule_operator'] ?? 'contains',
                'rule_value' => trim($_POST['rule_value'] ?? ''),
            ]);
        } catch (\Throwable $e) {
            app_log('Folder create failed: ' . $e->getMessage());
            flash('error', $e->getMessage() ?: 'Could not create the folder.');
            redirect('admin/folders/create');
        }

        FilterService::reprocess();
        $this->audit('folder_create', 'Created folder #' . $newId . ' ' . $displayName);
        flash('success', 'Folder created.');
        redirect('admin/folders');
    }

    /**
     * @param array<string, string> $params
     */
    public function foldersEdit(array $params): void
    {
        requireAdmin();
        $folder = $this->folders->find((int) ($params['id'] ?? 0));
        if ($folder === null) {
            flash('error', 'Folder not found.');
            redirect('admin/folders');
        }
        $this->render('admin/folders/form', [
            'title' => 'Edit folder',
            'folder' => $folder,
            'groupParents' => $this->folders->listGroupParentChoices((int) $folder['id']),
            'adminSection' => 'folders',
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function foldersUpdate(array $params): void
    {
        requireAdmin();
        verify_csrf_or_fail();
        $id = (int) ($params['id'] ?? 0);
        $folder = $this->folders->find($id);
        if ($folder === null) {
            flash('error', 'Folder not found.');
            redirect('admin/folders');
        }
        $displayName = trim($_POST['display_name'] ?? '');
        if ($displayName === '') {
            flash('error', 'Display name is required.');
            redirect('admin/folders/' . $id . '/edit');
        }
        $this->folders->update($id, [
            'display_name' => $displayName,
            // Folder type is no longer an admin-editable field — keep whatever the
            // folder already has (employee mailboxes stay 'employee', etc.).
            'folder_type' => (string) ($folder['folder_type'] ?? 'client'),
            'active' => isset($_POST['active']) ? 1 : 0,
            'display_parent_id' => (int) ($_POST['display_parent_id'] ?? 0),
        ]);
        $this->audit('folder_update', 'Updated folder #' . $id);
        flash('success', 'Folder updated.');
        redirect('admin/folders');
    }

    /**
     * @param array<string, string> $params
     */
    public function foldersDelete(array $params): void
    {
        requireAdmin();
        verify_csrf_or_fail();
        releaseSessionLock();

        $id = (int) ($params['id'] ?? 0);
        $folder = $this->folders->find($id);
        if ($folder === null) {
            flash('error', 'Folder not found.');
            redirect('admin/folders');
        }

        if (admin_folder_is_user_owned($folder)) {
            $owner = Database::fetchOne('SELECT name FROM users WHERE id = ? LIMIT 1', [(int) $folder['linked_user_id']]);
            $ownerName = trim((string) ($owner['name'] ?? ''));
            flash('error', 'This folder belongs to ' . ($ownerName !== '' ? $ownerName . '\'s' : 'a user') . ' account and can\'t be deleted here. To remove it, delete the user under Admin → Users.');
            redirect('admin/folders');
        }

        if (!$this->folders->isDeletable($folder)) {
            flash('error', 'This folder cannot be deleted. Inbox, Sent, Drafts, Trash, Spam, and other system folders are protected.');
            redirect('admin/folders');
        }

        try {
            $label = (string) ($folder['display_name'] ?? $folder['imap_path'] ?? '');
            $this->folders->delete($id);
            $this->audit('folder_delete', 'Deleted folder #' . $id . ' ' . $label);
            flash('success', 'Folder deleted.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }

        redirect('admin/folders');
    }

    public function foldersPurgeOrphans(): void
    {
        requireAdmin();
        verify_csrf_or_fail();
        releaseSessionLock();

        try {
            $removed = $this->folders->purgeOrphanedEmployeeMailboxes();
            if ($removed > 0) {
                $this->audit('folder_purge_orphans', 'Removed ' . $removed . ' orphaned employee mailbox tree(s)');
                flash('success', $removed . ' orphaned employee mailbox(es) removed from the server and folder list.');
            } else {
                flash('success', 'No orphaned employee mailboxes found.');
            }
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }

        redirect('admin/folders');
    }

    private function normalizeFolderType(string $type): string
    {
        $allowed = ['client', 'company', 'employee', 'system'];

        return in_array($type, $allowed, true) ? $type : 'client';
    }

    public function rulesIndex(): void
    {
        requireAdmin();
        $type = trim($_GET['type'] ?? '');
        $this->render('admin/rules/index', [
            'title' => $type === 'spam' ? 'Spam rules' : 'Filter rules',
            'rules' => $this->rules->listAll($type !== '' ? $type : null),
            'ruleType' => $type,
            'adminSection' => $type === 'spam' ? 'spam-rules' : 'rules',
        ]);
    }

    public function rulesCreate(): void
    {
        requireAdmin();
        $this->render('admin/rules/form', [
            'title' => 'Add rule',
            'rule' => null,
            'folders' => $this->folders->listAll(),
            'adminSection' => 'rules',
        ]);
    }

    public function rulesStore(): void
    {
        requireAdmin();
        verify_csrf_or_fail();
        $data = $this->ruleFormData();
        if ($data['name'] === '' || $data['condition_value'] === '') {
            flash('error', 'Name and condition value are required.');
            redirect('admin/rules/create');
        }
        $newId = $this->rules->create($data);
        FilterService::reprocess();
        $this->audit('rule_create', 'Created rule #' . $newId . ' ' . $data['name']);
        flash('success', 'Rule created. Mail will be re-organized on next inbox visit.');
        redirect('admin/rules' . ($data['rule_type'] === 'spam' ? '?type=spam' : ''));
    }

    /**
     * @param array<string, string> $params
     */
    public function rulesEdit(array $params): void
    {
        requireAdmin();
        $rule = $this->rules->find((int) ($params['id'] ?? 0));
        if ($rule === null) {
            flash('error', 'Rule not found.');
            redirect('admin/rules');
        }
        $this->render('admin/rules/form', [
            'title' => 'Edit rule',
            'rule' => $rule,
            'folders' => $this->folders->listAll(),
            'adminSection' => 'rules',
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function rulesUpdate(array $params): void
    {
        requireAdmin();
        verify_csrf_or_fail();
        $id = (int) ($params['id'] ?? 0);
        if ($this->rules->find($id) === null) {
            flash('error', 'Rule not found.');
            redirect('admin/rules');
        }
        $this->rules->update($id, $this->ruleFormData());
        FilterService::reprocess();
        $this->audit('rule_update', 'Updated rule #' . $id);
        flash('success', 'Rule updated. Mail will be re-organized on next inbox visit.');
        redirect('admin/rules');
    }

    /**
     * @param array<string, string> $params
     */
    public function rulesToggle(array $params): void
    {
        requireAdmin();
        verify_csrf_or_fail();
        $id = (int) ($params['id'] ?? 0);
        if ($this->rules->find($id) === null) {
            flash('error', 'Rule not found.');
            redirect('admin/rules');
        }
        $this->rules->toggle($id);
        FilterService::reprocess();
        $this->audit('rule_toggle', 'Toggled rule #' . $id);
        flash('success', 'Rule status toggled. Mail will be re-organized on next inbox visit.');
        redirect('admin/rules');
    }

    public function rulesReorder(): void
    {
        requireAdmin();
        verify_csrf_or_fail();

        $raw = $_POST['order'] ?? '[]';
        $order = json_decode($raw, true);
        if (!is_array($order)) {
            flash('error', 'Invalid reorder data.');
            redirect('admin/rules');
        }

        $this->rules->reorder($order);
        FilterService::reprocess();
        $this->audit('rule_reorder', 'Reordered ' . count($order) . ' rule(s)');
        flash('success', 'Rule order updated.');
        redirect('admin/rules');
    }

    /**
     * @param array<string, string> $params
     */
    public function rulesDelete(array $params): void
    {
        requireAdmin();
        verify_csrf_or_fail();
        $id = (int) ($params['id'] ?? 0);
        if ($this->rules->find($id) === null) {
            flash('error', 'Rule not found.');
            redirect('admin/rules');
        }
        $this->rules->delete($id);
        FilterService::reprocess();
        $this->audit('rule_delete', 'Deleted rule #' . $id);
        flash('success', 'Rule deleted.');
        redirect('admin/rules');
    }

    /**
     * @return array<string, mixed>
     */
    private function userFormData(): array
    {
        return [
            'name' => trim($_POST['name'] ?? ''),
            'username' => trim($_POST['username'] ?? ''),
            'password' => $_POST['password'] ?? '',
            'role' => $_POST['role'] ?? 'employee',
            'alias_email' => trim($_POST['alias_email'] ?? ''),
            'folder_name' => trim($_POST['folder_name'] ?? ''),
            'display_parent_id' => (int) ($_POST['display_parent_id'] ?? 0),
            'signature' => trim($_POST['signature'] ?? ''),
            'active' => isset($_POST['active']) ? 1 : 0,
            'must_change_password' => isset($_POST['must_change_password']) ? 1 : 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function aliasFormData(): array
    {
        return [
            'email' => trim($_POST['email'] ?? ''),
            'display_name' => trim($_POST['display_name'] ?? ''),
            'user_id' => (int) ($_POST['user_id'] ?? 0) ?: null,
            'default_folder_id' => (int) ($_POST['default_folder_id'] ?? 0) ?: null,
            'active' => isset($_POST['active']) ? 1 : 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ruleFormData(): array
    {
        return [
            'name' => trim($_POST['name'] ?? ''),
            'priority' => (int) ($_POST['priority'] ?? 100),
            'active' => isset($_POST['active']) ? 1 : 0,
            'rule_type' => $_POST['rule_type'] ?? 'client',
            'condition_field' => $_POST['condition_field'] ?? 'subject',
            'condition_operator' => $_POST['condition_operator'] ?? 'contains',
            'condition_value' => trim($_POST['condition_value'] ?? ''),
            'target_folder_id' => (int) ($_POST['target_folder_id'] ?? 0),
        ];
    }

    private function audit(string $action, string $details): void
    {
        try {
            Database::query(
                'INSERT INTO audit_log (user_id, action, details) VALUES (?, ?, ?)',
                [Auth::user()['id'] ?? null, $action, $details]
            );
        } catch (\Throwable $e) {
            app_log('Audit log failed (' . $action . '): ' . $e->getMessage());
        }
    }

    private function render(string $view, array $data): void
    {
        $data['authUser'] = Auth::user();
        $data['sessionUser'] = $data['authUser'];
        $data['user'] = $data['authUser'];
        $folderData = FolderCache::load(skipUnreadRefresh: true);
        $data['sidebarFolders'] = $folderData['folders'];
        $data['unreadCounts'] = $folderData['unread_counts'] ?? [];
        // Do not overwrite $data['folders'] — admin views use it for the DB folder
        // registry (display_name, imap_path, …). The sidebar uses $sidebarFolders.
        $data['activeFolder'] = '__admin__';
        $data['bodyClass'] = 'admin-shell';
        $data['success'] = flash('success');
        $data['error'] = flash('error');
        $data['prefs'] = user_preferences();

        view($view, $data);
    }
}
