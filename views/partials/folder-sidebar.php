<?php
/**
 * @var list<array{path: string, name: string}> $sidebarFolders
 * @var list<array{path: string, name: string}> $folders
 * @var string|null $activeFolder
 * @var array<string, int> $unreadCounts
 */

$unreadCounts = $unreadCounts ?? [];
$primaryOrder = sidebar_primary_folder_order();
$grouped = array_fill_keys(array_merge($primaryOrder, ['other']), []);

foreach (($sidebarFolders ?? $folders ?? []) as $folder) {
    $grouped[sidebar_folder_bucket($folder['path'])][] = $folder;
}

foreach ($primaryOrder as $bucket) {
    if ($bucket === 'inbox' || $grouped[$bucket] === []) {
        continue;
    }
    $grouped[$bucket] = sidebar_dedupe_primary_bucket($grouped[$bucket], $bucket);
}

$composeHref = url('compose');
$composeActive = $activeFolder ?? '';
if ($composeActive !== '' && !str_starts_with($composeActive, '__')) {
    $composeHref = url('compose') . '?return_folder=' . rawurlencode(encode_folder_path($composeActive));
}

$employeeRoots = sidebar_employee_root_path_set();

$renderTreeLink = static function (
    array $folder,
    string $bucket,
    ?string $leafLabel = null
) use ($activeFolder, $unreadCounts, $employeeRoots): void {
    $displayName = $leafLabel ?? sidebar_folder_label($folder, $bucket);
    $navPath = sidebar_folder_nav_path($folder['path']);
    $isActive = sidebar_folder_matches_active($activeFolder ?? '', $navPath);
    $icon = folder_icon_type($folder['path']);
    $isEmployee = isset($employeeRoots[strtolower((string) ($folder['path'] ?? ''))]);
    $unread = folder_shows_unread_badge($navPath)
        ? (int) ($unreadCounts[$navPath] ?? $unreadCounts[$folder['path']] ?? 0)
        : 0;
    ?>
    <a class="sidebar-link sidebar-tree-link<?= $isActive ? ' active' : '' ?><?= $isEmployee ? ' sidebar-link--employee' : '' ?>"
       href="<?= e(folder_url($navPath)) ?>"
       data-folder-path="<?= e($navPath) ?>"
       data-folder-b64="<?= e(encode_folder_path($navPath)) ?>"
       data-ajax-folder="1">
        <span class="folder-icon folder-icon-<?= e($icon) ?>" aria-hidden="true"></span>
        <span class="sidebar-link-text"><?= e($displayName) ?></span>
        <?php if ($unread > 0): ?>
            <span class="folder-badge"><?= $unread > 99 ? '99+' : $unread ?></span>
        <?php endif; ?>
    </a>
    <?php
};

$renderTreeRow = static function (
    array $folder,
    string $bucket,
    int $depth,
    ?string $leafLabel,
    bool $hasChildren,
    bool $branchOpen,
    ?string $branchKey
) use ($renderTreeLink): void {
    if ($hasChildren) {
        ?>
        <div class="sidebar-folder-branch<?= $branchOpen ? ' is-open' : '' ?>"
             data-sidebar-branch="<?= e((string) $branchKey) ?>">
            <div class="sidebar-tree-row" style="--tree-depth: <?= (int) $depth ?>;">
                <button type="button"
                        class="sidebar-tree-toggle"
                        aria-expanded="<?= $branchOpen ? 'true' : 'false' ?>"
                        aria-label="Expand or collapse folder">
                    <svg class="sidebar-tree-chevron" viewBox="0 0 24 24" width="10" height="10" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
                </button>
                <?php $renderTreeLink($folder, $bucket, $leafLabel); ?>
            </div>
            <div class="sidebar-folder-branch-children"<?= $branchOpen ? '' : ' hidden' ?>>
        <?php
        return;
    }
    ?>
    <div class="sidebar-tree-row" style="--tree-depth: <?= (int) $depth ?>;">
        <span class="sidebar-tree-toggle-spacer" aria-hidden="true"></span>
        <?php $renderTreeLink($folder, $bucket, $leafLabel); ?>
    </div>
    <?php
};

