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
require_once __DIR__ . '/maintenance/MaintenanceMobileSchemas.php';
require_once __DIR__ . '/maintenance/MaintenanceWristbandSchemas.php';
require_once __DIR__ . '/maintenance/MaintenanceMeasurementSchemas.php';
require_once __DIR__ . '/maintenance/MaintenanceAccessTemplateSchemas.php';
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
    use MaintenanceMobileSchemas;
    use MaintenanceWristbandSchemas;
    use MaintenanceMeasurementSchemas;
    use MaintenanceAccessTemplateSchemas;
    use MaintenanceFileWorkflowSchemas;
    use MaintenanceNotificationSchemas;
    use MaintenanceBackfills;
    use MaintenancePermissionSeeds;

    private const SCHEMA_VERSION = '2026-09-05-position-templates-v1';
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

        if (!self::columnExists('users', 'manager_user_id')) {
            Database::execute('ALTER TABLE users ADD COLUMN manager_user_id BIGINT UNSIGNED NULL AFTER assigned_owner_user_id');
        }

        self::ensureIndexExists('users', 'idx_users_manager', 'CREATE INDEX `idx_users_manager` ON `users` (`manager_user_id`)');
        self::ensureForeignKeyExists('users', 'fk_users_manager', 'ALTER TABLE `users` ADD CONSTRAINT `fk_users_manager` FOREIGN KEY (`manager_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL');
        Database::execute('UPDATE users SET manager_user_id = assigned_owner_user_id WHERE manager_user_id IS NULL AND assigned_owner_user_id IS NOT NULL');

        self::ensurePlatformSchemas();

        self::ensureFileWorkflowDocumentSchemas();

        self::ensureStorageItemSchemas();

        self::ensureMeasurementCatalogSchemas();

        self::ensureAccessTemplateSchemas();

        self::ensureNotificationSchemas();

        self::ensureRequestSchemas();

        self::ensureHandoverSchemas();

        self::ensureWristbandSchemas();

        self::ensurePurchaseSchemas();

        self::ensureAssetSchemas();

        self::ensureOperationalSchemas();

        self::ensureMobileSchemas();

        if (!self::ensureMovementSchemasAndRepairs()) {
            return;
        }

        self::ensureMeasurementMovementSchemas();

        self::seedUserPermissionDefaults();
        self::seedStaffHandoverRequestPermission();
        self::seedAdminPurchasePermissions();
        self::seedAdminOperationalPermissions();
        self::seedSplitMovementPermissions();
        self::seedAdminFilePermissions();
        self::seedEmailLogPermissions();
        self::seedAdminAssetPermissions();
        self::seedHandoverCustodyPermissions();
        self::seedAdminWristbandPermissions();
        self::seedTeamStoragePermissions();
        self::backfillFileAssets();
        self::markSchemaCurrent();
    }

}
