<?php
/**
 * @var string $from_email
 * @var list<array{email: string, display_name: string}> $aliases
 * @var bool $send_as_fixed
 */
$sendAsFixed = !empty($send_as_fixed);
?>
<div class="form-group compose-send-as">
    <label<?= $sendAsFixed ? '' : ' for="from_email"' ?>>Send as</label>
    <?php if ($sendAsFixed): ?>
        <div class="compose-send-as-static" id="from_email_display"><?= e($from_email) ?></div>
        <input type="hidden" id="from_email" name="from_email" value="<?= e($from_email) ?>">
    <?php else: ?>
        <div class="select-field">
            <select id="from_email" name="from_email" required>
                <?php foreach ($aliases as $alias): ?>
                    <option value="<?= e($alias['email']) ?>"<?= ($from_email === $alias['email']) ? ' selected' : '' ?>>
                        <?= e($alias['display_name']) ?> &lt;<?= e($alias['email']) ?>&gt;
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>
</div>
