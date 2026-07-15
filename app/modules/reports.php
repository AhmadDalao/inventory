<?php
declare(strict_types=1);

// Domain module: reports. Function names are preserved for route/view compatibility.

// Moved from controllers.php.

function report_summary_filters(): array
{
    $date = trim((string) query('date', date('Y-m-d')));
    $type = trim((string) query('movement_type', ''));
    $itemStatus = trim((string) query('item_status', 'all'));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = date('Y-m-d');
    }

    return [
        'date' => $date,
        'storage_id' => ctype_digit((string) query('storage_id', '')) ? (int) query('storage_id') : null,
        'movement_type' => in_array($type, ['restock', 'usage', 'adjustment', 'transfer'], true) ? $type : '',
        'item_status' => in_array($itemStatus, ['all', 'active', 'deleted'], true) ? $itemStatus : 'all',
    ];
}

function report_summary_quantity_expression(string $alias = 'm'): string
{
    return "ABS(COALESCE(NULLIF({$alias}.movement_quantity, 0), {$alias}.quantity_delta, 0))";
}

function report_summary_storage_label(?int $storageId): string
{
    if ($storageId === null) {
        return 'All locations';
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

function report_preset_cards(): array
{
    $today = date('Y-m-d');
    $monthStart = date('Y-m-01');
    $monthEnd = date('Y-m-t');
    $last30Start = date('Y-m-d', strtotime('-30 days'));

    $groups = [
        'Inventory' => [
	            [
	                'title' => 'Item Catalog',
	                'copy' => 'All active/deleted item records, SKU, barcode, unit, quantity, reorder level, value, and location summary.',
	                'icon' => 'items',
                'permission' => 'items.export',
                'download_url' => url('/exports/items?status=all'),
                'source_url' => url('/items'),
	                'badge' => 'Catalog',
	            ],
	            [
	                'title' => 'Company Assets',
	                'copy' => 'Durable property with serials, barcode tags, custody, condition, warranty, value, and current holder.',
	                'icon' => 'assets',
	                'permission' => 'assets.export',
	                'download_url' => url('/exports/assets.xlsx?active=all'),
	                'source_url' => url('/company-assets?active=all'),
	                'badge' => 'Assets',
	            ],
	            [
	                'title' => 'Storage Value',
                'copy' => 'Each storage with every item inside it, remaining quantity, used quantity, and stock value.',
                'icon' => 'storages',
                'permission' => 'storages.export',
                'download_url' => url('/exports/storages?status=active'),
                'source_url' => url('/storages'),
                'badge' => 'Value',
            ],
            [
                'title' => 'Today Stock Activity',
                'copy' => 'All restock, usage, transfer, and adjustment movements recorded today.',
                'icon' => 'movements',
                'permission' => 'movements.export',
                'download_url' => url('/exports/movements?date_from=' . rawurlencode($today) . '&date_to=' . rawurlencode($today)),
                'source_url' => url('/movements?date_from=' . rawurlencode($today) . '&date_to=' . rawurlencode($today)),
                'badge' => 'Today',
            ],
            [
                'title' => 'Low Stock Reorder',
                'copy' => 'Items at or below reorder level with suggested refill quantity and estimated value.',
                'icon' => 'reorder',
                'permission' => 'reorder.export',
                'download_url' => url('/exports/reorder'),
                'source_url' => url('/reorder'),
                'badge' => 'Refill',
            ],
            [
                'title' => 'Printable Label Data',
                'copy' => 'Open the label page to print item or storage barcodes after filtering.',
                'icon' => 'labels',
                'permission' => 'labels.view',
                'download_url' => '',
                'source_url' => url('/labels'),
                'badge' => 'Print',
            ],
        ],
        'Workflow' => [
            [
                'title' => 'This Month Usage',
                'copy' => 'Movement history filtered to usage events for the current month.',
                'icon' => 'movements',
                'permission' => 'movements.export',
                'download_url' => url('/exports/movements?movement_type=usage&date_from=' . rawurlencode($monthStart) . '&date_to=' . rawurlencode($monthEnd)),
                'source_url' => url('/movements?movement_type=usage&date_from=' . rawurlencode($monthStart) . '&date_to=' . rawurlencode($monthEnd)),
                'badge' => 'Usage',
            ],
            [
                'title' => 'Last 30 Days Transfers',
                'copy' => 'All stock transfers between warehouses and storages over the last 30 days.',
                'icon' => 'movements',
                'permission' => 'movements.export',
                'download_url' => url('/exports/movements?movement_type=transfer&date_from=' . rawurlencode($last30Start) . '&date_to=' . rawurlencode($today)),
                'source_url' => url('/movements?movement_type=transfer&date_from=' . rawurlencode($last30Start) . '&date_to=' . rawurlencode($today)),
                'badge' => 'Transfer',
            ],
            [
                'title' => 'Open Requests',
                'copy' => 'Pending and in-progress item requests for approval, receiving, or completion review.',
                'icon' => 'requests',
                'permission' => 'requests.export',
                'download_url' => url('/exports/requests?status=all&date_from=' . rawurlencode($last30Start) . '&date_to=' . rawurlencode($today)),
                'source_url' => url('/requests?status=all'),
                'badge' => 'Requests',
            ],
            [
                'title' => 'Requests Needing Decisions',
                'copy' => 'Request approvals still waiting for an owner or assigned admin decision.',
                'icon' => 'requests',
                'permission' => 'requests.export',
                'download_url' => url('/exports/requests?status=pending&date_from=' . rawurlencode($last30Start) . '&date_to=' . rawurlencode($today)),
                'source_url' => url('/requests?status=pending'),
                'badge' => 'Approve',
            ],
            [
                'title' => 'Handover Closeouts',
                'copy' => 'Temporary item issues, used quantities, returned quantities, and closeout status.',
                'icon' => 'handover',
                'permission' => 'handovers.export',
                'download_url' => url('/exports/handovers?status=all&date_from=' . rawurlencode($last30Start) . '&date_to=' . rawurlencode($today)),
                'source_url' => url('/handovers?status=all'),
                'badge' => 'Handover',
            ],
            [
                'title' => 'Open Handover Proof Trail',
                'copy' => 'Handovers that are still requested, delivered, awaiting receipt, or waiting final approval.',
                'icon' => 'handover',
                'permission' => 'handovers.export',
                'download_url' => url('/exports/handovers?status=open&date_from=' . rawurlencode($last30Start) . '&date_to=' . rawurlencode($today)),
                'source_url' => url('/handovers?status=open'),
                'badge' => 'Open',
            ],
        ],
        'Finance And Suppliers' => [
            [
                'title' => 'Purchase Approval Queue',
                'copy' => 'Supplier purchases submitted for approval before stock can move.',
                'icon' => 'purchases',
                'permission' => 'purchases.export',
                'download_url' => url('/exports/purchases?status=pending_approval&date_from=' . rawurlencode($last30Start) . '&date_to=' . rawurlencode($today)),
                'source_url' => url('/purchases?status=pending_approval'),
                'badge' => 'Approve',
            ],
            [
                'title' => 'Purchase Receiving Queue',
                'copy' => 'Approved or receipt-review purchases that still need received quantities confirmed.',
                'icon' => 'purchases',
                'permission' => 'purchases.export',
                'download_url' => url('/exports/purchases?status=receipt_review&date_from=' . rawurlencode($last30Start) . '&date_to=' . rawurlencode($today)),
                'source_url' => url('/purchases?status=receipt_review'),
                'badge' => 'Receive',
            ],
            [
                'title' => 'Completed Purchases',
                'copy' => 'Supplier purchases that finished receiving and posted restock movements.',
                'icon' => 'purchases',
                'permission' => 'purchases.export',
                'download_url' => url('/exports/purchases?status=completed&date_from=' . rawurlencode($monthStart) . '&date_to=' . rawurlencode($monthEnd)),
                'source_url' => url('/purchases?status=completed'),
                'badge' => 'Purchases',
            ],
            [
                'title' => 'Supplier Directory',
                'copy' => 'Supplier type, phone, VAT, CR, authorized person, purchase totals, and status.',
                'icon' => 'supplier',
                'permission' => 'suppliers.export',
                'download_url' => url('/exports/suppliers?status=all'),
                'source_url' => url('/suppliers'),
                'badge' => 'Suppliers',
            ],
            [
                'title' => 'Protected Files',
                'copy' => 'Purchase documents, item images, proof files, uploaders, and linked workflow records.',
                'icon' => 'files',
                'permission' => 'files.export',
                'download_url' => url('/exports/files?status=active'),
                'source_url' => url('/files'),
                'badge' => 'Files',
            ],
        ],
        'Control' => [
            [
                'title' => 'Stocktake Variance',
                'copy' => 'Cycle count records with expected quantity, counted quantity, variance, and approver.',
                'icon' => 'stocktakes',
                'permission' => 'stocktakes.export',
                'download_url' => url('/exports/stocktakes?status=all'),
                'source_url' => url('/stocktakes'),
                'badge' => 'Counts',
            ],
            [
                'title' => 'Audit Trail',
                'copy' => 'Admin activity, entity changes, IP address, user, and metadata.',
                'icon' => 'audit',
                'permission' => 'audit.export',
                'download_url' => url('/exports/audit?date_from=' . rawurlencode($last30Start) . '&date_to=' . rawurlencode($today)),
                'source_url' => url('/audit-log'),
                'badge' => 'Audit',
            ],
            [
                'title' => 'Email Delivery',
                'copy' => 'Password reset, setup, test email, workflow alert delivery, failures, and suppressions.',
                'icon' => 'notification',
                'permission' => 'email_logs.export',
                'download_url' => url('/exports/email-logs?date_from=' . rawurlencode($last30Start) . '&date_to=' . rawurlencode($today)),
                'source_url' => url('/email-logs'),
                'badge' => 'Mailer',
            ],
            [
                'title' => 'Users And Permissions',
                'copy' => 'Active/deleted users, roles, positions, assigned owners, and permission counts.',
                'icon' => 'users',
                'permission' => 'users.export',
                'download_url' => url('/exports/users?status=all'),
                'source_url' => url('/users'),
                'badge' => 'Access',
            ],
        ],
    ];

    foreach ($groups as $groupName => $cards) {
        $groups[$groupName] = array_values(array_filter($cards, static function (array $card): bool {
            return Auth::hasPermission((string) $card['permission']);
        }));

        if ($groups[$groupName] === []) {
            unset($groups[$groupName]);
        }
    }

    return $groups;
}

function handle_reports_index(): void
{
    app_ready_or_redirect();

    if (Auth::isStaff() || !reports_can_access()) {
        abort(403, 'You do not have access to report presets.');
    }

    $summaryFilters = report_summary_filters();
    $canViewDailySummary = Auth::hasPermission('movements.view') || Auth::hasPermission('movements.export');
    $summaryQuery = http_build_query(array_filter($summaryFilters, static fn ($value): bool => $value !== null && trim((string) $value) !== ''));

    View::render('reports/index', [
        'title' => site_setting('page.reports', 'Reports'),
        'groups' => report_preset_cards(),
        'summaryFilters' => $summaryFilters,
        'summary' => $canViewDailySummary ? report_summary_data($summaryFilters) : null,
        'storages' => all_storages_for_select($summaryFilters['storage_id']),
        'canViewDailySummary' => $canViewDailySummary,
        'savedPresets' => saved_report_presets(),
        'savedPresetTypes' => saved_report_preset_types(),
        'currentReportQuery' => $summaryQuery,
    ]);
}

// Moved from report_presets.php.

function saved_report_preset_types(): array
{
    return [
        'daily_operations' => [
            'label' => 'Daily operations',
            'icon' => 'reports',
            'source_path' => '/reports',
            'export_csv_path' => '/exports/daily-summary',
            'export_xlsx_path' => '/exports/daily-summary.xlsx',
            'view_permission' => 'movements.view',
            'export_permission' => 'movements.export',
            'default_filters' => ['date' => date('Y-m-d')],
        ],
        'finance' => [
            'label' => 'Finance purchases',
            'icon' => 'purchases',
            'source_path' => '/purchases',
            'export_csv_path' => '/exports/purchases',
            'export_xlsx_path' => '',
            'view_permission' => 'purchases.view',
            'export_permission' => 'purchases.export',
            'default_filters' => ['status' => 'all'],
        ],
        'usage_by_reason' => [
            'label' => 'Usage by reason',
            'icon' => 'movements',
            'source_path' => '/reports',
            'export_csv_path' => '/exports/daily-summary',
            'export_xlsx_path' => '/exports/daily-summary.xlsx',
            'view_permission' => 'movements.view',
            'export_permission' => 'movements.export',
            'default_filters' => ['date' => date('Y-m-d'), 'movement_type' => 'usage'],
        ],
        'storage_owner' => [
            'label' => 'Storage owner summary',
            'icon' => 'storages',
            'source_path' => '/storages',
            'export_csv_path' => '/exports/storages',
            'export_xlsx_path' => '/exports/storages.xlsx',
            'view_permission' => 'storages.view',
            'export_permission' => 'storages.export',
            'default_filters' => ['status' => 'active'],
        ],
        'purchases' => [
            'label' => 'Purchases',
            'icon' => 'purchases',
            'source_path' => '/purchases',
            'export_csv_path' => '/exports/purchases',
            'export_xlsx_path' => '',
            'view_permission' => 'purchases.view',
            'export_permission' => 'purchases.export',
            'default_filters' => ['status' => 'all'],
        ],
        'assets' => [
            'label' => 'Assets',
            'icon' => 'assets',
            'source_path' => '/company-assets',
            'export_csv_path' => '/exports/assets',
            'export_xlsx_path' => '/exports/assets.xlsx',
            'view_permission' => 'assets.view',
            'export_permission' => 'assets.export',
            'default_filters' => ['active' => 'all'],
        ],
        'stock_movements' => [
            'label' => 'Stock movements',
            'icon' => 'movements',
            'source_path' => '/movements',
            'export_csv_path' => '/exports/movements',
            'export_xlsx_path' => '/exports/movements.xlsx',
            'view_permission' => 'movements.view',
            'export_permission' => 'movements.export',
            'default_filters' => [],
        ],
        'requests' => [
            'label' => 'Requests',
            'icon' => 'requests',
            'source_path' => '/requests',
            'export_csv_path' => '/exports/requests',
            'export_xlsx_path' => '',
            'view_permission' => 'requests.view',
            'export_permission' => 'requests.export',
            'default_filters' => ['status' => 'all'],
        ],
        'handovers' => [
            'label' => 'Handovers',
            'icon' => 'handover',
            'source_path' => '/handovers',
            'export_csv_path' => '/exports/handovers',
            'export_xlsx_path' => '',
            'view_permission' => 'handovers.view',
            'export_permission' => 'handovers.export',
            'default_filters' => ['status' => 'all'],
        ],
    ];
}

function saved_report_preset_type(string $type): ?array
{
    $types = saved_report_preset_types();

    return $types[$type] ?? null;
}

function saved_report_can_view_type(string $type): bool
{
    $definition = saved_report_preset_type($type);

    if ($definition === null) {
        return false;
    }

    return Auth::hasPermission((string) $definition['view_permission'])
        || Auth::hasPermission((string) $definition['export_permission']);
}

function saved_report_can_export_type(string $type): bool
{
    $definition = saved_report_preset_type($type);

    return $definition !== null && Auth::hasPermission((string) $definition['export_permission']);
}

function saved_report_filter_state_from_query(string $queryString): array
{
    parse_str(ltrim($queryString, '?'), $parsed);

    $filters = [];

    foreach ($parsed as $key => $value) {
        if (!is_string($key) || $key === '' || $key === '_token') {
            continue;
        }

        if (is_array($value)) {
            $value = implode(',', array_map(static fn ($item): string => trim((string) $item), $value));
        }

        $value = trim((string) $value);

        if ($value === '') {
            continue;
        }

        $filters[preg_replace('/[^a-zA-Z0-9_\\-]/', '', $key) ?: $key] = mb_substr($value, 0, 190);
    }

    return $filters;
}

function saved_report_url(string $path, array $filters): string
{
    $query = http_build_query(array_filter($filters, static fn ($value): bool => trim((string) $value) !== ''));

    return url($path . ($query !== '' ? '?' . $query : ''));
}

function saved_report_preset_urls(array $preset): array
{
    $definition = saved_report_preset_type((string) $preset['report_type']);
    $filters = json_decode((string) ($preset['filters_json'] ?? '{}'), true);
    $filters = is_array($filters) ? $filters : [];

    if ($definition === null) {
        return ['source_url' => url('/reports'), 'export_url' => '', 'export_label' => 'Export'];
    }

    $format = (string) ($preset['export_format'] ?? 'csv');
    $exportPath = $format === 'xlsx' && ($definition['export_xlsx_path'] ?? '') !== ''
        ? (string) $definition['export_xlsx_path']
        : (string) $definition['export_csv_path'];

    return [
        'source_url' => saved_report_url((string) $definition['source_path'], $filters),
        'export_url' => $exportPath !== '' && saved_report_can_export_type((string) $preset['report_type'])
            ? saved_report_url($exportPath, $filters)
            : '',
        'export_label' => strtoupper($format),
    ];
}

function saved_report_presets(): array
{
    $user = Auth::user();
    $userId = (int) ($user['id'] ?? 0);

    $rows = Database::fetchAll(
        'SELECT presets.*, creator.name AS creator_name
         FROM report_presets presets
         LEFT JOIN users creator ON creator.id = presets.created_by
         WHERE presets.is_active = 1
           AND (presets.visibility = "shared" OR presets.created_by = :user_id)
         ORDER BY presets.updated_at DESC, presets.created_at DESC, presets.name ASC',
        ['user_id' => $userId]
    );

    return array_values(array_filter($rows, static function (array $preset): bool {
        return saved_report_can_view_type((string) $preset['report_type']);
    }));
}

function handle_report_preset_save_submit(?array $params = null): void
{
    app_ready_or_redirect();
    verify_csrf();

    if (!Auth::isAdmin() || !reports_can_access()) {
        abort(403, 'You do not have access to save report presets.');
    }

    $id = isset($params['id']) && ctype_digit((string) $params['id']) ? (int) $params['id'] : null;
    $name = trim((string) ($_POST['name'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $type = trim((string) ($_POST['report_type'] ?? 'daily_operations'));
    $format = trim((string) ($_POST['export_format'] ?? 'csv'));
    $visibility = trim((string) ($_POST['visibility'] ?? 'shared'));
    $filterQuery = trim((string) ($_POST['filter_query'] ?? ''));

    if ($name === '') {
        flash('danger', 'Preset name is required.');
        redirect('/reports');
    }

    if (!saved_report_can_view_type($type)) {
        flash('danger', 'You do not have permission for that report type.');
        redirect('/reports');
    }

    $definition = saved_report_preset_type($type);
    $filters = saved_report_filter_state_from_query($filterQuery);

    if ($filters === [] && $definition !== null) {
        $filters = (array) ($definition['default_filters'] ?? []);
    }

    $payload = [
        'name' => mb_substr($name, 0, 160),
        'description' => mb_substr($description, 0, 500),
        'report_type' => $type,
        'filters_json' => json_encode($filters, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'export_format' => in_array($format, ['csv', 'xlsx'], true) ? $format : 'csv',
        'visibility' => in_array($visibility, ['shared', 'private'], true) ? $visibility : 'shared',
        'user_id' => (int) (Auth::user()['id'] ?? 0),
    ];

    if ($id !== null) {
        $existing = Database::fetch('SELECT * FROM report_presets WHERE id = :id LIMIT 1', ['id' => $id]);

        if (!$existing) {
            abort(404, 'Report preset not found.');
        }

        if (!Auth::isOwner() && (int) $existing['created_by'] !== $payload['user_id']) {
            abort(403, 'Only the owner or preset creator can edit this preset.');
        }

        Database::execute(
            'UPDATE report_presets
             SET name = :name,
                 description = :description,
                 report_type = :report_type,
                 filters_json = :filters_json,
                 export_format = :export_format,
                 visibility = :visibility,
                 updated_by = :user_id,
                 updated_at = NOW()
             WHERE id = :id',
            $payload + ['id' => $id]
        );

        record_activity('report_preset_updated', 'report_preset', $id, 'Updated report preset ' . $payload['name'] . '.', [
            'report_type' => $type,
            'filters' => $filters,
        ]);
        flash('success', 'Report preset updated.');
        redirect('/reports');
    }

    Database::execute(
        'INSERT INTO report_presets (
            name,
            description,
            report_type,
            filters_json,
            export_format,
            visibility,
            is_active,
            created_by,
            updated_by,
            created_at,
            updated_at
         ) VALUES (
            :name,
            :description,
            :report_type,
            :filters_json,
            :export_format,
            :visibility,
            1,
            :created_by,
            :updated_by,
            NOW(),
            NOW()
         )',
        [
            'name' => $payload['name'],
            'description' => $payload['description'],
            'report_type' => $payload['report_type'],
            'filters_json' => $payload['filters_json'],
            'export_format' => $payload['export_format'],
            'visibility' => $payload['visibility'],
            'created_by' => $payload['user_id'],
            'updated_by' => $payload['user_id'],
        ]
    );

    $presetId = Database::lastInsertId();
    record_activity('report_preset_created', 'report_preset', $presetId, 'Created report preset ' . $payload['name'] . '.', [
        'report_type' => $type,
        'filters' => $filters,
    ]);
    flash('success', 'Report preset saved.');
    redirect('/reports');
}

function handle_report_preset_duplicate_submit(array $params): void
{
    app_ready_or_redirect();
    verify_csrf();

    if (!Auth::isAdmin() || !reports_can_access()) {
        abort(403, 'You do not have access to duplicate report presets.');
    }

    $id = ctype_digit((string) ($params['id'] ?? '')) ? (int) $params['id'] : 0;
    $preset = Database::fetch('SELECT * FROM report_presets WHERE id = :id AND is_active = 1 LIMIT 1', ['id' => $id]);

    if (!$preset || !saved_report_can_view_type((string) $preset['report_type'])) {
        abort(404, 'Report preset not found.');
    }

    $userId = (int) (Auth::user()['id'] ?? 0);

    Database::execute(
        'INSERT INTO report_presets (
            name,
            description,
            report_type,
            filters_json,
            export_format,
            visibility,
            is_active,
            created_by,
            updated_by,
            created_at,
            updated_at
         ) VALUES (
            :name,
            :description,
            :report_type,
            :filters_json,
            :export_format,
            "private",
            1,
            :created_by,
            :updated_by,
            NOW(),
            NOW()
         )',
        [
            'name' => mb_substr((string) $preset['name'] . ' copy', 0, 160),
            'description' => (string) ($preset['description'] ?? ''),
            'report_type' => (string) $preset['report_type'],
            'filters_json' => (string) $preset['filters_json'],
            'export_format' => (string) $preset['export_format'],
            'created_by' => $userId,
            'updated_by' => $userId,
        ]
    );

    flash('success', 'Report preset duplicated.');
    redirect('/reports');
}

function handle_report_preset_archive_submit(array $params): void
{
    app_ready_or_redirect();
    verify_csrf();

    if (!Auth::isAdmin() || !reports_can_access()) {
        abort(403, 'You do not have access to archive report presets.');
    }

    $id = ctype_digit((string) ($params['id'] ?? '')) ? (int) $params['id'] : 0;
    $preset = Database::fetch('SELECT * FROM report_presets WHERE id = :id LIMIT 1', ['id' => $id]);

    if (!$preset) {
        abort(404, 'Report preset not found.');
    }

    $userId = (int) (Auth::user()['id'] ?? 0);

    if (!Auth::isOwner() && (int) $preset['created_by'] !== $userId) {
        abort(403, 'Only the owner or preset creator can archive this preset.');
    }

    Database::execute(
        'UPDATE report_presets
         SET is_active = 0,
             archived_at = NOW(),
             archived_by = :archived_by,
             updated_by = :updated_by,
             updated_at = NOW()
         WHERE id = :id',
        [
            'id' => $id,
            'archived_by' => $userId,
            'updated_by' => $userId,
        ]
    );

    record_activity('report_preset_archived', 'report_preset', $id, 'Archived report preset ' . (string) $preset['name'] . '.');
    flash('success', 'Report preset archived.');
    redirect('/reports');
}
