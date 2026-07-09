<?php
/**
 * One message in an Outlook-style conversation thread.
 *
 * @var array<string, mixed> $segment
 * @var array<string, string> $display
 * @var bool $isLatest
 * @var string $folderPath
 * @var int $uid
 * @var list<array<string, mixed>> $attachments
 * @var list<array<string, mixed>> $threadHistorySegments
 * @var string $threadSubject
 * @var bool $expandAllThread
 */
$isLatest = !empty($isLatest);
$expandAllThread = !empty($expandAllThread);
$showThreadHistoryToggle = !empty($showThreadHistoryToggle);
$threadHistorySegments = $threadHistorySegments ?? [];
$threadSubject = $threadSubject ?? '';
$hasBody = trim((string) ($segment['body_html'] ?? '')) !== ''
    || trim((string) ($segment['body'] ?? '')) !== '';
$quotedPlain = trim((string) ($segment['quoted_plain'] ?? ''));
$quotedHtml = trim((string) ($segment['quoted_html'] ?? ''));
$snippet = (string) ($segment['snippet'] ?? '');
$collapsedPreview = (string) ($display['collapsed_preview'] ?? $snippet);
$collapsed = !$expandAllThread && !$isLatest && $hasBody;
$hasQuoted = $isLatest && !$collapsed && !$showThreadHistoryToggle && ($quotedPlain !== '' || $quotedHtml !== '');
?>
<article class="mail-message-card<?= $isLatest ? ' mail-message-card--latest' : '' ?><?= $collapsed ? ' mail-message-card--collapsed' : '' ?>"
    <?= $collapsed ? ' data-mail-thread-card tabindex="0" role="button" aria-expanded="false"' : '' ?>>
    <?php if ($collapsed): ?>
    <div class="mail-message-collapsed">
        <div class="mail-message-avatar" style="background-color: <?= e($display['avatar_color']) ?>" aria-hidden="true"><?= e($display['avatar_initial']) ?></div>
        <div class="mail-message-collapsed-main">
            <div class="mail-message-collapsed-top">
                <span class="mail-message-from-name"><?= e($display['sender_name']) ?><?php if ($display['sender_email'] !== ''): ?>&lt;<?= e($display['sender_email']) ?>&gt;<?php endif; ?></span>
                <time class="mail-message-date" datetime="<?= e($segment['date'] ?? '') ?>"><?= e($display['display_date']) ?></time>
            </div>
            <?php if ($collapsedPreview !== ''): ?>
                <p class="mail-message-snippet"><?= e($collapsedPreview) ?></p>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="mail-message-expanded"<?= $collapsed ? ' hidden' : '' ?>>
        <header class="mail-message-header">
            <div class="mail-message-avatar" style="background-color: <?= e($display['avatar_color']) ?>" aria-hidden="true"><?= e($display['avatar_initial']) ?></div>
            <div class="mail-message-sender">
                <div class="mail-message-sender-row">
                    <div class="mail-message-from">
                        <span class="mail-message-from-name"><?= e($display['sender_name']) ?><?php if ($display['sender_email'] !== ''): ?>&lt;<?= e($display['sender_email']) ?>&gt;<?php endif; ?></span>
                    </div>
                    <time class="mail-message-date" datetime="<?= e($segment['date'] ?? '') ?>"><?= e($display['display_date']) ?></time>
                </div>
                <?php if ($display['display_to'] !== '—' || $display['display_cc'] !== ''): ?>
                <div class="mail-message-recipients">
                    <?php if ($display['display_to'] !== '—'): ?>
                        <span>To: <?= e($display['display_to']) ?></span>
                    <?php endif; ?>
                    <?php if ($display['display_cc'] !== ''): ?>
                        <span>Cc: <?= e($display['display_cc']) ?></span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </header>

        <div class="mail-message-body">
            <?php if (!empty($segment['body_html'])): ?>
                <div class="mail-body-html"><?= $segment['body_html'] ?></div>
            <?php elseif (trim((string) ($segment['body'] ?? '')) !== ''): ?>
                <pre class="mail-body-plain"><?= e($segment['body']) ?></pre>
            <?php elseif (!$hasQuoted && !$showThreadHistoryToggle): ?>
                <p class="text-muted">(No message body)</p>
            <?php endif; ?>

            <?php if ($showThreadHistoryToggle && $threadHistorySegments !== []): ?>
            <div class="mail-quoted-wrap mail-thread-history-wrap">
                <button type="button" class="compose-quoted-toggle mail-thread-history-toggle" aria-expanded="false" aria-label="Show previous messages">
                    <span class="compose-quoted-toggle-dots" aria-hidden="true">⋯</span>
                </button>
                <div class="mail-thread-inline-history compose-quoted-body" hidden>
                    <?= mail_render_thread_inline_history_html($threadHistorySegments, $threadSubject, $replyFrom ?? null, $folderPath) ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($hasQuoted): ?>
            <div class="mail-quoted-wrap">
                <button type="button" class="compose-quoted-toggle mail-quoted-toggle" aria-expanded="false" aria-label="Show quoted message">
                    <span class="compose-quoted-toggle-dots" aria-hidden="true">⋯</span>
                </button>
                <div class="mail-quoted-body compose-quoted-body" hidden>
                    <?php if ($quotedHtml !== ''): ?>
                        <div class="mail-body-html mail-body-html--quoted"><?= $quotedHtml ?></div>
                    <?php else: ?>
                        <pre class="compose-quoted-text"><?= e(ltrim($quotedPlain, "\n")) ?></pre>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($attachments)): ?>
        <?php
        // Gmail-style attachment cards: image thumbnails, file-type icons for the
        // rest, human-readable sizes, hover download button.
        $fmtAttSize = static function (int $b): string {
            if ($b >= 1048576) {
                return number_format($b / 1048576, 1) . ' MB';
            }
            if ($b >= 1024) {
                return number_format($b / 1024, 0) . ' KB';
            }
            return $b > 0 ? $b . ' B' : '';
        };
        $attKindOf = static function (string $mime, string $filename): string {
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            // Fall back to the extension: stored mimes are often a generic
            // application/octet-stream. (svg deliberately excluded from inline
            // image preview — same-origin inline svg can carry scripts.)
            if (str_starts_with($mime, 'image/')
                || in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'avif'], true)) {
                return 'image';
            }
            if ($mime === 'application/pdf' || $ext === 'pdf') {
                return 'pdf';
            }
            if (in_array($ext, ['doc', 'docx', 'rtf', 'odt'], true)) {
                return 'doc';
            }
            if (in_array($ext, ['xls', 'xlsx', 'csv', 'ods'], true)) {
                return 'sheet';
            }
            if (in_array($ext, ['zip', 'rar', '7z', 'gz', 'tar'], true)) {
                return 'zip';
            }
            return 'file';
        };
        $attCount = count($attachments);
        ?>
        <div class="attachments attachments--cards">
            <div class="attachments-cards-header">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                <?= $attCount ?> <?= $attCount === 1 ? 'Attachment' : 'Attachments' ?>
            </div>
            <div class="attachment-cards">
                <?php foreach ($attachments as $att): ?>
                    <?php
                    $attId = (string) ($att['id'] ?? '');
                    $attPending = !empty($att['pending']);
                    $attFolder = $cardFolder ?? $folderPath;
                    $attUid = (int) ($cardUid ?? $uid);
                    $filename = (string) ($att['filename'] ?? 'attachment');
                    $mime = (string) ($att['mime'] ?? 'application/octet-stream');
                    $size = (int) ($att['size'] ?? 0);
                    if ($attPending && $attUid > 0 && $attId !== '') {
                        $baseUrl = url('attachment?thread_pending=1&folder=' . encode_folder_path($attFolder) . '&uid=' . $attUid . '&part=' . urlencode($attId));
                    } elseif ($attUid > 0 && $attId !== '') {
                        $baseUrl = url('attachment?folder=' . encode_folder_path($attFolder) . '&uid=' . $attUid . '&part=' . urlencode($attId));
                    } else {
                        $baseUrl = '';
                    }
                    $kind = $attKindOf($mime, $filename);
                    $canInline = $kind === 'image' || $kind === 'pdf';
                    $openUrl = $baseUrl !== '' && $canInline ? $baseUrl . '&disposition=inline' : $baseUrl;
                    $sizeLabel = $fmtAttSize($size);
                    $ext = strtoupper((string) pathinfo($filename, PATHINFO_EXTENSION));
                    ?>
                    <div class="attachment-card attachment-card--<?= e($kind) ?>">
                        <?php if ($openUrl !== ''): ?>
                        <a class="attachment-card-open" href="<?= e($openUrl) ?>"<?= $canInline ? ' target="_blank" rel="noopener"' : '' ?> title="<?= e($filename) ?>">
                        <?php else: ?>
                        <span class="attachment-card-open" title="<?= e($filename) ?>">
                        <?php endif; ?>
                            <span class="attachment-card-thumb">
                                <?php if ($kind === 'image' && $baseUrl !== ''): ?>
                                    <img src="<?= e($baseUrl . '&disposition=inline') ?>" alt="<?= e($filename) ?>" loading="lazy">
                                <?php else: ?>
                                    <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
                                    <?php if ($ext !== ''): ?><span class="attachment-card-ext"><?= e(mb_substr($ext, 0, 4)) ?></span><?php endif; ?>
                                <?php endif; ?>
                            </span>
                            <span class="attachment-card-footer">
                                <span class="attachment-card-name"><?= e($filename) ?></span>
                                <?php if ($sizeLabel !== ''): ?><span class="attachment-card-size"><?= e($sizeLabel) ?></span><?php endif; ?>
                            </span>
                        <?php if ($openUrl !== ''): ?>
                        </a>
                        <?php else: ?>
                        </span>
                        <?php endif; ?>
                        <?php if ($baseUrl !== ''): ?>
                        <a class="attachment-card-download" href="<?= e($baseUrl) ?>" title="Download" aria-label="Download <?= e($filename) ?>">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>
                        </a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($isLatest && !empty($isPane)): ?>
        <div class="mail-message-compose-slot" data-compose-slot></div>
        <?php endif; ?>
    </div>
</article>
