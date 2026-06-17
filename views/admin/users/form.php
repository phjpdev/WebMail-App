<?php
$isEdit = $editUser !== null;
$action = $isEdit ? url('admin/users/' . $editUser['id'] . '/update') : url('admin/users/store');
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
            <input type="text" id="name" name="name" value="<?= e($editUser['name'] ?? '') ?>" required>
        </div>
        <div class="form-group">
            <label for="username">Username</label>
            <input type="text" id="username" name="username" value="<?= e($editUser['username'] ?? '') ?>" required>
        </div>
        <div class="form-group">
            <label for="password">Password<?= $isEdit ? ' (leave blank to keep)' : '' ?></label>
            <input type="password" id="password" name="password" <?= $isEdit ? '' : 'required' ?>>
        </div>
        <div class="form-group form-check">
            <label class="form-check-label">
                <input type="checkbox" class="form-check-input" name="must_change_password" value="1"<?= !$isEdit || !empty($editUser['must_change_password']) ? ' checked' : '' ?>>
                <span>Require password change on next login</span>
            </label>
        </div>
        <?php if ($isEdit && ($editUser['role'] ?? '') === 'admin'): ?>
        <div class="form-group">
            <label>Role</label>
            <p class="form-static-value"><span class="badge badge-admin">Admin</span></p>
            <input type="hidden" name="role" value="admin">
        </div>
        <?php else: ?>
        <div class="form-group">
            <label for="role">Role</label>
            <select id="role" name="role">
                <option value="employee"<?= ($editUser['role'] ?? 'employee') === 'employee' ? ' selected' : '' ?>>Employee</option>
                <option value="admin"<?= ($editUser['role'] ?? '') === 'admin' ? ' selected' : '' ?>>Admin</option>
            </select>
        </div>
        <?php endif; ?>
        <?php if (!$isEdit): ?>
        <div class="form-group" id="employee-onboarding-fields">
            <label for="alias_email">Email address (required for employees)</label>
            <input type="email" id="alias_email" name="alias_email" placeholder="employee@bebenailsmd.com">
            <small class="form-hint">This becomes the employee's send-as address. A personal folder and an
                auto-routing rule (incoming mail to this address &rarr; their folder) are created automatically.</small>
        </div>
        <div class="form-group">
            <label for="folder_name">IMAP folder name</label>
            <input type="text" id="folder_name" name="folder_name" placeholder="Defaults to username">
        </div>
        <?php endif; ?>
        <?php if ($isEdit && ($editUser['role'] ?? '') !== 'admin'): ?>
        <div class="form-group">
            <label for="alias_email">Email address</label>
            <input type="email" id="alias_email" name="alias_email"
                   value="<?= e($editUser['alias_email'] ?? '') ?>" required>
            <small class="form-hint">Send-as address for this employee. Changing it updates the alias and routing rule.</small>
        </div>
        <div class="form-group form-check">
            <label class="form-check-label">
                <input type="checkbox" class="form-check-input" name="active" value="1"<?= (int) ($editUser['active'] ?? 1) ? ' checked' : '' ?>>
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
