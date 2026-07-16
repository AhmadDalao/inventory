<?php
declare(strict_types=1);

// Handover pickers and list filters. Function names stay global for route/view compatibility.

function handover_request_owner_candidates_for_select(?int $selectedId = null): array
{
    $params = [];
    $conditions = ['(
        users.is_active = 1
        AND users.role IN ("owner", "admin")
        AND EXISTS (
            SELECT 1
            FROM storages storage
            WHERE storage.owner_user_id = users.id
              AND storage.is_active = 1
              AND storage.is_system = 0
        )
    )'];

    if ($selectedId !== null) {
        $conditions[] = 'users.id = :selected_id';
        $params['selected_id'] = $selectedId;
    }

    return Database::fetchAll(
        'SELECT users.id, users.name, users.email, users.role
         FROM users
         WHERE ' . implode(' OR ', $conditions) . '
         ORDER BY FIELD(users.role, "owner", "admin"), users.name ASC',
        $params
    );
}

function handover_request_assigned_owner(array $user): ?array
{
    $assignedOwnerId = normalize_entity_id($user['assigned_owner_user_id'] ?? null);

    if ($assignedOwnerId === null) {
        return null;
    }

    return Database::fetch(
        'SELECT id, name, email, role, is_active
         FROM users
         WHERE id = :id
         LIMIT 1',
        ['id' => $assignedOwnerId]
    ) ?: null;
}

function handover_source_storages_for_user(array $user, ?int $selectedId = null): array
{
    if (($user['role'] ?? '') === 'owner') {
        return all_storages_for_select($selectedId);
    }

    return storages_owned_by_user_for_select((int) $user['id'], $selectedId);
}

function handover_request_source_storages_for_staff(array $user, ?int $selectedId = null, ?int $selectedOwnerId = null): array
{
    $assignedOwnerId = normalize_entity_id($user['assigned_owner_user_id'] ?? null);
    $requiredOwnerId = $assignedOwnerId ?? $selectedOwnerId;
    $storages = all_storages_for_select($selectedId);

    return array_values(array_filter($storages, static function (array $storage) use ($requiredOwnerId, $selectedId): bool {
        if (empty($storage['owner_user_id'])) {
            return false;
        }

        if ($selectedId !== null && (int) $storage['id'] === $selectedId) {
            return true;
        }

        if ($requiredOwnerId === null) {
            return true;
        }

        return (int) $storage['owner_user_id'] === (int) $requiredOwnerId;
    }));
}

function handover_destination_storages_for_select(?int $selectedId = null): array
{
    return array_values(array_filter(
        all_storages_for_select($selectedId),
        static fn (array $storage): bool => !empty($storage['owner_user_id']) || ($selectedId !== null && (int) $storage['id'] === $selectedId)
    ));
}

function handover_is_storage_transfer(array $handover): bool
{
    return (string) ($handover['recipient_type'] ?? 'staff') === 'storage'
        || !empty($handover['destination_storage_id']);
}

function handover_target_type_label(array $handover): string
{
    return handover_is_storage_transfer($handover) ? 'Storage transfer' : 'Staff use';
}

function handover_filters(): array
{
    $status = (string) query('status', 'all');

    return [
        'search' => trim((string) query('search', '')),
        'status' => in_array($status, ['open', 'requested', 'awaiting_receipt', 'receipt_review', 'delivered', 'pending_approval', 'closed', 'rejected', 'cancelled', 'all'], true) ? $status : 'all',
        'storage_id' => ctype_digit((string) query('storage_id', '')) ? (int) query('storage_id') : null,
        'date_from' => normalize_workflow_date((string) query('date_from', '')),
        'date_to' => normalize_workflow_date((string) query('date_to', '')),
    ];
}

function build_handover_where(array $filters, string $alias = 'h'): array
{
    $conditions = [];
    $params = [];

    if ($filters['status'] === 'open') {
        $conditions[] = "{$alias}.status IN ('requested', 'awaiting_receipt', 'receipt_review', 'delivered', 'pending_approval')";
    } elseif ($filters['status'] !== 'all') {
        $conditions[] = "{$alias}.status = :handover_status";
        $params['handover_status'] = $filters['status'];
    }

    if (!empty($filters['storage_id'])) {
        $conditions[] = "({$alias}.source_storage_id = :handover_storage_id OR {$alias}.destination_storage_id = :handover_storage_id)";
        $params['handover_storage_id'] = (int) $filters['storage_id'];
    }

    if ($filters['search'] !== '') {
        $conditions[] = "(
            {$alias}.handover_number LIKE :handover_search_number
            OR {$alias}.recipient_name LIKE :handover_search_recipient
            OR COALESCE({$alias}.notes, '') LIKE :handover_search_notes
            OR source_storage.name LIKE :handover_search_source_storage
            OR destination_storage.name LIKE :handover_search_destination_storage
            OR EXISTS (
                SELECT 1
                FROM handover_lines handover_lines
                WHERE handover_lines.handover_id = {$alias}.id
                  AND (
                      handover_lines.item_name LIKE :handover_search_item_name
                      OR handover_lines.item_sku LIKE :handover_search_item_sku
                  )
            )
        )";
        $handoverSearchLike = '%' . $filters['search'] . '%';
        $params['handover_search_number'] = $handoverSearchLike;
        $params['handover_search_recipient'] = $handoverSearchLike;
        $params['handover_search_notes'] = $handoverSearchLike;
        $params['handover_search_source_storage'] = $handoverSearchLike;
        $params['handover_search_destination_storage'] = $handoverSearchLike;
        $params['handover_search_item_name'] = $handoverSearchLike;
        $params['handover_search_item_sku'] = $handoverSearchLike;
    }

    if ($filters['date_from'] !== '') {
        $conditions[] = "{$alias}.issued_at >= :handover_date_from";
        $params['handover_date_from'] = $filters['date_from'] . ' 00:00:00';
    }

    if ($filters['date_to'] !== '') {
        $conditions[] = "{$alias}.issued_at <= :handover_date_to";
        $params['handover_date_to'] = $filters['date_to'] . ' 23:59:59';
    }

    [$scopeSql, $scopeParams] = visible_handover_scope($alias);
    $where = $conditions === [] ? 'WHERE 1 = 1' : 'WHERE ' . implode(' AND ', $conditions);

    return [$where . $scopeSql, $params + $scopeParams];
}
