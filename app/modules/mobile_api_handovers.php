<?php
declare(strict_types=1);

// Mobile adapter for the existing handover domain. Stock math stays in the
// shared handover/inventory modules; this file only validates API scope and
// orchestrates those existing operations.

function mobile_api_handover_fetch(int $handoverId): array
{
    $handover = Database::fetch(
        'SELECT h.*,
                source_storage.name AS source_storage_name,
                source_storage.storage_type AS source_storage_type,
                source_storage.owner_user_id AS source_owner_user_id,
                destination_storage.name AS destination_storage_name,
                destination_storage.storage_type AS destination_storage_type,
                destination_storage.owner_user_id AS destination_owner_user_id,
                creator.name AS creator_name,
                recipient.name AS recipient_user_name,
                recipient.email AS recipient_user_email,
                source_owner.name AS source_owner_name,
                destination_owner.name AS destination_owner_name,
                approver.name AS approver_name
         FROM handovers h
         INNER JOIN storages source_storage ON source_storage.id = h.source_storage_id
         LEFT JOIN storages destination_storage ON destination_storage.id = h.destination_storage_id
         LEFT JOIN users creator ON creator.id = h.created_by
         LEFT JOIN users recipient ON recipient.id = h.recipient_user_id
         LEFT JOIN users source_owner ON source_owner.id = source_storage.owner_user_id
         LEFT JOIN users destination_owner ON destination_owner.id = destination_storage.owner_user_id
         LEFT JOIN users approver ON approver.id = h.approver_user_id
         WHERE h.id = :id
         LIMIT 1',
        ['id' => $handoverId]
    );

    if (!$handover) {
        throw new MobileApiException('handover_not_found', 'Handover not found.', 404);
    }

    return $handover;
}

function mobile_api_handover_is_owner(array $session): bool
{
    return (string) ($session['role'] ?? '') === 'owner';
}

function mobile_api_handover_is_source_issuer(array $session, array $handover): bool
{
    $userId = (int) ($session['user_id'] ?? 0);
    return mobile_api_handover_is_owner($session)
        || $userId === (int) ($handover['source_owner_user_id'] ?? 0)
        || $userId === (int) ($handover['approver_user_id'] ?? 0)
        || $userId === (int) ($handover['request_approved_by'] ?? 0)
        || ($userId === (int) ($handover['created_by'] ?? 0) && (string) ($handover['handover_mode'] ?? '') === 'direct');
}

function mobile_api_handover_can_view(array $session, array $handover): bool
{
    $userId = (int) ($session['user_id'] ?? 0);
    return mobile_api_handover_is_owner($session)
        || $userId === (int) ($handover['recipient_user_id'] ?? 0)
        || $userId === (int) ($handover['created_by'] ?? 0)
        || $userId === (int) ($handover['source_owner_user_id'] ?? 0)
        || $userId === (int) ($handover['destination_owner_user_id'] ?? 0)
        || $userId === (int) ($handover['approver_user_id'] ?? 0);
}

function mobile_api_require_handover_view(array $session, array $handover): void
{
    if (!mobile_api_handover_can_view($session, $handover)) {
        throw new MobileApiException('handover_forbidden', 'You cannot access this handover.', 403);
    }
}

function mobile_api_handover_can_receive(array $session, array $handover): bool
{
    $userId = (int) ($session['user_id'] ?? 0);
    if ((string) ($handover['status'] ?? '') !== 'awaiting_receipt') {
        return false;
    }

    if (handover_is_storage_transfer($handover)) {
        return mobile_api_handover_is_owner($session)
            || $userId === (int) ($handover['destination_owner_user_id'] ?? 0);
    }

    return mobile_api_handover_is_owner($session)
        || $userId === (int) ($handover['recipient_user_id'] ?? 0);
}

function mobile_api_handover_line_payload(array $line): array
{
    return [
        'id' => (int) $line['id'],
        'item_id' => (int) $line['item_id'],
        'name' => (string) $line['item_name'],
        'sku' => (string) $line['item_sku'],
        'barcode' => trim((string) ($line['item_barcode'] ?? '')) ?: null,
        'unit' => (string) ($line['unit'] ?? 'pcs'),
        'image_url' => mobile_api_absolute_url(item_image_url($line['image_path'] ?? null)),
        'quantity_issued' => (float) $line['quantity_handed'],
        'quantity_received' => (float) $line['quantity_received'],
        'quantity_used' => (float) $line['quantity_used'],
        'quantity_returned' => (float) $line['quantity_returned'],
        'quantity_held' => handover_line_held_quantity($line),
    ];
}

function mobile_api_handover_payload(array $handover, bool $includeLines = true): array
{
    $purpose = handover_purpose_value($handover);
    $sourceName = (string) ($handover['source_storage_name'] ?? 'Storage');
    $destinationName = (string) ($handover['destination_storage_name'] ?? 'destination storage');
    $title = match ($purpose) {
        'storage_transfer' => 'Transfer to ' . $destinationName,
        'staff_custody' => 'Custody from ' . $sourceName,
        default => 'Handover from ' . $sourceName,
    };
    $data = [
        'id' => (int) $handover['id'],
        'reference' => (string) $handover['handover_number'],
        'purpose' => $purpose,
        'mode' => (string) ($handover['handover_mode'] ?? 'direct'),
        'status' => (string) $handover['status'],
        'source_storage' => [
            'id' => (int) $handover['source_storage_id'],
            'name' => (string) ($handover['source_storage_name'] ?? ''),
            'owner_user_id' => (int) ($handover['source_owner_user_id'] ?? 0) ?: null,
            'owner_name' => (string) ($handover['source_owner_name'] ?? '') ?: null,
        ],
        'destination_storage' => empty($handover['destination_storage_id']) ? null : [
            'id' => (int) $handover['destination_storage_id'],
            'name' => (string) ($handover['destination_storage_name'] ?? ''),
            'owner_user_id' => (int) ($handover['destination_owner_user_id'] ?? 0) ?: null,
            'owner_name' => (string) ($handover['destination_owner_name'] ?? '') ?: null,
        ],
        'recipient' => [
            'user_id' => (int) ($handover['recipient_user_id'] ?? 0) ?: null,
            'name' => (string) ($handover['recipient_user_name'] ?? $handover['recipient_name'] ?? ''),
        ],
        'issuer' => [
            'user_id' => (int) ($handover['source_owner_user_id'] ?? $handover['created_by'] ?? 0) ?: null,
            'name' => (string) ($handover['source_owner_name'] ?? $handover['creator_name'] ?? ''),
        ],
        'scheduled_for_date' => $handover['scheduled_for_date'] ?: null,
        'custody_review_date' => $handover['custody_review_date'] ?: null,
        'issue_condition' => $handover['issue_condition'] ?: null,
        'notes' => $handover['notes'] ?: null,
        'receipt_notes' => $handover['receipt_notes'] ?: null,
        'close_notes' => $handover['closed_notes'] ?: null,
        'created_at' => $handover['created_at'],
        'issued_at' => $handover['issued_at'],
        'receipt_reported_at' => $handover['receipt_reported_at'],
        'completed_at' => $handover['completed_at'],
        'title' => $title,
        'item_count' => (int) ($handover['item_count'] ?? 0),
        'total_quantity' => (float) ($handover['total_quantity'] ?? 0),
        'requires_action' => in_array((string) $handover['status'], ['awaiting_receipt', 'delivered'], true),
    ];

    if ($includeLines) {
        $lines = handover_lines((int) $handover['id']);
        $data['lines'] = array_map('mobile_api_handover_line_payload', $lines);
        $data['reconciliations'] = array_values(handover_reconciliations_for_handover((int) $handover['id']));
        $data['custody_returns'] = handover_is_staff_custody($handover)
            ? handover_custody_returns((int) $handover['id'])
            : [];
    }

    return $data;
}

