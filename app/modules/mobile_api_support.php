<?php
declare(strict_types=1);

final class MobileApiException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $statusCode = 422,
        public readonly array $fields = [],
        public readonly bool $retryable = false,
        public readonly array $details = []
    ) {
        parent::__construct($message);
    }
}

function mobile_api_json_input(): array
{
    static $payload;
    if (is_array($payload)) {
        return $payload;
    }
    $raw = file_get_contents('php://input') ?: '';
    $decoded = $raw !== '' ? json_decode($raw, true) : [];
    $payload = is_array($decoded) ? $decoded : [];
    return $payload;
}

function mobile_api_request_payload(): array
{
    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($contentType, 'multipart/form-data') || str_contains($contentType, 'application/x-www-form-urlencoded')) {
        $payload = $_POST;
        if (isset($payload['payload']) && is_string($payload['payload'])) {
            $decoded = json_decode($payload['payload'], true);
            if (is_array($decoded)) {
                $payload = array_merge($payload, $decoded);
            }
        }
        return is_array($payload) ? $payload : [];
    }

    return mobile_api_json_input();
}

function mobile_api_success($data = null, array $meta = [], int $status = 200): never
{
    json_response(['data' => $data, 'meta' => $meta, 'error' => null], $status);
}

function mobile_api_error(
    string $code,
    string $message,
    int $status = 422,
    array $fields = [],
    bool $retryable = false,
    array $details = []
): never
{
    json_response([
        'data' => null,
        'meta' => [],
        'error' => [
            'code' => $code,
            'message' => $message,
            'fields' => $fields,
            'retryable' => $retryable,
            'details' => $details === [] ? null : $details,
        ],
    ], $status);
}

function mobile_api_run(callable $handler): never
{
    try {
        $handler();
        mobile_api_success();
    } catch (MobileApiException $exception) {
        mobile_api_error(
            $exception->errorCode,
            $exception->getMessage(),
            $exception->statusCode,
            $exception->fields,
            $exception->retryable,
            $exception->details
        );
    } catch (RuntimeException $exception) {
        $message = $exception->getMessage();
        $conflict = str_contains(strtolower($message), 'negative') || str_contains(strtolower($message), 'balance');
        error_log('[mobile-api] Runtime failure: ' . $message);
        mobile_api_error(
            $conflict ? 'balance_changed' : 'operation_failed',
            $conflict ? 'Stock changed. Refresh the current balance and confirm again.' : 'The operation could not be completed.',
            $conflict ? 409 : 422,
            [],
            $conflict
        );
    } catch (Throwable $exception) {
        error_log('[mobile-api] ' . $exception->getMessage());
        mobile_api_error('server_error', 'The server could not complete this operation.', 500, [], true);
    }
}

function mobile_api_bearer_token(): string
{
    $header = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches) !== 1) {
        return '';
    }
    return trim($matches[1]);
}

function mobile_api_min_version_supported(string $version): bool
{
    $minimum = trim(site_setting('mobile.min_supported_version', '1.0.0')) ?: '1.0.0';
    return version_compare($version, $minimum, '>=');
}

function mobile_api_session(bool $touch = true): array
{
    if (site_setting('mobile.enabled', '0') !== '1') {
        throw new MobileApiException('mobile_disabled', 'Mobile access is currently disabled.', 503);
    }
    $token = mobile_api_bearer_token();
    if ($token === '') {
        throw new MobileApiException('unauthenticated', 'Sign in is required.', 401);
    }
    $session = Database::fetch(
        'SELECT mobile_session.*, users.name, users.email, users.role, users.position, users.is_active
         FROM mobile_device_sessions mobile_session
         INNER JOIN users ON users.id = mobile_session.user_id
         WHERE mobile_session.access_token_hash = :token_hash
           AND mobile_session.revoked_at IS NULL
           AND mobile_session.access_expires_at > NOW()
           AND users.is_active = 1
         LIMIT 1',
        ['token_hash' => hash('sha256', $token)]
    );
    if (!$session) {
        throw new MobileApiException('token_expired', 'The access token is invalid or expired.', 401, [], true);
    }
    if (!mobile_api_min_version_supported((string) $session['app_version'])) {
        throw new MobileApiException('upgrade_required', 'Update the application before continuing.', 426);
    }
    if (!Auth::userHasPermission((int) $session['user_id'], 'mobile.access')) {
        throw new MobileApiException('mobile_access_revoked', 'Mobile access was revoked for this employee.', 403);
    }
    mobile_api_require_employee_access($session);
    if ($touch) {
        Database::execute(
            'UPDATE mobile_device_sessions SET last_seen_at = NOW(), last_ip = :ip, updated_at = NOW() WHERE id = :id',
            ['ip' => auth_request_ip(), 'id' => $session['id']]
        );
    }
    return $session;
}

