<?php
declare(strict_types=1);

function flash_errors(array $errors): void
{
    foreach ($errors as $error) {
        flash('danger', $error);
    }
}

function app_ready_or_redirect(): void
{
    if (!app_installed()) {
        redirect('/setup');
    }
}

function storage_filters(): array
{
    $status = (string) query('status', 'active');
    $type = (string) query('type', '');

    return [
        'search' => trim((string) query('search', '')),
        'status' => in_array($status, ['active', 'archived', 'all'], true) ? $status : 'active',
        'type' => in_array($type, ['warehouse', 'storage'], true) ? $type : '',
    ];
}

function build_storage_where(array $filters, string $alias = 's'): array
{
    $conditions = [];
    $params = [];

    if ($filters['status'] === 'active') {
        $conditions[] = "{$alias}.is_active = 1";
    } elseif ($filters['status'] === 'archived') {
        $conditions[] = "{$alias}.is_active = 0";
    }

    if ($filters['search'] !== '') {
        $conditions[] = "({$alias}.name LIKE :search_name OR COALESCE({$alias}.notes, '') LIKE :search_notes)";
        $params['search_name'] = '%' . $filters['search'] . '%';
        $params['search_notes'] = '%' . $filters['search'] . '%';
    }

    if ($filters['type'] !== '') {
        $conditions[] = "{$alias}.storage_type = :storage_type";
        $params['storage_type'] = $filters['type'];
    }

    return [
        $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '',
        $params,
    ];
}

function item_filters(): array
{
    $status = (string) query('status', 'active');

    return [
        'search' => trim((string) query('search', '')),
        'status' => in_array($status, ['active', 'archived', 'all'], true) ? $status : 'active',
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
            OR COALESCE({$alias}.category, '') LIKE :search_category
            OR EXISTS (
                SELECT 1
                FROM item_storage_balances item_balances
                INNER JOIN storages matched_storage ON matched_storage.id = item_balances.storage_id
                WHERE item_balances.item_id = {$alias}.id
                  AND item_balances.quantity > 0
                  AND matched_storage.name LIKE :search_storage
            )
        )";
        $params['search_name'] = '%' . $filters['search'] . '%';
        $params['search_sku'] = '%' . $filters['search'] . '%';
        $params['search_category'] = '%' . $filters['search'] . '%';
        $params['search_storage'] = '%' . $filters['search'] . '%';
    }

    if ($filters['storage_id']) {
        $conditions[] = "EXISTS (
            SELECT 1
            FROM item_storage_balances filtered_balances
            WHERE filtered_balances.item_id = {$alias}.id
              AND filtered_balances.storage_id = :storage_id
              AND filtered_balances.quantity > 0
        )";
        $params['storage_id'] = $filters['storage_id'];
    }

    return [
        $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '',
        $params,
    ];
}

function movement_filters(): array
{
    $type = (string) query('movement_type', '');

    return [
        'item_id' => ctype_digit((string) query('item_id', '')) ? (int) query('item_id') : null,
        'storage_id' => ctype_digit((string) query('storage_id', '')) ? (int) query('storage_id') : null,
        'movement_type' => in_array($type, ['restock', 'usage', 'adjustment', 'transfer'], true) ? $type : '',
        'date_from' => trim((string) query('date_from', '')),
        'date_to' => trim((string) query('date_to', '')),
    ];
}

function build_movement_where(array $filters, string $alias = 'm', string $itemAlias = 'i'): array
{
    $conditions = [];
    $params = [];

    if ($filters['item_id']) {
        $conditions[] = "{$alias}.item_id = :item_id";
        $params['item_id'] = $filters['item_id'];
    }

    if ($filters['storage_id']) {
        $conditions[] = "({$alias}.source_storage_id = :storage_id OR {$alias}.destination_storage_id = :storage_id)";
        $params['storage_id'] = $filters['storage_id'];
    }

    if ($filters['movement_type'] !== '') {
        $conditions[] = "{$alias}.movement_type = :movement_type";
        $params['movement_type'] = $filters['movement_type'];
    }

    if ($filters['date_from'] !== '') {
        $conditions[] = "{$alias}.used_at >= :date_from";
        $params['date_from'] = $filters['date_from'] . ' 00:00:00';
    }

    if ($filters['date_to'] !== '') {
        $conditions[] = "{$alias}.used_at <= :date_to";
        $params['date_to'] = $filters['date_to'] . ' 23:59:59';
    }

    return [
        $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '',
        $params,
    ];
}

function all_items_for_select(): array
{
    return Database::fetchAll(
        'SELECT id, name, sku, unit, is_active
         FROM items
         WHERE is_active = 1
         ORDER BY name ASC'
    );
}

function all_storages_for_select(?int $selectedId = null): array
{
    $conditions = ['is_active = 1'];
    $params = [];

    if ($selectedId !== null) {
        $conditions[] = 'id = :selected_id';
        $params['selected_id'] = $selectedId;
    }

    return Database::fetchAll(
        'SELECT id, name, storage_type, is_active
         FROM storages
         WHERE ' . implode(' OR ', $conditions) . '
         ORDER BY FIELD(storage_type, "warehouse", "storage"), is_active DESC, name ASC',
        $params
    );
}

function active_item_sku_exists(string $sku, ?int $ignoreId = null): bool
{
    $sql = 'SELECT id FROM items WHERE sku = :sku AND is_active = 1';
    $params = ['sku' => $sku];

    if ($ignoreId !== null) {
        $sql .= ' AND id != :ignore_id';
        $params['ignore_id'] = $ignoreId;
    }

    $sql .= ' LIMIT 1';

    return Database::fetch($sql, $params) !== null;
}

function active_storage_name_exists(string $name, ?int $ignoreId = null): bool
{
    $sql = 'SELECT id FROM storages WHERE LOWER(name) = LOWER(:name) AND is_active = 1';
    $params = ['name' => $name];

    if ($ignoreId !== null) {
        $sql .= ' AND id != :ignore_id';
        $params['ignore_id'] = $ignoreId;
    }

    $sql .= ' LIMIT 1';

    return Database::fetch($sql, $params) !== null;
}

