<?php
declare(strict_types=1);

trait MaintenanceWristbandSchemas
{
    private static function ensureWristbandSchemas(): void
    {
        if (!self::columnExists('items', 'external_qr_tracking_enabled')) {
            Database::execute('ALTER TABLE items ADD COLUMN external_qr_tracking_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER refill_proof_policy');
        }

        if (!self::columnExists('handovers', 'wristband_tracking_mode')) {
            Database::execute('ALTER TABLE handovers ADD COLUMN wristband_tracking_mode ENUM("manual_only", "api_audit") NOT NULL DEFAULT "manual_only" AFTER usage_reporting_mode');
        }

        Database::execute(
            'CREATE TABLE IF NOT EXISTS wristband_integrations (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                storage_id BIGINT UNSIGNED NOT NULL,
                name VARCHAR(160) NOT NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 0,
                api_key_hash CHAR(64) NULL,
                api_key_prefix VARCHAR(20) NULL,
                ip_allowlist TEXT NULL,
                last_rotated_at DATETIME NULL,
                last_rotated_by BIGINT UNSIGNED NULL,
                created_by BIGINT UNSIGNED NULL,
                updated_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uniq_wristband_integration_storage (storage_id),
                UNIQUE KEY uniq_wristband_integration_prefix (api_key_prefix),
                CONSTRAINT fk_wristband_integration_storage FOREIGN KEY (storage_id) REFERENCES storages(id) ON DELETE CASCADE,
                CONSTRAINT fk_wristband_integration_rotator FOREIGN KEY (last_rotated_by) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_wristband_integration_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_wristband_integration_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        Database::execute(
            'CREATE TABLE IF NOT EXISTS wristband_imports (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                import_number VARCHAR(50) NOT NULL,
                source_filename VARCHAR(255) NOT NULL,
                source_sha256 CHAR(64) NOT NULL,
                mapping_mode ENUM("selected_item", "code_sku") NOT NULL,
                selected_item_id BIGINT UNSIGNED NULL,
                storage_id BIGINT UNSIGNED NULL,
                total_rows INT UNSIGNED NOT NULL DEFAULT 0,
                imported_rows INT UNSIGNED NOT NULL DEFAULT 0,
                duplicate_rows INT UNSIGNED NOT NULL DEFAULT 0,
                invalid_rows INT UNSIGNED NOT NULL DEFAULT 0,
                summary_json JSON NULL,
                created_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uniq_wristband_import_number (import_number),
                INDEX idx_wristband_import_created (created_at),
                INDEX idx_wristband_import_storage (storage_id, created_at),
                CONSTRAINT fk_wristband_import_item FOREIGN KEY (selected_item_id) REFERENCES items(id) ON DELETE SET NULL,
                CONSTRAINT fk_wristband_import_storage FOREIGN KEY (storage_id) REFERENCES storages(id) ON DELETE SET NULL,
                CONSTRAINT fk_wristband_import_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        if (!self::columnExists('wristband_imports', 'storage_id')) {
            Database::execute('ALTER TABLE wristband_imports ADD COLUMN storage_id BIGINT UNSIGNED NULL AFTER selected_item_id');
        }
        self::ensureIndexExists(
            'wristband_imports',
            'idx_wristband_import_storage',
            'CREATE INDEX idx_wristband_import_storage ON wristband_imports (storage_id, created_at)'
        );

        Database::execute(
            'CREATE TABLE IF NOT EXISTS wristband_sessions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                session_number VARCHAR(50) NOT NULL,
                integration_id BIGINT UNSIGNED NOT NULL,
                storage_id BIGINT UNSIGNED NOT NULL,
                handover_id BIGINT UNSIGNED NOT NULL,
                mode ENUM("api_audit", "manual_only") NOT NULL DEFAULT "api_audit",
                status ENUM("active", "paused", "manual_only", "closed") NOT NULL DEFAULT "active",
                paused_reason VARCHAR(500) NULL,
                variance_acknowledged TINYINT(1) NOT NULL DEFAULT 0,
                variance_note TEXT NULL,
                physical_used_quantity DECIMAL(18,4) NULL,
                api_checkins_quantity DECIMAL(18,4) NULL,
                variance_quantity DECIMAL(18,4) NULL,
                variance_acknowledged_by BIGINT UNSIGNED NULL,
                variance_acknowledged_at DATETIME NULL,
                started_at DATETIME NOT NULL,
                paused_at DATETIME NULL,
                resumed_at DATETIME NULL,
                closed_at DATETIME NULL,
                started_by BIGINT UNSIGNED NULL,
                updated_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uniq_wristband_session_number (session_number),
                UNIQUE KEY uniq_wristband_session_handover (handover_id),
                INDEX idx_wristband_session_storage_status (storage_id, status),
                CONSTRAINT fk_wristband_session_integration FOREIGN KEY (integration_id) REFERENCES wristband_integrations(id) ON DELETE RESTRICT,
                CONSTRAINT fk_wristband_session_storage FOREIGN KEY (storage_id) REFERENCES storages(id) ON DELETE RESTRICT,
                CONSTRAINT fk_wristband_session_handover FOREIGN KEY (handover_id) REFERENCES handovers(id) ON DELETE RESTRICT,
                CONSTRAINT fk_wristband_session_starter FOREIGN KEY (started_by) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_wristband_session_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_wristband_session_variance_user FOREIGN KEY (variance_acknowledged_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        if (!self::columnExists('wristband_sessions', 'physical_used_quantity')) {
            Database::execute('ALTER TABLE wristband_sessions ADD COLUMN physical_used_quantity DECIMAL(18,4) NULL AFTER variance_note');
        }
        if (!self::columnExists('wristband_sessions', 'api_checkins_quantity')) {
            Database::execute('ALTER TABLE wristband_sessions ADD COLUMN api_checkins_quantity DECIMAL(18,4) NULL AFTER physical_used_quantity');
        }
        if (!self::columnExists('wristband_sessions', 'variance_quantity')) {
            Database::execute('ALTER TABLE wristband_sessions ADD COLUMN variance_quantity DECIMAL(18,4) NULL AFTER api_checkins_quantity');
        }
        if (!self::columnExists('wristband_sessions', 'variance_acknowledged_by')) {
            Database::execute('ALTER TABLE wristband_sessions ADD COLUMN variance_acknowledged_by BIGINT UNSIGNED NULL AFTER variance_quantity');
        }
        if (!self::columnExists('wristband_sessions', 'variance_acknowledged_at')) {
            Database::execute('ALTER TABLE wristband_sessions ADD COLUMN variance_acknowledged_at DATETIME NULL AFTER variance_acknowledged_by');
        }

        Database::execute(
            'CREATE TABLE IF NOT EXISTS wristband_session_periods (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                session_id BIGINT UNSIGNED NOT NULL,
                paused_at DATETIME NOT NULL,
                paused_by BIGINT UNSIGNED NULL,
                pause_reason VARCHAR(500) NULL,
                resumed_at DATETIME NULL,
                resumed_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_wristband_period_session (session_id, paused_at),
                CONSTRAINT fk_wristband_period_session FOREIGN KEY (session_id) REFERENCES wristband_sessions(id) ON DELETE CASCADE,
                CONSTRAINT fk_wristband_period_pauser FOREIGN KEY (paused_by) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_wristband_period_resumer FOREIGN KEY (resumed_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        Database::execute(
            'CREATE TABLE IF NOT EXISTS wristband_codes (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                item_id BIGINT UNSIGNED NOT NULL,
                import_id BIGINT UNSIGNED NULL,
                code_hash CHAR(64) NOT NULL,
                code_masked VARCHAR(40) NOT NULL,
                state ENUM("available", "used", "void") NOT NULL DEFAULT "available",
                used_session_id BIGINT UNSIGNED NULL,
                used_event_id BIGINT UNSIGNED NULL,
                used_at DATETIME NULL,
                void_reason VARCHAR(500) NULL,
                void_by BIGINT UNSIGNED NULL,
                void_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uniq_wristband_code_hash (code_hash),
                INDEX idx_wristband_code_item_state (item_id, state),
                INDEX idx_wristband_code_session (used_session_id),
                CONSTRAINT fk_wristband_code_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE RESTRICT,
                CONSTRAINT fk_wristband_code_import FOREIGN KEY (import_id) REFERENCES wristband_imports(id) ON DELETE SET NULL,
                CONSTRAINT fk_wristband_code_session FOREIGN KEY (used_session_id) REFERENCES wristband_sessions(id) ON DELETE SET NULL,
                CONSTRAINT fk_wristband_code_void_user FOREIGN KEY (void_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        Database::execute(
            'CREATE TABLE IF NOT EXISTS wristband_events (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                integration_id BIGINT UNSIGNED NOT NULL,
                session_id BIGINT UNSIGNED NULL,
                code_id BIGINT UNSIGNED NULL,
                item_id BIGINT UNSIGNED NULL,
                handover_id BIGINT UNSIGNED NULL,
                external_event_id VARCHAR(190) NULL,
                payload_hash CHAR(64) NOT NULL,
                code_hash CHAR(64) NOT NULL,
                code_masked VARCHAR(40) NOT NULL,
                scanned_at DATETIME NULL,
                received_at DATETIME NOT NULL,
                request_ip VARCHAR(64) NULL,
                status ENUM("accepted", "paused", "unknown_code", "inactive_session", "item_not_eligible", "wrong_handover", "duplicate", "discarded", "reversed") NOT NULL,
                resolution_reason VARCHAR(500) NULL,
                resolved_by BIGINT UNSIGNED NULL,
                resolved_at DATETIME NULL,
                raw_payload JSON NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uniq_wristband_external_event (integration_id, external_event_id),
                INDEX idx_wristband_event_session_status (session_id, status),
                INDEX idx_wristband_event_code_hash (code_hash),
                INDEX idx_wristband_event_received (received_at),
                CONSTRAINT fk_wristband_event_integration FOREIGN KEY (integration_id) REFERENCES wristband_integrations(id) ON DELETE RESTRICT,
                CONSTRAINT fk_wristband_event_session FOREIGN KEY (session_id) REFERENCES wristband_sessions(id) ON DELETE SET NULL,
                CONSTRAINT fk_wristband_event_code FOREIGN KEY (code_id) REFERENCES wristband_codes(id) ON DELETE SET NULL,
                CONSTRAINT fk_wristband_event_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE SET NULL,
                CONSTRAINT fk_wristband_event_handover FOREIGN KEY (handover_id) REFERENCES handovers(id) ON DELETE SET NULL,
                CONSTRAINT fk_wristband_event_resolver FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        self::ensureIndexExists(
            'wristband_events',
            'uniq_wristband_payload',
            'CREATE UNIQUE INDEX uniq_wristband_payload ON wristband_events (integration_id, payload_hash)'
        );

        if ((int) Database::scalar('SELECT COUNT(*) FROM app_settings WHERE setting_key = "wristbands.api_enabled"') === 0) {
            Database::execute(
                'INSERT INTO app_settings (setting_key, setting_value, updated_by, updated_at)
                 VALUES ("wristbands.api_enabled", "0", NULL, NOW())'
            );
        }
    }
}
