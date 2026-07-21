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

$expectedStylesheets = [
    'css/foundation.css',
    'css/themes/clean-material.css',
    'css/themes/clean-console.css',
    'css/themes/kona.css',
    'css/compatibility.css',
    'css/print.css',
    'css/themes/official.css',
    'css/assets.css',
    'css/mobile.css',
];

if ($stylesheets !== $expectedStylesheets) {
    fail_frontend_assets('Stylesheets must retain the documented cascade order.');
}

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

$foundationCss = file_get_contents($root . '/assets/css/foundation.css') ?: '';
$materialThemeCss = file_get_contents($root . '/assets/css/themes/clean-material.css') ?: '';
$consoleThemeCss = file_get_contents($root . '/assets/css/themes/clean-console.css') ?: '';
$konaThemeCss = file_get_contents($root . '/assets/css/themes/kona.css') ?: '';
$compatibilityCss = file_get_contents($root . '/assets/css/compatibility.css') ?: '';
$officialThemeCss = file_get_contents($root . '/assets/css/themes/official.css') ?: '';
$assetsCss = file_get_contents($root . '/assets/css/assets.css') ?: '';
$mobileCss = file_get_contents($root . '/assets/css/mobile.css') ?: '';

foreach ([
    [$materialThemeCss, 'Material-inspired'],
    [$consoleThemeCss, 'Light admin console'],
    [$konaThemeCss, 'KONA-style primary'],
    [$compatibilityCss, 'Focused UI cleanup'],
    [$officialThemeCss, 'Official KONA theme'],
] as [$css, $marker]) {
    if (strpos($css, $marker) === false) {
        fail_frontend_assets('CSS module is missing marker: ' . $marker);
    }
}

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

if (is_file($root . '/assets/app.css')) {
    fail_frontend_assets('The retired CSS monolith assets/app.css must not be restored.');
}

if (strpos($foundationCss, 'Sidebar scroll fix') !== false) {
    fail_frontend_assets('Mobile/sidebar CSS should live in assets/css/mobile.css, not foundation.css.');
}

if (strpos($foundationCss, '.assets-page') !== false) {
    fail_frontend_assets('Asset CSS should live in assets/css/assets.css, not foundation.css.');
}

echo '[frontend-assets] PASS' . PHP_EOL;
