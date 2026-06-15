<?php
declare(strict_types=1);

function base_path(string $path = ''): string
{
    $base = dirname(__DIR__);

    return $path === '' ? $base : $base . '/' . ltrim($path, '/');
}

function starts_with(string $haystack, string $needle): bool
{
    if ($needle === '') {
        return true;
    }

    return strpos($haystack, $needle) === 0;
}

function app_config(?string $key = null, $default = null)
{
    global $appConfig;

    if ($key === null) {
        return $appConfig;
    }

    $value = $appConfig;

    foreach (explode('.', $key) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }

        $value = $value[$segment];
    }

    return $value;
}

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
    return url('/assets/' . ltrim($path, '/'));
}

function redirect(string $path = '/'): never
{
    header('Location: ' . url($path));
    exit;
}

function redirect_to_referer(string $fallback = '/'): never
{
    $referer = $_SERVER['HTTP_REFERER'] ?? '';

    if ($referer !== '') {
        header('Location: ' . $referer);
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

function abort(int $statusCode, string $message): never
{
    http_response_code($statusCode);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Error</title><style>body{font-family:ui-sans-serif,system-ui,sans-serif;background:#f6f3ed;color:#201f1b;display:grid;place-items:center;min-height:100vh;margin:0;padding:24px}.card{max-width:640px;background:#fff;padding:32px;border-radius:24px;box-shadow:0 20px 50px rgba(0,0,0,.08)}h1{margin:0 0 12px;font-size:28px}p{margin:0;font-size:16px;line-height:1.6;color:#605a52}</style></head><body><section class="card"><h1>Something broke</h1><p>' . e($message) . '</p></section></body></html>';
    exit;
}

function format_quantity($value): string
{
    $number = (float) ($value ?? 0);
    $formatted = number_format($number, 2, '.', '');

    return rtrim(rtrim($formatted, '0'), '.') ?: '0';
}

function format_money($value): string
{
    return '$' . number_format((float) ($value ?? 0), 2);
}

function quantity_value($value): float
{
    $normalized = str_replace(',', '', trim((string) $value));

    return $normalized === '' ? 0.0 : (float) $normalized;
}

function is_numeric_value($value): bool
{
    $normalized = str_replace(',', '', trim((string) $value));

    if ($normalized === '') {
        return false;
    }

    return is_numeric($normalized);
}

function active_route(string $path, bool $startsWith = false): string
{
    $current = request_path();

    if ($startsWith) {
        return starts_with($current, $path) ? 'is-active' : '';
    }

    return $current === $path ? 'is-active' : '';
}

function status_badge_class(string $type): string
{
    switch ($type) {
        case 'success':
            return 'badge-success';
        case 'warning':
            return 'badge-warning';
        case 'danger':
            return 'badge-danger';
        case 'info':
            return 'badge-info';
        default:
            return 'badge-muted';
    }
}

function selected($value, $current): string
{
    return (string) $value === (string) $current ? 'selected' : '';
}

function checked(bool $value): string
{
    return $value ? 'checked' : '';
}

function app_installed(): bool
{
    return Installer::status()['installed'];
}

function truncate_text(?string $value, int $length = 100): string
{
    $value = trim((string) $value);

    if (mb_strlen($value) <= $length) {
        return $value;
    }

    return rtrim(mb_substr($value, 0, $length - 1)) . '...';
}
