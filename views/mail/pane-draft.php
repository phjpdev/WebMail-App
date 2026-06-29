<?php
/**
 * Reading-pane draft editor (no read-preview stack).
 *
 * @var string $draftFolder
 * @var int $draftUid
 */
?>
<div class="reading-pane-inner reading-pane-inner--draft">
    <div class="draft-editor-pane"
        data-draft-folder-b64="<?= e(encode_folder_path($draftFolder)) ?>"
        data-draft-uid="<?= (int) $draftUid ?>">
        <?php require base_path('views/partials/compose-form-draft.php'); ?>
    </div>
</div>
