<?php
declare(strict_types=1);

function mobile_admin_permission_label_map(): array
{
    $labels = [];
    foreach (permission_catalog() as $group) {
        foreach (($group['permissions'] ?? []) as $key => $copy) {
            $labels[(string) $key] = (string) $copy;
        }
    }

    return $labels;
}

function mobile_admin_permission_label(string $permission, array $labels): string
{
    return $labels[$permission] ?? $permission;
}

function mobile_admin_access_values(array $source): array
{
    return [
        'enabled' => (int) ($source['enabled'] ?? 0),
        'can_usage' => (int) ($source['can_usage'] ?? 0),
        'can_restock' => (int) ($source['can_restock'] ?? 0),
        'can_transfer' => (int) ($source['can_transfer'] ?? 0),
        'can_handover' => (int) ($source['can_handover'] ?? 0),
        'can_custody' => (int) ($source['can_custody'] ?? 0),
        'direct_restock_enabled' => (int) ($source['direct_restock_enabled'] ?? 0),
    ];
}

function mobile_admin_access_values_from_input(): array
{
    return mobile_admin_access_values([
        'enabled' => input('enabled') === '1' ? 1 : 0,
        'can_usage' => input('can_usage') === '1' ? 1 : 0,
        'can_restock' => input('can_restock') === '1' ? 1 : 0,
        'can_transfer' => input('can_transfer') === '1' ? 1 : 0,
        'can_handover' => input('can_handover') === '1' ? 1 : 0,
        'can_custody' => input('can_custody') === '1' ? 1 : 0,
        'direct_restock_enabled' => input('direct_restock_enabled') === '1' ? 1 : 0,
    ]);
}

function mobile_admin_required_permissions(array $user, array $access): array
{
    if (($user['role'] ?? '') === 'owner' || (int) ($access['enabled'] ?? 0) !== 1) {
        return [];
    }

    $required = ['mobile.access', 'storages.view', 'items.view'];
    if ((int) ($access['can_usage'] ?? 0) === 1) {
        $required = array_merge($required, ['movements.view', 'movements.usage']);
    }
    if ((int) ($access['can_restock'] ?? 0) === 1 || (int) ($access['direct_restock_enabled'] ?? 0) === 1) {
        $required = array_merge($required, ['movements.view', 'movements.restock']);
    }
    if ((int) ($access['can_transfer'] ?? 0) === 1) {
        $required = array_merge($required, ['handovers.view', 'handovers.create', 'handovers.close']);
    }
    if ((int) ($access['can_handover'] ?? 0) === 1) {
        $required[] = 'handovers.view';
        $required[] = ($user['role'] ?? '') === 'staff' ? 'handovers.request' : 'handovers.create';
        $required[] = 'handovers.close';
    }
    if ((int) ($access['can_custody'] ?? 0) === 1) {
        $required[] = 'handovers.view';
        if (($user['role'] ?? '') === 'staff') {
            $required[] = 'handovers.custody_return';
        } else {
            $required = array_merge($required, ['handovers.create', 'handovers.custody_approve']);
        }
    }

    return sanitize_permission_input(array_values(array_unique($required)));
}

