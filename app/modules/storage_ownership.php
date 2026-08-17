<?php
declare(strict_types=1);

function storage_type_label(string $type): string
{
    return $type === 'warehouse' ? 'Warehouse' : 'Storage';
}

function active_storage_name_exists(string $name, ?int $ignoreId = null): bool
{
    $sql = 'SELECT id FROM storages WHERE LOWER(name) = LOWER(:name) AND is_active = 1 AND is_system = 0';
    $params = ['name' => $name];

    if ($ignoreId !== null) {
        $sql .= ' AND id != :ignore_id';
        $params['ignore_id'] = $ignoreId;
    }

    $sql .= ' LIMIT 1';

    return Database::fetch($sql, $params) !== null;
}

function requested_storage_copy_source(): ?array
{
    $copyStorageId = normalize_entity_id(input('copy_storage_id', input('copy', old('copy_storage_id'))));

    if ($copyStorageId === null) {
        return null;
    }

    return find_storage_or_abort($copyStorageId);
}

function next_storage_copy_name(string $name): string
{
    $baseName = trim($name) !== '' ? trim($name) : 'Location';
    $candidate = $baseName . ' Copy';
    $suffix = 2;

    while (active_storage_name_exists($candidate)) {
        $candidate = $baseName . ' Copy ' . $suffix;
        $suffix++;
    }

    return $candidate;
}

function user_is_global_owner(int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }

    return (string) Database::scalar(
        'SELECT role FROM users WHERE id = :id AND is_active = 1 LIMIT 1',
        ['id' => $userId]
    ) === 'owner';
}

function storage_owner_user_ids(int $storageId): array
{
    $rows = Database::fetchAll(
        'SELECT assignment.user_id
         FROM user_storage_assignments assignment
         INNER JOIN users owner_user ON owner_user.id = assignment.user_id AND owner_user.is_active = 1
         WHERE assignment.storage_id = :storage_id
           AND assignment.access_role = "owner"
         ORDER BY assignment.id ASC',
        ['storage_id' => $storageId]
    );
    $ownerIds = array_values(array_unique(array_map('intval', array_column($rows, 'user_id'))));

    $primaryOwnerId = normalize_entity_id(Database::scalar(
        'SELECT storage.owner_user_id
         FROM storages storage
         INNER JOIN users owner_user ON owner_user.id = storage.owner_user_id AND owner_user.is_active = 1
         WHERE storage.id = :id
         LIMIT 1',
        ['id' => $storageId]
    ));
    if ($primaryOwnerId !== null && !in_array($primaryOwnerId, $ownerIds, true)) {
        array_unshift($ownerIds, $primaryOwnerId);
    }

    return $ownerIds;
}

function storage_owner_user_id(int $storageId): ?int
{
    $ownerIds = storage_owner_user_ids($storageId);
    if ($ownerIds !== []) {
        return $ownerIds[0];
    }

    return normalize_entity_id(Database::scalar(
        'SELECT created_by FROM storages WHERE id = :id LIMIT 1',
        ['id' => $storageId]
    ));
}

function storage_is_owned_by_user(int $storageId, int $userId): bool
{
    return $userId > 0 && (user_is_global_owner($userId) || in_array($userId, storage_owner_user_ids($storageId), true));
}

function storage_assigned_user_ids(int $storageId, ?string $role = null): array
{
    $params = ['storage_id' => $storageId];
    $roleSql = '';
    if (in_array($role, ['owner', 'member'], true)) {
        $roleSql = ' AND assignment.access_role = :access_role';
        $params['access_role'] = $role;
    }

    $rows = Database::fetchAll(
        'SELECT assignment.user_id
         FROM user_storage_assignments assignment
         INNER JOIN users assigned_user ON assigned_user.id = assignment.user_id AND assigned_user.is_active = 1
         WHERE assignment.storage_id = :storage_id' . $roleSql . '
         ORDER BY assignment.access_role DESC, assignment.is_default DESC, assignment.id ASC',
        $params
    );

    return array_values(array_unique(array_map('intval', array_column($rows, 'user_id'))));
}

