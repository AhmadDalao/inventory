<?php
declare(strict_types=1);

function handle_handover_custody_return_create(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('handovers.custody_return');
    verify_csrf();

    $handover = find_handover_or_abort((int) $params['id']);

    if (!handover_custody_can_report_return($handover)) {
        abort(403, 'Only the assigned staff member can report a custody return.');
    }

    $existing = Database::fetch(
        'SELECT id
         FROM handover_custody_returns
         WHERE handover_id = :handover_id
           AND status IN ("draft", "submitted", "rejected")
         ORDER BY id DESC
         LIMIT 1',
        ['handover_id' => (int) $handover['id']]
    );

    if ($existing) {
        redirect('/handovers/' . (int) $handover['id'] . '/custody-returns/' . (int) $existing['id']);
    }

    $lines = array_values(array_filter(
        handover_lines((int) $handover['id']),
        static fn (array $line): bool => handover_line_held_quantity($line) > 0
    ));

    if ($lines === []) {
        flash('warning', 'Nothing is still held by this staff member.');
        redirect('/handovers/' . (int) $handover['id']);
    }

    $user = Auth::user();
    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        Database::execute(
            'INSERT INTO handover_custody_returns (
                return_number, handover_id, status, return_date, notes,
                created_by, updated_by, created_at, updated_at
             ) VALUES (
                :return_number, :handover_id, "draft", :return_date, NULL,
                :created_by, :updated_by, NOW(), NOW()
             )',
            [
                'return_number' => handover_custody_return_number(),
                'handover_id' => (int) $handover['id'],
                'return_date' => date('Y-m-d'),
                'created_by' => (int) ($user['id'] ?? 0),
                'updated_by' => (int) ($user['id'] ?? 0),
            ]
        );
        $returnId = Database::lastInsertId();

        foreach ($lines as $line) {
            Database::execute(
                'INSERT INTO handover_custody_return_lines (
                    custody_return_id, handover_line_id, item_id,
                    serviceable_quantity, damaged_quantity, consumed_quantity, lost_quantity,
                    notes, created_at, updated_at
                 ) VALUES (
                    :custody_return_id, :handover_line_id, :item_id,
                    0, 0, 0, 0, NULL, NOW(), NOW()
                 )',
                [
                    'custody_return_id' => $returnId,
                    'handover_line_id' => (int) $line['id'],
                    'item_id' => (int) $line['item_id'],
                ]
            );
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }

    record_activity(
        'custody_return_draft_created',
        'handover',
        (int) $handover['id'],
        'Created a custody return draft for ' . (string) $handover['handover_number'] . '.'
    );
    redirect('/handovers/' . (int) $handover['id'] . '/custody-returns/' . $returnId);
}

