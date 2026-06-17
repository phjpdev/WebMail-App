<?php ob_start(); ?>
<?php
$isEdit = !empty($folder);
$action = $isEdit ? url('admin/folders/' . $folder['id'] . '/update') : url('admin/folders/store');
$type = $folder['folder_type'] ?? 'client';
$types = ['client' => 'Client', 'company' => 'Company', 'employee' => 'Employee', 'system' => 'System'];
?>

<section class="page-header"><h2><?= $isEdit ? 'Edit folder' : 'Add folder' ?></h2></section>
<?php require base_path('views/partials/admin-nav.php'); ?>

<section class="card card-form">
    <form method="post" action="<?= e($action) ?>" class="compose-form">
        <?= csrf_field() ?>
        <div class="form-group">
            <label for="display_name">Display name</label>
            <input type="text" id="display_name" name="display_name" required placeholder="Client ABC"
                   value="<?= e($folder['display_name'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label for="folder_type">Folder type</label>
            <select id="folder_type" name="folder_type">
                <?php foreach ($types as $value => $label): ?>
                    <option value="<?= e($value) ?>"<?= $type === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php if ($isEdit): ?>
            <div class="form-group">
                <label>IMAP path</label>
                <p><code><?= e($folder['imap_path']) ?></code></p>
            </div>
            <div class="form-group form-check">
                <label class="form-check-label">
                    <input type="checkbox" class="form-check-input" name="active" value="1"<?= (int) ($folder['active'] ?? 1) ? ' checked' : '' ?>>
                    <span>Active</span>
                </label>
            </div>
        <?php else: ?>
            <div class="form-group">
                <label for="imap_path">IMAP path (optional)</label>
                <input type="text" id="imap_path" name="imap_path" placeholder="INBOX.ClientABC — auto-generated if empty">
            </div>
            <div class="form-group form-check">
                <label class="form-check-label">
                    <input type="checkbox" class="form-check-input" name="create_rule" value="1">
                    <span>Create filter rule</span>
                </label>
            </div>
            <div class="form-section">
                <p class="form-section-title">Filter rule options</p>
                <div class="form-group">
                    <label for="rule_field">Rule field</label>
                    <select id="rule_field" name="rule_field">
                        <option value="subject">Subject</option>
                        <option value="from">From</option>
                        <option value="from_domain">From domain</option>
                        <option value="to">To</option>
                        <option value="body">Body</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="rule_operator">Operator</label>
                    <select id="rule_operator" name="rule_operator">
                        <option value="contains">Contains</option>
                        <option value="equals">Equals</option>
                        <option value="starts_with">Starts with</option>
                        <option value="ends_with">Ends with</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="rule_value">Rule value</label>
                    <input type="text" id="rule_value" name="rule_value" placeholder="e.g. K Nails">
                </div>
            </div>
        <?php endif; ?>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save changes' : 'Create folder' ?></button>
            <a class="btn btn-secondary" href="<?= e(url('admin/folders')) ?>">Cancel</a>
        </div>
    </form>
</section>

<?php
$content = ob_get_clean();
require base_path('views/layout.php');
