<?php ob_start(); ?>
<?php
$isEdit = !empty($folder);
$action = $isEdit ? url('admin/folders/' . $folder['id'] . '/update') : url('admin/folders/store');
$type = $folder['folder_type'] ?? 'client';
$types = ['client' => 'Client', 'company' => 'Company', 'employee' => 'Employee', 'system' => 'System'];
$parentFolders = $parentFolders ?? [];
$groupParents = $groupParents ?? [];
$selectedParent = (int) ($_GET['parent'] ?? ($_POST['parent_folder_id'] ?? 0));
$selectedGroupParent = (int) ($folder['display_parent_id'] ?? 0);
?>

<section class="page-header"><h2><?= $isEdit ? 'Edit folder' : 'Add folder' ?></h2></section>
<?php require base_path('views/partials/admin-nav.php'); ?>

<section class="card card-form">
    <form method="post" action="<?= e($action) ?>" class="compose-form">
        <?= csrf_field() ?>
        <?php if (!$isEdit): ?>
        <div class="form-group">
            <label for="parent_folder_id">Parent folder</label>
            <select id="parent_folder_id" name="parent_folder_id">
                <option value="0">Inbox (top level)</option>
                <?php foreach ($parentFolders as $parent): ?>
                    <option value="<?= (int) $parent['id'] ?>"<?= $selectedParent === (int) $parent['id'] ? ' selected' : '' ?>>
                        <?= e($parent['label']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <small class="form-hint">Choose a parent to create a subfolder, or leave as Inbox for a new top-level folder.</small>
        </div>
        <?php endif; ?>
        <div class="form-group">
            <label for="display_name">Folder name</label>
            <input type="text" id="display_name" name="display_name" required
                   placeholder="e.g. JT, Client ABC, or Subfolder 2"
                   value="<?= e($folder['display_name'] ?? '') ?>">
            <small class="form-hint">Enter the name only — no need to type INBOX or use dots. Spaces become hyphens in the mailbox path.</small>
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
                <label>Mailbox path</label>
                <p><code><?= e($folder['imap_path']) ?></code></p>
            </div>
            <div class="form-group">
                <label for="display_parent_id">Show under (sidebar group)</label>
                <select id="display_parent_id" name="display_parent_id">
                    <option value="0">— Top level (no group)</option>
                    <?php foreach ($groupParents as $parent): ?>
                        <option value="<?= (int) $parent['id'] ?>"<?= $selectedGroupParent === (int) $parent['id'] ? ' selected' : '' ?>>
                            <?= e($parent['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="form-hint">Display only — nests this folder under the chosen folder in the sidebar. The mailbox, its address, and mail routing are unchanged.</small>
            </div>
            <div class="form-group form-check">
                <label class="form-check-label">
                    <input type="checkbox" class="form-check-input" name="active" value="1"<?= (int) ($folder['active'] ?? 1) ? ' checked' : '' ?>>
                    <span>Active</span>
                </label>
            </div>
        <?php else: ?>
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
