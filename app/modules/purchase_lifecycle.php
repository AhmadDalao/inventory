<?php
declare(strict_types=1);

// Domain module: purchase approval, receiving, and cancellation lifecycle handlers.
// Function names are preserved for route/view/test compatibility.

function purchase_decision_block_reason(array $purchase, ?array $user = null): ?string
{
    $user = $user ?? Auth::user();

    if ((string) $purchase['status'] !== 'pending_approval') {
        return 'Only purchases waiting for approval can be approved or rejected.';
    }

    if ((int) $purchase['requester_user_id'] === (int) ($user['id'] ?? 0)) {
        return 'You cannot approve or reject your own purchase.';
    }

    if ((int) $purchase['approver_user_id'] !== (int) ($user['id'] ?? 0) && !Auth::isOwner()) {
        return 'This purchase is assigned to a different approver.';
    }

    return null;
}

function handle_purchases_approve_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('purchases.approve');
    verify_csrf();

    $purchase = find_purchase_or_abort((int) $params['id']);
    $user = Auth::user();
    $blockReason = purchase_decision_block_reason($purchase, $user);

    if ($blockReason !== null) {
        flash('danger', $blockReason);
        redirect('/purchases/' . $purchase['id']);
    }

    $approvedQuantities = input('approved_quantity', []);
    $approvedCosts = input('approved_unit_cost', []);
    $decisionNotes = trim((string) input('decision_notes'));
    $lines = purchase_lines((int) $purchase['id']);
    $errors = [];
    $approvedAny = false;

    foreach ($lines as $line) {
        $lineId = (int) $line['id'];
        $quantityRaw = $approvedQuantities[$lineId] ?? $line['quantity_requested'];
        $costRaw = $approvedCosts[$lineId] ?? $line['unit_cost_quoted'];

        if (!is_numeric_value($quantityRaw) || quantity_value($quantityRaw) < 0) {
            $errors[] = 'Approved quantities must be valid zero-or-higher numbers.';
        }

        if (!is_numeric_value($costRaw) || quantity_value($costRaw) < 0) {
            $errors[] = 'Approved unit prices must be valid zero-or-higher numbers.';
        }

        if (quantity_value($quantityRaw) > 0) {
            $approvedAny = true;
        }
    }

    if (!$approvedAny) {
        $errors[] = 'Approve at least one line quantity or reject the purchase.';
    }

    if ($errors !== []) {
        flash_errors($errors);
        redirect('/purchases/' . $purchase['id']);
    }

    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        foreach ($lines as $line) {
            $lineId = (int) $line['id'];
            $approvedQty = round(quantity_value($approvedQuantities[$lineId] ?? $line['quantity_requested']), 2);
            $approvedCost = round(quantity_value($approvedCosts[$lineId] ?? $line['unit_cost_quoted']), 2);
            $line['unit_cost_approved'] = $approvedCost;
            $itemId = create_purchase_item_from_line($line, (int) $purchase['destination_storage_id'], (int) $user['id']);

            Database::execute(
                'UPDATE purchase_lines
                 SET item_id = :item_id,
                     quantity_approved = :quantity_approved,
                     unit_cost_approved = :unit_cost_approved,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'item_id' => $itemId,
                    'quantity_approved' => $approvedQty,
                    'unit_cost_approved' => $approvedCost,
                    'id' => $lineId,
                ]
            );
        }

        Database::execute(
            'UPDATE purchases
             SET status = "approved",
                 approved_at = NOW(),
                 approved_by = :approved_by,
                 decision_notes = :decision_notes,
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'approved_by' => (int) $user['id'],
                'decision_notes' => $decisionNotes !== '' ? $decisionNotes : null,
                'updated_by' => (int) $user['id'],
                'id' => $purchase['id'],
            ]
        );

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/purchases/' . $purchase['id']);
    }

    create_notification(
        (int) $purchase['requester_user_id'],
        'purchase_approved',
        'Purchase approved',
        $purchase['purchase_number'] . ' is approved. Receiving can now be reported.',
        url('/purchases/' . $purchase['id']),
        'purchase',
        (int) $purchase['id'],
        (int) $user['id']
    );

    flash('success', 'Purchase approved. No stock was added yet.');
    redirect('/purchases/' . $purchase['id']);
}

function handle_purchases_reject_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('purchases.approve');
    verify_csrf();

    $purchase = find_purchase_or_abort((int) $params['id']);
    $user = Auth::user();
    $blockReason = purchase_decision_block_reason($purchase, $user);

    if ($blockReason !== null) {
        flash('danger', $blockReason);
        redirect('/purchases/' . $purchase['id']);
    }

    Database::execute(
        'UPDATE purchases
         SET status = "rejected",
             rejected_at = NOW(),
             decision_notes = :decision_notes,
             updated_by = :updated_by,
             updated_at = NOW()
         WHERE id = :id',
        [
            'decision_notes' => trim((string) input('decision_notes')) ?: null,
            'updated_by' => (int) $user['id'],
            'id' => $purchase['id'],
        ]
    );

    create_notification(
        (int) $purchase['requester_user_id'],
        'purchase_rejected',
        'Purchase rejected',
        $purchase['purchase_number'] . ' was rejected.',
        url('/purchases/' . $purchase['id']),
        'purchase',
        (int) $purchase['id'],
        (int) $user['id']
    );

    flash('success', 'Purchase rejected. Stock was not changed.');
    redirect('/purchases/' . $purchase['id']);
}

