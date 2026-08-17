<?php
declare(strict_types=1);

trait MaintenancePermissionSeeds
{
    private static function seedUserPermissionDefaults(): void
    {
        $rows = Database::fetchAll(
            'SELECT u.id, u.role
             FROM users u
             WHERE u.role IN ("admin", "staff")
               AND NOT EXISTS (
                   SELECT 1
                   FROM user_permissions permissions
                   WHERE permissions.user_id = u.id
               )'
        );

        foreach ($rows as $row) {
            foreach (default_permissions_for_role((string) $row['role']) as $permission) {
                Database::execute(
                    'INSERT INTO user_permissions (user_id, permission_key, created_by, created_at)
                     VALUES (:user_id, :permission_key, NULL, NOW())
                     ON DUPLICATE KEY UPDATE permission_key = VALUES(permission_key)',
                    [
                        'user_id' => (int) $row['id'],
                        'permission_key' => $permission,
                    ]
                );
            }
        }
    }

    private static function seedStaffHandoverRequestPermission(): void
    {
        $settingKey = 'maintenance.seed_staff_handover_request_permission_v1';

        if (self::maintenanceSettingExists($settingKey)) {
            return;
        }

        $rows = Database::fetchAll(
            'SELECT u.id
             FROM users u
             WHERE u.role = "staff"
               AND u.is_active = 1
               AND EXISTS (
                   SELECT 1
                   FROM user_permissions permissions
                   WHERE permissions.user_id = u.id
                     AND permissions.permission_key = "handovers.view"
               )
               AND NOT EXISTS (
                   SELECT 1
                   FROM user_permissions permissions
                   WHERE permissions.user_id = u.id
                     AND permissions.permission_key = "handovers.request"
               )'
        );

        foreach ($rows as $row) {
            Database::execute(
                'INSERT INTO user_permissions (user_id, permission_key, created_by, created_at)
                 VALUES (:user_id, :permission_key, NULL, NOW())
                 ON DUPLICATE KEY UPDATE permission_key = VALUES(permission_key)',
                [
                    'user_id' => (int) $row['id'],
                    'permission_key' => 'handovers.request',
                ]
            );
        }

        self::setMaintenanceSetting($settingKey, (string) count($rows));
    }

    private static function seedAdminPurchasePermissions(): void
    {
        $settingKey = 'maintenance.seed_admin_purchase_permissions_v1';

        if (self::maintenanceSettingExists($settingKey)) {
            return;
        }

        $permissions = [
            'purchases.view',
            'purchases.create',
            'purchases.receive',
            'purchases.export',
        ];
        $rows = Database::fetchAll('SELECT id FROM users WHERE role = "admin" AND is_active = 1');

        foreach ($rows as $row) {
            foreach ($permissions as $permission) {
                Database::execute(
                    'INSERT INTO user_permissions (user_id, permission_key, created_by, created_at)
                     VALUES (:user_id, :permission_key, NULL, NOW())
                     ON DUPLICATE KEY UPDATE permission_key = VALUES(permission_key)',
                    [
                        'user_id' => (int) $row['id'],
                        'permission_key' => $permission,
                    ]
                );
            }
        }

        self::setMaintenanceSetting($settingKey, (string) (count($rows) * count($permissions)));
    }

    private static function seedAdminOperationalPermissions(): void
    {
        $settingKey = 'maintenance.seed_admin_operational_permissions_v1';

        if (self::maintenanceSettingExists($settingKey)) {
            return;
        }

        $permissions = [
            'stocktakes.view',
            'stocktakes.create',
            'stocktakes.approve',
            'stocktakes.cancel',
            'stocktakes.export',
            'suppliers.view',
            'suppliers.create',
            'suppliers.edit',
            'suppliers.archive',
            'suppliers.export',
            'reorder.view',
            'reorder.create_purchase',
            'reorder.export',
            'labels.view',
            'audit.view',
            'audit.export',
        ];
        $rows = Database::fetchAll('SELECT id FROM users WHERE role = "admin" AND is_active = 1');

        foreach ($rows as $row) {
            foreach ($permissions as $permission) {
                Database::execute(
                    'INSERT INTO user_permissions (user_id, permission_key, created_by, created_at)
                     VALUES (:user_id, :permission_key, NULL, NOW())
                     ON DUPLICATE KEY UPDATE permission_key = VALUES(permission_key)',
                    [
                        'user_id' => (int) $row['id'],
                        'permission_key' => $permission,
                    ]
                );
            }
        }

        self::setMaintenanceSetting($settingKey, (string) (count($rows) * count($permissions)));
    }

