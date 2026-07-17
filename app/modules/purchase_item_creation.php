<?php
declare(strict_types=1);

// Catalog item creation from approved purchase lines.

function create_purchase_item_from_line(array $line, int $storageId, int $userId): int
{
    if (!empty($line['item_id'])) {
        return (int) $line['item_id'];
    }

    $barcode = normalize_item_barcode($line['item_barcode'] ?? '');

    if (item_barcodes_required() && $barcode === '') {
        throw new RuntimeException('Barcode is required before this purchase line can create a new catalog item.');
    }

    if ($barcode !== '' && active_item_barcode_exists($barcode)) {
        throw new RuntimeException('Barcode ' . $barcode . ' already belongs to an active item.');
    }

    Database::execute(
        'INSERT INTO items (
            name,
            sku,
            barcode,
            category,
            storage_id,
            unit,
            current_quantity,
            reorder_level,
            cost_per_unit,
            image_path,
            notes,
            is_active,
            created_by,
            updated_by,
            created_at,
            updated_at
         ) VALUES (
            :name,
            :sku,
            :barcode,
            :category,
            NULL,
            :unit,
            0,
            0,
            :cost_per_unit,
            :image_path,
            :notes,
            1,
            :created_by,
            :updated_by,
            NOW(),
            NOW()
         )',
        [
            'name' => $line['item_name'],
            'sku' => $line['item_sku'],
            'barcode' => $barcode !== '' ? $barcode : null,
            'category' => $line['item_category'] ?: null,
            'unit' => $line['unit'],
            'cost_per_unit' => (float) ($line['unit_cost_approved'] ?: $line['unit_cost_quoted']),
            'image_path' => $line['item_image_path'] ?: null,
            'notes' => $line['item_notes'] ?: null,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]
    );

    return Database::lastInsertId();
}
