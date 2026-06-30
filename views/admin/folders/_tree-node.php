<?php
/**
 * @var array{folder: array<string, mixed>, children: list<array{folder: array<string, mixed>, children: list}>} $node
 * @var int $depth
 */
$depth = $depth ?? 0;
$folder = $node['folder'];
$children = $node['children'] ?? [];
$hasChildren = $children !== [];
$folderId = (int) ($folder['id'] ?? 0);
$displayName = (string) ($folder['display_name'] ?? '');
$imapPath = (string) ($folder['imap_path'] ?? '');
$folderType = (string) ($folder['folder_type'] ?? '');
$linkedUser = (string) ($folder['linked_user_name'] ?? '—');
$isActive = (int) ($folder['active'] ?? 1);
$searchText = strtolower($displayName . ' ' . $imapPath . ' ' . $folderType . ' ' . $linkedUser);

$canDelete = admin_folder_is_deletable($folder);
$canAddSubfolder = admin_folder_allows_subfolders($folder);
$branchId = 'admin-folder-branch-' . $folderId;
?>
<div class="admin-folder-branch<?= $hasChildren ? ' has-children' : '' ?>"
     role="treeitem"
     aria-expanded="<?= $hasChildren ? 'true' : 'false' ?>"
     data-folder-search="<?= e($searchText) ?>"
     data-branch-id="<?= e($branchId) ?>"
     style="--tree-depth: <?= (int) $depth ?>;">
    <div class="admin-folder-tree-row">
        <div class="admin-folder-tree-col-name">
            <?php if ($hasChildren): ?>
                <button type="button" class="admin-folder-branch-toggle is-open"
                        aria-expanded="true"
                        aria-controls="<?= e($branchId) ?>"
                        title="Expand or collapse">
                    <svg class="admin-folder-branch-chevron" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
                </button>
            <?php else: ?>
                <span class="admin-folder-branch-spacer" aria-hidden="true"></span>
            <?php endif; ?>
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
        <div class="admin-folder-tree-col-actions table-actions">
            <a href="<?= e(url('admin/folders/' . $folderId . '/edit')) ?>">Edit</a>
            <?php if ($canDelete): ?>
                <form method="post" action="<?= e(url('admin/folders/' . $folderId . '/delete')) ?>" class="admin-action-form"
                      data-confirm-title="Delete folder?"
                      data-confirm-message="<?= e('Delete folder "' . $displayName . '" and all of its subfolders? Messages will be removed from the mail server. This cannot be undone.') ?>"
                      data-confirm-danger="1"
                      data-confirm-label="Delete">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn-link-danger">Delete</button>
                </form>
            <?php endif; ?>
            <?php if ($canAddSubfolder): ?>
                <a href="<?= e(url('admin/folders/create?parent=' . $folderId)) ?>">Add subfolder</a>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($hasChildren): ?>
        <div class="admin-folder-branch-children is-open" id="<?= e($branchId) ?>" role="group">
            <?php
            $parentDepth = $depth;
            foreach ($children as $childNode):
                $node = $childNode;
                $depth = $parentDepth + 1;
                require base_path('views/admin/folders/_tree-node.php');
            endforeach;
            ?>
        </div>
    <?php endif; ?>
</div>
