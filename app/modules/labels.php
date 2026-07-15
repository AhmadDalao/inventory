<?php
declare(strict_types=1);

// Domain module: labels. Function names are preserved for route/view compatibility.

// Moved from workflows.php.

function label_filters(): array
{
    $type = (string) query('type', 'items');

    return [
        'type' => in_array($type, ['items', 'storages'], true) ? $type : 'items',
        'search' => trim((string) query('search', '')),
        'storage_id' => ctype_digit((string) query('storage_id', '')) ? (int) query('storage_id') : null,
    ];
}

function label_rows(array $filters): array
{
    if (($filters['type'] ?? 'items') === 'storages') {
        $conditions = ['is_active = 1', 'is_system = 0'];
        $params = [];

        if (($filters['search'] ?? '') !== '') {
            $conditions[] = '(name LIKE :label_search_name OR storage_type LIKE :label_search_type)';
            $params['label_search_name'] = '%' . $filters['search'] . '%';
            $params['label_search_type'] = '%' . $filters['search'] . '%';
        }

        $rows = Database::fetchAll(
            'SELECT id, name, storage_type, notes
             FROM storages
             WHERE ' . implode(' AND ', $conditions) . '
             ORDER BY FIELD(storage_type, "warehouse", "storage"), name ASC',
            $params
        );

        return array_map(static function (array $row): array {
            return [
                'label_type' => 'Storage',
                'title' => (string) $row['name'],
                'subtitle' => storage_type_label((string) $row['storage_type']),
                'code' => 'STORAGE-' . (int) $row['id'],
                'url' => url('/storages/' . $row['id']),
            ];
        }, $rows);
    }

    $conditions = ['item.is_active = 1'];
    $params = [];

    if (!empty($filters['storage_id'])) {
        $conditions[] = 'EXISTS (
            SELECT 1
            FROM item_storage_balances balance
            WHERE balance.item_id = item.id
              AND balance.storage_id = :label_storage_id
        )';
        $params['label_storage_id'] = (int) $filters['storage_id'];
    }

    if (($filters['search'] ?? '') !== '') {
        $conditions[] = '(item.name LIKE :label_search_name OR item.sku LIKE :label_search_sku OR COALESCE(item.barcode, "") LIKE :label_search_barcode OR COALESCE(item.category, "") LIKE :label_search_category)';
        $params['label_search_name'] = '%' . $filters['search'] . '%';
        $params['label_search_sku'] = '%' . $filters['search'] . '%';
        $params['label_search_barcode'] = '%' . $filters['search'] . '%';
        $params['label_search_category'] = '%' . $filters['search'] . '%';
    }

    $rows = Database::fetchAll(
        'SELECT item.id, item.name, item.sku, item.barcode, item.unit, item.category, item.image_path
         FROM items item
         WHERE ' . implode(' AND ', $conditions) . '
         ORDER BY item.name ASC',
        $params
    );

    return array_map(static function (array $row): array {
        $scanCode = item_scan_code($row);

        return [
            'label_type' => 'Item',
            'title' => (string) $row['name'],
            'subtitle' => (string) $row['sku'] . ' · ' . (normalize_item_barcode($row['barcode'] ?? '') !== '' ? 'Barcode ' . (string) $row['barcode'] : 'SKU label') . ' · ' . (string) $row['unit'],
            'code' => code39_normalize($scanCode),
            'raw_code' => $scanCode,
            'url' => url('/items/' . $row['id']),
            'image_url' => item_image_url($row['image_path'] ?? null),
        ];
    }, $rows);
}

function handle_labels_index(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('labels.view');

    $filters = label_filters();

    View::render('labels/index', [
        'title' => site_setting('page.labels', 'Labels'),
        'filters' => $filters,
        'rows' => label_rows($filters),
        'storages' => all_storages_for_select($filters['storage_id']),
    ]);
}
