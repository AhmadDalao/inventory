<?php
declare(strict_types=1);

// Reorder filters and low-stock suggestion queries.

function reorder_filters(): array
{
    return [
        'search' => trim((string) query('search', '')),
        'storage_id' => ctype_digit((string) query('storage_id', '')) ? (int) query('storage_id') : null,
        'include_zero_policy' => (string) query('include_zero_policy', '') === '1',
    ];
}

function reorder_suggestion_rows(array $filters): array
{
    $conditions = [
        'item.is_active = 1',
        'storage.is_active = 1',
        'storage.is_system = 0',
        'balance.quantity <= item.reorder_level',
    ];
    $params = [];

    if (empty($filters['include_zero_policy'])) {
        $conditions[] = 'item.reorder_level > 0';
    }

    if (!empty($filters['storage_id'])) {
        $conditions[] = 'storage.id = :storage_id';
        $params['storage_id'] = (int) $filters['storage_id'];
    }

    if (($filters['search'] ?? '') !== '') {
        $conditions[] = '(item.name LIKE :reorder_search_item OR item.sku LIKE :reorder_search_sku OR COALESCE(item.barcode, "") LIKE :reorder_search_barcode OR storage.name LIKE :reorder_search_storage)';
        $params['reorder_search_item'] = '%' . $filters['search'] . '%';
        $params['reorder_search_sku'] = '%' . $filters['search'] . '%';
        $params['reorder_search_barcode'] = '%' . $filters['search'] . '%';
        $params['reorder_search_storage'] = '%' . $filters['search'] . '%';
    }

    return Database::fetchAll(
        'SELECT item.id AS item_id,
                item.name AS item_name,
                item.sku,
                item.barcode,
                item.unit,
                item.category,
                item.cost_per_unit,
                item.image_path,
                item.reorder_level,
                storage.id AS storage_id,
                storage.name AS storage_name,
                storage.storage_type,
                balance.quantity,
                GREATEST(item.reorder_level - balance.quantity, 0) AS suggested_quantity
         FROM item_storage_balances balance
         INNER JOIN items item ON item.id = balance.item_id
         INNER JOIN storages storage ON storage.id = balance.storage_id
         WHERE ' . implode(' AND ', $conditions) . '
         ORDER BY storage.name ASC, suggested_quantity DESC, item.name ASC',
        $params
    );
}
