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

    View::render('users/form', [
        'title' => 'Create Admin',
        'mode' => 'create',
        'userRecord' => [
            'name' => old('name', ''),
            'email' => old('email', ''),
            'position' => $selectedPosition,
            'role' => $selectedRole,
            'assigned_owner_user_id' => old('assigned_owner_user_id', ''),
            'is_active' => 1,
        ],
        'positionOptions' => user_position_options(),
        'roleOptions' => user_role_options(),
        'ownerCandidates' => handover_request_owner_candidates_for_select(normalize_entity_id(old('assigned_owner_user_id', ''))),
        'permissionGroups' => permission_groups_for_form(is_array($selectedPermissions) ? sanitize_permission_input($selectedPermissions) : default_permissions_for_position($selectedPosition)),
    ]);
}

function handle_users_edit_page(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('users.edit');

    $userRecord = find_user_or_abort((int) $params['id']);

    View::render('users/form', [
        'title' => 'Edit ' . $userRecord['name'],
        'mode' => 'edit',
        'userRecord' => [
            'id' => $userRecord['id'],
            'name' => old('name', $userRecord['name']),
            'email' => old('email', $userRecord['email']),
            'position' => old('position', $userRecord['position'] ?: ($userRecord['role'] === 'owner' ? 'owner_operator' : ($userRecord['role'] === 'admin' ? 'general_admin' : 'staff'))),
            'role' => old('role', $userRecord['role']),
            'assigned_owner_user_id' => old('assigned_owner_user_id', (string) ($userRecord['assigned_owner_user_id'] ?? '')),
            'is_active' => (int) $userRecord['is_active'],
        ],
        'positionOptions' => user_position_options(),
        'roleOptions' => user_role_options(),
        'ownerCandidates' => handover_request_owner_candidates_for_select(normalize_entity_id(old('assigned_owner_user_id', (string) ($userRecord['assigned_owner_user_id'] ?? '')))),
        'permissionGroups' => permission_groups_for_form(
            is_array(old('permissions'))
                ? sanitize_permission_input((array) old('permissions'))
                : Auth::permissionsForUserId((int) $userRecord['id'])
        ),
    ]);
}
