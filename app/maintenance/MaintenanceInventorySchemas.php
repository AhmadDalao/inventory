<?php
declare(strict_types=1);

trait MaintenanceInventorySchemas
{
    private static function ensureStorageBaseSchema(): void
    {
        Database::execute(
            'CREATE TABLE IF NOT EXISTS storages (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(160) NOT NULL,
                system_key VARCHAR(80) NULL,
                storage_type ENUM("warehouse", "storage") NOT NULL DEFAULT "storage",
                notes TEXT NULL,
                is_system TINYINT(1) NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                owner_user_id BIGINT UNSIGNED NULL,
                created_by BIGINT UNSIGNED NULL,
                updated_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uniq_storages_system_key (system_key),
                INDEX idx_storages_name (name),
                INDEX idx_storages_system (is_system),
                INDEX idx_storages_status (is_active),
                INDEX idx_storages_owner (owner_user_id),
                CONSTRAINT fk_storages_owner_user FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_storages_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_storages_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private static function ensureStorageItemSchemas(): void
    {
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

        $storageSystemKeyColumnExists = (int) Database::scalar(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name',
            [
                'table_name' => 'storages',
                'column_name' => 'system_key',
            ]
        );

        if ($storageSystemKeyColumnExists === 0) {
            Database::execute('ALTER TABLE storages ADD COLUMN system_key VARCHAR(80) NULL AFTER name');
        }

        $storageIsSystemColumnExists = (int) Database::scalar(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name',
            [
                'table_name' => 'storages',
                'column_name' => 'is_system',
            ]
        );

        if ($storageIsSystemColumnExists === 0) {
            Database::execute('ALTER TABLE storages ADD COLUMN is_system TINYINT(1) NOT NULL DEFAULT 0 AFTER notes');
        }

        $storageOwnerColumnExists = (int) Database::scalar(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name',
            [
                'table_name' => 'storages',
                'column_name' => 'owner_user_id',
            ]
        );

        if ($storageOwnerColumnExists === 0) {
            Database::execute('ALTER TABLE storages ADD COLUMN owner_user_id BIGINT UNSIGNED NULL AFTER is_active');
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

        if (!self::columnExists('items', 'barcode')) {
            Database::execute('ALTER TABLE items ADD COLUMN barcode VARCHAR(120) NULL AFTER sku');
        }

        self::ensureNonUniqueIndex('storages', 'name', 'idx_storages_name');
        self::ensureNonUniqueIndex('items', 'sku', 'idx_items_sku');
        self::ensureIndexExists('items', 'idx_items_barcode', 'CREATE INDEX `idx_items_barcode` ON `items` (`barcode`)');
        self::ensureIndexExists('storages', 'idx_storages_system', 'CREATE INDEX `idx_storages_system` ON `storages` (`is_system`)');
        self::ensureIndexExists('storages', 'uniq_storages_system_key', 'CREATE UNIQUE INDEX `uniq_storages_system_key` ON `storages` (`system_key`)');
        self::ensureIndexExists('storages', 'idx_storages_owner', 'CREATE INDEX `idx_storages_owner` ON `storages` (`owner_user_id`)');

        Database::execute(
            'CREATE TABLE IF NOT EXISTS item_package_presets (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                item_id BIGINT UNSIGNED NOT NULL,
                label VARCHAR(60) NOT NULL,
                pieces_per_unit DECIMAL(12,2) NOT NULL DEFAULT 1.00,
                is_default TINYINT(1) NOT NULL DEFAULT 0,
                created_by BIGINT UNSIGNED NULL,
                updated_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uniq_item_package_label (item_id, label),
                INDEX idx_item_package_default (item_id, is_default),
                INDEX idx_item_package_created_by (created_by),
                INDEX idx_item_package_updated_by (updated_by),
                CONSTRAINT fk_item_package_presets_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE,
                CONSTRAINT fk_item_package_presets_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_item_package_presets_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $fallbackOwnerId = Database::scalar(
            'SELECT id
             FROM users
             WHERE role = "owner"
             ORDER BY id ASC
             LIMIT 1'
        );

        if ($fallbackOwnerId) {
            Database::execute(
                'UPDATE storages
                 SET owner_user_id = COALESCE(owner_user_id, created_by, :owner_user_id)
                 WHERE is_system = 0',
                ['owner_user_id' => (int) $fallbackOwnerId]
            );
        }

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
    }
}
