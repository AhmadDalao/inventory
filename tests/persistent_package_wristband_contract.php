<?php
declare(strict_types=1);

$root = dirname(__DIR__);

function release_contract_fail(string $message): never
{
    fwrite(STDERR, '[persistent-package-wristband] FAIL: ' . $message . PHP_EOL);
    exit(1);
}

function release_contract_read(string $relativePath): string
{
    global $root;
    $path = $root . '/' . $relativePath;
    $contents = is_file($path) ? file_get_contents($path) : false;

    if ($contents === false) {
        release_contract_fail('Missing or unreadable file: ' . $relativePath);
    }

    return $contents;
}

function release_contract_has_all(string $source, array $markers, string $label): void
{
    foreach ($markers as $marker) {
        if (!str_contains($source, $marker)) {
            release_contract_fail($label . ' is missing marker: ' . $marker);
        }
    }
}

$auth = release_contract_read('app/Auth.php');
$bootstrap = release_contract_read('app/bootstrap.php');
$authActions = release_contract_read('app/modules/auth_actions.php');
$userActions = release_contract_read('app/modules/user_actions.php');
$loginView = release_contract_read('views/auth/login.php');
$konaTheme = release_contract_read('assets/css/themes/kona.css');
$components = release_contract_read('assets/css/components.css');
$usersView = release_contract_read('views/users/index.php');
$routes = release_contract_read('index.php');
$platformSchema = release_contract_read('app/maintenance/MaintenancePlatformSchemas.php');

release_contract_has_all($auth, [
    "private const REMEMBER_DAYS = 30",
    'bin2hex(random_bytes(12))',
    'bin2hex(random_bytes(32))',
    "hash('sha256', \$validator)",
    'hash_equals',
    'session_regenerate_id(true)',
    "'secure' =>",
    "'httponly' => true",
    "'samesite' => 'Lax'",
    'revokePersistentSessionsForUser',
    'writeRememberCookie($parts[0] . \'.\' . $newValidator',
    'passwordAuthenticatedThisRequest',
    'if (!self::$passwordAuthenticatedThisRequest)',
], 'Persistent login security');
release_contract_has_all($platformSchema, [
    'CREATE TABLE IF NOT EXISTS persistent_login_tokens',
    'UNIQUE KEY uniq_persistent_login_selector',
    'validator_hash CHAR(64)',
], 'Persistent login schema');
release_contract_has_all($bootstrap, ['Auth::restoreFromPersistentCookie()'], 'Persistent login restoration');
release_contract_has_all($authActions, [
    'if ($email === \'\' || $password === \'\')',
    "record_login_attempt(\$email, false, 'missing_credentials')",
    "input('remember_me', '0') === '1'",
    'Auth::rememberCurrentUser()',
    'Auth::forgetPersistentLogin()',
    'Auth::revokePersistentSessionsForUser',
], 'Login and password-reset actions');
release_contract_has_all($loginView, [
    'class="field-label">Password',
    'type="password" name="password"',
    'required data-password-input',
    'name="remember_me"',
    'type="hidden" name="remember_me" value="0"',
    "old('remember_me', '1')",
    'Your password is required now.',
    'data-password-toggle',
], 'Login interface');
release_contract_has_all($components, [
    '.auth-card-login .password-field {',
    '.auth-card-login .password-input-wrap',
    'display: grid !important;',
    'display: block !important;',
    'visibility: visible !important;',
    'height: auto !important;',
], 'Login password field layout');
release_contract_has_all($konaTheme, [
    '.theme-clean .auth-card-login .field > .field-label',
], 'Login theme visibility');
if (str_contains($konaTheme, '.theme-clean .auth-card-login .field span {')) {
    release_contract_fail('Login theme must not hide every nested field span.');
}
release_contract_has_all($userActions, [
    'handle_users_revoke_persistent_sessions_submit',
    'Auth::revokePersistentSessionsForUser',
], 'Administrative session revocation');
release_contract_has_all($usersView, ['Revoke Saved Logins', '/revoke-sessions'], 'Administrative session UI');
release_contract_has_all($routes, ["'/users/{id}/revoke-sessions'"], 'Administrative session route');

