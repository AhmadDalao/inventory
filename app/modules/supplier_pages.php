<?php
declare(strict_types=1);

function handle_suppliers_index(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('suppliers.view');

    $filters = supplier_filters();
    $counts = [
        'active' => (int) Database::scalar('SELECT COUNT(*) FROM suppliers WHERE is_active = 1'),
        'archived' => (int) Database::scalar('SELECT COUNT(*) FROM suppliers WHERE is_active = 0'),
    ];

    View::render('suppliers/index', [
        'title' => site_setting('page.suppliers', 'Suppliers'),
        'suppliers' => supplier_summary_rows($filters),
        'filters' => $filters,
        'counts' => $counts,
    ]);
}

function handle_suppliers_create_page(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('suppliers.create');

    View::render('suppliers/form', [
        'title' => 'Create Supplier',
        'mode' => 'create',
        'supplier' => supplier_form_payload(),
    ]);
}

function handle_suppliers_show(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('suppliers.view');

    $supplier = find_supplier_or_abort((int) $params['id']);

    View::render('suppliers/show', [
        'title' => $supplier['name'],
        'supplier' => $supplier,
        'purchaseHistory' => supplier_purchase_history((int) $supplier['id']),
    ]);
}

function handle_suppliers_edit_page(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('suppliers.edit');

    $supplier = find_supplier_or_abort((int) $params['id']);

    View::render('suppliers/form', [
        'title' => 'Edit ' . $supplier['name'],
        'mode' => 'edit',
        'supplier' => supplier_form_payload($supplier),
    ]);
}