function mobile_api_handover_scope_sql(array $session): array
{
    if (mobile_api_handover_is_owner($session)) {
        return ['', []];
    }
    $userId = (int) $session['user_id'];
    return [
        ' AND (h.recipient_user_id = :scope_user OR h.created_by = :scope_creator OR source_storage.owner_user_id = :scope_source_owner OR destination_storage.owner_user_id = :scope_destination_owner OR h.approver_user_id = :scope_approver)',
        [
            'scope_user' => $userId,
            'scope_creator' => $userId,
            'scope_source_owner' => $userId,
            'scope_destination_owner' => $userId,
            'scope_approver' => $userId,
        ],
    ];
}

function mobile_api_handover_list_rows(array $session, bool $mine = false): array
{
    [$scopeSql, $params] = mobile_api_handover_scope_sql($session);
    if ($mine) {
        $scopeSql = ' AND h.recipient_user_id = :mine_user';
        $params = ['mine_user' => (int) $session['user_id']];
    }
    $status = trim((string) query('status', 'open'));
    $statusSql = $status === 'all'
        ? ''
        : ($status === 'open'
            ? ' AND h.status IN ("requested", "awaiting_receipt", "receipt_review", "delivered", "pending_approval")'
            : ' AND h.status = :status');
    if ($status !== 'all' && $status !== 'open') {
        $params['status'] = $status;
    }

    $rows = Database::fetchAll(
        'SELECT h.*,
                (SELECT COUNT(*) FROM handover_lines summary_line WHERE summary_line.handover_id = h.id) AS item_count,
                (SELECT COALESCE(SUM(summary_line.quantity_handed), 0) FROM handover_lines summary_line WHERE summary_line.handover_id = h.id) AS total_quantity,
                source_storage.name AS source_storage_name,
                source_storage.owner_user_id AS source_owner_user_id,
                destination_storage.name AS destination_storage_name,
                destination_storage.owner_user_id AS destination_owner_user_id,
                creator.name AS creator_name,
                recipient.name AS recipient_user_name,
                source_owner.name AS source_owner_name,
                destination_owner.name AS destination_owner_name
         FROM handovers h
         INNER JOIN storages source_storage ON source_storage.id = h.source_storage_id
         LEFT JOIN storages destination_storage ON destination_storage.id = h.destination_storage_id
         LEFT JOIN users creator ON creator.id = h.created_by
         LEFT JOIN users recipient ON recipient.id = h.recipient_user_id
         LEFT JOIN users source_owner ON source_owner.id = source_storage.owner_user_id
         LEFT JOIN users destination_owner ON destination_owner.id = destination_storage.owner_user_id
         WHERE 1 = 1' . $scopeSql . $statusSql . '
         ORDER BY h.updated_at DESC, h.id DESC
         LIMIT 200',
        $params
    );

    return array_map(static fn (array $row): array => mobile_api_handover_payload($row, false), $rows);
}

function handle_mobile_api_handovers(): void
{
    mobile_api_run(function (): void {
        $session = mobile_api_session();
        mobile_api_require_permission($session, 'handovers.view');
        mobile_api_success(mobile_api_handover_list_rows($session));
    });
}

function handle_mobile_api_handovers_mine(): void
{
    mobile_api_run(function (): void {
        $session = mobile_api_session();
        mobile_api_require_permission($session, 'handovers.view');
        mobile_api_success(mobile_api_handover_list_rows($session, true));
    });
}

function handle_mobile_api_handover_show(array $params): void
{
    mobile_api_run(function () use ($params): void {
        $session = mobile_api_session();
        mobile_api_require_permission($session, 'handovers.view');
        $handover = mobile_api_handover_fetch((int) $params['id']);
        mobile_api_require_handover_view($session, $handover);
        mobile_api_success(mobile_api_handover_payload($handover));
    });
}

function mobile_api_handover_parse_lines(array $payload, int $sourceStorageId, bool $enforceBalance): array
{
    $rawLines = is_array($payload['lines'] ?? null) ? $payload['lines'] : [];
    if ($rawLines === [] || count($rawLines) > 100) {
        throw new MobileApiException('validation_failed', 'Add 1 to 100 handover items.', 422, ['lines' => ['Required.']]);
    }
    $lines = [];
    $seen = [];
    foreach ($rawLines as $index => $rawLine) {
        if (!is_array($rawLine)) {
            throw new MobileApiException('validation_failed', 'Invalid handover line at index ' . $index . '.', 422);
        }
        $itemId = (int) ($rawLine['item_id'] ?? 0);
        $quantity = round((float) ($rawLine['quantity'] ?? 0), 2);
        if ($itemId <= 0 || $quantity <= 0) {
            throw new MobileApiException('validation_failed', 'Every handover line needs an item and positive quantity.', 422);
        }
        if (isset($seen[$itemId])) {
            throw new MobileApiException('validation_failed', 'Each item may appear only once in a handover.', 422);
        }
        $seen[$itemId] = true;
        $item = mobile_api_find_item($itemId, [$sourceStorageId]);
        $balance = item_storage_balance_record($itemId, $sourceStorageId);
        if ($balance === null) {
            throw new MobileApiException('item_not_assigned', $item['name'] . ' is not assigned to the source storage.', 422);
        }
        if ($enforceBalance && (float) $balance['quantity'] < $quantity) {
            throw new MobileApiException('balance_changed', $item['name'] . ' no longer has enough source stock.', 409, [], true);
        }
        $lines[] = [
            'item' => $item,
            'item_id' => $itemId,
            'quantity' => $quantity,
            'expected_balance' => $rawLine['expected_balance'] ?? null,
        ];
    }
    return $lines;
}