function storage_type_label(string $type): string
{
    return $type === 'warehouse' ? 'Warehouse' : 'Storage';
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
                      AND balances.quantity > 0
                ) AS location_count,
                (
                    SELECT GROUP_CONCAT(location_storage.name ORDER BY location_balances.quantity DESC, location_storage.name ASC SEPARATOR ", ")
                    FROM item_storage_balances location_balances
                    INNER JOIN storages location_storage ON location_storage.id = location_balances.storage_id
                    WHERE location_balances.item_id = i.id
                      AND location_balances.quantity > 0
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

function find_storage_or_abort(int $storageId): array
{
    $storage = Database::fetch(
        'SELECT s.*,
                (
                    SELECT COUNT(*)
                    FROM item_storage_balances balances
                    INNER JOIN items i ON i.id = balances.item_id
                    WHERE balances.storage_id = s.id
                      AND balances.quantity > 0
                      AND i.is_active = 1
                ) AS active_item_count,
                (
                    SELECT COALESCE(SUM(balances.quantity), 0)
                    FROM item_storage_balances balances
                    INNER JOIN items i ON i.id = balances.item_id
                    WHERE balances.storage_id = s.id
                      AND i.is_active = 1
                ) AS total_quantity,
                (
                    SELECT COALESCE(SUM(movements.movement_quantity), 0)
                    FROM inventory_movements movements
                    INNER JOIN items i ON i.id = movements.item_id
                    WHERE movements.source_storage_id = s.id
                      AND i.is_active = 1
                      AND movements.movement_type = "usage"
                ) AS total_used,
                (
                    SELECT COALESCE(SUM(movements.movement_quantity), 0)
                    FROM inventory_movements movements
                    INNER JOIN items i ON i.id = movements.item_id
                    WHERE movements.source_storage_id = s.id
                      AND i.is_active = 1
                      AND movements.movement_type = "transfer"
                ) AS transferred_out,
                (
                    SELECT COALESCE(SUM(movements.movement_quantity), 0)
                    FROM inventory_movements movements
                    INNER JOIN items i ON i.id = movements.item_id
                    WHERE movements.destination_storage_id = s.id
                      AND i.is_active = 1
                      AND movements.movement_type = "transfer"
                ) AS transferred_in,
                creator.name AS creator_name,
                updater.name AS updater_name
         FROM storages s
         LEFT JOIN users creator ON creator.id = s.created_by
         LEFT JOIN users updater ON updater.id = s.updated_by
         WHERE s.id = :id
         LIMIT 1',
        ['id' => $storageId]
    );

    if (!$storage) {
        abort(404, 'Storage not found.');
    }

    return $storage;
}

function find_user_or_abort(int $userId): array
{
    $user = Database::fetch(
        'SELECT * FROM users WHERE id = :id LIMIT 1',
        ['id' => $userId]
    );

    if (!$user) {
        abort(404, 'User not found.');
    }

    return $user;
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

function storage_items(int $storageId): array
{
    return Database::fetchAll(
        'SELECT i.id,
                i.name,
                i.sku,
                i.category,
                i.unit,
                i.reorder_level,
                i.cost_per_unit,
                i.notes,
                i.is_active,
                i.image_path,
                balances.quantity,
                (
                    SELECT COALESCE(SUM(movements.movement_quantity), 0)
                    FROM inventory_movements movements
                    WHERE movements.item_id = balances.item_id
                      AND movements.source_storage_id = balances.storage_id
                      AND movements.movement_type = "usage"
                ) AS total_used,
                (
                    SELECT COALESCE(SUM(movements.movement_quantity), 0)
                    FROM inventory_movements movements
                    WHERE movements.item_id = balances.item_id
                      AND movements.destination_storage_id = balances.storage_id
                      AND movements.movement_type = "transfer"
                ) AS transferred_in,
                (
                    SELECT COALESCE(SUM(movements.movement_quantity), 0)
                    FROM inventory_movements movements
                    WHERE movements.item_id = balances.item_id
                      AND movements.source_storage_id = balances.storage_id
                      AND movements.movement_type = "transfer"
                ) AS transferred_out,
                (
                    SELECT MAX(movements.used_at)
                    FROM inventory_movements movements
                    WHERE movements.item_id = balances.item_id
                      AND (
                          movements.source_storage_id = balances.storage_id
                          OR movements.destination_storage_id = balances.storage_id
                      )
                ) AS last_activity_at
         FROM item_storage_balances balances
         INNER JOIN items i ON i.id = balances.item_id
         WHERE balances.storage_id = :storage_id
           AND i.is_active = 1
         ORDER BY i.is_active DESC, balances.quantity DESC, i.name ASC',
        ['storage_id' => $storageId]
    );
}

function storage_summaries(array $filters): array
{
    [$where, $params] = build_storage_where($filters);

    return Database::fetchAll(
        "SELECT s.*,
                (
                    SELECT COUNT(*)
                    FROM item_storage_balances balances
                    INNER JOIN items i ON i.id = balances.item_id
                    WHERE balances.storage_id = s.id
                      AND balances.quantity > 0
                      AND i.is_active = 1
                ) AS active_item_count,
                (
                    SELECT COALESCE(SUM(balances.quantity), 0)
                    FROM item_storage_balances balances
                    INNER JOIN items i ON i.id = balances.item_id
                    WHERE balances.storage_id = s.id
                      AND i.is_active = 1
                ) AS total_quantity,
                (
                    SELECT COALESCE(SUM(balances.quantity * i.cost_per_unit), 0)
                    FROM item_storage_balances balances
                    INNER JOIN items i ON i.id = balances.item_id
                    WHERE balances.storage_id = s.id
                      AND i.is_active = 1
                ) AS total_stock_value,
                (
                    SELECT COALESCE(SUM(movements.movement_quantity), 0)
                    FROM inventory_movements movements
                    INNER JOIN items i ON i.id = movements.item_id
                    WHERE movements.source_storage_id = s.id
                      AND i.is_active = 1
                      AND movements.movement_type = 'usage'
                ) AS total_used,
                (
                    SELECT COALESCE(SUM(movements.movement_quantity), 0)
                    FROM inventory_movements movements
                    INNER JOIN items i ON i.id = movements.item_id
                    WHERE movements.source_storage_id = s.id
                      AND i.is_active = 1
                      AND movements.movement_type = 'transfer'
                ) AS transferred_out,
                (
                    SELECT COALESCE(SUM(movements.movement_quantity), 0)
                    FROM inventory_movements movements
                    INNER JOIN items i ON i.id = movements.item_id
                    WHERE movements.destination_storage_id = s.id
                      AND i.is_active = 1
                      AND movements.movement_type = 'transfer'
                ) AS transferred_in
         FROM storages s
         {$where}
         ORDER BY FIELD(s.storage_type, 'warehouse', 'storage'), s.is_active DESC, s.name ASC",
        $params
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
    return ctype_digit((string) $value) ? (int) $value : null;
}

function storage_exists_for_assignment(?int $storageId): bool
{
    if ($storageId === null) {
        return true;
    }

    return (int) Database::scalar(
        'SELECT COUNT(*) FROM storages WHERE id = :id AND is_active = 1',
        ['id' => $storageId]
    ) > 0;
}

function quantity_delta_for_type(string $type, float $quantity): float
{
    switch ($type) {
        case 'restock':
            return abs($quantity);
        case 'usage':
            return -abs($quantity);
        case 'adjustment':
            return $quantity;
        case 'transfer':
            return 0.0;
        default:
            return 0.0;
    }
}

function persist_item_storage_balance(int $itemId, int $storageId, float $quantity): void
{
    $normalizedQuantity = round($quantity, 2);

    if ($normalizedQuantity <= 0) {
        Database::execute(
            'DELETE FROM item_storage_balances WHERE item_id = :item_id AND storage_id = :storage_id',
            [
                'item_id' => $itemId,
                'storage_id' => $storageId,
            ]
        );

        return;
    }

    Database::execute(
        'INSERT INTO item_storage_balances (item_id, storage_id, quantity, created_at, updated_at)
         VALUES (:item_id, :storage_id, :quantity, NOW(), NOW())
         ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), updated_at = NOW()',
        [
            'item_id' => $itemId,
            'storage_id' => $storageId,
            'quantity' => $normalizedQuantity,
        ]
    );
}

function current_item_balance_map_for_update(int $itemId): array
{
    $rows = Database::fetchAll(
        'SELECT storage_id, quantity
         FROM item_storage_balances
         WHERE item_id = :item_id
         FOR UPDATE',
        ['item_id' => $itemId]
    );

    $balances = [];

    foreach ($rows as $row) {
        $balances[(int) $row['storage_id']] = (float) $row['quantity'];
    }

    return $balances;
}

function apply_inventory_movement(
    array $item,
    string $type,
    float $quantity,
    ?int $sourceStorageId,
    ?int $destinationStorageId,
    string $usedAt,
    ?string $referenceCode,
    ?string $notes,
    int $performedBy
): void {
    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        Database::fetch(
            'SELECT id, current_quantity FROM items WHERE id = :id LIMIT 1 FOR UPDATE',
            ['id' => $item['id']]
        );

        $balanceMap = current_item_balance_map_for_update((int) $item['id']);
        $rawQuantity = $type === 'adjustment' ? $quantity : abs($quantity);
        $movementQuantity = round(abs($rawQuantity), 2);
        $delta = round(quantity_delta_for_type($type, $quantity), 2);
        $sourceBalanceAfter = null;
        $destinationBalanceAfter = null;

        if ($type === 'usage' || $type === 'transfer' || $type === 'adjustment') {
            $currentSourceBalance = round($balanceMap[$sourceStorageId ?? 0] ?? 0.0, 2);

            if ($type === 'usage') {
                $sourceBalanceAfter = round($currentSourceBalance - $movementQuantity, 2);
            } elseif ($type === 'transfer') {
                $sourceBalanceAfter = round($currentSourceBalance - $movementQuantity, 2);
            } else {
                $sourceBalanceAfter = round($currentSourceBalance + $quantity, 2);
            }

            if ($sourceBalanceAfter < 0) {
                throw new RuntimeException('That movement would make the source location go negative. Hard no.');
            }

            $balanceMap[(int) $sourceStorageId] = $sourceBalanceAfter;
        }

        if ($type === 'restock' || $type === 'transfer') {
            $currentDestinationBalance = round($balanceMap[$destinationStorageId ?? 0] ?? 0.0, 2);
            $destinationBalanceAfter = round($currentDestinationBalance + $movementQuantity, 2);
            $balanceMap[(int) $destinationStorageId] = $destinationBalanceAfter;
        }

        if ($type === 'adjustment') {
            $movementQuantity = round(abs($quantity), 2);
        }

        foreach ($balanceMap as $storageId => $balanceQuantity) {
            persist_item_storage_balance((int) $item['id'], (int) $storageId, (float) $balanceQuantity);
        }

        $newBalance = (float) Database::scalar(
            'SELECT COALESCE(SUM(quantity), 0) FROM item_storage_balances WHERE item_id = :item_id',
            ['item_id' => $item['id']]
        );

        if ($newBalance < 0) {
            throw new RuntimeException('That movement would make stock negative. Bad data in, bad data out.');
        }

        Database::execute(
            'UPDATE items SET current_quantity = :current_quantity, updated_by = :updated_by, updated_at = NOW() WHERE id = :id',
            [
                'current_quantity' => $newBalance,
                'updated_by' => $performedBy,
                'id' => $item['id'],
            ]
        );

        Database::execute(
            'INSERT INTO inventory_movements (
                item_id,
                movement_type,
                movement_quantity,
                quantity_delta,
                balance_after,
                source_storage_id,
                destination_storage_id,
                source_balance_after,
                destination_balance_after,
                reference_code,
                notes,
                used_at,
                performed_by,
                created_at
             ) VALUES (
                :item_id,
                :movement_type,
                :movement_quantity,
                :quantity_delta,
                :balance_after,
                :source_storage_id,
                :destination_storage_id,
                :source_balance_after,
                :destination_balance_after,
                :reference_code,
                :notes,
                :used_at,
                :performed_by,
                NOW()
             )',
            [
                'item_id' => $item['id'],
                'movement_type' => $type,
                'movement_quantity' => $movementQuantity,
                'quantity_delta' => $delta,
                'balance_after' => $newBalance,
                'source_storage_id' => $sourceStorageId,
                'destination_storage_id' => $destinationStorageId,
                'source_balance_after' => $sourceBalanceAfter,
                'destination_balance_after' => $destinationBalanceAfter,
                'reference_code' => $referenceCode !== '' ? $referenceCode : null,
                'notes' => $notes !== '' ? $notes : null,
                'used_at' => $usedAt,
                'performed_by' => $performedBy,
            ]
        );

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}

function export_csv(string $filename, array $headers, array $rows): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'wb');

    if ($output === false) {
        abort(500, 'Could not start CSV export.');
    }

    fputcsv($output, $headers, ',', '"', '\\');

    foreach ($rows as $row) {
        fputcsv($output, $row, ',', '"', '\\');
    }

    fclose($output);
    exit;
}

