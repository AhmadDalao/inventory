<?php
declare(strict_types=1);

// Domain module: handover workflow handlers. Support helpers live beside this file.

function find_handover_or_abort(int $handoverId): array
{
    [$scopeSql, $scopeParams] = visible_handover_scope('h');
    $handover = Database::fetch(
        'SELECT h.*,
                source_storage.name AS source_storage_name,
                source_storage.storage_type AS source_storage_type,
                destination_storage.name AS destination_storage_name,
                destination_storage.storage_type AS destination_storage_type,
                source_storage.owner_user_id AS source_owner_user_id,
                destination_storage.owner_user_id AS destination_owner_user_id,
                creator.name AS creator_name,
                request_approver.name AS request_approver_name,
                request_approved_by_user.name AS request_approved_by_name,
                completer.name AS completed_by_name,
                submitter.name AS submitted_by_name,
                approver.name AS approved_by_name,
                recipient.name AS recipient_user_name,
                recipient.email AS recipient_user_email,
                source_owner.name AS source_owner_name,
                destination_owner.name AS destination_owner_name,
                destination_owner.email AS destination_owner_email
         FROM handovers h
         INNER JOIN storages source_storage ON source_storage.id = h.source_storage_id
         LEFT JOIN storages destination_storage ON destination_storage.id = h.destination_storage_id
         LEFT JOIN users creator ON creator.id = h.created_by
         LEFT JOIN users request_approver ON request_approver.id = h.approver_user_id
         LEFT JOIN users request_approved_by_user ON request_approved_by_user.id = h.request_approved_by
         LEFT JOIN users submitter ON submitter.id = h.submitted_by
         LEFT JOIN users approver ON approver.id = h.approved_by
         LEFT JOIN users completer ON completer.id = h.completed_by
         LEFT JOIN users recipient ON recipient.id = h.recipient_user_id
         LEFT JOIN users source_owner ON source_owner.id = source_storage.owner_user_id
         LEFT JOIN users destination_owner ON destination_owner.id = destination_storage.owner_user_id
         WHERE h.id = :id' . $scopeSql . '
         LIMIT 1',
        ['id' => $handoverId] + $scopeParams
    );

    if (!$handover) {
        abort(404, 'Handover not found.');
    }

    return $handover;
}

function handover_lines(int $handoverId): array
{
    $lines = Database::fetchAll(
        'SELECT handover_line.*,
                i.image_path,
                i.barcode AS item_barcode
         FROM handover_lines handover_line
         INNER JOIN items i ON i.id = handover_line.item_id
         WHERE handover_line.handover_id = :handover_id
         ORDER BY handover_line.item_name ASC, handover_line.id ASC',
        ['handover_id' => $handoverId]
    );

    return hydrate_handover_lines_expected_usage_breakdowns(hydrate_handover_lines_usage_breakdowns($lines));
}

function handover_request_decision_block_reason(array $handover, ?array $user = null): ?string
{
    $user = $user ?? Auth::user();

    if ($user === null) {
        return 'Login first.';
    }

    if ((string) ($handover['status'] ?? '') !== 'requested') {
        return 'Only pending handover requests can be approved or rejected.';
    }

    if ((string) ($handover['handover_mode'] ?? 'direct') !== 'request') {
        return 'Only requested handovers use this approval step.';
    }

    if ((int) ($handover['created_by'] ?? 0) === (int) ($user['id'] ?? 0)) {
        return 'You cannot approve or reject your own handover request.';
    }

    if (!Auth::isOwner() && (int) ($handover['approver_user_id'] ?? 0) !== (int) ($user['id'] ?? 0)) {
        return 'This handover request is assigned to a different owner.';
    }

    return null;
}

function handover_line_edit_block_reason(array $handover, ?array $user = null): ?string
{
    $user = $user ?? Auth::user();

    if ($user === null) {
        return 'Login first.';
    }

    if (!handover_line_edits_enabled()) {
        return 'Handover request item editing is disabled in Website Control.';
    }

    if ((string) ($handover['status'] ?? '') !== 'requested') {
        return 'Handover items can only be edited before approval or delivery.';
    }

    if ((string) ($handover['handover_mode'] ?? 'direct') !== 'request') {
        return 'Direct handovers cannot be edited after creation. Create another handover if more items are needed.';
    }

    $userId = (int) ($user['id'] ?? 0);
    $isRequester = (int) ($handover['created_by'] ?? 0) === $userId;
    $isStorageOwner = (int) ($handover['source_owner_user_id'] ?? 0) === $userId
        || (int) ($handover['approver_user_id'] ?? 0) === $userId;

    if (!$isRequester && !$isStorageOwner && !Auth::isOwner()) {
        return 'Only the requester, storage owner, or owner can edit requested handover items.';
    }

    if (!Auth::hasAnyPermission(['handovers.request', 'handovers.create', 'handovers.approve'])) {
        return 'You do not have permission to edit requested handover items.';
    }

    return null;
}

function handover_request_cancel_block_reason(array $handover, ?array $user = null): ?string
{
    return handover_cancel_block_reason($handover, $user);
}

function handover_cancel_block_reason(array $handover, ?array $user = null): ?string
{
    $user = $user ?? Auth::user();

    if ($user === null) {
        return 'Login first.';
    }

    $status = (string) ($handover['status'] ?? '');

    if (!in_array($status, ['requested', 'awaiting_receipt', 'receipt_review', 'delivered'], true)) {
        return 'This handover cannot be cancelled at this stage. Use the active closeout or approval flow instead.';
    }

    $userId = (int) ($user['id'] ?? 0);
    $isRequester = (int) ($handover['created_by'] ?? 0) === $userId;
    $isRecipient = (int) ($handover['recipient_user_id'] ?? 0) === $userId;
    $isStorageOwner = (int) ($handover['source_owner_user_id'] ?? 0) === $userId
        || (int) ($handover['approver_user_id'] ?? 0) === $userId;
    $isOwner = Auth::isOwner();

    if (!$isRequester && !$isRecipient && !$isStorageOwner && !$isOwner && !Auth::hasAnyPermission(['handovers.request', 'handovers.approve', 'handovers.create', 'handovers.close'])) {
        return 'You do not have permission to cancel handovers.';
    }

    if ($status === 'requested') {
        if (!$isRequester && !$isStorageOwner && !$isOwner) {
            return 'Only the requester, storage owner, or owner can cancel this handover request.';
        }
    } else {
        if ($isRecipient && !$isStorageOwner && !$isOwner) {
            return 'Receivers cannot cancel issued handovers. Report the received quantity or return usage for storage owner review.';
        }

        if (!$isStorageOwner && !$isOwner) {
            return 'Only the storage owner or owner can cancel an issued handover.';
        }
    }

    if ($status === 'delivered') {
        foreach (handover_lines((int) ($handover['id'] ?? 0)) as $line) {
            if (round((float) ($line['quantity_used'] ?? 0), 2) > 0 || round((float) ($line['quantity_returned'] ?? 0), 2) > 0) {
                return 'This handover already has usage or return quantities. Submit the closeout for owner approval instead of cancelling.';
            }
        }
    }

    return null;
}

function handover_can_report_receipt(array $handover, ?array $user = null): bool
{
    $user = $user ?? Auth::user();

    if ($user === null) {
        return false;
    }

    if (!in_array((string) ($handover['status'] ?? ''), ['awaiting_receipt', 'receipt_review'], true)) {
        return false;
    }

    if (handover_is_storage_transfer($handover)) {
        $userId = (int) ($user['id'] ?? 0);

        return Auth::isOwner()
            || (int) ($handover['destination_owner_user_id'] ?? 0) === $userId
            || (int) ($handover['recipient_user_id'] ?? 0) === $userId;
    }

    if (!Auth::hasPermission('handovers.close')) {
        return false;
    }

    return (int) ($handover['recipient_user_id'] ?? 0) === (int) ($user['id'] ?? 0);
}

function handover_receipt_confirm_block_reason(array $handover, ?array $user = null): ?string
{
    $user = $user ?? Auth::user();

    if ($user === null) {
        return 'Login first.';
    }

    if ((string) ($handover['status'] ?? '') !== 'receipt_review') {
        return 'Only handovers waiting on receipt review can be confirmed.';
    }

    if (handover_is_storage_transfer($handover)) {
        if (!Auth::isOwner()
            && (int) ($handover['source_owner_user_id'] ?? 0) !== (int) ($user['id'] ?? 0)
            && (int) ($handover['created_by'] ?? 0) !== (int) ($user['id'] ?? 0)) {
            return 'Only the source storage owner can confirm this transfer shortage.';
        }

        return null;
    }

    if (!Auth::isOwner()
        && (int) ($handover['source_owner_user_id'] ?? 0) !== (int) ($user['id'] ?? 0)
        && (int) ($handover['created_by'] ?? 0) !== (int) ($user['id'] ?? 0)) {
        return 'Only the storage owner can confirm the reported receipt quantity.';
    }

    return null;
}

function issue_handover_inventory(array $handover, array $lines, int $performedBy): void
{
    $bufferStorageId = system_storage_id('handover_buffer');

    foreach ($lines as $line) {
        $plannedQuantity = round((float) ($line['quantity_handed'] ?? 0), 2);

        if ($plannedQuantity <= 0) {
            continue;
        }

        $item = find_item_or_abort((int) $line['item_id']);
        $balance = item_storage_balance_record((int) $line['item_id'], (int) $handover['source_storage_id']);

        if ($balance === null || (float) $balance['quantity'] < $plannedQuantity) {
            throw new RuntimeException($line['item_name'] . ' no longer has enough stock to issue this handover.');
        }

        apply_inventory_movement(
            $item,
            'transfer',
            $plannedQuantity,
            (int) $handover['source_storage_id'],
            $bufferStorageId,
            date('Y-m-d H:i:s'),
            (string) $handover['handover_number'],
            'Issued for handover to ' . $handover['recipient_name'] . '.',
            $performedBy,
            'handover',
            (int) $handover['id']
        );
    }
}

