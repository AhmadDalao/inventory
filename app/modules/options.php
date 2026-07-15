<?php
declare(strict_types=1);

// Domain module: option catalogs, status labels, and small presentation helpers.
// Function names are preserved for route/view compatibility.

function user_role_options(): array
{
    return [
        'admin' => 'Admin',
        'staff' => 'Staff',
    ];
}

function user_role_label(string $role): string
{
    switch ($role) {
        case 'owner':
            return 'Owner';
        case 'admin':
            return 'Admin';
        case 'staff':
            return 'Staff';
        default:
            return ucfirst($role);
    }
}

function user_position_options(): array
{
    return [
        'owner_operator' => 'Owner / General Manager',
        'cfo' => 'CFO',
        'accountant' => 'Accountant',
        'operations_manager' => 'Operations Manager',
        'storage_manager' => 'Storage Manager',
        'reception_staff' => 'Reception Staff',
        'general_admin' => 'General Admin',
        'staff' => 'Staff',
    ];
}

function user_position_label(?string $position, string $role = ''): string
{
    $position = trim((string) $position);
    $options = user_position_options();

    if ($position !== '' && isset($options[$position])) {
        return $options[$position];
    }

    if ($role === 'owner') {
        return $options['owner_operator'];
    }

    if ($role === 'admin') {
        return $options['general_admin'];
    }

    return $options['staff'];
}

function user_initials(?string $name): string
{
    $name = trim((string) $name);

    if ($name === '') {
        return 'U';
    }

    $parts = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];

    if (count($parts) === 1) {
        return strtoupper(substr((string) $parts[0], 0, 2));
    }

    return strtoupper(substr((string) $parts[0], 0, 1) . substr((string) end($parts), 0, 1));
}

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

function access_role_for_position(string $position): string
{
    switch ($position) {
        case 'reception_staff':
        case 'staff':
            return 'staff';
        case 'owner_operator':
        case 'cfo':
        case 'accountant':
        case 'operations_manager':
        case 'storage_manager':
        case 'general_admin':
        default:
            return 'admin';
    }
}

function request_status_label(string $status): string
{
    switch ($status) {
        case 'draft':
            return 'Draft';
        case 'pending':
            return 'Pending';
        case 'approved':
            return 'Approved';
        case 'receipt_review':
            return 'Receipt Review';
        case 'completed':
            return 'Completed';
        case 'rejected':
            return 'Rejected';
        case 'cancelled':
            return 'Cancelled';
        default:
            return ucwords(str_replace('_', ' ', $status));
    }
}

function handover_status_label(string $status): string
{
    switch ($status) {
        case 'requested':
            return 'Requested';
        case 'awaiting_receipt':
            return 'Awaiting Receipt';
        case 'receipt_review':
            return 'Receipt Review';
        case 'delivered':
            return 'Delivered';
        case 'pending_approval':
            return 'Waiting Approval';
        case 'closed':
            return 'Closed';
        case 'rejected':
            return 'Rejected';
        case 'cancelled':
            return 'Cancelled';
        default:
            return ucwords(str_replace('_', ' ', $status));
    }
}

function handover_status_options(): array
{
    return [
        'requested' => 'Requested',
        'awaiting_receipt' => 'Awaiting Receipt',
        'receipt_review' => 'Receipt Review',
        'delivered' => 'Delivered',
        'pending_approval' => 'Waiting Approval',
        'closed' => 'Closed',
        'rejected' => 'Rejected',
        'cancelled' => 'Cancelled',
    ];
}

function purchase_status_label(string $status): string
{
    switch ($status) {
        case 'draft':
            return 'Draft';
        case 'pending_approval':
            return 'Waiting Approval';
        case 'approved':
            return 'Approved';
        case 'receipt_review':
            return 'Receipt Review';
        case 'completed':
            return 'Completed';
        case 'rejected':
            return 'Rejected';
        case 'cancelled':
            return 'Cancelled';
        default:
            return ucwords(str_replace('_', ' ', $status));
    }
}

function purchase_status_badge_type(string $status): string
{
    switch ($status) {
        case 'approved':
        case 'completed':
            return 'success';
        case 'pending_approval':
        case 'receipt_review':
            return 'warning';
        case 'rejected':
        case 'cancelled':
            return 'danger';
        case 'draft':
        default:
            return 'muted';
    }
}

function stocktake_status_label(string $status): string
{
    switch ($status) {
        case 'draft':
            return 'Draft';
        case 'pending_approval':
            return 'Waiting Approval';
        case 'approved':
            return 'Approved';
        case 'cancelled':
            return 'Cancelled';
        default:
            return ucwords(str_replace('_', ' ', $status));
    }
}

function stocktake_status_badge_type(string $status): string
{
    switch ($status) {
        case 'approved':
            return 'success';
        case 'pending_approval':
            return 'warning';
        case 'cancelled':
            return 'danger';
        case 'draft':
        default:
            return 'muted';
    }
}

function movement_type_options(): array
{
    return [
        'usage' => 'Usage',
        'restock' => 'Restock',
        'transfer' => 'Transfer',
        'adjustment' => 'Adjustment',
    ];
}

function movement_type_permission(string $movementType): ?string
{
    switch ($movementType) {
        case 'usage':
            return 'movements.usage';
        case 'restock':
            return 'movements.restock';
        case 'transfer':
            return 'movements.transfer';
        case 'adjustment':
            return 'movements.adjustment';
        default:
            return null;
    }
}

