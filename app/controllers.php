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
    $status = (string) query('status', 'all');
    $type = (string) query('type', '');

    return [
        'search' => trim((string) query('search', '')),
        'status' => in_array($status, ['active', 'archived', 'all'], true) ? $status : 'all',
        'type' => in_array($type, ['warehouse', 'storage'], true) ? $type : '',
    ];
}

function build_storage_where(array $filters, string $alias = 's'): array
{
    $conditions = ["{$alias}.is_system = 0"];
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
        'SELECT id, name, sku, barcode, unit, is_active
         FROM items
         WHERE is_active = 1
         ORDER BY name ASC'
    );
}

function all_storages_for_select(?int $selectedId = null, bool $includeSystem = false): array
{
    $conditions = [$includeSystem ? 'storages.is_active = 1' : '(storages.is_active = 1 AND storages.is_system = 0)'];
    $params = [];

    if ($selectedId !== null) {
        $conditions[] = 'storages.id = :selected_id';
        $params['selected_id'] = $selectedId;
    }

    return Database::fetchAll(
        'SELECT storages.id,
                storages.name,
                storages.storage_type,
                storages.is_active,
                storages.owner_user_id,
                owner_user.name AS owner_name
         FROM storages
         LEFT JOIN users owner_user ON owner_user.id = storages.owner_user_id
         WHERE ' . implode(' OR ', $conditions) . '
         ORDER BY FIELD(storages.storage_type, "warehouse", "storage"), storages.is_active DESC, storages.name ASC',
        $params
    );
}

function admin_owner_users_for_select(?int $selectedId = null): array
{
    $params = [];
    $conditions = ['(is_active = 1 AND role IN ("owner", "admin"))'];

    if ($selectedId !== null) {
        $conditions[] = 'id = :selected_id';
        $params['selected_id'] = $selectedId;
    }

    return Database::fetchAll(
        'SELECT id, name, email, role
         FROM users
         WHERE ' . implode(' OR ', $conditions) . '
         ORDER BY FIELD(role, "owner", "admin"), name ASC',
        $params
    );
}

function storage_owner_user_id(int $storageId): ?int
{
    $storage = Database::fetch(
        'SELECT owner_user_id, created_by
         FROM storages
         WHERE id = :id
         LIMIT 1',
        ['id' => $storageId]
    );

    if (!$storage) {
        return null;
    }

    if (!empty($storage['owner_user_id'])) {
        return (int) $storage['owner_user_id'];
    }

    if (!empty($storage['created_by'])) {
        return (int) $storage['created_by'];
    }

    return null;
}

function storage_is_owned_by_user(int $storageId, int $userId): bool
{
    return storage_owner_user_id($storageId) === $userId;
}

function storages_owned_by_user_for_select(int $userId, ?int $selectedId = null): array
{
    $params = ['owner_user_id' => $userId];
    $conditions = ['(storages.is_active = 1 AND storages.is_system = 0 AND storages.owner_user_id = :owner_user_id)'];

    if ($selectedId !== null) {
        $conditions[] = 'storages.id = :selected_id';
        $params['selected_id'] = $selectedId;
    }

    return Database::fetchAll(
        'SELECT storages.id,
                storages.name,
                storages.storage_type,
                storages.is_active,
                storages.owner_user_id,
                owner_user.name AS owner_name
         FROM storages
         LEFT JOIN users owner_user ON owner_user.id = storages.owner_user_id
         WHERE ' . implode(' OR ', $conditions) . '
         ORDER BY FIELD(storages.storage_type, "warehouse", "storage"), storages.name ASC',
        $params
    );
}

