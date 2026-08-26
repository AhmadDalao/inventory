<?php
declare(strict_types=1);

// Daily operations report summary query builders.

function report_summary_latest_closed_handover_usage_join_sql(): string
{
    return "INNER JOIN handovers report_handover
                ON report_handover.id = m.context_id
               AND report_handover.status = 'closed'
            INNER JOIN (
                SELECT context_id, item_id, MAX(id) AS movement_id
                FROM inventory_movements
                WHERE context_type = 'handover'
                  AND movement_type = 'usage'
                GROUP BY context_id, item_id
            ) latest_handover_usage
                ON latest_handover_usage.movement_id = m.id";
}

function report_summary_current_stock_usage_condition_sql(string $movementAlias = 'm'): string
{
    if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $movementAlias) !== 1) {
        throw new InvalidArgumentException('Invalid movement table alias.');
    }

    return "(
        {$movementAlias}.movement_type <> 'usage'
        OR COALESCE({$movementAlias}.context_type, '') <> 'handover'
        OR (
            EXISTS (
                SELECT 1
                FROM handovers current_usage_handover
                WHERE current_usage_handover.id = {$movementAlias}.context_id
                  AND current_usage_handover.status = 'closed'
            )
            AND {$movementAlias}.id = (
                SELECT MAX(current_usage_movement.id)
                FROM inventory_movements current_usage_movement
                WHERE current_usage_movement.context_type = 'handover'
                  AND current_usage_movement.movement_type = 'usage'
                  AND current_usage_movement.context_id = {$movementAlias}.context_id
                  AND current_usage_movement.item_id = {$movementAlias}.item_id
            )
        )
    )";
}

