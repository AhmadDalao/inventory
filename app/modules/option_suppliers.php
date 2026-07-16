<?php
declare(strict_types=1);

// Supplier option and label helpers.

function supplier_type_options(): array
{
    return [
        'product' => 'Product',
        'service' => 'Service',
        'other' => 'Other',
    ];
}

function supplier_type_label(?string $type): string
{
    $type = trim((string) $type);
    $options = supplier_type_options();

    return $options[$type] ?? 'Product';
}

function supplier_type_display(?string $type, ?string $customType = null): string
{
    $type = trim((string) $type);
    $customType = trim((string) $customType);

    if ($type === 'other' && $customType !== '') {
        return $customType;
    }

    return supplier_type_label($type);
}
