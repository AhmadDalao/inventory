<?php
declare(strict_types=1);

// Domain module: files. Function names are preserved for route/view compatibility.

// Moved from workflows.php.

function workflow_documents(string $workflowType, int $workflowId): array
{
    if (!in_array($workflowType, ['handover', 'request'], true)) {
        return [];
    }

    return Database::fetchAll(
        'SELECT documents.*,
                uploader.name AS uploaded_by_name
         FROM workflow_documents documents
         LEFT JOIN users uploader ON uploader.id = documents.uploaded_by
         WHERE documents.workflow_type = :workflow_type
           AND documents.workflow_id = :workflow_id
         ORDER BY documents.document_type = "signoff_pdf" DESC,
                  documents.created_at DESC,
                  documents.id DESC',
        [
            'workflow_type' => $workflowType,
            'workflow_id' => $workflowId,
        ]
    );
}

function file_filters(): array
{
    $group = trim((string) query('group', 'all'));
    $status = trim((string) query('status', 'all'));
    $groups = file_asset_group_options();
    $statuses = file_asset_status_options();

    return [
        'search' => trim((string) query('search', '')),
        'group' => array_key_exists($group, $groups) ? $group : 'all',
        'status' => array_key_exists($status, $statuses) ? $status : 'all',
        'date_from' => normalize_workflow_date((string) query('date_from', '')),
        'date_to' => normalize_workflow_date((string) query('date_to', '')),
    ];
}

function file_asset_select_sql(): string
{
    return 'SELECT assets.*,
                   uploader.name AS uploaded_by_name,
                   uploader.email AS uploaded_by_email,
                   deleter.name AS deleted_by_name,
                   item.name AS item_name,
                   item.sku AS item_sku,
                   company_asset.asset_number AS asset_number,
                   company_asset.name AS asset_name,
                   company_asset.barcode AS asset_barcode,
                   company_asset.serial_number AS asset_serial_number,
                   purchase.purchase_number,
                   purchase.status AS purchase_status,
                   handover.handover_number,
                   request_record.request_number,
                   supplier.name AS supplier_name,
                   storage_location.name AS storage_name
            FROM file_assets assets
            LEFT JOIN users uploader ON uploader.id = assets.uploaded_by
            LEFT JOIN users deleter ON deleter.id = assets.deleted_by
            LEFT JOIN items item
                ON (assets.context_type = "item" AND item.id = assets.context_id)
                OR (assets.source_type = "item_image" AND item.id = assets.source_id)
            LEFT JOIN company_assets company_asset
                ON (assets.context_type = "asset" AND company_asset.id = assets.context_id)
                OR (assets.source_type = "asset_image" AND company_asset.id = assets.source_id)
            LEFT JOIN purchases purchase
                ON assets.context_type = "purchase"
               AND purchase.id = assets.context_id
            LEFT JOIN suppliers supplier ON supplier.id = purchase.supplier_id
            LEFT JOIN handovers handover
                ON assets.context_type = "handover"
               AND handover.id = assets.context_id
            LEFT JOIN item_requests request_record
                ON assets.context_type = "request"
               AND request_record.id = assets.context_id
            LEFT JOIN storages storage_location
                ON storage_location.id = purchase.destination_storage_id
                OR storage_location.id = company_asset.storage_id
                OR storage_location.id = handover.source_storage_id
                OR storage_location.id = request_record.source_storage_id';
}

function file_asset_rows(array $filters, int $limit = 500): array
{
    [$where, $params] = build_file_asset_where($filters);

    return Database::fetchAll(
        file_asset_select_sql() . '
         ' . $where . '
         ORDER BY assets.created_at DESC, assets.id DESC
         LIMIT ' . max(1, min(1000, $limit)),
        $params
    );
}

