<?php
declare(strict_types=1);

// Domain module: handover usage breakdown queries and line hydration.

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

function hydrate_handover_lines_usage_breakdowns(array $lines): array
{
    $groups = handover_usage_breakdowns_for_lines(array_column($lines, 'id'));

    foreach ($lines as &$line) {
        $lineId = (int) ($line['id'] ?? 0);
        $breakdowns = $groups[$lineId] ?? [];
        $used = round((float) ($line['quantity_used'] ?? 0), 2);

        if ($breakdowns === [] && $used > 0) {
            $breakdowns[] = [
                'handover_line_id' => $lineId,
                'item_id' => (int) ($line['item_id'] ?? 0),
                'reason_code' => 'unspecified',
                'reason_custom' => '',
                'reason_label' => handover_usage_reason_label('unspecified'),
                'quantity' => $used,
                'notes' => '',
            ];
        }

        $line['usage_breakdowns'] = $breakdowns;
        $line['usage_reason_summary'] = handover_usage_reason_summary($breakdowns, (string) ($line['unit'] ?? 'pcs'));
    }
    unset($line);

    return $lines;
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

function hydrate_handover_lines_expected_usage_breakdowns(array $lines): array
{
    $groups = handover_expected_usage_breakdowns_for_lines(array_column($lines, 'id'));

    foreach ($lines as &$line) {
        $lineId = (int) ($line['id'] ?? 0);
        $breakdowns = $groups[$lineId] ?? [];
        $unit = (string) ($line['unit'] ?? 'pcs');
        $line['expected_usage_breakdowns'] = $breakdowns;
        $line['expected_usage_reason_summary'] = handover_usage_reason_summary($breakdowns, $unit);
        $line['usage_variance_summary'] = handover_usage_variance_summary(
            $breakdowns,
            (array) ($line['usage_breakdowns'] ?? []),
            $unit
        );
    }
    unset($line);

    return $lines;
}