function handle_handover_custody_return_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('handovers.custody_return');
    verify_csrf();

    $handover = find_handover_or_abort((int) $params['id']);
    $custodyReturn = find_handover_custody_return_or_abort((int) $params['return_id'], (int) $handover['id']);

    if (!handover_custody_return_can_edit($custodyReturn, $handover)) {
        abort(403, 'This custody return cannot be edited.');
    }

    $returnLines = handover_custody_return_lines((int) $custodyReturn['id']);
    $notes = trim((string) input('notes'));
    $returnDate = normalize_workflow_date(trim((string) input('return_date')));
    $updates = [];
    $storedDocuments = [];
    $errors = [];
    $total = 0.0;
    $serviceableInputs = input('serviceable_quantity', []);
    $damagedInputs = input('damaged_quantity', []);
    $consumedInputs = input('consumed_quantity', []);
    $lostInputs = input('lost_quantity', []);
    $lineNoteInputs = input('line_notes', []);

    $serviceableInputs = is_array($serviceableInputs) ? $serviceableInputs : [];
    $damagedInputs = is_array($damagedInputs) ? $damagedInputs : [];
    $consumedInputs = is_array($consumedInputs) ? $consumedInputs : [];
    $lostInputs = is_array($lostInputs) ? $lostInputs : [];
    $lineNoteInputs = is_array($lineNoteInputs) ? $lineNoteInputs : [];

    foreach ($returnLines as $line) {
        $lineId = (int) $line['id'];
        $values = [
            'serviceable_quantity' => quantity_value($serviceableInputs[$lineId] ?? 0),
            'damaged_quantity' => quantity_value($damagedInputs[$lineId] ?? 0),
            'consumed_quantity' => quantity_value($consumedInputs[$lineId] ?? 0),
            'lost_quantity' => quantity_value($lostInputs[$lineId] ?? 0),
            'notes' => trim((string) ($lineNoteInputs[$lineId] ?? '')),
        ];

        foreach (['serviceable_quantity', 'damaged_quantity', 'consumed_quantity', 'lost_quantity'] as $field) {
            if ($values[$field] < 0) {
                $errors[] = (string) $line['item_name'] . ': quantities cannot be negative.';
            }
        }

        $lineTotal = handover_custody_return_line_total($values);
        $held = handover_line_held_quantity($line);

        if ($lineTotal > $held + 0.009) {
            $errors[] = (string) $line['item_name'] . ': return outcomes exceed the ' . format_quantity($held) . ' still held.';
        }

        $proof = uploaded_file_at('damage_proof', $lineId);
        $proofError = validate_workflow_proof_upload($proof);

        if ($proofError !== null) {
            $errors[] = (string) $line['item_name'] . ': ' . $proofError;
        }

        if ($values['damaged_quantity'] > 0 && (int) ($line['proof_count'] ?? 0) === 0 && $proof === null) {
            $errors[] = (string) $line['item_name'] . ': add a proof image for damaged stock.';
        }

        if ($values['lost_quantity'] > 0 && $values['notes'] === '' && $notes === '') {
            $errors[] = (string) $line['item_name'] . ': explain why the item is lost or missing.';
        }

        $updates[$lineId] = ['values' => $values, 'proof' => $proof];
        $total += $lineTotal;
    }

    if ($total <= 0) {
        $errors[] = 'Enter at least one returned, damaged, consumed, or lost quantity.';
    }

    if ($errors !== []) {
        flash_errors($errors);
        redirect('/handovers/' . (int) $handover['id'] . '/custody-returns/' . (int) $custodyReturn['id']);
    }

    $user = Auth::user();
    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        foreach ($returnLines as $line) {
            $lineId = (int) $line['id'];
            $update = $updates[$lineId];
            $values = $update['values'];

            Database::execute(
                'UPDATE handover_custody_return_lines
                 SET serviceable_quantity = :serviceable,
                     damaged_quantity = :damaged,
                     consumed_quantity = :consumed,
                     lost_quantity = :lost,
                     notes = :notes,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'serviceable' => $values['serviceable_quantity'],
                    'damaged' => $values['damaged_quantity'],
                    'consumed' => $values['consumed_quantity'],
                    'lost' => $values['lost_quantity'],
                    'notes' => $values['notes'] !== '' ? $values['notes'] : null,
                    'id' => $lineId,
                ]
            );

            if (is_array($update['proof'])) {
                $document = store_workflow_proof_document(
                    $update['proof'],
                    'handover',
                    (string) $handover['handover_number'],
                    'custody_damage_return'
                );
                $storedDocuments[] = (string) $document['stored_filename'];
                $documentId = create_workflow_document_record(
                    'handover',
                    (int) $handover['id'],
                    (string) $handover['handover_number'],
                    'proof_image',
                    'custody_damage_return',
                    $document,
                    (int) ($user['id'] ?? 0)
                );
                Database::execute(
                    'INSERT INTO handover_custody_return_proofs (
                        custody_return_line_id, workflow_document_id, created_at
                     ) VALUES (:line_id, :document_id, NOW())',
                    ['line_id' => $lineId, 'document_id' => $documentId]
                );
            }
        }

        Database::execute(
            'UPDATE handover_custody_returns
             SET status = "submitted",
                 return_date = :return_date,
                 notes = :notes,
                 rejection_notes = NULL,
                 submitted_by = :submitted_by,
                 submitted_at = NOW(),
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'return_date' => $returnDate !== '' ? $returnDate : date('Y-m-d'),
                'notes' => $notes !== '' ? $notes : null,
                'submitted_by' => (int) ($user['id'] ?? 0),
                'updated_by' => (int) ($user['id'] ?? 0),
                'id' => (int) $custodyReturn['id'],
            ]
        );
        Database::execute(
            'UPDATE handovers SET updated_by = :user_id, updated_at = NOW() WHERE id = :id',
            ['user_id' => (int) ($user['id'] ?? 0), 'id' => (int) $handover['id']]
        );
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        foreach ($storedDocuments as $filename) {
            delete_workflow_document_file($filename);
        }

        throw $exception;
    }

    notify_workflow_observers(
        (int) ($user['id'] ?? 0),
        [(int) ($handover['source_storage_id'] ?? 0)],
        'custody_return_submitted',
        'Custody return awaiting review',
        (string) $custodyReturn['return_number'] . ' has quantities and evidence ready for approval.',
        url('/handovers/' . (int) $handover['id'] . '/custody-returns/' . (int) $custodyReturn['id']),
        'handover',
        (int) $handover['id'],
        [],
        [(int) ($handover['recipient_user_id'] ?? 0), (int) ($handover['created_by'] ?? 0)]
    );

    record_activity(
        'custody_return_submitted',
        'handover',
        (int) $handover['id'],
        'Submitted custody return ' . (string) $custodyReturn['return_number'] . ' for issuer review.'
    );
    custody_refresh_signoff_documents($handover);
    flash('success', 'Custody return submitted for issuer approval.');
    redirect('/handovers/' . (int) $handover['id']);
}

