<?php
declare(strict_types=1);

// Domain module: dashboard. Function names are preserved for route/view compatibility.

// Moved from controllers.php.

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

// Moved from workflows.php.

function workflow_dashboard_snapshot(?int $storageId = null): array
{
    $requestParams = [];
    $handoverParams = [];
    $purchaseParams = [];
    $requestStorageClause = '';
    $handoverStorageClause = '';
    $purchaseStorageClause = '';
    [$requestScopeSql, $requestScopeParams] = visible_request_scope('r');
    [$handoverScopeSql, $handoverScopeParams] = visible_handover_scope('h');

    if ($storageId !== null) {
        $requestStorageClause = ' AND (r.source_storage_id = :workflow_source_storage_id OR r.destination_storage_id = :workflow_destination_storage_id)';
        $handoverStorageClause = ' AND h.source_storage_id = :workflow_storage_id';
        $purchaseStorageClause = ' AND p.destination_storage_id = :workflow_purchase_storage_id';
        $requestParams['workflow_source_storage_id'] = $storageId;
        $requestParams['workflow_destination_storage_id'] = $storageId;
        $handoverParams['workflow_storage_id'] = $storageId;
        $purchaseParams['workflow_purchase_storage_id'] = $storageId;
    }

    $purchaseViewEnabled = Auth::hasPermission('purchases.view');

    return [
        'open_requests' => (int) Database::scalar(
            "SELECT COUNT(*)
             FROM item_requests r
             WHERE r.status IN ('pending', 'approved', 'receipt_review'){$requestStorageClause}{$requestScopeSql}",
            $requestParams + $requestScopeParams
        ),
        'open_handovers' => (int) Database::scalar(
            "SELECT COUNT(*)
             FROM handovers h
             WHERE h.status IN ('requested', 'awaiting_receipt', 'receipt_review', 'delivered', 'pending_approval'){$handoverStorageClause}{$handoverScopeSql}",
            $handoverParams + $handoverScopeParams
        ),
        'recent_requests' => Database::fetchAll(
            "SELECT r.id,
                    r.request_number,
                    r.request_mode,
                    r.status,
                    r.requested_at,
                    requester.name AS requester_name,
                    source_storage.name AS source_storage_name,
                    destination_storage.name AS destination_storage_name,
                    COALESCE(line_totals.total_requested, 0) AS total_requested
             FROM item_requests r
             INNER JOIN users requester ON requester.id = r.requester_user_id
             INNER JOIN storages source_storage ON source_storage.id = r.source_storage_id
             LEFT JOIN storages destination_storage ON destination_storage.id = r.destination_storage_id
             LEFT JOIN (
                 SELECT request_id,
                        COALESCE(SUM(quantity_requested), 0) AS total_requested
                 FROM item_request_lines
                 GROUP BY request_id
             ) line_totals ON line_totals.request_id = r.id
             WHERE r.status IN ('pending', 'approved', 'receipt_review'){$requestStorageClause}{$requestScopeSql}
             ORDER BY r.requested_at DESC, r.id DESC
             LIMIT 5",
            $requestParams + $requestScopeParams
        ),
        'recent_handovers' => Database::fetchAll(
            "SELECT h.id,
                    h.handover_number,
                    h.status,
                    h.issued_at,
                    h.recipient_name,
                    source_storage.name AS source_storage_name,
                    COALESCE(line_totals.total_handed, 0) AS total_handed
             FROM handovers h
             INNER JOIN storages source_storage ON source_storage.id = h.source_storage_id
             LEFT JOIN (
                 SELECT handover_id,
                        COALESCE(SUM(quantity_handed), 0) AS total_handed
                 FROM handover_lines
                 GROUP BY handover_id
             ) line_totals ON line_totals.handover_id = h.id
             WHERE h.status IN ('requested', 'awaiting_receipt', 'receipt_review', 'delivered', 'pending_approval'){$handoverStorageClause}{$handoverScopeSql}
             ORDER BY h.issued_at DESC, h.id DESC
             LIMIT 5",
            $handoverParams + $handoverScopeParams
        ),
        'open_purchases' => $purchaseViewEnabled ? (int) Database::scalar(
            "SELECT COUNT(*)
             FROM purchases p
             WHERE p.status IN ('pending_approval', 'approved', 'receipt_review'){$purchaseStorageClause}",
            $purchaseParams
        ) : 0,
        'pending_purchase_approvals' => $purchaseViewEnabled ? (int) Database::scalar(
            "SELECT COUNT(*)
             FROM purchases p
             WHERE p.status = 'pending_approval'{$purchaseStorageClause}",
            $purchaseParams
        ) : 0,
        'pending_purchase_receiving' => $purchaseViewEnabled ? (int) Database::scalar(
            "SELECT COUNT(*)
             FROM purchases p
             WHERE p.status IN ('approved', 'receipt_review'){$purchaseStorageClause}",
            $purchaseParams
        ) : 0,
        'recent_purchases' => $purchaseViewEnabled ? Database::fetchAll(
            "SELECT p.id,
                    p.purchase_number,
                    p.status,
                    p.currency,
                    p.created_at,
                    p.expected_date,
                    supplier.name AS supplier_name,
                    storage.name AS storage_name,
                    COALESCE(line_totals.total_value, 0) AS total_value
             FROM purchases p
             INNER JOIN suppliers supplier ON supplier.id = p.supplier_id
             INNER JOIN storages storage ON storage.id = p.destination_storage_id
             LEFT JOIN (
                 SELECT purchase_id,
                        COALESCE(SUM(CASE WHEN quantity_final > 0 THEN quantity_final ELSE quantity_approved END * unit_cost_approved), 0) AS total_value
                 FROM purchase_lines
                 GROUP BY purchase_id
             ) line_totals ON line_totals.purchase_id = p.id
             WHERE p.status IN ('pending_approval', 'approved', 'receipt_review', 'completed'){$purchaseStorageClause}
             ORDER BY p.created_at DESC, p.id DESC
             LIMIT 5",
            $purchaseParams
        ) : [],
        'purchase_value_by_storage' => $purchaseViewEnabled ? Database::fetchAll(
            "SELECT storage.id,
                    storage.name,
                    COALESCE(SUM(pl.quantity_final * pl.unit_cost_approved), 0) AS total_value
             FROM purchases p
             INNER JOIN storages storage ON storage.id = p.destination_storage_id
             INNER JOIN purchase_lines pl ON pl.purchase_id = p.id
             WHERE p.status = 'completed'{$purchaseStorageClause}
             GROUP BY storage.id, storage.name
             ORDER BY total_value DESC
             LIMIT 6",
            $purchaseParams
        ) : [],
    ];
}

