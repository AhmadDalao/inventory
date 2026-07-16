<?php
declare(strict_types=1);

function request_method(): string
{
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    return $method === 'HEAD' ? 'GET' : $method;
}

function request_path(): string
{
    static $path;

    if ($path !== null) {
        return $path;
    }

    $rawPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $basePath = (string) app_config('app.base_path', '');

    if ($basePath !== '' && $basePath !== '/' && starts_with($rawPath, $basePath)) {
        $rawPath = substr($rawPath, strlen($basePath)) ?: '/';
    }

    $normalized = '/' . trim($rawPath, '/');
    $path = $normalized === '//' ? '/' : rtrim($normalized, '/');

    return $path === '' ? '/' : $path;
}

function url(string $path = '/'): string
{
    $basePath = rtrim((string) app_config('app.base_path', ''), '/');
    $normalized = '/' . ltrim($path, '/');

    if ($normalized === '/index.php') {
        $normalized = '/';
    }

    if ($normalized === '/') {
        return $basePath === '' ? '/' : $basePath;
    }

    return ($basePath === '' ? '' : $basePath) . $normalized;
}

function asset_url(string $path): string
{
    $relativePath = 'assets/' . ltrim($path, '/');
    $assetUrl = url('/' . $relativePath);
    $assetPath = base_path($relativePath);

    if (!is_file($assetPath)) {
        return $assetUrl;
    }

    return $assetUrl . '?v=' . filemtime($assetPath);
}

function request_is_secure(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    $forwardedProto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));

    if ($forwardedProto === 'https') {
        return true;
    }

    $forwardedSsl = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')));

    return $forwardedSsl === 'on';
}

function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('X-Permitted-Cross-Domain-Policies: none');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Permissions-Policy: camera=(self), microphone=(), geolocation=(), payment=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdn.jsdelivr.net 'wasm-unsafe-eval'; worker-src 'self' blob: https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self' https://cdn.jsdelivr.net blob: data:; object-src 'none'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");

    if (request_is_secure()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

function safe_redirect_target(?string $target, string $fallback = '/'): string
{
    $target = preg_replace('/[\x00-\x1F\x7F]+/', '', trim((string) $target));

    if ($target === '') {
        return $fallback;
    }

    if (starts_with($target, '//')) {
        return $fallback;
    }

    $path = (string) parse_url($target, PHP_URL_PATH);
    $query = (string) parse_url($target, PHP_URL_QUERY);
    $fragment = (string) parse_url($target, PHP_URL_FRAGMENT);
    $host = (string) parse_url($target, PHP_URL_HOST);

    if ($host !== '') {
        $requestHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));

        if ($requestHost === '' || strtolower($host) !== $requestHost) {
            return $fallback;
        }
    }

    if ($path === '') {
        $path = '/';
    }

    $basePath = rtrim((string) app_config('app.base_path', ''), '/');

    if ($basePath !== '' && starts_with($path, $basePath)) {
        $path = substr($path, strlen($basePath)) ?: '/';
    }

    $safe = '/' . ltrim($path, '/');

    if ($query !== '') {
        $safe .= '?' . $query;
    }

    if ($fragment !== '') {
        $safe .= '#' . $fragment;
    }

    return $safe;
}

function safe_download_filename(string $filename, string $fallback = 'download'): string
{
    $filename = basename(str_replace('\\', '/', $filename));
    $filename = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', '', $filename));
    $filename = str_replace(['"', "'", ';'], '', $filename);

    if ($filename === '' || $filename === '.' || $filename === '..') {
        $filename = $fallback;
    }

    return substr($filename, 0, 180);
}

function content_disposition_attachment(string $filename): string
{
    $filename = safe_download_filename($filename);
    $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?: 'download';

    return 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($filename);
}

function content_disposition_inline(string $filename): string
{
    $filename = safe_download_filename($filename);
    $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?: 'preview';

    return 'inline; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($filename);
}

function send_download_headers(string $mimeType, string $filename, int $contentLength): void
{
    header('Content-Type: ' . ($mimeType !== '' ? $mimeType : 'application/octet-stream'));
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: ' . content_disposition_attachment($filename));
    header('Content-Transfer-Encoding: binary');
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');

    if ($contentLength >= 0) {
        header('Content-Length: ' . $contentLength);
    }
}

function send_inline_file_headers(string $mimeType, string $filename, int $contentLength): void
{
    header('Content-Type: ' . ($mimeType !== '' ? $mimeType : 'application/octet-stream'));
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: ' . content_disposition_inline($filename));
    header('Content-Transfer-Encoding: binary');
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');

    if ($contentLength >= 0) {
        header('Content-Length: ' . $contentLength);
    }
}