function default_item_payload(): array
{
    return [
        'name' => old('name', ''),
        'sku' => old('sku', ''),
        'category' => old('category', ''),
        'storage_id' => old('storage_id', ''),
        'unit' => old('unit', 'pcs'),
        'custom_unit' => old('custom_unit', ''),
        'reorder_level' => old('reorder_level', '0'),
        'cost_per_unit' => old('cost_per_unit', '0'),
        'current_quantity' => old('current_quantity', '0'),
        'image_path' => null,
        'notes' => old('notes', ''),
        'is_active' => 1,
    ];
}

function default_storage_payload(): array
{
    return [
        'name' => old('name', ''),
        'storage_type' => old('storage_type', 'storage'),
        'notes' => old('notes', ''),
        'is_active' => 1,
    ];
}

function handle_setup_page(): void
{
    $status = Installer::status();

    if ($status['installed']) {
        redirect('/login');
    }

    View::render('auth/setup', [
        'title' => 'Install Inventory HQ',
        'authPage' => true,
        'status' => $status,
    ]);
}

function handle_setup_submit(): void
{
    verify_csrf();

    if (Installer::status()['installed']) {
        redirect('/login');
    }

    $name = trim((string) input('name'));
    $email = strtolower(trim((string) input('email')));
    $password = (string) input('password');
    $passwordConfirmation = (string) input('password_confirmation');

    flash_old_input([
        'name' => $name,
        'email' => $email,
    ]);

    $errors = [];

    if ($name === '') {
        $errors[] = 'Owner name is required.';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Use a real email address.';
    }

    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }

    if ($password !== $passwordConfirmation) {
        $errors[] = 'Passwords do not match.';
    }

    if ($errors !== []) {
        flash_errors($errors);
        redirect('/setup');
    }

    try {
        Installer::run($name, $email, $password);
        consume_old_input();
        Auth::attempt($email, $password);
        flash('success', 'Setup finished. You are the owner now. Try not to burn it down.');
        redirect('/dashboard');
    } catch (Throwable $exception) {
        flash('danger', $exception->getMessage());
        redirect('/setup');
    }
}

function handle_login_page(): void
{
    if (!app_installed()) {
        redirect('/setup');
    }

    if (Auth::check()) {
        redirect('/dashboard');
    }

    View::render('auth/login', [
        'title' => 'Login',
        'authPage' => true,
    ]);
}

function handle_login_submit(): void
{
    verify_csrf();
    app_ready_or_redirect();

    $email = strtolower(trim((string) input('email')));
    $password = (string) input('password');

    flash_old_input(['email' => $email]);

    if (!Auth::attempt($email, $password)) {
        flash('danger', 'Wrong email or password.');
        redirect('/login');
    }

    consume_old_input();
    flash('success', 'Welcome back.');
    redirect('/dashboard');
}

function handle_logout_submit(): void
{
    verify_csrf();
    Auth::logout();
    flash('success', 'Logged out.');
    redirect('/login');
}

function handle_dashboard_page(): void
{
    app_ready_or_redirect();
    Auth::requireLogin();

    $metrics = [
        'items_total' => (int) Database::scalar('SELECT COUNT(*) FROM items WHERE is_active = 1'),
        'storages_total' => (int) Database::scalar('SELECT COUNT(*) FROM storages WHERE is_active = 1'),
        'warehouses_total' => (int) Database::scalar('SELECT COUNT(*) FROM storages WHERE is_active = 1 AND storage_type = "warehouse"'),
        'units_total' => (float) Database::scalar('SELECT COALESCE(SUM(current_quantity), 0) FROM items WHERE is_active = 1'),
        'low_stock' => (int) Database::scalar('SELECT COUNT(*) FROM items WHERE is_active = 1 AND current_quantity <= reorder_level'),
        'inventory_value' => (float) Database::scalar('SELECT COALESCE(SUM(current_quantity * cost_per_unit), 0) FROM items WHERE is_active = 1'),
        'used_last_30' => (float) Database::scalar(
            "SELECT COALESCE(SUM(m.movement_quantity), 0)
             FROM inventory_movements m
             INNER JOIN items i ON i.id = m.item_id
             WHERE i.is_active = 1
               AND m.movement_type = 'usage'
               AND m.used_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        ),
    ];

    $recentActivity = Database::fetchAll(
        'SELECT m.*,
                i.name AS item_name,
                i.sku,
                i.unit,
                source_storage.name AS source_storage_name,
                source_storage.storage_type AS source_storage_type,
                destination_storage.name AS destination_storage_name,
                destination_storage.storage_type AS destination_storage_type,
                u.name AS user_name
         FROM inventory_movements m
         INNER JOIN items i ON i.id = m.item_id AND i.is_active = 1
         LEFT JOIN storages source_storage ON source_storage.id = m.source_storage_id
         LEFT JOIN storages destination_storage ON destination_storage.id = m.destination_storage_id
         LEFT JOIN users u ON u.id = m.performed_by
         ORDER BY m.used_at DESC, m.id DESC
         LIMIT 10'
    );

    $topUsage = Database::fetchAll(
        'SELECT i.id,
                i.name,
                i.unit,
                SUM(m.movement_quantity) AS total_used,
                (
                    SELECT COUNT(*)
                    FROM item_storage_balances balances
                    WHERE balances.item_id = i.id
                      AND balances.quantity > 0
                ) AS location_count
         FROM inventory_movements m
         INNER JOIN items i ON i.id = m.item_id
         WHERE m.movement_type = "usage"
           AND i.is_active = 1
           AND m.used_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
         GROUP BY i.id, i.name, i.unit
         ORDER BY total_used DESC
         LIMIT 5'
    );

    $lowStockItems = Database::fetchAll(
        'SELECT i.id,
                i.name,
                i.sku,
                i.unit,
                i.current_quantity,
                i.reorder_level,
                (
                    SELECT COUNT(*)
                    FROM item_storage_balances balances
                    WHERE balances.item_id = i.id
                      AND balances.quantity > 0
                ) AS location_count
         FROM items i
         WHERE i.is_active = 1 AND i.current_quantity <= i.reorder_level
         ORDER BY i.current_quantity ASC, i.name ASC
         LIMIT 8'
    );

    View::render('dashboard', [
        'title' => 'Dashboard',
        'metrics' => $metrics,
        'recentActivity' => $recentActivity,
        'topUsage' => $topUsage,
        'lowStockItems' => $lowStockItems,
    ]);
}

