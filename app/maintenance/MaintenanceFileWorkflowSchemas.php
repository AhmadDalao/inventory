<?php
declare(strict_types=1);

trait MaintenanceFileWorkflowSchemas
{
    private static function ensureFileWorkflowDocumentSchemas(): void
    {
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
    }
}
