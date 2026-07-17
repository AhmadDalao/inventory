<?php
declare(strict_types=1);

trait MaintenancePurchaseSchemas
{
    private static function ensurePurchaseSchemas(): void
    {
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
    }
}
