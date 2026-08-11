<?php
declare(strict_types=1);

function mobile_admin_users(): array
{
    return Database::fetchAll(
        'SELECT users.id, users.name, users.email, users.role, users.position, users.is_active,
                access.enabled, access.can_usage, access.can_restock, access.can_transfer,
                access.can_handover, access.can_custody, access.direct_restock_enabled,
                COUNT(DISTINCT assignments.storage_id) AS storage_count,
                GROUP_CONCAT(DISTINCT storages.name ORDER BY assignments.is_default DESC, storages.name SEPARATOR ", ") AS storage_names,
                MAX(CASE WHEN assignments.is_default = 1 THEN assignments.storage_id ELSE 0 END) AS default_storage_id,
                COUNT(DISTINCT CASE WHEN devices.revoked_at IS NULL THEN devices.id END) AS active_device_count
         FROM users
         LEFT JOIN mobile_user_access access ON access.user_id = users.id
         LEFT JOIN user_storage_assignments assignments ON assignments.user_id = users.id
         LEFT JOIN storages ON storages.id = assignments.storage_id
         LEFT JOIN mobile_device_sessions devices ON devices.user_id = users.id
         GROUP BY users.id, users.name, users.email, users.role, users.position, users.is_active,
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

    View::render('mobile/admin', [
        'title' => 'Mobile Access',
        'users' => mobile_admin_users(),
        'storages' => Database::fetchAll('SELECT id, name, storage_type FROM storages WHERE is_active = 1 AND is_system = 0 ORDER BY name ASC'),
        'devices' => mobile_admin_devices(),
        'operations' => mobile_admin_operations(),
        'usageReasons' => mobile_usage_reason_catalog(false),
    ]);
}

function handle_mobile_admin_settings_submit(): void
{
    app_ready_or_redirect();
    Auth::requireOwner();
    verify_csrf();

    $reasonLabels = (array) input('usage_reason_labels', []);
    $reasonSortOrders = (array) input('usage_reason_sort_orders', []);
    $reasonActive = (array) input('usage_reason_active', []);
    $usageReasons = [];
    foreach (mobile_usage_reason_defaults() as $default) {
        $code = (string) $default['code'];
        $label = trim((string) ($reasonLabels[$code] ?? $default['label']));
        $usageReasons[] = [
            'code' => $code,
            'label' => substr($label !== '' ? $label : (string) $default['label'], 0, 60),
            'active' => array_key_exists($code, $reasonActive),
            'sort_order' => max(1, min(999, (int) ($reasonSortOrders[$code] ?? $default['sort_order']))),
        ];
    }
    if (!array_filter($usageReasons, static fn (array $reason): bool => (bool) $reason['active'])) {
        flash('danger', 'Keep at least one mobile usage reason active.');
        redirect('/mobile-access');
    }

    $settings = [
        'mobile.enabled' => input('enabled') === '1' ? '1' : '0',
        'mobile.manual_restock_enabled' => input('manual_restock_enabled') === '1' ? '1' : '0',
        'mobile.offline_drafts_enabled' => input('offline_drafts_enabled') === '1' ? '1' : '0',
        'mobile.require_usage_proof' => input('require_usage_proof') === '1' ? '1' : '0',
        'mobile.min_supported_version' => substr(trim((string) input('min_supported_version', '1.0.0')), 0, 40) ?: '1.0.0',
        'mobile.usage_reasons' => json_encode($usageReasons, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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
    $user = Database::fetch('SELECT id, name, role, is_active FROM users WHERE id = :id LIMIT 1', ['id' => $userId]);
    if (!$user) {
        abort(404, 'User not found.');
    }
    $storageIds = array_values(array_unique(array_filter(array_map('intval', (array) input('storage_ids', [])))));
    $defaultStorageId = (int) input('default_storage_id', 0);
    if ($defaultStorageId > 0 && !in_array($defaultStorageId, $storageIds, true)) {
        $storageIds[] = $defaultStorageId;
    }

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
                'enabled' => input('enabled') === '1' ? 1 : 0,
                'can_usage' => input('can_usage') === '1' ? 1 : 0,
                'can_restock' => input('can_restock') === '1' ? 1 : 0,
                'can_transfer' => input('can_transfer') === '1' ? 1 : 0,
                'can_handover' => input('can_handover') === '1' ? 1 : 0,
                'can_custody' => input('can_custody') === '1' ? 1 : 0,
                'direct_restock_enabled' => input('direct_restock_enabled') === '1' ? 1 : 0,
                'created_by' => Auth::user()['id'],
                'updated_by' => Auth::user()['id'],
            ]
        );
        Database::execute('DELETE FROM user_storage_assignments WHERE user_id = :user_id', ['user_id' => $userId]);
        foreach ($storageIds as $storageId) {
            $exists = Database::scalar('SELECT COUNT(*) FROM storages WHERE id = :id AND is_active = 1 AND is_system = 0', ['id' => $storageId]);
            if ((int) $exists !== 1) {
                throw new RuntimeException('One selected storage is unavailable.');
            }
            Database::execute(
                'INSERT INTO user_storage_assignments (user_id, storage_id, is_default, created_by, created_at, updated_at)
                 VALUES (:user_id, :storage_id, :is_default, :actor_id, NOW(), NOW())',
                ['user_id' => $userId, 'storage_id' => $storageId, 'is_default' => $storageId === $defaultStorageId ? 1 : 0, 'actor_id' => Auth::user()['id']]
            );
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('danger', 'Could not update mobile access. ' . $exception->getMessage());
        redirect('/mobile-access');
    }

    if (input('enabled') !== '1') {
        Database::execute('UPDATE mobile_device_sessions SET revoked_at = COALESCE(revoked_at, NOW()), revoked_by = :actor_id, updated_at = NOW() WHERE user_id = :user_id', ['actor_id' => Auth::user()['id'], 'user_id' => $userId]);
    }
    record_activity('mobile.user_access_updated', 'user', $userId, 'Updated mobile access for ' . $user['name'], ['storage_ids' => $storageIds, 'default_storage_id' => $defaultStorageId]);
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
