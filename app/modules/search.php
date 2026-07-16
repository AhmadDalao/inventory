<?php
declare(strict_types=1);

// Domain module: search. Function names are preserved for route/view compatibility.

// Moved from workflows.php.

function global_search_normalize_query(string $query): string
{
    $query = trim(preg_replace('/\s+/u', ' ', $query) ?: '');

    if (mb_strlen($query) > 80) {
        $query = mb_substr($query, 0, 80);
    }

    return $query;
}

function global_search_like(string $query): string
{
    return '%' . addcslashes($query, "\\%_") . '%';
}

function global_search_result(string $group, string $title, string $subtitle, string $url, string $icon = 'search', string $badge = ''): array
{
    return [
        'group' => $group,
        'title' => $title,
        'subtitle' => $subtitle,
        'url' => $url,
        'icon' => $icon,
        'badge' => $badge,
    ];
}

function global_search_text_matches(string $query, array $values): bool
{
    $haystack = mb_strtolower(implode(' ', array_map(static fn ($value): string => (string) $value, $values)));

    return mb_strpos($haystack, mb_strtolower($query)) !== false;
}

function global_search_accessible_pages(string $query): array
{
    $pages = [
        ['title' => site_setting('page.dashboard', 'Dashboard'), 'group' => 'Pages', 'url' => '/dashboard', 'icon' => 'dashboard', 'terms' => ['dashboard', 'overview', 'metrics'], 'allowed' => Auth::hasPermission('dashboard.view')],
        ['title' => site_setting('page.storages', 'Storages'), 'group' => 'Pages', 'url' => '/storages', 'icon' => 'storages', 'terms' => ['storages', 'warehouses', 'locations'], 'allowed' => !Auth::isStaff() && Auth::hasPermission('storages.view')],
        ['title' => site_setting('page.items', 'Items'), 'group' => 'Pages', 'url' => '/items', 'icon' => 'items', 'terms' => ['items', 'catalog', 'sku', 'stock'], 'allowed' => !Auth::isStaff() && Auth::hasPermission('items.view')],
        ['title' => site_setting('page.assets', 'Assets'), 'group' => 'Pages', 'url' => '/company-assets', 'icon' => 'assets', 'terms' => ['assets', 'asset', 'equipment', 'serial', 'property', 'custody', 'maintenance'], 'allowed' => Auth::hasPermission('assets.view')],
        ['title' => 'Asset Categories', 'group' => 'Pages', 'url' => '/company-assets/categories', 'icon' => 'assets', 'terms' => ['asset categories', 'asset category', 'asset hierarchy', 'subcategories', 'equipment categories'], 'allowed' => can_manage_asset_categories()],
        ['title' => site_setting('page.movements', 'Movement Log'), 'group' => 'Pages', 'url' => '/movements', 'icon' => 'movements', 'terms' => ['movement', 'usage', 'restock', 'transfer', 'adjustment'], 'allowed' => !Auth::isStaff() && Auth::hasPermission('movements.view')],
        ['title' => site_setting('page.scan', 'Scan Center'), 'group' => 'Pages', 'url' => '/scan', 'icon' => 'scan', 'terms' => ['scan', 'scanner', 'barcode', 'camera', 'hardware scanner', 'quick usage'], 'allowed' => !Auth::isStaff() && Auth::hasPermission('items.view')],
        ['title' => site_setting('page.requests', 'Requests'), 'group' => 'Pages', 'url' => '/requests', 'icon' => 'requests', 'terms' => ['requests', 'transfers', 'issue'], 'allowed' => Auth::hasPermission('requests.view')],
        ['title' => site_setting('page.handovers', 'Handovers'), 'group' => 'Pages', 'url' => '/handovers', 'icon' => 'handover', 'terms' => ['handovers', 'temporary issue', 'staff'], 'allowed' => Auth::hasPermission('handovers.view')],
        ['title' => site_setting('page.purchases', 'Purchases'), 'group' => 'Pages', 'url' => '/purchases', 'icon' => 'purchases', 'terms' => ['purchases', 'supplier', 'receipt', 'quote'], 'allowed' => !Auth::isStaff() && Auth::hasPermission('purchases.view')],
        ['title' => site_setting('page.reports', 'Reports'), 'group' => 'Pages', 'url' => '/reports', 'icon' => 'reports', 'terms' => ['reports', 'exports', 'presets', 'csv', 'stock value', 'usage report', 'daily summary', 'date summary', 'day report'], 'allowed' => !Auth::isStaff() && reports_can_access()],
        ['title' => site_setting('page.files', 'Files'), 'group' => 'Pages', 'url' => '/files', 'icon' => 'files', 'terms' => ['files', 'documents', 'proof', 'receipt'], 'allowed' => file_library_can_access(Auth::user())],
        ['title' => site_setting('page.documentation', 'Documentation'), 'group' => 'Pages', 'url' => '/documentation', 'icon' => 'documentation', 'terms' => ['documentation', 'help', 'training', 'guide'], 'allowed' => true],
        ['title' => 'Notifications', 'group' => 'Pages', 'url' => '/notifications', 'icon' => 'notification', 'terms' => ['notifications', 'inbox', 'alerts', 'approvals'], 'allowed' => Auth::check()],
        ['title' => site_setting('page.stocktakes', 'Stocktakes'), 'group' => 'Pages', 'url' => '/stocktakes', 'icon' => 'stocktakes', 'terms' => ['stocktakes', 'counts', 'cycle count'], 'allowed' => !Auth::isStaff() && Auth::hasPermission('stocktakes.view')],
        ['title' => site_setting('page.suppliers', 'Suppliers'), 'group' => 'Pages', 'url' => '/suppliers', 'icon' => 'supplier', 'terms' => ['suppliers', 'vendors', 'vat'], 'allowed' => !Auth::isStaff() && Auth::hasPermission('suppliers.view')],
        ['title' => site_setting('page.reorder', 'Reorder Center'), 'group' => 'Pages', 'url' => '/reorder', 'icon' => 'reorder', 'terms' => ['reorder', 'low stock', 'refill'], 'allowed' => !Auth::isStaff() && Auth::hasPermission('reorder.view')],
        ['title' => site_setting('page.labels', 'Labels'), 'group' => 'Pages', 'url' => '/labels', 'icon' => 'labels', 'terms' => ['labels', 'barcode', 'print'], 'allowed' => !Auth::isStaff() && Auth::hasPermission('labels.view')],
        ['title' => site_setting('page.users', 'Admins'), 'group' => 'Pages', 'url' => '/users', 'icon' => 'users', 'terms' => ['admins', 'users', 'roles', 'permissions'], 'allowed' => !Auth::isStaff() && Auth::hasPermission('users.view')],
        ['title' => site_setting('page.audit', 'Audit Log'), 'group' => 'Pages', 'url' => '/audit-log', 'icon' => 'audit', 'terms' => ['audit', 'activity', 'logs'], 'allowed' => !Auth::isStaff() && Auth::hasPermission('audit.view')],
        ['title' => site_setting('page.email_logs', 'Email Logs'), 'group' => 'Pages', 'url' => '/email-logs', 'icon' => 'notification', 'terms' => ['email', 'mailer', 'smtp', 'delivery', 'password reset', 'workflow alerts'], 'allowed' => !Auth::isStaff() && Auth::hasPermission('email_logs.view')],
        ['title' => site_setting('page.settings', 'Website Control'), 'group' => 'Pages', 'url' => '/settings/site', 'icon' => 'settings', 'terms' => ['website control', 'settings', 'theme', 'labels', 'barcode', 'ocr', 'openai', 'email', 'smtp', 'logo', 'thumbnail', 'export'], 'allowed' => !Auth::isStaff() && Auth::hasPermission('settings.view')],
    ];

    $results = [];

    foreach ($pages as $page) {
        if (!$page['allowed'] || !global_search_text_matches($query, array_merge([$page['title']], $page['terms']))) {
            continue;
        }

        $results[] = global_search_result($page['group'], $page['title'], 'Open page', url($page['url']), $page['icon'], 'Page');
    }

    return array_slice($results, 0, 6);
}