function handle_purchases_receive_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('purchases.receive');
    verify_csrf();

    $purchase = find_purchase_or_abort((int) $params['id']);
    $user = Auth::user();

    if ((string) $purchase['status'] !== 'approved') {
        flash('danger', 'Only approved purchases can be received.');
        redirect('/purchases/' . $purchase['id']);
    }

    $receivedQuantities = input('received_quantity', []);
    $receiptNotes = trim((string) input('receipt_notes'));
    $lines = purchase_lines((int) $purchase['id']);
    $errors = [];

    foreach ($lines as $line) {
        $lineId = (int) $line['id'];
        $quantityRaw = $receivedQuantities[$lineId] ?? '';

        if (!is_numeric_value($quantityRaw) || quantity_value($quantityRaw) < 0) {
            $errors[] = 'Received quantities must be valid zero-or-higher numbers.';
            continue;
        }

        if (quantity_value($quantityRaw) > (float) $line['quantity_approved']) {
            $errors[] = 'Received quantity cannot be higher than the approved quantity.';
        }
    }

    foreach (uploaded_files('documents') as $file) {
        $documentError = validate_purchase_document_upload($file);

        if ($documentError !== null) {
            $errors[] = $documentError;
        }
    }

    if ($errors !== []) {
        flash_errors($errors);
        redirect('/purchases/' . $purchase['id']);
    }

    $storedDocuments = [];
    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        foreach ($lines as $line) {
            $lineId = (int) $line['id'];
            Database::execute(
                'UPDATE purchase_lines
                 SET quantity_received = :quantity_received,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'quantity_received' => round(quantity_value($receivedQuantities[$lineId] ?? 0), 2),
                    'id' => $lineId,
                ]
            );
        }

        $storedDocuments = save_purchase_documents((int) $purchase['id'], (string) $purchase['purchase_number'], uploaded_files('documents'), (string) input('document_type', 'receipt'), (int) $user['id']);

        Database::execute(
            'UPDATE purchases
             SET status = "receipt_review",
                 receiver_user_id = :receiver_user_id,
                 receipt_reported_at = NOW(),
                 receipt_notes = :receipt_notes,
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'receiver_user_id' => (int) $user['id'],
                'receipt_notes' => $receiptNotes !== '' ? $receiptNotes : null,
                'updated_by' => (int) $user['id'],
                'id' => $purchase['id'],
            ]
        );

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        foreach ($storedDocuments as $filename) {
            delete_purchase_document_file($filename);
        }

        flash('danger', $exception->getMessage());
        redirect('/purchases/' . $purchase['id']);
    }

    create_notification(
        (int) $purchase['approver_user_id'],
        'purchase_receipt_reported',
        'Purchase receipt needs review',
        ($user['name'] ?? 'A user') . ' reported received quantities for ' . $purchase['purchase_number'] . '.',
        url('/purchases/' . $purchase['id']),
        'purchase',
        (int) $purchase['id'],
        (int) $user['id']
    );

    flash('success', 'Receipt reported. Waiting for approver confirmation.');
    redirect('/purchases/' . $purchase['id']);
}

function purchase_confirm_receipt_block_reason(array $purchase, ?array $user = null): ?string
{
    $user = $user ?? Auth::user();

    if ((string) $purchase['status'] !== 'receipt_review') {
        return 'Only purchases in receipt review can be finalized.';
    }

    if ((int) $purchase['requester_user_id'] === (int) ($user['id'] ?? 0)) {
        return 'You cannot confirm final receipt for your own purchase.';
    }

    if ((int) $purchase['receiver_user_id'] === (int) ($user['id'] ?? 0)) {
        return 'You cannot confirm the receipt you reported.';
    }

    if ((int) $purchase['approver_user_id'] !== (int) ($user['id'] ?? 0) && !Auth::isOwner()) {
        return 'This purchase is assigned to a different approver.';
    }

    return null;
}

function weighted_average_cost(float $oldQuantity, float $oldCost, float $receivedQuantity, float $receivedCost): float
{
    $newQuantity = $oldQuantity + $receivedQuantity;

    if ($newQuantity <= 0) {
        return round($receivedCost, 2);
    }

    return round((($oldQuantity * $oldCost) + ($receivedQuantity * $receivedCost)) / $newQuantity, 2);
}