function handle_mobile_api_handover_create(): void
{
    mobile_api_run(function (): void {
        $session = mobile_api_session();
        $payload = mobile_api_json_input();
        $purpose = trim((string) ($payload['purpose'] ?? 'temporary_use'));
        if (!in_array($purpose, ['temporary_use', 'storage_transfer', 'staff_custody'], true)) {
            throw new MobileApiException('validation_failed', 'Choose a valid handover purpose.', 422, ['purpose' => ['Invalid.']]);
        }
        mobile_api_require_capability($session, match ($purpose) {
            'storage_transfer' => 'transfer',
            'staff_custody' => 'custody',
            default => 'handover',
        });
        $canCreate = Auth::userHasPermission((int) $session['user_id'], 'handovers.create');
        $canRequest = Auth::userHasPermission((int) $session['user_id'], 'handovers.request');
        if (!$canCreate && !$canRequest) {
            throw new MobileApiException('forbidden', 'You cannot create or request handovers.', 403);
        }
        if (!$canCreate && $purpose !== 'temporary_use') {
            throw new MobileApiException('forbidden', 'Staff may only request temporary-use handovers.', 403);
        }

        $sourceStorageId = (int) ($payload['source_storage_id'] ?? 0);
        mobile_api_require_storage($session, $sourceStorageId);
        $sourceStorage = Database::fetch('SELECT * FROM storages WHERE id = :id AND is_active = 1 AND is_system = 0 LIMIT 1', ['id' => $sourceStorageId]);
        if (!$sourceStorage) {
            throw new MobileApiException('storage_not_found', 'Source storage not found.', 404);
        }
        if ($canCreate && !mobile_api_handover_is_owner($session) && (int) ($sourceStorage['owner_user_id'] ?? 0) !== (int) $session['user_id']) {
            throw new MobileApiException('storage_forbidden', 'You may issue stock only from a storage you own.', 403);
        }

        $destinationStorageId = $purpose === 'storage_transfer' ? (int) ($payload['destination_storage_id'] ?? 0) : 0;
        $recipientUserId = $purpose === 'storage_transfer' ? 0 : (int) ($payload['recipient_user_id'] ?? 0);
        $recipientName = '';
        $destinationStorage = null;
        if ($purpose === 'storage_transfer') {
            mobile_api_require_capability($session, 'transfer');
            if ($destinationStorageId <= 0 || $destinationStorageId === $sourceStorageId) {
                throw new MobileApiException('validation_failed', 'Choose a different destination storage.', 422, ['destination_storage_id' => ['Required.']]);
            }
            $destinationStorage = Database::fetch('SELECT * FROM storages WHERE id = :id AND is_active = 1 AND is_system = 0 LIMIT 1', ['id' => $destinationStorageId]);
            if (!$destinationStorage || (int) ($destinationStorage['owner_user_id'] ?? 0) <= 0) {
                throw new MobileApiException('validation_failed', 'Destination storage needs an active owner.', 422);
            }
            $recipientUserId = (int) $destinationStorage['owner_user_id'];
        }
        $recipient = Database::fetch('SELECT id, name, role, is_active FROM users WHERE id = :id LIMIT 1', ['id' => $recipientUserId]);
        if (!$recipient || (int) $recipient['is_active'] !== 1) {
            throw new MobileApiException('validation_failed', 'Choose an active recipient.', 422, ['recipient_user_id' => ['Required.']]);
        }
        if ($purpose !== 'storage_transfer' && (string) $recipient['role'] !== 'staff') {
            throw new MobileApiException('validation_failed', 'Staff handovers require a staff account.', 422);
        }
        $recipientName = (string) $recipient['name'];

        $isRequest = !$canCreate;
        $lines = mobile_api_handover_parse_lines($payload, $sourceStorageId, !$isRequest);
        $reviewDate = normalize_workflow_date(trim((string) ($payload['custody_review_date'] ?? '')));
        if ($purpose === 'staff_custody' && $reviewDate === '') {
            throw new MobileApiException('validation_failed', 'Set a custody review date.', 422, ['custody_review_date' => ['Required.']]);
        }

        $result = mobile_api_operation($session, 'handover.create', $payload, function (int $ledgerId) use ($session, $payload, $purpose, $sourceStorage, $destinationStorage, $destinationStorageId, $recipient, $recipientName, $lines, $isRequest, $reviewDate): array {
            if (!$isRequest) {
                foreach ($lines as $line) {
                    mobile_api_assert_expected_balance(
                        (int) $line['item_id'],
                        (int) $sourceStorage['id'],
                        $line['expected_balance'] ?? null
                    );
                }
            }
            $number = next_workflow_number('HDO', 'handovers', 'handover_number');
            $status = $isRequest ? 'requested' : 'awaiting_receipt';
            Database::execute(
                'INSERT INTO handovers (
                    handover_number, source_storage_id, destination_storage_id, approver_user_id,
                    recipient_name, recipient_user_id, recipient_type, handover_purpose,
                    issue_condition, custody_review_date, usage_reporting_mode, handover_mode,
                    status, scheduled_for_date, notes, requested_at, issued_at,
                    created_by, updated_by, created_at, updated_at
                 ) VALUES (
                    :number, :source, :destination, :approver, :recipient_name, :recipient_user,
                    :recipient_type, :purpose, :issue_condition, :review_date, :usage_mode,
                    :handover_mode, :status, :scheduled, :notes, :requested_at, NOW(),
                    :created_by, :updated_by, NOW(), NOW()
                 )',
                [
                    'number' => $number,
                    'source' => (int) $sourceStorage['id'],
                    'destination' => $destinationStorageId > 0 ? $destinationStorageId : null,
                    'approver' => (int) ($sourceStorage['owner_user_id'] ?? 0) ?: null,
                    'recipient_name' => $recipientName,
                    'recipient_user' => (int) $recipient['id'],
                    'recipient_type' => $purpose === 'storage_transfer' ? 'storage' : 'staff',
                    'purpose' => $purpose,
                    'issue_condition' => in_array((string) ($payload['issue_condition'] ?? 'good'), array_keys(handover_issue_condition_options()), true) ? (string) $payload['issue_condition'] : 'good',
                    'review_date' => $reviewDate !== '' ? $reviewDate : null,
                    'usage_mode' => $purpose === 'temporary_use' ? 'operational_summary' : 'legacy_per_item',
                    'handover_mode' => $isRequest ? 'request' : 'direct',
                    'status' => $status,
                    'scheduled' => normalize_workflow_date(trim((string) ($payload['scheduled_for_date'] ?? ''))) ?: null,
                    'notes' => trim((string) ($payload['notes'] ?? '')) ?: null,
                    'requested_at' => $isRequest ? date('Y-m-d H:i:s') : null,
                    'created_by' => (int) $session['user_id'],
                    'updated_by' => (int) $session['user_id'],
                ]
            );
            $handoverId = Database::lastInsertId();
            $issueLines = [];
            foreach ($lines as $line) {
                $item = $line['item'];
                Database::execute(
                    'INSERT INTO handover_lines (
                        handover_id, item_id, item_name, item_sku, unit,
                        quantity_handed, quantity_received, quantity_used, quantity_returned,
                        created_at, updated_at
                     ) VALUES (
                        :handover_id, :item_id, :item_name, :item_sku, :unit,
                        :quantity, 0, 0, 0, NOW(), NOW()
                     )',
                    [
                        'handover_id' => $handoverId,
                        'item_id' => (int) $item['id'],
                        'item_name' => (string) $item['name'],
                        'item_sku' => (string) $item['sku'],
                        'unit' => (string) $item['unit'],
                        'quantity' => (float) $line['quantity'],
                    ]
                );
                $issueLines[] = ['item_id' => (int) $item['id'], 'item_name' => (string) $item['name'], 'quantity_handed' => (float) $line['quantity']];
            }
            if (!$isRequest) {
                issue_handover_inventory([
                    'id' => $handoverId,
                    'handover_number' => $number,
                    'source_storage_id' => (int) $sourceStorage['id'],
                    'destination_storage_id' => $destinationStorageId > 0 ? $destinationStorageId : null,
                    'recipient_name' => $recipientName,
                    'recipient_type' => $purpose === 'storage_transfer' ? 'storage' : 'staff',
                ], $issueLines, (int) $session['user_id']);
            }
            record_activity('mobile.handover_created', 'handover', $handoverId, 'Created handover ' . $number . ' from the mobile application.', ['mobile_operation_id' => $ledgerId, 'purpose' => $purpose]);
            return ['_entity_type' => 'handover', '_entity_id' => $handoverId, 'handover_id' => $handoverId, 'reference' => $number, 'status' => $status];
        });
        mobile_api_success($result, ['idempotent' => true], 201);
    });
}

function mobile_api_line_quantity_map(array $payload, string $key): array
{
    $rows = is_array($payload[$key] ?? null) ? $payload[$key] : [];
    $map = [];
    foreach ($rows as $lineId => $value) {
        if (is_array($value)) {
            $id = (int) ($value['line_id'] ?? 0);
            $quantity = $value['quantity'] ?? null;
        } else {
            $id = (int) $lineId;
            $quantity = $value;
        }
        if ($id > 0) {
            $map[$id] = $quantity;
        }
    }
    return $map;
}

function mobile_api_handover_store_proof(array $handover, string $stage, int $userId): ?array
{
    $file = $_FILES['proof_image'] ?? null;
    if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $error = validate_workflow_proof_upload($file);
    if ($error !== null) {
        throw new MobileApiException('invalid_proof', $error, 422, ['proof_image' => [$error]]);
    }
    $document = store_workflow_proof_document($file, 'handover', (string) $handover['handover_number'], $stage);
    return ['stored' => $document, 'user_id' => $userId];
}

function mobile_api_handover_commit_proof(array $handover, string $stage, ?array $proof): void
{
    if ($proof === null) {
        return;
    }
    create_workflow_document_record('handover', (int) $handover['id'], (string) $handover['handover_number'], 'proof_image', $stage, $proof['stored'], (int) $proof['user_id']);
}