function csv_safe_cell($value): string
{
    if ($value === null) {
        return '';
    }

    $text = (string) $value;

    if ($text !== '' && preg_match('/^[=+\-@\t\r\n]/', $text) === 1) {
        return "'" . $text;
    }

    return $text;
}

function redirect(string $path = '/'): never
{
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));

    if (strpos($accept, 'application/json') !== false) {
        $flashes = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);

        $lastFlash = end($flashes) ?: null;
        $hasDanger = false;

        foreach ($flashes as $flashMessage) {
            if (($flashMessage['type'] ?? '') === 'danger') {
                $hasDanger = true;
                break;
            }
        }

        json_response([
            'ok' => !$hasDanger,
            'message' => $lastFlash['message'] ?? ($hasDanger ? 'Action failed.' : 'Saved.'),
            'messages' => $flashes,
            'redirect_url' => url($path),
        ], $hasDanger ? 422 : 200);
    }

    header('Location: ' . url($path));
    exit;
}

function redirect_to_referer(string $fallback = '/'): never
{
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    $target = safe_redirect_target($referer, $fallback);

    if ($target !== $fallback) {
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));

        if (strpos($accept, 'application/json') !== false) {
            $flashes = $_SESSION['_flash'] ?? [];
            unset($_SESSION['_flash']);

            $lastFlash = end($flashes) ?: null;
            $hasDanger = false;

            foreach ($flashes as $flashMessage) {
                if (($flashMessage['type'] ?? '') === 'danger') {
                    $hasDanger = true;
                    break;
                }
            }

            json_response([
                'ok' => !$hasDanger,
                'message' => $lastFlash['message'] ?? ($hasDanger ? 'Action failed.' : 'Saved.'),
                'messages' => $flashes,
                'redirect_url' => url($target),
            ], $hasDanger ? 422 : 200);
        }

        header('Location: ' . url($target));
        exit;
    }

    redirect($fallback);
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = [
        'type' => $type,
        'message' => $message,
    ];
}

function consume_flashes(): array
{
    $messages = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);

    return $messages;
}

function old(string $key, $default = '')
{
    return $_SESSION['_old'][$key] ?? $default;
}

function flash_old_input(array $values): void
{
    $_SESSION['_old'] = $values;
}

function consume_old_input(): void
{
    unset($_SESSION['_old']);
}

function input(string $key, $default = '')
{
    return $_POST[$key] ?? $_GET[$key] ?? $default;
}

function query(string $key, $default = '')
{
    return $_GET[$key] ?? $default;
}

function request_wants_json(): bool
{
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));

    return strpos($accept, 'application/json') !== false || $requestedWith === 'xmlhttprequest';
}