function file_asset_counts(): array
{
    $rows = Database::fetchAll(
        'SELECT file_group,
                COUNT(*) AS file_count,
                COALESCE(SUM(file_size), 0) AS total_size
         FROM file_assets
         WHERE deleted_at IS NULL
         GROUP BY file_group'
    );

    $counts = [
        'all' => ['file_count' => 0, 'total_size' => 0],
    ];

    foreach ($rows as $row) {
        $group = (string) $row['file_group'];
        $counts[$group] = [
            'file_count' => (int) $row['file_count'],
            'total_size' => (float) $row['total_size'],
        ];
        $counts['all']['file_count'] += (int) $row['file_count'];
        $counts['all']['total_size'] += (float) $row['total_size'];
    }

    return $counts;
}

function file_asset_find_or_abort(int $id): array
{
    $asset = Database::fetch(
        file_asset_select_sql() . '
         WHERE assets.id = :id
         LIMIT 1',
        ['id' => $id]
    );

    if (!$asset) {
        abort(404, 'File not found.');
    }

    return $asset;
}

function handle_files_index(): void
{
    app_ready_or_redirect();
    Auth::requireLogin();

    if (!file_library_can_access()) {
        flash('danger', 'Files are available to Owner, Admin, and CFO accounts only.');
        redirect('/dashboard');
    }

    $filters = file_filters();

    View::render('files/index', [
        'title' => site_setting('page.files', 'Files'),
        'filters' => $filters,
        'files' => file_asset_rows($filters),
        'groups' => file_asset_group_options(),
        'statuses' => file_asset_status_options(),
        'counts' => file_asset_counts(),
    ]);
}

