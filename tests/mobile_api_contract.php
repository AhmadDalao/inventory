<?php
declare(strict_types=1);

$root = dirname(__DIR__);

function fail_mobile_contract(string $message): never
{
    fwrite(STDERR, '[mobile-api-contract] FAIL: ' . $message . PHP_EOL);
    exit(1);
}

function mobile_contract_source(string $relativePath): string
{
    global $root;
    $path = $root . '/' . $relativePath;

    if (!is_file($path)) {
        fail_mobile_contract('Missing file: ' . $relativePath);
    }

    $source = file_get_contents($path);

    if ($source === false) {
        fail_mobile_contract('Could not read file: ' . $relativePath);
    }

    return $source;
}

$index = mobile_contract_source('index.php');
$manifest = require $root . '/app/module_manifest.php';
$mobileModules = $manifest['mobile_api'] ?? [];

$requiredModules = [
    'mobile_api_support',
    'mobile_api_auth',
    'mobile_usage_reasons',
    'mobile_api_inventory',
    'mobile_api_movements',
    'mobile_api_handovers',
    'mobile_admin',
];

if ($mobileModules !== $requiredModules) {
    fail_mobile_contract('The mobile API module manifest is incomplete or out of order.');
}

foreach ($requiredModules as $module) {
    if (!is_file($root . '/app/modules/' . $module . '.php')) {
        fail_mobile_contract('Missing module: app/modules/' . $module . '.php');
    }
}

$routes = [
    '/api/v1/auth/login' => 'handle_mobile_api_login',
    '/api/v1/auth/refresh' => 'handle_mobile_api_refresh',
    '/api/v1/auth/logout' => 'handle_mobile_api_logout',
    '/api/v1/me' => 'handle_mobile_api_me',
    '/api/v1/bootstrap' => 'handle_mobile_api_bootstrap',
    '/api/v1/sync' => 'handle_mobile_api_sync',
    '/api/v1/operations/mine' => 'handle_mobile_api_operations_mine',
    '/api/v1/storages' => 'handle_mobile_api_storages',
    '/api/v1/storages/{id}/items' => 'handle_mobile_api_storage_items',
    '/api/v1/items/lookup' => 'handle_mobile_api_item_lookup',
    '/api/v1/items/{id}' => 'handle_mobile_api_item_show',
    '/api/v1/movements/usage' => 'handle_mobile_api_usage',
    '/api/v1/movements/restock' => 'handle_mobile_api_restock',
    '/api/v1/movements/batch' => 'handle_mobile_api_batch',
    '/api/v1/handovers' => 'handle_mobile_api_handovers',
    '/api/v1/handovers/mine' => 'handle_mobile_api_handovers_mine',
    '/api/v1/handovers/{id}' => 'handle_mobile_api_handover_show',
    '/api/v1/handovers/{id}/receipt' => 'handle_mobile_api_handover_receive',
    '/api/v1/handovers/{id}/confirm-receipt' => 'handle_mobile_api_handover_confirm_receipt',
    '/api/v1/handovers/{id}/closeout' => 'handle_mobile_api_handover_closeout',
    '/api/v1/handovers/{id}/approve-closeout' => 'handle_mobile_api_handover_approve_closeout',
    '/api/v1/handovers/{id}/approve-request' => 'handle_mobile_api_handover_approve_request',
    '/api/v1/handovers/{id}/reject-request' => 'handle_mobile_api_handover_reject_request',
    '/api/v1/handovers/{id}/cancel' => 'handle_mobile_api_handover_cancel',
    '/api/v1/handovers/{id}/custody-returns' => 'handle_mobile_api_handover_custody_return_create',
    '/api/v1/handovers/{id}/custody-returns/{return_id}' => 'handle_mobile_api_handover_custody_return_show',
    '/api/v1/handovers/{id}/custody-returns/{return_id}/approve' => 'handle_mobile_api_handover_custody_return_approve',
    '/api/v1/handovers/{id}/custody-returns/{return_id}/reject' => 'handle_mobile_api_handover_custody_return_reject',
];

