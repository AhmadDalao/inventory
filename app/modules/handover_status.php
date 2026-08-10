<?php
declare(strict_types=1);

function handover_recovery_target_status(array $handover, array $lines): ?string
{
    $status = (string) ($handover['status'] ?? '');

    if ($status === 'rejected') {
        return 'requested';
    }

    if ($status !== 'cancelled') {
        return null;
    }

    $wasUnissuedRequest = (string) ($handover['handover_mode'] ?? 'direct') === 'request'
        && empty($handover['request_approved_at'])
        && empty($handover['request_approved_by']);

    if ($wasUnissuedRequest) {
        return 'requested';
    }

    if (!empty($handover['receipt_reported_at'])) {
        foreach ($lines as $line) {
            if (round((float) ($line['quantity_received'] ?? 0), 2) !== round((float) ($line['quantity_handed'] ?? 0), 2)) {
                return 'receipt_review';
            }
        }

        return 'delivered';
    }

    return !empty($handover['recipient_user_id']) ? 'awaiting_receipt' : 'delivered';
}

function handover_recovery_block_reason(array $handover, array $lines, ?array $user = null): ?string
{
    $user = $user ?? Auth::user();

    if ($user === null) {
        return 'Login first.';
    }

    if (!Auth::isOwner()) {
        return 'Only the owner can recover handovers.';
    }

    $targetStatus = handover_recovery_target_status($handover, $lines);

    if ($targetStatus === null) {
        return 'Only cancelled or rejected handovers can be recovered.';
    }

    if (!workflow_stock_impact_is_neutral('handover', (int) ($handover['id'] ?? 0))) {
        return 'This handover still has active stock impact. Close or cancel the stock flow before recovery.';
    }

    if ($targetStatus !== 'requested') {
        foreach ($lines as $line) {
            $plannedQuantity = round((float) ($line['quantity_handed'] ?? 0), 2);

            if ($plannedQuantity <= 0) {
                continue;
            }

            $balance = item_storage_balance_record((int) $line['item_id'], (int) $handover['source_storage_id']);

            if ($balance === null || (float) $balance['quantity'] < $plannedQuantity) {
                return $line['item_name'] . ' no longer has enough stock to recover this handover.';
            }
        }
    }

    return null;
}

function handover_lines_have_close_quantities(array $lines): bool
{
    foreach ($lines as $line) {
        if (round((float) ($line['quantity_used'] ?? 0), 2) > 0 || round((float) ($line['quantity_returned'] ?? 0), 2) > 0) {
            return true;
        }
    }

    return false;
}

function handover_source_can_cover_quantities(array $handover, array $lines, string $quantityField): ?string
{
    foreach ($lines as $line) {
        $quantity = round((float) ($line[$quantityField] ?? 0), 2);

        if ($quantity <= 0) {
            continue;
        }

        $balance = item_storage_balance_record((int) $line['item_id'], (int) $handover['source_storage_id']);

        if ($balance === null || (float) $balance['quantity'] < $quantity) {
            return $line['item_name'] . ' does not have enough source stock for this status change.';
        }
    }

    return null;
}

function handover_buffer_impact_by_item(array $handover): array
{
    $bufferStorageId = system_storage_id('handover_buffer');
    $impactByItem = [];

    foreach (workflow_stock_impact('handover', (int) ($handover['id'] ?? 0)) as $row) {
        if ((int) ($row['storage_id'] ?? 0) !== $bufferStorageId) {
            continue;
        }

        $itemId = (int) ($row['item_id'] ?? 0);

        if ($itemId <= 0) {
            continue;
        }

        $impactByItem[$itemId] = round(
            ($impactByItem[$itemId] ?? 0.0) + (float) ($row['quantity_delta'] ?? 0),
            2
        );
    }

    return $impactByItem;
}

function handover_quantities_by_item(array $rows, string $quantityField): array
{
    $quantities = [];

    foreach ($rows as $row) {
        $itemId = (int) ($row['item_id'] ?? 0);

        if ($itemId <= 0) {
            continue;
        }

        $quantities[$itemId] = round(
            ($quantities[$itemId] ?? 0.0) + (float) ($row[$quantityField] ?? 0),
            2
        );
    }

    return $quantities;
}

