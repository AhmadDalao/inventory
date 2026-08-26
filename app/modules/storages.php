<?php
declare(strict_types=1);

// Domain module: storage create/edit/archive action handlers.

function handle_storages_create_submit(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('storages.create');
    verify_csrf();

    $user = Auth::user();
    $copySource = requested_storage_copy_source();
    $payload = [
        'name' => trim((string) input('name')),
        'storage_type' => (string) input('storage_type', 'storage'),
        'usage_profile' => normalize_storage_usage_profile((string) input('usage_profile', 'general')),
        'notes' => trim((string) input('notes')),
        'owner_user_id' => normalize_entity_id(input('owner_user_id')),
        'owner_user_ids' => array_values(array_unique(array_filter(array_map('intval', (array) input('owner_user_ids', []))))),
        'member_user_ids' => array_values(array_unique(array_filter(array_map('intval', (array) input('member_user_ids', []))))),
        'copy_contents_mode' => (string) input('copy_contents_mode', 'empty'),
    ];
    $canAssignUsers = Auth::hasPermission('storages.assign_users');
    if (!$canAssignUsers) {
        $payload['owner_user_id'] = (int) $user['id'];
        $payload['owner_user_ids'] = [(int) $user['id']];
        $payload['member_user_ids'] = [];
    }

    flash_old_input($payload + [
        'copy_storage_id' => $copySource ? (string) $copySource['id'] : '',
    ]);

    $errors = [];

    if ($payload['name'] === '') {
        $errors[] = 'Storage name is required.';
    }

    if (!in_array($payload['storage_type'], ['warehouse', 'storage'], true)) {
        $errors[] = 'Pick a valid location type.';
    }

    if (!in_array((string) input('usage_profile', 'general'), storage_usage_profile_values(), true)) {
        $errors[] = 'Pick a valid usage reporting profile.';
    }

    if (!in_array($payload['copy_contents_mode'], ['empty', 'item_setup', 'current_stock'], true)) {
        $errors[] = 'Pick a valid copy mode.';
    }

    $ownerRecord = null;

    if (!$payload['owner_user_id']) {
        $errors[] = 'Pick which admin owns this storage.';
    } else {
        $ownerRecord = Database::fetch(
            'SELECT id, role, is_active
             FROM users
             WHERE id = :id
             LIMIT 1',
            ['id' => $payload['owner_user_id']]
        );

        if (!$ownerRecord || (int) ($ownerRecord['is_active'] ?? 0) !== 1 || !in_array((string) ($ownerRecord['role'] ?? ''), ['owner', 'admin'], true)) {
            $errors[] = 'Pick a valid active storage owner.';
        }
    }

    if ($payload['owner_user_id']) {
        $payload['owner_user_ids'][] = (int) $payload['owner_user_id'];
        $payload['owner_user_ids'] = array_values(array_unique($payload['owner_user_ids']));
    }

    foreach ($payload['owner_user_ids'] as $ownerUserId) {
        $candidate = Database::fetch('SELECT role, is_active FROM users WHERE id = :id LIMIT 1', ['id' => $ownerUserId]);
        if (!$candidate || (int) $candidate['is_active'] !== 1 || !in_array((string) $candidate['role'], ['owner', 'admin'], true)) {
            $errors[] = 'Every storage owner must be an active owner or admin.';
            break;
        }
    }

    foreach ($payload['member_user_ids'] as $memberUserId) {
        if ((int) Database::scalar('SELECT COUNT(*) FROM users WHERE id = :id AND is_active = 1', ['id' => $memberUserId]) !== 1) {
            $errors[] = 'Every assigned storage member must be an active user.';
            break;
        }
    }

    if (active_storage_name_exists($payload['name'])) {
        $errors[] = 'An active location already uses this name.';
    }

    if ($errors !== []) {
        flash_errors($errors);
        redirect('/storages/create');
    }

    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        Database::execute(
            'INSERT INTO storages (name, storage_type, usage_profile, notes, owner_user_id, is_active, created_by, updated_by, created_at, updated_at)
             VALUES (:name, :storage_type, :usage_profile, :notes, :owner_user_id, 1, :created_by, :updated_by, NOW(), NOW())',
            [
                'name' => $payload['name'],
                'storage_type' => $payload['storage_type'],
                'usage_profile' => $payload['usage_profile'],
                'notes' => $payload['notes'] !== '' ? $payload['notes'] : null,
                'owner_user_id' => (int) $payload['owner_user_id'],
                'created_by' => $user['id'],
                'updated_by' => $user['id'],
            ]
        );

        $storageId = Database::lastInsertId();
        sync_storage_assignments(
            $storageId,
            (int) $payload['owner_user_id'],
            $payload['owner_user_ids'],
            $payload['member_user_ids'],
            (int) $user['id']
        );

        if ($copySource !== null) {
            if ($payload['copy_contents_mode'] === 'current_stock') {
                clone_storage_inventory_to_location($copySource, $storageId, $payload['name'], (int) $user['id']);
            } elseif ($payload['copy_contents_mode'] === 'item_setup') {
                clone_storage_item_setup_to_location($copySource, $storageId, (int) $user['id']);
            }
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/storages/create');
    }

    consume_old_input();
    $successMessage = 'Storage created.';

    if ($copySource !== null && $payload['copy_contents_mode'] === 'current_stock') {
        $successMessage = 'Storage created and current stock copied.';
    } elseif ($copySource !== null && $payload['copy_contents_mode'] === 'item_setup') {
        $successMessage = 'Storage created and item setup copied with zero quantity.';
    }

    flash('success', $successMessage);
    redirect($copySource !== null ? '/storages/' . $storageId : '/storages');
}

