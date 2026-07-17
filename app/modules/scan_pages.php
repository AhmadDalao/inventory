<?php
declare(strict_types=1);

// Scan Center page handlers and shared access checks.

function handle_scan_index(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('items.view');

    if (Auth::isStaff()) {
        abort(403, 'Staff dashboard is intentionally simplified. Scanner is for inventory operators.');
    }

    $scanMovementTypeOptions = movement_type_options_for_user(['usage', 'restock']);
    $canManualRestock = scan_manual_restock_enabled() && can_create_movement_type('restock');

    View::render('scan/index', [
        'title' => site_setting('page.scan', 'Scan Center'),
        'storages' => all_storages_for_select(),
        'canCreateMovement' => $scanMovementTypeOptions !== [],
        'canManualRestock' => $canManualRestock,
        'scanMovementTypeOptions' => $scanMovementTypeOptions,
    ]);
}

function require_scan_manual_restock_access(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('items.view');

    if (Auth::isStaff()) {
        abort(403, 'Scanner is not available for staff accounts.');
    }

    if (!scan_manual_restock_enabled() || !can_create_movement_type('restock')) {
        abort(403, 'Manual Scan Center stock add is not enabled for your account.');
    }
}

function handle_scan_manual_page(): void
{
    require_scan_manual_restock_access();

    View::render('scan/manual', [
        'title' => 'Manual Stock Add',
        'storages' => all_storages_for_select(),
    ]);
}
