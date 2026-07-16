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
            <label for="alias_email">Email address <span class="text-required">*</span></label>
            <input type="email" id="alias_email" name="alias_email" required
                   placeholder="employee@bebenailsmd.com or personal@gmail.com">
            <small class="form-hint">Send-as address for this employee (any valid email — @bebenailsmd.com, Gmail, Outlook, etc.).
                Incoming mail <em>to this address</em> is auto-routed to their folder when it arrives in this mailbox.</small>
        </div>
        <div class="form-group" id="employee-folder-field">
            <label for="folder_name">Folder name <span class="text-required">*</span></label>
            <input type="text" id="folder_name" name="folder_name" required
                   pattern="[A-Za-z0-9 _-]+" title="Letters, numbers, spaces, hyphens, and underscores (spaces become hyphens)"
                   placeholder="e.g. ankesh or support">
            <small class="form-hint">Creates an IMAP folder (INBOX.<em>name</em>) and links it to this user. Spaces become hyphens (e.g. &ldquo;John Tran&rdquo; &rarr; INBOX.John-Tran). Required for employees.</small>
        </div>
        <div class="form-group" id="employee-group-field">
            <label for="display_parent_id">Show under (sidebar group)</label>
            <select id="display_parent_id" name="display_parent_id">
                <option value="0">— Top level (no group)</option>
                <?php foreach (($groupParents ?? []) as $parent): ?>
                    <option value="<?= (int) $parent['id'] ?>"><?= str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', (int) ($parent['depth'] ?? 0)) ?>&#128193; <?= e($parent['label']) ?></option>
                <?php endforeach; ?>
            </select>
            <small class="form-hint">Optional — nests this employee's folder under the chosen group (e.g. Employees) in the sidebar right away, so you don't have to edit it afterward.</small>
        </div>
        <?php endif; ?>
        <?php if ($isEdit && ($editUser['role'] ?? '') !== 'admin'): ?>
        <div class="form-group">
            <label for="alias_email">Email address</label>
            <input type="email" id="alias_email" name="alias_email"
                   value="<?= e($editUser['alias_email'] ?? '') ?>" required>
            <small class="form-hint">Send-as address (any valid email). Changing it updates the alias and routing rule.</small>
        </div>
        <div class="form-group">
            <label for="folder_name">Folder name <span class="text-required">*</span></label>
            <input type="text" id="folder_name" name="folder_name" required
                   pattern="[A-Za-z0-9 _-]+" title="Letters, numbers, spaces, hyphens, and underscores (spaces become hyphens)"
                   value="<?= e($editUser['folder_name'] ?? '') ?>"
                   placeholder="e.g. ankesh or support">
            <small class="form-hint">Employee IMAP folder (INBOX.<em>name</em>). Spaces become hyphens. Changing it renames the folder on the mail server and updates the sidebar.</small>
        </div>
        <div class="form-group form-check">
            <label class="form-check-label">
                <input type="checkbox" class="form-check-input" name="active" value="1"<?= (int) ($editUser['active'] ?? 1) ? ' checked' : '' ?>>
                <span>Active</span>
            </label>
        </div>
        <?php endif; ?>
        <div class="form-group">
            <label for="signature">Email signature</label>
            <textarea id="signature" name="signature" rows="4"
                      placeholder="e.g. John Tran&#10;Tax &amp; Business Consultant&#10;(571) 527-8120"><?= e($editUser['signature'] ?? '') ?></textarea>
            <small class="form-hint">Added to the bottom of this user's outgoing emails. You can set it here on their behalf — they can also change it later under Settings.</small>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save</button>
            <a class="btn btn-secondary" href="<?= e(url('admin/users')) ?>">Cancel</a>
        </div>
    </form>
</section>

<?php if (!$isEdit): ?>
<script>
(function () {
    var role = document.getElementById('role');
    var email = document.getElementById('alias_email');
    var folder = document.getElementById('folder_name');
    var onboarding = document.getElementById('employee-onboarding-fields');
    var folderField = document.getElementById('employee-folder-field');
    var groupField = document.getElementById('employee-group-field');
    if (!role || !email || !folder) return;

    function syncEmployeeFields() {
        var isEmployee = role.value === 'employee';
        email.required = isEmployee;
        folder.required = isEmployee;
        if (onboarding) onboarding.hidden = !isEmployee;
        if (folderField) folderField.hidden = !isEmployee;
        if (groupField) groupField.hidden = !isEmployee;
    }

    role.addEventListener('change', syncEmployeeFields);
    syncEmployeeFields();
})();
</script>
<?php endif; ?>

<?php
$content = ob_get_clean();
require base_path('views/layout.php');
