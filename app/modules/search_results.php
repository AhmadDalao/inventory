<?php
declare(strict_types=1);

// Domain module: global search entity aggregation.

function global_search_results(string $query): array
{
    $like = global_search_like($query);
    $results = global_search_accessible_pages($query);
    $directTarget = workflow_reference_open_target($query);

    if ($directTarget !== null) {
        array_unshift($results, workflow_reference_global_result($directTarget));
    }

    // Staff use assigned items through requests, handovers, and mobile flows;
    // the catalog/detail surface is intentionally reserved for non-staff users.
    if (!Auth::isStaff() && Auth::hasPermission('items.view')) {
        $itemFilters = [
            'search' => $query,
            'status' => 'active',
            'storage_id' => null,
        ];
        [$itemWhere, $itemParams] = build_item_where($itemFilters, 'i');
        $itemQuantitySelect = item_filtered_storage_quantity_select($itemFilters, $itemParams, 'global_item_storage_id');
        $rows = Database::fetchAll(
            'SELECT i.id, i.name, i.sku, i.barcode, i.category, i.unit, i.current_quantity,
                    ' . $itemQuantitySelect . '
             FROM items i
             ' . $itemWhere . '
             ORDER BY i.name ASC
             LIMIT 5',
            $itemParams
        );

        foreach ($rows as $row) {
            $scanCode = normalize_item_barcode($row['barcode'] ?? '') !== '' ? (string) $row['barcode'] : (string) $row['sku'];
            $results[] = global_search_result(
                'Items',
                (string) $row['name'],
                trim((string) $row['sku'] . ' · Scan: ' . $scanCode . ' · ' . format_quantity(item_display_quantity($row)) . ' ' . $row['unit']),
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

    if (Auth::hasPermission('storages.view')) {
        $searchUserId = (int) (Auth::user()['id'] ?? 0);
        $visibleStorageIds = user_visible_storage_ids($searchUserId);
        $storageVisibility = user_can_view_all_storages($searchUserId)
            ? '1 = 1'
            : ($visibleStorageIds === [] ? '1 = 0' : 'id IN (' . implode(', ', array_map('intval', $visibleStorageIds)) . ')');
        $rows = Database::fetchAll(
            'SELECT id, name, storage_type, notes
             FROM storages
             WHERE is_active = 1
               AND is_system = 0
               AND ' . $storageVisibility . '
               AND (name LIKE ? OR storage_type LIKE ? OR COALESCE(notes, "") LIKE ?)
             ORDER BY name ASC
             LIMIT 5',
            array_fill(0, 3, $like)
        );

        foreach ($rows as $row) {
            $results[] = global_search_result('Storages', (string) $row['name'], storage_type_label((string) $row['storage_type']), url('/storages/' . $row['id']), 'storages', 'Location');
        }
    }

    if (Auth::hasPermission('movements.view')) {
        [$movementWhere, $movementParams] = build_movement_where([
            'item_id' => null,
            'storage_id' => null,
            'movement_type' => '',
            'date_from' => '',
            'date_to' => '',
        ], 'movement', 'item');
        $movementSearch = '(
            item.name LIKE :global_movement_item_name
            OR item.sku LIKE :global_movement_item_sku
            OR movement.movement_type LIKE :global_movement_type
            OR COALESCE(movement.reference_code, "") LIKE :global_movement_reference
            OR COALESCE(movement.notes, "") LIKE :global_movement_notes
            OR COALESCE(source_storage.name, "") LIKE :global_movement_source
            OR COALESCE(destination_storage.name, "") LIKE :global_movement_destination
        )';
        $movementWhere = $movementWhere === ''
            ? 'WHERE ' . $movementSearch
            : $movementWhere . ' AND ' . $movementSearch;
        foreach (['item_name', 'item_sku', 'type', 'reference', 'notes', 'source', 'destination'] as $movementSearchKey) {
            $movementParams['global_movement_' . $movementSearchKey] = $like;
        }
        $rows = Database::fetchAll(
            'SELECT movement.id, movement.movement_type, movement.reference_code, movement.used_at, item.name AS item_name, item.sku,
                    source_storage.name AS source_name, destination_storage.name AS destination_name
             FROM inventory_movements movement
             INNER JOIN items item ON item.id = movement.item_id
             LEFT JOIN storages source_storage ON source_storage.id = movement.source_storage_id
             LEFT JOIN storages destination_storage ON destination_storage.id = movement.destination_storage_id
             ' . $movementWhere . '
             ORDER BY movement.used_at DESC, movement.id DESC
             LIMIT 4',
            $movementParams
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

    if (Auth::hasPermission('purchases.view')) {
        [$purchaseWhere, $purchaseParams] = build_purchase_where([
            'search' => $query,
            'status' => 'all',
            'storage_id' => null,
            'supplier_id' => null,
            'date_from' => '',
            'date_to' => '',
        ], 'p');
        $rows = Database::fetchAll(
            'SELECT p.id, p.purchase_number, p.status, supplier.name AS supplier_name, storage.name AS storage_name
             FROM purchases p
             INNER JOIN suppliers supplier ON supplier.id = p.supplier_id
             INNER JOIN storages storage ON storage.id = p.destination_storage_id
             ' . $purchaseWhere . '
             ORDER BY p.created_at DESC, p.id DESC
             LIMIT 5',
            $purchaseParams
        );

        foreach ($rows as $row) {
            $results[] = global_search_result('Purchases', (string) $row['purchase_number'], purchase_status_label((string) $row['status']) . ' · ' . (string) $row['supplier_name'], url('/purchases/' . $row['id']), 'purchases', 'PO');
        }
    }

    if (Auth::hasPermission('suppliers.view')) {
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
        $rows = file_asset_rows([
            'search' => $query,
            'group' => 'all',
            'status' => 'active',
            'date_from' => '',
            'date_to' => '',
        ], 4);

        foreach ($rows as $row) {
            $results[] = global_search_result('Files', (string) $row['display_name'], (string) $row['original_filename'], url('/files?search=' . rawurlencode((string) $row['original_filename'])), 'files', file_asset_group_label((string) $row['file_group']));
        }
    }

    if (Auth::hasPermission('stocktakes.view')) {
        [$stocktakeWhere, $stocktakeParams] = build_stocktake_where([
            'search' => $query,
            'status' => 'all',
            'storage_id' => null,
            'date_from' => '',
            'date_to' => '',
        ], 'stocktake');
        $rows = Database::fetchAll(
            'SELECT stocktake.id, stocktake.stocktake_number, stocktake.status, storage.name AS storage_name
             FROM stocktakes stocktake
             INNER JOIN storages storage ON storage.id = stocktake.storage_id
             LEFT JOIN users creator ON creator.id = stocktake.created_by
             ' . $stocktakeWhere . '
             ORDER BY stocktake.created_at DESC, stocktake.id DESC
             LIMIT 4',
            $stocktakeParams
        );

        foreach ($rows as $row) {
            $results[] = global_search_result('Stocktakes', (string) $row['stocktake_number'], ucfirst(str_replace('_', ' ', (string) $row['status'])) . ' · ' . (string) $row['storage_name'], url('/stocktakes/' . $row['id']), 'stocktakes', 'Count');
        }
    }

    if (Auth::hasPermission('reorder.view')) {
        $reorderStorageScope = current_user_item_storage_scope();
        $reorderVisibility = $reorderStorageScope === null
            ? '1 = 1'
            : ($reorderStorageScope === [] ? '1 = 0' : 'balance.storage_id IN (' . item_storage_scope_sql($reorderStorageScope) . ')');
        $rows = Database::fetchAll(
            'SELECT item.name AS item_name, item.sku, storage.name AS storage_name, balance.quantity, item.reorder_level
             FROM item_storage_balances balance
             INNER JOIN items item ON item.id = balance.item_id
             INNER JOIN storages storage ON storage.id = balance.storage_id
             WHERE item.is_active = 1
               AND storage.is_active = 1
               AND storage.is_system = 0
               AND ' . $reorderVisibility . '
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

    if (Auth::hasPermission('users.view')) {
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

    if (Auth::hasPermission('audit.view')) {
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

    if (Auth::hasPermission('email_logs.view')) {
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