function mobile_api_employee_access(int $userId, string $role = ''): array
{
    if ($role === '') {
        $role = (string) (Database::scalar('SELECT role FROM users WHERE id = :id', ['id' => $userId]) ?: '');
    }
    if ($role === 'owner') {
        return [
            'enabled' => 1,
            'can_usage' => 1,
            'can_restock' => 1,
            'can_transfer' => 1,
            'can_handover' => 1,
            'can_custody' => 1,
            'direct_restock_enabled' => 1,
        ];
    }

    return Database::fetch(
        'SELECT enabled, can_usage, can_restock, can_transfer, can_handover, can_custody, direct_restock_enabled
         FROM mobile_user_access
         WHERE user_id = :user_id
         LIMIT 1',
        ['user_id' => $userId]
    ) ?: [
        'enabled' => 0,
        'can_usage' => 0,
        'can_restock' => 0,
        'can_transfer' => 0,
        'can_handover' => 0,
        'can_custody' => 0,
        'direct_restock_enabled' => 0,
    ];
}

function mobile_api_require_employee_access(array $session): array
{
    $userId = (int) ($session['user_id'] ?? $session['id'] ?? 0);
    if ($userId <= 0) {
        throw new MobileApiException('mobile_access_revoked', 'Mobile access could not be verified for this employee.', 403);
    }
    $access = mobile_api_employee_access($userId, (string) ($session['role'] ?? ''));
    if ((int) ($access['enabled'] ?? 0) !== 1) {
        throw new MobileApiException('mobile_access_revoked', 'Mobile access is not enabled for this employee.', 403);
    }
    return $access;
}

function mobile_api_require_capability(array $session, string $capability): void
{
    if (!in_array($capability, ['usage', 'restock', 'transfer', 'handover', 'custody'], true)) {
        throw new MobileApiException('invalid_capability', 'Unknown mobile capability.', 500);
    }
    $access = mobile_api_require_employee_access($session);
    $effective = mobile_api_effective_capabilities(
        $access,
        mobile_api_permissions((int) $session['user_id']),
        mobile_api_storage_ids($session)
    );
    if (!in_array($capability, $effective, true)) {
        throw new MobileApiException('mobile_capability_denied', 'This mobile action is not enabled for your account.', 403);
    }
}

function mobile_api_enforce_mutation_rate_limit(array $session, int $limit = 120): void
{
    mobile_api_enforce_rate_limit(
        'mutation',
        (string) $session['user_id'] . ':' . (string) $session['id'] . ':' . auth_request_ip(),
        $limit,
        60
    );
    $recent = (int) Database::scalar(
        'SELECT COUNT(*) FROM mobile_operations WHERE user_id = :user_id AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)',
        ['user_id' => $session['user_id']]
    );
    if ($recent >= $limit) {
        throw new MobileApiException('rate_limited', 'Too many mobile operations. Wait a moment and retry.', 429, [], true);
    }
}

