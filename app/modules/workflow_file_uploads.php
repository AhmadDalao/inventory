<?php
declare(strict_types=1);

// Domain module: workflow proof/signoff document validation and storage.

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