foreach ($routes as $route => $handler) {
    if (strpos($index, "'" . $route . "'") === false) {
        fail_mobile_contract('Missing API route: ' . $route);
    }

    $handlerFound = false;

    foreach ($requiredModules as $module) {
        $source = mobile_contract_source('app/modules/' . $module . '.php');

        if (strpos($source, 'function ' . $handler . '(') !== false) {
            $handlerFound = true;
            break;
        }
    }

    if (!$handlerFound) {
        fail_mobile_contract('Missing API handler: ' . $handler);
    }
}

$support = mobile_contract_source('app/modules/mobile_api_support.php');
$auth = mobile_contract_source('app/modules/mobile_api_auth.php');
$usageReasons = mobile_contract_source('app/modules/mobile_usage_reasons.php');
$admin = mobile_contract_source('app/modules/mobile_admin.php');
$inventory = mobile_contract_source('app/modules/mobile_api_inventory.php');
$inventoryEvents = mobile_contract_source('app/modules/inventory_events.php');
$movements = mobile_contract_source('app/modules/mobile_api_movements.php');
$handovers = mobile_contract_source('app/modules/mobile_api_handovers.php');
$settings = mobile_contract_source('app/support/settings_schema.php');
$permissions = mobile_contract_source('app/support/permission_catalog.php');
$schema = mobile_contract_source('app/maintenance/MaintenanceMobileSchemas.php');
$schemaState = mobile_contract_source('app/maintenance/MaintenanceSchemaState.php');

foreach (['data', 'meta', 'error'] as $responseKey) {
    if (strpos($support, "'" . $responseKey . "'") === false) {
        fail_mobile_contract('API response envelope is missing key: ' . $responseKey);
    }
}

foreach ([
    'client_operation_id',
    'uniq_mobile_client_operation',
    'balance_changed',
    'expected_balance',
    'mobile_api_enforce_mutation_rate_limit',
    'inventory_change_events',
    "self::tableExists('inventory_change_events')",
    "self::tableExists('mobile_refresh_token_history')",
    "self::tableExists('mobile_api_rate_limits')",
    'mobile_refresh_token_history',
    'mobile_api_rate_limits',
    'mobile_api_operation_storage_id',
    'mobile_api_authoritative_balance_updates',
    'balance_updates',
    'current_balance',
] as $marker) {
    if (strpos($support . $schema . $schemaState, $marker) === false) {
        fail_mobile_contract('Idempotency or conflict protection is missing marker: ' . $marker);
    }
}

if (strpos($movements, "'storage_balance' => \$storageBalance") === false) {
    fail_mobile_contract('Batch movement responses must include authoritative storage balances.');
}

foreach ([
    'after',
    'next_cursor',
    'has_more',
    'full_resync_required',
    'inventory_latest_event_cursor',
    'tasks_changed',
] as $marker) {
    if (strpos($inventory . $inventoryEvents, $marker) === false) {
        fail_mobile_contract('Differential synchronization is missing marker: ' . $marker);
    }
}

foreach ([
    'reuse_detected_at',
    'refresh_reuse_detected',
    'UPDATE mobile_device_sessions SET revoked_at = NOW()',
] as $marker) {
    if (strpos($auth . $schema, $marker) === false) {
        fail_mobile_contract('Refresh-token reuse protection is missing marker: ' . $marker);
    }
}

foreach (['school', 'requires_custom_text', 'mobile.usage_reasons', 'no_show', 'noshow'] as $marker) {
    if (strpos($usageReasons, $marker) === false) {
        fail_mobile_contract('Usage reason catalog is missing marker: ' . $marker);
    }
}

if (strpos($inventory, "'usage_reasons' => mobile_usage_reason_catalog(true)") === false) {
    fail_mobile_contract('Bootstrap must return the active server-owned usage reason catalog.');
}

if (strpos($movements, 'custom_reason, notes') === false
    || strpos($movements, "'custom_reason' => \$customReason") === false
) {
    fail_mobile_contract('Single and batch usage must persist custom reasons.');
}

