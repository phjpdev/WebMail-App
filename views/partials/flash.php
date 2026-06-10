<div id="toast-stack" class="toast-stack" aria-live="polite" aria-atomic="true"></div>

<?php if (!empty($success)): ?>
    <div class="toast-payload" data-toast-type="success" data-toast-message="<?= e($success) ?>" hidden></div>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <div class="toast-payload" data-toast-type="error" data-toast-message="<?= e($error) ?>" hidden></div>
<?php endif; ?>