function handle_purchases_confirm_receipt_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('purchases.approve');
    verify_csrf();

    $purchase = find_purchase_or_abort((int) $params['id']);
    $user = Auth::user();
    $blockReason = purchase_confirm_receipt_block_reason($purchase, $user);

    if ($blockReason !== null) {
        flash('danger', $blockReason);
        redirect('/purchases/' . $purchase['id']);
    }

    $finalQuantities = input('final_quantity', []);
    $lines = purchase_lines((int) $purchase['id']);
    $errors = [];
    $finalAny = false;

    foreach ($lines as $line) {
        $lineId = (int) $line['id'];
        $quantityRaw = $finalQuantities[$lineId] ?? $line['quantity_received'];

        if (!is_numeric_value($quantityRaw) || quantity_value($quantityRaw) < 0) {
            $errors[] = 'Final received quantities must be valid zero-or-higher numbers.';
            continue;
        }

        if (quantity_value($quantityRaw) > (float) $line['quantity_approved']) {
            $errors[] = 'Final received quantity cannot be higher than approved quantity.';
        }

        if (quantity_value($quantityRaw) > 0) {
            $finalAny = true;
        }
    }

    if (!$finalAny) {
        $errors[] = 'Confirm at least one received item or cancel/reject the purchase.';
    }

    if ($errors !== []) {
        flash_errors($errors);
        redirect('/purchases/' . $purchase['id']);
    }

    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        foreach ($lines as $line) {
            $lineId = (int) $line['id'];
            $finalQty = round(quantity_value($finalQuantities[$lineId] ?? $line['quantity_received']), 2);

            Database::execute(
                'UPDATE purchase_lines
                 SET quantity_final = :quantity_final,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'quantity_final' => $finalQty,
                    'id' => $lineId,
                ]
            );

            if ($finalQty <= 0) {
                continue;
            }

            if (empty($line['item_id'])) {
                $line['unit_cost_approved'] = $line['unit_cost_approved'] ?: $line['unit_cost_quoted'];
                $line['item_id'] = create_purchase_item_from_line($line, (int) $purchase['destination_storage_id'], (int) $user['id']);
                Database::execute(
                    'UPDATE purchase_lines SET item_id = :item_id WHERE id = :id',
                    ['item_id' => (int) $line['item_id'], 'id' => $lineId]
                );
            }

            $item = find_item_or_abort((int) $line['item_id']);
            $unitCost = round((float) $line['unit_cost_approved'], 2);
            $nextCost = weighted_average_cost(
                (float) $item['current_quantity'],
                (float) $item['cost_per_unit'],
                $finalQty,
                $unitCost
            );

            apply_inventory_movement(
                $item,
                'restock',
                $finalQty,
                null,
                (int) $purchase['destination_storage_id'],
                date('Y-m-d H:i:s'),
                (string) $purchase['purchase_number'],
                'Supplier purchase receipt confirmed from ' . $purchase['supplier_name'] . '.',
                (int) $user['id'],
                'purchase',
                (int) $purchase['id']
            );

            Database::execute(
                'UPDATE items
                 SET cost_per_unit = :cost_per_unit,
                     updated_by = :updated_by,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'cost_per_unit' => $nextCost,
                    'updated_by' => (int) $user['id'],
                    'id' => (int) $line['item_id'],
                ]
            );
        }

        Database::execute(
            'UPDATE purchases
             SET status = "completed",
                 completed_at = NOW(),
                 completed_by = :completed_by,
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'completed_by' => (int) $user['id'],
                'updated_by' => (int) $user['id'],
                'id' => $purchase['id'],
            ]
        );

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/purchases/' . $purchase['id']);
    }

    foreach (array_unique([(int) $purchase['requester_user_id'], (int) ($purchase['receiver_user_id'] ?? 0)]) as $recipientId) {
        if ($recipientId <= 0 || $recipientId === (int) $user['id']) {
            continue;
        }

        create_notification(
            $recipientId,
            'purchase_completed',
            'Purchase completed',
            $purchase['purchase_number'] . ' was confirmed and stocked into ' . $purchase['storage_name'] . '.',
            url('/purchases/' . $purchase['id']),
            'purchase',
            (int) $purchase['id'],
            (int) $user['id']
        );
    }

    flash('success', 'Purchase completed and stock was added to storage.');
    redirect('/purchases/' . $purchase['id']);
}

function handle_purchases_cancel_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('purchases.cancel');
    verify_csrf();

    $purchase = find_purchase_or_abort((int) $params['id']);
    $user = Auth::user();

    if (!in_array((string) $purchase['status'], ['draft', 'pending_approval', 'approved'], true)) {
        flash('danger', 'This purchase can no longer be cancelled.');
        redirect('/purchases/' . $purchase['id']);
    }

    if ((int) $purchase['requester_user_id'] !== (int) $user['id'] && (int) $purchase['approver_user_id'] !== (int) $user['id'] && !Auth::isOwner()) {
        flash('danger', 'Only the creator, approver, or owner can cancel this purchase.');
        redirect('/purchases/' . $purchase['id']);
    }

    Database::execute(
        'UPDATE purchases
         SET status = "cancelled",
             cancelled_at = NOW(),
             updated_by = :updated_by,
             updated_at = NOW()
         WHERE id = :id',
        [
            'updated_by' => (int) $user['id'],
            'id' => $purchase['id'],
        ]
    );

    flash('success', 'Purchase cancelled. Stock was not changed.');
    redirect('/purchases/' . $purchase['id']);
}
