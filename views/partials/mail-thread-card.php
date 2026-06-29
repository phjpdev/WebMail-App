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
 */
$isLatest = !empty($isLatest);
$hasBody = trim((string) ($segment['body_html'] ?? '')) !== ''
    || trim((string) ($segment['body'] ?? '')) !== '';
$quotedPlain = trim((string) ($segment['quoted_plain'] ?? ''));
$quotedHtml = trim((string) ($segment['quoted_html'] ?? ''));
$snippet = (string) ($segment['snippet'] ?? '');
$collapsedPreview = (string) ($display['collapsed_preview'] ?? $snippet);
$collapsed = !$isLatest && $hasBody;
$hasQuoted = $isLatest && !$collapsed && ($quotedPlain !== '' || $quotedHtml !== '');
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
            <?php elseif (!$hasQuoted): ?>
                <p class="text-muted">(No message body)</p>
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
        <div class="attachments attachments--message">
            <strong>Attachments</strong>
            <ul>
                <?php foreach ($attachments as $att): ?>
                    <?php
                    $baseUrl = url('attachment?folder=' . encode_folder_path($folderPath) . '&uid=' . $uid . '&part=' . urlencode($att['id']));
                    $isPreview = str_starts_with($att['mime'], 'image/') || $att['mime'] === 'application/pdf';
                    ?>
                    <li>
                        <a href="<?= e($baseUrl) ?>"><?= e($att['filename']) ?> (<?= number_format($att['size'] / 1024, 1) ?> KB)</a>
                        <?php if ($isPreview): ?>
                            · <a href="<?= e($baseUrl . '&disposition=inline') ?>" target="_blank" rel="noopener">Preview</a>
                        <?php endif; ?>
                    </li>
                    <?php if (str_starts_with($att['mime'], 'image/')): ?>
                    <li class="attachment-preview">
                        <img src="<?= e($baseUrl . '&disposition=inline') ?>" alt="<?= e($att['filename']) ?>" loading="lazy">
                    </li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php if ($isLatest && !empty($isPane)): ?>
        <div class="mail-message-compose-slot" data-compose-slot></div>
        <?php endif; ?>
    </div>
</article>