    private static function seedAdminFilePermissions(): void
    {
        $settingKey = 'maintenance.seed_admin_file_permissions_v1';

        if (self::maintenanceSettingExists($settingKey)) {
            return;
        }

        $permissions = [
            'files.view',
            'files.download',
            'files.export',
        ];
        $rows = Database::fetchAll(
            'SELECT id
             FROM users
             WHERE is_active = 1
               AND role = "admin"'
        );

        foreach ($rows as $row) {
            foreach ($permissions as $permission) {
                Database::execute(
                    'INSERT INTO user_permissions (user_id, permission_key, created_by, created_at)
                     VALUES (:user_id, :permission_key, NULL, NOW())
                     ON DUPLICATE KEY UPDATE permission_key = VALUES(permission_key)',
                    [
                        'user_id' => (int) $row['id'],
                        'permission_key' => $permission,
                    ]
                );
            }
        }

        self::setMaintenanceSetting($settingKey, (string) (count($rows) * count($permissions)));
    }

    private static function seedSplitMovementPermissions(): void
    {
        $settingKey = 'maintenance.seed_split_movement_permissions_v1';

        if (self::maintenanceSettingExists($settingKey)) {
            return;
        }

        $permissions = [
            'movements.usage',
            'movements.restock',
            'movements.transfer',
            'movements.adjustment',
        ];
        $rows = Database::fetchAll(
            'SELECT DISTINCT u.id
             FROM users u
             INNER JOIN user_permissions existing_permission
                ON existing_permission.user_id = u.id
               AND existing_permission.permission_key = "movements.create"
             WHERE u.is_active = 1
               AND u.role != "owner"'
        );

        foreach ($rows as $row) {
            foreach ($permissions as $permission) {
                Database::execute(
                    'INSERT INTO user_permissions (user_id, permission_key, created_by, created_at)
                     VALUES (:user_id, :permission_key, NULL, NOW())
                     ON DUPLICATE KEY UPDATE permission_key = VALUES(permission_key)',
                    [
                        'user_id' => (int) $row['id'],
                        'permission_key' => $permission,
                    ]
                );
            }
        }

        self::setMaintenanceSetting($settingKey, (string) (count($rows) * count($permissions)));
    }

    private static function seedEmailLogPermissions(): void
    {
        $settingKey = 'maintenance.seed_email_log_permissions_v1';

        if (self::maintenanceSettingExists($settingKey)) {
            return;
        }

        $permissions = [
            'email_logs.view',
            'email_logs.export',
        ];
        $rows = Database::fetchAll(
            'SELECT DISTINCT u.id
             FROM users u
             LEFT JOIN user_permissions audit_view
                ON audit_view.user_id = u.id
               AND audit_view.permission_key = "audit.view"
             LEFT JOIN user_permissions settings_view
                ON settings_view.user_id = u.id
               AND settings_view.permission_key = "settings.view"
             WHERE u.is_active = 1
               AND u.role = "admin"
               AND (audit_view.id IS NOT NULL OR settings_view.id IS NOT NULL)'
        );

        foreach ($rows as $row) {
            foreach ($permissions as $permission) {
                Database::execute(
                    'INSERT INTO user_permissions (user_id, permission_key, created_by, created_at)
                     VALUES (:user_id, :permission_key, NULL, NOW())
                     ON DUPLICATE KEY UPDATE permission_key = VALUES(permission_key)',
                    [
                        'user_id' => (int) $row['id'],
                        'permission_key' => $permission,
                    ]
                );
            }
        }

        self::setMaintenanceSetting($settingKey, (string) (count($rows) * count($permissions)));
    }