function mobile_api_enforce_rate_limit(
    string $scope,
    string $key,
    int $limit,
    int $windowSeconds = 60
): void {
    $scope = preg_replace('/[^a-z0-9_.-]/i', '', $scope) ?: 'request';
    $limit = max(1, $limit);
    $windowSeconds = max(1, $windowSeconds);
    $keyHash = hash('sha256', $key);
    Database::execute(
        'INSERT INTO mobile_api_rate_limits (
            scope_name, key_hash, window_started_at, request_count, updated_at
         ) VALUES (
            :scope_name, :key_hash, NOW(), 1, NOW()
         ) ON DUPLICATE KEY UPDATE
            request_count = IF(window_started_at < DATE_SUB(NOW(), INTERVAL ' . $windowSeconds . ' SECOND), 1, request_count + 1),
            window_started_at = IF(window_started_at < DATE_SUB(NOW(), INTERVAL ' . $windowSeconds . ' SECOND), NOW(), window_started_at),
            updated_at = NOW()',
        ['scope_name' => $scope, 'key_hash' => $keyHash]
    );
    $count = (int) Database::scalar(
        'SELECT request_count FROM mobile_api_rate_limits WHERE scope_name = :scope_name AND key_hash = :key_hash',
        ['scope_name' => $scope, 'key_hash' => $keyHash]
    );
    if ($count > $limit) {
        throw new MobileApiException('rate_limited', 'Too many requests. Wait a moment and retry.', 429, [], true);
    }
}

function mobile_api_permissions(int $userId): array
{
    return Auth::permissionsForUserId($userId);
}

/**
 * Return only mobile actions that are usable after every applicable access
 * layer is evaluated. Mobile Access can narrow website permissions; it must
 * never expand them.
 */
function mobile_api_effective_capabilities(
    array $access,
    array $permissions,
    array $storageIds
): array {
    if ($storageIds === [] || !in_array('items.view', $permissions, true)) {
        return [];
    }

    $hasPermission = static fn (string $permission): bool => in_array($permission, $permissions, true);
    $enabled = static fn (string $capability): bool => (int) ($access['can_' . $capability] ?? 0) === 1;
    $capabilities = [];

    if ($enabled('usage') && $hasPermission('movements.usage')) {
        $capabilities[] = 'usage';
    }
    if (
        $enabled('restock')
        && $hasPermission('movements.restock')
        && site_setting('mobile.manual_restock_enabled', '0') === '1'
        && (int) ($access['direct_restock_enabled'] ?? 0) === 1
    ) {
        $capabilities[] = 'restock';
    }
    if ($enabled('transfer') && $hasPermission('handovers.create')) {
        $capabilities[] = 'transfer';
    }
    if (
        $enabled('handover')
        && ($hasPermission('handovers.create') || $hasPermission('handovers.request'))
    ) {
        $capabilities[] = 'handover';
    }
    if (
        $enabled('custody')
        && ($hasPermission('handovers.create') || $hasPermission('handovers.custody_return'))
    ) {
        $capabilities[] = 'custody';
    }

    return $capabilities;
}

function mobile_api_require_permission(array $session, string $permission): void
{
    if (!Auth::userHasPermission((int) $session['user_id'], $permission)) {
        throw new MobileApiException('forbidden', 'You do not have permission for this action.', 403);
    }
}

function mobile_api_storage_ids(array $session): array
{
    if (($session['role'] ?? '') === 'owner') {
        return array_map('intval', array_column(Database::fetchAll('SELECT id FROM storages WHERE is_active = 1 AND is_system = 0'), 'id'));
    }
    return array_map('intval', array_column(Database::fetchAll(
        'SELECT storage_id FROM user_storage_assignments WHERE user_id = :user_id ORDER BY is_default DESC, id ASC',
        ['user_id' => $session['user_id']]
    ), 'storage_id'));
}

/**
 * Resolve the inventory scope used by mobile reads and sync. A broken employee
 * setup is an actionable configuration error, not an empty inventory result.
 */
