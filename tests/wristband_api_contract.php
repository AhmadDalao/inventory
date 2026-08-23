<?php
declare(strict_types=1);

$root = dirname(__DIR__);

function fail_wristband_contract(string $message): never
{
    fwrite(STDERR, '[wristband-api-contract] FAIL: ' . $message . PHP_EOL);
    exit(1);
}

function wristband_contract_source(string $relativePath): string
{
    global $root;
    $path = $root . '/' . $relativePath;

    if (!is_file($path)) {
        fail_wristband_contract('Missing file: ' . $relativePath);
    }

    $source = file_get_contents($path);

    if ($source === false) {
        fail_wristband_contract('Could not read file: ' . $relativePath);
    }

    return $source;
}

function wristband_contract_has_all(string $source, array $markers, string $area): void
{
    foreach ($markers as $marker) {
        if (strpos($source, $marker) === false) {
            fail_wristband_contract($area . ' is missing marker: ' . $marker);
        }
    }
}

$manifest = require $root . '/app/module_manifest.php';
$modules = $manifest['wristbands'] ?? [];
$requiredModules = [
    'wristband_support',
    'wristband_registry',
    'wristband_sessions',
    'wristband_api',
    'wristband_pages',
    'wristband_actions',
];

if ($modules !== $requiredModules) {
    fail_wristband_contract('The wristband module manifest is incomplete or out of order.');
}

foreach ($requiredModules as $module) {
    if (!is_file($root . '/app/modules/' . $module . '.php')) {
        fail_wristband_contract('Missing module: app/modules/' . $module . '.php');
    }
}

$index = wristband_contract_source('index.php');
$support = wristband_contract_source('app/modules/wristband_support.php');
$registry = wristband_contract_source('app/modules/wristband_registry.php');
$sessions = wristband_contract_source('app/modules/wristband_sessions.php');
$api = wristband_contract_source('app/modules/wristband_api.php');
$pages = wristband_contract_source('app/modules/wristband_pages.php');
$actions = wristband_contract_source('app/modules/wristband_actions.php');
$schema = wristband_contract_source('app/maintenance/MaintenanceWristbandSchemas.php');
$items = wristband_contract_source('app/modules/items.php');
$handoverCreate = wristband_contract_source('app/modules/handover_create.php');
$handoverCloseout = wristband_contract_source('app/modules/handover_closeout.php');
$handoverCancellations = wristband_contract_source('app/modules/handover_cancellations.php');
$permissionCatalog = wristband_contract_source('app/support/permission_catalog.php');
$permissionPresets = wristband_contract_source('app/support/permission_presets.php');
$frontendRegistry = wristband_contract_source('app/modules/frontend_assets.php');
$appJs = wristband_contract_source('assets/app.js');
$wristbandJs = wristband_contract_source('assets/js/domains/wristbands.js');
$layout = wristband_contract_source('views/layout.php');
$handoverForm = wristband_contract_source('views/handovers/form.php');
$handoverShow = wristband_contract_source('views/handovers/show.php');
$openApi = wristband_contract_source('docs/openapi/wristband-api-v1.yaml');

foreach ([$registry, $sessions, $api, $pages] as $source) {
    foreach (['item.deleted_at', 'storage.deleted_at'] as $unsupportedArchiveColumn) {
        if (strpos($source, $unsupportedArchiveColumn) !== false) {
            fail_wristband_contract('Wristband modules must use the item/storage is_active archive model: ' . $unsupportedArchiveColumn);
        }
    }
}

foreach ([':user_id, :user_id', 'last_rotated_by = :user_id, updated_by = :user_id'] as $reusedPlaceholder) {
    if (strpos($actions, $reusedPlaceholder) !== false) {
        fail_wristband_contract('Wristband actions must not reuse native PDO named placeholders: ' . $reusedPlaceholder);
    }
}

$routes = [
    '/wristbands' => 'handle_wristband_codes_page',
    '/wristbands/imports' => 'handle_wristband_imports_page',
    '/wristbands/sessions' => 'handle_wristband_sessions_page',
    '/wristbands/exceptions' => 'handle_wristband_exceptions_page',
    '/wristbands/integrations' => 'handle_wristband_integrations_page',
    '/api/v1/integrations/kona/wristband-checkins' => 'handle_wristband_checkin_api',
];

foreach ($routes as $route => $handler) {
    if (strpos($index, "'" . $route . "'") === false) {
        fail_wristband_contract('Missing route: ' . $route);
    }

    if (strpos($pages . $actions . $api, 'function ' . $handler . '(') === false) {
        fail_wristband_contract('Missing handler: ' . $handler);
    }
}

wristband_contract_has_all($schema, [
    'external_qr_tracking_enabled',
    'wristband_tracking_mode',
    'wristband_integrations',
    'wristband_imports',
    'wristband_sessions',
    'wristband_session_periods',
    'wristband_codes',
    'wristband_events',
    'uniq_wristband_integration_storage',
    'uniq_wristband_session_handover',
    'uniq_wristband_code_hash',
    'uniq_wristband_external_event',
    'uniq_wristband_payload',
    '"available", "used", "void"',
    '"active", "paused", "manual_only", "closed"',
], 'Wristband schema');

wristband_contract_has_all($support, [
    'wristband_normalize_code',
    'wristband_code_hash',
    "hash('sha256'",
    "hash('sha256', \$plain)",
    'hash_equals',
    'wristband_ip_matches',
    'wristband_integration_allows_ip',
    "'data'",
    "'meta'",
    "'error'",
    'wristband_session_evidence',
    'wristband_review_snapshot',
    'wristband_store_review_snapshot',
], 'Wristband support');

