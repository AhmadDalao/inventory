<?php
declare(strict_types=1);

trait MaintenanceRequestSchemas
{
    private static function ensureRequestSchemas(): void
    {
        Database::execute(
            'CREATE TABLE IF NOT EXISTS item_requests (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                request_number VARCHAR(40) NOT NULL,
                requester_user_id BIGINT UNSIGNED NOT NULL,
                approver_user_id BIGINT UNSIGNED NOT NULL,
                source_storage_id BIGINT UNSIGNED NOT NULL,
                destination_storage_id BIGINT UNSIGNED NULL,
                request_mode ENUM("issue", "transfer") NOT NULL DEFAULT "transfer",
                status ENUM("draft", "pending", "approved", "receipt_review", "rejected", "completed", "cancelled") NOT NULL DEFAULT "pending",
                needed_by_date DATE NULL,
                notes TEXT NULL,
                decision_notes TEXT NULL,
                receipt_notes TEXT NULL,
                requested_at DATETIME NOT NULL,
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
                UNIQUE KEY uniq_item_requests_number (request_number),
                INDEX idx_item_requests_status (status, requested_at),
                INDEX idx_item_requests_mode (request_mode),
                INDEX idx_item_requests_requester (requester_user_id),
                INDEX idx_item_requests_approver (approver_user_id),
                INDEX idx_item_requests_source_storage (source_storage_id),
                INDEX idx_item_requests_destination_storage (destination_storage_id),
                CONSTRAINT fk_item_requests_requester FOREIGN KEY (requester_user_id) REFERENCES users(id) ON DELETE RESTRICT,
                CONSTRAINT fk_item_requests_approver FOREIGN KEY (approver_user_id) REFERENCES users(id) ON DELETE RESTRICT,
                CONSTRAINT fk_item_requests_source_storage FOREIGN KEY (source_storage_id) REFERENCES storages(id) ON DELETE RESTRICT,
                CONSTRAINT fk_item_requests_destination_storage FOREIGN KEY (destination_storage_id) REFERENCES storages(id) ON DELETE RESTRICT,
                CONSTRAINT fk_item_requests_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_item_requests_completed_by FOREIGN KEY (completed_by) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_item_requests_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        Database::execute('ALTER TABLE item_requests MODIFY COLUMN destination_storage_id BIGINT UNSIGNED NULL');

        $requestModeColumnExists = (int) Database::scalar(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name',
            [
                'table_name' => 'item_requests',
                'column_name' => 'request_mode',
            ]
        );

        if ($requestModeColumnExists === 0) {
            Database::execute('ALTER TABLE item_requests ADD COLUMN request_mode ENUM("issue", "transfer") NOT NULL DEFAULT "transfer" AFTER destination_storage_id');
        }

        self::ensureIndexExists('item_requests', 'idx_item_requests_mode', 'CREATE INDEX `idx_item_requests_mode` ON `item_requests` (`request_mode`)');
        Database::execute('ALTER TABLE item_requests MODIFY COLUMN status ENUM("draft", "pending", "approved", "receipt_review", "rejected", "completed", "cancelled") NOT NULL DEFAULT "pending"');
        Database::execute('UPDATE item_requests SET request_mode = CASE WHEN destination_storage_id IS NULL THEN "issue" ELSE "transfer" END');

        $receiptNotesColumnExists = (int) Database::scalar(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name',
            [
                'table_name' => 'item_requests',
                'column_name' => 'receipt_notes',
            ]
        );

        if ($receiptNotesColumnExists === 0) {
            Database::execute('ALTER TABLE item_requests ADD COLUMN receipt_notes TEXT NULL AFTER decision_notes');
        }

        $receiptReportedAtColumnExists = (int) Database::scalar(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name',
            [
                'table_name' => 'item_requests',
                'column_name' => 'receipt_reported_at',
            ]
        );

        if ($receiptReportedAtColumnExists === 0) {
            Database::execute('ALTER TABLE item_requests ADD COLUMN receipt_reported_at DATETIME NULL AFTER approved_at');
        }

        Database::execute(
            'CREATE TABLE IF NOT EXISTS item_request_lines (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                request_id BIGINT UNSIGNED NOT NULL,
                item_id BIGINT UNSIGNED NOT NULL,
                item_name VARCHAR(160) NOT NULL,
                item_sku VARCHAR(80) NOT NULL,
                unit VARCHAR(40) NOT NULL,
                quantity_requested DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                quantity_approved DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                quantity_received DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_item_request_lines_request (request_id),
                INDEX idx_item_request_lines_item (item_id),
                CONSTRAINT fk_item_request_lines_request FOREIGN KEY (request_id) REFERENCES item_requests(id) ON DELETE CASCADE,
                CONSTRAINT fk_item_request_lines_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
}