function mobile_api_inventory_scope_ids(array $session, ?array $permissions = null): array
{
    $permissions ??= mobile_api_permissions((int) $session['user_id']);
    $role = (string) ($session['role'] ?? '');
    $missingPermissions = array_values(array_diff(
        ['mobile.access', 'storages.view', 'items.view'],
        $permissions
    ));
    $storageIds = mobile_api_storage_ids($session);
    $missingStorage = $role !== 'owner' && $storageIds === [];
    $missingManager = $role === 'staff' && manager_user_for((int) $session['user_id']) === null;

    if ($missingPermissions !== [] || $missingStorage || $missingManager) {
        throw new MobileApiException(
            'mobile_setup_incomplete',
            'Your mobile account is not fully configured. Ask the owner to assign your manager, storage access, and required permissions in Mobile Access.',
            403,
            [],
            false,
            [
                'missing_permissions' => $missingPermissions,
                'requires_storage' => $missingStorage,
                'requires_manager' => $missingManager,
            ]
        );
    }

    return $storageIds;
}

function mobile_api_manager_payload(int $userId): ?array
{
    $manager = manager_user_for($userId);
    if (!$manager) {
        return null;
    }

    return [
        'id' => (int) $manager['id'],
        'name' => (string) $manager['name'],
        'role' => (string) $manager['role'],
        'position' => (string) ($manager['position'] ?? ''),
    ];
}

function mobile_api_storage_access_roles(array $session, ?array $storageIds = null): array
{
    $storageIds ??= mobile_api_storage_ids($session);
    $storageIds = array_values(array_unique(array_filter(
        array_map('intval', $storageIds),
        static fn (int $storageId): bool => $storageId > 0
    )));
    if ($storageIds === []) {
        return [];
    }

    if (($session['role'] ?? '') === 'owner') {
        return array_fill_keys($storageIds, 'owner');
    }

    $roles = array_fill_keys($storageIds, 'member');
    $rows = Database::fetchAll(
        'SELECT storage_id, access_role
         FROM user_storage_assignments
         WHERE user_id = ? AND storage_id IN (' . implode(',', array_fill(0, count($storageIds), '?')) . ')',
        array_merge([(int) $session['user_id']], $storageIds)
    );
    foreach ($rows as $row) {
        $storageId = (int) $row['storage_id'];
        $roles[$storageId] = (string) ($row['access_role'] ?? 'member') === 'owner' ? 'owner' : 'member';
    }

    ksort($roles);

    return $roles;
}

function mobile_api_require_storage(array $session, int $storageId): void
{
    if ($storageId <= 0 || !in_array($storageId, mobile_api_storage_ids($session), true)) {
        throw new MobileApiException('storage_forbidden', 'This storage is not assigned to you.', 403);
    }
}

function mobile_api_assert_expected_balance(int $itemId, int $storageId, mixed $expectedBalance): float
{
    if ($expectedBalance === null || $expectedBalance === '') {
        throw new MobileApiException(
            'validation_failed',
            'The stock snapshot is missing. Refresh the item and try again.',
            422,
            ['expected_balance' => ['Required.']]
        );
    }

    $row = Database::fetch(
        'SELECT quantity FROM item_storage_balances WHERE item_id = :item_id AND storage_id = :storage_id FOR UPDATE',
        ['item_id' => $itemId, 'storage_id' => $storageId]
    );
    $currentBalance = inventory_quantity((float) ($row['quantity'] ?? 0));
    $expected = inventory_quantity((float) $expectedBalance);
    if (abs($currentBalance - $expected) > inventory_quantity_tolerance()) {
        throw new MobileApiException(
            'balance_changed',
            'Stock changed since this item was reviewed. Refresh and confirm the latest quantity.',
            409,
            ['expected_balance' => [
                'Expected ' . format_quantity($expected) . '; latest ' . format_quantity($currentBalance) . '.',
            ]],
            true,
            [
                'item_id' => $itemId,
                'storage_id' => $storageId,
                'expected_balance' => $expected,
                'current_balance' => $currentBalance,
            ]
        );
    }

    return $currentBalance;
}

function mobile_api_random_token(): string
{
    return rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
}