foreach ([
    'access_expires_at',
    'refresh_expires_at',
    'access_token_hash',
    'refresh_token_hash',
    'revoked_at',
] as $marker) {
    if (strpos($auth . $support . $schema, $marker) === false) {
        fail_mobile_contract('Session security is missing marker: ' . $marker);
    }
}

foreach ([
    'mobile_user_access',
    'user_storage_assignments',
    'mobile_device_sessions',
    'mobile_operations',
    'inventory_movement_usage_details',
] as $table) {
    if (strpos($schema, $table) === false) {
        fail_mobile_contract('Mobile maintenance schema is missing table: ' . $table);
    }
}

if (strpos($settings, "'mobile.enabled'") === false
    || strpos($settings, "'default' => '0'") === false
) {
    fail_mobile_contract('Mobile API must remain disabled by default.');
}

if (strpos($permissions, "'mobile.access'") === false) {
    fail_mobile_contract('Permission catalog is missing mobile.access.');
}

foreach ([
    'mobile_api_effective_capabilities',
    'mobile_api_require_permission',
    'mobile_access_revoked',
    'mobile_capability_denied',
] as $marker) {
    if (strpos($support, $marker) === false) {
        fail_mobile_contract('Runtime permission enforcement is missing marker: ' . $marker);
    }
}

foreach ([
    "'items.view'",
    "'movements.usage'",
    "'movements.restock'",
    "'handovers.create'",
    "'handovers.request'",
    "'handovers.custody_return'",
] as $permissionMarker) {
    if (strpos($support, $permissionMarker) === false) {
        fail_mobile_contract('Effective mobile capabilities do not intersect website permission: ' . $permissionMarker);
    }
}

if (strpos($inventory, "'permissions' => \$permissions") === false
    || strpos($inventory, "'capabilities' => \$capabilities") === false
) {
    fail_mobile_contract('Bootstrap must return current permissions and effective capabilities.');
}

foreach ([
    'allowed_actions',
    'mobile_api_require_handover_view',
    'mobile_api_require_handover_action',
    'handover_action_denied',
] as $marker) {
    if (strpos($handovers, $marker) === false) {
        fail_mobile_contract('Record-level handover authorization is missing marker: ' . $marker);
    }
}

if (strpos($handovers, 'mobile_api_handover_assert_viewable') !== false) {
    fail_mobile_contract('The removed broad handover guard must not remain callable.');
}

foreach ([
    "\$requiredCapability = 'handover'",
    "\$requiredCapability = 'transfer'",
    "\$requiredCapability = 'custody'",
    'mobile_api_require_capability($session, $requiredCapability)',
] as $purposeGuard) {
    if (strpos($handovers, $purposeGuard) === false) {
        fail_mobile_contract('Handover creation is missing purpose guard: ' . $purposeGuard);
    }
}

if (strpos($support, '$session[\'user_id\'] ?? $session[\'id\']') === false) {
    fail_mobile_contract('Mobile access guard must accept both session rows and login user rows.');
}

if (strpos($admin, ':created_by, :updated_by') === false
    || strpos($admin, ':actor_id, :actor_id') !== false
) {
    fail_mobile_contract('Mobile Access save must use unique placeholders with strict PDO prepares.');
}

if (strpos($inventory, 'package_type') !== false
    || strpos($inventory, 'item_package_presets WHERE item_id = :item_id AND is_active') !== false
) {
    fail_mobile_contract('Mobile item payload must match the item_package_presets schema.');
}

foreach ([
    'proof_image',
    'mobile_api_operation',
    '$pdo->beginTransaction()',
    '$pdo->commit()',
    '$pdo->rollBack()',
] as $marker) {
    if (strpos($support . $movements . $handovers, $marker) === false) {
        fail_mobile_contract('Atomic/proof workflow is missing marker: ' . $marker);
    }
}

echo '[mobile-api-contract] PASS' . PHP_EOL;
