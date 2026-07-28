<?php
declare(strict_types=1);

// Domain module: handover create submit handler. Function names are preserved for route compatibility.

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
    $legacyRecipientType = in_array((string) input('recipient_type', 'staff'), ['staff', 'storage'], true)
        ? (string) input('recipient_type', 'staff')
        : 'staff';
    $requestedPurpose = (string) input('handover_purpose', $legacyRecipientType === 'storage' ? 'storage_transfer' : 'temporary_use');
    $handoverPurpose = $isStaffRequest || !in_array($requestedPurpose, ['temporary_use', 'staff_custody', 'storage_transfer'], true)
        ? 'temporary_use'
        : $requestedPurpose;
    $isStorageTransfer = $handoverPurpose === 'storage_transfer';
    $isStaffCustody = $handoverPurpose === 'staff_custody';
    $recipientType = $isStorageTransfer ? 'storage' : 'staff';
    $usageReportingMode = $handoverPurpose === 'temporary_use' ? 'operational_summary' : 'legacy_per_item';
    $issueCondition = in_array((string) input('issue_condition', 'good'), array_keys(handover_issue_condition_options()), true)
        ? (string) input('issue_condition', 'good')
        : 'good';
    $payload = [
        'source_storage_id' => normalize_entity_id(input('source_storage_id')),
        'destination_storage_id' => $isStorageTransfer ? normalize_entity_id(input('destination_storage_id')) : null,
        'recipient_type' => $recipientType,
        'handover_purpose' => $handoverPurpose,
        'issue_condition' => $issueCondition,
        'custody_review_date' => $isStaffCustody ? normalize_workflow_date(trim((string) input('custody_review_date'))) : '',
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
        'handover_purpose' => $payload['handover_purpose'],
        'issue_condition' => $payload['issue_condition'],
        'custody_review_date' => $payload['custody_review_date'],
        'request_owner_user_id' => (string) ($payload['request_owner_user_id'] ?? ''),
        'recipient_name' => $payload['recipient_name'],
        'recipient_user_id' => (string) ($payload['recipient_user_id'] ?? ''),
        'scheduled_for_date' => $payload['scheduled_for_date'],
        'notes' => $payload['notes'],
        'line_items' => array_map(static fn (array $line): array => [
            'item_id' => (string) $line['item_id'],
            'quantity' => format_quantity($line['quantity']),
        ], $lines),
    ]);

    $errors = $lineErrors;

    if (!$payload['source_storage_id'] || !storage_exists_for_assignment($payload['source_storage_id'])) {
        $errors[] = 'Pick a valid source storage.';
    } elseif (!$isStaffRequest && !Auth::isOwner() && !storage_is_owned_by_user((int) $payload['source_storage_id'], (int) ($user['id'] ?? 0))) {
        $errors[] = 'You can only create handovers from storages you own.';
    }

    if (!$isStorageTransfer && $payload['recipient_name'] === '' && !$payload['recipient_user_id']) {
        $errors[] = 'Enter a recipient name or choose a user.';
    }

    if ($isStaffCustody && !$payload['recipient_user_id']) {
        $errors[] = 'Long-term custody must be assigned to a staff account.';
    }

    if ($isStaffCustody && $payload['custody_review_date'] === '') {
        $errors[] = 'Set the expected custody review or return date.';
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
                handover_purpose,
                issue_condition,
                custody_review_date,
                usage_reporting_mode,
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
                :handover_purpose,
                :issue_condition,
                :custody_review_date,
                :usage_reporting_mode,
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
                'handover_purpose' => $payload['handover_purpose'],
                'issue_condition' => $payload['issue_condition'],
                'custody_review_date' => $payload['custody_review_date'] !== '' ? $payload['custody_review_date'] : null,
                'usage_reporting_mode' => $usageReportingMode,
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

        }

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
            $isStaffCustody ? 'handover_custody_created' : 'handover_created',
            ($isStaffCustody ? 'New long-term custody ' : 'New handover ') . $handoverNumber,
            $isStaffCustody
                ? 'Confirm what you received. These items stay assigned to you until approved return events are completed.'
                : 'Confirm the actual received quantity before you start using these items.',
            url('/handovers/' . $handoverId),
            'handover',
            $handoverId,
            (int) ($user['id'] ?? 0)
        );
    }

    consume_old_input();
    flash(
        'success',
        $isStaffRequest
            ? 'Handover request created.'
            : ($isStorageTransfer
                ? 'Storage transfer handover created.'
                : ($isStaffCustody ? 'Long-term staff custody created.' : 'Handover created.'))
    );
    redirect('/handovers/' . $handoverId);
}
