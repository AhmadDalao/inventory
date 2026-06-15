<?php
declare(strict_types=1);

$basePath = '';
$appUrl = trim((string) Env::get('APP_URL', ''));

if ($appUrl !== '') {
    $parsedPath = parse_url($appUrl, PHP_URL_PATH);
    $basePath = $parsedPath && $parsedPath !== '/' ? rtrim($parsedPath, '/') : '';
} else {
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $basePath = $scriptDir === '.' || $scriptDir === '/' ? '' : rtrim($scriptDir, '/');
}

return [
    'app' => [
        'name' => Env::get('APP_NAME', 'Inventory HQ'),
        'env' => Env::get('APP_ENV', 'production'),
        'debug' => filter_var(Env::get('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN),
        'timezone' => Env::get('APP_TIMEZONE', 'UTC'),
        'base_path' => $basePath,
    ],
    'db' => [
        'host' => Env::get('DB_HOST', '127.0.0.1'),
        'port' => Env::get('DB_PORT', '3306'),
        'database' => Env::get('DB_DATABASE', ''),
        'username' => Env::get('DB_USERNAME', ''),
        'password' => Env::get('DB_PASSWORD', ''),
        'charset' => Env::get('DB_CHARSET', 'utf8mb4'),
    ],
];
