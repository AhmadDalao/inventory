<?php
declare(strict_types=1);

function storage_filters(): array
{
    $status = (string) query('status', 'all');
    $type = (string) query('type', '');
    $usageProfile = (string) query('usage_profile', '');

    return [
        'search' => trim((string) query('search', '')),
        'status' => in_array($status, ['active', 'archived', 'all'], true) ? $status : 'all',
        'type' => in_array($type, ['warehouse', 'storage'], true) ? $type : '',
        'usage_profile' => in_array($usageProfile, storage_usage_profile_values(), true) ? $usageProfile : '',
        'storage_id' => ctype_digit((string) query('storage_id', '')) ? (int) query('storage_id') : null,
    ];
}

function build_storage_where(array $filters, string $alias = 's'): array
{
    $conditions = ["{$alias}.is_system = 0"];
    $params = [];

    $currentUserId = (int) (Auth::user()['id'] ?? 0);
    if ($currentUserId > 0 && !user_can_view_all_storages($currentUserId)) {
        $conditions[] = "EXISTS (
            SELECT 1
            FROM user_storage_assignments visible_assignment
            WHERE visible_assignment.storage_id = {$alias}.id
              AND visible_assignment.user_id = :visible_user_id
        )";
        $params['visible_user_id'] = $currentUserId;
    }

    if ($filters['status'] === 'active') {
        $conditions[] = "{$alias}.is_active = 1";
    } elseif ($filters['status'] === 'archived') {
        $conditions[] = "{$alias}.is_active = 0";
    }

    if ($filters['search'] !== '') {
        $conditions[] = "({$alias}.name LIKE :search_name OR COALESCE({$alias}.notes, '') LIKE :search_notes)";
        $params['search_name'] = '%' . $filters['search'] . '%';
        $params['search_notes'] = '%' . $filters['search'] . '%';
    }

    if ($filters['type'] !== '') {
        $conditions[] = "{$alias}.storage_type = :storage_type";
        $params['storage_type'] = $filters['type'];
    }

    if (($filters['usage_profile'] ?? '') !== '') {
        $conditions[] = "{$alias}.usage_profile = :usage_profile";
        $params['usage_profile'] = normalize_storage_usage_profile((string) $filters['usage_profile']);
    }

    if (($filters['storage_id'] ?? null) !== null) {
        $conditions[] = "{$alias}.id = :storage_id";
        $params['storage_id'] = (int) $filters['storage_id'];
    }

    return [
        $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '',
        $params,
    ];
}