function handle_items_index(): void
{
    app_ready_or_redirect();
    Auth::requireLogin();

    $filters = item_filters();
    [$where, $params] = build_item_where($filters);

    $items = Database::fetchAll(
        "SELECT i.*,
                default_storage.name AS default_storage_name,
                (
                    SELECT COUNT(*)
                    FROM item_storage_balances balances
                    WHERE balances.item_id = i.id
                      AND balances.quantity > 0
                ) AS location_count,
                (
                    SELECT GROUP_CONCAT(storage.name ORDER BY balances.quantity DESC, storage.name ASC SEPARATOR ', ')
                    FROM item_storage_balances balances
                    INNER JOIN storages storage ON storage.id = balances.storage_id
                    WHERE balances.item_id = i.id
                      AND balances.quantity > 0
                ) AS storage_summary,
                (SELECT MAX(m.used_at) FROM inventory_movements m WHERE m.item_id = i.id) AS last_movement_at
         FROM items i
         LEFT JOIN storages default_storage ON default_storage.id = i.storage_id
         {$where}
         ORDER BY i.is_active DESC, i.name ASC",
        $params
    );

    $counts = [
        'active' => (int) Database::scalar('SELECT COUNT(*) FROM items WHERE is_active = 1'),
        'archived' => (int) Database::scalar('SELECT COUNT(*) FROM items WHERE is_active = 0'),
    ];

    View::render('items/index', [
        'title' => 'Items',
        'items' => $items,
        'filters' => $filters,
        'counts' => $counts,
        'storages' => all_storages_for_select($filters['storage_id']),
    ]);
}

function handle_items_create_page(): void
{
    app_ready_or_redirect();
    Auth::requireLogin();

    View::render('items/form', [
        'title' => 'Create Item',
        'mode' => 'create',
        'item' => default_item_payload(),
        'storages' => all_storages_for_select(),
    ]);
}

function handle_items_create_submit(): void
{
    app_ready_or_redirect();
    Auth::requireLogin();
    verify_csrf();

    $user = Auth::user();
    $selectedUnit = trim((string) input('unit', 'pcs'));
    $customUnit = trim((string) input('custom_unit'));
    $storageId = normalize_storage_selection(input('storage_id'));
    $imageUpload = normalize_item_upload(['image_path' => null], trim((string) input('name')));
    $payload = [
        'name' => trim((string) input('name')),
        'sku' => strtoupper(trim((string) input('sku'))),
        'category' => trim((string) input('category')),
        'storage_id' => $storageId,
        'unit' => $selectedUnit,
        'custom_unit' => $customUnit,
        'reorder_level' => quantity_value(input('reorder_level')),
        'cost_per_unit' => quantity_value(input('cost_per_unit')),
        'current_quantity' => quantity_value(input('current_quantity')),
        'notes' => trim((string) input('notes')),
    ];

    $resolvedUnit = resolve_item_unit($selectedUnit, $customUnit);

    flash_old_input(array_map(
        static fn ($value) => is_float($value) ? (string) $value : $value,
        $payload
    ));

    $errors = [];

    if ($payload['name'] === '') {
        $errors[] = 'Item name is required.';
    }

    if ($payload['sku'] === '') {
        $errors[] = 'SKU is required.';
    }

    if ($selectedUnit === 'custom' && $customUnit === '') {
        $errors[] = 'Enter a custom unit name.';
    }

    if ($resolvedUnit === '') {
        $errors[] = 'Unit is required.';
    }

    if (!storage_exists_for_assignment($storageId)) {
        $errors[] = 'Pick a valid active storage.';
    }

    if ($imageUpload['error'] !== null) {
        $errors[] = $imageUpload['error'];
    }

    if (!is_numeric_value(input('current_quantity')) || !is_numeric_value(input('reorder_level')) || !is_numeric_value(input('cost_per_unit'))) {
        $errors[] = 'Quantity, reorder level, and cost must be valid numbers.';
    }

    if ($payload['current_quantity'] < 0 || $payload['reorder_level'] < 0 || $payload['cost_per_unit'] < 0) {
        $errors[] = 'Quantity, reorder level, and cost cannot be negative.';
    }

    if ($payload['current_quantity'] > 0 && $storageId === null) {
        $errors[] = 'Create an active location first, or set initial quantity to 0.';
    }

    if (active_item_sku_exists($payload['sku'])) {
        $errors[] = 'An active item already uses this SKU.';
    }

    if ($errors !== []) {
        flash_errors($errors);
        redirect('/items/create');
    }

    $pdo = Database::connection();
    $pdo->beginTransaction();
    $storedImagePath = null;

    try {
        Database::execute(
            'INSERT INTO items (name, sku, category, storage_id, unit, current_quantity, reorder_level, cost_per_unit, image_path, notes, is_active, created_by, updated_by, created_at, updated_at)
             VALUES (:name, :sku, :category, :storage_id, :unit, :current_quantity, :reorder_level, :cost_per_unit, :image_path, :notes, 1, :created_by, :updated_by, NOW(), NOW())',
            [
                'name' => $payload['name'],
                'sku' => $payload['sku'],
                'category' => $payload['category'] !== '' ? $payload['category'] : null,
                'storage_id' => $payload['storage_id'],
                'unit' => $resolvedUnit,
                'current_quantity' => $payload['current_quantity'],
                'reorder_level' => $payload['reorder_level'],
                'cost_per_unit' => $payload['cost_per_unit'],
                'image_path' => null,
                'notes' => $payload['notes'] !== '' ? $payload['notes'] : null,
                'created_by' => $user['id'],
                'updated_by' => $user['id'],
            ]
        );

        $itemId = Database::lastInsertId();

        if ($imageUpload['file'] !== null) {
            $storedImagePath = store_item_image($imageUpload['file'], $payload['name']);
            Database::execute(
                'UPDATE items SET image_path = :image_path, updated_at = NOW() WHERE id = :id',
                [
                    'image_path' => $storedImagePath,
                    'id' => $itemId,
                ]
            );
        }

        if ($payload['current_quantity'] > 0) {
            persist_item_storage_balance($itemId, (int) $storageId, $payload['current_quantity']);

            Database::execute(
                'INSERT INTO inventory_movements (
                    item_id,
                    movement_type,
                    movement_quantity,
                    quantity_delta,
                    balance_after,
                    destination_storage_id,
                    destination_balance_after,
                    reference_code,
                    notes,
                    used_at,
                    performed_by,
                    created_at
                 ) VALUES (
                    :item_id,
                    :movement_type,
                    :movement_quantity,
                    :quantity_delta,
                    :balance_after,
                    :destination_storage_id,
                    :destination_balance_after,
                    :reference_code,
                    :notes,
                    NOW(),
                    :performed_by,
                    NOW()
                 )',
                [
                    'item_id' => $itemId,
                    'movement_type' => 'restock',
                    'movement_quantity' => $payload['current_quantity'],
                    'quantity_delta' => $payload['current_quantity'],
                    'balance_after' => $payload['current_quantity'],
                    'destination_storage_id' => $storageId,
                    'destination_balance_after' => $payload['current_quantity'],
                    'reference_code' => 'INITIAL',
                    'notes' => 'Initial stock on item creation',
                    'performed_by' => $user['id'],
                ]
            );
        }

        $pdo->commit();
        consume_old_input();
        flash('success', 'Item created.');
        redirect('/items/' . $itemId);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($storedImagePath !== null) {
            delete_item_image($storedImagePath);
        }

        flash('danger', $exception->getMessage());
        redirect('/items/create');
    }
}

