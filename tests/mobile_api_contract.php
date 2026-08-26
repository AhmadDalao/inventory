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
    '/api/v1/me/verify-password' => 'handle_mobile_api_verify_password',
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
$storageSupport = mobile_contract_source('app/modules/storage_profiles.php');
$admin = mobile_contract_source('app/modules/mobile_admin.php');
$inventory = mobile_contract_source('app/modules/mobile_api_inventory.php');
$inventoryEvents = mobile_contract_source('app/modules/inventory_events.php');
$itemActions = mobile_contract_source('app/modules/item_actions.php');
$itemPersistence = mobile_contract_source('app/modules/items.php');
$itemAssignments = mobile_contract_source('app/modules/item_storage_assignments.php');
$movements = mobile_contract_source('app/modules/mobile_api_movements.php');
$handovers = mobile_contract_source('app/modules/mobile_api_handovers.php');
$settings = mobile_contract_source('app/support/settings_schema.php');
$permissions = mobile_contract_source('app/support/permission_catalog.php');
$schema = mobile_contract_source('app/maintenance/MaintenanceMobileSchemas.php');
$schemaState = mobile_contract_source('app/maintenance/MaintenanceSchemaState.php');
$schemaHelpers = mobile_contract_source('app/maintenance/MaintenanceSchemaHelpers.php');
$measurementSchema = mobile_contract_source('app/maintenance/MaintenanceMeasurementSchemas.php');
$inventorySchema = mobile_contract_source('app/maintenance/MaintenanceInventorySchemas.php');
$measurements = mobile_contract_source('app/modules/measurements.php');
$scanMovements = mobile_contract_source('app/modules/scan_movements.php');
$departments = mobile_contract_source('app/modules/departments.php');

foreach (['password_verify(', "'password_verify'", "'password_incorrect'"] as $passwordVerificationContract) {
    if (strpos($auth, $passwordVerificationContract) === false) {
        fail_mobile_contract('Password re-verification contract is missing: ' . $passwordVerificationContract);
    }
}

if (strpos($schemaHelpers, 'private static function indexExists(') === false) {
    fail_mobile_contract('Mobile schema upgrades require the shared indexExists helper.');
}

foreach (['data', 'meta', 'error'] as $responseKey) {
    if (strpos($support, "'" . $responseKey . "'") === false) {
        fail_mobile_contract('API response envelope is missing key: ' . $responseKey);
    }
}