function normalize_entity_id($value): ?int
{
    return ctype_digit((string) $value) ? (int) $value : null;
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

function active_storage_name_exists(string $name, ?int $ignoreId = null): bool
{
    $sql = 'SELECT id FROM storages WHERE LOWER(name) = LOWER(:name) AND is_active = 1 AND is_system = 0';
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

function requested_item_copy_source(): ?array
{
    $copyItemId = normalize_entity_id(input('copy_item_id', input('copy', old('copy_item_id'))));

    if ($copyItemId === null) {
        return null;
    }

    return find_item_or_abort($copyItemId);
}

function requested_storage_copy_source(): ?array
{
    $copyStorageId = normalize_entity_id(input('copy_storage_id', input('copy', old('copy_storage_id'))));

    if ($copyStorageId === null) {
        return null;
    }

    return find_storage_or_abort($copyStorageId);
}

function next_storage_copy_name(string $name): string
{
    $baseName = trim($name) !== '' ? trim($name) : 'Location';
    $candidate = $baseName . ' Copy';
    $suffix = 2;

    while (active_storage_name_exists($candidate)) {
        $candidate = $baseName . ' Copy ' . $suffix;
        $suffix++;
    }

    return $candidate;
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

function find_storage_or_abort(int $storageId): array
{
    $storage = Database::fetch(
        'SELECT s.*,
                (
                    SELECT COUNT(*)
                    FROM item_storage_balances balances
                    INNER JOIN items i ON i.id = balances.item_id
                    WHERE balances.storage_id = s.id
                      AND i.is_active = 1
                ) AS assigned_item_count,
                (
                    SELECT COUNT(*)
                    FROM item_storage_balances balances
                    INNER JOIN items i ON i.id = balances.item_id
                    WHERE balances.storage_id = s.id
                      AND balances.quantity > 0
                      AND i.is_active = 1
                ) AS stocked_item_count,
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
                updater.name AS updater_name,
                owner_user.name AS owner_name,
                owner_user.email AS owner_email,
                owner_user.role AS owner_role
         FROM storages s
         LEFT JOIN users creator ON creator.id = s.created_by
         LEFT JOIN users updater ON updater.id = s.updated_by
         LEFT JOIN users owner_user ON owner_user.id = s.owner_user_id
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

function normalize_package_preset_label($value): string
{
    $label = trim((string) $value);
    $label = preg_replace('/\s+/u', ' ', $label) ?: '';

    return mb_substr($label, 0, 60);
}

function item_package_presets(int $itemId): array
{
    $rows = Database::fetchAll(
        'SELECT presets.*,
                created_user.name AS created_by_name,
                updated_user.name AS updated_by_name
         FROM item_package_presets presets
         LEFT JOIN users created_user ON created_user.id = presets.created_by
         LEFT JOIN users updated_user ON updated_user.id = presets.updated_by
         WHERE presets.item_id = :item_id
         ORDER BY presets.is_default DESC, presets.label ASC',
        ['item_id' => $itemId]
    );

    return array_map(static function (array $preset): array {
        return [
            'id' => (int) $preset['id'],
            'item_id' => (int) $preset['item_id'],
            'label' => (string) $preset['label'],
            'pieces_per_unit' => format_quantity($preset['pieces_per_unit']),
            'pieces_per_unit_raw' => (float) $preset['pieces_per_unit'],
            'is_default' => (int) $preset['is_default'],
            'created_by_name' => $preset['created_by_name'] ?? null,
            'updated_by_name' => $preset['updated_by_name'] ?? null,
        ];
    }, $rows);
}

function item_package_preset_record(int $itemId, int $presetId): ?array
{
    return Database::fetch(
        'SELECT *
         FROM item_package_presets
         WHERE item_id = :item_id
           AND id = :id
         LIMIT 1',
        [
            'item_id' => $itemId,
            'id' => $presetId,
        ]
    );
}

function ensure_item_package_default(int $itemId): void
{
    $defaultExists = (int) Database::scalar(
        'SELECT COUNT(*)
         FROM item_package_presets
         WHERE item_id = :item_id
           AND is_default = 1',
        ['item_id' => $itemId]
    );

    if ($defaultExists > 0) {
        return;
    }

    $firstPresetId = Database::scalar(
        'SELECT id
         FROM item_package_presets
         WHERE item_id = :item_id
         ORDER BY id ASC
         LIMIT 1',
        ['item_id' => $itemId]
    );

    if ($firstPresetId) {
        Database::execute(
            'UPDATE item_package_presets
             SET is_default = 1,
                 updated_at = NOW()
             WHERE id = :id',
            ['id' => (int) $firstPresetId]
        );
    }
}

function storage_items(int $storageId): array
{
    return Database::fetchAll(
        'SELECT i.id,
                i.name,
                i.sku,
                i.barcode,
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
                      AND i.is_active = 1
                ) AS assigned_item_count,
                (
                    SELECT COUNT(*)
                    FROM item_storage_balances balances
                    INNER JOIN items i ON i.id = balances.item_id
                    WHERE balances.storage_id = s.id
                      AND balances.quantity > 0
                      AND i.is_active = 1
                ) AS stocked_item_count,
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

function sync_item_inventory_snapshot(int $itemId, int $updatedBy): float
{
    $currentQuantity = round((float) Database::scalar(
        'SELECT COALESCE(SUM(quantity), 0) FROM item_storage_balances WHERE item_id = :item_id',
        ['item_id' => $itemId]
    ), 2);

    Database::execute(
        'UPDATE items
         SET current_quantity = :current_quantity,
             storage_id = :storage_id,
             updated_by = :updated_by,
             updated_at = NOW()
         WHERE id = :id',
        [
            'current_quantity' => $currentQuantity,
            'storage_id' => preferred_item_storage_id($itemId),
            'updated_by' => $updatedBy,
            'id' => $itemId,
        ]
    );

    return $currentQuantity;
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

    if ($normalizedQuantity < 0) {
        throw new RuntimeException('Storage balances cannot be negative.');
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
    int $performedBy,
    ?string $contextType = null,
    ?int $contextId = null
): void {
    $pdo = Database::connection();
    $ownsTransaction = !$pdo->inTransaction();

    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

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

        $newBalance = sync_item_inventory_snapshot((int) $item['id'], $performedBy);

        if ($newBalance < 0) {
            throw new RuntimeException('That movement would make stock negative. Bad data in, bad data out.');
        }

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
                context_type,
                context_id,
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
                :context_type,
                :context_id,
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
                'context_type' => $contextType !== '' ? $contextType : null,
                'context_id' => $contextId,
                'notes' => $notes !== '' ? $notes : null,
                'used_at' => $usedAt,
                'performed_by' => $performedBy,
            ]
        );

        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->commit();
        }
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}

function clone_storage_inventory_to_location(array $sourceStorage, int $destinationStorageId, string $destinationStorageName, int $performedBy): void
{
    $items = storage_items((int) $sourceStorage['id']);

    foreach ($items as $item) {
        $quantity = round((float) $item['quantity'], 2);

        if ($quantity <= 0) {
            continue;
        }

        persist_item_storage_balance((int) $item['id'], $destinationStorageId, $quantity);

        $newBalance = sync_item_inventory_snapshot((int) $item['id'], $performedBy);

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
                'item_id' => $item['id'],
                'movement_type' => 'restock',
                'movement_quantity' => $quantity,
                'quantity_delta' => $quantity,
                'balance_after' => $newBalance,
                'destination_storage_id' => $destinationStorageId,
                'destination_balance_after' => $quantity,
                'reference_code' => 'STORAGE-COPY',
                'notes' => 'Copied current stock from ' . $sourceStorage['name'] . ' into ' . $destinationStorageName . '.',
                'performed_by' => $performedBy,
            ]
        );
    }
}

function clone_storage_item_setup_to_location(array $sourceStorage, int $destinationStorageId): void
{
    $items = storage_items((int) $sourceStorage['id']);

    foreach ($items as $item) {
        assign_item_to_storage((int) $item['id'], $destinationStorageId);
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

function export_xlsx(string $filename, string $bytes): never
{
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
    exit;
}

function default_item_payload(?array $sourceItem = null): array
{
    $sourceUnit = item_unit_form_state($sourceItem['unit'] ?? 'pcs');

    return [
        'name' => old('name', (string) ($sourceItem['name'] ?? '')),
        'sku' => old('sku', (string) ($sourceItem['sku'] ?? '')),
        'barcode' => old('barcode', (string) ($sourceItem['barcode'] ?? '')),
        'category' => old('category', (string) ($sourceItem['category'] ?? '')),
        'storage_id' => old('storage_id', ''),
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

function default_storage_payload(?array $sourceStorage = null): array
{
    return [
        'name' => old('name', $sourceStorage ? next_storage_copy_name((string) $sourceStorage['name']) : ''),
        'storage_type' => old('storage_type', (string) ($sourceStorage['storage_type'] ?? 'storage')),
        'notes' => old('notes', (string) ($sourceStorage['notes'] ?? '')),
        'owner_user_id' => old('owner_user_id', (string) ($sourceStorage['owner_user_id'] ?? ((Auth::user()['id'] ?? '') ?: ''))),
        'copy_storage_id' => old('copy_storage_id', $sourceStorage ? (string) $sourceStorage['id'] : ''),
        'copy_contents_mode' => old('copy_contents_mode', 'empty'),
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

function auth_request_ip(): string
{
    $remoteAddress = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    $forwardedFor = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    $proxyLikeRemote = $remoteAddress === ''
        || $remoteAddress === '127.0.0.1'
        || $remoteAddress === '::1'
        || starts_with($remoteAddress, '10.')
        || starts_with($remoteAddress, '192.168.')
        || preg_match('/^172\.(1[6-9]|2\d|3[0-1])\./', $remoteAddress) === 1;

    if ($proxyLikeRemote && $forwardedFor !== '') {
        $parts = explode(',', $forwardedFor);
        $candidate = trim((string) ($parts[0] ?? ''));

        if ($candidate !== '') {
            return substr($candidate, 0, 64);
        }
    }

    return substr($remoteAddress !== '' ? $remoteAddress : 'unknown', 0, 64);
}

function auth_request_user_agent(): string
{
    return substr(trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255);
}

function login_attempts_are_limited(string $email, string $ipAddress): bool
{
    try {
        $failedAttempts = (int) Database::scalar(
            'SELECT COUNT(*)
             FROM login_attempts
             WHERE success = 0
               AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
               AND (email = :email OR ip_address = :ip_address)',
            [
                'email' => $email,
                'ip_address' => $ipAddress,
            ]
        );
    } catch (Throwable $exception) {
        return false;
    }

    return $failedAttempts >= 8;
}

function record_login_attempt(string $email, bool $success, ?string $failureReason = null, ?int $userId = null): void
{
    try {
        Database::execute(
            'INSERT INTO login_attempts (
                user_id,
                email,
                ip_address,
                user_agent,
                success,
                failure_reason,
                created_at
             ) VALUES (
                :user_id,
                :email,
                :ip_address,
                :user_agent,
                :success,
                :failure_reason,
                NOW()
             )',
            [
                'user_id' => $userId,
                'email' => substr($email, 0, 190),
                'ip_address' => auth_request_ip(),
                'user_agent' => auth_request_user_agent() !== '' ? auth_request_user_agent() : null,
                'success' => $success ? 1 : 0,
                'failure_reason' => $failureReason,
            ]
        );
    } catch (Throwable $exception) {
        // Login audit must never lock users out if migration is still catching up.
    }
}

function password_reset_token_hash(string $token): string
{
    return hash('sha256', $token);
}

function create_password_reset_token(array $user, ?int $requestedByUserId = null): string
{
    $token = bin2hex(random_bytes(32));

    Database::execute(
        'UPDATE password_reset_tokens
         SET used_at = NOW()
         WHERE user_id = :user_id
           AND used_at IS NULL',
        ['user_id' => (int) $user['id']]
    );

    Database::execute(
        'INSERT INTO password_reset_tokens (
            user_id,
            requested_by_user_id,
            token_hash,
            request_ip,
            user_agent,
            expires_at,
            used_at,
            created_at
         ) VALUES (
            :user_id,
            :requested_by_user_id,
            :token_hash,
            :request_ip,
            :user_agent,
            DATE_ADD(NOW(), INTERVAL 60 MINUTE),
            NULL,
            NOW()
         )',
        [
            'user_id' => (int) $user['id'],
            'requested_by_user_id' => $requestedByUserId,
            'token_hash' => password_reset_token_hash($token),
            'request_ip' => auth_request_ip(),
            'user_agent' => auth_request_user_agent() !== '' ? auth_request_user_agent() : null,
        ]
    );

    return $token;
}

function find_valid_password_reset_token(string $token): ?array
{
    if (strlen($token) < 32 || strlen($token) > 160) {
        return null;
    }

    return Database::fetch(
        'SELECT reset_token.*,
                users.name AS user_name,
                users.email AS user_email,
                users.is_active AS user_is_active
         FROM password_reset_tokens reset_token
         INNER JOIN users ON users.id = reset_token.user_id
         WHERE reset_token.token_hash = :token_hash
           AND reset_token.used_at IS NULL
           AND reset_token.expires_at >= NOW()
           AND users.is_active = 1
         LIMIT 1',
        ['token_hash' => password_reset_token_hash($token)]
    );
}

function send_password_reset_email(array $user, string $token, ?int $requestedByUserId = null): array
{
    $recipientEmail = (string) ($user['email'] ?? $user['user_email'] ?? '');
    $recipientName = (string) ($user['name'] ?? $user['user_name'] ?? '');
    $resetUrl = absolute_url('/reset-password/' . rawurlencode($token));
    $subject = 'Reset your Inventory KONA password';
    $body = implode("\n", [
        'Password reset requested for Inventory KONA.',
        '',
        'Open this link within 60 minutes:',
        $resetUrl,
        '',
        'If you did not request this, ignore this email. Your current password stays unchanged.',
    ]);

    if (!email_password_resets_enabled()) {
        record_email_delivery_log(
            'password_reset',
            $recipientEmail,
            $recipientName,
            $subject,
            'suppressed',
            'Password reset emails are disabled.',
            (int) ($user['id'] ?? $user['user_id'] ?? 0) ?: null,
            'user',
            (int) ($user['id'] ?? $user['user_id'] ?? 0) ?: null
        );

        return ['ok' => false, 'status' => 'suppressed', 'error' => 'Password reset emails are disabled.'];
    }

    return send_inventory_email(
        $recipientEmail,
        $recipientName,
        $subject,
        $body,
        'password_reset',
        (int) ($user['id'] ?? $user['user_id'] ?? 0) ?: null,
        'user',
        (int) ($user['id'] ?? $user['user_id'] ?? 0) ?: null
    );
}

function handle_forgot_password_page(): void
{
    if (!app_installed()) {
        redirect('/setup');
    }

    if (Auth::check()) {
        redirect('/dashboard');
    }

    View::render('auth/forgot_password', [
        'title' => 'Forgot Password',
        'authPage' => true,
    ]);
}

function handle_forgot_password_submit(): void
{
    verify_csrf();
    app_ready_or_redirect();

    $email = strtolower(trim((string) input('email')));
    flash_old_input(['email' => $email]);

    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $user = Database::fetch(
            'SELECT id, name, email, is_active
             FROM users
             WHERE email = :email
             LIMIT 1',
            ['email' => $email]
        );

        if ($user && (int) ($user['is_active'] ?? 0) === 1) {
            $token = create_password_reset_token($user);
            $result = send_password_reset_email($user, $token);

            if (function_exists('record_activity')) {
                record_activity('auth.password_reset_requested', 'user', (int) $user['id'], 'Password reset requested for ' . $email, [
                    'email_status' => $result['status'] ?? 'unknown',
                ]);
            }
        }
    }

    consume_old_input();
    flash('success', 'If that email exists, a password reset link has been prepared.');
    redirect('/login');
}

function handle_reset_password_page(array $params): void
{
    if (!app_installed()) {
        redirect('/setup');
    }

    if (Auth::check()) {
        redirect('/dashboard');
    }

    $token = (string) ($params['token'] ?? '');
    $resetRecord = find_valid_password_reset_token($token);

    View::render('auth/reset_password', [
        'title' => 'Reset Password',
        'authPage' => true,
        'token' => $token,
        'resetRecord' => $resetRecord,
    ]);
}

function handle_reset_password_submit(array $params): void
{
    verify_csrf();
    app_ready_or_redirect();

    $token = (string) ($params['token'] ?? '');
    $resetRecord = find_valid_password_reset_token($token);

    if (!$resetRecord) {
        flash('danger', 'This reset link is invalid or expired.');
        redirect('/forgot-password');
    }

    $password = (string) input('password');
    $passwordConfirmation = (string) input('password_confirmation');
    $errors = [];

    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }

    if ($password !== $passwordConfirmation) {
        $errors[] = 'Passwords do not match.';
    }

    if ($errors !== []) {
        flash_errors($errors);
        redirect('/reset-password/' . rawurlencode($token));
    }

    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        Database::execute(
            'UPDATE users
             SET password_hash = :password_hash,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'id' => (int) $resetRecord['user_id'],
            ]
        );

        Database::execute(
            'UPDATE password_reset_tokens
             SET used_at = NOW()
             WHERE user_id = :user_id
               AND used_at IS NULL',
            ['user_id' => (int) $resetRecord['user_id']]
        );

        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', 'Could not reset password. Try again.');
        redirect('/forgot-password');
    }

    if (function_exists('record_activity')) {
        record_activity('auth.password_reset_completed', 'user', (int) $resetRecord['user_id'], 'Password reset completed for ' . $resetRecord['user_email']);
    }

    flash('success', 'Password updated. Sign in with the new password.');
    redirect('/login');
}

function handle_login_submit(): void
{
    verify_csrf();
    app_ready_or_redirect();

    $email = strtolower(trim((string) input('email')));
    $password = (string) input('password');
    $ipAddress = auth_request_ip();

    flash_old_input(['email' => $email]);

    if (login_attempts_are_limited($email, $ipAddress)) {
        record_login_attempt($email, false, 'rate_limited');
        flash('danger', 'Too many failed login attempts. Wait 15 minutes and try again.');
        redirect('/login');
    }

    if (!Auth::attempt($email, $password)) {
        record_login_attempt($email, false, 'invalid_credentials');
        flash('danger', 'Wrong email or password.');
        redirect('/login');
    }

    $user = Auth::user();
    record_login_attempt($email, true, null, $user ? (int) $user['id'] : null);

    if (function_exists('record_activity')) {
        record_activity('auth.login', 'user', $user ? (int) $user['id'] : null, 'User signed in: ' . ($user['email'] ?? $email), [
            'email' => $email,
            'ip_address' => $ipAddress,
        ]);
    }

    consume_old_input();
    flash('success', 'Welcome back.');
    redirect('/dashboard');
}

function handle_logout_submit(): void
{
    verify_csrf();
    $user = Auth::user();

    if (function_exists('record_activity')) {
        record_activity('auth.logout', 'user', $user ? (int) $user['id'] : null, 'User signed out: ' . ($user['email'] ?? 'unknown'), [
            'email' => $user['email'] ?? null,
            'ip_address' => auth_request_ip(),
        ]);
    }

    Auth::logout();
    flash('success', 'Logged out.');
    redirect('/login');
}

function normalize_dashboard_date_filter(string $value): string
{
    $value = trim($value);

    if ($value === '') {
        return '';
    }

    $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);

    if (!$date || $date->format('Y-m-d') !== $value) {
        return '';
    }

    return $value;
}

function dashboard_filters(): array
{
    $storageId = ctype_digit((string) query('storage_id', '')) ? (int) query('storage_id') : null;
    $dateFrom = normalize_dashboard_date_filter((string) query('date_from', ''));
    $dateTo = normalize_dashboard_date_filter((string) query('date_to', ''));

    if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
        [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
    }

    return [
        'storage_id' => $storageId,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
    ];
}

function selected_dashboard_storage(?int $storageId): ?array
{
    if ($storageId === null) {
        return null;
    }

    $storage = Database::fetch(
        'SELECT id, name, storage_type
         FROM storages
         WHERE id = :id
           AND is_active = 1
           AND is_system = 0
         LIMIT 1',
        ['id' => $storageId]
    );

    return $storage ?: null;
}

function dashboard_movement_scope(array $filters, string $movementAlias = 'm', string $itemAlias = 'i'): array
{
    $conditions = ["{$itemAlias}.is_active = 1"];
    $params = [];

    if (!empty($filters['storage_id'])) {
        $conditions[] = "({$movementAlias}.source_storage_id = :dashboard_source_storage_id OR {$movementAlias}.destination_storage_id = :dashboard_destination_storage_id)";
        $params['dashboard_source_storage_id'] = (int) $filters['storage_id'];
        $params['dashboard_destination_storage_id'] = (int) $filters['storage_id'];
    }

    if (($filters['date_from'] ?? '') !== '') {
        $conditions[] = "{$movementAlias}.used_at >= :dashboard_date_from";
        $params['dashboard_date_from'] = $filters['date_from'] . ' 00:00:00';
    }

    if (($filters['date_to'] ?? '') !== '') {
        $conditions[] = "{$movementAlias}.used_at <= :dashboard_date_to";
        $params['dashboard_date_to'] = $filters['date_to'] . ' 23:59:59';
    }

    return [
        'WHERE ' . implode(' AND ', $conditions),
        $params,
    ];
}

function dashboard_filter_labels(array $filters, ?array $selectedStorage): array
{
    $storageLabel = $selectedStorage
        ? storage_type_label((string) $selectedStorage['storage_type']) . ': ' . $selectedStorage['name']
        : 'All storages';

    if ($filters['date_from'] !== '' && $filters['date_to'] !== '') {
        $dateLabel = date('M j, Y', strtotime($filters['date_from'])) . ' - ' . date('M j, Y', strtotime($filters['date_to']));
    } elseif ($filters['date_from'] !== '') {
        $dateLabel = 'From ' . date('M j, Y', strtotime($filters['date_from']));
    } elseif ($filters['date_to'] !== '') {
        $dateLabel = 'Until ' . date('M j, Y', strtotime($filters['date_to']));
    } else {
        $dateLabel = 'All dates';
    }

    $trendLabel = 'Last 7 days';

    if ($filters['date_from'] !== '' && $filters['date_to'] !== '') {
        $trendLabel = $dateLabel;
    } elseif ($filters['date_from'] !== '' || $filters['date_to'] !== '') {
        $trendLabel = $dateLabel;
    }

    return [
        'storage' => $storageLabel,
        'date' => $dateLabel,
        'trend' => $trendLabel,
    ];
}

function dashboard_usage_trend(array $filters, int $days = 7): array
{
    $days = max(1, $days);
    $storageId = !empty($filters['storage_id']) ? (int) $filters['storage_id'] : null;
    $selectedFrom = $filters['date_from'] ?? '';
    $selectedTo = $filters['date_to'] ?? '';

    if ($selectedFrom !== '' && $selectedTo !== '') {
        $start = new DateTimeImmutable($selectedFrom);
        $end = new DateTimeImmutable($selectedTo);
    } elseif ($selectedFrom !== '') {
        $start = new DateTimeImmutable($selectedFrom);
        $end = $start->modify('+' . max(0, $days - 1) . ' days');
    } elseif ($selectedTo !== '') {
        $end = new DateTimeImmutable($selectedTo);
        $start = $end->modify('-' . max(0, $days - 1) . ' days');
    } else {
        $end = new DateTimeImmutable('today');
        $start = $end->modify('-' . max(0, $days - 1) . ' days');
    }

    if ($start > $end) {
        [$start, $end] = [$end, $start];
    }

    $dateSpan = ((int) $start->diff($end)->days) + 1;

    if ($dateSpan > 14) {
        $start = $end->modify('-13 days');
    }

    $params = [
        'trend_start' => $start->format('Y-m-d') . ' 00:00:00',
        'trend_end' => $end->format('Y-m-d') . ' 23:59:59',
    ];
    $storageCondition = '';

    if ($storageId !== null) {
        $storageCondition = ' AND (m.source_storage_id = :trend_source_storage_id OR m.destination_storage_id = :trend_destination_storage_id)';
        $params['trend_source_storage_id'] = $storageId;
        $params['trend_destination_storage_id'] = $storageId;
    }

    $rows = Database::fetchAll(
        "SELECT DATE(m.used_at) AS usage_day,
                COALESCE(SUM(m.movement_quantity), 0) AS total_used
         FROM inventory_movements m
         INNER JOIN items i ON i.id = m.item_id
         WHERE i.is_active = 1
           AND m.movement_type = 'usage'
           AND m.used_at >= :trend_start
           AND m.used_at <= :trend_end
           {$storageCondition}
         GROUP BY DATE(m.used_at)
         ORDER BY usage_day ASC",
        $params
    );

    $usageMap = [];

    foreach ($rows as $row) {
        $usageMap[(string) $row['usage_day']] = (float) $row['total_used'];
    }

    $trend = [];

    $totalDays = ((int) $start->diff($end)->days) + 1;

    for ($index = 0; $index < $totalDays; $index += 1) {
        $date = $start->modify('+' . $index . ' days');
        $key = $date->format('Y-m-d');

        $trend[] = [
            'date' => $key,
            'label' => $date->format('M j'),
            'total_used' => $usageMap[$key] ?? 0.0,
        ];
    }

    return $trend;
}

function dashboard_storage_value_breakdown(array $filters, int $limit = 6): array
{
    $limit = max(1, $limit);
    $where = 'WHERE s.is_active = 1 AND s.is_system = 0';
    $params = [];

    if (!empty($filters['storage_id'])) {
        $where .= ' AND s.id = :storage_id';
        $params['storage_id'] = (int) $filters['storage_id'];
    }

    return Database::fetchAll(
        sprintf(
            "SELECT s.id,
                    s.name,
                    s.storage_type,
                    COALESCE(SUM(CASE WHEN i.is_active = 1 THEN balances.quantity * i.cost_per_unit ELSE 0 END), 0) AS total_value,
                    COALESCE(SUM(CASE WHEN i.is_active = 1 THEN balances.quantity ELSE 0 END), 0) AS total_quantity
             FROM storages s
             LEFT JOIN item_storage_balances balances ON balances.storage_id = s.id
             LEFT JOIN items i ON i.id = balances.item_id
             {$where}
             GROUP BY s.id, s.name, s.storage_type
             ORDER BY total_value DESC, total_quantity DESC, s.name ASC
             LIMIT %d",
            $limit
        ),
        $params
    );
}

function normalize_site_settings_payload(array $submitted, array $clearSubmitted = [], bool $allowSecrets = true): array
{
    $payload = [];
    $errors = [];
    $skipped = [];

    foreach (site_setting_definitions() as $key => $field) {
        $value = trim((string) ($submitted[$key] ?? ''));
        $maxlength = (int) ($field['maxlength'] ?? 160);
        $options = $field['options'] ?? null;
        $type = (string) ($field['type'] ?? 'text');

        if ($type === 'secret') {
            if (!$allowSecrets) {
                $skipped[] = $key;
                continue;
            }

            $clearSecret = isset($clearSubmitted[$key]) && (string) $clearSubmitted[$key] === '1';

            if ($clearSecret) {
                $payload[$key] = '';
                continue;
            }

            if ($value === '') {
                $skipped[] = $key;
                continue;
            }
        }

        if ($maxlength > 0 && strlen($value) > $maxlength) {
            $errors[] = $field['label'] . ' must be ' . $maxlength . ' characters or less.';
        }

        if (in_array($type, ['select', 'choice'], true) && is_array($options)) {
            if ($value === '') {
                $value = (string) ($field['default'] ?? '');
            }

            if (!array_key_exists($value, $options)) {
                $errors[] = $field['label'] . ' has an invalid selection.';
                $value = (string) ($field['default'] ?? '');
            }
        }

        if ($type === 'email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $errors[] = $field['label'] . ' must be a valid email address.';
        }

        if ($type === 'number' && $value !== '' && !ctype_digit($value)) {
            $errors[] = $field['label'] . ' must be a whole number.';
        }

        if ($key === 'email.smtp_port' && $value !== '') {
            $port = (int) $value;

            if ($port < 1 || $port > 65535) {
                $errors[] = 'SMTP port must be between 1 and 65535.';
            }
        }

        if (in_array($key, ['workflow.signoff_image_custom_width', 'workflow.signoff_image_custom_height'], true) && $value !== '') {
            $size = (int) $value;

            if ($size < 40 || $size > 600) {
                $errors[] = $field['label'] . ' must be between 40 and 600 pixels.';
            }
        }

        if (in_array($key, ['exports.item_xlsx_thumbnail_custom_width', 'exports.item_xlsx_thumbnail_custom_height'], true) && $value !== '') {
            $size = (int) $value;

            if ($size < 40 || $size > 500) {
                $errors[] = $field['label'] . ' must be between 40 and 500 pixels.';
            }
        }

        if ($key === 'ocr.max_pdf_pages' && $value !== '') {
            $pageCount = (int) $value;

            if ($pageCount < 1 || $pageCount > 20) {
                $errors[] = 'Max PDF pages per file must be between 1 and 20.';
            }
        }

        if ($key === 'ocr.min_confidence' && $value !== '') {
            $confidence = (int) $value;

            if ($confidence < 1 || $confidence > 95) {
                $errors[] = 'Minimum confidence percent must be between 1 and 95.';
            }
        }

        $payload[$key] = $value;
    }

    return [$payload, $errors, $skipped];
}

function handle_dashboard_page(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('dashboard.view');

    if (Auth::isStaff()) {
        $currentUser = Auth::user();
        $staffCards = function_exists('staff_dashboard_handover_cards') && $currentUser
            ? staff_dashboard_handover_cards((int) $currentUser['id'])
            : [];

        View::render('dashboard', [
            'title' => site_setting('page.dashboard', 'Dashboard'),
            'isStaffDashboard' => true,
            'staffCards' => $staffCards,
            'dashboardNotifications' => function_exists('latest_notifications_for_user') && $currentUser
                ? latest_notifications_for_user((int) $currentUser['id'], 5)
                : [],
            'metrics' => [],
            'filters' => ['storage_id' => null, 'date_from' => '', 'date_to' => ''],
            'filterLabels' => ['storage' => '', 'date' => '', 'trend' => ''],
            'selectedStorage' => null,
            'storages' => [],
            'recentActivity' => [],
            'topUsage' => [],
            'lowStockItems' => [],
            'usageTrend' => [],
            'storageValueBreakdown' => [],
            'workflowSnapshot' => [
                'open_requests' => 0,
                'open_handovers' => 0,
                'recent_requests' => [],
                'recent_handovers' => [],
            ],
            'operationalSnapshot' => [
                'open_stocktakes' => 0,
                'pending_stocktake_approvals' => 0,
                'reorder_lines' => 0,
                'reorder_value' => 0,
                'recent_stocktakes' => [],
            ],
        ]);
        return;
    }

    $filters = dashboard_filters();
    $selectedStorage = selected_dashboard_storage($filters['storage_id']);

    if (!$selectedStorage) {
        $filters['storage_id'] = null;
    }

    [$movementWhere, $movementParams] = dashboard_movement_scope($filters);

    if ($selectedStorage) {
        $storageParams = ['storage_id' => (int) $selectedStorage['id']];
        $metrics = [
            'items_total' => (int) Database::scalar(
                'SELECT COUNT(*)
                 FROM item_storage_balances balances
                 INNER JOIN items i ON i.id = balances.item_id
                 WHERE balances.storage_id = :storage_id
                   AND i.is_active = 1',
                $storageParams
            ),
            'storages_total' => 1,
            'warehouses_total' => $selectedStorage['storage_type'] === 'warehouse' ? 1 : 0,
            'units_total' => (float) Database::scalar(
                'SELECT COALESCE(SUM(balances.quantity), 0)
                 FROM item_storage_balances balances
                 INNER JOIN items i ON i.id = balances.item_id
                 WHERE balances.storage_id = :storage_id
                   AND i.is_active = 1',
                $storageParams
            ),
            'low_stock' => (int) Database::scalar(
                'SELECT COUNT(*)
                 FROM item_storage_balances balances
                 INNER JOIN items i ON i.id = balances.item_id
                 WHERE balances.storage_id = :storage_id
                   AND i.is_active = 1
                   AND balances.quantity <= i.reorder_level',
                $storageParams
            ),
            'inventory_value' => (float) Database::scalar(
                'SELECT COALESCE(SUM(balances.quantity * i.cost_per_unit), 0)
                 FROM item_storage_balances balances
                 INNER JOIN items i ON i.id = balances.item_id
                 WHERE balances.storage_id = :storage_id
                   AND i.is_active = 1',
                $storageParams
            ),
            'used_last_30' => (float) Database::scalar(
                "SELECT COALESCE(SUM(m.movement_quantity), 0)
                 FROM inventory_movements m
                 INNER JOIN items i ON i.id = m.item_id
                 {$movementWhere}
                   AND m.movement_type = 'usage'",
                $movementParams
            ),
        ];
    } else {
        $metrics = [
            'items_total' => (int) Database::scalar('SELECT COUNT(*) FROM items WHERE is_active = 1'),
            'storages_total' => (int) Database::scalar('SELECT COUNT(*) FROM storages WHERE is_active = 1 AND is_system = 0'),
            'warehouses_total' => (int) Database::scalar('SELECT COUNT(*) FROM storages WHERE is_active = 1 AND is_system = 0 AND storage_type = "warehouse"'),
            'units_total' => (float) Database::scalar('SELECT COALESCE(SUM(current_quantity), 0) FROM items WHERE is_active = 1'),
            'low_stock' => (int) Database::scalar('SELECT COUNT(*) FROM items WHERE is_active = 1 AND current_quantity <= reorder_level'),
            'inventory_value' => (float) Database::scalar('SELECT COALESCE(SUM(current_quantity * cost_per_unit), 0) FROM items WHERE is_active = 1'),
            'used_last_30' => (float) Database::scalar(
                "SELECT COALESCE(SUM(m.movement_quantity), 0)
                 FROM inventory_movements m
                 INNER JOIN items i ON i.id = m.item_id
                 {$movementWhere}
                   AND m.movement_type = 'usage'",
                $movementParams
            ),
        ];
    }

    $recentActivity = Database::fetchAll(
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
         INNER JOIN items i ON i.id = m.item_id
         LEFT JOIN storages source_storage ON source_storage.id = m.source_storage_id
         LEFT JOIN storages destination_storage ON destination_storage.id = m.destination_storage_id
         LEFT JOIN users u ON u.id = m.performed_by
         {$movementWhere}
         ORDER BY m.used_at DESC, m.id DESC
         LIMIT 10",
        $movementParams
    );

    $topUsage = Database::fetchAll(
        "SELECT i.id,
                i.name,
                i.unit,
                SUM(m.movement_quantity) AS total_used,
                (
                    SELECT COUNT(*)
                    FROM item_storage_balances balances
                    WHERE balances.item_id = i.id
                ) AS location_count
         FROM inventory_movements m
         INNER JOIN items i ON i.id = m.item_id
         {$movementWhere}
           AND m.movement_type = 'usage'
         GROUP BY i.id, i.name, i.unit
         ORDER BY total_used DESC
         LIMIT 5",
        $movementParams
    );

    if ($selectedStorage) {
        $lowStockItems = Database::fetchAll(
            'SELECT i.id,
                    i.name,
                    i.sku,
                    i.unit,
                    balances.quantity AS current_quantity,
                    i.reorder_level,
                    1 AS location_count
             FROM item_storage_balances balances
             INNER JOIN items i ON i.id = balances.item_id
             WHERE balances.storage_id = :storage_id
               AND i.is_active = 1
               AND balances.quantity <= i.reorder_level
             ORDER BY balances.quantity ASC, i.name ASC
             LIMIT 8',
            ['storage_id' => (int) $selectedStorage['id']]
        );
    } else {
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
                    ) AS location_count
             FROM items i
             WHERE i.is_active = 1 AND i.current_quantity <= i.reorder_level
             ORDER BY i.current_quantity ASC, i.name ASC
             LIMIT 8'
        );
    }

    $usageTrend = dashboard_usage_trend($filters, 7);
    $storageValueBreakdown = dashboard_storage_value_breakdown($filters, 6);
    $filterLabels = dashboard_filter_labels($filters, $selectedStorage);
    $workflowSnapshot = function_exists('workflow_dashboard_snapshot')
        ? workflow_dashboard_snapshot($selectedStorage ? (int) $selectedStorage['id'] : null)
        : [
            'open_requests' => 0,
            'open_handovers' => 0,
            'recent_requests' => [],
            'recent_handovers' => [],
        ];
    $dashboardNotifications = function_exists('latest_notifications_for_user') && Auth::check()
        ? latest_notifications_for_user((int) (Auth::user()['id'] ?? 0), 5)
        : [];
    $operationalSnapshot = function_exists('operational_dashboard_snapshot')
        ? operational_dashboard_snapshot($selectedStorage ? (int) $selectedStorage['id'] : null)
        : [
            'open_stocktakes' => 0,
            'pending_stocktake_approvals' => 0,
            'reorder_lines' => 0,
            'reorder_value' => 0,
            'recent_stocktakes' => [],
        ];

    View::render('dashboard', [
        'title' => site_setting('page.dashboard', 'Dashboard'),
        'metrics' => $metrics,
        'filters' => $filters,
        'filterLabels' => $filterLabels,
        'selectedStorage' => $selectedStorage,
        'storages' => all_storages_for_select($filters['storage_id']),
        'recentActivity' => $recentActivity,
        'topUsage' => $topUsage,
        'lowStockItems' => $lowStockItems,
        'usageTrend' => $usageTrend,
        'storageValueBreakdown' => $storageValueBreakdown,
        'workflowSnapshot' => $workflowSnapshot,
        'operationalSnapshot' => $operationalSnapshot,
        'dashboardNotifications' => $dashboardNotifications,
    ]);
}

