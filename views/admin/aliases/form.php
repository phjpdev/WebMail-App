<?php
$isEdit = $alias !== null;
$action = $isEdit ? url('admin/aliases/' . $alias['id'] . '/update') : url('admin/aliases/store');
ob_start();
?>

<section class="page-header"><h2><?= $isEdit ? 'Edit alias' : 'Add alias' ?></h2></section>
<?php require base_path('views/partials/admin-nav.php'); ?>

<section class="card card-form">
    <form method="post" action="<?= e($action) ?>" class="compose-form">
        <?= csrf_field() ?>
        <div class="form-group">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="<?= e($alias['email'] ?? '') ?>" required>
        </div>
        <div class="form-group">
            <label for="display_name">Display name</label>
            <input type="text" id="display_name" name="display_name" value="<?= e($alias['display_name'] ?? '') ?>" required>
        </div>
        <div class="form-group">
            <label for="user_id">Linked user</label>
            <select id="user_id" name="user_id">
                <option value="">— None —</option>
                <?php foreach ($users as $u): ?>
                <option value="<?= (int) $u['id'] ?>"<?= (int) ($alias['user_id'] ?? 0) === (int) $u['id'] ? ' selected' : '' ?>><?= e($u['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="default_folder_id">Default folder</label>
            <select id="default_folder_id" name="default_folder_id">
                <option value="">— None —</option>
                <?php foreach ($folders as $f): ?>
                <option value="<?= (int) $f['id'] ?>"<?= (int) ($alias['default_folder_id'] ?? 0) === (int) $f['id'] ? ' selected' : '' ?>><?= e($f['display_name']) ?> (<?= e($f['imap_path']) ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group form-check">
            <label class="form-check-label">
                <input type="checkbox" class="form-check-input" name="active" value="1"<?= (int) ($alias['active'] ?? 1) ? ' checked' : '' ?>>
                <span>Active</span>
            </label>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save</button>
            <a class="btn btn-secondary" href="<?= e(url('admin/aliases')) ?>">Cancel</a>
        </div>
    </form>
</section>

<?php
$content = ob_get_clean();
require base_path('views/layout.php');
