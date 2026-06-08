<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/src/helpers.php';

loadEnv(dirname(__DIR__) . '/.env');

$config = config('app');
if (!$config['debug']) {
    ini_set('display_errors', '0');
    error_reporting(0);
} else {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

session_name('dj_webmail_session');
session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
]);

use App\Controllers\AuthController;
use App\Controllers\ComposeController;
use App\Controllers\DashboardController;
use App\Controllers\MailController;
use App\Router;

$authController = new AuthController();
$mailController = new MailController();
$composeController = new ComposeController();
$dashboardController = new DashboardController();

$router = new Router();

$router->get('/login', fn () => $authController->showLogin());
$router->post('/login', fn () => $authController->login());
$router->get('/logout', fn () => $authController->logout());

$router->get('/', fn () => $mailController->home());
$router->get('/folder/{folderB64}/message/{uid}', fn ($p) => $mailController->read($p));
$router->get('/folder/{folderB64}', fn ($p) => $mailController->folder($p));
$router->get('/attachment', fn () => $mailController->attachment());
$router->post('/message/move', fn () => $mailController->move());
$router->post('/message/trash', fn () => $mailController->trash());

$router->get('/compose', fn () => $composeController->compose());
$router->get('/compose/reply', fn () => $composeController->reply());
$router->get('/compose/forward', fn () => $composeController->forward());
$router->post('/compose/send', fn () => $composeController->send());

$router->get('/status', fn () => $dashboardController->status());
$router->post('/test-email', fn () => $dashboardController->sendTestEmail());

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = $_SERVER['REQUEST_URI'] ?? '/';

$router->dispatch($method, $uri);
