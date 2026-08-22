<?php
declare(strict_types=1);

trait MaintenanceMeasurementSchemas
{
    private static function ensureMeasurementCatalogSchemas(): void
    {
        $measurementColumnAdded = false;
        if (!self::columnExists('items', 'measurement_dimension')) {
            Database::execute('ALTER TABLE items ADD COLUMN measurement_dimension VARCHAR(20) NOT NULL DEFAULT "count" AFTER unit');
            $measurementColumnAdded = true;
        }

        if ($measurementColumnAdded) {
            Database::execute('UPDATE items SET measurement_dimension = "volume" WHERE LOWER(unit) IN ("ml", "milliliter", "milliliters", "l", "liter", "litre")');
            Database::execute('UPDATE items SET measurement_dimension = "mass" WHERE LOWER(unit) IN ("g", "gram", "grams", "kg", "kilogram", "kilograms")');
            Database::execute('UPDATE items SET measurement_dimension = "length" WHERE LOWER(unit) IN ("mm", "millimeter", "millimeters", "cm", "centimeter", "centimeters", "m", "meter", "metre")');
            Database::execute('UPDATE items SET measurement_dimension = "area" WHERE LOWER(unit) IN ("m2", "m²", "sqm")');
        }

        if (!self::columnExists('items', 'usage_proof_policy')) {
            Database::execute('ALTER TABLE items ADD COLUMN usage_proof_policy VARCHAR(20) NOT NULL DEFAULT "inherit" AFTER measurement_dimension');
        }

        if (!self::columnExists('items', 'refill_proof_policy')) {
            Database::execute('ALTER TABLE items ADD COLUMN refill_proof_policy VARCHAR(20) NOT NULL DEFAULT "inherit" AFTER usage_proof_policy');
        }

        if (!self::columnExists('item_package_presets', 'scan_code')) {
            Database::execute('ALTER TABLE item_package_presets ADD COLUMN scan_code VARCHAR(120) NULL AFTER label');
        }

        if (!self::columnExists('item_package_presets', 'is_active')) {
            Database::execute('ALTER TABLE item_package_presets ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER is_default');
        }

        Database::execute('ALTER TABLE item_package_presets MODIFY COLUMN pieces_per_unit DECIMAL(18,6) NOT NULL');
        self::ensureIndexExists('item_package_presets', 'idx_item_package_scan_code', 'CREATE INDEX `idx_item_package_scan_code` ON `item_package_presets` (`scan_code`)');
        self::ensureIndexExists('item_package_presets', 'idx_item_package_active', 'CREATE INDEX `idx_item_package_active` ON `item_package_presets` (`item_id`, `is_active`)');

        Database::execute(
            'CREATE TABLE IF NOT EXISTS departments (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(120) NOT NULL,
                code VARCHAR(40) NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                deleted_at DATETIME NULL,
                created_by BIGINT UNSIGNED NULL,
                updated_by BIGINT UNSIGNED NULL,
                deleted_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uniq_departments_code (code),
                INDEX idx_departments_active (is_active, deleted_at),
                CONSTRAINT fk_departments_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_departments_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_departments_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        if (!self::columnExists('departments', 'deleted_by')) {
            Database::execute('ALTER TABLE departments ADD COLUMN deleted_by BIGINT UNSIGNED NULL AFTER updated_by');
        }

        self::ensureIndexExists('departments', 'idx_departments_deleted_by', 'CREATE INDEX `idx_departments_deleted_by` ON `departments` (`deleted_by`)');
        self::ensureForeignKeyExists('departments', 'fk_departments_deleted_by', 'ALTER TABLE `departments` ADD CONSTRAINT `fk_departments_deleted_by` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL');

        Database::execute(
            'INSERT INTO departments (name, code, is_active, deleted_at, created_by, updated_by, created_at, updated_at)
             SELECT "Unassigned", "UNASSIGNED", 1, NULL, NULL, NULL, NOW(), NOW()
             WHERE NOT EXISTS (SELECT 1 FROM departments WHERE code = "UNASSIGNED")'
        );

        if (!self::columnExists('users', 'department_id')) {
            Database::execute('ALTER TABLE users ADD COLUMN department_id BIGINT UNSIGNED NULL AFTER manager_user_id');
        }

        self::ensureIndexExists('users', 'idx_users_department', 'CREATE INDEX `idx_users_department` ON `users` (`department_id`)');
        self::ensureForeignKeyExists('users', 'fk_users_department', 'ALTER TABLE `users` ADD CONSTRAINT `fk_users_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL');
        Database::execute(
            'UPDATE users
             SET department_id = (SELECT id FROM departments WHERE code = "UNASSIGNED" LIMIT 1)
             WHERE department_id IS NULL'
        );

        foreach (['current_quantity', 'reorder_level'] as $column) {
            Database::execute('ALTER TABLE items MODIFY COLUMN `' . $column . '` DECIMAL(18,6) NOT NULL DEFAULT 0');
        }

        Database::execute('ALTER TABLE item_storage_balances MODIFY COLUMN quantity DECIMAL(18,6) NOT NULL DEFAULT 0');
    }

    private static function ensureMeasurementMovementSchemas(): void
    {
        Database::execute('ALTER TABLE inventory_movements MODIFY COLUMN movement_quantity DECIMAL(18,6) NOT NULL DEFAULT 0');
        Database::execute('ALTER TABLE inventory_movements MODIFY COLUMN quantity_delta DECIMAL(18,6) NOT NULL');
        Database::execute('ALTER TABLE inventory_movements MODIFY COLUMN balance_after DECIMAL(18,6) NOT NULL');

        foreach (['source_balance_after', 'destination_balance_after'] as $column) {
            if (self::columnExists('inventory_movements', $column)) {
                Database::execute('ALTER TABLE inventory_movements MODIFY COLUMN `' . $column . '` DECIMAL(18,6) NULL');
            }
        }

        Database::execute(
            'CREATE TABLE IF NOT EXISTS inventory_movement_measurement_details (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                movement_id BIGINT UNSIGNED NOT NULL,
                input_quantity DECIMAL(18,6) NOT NULL,
                package_preset_id BIGINT UNSIGNED NULL,
                package_label VARCHAR(100) NULL,
                package_scan_code VARCHAR(120) NULL,
                conversion_multiplier DECIMAL(18,6) NOT NULL DEFAULT 1,
                base_quantity DECIMAL(18,6) NOT NULL,
                base_unit VARCHAR(40) NOT NULL,
                measurement_dimension VARCHAR(20) NOT NULL,
                reason_code VARCHAR(40) NULL,
                custom_reason VARCHAR(160) NULL,
                department_id BIGINT UNSIGNED NULL,
                department_name VARCHAR(120) NOT NULL,
                manager_user_id BIGINT UNSIGNED NULL,
                manager_name VARCHAR(120) NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uniq_movement_measurement (movement_id),
                INDEX idx_movement_measurement_department (department_id, created_at),
                INDEX idx_movement_measurement_package (package_preset_id),
                CONSTRAINT fk_movement_measurement_movement FOREIGN KEY (movement_id) REFERENCES inventory_movements(id) ON DELETE CASCADE,
                CONSTRAINT fk_movement_measurement_package FOREIGN KEY (package_preset_id) REFERENCES item_package_presets(id) ON DELETE SET NULL,
                CONSTRAINT fk_movement_measurement_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
                CONSTRAINT fk_movement_measurement_manager FOREIGN KEY (manager_user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        if (!self::columnExists('inventory_movement_measurement_details', 'reason_code')) {
            Database::execute('ALTER TABLE inventory_movement_measurement_details ADD COLUMN reason_code VARCHAR(40) NULL AFTER measurement_dimension');
        }

        if (!self::columnExists('inventory_movement_measurement_details', 'custom_reason')) {
            Database::execute('ALTER TABLE inventory_movement_measurement_details ADD COLUMN custom_reason VARCHAR(160) NULL AFTER reason_code');
        }

        self::ensureIndexExists(
            'inventory_movement_measurement_details',
            'idx_movement_measurement_reason',
            'CREATE INDEX `idx_movement_measurement_reason` ON `inventory_movement_measurement_details` (`reason_code`, `created_at`)'
        );

        Database::execute(
            'CREATE TABLE IF NOT EXISTS inventory_movement_documents (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                movement_id BIGINT UNSIGNED NOT NULL,
                file_asset_id BIGINT UNSIGNED NOT NULL,
                document_role VARCHAR(40) NOT NULL DEFAULT "proof",
                created_at DATETIME NOT NULL,
                UNIQUE KEY uniq_movement_document (movement_id, file_asset_id, document_role),
                INDEX idx_movement_documents_file (file_asset_id),
                CONSTRAINT fk_movement_documents_movement FOREIGN KEY (movement_id) REFERENCES inventory_movements(id) ON DELETE CASCADE,
                CONSTRAINT fk_movement_documents_file FOREIGN KEY (file_asset_id) REFERENCES file_assets(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
}
