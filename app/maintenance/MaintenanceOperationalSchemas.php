<?php
declare(strict_types=1);

trait MaintenanceOperationalSchemas
{
    private static function ensureOperationalSchemas(): void
    {
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
    }
}