function reconcile_handover_receipt_inventory(
    array $handover,
    array $receiptRows,
    int $performedBy,
    string $notePrefix = 'Receipt confirmation',
    bool $returnUnreceivedStock = true
): void {
    $quantityField = isset($receiptRows[0]) && array_key_exists('received', $receiptRows[0])
        ? 'received'
        : 'quantity_received';
    $desiredByItem = handover_quantities_by_item($receiptRows, $quantityField);
    $currentByItem = handover_buffer_impact_by_item($handover);
    $bufferStorageId = system_storage_id('handover_buffer');
    $itemIds = array_unique(array_merge(array_keys($desiredByItem), array_keys($currentByItem)));

    foreach ($itemIds as $itemId) {
        $itemId = (int) $itemId;
        $desired = round((float) ($desiredByItem[$itemId] ?? 0), 2);
        $current = round((float) ($currentByItem[$itemId] ?? 0), 2);
        $difference = round($desired - $current, 2);

        if (abs($difference) <= 0.009) {
            continue;
        }

        $item = find_item_or_abort($itemId);

        if ($difference > 0) {
            $sourceBalance = item_storage_balance_record($itemId, (int) $handover['source_storage_id']);
            $sourceAvailable = round((float) ($sourceBalance['quantity'] ?? 0), 2);

            if ($sourceAvailable < $difference) {
                throw new RuntimeException(
                    (string) ($item['name'] ?? 'Item')
                    . ' needs ' . format_quantity($difference)
                    . ' additional stock from the source to confirm this receipt, but only '
                    . format_quantity($sourceAvailable)
                    . ' is available. Restock the source or correct the received quantity first.'
                );
            }

            apply_inventory_movement(
                $item,
                'transfer',
                $difference,
                (int) $handover['source_storage_id'],
                $bufferStorageId,
                date('Y-m-d H:i:s'),
                (string) $handover['handover_number'],
                $notePrefix . ': added confirmed extra received stock from the source to the handover buffer.',
                $performedBy,
                'handover',
                (int) $handover['id']
            );
            continue;
        }

        if (!$returnUnreceivedStock) {
            continue;
        }

        apply_inventory_movement(
            $item,
            'transfer',
            abs($difference),
            $bufferStorageId,
            (int) $handover['source_storage_id'],
            date('Y-m-d H:i:s'),
            (string) $handover['handover_number'],
            $notePrefix . ': returned unreceived stock to the source storage.',
            $performedBy,
            'handover',
            (int) $handover['id']
        );
    }
}

function handover_closed_reversal_block_reason(array $handover, array $lines): ?string
{
    foreach ($lines as $line) {
        $returned = round((float) ($line['quantity_returned'] ?? 0), 2);

        if ($returned <= 0) {
            continue;
        }

        $balance = item_storage_balance_record((int) $line['item_id'], (int) $handover['source_storage_id']);

        if ($balance === null || (float) $balance['quantity'] < $returned) {
            return $line['item_name'] . ' no longer has enough returned stock in the source storage to reopen this closed handover.';
        }
    }

    return null;
}