function finalize_handover_inventory(array $handover, array $lineUpdates, int $performedBy): void
{
    $bufferStorageId = system_storage_id('handover_buffer');

    foreach ($lineUpdates as $update) {
        $item = find_item_or_abort((int) $update['item_id']);
        $usageSummary = handover_usage_reason_summary((array) ($update['breakdowns'] ?? []), (string) ($item['unit'] ?? 'pcs'));

        if ($update['used'] > 0) {
            apply_inventory_movement(
                $item,
                'usage',
                (float) $update['used'],
                $bufferStorageId,
                null,
                date('Y-m-d H:i:s'),
                (string) $handover['handover_number'],
                'Consumed during handover.' . ($usageSummary !== '' ? ' Usage: ' . $usageSummary . '.' : ''),
                $performedBy,
                'handover',
                (int) $handover['id']
            );
        }

        if ($update['returned'] > 0) {
            apply_inventory_movement(
                $item,
                'transfer',
                (float) $update['returned'],
                $bufferStorageId,
                (int) $handover['source_storage_id'],
                date('Y-m-d H:i:s'),
                (string) $handover['handover_number'],
                'Returned from handover back into storage.',
                $performedBy,
                'handover',
                (int) $handover['id']
            );
        }
    }
}

function finalize_handover_storage_transfer_inventory(array $handover, array $receiptUpdates, int $performedBy): void
{
    if (empty($handover['destination_storage_id'])) {
        throw new RuntimeException('Storage transfer destination is missing.');
    }

    $bufferStorageId = system_storage_id('handover_buffer');

    foreach ($receiptUpdates as $update) {
        $item = find_item_or_abort((int) $update['item_id']);
        $received = round((float) ($update['received'] ?? 0), 2);
        $shortage = round((float) ($update['shortage'] ?? 0), 2);

        if ($received > 0) {
            apply_inventory_movement(
                $item,
                'transfer',
                $received,
                $bufferStorageId,
                (int) $handover['destination_storage_id'],
                date('Y-m-d H:i:s'),
                (string) $handover['handover_number'],
                'Storage transfer received into destination storage.',
                $performedBy,
                'handover',
                (int) $handover['id']
            );
        }

        if ($shortage > 0) {
            apply_inventory_movement(
                $item,
                'transfer',
                $shortage,
                $bufferStorageId,
                (int) $handover['source_storage_id'],
                date('Y-m-d H:i:s'),
                (string) $handover['handover_number'],
                'Storage transfer shortage returned to source storage.',
                $performedBy,
                'handover',
                (int) $handover['id']
            );
        }
    }
}

function handover_summary_rows(array $filters): array
{
    [$where, $params] = build_handover_where($filters);

    return Database::fetchAll(
        "SELECT h.*,
                source_storage.name AS source_storage_name,
                source_storage.storage_type AS source_storage_type,
                destination_storage.name AS destination_storage_name,
                destination_storage.storage_type AS destination_storage_type,
                creator.name AS creator_name,
                COALESCE(line_totals.line_count, 0) AS line_count,
                COALESCE(line_totals.total_handed, 0) AS total_handed,
                COALESCE(line_totals.total_used, 0) AS total_used,
                COALESCE(line_totals.total_returned, 0) AS total_returned
         FROM handovers h
         INNER JOIN storages source_storage ON source_storage.id = h.source_storage_id
         LEFT JOIN storages destination_storage ON destination_storage.id = h.destination_storage_id
         LEFT JOIN users creator ON creator.id = h.created_by
         LEFT JOIN (
             SELECT handover_id,
                    COUNT(*) AS line_count,
                    COALESCE(SUM(quantity_handed), 0) AS total_handed,
                    COALESCE(SUM(quantity_used), 0) AS total_used,
                    COALESCE(SUM(quantity_returned), 0) AS total_returned
             FROM handover_lines
             GROUP BY handover_id
         ) line_totals ON line_totals.handover_id = h.id
         {$where}
         ORDER BY h.issued_at DESC, h.id DESC
         LIMIT 250",
        $params
    );
}

function staff_dashboard_handover_cards(int $userId): array
{
    return Database::fetchAll(
        'SELECT h.id,
                h.handover_number,
                h.status,
                h.scheduled_for_date,
                h.issued_at,
                h.closed_notes,
                source_storage.name AS source_storage_name,
                source_storage.storage_type AS source_storage_type,
                handover_line.item_id,
                handover_line.item_name,
                handover_line.item_sku,
                handover_line.unit,
                handover_line.quantity_handed,
                handover_line.quantity_received,
                handover_line.quantity_used,
                handover_line.quantity_returned,
                i.image_path
         FROM handovers h
         INNER JOIN handover_lines handover_line ON handover_line.handover_id = h.id
         INNER JOIN storages source_storage ON source_storage.id = h.source_storage_id
         INNER JOIN items i ON i.id = handover_line.item_id
         WHERE h.recipient_user_id = :user_id
           AND COALESCE(h.recipient_type, "staff") = "staff"
           AND h.status IN ("awaiting_receipt", "receipt_review", "delivered", "pending_approval")
           AND (
               CASE
                   WHEN h.status IN ("awaiting_receipt", "receipt_review") THEN handover_line.quantity_handed
                   ELSE handover_line.quantity_received
               END - handover_line.quantity_used - handover_line.quantity_returned
           ) > 0
         ORDER BY COALESCE(h.scheduled_for_date, DATE(h.issued_at)) ASC, h.issued_at DESC, handover_line.item_name ASC
         LIMIT 24',
        ['user_id' => $userId]
    );
}

function handle_handovers_index(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('handovers.view');

    $user = Auth::user();

    if ($user) {
        mark_notifications_for_entity_type_as_read((int) $user['id'], 'handover');
    }

    $filters = handover_filters();
    redirect_exact_workflow_reference_search((string) $filters['search'], ['handover']);
    $handovers = handover_summary_rows($filters);

    [$handoverScopeSql, $handoverScopeParams] = visible_handover_scope('h');
    $counts = [
        'open' => (int) Database::scalar("SELECT COUNT(*) FROM handovers h WHERE h.status IN ('requested', 'awaiting_receipt', 'receipt_review', 'delivered', 'pending_approval')" . $handoverScopeSql, $handoverScopeParams),
        'requested' => (int) Database::scalar("SELECT COUNT(*) FROM handovers h WHERE h.status = 'requested'" . $handoverScopeSql, $handoverScopeParams),
        'awaiting_receipt' => (int) Database::scalar("SELECT COUNT(*) FROM handovers h WHERE h.status = 'awaiting_receipt'" . $handoverScopeSql, $handoverScopeParams),
        'receipt_review' => (int) Database::scalar("SELECT COUNT(*) FROM handovers h WHERE h.status = 'receipt_review'" . $handoverScopeSql, $handoverScopeParams),
        'delivered' => (int) Database::scalar("SELECT COUNT(*) FROM handovers h WHERE h.status = 'delivered'" . $handoverScopeSql, $handoverScopeParams),
        'pending_approval' => (int) Database::scalar("SELECT COUNT(*) FROM handovers h WHERE h.status = 'pending_approval'" . $handoverScopeSql, $handoverScopeParams),
        'closed' => (int) Database::scalar("SELECT COUNT(*) FROM handovers h WHERE h.status = 'closed'" . $handoverScopeSql, $handoverScopeParams),
        'rejected' => (int) Database::scalar("SELECT COUNT(*) FROM handovers h WHERE h.status = 'rejected'" . $handoverScopeSql, $handoverScopeParams),
        'cancelled' => (int) Database::scalar("SELECT COUNT(*) FROM handovers h WHERE h.status = 'cancelled'" . $handoverScopeSql, $handoverScopeParams),
    ];

    View::render('handovers/index', [
        'title' => site_setting('page.handovers', 'Handovers'),
        'filters' => $filters,
        'handovers' => $handovers,
        'counts' => $counts,
        'storages' => all_storages_for_select($filters['storage_id']),
    ]);
}

