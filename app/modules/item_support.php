<?php
declare(strict_types=1);

// Domain module: item support helpers. Function names are preserved for route/view compatibility.

function item_filters(): array
{
    $status = (string) query('status', 'all');

    return [
        'search' => trim((string) query('search', '')),
        'status' => in_array($status, ['active', 'archived', 'all'], true) ? $status : 'all',
        'storage_id' => ctype_digit((string) query('storage_id', '')) ? (int) query('storage_id') : null,
    ];
}

function build_item_where(array $filters, string $alias = 'i'): array
{
    $conditions = [];
    $params = [];

    if ($filters['status'] === 'active') {
        $conditions[] = "{$alias}.is_active = 1";
    } elseif ($filters['status'] === 'archived') {
        $conditions[] = "{$alias}.is_active = 0";
    }

    if ($filters['search'] !== '') {
        $conditions[] = "(
            {$alias}.name LIKE :search_name
            OR {$alias}.sku LIKE :search_sku
            OR COALESCE({$alias}.barcode, '') LIKE :search_barcode
            OR COALESCE({$alias}.category, '') LIKE :search_category
            OR EXISTS (
                SELECT 1
                FROM item_storage_balances item_balances
                INNER JOIN storages matched_storage ON matched_storage.id = item_balances.storage_id
                WHERE item_balances.item_id = {$alias}.id
                  AND matched_storage.name LIKE :search_storage
            )
        )";
        $params['search_name'] = '%' . $filters['search'] . '%';
        $params['search_sku'] = '%' . $filters['search'] . '%';
        $params['search_barcode'] = '%' . $filters['search'] . '%';
        $params['search_category'] = '%' . $filters['search'] . '%';
        $params['search_storage'] = '%' . $filters['search'] . '%';
    }

    if ($filters['storage_id']) {
        $conditions[] = "EXISTS (
            SELECT 1
            FROM item_storage_balances filtered_balances
            WHERE filtered_balances.item_id = {$alias}.id
              AND filtered_balances.storage_id = :storage_id
        )";
        $params['storage_id'] = $filters['storage_id'];
    }

    return [
        $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '',
        $params,
    ];
}

function item_filtered_storage_quantity_select(array $filters, array &$params, string $paramName = 'item_filtered_storage_id'): string
{
    if (empty($filters['storage_id'])) {
        return 'NULL AS filtered_storage_quantity';
    }

    $params[$paramName] = (int) $filters['storage_id'];

    return "(
        SELECT filtered_balance.quantity
        FROM item_storage_balances filtered_balance
        WHERE filtered_balance.item_id = i.id
          AND filtered_balance.storage_id = :{$paramName}
        LIMIT 1
    ) AS filtered_storage_quantity";
}

function item_display_quantity(array $item): float
{
    if (array_key_exists('filtered_storage_quantity', $item) && $item['filtered_storage_quantity'] !== null) {
        return (float) $item['filtered_storage_quantity'];
    }

    return (float) ($item['current_quantity'] ?? 0);
}

function active_item_by_sku(string $sku, ?int $ignoreId = null): ?array
{
    $sku = strtoupper(trim($sku));

    if ($sku === '') {
        return null;
    }

    $sql = 'SELECT * FROM items WHERE sku = :sku AND is_active = 1';
    $params = ['sku' => $sku];

    if ($ignoreId !== null) {
        $sql .= ' AND id != :ignore_id';
        $params['ignore_id'] = $ignoreId;
    }

    $sql .= ' ORDER BY id ASC LIMIT 1';

    return Database::fetch($sql, $params);
}

function active_item_sku_exists(string $sku, ?int $ignoreId = null): bool
{
    return active_item_by_sku($sku, $ignoreId) !== null;
}

