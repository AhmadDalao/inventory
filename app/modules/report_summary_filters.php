<?php
declare(strict_types=1);

// Report summary filters and display labels.

function report_summary_filters(): array
{
    $today = date('Y-m-d');
    $legacyDate = trim((string) query('date', ''));
    $dateFrom = trim((string) query('date_from', $legacyDate !== '' ? $legacyDate : $today));
    $dateTo = trim((string) query('date_to', $legacyDate !== '' ? $legacyDate : $today));
    $type = trim((string) query('movement_type', ''));
    $itemStatus = trim((string) query('item_status', 'all'));
    $reason = trim((string) query('reason', ''));
    $unit = trim((string) query('unit', ''));

    $dateFrom = report_summary_valid_date($dateFrom) ? $dateFrom : $today;
    $dateTo = report_summary_valid_date($dateTo) ? $dateTo : $today;

    if ($dateFrom > $dateTo) {
        [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
    }

    return [
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'storage_id' => ctype_digit((string) query('storage_id', '')) ? (int) query('storage_id') : null,
        'item_id' => ctype_digit((string) query('item_id', '')) ? (int) query('item_id') : null,
        'department_id' => ctype_digit((string) query('department_id', '')) ? (int) query('department_id') : null,
        'employee_id' => ctype_digit((string) query('employee_id', '')) ? (int) query('employee_id') : null,
        'manager_id' => ctype_digit((string) query('manager_id', '')) ? (int) query('manager_id') : null,
        'package_preset_id' => ctype_digit((string) query('package_preset_id', '')) ? (int) query('package_preset_id') : null,
        'movement_type' => in_array($type, ['restock', 'usage', 'adjustment', 'transfer'], true) ? $type : '',
        'item_status' => in_array($itemStatus, ['all', 'active', 'deleted'], true) ? $itemStatus : 'all',
        'reason' => $reason !== '' ? mobile_usage_reason_normalize_code($reason) : '',
        'unit' => mb_substr($unit, 0, 32),
    ];
}

function report_summary_valid_date(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

    return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value;
}

function report_summary_period_label(array $filters): string
{
    $dateFrom = (string) ($filters['date_from'] ?? date('Y-m-d'));
    $dateTo = (string) ($filters['date_to'] ?? $dateFrom);
    $fromLabel = date('M j, Y', strtotime($dateFrom));
    $toLabel = date('M j, Y', strtotime($dateTo));

    return $dateFrom === $dateTo
        ? 'On ' . $fromLabel
        : 'From ' . $fromLabel . ' To ' . $toLabel;
}

function report_summary_period_filename(array $filters): string
{
    $dateFrom = str_replace('-', '', (string) ($filters['date_from'] ?? date('Y-m-d')));
    $dateTo = str_replace('-', '', (string) ($filters['date_to'] ?? $filters['date_from'] ?? date('Y-m-d')));

    return $dateFrom === $dateTo ? $dateFrom : $dateFrom . '-to-' . $dateTo;
}

function report_summary_quantity_expression(string $alias = 'm'): string
{
    return "ABS(COALESCE(NULLIF({$alias}.movement_quantity, 0), {$alias}.quantity_delta, 0))";
}

function report_summary_base_quantity_expression(string $movementAlias = 'm', string $measurementAlias = 'md'): string
{
    return "ABS(COALESCE({$measurementAlias}.base_quantity, NULLIF({$movementAlias}.movement_quantity, 0), {$movementAlias}.quantity_delta, 0))";
}

function report_summary_base_unit_expression(string $itemAlias = 'i', string $measurementAlias = 'md'): string
{
    return "COALESCE(NULLIF({$measurementAlias}.base_unit, ''), " . inventory_item_unit_sql_expression($itemAlias) . ')';
}

function report_summary_filter_query(array $filters): array
{
    return array_filter([
        'date_from' => $filters['date_from'] ?? null,
        'date_to' => $filters['date_to'] ?? null,
        'storage_id' => $filters['storage_id'] ?? null,
        'item_id' => $filters['item_id'] ?? null,
        'department_id' => $filters['department_id'] ?? null,
        'employee_id' => $filters['employee_id'] ?? null,
        'manager_id' => $filters['manager_id'] ?? null,
        'package_preset_id' => $filters['package_preset_id'] ?? null,
        'movement_type' => $filters['movement_type'] ?? '',
        'item_status' => ($filters['item_status'] ?? 'all') !== 'all' ? $filters['item_status'] : null,
        'reason' => $filters['reason'] ?? '',
        'unit' => $filters['unit'] ?? '',
    ], static fn ($value): bool => $value !== '' && $value !== null);
}

function report_summary_unit_totals_text(array $totals, string $empty = '0'): string
{
    $parts = [];

    foreach ($totals as $row) {
        $quantity = (float) ($row['quantity'] ?? 0);
        $unit = trim((string) ($row['unit'] ?? '')) ?: 'pcs';

        if (abs($quantity) < 0.0000005) {
            continue;
        }

        $parts[] = format_quantity($quantity) . ' ' . $unit;
    }

    return $parts === [] ? $empty : implode(' · ', $parts);
}

/**
 * Build report filter options from the same authorized movement scope used by
 * the report itself. This keeps scoped users from discovering unrelated item
 * or employee metadata through dropdowns.
 */
function report_summary_selector_data(array $filters): array
{
    $storageScope = current_user_item_storage_scope();
    $scopeSql = item_storage_scope_sql($storageScope);
    $selectedItemId = (int) ($filters['item_id'] ?? 0);
    $itemConditions = ['(items.is_active = 1 OR items.id = :selector_selected_item_id)'];
    $itemParams = ['selector_selected_item_id' => $selectedItemId];

    if ($storageScope !== null) {
        $itemConditions[] = $storageScope === []
            ? '1 = 0'
            : 'EXISTS (
                SELECT 1
                FROM item_storage_balances selector_item_balance
                WHERE selector_item_balance.item_id = items.id
                  AND selector_item_balance.storage_id IN (' . $scopeSql . ')
            )';
    }

    $items = Database::fetchAll(
        'SELECT items.id, items.name, items.sku, items.is_active
         FROM items
         WHERE ' . implode(' AND ', $itemConditions) . '
         ORDER BY items.name ASC, items.sku ASC',
        $itemParams
    );

    $activeItemConditions = ['items.is_active = 1'];
    if ($storageScope !== null) {
        $activeItemConditions[] = $storageScope === []
            ? '1 = 0'
            : 'EXISTS (
                SELECT 1
                FROM item_storage_balances selector_active_balance
                WHERE selector_active_balance.item_id = items.id
                  AND selector_active_balance.storage_id IN (' . $scopeSql . ')
            )';
    }
    $activeItemWhere = implode(' AND ', $activeItemConditions);

    $units = Database::fetchAll(
        'SELECT DISTINCT ' . inventory_item_unit_sql_expression('items') . ' AS value
         FROM items
         WHERE ' . $activeItemWhere . '
         ORDER BY value ASC'
    );

    $packageConditions = ['presets.is_active = 1', $activeItemWhere];
    $packageParams = [];
    if ($selectedItemId > 0) {
        $packageConditions[] = 'presets.item_id = :selector_package_item_id';
        $packageParams['selector_package_item_id'] = $selectedItemId;
    }
    $packagePresets = Database::fetchAll(
        'SELECT presets.id,
                presets.item_id,
                presets.label,
                presets.pieces_per_unit,
                items.name AS item_name,
                ' . inventory_item_unit_sql_expression('items') . ' AS base_unit
         FROM item_package_presets presets
         INNER JOIN items ON items.id = presets.item_id
         WHERE ' . implode(' AND ', $packageConditions) . '
         ORDER BY items.name ASC, presets.label ASC',
        $packageParams
    );

    $people = report_summary_people_selector_data($filters);

    return [
        'storages' => all_storages_for_select((int) ($filters['storage_id'] ?? 0) ?: null),
        'items' => $items,
        'departments' => $people['departments'],
        'employees' => $people['employees'],
        'managers' => $people['managers'],
        'usageReasons' => mobile_usage_reason_catalog(true),
        'units' => $units,
        'packagePresets' => $packagePresets,
    ];
}

