<?php
declare(strict_types=1);

// Domain module: users. Function names are preserved for route/view compatibility.

// Moved from controllers.php.

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

function handle_users_create_submit(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('users.create');
    verify_csrf();

    $payload = [
        'name' => trim((string) input('name')),
        'email' => strtolower(trim((string) input('email'))),
        'position' => trim((string) input('position', 'operations_manager')),
        'role' => trim((string) input('role', 'admin')),
        'assigned_owner_user_id' => normalize_entity_id(input('assigned_owner_user_id')),
        'password' => (string) input('password'),
        'password_confirmation' => (string) input('password_confirmation'),
        'permissions' => is_array(input('permissions', [])) ? input('permissions', []) : [],
    ];

    flash_old_input([
        'name' => $payload['name'],
        'email' => $payload['email'],
        'position' => $payload['position'],
        'role' => $payload['role'],
        'assigned_owner_user_id' => (string) ($payload['assigned_owner_user_id'] ?? ''),
        'permissions' => $payload['permissions'],
    ]);

    $errors = [];

    if ($payload['name'] === '') {
        $errors[] = 'Name is required.';
    }

    if (!filter_var($payload['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Use a valid email address.';
    }

    if (!array_key_exists($payload['role'], user_role_options())) {
        $errors[] = 'Pick a valid role.';
    }

    if (!array_key_exists($payload['position'], user_position_options())) {
        $errors[] = 'Pick a valid position.';
    }

    if ($payload['role'] !== 'staff') {
        $payload['assigned_owner_user_id'] = null;
    }

    if ($payload['assigned_owner_user_id'] !== null) {
        $assignedOwner = Database::fetch(
            'SELECT id, role, is_active
             FROM users
             WHERE id = :id
             LIMIT 1',
            ['id' => $payload['assigned_owner_user_id']]
        );

        if (!$assignedOwner || (int) ($assignedOwner['is_active'] ?? 0) !== 1 || !in_array((string) ($assignedOwner['role'] ?? ''), ['owner', 'admin'], true)) {
            $errors[] = 'Pick a valid active storage owner for this staff account.';
        }
    }

    if (strlen($payload['password']) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }

    if ($payload['password'] !== $payload['password_confirmation']) {
        $errors[] = 'Passwords do not match.';
    }

    $existingEmail = Database::fetch('SELECT id FROM users WHERE email = :email LIMIT 1', [
        'email' => $payload['email'],
    ]);

    if ($existingEmail) {
        $errors[] = 'Email already exists.';
    }

    if ($errors !== []) {
        flash_errors($errors);
        redirect('/users/create');
    }

    $permissions = sanitize_permission_input($payload['permissions']);

    if ($permissions === []) {
        $permissions = default_permissions_for_position($payload['position']);
    }

    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        Database::execute(
            'INSERT INTO users (name, email, password_hash, role, position, is_active, assigned_owner_user_id, created_at, updated_at)
             VALUES (:name, :email, :password_hash, :role, :position, 1, :assigned_owner_user_id, NOW(), NOW())',
            [
                'name' => $payload['name'],
                'email' => $payload['email'],
                'password_hash' => password_hash($payload['password'], PASSWORD_DEFAULT),
                'role' => $payload['role'],
                'position' => $payload['position'],
                'assigned_owner_user_id' => $payload['assigned_owner_user_id'],
            ]
        );

        $userId = Database::lastInsertId();
        save_user_permissions($userId, $permissions, (int) (Auth::user()['id'] ?? 0));
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/users/create');
    }

    consume_old_input();
    if (function_exists('record_activity')) {
        record_activity('user.created', 'user', $userId, 'Created user ' . $payload['email'], [
            'role' => $payload['role'],
            'position' => $payload['position'],
            'permissions' => $permissions,
        ]);
    }
    flash('success', 'User created.');
    redirect('/users');
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

function handle_users_edit_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('users.edit');
    verify_csrf();

    $userRecord = find_user_or_abort((int) $params['id']);

    $payload = [
        'name' => trim((string) input('name')),
        'email' => strtolower(trim((string) input('email'))),
        'position' => trim((string) input('position', (string) ($userRecord['position'] ?? 'general_admin'))),
        'role' => trim((string) input('role', (string) $userRecord['role'])),
        'assigned_owner_user_id' => normalize_entity_id(input('assigned_owner_user_id')),
        'password' => (string) input('password'),
        'password_confirmation' => (string) input('password_confirmation'),
        'permissions' => is_array(input('permissions', [])) ? input('permissions', []) : [],
    ];

    flash_old_input([
        'name' => $payload['name'],
        'email' => $payload['email'],
        'position' => $payload['position'],
        'role' => $payload['role'],
        'assigned_owner_user_id' => (string) ($payload['assigned_owner_user_id'] ?? ''),
        'permissions' => $payload['permissions'],
    ]);

    $errors = [];

    if ($payload['name'] === '') {
        $errors[] = 'Name is required.';
    }

    if (!filter_var($payload['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Use a valid email address.';
    }

    if ($userRecord['role'] !== 'owner' && !array_key_exists($payload['role'], user_role_options())) {
        $errors[] = 'Pick a valid role.';
    }

    if (!array_key_exists($payload['position'], user_position_options())) {
        $errors[] = 'Pick a valid position.';
    }

    if ($userRecord['role'] === 'owner' || $payload['role'] !== 'staff') {
        $payload['assigned_owner_user_id'] = null;
    }

    if ($payload['assigned_owner_user_id'] !== null) {
        $assignedOwner = Database::fetch(
            'SELECT id, role, is_active
             FROM users
             WHERE id = :id
             LIMIT 1',
            ['id' => $payload['assigned_owner_user_id']]
        );

        if (!$assignedOwner || (int) ($assignedOwner['is_active'] ?? 0) !== 1 || !in_array((string) ($assignedOwner['role'] ?? ''), ['owner', 'admin'], true)) {
            $errors[] = 'Pick a valid active storage owner for this staff account.';
        }
    }

    if ($payload['password'] !== '' && strlen($payload['password']) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }

    if ($payload['password'] !== $payload['password_confirmation']) {
        $errors[] = 'Passwords do not match.';
    }

    $existingEmail = Database::fetch(
        'SELECT id FROM users WHERE email = :email AND id != :id LIMIT 1',
        ['email' => $payload['email'], 'id' => $userRecord['id']]
    );

    if ($existingEmail) {
        $errors[] = 'Email already exists.';
    }

    if ($errors !== []) {
        flash_errors($errors);
        redirect('/users/' . $userRecord['id'] . '/edit');
    }

    $nextRole = $userRecord['role'] === 'owner' ? 'owner' : $payload['role'];
    $permissions = $nextRole === 'owner'
        ? permission_keys()
        : sanitize_permission_input($payload['permissions']);

    if ($nextRole !== 'owner' && $permissions === []) {
        $permissions = default_permissions_for_position($payload['position']);
    }

    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        Database::execute(
            'UPDATE users
                 SET name = :name,
                     email = :email,
                     role = :role,
                     position = :position,
                     assigned_owner_user_id = :assigned_owner_user_id,
                     updated_at = NOW()
                 WHERE id = :id',
            [
                'name' => $payload['name'],
                'email' => $payload['email'],
                'role' => $nextRole,
                'position' => $payload['position'],
                'assigned_owner_user_id' => $payload['assigned_owner_user_id'],
                'id' => $userRecord['id'],
            ]
        );

        if ($payload['password'] !== '') {
            Database::execute(
                'UPDATE users SET password_hash = :password_hash, updated_at = NOW() WHERE id = :id',
                [
                    'password_hash' => password_hash($payload['password'], PASSWORD_DEFAULT),
                    'id' => $userRecord['id'],
                ]
            );
        }

        if ($nextRole !== 'owner') {
            save_user_permissions((int) $userRecord['id'], $permissions, (int) (Auth::user()['id'] ?? 0));
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/users/' . $userRecord['id'] . '/edit');
    }

    consume_old_input();
    if (function_exists('record_activity')) {
        record_activity('user.updated', 'user', (int) $userRecord['id'], 'Updated user ' . $payload['email'], [
            'role' => $nextRole,
            'position' => $payload['position'],
            'password_changed' => $payload['password'] !== '',
            'permissions' => $nextRole === 'owner' ? ['owner_all'] : $permissions,
        ]);
    }
    flash('success', 'User updated.');
    redirect('/users');
}

function handle_users_status_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('users.disable');
    verify_csrf();

    $userRecord = find_user_or_abort((int) $params['id']);
    $currentUser = Auth::user();

    if ($userRecord['role'] === 'owner') {
        flash('danger', 'You do not disable the owner account. That is how stupid outages happen.');
        redirect('/users');
    }

    if ((int) $userRecord['id'] === (int) $currentUser['id']) {
        flash('danger', 'Disabling yourself is a rookie move.');
        redirect('/users');
    }

    $nextStatus = (int) $userRecord['is_active'] === 1 ? 0 : 1;

    Database::execute(
        'UPDATE users SET is_active = :is_active, updated_at = NOW() WHERE id = :id',
        [
            'is_active' => $nextStatus,
            'id' => $userRecord['id'],
        ]
    );

    if (function_exists('record_activity')) {
        record_activity($nextStatus ? 'user.restored' : 'user.disabled', 'user', (int) $userRecord['id'], ($nextStatus ? 'Restored ' : 'Disabled ') . $userRecord['email']);
    }
    flash('success', $nextStatus ? 'User restored.' : 'User disabled.');
    redirect('/users');
}

function handle_users_send_reset_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('users.edit');
    verify_csrf();

    $userRecord = find_user_or_abort((int) $params['id']);
    $currentUser = Auth::user();

    if (!Auth::isOwner() && (string) $userRecord['role'] === 'owner') {
        flash('danger', 'Only the owner can send a reset link to the owner account.');
        redirect('/users');
    }

    if ((int) ($userRecord['is_active'] ?? 0) !== 1) {
        flash('danger', 'Restore this user before sending a reset link.');
        redirect('/users');
    }

    $token = create_password_reset_token($userRecord, $currentUser ? (int) $currentUser['id'] : null);
    $result = send_password_reset_email($userRecord, $token, $currentUser ? (int) $currentUser['id'] : null);

    if (function_exists('record_activity')) {
        record_activity('user.password_reset_sent', 'user', (int) $userRecord['id'], 'Sent password reset link to ' . $userRecord['email'], [
            'email_status' => $result['status'] ?? 'unknown',
            'sent_by' => $currentUser['email'] ?? null,
        ]);
    }

    if (($result['status'] ?? '') === 'sent') {
        flash('success', 'Password reset email sent.');
    } elseif (($result['status'] ?? '') === 'suppressed') {
        flash('warning', 'Reset link created but email was not sent: ' . ($result['error'] ?? 'suppressed'));
    } else {
        flash('danger', 'Reset link created, but email failed: ' . ($result['error'] ?? 'unknown error'));
    }

    redirect('/users');
}

// Moved from workflows.php.

function save_user_permissions(int $userId, array $permissions, ?int $performedBy = null): void
{
    $permissions = sanitize_permission_input($permissions);
    $pdo = Database::connection();
    $ownsTransaction = !$pdo->inTransaction();

    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        Database::execute('DELETE FROM user_permissions WHERE user_id = :user_id', ['user_id' => $userId]);

        foreach ($permissions as $permission) {
            Database::execute(
                'INSERT INTO user_permissions (user_id, permission_key, created_by, created_at)
                 VALUES (:user_id, :permission_key, :created_by, NOW())',
                [
                    'user_id' => $userId,
                    'permission_key' => $permission,
                    'created_by' => $performedBy,
                ]
            );
        }

        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->commit();
        }
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }

    Auth::resetPermissionCache();
}

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
                users.last_login_at,
                users.created_at,
                assigned_owner.name AS assigned_owner_name
         FROM users
         LEFT JOIN users assigned_owner ON assigned_owner.id = users.assigned_owner_user_id
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
