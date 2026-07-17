<?php
declare(strict_types=1);

// Domain module: purchase approval and rejection actions.

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
