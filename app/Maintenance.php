<?php
declare(strict_types=1);

final class Maintenance
{
    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }

        self::$booted = true;
        ensure_directory_exists(item_upload_directory());

        try {
            self::syncSchema();
        } catch (Throwable $exception) {
            return;
        }
    }

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

        Database::execute(
            'CREATE TABLE IF NOT EXISTS storages (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(160) NOT NULL,
                storage_type ENUM("warehouse", "storage") NOT NULL DEFAULT "storage",
                notes TEXT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_by BIGINT UNSIGNED NULL,
                updated_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_storages_name (name),
                INDEX idx_storages_status (is_active),
                CONSTRAINT fk_storages_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_storages_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $storageTypeColumnExists = (int) Database::scalar(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name',
            [
                'table_name' => 'storages',
                'column_name' => 'storage_type',
            ]
        );

        if ($storageTypeColumnExists === 0) {
            Database::execute('ALTER TABLE storages ADD COLUMN storage_type ENUM("warehouse", "storage") NOT NULL DEFAULT "storage" AFTER name');
        }

        $imageColumnExists = (int) Database::scalar(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name',
            [
                'table_name' => 'items',
                'column_name' => 'image_path',
            ]
        );

        if ($imageColumnExists === 0) {
            Database::execute('ALTER TABLE items ADD COLUMN image_path VARCHAR(255) NULL AFTER cost_per_unit');
        }

        $storageColumnExists = (int) Database::scalar(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name',
            [
                'table_name' => 'items',
                'column_name' => 'storage_id',
            ]
        );

        if ($storageColumnExists === 0) {
            Database::execute('ALTER TABLE items ADD COLUMN storage_id BIGINT UNSIGNED NULL AFTER category');
        }

        self::ensureNonUniqueIndex('storages', 'name', 'idx_storages_name');
        self::ensureNonUniqueIndex('items', 'sku', 'idx_items_sku');

        Database::execute(
            'CREATE TABLE IF NOT EXISTS item_storage_balances (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                item_id BIGINT UNSIGNED NOT NULL,
                storage_id BIGINT UNSIGNED NOT NULL,
                quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uniq_item_storage (item_id, storage_id),
                INDEX idx_item_storage_quantity (storage_id, quantity),
                CONSTRAINT fk_item_storage_balances_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE,
                CONSTRAINT fk_item_storage_balances_storage FOREIGN KEY (storage_id) REFERENCES storages(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

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
    }

    private static function ensureNonUniqueIndex(string $table, string $column, string $indexName): void
    {
        $uniqueIndexes = Database::fetchAll(
            'SELECT DISTINCT index_name
             FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
               AND column_name = :column_name
               AND non_unique = 0
               AND index_name != "PRIMARY"',
            [
                'table_name' => $table,
                'column_name' => $column,
            ]
        );

        foreach ($uniqueIndexes as $index) {
            Database::execute('ALTER TABLE `' . $table . '` DROP INDEX `' . $index['index_name'] . '`');
        }

        $indexExists = (int) Database::scalar(
            'SELECT COUNT(*)
             FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
               AND index_name = :index_name',
            [
                'table_name' => $table,
                'index_name' => $indexName,
            ]
        );

        if ($indexExists === 0) {
            Database::execute('CREATE INDEX `' . $indexName . '` ON `' . $table . '` (`' . $column . '`)');
        }
    }
}
