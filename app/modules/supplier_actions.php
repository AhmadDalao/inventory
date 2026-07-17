<?php
declare(strict_types=1);

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
