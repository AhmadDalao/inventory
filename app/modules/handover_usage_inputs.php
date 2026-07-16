<?php
declare(strict_types=1);

// Domain module: handover expected/actual usage form input parsing.

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
