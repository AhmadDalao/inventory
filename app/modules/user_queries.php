<?php
declare(strict_types=1);

function users_for_access_control(): array
{
    $users = Database::fetchAll(
        'SELECT users.id,
                users.name,
                users.email,
                users.role,
                users.position,
                users.is_active,
                users.assigned_owner_user_id,
                users.manager_user_id,
                users.department_id,
                users.last_login_at,
                users.created_at,
                manager_user.name AS manager_name,
                department.name AS department_name,
                (SELECT COUNT(*)
                   FROM user_storage_assignments assignment
                   INNER JOIN storages storage ON storage.id = assignment.storage_id
                  WHERE assignment.user_id = users.id
                    AND storage.is_active = 1
                    AND storage.is_system = 0) AS storage_count,
                (SELECT GROUP_CONCAT(storage.name ORDER BY assignment.is_default DESC, assignment.access_role DESC, storage.name SEPARATOR ", ")
                   FROM user_storage_assignments assignment
                   INNER JOIN storages storage ON storage.id = assignment.storage_id
                  WHERE assignment.user_id = users.id
                    AND storage.is_active = 1
                    AND storage.is_system = 0) AS storage_names
         FROM users
         LEFT JOIN users manager_user ON manager_user.id = COALESCE(users.manager_user_id, users.assigned_owner_user_id)
         LEFT JOIN departments department ON department.id = users.department_id
         ORDER BY FIELD(users.role, "owner", "admin", "staff"), users.created_at ASC'
    );

    foreach ($users as &$user) {
        $user['permission_count'] = ($user['role'] ?? '') === 'owner'
            ? count(permission_keys())
            : count(Auth::permissionsForUserId((int) $user['id']));
    }
    unset($user);

    return $users;
}

function manager_candidates_for_select(?int $selectedId = null, ?int $excludeUserId = null): array
{
    $params = [];
    $conditions = ['(is_active = 1 AND role IN ("owner", "admin"))'];
    if ($selectedId !== null) {
        $conditions[] = 'id = :selected_id';
        $params['selected_id'] = $selectedId;
    }
    if ($excludeUserId !== null) {
        $excludeSql = ' AND id != :exclude_user_id';
        $params['exclude_user_id'] = $excludeUserId;
    } else {
        $excludeSql = '';
    }

    return Database::fetchAll(
        'SELECT id, name, email, role, position, is_active
         FROM users
         WHERE (' . implode(' OR ', $conditions) . ')' . $excludeSql . '
         ORDER BY FIELD(role, "owner", "admin"), name ASC',
        $params
    );
}

function active_users_for_select(?int $excludeUserId = null): array
{
    $sql = 'SELECT id, name, email, role
            FROM users
            WHERE is_active = 1';
    $params = [];

    if ($excludeUserId !== null) {
        $sql .= ' AND id != :exclude_user_id';
        $params['exclude_user_id'] = $excludeUserId;
    }

    $sql .= ' ORDER BY FIELD(role, "owner", "admin", "staff"), name ASC';

    return Database::fetchAll($sql, $params);
}

function active_staff_users_for_select(?int $selectedId = null): array
{
    $params = [];
    $conditions = ['(is_active = 1 AND role = "staff")'];

    if ($selectedId !== null) {
        $conditions[] = 'id = :selected_id';
        $params['selected_id'] = $selectedId;
    }

    return Database::fetchAll(
        'SELECT id, name, email, role
         FROM users
         WHERE ' . implode(' OR ', $conditions) . '
         ORDER BY name ASC',
        $params
    );
}

function users_with_permission_for_select(string $permission, ?int $excludeUserId = null): array
{
    $users = active_users_for_select($excludeUserId);

    return array_values(array_filter($users, static function (array $user) use ($permission): bool {
        return ($user['role'] ?? '') === 'owner' || Auth::userHasPermission((int) $user['id'], $permission);
    }));
}
