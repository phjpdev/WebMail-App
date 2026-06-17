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

    $raw = file_get_contents($path);
    if ($raw === false) {
        return;
    }

    // Strip UTF-8 BOM (common when editing .env on Windows)
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
    $lines = preg_split('/\R/', $raw) ?: [];

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

        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        $_ENV[$key] = rtrim($value, "\r");
        // Never call putenv() — # and ; in passwords break getenv().
    }
}

/**
 * Read a secret from .env. Supports plain value or base64 (_B64 suffix).
 * Use _B64 for passwords with #, &, ;, +, {, etc.
 */
function env_secret(string $key): string
{
    $b64Key = $key . '_B64';
    if (array_key_exists($b64Key, $_ENV) && $_ENV[$b64Key] !== '') {
        $decoded = base64_decode($_ENV[$b64Key], true);

        return $decoded !== false ? $decoded : '';
    }

    return array_key_exists($key, $_ENV) ? (string) $_ENV[$key] : '';
}

/**
 * Load environment from .env (preferred) or common production filenames.
 */
function bootstrapEnv(string $baseDir): void
{
    foreach (['.env', '.env.production', '.env-production'] as $file) {
        loadEnv($baseDir . '/' . $file);
        if (!empty($_ENV['DB_NAME'])) {
            return;
        }
    }
}

function env(string $key, mixed $default = null): mixed
{
    if (!array_key_exists($key, $_ENV)) {
        return $default;
    }

    $value = $_ENV[$key];

    return ($value === '' && $default !== null) ? $default : $value;
}

/**
 * Application base URL for links and redirects.
 * Uses APP_URL from .env, or auto-detects from the current request.
 * If .env still points at localhost on a live domain, the request host wins.
 */
function app_base_url(): string
{
    $configured = env('APP_URL', '');
    $requestHost = $_SERVER['HTTP_HOST'] ?? '';

    if ($configured !== '') {
        $configured = rtrim($configured, '/');
        $localHosts = ['localhost', '127.0.0.1'];
        $configHost = parse_url($configured, PHP_URL_HOST) ?: '';
        $isLocalConfig = in_array($configHost, $localHosts, true)
            || str_contains($configured, 'localhost')
            || str_contains($configured, '127.0.0.1');
        $isLocalRequest = $requestHost === ''
            || in_array($requestHost, $localHosts, true)
            || str_starts_with($requestHost, '127.0.0.1');

        if (!($isLocalConfig && !$isLocalRequest)) {
            return $configured;
        }
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '') === 'on');
    $scheme = $https ? 'https' : 'http';
    $host = $requestHost !== '' ? $requestHost : 'localhost';

    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $basePath = dirname($script);
    if ($basePath === '/' || $basePath === '.') {
        $basePath = '';
    }

    return rtrim($scheme . '://' . $host . $basePath, '/');
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
        error_page(500, 'View not found.');
    }

    require $viewPath;
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['_csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): bool
{
    $token = $_POST['_csrf'] ?? '';
    $session = $_SESSION['_csrf_token'] ?? '';

    return $token !== '' && $session !== '' && hash_equals($session, $token);
}

function verify_csrf_or_fail(): void
{
    if (!csrf_verify()) {
        http_response_code(403);
        error_page(403, 'Invalid security token. Please go back and try again.');
        exit;
    }
}

function client_ip(): string
{
    $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // Only trust X-Forwarded-For when the direct peer is a local/private reverse
    // proxy. Otherwise the header is fully attacker-controlled and could be used
    // to evade the login rate limiter.
    $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($forwarded !== '' && is_private_ip($remote)) {
        $first = trim(explode(',', $forwarded)[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) {
            return $first;
        }
    }

    return $remote;
}

function is_private_ip(string $ip): bool
{
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return false;
    }

    if ($ip === '127.0.0.1' || $ip === '::1') {
        return true;
    }

    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}

/**
 * Whether the current request expects a JSON response (AJAX).
 */
function wants_json(): bool
{
    if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest') {
        return true;
    }

    return str_contains(strtolower($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
}

/**
 * @param array<string, mixed> $data
 */
function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function requireAuth(): void
{
    if (!App\Auth::isLoggedIn()) {
        redirect('login');
    }

    // An account disabled mid-session is logged out immediately.
    if (!App\Auth::ensureActive()) {
        App\Auth::logout();
        if (wants_json()) {
            json_response(['ok' => false, 'error' => 'Your session has ended.'], 401);
        }
        redirect('login');
    }

    App\Auth::enforcePasswordChange();
}

function requireAdmin(): void
{
    requireAuth();

    if (!App\Auth::isAdmin()) {
        error_page(403);
        exit;
    }
}

/**
 * Abort the request unless the current user may access the given IMAP folder.
 * Prevents employees from reaching other people's folders via crafted URLs.
 */
function assert_folder_access(string $imapPath): void
{
    if (\App\Services\FolderCache::canAccess($imapPath)) {
        return;
    }

    if (wants_json()) {
        json_response(['ok' => false, 'error' => 'You do not have access to that folder.'], 403);
    }

    error_page(403, 'You do not have access to that folder.');
}

function error_page(int $code, ?string $message = null): void
{
    $titles = [
        403 => 'Access denied',
        404 => 'Page not found',
        405 => 'Method not allowed',
        500 => 'Server error',
    ];
    $defaults = [
        403 => 'You do not have permission to access this page.',
        404 => 'The page you requested could not be found.',
        405 => 'That action is not allowed on this URL.',
        500 => 'Something went wrong. Please try again later.',
    ];

    http_response_code($code);
    view('errors/' . $code, [
        'title' => $titles[$code] ?? 'Error',
        'message' => $message ?? ($defaults[$code] ?? 'An error occurred.'),
        'code' => $code,
    ]);
    exit;
}

function schema_has_column(string $table, string $column): bool
{
    static $cache = [];

    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $dbName = config('database')['name'];
        $row = \App\Database::fetchOne(
            'SELECT 1 AS ok FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            [$dbName, $table, $column]
        );
        $cache[$key] = $row !== null;
    } catch (\Throwable) {
        $cache[$key] = false;
    }

    return $cache[$key];
}

/**
 * @return array<string, mixed>
 */
function user_preferences(?array $user = null): array
{
    $user = $user ?? App\Auth::user();
    if ($user === null) {
        return [];
    }

    $defaults = [
        'poll_interval' => config('app')['mail_poll_interval'],
        'sound_enabled' => false,
        'notify_enabled' => false,
        'theme' => 'light',
    ];

    $prefs = $user['preferences'] ?? null;
    if (is_string($prefs)) {
        $decoded = json_decode($prefs, true);
        $prefs = is_array($decoded) ? $decoded : [];
    } elseif (!is_array($prefs)) {
        $prefs = [];
    }

    return array_merge($defaults, $prefs);
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
    return resolve_system_folder(['trash'], 'INBOX.Trash');
}

function spam_folder_path(): string
{
    return resolve_system_folder(['spam', 'junk'], 'INBOX.spam');
}

/**
 * Resolve a system folder (Trash/Spam) from the folders table/cache by matching
 * any of the given keywords in the path, falling back to a sensible default.
 *
 * @param list<string> $keywords
 */
function resolve_system_folder(array $keywords, string $default): string
{
    try {
        foreach (\App\Services\FolderCache::load()['folders'] as $folder) {
            $lower = strtolower($folder['path']);
            foreach ($keywords as $keyword) {
                if (str_contains($lower, $keyword)) {
                    return $folder['path'];
                }
            }
        }
    } catch (\Throwable) {
        // fall through to default
    }

    return $default;
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

function normalize_email_token(string $token): string
{
    $token = trim($token);
    if (preg_match('/<([^>]+)>/', $token, $m)) {
        return trim($m[1]);
    }

    return $token;
}

/**
 * @return array{valid: list<string>, invalid: list<string>}
 */
function parse_email_list(string $input): array
{
    $valid = [];
    $invalid = [];
    $parts = preg_split('/[,;]+/', $input);

    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }

        $email = normalize_email_token($part);
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $valid[] = $email;
        } else {
            $invalid[] = $part;
        }
    }

    return ['valid' => $valid, 'invalid' => $invalid];
}

