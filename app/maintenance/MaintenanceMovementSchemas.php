<?php
declare(strict_types=1);

trait MaintenanceMovementSchemas
{
    private static function ensureMovementSchemasAndRepairs(): bool
    {
        $inventoryMovementsTableExists = (int) Database::scalar(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name',
            ['table_name' => 'inventory_movements']
        );

        if ($inventoryMovementsTableExists === 0) {
            return false;
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
        self::ensureLegacyStorageBalances();
        self::syncItemQuantitiesFromStorageBalances();

        return true;
    }

    private static function ensureLegacyStorageBalances(): void
    {
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
    }

    private static function syncItemQuantitiesFromStorageBalances(): void
    {
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
}
