<?php
declare(strict_types=1);

// Domain module: request receipt handlers. Function names are preserved for route compatibility.

function handle_requests_receive_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('requests.receive');
    verify_csrf();

    $request = find_request_or_abort((int) $params['id']);
    $user = Auth::user();

    if (!request_can_report_receipt($request, $user)) {
        flash('danger', 'Only the requester can report receipt quantities.');
        redirect('/requests/' . $request['id']);
    }

    if (!in_array((string) ($request['status'] ?? ''), ['approved', 'receipt_review'], true)) {
        flash('danger', 'Only approved requests can accept a receipt report.');
        redirect('/requests/' . $request['id']);
    }

    $lines = request_lines((int) $request['id']);
    $receiptNotes = trim((string) input('receipt_notes'));
    [$receiptUpdates, $receiptErrors, $hasVariance] = build_request_receipt_updates($lines, input('line_received'));
    $proofFile = uploaded_file('proof_image');
    $proofError = validate_workflow_proof_upload($proofFile);

    if ($proofError !== null) {
        if (request_wants_json()) {
            json_response([
                'ok' => false,
                'message' => $proofError,
            ], 422);
        }

        flash('danger', $proofError);
        redirect('/requests/' . $request['id']);
    }

    if ($receiptErrors !== []) {
        $message = implode(' ', array_unique($receiptErrors));

        if (request_wants_json()) {
            json_response([
                'ok' => false,
                'message' => $message,
            ], 422);
        }

        flash('danger', $message);
        redirect('/requests/' . $request['id']);
    }

    $pdo = Database::connection();
    $storedProof = null;

    try {
        if ($proofFile !== null) {
            $storedProof = store_workflow_proof_document($proofFile, 'request', (string) $request['request_number'], 'receipt_report');
        }
    } catch (Throwable $exception) {
        if (request_wants_json()) {
            json_response([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        flash('danger', $exception->getMessage());
        redirect('/requests/' . $request['id']);
    }

    $pdo->beginTransaction();

    try {
        $requiresReceiptReview = (string) $request['status'] === 'receipt_review' || $hasVariance;

        if ($requiresReceiptReview) {
            foreach ($receiptUpdates as $update) {
                Database::execute(
                    'UPDATE item_request_lines
                     SET quantity_received = :quantity_received,
                         updated_at = NOW()
                     WHERE id = :id',
                    [
                        'quantity_received' => (float) $update['received'],
                        'id' => (int) $update['line_id'],
                    ]
                );
            }

            Database::execute(
                'UPDATE item_requests
                 SET status = "receipt_review",
                     receipt_notes = :receipt_notes,
                     receipt_reported_at = NOW(),
                     updated_by = :updated_by,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'receipt_notes' => $receiptNotes !== '' ? $receiptNotes : null,
                    'updated_by' => (int) $user['id'],
                    'id' => (int) $request['id'],
                ]
            );
        } else {
            apply_request_receipt_confirmation_movements($request, $receiptUpdates, (int) $user['id']);

            Database::execute(
                'UPDATE item_requests
                 SET status = "completed",
                     receipt_notes = :receipt_notes,
                     receipt_reported_at = NOW(),
                     completed_at = NOW(),
                     completed_by = :completed_by,
                     updated_by = :updated_by,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'receipt_notes' => $receiptNotes !== '' ? $receiptNotes : null,
                    'completed_by' => (int) $user['id'],
                    'updated_by' => (int) $user['id'],
                    'id' => (int) $request['id'],
                ]
            );
        }

        if ($storedProof !== null) {
            create_workflow_document_record(
                'request',
                (int) $request['id'],
                (string) $request['request_number'],
                'proof_image',
                'receipt_report',
                $storedProof,
                (int) $user['id']
            );
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($storedProof !== null) {
            delete_workflow_document_file((string) $storedProof['stored_filename']);
        }

        flash('danger', $exception->getMessage());
        redirect('/requests/' . $request['id']);
    }

    if ((string) $request['status'] === 'receipt_review' || $hasVariance) {
        create_notification(
            (int) $request['approver_user_id'],
            'request_receipt_review',
            'Receipt report ready for ' . $request['request_number'],
            ($user['name'] ?? 'Requester') . ' reported actual received quantities for review.',
            url('/requests/' . $request['id']),
            'request',
            (int) $request['id'],
            (int) ($user['id'] ?? 0)
        );
    } else {
        create_notification(
            (int) $request['approver_user_id'],
            'request_completed',
            'Request ' . $request['request_number'] . ' completed',
            ($user['name'] ?? 'Requester') . ' confirmed exact receipt.',
            url('/requests/' . $request['id']),
            'request',
            (int) $request['id'],
            (int) ($user['id'] ?? 0)
        );
    }

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => ((string) $request['status'] === 'receipt_review' || $hasVariance)
                ? 'Receipt report saved. Waiting for approver confirmation.'
                : 'Request completed with the reported received quantities.',
            'redirect_url' => url('/requests/' . $request['id']),
        ]);
    }

    flash('success', ((string) $request['status'] === 'receipt_review' || $hasVariance)
        ? 'Receipt report saved. Waiting for approver confirmation.'
        : 'Request completed with the reported received quantities.');
    redirect('/requests/' . $request['id']);
}

function handle_requests_confirm_receipt_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('requests.approve');
    verify_csrf();

    $request = find_request_or_abort((int) $params['id']);
    $user = Auth::user();
    $receiptConfirmBlockReason = request_receipt_confirm_block_reason($request, $user);

    if ($receiptConfirmBlockReason !== null) {
        flash('danger', $receiptConfirmBlockReason);
        redirect('/requests/' . $request['id']);
    }

    $lines = request_lines((int) $request['id']);
    $reportedInput = [];

    foreach ($lines as $line) {
        $reportedInput[(int) $line['id']] = (string) $line['quantity_received'];
    }

    [$receiptUpdates, $receiptErrors] = build_request_receipt_updates($lines, $reportedInput);

    if ($receiptErrors !== []) {
        $message = implode(' ', array_unique($receiptErrors));

        if (request_wants_json()) {
            json_response([
                'ok' => false,
                'message' => $message,
            ], 422);
        }

        flash('danger', $message);
        redirect('/requests/' . $request['id']);
    }

    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        apply_request_receipt_confirmation_movements($request, $receiptUpdates, (int) $user['id']);

        Database::execute(
            'UPDATE item_requests
             SET status = "completed",
                 completed_at = NOW(),
                 completed_by = :completed_by,
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'completed_by' => (int) $user['id'],
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

    create_notification(
        (int) $request['requester_user_id'],
        'request_receipt_confirmed',
        'Receipt confirmed for ' . $request['request_number'],
        ($user['name'] ?? 'Approver') . ' approved the reported received quantities.',
        url('/requests/' . $request['id']),
        'request',
        (int) $request['id'],
        (int) ($user['id'] ?? 0)
    );

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => 'Receipt quantities approved and request closed.',
            'redirect_url' => url('/requests/' . $request['id']),
        ]);
    }

    flash('success', 'Receipt quantities approved and request closed.');
    redirect('/requests/' . $request['id']);
}
