<?php
declare(strict_types=1);

function mobile_api_item_payload(array $item, array $allowedStorageIds): array
{
    $balances = [];
    $packagePresets = Database::fetchAll(
        'SELECT id, label, pieces_per_unit, is_default
         FROM item_package_presets
         WHERE item_id = :item_id
         ORDER BY is_default DESC, label ASC',
        ['item_id' => $item['id']]
    );
    if ($allowedStorageIds !== []) {
        $placeholders = implode(',', array_fill(0, count($allowedStorageIds), '?'));
        $params = array_merge([(int) $item['id']], $allowedStorageIds);
        $balances = Database::fetchAll(
            'SELECT balance.storage_id, storage.name AS storage_name, storage.storage_type, balance.quantity
             FROM item_storage_balances balance INNER JOIN storages storage ON storage.id = balance.storage_id
             WHERE balance.item_id = ? AND balance.storage_id IN (' . $placeholders . ') ORDER BY storage.name ASC',
            $params
        );
    }
    return [
        'id' => (int) $item['id'], 'name' => $item['name'], 'sku' => $item['sku'],
        'barcode' => $item['barcode'] ?: null, 'unit' => $item['unit'], 'category' => $item['category'] ?: null,
        'image_url' => mobile_api_absolute_url(item_image_url($item['image_path'] ?? null)),
        'balances' => array_map(static fn (array $row): array => [
            'storage_id' => (int) $row['storage_id'], 'storage_name' => $row['storage_name'],
            'storage_type' => $row['storage_type'], 'quantity' => (float) $row['quantity'],
        ], $balances),
        'package_presets' => array_map(static fn (array $preset): array => [
            'id' => (int) $preset['id'],
            'label' => (string) $preset['label'],
            'pieces_per_unit' => (float) $preset['pieces_per_unit'],
            'is_default' => (int) $preset['is_default'],
        ], $packagePresets),
    ];
}

function mobile_api_find_item(int $id, ?array $allowedStorageIds = null): array
{
    if ($allowedStorageIds !== null) {
        $allowedStorageIds = array_values(array_unique(array_map('intval', $allowedStorageIds)));
        if ($allowedStorageIds === []) {
            throw new MobileApiException('item_not_found', 'Item not found.', 404);
        }
        $item = Database::fetch(
            'SELECT DISTINCT item.*
             FROM items item
             INNER JOIN item_storage_balances balance ON balance.item_id = item.id
             WHERE item.id = ? AND item.is_active = 1
               AND balance.storage_id IN (' . implode(',', array_fill(0, count($allowedStorageIds), '?')) . ')
             LIMIT 1',
            array_merge([$id], $allowedStorageIds)
        );
    } else {
        $item = Database::fetch('SELECT * FROM items WHERE id = :id AND is_active = 1 LIMIT 1', ['id' => $id]);
    }
    if (!$item) {
        throw new MobileApiException('item_not_found', 'Item not found.', 404);
    }
    return $item;
}

function handle_mobile_api_me(): void
{
    mobile_api_run(function (): void {
        $session = mobile_api_session();
        $permissions = mobile_api_permissions((int) $session['user_id']);
        mobile_api_success([
            'user' => ['id' => (int) $session['user_id'], 'name' => $session['name'], 'email' => $session['email'], 'role' => $session['role'], 'position' => $session['position']],
            'permissions' => $permissions,
            'storage_ids' => in_array('items.view', $permissions, true) ? mobile_api_storage_ids($session) : [],
            'device_session_id' => (int) $session['id'],
        ]);
    });
}

