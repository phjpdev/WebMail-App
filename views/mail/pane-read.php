<?php
/**
 * Reading-pane fragment (no layout). Rendered to HTML for AJAX pane loads.
 *
 * @var array<string, mixed> $message
 * @var string $folderPath
 * @var string $folderB64
 * @var string $replyFrom
 * @var list<array{path: string, name: string}> $moveTargets
 * @var string $sanitizedHtml
 * @var int $pollInterval
 */
$isPane = true;
$threadKey = mail_normalize_thread_subject((string) ($message['subject'] ?? ''));
?>
<div class="reading-pane-inner print-area">
    <section class="card mail-read-card mail-read-card--pane"
        data-message-sync="1"
        data-folder-b64="<?= e($folderB64) ?>"
        data-uid="<?= (int) $message['uid'] ?>"
        <?php if (!empty($threadKey)): ?>data-thread-key="<?= e($threadKey) ?>"<?php endif; ?>
        data-seen="<?= !empty($message['seen']) ? '1' : '0' ?>"
        data-flagged="<?= !empty($message['flagged']) ? '1' : '0' ?>"
        data-sync-url="<?= e(url('folder/' . $folderB64 . '/message/' . (int) $message['uid'] . '/sync')) ?>"
        data-folder-url="<?= e(folder_url($folderPath)) ?>"
        data-poll-interval="<?= max(15, (int) ($pollInterval ?? 30)) ?>">
        <?php require base_path('views/partials/mail-read-content.php'); ?>
    </section>
</div>
