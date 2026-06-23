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
?>
<div class="reading-pane-inner print-area">
    <div class="reading-pane-subject-bar">
        <h3 class="reading-pane-subject"><?= e($message['subject'] ?: '(no subject)') ?></h3>
    </div>
    <section class="card mail-read-card mail-read-card--pane"
        data-message-sync="1"
        data-folder-b64="<?= e($folderB64) ?>"
        data-uid="<?= (int) $message['uid'] ?>"
        data-seen="<?= !empty($message['seen']) ? '1' : '0' ?>"
        data-flagged="<?= !empty($message['flagged']) ? '1' : '0' ?>"
        data-sync-url="<?= e(url('folder/' . $folderB64 . '/message/' . (int) $message['uid'] . '/sync')) ?>"
        data-folder-url="<?= e(folder_url($folderPath)) ?>"
        data-poll-interval="<?= (int) $pollInterval ?>">
        <?php require base_path('views/partials/mail-read-content.php'); ?>
    </section>
</div>
