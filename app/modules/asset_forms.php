<?php
declare(strict_types=1);

// Asset form defaults.
function asset_form_payload(?array $asset = null): array
{
    return array_merge([
        'id' => null,
        'asset_number' => '',
        'name' => '',
        'category_id' => null,
        'category' => '',
        'model' => '',
        'serial_number' => '',
        'barcode' => '',
        'image_path' => '',
        'condition_status' => 'good',
        'status' => 'available',
        'storage_id' => null,
        'assigned_user_id' => null,
        'supplier_id' => null,
        'purchase_id' => null,
        'purchase_date' => '',
        'purchase_cost' => '0.00',
        'depreciation_start_date' => '',
        'useful_life_months' => 60,
        'salvage_value' => '0.00',
        'depreciation_method' => 'straight_line',
        'warranty_expires_at' => '',
        'notes' => '',
        'bulk_quantity' => 1,
    ], $asset ?? []);
}
