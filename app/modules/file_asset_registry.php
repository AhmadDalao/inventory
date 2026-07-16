<?php
declare(strict_types=1);

// Domain module: file asset registration and deletion markers.

function register_file_asset(array $asset): void
{
    if (!site_settings_table_exists()) {
        return;
    }

    try {
        $tableExists = (int) Database::scalar(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = :table_name',
            ['table_name' => 'file_assets']
        );
    } catch (Throwable $exception) {
        return;
    }

    if ($tableExists === 0) {
        return;
    }

    $relativePath = trim((string) ($asset['relative_path'] ?? ''));

    if ($relativePath === '') {
        return;
    }

    $archivePath = trim((string) ($asset['archive_path'] ?? ''));

    if ($archivePath === '') {
        $existingArchivePath = Database::scalar(
            'SELECT archive_path
             FROM file_assets
             WHERE relative_path = :relative_path
             LIMIT 1',
            ['relative_path' => $relativePath]
        );

        if (is_string($existingArchivePath) && trim($existingArchivePath) !== '' && is_file(base_path((string) $existingArchivePath))) {
            $archivePath = (string) $existingArchivePath;
        } else {
            $archivePath = file_asset_archive_copy($relativePath) ?? '';
        }
    }

    Database::execute(
        'INSERT INTO file_assets (
            source_type,
            source_id,
            context_type,
            context_id,
            display_name,
            original_filename,
            stored_filename,
            relative_path,
            archive_path,
            mime_type,
            file_size,
            file_group,
            uploaded_by,
            created_at,
            updated_at
         ) VALUES (
            :source_type,
            :source_id,
            :context_type,
            :context_id,
            :display_name,
            :original_filename,
            :stored_filename,
            :relative_path,
            :archive_path,
            :mime_type,
            :file_size,
            :file_group,
            :uploaded_by,
            COALESCE(:created_at, NOW()),
            NOW()
         )
         ON DUPLICATE KEY UPDATE
            source_type = VALUES(source_type),
            source_id = VALUES(source_id),
            context_type = VALUES(context_type),
            context_id = VALUES(context_id),
            display_name = VALUES(display_name),
            original_filename = VALUES(original_filename),
            stored_filename = VALUES(stored_filename),
            mime_type = VALUES(mime_type),
            file_size = CASE WHEN VALUES(file_size) > 0 THEN VALUES(file_size) ELSE file_size END,
            archive_path = CASE WHEN COALESCE(archive_path, "") != "" THEN archive_path ELSE VALUES(archive_path) END,
            file_group = VALUES(file_group),
            uploaded_by = COALESCE(VALUES(uploaded_by), uploaded_by),
            deleted_at = NULL,
            deleted_by = NULL,
            updated_at = NOW()',
        [
            'source_type' => (string) ($asset['source_type'] ?? 'general'),
            'source_id' => isset($asset['source_id']) ? (int) $asset['source_id'] : null,
            'context_type' => $asset['context_type'] ?? null,
            'context_id' => isset($asset['context_id']) ? (int) $asset['context_id'] : null,
            'display_name' => trim((string) ($asset['display_name'] ?? 'File')) ?: 'File',
            'original_filename' => trim((string) ($asset['original_filename'] ?? basename($relativePath))) ?: basename($relativePath),
            'stored_filename' => trim((string) ($asset['stored_filename'] ?? basename($relativePath))) ?: basename($relativePath),
            'relative_path' => $relativePath,
            'archive_path' => $archivePath !== '' ? $archivePath : null,
            'mime_type' => trim((string) ($asset['mime_type'] ?? 'application/octet-stream')) ?: 'application/octet-stream',
            'file_size' => max(0, (int) ($asset['file_size'] ?? 0)),
            'file_group' => trim((string) ($asset['file_group'] ?? 'general')) ?: 'general',
            'uploaded_by' => isset($asset['uploaded_by']) ? (int) $asset['uploaded_by'] : null,
            'created_at' => $asset['created_at'] ?? null,
        ]
    );
}

function register_item_image_asset(int $itemId, string $imagePath, string $displayName, ?int $userId = null, ?string $createdAt = null): void
{
    $filename = basename($imagePath);

    if ($filename === '') {
        return;
    }

    $relativePath = file_asset_relative_path('uploads/items', $filename);
    $absolutePath = base_path($relativePath);

    register_file_asset([
        'source_type' => 'item_image',
        'source_id' => $itemId,
        'context_type' => 'item',
        'context_id' => $itemId,
        'display_name' => $displayName !== '' ? $displayName : 'Item image',
        'original_filename' => $filename,
        'stored_filename' => $filename,
        'relative_path' => $relativePath,
        'mime_type' => file_asset_mime_type($absolutePath, 'image/jpeg'),
        'file_size' => file_asset_size($absolutePath),
        'file_group' => 'item_image',
        'uploaded_by' => $userId,
        'created_at' => $createdAt,
    ]);
}

function register_asset_image_asset(int $assetId, string $imagePath, string $displayName, ?int $userId = null, ?string $createdAt = null): void
{
    $filename = basename($imagePath);

    if ($filename === '') {
        return;
    }

    $relativePath = file_asset_relative_path('uploads/assets', $filename);
    $absolutePath = base_path($relativePath);

    register_file_asset([
        'source_type' => 'asset_image',
        'source_id' => $assetId,
        'context_type' => 'asset',
        'context_id' => $assetId,
        'display_name' => $displayName !== '' ? $displayName : 'Asset image',
        'original_filename' => $filename,
        'stored_filename' => $filename,
        'relative_path' => $relativePath,
        'mime_type' => file_asset_mime_type($absolutePath, 'image/jpeg'),
        'file_size' => file_asset_size($absolutePath),
        'file_group' => 'asset_image',
        'uploaded_by' => $userId,
        'created_at' => $createdAt,
    ]);
}

