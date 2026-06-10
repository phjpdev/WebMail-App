<?php
/**
 * @var int $page
 * @var int $totalPages
 * @var int $totalMessages
 * @var string $baseUrl URL with trailing ? or & for query params
 * @var int $currentPerPage
 */
if (($totalPages ?? 1) < 1) {
    return;
}
$page = (int) ($page ?? 1);
$totalPages = (int) ($totalPages ?? 1);
$totalMessages = (int) ($totalMessages ?? 0);
$baseUrl = $baseUrl ?? '?';
$currentPerPage = (int) ($currentPerPage ?? mail_per_page());
$sep = str_contains($baseUrl, '?') ? (str_ends_with($baseUrl, '?') || str_ends_with($baseUrl, '&') ? '' : '&') : '?';
?>

<nav class="pagination" aria-label="Pagination">
    <?php if ($page > 1): ?>
        <a class="btn btn-secondary btn-sm pagination-btn" href="<?= e($baseUrl . $sep . 'page=' . ($page - 1)) ?>">← Prev</a>
    <?php else: ?>
        <span class="btn btn-secondary btn-sm pagination-btn is-disabled" aria-disabled="true">← Prev</span>
    <?php endif; ?>

    <div class="pagination-pages">
        <?php
        $start = max(1, $page - 2);
        $end = min($totalPages, $page + 2);
        if ($start > 1): ?>
            <a class="pagination-page" href="<?= e($baseUrl . $sep . 'page=1') ?>">1</a>
            <?php if ($start > 2): ?><span class="pagination-ellipsis">…</span><?php endif; ?>
        <?php endif;

        for ($i = $start; $i <= $end; $i++): ?>
            <?php if ($i === $page): ?>
                <span class="pagination-page is-current" aria-current="page"><?= $i ?></span>
            <?php else: ?>
                <a class="pagination-page" href="<?= e($baseUrl . $sep . 'page=' . $i) ?>"><?= $i ?></a>
            <?php endif; ?>
        <?php endfor;

        if ($end < $totalPages): ?>
            <?php if ($end < $totalPages - 1): ?><span class="pagination-ellipsis">…</span><?php endif; ?>
            <a class="pagination-page" href="<?= e($baseUrl . $sep . 'page=' . $totalPages) ?>"><?= $totalPages ?></a>
        <?php endif; ?>
    </div>

    <span class="pagination-info"><?= $totalMessages ?> message<?= $totalMessages === 1 ? '' : 's' ?> · Page <?= $page ?> of <?= $totalPages ?></span>

    <div class="pagination-per-page">
        <label for="per-page-select" class="pagination-per-page-label">Per page</label>
        <div class="select-field select-field-inline select-field-compact">
            <select id="per-page-select" class="per-page-select" data-base-url="<?= e($baseUrl) ?>">
                <?php foreach (mail_per_page_options() as $option): ?>
                    <?php
                    $perPageUrl = $baseUrl . $sep . 'per_page=' . $option . '&page=1';
                    ?>
                    <option value="<?= e($perPageUrl) ?>"<?= $option === $currentPerPage ? ' selected' : '' ?>><?= $option ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <?php if ($page < $totalPages): ?>
        <a class="btn btn-secondary btn-sm pagination-btn" href="<?= e($baseUrl . $sep . 'page=' . ($page + 1)) ?>">Next →</a>
    <?php else: ?>
        <span class="btn btn-secondary btn-sm pagination-btn is-disabled" aria-disabled="true">Next →</span>
    <?php endif; ?>
</nav>
