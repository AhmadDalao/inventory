<?php
declare(strict_types=1);

function wristband_normalize_code(string $code): string
{
    return strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', trim($code)));
}

function wristband_code_hash(string $code): string
{
    return hash('sha256', wristband_normalize_code($code));
}

function wristband_mask_code(string $code): string
{
    $normalized = wristband_normalize_code($code);
    $length = strlen($normalized);
    if ($length <= 8) {
        return str_repeat('*', max(0, $length - 3)) . substr($normalized, -3);
    }

    return substr($normalized, 0, 4) . str_repeat('*', max(4, $length - 8)) . substr($normalized, -4);
}

function wristband_api_enabled(): bool
{
    return site_setting('wristbands.api_enabled', '0') === '1';
}

function wristband_set_api_enabled(bool $enabled, int $userId): void
{
    Database::execute(
        'INSERT INTO app_settings (setting_key, setting_value, updated_by, updated_at)
         VALUES (:setting_key, :setting_value, :updated_by, NOW())
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by), updated_at = NOW()',
        [
            'setting_key' => 'wristbands.api_enabled',
            'setting_value' => $enabled ? '1' : '0',
            'updated_by' => $userId,
        ]
    );
    site_settings_cache_reset();
}

function wristband_new_reference(string $prefix): string
{
    return strtoupper($prefix) . '-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(2)));
}

function wristband_generate_api_key(): array
{
    $plain = 'kona_wb_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

    return [
        'plain' => $plain,
        'hash' => hash('sha256', $plain),
        'prefix' => substr($plain, 0, 18),
    ];
}

function wristband_request_api_key(): string
{
    $header = trim((string) ($_SERVER['HTTP_X_KONA_API_KEY'] ?? ''));
    if ($header !== '') {
        return $header;
    }
    $authorization = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches) === 1) {
        return trim((string) $matches[1]);
    }

    return '';
}

function wristband_integration_by_api_key(string $plainKey): ?array
{
    if ($plainKey === '') {
        return null;
    }
    $prefix = substr($plainKey, 0, 18);
    $integration = Database::fetch(
        'SELECT integration.*, storage.name AS storage_name, storage.is_active AS storage_active
         FROM wristband_integrations integration
         INNER JOIN storages storage ON storage.id = integration.storage_id
         WHERE integration.api_key_prefix = :prefix
         LIMIT 1',
        ['prefix' => $prefix]
    );
    if ($integration === null || empty($integration['api_key_hash'])) {
        return null;
    }

    return hash_equals((string) $integration['api_key_hash'], hash('sha256', $plainKey)) ? $integration : null;
}

function wristband_ip_matches(string $ip, string $rule): bool
{
    $rule = trim($rule);
    if ($rule === '') {
        return false;
    }
    if (!str_contains($rule, '/')) {
        return hash_equals($rule, $ip);
    }
    [$subnet, $bits] = array_pad(explode('/', $rule, 2), 2, '');
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
        || filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
        || !ctype_digit($bits)) {
        return false;
    }
    $bitsValue = max(0, min(32, (int) $bits));
    $mask = $bitsValue === 0 ? 0 : (-1 << (32 - $bitsValue));

    return ((ip2long($ip) & $mask) === (ip2long($subnet) & $mask));
}