function handle_storages_edit_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('storages.edit');
    verify_csrf();

    $storage = find_storage_or_abort((int) $params['id']);
    $user = Auth::user();
    if (!user_can_manage_storage((int) $user['id'], (int) $storage['id'])) {
        abort(403, 'Only an assigned storage owner can edit this storage.');
    }
    $payload = [
        'name' => trim((string) input('name')),
        'storage_type' => (string) input('storage_type', 'storage'),
        'usage_profile' => normalize_storage_usage_profile((string) input('usage_profile', (string) ($storage['usage_profile'] ?? 'wristband'))),
        'notes' => trim((string) input('notes')),
        'owner_user_id' => normalize_entity_id(input('owner_user_id')),
        'owner_user_ids' => array_values(array_unique(array_filter(array_map('intval', (array) input('owner_user_ids', []))))),
        'member_user_ids' => array_values(array_unique(array_filter(array_map('intval', (array) input('member_user_ids', []))))),
    ];
    $canAssignUsers = Auth::hasPermission('storages.assign_users');
    if (!$canAssignUsers) {
        $payload['owner_user_id'] = (int) $storage['owner_user_id'];
        $payload['owner_user_ids'] = storage_owner_user_ids((int) $storage['id']);
        $payload['member_user_ids'] = storage_assigned_user_ids((int) $storage['id'], 'member');
    }

    flash_old_input($payload);

    $errors = [];

    if ($payload['name'] === '') {
        $errors[] = 'Storage name is required.';
    }

    if (!in_array($payload['storage_type'], ['warehouse', 'storage'], true)) {
        $errors[] = 'Pick a valid location type.';
    }

    if (!in_array((string) input('usage_profile', (string) ($storage['usage_profile'] ?? 'wristband')), storage_usage_profile_values(), true)) {
        $errors[] = 'Pick a valid usage reporting profile.';
    }

    $ownerRecord = null;

    if (!$payload['owner_user_id']) {
        $errors[] = 'Pick which admin owns this storage.';
    } else {
        $ownerRecord = Database::fetch(
            'SELECT id, role, is_active
             FROM users
             WHERE id = :id
             LIMIT 1',
            ['id' => $payload['owner_user_id']]
        );

        if (!$ownerRecord || (int) ($ownerRecord['is_active'] ?? 0) !== 1 || !in_array((string) ($ownerRecord['role'] ?? ''), ['owner', 'admin'], true)) {
            $errors[] = 'Pick a valid active storage owner.';
        }
    }

    if ($payload['owner_user_id']) {
        $payload['owner_user_ids'][] = (int) $payload['owner_user_id'];
        $payload['owner_user_ids'] = array_values(array_unique($payload['owner_user_ids']));
    }

    foreach ($payload['owner_user_ids'] as $ownerUserId) {
        $candidate = Database::fetch('SELECT role, is_active FROM users WHERE id = :id LIMIT 1', ['id' => $ownerUserId]);
        if (!$candidate || (int) $candidate['is_active'] !== 1 || !in_array((string) $candidate['role'], ['owner', 'admin'], true)) {
            $errors[] = 'Every storage owner must be an active owner or admin.';
            break;
        }
    }

    foreach ($payload['member_user_ids'] as $memberUserId) {
        if ((int) Database::scalar('SELECT COUNT(*) FROM users WHERE id = :id AND is_active = 1', ['id' => $memberUserId]) !== 1) {
            $errors[] = 'Every assigned storage member must be an active user.';
            break;
        }
    }

    if (active_storage_name_exists($payload['name'], (int) $storage['id'])) {
        $errors[] = 'An active location already uses this name.';
    }

    if ($errors !== []) {
        flash_errors($errors);
        redirect('/storages/' . $storage['id'] . '/edit');
    }

    $pdo = Database::connection();
    $pdo->beginTransaction();
    try {
        Database::execute(
            'UPDATE storages
             SET name = :name,
                 storage_type = :storage_type,
                 usage_profile = :usage_profile,
                 notes = :notes,
                 owner_user_id = :owner_user_id,
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'name' => $payload['name'],
                'storage_type' => $payload['storage_type'],
                'usage_profile' => $payload['usage_profile'],
                'notes' => $payload['notes'] !== '' ? $payload['notes'] : null,
                'owner_user_id' => (int) $payload['owner_user_id'],
                'updated_by' => $user['id'],
                'id' => $storage['id'],
            ]
        );
        sync_storage_assignments(
            (int) $storage['id'],
            (int) $payload['owner_user_id'],
            $payload['owner_user_ids'],
            $payload['member_user_ids'],
            (int) $user['id']
        );
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('danger', $exception->getMessage());
        redirect('/storages/' . $storage['id'] . '/edit');
    }

    consume_old_input();
    flash('success', 'Storage updated.');
    redirect('/storages');
}

