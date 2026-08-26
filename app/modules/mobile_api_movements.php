<?php
declare(strict_types=1);

function mobile_api_movement_proof_file(): ?array
{
    $proofFile = $_FILES['proof_image'] ?? null;
    if (!is_array($proofFile) || (int) ($proofFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $error = validate_workflow_proof_upload($proofFile);
    if ($error !== null) {
        throw new MobileApiException('invalid_proof_image', $error, 422, ['proof_image' => [$error]]);
    }

    return $proofFile;
}

function mobile_api_measurement_from_line(array $item, array $line, string $fieldPrefix = ''): array
{
    try {
        return inventory_measurement_from_payload($item, $line);
    } catch (InvalidArgumentException $exception) {
        $field = $fieldPrefix . (array_key_exists('input_quantity', $line) ? 'input_quantity' : 'quantity');
        throw new MobileApiException(
            'validation_failed',
            $exception->getMessage(),
            422,
            [$field => [$exception->getMessage()]]
        );
    }
}

function mobile_api_movement_department_override(array $session, array $payload, string $field = 'department_id'): ?int
{
    $departmentId = normalize_entity_id($payload['department_id'] ?? null);
    if ($departmentId === null) {
        return null;
    }
    mobile_api_require_permission($session, 'movements.override_department');

    try {
        return valid_department_assignment_id($departmentId);
    } catch (InvalidArgumentException $exception) {
        throw new MobileApiException('validation_failed', $exception->getMessage(), 422, [$field => [$exception->getMessage()]]);
    }
}

function mobile_api_validate_movement_proof(?array $proofFile, array $items, array $operationTypes): void
{
    foreach (array_unique($operationTypes) as $operationType) {
        $proofType = $operationType === 'restock' ? 'refill' : 'usage';
        if (inventory_operation_requires_proof($items, $proofType) && $proofFile === null) {
            throw new MobileApiException(
                'proof_required',
                'A proof image is required for this ' . ($proofType === 'refill' ? 'refill' : 'usage') . '.',
                422,
                ['proof_image' => ['Required.']]
            );
        }
    }
}

function mobile_api_post_movement(string $type): void
{
    mobile_api_run(function () use ($type): void {
        $session = mobile_api_session();
        $permission = $type === 'usage' ? 'movements.usage' : 'movements.restock';
        mobile_api_require_capability($session, $type === 'usage' ? 'usage' : 'restock');
        mobile_api_require_permission($session, $permission);
        $mobileAccess = mobile_api_require_employee_access($session);
        if ($type === 'restock'
            && (site_setting('mobile.manual_restock_enabled', '0') !== '1'
                || (int) ($mobileAccess['direct_restock_enabled'] ?? 0) !== 1)) {
            throw new MobileApiException('restock_disabled', 'Direct mobile refill is disabled.', 403);
        }

        $payload = mobile_api_request_payload();
        $storageId = (int) ($payload['storage_id'] ?? 0);
        $itemId = (int) ($payload['item_id'] ?? 0);
        mobile_api_require_storage($session, $storageId);
        $item = mobile_api_find_item($itemId, [$storageId]);
        $measurement = mobile_api_measurement_from_line($item, $payload);
        $departmentId = mobile_api_movement_department_override($session, $payload);
        $proofFile = mobile_api_movement_proof_file();
        mobile_api_validate_movement_proof($proofFile, [$item], [$type]);

        $reasonInput = $type === 'usage'
            ? usage_reason_input_for_storage(
                $storageId,
                (string) ($payload['reason'] ?? ''),
                isset($payload['custom_reason']) ? (string) $payload['custom_reason'] : null
            )
            : ['code' => '', 'custom_reason' => null];

        $storedProof = null;
        try {
            $result = mobile_api_operation(
                $session,
                'movement.' . $type,
                $payload,
                function (int $ledgerId) use (
                    $session,
                    $type,
                    $storageId,
                    $payload,
                    $item,
                    $measurement,
                    $departmentId,
                    $reasonInput,
                    $proofFile,
                    &$storedProof
                ): array {
                    mobile_api_assert_expected_balance((int) $item['id'], $storageId, $payload['expected_balance'] ?? null);
                    $reference = 'MOB-' . strtoupper(substr(hash('sha256', (string) $payload['client_operation_id']), 0, 12));
                    $notes = trim((string) ($payload['notes'] ?? ''));
                    $movementId = apply_inventory_movement(
                        $item,
                        $type,
                        (float) $measurement['base_quantity'],
                        $type === 'usage' ? $storageId : null,
                        $type === 'restock' ? $storageId : null,
                        date('Y-m-d H:i:s'),
                        $reference,
                        $notes,
                        (int) $session['user_id'],
                        'mobile_operation',
                        $ledgerId,
                        $measurement,
                        $departmentId
                    );

                    if ($type === 'usage') {
                        Database::execute(
                            'INSERT INTO inventory_movement_usage_details (movement_id, reason_code, custom_reason, notes, created_by, created_at, updated_at)
                             VALUES (:movement_id, :reason, :custom_reason, :notes, :created_by, NOW(), NOW())',
                            [
                                'movement_id' => $movementId,
                                'reason' => $reasonInput['code'],
                                'custom_reason' => $reasonInput['custom_reason'],
                                'notes' => $notes !== '' ? $notes : null,
                                'created_by' => $session['user_id'],
                            ]
                        );
                    }

                    $fileAssetId = null;
                    if (is_array($proofFile)) {
                        $storedProof = store_workflow_proof_document(
                            $proofFile,
                            'mobile-operation',
                            $reference,
                            $type === 'usage' ? 'usage' : 'refill'
                        );
                        $fileAssetId = register_inventory_operation_proof(
                            $storedProof,
                            [$movementId],
                            'mobile_' . $type . '_proof',
                            $ledgerId,
                            $reference,
                            (int) $session['user_id'],
                            $type === 'usage' ? 'usage_proof' : 'refill_proof'
                        );
                    }

                    $balance = inventory_quantity((float) Database::scalar(
                        'SELECT quantity FROM item_storage_balances WHERE item_id = :item_id AND storage_id = :storage_id',
                        ['item_id' => $item['id'], 'storage_id' => $storageId]
                    ));
                    $actor = inventory_actor_department_snapshot((int) $session['user_id'], $departmentId);

                    return [
                        '_entity_type' => 'inventory_movement',
                        '_entity_id' => $movementId,
                        'movement_id' => $movementId,
                        'reference' => $reference,
                        'item_id' => (int) $item['id'],
                        'storage_id' => $storageId,
                        'quantity' => (float) $measurement['base_quantity'],
                        'measurement' => inventory_measurement_response($measurement),
                        'department' => [
                            'id' => $actor['department_id'],
                            'name' => $actor['department_name'],
                        ],
                        'manager' => $actor['manager_user_id'] !== null ? [
                            'id' => $actor['manager_user_id'],
                            'name' => $actor['manager_name'],
                        ] : null,
                        'proof' => ['uploaded' => $fileAssetId !== null, 'file_asset_id' => $fileAssetId],
                        'storage_balance' => $balance,
                    ];
                }
            );
        } catch (Throwable $exception) {
            if (is_array($storedProof) && !empty($storedProof['stored_filename'])) {
                delete_workflow_document_file((string) $storedProof['stored_filename']);
            }
            throw $exception;
        }

        mobile_api_success($result, ['idempotent' => true], 201);
    });
}

function handle_mobile_api_usage(): void
{
    mobile_api_post_movement('usage');
}

function handle_mobile_api_restock(): void
{
    mobile_api_post_movement('restock');
}

function handle_mobile_api_batch(): void
{
    mobile_api_run(function (): void {
        $session = mobile_api_session();
        $payload = mobile_api_request_payload();
        $lines = is_array($payload['lines'] ?? null) ? array_values($payload['lines']) : [];
        if ($lines === [] || count($lines) > 100) {
            throw new MobileApiException('validation_failed', 'Batch must contain 1 to 100 lines.', 422);
        }

        $prepared = [];
        $items = [];
        $types = [];
        foreach ($lines as $index => $line) {
            if (!is_array($line)) {
                throw new MobileApiException('validation_failed', 'Every batch line must be an object.', 422);
            }
            $type = (string) ($line['type'] ?? '');
            if (!in_array($type, ['usage', 'restock'], true)) {
                throw new MobileApiException('validation_failed', 'Invalid batch line at index ' . $index . '.', 422);
            }
            $storageId = (int) ($line['storage_id'] ?? 0);
            mobile_api_require_storage($session, $storageId);
            mobile_api_require_capability($session, $type === 'usage' ? 'usage' : 'restock');
            mobile_api_require_permission($session, $type === 'usage' ? 'movements.usage' : 'movements.restock');
            $mobileAccess = mobile_api_require_employee_access($session);
            if ($type === 'restock'
                && (site_setting('mobile.manual_restock_enabled', '0') !== '1'
                    || (int) ($mobileAccess['direct_restock_enabled'] ?? 0) !== 1)) {
                throw new MobileApiException('restock_disabled', 'Direct mobile refill is disabled.', 403);
            }

            $item = mobile_api_find_item((int) ($line['item_id'] ?? 0), [$storageId]);
            $measurement = mobile_api_measurement_from_line($item, $line, 'lines.' . $index . '.');
            $reasonInput = $type === 'usage'
                ? usage_reason_input_for_storage(
                    $storageId,
                    (string) ($line['reason'] ?? ''),
                    isset($line['custom_reason']) ? (string) $line['custom_reason'] : null,
                    'lines.' . $index . '.reason'
                )
                : ['code' => '', 'custom_reason' => null];
            $departmentId = mobile_api_movement_department_override($session, $line, 'lines.' . $index . '.department_id');

            $prepared[] = [
                'index' => $index,
                'line' => $line,
                'type' => $type,
                'storage_id' => $storageId,
                'item' => $item,
                'measurement' => $measurement,
                'reason' => $reasonInput,
                'department_id' => $departmentId,
            ];
            $items[(int) $item['id']] = $item;
            $types[] = $type;
        }

        $proofFile = mobile_api_movement_proof_file();
        mobile_api_validate_movement_proof($proofFile, array_values($items), $types);
        $storedProof = null;

        try {
            $result = mobile_api_operation(
                $session,
                'movement.batch',
                $payload,
                function (int $ledgerId) use ($session, $prepared, $proofFile, &$storedProof): array {
                    $results = [];
                    $movementIds = [];
                    $reference = 'MOB-BATCH-' . $ledgerId;
                    $checkedBalances = [];

                    foreach ($prepared as $entry) {
                        $line = $entry['line'];
                        $item = $entry['item'];
                        $storageId = (int) $entry['storage_id'];
                        $balanceKey = (int) $item['id'] . ':' . $storageId;
                        if (!array_key_exists($balanceKey, $checkedBalances)) {
                            $checkedBalances[$balanceKey] = mobile_api_assert_expected_balance(
                                (int) $item['id'],
                                $storageId,
                                $line['expected_balance'] ?? null
                            );
                        }

                        $type = $entry['type'];
                        $measurement = $entry['measurement'];
                        $notes = trim((string) ($line['notes'] ?? ''));
                        $movementId = apply_inventory_movement(
                            $item,
                            $type,
                            (float) $measurement['base_quantity'],
                            $type === 'usage' ? $storageId : null,
                            $type === 'restock' ? $storageId : null,
                            date('Y-m-d H:i:s'),
                            $reference,
                            $notes,
                            (int) $session['user_id'],
                            'mobile_operation',
                            $ledgerId,
                            $measurement,
                            $entry['department_id']
                        );
                        $movementIds[] = $movementId;

                        if ($type === 'usage') {
                            Database::execute(
                                'INSERT INTO inventory_movement_usage_details (movement_id, reason_code, custom_reason, notes, created_by, created_at, updated_at)
                                 VALUES (:movement_id, :reason, :custom_reason, :notes, :user_id, NOW(), NOW())',
                                [
                                    'movement_id' => $movementId,
                                    'reason' => $entry['reason']['code'],
                                    'custom_reason' => $entry['reason']['custom_reason'],
                                    'notes' => $notes !== '' ? $notes : null,
                                    'user_id' => $session['user_id'],
                                ]
                            );
                        }

                        $storageBalance = inventory_quantity((float) Database::scalar(
                            'SELECT quantity FROM item_storage_balances WHERE item_id = :item_id AND storage_id = :storage_id',
                            ['item_id' => $item['id'], 'storage_id' => $storageId]
                        ));
                        $actor = inventory_actor_department_snapshot((int) $session['user_id'], $entry['department_id']);
                        $results[] = [
                            'movement_id' => $movementId,
                            'item_id' => (int) $item['id'],
                            'storage_id' => $storageId,
                            'type' => $type,
                            'quantity' => (float) $measurement['base_quantity'],
                            'measurement' => inventory_measurement_response($measurement),
                            'department' => ['id' => $actor['department_id'], 'name' => $actor['department_name']],
                            'manager' => $actor['manager_user_id'] !== null ? [
                                'id' => $actor['manager_user_id'],
                                'name' => $actor['manager_name'],
                            ] : null,
                            'storage_balance' => $storageBalance,
                        ];
                    }

                    $fileAssetId = null;
                    if (is_array($proofFile)) {
                        $proofType = in_array('usage', array_column($prepared, 'type'), true) ? 'usage' : 'refill';
                        $storedProof = store_workflow_proof_document($proofFile, 'mobile-operation', $reference, $proofType);
                        $fileAssetId = register_inventory_operation_proof(
                            $storedProof,
                            $movementIds,
                            'mobile_batch_proof',
                            $ledgerId,
                            $reference,
                            (int) $session['user_id'],
                            $proofType . '_proof'
                        );
                    }

                    return [
                        '_entity_type' => 'mobile_batch',
                        '_entity_id' => $ledgerId,
                        'operation_id' => $ledgerId,
                        'reference' => $reference,
                        'proof' => ['uploaded' => $fileAssetId !== null, 'file_asset_id' => $fileAssetId],
                        'lines' => $results,
                    ];
                }
            );
        } catch (Throwable $exception) {
            if (is_array($storedProof) && !empty($storedProof['stored_filename'])) {
                delete_workflow_document_file((string) $storedProof['stored_filename']);
            }
            throw $exception;
        }

        mobile_api_success($result, ['atomic' => true], 201);
    });
}
