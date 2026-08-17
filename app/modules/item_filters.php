<?php
declare(strict_types=1);

function item_filters(): array
{
    $status = (string) query('status', 'all');

    return [
        'search' => trim((string) query('search', '')),
        'status' => in_array($status, ['active', 'archived', 'all'], true) ? $status : 'all',
        'storage_id' => ctype_digit((string) query('storage_id', '')) ? (int) query('storage_id') : null,
    ];
}

/**
 * Null means unrestricted. An empty array means the user has no visible storage.
 */
function current_user_item_storage_scope(): ?array
{
    $userId = (int) (Auth::user()['id'] ?? 0);

    if ($userId <= 0) {
        return [];
    }

    if (user_can_view_all_storages($userId)) {
        return null;
    }

    return user_visible_storage_ids($userId);
}

function item_storage_scope_sql(?array $storageIds): string
{
    if ($storageIds === null) {
        return '';
    }

    $storageIds = array_values(array_unique(array_filter(
        array_map('intval', $storageIds),
        static fn (int $storageId): bool => $storageId > 0
    )));

    return $storageIds === [] ? '0' : implode(',', $storageIds);
}

function current_user_can_view_item(int $itemId): bool
{
    $storageIds = current_user_item_storage_scope();

    if ($storageIds === null) {
        return true;
    }

    if ($storageIds === []) {
        return false;
    }

    return (int) Database::scalar(
        'SELECT COUNT(*)
         FROM item_storage_balances
         WHERE item_id = :item_id
           AND storage_id IN (' . item_storage_scope_sql($storageIds) . ')',
        ['item_id' => $itemId]
    ) > 0;
}

function require_current_user_item_visibility(int $itemId): void
{
    if (!current_user_can_view_item($itemId)) {
        abort(404, 'Item not found.');
    }
}

function build_item_where(array $filters, string $alias = 'i'): array
{
    $conditions = [];
    $params = [];
    $storageScope = current_user_item_storage_scope();
    $storageScopeSql = item_storage_scope_sql($storageScope);

    if ($storageScope !== null) {
        $conditions[] = $storageScope === []
            ? '1 = 0'
            : "EXISTS (
                SELECT 1
                FROM item_storage_balances visible_balances
                WHERE visible_balances.item_id = {$alias}.id
                  AND visible_balances.storage_id IN ({$storageScopeSql})
            )";
    }

    if ($filters['status'] === 'active') {
        $conditions[] = "{$alias}.is_active = 1";
    } elseif ($filters['status'] === 'archived') {
        $conditions[] = "{$alias}.is_active = 0";
    }

    if ($filters['search'] !== '') {
        $conditions[] = "(
            {$alias}.name LIKE :search_name
            OR {$alias}.sku LIKE :search_sku
            OR COALESCE({$alias}.barcode, '') LIKE :search_barcode
            OR COALESCE({$alias}.category, '') LIKE :search_category
            OR EXISTS (
                SELECT 1
                FROM item_storage_balances item_balances
                INNER JOIN storages matched_storage ON matched_storage.id = item_balances.storage_id
                WHERE item_balances.item_id = {$alias}.id
                  " . ($storageScope !== null ? "AND item_balances.storage_id IN ({$storageScopeSql})" : '') . "
                  AND matched_storage.name LIKE :search_storage
            )
        )";
        $params['search_name'] = '%' . $filters['search'] . '%';
        $params['search_sku'] = '%' . $filters['search'] . '%';
        $params['search_barcode'] = '%' . $filters['search'] . '%';
        $params['search_category'] = '%' . $filters['search'] . '%';
        $params['search_storage'] = '%' . $filters['search'] . '%';
    }

    if ($filters['storage_id']) {
        $requestedStorageId = (int) $filters['storage_id'];

        if ($storageScope !== null && !in_array($requestedStorageId, $storageScope, true)) {
            $conditions[] = '1 = 0';
        } else {
            $conditions[] = "EXISTS (
                SELECT 1
                FROM item_storage_balances filtered_balances
                WHERE filtered_balances.item_id = {$alias}.id
                  AND filtered_balances.storage_id = :storage_id
            )";
            $params['storage_id'] = $requestedStorageId;
        }
    }

    return [
        $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '',
        $params,
    ];
}

function item_filtered_storage_quantity_select(array $filters, array &$params, string $paramName = 'item_filtered_storage_id'): string
{
    $storageScope = current_user_item_storage_scope();

    if (empty($filters['storage_id'])) {
        if ($storageScope === null) {
            return 'NULL AS filtered_storage_quantity';
        }

        if ($storageScope === []) {
            return '0 AS filtered_storage_quantity';
        }

        return "(
            SELECT COALESCE(SUM(visible_balance.quantity), 0)
            FROM item_storage_balances visible_balance
            WHERE visible_balance.item_id = i.id
              AND visible_balance.storage_id IN (" . item_storage_scope_sql($storageScope) . ")
        ) AS filtered_storage_quantity";
    }

    if ($storageScope !== null && !in_array((int) $filters['storage_id'], $storageScope, true)) {
        return 'NULL AS filtered_storage_quantity';
    }

    $params[$paramName] = (int) $filters['storage_id'];

    return "(
        SELECT filtered_balance.quantity
        FROM item_storage_balances filtered_balance
        WHERE filtered_balance.item_id = i.id
          AND filtered_balance.storage_id = :{$paramName}
        LIMIT 1
    ) AS filtered_storage_quantity";
}

function item_display_quantity(array $item): float
{
    if (array_key_exists('filtered_storage_quantity', $item) && $item['filtered_storage_quantity'] !== null) {
        return (float) $item['filtered_storage_quantity'];
    }

    return (float) ($item['current_quantity'] ?? 0);
}
