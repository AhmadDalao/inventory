<?php
declare(strict_types=1);

// Domain module: request support and inventory helpers. Function names are preserved for compatibility.


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