function mobile_api_handover_delete_uncommitted_proof(?array $proof): void
{
    if ($proof !== null) {
        delete_workflow_document_file((string) $proof['stored']['stored_filename']);
    }
}

function handle_mobile_api_handover_receive(array $params): void
{
    mobile_api_run(function () use ($params): void {
        $session = mobile_api_session();
        mobile_api_require_permission($session, 'handovers.close');
        $handover = mobile_api_handover_fetch((int) $params['id']);
        if (!mobile_api_handover_can_receive($session, $handover)) {
            throw new MobileApiException('handover_not_receivable', 'Only the assigned recipient may confirm this receipt.', 403);
        }
        $payload = mobile_api_request_payload();
        $completed = mobile_api_completed_operation($session, $payload);
        if ($completed !== null) {
            mobile_api_success($completed, ['idempotent' => true]);
        }
        $lines = handover_lines((int) $handover['id']);
        [$updates, $errors, $hasVariance] = build_handover_receipt_updates($lines, mobile_api_line_quantity_map($payload, 'received_quantities'));
        if ($errors !== []) {
            throw new MobileApiException('validation_failed', implode(' ', array_unique($errors)), 422);
        }
        $proof = mobile_api_handover_store_proof($handover, 'receipt_report', (int) $session['user_id']);
        try {
            $result = mobile_api_operation($session, 'handover.receive', $payload, function (int $ledgerId) use ($session, $handover, $lines, $updates, $hasVariance, $payload, $proof): array {
                foreach ($updates as $update) {
                    Database::execute('UPDATE handover_lines SET quantity_received = :quantity, updated_at = NOW() WHERE id = :id', ['quantity' => (float) $update['received'], 'id' => (int) $update['line_id']]);
                }
                $isTransfer = handover_is_storage_transfer($handover);
                $nextStatus = $hasVariance ? 'receipt_review' : ($isTransfer ? 'closed' : 'delivered');
                if ($isTransfer && !$hasVariance) {
                    finalize_handover_storage_transfer_inventory($handover, $updates, (int) $session['user_id']);
                }
                Database::execute(
                    'UPDATE handovers SET status = :status, receipt_notes = :notes, receipt_reported_at = NOW(),
                        submitted_at = :submitted_at, submitted_by = :submitted_by,
                        approved_at = :approved_at, approved_by = :approved_by,
                        completed_at = :completed_at, completed_by = :completed_by,
                        updated_by = :updated_by, updated_at = NOW() WHERE id = :id',
                    [
                        'status' => $nextStatus,
                        'notes' => trim((string) ($payload['receipt_notes'] ?? '')) ?: null,
                        'submitted_at' => $isTransfer && !$hasVariance ? date('Y-m-d H:i:s') : null,
                        'submitted_by' => $isTransfer && !$hasVariance ? (int) $session['user_id'] : null,
                        'approved_at' => $isTransfer && !$hasVariance ? date('Y-m-d H:i:s') : null,
                        'approved_by' => $isTransfer && !$hasVariance ? (int) $session['user_id'] : null,
                        'completed_at' => $isTransfer && !$hasVariance ? date('Y-m-d H:i:s') : null,
                        'completed_by' => $isTransfer && !$hasVariance ? (int) $session['user_id'] : null,
                        'updated_by' => (int) $session['user_id'],
                        'id' => (int) $handover['id'],
                    ]
                );
                mobile_api_handover_commit_proof($handover, 'receipt_report', $proof);
                record_activity('mobile.handover_receipt_reported', 'handover', (int) $handover['id'], 'Receipt quantities reported from the mobile application.', ['mobile_operation_id' => $ledgerId, 'has_variance' => $hasVariance, 'quantities' => handover_receipt_audit_rows($lines, $updates)]);
                return ['_entity_type' => 'handover', '_entity_id' => (int) $handover['id'], 'handover_id' => (int) $handover['id'], 'status' => $nextStatus, 'has_variance' => $hasVariance];
            });
        } catch (Throwable $exception) {
            mobile_api_handover_delete_uncommitted_proof($proof);
            throw $exception;
        }
        try {
            $updated = mobile_api_handover_fetch((int) $handover['id']);
            ensure_workflow_signoff_pdf('handover', $updated, handover_lines((int) $handover['id']));
        } catch (Throwable $exception) {
            error_log('[mobile-api] Handover receipt signoff refresh failed: ' . $exception->getMessage());
        }
        mobile_api_success($result, ['idempotent' => true]);
    });
}

function handle_mobile_api_handover_confirm_receipt(array $params): void
{
    mobile_api_run(function () use ($params): void {
        $session = mobile_api_session();
        mobile_api_require_permission($session, 'handovers.approve');
        $handover = mobile_api_handover_fetch((int) $params['id']);
        if ((string) $handover['status'] !== 'receipt_review' || !mobile_api_handover_is_source_issuer($session, $handover)) {
            throw new MobileApiException('handover_not_reviewable', 'Only the source issuer may confirm a receipt difference.', 403);
        }
        $payload = mobile_api_json_input();
        $lines = handover_lines((int) $handover['id']);
        [$updates, $errors] = build_handover_receipt_updates($lines, mobile_api_line_quantity_map($payload, 'received_quantities'));
        if ($errors !== []) {
            throw new MobileApiException('validation_failed', implode(' ', array_unique($errors)), 422);
        }
        $result = mobile_api_operation($session, 'handover.confirm_receipt', $payload, function (int $ledgerId) use ($session, $handover, $lines, $updates, $payload): array {
            reconcile_handover_receipt_inventory($handover, $updates, (int) $session['user_id'], 'Mobile issuer receipt confirmation', !handover_is_storage_transfer($handover));
            foreach ($updates as $update) {
                Database::execute('UPDATE handover_lines SET quantity_received = :quantity, updated_at = NOW() WHERE id = :id', ['quantity' => (float) $update['received'], 'id' => (int) $update['line_id']]);
            }
            $isTransfer = handover_is_storage_transfer($handover);
            if ($isTransfer) {
                finalize_handover_storage_transfer_inventory($handover, $updates, (int) $session['user_id']);
            }
            $nextStatus = $isTransfer ? 'closed' : 'delivered';
            Database::execute(
                'UPDATE handovers SET status = :status, receipt_notes = :notes,
                    submitted_at = :submitted_at, submitted_by = :submitted_by,
                    approved_at = :approved_at, approved_by = :approved_by,
                    completed_at = :completed_at, completed_by = :completed_by,
                    updated_by = :updated_by, updated_at = NOW() WHERE id = :id',
                [
                    'status' => $nextStatus,
                    'notes' => trim((string) ($payload['receipt_notes'] ?? $handover['receipt_notes'] ?? '')) ?: null,
                    'submitted_at' => $isTransfer ? date('Y-m-d H:i:s') : null,
                    'submitted_by' => $isTransfer ? (int) $session['user_id'] : null,
                    'approved_at' => $isTransfer ? date('Y-m-d H:i:s') : null,
                    'approved_by' => $isTransfer ? (int) $session['user_id'] : null,
                    'completed_at' => $isTransfer ? date('Y-m-d H:i:s') : null,
                    'completed_by' => $isTransfer ? (int) $session['user_id'] : null,
                    'updated_by' => (int) $session['user_id'],
                    'id' => (int) $handover['id'],
                ]
            );
            record_activity('mobile.handover_receipt_confirmed', 'handover', (int) $handover['id'], 'Issuer confirmed mobile receipt quantities.', ['mobile_operation_id' => $ledgerId, 'quantities' => handover_receipt_audit_rows($lines, $updates, true)]);
            return ['_entity_type' => 'handover', '_entity_id' => (int) $handover['id'], 'handover_id' => (int) $handover['id'], 'status' => $nextStatus];
        });
        try {
            $updated = mobile_api_handover_fetch((int) $handover['id']);
            ensure_workflow_signoff_pdf('handover', $updated, handover_lines((int) $handover['id']));
        } catch (Throwable $exception) {
            error_log('[mobile-api] Receipt confirmation signoff refresh failed: ' . $exception->getMessage());
        }
        mobile_api_success($result, ['idempotent' => true]);
    });
}