function operational_dashboard_snapshot(?int $storageId = null): array
{
    $stocktakeStorageClause = '';
    $stocktakeAliasStorageClause = '';
    $stocktakeParams = [];
    $reorderFilters = [
        'search' => '',
        'storage_id' => $storageId,
        'include_zero_policy' => false,
    ];

    if ($storageId !== null) {
        $stocktakeStorageClause = ' AND storage_id = :stocktake_storage_id';
        $stocktakeAliasStorageClause = ' AND stocktake.storage_id = :stocktake_storage_id';
        $stocktakeParams['stocktake_storage_id'] = $storageId;
    }

    $reorderRows = Auth::hasPermission('reorder.view') ? reorder_suggestion_rows($reorderFilters) : [];

    return [
        'open_stocktakes' => Auth::hasPermission('stocktakes.view') ? (int) Database::scalar(
            "SELECT COUNT(*)
             FROM stocktakes
             WHERE status IN ('draft', 'pending_approval'){$stocktakeStorageClause}",
            $stocktakeParams
        ) : 0,
        'pending_stocktake_approvals' => Auth::hasPermission('stocktakes.view') ? (int) Database::scalar(
            "SELECT COUNT(*)
             FROM stocktakes
             WHERE status = 'pending_approval'{$stocktakeStorageClause}",
            $stocktakeParams
        ) : 0,
        'reorder_lines' => count($reorderRows),
        'reorder_value' => array_reduce($reorderRows, static fn (float $carry, array $row): float => $carry + ((float) $row['suggested_quantity'] * (float) $row['cost_per_unit']), 0.0),
        'recent_stocktakes' => Auth::hasPermission('stocktakes.view') ? Database::fetchAll(
            "SELECT stocktake.id,
                    stocktake.stocktake_number,
                    stocktake.status,
                    stocktake.created_at,
                    storage.name AS storage_name,
                    COALESCE(line_totals.total_variance, 0) AS total_variance
             FROM stocktakes stocktake
             INNER JOIN storages storage ON storage.id = stocktake.storage_id
             LEFT JOIN (
                 SELECT stocktake_id, COALESCE(SUM(variance_quantity), 0) AS total_variance
                 FROM stocktake_lines
                 GROUP BY stocktake_id
             ) line_totals ON line_totals.stocktake_id = stocktake.id
             WHERE stocktake.status IN ('draft', 'pending_approval'){$stocktakeAliasStorageClause}
             ORDER BY stocktake.created_at DESC, stocktake.id DESC
             LIMIT 5",
            $stocktakeParams
        ) : [],
    ];
}
