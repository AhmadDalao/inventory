<?php
declare(strict_types=1);

// Report summary filters and display labels.

function report_summary_filters(): array
{
    $date = trim((string) query('date', date('Y-m-d')));
    $type = trim((string) query('movement_type', ''));
    $itemStatus = trim((string) query('item_status', 'all'));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = date('Y-m-d');
    }

    return [
        'date' => $date,
        'storage_id' => ctype_digit((string) query('storage_id', '')) ? (int) query('storage_id') : null,
        'movement_type' => in_array($type, ['restock', 'usage', 'adjustment', 'transfer'], true) ? $type : '',
        'item_status' => in_array($itemStatus, ['all', 'active', 'deleted'], true) ? $itemStatus : 'all',
    ];
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