function handover_storage_transfer_reopen_block_reason(array $handover, array $lines): ?string
{
    if ((string) ($handover['status'] ?? '') !== 'closed') {
        return 'Only a closed storage transfer can be reopened for receipt correction.';
    }

    $destinationStorageId = (int) ($handover['destination_storage_id'] ?? 0);

    if ($destinationStorageId <= 0) {
        return 'This storage transfer has no destination storage.';
    }

    foreach (handover_buffer_impact_by_item($handover) as $bufferImpact) {
        if (abs((float) $bufferImpact) > 0.009) {
            return 'This transfer buffer is not neutral. Repair its stock impact before reopening receipt review.';
        }
    }

    $plannedByItem = handover_quantities_by_item($lines, 'quantity_handed');
    $receivedByItem = handover_quantities_by_item($lines, 'quantity_received');
    $itemNames = [];

    foreach ($lines as $line) {
        $itemNames[(int) ($line['item_id'] ?? 0)] = (string) ($line['item_name'] ?? 'Item');
    }

    foreach (array_unique(array_merge(array_keys($plannedByItem), array_keys($receivedByItem))) as $itemId) {
        $itemId = (int) $itemId;
        $planned = round((float) ($plannedByItem[$itemId] ?? 0), 2);
        $received = round((float) ($receivedByItem[$itemId] ?? 0), 2);

        if ($received > 0) {
            $destinationBalance = item_storage_balance_record($itemId, $destinationStorageId);

            if ($destinationBalance === null || round((float) $destinationBalance['quantity'], 2) < $received) {
                return ($itemNames[$itemId] ?? 'Item') . ' no longer has enough stock in the destination storage to reopen this transfer.';
            }
        }

        $shortage = max(0, round($planned - $received, 2));

        if ($shortage > 0) {
            $sourceBalance = item_storage_balance_record($itemId, (int) $handover['source_storage_id']);

            if ($sourceBalance === null || round((float) $sourceBalance['quantity'], 2) < $shortage) {
                return ($itemNames[$itemId] ?? 'Item') . ' no longer has enough shortage stock in the source storage to reopen this transfer.';
            }
        }
    }

    return null;
}

function handover_status_override_options(array $handover, array $lines, ?array $user = null): array
{
    $currentStatus = (string) ($handover['status'] ?? '');
    $allOptions = handover_status_options();
    $options = [];

    if (isset($allOptions[$currentStatus])) {
        $options[$currentStatus] = $allOptions[$currentStatus];
    }

    foreach ($allOptions as $status => $label) {
        if ($status === $currentStatus) {
            continue;
        }

        if (handover_status_override_block_reason($handover, $lines, $status, $user) === null) {
            $options[$status] = $label;
        }
    }

    return $options;
}

