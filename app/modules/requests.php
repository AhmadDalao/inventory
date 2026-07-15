<?php
declare(strict_types=1);

// Domain module: requests. Function names are preserved for route/view compatibility.

// Moved from workflows.php.

function request_destination_storages_for_user(array $user, ?int $selectedId = null): array
{
    if (($user['role'] ?? '') === 'owner') {
        return all_storages_for_select($selectedId);
    }

    return storages_owned_by_user_for_select((int) $user['id'], $selectedId);
}

function visible_request_scope(string $alias = 'r'): array
{
    $user = Auth::user();

    if ($user === null || Auth::isOwner() || Auth::hasPermission('requests.approve')) {
        return ['', []];
    }

    return [
        " AND ({$alias}.requester_user_id = :request_scope_requester_user_id OR {$alias}.approver_user_id = :request_scope_approver_user_id)",
        [
            'request_scope_requester_user_id' => (int) $user['id'],
            'request_scope_approver_user_id' => (int) $user['id'],
        ],
    ];
}

function request_filters(): array
{
    $status = (string) query('status', 'all');

    return [
        'search' => trim((string) query('search', '')),
        'status' => in_array($status, ['open', 'draft', 'pending', 'approved', 'receipt_review', 'completed', 'rejected', 'cancelled', 'all'], true) ? $status : 'all',
        'storage_id' => ctype_digit((string) query('storage_id', '')) ? (int) query('storage_id') : null,
        'date_from' => normalize_workflow_date((string) query('date_from', '')),
        'date_to' => normalize_workflow_date((string) query('date_to', '')),
    ];
}

function build_request_where(array $filters, string $alias = 'r'): array
{
    $conditions = [];
    $params = [];

    if ($filters['status'] === 'open') {
        $conditions[] = "{$alias}.status IN ('pending', 'approved', 'receipt_review')";
    } elseif ($filters['status'] !== 'all') {
        $conditions[] = "{$alias}.status = :request_status";
        $params['request_status'] = $filters['status'];
    }

    if (!empty($filters['storage_id'])) {
        $conditions[] = "({$alias}.source_storage_id = :request_source_storage_id OR {$alias}.destination_storage_id = :request_destination_storage_id)";
        $params['request_source_storage_id'] = (int) $filters['storage_id'];
        $params['request_destination_storage_id'] = (int) $filters['storage_id'];
    }

    if ($filters['search'] !== '') {
        $conditions[] = "(
            {$alias}.request_number LIKE :request_search_number
            OR COALESCE({$alias}.notes, '') LIKE :request_search_notes
            OR requester.name LIKE :request_search_requester
            OR approver.name LIKE :request_search_approver
            OR source_storage.name LIKE :request_search_source_storage
            OR destination_storage.name LIKE :request_search_destination_storage
            OR EXISTS (
                SELECT 1
                FROM item_request_lines request_lines
                WHERE request_lines.request_id = {$alias}.id
                  AND (
                      request_lines.item_name LIKE :request_search_item_name
                      OR request_lines.item_sku LIKE :request_search_item_sku
                  )
            )
        )";
        $requestSearchLike = '%' . $filters['search'] . '%';
        $params['request_search_number'] = $requestSearchLike;
        $params['request_search_notes'] = $requestSearchLike;
        $params['request_search_requester'] = $requestSearchLike;
        $params['request_search_approver'] = $requestSearchLike;
        $params['request_search_source_storage'] = $requestSearchLike;
        $params['request_search_destination_storage'] = $requestSearchLike;
        $params['request_search_item_name'] = $requestSearchLike;
        $params['request_search_item_sku'] = $requestSearchLike;
    }

    if ($filters['date_from'] !== '') {
        $conditions[] = "{$alias}.requested_at >= :request_date_from";
        $params['request_date_from'] = $filters['date_from'] . ' 00:00:00';
    }

    if ($filters['date_to'] !== '') {
        $conditions[] = "{$alias}.requested_at <= :request_date_to";
        $params['request_date_to'] = $filters['date_to'] . ' 23:59:59';
    }

    [$scopeSql, $scopeParams] = visible_request_scope($alias);
    $where = $conditions === [] ? 'WHERE 1 = 1' : 'WHERE ' . implode(' AND ', $conditions);

    return [$where . $scopeSql, $params + $scopeParams];
}

function find_request_or_abort(int $requestId): array
{
    [$scopeSql, $scopeParams] = visible_request_scope('r');
    $request = Database::fetch(
        'SELECT r.*,
                requester.name AS requester_name,
                requester.email AS requester_email,
                requester.role AS requester_role,
                approver.name AS approver_name,
                approver.email AS approver_email,
                source_storage.name AS source_storage_name,
                source_storage.storage_type AS source_storage_type,
                destination_storage.name AS destination_storage_name,
                destination_storage.storage_type AS destination_storage_type,
                approved_by_user.name AS approved_by_name,
                completed_by_user.name AS completed_by_name
         FROM item_requests r
         INNER JOIN users requester ON requester.id = r.requester_user_id
         INNER JOIN users approver ON approver.id = r.approver_user_id
         INNER JOIN storages source_storage ON source_storage.id = r.source_storage_id
         LEFT JOIN storages destination_storage ON destination_storage.id = r.destination_storage_id
         LEFT JOIN users approved_by_user ON approved_by_user.id = r.approved_by
         LEFT JOIN users completed_by_user ON completed_by_user.id = r.completed_by
         WHERE r.id = :id' . $scopeSql . '
         LIMIT 1',
        ['id' => $requestId] + $scopeParams
    );

    if (!$request) {
        abort(404, 'Request not found.');
    }

    return $request;
}

