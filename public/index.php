<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/src/helpers.php';

bootstrapEnv(dirname(__DIR__));

$config = config('app');
if (!$config['debug']) {
    ini_set('display_errors', '0');
    error_reporting(0);
} else {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

session_name('dj_webmail_session');

// Don't let PHP emit its default "no-store" session cache headers — those
// disable the browser's back/forward cache and make navigating Back slow
// (a full reload + IMAP round-trip). We send our own headers below.
session_cache_limiter('');

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
]);

// Allow instant Back/Forward (bfcache) while keeping authenticated pages out
// of shared/proxy caches. "no-cache" still forces revalidation for normal
// navigations, but does not block bfcache the way "no-store" does.
header('Cache-Control: private, no-cache, must-revalidate, max-age=0');

use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\ComposeController;
use App\Controllers\DashboardController;
use App\Controllers\MailController;
use App\Controllers\SettingsController;
use App\Router;

$authController = new AuthController();
$mailController = new MailController();
$composeController = new ComposeController();
$dashboardController = new DashboardController();
$adminController = new AdminController();
$settingsController = new SettingsController();

$router = new Router();

$router->get('/login', fn () => $authController->showLogin());
$router->post('/login', fn () => $authController->login());
$router->get('/logout', fn () => $authController->logout());

$router->get('/change-password', fn () => $settingsController->changePasswordForm());
$router->post('/change-password', fn () => $settingsController->changePassword());
$router->get('/settings', fn () => $settingsController->index());
$router->post('/settings', fn () => $settingsController->update());

$router->get('/', fn () => $mailController->home());
$router->get('/folder/{folderB64}/message/{uid}/sync', fn ($p) => $mailController->messageSync($p));
$router->get('/folder/{folderB64}/message/{uid}', fn ($p) => $mailController->read($p));
$router->get('/folder/{folderB64}/sync', fn ($p) => $mailController->folderSync($p));
$router->get('/folder/{folderB64}', fn ($p) => $mailController->folder($p));
$router->get('/folders/unread', fn () => $mailController->foldersUnread());
$router->get('/attachment', fn () => $mailController->attachment());
$router->post('/message/move', fn () => $mailController->move());
$router->post('/message/trash', fn () => $mailController->trash());
$router->post('/message/bulk-move', fn () => $mailController->bulkMove());
$router->post('/message/bulk-trash', fn () => $mailController->bulkTrash());
$router->post('/message/mark-read', fn () => $mailController->markRead());
$router->post('/message/mark-unread', fn () => $mailController->markUnread());
$router->post('/message/bulk-mark-read', fn () => $mailController->bulkMarkRead());
$router->post('/message/bulk-mark-unread', fn () => $mailController->bulkMarkUnread());
$router->post('/message/flag', fn () => $mailController->flag());
$router->post('/message/unflag', fn () => $mailController->unflag());
$router->post('/message/spam', fn () => $mailController->spam());
$router->post('/filter/run', fn () => $mailController->runFilter());

$router->get('/compose', fn () => $composeController->compose());
$router->get('/compose/reply', fn () => $composeController->reply());
$router->get('/compose/reply-all', fn () => $composeController->replyAll());
$router->get('/compose/forward', fn () => $composeController->forward());
$router->post('/compose/send', fn () => $composeController->send());
$router->post('/compose/draft', fn () => $composeController->saveDraft());

$router->get('/status', fn () => $dashboardController->status());
$router->post('/test-email', fn () => $dashboardController->sendTestEmail());

$router->get('/admin', fn () => $adminController->dashboard());
$router->post('/admin/sync', fn () => $adminController->sync());
$router->get('/admin/audit', fn () => $adminController->auditIndex());
$router->get('/admin/users', fn () => $adminController->usersIndex());
$router->get('/admin/users/create', fn () => $adminController->usersCreate());
$router->post('/admin/users/store', fn () => $adminController->usersStore());
$router->post('/admin/users/backfill', fn () => $adminController->usersBackfill());
$router->get('/admin/users/{id}/edit', fn ($p) => $adminController->usersEdit($p));
$router->post('/admin/users/{id}/update', fn ($p) => $adminController->usersUpdate($p));
$router->post('/admin/users/{id}/disable', fn ($p) => $adminController->usersDisable($p));
$router->get('/admin/aliases', fn () => $adminController->aliasesIndex());
$router->get('/admin/aliases/create', fn () => $adminController->aliasesCreate());
$router->post('/admin/aliases/store', fn () => $adminController->aliasesStore());
$router->get('/admin/aliases/{id}/edit', fn ($p) => $adminController->aliasesEdit($p));
$router->post('/admin/aliases/{id}/update', fn ($p) => $adminController->aliasesUpdate($p));
$router->get('/admin/folders', fn () => $adminController->foldersIndex());
$router->get('/admin/folders/create', fn () => $adminController->foldersCreate());
$router->post('/admin/folders/store', fn () => $adminController->foldersStore());
$router->get('/admin/rules', fn () => $adminController->rulesIndex());
$router->get('/admin/rules/create', fn () => $adminController->rulesCreate());
$router->post('/admin/rules/store', fn () => $adminController->rulesStore());
$router->post('/admin/rules/reorder', fn () => $adminController->rulesReorder());
$router->get('/admin/rules/{id}/edit', fn ($p) => $adminController->rulesEdit($p));
$router->post('/admin/rules/{id}/update', fn ($p) => $adminController->rulesUpdate($p));
$router->post('/admin/rules/{id}/toggle', fn ($p) => $adminController->rulesToggle($p));

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = $_SERVER['REQUEST_URI'] ?? '/';

$router->dispatch($method, $uri);
