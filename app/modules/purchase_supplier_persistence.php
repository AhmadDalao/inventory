<?php
declare(strict_types=1);

// Supplier create/link behavior used while saving purchase drafts.

function persist_supplier_from_purchase_payload(array $payload, int $userId): int
{
    if (!empty($payload['supplier_id'])) {
        $supplier = Database::fetch(
            'SELECT id FROM suppliers WHERE id = :id AND is_active = 1 LIMIT 1',
            ['id' => (int) $payload['supplier_id']]
        );

        if ($supplier) {
            return (int) $supplier['id'];
        }
    }

    $existingSupplier = Database::fetch(
        'SELECT id FROM suppliers WHERE is_active = 1 AND LOWER(name) = LOWER(:name) LIMIT 1',
        ['name' => $payload['supplier_name']]
    );

    if ($existingSupplier) {
        return (int) $existingSupplier['id'];
    }

    $supplierType = array_key_exists((string) ($payload['supplier_type'] ?? ''), supplier_type_options()) ? (string) $payload['supplier_type'] : 'product';
    $supplierTypeOther = $supplierType === 'other' ? trim((string) ($payload['supplier_type_other'] ?? '')) : '';

    Database::execute(
        'INSERT INTO suppliers (name, supplier_type, supplier_type_other, phone, email, tax_number, commercial_registration, national_address, authorized_person, notes, is_active, created_by, updated_by, created_at, updated_at)
         VALUES (:name, :supplier_type, :supplier_type_other, :phone, :email, :tax_number, :commercial_registration, :national_address, :authorized_person, :notes, 1, :created_by, :updated_by, NOW(), NOW())',
        [
            'name' => $payload['supplier_name'],
            'supplier_type' => $supplierType,
            'supplier_type_other' => $supplierTypeOther !== '' ? $supplierTypeOther : null,
            'phone' => trim((string) ($payload['supplier_phone'] ?? '')),
            'email' => $payload['supplier_email'] !== '' ? $payload['supplier_email'] : null,
            'tax_number' => $payload['supplier_tax_number'] !== '' ? $payload['supplier_tax_number'] : null,
            'commercial_registration' => trim((string) ($payload['supplier_commercial_registration'] ?? '')) !== '' ? strtoupper(trim((string) $payload['supplier_commercial_registration'])) : null,
            'national_address' => trim((string) ($payload['supplier_national_address'] ?? '')),
            'authorized_person' => trim((string) ($payload['supplier_authorized_person'] ?? '')),
            'notes' => $payload['supplier_notes'] !== '' ? $payload['supplier_notes'] : null,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]
    );

    return Database::lastInsertId();
}
