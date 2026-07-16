<?php
declare(strict_types=1);

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
