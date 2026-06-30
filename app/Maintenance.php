<?php
declare(strict_types=1);

final class Maintenance
{
    private const SCHEMA_VERSION = '2026-06-30-company-assets-v2';
    private const SCHEMA_VERSION_SETTING_KEY = 'maintenance.schema_version';
    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }

        self::$booted = true;
        ensure_directory_exists(item_upload_directory());
	        ensure_directory_exists(purchase_upload_directory());
	        ensure_directory_exists(workflow_upload_directory());
	        ensure_directory_exists(file_archive_directory());
	        ensure_directory_exists(asset_upload_directory());
	        ensure_directory_exists(asset_document_upload_directory());
	        ensure_directory_exists(brand_logo_upload_directory());

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

        if (self::schemaIsCurrent()) {
            return;
        }

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

        Database::execute(
            'CREATE TABLE IF NOT EXISTS user_permissions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                permission_key VARCHAR(120) NOT NULL,
                created_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uniq_user_permission (user_id, permission_key),
                INDEX idx_user_permissions_key (permission_key),
                CONSTRAINT fk_user_permissions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_user_permissions_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        Database::execute(
            'CREATE TABLE IF NOT EXISTS app_settings (
                setting_key VARCHAR(120) PRIMARY KEY,
                setting_value TEXT NULL,
                updated_by BIGINT UNSIGNED NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_app_settings_updated_by (updated_by),
                CONSTRAINT fk_app_settings_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        Database::execute(
            'CREATE TABLE IF NOT EXISTS login_attempts (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NULL,
                email VARCHAR(190) NOT NULL DEFAULT "",
                ip_address VARCHAR(64) NOT NULL DEFAULT "",
                user_agent VARCHAR(255) NULL,
                success TINYINT(1) NOT NULL DEFAULT 0,
                failure_reason VARCHAR(80) NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_login_attempts_email_time (email, created_at),
                INDEX idx_login_attempts_ip_time (ip_address, created_at),
                INDEX idx_login_attempts_user_time (user_id, created_at),
                CONSTRAINT fk_login_attempts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        Database::execute(
            'CREATE TABLE IF NOT EXISTS password_reset_tokens (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                requested_by_user_id BIGINT UNSIGNED NULL,
                token_hash CHAR(64) NOT NULL,
                request_ip VARCHAR(64) NULL,
                user_agent VARCHAR(255) NULL,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uniq_password_reset_token_hash (token_hash),
                INDEX idx_password_reset_user (user_id, used_at, expires_at),
                INDEX idx_password_reset_requested_by (requested_by_user_id),
                CONSTRAINT fk_password_reset_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_password_reset_tokens_requested_by FOREIGN KEY (requested_by_user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        Database::execute(
            'CREATE TABLE IF NOT EXISTS email_delivery_logs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NULL,
                email_type VARCHAR(80) NOT NULL,
                recipient_email VARCHAR(190) NOT NULL,
                recipient_name VARCHAR(190) NULL,
                subject VARCHAR(190) NOT NULL,
                status ENUM("sent", "failed", "suppressed") NOT NULL DEFAULT "suppressed",
                entity_type VARCHAR(80) NULL,
                entity_id BIGINT UNSIGNED NULL,
                error_message VARCHAR(255) NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_email_logs_user (user_id, created_at),
                INDEX idx_email_logs_type (email_type, created_at),
                INDEX idx_email_logs_entity (entity_type, entity_id),
                INDEX idx_email_logs_status (status, created_at),
                CONSTRAINT fk_email_delivery_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        Database::execute(
            'CREATE TABLE IF NOT EXISTS file_assets (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                source_type VARCHAR(60) NOT NULL,
                source_id BIGINT UNSIGNED NULL,
                context_type VARCHAR(60) NULL,
                context_id BIGINT UNSIGNED NULL,
                display_name VARCHAR(255) NOT NULL,
                original_filename VARCHAR(255) NOT NULL,
                stored_filename VARCHAR(255) NOT NULL,
                relative_path VARCHAR(500) NOT NULL,
                archive_path VARCHAR(500) NULL,
                mime_type VARCHAR(120) NOT NULL,
                file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
                file_group VARCHAR(60) NOT NULL DEFAULT "general",
                uploaded_by BIGINT UNSIGNED NULL,
                deleted_at DATETIME NULL,
                deleted_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uniq_file_assets_path (relative_path),
                INDEX idx_file_assets_source (source_type, source_id),
                INDEX idx_file_assets_context (context_type, context_id),
                INDEX idx_file_assets_group (file_group, created_at),
                INDEX idx_file_assets_uploaded_by (uploaded_by),
                INDEX idx_file_assets_deleted (deleted_at),
                CONSTRAINT fk_file_assets_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_file_assets_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        if (!self::columnExists('file_assets', 'archive_path')) {
            Database::execute('ALTER TABLE file_assets ADD COLUMN archive_path VARCHAR(500) NULL AFTER relative_path');
        }

        Database::execute(
            'CREATE TABLE IF NOT EXISTS workflow_documents (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                workflow_type ENUM("handover", "request") NOT NULL,
                workflow_id BIGINT UNSIGNED NOT NULL,
                document_type ENUM("proof_image", "signoff_pdf", "signoff_excel") NOT NULL DEFAULT "proof_image",
                stage VARCHAR(80) NOT NULL DEFAULT "general",
                original_filename VARCHAR(255) NOT NULL,
                stored_filename VARCHAR(255) NOT NULL,
                mime_type VARCHAR(120) NOT NULL,
                file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
                uploaded_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_workflow_documents_workflow (workflow_type, workflow_id),
                INDEX idx_workflow_documents_type (document_type, stage),
                INDEX idx_workflow_documents_uploaded_by (uploaded_by),
                CONSTRAINT fk_workflow_documents_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        Database::execute('ALTER TABLE workflow_documents MODIFY COLUMN document_type ENUM("proof_image", "signoff_pdf", "signoff_excel") NOT NULL DEFAULT "proof_image"');

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

        Database::execute(
            'CREATE TABLE IF NOT EXISTS notifications (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                actor_user_id BIGINT UNSIGNED NULL,
                notification_type VARCHAR(80) NOT NULL,
                entity_type VARCHAR(40) NULL,
                entity_id BIGINT UNSIGNED NULL,
                title VARCHAR(190) NOT NULL,
                message TEXT NULL,
                action_url VARCHAR(255) NULL,
                read_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_notifications_user (user_id, read_at, created_at),
                INDEX idx_notifications_entity (entity_type, entity_id),
                INDEX idx_notifications_actor (actor_user_id),
                CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $notificationActorColumnExists = (int) Database::scalar(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name',
            [
                'table_name' => 'notifications',
                'column_name' => 'actor_user_id',
            ]
        );

        if ($notificationActorColumnExists === 0) {
            Database::execute('ALTER TABLE notifications ADD COLUMN actor_user_id BIGINT UNSIGNED NULL AFTER user_id');
        }

        self::ensureIndexExists('notifications', 'idx_notifications_actor', 'CREATE INDEX `idx_notifications_actor` ON `notifications` (`actor_user_id`)');

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

        Database::execute(
            'CREATE TABLE IF NOT EXISTS handovers (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                handover_number VARCHAR(40) NOT NULL,
                source_storage_id BIGINT UNSIGNED NOT NULL,
                approver_user_id BIGINT UNSIGNED NULL,
                recipient_name VARCHAR(160) NOT NULL,
                recipient_user_id BIGINT UNSIGNED NULL,
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
                INDEX idx_handovers_recipient_user (recipient_user_id),
                CONSTRAINT fk_handovers_source_storage FOREIGN KEY (source_storage_id) REFERENCES storages(id) ON DELETE RESTRICT,
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
        self::ensureForeignKeyExists('handovers', 'fk_handovers_approver_user', 'ALTER TABLE `handovers` ADD CONSTRAINT `fk_handovers_approver_user` FOREIGN KEY (`approver_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL');
        self::ensureForeignKeyExists('handovers', 'fk_handovers_request_approved_by', 'ALTER TABLE `handovers` ADD CONSTRAINT `fk_handovers_request_approved_by` FOREIGN KEY (`request_approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL');

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
            'CREATE TABLE IF NOT EXISTS company_assets (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                asset_number VARCHAR(40) NOT NULL,
                name VARCHAR(160) NOT NULL,
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
                INDEX idx_company_assets_storage (storage_id),
                INDEX idx_company_assets_assigned_user (assigned_user_id),
                INDEX idx_company_assets_supplier (supplier_id),
                CONSTRAINT fk_company_assets_storage FOREIGN KEY (storage_id) REFERENCES storages(id) ON DELETE SET NULL,
                CONSTRAINT fk_company_assets_assigned_user FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_company_assets_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
                CONSTRAINT fk_company_assets_purchase FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE SET NULL,
                CONSTRAINT fk_company_assets_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_company_assets_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
	        );

	        Database::execute('ALTER TABLE company_assets MODIFY COLUMN status ENUM("available", "pending_receipt", "assigned", "return_requested", "damaged", "maintenance", "lost", "retired") NOT NULL DEFAULT "available"');

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

    private static function repairMissingStorageBalancesFromMovementHistory(): void
    {
        $settingKey = 'maintenance.repair_missing_storage_balances_v1';

        if (self::maintenanceSettingExists($settingKey)) {
            return;
        }

        $rows = Database::fetchAll(
            'SELECT latest.item_id,
                    latest.storage_id,
                    latest.balance_after
             FROM (
                 SELECT events.item_id,
                        events.storage_id,
                        events.balance_after,
                        events.reference_code,
                        ROW_NUMBER() OVER (
                            PARTITION BY events.item_id, events.storage_id
                            ORDER BY events.used_at DESC, events.movement_id DESC
                        ) AS rn
                 FROM (
                     SELECT m.item_id,
                            m.source_storage_id AS storage_id,
                            m.source_balance_after AS balance_after,
                            m.reference_code,
                            m.used_at,
                            m.id AS movement_id
                     FROM inventory_movements m
                     WHERE m.source_storage_id IS NOT NULL
                       AND m.source_balance_after IS NOT NULL

                     UNION ALL

                     SELECT m.item_id,
                            m.destination_storage_id AS storage_id,
                            m.destination_balance_after AS balance_after,
                            m.reference_code,
                            m.used_at,
                            m.id AS movement_id
                     FROM inventory_movements m
                     WHERE m.destination_storage_id IS NOT NULL
                       AND m.destination_balance_after IS NOT NULL
                 ) events
             ) latest
             INNER JOIN items i ON i.id = latest.item_id
             INNER JOIN storages s ON s.id = latest.storage_id
             LEFT JOIN item_storage_balances balances
                 ON balances.item_id = latest.item_id
                AND balances.storage_id = latest.storage_id
             WHERE latest.rn = 1
               AND i.is_active = 1
               AND s.is_active = 1
               AND balances.id IS NULL
               AND latest.balance_after >= 0
               AND COALESCE(latest.reference_code, "") != "REMOVE-LOCATION"'
        );

        foreach ($rows as $row) {
            Database::execute(
                'INSERT INTO item_storage_balances (item_id, storage_id, quantity, created_at, updated_at)
                 VALUES (:item_id, :storage_id, :quantity, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), updated_at = NOW()',
                [
                    'item_id' => (int) $row['item_id'],
                    'storage_id' => (int) $row['storage_id'],
                    'quantity' => round((float) $row['balance_after'], 2),
                ]
            );
        }

        self::setMaintenanceSetting($settingKey, (string) count($rows));
    }

    private static function maintenanceSettingExists(string $settingKey): bool
    {
        return Database::fetch(
            'SELECT setting_key FROM app_settings WHERE setting_key = :setting_key LIMIT 1',
            ['setting_key' => $settingKey]
        ) !== null;
    }

    private static function schemaIsCurrent(): bool
    {
        if (!self::tableExists('app_settings')) {
            return false;
        }

        $currentVersion = Database::scalar(
            'SELECT setting_value
             FROM app_settings
             WHERE setting_key = :setting_key
             LIMIT 1',
            ['setting_key' => self::SCHEMA_VERSION_SETTING_KEY]
        );

        if ((string) $currentVersion !== self::SCHEMA_VERSION) {
            return false;
        }

        return self::userSchemaIsCurrent()
            && self::itemSchemaIsCurrent()
            && self::itemPackageSchemaIsCurrent()
            && self::handoverStatusSchemaIsCurrent()
            && self::handoverUsageSchemaIsCurrent()
            && self::purchaseSchemaIsCurrent()
            && self::supplierSchemaIsCurrent()
            && self::operationalSchemaIsCurrent()
            && self::fileSchemaIsCurrent()
            && self::workflowDocumentSchemaIsCurrent();
    }

    private static function markSchemaCurrent(): void
    {
        if (!self::tableExists('app_settings')) {
            return;
        }

        self::setMaintenanceSetting(self::SCHEMA_VERSION_SETTING_KEY, self::SCHEMA_VERSION);
    }

    private static function handoverStatusSchemaIsCurrent(): bool
    {
        if (!self::tableExists('handovers')) {
            return false;
        }

        $columnType = (string) Database::scalar(
            'SELECT COLUMN_TYPE
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
               AND column_name = :column_name
             LIMIT 1',
            [
                'table_name' => 'handovers',
                'column_name' => 'status',
            ]
        );

        return str_contains($columnType, "'requested'")
            && str_contains($columnType, "'rejected'");
    }

    private static function handoverUsageSchemaIsCurrent(): bool
    {
        return self::tableExists('handover_usage_breakdowns')
            && self::columnExists('handover_usage_breakdowns', 'handover_line_id')
            && self::columnExists('handover_usage_breakdowns', 'reason_code')
            && self::columnExists('handover_usage_breakdowns', 'reason_custom')
            && self::columnExists('handover_usage_breakdowns', 'quantity');
    }

    private static function userSchemaIsCurrent(): bool
    {
        return self::columnExists('users', 'position');
    }

    private static function itemSchemaIsCurrent(): bool
    {
        return self::columnExists('items', 'barcode')
            && self::columnExists('purchase_lines', 'item_barcode');
    }

    private static function itemPackageSchemaIsCurrent(): bool
    {
        return self::tableExists('item_package_presets')
            && self::columnExists('item_package_presets', 'pieces_per_unit')
            && self::columnExists('item_package_presets', 'is_default');
    }

    private static function purchaseSchemaIsCurrent(): bool
    {
        foreach (['suppliers', 'purchases', 'purchase_lines', 'purchase_documents', 'purchase_ocr_runs'] as $tableName) {
            if (!self::tableExists($tableName)) {
                return false;
            }
        }

        $columnType = (string) Database::scalar(
            'SELECT COLUMN_TYPE
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
               AND column_name = :column_name
             LIMIT 1',
            [
                'table_name' => 'purchases',
                'column_name' => 'status',
            ]
        );

        return str_contains($columnType, "'receipt_review'")
            && str_contains($columnType, "'completed'");
    }

    private static function supplierSchemaIsCurrent(): bool
    {
        if (!self::tableExists('suppliers')) {
            return false;
        }

        return self::columnExists('suppliers', 'supplier_type')
            && self::columnExists('suppliers', 'supplier_type_other')
            && self::columnExists('suppliers', 'commercial_registration')
            && self::columnExists('suppliers', 'national_address')
            && self::columnExists('suppliers', 'authorized_person');
    }

    private static function operationalSchemaIsCurrent(): bool
    {
        foreach (['stocktakes', 'stocktake_lines', 'activity_logs', 'login_attempts', 'password_reset_tokens', 'email_delivery_logs'] as $tableName) {
            if (!self::tableExists($tableName)) {
                return false;
            }
        }

        $columnType = (string) Database::scalar(
            'SELECT COLUMN_TYPE
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
               AND column_name = :column_name
             LIMIT 1',
            [
                'table_name' => 'stocktakes',
                'column_name' => 'status',
            ]
        );

        return str_contains($columnType, "'pending_approval'")
            && str_contains($columnType, "'approved'");
    }

    private static function fileSchemaIsCurrent(): bool
    {
        if (!self::tableExists('file_assets')) {
            return false;
        }

        return self::columnExists('file_assets', 'relative_path')
            && self::columnExists('file_assets', 'archive_path')
            && self::columnExists('file_assets', 'deleted_at');
    }

    private static function workflowDocumentSchemaIsCurrent(): bool
    {
        if (!self::tableExists('workflow_documents')) {
            return false;
        }

        $documentType = (string) Database::scalar(
            'SELECT COLUMN_TYPE
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
               AND column_name = :column_name
             LIMIT 1',
            [
                'table_name' => 'workflow_documents',
                'column_name' => 'document_type',
            ]
        );

        return self::tableExists('workflow_documents')
            && self::columnExists('workflow_documents', 'workflow_type')
            && self::columnExists('workflow_documents', 'stage')
            && self::columnExists('workflow_documents', 'stored_filename')
            && str_contains($documentType, "'signoff_excel'");
    }

    private static function setMaintenanceSetting(string $settingKey, string $settingValue): void
    {
        Database::execute(
            'INSERT INTO app_settings (setting_key, setting_value, updated_by, updated_at)
             VALUES (:setting_key, :setting_value, NULL, NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = NULL, updated_at = NOW()',
            [
                'setting_key' => $settingKey,
                'setting_value' => $settingValue,
            ]
        );
    }

    private static function tableExists(string $tableName): bool
    {
        return (int) Database::scalar(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = :table_name',
            ['table_name' => $tableName]
        ) > 0;
    }

    private static function columnExists(string $tableName, string $columnName): bool
    {
        return (int) Database::scalar(
            'SELECT COUNT(*)
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
               AND column_name = :column_name',
            [
                'table_name' => $tableName,
                'column_name' => $columnName,
            ]
        ) > 0;
    }

    private static function ensureNonUniqueIndex(string $table, string $column, string $indexName): void
    {
        $uniqueIndexes = Database::fetchAll(
            'SELECT DISTINCT index_name
             FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
               AND column_name = :column_name
               AND non_unique = 0
               AND index_name != "PRIMARY"',
            [
                'table_name' => $table,
                'column_name' => $column,
            ]
        );

        foreach ($uniqueIndexes as $index) {
            Database::execute('ALTER TABLE `' . $table . '` DROP INDEX `' . $index['index_name'] . '`');
        }

        $indexExists = (int) Database::scalar(
            'SELECT COUNT(*)
             FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
               AND index_name = :index_name',
            [
                'table_name' => $table,
                'index_name' => $indexName,
            ]
        );

        if ($indexExists === 0) {
            Database::execute('CREATE INDEX `' . $indexName . '` ON `' . $table . '` (`' . $column . '`)');
        }
    }

    private static function ensureIndexExists(string $table, string $indexName, string $sql): void
    {
        $indexExists = (int) Database::scalar(
            'SELECT COUNT(*)
             FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
               AND index_name = :index_name',
            [
                'table_name' => $table,
                'index_name' => $indexName,
            ]
        );

        if ($indexExists === 0) {
            Database::execute($sql);
        }
    }

    private static function ensureForeignKeyExists(string $table, string $constraintName, string $sql): void
    {
        $constraintExists = (int) Database::scalar(
            'SELECT COUNT(*)
             FROM information_schema.table_constraints
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
               AND constraint_name = :constraint_name
               AND constraint_type = "FOREIGN KEY"',
            [
                'table_name' => $table,
                'constraint_name' => $constraintName,
            ]
        );

        if ($constraintExists === 0) {
            Database::execute($sql);
        }
    }

    private static function seedUserPermissionDefaults(): void
    {
        $rows = Database::fetchAll(
            'SELECT u.id, u.role
             FROM users u
             WHERE u.role IN ("admin", "staff")
               AND NOT EXISTS (
                   SELECT 1
                   FROM user_permissions permissions
                   WHERE permissions.user_id = u.id
               )'
        );

        foreach ($rows as $row) {
            foreach (default_permissions_for_role((string) $row['role']) as $permission) {
                Database::execute(
                    'INSERT INTO user_permissions (user_id, permission_key, created_by, created_at)
                     VALUES (:user_id, :permission_key, NULL, NOW())
                     ON DUPLICATE KEY UPDATE permission_key = VALUES(permission_key)',
                    [
                        'user_id' => (int) $row['id'],
                        'permission_key' => $permission,
                    ]
                );
            }
        }
    }

    private static function seedStaffHandoverRequestPermission(): void
    {
        $settingKey = 'maintenance.seed_staff_handover_request_permission_v1';

        if (self::maintenanceSettingExists($settingKey)) {
            return;
        }

        $rows = Database::fetchAll(
            'SELECT u.id
             FROM users u
             WHERE u.role = "staff"
               AND u.is_active = 1
               AND EXISTS (
                   SELECT 1
                   FROM user_permissions permissions
                   WHERE permissions.user_id = u.id
                     AND permissions.permission_key = "handovers.view"
               )
               AND NOT EXISTS (
                   SELECT 1
                   FROM user_permissions permissions
                   WHERE permissions.user_id = u.id
                     AND permissions.permission_key = "handovers.request"
               )'
        );

        foreach ($rows as $row) {
            Database::execute(
                'INSERT INTO user_permissions (user_id, permission_key, created_by, created_at)
                 VALUES (:user_id, :permission_key, NULL, NOW())
                 ON DUPLICATE KEY UPDATE permission_key = VALUES(permission_key)',
                [
                    'user_id' => (int) $row['id'],
                    'permission_key' => 'handovers.request',
                ]
            );
        }

        self::setMaintenanceSetting($settingKey, (string) count($rows));
    }

    private static function seedAdminPurchasePermissions(): void
    {
        $settingKey = 'maintenance.seed_admin_purchase_permissions_v1';

        if (self::maintenanceSettingExists($settingKey)) {
            return;
        }

        $permissions = [
            'purchases.view',
            'purchases.create',
            'purchases.receive',
            'purchases.export',
        ];
        $rows = Database::fetchAll('SELECT id FROM users WHERE role = "admin" AND is_active = 1');

        foreach ($rows as $row) {
            foreach ($permissions as $permission) {
                Database::execute(
                    'INSERT INTO user_permissions (user_id, permission_key, created_by, created_at)
                     VALUES (:user_id, :permission_key, NULL, NOW())
                     ON DUPLICATE KEY UPDATE permission_key = VALUES(permission_key)',
                    [
                        'user_id' => (int) $row['id'],
                        'permission_key' => $permission,
                    ]
                );
            }
        }

        self::setMaintenanceSetting($settingKey, (string) (count($rows) * count($permissions)));
    }

    private static function seedAdminOperationalPermissions(): void
    {
        $settingKey = 'maintenance.seed_admin_operational_permissions_v1';

        if (self::maintenanceSettingExists($settingKey)) {
            return;
        }

        $permissions = [
            'stocktakes.view',
            'stocktakes.create',
            'stocktakes.approve',
            'stocktakes.cancel',
            'stocktakes.export',
            'suppliers.view',
            'suppliers.create',
            'suppliers.edit',
            'suppliers.archive',
            'suppliers.export',
            'reorder.view',
            'reorder.create_purchase',
            'reorder.export',
            'labels.view',
            'audit.view',
            'audit.export',
        ];
        $rows = Database::fetchAll('SELECT id FROM users WHERE role = "admin" AND is_active = 1');

        foreach ($rows as $row) {
            foreach ($permissions as $permission) {
                Database::execute(
                    'INSERT INTO user_permissions (user_id, permission_key, created_by, created_at)
                     VALUES (:user_id, :permission_key, NULL, NOW())
                     ON DUPLICATE KEY UPDATE permission_key = VALUES(permission_key)',
                    [
                        'user_id' => (int) $row['id'],
                        'permission_key' => $permission,
                    ]
                );
            }
        }

        self::setMaintenanceSetting($settingKey, (string) (count($rows) * count($permissions)));
    }

    private static function seedAdminFilePermissions(): void
    {
        $settingKey = 'maintenance.seed_admin_file_permissions_v1';

        if (self::maintenanceSettingExists($settingKey)) {
            return;
        }

        $permissions = [
            'files.view',
            'files.download',
            'files.export',
        ];
        $rows = Database::fetchAll(
            'SELECT id
             FROM users
             WHERE is_active = 1
               AND role = "admin"'
        );

        foreach ($rows as $row) {
            foreach ($permissions as $permission) {
                Database::execute(
                    'INSERT INTO user_permissions (user_id, permission_key, created_by, created_at)
                     VALUES (:user_id, :permission_key, NULL, NOW())
                     ON DUPLICATE KEY UPDATE permission_key = VALUES(permission_key)',
                    [
                        'user_id' => (int) $row['id'],
                        'permission_key' => $permission,
                    ]
                );
            }
        }

        self::setMaintenanceSetting($settingKey, (string) (count($rows) * count($permissions)));
    }

    private static function seedSplitMovementPermissions(): void
    {
        $settingKey = 'maintenance.seed_split_movement_permissions_v1';

        if (self::maintenanceSettingExists($settingKey)) {
            return;
        }

        $permissions = [
            'movements.usage',
            'movements.restock',
            'movements.transfer',
            'movements.adjustment',
        ];
        $rows = Database::fetchAll(
            'SELECT DISTINCT u.id
             FROM users u
             INNER JOIN user_permissions existing_permission
                ON existing_permission.user_id = u.id
               AND existing_permission.permission_key = "movements.create"
             WHERE u.is_active = 1
               AND u.role != "owner"'
        );

        foreach ($rows as $row) {
            foreach ($permissions as $permission) {
                Database::execute(
                    'INSERT INTO user_permissions (user_id, permission_key, created_by, created_at)
                     VALUES (:user_id, :permission_key, NULL, NOW())
                     ON DUPLICATE KEY UPDATE permission_key = VALUES(permission_key)',
                    [
                        'user_id' => (int) $row['id'],
                        'permission_key' => $permission,
                    ]
                );
            }
        }

        self::setMaintenanceSetting($settingKey, (string) (count($rows) * count($permissions)));
    }

    private static function seedEmailLogPermissions(): void
    {
        $settingKey = 'maintenance.seed_email_log_permissions_v1';

        if (self::maintenanceSettingExists($settingKey)) {
            return;
        }

        $permissions = [
            'email_logs.view',
            'email_logs.export',
        ];
        $rows = Database::fetchAll(
            'SELECT DISTINCT u.id
             FROM users u
             LEFT JOIN user_permissions audit_view
                ON audit_view.user_id = u.id
               AND audit_view.permission_key = "audit.view"
             LEFT JOIN user_permissions settings_view
                ON settings_view.user_id = u.id
               AND settings_view.permission_key = "settings.view"
             WHERE u.is_active = 1
               AND u.role = "admin"
               AND (audit_view.id IS NOT NULL OR settings_view.id IS NOT NULL)'
        );

        foreach ($rows as $row) {
            foreach ($permissions as $permission) {
                Database::execute(
                    'INSERT INTO user_permissions (user_id, permission_key, created_by, created_at)
                     VALUES (:user_id, :permission_key, NULL, NOW())
                     ON DUPLICATE KEY UPDATE permission_key = VALUES(permission_key)',
                    [
                        'user_id' => (int) $row['id'],
                        'permission_key' => $permission,
                    ]
                );
            }
        }

        self::setMaintenanceSetting($settingKey, (string) (count($rows) * count($permissions)));
    }

    private static function seedAdminAssetPermissions(): void
    {
        $settingKey = 'maintenance.seed_admin_asset_permissions_v1';

        if (self::maintenanceSettingExists($settingKey)) {
            return;
        }

        $permissions = [
            'assets.view',
            'assets.create',
            'assets.edit',
            'assets.assign',
            'assets.maintenance',
            'assets.export',
            'assets.files',
        ];
        $rows = Database::fetchAll('SELECT id FROM users WHERE role = "admin" AND is_active = 1');

        foreach ($rows as $row) {
            foreach ($permissions as $permission) {
                Database::execute(
                    'INSERT INTO user_permissions (user_id, permission_key, created_by, created_at)
                     VALUES (:user_id, :permission_key, NULL, NOW())
                     ON DUPLICATE KEY UPDATE permission_key = VALUES(permission_key)',
                    [
                        'user_id' => (int) $row['id'],
                        'permission_key' => $permission,
                    ]
                );
            }
        }

        self::setMaintenanceSetting($settingKey, (string) (count($rows) * count($permissions)));
    }

    private static function backfillFileAssets(): void
    {
        $settingKey = 'maintenance.backfill_file_assets_v1';

        if (self::maintenanceSettingExists($settingKey)) {
            return;
        }

        $count = 0;

        $purchaseDocuments = Database::fetchAll(
            'SELECT documents.*,
                    purchases.id AS purchase_id,
                    purchases.purchase_number
             FROM purchase_documents documents
             INNER JOIN purchases ON purchases.id = documents.purchase_id
             ORDER BY documents.id ASC'
        );

        foreach ($purchaseDocuments as $document) {
            register_purchase_document_asset(
                (int) $document['id'],
                (int) $document['purchase_id'],
                (string) $document['purchase_number'],
                $document,
                $document['uploaded_by'] !== null ? (int) $document['uploaded_by'] : null,
                (string) $document['created_at']
            );
            $count++;
        }

        $purchaseLineImages = Database::fetchAll(
            'SELECT purchase_line.id,
                    purchase_line.purchase_id,
                    purchase_line.item_name,
                    purchase_line.item_image_path,
                    purchase_line.created_at,
                    purchases.requester_user_id
             FROM purchase_lines purchase_line
             INNER JOIN purchases ON purchases.id = purchase_line.purchase_id
             WHERE COALESCE(purchase_line.item_image_path, "") != ""
             ORDER BY purchase_line.id ASC'
        );

        foreach ($purchaseLineImages as $line) {
            register_purchase_line_image_asset(
                (int) $line['id'],
                (int) $line['purchase_id'],
                (string) $line['item_image_path'],
                (string) $line['item_name'],
                $line['requester_user_id'] !== null ? (int) $line['requester_user_id'] : null,
                (string) $line['created_at']
            );
            $count++;
        }

        $itemImages = Database::fetchAll(
            'SELECT id,
                    name,
                    image_path,
                    created_by,
                    created_at
             FROM items
             WHERE COALESCE(image_path, "") != ""
             ORDER BY id ASC'
        );

        foreach ($itemImages as $item) {
            register_item_image_asset(
                (int) $item['id'],
                (string) $item['image_path'],
                (string) $item['name'],
                $item['created_by'] !== null ? (int) $item['created_by'] : null,
                (string) $item['created_at']
            );
            $count++;
        }

        self::setMaintenanceSetting($settingKey, (string) $count);
    }
}
