<?php
declare(strict_types=1);

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

function build_handover_close_updates(array $lines, $returnedInput, array $usageInput = [], $usedFallbackInput = []): array
{
    $errors = [];
    $updates = [];

    foreach ($lines as $line) {
        $lineId = (int) $line['id'];
        $handed = handover_active_quantity($line);
        $returnedRaw = is_array($returnedInput)
            ? ($returnedInput[$lineId] ?? $returnedInput[(string) $lineId] ?? null)
            : null;
        $usedFallbackRaw = is_array($usedFallbackInput)
            ? ($usedFallbackInput[$lineId] ?? $usedFallbackInput[(string) $lineId] ?? null)
            : null;

        if ($returnedRaw !== null && trim((string) $returnedRaw) !== '') {
            if (!is_numeric_value($returnedRaw) || quantity_value($returnedRaw) < 0) {
                $errors[] = $line['item_name'] . ' must have a valid returned quantity.';
                continue;
            }

            $returned = round(quantity_value($returnedRaw), 2);

            if ($returned > $handed) {
                $errors[] = $line['item_name'] . ' cannot return more than the confirmed received quantity.';
                continue;
            }

            $used = round($handed - $returned, 2);
        } elseif ($usedFallbackRaw !== null && trim((string) $usedFallbackRaw) !== '') {
            if (!is_numeric_value($usedFallbackRaw) || quantity_value($usedFallbackRaw) < 0) {
                $errors[] = 'Used quantity must be zero or more for every line.';
                continue;
            }

            $used = round(quantity_value($usedFallbackRaw), 2);
            $returned = round($handed - $used, 2);
        } else {
            $errors[] = $line['item_name'] . ' must have a returned quantity.';
            continue;
        }

        $parsedUsage = parse_handover_usage_input_rows($line, $usageInput);
        $errors = array_merge($errors, $parsedUsage['errors']);
        $breakdowns = $parsedUsage['breakdowns'];
        $hasUsageRows = (bool) $parsedUsage['has_usage_rows'];
        $breakdownUsed = round((float) $parsedUsage['used'], 2);

        if ($hasUsageRows && abs($breakdownUsed - $used) >= 0.01) {
            $errors[] = $line['item_name'] . ' usage reasons must total ' . format_quantity($used) . ' ' . (string) ($line['unit'] ?? 'pcs') . ' after returned quantity is entered.';
            continue;
        }

        if ($used > $handed) {
            $errors[] = $line['item_name'] . ' cannot use more than the confirmed received quantity.';
            continue;
        }

        if (!$hasUsageRows && $used > 0) {
            $breakdowns[] = [
                'reason_code' => 'unspecified',
                'reason_custom' => '',
                'quantity' => $used,
                'notes' => '',
            ];
        }

        $updates[] = [
            'line_id' => $lineId,
            'item_id' => (int) $line['item_id'],
            'used' => $used,
            'returned' => $returned,
            'breakdowns' => $breakdowns,
        ];
    }

    return [$updates, $errors];
}