function report_summary_usage_reason_groups(array $filters): array
{
    $usageFilters = $filters;
    $usageFilters['movement_type'] = 'usage';
    [$usageWhere, $usageParams] = build_report_summary_where($usageFilters);
    $currentUsageWhere = $usageWhere . ' AND ' . report_summary_current_stock_usage_condition_sql('m');
    $reasonWhere = $usageWhere . " AND m.context_type = 'handover'";

    $legacyUnit = inventory_item_unit_sql_expression('i');
    $legacyRows = Database::fetchAll(
        "SELECT m.item_id,
                {$legacyUnit} AS unit,
                hub.reason_code,
                hub.reason_custom,
                hub.notes,
                COALESCE(SUM(hub.quantity), 0) AS quantity
         FROM inventory_movements m
         " . report_summary_latest_closed_handover_usage_join_sql() . "
         INNER JOIN handover_usage_breakdowns hub
            ON hub.handover_id = m.context_id
           AND hub.item_id = m.item_id
         LEFT JOIN items i ON i.id = m.item_id
         {$reasonWhere}
         GROUP BY m.item_id, {$legacyUnit}, hub.reason_code, hub.reason_custom, hub.notes
         HAVING quantity > 0
         ORDER BY m.item_id ASC, quantity DESC",
        $usageParams
    );

    $measurementQuantity = report_summary_base_quantity_expression();
    $measurementUnit = report_summary_base_unit_expression();
    $directRows = Database::fetchAll(
        "SELECT m.item_id,
                {$measurementUnit} AS unit,
                COALESCE(NULLIF(mud.reason_code, ''), NULLIF(md.reason_code, ''), 'unspecified') AS reason_code,
                COALESCE(NULLIF(mud.custom_reason, ''), NULLIF(md.custom_reason, '')) AS reason_custom,
                COALESCE(NULLIF(mud.notes, ''), NULLIF(m.notes, '')) AS notes,
                COALESCE(SUM({$measurementQuantity}), 0) AS quantity
         FROM inventory_movements m
         LEFT JOIN items i ON i.id = m.item_id
         LEFT JOIN inventory_movement_measurement_details md ON md.movement_id = m.id
         LEFT JOIN inventory_movement_usage_details mud ON mud.movement_id = m.id
         {$currentUsageWhere}
           AND (mud.id IS NOT NULL OR NULLIF(md.reason_code, '') IS NOT NULL)
         GROUP BY m.item_id,
                  {$measurementUnit},
                  COALESCE(NULLIF(mud.reason_code, ''), NULLIF(md.reason_code, ''), 'unspecified'),
                  COALESCE(NULLIF(mud.custom_reason, ''), NULLIF(md.custom_reason, '')),
                  COALESCE(NULLIF(mud.notes, ''), NULLIF(m.notes, ''))
         HAVING quantity > 0
         ORDER BY m.item_id ASC, quantity DESC",
        $usageParams
    );

    $groups = [];

    foreach (array_merge($legacyRows, $directRows) as $row) {
        $itemId = (int) ($row['item_id'] ?? 0);

        if ($itemId <= 0) {
            continue;
        }

        $reasonCode = mobile_usage_reason_normalize_code((string) ($row['reason_code'] ?? 'unspecified'));
        $groups[$itemId][] = [
            'label' => inventory_usage_reason_label($reasonCode, (string) ($row['reason_custom'] ?? '')),
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
    $currentUsageWhere = $usageWhere . ' AND ' . report_summary_current_stock_usage_condition_sql('m');
    $reasonWhere = $usageWhere . " AND m.context_type = 'handover'";

    $legacyUnit = inventory_item_unit_sql_expression('i');
    $legacyRows = Database::fetchAll(
        "SELECT DATE(m.used_at) AS usage_date,
                m.item_id,
                m.context_id AS handover_id,
                {$legacyUnit} AS unit,
                hub.reason_code,
                hub.reason_custom,
                hub.notes,
                COALESCE(SUM(hub.quantity), 0) AS quantity
         FROM inventory_movements m
         " . report_summary_latest_closed_handover_usage_join_sql() . "
         INNER JOIN handover_usage_breakdowns hub
            ON hub.handover_id = m.context_id
           AND hub.item_id = m.item_id
         LEFT JOIN items i ON i.id = m.item_id
         {$reasonWhere}
         GROUP BY DATE(m.used_at), m.item_id, m.context_id, {$legacyUnit}, hub.reason_code, hub.reason_custom, hub.notes
         HAVING quantity > 0
         ORDER BY usage_date DESC, m.item_id ASC, quantity DESC",
        $usageParams
    );

    $measurementQuantity = report_summary_base_quantity_expression();
    $measurementUnit = report_summary_base_unit_expression();
    $directRows = Database::fetchAll(
        "SELECT DATE(m.used_at) AS usage_date,
                m.item_id,
                COALESCE(m.context_id, 0) AS handover_id,
                {$measurementUnit} AS unit,
                COALESCE(NULLIF(mud.reason_code, ''), NULLIF(md.reason_code, ''), 'unspecified') AS reason_code,
                COALESCE(NULLIF(mud.custom_reason, ''), NULLIF(md.custom_reason, '')) AS reason_custom,
                COALESCE(NULLIF(mud.notes, ''), NULLIF(m.notes, '')) AS notes,
                COALESCE(SUM({$measurementQuantity}), 0) AS quantity
         FROM inventory_movements m
         LEFT JOIN items i ON i.id = m.item_id
         LEFT JOIN inventory_movement_measurement_details md ON md.movement_id = m.id
         LEFT JOIN inventory_movement_usage_details mud ON mud.movement_id = m.id
         {$currentUsageWhere}
           AND (mud.id IS NOT NULL OR NULLIF(md.reason_code, '') IS NOT NULL)
         GROUP BY DATE(m.used_at),
                  m.item_id,
                  COALESCE(m.context_id, 0),
                  {$measurementUnit},
                  COALESCE(NULLIF(mud.reason_code, ''), NULLIF(md.reason_code, ''), 'unspecified'),
                  COALESCE(NULLIF(mud.custom_reason, ''), NULLIF(md.custom_reason, '')),
                  COALESCE(NULLIF(mud.notes, ''), NULLIF(m.notes, ''))
         HAVING quantity > 0
         ORDER BY usage_date DESC, m.item_id ASC, quantity DESC",
        $usageParams
    );

    $groups = [];

    foreach (array_merge($legacyRows, $directRows) as $row) {
        $itemId = (int) ($row['item_id'] ?? 0);
        $handoverId = (int) ($row['handover_id'] ?? 0);
        $usageDate = trim((string) ($row['usage_date'] ?? ''));

        if ($itemId <= 0 || $usageDate === '') {
            continue;
        }

        $reasonCode = mobile_usage_reason_normalize_code((string) ($row['reason_code'] ?? 'unspecified'));
        $groups[$usageDate . ':' . $itemId . ':' . $handoverId][] = [
            'label' => inventory_usage_reason_label($reasonCode, (string) ($row['reason_custom'] ?? '')),
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

    // Operational reconciliation belongs to the whole handover. It cannot be
    // truthfully attributed to one SKU or package preset.
    if (!empty($filters['item_id']) || !empty($filters['package_preset_id'])) {
        return [];
    }

    $conditions = [
        "h.usage_reporting_mode = 'operational_summary'",
        "COALESCE(h.recipient_type, 'staff') = 'staff'",
        "h.status = 'closed'",
        'r.approved_at IS NOT NULL',
    ];
    $params = [];
    $storageScope = current_user_item_storage_scope();

    if ($storageScope !== null) {
        $storageScopeSql = item_storage_scope_sql($storageScope);
        $conditions[] = $storageScope === []
            ? '1 = 0'
            : "(
                h.source_storage_id IN ({$storageScopeSql})
                OR h.destination_storage_id IN ({$storageScopeSql})
            )";
    }
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

    if ((int) ($filters['employee_id'] ?? 0) > 0) {
        $conditions[] = 'h.recipient_user_id = :operational_employee_id';
        $params['operational_employee_id'] = (int) $filters['employee_id'];
    }

    if ((int) ($filters['manager_id'] ?? 0) > 0) {
        $conditions[] = 'COALESCE(h.manager_user_id, receiver.manager_user_id) = :operational_manager_id';
        $params['operational_manager_id'] = (int) $filters['manager_id'];
    }

    if ((int) ($filters['department_id'] ?? 0) > 0) {
        $conditions[] = 'COALESCE(h.recipient_department_id, receiver.department_id) = :operational_department_id';
        $params['operational_department_id'] = (int) $filters['department_id'];
    }

    if (trim((string) ($filters['unit'] ?? '')) !== '') {
        $conditions[] = 'r.unit = :operational_unit';
        $params['operational_unit'] = trim((string) $filters['unit']);
    }

    if (trim((string) ($filters['reason'] ?? '')) !== '') {
        $reason = mobile_usage_reason_normalize_code((string) $filters['reason']);
        $legacyReason = $reason === 'no_show' ? 'noshow' : $reason;
        $conditions[] = 'EXISTS (
            SELECT 1
            FROM handover_reconciliation_entries operational_reason
            WHERE operational_reason.reconciliation_id = r.id
              AND operational_reason.reason_code IN (:operational_reason, :operational_legacy_reason)
        )';
        $params['operational_reason'] = $reason;
        $params['operational_legacy_reason'] = $legacyReason;
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

function report_summary_unit_totals(string $where, array $params): array
{
    $baseQuantity = report_summary_base_quantity_expression();
    $baseUnit = report_summary_base_unit_expression();
    $rows = Database::fetchAll(
        "SELECT m.movement_type,
                {$baseUnit} AS unit,
                COALESCE(SUM({$baseQuantity}), 0) AS quantity
         FROM inventory_movements m
         LEFT JOIN items i ON i.id = m.item_id
         LEFT JOIN inventory_movement_measurement_details md ON md.movement_id = m.id
         {$where}
           AND " . report_summary_current_stock_usage_condition_sql('m') . "
         GROUP BY m.movement_type, {$baseUnit}
         ORDER BY m.movement_type ASC, unit ASC",
        $params
    );
    $totals = [
        'usage' => [],
        'restock' => [],
        'transfer' => [],
        'adjustment' => [],
    ];

    foreach ($rows as $row) {
        $type = (string) ($row['movement_type'] ?? '');
        if (!array_key_exists($type, $totals)) {
            continue;
        }
        $totals[$type][] = [
            'unit' => trim((string) ($row['unit'] ?? '')) ?: 'pcs',
            'quantity' => (float) ($row['quantity'] ?? 0),
        ];
    }

    return $totals;
}

function report_summary_proof_join(): string
{
    return "LEFT JOIN (
        SELECT movement_documents.movement_id,
               COUNT(*) AS proof_count,
               GROUP_CONCAT(
                   DISTINCT CONCAT(file_rows.id, ':', file_rows.display_name)
                   ORDER BY file_rows.display_name
                   SEPARATOR ' | '
               ) AS proof_files
        FROM inventory_movement_documents movement_documents
        INNER JOIN file_assets file_rows ON file_rows.id = movement_documents.file_asset_id
        WHERE file_rows.deleted_at IS NULL
        GROUP BY movement_documents.movement_id
    ) movement_proofs ON movement_proofs.movement_id = m.id";
}

function report_summary_user_breakdown(string $where, array $params, bool $forExport = false): array
{
    $baseQuantity = report_summary_base_quantity_expression();
    $baseUnit = report_summary_base_unit_expression();
    $employeeId = "CASE WHEN usage_handover.id IS NOT NULL
        THEN COALESCE(usage_handover.recipient_user_id, 0)
        ELSE COALESCE(m.performed_by, 0)
    END";
    $employeeName = "CASE WHEN usage_handover.id IS NOT NULL
        THEN COALESCE(recipient_user.name, NULLIF(usage_handover.recipient_name, ''), 'Unassigned')
        ELSE COALESCE(performed_user.name, 'System')
    END";
    $departmentName = "CASE WHEN usage_handover.id IS NOT NULL
        THEN COALESCE(NULLIF(usage_handover.recipient_department_name, ''), recipient_department.name, 'Unassigned')
        ELSE COALESCE(NULLIF(md.department_name, ''), performed_department.name, 'Unassigned')
    END";
    $managerName = "CASE WHEN usage_handover.id IS NOT NULL
        THEN COALESCE(handover_manager.name, recipient_manager.name, 'Unassigned')
        ELSE COALESCE(NULLIF(md.manager_name, ''), performed_manager.name, 'Unassigned')
    END";
    $joins = "LEFT JOIN items i ON i.id = m.item_id
        LEFT JOIN inventory_movement_measurement_details md ON md.movement_id = m.id
        LEFT JOIN users performed_user ON performed_user.id = m.performed_by
        LEFT JOIN departments performed_department ON performed_department.id = performed_user.department_id
        LEFT JOIN users performed_manager ON performed_manager.id = performed_user.manager_user_id
        LEFT JOIN handovers usage_handover ON usage_handover.id = m.context_id AND m.context_type = 'handover'
        LEFT JOIN users recipient_user ON recipient_user.id = usage_handover.recipient_user_id
        LEFT JOIN departments recipient_department ON recipient_department.id = recipient_user.department_id
        LEFT JOIN users recipient_manager ON recipient_manager.id = recipient_user.manager_user_id
        LEFT JOIN users handover_manager ON handover_manager.id = usage_handover.manager_user_id";
    $summaryLimit = $forExport ? '' : "\n         LIMIT 30";
    $summaryRows = Database::fetchAll(
        "SELECT {$employeeId} AS employee_id,
                {$employeeName} AS user_name,
                {$departmentName} AS department_name,
                {$managerName} AS manager_name,
                COUNT(*) AS movement_count,
                COUNT(DISTINCT m.item_id) AS item_count,
                MAX(m.used_at) AS last_activity_at
         FROM inventory_movements m
         {$joins}
         {$where}
         GROUP BY {$employeeId}, {$employeeName}, {$departmentName}, {$managerName}
         ORDER BY movement_count DESC, user_name ASC{$summaryLimit}",
        $params
    );
    $unitRows = Database::fetchAll(
        "SELECT {$employeeId} AS employee_id,
                m.movement_type,
                {$baseUnit} AS unit,
                COALESCE(SUM({$baseQuantity}), 0) AS quantity
         FROM inventory_movements m
         {$joins}
         {$where}
           AND " . report_summary_current_stock_usage_condition_sql('m') . "
         GROUP BY {$employeeId}, m.movement_type, {$baseUnit}
         ORDER BY employee_id ASC, m.movement_type ASC, unit ASC",
        $params
    );
    $totals = [];
    foreach ($unitRows as $row) {
        $employeeKey = (string) ((int) ($row['employee_id'] ?? 0));
        $type = (string) ($row['movement_type'] ?? '');
        if (!in_array($type, ['usage', 'restock', 'transfer', 'adjustment'], true)) {
            continue;
        }
        $totals[$employeeKey][$type][] = [
            'unit' => trim((string) ($row['unit'] ?? '')) ?: 'pcs',
            'quantity' => (float) ($row['quantity'] ?? 0),
        ];
    }

    foreach ($summaryRows as &$row) {
        $employeeKey = (string) ((int) ($row['employee_id'] ?? 0));
        foreach (['usage', 'restock', 'transfer', 'adjustment'] as $type) {
            $row[$type . '_totals'] = $totals[$employeeKey][$type] ?? [];
        }
    }
    unset($row);

    return $summaryRows;
}

function report_summary_data(array $filters, bool $forExport = false): array
{
    [$where, $params] = build_report_summary_where($filters);
    $cards = Database::fetch(
        "SELECT COUNT(*) AS movement_count,
                COUNT(DISTINCT m.item_id) AS item_count,
                COUNT(DISTINCT CASE
                    WHEN usage_handover.id IS NOT NULL THEN usage_handover.recipient_user_id
                    ELSE m.performed_by
                END) AS user_count
         FROM inventory_movements m
         LEFT JOIN handovers usage_handover ON usage_handover.id = m.context_id AND m.context_type = 'handover'
         {$where}",
        $params
    ) ?: [];
    $cardUnitTotals = report_summary_unit_totals($where, $params);

    $usageFilters = $filters;
    $usageFilters['movement_type'] = 'usage';
    [$usageWhere, $usageParams] = build_report_summary_where($usageFilters);
    $currentUsageWhere = $usageWhere . ' AND ' . report_summary_current_stock_usage_condition_sql('m');
    $usageQuantity = report_summary_base_quantity_expression();
    $usageUnit = report_summary_base_unit_expression();
    $proofJoin = report_summary_proof_join();
    $usageStaffName = "CASE
        WHEN usage_handover.id IS NOT NULL
            THEN COALESCE(recipient_user.name, NULLIF(usage_handover.recipient_name, ''), 'Unassigned')
        ELSE COALESCE(u.name, 'System')
    END";
    $usageApproverName = "CASE
        WHEN usage_handover.id IS NOT NULL
            THEN COALESCE(approved_user.name, assigned_approver.name, u.name, 'Unassigned')
        ELSE ''
    END";
    $usageLocation = "CASE
        WHEN usage_handover.id IS NOT NULL
            THEN COALESCE(handover_source.name, handover_destination.name, 'Unassigned')
        ELSE COALESCE(source_storage.name, destination_storage.name, 'Unassigned')
    END";
    $usageDepartment = "CASE
        WHEN usage_handover.id IS NOT NULL
            THEN COALESCE(NULLIF(usage_handover.recipient_department_name, ''), recipient_department.name, 'Unassigned')
        ELSE COALESCE(NULLIF(md.department_name, ''), performed_department.name, 'Unassigned')
    END";
    $usageManager = "CASE
        WHEN usage_handover.id IS NOT NULL
            THEN COALESCE(handover_manager.name, recipient_manager.name, 'Unassigned')
        ELSE COALESCE(NULLIF(md.manager_name, ''), performed_manager.name, 'Unassigned')
    END";

    $usageByItemLimit = $forExport ? '' : "\n         LIMIT 50";
    $usageByItem = Database::fetchAll(
        "SELECT m.item_id,
                COALESCE(i.name, CONCAT('Item #', m.item_id)) AS item_name,
                COALESCE(i.sku, '') AS sku,
                {$usageUnit} AS unit,
                COALESCE(i.barcode, '') AS barcode,
                i.is_active AS item_is_active,
                i.image_path,
                COALESCE(SUM({$usageQuantity}), 0) AS used_quantity,
                COUNT(*) AS movement_count,
                COALESCE(SUM(movement_proofs.proof_count), 0) AS proof_count,
                GROUP_CONCAT(DISTINCT NULLIF(movement_proofs.proof_files, '') SEPARATOR ' | ') AS proof_files,
                GROUP_CONCAT(
                    DISTINCT CASE WHEN md.id IS NOT NULL THEN CONCAT(
                        md.input_quantity,
                        ' x ',
                        COALESCE(NULLIF(md.package_label, ''), md.base_unit),
                        ' = ',
                        md.base_quantity,
                        ' ',
                        md.base_unit
                    ) END
                    SEPARATOR ' | '
                ) AS entered_measurements,
                GROUP_CONCAT(DISTINCT NULLIF(md.package_label, '') ORDER BY md.package_label SEPARATOR ', ') AS packages,
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
                GROUP_CONCAT(
                    DISTINCT CASE
                        WHEN usage_handover.id IS NOT NULL THEN COALESCE(NULLIF(usage_handover.recipient_department_name, ''), recipient_department.name, 'Unassigned')
                        ELSE COALESCE(NULLIF(md.department_name, ''), performed_department.name, 'Unassigned')
                    END
                    SEPARATOR ', '
                ) AS departments,
                GROUP_CONCAT(
                    DISTINCT CASE
                        WHEN usage_handover.id IS NOT NULL THEN COALESCE(handover_manager.name, recipient_manager.name, 'Unassigned')
                        ELSE COALESCE(NULLIF(md.manager_name, ''), performed_manager.name, 'Unassigned')
                    END
                    SEPARATOR ', '
                ) AS managers,
                MAX(m.used_at) AS last_activity_at,
                GROUP_CONCAT(DISTINCT NULLIF(m.reference_code, '') ORDER BY m.reference_code SEPARATOR ', ') AS references_list
         FROM inventory_movements m
         LEFT JOIN items i ON i.id = m.item_id
         LEFT JOIN inventory_movement_measurement_details md ON md.movement_id = m.id
         LEFT JOIN storages source_storage ON source_storage.id = m.source_storage_id
         LEFT JOIN storages destination_storage ON destination_storage.id = m.destination_storage_id
         LEFT JOIN users u ON u.id = m.performed_by
         LEFT JOIN departments performed_department ON performed_department.id = u.department_id
         LEFT JOIN users performed_manager ON performed_manager.id = u.manager_user_id
         LEFT JOIN handovers usage_handover ON usage_handover.id = m.context_id AND m.context_type = 'handover'
         LEFT JOIN users recipient_user ON recipient_user.id = usage_handover.recipient_user_id
         LEFT JOIN departments recipient_department ON recipient_department.id = recipient_user.department_id
         LEFT JOIN users recipient_manager ON recipient_manager.id = recipient_user.manager_user_id
         LEFT JOIN users handover_manager ON handover_manager.id = usage_handover.manager_user_id
         LEFT JOIN users approved_user ON approved_user.id = usage_handover.approved_by
         LEFT JOIN users assigned_approver ON assigned_approver.id = usage_handover.approver_user_id
         LEFT JOIN storages handover_source ON handover_source.id = usage_handover.source_storage_id
         LEFT JOIN storages handover_destination ON handover_destination.id = usage_handover.destination_storage_id
         {$proofJoin}
         {$currentUsageWhere}
         GROUP BY m.item_id, i.name, i.sku, {$usageUnit}, i.barcode, i.is_active, i.image_path
         ORDER BY used_quantity DESC, item_name ASC{$usageByItemLimit}",
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
                {$usageUnit} AS unit,
                COALESCE(i.barcode, '') AS barcode,
                i.is_active AS item_is_active,
                i.image_path,
                COALESCE(SUM({$usageQuantity}), 0) AS used_quantity,
                COUNT(*) AS movement_count,
                COALESCE(SUM(movement_proofs.proof_count), 0) AS proof_count,
                GROUP_CONCAT(DISTINCT NULLIF(movement_proofs.proof_files, '') SEPARATOR ' | ') AS proof_files,
                GROUP_CONCAT(
                    DISTINCT CASE WHEN md.id IS NOT NULL THEN CONCAT(
                        md.input_quantity,
                        ' x ',
                        COALESCE(NULLIF(md.package_label, ''), md.base_unit),
                        ' = ',
                        md.base_quantity,
                        ' ',
                        md.base_unit
                    ) END
                    SEPARATOR ' | '
                ) AS entered_measurements,
                GROUP_CONCAT(DISTINCT NULLIF(md.package_label, '') ORDER BY md.package_label SEPARATOR ', ') AS packages,
                {$usageStaffName} AS staff_name,
                {$usageApproverName} AS approver_name,
                {$usageLocation} AS usage_location,
                {$usageDepartment} AS department_name,
                {$usageManager} AS manager_name,
                MIN(m.used_at) AS first_activity_at,
                MAX(m.used_at) AS last_activity_at,
                GROUP_CONCAT(DISTINCT NULLIF(m.reference_code, '') ORDER BY m.reference_code SEPARATOR ', ') AS references_list,
                GROUP_CONCAT(DISTINCT NULLIF(TRIM(m.notes), '') ORDER BY m.notes SEPARATOR ' | ') AS notes_list
         FROM inventory_movements m
         LEFT JOIN items i ON i.id = m.item_id
         LEFT JOIN inventory_movement_measurement_details md ON md.movement_id = m.id
         LEFT JOIN storages source_storage ON source_storage.id = m.source_storage_id
         LEFT JOIN storages destination_storage ON destination_storage.id = m.destination_storage_id
         LEFT JOIN users u ON u.id = m.performed_by
         LEFT JOIN departments performed_department ON performed_department.id = u.department_id
         LEFT JOIN users performed_manager ON performed_manager.id = u.manager_user_id
         LEFT JOIN handovers usage_handover ON usage_handover.id = m.context_id AND m.context_type = 'handover'
         LEFT JOIN users recipient_user ON recipient_user.id = usage_handover.recipient_user_id
         LEFT JOIN departments recipient_department ON recipient_department.id = recipient_user.department_id
         LEFT JOIN users recipient_manager ON recipient_manager.id = recipient_user.manager_user_id
         LEFT JOIN users handover_manager ON handover_manager.id = usage_handover.manager_user_id
         LEFT JOIN users approved_user ON approved_user.id = usage_handover.approved_by
         LEFT JOIN users assigned_approver ON assigned_approver.id = usage_handover.approver_user_id
         LEFT JOIN storages handover_source ON handover_source.id = usage_handover.source_storage_id
         LEFT JOIN storages handover_destination ON handover_destination.id = usage_handover.destination_storage_id
         {$proofJoin}
         {$currentUsageWhere}
         GROUP BY DATE(m.used_at),
                  m.item_id,
                  COALESCE(m.context_type, ''),
                  COALESCE(m.context_id, 0),
                  i.name,
                  i.sku,
                  {$usageUnit},
                  i.barcode,
                  i.is_active,
                  i.image_path,
                  {$usageStaffName},
                  {$usageApproverName},
                  {$usageLocation},
                  {$usageDepartment},
                  {$usageManager}
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

    $userBreakdown = report_summary_user_breakdown($where, $params, $forExport);

    $timelineLimit = $forExport ? '' : "\n         LIMIT 120";
    $timeline = Database::fetchAll(
        "SELECT m.*,
                COALESCE(i.name, CONCAT('Item #', m.item_id)) AS item_name,
                COALESCE(i.sku, '') AS sku,
                " . report_summary_base_unit_expression() . " AS unit,
                COALESCE(i.barcode, '') AS barcode,
                i.is_active AS item_is_active,
                i.image_path,
                source_storage.name AS source_storage_name,
                destination_storage.name AS destination_storage_name,
                CASE
                    WHEN usage_handover.id IS NOT NULL
                        THEN COALESCE(recipient_user.name, NULLIF(usage_handover.recipient_name, ''), 'Unassigned')
                    ELSE COALESCE(u.name, 'System')
                END AS user_name,
                CASE
                    WHEN usage_handover.id IS NOT NULL
                        THEN COALESCE(NULLIF(usage_handover.recipient_department_name, ''), recipient_department.name, 'Unassigned')
                    ELSE COALESCE(NULLIF(md.department_name, ''), performed_department.name, 'Unassigned')
                END AS department_name,
                CASE
                    WHEN usage_handover.id IS NOT NULL
                        THEN COALESCE(handover_manager.name, recipient_manager.name, 'Unassigned')
                    ELSE COALESCE(NULLIF(md.manager_name, ''), performed_manager.name, 'Unassigned')
                END AS manager_name,
                md.input_quantity,
                md.package_label,
                md.package_scan_code,
                md.conversion_multiplier,
                md.base_quantity,
                md.base_unit,
                md.measurement_dimension,
                COALESCE(NULLIF(mud.reason_code, ''), NULLIF(md.reason_code, '')) AS reason_code,
                COALESCE(NULLIF(mud.custom_reason, ''), NULLIF(md.custom_reason, '')) AS custom_reason,
                COALESCE(movement_proofs.proof_count, 0) AS proof_count,
                movement_proofs.proof_files
         FROM inventory_movements m
         LEFT JOIN items i ON i.id = m.item_id
         LEFT JOIN inventory_movement_measurement_details md ON md.movement_id = m.id
         LEFT JOIN inventory_movement_usage_details mud ON mud.movement_id = m.id
         LEFT JOIN storages source_storage ON source_storage.id = m.source_storage_id
         LEFT JOIN storages destination_storage ON destination_storage.id = m.destination_storage_id
         LEFT JOIN users u ON u.id = m.performed_by
         LEFT JOIN departments performed_department ON performed_department.id = u.department_id
         LEFT JOIN users performed_manager ON performed_manager.id = u.manager_user_id
         LEFT JOIN handovers usage_handover ON usage_handover.id = m.context_id AND m.context_type = 'handover'
         LEFT JOIN users recipient_user ON recipient_user.id = usage_handover.recipient_user_id
         LEFT JOIN departments recipient_department ON recipient_department.id = recipient_user.department_id
         LEFT JOIN users recipient_manager ON recipient_manager.id = recipient_user.manager_user_id
         LEFT JOIN users handover_manager ON handover_manager.id = usage_handover.manager_user_id
         {$proofJoin}
         {$where}
         ORDER BY m.used_at DESC, m.id DESC{$timelineLimit}",
        $params
    );
    $timeline = array_map(
        static fn (array $movement): array => movement_apply_filter_scope($movement, $filters['storage_id'] ?? null),
        $timeline
    );

    $query = report_summary_filter_query($filters);

    $movementQuery = $query;
    unset($movementQuery['item_status']);
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
            'used_totals' => $cardUnitTotals['usage'],
            'restocked_totals' => $cardUnitTotals['restock'],
            'transferred_totals' => $cardUnitTotals['transfer'],
            'adjusted_totals' => $cardUnitTotals['adjustment'],
            // Compatibility keys remain for older templates, but new UI and
            // exports use the unit-grouped values above.
            'used_units' => 0.0,
            'restocked_units' => 0.0,
            'transferred_units' => 0.0,
            'adjusted_units' => 0.0,
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