function can_create_movement_type(string $movementType): bool
{
    $permission = movement_type_permission($movementType);

    if ($permission === null) {
        return false;
    }

    return Auth::hasPermission('movements.create') || Auth::hasPermission($permission);
}

function movement_type_options_for_user(?array $allowedTypes = null): array
{
    $types = movement_type_options();

    if ($allowedTypes !== null) {
        $allowedMap = array_fill_keys($allowedTypes, true);
        $types = array_filter(
            $types,
            static fn (string $type): bool => isset($allowedMap[$type]),
            ARRAY_FILTER_USE_KEY
        );
    }

    return array_filter(
        $types,
        static fn (string $type): bool => can_create_movement_type($type),
        ARRAY_FILTER_USE_KEY
    );
}

function asset_status_options(): array
{
    return [
        'available' => 'Available',
        'pending_receipt' => 'Pending Receipt',
        'assigned' => 'Assigned',
	        'return_requested' => 'Return Requested',
	        'damaged' => 'Damaged',
	        'maintenance' => 'Maintenance',
	        'lost' => 'Lost',
        'retired' => 'Retired',
    ];
}

function asset_status_label(?string $status): string
{
    $status = trim((string) $status);
    $options = asset_status_options();

    return $options[$status] ?? ucwords(str_replace('_', ' ', $status !== '' ? $status : 'available'));
}

function asset_status_tone(?string $status): string
{
    switch ((string) $status) {
        case 'available':
            return 'success';
        case 'pending_receipt':
        case 'return_requested':
            return 'warning';
        case 'assigned':
            return 'info';
	        case 'maintenance':
	            return 'muted';
	        case 'damaged':
	        case 'lost':
	        case 'retired':
	            return 'danger';
        default:
            return 'muted';
    }
}

function asset_condition_options(): array
{
    return [
        'new' => 'New',
        'good' => 'Good',
        'fair' => 'Fair',
        'damaged' => 'Damaged',
        'lost' => 'Lost',
        'retired' => 'Retired',
    ];
}

function asset_condition_label(?string $condition): string
{
    $condition = trim((string) $condition);
    $options = asset_condition_options();

    return $options[$condition] ?? ucwords(str_replace('_', ' ', $condition !== '' ? $condition : 'good'));
}

function asset_action_type_label(?string $actionType): string
{
    $actionType = trim((string) $actionType);

    return [
        'assign' => 'Assignment',
        'receive' => 'Receipt',
        'return_request' => 'Return Request',
        'return_confirm' => 'Return Confirmed',
        'transfer' => 'Transfer',
        'damage' => 'Damage',
        'lost' => 'Lost',
        'maintenance_start' => 'Maintenance Started',
        'maintenance_complete' => 'Maintenance Completed',
        'retire' => 'Retired',
        'override' => 'Status Override',
    ][$actionType] ?? ucwords(str_replace('_', ' ', $actionType !== '' ? $actionType : 'Action'));
}

function item_unit_options(): array
{
    return [
        'pcs' => 'Pieces (pcs)',
        'box' => 'Box',
        'pack' => 'Pack',
        'carton' => 'Carton',
        'set' => 'Set',
        'roll' => 'Roll',
        'bottle' => 'Bottle',
        'kg' => 'Kilogram (kg)',
        'g' => 'Gram (g)',
        'liter' => 'Liter',
        'ml' => 'Milliliter (ml)',
        'meter' => 'Meter',
        'custom' => 'Custom',
    ];
}

function is_known_unit(string $unit): bool
{
    return array_key_exists($unit, item_unit_options()) && $unit !== 'custom';
}

function item_unit_form_state(?string $storedUnit): array
{
    $storedUnit = trim((string) $storedUnit);

    if ($storedUnit === '' || is_known_unit($storedUnit)) {
        return [
            'unit' => $storedUnit !== '' ? $storedUnit : 'pcs',
            'custom_unit' => '',
        ];
    }

    return [
        'unit' => 'custom',
        'custom_unit' => $storedUnit,
    ];
}

function resolve_item_unit(string $selectedUnit, string $customUnit): string
{
    $selectedUnit = trim($selectedUnit);
    $customUnit = trim($customUnit);

    if ($selectedUnit === 'custom') {
        return $customUnit;
    }

    if (is_known_unit($selectedUnit)) {
        return $selectedUnit;
    }

    return '';
}

function item_barcodes_required(): bool
{
    return site_setting('items.barcode_required', '0') === '1';
}

function scan_manual_restock_enabled(): bool
{
    return site_setting('scan.manual_restock_enabled', '1') === '1';
}

function normalize_item_barcode($value): string
{
    $barcode = trim((string) $value);
    $barcode = preg_replace('/[\x00-\x1F\x7F]+/', '', $barcode) ?: '';

    return mb_substr($barcode, 0, 120);
}

function item_scan_code(array $item): string
{
    $barcode = normalize_item_barcode($item['barcode'] ?? '');

    return $barcode !== '' ? $barcode : (string) ($item['sku'] ?? '');
}

function reports_can_access(): bool
{
    foreach ([
        'items.export',
        'movements.export',
        'storages.export',
        'requests.export',
        'handovers.export',
        'assets.export',
        'purchases.export',
        'files.export',
        'stocktakes.export',
        'suppliers.export',
        'reorder.export',
        'audit.export',
        'email_logs.export',
        'users.export',
    ] as $permission) {
        if (Auth::hasPermission($permission)) {
            return true;
        }
    }

    return false;
}