function active_item_by_barcode(string $barcode, ?int $ignoreId = null): ?array
{
    $barcode = normalize_item_barcode($barcode);

    if ($barcode === '') {
        return null;
    }

    $sql = 'SELECT * FROM items WHERE barcode = :barcode AND is_active = 1';
    $params = ['barcode' => $barcode];

    if ($ignoreId !== null) {
        $sql .= ' AND id != :ignore_id';
        $params['ignore_id'] = $ignoreId;
    }

    $sql .= ' ORDER BY id ASC LIMIT 1';

    return Database::fetch($sql, $params);
}

function active_item_barcode_exists(string $barcode, ?int $ignoreId = null): bool
{
    return active_item_by_barcode($barcode, $ignoreId) !== null;
}

function requested_item_copy_source(): ?array
{
    $copyItemId = normalize_entity_id(input('copy_item_id', input('copy', old('copy_item_id'))));

    if ($copyItemId === null) {
        return null;
    }

    return find_item_or_abort($copyItemId);
}

function find_item_or_abort(int $itemId): array
{
    $item = Database::fetch(
        'SELECT i.*,
                default_storage.name AS default_storage_name,
                default_storage.storage_type AS default_storage_type,
                creator.name AS creator_name,
                updater.name AS updater_name,
                (
                    SELECT COUNT(*)
                    FROM item_storage_balances balances
                    WHERE balances.item_id = i.id
                ) AS location_count,
                (
                    SELECT GROUP_CONCAT(location_storage.name ORDER BY location_balances.quantity DESC, location_storage.name ASC SEPARATOR ", ")
                    FROM item_storage_balances location_balances
                    INNER JOIN storages location_storage ON location_storage.id = location_balances.storage_id
                    WHERE location_balances.item_id = i.id
                ) AS location_summary
         FROM items i
         LEFT JOIN storages default_storage ON default_storage.id = i.storage_id
         LEFT JOIN users creator ON creator.id = i.created_by
         LEFT JOIN users updater ON updater.id = i.updated_by
         WHERE i.id = :id
         LIMIT 1',
        ['id' => $itemId]
    );

    if (!$item) {
        abort(404, 'Item not found.');
    }

    return $item;
}

function item_history_metrics(int $itemId): array
{
    return Database::fetch(
        'SELECT
             COALESCE(SUM(CASE WHEN movement_type = "usage" THEN movement_quantity ELSE 0 END), 0) AS total_used,
             COALESCE(SUM(CASE WHEN movement_type = "restock" THEN movement_quantity WHEN movement_type = "adjustment" AND quantity_delta > 0 THEN quantity_delta ELSE 0 END), 0) AS total_added,
             COALESCE(SUM(CASE WHEN movement_type = "transfer" THEN movement_quantity ELSE 0 END), 0) AS total_transferred,
             COUNT(*) AS movement_count
         FROM inventory_movements
         WHERE item_id = :item_id',
        ['item_id' => $itemId]
    ) ?: [
        'total_used' => 0,
        'total_added' => 0,
        'total_transferred' => 0,
        'movement_count' => 0,
    ];
}

function latest_item_movement(int $itemId): ?array
{
    return Database::fetch(
        'SELECT m.*,
                u.name AS user_name,
                source_storage.name AS source_storage_name,
                source_storage.storage_type AS source_storage_type,
                destination_storage.name AS destination_storage_name,
                destination_storage.storage_type AS destination_storage_type
         FROM inventory_movements m
         LEFT JOIN users u ON u.id = m.performed_by
         LEFT JOIN storages source_storage ON source_storage.id = m.source_storage_id
         LEFT JOIN storages destination_storage ON destination_storage.id = m.destination_storage_id
         WHERE m.item_id = :item_id
         ORDER BY m.used_at DESC, m.id DESC
         LIMIT 1',
        ['item_id' => $itemId]
    );
}