function global_search_documentation_results(string $query): array
{
    $results = [];

    foreach (documentation_sections() as $section) {
        if (!global_search_text_matches($query, [
            $section['title'],
            $section['audience'],
            $section['summary'],
            implode(' ', $section['features']),
            implode(' ', $section['steps']),
            implode(' ', $section['rules']),
        ])) {
            continue;
        }

        $results[] = global_search_result('Documentation', (string) $section['title'], (string) $section['summary'], url('/documentation#doc-' . $section['slug']), (string) $section['icon'], 'Guide');

        if (count($results) >= 3) {
            break;
        }
    }

    return $results;
}

function global_search_settings_results(string $query): array
{
    if (Auth::isStaff() || !Auth::hasPermission('settings.view')) {
        return [];
    }

    $results = [];
    $canSeeSecrets = Auth::hasPermission('settings.secrets');

    foreach (site_setting_schema() as $group) {
        $groupTitle = (string) ($group['title'] ?? 'Settings');
        $groupCopy = (string) ($group['copy'] ?? '');

        foreach (($group['fields'] ?? []) as $key => $field) {
            if (($field['type'] ?? 'text') === 'secret' && !$canSeeSecrets) {
                continue;
            }

            $optionsText = '';
            if (!empty($field['options']) && is_array($field['options'])) {
                $optionsText = implode(' ', array_map(
                    static fn ($optionValue, $optionLabel): string => (string) $optionValue . ' ' . (string) $optionLabel,
                    array_keys($field['options']),
                    array_values($field['options'])
                ));
            }

            if (!global_search_text_matches($query, [
                $groupTitle,
                $groupCopy,
                $key,
                $field['label'] ?? '',
                $field['help'] ?? '',
                $field['default'] ?? '',
                $optionsText,
            ])) {
                continue;
            }

            $fieldAnchor = 'setting-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $key);
            $results[] = global_search_result(
                'Settings',
                (string) ($field['label'] ?? $key),
                $groupTitle . ' · ' . (string) $key,
                url('/settings/site?settings_search=' . rawurlencode($query) . '#' . $fieldAnchor),
                'settings',
                'Setting'
            );

            if (count($results) >= 6) {
                return $results;
            }
        }
    }

    return $results;
}