function handle_items_index(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('items.view');

    $filters = item_filters();
    [$where, $params] = build_item_where($filters);
    $storages = all_storages_for_select($filters['storage_id']);
    $selectedStorage = null;

    if ($filters['storage_id']) {
        foreach ($storages as $storage) {
            if ((int) $storage['id'] === (int) $filters['storage_id']) {
                $selectedStorage = $storage;
                break;
            }
        }
    }

    $items = Database::fetchAll(
        "SELECT i.*,
                default_storage.name AS default_storage_name,
                (
                    SELECT COUNT(*)
                    FROM item_storage_balances balances
                    WHERE balances.item_id = i.id
                ) AS location_count,
                (
                    SELECT GROUP_CONCAT(storage.name ORDER BY balances.quantity DESC, storage.name ASC SEPARATOR ', ')
                    FROM item_storage_balances balances
                    INNER JOIN storages storage ON storage.id = balances.storage_id
                    WHERE balances.item_id = i.id
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
        'title' => site_setting('page.items', 'Items'),
        'items' => $items,
        'filters' => $filters,
        'counts' => $counts,
        'storages' => $storages,
        'selectedStorage' => $selectedStorage,
    ]);
}

function handle_items_create_page(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('items.create');
    $copySource = requested_item_copy_source();

    View::render('items/form', [
        'title' => 'Create Item',
        'mode' => 'create',
        'item' => default_item_payload($copySource),
        'copySource' => $copySource,
        'storages' => all_storages_for_select(),
    ]);
}