function mobile_admin_setup_state(array $user, array $assignmentRows): array
{
    $isOwner = ($user['role'] ?? '') === 'owner';
    $access = mobile_admin_access_values($user);
    $enabled = $isOwner || (int) $access['enabled'] === 1;
    $storageIds = array_values(array_unique(array_map('intval', array_column($assignmentRows, 'storage_id'))));
    $requiredPermissions = mobile_admin_required_permissions($user, $access);
    $currentPermissions = Auth::permissionsForUserId((int) $user['id']);
    $missingPermissions = array_values(array_diff($requiredPermissions, $currentPermissions));
    $issues = [];

    if ((int) ($user['is_active'] ?? 0) !== 1) {
        $issues[] = 'Employee account is disabled.';
    }
    if ($enabled && !$isOwner && $storageIds === []) {
        $issues[] = 'Assign at least one storage.';
    }
    if ($enabled && ($user['role'] ?? '') === 'staff' && (int) ($user['manager_user_id'] ?? 0) <= 0) {
        $issues[] = 'Assign an active manager.';
    }
    if ($enabled && !$isOwner && (int) ($user['default_storage_id'] ?? 0) <= 0) {
        $issues[] = 'Choose a default storage.';
    }
    if ($enabled && $missingPermissions !== []) {
        $issues[] = 'Add the required web permissions.';
    }

    return [
        'enabled' => $enabled,
        'ready' => $enabled && $issues === [],
        'issues' => $issues,
        'missing_permissions' => $missingPermissions,
        'required_permissions' => $requiredPermissions,
        'storage_ids' => $storageIds,
    ];
}

function mobile_admin_users(): array
{
    return Database::fetchAll(
        'SELECT users.id, users.name, users.email, users.role, users.position, users.is_active,
                users.manager_user_id, manager.name AS manager_name,
                access.enabled, access.can_usage, access.can_restock, access.can_transfer,
                access.can_handover, access.can_custody, access.direct_restock_enabled,
                COUNT(DISTINCT assignments.storage_id) AS storage_count,
                GROUP_CONCAT(DISTINCT storages.name ORDER BY assignments.is_default DESC, storages.name SEPARATOR ", ") AS storage_names,
                MAX(CASE WHEN assignments.is_default = 1 THEN assignments.storage_id ELSE 0 END) AS default_storage_id,
                COUNT(DISTINCT CASE WHEN devices.revoked_at IS NULL THEN devices.id END) AS active_device_count
         FROM users
         LEFT JOIN users manager ON manager.id = users.manager_user_id
         LEFT JOIN mobile_user_access access ON access.user_id = users.id
         LEFT JOIN user_storage_assignments assignments ON assignments.user_id = users.id
         LEFT JOIN storages ON storages.id = assignments.storage_id
         LEFT JOIN mobile_device_sessions devices ON devices.user_id = users.id
         GROUP BY users.id, users.name, users.email, users.role, users.position, users.is_active,
                  users.manager_user_id, manager.name,
                  access.enabled, access.can_usage, access.can_restock, access.can_transfer,
                  access.can_handover, access.can_custody, access.direct_restock_enabled
         ORDER BY FIELD(users.role, "owner", "admin", "staff"), users.name ASC'
    );
}

function mobile_admin_operations(int $limit = 100): array
{
    return Database::fetchAll(
        'SELECT operations.*, users.name AS user_name, devices.device_name
         FROM mobile_operations operations
         INNER JOIN users ON users.id = operations.user_id
         LEFT JOIN mobile_device_sessions devices ON devices.id = operations.device_session_id
         ORDER BY operations.created_at DESC, operations.id DESC
         LIMIT ' . max(1, min(500, $limit))
    );
}

function mobile_admin_devices(): array
{
    return Database::fetchAll(
        'SELECT devices.*, users.name AS user_name, users.email AS user_email
         FROM mobile_device_sessions devices
         INNER JOIN users ON users.id = devices.user_id
         ORDER BY devices.revoked_at IS NULL DESC, devices.last_seen_at DESC, devices.id DESC'
    );
}

