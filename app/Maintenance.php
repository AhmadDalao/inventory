<?php
declare(strict_types=1);

require_once __DIR__ . '/maintenance/MaintenanceBoot.php';
require_once __DIR__ . '/maintenance/MaintenanceSchemaHelpers.php';
require_once __DIR__ . '/maintenance/MaintenanceSchemaState.php';
require_once __DIR__ . '/maintenance/MaintenancePlatformSchemas.php';
require_once __DIR__ . '/maintenance/MaintenanceInventorySchemas.php';
require_once __DIR__ . '/maintenance/MaintenanceRequestSchemas.php';
require_once __DIR__ . '/maintenance/MaintenanceHandoverSchemas.php';
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

        Database::execute(
            'CREATE TABLE IF NOT EXISTS suppliers (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(190) NOT NULL,
                supplier_type ENUM("service", "product", "other") NOT NULL DEFAULT "product",
                supplier_type_other VARCHAR(120) NULL,
                phone VARCHAR(80) NOT NULL DEFAULT "",
                email VARCHAR(190) NULL,
                tax_number VARCHAR(120) NULL,
                commercial_registration VARCHAR(120) NULL,
                national_address VARCHAR(255) NOT NULL DEFAULT "",
                authorized_person VARCHAR(190) NOT NULL DEFAULT "",
                notes TEXT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_by BIGINT UNSIGNED NULL,
                updated_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_suppliers_name (name),
                INDEX idx_suppliers_type (supplier_type),
                INDEX idx_suppliers_status (is_active),
                CONSTRAINT fk_suppliers_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_suppliers_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        if (!self::columnExists('suppliers', 'supplier_type')) {
            Database::execute('ALTER TABLE suppliers ADD COLUMN supplier_type ENUM("service", "product", "other") NOT NULL DEFAULT "product" AFTER name');
        }

        if (!self::columnExists('suppliers', 'supplier_type_other')) {
            Database::execute('ALTER TABLE suppliers ADD COLUMN supplier_type_other VARCHAR(120) NULL AFTER supplier_type');
        }

        if (!self::columnExists('suppliers', 'commercial_registration')) {
            Database::execute('ALTER TABLE suppliers ADD COLUMN commercial_registration VARCHAR(120) NULL AFTER tax_number');
        }

        if (!self::columnExists('suppliers', 'national_address')) {
            Database::execute('ALTER TABLE suppliers ADD COLUMN national_address VARCHAR(255) NOT NULL DEFAULT "" AFTER commercial_registration');
        }

        if (!self::columnExists('suppliers', 'authorized_person')) {
            Database::execute('ALTER TABLE suppliers ADD COLUMN authorized_person VARCHAR(190) NOT NULL DEFAULT "" AFTER national_address');
        }

        self::ensureIndexExists('suppliers', 'idx_suppliers_type', 'CREATE INDEX `idx_suppliers_type` ON `suppliers` (`supplier_type`)');
        Database::execute('UPDATE suppliers SET supplier_type = "product" WHERE supplier_type IS NULL OR supplier_type = ""');
        Database::execute('UPDATE suppliers SET authorized_person = name WHERE authorized_person IS NULL OR authorized_person = ""');
        Database::execute('UPDATE suppliers SET national_address = "Pending national address" WHERE national_address IS NULL OR national_address = ""');

        Database::execute(
            'CREATE TABLE IF NOT EXISTS purchases (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                purchase_number VARCHAR(40) NOT NULL,
                supplier_id BIGINT UNSIGNED NOT NULL,
                destination_storage_id BIGINT UNSIGNED NOT NULL,
                requester_user_id BIGINT UNSIGNED NOT NULL,
                approver_user_id BIGINT UNSIGNED NOT NULL,
                receiver_user_id BIGINT UNSIGNED NULL,
                status ENUM("draft", "pending_approval", "approved", "receipt_review", "completed", "rejected", "cancelled") NOT NULL DEFAULT "draft",
                currency VARCHAR(8) NOT NULL DEFAULT "SAR",
                expected_date DATE NULL,
                notes TEXT NULL,
                decision_notes TEXT NULL,
                receipt_notes TEXT NULL,
                submitted_at DATETIME NULL,
                approved_at DATETIME NULL,
                receipt_reported_at DATETIME NULL,
                completed_at DATETIME NULL,
                rejected_at DATETIME NULL,
                cancelled_at DATETIME NULL,
                approved_by BIGINT UNSIGNED NULL,
                completed_by BIGINT UNSIGNED NULL,
                updated_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uniq_purchases_number (purchase_number),
                INDEX idx_purchases_status (status, created_at),
                INDEX idx_purchases_supplier (supplier_id),
                INDEX idx_purchases_storage (destination_storage_id),
                INDEX idx_purchases_requester (requester_user_id),
                INDEX idx_purchases_approver (approver_user_id),
                CONSTRAINT fk_purchases_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE RESTRICT,
                CONSTRAINT fk_purchases_storage FOREIGN KEY (destination_storage_id) REFERENCES storages(id) ON DELETE RESTRICT,
                CONSTRAINT fk_purchases_requester FOREIGN KEY (requester_user_id) REFERENCES users(id) ON DELETE RESTRICT,
                CONSTRAINT fk_purchases_approver FOREIGN KEY (approver_user_id) REFERENCES users(id) ON DELETE RESTRICT,
                CONSTRAINT fk_purchases_receiver FOREIGN KEY (receiver_user_id) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_purchases_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_purchases_completed_by FOREIGN KEY (completed_by) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_purchases_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        Database::execute(
            'CREATE TABLE IF NOT EXISTS purchase_lines (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                purchase_id BIGINT UNSIGNED NOT NULL,
                item_id BIGINT UNSIGNED NULL,
                item_name VARCHAR(160) NOT NULL,
                item_sku VARCHAR(80) NOT NULL,
                item_barcode VARCHAR(120) NULL,
                item_category VARCHAR(120) NULL,
                unit VARCHAR(40) NOT NULL DEFAULT "pcs",
                item_image_path VARCHAR(255) NULL,
                item_notes TEXT NULL,
                quantity_requested DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                quantity_approved DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                quantity_received DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                quantity_final DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                unit_cost_quoted DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                unit_cost_approved DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_purchase_lines_purchase (purchase_id),
                INDEX idx_purchase_lines_item (item_id),
                CONSTRAINT fk_purchase_lines_purchase FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE CASCADE,
                CONSTRAINT fk_purchase_lines_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        if (!self::columnExists('purchase_lines', 'item_barcode')) {
            Database::execute('ALTER TABLE purchase_lines ADD COLUMN item_barcode VARCHAR(120) NULL AFTER item_sku');
        }

        Database::execute(
            'CREATE TABLE IF NOT EXISTS purchase_documents (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                purchase_id BIGINT UNSIGNED NOT NULL,
                purchase_line_id BIGINT UNSIGNED NULL,
                document_type ENUM("quote", "price_list", "receipt", "proof", "other") NOT NULL DEFAULT "proof",
                original_filename VARCHAR(255) NOT NULL,
                stored_filename VARCHAR(255) NOT NULL,
                mime_type VARCHAR(120) NOT NULL,
                file_size BIGINT UNSIGNED NOT NULL,
                uploaded_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_purchase_documents_purchase (purchase_id),
                INDEX idx_purchase_documents_line (purchase_line_id),
                CONSTRAINT fk_purchase_documents_purchase FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE CASCADE,
                CONSTRAINT fk_purchase_documents_line FOREIGN KEY (purchase_line_id) REFERENCES purchase_lines(id) ON DELETE SET NULL,
                CONSTRAINT fk_purchase_documents_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        Database::execute(
            'CREATE TABLE IF NOT EXISTS purchase_ocr_runs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                purchase_id BIGINT UNSIGNED NULL,
                created_draft_purchase_id BIGINT UNSIGNED NULL,
                source_filename VARCHAR(255) NOT NULL DEFAULT "",
                mime_type VARCHAR(120) NOT NULL DEFAULT "",
                engine VARCHAR(120) NOT NULL DEFAULT "",
                confidence DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
                parsed_line_count INT UNSIGNED NOT NULL DEFAULT 0,
                warnings TEXT NULL,
                text_excerpt TEXT NULL,
                processed_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_purchase_ocr_runs_purchase (purchase_id),
                INDEX idx_purchase_ocr_runs_draft (created_draft_purchase_id),
                INDEX idx_purchase_ocr_runs_processed_by (processed_by, created_at),
                INDEX idx_purchase_ocr_runs_engine (engine, created_at),
                CONSTRAINT fk_purchase_ocr_runs_purchase FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE SET NULL,
                CONSTRAINT fk_purchase_ocr_runs_draft FOREIGN KEY (created_draft_purchase_id) REFERENCES purchases(id) ON DELETE SET NULL,
                CONSTRAINT fk_purchase_ocr_runs_processed_by FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

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

        Database::execute(
            'CREATE TABLE IF NOT EXISTS stocktakes (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                stocktake_number VARCHAR(40) NOT NULL,
                storage_id BIGINT UNSIGNED NOT NULL,
                status ENUM("draft", "pending_approval", "approved", "cancelled") NOT NULL DEFAULT "draft",
                notes TEXT NULL,
                counted_at DATETIME NULL,
                approved_at DATETIME NULL,
                cancelled_at DATETIME NULL,
                created_by BIGINT UNSIGNED NULL,
                approved_by BIGINT UNSIGNED NULL,
                updated_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uniq_stocktakes_number (stocktake_number),
                INDEX idx_stocktakes_storage (storage_id),
                INDEX idx_stocktakes_status (status, created_at),
                CONSTRAINT fk_stocktakes_storage FOREIGN KEY (storage_id) REFERENCES storages(id) ON DELETE RESTRICT,
                CONSTRAINT fk_stocktakes_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_stocktakes_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_stocktakes_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        Database::execute(
            'CREATE TABLE IF NOT EXISTS stocktake_lines (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                stocktake_id BIGINT UNSIGNED NOT NULL,
                item_id BIGINT UNSIGNED NOT NULL,
                item_name VARCHAR(160) NOT NULL,
                item_sku VARCHAR(80) NOT NULL,
                unit VARCHAR(40) NOT NULL,
                expected_quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                counted_quantity DECIMAL(12,2) NULL,
                variance_quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                notes TEXT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_stocktake_lines_stocktake (stocktake_id),
                INDEX idx_stocktake_lines_item (item_id),
                CONSTRAINT fk_stocktake_lines_stocktake FOREIGN KEY (stocktake_id) REFERENCES stocktakes(id) ON DELETE CASCADE,
                CONSTRAINT fk_stocktake_lines_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        Database::execute(
            'CREATE TABLE IF NOT EXISTS activity_logs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NULL,
                action VARCHAR(120) NOT NULL,
                entity_type VARCHAR(80) NULL,
                entity_id BIGINT UNSIGNED NULL,
                summary VARCHAR(255) NOT NULL,
                metadata TEXT NULL,
                ip_address VARCHAR(64) NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_activity_user (user_id, created_at),
                INDEX idx_activity_entity (entity_type, entity_id),
                INDEX idx_activity_action (action),
                CONSTRAINT fk_activity_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
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
