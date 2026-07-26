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

    $dateFrom = report_summary_valid_date($dateFrom) ? $dateFrom : $today;
    $dateTo = report_summary_valid_date($dateTo) ? $dateTo : $today;

    if ($dateFrom > $dateTo) {
        [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
    }

    return [
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'storage_id' => ctype_digit((string) query('storage_id', '')) ? (int) query('storage_id') : null,
        'movement_type' => in_array($type, ['restock', 'usage', 'adjustment', 'transfer'], true) ? $type : '',
        'item_status' => in_array($itemStatus, ['all', 'active', 'deleted'], true) ? $itemStatus : 'all',
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