function handle_handovers_create_page(): void
{
    app_ready_or_redirect();
    Auth::requireLogin();

    if (Auth::isStaff()) {
        Auth::requirePermission('handovers.request');
    } else {
        Auth::requirePermission('handovers.create');
    }

    $currentUser = Auth::user() ?? [];
    $selectedSourceStorageId = normalize_entity_id(old('source_storage_id', ''));
    $selectedDestinationStorageId = normalize_entity_id(old('destination_storage_id', ''));
    $selectedRecipientUserId = normalize_entity_id(old('recipient_user_id', ''));
    $selectedRequestOwnerId = normalize_entity_id(old('request_owner_user_id', ''));
    $selectedRecipientType = Auth::isStaff() ? 'staff' : (in_array((string) old('recipient_type', 'staff'), ['staff', 'storage'], true) ? (string) old('recipient_type', 'staff') : 'staff');
    $lockedRequestOwner = Auth::isStaff() ? handover_request_assigned_owner($currentUser) : null;
    $sourceStorages = Auth::isStaff()
        ? handover_request_source_storages_for_staff($currentUser, $selectedSourceStorageId, $selectedRequestOwnerId)
        : handover_source_storages_for_user($currentUser, $selectedSourceStorageId);

    View::render('handovers/form', [
        'title' => Auth::isStaff() ? 'Request Handover' : 'Create Handover',
        'handoverRecord' => [
            'source_storage_id' => old('source_storage_id', ''),
            'destination_storage_id' => old('destination_storage_id', ''),
            'recipient_type' => $selectedRecipientType,
            'request_owner_user_id' => old('request_owner_user_id', $lockedRequestOwner ? (string) $lockedRequestOwner['id'] : ''),
            'recipient_name' => Auth::isStaff() ? (string) ($currentUser['name'] ?? '') : old('recipient_name', ''),
            'recipient_user_id' => Auth::isStaff() ? (string) ($currentUser['id'] ?? '') : old('recipient_user_id', ''),
            'scheduled_for_date' => old('scheduled_for_date', ''),
            'notes' => old('notes', ''),
        ],
        'lineItems' => old('line_items', [['item_id' => '', 'quantity' => '']]),
        'sourceStorages' => $sourceStorages,
        'destinationStorages' => Auth::isStaff() ? [] : handover_destination_storages_for_select($selectedDestinationStorageId),
        'users' => Auth::isStaff() ? [] : active_staff_users_for_select($selectedRecipientUserId),
        'ownerCandidates' => Auth::isStaff() && !$lockedRequestOwner ? handover_request_owner_candidates_for_select($selectedRequestOwnerId) : [],
        'lockedRequestOwner' => $lockedRequestOwner,
        'isStaffRequest' => Auth::isStaff(),
        'storageCatalogJson' => json_encode(workflow_storage_item_catalog(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'storageMetaJson' => json_encode(workflow_storage_meta($sourceStorages), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
}

function handle_handovers_create_submit(): void
{
    app_ready_or_redirect();
    Auth::requireLogin();

    if (Auth::isStaff()) {
        Auth::requirePermission('handovers.request');
    } else {
        Auth::requirePermission('handovers.create');
    }

    verify_csrf();

    $user = Auth::user();
    $isStaffRequest = Auth::isStaff();
    [$lines, $lineErrors] = parse_workflow_lines();
    $recipientType = $isStaffRequest ? 'staff' : (in_array((string) input('recipient_type', 'staff'), ['staff', 'storage'], true) ? (string) input('recipient_type', 'staff') : 'staff');
    $isStorageTransfer = $recipientType === 'storage';
    [$expectedUsageByItem, $expectedUsageErrors] = $isStorageTransfer ? [[], []] : parse_handover_expected_usage_by_item($lines);
    $payload = [
        'source_storage_id' => normalize_entity_id(input('source_storage_id')),
        'destination_storage_id' => $isStorageTransfer ? normalize_entity_id(input('destination_storage_id')) : null,
        'recipient_type' => $recipientType,
        'request_owner_user_id' => normalize_entity_id(input('request_owner_user_id')),
        'recipient_name' => $isStaffRequest ? trim((string) ($user['name'] ?? '')) : trim((string) input('recipient_name')),
        'recipient_user_id' => $isStaffRequest ? (int) ($user['id'] ?? 0) : normalize_entity_id(input('recipient_user_id')),
        'scheduled_for_date' => normalize_workflow_date(trim((string) input('scheduled_for_date'))),
        'notes' => trim((string) input('notes')),
    ];

    flash_old_input([
        'source_storage_id' => (string) ($payload['source_storage_id'] ?? ''),
        'destination_storage_id' => (string) ($payload['destination_storage_id'] ?? ''),
        'recipient_type' => $payload['recipient_type'],
        'request_owner_user_id' => (string) ($payload['request_owner_user_id'] ?? ''),
        'recipient_name' => $payload['recipient_name'],
        'recipient_user_id' => (string) ($payload['recipient_user_id'] ?? ''),
        'scheduled_for_date' => $payload['scheduled_for_date'],
        'notes' => $payload['notes'],
        'line_items' => array_map(static fn (array $line): array => [
            'item_id' => (string) $line['item_id'],
            'quantity' => format_quantity($line['quantity']),
        ], $lines),
        'expected_usage_reason' => input('expected_usage_reason', []),
        'expected_usage_quantity' => input('expected_usage_quantity', []),
        'expected_usage_other' => input('expected_usage_other', []),
        'expected_usage_notes' => input('expected_usage_notes', []),
    ]);

    $errors = array_merge($lineErrors, $expectedUsageErrors);

    if (!$payload['source_storage_id'] || !storage_exists_for_assignment($payload['source_storage_id'])) {
        $errors[] = 'Pick a valid source storage.';
    } elseif (!$isStaffRequest && !Auth::isOwner() && !storage_is_owned_by_user((int) $payload['source_storage_id'], (int) ($user['id'] ?? 0))) {
        $errors[] = 'You can only create handovers from storages you own.';
    }

    if (!$isStorageTransfer && $payload['recipient_name'] === '' && !$payload['recipient_user_id']) {
        $errors[] = 'Enter a recipient name or choose a user.';
    }

    $sourceOwner = $payload['source_storage_id'] ? storage_owner_record((int) $payload['source_storage_id']) : null;
    $destinationOwner = null;
    $assignedRequestOwnerId = $isStaffRequest ? normalize_entity_id($user['assigned_owner_user_id'] ?? null) : null;
    $expectedRequestOwnerId = $assignedRequestOwnerId ?? $payload['request_owner_user_id'];
    $recipientUser = null;

    if ($isStaffRequest) {
        if (!$sourceOwner || empty($sourceOwner['owner_user_id']) || (int) ($sourceOwner['owner_is_active'] ?? 0) !== 1) {
            $errors[] = 'This storage needs an active owner before a handover request can be sent.';
        }

        if ($expectedRequestOwnerId === null) {
            $errors[] = 'Pick who you are requesting this handover from.';
        }

        if ($expectedRequestOwnerId !== null && $sourceOwner && (int) ($sourceOwner['owner_user_id'] ?? 0) !== (int) $expectedRequestOwnerId) {
            $errors[] = 'Pick a storage owned by the selected handover approver.';
        }
    }

    if ($isStorageTransfer) {
        if (!$payload['destination_storage_id'] || !storage_exists_for_assignment($payload['destination_storage_id'])) {
            $errors[] = 'Pick a valid destination storage.';
        } elseif ((int) $payload['destination_storage_id'] === (int) $payload['source_storage_id']) {
            $errors[] = 'Source and destination storage cannot be the same.';
        } else {
            $destinationOwner = storage_owner_record((int) $payload['destination_storage_id']);

            if (!$destinationOwner || empty($destinationOwner['owner_user_id']) || (int) ($destinationOwner['owner_is_active'] ?? 0) !== 1) {
                $errors[] = 'Destination storage needs an active owner before stock can be transferred.';
            } else {
                $payload['recipient_user_id'] = (int) $destinationOwner['owner_user_id'];
                $payload['recipient_name'] = (string) ($destinationOwner['owner_name'] ?: $destinationOwner['storage_name']);
            }
        }
    } elseif ($payload['recipient_user_id']) {
        $recipientUser = Database::fetch(
            'SELECT id, name, role, is_active
             FROM users
             WHERE id = :id
             LIMIT 1',
            ['id' => $payload['recipient_user_id']]
        );

        if (!$recipientUser || (int) ($recipientUser['is_active'] ?? 0) !== 1) {
            $errors[] = 'Pick a valid active recipient user.';
        } elseif (($recipientUser['role'] ?? '') !== 'staff') {
            $errors[] = 'Handovers can only be assigned to staff accounts.';
        } elseif ($payload['recipient_name'] === '') {
            $payload['recipient_name'] = (string) $recipientUser['name'];
        }
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

        if (!$isStaffRequest && (float) $balance['quantity'] < (float) $line['quantity']) {
            $errors[] = $item['name'] . ' does not have enough stock for this handover.';
            continue;
        }

        $itemsById[(int) $item['id']] = $item;
    }

    if ($errors !== []) {
        flash_errors($errors);
        redirect('/handovers/create');
    }

    $handoverNumber = next_workflow_number('HDO', 'handovers', 'handover_number');
    $initialStatus = $isStaffRequest
        ? 'requested'
        : ($isStorageTransfer || $payload['recipient_user_id'] ? 'awaiting_receipt' : 'delivered');
    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        Database::execute(
            'INSERT INTO handovers (
                handover_number,
                source_storage_id,
                destination_storage_id,
                approver_user_id,
                recipient_name,
                recipient_user_id,
                recipient_type,
                handover_mode,
                status,
                scheduled_for_date,
                notes,
                request_decision_notes,
                receipt_notes,
                closed_notes,
                requested_at,
                issued_at,
                request_approved_at,
                request_rejected_at,
                receipt_reported_at,
                cancelled_at,
                created_by,
                request_approved_by,
                updated_by,
                created_at,
                updated_at
             ) VALUES (
                :handover_number,
                :source_storage_id,
                :destination_storage_id,
                :approver_user_id,
                :recipient_name,
                :recipient_user_id,
                :recipient_type,
                :handover_mode,
                :status,
                :scheduled_for_date,
                :notes,
                NULL,
                NULL,
                NULL,
                :requested_at,
                NOW(),
                NULL,
                NULL,
                NULL,
                NULL,
                :created_by,
                NULL,
                :updated_by,
                NOW(),
                NOW()
             )',
            [
                'handover_number' => $handoverNumber,
                'source_storage_id' => (int) $payload['source_storage_id'],
                'destination_storage_id' => $payload['destination_storage_id'] !== null ? (int) $payload['destination_storage_id'] : null,
                'approver_user_id' => $sourceOwner['owner_user_id'] ?? null,
                'recipient_name' => $payload['recipient_name'],
                'recipient_user_id' => $payload['recipient_user_id'],
                'recipient_type' => $payload['recipient_type'],
                'handover_mode' => $isStaffRequest ? 'request' : 'direct',
                'status' => $initialStatus,
                'scheduled_for_date' => $payload['scheduled_for_date'] !== '' ? $payload['scheduled_for_date'] : null,
                'notes' => $payload['notes'] !== '' ? $payload['notes'] : null,
                'requested_at' => $isStaffRequest ? date('Y-m-d H:i:s') : null,
                'created_by' => (int) $user['id'],
                'updated_by' => (int) $user['id'],
            ]
        );

        $handoverId = Database::lastInsertId();
        $expectedUsageUpdates = [];

        foreach ($lines as $line) {
            $item = $itemsById[(int) $line['item_id']];

            Database::execute(
                'INSERT INTO handover_lines (
                    handover_id,
                    item_id,
                    item_name,
                    item_sku,
                    unit,
                    quantity_handed,
                    quantity_received,
                    quantity_used,
                    quantity_returned,
                    created_at,
                    updated_at
                 ) VALUES (
                    :handover_id,
                    :item_id,
                    :item_name,
                    :item_sku,
                    :unit,
                    :quantity_handed,
                    :quantity_received,
                    0,
                    0,
                    NOW(),
                    NOW()
                 )',
                [
                    'handover_id' => $handoverId,
                    'item_id' => (int) $item['id'],
                    'item_name' => $item['name'],
                    'item_sku' => $item['sku'],
                    'unit' => $item['unit'],
                    'quantity_handed' => $line['quantity'],
                    'quantity_received' => $payload['recipient_user_id'] ? 0 : $line['quantity'],
                ]
            );

            $lineId = Database::lastInsertId();

            if (!$isStorageTransfer && !empty($expectedUsageByItem[(int) $item['id']])) {
                $expectedUsageUpdates[] = [
                    'line_id' => $lineId,
                    'item_id' => (int) $item['id'],
                    'breakdowns' => $expectedUsageByItem[(int) $item['id']],
                ];
            }
        }

        save_handover_expected_usage_breakdowns($handoverId, $expectedUsageUpdates, (int) $user['id']);

        if (!$isStaffRequest) {
            issue_handover_inventory([
                'id' => $handoverId,
                'handover_number' => $handoverNumber,
                'source_storage_id' => (int) $payload['source_storage_id'],
                'destination_storage_id' => $payload['destination_storage_id'] !== null ? (int) $payload['destination_storage_id'] : null,
                'recipient_name' => $payload['recipient_name'],
                'recipient_type' => $payload['recipient_type'],
            ], array_map(static function (array $line) use ($itemsById): array {
                $item = $itemsById[(int) $line['item_id']];

                return [
                    'item_id' => (int) $item['id'],
                    'item_name' => (string) $item['name'],
                    'quantity_handed' => (float) $line['quantity'],
                ];
            }, $lines), (int) $user['id']);
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/handovers/create');
    }

    if ($isStaffRequest && !empty($sourceOwner['owner_user_id'])) {
        create_notification(
            (int) $sourceOwner['owner_user_id'],
            'handover_requested',
            'New handover request ' . $handoverNumber,
            ($user['name'] ?? 'Staff') . ' requested a temporary handover from ' . ($sourceOwner['storage_name'] ?? 'your storage') . '.',
            url('/handovers/' . $handoverId),
            'handover',
            $handoverId,
            (int) ($user['id'] ?? 0)
        );
    } elseif ($isStorageTransfer && $payload['recipient_user_id']) {
        create_notification(
            (int) $payload['recipient_user_id'],
            'handover_storage_transfer_created',
            'Storage transfer ' . $handoverNumber . ' awaiting receipt',
            'Confirm what arrived into ' . (string) ($destinationOwner['storage_name'] ?? 'your destination storage') . '.',
            url('/handovers/' . $handoverId),
            'handover',
            $handoverId,
            (int) ($user['id'] ?? 0)
        );
    } elseif ($payload['recipient_user_id']) {
        create_notification(
            (int) $payload['recipient_user_id'],
            'handover_created',
            'New handover ' . $handoverNumber,
            'Confirm the actual received quantity before you start using these items.',
            url('/handovers/' . $handoverId),
            'handover',
            $handoverId,
            (int) ($user['id'] ?? 0)
        );
    }

    consume_old_input();
    flash('success', $isStaffRequest ? 'Handover request created.' : ($isStorageTransfer ? 'Storage transfer handover created.' : 'Handover created.'));
    redirect('/handovers/' . $handoverId);
}

function handle_handovers_show(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('handovers.view');

    $handover = find_handover_or_abort((int) $params['id']);
    $user = Auth::user();

    if ($user) {
        mark_notifications_for_entity_as_read((int) $user['id'], 'handover', (int) $handover['id']);
    }

    $lines = handover_lines((int) $handover['id']);

    try {
        ensure_workflow_signoff_pdf('handover', $handover, $lines);
    } catch (Throwable $exception) {
        // The workflow page must stay usable even if attachment generation fails.
    }

    $sourceStorage = Database::fetch(
        'SELECT s.id,
                s.name,
                s.storage_type,
                s.owner_user_id,
                owner.name AS owner_name
         FROM storages s
         LEFT JOIN users owner ON owner.id = s.owner_user_id
         WHERE s.id = :id
         LIMIT 1',
        ['id' => (int) $handover['source_storage_id']]
    );
    $lineEditBlockReason = handover_line_edit_block_reason($handover, $user);

    View::render('handovers/show', [
        'title' => $handover['handover_number'],
        'handoverRecord' => $handover,
        'lines' => $lines,
        'documents' => workflow_documents('handover', (int) $handover['id']),
        'canEditHandoverLines' => $lineEditBlockReason === null,
        'lineEditBlockReason' => $lineEditBlockReason,
        'sourceStorages' => $sourceStorage ? [$sourceStorage] : [],
        'storageCatalogJson' => json_encode(workflow_storage_item_catalog(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'storageMetaJson' => json_encode(workflow_storage_meta($sourceStorage ? [$sourceStorage] : []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
}

function handle_handovers_lines_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requireLogin();
    verify_csrf();

    $handover = find_handover_or_abort((int) $params['id']);
    $user = Auth::user();
    $blockReason = handover_line_edit_block_reason($handover, $user);

    if ($blockReason !== null) {
        flash('danger', $blockReason);
        redirect('/handovers/' . $handover['id']);
    }

    [$lines, $lineErrors] = parse_workflow_lines();
    flash_old_input([
        'edit_line_items' => array_map(static fn (array $line): array => [
            'item_id' => (string) $line['item_id'],
            'quantity' => format_quantity($line['quantity']),
        ], $lines),
    ]);

    $errors = $lineErrors;
    $sourceStorageId = (int) ($handover['source_storage_id'] ?? 0);
    $itemsById = [];

    if ($sourceStorageId <= 0 || !storage_exists_for_assignment($sourceStorageId)) {
        $errors[] = 'The source storage is no longer available.';
    }

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

        if (item_storage_balance_record((int) $item['id'], $sourceStorageId) === null) {
            $errors[] = $item['name'] . ' is not assigned to ' . ($handover['source_storage_name'] ?? 'the source storage') . '.';
            continue;
        }

        $itemsById[(int) $item['id']] = $item;
    }

    if ($errors !== []) {
        flash_errors($errors);
        redirect('/handovers/' . $handover['id']);
    }

    $previousLines = handover_lines((int) $handover['id']);
    $previousLineIds = array_map(static fn (array $line): int => (int) $line['id'], $previousLines);
    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        if ($previousLineIds !== []) {
            Database::execute(
                'DELETE FROM handover_usage_breakdowns
                 WHERE handover_id = :handover_id
                   AND handover_line_id IN (' . implode(',', $previousLineIds) . ')',
                ['handover_id' => (int) $handover['id']]
            );
        }

        Database::execute(
            'DELETE FROM handover_lines
             WHERE handover_id = :handover_id',
            ['handover_id' => (int) $handover['id']]
        );

        foreach ($lines as $line) {
            $item = $itemsById[(int) $line['item_id']];

            Database::execute(
                'INSERT INTO handover_lines (
                    handover_id,
                    item_id,
                    item_name,
                    item_sku,
                    unit,
                    quantity_handed,
                    quantity_received,
                    quantity_used,
                    quantity_returned,
                    created_at,
                    updated_at
                 ) VALUES (
                    :handover_id,
                    :item_id,
                    :item_name,
                    :item_sku,
                    :unit,
                    :quantity_handed,
                    0,
                    0,
                    0,
                    NOW(),
                    NOW()
                 )',
                [
                    'handover_id' => (int) $handover['id'],
                    'item_id' => (int) $item['id'],
                    'item_name' => $item['name'],
                    'item_sku' => $item['sku'],
                    'unit' => $item['unit'],
                    'quantity_handed' => $line['quantity'],
                ]
            );
        }

        Database::execute(
            'UPDATE handovers
             SET updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'updated_by' => (int) ($user['id'] ?? 0),
                'id' => (int) $handover['id'],
            ]
        );

        record_activity('handover.lines_updated', 'handover', (int) $handover['id'], 'Updated requested handover items ' . $handover['handover_number'], [
            'old_lines' => array_map(static fn (array $line): array => [
                'item_id' => (int) $line['item_id'],
                'quantity' => (float) $line['quantity_handed'],
            ], $previousLines),
            'new_lines' => array_map(static fn (array $line): array => [
                'item_id' => (int) $line['item_id'],
                'quantity' => (float) $line['quantity'],
            ], $lines),
        ]);

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/handovers/' . $handover['id']);
    }

    $recipientIds = array_values(array_unique(array_filter([
        (int) ($handover['created_by'] ?? 0),
        (int) ($handover['approver_user_id'] ?? 0),
    ], static fn (int $recipientId): bool => $recipientId > 0 && $recipientId !== (int) ($user['id'] ?? 0))));

    foreach ($recipientIds as $recipientId) {
        create_notification(
            $recipientId,
            'handover_lines_updated',
            'Handover request ' . $handover['handover_number'] . ' updated',
            ($user['name'] ?? 'A user') . ' changed the requested item lines before approval.',
            url('/handovers/' . $handover['id']),
            'handover',
            (int) $handover['id'],
            (int) ($user['id'] ?? 0)
        );
    }

    try {
        $updatedHandover = find_handover_or_abort((int) $handover['id']);
        ensure_workflow_signoff_pdf('handover', $updatedHandover, handover_lines((int) $handover['id']));
    } catch (Throwable $exception) {
        // Attachment regeneration should not block the saved edit.
    }

    consume_old_input();
    flash('success', 'Requested handover items updated.');
    redirect('/handovers/' . $handover['id']);
}

function handle_handovers_approve_request_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('handovers.approve');
    verify_csrf();

    $handover = find_handover_or_abort((int) $params['id']);
    $user = Auth::user();
    $decisionBlockReason = handover_request_decision_block_reason($handover, $user);

    if ($decisionBlockReason !== null) {
        flash('danger', $decisionBlockReason);
        redirect('/handovers/' . $handover['id']);
    }

    $decisionNotes = trim((string) input('request_decision_notes'));
    $lines = handover_lines((int) $handover['id']);
    $initialStatus = !empty($handover['recipient_user_id']) ? 'awaiting_receipt' : 'delivered';
    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        issue_handover_inventory($handover, $lines, (int) $user['id']);

        Database::execute(
            'UPDATE handovers
             SET status = :status,
                 request_decision_notes = :request_decision_notes,
                 request_approved_at = NOW(),
                 request_approved_by = :request_approved_by,
                 issued_at = NOW(),
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'status' => $initialStatus,
                'request_decision_notes' => $decisionNotes !== '' ? $decisionNotes : null,
                'request_approved_by' => (int) $user['id'],
                'updated_by' => (int) $user['id'],
                'id' => (int) $handover['id'],
            ]
        );

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/handovers/' . $handover['id']);
    }

    if (!empty($handover['recipient_user_id'])) {
        create_notification(
            (int) $handover['recipient_user_id'],
            'handover_request_approved',
            'Handover request ' . $handover['handover_number'] . ' approved',
            'Your request is approved. Confirm the actual received quantity once you get the items.',
            url('/handovers/' . $handover['id']),
            'handover',
            (int) $handover['id'],
            (int) ($user['id'] ?? 0)
        );
    }

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => 'Handover request approved.',
            'redirect_url' => url('/handovers/' . $handover['id']),
        ]);
    }

    flash('success', 'Handover request approved.');
    redirect('/handovers/' . $handover['id']);
}