function handover_status_override_block_reason(array $handover, array $lines, string $targetStatus, ?array $user = null): ?string
{
    $user = $user ?? Auth::user();
    $targetStatus = trim($targetStatus);
    $currentStatus = (string) ($handover['status'] ?? '');

    if ($user === null) {
        return 'Login first.';
    }

    if (!Auth::isOwner()) {
        return 'Only the owner can override handover statuses.';
    }

    if (!array_key_exists($targetStatus, handover_status_options())) {
        return 'Pick a valid handover status.';
    }

    if ($targetStatus === $currentStatus) {
        return 'This handover is already ' . handover_status_label($targetStatus) . '.';
    }

    if (handover_is_storage_transfer($handover)) {
        if ($currentStatus === 'closed' && $targetStatus === 'receipt_review') {
            return handover_storage_transfer_reopen_block_reason($handover, $lines);
        }

        return 'Storage transfers use destination receipt, cancellation, recovery, or safe receipt reopening. Direct status jumps are blocked.';
    }

    if (handover_is_staff_custody($handover)) {
        return 'Long-term custody status changes must use receipt, custody return, cancellation, or recovery actions.';
    }

    if ($targetStatus === 'receipt_review') {
        if ($currentStatus !== 'delivered') {
            return 'Only a delivered staff handover can be reopened for receipt approval.';
        }

        if (empty($handover['receipt_reported_at'])) {
            return 'Receipt Review needs actual received quantities from the recipient.';
        }

        if (handover_lines_have_close_quantities($lines)) {
            return 'This handover already has usage or returned quantities. Reopen the closeout instead of the receipt.';
        }

        return null;
    }

    if ((string) ($handover['handover_mode'] ?? 'direct') !== 'request' && in_array($targetStatus, ['requested', 'rejected'], true)) {
        return 'Direct handovers do not use Requested or Rejected statuses.';
    }

    if (in_array($currentStatus, ['cancelled', 'rejected'], true)) {
        if (!workflow_stock_impact_is_neutral('handover', (int) ($handover['id'] ?? 0))) {
            return 'This handover still has active stock impact. Cancel or reverse stock before changing the status.';
        }

        if ($targetStatus === 'requested') {
            return null;
        }

        if (in_array($targetStatus, ['awaiting_receipt', 'delivered'], true)) {
            return handover_source_can_cover_quantities($handover, $lines, 'quantity_handed');
        }

        return 'Cancelled or rejected handovers can only be reopened to Requested, Awaiting Receipt, or Delivered.';
    }

    if ($currentStatus === 'closed') {
        if (!in_array($targetStatus, ['delivered', 'pending_approval'], true)) {
            return 'Closed handovers can only be reopened to Delivered or Waiting Approval.';
        }

        return handover_closed_reversal_block_reason($handover, $lines);
    }

    if ($currentStatus === 'pending_approval') {
        if (!in_array($targetStatus, ['delivered', 'closed'], true)) {
            return 'Waiting Approval can only go back to Delivered or forward to Closed.';
        }

        return null;
    }

    if ($targetStatus === 'pending_approval') {
        return 'Waiting Approval needs used and returned quantities. Use the closeout form instead.';
    }

    if ($targetStatus === 'closed') {
        if ($currentStatus !== 'delivered') {
            return 'Only Delivered handovers can be closed directly.';
        }

        return null;
    }

    if ($targetStatus === 'rejected') {
        return $currentStatus === 'requested' ? null : 'Only Requested handovers can be rejected.';
    }

    if ($targetStatus === 'cancelled') {
        if (!in_array($currentStatus, ['requested', 'awaiting_receipt', 'receipt_review', 'delivered'], true)) {
            return 'This handover cannot be cancelled from its current status.';
        }

        if ($currentStatus === 'delivered' && handover_lines_have_close_quantities($lines)) {
            return 'This handover already has usage or returned quantities. Reopen it or close it properly instead of cancelling.';
        }

        return null;
    }

    if ($targetStatus === 'requested') {
        if (!in_array($currentStatus, ['awaiting_receipt', 'receipt_review', 'delivered'], true)) {
            return 'Only active handovers can be moved back to Requested.';
        }

        if ($currentStatus === 'delivered' && handover_lines_have_close_quantities($lines)) {
            return 'Clear the usage/return closeout first. This delivered handover already has closeout quantities.';
        }

        return null;
    }

    if ($targetStatus === 'awaiting_receipt') {
        if ($currentStatus === 'requested') {
            return handover_source_can_cover_quantities($handover, $lines, 'quantity_handed');
        }

        if ($currentStatus === 'delivered') {
            if (handover_lines_have_close_quantities($lines)) {
                return 'Clear the usage/return closeout first. This delivered handover already has closeout quantities.';
            }

            $reservedByItem = handover_buffer_impact_by_item($handover);
            $handedByItem = handover_quantities_by_item($lines, 'quantity_handed');

            foreach ($handedByItem as $itemId => $handedQuantity) {
                if (abs(round((float) $handedQuantity - (float) ($reservedByItem[$itemId] ?? 0), 2)) > 0.009) {
                    return 'This handover already has an approved receipt stock adjustment. Reopen Receipt Review instead of resetting receipt confirmation.';
                }
            }

            return null;
        }

        return 'Only Requested or Delivered handovers can move to Awaiting Receipt.';
    }

    if ($targetStatus === 'delivered') {
        if ($currentStatus === 'requested') {
            return handover_source_can_cover_quantities($handover, $lines, 'quantity_handed');
        }

        if (in_array($currentStatus, ['awaiting_receipt', 'receipt_review', 'delivered'], true)) {
            return null;
        }

        return 'This handover cannot be moved to Delivered from its current status.';
    }

    return null;
}