function handle_file_download(array $params): void
{
    app_ready_or_redirect();
    Auth::requireLogin();

    if (!file_library_can_download()) {
        flash('danger', 'You do not have access to download files.');
        redirect('/files');
    }

    $asset = file_asset_find_or_abort((int) $params['id']);
    $path = file_asset_absolute_path($asset);

    if (!is_file($path)) {
        abort(404, 'The tracked file copy is missing.');
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    send_download_headers((string) $asset['mime_type'], (string) $asset['original_filename'], (int) filesize($path));
    readfile($path);
    exit;
}

function workflow_document_find_or_abort(int $documentId): array
{
    $document = Database::fetch(
        'SELECT documents.*,
                uploader.name AS uploaded_by_name
         FROM workflow_documents documents
         LEFT JOIN users uploader ON uploader.id = documents.uploaded_by
         WHERE documents.id = :id
         LIMIT 1',
        ['id' => $documentId]
    );

    if (!$document) {
        abort(404, 'Workflow document not found.');
    }

    return $document;
}

function workflow_document_authorized_file(int $documentId): array
{
    $document = workflow_document_find_or_abort($documentId);
    $workflowType = (string) $document['workflow_type'];

    if ($workflowType === 'handover') {
        find_handover_or_abort((int) $document['workflow_id']);
    } elseif ($workflowType === 'request') {
        find_request_or_abort((int) $document['workflow_id']);
    } else {
        abort(403, 'You do not have access to this document.');
    }

    $path = workflow_document_path((string) $document['stored_filename']);

    if (!is_file($path)) {
        abort(404, 'The workflow document file is missing.');
    }

    return [$document, $path];
}

function handle_workflow_document_view(array $params): void
{
    app_ready_or_redirect();
    Auth::requireLogin();

    [$document, $path] = workflow_document_authorized_file((int) $params['id']);
    $mimeType = (string) $document['mime_type'];
    $filename = (string) $document['original_filename'];
    $previewableMimeTypes = [
        'application/pdf',
        'image/gif',
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if (in_array($mimeType, $previewableMimeTypes, true)) {
        send_inline_file_headers($mimeType, $filename, (int) filesize($path));
    } else {
        send_download_headers($mimeType, $filename, (int) filesize($path));
    }

    readfile($path);
    exit;
}

function handle_workflow_document_download(array $params): void
{
    app_ready_or_redirect();
    Auth::requireLogin();

    [$document, $path] = workflow_document_authorized_file((int) $params['id']);

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    send_download_headers((string) $document['mime_type'], (string) $document['original_filename'], (int) filesize($path));
    readfile($path);
    exit;
}

function handle_export_files(): void
{
    app_ready_or_redirect();
    Auth::requireLogin();

    if (!file_library_can_export()) {
        flash('danger', 'You do not have access to export files.');
        redirect('/files');
    }

    $filters = file_filters();

    if (trim((string) query('status', '')) === '') {
        $filters['status'] = 'all';
    }

    $rows = array_map(static function (array $asset): array {
        return [
            $asset['display_name'],
            $asset['original_filename'],
            file_asset_source_label((string) $asset['source_type']),
            $asset['file_group'],
            $asset['mime_type'],
            (int) $asset['file_size'],
            format_file_size($asset['file_size']),
            file_asset_context_label($asset),
            $asset['purchase_number'] ?: '',
            $asset['supplier_name'] ?: '',
            $asset['storage_name'] ?: '',
            $asset['item_name'] ?: '',
            $asset['item_sku'] ?: '',
            $asset['uploaded_by_name'] ?: '',
            $asset['created_at'] ?: '',
            $asset['deleted_at'] ?: '',
            $asset['deleted_by_name'] ?: '',
            $asset['relative_path'],
            $asset['archive_path'] ?: '',
        ];
    }, file_asset_rows($filters, 1000));

    export_csv('files-export-' . date('Ymd-His') . '.csv', [
        'Display Name',
        'Original Filename',
        'Source Type',
        'File Group',
        'MIME Type',
        'Size Bytes',
        'Size',
        'Context',
        'Purchase Number',
        'Supplier',
        'Storage',
        'Item',
        'SKU',
        'Uploaded By',
        'Uploaded At',
        'Deleted At',
        'Deleted By',
        'Original Path',
        'Archive Path',
    ], $rows);
}

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

function file_asset_group_options(): array
{
    return [
        'all' => 'All files',
        'item_image' => 'Item images',
        'asset_image' => 'Asset images',
        'asset_file' => 'Asset files',
        'purchase_document' => 'Purchase documents',
        'purchase_line_image' => 'Purchase line images',
        'workflow_proof' => 'Workflow proof images',
        'workflow_pdf' => 'Workflow sign-off PDFs',
        'workflow_excel' => 'Workflow sign-off sheets',
    ];
}

function file_asset_group_label(string $group): string
{
    $groups = file_asset_group_options();

    return $groups[$group] ?? ucwords(str_replace('_', ' ', $group));
}

function file_asset_status_options(): array
{
    return [
        'all' => 'All statuses',
        'active' => 'Available',
        'deleted' => 'Deleted',
    ];
}

function file_library_can_access(?array $user = null): bool
{
    $user = $user ?? Auth::user();

    if (!$user) {
        return false;
    }

    if ((string) ($user['role'] ?? '') === 'staff') {
        return false;
    }

    return in_array((string) ($user['role'] ?? ''), ['owner', 'admin'], true)
        || (string) ($user['position'] ?? '') === 'cfo'
        || Auth::hasPermission('files.view');
}

function file_library_can_download(?array $user = null): bool
{
    if (!file_library_can_access($user)) {
        return false;
    }

    $user = $user ?? Auth::user();

    return in_array((string) ($user['role'] ?? ''), ['owner', 'admin'], true)
        || (string) ($user['position'] ?? '') === 'cfo'
        || Auth::hasPermission('files.download');
}

function file_library_can_export(?array $user = null): bool
{
    if (!file_library_can_access($user)) {
        return false;
    }

    $user = $user ?? Auth::user();

    return in_array((string) ($user['role'] ?? ''), ['owner', 'admin'], true)
        || (string) ($user['position'] ?? '') === 'cfo'
        || Auth::hasPermission('files.export');
}

function file_library_can_manage(?array $user = null): bool
{
    $user = $user ?? Auth::user();

    if (!$user || (string) ($user['role'] ?? '') === 'staff') {
        return false;
    }

    return (string) ($user['role'] ?? '') === 'owner' || Auth::hasPermission('files.manage');
}

function file_asset_relative_path(string $directory, string $storedFilename): string
{
    return trim($directory, '/') . '/' . basename($storedFilename);
}

function file_asset_absolute_path(array $asset): string
{
    $archivePath = trim((string) ($asset['archive_path'] ?? ''));

    if ($archivePath !== '' && is_file(base_path($archivePath))) {
        return base_path($archivePath);
    }

    return base_path((string) ($asset['relative_path'] ?? ''));
}

function file_asset_exists(array $asset): bool
{
    $path = file_asset_absolute_path($asset);

    return $path !== base_path() && is_file($path);
}

function file_asset_mime_type(string $path, string $fallback = 'application/octet-stream'): string
{
    if (!is_file($path)) {
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        if ($extension === 'pdf') {
            return 'application/pdf';
        }

        if (in_array($extension, ['jpg', 'jpeg'], true)) {
            return 'image/jpeg';
        }

        if ($extension === 'png') {
            return 'image/png';
        }

        if ($extension === 'webp') {
            return 'image/webp';
        }

        return $fallback;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = $finfo ? (string) finfo_file($finfo, $path) : '';

    if ($finfo && PHP_VERSION_ID < 80500) {
        finfo_close($finfo);
    }

    return $mimeType !== '' ? $mimeType : $fallback;
}

function file_asset_size(string $path, int $fallback = 0): int
{
    if (!is_file($path)) {
        return $fallback;
    }

    $size = filesize($path);

    return $size === false ? $fallback : (int) $size;
}

function format_file_size($bytes): string
{
    $bytes = max(0, (float) $bytes);

    if ($bytes >= 1024 * 1024) {
        return number_format($bytes / (1024 * 1024), 2) . ' MB';
    }

    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    }

    return number_format($bytes, 0) . ' B';
}

function file_asset_archive_copy(string $sourceRelativePath): ?string
{
    $sourceRelativePath = trim($sourceRelativePath);

    if ($sourceRelativePath === '') {
        return null;
    }

    $sourcePath = base_path($sourceRelativePath);

    if (!is_file($sourcePath)) {
        return null;
    }

    ensure_directory_exists(file_archive_directory());

    $extension = strtolower((string) pathinfo($sourcePath, PATHINFO_EXTENSION));
    $extension = $extension !== '' ? $extension : 'bin';
    $baseName = pathinfo($sourcePath, PATHINFO_FILENAME);
    $filename = date('YmdHis') . '-' . slugify_filename($baseName !== '' ? $baseName : 'file') . '-' . substr(bin2hex(random_bytes(5)), 0, 10) . '.' . $extension;
    $destination = file_archive_directory() . '/' . $filename;

    if (!copy($sourcePath, $destination)) {
        return null;
    }

    return file_asset_relative_path('storage/files', $filename);
}

function file_asset_source_label(string $sourceType): string
{
    switch ($sourceType) {
        case 'item_image':
            return 'Item image';
        case 'asset_image':
            return 'Asset image';
        case 'asset_file':
            return 'Asset file';
        case 'purchase_document':
            return 'Purchase document';
        case 'purchase_line_image':
            return 'Purchase line image';
        case 'workflow_proof':
            return 'Workflow proof image';
        case 'workflow_pdf':
            return 'Workflow sign-off PDF';
        case 'workflow_excel':
            return 'Workflow sign-off sheet';
        default:
            return ucwords(str_replace('_', ' ', $sourceType));
    }
}

function file_asset_context_label(array $asset): string
{
    if (!empty($asset['handover_number'])) {
        return (string) $asset['handover_number'];
    }

    if (!empty($asset['request_number'])) {
        return (string) $asset['request_number'];
    }

    if (!empty($asset['purchase_number'])) {
        return (string) $asset['purchase_number'];
    }

    if (!empty($asset['item_name'])) {
        return trim((string) $asset['item_name'] . (!empty($asset['item_sku']) ? ' · ' . $asset['item_sku'] : ''));
    }

    if (!empty($asset['asset_number'])) {
        return trim((string) $asset['asset_number'] . (!empty($asset['asset_name']) ? ' · ' . $asset['asset_name'] : ''));
    }

    if (!empty($asset['context_type']) && !empty($asset['context_id'])) {
        return ucwords(str_replace('_', ' ', (string) $asset['context_type'])) . ' #' . (int) $asset['context_id'];
    }

    return 'General upload';
}

function file_asset_context_url(array $asset): ?string
{
    if (($asset['context_type'] ?? '') === 'purchase' && !empty($asset['context_id'])) {
        return url('/purchases/' . (int) $asset['context_id']);
    }

    if (($asset['context_type'] ?? '') === 'handover' && !empty($asset['context_id'])) {
        return url('/handovers/' . (int) $asset['context_id']);
    }

    if (($asset['context_type'] ?? '') === 'request' && !empty($asset['context_id'])) {
        return url('/requests/' . (int) $asset['context_id']);
    }

    if (($asset['context_type'] ?? '') === 'item' && !empty($asset['context_id'])) {
        return url('/items/' . (int) $asset['context_id']);
    }

    if (($asset['context_type'] ?? '') === 'asset' && !empty($asset['context_id'])) {
        return url('/company-assets/' . (int) $asset['context_id']);
    }

    if (($asset['source_type'] ?? '') === 'item_image' && !empty($asset['source_id'])) {
        return url('/items/' . (int) $asset['source_id']);
    }

    if (($asset['source_type'] ?? '') === 'asset_image' && !empty($asset['source_id'])) {
        return url('/company-assets/' . (int) $asset['source_id']);
    }

    return null;
}

function file_asset_preview_url(array $asset): ?string
{
    if (!starts_with((string) ($asset['mime_type'] ?? ''), 'image/')) {
        return null;
    }

    $relativePath = (string) ($asset['relative_path'] ?? '');

    if (!starts_with($relativePath, 'uploads/items/') && !starts_with($relativePath, 'uploads/assets/')) {
        return null;
    }

    if (!file_asset_exists($asset)) {
        return null;
    }

    if (starts_with($relativePath, 'uploads/assets/')) {
        return url('/uploads/assets/' . rawurlencode(basename($relativePath)));
    }

    return url('/uploads/items/' . rawurlencode(basename($relativePath)));
}

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

function item_image_url(?string $imagePath): ?string
{
    $imagePath = trim((string) $imagePath);

    if ($imagePath === '') {
        return null;
    }

    $fullPath = item_upload_directory() . '/' . basename($imagePath);

    if (!is_file($fullPath)) {
        return null;
    }

    return url('/uploads/items/' . rawurlencode(basename($imagePath)));
}

function asset_image_url(?string $imagePath): ?string
{
    $imagePath = trim((string) $imagePath);

    if ($imagePath === '') {
        return null;
    }

    $fullPath = asset_upload_directory() . '/' . basename($imagePath);

    if (!is_file($fullPath)) {
        return null;
    }

    return url('/uploads/assets/' . rawurlencode(basename($imagePath)));
}

function item_xlsx_thumbnail_export_enabled(): bool
{
    return site_setting('exports.item_xlsx_thumbnails', '1') === '1';
}

function asset_xlsx_thumbnail_export_enabled(): bool
{
    return site_setting('exports.asset_xlsx_thumbnails', '1') === '1';
}

function storage_xlsx_thumbnail_export_enabled(): bool
{
    return site_setting('exports.storage_xlsx_thumbnails', '1') === '1';
}

function movement_xlsx_thumbnail_export_enabled(): bool
{
    return site_setting('exports.movement_xlsx_thumbnails', '1') === '1';
}

function report_xlsx_thumbnail_export_enabled(): bool
{
    return site_setting('exports.report_xlsx_thumbnails', '1') === '1';
}

function excel_export_barcode_images_enabled(): bool
{
    return site_setting('exports.excel_barcode_images', '1') === '1';
}