function handle_mobile_admin_page(): void
{
    app_ready_or_redirect();
    Auth::requireOwner();

    $users = mobile_admin_users();
    $assignmentsByUser = [];
    foreach (Database::fetchAll(
        'SELECT assignment.user_id, assignment.storage_id, assignment.access_role, assignment.is_default, storage.name AS storage_name
         FROM user_storage_assignments assignment
         INNER JOIN storages storage ON storage.id = assignment.storage_id AND storage.is_active = 1 AND storage.is_system = 0
         ORDER BY assignment.user_id ASC, assignment.is_default DESC, storage.name ASC'
    ) as $assignment) {
        $assignmentsByUser[(int) $assignment['user_id']][] = $assignment;
    }
    foreach ($users as &$user) {
        $user['mobile_setup'] = mobile_admin_setup_state(
            $user,
            $assignmentsByUser[(int) $user['id']] ?? []
        );
    }
    unset($user);

    View::render('mobile/admin', [
        'title' => 'Mobile Access',
        'users' => $users,
        'storages' => Database::fetchAll('SELECT id, name, storage_type FROM storages WHERE is_active = 1 AND is_system = 0 ORDER BY name ASC'),
        'managers' => Database::fetchAll('SELECT id, name, role, position FROM users WHERE is_active = 1 AND role IN ("owner", "admin") ORDER BY FIELD(role, "owner", "admin"), name ASC'),
        'assignmentsByUser' => $assignmentsByUser,
        'permissionLabels' => mobile_admin_permission_label_map(),
        'devices' => mobile_admin_devices(),
        'operations' => mobile_admin_operations(),
        'usageReasons' => mobile_usage_reason_catalog(false),
        'generalUsageReasons' => usage_reason_catalog_for_profile('general', false),
    ]);
}

function mobile_admin_usage_reason_payload(array $defaults, string $prefix = ''): array
{
    $labels = (array) input($prefix . 'usage_reason_labels', []);
    $sortOrders = (array) input($prefix . 'usage_reason_sort_orders', []);
    $activeReasons = (array) input($prefix . 'usage_reason_active', []);
    $reasons = [];

    foreach ($defaults as $default) {
        $code = (string) $default['code'];
        $label = trim((string) ($labels[$code] ?? $default['label']));
        $reasons[] = [
            'code' => $code,
            'label' => substr($label !== '' ? $label : (string) $default['label'], 0, 60),
            'active' => array_key_exists($code, $activeReasons),
            'sort_order' => max(1, min(999, (int) ($sortOrders[$code] ?? $default['sort_order']))),
        ];
    }

    return $reasons;
}

