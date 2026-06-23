<?php
/**
 * @var string $href
 * @var string $label
 * @var string $class Optional extra class names
 */
$class = trim('back-nav ' . ($class ?? ''));
?>
<a href="<?= e($href) ?>" class="<?= e($class) ?>">
    <svg class="back-nav-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>
    <span class="back-nav-label"><?= e($label) ?></span>
</a>