function handover_adjust_breakdowns_for_approval(array $line, float $confirmedUsed): array
{
    $existing = array_values(array_filter((array) ($line['usage_breakdowns'] ?? []), static function (array $breakdown): bool {
        return round((float) ($breakdown['quantity'] ?? 0), 2) > 0;
    }));
    $existingTotal = round(array_reduce($existing, static function (float $carry, array $breakdown): float {
        return $carry + round((float) ($breakdown['quantity'] ?? 0), 2);
    }, 0.0), 2);

    if (abs($existingTotal - $confirmedUsed) < 0.01) {
        return $existing;
    }

    if ($confirmedUsed <= 0) {
        return [];
    }

    $adjustmentNote = 'Owner approval adjustment after confirming returned quantity.';

    if ($existingTotal <= 0) {
        return [[
            'reason_code' => 'unspecified',
            'reason_custom' => '',
            'quantity' => $confirmedUsed,
            'notes' => $adjustmentNote,
        ]];
    }

    if ($confirmedUsed > $existingTotal) {
        $existing[] = [
            'reason_code' => 'unspecified',
            'reason_custom' => '',
            'quantity' => round($confirmedUsed - $existingTotal, 2),
            'notes' => $adjustmentNote,
        ];

        return $existing;
    }

    $remaining = $confirmedUsed;
    $trimmed = [];

    foreach ($existing as $breakdown) {
        if ($remaining <= 0) {
            break;
        }

        $originalQuantity = round((float) ($breakdown['quantity'] ?? 0), 2);
        $quantity = min($originalQuantity, $remaining);

        if ($quantity <= 0) {
            continue;
        }

        $breakdown['quantity'] = round($quantity, 2);

        if ($quantity < $originalQuantity) {
            $notes = trim((string) ($breakdown['notes'] ?? ''));
            $breakdown['notes'] = $notes !== '' ? $notes . ' ' . $adjustmentNote : $adjustmentNote;
        }

        $trimmed[] = $breakdown;
        $remaining = round($remaining - $quantity, 2);
    }

    return $trimmed;
}

function build_handover_approval_updates(array $lines, $returnedInput, array $usageInput = []): array
{
    $errors = [];
    $updates = [];

    foreach ($lines as $line) {
        $lineId = (int) $line['id'];
        $received = handover_active_quantity($line);
        $returnedRaw = is_array($returnedInput)
            ? ($returnedInput[$lineId] ?? $returnedInput[(string) $lineId] ?? $line['quantity_returned'])
            : $line['quantity_returned'];

        if (!is_numeric_value($returnedRaw) || quantity_value($returnedRaw) < 0) {
            $errors[] = $line['item_name'] . ' must have a valid confirmed return quantity.';
            continue;
        }

        $returned = round(quantity_value($returnedRaw), 2);

        if ($returned > $received) {
            $errors[] = $line['item_name'] . ' cannot return more than the confirmed received quantity.';
            continue;
        }

        $used = round($received - $returned, 2);
        $parsedUsage = parse_handover_usage_input_rows($line, $usageInput);
        $errors = array_merge($errors, $parsedUsage['errors']);
        $breakdowns = handover_adjust_breakdowns_for_approval($line, $used);

        if ((bool) $parsedUsage['has_usage_rows']) {
            $breakdownUsed = round((float) $parsedUsage['used'], 2);

            if (abs($breakdownUsed - $used) >= 0.01) {
                $errors[] = $line['item_name'] . ' usage breakdown must total ' . format_quantity($used) . ' ' . (string) ($line['unit'] ?? 'pcs') . ' after your confirmed return.';
                continue;
            }

            $breakdowns = $parsedUsage['breakdowns'];
        }

        $updates[] = [
            'line_id' => $lineId,
            'item_id' => (int) $line['item_id'],
            'used' => $used,
            'returned' => $returned,
            'breakdowns' => $breakdowns,
        ];
    }

    return [$updates, $errors];
}

