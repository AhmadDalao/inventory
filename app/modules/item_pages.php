<?php
declare(strict_types=1);

// Item page render handlers. Submit handlers stay in focused persistence/action modules.

function handle_items_index(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('items.view');

    $filters = item_filters();
    [$where, $params] = build_item_where($filters);
    $filteredStorageQuantitySelect = item_filtered_storage_quantity_select($filters, $params);
    $storageScope = current_user_item_storage_scope();
    $storageScopeSql = item_storage_scope_sql($storageScope);
    $balanceScopeSql = $storageScope !== null ? " AND balances.storage_id IN ({$storageScopeSql})" : '';
    $movementScopeSql = $storageScope !== null
        ? " AND (m.source_storage_id IN ({$storageScopeSql}) OR m.destination_storage_id IN ({$storageScopeSql}))"
        : '';
    $defaultStorageScopeSql = $storageScope !== null ? " AND default_storage.id IN ({$storageScopeSql})" : '';
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
                    {$balanceScopeSql}
                ) AS location_count,
                (
                    SELECT GROUP_CONCAT(storage.name ORDER BY balances.quantity DESC, storage.name ASC SEPARATOR ', ')
                    FROM item_storage_balances balances
                    INNER JOIN storages storage ON storage.id = balances.storage_id
                    WHERE balances.item_id = i.id
                    {$balanceScopeSql}
                ) AS storage_summary,
                (SELECT MAX(m.used_at) FROM inventory_movements m WHERE m.item_id = i.id{$movementScopeSql}) AS last_movement_at
         FROM items i
         LEFT JOIN storages default_storage ON default_storage.id = i.storage_id{$defaultStorageScopeSql}
         {$where}
         ORDER BY i.is_active DESC, i.name ASC",
        $params
    );

    [$activeWhere, $activeParams] = build_item_where(['search' => '', 'status' => 'active', 'storage_id' => null]);
    [$archivedWhere, $archivedParams] = build_item_where(['search' => '', 'status' => 'archived', 'storage_id' => null]);
    $counts = [
        'active' => (int) Database::scalar("SELECT COUNT(*) FROM items i {$activeWhere}", $activeParams),
        'archived' => (int) Database::scalar("SELECT COUNT(*) FROM items i {$archivedWhere}", $archivedParams),
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

    $userId = (int) (Auth::user()['id'] ?? 0);

    if (
        $defaultStorageId !== null
        && (!storage_exists_for_assignment($defaultStorageId) || !user_can_view_storage($userId, $defaultStorageId))
    ) {
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

function handle_items_show(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('items.view');

    $item = find_item_or_abort((int) $params['id']);
    require_current_user_item_visibility((int) $item['id']);
    $storageScope = current_user_item_storage_scope();
    $storageScopeSql = item_storage_scope_sql($storageScope);
    $movementScopeSql = $storageScope !== null
        ? " AND (m.source_storage_id IN ({$storageScopeSql}) OR m.destination_storage_id IN ({$storageScopeSql}))"
        : '';
    $sourceJoinScopeSql = $storageScope !== null ? " AND source_storage.id IN ({$storageScopeSql})" : '';
    $destinationJoinScopeSql = $storageScope !== null ? " AND destination_storage.id IN ({$storageScopeSql})" : '';
    $history = Database::fetchAll(
        'SELECT m.*,
                u.name AS user_name,
                CASE
                    WHEN m.source_storage_id IS NOT NULL AND source_storage.id IS NULL THEN "Restricted location"
                    ELSE source_storage.name
                END AS source_storage_name,
                source_storage.storage_type AS source_storage_type,
                CASE
                    WHEN m.destination_storage_id IS NOT NULL AND destination_storage.id IS NULL THEN "Restricted location"
                    ELSE destination_storage.name
                END AS destination_storage_name,
                destination_storage.storage_type AS destination_storage_type
         FROM inventory_movements m
         LEFT JOIN users u ON u.id = m.performed_by
         LEFT JOIN storages source_storage ON source_storage.id = m.source_storage_id' . $sourceJoinScopeSql . '
         LEFT JOIN storages destination_storage ON destination_storage.id = m.destination_storage_id' . $destinationJoinScopeSql . '
         WHERE m.item_id = :item_id
         ' . $movementScopeSql . '
         ORDER BY m.used_at DESC, m.id DESC
         LIMIT 50',
        ['item_id' => $item['id']]
    );

    $historyMetrics = item_history_metrics((int) $item['id'], $storageScope);
    $balances = item_storage_balances((int) $item['id'], $storageScope);
    $isStorageScoped = $storageScope !== null;

    if ($isStorageScoped) {
        $item['current_quantity'] = array_sum(array_map(static fn (array $balance): float => (float) $balance['quantity'], $balances));
        $item['location_count'] = count($balances);
        $item['location_summary'] = implode(', ', array_column($balances, 'name'));

        if (!in_array((int) ($item['storage_id'] ?? 0), $storageScope, true)) {
            $item['default_storage_name'] = null;
            $item['default_storage_type'] = null;
        }
    }

    $stockPositions = item_stock_positions($balances, $isStorageScoped ? null : (int) $item['id']);
    $packagePresets = item_package_presets((int) $item['id'], Auth::hasPermission('items.edit'));

    View::render('items/show', [
        'title' => $item['name'],
        'item' => $item,
        'history' => $history,
        'historyMetrics' => $historyMetrics,
        'balances' => $balances,
        'stockPositions' => $stockPositions,
        'isStorageScoped' => $isStorageScoped,
        'packagePresets' => $packagePresets,
        'usageReasons' => mobile_usage_reason_catalog(true),
        'departmentOptions' => Auth::hasPermission('movements.override_department') ? department_options() : [],
        'purchaseHistory' => Auth::hasPermission('purchases.view') && function_exists('purchase_history_for_item')
            ? purchase_history_for_item((int) $item['id'])
            : [],
        'storages' => all_storages_for_select($item['storage_id'] ? (int) $item['storage_id'] : null),
        'movementTypeOptions' => movement_type_options_for_user(),
    ]);
}

function handle_items_edit_page(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('items.edit');

    $item = find_item_or_abort((int) $params['id']);
    require_current_user_item_visibility((int) $item['id']);
    $storageScope = current_user_item_storage_scope();

    if ($storageScope !== null) {
        $visibleBalances = item_storage_balances((int) $item['id'], $storageScope);
        $item['current_quantity'] = array_sum(array_map(
            static fn (array $balance): float => (float) $balance['quantity'],
            $visibleBalances
        ));

        if (!in_array((int) ($item['storage_id'] ?? 0), $storageScope, true)) {
            $item['storage_id'] = null;
        }
    }

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
            'measurement_dimension' => old('measurement_dimension', normalize_inventory_measurement_dimension($item['measurement_dimension'] ?? 'count')),
            'usage_proof_policy' => old('usage_proof_policy', normalize_inventory_proof_policy($item['usage_proof_policy'] ?? 'inherit')),
            'refill_proof_policy' => old('refill_proof_policy', normalize_inventory_proof_policy($item['refill_proof_policy'] ?? 'inherit')),
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