function handle_handover_custody_return_approve(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('handovers.custody_approve');
    verify_csrf();

    $handover = find_handover_or_abort((int) $params['id']);
    $custodyReturn = find_handover_custody_return_or_abort((int) $params['return_id'], (int) $handover['id']);

    if (!handover_custody_can_review_return($handover)) {
        abort(403, 'Only the source issuer can approve this custody return.');
    }

    $reviewNotes = trim((string) input('review_notes'));
    $user = Auth::user();
    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        $lockedReturn = Database::fetch(
            'SELECT * FROM handover_custody_returns WHERE id = :id FOR UPDATE',
            ['id' => (int) $custodyReturn['id']]
        );

        if (!$lockedReturn || (string) $lockedReturn['status'] !== 'submitted') {
            throw new RuntimeException('This custody return is no longer waiting for approval.');
        }

        $returnLines = handover_custody_return_lines((int) $custodyReturn['id']);
        $bufferStorageId = system_storage_id('handover_buffer');
        $quarantineStorageId = system_storage_id('damaged_quarantine');

        foreach ($returnLines as $returnLine) {
            if ((float) $returnLine['damaged_quantity'] > 0 && (int) ($returnLine['proof_count'] ?? 0) === 0) {
                throw new RuntimeException((string) $returnLine['item_name'] . ' is missing required damage proof.');
            }

            $handoverLine = Database::fetch(
                'SELECT * FROM handover_lines WHERE id = :id FOR UPDATE',
                ['id' => (int) $returnLine['handover_line_id']]
            );
            $item = Database::fetch(
                'SELECT * FROM items WHERE id = :id LIMIT 1',
                ['id' => (int) $returnLine['item_id']]
            );

            if (!$handoverLine || !$item) {
                throw new RuntimeException('A custody return item no longer exists.');
            }

            $returnTotal = handover_custody_return_line_total($returnLine);

            if ($returnTotal > handover_line_held_quantity($handoverLine) + 0.009) {
                throw new RuntimeException((string) $returnLine['item_name'] . ' exceeds the quantity still held.');
            }

            $reference = (string) $lockedReturn['return_number'] . ' / ' . (string) $handover['handover_number'];
            $serviceable = (float) $returnLine['serviceable_quantity'];
            $damaged = (float) $returnLine['damaged_quantity'];
            $consumed = (float) $returnLine['consumed_quantity'];
            $lost = (float) $returnLine['lost_quantity'];

            if ($serviceable > 0) {
                apply_inventory_movement($item, 'transfer', $serviceable, $bufferStorageId, (int) $handover['source_storage_id'], date('Y-m-d H:i:s'), $reference, 'Serviceable custody return restored to source storage.', (int) $user['id'], 'handover', (int) $handover['id']);
            }
            if ($damaged > 0) {
                apply_inventory_movement($item, 'transfer', $damaged, $bufferStorageId, $quarantineStorageId, date('Y-m-d H:i:s'), $reference, 'Damaged custody return moved to quarantine.', (int) $user['id'], 'handover', (int) $handover['id']);
            }
            if ($consumed > 0) {
                apply_inventory_movement($item, 'usage', $consumed, $bufferStorageId, null, date('Y-m-d H:i:s'), $reference, 'Custody item consumed or worn out.', (int) $user['id'], 'handover', (int) $handover['id']);
            }
            if ($lost > 0) {
                apply_inventory_movement($item, 'usage', $lost, $bufferStorageId, null, date('Y-m-d H:i:s'), $reference, 'Custody item reported lost or missing. ' . trim((string) ($returnLine['notes'] ?? '')), (int) $user['id'], 'handover', (int) $handover['id']);
            }

            Database::execute(
                'UPDATE handover_lines
                 SET quantity_returned = quantity_returned + :returned,
                     quantity_used = quantity_used + :used,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'returned' => round($serviceable + $damaged, 2),
                    'used' => round($consumed + $lost, 2),
                    'id' => (int) $handoverLine['id'],
                ]
            );
        }

        $heldTotal = (float) Database::scalar(
            'SELECT COALESCE(SUM(GREATEST(quantity_received - quantity_used - quantity_returned, 0)), 0)
             FROM handover_lines
             WHERE handover_id = :handover_id',
            ['handover_id' => (int) $handover['id']]
        );
        $nextStatus = $heldTotal <= 0.009 ? 'closed' : 'delivered';

        Database::execute(
            'UPDATE handover_custody_returns
             SET status = "approved",
                 review_notes = :review_notes,
                 reviewed_by = :reviewed_by,
                 reviewed_at = NOW(),
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'review_notes' => $reviewNotes !== '' ? $reviewNotes : null,
                'reviewed_by' => (int) $user['id'],
                'updated_by' => (int) $user['id'],
                'id' => (int) $custodyReturn['id'],
            ]
        );
        if ($nextStatus === 'closed') {
            Database::execute(
                'UPDATE handovers
                 SET status = "closed",
                     approved_at = NOW(),
                     completed_at = NOW(),
                     approved_by = :approved_by,
                     completed_by = :completed_by,
                     updated_by = :updated_by,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'approved_by' => (int) $user['id'],
                    'completed_by' => (int) $user['id'],
                    'updated_by' => (int) $user['id'],
                    'id' => (int) $handover['id'],
                ]
            );
        } else {
            Database::execute(
                'UPDATE handovers
                 SET status = "delivered", updated_by = :user_id, updated_at = NOW()
                 WHERE id = :id',
                ['user_id' => (int) $user['id'], 'id' => (int) $handover['id']]
            );
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/handovers/' . (int) $handover['id'] . '/custody-returns/' . (int) $custodyReturn['id']);
    }

    create_notification(
        (int) $handover['recipient_user_id'],
        'custody_return_approved',
        'Custody return approved',
        (string) $custodyReturn['return_number'] . ' was approved. Any items still held remain assigned to you.',
        url('/handovers/' . (int) $handover['id']),
        'handover',
        (int) $handover['id'],
        (int) $user['id']
    );
    record_activity('custody_return_approved', 'handover', (int) $handover['id'], 'Approved custody return ' . (string) $custodyReturn['return_number'] . '.');
    custody_refresh_signoff_documents($handover);
    flash('success', 'Custody return approved and stock updated.');
    redirect('/handovers/' . (int) $handover['id']);
}

