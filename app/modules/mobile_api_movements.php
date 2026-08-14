<?php
declare(strict_types=1);

function mobile_api_post_movement(string $type): void
{
    mobile_api_run(function () use ($type): void {
        $session = mobile_api_session();
        mobile_api_require_capability($session, $type === 'usage' ? 'usage' : 'restock');
        mobile_api_require_permission($session, $type === 'usage' ? 'movements.usage' : 'movements.restock');
        $mobileAccess = mobile_api_require_employee_access($session);
        if ($type === 'restock' && (site_setting('mobile.manual_restock_enabled', '0') !== '1' || (int) ($mobileAccess['direct_restock_enabled'] ?? 0) !== 1)) {
            throw new MobileApiException('restock_disabled', 'Direct mobile restock is disabled.', 403);
        }
        $payload = mobile_api_json_input();
        $storageId = (int) ($payload['storage_id'] ?? 0);
        $itemId = (int) ($payload['item_id'] ?? 0);
        $quantity = round((float) ($payload['quantity'] ?? 0), 2);
        mobile_api_require_storage($session, $storageId);
        if ($quantity <= 0) {
            throw new MobileApiException('validation_failed', 'Quantity must be greater than zero.', 422, ['quantity' => ['Must be greater than zero.']]);
        }
        $reasonInput = $type === 'usage'
            ? mobile_usage_reason_input((string) ($payload['reason'] ?? ''), isset($payload['custom_reason']) ? (string) $payload['custom_reason'] : null)
            : ['code' => '', 'custom_reason' => null];
        $reason = $reasonInput['code'];
        $customReason = $reasonInput['custom_reason'];
        $item = mobile_api_find_item($itemId, [$storageId]);
        $result = mobile_api_operation($session, 'movement.' . $type, $payload, function (int $ledgerId) use ($session, $type, $storageId, $quantity, $reason, $customReason, $payload, $item): array {
            mobile_api_assert_expected_balance((int) $item['id'], $storageId, $payload['expected_balance'] ?? null);
            $reference = 'MOB-' . strtoupper(substr(hash('sha256', (string) $payload['client_operation_id']), 0, 12));
            apply_inventory_movement(
                $item, $type, $quantity,
                $type === 'usage' ? $storageId : null,
                $type === 'restock' ? $storageId : null,
                date('Y-m-d H:i:s'), $reference,
                trim((string) ($payload['notes'] ?? '')),
                (int) $session['user_id'], 'mobile_operation', $ledgerId
            );
            $movementId = (int) Database::scalar(
                'SELECT id FROM inventory_movements WHERE context_type = "mobile_operation" AND context_id = :context_id ORDER BY id DESC LIMIT 1',
                ['context_id' => $ledgerId]
            );
            if ($type === 'usage') {
                Database::execute(
                    'INSERT INTO inventory_movement_usage_details (movement_id, reason_code, custom_reason, notes, created_by, created_at, updated_at)
                     VALUES (:movement_id, :reason, :custom_reason, :notes, :created_by, NOW(), NOW())',
                    ['movement_id' => $movementId, 'reason' => $reason, 'custom_reason' => $customReason, 'notes' => trim((string) ($payload['notes'] ?? '')) ?: null, 'created_by' => $session['user_id']]
                );
            }
            $balance = (float) Database::scalar('SELECT quantity FROM item_storage_balances WHERE item_id = :item_id AND storage_id = :storage_id', ['item_id' => $item['id'], 'storage_id' => $storageId]);
            return ['_entity_type' => 'inventory_movement', '_entity_id' => $movementId, 'movement_id' => $movementId, 'reference' => $reference, 'item_id' => (int) $item['id'], 'storage_id' => $storageId, 'quantity' => $quantity, 'storage_balance' => $balance];
        });
        mobile_api_success($result, ['idempotent' => true], 201);
    });
}

function handle_mobile_api_usage(): void { mobile_api_post_movement('usage'); }
function handle_mobile_api_restock(): void { mobile_api_post_movement('restock'); }