function handle_handovers_reject_request_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('handovers.approve');
    verify_csrf();

    $handover = find_handover_or_abort((int) $params['id']);
    $user = Auth::user();
    $decisionBlockReason = handover_request_decision_block_reason($handover, $user);

    if ($decisionBlockReason !== null) {
        flash('danger', $decisionBlockReason);
        redirect('/handovers/' . $handover['id']);
    }

    $decisionNotes = trim((string) input('request_decision_notes'));

    Database::execute(
        'UPDATE handovers
         SET status = "rejected",
             request_decision_notes = :request_decision_notes,
             request_rejected_at = NOW(),
             updated_by = :updated_by,
             updated_at = NOW()
         WHERE id = :id',
        [
            'request_decision_notes' => $decisionNotes !== '' ? $decisionNotes : null,
            'updated_by' => (int) $user['id'],
            'id' => (int) $handover['id'],
        ]
    );

    if (!empty($handover['recipient_user_id'])) {
        create_notification(
            (int) $handover['recipient_user_id'],
            'handover_request_rejected',
            'Handover request ' . $handover['handover_number'] . ' rejected',
            $decisionNotes !== '' ? $decisionNotes : 'The storage owner rejected this handover request.',
            url('/handovers/' . $handover['id']),
            'handover',
            (int) $handover['id'],
            (int) ($user['id'] ?? 0)
        );
    }

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => 'Handover request rejected.',
            'redirect_url' => url('/handovers/' . $handover['id']),
        ]);
    }

    flash('success', 'Handover request rejected.');
    redirect('/handovers/' . $handover['id']);
}

