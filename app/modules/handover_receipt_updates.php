<?php
declare(strict_types=1);

// Domain module: handover receipt quantity validation and update building.

function handover_active_quantity(array $line): float
{
    return round((float) $line['quantity_received'], 2);
}

function build_handover_receipt_updates(array $lines, $receivedInput): array
{
    $errors = [];
    $updates = [];
    $hasVariance = false;

    foreach ($lines as $line) {
        $lineId = (int) $line['id'];
        $receivedValue = is_array($receivedInput) ? ($receivedInput[$lineId] ?? '') : '';

        if (!is_numeric_value($receivedValue) || quantity_value($receivedValue) < 0) {
            $errors[] = 'Received quantity must be zero or more for every handover line.';
            continue;
        }

        $handed = round((float) $line['quantity_handed'], 2);
        $received = round(quantity_value($receivedValue), 2);

        if ($received > $handed) {
            $errors[] = $line['item_name'] . ' cannot receive more than the planned handover quantity.';
            continue;
        }

        $updates[] = [
            'line_id' => $lineId,
            'item_id' => (int) $line['item_id'],
            'handed' => $handed,
            'received' => $received,
            'shortage' => round($handed - $received, 2),
        ];

        if ($received !== $handed) {
            $hasVariance = true;
        }
    }

    return [$updates, $errors, $hasVariance];
}
