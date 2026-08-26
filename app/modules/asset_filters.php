<?php
declare(strict_types=1);

// Asset filter parsing and SQL where-clause construction.
function asset_filters(): array
{
    $status = trim((string) query('status', 'all'));
    $condition = trim((string) query('condition', 'all'));
    $active = trim((string) query('active', 'all'));
    $categoryId = ctype_digit((string) query('category_id', '')) ? (int) query('category_id') : null;
    $categoryParentId = ctype_digit((string) query('category_parent_id', '')) ? (int) query('category_parent_id') : null;

    $validStatuses = array_keys(asset_status_options());
    $validConditions = array_keys(asset_condition_options());

    return [
        'search' => mb_substr(trim((string) query('search', '')), 0, 120),
        'status' => in_array($status, array_merge(['all'], $validStatuses), true) ? $status : 'all',
        'condition' => in_array($condition, array_merge(['all'], $validConditions), true) ? $condition : 'all',
        'storage_id' => ctype_digit((string) query('storage_id', '')) ? (int) query('storage_id') : null,
        'assigned_user_id' => ctype_digit((string) query('assigned_user_id', '')) ? (int) query('assigned_user_id') : null,
        'category_parent_id' => $categoryParentId !== null && $categoryParentId > 0 ? $categoryParentId : null,
        'category_id' => $categoryId !== null && $categoryId > 0 ? $categoryId : null,
        'active' => in_array($active, ['all', 'active', 'archived'], true) ? $active : 'all',
    ];
}

function asset_visibility_condition(string $alias = 'a'): array
{
    $userId = (int) (Auth::user()['id'] ?? 0);

    if ($userId <= 0) {
        return ['0 = 1', []];
    }

    // Staff custody is personal: storage assignment must not expose other assets.
    if (Auth::isStaff()) {
        return ["{$alias}.assigned_user_id = :asset_visible_user_id", ['asset_visible_user_id' => $userId]];
    }

    if (user_can_view_all_storages($userId)) {
        return ['1 = 1', []];
    }

    $conditions = ["{$alias}.assigned_user_id = :asset_visible_user_id"];
    $params = ['asset_visible_user_id' => $userId];
    $storageIds = user_visible_storage_ids($userId);

    if ($storageIds !== []) {
        $conditions[] = "{$alias}.storage_id IN (" . implode(',', array_map('intval', $storageIds)) . ')';
    }

    return ['(' . implode(' OR ', $conditions) . ')', $params];
}

function build_asset_where(array $filters, string $alias = 'a'): array
{
    [$visibilitySql, $visibilityParams] = asset_visibility_condition($alias);
    $conditions = [$visibilitySql];
    $params = $visibilityParams;

    $search = trim((string) ($filters['search'] ?? ''));

    if ($search !== '') {
        $searchLike = '%' . $search . '%';
        $conditions[] = "(
            {$alias}.asset_number LIKE :asset_search_number
            OR {$alias}.name LIKE :asset_search_name
            OR COALESCE({$alias}.category, '') LIKE :asset_search_category
            OR EXISTS (SELECT 1 FROM asset_categories asset_search_category_record WHERE asset_search_category_record.id = {$alias}.category_id AND (asset_search_category_record.name LIKE :asset_search_category_record OR COALESCE(asset_search_category_record.code, '') LIKE :asset_search_category_code))
            OR EXISTS (SELECT 1 FROM asset_categories asset_search_parent_category WHERE asset_search_parent_category.id = (SELECT parent_id FROM asset_categories asset_search_direct_category WHERE asset_search_direct_category.id = {$alias}.category_id LIMIT 1) AND (asset_search_parent_category.name LIKE :asset_search_parent_category OR COALESCE(asset_search_parent_category.code, '') LIKE :asset_search_parent_category_code))
            OR COALESCE({$alias}.model, '') LIKE :asset_search_model
            OR COALESCE({$alias}.serial_number, '') LIKE :asset_search_serial
            OR COALESCE({$alias}.barcode, '') LIKE :asset_search_barcode
            OR EXISTS (SELECT 1 FROM storages asset_search_storage WHERE asset_search_storage.id = {$alias}.storage_id AND asset_search_storage.name LIKE :asset_search_storage)
            OR EXISTS (SELECT 1 FROM users asset_search_user WHERE asset_search_user.id = {$alias}.assigned_user_id AND asset_search_user.name LIKE :asset_search_user)
            OR EXISTS (SELECT 1 FROM suppliers asset_search_supplier WHERE asset_search_supplier.id = {$alias}.supplier_id AND asset_search_supplier.name LIKE :asset_search_supplier)
        )";
        foreach ([
            'asset_search_number',
            'asset_search_name',
            'asset_search_category',
            'asset_search_category_record',
            'asset_search_category_code',
            'asset_search_parent_category',
            'asset_search_parent_category_code',
            'asset_search_model',
            'asset_search_serial',
            'asset_search_barcode',
            'asset_search_storage',
            'asset_search_user',
            'asset_search_supplier',
        ] as $paramName) {
            $params[$paramName] = $searchLike;
        }
    }

    if (($filters['status'] ?? 'all') !== 'all') {
        $conditions[] = "{$alias}.status = :asset_status";
        $params['asset_status'] = (string) $filters['status'];
    }

    if (($filters['condition'] ?? 'all') !== 'all') {
        $conditions[] = "{$alias}.condition_status = :asset_condition";
        $params['asset_condition'] = (string) $filters['condition'];
    }

    if (!empty($filters['storage_id'])) {
        $conditions[] = "{$alias}.storage_id = :asset_storage_id";
        $params['asset_storage_id'] = (int) $filters['storage_id'];
    }

    if (!empty($filters['assigned_user_id']) && !Auth::isStaff()) {
        $conditions[] = "{$alias}.assigned_user_id = :asset_assigned_user_id";
        $params['asset_assigned_user_id'] = (int) $filters['assigned_user_id'];
    }

    $effectiveCategoryId = !empty($filters['category_id'])
        ? (int) $filters['category_id']
        : (!empty($filters['category_parent_id']) ? (int) $filters['category_parent_id'] : 0);

    if ($effectiveCategoryId > 0) {
        $categoryIds = asset_category_descendant_ids($effectiveCategoryId);

        if ($categoryIds === []) {
            $conditions[] = '0 = 1';
        } else {
            $placeholders = [];

            foreach ($categoryIds as $index => $categoryId) {
                $paramName = 'asset_category_id_' . $index;
                $placeholders[] = ':' . $paramName;
                $params[$paramName] = $categoryId;
            }

            $conditions[] = "{$alias}.category_id IN (" . implode(',', $placeholders) . ')';
        }
    }

    if (($filters['active'] ?? 'all') === 'active') {
        $conditions[] = "{$alias}.is_active = 1";
    } elseif (($filters['active'] ?? 'all') === 'archived') {
        $conditions[] = "{$alias}.is_active = 0";
    }

    return ['WHERE ' . implode(' AND ', $conditions), $params];
}
