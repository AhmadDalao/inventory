<?php
declare(strict_types=1);

// Daily operations report summary query builders.

function report_summary_usage_reason_groups(array $filters): array
{
    $usageFilters = $filters;
    $usageFilters['movement_type'] = 'usage';
    [$usageWhere, $usageParams] = build_report_summary_where($usageFilters);
    $reasonWhere = $usageWhere . " AND m.context_type = 'handover'";

    $rows = Database::fetchAll(
        "SELECT m.item_id,
                COALESCE(i.unit, 'pcs') AS unit,
                hub.reason_code,
                hub.reason_custom,
                hub.notes,
                COALESCE(SUM(hub.quantity), 0) AS quantity
         FROM inventory_movements m
         INNER JOIN handover_usage_breakdowns hub
            ON hub.handover_id = m.context_id
           AND hub.item_id = m.item_id
         LEFT JOIN items i ON i.id = m.item_id
         {$reasonWhere}
         GROUP BY m.item_id, i.unit, hub.reason_code, hub.reason_custom, hub.notes
         HAVING quantity > 0
         ORDER BY m.item_id ASC, quantity DESC",
        $usageParams
    );

    $groups = [];

    foreach ($rows as $row) {
        $itemId = (int) ($row['item_id'] ?? 0);

        if ($itemId <= 0) {
            continue;
        }

        $groups[$itemId][] = [
            'label' => handover_usage_reason_label((string) ($row['reason_code'] ?? 'unspecified'), (string) ($row['reason_custom'] ?? '')),
            'quantity' => (float) ($row['quantity'] ?? 0),
            'unit' => (string) ($row['unit'] ?: 'pcs'),
            'notes' => trim((string) ($row['notes'] ?? '')),
        ];
    }

    return $groups;
}

