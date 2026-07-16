<?php
declare(strict_types=1);

// Domain module: request cancellation, recovery, and void handlers. Function names are preserved for route compatibility.

function handle_requests_cancel_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requireLogin();
    verify_csrf();

    $request = find_request_or_abort((int) $params['id']);
    $user = Auth::user();
    $cancelBlockReason = request_cancel_block_reason($request, $user);

    if ($cancelBlockReason !== null) {
        flash('danger', $cancelBlockReason);
        redirect('/requests/' . $request['id']);
    }

    $decisionNotes = trim((string) input('decision_notes'));

    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        if (in_array((string) $request['status'], ['approved', 'receipt_review'], true)) {
            $transitStorageId = system_storage_id('request_transit');

            foreach (request_lines((int) $request['id']) as $line) {
                if ((float) $line['quantity_approved'] <= 0) {
                    continue;
                }

                $item = find_item_or_abort((int) $line['item_id']);

                apply_inventory_movement(
                    $item,
                    'transfer',
                    (float) $line['quantity_approved'],
                    $transitStorageId,
                    (int) $request['source_storage_id'],
                    date('Y-m-d H:i:s'),
                    (string) $request['request_number'],
                    'Cancelled request returned from transit.',
                    (int) $user['id'],
                    'request',
                    (int) $request['id']
                );
            }
        }

        Database::execute(
            'UPDATE item_requests
             SET status = "cancelled",
                 decision_notes = :decision_notes,
                 cancelled_at = NOW(),
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'decision_notes' => $decisionNotes !== '' ? $decisionNotes : null,
                'updated_by' => (int) $user['id'],
                'id' => (int) $request['id'],
            ]
        );

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/requests/' . $request['id']);
    }

    $notificationUserIds = array_values(array_unique(array_filter([
        (int) ($request['requester_user_id'] ?? 0),
        (int) ($request['approver_user_id'] ?? 0),
    ], static fn (int $id): bool => $id > 0 && $id !== (int) ($user['id'] ?? 0))));

    foreach ($notificationUserIds as $notificationUserId) {
        create_notification(
            $notificationUserId,
            'request_cancelled',
            'Request ' . $request['request_number'] . ' cancelled',
            ($user['name'] ?? 'Someone') . ' cancelled this request.' . ($decisionNotes !== '' ? ' ' . $decisionNotes : ''),
            url('/requests/' . $request['id']),
            'request',
            (int) $request['id'],
            (int) ($user['id'] ?? 0)
        );
    }

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => 'Request cancelled.',
            'redirect_url' => url('/requests/' . $request['id']),
        ]);
    }

    flash('success', 'Request cancelled.');
    redirect('/requests/' . $request['id']);
}

function handle_requests_recover_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requireOwner();
    verify_csrf();

    $request = find_request_or_abort((int) $params['id']);
    $user = Auth::user();
    $lines = request_lines((int) $request['id']);
    $targetStatus = request_recovery_target_status($request, $lines);
    $blockReason = request_recovery_block_reason($request, $lines, $user);

    if ($targetStatus === null || $blockReason !== null) {
        flash('danger', $blockReason ?? 'This request cannot be recovered.');
        redirect('/requests/' . $request['id']);
    }

    $notes = trim((string) input('status_notes'));
    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        if (in_array($targetStatus, ['approved', 'receipt_review'], true)) {
            issue_request_inventory($request, $lines, (int) ($user['id'] ?? 0));
        }

        $existingNotes = (string) ($request['decision_notes'] ?? '');
        $recoveryNote = trim(
            $existingNotes .
            "\n\nRecovered by " . (string) ($user['name'] ?? 'Admin') . ' on ' . date('Y-m-d H:i:s') .
            ($notes !== '' ? ': ' . $notes : '.')
        );

        Database::execute(
            'UPDATE item_requests
             SET status = :status,
                 decision_notes = :decision_notes,
                 cancelled_at = NULL,
                 rejected_at = NULL,
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'status' => $targetStatus,
                'decision_notes' => $recoveryNote !== '' ? $recoveryNote : null,
                'updated_by' => (int) ($user['id'] ?? 0),
                'id' => (int) $request['id'],
            ]
        );

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/requests/' . $request['id']);
    }

    record_activity('request.recovered', 'request', (int) $request['id'], 'Recovered request ' . $request['request_number'], [
        'request_id' => (int) $request['id'],
        'request_number' => (string) $request['request_number'],
        'from_status' => (string) $request['status'],
        'to_status' => $targetStatus,
        'notes' => $notes,
    ]);

    $notificationUserIds = array_values(array_unique(array_filter([
        (int) ($request['requester_user_id'] ?? 0),
        (int) ($request['approver_user_id'] ?? 0),
    ], static fn (int $id): bool => $id > 0 && $id !== (int) ($user['id'] ?? 0))));

    foreach ($notificationUserIds as $notificationUserId) {
        create_notification(
            $notificationUserId,
            'request_recovered',
            'Request ' . $request['request_number'] . ' recovered',
            ($user['name'] ?? 'Admin') . ' reopened this request as ' . request_status_label($targetStatus) . '.',
            url('/requests/' . $request['id']),
            'request',
            (int) $request['id'],
            (int) ($user['id'] ?? 0)
        );
    }

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => 'Request recovered as ' . request_status_label($targetStatus) . '.',
            'redirect_url' => url('/requests/' . $request['id']),
        ]);
    }

    flash('success', 'Request recovered as ' . request_status_label($targetStatus) . '.');
    redirect('/requests/' . $request['id']);
}

function handle_requests_void_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requireOwner();
    verify_csrf();

    $request = find_request_or_abort((int) $params['id']);
    $user = Auth::user();
    $blockReason = workflow_void_block_reason('request', $request, $user);

    if ($blockReason !== null) {
        flash('danger', $blockReason);
        redirect('/requests/' . $request['id']);
    }

    $confirm = trim((string) input('void_confirm'));
    $notes = trim((string) input('void_notes'));
    $requestNumber = (string) $request['request_number'];

    if ($confirm !== $requestNumber) {
        flash('danger', 'Type the request number exactly to mark it void.');
        redirect('/requests/' . $request['id']);
    }

    if ($notes === '') {
        flash('danger', 'Void reason is required.');
        redirect('/requests/' . $request['id']);
    }

    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        $voidNote = trim(
            ((string) ($request['decision_notes'] ?? '')) .
            "\n\nVoided by " . (string) ($user['name'] ?? 'Owner') . ' on ' . date('Y-m-d H:i:s') . ': ' . $notes
        );

        Database::execute(
            'UPDATE item_requests
             SET status = "cancelled",
                 decision_notes = :decision_notes,
                 cancelled_at = COALESCE(cancelled_at, NOW()),
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'decision_notes' => $voidNote,
                'updated_by' => (int) ($user['id'] ?? 0),
                'id' => (int) $request['id'],
            ]
        );

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/requests/' . $request['id']);
    }

    record_activity('request.voided', 'request', (int) $request['id'], 'Marked request void ' . $requestNumber, [
        'request_id' => (int) $request['id'],
        'request_number' => $requestNumber,
        'reason' => $notes,
    ]);

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => 'Request marked void and kept for audit.',
            'redirect_url' => url('/requests/' . $request['id']),
        ]);
    }

    flash('success', 'Request marked void and kept for audit.');
    redirect('/requests/' . $request['id']);
}
