<?php
declare(strict_types=1);

// Request inventory issue, receipt parsing, and receipt movement helpers.
function issue_request_inventory(array $request, array $lines, int $performedBy): void
{
    $transitStorageId = system_storage_id('request_transit');

    foreach ($lines as $line) {
        $approvedQuantity = round((float) ($line['quantity_approved'] ?? 0), 2);

        if ($approvedQuantity <= 0) {
            continue;
        }

        $item = find_item_or_abort((int) $line['item_id']);
        $balance = item_storage_balance_record((int) $line['item_id'], (int) $request['source_storage_id']);

        if ($balance === null || (float) $balance['quantity'] < $approvedQuantity) {
            throw new RuntimeException($line['item_name'] . ' no longer has enough stock to recover this request.');
        }

        apply_inventory_movement(
            $item,
            'transfer',
            $approvedQuantity,
            (int) $request['source_storage_id'],
            $transitStorageId,
            date('Y-m-d H:i:s'),
            (string) $request['request_number'],
            'Recovered request moved approved stock back into transit.',
            $performedBy,
            'request',
            (int) $request['id']
        );
    }
}

function build_request_receipt_updates(array $lines, $receivedInput): array
{
    $errors = [];
    $updates = [];
    $hasVariance = false;

    foreach ($lines as $line) {
        $lineId = (int) $line['id'];
        $receivedValue = is_array($receivedInput) ? ($receivedInput[$lineId] ?? '') : '';

        if (!is_numeric_value($receivedValue) || quantity_value($receivedValue) < 0) {
            $errors[] = 'Received quantity must be zero or more for every line.';
            continue;
        }

        $approved = round((float) $line['quantity_approved'], 2);
        $received = round(quantity_value($receivedValue), 2);

        if ($received > $approved) {
            $errors[] = $line['item_name'] . ' cannot receive more than the approved quantity.';
            continue;
        }

        $updates[] = [
            'line_id' => $lineId,
            'item_id' => (int) $line['item_id'],
            'approved' => $approved,
            'received' => $received,
            'remainder' => round($approved - $received, 2),
        ];

        if ($received !== $approved) {
            $hasVariance = true;
        }
    }

    return [$updates, $errors, $hasVariance];
}

function apply_request_receipt_confirmation_movements(array $request, array $receiptUpdates, int $performedBy): void
{
    $transitStorageId = system_storage_id('request_transit');
    $isTransfer = (string) ($request['request_mode'] ?? 'transfer') === 'transfer';

    foreach ($receiptUpdates as $update) {
        if ((float) $update['approved'] <= 0) {
            continue;
        }

        $item = find_item_or_abort((int) $update['item_id']);

        if ((float) $update['received'] > 0) {
            if ($isTransfer) {
                apply_inventory_movement(
                    $item,
                    'transfer',
                    (float) $update['received'],
                    $transitStorageId,
                    (int) $request['destination_storage_id'],
                    date('Y-m-d H:i:s'),
                    (string) $request['request_number'],
                    'Request receipt confirmed into destination storage.',
                    $performedBy,
                    'request',
                    (int) $request['id']
                );
            } else {
                apply_inventory_movement(
                    $item,
                    'usage',
                    (float) $update['received'],
                    $transitStorageId,
                    null,
                    date('Y-m-d H:i:s'),
                    (string) $request['request_number'],
                    'Issue request receipt confirmed and released for use.',
                    $performedBy,
                    'request',
                    (int) $request['id']
                );
            }
        }

        if ((float) $update['remainder'] > 0) {
            apply_inventory_movement(
                $item,
                'transfer',
                (float) $update['remainder'],
                $transitStorageId,
                (int) $request['source_storage_id'],
                date('Y-m-d H:i:s'),
                (string) $request['request_number'],
                'Unreceived request quantity returned to source storage.',
                $performedBy,
                'request',
                (int) $request['id']
            );
        }

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
}
