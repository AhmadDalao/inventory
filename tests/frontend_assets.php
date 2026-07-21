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

foreach ($stylesheets as $asset) {
    $path = $root . '/assets/' . ltrim($asset, '/');

    if (!is_file($path)) {
        fail_frontend_assets('Registered asset is missing: assets/' . $asset);
    }

    if (filesize($path) === 0) {
        fail_frontend_assets('Registered asset is empty: assets/' . $asset);
    }
}

foreach ($scripts as $script) {
    if (!is_array($script)) {
        fail_frontend_assets('Frontend scripts must use descriptor arrays.');
    }

    $asset = (string) ($script['path'] ?? '');
    $type = (string) ($script['type'] ?? '');

    if ($asset === '' || $type !== 'module') {
        fail_frontend_assets('Frontend scripts must define a path and use native module type.');
    }

    $path = $root . '/assets/' . ltrim($asset, '/');

    if (!is_file($path)) {
        fail_frontend_assets('Registered script is missing: assets/' . $asset);
    }

    if (filesize($path) === 0) {
        fail_frontend_assets('Registered script is empty: assets/' . $asset);
    }
}

$layout = file_get_contents($root . '/views/layout.php') ?: '';

if (strpos($layout, 'frontend_stylesheets()') === false || strpos($layout, 'frontend_scripts()') === false) {
    fail_frontend_assets('Layout must load frontend assets through the registry.');
}

if (strpos($layout, "['path']") === false || strpos($layout, "['type']") === false) {
    fail_frontend_assets('Layout must render native module script descriptors.');
}

$htaccess = file_get_contents($root . '/.htaccess') ?: '';

if (strpos($htaccess, 'max-age=0, must-revalidate') === false) {
    fail_frontend_assets('JavaScript modules must be revalidated after deployment.');
}

$baseCss = file_get_contents($root . '/assets/app.css') ?: '';
$assetsCss = file_get_contents($root . '/assets/css/assets.css') ?: '';
$mobileCss = file_get_contents($root . '/assets/css/mobile.css') ?: '';

foreach ([
    'Asset module',
    '.assets-page',
    '.asset-category-layout',
] as $marker) {
    if (strpos($assetsCss, $marker) === false) {
        fail_frontend_assets('Assets CSS module is missing marker: ' . $marker);
    }
}

foreach ([
    'Sidebar scroll fix',
    'Mobile hardening',
    'Mobile table policy',
    'Searchable select dropdowns must overlay',
] as $marker) {
    if (strpos($mobileCss, $marker) === false) {
        fail_frontend_assets('Mobile CSS module is missing marker: ' . $marker);
    }
}

if (strpos($mobileCss, '.app-shell .data-table-mobile tr[hidden]') === false) {
    fail_frontend_assets('Mobile table pagination must keep hidden rows out of layout.');
}

if (strpos($baseCss, 'Sidebar scroll fix') !== false) {
    fail_frontend_assets('Mobile/sidebar CSS should live in assets/css/mobile.css, not the base app.css.');
}

if (strpos($baseCss, '.assets-page') !== false) {
    fail_frontend_assets('Asset CSS should live in assets/css/assets.css, not the base app.css.');
}

echo '[frontend-assets] PASS' . PHP_EOL;
