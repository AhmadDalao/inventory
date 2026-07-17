<?php
declare(strict_types=1);

// Domain module: purchase received-quantity reporting.

function handle_purchases_receive_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('purchases.receive');
    verify_csrf();

    $purchase = find_purchase_or_abort((int) $params['id']);
    $user = Auth::user();

    if ((string) $purchase['status'] !== 'approved') {
        flash('danger', 'Only approved purchases can be received.');
        redirect('/purchases/' . $purchase['id']);
    }

    $receivedQuantities = input('received_quantity', []);
    $receiptNotes = trim((string) input('receipt_notes'));
    $lines = purchase_lines((int) $purchase['id']);
    $errors = [];

    foreach ($lines as $line) {
        $lineId = (int) $line['id'];
        $quantityRaw = $receivedQuantities[$lineId] ?? '';

        if (!is_numeric_value($quantityRaw) || quantity_value($quantityRaw) < 0) {
            $errors[] = 'Received quantities must be valid zero-or-higher numbers.';
            continue;
        }

        if (quantity_value($quantityRaw) > (float) $line['quantity_approved']) {
            $errors[] = 'Received quantity cannot be higher than the approved quantity.';
        }
    }

    foreach (uploaded_files('documents') as $file) {
        $documentError = validate_purchase_document_upload($file);

        if ($documentError !== null) {
            $errors[] = $documentError;
        }
    }

    if ($errors !== []) {
        flash_errors($errors);
        redirect('/purchases/' . $purchase['id']);
    }

    $storedDocuments = [];
    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        foreach ($lines as $line) {
            $lineId = (int) $line['id'];
            Database::execute(
                'UPDATE purchase_lines
                 SET quantity_received = :quantity_received,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'quantity_received' => round(quantity_value($receivedQuantities[$lineId] ?? 0), 2),
                    'id' => $lineId,
                ]
            );
        }

        $storedDocuments = save_purchase_documents((int) $purchase['id'], (string) $purchase['purchase_number'], uploaded_files('documents'), (string) input('document_type', 'receipt'), (int) $user['id']);

        Database::execute(
            'UPDATE purchases
             SET status = "receipt_review",
                 receiver_user_id = :receiver_user_id,
                 receipt_reported_at = NOW(),
                 receipt_notes = :receipt_notes,
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'receiver_user_id' => (int) $user['id'],
                'receipt_notes' => $receiptNotes !== '' ? $receiptNotes : null,
                'updated_by' => (int) $user['id'],
                'id' => $purchase['id'],
            ]
        );

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        foreach ($storedDocuments as $filename) {
            delete_purchase_document_file($filename);
        }

        flash('danger', $exception->getMessage());
        redirect('/purchases/' . $purchase['id']);
    }

    create_notification(
        (int) $purchase['approver_user_id'],
        'purchase_receipt_reported',
        'Purchase receipt needs review',
        ($user['name'] ?? 'A user') . ' reported received quantities for ' . $purchase['purchase_number'] . '.',
        url('/purchases/' . $purchase['id']),
        'purchase',
        (int) $purchase['id'],
        (int) $user['id']
    );

    flash('success', 'Receipt reported. Waiting for approver confirmation.');
    redirect('/purchases/' . $purchase['id']);
}
