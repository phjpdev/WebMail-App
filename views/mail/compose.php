<?php ob_start(); ?>

<section class="page-header">
    <h2><?= e(ucfirst($mode)) ?></h2>
</section>

<section class="card">
    <form method="post" action="<?= e(url('compose/send')) ?>" class="compose-form" id="compose-form">
        <input type="hidden" name="mode" value="<?= e($mode) ?>">
        <?php if (!empty($folderPath) && !empty($uid)): ?>
            <input type="hidden" name="folder" value="<?= e(encode_folder_path($folderPath)) ?>">
            <input type="hidden" name="uid" value="<?= (int) $uid ?>">
        <?php endif; ?>

        <div class="form-group">
            <label for="from_email">Send as</label>
            <select id="from_email" name="from_email" required>
                <?php foreach ($aliases as $alias): ?>
                    <option value="<?= e($alias['email']) ?>"<?= ($from_email === $alias['email']) ? ' selected' : '' ?>>
                        <?= e($alias['display_name']) ?> &lt;<?= e($alias['email']) ?>&gt;
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="to">To</label>
            <input type="text" id="to" name="to" value="<?= e($to) ?>" required
                   placeholder="email@example.com, other@example.com" autocomplete="email">
        </div>

        <p class="compose-cc-toggle">
            <button type="button" class="btn-link-muted" id="toggle-cc-bcc" aria-expanded="<?= (!empty($cc) || !empty($bcc)) ? 'true' : 'false' ?>">
                Cc / Bcc
            </button>
        </p>

        <div id="cc-bcc-fields" class="cc-bcc-fields"<?= (empty($cc) && empty($bcc)) ? ' hidden' : '' ?>>
            <div class="form-group">
                <label for="cc">Cc</label>
                <input type="text" id="cc" name="cc" value="<?= e($cc ?? '') ?>"
                       placeholder="email@example.com, other@example.com" autocomplete="off">
            </div>
            <div class="form-group">
                <label for="bcc">Bcc</label>
                <input type="text" id="bcc" name="bcc" value="<?= e($bcc ?? '') ?>"
                       placeholder="email@example.com, other@example.com" autocomplete="off">
            </div>
        </div>

        <div class="form-group">
            <label for="subject">Subject</label>
            <input type="text" id="subject" name="subject" value="<?= e($subject) ?>" required>
        </div>

        <div class="form-group">
            <label for="body">Message</label>
            <textarea id="body" name="body" rows="14" required><?= e($body) ?></textarea>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Send</button>
            <a class="btn btn-secondary" href="<?= e(url('')) ?>">Cancel</a>
        </div>
    </form>
</section>

<script>
(function () {
    var btn = document.getElementById('toggle-cc-bcc');
    var fields = document.getElementById('cc-bcc-fields');
    if (!btn || !fields) return;
    btn.addEventListener('click', function () {
        var open = fields.hidden;
        fields.hidden = !open;
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) fields.querySelector('input')?.focus();
    });
})();
</script>

<?php
$content = ob_get_clean();
require base_path('views/layout.php');
