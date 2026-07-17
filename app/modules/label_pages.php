<?php
declare(strict_types=1);

// Label page handlers.

function handle_labels_index(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('labels.view');

    $filters = label_filters();

    View::render('labels/index', [
        'title' => site_setting('page.labels', 'Labels'),
        'filters' => $filters,
        'rows' => label_rows($filters),
        'storages' => all_storages_for_select($filters['storage_id']),
    ]);
}