function report_summary_people_selector_data(array $filters): array
{
    $selectorFilters = $filters;
    $selectorFilters['department_id'] = null;
    $selectorFilters['employee_id'] = null;
    $selectorFilters['manager_id'] = null;
    [$where, $params] = build_report_summary_where($selectorFilters);

    $rows = Database::fetchAll(
        'SELECT DISTINCT
                CASE
                    WHEN usage_handover.id IS NOT NULL THEN recipient_user.id
                    ELSE performed_user.id
                END AS employee_id,
                CASE
                    WHEN usage_handover.id IS NOT NULL THEN recipient_user.name
                    ELSE performed_user.name
                END AS employee_name,
                CASE
                    WHEN usage_handover.id IS NOT NULL THEN COALESCE(usage_handover.recipient_department_id, recipient_department.id)
                    ELSE COALESCE(md.department_id, performed_department.id)
                END AS department_id,
                CASE
                    WHEN usage_handover.id IS NOT NULL THEN COALESCE(NULLIF(usage_handover.recipient_department_name, ""), recipient_department.name)
                    ELSE COALESCE(NULLIF(md.department_name, ""), performed_department.name)
                END AS department_name,
                CASE
                    WHEN usage_handover.id IS NOT NULL THEN COALESCE(handover_manager.id, recipient_manager.id)
                    ELSE COALESCE(md.manager_user_id, performed_manager.id)
                END AS manager_id,
                CASE
                    WHEN usage_handover.id IS NOT NULL THEN COALESCE(handover_manager.name, recipient_manager.name)
                    ELSE COALESCE(NULLIF(md.manager_name, ""), performed_manager.name)
                END AS manager_name
         FROM inventory_movements m
         LEFT JOIN inventory_movement_measurement_details md ON md.movement_id = m.id
         LEFT JOIN users performed_user ON performed_user.id = m.performed_by
         LEFT JOIN departments performed_department ON performed_department.id = performed_user.department_id
         LEFT JOIN users performed_manager ON performed_manager.id = performed_user.manager_user_id
         LEFT JOIN handovers usage_handover
            ON usage_handover.id = m.context_id
           AND m.context_type = "handover"
         LEFT JOIN users recipient_user ON recipient_user.id = usage_handover.recipient_user_id
         LEFT JOIN departments recipient_department ON recipient_department.id = recipient_user.department_id
         LEFT JOIN users recipient_manager ON recipient_manager.id = recipient_user.manager_user_id
         LEFT JOIN users handover_manager ON handover_manager.id = usage_handover.manager_user_id
         ' . $where,
        $params
    );

    $employees = [];
    $departments = [];
    $managers = [];
    foreach ($rows as $row) {
        $employeeId = (int) ($row['employee_id'] ?? 0);
        $departmentId = (int) ($row['department_id'] ?? 0);
        $managerId = (int) ($row['manager_id'] ?? 0);

        if ($employeeId > 0) {
            $employees[$employeeId] = ['id' => $employeeId, 'name' => trim((string) ($row['employee_name'] ?? '')) ?: 'Unknown user'];
        }
        if ($departmentId > 0) {
            $departments[$departmentId] = ['id' => $departmentId, 'name' => trim((string) ($row['department_name'] ?? '')) ?: 'Unknown department'];
        }
        if ($managerId > 0) {
            $managers[$managerId] = ['id' => $managerId, 'name' => trim((string) ($row['manager_name'] ?? '')) ?: 'Unknown manager'];
        }
    }

    uasort($employees, static fn (array $left, array $right): int => strcasecmp($left['name'], $right['name']));
    uasort($departments, static fn (array $left, array $right): int => strcasecmp($left['name'], $right['name']));
    uasort($managers, static fn (array $left, array $right): int => strcasecmp($left['name'], $right['name']));

    $employees = array_values($employees);
    $departments = array_values($departments);
    $managers = array_values($managers);

    return [
        'employees' => $employees,
        'departments' => $departments,
        'managers' => $managers,
    ];
}