function handle_handover_custody_return_reject(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('handovers.custody_approve');
    verify_csrf();

    $handover = find_handover_or_abort((int) $params['id']);
    $custodyReturn = find_handover_custody_return_or_abort((int) $params['return_id'], (int) $handover['id']);

    if (!handover_custody_can_review_return($handover)) {
        abort(403, 'Only the source issuer can reject this custody return.');
    }

    $reason = trim((string) input('rejection_notes'));

    if ($reason === '') {
        flash('danger', 'Explain what the staff member needs to correct.');
        redirect('/handovers/' . (int) $handover['id'] . '/custody-returns/' . (int) $custodyReturn['id']);
    }

    $user = Auth::user() ?? [];
    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        $lockedReturn = Database::fetch(
            'SELECT status
             FROM handover_custody_returns
             WHERE id = :id
             FOR UPDATE',
            ['id' => (int) $custodyReturn['id']]
        );

        if (!$lockedReturn || (string) $lockedReturn['status'] !== 'submitted') {
            throw new RuntimeException('This custody return is no longer waiting for review.');
        }

        Database::execute(
            'UPDATE handover_custody_returns
             SET status = "rejected",
                 rejection_notes = :reason,
                 reviewed_by = :reviewed_by,
                 reviewed_at = NOW(),
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'reason' => $reason,
                'reviewed_by' => (int) ($user['id'] ?? 0),
                'updated_by' => (int) ($user['id'] ?? 0),
                'id' => (int) $custodyReturn['id'],
            ]
        );
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/handovers/' . (int) $handover['id'] . '/custody-returns/' . (int) $custodyReturn['id']);
    }

    create_notification(
        (int) $handover['recipient_user_id'],
        'custody_return_rejected',
        'Custody return needs correction',
        $reason,
        url('/handovers/' . (int) $handover['id'] . '/custody-returns/' . (int) $custodyReturn['id']),
        'handover',
        (int) $handover['id'],
        (int) ($user['id'] ?? 0)
    );
    record_activity('custody_return_rejected', 'handover', (int) $handover['id'], 'Rejected custody return ' . (string) $custodyReturn['return_number'] . ' for correction.');
    flash('success', 'Return sent back for correction.');
    redirect('/handovers/' . (int) $handover['id']);
}