function register_asset_document_asset(int $assetId, string $assetNumber, array $document, ?int $userId = null, ?string $createdAt = null): void
{
    $filename = basename((string) ($document['stored_filename'] ?? ''));

    if ($filename === '') {
        return;
    }

    $relativePath = file_asset_relative_path('storage/assets', $filename);
    $absolutePath = base_path($relativePath);

    register_file_asset([
        'source_type' => 'asset_file',
        'source_id' => $assetId,
        'context_type' => 'asset',
        'context_id' => $assetId,
        'display_name' => trim($assetNumber . ' · Asset file') ?: 'Asset file',
        'original_filename' => (string) ($document['original_filename'] ?? $filename),
        'stored_filename' => $filename,
        'relative_path' => $relativePath,
        'mime_type' => (string) ($document['mime_type'] ?? file_asset_mime_type($absolutePath)),
        'file_size' => (int) ($document['file_size'] ?? file_asset_size($absolutePath)),
        'file_group' => 'asset_file',
        'uploaded_by' => $userId,
        'created_at' => $createdAt,
    ]);
}

function register_purchase_line_image_asset(int $lineId, int $purchaseId, string $imagePath, string $displayName, ?int $userId = null, ?string $createdAt = null): void
{
    $filename = basename($imagePath);

    if ($filename === '') {
        return;
    }

    $relativePath = file_asset_relative_path('uploads/items', $filename);
    $absolutePath = base_path($relativePath);

    register_file_asset([
        'source_type' => 'purchase_line_image',
        'source_id' => $lineId,
        'context_type' => 'purchase',
        'context_id' => $purchaseId,
        'display_name' => $displayName !== '' ? $displayName : 'Purchase line image',
        'original_filename' => $filename,
        'stored_filename' => $filename,
        'relative_path' => $relativePath,
        'mime_type' => file_asset_mime_type($absolutePath, 'image/jpeg'),
        'file_size' => file_asset_size($absolutePath),
        'file_group' => 'purchase_line_image',
        'uploaded_by' => $userId,
        'created_at' => $createdAt,
    ]);
}

function register_purchase_document_asset(int $documentId, int $purchaseId, string $purchaseNumber, array $document, ?int $userId = null, ?string $createdAt = null): void
{
    $filename = basename((string) ($document['stored_filename'] ?? ''));

    if ($filename === '') {
        return;
    }

    $relativePath = file_asset_relative_path('storage/purchases', $filename);
    $absolutePath = base_path($relativePath);
    $documentType = (string) ($document['document_type'] ?? 'proof');

    register_file_asset([
        'source_type' => 'purchase_document',
        'source_id' => $documentId,
        'context_type' => 'purchase',
        'context_id' => $purchaseId,
        'display_name' => trim($purchaseNumber . ' · ' . file_asset_source_label($documentType)) ?: 'Purchase document',
        'original_filename' => (string) ($document['original_filename'] ?? $filename),
        'stored_filename' => $filename,
        'relative_path' => $relativePath,
        'mime_type' => (string) ($document['mime_type'] ?? file_asset_mime_type($absolutePath)),
        'file_size' => (int) ($document['file_size'] ?? file_asset_size($absolutePath)),
        'file_group' => 'purchase_document',
        'uploaded_by' => $userId,
        'created_at' => $createdAt,
    ]);
}

function register_workflow_document_asset(int $documentId, string $workflowType, int $workflowId, string $workflowNumber, array $document, ?int $userId = null, ?string $createdAt = null): void
{
    $filename = basename((string) ($document['stored_filename'] ?? ''));

    if ($filename === '') {
        return;
    }

    $documentType = (string) ($document['document_type'] ?? 'proof_image');
    if ($documentType === 'signoff_pdf') {
        $fileGroup = 'workflow_pdf';
        $sourceType = 'workflow_pdf';
    } elseif ($documentType === 'signoff_excel') {
        $fileGroup = 'workflow_excel';
        $sourceType = 'workflow_excel';
    } else {
        $fileGroup = 'workflow_proof';
        $sourceType = 'workflow_proof';
    }
    $relativePath = file_asset_relative_path('storage/workflows', $filename);
    $absolutePath = base_path($relativePath);

    register_file_asset([
        'source_type' => $sourceType,
        'source_id' => $documentId,
        'context_type' => $workflowType,
        'context_id' => $workflowId,
        'display_name' => trim($workflowNumber . ' · ' . file_asset_source_label($sourceType)) ?: 'Workflow document',
        'original_filename' => (string) ($document['original_filename'] ?? $filename),
        'stored_filename' => $filename,
        'relative_path' => $relativePath,
        'mime_type' => (string) ($document['mime_type'] ?? file_asset_mime_type($absolutePath)),
        'file_size' => (int) ($document['file_size'] ?? file_asset_size($absolutePath)),
        'file_group' => $fileGroup,
        'uploaded_by' => $userId,
        'created_at' => $createdAt,
    ]);
}

function mark_file_asset_deleted_by_relative_path(string $relativePath, ?int $deletedBy = null): void
{
    $relativePath = trim($relativePath);

    if ($relativePath === '') {
        return;
    }

    try {
        Database::execute(
            'UPDATE file_assets
             SET deleted_at = COALESCE(deleted_at, NOW()),
                 deleted_by = COALESCE(:deleted_by, deleted_by),
                 updated_at = NOW()
             WHERE relative_path = :relative_path',
            [
                'deleted_by' => $deletedBy,
                'relative_path' => $relativePath,
            ]
        );
    } catch (Throwable $exception) {
        return;
    }
}
