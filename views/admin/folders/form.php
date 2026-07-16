<?php ob_start(); ?>
<?php
$isEdit = !empty($folder);
$action = $isEdit ? url('admin/folders/' . $folder['id'] . '/update') : url('admin/folders/store');
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
                        <?= str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', (int) ($parent['depth'] ?? 0)) ?>&#128193; <?= e($parent['label']) ?>
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
                            <?= str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', (int) ($parent['depth'] ?? 0)) ?>&#128193; <?= e($parent['label']) ?>
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