function mobile_api_operation(array $session, string $type, array $payload, callable $callback): array
{
    mobile_api_enforce_mutation_rate_limit($session);
    $operationId = trim((string) ($payload['client_operation_id'] ?? ''));
    if ($operationId === '' || strlen($operationId) > 80) {
        throw new MobileApiException('invalid_operation_id', 'A valid client_operation_id is required.', 422, ['client_operation_id' => ['Required.']]);
    }
    $existing = Database::fetch('SELECT * FROM mobile_operations WHERE client_operation_id = :id LIMIT 1', ['id' => $operationId]);
    if ($existing) {
        if ((int) $existing['user_id'] !== (int) $session['user_id']) {
            throw new MobileApiException('operation_id_conflict', 'That operation ID belongs to another user.', 409);
        }
        if ($existing['status'] === 'succeeded') {
            return json_decode((string) $existing['response_json'], true) ?: [];
        }
        throw new MobileApiException((string) ($existing['error_code'] ?: 'operation_in_progress'), (string) ($existing['error_message'] ?: 'This operation is already being processed.'), 409, [], true);
    }

    $pdo = Database::connection();
    $pdo->beginTransaction();
    $managerUserId = manager_user_id_for((int) $session['user_id']);
    try {
        $startCursor = inventory_latest_event_cursor();
        $storageId = mobile_api_operation_storage_id($payload);
        Database::execute(
            'INSERT INTO mobile_operations (client_operation_id, user_id, manager_user_id, device_session_id, operation_type, storage_id, status, request_json, ip_address, app_version, created_at)
             VALUES (:operation_id, :user_id, :manager_user_id, :device_id, :operation_type, :storage_id, "pending", :request_json, :ip, :app_version, NOW())',
            [
                'operation_id' => $operationId,
                'user_id' => $session['user_id'],
                'manager_user_id' => $managerUserId,
                'device_id' => $session['id'],
                'operation_type' => $type,
                'storage_id' => $storageId,
                'request_json' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'ip' => auth_request_ip(),
                'app_version' => $session['app_version'],
            ]
        );
        $ledgerId = Database::lastInsertId();
        $result = $callback($ledgerId);
        $entityType = $result['_entity_type'] ?? null;
        $entityId = $result['_entity_id'] ?? null;
        unset($result['_entity_type'], $result['_entity_id']);
        if ($entityType !== null && $entityId !== null) {
            inventory_record_workflow_event(
                (string) $entityType,
                (int) $entityId,
                'mobile.' . $type,
                $storageId,
                (int) $session['user_id'],
                ['mobile_operation_id' => $ledgerId]
            );
        }
        $result['balance_updates'] = mobile_api_authoritative_balance_updates($session, $startCursor);
        $result['sync_cursor'] = inventory_latest_event_cursor();
        Database::execute(
            'UPDATE mobile_operations SET status = "succeeded", entity_type = :entity_type, entity_id = :entity_id, response_json = :response_json, completed_at = NOW() WHERE id = :id',
            [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'response_json' => json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'id' => $ledgerId,
            ]
        );
        $pdo->commit();
        try {
            mobile_api_notify_operation_observers(
                $session,
                $type,
                $payload,
                $entityType !== null ? (string) $entityType : null,
                $entityId !== null ? (int) $entityId : null
            );
        } catch (Throwable $notificationException) {
            error_log('[mobile-api] Could not notify operation observers: ' . $notificationException->getMessage());
        }
        return $result;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $conflict = $exception instanceof MobileApiException
            ? $exception->statusCode === 409
            : str_contains(strtolower($exception->getMessage()), 'balance')
                || str_contains(strtolower($exception->getMessage()), 'negative');
        try {
            $safeErrorMessage = $exception instanceof MobileApiException
                ? $exception->getMessage()
                : ($conflict
                    ? 'Stock changed. Refresh the current balance and confirm again.'
                    : 'The operation could not be completed.');
            if (!$exception instanceof MobileApiException) {
                error_log('[mobile-api] Operation ' . $operationId . ' failed: ' . $exception->getMessage());
            }
            $existingFailure = Database::fetch(
                'SELECT id, user_id FROM mobile_operations WHERE client_operation_id = :operation_id LIMIT 1',
                ['operation_id' => $operationId]
            );
            if (!$existingFailure) {
                Database::execute(
                    'INSERT INTO mobile_operations (
                        client_operation_id, user_id, manager_user_id, device_session_id, operation_type, storage_id, status,
                        request_json, error_code, error_message, ip_address, app_version, created_at, completed_at
                     ) VALUES (
                        :operation_id, :user_id, :manager_user_id, :device_id, :operation_type, :storage_id, :status,
                        :request_json, :error_code, :error_message, :ip, :app_version, NOW(), NOW()
                     )',
                    [
                        'operation_id' => $operationId,
                        'user_id' => $session['user_id'],
                        'manager_user_id' => $managerUserId,
                        'device_id' => $session['id'],
                        'operation_type' => $type,
                        'storage_id' => mobile_api_operation_storage_id($payload),
                        'status' => $conflict ? 'conflict' : 'failed',
                        'request_json' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        'error_code' => $exception instanceof MobileApiException ? $exception->errorCode : ($conflict ? 'balance_changed' : 'operation_failed'),
                        'error_message' => substr($safeErrorMessage, 0, 255),
                        'ip' => auth_request_ip(),
                        'app_version' => $session['app_version'],
                    ]
                );
            }
        } catch (Throwable $logException) {
            error_log('[mobile-api] Could not persist failed operation: ' . $logException->getMessage());
        }
        throw $exception;
    }
}

