<?php
declare(strict_types=1);

require_once __DIR__ . '/maintenance/MaintenanceBoot.php';
require_once __DIR__ . '/maintenance/MaintenanceSchemaHelpers.php';
require_once __DIR__ . '/maintenance/MaintenanceSchemaState.php';
require_once __DIR__ . '/maintenance/MaintenancePlatformSchemas.php';
require_once __DIR__ . '/maintenance/MaintenanceInventorySchemas.php';
require_once __DIR__ . '/maintenance/MaintenanceRequestSchemas.php';
require_once __DIR__ . '/maintenance/MaintenanceHandoverSchemas.php';
require_once __DIR__ . '/maintenance/MaintenancePurchaseSchemas.php';
require_once __DIR__ . '/maintenance/MaintenanceAssetSchemas.php';
require_once __DIR__ . '/maintenance/MaintenanceOperationalSchemas.php';
require_once __DIR__ . '/maintenance/MaintenanceMovementSchemas.php';
require_once __DIR__ . '/maintenance/MaintenanceFileWorkflowSchemas.php';
require_once __DIR__ . '/maintenance/MaintenanceNotificationSchemas.php';
require_once __DIR__ . '/maintenance/MaintenanceBackfills.php';
require_once __DIR__ . '/maintenance/MaintenancePermissionSeeds.php';

final class Maintenance
{
    use MaintenanceBoot;
    use MaintenanceSchemaHelpers;
    use MaintenanceSchemaState;
    use MaintenancePlatformSchemas;
    use MaintenanceInventorySchemas;
    use MaintenanceRequestSchemas;
    use MaintenanceHandoverSchemas;
    use MaintenancePurchaseSchemas;
    use MaintenanceAssetSchemas;
    use MaintenanceOperationalSchemas;
    use MaintenanceMovementSchemas;
    use MaintenanceFileWorkflowSchemas;
    use MaintenanceNotificationSchemas;
    use MaintenanceBackfills;
    use MaintenancePermissionSeeds;

    private const SCHEMA_VERSION = '2026-07-15-handover-storage-transfer-v1';
    private const SCHEMA_VERSION_SETTING_KEY = 'maintenance.schema_version';
    private static bool $booted = false;

    private static function syncSchema(): void
    {
        $usersTableExists = (int) Database::scalar(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name',
            ['table_name' => 'users']
        );

        $itemsTableExists = (int) Database::scalar(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name',
            ['table_name' => 'items']
        );

        if ($usersTableExists === 0 || $itemsTableExists === 0) {
            return;
        }

        if (self::schemaIsCurrent()) {
            return;
        }

        self::ensureStorageBaseSchema();

        Database::execute('ALTER TABLE users MODIFY COLUMN role ENUM("owner", "admin", "staff") NOT NULL DEFAULT "admin"');

        $positionColumnExists = (int) Database::scalar(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name',
            [
                'table_name' => 'users',
                'column_name' => 'position',
            ]
        );

        if ($positionColumnExists === 0) {
            Database::execute('ALTER TABLE users ADD COLUMN position VARCHAR(80) NULL AFTER role');
        }

        self::ensureIndexExists('users', 'idx_users_position', 'CREATE INDEX `idx_users_position` ON `users` (`position`)');

        Database::execute(
            'UPDATE users
             SET position = CASE
                 WHEN role = "owner" THEN "owner_operator"
                 WHEN role = "admin" THEN "general_admin"
                 ELSE "staff"
             END
             WHERE position IS NULL OR position = ""'
        );

        $assignedOwnerColumnExists = (int) Database::scalar(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name',
            [
                'table_name' => 'users',
                'column_name' => 'assigned_owner_user_id',
            ]
        );

        if ($assignedOwnerColumnExists === 0) {
            Database::execute('ALTER TABLE users ADD COLUMN assigned_owner_user_id BIGINT UNSIGNED NULL AFTER is_active');
        }

        self::ensureIndexExists('users', 'idx_users_assigned_owner', 'CREATE INDEX `idx_users_assigned_owner` ON `users` (`assigned_owner_user_id`)');
        self::ensureForeignKeyExists('users', 'fk_users_assigned_owner', 'ALTER TABLE `users` ADD CONSTRAINT `fk_users_assigned_owner` FOREIGN KEY (`assigned_owner_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL');

        self::ensurePlatformSchemas();

        self::ensureFileWorkflowDocumentSchemas();

        self::ensureStorageItemSchemas();

        self::ensureNotificationSchemas();

        self::ensureRequestSchemas();

        self::ensureHandoverSchemas();

        self::ensurePurchaseSchemas();

        self::ensureAssetSchemas();

        self::ensureOperationalSchemas();

        if (!self::ensureMovementSchemasAndRepairs()) {
            return;
        }

        self::seedUserPermissionDefaults();
        self::seedStaffHandoverRequestPermission();
        self::seedAdminPurchasePermissions();
        self::seedAdminOperationalPermissions();
        self::seedSplitMovementPermissions();
        self::seedAdminFilePermissions();
        self::seedEmailLogPermissions();
        self::seedAdminAssetPermissions();
        self::backfillFileAssets();
        self::markSchemaCurrent();
    }

}