function user_assigned_storage_ids(int $userId, bool $includeOwned = true, bool $includeSystem = false): array
{
    $systemSql = $includeSystem ? '' : ' AND storage.is_system = 0';
    $roleSql = $includeOwned ? '' : ' AND assignment.access_role = "member"';
    $rows = Database::fetchAll(
        'SELECT assignment.storage_id
         FROM user_storage_assignments assignment
         INNER JOIN storages storage ON storage.id = assignment.storage_id AND storage.is_active = 1' . $systemSql . '
         WHERE assignment.user_id = :user_id' . $roleSql . '
         ORDER BY assignment.is_default DESC, assignment.id ASC',
        ['user_id' => $userId]
    );

    return array_values(array_unique(array_map('intval', array_column($rows, 'storage_id'))));
}

function user_can_view_all_storages(int $userId): bool
{
    return user_is_global_owner($userId) || Auth::userHasPermission($userId, 'storages.view_all');
}

function user_visible_storage_ids(int $userId, bool $includeSystem = false): array
{
    if (user_can_view_all_storages($userId)) {
        $systemSql = $includeSystem ? '' : ' AND is_system = 0';

        return array_map('intval', array_column(Database::fetchAll(
            'SELECT id FROM storages WHERE is_active = 1' . $systemSql . ' ORDER BY id ASC'
        ), 'id'));
    }

    return user_assigned_storage_ids($userId, true, $includeSystem);
}

function user_can_view_storage(int $userId, int $storageId): bool
{
    if ($userId <= 0 || $storageId <= 0) {
        return false;
    }

    if (user_can_view_all_storages($userId)) {
        return true;
    }

    return (int) Database::scalar(
        'SELECT COUNT(*) FROM user_storage_assignments WHERE user_id = :user_id AND storage_id = :storage_id',
        ['user_id' => $userId, 'storage_id' => $storageId]
    ) > 0;
}

function user_can_manage_storage(int $userId, int $storageId): bool
{
    return user_is_global_owner($userId)
        || (storage_is_owned_by_user($storageId, $userId) && Auth::userHasPermission($userId, 'storages.edit'));
}

function storage_assignment_rows(int $storageId): array
{
    return Database::fetchAll(
        'SELECT assignment.*, assigned_user.name, assigned_user.email, assigned_user.role
         FROM user_storage_assignments assignment
         INNER JOIN users assigned_user ON assigned_user.id = assignment.user_id
         WHERE assignment.storage_id = :storage_id
         ORDER BY assignment.access_role DESC, assigned_user.name ASC',
        ['storage_id' => $storageId]
    );
}

function sync_storage_assignments(
    int $storageId,
    int $primaryOwnerId,
    array $ownerUserIds,
    array $memberUserIds,
    int $actorUserId
): void {
    $defaultByUser = [];
    foreach (Database::fetchAll(
        'SELECT user_id, is_default FROM user_storage_assignments WHERE storage_id = :storage_id',
        ['storage_id' => $storageId]
    ) as $existingAssignment) {
        $defaultByUser[(int) $existingAssignment['user_id']] = (int) $existingAssignment['is_default'];
    }
    $ownerUserIds = array_values(array_unique(array_filter(array_map('intval', $ownerUserIds), static fn (int $id): bool => $id > 0)));
    if (!in_array($primaryOwnerId, $ownerUserIds, true)) {
        array_unshift($ownerUserIds, $primaryOwnerId);
    }
    $memberUserIds = array_values(array_diff(
        array_unique(array_filter(array_map('intval', $memberUserIds), static fn (int $id): bool => $id > 0)),
        $ownerUserIds
    ));

    if ($ownerUserIds === []) {
        throw new RuntimeException('Every active storage needs at least one owner.');
    }

    Database::execute('DELETE FROM user_storage_assignments WHERE storage_id = :storage_id', ['storage_id' => $storageId]);
    foreach (['owner' => $ownerUserIds, 'member' => $memberUserIds] as $accessRole => $userIds) {
        foreach ($userIds as $userId) {
            Database::execute(
                'INSERT INTO user_storage_assignments (user_id, storage_id, access_role, is_default, created_by, created_at, updated_at)
                 VALUES (:user_id, :storage_id, :access_role, :is_default, :created_by, NOW(), NOW())',
                [
                    'user_id' => $userId,
                    'storage_id' => $storageId,
                    'access_role' => $accessRole,
                    'is_default' => $defaultByUser[$userId] ?? 0,
                    'created_by' => $actorUserId,
                ]
            );
        }
    }
}

