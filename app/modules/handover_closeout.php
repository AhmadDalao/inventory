<?php
declare(strict_types=1);

// Domain module: handover closeout and final approval handlers. Function names are preserved for route compatibility.

function handle_handovers_close_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('handovers.close');
    verify_csrf();

    $handover = find_handover_or_abort((int) $params['id']);
    $user = Auth::user();
    if (handover_is_storage_transfer($handover) || handover_is_staff_custody($handover)) {
        flash('danger', handover_is_staff_custody($handover)
            ? 'Long-term custody uses partial custody returns, not temporary-use closeout.'
            : 'Storage transfers close through receipt confirmation, not usage closeout.');
        redirect('/handovers/' . $handover['id']);
    }

    $isSourceOwner = handover_user_is_source_owner($handover, (int) ($user['id'] ?? 0))
        || (int) ($handover['created_by'] ?? 0) === (int) ($user['id'] ?? 0);
    $isRecipient = (int) ($handover['recipient_user_id'] ?? 0) === (int) ($user['id'] ?? 0);

    if (($handover['status'] ?? '') !== 'delivered') {
        flash('danger', 'Only delivered handovers can be submitted.');
        redirect('/handovers/' . $handover['id']);
    }

    $returnedInput = input('line_returned', []);
    $closedNotes = trim((string) input('closed_notes'));
    $lines = handover_lines((int) $handover['id']);
    $usesOperationalReconciliation = handover_uses_operational_reconciliation($handover);
    $autoApprove = $isSourceOwner && empty($handover['recipient_user_id']);
    $reconciliationPayloads = [];

    if ($usesOperationalReconciliation) {
        [$lineUpdates, $errors] = build_handover_operational_line_updates($lines, $returnedInput);
        [$reconciliationPayloads, $reconciliationErrors] = build_handover_reconciliation_payloads(
            $lines,
            $lineUpdates,
            input('reconciliation', []),
            $autoApprove
        );
        $errors = array_merge($errors, $reconciliationErrors);
    } else {
        $usedInput = input('line_used', []);
        $usageInput = [
            'quantity' => input('line_usage_quantity', []),
            'reason' => input('line_usage_reason', []),
            'other' => input('line_usage_other', []),
            'notes' => input('line_usage_notes', []),
        ];
        [$lineUpdates, $errors] = build_handover_close_updates($lines, $returnedInput, $usageInput, $usedInput);
    }
    $proofFile = uploaded_file('proof_image');
    $proofError = validate_workflow_proof_upload($proofFile);

    if (!$isRecipient && !$isSourceOwner) {
        $errors[] = 'Only the recipient or storage owner can submit this handover.';
    }

    if ($proofError !== null) {
        $errors[] = $proofError;
    }

    if ($errors !== []) {
        if (request_wants_json()) {
            json_response([
                'ok' => false,
                'message' => $errors[0],
            ], 422);
        }

        flash_errors($errors);
        redirect('/handovers/' . $handover['id']);
    }

    $pdo = Database::connection();
    $storedProof = null;

    try {
        if ($proofFile !== null) {
            $storedProof = store_workflow_proof_document($proofFile, 'handover', (string) $handover['handover_number'], 'closeout_report');
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
        foreach ($lineUpdates as $update) {
            Database::execute(
                'UPDATE handover_lines
                 SET quantity_used = :quantity_used,
                     quantity_returned = :quantity_returned,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'quantity_used' => $update['used'],
                    'quantity_returned' => $update['returned'],
                    'id' => $update['line_id'],
                ]
            );
        }

        if ($usesOperationalReconciliation) {
            clear_legacy_handover_usage_breakdowns((int) $handover['id']);
            save_handover_reconciliations(
                (int) $handover['id'],
                $reconciliationPayloads,
                (int) $user['id'],
                $autoApprove
            );
        } else {
            save_handover_usage_breakdowns((int) $handover['id'], $lineUpdates, (int) $user['id']);
        }

        if ($autoApprove) {
            $wristbandReview = wristband_review_snapshot((int) $handover['id'], $lineUpdates);
            if ($wristbandReview !== null) {
                wristband_store_review_snapshot($wristbandReview, (int) $user['id'], true, 'Direct issuer closeout; stock and API evidence reviewed together.');
            }
            finalize_handover_inventory($handover, $lineUpdates, (int) $user['id']);
            wristband_close_session_for_handover((int) $handover['id'], (int) $user['id']);

            Database::execute(
                'UPDATE handovers
                 SET status = "closed",
                     closed_notes = :closed_notes,
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
                    'closed_notes' => $closedNotes !== '' ? $closedNotes : null,
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
                 SET status = "pending_approval",
                     closed_notes = :closed_notes,
                     submitted_at = NOW(),
                     submitted_by = :submitted_by,
                     updated_by = :updated_by,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'closed_notes' => $closedNotes !== '' ? $closedNotes : null,
                    'submitted_by' => (int) $user['id'],
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
                'closeout_report',
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
        // Attachment regeneration should not block a valid closeout submission.
    }

    if ($autoApprove) {
        if (request_wants_json()) {
            json_response([
                'ok' => true,
                'message' => 'Handover closed.',
                'redirect_url' => url('/handovers/' . $handover['id']),
            ]);
        }

        flash('success', 'Handover closed.');
        redirect('/handovers/' . $handover['id']);
    }

    notify_workflow_observers(
        (int) ($user['id'] ?? 0),
        [(int) ($handover['source_storage_id'] ?? 0), (int) ($handover['destination_storage_id'] ?? 0)],
        'handover_waiting_approval',
        'Handover ' . $handover['handover_number'] . ' is waiting for approval',
        ($user['name'] ?? 'Someone') . ' submitted used quantities and the remaining stock is waiting for approval.',
        url('/handovers/' . $handover['id']),
        'handover',
        (int) $handover['id'],
        [],
        [(int) ($handover['recipient_user_id'] ?? 0), (int) ($handover['created_by'] ?? 0)]
    );

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => 'Handover submitted for approval.',
            'redirect_url' => url('/handovers/' . $handover['id']),
        ]);
    }

    flash('success', 'Handover submitted for approval.');
    redirect('/handovers/' . $handover['id']);
}

