<?php
declare(strict_types=1);

trait MaintenanceMobileSchemas
{
    private static function ensureMobileSchemas(): void
    {
        Database::execute(
            'CREATE TABLE IF NOT EXISTS mobile_user_access (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 0,
                can_usage TINYINT(1) NOT NULL DEFAULT 0,
                can_restock TINYINT(1) NOT NULL DEFAULT 0,
                can_transfer TINYINT(1) NOT NULL DEFAULT 0,
                can_handover TINYINT(1) NOT NULL DEFAULT 0,
                can_custody TINYINT(1) NOT NULL DEFAULT 0,
                direct_restock_enabled TINYINT(1) NOT NULL DEFAULT 0,
                created_by BIGINT UNSIGNED NULL,
                updated_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uniq_mobile_user_access (user_id),
                INDEX idx_mobile_user_access_enabled (enabled, user_id),
                CONSTRAINT fk_mobile_access_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_mobile_access_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_mobile_access_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        Database::execute(
            'CREATE TABLE IF NOT EXISTS user_storage_assignments (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                storage_id BIGINT UNSIGNED NOT NULL,
                access_role ENUM("member", "owner") NOT NULL DEFAULT "member",
                is_default TINYINT(1) NOT NULL DEFAULT 0,
                created_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uniq_mobile_user_storage (user_id, storage_id),
                INDEX idx_mobile_assignment_storage (storage_id, user_id),
                INDEX idx_storage_assignment_role (storage_id, access_role, user_id),
                CONSTRAINT fk_mobile_assignment_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_mobile_assignment_storage FOREIGN KEY (storage_id) REFERENCES storages(id) ON DELETE CASCADE,
                CONSTRAINT fk_mobile_assignment_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        if (!self::columnExists('user_storage_assignments', 'access_role')) {
            Database::execute('ALTER TABLE user_storage_assignments ADD COLUMN access_role ENUM("member", "owner") NOT NULL DEFAULT "member" AFTER storage_id');
        }
        self::ensureIndexExists(
            'user_storage_assignments',
            'idx_storage_assignment_role',
            'CREATE INDEX `idx_storage_assignment_role` ON `user_storage_assignments` (`storage_id`, `access_role`, `user_id`)'
        );
        Database::execute(
            'INSERT INTO user_storage_assignments (user_id, storage_id, access_role, is_default, created_by, created_at, updated_at)
             SELECT storage.owner_user_id, storage.id, "owner", 0, storage.updated_by, NOW(), NOW()
             FROM storages storage
             WHERE storage.owner_user_id IS NOT NULL
               AND storage.is_system = 0
             ON DUPLICATE KEY UPDATE access_role = "owner", updated_at = NOW()'
        );

        Database::execute(
            'CREATE TABLE IF NOT EXISTS mobile_device_sessions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                device_uuid VARCHAR(120) NOT NULL,
                device_name VARCHAR(160) NULL,
                platform ENUM("android", "ios", "unknown") NOT NULL DEFAULT "unknown",
                app_version VARCHAR(40) NOT NULL,
                access_token_hash CHAR(64) NOT NULL,
                access_expires_at DATETIME NOT NULL,
                refresh_token_hash CHAR(64) NOT NULL,
                refresh_expires_at DATETIME NOT NULL,
                last_seen_at DATETIME NULL,
                last_ip VARCHAR(64) NULL,
                revoked_at DATETIME NULL,
                revoked_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uniq_mobile_access_token (access_token_hash),
                UNIQUE KEY uniq_mobile_refresh_token (refresh_token_hash),
                INDEX idx_mobile_device_user (user_id, revoked_at),
                INDEX idx_mobile_device_uuid (device_uuid, user_id),
                CONSTRAINT fk_mobile_device_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_mobile_device_revoker FOREIGN KEY (revoked_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        Database::execute(
            'CREATE TABLE IF NOT EXISTS mobile_operations (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                client_operation_id VARCHAR(80) NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                device_session_id BIGINT UNSIGNED NOT NULL,
                operation_type VARCHAR(80) NOT NULL,
                entity_type VARCHAR(80) NULL,
                entity_id BIGINT UNSIGNED NULL,
                storage_id BIGINT UNSIGNED NULL,
                manager_user_id BIGINT UNSIGNED NULL,
                status ENUM("pending", "succeeded", "failed", "conflict") NOT NULL DEFAULT "pending",
                request_json MEDIUMTEXT NULL,
                response_json MEDIUMTEXT NULL,
                error_code VARCHAR(80) NULL,
                error_message VARCHAR(255) NULL,
                ip_address VARCHAR(64) NULL,
                app_version VARCHAR(40) NULL,
                created_at DATETIME NOT NULL,
                completed_at DATETIME NULL,
                UNIQUE KEY uniq_mobile_client_operation (client_operation_id),
                INDEX idx_mobile_operation_user (user_id, created_at),
                INDEX idx_mobile_operation_storage (storage_id, created_at),
                INDEX idx_mobile_operation_manager (manager_user_id, created_at),
                INDEX idx_mobile_operation_status (status, created_at),
                CONSTRAINT fk_mobile_operation_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
                CONSTRAINT fk_mobile_operation_device FOREIGN KEY (device_session_id) REFERENCES mobile_device_sessions(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        if (!self::columnExists('mobile_operations', 'storage_id')) {
            Database::execute('ALTER TABLE mobile_operations ADD COLUMN storage_id BIGINT UNSIGNED NULL AFTER entity_id');
        }
        if (!self::indexExists('mobile_operations', 'idx_mobile_operation_storage')) {
            Database::execute('ALTER TABLE mobile_operations ADD INDEX idx_mobile_operation_storage (storage_id, created_at)');
        }
        if (!self::columnExists('mobile_operations', 'manager_user_id')) {
            Database::execute('ALTER TABLE mobile_operations ADD COLUMN manager_user_id BIGINT UNSIGNED NULL AFTER storage_id');
        }
        if (!self::indexExists('mobile_operations', 'idx_mobile_operation_manager')) {
            Database::execute('ALTER TABLE mobile_operations ADD INDEX idx_mobile_operation_manager (manager_user_id, created_at)');
        }
        Database::execute(
            'UPDATE mobile_operations operation_row
             INNER JOIN users employee ON employee.id = operation_row.user_id
             SET operation_row.manager_user_id = employee.manager_user_id
             WHERE operation_row.manager_user_id IS NULL
               AND employee.manager_user_id IS NOT NULL'
        );

        Database::execute(
            'CREATE TABLE IF NOT EXISTS inventory_movement_usage_details (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                movement_id BIGINT UNSIGNED NOT NULL,
                reason_code VARCHAR(40) NOT NULL,
                custom_reason VARCHAR(160) NULL,
                notes TEXT NULL,
                created_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uniq_mobile_usage_movement (movement_id),
                INDEX idx_mobile_usage_reason (reason_code, created_at),
                CONSTRAINT fk_mobile_usage_movement FOREIGN KEY (movement_id) REFERENCES inventory_movements(id) ON DELETE CASCADE,
                CONSTRAINT fk_mobile_usage_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        Database::execute(
            'CREATE TABLE IF NOT EXISTS inventory_change_events (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                event_type VARCHAR(60) NOT NULL,
                item_id BIGINT UNSIGNED NULL,
                storage_id BIGINT UNSIGNED NULL,
                entity_type VARCHAR(80) NULL,
                entity_id BIGINT UNSIGNED NULL,
                movement_id BIGINT UNSIGNED NULL,
                performed_by BIGINT UNSIGNED NULL,
                payload_json MEDIUMTEXT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_inventory_event_storage (storage_id, id),
                INDEX idx_inventory_event_item (item_id, id),
                INDEX idx_inventory_event_entity (entity_type, entity_id, id),
                INDEX idx_inventory_event_created (created_at, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        Database::execute(
            'CREATE TABLE IF NOT EXISTS mobile_refresh_token_history (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                device_session_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                refresh_token_hash CHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NOT NULL,
                reuse_detected_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uniq_mobile_refresh_history_hash (refresh_token_hash),
                INDEX idx_mobile_refresh_history_session (device_session_id, created_at),
                INDEX idx_mobile_refresh_history_expiry (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        Database::execute(
            'CREATE TABLE IF NOT EXISTS mobile_api_rate_limits (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                scope_name VARCHAR(40) NOT NULL,
                key_hash CHAR(64) NOT NULL,
                window_started_at DATETIME NOT NULL,
                request_count INT UNSIGNED NOT NULL DEFAULT 0,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uniq_mobile_rate_limit (scope_name, key_hash),
                INDEX idx_mobile_rate_limit_updated (updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
}