function custody_refresh_signoff_documents(array $handover): void
{
    try {
        $fresh = find_handover_or_abort((int) $handover['id']);
        ensure_workflow_signoff_pdf('handover', $fresh, handover_lines((int) $handover['id']));
    } catch (Throwable $exception) {
        // Stock posting must never fail because a document renderer is unavailable.
    }
}

function handle_handover_custody_replacement_create(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('handovers.custody_approve');
    verify_csrf();

    $handover = find_handover_or_abort((int) $params['id']);
    $custodyReturn = find_handover_custody_return_or_abort((int) $params['return_id'], (int) $handover['id']);

    if (!handover_custody_can_review_return($handover)) {
        abort(403, 'Only the source issuer can request replacement stock.');
    }
    if ((string) $custodyReturn['status'] !== 'approved') {
        flash('danger', 'Approve the custody return before requesting replacements.');
        redirect('/handovers/' . (int) $handover['id'] . '/custody-returns/' . (int) $custodyReturn['id']);
    }
    if (!empty($custodyReturn['replacement_handover_id'])) {
        redirect('/handovers/' . (int) $custodyReturn['replacement_handover_id']);
    }

    $replacementLines = array_values(array_filter(
        handover_custody_return_lines((int) $custodyReturn['id']),
        static fn (array $line): bool => (float) $line['damaged_quantity'] + (float) $line['lost_quantity'] > 0.009
    ));

    if ($replacementLines === []) {
        flash('warning', 'There are no damaged or lost quantities to replace.');
        redirect('/handovers/' . (int) $handover['id']);
    }

    $user = Auth::user() ?? [];
    $handoverNumber = next_workflow_number('HDO', 'handovers', 'handover_number');
    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        Database::execute(
            'INSERT INTO handovers (
                handover_number, source_storage_id, destination_storage_id, approver_user_id, manager_user_id,
                recipient_name, recipient_user_id, recipient_type, handover_purpose,
                issue_condition, custody_review_date, usage_reporting_mode, handover_mode,
                status, scheduled_for_date, notes, requested_at, issued_at,
                created_by, updated_by, created_at, updated_at
             ) VALUES (
                :handover_number, :source_storage_id, NULL, :approver_user_id, :manager_user_id,
                :recipient_name, :recipient_user_id, "staff", "staff_custody",
                :issue_condition, :custody_review_date, "legacy_per_item", "request",
                "requested", :scheduled_for_date, :notes, NOW(), NOW(),
                :created_by, :updated_by, NOW(), NOW()
             )',
            [
                'handover_number' => $handoverNumber,
                'source_storage_id' => (int) $handover['source_storage_id'],
                'approver_user_id' => (int) (storage_owner_user_id((int) $handover['source_storage_id']) ?? $handover['approver_user_id'] ?? 0),
                'manager_user_id' => manager_user_id_for((int) $handover['recipient_user_id']),
                'recipient_name' => (string) $handover['recipient_name'],
                'recipient_user_id' => (int) $handover['recipient_user_id'],
                'issue_condition' => (string) ($handover['issue_condition'] ?? 'good'),
                'custody_review_date' => $handover['custody_review_date'] ?: null,
                'scheduled_for_date' => $handover['custody_review_date'] ?: null,
                'notes' => 'Replacement requested from custody return ' . (string) $custodyReturn['return_number'] . '.',
                'created_by' => (int) $handover['recipient_user_id'],
                'updated_by' => (int) ($user['id'] ?? 0),
            ]
        );
        $replacementId = Database::lastInsertId();

        foreach ($replacementLines as $line) {
            $quantity = round((float) $line['damaged_quantity'] + (float) $line['lost_quantity'], 2);
            Database::execute(
                'INSERT INTO handover_lines (
                    handover_id, item_id, item_name, item_sku, unit,
                    quantity_handed, quantity_received, quantity_used, quantity_returned,
                    created_at, updated_at
                 ) VALUES (
                    :handover_id, :item_id, :item_name, :item_sku, :unit,
                    :quantity, 0, 0, 0, NOW(), NOW()
                 )',
                [
                    'handover_id' => $replacementId,
                    'item_id' => (int) $line['item_id'],
                    'item_name' => (string) $line['item_name'],
                    'item_sku' => (string) $line['item_sku'],
                    'unit' => (string) $line['unit'],
                    'quantity' => $quantity,
                ]
            );
        }

        Database::execute(
            'UPDATE handover_custody_returns
             SET replacement_handover_id = :replacement_id,
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'replacement_id' => $replacementId,
                'updated_by' => (int) ($user['id'] ?? 0),
                'id' => (int) $custodyReturn['id'],
            ]
        );
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    record_activity(
        'custody_replacement_requested',
        'handover',
        (int) $replacementId,
        'Created replacement request ' . $handoverNumber . ' from ' . (string) $custodyReturn['return_number'] . '.'
    );
    flash('success', 'Replacement request created. Stock will move only after normal approval.');
    redirect('/handovers/' . $replacementId);
}