function handle_handovers_cancel_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requireLogin();
    verify_csrf();

    $handover = find_handover_or_abort((int) $params['id']);
    $user = Auth::user();
    $cancelBlockReason = handover_cancel_block_reason($handover, $user);

    if ($cancelBlockReason !== null) {
        flash('danger', $cancelBlockReason);
        redirect('/handovers/' . $handover['id']);
    }

    $cancelNotes = trim((string) input('cancel_notes', (string) input('request_decision_notes')));

    $lines = handover_lines((int) $handover['id']);
    $requestDecisionNotes = (string) ($handover['request_decision_notes'] ?? '');
    $closedNotes = (string) ($handover['closed_notes'] ?? '');

    if ($cancelNotes !== '') {
        if ((string) ($handover['status'] ?? '') === 'requested') {
            $requestDecisionNotes = $cancelNotes;
        } else {
            $closedNotes = $cancelNotes;
        }
    }

    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        cancel_handover_inventory($handover, $lines, (int) ($user['id'] ?? 0));

        Database::execute(
            'UPDATE handovers
             SET status = "cancelled",
                 request_decision_notes = :request_decision_notes,
                 closed_notes = :closed_notes,
                 cancelled_at = NOW(),
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'request_decision_notes' => $requestDecisionNotes !== '' ? $requestDecisionNotes : null,
                'closed_notes' => $closedNotes !== '' ? $closedNotes : null,
                'updated_by' => (int) ($user['id'] ?? 0),
                'id' => (int) $handover['id'],
            ]
        );

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/handovers/' . $handover['id']);
    }

    $notificationUserIds = array_values(array_unique(array_filter([
        (int) ($handover['created_by'] ?? 0),
        (int) ($handover['recipient_user_id'] ?? 0),
        (int) ($handover['approver_user_id'] ?? 0),
        (int) ($handover['source_owner_user_id'] ?? 0),
    ], static fn (int $id): bool => $id > 0 && $id !== (int) ($user['id'] ?? 0))));

    foreach ($notificationUserIds as $notificationUserId) {
        create_notification(
            $notificationUserId,
            'handover_cancelled',
            'Handover ' . $handover['handover_number'] . ' cancelled',
            ($user['name'] ?? 'Someone') . ' cancelled this handover.' . ($cancelNotes !== '' ? ' ' . $cancelNotes : ''),
            url('/handovers/' . $handover['id']),
            'handover',
            (int) $handover['id'],
            (int) ($user['id'] ?? 0)
        );
    }

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => 'Handover cancelled.',
            'redirect_url' => url('/handovers/' . $handover['id']),
        ]);
    }

    flash('success', 'Handover cancelled.');
    redirect('/handovers/' . $handover['id']);
}

function handle_handovers_recover_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requireOwner();
    verify_csrf();

    $handover = find_handover_or_abort((int) $params['id']);
    $user = Auth::user();
    $lines = handover_lines((int) $handover['id']);
    $targetStatus = handover_recovery_target_status($handover, $lines);
    $blockReason = handover_recovery_block_reason($handover, $lines, $user);

    if ($targetStatus === null || $blockReason !== null) {
        flash('danger', $blockReason ?? 'This handover cannot be recovered.');
        redirect('/handovers/' . $handover['id']);
    }

    $notes = trim((string) input('status_notes'));
    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        if ($targetStatus !== 'requested') {
            issue_handover_inventory($handover, $lines, (int) ($user['id'] ?? 0));
        }

        $noteColumn = $targetStatus === 'requested' ? 'request_decision_notes' : 'closed_notes';
        $existingNotes = (string) ($handover[$noteColumn] ?? '');
        $recoveryNote = trim(
            $existingNotes .
            "\n\nRecovered by " . (string) ($user['name'] ?? 'Admin') . ' on ' . date('Y-m-d H:i:s') .
            ($notes !== '' ? ': ' . $notes : '.')
        );

        Database::execute(
            'UPDATE handovers
             SET status = :status,
                 ' . $noteColumn . ' = :status_notes,
                 cancelled_at = NULL,
                 request_rejected_at = NULL,
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'status' => $targetStatus,
                'status_notes' => $recoveryNote !== '' ? $recoveryNote : null,
                'updated_by' => (int) ($user['id'] ?? 0),
                'id' => (int) $handover['id'],
            ]
        );

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/handovers/' . $handover['id']);
    }

    record_activity('handover.recovered', 'handover', (int) $handover['id'], 'Recovered handover ' . $handover['handover_number'], [
        'handover_id' => (int) $handover['id'],
        'handover_number' => (string) $handover['handover_number'],
        'from_status' => (string) $handover['status'],
        'to_status' => $targetStatus,
        'notes' => $notes,
    ]);

    $notificationUserIds = array_values(array_unique(array_filter([
        (int) ($handover['created_by'] ?? 0),
        (int) ($handover['recipient_user_id'] ?? 0),
        (int) ($handover['approver_user_id'] ?? 0),
        (int) ($handover['source_owner_user_id'] ?? 0),
    ], static fn (int $id): bool => $id > 0 && $id !== (int) ($user['id'] ?? 0))));

    foreach ($notificationUserIds as $notificationUserId) {
        create_notification(
            $notificationUserId,
            'handover_recovered',
            'Handover ' . $handover['handover_number'] . ' recovered',
            ($user['name'] ?? 'Admin') . ' reopened this handover as ' . handover_status_label($targetStatus) . '.',
            url('/handovers/' . $handover['id']),
            'handover',
            (int) $handover['id'],
            (int) ($user['id'] ?? 0)
        );
    }

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => 'Handover recovered as ' . handover_status_label($targetStatus) . '.',
            'redirect_url' => url('/handovers/' . $handover['id']),
        ]);
    }

    flash('success', 'Handover recovered as ' . handover_status_label($targetStatus) . '.');
    redirect('/handovers/' . $handover['id']);
}

