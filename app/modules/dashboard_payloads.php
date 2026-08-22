<?php
declare(strict_types=1);

// Dashboard payload builders used by the route handler.

function dashboard_staff_payload(): array
{
    $currentUser = Auth::user();
    $staffCards = function_exists('staff_dashboard_handover_cards') && $currentUser
        ? staff_dashboard_handover_cards((int) $currentUser['id'])
        : [];

    return [
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
    ];
}

function dashboard_item_unit_expression(string $alias = 'i'): string
{
    return inventory_item_unit_sql_expression($alias);
}

function dashboard_unit_totals_text(array $totals): string
{
    $parts = [];
    foreach ($totals as $row) {
        $parts[] = format_quantity($row['quantity'] ?? 0) . ' ' . ((string) ($row['unit'] ?? '') ?: 'pcs');
    }

    return $parts === [] ? '0' : implode(' · ', $parts);
}

function dashboard_stock_unit_totals(?int $storageId = null): array
{
    $unitExpression = dashboard_item_unit_expression('i');
    if ($storageId !== null) {
        return Database::fetchAll(
            "SELECT {$unitExpression} AS unit, COALESCE(SUM(balances.quantity), 0) AS quantity
             FROM item_storage_balances balances
             INNER JOIN items i ON i.id = balances.item_id
             WHERE balances.storage_id = :storage_id AND i.is_active = 1
             GROUP BY {$unitExpression}
             ORDER BY unit ASC",
            ['storage_id' => $storageId]
        );
    }

    return Database::fetchAll(
        "SELECT {$unitExpression} AS unit, COALESCE(SUM(i.current_quantity), 0) AS quantity
         FROM items i
         WHERE i.is_active = 1
         GROUP BY {$unitExpression}
         ORDER BY unit ASC"
    );
}

function dashboard_movement_unit_totals(string $movementWhere, array $movementParams, string $movementType): array
{
    $unitExpression = "COALESCE(NULLIF(md.base_unit, ''), " . dashboard_item_unit_expression('i') . ')';

    return Database::fetchAll(
        "SELECT {$unitExpression} AS unit,
                COALESCE(SUM(ABS(COALESCE(md.base_quantity, NULLIF(m.movement_quantity, 0), m.quantity_delta, 0))), 0) AS quantity
         FROM inventory_movements m
         INNER JOIN items i ON i.id = m.item_id
         LEFT JOIN inventory_movement_measurement_details md ON md.movement_id = m.id
         {$movementWhere}
           AND m.movement_type = :dashboard_movement_type
         GROUP BY {$unitExpression}
         ORDER BY unit ASC",
        array_merge($movementParams, ['dashboard_movement_type' => $movementType])
    );
}

function dashboard_summary_metrics(?array $selectedStorage, string $movementWhere, array $movementParams): array
{
    $assetDashboardEnabled = Auth::hasPermission('assets.view');

    if ($selectedStorage) {
        $storageParams = ['storage_id' => (int) $selectedStorage['id']];

        return [
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
            'stock_totals' => dashboard_stock_unit_totals((int) $selectedStorage['id']),
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
            'used_totals' => dashboard_movement_unit_totals($movementWhere, $movementParams, 'usage'),
            'assets_total' => $assetDashboardEnabled ? (int) Database::scalar(
                'SELECT COUNT(*)
                 FROM company_assets
                 WHERE is_active = 1
                   AND storage_id = :storage_id',
                $storageParams
            ) : 0,
            'assets_assigned' => $assetDashboardEnabled ? (int) Database::scalar(
                "SELECT COUNT(*)
                 FROM company_assets
                 WHERE is_active = 1
                   AND storage_id = :storage_id
                   AND status IN ('assigned', 'pending_receipt', 'return_requested')",
                $storageParams
            ) : 0,
            'assets_maintenance' => $assetDashboardEnabled ? (int) Database::scalar(
                "SELECT COUNT(*)
                 FROM company_assets
                 WHERE is_active = 1
                   AND storage_id = :storage_id
                   AND status IN ('maintenance', 'damaged', 'lost')",
                $storageParams
            ) : 0,
            'assets_value' => $assetDashboardEnabled ? (float) Database::scalar(
                'SELECT COALESCE(SUM(purchase_cost), 0)
                 FROM company_assets
                 WHERE is_active = 1
                   AND storage_id = :storage_id',
                $storageParams
            ) : 0.0,
        ];
    }

    return [
        'items_total' => (int) Database::scalar('SELECT COUNT(*) FROM items WHERE is_active = 1'),
        'storages_total' => (int) Database::scalar('SELECT COUNT(*) FROM storages WHERE is_active = 1 AND is_system = 0'),
        'warehouses_total' => (int) Database::scalar('SELECT COUNT(*) FROM storages WHERE is_active = 1 AND is_system = 0 AND storage_type = "warehouse"'),
        'units_total' => (float) Database::scalar('SELECT COALESCE(SUM(current_quantity), 0) FROM items WHERE is_active = 1'),
        'stock_totals' => dashboard_stock_unit_totals(),
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
        'used_totals' => dashboard_movement_unit_totals($movementWhere, $movementParams, 'usage'),
        'assets_total' => $assetDashboardEnabled ? (int) Database::scalar('SELECT COUNT(*) FROM company_assets WHERE is_active = 1') : 0,
        'assets_assigned' => $assetDashboardEnabled ? (int) Database::scalar("SELECT COUNT(*) FROM company_assets WHERE is_active = 1 AND status IN ('assigned', 'pending_receipt', 'return_requested')") : 0,
        'assets_maintenance' => $assetDashboardEnabled ? (int) Database::scalar("SELECT COUNT(*) FROM company_assets WHERE is_active = 1 AND status IN ('maintenance', 'damaged', 'lost')") : 0,
        'assets_value' => $assetDashboardEnabled ? (float) Database::scalar('SELECT COALESCE(SUM(purchase_cost), 0) FROM company_assets WHERE is_active = 1') : 0.0,
    ];
}

function dashboard_recent_activity(array $filters, string $movementWhere, array $movementParams): array
{
    $recentActivity = Database::fetchAll(
        "SELECT m.*,
                COALESCE(i.name, CONCAT('Item #', m.item_id)) AS item_name,
                COALESCE(i.sku, '') AS sku,
                COALESCE(i.unit, '') AS unit,
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
         {$movementWhere}
         ORDER BY m.used_at DESC, m.id DESC
         LIMIT 10",
        $movementParams
    );

    return array_map(
        static fn (array $movement): array => movement_apply_filter_scope($movement, $filters['storage_id']),
        $recentActivity
    );
}

function dashboard_top_usage(string $movementWhere, array $movementParams): array
{
    return Database::fetchAll(
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
}

function dashboard_low_stock_items(?array $selectedStorage): array
{
    if ($selectedStorage) {
        return Database::fetchAll(
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
    }

    return Database::fetchAll(
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