function handover_custody_quarantine_action(array $params, string $action): void
{
    app_ready_or_redirect();
    Auth::requireOwner();
    verify_csrf();

    $lineId = (int) $params['line_id'];
    $quantity = quantity_value(input('quantity', 0));
    $reason = trim((string) input('reason'));
    $destinationStorageId = $action === 'return_to_service'
        ? normalize_entity_id(input('destination_storage_id'))
        : null;

    if ($quantity <= 0) {
        flash('danger', 'Enter a quantity greater than zero.');
        redirect('/handovers/custody/quarantine');
    }
    if ($reason === '') {
        flash('danger', 'A disposition reason is required.');
        redirect('/handovers/custody/quarantine');
    }
    if ($action === 'return_to_service' && (!$destinationStorageId || !storage_exists_for_assignment($destinationStorageId))) {
        flash('danger', 'Pick a valid active destination storage.');
        redirect('/handovers/custody/quarantine');
    }

    $user = Auth::user() ?? [];
    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        $returnLine = Database::fetch(
            'SELECT return_line.*,
                    custody_return.return_number,
                    custody_return.handover_id,
                    custody_return.status AS return_status,
                    handover.handover_number,
                    item.name AS item_name
             FROM handover_custody_return_lines return_line
             INNER JOIN handover_custody_returns custody_return ON custody_return.id = return_line.custody_return_id
             INNER JOIN handovers handover ON handover.id = custody_return.handover_id
             INNER JOIN items item ON item.id = return_line.item_id
             WHERE return_line.id = :id
             FOR UPDATE',
            ['id' => $lineId]
        );

        if (!$returnLine || (string) $returnLine['return_status'] !== 'approved') {
            throw new RuntimeException('This quarantine line is not available for disposition.');
        }

        $processed = (float) Database::scalar(
            'SELECT COALESCE(SUM(quantity), 0)
             FROM handover_quarantine_dispositions
             WHERE custody_return_line_id = :line_id',
            ['line_id' => $lineId]
        );
        $available = max(0.0, round((float) $returnLine['damaged_quantity'] - $processed, 2));

        if ($quantity > $available + 0.009) {
            throw new RuntimeException('Only ' . format_quantity($available) . ' remains for this damaged return.');
        }

        $item = Database::fetch('SELECT * FROM items WHERE id = :id LIMIT 1', ['id' => (int) $returnLine['item_id']]);
        if (!$item) {
            throw new RuntimeException('The quarantined item no longer exists.');
        }

        $quarantineStorageId = system_storage_id('damaged_quarantine');
        $reference = (string) $returnLine['return_number'] . ' / ' . (string) $returnLine['handover_number'];

        if ($action === 'return_to_service') {
            apply_inventory_movement(
                $item,
                'transfer',
                $quantity,
                $quarantineStorageId,
                $destinationStorageId,
                date('Y-m-d H:i:s'),
                $reference,
                'Quarantined custody item returned to service. ' . $reason,
                (int) ($user['id'] ?? 0),
                'handover',
                (int) $returnLine['handover_id']
            );
        } else {
            apply_inventory_movement(
                $item,
                'usage',
                $quantity,
                $quarantineStorageId,
                null,
                date('Y-m-d H:i:s'),
                $reference,
                'Quarantined custody item disposed / written off. ' . $reason,
                (int) ($user['id'] ?? 0),
                'handover',
                (int) $returnLine['handover_id']
            );
        }

        Database::execute(
            'INSERT INTO handover_quarantine_dispositions (
                custody_return_line_id, item_id, action_type, quantity,
                destination_storage_id, reason, performed_by, performed_at, created_at
             ) VALUES (
                :line_id, :item_id, :action_type, :quantity,
                :destination_storage_id, :reason, :performed_by, NOW(), NOW()
             )',
            [
                'line_id' => $lineId,
                'item_id' => (int) $returnLine['item_id'],
                'action_type' => $action,
                'quantity' => $quantity,
                'destination_storage_id' => $destinationStorageId,
                'reason' => $reason,
                'performed_by' => (int) ($user['id'] ?? 0),
            ]
        );
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('danger', $exception->getMessage());
        redirect('/handovers/custody/quarantine');
    }

    record_activity(
        'custody_quarantine_' . $action,
        'handover',
        (int) $returnLine['handover_id'],
        ($action === 'return_to_service' ? 'Returned quarantined stock to service: ' : 'Disposed quarantined stock: ')
            . format_quantity($quantity) . ' ' . (string) $returnLine['item_name'] . '.'
    );
    flash('success', $action === 'return_to_service' ? 'Item returned to active service.' : 'Item disposed and written off.');
    redirect('/handovers/custody/quarantine');
}

function handle_handover_custody_quarantine_return_to_service(array $params): void
{
    handover_custody_quarantine_action($params, 'return_to_service');
}

function handle_handover_custody_quarantine_dispose(array $params): void
{
    handover_custody_quarantine_action($params, 'dispose');
}