function handle_items_create_submit(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('items.create');
    verify_csrf();

    $user = Auth::user();
    $copySource = requested_item_copy_source();
    $useExistingItem = input('use_existing_item') === '1';
    $selectedUnit = trim((string) input('unit', 'pcs'));
    $customUnit = trim((string) input('custom_unit'));
    $storageId = normalize_storage_selection(input('storage_id'));
    $imageUpload = normalize_item_upload(['image_path' => null], trim((string) input('name')));
    $payload = [
        'name' => trim((string) input('name')),
        'sku' => strtoupper(trim((string) input('sku'))),
        'barcode' => normalize_item_barcode(input('barcode')),
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
        $payload + [
            'copy_item_id' => $copySource ? (string) $copySource['id'] : '',
            'use_existing_item' => $useExistingItem ? '1' : '0',
        ]
    ));

    $errors = [];
    $existingItem = active_item_by_sku($payload['sku']);

    if ($payload['name'] === '') {
        $errors[] = 'Item name is required.';
    }

    if ($payload['sku'] === '') {
        $errors[] = 'SKU is required.';
    }

    if (item_barcodes_required() && $payload['barcode'] === '' && !($existingItem !== null && $useExistingItem)) {
        $errors[] = 'Barcode is required by the current inventory settings.';
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

    if ($existingItem !== null && $useExistingItem) {
        if ($storageId === null) {
            $errors[] = 'That SKU already exists. Pick a storage. Use quantity 0 if you only want to assign the item there.';
        }
    } elseif ($payload['current_quantity'] > 0 && $storageId === null) {
        $errors[] = 'Create an active location first, or set initial quantity to 0.';
    }

    if ($existingItem !== null && !$useExistingItem) {
        $errors[] = 'That SKU already exists. Leave "add stock to the existing item" on, or change the SKU.';
    }

    if ($existingItem !== null && $useExistingItem && $payload['barcode'] !== '') {
        $existingBarcode = normalize_item_barcode($existingItem['barcode'] ?? '');

        if ($existingBarcode !== '' && $existingBarcode !== $payload['barcode']) {
            $errors[] = 'That SKU already has a different barcode. Edit the existing item directly if the barcode changed.';
        }
    }

    if ($payload['barcode'] !== '' && active_item_barcode_exists($payload['barcode'], $existingItem ? (int) $existingItem['id'] : null)) {
        $errors[] = 'An active item already uses this barcode. Open that item instead of creating a duplicate.';
    }

    if ($errors !== []) {
        flash_errors($errors);
        redirect('/items/create');
    }

    if ($existingItem !== null && $useExistingItem) {
        try {
            if ($payload['barcode'] !== '' && normalize_item_barcode($existingItem['barcode'] ?? '') === '') {
                Database::execute(
                    'UPDATE items SET barcode = :barcode, updated_by = :updated_by, updated_at = NOW() WHERE id = :id',
                    [
                        'barcode' => $payload['barcode'],
                        'updated_by' => (int) $user['id'],
                        'id' => (int) $existingItem['id'],
                    ]
                );
            }

            if ($payload['current_quantity'] > 0) {
                $restockNote = trim($payload['notes']);

                if ($copySource !== null) {
                    $restockNote = trim($restockNote . ($restockNote !== '' ? ' ' : '') . 'Created from copied item setup.');
                }

                if ($restockNote === '') {
                    $restockNote = 'Stock added from the create item form.';
                }

                apply_inventory_movement(
                    $existingItem,
                    'restock',
                    $payload['current_quantity'],
                    null,
                    (int) $storageId,
                    date('Y-m-d H:i:s'),
                    'SKU-REUSE',
                    $restockNote,
                    (int) $user['id']
                );
            } else {
                assign_item_to_storage((int) $existingItem['id'], (int) $storageId);
                sync_item_inventory_snapshot((int) $existingItem['id'], (int) $user['id']);
            }
        } catch (Throwable $exception) {
            flash('danger', $exception->getMessage());
            redirect('/items/create');
        }

        consume_old_input();
        flash('success', $payload['current_quantity'] > 0
            ? 'Stock added to the existing item for SKU ' . $existingItem['sku'] . '.'
            : 'The existing item for SKU ' . $existingItem['sku'] . ' is now assigned to that storage with 0 stock.'
        );
        flash('warning', 'The existing item stayed the source of truth. Edit it directly if you need to change its details or image.');
        redirect('/items/' . $existingItem['id']);
    }

    $pdo = Database::connection();
    $pdo->beginTransaction();
    $storedImagePath = null;
    $copiedImagePath = null;

    try {
        Database::execute(
            'INSERT INTO items (name, sku, barcode, category, storage_id, unit, current_quantity, reorder_level, cost_per_unit, image_path, notes, is_active, created_by, updated_by, created_at, updated_at)
             VALUES (:name, :sku, :barcode, :category, :storage_id, :unit, :current_quantity, :reorder_level, :cost_per_unit, :image_path, :notes, 1, :created_by, :updated_by, NOW(), NOW())',
            [
                'name' => $payload['name'],
                'sku' => $payload['sku'],
                'barcode' => $payload['barcode'] !== '' ? $payload['barcode'] : null,
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
        } elseif ($copySource !== null && !empty($copySource['image_path'])) {
            $copiedImagePath = duplicate_item_image((string) $copySource['image_path'], $payload['name']);

            if ($copiedImagePath !== null) {
                Database::execute(
                    'UPDATE items SET image_path = :image_path, updated_at = NOW() WHERE id = :id',
                    [
                        'image_path' => $copiedImagePath,
                        'id' => $itemId,
                    ]
                );
            }
        }

        if ($storageId !== null) {
            persist_item_storage_balance($itemId, (int) $storageId, $payload['current_quantity']);
        }

        if ($payload['current_quantity'] > 0) {
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
        if ($storedImagePath !== null) {
            register_item_image_asset($itemId, $storedImagePath, $payload['name'], (int) $user['id']);
        } elseif ($copiedImagePath !== null) {
            register_item_image_asset($itemId, $copiedImagePath, $payload['name'], (int) $user['id']);
        }
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

        if ($copiedImagePath !== null) {
            delete_item_image($copiedImagePath);
        }

        flash('danger', $exception->getMessage());
        redirect('/items/create');
    }
}

function handle_items_show(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('items.view');

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
    $packagePresets = item_package_presets((int) $item['id']);

    View::render('items/show', [
        'title' => $item['name'],
        'item' => $item,
        'history' => $history,
        'historyMetrics' => $historyMetrics,
        'balances' => $balances,
        'packagePresets' => $packagePresets,
        'purchaseHistory' => function_exists('purchase_history_for_item') ? purchase_history_for_item((int) $item['id']) : [],
        'storages' => all_storages_for_select($item['storage_id'] ? (int) $item['storage_id'] : null),
        'movementTypeOptions' => movement_type_options_for_user(),
    ]);
}

function handle_items_edit_page(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('items.edit');

    $item = find_item_or_abort((int) $params['id']);
    $unitState = item_unit_form_state((string) $item['unit']);

    View::render('items/form', [
        'title' => 'Edit ' . $item['name'],
        'mode' => 'edit',
        'item' => array_merge([
            'name' => old('name', $item['name']),
            'sku' => old('sku', $item['sku']),
            'barcode' => old('barcode', $item['barcode'] ?? ''),
            'category' => old('category', $item['category']),
            'storage_id' => old('storage_id', $item['storage_id']),
            'unit' => old('unit', $unitState['unit']),
            'custom_unit' => old('custom_unit', $unitState['custom_unit']),
            'reorder_level' => old('reorder_level', format_quantity($item['reorder_level'])),
            'cost_per_unit' => old('cost_per_unit', format_quantity($item['cost_per_unit'])),
            'current_quantity' => format_quantity($item['current_quantity']),
            'image_path' => $item['image_path'],
            'notes' => old('notes', $item['notes']),
            'is_active' => (int) $item['is_active'],
            'id' => $item['id'],
        ]),
        'copySource' => null,
        'storages' => all_storages_for_select($item['storage_id'] ? (int) $item['storage_id'] : null),
    ]);
}

function handle_items_edit_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('items.edit');
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
        'barcode' => normalize_item_barcode(input('barcode')),
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

    if (item_barcodes_required() && $payload['barcode'] === '') {
        $errors[] = 'Barcode is required by the current inventory settings.';
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

    if ($payload['barcode'] !== '' && active_item_barcode_exists($payload['barcode'], (int) $item['id'])) {
        $errors[] = 'An active item already uses this barcode.';
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
                 barcode = :barcode,
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
                'barcode' => $payload['barcode'] !== '' ? $payload['barcode'] : null,
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

    if ($storedImagePath !== null) {
        register_item_image_asset((int) $item['id'], $storedImagePath, $payload['name'], (int) $user['id']);
    }

    consume_old_input();
    flash('success', 'Item updated.');
    redirect('/items/' . $item['id']);
}

function handle_item_package_preset_save_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('items.edit');
    verify_csrf();

    $item = find_item_or_abort((int) $params['id']);
    $user = Auth::user();
    $presetId = normalize_entity_id(input('preset_id'));
    $label = normalize_package_preset_label(input('label'));
    $piecesPerUnit = quantity_value(input('pieces_per_unit'));
    $isDefault = input('is_default') === '1';
    $errors = [];

    if ($label === '') {
        $errors[] = 'Package label is required.';
    }

    if (!is_numeric_value(input('pieces_per_unit')) || $piecesPerUnit <= 0) {
        $errors[] = 'Pieces per package must be greater than zero.';
    }

    if ($presetId !== null && item_package_preset_record((int) $item['id'], $presetId) === null) {
        $errors[] = 'That package preset no longer exists.';
    }

    $duplicateParams = [
        'item_id' => (int) $item['id'],
        'label' => $label,
    ];
    $duplicateSql = 'SELECT id
         FROM item_package_presets
         WHERE item_id = :item_id
           AND LOWER(label) = LOWER(:label)';

    if ($presetId !== null) {
        $duplicateSql .= ' AND id != :preset_id';
        $duplicateParams['preset_id'] = $presetId;
    }

    $duplicateSql .= ' LIMIT 1';
    $duplicate = Database::fetch($duplicateSql, $duplicateParams);

    if ($duplicate !== null) {
        $errors[] = 'This item already has a package preset with that label.';
    }

    if ($errors !== []) {
        flash_errors($errors);
        redirect('/items/' . $item['id']);
    }

    try {
        Database::connection()->beginTransaction();

        if ($isDefault) {
            Database::execute(
                'UPDATE item_package_presets
                 SET is_default = 0,
                     updated_by = :updated_by,
                     updated_at = NOW()
                 WHERE item_id = :item_id',
                [
                    'item_id' => (int) $item['id'],
                    'updated_by' => (int) $user['id'],
                ]
            );
        }

        if ($presetId !== null) {
            Database::execute(
                'UPDATE item_package_presets
                 SET label = :label,
                     pieces_per_unit = :pieces_per_unit,
                     is_default = :is_default,
                     updated_by = :updated_by,
                     updated_at = NOW()
                 WHERE id = :id
                   AND item_id = :item_id',
                [
                    'label' => $label,
                    'pieces_per_unit' => $piecesPerUnit,
                    'is_default' => $isDefault ? 1 : 0,
                    'updated_by' => (int) $user['id'],
                    'id' => $presetId,
                    'item_id' => (int) $item['id'],
                ]
            );
        } else {
            $hasPresets = (int) Database::scalar(
                'SELECT COUNT(*) FROM item_package_presets WHERE item_id = :item_id',
                ['item_id' => (int) $item['id']]
            ) > 0;

            Database::execute(
                'INSERT INTO item_package_presets (
                    item_id,
                    label,
                    pieces_per_unit,
                    is_default,
                    created_by,
                    updated_by,
                    created_at,
                    updated_at
                 ) VALUES (
                    :item_id,
                    :label,
                    :pieces_per_unit,
                    :is_default,
                    :created_by,
                    :updated_by,
                    NOW(),
                    NOW()
                 )',
                [
                    'item_id' => (int) $item['id'],
                    'label' => $label,
                    'pieces_per_unit' => $piecesPerUnit,
                    'is_default' => ($isDefault || !$hasPresets) ? 1 : 0,
                    'created_by' => (int) $user['id'],
                    'updated_by' => (int) $user['id'],
                ]
            );
        }

        ensure_item_package_default((int) $item['id']);
        Database::connection()->commit();
    } catch (Throwable $exception) {
        if (Database::connection()->inTransaction()) {
            Database::connection()->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/items/' . $item['id']);
    }

    flash('success', 'Package preset saved.');
    redirect('/items/' . $item['id']);
}

function handle_item_package_preset_delete_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('items.edit');
    verify_csrf();

    $item = find_item_or_abort((int) $params['id']);
    $presetId = normalize_entity_id($params['preset_id'] ?? null);

    if ($presetId === null || item_package_preset_record((int) $item['id'], $presetId) === null) {
        flash('danger', 'That package preset no longer exists.');
        redirect('/items/' . $item['id']);
    }

    Database::execute(
        'DELETE FROM item_package_presets
         WHERE id = :id
           AND item_id = :item_id',
        [
            'id' => $presetId,
            'item_id' => (int) $item['id'],
        ]
    );

    ensure_item_package_default((int) $item['id']);
    flash('success', 'Package preset removed.');
    redirect('/items/' . $item['id']);
}

function handle_items_status_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('items.archive');
    verify_csrf();

    $item = find_item_or_abort((int) $params['id']);
    $user = Auth::user();
    $nextStatus = (int) $item['is_active'] === 1 ? 0 : 1;

    if ($nextStatus === 0 && item_has_location_assignments((int) $item['id'])) {
        flash('danger', 'This item is still assigned to one or more storages. Remove it from those storages first, then archive it.');
        redirect('/items/' . $item['id']);
    }

    if ($nextStatus === 1 && active_item_sku_exists((string) $item['sku'], (int) $item['id'])) {
        flash('danger', 'Recover failed. Another active item already uses SKU ' . $item['sku'] . '.');
        redirect('/items?status=archived');
    }

    if ($nextStatus === 1 && normalize_item_barcode($item['barcode'] ?? '') !== '' && active_item_barcode_exists((string) $item['barcode'], (int) $item['id'])) {
        flash('danger', 'Recover failed. Another active item already uses barcode ' . $item['barcode'] . '.');
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

    flash('success', $nextStatus ? 'Item recovered.' : 'Item archived.');
    redirect($nextStatus ? '/items' : '/items?status=archived');
}

function handle_item_location_remove_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('items.remove_from_storage');
    verify_csrf();

    $item = find_item_or_abort((int) $params['id']);
    $user = Auth::user();
    $storageId = normalize_entity_id($params['storage_id'] ?? null);
    $returnTo = trim((string) input('return_to', '/items/' . $item['id']));
    $fallbackPath = '/items/' . $item['id'];

    if ($storageId === null) {
        flash('danger', 'That storage is invalid.');
        redirect($fallbackPath);
    }

    $balance = item_storage_balance_record((int) $item['id'], $storageId);

    if ($balance === null) {
        flash('danger', 'This item is not assigned to that storage anymore.');
        redirect(starts_with($returnTo, '/') ? $returnTo : $fallbackPath);
    }

    try {
        if (round((float) $balance['quantity'], 2) > 0) {
            apply_inventory_movement(
                $item,
                'adjustment',
                -abs((float) $balance['quantity']),
                $storageId,
                null,
                date('Y-m-d H:i:s'),
                'REMOVE-LOCATION',
                'Removed item from ' . $balance['name'] . '. Other storages keep their balances.',
                (int) $user['id']
            );
        }

        Database::execute(
            'DELETE FROM item_storage_balances WHERE item_id = :item_id AND storage_id = :storage_id',
            [
                'item_id' => $item['id'],
                'storage_id' => $storageId,
            ]
        );

        sync_item_inventory_snapshot((int) $item['id'], (int) $user['id']);
    } catch (Throwable $exception) {
        flash('danger', $exception->getMessage());
        redirect(starts_with($returnTo, '/') ? $returnTo : $fallbackPath);
    }

    flash('success', 'Item removed from ' . $balance['name'] . '. Other storages were not touched.');
    redirect(starts_with($returnTo, '/') ? $returnTo : $fallbackPath);
}

function handle_item_movement_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requireLogin();
    verify_csrf();

    $item = find_item_or_abort((int) $params['id']);
    $user = Auth::user();
    $movementType = (string) input('movement_type');

    if (!can_create_movement_type($movementType)) {
        $message = movement_type_permission($movementType) === null
            ? 'Pick a valid movement type.'
            : 'You do not have permission to create that movement type.';

        if (request_wants_json()) {
            json_response([
                'message' => $message,
                'errors' => [$message],
            ], 403);
        }

        flash('danger', $message);
        redirect('/items/' . $item['id']);
    }

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
    Auth::requirePermission('movements.view');

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
        'title' => site_setting('page.movements', 'Movement Log'),
        'movements' => $movements,
        'filters' => $filters,
        'items' => all_items_for_select(),
        'storages' => all_storages_for_select($filters['storage_id']),
    ]);
}

function scan_item_payload(array $item): array
{
    $balances = array_map(static function (array $balance): array {
        return [
            'storage_id' => (int) $balance['storage_id'],
            'name' => (string) $balance['name'],
            'type' => storage_type_label((string) $balance['storage_type']),
            'quantity' => format_quantity($balance['quantity']),
            'quantity_raw' => (float) $balance['quantity'],
            'used' => format_quantity($balance['total_used']),
            'transferred_in' => format_quantity($balance['transferred_in']),
            'transferred_out' => format_quantity($balance['transferred_out']),
        ];
    }, item_storage_balances((int) $item['id']));

    $barcode = normalize_item_barcode($item['barcode'] ?? '');

    return [
        'id' => (int) $item['id'],
        'name' => (string) $item['name'],
        'sku' => (string) $item['sku'],
        'barcode' => $barcode,
        'scan_code' => item_scan_code($item),
        'category' => (string) ($item['category'] ?? ''),
        'unit' => (string) $item['unit'],
        'quantity' => format_quantity($item['current_quantity']),
        'quantity_raw' => (float) $item['current_quantity'],
        'cost_per_unit' => format_money($item['cost_per_unit']),
        'stock_value' => format_money(stock_value($item['current_quantity'], $item['cost_per_unit'])),
        'image_url' => item_image_url($item['image_path'] ?? null),
        'item_url' => url('/items/' . $item['id']),
        'label_url' => url('/labels?search=' . rawurlencode($barcode !== '' ? $barcode : (string) $item['sku'])),
        'movement_url' => url('/items/' . $item['id'] . '/movements'),
        'location_count' => (int) ($item['location_count'] ?? 0),
        'location_summary' => (string) ($item['location_summary'] ?? ''),
        'package_presets' => item_package_presets((int) $item['id']),
        'balances' => $balances,
    ];
}

