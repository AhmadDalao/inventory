<?php
declare(strict_types=1);

// Domain module: handover receipt handlers. Function names are preserved for route compatibility.

function handle_handovers_receive_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requireLogin();
    verify_csrf();

    $handover = find_handover_or_abort((int) $params['id']);
    $user = Auth::user();
    $isStorageTransfer = handover_is_storage_transfer($handover);

    if (!handover_can_report_receipt($handover, $user)) {
        flash('danger', $isStorageTransfer
            ? 'Only the destination storage owner can report transfer receipt quantities.'
            : 'Only the assigned recipient can report received quantities.');
        redirect('/handovers/' . $handover['id']);
    }

    $lines = handover_lines((int) $handover['id']);
    $receiptNotes = trim((string) input('receipt_notes'));
    [$receiptUpdates, $receiptErrors, $hasVariance] = build_handover_receipt_updates($lines, input('line_received'));
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
        redirect('/handovers/' . $handover['id']);
    }

    if ($receiptErrors !== []) {
        $message = implode(' ', array_unique($receiptErrors));

        if (request_wants_json()) {
            json_response([
                'ok' => false,
                'message' => $message,
            ], 422);
        }

        flash_errors($receiptErrors);
        redirect('/handovers/' . $handover['id']);
    }

    $pdo = Database::connection();
    $storedProof = null;

    try {
        if ($proofFile !== null) {
            $storedProof = store_workflow_proof_document($proofFile, 'handover', (string) $handover['handover_number'], 'receipt_report');
        }
    } catch (Throwable $exception) {
        if (request_wants_json()) {
            json_response([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        flash('danger', $exception->getMessage());
        redirect('/handovers/' . $handover['id']);
    }

    $pdo->beginTransaction();

    try {
        foreach ($receiptUpdates as $update) {
            Database::execute(
                'UPDATE handover_lines
                 SET quantity_received = :quantity_received,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'quantity_received' => (float) $update['received'],
                    'id' => (int) $update['line_id'],
                ]
            );
        }

        if ($isStorageTransfer) {
            if (!$hasVariance) {
                finalize_handover_storage_transfer_inventory($handover, $receiptUpdates, (int) $user['id']);

                Database::execute(
                    'UPDATE handovers
                     SET status = "closed",
                         receipt_notes = :receipt_notes,
                         receipt_reported_at = NOW(),
                         submitted_at = NOW(),
                         submitted_by = :submitted_by,
                         approved_at = NOW(),
                         approved_by = :approved_by,
                         completed_at = NOW(),
                         completed_by = :completed_by,
                         updated_by = :updated_by,
                         updated_at = NOW()
                     WHERE id = :id',
                    [
                        'receipt_notes' => $receiptNotes !== '' ? $receiptNotes : null,
                        'submitted_by' => (int) $user['id'],
                        'approved_by' => (int) $user['id'],
                        'completed_by' => (int) $user['id'],
                        'updated_by' => (int) $user['id'],
                        'id' => (int) $handover['id'],
                    ]
                );
            } else {
                Database::execute(
                    'UPDATE handovers
                     SET status = "receipt_review",
                         receipt_notes = :receipt_notes,
                         receipt_reported_at = NOW(),
                         updated_by = :updated_by,
                         updated_at = NOW()
                     WHERE id = :id',
                    [
                        'receipt_notes' => $receiptNotes !== '' ? $receiptNotes : null,
                        'updated_by' => (int) $user['id'],
                        'id' => (int) $handover['id'],
                    ]
                );
            }
        } else {
            Database::execute(
                'UPDATE handovers
                 SET status = :status,
                     receipt_notes = :receipt_notes,
                     receipt_reported_at = NOW(),
                     updated_by = :updated_by,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'status' => $hasVariance ? 'receipt_review' : 'delivered',
                    'receipt_notes' => $receiptNotes !== '' ? $receiptNotes : null,
                    'updated_by' => (int) $user['id'],
                    'id' => (int) $handover['id'],
                ]
            );
        }

        if ($storedProof !== null) {
            create_workflow_document_record(
                'handover',
                (int) $handover['id'],
                (string) $handover['handover_number'],
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
        redirect('/handovers/' . $handover['id']);
    }

    try {
        $updatedHandover = find_handover_or_abort((int) $handover['id']);
        ensure_workflow_signoff_pdf('handover', $updatedHandover, handover_lines((int) $handover['id']));
    } catch (Throwable $exception) {
        // Attachment regeneration should not block receipt reporting.
    }

    if (!empty($handover['source_owner_user_id'])) {
        create_notification(
            (int) $handover['source_owner_user_id'],
            !$hasVariance ? 'handover_received' : 'handover_receipt_review',
            !$hasVariance
                ? 'Handover ' . $handover['handover_number'] . ' was received'
                : 'Handover ' . $handover['handover_number'] . ' needs receipt review',
            $isStorageTransfer
                ? ($hasVariance
                    ? ($user['name'] ?? 'Destination owner') . ' reported a transfer receipt difference and is waiting for source owner confirmation.'
                    : ($user['name'] ?? 'Destination owner') . ' confirmed the transfer receipt and stock moved to the destination storage.')
                : ($hasVariance
                    ? ($user['name'] ?? 'Recipient') . ' reported a receipt difference and is waiting for your confirmation.'
                    : ($user['name'] ?? 'Recipient') . ' confirmed the full receipt and can now report usage and returns.'),
            url('/handovers/' . $handover['id']),
            'handover',
            (int) $handover['id'],
            (int) ($user['id'] ?? 0)
        );
    }

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => $isStorageTransfer
                ? ($hasVariance
                    ? 'Transfer receipt saved. Waiting for the source storage owner to confirm the receipt difference.'
                    : 'Transfer received and closed.')
                : ($hasVariance
                    ? 'Receipt difference saved. Waiting for the issuer to review the quantities.'
                    : 'Receipt confirmed. You can now report usage and returns.'),
            'redirect_url' => url('/handovers/' . $handover['id']),
        ]);
    }

    flash('success', $isStorageTransfer
        ? ($hasVariance
            ? 'Transfer receipt saved. Waiting for the source storage owner to confirm the receipt difference.'
            : 'Transfer received and closed.')
        : ($hasVariance
            ? 'Receipt difference saved. Waiting for the issuer to review the quantities.'
            : 'Receipt confirmed. You can now report usage and returns.'));
    redirect('/handovers/' . $handover['id']);
}