foreach ([
    'client_operation_id',
    'uniq_mobile_client_operation',
    'mobile_api_existing_operation_result',
    'mobile_api_is_duplicate_operation_exception',
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

if (substr_count($support, 'mobile_api_existing_operation_result($session, $operationId)') < 2
    || strpos($support, 'if (mobile_api_is_duplicate_operation_exception($exception))') === false
) {
    fail_mobile_contract('Concurrent duplicate operation IDs must replay the winning committed response.');
}

if (strpos(
    $support,
    '$storageIds = mobile_api_inventory_scope_ids($session, $permissions);'
) === false) {
    fail_mobile_contract('Authoritative mutation responses must stay inside the strict mobile inventory scope.');
}

if (strpos(
    $handovers,
    'mobile_api_inventory_scope_ids($session, $permissions)'
) === false) {
    fail_mobile_contract('Mobile custody actions must use the strict mobile inventory scope.');
}

foreach ([
    'measurement_dimension',
    'usage_proof_policy',
    'refill_proof_policy',
    'inventory_movement_measurement_details',
    'inventory_movement_documents',
    'departments',
    'department_id',
] as $marker) {
    if (strpos($measurementSchema, $marker) === false) {
        fail_mobile_contract('Measured inventory schema is missing marker: ' . $marker);
    }
}

foreach ([
    'resolve_inventory_measurement',
    'inventory_actor_department_snapshot',
    'inventory_operation_requires_proof',
    'record_inventory_movement_measurement',
] as $marker) {
    if (strpos($measurements, $marker) === false) {
        fail_mobile_contract('Measured inventory service is missing marker: ' . $marker);
    }
}

foreach ([
    'scan_movement_batch_validate_line',
    'inventory_measurement_from_payload',
    'inventory_operation_requires_proof',
    'register_inventory_operation_proof',
    '$pdo->beginTransaction()',
    '$pdo->rollBack()',
] as $marker) {
    if (strpos($scanMovements, $marker) === false) {
        fail_mobile_contract('Measured Scan Center batch is missing marker: ' . $marker);
    }
}

foreach (['handle_departments_page', 'handle_department_save_submit', 'handle_department_archive_submit'] as $marker) {
    if (strpos($departments, $marker) === false) {
        fail_mobile_contract('Department lifecycle is missing handler: ' . $marker);
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
    'inventory_record_item_change_event',
    'inventory_record_item_change_events_for_assignments',
] as $marker) {
    if (strpos($inventoryEvents, $marker) === false) {
        fail_mobile_contract('Item lifecycle synchronization is missing helper: ' . $marker);
    }
}

if (strpos($inventory, "if ((\$itemPayload['balances'] ?? []) === [])") === false
    || strpos($inventory, '$deletedIds[] = (int) $itemId;') === false
) {
    fail_mobile_contract('Items removed from an authorized storage must synchronize as tombstones.');
}

foreach (['item.unassigned', 'inventory_record_item_change_event(', '$pdo->beginTransaction()', '$pdo->rollBack()'] as $marker) {
    if (strpos($itemActions, $marker) === false) {
        fail_mobile_contract('Item removal lifecycle is missing transactional realtime marker: ' . $marker);
    }
}

foreach (['item.created', 'item.assigned', 'item.updated', 'inventory_record_item_change_events_for_assignments'] as $marker) {
    if (strpos($itemPersistence, $marker) === false) {
        fail_mobile_contract('Item persistence is missing realtime marker: ' . $marker);
    }
}

if (strpos($itemAssignments, 'function assign_item_to_storage(int $itemId, int $storageId): bool') === false
    || strpos($itemAssignments, 'return !$alreadyAssigned;') === false
) {
    fail_mobile_contract('Storage assignment must report whether a new mobile-visible assignment was created.');
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

foreach (['general_usage_reason_defaults', 'mobile.general_usage_reasons', 'usage_reason_input_for_storage'] as $marker) {
    if (strpos($usageReasons, $marker) === false) {
        fail_mobile_contract('Storage-profile usage reasons are missing marker: ' . $marker);
    }
}

foreach (['usage_profile', 'storage_usage_profile_for_id'] as $marker) {
    if (strpos($inventory . $inventorySchema . $storageSupport, $marker) === false) {
        fail_mobile_contract('Storage usage profile contract is missing marker: ' . $marker);
    }
}

if (strpos($inventory, "'usage_reasons' => mobile_usage_reason_catalog(true)") === false) {
    fail_mobile_contract('Bootstrap must return the active server-owned usage reason catalog.');
}

if (strpos($inventory, "'usage_reason_catalogs' => usage_reason_catalogs(true)") === false) {
    fail_mobile_contract('Bootstrap must return profile-aware usage reason catalogs.');
}

if (substr_count($movements, 'custom_reason, notes') < 2
    || strpos($movements, "'custom_reason' => \$reasonInput['custom_reason']") === false
    || strpos($movements, "'custom_reason' => \$entry['reason']['custom_reason']") === false
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
    "'mobile.access'",
    "'storages.view'",
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
    'function mobile_api_inventory_scope_ids(',
    'mobile_setup_incomplete',
    'requires_storage',
    'requires_manager',
] as $scopeMarker) {
    if (strpos($support, $scopeMarker) === false) {
        fail_mobile_contract('Mobile inventory setup validation is missing marker: ' . $scopeMarker);
    }
}

$storageScopeOffset = strpos($support, 'function mobile_api_storage_ids(');
$storageScopeSource = $storageScopeOffset === false ? '' : substr($support, $storageScopeOffset, 1400);
foreach (['INNER JOIN storages storage', 'storage.is_active = 1', 'storage.is_system = 0'] as $scopeMarker) {
    if (strpos($storageScopeSource, $scopeMarker) === false) {
        fail_mobile_contract('Mobile storage assignments must exclude archived and system locations: ' . $scopeMarker);
    }
}

$storagesHandlerOffset = strpos($inventory, 'function handle_mobile_api_storages(');
$storagesHandlerSource = $storagesHandlerOffset === false ? '' : substr($inventory, $storagesHandlerOffset, 2600);
if (strpos($storagesHandlerSource, '$term') !== false || strpos($storagesHandlerSource, 'item_package_presets') !== false) {
    fail_mobile_contract('The storages endpoint must not execute item lookup logic or depend on an undefined search term.');
}

$itemLookupOffset = strpos($inventory, 'function handle_mobile_api_item_lookup(');
$itemLookupSource = $itemLookupOffset === false ? '' : substr($inventory, $itemLookupOffset, 5000);
foreach ([
    'FROM item_package_presets preset',
    'preset.scan_code = ?',
    'matched_package_preset_id',
    'mobile_api_item_payload(',
] as $packageLookupMarker) {
    if (strpos($itemLookupSource, $packageLookupMarker) === false) {
        fail_mobile_contract('Mobile item lookup must resolve package barcodes inside the authorized storage scope: ' . $packageLookupMarker);
    }
}

foreach ([
    'handle_mobile_api_me',
    'handle_mobile_api_bootstrap',
    'handle_mobile_api_sync',
    'handle_mobile_api_storages',
    'handle_mobile_api_storage_items',
    'handle_mobile_api_item_lookup',
    'handle_mobile_api_item_show',
] as $scopeHandler) {
    $handlerOffset = strpos($inventory, 'function ' . $scopeHandler . '(');
    if ($handlerOffset === false) {
        fail_mobile_contract('Missing mobile inventory handler: ' . $scopeHandler);
    }

    $handlerSource = substr($inventory, $handlerOffset, 2200);
    if (strpos($handlerSource, 'mobile_api_inventory_scope_ids(') === false) {
        fail_mobile_contract($scopeHandler . ' must enforce configured storage scope.');
    }
}

foreach ([
    'mobile_admin_required_permissions',
    'mobile_admin_setup_state',
    'sync_user_storage_memberships',
    'manager_user_id',
    'apply_required_permissions',
] as $adminMarker) {
    if (strpos($admin, $adminMarker) === false) {
        fail_mobile_contract('Unified Mobile Access configuration is missing marker: ' . $adminMarker);
    }
}

foreach ([
    'allowed_actions',
    'mobile_api_require_handover_view',
    'mobile_api_require_handover_action',
    'handover_action_denied',
    'mobile_api_handover_action_storage_id',
    'mobile_api_handover_action_has_storage_scope',
    'mobile_api_require_storage(',
] as $marker) {
    if (strpos($handovers, $marker) === false) {
        fail_mobile_contract('Record-level handover authorization is missing marker: ' . $marker);
    }
}

if (strpos($handovers, 'mobile_api_handover_assert_viewable') !== false) {
    fail_mobile_contract('The removed broad handover guard must not remain callable.');
}

$custodyApproveOffset = strpos($handovers, 'function handle_mobile_api_handover_custody_return_approve(');
$custodyApproveSource = $custodyApproveOffset === false ? '' : substr($handovers, $custodyApproveOffset, 1600);
$custodyRejectOffset = strpos($handovers, 'function handle_mobile_api_handover_custody_return_reject(');
$custodyRejectSource = $custodyRejectOffset === false ? '' : substr($handovers, $custodyRejectOffset, 1300);
foreach ([$custodyApproveSource, $custodyRejectSource] as $custodyReviewSource) {
    if (strpos($custodyReviewSource, 'mobile_api_require_storage($session, (int) $handover[\'source_storage_id\'])') === false) {
        fail_mobile_contract('Custody review mutations must enforce current source-storage access.');
    }
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

if (strpos($inventory, "'package_type' => normalize_item_package_type") === false
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