function csrf_token(): string
{
    if (!isset($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $token = $_POST['_token'] ?? '';

    if (!hash_equals((string) ($_SESSION['_csrf'] ?? ''), (string) $token)) {
        abort(419, 'Invalid CSRF token.');
    }
}

function error_title_for_status(int $statusCode): string
{
    if ($statusCode === 404) {
        return 'Page Not Found';
    }

    if ($statusCode === 403) {
        return 'Access Blocked';
    }

    if ($statusCode === 419) {
        return 'Session Expired';
    }

    return 'Something Needs Attention';
}

function error_module_target_for_message(string $message): ?array
{
    $normalized = strtolower($message);

    if (trim($normalized) === 'page not found.') {
        return null;
    }

    $targets = [
        'stocktake' => ['path' => '/stocktakes', 'label' => 'Back To Stocktakes', 'permission' => 'stocktakes.view', 'admin_only' => true],
        'handover' => ['path' => '/handovers', 'label' => 'Back To Handovers', 'permission' => 'handovers.view', 'admin_only' => false],
        'request' => ['path' => '/requests', 'label' => 'Back To Requests', 'permission' => 'requests.view', 'admin_only' => false],
        'purchase' => ['path' => '/purchases', 'label' => 'Back To Purchases', 'permission' => 'purchases.view', 'admin_only' => true],
        'supplier' => ['path' => '/suppliers', 'label' => 'Back To Suppliers', 'permission' => 'suppliers.view', 'admin_only' => true],
        'storage' => ['path' => '/storages', 'label' => 'Back To Storages', 'permission' => 'storages.view', 'admin_only' => true],
        'item' => ['path' => '/items', 'label' => 'Back To Items', 'permission' => 'items.view', 'admin_only' => true],
        'file' => ['path' => '/files', 'label' => 'Back To Files', 'permission' => 'files.view', 'admin_only' => true],
        'workflow document' => ['path' => '/files', 'label' => 'Back To Files', 'permission' => 'files.view', 'admin_only' => true],
        'user' => ['path' => '/users', 'label' => 'Back To Admins', 'permission' => 'users.view', 'admin_only' => true],
    ];

    foreach ($targets as $needle => $target) {
        if (strpos($normalized, $needle) !== false) {
            return $target;
        }
    }

    return null;
}

function error_target_allowed(array $target): bool
{
    if (!app_installed() || !Auth::check()) {
        return false;
    }

    if (!empty($target['admin_only']) && Auth::isStaff()) {
        return false;
    }

    $permission = (string) ($target['permission'] ?? '');

    return $permission === '' || Auth::hasPermission($permission);
}

function error_redirect_target(int $statusCode, string $message): ?array
{
    if ($statusCode !== 404 || request_method() !== 'GET' || request_wants_json()) {
        return null;
    }

    $target = error_module_target_for_message($message);

    if ($target === null) {
        return null;
    }

    if (!error_target_allowed($target)) {
        $target = ['path' => '/dashboard', 'label' => 'Back To Dashboard', 'permission' => 'dashboard.view', 'admin_only' => false];
    }

    if (!error_target_allowed($target)) {
        return null;
    }

    if (request_path() === (string) $target['path']) {
        return null;
    }

    return $target;
}

function error_page_actions(int $statusCode, string $message): array
{
    $actions = [];
    $target = error_module_target_for_message($message);

    if ($target !== null && error_target_allowed($target)) {
        $actions[] = [
            'href' => url((string) $target['path']),
            'label' => (string) $target['label'],
            'style' => 'primary',
        ];
    }

    if (app_installed() && Auth::check() && Auth::hasPermission('dashboard.view')) {
        $actions[] = [
            'href' => url('/dashboard'),
            'label' => 'Back To Dashboard',
            'style' => $actions === [] ? 'primary' : 'ghost',
        ];
    } elseif (app_installed()) {
        $actions[] = [
            'href' => url('/login'),
            'label' => 'Back To Login',
            'style' => 'primary',
        ];
    }

    return $actions;
}

function render_standalone_error_page(int $statusCode, string $message): never
{
    $title = error_title_for_status($statusCode);
    $primaryHref = app_installed() ? url('/login') : url('/setup');
    $primaryLabel = app_installed() ? 'Back To Login' : 'Run Setup';

    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . e($title) . '</title><style>body{font-family:ui-sans-serif,system-ui,sans-serif;background:#f7f3eb;color:#111;display:grid;place-items:center;min-height:100vh;margin:0;padding:24px}.card{width:min(720px,100%);background:#fff;padding:36px;border:1px solid #eadfce;border-radius:28px;box-shadow:0 24px 70px rgba(29,24,17,.10)}.code{display:inline-flex;padding:8px 12px;border-radius:999px;background:#fff3cf;color:#8a5a09;font-weight:800;letter-spacing:.08em;text-transform:uppercase}h1{font-size:clamp(34px,6vw,64px);line-height:.95;margin:18px 0 12px}p{color:#726b61;font-size:18px;line-height:1.6;margin:0 0 24px}.actions{display:flex;gap:12px;flex-wrap:wrap}a{display:inline-flex;align-items:center;justify-content:center;min-height:48px;padding:0 20px;border-radius:14px;text-decoration:none;font-weight:800}.primary{background:#e7b64a;color:#1f1608}.ghost{border:1px solid #eadfce;color:#5f4328}</style></head><body><section class="card"><span class="code">' . e((string) $statusCode) . '</span><h1>' . e($title) . '</h1><p>' . e($message) . '</p><div class="actions"><a class="primary" href="' . e($primaryHref) . '">' . e($primaryLabel) . '</a></div></section></body></html>';
    exit;
}

function abort(int $statusCode, string $message): never
{
    if (request_wants_json()) {
        json_response([
            'ok' => false,
            'message' => $message,
            'status' => $statusCode,
        ], $statusCode);
    }

    $redirectTarget = error_redirect_target($statusCode, $message);

    if ($redirectTarget !== null) {
        flash('warning', $message);
        redirect((string) $redirectTarget['path']);
    }

    http_response_code($statusCode);

    if (app_installed() && Auth::check()) {
        View::render('errors/show', [
            'title' => error_title_for_status($statusCode),
            'statusCode' => $statusCode,
            'message' => $message,
            'actions' => error_page_actions($statusCode, $message),
        ]);
        exit;
    }

    render_standalone_error_page($statusCode, $message);
    exit;
}

function json_response(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
