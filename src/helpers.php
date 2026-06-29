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

/**
 * Render a view template and return the HTML string (for AJAX fragments).
 */
function view_string(string $name, array $data = []): string
{
    ob_start();
    view($name, $data);

    return ob_get_clean() ?: '';
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
        if (wants_json()) {
            json_response(['ok' => false, 'error' => 'Invalid security token. Please refresh the page and try again.'], 403);
        }
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
function json_encode_safe(array $data): string
{
    $json = json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        app_log('json_encode failed: ' . json_last_error_msg());

        return '{"ok":false,"error":"Could not encode response."}';
    }

    return $json;
}

/**
 * @param array<string, mixed> $data
 */
function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode_safe($data);
    exit;
}

/**
 * Send JSON, flush the HTTP response, then run work in the background.
 *
 * @param array<string, mixed> $data
 */
function json_response_then(array $data, callable $after, int $status = 200): never
{
    // Release the session before responding so parallel folder loads are not
    // blocked while background IMAP work runs in this same PHP process.
    releaseSessionLock();

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Connection: close');
    $body = json_encode_safe($data);
    header('Content-Length: ' . (string) strlen($body));
    echo $body;
    finish_background($after);
    exit;
}

function redirect_then(string $path, callable $after): never
{
    releaseSessionLock();
    header('Location: ' . url($path));
    finish_background($after);
    exit;
}

function finish_background(callable $after): void
{
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();
    }

    ignore_user_abort(true);
    @set_time_limit(120);

    try {
        $after();
    } catch (\Throwable $e) {
        app_log('Background task failed: ' . $e->getMessage());
    }
}

/**
 * Fire-and-forget HTTP GET to this app (carries the current session cookie).
 * On Apache mod_php the client connection stays open until the script ends, so
 * heavy IMAP work must run in a separate request instead of finish_background().
 */
function dispatch_async_request(string $path, array $query = []): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    $targetUrl = url($path);
    if ($query !== []) {
        $targetUrl .= (str_contains($targetUrl, '?') ? '&' : '?') . http_build_query($query);
    }

    $parts = parse_url($targetUrl);
    if ($parts === false || empty($parts['host'])) {
        return;
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
    $host = (string) $parts['host'];
    $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    $pathAndQuery = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

    $cookie = '';
    if (session_status() === PHP_SESSION_ACTIVE && session_id() !== '') {
        $cookie = session_name() . '=' . session_id();
    }

    $errno = 0;
    $errstr = '';
    $remote = ($scheme === 'https' ? 'ssl://' : '') . $host;
    $fp = @fsockopen($remote, $port, $errno, $errstr, 2);
    if ($fp === false) {
        app_log('dispatch_async_request connect failed: ' . $errstr);

        return;
    }

    stream_set_timeout($fp, 2);

    if ($scheme === 'https') {
        @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
    }

    $hostHeader = $host;
    if (($scheme === 'http' && $port !== 80) || ($scheme === 'https' && $port !== 443)) {
        $hostHeader .= ':' . $port;
    }

    $headers = "GET {$pathAndQuery} HTTP/1.1\r\n"
        . "Host: {$hostHeader}\r\n"
        . "Connection: Close\r\n";
    if ($cookie !== '') {
        $headers .= "Cookie: {$cookie}\r\n";
    }
    $headers .= "\r\n";

    @fwrite($fp, $headers);
    @fclose($fp);
}

/**
 * Open the session, run a short write, then release the lock immediately.
 * Use inside background tasks instead of holding the session across IMAP work.
 */
