<?php ob_start(); ?>

<section class="page-header page-header--read">
    <nav class="breadcrumb" aria-label="Back">
        <?php
        $href = folder_url($folderPath);
        $label = 'Back to folder';
        require base_path('views/partials/back-nav.php');
        ?>
    </nav>
    <h2 class="read-subject-title"><?= e($message['subject'] ?: '(no subject)') ?></h2>
</section>

<section class="card mail-read-card print-area"
    data-message-sync="1"
    data-folder-b64="<?= e($folderB64 ?? encode_folder_path($folderPath)) ?>"
    data-uid="<?= (int) $message['uid'] ?>"
    data-seen="<?= !empty($message['seen']) ? '1' : '0' ?>"
    data-flagged="<?= !empty($message['flagged']) ? '1' : '0' ?>"
    data-sync-url="<?= e(url('folder/' . ($folderB64 ?? encode_folder_path($folderPath)) . '/message/' . (int) $message['uid'] . '/sync')) ?>"
    data-folder-url="<?= e(folder_url($folderPath)) ?>"
    data-poll-interval="<?= (int) ($pollInterval ?? 30) ?>">
    <?php
    $isPane = false;
    require base_path('views/partials/mail-read-content.php');
    ?>
</section>

<?php
$content = ob_get_clean();
require base_path('views/layout.php');
