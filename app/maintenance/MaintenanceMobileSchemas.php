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
                is_default TINYINT(1) NOT NULL DEFAULT 0,
                created_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uniq_mobile_user_storage (user_id, storage_id),
                INDEX idx_mobile_assignment_storage (storage_id, user_id),
                CONSTRAINT fk_mobile_assignment_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_mobile_assignment_storage FOREIGN KEY (storage_id) REFERENCES storages(id) ON DELETE CASCADE,
                CONSTRAINT fk_mobile_assignment_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
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
                INDEX idx_mobile_operation_status (status, created_at),
                CONSTRAINT fk_mobile_operation_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
                CONSTRAINT fk_mobile_operation_device FOREIGN KEY (device_session_id) REFERENCES mobile_device_sessions(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
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
    }
}
