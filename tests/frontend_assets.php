<?php
declare(strict_types=1);

$root = dirname(__DIR__);

function fail_frontend_assets(string $message): never
{
    fwrite(STDERR, '[frontend-assets] FAIL: ' . $message . PHP_EOL);
    exit(1);
}

require_once $root . '/app/modules/frontend_assets.php';

$stylesheets = frontend_stylesheets();
$scripts = frontend_scripts();

if ($stylesheets === [] || $scripts === []) {
    fail_frontend_assets('Frontend stylesheet and script registries must not be empty.');
}

foreach (array_merge($stylesheets, $scripts) as $asset) {
    $path = $root . '/assets/' . ltrim($asset, '/');

    if (!is_file($path)) {
        fail_frontend_assets('Registered asset is missing: assets/' . $asset);
    }

    if (filesize($path) === 0) {
        fail_frontend_assets('Registered asset is empty: assets/' . $asset);
    }
}

$layout = file_get_contents($root . '/views/layout.php') ?: '';

if (!str_contains($layout, 'frontend_stylesheets()') || !str_contains($layout, 'frontend_scripts()')) {
    fail_frontend_assets('Layout must load frontend assets through the registry.');
}

$baseCss = file_get_contents($root . '/assets/app.css') ?: '';
$mobileCss = file_get_contents($root . '/assets/css/mobile.css') ?: '';

foreach ([
    'Sidebar scroll fix',
    'Mobile table policy',
    'Searchable select dropdowns must overlay',
] as $marker) {
    if (!str_contains($mobileCss, $marker)) {
        fail_frontend_assets('Mobile CSS module is missing marker: ' . $marker);
    }
}

if (str_contains($baseCss, 'Sidebar scroll fix')) {
    fail_frontend_assets('Mobile/sidebar CSS should live in assets/css/mobile.css, not the base app.css.');
}

echo '[frontend-assets] PASS' . PHP_EOL;