function handle_items_show(array $params): void
{
    app_ready_or_redirect();
    Auth::requireLogin();

    $item = find_item_or_abort((int) $params['id']);
    $history = Database::fetchAll(
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
         LIMIT 50',
        ['item_id' => $item['id']]
    );

    $historyMetrics = item_history_metrics((int) $item['id']);
    $balances = item_storage_balances((int) $item['id']);

    View::render('items/show', [
        'title' => $item['name'],
        'item' => $item,
        'history' => $history,
        'historyMetrics' => $historyMetrics,
        'balances' => $balances,
        'storages' => all_storages_for_select($item['storage_id'] ? (int) $item['storage_id'] : null),
    ]);
}

function handle_items_edit_page(array $params): void
{
    app_ready_or_redirect();
    Auth::requireLogin();

    $item = find_item_or_abort((int) $params['id']);

    View::render('items/form', [
        'title' => 'Edit ' . $item['name'],
        'mode' => 'edit',
        'item' => array_merge([
            'name' => old('name', $item['name']),
            'sku' => old('sku', $item['sku']),
            'category' => old('category', $item['category']),
            'storage_id' => old('storage_id', $item['storage_id']),
            'reorder_level' => old('reorder_level', format_quantity($item['reorder_level'])),
            'cost_per_unit' => old('cost_per_unit', format_quantity($item['cost_per_unit'])),
            'current_quantity' => format_quantity($item['current_quantity']),
            'image_path' => $item['image_path'],
            'notes' => old('notes', $item['notes']),
            'is_active' => (int) $item['is_active'],
            'id' => $item['id'],
        ], item_unit_form_state(old('unit', $item['unit']))),
        'storages' => all_storages_for_select($item['storage_id'] ? (int) $item['storage_id'] : null),
    ]);
}

function handle_items_edit_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requireLogin();
    verify_csrf();

    $item = find_item_or_abort((int) $params['id']);
    $user = Auth::user();
    $selectedUnit = trim((string) input('unit', 'pcs'));
    $customUnit = trim((string) input('custom_unit'));
    $storageId = normalize_storage_selection(input('storage_id'));
    $imageUpload = normalize_item_upload($item, trim((string) input('name', $item['name'])));

    $payload = [
        'name' => trim((string) input('name')),
        'sku' => strtoupper(trim((string) input('sku'))),
        'category' => trim((string) input('category')),
        'storage_id' => $storageId,
        'unit' => $selectedUnit,
        'custom_unit' => $customUnit,
        'reorder_level' => quantity_value(input('reorder_level')),
        'cost_per_unit' => quantity_value(input('cost_per_unit')),
        'notes' => trim((string) input('notes')),
    ];

    $resolvedUnit = resolve_item_unit($selectedUnit, $customUnit);

    flash_old_input(array_map(
        static fn ($value) => is_float($value) ? (string) $value : $value,
        $payload
    ));

    $errors = [];

    if ($payload['name'] === '' || $payload['sku'] === '') {
        $errors[] = 'Name and SKU are required.';
    }

    if ($selectedUnit === 'custom' && $customUnit === '') {
        $errors[] = 'Enter a custom unit name.';
    }

    if ($resolvedUnit === '') {
        $errors[] = 'Unit is required.';
    }

    if (!storage_exists_for_assignment($storageId)) {
        $errors[] = 'Pick a valid active storage.';
    }

    if ($imageUpload['error'] !== null) {
        $errors[] = $imageUpload['error'];
    }

    if (!is_numeric_value(input('reorder_level')) || !is_numeric_value(input('cost_per_unit'))) {
        $errors[] = 'Reorder level and cost must be valid numbers.';
    }

    if ($payload['reorder_level'] < 0 || $payload['cost_per_unit'] < 0) {
        $errors[] = 'Reorder level and cost cannot be negative.';
    }

    if (active_item_sku_exists($payload['sku'], (int) $item['id'])) {
        $errors[] = 'An active item already uses this SKU.';
    }

    if ($errors !== []) {
        flash_errors($errors);
        redirect('/items/' . $item['id'] . '/edit');
    }

    $storedImagePath = null;
    $nextImagePath = $item['image_path'];

    try {
        if ($imageUpload['file'] !== null) {
            $storedImagePath = store_item_image($imageUpload['file'], $payload['name']);
            $nextImagePath = $storedImagePath;
        }

        Database::execute(
            'UPDATE items
             SET name = :name,
                 sku = :sku,
                 category = :category,
                 storage_id = :storage_id,
                 unit = :unit,
                 reorder_level = :reorder_level,
                 cost_per_unit = :cost_per_unit,
                 image_path = :image_path,
                 notes = :notes,
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'name' => $payload['name'],
                'sku' => $payload['sku'],
                'category' => $payload['category'] !== '' ? $payload['category'] : null,
                'storage_id' => $payload['storage_id'],
                'unit' => $resolvedUnit,
                'reorder_level' => $payload['reorder_level'],
                'cost_per_unit' => $payload['cost_per_unit'],
                'image_path' => $nextImagePath,
                'notes' => $payload['notes'] !== '' ? $payload['notes'] : null,
                'updated_by' => $user['id'],
                'id' => $item['id'],
            ]
        );
    } catch (Throwable $exception) {
        if ($storedImagePath !== null) {
            delete_item_image($storedImagePath);
        }

        flash('danger', $exception->getMessage());
        redirect('/items/' . $item['id'] . '/edit');
    }

    if ($storedImagePath !== null && !empty($item['image_path']) && $item['image_path'] !== $storedImagePath) {
        delete_item_image($item['image_path']);
    }

    consume_old_input();
    flash('success', 'Item updated.');
    redirect('/items/' . $item['id']);
}

function handle_items_status_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requireLogin();
    verify_csrf();

    $item = find_item_or_abort((int) $params['id']);
    $user = Auth::user();
    $nextStatus = (int) $item['is_active'] === 1 ? 0 : 1;

    if ($nextStatus === 1 && active_item_sku_exists((string) $item['sku'], (int) $item['id'])) {
        flash('danger', 'Recover failed. Another active item already uses SKU ' . $item['sku'] . '.');
        redirect('/items?status=archived');
    }

    Database::execute(
        'UPDATE items SET is_active = :is_active, updated_by = :updated_by, updated_at = NOW() WHERE id = :id',
        [
            'is_active' => $nextStatus,
            'updated_by' => $user['id'],
            'id' => $item['id'],
        ]
    );

    flash('success', $nextStatus ? 'Item recovered.' : 'Item deleted.');
    redirect($nextStatus ? '/items' : '/items?status=archived');
}

