<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/src/helpers.php';

bootstrapEnv(dirname(__DIR__));
bootstrapAppTimezone();

$service = new App\Services\AdminUserService();
$result = $service->backfillEmployees();
echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;

App\Services\FilterService::reprocess();
$filter = App\Services\FilterService::runBackground(true);
echo json_encode($filter, JSON_PRETTY_PRINT) . PHP_EOL;

$rows = App\Database::query(
    "SELECT f.imap_path, f.display_name, a.email
     FROM folders f
     LEFT JOIN aliases a ON a.default_folder_id = f.id
     WHERE f.folder_type = 'employee' AND f.active = 1"
)->fetchAll();
echo json_encode($rows, JSON_PRETTY_PRINT) . PHP_EOL;
