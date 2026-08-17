<?php
declare(strict_types=1);

// Domain module: request create and submit handlers. Function names are preserved for route compatibility.

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
    $sourceOwnerIds = $payload['source_storage_id'] ? storage_owner_user_ids((int) $payload['source_storage_id']) : [];
    $managerUserId = manager_user_id_for((int) ($user['id'] ?? 0));

    if ($sourceOwnerIds === []) {
        $errors[] = 'The source storage needs an active owner admin before requests can be created.';
    }

    if (in_array((int) ($user['id'] ?? 0), $sourceOwnerIds, true)) {
        $errors[] = 'You cannot create a request from a storage you own. Use a direct transfer, handover, or stock update instead.';
    }

    if ($isStaffRequest && $payload['source_storage_id'] && !user_can_view_storage((int) ($user['id'] ?? 0), (int) $payload['source_storage_id'])) {
        $errors[] = 'You can only request items from a storage assigned to you.';
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
                manager_user_id,
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
                :manager_user_id,
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
                'approver_user_id' => storage_owner_user_id((int) $payload['source_storage_id']),
                'manager_user_id' => $managerUserId,
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
        $designatedApproverId = storage_owner_user_id((int) $payload['source_storage_id']);

        if ($designatedApproverId !== null && $designatedApproverId !== (int) ($user['id'] ?? 0)) {
            create_notification(
                $designatedApproverId,
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

        notify_workflow_observers(
            (int) ($user['id'] ?? 0),
            array_values(array_filter([
                (int) $payload['source_storage_id'],
                (int) ($payload['destination_storage_id'] ?? 0),
            ])),
            'request_created',
            'New item request ' . $requestNumber,
            ($user['name'] ?? 'Someone') . ($requestMode === 'issue'
                ? ' asked for items to use from ' . ($sourceOwner['storage_name'] ?? 'your storage') . '.'
                : ' requested a storage transfer from ' . ($sourceOwner['storage_name'] ?? 'your storage') . '.'),
            url('/requests/' . $requestId),
            'request',
            $requestId,
            array_values(array_filter([$designatedApproverId])),
            [(int) ($user['id'] ?? 0)]
        );
    }

    consume_old_input();
    flash('success', $requestStatus === 'draft' ? 'Request draft saved.' : 'Request submitted.');
    redirect('/requests/' . $requestId);
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

    $notificationTitle = 'New item request ' . $request['request_number'];
    $notificationBody = ($user['name'] ?? 'Someone') . ((string) ($request['request_mode'] ?? 'transfer') === 'issue'
        ? ' asked for items to use from ' . ($request['source_storage_name'] ?? 'your storage') . '.'
        : ' requested a storage transfer from ' . ($request['source_storage_name'] ?? 'your storage') . '.');
    $designatedApproverId = normalize_entity_id($request['approver_user_id'] ?? null);

    if ($designatedApproverId !== null && $designatedApproverId !== (int) ($user['id'] ?? 0)) {
        create_notification(
            $designatedApproverId,
            'request_created',
            $notificationTitle,
            $notificationBody,
            url('/requests/' . $request['id']),
            'request',
            (int) $request['id'],
            (int) ($user['id'] ?? 0)
        );
    }

    notify_workflow_observers(
        (int) ($user['id'] ?? 0),
        array_values(array_filter([
            (int) ($request['source_storage_id'] ?? 0),
            (int) ($request['destination_storage_id'] ?? 0),
        ])),
        'request_created',
        $notificationTitle,
        $notificationBody,
        url('/requests/' . $request['id']),
        'request',
        (int) $request['id'],
        array_values(array_filter([$designatedApproverId])),
        [(int) ($request['requester_user_id'] ?? 0)]
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