function reverse_closed_handover_inventory(array $handover, array $lines, int $performedBy): void
{
    $bufferStorageId = system_storage_id('handover_buffer');

    foreach ($lines as $line) {
        $item = find_item_or_abort((int) $line['item_id']);
        $used = round((float) ($line['quantity_used'] ?? 0), 2);
        $returned = round((float) ($line['quantity_returned'] ?? 0), 2);

        if ($used > 0) {
            apply_inventory_movement(
                $item,
                'restock',
                $used,
                null,
                $bufferStorageId,
                date('Y-m-d H:i:s'),
                (string) $handover['handover_number'],
                'Admin status override reopened closed handover and restored consumed stock to buffer.',
                $performedBy,
                'handover',
                (int) $handover['id']
            );
        }

        if ($returned > 0) {
            apply_inventory_movement(
                $item,
                'transfer',
                $returned,
                (int) $handover['source_storage_id'],
                $bufferStorageId,
                date('Y-m-d H:i:s'),
                (string) $handover['handover_number'],
                'Admin status override reopened closed handover and moved returned stock back to buffer.',
                $performedBy,
                'handover',
                (int) $handover['id']
            );
        }
    }
}

function reopen_closed_storage_transfer_inventory(array $handover, array $lines, int $performedBy): void
{
    $blockReason = handover_storage_transfer_reopen_block_reason($handover, $lines);

    if ($blockReason !== null) {
        throw new RuntimeException($blockReason);
    }

    $bufferStorageId = system_storage_id('handover_buffer');
    $destinationStorageId = (int) $handover['destination_storage_id'];
    $plannedByItem = handover_quantities_by_item($lines, 'quantity_handed');
    $receivedByItem = handover_quantities_by_item($lines, 'quantity_received');

    foreach (array_unique(array_merge(array_keys($plannedByItem), array_keys($receivedByItem))) as $itemId) {
        $itemId = (int) $itemId;
        $item = find_item_or_abort($itemId);
        $planned = round((float) ($plannedByItem[$itemId] ?? 0), 2);
        $received = round((float) ($receivedByItem[$itemId] ?? 0), 2);

        if ($received > 0) {
            apply_inventory_movement(
                $item,
                'transfer',
                $received,
                $destinationStorageId,
                $bufferStorageId,
                date('Y-m-d H:i:s'),
                (string) $handover['handover_number'],
                'Owner reopened the closed storage transfer and moved received stock back into receipt review.',
                $performedBy,
                'handover',
                (int) $handover['id']
            );
        }

        $shortage = max(0, round($planned - $received, 2));

        if ($shortage > 0) {
            apply_inventory_movement(
                $item,
                'transfer',
                $shortage,
                (int) $handover['source_storage_id'],
                $bufferStorageId,
                date('Y-m-d H:i:s'),
                (string) $handover['handover_number'],
                'Owner reopened the closed storage transfer and restored its previously returned shortage to the buffer.',
                $performedBy,
                'handover',
                (int) $handover['id']
            );
        }

        $overage = max(0, round($received - $planned, 2));

        if ($overage > 0) {
            apply_inventory_movement(
                $item,
                'transfer',
                $overage,
                $bufferStorageId,
                (int) $handover['source_storage_id'],
                date('Y-m-d H:i:s'),
                (string) $handover['handover_number'],
                'Owner reopened the closed storage transfer and reversed the approved excess receipt adjustment.',
                $performedBy,
                'handover',
                (int) $handover['id']
            );
        }
    }
}

function confirm_handover_receipt_shortage_inventory(array $handover, array $lines, int $performedBy): void
{
    reconcile_handover_receipt_inventory(
        $handover,
        $lines,
        $performedBy,
        'Admin status override receipt correction'
    );
}

