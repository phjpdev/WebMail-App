<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/src/helpers.php';

bootstrapEnv(dirname(__DIR__));
bootstrapAppTimezone();

App\Services\FilterService::reprocess();
$result = App\Services\FilterService::runBackground(true);

echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
