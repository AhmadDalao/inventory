<?php
declare(strict_types=1);

// Domain module: purchase documents and protected purchase file actions.

function purchase_document_type_options(): array
{
    return [
        'quote' => 'Quote',
        'price_list' => 'Price List',
        'receipt' => 'Receipt',
        'proof' => 'Proof of Purchase',
        'other' => 'Other',
    ];
}

function purchase_documents(int $purchaseId): array
{
    return Database::fetchAll(
        'SELECT documents.*,
                uploader.name AS uploader_name
         FROM purchase_documents documents
         LEFT JOIN users uploader ON uploader.id = documents.uploaded_by
         WHERE documents.purchase_id = :purchase_id
         ORDER BY documents.created_at DESC, documents.id DESC',
        ['purchase_id' => $purchaseId]
    );
}

function purchase_document_count(int $purchaseId): int
{
    return (int) Database::scalar(
        'SELECT COUNT(*) FROM purchase_documents WHERE purchase_id = :purchase_id',
        ['purchase_id' => $purchaseId]
    );
}

function save_purchase_documents(int $purchaseId, string $purchaseNumber, array $files, string $documentType, int $userId): array
{
    $stored = [];

    foreach ($files as $file) {
        $error = validate_purchase_document_upload($file);

        if ($error !== null) {
            throw new RuntimeException($error);
        }

        $meta = store_purchase_document($file, $purchaseNumber);
        $stored[] = $meta['stored_filename'];

        Database::execute(
            'INSERT INTO purchase_documents (
                purchase_id,
                purchase_line_id,
                document_type,
                original_filename,
                stored_filename,
                mime_type,
                file_size,
                uploaded_by,
                created_at
             ) VALUES (
                :purchase_id,
                NULL,
                :document_type,
                :original_filename,
                :stored_filename,
                :mime_type,
                :file_size,
                :uploaded_by,
                NOW()
             )',
            [
                'purchase_id' => $purchaseId,
                'document_type' => array_key_exists($documentType, purchase_document_type_options()) ? $documentType : 'proof',
                'original_filename' => $meta['original_filename'],
                'stored_filename' => $meta['stored_filename'],
                'mime_type' => $meta['mime_type'],
                'file_size' => $meta['file_size'],
                'uploaded_by' => $userId,
            ]
        );

        $documentId = Database::lastInsertId();
        register_purchase_document_asset(
            $documentId,
            $purchaseId,
            $purchaseNumber,
            [
                'document_type' => array_key_exists($documentType, purchase_document_type_options()) ? $documentType : 'proof',
                'original_filename' => $meta['original_filename'],
                'stored_filename' => $meta['stored_filename'],
                'mime_type' => $meta['mime_type'],
                'file_size' => $meta['file_size'],
            ],
            $userId
        );
    }

    return $stored;
}

function handle_purchase_document_download(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('purchases.files');

    $document = Database::fetch(
        'SELECT documents.*,
                purchases.id AS purchase_id
         FROM purchase_documents documents
         INNER JOIN purchases ON purchases.id = documents.purchase_id
         WHERE documents.id = :id
         LIMIT 1',
        ['id' => (int) $params['id']]
    );

    if (!$document) {
        abort(404, 'Purchase document not found.');
    }

    find_purchase_or_abort((int) $document['purchase_id']);

    $path = purchase_document_path((string) $document['stored_filename']);

    if (!is_file($path)) {
        abort(404, 'Purchase document file is missing.');
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    send_download_headers((string) $document['mime_type'], (string) $document['original_filename'], (int) filesize($path));
    readfile($path);
    exit;
}

function handle_purchase_document_delete_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('purchases.files');
    verify_csrf();

    $document = Database::fetch(
        'SELECT documents.*,
                purchases.status,
                purchases.id AS purchase_id
         FROM purchase_documents documents
         INNER JOIN purchases ON purchases.id = documents.purchase_id
         WHERE documents.id = :id
         LIMIT 1',
        ['id' => (int) $params['id']]
    );

    if (!$document) {
        abort(404, 'Purchase document not found.');
    }

    $purchase = find_purchase_or_abort((int) $document['purchase_id']);
    $blocked = purchase_draft_management_block_reason($purchase);

    if ($blocked !== null) {
        flash('danger', $blocked);
        redirect('/purchases/' . $document['purchase_id']);
    }

    Database::execute('DELETE FROM purchase_documents WHERE id = :id', ['id' => (int) $document['id']]);
    delete_purchase_document_file((string) $document['stored_filename']);

    flash('success', 'Purchase document deleted.');
    redirect('/purchases/' . $document['purchase_id']);
}