function with_session_write(callable $fn): void
{
    ensure_session_writable();

    try {
        $fn();
    } finally {
        releaseSessionLock();
    }
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

/**
 * Release the session lock so parallel XHR (pane + sync) are not serialized.
 * Call after requireAuth() on JSON/API handlers that do not write session data.
 */
function releaseSessionLock(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}

/**
 * Re-open the session for writes after releaseSessionLock() (e.g. post-send background work).
 */
function ensure_session_writable(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

/**
 * Persist session badge updates and release the lock so badge polls can run in parallel.
 */
function flush_session_for_poll(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
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

/** Decode a URL folder token and map it to the exact IMAP mailbox path. */
function mail_folder_path(string $encoded): string
{
    return \App\Services\FolderCache::resolvePath(decode_folder_path($encoded));
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

/**
 * Personal inbox path for the logged-in employee (e.g. INBOX.Jean).
 */
function employee_linked_inbox_path(?array $user = null): ?string
{
    static $cache = [];

    $user = $user ?? App\Auth::user();
    if ($user === null || ($user['role'] ?? '') !== 'employee') {
        return null;
    }

    $userId = (int) $user['id'];
    if (array_key_exists($userId, $cache)) {
        return $cache[$userId];
    }

    try {
        $row = App\Database::fetchOne(
            "SELECT imap_path FROM folders WHERE linked_user_id = ? AND folder_type = 'employee' AND active = 1 LIMIT 1",
            [$userId]
        );
        $cache[$userId] = ($row !== null && !empty($row['imap_path'])) ? (string) $row['imap_path'] : null;
    } catch (\Throwable) {
        $cache[$userId] = null;
    }

    return $cache[$userId];
}

/**
 * @return array{path: string, name: string}|null
 */
function folder_registry_meta(string $path): ?array
{
    if ($path === '') {
        return null;
    }

    try {
        $row = App\Database::fetchOne(
            'SELECT imap_path, display_name FROM folders WHERE active = 1 AND LOWER(imap_path) = LOWER(?) LIMIT 1',
            [$path]
        );
    } catch (\Throwable) {
        return null;
    }

    if ($row === null || empty($row['imap_path'])) {
        return null;
    }

    return [
        'path' => (string) $row['imap_path'],
        'name' => (string) ($row['display_name'] ?? preg_replace('/^INBOX\./i', '', (string) $row['imap_path'])),
    ];
}

/**
 * Remember a correspondent employee folder (e.g. Support) for the sidebar.
 */
function mail_note_employee_correspondent(string $folderPath): void
{
    $user = App\Auth::user();
    if ($user === null || ($user['role'] ?? '') !== 'employee') {
        return;
    }

    $folderPath = \App\Services\FolderCache::resolvePath($folderPath);
    if ($folderPath === '') {
        return;
    }

    if (!isset($_SESSION['_employee_correspondents']) || !is_array($_SESSION['_employee_correspondents'])) {
        $_SESSION['_employee_correspondents'] = [];
    }

    $_SESSION['_employee_correspondents'][$folderPath] = time();
    employee_correspondent_folder_paths_invalidate((int) ($user['id'] ?? 0));
}

/**
 * Stop tracking a correspondent folder in the employee sidebar.
 */
function mail_forget_employee_correspondent(string $folderPath): void
{
    $user = App\Auth::user();
    if ($user === null || ($user['role'] ?? '') !== 'employee') {
        return;
    }

    $folderPath = \App\Services\FolderCache::resolvePath($folderPath);
    if ($folderPath === '' || !is_array($_SESSION['_employee_correspondents'] ?? null)) {
        return;
    }

    foreach (array_keys($_SESSION['_employee_correspondents']) as $key) {
        if (strcasecmp((string) $key, $folderPath) === 0) {
            unset($_SESSION['_employee_correspondents'][$key]);
            break;
        }
    }

    employee_correspondent_folder_paths_invalidate((int) ($user['id'] ?? 0));
}

/**
 * Remove an empty employee correspondent folder from the sidebar.
 *
 * @return array{path: string, redirect: string}|null
 */
function mail_prune_empty_correspondent_folder(string $folderPath): ?array
{
    $folderPath = \App\Services\FolderCache::resolvePath($folderPath);
    if ($folderPath === '' || !employee_is_correspondent_folder($folderPath)) {
        return null;
    }

    if (\App\Services\MailCacheService::countListableMessagesInIndex($folderPath) > 0) {
        return null;
    }

    mail_forget_employee_correspondent($folderPath);

    return [
        'path' => $folderPath,
        'redirect' => encode_folder_path(default_mail_folder()),
    ];
}

/**
 * @return array<int, list<string>>
 */
function &employee_correspondent_folder_paths_cache(): array
{
    static $cache = [];

    return $cache;
}

function employee_correspondent_folder_paths_invalidate(?int $userId = null): void
{
    $cache = &employee_correspondent_folder_paths_cache();
    if ($userId === null || $userId <= 0) {
        $cache = [];

        return;
    }

    unset($cache[$userId]);
}

/**
 * @return list<array{path: string, email: string, name: string}>
 */
function employee_other_mailboxes(int $userId, string $ownPrefix): array
{
    try {
        $rows = App\Database::query(
            "SELECT f.imap_path, f.display_name, LOWER(a.email) AS email
             FROM folders f
             INNER JOIN aliases a ON a.default_folder_id = f.id AND a.active = 1
             WHERE f.folder_type = 'employee' AND f.active = 1
               AND LOWER(f.imap_path) <> LOWER(?)",
            [$ownPrefix]
        )->fetchAll();
    } catch (\Throwable) {
        return [];
    }

    $mailboxes = [];
    foreach ($rows as $row) {
        $path = (string) ($row['imap_path'] ?? '');
        if ($path === '' || strcasecmp($path, $ownPrefix) === 0) {
            continue;
        }

        $email = strtolower(trim((string) ($row['email'] ?? '')));
        if ($email === '') {
            continue;
        }

        $mailboxes[] = [
            'path' => $path,
            'email' => $email,
            'name' => (string) ($row['display_name'] ?? preg_replace('/^INBOX\./', '', $path)),
        ];
    }

    return $mailboxes;
}

function employee_has_correspondence_with(
    string $ownPrefix,
    string $correspondentPath,
    string $correspondentEmail,
    int $userId,
): bool {
    $emailLike = '%' . strtolower($correspondentEmail) . '%';
    $ownLike = strtolower($ownPrefix) . '.%';

    try {
        $inbound = App\Database::fetchOne(
            'SELECT 1 FROM mail_index
             WHERE (LOWER(folder_path) = LOWER(?) OR LOWER(folder_path) LIKE ?)
               AND LOWER(from_addr) LIKE ?
             LIMIT 1',
            [$ownPrefix, $ownLike, $emailLike]
        );
        if ($inbound !== null) {
            return true;
        }

        $outbound = App\Database::fetchOne(
            'SELECT 1 FROM mail_bodies
             WHERE (LOWER(folder_path) = LOWER(?) OR LOWER(folder_path) LIKE ?)
               AND LOWER(to_addrs) LIKE ?
             LIMIT 1',
            [$ownPrefix, $ownLike, $emailLike]
        );
        if ($outbound !== null) {
            return true;
        }

        foreach (mail_user_emails($userId) as $userEmail) {
            $fromLike = '%' . strtolower($userEmail) . '%';
            $inCorrespondent = App\Database::fetchOne(
                'SELECT 1 FROM mail_index
                 WHERE LOWER(folder_path) = LOWER(?) AND LOWER(from_addr) LIKE ?
                 LIMIT 1',
                [$correspondentPath, $fromLike]
            );
            if ($inCorrespondent !== null) {
                return true;
            }
        }
    } catch (\Throwable) {
        return false;
    }

    return false;
}

/**
 * Employee folders for people the user has emailed or received mail from.
 *
 * @return list<string>
 */
function employee_correspondent_folder_paths(?int $userId = null): array
{
    $cache = &employee_correspondent_folder_paths_cache();

    $user = App\Auth::user();
    if ($userId === null) {
        $userId = (int) ($user['id'] ?? 0);
    }

    if ($userId <= 0 || $user === null || ($user['role'] ?? '') !== 'employee') {
        return [];
    }

    if (array_key_exists($userId, $cache)) {
        return $cache[$userId];
    }

    $ownPrefix = employee_linked_inbox_path($user);
    if ($ownPrefix === null || $ownPrefix === '') {
        $cache[$userId] = [];

        return [];
    }

    $paths = [];
    $session = $_SESSION['_employee_correspondents'] ?? [];
    if (is_array($session)) {
        foreach ($session as $path => $ts) {
            if (is_string($path) && $path !== '' && (int) $ts > 0) {
                $paths[] = \App\Services\FolderCache::resolvePath($path);
            }
        }
    }

    foreach (employee_other_mailboxes($userId, $ownPrefix) as $mailbox) {
        if (!employee_has_correspondence_with(
            $ownPrefix,
            $mailbox['path'],
            $mailbox['email'],
            $userId,
        )) {
            continue;
        }

        if (\App\Services\MailCacheService::countListableMessagesInIndex($mailbox['path']) <= 0) {
            continue;
        }

        $paths[] = $mailbox['path'];
    }

    $unique = [];
    foreach ($paths as $path) {
        if ($path === '' || strcasecmp($path, $ownPrefix) === 0) {
            continue;
        }
        $key = strtoupper($path);
        if (!isset($unique[$key])) {
            $unique[$key] = $path;
        }
    }

    $cache[$userId] = array_values($unique);

    return $cache[$userId];
}

function employee_can_access_correspondent_folder(string $path): bool
{
    foreach (employee_correspondent_folder_paths() as $allowed) {
        if (strcasecmp($path, $allowed) === 0) {
            return true;
        }
    }

    return false;
}

/**
 * True when viewing another employee's mailbox (e.g. Jean opened Support).
 */
function employee_is_correspondent_folder(string $folderPath): bool
{
    $own = employee_linked_inbox_path();
    if ($own === null || $own === '') {
        return false;
    }

    if (strcasecmp($folderPath, $own) === 0) {
        return false;
    }

    return employee_can_access_correspondent_folder($folderPath);
}

/**
 * Email addresses the current employee must be a party to in order to view a
 * message inside a correspondent folder.
 *
 * Returns null when no privacy restriction applies — i.e. the viewer is an
 * admin, the folder is the employee's own mailbox, or it is not a correspondent
 * folder at all. When a (possibly empty) list is returned, only messages whose
 * participants include one of these addresses may be shown; an empty list means
 * nothing in that folder is visible to this user.
 *
 * @return list<string>|null
 */
function employee_correspondent_privacy_emails(string $folderPath): ?array
{
    $user = App\Auth::user();
    if ($user === null || ($user['role'] ?? '') !== 'employee') {
        return null;
    }

    if (!employee_is_correspondent_folder($folderPath)) {
        return null;
    }

    return mail_user_emails((int) ($user['id'] ?? 0));
}

/**
 * True when one of the user's addresses appears among a message's participants
 * (From / To / Cc / Bcc / Reply-To / Sender / Delivered-To).
 *
 * @param array<string, mixed> $message
 * @param list<string> $userEmails lowercase addresses
 */
function mail_message_involves_user(array $message, array $userEmails): bool
{
    if ($userEmails === []) {
        return false;
    }

    $haystack = '';
    foreach (['from', 'to', 'cc', 'bcc', 'reply_to', 'sender', 'delivered_to'] as $field) {
        $value = $message[$field] ?? '';
        if (is_string($value) && $value !== '') {
            $haystack .= ' ' . strtolower($value);
        }
    }

    if ($haystack === '') {
        return false;
    }

    foreach ($userEmails as $email) {
        if ($email !== '' && str_contains($haystack, $email)) {
            return true;
        }
    }

    return false;
}

/**
 * Apply correspondent-folder privacy to a message list: in another employee's
 * mailbox, hide messages the current user is not a party to. No-op for the
 * user's own mailbox, non-correspondent folders, and admins.
 *
 * @param array{messages?: list<array<string, mixed>>, total?: int, page?: int, per_page?: int, total_pages?: int} $list
 * @return array{messages?: list<array<string, mixed>>, total?: int, page?: int, per_page?: int, total_pages?: int}
 */
function employee_filter_correspondent_list(string $folderPath, array $list): array
{
    $emails = employee_correspondent_privacy_emails($folderPath);
    if ($emails === null || !isset($list['messages']) || !is_array($list['messages'])) {
        return $list;
    }

    $before = count($list['messages']);
    $filtered = [];
    foreach ($list['messages'] as $msg) {
        if (is_array($msg) && mail_message_involves_user($msg, $emails)) {
            $filtered[] = $msg;
        }
    }

    $removedCount = $before - count($filtered);
    if ($removedCount > 0) {
        $list['total'] = max(0, (int) ($list['total'] ?? 0) - $removedCount);
        if ($filtered === []) {
            $list['total_pages'] = 0;
            $list['page'] = 1;
        } elseif (isset($list['per_page'])) {
            $perPage = max(1, (int) $list['per_page']);
            $list['total_pages'] = (int) max(1, (int) ceil((int) $list['total'] / $perPage));
        }
    }

    $list['messages'] = $filtered;

    return employee_group_correspondent_list_by_thread($folderPath, $list);
}

/**
 * True when listing the logged-in employee's own inbox (not a correspondent folder).
 */
function employee_is_own_inbox_folder(string $folderPath): bool
{
    $own = employee_linked_inbox_path();
    if ($own === null || $own === '') {
        return false;
    }

    return strcasecmp(
        \App\Services\FolderCache::resolvePath($folderPath),
        \App\Services\FolderCache::resolvePath($own),
    ) === 0;
}

/**
 * Resolved personal Sent folder for the logged-in employee.
 */
function employee_personal_sent_folder_path(): ?string
{
    $own = employee_linked_inbox_path();
    if ($own === null || $own === '') {
        return null;
    }

    return \App\Services\FolderCache::resolvePath(
        resolve_system_folder(['sent'], rtrim($own, '.') . '.Sent'),
    );
}

function employee_is_personal_sent_folder(string $folderPath): bool
{
    $sent = employee_personal_sent_folder_path();
    if ($sent === null || $sent === '') {
        return false;
    }

    return strcasecmp(
        \App\Services\FolderCache::resolvePath($folderPath),
        $sent,
    ) === 0;
}

/**
 * Hide inbox copies of threads that already live in a correspondent folder (e.g. Support).
 *
 * @param array<string, mixed> $msg
 */
function employee_should_hide_inbox_correspondent_message(array $msg): bool
{
    $parsed = mail_parse_address((string) ($msg['from'] ?? ''));
    $fromEmail = strtolower($parsed['email'] !== '' ? $parsed['email'] : normalize_email_token((string) ($msg['from'] ?? '')));
    if ($fromEmail === '') {
        return false;
    }

    $corrFolder = folder_for_alias_email($fromEmail);
    if ($corrFolder === null || !employee_can_access_correspondent_folder($corrFolder)) {
        return false;
    }

    $baseSubject = mail_normalize_thread_subject((string) ($msg['subject'] ?? ''));
    if ($baseSubject === '') {
        return false;
    }

    if (mail_find_correspondent_outbound_for_subject($corrFolder, $baseSubject) !== []) {
        return true;
    }

    return mail_find_correspondent_inbound_for_subject($corrFolder, $baseSubject) !== [];
}

/**
 * @param array{messages?: list<array<string, mixed>>, total?: int, page?: int, per_page?: int, total_pages?: int} $list
 * @return array{messages?: list<array<string, mixed>>, total?: int, page?: int, per_page?: int, total_pages?: int}
 */
function employee_filter_own_inbox_list(string $folderPath, array $list): array
{
    if (!employee_is_own_inbox_folder($folderPath) || !isset($list['messages']) || !is_array($list['messages'])) {
        return $list;
    }

    $before = count($list['messages']);
    $filtered = [];
    foreach ($list['messages'] as $msg) {
        if (!is_array($msg) || !employee_should_hide_inbox_correspondent_message($msg)) {
            $filtered[] = $msg;
        }
    }

    $removedCount = $before - count($filtered);
    if ($removedCount > 0) {
        $list['total'] = max(0, (int) ($list['total'] ?? 0) - $removedCount);
        if ($filtered === []) {
            $list['total_pages'] = 0;
            $list['page'] = 1;
        } elseif (isset($list['per_page'])) {
            $perPage = max(1, (int) $list['per_page']);
            $list['total_pages'] = (int) max(1, (int) ceil((int) $list['total'] / $perPage));
        }
    }

    $list['messages'] = $filtered;

    return $list;
}

/**
 * @param array<string, mixed> $msg
 */
function mail_list_message_fingerprint(array $msg): string
{
    $parsed = mail_parse_address((string) ($msg['from'] ?? ''));

    return strtolower($parsed['email'] !== '' ? $parsed['email'] : normalize_email_token((string) ($msg['from'] ?? '')))
        . '|' . trim((string) ($msg['date'] ?? ''))
        . '|' . mail_normalize_thread_subject((string) ($msg['subject'] ?? ''));
}

/**
 * Include legacy/global Sent copies when the employee personal Sent folder is empty in cache.
 *
 * @param array{messages?: list<array<string, mixed>>, total?: int, page?: int, per_page?: int, total_pages?: int} $list
 * @return array{messages?: list<array<string, mixed>>, total?: int, page?: int, per_page?: int, total_pages?: int}
 */
function employee_merge_personal_sent_list(string $folderPath, array $list): array
{
    if (!employee_is_personal_sent_folder($folderPath) || !isset($list['messages']) || !is_array($list['messages'])) {
        return $list;
    }

    $ownSent = \App\Services\FolderCache::resolvePath($folderPath);
    $globalSent = \App\Services\FolderCache::resolvePath('INBOX.Sent');
    if (strcasecmp($ownSent, $globalSent) === 0) {
        return $list;
    }

    $userEmails = mail_user_emails();
    if ($userEmails === []) {
        return $list;
    }

    $fingerprints = [];
    foreach ($list['messages'] as $msg) {
        if (is_array($msg)) {
            $fingerprints[mail_list_message_fingerprint($msg)] = true;
        }
    }

    $clauses = [];
    $params = [$globalSent];
    foreach ($userEmails as $email) {
        $clauses[] = 'LOWER(i.from_addr) LIKE ?';
        $params[] = '%' . strtolower($email) . '%';
    }

    try {
        $rows = App\Database::query(
            'SELECT i.imap_uid, i.from_addr, i.subject, i.msg_date, i.seen, i.flagged, i.has_attachment, i.size,
                    b.plain_body, b.html_body,
                    COALESCE(NULLIF(i.to_addrs, \'\'), b.to_addrs) AS to_addrs,
                    COALESCE(NULLIF(i.cc_addrs, \'\'), b.cc_addrs) AS cc_addrs
             FROM mail_index i
             LEFT JOIN mail_bodies b
                ON b.folder_path = i.folder_path AND b.imap_uid = i.imap_uid
             WHERE i.folder_path = ? AND (' . implode(' OR ', $clauses) . ')
             ORDER BY i.msg_date DESC, i.imap_uid DESC',
            $params
        )->fetchAll();
    } catch (\Throwable) {
        return $list;
    }

    $extra = [];
    foreach ($rows as $row) {
        $msg = \App\Services\MailCacheService::messageFromIndexRow($row, $globalSent);
        if (isset($fingerprints[mail_list_message_fingerprint($msg)])) {
            continue;
        }
        $msg['list_folder'] = $globalSent;
        $fingerprints[mail_list_message_fingerprint($msg)] = true;
        $extra[] = $msg;
    }

    foreach (employee_correspondent_folder_paths() as $corrPath) {
        $corrPath = \App\Services\FolderCache::resolvePath($corrPath);
        $corrClauses = [];
        $corrParams = [$corrPath];
        foreach ($userEmails as $email) {
            $corrClauses[] = 'LOWER(i.from_addr) LIKE ?';
            $corrParams[] = '%' . strtolower($email) . '%';
        }
        if ($corrClauses === []) {
            continue;
        }

        try {
            $corrRows = App\Database::query(
                'SELECT i.imap_uid, i.from_addr, i.subject, i.msg_date, i.seen, i.flagged, i.has_attachment, i.size,
                        b.plain_body, b.html_body,
                        COALESCE(NULLIF(i.to_addrs, \'\'), b.to_addrs) AS to_addrs,
                        COALESCE(NULLIF(i.cc_addrs, \'\'), b.cc_addrs) AS cc_addrs
                 FROM mail_index i
                 LEFT JOIN mail_bodies b
                    ON b.folder_path = i.folder_path AND b.imap_uid = i.imap_uid
                 WHERE i.folder_path = ? AND (' . implode(' OR ', $corrClauses) . ')
                 ORDER BY i.msg_date DESC, i.imap_uid DESC',
                $corrParams
            )->fetchAll();
        } catch (\Throwable) {
            continue;
        }

        foreach ($corrRows as $row) {
            $msg = \App\Services\MailCacheService::messageFromIndexRow($row, $corrPath);
            if (isset($fingerprints[mail_list_message_fingerprint($msg)])) {
                continue;
            }
            $msg['list_folder'] = $corrPath;
            $fingerprints[mail_list_message_fingerprint($msg)] = true;
            $extra[] = $msg;
        }
    }

    if ($extra === []) {
        return $list;
    }

    $merged = array_merge($list['messages'], $extra);
    usort($merged, static function (array $a, array $b): int {
        $aTs = strtotime((string) ($a['date'] ?? '')) ?: 0;
        $bTs = strtotime((string) ($b['date'] ?? '')) ?: 0;
        if ($aTs === $bTs) {
            return ((int) ($b['uid'] ?? 0)) <=> ((int) ($a['uid'] ?? 0));
        }

        return $bTs <=> $aTs;
    });

    $list['messages'] = $merged;
    $list['total'] = count($merged);
    if (isset($list['per_page'])) {
        $perPage = max(1, (int) $list['per_page']);
        $list['total_pages'] = (int) max(1, (int) ceil(count($merged) / $perPage));
    }

    return mail_filter_removed_messages($ownSent, $list);
}

/**
 * One list row per conversation thread in correspondent folders (latest message only).
 *
 * @param array{messages?: list<array<string, mixed>>, total?: int, page?: int, per_page?: int, total_pages?: int} $list
 * @return array{messages?: list<array<string, mixed>>, total?: int, page?: int, per_page?: int, total_pages?: int}
 */
function employee_group_correspondent_list_by_thread(string $folderPath, array $list): array
{
    if (!employee_is_correspondent_folder($folderPath) || !isset($list['messages']) || !is_array($list['messages'])) {
        return $list;
    }

    if ($list['messages'] === []) {
        return $list;
    }

    $groups = [];
    foreach ($list['messages'] as $msg) {
        if (!is_array($msg)) {
            continue;
        }
        $key = mail_normalize_thread_subject((string) ($msg['subject'] ?? ''));
        if ($key === '') {
            $key = 'uid:' . (int) ($msg['uid'] ?? 0);
        }

        $msgTs = strtotime((string) ($msg['date'] ?? '')) ?: 0;
        if (!isset($groups[$key]) || $msgTs >= (strtotime((string) ($groups[$key]['date'] ?? '')) ?: 0)) {
            $groups[$key] = $msg;
        }
    }

    $merged = array_values($groups);
    usort($merged, static function (array $a, array $b): int {
        $aTs = strtotime((string) ($a['date'] ?? '')) ?: 0;
        $bTs = strtotime((string) ($b['date'] ?? '')) ?: 0;

        return $bTs <=> $aTs;
    });

    $list['messages'] = $merged;
    $list['total'] = count($merged);
    if (isset($list['per_page'])) {
        $perPage = max(1, (int) $list['per_page']);
        $list['total_pages'] = (int) max(1, (int) ceil(count($merged) / $perPage));
    }

    return $list;
}

/**
 * Restrict a list of UIDs in a correspondent folder to those the current user
 * is a party to (used to guard snippet/attachment endpoints that accept
 * arbitrary UIDs). Returns the input unchanged when no restriction applies.
 *
 * @param list<int> $uids
 * @return list<int>
 */
function employee_visible_correspondent_uids(string $folderPath, array $uids): array
{
    $emails = employee_correspondent_privacy_emails($folderPath);
    if ($emails === null) {
        return $uids;
    }
    if ($emails === [] || $uids === []) {
        return [];
    }

    $indexPath = \App\Services\FolderCache::resolvePath($folderPath);
    $placeholders = implode(',', array_fill(0, count($uids), '?'));

    try {
        $rows = App\Database::query(
            'SELECT i.imap_uid, i.from_addr, b.to_addrs, b.cc_addrs
             FROM mail_index i
             LEFT JOIN mail_bodies b
                ON b.folder_path = i.folder_path AND b.imap_uid = i.imap_uid
             WHERE i.folder_path = ? AND i.imap_uid IN (' . $placeholders . ')',
            array_merge([$indexPath], $uids)
        )->fetchAll();
    } catch (\Throwable) {
        return [];
    }

    $allowed = [];
    foreach ($rows as $row) {
        $message = [
            'from' => (string) ($row['from_addr'] ?? ''),
            'to' => (string) ($row['to_addrs'] ?? ''),
            'cc' => (string) ($row['cc_addrs'] ?? ''),
        ];
        if (mail_message_involves_user($message, $emails)) {
            $allowed[(int) $row['imap_uid']] = true;
        }
    }

    return array_values(array_filter($uids, static fn (int $uid): bool => isset($allowed[$uid])));
}

/**
 * True when an employee sent mail into another employee's mailbox folder.
 */
function employee_outbound_correspondent_folder(string $folderPath, ?array $user = null): bool
{
    $user = $user ?? App\Auth::user();
    if ($user === null || ($user['role'] ?? '') !== 'employee') {
        return false;
    }

    $own = employee_linked_inbox_path($user);
    $folderPath = \App\Services\FolderCache::resolvePath($folderPath);
    if ($own === null || $own === '' || $folderPath === '' || strcasecmp($folderPath, $own) === 0) {
        return false;
    }

    return folder_registry_meta($folderPath) !== null;
}

/**
 * True when the current user just sent mail into a folder — badges must not
 * count that delivery in the sender's session (only unread inbound mail counts).
 */
function sender_suppresses_dest_folder_badge(string $folderPath, ?array $user = null): bool
{
    if (employee_outbound_correspondent_folder($folderPath, $user)) {
        return true;
    }

    $user = $user ?? App\Auth::user();
    if ($user === null || ($user['role'] ?? '') !== 'admin') {
        return false;
    }

    $folderPath = \App\Services\FolderCache::resolvePath($folderPath);
    if ($folderPath === '') {
        return false;
    }

    try {
        $row = App\Database::fetchOne(
            "SELECT 1 FROM folders
             WHERE active = 1 AND folder_type = 'employee' AND linked_user_id IS NOT NULL
             AND LOWER(imap_path) = LOWER(?)
             LIMIT 1",
            [$folderPath]
        );

        return $row !== null;
    } catch (\Throwable) {
        return false;
    }
}

function employee_correspondent_folder_name(string $folderPath): ?string
{
    if (!employee_is_correspondent_folder($folderPath)) {
        return null;
    }

    $meta = folder_registry_meta($folderPath);

    return $meta['name'] ?? null;
}

/**
 * Reply-To address when composing in a correspondent folder (e.g. Support).
 */
function mail_correspondent_reply_address(string $folderPath): string
{
    $corrEmail = alias_email_for_folder($folderPath);
    if ($corrEmail === null || $corrEmail === '') {
        return '';
    }

    $aliasService = new App\Services\AliasService();
    $name = employee_correspondent_folder_name($folderPath);
    if ($name === null || $name === '') {
        $name = $aliasService->getDisplayName($corrEmail);
    }

    if ($name !== '' && strcasecmp($name, $corrEmail) !== 0) {
        return $name . ' <' . $corrEmail . '>';
    }

    return $corrEmail;
}

/**
 * Resolve the To header for a reply (handles correspondent-folder threads).
 *
 * @param array<string, mixed> $message
 */
function mail_resolve_reply_to(array $message, string $folderPath): string
{
    $from = trim((string) ($message['from'] ?? ''));

    if (employee_is_correspondent_folder($folderPath) && mail_is_sent_by_user($from)) {
        return mail_correspondent_reply_address($folderPath);
    }

    return $from;
}

/**
 * Resolve the From alias for a reply.
 *
 * @param array<string, mixed> $message
 */
function mail_resolve_reply_from(array $message, string $folderPath): string
{
    $aliasService = new App\Services\AliasService();
    $userId = App\Auth::user()['id'] ?? null;

    if (employee_is_correspondent_folder($folderPath)) {
        return $aliasService->userAlias($userId);
    }

    return $aliasService->resolveReplyAlias($message['delivered_to'] ?? null, $message['to'] ?? null)
        ?? $aliasService->userAlias($userId);
}

/**
 * Quoted body for a reply — in correspondent folders, quote the latest inbound
 * reply from the correspondent when the indexed row is the user's outbound copy.
 *
 * @param array<string, mixed> $message
 */
function mail_build_reply_quoted_body(array $message, string $folderPath): string
{
    $uid = (int) ($message['uid'] ?? 0);
    if (
        employee_is_correspondent_folder($folderPath)
        && $uid > 0
        && mail_is_sent_by_user((string) ($message['from'] ?? ''))
    ) {
        $inbound = mail_find_correspondent_inbound_replies($folderPath, $uid, $message);
        if ($inbound !== []) {
            $latest = $inbound[count($inbound) - 1];

            return mail_format_quoted_reply_body([
                'plain' => $latest['body'] ?? '',
                'html' => $latest['body_html'] ?? '',
                'date' => $latest['date'] ?? '',
                'from' => $latest['from'] ?? '',
            ]);
        }
    }

    return mail_format_quoted_reply_body($message);
}

/**
 * @param array<string, mixed> $message
 */
function mail_format_quoted_reply_body(array $message): string
{
    $plain = rtrim((string) ($message['plain'] ?? strip_tags((string) ($message['html'] ?? ''))));
    $lines = explode("\n", $plain);
    $quoted = array_map(static fn (string $line): string => '> ' . $line, $lines);
    while ($quoted !== [] && trim($quoted[array_key_last($quoted)], '> ') === '') {
        array_pop($quoted);
    }

    return sprintf(
        "On %s, %s wrote:\n%s",
        $message['date'] ?? '',
        $message['from'] ?? '',
        implode("\n", $quoted)
    );
}

/**
 * Conversation subject for list/read (strip Re:/Fwd: in correspondent folders).
 */
function mail_display_subject(array $msg, string $folderPath): string
{
    $subject = (string) ($msg['subject'] ?? '');
    if ($subject === '') {
        return '(no subject)';
    }

    if (employee_is_correspondent_folder($folderPath)) {
        $base = mail_normalize_thread_subject($subject);

        return $base !== '' ? $base : $subject;
    }

    return $subject;
}

/**
 * @param array<string, mixed> $msg
 */
function mail_enrich_correspondent_folder_list_row(string $folderPath, array &$msg): void
{
    if (!employee_is_correspondent_folder($folderPath)) {
        return;
    }

    $name = employee_correspondent_folder_name($folderPath);
    if ($name !== null && $name !== '') {
        $msg['list_from'] = $name;
    }

    $base = mail_normalize_thread_subject((string) ($msg['subject'] ?? ''));
    if ($base !== '') {
        $msg['subject'] = $base;
    }

    if (mail_is_sent_by_user((string) ($msg['from'] ?? ''))) {
        $msg['seen'] = true;
    }

    $uid = (int) ($msg['uid'] ?? 0);
    if ($uid <= 0) {
        return;
    }

    if ($base === '') {
        return;
    }

    $threadEntries = array_merge(
        mail_find_correspondent_outbound_for_subject($folderPath, $base),
        mail_find_correspondent_inbound_for_subject($folderPath, $base),
    );
    if ($threadEntries === []) {
        return;
    }

    usort($threadEntries, static function (array $a, array $b): int {
        $aTs = strtotime((string) ($a['date'] ?? '')) ?: 0;
        $bTs = strtotime((string) ($b['date'] ?? '')) ?: 0;

        return $aTs <=> $bTs;
    });

    $latest = $threadEntries[count($threadEntries) - 1];
    if ($base !== '') {
        $msg['subject'] = count($threadEntries) > 1 ? 'Re: ' . $base : $base;
    }

    $snippet = mail_conversation_snippet((string) ($latest['body'] ?? ''));
    if ($snippet !== '') {
        $msg['snippet'] = $snippet;
    }

    if (!empty($latest['is_inbound_reply'])) {
        $replyFolder = (string) ($latest['folder_path'] ?? '');
        $replyUid = (int) ($latest['imap_uid'] ?? 0);
        if ($replyFolder !== '' && $replyUid > 0) {
            $msg['seen'] = \App\Services\MailCacheService::effectiveSeen($replyFolder, $replyUid);
        }
    }
}

/**
 * @param array<string, mixed> $message
 */
function mail_note_correspondents_from_message(array $message): void
{
    $user = App\Auth::user();
    if ($user === null || ($user['role'] ?? '') !== 'employee') {
        return;
    }

    foreach (['from', 'to', 'cc'] as $field) {
        $header = (string) ($message[$field] ?? '');
        if ($header === '') {
            continue;
        }
        $parsed = parse_email_list($header);
        foreach ($parsed['valid'] as $email) {
            $folder = folder_for_alias_email(normalize_email_token($email));
            if ($folder !== null) {
                mail_note_employee_correspondent($folder);
            }
        }
    }
}

function mail_note_correspondents_from_addresses(string $to, string $cc = '', string $bcc = ''): void
{
    foreach ([$to, $cc, $bcc] as $header) {
        if (trim($header) === '') {
            continue;
        }
        $parsed = parse_email_list($header);
        foreach ($parsed['valid'] as $email) {
            $folder = folder_for_alias_email(normalize_email_token($email));
            if ($folder !== null) {
                mail_note_employee_correspondent($folder);
            }
        }
    }
}

/**
 * @param array<string, mixed> $msg
 */
function mail_note_correspondent_from_list_message(array $msg): void
{
    $user = App\Auth::user();
    if ($user === null || ($user['role'] ?? '') !== 'employee') {
        return;
    }

    $from = normalize_email_token((string) ($msg['from'] ?? ''));
    if ($from === '') {
        return;
    }

    $folder = folder_for_alias_email($from);
    if ($folder !== null) {
        mail_note_employee_correspondent($folder);
    }
}

/**
 * Sidebar payload for employee correspondent folders (after send/receive).
 *
 * @return list<array{path: string, name: string, b64: string, icon: string, url: string}>
 */
function mail_correspondent_folders_sidebar_payload(?int $userId = null): array
{
    $out = [];
    foreach (employee_correspondent_folder_paths($userId) as $path) {
        $meta = folder_registry_meta($path);
        if ($meta === null) {
            continue;
        }

        $resolved = \App\Services\FolderCache::resolvePath($meta['path']);
        $folder = ['path' => $resolved, 'name' => $meta['name']];
        $out[] = [
            'path' => $resolved,
            'name' => sidebar_folder_label($folder, 'other'),
            'b64' => encode_folder_path($resolved),
            'icon' => folder_icon_type($resolved),
            'url' => folder_url($resolved),
        ];
    }

    return $out;
}

/**
 * Default folder after login: employees land on their linked folder, admins on INBOX.
 */
function default_mail_folder(): string
{
    $user = App\Auth::user();
    if ($user === null || ($user['role'] ?? '') !== 'employee') {
        return 'INBOX';
    }

    try {
        $row = App\Database::fetchOne(
            'SELECT imap_path FROM folders WHERE linked_user_id = ? AND active = 1 LIMIT 1',
            [(int) $user['id']]
        );
        if ($row !== null && !empty($row['imap_path'])) {
            return $row['imap_path'];
        }
    } catch (\Throwable $e) {
        // ignore
    }

    // Employee without a linked folder cannot access INBOX — land on Sent instead.
    return resolve_system_folder(['sent'], 'INBOX.Sent');
}

/**
 * IMAP folder linked to a send-as alias (e.g. support@ → INBOX.support).
 */
function folder_for_alias_email(string $email): ?string
{
    if ($email === '') {
        return null;
    }

    try {
        $row = App\Database::fetchOne(
            'SELECT f.imap_path
             FROM aliases a
             INNER JOIN folders f ON a.default_folder_id = f.id AND f.active = 1
             WHERE LOWER(a.email) = LOWER(?) AND a.active = 1
             LIMIT 1',
            [$email]
        );

        return !empty($row['imap_path']) ? (string) $row['imap_path'] : null;
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * Primary send-as alias for an employee folder (e.g. INBOX.support → support@).
 */
function alias_email_for_folder(string $folderPath): ?string
{
    if ($folderPath === '') {
        return null;
    }

    try {
        $row = App\Database::fetchOne(
            'SELECT a.email
             FROM aliases a
             INNER JOIN folders f ON a.default_folder_id = f.id AND f.active = 1
             WHERE f.imap_path = ? AND a.active = 1
             ORDER BY a.id ASC
             LIMIT 1',
            [$folderPath]
        );

        return !empty($row['email']) ? (string) $row['email'] : null;
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * Mark or remove unread outbound echoes in employee folders (mail From the
 * folder alias that was mis-routed via envelope headers).
 *
 * @param list<string> $folderPaths
 */
function reconcile_alias_self_sent_echoes(App\Services\ImapService $imap, array $folderPaths, int $limit = 30): void
{
    foreach (array_values(array_unique(array_filter($folderPaths))) as $path) {
        $alias = alias_email_for_folder($path);
        if ($alias === null) {
            continue;
        }

        $imap->suppressInboundEchoOfSentMessage($path, $alias, $limit, null, false);
        App\Services\MailCacheService::reconcileBadgeFromIndex($path);
    }
}

/**
 * Best folder context for compose/send: return folder, reply folder, alias folder,
 * or the employee's linked folder — never default to Inbox for badge refresh.
 */
function compose_context_folder(string $returnFolder, string $messageFolder, string $fromEmail = ''): string
{
    foreach ([$returnFolder, $messageFolder] as $path) {
        if ($path !== '' && App\Services\FolderCache::canAccess($path)) {
            return $path;
        }
    }

    if ($fromEmail !== '') {
        $aliasFolder = folder_for_alias_email($fromEmail);
        if ($aliasFolder !== null && App\Services\FolderCache::canAccess($aliasFolder)) {
            return $aliasFolder;
        }
    }

    $default = default_mail_folder();
    if ($default !== 'INBOX' && App\Services\FolderCache::canAccess($default)) {
        return $default;
    }

    return '';
}

/**
 * Domains and contacts for compose recipient autocomplete.
 *
 * @return array{domains: list<string>, contacts: list<array{email: string, name: string, local: string}>}
 */
function compose_recipient_autocomplete_data(): array
{
    $domains = [];
    $contacts = [];

    foreach ((new App\Services\AliasService())->listActive() as $alias) {
        $email = strtolower(trim((string) ($alias['email'] ?? '')));
        if ($email === '' || !str_contains($email, '@')) {
            continue;
        }

        [, $domain] = explode('@', $email, 2);
        if ($domain !== '') {
            $domains[$domain] = true;
        }

        $contacts[] = [
            'email' => $email,
            'name' => (string) ($alias['display_name'] ?? ''),
            'local' => explode('@', $email, 2)[0],
        ];
    }

    $mailbox = strtolower(trim((string) (config('mail')['mailbox_email'] ?? '')));
    $primaryDomain = '';
    if ($mailbox !== '' && str_contains($mailbox, '@')) {
        $primaryDomain = substr(strrchr($mailbox, '@'), 1);
    }

    $domainList = array_keys($domains);
    usort($domainList, static function (string $a, string $b) use ($primaryDomain): int {
        if ($a === $primaryDomain) {
            return -1;
        }
        if ($b === $primaryDomain) {
            return 1;
        }

        return strcasecmp($a, $b);
    });

    usort($contacts, static fn (array $a, array $b): int => strcasecmp(
        $a['name'] !== '' ? $a['name'] : $a['email'],
        $b['name'] !== '' ? $b['name'] : $b['email']
    ));

    return ['domains' => $domainList, 'contacts' => $contacts];
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
    // Canonical spam folder is "Junk" (the server / Apple Mail special-use
    // folder); fall back to a "Spam" folder only if no Junk exists.
    return \App\Services\FolderCache::resolvePath(
        resolve_system_folder(['junk', 'spam'], 'INBOX.Junk')
    );
}

/**
 * Resolve a system folder (Trash/Spam) from the folders table/cache by matching
 * any of the given keywords in the path, falling back to a sensible default.
 *
 * @param list<string> $keywords
 */
function resolve_system_folder(array $keywords, string $default): string
{
    $employeeInbox = employee_linked_inbox_path();

    try {
        $folders = \App\Services\FolderCache::load(skipUnreadRefresh: true)['folders'];

        if ($employeeInbox !== null) {
            $prefix = rtrim($employeeInbox, '.') . '.';
            $prefixLen = strlen($prefix);
            foreach ($keywords as $keyword) {
                $keywordLower = strtolower($keyword);
                foreach ($folders as $folder) {
                    $path = (string) ($folder['path'] ?? '');
                    if (
                        $path !== ''
                        && strlen($path) > $prefixLen
                        && strncasecmp($path, $prefix, $prefixLen) === 0
                        && str_contains(strtolower($path), $keywordLower)
                    ) {
                        return $path;
                    }
                }
            }

            $suffixByKeyword = [
                'sent' => 'Sent',
                'draft' => 'Drafts',
                'archive' => 'Archive',
                'junk' => 'Junk',
                'spam' => 'Spam',
                'trash' => 'Trash',
            ];
            foreach ($keywords as $keyword) {
                $keyword = strtolower($keyword);
                foreach ($suffixByKeyword as $needle => $suffix) {
                    if (str_contains($keyword, $needle) || str_contains($needle, $keyword)) {
                        return $employeeInbox . '.' . $suffix;
                    }
                }
            }
        }

        foreach ($keywords as $keyword) {
            foreach ($folders as $folder) {
                $path = (string) ($folder['path'] ?? '');
                if ($path !== '' && str_contains(strtolower($path), $keyword)) {
                    return $path;
                }
            }
        }
    } catch (\Throwable) {
        // fall through to default
    }

    if ($employeeInbox !== null) {
        $suffixByKeyword = [
            'sent' => 'Sent',
            'draft' => 'Drafts',
            'archive' => 'Archive',
            'junk' => 'Junk',
            'spam' => 'Spam',
            'trash' => 'Trash',
        ];
        foreach ($keywords as $keyword) {
            $keyword = strtolower($keyword);
            foreach ($suffixByKeyword as $needle => $suffix) {
                if (str_contains($keyword, $needle) || str_contains($needle, $keyword)) {
                    return $employeeInbox . '.' . $suffix;
                }
            }
        }
    }

    return $default;
}

function app_timezone(): string
{
    static $tz = null;

    if ($tz === null) {
        $tz = (string) (config('app')['timezone'] ?? 'America/New_York');
    }

    return $tz;
}

function app_timezone_object(): \DateTimeZone
{
    static $zone = null;

    if ($zone === null) {
        $zone = new \DateTimeZone(app_timezone());
    }

    return $zone;
}

function bootstrapAppTimezone(): void
{
    date_default_timezone_set(app_timezone());
}

/**
 * Parse a mail or DB datetime into the application timezone (EST/EDT).
 */
function to_app_datetime(mixed $date): ?\DateTimeImmutable
{
    if ($date === null || $date === '') {
        return null;
    }

    if (is_numeric($date)) {
        $ts = (int) $date;
        if ($ts <= 0) {
            return null;
        }

        return (new \DateTimeImmutable('@' . $ts))->setTimezone(app_timezone_object());
    }

    $value = trim((string) $date);
    if ($value === '') {
        return null;
    }

    try {
        return (new \DateTimeImmutable($value))->setTimezone(app_timezone_object());
    } catch (\Exception) {
        $ts = strtotime($value);
        if ($ts === false || $ts <= 0) {
            return null;
        }

        return (new \DateTimeImmutable('@' . $ts))->setTimezone(app_timezone_object());
    }
}

function format_app_datetime(?string $date, string $format = 'Y-m-d H:i:s'): string
{
    $dt = to_app_datetime($date);
    if ($dt === null) {
        return $date ?? '';
    }

    return $dt->format($format);
}

function format_mail_datetime(?string $date): string
{
    return format_app_datetime($date, 'Y-m-d H:i:s');
}

/**
 * Outlook-style date for the message read header (e.g. Fri 6/26/2026 6:14 PM).
 */
function format_mail_read_datetime(?string $date): string
{
    if ($date === null || trim($date) === '') {
        return '—';
    }

    $dt = to_app_datetime($date);
    if ($dt === null) {
        return $date;
    }

    return $dt->format('D n/j/Y g:i A');
}

/**
 * @return array{name: string, email: string}
 */
function mail_parse_address(?string $header): array
{
    $header = trim((string) $header);
    if ($header === '') {
        return ['name' => '', 'email' => ''];
    }

    if (preg_match('/^(.+?)\s*<([^>]+)>$/', $header, $m)) {
        return [
            'name' => trim($m[1], "\"' "),
            'email' => trim($m[2]),
        ];
    }

    if (filter_var($header, FILTER_VALIDATE_EMAIL)) {
        return ['name' => '', 'email' => $header];
    }

    return ['name' => $header, 'email' => ''];
}

function mail_user_initials(): string
{
    $user = App\Auth::user();
    $name = trim((string) ($user['name'] ?? ''));
    if ($name === '') {
        return 'Y';
    }

    $parts = preg_split('/\s+/', $name) ?: [];
    if (count($parts) >= 2) {
        return mb_strtoupper(
            mb_substr($parts[0], 0, 1) . mb_substr($parts[count($parts) - 1], 0, 1)
        );
    }

    return mb_strtoupper(mb_substr($name, 0, 2));
}

function format_mail_date(?string $date): string
{
    if ($date === null || $date === '') {
        return '';
    }

    $dt = to_app_datetime($date);
    if ($dt === null) {
        return $date;
    }

    $now = new \DateTimeImmutable('now', app_timezone_object());
    $diff = $now->getTimestamp() - $dt->getTimestamp();

    if ($diff < 86400 && $dt->format('Y-m-d') === $now->format('Y-m-d')) {
        return $dt->format('g:i A');
    }

    if ($diff < 604800) {
        return $dt->format('D g:i A');
    }

    if ($dt->format('Y') === $now->format('Y')) {
        return $dt->format('M j');
    }

    return $dt->format('M j, Y');
}

function folder_icon_type(string $path): string
{
    $employeeInbox = employee_linked_inbox_path();
    if ($employeeInbox !== null && strcasecmp($path, $employeeInbox) === 0) {
        return 'inbox';
    }

    $lower = strtolower($path);
    if ($path === 'INBOX' || strcasecmp($path, 'INBOX') === 0) {
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
    if (str_contains($lower, 'archive')) {
        return 'archive';
    }
    // Junk and Spam share one canonical "Spam" identity (icon + bucket) so the
    // UI never shows two near-identical spam folders.
    if (str_contains($lower, 'junk') || str_contains($lower, 'spam')) {
        return 'spam';
    }

    return 'folder';
}

/**
 * Sidebar bucket for ordering (primary nav vs custom folders).
 */
function sidebar_folder_bucket(string $path): string
{
    $employeeInbox = employee_linked_inbox_path();
    if ($employeeInbox !== null && strcasecmp($path, $employeeInbox) === 0) {
        return 'inbox';
    }

    $lower = strtolower($path);
    if ($path === 'INBOX') {
        return 'inbox';
    }
    if (str_contains($lower, 'sent')) {
        return 'sent';
    }
    if (str_contains($lower, 'draft')) {
        return 'drafts';
    }
    if (str_contains($lower, 'archive')) {
        return 'archive';
    }
    // Junk folders are bucketed as Spam so there is a single canonical spam slot.
    if (str_contains($lower, 'junk') || str_contains($lower, 'spam')) {
        return 'spam';
    }
    if (str_contains($lower, 'trash')) {
        return 'trash';
    }

    return 'other';
}

/**
 * @return list<string>
 */
function sidebar_primary_folder_order(): array
{
    return ['inbox', 'sent', 'drafts', 'archive', 'spam', 'trash'];
}

function sidebar_folder_label(array $folder, string $bucket): string
{
    $labels = [
        'inbox' => 'Inbox',
        'sent' => 'Sent',
        'drafts' => 'Drafts',
        'archive' => 'Archive',
        'junk' => 'Junk',
        'spam' => 'Junk',
        'trash' => 'Trash',
    ];

    if (isset($labels[$bucket])) {
        return $labels[$bucket];
    }

    if ($folder['path'] === 'INBOX') {
        return 'Inbox';
    }

    return preg_replace('/^INBOX\./', '', $folder['name']);
}

/**
 * Hierarchy depth and leaf label for a custom ("other") sidebar folder, so it
 * can be nested under any ancestor folder that is also shown in the sidebar.
 * Example: "INBOX.test1.test1-sub1" under "INBOX.test1" → depth 1, "test1-sub1".
 *
 * @param array{path: string, name: string, delimiter?: string} $folder
 * @param array<string, bool> $presentPaths  Lowercased paths shown in the same group.
 * @return array{0: int, 1: string}  [depth, leaf label]
 */
function sidebar_folder_nesting(array $folder, array $presentPaths, string $delimiter = '.'): array
{
    $path = $folder['path'];
    if ($delimiter === '' || !str_contains($path, $delimiter)) {
        return [0, sidebar_folder_label($folder, 'other')];
    }

    $segments = explode($delimiter, $path);
    $depth = 0;
    $nearestAncestor = '';
    $prefix = '';

    for ($i = 0, $last = count($segments) - 1; $i < $last; $i++) {
        $prefix = $prefix === '' ? $segments[$i] : $prefix . $delimiter . $segments[$i];
        // INBOX is the implicit root, never a visible parent folder.
        if (strcasecmp($prefix, 'INBOX') === 0) {
            continue;
        }
        if (isset($presentPaths[strtolower($prefix)])) {
            $depth++;
            $nearestAncestor = $prefix;
        }
    }

    $leaf = $nearestAncestor !== ''
        ? substr($path, strlen($nearestAncestor) + strlen($delimiter))
        : sidebar_folder_label($folder, 'other');

    return [$depth, $leaf];
}

function is_trash_folder(string $path): bool
{
    return folder_icon_type($path) === 'trash';
}

function is_draft_folder(string $path): bool
{
    return folder_icon_type($path) === 'draft';
}

function is_sent_folder(string $path): bool
{
    return folder_icon_type($path) === 'sent';
}

/**
 * One-line preview for the message list (draft body snippet, etc.).
 */
function mail_list_snippet(?string $plain, ?string $html = null, int $maxLen = 140): string
{
    $text = trim((string) $plain);
    if ($text === '' && $html !== null && trim($html) !== '') {
        $text = trim(html_entity_decode(
            strip_tags(str_replace(['<br>', '<br/>', '<br />', '</div>', '</p>', '</li>'], "\n", $html)),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        ));
    }

    if ($text === '') {
        return '';
    }

    // Prefer the newest reply text above quoted history.
    $parts = preg_split("/\n\s*On .+ wrote:\s*\n/i", $text, 2);
    if (is_array($parts) && isset($parts[0])) {
        $text = trim((string) $parts[0]);
    }

    $lines = preg_split('/\R/', $text) ?: [];
    $kept = [];
    foreach ($lines as $line) {
        $line = trim((string) $line);
        if ($line === '' || str_starts_with($line, '>')) {
            continue;
        }
        $kept[] = $line;
    }
    if ($kept !== []) {
        $text = implode(' ', $kept);
    }

    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    $text = trim($text);

    if ($text === '') {
        return mail_list_snippet_fallback($plain, $html, $maxLen);
    }

    if (mb_strlen($text) > $maxLen) {
        return mb_substr($text, 0, max(1, $maxLen - 1)) . '…';
    }

    return $text;
}

/**
 * Fallback when quote-stripping leaves nothing (common on reply-only messages).
 */
function mail_list_snippet_fallback(?string $plain, ?string $html = null, int $maxLen = 140): string
{
    $raw = trim((string) $plain);
    if ($raw === '' && $html !== null && trim($html) !== '') {
        $raw = trim(html_entity_decode(
            strip_tags(str_replace(['<br>', '<br/>', '<br />', '</div>', '</p>', '</li>'], ' ', $html)),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        ));
    }

    $raw = preg_replace('/\s+/u', ' ', $raw) ?? $raw;
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }

    if (mb_strlen($raw) > $maxLen) {
        return mb_substr($raw, 0, max(1, $maxLen - 1)) . '…';
    }

    return $raw;
}

/**
 * @param array<string, mixed> $msg
 * @return array{is_draft: bool, list_from: string, snippet: string, avatar_from: string}
 */
function mail_list_row_display(array $msg, string $folderPath): array
{
    $isDraft = is_draft_folder($folderPath);
    $from = (string) ($msg['from'] ?? '');
    $to = (string) ($msg['to'] ?? '');
    $listFrom = (string) ($msg['list_from'] ?? '');
    if ($listFrom === '') {
        $listFrom = ($isDraft && $to !== '') ? format_mail_from($to) : format_mail_from($from);
    }

    return [
        'is_draft' => $isDraft,
        'list_from' => $listFrom,
        'snippet' => (string) ($msg['snippet'] ?? ''),
        'avatar_from' => ($isDraft && $to !== '') ? $to : $from,
    ];
}

/** Trash is a holding area — never show an unread badge in the sidebar or header. */
function folder_shows_unread_badge(string $path): bool
{
    return !is_trash_folder($path);
}

/** Drafts badge shows total draft count, not IMAP \\Seen flags. */
function folder_uses_draft_badge(string $path): bool
{
    return is_draft_folder($path);
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

function normalize_message_id(?string $messageId): string
{
    return strtolower(trim(trim((string) $messageId), '<>'));
}

function extract_message_id_from_mime(string $mime): ?string
{
    if (!preg_match('/^Message-ID:\s*(.+)$/im', $mime, $matches)) {
        return null;
    }

    $id = trim((string) ($matches[1] ?? ''));

    return $id !== '' ? trim($id, '<>') : null;
}

/**
 * True when outbound mail is routed to custom folders only (not the filter inbox).
 *
 * @param list<string> $destPaths
 */
function outbound_send_skips_inbox_badge(array $destPaths, string $inbox = ''): bool
{
    if ($destPaths === []) {
        return false;
    }

    $inbox = $inbox !== '' ? $inbox : (string) (config('app')['filter_source_folder'] ?? 'INBOX');

    foreach ($destPaths as $path) {
        $path = (string) $path;
        if ($path === $inbox || strtoupper($path) === 'INBOX') {
            return false;
        }
    }

    return true;
}

/**
 * Clear unread inbox echoes after send/reply when mail routes to other folders.
 *
 * @param list<string> $destPaths
 */
function reconcile_inbox_after_outbound_send(
    array $destPaths,
    string $fromEmail,
    ?string $sentMessageId = null,
    bool $runFilter = false,
): void {
    $inbox = (string) (config('app')['filter_source_folder'] ?? 'INBOX');
    if (!outbound_send_skips_inbox_badge($destPaths, $inbox)) {
        return;
    }

    $imap = new App\Services\ImapService();
    if (!$imap->connect()) {
        return;
    }

    $imap->suppressInboundEchoOfSentMessage($inbox, $fromEmail, 20, $sentMessageId);
    if ($runFilter) {
        App\Services\FilterService::runBackground(true, 8);
        $imap->suppressInboundEchoOfSentMessage($inbox, $fromEmail, 20, $sentMessageId);
    }
    App\Services\MailCacheService::reconcileBadgeFromIndex($inbox);
    App\Services\FolderCache::refreshPaths([$inbox]);
}

/**
 * Mark outbound copies in employee correspondent folders as read (no unread badge).
 *
 * @param list<string> $destPaths
 */
function reconcile_correspondent_outbound_echoes(
    App\Services\ImapService $imap,
    array $destPaths,
    string $fromEmail,
    ?string $sentMessageId = null,
    int $limit = 30,
): void {
    $user = App\Auth::user();
    if ($user === null || ($user['role'] ?? '') !== 'employee') {
        return;
    }

    $emails = mail_user_emails((int) $user['id']);
    $fromEmail = strtolower(trim($fromEmail));
    if ($fromEmail !== '' && !in_array($fromEmail, $emails, true)) {
        $emails[] = $fromEmail;
    }

    foreach (array_values(array_unique(array_filter($destPaths))) as $path) {
        $path = \App\Services\FolderCache::resolvePath((string) $path);
        if ($path === '' || !employee_outbound_correspondent_folder($path, $user)) {
            continue;
        }

        $imap->suppressInboundEchoOfSentMessage($path, $emails, $limit, $sentMessageId, false);
        App\Services\MailCacheService::reconcileBadgeFromIndex($path);
        App\Services\FolderCache::setUnreadCount($path, 0);
    }
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

/**
 * Email addresses that belong to the logged-in user (for read-view display).
 *
 * @return list<string>
 */
function mail_user_emails(?int $userId = null): array
{
    $aliasService = new App\Services\AliasService();

    if ($userId === null) {
        $user = App\Auth::user();
        $userId = $user['id'] ?? null;
    }

    $emails = [];
    $userAlias = strtolower(trim($aliasService->userAlias($userId)));
    if ($userAlias !== '') {
        $emails[] = $userAlias;
    }

    if ($userId !== null && $userId > 0) {
        $rows = App\Database::query(
            'SELECT email FROM aliases WHERE user_id = ? AND active = 1',
            [$userId]
        )->fetchAll();
        foreach ($rows as $row) {
            $email = strtolower(trim((string) ($row['email'] ?? '')));
            if ($email !== '') {
                $emails[] = $email;
            }
        }
    }

    return array_values(array_unique(array_filter($emails)));
}

/**
 * True when the logged-in user sent the message (From matches one of their aliases).
 */
function mail_is_sent_by_user(?string $from, ?int $userId = null): bool
{
    $fromEmail = strtolower(normalize_email_token((string) $from));
    if ($fromEmail === '') {
        return false;
    }

    return in_array($fromEmail, mail_user_emails($userId), true);
}

/**
 * Format To/Cc for read view — own addresses become "You" on inbound mail only.
 */
function format_mail_recipients(?string $header, bool $substituteSelf = true): string
{
    if ($header === null || trim($header) === '') {
        return '—';
    }

    if (!$substituteSelf) {
        return trim($header);
    }

    $own = mail_user_emails();
    $parts = preg_split('/\s*,\s*/', $header) ?: [];
    $out = [];
    $youAdded = false;

    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }

        $email = strtolower(normalize_email_token($part));
        if ($email !== '' && in_array($email, $own, true)) {
            if (!$youAdded) {
                $out[] = 'You';
                $youAdded = true;
            }
            continue;
        }

        $out[] = $part;
    }

    return $out === [] ? '—' : implode(', ', $out);
}

/**
 * @return array{date: string, from: string}
 */
function mail_parse_on_wrote_header(string $header): array
{
    $header = trim($header);
    if (preg_match('/^(.+?),\s*(.+)$/s', $header, $m)) {
        return ['date' => trim($m[1]), 'from' => trim($m[2])];
    }

    return ['date' => '', 'from' => $header];
}

function mail_unquote_plain(string $text): string
{
    $lines = preg_split('/\R/', $text) ?: [];
    $out = [];
    foreach ($lines as $line) {
        $out[] = preg_replace('/^>\s?/', '', (string) $line) ?? $line;
    }

    return trim(implode("\n", $out));
}

function mail_plain_from_html(string $html): string
{
    return trim(html_entity_decode(
        strip_tags(str_replace(['<br>', '<br/>', '<br />', '</div>', '</p>', '</li>'], "\n", $html)),
        ENT_QUOTES | ENT_HTML5,
        'UTF-8'
    ));
}

function mail_conversation_snippet(string $body, int $maxLen = 120): string
{
    $text = trim(preg_replace('/\s+/u', ' ', mail_unquote_plain($body)) ?? '');
    if ($text === '') {
        return '';
    }
    if (mb_strlen($text) > $maxLen) {
        return mb_substr($text, 0, $maxLen - 1) . '…';
    }

    return $text;
}

function mail_extract_latest_html(string $html): string
{
    return mail_split_html_quote($html)['visible'];
}

/**
 * Resolve a thread segment From header to an email when possible.
 */
function mail_normalize_segment_from(string $from): string
{
    $from = trim($from);
    if ($from === '') {
        return '';
    }

    $parsed = mail_parse_address($from);
    if ($parsed['email'] !== '') {
        return $parsed['email'];
    }

    $name = $parsed['name'] !== '' ? $parsed['name'] : $from;
    if ($name === '') {
        return $from;
    }

    $aliasService = new App\Services\AliasService();
    foreach (mail_user_emails() as $email) {
        if (strcasecmp($aliasService->getDisplayName($email), $name) === 0) {
            return $email;
        }
    }

    return $from;
}

/**
 * @return list<array{from: string, to: string, cc: string, date: string, body: string}>
 */
function mail_parse_quoted_block(string $text, array $header): array
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $body = $text;
    $nested = '';
    if (preg_match('/\n\s*On .+? wrote:\s*\n/is', $text, $match, PREG_OFFSET_CAPTURE)) {
        $body = substr($text, 0, $match[0][1]);
        $nested = substr($text, $match[0][1]);
    }

    $segments = [[
        'from' => mail_normalize_segment_from($header['from'] ?? ''),
        'to' => $header['to'] ?? '',
        'cc' => '',
        'date' => $header['date'] ?? '',
        'body' => mail_unquote_plain($body),
    ]];

    if ($nested !== '' && preg_match('/\n\s*On (.+?) wrote:\s*\n/is', $nested, $match, PREG_OFFSET_CAPTURE)) {
        $nestedHeader = mail_parse_on_wrote_header($match[1][0]);
        $nestedRest = substr($nested, $match[0][1] + strlen($match[0][0]));
        $segments = array_merge($segments, mail_parse_quoted_block($nestedRest, $nestedHeader));
    }

    return $segments;
}

/**
 * @return list<array{from: string, to: string, cc: string, date: string, body: string}>
 */
function mail_split_conversation_plain(string $plain): array
{
    $plain = str_replace(["\r\n", "\r"], "\n", trim($plain));
    if ($plain === '') {
        return [];
    }

    if (!preg_match('/\n\s*On (.+?) wrote:\s*\n/is', $plain, $match, PREG_OFFSET_CAPTURE)) {
        return [[
            'from' => '',
            'to' => '',
            'cc' => '',
            'date' => '',
            'body' => mail_unquote_plain($plain),
        ]];
    }

    $latest = trim(substr($plain, 0, $match[0][1]));
    $remainder = substr($plain, $match[0][1] + strlen($match[0][0]));
    $header = mail_parse_on_wrote_header($match[1][0]);

    $segments = [[
        'from' => '',
        'to' => '',
        'cc' => '',
        'date' => '',
        'body' => mail_unquote_plain($latest),
    ]];

    return array_merge($segments, mail_parse_quoted_block($remainder, $header));
}

/**
 * Tombstones for messages removed optimistically (hide until IMAP confirms).
 * Persisted to disk so deletes survive session_write_close() on API handlers.
 */
function mail_removed_uids_key(string $folderPath): string
{
    return strtoupper(\App\Services\FolderCache::resolvePath($folderPath));
}

function mail_removed_uids_user_key(string $folderPath): string
{
    $user = \App\Auth::user();
    $userId = (int) ($user['id'] ?? 0);
    $folderKey = mail_removed_uids_key($folderPath);

    if ($userId <= 0 || $folderKey === '') {
        return '';
    }

    return $userId . ':' . $folderKey;
}

function mail_removed_uids_store_path(): string
{
    return base_path('storage/removed_uids.json');
}

/**
 * @return array<string, array<string, int>>
 */
function mail_load_removed_uids_store(): array
{
    $path = mail_removed_uids_store_path();
    if (!is_readable($path)) {
        return [];
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        return [];
    }

    $cutoff = time() - (30 * 86400);
    foreach ($decoded as $storeKey => $uids) {
        if (!is_array($uids)) {
            unset($decoded[$storeKey]);
            continue;
        }
        foreach ($uids as $uid => $ts) {
            if ((int) $ts < $cutoff) {
                unset($decoded[$storeKey][$uid]);
            }
        }
        if ($decoded[$storeKey] === []) {
            unset($decoded[$storeKey]);
        }
    }

    return $decoded;
}

/**
 * @param array<string, array<string, int>> $data
 */
function mail_save_removed_uids_store(array $data): void
{
    $path = mail_removed_uids_store_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/**
 * @return array<int, true> uid => true
 */
function mail_removed_uids_for_folder(string $folderPath): array
{
    $removed = [];
    $storeKey = mail_removed_uids_user_key($folderPath);
    if ($storeKey !== '') {
        $store = mail_load_removed_uids_store();
        foreach ($store[$storeKey] ?? [] as $uid => $ts) {
            $uid = (int) $uid;
            if ($uid > 0) {
                $removed[$uid] = true;
            }
        }
    }

    $key = mail_removed_uids_key($folderPath);
    if ($key !== '' && isset($_SESSION['_mail_removed_uids'][$key]) && is_array($_SESSION['_mail_removed_uids'][$key])) {
        foreach ($_SESSION['_mail_removed_uids'][$key] as $uid => $ts) {
            $uid = (int) $uid;
            if ($uid > 0) {
                $removed[$uid] = true;
            }
        }
    }

    return $removed;
}

/**
 * @param list<int> $uids
 */
function mail_mark_uids_removed(string $folderPath, array $uids): void
{
    $key = mail_removed_uids_key($folderPath);
    $storeKey = mail_removed_uids_user_key($folderPath);
    if ($key === '' || $storeKey === '') {
        return;
    }

    $now = time();
    $store = mail_load_removed_uids_store();
    if (!isset($store[$storeKey]) || !is_array($store[$storeKey])) {
        $store[$storeKey] = [];
    }

    foreach ($uids as $uid) {
        $uid = (int) $uid;
        if ($uid <= 0) {
            continue;
        }
        $store[$storeKey][(string) $uid] = $now;
    }

    mail_save_removed_uids_store($store);

    ensure_session_writable();
    if (!isset($_SESSION['_mail_removed_uids']) || !is_array($_SESSION['_mail_removed_uids'])) {
        $_SESSION['_mail_removed_uids'] = [];
    }
    if (!isset($_SESSION['_mail_removed_uids'][$key]) || !is_array($_SESSION['_mail_removed_uids'][$key])) {
        $_SESSION['_mail_removed_uids'][$key] = [];
    }
    foreach ($uids as $uid) {
        $uid = (int) $uid;
        if ($uid > 0) {
            $_SESSION['_mail_removed_uids'][$key][$uid] = $now;
        }
    }
    session_write_close();
}

function mail_is_uid_removed(string $folderPath, int $uid): bool
{
    if ($folderPath === '' || $uid <= 0) {
        return false;
    }

    return isset(mail_removed_uids_for_folder($folderPath)[$uid]);
}

/**
 * @param list<int> $uids
 */
function mail_clear_removed_uids(string $folderPath, array $uids): void
{
    $key = mail_removed_uids_key($folderPath);
    $storeKey = mail_removed_uids_user_key($folderPath);
    if ($key === '' || $storeKey === '') {
        return;
    }

    $store = mail_load_removed_uids_store();
    if (isset($store[$storeKey]) && is_array($store[$storeKey])) {
        foreach ($uids as $uid) {
            unset($store[$storeKey][(string) (int) $uid]);
        }
        if ($store[$storeKey] === []) {
            unset($store[$storeKey]);
        }
        mail_save_removed_uids_store($store);
    }

    with_session_write(function () use ($key, $uids): void {
        if (!isset($_SESSION['_mail_removed_uids'][$key])) {
            return;
        }
        foreach ($uids as $uid) {
            unset($_SESSION['_mail_removed_uids'][$key][(int) $uid]);
        }
        if ($_SESSION['_mail_removed_uids'][$key] === []) {
            unset($_SESSION['_mail_removed_uids'][$key]);
        }
    });
}

/**
 * @param array{messages: list<array<string, mixed>>, total: int, page?: int, per_page?: int, total_pages?: int} $list
 * @return array{messages: list<array<string, mixed>>, total: int, page?: int, per_page?: int, total_pages?: int}
 */
function mail_filter_removed_messages(string $folderPath, array $list): array
{
    $removed = mail_removed_uids_for_folder($folderPath);
    if ($removed === [] || !isset($list['messages'])) {
        return $list;
    }

    $before = count($list['messages']);
    $filtered = [];
    foreach ($list['messages'] as $msg) {
        $uid = (int) ($msg['uid'] ?? 0);
        if ($uid > 0 && isset($removed[$uid])) {
            continue;
        }
        $filtered[] = $msg;
    }

    if ($before !== count($filtered)) {
        $list['total'] = max(0, (int) ($list['total'] ?? 0) - ($before - count($filtered)));
        if (isset($list['per_page'], $list['total_pages'])) {
            $perPage = max(1, (int) $list['per_page']);
            $list['total_pages'] = (int) max(1, (int) ceil($list['total'] / $perPage));
        }
    } elseif ($filtered === [] && $removed !== []) {
        $list['total'] = 0;
        $list['total_pages'] = 0;
        $list['page'] = 1;
    }

    $list['messages'] = $filtered;

    return $list;
}

/**
 * Session key for replies sent while viewing a message (shown in the thread until reload).
 */
function mail_thread_reply_session_key(string $folderPath, int $uid): string
{
    return strtoupper(\App\Services\FolderCache::resolvePath($folderPath)) . '#' . $uid;
}

function mail_thread_reply_cache_file(string $folderPath, int $uid): string
{
    $userId = (int) (App\Auth::user()['id'] ?? 0);
    $key = mail_thread_reply_session_key($folderPath, $uid);
    $safe = preg_replace('/[^a-zA-Z0-9._#-]/', '_', $key);
    $dir = base_path('storage/thread_replies/' . ($userId > 0 ? (string) $userId : 'guest'));

    return $dir . '/' . $safe . '.json';
}

/**
 * @param array{from?: string, to?: string, cc?: string, date?: string, body?: string, body_html?: string} $a
 * @param array{from?: string, to?: string, cc?: string, date?: string, body?: string, body_html?: string} $b
 */
function mail_thread_reply_matches(array $a, array $b): bool
{
    return trim((string) ($a['date'] ?? '')) === trim((string) ($b['date'] ?? ''))
        && trim((string) ($a['body'] ?? '')) === trim((string) ($b['body'] ?? ''))
        && trim((string) ($a['from'] ?? '')) === trim((string) ($b['from'] ?? ''));
}

/**
 * @param list<array{from?: string, to?: string, cc?: string, date?: string, body?: string, body_html?: string}> $replies
 * @return list<array{from?: string, to?: string, cc?: string, date?: string, body?: string, body_html?: string}>
 */
function mail_merge_thread_replies(array $replies): array
{
    $merged = [];
    foreach ($replies as $reply) {
        if (!is_array($reply)) {
            continue;
        }
        $duplicate = false;
        foreach ($merged as $existing) {
            if (mail_thread_reply_matches($existing, $reply)) {
                $duplicate = true;
                break;
            }
        }
        if (!$duplicate) {
            $merged[] = $reply;
        }
    }

    return $merged;
}

/**
 * @return list<array{from?: string, to?: string, cc?: string, date?: string, body?: string, body_html?: string}>
 */
function mail_load_thread_reply_cache(string $folderPath, int $uid): array
{
    if ($folderPath === '' || $uid <= 0) {
        return [];
    }

    $path = mail_thread_reply_cache_file($folderPath, $uid);
    if (!is_file($path)) {
        return [];
    }

    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);

    return is_array($decoded) ? mail_merge_thread_replies($decoded) : [];
}

/**
 * @param list<array{from?: string, to?: string, cc?: string, date?: string, body?: string, body_html?: string}> $replies
 */
function mail_save_thread_reply_cache(string $folderPath, int $uid, array $replies): void
{
    if ($folderPath === '' || $uid <= 0) {
        return;
    }

    $replies = mail_merge_thread_replies($replies);
    if ($replies === []) {
        return;
    }

    $path = mail_thread_reply_cache_file($folderPath, $uid);
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    file_put_contents($path, json_encode_safe($replies));
}

/**
 * @param array{from?: string, to?: string, cc?: string, date?: string, body?: string, body_html?: string} $reply
 */
function mail_store_thread_reply(string $folderPath, int $uid, array $reply): void
{
    if ($folderPath === '' || $uid <= 0) {
        return;
    }

    $key = mail_thread_reply_session_key($folderPath, $uid);
    if (!isset($_SESSION['_mail_thread_replies']) || !is_array($_SESSION['_mail_thread_replies'])) {
        $_SESSION['_mail_thread_replies'] = [];
    }
    if (!isset($_SESSION['_mail_thread_replies'][$key]) || !is_array($_SESSION['_mail_thread_replies'][$key])) {
        $_SESSION['_mail_thread_replies'][$key] = [];
    }

    foreach ($_SESSION['_mail_thread_replies'][$key] as $existing) {
        if (is_array($existing) && mail_thread_reply_matches($existing, $reply)) {
            return;
        }
    }

    $_SESSION['_mail_thread_replies'][$key][] = $reply;

    $cached = mail_load_thread_reply_cache($folderPath, $uid);
    $cached[] = $reply;
    mail_save_thread_reply_cache($folderPath, $uid, $cached);
}

/**
 * @return list<array{from?: string, to?: string, cc?: string, date?: string, body?: string, body_html?: string}>
 */
function mail_pending_thread_replies(string $folderPath, int $uid): array
{
    if ($folderPath === '' || $uid <= 0) {
        return [];
    }

    $key = mail_thread_reply_session_key($folderPath, $uid);
    $sessionReplies = $_SESSION['_mail_thread_replies'][$key] ?? [];
    if (!is_array($sessionReplies)) {
        $sessionReplies = [];
    }

    return mail_merge_thread_replies(array_merge(
        mail_load_thread_reply_cache($folderPath, $uid),
        $sessionReplies,
    ));
}

function mail_normalize_thread_body(string $body): string
{
    return trim(preg_replace('/\s+/u', ' ', mail_unquote_plain($body)) ?? '');
}

/**
 * @param array<string, mixed> $segment
 */
function mail_thread_segment_fingerprint(array $segment): string
{
    return strtolower(normalize_email_token((string) ($segment['from'] ?? '')))
        . '|' . trim((string) ($segment['date'] ?? ''))
        . '|' . mail_normalize_thread_body((string) ($segment['body'] ?? ''));
}

/**
 * @param list<array<string, mixed>> $segments
 * @return list<array<string, mixed>>
 */
function mail_dedupe_thread_segments(array $segments): array
{
    $out = [];
    $seen = [];

    foreach ($segments as $segment) {
        $fp = mail_thread_segment_fingerprint($segment);
        if ($fp === '||' || isset($seen[$fp])) {
            continue;
        }
        $seen[$fp] = true;
        $out[] = $segment;
    }

    return $out;
}

function mail_clear_thread_reply_cache(string $folderPath, int $uid): void
{
    if ($folderPath === '' || $uid <= 0) {
        return;
    }

    $key = mail_thread_reply_session_key($folderPath, $uid);
    unset($_SESSION['_mail_thread_replies'][$key]);
    @unlink(mail_thread_reply_cache_file($folderPath, $uid));
}

/**
 * @param list<array<string, mixed>> $segments
 * @param list<array{from?: string, to?: string, cc?: string, date?: string, body?: string, body_html?: string}> $pending
 * @return list<array{from?: string, to?: string, cc?: string, date?: string, body?: string, body_html?: string}>
 */
function mail_filter_redundant_pending_replies(array $segments, array $pending): array
{
    $fingerprints = [];
    foreach ($segments as $segment) {
        $fp = mail_thread_segment_fingerprint($segment);
        if ($fp !== '||') {
            $fingerprints[$fp] = true;
        }
    }

    $newestBody = $segments !== []
        ? mail_normalize_thread_body((string) ($segments[0]['body'] ?? ''))
        : '';

    return array_values(array_filter($pending, static function (array $reply) use ($fingerprints, $newestBody): bool {
        $body = mail_normalize_thread_body((string) ($reply['body'] ?? ''));
        if ($body === '') {
            return false;
        }

        $fp = mail_thread_segment_fingerprint([
            'from' => $reply['from'] ?? '',
            'date' => $reply['date'] ?? '',
            'body' => $reply['body'] ?? '',
        ]);
        if (isset($fingerprints[$fp])) {
            return false;
        }

        return !($newestBody !== '' && $newestBody === $body);
    }));
}

function mail_normalize_thread_subject(string $subject): string
{
    $subject = trim($subject);
    while (preg_match('/^(Re|Fwd|Fw):\s*/iu', $subject)) {
        $subject = trim((string) preg_replace('/^(Re|Fwd|Fw):\s*/iu', '', $subject, 1));
    }

    return $subject;
}

/**
 * First email address from a To/Cc header.
 */
function mail_first_recipient_email(?string $header): string
{
    if ($header === null || trim($header) === '') {
        return '';
    }

    $parsed = parse_email_list($header);
    foreach ($parsed['valid'] as $email) {
        $email = trim($email);
        if ($email !== '') {
            return $email;
        }
    }

    $token = normalize_email_token($header);
    if ($token !== '' && str_contains($token, '@')) {
        return $token;
    }

    return '';
}

/**
 * Outlook-style one-line preview on collapsed thread cards.
 *
 * @param array<string, mixed> $segment
 */
function mail_thread_collapsed_preview(array $segment, string $folderPath): string
{
    if (employee_is_correspondent_folder($folderPath)) {
        if (!mail_thread_segment_is_user_sent($segment, $folderPath)) {
            return 'To: You';
        }

        $email = mail_first_recipient_email((string) ($segment['to'] ?? ''));
        if ($email !== '') {
            return $email;
        }
    }

    if (mail_thread_segment_is_user_sent($segment, $folderPath)) {
        $email = mail_first_recipient_email((string) ($segment['to'] ?? ''));
        if ($email !== '') {
            return $email;
        }
    }

    return mail_conversation_snippet((string) ($segment['body'] ?? ''));
}

/**
 * Cached replies to the message being read (Sent folder and routed copies).
 *
 * @param array<string, mixed> $message
 * @return list<array{from?: string, to?: string, cc?: string, date?: string, body?: string, body_html?: string, is_sent_reply?: bool}>
 */
function mail_find_cached_thread_replies(string $folderPath, int $uid, array $message): array
{
    if ($uid <= 0 || is_draft_folder($folderPath)) {
        return [];
    }

    $baseSubject = mail_normalize_thread_subject((string) ($message['subject'] ?? ''));
    if ($baseSubject === '') {
        return [];
    }

    $msgDate = trim((string) ($message['date'] ?? ''));
    $currentKey = mail_thread_reply_session_key($folderPath, $uid);

    try {
        $rows = App\Database::query(
            'SELECT folder_path, imap_uid, from_addr, subject, msg_date
             FROM mail_index
             WHERE subject LIKE ?
             ORDER BY msg_date ASC',
            ['Re:%']
        )->fetchAll();
    } catch (\Throwable) {
        return [];
    }

    $replies = [];
    foreach ($rows as $row) {
        $rowFolder = (string) ($row['folder_path'] ?? '');
        $rowUid = (int) ($row['imap_uid'] ?? 0);
        if ($rowFolder === '' || $rowUid <= 0) {
            continue;
        }

        if (strcasecmp(mail_thread_reply_session_key($rowFolder, $rowUid), $currentKey) === 0) {
            continue;
        }

        $subject = (string) ($row['subject'] ?? '');
        if (mail_normalize_thread_subject($subject) !== $baseSubject) {
            continue;
        }

        $from = (string) ($row['from_addr'] ?? '');
        if (!mail_is_sent_by_user($from)) {
            continue;
        }

        $sentDate = trim((string) ($row['msg_date'] ?? ''));
        if ($msgDate !== '' && $sentDate !== '' && strtotime($sentDate) < strtotime($msgDate)) {
            continue;
        }

        $body = \App\Services\MailCacheService::getBody($rowFolder, $rowUid);
        if ($body === null) {
            continue;
        }

        $plain = trim((string) ($body['plain'] ?? ''));
        if ($plain === '') {
            continue;
        }

        $split = compose_split_reply_body($plain);
        $composePlain = mail_unquote_plain($split['compose'] !== '' ? $split['compose'] : $plain);
        if ($composePlain === '') {
            continue;
        }

        $composeHtml = '';
        $html = trim((string) ($body['html'] ?? ''));
        if ($html !== '') {
            $htmlSplit = mail_split_html_quote($html);
            $composeHtml = $htmlSplit['visible'];
        }

        $replies[] = [
            'from' => (string) ($body['from'] ?? $from),
            'to' => (string) ($body['to'] ?? ''),
            'cc' => (string) ($body['cc'] ?? ''),
            'date' => (string) ($body['date'] ?? $sentDate),
            'body' => $composePlain,
            'body_html' => $composeHtml,
            'is_sent_reply' => true,
        ];
    }

    return $replies;
}

/**
 * Inbound replies from a correspondent (e.g. Support) stored in the viewer's own
 * mailbox — used to show full threads inside correspondent folders.
 *
 * @param array<string, mixed> $message
 * @return list<array{from?: string, to?: string, cc?: string, date?: string, body?: string, body_html?: string, folder_path?: string, imap_uid?: int, is_inbound_reply?: bool}>
 */
function mail_find_correspondent_inbound_replies(string $folderPath, int $uid, array $message): array
{
    if ($uid <= 0 || !employee_is_correspondent_folder($folderPath)) {
        return [];
    }

    $baseSubject = mail_normalize_thread_subject((string) ($message['subject'] ?? ''));
    if ($baseSubject === '') {
        return [];
    }

    $afterDate = trim((string) ($message['date'] ?? ''));

    return mail_find_correspondent_inbound_for_subject($folderPath, $baseSubject, $afterDate !== '' ? $afterDate : null);
}

/**
 * Inbound replies from the correspondent alias in the employee's own mailbox.
 *
 * @return list<array{from?: string, to?: string, cc?: string, date?: string, body?: string, body_html?: string, folder_path?: string, imap_uid?: int, is_inbound_reply?: bool}>
 */
function mail_find_correspondent_inbound_for_subject(
    string $folderPath,
    string $baseSubject,
    ?string $afterDate = null,
): array {
    if (!employee_is_correspondent_folder($folderPath)) {
        return [];
    }

    $corrEmail = alias_email_for_folder($folderPath);
    if ($corrEmail === null || $corrEmail === '') {
        return [];
    }
    $corrEmail = strtolower(trim($corrEmail));

    $ownInbox = employee_linked_inbox_path();
    if ($ownInbox === null || $ownInbox === '') {
        return [];
    }
    $ownInbox = \App\Services\FolderCache::resolvePath($ownInbox);

    $baseSubject = mail_normalize_thread_subject($baseSubject);
    if ($baseSubject === '') {
        return [];
    }

    try {
        $rows = App\Database::query(
            'SELECT i.imap_uid, i.from_addr, i.subject, i.msg_date
             FROM mail_index i
             WHERE LOWER(i.folder_path) = LOWER(?)
             ORDER BY i.msg_date ASC',
            [$ownInbox]
        )->fetchAll();
    } catch (\Throwable) {
        return [];
    }

    $replies = [];
    foreach ($rows as $row) {
        $rowUid = (int) ($row['imap_uid'] ?? 0);
        if ($rowUid <= 0) {
            continue;
        }

        $fromRaw = (string) ($row['from_addr'] ?? '');
        $fromEmail = strtolower(normalize_email_token($fromRaw));
        if ($fromEmail !== $corrEmail && !str_contains(strtolower($fromRaw), $corrEmail)) {
            continue;
        }

        if (mail_normalize_thread_subject((string) ($row['subject'] ?? '')) !== $baseSubject) {
            continue;
        }

        $sentDate = trim((string) ($row['msg_date'] ?? ''));
        if ($afterDate !== null && $afterDate !== '' && $sentDate !== '' && strtotime($sentDate) <= strtotime($afterDate)) {
            continue;
        }

        $body = \App\Services\MailCacheService::getBody($ownInbox, $rowUid);
        if ($body === null) {
            continue;
        }

        $plain = trim((string) ($body['plain'] ?? ''));
        if ($plain === '') {
            continue;
        }

        $split = compose_split_reply_body($plain);
        $composePlain = mail_unquote_plain($split['compose'] !== '' ? $split['compose'] : $plain);
        if ($composePlain === '') {
            continue;
        }

        $composeHtml = '';
        $html = trim((string) ($body['html'] ?? ''));
        if ($html !== '') {
            $htmlSplit = mail_split_html_quote($html);
            $composeHtml = $htmlSplit['visible'];
        }

        $replies[] = [
            'from' => (string) ($body['from'] ?? $fromRaw),
            'to' => (string) ($body['to'] ?? ''),
            'cc' => (string) ($body['cc'] ?? ''),
            'date' => (string) ($body['date'] ?? $sentDate),
            'body' => $composePlain,
            'body_html' => $composeHtml,
            'folder_path' => $ownInbox,
            'imap_uid' => $rowUid,
            'is_inbound_reply' => true,
        ];
    }

    return $replies;
}

/**
 * Outbound copies in a correspondent folder for one conversation subject.
 *
 * @return list<array{from?: string, to?: string, cc?: string, date?: string, body?: string, body_html?: string, folder_path?: string, imap_uid?: int, is_outbound?: bool}>
 */
function mail_find_correspondent_outbound_for_subject(string $folderPath, string $baseSubject): array
{
    if (!employee_is_correspondent_folder($folderPath)) {
        return [];
    }

    $baseSubject = mail_normalize_thread_subject($baseSubject);
    if ($baseSubject === '') {
        return [];
    }

    $indexPath = \App\Services\FolderCache::resolvePath($folderPath);

    try {
        $rows = App\Database::query(
            'SELECT i.imap_uid, i.from_addr, i.subject, i.msg_date
             FROM mail_index i
             WHERE i.folder_path = ?
             ORDER BY i.msg_date ASC',
            [$indexPath]
        )->fetchAll();
    } catch (\Throwable) {
        return [];
    }

    $messages = [];
    foreach ($rows as $row) {
        $rowUid = (int) ($row['imap_uid'] ?? 0);
        if ($rowUid <= 0) {
            continue;
        }

        if (mail_normalize_thread_subject((string) ($row['subject'] ?? '')) !== $baseSubject) {
            continue;
        }

        $from = (string) ($row['from_addr'] ?? '');
        if (!mail_is_sent_by_user($from)) {
            continue;
        }

        $body = \App\Services\MailCacheService::getBody($indexPath, $rowUid);
        if ($body === null) {
            continue;
        }

        $plain = trim((string) ($body['plain'] ?? ''));
        if ($plain === '') {
            continue;
        }

        $split = compose_split_reply_body($plain);
        $composePlain = mail_unquote_plain($split['compose'] !== '' ? $split['compose'] : $plain);
        if ($composePlain === '') {
            continue;
        }

        $composeHtml = '';
        $html = trim((string) ($body['html'] ?? ''));
        if ($html !== '') {
            $htmlSplit = mail_split_html_quote($html);
            $composeHtml = $htmlSplit['visible'];
        }

        $messages[] = [
            'from' => (string) ($body['from'] ?? $from),
            'to' => (string) ($body['to'] ?? ''),
            'cc' => (string) ($body['cc'] ?? ''),
            'date' => (string) ($body['date'] ?? ($row['msg_date'] ?? '')),
            'body' => $composePlain,
            'body_html' => $composeHtml,
            'folder_path' => $indexPath,
            'imap_uid' => $rowUid,
            'is_outbound' => true,
        ];
    }

    return $messages;
}

/**
 * Full correspondent-folder thread: outbound copies + inbound replies in chronological order.
 *
 * @return list<array<string, mixed>>
 */
function mail_build_correspondent_conversation_thread(
    array $message,
    string $sanitizedHtml,
    ?string $replyFrom,
    string $folderPath,
    int $uid,
): array {
    $baseSubject = mail_normalize_thread_subject((string) ($message['subject'] ?? ''));
    if ($baseSubject === '') {
        return [];
    }

    $outbound = mail_find_correspondent_outbound_for_subject($folderPath, $baseSubject);
    $inbound = mail_find_correspondent_inbound_for_subject($folderPath, $baseSubject);

    $entries = [];
    foreach ($outbound as $item) {
        $entries[] = $item;
    }
    foreach ($inbound as $item) {
        $entries[] = $item;
    }

    if ($entries === []) {
        return [];
    }

    usort($entries, static function (array $a, array $b): int {
        $aTs = strtotime((string) ($a['date'] ?? '')) ?: 0;
        $bTs = strtotime((string) ($b['date'] ?? '')) ?: 0;
        if ($aTs === $bTs) {
            return ((int) ($a['imap_uid'] ?? 0)) <=> ((int) ($b['imap_uid'] ?? 0));
        }

        return $aTs <=> $bTs;
    });

    $segments = [];
    $hasCurrent = false;
    foreach ($entries as $entry) {
        $entryUid = (int) ($entry['imap_uid'] ?? 0);
        $isCurrent = !empty($entry['is_outbound']) && $entryUid === $uid;
        if ($isCurrent) {
            $hasCurrent = true;
        }

        $segments[] = [
            'from' => (string) ($entry['from'] ?? ''),
            'to' => (string) ($entry['to'] ?? ''),
            'cc' => (string) ($entry['cc'] ?? ''),
            'date' => (string) ($entry['date'] ?? ''),
            'body' => (string) ($entry['body'] ?? ''),
            'body_html' => (string) ($entry['body_html'] ?? ''),
            'quoted_plain' => '',
            'quoted_html' => '',
            'is_current' => $isCurrent,
            'is_sent_reply' => empty($entry['is_inbound_reply']),
            'is_inbound_reply' => !empty($entry['is_inbound_reply']),
            'imap_uid' => $entryUid,
            'snippet' => mail_conversation_snippet((string) ($entry['body'] ?? '')),
        ];
    }

    if (!$hasCurrent) {
        for ($i = count($segments) - 1; $i >= 0; $i--) {
            if (!empty($segments[$i]['is_sent_reply'])) {
                $segments[$i]['is_current'] = true;
                break;
            }
        }
    }

    $count = count($segments);
    for ($i = 0; $i < $count; $i++) {
        if (trim((string) ($segments[$i]['to'] ?? '')) !== '') {
            continue;
        }
        $from = (string) ($segments[$i]['from'] ?? '');
        if (!mail_is_sent_by_user($from)) {
            continue;
        }
        for ($j = $i - 1; $j >= 0; $j--) {
            $newerFrom = mail_normalize_segment_from((string) ($segments[$j]['from'] ?? ''));
            if ($newerFrom !== '' && !mail_is_sent_by_user($newerFrom)) {
                $segments[$i]['to'] = $newerFrom;
                break;
            }
        }
    }

    return mail_sort_thread_segments_chronological(mail_dedupe_thread_segments($segments));
}

/**
 * @param list<array<string, mixed>> $segments
 * @return list<array<string, mixed>>
 */
function mail_sort_thread_segments_chronological(array $segments): array
{
    if (count($segments) <= 1) {
        return $segments;
    }

    $indexed = [];
    foreach ($segments as $i => $segment) {
        $indexed[] = [
            'i' => $i,
            'segment' => $segment,
            'ts' => strtotime((string) ($segment['date'] ?? '')) ?: 0,
        ];
    }

    usort($indexed, static function (array $a, array $b): int {
        if ($a['ts'] === $b['ts']) {
            return $a['i'] <=> $b['i'];
        }

        return $a['ts'] <=> $b['ts'];
    });

    return array_map(static fn (array $row): array => $row['segment'], $indexed);
}

/**
 * Mark correspondent-thread inbound replies read in the viewer's own mailbox.
 *
 * @param list<array<string, mixed>> $inboundReplies
 */
function mail_mark_correspondent_inbound_read(string $corrFolderPath, int $uid, array $message): void
{
    foreach (mail_find_correspondent_inbound_replies($corrFolderPath, $uid, $message) as $reply) {
        $replyFolder = (string) ($reply['folder_path'] ?? '');
        $replyUid = (int) ($reply['imap_uid'] ?? 0);
        if ($replyFolder === '' || $replyUid <= 0) {
            continue;
        }
        if (\App\Services\MailCacheService::effectiveSeen($replyFolder, $replyUid)) {
            continue;
        }
        \App\Services\MailCacheService::markReadForUser($replyFolder, $replyUid);
        if (\App\Services\MailCacheService::readUpdatesImapState($replyFolder)) {
            \App\Services\MailCacheService::updateIndexSeen($replyFolder, $replyUid, true);
            \App\Services\FolderCache::bumpUnread($replyFolder, -1);
            $imap = new App\Services\ImapService();
            if ($imap->connect()) {
                $imap->markSeen($replyFolder, $replyUid);
            }
        }
    }

    \App\Services\MailCacheService::reconcileBadgeFromIndex($corrFolderPath);
    $ownInbox = employee_linked_inbox_path();
    if ($ownInbox !== null && $ownInbox !== '') {
        \App\Services\MailCacheService::reconcileBadgeFromIndex($ownInbox);
    }
}

/**
 * @param array<string, mixed> $msg
 */
function mail_enrich_list_with_thread_preview(string $folderPath, array &$msg): void
{
    if (is_draft_folder($folderPath) || is_sent_folder($folderPath)) {
        return;
    }

    $uid = (int) ($msg['uid'] ?? 0);
    if ($uid <= 0) {
        return;
    }

    if (employee_is_correspondent_folder($folderPath)) {
        mail_enrich_correspondent_folder_list_row($folderPath, $msg);

        return;
    }

    $replies = mail_find_cached_thread_replies($folderPath, $uid, $msg);

    if ($replies === []) {
        return;
    }

    $latest = $replies[count($replies) - 1];
    $baseSubject = mail_normalize_thread_subject((string) ($msg['subject'] ?? ''));
    if ($baseSubject !== '') {
        $msg['subject'] = 'Re: ' . $baseSubject;
    }

    $snippet = mail_conversation_snippet((string) ($latest['body'] ?? ''));
    if ($snippet !== '') {
        $msg['snippet'] = $snippet;
    }

    // Outlook-style: list row keeps the conversation partner (original sender), not "You".
}

/**
 * Build Outlook-style conversation segments (oldest first for read view).
 *
 * @return list<array<string, mixed>>
 */
function mail_build_conversation_thread(
    array $message,
    string $sanitizedHtml = '',
    ?string $replyFrom = null,
    ?string $folderPath = null,
    ?int $uid = null,
): array {
    if (
        $folderPath !== null
        && $folderPath !== ''
        && $uid !== null
        && $uid > 0
        && employee_is_correspondent_folder($folderPath)
    ) {
        $correspondentThread = mail_build_correspondent_conversation_thread(
            $message,
            $sanitizedHtml,
            $replyFrom,
            $folderPath,
            $uid,
        );
        if ($correspondentThread !== []) {
            return $correspondentThread;
        }
    }

    $plain = trim((string) ($message['plain'] ?? ''));
    if ($plain === '' && $sanitizedHtml !== '') {
        $plain = mail_plain_from_html($sanitizedHtml);
    }

    $segments = mail_split_conversation_plain($plain);
    if ($segments === []) {
        $segments[] = [
            'from' => '',
            'to' => '',
            'cc' => '',
            'date' => '',
            'body' => '',
        ];
    }

    $segments[0]['from'] = (string) ($message['from'] ?? '');
    $segments[0]['to'] = (string) ($message['to'] ?? '');
    $segments[0]['cc'] = (string) ($message['cc'] ?? '');
    $segments[0]['date'] = (string) ($message['date'] ?? '');
    $segments[0]['is_current'] = true;

    $fullSplit = compose_split_reply_body($plain);
    $htmlSplit = mail_split_html_quote($sanitizedHtml);
    $hasQuotedHistory = count($segments) > 1;
    $isThreadedReply = (bool) preg_match('/^Re:\s/i', (string) ($message['subject'] ?? ''));

    if (count($segments) === 1) {
        $segments[0]['body'] = $fullSplit['compose'] !== ''
            ? mail_unquote_plain($fullSplit['compose'])
            : mail_unquote_plain($segments[0]['body']);
        $segments[0]['body_html'] = $htmlSplit['visible'];
        $segments[0]['quoted_plain'] = $fullSplit['quoted'];
        $segments[0]['quoted_html'] = $htmlSplit['quoted'];
    } else {
        if ($fullSplit['compose'] !== '') {
            $segments[0]['body'] = mail_unquote_plain($fullSplit['compose']);
        }
        $segments[0]['body_html'] = $htmlSplit['visible'] !== ''
            ? $htmlSplit['visible']
            : (trim(strip_tags(mail_extract_latest_html($sanitizedHtml))) !== ''
                ? mail_extract_latest_html($sanitizedHtml)
                : '');
        // Quoted history is shown via prior thread cards or inline dots.
        $segments[0]['quoted_plain'] = '';
        $segments[0]['quoted_html'] = '';
    }

    foreach ($segments as $i => &$segment) {
        if ($i > 0) {
            $segment['is_current'] = false;
            $segment['body_html'] = '';
            $segment['cc'] = '';
            $segment['from'] = mail_normalize_segment_from((string) ($segment['from'] ?? ''));
        }
        $segment['snippet'] = mail_conversation_snippet((string) ($segment['body'] ?? ''));
    }
    unset($segment);

    if ($folderPath !== null && $folderPath !== '' && $uid !== null && $uid > 0) {
        $extraReplies = mail_pending_thread_replies($folderPath, $uid);
        if ($isThreadedReply && $hasQuotedHistory) {
            mail_clear_thread_reply_cache($folderPath, $uid);
            $extraReplies = [];
        } else {
            $cachedSent = mail_find_cached_thread_replies($folderPath, $uid, $message);
            $cachedInbound = employee_is_correspondent_folder($folderPath)
                ? mail_find_correspondent_inbound_replies($folderPath, $uid, $message)
                : [];
            $extraReplies = mail_merge_thread_replies(array_merge($extraReplies, $cachedSent, $cachedInbound));
            $extraReplies = mail_filter_redundant_pending_replies($segments, $extraReplies);
        }

        foreach (array_reverse($extraReplies) as $reply) {
            $body = trim((string) ($reply['body'] ?? ''));
            $bodyHtml = trim((string) ($reply['body_html'] ?? ''));
            array_unshift($segments, [
                'from' => (string) ($reply['from'] ?? ''),
                'to' => (string) ($reply['to'] ?? ''),
                'cc' => (string) ($reply['cc'] ?? ''),
                'date' => (string) ($reply['date'] ?? ''),
                'body' => $body,
                'body_html' => $bodyHtml,
                'quoted_plain' => '',
                'quoted_html' => '',
                'is_current' => false,
                'is_sent_reply' => !empty($reply['is_inbound_reply']) ? false : true,
                'is_inbound_reply' => !empty($reply['is_inbound_reply']),
                'snippet' => mail_conversation_snippet($body),
            ]);
        }
    }

    // Infer To: on older outbound segments (quoted originals) for collapsed preview.
    $count = count($segments);
    for ($i = 0; $i < $count; $i++) {
        if (trim((string) ($segments[$i]['to'] ?? '')) !== '') {
            continue;
        }
        $from = (string) ($segments[$i]['from'] ?? '');
        if (!mail_is_sent_by_user($from)) {
            continue;
        }
        for ($j = $i - 1; $j >= 0; $j--) {
            $newerFrom = mail_normalize_segment_from((string) ($segments[$j]['from'] ?? ''));
            if ($newerFrom !== '' && !mail_is_sent_by_user($newerFrom)) {
                $segments[$i]['to'] = $newerFrom;
                break;
            }
        }
    }

    return mail_sort_thread_segments_chronological(mail_dedupe_thread_segments($segments));
}

/**
 * @param array<string, mixed> $segment
 * @return array{
 *   sender_name: string,
 *   sender_email: string,
 *   avatar_initial: string,
 *   avatar_color: string,
 *   display_to: string,
 *   display_cc: string,
 *   display_date: string,
 *   collapsed_preview: string
 * }
 */
function mail_thread_segment_display(array $segment, ?string $replyFrom = null, string $folderPath = ''): array
{
    $from = (string) ($segment['from'] ?? '');
    $userSent = mail_thread_segment_is_user_sent($segment, $folderPath);
    $parsed = mail_parse_address($from);

    if ($userSent) {
        $senderName = 'You';
        $senderEmail = '';
        $avatarInitial = mail_initials_from_alias_or_address($from, $replyFrom);
        $avatarColor = mail_avatar_color($from !== '' ? $from : ($replyFrom ?? ''));
    } else {
        $aliasName = $from !== '' ? (new App\Services\AliasService())->getDisplayName($from) : '';
        $email = $parsed['email'] !== '' ? $parsed['email'] : normalize_email_token($from);
        if ($parsed['name'] !== '') {
            $senderName = $parsed['name'];
            $senderEmail = $parsed['email'];
        } elseif ($aliasName !== '' && $email !== '' && strcasecmp($aliasName, $email) !== 0) {
            $senderName = $aliasName;
            $senderEmail = $email;
        } elseif ($email !== '') {
            $senderName = $email;
            $senderEmail = '';
        } else {
            $senderName = '—';
            $senderEmail = '';
        }
        $avatarInitial = mail_initials_from_alias_or_address($from);
        $avatarColor = mail_avatar_color($from);
    }

    return [
        'sender_name' => $senderName,
        'sender_email' => $senderEmail,
        'avatar_initial' => $avatarInitial,
        'avatar_color' => $avatarColor,
        'display_to' => format_mail_recipients($segment['to'] ?? null, !$userSent),
        'display_cc' => !empty($segment['cc']) ? format_mail_recipients($segment['cc'], !$userSent) : '',
        'display_date' => format_mail_read_datetime($segment['date'] ?? ''),
        'collapsed_preview' => mail_thread_collapsed_preview($segment, $folderPath),
    ];
}

/**
 * Outlook-style Sent line (e.g. Thursday, February 5, 2026 4:29 AM).
 */
function format_mail_outlook_sent_datetime(?string $date): string
{
    if ($date === null || trim($date) === '') {
        return '—';
    }

    $dt = to_app_datetime($date);
    if ($dt === null) {
        return $date;
    }

    return $dt->format('l, F j, Y g:i A');
}

/**
 * From line for inline thread history (Name <email@example.com>).
 *
 * @param array<string, mixed> $segment
 */
function mail_thread_inline_from_line(array $segment, ?string $replyFrom, string $folderPath): string
{
    $display = mail_thread_segment_display($segment, $replyFrom, $folderPath);
    $fromLine = $display['sender_name'];
    if ($display['sender_email'] !== '') {
        return $fromLine . ' <' . $display['sender_email'] . '>';
    }

    $parsed = mail_parse_address((string) ($segment['from'] ?? ''));
    if ($parsed['email'] !== '') {
        return $fromLine . ' <' . $parsed['email'] . '>';
    }

    if (mail_thread_segment_is_user_sent($segment, $folderPath) && $replyFrom !== null && $replyFrom !== '') {
        return $fromLine . ' <' . normalize_email_token($replyFrom) . '>';
    }

    return $fromLine;
}

/**
 * Render Outlook-style inline blocks for earlier messages in a thread.
 *
 * @param list<array<string, mixed>> $segments Newest prior segment first
 */
function mail_render_thread_inline_history_html(
    array $segments,
    string $subject,
    ?string $replyFrom,
    string $folderPath,
): string {
    if ($segments === []) {
        return '';
    }

    $blocks = [];
    foreach ($segments as $segment) {
        $display = mail_thread_segment_display($segment, $replyFrom, $folderPath);
        $fromLine = mail_thread_inline_from_line($segment, $replyFrom, $folderPath);
        $sent = format_mail_outlook_sent_datetime($segment['date'] ?? '');
        $to = $display['display_to'];

        $meta = '<div class="mail-thread-inline-meta">'
            . '<div><strong>From:</strong> ' . e($fromLine) . '</div>'
            . '<div><strong>Sent:</strong> ' . e($sent) . '</div>'
            . '<div><strong>To:</strong> ' . e($to) . '</div>'
            . '<div><strong>Subject:</strong> ' . e($subject) . '</div>'
            . '</div>';

        $body = '';
        if (trim((string) ($segment['body_html'] ?? '')) !== '') {
            $body = '<div class="mail-thread-inline-body mail-body-html mail-body-html--quoted">'
                . $segment['body_html']
                . '</div>';
        } elseif (trim((string) ($segment['body'] ?? '')) !== '') {
            $body = '<pre class="mail-thread-inline-body compose-quoted-text">'
                . e((string) $segment['body'])
                . '</pre>';
        }

        $blocks[] = '<div class="mail-thread-inline-block">'
            . '<hr class="mail-thread-inline-sep" aria-hidden="true">'
            . $meta
            . $body
            . '</div>';
    }

    return '<div class="mail-thread-inline-history-inner">' . implode('', $blocks) . '</div>';
}

function mail_avatar_initials_from_name(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return '?';
    }

    $parts = preg_split('/\s+/', $name) ?: [];
    if (count($parts) >= 2) {
        return mb_strtoupper(
            mb_substr($parts[0], 0, 1) . mb_substr($parts[count($parts) - 1], 0, 1)
        );
    }

    return mb_strtoupper(mb_substr($name, 0, 2));
}

function mail_initials_from_alias_or_address(string $from, ?string $fallbackEmail = null): string
{
    $email = trim($from);
    if ($email === '') {
        $email = trim((string) $fallbackEmail);
    }

    if ($email !== '') {
        $aliasName = (new App\Services\AliasService())->getDisplayName($email);
        if ($aliasName !== '' && strcasecmp($aliasName, $email) !== 0) {
            return mail_avatar_initials_from_name($aliasName);
        }

        return mail_avatar_initials_from_header($email);
    }

    return mail_user_initials();
}

/**
 * True when this thread segment is an outbound message from the current user.
 *
 * @param array<string, mixed> $segment
 */
function mail_thread_segment_is_user_sent(array $segment, string $folderPath): bool
{
    if (!empty($segment['is_sent_reply'])) {
        return true;
    }

    $from = (string) ($segment['from'] ?? '');
    if ($from === '' || !mail_is_sent_by_user($from)) {
        return false;
    }

    if (employee_is_correspondent_folder($folderPath)) {
        return true;
    }

    // Quoted earlier messages in a thread that we sent.
    if (empty($segment['is_current'])) {
        return true;
    }

    $folderLower = strtolower($folderPath);
    if (str_contains($folderLower, 'sent') || str_contains($folderLower, 'draft')) {
        return true;
    }

    // In employee/inbox folders a matching From: on the open message is usually inbound.
    return false;
}

function mail_avatar_initials_from_header(?string $from): string
{
    $parsed = mail_parse_address((string) $from);
    $name = $parsed['name'] !== '' ? $parsed['name'] : $parsed['email'];
    if ($name === '') {
        return '?';
    }

    $parts = preg_split('/\s+/', trim($name)) ?: [];
    if (count($parts) >= 2) {
        return mb_strtoupper(
            mb_substr($parts[0], 0, 1) . mb_substr($parts[count($parts) - 1], 0, 1)
        );
    }

    return mb_strtoupper(mb_substr($name, 0, 2));
}

/**
 * Load draft message fields for the edit-draft compose form.
 *
 * @return array{
 *     to: string,
 *     cc: string,
 *     bcc: string,
 *     subject: string,
 *     body: string,
 *     body_html: string,
 *     from_email: string,
 *     draftFolder: string,
 *     draftUid: int
 * }|null
 */
function compose_draft_form_context(string $folderPath, int $uid): ?array
{
    if ($folderPath === '' || $uid <= 0 || !is_draft_folder($folderPath)) {
        return null;
    }

    $imap = new \App\Services\ImapService();
    if (!$imap->connect()) {
        return null;
    }

    $message = $imap->getMessageByUid($folderPath, $uid);
    if ($message === null) {
        return null;
    }

    $cached = \App\Services\MailCacheService::getBody($folderPath, $uid);
    $aliasService = new \App\Services\AliasService();
    $userId = \App\Auth::user()['id'] ?? null;
    $savedFrom = (string) ($cached['from'] ?? '');
    $mimeFrom = (string) ($message['from'] ?? '');
    $fromEmail = $aliasService->resolveAllowedFrom(
        $savedFrom !== '' ? $savedFrom : $mimeFrom,
        $userId
    );

    $sessionUser = \App\Auth::user();
    if ($sessionUser !== null && ($sessionUser['role'] ?? '') === 'employee') {
        $fromEmail = $aliasService->userAlias((int) ($sessionUser['id'] ?? 0));
    }

    return [
        'to' => (string) ($message['to'] ?? ''),
        'cc' => (string) ($message['cc'] ?? ''),
        'bcc' => (string) ($message['bcc'] ?? ''),
        'subject' => (string) ($message['subject'] ?? ''),
        'body' => (string) ($cached['plain'] ?? $message['plain'] ?? ''),
        'body_html' => (string) ($cached['html'] ?? $message['html'] ?? ''),
        'from_email' => $fromEmail,
        'folderPath' => $folderPath,
        'draftFolder' => $folderPath,
        'draftUid' => $uid,
    ];
}

function compose_split_reply_body(string $body): array
{
    $body = str_replace(["\r\n", "\r"], "\n", $body);
    $patterns = [
        '/\n\s*On .+? wrote:\s*\n/is',
        '/\n\nOn .+ wrote:\n/s',
        '/(?:\n\n|^)-{3,}\s*Forwarded message\s*-{3,}\s*\n/s',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $body, $match, PREG_OFFSET_CAPTURE)) {
            return [
                'compose' => rtrim(substr($body, 0, $match[0][1])),
                'quoted' => substr($body, $match[0][1]),
            ];
        }
    }

    return ['compose' => trim($body), 'quoted' => ''];
}

/**
 * @return array{visible: string, quoted: string}
 */
function mail_split_html_quote(string $html): array
{
    if (trim($html) === '') {
        return ['visible' => '', 'quoted' => ''];
    }

    $patterns = [
        '/(<div[^>]*id=["\']divRplyFwdMsg["\'][^>]*>.*)$/is',
        '/(<div[^>]*class=["\'][^"\']*gmail_quote[^"\']*["\'][^>]*>.*)$/is',
        '/(<blockquote[^>]*>.*)$/is',
        '/(<br\s*\/?>\s*On .+? wrote:\s*<br\s*\/?>.*)$/is',
        '/(\s*On .+? wrote:\s*<br\s*\/?>.*)$/is',
        '/(\n\s*On .+? wrote:\s*\n.*)$/is',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $html, $match, PREG_OFFSET_CAPTURE)) {
            return [
                'visible' => trim(substr($html, 0, $match[0][1])),
                'quoted' => substr($html, $match[0][1]),
            ];
        }
    }

    return ['visible' => trim($html), 'quoted' => ''];
}

function mail_avatar_initial(?string $from): string
{
    if ($from === null || trim($from) === '') {
        return '?';
    }

    return mail_initials_from_alias_or_address($from);
}

function mail_avatar_color(?string $from): string
{
    $colors = ['#8b5cf6', '#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#ec4899', '#6366f1', '#14b8a6'];
    $email = $from ?? '';
    $h = 0;
    $len = strlen($email);
    for ($i = 0; $i < $len; $i++) {
        $h = (($h << 5) - $h + ord($email[$i])) & 0x7FFFFFFF;
    }

    return $colors[$h % count($colors)];
}
