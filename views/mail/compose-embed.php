<?php
/**
 * Compose form fragment for the reading-pane slide-over (no layout).
 *
 * @var string|null $error
 * @var string|null $success
 */
?>
<div class="compose-panel-inner">
    <?php if (!empty($error)): ?>
        <p class="status status-error compose-panel-flash"><?= e($error) ?></p>
    <?php endif; ?>
    <?php if (!empty($success)): ?>
        <p class="status status-success compose-panel-flash"><?= e($success) ?></p>
    <?php endif; ?>
    <?php
    $embed = true;
    require base_path('views/partials/compose-form.php');
    ?>
</div>
