<?php
/**
 * @var list<array{path: string, name: string}> $folders
 * @var string|null $activeFolder
 */

$groupOrder = ['inbox', 'sent', 'drafts', 'other', 'trash'];
$grouped = [
    'inbox' => [],
    'sent' => [],
    'drafts' => [],
    'trash' => [],
    'other' => [],
];

foreach ($folders as $folder) {
    $path = $folder['path'];
    $lower = strtolower($path);

    if ($path === 'INBOX') {
        $grouped['inbox'][] = $folder;
    } elseif (str_contains($lower, 'sent')) {
        $grouped['sent'][] = $folder;
    } elseif (str_contains($lower, 'draft')) {
        $grouped['drafts'][] = $folder;
    } elseif (str_contains($lower, 'trash')) {
        $grouped['trash'][] = $folder;
    } else {
        $grouped['other'][] = $folder;
    }
}

$labels = [
    'inbox' => 'Inbox',
    'sent' => 'Sent',
    'drafts' => 'Drafts',
    'other' => 'Folders',
    'trash' => 'Trash',
];
?>

<nav class="sidebar-nav">
    <a class="btn btn-primary btn-block sidebar-compose" href="<?= e(url('compose')) ?>">Compose</a>

    <?php foreach ($groupOrder as $group): ?>
        <?php if (empty($grouped[$group])) continue; ?>
        <p class="sidebar-label"><?= e($labels[$group]) ?></p>
        <?php foreach ($grouped[$group] as $folder): ?>
            <?php
            $displayName = $folder['path'] === 'INBOX' ? 'Inbox' : $folder['name'];
            $isActive = ($activeFolder ?? '') === $folder['path'];
            ?>
            <a class="sidebar-link<?= $isActive ? ' active' : '' ?>"
               href="<?= e(folder_url($folder['path'])) ?>">
                <?= e($displayName) ?>
            </a>
        <?php endforeach; ?>
    <?php endforeach; ?>

    <?php if (($user['role'] ?? '') === 'admin'): ?>
        <p class="sidebar-label">Admin</p>
        <a class="sidebar-link<?= ($activeFolder ?? '') === '__status__' ? ' active' : '' ?>"
           href="<?= e(url('status')) ?>">Connection status</a>
    <?php endif; ?>
</nav>
