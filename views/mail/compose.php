<?php ob_start(); ?>

<section class="page-header">
    <h2><?= e(ucfirst($mode)) ?></h2>
</section>

<section class="card">
    <form method="post" action="<?= e(url('compose/send')) ?>" class="compose-form">
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
            <input type="email" id="to" name="to" value="<?= e($to) ?>" required>
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

<?php
$content = ob_get_clean();
require base_path('views/layout.php');
