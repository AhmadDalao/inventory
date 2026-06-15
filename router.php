<?php
declare(strict_types=1);

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$filePath = __DIR__ . $uri;

if ($uri !== '/' && is_file($filePath)) {
    return false;
}

require __DIR__ . '/index.php';