function request_decision_block_reason(array $request, ?array $user = null): ?string
{
    $user = $user ?? Auth::user();

    if ($user === null) {
        return 'Login first.';
    }

    if ((string) ($request['status'] ?? '') !== 'pending') {
        return 'Only pending requests can be approved or rejected.';
    }

    if ((int) ($request['requester_user_id'] ?? 0) === (int) ($user['id'] ?? 0)) {
        return 'You cannot approve or reject your own request.';
    }

    if ((int) ($request['approver_user_id'] ?? 0) !== (int) ($user['id'] ?? 0) && !Auth::isOwner()) {
        return 'This request is assigned to a different approver.';
    }

    return null;
}

function request_can_report_receipt(array $request, ?array $user = null): bool
{
    $user = $user ?? Auth::user();

    if ($user === null || !Auth::hasPermission('requests.receive')) {
        return false;
    }

    if (!in_array((string) ($request['status'] ?? ''), ['approved', 'receipt_review'], true)) {
        return false;
    }

    return Auth::isOwner() || (int) ($request['requester_user_id'] ?? 0) === (int) ($user['id'] ?? 0);
}

function request_submit_draft_block_reason(array $request, ?array $user = null): ?string
{
    $user = $user ?? Auth::user();

    if ($user === null) {
        return 'Login first.';
    }

    if (!Auth::hasPermission('requests.create')) {
        return 'You do not have permission to submit request drafts.';
    }

    if ((string) ($request['status'] ?? '') !== 'draft') {
        return 'Only draft requests can be submitted.';
    }

    $userId = (int) ($user['id'] ?? 0);

    if ((int) ($request['requester_user_id'] ?? 0) !== $userId && !Auth::isOwner()) {
        return 'Only the requester or owner can submit this draft.';
    }

    $sourceOwner = storage_owner_record((int) ($request['source_storage_id'] ?? 0));

    if (!$sourceOwner || empty($sourceOwner['owner_user_id']) || (int) ($sourceOwner['owner_is_active'] ?? 0) !== 1) {
        return 'The source storage needs an active owner admin before this draft can be submitted.';
    }

    if ((int) ($sourceOwner['owner_user_id'] ?? 0) === (int) ($request['requester_user_id'] ?? 0)) {
        return 'The requester now owns the source storage, so this draft cannot be submitted as a request.';
    }

    return null;
}

function request_receipt_confirm_block_reason(array $request, ?array $user = null): ?string
{
    $user = $user ?? Auth::user();

    if ($user === null) {
        return 'Login first.';
    }

    if ((string) ($request['status'] ?? '') !== 'receipt_review') {
        return 'Only receipt review requests can be confirmed.';
    }

    if ((int) ($request['requester_user_id'] ?? 0) === (int) ($user['id'] ?? 0)) {
        return 'You cannot approve your own receipt report.';
    }

    if ((int) ($request['approver_user_id'] ?? 0) !== (int) ($user['id'] ?? 0) && !Auth::isOwner()) {
        return 'This request is assigned to a different approver.';
    }

    return null;
}

function request_cancel_block_reason(array $request, ?array $user = null): ?string
{
    $user = $user ?? Auth::user();

    if ($user === null) {
        return 'Login first.';
    }

    if (!in_array((string) ($request['status'] ?? ''), ['draft', 'pending', 'approved', 'receipt_review'], true)) {
        return 'Only open requests can be cancelled.';
    }

    $userId = (int) ($user['id'] ?? 0);
    $isRequester = (int) ($request['requester_user_id'] ?? 0) === $userId;
    $isApprover = (int) ($request['approver_user_id'] ?? 0) === $userId;
    $isOwner = Auth::isOwner();

    if (!$isRequester && !$isApprover && !$isOwner && !Auth::hasPermission('requests.cancel')) {
        return 'You do not have permission to cancel requests.';
    }

    if (!$isRequester && !$isApprover && !$isOwner) {
        return 'Only the requester, approver, or owner can cancel this request.';
    }

    return null;
}

function request_recovery_target_status(array $request, array $lines): ?string
{
    $status = (string) ($request['status'] ?? '');

    if ($status === 'rejected') {
        return 'pending';
    }

    if ($status !== 'cancelled') {
        return null;
    }

    $hasApprovedQuantity = false;
    $hasReceiptVariance = false;

    foreach ($lines as $line) {
        $approved = round((float) ($line['quantity_approved'] ?? 0), 2);
        $received = round((float) ($line['quantity_received'] ?? 0), 2);

        if ($approved > 0) {
            $hasApprovedQuantity = true;
        }

        if ($received > 0 && $received !== $approved) {
            $hasReceiptVariance = true;
        }
    }

    if (!empty($request['receipt_reported_at']) && $hasApprovedQuantity && $hasReceiptVariance) {
        return 'receipt_review';
    }

    if (!empty($request['approved_at']) || $hasApprovedQuantity) {
        return 'approved';
    }

    return 'pending';
}

function request_recovery_block_reason(array $request, array $lines, ?array $user = null): ?string
{
    $user = $user ?? Auth::user();

    if ($user === null) {
        return 'Login first.';
    }

    if (!Auth::isOwner()) {
        return 'Only the owner can recover requests.';
    }

    $targetStatus = request_recovery_target_status($request, $lines);

    if ($targetStatus === null) {
        return 'Only cancelled or rejected requests can be recovered.';
    }

    if (!workflow_stock_impact_is_neutral('request', (int) ($request['id'] ?? 0))) {
        return 'This request still has active stock impact. Close or cancel the stock flow before recovery.';
    }

    if (in_array($targetStatus, ['approved', 'receipt_review'], true)) {
        foreach ($lines as $line) {
            $approvedQuantity = round((float) ($line['quantity_approved'] ?? 0), 2);

            if ($approvedQuantity <= 0) {
                return 'Approved quantities are missing, so this request can only be recreated manually.';
            }

            $balance = item_storage_balance_record((int) $line['item_id'], (int) $request['source_storage_id']);

            if ($balance === null || (float) $balance['quantity'] < $approvedQuantity) {
                return $line['item_name'] . ' no longer has enough stock to recover this request.';
            }
        }
    }

    return null;
}

