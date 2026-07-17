<?php
declare(strict_types=1);

// Stocktake page handlers.

function handle_stocktakes_index(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('stocktakes.view');

    $filters = stocktake_filters();
    redirect_exact_workflow_reference_search((string) $filters['search'], ['stocktake']);

    View::render('stocktakes/index', [
        'title' => site_setting('page.stocktakes', 'Stocktakes'),
        'stocktakes' => stocktake_summary_rows($filters),
        'filters' => $filters,
        'statuses' => stocktake_status_options(),
        'storages' => all_storages_for_select($filters['storage_id']),
    ]);
}

function handle_stocktakes_create_page(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('stocktakes.create');

    $selectedStorageId = normalize_entity_id(old('storage_id', query('storage_id', '')));
    $previewItems = $selectedStorageId ? storage_items($selectedStorageId) : [];

    View::render('stocktakes/form', [
        'title' => 'Create Stocktake',
        'storages' => all_storages_for_select($selectedStorageId),
        'storageId' => $selectedStorageId,
        'previewItems' => array_values(array_filter($previewItems, static fn (array $item): bool => (int) $item['is_active'] === 1)),
        'notes' => old('notes', ''),
    ]);
}

function handle_stocktakes_show(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('stocktakes.view');

    $stocktake = find_stocktake_or_abort((int) $params['id']);

    View::render('stocktakes/show', [
        'title' => $stocktake['stocktake_number'],
        'stocktake' => $stocktake,
        'lines' => stocktake_lines((int) $stocktake['id']),
    ]);
}
