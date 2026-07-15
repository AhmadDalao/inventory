<?php
declare(strict_types=1);

// Domain module: suppliers. Function names are preserved for route/view compatibility.

// Moved from workflows.php.

function supplier_filters(): array
{
    $status = (string) query('status', 'all');

    return [
        'search' => trim((string) query('search', '')),
        'status' => in_array($status, ['active', 'archived', 'all'], true) ? $status : 'all',
    ];
}

function supplier_summary_rows(array $filters): array
{
    [$where, $params] = build_supplier_where($filters);

    return Database::fetchAll(
        "SELECT supplier.*,
                creator.name AS creator_name,
                COALESCE(purchase_totals.purchase_count, 0) AS purchase_count,
                COALESCE(purchase_totals.completed_count, 0) AS completed_count,
                COALESCE(purchase_totals.total_value, 0) AS total_value,
                purchase_totals.last_purchase_at
         FROM suppliers supplier
         LEFT JOIN users creator ON creator.id = supplier.created_by
         LEFT JOIN (
             SELECT p.supplier_id,
                    COUNT(*) AS purchase_count,
                    SUM(CASE WHEN p.status = 'completed' THEN 1 ELSE 0 END) AS completed_count,
                    COALESCE(SUM(CASE WHEN p.status = 'completed' THEN line_totals.received_total ELSE 0 END), 0) AS total_value,
                    MAX(p.created_at) AS last_purchase_at
             FROM purchases p
             LEFT JOIN (
                 SELECT purchase_id,
                        COALESCE(SUM(quantity_final * unit_cost_approved), 0) AS received_total
                 FROM purchase_lines
                 GROUP BY purchase_id
             ) line_totals ON line_totals.purchase_id = p.id
             GROUP BY p.supplier_id
         ) purchase_totals ON purchase_totals.supplier_id = supplier.id
         {$where}
         ORDER BY supplier.is_active DESC, supplier.name ASC",
        $params
    );
}

function find_supplier_or_abort(int $supplierId): array
{
    $supplier = Database::fetch(
        'SELECT supplier.*,
                creator.name AS creator_name,
                updater.name AS updater_name
         FROM suppliers supplier
         LEFT JOIN users creator ON creator.id = supplier.created_by
         LEFT JOIN users updater ON updater.id = supplier.updated_by
         WHERE supplier.id = :id
         LIMIT 1',
        ['id' => $supplierId]
    );

    if (!$supplier) {
        abort(404, 'Supplier not found.');
    }

    return $supplier;
}

function supplier_purchase_history(int $supplierId): array
{
    return Database::fetchAll(
        'SELECT p.id,
                p.purchase_number,
                p.status,
                p.currency,
                p.created_at,
                p.completed_at,
                storage.name AS storage_name,
                COALESCE(line_totals.total_value, 0) AS total_value,
                COALESCE(line_totals.total_quantity, 0) AS total_quantity
         FROM purchases p
         INNER JOIN storages storage ON storage.id = p.destination_storage_id
         LEFT JOIN (
             SELECT purchase_id,
                    COALESCE(SUM(quantity_final * unit_cost_approved), 0) AS total_value,
                    COALESCE(SUM(quantity_final), 0) AS total_quantity
             FROM purchase_lines
             GROUP BY purchase_id
         ) line_totals ON line_totals.purchase_id = p.id
         WHERE p.supplier_id = :supplier_id
         ORDER BY p.created_at DESC, p.id DESC
         LIMIT 50',
        ['supplier_id' => $supplierId]
    );
}

function active_supplier_name_exists(string $name, ?int $ignoreId = null): bool
{
    $sql = 'SELECT COUNT(*) FROM suppliers WHERE LOWER(name) = LOWER(:name) AND is_active = 1';
    $params = ['name' => trim($name)];

    if ($ignoreId !== null) {
        $sql .= ' AND id != :id';
        $params['id'] = $ignoreId;
    }

    return (int) Database::scalar($sql, $params) > 0;
}

function supplier_form_payload(?array $supplier = null): array
{
    return [
        'id' => $supplier['id'] ?? null,
        'name' => old('name', (string) ($supplier['name'] ?? '')),
        'supplier_type' => old('supplier_type', (string) ($supplier['supplier_type'] ?? 'product')),
        'supplier_type_other' => old('supplier_type_other', (string) ($supplier['supplier_type_other'] ?? '')),
        'phone' => old('phone', (string) ($supplier['phone'] ?? '')),
        'email' => old('email', (string) ($supplier['email'] ?? '')),
        'tax_number' => old('tax_number', (string) ($supplier['tax_number'] ?? '')),
        'commercial_registration' => old('commercial_registration', (string) ($supplier['commercial_registration'] ?? '')),
        'national_address' => old('national_address', (string) ($supplier['national_address'] ?? '')),
        'authorized_person' => old('authorized_person', (string) ($supplier['authorized_person'] ?? '')),
        'notes' => old('notes', (string) ($supplier['notes'] ?? '')),
        'is_active' => (int) ($supplier['is_active'] ?? 1),
    ];
}

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