function handle_mobile_admin_settings_submit(): void
{
    app_ready_or_redirect();
    Auth::requireOwner();
    verify_csrf();

    $usageReasons = mobile_admin_usage_reason_payload(mobile_usage_reason_defaults());
    $generalUsageReasons = mobile_admin_usage_reason_payload(general_usage_reason_defaults(), 'general_');
    if (!array_filter($usageReasons, static fn (array $reason): bool => (bool) $reason['active'])) {
        flash('danger', 'Keep at least one wristband usage reason active.');
        redirect('/mobile-access');
    }
    if (!array_filter($generalUsageReasons, static fn (array $reason): bool => (bool) $reason['active'])) {
        flash('danger', 'Keep at least one general operations reason active.');
        redirect('/mobile-access');
    }

    $settings = [
        'mobile.enabled' => input('enabled') === '1' ? '1' : '0',
        'mobile.manual_restock_enabled' => input('manual_restock_enabled') === '1' ? '1' : '0',
        'mobile.offline_drafts_enabled' => input('offline_drafts_enabled') === '1' ? '1' : '0',
        'mobile.require_usage_proof' => input('require_usage_proof') === '1' ? '1' : '0',
        'mobile.min_supported_version' => substr(trim((string) input('min_supported_version', '1.0.0')), 0, 40) ?: '1.0.0',
        'mobile.usage_reasons' => json_encode($usageReasons, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'mobile.general_usage_reasons' => json_encode($generalUsageReasons, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];
    foreach ($settings as $key => $value) {
        Database::execute(
            'INSERT INTO app_settings (setting_key, setting_value, updated_by, updated_at)
             VALUES (:key, :value, :user_id, NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by), updated_at = NOW()',
            ['key' => $key, 'value' => $value, 'user_id' => Auth::user()['id']]
        );
    }
    site_settings_cache_reset();
    record_activity('mobile.settings_updated', 'mobile_settings', null, 'Updated mobile access settings', $settings);
    flash('success', 'Mobile settings updated.');
    redirect('/mobile-access');
}

function handle_mobile_admin_user_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requireOwner();
    verify_csrf();

    $userId = (int) ($params['id'] ?? 0);
    $user = Database::fetch('SELECT id, name, role, is_active, manager_user_id FROM users WHERE id = :id LIMIT 1', ['id' => $userId]);
    if (!$user) {
        abort(404, 'User not found.');
    }
    $access = mobile_admin_access_values_from_input();
    if ((int) $user['is_active'] !== 1 && (int) $access['enabled'] === 1) {
        flash('danger', 'Enable the employee account before enabling mobile access.');
        redirect('/mobile-access');
    }

    $managerUserId = ($user['role'] ?? '') === 'owner'
        ? null
        : normalize_entity_id(input('manager_user_id'));
    $managerError = manager_assignment_block_reason($userId, $managerUserId);
    if ($managerError !== null) {
        flash('danger', $managerError);
        redirect('/mobile-access');
    }
    if (($user['role'] ?? '') === 'staff' && (int) $access['enabled'] === 1 && $managerUserId === null) {
        flash('danger', 'Assign a manager before enabling mobile access for staff.');
        redirect('/mobile-access');
    }

    $storageIds = array_values(array_unique(array_filter(array_map('intval', (array) input('storage_ids', [])))));
    $ownedStorageIds = array_map('intval', array_column(Database::fetchAll(
        'SELECT storage_id FROM user_storage_assignments WHERE user_id = :user_id AND access_role = "owner"',
        ['user_id' => $userId]
    ), 'storage_id'));
    $storageIds = array_values(array_unique(array_merge($storageIds, $ownedStorageIds)));
    $defaultStorageId = (int) input('default_storage_id', 0);
    if (($user['role'] ?? '') === 'owner') {
        $storageIds = array_map('intval', array_column(Database::fetchAll(
            'SELECT id FROM storages WHERE is_active = 1 AND is_system = 0 ORDER BY name ASC'
        ), 'id'));
        if (!in_array($defaultStorageId, $storageIds, true)) {
            $savedDefaultStorageId = (int) (Database::scalar(
                'SELECT storage_id FROM user_storage_assignments
                 WHERE user_id = :user_id AND is_default = 1
                 ORDER BY access_role = "owner" DESC, id ASC
                 LIMIT 1',
                ['user_id' => $userId]
            ) ?: 0);
            $defaultStorageId = in_array($savedDefaultStorageId, $storageIds, true)
                ? $savedDefaultStorageId
                : (int) ($storageIds[0] ?? 0);
        }
    } elseif ((int) $access['enabled'] === 1) {
        if ($storageIds === []) {
            flash('danger', 'Assign at least one storage before enabling mobile access.');
            redirect('/mobile-access');
        }
        if ($defaultStorageId <= 0) {
            $defaultStorageId = $storageIds[0];
        }
        if (!in_array($defaultStorageId, $storageIds, true)) {
            flash('danger', 'The default storage must be one of the assigned storages.');
            redirect('/mobile-access');
        }
    }

    $requiredPermissions = mobile_admin_required_permissions($user, $access);
    $currentPermissions = Auth::permissionsForUserId($userId);
    $missingPermissions = array_values(array_diff($requiredPermissions, $currentPermissions));
    $autoAddPermissions = input('apply_required_permissions') === '1';
    if ($missingPermissions !== [] && !$autoAddPermissions) {
        flash('danger', 'Required mobile permissions are missing. Keep “Add required permissions automatically” enabled or update the user permissions first.');
        redirect('/mobile-access');
    }
    $permissionsToSave = array_values(array_unique(array_merge($currentPermissions, $autoAddPermissions ? $requiredPermissions : [])));
    sort($currentPermissions);
    sort($permissionsToSave);

    $pdo = Database::connection();
    $pdo->beginTransaction();
    try {
        Database::execute(
            'INSERT INTO mobile_user_access (
                user_id, enabled, can_usage, can_restock, can_transfer, can_handover, can_custody,
                direct_restock_enabled, created_by, updated_by, created_at, updated_at
             ) VALUES (
                :user_id, :enabled, :can_usage, :can_restock, :can_transfer, :can_handover, :can_custody,
                :direct_restock_enabled, :created_by, :updated_by, NOW(), NOW()
             ) ON DUPLICATE KEY UPDATE
                enabled = VALUES(enabled), can_usage = VALUES(can_usage), can_restock = VALUES(can_restock),
                can_transfer = VALUES(can_transfer), can_handover = VALUES(can_handover),
                can_custody = VALUES(can_custody), direct_restock_enabled = VALUES(direct_restock_enabled),
                updated_by = VALUES(updated_by), updated_at = NOW()',
            [
                'user_id' => $userId,
                'enabled' => $access['enabled'],
                'can_usage' => $access['can_usage'],
                'can_restock' => $access['can_restock'],
                'can_transfer' => $access['can_transfer'],
                'can_handover' => $access['can_handover'],
                'can_custody' => $access['can_custody'],
                'direct_restock_enabled' => $access['direct_restock_enabled'],
                'created_by' => Auth::user()['id'],
                'updated_by' => Auth::user()['id'],
            ]
        );
        sync_user_storage_memberships(
            $userId,
            $storageIds,
            $defaultStorageId > 0 ? $defaultStorageId : null,
            (int) Auth::user()['id']
        );
        Database::execute(
            'UPDATE users SET manager_user_id = :manager_user_id, assigned_owner_user_id = :assigned_owner_user_id, updated_at = NOW() WHERE id = :id',
            [
                'manager_user_id' => $managerUserId,
                'assigned_owner_user_id' => $managerUserId,
                'id' => $userId,
            ]
        );
        if (($user['role'] ?? '') !== 'owner' && $permissionsToSave !== $currentPermissions) {
            save_user_permissions($userId, $permissionsToSave, (int) Auth::user()['id']);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('danger', 'Could not update mobile access. ' . $exception->getMessage());
        redirect('/mobile-access');
    }

    if ((int) $access['enabled'] !== 1) {
        Database::execute('UPDATE mobile_device_sessions SET revoked_at = COALESCE(revoked_at, NOW()), revoked_by = :actor_id, updated_at = NOW() WHERE user_id = :user_id', ['actor_id' => Auth::user()['id'], 'user_id' => $userId]);
    }
    record_activity('mobile.user_access_updated', 'user', $userId, 'Updated mobile access for ' . $user['name'], [
        'manager_user_id' => $managerUserId,
        'storage_ids' => $storageIds,
        'default_storage_id' => $defaultStorageId,
        'capabilities' => $access,
        'permissions_added' => $autoAddPermissions ? $missingPermissions : [],
    ]);
    flash('success', 'Mobile access updated for ' . $user['name'] . '.');
    redirect('/mobile-access');
}

function handle_mobile_admin_device_revoke(array $params): void
{
    app_ready_or_redirect();
    Auth::requireOwner();
    verify_csrf();
    $deviceId = (int) ($params['id'] ?? 0);
    Database::execute(
        'UPDATE mobile_device_sessions SET revoked_at = COALESCE(revoked_at, NOW()), revoked_by = :actor_id, updated_at = NOW() WHERE id = :id',
        ['actor_id' => Auth::user()['id'], 'id' => $deviceId]
    );
    record_activity('mobile.device_revoked', 'mobile_device', $deviceId, 'Revoked a mobile device session');
    flash('success', 'Device access revoked.');
    redirect('/mobile-access');
}
