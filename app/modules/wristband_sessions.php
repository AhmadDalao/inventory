<?php
declare(strict_types=1);

function wristband_start_session_for_handover(int $handoverId, int $storageId, int $userId): int
{
    $pdo = Database::connection();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        if (!wristband_api_enabled()) {
            throw new RuntimeException('Wristband API Audit is disabled globally.');
        }
        $integration = Database::fetch(
            'SELECT integration.*, storage.is_active AS storage_active
             FROM wristband_integrations integration
             INNER JOIN storages storage ON storage.id = integration.storage_id
             WHERE integration.storage_id = :storage_id
             LIMIT 1 FOR UPDATE',
            ['storage_id' => $storageId]
        );
        if ($integration === null || (int) $integration['enabled'] !== 1 || (int) $integration['storage_active'] !== 1) {
            throw new RuntimeException('This storage does not have an enabled wristband integration.');
        }
        if (!wristband_handover_has_tracked_items($handoverId)) {
            throw new RuntimeException('API Audit requires at least one handover item with external QR tracking enabled.');
        }
        $handover = Database::fetch(
            'SELECT id, handover_purpose, recipient_type, recipient_user_id, source_storage_id
             FROM handovers WHERE id = :id LIMIT 1 FOR UPDATE',
            ['id' => $handoverId]
        );
        if ($handover === null
            || (string) $handover['handover_purpose'] !== 'temporary_use'
            || (string) $handover['recipient_type'] !== 'staff'
            || (int) ($handover['recipient_user_id'] ?? 0) <= 0
            || (int) $handover['source_storage_id'] !== $storageId) {
            throw new RuntimeException('API Audit requires a temporary-use handover assigned to a staff account.');
        }
        $existing = wristband_session_for_handover($handoverId);
        if ($existing !== null) {
            throw new RuntimeException('This handover already has a wristband tracking session.');
        }
        $active = Database::fetch(
            'SELECT id, session_number FROM wristband_sessions
             WHERE storage_id = :storage_id AND status IN ("active", "paused")
             LIMIT 1 FOR UPDATE',
            ['storage_id' => $storageId]
        );
        if ($active !== null) {
            throw new RuntimeException('This storage already has an active wristband API Audit session: ' . (string) $active['session_number']);
        }

        Database::execute(
            'INSERT INTO wristband_sessions
                (session_number, integration_id, storage_id, handover_id, mode, status, variance_acknowledged,
                 started_at, started_by, updated_by, created_at, updated_at)
             VALUES
                (:session_number, :integration_id, :storage_id, :handover_id, "api_audit", "active", 0,
                 NOW(), :started_by, :updated_by, NOW(), NOW())',
            [
                'session_number' => wristband_new_reference('WBS'),
                'integration_id' => (int) $integration['id'],
                'storage_id' => $storageId,
                'handover_id' => $handoverId,
                'started_by' => $userId,
                'updated_by' => $userId,
            ]
        );
        $sessionId = Database::lastInsertId();
        Database::execute(
            'UPDATE handovers SET wristband_tracking_mode = "api_audit", updated_at = NOW() WHERE id = :id',
            ['id' => $handoverId]
        );
        record_activity('wristband.session.started', 'handover', $handoverId, 'Started wristband API Audit session.', ['session_id' => $sessionId, 'storage_id' => $storageId]);
        if ($ownsTransaction) {
            $pdo->commit();
        }

        return $sessionId;
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function wristband_pause_session(int $sessionId, int $userId, string $reason): void
{
    if ($reason === '') {
        throw new RuntimeException('A pause reason is required.');
    }
    $session = Database::fetch('SELECT * FROM wristband_sessions WHERE id = :id LIMIT 1', ['id' => $sessionId]);
    if ($session === null || !wristband_user_can_control_session($session, $userId)) {
        throw new RuntimeException('You cannot pause this API Audit session.');
    }
    if ((string) $session['status'] !== 'active') {
        throw new RuntimeException('Only an active API Audit session can be paused.');
    }
    Database::execute(
        'UPDATE wristband_sessions
         SET status = "paused", paused_at = NOW(), paused_reason = :reason, updated_by = :updated_by, updated_at = NOW()
         WHERE id = :id',
        ['reason' => $reason, 'updated_by' => $userId, 'id' => $sessionId]
    );
    Database::execute(
        'INSERT INTO wristband_session_periods (session_id, paused_at, paused_by, pause_reason, created_at)
         VALUES (:session_id, NOW(), :paused_by, :pause_reason, NOW())',
        ['session_id' => $sessionId, 'paused_by' => $userId, 'pause_reason' => $reason]
    );
    record_activity('wristband.session.paused', 'handover', (int) $session['handover_id'], 'Paused wristband API Audit.', ['session_id' => $sessionId, 'reason' => $reason]);
}

function wristband_resume_session(int $sessionId, int $userId): void
{
    $session = Database::fetch('SELECT * FROM wristband_sessions WHERE id = :id LIMIT 1', ['id' => $sessionId]);
    if ($session === null || !wristband_user_can_control_session($session, $userId)) {
        throw new RuntimeException('You cannot resume this API Audit session.');
    }
    if ((string) $session['status'] !== 'paused') {
        throw new RuntimeException('Only a paused API Audit session can be resumed.');
    }
    if (!wristband_api_enabled()) {
        throw new RuntimeException('Enable Wristband API Audit globally before resuming.');
    }
    $integration = Database::fetch('SELECT enabled FROM wristband_integrations WHERE id = :id LIMIT 1', ['id' => (int) $session['integration_id']]);
    if ($integration === null || (int) $integration['enabled'] !== 1) {
        throw new RuntimeException('Enable this storage integration before resuming.');
    }
    Database::execute(
        'UPDATE wristband_sessions
         SET status = "active", paused_at = NULL, paused_reason = NULL, resumed_at = NOW(), updated_by = :updated_by, updated_at = NOW()
         WHERE id = :id',
        ['updated_by' => $userId, 'id' => $sessionId]
    );
    Database::execute(
        'UPDATE wristband_session_periods
         SET resumed_at = NOW(), resumed_by = :resumed_by
         WHERE session_id = :session_id AND resumed_at IS NULL
         ORDER BY id DESC LIMIT 1',
        ['resumed_by' => $userId, 'session_id' => $sessionId]
    );
    record_activity('wristband.session.resumed', 'handover', (int) $session['handover_id'], 'Resumed wristband API Audit.', ['session_id' => $sessionId]);
}

function wristband_switch_session_to_manual(int $sessionId, int $userId, string $reason): void
{
    if ($reason === '') {
        throw new RuntimeException('A reason is required when switching permanently to Manual Only.');
    }
    $session = Database::fetch('SELECT * FROM wristband_sessions WHERE id = :id LIMIT 1', ['id' => $sessionId]);
    if ($session === null || !wristband_user_can_control_session($session, $userId)) {
        throw new RuntimeException('You cannot change this API Audit session.');
    }
    if (!in_array((string) $session['status'], ['active', 'paused'], true)) {
        throw new RuntimeException('This session is already closed or manual-only.');
    }
    Database::execute(
        'UPDATE wristband_sessions
         SET status = "manual_only", mode = "manual_only", paused_reason = :reason,
             closed_at = NOW(), updated_by = :updated_by, updated_at = NOW()
         WHERE id = :id',
        ['reason' => $reason, 'updated_by' => $userId, 'id' => $sessionId]
    );
    Database::execute(
        'UPDATE handovers SET wristband_tracking_mode = "manual_only", updated_at = NOW() WHERE id = :id',
        ['id' => (int) $session['handover_id']]
    );
    Database::execute(
        'UPDATE wristband_session_periods
         SET resumed_at = NOW(), resumed_by = :resumed_by
         WHERE session_id = :session_id AND resumed_at IS NULL
         ORDER BY id DESC LIMIT 1',
        ['resumed_by' => $userId, 'session_id' => $sessionId]
    );
    record_activity('wristband.session.manual_only', 'handover', (int) $session['handover_id'], 'Switched wristband tracking to Manual Only.', ['session_id' => $sessionId, 'reason' => $reason]);
}

function wristband_close_session_for_handover(int $handoverId, int $userId = 0): void
{
    $session = wristband_session_for_handover($handoverId);
    if ($session === null || (string) $session['status'] === 'closed') {
        return;
    }
    Database::execute(
        'UPDATE wristband_sessions SET status = "closed", closed_at = NOW(), updated_by = :updated_by, updated_at = NOW() WHERE id = :id',
        ['updated_by' => $userId > 0 ? $userId : null, 'id' => (int) $session['id']]
    );
    Database::execute(
        'UPDATE wristband_session_periods
         SET resumed_at = NOW(), resumed_by = :resumed_by
         WHERE session_id = :session_id AND resumed_at IS NULL
         ORDER BY id DESC LIMIT 1',
        ['resumed_by' => $userId > 0 ? $userId : null, 'session_id' => (int) $session['id']]
    );
    record_activity('wristband.session.closed', 'handover', $handoverId, 'Closed wristband API Audit session.', ['session_id' => (int) $session['id']]);
}

function wristband_accept_paused_event(int $eventId, int $userId): void
{
    $pdo = Database::connection();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $event = Database::fetch(
            'SELECT event.*, session.status AS session_status, session.storage_id, session.handover_id
             FROM wristband_events event
             INNER JOIN wristband_sessions session ON session.id = event.session_id
             WHERE event.id = :id LIMIT 1 FOR UPDATE',
            ['id' => $eventId]
        );
        if ($event === null || (string) $event['status'] !== 'paused' || $event['resolved_at'] !== null) {
            throw new RuntimeException('This paused event is no longer available for acceptance.');
        }
        if ((string) $event['session_status'] !== 'active') {
            throw new RuntimeException('Resume the API Audit session before accepting paused events.');
        }
        $session = Database::fetch('SELECT * FROM wristband_sessions WHERE id = :id LIMIT 1', ['id' => (int) $event['session_id']]);
        if ($session === null || !wristband_user_can_control_session($session, $userId)) {
            throw new RuntimeException('You cannot resolve this paused event.');
        }
        $code = Database::fetch(
            'SELECT code.*, item.external_qr_tracking_enabled, item.measurement_dimension,
                    item.is_active AS item_active, item.deleted_at AS item_deleted_at
             FROM wristband_codes code
             INNER JOIN items item ON item.id = code.item_id
             WHERE code.code_hash = :code_hash LIMIT 1 FOR UPDATE',
            ['code_hash' => (string) $event['code_hash']]
        );
        if ($code === null
            || (string) $code['state'] !== 'available'
            || (int) $code['external_qr_tracking_enabled'] !== 1
            || (string) $code['measurement_dimension'] !== 'count'
            || (int) $code['item_active'] !== 1
            || $code['item_deleted_at'] !== null) {
            throw new RuntimeException('The wristband code is no longer available.');
        }
        if (!in_array((int) $code['item_id'], wristband_session_item_ids((int) $session['id']), true)) {
            throw new RuntimeException('This wristband item is not part of the handover.');
        }
        Database::execute(
            'UPDATE wristband_events
             SET status = "accepted", code_id = :code_id, item_id = :item_id,
                 resolution_reason = "Accepted after pause", resolved_by = :resolved_by, resolved_at = NOW()
             WHERE id = :id',
            ['code_id' => (int) $code['id'], 'item_id' => (int) $code['item_id'], 'resolved_by' => $userId, 'id' => $eventId]
        );
        Database::execute(
            'UPDATE wristband_codes
             SET state = "used", used_session_id = :session_id, used_event_id = :event_id, used_at = NOW(), updated_at = NOW()
             WHERE id = :id',
            ['session_id' => (int) $session['id'], 'event_id' => $eventId, 'id' => (int) $code['id']]
        );
        if ($ownsTransaction) {
            $pdo->commit();
        }
        record_activity('wristband.event.accepted_after_pause', 'handover', (int) $session['handover_id'], 'Accepted a paused wristband event.', ['event_id' => $eventId]);
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function wristband_discard_event(int $eventId, int $userId, string $reason): void
{
    if ($reason === '') {
        throw new RuntimeException('A discard reason is required.');
    }
    $event = Database::fetch(
        'SELECT event.*, integration.storage_id,
                COALESCE(session.handover_id, event.handover_id) AS linked_handover_id
         FROM wristband_events event
         INNER JOIN wristband_integrations integration ON integration.id = event.integration_id
         LEFT JOIN wristband_sessions session ON session.id = event.session_id
         WHERE event.id = :id LIMIT 1',
        ['id' => $eventId]
    );
    $discardableStatuses = ['paused', 'unknown_code', 'inactive_session', 'item_not_eligible', 'wrong_handover'];
    if ($event === null || $event['resolved_at'] !== null || !in_array((string) $event['status'], $discardableStatuses, true)) {
        throw new RuntimeException('This event cannot be discarded.');
    }
    if (!user_is_global_owner($userId)
        && ((int) ($event['storage_id'] ?? 0) <= 0 || !storage_is_owned_by_user((int) $event['storage_id'], $userId))) {
        throw new RuntimeException('You cannot discard this event.');
    }
    Database::execute(
        'UPDATE wristband_events
         SET status = "discarded", resolution_reason = :reason, resolved_by = :resolved_by, resolved_at = NOW()
         WHERE id = :id',
        ['reason' => $reason, 'resolved_by' => $userId, 'id' => $eventId]
    );
    record_activity('wristband.event.discarded', 'handover', (int) ($event['linked_handover_id'] ?? 0), 'Discarded a wristband API event.', ['event_id' => $eventId, 'reason' => $reason]);
}

function wristband_reverse_event(int $eventId, int $userId, string $reason): void
{
    if (!user_is_global_owner($userId) || $reason === '') {
        throw new RuntimeException('Only an owner can reverse an accepted wristband event, with a reason.');
    }
    $pdo = Database::connection();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $event = Database::fetch('SELECT * FROM wristband_events WHERE id = :id LIMIT 1 FOR UPDATE', ['id' => $eventId]);
        if ($event === null || (string) $event['status'] !== 'accepted' || (int) ($event['code_id'] ?? 0) <= 0) {
            throw new RuntimeException('Only an accepted wristband event can be reversed.');
        }
        $code = Database::fetch('SELECT * FROM wristband_codes WHERE id = :id LIMIT 1 FOR UPDATE', ['id' => (int) $event['code_id']]);
        if ($code === null || (int) ($code['used_event_id'] ?? 0) !== $eventId) {
            throw new RuntimeException('The wristband code no longer points to this event.');
        }
        Database::execute(
            'UPDATE wristband_codes
             SET state = "available", used_session_id = NULL, used_event_id = NULL, used_at = NULL, updated_at = NOW()
             WHERE id = :id',
            ['id' => (int) $code['id']]
        );
        Database::execute(
            'UPDATE wristband_events
             SET status = "reversed", resolution_reason = :reason, resolved_by = :resolved_by, resolved_at = NOW()
             WHERE id = :id',
            ['reason' => $reason, 'resolved_by' => $userId, 'id' => $eventId]
        );
        if ($ownsTransaction) {
            $pdo->commit();
        }
        record_activity('wristband.event.reversed', 'handover', (int) ($event['handover_id'] ?? 0), 'Reversed an accepted wristband event.', ['event_id' => $eventId, 'reason' => $reason]);
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}
