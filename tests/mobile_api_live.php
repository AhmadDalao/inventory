<?php
declare(strict_types=1);

$options = getopt('', ['base-url:', 'prefix::', 'allow-live']);

if (!isset($options['base-url'])) {
    fwrite(STDERR, "Usage: php tests/mobile_api_live.php --base-url=https://inventory.example.com [--prefix=ZZMOBILEAPI...] [--allow-live]\n");
    exit(1);
}

$baseUrl = rtrim((string) $options['base-url'], '/');
$baseHost = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));
$prefix = strtoupper((string) ($options['prefix'] ?? ('ZZMOBILEAPI' . date('YmdHis'))));
$prefix = preg_replace('/[^A-Z0-9]/', '', $prefix) ?: ('ZZMOBILEAPI' . date('YmdHis'));

if (in_array($baseHost, ['inventory.ahmaddalao.com', 'www.inventory.ahmaddalao.com'], true)
    && !array_key_exists('allow-live', $options)
) {
    fwrite(STDERR, "Refusing to run mobile API lifecycle tests against production without --allow-live. Back up first.\n");
    exit(1);
}

require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/modules.php';

$test = [
    'user_id' => 0,
    'storage_ids' => [],
    'item_id' => 0,
    'email' => strtolower($prefix) . '@inventory.test',
    'operation_prefix' => substr($prefix, 0, 42) . '-',
    'rate_limit_start_id' => null,
    'settings' => [],
    'setting_keys' => [
        'mobile.enabled',
        'mobile.manual_restock_enabled',
        'mobile.require_usage_proof',
        'mobile.min_supported_version',
        'mobile.usage_reasons',
    ],
    'cleaned' => false,
];

function mobile_live_note(string $message): void
{
    echo '[mobile-api-live] ' . $message . PHP_EOL;
}

function mobile_live_fail(string $message): never
{
    throw new RuntimeException($message);
}

function mobile_live_assert(bool $condition, string $message): void
{
    if (!$condition) {
        mobile_live_fail($message);
    }
}

function mobile_live_setting(string $key, string $value): void
{
    Database::execute(
        'INSERT INTO app_settings (setting_key, setting_value, updated_by, updated_at)
         VALUES (:key, :value, NULL, NOW())
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = NULL, updated_at = NOW()',
        ['key' => $key, 'value' => $value]
    );
    site_settings_cache_reset();
}

function mobile_live_balance(int $itemId, int $storageId): float
{
    return round((float) Database::scalar(
        'SELECT quantity FROM item_storage_balances WHERE item_id = :item_id AND storage_id = :storage_id',
        ['item_id' => $itemId, 'storage_id' => $storageId]
    ), 2);
}

function mobile_live_http(
    string $method,
    string $path,
    ?array $payload = null,
    ?string $accessToken = null
): array {
    global $baseUrl;

    $ch = curl_init($baseUrl . $path);
    if ($ch === false) {
        mobile_live_fail('Could not initialize cURL.');
    }

    $headers = ['Accept: application/json', 'X-Inventory-App-Version: 1.0.0'];
    if ($accessToken !== null && $accessToken !== '') {
        $headers[] = 'Authorization: Bearer ' . $accessToken;
    }
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_USERAGENT => 'InventoryKonaMobileLifecycle/1.0',
    ]);
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    $body = curl_exec($ch);
    if ($body === false) {
        $error = curl_error($ch);
        if (PHP_VERSION_ID < 80500) {
            curl_close($ch);
        }
        mobile_live_fail('HTTP request failed for ' . $path . ': ' . $error);
    }

    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if (PHP_VERSION_ID < 80500) {
        curl_close($ch);
    }
    $decoded = json_decode((string) $body, true);

    if (!is_array($decoded)) {
        mobile_live_fail('Expected JSON from ' . $path . '; received HTTP ' . $status . '.');
    }
    foreach (['data', 'meta', 'error'] as $key) {
        if (!array_key_exists($key, $decoded)) {
            mobile_live_fail('API envelope from ' . $path . ' is missing ' . $key . '.');
        }
    }

    return ['status' => $status, 'json' => $decoded];
}