function handle_storages_status_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('storages.archive');
    verify_csrf();

    $storage = find_storage_or_abort((int) $params['id']);
    $user = Auth::user();
    if (!user_can_manage_storage((int) $user['id'], (int) $storage['id'])) {
        abort(403, 'Only an assigned storage owner can archive or recover this storage.');
    }
    $nextStatus = (int) $storage['is_active'] === 1 ? 0 : 1;

    if ($nextStatus === 0 && (int) $storage['stocked_item_count'] > 0) {
        flash('danger', 'Move or remove the remaining stock in this location before deleting it.');
        redirect('/storages');
    }

    if ($nextStatus === 1 && active_storage_name_exists((string) $storage['name'], (int) $storage['id'])) {
        flash('danger', 'Recover failed. Another active location already uses the name ' . $storage['name'] . '.');
        redirect('/storages?status=archived');
    }

    Database::execute(
        'UPDATE storages SET is_active = :is_active, updated_by = :updated_by, updated_at = NOW() WHERE id = :id',
        [
            'is_active' => $nextStatus,
            'updated_by' => $user['id'],
            'id' => $storage['id'],
        ]
    );

    flash('success', $nextStatus ? 'Storage recovered.' : 'Storage deleted.');
    redirect($nextStatus ? '/storages' : '/storages?status=archived');
}