$renderSidebarFolderBranch = static function (array $node, int $depth = 0) use (
    &$renderSidebarFolderBranch,
    $renderTreeRow,
    $activeFolder
): void {
    $folder = $node['folder'];
    $children = $node['children'] ?? [];
    $hasChildren = $children !== [];
    $navPath = sidebar_folder_nav_path($folder['path']);
    $displayName = sidebar_folder_tree_label($folder);
    $branchKey = strtolower($navPath);
    $branchOpen = $hasChildren && sidebar_folder_branch_should_open($children, $activeFolder ?? '');

    if ($hasChildren) {
        $renderTreeRow($folder, 'other', $depth, $displayName, true, $branchOpen, $branchKey);
        foreach ($children as $childNode) {
            $renderSidebarFolderBranch($childNode, $depth + 1);
        }
        echo '</div></div>';
        return;
    }

    $renderTreeRow($folder, 'other', $depth, $displayName, false, false, null);
};

// Manual, display-only sidebar grouping. An admin can set a folder's
// display_parent_id so it renders nested under another folder in the sidebar.
// The IMAP mailboxes, their paths, addresses and routing are NOT changed — this
// only affects how the "other" folders are laid out here.
// Keys are normalized with employee_mailbox_root_prefix() so an employee mailbox
// matches whether the sidebar lists it as INBOX.Jean or INBOX.Jean.Inbox.
$displayChildToParent = [];   // childKey  => parentKey
$displayParentPaths = [];     // parentKey => true
try {
    foreach (App\Database::query(
        "SELECT c.imap_path AS child_path, p.imap_path AS parent_path
         FROM folders c
         JOIN folders p ON p.id = c.display_parent_id
         WHERE c.active = 1 AND p.active = 1 AND c.display_parent_id IS NOT NULL"
    )->fetchAll() as $row) {
        $childKey = strtolower(employee_mailbox_root_prefix((string) ($row['child_path'] ?? '')));
        $parentKey = strtolower(employee_mailbox_root_prefix((string) ($row['parent_path'] ?? '')));
        if ($childKey !== '' && $parentKey !== '' && $childKey !== $parentKey) {
            $displayChildToParent[$childKey] = $parentKey;
            $displayParentPaths[$parentKey] = true;
        }
    }
} catch (\Throwable) {
}

$displayGroups = []; // parentKey => ['folder' => ?array, 'children' => list]
if ($displayChildToParent !== [] && $grouped['other'] !== []) {
    $remainingOther = [];
    $consumedChildKeys = [];
    foreach ($grouped['other'] as $folder) {
        $key = strtolower(employee_mailbox_root_prefix((string) ($folder['path'] ?? '')));
        if ($key !== '' && isset($displayParentPaths[$key])) {
            $displayGroups[$key]['folder'] = $folder;
            $displayGroups[$key]['children'] ??= [];
        } elseif ($key !== '' && isset($displayChildToParent[$key]) && !isset($consumedChildKeys[$key])) {
            $consumedChildKeys[$key] = true;
            $parentKey = $displayChildToParent[$key];
            $displayGroups[$parentKey]['children'][] = $folder;
            $displayGroups[$parentKey]['folder'] ??= null;
        } else {
            $remainingOther[] = $folder;
        }
    }

    // Only keep groups that have both a visible container and at least one child;
    // otherwise return the folders to the flat list so nothing disappears.
    foreach ($displayGroups as $key => $group) {
        $hasFolder = !empty($group['folder']);
        $hasChildren = !empty($group['children']);
        if (!$hasFolder || !$hasChildren) {
            if ($hasFolder) {
                $remainingOther[] = $group['folder'];
            }
            foreach ($group['children'] ?? [] as $orphan) {
                $remainingOther[] = $orphan;
            }
            unset($displayGroups[$key]);
        }
    }

    $grouped['other'] = $remainingOther;
}

$otherFolderTree = [];
if ($grouped['other'] !== []) {
    $otherFolders = sidebar_dedupe_other_folders($grouped['other']);
    $delimiter = $otherFolders[0]['delimiter'] ?? '.';
    $otherFolderTree = build_sidebar_other_folder_tree($otherFolders, 'path', $delimiter);
}