function handle_handovers_status_override_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requireOwner();
    verify_csrf();

    $handover = find_handover_or_abort((int) $params['id']);
    $user = Auth::user();
    $lines = handover_lines((int) $handover['id']);
    $targetStatus = trim((string) input('target_status'));
    $notes = trim((string) input('status_notes'));
    $blockReason = handover_status_override_block_reason($handover, $lines, $targetStatus, $user);

    if ($blockReason !== null) {
        if (request_wants_json()) {
            json_response([
                'ok' => false,
                'message' => $blockReason,
            ], 422);
        }

        flash('danger', $blockReason);
        redirect('/handovers/' . $handover['id']);
    }

    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        apply_handover_status_override($handover, $lines, $targetStatus, (int) ($user['id'] ?? 0), $notes);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if (request_wants_json()) {
            json_response([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        flash('danger', $exception->getMessage());
        redirect('/handovers/' . $handover['id']);
    }

    record_activity('handover.status_override', 'handover', (int) $handover['id'], 'Changed handover status ' . $handover['handover_number'], [
        'handover_id' => (int) $handover['id'],
        'handover_number' => (string) $handover['handover_number'],
        'from_status' => (string) $handover['status'],
        'to_status' => $targetStatus,
        'notes' => $notes,
    ]);

    $notificationUserIds = array_values(array_unique(array_filter([
        (int) ($handover['created_by'] ?? 0),
        (int) ($handover['recipient_user_id'] ?? 0),
        (int) ($handover['approver_user_id'] ?? 0),
        (int) ($handover['source_owner_user_id'] ?? 0),
    ], static fn (int $id): bool => $id > 0 && $id !== (int) ($user['id'] ?? 0))));

    foreach ($notificationUserIds as $notificationUserId) {
        create_notification(
            $notificationUserId,
            'handover_status_override',
            'Handover ' . $handover['handover_number'] . ' status changed',
            ($user['name'] ?? 'Admin') . ' changed this handover from ' . handover_status_label((string) $handover['status']) . ' to ' . handover_status_label($targetStatus) . '.',
            url('/handovers/' . $handover['id']),
            'handover',
            (int) $handover['id'],
            (int) ($user['id'] ?? 0)
        );
    }

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => 'Handover status changed to ' . handover_status_label($targetStatus) . '.',
            'redirect_url' => url('/handovers/' . $handover['id']),
        ]);
    }

    flash('success', 'Handover status changed to ' . handover_status_label($targetStatus) . '.');
    redirect('/handovers/' . $handover['id']);
}

function handle_handovers_void_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requireOwner();
    verify_csrf();

    $handover = find_handover_or_abort((int) $params['id']);
    $user = Auth::user();
    $blockReason = workflow_void_block_reason('handover', $handover, $user);

    if ($blockReason !== null) {
        flash('danger', $blockReason);
        redirect('/handovers/' . $handover['id']);
    }

    $confirm = trim((string) input('void_confirm'));
    $notes = trim((string) input('void_notes'));
    $handoverNumber = (string) $handover['handover_number'];

    if ($confirm !== $handoverNumber) {
        flash('danger', 'Type the handover number exactly to mark it void.');
        redirect('/handovers/' . $handover['id']);
    }

    if ($notes === '') {
        flash('danger', 'Void reason is required.');
        redirect('/handovers/' . $handover['id']);
    }

    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        $noteColumn = (string) ($handover['status'] ?? '') === 'requested' ? 'request_decision_notes' : 'closed_notes';
        $existingNote = (string) ($handover[$noteColumn] ?? '');
        $voidNote = trim(
            $existingNote .
            "\n\nVoided by " . (string) ($user['name'] ?? 'Owner') . ' on ' . date('Y-m-d H:i:s') . ': ' . $notes
        );

        Database::execute(
            'UPDATE handovers
             SET status = "cancelled",
                 ' . $noteColumn . ' = :void_notes,
                 cancelled_at = COALESCE(cancelled_at, NOW()),
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'void_notes' => $voidNote,
                'updated_by' => (int) ($user['id'] ?? 0),
                'id' => (int) $handover['id'],
            ]
        );

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/handovers/' . $handover['id']);
    }

    record_activity('handover.voided', 'handover', (int) $handover['id'], 'Marked handover void ' . $handoverNumber, [
        'handover_id' => (int) $handover['id'],
        'handover_number' => $handoverNumber,
        'reason' => $notes,
    ]);

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => 'Handover marked void and kept for audit.',
            'redirect_url' => url('/handovers/' . $handover['id']),
        ]);
    }

    flash('success', 'Handover marked void and kept for audit.');
    redirect('/handovers/' . $handover['id']);
}

function handle_handovers_receive_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requireLogin();
    verify_csrf();

    $handover = find_handover_or_abort((int) $params['id']);
    $user = Auth::user();
    $isStorageTransfer = handover_is_storage_transfer($handover);

    if (!handover_can_report_receipt($handover, $user)) {
        flash('danger', $isStorageTransfer
            ? 'Only the destination storage owner can report transfer receipt quantities.'
            : 'Only the assigned recipient can report received quantities.');
        redirect('/handovers/' . $handover['id']);
    }

    $lines = handover_lines((int) $handover['id']);
    $receiptNotes = trim((string) input('receipt_notes'));
    [$receiptUpdates, $receiptErrors, $hasVariance] = build_handover_receipt_updates($lines, input('line_received'));
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
        redirect('/handovers/' . $handover['id']);
    }

    if ($receiptErrors !== []) {
        $message = implode(' ', array_unique($receiptErrors));

        if (request_wants_json()) {
            json_response([
                'ok' => false,
                'message' => $message,
            ], 422);
        }

        flash_errors($receiptErrors);
        redirect('/handovers/' . $handover['id']);
    }

    $pdo = Database::connection();
    $storedProof = null;

    try {
        if ($proofFile !== null) {
            $storedProof = store_workflow_proof_document($proofFile, 'handover', (string) $handover['handover_number'], 'receipt_report');
        }
    } catch (Throwable $exception) {
        if (request_wants_json()) {
            json_response([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        flash('danger', $exception->getMessage());
        redirect('/handovers/' . $handover['id']);
    }

    $pdo->beginTransaction();

    try {
        foreach ($receiptUpdates as $update) {
            Database::execute(
                'UPDATE handover_lines
                 SET quantity_received = :quantity_received,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'quantity_received' => (float) $update['received'],
                    'id' => (int) $update['line_id'],
                ]
            );
        }

        if ($isStorageTransfer) {
            if (!$hasVariance) {
                finalize_handover_storage_transfer_inventory($handover, $receiptUpdates, (int) $user['id']);

                Database::execute(
                    'UPDATE handovers
                     SET status = "closed",
                         receipt_notes = :receipt_notes,
                         receipt_reported_at = NOW(),
                         submitted_at = NOW(),
                         submitted_by = :submitted_by,
                         approved_at = NOW(),
                         approved_by = :approved_by,
                         completed_at = NOW(),
                         completed_by = :completed_by,
                         updated_by = :updated_by,
                         updated_at = NOW()
                     WHERE id = :id',
                    [
                        'receipt_notes' => $receiptNotes !== '' ? $receiptNotes : null,
                        'submitted_by' => (int) $user['id'],
                        'approved_by' => (int) $user['id'],
                        'completed_by' => (int) $user['id'],
                        'updated_by' => (int) $user['id'],
                        'id' => (int) $handover['id'],
                    ]
                );
            } else {
                Database::execute(
                    'UPDATE handovers
                     SET status = "receipt_review",
                         receipt_notes = :receipt_notes,
                         receipt_reported_at = NOW(),
                         updated_by = :updated_by,
                         updated_at = NOW()
                     WHERE id = :id',
                    [
                        'receipt_notes' => $receiptNotes !== '' ? $receiptNotes : null,
                        'updated_by' => (int) $user['id'],
                        'id' => (int) $handover['id'],
                    ]
                );
            }
        } else {
            Database::execute(
                'UPDATE handovers
                 SET status = :status,
                     receipt_notes = :receipt_notes,
                     receipt_reported_at = NOW(),
                     updated_by = :updated_by,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'status' => $hasVariance ? 'receipt_review' : 'delivered',
                    'receipt_notes' => $receiptNotes !== '' ? $receiptNotes : null,
                    'updated_by' => (int) $user['id'],
                    'id' => (int) $handover['id'],
                ]
            );
        }

        if ($storedProof !== null) {
            create_workflow_document_record(
                'handover',
                (int) $handover['id'],
                (string) $handover['handover_number'],
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
        redirect('/handovers/' . $handover['id']);
    }

    try {
        $updatedHandover = find_handover_or_abort((int) $handover['id']);
        ensure_workflow_signoff_pdf('handover', $updatedHandover, handover_lines((int) $handover['id']));
    } catch (Throwable $exception) {
        // Attachment regeneration should not block receipt reporting.
    }

    if (!empty($handover['source_owner_user_id'])) {
        create_notification(
            (int) $handover['source_owner_user_id'],
            $hasVariance ? 'handover_receipt_review' : 'handover_received',
            $hasVariance
                ? 'Handover ' . $handover['handover_number'] . ' needs receipt review'
                : 'Handover ' . $handover['handover_number'] . ' was received',
            $isStorageTransfer
                ? ($hasVariance
                    ? ($user['name'] ?? 'Destination owner') . ' reported a transfer shortage and is waiting for source owner confirmation.'
                    : ($user['name'] ?? 'Destination owner') . ' confirmed the transfer receipt and stock moved to the destination storage.')
                : ($hasVariance
                    ? ($user['name'] ?? 'Recipient') . ' reported the actual received quantity and is waiting for your confirmation.'
                    : ($user['name'] ?? 'Recipient') . ' confirmed the delivered quantity and can now track usage.'),
            url('/handovers/' . $handover['id']),
            'handover',
            (int) $handover['id'],
            (int) ($user['id'] ?? 0)
        );
    }

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => $isStorageTransfer
                ? ($hasVariance
                    ? 'Transfer receipt saved. Waiting for the source storage owner to confirm the shortage.'
                    : 'Transfer received and closed.')
                : ($hasVariance
                    ? 'Receipt report saved. Waiting for the storage owner to confirm the shortage.'
                    : 'Receipt confirmed. You can now track usage and returns.'),
            'redirect_url' => url('/handovers/' . $handover['id']),
        ]);
    }

    flash('success', $isStorageTransfer
        ? ($hasVariance
            ? 'Transfer receipt saved. Waiting for the source storage owner to confirm the shortage.'
            : 'Transfer received and closed.')
        : ($hasVariance
            ? 'Receipt report saved. Waiting for the storage owner to confirm the shortage.'
            : 'Receipt confirmed. You can now track usage and returns.'));
    redirect('/handovers/' . $handover['id']);
}