function item_storage_balances(int $itemId): array
{
    return Database::fetchAll(
        'SELECT balances.item_id,
                balances.storage_id,
                balances.quantity,
                storage.name,
                storage.storage_type,
                storage.is_active,
                (
                    SELECT COALESCE(SUM(movement_quantity), 0)
                    FROM inventory_movements movements
                    WHERE movements.item_id = balances.item_id
                      AND movements.source_storage_id = balances.storage_id
                      AND movements.movement_type = "usage"
                ) AS total_used,
                (
                    SELECT COALESCE(SUM(movement_quantity), 0)
                    FROM inventory_movements movements
                    WHERE movements.item_id = balances.item_id
                      AND movements.source_storage_id = balances.storage_id
                      AND movements.movement_type = "transfer"
                ) AS transferred_out,
                (
                    SELECT COALESCE(SUM(movement_quantity), 0)
                    FROM inventory_movements movements
                    WHERE movements.item_id = balances.item_id
                      AND movements.destination_storage_id = balances.storage_id
                      AND movements.movement_type = "transfer"
                ) AS transferred_in
         FROM item_storage_balances balances
         INNER JOIN storages storage ON storage.id = balances.storage_id
         WHERE balances.item_id = :item_id
         ORDER BY FIELD(storage.storage_type, "warehouse", "storage"), balances.quantity DESC, storage.name ASC',
        ['item_id' => $itemId]
    );
}

function item_balance_map(array $balances): array
{
    $map = [];

    foreach ($balances as $balance) {
        $map[(string) $balance['storage_id']] = (float) $balance['quantity'];
    }

    return $map;
}

