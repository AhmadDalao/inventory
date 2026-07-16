<?php
declare(strict_types=1);

require_once __DIR__ . '/support/permissions.php';
require_once __DIR__ . '/support/http.php';
require_once __DIR__ . '/support/presentation.php';
require_once __DIR__ . '/support/branding.php';

if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

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

require_once __DIR__ . '/support/settings.php';