function report_summary_data(array $filters): array
{
    [$where, $params] = build_report_summary_where($filters);
    $quantity = report_summary_quantity_expression();

    $cards = Database::fetch(
        "SELECT COUNT(*) AS movement_count,
                COUNT(DISTINCT m.item_id) AS item_count,
                COUNT(DISTINCT m.performed_by) AS user_count,
                COALESCE(SUM(CASE WHEN m.movement_type = 'usage' THEN {$quantity} ELSE 0 END), 0) AS used_units,
                COALESCE(SUM(CASE WHEN m.movement_type = 'restock' THEN {$quantity} ELSE 0 END), 0) AS restocked_units,
                COALESCE(SUM(CASE WHEN m.movement_type = 'transfer' THEN {$quantity} ELSE 0 END), 0) AS transferred_units,
                COALESCE(SUM(CASE WHEN m.movement_type = 'adjustment' THEN {$quantity} ELSE 0 END), 0) AS adjusted_units
         FROM inventory_movements m
         {$where}",
        $params
    ) ?: [];

    $usageFilters = $filters;
    $usageFilters['movement_type'] = 'usage';
    [$usageWhere, $usageParams] = build_report_summary_where($usageFilters);
    $usageQuantity = report_summary_quantity_expression();

    $usageByItem = Database::fetchAll(
        "SELECT m.item_id,
                COALESCE(i.name, CONCAT('Item #', m.item_id)) AS item_name,
                COALESCE(i.sku, '') AS sku,
                COALESCE(i.unit, '') AS unit,
                COALESCE(i.barcode, '') AS barcode,
                i.is_active AS item_is_active,
                i.image_path,
                COALESCE(SUM({$usageQuantity}), 0) AS used_quantity,
                COUNT(*) AS movement_count,
                GROUP_CONCAT(DISTINCT COALESCE(u.name, 'System') ORDER BY COALESCE(u.name, 'System') SEPARATOR ', ') AS users,
                GROUP_CONCAT(DISTINCT COALESCE(source_storage.name, destination_storage.name, 'Unassigned') ORDER BY COALESCE(source_storage.name, destination_storage.name, 'Unassigned') SEPARATOR ', ') AS locations,
                MAX(m.used_at) AS last_activity_at,
                GROUP_CONCAT(DISTINCT NULLIF(m.reference_code, '') ORDER BY m.reference_code SEPARATOR ', ') AS references_list
         FROM inventory_movements m
         LEFT JOIN items i ON i.id = m.item_id
         LEFT JOIN storages source_storage ON source_storage.id = m.source_storage_id
         LEFT JOIN storages destination_storage ON destination_storage.id = m.destination_storage_id
         LEFT JOIN users u ON u.id = m.performed_by
         {$usageWhere}
         GROUP BY m.item_id, i.name, i.sku, i.unit, i.barcode, i.is_active, i.image_path
         ORDER BY used_quantity DESC, item_name ASC
         LIMIT 50",
        $usageParams
    );
    $usageReasonGroups = report_summary_usage_reason_groups($filters);

    foreach ($usageByItem as &$usageRow) {
        $usageRow['usage_reasons'] = $usageReasonGroups[(int) ($usageRow['item_id'] ?? 0)] ?? [];
    }

    unset($usageRow);

    $userBreakdown = Database::fetchAll(
        "SELECT COALESCE(u.name, 'System') AS user_name,
                COUNT(*) AS movement_count,
                COUNT(DISTINCT m.item_id) AS item_count,
                COALESCE(SUM(CASE WHEN m.movement_type = 'usage' THEN {$quantity} ELSE 0 END), 0) AS used_units,
                COALESCE(SUM(CASE WHEN m.movement_type = 'restock' THEN {$quantity} ELSE 0 END), 0) AS restocked_units,
                COALESCE(SUM(CASE WHEN m.movement_type = 'transfer' THEN {$quantity} ELSE 0 END), 0) AS transferred_units,
                COALESCE(SUM(CASE WHEN m.movement_type = 'adjustment' THEN {$quantity} ELSE 0 END), 0) AS adjusted_units,
                MAX(m.used_at) AS last_activity_at
         FROM inventory_movements m
         LEFT JOIN users u ON u.id = m.performed_by
         {$where}
         GROUP BY COALESCE(u.name, 'System')
         ORDER BY movement_count DESC, user_name ASC
         LIMIT 30",
        $params
    );

    $timeline = Database::fetchAll(
        "SELECT m.*,
                COALESCE(i.name, CONCAT('Item #', m.item_id)) AS item_name,
                COALESCE(i.sku, '') AS sku,
                COALESCE(i.unit, '') AS unit,
                COALESCE(i.barcode, '') AS barcode,
                i.is_active AS item_is_active,
                i.image_path,
                source_storage.name AS source_storage_name,
                destination_storage.name AS destination_storage_name,
                COALESCE(u.name, 'System') AS user_name
         FROM inventory_movements m
         LEFT JOIN items i ON i.id = m.item_id
         LEFT JOIN storages source_storage ON source_storage.id = m.source_storage_id
         LEFT JOIN storages destination_storage ON destination_storage.id = m.destination_storage_id
         LEFT JOIN users u ON u.id = m.performed_by
         {$where}
         ORDER BY m.used_at DESC, m.id DESC
         LIMIT 120",
        $params
    );
    $timeline = array_map(
        static fn (array $movement): array => movement_apply_filter_scope($movement, $filters['storage_id'] ?? null),
        $timeline
    );

    $query = array_filter([
        'date' => $filters['date'],
        'storage_id' => $filters['storage_id'] ?? null,
        'movement_type' => $filters['movement_type'] ?? '',
        'item_status' => ($filters['item_status'] ?? 'all') !== 'all' ? $filters['item_status'] : null,
    ], static fn ($value): bool => $value !== '' && $value !== null);

    $movementQuery = array_filter([
        'date_from' => $filters['date'],
        'date_to' => $filters['date'],
        'storage_id' => $filters['storage_id'] ?? null,
        'movement_type' => $filters['movement_type'] ?? '',
    ], static fn ($value): bool => $value !== '' && $value !== null);

    return [
        'cards' => [
            'movement_count' => (int) ($cards['movement_count'] ?? 0),
            'item_count' => (int) ($cards['item_count'] ?? 0),
            'user_count' => (int) ($cards['user_count'] ?? 0),
            'used_units' => (float) ($cards['used_units'] ?? 0),
            'restocked_units' => (float) ($cards['restocked_units'] ?? 0),
            'transferred_units' => (float) ($cards['transferred_units'] ?? 0),
            'adjusted_units' => (float) ($cards['adjusted_units'] ?? 0),
        ],
        'usage_by_item' => $usageByItem,
        'user_breakdown' => $userBreakdown,
        'timeline' => $timeline,
        'storage_label' => report_summary_storage_label($filters['storage_id'] ?? null),
        'export_url' => url('/exports/daily-summary' . ($query ? '?' . http_build_query($query) : '')),
        'export_xlsx_url' => url('/exports/daily-summary.xlsx' . ($query ? '?' . http_build_query($query) : '')),
        'movement_url' => url('/movements' . ($movementQuery ? '?' . http_build_query($movementQuery) : '')),
    ];
}
