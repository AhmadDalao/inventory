<?php
declare(strict_types=1);

function handover_usage_reason_options(): array
{
    return [
        'unspecified' => 'Unspecified',
        'walkin' => 'Walk-in',
        'online' => 'Online',
        'event' => 'Event',
        'damage' => 'Damage',
        'sport' => 'Sport',
        'school' => 'School',
        'complimentary' => 'Complimentary',
        'noshow' => 'No Show',
        'other' => 'Other',
    ];
}

function handover_usage_reason_label(string $code, string $custom = ''): string
{
    $code = normalize_handover_usage_reason($code);
    $label = handover_usage_reason_options()[$code] ?? handover_usage_reason_options()['unspecified'];
    $custom = trim($custom);

    if ($code === 'other' && $custom !== '') {
        return $label . ': ' . $custom;
    }

    return $label;
}

function handover_usage_reason_summary(array $breakdowns, string $unit = 'pcs'): string
{
    $totals = [];

    foreach ($breakdowns as $breakdown) {
        $quantity = round((float) ($breakdown['quantity'] ?? 0), 2);

        if ($quantity <= 0) {
            continue;
        }

        $label = handover_usage_reason_label(
            (string) ($breakdown['reason_code'] ?? 'unspecified'),
            (string) ($breakdown['reason_custom'] ?? '')
        );
        $key = $label . '|' . $unit;

        if (!isset($totals[$key])) {
            $totals[$key] = [
                'label' => $label,
                'unit' => $unit !== '' ? $unit : 'pcs',
                'quantity' => 0.0,
            ];
        }

        $totals[$key]['quantity'] = round($totals[$key]['quantity'] + $quantity, 2);
    }

    if ($totals === []) {
        return '';
    }

    $parts = [];

    foreach ($totals as $total) {
        $parts[] = $total['label'] . ' ' . format_quantity((float) $total['quantity']) . ' ' . $total['unit'];
    }

    return implode('; ', $parts);
}

function handover_usage_variance_summary(array $expectedBreakdowns, array $actualBreakdowns, string $unit = 'pcs'): string
{
    $hasActual = false;
    $totals = [];
    $unit = $unit !== '' ? $unit : 'pcs';
    $collect = static function (array $breakdowns, float $multiplier) use (&$totals, &$hasActual, $unit): void {
        foreach ($breakdowns as $breakdown) {
            $quantity = round((float) ($breakdown['quantity'] ?? 0), 2);

            if ($quantity <= 0) {
                continue;
            }

            if ($multiplier > 0) {
                $hasActual = true;
            }

            $reasonCode = normalize_handover_usage_reason((string) ($breakdown['reason_code'] ?? 'unspecified'));
            $label = handover_usage_reason_label(
                $reasonCode,
                (string) ($breakdown['reason_custom'] ?? '')
            );
            $key = $label . '|' . $unit;

            if (!isset($totals[$key])) {
                $totals[$key] = [
                    'label' => $label,
                    'reason_code' => $reasonCode,
                    'unit' => $unit,
                    'quantity' => 0.0,
                ];
            }

            $totals[$key]['quantity'] = round($totals[$key]['quantity'] + ($quantity * $multiplier), 2);
        }
    };

    $collect($expectedBreakdowns, -1.0);
    $collect($actualBreakdowns, 1.0);

    if (!$hasActual) {
        return '';
    }

    $parts = [];

    foreach ($totals as $total) {
        $quantity = round((float) ($total['quantity'] ?? 0), 2);

        if (abs($quantity) < 0.01) {
            continue;
        }

        $prefix = $quantity > 0 ? '+' : '';
        $parts[] = $total['label'] . ' ' . $prefix . format_quantity($quantity) . ' ' . $total['unit'];
    }

    return $parts !== [] ? implode('; ', $parts) : 'No variance';
}

