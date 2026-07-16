<?php
declare(strict_types=1);

// Domain module: purchase submission, show, and history handlers.
// Function names are preserved for route/view compatibility.
function handle_purchases_create_submit(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('purchases.create');
    verify_csrf();

    $purchaseId = persist_purchase_from_request();
    redirect('/purchases/' . $purchaseId);
}

function handle_purchases_edit_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('purchases.create');
    verify_csrf();

    $purchase = find_purchase_or_abort((int) $params['id']);

    if ((string) $purchase['status'] !== 'draft') {
        flash('danger', 'Only draft purchases can be edited.');
        redirect('/purchases/' . $purchase['id']);
    }

    $purchaseId = persist_purchase_from_request($purchase);
    redirect('/purchases/' . $purchaseId);
}

function handle_purchases_show(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('purchases.view');

    $purchase = find_purchase_or_abort((int) $params['id']);
    $lines = purchase_lines((int) $purchase['id']);
    $documents = purchase_documents((int) $purchase['id']);

    View::render('purchases/show', [
        'title' => $purchase['purchase_number'],
        'purchase' => $purchase,
        'lines' => $lines,
        'documents' => $documents,
        'documentTypes' => purchase_document_type_options(),
    ]);
}

function handle_purchases_submit_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('purchases.create');
    verify_csrf();

    $purchase = find_purchase_or_abort((int) $params['id']);
    $user = Auth::user();

    if ((string) $purchase['status'] !== 'draft') {
        flash('danger', 'Only draft purchases can be submitted.');
        redirect('/purchases/' . $purchase['id']);
    }

    if (!purchase_submit_ready((int) $purchase['id'])) {
        flash('danger', 'Attach at least one quote, price list, receipt, or proof file before submitting.');
        redirect('/purchases/' . $purchase['id']);
    }

    Database::execute(
        'UPDATE purchases
         SET status = "pending_approval",
             submitted_at = NOW(),
             updated_by = :updated_by,
             updated_at = NOW()
         WHERE id = :id',
        [
            'updated_by' => (int) $user['id'],
            'id' => $purchase['id'],
        ]
    );

    create_notification(
        (int) $purchase['approver_user_id'],
        'purchase_submitted',
        'Purchase approval needed',
        ($user['name'] ?? 'A user') . ' submitted ' . $purchase['purchase_number'] . ' for supplier approval.',
        url('/purchases/' . $purchase['id']),
        'purchase',
        (int) $purchase['id'],
        (int) $user['id']
    );

    flash('success', 'Purchase submitted for approval.');
    redirect('/purchases/' . $purchase['id']);
}

function purchase_history_for_item(int $itemId, int $limit = 10): array
{
    if (!Auth::hasPermission('purchases.view')) {
        return [];
    }

    return Database::fetchAll(
        'SELECT p.id,
                p.purchase_number,
                p.status,
                p.currency,
                p.completed_at,
                supplier.name AS supplier_name,
                storage.name AS storage_name,
                pl.quantity_final,
                pl.unit_cost_approved
         FROM purchase_lines pl
         INNER JOIN purchases p ON p.id = pl.purchase_id
         INNER JOIN suppliers supplier ON supplier.id = p.supplier_id
         INNER JOIN storages storage ON storage.id = p.destination_storage_id
         WHERE pl.item_id = :item_id
         ORDER BY COALESCE(p.completed_at, p.created_at) DESC, p.id DESC
         LIMIT ' . (int) $limit,
        ['item_id' => $itemId]
    );
}

function purchase_history_for_storage(int $storageId, int $limit = 10): array
{
    if (!Auth::hasPermission('purchases.view')) {
        return [];
    }

    return Database::fetchAll(
        'SELECT p.id,
                p.purchase_number,
                p.status,
                p.currency,
                p.completed_at,
                supplier.name AS supplier_name,
                COALESCE(SUM(pl.quantity_final * pl.unit_cost_approved), 0) AS total_value,
                COALESCE(SUM(pl.quantity_final), 0) AS total_quantity
         FROM purchases p
         INNER JOIN suppliers supplier ON supplier.id = p.supplier_id
         INNER JOIN purchase_lines pl ON pl.purchase_id = p.id
         WHERE p.destination_storage_id = :storage_id
         GROUP BY p.id, p.purchase_number, p.status, p.currency, p.completed_at, supplier.name, p.created_at
         ORDER BY COALESCE(p.completed_at, p.created_at) DESC, p.id DESC
         LIMIT ' . (int) $limit,
        ['storage_id' => $storageId]
    );
}