function handle_mobile_api_handover_closeout(array $params): void
{
    mobile_api_run(function () use ($params): void {
        $session = mobile_api_session();
        mobile_api_require_permission($session, 'handovers.close');
        $handover = mobile_api_handover_fetch((int) $params['id']);
        if (handover_purpose_value($handover) !== 'temporary_use' || (string) $handover['status'] !== 'delivered' || (!mobile_api_handover_is_owner($session) && (int) $handover['recipient_user_id'] !== (int) $session['user_id'])) {
            throw new MobileApiException('handover_not_closeable', 'Only the recipient may report usage for a delivered temporary handover.', 403);
        }
        $payload = mobile_api_request_payload();
        $completed = mobile_api_completed_operation($session, $payload);
        if ($completed !== null) {
            mobile_api_success($completed, ['idempotent' => true]);
        }
        $lines = handover_lines((int) $handover['id']);
        [$lineUpdates, $lineErrors] = build_handover_operational_line_updates($lines, mobile_api_line_quantity_map($payload, 'returned_quantities'));
        [$reconciliations, $reconciliationErrors] = build_handover_reconciliation_payloads($lines, $lineUpdates, $payload['reconciliations'] ?? [], false);
        $errors = array_merge($lineErrors, $reconciliationErrors);
        if ($errors !== []) {
            throw new MobileApiException('validation_failed', implode(' ', array_unique($errors)), 422);
        }
        $proof = mobile_api_handover_store_proof($handover, 'closeout_report', (int) $session['user_id']);
        try {
            $result = mobile_api_operation($session, 'handover.closeout', $payload, function (int $ledgerId) use ($session, $handover, $lineUpdates, $reconciliations, $payload, $proof): array {
                foreach ($lineUpdates as $update) {
                    Database::execute('UPDATE handover_lines SET quantity_used = :used, quantity_returned = :returned, updated_at = NOW() WHERE id = :id', ['used' => (float) $update['used'], 'returned' => (float) $update['returned'], 'id' => (int) $update['line_id']]);
                }
                clear_legacy_handover_usage_breakdowns((int) $handover['id']);
                save_handover_reconciliations((int) $handover['id'], $reconciliations, (int) $session['user_id'], false);
                Database::execute('UPDATE handovers SET status = "pending_approval", closed_notes = :notes, submitted_at = NOW(), submitted_by = :user_id, updated_by = :user_id, updated_at = NOW() WHERE id = :id', ['notes' => trim((string) ($payload['close_notes'] ?? '')) ?: null, 'user_id' => (int) $session['user_id'], 'id' => (int) $handover['id']]);
                mobile_api_handover_commit_proof($handover, 'closeout_report', $proof);
                record_activity('mobile.handover_closeout_submitted', 'handover', (int) $handover['id'], 'Submitted mobile handover usage and returns for issuer review.', ['mobile_operation_id' => $ledgerId]);
                return ['_entity_type' => 'handover', '_entity_id' => (int) $handover['id'], 'handover_id' => (int) $handover['id'], 'status' => 'pending_approval'];
            });
        } catch (Throwable $exception) {
            mobile_api_handover_delete_uncommitted_proof($proof);
            throw $exception;
        }
        try {
            $updated = mobile_api_handover_fetch((int) $handover['id']);
            ensure_workflow_signoff_pdf('handover', $updated, handover_lines((int) $handover['id']));
        } catch (Throwable $exception) {
            error_log('[mobile-api] Closeout signoff refresh failed: ' . $exception->getMessage());
        }
        mobile_api_success($result, ['idempotent' => true]);
    });
}

function handle_mobile_api_handover_approve_closeout(array $params): void
{
    mobile_api_run(function () use ($params): void {
        $session = mobile_api_session();
        mobile_api_require_permission($session, 'handovers.approve');
        $handover = mobile_api_handover_fetch((int) $params['id']);
        if (handover_purpose_value($handover) !== 'temporary_use' || (string) $handover['status'] !== 'pending_approval' || !mobile_api_handover_is_source_issuer($session, $handover)) {
            throw new MobileApiException('handover_not_approvable', 'Only the source issuer may approve this temporary handover closeout.', 403);
        }
        $payload = mobile_api_json_input();
        $lines = handover_lines((int) $handover['id']);
        [$lineUpdates, $lineErrors] = build_handover_operational_line_updates($lines, mobile_api_line_quantity_map($payload, 'returned_quantities'));
        [$reconciliations, $reconciliationErrors] = build_handover_reconciliation_payloads($lines, $lineUpdates, $payload['reconciliations'] ?? [], true);
        $errors = array_merge($lineErrors, $reconciliationErrors);
        if ($errors !== []) {
            throw new MobileApiException('validation_failed', implode(' ', array_unique($errors)), 422);
        }
        $result = mobile_api_operation($session, 'handover.approve_closeout', $payload, function (int $ledgerId) use ($session, $handover, $lineUpdates, $reconciliations, $payload): array {
            foreach ($lineUpdates as $update) {
                Database::execute('UPDATE handover_lines SET quantity_used = :used, quantity_returned = :returned, updated_at = NOW() WHERE id = :id', ['used' => (float) $update['used'], 'returned' => (float) $update['returned'], 'id' => (int) $update['line_id']]);
            }
            clear_legacy_handover_usage_breakdowns((int) $handover['id']);
            save_handover_reconciliations((int) $handover['id'], $reconciliations, (int) $session['user_id'], true);
            finalize_handover_inventory($handover, $lineUpdates, (int) $session['user_id']);
            Database::execute('UPDATE handovers SET status = "closed", closed_notes = :notes, approved_at = NOW(), completed_at = NOW(), approved_by = :user_id, completed_by = :user_id, updated_by = :user_id, updated_at = NOW() WHERE id = :id', ['notes' => trim((string) ($payload['approval_notes'] ?? $handover['closed_notes'] ?? '')) ?: null, 'user_id' => (int) $session['user_id'], 'id' => (int) $handover['id']]);
            record_activity('mobile.handover_closeout_approved', 'handover', (int) $handover['id'], 'Approved mobile handover closeout and posted final stock.', ['mobile_operation_id' => $ledgerId]);
            return ['_entity_type' => 'handover', '_entity_id' => (int) $handover['id'], 'handover_id' => (int) $handover['id'], 'status' => 'closed'];
        });
        try {
            $updated = mobile_api_handover_fetch((int) $handover['id']);
            ensure_workflow_signoff_pdf('handover', $updated, handover_lines((int) $handover['id']));
        } catch (Throwable $exception) {
            error_log('[mobile-api] Approved signoff refresh failed: ' . $exception->getMessage());
        }
        mobile_api_success($result, ['idempotent' => true]);
    });
}

