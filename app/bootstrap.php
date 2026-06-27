<?php
declare(strict_types=1);

require __DIR__ . '/Env.php';
require __DIR__ . '/helpers.php';

$composerAutoload = base_path('vendor/autoload.php');

if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

Env::load(base_path('.env'));

$appConfig = require base_path('config/app.php');

date_default_timezone_set((string) app_config('app.timezone', 'UTC'));

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('inventory_session');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
        'path' => url('/'),
    ]);
    session_start();
}

require __DIR__ . '/Database.php';
require __DIR__ . '/Auth.php';
require __DIR__ . '/Installer.php';
require __DIR__ . '/Maintenance.php';
require __DIR__ . '/View.php';
require __DIR__ . '/Router.php';

Maintenance::boot();
