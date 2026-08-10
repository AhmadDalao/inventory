<?php
declare(strict_types=1);

function mobile_api_item_payload(array $item, array $allowedStorageIds): array
{
    $balances = [];
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
        'package_presets' => Database::fetchAll(
            'SELECT id, label, package_type, pieces_per_unit, is_default FROM item_package_presets WHERE item_id = :item_id AND is_active = 1 ORDER BY is_default DESC, label ASC',
            ['item_id' => $item['id']]
        ),
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
        mobile_api_success([
            'user' => ['id' => (int) $session['user_id'], 'name' => $session['name'], 'email' => $session['email'], 'role' => $session['role'], 'position' => $session['position']],
            'permissions' => mobile_api_permissions((int) $session['user_id']),
            'storage_ids' => mobile_api_storage_ids($session),
            'device_session_id' => (int) $session['id'],
        ]);
    });
}

function handle_mobile_api_bootstrap(): void
{
    mobile_api_run(function (): void {
        $session = mobile_api_session();
        $ids = mobile_api_storage_ids($session);
        $access = mobile_api_require_employee_access($session);
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
        $capabilities = [];
        foreach (['usage', 'restock', 'transfer', 'handover', 'custody'] as $capability) {
            if ((int) ($access['can_' . $capability] ?? 0) === 1) {
                $capabilities[] = $capability;
            }
        }
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
        mobile_api_success([
            'user' => ['id' => (int) $session['user_id'], 'name' => $session['name'], 'role' => $session['role'], 'position' => $session['position']],
            'permissions' => mobile_api_permissions((int) $session['user_id']),
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
            ],
            'server_time' => date(DATE_ATOM),
        ], ['sync_cursor' => gmdate('Y-m-d H:i:s')]);
    });
}

function handle_mobile_api_sync(): void
{
    mobile_api_run(function (): void {
        $session = mobile_api_session();
        $ids = mobile_api_storage_ids($session);
        $since = trim((string) query('since', '1970-01-01 00:00:00'));
        $items = [];
        if ($ids !== []) {
            $params = array_merge([$since], $ids);
            $rows = Database::fetchAll(
                'SELECT DISTINCT item.* FROM items item INNER JOIN item_storage_balances balance ON balance.item_id = item.id
                 WHERE item.updated_at > ? AND item.is_active = 1 AND balance.storage_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY item.updated_at ASC LIMIT 1000',
                $params
            );
            foreach ($rows as $item) {
                $items[] = mobile_api_item_payload($item, $ids);
            }
        }
        $deletedIds = [];
        if ($ids !== []) {
            $deletedIds = array_map('intval', array_column(Database::fetchAll(
                'SELECT DISTINCT item.id
                 FROM items item
                 INNER JOIN item_storage_balances balance ON balance.item_id = item.id
                 WHERE item.is_active = 0 AND item.updated_at > ?
                   AND balance.storage_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
                 ORDER BY item.updated_at ASC
                 LIMIT 1000',
                array_merge([$since], $ids)
            ), 'id'));
        }
        $tasks = Auth::userHasPermission((int) $session['user_id'], 'handovers.view')
            ? mobile_api_handover_list_rows($session, true)
            : [];
        mobile_api_success(
            ['items' => $items, 'deleted_item_ids' => $deletedIds, 'tasks' => $tasks],
            ['sync_cursor' => gmdate('Y-m-d H:i:s'), 'has_more' => count($items) === 1000 || count($deletedIds) === 1000]
        );
    });
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