function handle_mobile_api_handover_approve_request(array $params): void
{
    mobile_api_run(function () use ($params): void {
        $session = mobile_api_session();
        mobile_api_require_permission($session, 'handovers.approve');
        $handover = mobile_api_handover_fetch((int) $params['id']);
        if ((string) $handover['status'] !== 'requested' || !mobile_api_handover_is_source_issuer($session, $handover)) {
            throw new MobileApiException('handover_not_approvable', 'Only the source issuer may approve this request.', 403);
        }
        $payload = mobile_api_json_input();
        $lines = handover_lines((int) $handover['id']);
        $result = mobile_api_operation($session, 'handover.approve_request', $payload, function (int $ledgerId) use ($session, $handover, $lines, $payload): array {
            issue_handover_inventory($handover, $lines, (int) $session['user_id']);
            Database::execute(
                'UPDATE handovers
                 SET status = "awaiting_receipt", request_decision_notes = :notes,
                     request_approved_at = NOW(), request_approved_by = :user_id,
                     issued_at = NOW(), updated_by = :user_id, updated_at = NOW()
                 WHERE id = :id',
                [
                    'notes' => trim((string) ($payload['notes'] ?? '')) ?: null,
                    'user_id' => (int) $session['user_id'],
                    'id' => (int) $handover['id'],
                ]
            );
            record_activity('mobile.handover_request_approved', 'handover', (int) $handover['id'], 'Approved mobile handover request and reserved source stock.', ['mobile_operation_id' => $ledgerId]);
            return ['_entity_type' => 'handover', '_entity_id' => (int) $handover['id'], 'handover_id' => (int) $handover['id'], 'status' => 'awaiting_receipt'];
        });
        mobile_api_success($result, ['idempotent' => true]);
    });
}

function handle_mobile_api_handover_reject_request(array $params): void
{
    mobile_api_run(function () use ($params): void {
        $session = mobile_api_session();
        mobile_api_require_permission($session, 'handovers.approve');
        $handover = mobile_api_handover_fetch((int) $params['id']);
        if ((string) $handover['status'] !== 'requested' || !mobile_api_handover_is_source_issuer($session, $handover)) {
            throw new MobileApiException('handover_not_rejectable', 'Only the source issuer may reject this request.', 403);
        }
        $payload = mobile_api_json_input();
        $result = mobile_api_operation($session, 'handover.reject_request', $payload, function (int $ledgerId) use ($session, $handover, $payload): array {
            Database::execute(
                'UPDATE handovers
                 SET status = "rejected", request_decision_notes = :notes,
                     request_rejected_at = NOW(), updated_by = :user_id, updated_at = NOW()
                 WHERE id = :id',
                [
                    'notes' => trim((string) ($payload['notes'] ?? '')) ?: null,
                    'user_id' => (int) $session['user_id'],
                    'id' => (int) $handover['id'],
                ]
            );
            record_activity('mobile.handover_request_rejected', 'handover', (int) $handover['id'], 'Rejected mobile handover request.', ['mobile_operation_id' => $ledgerId]);
            return ['_entity_type' => 'handover', '_entity_id' => (int) $handover['id'], 'handover_id' => (int) $handover['id'], 'status' => 'rejected'];
        });
        mobile_api_success($result, ['idempotent' => true]);
    });
}

function handle_mobile_api_handover_cancel(array $params): void
{
    mobile_api_run(function () use ($params): void {
        $session = mobile_api_session();
        $handover = mobile_api_handover_fetch((int) $params['id']);
        $status = (string) $handover['status'];
        $userId = (int) $session['user_id'];
        $isOwnUnapprovedRequest = $status === 'requested' && $userId === (int) $handover['created_by'];
        if (!$isOwnUnapprovedRequest && !mobile_api_handover_is_source_issuer($session, $handover)) {
            throw new MobileApiException('handover_not_cancelable', 'Only the requester before approval or the source issuer may cancel this handover.', 403);
        }
        if (!in_array($status, ['requested', 'awaiting_receipt', 'receipt_review', 'delivered'], true)) {
            throw new MobileApiException('handover_not_cancelable', 'This handover can no longer be cancelled through the normal workflow.', 409);
        }
        if ($status === 'delivered' && !mobile_api_handover_is_source_issuer($session, $handover)) {
            throw new MobileApiException('handover_not_cancelable', 'The receiver cannot cancel after delivery; report the issue to the source issuer.', 403);
        }
        $payload = mobile_api_json_input();
        $lines = handover_lines((int) $handover['id']);
        $result = mobile_api_operation($session, 'handover.cancel', $payload, function (int $ledgerId) use ($session, $handover, $lines, $payload): array {
            cancel_handover_inventory($handover, $lines, (int) $session['user_id']);
            Database::execute(
                'UPDATE handovers
                 SET status = "cancelled", closed_notes = :notes, cancelled_at = NOW(),
                     updated_by = :user_id, updated_at = NOW()
                 WHERE id = :id',
                [
                    'notes' => trim((string) ($payload['notes'] ?? '')) ?: null,
                    'user_id' => (int) $session['user_id'],
                    'id' => (int) $handover['id'],
                ]
            );
            record_activity('mobile.handover_cancelled', 'handover', (int) $handover['id'], 'Cancelled mobile handover through the audited workflow.', ['mobile_operation_id' => $ledgerId]);
            return ['_entity_type' => 'handover', '_entity_id' => (int) $handover['id'], 'handover_id' => (int) $handover['id'], 'status' => 'cancelled'];
        });
        mobile_api_success($result, ['idempotent' => true]);
    });
}

function mobile_api_custody_return_fetch(int $handoverId, int $returnId): array
{
    $return = Database::fetch(
        'SELECT custody_return.*, submitter.name AS submitted_by_name, reviewer.name AS reviewed_by_name
         FROM handover_custody_returns custody_return
         LEFT JOIN users submitter ON submitter.id = custody_return.submitted_by
         LEFT JOIN users reviewer ON reviewer.id = custody_return.reviewed_by
         WHERE custody_return.id = :return_id AND custody_return.handover_id = :handover_id
         LIMIT 1',
        ['return_id' => $returnId, 'handover_id' => $handoverId]
    );
    if (!$return) {
        throw new MobileApiException('custody_return_not_found', 'Custody return not found.', 404);
    }
    $return['lines'] = handover_custody_return_lines($returnId);
    foreach ($return['lines'] as &$line) {
        $line['proofs'] = handover_custody_return_proofs((int) $line['id']);
    }
    unset($line);
    return $return;
}

function mobile_api_custody_line_payloads(array $payload): array
{
    $rows = is_array($payload['lines'] ?? null) ? $payload['lines'] : [];
    $normalized = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $lineId = (int) ($row['handover_line_id'] ?? 0);
        if ($lineId <= 0) {
            continue;
        }
        $normalized[$lineId] = [
            'handover_line_id' => $lineId,
            'serviceable_quantity' => quantity_value($row['serviceable_quantity'] ?? 0),
            'damaged_quantity' => quantity_value($row['damaged_quantity'] ?? 0),
            'consumed_quantity' => quantity_value($row['consumed_quantity'] ?? 0),
            'lost_quantity' => quantity_value($row['lost_quantity'] ?? 0),
            'notes' => trim((string) ($row['notes'] ?? '')),
        ];
    }
    return $normalized;
}

function mobile_api_custody_proof_file(int $handoverLineId): ?array
{
    $key = 'damage_proof_' . $handoverLineId;
    return uploaded_file($key) ?? uploaded_file_at('damage_proof', $handoverLineId);
}

function mobile_api_store_custody_proofs(array $handover, array $linePayloads): array
{
    $stored = [];
    try {
        foreach ($linePayloads as $lineId => $line) {
            $file = mobile_api_custody_proof_file((int) $lineId);
            $error = validate_workflow_proof_upload($file);
            if ($error !== null) {
                throw new MobileApiException('invalid_proof', $error, 422, ['damage_proof_' . $lineId => [$error]]);
            }
            if ((float) $line['damaged_quantity'] > 0 && $file === null) {
                throw new MobileApiException(
                    'damage_proof_required',
                    'A proof image is required for every damaged return.',
                    422,
                    ['damage_proof_' . $lineId => ['Required for damaged stock.']]
                );
            }
            if ($file !== null) {
                $stored[(int) $lineId] = store_workflow_proof_document(
                    $file,
                    'handover',
                    (string) $handover['handover_number'],
                    'custody_damage_return'
                );
            }
        }
    } catch (Throwable $exception) {
        mobile_api_delete_custody_proofs($stored);
        throw $exception;
    }
    return $stored;
}

