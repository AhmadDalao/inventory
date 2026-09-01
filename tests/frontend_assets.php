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
    'css/shell.css',
    'css/components.css',
    'css/tables.css',
    'css/workflows.css',
    'css/domains/inventory.css',
    'css/domains/scan.css',
    'css/domains/handovers.css',
    'css/domains/wristbands.css',
    'css/domains/purchases-ocr.css',
    'css/domains/reports.css',
    'css/domains/admin.css',
    'css/domains/settings.css',
    'css/domains/documentation.css',
    'css/domains/assets.css',
    'css/themes/classic.css',
    'css/themes/kona.css',
    'css/themes/official.css',
    'css/print.css',
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

    $stylesheetSource = file_get_contents($path) ?: '';

    if (!preg_match_all('/url\(\s*[\'\"]?([^\'\")]+)[\'\"]?\s*\)/', $stylesheetSource, $urlMatches)) {
        continue;
    }

    foreach ($urlMatches[1] as $assetReference) {
        $assetReference = trim((string) $assetReference);

        if ($assetReference === ''
            || preg_match('/^(?:data:|https?:\/\/|#|var\()/i', $assetReference) === 1
        ) {
            continue;
        }

        $assetReference = preg_replace('/[?#].*$/', '', $assetReference) ?: $assetReference;
        $referencedPath = realpath(dirname($path) . '/' . $assetReference);

        if ($referencedPath === false || !is_file($referencedPath)) {
            fail_frontend_assets('Stylesheet reference is missing: ' . $assetReference . ' from assets/' . $asset);
        }
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

    $pendingModules = [$path];
    $checkedModules = [];

    while ($pendingModules !== []) {
        $modulePath = array_pop($pendingModules);
        $realModulePath = realpath($modulePath);

        if ($realModulePath === false || isset($checkedModules[$realModulePath])) {
            continue;
        }

        $checkedModules[$realModulePath] = true;
        $moduleSource = file_get_contents($realModulePath) ?: '';

        if (!preg_match_all('/(?:import|export)\s+(?:[^;]*?\s+from\s+)?[\'"]([^\'"]+)[\'"]/', $moduleSource, $matches)) {
            continue;
        }

        foreach ($matches[1] as $moduleImport) {
            if (substr($moduleImport, 0, 1) !== '.') {
                continue;
            }

            $importPath = dirname($realModulePath) . '/' . $moduleImport;
            $resolvedImport = realpath($importPath);

            if ($resolvedImport === false || !is_file($resolvedImport)) {
                fail_frontend_assets('JavaScript module import is missing: ' . $moduleImport . ' from ' . $realModulePath);
            }

            $pendingModules[] = $resolvedImport;
        }
    }
}

$layout = file_get_contents($root . '/views/layout.php') ?: '';

if (strpos($layout, 'frontend_stylesheets()') === false || strpos($layout, 'frontend_scripts()') === false) {
    fail_frontend_assets('Layout must load frontend assets through the registry.');
}

if (strpos($layout, "['path']") === false || strpos($layout, "['type']") === false) {
    fail_frontend_assets('Layout must render native module script descriptors.');
}

$entryScript = file_get_contents($root . '/assets/app.js') ?: '';

if (substr_count($entryScript, 'registerInitializer(') < 10) {
    fail_frontend_assets('The JavaScript entry point must register the modular initializers.');
}

if (substr_count($entryScript, "./js/") < 10) {
    fail_frontend_assets('The JavaScript entry point must import the frontend modules.');
}

if (substr_count($entryScript, "\n") > 100) {
    fail_frontend_assets('assets/app.js must remain a small bootstrap entry point.');
}

$runtimeScript = file_get_contents($root . '/assets/js/core/runtime.js') ?: '';
$filterScript = file_get_contents($root . '/assets/js/ui/filters.js') ?: '';
$realtimeScript = file_get_contents($root . '/assets/js/ui/realtime.js') ?: '';
$webRealtime = file_get_contents($root . '/app/modules/web_realtime.php') ?: '';

if (strpos($runtimeScript, 'inventory:content-replaced') === false || strpos($filterScript, 'inventory:content-replaced') === false) {
    fail_frontend_assets('AJAX replacements must dispatch inventory:content-replaced with the replaced root.');
}

foreach ([
    'pollIntervalMs = 5000',
    "document.visibilityState !== 'visible'",
    'inventory:refresh',
    'replaceMainContentFromUrl',
] as $marker) {
    if (strpos($realtimeScript, $marker) === false) {
        fail_frontend_assets('Visible-page realtime refresh is missing marker: ' . $marker);
    }
}

if (strpos($layout, 'data-live-sync-url') === false) {
    fail_frontend_assets('The authenticated layout must expose the live sync endpoint.');
}