/**
 * @return list<int>
 */
function mail_per_page_options(): array
{
    return [15, 25, 50, 100];
}

function mail_per_page(): int
{
    $allowed = mail_per_page_options();

    if (isset($_GET['per_page'])) {
        $requested = (int) $_GET['per_page'];
        if (in_array($requested, $allowed, true)) {
            $_SESSION['mail_per_page'] = $requested;
            persist_user_preference('mail_per_page', $requested);

            return $requested;
        }
    }

    $session = (int) ($_SESSION['mail_per_page'] ?? 0);
    if (in_array($session, $allowed, true)) {
        return $session;
    }

    // Fall back to the per-user saved preference before the global default.
    $pref = (int) (user_preferences()['mail_per_page'] ?? 0);
    if (in_array($pref, $allowed, true)) {
        $_SESSION['mail_per_page'] = $pref;

        return $pref;
    }

    $default = (int) config('app')['mail_per_page'];

    return in_array($default, $allowed, true) ? $default : 15;
}

/**
 * Persist a single key into the current user's JSON preferences (best effort).
 */
function persist_user_preference(string $key, mixed $value): void
{
    $user = App\Auth::user();
    if ($user === null || empty($user['id']) || !schema_has_column('users', 'preferences')) {
        return;
    }

    $prefs = user_preferences($user);
    if (($prefs[$key] ?? null) === $value) {
        return;
    }
    $prefs[$key] = $value;

    try {
        $encoded = json_encode($prefs);
        App\Database::query('UPDATE users SET preferences = ? WHERE id = ?', [$encoded, (int) $user['id']]);
        if (isset($_SESSION['auth_user']) && is_array($_SESSION['auth_user'])) {
            $_SESSION['auth_user']['preferences'] = $encoded;
        }
    } catch (\Throwable $e) {
        app_log('Failed to persist preference ' . $key . ': ' . $e->getMessage());
    }
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