function handle_scan_index(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('items.view');

    if (Auth::isStaff()) {
        abort(403, 'Staff dashboard is intentionally simplified. Scanner is for inventory operators.');
    }

    $scanMovementTypeOptions = movement_type_options_for_user(['usage', 'restock']);

    View::render('scan/index', [
        'title' => site_setting('page.scan', 'Scan Center'),
        'storages' => all_storages_for_select(),
        'canCreateMovement' => $scanMovementTypeOptions !== [],
        'scanMovementTypeOptions' => $scanMovementTypeOptions,
    ]);
}

function handle_scan_lookup(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('items.view');

    if (Auth::isStaff()) {
        json_response([
            'ok' => false,
            'message' => 'Scanner is not available for staff accounts.',
        ], 403);
    }

    $query = trim((string) query('q', ''));

    if ($query === '') {
        json_response([
            'ok' => false,
            'message' => 'Scan or type a barcode, SKU, or item name.',
        ], 422);
    }

    $query = mb_substr($query, 0, 120);
    $workflowTarget = workflow_reference_open_target($query);

    if ($workflowTarget !== null) {
        json_response([
            'ok' => true,
            'query' => workflow_reference_normalize($query),
            'count' => 0,
            'items' => [],
            'open_url' => $workflowTarget['url'],
            'open_reference' => $workflowTarget['reference'],
            'message' => 'Opening ' . $workflowTarget['reference'] . '.',
        ]);
    }

    $like = '%' . addcslashes($query, "\\%_") . '%';
    $exact = mb_strtolower($query);

    $items = Database::fetchAll(
        'SELECT i.*,
                default_storage.name AS default_storage_name,
                default_storage.storage_type AS default_storage_type,
                (
                    SELECT COUNT(*)
                    FROM item_storage_balances balances
                    WHERE balances.item_id = i.id
                ) AS location_count,
                (
                    SELECT GROUP_CONCAT(storage.name ORDER BY balances.quantity DESC, storage.name ASC SEPARATOR ", ")
                    FROM item_storage_balances balances
                    INNER JOIN storages storage ON storage.id = balances.storage_id
                    WHERE balances.item_id = i.id
                ) AS location_summary
         FROM items i
         LEFT JOIN storages default_storage ON default_storage.id = i.storage_id
         WHERE i.is_active = 1
           AND (
               LOWER(COALESCE(i.barcode, "")) = :exact_barcode
               OR LOWER(i.sku) = :exact_sku
               OR i.name LIKE :like_name
               OR i.sku LIKE :like_sku
               OR COALESCE(i.barcode, "") LIKE :like_barcode
               OR EXISTS (
                    SELECT 1
                    FROM item_storage_balances scan_balances
                    INNER JOIN storages scan_storage ON scan_storage.id = scan_balances.storage_id
                    WHERE scan_balances.item_id = i.id
                      AND scan_storage.name LIKE :like_storage
               )
           )
         ORDER BY CASE
                    WHEN LOWER(COALESCE(i.barcode, "")) = :order_barcode THEN 0
                    WHEN LOWER(i.sku) = :order_sku THEN 1
                    WHEN i.sku LIKE :order_sku_like THEN 2
                    ELSE 3
                  END,
                  i.name ASC
         LIMIT 8',
        [
            'exact_barcode' => $exact,
            'exact_sku' => $exact,
            'like_name' => $like,
            'like_sku' => $like,
            'like_barcode' => $like,
            'like_storage' => $like,
            'order_barcode' => $exact,
            'order_sku' => $exact,
            'order_sku_like' => $like,
        ]
    );

    json_response([
        'ok' => true,
        'query' => $query,
        'count' => count($items),
        'items' => array_map('scan_item_payload', $items),
    ]);
}

function report_preset_cards(): array
{
    $today = date('Y-m-d');
    $monthStart = date('Y-m-01');
    $monthEnd = date('Y-m-t');
    $last30Start = date('Y-m-d', strtotime('-30 days'));

    $groups = [
        'Inventory' => [
            [
                'title' => 'Item Catalog',
                'copy' => 'All active/deleted item records, SKU, barcode, unit, quantity, reorder level, value, and location summary.',
                'icon' => 'items',
                'permission' => 'items.export',
                'download_url' => url('/exports/items?status=all'),
                'source_url' => url('/items'),
                'badge' => 'Catalog',
            ],
            [
                'title' => 'Storage Value',
                'copy' => 'Each storage with every item inside it, remaining quantity, used quantity, and stock value.',
                'icon' => 'storages',
                'permission' => 'storages.export',
                'download_url' => url('/exports/storages?status=active'),
                'source_url' => url('/storages'),
                'badge' => 'Value',
            ],
            [
                'title' => 'Today Stock Activity',
                'copy' => 'All restock, usage, transfer, and adjustment movements recorded today.',
                'icon' => 'movements',
                'permission' => 'movements.export',
                'download_url' => url('/exports/movements?date_from=' . rawurlencode($today) . '&date_to=' . rawurlencode($today)),
                'source_url' => url('/movements?date_from=' . rawurlencode($today) . '&date_to=' . rawurlencode($today)),
                'badge' => 'Today',
            ],
            [
                'title' => 'Low Stock Reorder',
                'copy' => 'Items at or below reorder level with suggested refill quantity and estimated value.',
                'icon' => 'reorder',
                'permission' => 'reorder.export',
                'download_url' => url('/exports/reorder'),
                'source_url' => url('/reorder'),
                'badge' => 'Refill',
            ],
            [
                'title' => 'Printable Label Data',
                'copy' => 'Open the label page to print item or storage barcodes after filtering.',
                'icon' => 'labels',
                'permission' => 'labels.view',
                'download_url' => '',
                'source_url' => url('/labels'),
                'badge' => 'Print',
            ],
        ],
        'Workflow' => [
            [
                'title' => 'This Month Usage',
                'copy' => 'Movement history filtered to usage events for the current month.',
                'icon' => 'movements',
                'permission' => 'movements.export',
                'download_url' => url('/exports/movements?movement_type=usage&date_from=' . rawurlencode($monthStart) . '&date_to=' . rawurlencode($monthEnd)),
                'source_url' => url('/movements?movement_type=usage&date_from=' . rawurlencode($monthStart) . '&date_to=' . rawurlencode($monthEnd)),
                'badge' => 'Usage',
            ],
            [
                'title' => 'Last 30 Days Transfers',
                'copy' => 'All stock transfers between warehouses and storages over the last 30 days.',
                'icon' => 'movements',
                'permission' => 'movements.export',
                'download_url' => url('/exports/movements?movement_type=transfer&date_from=' . rawurlencode($last30Start) . '&date_to=' . rawurlencode($today)),
                'source_url' => url('/movements?movement_type=transfer&date_from=' . rawurlencode($last30Start) . '&date_to=' . rawurlencode($today)),
                'badge' => 'Transfer',
            ],
            [
                'title' => 'Open Requests',
                'copy' => 'Pending and in-progress item requests for approval, receiving, or completion review.',
                'icon' => 'requests',
                'permission' => 'requests.export',
                'download_url' => url('/exports/requests?status=all&date_from=' . rawurlencode($last30Start) . '&date_to=' . rawurlencode($today)),
                'source_url' => url('/requests?status=all'),
                'badge' => 'Requests',
            ],
            [
                'title' => 'Requests Needing Decisions',
                'copy' => 'Request approvals still waiting for an owner or assigned admin decision.',
                'icon' => 'requests',
                'permission' => 'requests.export',
                'download_url' => url('/exports/requests?status=pending&date_from=' . rawurlencode($last30Start) . '&date_to=' . rawurlencode($today)),
                'source_url' => url('/requests?status=pending'),
                'badge' => 'Approve',
            ],
            [
                'title' => 'Handover Closeouts',
                'copy' => 'Temporary item issues, used quantities, returned quantities, and closeout status.',
                'icon' => 'handover',
                'permission' => 'handovers.export',
                'download_url' => url('/exports/handovers?status=all&date_from=' . rawurlencode($last30Start) . '&date_to=' . rawurlencode($today)),
                'source_url' => url('/handovers?status=all'),
                'badge' => 'Handover',
            ],
            [
                'title' => 'Open Handover Proof Trail',
                'copy' => 'Handovers that are still requested, delivered, awaiting receipt, or waiting final approval.',
                'icon' => 'handover',
                'permission' => 'handovers.export',
                'download_url' => url('/exports/handovers?status=open&date_from=' . rawurlencode($last30Start) . '&date_to=' . rawurlencode($today)),
                'source_url' => url('/handovers?status=open'),
                'badge' => 'Open',
            ],
        ],
        'Finance And Suppliers' => [
            [
                'title' => 'Purchase Approval Queue',
                'copy' => 'Supplier purchases submitted for approval before stock can move.',
                'icon' => 'purchases',
                'permission' => 'purchases.export',
                'download_url' => url('/exports/purchases?status=pending_approval&date_from=' . rawurlencode($last30Start) . '&date_to=' . rawurlencode($today)),
                'source_url' => url('/purchases?status=pending_approval'),
                'badge' => 'Approve',
            ],
            [
                'title' => 'Purchase Receiving Queue',
                'copy' => 'Approved or receipt-review purchases that still need received quantities confirmed.',
                'icon' => 'purchases',
                'permission' => 'purchases.export',
                'download_url' => url('/exports/purchases?status=receipt_review&date_from=' . rawurlencode($last30Start) . '&date_to=' . rawurlencode($today)),
                'source_url' => url('/purchases?status=receipt_review'),
                'badge' => 'Receive',
            ],
            [
                'title' => 'Completed Purchases',
                'copy' => 'Supplier purchases that finished receiving and posted restock movements.',
                'icon' => 'purchases',
                'permission' => 'purchases.export',
                'download_url' => url('/exports/purchases?status=completed&date_from=' . rawurlencode($monthStart) . '&date_to=' . rawurlencode($monthEnd)),
                'source_url' => url('/purchases?status=completed'),
                'badge' => 'Purchases',
            ],
            [
                'title' => 'Supplier Directory',
                'copy' => 'Supplier type, phone, VAT, CR, authorized person, purchase totals, and status.',
                'icon' => 'supplier',
                'permission' => 'suppliers.export',
                'download_url' => url('/exports/suppliers?status=all'),
                'source_url' => url('/suppliers'),
                'badge' => 'Suppliers',
            ],
            [
                'title' => 'Protected Files',
                'copy' => 'Purchase documents, item images, proof files, uploaders, and linked workflow records.',
                'icon' => 'files',
                'permission' => 'files.export',
                'download_url' => url('/exports/files?status=active'),
                'source_url' => url('/files'),
                'badge' => 'Files',
            ],
        ],
        'Control' => [
            [
                'title' => 'Stocktake Variance',
                'copy' => 'Cycle count records with expected quantity, counted quantity, variance, and approver.',
                'icon' => 'stocktakes',
                'permission' => 'stocktakes.export',
                'download_url' => url('/exports/stocktakes?status=all'),
                'source_url' => url('/stocktakes'),
                'badge' => 'Counts',
            ],
            [
                'title' => 'Audit Trail',
                'copy' => 'Admin activity, entity changes, IP address, user, and metadata.',
                'icon' => 'audit',
                'permission' => 'audit.export',
                'download_url' => url('/exports/audit?date_from=' . rawurlencode($last30Start) . '&date_to=' . rawurlencode($today)),
                'source_url' => url('/audit-log'),
                'badge' => 'Audit',
            ],
            [
                'title' => 'Email Delivery',
                'copy' => 'Password reset, setup, test email, workflow alert delivery, failures, and suppressions.',
                'icon' => 'notification',
                'permission' => 'email_logs.export',
                'download_url' => url('/exports/email-logs?date_from=' . rawurlencode($last30Start) . '&date_to=' . rawurlencode($today)),
                'source_url' => url('/email-logs'),
                'badge' => 'Mailer',
            ],
            [
                'title' => 'Users And Permissions',
                'copy' => 'Active/deleted users, roles, positions, assigned owners, and permission counts.',
                'icon' => 'users',
                'permission' => 'users.export',
                'download_url' => url('/exports/users?status=all'),
                'source_url' => url('/users'),
                'badge' => 'Access',
            ],
        ],
    ];

    foreach ($groups as $groupName => $cards) {
        $groups[$groupName] = array_values(array_filter($cards, static function (array $card): bool {
            return Auth::hasPermission((string) $card['permission']);
        }));

        if ($groups[$groupName] === []) {
            unset($groups[$groupName]);
        }
    }

    return $groups;
}

function handle_reports_index(): void
{
    app_ready_or_redirect();

    if (Auth::isStaff() || !reports_can_access()) {
        abort(403, 'You do not have access to report presets.');
    }

    View::render('reports/index', [
        'title' => site_setting('page.reports', 'Reports'),
        'groups' => report_preset_cards(),
    ]);
}

function item_export_rows(array $filters): array
{
    [$where, $params] = build_item_where($filters);

    return Database::fetchAll(
        "SELECT i.*,
                default_storage.name AS default_storage_name,
                (
                    SELECT COUNT(*)
                    FROM item_storage_balances balances
                    WHERE balances.item_id = i.id
                ) AS location_count,
                (
                    SELECT GROUP_CONCAT(storage.name ORDER BY balances.quantity DESC, storage.name ASC SEPARATOR ', ')
                    FROM item_storage_balances balances
                    INNER JOIN storages storage ON storage.id = balances.storage_id
                    WHERE balances.item_id = i.id
                ) AS storage_summary,
                (SELECT MAX(m.used_at) FROM inventory_movements m WHERE m.item_id = i.id) AS last_movement_at
         FROM items i
         LEFT JOIN storages default_storage ON default_storage.id = i.storage_id
         {$where}
         ORDER BY i.name ASC",
        $params
    );
}