function issue_request_inventory(array $request, array $lines, int $performedBy): void
{
    $transitStorageId = system_storage_id('request_transit');

    foreach ($lines as $line) {
        $approvedQuantity = round((float) ($line['quantity_approved'] ?? 0), 2);

        if ($approvedQuantity <= 0) {
            continue;
        }

        $item = find_item_or_abort((int) $line['item_id']);
        $balance = item_storage_balance_record((int) $line['item_id'], (int) $request['source_storage_id']);

        if ($balance === null || (float) $balance['quantity'] < $approvedQuantity) {
            throw new RuntimeException($line['item_name'] . ' no longer has enough stock to recover this request.');
        }

        apply_inventory_movement(
            $item,
            'transfer',
            $approvedQuantity,
            (int) $request['source_storage_id'],
            $transitStorageId,
            date('Y-m-d H:i:s'),
            (string) $request['request_number'],
            'Recovered request moved approved stock back into transit.',
            $performedBy,
            'request',
            (int) $request['id']
        );
    }
}

function build_request_receipt_updates(array $lines, $receivedInput): array
{
    $errors = [];
    $updates = [];
    $hasVariance = false;

    foreach ($lines as $line) {
        $lineId = (int) $line['id'];
        $receivedValue = is_array($receivedInput) ? ($receivedInput[$lineId] ?? '') : '';

        if (!is_numeric_value($receivedValue) || quantity_value($receivedValue) < 0) {
            $errors[] = 'Received quantity must be zero or more for every line.';
            continue;
        }

        $approved = round((float) $line['quantity_approved'], 2);
        $received = round(quantity_value($receivedValue), 2);

        if ($received > $approved) {
            $errors[] = $line['item_name'] . ' cannot receive more than the approved quantity.';
            continue;
        }

        $updates[] = [
            'line_id' => $lineId,
            'item_id' => (int) $line['item_id'],
            'approved' => $approved,
            'received' => $received,
            'remainder' => round($approved - $received, 2),
        ];

        if ($received !== $approved) {
            $hasVariance = true;
        }
    }

    return [$updates, $errors, $hasVariance];
}

function apply_request_receipt_confirmation_movements(array $request, array $receiptUpdates, int $performedBy): void
{
    $transitStorageId = system_storage_id('request_transit');
    $isTransfer = (string) ($request['request_mode'] ?? 'transfer') === 'transfer';

    foreach ($receiptUpdates as $update) {
        if ((float) $update['approved'] <= 0) {
            continue;
        }

        $item = find_item_or_abort((int) $update['item_id']);

        if ((float) $update['received'] > 0) {
            if ($isTransfer) {
                apply_inventory_movement(
                    $item,
                    'transfer',
                    (float) $update['received'],
                    $transitStorageId,
                    (int) $request['destination_storage_id'],
                    date('Y-m-d H:i:s'),
                    (string) $request['request_number'],
                    'Request receipt confirmed into destination storage.',
                    $performedBy,
                    'request',
                    (int) $request['id']
                );
            } else {
                apply_inventory_movement(
                    $item,
                    'usage',
                    (float) $update['received'],
                    $transitStorageId,
                    null,
                    date('Y-m-d H:i:s'),
                    (string) $request['request_number'],
                    'Issue request receipt confirmed and released for use.',
                    $performedBy,
                    'request',
                    (int) $request['id']
                );
            }
        }

        if ((float) $update['remainder'] > 0) {
            apply_inventory_movement(
                $item,
                'transfer',
                (float) $update['remainder'],
                $transitStorageId,
                (int) $request['source_storage_id'],
                date('Y-m-d H:i:s'),
                (string) $request['request_number'],
                'Unreceived request quantity returned to source storage.',
                $performedBy,
                'request',
                (int) $request['id']
            );
        }

        Database::execute(
            'UPDATE item_request_lines
             SET quantity_received = :quantity_received,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'quantity_received' => (float) $update['received'],
                'id' => (int) $update['line_id'],
            ]
        );
    }
}

function request_lines(int $requestId): array
{
    return Database::fetchAll(
        'SELECT request_line.*,
                i.image_path,
                i.barcode AS item_barcode,
                COALESCE(source_balances.quantity, 0) AS source_available_now
         FROM item_request_lines request_line
         INNER JOIN items i ON i.id = request_line.item_id
         INNER JOIN item_requests requests ON requests.id = request_line.request_id
         LEFT JOIN item_storage_balances source_balances
            ON source_balances.item_id = request_line.item_id
           AND source_balances.storage_id = requests.source_storage_id
         WHERE request_line.request_id = :request_id
         ORDER BY request_line.item_name ASC, request_line.id ASC',
        ['request_id' => $requestId]
    );
}