function handle_mobile_api_batch(): void
{
    mobile_api_run(function (): void {
        $session = mobile_api_session();
        $payload = mobile_api_request_payload();
        $lines = is_array($payload['lines'] ?? null) ? $payload['lines'] : [];
        if ($lines === [] || count($lines) > 100) {
            throw new MobileApiException('validation_failed', 'Batch must contain 1 to 100 lines.', 422);
        }

        $containsUsage = false;
        foreach ($lines as $line) {
            if (is_array($line) && (string) ($line['type'] ?? '') === 'usage') {
                $containsUsage = true;
                break;
            }
        }

        $proofFile = $_FILES['proof_image'] ?? null;
        if (is_array($proofFile) && (int) ($proofFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $proofFile = null;
        }
        if ($proofFile !== null) {
            $proofError = validate_workflow_proof_upload($proofFile);
            if ($proofError !== null) {
                throw new MobileApiException('invalid_proof_image', $proofError, 422, ['proof_image' => [$proofError]]);
            }
        }
        if ($containsUsage && site_setting('mobile.require_usage_proof', '0') === '1' && $proofFile === null) {
            throw new MobileApiException('proof_required', 'A proof image is required for mobile usage.', 422, ['proof_image' => ['Required.']]);
        }

        $storedProof = null;
        try {
            $result = mobile_api_operation($session, 'movement.batch', $payload, function (int $ledgerId) use ($session, $lines, $proofFile, &$storedProof): array {
            $results = [];
            foreach ($lines as $index => $line) {
                if (!is_array($line)) {
                    throw new MobileApiException('validation_failed', 'Every batch line must be an object.', 422);
                }
                $type = (string) ($line['type'] ?? '');
                $storageId = (int) ($line['storage_id'] ?? 0);
                $quantity = round((float) ($line['quantity'] ?? 0), 2);
                if (!in_array($type, ['usage', 'restock'], true) || $quantity <= 0) {
                    throw new MobileApiException('validation_failed', 'Invalid batch line at index ' . $index . '.', 422);
                }
                mobile_api_require_storage($session, $storageId);
                mobile_api_require_capability($session, $type === 'usage' ? 'usage' : 'restock');
                mobile_api_require_permission($session, $type === 'usage' ? 'movements.usage' : 'movements.restock');
                $mobileAccess = mobile_api_require_employee_access($session);
                if ($type === 'restock' && (site_setting('mobile.manual_restock_enabled', '0') !== '1' || (int) ($mobileAccess['direct_restock_enabled'] ?? 0) !== 1)) {
                    throw new MobileApiException('restock_disabled', 'Direct mobile restock is disabled.', 403);
                }
                $item = mobile_api_find_item((int) ($line['item_id'] ?? 0), [$storageId]);
                mobile_api_assert_expected_balance((int) $item['id'], $storageId, $line['expected_balance'] ?? null);
                $reasonInput = $type === 'usage'
                    ? mobile_usage_reason_input(
                        (string) ($line['reason'] ?? ''),
                        isset($line['custom_reason']) ? (string) $line['custom_reason'] : null,
                        'lines.' . $index . '.reason'
                    )
                    : ['code' => '', 'custom_reason' => null];
                $reason = $reasonInput['code'];
                $customReason = $reasonInput['custom_reason'];
                $reference = 'MOB-BATCH-' . $ledgerId;
                apply_inventory_movement($item, $type, $quantity, $type === 'usage' ? $storageId : null, $type === 'restock' ? $storageId : null, date('Y-m-d H:i:s'), $reference, trim((string) ($line['notes'] ?? '')), (int) $session['user_id'], 'mobile_operation', $ledgerId);
                $movementId = (int) Database::scalar(
                    'SELECT id FROM inventory_movements WHERE context_type = "mobile_operation" AND context_id = :context_id AND item_id = :item_id ORDER BY id DESC LIMIT 1',
                    ['context_id' => $ledgerId, 'item_id' => $item['id']]
                );
                if ($type === 'usage') {
                    Database::execute(
                        'INSERT INTO inventory_movement_usage_details (movement_id, reason_code, custom_reason, notes, created_by, created_at, updated_at)
                         VALUES (:movement_id, :reason, :custom_reason, :notes, :user_id, NOW(), NOW())',
                        [
                            'movement_id' => $movementId,
                            'reason' => $reason,
                            'custom_reason' => $customReason,
                            'notes' => trim((string) ($line['notes'] ?? '')) ?: null,
                            'user_id' => $session['user_id'],
                        ]
                    );
                }
                $storageBalance = (float) Database::scalar(
                    'SELECT quantity FROM item_storage_balances WHERE item_id = :item_id AND storage_id = :storage_id',
                    ['item_id' => $item['id'], 'storage_id' => $storageId]
                );
                $results[] = [
                    'movement_id' => $movementId,
                    'item_id' => (int) $item['id'],
                    'storage_id' => $storageId,
                    'type' => $type,
                    'quantity' => $quantity,
                    'storage_balance' => $storageBalance,
                ];
            }

            if (is_array($proofFile)) {
                $reference = 'MOB-BATCH-' . $ledgerId;
                $storedProof = store_workflow_proof_document($proofFile, 'mobile-operation', $reference, 'usage');
            }

            return [
                '_entity_type' => 'mobile_batch',
                '_entity_id' => $ledgerId,
                'operation_id' => $ledgerId,
                'reference' => 'MOB-BATCH-' . $ledgerId,
                'proof_uploaded' => $storedProof !== null,
                'lines' => $results,
            ];
            });
        } catch (Throwable $exception) {
            if (is_array($storedProof) && !empty($storedProof['stored_filename'])) {
                delete_workflow_document_file((string) $storedProof['stored_filename']);
            }
            throw $exception;
        }

        if (is_array($storedProof) && !empty($storedProof['stored_filename'])) {
            try {
                $relativePath = file_asset_relative_path('storage/workflows', (string) $storedProof['stored_filename']);
                register_file_asset([
                    'source_type' => 'mobile_usage_proof',
                    'source_id' => (int) ($result['operation_id'] ?? 0),
                    'context_type' => 'mobile_operation',
                    'context_id' => (int) ($result['operation_id'] ?? 0),
                    'display_name' => (string) ($result['reference'] ?? 'Mobile usage') . ' · Usage proof',
                    'original_filename' => (string) $storedProof['original_filename'],
                    'stored_filename' => (string) $storedProof['stored_filename'],
                    'relative_path' => $relativePath,
                    'mime_type' => (string) $storedProof['mime_type'],
                    'file_size' => (int) $storedProof['file_size'],
                    'file_group' => 'workflow_proof',
                    'uploaded_by' => (int) $session['user_id'],
                ]);
            } catch (Throwable $exception) {
                error_log('[mobile-api] Usage proof was saved but could not be indexed: ' . $exception->getMessage());
            }
        }

        mobile_api_success($result, ['atomic' => true], 201);
    });
}