if (strpos($webRealtime, 'inventory_latest_event_cursor()') === false) {
    fail_frontend_assets('The web realtime endpoint must read the inventory event cursor.');
}

$htaccess = file_get_contents($root . '/.htaccess') ?: '';

if (strpos($htaccess, 'max-age=0, must-revalidate') === false) {
    fail_frontend_assets('JavaScript modules must be revalidated after deployment.');
}

$foundationCss = file_get_contents($root . '/assets/css/foundation.css') ?: '';
$shellCss = file_get_contents($root . '/assets/css/shell.css') ?: '';
$componentsCss = file_get_contents($root . '/assets/css/components.css') ?: '';
$tablesCss = file_get_contents($root . '/assets/css/tables.css') ?: '';
$workflowsCss = file_get_contents($root . '/assets/css/workflows.css') ?: '';
$konaThemeCss = file_get_contents($root . '/assets/css/themes/kona.css') ?: '';
$classicThemeCss = file_get_contents($root . '/assets/css/themes/classic.css') ?: '';
$officialThemeCss = file_get_contents($root . '/assets/css/themes/official.css') ?: '';
$assetsCss = file_get_contents($root . '/assets/css/domains/assets.css') ?: '';
$adminCss = file_get_contents($root . '/assets/css/domains/admin.css') ?: '';
$mobileCss = file_get_contents($root . '/assets/css/mobile.css') ?: '';

foreach ([
    [$foundationCss, 'Foundation:'],
    [$shellCss, 'Shell:'],
    [$componentsCss, 'Components:'],
    [$tablesCss, 'Tables:'],
    [$workflowsCss, 'Workflows:'],
    [$classicThemeCss, 'Classic Warm theme:'],
    [$konaThemeCss, 'KONA theme: consolidated'],
    [$officialThemeCss, 'Official KONA theme'],
] as [$css, $marker]) {
    if (strpos($css, $marker) === false) {
        fail_frontend_assets('CSS module is missing marker: ' . $marker);
    }
}

if (strpos($componentsCss, '.notification-menu:not([open]) > .notification-panel') === false
    || strpos($componentsCss, '.topbar-user-menu:not([open]) > .topbar-user-panel') === false
) {
    fail_frontend_assets('Closed topbar popovers must not remain rendered off-canvas.');
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

$teamHierarchyScript = file_get_contents($root . '/assets/js/domains/team-hierarchy.js') ?: '';
foreach (['data-team-manager-form', 'dragstart', 'data-team-root-drop', 'data-team-bulk-form', 'data-team-search', 'data-team-view-button'] as $marker) {
    if (strpos($teamHierarchyScript, $marker) === false) {
        fail_frontend_assets('Team hierarchy JavaScript is missing marker: ' . $marker);
    }
}
if (strpos($entryScript, 'initTeamHierarchy') === false || strpos($adminCss, 'Team hierarchy:') === false) {
    fail_frontend_assets('Team hierarchy assets are not registered in the modular frontend.');
}

foreach ([
    'Sidebar scroll fix',
    'Mobile hardening',
    'Mobile table policy',
    'Searchable select dropdowns must overlay',
    'Responsive viewport matrix',
] as $marker) {
    if (strpos($mobileCss, $marker) === false) {
        fail_frontend_assets('Mobile CSS module is missing marker: ' . $marker);
    }
}

$responsiveSmoke = file_get_contents($root . '/tests/responsive_ui_smoke.js') ?: '';

foreach ([
    "name: 'compact-phone', width: 390",
    "name: 'large-phone', width: 430",
    "name: 'tablet-portrait', width: 768",
    "name: 'tablet-landscape', width: 1024",
    "name: 'desktop', width: 1440",
    "name: 'wide-desktop', width: 1920",
    'globalOverflow',
    'clipped controls',
    'console errors',
] as $marker) {
    if (strpos($responsiveSmoke, $marker) === false) {
        fail_frontend_assets('Responsive UI smoke test is missing coverage marker: ' . $marker);
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
    fail_frontend_assets('Asset CSS should live in assets/css/domains/assets.css, not foundation.css.');
}

if (substr_count($foundationCss, "\n") > 500) {
    fail_frontend_assets('Foundation CSS must remain limited to tokens, reset, and typography.');
}

foreach ([
    'assets/css/compatibility.css',
    'assets/css/assets.css',
    'assets/css/themes/clean-material.css',
    'assets/css/themes/clean-console.css',
] as $retiredAsset) {
    if (is_file($root . '/' . $retiredAsset)) {
        fail_frontend_assets('Retired CSS pass must not be restored: ' . $retiredAsset);
    }
}

echo '[frontend-assets] PASS' . PHP_EOL;
