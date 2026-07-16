<?php
declare(strict_types=1);

// Domain module: approving and rejecting requested handovers.

function handle_handovers_approve_request_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('handovers.approve');
    verify_csrf();

    $handover = find_handover_or_abort((int) $params['id']);
    $user = Auth::user();
    $decisionBlockReason = handover_request_decision_block_reason($handover, $user);

    if ($decisionBlockReason !== null) {
        flash('danger', $decisionBlockReason);
        redirect('/handovers/' . $handover['id']);
    }

    $decisionNotes = trim((string) input('request_decision_notes'));
    $lines = handover_lines((int) $handover['id']);
    $initialStatus = !empty($handover['recipient_user_id']) ? 'awaiting_receipt' : 'delivered';
    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        issue_handover_inventory($handover, $lines, (int) $user['id']);

        Database::execute(
            'UPDATE handovers
             SET status = :status,
                 request_decision_notes = :request_decision_notes,
                 request_approved_at = NOW(),
                 request_approved_by = :request_approved_by,
                 issued_at = NOW(),
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'status' => $initialStatus,
                'request_decision_notes' => $decisionNotes !== '' ? $decisionNotes : null,
                'request_approved_by' => (int) $user['id'],
                'updated_by' => (int) $user['id'],
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

    if (!empty($handover['recipient_user_id'])) {
        create_notification(
            (int) $handover['recipient_user_id'],
            'handover_request_approved',
            'Handover request ' . $handover['handover_number'] . ' approved',
            'Your request is approved. Confirm the actual received quantity once you get the items.',
            url('/handovers/' . $handover['id']),
            'handover',
            (int) $handover['id'],
            (int) ($user['id'] ?? 0)
        );
    }

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => 'Handover request approved.',
            'redirect_url' => url('/handovers/' . $handover['id']),
        ]);
    }

    flash('success', 'Handover request approved.');
    redirect('/handovers/' . $handover['id']);
}

function handle_handovers_reject_request_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('handovers.approve');
    verify_csrf();

    $handover = find_handover_or_abort((int) $params['id']);
    $user = Auth::user();
    $decisionBlockReason = handover_request_decision_block_reason($handover, $user);

    if ($decisionBlockReason !== null) {
        flash('danger', $decisionBlockReason);
        redirect('/handovers/' . $handover['id']);
    }

    $decisionNotes = trim((string) input('request_decision_notes'));

    Database::execute(
        'UPDATE handovers
         SET status = "rejected",
             request_decision_notes = :request_decision_notes,
             request_rejected_at = NOW(),
             updated_by = :updated_by,
             updated_at = NOW()
         WHERE id = :id',
        [
            'request_decision_notes' => $decisionNotes !== '' ? $decisionNotes : null,
            'updated_by' => (int) $user['id'],
            'id' => (int) $handover['id'],
        ]
    );

    if (!empty($handover['recipient_user_id'])) {
        create_notification(
            (int) $handover['recipient_user_id'],
            'handover_request_rejected',
            'Handover request ' . $handover['handover_number'] . ' rejected',
            $decisionNotes !== '' ? $decisionNotes : 'The storage owner rejected this handover request.',
            url('/handovers/' . $handover['id']),
            'handover',
            (int) $handover['id'],
            (int) ($user['id'] ?? 0)
        );
    }

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => 'Handover request rejected.',
            'redirect_url' => url('/handovers/' . $handover['id']),
        ]);
    }

    flash('success', 'Handover request rejected.');
    redirect('/handovers/' . $handover['id']);
}