function handle_mobile_api_bootstrap(): void
{
    mobile_api_run(function (): void {
        $session = mobile_api_session();
        $access = mobile_api_require_employee_access($session);
        $permissions = mobile_api_permissions((int) $session['user_id']);
        $ids = in_array('items.view', $permissions, true) ? mobile_api_storage_ids($session) : [];
        $storages = $ids === [] ? [] : Database::fetchAll(
            'SELECT storage.id, storage.name, storage.storage_type, assignment.is_default
             FROM storages storage LEFT JOIN user_storage_assignments assignment ON assignment.storage_id = storage.id AND assignment.user_id = ?
             WHERE storage.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') AND storage.is_active = 1 ORDER BY assignment.is_default DESC, storage.name ASC',
            array_merge([(int) $session['user_id']], $ids)
        );
        $items = [];
        if ($ids !== [] && Auth::userHasPermission((int) $session['user_id'], 'items.view')) {
            $rows = Database::fetchAll(
                'SELECT DISTINCT item.*
                 FROM items item
                 INNER JOIN item_storage_balances balance ON balance.item_id = item.id
                 WHERE item.is_active = 1
                   AND balance.storage_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
                 ORDER BY item.name ASC
                 LIMIT 1000',
                $ids
            );
            $items = array_map(static fn (array $item): array => mobile_api_item_payload($item, $ids), $rows);
        }
        $capabilities = mobile_api_effective_capabilities($access, $permissions, $ids);
        $recipients = [];
        if (array_intersect($capabilities, ['handover', 'custody']) !== []) {
            $recipients = Database::fetchAll(
                'SELECT id, name, role, position
                 FROM users
                 WHERE is_active = 1 AND role = "staff" AND id <> :user_id
                 ORDER BY name ASC
                 LIMIT 500',
                ['user_id' => $session['user_id']]
            );
        }
        $cursor = inventory_latest_event_cursor();
        mobile_api_success([
            'user' => ['id' => (int) $session['user_id'], 'name' => $session['name'], 'role' => $session['role'], 'position' => $session['position']],
            'permissions' => $permissions,
            'storages' => $storages,
            'items' => $items,
            'tasks' => Auth::userHasPermission((int) $session['user_id'], 'handovers.view') ? mobile_api_handover_list_rows($session, true) : [],
            'capabilities' => $capabilities,
            'recipients' => array_map(static fn (array $recipient): array => [
                'id' => (int) $recipient['id'],
                'name' => (string) $recipient['name'],
                'role' => (string) $recipient['role'],
                'position' => (string) ($recipient['position'] ?? ''),
            ], $recipients),
            'settings' => [
                'manual_restock_enabled' => site_setting('mobile.manual_restock_enabled', '0') === '1',
                'offline_drafts_enabled' => site_setting('mobile.offline_drafts_enabled', '1') === '1',
                'require_usage_proof' => site_setting('mobile.require_usage_proof', '0') === '1',
                'min_supported_version' => site_setting('mobile.min_supported_version', '1.0.0'),
                'usage_reasons' => mobile_usage_reason_catalog(true),
            ],
            'server_time' => date(DATE_ATOM),
        ], [
            'sync_cursor' => $cursor,
            'access_fingerprint' => mobile_api_access_fingerprint($session, $permissions, $ids, $capabilities),
        ]);
    });
}

function mobile_api_access_fingerprint(array $session, array $permissions, array $storageIds, array $capabilities): string
{
    sort($permissions);
    sort($storageIds);
    sort($capabilities);
    return hash('sha256', json_encode([
        'user_id' => (int) $session['user_id'],
        'permissions' => array_values($permissions),
        'storage_ids' => array_values($storageIds),
        'capabilities' => array_values($capabilities),
        'mobile_enabled' => site_setting('mobile.enabled', '0'),
        'minimum_version' => site_setting('mobile.min_supported_version', '1.0.0'),
    ], JSON_UNESCAPED_SLASHES));
}