function save_handover_usage_breakdowns(int $handoverId, array $lineUpdates, int $performedBy): void
{
    $lineIds = array_values(array_unique(array_filter(array_map(static fn (array $update): int => (int) ($update['line_id'] ?? 0), $lineUpdates))));

    if ($lineIds === []) {
        return;
    }

    $params = ['handover_id' => $handoverId];
    $placeholders = [];

    foreach ($lineIds as $index => $lineId) {
        $key = 'line_id_' . $index;
        $placeholders[] = ':' . $key;
        $params[$key] = $lineId;
    }

    Database::execute(
        'DELETE FROM handover_usage_breakdowns
         WHERE handover_id = :handover_id
           AND handover_line_id IN (' . implode(', ', $placeholders) . ')',
        $params
    );

    foreach ($lineUpdates as $update) {
        foreach (($update['breakdowns'] ?? []) as $breakdown) {
            $quantity = round((float) ($breakdown['quantity'] ?? 0), 2);

            if ($quantity <= 0) {
                continue;
            }

            Database::execute(
                'INSERT INTO handover_usage_breakdowns (
                    handover_id,
                    handover_line_id,
                    item_id,
                    reason_code,
                    reason_custom,
                    quantity,
                    notes,
                    created_by,
                    updated_by,
                    created_at,
                    updated_at
                 ) VALUES (
                    :handover_id,
                    :handover_line_id,
                    :item_id,
                    :reason_code,
                    :reason_custom,
                    :quantity,
                    :notes,
                    :created_by,
                    :updated_by,
                    NOW(),
                    NOW()
                 )',
                [
                    'handover_id' => $handoverId,
                    'handover_line_id' => (int) $update['line_id'],
                    'item_id' => (int) $update['item_id'],
                    'reason_code' => normalize_handover_usage_reason((string) ($breakdown['reason_code'] ?? '')),
                    'reason_custom' => trim((string) ($breakdown['reason_custom'] ?? '')) !== '' ? trim((string) ($breakdown['reason_custom'] ?? '')) : null,
                    'quantity' => $quantity,
                    'notes' => trim((string) ($breakdown['notes'] ?? '')) !== '' ? trim((string) ($breakdown['notes'] ?? '')) : null,
                    'created_by' => $performedBy,
                    'updated_by' => $performedBy,
                ]
            );
        }
    }
}

function save_handover_expected_usage_breakdowns(int $handoverId, array $lineUpdates, int $performedBy): void
{
    $lineIds = array_values(array_unique(array_filter(array_map(static fn (array $update): int => (int) ($update['line_id'] ?? 0), $lineUpdates))));

    if ($lineIds === []) {
        return;
    }

    $params = ['handover_id' => $handoverId];
    $placeholders = [];

    foreach ($lineIds as $index => $lineId) {
        $key = 'line_id_' . $index;
        $placeholders[] = ':' . $key;
        $params[$key] = $lineId;
    }

    Database::execute(
        'DELETE FROM handover_expected_usage_breakdowns
         WHERE handover_id = :handover_id
           AND handover_line_id IN (' . implode(', ', $placeholders) . ')',
        $params
    );

    foreach ($lineUpdates as $update) {
        foreach (($update['breakdowns'] ?? []) as $breakdown) {
            $quantity = round((float) ($breakdown['quantity'] ?? 0), 2);

            if ($quantity <= 0) {
                continue;
            }

            Database::execute(
                'INSERT INTO handover_expected_usage_breakdowns (
                    handover_id,
                    handover_line_id,
                    item_id,
                    reason_code,
                    reason_custom,
                    quantity,
                    notes,
                    created_by,
                    updated_by,
                    created_at,
                    updated_at
                 ) VALUES (
                    :handover_id,
                    :handover_line_id,
                    :item_id,
                    :reason_code,
                    :reason_custom,
                    :quantity,
                    :notes,
                    :created_by,
                    :updated_by,
                    NOW(),
                    NOW()
                 )',
                [
                    'handover_id' => $handoverId,
                    'handover_line_id' => (int) $update['line_id'],
                    'item_id' => (int) $update['item_id'],
                    'reason_code' => normalize_handover_usage_reason((string) ($breakdown['reason_code'] ?? '')),
                    'reason_custom' => trim((string) ($breakdown['reason_custom'] ?? '')) !== '' ? trim((string) ($breakdown['reason_custom'] ?? '')) : null,
                    'quantity' => $quantity,
                    'notes' => trim((string) ($breakdown['notes'] ?? '')) !== '' ? trim((string) ($breakdown['notes'] ?? '')) : null,
                    'created_by' => $performedBy,
                    'updated_by' => $performedBy,
                ]
            );
        }
    }
}
