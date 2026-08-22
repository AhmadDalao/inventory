<?php
declare(strict_types=1);

function build_report_summary_where(array $filters, string $alias = 'm'): array
{
    $conditions = [
        "{$alias}.used_at >= :summary_date_from",
        "{$alias}.used_at <= :summary_date_to",
    ];
    $params = [
        'summary_date_from' => $filters['date_from'] . ' 00:00:00',
        'summary_date_to' => $filters['date_to'] . ' 23:59:59',
    ];

    if (!empty($filters['storage_id'])) {
        $conditions[] = "(
            {$alias}.source_storage_id = :summary_source_storage_id
            OR {$alias}.destination_storage_id = :summary_destination_storage_id
            OR (
                {$alias}.context_type = 'handover'
                AND EXISTS (
                    SELECT 1
                    FROM handovers summary_handover_storage
                    WHERE summary_handover_storage.id = {$alias}.context_id
                      AND (
                          summary_handover_storage.source_storage_id = :summary_handover_source_storage_id
                          OR summary_handover_storage.destination_storage_id = :summary_handover_destination_storage_id
                      )
                )
            )
        )";
        $params['summary_source_storage_id'] = (int) $filters['storage_id'];
        $params['summary_destination_storage_id'] = (int) $filters['storage_id'];
        $params['summary_handover_source_storage_id'] = (int) $filters['storage_id'];
        $params['summary_handover_destination_storage_id'] = (int) $filters['storage_id'];
    }

    if (($filters['movement_type'] ?? '') !== '') {
        $conditions[] = "{$alias}.movement_type = :summary_movement_type";
        $params['summary_movement_type'] = (string) $filters['movement_type'];
    }

    if (($filters['item_status'] ?? 'all') === 'active') {
        $conditions[] = "EXISTS (SELECT 1 FROM items summary_item_status WHERE summary_item_status.id = {$alias}.item_id AND summary_item_status.is_active = 1)";
    } elseif (($filters['item_status'] ?? 'all') === 'deleted') {
        $conditions[] = "EXISTS (SELECT 1 FROM items summary_item_status WHERE summary_item_status.id = {$alias}.item_id AND summary_item_status.is_active = 0)";
    }

    if (!empty($filters['item_id'])) {
        $conditions[] = "{$alias}.item_id = :summary_item_id";
        $params['summary_item_id'] = (int) $filters['item_id'];
    }

    if (!empty($filters['department_id'])) {
        $conditions[] = "(
            EXISTS (
                SELECT 1
                FROM inventory_movement_measurement_details summary_department_snapshot
                WHERE summary_department_snapshot.movement_id = {$alias}.id
                  AND summary_department_snapshot.department_id = :summary_snapshot_department_id
            )
            OR (
                NOT EXISTS (
                    SELECT 1
                    FROM inventory_movement_measurement_details summary_department_missing
                    WHERE summary_department_missing.movement_id = {$alias}.id
                )
                AND EXISTS (
                    SELECT 1
                    FROM users summary_department_user
                    WHERE summary_department_user.id = {$alias}.performed_by
                      AND summary_department_user.department_id = :summary_current_department_id
                )
            )
        )";
        $params['summary_snapshot_department_id'] = (int) $filters['department_id'];
        $params['summary_current_department_id'] = (int) $filters['department_id'];
    }

    if (!empty($filters['employee_id'])) {
        $conditions[] = "(
            (
                {$alias}.context_type = 'handover'
                AND EXISTS (
                    SELECT 1
                    FROM handovers summary_employee_handover
                    WHERE summary_employee_handover.id = {$alias}.context_id
                      AND summary_employee_handover.recipient_user_id = :summary_handover_employee_id
                )
            )
            OR (
                COALESCE({$alias}.context_type, '') <> 'handover'
                AND {$alias}.performed_by = :summary_performer_employee_id
            )
        )";
        $params['summary_handover_employee_id'] = (int) $filters['employee_id'];
        $params['summary_performer_employee_id'] = (int) $filters['employee_id'];
    }

    if (!empty($filters['manager_id'])) {
        $conditions[] = "(
            EXISTS (
                SELECT 1
                FROM inventory_movement_measurement_details summary_manager_snapshot
                WHERE summary_manager_snapshot.movement_id = {$alias}.id
                  AND summary_manager_snapshot.manager_user_id = :summary_snapshot_manager_id
            )
            OR EXISTS (
                SELECT 1
                FROM handovers summary_manager_handover
                LEFT JOIN users summary_manager_recipient ON summary_manager_recipient.id = summary_manager_handover.recipient_user_id
                WHERE summary_manager_handover.id = {$alias}.context_id
                  AND {$alias}.context_type = 'handover'
                  AND COALESCE(summary_manager_handover.manager_user_id, summary_manager_recipient.manager_user_id) = :summary_handover_manager_id
            )
            OR (
                NOT EXISTS (
                    SELECT 1
                    FROM inventory_movement_measurement_details summary_manager_missing
                    WHERE summary_manager_missing.movement_id = {$alias}.id
                )
                AND EXISTS (
                    SELECT 1
                    FROM users summary_manager_user
                    WHERE summary_manager_user.id = {$alias}.performed_by
                      AND summary_manager_user.manager_user_id = :summary_current_manager_id
                )
            )
        )";
        $params['summary_snapshot_manager_id'] = (int) $filters['manager_id'];
        $params['summary_handover_manager_id'] = (int) $filters['manager_id'];
        $params['summary_current_manager_id'] = (int) $filters['manager_id'];
    }

    if (!empty($filters['package_preset_id'])) {
        $conditions[] = "EXISTS (
            SELECT 1
            FROM inventory_movement_measurement_details summary_package_snapshot
            WHERE summary_package_snapshot.movement_id = {$alias}.id
              AND summary_package_snapshot.package_preset_id = :summary_package_preset_id
        )";
        $params['summary_package_preset_id'] = (int) $filters['package_preset_id'];
    }

    if (trim((string) ($filters['unit'] ?? '')) !== '') {
        $conditions[] = "(
            EXISTS (
                SELECT 1
                FROM inventory_movement_measurement_details summary_unit_snapshot
                WHERE summary_unit_snapshot.movement_id = {$alias}.id
                  AND summary_unit_snapshot.base_unit = :summary_snapshot_unit
            )
            OR (
                NOT EXISTS (
                    SELECT 1
                    FROM inventory_movement_measurement_details summary_unit_missing
                    WHERE summary_unit_missing.movement_id = {$alias}.id
                )
                AND EXISTS (
                    SELECT 1
                    FROM items summary_unit_item
                    WHERE summary_unit_item.id = {$alias}.item_id
                      AND " . inventory_item_unit_sql_expression('summary_unit_item') . " = :summary_item_unit
                )
            )
        )";
        $params['summary_snapshot_unit'] = (string) $filters['unit'];
        $params['summary_item_unit'] = (string) $filters['unit'];
    }

    if (trim((string) ($filters['reason'] ?? '')) !== '') {
        $reason = mobile_usage_reason_normalize_code((string) $filters['reason']);
        $legacyReason = $reason === 'no_show' ? 'noshow' : $reason;
        $conditions[] = "(
            EXISTS (
                SELECT 1
                FROM inventory_movement_usage_details summary_usage_reason
                WHERE summary_usage_reason.movement_id = {$alias}.id
                  AND summary_usage_reason.reason_code IN (:summary_reason_code, :summary_legacy_reason_code)
            )
            OR EXISTS (
                SELECT 1
                FROM inventory_movement_measurement_details summary_measurement_reason
                WHERE summary_measurement_reason.movement_id = {$alias}.id
                  AND summary_measurement_reason.reason_code IN (:summary_measurement_reason_code, :summary_measurement_legacy_reason_code)
            )
            OR (
                {$alias}.context_type = 'handover'
                AND EXISTS (
                    SELECT 1
                    FROM handover_usage_breakdowns summary_handover_reason
                    WHERE summary_handover_reason.handover_id = {$alias}.context_id
                      AND summary_handover_reason.item_id = {$alias}.item_id
                      AND summary_handover_reason.reason_code IN (:summary_handover_reason_code, :summary_handover_legacy_reason_code)
                )
            )
        )";
        foreach ([
            'summary_reason_code',
            'summary_measurement_reason_code',
            'summary_handover_reason_code',
        ] as $key) {
            $params[$key] = $reason;
        }
        foreach ([
            'summary_legacy_reason_code',
            'summary_measurement_legacy_reason_code',
            'summary_handover_legacy_reason_code',
        ] as $key) {
            $params[$key] = $legacyReason;
        }
    }

    return ['WHERE ' . implode(' AND ', $conditions), $params];
}
