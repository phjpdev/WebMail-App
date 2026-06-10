<?php
$isEdit = $user !== null;
$action = $isEdit ? url('admin/users/' . $user['id'] . '/update') : url('admin/users/store');
ob_start();
?>

<section class="page-header">
    <h2><?= $isEdit ? 'Edit user' : 'Add user' ?></h2>
</section>

<?php require base_path('views/partials/admin-nav.php'); ?>

<section class="card card-form">
    <form method="post" action="<?= e($action) ?>" class="compose-form">
        <?= csrf_field() ?>
        <div class="form-group">
            <label for="name">Full name</label>
            <input type="text" id="name" name="name" value="<?= e($user['name'] ?? '') ?>" required>
        </div>
        <div class="form-group">
            <label for="username">Username</label>
            <input type="text" id="username" name="username" value="<?= e($user['username'] ?? '') ?>" required>
        </div>
        <div class="form-group">
            <label for="password">Password<?= $isEdit ? ' (leave blank to keep)' : '' ?></label>
            <input type="password" id="password" name="password" <?= $isEdit ? '' : 'required' ?>>
        </div>
        <div class="form-group">
            <label for="access_code">Access code (employees)<?= $isEdit ? ' (leave blank to keep)' : '' ?></label>
            <input type="password" id="access_code" name="access_code" autocomplete="new-password">
            <p class="text-muted form-hint">Employees can log in with username + access code instead of password.</p>
        </div>
        <div class="form-group form-check">
            <label class="form-check-label">
                <input type="checkbox" class="form-check-input" name="must_change_password" value="1"<?= !$isEdit || !empty($user['must_change_password']) ? ' checked' : '' ?>>
                <span>Require password change on next login</span>
            </label>
        </div>
        <?php if ($isEdit && ($user['role'] ?? '') === 'admin'): ?>
        <div class="form-group">
            <label>Role</label>
            <p class="form-static-value"><span class="badge badge-admin">Admin</span></p>
            <input type="hidden" name="role" value="admin">
        </div>
        <?php else: ?>
        <div class="form-group">
            <label for="role">Role</label>
            <select id="role" name="role">
                <option value="employee"<?= ($user['role'] ?? 'employee') === 'employee' ? ' selected' : '' ?>>Employee</option>
                <option value="admin"<?= ($user['role'] ?? '') === 'admin' ? ' selected' : '' ?>>Admin</option>
            </select>
        </div>
        <?php endif; ?>
        <?php if (!$isEdit): ?>
        <div class="form-group">
            <label for="alias_email">Alias email (employee onboarding)</label>
            <input type="email" id="alias_email" name="alias_email" placeholder="employee@example.com">
        </div>
        <div class="form-group">
            <label for="folder_name">IMAP folder name</label>
            <input type="text" id="folder_name" name="folder_name" placeholder="Defaults to username">
        </div>
        <?php endif; ?>
        <?php if ($isEdit && ($user['role'] ?? '') !== 'admin'): ?>
        <div class="form-group form-check">
            <label class="form-check-label">
                <input type="checkbox" class="form-check-input" name="active" value="1"<?= (int) ($user['active'] ?? 1) ? ' checked' : '' ?>>
                <span>Active</span>
            </label>
        </div>
        <?php endif; ?>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save</button>
            <a class="btn btn-secondary" href="<?= e(url('admin/users')) ?>">Cancel</a>
        </div>
    </form>
</section>

<?php
$content = ob_get_clean();
require base_path('views/layout.php');