function mobile_api_authoritative_balance_updates(array $session, int $afterCursor): array
{
    $storageIds = mobile_api_storage_ids($session);
    if ($storageIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($storageIds), '?'));
    $rows = Database::fetchAll(
        'SELECT DISTINCT event.item_id, event.storage_id
         FROM inventory_change_events event
         WHERE event.id > ?
           AND event.item_id IS NOT NULL
           AND event.storage_id IN (' . $placeholders . ')
         ORDER BY event.item_id ASC, event.storage_id ASC',
        array_merge([$afterCursor], $storageIds)
    );

    $updates = [];
    foreach ($rows as $row) {
        $itemId = (int) ($row['item_id'] ?? 0);
        $storageId = (int) ($row['storage_id'] ?? 0);
        if ($itemId <= 0 || $storageId <= 0) {
            continue;
        }
        $balance = Database::fetch(
            'SELECT item.name, item.sku, item.unit, item.is_active,
                    COALESCE(balance.quantity, 0) AS storage_balance
             FROM items item
             LEFT JOIN item_storage_balances balance
               ON balance.item_id = item.id AND balance.storage_id = :storage_id
             WHERE item.id = :item_id
             LIMIT 1',
            ['item_id' => $itemId, 'storage_id' => $storageId]
        );
        if (!$balance) {
            continue;
        }
        $updates[] = [
            'item_id' => $itemId,
            'storage_id' => $storageId,
            'storage_balance' => (float) $balance['storage_balance'],
            'item_name' => (string) $balance['name'],
            'sku' => (string) $balance['sku'],
            'unit' => (string) $balance['unit'],
            'active' => (int) $balance['is_active'] === 1,
        ];
    }

    return $updates;
}

function mobile_api_operation_storage_id(array $payload): ?int
{
    foreach (['storage_id', 'source_storage_id', 'destination_storage_id'] as $key) {
        if (isset($payload[$key]) && (int) $payload[$key] > 0) {
            return (int) $payload[$key];
        }
    }

    $lines = $payload['lines'] ?? [];
    if (is_array($lines)) {
        foreach ($lines as $line) {
            if (is_array($line) && isset($line['storage_id']) && (int) $line['storage_id'] > 0) {
                return (int) $line['storage_id'];
            }
        }
    }

    return null;
}

