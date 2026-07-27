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

function report_summary_usage_reason_groups_by_day(array $filters): array
{
    $usageFilters = $filters;
    $usageFilters['movement_type'] = 'usage';
    [$usageWhere, $usageParams] = build_report_summary_where($usageFilters);
    $reasonWhere = $usageWhere . " AND m.context_type = 'handover'";

    $rows = Database::fetchAll(
        "SELECT DATE(m.used_at) AS usage_date,
                m.item_id,
                m.context_id AS handover_id,
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
         GROUP BY DATE(m.used_at), m.item_id, m.context_id, i.unit, hub.reason_code, hub.reason_custom, hub.notes
         HAVING quantity > 0
         ORDER BY usage_date DESC, m.item_id ASC, quantity DESC",
        $usageParams
    );

    $groups = [];

    foreach ($rows as $row) {
        $itemId = (int) ($row['item_id'] ?? 0);
        $handoverId = (int) ($row['handover_id'] ?? 0);
        $usageDate = trim((string) ($row['usage_date'] ?? ''));

        if ($itemId <= 0 || $usageDate === '') {
            continue;
        }

        $groups[$usageDate . ':' . $itemId . ':' . $handoverId][] = [
            'label' => handover_usage_reason_label((string) ($row['reason_code'] ?? 'unspecified'), (string) ($row['reason_custom'] ?? '')),
            'quantity' => (float) ($row['quantity'] ?? 0),
            'unit' => (string) ($row['unit'] ?: 'pcs'),
            'notes' => trim((string) ($row['notes'] ?? '')),
        ];
    }

    return $groups;
}

function report_summary_usage_reason_text(array $reasons, string $fallbackUnit = 'pcs'): string
{
    $parts = [];

    foreach ($reasons as $reason) {
        $label = trim((string) ($reason['label'] ?? 'Unspecified'));
        $quantity = format_quantity($reason['quantity'] ?? 0);
        $unit = trim((string) ($reason['unit'] ?? $fallbackUnit)) ?: $fallbackUnit;
        $notes = trim((string) ($reason['notes'] ?? ''));
        $parts[] = $label . ' ' . $quantity . ' ' . $unit . ($notes !== '' ? ' (' . $notes . ')' : '');
    }

    return implode('; ', $parts);
}

function report_summary_usage_notes_text(array $row): string
{
    $reasonNotes = [];

    foreach ((array) ($row['usage_reasons'] ?? []) as $reason) {
        $notes = trim((string) ($reason['notes'] ?? ''));

        if ($notes === '') {
            continue;
        }

        $label = trim((string) ($reason['label'] ?? 'Usage')) ?: 'Usage';
        $reasonNotes[] = $label . ': ' . $notes;
    }

    if ($reasonNotes !== []) {
        return implode('; ', array_values(array_unique($reasonNotes)));
    }

    $movementNotes = trim((string) ($row['notes_list'] ?? ''));

    if (preg_match('/^Consumed during handover\.?\s*Usage:/i', $movementNotes) === 1) {
        return '';
    }

    return $movementNotes;
}

