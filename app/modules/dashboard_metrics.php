<?php
declare(strict_types=1);

// Dashboard metric builders and workflow snapshots.

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
