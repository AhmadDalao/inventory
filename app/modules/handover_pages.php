<?php
declare(strict_types=1);

// Domain module: handover page handlers. Function names are preserved for route compatibility.

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
