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
        $folderCount = (int) Database::fetchOne('SELECT COUNT(*) AS c FROM folders WHERE active = 1')['c'];

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
        FilterService::clearSessionFlag();
        FilterService::runIfNeeded(true);
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
        }

        $this->users->createEmployee($data);
        flash(
            'success',
            ($data['role'] ?? 'employee') === 'employee'
                ? 'User created with folder, alias, and filter rule.'
                : 'User created.'
        );
        redirect('admin/users');
    }

    public function usersBackfill(): void
    {
        requireAdmin();
        verify_csrf_or_fail();

        $result = $this->users->backfillEmployees();

        // Re-run routing so existing INBOX mail lands in the new folders.
        FilterService::clearSessionFlag();
        $filter = FilterService::runIfNeeded(true);

        $moved = $filter['moved'] ?? 0;
        flash(
            'success',
            sprintf(
                'Backfill complete: %d employee(s) provisioned, %d already set up. %d existing message(s) routed.',
                $result['provisioned'],
                $result['skipped'],
                $moved
            )
        );

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
        $data = $this->userFormData();
        $this->users->update($id, $data);
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
        flash('success', 'User disabled.');
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
        $this->aliases->create($data);
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
        $this->aliases->update((int) ($params['id'] ?? 0), $this->aliasFormData());
        flash('success', 'Alias updated.');
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
            'adminSection' => 'folders',
        ]);
    }

    public function foldersStore(): void
    {
        requireAdmin();
        verify_csrf_or_fail();
        $displayName = trim($_POST['display_name'] ?? '');
        $folderType = $_POST['folder_type'] ?? 'client';
        if ($displayName === '') {
            flash('error', 'Display name is required.');
            redirect('admin/folders/create');
        }

        $this->folders->createClientFolder([
            'display_name' => $displayName,
            'folder_type' => $folderType,
            'imap_path' => trim($_POST['imap_path'] ?? '') ?: null,
            'create_rule' => isset($_POST['create_rule']),
            'rule_field' => $_POST['rule_field'] ?? 'subject',
            'rule_operator' => $_POST['rule_operator'] ?? 'contains',
            'rule_value' => trim($_POST['rule_value'] ?? ''),
        ]);

        flash('success', 'Folder created on IMAP and in database.');
        redirect('admin/folders');
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
        $this->rules->create($data);
        FilterService::clearSessionFlag();
        flash('success', 'Rule created. Mail will be re-organized on next inbox visit.');
        redirect('admin/rules');
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
        $this->rules->update((int) ($params['id'] ?? 0), $this->ruleFormData());
        FilterService::clearSessionFlag();
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
        $this->rules->toggle((int) ($params['id'] ?? 0));
        FilterService::clearSessionFlag();
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
        FilterService::clearSessionFlag();
        flash('success', 'Rule order updated.');
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

    private function render(string $view, array $data): void
    {
        $data['authUser'] = Auth::user();
        $data['sessionUser'] = $data['authUser'];
        $data['user'] = $data['authUser'];
        $data['filterPending'] = !empty($_SESSION['_filter_pending']);
        $folderData = FolderCache::load();
        $data['folders'] = $folderData['folders'];
        $data['unreadCounts'] = $folderData['unread_counts'] ?? [];
        $data['activeFolder'] = '__admin__';
        $data['success'] = flash('success');
        $data['error'] = flash('error');
        $data['prefs'] = user_preferences();

        view($view, $data);
    }
}
