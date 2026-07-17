<?php
declare(strict_types=1);

// Storage page render handlers. Persistence stays in storages.php.

function handle_storages_index(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('storages.view');

    $filters = storage_filters();
    $storages = storage_summaries($filters);

    $counts = [
        'active' => (int) Database::scalar('SELECT COUNT(*) FROM storages WHERE is_active = 1'),
        'archived' => (int) Database::scalar('SELECT COUNT(*) FROM storages WHERE is_active = 0'),
    ];

    View::render('storages/index', [
        'title' => site_setting('page.storages', 'Storages'),
        'storages' => $storages,
        'storageOptions' => all_storages_for_select($filters['storage_id']),
        'filters' => $filters,
        'counts' => $counts,
    ]);
}

function handle_storages_show(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('storages.view');

    $storage = find_storage_or_abort((int) $params['id']);
    $items = storage_items((int) $storage['id']);

    $metrics = [
        'contained_items' => count($items),
        'stocked_items' => count(array_filter(
            $items,
            static fn (array $item): bool => (int) $item['is_active'] === 1 && round((float) $item['quantity'], 2) > 0
        )),
        'low_stock_items' => count(array_filter(
            $items,
            static fn (array $item): bool => (int) $item['is_active'] === 1 && (float) $item['quantity'] <= (float) $item['reorder_level']
        )),
        'stock_value' => array_reduce(
            $items,
            static fn (float $carry, array $item): float => $carry + stock_value($item['quantity'], $item['cost_per_unit']),
            0.0
        ),
    ];

    View::render('storages/show', [
        'title' => $storage['name'],
        'storage' => $storage,
        'items' => $items,
        'metrics' => $metrics,
        'purchaseHistory' => function_exists('purchase_history_for_storage') ? purchase_history_for_storage((int) $storage['id']) : [],
    ]);
}

function handle_storages_create_page(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('storages.create');
    $copySource = requested_storage_copy_source();
    $currentUser = Auth::user();

    View::render('storages/form', [
        'title' => 'Create Storage',
        'mode' => 'create',
        'storage' => default_storage_payload($copySource),
        'copySource' => $copySource,
        'ownerCandidates' => admin_owner_users_for_select((int) ($currentUser['id'] ?? 0)),
    ]);
}

function handle_storages_edit_page(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('storages.edit');

    $storage = find_storage_or_abort((int) $params['id']);

    View::render('storages/form', [
        'title' => 'Edit ' . $storage['name'],
        'mode' => 'edit',
        'storage' => [
            'id' => $storage['id'],
            'name' => old('name', $storage['name']),
            'storage_type' => old('storage_type', $storage['storage_type']),
            'notes' => old('notes', $storage['notes']),
            'owner_user_id' => old('owner_user_id', (string) ($storage['owner_user_id'] ?? '')),
            'copy_storage_id' => '',
            'copy_contents_mode' => 'empty',
            'is_active' => (int) $storage['is_active'],
            'assigned_item_count' => (int) $storage['assigned_item_count'],
            'stocked_item_count' => (int) $storage['stocked_item_count'],
            'total_quantity' => (float) $storage['total_quantity'],
            'total_used' => (float) $storage['total_used'],
        ],
        'copySource' => null,
        'ownerCandidates' => admin_owner_users_for_select((int) ($storage['owner_user_id'] ?? 0)),
    ]);
}