function handle_item_movement_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requireLogin();
    verify_csrf();

    $item = find_item_or_abort((int) $params['id']);
    $user = Auth::user();

    if (!(int) $item['is_active']) {
        if (request_wants_json()) {
            json_response([
                'message' => 'Deleted items do not get new movement logs.',
                'errors' => ['Deleted items do not get new movement logs.'],
            ], 422);
        }

        flash('danger', 'Deleted items do not get new movement logs.');
        redirect('/items/' . $item['id']);
    }

    $movementType = (string) input('movement_type');
    $quantity = quantity_value(input('quantity'));
    $sourceStorageId = normalize_storage_selection(input('source_storage_id'));
    $destinationStorageId = normalize_storage_selection(input('destination_storage_id'));
    $usedAt = trim((string) input('used_at'));
    $referenceCode = trim((string) input('reference_code'));
    $notes = trim((string) input('notes'));

    $errors = [];

    if (!in_array($movementType, ['restock', 'usage', 'adjustment', 'transfer'], true)) {
        $errors[] = 'Pick a valid movement type.';
    }

    if (!is_numeric_value(input('quantity'))) {
        $errors[] = 'Quantity must be a valid number.';
    }

    if ($movementType === 'adjustment') {
        if ((string) input('quantity') === '') {
            $errors[] = 'Adjustment quantity is required.';
        }
    } elseif ($quantity <= 0) {
        $errors[] = 'Quantity must be greater than zero.';
    }

    if ($movementType === 'usage' && !$sourceStorageId) {
        $errors[] = 'Pick the location you are using stock from.';
    }

    if ($movementType === 'restock' && !$destinationStorageId) {
        $errors[] = 'Pick the location you are adding stock to.';
    }

    if ($movementType === 'adjustment' && !$sourceStorageId) {
        $errors[] = 'Pick the location you are adjusting.';
    }

    if ($movementType === 'transfer' && (!$sourceStorageId || !$destinationStorageId)) {
        $errors[] = 'Pick both the source and destination locations.';
    }

    if ($movementType === 'transfer' && $sourceStorageId && $destinationStorageId && $sourceStorageId === $destinationStorageId) {
        $errors[] = 'Source and destination cannot be the same location.';
    }

    foreach ([$sourceStorageId, $destinationStorageId] as $storageId) {
        if ($storageId !== null && !storage_exists_for_assignment($storageId)) {
            $errors[] = 'Pick valid active locations.';
            break;
        }
    }

    if ($usedAt === '') {
        $errors[] = 'Date and time are required.';
    }

    if ($errors !== []) {
        if (request_wants_json()) {
            json_response([
                'message' => 'Movement could not be saved.',
                'errors' => $errors,
            ], 422);
        }

        flash_errors($errors);
        redirect('/items/' . $item['id']);
    }

    try {
        apply_inventory_movement(
            $item,
            $movementType,
            $movementType === 'adjustment' ? (float) input('quantity') : $quantity,
            $sourceStorageId,
            $destinationStorageId,
            $usedAt,
            $referenceCode,
            $notes,
            (int) $user['id']
        );

        $updatedItem = find_item_or_abort((int) $item['id']);
        $payload = item_response_payload($updatedItem);

        if (request_wants_json()) {
            json_response(array_merge([
                'message' => 'Movement saved.',
            ], $payload));
        }

        flash('success', 'Movement saved.');
    } catch (Throwable $exception) {
        if (request_wants_json()) {
            json_response([
                'message' => $exception->getMessage(),
                'errors' => [$exception->getMessage()],
            ], 422);
        }

        flash('danger', $exception->getMessage());
    }

    redirect('/items/' . $item['id']);
}

function handle_movements_index(): void
{
    app_ready_or_redirect();
    Auth::requireLogin();

    $filters = movement_filters();
    [$where, $params] = build_movement_where($filters);

    $movements = Database::fetchAll(
        "SELECT m.*,
                i.name AS item_name,
                i.sku,
                i.unit,
                source_storage.name AS source_storage_name,
                source_storage.storage_type AS source_storage_type,
                destination_storage.name AS destination_storage_name,
                destination_storage.storage_type AS destination_storage_type,
                u.name AS user_name
         FROM inventory_movements m
         INNER JOIN items i ON i.id = m.item_id AND i.is_active = 1
         LEFT JOIN storages source_storage ON source_storage.id = m.source_storage_id
         LEFT JOIN storages destination_storage ON destination_storage.id = m.destination_storage_id
         LEFT JOIN users u ON u.id = m.performed_by
         {$where}
         ORDER BY m.used_at DESC, m.id DESC
         LIMIT 250",
        $params
    );

    View::render('movements/index', [
        'title' => 'Movement Log',
        'movements' => $movements,
        'filters' => $filters,
        'items' => all_items_for_select(),
        'storages' => all_storages_for_select($filters['storage_id']),
    ]);
}

function handle_export_items(): void
{
    app_ready_or_redirect();
    Auth::requireLogin();

    $filters = item_filters();
    [$where, $params] = build_item_where($filters);

    $items = Database::fetchAll(
        "SELECT i.*,
                default_storage.name AS default_storage_name,
                (
                    SELECT COUNT(*)
                    FROM item_storage_balances balances
                    WHERE balances.item_id = i.id
                      AND balances.quantity > 0
                ) AS location_count,
                (
                    SELECT GROUP_CONCAT(storage.name ORDER BY balances.quantity DESC, storage.name ASC SEPARATOR ', ')
                    FROM item_storage_balances balances
                    INNER JOIN storages storage ON storage.id = balances.storage_id
                    WHERE balances.item_id = i.id
                      AND balances.quantity > 0
                ) AS storage_summary,
                (SELECT MAX(m.used_at) FROM inventory_movements m WHERE m.item_id = i.id) AS last_movement_at
         FROM items i
         LEFT JOIN storages default_storage ON default_storage.id = i.storage_id
         {$where}
         ORDER BY i.name ASC",
        $params
    );

    $rows = array_map(static function (array $item): array {
        return [
            $item['name'],
            $item['sku'],
            $item['category'] ?: '',
            $item['location_count'],
            $item['storage_summary'] ?: '',
            $item['default_storage_name'] ?: '',
            $item['unit'],
            format_quantity($item['current_quantity']),
            format_quantity($item['reorder_level']),
            format_money($item['cost_per_unit']),
            (int) $item['is_active'] === 1 ? 'Active' : 'Deleted',
            $item['last_movement_at'] ?: '',
            $item['notes'] ?: '',
        ];
    }, $items);

    export_csv('items-export-' . date('Ymd-His') . '.csv', [
        'Name',
        'SKU',
        'Category',
        'Location Count',
        'Locations',
        'Default Location',
        'Unit',
        'Current Quantity',
        'Reorder Level',
        'Cost Per Unit',
        'Status',
        'Last Movement At',
        'Notes',
    ], $rows);
}

function handle_export_movements(): void
{
    app_ready_or_redirect();
    Auth::requireLogin();

    $filters = movement_filters();
    [$where, $params] = build_movement_where($filters);

    $movements = Database::fetchAll(
        "SELECT m.*,
                i.name AS item_name,
                i.sku,
                i.unit,
                source_storage.name AS source_storage_name,
                source_storage.storage_type AS source_storage_type,
                destination_storage.name AS destination_storage_name,
                destination_storage.storage_type AS destination_storage_type,
                u.name AS user_name
         FROM inventory_movements m
         INNER JOIN items i ON i.id = m.item_id AND i.is_active = 1
         LEFT JOIN storages source_storage ON source_storage.id = m.source_storage_id
         LEFT JOIN storages destination_storage ON destination_storage.id = m.destination_storage_id
         LEFT JOIN users u ON u.id = m.performed_by
         {$where}
         ORDER BY m.used_at DESC, m.id DESC",
        $params
    );

    $rows = array_map(static function (array $movement): array {
        return [
            $movement['used_at'],
            $movement['item_name'],
            $movement['sku'],
            ucfirst($movement['movement_type']),
            $movement['movement_quantity'] ? format_quantity($movement['movement_quantity']) : '',
            format_quantity($movement['quantity_delta']),
            format_quantity($movement['balance_after']),
            $movement['source_storage_name'] ?: '',
            $movement['destination_storage_name'] ?: '',
            $movement['reference_code'] ?: '',
            $movement['user_name'] ?: '',
            $movement['notes'] ?: '',
        ];
    }, $movements);

    export_csv('movement-export-' . date('Ymd-His') . '.csv', [
        'Used At',
        'Item',
        'SKU',
        'Type',
        'Movement Quantity',
        'Quantity Delta',
        'Balance After',
        'Source Location',
        'Destination Location',
        'Reference',
        'Performed By',
        'Notes',
    ], $rows);
}