function item_response_payload(array $item): array
{
    $historyMetrics = item_history_metrics((int) $item['id']);
    $latestMovement = latest_item_movement((int) $item['id']);
    $balances = item_storage_balances((int) $item['id']);
    $balanceMap = item_balance_map($balances);

    return [
        'item' => [
            'id' => (int) $item['id'],
            'unit' => $item['unit'],
            'current_quantity' => format_quantity($item['current_quantity']),
            'current_quantity_raw' => (float) $item['current_quantity'],
            'total_used' => format_quantity($historyMetrics['total_used']),
            'total_used_raw' => (float) $historyMetrics['total_used'],
            'total_added' => format_quantity($historyMetrics['total_added']),
            'total_added_raw' => (float) $historyMetrics['total_added'],
            'total_transferred' => format_quantity($historyMetrics['total_transferred'] ?? 0),
            'total_transferred_raw' => (float) ($historyMetrics['total_transferred'] ?? 0),
            'movement_count' => (int) $historyMetrics['movement_count'],
            'cost_per_unit' => format_money($item['cost_per_unit']),
            'cost_per_unit_raw' => (float) $item['cost_per_unit'],
            'stock_value' => format_money(stock_value($item['current_quantity'], $item['cost_per_unit'])),
            'balance_map_json' => json_encode($balanceMap, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'location_balances_html' => View::partialToString('items/location_balances', [
                'item' => $item,
                'balances' => $balances,
            ]),
        ],
        'movement' => $latestMovement ? [
            'row_html' => View::partialToString('items/history_row', [
                'movement' => $latestMovement,
                'item' => $item,
            ]),
        ] : null,
    ];
}

function normalize_item_upload(array $item, string $itemName): array
{
    $imageFile = uploaded_file('image');
    $imageError = validate_item_image_upload($imageFile);

    return [
        'file' => $imageFile,
        'error' => $imageError,
        'current_image_path' => $item['image_path'] ?? null,
        'item_name' => $itemName,
    ];
}

function normalize_storage_selection($value): ?int
{
    return normalize_entity_id($value);
}

function storage_exists_for_assignment(?int $storageId): bool
{
    if ($storageId === null) {
        return true;
    }

    return (int) Database::scalar(
        'SELECT COUNT(*)
         FROM storages
         WHERE id = :id
           AND is_active = 1
           AND is_system = 0',
        ['id' => $storageId]
    ) > 0;
}

function assign_item_to_storage(int $itemId, int $storageId): void
{
    Database::execute(
        'INSERT INTO item_storage_balances (item_id, storage_id, quantity, created_at, updated_at)
         VALUES (:item_id, :storage_id, 0, NOW(), NOW())
         ON DUPLICATE KEY UPDATE updated_at = NOW()',
        [
            'item_id' => $itemId,
            'storage_id' => $storageId,
        ]
    );
}

function item_has_storage_balance(int $itemId, int $storageId): bool
{
    return (int) Database::scalar(
        'SELECT COUNT(*) FROM item_storage_balances balances
         INNER JOIN storages storage ON storage.id = balances.storage_id
         WHERE balances.item_id = :item_id
           AND balances.storage_id = :storage_id
           AND storage.is_active = 1',
        [
            'item_id' => $itemId,
            'storage_id' => $storageId,
        ]
    ) > 0;
}

function item_has_location_assignments(int $itemId): bool
{
    return (int) Database::scalar(
        'SELECT COUNT(*) FROM item_storage_balances WHERE item_id = :item_id',
        ['item_id' => $itemId]
    ) > 0;
}

function item_storage_balance_record(int $itemId, int $storageId): ?array
{
    return Database::fetch(
        'SELECT balances.item_id,
                balances.storage_id,
                balances.quantity,
                storage.name,
                storage.storage_type,
                storage.is_active
         FROM item_storage_balances balances
         INNER JOIN storages storage ON storage.id = balances.storage_id
         WHERE balances.item_id = :item_id
           AND balances.storage_id = :storage_id
         LIMIT 1',
        [
            'item_id' => $itemId,
            'storage_id' => $storageId,
        ]
    );
}

function preferred_item_storage_id(int $itemId): ?int
{
    $currentDefaultStorageId = normalize_entity_id(Database::scalar(
        'SELECT storage_id FROM items WHERE id = :id LIMIT 1',
        ['id' => $itemId]
    ));

    if ($currentDefaultStorageId !== null && item_has_storage_balance($itemId, $currentDefaultStorageId)) {
        return $currentDefaultStorageId;
    }

    $nextStorageId = Database::scalar(
        'SELECT balances.storage_id
         FROM item_storage_balances balances
         INNER JOIN storages storage ON storage.id = balances.storage_id
         WHERE balances.item_id = :item_id
           AND storage.is_active = 1
         ORDER BY CASE WHEN balances.quantity > 0 THEN 0 ELSE 1 END,
                  FIELD(storage.storage_type, "storage", "warehouse"),
                  balances.quantity DESC,
                  storage.name ASC
         LIMIT 1',
        ['item_id' => $itemId]
    );

    return normalize_entity_id($nextStorageId);
}

function default_item_payload(?array $sourceItem = null, ?int $defaultStorageId = null): array
{
    $sourceUnit = item_unit_form_state($sourceItem['unit'] ?? 'pcs');
    $storageId = $sourceItem ? '' : ($defaultStorageId ? (string) $defaultStorageId : '');

    return [
        'name' => old('name', (string) ($sourceItem['name'] ?? '')),
        'sku' => old('sku', (string) ($sourceItem['sku'] ?? '')),
        'barcode' => old('barcode', (string) ($sourceItem['barcode'] ?? '')),
        'category' => old('category', (string) ($sourceItem['category'] ?? '')),
        'storage_id' => old('storage_id', $storageId),
        'unit' => old('unit', $sourceUnit['unit']),
        'custom_unit' => old('custom_unit', $sourceUnit['custom_unit']),
        'reorder_level' => old('reorder_level', $sourceItem ? format_quantity((float) $sourceItem['reorder_level']) : '0'),
        'cost_per_unit' => old('cost_per_unit', $sourceItem ? format_quantity((float) $sourceItem['cost_per_unit']) : '0'),
        'current_quantity' => old('current_quantity', '0'),
        'image_path' => $sourceItem['image_path'] ?? null,
        'notes' => old('notes', (string) ($sourceItem['notes'] ?? '')),
        'copy_item_id' => old('copy_item_id', $sourceItem ? (string) $sourceItem['id'] : ''),
        'use_existing_item' => old('use_existing_item', '1'),
        'is_active' => 1,
    ];
}
