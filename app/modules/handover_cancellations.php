<?php
declare(strict_types=1);

// Domain module: handover cancellation and audited void handlers.

function handle_handovers_cancel_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requireLogin();
    verify_csrf();

    $handover = find_handover_or_abort((int) $params['id']);
    $user = Auth::user();
    $cancelBlockReason = handover_cancel_block_reason($handover, $user);

    if ($cancelBlockReason !== null) {
        flash('danger', $cancelBlockReason);
        redirect('/handovers/' . $handover['id']);
    }

    $cancelNotes = trim((string) input('cancel_notes', (string) input('request_decision_notes')));

    $lines = handover_lines((int) $handover['id']);
    $requestDecisionNotes = (string) ($handover['request_decision_notes'] ?? '');
    $closedNotes = (string) ($handover['closed_notes'] ?? '');

    if ($cancelNotes !== '') {
        if ((string) ($handover['status'] ?? '') === 'requested') {
            $requestDecisionNotes = $cancelNotes;
        } else {
            $closedNotes = $cancelNotes;
        }
    }

    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        cancel_handover_inventory($handover, $lines, (int) ($user['id'] ?? 0));

        Database::execute(
            'UPDATE handovers
             SET status = "cancelled",
                 request_decision_notes = :request_decision_notes,
                 closed_notes = :closed_notes,
                 cancelled_at = NOW(),
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'request_decision_notes' => $requestDecisionNotes !== '' ? $requestDecisionNotes : null,
                'closed_notes' => $closedNotes !== '' ? $closedNotes : null,
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

    notify_workflow_participants_and_observers(
        (int) ($user['id'] ?? 0),
        [
            (int) ($handover['created_by'] ?? 0),
            (int) ($handover['recipient_user_id'] ?? 0),
            (int) ($handover['approver_user_id'] ?? 0),
        ],
        [(int) ($handover['source_storage_id'] ?? 0), (int) ($handover['destination_storage_id'] ?? 0)],
        'handover_cancelled',
        'Handover ' . $handover['handover_number'] . ' cancelled',
        ($user['name'] ?? 'Someone') . ' cancelled this handover.' . ($cancelNotes !== '' ? ' ' . $cancelNotes : ''),
        url('/handovers/' . $handover['id']),
        'handover',
        (int) $handover['id'],
        [(int) ($handover['recipient_user_id'] ?? 0), (int) ($handover['created_by'] ?? 0)]
    );

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => 'Handover cancelled.',
            'redirect_url' => url('/handovers/' . $handover['id']),
        ]);
    }

    flash('success', 'Handover cancelled.');
    redirect('/handovers/' . $handover['id']);
}

function handle_handovers_void_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requireOwner();
    verify_csrf();

    $handover = find_handover_or_abort((int) $params['id']);
    $user = Auth::user();
    $blockReason = workflow_void_block_reason('handover', $handover, $user);

    if ($blockReason !== null) {
        flash('danger', $blockReason);
        redirect('/handovers/' . $handover['id']);
    }

    $confirm = trim((string) input('void_confirm'));
    $notes = trim((string) input('void_notes'));
    $handoverNumber = (string) $handover['handover_number'];

    if ($confirm !== $handoverNumber) {
        flash('danger', 'Type the handover number exactly to mark it void.');
        redirect('/handovers/' . $handover['id']);
    }

    if ($notes === '') {
        flash('danger', 'Void reason is required.');
        redirect('/handovers/' . $handover['id']);
    }

    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        $noteColumn = (string) ($handover['status'] ?? '') === 'requested' ? 'request_decision_notes' : 'closed_notes';
        $existingNote = (string) ($handover[$noteColumn] ?? '');
        $voidNote = trim(
            $existingNote .
            "\n\nVoided by " . (string) ($user['name'] ?? 'Owner') . ' on ' . date('Y-m-d H:i:s') . ': ' . $notes
        );

        Database::execute(
            'UPDATE handovers
             SET status = "cancelled",
                 ' . $noteColumn . ' = :void_notes,
                 cancelled_at = COALESCE(cancelled_at, NOW()),
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'void_notes' => $voidNote,
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

    record_activity('handover.voided', 'handover', (int) $handover['id'], 'Marked handover void ' . $handoverNumber, [
        'handover_id' => (int) $handover['id'],
        'handover_number' => $handoverNumber,
        'reason' => $notes,
    ]);

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => 'Handover marked void and kept for audit.',
            'redirect_url' => url('/handovers/' . $handover['id']),
        ]);
    }

    flash('success', 'Handover marked void and kept for audit.');
    redirect('/handovers/' . $handover['id']);
}