function handle_export_storages(): void
{
    app_ready_or_redirect();
    Auth::requireLogin();

    $filters = storage_filters();
    $storages = storage_summaries($filters);
    $rows = [];

    foreach ($storages as $storage) {
        $storageLabel = storage_type_label($storage['storage_type']);
        $storageStatus = (int) $storage['is_active'] === 1 ? 'Active' : 'Deleted';
        $storageUpdatedAt = $storage['updated_at'] ? format_datetime_display($storage['updated_at']) : '';
        $storageItems = storage_items((int) $storage['id']);

        $storageColumns = [
            $storage['name'],
            $storageLabel,
            $storageStatus,
            (int) $storage['active_item_count'],
            format_quantity($storage['total_quantity']),
            format_money($storage['total_stock_value']),
            format_quantity($storage['total_used']),
            format_quantity($storage['transferred_in']),
            format_quantity($storage['transferred_out']),
            $storage['notes'] ?: '',
            $storageUpdatedAt,
        ];

        $rows[] = array_merge($storageColumns, [
            'Storage',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
        ]);

        foreach ($storageItems as $item) {
            $rows[] = array_merge($storageColumns, [
                'Item',
                $item['name'],
                $item['sku'],
                $item['category'] ?: 'Unsorted',
                format_quantity($item['quantity']),
                $item['unit'],
                format_money($item['cost_per_unit']),
                format_money(stock_value($item['quantity'], $item['cost_per_unit'])),
                format_quantity($item['reorder_level']),
                format_quantity($item['total_used']),
                format_quantity($item['transferred_in']),
                format_quantity($item['transferred_out']),
                (int) $item['is_active'] === 1 ? 'Active' : 'Deleted',
                $item['last_activity_at'] ? format_datetime_display($item['last_activity_at']) : 'Never',
                $item['notes'] ?: '',
            ]);
        }

        $rows[] = array_fill(0, 26, '');
    }

    export_csv('storage-export-' . date('Ymd-His') . '.csv', [
        'Storage Name',
        'Storage Type',
        'Storage Status',
        'Active Items',
        'Remaining Quantity',
        'Storage Total Value',
        'Used Quantity',
        'Transferred In',
        'Transferred Out',
        'Storage Notes',
        'Storage Updated At',
        'Row Type',
        'Item Name',
        'Item SKU',
        'Item Category',
        'Item Quantity',
        'Item Unit',
        'Item Cost Per Unit',
        'Item Stock Value',
        'Item Reorder Level',
        'Item Used Quantity',
        'Item Transferred In',
        'Item Transferred Out',
        'Item Status',
        'Item Last Activity',
        'Item Notes',
    ], $rows);
}

function handle_export_users(): void
{
    app_ready_or_redirect();
    Auth::requireOwner();

    $users = Database::fetchAll(
        'SELECT id, name, email, role, is_active, last_login_at, created_at
         FROM users
         ORDER BY FIELD(role, "owner", "admin"), created_at ASC'
    );

    $rows = array_map(static function (array $userRecord): array {
        return [
            $userRecord['name'],
            $userRecord['email'],
            strtoupper($userRecord['role']),
            (int) $userRecord['is_active'] === 1 ? 'Active' : 'Disabled',
            $userRecord['last_login_at'] ?: '',
            $userRecord['created_at'] ?: '',
        ];
    }, $users);

    export_csv('admin-export-' . date('Ymd-His') . '.csv', [
        'Name',
        'Email',
        'Role',
        'Status',
        'Last Login At',
        'Created At',
    ], $rows);
}

function handle_storages_index(): void
{
    app_ready_or_redirect();
    Auth::requireLogin();

    $filters = storage_filters();
    $storages = storage_summaries($filters);

    $counts = [
        'active' => (int) Database::scalar('SELECT COUNT(*) FROM storages WHERE is_active = 1'),
        'archived' => (int) Database::scalar('SELECT COUNT(*) FROM storages WHERE is_active = 0'),
    ];

    View::render('storages/index', [
        'title' => 'Storages',
        'storages' => $storages,
        'filters' => $filters,
        'counts' => $counts,
    ]);
}

function handle_storages_show(array $params): void
{
    app_ready_or_redirect();
    Auth::requireLogin();

    $storage = find_storage_or_abort((int) $params['id']);
    $items = storage_items((int) $storage['id']);

    $metrics = [
        'contained_items' => count($items),
        'active_items' => count(array_filter($items, static fn (array $item): bool => (int) $item['is_active'] === 1)),
        'low_stock_items' => count(array_filter(
            $items,
            static fn (array $item): bool => (int) $item['is_active'] === 1 && (float) $item['quantity'] <= (float) $item['reorder_level']
        )),
        'stock_value' => array_reduce(
            $items,
            static fn (float $carry, array $item): float => $carry + stock_value($item['quantity'], $item['cost_per_unit']),
            0.0
        ),
    ];

    View::render('storages/show', [
        'title' => $storage['name'],
        'storage' => $storage,
        'items' => $items,
        'metrics' => $metrics,
    ]);
}

function handle_storages_create_page(): void
{
    app_ready_or_redirect();
    Auth::requireLogin();

    View::render('storages/form', [
        'title' => 'Create Storage',
        'mode' => 'create',
        'storage' => default_storage_payload(),
    ]);
}

function handle_storages_create_submit(): void
{
    app_ready_or_redirect();
    Auth::requireLogin();
    verify_csrf();

    $user = Auth::user();
    $payload = [
        'name' => trim((string) input('name')),
        'storage_type' => (string) input('storage_type', 'storage'),
        'notes' => trim((string) input('notes')),
    ];

    flash_old_input($payload);

    $errors = [];

    if ($payload['name'] === '') {
        $errors[] = 'Storage name is required.';
    }

    if (!in_array($payload['storage_type'], ['warehouse', 'storage'], true)) {
        $errors[] = 'Pick a valid location type.';
    }

    if (active_storage_name_exists($payload['name'])) {
        $errors[] = 'An active location already uses this name.';
    }

    if ($errors !== []) {
        flash_errors($errors);
        redirect('/storages/create');
    }

    Database::execute(
        'INSERT INTO storages (name, storage_type, notes, is_active, created_by, updated_by, created_at, updated_at)
         VALUES (:name, :storage_type, :notes, 1, :created_by, :updated_by, NOW(), NOW())',
        [
            'name' => $payload['name'],
            'storage_type' => $payload['storage_type'],
            'notes' => $payload['notes'] !== '' ? $payload['notes'] : null,
            'created_by' => $user['id'],
            'updated_by' => $user['id'],
        ]
    );

    consume_old_input();
    flash('success', 'Storage created.');
    redirect('/storages');
}

function handle_storages_edit_page(array $params): void
{
    app_ready_or_redirect();
    Auth::requireLogin();

    $storage = find_storage_or_abort((int) $params['id']);

    View::render('storages/form', [
        'title' => 'Edit ' . $storage['name'],
        'mode' => 'edit',
        'storage' => [
            'id' => $storage['id'],
            'name' => old('name', $storage['name']),
            'storage_type' => old('storage_type', $storage['storage_type']),
            'notes' => old('notes', $storage['notes']),
            'is_active' => (int) $storage['is_active'],
            'active_item_count' => (int) $storage['active_item_count'],
            'total_quantity' => (float) $storage['total_quantity'],
            'total_used' => (float) $storage['total_used'],
        ],
    ]);
}