function request_summary_rows(array $filters): array
{
    [$where, $params] = build_request_where($filters);

    return Database::fetchAll(
        "SELECT r.*,
                requester.name AS requester_name,
                approver.name AS approver_name,
                source_storage.name AS source_storage_name,
                source_storage.storage_type AS source_storage_type,
                destination_storage.name AS destination_storage_name,
                destination_storage.storage_type AS destination_storage_type,
                COALESCE(line_totals.line_count, 0) AS line_count,
                COALESCE(line_totals.total_requested, 0) AS total_requested
         FROM item_requests r
         INNER JOIN users requester ON requester.id = r.requester_user_id
         INNER JOIN users approver ON approver.id = r.approver_user_id
         INNER JOIN storages source_storage ON source_storage.id = r.source_storage_id
         LEFT JOIN storages destination_storage ON destination_storage.id = r.destination_storage_id
         LEFT JOIN (
             SELECT request_id,
                    COUNT(*) AS line_count,
                    COALESCE(SUM(quantity_requested), 0) AS total_requested
             FROM item_request_lines
             GROUP BY request_id
         ) line_totals ON line_totals.request_id = r.id
         {$where}
         ORDER BY r.requested_at DESC, r.id DESC
         LIMIT 250",
        $params
    );
}

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
        'storageCatalogJson' => json_encode(workflow_storage_item_catalog(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'storageMetaJson' => json_encode(workflow_storage_meta($sourceStorages), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
}

function handle_requests_create_submit(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('requests.create');
    verify_csrf();

    $user = Auth::user();
    $isStaffRequest = Auth::isStaff();
    $requestMode = $isStaffRequest ? 'issue' : 'transfer';
    $requestAction = (string) input('request_action', 'submit') === 'draft' ? 'draft' : 'submit';
    $requestStatus = $requestAction === 'draft' ? 'draft' : 'pending';
    [$lines, $lineErrors] = parse_workflow_lines();
    $payload = [
        'source_storage_id' => normalize_entity_id(input('source_storage_id')),
        'destination_storage_id' => normalize_entity_id(input('destination_storage_id')),
        'needed_by_date' => normalize_workflow_date(trim((string) input('needed_by_date'))),
        'notes' => trim((string) input('notes')),
    ];

    flash_old_input([
        'source_storage_id' => (string) ($payload['source_storage_id'] ?? ''),
        'destination_storage_id' => (string) ($payload['destination_storage_id'] ?? ''),
        'needed_by_date' => $payload['needed_by_date'],
        'notes' => $payload['notes'],
        'line_items' => array_map(static fn (array $line): array => [
            'item_id' => (string) $line['item_id'],
            'quantity' => format_quantity($line['quantity']),
        ], $lines),
    ]);

    $errors = $lineErrors;

    if (!$payload['source_storage_id'] || !storage_exists_for_assignment($payload['source_storage_id'])) {
        $errors[] = 'Pick a valid source storage.';
    }

    $sourceOwner = $payload['source_storage_id'] ? storage_owner_record((int) $payload['source_storage_id']) : null;

    if (!$sourceOwner || empty($sourceOwner['owner_user_id']) || (int) ($sourceOwner['owner_is_active'] ?? 0) !== 1) {
        $errors[] = 'The source storage needs an active owner admin before requests can be created.';
    }

    if ($sourceOwner && (int) ($sourceOwner['owner_user_id'] ?? 0) === (int) ($user['id'] ?? 0)) {
        $errors[] = 'You cannot create a request from a storage you own. Use a direct transfer, handover, or stock update instead.';
    }

    if ($requestMode === 'transfer') {
        if (!$payload['destination_storage_id'] || !storage_exists_for_assignment($payload['destination_storage_id'])) {
            $errors[] = 'Pick a valid destination storage.';
        } elseif (!Auth::isOwner() && !storage_is_owned_by_user((int) $payload['destination_storage_id'], (int) ($user['id'] ?? 0))) {
            $errors[] = 'Pick one of your own storages as the destination.';
        }

        if ($payload['source_storage_id'] && $payload['destination_storage_id'] && $payload['source_storage_id'] === $payload['destination_storage_id']) {
            $errors[] = 'Source and destination storages cannot be the same.';
        }
    } else {
        $payload['destination_storage_id'] = null;
    }

    $itemsById = [];

    foreach ($lines as $line) {
        $item = Database::fetch(
            'SELECT i.*
             FROM items i
             WHERE i.id = :id
               AND i.is_active = 1
             LIMIT 1',
            ['id' => $line['item_id']]
        );

        if (!$item) {
            $errors[] = 'One of the selected items no longer exists.';
            continue;
        }

        $balance = item_storage_balance_record((int) $item['id'], (int) $payload['source_storage_id']);

        if ($balance === null) {
            $errors[] = $item['name'] . ' is not assigned to the selected source storage.';
            continue;
        }

        $itemsById[(int) $item['id']] = $item;
    }

    if ($errors !== []) {
        flash_errors($errors);
        redirect('/requests/create');
    }

    $requestNumber = next_workflow_number('REQ', 'item_requests', 'request_number');
    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        Database::execute(
            'INSERT INTO item_requests (
                request_number,
                request_mode,
                requester_user_id,
                approver_user_id,
                source_storage_id,
                destination_storage_id,
                status,
                needed_by_date,
                notes,
                decision_notes,
                requested_at,
                approved_at,
                completed_at,
                rejected_at,
                cancelled_at,
                approved_by,
                completed_by,
                updated_by,
                created_at,
                updated_at
             ) VALUES (
                :request_number,
                :request_mode,
                :requester_user_id,
                :approver_user_id,
                :source_storage_id,
                :destination_storage_id,
                :status,
                :needed_by_date,
                :notes,
                NULL,
                NOW(),
                NULL,
                NULL,
                NULL,
                NULL,
                NULL,
                NULL,
                :updated_by,
                NOW(),
                NOW()
             )',
            [
                'request_number' => $requestNumber,
                'request_mode' => $requestMode,
                'requester_user_id' => (int) $user['id'],
                'approver_user_id' => (int) $sourceOwner['owner_user_id'],
                'source_storage_id' => (int) $payload['source_storage_id'],
                'destination_storage_id' => $payload['destination_storage_id'] ? (int) $payload['destination_storage_id'] : null,
                'status' => $requestStatus,
                'needed_by_date' => $payload['needed_by_date'] !== '' ? $payload['needed_by_date'] : null,
                'notes' => $payload['notes'] !== '' ? $payload['notes'] : null,
                'updated_by' => (int) $user['id'],
            ]
        );

        $requestId = Database::lastInsertId();

        foreach ($lines as $line) {
            $item = $itemsById[(int) $line['item_id']];

            Database::execute(
                'INSERT INTO item_request_lines (
                    request_id,
                    item_id,
                    item_name,
                    item_sku,
                    unit,
                    quantity_requested,
                    quantity_approved,
                    quantity_received,
                    created_at,
                    updated_at
                 ) VALUES (
                    :request_id,
                    :item_id,
                    :item_name,
                    :item_sku,
                    :unit,
                    :quantity_requested,
                    0,
                    0,
                    NOW(),
                    NOW()
                 )',
                [
                    'request_id' => $requestId,
                    'item_id' => (int) $item['id'],
                    'item_name' => $item['name'],
                    'item_sku' => $item['sku'],
                    'unit' => $item['unit'],
                    'quantity_requested' => $line['quantity'],
                ]
            );
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/requests/create');
    }

    if ($requestStatus === 'pending') {
        create_notification(
            (int) $sourceOwner['owner_user_id'],
            'request_created',
            'New item request ' . $requestNumber,
            ($user['name'] ?? 'Someone') . ($requestMode === 'issue'
                ? ' asked for items to use from ' . ($sourceOwner['storage_name'] ?? 'your storage') . '.'
                : ' requested a storage transfer from ' . ($sourceOwner['storage_name'] ?? 'your storage') . '.'),
            url('/requests/' . $requestId),
            'request',
            $requestId,
            (int) ($user['id'] ?? 0)
        );
    }

    consume_old_input();
    flash('success', $requestStatus === 'draft' ? 'Request draft saved.' : 'Request submitted.');
    redirect('/requests/' . $requestId);
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

function handle_requests_submit_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('requests.create');
    verify_csrf();

    $request = find_request_or_abort((int) $params['id']);
    $user = Auth::user();
    $blockReason = request_submit_draft_block_reason($request, $user);

    if ($blockReason !== null) {
        flash('danger', $blockReason);
        redirect('/requests/' . $request['id']);
    }

    Database::execute(
        'UPDATE item_requests
         SET status = "pending",
             requested_at = NOW(),
             updated_by = :updated_by,
             updated_at = NOW()
         WHERE id = :id',
        [
            'updated_by' => (int) ($user['id'] ?? 0),
            'id' => (int) $request['id'],
        ]
    );

    create_notification(
        (int) $request['approver_user_id'],
        'request_created',
        'New item request ' . $request['request_number'],
        ($user['name'] ?? 'Someone') . ((string) ($request['request_mode'] ?? 'transfer') === 'issue'
            ? ' asked for items to use from ' . ($request['source_storage_name'] ?? 'your storage') . '.'
            : ' requested a storage transfer from ' . ($request['source_storage_name'] ?? 'your storage') . '.'),
        url('/requests/' . $request['id']),
        'request',
        (int) $request['id'],
        (int) ($user['id'] ?? 0)
    );

    record_activity('request.submitted', 'request', (int) $request['id'], 'Submitted request draft ' . $request['request_number'], [
        'request_number' => (string) $request['request_number'],
    ]);

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => 'Request submitted for approval.',
            'redirect_url' => url('/requests/' . $request['id']),
        ]);
    }

    flash('success', 'Request submitted for approval.');
    redirect('/requests/' . $request['id']);
}