function handle_handovers_confirm_receipt_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requireLogin();
    verify_csrf();

    $handover = find_handover_or_abort((int) $params['id']);
    $user = Auth::user();
    $isStorageTransfer = handover_is_storage_transfer($handover);

    $receiptConfirmBlockReason = handover_receipt_confirm_block_reason($handover, $user);

    if ($receiptConfirmBlockReason !== null) {
        flash('danger', $receiptConfirmBlockReason);
        redirect('/handovers/' . $handover['id']);
    }

    $lines = handover_lines((int) $handover['id']);
    [$receiptUpdates, $receiptErrors] = build_handover_receipt_updates($lines, input('line_received', []));

    if ($receiptErrors !== []) {
        $message = implode(' ', array_unique($receiptErrors));

        if (request_wants_json()) {
            json_response([
                'ok' => false,
                'message' => $message,
            ], 422);
        }

        flash_errors($receiptErrors);
        redirect('/handovers/' . $handover['id']);
    }

    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        foreach ($receiptUpdates as $update) {
            Database::execute(
                'UPDATE handover_lines
                 SET quantity_received = :quantity_received,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'quantity_received' => (float) $update['received'],
                    'id' => (int) $update['line_id'],
                ]
            );
        }

        if ($isStorageTransfer) {
            reconcile_handover_receipt_inventory(
                $handover,
                $receiptUpdates,
                (int) $user['id'],
                'Transfer receipt confirmation',
                false
            );

            finalize_handover_storage_transfer_inventory($handover, $receiptUpdates, (int) $user['id']);

            Database::execute(
                'UPDATE handovers
                 SET status = "closed",
                     submitted_at = COALESCE(submitted_at, NOW()),
                     submitted_by = COALESCE(submitted_by, :submitted_by),
                     approved_at = NOW(),
                     approved_by = :approved_by,
                     completed_at = NOW(),
                     completed_by = :completed_by,
                     updated_by = :updated_by,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'submitted_by' => (int) $user['id'],
                    'approved_by' => (int) $user['id'],
                    'completed_by' => (int) $user['id'],
                    'updated_by' => (int) $user['id'],
                    'id' => (int) $handover['id'],
                ]
            );
        } else {
            reconcile_handover_receipt_inventory(
                $handover,
                $receiptUpdates,
                (int) $user['id'],
                'Receipt confirmation'
            );

            Database::execute(
                'UPDATE handovers
                 SET status = "delivered",
                     updated_by = :updated_by,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'updated_by' => (int) $user['id'],
                    'id' => (int) $handover['id'],
                ]
            );
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/handovers/' . $handover['id']);
    }

    try {
        $updatedHandover = find_handover_or_abort((int) $handover['id']);
        ensure_workflow_signoff_pdf('handover', $updatedHandover, handover_lines((int) $handover['id']));
    } catch (Throwable $exception) {
        // Attachment regeneration should not block receipt confirmation.
    }

    if (!empty($handover['recipient_user_id'])) {
        create_notification(
            (int) $handover['recipient_user_id'],
            'handover_delivery_confirmed',
            $isStorageTransfer
                ? 'Transfer ' . $handover['handover_number'] . ' approved'
                : 'Handover ' . $handover['handover_number'] . ' is ready',
            $isStorageTransfer
                ? 'The source owner approved the transfer receipt difference and the confirmed stock moved to the destination storage.'
                : 'The reported received quantity was confirmed. You can now track usage and returns.',
            url('/handovers/' . $handover['id']),
            'handover',
            (int) $handover['id'],
            (int) ($user['id'] ?? 0)
        );
    }

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => $isStorageTransfer
                ? 'Transfer receipt difference approved and closed.'
                : 'Received quantities approved. The handover is now active.',
            'redirect_url' => url('/handovers/' . $handover['id']),
        ]);
    }

    flash('success', $isStorageTransfer
        ? 'Transfer receipt difference approved and closed.'
        : 'Received quantities approved. The handover is now active.');
    redirect('/handovers/' . $handover['id']);
}