$packages = release_contract_read('app/modules/item_packages.php');
$measurementSchema = release_contract_read('app/maintenance/MaintenanceMeasurementSchemas.php');
$itemView = release_contract_read('views/items/show.php');
$packageJs = release_contract_read('assets/js/domains/package-presets.js');
$mobileInventory = release_contract_read('app/modules/mobile_api_inventory.php');
$flutterModels = release_contract_read('mobile/lib/core/models/inventory_models.dart');

foreach ([
    'individual' => 'Individual',
    'pack' => 'Pack',
    'box' => 'Box',
    'bag' => 'Bag',
    'bottle' => 'Bottle',
    'container' => 'Container',
    'roll' => 'Roll',
    'bundle' => 'Bundle',
    'carton' => 'Carton',
    'other' => 'Other',
] as $code => $label) {
    release_contract_has_all($packages, ["'{$code}' => '{$label}'"], 'Package type catalog');
}
release_contract_has_all($measurementSchema, [
    'ADD COLUMN package_type VARCHAR(40) NULL',
    'idx_item_package_type',
], 'Package type migration');
release_contract_has_all($itemView . $packageJs, [
    'data-package-type',
    'data-custom-package-label',
    'One ',
    ' contains',
], 'Package preset interface');
release_contract_has_all($mobileInventory, [
    'label, package_type, scan_code, pieces_per_unit, is_default, is_active',
    "'package_type' => normalize_item_package_type",
], 'Mobile package API');
release_contract_has_all($flutterModels, [
    'final String? packageType;',
    "packageType: json['package_type'] as String?",
], 'Flutter package compatibility');

$wristbandRegistry = release_contract_read('app/modules/wristband_registry.php');
$wristbandActions = release_contract_read('app/modules/wristband_actions.php');
$wristbandView = release_contract_read('views/wristbands/imports.php');
$wristbandJs = release_contract_read('assets/js/domains/wristbands.js');
$manualRestock = release_contract_read('app/modules/scan_manual_restock.php');
$manualRestockView = release_contract_read('views/scan/manual.php');
$manualRestockJs = release_contract_read('assets/js/domains/manual-stock.js');

release_contract_has_all($wristbandView . $wristbandJs, [
    'data-wristband-storage',
    'data-combobox-select',
    'data-wristband-item-search',
    'data-wristband-preflight',
    'CSV Example',
    'Excel Example',
    'Enable external QR tracking for selected item',
], 'Wristband import interface');
release_contract_has_all($wristbandActions . $routes, [
    'handle_wristband_import_preflight_submit',
    '/wristbands/imports/sample.csv',
    '/wristbands/imports/sample.xlsx',
    '/wristbands/imports/preflight',
    '/wristbands/imports/items',
], 'Wristband import routes');
release_contract_has_all($wristbandRegistry, [
    'FROM item_storage_balances balance',
    'WHERE balance.storage_id = :storage_id',
    'AND item.is_active = 1',
    'AND item.measurement_dimension = "count"',
    'Choose an active count-based item assigned to the selected storage.',
], 'Storage-scoped wristband selection');

if (!preg_match('/function wristband_import_candidate_items\(.*?\n}\n/s', $wristbandRegistry, $candidateMatch)) {
    release_contract_fail('Could not isolate wristband_import_candidate_items().');
}
if (preg_match('/balance\.quantity\s*>\s*0|quantity\s*>\s*0/i', $candidateMatch[0])) {
    release_contract_fail('Wristband item selection must include zero-quantity storage assignments.');
}

release_contract_has_all($manualRestock . $manualRestockView . $manualRestockJs, [
    'Wristband Codes',
    'wristband_file_field',
    "Auth::hasPermission('wristbands.import')",
    'wristband restock quantity must be a whole number',
    'valid wristband codes do not match',
    "Auth::hasPermission('items.edit')",
    'wristband_import_codes(',
    "'selected_item'",
    'true',
    'proof_image',
], 'Atomic restock and wristband-code import');
release_contract_has_all($manualRestock, [
    '$pdo->beginTransaction()',
    '$pdo->commit()',
    '$pdo->rollBack()',
], 'Manual restock transaction');
release_contract_has_all($wristbandRegistry, [
    '$ownsTransaction = !$pdo->inTransaction()',
    'if ($strict && $stats[\'imported\'] !== count($prepared))',
], 'Nested wristband import transaction');

echo '[persistent-package-wristband] PASS' . PHP_EOL;
