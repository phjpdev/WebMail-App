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

// One canonical folder per primary nav slot (Sent, Drafts, Archive, Junk, Trash).
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

$renderFolderLink = static function (array $folder, string $bucket, int $depth = 0, ?string $leafLabel = null) use ($activeFolder, $unreadCounts): void {
    $displayName = $leafLabel ?? sidebar_folder_label($folder, $bucket);
    $isActive = strcasecmp($activeFolder ?? '', $folder['path']) === 0;
    $icon = folder_icon_type($folder['path']);
    $unread = folder_shows_unread_badge($folder['path'])
        ? (int) ($unreadCounts[$folder['path']] ?? 0)
        : 0;
    ?>
    <a class="sidebar-link<?= $isActive ? ' active' : '' ?><?= $depth > 0 ? ' is-nested' : '' ?>"
       href="<?= e(folder_url($folder['path'])) ?>"
       data-folder-path="<?= e($folder['path']) ?>"
       data-folder-b64="<?= e(encode_folder_path($folder['path'])) ?>"<?= $depth > 0 ? ' style="--folder-depth: ' . $depth . ';"' : '' ?>
       data-ajax-folder="1">
        <span class="folder-icon folder-icon-<?= e($icon) ?>" aria-hidden="true"></span>
        <span class="sidebar-link-text"><?= e($displayName) ?></span>
        <?php if ($unread > 0): ?>
            <span class="folder-badge"><?= $unread > 99 ? '99+' : $unread ?></span>
        <?php endif; ?>
    </a>
    <?php
};

$otherUnread = 0;
foreach ($grouped['other'] as $folder) {
    if (folder_shows_unread_badge($folder['path'])) {
        $otherUnread += (int) ($unreadCounts[$folder['path']] ?? 0);
    }
}
$foldersOpen = false;
foreach ($grouped['other'] as $folder) {
    if (strcasecmp($activeFolder ?? '', $folder['path']) === 0) {
        $foldersOpen = true;
        break;
    }
}
if (!$foldersOpen && $grouped['other'] !== [] && ($sessionUser['role'] ?? '') === 'employee') {
    $foldersOpen = true;
}

?>

<nav class="sidebar-nav">
    <a class="btn btn-primary btn-compose" href="<?= e($composeHref) ?>" id="compose-link">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
        Compose
    </a>

    <div class="sidebar-groups" id="folder-sidebar-list">
        <div class="sidebar-primary-folders">
            <?php foreach ($primaryOrder as $bucket): ?>
                <?php foreach ($grouped[$bucket] as $folder): ?>
                    <?php $renderFolderLink($folder, $bucket); ?>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </div>

        <?php if ($grouped['other'] !== []): ?>
            <div class="sidebar-divider" aria-hidden="true"></div>
            <div class="sidebar-group<?= $foldersOpen ? ' is-open' : '' ?> is-collapsible" data-group="other">
                <button type="button" class="sidebar-group-toggle" aria-expanded="<?= $foldersOpen ? 'true' : 'false' ?>">
                    <span class="sidebar-group-chevron-btn" aria-hidden="true">
                        <svg class="sidebar-group-chevron-icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg>
                    </span>
                    <span class="sidebar-group-icon folder-icon folder-icon-folder" aria-hidden="true"></span>
                    <span class="sidebar-group-title">Folders</span>
                    <?php if ($otherUnread > 0): ?>
                        <span class="folder-badge folder-badge-sm"><?= $otherUnread > 99 ? '99+' : $otherUnread ?></span>
                    <?php endif; ?>
                </button>
                <div class="sidebar-group-items">
                    <?php
                    // Nest custom folders under any parent also shown here, using
                    // the IMAP hierarchy delimiter (e.g. test1 → test1-sub1).
                    $otherFolders = $grouped['other'];
                    usort($otherFolders, static fn ($a, $b) => strcasecmp($a['path'], $b['path']));
                    $presentOther = [];
                    foreach ($otherFolders as $f) {
                        $presentOther[strtolower($f['path'])] = true;
                    }
                    foreach ($otherFolders as $folder):
                        [$depth, $leaf] = sidebar_folder_nesting($folder, $presentOther, $folder['delimiter'] ?? '.');
                        $renderFolderLink($folder, 'other', $depth, $leaf);
                    endforeach;
                    ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php if (($sessionUser['role'] ?? '') === 'admin'): ?>
        <div class="sidebar-footer">
            <a class="sidebar-link<?= ($activeFolder ?? '') === '__admin__' ? ' active' : '' ?>"
               href="<?= e(url('admin')) ?>">
                <span class="folder-icon folder-icon-admin" aria-hidden="true"></span>
                <span class="sidebar-link-text">Admin panel</span>
            </a>
            <a class="sidebar-link<?= ($activeFolder ?? '') === '__status__' ? ' active' : '' ?>"
               href="<?= e(url('status')) ?>">
                <span class="folder-icon folder-icon-connection" aria-hidden="true"></span>
                <span class="sidebar-link-text">Connection status</span>
            </a>
        </div>
    <?php endif; ?>
</nav>