function apply_handover_status_override(array $handover, array $lines, string $targetStatus, int $performedBy, string $notes = ''): void
{
    $currentStatus = (string) ($handover['status'] ?? '');
    $noteColumn = in_array($targetStatus, ['requested', 'rejected'], true) ? 'request_decision_notes' : 'closed_notes';
    $existingNote = (string) ($handover[$noteColumn] ?? '');
    $actor = Auth::user();
    $overrideNote = trim(
        $existingNote .
        "\n\nStatus override by " . (string) (($actor['name'] ?? null) ?: 'Admin') . ' on ' . date('Y-m-d H:i:s') .
        ': ' . handover_status_label($currentStatus) . ' -> ' . handover_status_label($targetStatus) .
        ($notes !== '' ? '. ' . $notes : '.')
    );

    if (handover_is_storage_transfer($handover)) {
        if ($currentStatus !== 'closed' || $targetStatus !== 'receipt_review') {
            throw new RuntimeException('Unsafe storage-transfer status override blocked.');
        }

        reopen_closed_storage_transfer_inventory($handover, $lines, $performedBy);
    } elseif (handover_is_staff_custody($handover)) {
        throw new RuntimeException('Long-term custody status changes must use custody workflow actions.');
    } elseif ($currentStatus === 'requested' && in_array($targetStatus, ['awaiting_receipt', 'delivered'], true)) {
        issue_handover_inventory($handover, $lines, $performedBy);
    } elseif (in_array($currentStatus, ['cancelled', 'rejected'], true) && in_array($targetStatus, ['awaiting_receipt', 'delivered'], true)) {
        issue_handover_inventory($handover, $lines, $performedBy);
    } elseif (in_array($currentStatus, ['awaiting_receipt', 'receipt_review', 'delivered'], true) && in_array($targetStatus, ['requested', 'cancelled'], true)) {
        cancel_handover_inventory($handover, $lines, $performedBy);
    } elseif ($currentStatus === 'receipt_review' && $targetStatus === 'delivered') {
        confirm_handover_receipt_shortage_inventory($handover, $lines, $performedBy);
    } elseif ($currentStatus === 'closed' && in_array($targetStatus, ['delivered', 'pending_approval'], true)) {
        reverse_closed_handover_inventory($handover, $lines, $performedBy);
    } elseif ($currentStatus === 'pending_approval' && $targetStatus === 'closed') {
        $lineUpdates = array_map(static function (array $line): array {
            return [
                'line_id' => (int) $line['id'],
                'item_id' => (int) $line['item_id'],
                'used' => round((float) ($line['quantity_used'] ?? 0), 2),
                'returned' => round((float) ($line['quantity_returned'] ?? 0), 2),
                'breakdowns' => (array) ($line['usage_breakdowns'] ?? []),
            ];
        }, $lines);
        finalize_handover_inventory($handover, $lineUpdates, $performedBy);
    } elseif ($currentStatus === 'delivered' && $targetStatus === 'closed') {
        $lineUpdates = array_map(static function (array $line): array {
            $received = round((float) (($line['quantity_received'] ?? 0) ?: ($line['quantity_handed'] ?? 0)), 2);

            return [
                'line_id' => (int) $line['id'],
                'item_id' => (int) $line['item_id'],
                'used' => 0.0,
                'returned' => $received,
                'breakdowns' => [],
            ];
        }, $lines);

        foreach ($lineUpdates as $update) {
            Database::execute(
                'UPDATE handover_lines
                 SET quantity_used = 0,
                     quantity_returned = :quantity_returned,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'quantity_returned' => $update['returned'],
                    'id' => $update['line_id'],
                ]
            );
        }

        Database::execute('DELETE FROM handover_usage_breakdowns WHERE handover_id = :handover_id', ['handover_id' => (int) $handover['id']]);
        finalize_handover_inventory($handover, $lineUpdates, $performedBy);
    }

    if ($targetStatus === 'delivered') {
        if (in_array($currentStatus, ['closed', 'pending_approval'], true)) {
            Database::execute(
                'UPDATE handover_lines
                 SET quantity_received = CASE WHEN quantity_received > 0 THEN quantity_received ELSE quantity_handed END,
                     quantity_used = 0,
                     quantity_returned = 0,
                     updated_at = NOW()
                 WHERE handover_id = :handover_id',
                ['handover_id' => (int) $handover['id']]
            );
            Database::execute('DELETE FROM handover_usage_breakdowns WHERE handover_id = :handover_id', ['handover_id' => (int) $handover['id']]);
        } elseif ($currentStatus !== 'receipt_review') {
            Database::execute(
                'UPDATE handover_lines
                 SET quantity_received = CASE WHEN quantity_received > 0 THEN quantity_received ELSE quantity_handed END,
                     updated_at = NOW()
                 WHERE handover_id = :handover_id',
                ['handover_id' => (int) $handover['id']]
            );
        }
    } elseif ($targetStatus === 'awaiting_receipt') {
        Database::execute(
            'UPDATE handover_lines
             SET quantity_received = 0,
                 quantity_used = 0,
                 quantity_returned = 0,
                 updated_at = NOW()
             WHERE handover_id = :handover_id',
            ['handover_id' => (int) $handover['id']]
        );
        Database::execute('DELETE FROM handover_usage_breakdowns WHERE handover_id = :handover_id', ['handover_id' => (int) $handover['id']]);
    } elseif ($targetStatus === 'requested') {
        Database::execute(
            'UPDATE handover_lines
             SET quantity_received = 0,
                 quantity_used = 0,
                 quantity_returned = 0,
                 updated_at = NOW()
             WHERE handover_id = :handover_id',
            ['handover_id' => (int) $handover['id']]
        );
        Database::execute('DELETE FROM handover_usage_breakdowns WHERE handover_id = :handover_id', ['handover_id' => (int) $handover['id']]);
    }

    $actorIdSql = (string) max(0, $performedBy);
    $timestampSql = [
        'requested' => 'receipt_reported_at = NULL, submitted_at = NULL, submitted_by = NULL, approved_at = NULL, approved_by = NULL, completed_at = NULL, completed_by = NULL, request_rejected_at = NULL, cancelled_at = NULL',
        'awaiting_receipt' => 'request_approved_at = COALESCE(request_approved_at, NOW()), request_approved_by = COALESCE(request_approved_by, ' . $actorIdSql . '), issued_at = COALESCE(issued_at, NOW()), receipt_reported_at = NULL, submitted_at = NULL, submitted_by = NULL, approved_at = NULL, approved_by = NULL, completed_at = NULL, completed_by = NULL, request_rejected_at = NULL, cancelled_at = NULL',
        'receipt_review' => 'receipt_reported_at = COALESCE(receipt_reported_at, NOW()), submitted_at = NULL, submitted_by = NULL, approved_at = NULL, approved_by = NULL, completed_at = NULL, completed_by = NULL, cancelled_at = NULL',
        'delivered' => 'request_approved_at = COALESCE(request_approved_at, NOW()), request_approved_by = COALESCE(request_approved_by, ' . $actorIdSql . '), issued_at = COALESCE(issued_at, NOW()), receipt_reported_at = COALESCE(receipt_reported_at, NOW()), submitted_at = NULL, submitted_by = NULL, approved_at = NULL, approved_by = NULL, completed_at = NULL, completed_by = NULL, request_rejected_at = NULL, cancelled_at = NULL',
        'pending_approval' => 'submitted_at = COALESCE(submitted_at, NOW()), submitted_by = COALESCE(submitted_by, ' . $actorIdSql . '), approved_at = NULL, approved_by = NULL, completed_at = NULL, completed_by = NULL, cancelled_at = NULL',
        'closed' => 'submitted_at = COALESCE(submitted_at, NOW()), submitted_by = COALESCE(submitted_by, ' . $actorIdSql . '), approved_at = NOW(), approved_by = ' . $actorIdSql . ', completed_at = NOW(), completed_by = ' . $actorIdSql . ', cancelled_at = NULL',
        'rejected' => 'request_rejected_at = NOW(), cancelled_at = NULL',
        'cancelled' => 'cancelled_at = NOW()',
    ][$targetStatus];

    $executeParams = [
        'status' => $targetStatus,
        'status_notes' => $overrideNote !== '' ? $overrideNote : null,
        'updated_by' => $performedBy,
        'id' => (int) $handover['id'],
    ];

    Database::execute(
        'UPDATE handovers
         SET status = :status,
             ' . $noteColumn . ' = :status_notes,
             ' . $timestampSql . ',
             updated_by = :updated_by,
             updated_at = NOW()
         WHERE id = :id',
        $executeParams
    );
}