wristband_contract_has_all($registry, [
    'wristband_import_csv_rows',
    'wristband_import_xlsx_rows',
    'selected_item',
    'code_sku',
    'wristband_insert_code_batch',
    'array_chunk($prepared, 500)',
    'INSERT IGNORE INTO wristband_codes',
    'code.code_hash = :search_hash',
    'measurement_dimension = "count"',
    '250000',
    '64 * 1024 * 1024',
    'LIBXML_NONET | LIBXML_COMPACT',
], 'Wristband import registry');

wristband_contract_has_all($sessions, [
    'wristband_start_session_for_handover',
    'wristband_pause_session',
    'wristband_resume_session',
    'wristband_switch_session_to_manual',
    'wristband_accept_paused_event',
    'wristband_discard_event',
    'wristband_reverse_event',
    'wristband_close_session_for_handover',
    'FOR UPDATE',
    'measurement_dimension',
], 'Wristband session lifecycle');

wristband_contract_has_all($api, [
    'wristband_api_require_https',
    'wristband_request_api_key',
    'wristband_integration_by_api_key',
    'wristband_integration_allows_ip',
    'wristband_events',
    'idempotency_conflict',
    'integration_paused',
    'external_event_id',
    'payload_hash',
    'FOR UPDATE',
    'measurement_dimension',
    'external_qr_tracking_enabled',
    'wristband_api_insert_paused_event',
    'wristband_api_paused_response',
    'SELECT setting_value FROM app_settings',
    'integrationStillEnabled',
    'sessionStillActive',
    "['idempotent_replay' => true]",
], 'Wristband check-in API');

foreach (['accepted', 'duplicate', 'paused', 'unknown_code', 'item_not_eligible', 'wrong_handover', 'inactive_session', 'reversed', 'discarded'] as $status) {
    if (strpos($api . $sessions . $schema, $status) === false) {
        fail_wristband_contract('Wristband lifecycle is missing event status: ' . $status);
    }
}

$stockMutationTokens = [
    'apply_inventory_movement(',
    'sync_item_inventory_snapshot(',
    'INSERT INTO inventory_movements',
    'UPDATE item_storage_balances',
    'DELETE FROM item_storage_balances',
];

foreach ([$registry, $sessions, $api] as $source) {
    foreach ($stockMutationTokens as $token) {
        if (strpos($source, $token) !== false) {
            fail_wristband_contract('API evidence modules must never mutate stock directly: ' . $token);
        }
    }
}

wristband_contract_has_all($items, [
    'external_qr_tracking_enabled',
    "measurement_dimension'] !== 'count'",
], 'Tracked item validation');

wristband_contract_has_all($handoverCreate, [
    'wristband_tracking_mode',
    'wristband_start_session_for_handover',
], 'Handover API Audit creation');

wristband_contract_has_all($handoverCloseout, [
    'wristband_review_snapshot',
    'wristband_store_review_snapshot',
], 'Handover API evidence review');

wristband_contract_has_all($handoverCancellations, [
    'wristband_close_session_for_handover',
], 'Handover session cancellation');

foreach ([
    'wristbands.view',
    'wristbands.import',
    'wristbands.manage',
    'wristbands.sessions',
    'wristbands.exceptions',
    'wristbands.integrations',
    'wristbands.reverse',
    'wristbands.evidence',
] as $permission) {
    if (strpos($permissionCatalog, "'" . $permission . "'") === false) {
        fail_wristband_contract('Missing permission: ' . $permission);
    }
}

if (strpos($permissionPresets, 'wristbands.view') === false || strpos($permissionPresets, 'wristbands.evidence') === false) {
    fail_wristband_contract('Default position/access presets do not include wristband permissions.');
}

foreach ([
    'views/wristbands/index.php',
    'views/wristbands/imports.php',
    'views/wristbands/sessions.php',
    'views/wristbands/exceptions.php',
    'views/wristbands/integrations.php',
    'assets/css/domains/wristbands.css',
    'assets/js/domains/wristbands.js',
] as $path) {
    if (!is_file($root . '/' . $path)) {
        fail_wristband_contract('Missing UI asset: ' . $path);
    }
}

wristband_contract_has_all($frontendRegistry, ['domains/wristbands.css'], 'Frontend stylesheet registry');
wristband_contract_has_all($appJs, ["./js/domains/wristbands.js", "registerInitializer('wristbands'"], 'Frontend JavaScript registry');
wristband_contract_has_all($wristbandJs, ['data-wristband-mapping-mode', 'data-copy-wristband-key'], 'Wristband JavaScript');
wristband_contract_has_all($layout, ['/wristbands', 'Wristband Codes'], 'Sidebar navigation');
wristband_contract_has_all($handoverForm, ['Manual Only', 'API Audit'], 'Handover wristband mode selector');
wristband_contract_has_all($handoverShow, ['Pause API Check', 'Switch To Manual Only'], 'Handover wristband controls');

wristband_contract_has_all($openApi, [
    'openapi: 3.1.0',
    '/api/v1/integrations/kona/wristband-checkins:',
    'operationId: recordKonaWristbandCheckin',
    'X-KONA-API-Key',
    'BearerAuth',
    'WristbandCheckinRequest',
    'external_event_id',
    'integration_paused',
    'It never creates an inventory movement',
    "'202':",
    "'409':",
    "'429':",
], 'Wristband OpenAPI contract');

if (!is_file($root . '/tests/wristband_workflow.php')) {
    fail_wristband_contract('Missing rollback-only workflow test: tests/wristband_workflow.php');
}

fwrite(STDOUT, '[wristband-api-contract] PASS' . PHP_EOL);