function handle_mobile_api_sync(): void
{
    mobile_api_run(function (): void {
        $session = mobile_api_session();
        mobile_api_enforce_rate_limit(
            'sync',
            (string) $session['user_id'] . ':' . (string) $session['id'] . ':' . auth_request_ip(),
            30,
            60
        );
        $access = mobile_api_require_employee_access($session);
        $permissions = mobile_api_permissions((int) $session['user_id']);
        $ids = in_array('items.view', $permissions, true) ? mobile_api_storage_ids($session) : [];
        $capabilities = mobile_api_effective_capabilities($access, $permissions, $ids);
        $after = max(0, (int) query('after', 0));
        $limit = min(500, max(25, (int) query('limit', 250)));
        $latestCursor = inventory_latest_event_cursor();
        $oldestCursor = inventory_oldest_event_cursor();

        if ($after > 0 && $oldestCursor > 0 && $after < ($oldestCursor - 1)) {
            mobile_api_success([
                'full_resync_required' => true,
                'reason' => 'cursor_expired',
                'items' => [],
                'deleted_item_ids' => [],
                'tasks' => [],
                'tasks_changed' => true,
                'permissions' => $permissions,
                'storage_ids' => $ids,
                'capabilities' => $capabilities,
            ], [
                'sync_cursor' => $latestCursor,
                'next_cursor' => $latestCursor,
                'has_more' => false,
                'access_fingerprint' => mobile_api_access_fingerprint($session, $permissions, $ids, $capabilities),
            ]);
        }

        $eventRows = Database::fetchAll(
            'SELECT id, event_type, item_id, storage_id, entity_type, entity_id, movement_id, payload_json, created_at
             FROM inventory_change_events
             WHERE id > :after
             ORDER BY id ASC
             LIMIT ' . ($limit + 1),
            ['after' => $after]
        );
        $hasMore = count($eventRows) > $limit;
        if ($hasMore) {
            $eventRows = array_slice($eventRows, 0, $limit);
        }
        $nextCursor = $eventRows === [] ? $latestCursor : (int) end($eventRows)['id'];
        reset($eventRows);

        $allowedStorageLookup = array_fill_keys(array_map('intval', $ids), true);
        $changedItemIds = [];
        $authorizedEvents = [];
        $workflowChanged = false;
        foreach ($eventRows as $event) {
            $storageId = (int) ($event['storage_id'] ?? 0);
            $itemId = (int) ($event['item_id'] ?? 0);
            $authorized = $storageId > 0 && isset($allowedStorageLookup[$storageId]);
            // A storage-scoped event must never leak across storage assignments.
            // Item-level fallback is only valid for events without a storage scope.
            if (!$authorized && $storageId <= 0 && $itemId > 0 && $ids !== []) {
                $authorized = (bool) Database::scalar(
                    'SELECT 1 FROM item_storage_balances WHERE item_id = ? AND storage_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') LIMIT 1',
                    array_merge([$itemId], $ids)
                );
            }
            if (!$authorized && (string) ($event['entity_type'] ?? '') === 'handover' && in_array('handovers.view', $permissions, true)) {
                $authorized = mobile_api_handover_visible_to_session((int) $event['entity_id'], $session, $ids);
            }
            if (!$authorized) {
                continue;
            }
            if ($itemId > 0) {
                $changedItemIds[$itemId] = true;
            }
            if ((string) ($event['entity_type'] ?? '') === 'handover') {
                $workflowChanged = true;
            }
            $authorizedEvents[] = [
                'id' => (int) $event['id'],
                'type' => (string) $event['event_type'],
                'item_id' => $itemId > 0 ? $itemId : null,
                'storage_id' => $storageId > 0 ? $storageId : null,
                'entity_type' => $event['entity_type'] ?: null,
                'entity_id' => $event['entity_id'] !== null ? (int) $event['entity_id'] : null,
                'created_at' => (string) $event['created_at'],
            ];
        }

        $items = [];
        $deletedIds = [];
        foreach (array_keys($changedItemIds) as $itemId) {
            $item = Database::fetch('SELECT * FROM items WHERE id = :id LIMIT 1', ['id' => $itemId]);
            if (!$item || (int) ($item['is_active'] ?? 0) !== 1) {
                $deletedIds[] = (int) $itemId;
                continue;
            }
            $items[] = mobile_api_item_payload($item, $ids);
        }
        $tasks = Auth::userHasPermission((int) $session['user_id'], 'handovers.view')
            ? (($workflowChanged || $after === 0) ? mobile_api_handover_list_rows($session, true) : [])
            : [];
        mobile_api_success(
            [
                'full_resync_required' => false,
                'items' => $items,
                'deleted_item_ids' => array_values(array_unique($deletedIds)),
                'tasks' => $tasks,
                'tasks_changed' => $workflowChanged || $after === 0,
                'events' => $authorizedEvents,
                'permissions' => $permissions,
                'storage_ids' => $ids,
                'capabilities' => $capabilities,
            ],
            [
                'sync_cursor' => $nextCursor,
                'next_cursor' => $nextCursor,
                'latest_cursor' => $latestCursor,
                'has_more' => $hasMore,
                'access_fingerprint' => mobile_api_access_fingerprint($session, $permissions, $ids, $capabilities),
            ]
        );
    });
}

function mobile_api_handover_visible_to_session(int $handoverId, array $session, array $storageIds): bool
{
    if ($handoverId <= 0) {
        return false;
    }
    if ((string) ($session['role'] ?? '') === 'owner') {
        return true;
    }
    $params = [$handoverId, (int) $session['user_id'], (int) $session['user_id'], (int) $session['user_id']];
    $storageSql = '';
    if ($storageIds !== []) {
        $placeholders = implode(',', array_fill(0, count($storageIds), '?'));
        $storageSql = ' OR handover.source_storage_id IN (' . $placeholders . ') OR handover.destination_storage_id IN (' . $placeholders . ')';
        $params = array_merge($params, $storageIds, $storageIds);
    }
    return (bool) Database::scalar(
        'SELECT 1 FROM handovers handover
         WHERE handover.id = ?
           AND (handover.recipient_user_id = ? OR handover.created_by = ? OR handover.approver_user_id = ?' . $storageSql . ')
         LIMIT 1',
        $params
    );
}