function handle_handovers_confirm_receipt_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requireLogin();
    verify_csrf();

    $handover = find_handover_or_abort((int) $params['id']);
    $user = Auth::user();
    $isStorageTransfer = handover_is_storage_transfer($handover);
    if (!$isStorageTransfer && !Auth::hasPermission('handovers.approve')) {
        flash('danger', 'You do not have access to that area.');
        redirect('/handovers/' . $handover['id']);
    }

    $receiptConfirmBlockReason = handover_receipt_confirm_block_reason($handover, $user);

    if ($receiptConfirmBlockReason !== null) {
        flash('danger', $receiptConfirmBlockReason);
        redirect('/handovers/' . $handover['id']);
    }

    $lines = handover_lines((int) $handover['id']);
    $bufferStorageId = system_storage_id('handover_buffer');
    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        if ($isStorageTransfer) {
            $receiptUpdates = array_map(static fn (array $line): array => [
                'line_id' => (int) $line['id'],
                'item_id' => (int) $line['item_id'],
                'handed' => round((float) $line['quantity_handed'], 2),
                'received' => round((float) $line['quantity_received'], 2),
                'shortage' => max(0, round((float) $line['quantity_handed'] - (float) $line['quantity_received'], 2)),
            ], $lines);

            finalize_handover_storage_transfer_inventory($handover, $receiptUpdates, (int) $user['id']);

            Database::execute(
                'UPDATE handovers
                 SET status = "closed",
                     submitted_at = COALESCE(submitted_at, NOW()),
                     submitted_by = COALESCE(submitted_by, :submitted_by),
                     approved_at = NOW(),
                     approved_by = :approved_by,
                     completed_at = NOW(),
                     completed_by = :completed_by,
                     updated_by = :updated_by,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'submitted_by' => (int) $user['id'],
                    'approved_by' => (int) $user['id'],
                    'completed_by' => (int) $user['id'],
                    'updated_by' => (int) $user['id'],
                    'id' => (int) $handover['id'],
                ]
            );
        } else {
            foreach ($lines as $line) {
                $received = round((float) $line['quantity_received'], 2);
                $planned = round((float) $line['quantity_handed'], 2);
                $shortage = round($planned - $received, 2);

                if ($shortage <= 0) {
                    continue;
                }

                $item = find_item_or_abort((int) $line['item_id']);

                apply_inventory_movement(
                    $item,
                    'transfer',
                    $shortage,
                    $bufferStorageId,
                    (int) $handover['source_storage_id'],
                    date('Y-m-d H:i:s'),
                    (string) $handover['handover_number'],
                    'Unreceived handover quantity returned to source storage.',
                    (int) $user['id'],
                    'handover',
                    (int) $handover['id']
                );
            }

            Database::execute(
                'UPDATE handovers
                 SET status = "delivered",
                     updated_by = :updated_by,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'updated_by' => (int) $user['id'],
                    'id' => (int) $handover['id'],
                ]
            );
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/handovers/' . $handover['id']);
    }

    try {
        $updatedHandover = find_handover_or_abort((int) $handover['id']);
        ensure_workflow_signoff_pdf('handover', $updatedHandover, handover_lines((int) $handover['id']));
    } catch (Throwable $exception) {
        // Attachment regeneration should not block receipt confirmation.
    }

    if (!empty($handover['recipient_user_id'])) {
        create_notification(
            (int) $handover['recipient_user_id'],
            'handover_delivery_confirmed',
            $isStorageTransfer
                ? 'Transfer ' . $handover['handover_number'] . ' approved'
                : 'Handover ' . $handover['handover_number'] . ' is ready',
            $isStorageTransfer
                ? 'The source owner approved the transfer shortage and the received stock moved to the destination storage.'
                : 'The reported received quantity was confirmed. You can now track usage and returns.',
            url('/handovers/' . $handover['id']),
            'handover',
            (int) $handover['id'],
            (int) ($user['id'] ?? 0)
        );
    }

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => $isStorageTransfer
                ? 'Transfer shortage approved and closed.'
                : 'Receipt discrepancy approved. The handover is now active.',
            'redirect_url' => url('/handovers/' . $handover['id']),
        ]);
    }

    flash('success', $isStorageTransfer
        ? 'Transfer shortage approved and closed.'
        : 'Receipt discrepancy approved. The handover is now active.');
    redirect('/handovers/' . $handover['id']);
}

function handle_handovers_close_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('handovers.close');
    verify_csrf();

    $handover = find_handover_or_abort((int) $params['id']);
    $user = Auth::user();
    if (handover_is_storage_transfer($handover)) {
        flash('danger', 'Storage transfers close through receipt confirmation, not usage closeout.');
        redirect('/handovers/' . $handover['id']);
    }

    $isSourceOwner = Auth::isOwner()
        || (int) ($handover['source_owner_user_id'] ?? 0) === (int) ($user['id'] ?? 0)
        || (int) ($handover['created_by'] ?? 0) === (int) ($user['id'] ?? 0);
    $isRecipient = (int) ($handover['recipient_user_id'] ?? 0) === (int) ($user['id'] ?? 0);

    if (($handover['status'] ?? '') !== 'delivered') {
        flash('danger', 'Only delivered handovers can be submitted.');
        redirect('/handovers/' . $handover['id']);
    }

    $returnedInput = input('line_returned', []);
    $usedInput = input('line_used', []);
    $usageInput = [
        'quantity' => input('line_usage_quantity', []),
        'reason' => input('line_usage_reason', []),
        'other' => input('line_usage_other', []),
        'notes' => input('line_usage_notes', []),
    ];
    $closedNotes = trim((string) input('closed_notes'));
    $lines = handover_lines((int) $handover['id']);
    [$lineUpdates, $errors] = build_handover_close_updates($lines, $returnedInput, $usageInput, $usedInput);
    $proofFile = uploaded_file('proof_image');
    $proofError = validate_workflow_proof_upload($proofFile);

    if (!$isRecipient && !$isSourceOwner) {
        $errors[] = 'Only the recipient or storage owner can submit this handover.';
    }

    if ($proofError !== null) {
        $errors[] = $proofError;
    }

    if ($errors !== []) {
        if (request_wants_json()) {
            json_response([
                'ok' => false,
                'message' => $errors[0],
            ], 422);
        }

        flash_errors($errors);
        redirect('/handovers/' . $handover['id']);
    }

    $pdo = Database::connection();
    $storedProof = null;

    try {
        if ($proofFile !== null) {
            $storedProof = store_workflow_proof_document($proofFile, 'handover', (string) $handover['handover_number'], 'closeout_report');
        }
    } catch (Throwable $exception) {
        if (request_wants_json()) {
            json_response([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        flash('danger', $exception->getMessage());
        redirect('/handovers/' . $handover['id']);
    }

    $pdo->beginTransaction();

    try {
        foreach ($lineUpdates as $update) {
            Database::execute(
                'UPDATE handover_lines
                 SET quantity_used = :quantity_used,
                     quantity_returned = :quantity_returned,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'quantity_used' => $update['used'],
                    'quantity_returned' => $update['returned'],
                    'id' => $update['line_id'],
                ]
            );
        }

        save_handover_usage_breakdowns((int) $handover['id'], $lineUpdates, (int) $user['id']);

        if ($isSourceOwner && empty($handover['recipient_user_id'])) {
            finalize_handover_inventory($handover, $lineUpdates, (int) $user['id']);

            Database::execute(
                'UPDATE handovers
                 SET status = "closed",
                     closed_notes = :closed_notes,
                     submitted_at = COALESCE(submitted_at, NOW()),
                     submitted_by = COALESCE(submitted_by, :submitted_by),
                     approved_at = NOW(),
                     approved_by = :approved_by,
                     completed_at = NOW(),
                     completed_by = :completed_by,
                     updated_by = :updated_by,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'closed_notes' => $closedNotes !== '' ? $closedNotes : null,
                    'submitted_by' => (int) $user['id'],
                    'approved_by' => (int) $user['id'],
                    'completed_by' => (int) $user['id'],
                    'updated_by' => (int) $user['id'],
                    'id' => (int) $handover['id'],
                ]
            );
        } else {
            Database::execute(
                'UPDATE handovers
                 SET status = "pending_approval",
                     closed_notes = :closed_notes,
                     submitted_at = NOW(),
                     submitted_by = :submitted_by,
                     updated_by = :updated_by,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'closed_notes' => $closedNotes !== '' ? $closedNotes : null,
                    'submitted_by' => (int) $user['id'],
                    'updated_by' => (int) $user['id'],
                    'id' => (int) $handover['id'],
                ]
            );
        }

        if ($storedProof !== null) {
            create_workflow_document_record(
                'handover',
                (int) $handover['id'],
                (string) $handover['handover_number'],
                'proof_image',
                'closeout_report',
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
        redirect('/handovers/' . $handover['id']);
    }

    if ($isSourceOwner && empty($handover['recipient_user_id'])) {
        if (request_wants_json()) {
            json_response([
                'ok' => true,
                'message' => 'Handover closed.',
                'redirect_url' => url('/handovers/' . $handover['id']),
            ]);
        }

        flash('success', 'Handover closed.');
        redirect('/handovers/' . $handover['id']);
    }

    if (!empty($handover['source_owner_user_id'])) {
        create_notification(
            (int) $handover['source_owner_user_id'],
            'handover_waiting_approval',
            'Handover ' . $handover['handover_number'] . ' is waiting for approval',
            ($user['name'] ?? 'Someone') . ' submitted used quantities and the remaining stock is waiting for your approval.',
            url('/handovers/' . $handover['id']),
            'handover',
            (int) $handover['id'],
            (int) ($user['id'] ?? 0)
        );
    }

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => 'Handover submitted for approval.',
            'redirect_url' => url('/handovers/' . $handover['id']),
        ]);
    }

    flash('success', 'Handover submitted for approval.');
    redirect('/handovers/' . $handover['id']);
}