/**
 * @return array<int, array{id: int, name: string}>
 */
function report_summary_proof_file_entries(?string $proofFiles): array
{
    $entries = [];

    foreach (preg_split('/\s*\|\s*/', trim((string) $proofFiles)) ?: [] as $value) {
        if ($value === '' || preg_match('/^(\d+):(.*)$/s', $value, $matches) !== 1) {
            continue;
        }

        $id = (int) $matches[1];
        $name = trim((string) $matches[2]);

        if ($id <= 0 || isset($entries[$id])) {
            continue;
        }

        $entries[$id] = [
            'id' => $id,
            'name' => $name !== '' ? $name : 'Proof file',
        ];
    }

    return array_values($entries);
}

function report_summary_proof_file_names_text(?string $proofFiles): string
{
    return implode('; ', array_column(report_summary_proof_file_entries($proofFiles), 'name'));
}

function report_summary_storage_label(?int $storageId): string
{
    if ($storageId === null) {
        return 'All locations';
    }

    $storageScope = current_user_item_storage_scope();

    if ($storageScope !== null && !in_array($storageId, $storageScope, true)) {
        return 'Unavailable location';
    }

    $storage = Database::fetch(
        'SELECT name, storage_type FROM storages WHERE id = :id LIMIT 1',
        ['id' => $storageId]
    );

    if (!$storage) {
        return 'Unknown location';
    }

    return storage_type_label((string) $storage['storage_type']) . ' · ' . (string) $storage['name'];
}

function report_summary_movement_label(string $movementType): string
{
    return $movementType === '' ? 'All movement types' : ucfirst($movementType);
}

function report_summary_item_status_label(string $status): string
{
    if ($status === 'active') {
        return 'Active items';
    }

    if ($status === 'deleted') {
        return 'Deleted items';
    }

    return 'All item statuses';
}

function report_summary_item_record_status_label($isActive): string
{
    if ($isActive === null || $isActive === '') {
        return 'Unknown';
    }

    return (int) $isActive === 1 ? 'Active' : 'Deleted';
}
