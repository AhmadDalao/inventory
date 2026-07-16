<?php
declare(strict_types=1);

// Domain module: inventory. Function names are preserved for route/view compatibility.

// Moved from controllers.php.

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

function handle_items_index(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('items.view');

    $filters = item_filters();
    [$where, $params] = build_item_where($filters);
    $filteredStorageQuantitySelect = item_filtered_storage_quantity_select($filters, $params);
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
                {$filteredStorageQuantitySelect},
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
    $defaultStorageId = normalize_entity_id(query('storage_id', ''));

    if ($defaultStorageId !== null && !storage_exists_for_assignment($defaultStorageId)) {
        $defaultStorageId = null;
    }

    View::render('items/form', [
        'title' => 'Create Item',
        'mode' => 'create',
        'item' => default_item_payload($copySource, $defaultStorageId),
        'copySource' => $copySource,
        'storages' => all_storages_for_select($defaultStorageId),
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
