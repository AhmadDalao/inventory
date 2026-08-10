<?php
declare(strict_types=1);

final class MobileApiException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $statusCode = 422,
        public readonly array $fields = [],
        public readonly bool $retryable = false
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

function mobile_api_error(string $code, string $message, int $status = 422, array $fields = [], bool $retryable = false): never
{
    json_response([
        'data' => null,
        'meta' => [],
        'error' => ['code' => $code, 'message' => $message, 'fields' => $fields, 'retryable' => $retryable],
    ], $status);
}

function mobile_api_run(callable $handler): never
{
    try {
        $handler();
        mobile_api_success();
    } catch (MobileApiException $exception) {
        mobile_api_error($exception->errorCode, $exception->getMessage(), $exception->statusCode, $exception->fields, $exception->retryable);
    } catch (RuntimeException $exception) {
        $message = $exception->getMessage();
        $conflict = str_contains(strtolower($message), 'negative') || str_contains(strtolower($message), 'balance');
        mobile_api_error($conflict ? 'balance_changed' : 'operation_failed', $message, $conflict ? 409 : 422, [], $conflict);
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
    $access = mobile_api_employee_access((int) $session['user_id'], (string) ($session['role'] ?? ''));
    if ((int) ($access['enabled'] ?? 0) !== 1) {
        throw new MobileApiException('mobile_access_revoked', 'Mobile access is not enabled for this employee.', 403);
    }
    return $access;
}

function mobile_api_require_capability(array $session, string $capability): void
{
    $column = match ($capability) {
        'usage' => 'can_usage',
        'restock' => 'can_restock',
        'transfer' => 'can_transfer',
        'handover' => 'can_handover',
        'custody' => 'can_custody',
        default => throw new MobileApiException('invalid_capability', 'Unknown mobile capability.', 500),
    };
    $access = mobile_api_require_employee_access($session);
    if ((int) ($access[$column] ?? 0) !== 1) {
        throw new MobileApiException('mobile_capability_denied', 'This mobile action is not enabled for your account.', 403);
    }
}

function mobile_api_enforce_mutation_rate_limit(array $session, int $limit = 120): void
{
    $recent = (int) Database::scalar(
        'SELECT COUNT(*) FROM mobile_operations WHERE user_id = :user_id AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)',
        ['user_id' => $session['user_id']]
    );
    if ($recent >= $limit) {
        throw new MobileApiException('rate_limited', 'Too many mobile operations. Wait a moment and retry.', 429, [], true);
    }
}

function mobile_api_permissions(int $userId): array
{
    return Auth::permissionsForUserId($userId);
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
    $currentBalance = round((float) ($row['quantity'] ?? 0), 2);
    $expected = round((float) $expectedBalance, 2);
    if (abs($currentBalance - $expected) > 0.005) {
        throw new MobileApiException(
            'balance_changed',
            'Stock changed since this item was reviewed. Refresh and confirm the latest quantity.',
            409,
            ['expected_balance' => [
                'Expected ' . format_quantity($expected) . '; latest ' . format_quantity($currentBalance) . '.',
            ]],
            true
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
    try {
        Database::execute(
            'INSERT INTO mobile_operations (client_operation_id, user_id, device_session_id, operation_type, status, request_json, ip_address, app_version, created_at)
             VALUES (:operation_id, :user_id, :device_id, :operation_type, "pending", :request_json, :ip, :app_version, NOW())',
            [
                'operation_id' => $operationId,
                'user_id' => $session['user_id'],
                'device_id' => $session['id'],
                'operation_type' => $type,
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
            $existingFailure = Database::fetch(
                'SELECT id, user_id FROM mobile_operations WHERE client_operation_id = :operation_id LIMIT 1',
                ['operation_id' => $operationId]
            );
            if (!$existingFailure) {
                Database::execute(
                    'INSERT INTO mobile_operations (
                        client_operation_id, user_id, device_session_id, operation_type, status,
                        request_json, error_code, error_message, ip_address, app_version, created_at, completed_at
                     ) VALUES (
                        :operation_id, :user_id, :device_id, :operation_type, :status,
                        :request_json, :error_code, :error_message, :ip, :app_version, NOW(), NOW()
                     )',
                    [
                        'operation_id' => $operationId,
                        'user_id' => $session['user_id'],
                        'device_id' => $session['id'],
                        'operation_type' => $type,
                        'status' => $conflict ? 'conflict' : 'failed',
                        'request_json' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        'error_code' => $exception instanceof MobileApiException ? $exception->errorCode : ($conflict ? 'balance_changed' : 'operation_failed'),
                        'error_message' => substr($exception->getMessage(), 0, 255),
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