function mobile_api_delete_custody_proofs(array $documents): void
{
    foreach ($documents as $document) {
        delete_workflow_document_file((string) ($document['stored_filename'] ?? ''));
    }
}

function handle_mobile_api_handover_custody_return_create(array $params): void
{
    mobile_api_run(function () use ($params): void {
        $session = mobile_api_session();
        mobile_api_require_capability($session, 'custody');
        mobile_api_require_permission($session, 'handovers.custody_return');
        $handover = mobile_api_handover_fetch((int) $params['id']);
        if (!handover_is_staff_custody($handover)
            || (string) $handover['status'] !== 'delivered'
            || (int) $handover['recipient_user_id'] !== (int) $session['user_id']) {
            throw new MobileApiException('custody_return_forbidden', 'Only the assigned employee may report a delivered custody return.', 403);
        }
        $payload = mobile_api_request_payload();
        $completed = mobile_api_completed_operation($session, $payload);
        if ($completed !== null) {
            mobile_api_success($completed, ['idempotent' => true]);
        }
        $linePayloads = mobile_api_custody_line_payloads($payload);
        $handoverLines = [];
        foreach (handover_lines((int) $handover['id']) as $line) {
            $handoverLines[(int) $line['id']] = $line;
        }
        $errors = [];
        $total = 0.0;
        foreach ($linePayloads as $lineId => $line) {
            $handoverLine = $handoverLines[$lineId] ?? null;
            if (!$handoverLine) {
                $errors[] = 'A submitted line does not belong to this handover.';
                continue;
            }
            foreach (['serviceable_quantity', 'damaged_quantity', 'consumed_quantity', 'lost_quantity'] as $field) {
                if ((float) $line[$field] < 0) {
                    $errors[] = (string) $handoverLine['item_name'] . ': quantities cannot be negative.';
                }
            }
            $lineTotal = handover_custody_return_line_total($line);
            if ($lineTotal > handover_line_held_quantity($handoverLine) + 0.009) {
                $errors[] = (string) $handoverLine['item_name'] . ': return outcomes exceed the quantity still held.';
            }
            if ((float) $line['lost_quantity'] > 0 && $line['notes'] === '' && trim((string) ($payload['notes'] ?? '')) === '') {
                $errors[] = (string) $handoverLine['item_name'] . ': explain the lost or missing quantity.';
            }
            $total += $lineTotal;
        }
        if ($total <= 0.009) {
            $errors[] = 'Enter at least one serviceable, damaged, consumed, or lost quantity.';
        }
        if ($errors !== []) {
            throw new MobileApiException('validation_failed', implode(' ', array_unique($errors)), 422);
        }
        $storedProofs = mobile_api_store_custody_proofs($handover, $linePayloads);
        try {
            $result = mobile_api_operation($session, 'handover.custody_return.submit', $payload, function (int $ledgerId) use ($session, $handover, $payload, $linePayloads, $storedProofs): array {
                $returnNumber = handover_custody_return_number();
                Database::execute(
                    'INSERT INTO handover_custody_returns (
                        handover_id, return_number, status, return_date, notes,
                        submitted_by, submitted_at, created_by, updated_by, created_at, updated_at
                     ) VALUES (
                        :handover_id, :return_number, "submitted", :return_date, :notes,
                        :user_id, NOW(), :user_id, :user_id, NOW(), NOW()
                     )',
                    [
                        'handover_id' => (int) $handover['id'],
                        'return_number' => $returnNumber,
                        'return_date' => normalize_workflow_date(trim((string) ($payload['return_date'] ?? ''))) ?: date('Y-m-d'),
                        'notes' => trim((string) ($payload['notes'] ?? '')) ?: null,
                        'user_id' => (int) $session['user_id'],
                    ]
                );
                $returnId = Database::lastInsertId();
                foreach ($linePayloads as $handoverLineId => $line) {
                    $handoverLine = Database::fetch('SELECT item_id FROM handover_lines WHERE id = :id LIMIT 1', ['id' => $handoverLineId]);
                    Database::execute(
                        'INSERT INTO handover_custody_return_lines (
                            custody_return_id, handover_line_id, item_id,
                            serviceable_quantity, damaged_quantity, consumed_quantity, lost_quantity,
                            notes, created_at, updated_at
                         ) VALUES (
                            :return_id, :handover_line_id, :item_id,
                            :serviceable, :damaged, :consumed, :lost,
                            :notes, NOW(), NOW()
                         )',
                        [
                            'return_id' => $returnId,
                            'handover_line_id' => $handoverLineId,
                            'item_id' => (int) ($handoverLine['item_id'] ?? 0),
                            'serviceable' => (float) $line['serviceable_quantity'],
                            'damaged' => (float) $line['damaged_quantity'],
                            'consumed' => (float) $line['consumed_quantity'],
                            'lost' => (float) $line['lost_quantity'],
                            'notes' => $line['notes'] !== '' ? $line['notes'] : null,
                        ]
                    );
                    $returnLineId = Database::lastInsertId();
                    if (isset($storedProofs[$handoverLineId])) {
                        $documentId = create_workflow_document_record(
                            'handover',
                            (int) $handover['id'],
                            (string) $handover['handover_number'],
                            'proof_image',
                            'custody_damage_return',
                            $storedProofs[$handoverLineId],
                            (int) $session['user_id']
                        );
                        Database::execute(
                            'INSERT INTO handover_custody_return_proofs (custody_return_line_id, workflow_document_id, created_at)
                             VALUES (:return_line_id, :document_id, NOW())',
                            ['return_line_id' => $returnLineId, 'document_id' => $documentId]
                        );
                    }
                }
                Database::execute('UPDATE handovers SET updated_by = :user_id, updated_at = NOW() WHERE id = :id', ['user_id' => (int) $session['user_id'], 'id' => (int) $handover['id']]);
                record_activity('mobile.custody_return_submitted', 'handover', (int) $handover['id'], 'Submitted mobile custody return ' . $returnNumber . ' for issuer review.', ['mobile_operation_id' => $ledgerId]);
                return [
                    '_entity_type' => 'handover_custody_return',
                    '_entity_id' => $returnId,
                    'handover_id' => (int) $handover['id'],
                    'custody_return_id' => $returnId,
                    'reference' => $returnNumber,
                    'status' => 'submitted',
                ];
            });
        } catch (Throwable $exception) {
            mobile_api_delete_custody_proofs($storedProofs);
            throw $exception;
        }
        custody_refresh_signoff_documents($handover);
        mobile_api_success($result, ['idempotent' => true], 201);
    });
}

function handle_mobile_api_handover_custody_return_show(array $params): void
{
    mobile_api_run(function () use ($params): void {
        $session = mobile_api_session();
        $handover = mobile_api_handover_fetch((int) $params['id']);
        mobile_api_handover_assert_viewable($session, $handover);
        mobile_api_success(mobile_api_custody_return_fetch((int) $handover['id'], (int) $params['return_id']));
    });
}

