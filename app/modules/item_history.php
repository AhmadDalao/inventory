<?php
declare(strict_types=1);

function item_history_metrics(int $itemId, ?array $storageIds = null): array
{
    $scopeSql = $storageIds !== null
        ? ' AND (source_storage_id IN (' . item_storage_scope_sql($storageIds) . ') OR destination_storage_id IN (' . item_storage_scope_sql($storageIds) . '))'
        : '';

    return Database::fetch(
        'SELECT
             COALESCE(SUM(CASE WHEN movement_type = "usage" THEN movement_quantity ELSE 0 END), 0) AS total_used,
             COALESCE(SUM(CASE WHEN movement_type = "restock" THEN movement_quantity WHEN movement_type = "adjustment" AND quantity_delta > 0 THEN quantity_delta ELSE 0 END), 0) AS total_added,
             COALESCE(SUM(CASE WHEN movement_type = "transfer" THEN movement_quantity ELSE 0 END), 0) AS total_transferred,
             COUNT(*) AS movement_count
         FROM inventory_movements
         WHERE item_id = :item_id' . $scopeSql,
        ['item_id' => $itemId]
    ) ?: [
        'total_used' => 0,
        'total_added' => 0,
        'total_transferred' => 0,
        'movement_count' => 0,
    ];
}

function latest_item_movement(int $itemId, ?array $storageIds = null): ?array
{
    $scopeSql = $storageIds !== null
        ? ' AND (m.source_storage_id IN (' . item_storage_scope_sql($storageIds) . ') OR m.destination_storage_id IN (' . item_storage_scope_sql($storageIds) . '))'
        : '';
    $sourceJoinScopeSql = $storageIds !== null ? ' AND source_storage.id IN (' . item_storage_scope_sql($storageIds) . ')' : '';
    $destinationJoinScopeSql = $storageIds !== null ? ' AND destination_storage.id IN (' . item_storage_scope_sql($storageIds) . ')' : '';

    return Database::fetch(
        'SELECT m.*,
                u.name AS user_name,
                source_storage.name AS source_storage_name,
                source_storage.storage_type AS source_storage_type,
                destination_storage.name AS destination_storage_name,
                destination_storage.storage_type AS destination_storage_type
         FROM inventory_movements m
         LEFT JOIN users u ON u.id = m.performed_by
         LEFT JOIN storages source_storage ON source_storage.id = m.source_storage_id' . $sourceJoinScopeSql . '
         LEFT JOIN storages destination_storage ON destination_storage.id = m.destination_storage_id' . $destinationJoinScopeSql . '
         WHERE m.item_id = :item_id
         ' . $scopeSql . '
         ORDER BY m.used_at DESC, m.id DESC
         LIMIT 1',
        ['item_id' => $itemId]
    );
}

function item_storage_balances(int $itemId, ?array $storageIds = null): array
{
    $scopeSql = $storageIds !== null
        ? ' AND balances.storage_id IN (' . item_storage_scope_sql($storageIds) . ')'
        : '';

    return Database::fetchAll(
        'SELECT balances.item_id,
                balances.storage_id,
                balances.quantity,
                storage.name,
                storage.storage_type,
                storage.is_active,
                storage.is_system,
                storage.system_key,
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
         WHERE balances.item_id = :item_id' . $scopeSql . '
         ORDER BY FIELD(storage.storage_type, "warehouse", "storage"), balances.quantity DESC, storage.name ASC',
        ['item_id' => $itemId]
    );
}

function item_stock_positions(array $balances, ?int $itemId = null): array
{
    $positions = [
        'available_active' => 0.0,
        'held_by_staff' => 0.0,
        'damaged_quarantine' => 0.0,
        'total_physical' => 0.0,
    ];

    foreach ($balances as $balance) {
        $quantity = (float) ($balance['quantity'] ?? 0);
        $systemKey = (string) ($balance['system_key'] ?? '');

        $positions['total_physical'] += $quantity;

        if ((int) ($balance['is_system'] ?? 0) === 0 && (int) ($balance['is_active'] ?? 0) === 1) {
            $positions['available_active'] += $quantity;
        } elseif ($systemKey === 'damaged_quarantine') {
            $positions['damaged_quarantine'] += $quantity;
        }
    }

    if ($itemId !== null && $itemId > 0) {
        $positions['held_by_staff'] = (float) Database::scalar(
            'SELECT COALESCE(SUM(GREATEST(hl.quantity_received - hl.quantity_used - hl.quantity_returned, 0)), 0)
             FROM handover_lines hl
             INNER JOIN handovers h ON h.id = hl.handover_id
             WHERE hl.item_id = :item_id
               AND h.recipient_type = "staff"
               AND COALESCE(
                    NULLIF(h.handover_purpose, ""),
                    "temporary_use"
               ) IN ("temporary_use", "staff_custody")
               AND h.status IN ("delivered", "pending_approval")',
            ['item_id' => $itemId]
        );
    }

    return $positions;
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
    $storageScope = current_user_item_storage_scope();
    $historyMetrics = item_history_metrics((int) $item['id'], $storageScope);
    $latestMovement = latest_item_movement((int) $item['id'], $storageScope);
    $balances = item_storage_balances((int) $item['id'], $storageScope);
    $balanceMap = item_balance_map($balances);
    $stockPositions = item_stock_positions($balances, $storageScope === null ? (int) $item['id'] : null);
    $visibleQuantity = $storageScope === null
        ? (float) $item['current_quantity']
        : array_sum(array_map(static fn (array $balance): float => (float) $balance['quantity'], $balances));

    return [
        'item' => [
            'id' => (int) $item['id'],
            'unit' => $item['unit'],
            'current_quantity' => format_quantity($visibleQuantity),
            'current_quantity_raw' => $visibleQuantity,
            'total_used' => format_quantity($historyMetrics['total_used']),
            'total_used_raw' => (float) $historyMetrics['total_used'],
            'total_added' => format_quantity($historyMetrics['total_added']),
            'total_added_raw' => (float) $historyMetrics['total_added'],
            'total_transferred' => format_quantity($historyMetrics['total_transferred'] ?? 0),
            'total_transferred_raw' => (float) ($historyMetrics['total_transferred'] ?? 0),
            'movement_count' => (int) $historyMetrics['movement_count'],
            'cost_per_unit' => format_money($item['cost_per_unit']),
            'cost_per_unit_raw' => (float) $item['cost_per_unit'],
            'stock_value' => format_money(stock_value($visibleQuantity, $item['cost_per_unit'])),
            'stock_positions' => [
                'available_active' => format_quantity($stockPositions['available_active']),
                'available_active_raw' => $stockPositions['available_active'],
                'held_by_staff' => format_quantity($stockPositions['held_by_staff']),
                'held_by_staff_raw' => $stockPositions['held_by_staff'],
                'damaged_quarantine' => format_quantity($stockPositions['damaged_quarantine']),
                'damaged_quarantine_raw' => $stockPositions['damaged_quarantine'],
                'total_physical' => format_quantity($stockPositions['total_physical']),
                'total_physical_raw' => $stockPositions['total_physical'],
            ],
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
