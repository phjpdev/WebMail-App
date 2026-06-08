<?php

declare(strict_types=1);

/**
 * Load .env file into $_ENV and putenv.
 */
function loadEnv(string $path): void
{
    if (!is_file($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $key = trim($parts[0]);
        $value = trim($parts[1]);
        $value = trim($value, "\"'");

        $_ENV[$key] = $value;
        putenv("$key=$value");
    }
}

function env(string $key, mixed $default = null): mixed
{
    $value = $_ENV[$key] ?? getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }

    return $value;
}

function config(string $file): array
{
    static $cache = [];

    if (!isset($cache[$file])) {
        $cache[$file] = require dirname(__DIR__) . "/config/{$file}.php";
    }

    return $cache[$file];
}

function app_log(string $message): void
{
    $path = config('app')['log_path'];
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $line = sprintf("[%s] %s\n", date('Y-m-d H:i:s'), $message);
    file_put_contents($path, $line, FILE_APPEND);
}

function base_path(string $path = ''): string
{
    return dirname(__DIR__) . ($path !== '' ? '/' . ltrim($path, '/') : '');
}

function url(string $path = ''): string
{
    $base = config('app')['url'];
    $path = ltrim($path, '/');

    return $path === '' ? $base : $base . '/' . $path;
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

function flash(string $key, ?string $message = null): ?string
{
    if ($message !== null) {
        $_SESSION['_flash'][$key] = $message;
        return null;
    }

    $value = $_SESSION['_flash'][$key] ?? null;
    unset($_SESSION['_flash'][$key]);

    return $value;
}

function view(string $name, array $data = []): void
{
    extract($data, EXTR_SKIP);
    $viewPath = base_path("views/{$name}.php");

    if (!is_file($viewPath)) {
        http_response_code(500);
        echo "View not found: {$name}";
        exit;
    }

    require $viewPath;
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function requireAuth(): void
{
    if (!App\Auth::isLoggedIn()) {
        redirect('login');
    }
}

function requireAdmin(): void
{
    requireAuth();

    if (!App\Auth::isAdmin()) {
        http_response_code(403);
        echo '403 Forbidden';
        exit;
    }
}

function encode_folder_path(string $path): string
{
    return rtrim(strtr(base64_encode($path), '+/', '-_'), '=');
}

function decode_folder_path(string $encoded): string
{
    $padded = strtr($encoded, '-_', '+/');
    $padLen = strlen($padded) % 4;
    if ($padLen > 0) {
        $padded .= str_repeat('=', 4 - $padLen);
    }

    $decoded = base64_decode($padded, true);

    return $decoded === false ? '' : $decoded;
}

function folder_url(string $folderPath, string $suffix = ''): string
{
    $encoded = encode_folder_path($folderPath);
    $path = 'folder/' . $encoded;

    if ($suffix !== '') {
        $path .= '/' . ltrim($suffix, '/');
    }

    return url($path);
}

function message_url(string $folderPath, int $uid): string
{
    return folder_url($folderPath, 'message/' . $uid);
}

function trash_folder_path(): string
{
    return 'INBOX.Trash';
}

function format_mail_date(?string $date): string
{
    if ($date === null || $date === '') {
        return '';
    }

    $ts = strtotime($date);
    if ($ts === false) {
        return $date;
    }

    $now = time();
    $diff = $now - $ts;

    if ($diff < 86400 && date('Y-m-d', $ts) === date('Y-m-d', $now)) {
        return date('g:i A', $ts);
    }

    if ($diff < 604800) {
        return date('D g:i A', $ts);
    }

    if (date('Y', $ts) === date('Y', $now)) {
        return date('M j', $ts);
    }

    return date('M j, Y', $ts);
}

function folder_icon_type(string $path): string
{
    $lower = strtolower($path);
    if ($path === 'INBOX') {
        return 'inbox';
    }
    if (str_contains($lower, 'sent')) {
        return 'sent';
    }
    if (str_contains($lower, 'draft')) {
        return 'draft';
    }
    if (str_contains($lower, 'trash')) {
        return 'trash';
    }
    if (str_contains($lower, 'spam') || str_contains($lower, 'junk')) {
        return 'spam';
    }

    return 'folder';
}

function format_mail_from(?string $from): string
{
    if ($from === null || $from === '') {
        return 'Unknown';
    }

    if (preg_match('/^(.+?)\s*<[^>]+>$/', $from, $m)) {
        return trim($m[1], '"\' ');
    }

    if (strlen($from) > 40) {
        return substr($from, 0, 37) . '…';
    }

    return $from;
}
