<?php
declare(strict_types=1);

// Domain module: request page handlers. Function names are preserved for route compatibility.

function handle_requests_index(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('requests.view');

    $user = Auth::user();

    if ($user) {
        mark_notifications_for_entity_type_as_read((int) $user['id'], 'request');
    }

    $filters = request_filters();
    redirect_exact_workflow_reference_search((string) $filters['search'], ['request']);
    $requests = request_summary_rows($filters);

    [$requestScopeSql, $requestScopeParams] = visible_request_scope('r');
    $counts = [
        'draft' => (int) Database::scalar("SELECT COUNT(*) FROM item_requests r WHERE r.status = 'draft'" . $requestScopeSql, $requestScopeParams),
        'open' => (int) Database::scalar("SELECT COUNT(*) FROM item_requests r WHERE r.status IN ('pending', 'approved', 'receipt_review')" . $requestScopeSql, $requestScopeParams),
        'completed' => (int) Database::scalar("SELECT COUNT(*) FROM item_requests r WHERE r.status = 'completed'" . $requestScopeSql, $requestScopeParams),
        'rejected' => (int) Database::scalar("SELECT COUNT(*) FROM item_requests r WHERE r.status = 'rejected'" . $requestScopeSql, $requestScopeParams),
        'cancelled' => (int) Database::scalar("SELECT COUNT(*) FROM item_requests r WHERE r.status = 'cancelled'" . $requestScopeSql, $requestScopeParams),
    ];

    View::render('requests/index', [
        'title' => site_setting('page.requests', 'Requests'),
        'filters' => $filters,
        'requests' => $requests,
        'counts' => $counts,
        'storages' => all_storages_for_select($filters['storage_id']),
    ]);
}

function handle_requests_create_page(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('requests.create');
    $currentUser = Auth::user() ?? [];
    $selectedSourceStorageId = normalize_entity_id(old('source_storage_id', ''));
    $selectedDestinationStorageId = normalize_entity_id(old('destination_storage_id', ''));
    $sourceStorages = all_storages_for_select($selectedSourceStorageId);
    $destinationStorages = Auth::isStaff()
        ? []
        : request_destination_storages_for_user($currentUser, $selectedDestinationStorageId);

    View::render('requests/form', [
        'title' => 'Create Request',
        'requestRecord' => [
            'source_storage_id' => old('source_storage_id', ''),
            'destination_storage_id' => old('destination_storage_id', ''),
            'needed_by_date' => old('needed_by_date', ''),
            'notes' => old('notes', ''),
        ],
        'lineItems' => old('line_items', [['item_id' => '', 'quantity' => '']]),
        'sourceStorages' => $sourceStorages,
        'destinationStorages' => $destinationStorages,
        'isStaffRequest' => Auth::isStaff(),
        'usageReasonOptions' => handover_usage_reason_options(),
        'storageCatalogJson' => json_encode(
            workflow_storage_item_catalog(array_column($sourceStorages, 'id')),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ),
        'storageMetaJson' => json_encode(workflow_storage_meta($sourceStorages), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
}

function handle_requests_show(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('requests.view');

    $request = find_request_or_abort((int) $params['id']);
    $user = Auth::user();

    if ($user) {
        mark_notifications_for_entity_as_read((int) $user['id'], 'request', (int) $request['id']);
    }

    $lines = request_lines((int) $request['id']);

    try {
        ensure_workflow_signoff_pdf('request', $request, $lines);
    } catch (Throwable $exception) {
        // The workflow page must stay usable even if attachment generation fails.
    }

    View::render('requests/show', [
        'title' => $request['request_number'],
        'requestRecord' => $request,
        'lines' => $lines,
        'documents' => workflow_documents('request', (int) $request['id']),
    ]);
}
