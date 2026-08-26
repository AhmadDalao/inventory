<?php
declare(strict_types=1);

// Domain module: file library pages, downloads, workflow document access, and file exports.

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
                OR (assets.source_type IN ("asset_image", "asset_file") AND company_asset.id = assets.source_id)
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
    [$visibilitySql, $visibilityParams] = file_asset_visibility_condition('assets');
    $rows = Database::fetchAll(
        'SELECT file_group,
                COUNT(*) AS file_count,
                COALESCE(SUM(file_size), 0) AS total_size
         FROM file_assets assets
         WHERE assets.deleted_at IS NULL
           AND ' . $visibilitySql . '
         GROUP BY file_group',
        $visibilityParams
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
    [$visibilitySql, $visibilityParams] = file_asset_visibility_condition('assets');
    $asset = Database::fetch(
        file_asset_select_sql() . '
         WHERE assets.id = :id
           AND ' . $visibilitySql . '
         LIMIT 1',
        ['id' => $id] + $visibilityParams
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
