<?php
declare(strict_types=1);

// Domain module: request approval and rejection handlers. Function names are preserved for route compatibility.

function handle_requests_approve_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('requests.approve');
    verify_csrf();

    $request = find_request_or_abort((int) $params['id']);
    $user = Auth::user();

    $decisionBlockReason = request_decision_block_reason($request, $user);

    if ($decisionBlockReason !== null) {
        flash('danger', $decisionBlockReason);
        redirect('/requests/' . $request['id']);
    }

    $decisionNotes = trim((string) input('decision_notes'));
    $lines = request_lines((int) $request['id']);
    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        $transitStorageId = system_storage_id('request_transit');

        foreach ($lines as $line) {
            $item = find_item_or_abort((int) $line['item_id']);
            $balance = item_storage_balance_record((int) $line['item_id'], (int) $request['source_storage_id']);

            if ($balance === null || (float) $balance['quantity'] < (float) $line['quantity_requested']) {
                throw new RuntimeException($line['item_name'] . ' no longer has enough stock to approve this request.');
            }

            apply_inventory_movement(
                $item,
                'transfer',
                (float) $line['quantity_requested'],
                (int) $request['source_storage_id'],
                $transitStorageId,
                date('Y-m-d H:i:s'),
                (string) $request['request_number'],
                (string) ($request['request_mode'] ?? 'transfer') === 'transfer'
                    ? 'Approved request transfer into transit.'
                    : 'Approved issue request reserved for release.',
                (int) $user['id'],
                'request',
                (int) $request['id']
            );

            Database::execute(
                'UPDATE item_request_lines
                 SET quantity_approved = :quantity_approved,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'quantity_approved' => (float) $line['quantity_requested'],
                    'id' => (int) $line['id'],
                ]
            );
        }

        Database::execute(
            'UPDATE item_requests
             SET status = "approved",
                 decision_notes = :decision_notes,
                 approved_at = NOW(),
                 approved_by = :approved_by,
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'decision_notes' => $decisionNotes !== '' ? $decisionNotes : null,
                'approved_by' => (int) $user['id'],
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

    notify_workflow_participants_and_observers(
        (int) ($user['id'] ?? 0),
        [(int) ($request['requester_user_id'] ?? 0)],
        [(int) ($request['source_storage_id'] ?? 0), (int) ($request['destination_storage_id'] ?? 0)],
        'request_approved',
        'Request ' . $request['request_number'] . ' approved',
        'Your request is now in progress.',
        url('/requests/' . $request['id']),
        'request',
        (int) $request['id'],
        [(int) ($request['requester_user_id'] ?? 0)]
    );

    $successMessage = (string) ($request['request_mode'] ?? 'transfer') === 'transfer'
        ? 'Request approved and moved into transit.'
        : 'Request approved and reserved for release.';

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => $successMessage,
            'redirect_url' => url('/requests/' . $request['id']),
        ]);
    }

    flash('success', $successMessage);
    redirect('/requests/' . $request['id']);
}

function handle_requests_reject_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('requests.approve');
    verify_csrf();

    $request = find_request_or_abort((int) $params['id']);
    $user = Auth::user();

    $decisionBlockReason = request_decision_block_reason($request, $user);

    if ($decisionBlockReason !== null) {
        flash('danger', $decisionBlockReason);
        redirect('/requests/' . $request['id']);
    }

    $decisionNotes = trim((string) input('decision_notes'));

    Database::execute(
        'UPDATE item_requests
         SET status = "rejected",
             decision_notes = :decision_notes,
             rejected_at = NOW(),
             updated_by = :updated_by,
             updated_at = NOW()
         WHERE id = :id',
        [
            'decision_notes' => $decisionNotes !== '' ? $decisionNotes : null,
            'updated_by' => (int) $user['id'],
            'id' => (int) $request['id'],
        ]
    );

    notify_workflow_participants_and_observers(
        (int) ($user['id'] ?? 0),
        [(int) ($request['requester_user_id'] ?? 0)],
        [(int) ($request['source_storage_id'] ?? 0), (int) ($request['destination_storage_id'] ?? 0)],
        'request_rejected',
        'Request ' . $request['request_number'] . ' rejected',
        $decisionNotes !== '' ? $decisionNotes : 'Your request was rejected.',
        url('/requests/' . $request['id']),
        'request',
        (int) $request['id'],
        [(int) ($request['requester_user_id'] ?? 0)]
    );

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => 'Request rejected.',
            'redirect_url' => url('/requests/' . $request['id']),
        ]);
    }

    flash('success', 'Request rejected.');
    redirect('/requests/' . $request['id']);
}
