<?php
declare(strict_types=1);

function wristband_api_require_https(): void
{
    $host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
    if (!request_is_secure() && !in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
        wristband_api_response(426, null, 'https_required', 'This integration accepts HTTPS requests only.');
    }
}

function wristband_api_event_by_idempotency(int $integrationId, string $externalEventId, string $payloadHash): ?array
{
    if ($externalEventId !== '') {
        return Database::fetch(
            'SELECT event.*, session.session_number
             FROM wristband_events event
             LEFT JOIN wristband_sessions session ON session.id = event.session_id
             WHERE event.integration_id = :integration_id AND event.external_event_id = :external_event_id
             LIMIT 1',
            ['integration_id' => $integrationId, 'external_event_id' => $externalEventId]
        );
    }

    return Database::fetch(
        'SELECT event.*, session.session_number
         FROM wristband_events event
         LEFT JOIN wristband_sessions session ON session.id = event.session_id
         WHERE event.integration_id = :integration_id AND event.payload_hash = :payload_hash
         ORDER BY event.id DESC LIMIT 1',
        ['integration_id' => $integrationId, 'payload_hash' => $payloadHash]
    );
}

function wristband_api_event_payload(array $event, bool $duplicate = false): array
{
    return [
        'event_id' => (int) $event['id'],
        'status' => (string) $event['status'],
        'duplicate' => $duplicate,
        'session' => $event['session_number'] ?? null,
        'code' => (string) $event['code_masked'],
        'item_id' => isset($event['item_id']) ? (int) $event['item_id'] : null,
    ];
}

