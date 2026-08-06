<?php
/**
 * @var int $page
 * @var int $totalPages
 * @var int $totalMessages
 * @var string $baseUrl URL with trailing ? or & for query params
 * @var int $currentPerPage
 * @var int|null $serverTotal true IMAP message count (full-history browsing)
 * @var bool|null $backfillDone whether the folder's whole history is indexed
 */
if (($totalPages ?? 1) < 1) {
    return;
}
$page = (int) ($page ?? 1);
$totalPages = (int) ($totalPages ?? 1);
$totalMessages = (int) ($totalMessages ?? 0);
$baseUrl = $baseUrl ?? '?';
$currentPerPage = (int) ($currentPerPage ?? mail_per_page());
$serverTotal = (int) ($serverTotal ?? 0);
$backfillDone = !empty($backfillDone ?? true);
$historyRemaining = empty($searchQuery) && !$backfillDone && $serverTotal > $totalMessages;
$sep = str_contains($baseUrl, '?') ? (str_ends_with($baseUrl, '?') || str_ends_with($baseUrl, '&') ? '' : '&') : '?';
$rangeStart = $totalMessages === 0 ? 0 : (($page - 1) * $currentPerPage) + 1;
$rangeEnd = min($page * $currentPerPage, $totalMessages);
$rangeStart = min($rangeStart, max(0, $rangeEnd));
$prevUrl = $baseUrl . $sep . 'page=' . ($page - 1);
$nextUrl = $baseUrl . $sep . 'page=' . ($page + 1);
?>

<nav class="pagination" aria-label="Pagination">
    <div class="pagination-nav">
        <?php if ($page > 1): ?>
            <a class="pagination-nav-btn" href="<?= e($prevUrl) ?>" aria-label="Previous page">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
            </a>
        <?php else: ?>
            <span class="pagination-nav-btn is-disabled" aria-disabled="true" aria-label="Previous page">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
            </span>
        <?php endif; ?>

        <?php if ($page < $totalPages): ?>
            <a class="pagination-nav-btn" href="<?= e($nextUrl) ?>" aria-label="Next page">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
            </a>
        <?php else: ?>
            <span class="pagination-nav-btn is-disabled" aria-disabled="true" aria-label="Next page">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
            </span>
        <?php endif; ?>
    </div>

    <div class="pagination-summary">
        <span class="pagination-range"><?= $rangeStart ?>–<?= $rangeEnd ?> of <?= $totalMessages ?></span>
        <?php if ($totalPages > 1): ?>
            <span class="pagination-page-label">· Page <?= $page ?> of <?= $totalPages ?></span>
        <?php endif; ?>
        <?php if ($historyRemaining): ?>
            <span class="pagination-server-total" title="Older messages are still on the mail server — they load as you browse past the last page">· <?= $serverTotal ?> on server</span>
        <?php endif; ?>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="pagination-pages" aria-hidden="true">
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
        <?php if ($historyRemaining): ?>
            <a class="pagination-page pagination-load-older" href="<?= e($baseUrl . $sep . 'page=' . ($totalPages + 1)) ?>"
               title="Fetch the next batch of older messages from the mail server">Load older</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if ($historyRemaining && $totalPages <= 1): ?>
    <div class="pagination-pages" aria-hidden="true">
        <a class="pagination-page pagination-load-older" href="<?= e($baseUrl . $sep . 'page=' . ($totalPages + 1)) ?>"
           title="Fetch the next batch of older messages from the mail server">Load older</a>
    </div>
    <?php endif; ?>

    <div class="pagination-per-page">
        <label for="per-page-select" class="pagination-per-page-label">Show</label>
        <div class="select-field select-field-inline select-field-compact">
            <select id="per-page-select" class="per-page-select" data-base-url="<?= e($baseUrl) ?>" aria-label="Messages per page">
                <?php foreach (mail_per_page_options() as $option): ?>
                    <?php $perPageUrl = $baseUrl . $sep . 'per_page=' . $option . '&page=1'; ?>
                    <option value="<?= e($perPageUrl) ?>"<?= $option === $currentPerPage ? ' selected' : '' ?>><?= $option ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
</nav>
