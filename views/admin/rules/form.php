<?php
$isEdit = $rule !== null;
$action = $isEdit ? url('admin/rules/' . $rule['id'] . '/update') : url('admin/rules/store');
ob_start();
?>

<section class="page-header"><h2><?= $isEdit ? 'Edit rule' : 'Add rule' ?></h2></section>
<?php require base_path('views/partials/admin-nav.php'); ?>

<section class="card card-form">
    <form method="post" action="<?= e($action) ?>" class="compose-form">
        <?= csrf_field() ?>
        <div class="form-group">
            <label for="name">Rule name</label>
            <input type="text" id="name" name="name" value="<?= e($rule['name'] ?? '') ?>" required>
        </div>
        <div class="form-group">
            <label for="priority">Priority (lower = first)</label>
            <input type="number" id="priority" name="priority" value="<?= (int) ($rule['priority'] ?? 100) ?>" min="1" max="9999">
        </div>
        <div class="form-group">
            <label for="rule_type">Rule type</label>
            <select id="rule_type" name="rule_type">
                <?php foreach (['spam', 'company', 'employee', 'client'] as $type): ?>
                <option value="<?= $type ?>"<?= ($rule['rule_type'] ?? '') === $type ? ' selected' : '' ?>><?= ucfirst($type) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="condition_field">Field</label>
            <select id="condition_field" name="condition_field">
                <?php foreach (['to', 'from', 'from_domain', 'subject', 'body'] as $field): ?>
                <option value="<?= $field ?>"<?= ($rule['condition_field'] ?? '') === $field ? ' selected' : '' ?>><?= $field ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="condition_operator">Operator</label>
            <select id="condition_operator" name="condition_operator">
                <?php foreach (['equals', 'contains', 'starts_with', 'ends_with'] as $op): ?>
                <option value="<?= $op ?>"<?= ($rule['condition_operator'] ?? '') === $op ? ' selected' : '' ?>><?= $op ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="condition_value">Value</label>
            <input type="text" id="condition_value" name="condition_value" value="<?= e($rule['condition_value'] ?? '') ?>" required>
        </div>
        <div class="form-group">
            <label for="target_folder_id">Target folder</label>
            <select id="target_folder_id" name="target_folder_id" required>
                <?php foreach ($folders as $f): ?>
                <option value="<?= (int) $f['id'] ?>"<?= (int) ($rule['target_folder_id'] ?? 0) === (int) $f['id'] ? ' selected' : '' ?>><?= e($f['display_name']) ?> (<?= e($f['imap_path']) ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group form-check">
            <label class="form-check-label">
                <input type="checkbox" class="form-check-input" name="active" value="1"<?= (int) ($rule['active'] ?? 1) ? ' checked' : '' ?>>
                <span>Active</span>
            </label>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save</button>
            <a class="btn btn-secondary" href="<?= e(url('admin/rules')) ?>">Cancel</a>
        </div>
    </form>
</section>

<?php
$content = ob_get_clean();
require base_path('views/layout.php');