function mobile_api_operation_storage_ids(array $payload): array
{
    $storageIds = [];
    foreach (['storage_id', 'source_storage_id', 'destination_storage_id'] as $key) {
        if (isset($payload[$key]) && (int) $payload[$key] > 0) {
            $storageIds[] = (int) $payload[$key];
        }
    }

    $lines = $payload['lines'] ?? [];
    if (is_array($lines)) {
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            foreach (['storage_id', 'source_storage_id', 'destination_storage_id'] as $key) {
                if (isset($line[$key]) && (int) $line[$key] > 0) {
                    $storageIds[] = (int) $line[$key];
                }
            }
        }
    }

    return array_values(array_unique(array_filter($storageIds)));
}

function mobile_api_notify_operation_observers(
    array $session,
    string $type,
    array $payload,
    ?string $entityType,
    ?int $entityId
): void {
    $actorUserId = (int) ($session['user_id'] ?? 0);
    if ($actorUserId <= 0) {
        return;
    }

    $actorName = trim((string) ($session['user_name'] ?? $session['name'] ?? ''));
    if ($actorName === '') {
        $actorName = (string) (Database::scalar(
            'SELECT name FROM users WHERE id = :id LIMIT 1',
            ['id' => $actorUserId]
        ) ?: 'A staff member');
    }

    $storageIds = mobile_api_operation_storage_ids($payload);
    $relatedUserIds = [];
    $reference = '';
    $actionUrl = url('/movements');
    $contextType = $entityType;
    $contextId = $entityId;

    if ($entityType === 'handover' && $entityId !== null && $entityId > 0) {
        $handover = Database::fetch(
            'SELECT handover_number, source_storage_id, destination_storage_id, recipient_user_id, created_by
             FROM handovers WHERE id = :id LIMIT 1',
            ['id' => $entityId]
        );
        if ($handover) {
            $reference = (string) ($handover['handover_number'] ?? '');
            $storageIds = array_merge($storageIds, array_filter([
                (int) ($handover['source_storage_id'] ?? 0),
                (int) ($handover['destination_storage_id'] ?? 0),
            ]));
            $relatedUserIds = array_filter([
                (int) ($handover['recipient_user_id'] ?? 0),
                (int) ($handover['created_by'] ?? 0),
            ]);
            $actionUrl = url('/handovers/' . $entityId);
        }
    }

    $label = match (true) {
        str_contains($type, 'usage') => 'reported stock usage',
        str_contains($type, 'restock') => 'added stock',
        str_contains($type, 'transfer') => 'started a stock transfer',
        str_contains($type, 'handover') => 'updated a handover',
        str_contains($type, 'custody') => 'updated staff custody',
        default => 'completed a mobile inventory action',
    };
    $title = $actorName . ' ' . $label;
    $message = $title . ($reference !== '' ? ' for ' . $reference : '') . '.';

    notify_workflow_observers(
        $actorUserId,
        array_values(array_unique($storageIds)),
        'mobile_operation_' . preg_replace('/[^a-z0-9]+/i', '_', strtolower($type)),
        $title,
        $message,
        $actionUrl,
        $contextType,
        $contextId,
        [],
        $relatedUserIds
    );
}

function mobile_api_completed_operation(array $session, array $payload): ?array
{
    $operationId = trim((string) ($payload['client_operation_id'] ?? ''));
    if ($operationId === '') {
        return null;
    }

    $existing = Database::fetch(
        'SELECT user_id, status, response_json
         FROM mobile_operations
         WHERE client_operation_id = :operation_id
         LIMIT 1',
        ['operation_id' => $operationId]
    );

    if (!$existing || (string) $existing['status'] !== 'succeeded') {
        return null;
    }
    if ((int) $existing['user_id'] !== (int) $session['user_id']) {
        throw new MobileApiException('operation_id_conflict', 'That operation ID belongs to another user.', 409);
    }

    $decoded = json_decode((string) $existing['response_json'], true);
    return is_array($decoded) ? $decoded : [];
}

function mobile_api_absolute_url(?string $path): ?string
{
    if ($path === null || $path === '') {
        return null;
    }
    $scheme = request_is_secure() ? 'https' : 'http';
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    return $host !== '' ? $scheme . '://' . $host . $path : $path;
}
