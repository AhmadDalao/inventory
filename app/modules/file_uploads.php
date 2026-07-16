<?php
declare(strict_types=1);

// Domain module: upload validation and protected document/image storage helpers.

function uploaded_file(string $key): ?array
{
    if (!isset($_FILES[$key]) || !is_array($_FILES[$key])) {
        return null;
    }

    if ((int) ($_FILES[$key]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    return $_FILES[$key];
}

function uploaded_files(string $key): array
{
    if (!isset($_FILES[$key]) || !is_array($_FILES[$key])) {
        return [];
    }

    $file = $_FILES[$key];
    $names = $file['name'] ?? [];

    if (!is_array($names)) {
        return uploaded_file($key) ? [uploaded_file($key)] : [];
    }

    $files = [];
    $count = count($names);

    for ($index = 0; $index < $count; $index++) {
        $error = (int) ($file['error'][$index] ?? UPLOAD_ERR_NO_FILE);

        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        $files[] = [
            'name' => $file['name'][$index] ?? '',
            'type' => $file['type'][$index] ?? '',
            'tmp_name' => $file['tmp_name'][$index] ?? '',
            'error' => $error,
            'size' => $file['size'][$index] ?? 0,
        ];
    }

    return $files;
}

function uploaded_file_at(string $key, int $index): ?array
{
    if (!isset($_FILES[$key]) || !is_array($_FILES[$key]) || !is_array($_FILES[$key]['name'] ?? null)) {
        return null;
    }

    $error = (int) ($_FILES[$key]['error'][$index] ?? UPLOAD_ERR_NO_FILE);

    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    return [
        'name' => $_FILES[$key]['name'][$index] ?? '',
        'type' => $_FILES[$key]['type'][$index] ?? '',
        'tmp_name' => $_FILES[$key]['tmp_name'][$index] ?? '',
        'error' => $error,
        'size' => $_FILES[$key]['size'][$index] ?? 0,
    ];
}

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

function workflow_document_mime_extensions(): array
{
    return [
        'application/pdf' => 'pdf',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.ms-office' => 'xls',
        'text/html' => 'xls',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
}

function validate_workflow_proof_upload(?array $file): ?string
{
    if ($file === null) {
        return null;
    }

    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($error !== UPLOAD_ERR_OK) {
        return 'Proof image upload failed. Use JPG, PNG, or WebP under 10 MB.';
    }

    $size = (int) ($file['size'] ?? 0);

    if ($size <= 0 || $size > 10 * 1024 * 1024) {
        return 'Proof image must be smaller than 10 MB.';
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');

    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        return 'Uploaded proof image is invalid.';
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = $finfo ? (string) finfo_file($finfo, $tmpName) : '';

    if ($finfo && PHP_VERSION_ID < 80500) {
        finfo_close($finfo);
    }

    if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        return 'Proof image must be JPG, PNG, or WebP.';
    }

    if (@getimagesize($tmpName) === false) {
        return 'Uploaded proof image is invalid.';
    }

    return null;
}

function workflow_document_file_meta(array $file): array
{
    $tmpName = (string) ($file['tmp_name'] ?? '');
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = $finfo ? (string) finfo_file($finfo, $tmpName) : '';

    if ($finfo && PHP_VERSION_ID < 80500) {
        finfo_close($finfo);
    }

    $extensions = workflow_document_mime_extensions();

    if (!isset($extensions[$mimeType])) {
        throw new RuntimeException('Unsupported workflow document type.');
    }

    return [
        'mime_type' => $mimeType,
        'extension' => $extensions[$mimeType],
    ];
}

function store_workflow_proof_document(array $file, string $workflowType, string $workflowNumber, string $stage): array
{
    $meta = workflow_document_file_meta($file);
    ensure_directory_exists(workflow_upload_directory());

    $originalName = basename((string) ($file['name'] ?? 'proof'));
    $filename = date('YmdHis') . '-' . slugify_filename($workflowType . '-' . $workflowNumber . '-' . $stage . '-' . pathinfo($originalName, PATHINFO_FILENAME)) . '-' . substr(bin2hex(random_bytes(5)), 0, 10) . '.' . $meta['extension'];
    $destination = workflow_upload_directory() . '/' . $filename;

    if (!move_uploaded_file((string) ($file['tmp_name'] ?? ''), $destination)) {
        throw new RuntimeException('Could not save the workflow proof image.');
    }

    return [
        'original_filename' => $originalName !== '' ? $originalName : 'proof.' . $meta['extension'],
        'stored_filename' => $filename,
        'mime_type' => $meta['mime_type'],
        'file_size' => (int) ($file['size'] ?? 0),
    ];
}

function store_workflow_pdf_document(string $pdfBytes, string $workflowType, string $workflowNumber, string $stage): array
{
    ensure_directory_exists(workflow_upload_directory());

    $filename = date('YmdHis') . '-' . slugify_filename($workflowType . '-' . $workflowNumber . '-' . $stage . '-signoff-img-v14') . '-' . substr(bin2hex(random_bytes(5)), 0, 10) . '.pdf';
    $destination = workflow_upload_directory() . '/' . $filename;

    if (file_put_contents($destination, $pdfBytes) === false) {
        throw new RuntimeException('Could not save the workflow sign-off PDF.');
    }

    return [
        'original_filename' => strtoupper($workflowType) . '-' . $workflowNumber . '-signoff.pdf',
        'stored_filename' => $filename,
        'mime_type' => 'application/pdf',
        'file_size' => filesize($destination) ?: strlen($pdfBytes),
    ];
}

function store_workflow_excel_document(string $sheetBytes, string $workflowType, string $workflowNumber, string $stage): array
{
    ensure_directory_exists(workflow_upload_directory());

    $filename = date('YmdHis') . '-' . slugify_filename($workflowType . '-' . $workflowNumber . '-' . $stage . '-signoff-sheet-img-v14') . '-' . substr(bin2hex(random_bytes(5)), 0, 10) . '.xlsx';
    $destination = workflow_upload_directory() . '/' . $filename;

    if (file_put_contents($destination, $sheetBytes) === false) {
        throw new RuntimeException('Could not save the workflow sign-off sheet.');
    }

    return [
        'original_filename' => strtoupper($workflowType) . '-' . $workflowNumber . '-signoff-sheet.xlsx',
        'stored_filename' => $filename,
        'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'file_size' => filesize($destination) ?: strlen($sheetBytes),
    ];
}

function workflow_document_path(string $storedFilename): string
{
    return workflow_upload_directory() . '/' . basename($storedFilename);
}

function delete_workflow_document_file(?string $storedFilename): void
{
    $storedFilename = trim((string) $storedFilename);

    if ($storedFilename === '') {
        return;
    }

    $path = workflow_document_path($storedFilename);

    if (is_file($path)) {
        unlink($path);
    }

    $deletedBy = class_exists('Auth') && Auth::user() ? (int) Auth::user()['id'] : null;
    mark_file_asset_deleted_by_relative_path(file_asset_relative_path('storage/workflows', $storedFilename), $deletedBy);
}

function validate_item_image_upload(?array $file): ?string
{
    if ($file === null) {
        return null;
    }

    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($error !== UPLOAD_ERR_OK) {
        return 'Image upload failed. Try a JPG, PNG, or WebP under 5 MB.';
    }

    $size = (int) ($file['size'] ?? 0);

    if ($size <= 0 || $size > 5 * 1024 * 1024) {
        return 'Image must be smaller than 5 MB.';
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');

    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        return 'Uploaded image is invalid.';
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = $finfo ? (string) finfo_file($finfo, $tmpName) : '';

    if ($finfo && PHP_VERSION_ID < 80500) {
        finfo_close($finfo);
    }

    if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        return 'Image must be JPG, PNG, or WebP.';
    }

    return null;
}

function store_item_image(array $file, string $itemName): string
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
        throw new RuntimeException('Unsupported image type.');
    }

    ensure_directory_exists(item_upload_directory());

    $filename = date('YmdHis') . '-' . slugify_filename($itemName) . '-' . substr(bin2hex(random_bytes(5)), 0, 10) . '.' . $extensions[$mimeType];
    $destination = item_upload_directory() . '/' . $filename;

    if (!move_uploaded_file($tmpName, $destination)) {
        throw new RuntimeException('Could not save the uploaded image.');
    }

    return $filename;
}

function duplicate_item_image(?string $imagePath, string $itemName): ?string
{
    $imagePath = trim((string) $imagePath);

    if ($imagePath === '') {
        return null;
    }

    $sourcePath = item_upload_directory() . '/' . basename($imagePath);

    if (!is_file($sourcePath)) {
        return null;
    }

    ensure_directory_exists(item_upload_directory());

    $extension = strtolower((string) pathinfo($sourcePath, PATHINFO_EXTENSION));
    $extension = $extension !== '' ? $extension : 'jpg';
    $filename = date('YmdHis') . '-' . slugify_filename($itemName) . '-' . substr(bin2hex(random_bytes(5)), 0, 10) . '.' . $extension;
    $destination = item_upload_directory() . '/' . $filename;

    if (!copy($sourcePath, $destination)) {
        throw new RuntimeException('Could not reuse the copied image.');
    }

    return $filename;
}

function delete_item_image(?string $imagePath): void
{
    $imagePath = trim((string) $imagePath);

    if ($imagePath === '') {
        return;
    }

    $fullPath = item_upload_directory() . '/' . basename($imagePath);

    if (is_file($fullPath)) {
        unlink($fullPath);
    }

    $deletedBy = class_exists('Auth') && Auth::user() ? (int) Auth::user()['id'] : null;
    mark_file_asset_deleted_by_relative_path(file_asset_relative_path('uploads/items', $imagePath), $deletedBy);
}

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
