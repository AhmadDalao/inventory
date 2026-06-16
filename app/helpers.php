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
    $relativePath = 'assets/' . ltrim($path, '/');
    $assetUrl = url('/' . $relativePath);
    $assetPath = base_path($relativePath);

    if (!is_file($assetPath)) {
        return $assetUrl;
    }

    return $assetUrl . '?v=' . filemtime($assetPath);
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

function abort(int $statusCode, string $message): never
{
    http_response_code($statusCode);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Error</title><style>body{font-family:ui-sans-serif,system-ui,sans-serif;background:#f6f3ed;color:#201f1b;display:grid;place-items:center;min-height:100vh;margin:0;padding:24px}.card{max-width:640px;background:#fff;padding:32px;border-radius:24px;box-shadow:0 20px 50px rgba(0,0,0,.08)}h1{margin:0 0 12px;font-size:28px}p{margin:0;font-size:16px;line-height:1.6;color:#605a52}</style></head><body><section class="card"><h1>Something broke</h1><p>' . e($message) . '</p></section></body></html>';
    exit;
}

function json_response(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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

function format_datetime_display(string $value): string
{
    return date('M j, Y g:i A', strtotime($value));
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

function slugify_filename(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?: 'item';

    return trim($value, '-') ?: 'item';
}

function item_unit_options(): array
{
    return [
        'pcs' => 'Pieces (pcs)',
        'box' => 'Box',
        'pack' => 'Pack',
        'carton' => 'Carton',
        'set' => 'Set',
        'roll' => 'Roll',
        'bottle' => 'Bottle',
        'kg' => 'Kilogram (kg)',
        'g' => 'Gram (g)',
        'liter' => 'Liter',
        'ml' => 'Milliliter (ml)',
        'meter' => 'Meter',
        'custom' => 'Custom',
    ];
}

function is_known_unit(string $unit): bool
{
    return array_key_exists($unit, item_unit_options()) && $unit !== 'custom';
}

function item_unit_form_state(?string $storedUnit): array
{
    $storedUnit = trim((string) $storedUnit);

    if ($storedUnit === '' || is_known_unit($storedUnit)) {
        return [
            'unit' => $storedUnit !== '' ? $storedUnit : 'pcs',
            'custom_unit' => '',
        ];
    }

    return [
        'unit' => 'custom',
        'custom_unit' => $storedUnit,
    ];
}

function resolve_item_unit(string $selectedUnit, string $customUnit): string
{
    $selectedUnit = trim($selectedUnit);
    $customUnit = trim($customUnit);

    if ($selectedUnit === 'custom') {
        return $customUnit;
    }

    if (is_known_unit($selectedUnit)) {
        return $selectedUnit;
    }

    return '';
}

function item_upload_directory(): string
{
    return base_path('uploads/items');
}

function ensure_directory_exists(string $path): void
{
    if (is_dir($path)) {
        return;
    }

    if (!mkdir($path, 0755, true) && !is_dir($path)) {
        throw new RuntimeException('Could not create upload directory.');
    }
}

function uploaded_file(string $key): ?array
{
    if (!isset($_FILES[$key]) || !is_array($_FILES[$key])) {
        return null;
    }

    if ((int) ($_FILES[$key]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    return $_FILES[$key];
}

function validate_item_image_upload(?array $file): ?string
{
    if ($file === null) {
        return null;
    }

    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($error !== UPLOAD_ERR_OK) {
        return 'Image upload failed. Try a JPG, PNG, or WebP under 5 MB.';
    }

    $size = (int) ($file['size'] ?? 0);

    if ($size <= 0 || $size > 5 * 1024 * 1024) {
        return 'Image must be smaller than 5 MB.';
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');

    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        return 'Uploaded image is invalid.';
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = $finfo ? (string) finfo_file($finfo, $tmpName) : '';

    if ($finfo) {
        finfo_close($finfo);
    }

    if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        return 'Image must be JPG, PNG, or WebP.';
    }

    return null;
}

function store_item_image(array $file, string $itemName): string
{
    $tmpName = (string) ($file['tmp_name'] ?? '');
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = $finfo ? (string) finfo_file($finfo, $tmpName) : '';

    if ($finfo) {
        finfo_close($finfo);
    }

    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    if (!isset($extensions[$mimeType])) {
        throw new RuntimeException('Unsupported image type.');
    }

    ensure_directory_exists(item_upload_directory());

    $filename = date('YmdHis') . '-' . slugify_filename($itemName) . '-' . substr(bin2hex(random_bytes(5)), 0, 10) . '.' . $extensions[$mimeType];
    $destination = item_upload_directory() . '/' . $filename;

    if (!move_uploaded_file($tmpName, $destination)) {
        throw new RuntimeException('Could not save the uploaded image.');
    }

    return $filename;
}

function delete_item_image(?string $imagePath): void
{
    $imagePath = trim((string) $imagePath);

    if ($imagePath === '') {
        return;
    }

    $fullPath = item_upload_directory() . '/' . basename($imagePath);

    if (is_file($fullPath)) {
        unlink($fullPath);
    }
}

function item_image_url(?string $imagePath): ?string
{
    $imagePath = trim((string) $imagePath);

    if ($imagePath === '') {
        return null;
    }

    $fullPath = item_upload_directory() . '/' . basename($imagePath);

    if (!is_file($fullPath)) {
        return null;
    }

    return url('/uploads/items/' . rawurlencode(basename($imagePath)));
}

function item_initial(?string $value): string
{
    $value = trim((string) $value);

    if ($value === '') {
        return 'I';
    }

    return strtoupper(substr($value, 0, 1));
}

function ui_icon(string $name): string
{
    $icons = [
        'dashboard' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 13h7V4H4zm9 7h7V11h-7zM4 20h7v-5H4zm9-9h7V4h-7z"/></svg>',
        'storages' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7.5 12 3l9 4.5-9 4.5z"/><path d="M3 12l9 4.5 9-4.5"/><path d="M3 16.5 12 21l9-4.5"/></svg>',
        'items' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7.5 12 3l8 4.5v9L12 21l-8-4.5z"/><path d="M12 12 20 7.5"/><path d="M12 12v9"/><path d="M12 12 4 7.5"/></svg>',
        'movements' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 7h11"/><path d="m14 4 4 3-4 3"/><path d="M17 17H6"/><path d="m10 14-4 3 4 3"/></svg>',
        'users' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 19a4 4 0 0 0-8 0"/><circle cx="12" cy="10" r="3"/><path d="M20 19a4 4 0 0 0-3-3.87"/><path d="M17 7.13A3 3 0 0 1 17 13"/></svg>',
        'plus' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14"/><path d="M5 12h14"/></svg>',
        'export' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/></svg>',
        'filter' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16"/><path d="M7 12h10"/><path d="M10 18h4"/></svg>',
        'search' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6"/><path d="m20 20-4.2-4.2"/></svg>',
        'edit' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m4 20 4.5-1 9-9a2.1 2.1 0 0 0-3-3l-9 9z"/><path d="m13 6 5 5"/></svg>',
        'back' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 12H5"/><path d="m12 5-7 7 7 7"/></svg>',
        'value' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2v20"/><path d="M17 6.5A4.5 4.5 0 0 0 12.5 4h-1A4.5 4.5 0 0 0 7 8.5c0 2 1.3 3.2 5 4 3.7.8 5 2 5 4A4.5 4.5 0 0 1 12.5 21h-1A4.5 4.5 0 0 1 7 18.5"/></svg>',
        'flash' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M13 2 4 14h6l-1 8 9-12h-6z"/></svg>',
    ];

    $markup = $icons[$name] ?? $icons['flash'];

    return '<span class="ui-icon ui-icon-' . e($name) . '">' . $markup . '</span>';
}

function stock_value($quantity, $costPerUnit): float
{
    return (float) $quantity * (float) $costPerUnit;
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