?>

<nav class="sidebar-nav">
    <a class="btn btn-primary btn-compose" href="<?= e($composeHref) ?>" id="compose-link">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
        Compose
    </a>

    <div class="sidebar-groups" id="folder-sidebar-list">
        <div class="sidebar-folder-tree sidebar-primary-folders--tree">
            <?php foreach ($primaryOrder as $bucket): ?>
                <?php foreach ($grouped[$bucket] as $folder): ?>
                    <?php $renderTreeRow($folder, $bucket, 0, null, false, false, null); ?>
                <?php endforeach; ?>
            <?php endforeach; ?>
            <?php foreach ($otherFolderTree as $node): ?>
                <?php $renderSidebarFolderBranch($node, 0); ?>
            <?php endforeach; ?>
            <?php foreach ($displayGroups as $group): ?>
                <?php
                $groupFolder = $group['folder'];
                $groupNav = sidebar_folder_nav_path($groupFolder['path']);
                $groupLabel = sidebar_folder_tree_label($groupFolder);
                $groupOpen = sidebar_folder_matches_active($activeFolder ?? '', $groupNav);
                $groupUnread = 0;
                foreach ($group['children'] as $childFolder) {
                    $childNav = sidebar_folder_nav_path($childFolder['path']);
                    if (folder_shows_unread_badge($childNav)) {
                        $groupUnread += (int) ($unreadCounts[$childNav] ?? $unreadCounts[$childFolder['path']] ?? 0);
                    }
                    if (sidebar_folder_matches_active($activeFolder ?? '', $childNav)) {
                        $groupOpen = true;
                    }
                }
                ?>
                <div class="sidebar-group is-collapsible sidebar-group--folder<?= $groupOpen ? ' is-open' : '' ?>" data-group="folder:<?= e(strtolower($groupNav)) ?>">
                    <div class="sidebar-tree-row" style="--tree-depth: 0;">
                        <span class="sidebar-tree-toggle-spacer" aria-hidden="true"></span>
                        <button type="button" class="sidebar-group-toggle sidebar-link sidebar-tree-link" aria-expanded="<?= $groupOpen ? 'true' : 'false' ?>" title="<?= e($groupLabel) ?>">
                            <span class="folder-icon folder-icon-folder" aria-hidden="true"></span>
                            <span class="sidebar-link-text"><?= e($groupLabel) ?></span>
                            <?php if ($groupUnread > 0): ?>
                                <span class="folder-badge folder-badge-sm"><?= $groupUnread > 99 ? '99+' : $groupUnread ?></span>
                            <?php endif; ?>
                            <svg class="sidebar-group-caret" viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
                        </button>
                    </div>
                    <div class="sidebar-group-items">
                        <?php foreach ($group['children'] as $childFolder): ?>
                            <?php $renderTreeRow($childFolder, 'employee', 1, null, false, false, null); ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if (($sessionUser['role'] ?? '') === 'admin'): ?>
        <div class="sidebar-footer sidebar-folder-tree">
            <div class="sidebar-tree-row" style="--tree-depth: 0;">
                <span class="sidebar-tree-toggle-spacer" aria-hidden="true"></span>
                <a class="sidebar-link sidebar-tree-link<?= ($activeFolder ?? '') === '__admin__' ? ' active' : '' ?>"
                   href="<?= e(url('admin')) ?>">
                    <span class="folder-icon folder-icon-admin" aria-hidden="true"></span>
                    <span class="sidebar-link-text">Admin panel</span>
                </a>
            </div>
            <div class="sidebar-tree-row" style="--tree-depth: 0;">
                <span class="sidebar-tree-toggle-spacer" aria-hidden="true"></span>
                <a class="sidebar-link sidebar-tree-link<?= ($activeFolder ?? '') === '__status__' ? ' active' : '' ?>"
                   href="<?= e(url('status')) ?>">
                    <span class="folder-icon folder-icon-connection" aria-hidden="true"></span>
                    <span class="sidebar-link-text">Connection status</span>
                </a>
            </div>
        </div>
    <?php endif; ?>
</nav>