function handle_requests_approve_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('requests.approve');
    verify_csrf();

    $request = find_request_or_abort((int) $params['id']);
    $user = Auth::user();

    $decisionBlockReason = request_decision_block_reason($request, $user);

    if ($decisionBlockReason !== null) {
        flash('danger', $decisionBlockReason);
        redirect('/requests/' . $request['id']);
    }

    $decisionNotes = trim((string) input('decision_notes'));
    $lines = request_lines((int) $request['id']);
    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        $transitStorageId = system_storage_id('request_transit');

        foreach ($lines as $line) {
            $item = find_item_or_abort((int) $line['item_id']);
            $balance = item_storage_balance_record((int) $line['item_id'], (int) $request['source_storage_id']);

            if ($balance === null || (float) $balance['quantity'] < (float) $line['quantity_requested']) {
                throw new RuntimeException($line['item_name'] . ' no longer has enough stock to approve this request.');
            }

            apply_inventory_movement(
                $item,
                'transfer',
                (float) $line['quantity_requested'],
                (int) $request['source_storage_id'],
                $transitStorageId,
                date('Y-m-d H:i:s'),
                (string) $request['request_number'],
                (string) ($request['request_mode'] ?? 'transfer') === 'transfer'
                    ? 'Approved request transfer into transit.'
                    : 'Approved issue request reserved for release.',
                (int) $user['id'],
                'request',
                (int) $request['id']
            );

            Database::execute(
                'UPDATE item_request_lines
                 SET quantity_approved = :quantity_approved,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'quantity_approved' => (float) $line['quantity_requested'],
                    'id' => (int) $line['id'],
                ]
            );
        }

        Database::execute(
            'UPDATE item_requests
             SET status = "approved",
                 decision_notes = :decision_notes,
                 approved_at = NOW(),
                 approved_by = :approved_by,
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'decision_notes' => $decisionNotes !== '' ? $decisionNotes : null,
                'approved_by' => (int) $user['id'],
                'updated_by' => (int) $user['id'],
                'id' => (int) $request['id'],
            ]
        );

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/requests/' . $request['id']);
    }

    create_notification(
        (int) $request['requester_user_id'],
        'request_approved',
        'Request ' . $request['request_number'] . ' approved',
        'Your request is now in progress.',
        url('/requests/' . $request['id']),
        'request',
        (int) $request['id'],
        (int) ($user['id'] ?? 0)
    );

    $successMessage = (string) ($request['request_mode'] ?? 'transfer') === 'transfer'
        ? 'Request approved and moved into transit.'
        : 'Request approved and reserved for release.';

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => $successMessage,
            'redirect_url' => url('/requests/' . $request['id']),
        ]);
    }

    flash('success', $successMessage);
    redirect('/requests/' . $request['id']);
}

