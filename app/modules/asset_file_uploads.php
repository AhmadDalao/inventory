<?php
declare(strict_types=1);

// Domain module: company asset images and protected asset documents.

function validate_asset_image_upload(?array $file): ?string
{
    return validate_item_image_upload($file);
}

function store_asset_image(array $file, string $assetName): string
{
    $tmpName = (string) ($file['tmp_name'] ?? '');
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = $finfo ? (string) finfo_file($finfo, $tmpName) : '';

    if ($finfo && PHP_VERSION_ID < 80500) {
        finfo_close($finfo);
    }

    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    if (!isset($extensions[$mimeType])) {
        throw new RuntimeException('Unsupported asset image type.');
    }

    ensure_directory_exists(asset_upload_directory());

    $filename = date('YmdHis') . '-' . slugify_filename($assetName) . '-' . substr(bin2hex(random_bytes(5)), 0, 10) . '.' . $extensions[$mimeType];
    $destination = asset_upload_directory() . '/' . $filename;

    if (!move_uploaded_file($tmpName, $destination)) {
        throw new RuntimeException('Could not save the asset image.');
    }

    return $filename;
}

function delete_asset_image(?string $imagePath): void
{
    $imagePath = trim((string) $imagePath);

    if ($imagePath === '') {
        return;
    }

    $fullPath = asset_upload_directory() . '/' . basename($imagePath);

    if (is_file($fullPath)) {
        unlink($fullPath);
    }

    $deletedBy = class_exists('Auth') && Auth::user() ? (int) Auth::user()['id'] : null;
    mark_file_asset_deleted_by_relative_path(file_asset_relative_path('uploads/assets', $imagePath), $deletedBy);
}

function duplicate_asset_image(?string $imagePath, string $assetName): ?string
{
    $imagePath = trim((string) $imagePath);

    if ($imagePath === '') {
        return null;
    }

    $sourcePath = asset_upload_directory() . '/' . basename($imagePath);

    if (!is_file($sourcePath)) {
        return null;
    }

    ensure_directory_exists(asset_upload_directory());

    $extension = strtolower((string) pathinfo($sourcePath, PATHINFO_EXTENSION));
    $extension = $extension !== '' ? $extension : 'jpg';
    $filename = date('YmdHis') . '-' . slugify_filename($assetName) . '-' . substr(bin2hex(random_bytes(5)), 0, 10) . '.' . $extension;
    $destination = asset_upload_directory() . '/' . $filename;

    if (!copy($sourcePath, $destination)) {
        throw new RuntimeException('Could not reuse the copied asset image.');
    }

    return $filename;
}

function asset_document_mime_extensions(): array
{
    return [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
}

function validate_asset_document_upload(?array $file): ?string
{
    if ($file === null) {
        return null;
    }

    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($error !== UPLOAD_ERR_OK) {
        return 'Asset file upload failed. Use PDF, JPG, PNG, or WebP under 15 MB.';
    }

    $size = (int) ($file['size'] ?? 0);

    if ($size <= 0 || $size > 15 * 1024 * 1024) {
        return 'Asset file must be smaller than 15 MB.';
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');

    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        return 'Uploaded asset file is invalid.';
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = $finfo ? (string) finfo_file($finfo, $tmpName) : '';

    if ($finfo && PHP_VERSION_ID < 80500) {
        finfo_close($finfo);
    }

    if (!isset(asset_document_mime_extensions()[$mimeType])) {
        return 'Asset file must be PDF, JPG, PNG, or WebP.';
    }

    return null;
}

function store_asset_document(array $file, string $assetNumber, string $label = 'asset-file'): array
{
    $tmpName = (string) ($file['tmp_name'] ?? '');
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = $finfo ? (string) finfo_file($finfo, $tmpName) : '';

    if ($finfo && PHP_VERSION_ID < 80500) {
        finfo_close($finfo);
    }

    $extensions = asset_document_mime_extensions();

    if (!isset($extensions[$mimeType])) {
        throw new RuntimeException('Unsupported asset file type.');
    }

    ensure_directory_exists(asset_document_upload_directory());

    $originalName = basename((string) ($file['name'] ?? 'asset-file'));
    $filename = date('YmdHis') . '-' . slugify_filename($assetNumber . '-' . $label . '-' . pathinfo($originalName, PATHINFO_FILENAME)) . '-' . substr(bin2hex(random_bytes(5)), 0, 10) . '.' . $extensions[$mimeType];
    $destination = asset_document_upload_directory() . '/' . $filename;

    if (!move_uploaded_file($tmpName, $destination)) {
        throw new RuntimeException('Could not save the asset file.');
    }

    return [
        'original_filename' => $originalName !== '' ? $originalName : 'asset-file.' . $extensions[$mimeType],
        'stored_filename' => $filename,
        'mime_type' => $mimeType,
        'file_size' => (int) ($file['size'] ?? 0),
    ];
}