function handle_handovers_approve_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requireLogin();
    verify_csrf();

    $handover = find_handover_or_abort((int) $params['id']);
    $user = Auth::user();
    $approvalBlockReason = handover_close_approval_block_reason($handover, $user);

    if ($approvalBlockReason !== null) {
        flash('danger', $approvalBlockReason);
        redirect('/handovers/' . $handover['id']);
    }

    $closedNotes = trim((string) input('closed_notes', (string) ($handover['closed_notes'] ?? '')));
    $lines = handover_lines((int) $handover['id']);
    $usesOperationalReconciliation = handover_uses_operational_reconciliation($handover);
    $reconciliationPayloads = [];

    if ($usesOperationalReconciliation) {
        [$lineUpdates, $errors] = build_handover_operational_line_updates($lines, input('line_returned', []));
        [$reconciliationPayloads, $reconciliationErrors] = build_handover_reconciliation_payloads(
            $lines,
            $lineUpdates,
            input('reconciliation', []),
            true
        );
        $errors = array_merge($errors, $reconciliationErrors);
    } else {
        $usageInput = [
            'quantity' => input('line_usage_quantity', []),
            'reason' => input('line_usage_reason', []),
            'other' => input('line_usage_other', []),
            'notes' => input('line_usage_notes', []),
        ];
        [$lineUpdates, $errors] = build_handover_approval_updates($lines, input('line_returned', []), $usageInput);
    }

    $wristbandReview = wristband_review_snapshot((int) $handover['id'], $lineUpdates);
    $wristbandVarianceAcknowledged = (string) input('wristband_variance_acknowledged', '0') === '1';
    $wristbandVarianceNote = trim((string) input('wristband_variance_note'));
    if ($wristbandReview !== null
        && (bool) $wristbandReview['requires_acknowledgement']
        && (!$wristbandVarianceAcknowledged || $wristbandVarianceNote === '')) {
        $errors[] = 'Acknowledge the wristband API variance or unresolved exceptions and add a review note before approval.';
    }

    if ($errors !== []) {
        if (request_wants_json()) {
            json_response([
                'ok' => false,
                'message' => $errors[0],
            ], 422);
        }

        flash_errors($errors);
        redirect('/handovers/' . $handover['id']);
    }

    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        foreach ($lineUpdates as $update) {
            Database::execute(
                'UPDATE handover_lines
                 SET quantity_used = :quantity_used,
                     quantity_returned = :quantity_returned,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'quantity_used' => $update['used'],
                    'quantity_returned' => $update['returned'],
                    'id' => $update['line_id'],
                ]
            );
        }

        if ($usesOperationalReconciliation) {
            clear_legacy_handover_usage_breakdowns((int) $handover['id']);
            save_handover_reconciliations(
                (int) $handover['id'],
                $reconciliationPayloads,
                (int) $user['id'],
                true
            );
        } else {
            save_handover_usage_breakdowns((int) $handover['id'], $lineUpdates, (int) $user['id']);
        }
        if ($wristbandReview !== null) {
            wristband_store_review_snapshot(
                $wristbandReview,
                (int) $user['id'],
                $wristbandVarianceAcknowledged,
                $wristbandVarianceNote
            );
        }
        finalize_handover_inventory($handover, $lineUpdates, (int) $user['id']);
        wristband_close_session_for_handover((int) $handover['id'], (int) $user['id']);

        Database::execute(
            'UPDATE handovers
             SET status = "closed",
                 closed_notes = :closed_notes,
                 approved_at = NOW(),
                 approved_by = :approved_by,
                 completed_at = NOW(),
                 completed_by = :completed_by,
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'closed_notes' => $closedNotes !== '' ? $closedNotes : null,
                'approved_by' => (int) $user['id'],
                'completed_by' => (int) $user['id'],
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

    try {
        $updatedHandover = find_handover_or_abort((int) $handover['id']);
        ensure_workflow_signoff_pdf('handover', $updatedHandover, handover_lines((int) $handover['id']));
    } catch (Throwable $exception) {
        // Attachment regeneration should not block an already approved closeout.
    }

    if (!empty($handover['recipient_user_id'])) {
        create_notification(
            (int) $handover['recipient_user_id'],
            'handover_closed',
            'Handover ' . $handover['handover_number'] . ' approved',
            'The used quantity was accepted and the remaining stock was returned to the storage.',
            url('/handovers/' . $handover['id']),
            'handover',
            (int) $handover['id'],
            (int) ($user['id'] ?? 0)
        );
    }

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => 'Handover approved and closed.',
            'redirect_url' => url('/handovers/' . $handover['id']),
        ]);
    }

    flash('success', 'Handover approved and closed.');
    redirect('/handovers/' . $handover['id']);
}