function wristband_api_insert_event(array $values): int
{
    Database::execute(
        'INSERT INTO wristband_events
            (integration_id, session_id, code_id, item_id, handover_id, external_event_id,
             payload_hash, code_hash, code_masked, scanned_at, received_at, request_ip,
             status, resolution_reason, raw_payload, created_at)
         VALUES
            (:integration_id, :session_id, :code_id, :item_id, :handover_id, :external_event_id,
             :payload_hash, :code_hash, :code_masked, :scanned_at, NOW(), :request_ip,
             :status, :resolution_reason, :raw_payload, NOW())',
        [
            'integration_id' => (int) $values['integration_id'],
            'session_id' => $values['session_id'] ?? null,
            'code_id' => $values['code_id'] ?? null,
            'item_id' => $values['item_id'] ?? null,
            'handover_id' => $values['handover_id'] ?? null,
            'external_event_id' => ($values['external_event_id'] ?? '') !== '' ? (string) $values['external_event_id'] : null,
            'payload_hash' => (string) $values['payload_hash'],
            'code_hash' => (string) $values['code_hash'],
            'code_masked' => (string) $values['code_masked'],
            'scanned_at' => $values['scanned_at'] ?? null,
            'request_ip' => (string) ($values['request_ip'] ?? ''),
            'status' => (string) $values['status'],
            'resolution_reason' => $values['resolution_reason'] ?? null,
            'raw_payload' => json_encode($values['raw_payload'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]
    );

    $eventId = Database::lastInsertId();
    $status = (string) $values['status'];
    $handoverId = isset($values['handover_id']) ? (int) $values['handover_id'] : 0;
    record_activity(
        'wristband.event.' . $status,
        $handoverId > 0 ? 'handover' : 'wristband_integration',
        $handoverId > 0 ? $handoverId : (int) $values['integration_id'],
        'Recorded wristband API evidence: ' . str_replace('_', ' ', $status) . '.',
        [
            'event_id' => $eventId,
            'session_id' => $values['session_id'] ?? null,
            'item_id' => $values['item_id'] ?? null,
            'code' => (string) $values['code_masked'],
            'status' => $status,
        ]
    );

    return $eventId;
}

function wristband_api_insert_paused_event(array $values, string $status, string $reason): array
{
    try {
        $eventId = wristband_api_insert_event($values + [
            'status' => $status,
            'resolution_reason' => $reason,
        ]);
        $event = Database::fetch(
            'SELECT event.*, session.session_number
             FROM wristband_events event
             LEFT JOIN wristband_sessions session ON session.id = event.session_id
             WHERE event.id = :id',
            ['id' => $eventId]
        );

        return $event ?? ($values + ['id' => $eventId, 'status' => $status]);
    } catch (Throwable $exception) {
        $existing = wristband_api_event_by_idempotency(
            (int) $values['integration_id'],
            (string) ($values['external_event_id'] ?? ''),
            (string) $values['payload_hash']
        );
        if ($existing === null) {
            throw $exception;
        }
        if (($values['external_event_id'] ?? '') !== ''
            && !hash_equals((string) $existing['payload_hash'], (string) $values['payload_hash'])) {
            wristband_api_response(409, null, 'idempotency_conflict', 'That external event ID was already used with different wristband data.');
        }

        return $existing + ['idempotent_replay' => true];
    }
}

function wristband_api_paused_response(array $values, string $status, string $reason): never
{
    try {
        $event = wristband_api_insert_paused_event($values, $status, $reason);
    } catch (Throwable $exception) {
        error_log('Wristband paused-event failure: ' . $exception->getMessage());
        wristband_api_response(500, null, 'server_error', 'The wristband event could not be recorded safely. Retry with the same external_event_id.');
    }
    $isReplay = (bool) ($event['idempotent_replay'] ?? false);
    unset($event['idempotent_replay']);
    wristband_api_response(
        $isReplay ? 200 : 202,
        wristband_api_event_payload($event, $isReplay),
        $isReplay ? null : 'integration_paused',
        $isReplay ? null : $reason,
        $isReplay ? ['idempotent_replay' => true] : []
    );
}

function handle_wristband_checkin_api(): void
{
    wristband_api_require_https();
    $plainKey = wristband_request_api_key();
    $integration = wristband_integration_by_api_key($plainKey);
    if ($integration === null) {
        wristband_api_response(401, null, 'invalid_api_key', 'The wristband integration key is invalid.');
    }
    $ip = wristband_client_ip();
    if (!wristband_integration_allows_ip($integration, $ip)) {
        wristband_api_response(403, null, 'ip_not_allowed', 'This source IP is not allowed for the integration.');
    }
    $recentCount = (int) Database::scalar(
        'SELECT COUNT(*) FROM wristband_events
         WHERE integration_id = :integration_id AND request_ip = :request_ip
           AND received_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)',
        ['integration_id' => (int) $integration['id'], 'request_ip' => $ip]
    );
    if ($recentCount >= 120) {
        header('Retry-After: 60');
        wristband_api_response(429, null, 'rate_limited', 'Too many wristband events. Retry later.');
    }

    $input = wristband_json_input();
    $normalized = wristband_normalize_code((string) ($input['code'] ?? ''));
    $externalEventId = trim((string) ($input['external_event_id'] ?? ''));
    if (strlen($normalized) < 6 || strlen($normalized) > 128) {
        wristband_api_response(422, null, 'invalid_code', 'A valid wristband code is required.');
    }
    if (strlen($externalEventId) > 190) {
        wristband_api_response(422, null, 'invalid_external_event_id', 'The external event ID is too long.');
    }
    $scannedAt = null;
    $scannedAtRaw = trim((string) ($input['scanned_at'] ?? ''));
    if ($scannedAtRaw !== '') {
        try {
            $scannedAt = (new DateTimeImmutable($scannedAtRaw))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
        } catch (Throwable) {
            wristband_api_response(422, null, 'invalid_scanned_at', 'scanned_at must be an ISO-8601 timestamp.');
        }
    }
    $payloadHash = hash('sha256', implode('|', [(int) $integration['id'], $normalized, $scannedAtRaw, $externalEventId]));
    $existing = wristband_api_event_by_idempotency((int) $integration['id'], $externalEventId, $payloadHash);
    if ($existing !== null) {
        if ($externalEventId !== '' && !hash_equals((string) $existing['payload_hash'], $payloadHash)) {
            wristband_api_response(409, null, 'idempotency_conflict', 'That external event ID was already used with different wristband data.');
        }
        wristband_api_response(200, wristband_api_event_payload($existing, true), null, null, ['idempotent_replay' => true]);
    }

    $session = wristband_active_session_for_storage((int) $integration['storage_id']);
    $baseValues = [
        'integration_id' => (int) $integration['id'],
        'session_id' => $session !== null ? (int) $session['id'] : null,
        'handover_id' => $session !== null ? (int) $session['handover_id'] : null,
        'external_event_id' => $externalEventId,
        'payload_hash' => $payloadHash,
        'code_hash' => wristband_code_hash($normalized),
        'code_masked' => wristband_mask_code($normalized),
        'scanned_at' => $scannedAt,
        'request_ip' => $ip,
        'raw_payload' => [
            'code' => wristband_mask_code($normalized),
            'scanned_at' => $scannedAtRaw,
            'external_event_id' => $externalEventId,
        ],
    ];

    $paused = !wristband_api_enabled()
        || (int) $integration['enabled'] !== 1
        || $session === null
        || (string) ($session['status'] ?? '') === 'paused';
    if ($paused) {
        $status = $session === null ? 'inactive_session' : 'paused';
        $reason = !wristband_api_enabled()
            ? 'Global wristband API checking is disabled.'
            : ((int) $integration['enabled'] !== 1
                ? 'This storage integration is disabled.'
                : ($session === null ? 'No active API Audit session exists for this storage.' : 'The API Audit session is paused.'));
        wristband_api_paused_response($baseValues, $status, $reason);
    }

    $pdo = Database::connection();
    $lockedSession = null;
    $code = null;
    $eventId = 0;
    $pdo->beginTransaction();
    try {
        $lockedSession = Database::fetch(
            'SELECT * FROM wristband_sessions WHERE id = :id LIMIT 1 FOR UPDATE',
            ['id' => (int) $session['id']]
        );
        $lockedIntegration = Database::fetch(
            'SELECT integration.*, storage.is_active AS storage_active
             FROM wristband_integrations integration
             INNER JOIN storages storage ON storage.id = integration.storage_id
             WHERE integration.id = :id LIMIT 1 FOR UPDATE',
            ['id' => (int) $integration['id']]
        );
        $lockedGlobalSetting = Database::fetch(
            'SELECT setting_value FROM app_settings
             WHERE setting_key = "wristbands.api_enabled" LIMIT 1 FOR UPDATE'
        );
        $globalStillEnabled = (string) ($lockedGlobalSetting['setting_value'] ?? '0') === '1';
        $integrationStillEnabled = $lockedIntegration !== null
            && (int) ($lockedIntegration['enabled'] ?? 0) === 1
            && (int) ($lockedIntegration['storage_active'] ?? 0) === 1;
        $sessionStillActive = $lockedSession !== null && (string) $lockedSession['status'] === 'active';
        if (!$globalStillEnabled || !$integrationStillEnabled || !$sessionStillActive) {
            $pdo->rollBack();
            $reason = !$globalStillEnabled
                ? 'Global wristband API checking was disabled before this event could be accepted.'
                : (!$integrationStillEnabled
                    ? 'This storage integration was disabled before this event could be accepted.'
                    : 'The API Audit session stopped before this event could be accepted.');
            wristband_api_paused_response($baseValues, 'paused', $reason);
        }
        $code = Database::fetch(
            'SELECT code.*, item.external_qr_tracking_enabled, item.measurement_dimension,
                    item.is_active AS item_active, item.deleted_at AS item_deleted_at
             FROM wristband_codes code
             INNER JOIN items item ON item.id = code.item_id
             WHERE code.code_hash = :code_hash LIMIT 1 FOR UPDATE',
            ['code_hash' => $baseValues['code_hash']]
        );
        if ($code === null) {
            $eventId = wristband_api_insert_event($baseValues + ['status' => 'unknown_code', 'resolution_reason' => 'Code is not registered.']);
            $pdo->commit();
            $event = Database::fetch('SELECT * FROM wristband_events WHERE id = :id', ['id' => $eventId]);
            wristband_api_response(202, wristband_api_event_payload($event ?? ($baseValues + ['id' => $eventId, 'status' => 'unknown_code'])), 'unknown_code', 'The wristband code is not registered.');
        }
        if ((string) $code['state'] === 'used') {
            $eventId = wristband_api_insert_event($baseValues + [
                'code_id' => (int) $code['id'],
                'item_id' => (int) $code['item_id'],
                'status' => 'duplicate',
                'resolution_reason' => 'Code was already accepted.',
            ]);
            $pdo->commit();
            $event = Database::fetch('SELECT * FROM wristband_events WHERE id = :id', ['id' => $eventId]);
            wristband_api_response(200, wristband_api_event_payload($event ?? ($baseValues + ['id' => $eventId, 'status' => 'duplicate'])), null, null, ['duplicate_code' => true]);
        }
        if ((string) $code['state'] === 'void'
            || (int) $code['external_qr_tracking_enabled'] !== 1
            || (string) $code['measurement_dimension'] !== 'count'
            || (int) $code['item_active'] !== 1
            || $code['item_deleted_at'] !== null) {
            $eventId = wristband_api_insert_event($baseValues + [
                'code_id' => (int) $code['id'],
                'item_id' => (int) $code['item_id'],
                'status' => 'item_not_eligible',
                'resolution_reason' => 'Code is void, inactive, or its item is not an eligible count-based wristband item.',
            ]);
            $pdo->commit();
            $event = Database::fetch('SELECT * FROM wristband_events WHERE id = :id', ['id' => $eventId]);
            wristband_api_response(202, wristband_api_event_payload($event ?? ($baseValues + ['id' => $eventId, 'status' => 'item_not_eligible'])), 'item_not_eligible', 'This wristband is not eligible for API Audit.');
        }
        if (!in_array((int) $code['item_id'], wristband_session_item_ids((int) $lockedSession['id']), true)) {
            $eventId = wristband_api_insert_event($baseValues + [
                'code_id' => (int) $code['id'],
                'item_id' => (int) $code['item_id'],
                'status' => 'wrong_handover',
                'resolution_reason' => 'The mapped item is not part of the active handover.',
            ]);
            $pdo->commit();
            $event = Database::fetch('SELECT * FROM wristband_events WHERE id = :id', ['id' => $eventId]);
            wristband_api_response(202, wristband_api_event_payload($event ?? ($baseValues + ['id' => $eventId, 'status' => 'wrong_handover'])), 'wrong_handover', 'This wristband item is not part of the active handover.');
        }
        $eventId = wristband_api_insert_event($baseValues + [
            'code_id' => (int) $code['id'],
            'item_id' => (int) $code['item_id'],
            'status' => 'accepted',
        ]);
        Database::execute(
            'UPDATE wristband_codes
             SET state = "used", used_session_id = :session_id, used_event_id = :event_id, used_at = NOW(), updated_at = NOW()
             WHERE id = :id',
            ['session_id' => (int) $lockedSession['id'], 'event_id' => $eventId, 'id' => (int) $code['id']]
        );
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $duplicate = wristband_api_event_by_idempotency((int) $integration['id'], $externalEventId, $payloadHash);
        if ($duplicate !== null) {
            wristband_api_response(200, wristband_api_event_payload($duplicate, true), null, null, ['idempotent_replay' => true]);
        }
        error_log('Wristband integration failure: ' . $exception->getMessage());
        wristband_api_response(500, null, 'server_error', 'The wristband event could not be processed safely. Retry with the same external_event_id.');
    }

    if ($lockedSession === null || $code === null || $eventId <= 0) {
        wristband_api_response(500, null, 'server_error', 'The wristband event did not complete safely. Retry with the same external_event_id.');
    }
    $acceptedCount = (int) Database::scalar('SELECT COUNT(*) FROM wristband_events WHERE session_id = :session_id AND status = "accepted"', ['session_id' => (int) $lockedSession['id']]);
    $event = Database::fetch('SELECT event.*, session.session_number FROM wristband_events event LEFT JOIN wristband_sessions session ON session.id = event.session_id WHERE event.id = :id', ['id' => $eventId]);
    wristband_api_response(201, wristband_api_event_payload($event ?? ($baseValues + ['id' => $eventId, 'status' => 'accepted'])) + ['accepted_count' => $acceptedCount]);
}
