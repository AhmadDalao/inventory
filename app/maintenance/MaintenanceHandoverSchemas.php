<?php
declare(strict_types=1);

trait MaintenanceHandoverSchemas
{
    private static function ensureHandoverSchemas(): void
    {
        Database::execute(
            'CREATE TABLE IF NOT EXISTS handovers (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                handover_number VARCHAR(40) NOT NULL,
                source_storage_id BIGINT UNSIGNED NOT NULL,
                destination_storage_id BIGINT UNSIGNED NULL,
                approver_user_id BIGINT UNSIGNED NULL,
                recipient_name VARCHAR(160) NOT NULL,
                recipient_user_id BIGINT UNSIGNED NULL,
                recipient_type ENUM("staff", "storage") NOT NULL DEFAULT "staff",
                handover_purpose ENUM("temporary_use", "staff_custody", "storage_transfer") NOT NULL DEFAULT "temporary_use",
                issue_condition VARCHAR(40) NOT NULL DEFAULT "good",
                custody_review_date DATE NULL,
                usage_reporting_mode ENUM("legacy_per_item", "operational_summary") NOT NULL DEFAULT "operational_summary",
                handover_mode ENUM("direct", "request") NOT NULL DEFAULT "direct",
                status ENUM("requested", "awaiting_receipt", "receipt_review", "delivered", "pending_approval", "closed", "rejected", "cancelled") NOT NULL DEFAULT "delivered",
                scheduled_for_date DATE NULL,
                notes TEXT NULL,
                request_decision_notes TEXT NULL,
                receipt_notes TEXT NULL,
                closed_notes TEXT NULL,
                requested_at DATETIME NULL,
                issued_at DATETIME NOT NULL,
                request_approved_at DATETIME NULL,
                request_rejected_at DATETIME NULL,
                receipt_reported_at DATETIME NULL,
                submitted_at DATETIME NULL,
                approved_at DATETIME NULL,
                completed_at DATETIME NULL,
                cancelled_at DATETIME NULL,
                created_by BIGINT UNSIGNED NULL,
                request_approved_by BIGINT UNSIGNED NULL,
                submitted_by BIGINT UNSIGNED NULL,
                approved_by BIGINT UNSIGNED NULL,
                completed_by BIGINT UNSIGNED NULL,
                updated_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uniq_handovers_number (handover_number),
                INDEX idx_handovers_status (status, issued_at),
                INDEX idx_handovers_approver (approver_user_id),
                INDEX idx_handovers_mode (handover_mode),
                INDEX idx_handovers_source_storage (source_storage_id),
                INDEX idx_handovers_destination_storage (destination_storage_id),
                INDEX idx_handovers_recipient_type (recipient_type),
                INDEX idx_handovers_purpose (handover_purpose, status),
                INDEX idx_handovers_recipient_user (recipient_user_id),
                CONSTRAINT fk_handovers_source_storage FOREIGN KEY (source_storage_id) REFERENCES storages(id) ON DELETE RESTRICT,
                CONSTRAINT fk_handovers_destination_storage FOREIGN KEY (destination_storage_id) REFERENCES storages(id) ON DELETE RESTRICT,
                CONSTRAINT fk_handovers_approver_user FOREIGN KEY (approver_user_id) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_handovers_recipient_user FOREIGN KEY (recipient_user_id) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_handovers_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_handovers_request_approved_by FOREIGN KEY (request_approved_by) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_handovers_submitted_by FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_handovers_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_handovers_completed_by FOREIGN KEY (completed_by) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_handovers_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $handoverApproverColumnExists = (int) Database::scalar(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name',
            [
                'table_name' => 'handovers',
                'column_name' => 'approver_user_id',
            ]
        );

        if ($handoverApproverColumnExists === 0) {
            Database::execute('ALTER TABLE handovers ADD COLUMN approver_user_id BIGINT UNSIGNED NULL AFTER source_storage_id');
        }

        if (!self::columnExists('handovers', 'destination_storage_id')) {
            Database::execute('ALTER TABLE handovers ADD COLUMN destination_storage_id BIGINT UNSIGNED NULL AFTER source_storage_id');
        }

        if (!self::columnExists('handovers', 'recipient_type')) {
            Database::execute('ALTER TABLE handovers ADD COLUMN recipient_type ENUM("staff", "storage") NOT NULL DEFAULT "staff" AFTER recipient_user_id');
        }

        if (!self::columnExists('handovers', 'handover_purpose')) {
            Database::execute('ALTER TABLE handovers ADD COLUMN handover_purpose ENUM("temporary_use", "staff_custody", "storage_transfer") NOT NULL DEFAULT "temporary_use" AFTER recipient_type');
        }

        if (!self::columnExists('handovers', 'issue_condition')) {
            Database::execute('ALTER TABLE handovers ADD COLUMN issue_condition VARCHAR(40) NOT NULL DEFAULT "good" AFTER handover_purpose');
        }

        if (!self::columnExists('handovers', 'custody_review_date')) {
            Database::execute('ALTER TABLE handovers ADD COLUMN custody_review_date DATE NULL AFTER issue_condition');
        }

        if (!self::columnExists('handovers', 'usage_reporting_mode')) {
            Database::execute('ALTER TABLE handovers ADD COLUMN usage_reporting_mode ENUM("legacy_per_item", "operational_summary") NOT NULL DEFAULT "legacy_per_item" AFTER custody_review_date');
            Database::execute('UPDATE handovers SET usage_reporting_mode = "legacy_per_item"');
        }

        Database::execute('ALTER TABLE handovers MODIFY COLUMN usage_reporting_mode ENUM("legacy_per_item", "operational_summary") NOT NULL DEFAULT "operational_summary"');

        $handoverModeColumnExists = (int) Database::scalar(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name',
            [
                'table_name' => 'handovers',
                'column_name' => 'handover_mode',
            ]
        );

        if ($handoverModeColumnExists === 0) {
            Database::execute('ALTER TABLE handovers ADD COLUMN handover_mode ENUM("direct", "request") NOT NULL DEFAULT "direct" AFTER recipient_user_id');
        }

        $submittedAtColumnExists = (int) Database::scalar(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name',
            [
                'table_name' => 'handovers',
                'column_name' => 'submitted_at',
            ]
        );

        if ($submittedAtColumnExists === 0) {
            Database::execute('ALTER TABLE handovers ADD COLUMN submitted_at DATETIME NULL AFTER issued_at');
        }

        $receiptNotesColumnExists = (int) Database::scalar(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name',
            [
                'table_name' => 'handovers',
                'column_name' => 'receipt_notes',
            ]
        );

        if ($receiptNotesColumnExists === 0) {
            Database::execute('ALTER TABLE handovers ADD COLUMN receipt_notes TEXT NULL AFTER notes');
        }

        $requestDecisionNotesColumnExists = (int) Database::scalar(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name',
            [
                'table_name' => 'handovers',
                'column_name' => 'request_decision_notes',
            ]
        );

        if ($requestDecisionNotesColumnExists === 0) {
            Database::execute('ALTER TABLE handovers ADD COLUMN request_decision_notes TEXT NULL AFTER notes');
        }

        $requestedAtColumnExists = (int) Database::scalar(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name',
            [
                'table_name' => 'handovers',
                'column_name' => 'requested_at',
            ]
        );

        if ($requestedAtColumnExists === 0) {
            Database::execute('ALTER TABLE handovers ADD COLUMN requested_at DATETIME NULL AFTER closed_notes');
        }

        $requestApprovedAtColumnExists = (int) Database::scalar(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name',
            [
                'table_name' => 'handovers',
                'column_name' => 'request_approved_at',
            ]
        );

        if ($requestApprovedAtColumnExists === 0) {
            Database::execute('ALTER TABLE handovers ADD COLUMN request_approved_at DATETIME NULL AFTER issued_at');
        }

        $requestRejectedAtColumnExists = (int) Database::scalar(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name',
            [
                'table_name' => 'handovers',
                'column_name' => 'request_rejected_at',
            ]
        );

        if ($requestRejectedAtColumnExists === 0) {
            Database::execute('ALTER TABLE handovers ADD COLUMN request_rejected_at DATETIME NULL AFTER request_approved_at');
        }

        $receiptReportedAtColumnExists = (int) Database::scalar(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name',
            [
                'table_name' => 'handovers',
                'column_name' => 'receipt_reported_at',
            ]
        );

        if ($receiptReportedAtColumnExists === 0) {
            Database::execute('ALTER TABLE handovers ADD COLUMN receipt_reported_at DATETIME NULL AFTER issued_at');
        }

        $approvedAtColumnExists = (int) Database::scalar(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name',
            [
                'table_name' => 'handovers',
                'column_name' => 'approved_at',
            ]
        );

        if ($approvedAtColumnExists === 0) {
            Database::execute('ALTER TABLE handovers ADD COLUMN approved_at DATETIME NULL AFTER submitted_at');
        }

        $cancelledAtColumnExists = (int) Database::scalar(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name',
            [
                'table_name' => 'handovers',
                'column_name' => 'cancelled_at',
            ]
        );

        if ($cancelledAtColumnExists === 0) {
            Database::execute('ALTER TABLE handovers ADD COLUMN cancelled_at DATETIME NULL AFTER completed_at');
        }

        $submittedByColumnExists = (int) Database::scalar(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name',
            [
                'table_name' => 'handovers',
                'column_name' => 'submitted_by',
            ]
        );

        if ($submittedByColumnExists === 0) {
            Database::execute('ALTER TABLE handovers ADD COLUMN submitted_by BIGINT UNSIGNED NULL AFTER created_by');
        }

        $approvedByColumnExists = (int) Database::scalar(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name',
            [
                'table_name' => 'handovers',
                'column_name' => 'approved_by',
            ]
        );

        if ($approvedByColumnExists === 0) {
            Database::execute('ALTER TABLE handovers ADD COLUMN approved_by BIGINT UNSIGNED NULL AFTER submitted_by');
        }

        $requestApprovedByColumnExists = (int) Database::scalar(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name',
            [
                'table_name' => 'handovers',
                'column_name' => 'request_approved_by',
            ]
        );

        if ($requestApprovedByColumnExists === 0) {
            Database::execute('ALTER TABLE handovers ADD COLUMN request_approved_by BIGINT UNSIGNED NULL AFTER created_by');
        }

        self::ensureIndexExists('handovers', 'idx_handovers_approver', 'CREATE INDEX `idx_handovers_approver` ON `handovers` (`approver_user_id`)');
        self::ensureIndexExists('handovers', 'idx_handovers_mode', 'CREATE INDEX `idx_handovers_mode` ON `handovers` (`handover_mode`)');
        self::ensureIndexExists('handovers', 'idx_handovers_destination_storage', 'CREATE INDEX `idx_handovers_destination_storage` ON `handovers` (`destination_storage_id`)');
        self::ensureIndexExists('handovers', 'idx_handovers_recipient_type', 'CREATE INDEX `idx_handovers_recipient_type` ON `handovers` (`recipient_type`)');
        self::ensureIndexExists('handovers', 'idx_handovers_purpose', 'CREATE INDEX `idx_handovers_purpose` ON `handovers` (`handover_purpose`, `status`)');
        self::ensureForeignKeyExists('handovers', 'fk_handovers_approver_user', 'ALTER TABLE `handovers` ADD CONSTRAINT `fk_handovers_approver_user` FOREIGN KEY (`approver_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL');
        self::ensureForeignKeyExists('handovers', 'fk_handovers_request_approved_by', 'ALTER TABLE `handovers` ADD CONSTRAINT `fk_handovers_request_approved_by` FOREIGN KEY (`request_approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL');
        self::ensureForeignKeyExists('handovers', 'fk_handovers_destination_storage', 'ALTER TABLE `handovers` ADD CONSTRAINT `fk_handovers_destination_storage` FOREIGN KEY (`destination_storage_id`) REFERENCES `storages` (`id`) ON DELETE RESTRICT');
        Database::execute('UPDATE handovers SET recipient_type = "staff" WHERE recipient_type IS NULL OR recipient_type = ""');
        Database::execute(
            'UPDATE handovers
             SET handover_purpose = CASE
                 WHEN recipient_type = "storage" OR destination_storage_id IS NOT NULL THEN "storage_transfer"
                 ELSE "temporary_use"
             END
             WHERE handover_purpose IS NULL
                OR handover_purpose = ""
                OR (handover_purpose = "temporary_use" AND (recipient_type = "storage" OR destination_storage_id IS NOT NULL))'
        );

        Database::execute('ALTER TABLE handovers MODIFY COLUMN status ENUM("open", "completed", "cancelled", "awaiting_receipt", "receipt_review", "delivered", "pending_approval", "closed", "requested", "rejected") NOT NULL DEFAULT "delivered"');
        Database::execute(
            'UPDATE handovers
             SET status = CASE
                 WHEN status = "open" THEN "delivered"
                 WHEN status = "completed" THEN "closed"
                 WHEN status = "" AND handover_mode = "request" AND request_rejected_at IS NULL AND cancelled_at IS NULL AND request_approved_at IS NULL THEN "requested"
                 WHEN status = "" AND request_rejected_at IS NOT NULL THEN "rejected"
                 WHEN status = "" AND cancelled_at IS NOT NULL THEN "cancelled"
                 WHEN status = "" AND request_approved_at IS NOT NULL AND recipient_user_id IS NOT NULL THEN "awaiting_receipt"
                 WHEN status = "" AND request_approved_at IS NOT NULL AND recipient_user_id IS NULL THEN "delivered"
                 WHEN status = "" THEN "delivered"
                 ELSE status
             END'
        );
        Database::execute('ALTER TABLE handovers MODIFY COLUMN status ENUM("requested", "awaiting_receipt", "receipt_review", "delivered", "pending_approval", "closed", "rejected", "cancelled") NOT NULL DEFAULT "delivered"');
        Database::execute(
            'UPDATE handovers h
             INNER JOIN storages s ON s.id = h.source_storage_id
             SET h.approver_user_id = COALESCE(h.approver_user_id, s.owner_user_id)'
        );

        Database::execute(
            'CREATE TABLE IF NOT EXISTS handover_lines (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                handover_id BIGINT UNSIGNED NOT NULL,
                item_id BIGINT UNSIGNED NOT NULL,
                item_name VARCHAR(160) NOT NULL,
                item_sku VARCHAR(80) NOT NULL,
                unit VARCHAR(40) NOT NULL,
                quantity_handed DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                quantity_received DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                quantity_used DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                quantity_returned DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_handover_lines_handover (handover_id),
                INDEX idx_handover_lines_item (item_id),
                CONSTRAINT fk_handover_lines_handover FOREIGN KEY (handover_id) REFERENCES handovers(id) ON DELETE CASCADE,
                CONSTRAINT fk_handover_lines_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        Database::execute(
            'CREATE TABLE IF NOT EXISTS handover_usage_breakdowns (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                handover_id BIGINT UNSIGNED NOT NULL,
                handover_line_id BIGINT UNSIGNED NOT NULL,
                item_id BIGINT UNSIGNED NOT NULL,
                reason_code VARCHAR(40) NOT NULL DEFAULT "unspecified",
                reason_custom VARCHAR(120) NULL,
                quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                notes VARCHAR(255) NULL,
                created_by BIGINT UNSIGNED NULL,
                updated_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_handover_usage_handover (handover_id),
                INDEX idx_handover_usage_line (handover_line_id),
                INDEX idx_handover_usage_item (item_id),
                INDEX idx_handover_usage_reason (reason_code),
                CONSTRAINT fk_handover_usage_handover FOREIGN KEY (handover_id) REFERENCES handovers(id) ON DELETE CASCADE,
                CONSTRAINT fk_handover_usage_line FOREIGN KEY (handover_line_id) REFERENCES handover_lines(id) ON DELETE CASCADE,
                CONSTRAINT fk_handover_usage_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE RESTRICT,
                CONSTRAINT fk_handover_usage_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_handover_usage_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        Database::execute(
            'CREATE TABLE IF NOT EXISTS handover_expected_usage_breakdowns (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                handover_id BIGINT UNSIGNED NOT NULL,
                handover_line_id BIGINT UNSIGNED NOT NULL,
                item_id BIGINT UNSIGNED NOT NULL,
                reason_code VARCHAR(40) NOT NULL DEFAULT "unspecified",
                reason_custom VARCHAR(120) NULL,
                quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                notes VARCHAR(255) NULL,
                created_by BIGINT UNSIGNED NULL,
                updated_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_handover_expected_usage_handover (handover_id),
                INDEX idx_handover_expected_usage_line (handover_line_id),
                INDEX idx_handover_expected_usage_item (item_id),
                INDEX idx_handover_expected_usage_reason (reason_code),
                CONSTRAINT fk_handover_expected_usage_handover FOREIGN KEY (handover_id) REFERENCES handovers(id) ON DELETE CASCADE,
                CONSTRAINT fk_handover_expected_usage_line FOREIGN KEY (handover_line_id) REFERENCES handover_lines(id) ON DELETE CASCADE,
                CONSTRAINT fk_handover_expected_usage_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE RESTRICT,
                CONSTRAINT fk_handover_expected_usage_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_handover_expected_usage_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        Database::execute(
            'CREATE TABLE IF NOT EXISTS handover_reconciliations (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                handover_id BIGINT UNSIGNED NOT NULL,
                unit VARCHAR(40) NOT NULL DEFAULT "pcs",
                issued_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                received_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                returned_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                physical_used_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                operational_used_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                difference_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                discrepancy_notes TEXT NULL,
                variance_reason_code VARCHAR(40) NULL,
                variance_notes TEXT NULL,
                submitted_by BIGINT UNSIGNED NULL,
                approved_by BIGINT UNSIGNED NULL,
                submitted_at DATETIME NULL,
                approved_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uniq_handover_reconciliation_unit (handover_id, unit),
                INDEX idx_handover_reconciliation_handover (handover_id),
                INDEX idx_handover_reconciliation_submitted_by (submitted_by),
                INDEX idx_handover_reconciliation_approved_by (approved_by),
                CONSTRAINT fk_handover_reconciliation_handover FOREIGN KEY (handover_id) REFERENCES handovers(id) ON DELETE CASCADE,
                CONSTRAINT fk_handover_reconciliation_submitted_by FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_handover_reconciliation_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        Database::execute(
            'CREATE TABLE IF NOT EXISTS handover_reconciliation_entries (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                reconciliation_id BIGINT UNSIGNED NOT NULL,
                reason_code VARCHAR(40) NOT NULL,
                quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                notes VARCHAR(255) NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uniq_handover_reconciliation_reason (reconciliation_id, reason_code),
                INDEX idx_handover_reconciliation_entry_reason (reason_code),
                CONSTRAINT fk_handover_reconciliation_entry_header FOREIGN KEY (reconciliation_id) REFERENCES handover_reconciliations(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        Database::execute(
            'CREATE TABLE IF NOT EXISTS handover_custody_returns (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                handover_id BIGINT UNSIGNED NOT NULL,
                return_number VARCHAR(50) NOT NULL,
                status ENUM("draft", "submitted", "approved", "rejected", "cancelled") NOT NULL DEFAULT "draft",
                return_date DATE NOT NULL,
                notes TEXT NULL,
                rejection_notes TEXT NULL,
                review_notes TEXT NULL,
                submitted_by BIGINT UNSIGNED NULL,
                submitted_at DATETIME NULL,
                reviewed_by BIGINT UNSIGNED NULL,
                reviewed_at DATETIME NULL,
                replacement_handover_id BIGINT UNSIGNED NULL,
                created_by BIGINT UNSIGNED NULL,
                updated_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uniq_handover_custody_return_number (return_number),
                INDEX idx_handover_custody_return_handover (handover_id, status),
                INDEX idx_handover_custody_return_replacement (replacement_handover_id),
                CONSTRAINT fk_handover_custody_return_handover FOREIGN KEY (handover_id) REFERENCES handovers(id) ON DELETE CASCADE,
                CONSTRAINT fk_handover_custody_return_submitted_by FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_handover_custody_return_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_handover_custody_return_replacement FOREIGN KEY (replacement_handover_id) REFERENCES handovers(id) ON DELETE SET NULL,
                CONSTRAINT fk_handover_custody_return_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_handover_custody_return_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        if (!self::columnExists('handover_custody_returns', 'review_notes')) {
            Database::execute('ALTER TABLE handover_custody_returns ADD COLUMN review_notes TEXT NULL AFTER rejection_notes');
        }

        Database::execute(
            'CREATE TABLE IF NOT EXISTS handover_custody_return_lines (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                custody_return_id BIGINT UNSIGNED NOT NULL,
                handover_line_id BIGINT UNSIGNED NOT NULL,
                item_id BIGINT UNSIGNED NOT NULL,
                serviceable_quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                damaged_quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                consumed_quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                lost_quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                notes TEXT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uniq_custody_return_handover_line (custody_return_id, handover_line_id),
                INDEX idx_custody_return_line_item (item_id),
                INDEX idx_custody_return_line_handover_line (handover_line_id),
                CONSTRAINT fk_custody_return_line_return FOREIGN KEY (custody_return_id) REFERENCES handover_custody_returns(id) ON DELETE CASCADE,
                CONSTRAINT fk_custody_return_line_handover_line FOREIGN KEY (handover_line_id) REFERENCES handover_lines(id) ON DELETE RESTRICT,
                CONSTRAINT fk_custody_return_line_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        Database::execute(
            'CREATE TABLE IF NOT EXISTS handover_custody_return_proofs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                custody_return_line_id BIGINT UNSIGNED NOT NULL,
                workflow_document_id BIGINT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uniq_custody_return_proof_document (workflow_document_id),
                INDEX idx_custody_return_proof_line (custody_return_line_id),
                CONSTRAINT fk_custody_return_proof_line FOREIGN KEY (custody_return_line_id) REFERENCES handover_custody_return_lines(id) ON DELETE CASCADE,
                CONSTRAINT fk_custody_return_proof_document FOREIGN KEY (workflow_document_id) REFERENCES workflow_documents(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        Database::execute(
            'CREATE TABLE IF NOT EXISTS handover_quarantine_dispositions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                custody_return_line_id BIGINT UNSIGNED NOT NULL,
                item_id BIGINT UNSIGNED NOT NULL,
                action_type ENUM("return_to_service", "dispose") NOT NULL,
                quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                destination_storage_id BIGINT UNSIGNED NULL,
                reason TEXT NOT NULL,
                performed_by BIGINT UNSIGNED NULL,
                performed_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_quarantine_disposition_line (custody_return_line_id),
                INDEX idx_quarantine_disposition_item (item_id),
                INDEX idx_quarantine_disposition_destination (destination_storage_id),
                CONSTRAINT fk_quarantine_disposition_line FOREIGN KEY (custody_return_line_id) REFERENCES handover_custody_return_lines(id) ON DELETE RESTRICT,
                CONSTRAINT fk_quarantine_disposition_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE RESTRICT,
                CONSTRAINT fk_quarantine_disposition_destination FOREIGN KEY (destination_storage_id) REFERENCES storages(id) ON DELETE RESTRICT,
                CONSTRAINT fk_quarantine_disposition_performed_by FOREIGN KEY (performed_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $handoverReceivedQuantityColumnExists = (int) Database::scalar(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name',
            [
                'table_name' => 'handover_lines',
                'column_name' => 'quantity_received',
            ]
        );

        if ($handoverReceivedQuantityColumnExists === 0) {
            Database::execute('ALTER TABLE handover_lines ADD COLUMN quantity_received DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER quantity_handed');
            Database::execute('UPDATE handover_lines SET quantity_received = quantity_handed');
        }
    }
}