function handle_mobile_api_operations_mine(): void
{
    mobile_api_run(function (): void {
        $session = mobile_api_session();
        $rows = Database::fetchAll(
            'SELECT id, client_operation_id, operation_type, status, entity_type, entity_id,
                    response_json, error_code, error_message, created_at, completed_at
             FROM mobile_operations
             WHERE user_id = :user_id
             ORDER BY created_at DESC, id DESC
             LIMIT 100',
            ['user_id' => (int) $session['user_id']]
        );

        $items = array_map(static function (array $row): array {
            $response = json_decode((string) ($row['response_json'] ?? ''), true);
            $response = is_array($response) ? $response : [];
            return [
                'id' => (int) $row['id'],
                'client_operation_id' => (string) $row['client_operation_id'],
                'operation_type' => (string) $row['operation_type'],
                'status' => (string) $row['status'],
                'reference' => $response['reference'] ?? $response['handover_number'] ?? null,
                'message' => $row['error_message'] ?: ($response['message'] ?? null),
                'error_code' => $row['error_code'] ?: null,
                'entity_type' => $row['entity_type'] ?: null,
                'entity_id' => isset($row['entity_id']) ? (int) $row['entity_id'] : null,
                'created_at' => (string) $row['created_at'],
                'completed_at' => $row['completed_at'] ?: null,
            ];
        }, $rows);

        mobile_api_success(['items' => $items]);
    });
}

function handle_mobile_api_storages(): void
{
    mobile_api_run(function (): void {
        $session = mobile_api_session();
        mobile_api_require_permission($session, 'items.view');
        $ids = mobile_api_storage_ids($session);
        if ($ids === []) {
            mobile_api_success([]);
        }
        $rows = Database::fetchAll(
            'SELECT storage.id, storage.name, storage.storage_type, assignment.is_default,
                    COUNT(balance.item_id) AS item_count, COALESCE(SUM(balance.quantity), 0) AS total_units
             FROM storages storage
             LEFT JOIN user_storage_assignments assignment ON assignment.storage_id = storage.id AND assignment.user_id = ?
             LEFT JOIN item_storage_balances balance ON balance.storage_id = storage.id
             WHERE storage.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') AND storage.is_active = 1
             GROUP BY storage.id, storage.name, storage.storage_type, assignment.is_default ORDER BY assignment.is_default DESC, storage.name ASC',
            array_merge([(int) $session['user_id']], $ids)
        );
        mobile_api_success($rows);
    });
}

function handle_mobile_api_storage_items(array $params): void
{
    mobile_api_run(function () use ($params): void {
        $session = mobile_api_session();
        mobile_api_require_permission($session, 'items.view');
        $storageId = (int) $params['id'];
        mobile_api_require_storage($session, $storageId);
        $search = trim((string) query('search', ''));
        $where = $search !== '' ? ' AND (item.name LIKE :search OR item.sku LIKE :search OR item.barcode LIKE :search)' : '';
        $rows = Database::fetchAll(
            'SELECT item.*, balance.quantity AS storage_quantity FROM item_storage_balances balance INNER JOIN items item ON item.id = balance.item_id
             WHERE balance.storage_id = :storage_id AND item.is_active = 1' . $where . ' ORDER BY item.name ASC LIMIT 500',
            ['storage_id' => $storageId] + ($search !== '' ? ['search' => '%' . $search . '%'] : [])
        );
        mobile_api_success(array_map(static function (array $item) use ($storageId): array {
            $payload = mobile_api_item_payload($item, [$storageId]);
            $payload['storage_quantity'] = (float) $item['storage_quantity'];
            return $payload;
        }, $rows));
    });
}

function handle_mobile_api_item_lookup(): void
{
    mobile_api_run(function (): void {
        $session = mobile_api_session();
        mobile_api_require_permission($session, 'items.view');
        $term = trim((string) query('q', query('code', '')));
        if ($term === '') {
            throw new MobileApiException('validation_failed', 'Scan or enter an item code.', 422);
        }
        $ids = mobile_api_storage_ids($session);
        $requestedStorageId = (int) query('storage_id', 0);
        if ($requestedStorageId > 0) {
            mobile_api_require_storage($session, $requestedStorageId);
            $ids = [$requestedStorageId];
        }
        if ($ids === []) {
            mobile_api_success([]);
        }
        $rows = Database::fetchAll(
            'SELECT DISTINCT item.*
             FROM items item
             INNER JOIN item_storage_balances balance ON balance.item_id = item.id
             WHERE item.is_active = 1
               AND balance.storage_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
               AND (item.barcode = ? OR item.sku = ? OR item.name LIKE ? OR item.sku LIKE ? OR item.barcode LIKE ?)
             ORDER BY CASE WHEN item.barcode = ? OR item.sku = ? THEN 0 ELSE 1 END, item.name ASC
             LIMIT 25',
            array_merge($ids, [$term, $term, '%' . $term . '%', '%' . $term . '%', '%' . $term . '%', $term, $term])
        );
        mobile_api_success(array_map(static fn (array $item): array => mobile_api_item_payload($item, $ids), $rows));
    });
}

function handle_mobile_api_item_show(array $params): void
{
    mobile_api_run(function () use ($params): void {
        $session = mobile_api_session();
        mobile_api_require_permission($session, 'items.view');
        $ids = mobile_api_storage_ids($session);
        mobile_api_success(mobile_api_item_payload(mobile_api_find_item((int) $params['id'], $ids), $ids));
    });
}
