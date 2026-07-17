<?php
declare(strict_types=1);

// Reorder page handlers.

function handle_reorder_index(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('reorder.view');

    $filters = reorder_filters();

    View::render('reorder/index', [
        'title' => site_setting('page.reorder', 'Reorder Center'),
        'filters' => $filters,
        'rows' => reorder_suggestion_rows($filters),
        'storages' => all_storages_for_select($filters['storage_id']),
        'suppliers' => suppliers_for_select(),
        'approvers' => purchase_approvers_for_select(),
    ]);
}
