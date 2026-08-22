<?php
declare(strict_types=1);

// Report summary filters and display labels.

function report_summary_filters(): array
{
    $today = date('Y-m-d');
    $legacyDate = trim((string) query('date', ''));
    $dateFrom = trim((string) query('date_from', $legacyDate !== '' ? $legacyDate : $today));
    $dateTo = trim((string) query('date_to', $legacyDate !== '' ? $legacyDate : $today));
    $type = trim((string) query('movement_type', ''));
    $itemStatus = trim((string) query('item_status', 'all'));
    $reason = trim((string) query('reason', ''));
    $unit = trim((string) query('unit', ''));

    $dateFrom = report_summary_valid_date($dateFrom) ? $dateFrom : $today;
    $dateTo = report_summary_valid_date($dateTo) ? $dateTo : $today;

    if ($dateFrom > $dateTo) {
        [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
    }

    return [
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'storage_id' => ctype_digit((string) query('storage_id', '')) ? (int) query('storage_id') : null,
        'item_id' => ctype_digit((string) query('item_id', '')) ? (int) query('item_id') : null,
        'department_id' => ctype_digit((string) query('department_id', '')) ? (int) query('department_id') : null,
        'employee_id' => ctype_digit((string) query('employee_id', '')) ? (int) query('employee_id') : null,
        'manager_id' => ctype_digit((string) query('manager_id', '')) ? (int) query('manager_id') : null,
        'package_preset_id' => ctype_digit((string) query('package_preset_id', '')) ? (int) query('package_preset_id') : null,
        'movement_type' => in_array($type, ['restock', 'usage', 'adjustment', 'transfer'], true) ? $type : '',
        'item_status' => in_array($itemStatus, ['all', 'active', 'deleted'], true) ? $itemStatus : 'all',
        'reason' => $reason !== '' ? mobile_usage_reason_normalize_code($reason) : '',
        'unit' => mb_substr($unit, 0, 32),
    ];
}

function report_summary_valid_date(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

    return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value;
}

function report_summary_period_label(array $filters): string
{
    $dateFrom = (string) ($filters['date_from'] ?? date('Y-m-d'));
    $dateTo = (string) ($filters['date_to'] ?? $dateFrom);
    $fromLabel = date('M j, Y', strtotime($dateFrom));
    $toLabel = date('M j, Y', strtotime($dateTo));

    return $dateFrom === $dateTo
        ? 'On ' . $fromLabel
        : 'From ' . $fromLabel . ' To ' . $toLabel;
}

function report_summary_period_filename(array $filters): string
{
    $dateFrom = str_replace('-', '', (string) ($filters['date_from'] ?? date('Y-m-d')));
    $dateTo = str_replace('-', '', (string) ($filters['date_to'] ?? $filters['date_from'] ?? date('Y-m-d')));

    return $dateFrom === $dateTo ? $dateFrom : $dateFrom . '-to-' . $dateTo;
}

function report_summary_quantity_expression(string $alias = 'm'): string
{
    return "ABS(COALESCE(NULLIF({$alias}.movement_quantity, 0), {$alias}.quantity_delta, 0))";
}

function report_summary_base_quantity_expression(string $movementAlias = 'm', string $measurementAlias = 'md'): string
{
    return "ABS(COALESCE({$measurementAlias}.base_quantity, NULLIF({$movementAlias}.movement_quantity, 0), {$movementAlias}.quantity_delta, 0))";
}

function report_summary_base_unit_expression(string $itemAlias = 'i', string $measurementAlias = 'md'): string
{
    return "COALESCE(NULLIF({$measurementAlias}.base_unit, ''), " . inventory_item_unit_sql_expression($itemAlias) . ')';
}

function report_summary_filter_query(array $filters): array
{
    return array_filter([
        'date_from' => $filters['date_from'] ?? null,
        'date_to' => $filters['date_to'] ?? null,
        'storage_id' => $filters['storage_id'] ?? null,
        'item_id' => $filters['item_id'] ?? null,
        'department_id' => $filters['department_id'] ?? null,
        'employee_id' => $filters['employee_id'] ?? null,
        'manager_id' => $filters['manager_id'] ?? null,
        'package_preset_id' => $filters['package_preset_id'] ?? null,
        'movement_type' => $filters['movement_type'] ?? '',
        'item_status' => ($filters['item_status'] ?? 'all') !== 'all' ? $filters['item_status'] : null,
        'reason' => $filters['reason'] ?? '',
        'unit' => $filters['unit'] ?? '',
    ], static fn ($value): bool => $value !== '' && $value !== null);
}

function report_summary_unit_totals_text(array $totals, string $empty = '0'): string
{
    $parts = [];

    foreach ($totals as $row) {
        $quantity = (float) ($row['quantity'] ?? 0);
        $unit = trim((string) ($row['unit'] ?? '')) ?: 'pcs';

        if (abs($quantity) < 0.0000005) {
            continue;
        }

        $parts[] = format_quantity($quantity) . ' ' . $unit;
    }

    return $parts === [] ? $empty : implode(' · ', $parts);
}

/**
 * @return array<int, array{id: int, name: string}>
 */
function report_summary_proof_file_entries(?string $proofFiles): array
{
    $entries = [];

    foreach (preg_split('/\s*\|\s*/', trim((string) $proofFiles)) ?: [] as $value) {
        if ($value === '' || preg_match('/^(\d+):(.*)$/s', $value, $matches) !== 1) {
            continue;
        }

        $id = (int) $matches[1];
        $name = trim((string) $matches[2]);

        if ($id <= 0 || isset($entries[$id])) {
            continue;
        }

        $entries[$id] = [
            'id' => $id,
            'name' => $name !== '' ? $name : 'Proof file',
        ];
    }

    return array_values($entries);
}

function report_summary_proof_file_names_text(?string $proofFiles): string
{
    return implode('; ', array_column(report_summary_proof_file_entries($proofFiles), 'name'));
}

function report_summary_storage_label(?int $storageId): string
{
    if ($storageId === null) {
        return 'All locations';
    }

    $storage = Database::fetch(
        'SELECT name, storage_type FROM storages WHERE id = :id LIMIT 1',
        ['id' => $storageId]
    );

    if (!$storage) {
        return 'Unknown location';
    }

    return storage_type_label((string) $storage['storage_type']) . ' · ' . (string) $storage['name'];
}

function report_summary_movement_label(string $movementType): string
{
    return $movementType === '' ? 'All movement types' : ucfirst($movementType);
}

function report_summary_item_status_label(string $status): string
{
    if ($status === 'active') {
        return 'Active items';
    }

    if ($status === 'deleted') {
        return 'Deleted items';
    }

    return 'All item statuses';
}

function report_summary_item_record_status_label($isActive): string
{
    if ($isActive === null || $isActive === '') {
        return 'Unknown';
    }

    return (int) $isActive === 1 ? 'Active' : 'Deleted';
}
