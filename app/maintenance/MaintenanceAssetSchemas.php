<?php
declare(strict_types=1);

trait MaintenanceAssetSchemas
{
    private static function ensureAssetSchemas(): void
    {
        Database::execute(
            'CREATE TABLE IF NOT EXISTS asset_categories (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                parent_id BIGINT UNSIGNED NULL,
                name VARCHAR(160) NOT NULL,
                code VARCHAR(40) NULL,
                description TEXT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_by BIGINT UNSIGNED NULL,
                updated_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_asset_categories_parent (parent_id, sort_order),
                INDEX idx_asset_categories_name (name),
                INDEX idx_asset_categories_code (code),
                INDEX idx_asset_categories_active (is_active),
                CONSTRAINT fk_asset_categories_parent FOREIGN KEY (parent_id) REFERENCES asset_categories(id) ON DELETE SET NULL,
                CONSTRAINT fk_asset_categories_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_asset_categories_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        Database::execute(
            'CREATE TABLE IF NOT EXISTS company_assets (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                asset_number VARCHAR(40) NOT NULL,
                name VARCHAR(160) NOT NULL,
                category_id BIGINT UNSIGNED NULL,
                category VARCHAR(120) NULL,
                model VARCHAR(160) NULL,
                serial_number VARCHAR(160) NULL,
                barcode VARCHAR(160) NULL,
                image_path VARCHAR(255) NULL,
                condition_status ENUM("new", "good", "fair", "damaged", "lost", "retired") NOT NULL DEFAULT "good",
                status ENUM("available", "pending_receipt", "assigned", "return_requested", "damaged", "maintenance", "lost", "retired") NOT NULL DEFAULT "available",
                storage_id BIGINT UNSIGNED NULL,
                assigned_user_id BIGINT UNSIGNED NULL,
                supplier_id BIGINT UNSIGNED NULL,
                purchase_id BIGINT UNSIGNED NULL,
                purchase_date DATE NULL,
                purchase_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                depreciation_start_date DATE NULL,
                useful_life_months INT UNSIGNED NOT NULL DEFAULT 60,
                salvage_value DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                depreciation_method ENUM("straight_line") NOT NULL DEFAULT "straight_line",
                warranty_expires_at DATE NULL,
                notes TEXT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_by BIGINT UNSIGNED NULL,
                updated_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uniq_company_assets_number (asset_number),
                UNIQUE KEY uniq_company_assets_barcode (barcode),
                INDEX idx_company_assets_name (name),
                INDEX idx_company_assets_serial (serial_number),
                INDEX idx_company_assets_status (status, is_active),
                INDEX idx_company_assets_category (category_id),
                INDEX idx_company_assets_storage (storage_id),
                INDEX idx_company_assets_assigned_user (assigned_user_id),
                INDEX idx_company_assets_supplier (supplier_id),
                CONSTRAINT fk_company_assets_category FOREIGN KEY (category_id) REFERENCES asset_categories(id) ON DELETE SET NULL,
                CONSTRAINT fk_company_assets_storage FOREIGN KEY (storage_id) REFERENCES storages(id) ON DELETE SET NULL,
                CONSTRAINT fk_company_assets_assigned_user FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_company_assets_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
                CONSTRAINT fk_company_assets_purchase FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE SET NULL,
                CONSTRAINT fk_company_assets_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_company_assets_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        Database::execute('ALTER TABLE company_assets MODIFY COLUMN status ENUM("available", "pending_receipt", "assigned", "return_requested", "damaged", "maintenance", "lost", "retired") NOT NULL DEFAULT "available"');

        if (!self::columnExists('company_assets', 'category_id')) {
            Database::execute('ALTER TABLE company_assets ADD COLUMN category_id BIGINT UNSIGNED NULL AFTER name');
        }

        if (!self::columnExists('company_assets', 'depreciation_start_date')) {
            Database::execute('ALTER TABLE company_assets ADD COLUMN depreciation_start_date DATE NULL AFTER purchase_cost');
        }

        if (!self::columnExists('company_assets', 'useful_life_months')) {
            Database::execute('ALTER TABLE company_assets ADD COLUMN useful_life_months INT UNSIGNED NOT NULL DEFAULT 60 AFTER depreciation_start_date');
        }

        if (!self::columnExists('company_assets', 'salvage_value')) {
            Database::execute('ALTER TABLE company_assets ADD COLUMN salvage_value DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER useful_life_months');
        }

        if (!self::columnExists('company_assets', 'depreciation_method')) {
            Database::execute('ALTER TABLE company_assets ADD COLUMN depreciation_method ENUM("straight_line") NOT NULL DEFAULT "straight_line" AFTER salvage_value');
        }

        self::ensureIndexExists('company_assets', 'idx_company_assets_category', 'CREATE INDEX `idx_company_assets_category` ON `company_assets` (`category_id`)');
        self::ensureForeignKeyExists('company_assets', 'fk_company_assets_category', 'ALTER TABLE `company_assets` ADD CONSTRAINT `fk_company_assets_category` FOREIGN KEY (`category_id`) REFERENCES `asset_categories` (`id`) ON DELETE SET NULL');

        Database::execute(
            'CREATE TABLE IF NOT EXISTS asset_custody_actions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                asset_id BIGINT UNSIGNED NOT NULL,
                action_type ENUM("assign", "receive", "return_request", "return_confirm", "transfer", "damage", "lost", "maintenance_start", "maintenance_complete", "retire", "override") NOT NULL,
                status ENUM("pending", "completed", "cancelled") NOT NULL DEFAULT "pending",
                from_user_id BIGINT UNSIGNED NULL,
                to_user_id BIGINT UNSIGNED NULL,
                from_storage_id BIGINT UNSIGNED NULL,
                to_storage_id BIGINT UNSIGNED NULL,
                condition_before VARCHAR(40) NULL,
                condition_after VARCHAR(40) NULL,
                notes TEXT NULL,
                requested_by BIGINT UNSIGNED NULL,
                confirmed_by BIGINT UNSIGNED NULL,
                requested_at DATETIME NOT NULL,
                confirmed_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_asset_custody_asset (asset_id, requested_at),
                INDEX idx_asset_custody_status (status, action_type),
                INDEX idx_asset_custody_to_user (to_user_id, status),
                CONSTRAINT fk_asset_custody_asset FOREIGN KEY (asset_id) REFERENCES company_assets(id) ON DELETE CASCADE,
                CONSTRAINT fk_asset_custody_from_user FOREIGN KEY (from_user_id) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_asset_custody_to_user FOREIGN KEY (to_user_id) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_asset_custody_from_storage FOREIGN KEY (from_storage_id) REFERENCES storages(id) ON DELETE SET NULL,
                CONSTRAINT fk_asset_custody_to_storage FOREIGN KEY (to_storage_id) REFERENCES storages(id) ON DELETE SET NULL,
                CONSTRAINT fk_asset_custody_requested_by FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_asset_custody_confirmed_by FOREIGN KEY (confirmed_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        Database::execute(
            'CREATE TABLE IF NOT EXISTS asset_events (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                asset_id BIGINT UNSIGNED NOT NULL,
                event_type VARCHAR(80) NOT NULL,
                summary VARCHAR(255) NOT NULL,
                metadata TEXT NULL,
                user_id BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_asset_events_asset (asset_id, created_at),
                INDEX idx_asset_events_type (event_type, created_at),
                CONSTRAINT fk_asset_events_asset FOREIGN KEY (asset_id) REFERENCES company_assets(id) ON DELETE CASCADE,
                CONSTRAINT fk_asset_events_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        Database::execute(
            'CREATE TABLE IF NOT EXISTS asset_maintenance_records (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                asset_id BIGINT UNSIGNED NOT NULL,
                supplier_id BIGINT UNSIGNED NULL,
                title VARCHAR(190) NOT NULL,
                status ENUM("open", "in_progress", "completed", "cancelled") NOT NULL DEFAULT "open",
                due_date DATE NULL,
                completed_at DATETIME NULL,
                cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                notes TEXT NULL,
                created_by BIGINT UNSIGNED NULL,
                updated_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_asset_maintenance_asset (asset_id, status),
                INDEX idx_asset_maintenance_supplier (supplier_id),
                INDEX idx_asset_maintenance_due (due_date, status),
                CONSTRAINT fk_asset_maintenance_asset FOREIGN KEY (asset_id) REFERENCES company_assets(id) ON DELETE CASCADE,
                CONSTRAINT fk_asset_maintenance_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
                CONSTRAINT fk_asset_maintenance_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_asset_maintenance_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
}