function global_search_fallback_url(string $query): string
{
    if (!Auth::isStaff() && Auth::hasPermission('items.view')) {
        return url('/items?search=' . rawurlencode($query));
    }

    if (Auth::hasPermission('requests.view')) {
        return url('/requests?search=' . rawurlencode($query));
    }

    if (Auth::hasPermission('handovers.view')) {
        return url('/handovers?search=' . rawurlencode($query));
    }

    return url('/documentation');
}

function global_search_results(string $query): array
{
    $like = global_search_like($query);
    $results = global_search_accessible_pages($query);
    $directTarget = workflow_reference_open_target($query);

    if ($directTarget !== null) {
        array_unshift($results, workflow_reference_global_result($directTarget));
    }

    if (!Auth::isStaff() && Auth::hasPermission('items.view')) {
        $rows = Database::fetchAll(
            'SELECT i.id, i.name, i.sku, i.barcode, i.category, i.unit, i.current_quantity
             FROM items i
             WHERE i.is_active = 1
               AND (
                   i.name LIKE ?
                   OR i.sku LIKE ?
                   OR COALESCE(i.barcode, "") LIKE ?
                   OR COALESCE(i.category, "") LIKE ?
                   OR EXISTS (
                       SELECT 1
                       FROM item_storage_balances balance
                       INNER JOIN storages storage ON storage.id = balance.storage_id
                       WHERE balance.item_id = i.id
                         AND storage.name LIKE ?
                   )
               )
             ORDER BY i.name ASC
             LIMIT 5',
            array_fill(0, 5, $like)
        );

        foreach ($rows as $row) {
            $scanCode = normalize_item_barcode($row['barcode'] ?? '') !== '' ? (string) $row['barcode'] : (string) $row['sku'];
            $results[] = global_search_result(
                'Items',
                (string) $row['name'],
                trim((string) $row['sku'] . ' · Scan: ' . $scanCode . ' · ' . format_quantity($row['current_quantity']) . ' ' . $row['unit']),
                url('/items/' . $row['id']),
                'items',
                $row['category'] ? (string) $row['category'] : 'Item'
            );
        }
    }

    if (Auth::hasPermission('assets.view')) {
        [$where, $params] = build_asset_where([
            'search' => $query,
            'status' => 'all',
            'condition' => 'all',
            'storage_id' => null,
            'assigned_user_id' => null,
            'active' => Auth::isStaff() ? 'active' : 'all',
        ], 'a');

        $rows = Database::fetchAll(
            company_asset_select_sql() . "
             {$where}
             ORDER BY a.is_active DESC, a.updated_at DESC, a.id DESC
             LIMIT 5",
            $params
        );

        foreach ($rows as $row) {
            $holder = $row['assigned_user_name'] ?: ($row['storage_name'] ?: 'No custody');
            $results[] = global_search_result(
                'Assets',
                (string) $row['name'],
                (string) $row['asset_number'] . ' · ' . asset_status_label((string) $row['status']) . ' · ' . $holder,
                url('/company-assets/' . $row['id']),
                'assets',
                'Asset'
            );
        }
    }

    if (!Auth::isStaff() && Auth::hasPermission('storages.view')) {
        $rows = Database::fetchAll(
            'SELECT id, name, storage_type, notes
             FROM storages
             WHERE is_active = 1
               AND is_system = 0
               AND (name LIKE ? OR storage_type LIKE ? OR COALESCE(notes, "") LIKE ?)
             ORDER BY name ASC
             LIMIT 5',
            array_fill(0, 3, $like)
        );

        foreach ($rows as $row) {
            $results[] = global_search_result('Storages', (string) $row['name'], storage_type_label((string) $row['storage_type']), url('/storages/' . $row['id']), 'storages', 'Location');
        }
    }

    if (!Auth::isStaff() && Auth::hasPermission('movements.view')) {
        $rows = Database::fetchAll(
            'SELECT movement.id, movement.movement_type, movement.reference_code, movement.used_at, item.name AS item_name, item.sku,
                    source_storage.name AS source_name, destination_storage.name AS destination_name
             FROM inventory_movements movement
             INNER JOIN items item ON item.id = movement.item_id
             LEFT JOIN storages source_storage ON source_storage.id = movement.source_storage_id
             LEFT JOIN storages destination_storage ON destination_storage.id = movement.destination_storage_id
             WHERE item.name LIKE ?
                OR item.sku LIKE ?
                OR movement.movement_type LIKE ?
                OR COALESCE(movement.reference_code, "") LIKE ?
                OR COALESCE(movement.notes, "") LIKE ?
                OR COALESCE(source_storage.name, "") LIKE ?
                OR COALESCE(destination_storage.name, "") LIKE ?
             ORDER BY movement.used_at DESC, movement.id DESC
             LIMIT 4',
            array_fill(0, 7, $like)
        );

        foreach ($rows as $row) {
            $reference = $row['reference_code'] ? ' · Ref ' . $row['reference_code'] : '';
            $results[] = global_search_result('Movements', ucfirst((string) $row['movement_type']) . ' · ' . $row['item_name'], (string) $row['sku'] . $reference, url('/movements?search=' . rawurlencode((string) ($row['reference_code'] ?: $row['sku']))), 'movements', 'Log');
        }
    }

    if (Auth::hasPermission('requests.view')) {
        [$where, $params] = build_request_where([
            'search' => $query,
            'status' => 'all',
            'storage_id' => null,
            'date_from' => '',
            'date_to' => '',
        ], 'r');
        $rows = Database::fetchAll(
            "SELECT r.id, r.request_number, r.request_mode, r.status, requester.name AS requester_name,
                    source_storage.name AS source_storage_name, destination_storage.name AS destination_storage_name
             FROM item_requests r
             INNER JOIN users requester ON requester.id = r.requester_user_id
             INNER JOIN users approver ON approver.id = r.approver_user_id
             INNER JOIN storages source_storage ON source_storage.id = r.source_storage_id
             LEFT JOIN storages destination_storage ON destination_storage.id = r.destination_storage_id
             {$where}
             ORDER BY r.requested_at DESC, r.id DESC
             LIMIT 5",
            $params
        );

        foreach ($rows as $row) {
            $results[] = global_search_result('Requests', (string) $row['request_number'], request_status_label((string) $row['status']) . ' · ' . (string) $row['requester_name'], url('/requests/' . $row['id']), 'requests', ucfirst((string) $row['request_mode']));
        }
    }

    if (Auth::hasPermission('handovers.view')) {
        [$where, $params] = build_handover_where([
            'search' => $query,
            'status' => 'all',
            'storage_id' => null,
            'date_from' => '',
            'date_to' => '',
        ], 'h');
        $rows = Database::fetchAll(
            "SELECT h.id, h.handover_number, h.status, h.recipient_name, h.recipient_type,
                    source_storage.name AS source_storage_name,
                    destination_storage.name AS destination_storage_name
             FROM handovers h
             INNER JOIN storages source_storage ON source_storage.id = h.source_storage_id
             LEFT JOIN storages destination_storage ON destination_storage.id = h.destination_storage_id
             {$where}
             ORDER BY h.issued_at DESC, h.id DESC
             LIMIT 5",
            $params
        );

        foreach ($rows as $row) {
            $subtitleParts = [handover_status_label((string) $row['status']), (string) $row['recipient_name']];

            if (($row['recipient_type'] ?? 'staff') === 'storage' && !empty($row['destination_storage_name'])) {
                $subtitleParts[] = (string) $row['source_storage_name'] . ' to ' . (string) $row['destination_storage_name'];
            }

            $results[] = global_search_result('Handovers', (string) $row['handover_number'], implode(' · ', array_filter($subtitleParts)), url('/handovers/' . $row['id']), 'handover', 'Handover');
        }
    }

    if (!Auth::isStaff() && Auth::hasPermission('purchases.view')) {
        $rows = Database::fetchAll(
            'SELECT p.id, p.purchase_number, p.status, supplier.name AS supplier_name, storage.name AS storage_name
             FROM purchases p
             INNER JOIN suppliers supplier ON supplier.id = p.supplier_id
             INNER JOIN storages storage ON storage.id = p.destination_storage_id
             WHERE p.purchase_number LIKE ?
                OR p.status LIKE ?
                OR supplier.name LIKE ?
                OR storage.name LIKE ?
                OR EXISTS (
                    SELECT 1
                    FROM purchase_lines line
                    WHERE line.purchase_id = p.id
                      AND (line.item_name LIKE ? OR line.item_sku LIKE ?)
                )
             ORDER BY p.created_at DESC, p.id DESC
             LIMIT 5',
            array_fill(0, 6, $like)
        );

        foreach ($rows as $row) {
            $results[] = global_search_result('Purchases', (string) $row['purchase_number'], purchase_status_label((string) $row['status']) . ' · ' . (string) $row['supplier_name'], url('/purchases/' . $row['id']), 'purchases', 'PO');
        }
    }

    if (!Auth::isStaff() && Auth::hasPermission('suppliers.view')) {
        $rows = Database::fetchAll(
            'SELECT id, name, supplier_type, supplier_type_other, phone, email, tax_number, commercial_registration, national_address, authorized_person
             FROM suppliers
             WHERE is_active = 1
               AND (
                   name LIKE ?
                   OR supplier_type LIKE ?
                   OR COALESCE(supplier_type_other, "") LIKE ?
                   OR COALESCE(phone, "") LIKE ?
                   OR COALESCE(email, "") LIKE ?
                   OR COALESCE(tax_number, "") LIKE ?
                   OR COALESCE(commercial_registration, "") LIKE ?
                   OR COALESCE(national_address, "") LIKE ?
                   OR COALESCE(authorized_person, "") LIKE ?
             )
             ORDER BY name ASC
             LIMIT 4',
            array_fill(0, 9, $like)
        );

        foreach ($rows as $row) {
            $subtitle = trim(implode(' · ', array_filter([(string) ($row['authorized_person'] ?? ''), (string) ($row['phone'] ?? ''), (string) ($row['email'] ?? '')])));
            $results[] = global_search_result('Suppliers', (string) $row['name'], $subtitle !== '' ? $subtitle : 'Supplier', url('/suppliers/' . $row['id']), 'supplier', supplier_type_display($row['supplier_type'] ?? 'product', $row['supplier_type_other'] ?? null));
        }
    }

    if (file_library_can_access(Auth::user())) {
        $rows = Database::fetchAll(
            'SELECT id, display_name, original_filename, file_group, source_type
             FROM file_assets
             WHERE deleted_at IS NULL
               AND (display_name LIKE ? OR original_filename LIKE ? OR stored_filename LIKE ? OR source_type LIKE ? OR file_group LIKE ?)
             ORDER BY created_at DESC, id DESC
             LIMIT 4',
            array_fill(0, 5, $like)
        );

        foreach ($rows as $row) {
            $results[] = global_search_result('Files', (string) $row['display_name'], (string) $row['original_filename'], url('/files?search=' . rawurlencode((string) $row['original_filename'])), 'files', file_asset_group_label((string) $row['file_group']));
        }
    }

    if (!Auth::isStaff() && Auth::hasPermission('stocktakes.view')) {
        $rows = Database::fetchAll(
            'SELECT stocktake.id, stocktake.stocktake_number, stocktake.status, storage.name AS storage_name
             FROM stocktakes stocktake
             INNER JOIN storages storage ON storage.id = stocktake.storage_id
             WHERE stocktake.stocktake_number LIKE ?
                OR stocktake.status LIKE ?
                OR storage.name LIKE ?
                OR EXISTS (
                    SELECT 1
                    FROM stocktake_lines line
                    WHERE line.stocktake_id = stocktake.id
                      AND (line.item_name LIKE ? OR line.item_sku LIKE ?)
                )
             ORDER BY stocktake.created_at DESC, stocktake.id DESC
             LIMIT 4',
            array_fill(0, 5, $like)
        );

        foreach ($rows as $row) {
            $results[] = global_search_result('Stocktakes', (string) $row['stocktake_number'], ucfirst(str_replace('_', ' ', (string) $row['status'])) . ' · ' . (string) $row['storage_name'], url('/stocktakes/' . $row['id']), 'stocktakes', 'Count');
        }
    }

    if (!Auth::isStaff() && Auth::hasPermission('reorder.view')) {
		$rows = Database::fetchAll(
            'SELECT item.name AS item_name, item.sku, storage.name AS storage_name, balance.quantity, item.reorder_level
             FROM item_storage_balances balance
             INNER JOIN items item ON item.id = balance.item_id
             INNER JOIN storages storage ON storage.id = balance.storage_id
             WHERE item.is_active = 1
               AND storage.is_active = 1
               AND storage.is_system = 0
               AND item.reorder_level > 0
               AND balance.quantity <= item.reorder_level
               AND (item.name LIKE ? OR item.sku LIKE ? OR storage.name LIKE ?)
             ORDER BY storage.name ASC, item.name ASC
             LIMIT 4',
            array_fill(0, 3, $like)
        );

        foreach ($rows as $row) {
            $results[] = global_search_result('Reorder', (string) $row['item_name'], (string) $row['storage_name'] . ' · ' . format_quantity($row['quantity']) . ' left', url('/reorder?search=' . rawurlencode((string) $row['sku'])), 'reorder', 'Low stock');
        }
    }

    if (!Auth::isStaff() && Auth::hasPermission('users.view')) {
		$rows = Database::fetchAll(
            'SELECT id, name, email, role, position, is_active
             FROM users
             WHERE name LIKE ? OR email LIKE ? OR role LIKE ? OR COALESCE(position, "") LIKE ?
             ORDER BY is_active DESC, name ASC
             LIMIT 4',
            array_fill(0, 4, $like)
        );

        foreach ($rows as $row) {
            $results[] = global_search_result('Admins', (string) $row['name'], (string) $row['email'] . ' · ' . user_position_label($row['position'] ?? '', (string) $row['role']), url('/users'), 'users', user_role_label((string) $row['role']));
        }
    }

    if (!Auth::isStaff() && Auth::hasPermission('audit.view')) {
		$rows = Database::fetchAll(
            'SELECT activity.id, activity.action, activity.summary, activity.entity_type, activity.created_at, user.name AS user_name
             FROM activity_logs activity
             LEFT JOIN users user ON user.id = activity.user_id
             WHERE activity.summary LIKE ? OR activity.action LIKE ? OR COALESCE(activity.entity_type, "") LIKE ? OR COALESCE(user.name, "") LIKE ?
             ORDER BY activity.created_at DESC, activity.id DESC
             LIMIT 4',
            array_fill(0, 4, $like)
        );

        foreach ($rows as $row) {
            $results[] = global_search_result('Audit', (string) $row['summary'], (string) $row['action'] . ($row['user_name'] ? ' · ' . $row['user_name'] : ''), url('/audit-log?search=' . rawurlencode((string) $row['action'])), 'audit', 'Activity');
        }
    }

    if (!Auth::isStaff() && Auth::hasPermission('email_logs.view')) {
        $rows = Database::fetchAll(
            'SELECT log.id, log.email_type, log.recipient_email, log.subject, log.status, log.error_message, log.created_at
             FROM email_delivery_logs log
             WHERE log.email_type LIKE ?
                OR log.recipient_email LIKE ?
                OR log.subject LIKE ?
                OR log.status LIKE ?
                OR COALESCE(log.error_message, "") LIKE ?
             ORDER BY log.created_at DESC, log.id DESC
             LIMIT 4',
            array_fill(0, 5, $like)
        );

        foreach ($rows as $row) {
            $results[] = global_search_result(
                'Email Logs',
                (string) $row['subject'],
                email_log_status_label((string) $row['status']) . ' · ' . (string) $row['recipient_email'],
                url('/email-logs?search=' . rawurlencode((string) ($row['recipient_email'] ?: $row['email_type']))),
                'notification',
                (string) $row['email_type']
            );
        }
    }

    return array_slice(array_merge($results, global_search_settings_results($query), global_search_documentation_results($query)), 0, 32);
}

function handle_global_search(): void
{
    app_ready_or_redirect();
    Auth::requireLogin();

    $query = global_search_normalize_query((string) query('q', ''));
    $directTarget = workflow_reference_open_target($query);

    if (mb_strlen($query) < 2) {
        json_response([
            'ok' => true,
            'query' => $query,
            'results' => [],
            'fallback_url' => '',
            'message' => 'Type at least 2 characters.',
        ]);
    }

    json_response([
        'ok' => true,
        'query' => $query,
        'results' => global_search_results($query),
        'fallback_url' => global_search_fallback_url($query),
        'direct_url' => $directTarget['url'] ?? '',
        'direct_reference' => $directTarget['reference'] ?? '',
    ]);
}

function handle_workflow_reference_open(array $params): void
{
    app_ready_or_redirect();
    Auth::requireLogin();

    $reference = workflow_reference_normalize((string) ($params['reference'] ?? ''));

    if ($reference === '') {
        flash('danger', 'Workflow reference is missing.');
        redirect('/dashboard');
    }

    $target = workflow_reference_open_target($reference);

    if ($target !== null) {
        redirect((string) $target['path']);
    }

    flash('danger', 'No workflow matched reference ' . $reference . '.');
    redirect('/dashboard');
}
