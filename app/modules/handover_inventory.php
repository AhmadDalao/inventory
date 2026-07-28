<?php
declare(strict_types=1);

function issue_handover_inventory(array $handover, array $lines, int $performedBy): void
{
    $bufferStorageId = system_storage_id('handover_buffer');

    foreach ($lines as $line) {
        $plannedQuantity = round((float) ($line['quantity_handed'] ?? 0), 2);

        if ($plannedQuantity <= 0) {
            continue;
        }

        $item = find_item_or_abort((int) $line['item_id']);
        $balance = item_storage_balance_record((int) $line['item_id'], (int) $handover['source_storage_id']);

        if ($balance === null || (float) $balance['quantity'] < $plannedQuantity) {
            throw new RuntimeException($line['item_name'] . ' no longer has enough stock to issue this handover.');
        }

        apply_inventory_movement(
            $item,
            'transfer',
            $plannedQuantity,
            (int) $handover['source_storage_id'],
            $bufferStorageId,
            date('Y-m-d H:i:s'),
            (string) $handover['handover_number'],
            'Issued for handover to ' . $handover['recipient_name'] . '.',
            $performedBy,
            'handover',
            (int) $handover['id']
        );
    }
}

function finalize_handover_inventory(array $handover, array $lineUpdates, int $performedBy): void
{
    $bufferStorageId = system_storage_id('handover_buffer');

    foreach ($lineUpdates as $update) {
        $item = find_item_or_abort((int) $update['item_id']);
        $usageSummary = handover_usage_reason_summary((array) ($update['breakdowns'] ?? []), (string) ($item['unit'] ?? 'pcs'));

        if ($update['used'] > 0) {
            apply_inventory_movement(
                $item,
                'usage',
                (float) $update['used'],
                $bufferStorageId,
                null,
                date('Y-m-d H:i:s'),
                (string) $handover['handover_number'],
                'Consumed during handover.' . ($usageSummary !== '' ? ' Usage: ' . $usageSummary . '.' : ''),
                $performedBy,
                'handover',
                (int) $handover['id']
            );
        }

        if ($update['returned'] > 0) {
            apply_inventory_movement(
                $item,
                'transfer',
                (float) $update['returned'],
                $bufferStorageId,
                (int) $handover['source_storage_id'],
                date('Y-m-d H:i:s'),
                (string) $handover['handover_number'],
                'Returned from handover back into storage.',
                $performedBy,
                'handover',
                (int) $handover['id']
            );
        }
    }
}

function finalize_handover_storage_transfer_inventory(array $handover, array $receiptUpdates, int $performedBy): void
{
    if (empty($handover['destination_storage_id'])) {
        throw new RuntimeException('Storage transfer destination is missing.');
    }

    $bufferStorageId = system_storage_id('handover_buffer');

    foreach ($receiptUpdates as $update) {
        $item = find_item_or_abort((int) $update['item_id']);
        $received = round((float) ($update['received'] ?? 0), 2);
        $shortage = round((float) ($update['shortage'] ?? 0), 2);

        if ($received > 0) {
            apply_inventory_movement(
                $item,
                'transfer',
                $received,
                $bufferStorageId,
                (int) $handover['destination_storage_id'],
                date('Y-m-d H:i:s'),
                (string) $handover['handover_number'],
                'Storage transfer received into destination storage.',
                $performedBy,
                'handover',
                (int) $handover['id']
            );
        }

        if ($shortage > 0) {
            apply_inventory_movement(
                $item,
                'transfer',
                $shortage,
                $bufferStorageId,
                (int) $handover['source_storage_id'],
                date('Y-m-d H:i:s'),
                (string) $handover['handover_number'],
                'Storage transfer shortage returned to source storage.',
                $performedBy,
                'handover',
                (int) $handover['id']
            );
        }
    }
}

function cancel_handover_inventory(array $handover, array $lines, int $performedBy): void
{
    $status = (string) ($handover['status'] ?? '');

    if (!in_array($status, ['awaiting_receipt', 'receipt_review', 'delivered'], true)) {
        return;
    }

    $bufferStorageId = system_storage_id('handover_buffer');

    foreach ($lines as $line) {
        if ($status === 'delivered' && handover_is_staff_custody($handover)) {
            $quantity = handover_line_held_quantity($line);
        } else {
            $quantity = $status === 'delivered'
                ? round((float) (($line['quantity_received'] ?? 0) ?: ($line['quantity_handed'] ?? 0)), 2)
                : round((float) ($line['quantity_handed'] ?? 0), 2);
        }

        if ($quantity <= 0) {
            continue;
        }

        $item = find_item_or_abort((int) $line['item_id']);

        apply_inventory_movement(
            $item,
            'transfer',
            $quantity,
            $bufferStorageId,
            (int) $handover['source_storage_id'],
            date('Y-m-d H:i:s'),
            (string) $handover['handover_number'],
            handover_is_staff_custody($handover)
                ? 'Cancelled long-term custody returned stock still held by staff to source storage.'
                : 'Cancelled handover returned reserved stock to source storage.',
            $performedBy,
            'handover',
            (int) $handover['id']
        );
    }
}
