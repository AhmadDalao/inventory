<?php
declare(strict_types=1);

// Domain module: handover workflow handlers. Support helpers live beside this file.

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