function handle_handovers_approve_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('handovers.approve');
    verify_csrf();

    $handover = find_handover_or_abort((int) $params['id']);
    $user = Auth::user();
    if (handover_is_storage_transfer($handover)) {
        flash('danger', 'Storage transfers are approved through receipt confirmation, not usage closeout.');
        redirect('/handovers/' . $handover['id']);
    }

    $isSourceOwner = Auth::isOwner()
        || (int) ($handover['source_owner_user_id'] ?? 0) === (int) ($user['id'] ?? 0)
        || (int) ($handover['created_by'] ?? 0) === (int) ($user['id'] ?? 0);

    if (!$isSourceOwner) {
        flash('danger', 'Only the storage owner can approve this handover.');
        redirect('/handovers/' . $handover['id']);
    }

    if (($handover['status'] ?? '') !== 'pending_approval') {
        flash('danger', 'Only handovers waiting for approval can be approved.');
        redirect('/handovers/' . $handover['id']);
    }

    $closedNotes = trim((string) input('closed_notes', (string) ($handover['closed_notes'] ?? '')));
    $lines = handover_lines((int) $handover['id']);
    $usageInput = [
        'quantity' => input('line_usage_quantity', []),
        'reason' => input('line_usage_reason', []),
        'other' => input('line_usage_other', []),
        'notes' => input('line_usage_notes', []),
    ];
    [$lineUpdates, $errors] = build_handover_approval_updates($lines, input('line_returned', []), $usageInput);

    if ($errors !== []) {
        if (request_wants_json()) {
            json_response([
                'ok' => false,
                'message' => $errors[0],
            ], 422);
        }

        flash_errors($errors);
        redirect('/handovers/' . $handover['id']);
    }

    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        foreach ($lineUpdates as $update) {
            Database::execute(
                'UPDATE handover_lines
                 SET quantity_used = :quantity_used,
                     quantity_returned = :quantity_returned,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'quantity_used' => $update['used'],
                    'quantity_returned' => $update['returned'],
                    'id' => $update['line_id'],
                ]
            );
        }

        save_handover_usage_breakdowns((int) $handover['id'], $lineUpdates, (int) $user['id']);
        finalize_handover_inventory($handover, $lineUpdates, (int) $user['id']);

        Database::execute(
            'UPDATE handovers
             SET status = "closed",
                 closed_notes = :closed_notes,
                 approved_at = NOW(),
                 approved_by = :approved_by,
                 completed_at = NOW(),
                 completed_by = :completed_by,
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'closed_notes' => $closedNotes !== '' ? $closedNotes : null,
                'approved_by' => (int) $user['id'],
                'completed_by' => (int) $user['id'],
                'updated_by' => (int) $user['id'],
                'id' => (int) $handover['id'],
            ]
        );

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/handovers/' . $handover['id']);
    }

    try {
        $updatedHandover = find_handover_or_abort((int) $handover['id']);
        ensure_workflow_signoff_pdf('handover', $updatedHandover, handover_lines((int) $handover['id']));
    } catch (Throwable $exception) {
        // Attachment regeneration should not block an already approved closeout.
    }

    if (!empty($handover['recipient_user_id'])) {
        create_notification(
            (int) $handover['recipient_user_id'],
            'handover_closed',
            'Handover ' . $handover['handover_number'] . ' approved',
            'The used quantity was accepted and the remaining stock was returned to the storage.',
            url('/handovers/' . $handover['id']),
            'handover',
            (int) $handover['id'],
            (int) ($user['id'] ?? 0)
        );
    }

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => 'Handover approved and closed.',
            'redirect_url' => url('/handovers/' . $handover['id']),
        ]);
    }

    flash('success', 'Handover approved and closed.');
    redirect('/handovers/' . $handover['id']);
}

function handle_export_handovers(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('handovers.export');

    $filters = handover_filters();
    if (trim((string) query('status', '')) === '') {
        $filters['status'] = 'all';
    }
    $handovers = handover_summary_rows($filters);
    $rows = [];

    foreach ($handovers as $handover) {
        $isStorageTransfer = handover_is_storage_transfer($handover);

        foreach (handover_lines((int) $handover['id']) as $line) {
            if ($isStorageTransfer) {
                $remainingQuantity = max(0, round(
                    (float) ($line['quantity_handed'] ?? 0)
                    - (float) ($line['quantity_received'] ?? 0)
                    - (float) ($line['quantity_returned'] ?? 0),
                    2
                ));
            } else {
                $baseQuantity = in_array((string) ($handover['status'] ?? ''), ['requested', 'awaiting_receipt'], true)
                    ? round((float) ($line['quantity_handed'] ?? 0), 2)
                    : round((float) ($line['quantity_received'] ?? 0), 2);
                $remainingQuantity = max(0, round($baseQuantity - (float) ($line['quantity_used'] ?? 0) - (float) ($line['quantity_returned'] ?? 0), 2));
            }

            $rows[] = [
                $handover['handover_number'],
                (string) ($handover['handover_mode'] ?? 'direct') === 'request' ? 'Request' : 'Direct',
                handover_target_type_label($handover),
                handover_status_label((string) $handover['status']),
                $handover['source_storage_name'],
                $handover['destination_storage_name'] ?? '',
                $handover['recipient_name'],
                $handover['requested_at'] ?: '',
                $handover['issued_at'],
                $handover['request_approved_at'] ?: '',
                $handover['request_rejected_at'] ?: '',
                $handover['receipt_reported_at'] ?: '',
                $handover['completed_at'] ?: '',
                $line['item_name'],
                $line['item_sku'],
                $line['unit'],
                format_quantity($line['quantity_handed']),
                format_quantity($line['quantity_received']),
                format_quantity($line['quantity_used']),
                format_quantity($line['quantity_returned']),
                format_quantity($remainingQuantity),
                (string) ($line['expected_usage_reason_summary'] ?? ''),
                (string) ($line['usage_reason_summary'] ?? ''),
                (string) ($line['usage_variance_summary'] ?? ''),
                $handover['notes'] ?: '',
                $handover['request_decision_notes'] ?: '',
                $handover['receipt_notes'] ?: '',
                $handover['closed_notes'] ?: '',
            ];
        }
    }

    export_csv('handovers-export-' . date('Ymd-His') . '.csv', [
        'Handover Number',
        'Mode',
        'Target Type',
        'Status',
        'Source Storage',
        'Destination Storage',
        'Recipient',
        'Requested At',
        'Issued At',
        'Request Approved At',
        'Request Rejected At',
        'Receipt Reported At',
        'Completed At',
        'Item',
        'SKU',
        'Unit',
        'Planned Quantity',
        'Received Quantity',
        'Used Quantity',
        'Returned Quantity',
        'Remaining Quantity',
        'Expected Usage Reasons',
        'Usage Reasons',
        'Usage Variance',
        'Notes',
        'Request Decision Notes',
        'Receipt Notes',
        'Closed Notes',
    ], $rows);
}
