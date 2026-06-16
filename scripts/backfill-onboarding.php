<?php

declare(strict_types=1);

/**
 * One-off / repeatable backfill: ensure every active employee has a personal
 * folder, send-as alias, and routing rule, then re-route existing INBOX mail.
 *
 * Usage:  php scripts/backfill-onboarding.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/src/helpers.php';

bootstrapEnv(dirname(__DIR__));

if (session_status() !== PHP_SESSION_ACTIVE) {
    $_SESSION = [];
}

use App\Services\AdminUserService;
use App\Services\FilterService;

fwrite(STDOUT, "Backfilling employee onboarding...\n");

$result = (new AdminUserService())->backfillEmployees();

fwrite(STDOUT, sprintf(
    "Provisioned: %d, already set up: %d\n",
    $result['provisioned'],
    $result['skipped']
));

if ($result['users'] !== []) {
    fwrite(STDOUT, '  -> ' . implode(', ', $result['users']) . "\n");
}

fwrite(STDOUT, "Routing existing inbox mail...\n");

FilterService::clearSessionFlag();
$filter = FilterService::runIfNeeded(true);

fwrite(STDOUT, sprintf(
    "Filter pass: processed=%d, moved=%d, errors=%d, duration=%dms\n",
    $filter['processed'] ?? 0,
    $filter['moved'] ?? 0,
    count($filter['errors'] ?? []),
    $filter['duration_ms'] ?? 0
));

foreach ($filter['errors'] ?? [] as $error) {
    fwrite(STDERR, '  ! ' . $error . "\n");
}

fwrite(STDOUT, "Done.\n");