function handle_requests_reject_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('requests.approve');
    verify_csrf();

    $request = find_request_or_abort((int) $params['id']);
    $user = Auth::user();

    $decisionBlockReason = request_decision_block_reason($request, $user);

    if ($decisionBlockReason !== null) {
        flash('danger', $decisionBlockReason);
        redirect('/requests/' . $request['id']);
    }

    $decisionNotes = trim((string) input('decision_notes'));

    Database::execute(
        'UPDATE item_requests
         SET status = "rejected",
             decision_notes = :decision_notes,
             rejected_at = NOW(),
             updated_by = :updated_by,
             updated_at = NOW()
         WHERE id = :id',
        [
            'decision_notes' => $decisionNotes !== '' ? $decisionNotes : null,
            'updated_by' => (int) $user['id'],
            'id' => (int) $request['id'],
        ]
    );

    create_notification(
        (int) $request['requester_user_id'],
        'request_rejected',
        'Request ' . $request['request_number'] . ' rejected',
        $decisionNotes !== '' ? $decisionNotes : 'Your request was rejected.',
        url('/requests/' . $request['id']),
        'request',
        (int) $request['id'],
        (int) ($user['id'] ?? 0)
    );

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => 'Request rejected.',
            'redirect_url' => url('/requests/' . $request['id']),
        ]);
    }

    flash('success', 'Request rejected.');
    redirect('/requests/' . $request['id']);
}

function handle_requests_receive_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('requests.receive');
    verify_csrf();

    $request = find_request_or_abort((int) $params['id']);
    $user = Auth::user();

    if (!request_can_report_receipt($request, $user)) {
        flash('danger', 'Only the requester can report receipt quantities.');
        redirect('/requests/' . $request['id']);
    }

    if (!in_array((string) ($request['status'] ?? ''), ['approved', 'receipt_review'], true)) {
        flash('danger', 'Only approved requests can accept a receipt report.');
        redirect('/requests/' . $request['id']);
    }

    $lines = request_lines((int) $request['id']);
    $receiptNotes = trim((string) input('receipt_notes'));
    [$receiptUpdates, $receiptErrors, $hasVariance] = build_request_receipt_updates($lines, input('line_received'));
    $proofFile = uploaded_file('proof_image');
    $proofError = validate_workflow_proof_upload($proofFile);

    if ($proofError !== null) {
        if (request_wants_json()) {
            json_response([
                'ok' => false,
                'message' => $proofError,
            ], 422);
        }

        flash('danger', $proofError);
        redirect('/requests/' . $request['id']);
    }

    if ($receiptErrors !== []) {
        $message = implode(' ', array_unique($receiptErrors));

        if (request_wants_json()) {
            json_response([
                'ok' => false,
                'message' => $message,
            ], 422);
        }

        flash('danger', $message);
        redirect('/requests/' . $request['id']);
    }

    $pdo = Database::connection();
    $storedProof = null;

    try {
        if ($proofFile !== null) {
            $storedProof = store_workflow_proof_document($proofFile, 'request', (string) $request['request_number'], 'receipt_report');
        }
    } catch (Throwable $exception) {
        if (request_wants_json()) {
            json_response([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        flash('danger', $exception->getMessage());
        redirect('/requests/' . $request['id']);
    }

    $pdo->beginTransaction();

    try {
        $requiresReceiptReview = (string) $request['status'] === 'receipt_review' || $hasVariance;

        if ($requiresReceiptReview) {
            foreach ($receiptUpdates as $update) {
                Database::execute(
                    'UPDATE item_request_lines
                     SET quantity_received = :quantity_received,
                         updated_at = NOW()
                     WHERE id = :id',
                    [
                        'quantity_received' => (float) $update['received'],
                        'id' => (int) $update['line_id'],
                    ]
                );
            }

            Database::execute(
                'UPDATE item_requests
                 SET status = "receipt_review",
                     receipt_notes = :receipt_notes,
                     receipt_reported_at = NOW(),
                     updated_by = :updated_by,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'receipt_notes' => $receiptNotes !== '' ? $receiptNotes : null,
                    'updated_by' => (int) $user['id'],
                    'id' => (int) $request['id'],
                ]
            );
        } else {
            apply_request_receipt_confirmation_movements($request, $receiptUpdates, (int) $user['id']);

            Database::execute(
                'UPDATE item_requests
                 SET status = "completed",
                     receipt_notes = :receipt_notes,
                     receipt_reported_at = NOW(),
                     completed_at = NOW(),
                     completed_by = :completed_by,
                     updated_by = :updated_by,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'receipt_notes' => $receiptNotes !== '' ? $receiptNotes : null,
                    'completed_by' => (int) $user['id'],
                    'updated_by' => (int) $user['id'],
                    'id' => (int) $request['id'],
                ]
            );
        }

        if ($storedProof !== null) {
            create_workflow_document_record(
                'request',
                (int) $request['id'],
                (string) $request['request_number'],
                'proof_image',
                'receipt_report',
                $storedProof,
                (int) $user['id']
            );
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($storedProof !== null) {
            delete_workflow_document_file((string) $storedProof['stored_filename']);
        }

        flash('danger', $exception->getMessage());
        redirect('/requests/' . $request['id']);
    }

    if ((string) $request['status'] === 'receipt_review' || $hasVariance) {
        create_notification(
            (int) $request['approver_user_id'],
            'request_receipt_review',
            'Receipt report ready for ' . $request['request_number'],
            ($user['name'] ?? 'Requester') . ' reported actual received quantities for review.',
            url('/requests/' . $request['id']),
            'request',
            (int) $request['id'],
            (int) ($user['id'] ?? 0)
        );
    } else {
        create_notification(
            (int) $request['approver_user_id'],
            'request_completed',
            'Request ' . $request['request_number'] . ' completed',
            ($user['name'] ?? 'Requester') . ' confirmed exact receipt.',
            url('/requests/' . $request['id']),
            'request',
            (int) $request['id'],
            (int) ($user['id'] ?? 0)
        );
    }

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => ((string) $request['status'] === 'receipt_review' || $hasVariance)
                ? 'Receipt report saved. Waiting for approver confirmation.'
                : 'Request completed with the reported received quantities.',
            'redirect_url' => url('/requests/' . $request['id']),
        ]);
    }

    flash('success', ((string) $request['status'] === 'receipt_review' || $hasVariance)
        ? 'Receipt report saved. Waiting for approver confirmation.'
        : 'Request completed with the reported received quantities.');
    redirect('/requests/' . $request['id']);
}