    private static function seedAdminAssetPermissions(): void
    {
        $settingKey = 'maintenance.seed_admin_asset_permissions_v2';

        if (self::maintenanceSettingExists($settingKey)) {
            return;
        }

        $permissions = [
            'assets.view',
            'assets.create',
            'assets.edit',
            'assets.categories',
            'assets.assign',
            'assets.maintenance',
            'assets.export',
            'assets.files',
        ];
        $rows = Database::fetchAll('SELECT id FROM users WHERE role = "admin" AND is_active = 1');

        foreach ($rows as $row) {
            foreach ($permissions as $permission) {
                Database::execute(
                    'INSERT INTO user_permissions (user_id, permission_key, created_by, created_at)
                     VALUES (:user_id, :permission_key, NULL, NOW())
                     ON DUPLICATE KEY UPDATE permission_key = VALUES(permission_key)',
                    [
                        'user_id' => (int) $row['id'],
                        'permission_key' => $permission,
                    ]
                );
            }
        }

        self::setMaintenanceSetting($settingKey, (string) (count($rows) * count($permissions)));
    }

    private static function seedHandoverCustodyPermissions(): void
    {
        $settingKey = 'maintenance.seed_handover_custody_permissions_v1';

        if (self::maintenanceSettingExists($settingKey)) {
            return;
        }

        $staffRows = Database::fetchAll(
            'SELECT DISTINCT u.id
             FROM users u
             INNER JOIN user_permissions existing_permission
                ON existing_permission.user_id = u.id
               AND existing_permission.permission_key = "handovers.close"
             WHERE u.is_active = 1
               AND u.role = "staff"'
        );
        $approverRows = Database::fetchAll(
            'SELECT DISTINCT u.id
             FROM users u
             INNER JOIN user_permissions existing_permission
                ON existing_permission.user_id = u.id
               AND existing_permission.permission_key = "handovers.approve"
             WHERE u.is_active = 1
               AND u.role = "admin"'
        );

        foreach ($staffRows as $row) {
            Database::execute(
                'INSERT INTO user_permissions (user_id, permission_key, created_by, created_at)
                 VALUES (:user_id, "handovers.custody_return", NULL, NOW())
                 ON DUPLICATE KEY UPDATE permission_key = VALUES(permission_key)',
                ['user_id' => (int) $row['id']]
            );
        }

        foreach ($approverRows as $row) {
            Database::execute(
                'INSERT INTO user_permissions (user_id, permission_key, created_by, created_at)
                 VALUES (:user_id, "handovers.custody_approve", NULL, NOW())
                 ON DUPLICATE KEY UPDATE permission_key = VALUES(permission_key)',
                ['user_id' => (int) $row['id']]
            );
        }

        self::setMaintenanceSetting($settingKey, (string) (count($staffRows) + count($approverRows)));
    }

    private static function seedTeamStoragePermissions(): void
    {
        $settingKey = 'maintenance.seed_team_storage_permissions_v1';

        if (self::maintenanceSettingExists($settingKey)) {
            return;
        }

        $adminPermissions = [
            'storages.view_all',
            'storages.assign_users',
            'team.view',
            'team.activity.view',
            'team.manage',
        ];
        $staffPermissions = ['storages.view', 'items.view'];
        $granted = 0;

        foreach (Database::fetchAll('SELECT id, role FROM users WHERE is_active = 1 AND role IN ("admin", "staff")') as $row) {
            $permissions = (string) $row['role'] === 'admin' ? $adminPermissions : $staffPermissions;
            foreach ($permissions as $permission) {
                Database::execute(
                    'INSERT INTO user_permissions (user_id, permission_key, created_by, created_at)
                     VALUES (:user_id, :permission_key, NULL, NOW())
                     ON DUPLICATE KEY UPDATE permission_key = VALUES(permission_key)',
                    [
                        'user_id' => (int) $row['id'],
                        'permission_key' => $permission,
                    ]
                );
                $granted++;
            }
        }

        self::setMaintenanceSetting($settingKey, (string) $granted);
    }
}