function wristband_integration_allows_ip(array $integration, string $ip): bool
{
    $rules = preg_split('/[\s,]+/', trim((string) ($integration['ip_allowlist'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if ($rules === []) {
        return true;
    }
    foreach ($rules as $rule) {
        if (wristband_ip_matches($ip, (string) $rule)) {
            return true;
        }
    }

    return false;
}

function wristband_json_input(): array
{
    $raw = (string) file_get_contents('php://input');
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        wristband_api_response(422, null, 'invalid_json', 'Send a valid JSON request body.');
    }

    return $decoded;
}

function wristband_api_response(int $status, ?array $data = null, ?string $errorCode = null, ?string $message = null, array $meta = []): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'data' => $data,
        'meta' => $meta,
        'error' => $errorCode === null ? null : ['code' => $errorCode, 'message' => $message],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function wristband_user_can_control_session(array $session, int $userId): bool
{
    return user_is_global_owner($userId)
        || storage_is_owned_by_user((int) $session['storage_id'], $userId);
}

function wristband_evidence_visible_to_current_user(array $handover): bool
{
    $user = Auth::user();
    if ($user === null || !Auth::hasPermission('wristbands.evidence')) {
        return false;
    }
    if ((string) ($user['role'] ?? '') === 'staff' && !in_array((string) $handover['status'], ['pending_approval', 'closed'], true)) {
        return false;
    }

    return user_is_global_owner((int) $user['id'])
        || storage_is_owned_by_user((int) $handover['source_storage_id'], (int) $user['id'])
        || (int) ($handover['approver_user_id'] ?? 0) === (int) $user['id'];
}

function wristband_session_evidence(int $handoverId): ?array
{
    $session = Database::fetch(
        'SELECT session.*, integration.name AS integration_name, storage.name AS storage_name,
                handover.handover_number
         FROM wristband_sessions session
         INNER JOIN wristband_integrations integration ON integration.id = session.integration_id
         INNER JOIN storages storage ON storage.id = session.storage_id
         INNER JOIN handovers handover ON handover.id = session.handover_id
         WHERE session.handover_id = :handover_id
         LIMIT 1',
        ['handover_id' => $handoverId]
    );
    if ($session === null) {
        return null;
    }
    $session['accepted_count'] = (int) Database::scalar(
        'SELECT COUNT(*) FROM wristband_events WHERE session_id = :session_id AND status = "accepted"',
        ['session_id' => (int) $session['id']]
    );
    $session['unresolved_count'] = (int) Database::scalar(
        'SELECT COUNT(*) FROM wristband_events
         WHERE session_id = :session_id
           AND status IN ("paused", "unknown_code", "item_not_eligible", "wrong_handover", "inactive_session")
           AND resolved_at IS NULL',
        ['session_id' => (int) $session['id']]
    );
    $session['periods'] = Database::fetchAll(
        'SELECT period.*, pauser.name AS paused_by_name, resumer.name AS resumed_by_name
         FROM wristband_session_periods period
         LEFT JOIN users pauser ON pauser.id = period.paused_by
         LEFT JOIN users resumer ON resumer.id = period.resumed_by
         WHERE period.session_id = :session_id ORDER BY period.paused_at ASC',
        ['session_id' => (int) $session['id']]
    );

    return $session;
}

function wristband_physical_used_from_line_updates(array $lineUpdates): float
{
    $lineIds = [];
    foreach ($lineUpdates as $line) {
        $lineId = (int) ($line['line_id'] ?? 0);
        if ($lineId > 0) {
            $lineIds[] = $lineId;
        }
    }
    if ($lineIds === []) {
        return 0.0;
    }

    $placeholders = implode(',', array_fill(0, count($lineIds), '?'));
    $trackedIds = array_map(
        static fn (array $row): int => (int) $row['id'],
        Database::fetchAll(
            'SELECT line.id
             FROM handover_lines line
             INNER JOIN items item ON item.id = line.item_id
             WHERE line.id IN (' . $placeholders . ')
               AND item.external_qr_tracking_enabled = 1
               AND item.measurement_dimension = "count"',
            $lineIds
        )
    );
    $trackedLookup = array_fill_keys($trackedIds, true);
    $total = 0.0;
    foreach ($lineUpdates as $line) {
        if (!isset($trackedLookup[(int) ($line['line_id'] ?? 0)])) {
            continue;
        }
        $total += max(0.0, (float) ($line['used'] ?? 0));
    }

    return $total;
}

function wristband_client_ip(): string
{
    return trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
}

function wristband_integration_for_storage(int $storageId): ?array
{
    return Database::fetch(
        'SELECT integration.*, storage.name AS storage_name, storage.is_active AS storage_active
         FROM wristband_integrations integration
         INNER JOIN storages storage ON storage.id = integration.storage_id
         WHERE integration.storage_id = :storage_id
         LIMIT 1',
        ['storage_id' => $storageId]
    );
}

function wristband_active_session_for_storage(int $storageId): ?array
{
    return Database::fetch(
        'SELECT session.*, handover.handover_number
         FROM wristband_sessions session
         INNER JOIN handovers handover ON handover.id = session.handover_id
         WHERE session.storage_id = :storage_id
           AND session.status IN ("active", "paused")
         ORDER BY session.id DESC
         LIMIT 1',
        ['storage_id' => $storageId]
    );
}

function wristband_session_for_handover(int $handoverId): ?array
{
    return Database::fetch(
        'SELECT session.*, handover.handover_number, handover.status AS handover_status,
                handover.handover_purpose, storage.name AS storage_name
         FROM wristband_sessions session
         INNER JOIN handovers handover ON handover.id = session.handover_id
         INNER JOIN storages storage ON storage.id = session.storage_id
         WHERE session.handover_id = :handover_id
         LIMIT 1',
        ['handover_id' => $handoverId]
    );
}

function wristband_handover_has_tracked_items(int $handoverId): bool
{
    return (int) Database::scalar(
        'SELECT COUNT(*)
         FROM handover_lines line
         INNER JOIN items item ON item.id = line.item_id
         WHERE line.handover_id = :handover_id
           AND item.external_qr_tracking_enabled = 1
           AND item.measurement_dimension = "count"',
        ['handover_id' => $handoverId]
    ) > 0;
}

function wristband_session_item_ids(int $sessionId): array
{
    return array_map(
        static fn (array $row): int => (int) $row['item_id'],
        Database::fetchAll(
            'SELECT DISTINCT line.item_id
             FROM wristband_sessions session
             INNER JOIN handover_lines line ON line.handover_id = session.handover_id
             INNER JOIN items item ON item.id = line.item_id
             WHERE session.id = :session_id
               AND item.external_qr_tracking_enabled = 1
               AND item.measurement_dimension = "count"',
            ['session_id' => $sessionId]
        )
    );
}

function wristband_session_physical_used(int $handoverId): float
{
    return (float) Database::scalar(
        'SELECT COALESCE(SUM(line.quantity_used), 0)
         FROM handover_lines line
         INNER JOIN items item ON item.id = line.item_id
         WHERE line.handover_id = :handover_id
           AND item.external_qr_tracking_enabled = 1
           AND item.measurement_dimension = "count"',
        ['handover_id' => $handoverId]
    );
}

function wristband_session_variance(int $handoverId): float
{
    $session = wristband_session_evidence($handoverId);
    if ($session === null) {
        return 0.0;
    }

    return wristband_session_physical_used($handoverId) - (float) $session['accepted_count'];
}

function wristband_review_snapshot(int $handoverId, array $lineUpdates): ?array
{
    $session = wristband_session_evidence($handoverId);
    if ($session === null) {
        return null;
    }

    $physicalUsed = round(wristband_physical_used_from_line_updates($lineUpdates), 4);
    $apiCheckins = (float) ($session['accepted_count'] ?? 0);
    $variance = round($physicalUsed - $apiCheckins, 4);
    $unresolved = (int) ($session['unresolved_count'] ?? 0);

    return [
        'session_id' => (int) $session['id'],
        'handover_id' => $handoverId,
        'mode' => (string) $session['mode'],
        'status' => (string) $session['status'],
        'physical_used_quantity' => $physicalUsed,
        'api_checkins_quantity' => $apiCheckins,
        'variance_quantity' => $variance,
        'unresolved_count' => $unresolved,
        'requires_acknowledgement' => (string) $session['mode'] === 'api_audit'
            && (abs($variance) > 0.0001 || $unresolved > 0),
    ];
}

function wristband_store_review_snapshot(array $snapshot, int $userId, bool $acknowledged, string $note): void
{
    $requiresAcknowledgement = (bool) ($snapshot['requires_acknowledgement'] ?? false);
    $note = trim($note);
    if ($requiresAcknowledgement && (!$acknowledged || $note === '')) {
        throw new RuntimeException('Acknowledge the wristband API variance or unresolved exceptions and add a review note before approval.');
    }

    Database::execute(
        'UPDATE wristband_sessions
         SET physical_used_quantity = :physical_used_quantity,
             api_checkins_quantity = :api_checkins_quantity,
             variance_quantity = :variance_quantity,
             variance_acknowledged = :variance_acknowledged,
             variance_note = :variance_note,
             variance_acknowledged_by = :variance_acknowledged_by,
             variance_acknowledged_at = :variance_acknowledged_at,
             updated_by = :updated_by,
             updated_at = NOW()
         WHERE id = :id',
        [
            'physical_used_quantity' => (float) $snapshot['physical_used_quantity'],
            'api_checkins_quantity' => (float) $snapshot['api_checkins_quantity'],
            'variance_quantity' => (float) $snapshot['variance_quantity'],
            'variance_acknowledged' => $acknowledged ? 1 : 0,
            'variance_note' => $note !== '' ? $note : null,
            'variance_acknowledged_by' => $acknowledged ? $userId : null,
            'variance_acknowledged_at' => $acknowledged ? date('Y-m-d H:i:s') : null,
            'updated_by' => $userId,
            'id' => (int) $snapshot['session_id'],
        ]
    );
    record_activity(
        'wristband.session.reconciled',
        'handover',
        (int) $snapshot['handover_id'],
        'Reviewed wristband API evidence without changing stock quantities.',
        [
            'session_id' => (int) $snapshot['session_id'],
            'physical_used_quantity' => (float) $snapshot['physical_used_quantity'],
            'api_checkins_quantity' => (float) $snapshot['api_checkins_quantity'],
            'variance_quantity' => (float) $snapshot['variance_quantity'],
            'unresolved_count' => (int) $snapshot['unresolved_count'],
            'acknowledged' => $acknowledged,
            'note' => $note,
        ]
    );
}