function sync_user_storage_memberships(
    int $userId,
    array $storageIds,
    ?int $defaultStorageId,
    int $actorUserId
): void {
    $storageIds = array_values(array_unique(array_filter(
        array_map('intval', $storageIds),
        static fn (int $storageId): bool => $storageId > 0
    )));

    if ($defaultStorageId !== null && !in_array($defaultStorageId, $storageIds, true)) {
        $storageIds[] = $defaultStorageId;
    }

    if ($storageIds !== []) {
        $placeholders = implode(',', array_fill(0, count($storageIds), '?'));
        $availableIds = array_map('intval', array_column(Database::fetchAll(
            'SELECT id FROM storages WHERE is_active = 1 AND is_system = 0 AND id IN (' . $placeholders . ')',
            $storageIds
        ), 'id'));
        $unavailableIds = array_diff($storageIds, $availableIds);
        if ($unavailableIds !== []) {
            throw new RuntimeException('One selected storage is unavailable.');
        }
    }

    Database::execute(
        'DELETE FROM user_storage_assignments WHERE user_id = :user_id AND access_role = "member"',
        ['user_id' => $userId]
    );

    foreach ($storageIds as $storageId) {
        $ownerAssignmentExists = (int) Database::scalar(
            'SELECT COUNT(*) FROM user_storage_assignments
             WHERE user_id = :user_id AND storage_id = :storage_id AND access_role = "owner"',
            ['user_id' => $userId, 'storage_id' => $storageId]
        ) > 0;

        if (!$ownerAssignmentExists) {
            Database::execute(
                'INSERT INTO user_storage_assignments
                    (user_id, storage_id, access_role, is_default, created_by, created_at, updated_at)
                 VALUES
                    (:user_id, :storage_id, "member", :is_default, :created_by, NOW(), NOW())',
                [
                    'user_id' => $userId,
                    'storage_id' => $storageId,
                    'is_default' => $storageId === $defaultStorageId ? 1 : 0,
                    'created_by' => $actorUserId,
                ]
            );
        }
    }

    set_user_default_storage($userId, $defaultStorageId);
}

function set_user_default_storage(int $userId, ?int $storageId): void
{
    Database::execute('UPDATE user_storage_assignments SET is_default = 0, updated_at = NOW() WHERE user_id = :user_id', ['user_id' => $userId]);
    if ($storageId !== null) {
        Database::execute(
            'UPDATE user_storage_assignments SET is_default = 1, updated_at = NOW()
             WHERE user_id = :user_id AND storage_id = :storage_id',
            ['user_id' => $userId, 'storage_id' => $storageId]
        );
    }
}

function storages_owned_by_user_for_select(int $userId, ?int $selectedId = null): array
{
    $params = ['owner_user_id' => $userId];
    if (user_is_global_owner($userId)) {
        $conditions = ['(storages.is_active = 1 AND storages.is_system = 0)'];
        unset($params['owner_user_id']);
    } else {
        $conditions = ['(storages.is_active = 1 AND storages.is_system = 0 AND EXISTS (
            SELECT 1 FROM user_storage_assignments owner_assignment
            WHERE owner_assignment.storage_id = storages.id
              AND owner_assignment.user_id = :owner_user_id
              AND owner_assignment.access_role = "owner"
        ))'];
    }

    if ($selectedId !== null && user_can_view_storage($userId, $selectedId)) {
        $conditions[] = 'storages.id = :selected_id';
        $params['selected_id'] = $selectedId;
    }

    return Database::fetchAll(
        'SELECT storages.id, storages.name, storages.storage_type, storages.is_active,
                storages.owner_user_id, owner_user.name AS owner_name
         FROM storages
         LEFT JOIN users owner_user ON owner_user.id = storages.owner_user_id
         WHERE ' . implode(' OR ', $conditions) . '
         ORDER BY FIELD(storages.storage_type, "warehouse", "storage"), storages.name ASC',
        $params
    );
}