function handle_storages_edit_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requireLogin();
    verify_csrf();

    $storage = find_storage_or_abort((int) $params['id']);
    $user = Auth::user();
    $payload = [
        'name' => trim((string) input('name')),
        'storage_type' => (string) input('storage_type', 'storage'),
        'notes' => trim((string) input('notes')),
    ];

    flash_old_input($payload);

    $errors = [];

    if ($payload['name'] === '') {
        $errors[] = 'Storage name is required.';
    }

    if (!in_array($payload['storage_type'], ['warehouse', 'storage'], true)) {
        $errors[] = 'Pick a valid location type.';
    }

    if (active_storage_name_exists($payload['name'], (int) $storage['id'])) {
        $errors[] = 'An active location already uses this name.';
    }

    if ($errors !== []) {
        flash_errors($errors);
        redirect('/storages/' . $storage['id'] . '/edit');
    }

    Database::execute(
        'UPDATE storages
         SET name = :name,
             storage_type = :storage_type,
             notes = :notes,
             updated_by = :updated_by,
             updated_at = NOW()
         WHERE id = :id',
        [
            'name' => $payload['name'],
            'storage_type' => $payload['storage_type'],
            'notes' => $payload['notes'] !== '' ? $payload['notes'] : null,
            'updated_by' => $user['id'],
            'id' => $storage['id'],
        ]
    );

    consume_old_input();
    flash('success', 'Storage updated.');
    redirect('/storages');
}

function handle_storages_status_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requireLogin();
    verify_csrf();

    $storage = find_storage_or_abort((int) $params['id']);
    $user = Auth::user();
    $nextStatus = (int) $storage['is_active'] === 1 ? 0 : 1;

    if ($nextStatus === 0 && (int) $storage['active_item_count'] > 0) {
        flash('danger', 'Move or delete the active items in this location before deleting it.');
        redirect('/storages');
    }

    if ($nextStatus === 1 && active_storage_name_exists((string) $storage['name'], (int) $storage['id'])) {
        flash('danger', 'Recover failed. Another active location already uses the name ' . $storage['name'] . '.');
        redirect('/storages?status=archived');
    }

    Database::execute(
        'UPDATE storages SET is_active = :is_active, updated_by = :updated_by, updated_at = NOW() WHERE id = :id',
        [
            'is_active' => $nextStatus,
            'updated_by' => $user['id'],
            'id' => $storage['id'],
        ]
    );

    flash('success', $nextStatus ? 'Storage recovered.' : 'Storage deleted.');
    redirect($nextStatus ? '/storages' : '/storages?status=archived');
}

function handle_users_index(): void
{
    app_ready_or_redirect();
    Auth::requireOwner();

    $users = Database::fetchAll(
        'SELECT id, name, email, role, is_active, last_login_at, created_at
         FROM users
         ORDER BY FIELD(role, "owner", "admin"), created_at ASC'
    );

    View::render('users/index', [
        'title' => 'Admins',
        'users' => $users,
    ]);
}

function handle_users_create_page(): void
{
    app_ready_or_redirect();
    Auth::requireOwner();

    View::render('users/form', [
        'title' => 'Create Admin',
        'mode' => 'create',
        'userRecord' => [
            'name' => old('name', ''),
            'email' => old('email', ''),
            'role' => 'admin',
            'is_active' => 1,
        ],
    ]);
}

function handle_users_create_submit(): void
{
    app_ready_or_redirect();
    Auth::requireOwner();
    verify_csrf();

    $payload = [
        'name' => trim((string) input('name')),
        'email' => strtolower(trim((string) input('email'))),
        'password' => (string) input('password'),
        'password_confirmation' => (string) input('password_confirmation'),
    ];

    flash_old_input([
        'name' => $payload['name'],
        'email' => $payload['email'],
    ]);

    $errors = [];

    if ($payload['name'] === '') {
        $errors[] = 'Name is required.';
    }

    if (!filter_var($payload['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Use a valid email address.';
    }

    if (strlen($payload['password']) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }

    if ($payload['password'] !== $payload['password_confirmation']) {
        $errors[] = 'Passwords do not match.';
    }

    $existingEmail = Database::fetch('SELECT id FROM users WHERE email = :email LIMIT 1', [
        'email' => $payload['email'],
    ]);

    if ($existingEmail) {
        $errors[] = 'Email already exists.';
    }

    if ($errors !== []) {
        flash_errors($errors);
        redirect('/users/create');
    }

    Database::execute(
        'INSERT INTO users (name, email, password_hash, role, is_active, created_at, updated_at)
         VALUES (:name, :email, :password_hash, "admin", 1, NOW(), NOW())',
        [
            'name' => $payload['name'],
            'email' => $payload['email'],
            'password_hash' => password_hash($payload['password'], PASSWORD_DEFAULT),
        ]
    );

    consume_old_input();
    flash('success', 'Admin created.');
    redirect('/users');
}

function handle_users_edit_page(array $params): void
{
    app_ready_or_redirect();
    Auth::requireOwner();

    $userRecord = find_user_or_abort((int) $params['id']);

    View::render('users/form', [
        'title' => 'Edit ' . $userRecord['name'],
        'mode' => 'edit',
        'userRecord' => [
            'id' => $userRecord['id'],
            'name' => old('name', $userRecord['name']),
            'email' => old('email', $userRecord['email']),
            'role' => $userRecord['role'],
            'is_active' => (int) $userRecord['is_active'],
        ],
    ]);
}

function handle_users_edit_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requireOwner();
    verify_csrf();

    $userRecord = find_user_or_abort((int) $params['id']);

    $payload = [
        'name' => trim((string) input('name')),
        'email' => strtolower(trim((string) input('email'))),
        'password' => (string) input('password'),
        'password_confirmation' => (string) input('password_confirmation'),
    ];

    flash_old_input([
        'name' => $payload['name'],
        'email' => $payload['email'],
    ]);

    $errors = [];

    if ($payload['name'] === '') {
        $errors[] = 'Name is required.';
    }

    if (!filter_var($payload['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Use a valid email address.';
    }

    if ($payload['password'] !== '' && strlen($payload['password']) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }

    if ($payload['password'] !== $payload['password_confirmation']) {
        $errors[] = 'Passwords do not match.';
    }

    $existingEmail = Database::fetch(
        'SELECT id FROM users WHERE email = :email AND id != :id LIMIT 1',
        ['email' => $payload['email'], 'id' => $userRecord['id']]
    );

    if ($existingEmail) {
        $errors[] = 'Email already exists.';
    }

    if ($errors !== []) {
        flash_errors($errors);
        redirect('/users/' . $userRecord['id'] . '/edit');
    }

    Database::execute(
        'UPDATE users SET name = :name, email = :email, updated_at = NOW() WHERE id = :id',
        [
            'name' => $payload['name'],
            'email' => $payload['email'],
            'id' => $userRecord['id'],
        ]
    );

    if ($payload['password'] !== '') {
        Database::execute(
            'UPDATE users SET password_hash = :password_hash, updated_at = NOW() WHERE id = :id',
            [
                'password_hash' => password_hash($payload['password'], PASSWORD_DEFAULT),
                'id' => $userRecord['id'],
            ]
        );
    }

    consume_old_input();
    flash('success', 'User updated.');
    redirect('/users');
}

function handle_users_status_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requireOwner();
    verify_csrf();

    $userRecord = find_user_or_abort((int) $params['id']);
    $currentUser = Auth::user();

    if ($userRecord['role'] === 'owner') {
        flash('danger', 'You do not disable the owner account. That is how stupid outages happen.');
        redirect('/users');
    }

    if ((int) $userRecord['id'] === (int) $currentUser['id']) {
        flash('danger', 'Disabling yourself is a rookie move.');
        redirect('/users');
    }

    $nextStatus = (int) $userRecord['is_active'] === 1 ? 0 : 1;

    Database::execute(
        'UPDATE users SET is_active = :is_active, updated_at = NOW() WHERE id = :id',
        [
            'is_active' => $nextStatus,
            'id' => $userRecord['id'],
        ]
    );

    flash('success', $nextStatus ? 'Admin restored.' : 'Admin disabled.');
    redirect('/users');
}