function mobile_live_expect(array $response, int $status, ?string $errorCode = null): array
{
    $actual = (int) ($response['status'] ?? 0);
    $json = is_array($response['json'] ?? null) ? $response['json'] : [];
    if ($actual !== $status) {
        mobile_live_fail(
            'Expected HTTP ' . $status . ', got ' . $actual . ': '
            . json_encode($json['error'] ?? $json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }
    if ($errorCode !== null) {
        $actualCode = (string) ($json['error']['code'] ?? '');
        if ($actualCode !== $errorCode) {
            mobile_live_fail('Expected error ' . $errorCode . ', got ' . ($actualCode ?: 'none') . '.');
        }
    }
    return $json;
}

function mobile_live_cleanup(): void
{
    global $test;

    if (($test['cleaned'] ?? false) === true) {
        return;
    }
    $test['cleaned'] = true;

    try {
        $operationRows = Database::fetchAll(
            'SELECT id FROM mobile_operations WHERE client_operation_id LIKE :prefix',
            ['prefix' => (string) $test['operation_prefix'] . '%']
        );
        $operationIds = array_map('intval', array_column($operationRows, 'id'));
        if ($operationIds !== []) {
            $idList = implode(',', $operationIds);
            Database::execute(
                'DELETE usage_detail FROM inventory_movement_usage_details usage_detail
                 INNER JOIN inventory_movements movement ON movement.id = usage_detail.movement_id
                 WHERE movement.context_type = "mobile_operation" AND movement.context_id IN (' . $idList . ')'
            );
            Database::execute(
                'DELETE FROM inventory_movements WHERE context_type = "mobile_operation" AND context_id IN (' . $idList . ')'
            );
            Database::execute('DELETE FROM mobile_operations WHERE id IN (' . $idList . ')');
        }

        if ((int) $test['user_id'] > 0) {
            $userId = (int) $test['user_id'];
            Database::execute(
                'DELETE FROM notifications WHERE user_id = :user_id OR actor_user_id = :actor_user_id',
                ['user_id' => $userId, 'actor_user_id' => $userId]
            );
            Database::execute(
                'DELETE FROM activity_logs WHERE user_id = :actor_user_id OR (entity_type = "user" AND entity_id = :entity_user_id)',
                ['actor_user_id' => $userId, 'entity_user_id' => $userId]
            );
            Database::execute(
                'DELETE FROM login_attempts WHERE user_id = :user_id OR email = :email',
                ['user_id' => $userId, 'email' => $test['email']]
            );
            Database::execute('DELETE FROM mobile_refresh_token_history WHERE user_id = :user_id', ['user_id' => $userId]);
            Database::execute('DELETE FROM mobile_device_sessions WHERE user_id = :user_id', ['user_id' => $userId]);
            Database::execute('DELETE FROM user_storage_assignments WHERE user_id = :user_id', ['user_id' => $userId]);
            Database::execute('DELETE FROM mobile_user_access WHERE user_id = :user_id', ['user_id' => $userId]);
            Database::execute('DELETE FROM user_permissions WHERE user_id = :user_id', ['user_id' => $userId]);
        }

        if ((int) $test['item_id'] > 0) {
            $itemId = (int) $test['item_id'];
            Database::execute(
                'DELETE FROM inventory_change_events WHERE item_id = :item_id OR performed_by = :user_id',
                ['item_id' => $itemId, 'user_id' => (int) $test['user_id']]
            );
            Database::execute('DELETE FROM item_storage_balances WHERE item_id = :item_id', ['item_id' => $itemId]);
            Database::execute('DELETE FROM items WHERE id = :item_id', ['item_id' => $itemId]);
        }
        foreach (array_map('intval', (array) $test['storage_ids']) as $storageId) {
            Database::execute('DELETE FROM storages WHERE id = :storage_id', ['storage_id' => $storageId]);
        }
        if ((int) $test['user_id'] > 0) {
            Database::execute('DELETE FROM users WHERE id = :user_id', ['user_id' => (int) $test['user_id']]);
        }

        foreach ((array) $test['setting_keys'] as $key) {
            Database::execute('DELETE FROM app_settings WHERE setting_key = :key', ['key' => $key]);
        }
        foreach ((array) $test['settings'] as $key => $setting) {
            Database::execute(
                'INSERT INTO app_settings (setting_key, setting_value, updated_by, updated_at)
                 VALUES (:key, :value, :updated_by, :updated_at)',
                [
                    'key' => $key,
                    'value' => $setting['value'],
                    'updated_by' => $setting['updated_by'],
                    'updated_at' => $setting['updated_at'],
                ]
            );
        }
        if ($test['rate_limit_start_id'] !== null) {
            Database::execute(
                'DELETE FROM mobile_api_rate_limits WHERE id > :start_id',
                ['start_id' => (int) $test['rate_limit_start_id']]
            );
        }
        site_settings_cache_reset();
        mobile_live_note('Temporary records and settings cleaned.');
    } catch (Throwable $exception) {
        fwrite(STDERR, '[mobile-api-live] CLEANUP WARNING: ' . $exception->getMessage() . PHP_EOL);
    }
}

register_shutdown_function('mobile_live_cleanup');

try {
    $test['rate_limit_start_id'] = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM mobile_api_rate_limits');
    $placeholders = implode(',', array_fill(0, count($test['setting_keys']), '?'));
    $statement = Database::connection()->prepare(
        'SELECT setting_key, setting_value, updated_by, updated_at FROM app_settings WHERE setting_key IN (' . $placeholders . ')'
    );
    $statement->execute($test['setting_keys']);
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $test['settings'][(string) $row['setting_key']] = [
            'value' => (string) $row['setting_value'],
            'updated_by' => $row['updated_by'] !== null ? (int) $row['updated_by'] : null,
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    $ownerId = (int) Database::scalar('SELECT id FROM users WHERE role = "owner" AND is_active = 1 ORDER BY id ASC LIMIT 1');
    mobile_live_assert($ownerId > 0, 'An active owner is required to seed isolated test records.');

    $password = 'MobileLifecycle!2026';
    Database::execute(
        'INSERT INTO users (
            name, email, password_hash, role, position, is_active,
            assigned_owner_user_id, manager_user_id, created_at, updated_at
         ) VALUES (
            :name, :email, :password_hash, "staff", "Staff", 1,
            :owner_id, :manager_user_id, NOW(), NOW()
         )',
        [
            'name' => $prefix . ' Employee',
            'email' => $test['email'],
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'owner_id' => $ownerId,
            'manager_user_id' => $ownerId,
        ]
    );
    $test['user_id'] = Database::lastInsertId();

    foreach (['mobile.access', 'storages.view', 'items.view', 'movements.view', 'movements.usage', 'movements.restock'] as $permission) {
        Database::execute(
            'INSERT INTO user_permissions (user_id, permission_key, created_by, created_at)
             VALUES (:user_id, :permission, :owner_id, NOW())',
            ['user_id' => $test['user_id'], 'permission' => $permission, 'owner_id' => $ownerId]
        );
    }

    Database::execute(
        'INSERT INTO mobile_user_access (
            user_id, enabled, can_usage, can_restock, can_transfer, can_handover, can_custody,
            direct_restock_enabled, created_by, updated_by, created_at, updated_at
         ) VALUES (:user_id, 1, 1, 1, 0, 0, 0, 1, :created_by, :updated_by, NOW(), NOW())',
        ['user_id' => $test['user_id'], 'created_by' => $ownerId, 'updated_by' => $ownerId]
    );

    foreach (['Assigned' => 'wristband', 'Forbidden' => 'general'] as $suffix => $usageProfile) {
        Database::execute(
            'INSERT INTO storages (name, storage_type, usage_profile, notes, is_system, is_active, owner_user_id, created_by, updated_by, created_at, updated_at)
             VALUES (:name, "storage", :usage_profile, :notes, 0, 1, :owner_user_id, :created_by, :updated_by, NOW(), NOW())',
            [
                'name' => $prefix . ' ' . $suffix,
                'usage_profile' => $usageProfile,
                'notes' => 'Temporary mobile API lifecycle storage',
                'owner_user_id' => $ownerId,
                'created_by' => $ownerId,
                'updated_by' => $ownerId,
            ]
        );
        $test['storage_ids'][] = Database::lastInsertId();
    }
    [$assignedStorageId, $forbiddenStorageId] = array_map('intval', $test['storage_ids']);

    Database::execute(
        'INSERT INTO user_storage_assignments (user_id, storage_id, is_default, created_by, created_at, updated_at)
         VALUES (:user_id, :storage_id, 1, :owner_id, NOW(), NOW())',
        ['user_id' => $test['user_id'], 'storage_id' => $assignedStorageId, 'owner_id' => $ownerId]
    );

    Database::execute(
        'INSERT INTO items (
            name, sku, barcode, category, storage_id, unit, current_quantity, reorder_level,
            cost_per_unit, notes, is_active, created_by, updated_by, created_at, updated_at
         ) VALUES (
            :name, :sku, :barcode, "Mobile lifecycle", :storage_id, "pcs", 20, 5,
            1, :notes, 1, :created_by, :updated_by, NOW(), NOW()
         )',
        [
            'name' => $prefix . ' Item',
            'sku' => $prefix . '-SKU',
            'barcode' => $prefix . '-BAR',
            'storage_id' => $assignedStorageId,
            'notes' => 'Temporary mobile API lifecycle item',
            'created_by' => $ownerId,
            'updated_by' => $ownerId,
        ]
    );
    $test['item_id'] = Database::lastInsertId();
    Database::execute(
        'INSERT INTO item_storage_balances (item_id, storage_id, quantity, created_at, updated_at)
         VALUES (:item_id, :storage_id, 20, NOW(), NOW())',
        ['item_id' => $test['item_id'], 'storage_id' => $assignedStorageId]
    );

    mobile_live_setting('mobile.enabled', '1');
    mobile_live_setting('mobile.manual_restock_enabled', '1');
    mobile_live_setting('mobile.require_usage_proof', '0');
    mobile_live_setting('mobile.min_supported_version', '1.0.0');
    $seededAccess = mobile_api_employee_access((int) $test['user_id'], 'staff');
    mobile_live_assert((int) ($seededAccess['enabled'] ?? 0) === 1, 'Seeded mobile access was not enabled before login.');
    mobile_live_note('Seeded isolated employee, storage, and item records.');

    $login = mobile_live_expect(mobile_live_http('POST', '/api/v1/auth/login', [
        'email' => $test['email'],
        'password' => $password,
        'app_version' => '1.0.0',
        'device_uuid' => strtolower($prefix) . '-device',
        'device_name' => 'Lifecycle Android',
        'platform' => 'android',
    ]), 201);
    $access = (string) ($login['data']['access_token'] ?? '');
    $refresh = (string) ($login['data']['refresh_token'] ?? '');
    mobile_live_assert($access !== '' && $refresh !== '', 'Login did not return both tokens.');
    mobile_live_note('Authentication and device registration passed.');

    mobile_live_expect(
        mobile_live_http('POST', '/api/v1/me/verify-password', ['password' => 'definitely-wrong'], $access),
        403,
        'password_incorrect'
    );
    $passwordVerification = mobile_live_expect(
        mobile_live_http('POST', '/api/v1/me/verify-password', ['password' => $password], $access),
        200
    );
    mobile_live_assert(
        ($passwordVerification['data']['verified'] ?? false) === true,
        'Current-password verification did not return a verified result.'
    );
    mobile_live_note('Authenticated current-password verification passed.');

    $bootstrap = mobile_live_expect(mobile_live_http('GET', '/api/v1/bootstrap', null, $access), 200);
    $storageIds = array_map('intval', array_column((array) ($bootstrap['data']['storages'] ?? []), 'id'));
    mobile_live_assert(in_array($assignedStorageId, $storageIds, true), 'Assigned storage is missing from bootstrap.');
    mobile_live_assert(!in_array($forbiddenStorageId, $storageIds, true), 'Unassigned storage leaked into bootstrap.');
    $usageReasons = array_values(array_filter(
        (array) ($bootstrap['data']['settings']['usage_reasons'] ?? []),
        'is_array'
    ));
    $usageReasonCodes = array_map(
        static fn (array $reason): string => (string) ($reason['code'] ?? ''),
        $usageReasons
    );
    mobile_live_assert(in_array('school', $usageReasonCodes, true), 'Bootstrap usage reasons are missing School.');
    mobile_live_assert(in_array('other', $usageReasonCodes, true), 'Bootstrap usage reasons are missing Other.');
    $syncCursor = (int) ($bootstrap['meta']['sync_cursor'] ?? 0);
    mobile_live_note('Bootstrap storage isolation and server-owned usage reasons passed.');

    $lookup = mobile_live_expect(mobile_live_http(
        'GET',
        '/api/v1/items/lookup?q=' . rawurlencode($prefix . '-BAR') . '&storage_id=' . $assignedStorageId,
        null,
        $access
    ), 200);
    mobile_live_assert((int) ($lookup['data'][0]['id'] ?? 0) === (int) $test['item_id'], 'Barcode lookup did not return the test item.');
    mobile_live_expect(mobile_live_http('GET', '/api/v1/storages/' . $forbiddenStorageId . '/items', null, $access), 403, 'storage_forbidden');
    mobile_live_note('Barcode lookup and storage access isolation passed.');

    $usageOperation = $test['operation_prefix'] . 'usage';
    $usagePayload = [
        'client_operation_id' => $usageOperation,
        'storage_id' => $assignedStorageId,
        'item_id' => (int) $test['item_id'],
        'quantity' => 2,
        'expected_balance' => 20,
        'reason' => 'event',
        'notes' => 'Mobile lifecycle usage',
    ];
    $usage = mobile_live_expect(mobile_live_http('POST', '/api/v1/movements/usage', $usagePayload, $access), 201);
    mobile_live_assert(mobile_live_balance((int) $test['item_id'], $assignedStorageId) === 18.0, 'Usage did not reduce assigned storage to 18.');
    mobile_live_assert((float) ($usage['data']['storage_balance'] ?? -1) === 18.0, 'Usage response did not return the authoritative storage balance.');
    mobile_live_assert((int) ($usage['data']['sync_cursor'] ?? 0) > $syncCursor, 'Usage response did not advance the event cursor.');
    $balanceUpdates = array_values(array_filter((array) ($usage['data']['balance_updates'] ?? []), 'is_array'));
    mobile_live_assert(
        count(array_filter($balanceUpdates, static fn (array $row): bool =>
            (int) ($row['item_id'] ?? 0) === (int) $test['item_id']
            && (int) ($row['storage_id'] ?? 0) === $assignedStorageId
            && (float) ($row['storage_balance'] ?? -1) === 18.0
        )) === 1,
        'Usage response omitted its authoritative balance update.'
    );

    inventory_record_change_event(
        'test.forbidden_storage',
        (int) $test['item_id'],
        $forbiddenStorageId,
        'test',
        null,
        null,
        (int) $test['user_id'],
        ['prefix' => $prefix]
    );
    $sync = mobile_live_expect(mobile_live_http('GET', '/api/v1/sync?after=' . $syncCursor, null, $access), 200);
    mobile_live_assert(($sync['data']['full_resync_required'] ?? true) === false, 'Differential sync unexpectedly requested a full bootstrap.');
    mobile_live_assert((int) ($sync['meta']['next_cursor'] ?? 0) > $syncCursor, 'Differential sync did not advance its cursor.');
    $syncItems = array_values(array_filter((array) ($sync['data']['items'] ?? []), 'is_array'));
    $syncedItem = null;
    foreach ($syncItems as $candidate) {
        if ((int) ($candidate['id'] ?? 0) === (int) $test['item_id']) {
            $syncedItem = $candidate;
            break;
        }
    }
    mobile_live_assert(is_array($syncedItem), 'Differential sync omitted the changed item.');
    $syncedBalances = array_values(array_filter((array) ($syncedItem['balances'] ?? []), 'is_array'));
    mobile_live_assert(
        count(array_filter($syncedBalances, static fn (array $row): bool =>
            (int) ($row['storage_id'] ?? 0) === $assignedStorageId
            && (float) ($row['quantity'] ?? -1) === 18.0
        )) === 1,
        'Differential sync did not return the current assigned-storage balance.'
    );
    $syncEvents = array_values(array_filter((array) ($sync['data']['events'] ?? []), 'is_array'));
    mobile_live_assert(
        count(array_filter($syncEvents, static fn (array $event): bool => (int) ($event['storage_id'] ?? 0) === $assignedStorageId)) >= 1,
        'Differential sync omitted the authorized stock event.'
    );
    mobile_live_assert(
        count(array_filter($syncEvents, static fn (array $event): bool => (int) ($event['storage_id'] ?? 0) === $forbiddenStorageId)) === 0,
        'Differential sync leaked an event from an unassigned storage.'
    );
    $syncCursor = (int) ($sync['meta']['next_cursor'] ?? $syncCursor);
    mobile_live_note('Authoritative mutation response, differential cursor sync, and event isolation passed.');
    $usageRetry = mobile_live_expect(mobile_live_http('POST', '/api/v1/movements/usage', $usagePayload, $access), 201);
    mobile_live_assert(
        (int) ($usage['data']['movement_id'] ?? 0) === (int) ($usageRetry['data']['movement_id'] ?? -1),
        'Idempotent retry returned a different movement.'
    );
    mobile_live_assert(mobile_live_balance((int) $test['item_id'], $assignedStorageId) === 18.0, 'Idempotent retry deducted stock twice.');
    mobile_live_note('Usage and idempotent retry passed.');

    $expectedBalance = 18.0;
    foreach ($usageReasons as $reasonIndex => $reason) {
        $reasonCode = (string) ($reason['code'] ?? '');
        mobile_live_assert($reasonCode !== '', 'Bootstrap returned an empty usage reason code.');
        $reasonPayload = [
            'client_operation_id' => $test['operation_prefix'] . 'reason-' . $reasonIndex . '-' . $reasonCode,
            'storage_id' => $assignedStorageId,
            'item_id' => (int) $test['item_id'],
            'quantity' => 1,
            'expected_balance' => $expectedBalance,
            'reason' => $reasonCode,
        ];
        if ($reasonCode === 'other') {
            $reasonPayload['custom_reason'] = 'Lifecycle custom reason';
        }
        $reasonResponse = mobile_live_expect(
            mobile_live_http('POST', '/api/v1/movements/usage', $reasonPayload, $access),
            201
        );
        $expectedBalance -= 1;
        mobile_live_assert(
            mobile_live_balance((int) $test['item_id'], $assignedStorageId) === $expectedBalance,
            'Usage reason ' . $reasonCode . ' did not post the expected quantity.'
        );
        if ($reasonCode === 'other') {
            $storedCustomReason = (string) Database::scalar(
                'SELECT custom_reason FROM inventory_movement_usage_details WHERE movement_id = :movement_id LIMIT 1',
                ['movement_id' => (int) ($reasonResponse['data']['movement_id'] ?? 0)]
            );
            mobile_live_assert($storedCustomReason === 'Lifecycle custom reason', 'Other custom reason was not stored.');
        }
    }
    mobile_live_note('Every active usage reason and Other custom text passed.');

    mobile_live_expect(mobile_live_http('POST', '/api/v1/movements/usage', [
        'client_operation_id' => $test['operation_prefix'] . 'unknown-reason',
        'storage_id' => $assignedStorageId,
        'item_id' => (int) $test['item_id'],
        'quantity' => 1,
        'expected_balance' => $expectedBalance,
        'reason' => 'not_a_real_reason',
    ], $access), 422, 'validation_failed');
    mobile_live_expect(mobile_live_http('POST', '/api/v1/movements/usage', [
        'client_operation_id' => $test['operation_prefix'] . 'other-without-description',
        'storage_id' => $assignedStorageId,
        'item_id' => (int) $test['item_id'],
        'quantity' => 1,
        'expected_balance' => $expectedBalance,
        'reason' => 'other',
    ], $access), 422, 'validation_failed');

    $disabledCatalog = mobile_usage_reason_defaults();
    foreach ($disabledCatalog as &$disabledReason) {
        if (($disabledReason['code'] ?? '') === 'school') {
            $disabledReason['active'] = false;
        }
    }
    unset($disabledReason);
    mobile_live_setting('mobile.usage_reasons', json_encode($disabledCatalog, JSON_UNESCAPED_SLASHES));
    mobile_live_expect(mobile_live_http('POST', '/api/v1/movements/usage', [
        'client_operation_id' => $test['operation_prefix'] . 'disabled-school',
        'storage_id' => $assignedStorageId,
        'item_id' => (int) $test['item_id'],
        'quantity' => 1,
        'expected_balance' => $expectedBalance,
        'reason' => 'school',
    ], $access), 422, 'validation_failed');
    mobile_live_setting('mobile.usage_reasons', '');
    mobile_live_assert(
        mobile_live_balance((int) $test['item_id'], $assignedStorageId) === $expectedBalance,
        'Rejected usage reasons changed stock.'
    );
    mobile_live_note('Unknown, incomplete Other, and disabled reasons were rejected safely.');

    mobile_live_expect(mobile_live_http('POST', '/api/v1/movements/usage', [
        'client_operation_id' => $test['operation_prefix'] . 'stale',
        'storage_id' => $assignedStorageId,
        'item_id' => (int) $test['item_id'],
        'quantity' => 1,
        'expected_balance' => 20,
        'reason' => 'event',
    ], $access), 409, 'balance_changed');
    mobile_live_assert(mobile_live_balance((int) $test['item_id'], $assignedStorageId) === $expectedBalance, 'Stale-balance conflict changed stock.');

    mobile_live_expect(mobile_live_http('POST', '/api/v1/movements/batch', [
        'client_operation_id' => $test['operation_prefix'] . 'batch-rollback',
        'lines' => [
            ['type' => 'usage', 'storage_id' => $assignedStorageId, 'item_id' => (int) $test['item_id'], 'quantity' => 1, 'expected_balance' => $expectedBalance, 'reason' => 'event'],
            ['type' => 'usage', 'storage_id' => $assignedStorageId, 'item_id' => (int) $test['item_id'], 'quantity' => 100, 'expected_balance' => $expectedBalance - 1, 'reason' => 'damage'],
        ],
    ], $access), 409, 'balance_changed');
    mobile_live_assert(mobile_live_balance((int) $test['item_id'], $assignedStorageId) === $expectedBalance, 'Failed batch did not roll back atomically.');
    mobile_live_note('Stale balance and atomic batch rollback passed.');

    mobile_live_expect(mobile_live_http('POST', '/api/v1/movements/restock', [
        'client_operation_id' => $test['operation_prefix'] . 'restock',
        'storage_id' => $assignedStorageId,
        'item_id' => (int) $test['item_id'],
        'quantity' => 3,
        'expected_balance' => $expectedBalance,
        'notes' => 'Mobile lifecycle restock',
    ], $access), 201);
    $expectedBalance += 3;
    mobile_live_assert(mobile_live_balance((int) $test['item_id'], $assignedStorageId) === $expectedBalance, 'Restock did not increase assigned storage by 3.');
    $itemTotal = round((float) Database::scalar('SELECT current_quantity FROM items WHERE id = :id', ['id' => $test['item_id']]), 2);
    mobile_live_assert($itemTotal === $expectedBalance, 'Item total drifted from its storage balance.');

    $operations = mobile_live_expect(mobile_live_http('GET', '/api/v1/operations/mine', null, $access), 200);
    $operationRows = (array) ($operations['data']['items'] ?? []);
    mobile_live_assert($operationRows !== [], 'Operation history returned no records.');
    $statuses = array_values(array_unique(array_map(
        static fn (array $row): string => (string) ($row['status'] ?? ''),
        array_filter($operationRows, 'is_array')
    )));
    mobile_live_assert(in_array('succeeded', $statuses, true) && in_array('conflict', $statuses, true), 'Operation history is missing success or conflict records.');
    mobile_live_note('Privileged restock, stock synchronization, and operation history passed.');

    $rotated = mobile_live_expect(mobile_live_http('POST', '/api/v1/auth/refresh', ['refresh_token' => $refresh]), 200);
    $newAccess = (string) ($rotated['data']['access_token'] ?? '');
    $newRefresh = (string) ($rotated['data']['refresh_token'] ?? '');
    mobile_live_assert($newAccess !== '' && $newRefresh !== '' && $newAccess !== $access, 'Refresh did not rotate both tokens.');
    mobile_live_expect(mobile_live_http('GET', '/api/v1/me', null, $access), 401, 'token_expired');
    mobile_live_expect(mobile_live_http('GET', '/api/v1/me', null, $newAccess), 200);
    mobile_live_expect(
        mobile_live_http('POST', '/api/v1/auth/refresh', ['refresh_token' => $refresh]),
        401,
        'refresh_reuse_detected'
    );
    mobile_live_expect(mobile_live_http('GET', '/api/v1/me', null, $newAccess), 401, 'token_expired');

    $logoutLogin = mobile_live_expect(mobile_live_http('POST', '/api/v1/auth/login', [
        'email' => $test['email'],
        'password' => $password,
        'device_name' => 'Lifecycle Logout Device',
        'device_uuid' => strtolower($prefix) . '-logout-device',
        'platform' => 'android',
        'app_version' => '1.0.0',
    ]), 201);
    $logoutAccess = (string) ($logoutLogin['data']['access_token'] ?? '');
    mobile_live_assert($logoutAccess !== '', 'Second login did not return an access token for logout testing.');
    mobile_live_expect(mobile_live_http('POST', '/api/v1/auth/logout', [], $logoutAccess), 200);
    mobile_live_expect(mobile_live_http('GET', '/api/v1/me', null, $logoutAccess), 401, 'token_expired');
    mobile_live_note('Token rotation, refresh reuse detection, device revocation, and logout passed.');

    mobile_live_cleanup();
    mobile_live_note('PASS');
} catch (Throwable $exception) {
    fwrite(STDERR, '[mobile-api-live] FAIL: ' . $exception->getMessage() . PHP_EOL);
    if ((int) ($test['user_id'] ?? 0) > 0) {
        try {
            $access = mobile_api_employee_access((int) $test['user_id'], 'staff');
            fwrite(STDERR, '[mobile-api-live] Seeded access flags: ' . json_encode($access, JSON_UNESCAPED_SLASHES) . PHP_EOL);
        } catch (Throwable $ignored) {
        }
    }
    exit(1);
}