function report_summary_operational_usage(array $filters): array
{
    $movementType = trim((string) ($filters['movement_type'] ?? ''));

    if ($movementType !== '' && $movementType !== 'usage') {
        return [];
    }

    $conditions = [
        "h.usage_reporting_mode = 'operational_summary'",
        "COALESCE(h.recipient_type, 'staff') = 'staff'",
        'r.approved_at IS NOT NULL',
    ];
    $params = [];
    $dateExpression = 'COALESCE(h.scheduled_for_date, DATE(COALESCE(r.approved_at, r.submitted_at, r.updated_at)))';
    $dateFrom = trim((string) ($filters['date_from'] ?? ''));
    $dateTo = trim((string) ($filters['date_to'] ?? ''));

    if ($dateFrom !== '') {
        $conditions[] = $dateExpression . ' >= :operational_date_from';
        $params['operational_date_from'] = $dateFrom;
    }

    if ($dateTo !== '') {
        $conditions[] = $dateExpression . ' <= :operational_date_to';
        $params['operational_date_to'] = $dateTo;
    }

    if ((int) ($filters['storage_id'] ?? 0) > 0) {
        $conditions[] = 'h.source_storage_id = :operational_storage_id';
        $params['operational_storage_id'] = (int) $filters['storage_id'];
    }

    $rows = Database::fetchAll(
        'SELECT r.id AS reconciliation_id,
                r.handover_id,
                h.handover_number,
                ' . $dateExpression . ' AS activity_date,
                COALESCE(r.approved_at, r.submitted_at, r.updated_at) AS activity_at,
                r.unit,
                r.issued_total,
                r.received_total,
                r.returned_total,
                r.physical_used_total,
                r.operational_used_total,
                r.difference_total,
                COALESCE(r.discrepancy_notes, "") AS discrepancy_notes,
                COALESCE(r.variance_reason_code, "") AS variance_reason_code,
                COALESCE(r.variance_notes, "") AS variance_notes,
                COALESCE(source_storage.name, "Unassigned") AS source_storage_name,
                COALESCE(receiver.name, NULLIF(h.recipient_name, ""), "Unassigned") AS receiver_name,
                COALESCE(reconciliation_approver.name, handover_approver.name, assigned_approver.name, "Unassigned") AS approver_name,
                COALESCE(SUM(CASE WHEN e.reason_code = "online" THEN e.quantity ELSE 0 END), 0) AS online_quantity,
                COALESCE(SUM(CASE WHEN e.reason_code = "walkin" THEN e.quantity ELSE 0 END), 0) AS walkin_quantity,
                COALESCE(SUM(CASE WHEN e.reason_code = "event" THEN e.quantity ELSE 0 END), 0) AS event_quantity,
                COALESCE(SUM(CASE WHEN e.reason_code = "sport" THEN e.quantity ELSE 0 END), 0) AS sport_quantity,
                COALESCE(SUM(CASE WHEN e.reason_code = "damage" THEN e.quantity ELSE 0 END), 0) AS damage_quantity,
                COALESCE(SUM(CASE WHEN e.reason_code = "complimentary" THEN e.quantity ELSE 0 END), 0) AS complimentary_quantity,
                COALESCE(SUM(CASE WHEN e.reason_code = "noshow" THEN e.quantity ELSE 0 END), 0) AS noshow_quantity,
                COALESCE(SUM(CASE WHEN e.reason_code = "other" THEN e.quantity ELSE 0 END), 0) AS other_quantity
         FROM handover_reconciliations r
         INNER JOIN handovers h ON h.id = r.handover_id
         LEFT JOIN handover_reconciliation_entries e ON e.reconciliation_id = r.id
         LEFT JOIN storages source_storage ON source_storage.id = h.source_storage_id
         LEFT JOIN users receiver ON receiver.id = h.recipient_user_id
         LEFT JOIN users reconciliation_approver ON reconciliation_approver.id = r.approved_by
         LEFT JOIN users handover_approver ON handover_approver.id = h.approved_by
         LEFT JOIN users assigned_approver ON assigned_approver.id = h.approver_user_id
         WHERE ' . implode(' AND ', $conditions) . '
         GROUP BY r.id,
                  r.handover_id,
                  h.handover_number,
                  activity_date,
                  activity_at,
                  r.unit,
                  r.issued_total,
                  r.received_total,
                  r.returned_total,
                  r.physical_used_total,
                  r.operational_used_total,
                  r.difference_total,
                  r.discrepancy_notes,
                  r.variance_reason_code,
                  r.variance_notes,
                  source_storage.name,
                  receiver.name,
                  h.recipient_name,
                  reconciliation_approver.name,
                  handover_approver.name,
                  assigned_approver.name
         ORDER BY activity_at DESC, h.handover_number DESC, r.unit ASC',
        $params
    );
    $varianceOptions = handover_reconciliation_variance_reason_options();

    foreach ($rows as &$row) {
        $varianceCode = (string) ($row['variance_reason_code'] ?? '');
        $row['variance_reason_label'] = $varianceCode !== ''
            ? ($varianceOptions[$varianceCode] ?? $varianceCode)
            : '';
    }

    unset($row);

    return $rows;
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
                GROUP_CONCAT(
                    DISTINCT CASE
                        WHEN usage_handover.id IS NOT NULL
                            THEN COALESCE(recipient_user.name, NULLIF(usage_handover.recipient_name, ''), 'Unassigned')
                        ELSE COALESCE(u.name, 'System')
                    END
                    ORDER BY CASE
                        WHEN usage_handover.id IS NOT NULL
                            THEN COALESCE(recipient_user.name, NULLIF(usage_handover.recipient_name, ''), 'Unassigned')
                        ELSE COALESCE(u.name, 'System')
                    END
                    SEPARATOR ', '
                ) AS users,
                GROUP_CONCAT(
                    DISTINCT CASE
                        WHEN usage_handover.id IS NOT NULL
                            THEN COALESCE(approved_user.name, assigned_approver.name, u.name, 'Unassigned')
                        ELSE ''
                    END
                    ORDER BY CASE
                        WHEN usage_handover.id IS NOT NULL
                            THEN COALESCE(approved_user.name, assigned_approver.name, u.name, 'Unassigned')
                        ELSE ''
                    END
                    SEPARATOR ', '
                ) AS approvers,
                GROUP_CONCAT(
                    DISTINCT CASE
                        WHEN usage_handover.id IS NOT NULL
                            THEN COALESCE(handover_source.name, handover_destination.name, 'Unassigned')
                        ELSE COALESCE(source_storage.name, destination_storage.name, 'Unassigned')
                    END
                    ORDER BY CASE
                        WHEN usage_handover.id IS NOT NULL
                            THEN COALESCE(handover_source.name, handover_destination.name, 'Unassigned')
                        ELSE COALESCE(source_storage.name, destination_storage.name, 'Unassigned')
                    END
                    SEPARATOR ', '
                ) AS locations,
                MAX(m.used_at) AS last_activity_at,
                GROUP_CONCAT(DISTINCT NULLIF(m.reference_code, '') ORDER BY m.reference_code SEPARATOR ', ') AS references_list
         FROM inventory_movements m
         LEFT JOIN items i ON i.id = m.item_id
         LEFT JOIN storages source_storage ON source_storage.id = m.source_storage_id
         LEFT JOIN storages destination_storage ON destination_storage.id = m.destination_storage_id
         LEFT JOIN users u ON u.id = m.performed_by
         LEFT JOIN handovers usage_handover ON usage_handover.id = m.context_id AND m.context_type = 'handover'
         LEFT JOIN users recipient_user ON recipient_user.id = usage_handover.recipient_user_id
         LEFT JOIN users approved_user ON approved_user.id = usage_handover.approved_by
         LEFT JOIN users assigned_approver ON assigned_approver.id = usage_handover.approver_user_id
         LEFT JOIN storages handover_source ON handover_source.id = usage_handover.source_storage_id
         LEFT JOIN storages handover_destination ON handover_destination.id = usage_handover.destination_storage_id
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

    $usageByDay = Database::fetchAll(
        "SELECT DATE(m.used_at) AS usage_date,
                m.item_id,
                COALESCE(m.context_type, '') AS context_type,
                COALESCE(m.context_id, 0) AS context_id,
                COALESCE(i.name, CONCAT('Item #', m.item_id)) AS item_name,
                COALESCE(i.sku, '') AS sku,
                COALESCE(i.unit, '') AS unit,
                COALESCE(i.barcode, '') AS barcode,
                i.is_active AS item_is_active,
                i.image_path,
                COALESCE(SUM({$usageQuantity}), 0) AS used_quantity,
                COUNT(*) AS movement_count,
                CASE
                    WHEN usage_handover.id IS NOT NULL
                        THEN COALESCE(recipient_user.name, NULLIF(usage_handover.recipient_name, ''), 'Unassigned')
                    ELSE COALESCE(u.name, 'System')
                END AS staff_name,
                CASE
                    WHEN usage_handover.id IS NOT NULL
                        THEN COALESCE(approved_user.name, assigned_approver.name, u.name, 'Unassigned')
                    ELSE ''
                END AS approver_name,
                CASE
                    WHEN usage_handover.id IS NOT NULL
                        THEN COALESCE(handover_source.name, handover_destination.name, 'Unassigned')
                    ELSE COALESCE(source_storage.name, destination_storage.name, 'Unassigned')
                END AS usage_location,
                MIN(m.used_at) AS first_activity_at,
                MAX(m.used_at) AS last_activity_at,
                GROUP_CONCAT(DISTINCT NULLIF(m.reference_code, '') ORDER BY m.reference_code SEPARATOR ', ') AS references_list,
                GROUP_CONCAT(DISTINCT NULLIF(TRIM(m.notes), '') ORDER BY m.notes SEPARATOR ' | ') AS notes_list
         FROM inventory_movements m
         LEFT JOIN items i ON i.id = m.item_id
         LEFT JOIN storages source_storage ON source_storage.id = m.source_storage_id
         LEFT JOIN storages destination_storage ON destination_storage.id = m.destination_storage_id
         LEFT JOIN users u ON u.id = m.performed_by
         LEFT JOIN handovers usage_handover ON usage_handover.id = m.context_id AND m.context_type = 'handover'
         LEFT JOIN users recipient_user ON recipient_user.id = usage_handover.recipient_user_id
         LEFT JOIN users approved_user ON approved_user.id = usage_handover.approved_by
         LEFT JOIN users assigned_approver ON assigned_approver.id = usage_handover.approver_user_id
         LEFT JOIN storages handover_source ON handover_source.id = usage_handover.source_storage_id
         LEFT JOIN storages handover_destination ON handover_destination.id = usage_handover.destination_storage_id
         {$usageWhere}
         GROUP BY DATE(m.used_at),
                  m.item_id,
                  COALESCE(m.context_type, ''),
                  COALESCE(m.context_id, 0),
                  i.name,
                  i.sku,
                  i.unit,
                  i.barcode,
                  i.is_active,
                  i.image_path,
                  staff_name,
                  approver_name,
                  usage_location
         ORDER BY usage_date DESC, last_activity_at DESC, used_quantity DESC, item_name ASC",
        $usageParams
    );
    $usageReasonGroupsByDay = report_summary_usage_reason_groups_by_day($filters);

    foreach ($usageByDay as &$usageDayRow) {
        $usageKey = (string) ($usageDayRow['usage_date'] ?? '')
            . ':' . (int) ($usageDayRow['item_id'] ?? 0)
            . ':' . (int) ($usageDayRow['context_id'] ?? 0);
        $usageDayRow['usage_reasons'] = $usageReasonGroupsByDay[$usageKey] ?? [];
    }

    unset($usageDayRow);
    $operationalUsage = report_summary_operational_usage($filters);

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
        'date_from' => $filters['date_from'],
        'date_to' => $filters['date_to'],
        'storage_id' => $filters['storage_id'] ?? null,
        'movement_type' => $filters['movement_type'] ?? '',
        'item_status' => ($filters['item_status'] ?? 'all') !== 'all' ? $filters['item_status'] : null,
    ], static fn ($value): bool => $value !== '' && $value !== null);

    $movementQuery = array_filter([
        'date_from' => $filters['date_from'],
        'date_to' => $filters['date_to'],
        'storage_id' => $filters['storage_id'] ?? null,
        'movement_type' => $filters['movement_type'] ?? '',
    ], static fn ($value): bool => $value !== '' && $value !== null);
    $usageExportQuery = $query;
    $usageExportQuery['movement_type'] = 'usage';
    $usageExportQuery['report_scope'] = 'usage_by_day';
    $operationalExportQuery = $query;
    $operationalExportQuery['movement_type'] = 'usage';
    $operationalExportQuery['report_scope'] = 'operational_usage';

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
        'usage_by_day' => $usageByDay,
        'operational_usage' => $operationalUsage,
        'user_breakdown' => $userBreakdown,
        'timeline' => $timeline,
        'storage_label' => report_summary_storage_label($filters['storage_id'] ?? null),
        'export_url' => url('/exports/daily-summary' . ($query ? '?' . http_build_query($query) : '')),
        'export_xlsx_url' => url('/exports/daily-summary.xlsx' . ($query ? '?' . http_build_query($query) : '')),
        'usage_export_url' => url('/exports/daily-summary?' . http_build_query($usageExportQuery)),
        'usage_export_xlsx_url' => url('/exports/daily-summary.xlsx?' . http_build_query($usageExportQuery)),
        'operational_export_url' => url('/exports/daily-summary?' . http_build_query($operationalExportQuery)),
        'operational_export_xlsx_url' => url('/exports/daily-summary.xlsx?' . http_build_query($operationalExportQuery)),
        'movement_url' => url('/movements' . ($movementQuery ? '?' . http_build_query($movementQuery) : '')),
    ];
}