function handover_usage_breakdowns_for_lines(array $lineIds): array
{
    $lineIds = array_values(array_unique(array_filter(array_map('intval', $lineIds), static fn (int $lineId): bool => $lineId > 0)));

    if ($lineIds === []) {
        return [];
    }

    $params = [];
    $placeholders = [];

    foreach ($lineIds as $index => $lineId) {
        $key = 'line_id_' . $index;
        $placeholders[] = ':' . $key;
        $params[$key] = $lineId;
    }

    $rows = Database::fetchAll(
        'SELECT *
         FROM handover_usage_breakdowns
         WHERE handover_line_id IN (' . implode(', ', $placeholders) . ')
         ORDER BY handover_line_id ASC, id ASC',
        $params
    );
    $grouped = [];

    foreach ($rows as $row) {
        $lineId = (int) $row['handover_line_id'];
        $row['reason_code'] = normalize_handover_usage_reason((string) ($row['reason_code'] ?? ''));
        $row['reason_label'] = handover_usage_reason_label((string) $row['reason_code'], (string) ($row['reason_custom'] ?? ''));
        $row['quantity'] = round((float) ($row['quantity'] ?? 0), 2);
        $grouped[$lineId][] = $row;
    }

    return $grouped;
}

function handover_expected_usage_breakdowns_for_lines(array $lineIds): array
{
    $lineIds = array_values(array_unique(array_filter(array_map('intval', $lineIds), static fn (int $lineId): bool => $lineId > 0)));

    if ($lineIds === []) {
        return [];
    }

    $params = [];
    $placeholders = [];

    foreach ($lineIds as $index => $lineId) {
        $key = 'line_id_' . $index;
        $placeholders[] = ':' . $key;
        $params[$key] = $lineId;
    }

    $rows = Database::fetchAll(
        'SELECT *
         FROM handover_expected_usage_breakdowns
         WHERE handover_line_id IN (' . implode(', ', $placeholders) . ')
         ORDER BY handover_line_id ASC, id ASC',
        $params
    );
    $grouped = [];

    foreach ($rows as $row) {
        $lineId = (int) $row['handover_line_id'];
        $row['reason_code'] = normalize_handover_usage_reason((string) ($row['reason_code'] ?? ''));
        $row['reason_label'] = handover_usage_reason_label((string) $row['reason_code'], (string) ($row['reason_custom'] ?? ''));
        $row['quantity'] = round((float) ($row['quantity'] ?? 0), 2);
        $grouped[$lineId][] = $row;
    }

    return $grouped;
}

function parse_handover_expected_usage_by_item(array $lines): array
{
    $itemIds = input('line_item_id', []);
    $reasons = input('expected_usage_reason', []);
    $quantities = input('expected_usage_quantity', []);
    $customReasons = input('expected_usage_other', []);
    $notes = input('expected_usage_notes', []);

    if (!is_array($itemIds)) {
        return [[], []];
    }

    $lineQuantityByItem = [];

    foreach ($lines as $line) {
        $itemId = (int) ($line['item_id'] ?? 0);

        if ($itemId <= 0) {
            continue;
        }

        $lineQuantityByItem[$itemId] = round(($lineQuantityByItem[$itemId] ?? 0.0) + (float) ($line['quantity'] ?? 0), 2);
    }

    $breakdownsByItem = [];
    $errors = [];

    foreach ($itemIds as $lineIndex => $rawItemId) {
        $itemId = normalize_entity_id($rawItemId);

        if ($itemId === null || !isset($lineQuantityByItem[$itemId])) {
            continue;
        }

        $lineReasons = is_array($reasons[$lineIndex] ?? null) ? $reasons[$lineIndex] : [];
        $lineQuantities = is_array($quantities[$lineIndex] ?? null) ? $quantities[$lineIndex] : [];
        $lineCustomReasons = is_array($customReasons[$lineIndex] ?? null) ? $customReasons[$lineIndex] : [];
        $lineNotes = is_array($notes[$lineIndex] ?? null) ? $notes[$lineIndex] : [];
        $rowKeys = array_unique(array_merge(
            array_keys($lineReasons),
            array_keys($lineQuantities),
            array_keys($lineCustomReasons),
            array_keys($lineNotes)
        ));

        foreach ($rowKeys as $rowKey) {
            $rawQuantity = $lineQuantities[$rowKey] ?? '';
            $rawReason = (string) ($lineReasons[$rowKey] ?? 'unspecified');
            $rawCustomReason = trim((string) ($lineCustomReasons[$rowKey] ?? ''));
            $rawNotes = trim((string) ($lineNotes[$rowKey] ?? ''));
            $hasAnyInput = trim((string) $rawQuantity) !== ''
                || trim($rawReason) !== ''
                || $rawCustomReason !== ''
                || $rawNotes !== '';

            if (!$hasAnyInput || (trim((string) $rawQuantity) === '' && $rawCustomReason === '' && $rawNotes === '')) {
                continue;
            }

            if (!is_numeric_value($rawQuantity) || quantity_value($rawQuantity) <= 0) {
                $errors[] = 'Expected usage rows need a quantity greater than zero.';
                continue;
            }

            $breakdownsByItem[$itemId][] = [
                'reason_code' => normalize_handover_usage_reason($rawReason),
                'reason_custom' => $rawCustomReason,
                'quantity' => quantity_value($rawQuantity),
                'notes' => $rawNotes,
            ];
        }
    }

    foreach ($breakdownsByItem as $itemId => $breakdowns) {
        $expectedTotal = round(array_reduce(
            $breakdowns,
            static fn (float $carry, array $breakdown): float => $carry + (float) ($breakdown['quantity'] ?? 0),
            0.0
        ), 2);

        if ($expectedTotal > round((float) ($lineQuantityByItem[$itemId] ?? 0), 2)) {
            $errors[] = 'Expected usage cannot be greater than the planned handover quantity.';
        }
    }

    return [$breakdownsByItem, $errors];
}

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

