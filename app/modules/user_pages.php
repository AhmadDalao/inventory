<?php
declare(strict_types=1);

function handle_users_index(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('users.view');

    View::render('users/index', [
        'title' => site_setting('page.users', 'Admins'),
        'users' => users_for_access_control(),
    ]);
}

function handle_users_create_page(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('users.create');

    $selectedPosition = (string) old('position', 'operations_manager');
    $selectedRole = (string) old('role', access_role_for_position($selectedPosition));
    $selectedPermissions = old('permissions', default_permissions_for_position($selectedPosition));
    $managerUserId = normalize_entity_id(old('manager_user_id', ''));
    $selectedStorageIds = array_values(array_unique(array_filter(array_map('intval', (array) old('storage_ids', [])))));
    $defaultStorageId = normalize_entity_id(old('default_storage_id', ''));
    $departmentId = valid_department_assignment_id(old('department_id', unassigned_department_id()));

    View::render('users/form', [
        'title' => 'Create Admin',
        'mode' => 'create',
        'userRecord' => [
            'name' => old('name', ''),
            'email' => old('email', ''),
            'position' => $selectedPosition,
            'role' => $selectedRole,
            'manager_user_id' => $managerUserId,
            'department_id' => $departmentId,
            'is_active' => 1,
        ],
        'positionOptions' => user_position_options(),
        'roleOptions' => user_role_options(),
        'managerCandidates' => manager_candidates_for_select($managerUserId),
        'storageOptions' => all_storages_for_select($defaultStorageId),
        'selectedStorageIds' => $selectedStorageIds,
        'ownedStorageIds' => [],
        'defaultStorageId' => $defaultStorageId,
        'canManageTeam' => Auth::isOwner() || Auth::hasPermission('team.manage'),
        'canManageDepartments' => Auth::isOwner() || Auth::hasPermission('departments.manage'),
        'departmentOptions' => department_options(),
        'canAssignStorages' => Auth::isOwner() || Auth::hasPermission('storages.assign_users'),
        'permissionGroups' => permission_groups_for_form(is_array($selectedPermissions) ? sanitize_permission_input($selectedPermissions) : default_permissions_for_position($selectedPosition)),
    ]);
}

function handle_users_edit_page(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('users.edit');

    $userRecord = find_user_or_abort((int) $params['id']);
    $storedManagerUserId = normalize_entity_id($userRecord['manager_user_id'] ?? $userRecord['assigned_owner_user_id'] ?? null);
    $managerUserId = normalize_entity_id(old('manager_user_id', (string) ($storedManagerUserId ?? '')));
    $storedMemberStorageIds = user_assigned_storage_ids((int) $userRecord['id'], false);
    $storedOwnedStorageIds = array_map('intval', array_column(Database::fetchAll(
        'SELECT storage_id FROM user_storage_assignments WHERE user_id = :user_id AND access_role = "owner"',
        ['user_id' => $userRecord['id']]
    ), 'storage_id'));
    $selectedStorageIds = array_values(array_unique(array_filter(array_map(
        'intval',
        (array) old('storage_ids', $storedMemberStorageIds)
    ))));
    $storedDefaultStorageId = normalize_entity_id(Database::scalar(
        'SELECT storage_id FROM user_storage_assignments WHERE user_id = :user_id AND is_default = 1 LIMIT 1',
        ['user_id' => $userRecord['id']]
    ));
    $defaultStorageId = normalize_entity_id(old('default_storage_id', (string) ($storedDefaultStorageId ?? '')));
    $departmentId = valid_department_assignment_id(
        old('department_id', (string) ($userRecord['department_id'] ?? '')),
        normalize_entity_id($userRecord['department_id'] ?? null)
    );

    View::render('users/form', [
        'title' => 'Edit ' . $userRecord['name'],
        'mode' => 'edit',
        'userRecord' => [
            'id' => $userRecord['id'],
            'name' => old('name', $userRecord['name']),
            'email' => old('email', $userRecord['email']),
            'position' => old('position', $userRecord['position'] ?: ($userRecord['role'] === 'owner' ? 'owner_operator' : ($userRecord['role'] === 'admin' ? 'general_admin' : 'staff'))),
            'role' => old('role', $userRecord['role']),
            'manager_user_id' => $managerUserId,
            'department_id' => $departmentId,
            'is_active' => (int) $userRecord['is_active'],
        ],
        'positionOptions' => user_position_options(),
        'roleOptions' => user_role_options(),
        'managerCandidates' => manager_candidates_for_select($managerUserId, (int) $userRecord['id']),
        'storageOptions' => all_storages_for_select($defaultStorageId),
        'selectedStorageIds' => $selectedStorageIds,
        'ownedStorageIds' => $storedOwnedStorageIds,
        'defaultStorageId' => $defaultStorageId,
        'canManageTeam' => Auth::isOwner() || Auth::hasPermission('team.manage'),
        'canManageDepartments' => Auth::isOwner() || Auth::hasPermission('departments.manage'),
        'departmentOptions' => department_options(),
        'canAssignStorages' => Auth::isOwner() || Auth::hasPermission('storages.assign_users'),
        'permissionGroups' => permission_groups_for_form(
            is_array(old('permissions'))
                ? sanitize_permission_input((array) old('permissions'))
                : Auth::permissionsForUserId((int) $userRecord['id'])
        ),
    ]);
}
