<?php
declare(strict_types=1);

// Domain module: handover recovery and owner status override handlers.

function handle_handovers_recover_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requireOwner();
    verify_csrf();

    $handover = find_handover_or_abort((int) $params['id']);
    $user = Auth::user();
    $lines = handover_lines((int) $handover['id']);
    $targetStatus = handover_recovery_target_status($handover, $lines);
    $blockReason = handover_recovery_block_reason($handover, $lines, $user);

    if ($targetStatus === null || $blockReason !== null) {
        flash('danger', $blockReason ?? 'This handover cannot be recovered.');
        redirect('/handovers/' . $handover['id']);
    }

    $notes = trim((string) input('status_notes'));
    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        if ($targetStatus !== 'requested') {
            issue_handover_inventory($handover, $lines, (int) ($user['id'] ?? 0));
        }

        $noteColumn = $targetStatus === 'requested' ? 'request_decision_notes' : 'closed_notes';
        $existingNotes = (string) ($handover[$noteColumn] ?? '');
        $recoveryNote = trim(
            $existingNotes .
            "\n\nRecovered by " . (string) ($user['name'] ?? 'Admin') . ' on ' . date('Y-m-d H:i:s') .
            ($notes !== '' ? ': ' . $notes : '.')
        );

        Database::execute(
            'UPDATE handovers
             SET status = :status,
                 ' . $noteColumn . ' = :status_notes,
                 cancelled_at = NULL,
                 request_rejected_at = NULL,
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'status' => $targetStatus,
                'status_notes' => $recoveryNote !== '' ? $recoveryNote : null,
                'updated_by' => (int) ($user['id'] ?? 0),
                'id' => (int) $handover['id'],
            ]
        );

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/handovers/' . $handover['id']);
    }

    record_activity('handover.recovered', 'handover', (int) $handover['id'], 'Recovered handover ' . $handover['handover_number'], [
        'handover_id' => (int) $handover['id'],
        'handover_number' => (string) $handover['handover_number'],
        'from_status' => (string) $handover['status'],
        'to_status' => $targetStatus,
        'notes' => $notes,
    ]);

    notify_workflow_participants_and_observers(
        (int) ($user['id'] ?? 0),
        [
            (int) ($handover['created_by'] ?? 0),
            (int) ($handover['recipient_user_id'] ?? 0),
            (int) ($handover['approver_user_id'] ?? 0),
        ],
        [(int) ($handover['source_storage_id'] ?? 0), (int) ($handover['destination_storage_id'] ?? 0)],
        'handover_recovered',
        'Handover ' . $handover['handover_number'] . ' recovered',
        ($user['name'] ?? 'Admin') . ' reopened this handover as ' . handover_status_label($targetStatus) . '.',
        url('/handovers/' . $handover['id']),
        'handover',
        (int) $handover['id'],
        [(int) ($handover['recipient_user_id'] ?? 0), (int) ($handover['created_by'] ?? 0)]
    );

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => 'Handover recovered as ' . handover_status_label($targetStatus) . '.',
            'redirect_url' => url('/handovers/' . $handover['id']),
        ]);
    }

    flash('success', 'Handover recovered as ' . handover_status_label($targetStatus) . '.');
    redirect('/handovers/' . $handover['id']);
}

function handle_handovers_status_override_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requireOwner();
    verify_csrf();

    $handover = find_handover_or_abort((int) $params['id']);
    $user = Auth::user();
    $lines = handover_lines((int) $handover['id']);
    $targetStatus = trim((string) input('target_status'));
    $notes = trim((string) input('status_notes'));
    $blockReason = handover_status_override_block_reason($handover, $lines, $targetStatus, $user);

    if ($blockReason !== null) {
        if (request_wants_json()) {
            json_response([
                'ok' => false,
                'message' => $blockReason,
            ], 422);
        }

        flash('danger', $blockReason);
        redirect('/handovers/' . $handover['id']);
    }

    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        apply_handover_status_override($handover, $lines, $targetStatus, (int) ($user['id'] ?? 0), $notes);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if (request_wants_json()) {
            json_response([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        flash('danger', $exception->getMessage());
        redirect('/handovers/' . $handover['id']);
    }

    record_activity('handover.status_override', 'handover', (int) $handover['id'], 'Changed handover status ' . $handover['handover_number'], [
        'handover_id' => (int) $handover['id'],
        'handover_number' => (string) $handover['handover_number'],
        'from_status' => (string) $handover['status'],
        'to_status' => $targetStatus,
        'notes' => $notes,
    ]);

    notify_workflow_participants_and_observers(
        (int) ($user['id'] ?? 0),
        [
            (int) ($handover['created_by'] ?? 0),
            (int) ($handover['recipient_user_id'] ?? 0),
            (int) ($handover['approver_user_id'] ?? 0),
        ],
        [(int) ($handover['source_storage_id'] ?? 0), (int) ($handover['destination_storage_id'] ?? 0)],
        'handover_status_override',
        'Handover ' . $handover['handover_number'] . ' status changed',
        ($user['name'] ?? 'Admin') . ' changed this handover from ' . handover_status_label((string) $handover['status']) . ' to ' . handover_status_label($targetStatus) . '.',
        url('/handovers/' . $handover['id']),
        'handover',
        (int) $handover['id'],
        [(int) ($handover['recipient_user_id'] ?? 0), (int) ($handover['created_by'] ?? 0)]
    );

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => 'Handover status changed to ' . handover_status_label($targetStatus) . '.',
            'redirect_url' => url('/handovers/' . $handover['id']),
        ]);
    }

    flash('success', 'Handover status changed to ' . handover_status_label($targetStatus) . '.');
    redirect('/handovers/' . $handover['id']);
}
