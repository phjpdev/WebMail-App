<?php
/**
 * @var string $from_email
 * @var list<array{email: string, display_name: string}> $aliases
 * @var bool $send_as_fixed
 * @var string $compose_send_as_variant
 */
$sendAsFixed = !empty($send_as_fixed);
$variant = (string) ($compose_send_as_variant ?? 'default');
$aliases = compose_send_as_aliases((string) ($from_email ?? ''), $aliases ?? []);
$fromEmail = trim((string) ($from_email ?? ''));
if ($fromEmail === '' && $aliases !== []) {
    $fromEmail = (string) ($aliases[0]['email'] ?? '');
}
$sendAsName = compose_send_as_display_name($fromEmail, $aliases);
$useStaticSendAs = $sendAsFixed || count($aliases) <= 1;
$rowClass = 'compose-send-as' . ($variant === 'draft' ? ' compose-send-as--draft' : '');
?>
<div class="form-group <?= e($rowClass) ?>">
    <label class="compose-send-as-label"<?= $useStaticSendAs ? '' : ' for="from_email"' ?>>Send as</label>
    <?php if ($useStaticSendAs): ?>
        <div class="compose-send-as-identity" id="from_email_display">
            <span class="compose-send-as-avatar" style="background-color: <?= e(mail_avatar_color($fromEmail)) ?>" aria-hidden="true"><?= e(mail_avatar_initials_from_name($sendAsName !== '' ? $sendAsName : $fromEmail)) ?></span>
            <span class="compose-send-as-text">
                <?php if ($sendAsName !== '' && strcasecmp($sendAsName, $fromEmail) !== 0): ?>
                    <span class="compose-send-as-name"><?= e($sendAsName) ?></span>
                <?php endif; ?>
                <span class="compose-send-as-email"><?= e($fromEmail) ?></span>
            </span>
        </div>
        <input type="hidden" id="from_email" name="from_email" value="<?= e($fromEmail) ?>">
    <?php else: ?>
        <div class="compose-send-as-control select-field select-field--compose">
            <select id="from_email" name="from_email" required>
                <?php foreach ($aliases as $alias): ?>
                    <option value="<?= e($alias['email']) ?>"<?= (strcasecmp($fromEmail, $alias['email']) === 0) ? ' selected' : '' ?>>
                        <?= e($alias['display_name']) ?> &lt;<?= e($alias['email']) ?>&gt;
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>
</div>