function handle_export_items(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('items.export');

    $items = item_export_rows(item_filters());

    $rows = array_map(static function (array $item): array {
        return [
            $item['name'],
            $item['sku'],
            $item['barcode'] ?: '',
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
        'Barcode',
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

function item_export_xlsx_sheet_xml(array $items, array $images, array $imageSize): string
{
    $includeBarcodeImages = excel_export_barcode_images_enabled();
    $headers = [
        'Image',
        'Name',
        'SKU',
        'Barcode Value',
        'Scan Code',
    ];

    if ($includeBarcodeImages) {
        $headers[] = 'Barcode Image';
    }

    $headers = array_merge($headers, [
        'Category',
        'Locations',
        'Default Location',
        'Unit',
        'Current Quantity',
        'Reorder Level',
        'Cost Per Unit',
        'Status',
        'Last Movement',
        'Notes',
    ]);

    $sheetRows = [];
    $headerCells = '';

    foreach ($headers as $index => $header) {
        $headerCells .= workflow_xlsx_cell(workflow_xlsx_column($index + 1) . '1', $header, 2);
    }

    $imageWidth = max(40, min(500, (int) ($imageSize['width'] ?? 120)));
    $imageHeight = max(40, min(400, (int) ($imageSize['height'] ?? 90)));
    $imageColumnWidth = max(14, min(58, (int) ceil(($imageWidth / 7) + 6)));
    $imageRowHeight = max(54, min(420, $imageHeight + 12));

    $sheetRows[] = '<row r="1" ht="24" customHeight="1">' . $headerCells . '</row>';
    $rowNumber = 2;

    foreach ($items as $item) {
        $scanCode = item_scan_code($item);
        $rowValues = [
            workflow_xlsx_has_image_at($images, $rowNumber, 0) ? '' : 'No image',
            (string) $item['name'],
            (string) $item['sku'],
            normalize_item_barcode($item['barcode'] ?? '') !== '' ? normalize_item_barcode($item['barcode'] ?? '') : 'Not set',
            $scanCode,
        ];

        if ($includeBarcodeImages) {
            $rowValues[] = workflow_xlsx_has_image_at($images, $rowNumber, 5) ? '' : ($scanCode !== '' ? 'Barcode image unavailable' : 'No scan code');
        }

        $rowValues = array_merge($rowValues, [
            (string) ($item['category'] ?: ''),
            (string) ($item['storage_summary'] ?: ''),
            (string) ($item['default_storage_name'] ?: ''),
            (string) $item['unit'],
            format_quantity($item['current_quantity']),
            format_quantity($item['reorder_level']),
            format_money($item['cost_per_unit']),
            (int) $item['is_active'] === 1 ? 'Active' : 'Deleted',
            (string) ($item['last_movement_at'] ?: ''),
            (string) ($item['notes'] ?: ''),
        ]);

        $cells = '';

        foreach ($rowValues as $index => $value) {
            $cells .= workflow_xlsx_cell(workflow_xlsx_column($index + 1) . $rowNumber, (string) $value, 3);
        }

        $sheetRows[] = '<row r="' . $rowNumber . '" ht="' . $imageRowHeight . '" customHeight="1">' . $cells . '</row>';
        $rowNumber++;
    }

    $columnWidths = [
        $imageColumnWidth,
        26,
        18,
        18,
        22,
    ];

    if ($includeBarcodeImages) {
        $columnWidths[] = 32;
    }

    $columnWidths = array_merge($columnWidths, [
        18,
        28,
        28,
        10,
        16,
        16,
        18,
        18,
        18,
        34,
    ]);

    $columnXml = '';

    foreach ($columnWidths as $index => $width) {
        $columnNumber = $index + 1;
        $columnXml .= '<col min="' . $columnNumber . '" max="' . $columnNumber . '" width="' . $width . '" customWidth="1"/>';
    }

    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
    $xml .= '<sheetViews><sheetView workbookViewId="0" showGridLines="0"/></sheetViews>';
    $xml .= '<cols>' . $columnXml . '</cols>';
    $xml .= '<sheetData>' . implode('', $sheetRows) . '</sheetData>';
    $xml .= '<pageMargins left="0.35" right="0.35" top="0.5" bottom="0.5" header="0.3" footer="0.3"/>';

    if ($images) {
        $xml .= '<drawing r:id="rId1"/>';
    }

    $xml .= '</worksheet>';

    return $xml;
}

function item_export_xlsx_payload(array $items): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive is required to generate Excel item exports.');
    }

    $images = [];
    $imageSize = item_xlsx_thumbnail_export_size();
    $includeBarcodeImages = excel_export_barcode_images_enabled();

    foreach ($items as $index => $item) {
        $image = workflow_xlsx_image_asset($item['image_path'] ?? null, $imageSize);
        $rowNumber = 2 + $index;

        if ($image === null) {
            $image = null;
        } else {
            $image['row'] = $rowNumber;
            $image['col'] = 0;
            $image['name'] = 'Item Thumbnail ' . ($index + 1);
            $images[] = $image;
        }

        if ($includeBarcodeImages) {
            $scanCode = item_scan_code($item);
            $barcodeImage = $scanCode !== '' ? workflow_code39_png_asset($scanCode, 220, 52) : null;

            if ($barcodeImage !== null) {
                $barcodeImage['row'] = $rowNumber;
                $barcodeImage['col'] = 5;
                $barcodeImage['name'] = 'Item Barcode ' . ($index + 1);
                $images[] = $barcodeImage;
            }
        }
    }

    $tmp = tempnam(sys_get_temp_dir(), 'items-xlsx-');

    if ($tmp === false) {
        throw new RuntimeException('Could not create temporary Excel file.');
    }

    $zip = new ZipArchive();

    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($tmp);
        throw new RuntimeException('Could not open temporary Excel archive.');
    }

    $zip->addFromString('[Content_Types].xml', workflow_xlsx_content_types_xml(array_values($images)));
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>');
    $zip->addFromString('docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes"><Application>Inventory KONA</Application></Properties>');
    $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>Item Export</dc:title><dc:creator>Inventory KONA</dc:creator><cp:lastModifiedBy>Inventory KONA</cp:lastModifiedBy><dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('c') . '</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">' . gmdate('c') . '</dcterms:modified></cp:coreProperties>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Items" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $zip->addFromString('xl/styles.xml', workflow_xlsx_styles_xml());
    $zip->addFromString('xl/worksheets/sheet1.xml', item_export_xlsx_sheet_xml($items, $images, $imageSize));

    if ($images) {
        $zip->addFromString('xl/worksheets/_rels/sheet1.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/></Relationships>');
        $zip->addFromString('xl/drawings/drawing1.xml', workflow_xlsx_drawing_xml(array_values($images)));
        $zip->addFromString('xl/drawings/_rels/drawing1.xml.rels', workflow_xlsx_drawing_rels_xml(array_values($images)));

        foreach (array_values($images) as $index => $image) {
            $zip->addFromString('xl/media/image' . ($index + 1) . '.' . $image['extension'], (string) $image['bytes']);
        }
    }

    $zip->close();
    $bytes = file_get_contents($tmp);
    @unlink($tmp);

    if ($bytes === false || $bytes === '') {
        throw new RuntimeException('Could not build Excel item export.');
    }

    return $bytes;
}

function handle_export_items_xlsx(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('items.export');

    if (!item_xlsx_thumbnail_export_enabled()) {
        abort(403, 'Item Excel thumbnail export is disabled in Website Control.');
    }

    try {
        export_xlsx('items-export-' . date('Ymd-His') . '.xlsx', item_export_xlsx_payload(item_export_rows(item_filters())));
    } catch (Throwable $exception) {
        abort(500, 'Could not export item thumbnails. ' . $exception->getMessage());
    }
}

function handle_export_movements(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('movements.export');

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
    Auth::requirePermission('storages.export');

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
            (int) $storage['assigned_item_count'],
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
        'Assigned Items',
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

function storage_export_xlsx_sheet_xml(array $rows, array $images, array $imageSize): string
{
    $includeBarcodeImages = excel_export_barcode_images_enabled();
    $headers = [
        'Storage Name',
        'Storage Type',
        'Storage Status',
        'Assigned Items',
        'Remaining Quantity',
        'Storage Total Value',
        'Used Quantity',
        'Transferred In',
        'Transferred Out',
        'Storage Notes',
        'Storage Updated At',
        'Row Type',
        'Item Image',
        'Item Name',
        'Item SKU',
        'Barcode Value',
        'Scan Code',
    ];

    if ($includeBarcodeImages) {
        $headers[] = 'Barcode Image';
    }

    $headers = array_merge($headers, [
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
    ]);

    $imageWidth = max(40, min(500, (int) ($imageSize['width'] ?? 120)));
    $imageHeight = max(40, min(400, (int) ($imageSize['height'] ?? 90)));
    $imageColumnWidth = max(14, min(58, (int) ceil(($imageWidth / 7) + 6)));
    $imageRowHeight = max(54, min(420, $imageHeight + 12));
    $sheetRows = [];
    $headerCells = '';

    foreach ($headers as $index => $header) {
        $headerCells .= workflow_xlsx_cell(workflow_xlsx_column($index + 1) . '1', $header, 2);
    }

    $sheetRows[] = '<row r="1" ht="24" customHeight="1">' . $headerCells . '</row>';

    foreach ($rows as $rowNumber => $row) {
        $excelRow = $rowNumber + 2;
        $rowValues = [
            (string) ($row['storage_name'] ?? ''),
            (string) ($row['storage_type'] ?? ''),
            (string) ($row['storage_status'] ?? ''),
            (string) ($row['assigned_items'] ?? ''),
            (string) ($row['storage_quantity'] ?? ''),
            (string) ($row['storage_value'] ?? ''),
            (string) ($row['storage_used'] ?? ''),
            (string) ($row['storage_transferred_in'] ?? ''),
            (string) ($row['storage_transferred_out'] ?? ''),
            (string) ($row['storage_notes'] ?? ''),
            (string) ($row['storage_updated_at'] ?? ''),
            (string) ($row['row_type'] ?? ''),
            workflow_xlsx_has_image_at($images, $excelRow, 12) ? '' : ((string) ($row['row_type'] ?? '') === 'Item' ? 'No image' : ''),
            (string) ($row['item_name'] ?? ''),
            (string) ($row['item_sku'] ?? ''),
            (string) ($row['barcode_value'] ?? ''),
            (string) ($row['scan_code'] ?? ''),
        ];

        if ($includeBarcodeImages) {
            $rowValues[] = workflow_xlsx_has_image_at($images, $excelRow, 17) ? '' : ((string) ($row['scan_code'] ?? '') !== '' ? 'Barcode image unavailable' : '');
        }

        $rowValues = array_merge($rowValues, [
            (string) ($row['item_category'] ?? ''),
            (string) ($row['item_quantity'] ?? ''),
            (string) ($row['item_unit'] ?? ''),
            (string) ($row['item_cost_per_unit'] ?? ''),
            (string) ($row['item_stock_value'] ?? ''),
            (string) ($row['item_reorder_level'] ?? ''),
            (string) ($row['item_used_quantity'] ?? ''),
            (string) ($row['item_transferred_in'] ?? ''),
            (string) ($row['item_transferred_out'] ?? ''),
            (string) ($row['item_status'] ?? ''),
            (string) ($row['item_last_activity'] ?? ''),
            (string) ($row['item_notes'] ?? ''),
        ]);

        $cells = '';

        foreach ($rowValues as $index => $value) {
            $cells .= workflow_xlsx_cell(workflow_xlsx_column($index + 1) . $excelRow, (string) $value, 3);
        }

        $style = (string) ($row['row_type'] ?? '') === 'Storage' ? 4 : 3;
        $height = (string) ($row['row_type'] ?? '') === 'Item' ? $imageRowHeight : 26;

        if ($style === 4) {
            $cells = '';
            foreach ($rowValues as $index => $value) {
                $cells .= workflow_xlsx_cell(workflow_xlsx_column($index + 1) . $excelRow, (string) $value, $style);
            }
        }

        $sheetRows[] = '<row r="' . $excelRow . '" ht="' . $height . '" customHeight="1">' . $cells . '</row>';
    }

    $columnWidths = [
        24,
        16,
        16,
        14,
        18,
        18,
        16,
        16,
        16,
        28,
        20,
        12,
        $imageColumnWidth,
        24,
        18,
        18,
        22,
    ];

    if ($includeBarcodeImages) {
        $columnWidths[] = 32;
    }

    $columnWidths = array_merge($columnWidths, [
        18,
        16,
        10,
        18,
        18,
        18,
        18,
        18,
        18,
        16,
        20,
        34,
    ]);

    $columnXml = '';
    foreach ($columnWidths as $index => $width) {
        $columnNumber = $index + 1;
        $columnXml .= '<col min="' . $columnNumber . '" max="' . $columnNumber . '" width="' . $width . '" customWidth="1"/>';
    }

    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
    $xml .= '<sheetViews><sheetView workbookViewId="0" showGridLines="0"/></sheetViews>';
    $xml .= '<cols>' . $columnXml . '</cols>';
    $xml .= '<sheetData>' . implode('', $sheetRows) . '</sheetData>';
    $xml .= '<pageMargins left="0.35" right="0.35" top="0.5" bottom="0.5" header="0.3" footer="0.3"/>';

    if ($images) {
        $xml .= '<drawing r:id="rId1"/>';
    }

    $xml .= '</worksheet>';

    return $xml;
}

function storage_export_xlsx_payload(array $storages): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive is required to generate Excel storage exports.');
    }

    $rows = [];
    $images = [];
    $imageSize = item_xlsx_thumbnail_export_size();
    $includeBarcodeImages = excel_export_barcode_images_enabled();

    foreach ($storages as $storage) {
        $storageLabel = storage_type_label($storage['storage_type']);
        $storageStatus = (int) $storage['is_active'] === 1 ? 'Active' : 'Deleted';
        $storageUpdatedAt = $storage['updated_at'] ? format_datetime_display($storage['updated_at']) : '';
        $storageBase = [
            'storage_name' => (string) $storage['name'],
            'storage_type' => $storageLabel,
            'storage_status' => $storageStatus,
            'assigned_items' => (string) (int) $storage['assigned_item_count'],
            'storage_quantity' => format_quantity($storage['total_quantity']),
            'storage_value' => format_money($storage['total_stock_value']),
            'storage_used' => format_quantity($storage['total_used']),
            'storage_transferred_in' => format_quantity($storage['transferred_in']),
            'storage_transferred_out' => format_quantity($storage['transferred_out']),
            'storage_notes' => (string) ($storage['notes'] ?: ''),
            'storage_updated_at' => $storageUpdatedAt,
        ];

        $rows[] = $storageBase + [
            'row_type' => 'Storage',
        ];

        foreach (storage_items((int) $storage['id']) as $item) {
            $scanCode = item_scan_code($item);
            $excelRow = count($rows) + 2;
            $image = workflow_xlsx_image_asset($item['image_path'] ?? null, $imageSize);

            if ($image !== null) {
                $image['row'] = $excelRow;
                $image['col'] = 12;
                $image['name'] = 'Storage Item Thumbnail ' . $excelRow;
                $images[] = $image;
            }

            if ($includeBarcodeImages && $scanCode !== '') {
                $barcodeImage = workflow_code39_png_asset($scanCode, 220, 52);

                if ($barcodeImage !== null) {
                    $barcodeImage['row'] = $excelRow;
                    $barcodeImage['col'] = 17;
                    $barcodeImage['name'] = 'Storage Item Barcode ' . $excelRow;
                    $images[] = $barcodeImage;
                }
            }

            $rows[] = $storageBase + [
                'row_type' => 'Item',
                'item_name' => (string) $item['name'],
                'item_sku' => (string) $item['sku'],
                'barcode_value' => normalize_item_barcode($item['barcode'] ?? '') !== '' ? normalize_item_barcode($item['barcode'] ?? '') : 'Not set',
                'scan_code' => $scanCode,
                'item_category' => (string) ($item['category'] ?: 'Unsorted'),
                'item_quantity' => format_quantity($item['quantity']),
                'item_unit' => (string) $item['unit'],
                'item_cost_per_unit' => format_money($item['cost_per_unit']),
                'item_stock_value' => format_money(stock_value($item['quantity'], $item['cost_per_unit'])),
                'item_reorder_level' => format_quantity($item['reorder_level']),
                'item_used_quantity' => format_quantity($item['total_used']),
                'item_transferred_in' => format_quantity($item['transferred_in']),
                'item_transferred_out' => format_quantity($item['transferred_out']),
                'item_status' => (int) $item['is_active'] === 1 ? 'Active' : 'Deleted',
                'item_last_activity' => $item['last_activity_at'] ? format_datetime_display($item['last_activity_at']) : 'Never',
                'item_notes' => (string) ($item['notes'] ?: ''),
            ];
        }
    }

    $tmp = tempnam(sys_get_temp_dir(), 'storages-xlsx-');

    if ($tmp === false) {
        throw new RuntimeException('Could not create temporary Excel file.');
    }

    $zip = new ZipArchive();

    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($tmp);
        throw new RuntimeException('Could not open temporary Excel archive.');
    }

    $zip->addFromString('[Content_Types].xml', workflow_xlsx_content_types_xml(array_values($images)));
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>');
    $zip->addFromString('docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes"><Application>Inventory KONA</Application></Properties>');
    $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>Storage Export</dc:title><dc:creator>Inventory KONA</dc:creator><cp:lastModifiedBy>Inventory KONA</cp:lastModifiedBy><dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('c') . '</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">' . gmdate('c') . '</dcterms:modified></cp:coreProperties>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Storages" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $zip->addFromString('xl/styles.xml', workflow_xlsx_styles_xml());
    $zip->addFromString('xl/worksheets/sheet1.xml', storage_export_xlsx_sheet_xml($rows, $images, $imageSize));

    if ($images) {
        $zip->addFromString('xl/worksheets/_rels/sheet1.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/></Relationships>');
        $zip->addFromString('xl/drawings/drawing1.xml', workflow_xlsx_drawing_xml(array_values($images)));
        $zip->addFromString('xl/drawings/_rels/drawing1.xml.rels', workflow_xlsx_drawing_rels_xml(array_values($images)));

        foreach (array_values($images) as $index => $image) {
            $zip->addFromString('xl/media/image' . ($index + 1) . '.' . $image['extension'], (string) $image['bytes']);
        }
    }

    $zip->close();
    $bytes = file_get_contents($tmp);
    @unlink($tmp);

    if ($bytes === false || $bytes === '') {
        throw new RuntimeException('Could not build Excel storage export.');
    }

    return $bytes;
}

