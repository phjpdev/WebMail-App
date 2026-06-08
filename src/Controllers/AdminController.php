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

    public function sync(): void
    {
        requireAdmin();
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
            'user' => null,
            'adminSection' => 'users',
        ]);
    }

    public function usersStore(): void
    {
        requireAdmin();
        $data = $this->userFormData();
        if ($data['name'] === '' || $data['username'] === '' || $data['password'] === '') {
            flash('error', 'Name, username, and password are required.');
            redirect('admin/users/create');
        }

        $this->users->createEmployee($data);
        flash('success', 'User created with folder, alias, and filter rule.');
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
            'user' => $user,
            'adminSection' => 'users',
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function usersUpdate(array $params): void
    {
        requireAdmin();
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
        $this->users->disable((int) ($params['id'] ?? 0));
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
        $this->render('admin/rules/index', [
            'title' => 'Filter rules',
            'rules' => $this->rules->listAll(),
            'adminSection' => 'rules',
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
        $data = $this->ruleFormData();
        if ($data['name'] === '' || $data['condition_value'] === '') {
            flash('error', 'Name and condition value are required.');
            redirect('admin/rules/create');
        }
        $this->rules->create($data);
        flash('success', 'Rule created.');
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
        $this->rules->update((int) ($params['id'] ?? 0), $this->ruleFormData());
        flash('success', 'Rule updated.');
        redirect('admin/rules');
    }

    /**
     * @param array<string, string> $params
     */
    public function rulesToggle(array $params): void
    {
        requireAdmin();
        $this->rules->toggle((int) ($params['id'] ?? 0));
        flash('success', 'Rule status toggled.');
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
        $data['user'] = Auth::user();
        $data['folders'] = FolderCache::load()['folders'];
        $data['activeFolder'] = '__admin__';
        $data['success'] = flash('success');
        $data['error'] = flash('error');

        view($view, $data);
    }
}