function handle_requests_confirm_receipt_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('requests.approve');
    verify_csrf();

    $request = find_request_or_abort((int) $params['id']);
    $user = Auth::user();
    $receiptConfirmBlockReason = request_receipt_confirm_block_reason($request, $user);

    if ($receiptConfirmBlockReason !== null) {
        flash('danger', $receiptConfirmBlockReason);
        redirect('/requests/' . $request['id']);
    }

    $lines = request_lines((int) $request['id']);
    $reportedInput = [];

    foreach ($lines as $line) {
        $reportedInput[(int) $line['id']] = (string) $line['quantity_received'];
    }

    [$receiptUpdates, $receiptErrors] = build_request_receipt_updates($lines, $reportedInput);

    if ($receiptErrors !== []) {
        $message = implode(' ', array_unique($receiptErrors));

        if (request_wants_json()) {
            json_response([
                'ok' => false,
                'message' => $message,
            ], 422);
        }

        flash('danger', $message);
        redirect('/requests/' . $request['id']);
    }

    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        apply_request_receipt_confirmation_movements($request, $receiptUpdates, (int) $user['id']);

        Database::execute(
            'UPDATE item_requests
             SET status = "completed",
                 completed_at = NOW(),
                 completed_by = :completed_by,
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'completed_by' => (int) $user['id'],
                'updated_by' => (int) $user['id'],
                'id' => (int) $request['id'],
            ]
        );

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/requests/' . $request['id']);
    }

    create_notification(
        (int) $request['requester_user_id'],
        'request_receipt_confirmed',
        'Receipt confirmed for ' . $request['request_number'],
        ($user['name'] ?? 'Approver') . ' approved the reported received quantities.',
        url('/requests/' . $request['id']),
        'request',
        (int) $request['id'],
        (int) ($user['id'] ?? 0)
    );

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => 'Receipt quantities approved and request closed.',
            'redirect_url' => url('/requests/' . $request['id']),
        ]);
    }

    flash('success', 'Receipt quantities approved and request closed.');
    redirect('/requests/' . $request['id']);
}

function handle_requests_cancel_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requireLogin();
    verify_csrf();

    $request = find_request_or_abort((int) $params['id']);
    $user = Auth::user();
    $cancelBlockReason = request_cancel_block_reason($request, $user);

    if ($cancelBlockReason !== null) {
        flash('danger', $cancelBlockReason);
        redirect('/requests/' . $request['id']);
    }

    $decisionNotes = trim((string) input('decision_notes'));

    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        if (in_array((string) $request['status'], ['approved', 'receipt_review'], true)) {
            $transitStorageId = system_storage_id('request_transit');

            foreach (request_lines((int) $request['id']) as $line) {
                if ((float) $line['quantity_approved'] <= 0) {
                    continue;
                }

                $item = find_item_or_abort((int) $line['item_id']);

                apply_inventory_movement(
                    $item,
                    'transfer',
                    (float) $line['quantity_approved'],
                    $transitStorageId,
                    (int) $request['source_storage_id'],
                    date('Y-m-d H:i:s'),
                    (string) $request['request_number'],
                    'Cancelled request returned from transit.',
                    (int) $user['id'],
                    'request',
                    (int) $request['id']
                );
            }
        }

        Database::execute(
            'UPDATE item_requests
             SET status = "cancelled",
                 decision_notes = :decision_notes,
                 cancelled_at = NOW(),
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'decision_notes' => $decisionNotes !== '' ? $decisionNotes : null,
                'updated_by' => (int) $user['id'],
                'id' => (int) $request['id'],
            ]
        );

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/requests/' . $request['id']);
    }

    $notificationUserIds = array_values(array_unique(array_filter([
        (int) ($request['requester_user_id'] ?? 0),
        (int) ($request['approver_user_id'] ?? 0),
    ], static fn (int $id): bool => $id > 0 && $id !== (int) ($user['id'] ?? 0))));

    foreach ($notificationUserIds as $notificationUserId) {
        create_notification(
            $notificationUserId,
            'request_cancelled',
            'Request ' . $request['request_number'] . ' cancelled',
            ($user['name'] ?? 'Someone') . ' cancelled this request.' . ($decisionNotes !== '' ? ' ' . $decisionNotes : ''),
            url('/requests/' . $request['id']),
            'request',
            (int) $request['id'],
            (int) ($user['id'] ?? 0)
        );
    }

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => 'Request cancelled.',
            'redirect_url' => url('/requests/' . $request['id']),
        ]);
    }

    flash('success', 'Request cancelled.');
    redirect('/requests/' . $request['id']);
}