function handle_export_storages_xlsx(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('storages.export');

    if (!storage_xlsx_thumbnail_export_enabled()) {
        abort(403, 'Storage Excel thumbnail export is disabled in Website Control.');
    }

    try {
        export_xlsx('storage-export-' . date('Ymd-His') . '.xlsx', storage_export_xlsx_payload(storage_summaries(storage_filters())));
    } catch (Throwable $exception) {
        abort(500, 'Could not export storage thumbnails. ' . $exception->getMessage());
    }
}

function handle_export_users(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('users.export');

    $users = users_for_access_control();

    $rows = array_map(static function (array $userRecord): array {
        return [
            $userRecord['name'],
            $userRecord['email'],
            user_position_label($userRecord['position'] ?? '', (string) $userRecord['role']),
            user_role_label((string) $userRecord['role']),
            ($userRecord['role'] ?? '') === 'staff' ? (string) ($userRecord['assigned_owner_name'] ?? '') : '',
            (int) $userRecord['is_active'] === 1 ? 'Active' : 'Disabled',
            (int) ($userRecord['permission_count'] ?? 0),
            $userRecord['last_login_at'] ?: '',
            $userRecord['created_at'] ?: '',
        ];
    }, $users);

    export_csv('admin-export-' . date('Ymd-His') . '.csv', [
        'Name',
        'Email',
        'Position',
        'Role',
        'Assigned Owner',
        'Status',
        'Permission Count',
        'Last Login At',
        'Created At',
    ], $rows);
}

function handle_storages_index(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('storages.view');

    $filters = storage_filters();
    $storages = storage_summaries($filters);

    $counts = [
        'active' => (int) Database::scalar('SELECT COUNT(*) FROM storages WHERE is_active = 1'),
        'archived' => (int) Database::scalar('SELECT COUNT(*) FROM storages WHERE is_active = 0'),
    ];

    View::render('storages/index', [
        'title' => site_setting('page.storages', 'Storages'),
        'storages' => $storages,
        'filters' => $filters,
        'counts' => $counts,
    ]);
}

function handle_storages_show(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('storages.view');

    $storage = find_storage_or_abort((int) $params['id']);
    $items = storage_items((int) $storage['id']);

    $metrics = [
        'contained_items' => count($items),
        'stocked_items' => count(array_filter(
            $items,
            static fn (array $item): bool => (int) $item['is_active'] === 1 && round((float) $item['quantity'], 2) > 0
        )),
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
        'purchaseHistory' => function_exists('purchase_history_for_storage') ? purchase_history_for_storage((int) $storage['id']) : [],
    ]);
}

function handle_storages_create_page(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('storages.create');
    $copySource = requested_storage_copy_source();
    $currentUser = Auth::user();

    View::render('storages/form', [
        'title' => 'Create Storage',
        'mode' => 'create',
        'storage' => default_storage_payload($copySource),
        'copySource' => $copySource,
        'ownerCandidates' => admin_owner_users_for_select((int) ($currentUser['id'] ?? 0)),
    ]);
}

function handle_storages_create_submit(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('storages.create');
    verify_csrf();

    $user = Auth::user();
    $copySource = requested_storage_copy_source();
    $payload = [
        'name' => trim((string) input('name')),
        'storage_type' => (string) input('storage_type', 'storage'),
        'notes' => trim((string) input('notes')),
        'owner_user_id' => normalize_entity_id(input('owner_user_id')),
        'copy_contents_mode' => (string) input('copy_contents_mode', 'empty'),
    ];

    flash_old_input($payload + [
        'copy_storage_id' => $copySource ? (string) $copySource['id'] : '',
    ]);

    $errors = [];

    if ($payload['name'] === '') {
        $errors[] = 'Storage name is required.';
    }

    if (!in_array($payload['storage_type'], ['warehouse', 'storage'], true)) {
        $errors[] = 'Pick a valid location type.';
    }

    if (!in_array($payload['copy_contents_mode'], ['empty', 'item_setup', 'current_stock'], true)) {
        $errors[] = 'Pick a valid copy mode.';
    }

    $ownerRecord = null;

    if (!$payload['owner_user_id']) {
        $errors[] = 'Pick which admin owns this storage.';
    } else {
        $ownerRecord = Database::fetch(
            'SELECT id, role, is_active
             FROM users
             WHERE id = :id
             LIMIT 1',
            ['id' => $payload['owner_user_id']]
        );

        if (!$ownerRecord || (int) ($ownerRecord['is_active'] ?? 0) !== 1 || !in_array((string) ($ownerRecord['role'] ?? ''), ['owner', 'admin'], true)) {
            $errors[] = 'Pick a valid active storage owner.';
        }
    }

    if (active_storage_name_exists($payload['name'])) {
        $errors[] = 'An active location already uses this name.';
    }

    if ($errors !== []) {
        flash_errors($errors);
        redirect('/storages/create');
    }

    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        Database::execute(
            'INSERT INTO storages (name, storage_type, notes, owner_user_id, is_active, created_by, updated_by, created_at, updated_at)
             VALUES (:name, :storage_type, :notes, :owner_user_id, 1, :created_by, :updated_by, NOW(), NOW())',
            [
                'name' => $payload['name'],
                'storage_type' => $payload['storage_type'],
                'notes' => $payload['notes'] !== '' ? $payload['notes'] : null,
                'owner_user_id' => (int) $payload['owner_user_id'],
                'created_by' => $user['id'],
                'updated_by' => $user['id'],
            ]
        );

        $storageId = Database::lastInsertId();

        if ($copySource !== null) {
            if ($payload['copy_contents_mode'] === 'current_stock') {
                clone_storage_inventory_to_location($copySource, $storageId, $payload['name'], (int) $user['id']);
            } elseif ($payload['copy_contents_mode'] === 'item_setup') {
                clone_storage_item_setup_to_location($copySource, $storageId);
            }
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/storages/create');
    }

    consume_old_input();
    $successMessage = 'Storage created.';

    if ($copySource !== null && $payload['copy_contents_mode'] === 'current_stock') {
        $successMessage = 'Storage created and current stock copied.';
    } elseif ($copySource !== null && $payload['copy_contents_mode'] === 'item_setup') {
        $successMessage = 'Storage created and item setup copied with zero quantity.';
    }

    flash('success', $successMessage);
    redirect($copySource !== null ? '/storages/' . $storageId : '/storages');
}

function handle_storages_edit_page(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('storages.edit');

    $storage = find_storage_or_abort((int) $params['id']);

    View::render('storages/form', [
        'title' => 'Edit ' . $storage['name'],
        'mode' => 'edit',
        'storage' => [
            'id' => $storage['id'],
            'name' => old('name', $storage['name']),
            'storage_type' => old('storage_type', $storage['storage_type']),
            'notes' => old('notes', $storage['notes']),
            'owner_user_id' => old('owner_user_id', (string) ($storage['owner_user_id'] ?? '')),
            'copy_storage_id' => '',
            'copy_contents_mode' => 'empty',
            'is_active' => (int) $storage['is_active'],
            'assigned_item_count' => (int) $storage['assigned_item_count'],
            'stocked_item_count' => (int) $storage['stocked_item_count'],
            'total_quantity' => (float) $storage['total_quantity'],
            'total_used' => (float) $storage['total_used'],
        ],
        'copySource' => null,
        'ownerCandidates' => admin_owner_users_for_select((int) ($storage['owner_user_id'] ?? 0)),
    ]);
}

