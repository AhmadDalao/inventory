<?php
declare(strict_types=1);

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
        $conditions[] = "({$alias}.source_storage_id = :movement_source_storage_id OR {$alias}.destination_storage_id = :movement_destination_storage_id)";
        $params['movement_source_storage_id'] = $filters['storage_id'];
        $params['movement_destination_storage_id'] = $filters['storage_id'];
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

function movement_absolute_quantity(array $movement): float
{
    if (($movement['movement_quantity'] ?? null) !== null && (string) $movement['movement_quantity'] !== '') {
        return abs((float) $movement['movement_quantity']);
    }

    return abs((float) ($movement['quantity_delta'] ?? 0));
}

function movement_scope_for_storage(array $movement, ?int $storageId): array
{
    if ($storageId === null) {
        return [
            'scope_label' => 'All locations',
            'location_change' => (float) ($movement['quantity_delta'] ?? 0),
            'location_balance_after' => (float) ($movement['balance_after'] ?? 0),
        ];
    }

    $sourceId = isset($movement['source_storage_id']) ? (int) $movement['source_storage_id'] : null;
    $destinationId = isset($movement['destination_storage_id']) ? (int) $movement['destination_storage_id'] : null;
    $quantity = movement_absolute_quantity($movement);
    $type = (string) ($movement['movement_type'] ?? '');

    if ($sourceId === $storageId) {
        $change = $type === 'adjustment' ? (float) ($movement['quantity_delta'] ?? 0) : -$quantity;
        $sourceLabels = [
            'usage' => 'Used from selected location',
            'transfer' => 'Transferred out of selected location',
            'adjustment' => 'Adjusted selected location',
        ];

        return [
            'scope_label' => $sourceLabels[$type] ?? 'Source location',
            'location_change' => $change,
            'location_balance_after' => (float) ($movement['source_balance_after'] ?? $movement['balance_after'] ?? 0),
        ];
    }

    if ($destinationId === $storageId) {
        $destinationLabels = [
            'restock' => 'Added to selected location',
            'transfer' => 'Transferred into selected location',
        ];

        return [
            'scope_label' => $destinationLabels[$type] ?? 'Destination location',
            'location_change' => $quantity,
            'location_balance_after' => (float) ($movement['destination_balance_after'] ?? $movement['balance_after'] ?? 0),
        ];
    }

    return [
        'scope_label' => 'Outside selected location',
        'location_change' => (float) ($movement['quantity_delta'] ?? 0),
        'location_balance_after' => (float) ($movement['balance_after'] ?? 0),
    ];
}

function movement_apply_filter_scope(array $movement, ?int $storageId): array
{
    $scope = movement_scope_for_storage($movement, $storageId);

    return array_merge($movement, [
        'location_scope_label' => $scope['scope_label'],
        'location_change' => $scope['location_change'],
        'location_balance_after' => $scope['location_balance_after'],
        'is_location_scoped' => $storageId !== null,
    ]);
}

function handle_movements_index(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('movements.view');

    $filters = movement_filters();
    [$where, $params] = build_movement_where($filters);

    $movements = Database::fetchAll(
        "SELECT m.*,
                COALESCE(i.name, CONCAT('Item #', m.item_id)) AS item_name,
                COALESCE(i.sku, '') AS sku,
                COALESCE(i.unit, '') AS unit,
                i.image_path,
                source_storage.name AS source_storage_name,
                source_storage.storage_type AS source_storage_type,
                destination_storage.name AS destination_storage_name,
                destination_storage.storage_type AS destination_storage_type,
                u.name AS user_name
         FROM inventory_movements m
         LEFT JOIN items i ON i.id = m.item_id
         LEFT JOIN storages source_storage ON source_storage.id = m.source_storage_id
         LEFT JOIN storages destination_storage ON destination_storage.id = m.destination_storage_id
         LEFT JOIN users u ON u.id = m.performed_by
         {$where}
         ORDER BY m.used_at DESC, m.id DESC
         LIMIT 250",
        $params
    );
    $movements = array_map(
        static fn (array $movement): array => movement_apply_filter_scope($movement, $filters['storage_id']),
        $movements
    );

    View::render('movements/index', [
        'title' => site_setting('page.movements', 'Movement Log'),
        'movements' => $movements,
        'filters' => $filters,
        'items' => all_items_for_select(),
        'storages' => all_storages_for_select($filters['storage_id']),
    ]);
}
