<?php
declare(strict_types=1);

// Request visibility, filter parsing, and SQL where-clause construction.
function request_destination_storages_for_user(array $user, ?int $selectedId = null): array
{
    if (($user['role'] ?? '') === 'owner') {
        return all_storages_for_select($selectedId);
    }

    return storages_owned_by_user_for_select((int) $user['id'], $selectedId);
}

function visible_request_scope(string $alias = 'r'): array
{
    $user = Auth::user();

    if ($user === null || Auth::isOwner()) {
        return ['', []];
    }

    $userId = (int) $user['id'];

    return [
        " AND (
            {$alias}.requester_user_id = :request_scope_requester_user_id
            OR {$alias}.approver_user_id = :request_scope_approver_user_id
            OR {$alias}.manager_user_id = :request_scope_manager_user_id
            OR EXISTS (
                SELECT 1 FROM user_storage_assignments request_source_owner
                WHERE request_source_owner.storage_id = {$alias}.source_storage_id
                  AND request_source_owner.user_id = :request_scope_source_owner_user_id
                  AND request_source_owner.access_role = 'owner'
            )
            OR EXISTS (
                SELECT 1 FROM user_storage_assignments request_destination_owner
                WHERE request_destination_owner.storage_id = {$alias}.destination_storage_id
                  AND request_destination_owner.user_id = :request_scope_destination_owner_user_id
                  AND request_destination_owner.access_role = 'owner'
            )
        )",
        [
            'request_scope_requester_user_id' => $userId,
            'request_scope_approver_user_id' => $userId,
            'request_scope_manager_user_id' => $userId,
            'request_scope_source_owner_user_id' => $userId,
            'request_scope_destination_owner_user_id' => $userId,
        ],
    ];
}

function request_filters(): array
{
    $status = (string) query('status', 'all');

    return [
        'search' => trim((string) query('search', '')),
        'status' => in_array($status, ['open', 'draft', 'pending', 'approved', 'receipt_review', 'completed', 'rejected', 'cancelled', 'all'], true) ? $status : 'all',
        'storage_id' => ctype_digit((string) query('storage_id', '')) ? (int) query('storage_id') : null,
        'date_from' => normalize_workflow_date((string) query('date_from', '')),
        'date_to' => normalize_workflow_date((string) query('date_to', '')),
    ];
}

function build_request_where(array $filters, string $alias = 'r'): array
{
    $conditions = [];
    $params = [];

    if ($filters['status'] === 'open') {
        $conditions[] = "{$alias}.status IN ('pending', 'approved', 'receipt_review')";
    } elseif ($filters['status'] !== 'all') {
        $conditions[] = "{$alias}.status = :request_status";
        $params['request_status'] = $filters['status'];
    }

    if (!empty($filters['storage_id'])) {
        $conditions[] = "({$alias}.source_storage_id = :request_source_storage_id OR {$alias}.destination_storage_id = :request_destination_storage_id)";
        $params['request_source_storage_id'] = (int) $filters['storage_id'];
        $params['request_destination_storage_id'] = (int) $filters['storage_id'];
    }

    if ($filters['search'] !== '') {
        $conditions[] = "(
            {$alias}.request_number LIKE :request_search_number
            OR COALESCE({$alias}.notes, '') LIKE :request_search_notes
            OR requester.name LIKE :request_search_requester
            OR approver.name LIKE :request_search_approver
            OR source_storage.name LIKE :request_search_source_storage
            OR destination_storage.name LIKE :request_search_destination_storage
            OR EXISTS (
                SELECT 1
                FROM item_request_lines request_lines
                WHERE request_lines.request_id = {$alias}.id
                  AND (
                      request_lines.item_name LIKE :request_search_item_name
                      OR request_lines.item_sku LIKE :request_search_item_sku
                  )
            )
        )";
        $requestSearchLike = '%' . $filters['search'] . '%';
        $params['request_search_number'] = $requestSearchLike;
        $params['request_search_notes'] = $requestSearchLike;
        $params['request_search_requester'] = $requestSearchLike;
        $params['request_search_approver'] = $requestSearchLike;
        $params['request_search_source_storage'] = $requestSearchLike;
        $params['request_search_destination_storage'] = $requestSearchLike;
        $params['request_search_item_name'] = $requestSearchLike;
        $params['request_search_item_sku'] = $requestSearchLike;
    }

    if ($filters['date_from'] !== '') {
        $conditions[] = "{$alias}.requested_at >= :request_date_from";
        $params['request_date_from'] = $filters['date_from'] . ' 00:00:00';
    }

    if ($filters['date_to'] !== '') {
        $conditions[] = "{$alias}.requested_at <= :request_date_to";
        $params['request_date_to'] = $filters['date_to'] . ' 23:59:59';
    }

    [$scopeSql, $scopeParams] = visible_request_scope($alias);
    $where = $conditions === [] ? 'WHERE 1 = 1' : 'WHERE ' . implode(' AND ', $conditions);

    return [$where . $scopeSql, $params + $scopeParams];
}