function handle_requests_recover_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requireOwner();
    verify_csrf();

    $request = find_request_or_abort((int) $params['id']);
    $user = Auth::user();
    $lines = request_lines((int) $request['id']);
    $targetStatus = request_recovery_target_status($request, $lines);
    $blockReason = request_recovery_block_reason($request, $lines, $user);

    if ($targetStatus === null || $blockReason !== null) {
        flash('danger', $blockReason ?? 'This request cannot be recovered.');
        redirect('/requests/' . $request['id']);
    }

    $notes = trim((string) input('status_notes'));
    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        if (in_array($targetStatus, ['approved', 'receipt_review'], true)) {
            issue_request_inventory($request, $lines, (int) ($user['id'] ?? 0));
        }

        $existingNotes = (string) ($request['decision_notes'] ?? '');
        $recoveryNote = trim(
            $existingNotes .
            "\n\nRecovered by " . (string) ($user['name'] ?? 'Admin') . ' on ' . date('Y-m-d H:i:s') .
            ($notes !== '' ? ': ' . $notes : '.')
        );

        Database::execute(
            'UPDATE item_requests
             SET status = :status,
                 decision_notes = :decision_notes,
                 cancelled_at = NULL,
                 rejected_at = NULL,
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'status' => $targetStatus,
                'decision_notes' => $recoveryNote !== '' ? $recoveryNote : null,
                'updated_by' => (int) ($user['id'] ?? 0),
                'id' => (int) $request['id'],
            ]
        );

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/requests/' . $request['id']);
    }

    record_activity('request.recovered', 'request', (int) $request['id'], 'Recovered request ' . $request['request_number'], [
        'request_id' => (int) $request['id'],
        'request_number' => (string) $request['request_number'],
        'from_status' => (string) $request['status'],
        'to_status' => $targetStatus,
        'notes' => $notes,
    ]);

    $notificationUserIds = array_values(array_unique(array_filter([
        (int) ($request['requester_user_id'] ?? 0),
        (int) ($request['approver_user_id'] ?? 0),
    ], static fn (int $id): bool => $id > 0 && $id !== (int) ($user['id'] ?? 0))));

    foreach ($notificationUserIds as $notificationUserId) {
        create_notification(
            $notificationUserId,
            'request_recovered',
            'Request ' . $request['request_number'] . ' recovered',
            ($user['name'] ?? 'Admin') . ' reopened this request as ' . request_status_label($targetStatus) . '.',
            url('/requests/' . $request['id']),
            'request',
            (int) $request['id'],
            (int) ($user['id'] ?? 0)
        );
    }

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => 'Request recovered as ' . request_status_label($targetStatus) . '.',
            'redirect_url' => url('/requests/' . $request['id']),
        ]);
    }

    flash('success', 'Request recovered as ' . request_status_label($targetStatus) . '.');
    redirect('/requests/' . $request['id']);
}

function handle_requests_void_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requireOwner();
    verify_csrf();

    $request = find_request_or_abort((int) $params['id']);
    $user = Auth::user();
    $blockReason = workflow_void_block_reason('request', $request, $user);

    if ($blockReason !== null) {
        flash('danger', $blockReason);
        redirect('/requests/' . $request['id']);
    }

    $confirm = trim((string) input('void_confirm'));
    $notes = trim((string) input('void_notes'));
    $requestNumber = (string) $request['request_number'];

    if ($confirm !== $requestNumber) {
        flash('danger', 'Type the request number exactly to mark it void.');
        redirect('/requests/' . $request['id']);
    }

    if ($notes === '') {
        flash('danger', 'Void reason is required.');
        redirect('/requests/' . $request['id']);
    }

    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        $voidNote = trim(
            ((string) ($request['decision_notes'] ?? '')) .
            "\n\nVoided by " . (string) ($user['name'] ?? 'Owner') . ' on ' . date('Y-m-d H:i:s') . ': ' . $notes
        );

        Database::execute(
            'UPDATE item_requests
             SET status = "cancelled",
                 decision_notes = :decision_notes,
                 cancelled_at = COALESCE(cancelled_at, NOW()),
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'decision_notes' => $voidNote,
                'updated_by' => (int) ($user['id'] ?? 0),
                'id' => (int) $request['id'],
            ]
        );

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/requests/' . $request['id']);
    }

    record_activity('request.voided', 'request', (int) $request['id'], 'Marked request void ' . $requestNumber, [
        'request_id' => (int) $request['id'],
        'request_number' => $requestNumber,
        'reason' => $notes,
    ]);

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => 'Request marked void and kept for audit.',
            'redirect_url' => url('/requests/' . $request['id']),
        ]);
    }

    flash('success', 'Request marked void and kept for audit.');
    redirect('/requests/' . $request['id']);
}

function handle_export_requests(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('requests.export');

    $filters = request_filters();
    if (trim((string) query('status', '')) === '') {
        $filters['status'] = 'all';
    }
    $requests = request_summary_rows($filters);
    $rows = [];

    foreach ($requests as $request) {
        foreach (request_lines((int) $request['id']) as $line) {
            $rows[] = [
                $request['request_number'],
                request_status_label((string) $request['status']),
                $request['requester_name'],
                $request['approver_name'],
                $request['source_storage_name'],
                $request['destination_storage_name'],
                $request['requested_at'],
                $request['approved_at'] ?: '',
                $request['receipt_reported_at'] ?: '',
                $request['completed_at'] ?: '',
                $line['item_name'],
                $line['item_sku'],
                $line['unit'],
                format_quantity($line['quantity_requested']),
                format_quantity($line['quantity_approved']),
                format_quantity($line['quantity_received']),
                $request['notes'] ?: '',
                $request['decision_notes'] ?: '',
                $request['receipt_notes'] ?: '',
            ];
        }
    }

    export_csv('requests-export-' . date('Ymd-His') . '.csv', [
        'Request Number',
        'Status',
        'Requester',
        'Approver',
        'Source Storage',
        'Destination Storage',
        'Requested At',
        'Approved At',
        'Receipt Reported At',
        'Completed At',
        'Item',
        'SKU',
        'Unit',
        'Requested Quantity',
        'Approved Quantity',
        'Received Quantity',
        'Notes',
        'Decision Notes',
        'Receipt Notes',
    ], $rows);
}