function handle_mobile_api_handover_custody_return_approve(array $params): void
{
    mobile_api_run(function () use ($params): void {
        $session = mobile_api_session();
        mobile_api_require_permission($session, 'handovers.custody_approve');
        $handover = mobile_api_handover_fetch((int) $params['id']);
        if (!handover_is_staff_custody($handover) || !mobile_api_handover_is_source_issuer($session, $handover)) {
            throw new MobileApiException('custody_review_forbidden', 'Only the source issuer may approve this custody return.', 403);
        }
        $custodyReturn = mobile_api_custody_return_fetch((int) $handover['id'], (int) $params['return_id']);
        if ((string) $custodyReturn['status'] !== 'submitted') {
            throw new MobileApiException('custody_return_not_reviewable', 'This custody return is no longer waiting for approval.', 409);
        }
        $payload = mobile_api_json_input();
        $result = mobile_api_operation($session, 'handover.custody_return.approve', $payload, function (int $ledgerId) use ($session, $handover, $custodyReturn, $payload): array {
            $returnLines = handover_custody_return_lines((int) $custodyReturn['id']);
            $bufferStorageId = system_storage_id('handover_buffer');
            $quarantineStorageId = system_storage_id('damaged_quarantine');
            foreach ($returnLines as $returnLine) {
                if ((float) $returnLine['damaged_quantity'] > 0 && (int) ($returnLine['proof_count'] ?? 0) === 0) {
                    throw new MobileApiException('damage_proof_required', (string) $returnLine['item_name'] . ' is missing damage proof.', 422);
                }
                $handoverLine = Database::fetch('SELECT * FROM handover_lines WHERE id = :id FOR UPDATE', ['id' => (int) $returnLine['handover_line_id']]);
                $item = Database::fetch('SELECT * FROM items WHERE id = :id LIMIT 1', ['id' => (int) $returnLine['item_id']]);
                if (!$handoverLine || !$item) {
                    throw new MobileApiException('custody_line_missing', 'A custody return item no longer exists.', 409);
                }
                if (handover_custody_return_line_total($returnLine) > handover_line_held_quantity($handoverLine) + 0.009) {
                    throw new MobileApiException('balance_changed', (string) $returnLine['item_name'] . ' exceeds the quantity still held.', 409, [], true);
                }
                $reference = (string) $custodyReturn['return_number'] . ' / ' . (string) $handover['handover_number'];
                $serviceable = (float) $returnLine['serviceable_quantity'];
                $damaged = (float) $returnLine['damaged_quantity'];
                $consumed = (float) $returnLine['consumed_quantity'];
                $lost = (float) $returnLine['lost_quantity'];
                if ($serviceable > 0) {
                    apply_inventory_movement($item, 'transfer', $serviceable, $bufferStorageId, (int) $handover['source_storage_id'], date('Y-m-d H:i:s'), $reference, 'Serviceable custody return restored to source storage.', (int) $session['user_id'], 'handover', (int) $handover['id']);
                }
                if ($damaged > 0) {
                    apply_inventory_movement($item, 'transfer', $damaged, $bufferStorageId, $quarantineStorageId, date('Y-m-d H:i:s'), $reference, 'Damaged custody return moved to quarantine.', (int) $session['user_id'], 'handover', (int) $handover['id']);
                }
                if ($consumed > 0) {
                    apply_inventory_movement($item, 'usage', $consumed, $bufferStorageId, null, date('Y-m-d H:i:s'), $reference, 'Custody item consumed or worn out.', (int) $session['user_id'], 'handover', (int) $handover['id']);
                }
                if ($lost > 0) {
                    apply_inventory_movement($item, 'usage', $lost, $bufferStorageId, null, date('Y-m-d H:i:s'), $reference, 'Custody item reported lost or missing. ' . trim((string) ($returnLine['notes'] ?? '')), (int) $session['user_id'], 'handover', (int) $handover['id']);
                }
                Database::execute(
                    'UPDATE handover_lines SET quantity_returned = quantity_returned + :returned,
                        quantity_used = quantity_used + :used, updated_at = NOW() WHERE id = :id',
                    ['returned' => round($serviceable + $damaged, 2), 'used' => round($consumed + $lost, 2), 'id' => (int) $handoverLine['id']]
                );
            }
            $heldTotal = (float) Database::scalar(
                'SELECT COALESCE(SUM(GREATEST(quantity_received - quantity_used - quantity_returned, 0)), 0)
                 FROM handover_lines WHERE handover_id = :handover_id',
                ['handover_id' => (int) $handover['id']]
            );
            $nextStatus = $heldTotal <= 0.009 ? 'closed' : 'delivered';
            Database::execute(
                'UPDATE handover_custody_returns SET status = "approved", review_notes = :notes,
                    reviewed_by = :user_id, reviewed_at = NOW(), updated_by = :user_id, updated_at = NOW() WHERE id = :id',
                ['notes' => trim((string) ($payload['review_notes'] ?? '')) ?: null, 'user_id' => (int) $session['user_id'], 'id' => (int) $custodyReturn['id']]
            );
            if ($nextStatus === 'closed') {
                Database::execute(
                    'UPDATE handovers SET status = "closed", approved_at = NOW(), completed_at = NOW(),
                        approved_by = :user_id, completed_by = :user_id, updated_by = :user_id, updated_at = NOW() WHERE id = :id',
                    ['user_id' => (int) $session['user_id'], 'id' => (int) $handover['id']]
                );
            } else {
                Database::execute(
                    'UPDATE handovers SET status = "delivered", updated_by = :user_id, updated_at = NOW() WHERE id = :id',
                    ['user_id' => (int) $session['user_id'], 'id' => (int) $handover['id']]
                );
            }
            record_activity('mobile.custody_return_approved', 'handover', (int) $handover['id'], 'Approved mobile custody return ' . (string) $custodyReturn['return_number'] . '.', ['mobile_operation_id' => $ledgerId]);
            return ['_entity_type' => 'handover_custody_return', '_entity_id' => (int) $custodyReturn['id'], 'handover_id' => (int) $handover['id'], 'custody_return_id' => (int) $custodyReturn['id'], 'status' => 'approved', 'handover_status' => $nextStatus, 'quantity_still_held' => round($heldTotal, 2)];
        });
        custody_refresh_signoff_documents($handover);
        mobile_api_success($result, ['idempotent' => true]);
    });
}

function handle_mobile_api_handover_custody_return_reject(array $params): void
{
    mobile_api_run(function () use ($params): void {
        $session = mobile_api_session();
        mobile_api_require_permission($session, 'handovers.custody_approve');
        $handover = mobile_api_handover_fetch((int) $params['id']);
        if (!handover_is_staff_custody($handover) || !mobile_api_handover_is_source_issuer($session, $handover)) {
            throw new MobileApiException('custody_review_forbidden', 'Only the source issuer may reject this custody return.', 403);
        }
        $custodyReturn = mobile_api_custody_return_fetch((int) $handover['id'], (int) $params['return_id']);
        if ((string) $custodyReturn['status'] !== 'submitted') {
            throw new MobileApiException('custody_return_not_reviewable', 'This custody return is no longer waiting for review.', 409);
        }
        $payload = mobile_api_json_input();
        $reason = trim((string) ($payload['rejection_notes'] ?? ''));
        if ($reason === '') {
            throw new MobileApiException('rejection_reason_required', 'Explain what the employee must correct.', 422, ['rejection_notes' => ['Required.']]);
        }
        $result = mobile_api_operation($session, 'handover.custody_return.reject', $payload, function (int $ledgerId) use ($session, $handover, $custodyReturn, $reason): array {
            Database::execute(
                'UPDATE handover_custody_returns SET status = "rejected", rejection_notes = :reason,
                    reviewed_by = :user_id, reviewed_at = NOW(), updated_by = :user_id, updated_at = NOW() WHERE id = :id',
                ['reason' => $reason, 'user_id' => (int) $session['user_id'], 'id' => (int) $custodyReturn['id']]
            );
            record_activity('mobile.custody_return_rejected', 'handover', (int) $handover['id'], 'Rejected mobile custody return ' . (string) $custodyReturn['return_number'] . ' for correction.', ['mobile_operation_id' => $ledgerId]);
            return ['_entity_type' => 'handover_custody_return', '_entity_id' => (int) $custodyReturn['id'], 'handover_id' => (int) $handover['id'], 'custody_return_id' => (int) $custodyReturn['id'], 'status' => 'rejected'];
        });
        mobile_api_success($result, ['idempotent' => true]);
    });
}
