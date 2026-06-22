<?php ob_start(); ?>

<section class="page-header">
    <h2><?= e(ucfirst(str_replace('-', ' ', $mode))) ?></h2>
</section>

<section class="card">
    <?php
    $embed = false;
    require base_path('views/partials/compose-form.php');
    ?>
</section>

<?php
$content = ob_get_clean();
require base_path('views/layout.php');