function handover_close_nested_values(array $usageInput, string $key, int $lineId): array
{
    $values = $usageInput[$key] ?? [];

    if (!is_array($values)) {
        return [];
    }

    $lineValues = $values[$lineId] ?? $values[(string) $lineId] ?? [];

    if (!is_array($lineValues)) {
        return $lineValues !== '' ? [$lineValues] : [];
    }

    return array_values($lineValues);
}

function parse_handover_usage_input_rows(array $line, array $usageInput): array
{
    $errors = [];
    $lineId = (int) $line['id'];
    $quantityRows = handover_close_nested_values($usageInput, 'quantity', $lineId);
    $reasonRows = handover_close_nested_values($usageInput, 'reason', $lineId);
    $otherRows = handover_close_nested_values($usageInput, 'other', $lineId);
    $noteRows = handover_close_nested_values($usageInput, 'notes', $lineId);
    $rowCount = max(count($quantityRows), count($reasonRows), count($otherRows), count($noteRows));
    $breakdowns = [];
    $hasUsageRows = false;
    $used = 0.0;

    for ($index = 0; $index < $rowCount; $index++) {
        $quantityRaw = trim((string) ($quantityRows[$index] ?? ''));
        $reasonRaw = trim((string) ($reasonRows[$index] ?? ''));
        $otherRaw = trim((string) ($otherRows[$index] ?? ''));
        $noteRaw = trim((string) ($noteRows[$index] ?? ''));
        $reasonCode = normalize_handover_usage_reason($reasonRaw);
        $hasMeaningfulReason = $reasonRaw !== '' && $reasonCode !== 'unspecified';
        $hasMeaningfulQuantity = $quantityRaw !== ''
            && (
                !is_numeric_value($quantityRaw)
                || quantity_value($quantityRaw) < 0
                || round(quantity_value($quantityRaw), 2) > 0
            );
        $hasRowData = $hasMeaningfulQuantity || $hasMeaningfulReason || $otherRaw !== '' || $noteRaw !== '';

        if (!$hasRowData) {
            continue;
        }

        $hasUsageRows = true;

        if ($quantityRaw === '') {
            $errors[] = $line['item_name'] . ' has a usage reason without a quantity.';
            continue;
        }

        if (!is_numeric_value($quantityRaw) || quantity_value($quantityRaw) < 0) {
            $errors[] = 'Usage reason quantities must be zero or more for every line.';
            continue;
        }

        $quantity = round(quantity_value($quantityRaw), 2);

        if ($quantity <= 0) {
            continue;
        }

        $breakdowns[] = [
            'reason_code' => $reasonCode,
            'reason_custom' => $reasonCode === 'other' ? $otherRaw : '',
            'quantity' => $quantity,
            'notes' => $noteRaw,
        ];
        $used = round($used + $quantity, 2);
    }

    return [
        'breakdowns' => $breakdowns,
        'errors' => $errors,
        'has_usage_rows' => $hasUsageRows,
        'used' => $used,
    ];
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