function handle_storages_edit_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('storages.edit');
    verify_csrf();

    $storage = find_storage_or_abort((int) $params['id']);
    $user = Auth::user();
    $payload = [
        'name' => trim((string) input('name')),
        'storage_type' => (string) input('storage_type', 'storage'),
        'notes' => trim((string) input('notes')),
        'owner_user_id' => normalize_entity_id(input('owner_user_id')),
    ];

    flash_old_input($payload);

    $errors = [];

    if ($payload['name'] === '') {
        $errors[] = 'Storage name is required.';
    }

    if (!in_array($payload['storage_type'], ['warehouse', 'storage'], true)) {
        $errors[] = 'Pick a valid location type.';
    }

    $ownerRecord = null;

    if (!$payload['owner_user_id']) {
        $errors[] = 'Pick which admin owns this storage.';
    } else {
        $ownerRecord = Database::fetch(
            'SELECT id, role, is_active
             FROM users
             WHERE id = :id
             LIMIT 1',
            ['id' => $payload['owner_user_id']]
        );

        if (!$ownerRecord || (int) ($ownerRecord['is_active'] ?? 0) !== 1 || !in_array((string) ($ownerRecord['role'] ?? ''), ['owner', 'admin'], true)) {
            $errors[] = 'Pick a valid active storage owner.';
        }
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
             owner_user_id = :owner_user_id,
             updated_by = :updated_by,
             updated_at = NOW()
         WHERE id = :id',
        [
            'name' => $payload['name'],
            'storage_type' => $payload['storage_type'],
            'notes' => $payload['notes'] !== '' ? $payload['notes'] : null,
            'owner_user_id' => (int) $payload['owner_user_id'],
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
    Auth::requirePermission('storages.archive');
    verify_csrf();

    $storage = find_storage_or_abort((int) $params['id']);
    $user = Auth::user();
    $nextStatus = (int) $storage['is_active'] === 1 ? 0 : 1;

    if ($nextStatus === 0 && (int) $storage['stocked_item_count'] > 0) {
        flash('danger', 'Move or remove the remaining stock in this location before deleting it.');
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
    Auth::requirePermission('users.view');

    View::render('users/index', [
        'title' => site_setting('page.users', 'Admins'),
        'users' => users_for_access_control(),
    ]);
}

function handle_users_create_page(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('users.create');

    $selectedPosition = (string) old('position', 'operations_manager');
    $selectedRole = (string) old('role', access_role_for_position($selectedPosition));
    $selectedPermissions = old('permissions', default_permissions_for_position($selectedPosition));

    View::render('users/form', [
        'title' => 'Create Admin',
        'mode' => 'create',
        'userRecord' => [
            'name' => old('name', ''),
            'email' => old('email', ''),
            'position' => $selectedPosition,
            'role' => $selectedRole,
            'assigned_owner_user_id' => old('assigned_owner_user_id', ''),
            'is_active' => 1,
        ],
        'positionOptions' => user_position_options(),
        'roleOptions' => user_role_options(),
        'ownerCandidates' => handover_request_owner_candidates_for_select(normalize_entity_id(old('assigned_owner_user_id', ''))),
        'permissionGroups' => permission_groups_for_form(is_array($selectedPermissions) ? sanitize_permission_input($selectedPermissions) : default_permissions_for_position($selectedPosition)),
    ]);
}

function handle_users_create_submit(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('users.create');
    verify_csrf();

    $payload = [
        'name' => trim((string) input('name')),
        'email' => strtolower(trim((string) input('email'))),
        'position' => trim((string) input('position', 'operations_manager')),
        'role' => trim((string) input('role', 'admin')),
        'assigned_owner_user_id' => normalize_entity_id(input('assigned_owner_user_id')),
        'password' => (string) input('password'),
        'password_confirmation' => (string) input('password_confirmation'),
        'permissions' => is_array(input('permissions', [])) ? input('permissions', []) : [],
    ];

    flash_old_input([
        'name' => $payload['name'],
        'email' => $payload['email'],
        'position' => $payload['position'],
        'role' => $payload['role'],
        'assigned_owner_user_id' => (string) ($payload['assigned_owner_user_id'] ?? ''),
        'permissions' => $payload['permissions'],
    ]);

    $errors = [];

    if ($payload['name'] === '') {
        $errors[] = 'Name is required.';
    }

    if (!filter_var($payload['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Use a valid email address.';
    }

    if (!array_key_exists($payload['role'], user_role_options())) {
        $errors[] = 'Pick a valid role.';
    }

    if (!array_key_exists($payload['position'], user_position_options())) {
        $errors[] = 'Pick a valid position.';
    }

    if ($payload['role'] !== 'staff') {
        $payload['assigned_owner_user_id'] = null;
    }

    if ($payload['assigned_owner_user_id'] !== null) {
        $assignedOwner = Database::fetch(
            'SELECT id, role, is_active
             FROM users
             WHERE id = :id
             LIMIT 1',
            ['id' => $payload['assigned_owner_user_id']]
        );

        if (!$assignedOwner || (int) ($assignedOwner['is_active'] ?? 0) !== 1 || !in_array((string) ($assignedOwner['role'] ?? ''), ['owner', 'admin'], true)) {
            $errors[] = 'Pick a valid active storage owner for this staff account.';
        }
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

    $permissions = sanitize_permission_input($payload['permissions']);

    if ($permissions === []) {
        $permissions = default_permissions_for_position($payload['position']);
    }

    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        Database::execute(
            'INSERT INTO users (name, email, password_hash, role, position, is_active, assigned_owner_user_id, created_at, updated_at)
             VALUES (:name, :email, :password_hash, :role, :position, 1, :assigned_owner_user_id, NOW(), NOW())',
            [
                'name' => $payload['name'],
                'email' => $payload['email'],
                'password_hash' => password_hash($payload['password'], PASSWORD_DEFAULT),
                'role' => $payload['role'],
                'position' => $payload['position'],
                'assigned_owner_user_id' => $payload['assigned_owner_user_id'],
            ]
        );

        $userId = Database::lastInsertId();
        save_user_permissions($userId, $permissions, (int) (Auth::user()['id'] ?? 0));
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/users/create');
    }

    consume_old_input();
    if (function_exists('record_activity')) {
        record_activity('user.created', 'user', $userId, 'Created user ' . $payload['email'], [
            'role' => $payload['role'],
            'position' => $payload['position'],
            'permissions' => $permissions,
        ]);
    }
    flash('success', 'User created.');
    redirect('/users');
}

function handle_users_edit_page(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('users.edit');

    $userRecord = find_user_or_abort((int) $params['id']);

    View::render('users/form', [
        'title' => 'Edit ' . $userRecord['name'],
        'mode' => 'edit',
        'userRecord' => [
            'id' => $userRecord['id'],
            'name' => old('name', $userRecord['name']),
            'email' => old('email', $userRecord['email']),
            'position' => old('position', $userRecord['position'] ?: ($userRecord['role'] === 'owner' ? 'owner_operator' : ($userRecord['role'] === 'admin' ? 'general_admin' : 'staff'))),
            'role' => old('role', $userRecord['role']),
            'assigned_owner_user_id' => old('assigned_owner_user_id', (string) ($userRecord['assigned_owner_user_id'] ?? '')),
            'is_active' => (int) $userRecord['is_active'],
        ],
        'positionOptions' => user_position_options(),
        'roleOptions' => user_role_options(),
        'ownerCandidates' => handover_request_owner_candidates_for_select(normalize_entity_id(old('assigned_owner_user_id', (string) ($userRecord['assigned_owner_user_id'] ?? '')))),
        'permissionGroups' => permission_groups_for_form(
            is_array(old('permissions'))
                ? sanitize_permission_input((array) old('permissions'))
                : Auth::permissionsForUserId((int) $userRecord['id'])
        ),
    ]);
}

function handle_users_edit_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('users.edit');
    verify_csrf();

    $userRecord = find_user_or_abort((int) $params['id']);

    $payload = [
        'name' => trim((string) input('name')),
        'email' => strtolower(trim((string) input('email'))),
        'position' => trim((string) input('position', (string) ($userRecord['position'] ?? 'general_admin'))),
        'role' => trim((string) input('role', (string) $userRecord['role'])),
        'assigned_owner_user_id' => normalize_entity_id(input('assigned_owner_user_id')),
        'password' => (string) input('password'),
        'password_confirmation' => (string) input('password_confirmation'),
        'permissions' => is_array(input('permissions', [])) ? input('permissions', []) : [],
    ];

    flash_old_input([
        'name' => $payload['name'],
        'email' => $payload['email'],
        'position' => $payload['position'],
        'role' => $payload['role'],
        'assigned_owner_user_id' => (string) ($payload['assigned_owner_user_id'] ?? ''),
        'permissions' => $payload['permissions'],
    ]);

    $errors = [];

    if ($payload['name'] === '') {
        $errors[] = 'Name is required.';
    }

    if (!filter_var($payload['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Use a valid email address.';
    }

    if ($userRecord['role'] !== 'owner' && !array_key_exists($payload['role'], user_role_options())) {
        $errors[] = 'Pick a valid role.';
    }

    if (!array_key_exists($payload['position'], user_position_options())) {
        $errors[] = 'Pick a valid position.';
    }

    if ($userRecord['role'] === 'owner' || $payload['role'] !== 'staff') {
        $payload['assigned_owner_user_id'] = null;
    }

    if ($payload['assigned_owner_user_id'] !== null) {
        $assignedOwner = Database::fetch(
            'SELECT id, role, is_active
             FROM users
             WHERE id = :id
             LIMIT 1',
            ['id' => $payload['assigned_owner_user_id']]
        );

        if (!$assignedOwner || (int) ($assignedOwner['is_active'] ?? 0) !== 1 || !in_array((string) ($assignedOwner['role'] ?? ''), ['owner', 'admin'], true)) {
            $errors[] = 'Pick a valid active storage owner for this staff account.';
        }
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

    $nextRole = $userRecord['role'] === 'owner' ? 'owner' : $payload['role'];
    $permissions = $nextRole === 'owner'
        ? permission_keys()
        : sanitize_permission_input($payload['permissions']);

    if ($nextRole !== 'owner' && $permissions === []) {
        $permissions = default_permissions_for_position($payload['position']);
    }

    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        Database::execute(
            'UPDATE users
                 SET name = :name,
                     email = :email,
                     role = :role,
                     position = :position,
                     assigned_owner_user_id = :assigned_owner_user_id,
                     updated_at = NOW()
                 WHERE id = :id',
            [
                'name' => $payload['name'],
                'email' => $payload['email'],
                'role' => $nextRole,
                'position' => $payload['position'],
                'assigned_owner_user_id' => $payload['assigned_owner_user_id'],
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

        if ($nextRole !== 'owner') {
            save_user_permissions((int) $userRecord['id'], $permissions, (int) (Auth::user()['id'] ?? 0));
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/users/' . $userRecord['id'] . '/edit');
    }

    consume_old_input();
    if (function_exists('record_activity')) {
        record_activity('user.updated', 'user', (int) $userRecord['id'], 'Updated user ' . $payload['email'], [
            'role' => $nextRole,
            'position' => $payload['position'],
            'password_changed' => $payload['password'] !== '',
            'permissions' => $nextRole === 'owner' ? ['owner_all'] : $permissions,
        ]);
    }
    flash('success', 'User updated.');
    redirect('/users');
}

function handle_users_status_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('users.disable');
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

    if (function_exists('record_activity')) {
        record_activity($nextStatus ? 'user.restored' : 'user.disabled', 'user', (int) $userRecord['id'], ($nextStatus ? 'Restored ' : 'Disabled ') . $userRecord['email']);
    }
    flash('success', $nextStatus ? 'User restored.' : 'User disabled.');
    redirect('/users');
}

function handle_users_send_reset_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('users.edit');
    verify_csrf();

    $userRecord = find_user_or_abort((int) $params['id']);
    $currentUser = Auth::user();

    if (!Auth::isOwner() && (string) $userRecord['role'] === 'owner') {
        flash('danger', 'Only the owner can send a reset link to the owner account.');
        redirect('/users');
    }

    if ((int) ($userRecord['is_active'] ?? 0) !== 1) {
        flash('danger', 'Restore this user before sending a reset link.');
        redirect('/users');
    }

    $token = create_password_reset_token($userRecord, $currentUser ? (int) $currentUser['id'] : null);
    $result = send_password_reset_email($userRecord, $token, $currentUser ? (int) $currentUser['id'] : null);

    if (function_exists('record_activity')) {
        record_activity('user.password_reset_sent', 'user', (int) $userRecord['id'], 'Sent password reset link to ' . $userRecord['email'], [
            'email_status' => $result['status'] ?? 'unknown',
            'sent_by' => $currentUser['email'] ?? null,
        ]);
    }

    if (($result['status'] ?? '') === 'sent') {
        flash('success', 'Password reset email sent.');
    } elseif (($result['status'] ?? '') === 'suppressed') {
        flash('warning', 'Reset link created but email was not sent: ' . ($result['error'] ?? 'suppressed'));
    } else {
        flash('danger', 'Reset link created, but email failed: ' . ($result['error'] ?? 'unknown error'));
    }

    redirect('/users');
}

function brand_logo_uploaded_file_meta(array $file): array
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_OK);

    if ($error !== UPLOAD_ERR_OK) {
        $messages = [
            UPLOAD_ERR_INI_SIZE => 'Logo file is larger than the server upload limit.',
            UPLOAD_ERR_FORM_SIZE => 'Logo file is larger than the allowed form size.',
            UPLOAD_ERR_PARTIAL => 'Logo upload was interrupted. Try again.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server upload temp directory is missing.',
            UPLOAD_ERR_CANT_WRITE => 'Server could not write the uploaded logo.',
            UPLOAD_ERR_EXTENSION => 'A server extension blocked the logo upload.',
        ];

        throw new RuntimeException($messages[$error] ?? 'Logo upload failed.');
    }

    $size = (int) ($file['size'] ?? 0);

    if ($size <= 0) {
        throw new RuntimeException('Choose a logo file to upload.');
    }

    if ($size > 4 * 1024 * 1024) {
        throw new RuntimeException('Logo file is too large. Max size is 4 MB.');
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');

    if ($tmpName === '' || !is_file($tmpName)) {
        throw new RuntimeException('Logo upload could not be read.');
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = $finfo ? (string) finfo_file($finfo, $tmpName) : '';

    if ($finfo) {
        finfo_close($finfo);
    }

    $extensions = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    if (!isset($extensions[$mimeType])) {
        throw new RuntimeException('Logo must be a PNG, JPG, or WebP image.');
    }

    if (@getimagesize($tmpName) === false) {
        throw new RuntimeException('Logo image is invalid.');
    }

    return [
        'mime_type' => $mimeType,
        'extension' => $extensions[$mimeType],
    ];
}

function delete_brand_custom_logo_asset(?string $asset): void
{
    $asset = $asset === null ? '' : ltrim(str_replace('\\', '/', $asset), '/');

    if ($asset === '' || !starts_with($asset, 'brand/uploads/')) {
        return;
    }

    $path = base_path('assets/' . $asset);

    if (is_file($path)) {
        @unlink($path);
    }
}

function save_brand_logo_setting(string $key, ?string $value, ?int $userId): void
{
    if ($value === null || trim($value) === '') {
        Database::execute('DELETE FROM app_settings WHERE setting_key = :setting_key', [
            'setting_key' => $key,
        ]);
        return;
    }

    Database::execute(
        'INSERT INTO app_settings (setting_key, setting_value, updated_by, updated_at)
         VALUES (:setting_key, :setting_value, :updated_by, NOW())
         ON DUPLICATE KEY UPDATE
            setting_value = VALUES(setting_value),
            updated_by = VALUES(updated_by),
            updated_at = VALUES(updated_at)',
        [
            'setting_key' => $key,
            'setting_value' => trim($value),
            'updated_by' => $userId,
        ]
    );
}

function store_brand_logo_upload(array $file): array
{
    $meta = brand_logo_uploaded_file_meta($file);
    ensure_directory_exists(brand_logo_upload_directory());

    $originalName = basename((string) ($file['name'] ?? 'logo.' . $meta['extension']));
    $baseName = pathinfo($originalName, PATHINFO_FILENAME);
    $filename = date('YmdHis') . '-' . slugify_filename($baseName !== '' ? $baseName : 'logo') . '-' . substr(bin2hex(random_bytes(5)), 0, 10) . '.' . $meta['extension'];
    $destination = brand_logo_upload_directory() . '/' . $filename;

    if (!move_uploaded_file((string) ($file['tmp_name'] ?? ''), $destination)) {
        throw new RuntimeException('Could not save the logo file.');
    }

    return [
        'asset' => 'brand/uploads/' . $filename,
        'original_name' => $originalName !== '' ? $originalName : 'logo.' . $meta['extension'],
    ];
}

function handle_site_logo_submit(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('settings.edit');
    verify_csrf();

    $user = Auth::user();
    $userId = isset($user['id']) ? (int) $user['id'] : null;
    $oldAsset = brand_custom_logo_asset();
    $clearLogo = input('clear_brand_logo', '') === '1';
    $file = uploaded_file('brand_logo');

    if ($file === null && !$clearLogo) {
        flash('danger', 'Choose a logo file or use Clear custom logo.');
        redirect('/settings/site');
    }

    try {
        if ($file !== null) {
            $stored = store_brand_logo_upload($file);
            save_brand_logo_setting('brand.logo_path', $stored['asset'], $userId);
            save_brand_logo_setting('brand.logo_name', $stored['original_name'], $userId);

            if ($oldAsset !== null && $oldAsset !== $stored['asset']) {
                delete_brand_custom_logo_asset($oldAsset);
            }

            site_settings_cache_reset();
            if (function_exists('record_activity')) {
                record_activity('settings.logo_updated', 'settings', null, 'Updated website logo', [
                    'file' => $stored['original_name'],
                ]);
            }
            flash('success', 'Website logo updated.');
            redirect('/settings/site');
        }

        save_brand_logo_setting('brand.logo_path', null, $userId);
        save_brand_logo_setting('brand.logo_name', null, $userId);
        delete_brand_custom_logo_asset($oldAsset);
        site_settings_cache_reset();

        if (function_exists('record_activity')) {
            record_activity('settings.logo_cleared', 'settings', null, 'Cleared custom website logo');
        }

        flash('success', 'Custom logo cleared. The official KONA logo is active again.');
    } catch (Throwable $exception) {
        flash('danger', 'Could not update logo. ' . $exception->getMessage());
    }

    redirect('/settings/site');
}

function handle_site_settings_page(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('settings.view');

    $values = old('site_settings', site_settings());

    View::render('settings/site', [
        'title' => site_setting('page.settings', 'Website Control'),
        'settingGroups' => site_setting_groups(is_array($values) ? $values : [], Auth::hasPermission('settings.secrets')),
        'canManageSecretSettings' => Auth::hasPermission('settings.secrets'),
        'ocrHealth' => function_exists('purchase_ocr_health') ? purchase_ocr_health() : null,
    ]);
}

function handle_site_settings_submit(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('settings.edit');
    verify_csrf();

    $submitted = input('settings', []);
    $clearSubmitted = input('clear_settings', []);

    if (!is_array($submitted)) {
        $submitted = [];
    }

    if (!is_array($clearSubmitted)) {
        $clearSubmitted = [];
    }

    [$payload, $errors, $skipped] = normalize_site_settings_payload($submitted, $clearSubmitted, Auth::hasPermission('settings.secrets'));

    flash_old_input(['site_settings' => $payload]);

    if ($errors !== []) {
        flash_errors($errors);
        redirect('/settings/site');
    }

    $defaults = site_setting_defaults();
    $user = Auth::user();
    $pdo = Database::connection();

    try {
        $pdo->beginTransaction();

        foreach ($payload as $key => $value) {
            $default = (string) ($defaults[$key] ?? '');

            if ($value === '' || $value === $default) {
                Database::execute('DELETE FROM app_settings WHERE setting_key = :setting_key', [
                    'setting_key' => $key,
                ]);
                continue;
            }

            Database::execute(
                'INSERT INTO app_settings (setting_key, setting_value, updated_by, updated_at)
                 VALUES (:setting_key, :setting_value, :updated_by, NOW())
                 ON DUPLICATE KEY UPDATE
                    setting_value = VALUES(setting_value),
                    updated_by = VALUES(updated_by),
                    updated_at = VALUES(updated_at)',
                [
                    'setting_key' => $key,
                    'setting_value' => $value,
                    'updated_by' => $user['id'] ?? null,
                ]
            );
        }

        if ($pdo->inTransaction()) {
            $pdo->commit();
        }

        site_settings_cache_reset();
        consume_old_input();
        if (function_exists('record_activity')) {
            record_activity('settings.updated', 'settings', null, 'Updated website control settings', [
                'changed_keys' => array_keys($payload),
                'skipped_secret_keys' => $skipped,
            ]);
        }
        flash('success', 'Website control updated.');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', 'Could not save website control. ' . $exception->getMessage());
    }

    redirect('/settings/site');
}

function handle_site_email_test_submit(): void
{
    app_ready_or_redirect();
    Auth::requireOwner();
    verify_csrf();

    $user = Auth::user();
    $recipientEmail = strtolower(trim((string) input('test_email', (string) ($user['email'] ?? ''))));

    if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        flash('danger', 'Use a valid email address for the test.');
        redirect('/settings/site');
    }

    $result = send_inventory_email(
        $recipientEmail,
        (string) ($user['name'] ?? ''),
        'Inventory KONA test email',
        "This is a test email from Inventory KONA.\n\nIf this reached your inbox, PHP mail is working on the server.",
        'test_email',
        $user ? (int) $user['id'] : null,
        'settings',
        null,
        true
    );

    if (function_exists('record_activity')) {
        record_activity('settings.email_test', 'settings', null, 'Sent email delivery test to ' . $recipientEmail, [
            'status' => $result['status'] ?? 'unknown',
            'error' => $result['error'] ?? null,
        ]);
    }

    if (($result['status'] ?? '') === 'sent') {
        flash('success', 'Test email sent. Check the inbox.');
    } elseif (($result['status'] ?? '') === 'suppressed') {
        flash('warning', 'Test email logged but not sent: ' . ($result['error'] ?? 'suppressed'));
    } else {
        flash('danger', 'Test email failed: ' . ($result['error'] ?? 'unknown error'));
    }

    redirect('/settings/site');
}
