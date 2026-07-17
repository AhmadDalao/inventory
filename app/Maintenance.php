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

        $inventoryMovementsTableExists = (int) Database::scalar(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name',
            ['table_name' => 'inventory_movements']
        );

        if ($inventoryMovementsTableExists === 0) {
            return;
        }

        Database::execute('ALTER TABLE inventory_movements MODIFY COLUMN movement_type ENUM("restock", "usage", "adjustment", "transfer") NOT NULL');

        $movementQuantityColumnExists = (int) Database::scalar(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name',
            [
                'table_name' => 'inventory_movements',
                'column_name' => 'movement_quantity',
            ]
        );

        if ($movementQuantityColumnExists === 0) {
            Database::execute('ALTER TABLE inventory_movements ADD COLUMN movement_quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER movement_type');
        }

        $sourceStorageColumnExists = (int) Database::scalar(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name',
            [
                'table_name' => 'inventory_movements',
                'column_name' => 'source_storage_id',
            ]
        );

        if ($sourceStorageColumnExists === 0) {
            Database::execute('ALTER TABLE inventory_movements ADD COLUMN source_storage_id BIGINT UNSIGNED NULL AFTER balance_after');
        }

        $destinationStorageColumnExists = (int) Database::scalar(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name',
            [
                'table_name' => 'inventory_movements',
                'column_name' => 'destination_storage_id',
            ]
        );

        if ($destinationStorageColumnExists === 0) {
            Database::execute('ALTER TABLE inventory_movements ADD COLUMN destination_storage_id BIGINT UNSIGNED NULL AFTER source_storage_id');
        }

        $sourceBalanceAfterColumnExists = (int) Database::scalar(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name',
            [
                'table_name' => 'inventory_movements',
                'column_name' => 'source_balance_after',
            ]
        );

        if ($sourceBalanceAfterColumnExists === 0) {
            Database::execute('ALTER TABLE inventory_movements ADD COLUMN source_balance_after DECIMAL(12,2) NULL AFTER destination_storage_id');
        }

        $destinationBalanceAfterColumnExists = (int) Database::scalar(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name',
            [
                'table_name' => 'inventory_movements',
                'column_name' => 'destination_balance_after',
            ]
        );

        if ($destinationBalanceAfterColumnExists === 0) {
            Database::execute('ALTER TABLE inventory_movements ADD COLUMN destination_balance_after DECIMAL(12,2) NULL AFTER source_balance_after');
        }

        $contextTypeColumnExists = (int) Database::scalar(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name',
            [
                'table_name' => 'inventory_movements',
                'column_name' => 'context_type',
            ]
        );

        if ($contextTypeColumnExists === 0) {
            Database::execute('ALTER TABLE inventory_movements ADD COLUMN context_type VARCHAR(40) NULL AFTER reference_code');
        }

        $contextIdColumnExists = (int) Database::scalar(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name',
            [
                'table_name' => 'inventory_movements',
                'column_name' => 'context_id',
            ]
        );

        if ($contextIdColumnExists === 0) {
            Database::execute('ALTER TABLE inventory_movements ADD COLUMN context_id BIGINT UNSIGNED NULL AFTER context_type');
        }

        self::ensureIndexExists('inventory_movements', 'idx_movements_context', 'CREATE INDEX `idx_movements_context` ON `inventory_movements` (`context_type`, `context_id`)');

        Database::execute('UPDATE inventory_movements SET movement_quantity = ABS(quantity_delta) WHERE movement_quantity = 0');
        Database::execute(
            'UPDATE inventory_movements m
             INNER JOIN items i ON i.id = m.item_id
             SET m.destination_storage_id = i.storage_id,
                 m.destination_balance_after = m.balance_after
             WHERE m.movement_type = "restock"
               AND m.destination_storage_id IS NULL
               AND i.storage_id IS NOT NULL'
        );
        Database::execute(
            'UPDATE inventory_movements m
             INNER JOIN items i ON i.id = m.item_id
             SET m.source_storage_id = i.storage_id,
                 m.source_balance_after = m.balance_after
             WHERE m.movement_type IN ("usage", "adjustment")
               AND m.source_storage_id IS NULL
               AND i.storage_id IS NOT NULL'
        );

        self::repairMissingStorageBalancesFromMovementHistory();

        $legacyStorageId = null;
        $itemsNeedingBalances = Database::fetchAll(
            'SELECT id, storage_id, current_quantity
             FROM items
             WHERE current_quantity > 0
               AND NOT EXISTS (
                   SELECT 1
                   FROM item_storage_balances balances
                   WHERE balances.item_id = items.id
               )'
        );

        if ($itemsNeedingBalances !== []) {
            $legacyStorage = Database::fetch('SELECT id FROM storages WHERE name = :name LIMIT 1', [
                'name' => 'Unassigned Legacy Stock',
            ]);

            if ($legacyStorage) {
                $legacyStorageId = (int) $legacyStorage['id'];
            } else {
                Database::execute(
                    'INSERT INTO storages (name, storage_type, notes, is_active, created_at, updated_at)
                     VALUES (:name, "warehouse", :notes, 1, NOW(), NOW())',
                    [
                        'name' => 'Unassigned Legacy Stock',
                        'notes' => 'Auto-created to hold stock from the old single-location model.',
                    ]
                );
                $legacyStorageId = Database::lastInsertId();
            }
        }

        foreach ($itemsNeedingBalances as $item) {
            $storageId = $item['storage_id'] ? (int) $item['storage_id'] : $legacyStorageId;

            if (!$storageId) {
                continue;
            }

            Database::execute(
                'INSERT INTO item_storage_balances (item_id, storage_id, quantity, created_at, updated_at)
                 VALUES (:item_id, :storage_id, :quantity, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), updated_at = NOW()',
                [
                    'item_id' => $item['id'],
                    'storage_id' => $storageId,
                    'quantity' => $item['current_quantity'],
                ]
            );

            if (!$item['storage_id']) {
                Database::execute(
                    'UPDATE items SET storage_id = :storage_id, updated_at = NOW() WHERE id = :id',
                    [
                        'storage_id' => $storageId,
                        'id' => $item['id'],
                    ]
                );
            }
        }

        Database::execute(
            'UPDATE items i
             LEFT JOIN (
                 SELECT item_id, COALESCE(SUM(quantity), 0) AS total_quantity
                 FROM item_storage_balances
                 GROUP BY item_id
             ) balances ON balances.item_id = i.id
             SET i.current_quantity = COALESCE(balances.total_quantity, 0)'
        );

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
