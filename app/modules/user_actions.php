<?php
declare(strict_types=1);

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
        'manager_user_id' => normalize_entity_id(input('manager_user_id')),
        'department_id' => valid_department_assignment_id(input('department_id')),
        'storage_ids' => array_values(array_unique(array_filter(array_map('intval', (array) input('storage_ids', []))))),
        'default_storage_id' => normalize_entity_id(input('default_storage_id')),
        'password' => (string) input('password'),
        'password_confirmation' => (string) input('password_confirmation'),
        'permissions' => is_array(input('permissions', [])) ? input('permissions', []) : [],
    ];

    flash_old_input([
        'name' => $payload['name'],
        'email' => $payload['email'],
        'position' => $payload['position'],
        'role' => $payload['role'],
        'manager_user_id' => (string) ($payload['manager_user_id'] ?? ''),
        'department_id' => (string) ($payload['department_id'] ?? ''),
        'storage_ids' => $payload['storage_ids'],
        'default_storage_id' => (string) ($payload['default_storage_id'] ?? ''),
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

    if (!(Auth::isOwner() || Auth::hasPermission('team.manage'))) {
        $payload['manager_user_id'] = null;
    }
    if (!(Auth::isOwner() || Auth::hasPermission('departments.manage'))) {
        $payload['department_id'] = unassigned_department_id();
    }
    if ($payload['department_id'] === null) {
        $errors[] = 'Pick an active department.';
    }
    if (!(Auth::isOwner() || Auth::hasPermission('storages.assign_users'))) {
        $payload['storage_ids'] = [];
        $payload['default_storage_id'] = null;
    }
    $managerError = manager_assignment_block_reason(0, $payload['manager_user_id']);
    if ($managerError !== null) {
        $errors[] = $managerError;
    }
    if ($payload['default_storage_id'] !== null && !in_array($payload['default_storage_id'], $payload['storage_ids'], true)) {
        $errors[] = 'The default storage must be one of the assigned storages.';
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
            'INSERT INTO users (name, email, password_hash, role, position, is_active, assigned_owner_user_id, manager_user_id, department_id, created_at, updated_at)
             VALUES (:name, :email, :password_hash, :role, :position, 1, :legacy_manager_user_id, :manager_user_id, :department_id, NOW(), NOW())',
            [
                'name' => $payload['name'],
                'email' => $payload['email'],
                'password_hash' => password_hash($payload['password'], PASSWORD_DEFAULT),
                'role' => $payload['role'],
                'position' => $payload['position'],
                'legacy_manager_user_id' => $payload['manager_user_id'],
                'manager_user_id' => $payload['manager_user_id'],
                'department_id' => $payload['department_id'],
            ]
        );

        $userId = Database::lastInsertId();
        save_user_permissions($userId, $permissions, (int) (Auth::user()['id'] ?? 0));
        sync_user_storage_memberships($userId, $payload['storage_ids'], $payload['default_storage_id'], (int) (Auth::user()['id'] ?? 0));
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
            'manager_user_id' => $payload['manager_user_id'],
            'department_id' => $payload['department_id'],
            'storage_ids' => $payload['storage_ids'],
            'default_storage_id' => $payload['default_storage_id'],
            'permissions' => $permissions,
        ]);
    }
    flash('success', 'User created.');
    redirect('/users');
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
        'manager_user_id' => normalize_entity_id(input('manager_user_id')),
        'department_id' => valid_department_assignment_id(input('department_id')),
        'storage_ids' => array_values(array_unique(array_filter(array_map('intval', (array) input('storage_ids', []))))),
        'default_storage_id' => normalize_entity_id(input('default_storage_id')),
        'password' => (string) input('password'),
        'password_confirmation' => (string) input('password_confirmation'),
        'permissions' => is_array(input('permissions', [])) ? input('permissions', []) : [],
    ];

    flash_old_input([
        'name' => $payload['name'],
        'email' => $payload['email'],
        'position' => $payload['position'],
        'role' => $payload['role'],
        'manager_user_id' => (string) ($payload['manager_user_id'] ?? ''),
        'department_id' => (string) ($payload['department_id'] ?? ''),
        'storage_ids' => $payload['storage_ids'],
        'default_storage_id' => (string) ($payload['default_storage_id'] ?? ''),
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

    $storedManagerUserId = normalize_entity_id($userRecord['manager_user_id'] ?? $userRecord['assigned_owner_user_id'] ?? null);
    if ($userRecord['role'] === 'owner') {
        $payload['manager_user_id'] = null;
    } elseif (!(Auth::isOwner() || Auth::hasPermission('team.manage'))) {
        $payload['manager_user_id'] = $storedManagerUserId;
    }
    if (!(Auth::isOwner() || Auth::hasPermission('departments.manage'))) {
        $payload['department_id'] = normalize_entity_id($userRecord['department_id'] ?? null) ?? unassigned_department_id();
    }
    if ($payload['department_id'] === null) {
        $errors[] = 'Pick an active department.';
    }
    if (!(Auth::isOwner() || Auth::hasPermission('storages.assign_users'))) {
        $payload['storage_ids'] = user_assigned_storage_ids((int) $userRecord['id'], false);
        $payload['default_storage_id'] = normalize_entity_id(Database::scalar(
            'SELECT storage_id FROM user_storage_assignments WHERE user_id = :user_id AND is_default = 1 LIMIT 1',
            ['user_id' => $userRecord['id']]
        ));
    }
    $ownedStorageIds = array_map('intval', array_column(Database::fetchAll(
        'SELECT storage_id FROM user_storage_assignments WHERE user_id = :user_id AND access_role = "owner"',
        ['user_id' => $userRecord['id']]
    ), 'storage_id'));
    $allowedDefaultStorageIds = array_values(array_unique(array_merge($payload['storage_ids'], $ownedStorageIds)));
    $managerError = manager_assignment_block_reason((int) $userRecord['id'], $payload['manager_user_id']);
    if ($managerError !== null) {
        $errors[] = $managerError;
    }
    if ($payload['default_storage_id'] !== null && !in_array($payload['default_storage_id'], $allowedDefaultStorageIds, true)) {
        $errors[] = 'The default storage must be one of the assigned or owned storages.';
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
                     assigned_owner_user_id = :legacy_manager_user_id,
                     manager_user_id = :manager_user_id,
                     department_id = :department_id,
                     updated_at = NOW()
                 WHERE id = :id',
            [
                'name' => $payload['name'],
                'email' => $payload['email'],
                'role' => $nextRole,
                'position' => $payload['position'],
                'legacy_manager_user_id' => $payload['manager_user_id'],
                'manager_user_id' => $payload['manager_user_id'],
                'department_id' => $payload['department_id'],
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
            Auth::revokePersistentSessionsForUser((int) $userRecord['id']);
        }

        if ($nextRole !== 'owner') {
            save_user_permissions((int) $userRecord['id'], $permissions, (int) (Auth::user()['id'] ?? 0));
        }
        sync_user_storage_memberships(
            (int) $userRecord['id'],
            array_values(array_unique(array_merge($payload['storage_ids'], $payload['default_storage_id'] !== null ? [$payload['default_storage_id']] : []))),
            $payload['default_storage_id'],
            (int) (Auth::user()['id'] ?? 0)
        );

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
            'manager_user_id' => $payload['manager_user_id'],
            'department_id' => $payload['department_id'],
            'storage_ids' => $payload['storage_ids'],
            'default_storage_id' => $payload['default_storage_id'],
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

    if ($nextStatus === 0) {
        Auth::revokePersistentSessionsForUser((int) $userRecord['id']);
    }

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

function handle_users_revoke_persistent_sessions_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('users.edit');
    verify_csrf();

    $userRecord = find_user_or_abort((int) $params['id']);

    if (!Auth::isOwner() && (string) $userRecord['role'] === 'owner') {
        flash('danger', 'Only the owner can revoke saved logins for the owner account.');
        redirect('/users');
    }

    Auth::revokePersistentSessionsForUser((int) $userRecord['id']);

    if (function_exists('record_activity')) {
        record_activity(
            'user.persistent_sessions_revoked',
            'user',
            (int) $userRecord['id'],
            'Revoked saved browser logins for ' . $userRecord['email'],
            ['revoked_by' => (int) (Auth::user()['id'] ?? 0)]
        );
    }

    flash('success', 'Saved browser logins revoked.');
    redirect('/users');
}
