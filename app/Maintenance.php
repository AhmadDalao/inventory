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
                name VARCHAR(160) NOT NULL UNIQUE,
                notes TEXT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_by BIGINT UNSIGNED NULL,
                updated_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_storages_status (is_active),
                CONSTRAINT fk_storages_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_storages_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

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
    }
}
