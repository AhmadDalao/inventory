<?php
declare(strict_types=1);

// Domain module: dashboard route handler. Function names are preserved for route/view compatibility.

require_once __DIR__ . '/dashboard_filters.php';
require_once __DIR__ . '/dashboard_metrics.php';

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
    $assetDashboardEnabled = Auth::hasPermission('assets.view');

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
            'assets_total' => $assetDashboardEnabled ? (int) Database::scalar('SELECT COUNT(*) FROM company_assets WHERE is_active = 1') : 0,
            'assets_assigned' => $assetDashboardEnabled ? (int) Database::scalar("SELECT COUNT(*) FROM company_assets WHERE is_active = 1 AND status IN ('assigned', 'pending_receipt', 'return_requested')") : 0,
            'assets_maintenance' => $assetDashboardEnabled ? (int) Database::scalar("SELECT COUNT(*) FROM company_assets WHERE is_active = 1 AND status IN ('maintenance', 'damaged', 'lost')") : 0,
            'assets_value' => $assetDashboardEnabled ? (float) Database::scalar('SELECT COALESCE(SUM(purchase_cost), 0) FROM company_assets WHERE is_active = 1') : 0.0,
        ];
    }

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
    $recentActivity = array_map(
        static fn (array $movement): array => movement_apply_filter_scope($movement, $filters['storage_id']),
        $recentActivity
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
    $workflowSnapshot = workflow_dashboard_snapshot($selectedStorage ? (int) $selectedStorage['id'] : null);
    $dashboardNotifications = function_exists('latest_notifications_for_user') && Auth::check()
        ? latest_notifications_for_user((int) (Auth::user()['id'] ?? 0), 5)
        : [];
    $operationalSnapshot = operational_dashboard_snapshot($selectedStorage ? (int) $selectedStorage['id'] : null);

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
