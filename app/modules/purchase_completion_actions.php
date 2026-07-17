<?php
declare(strict_types=1);

// Domain module: purchase final receipt confirmation and stock posting.

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
