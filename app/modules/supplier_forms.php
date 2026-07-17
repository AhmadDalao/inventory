<?php
declare(strict_types=1);

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
