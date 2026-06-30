<?php
/**
 * @var array<string, mixed> $folder
 * @var int $depth
 * @var bool $hasChildren
 */
$folderId = (int) ($folder['id'] ?? 0);
$displayName = (string) ($folder['display_name'] ?? '');
$imapPath = (string) ($folder['imap_path'] ?? '');
$folderType = (string) ($folder['folder_type'] ?? '');
$linkedUser = (string) ($folder['linked_user_name'] ?? '—');
$isActive = (int) ($folder['active'] ?? 1);
$searchText = strtolower($displayName . ' ' . $imapPath . ' ' . $folderType . ' ' . $linkedUser);
$icon = folder_icon_type($imapPath);
$canDelete = admin_folder_is_deletable($folder);
$canAddSubfolder = admin_folder_allows_subfolders($folder);
$branchId = 'admin-folder-branch-' . $folderId;
$subtreeId = $branchId . '-subtree';
$hasChildren = $hasChildren ?? false;
?>
<div class="admin-folder-tree-row" style="--tree-depth: <?= (int) $depth ?>;">
    <div class="admin-folder-tree-col-name">
        <?php if ($hasChildren): ?>
            <button type="button" class="admin-folder-branch-toggle"
                    aria-expanded="false"
                    aria-controls="<?= e($subtreeId) ?>"
                    title="Expand or collapse">
                <svg class="admin-folder-branch-chevron" viewBox="0 0 24 24" width="10" height="10" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
            </button>
        <?php else: ?>
            <span class="admin-folder-branch-spacer" aria-hidden="true"></span>
        <?php endif; ?>
        <span class="folder-icon folder-icon-<?= e($icon) ?> admin-folder-tree-icon" aria-hidden="true"></span>
        <span class="admin-folder-tree-label">
            <span class="admin-folder-tree-display"><?= e($displayName) ?></span>
            <code class="admin-folder-tree-path"><?= e($imapPath) ?></code>
        </span>
    </div>
    <div class="admin-folder-tree-col-type">
        <span class="badge badge-<?= e($folderType) ?>"><?= e($folderType) ?></span>
    </div>
    <div class="admin-folder-tree-col-user"><?= e($linkedUser !== '' ? $linkedUser : '—') ?></div>
    <div class="admin-folder-tree-col-status">
        <span class="badge badge-<?= $isActive ? 'active' : 'inactive' ?>"><?= $isActive ? 'Active' : 'Inactive' ?></span>
    </div>
    <div class="admin-folder-tree-col-action">
        <a href="<?= e(url('admin/folders/' . $folderId . '/edit')) ?>">Edit</a>
    </div>
    <div class="admin-folder-tree-col-action">
        <?php if ($canDelete): ?>
            <form method="post" action="<?= e(url('admin/folders/' . $folderId . '/delete')) ?>" class="admin-action-form"
                  data-confirm-title="Delete folder?"
                  data-confirm-message="<?= e('Delete folder "' . $displayName . '" and all of its subfolders? Messages will be removed from the mail server. This cannot be undone.') ?>"
                  data-confirm-danger="1"
                  data-confirm-label="Delete">
                <?= csrf_field() ?>
                <button type="submit" class="btn-link-danger">Delete</button>
            </form>
        <?php else: ?>
            <span class="admin-folder-action-empty" aria-hidden="true">—</span>
        <?php endif; ?>
    </div>
    <div class="admin-folder-tree-col-action">
        <?php if ($canAddSubfolder): ?>
            <a href="<?= e(url('admin/folders/create?parent=' . $folderId)) ?>">Add subfolder</a>
        <?php else: ?>
            <span class="admin-folder-action-empty" aria-hidden="true">—</span>
        <?php endif; ?>
    </div>
</div>
