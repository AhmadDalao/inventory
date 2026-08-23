<?php
declare(strict_types=1);

trait MaintenancePlatformSchemas
{
    private static function ensurePlatformSchemas(): void
    {
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
            'CREATE TABLE IF NOT EXISTS report_presets (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(160) NOT NULL,
                description TEXT NULL,
                report_type VARCHAR(80) NOT NULL,
                filters_json TEXT NOT NULL,
                export_format ENUM("csv", "xlsx") NOT NULL DEFAULT "csv",
                visibility ENUM("shared", "private") NOT NULL DEFAULT "shared",
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_by BIGINT UNSIGNED NULL,
                updated_by BIGINT UNSIGNED NULL,
                archived_by BIGINT UNSIGNED NULL,
                archived_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_report_presets_type (report_type, is_active),
                INDEX idx_report_presets_visibility (visibility, is_active),
                INDEX idx_report_presets_created_by (created_by),
                INDEX idx_report_presets_archived (archived_at),
                CONSTRAINT fk_report_presets_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_report_presets_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_report_presets_archived_by FOREIGN KEY (archived_by) REFERENCES users(id) ON DELETE SET NULL
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
            'CREATE TABLE IF NOT EXISTS persistent_login_tokens (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                selector CHAR(24) NOT NULL,
                validator_hash CHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                last_used_at DATETIME NULL,
                created_ip VARCHAR(64) NULL,
                last_ip VARCHAR(64) NULL,
                user_agent_hash CHAR(64) NULL,
                revoked_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uniq_persistent_login_selector (selector),
                INDEX idx_persistent_login_user (user_id, revoked_at, expires_at),
                CONSTRAINT fk_persistent_login_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
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
    }
}