function supplier_payload_from_request(?array $supplier = null): array
{
    $payload = [
        'name' => trim((string) input('name')),
        'supplier_type' => trim((string) input('supplier_type', 'product')),
        'supplier_type_other' => trim((string) input('supplier_type_other')),
        'phone' => trim((string) input('phone')),
        'email' => strtolower(trim((string) input('email'))),
        'tax_number' => strtoupper(trim((string) input('tax_number'))),
        'commercial_registration' => strtoupper(trim((string) input('commercial_registration'))),
        'national_address' => trim((string) input('national_address')),
        'authorized_person' => trim((string) input('authorized_person')),
        'notes' => trim((string) input('notes')),
    ];

    flash_old_input($payload);

    $errors = [];

    if ($payload['name'] === '') {
        $errors[] = 'Supplier name is required.';
    }

    if (!array_key_exists($payload['supplier_type'], supplier_type_options())) {
        $errors[] = 'Supplier type is required.';
    }

    if ($payload['supplier_type'] === 'other' && $payload['supplier_type_other'] === '') {
        $errors[] = 'Write the custom supplier type when choosing Other.';
    }

    if ($payload['supplier_type'] !== 'other') {
        $payload['supplier_type_other'] = '';
    }

    if ($payload['phone'] === '') {
        $errors[] = 'Supplier phone number is required.';
    }

    if ($payload['national_address'] === '') {
        $errors[] = 'National address is required.';
    }

    if ($payload['authorized_person'] === '') {
        $errors[] = 'Authorized person name is required.';
    }

    if ($payload['email'] !== '' && !filter_var($payload['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Supplier email is not valid.';
    }

    if ($payload['name'] !== '' && active_supplier_name_exists($payload['name'], $supplier ? (int) $supplier['id'] : null)) {
        $errors[] = 'An active supplier already uses this name.';
    }

    return [$payload, $errors];
}

function handle_suppliers_create_submit(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('suppliers.create');
    verify_csrf();

    [$payload, $errors] = supplier_payload_from_request();

    if ($errors !== []) {
        flash_errors($errors);
        redirect('/suppliers/create');
    }

    $user = Auth::user();
    Database::execute(
        'INSERT INTO suppliers (name, supplier_type, supplier_type_other, phone, email, tax_number, commercial_registration, national_address, authorized_person, notes, is_active, created_by, updated_by, created_at, updated_at)
         VALUES (:name, :supplier_type, :supplier_type_other, :phone, :email, :tax_number, :commercial_registration, :national_address, :authorized_person, :notes, 1, :created_by, :updated_by, NOW(), NOW())',
        [
            'name' => $payload['name'],
            'supplier_type' => $payload['supplier_type'],
            'supplier_type_other' => $payload['supplier_type_other'] !== '' ? $payload['supplier_type_other'] : null,
            'phone' => $payload['phone'],
            'email' => $payload['email'] !== '' ? $payload['email'] : null,
            'tax_number' => $payload['tax_number'] !== '' ? $payload['tax_number'] : null,
            'commercial_registration' => $payload['commercial_registration'] !== '' ? $payload['commercial_registration'] : null,
            'national_address' => $payload['national_address'],
            'authorized_person' => $payload['authorized_person'],
            'notes' => $payload['notes'] !== '' ? $payload['notes'] : null,
            'created_by' => (int) $user['id'],
            'updated_by' => (int) $user['id'],
        ]
    );
    $supplierId = Database::lastInsertId();

    consume_old_input();
    record_activity('supplier.created', 'supplier', $supplierId, 'Created supplier ' . $payload['name']);
    flash('success', 'Supplier created.');
    redirect('/suppliers/' . $supplierId);
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

function handle_suppliers_edit_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('suppliers.edit');
    verify_csrf();

    $supplier = find_supplier_or_abort((int) $params['id']);
    [$payload, $errors] = supplier_payload_from_request($supplier);

    if ($errors !== []) {
        flash_errors($errors);
        redirect('/suppliers/' . $supplier['id'] . '/edit');
    }

    Database::execute(
        'UPDATE suppliers
         SET name = :name,
             supplier_type = :supplier_type,
             supplier_type_other = :supplier_type_other,
             phone = :phone,
             email = :email,
             tax_number = :tax_number,
             commercial_registration = :commercial_registration,
             national_address = :national_address,
             authorized_person = :authorized_person,
             notes = :notes,
             updated_by = :updated_by,
             updated_at = NOW()
         WHERE id = :id',
        [
            'name' => $payload['name'],
            'supplier_type' => $payload['supplier_type'],
            'supplier_type_other' => $payload['supplier_type_other'] !== '' ? $payload['supplier_type_other'] : null,
            'phone' => $payload['phone'],
            'email' => $payload['email'] !== '' ? $payload['email'] : null,
            'tax_number' => $payload['tax_number'] !== '' ? $payload['tax_number'] : null,
            'commercial_registration' => $payload['commercial_registration'] !== '' ? $payload['commercial_registration'] : null,
            'national_address' => $payload['national_address'],
            'authorized_person' => $payload['authorized_person'],
            'notes' => $payload['notes'] !== '' ? $payload['notes'] : null,
            'updated_by' => (int) (Auth::user()['id'] ?? 0),
            'id' => (int) $supplier['id'],
        ]
    );

    consume_old_input();
    record_activity('supplier.updated', 'supplier', (int) $supplier['id'], 'Updated supplier ' . $payload['name']);
    flash('success', 'Supplier updated.');
    redirect('/suppliers/' . $supplier['id']);
}

function handle_suppliers_status_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('suppliers.archive');
    verify_csrf();

    $supplier = find_supplier_or_abort((int) $params['id']);
    $nextStatus = (int) $supplier['is_active'] === 1 ? 0 : 1;

    Database::execute(
        'UPDATE suppliers
         SET is_active = :is_active,
             updated_by = :updated_by,
             updated_at = NOW()
         WHERE id = :id',
        [
            'is_active' => $nextStatus,
            'updated_by' => (int) (Auth::user()['id'] ?? 0),
            'id' => (int) $supplier['id'],
        ]
    );

    record_activity($nextStatus ? 'supplier.restored' : 'supplier.archived', 'supplier', (int) $supplier['id'], ($nextStatus ? 'Recovered ' : 'Archived ') . $supplier['name']);
    flash('success', $nextStatus ? 'Supplier recovered.' : 'Supplier archived.');
    redirect('/suppliers');
}
