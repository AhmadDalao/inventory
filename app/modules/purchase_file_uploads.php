<?php
declare(strict_types=1);

// Domain module: purchase document validation and storage.

function purchase_document_mime_extensions(): array
{
    return [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
}

function validate_purchase_document_upload(?array $file): ?string
{
    if ($file === null) {
        return null;
    }

    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($error !== UPLOAD_ERR_OK) {
        return 'Document upload failed. Use PDF, JPG, PNG, or WebP under 15 MB.';
    }

    $size = (int) ($file['size'] ?? 0);

    if ($size <= 0 || $size > 15 * 1024 * 1024) {
        return 'Purchase documents must be smaller than 15 MB.';
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');

    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        return 'Uploaded purchase document is invalid.';
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = $finfo ? (string) finfo_file($finfo, $tmpName) : '';

    if ($finfo && PHP_VERSION_ID < 80500) {
        finfo_close($finfo);
    }

    if (!array_key_exists($mimeType, purchase_document_mime_extensions())) {
        return 'Purchase documents must be PDF, JPG, PNG, or WebP.';
    }

    if (starts_with($mimeType, 'image/') && @getimagesize($tmpName) === false) {
        return 'Uploaded purchase image is invalid.';
    }

    return null;
}

function purchase_document_file_meta(array $file): array
{
    $tmpName = (string) ($file['tmp_name'] ?? '');
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = $finfo ? (string) finfo_file($finfo, $tmpName) : '';

    if ($finfo && PHP_VERSION_ID < 80500) {
        finfo_close($finfo);
    }

    $extensions = purchase_document_mime_extensions();

    if (!isset($extensions[$mimeType])) {
        throw new RuntimeException('Unsupported purchase document type.');
    }

    return [
        'mime_type' => $mimeType,
        'extension' => $extensions[$mimeType],
    ];
}

function store_purchase_document(array $file, string $purchaseNumber): array
{
    $meta = purchase_document_file_meta($file);
    ensure_directory_exists(purchase_upload_directory());

    $originalName = basename((string) ($file['name'] ?? 'document'));
    $filename = date('YmdHis') . '-' . slugify_filename($purchaseNumber . '-' . pathinfo($originalName, PATHINFO_FILENAME)) . '-' . substr(bin2hex(random_bytes(5)), 0, 10) . '.' . $meta['extension'];
    $destination = purchase_upload_directory() . '/' . $filename;

    if (!move_uploaded_file((string) ($file['tmp_name'] ?? ''), $destination)) {
        throw new RuntimeException('Could not save the purchase document.');
    }

    return [
        'original_filename' => $originalName !== '' ? $originalName : 'document.' . $meta['extension'],
        'stored_filename' => $filename,
        'mime_type' => $meta['mime_type'],
        'file_size' => (int) ($file['size'] ?? 0),
    ];
}

function purchase_document_path(string $storedFilename): string
{
    return purchase_upload_directory() . '/' . basename($storedFilename);
}

function delete_purchase_document_file(?string $storedFilename): void
{
    $storedFilename = trim((string) $storedFilename);

    if ($storedFilename === '') {
        return;
    }

    $path = purchase_document_path($storedFilename);

    if (is_file($path)) {
        unlink($path);
    }

    $deletedBy = class_exists('Auth') && Auth::user() ? (int) Auth::user()['id'] : null;
    mark_file_asset_deleted_by_relative_path(file_asset_relative_path('storage/purchases', $storedFilename), $deletedBy);
}
