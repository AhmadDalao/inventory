<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$requireDatabase = in_array('--require-db', $argv ?? [], true);
$temporaryFile = null;
$pdo = null;

function fail_wristband_workflow(string $message): never
{
    fwrite(STDERR, '[wristband-workflow] FAIL: ' . $message . PHP_EOL);
    exit(1);
}

function assert_wristband_workflow(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    require $root . '/app/bootstrap.php';
    require $root . '/app/modules.php';
    $pdo = Database::connection();
} catch (Throwable $exception) {
    if ($requireDatabase) {
        fail_wristband_workflow('Database bootstrap failed: ' . $exception->getMessage());
    }

    echo '[wristband-workflow] SKIP: database unavailable' . PHP_EOL;
    exit(0);
}

try {
    $owner = Database::fetch(
        'SELECT id, name FROM users WHERE role = "owner" AND is_active = 1 ORDER BY id ASC LIMIT 1'
    );
    $item = Database::fetch(
        'SELECT id, name, sku, unit FROM items WHERE is_active = 1 ORDER BY id ASC LIMIT 1'
    );

    assert_wristband_workflow($owner !== null, 'An active owner account is required.');
    assert_wristband_workflow($item !== null, 'An active item is required.');

    $ownerId = (int) $owner['id'];
    $itemId = (int) $item['id'];
    $suffix = strtoupper(bin2hex(random_bytes(4)));
    $codeOne = 'WBT' . $suffix . 'A1';
    $codeTwo = 'WBT' . $suffix . 'B2';

    $pdo->beginTransaction();

    $movementCountBefore = (int) Database::scalar('SELECT COUNT(*) FROM inventory_movements');
    $balanceTotalBefore = (float) (Database::scalar('SELECT COALESCE(SUM(quantity), 0) FROM item_storage_balances') ?? 0);

    Database::execute(
        'UPDATE items
         SET external_qr_tracking_enabled = 1, measurement_dimension = "count", updated_at = NOW()
         WHERE id = :id',
        ['id' => $itemId]
    );

    Database::execute(
        'INSERT INTO storages
            (name, system_key, storage_type, notes, is_system, is_active, owner_user_id,
             created_by, updated_by, created_at, updated_at)
         VALUES
            (:name, NULL, "storage", :notes, 0, 1, :owner_user_id,
             :created_by, :updated_by, NOW(), NOW())',
        [
            'name' => 'WBT Storage ' . $suffix,
            'notes' => 'Rollback-only wristband workflow test.',
            'owner_user_id' => $ownerId,
            'created_by' => $ownerId,
            'updated_by' => $ownerId,
        ]
    );
    $storageId = Database::lastInsertId();

    Database::execute(
        'INSERT INTO item_storage_balances (item_id, storage_id, quantity, created_at, updated_at)
         VALUES (:item_id, :storage_id, 0, NOW(), NOW())',
        ['item_id' => $itemId, 'storage_id' => $storageId]
    );

    $apiKey = wristband_generate_api_key();
    Database::execute(
        'INSERT INTO wristband_integrations
            (storage_id, name, enabled, api_key_hash, api_key_prefix, last_rotated_at,
             last_rotated_by, created_by, updated_by, created_at, updated_at)
         VALUES
            (:storage_id, :name, 1, :api_key_hash, :api_key_prefix, NOW(),
             :last_rotated_by, :created_by, :updated_by, NOW(), NOW())',
        [
            'storage_id' => $storageId,
            'name' => 'WBT Integration ' . $suffix,
            'api_key_hash' => (string) $apiKey['hash'],
            'api_key_prefix' => (string) $apiKey['prefix'],
            'last_rotated_by' => $ownerId,
            'created_by' => $ownerId,
            'updated_by' => $ownerId,
        ]
    );
    $integrationId = Database::lastInsertId();

    wristband_set_api_enabled(true, $ownerId);

    Database::execute(
        'INSERT INTO handovers
            (handover_number, source_storage_id, approver_user_id, recipient_name, recipient_user_id,
             recipient_type, handover_purpose, issue_condition, usage_reporting_mode,
             wristband_tracking_mode, handover_mode, status, issued_at, created_by, updated_by,
             created_at, updated_at)
         VALUES
            (:handover_number, :source_storage_id, :approver_user_id, :recipient_name, :recipient_user_id,
             "staff", "temporary_use", "good", "operational_summary",
             "api_audit", "direct", "delivered", NOW(), :created_by, :updated_by,
             NOW(), NOW())',
        [
            'handover_number' => 'WBT-HDO-' . $suffix,
            'source_storage_id' => $storageId,
            'approver_user_id' => $ownerId,
            'recipient_name' => (string) $owner['name'],
            'recipient_user_id' => $ownerId,
            'created_by' => $ownerId,
            'updated_by' => $ownerId,
        ]
    );
    $handoverId = Database::lastInsertId();

    Database::execute(
        'INSERT INTO handover_lines
            (handover_id, item_id, item_name, item_sku, unit, quantity_handed, quantity_received,
             quantity_used, quantity_returned, created_at, updated_at)
         VALUES
            (:handover_id, :item_id, :item_name, :item_sku, :unit, 2, 2, 0, 0, NOW(), NOW())',
        [
            'handover_id' => $handoverId,
            'item_id' => $itemId,
            'item_name' => (string) $item['name'],
            'item_sku' => (string) $item['sku'],
            'unit' => (string) $item['unit'],
        ]
    );

    $temporaryFile = tempnam(sys_get_temp_dir(), 'wristband-workflow-');
    assert_wristband_workflow(is_string($temporaryFile), 'Could not create the temporary import file.');
    file_put_contents($temporaryFile, "code\n{$codeOne}\n{$codeTwo}\n");
    $import = wristband_import_codes(
        $temporaryFile,
        'wristband-workflow.csv',
        'csv',
        'selected_item',
        $itemId,
        $ownerId,
        $storageId
    );
    assert_wristband_workflow((int) $import['imported'] === 2, 'Two unique wristband codes should import.');

    $sessionId = wristband_start_session_for_handover($handoverId, $storageId, $ownerId);
    $session = Database::fetch('SELECT * FROM wristband_sessions WHERE id = :id', ['id' => $sessionId]);
    assert_wristband_workflow($session !== null && (string) $session['status'] === 'active', 'API Audit session should start active.');

    wristband_pause_session($sessionId, $ownerId, 'Workflow test pause.');
    assert_wristband_workflow(
        (string) Database::scalar('SELECT status FROM wristband_sessions WHERE id = :id', ['id' => $sessionId]) === 'paused',
        'Session should pause.'
    );

    $registryOne = Database::fetch('SELECT * FROM wristband_codes WHERE code_hash = :hash', ['hash' => wristband_code_hash($codeOne)]);
    assert_wristband_workflow($registryOne !== null, 'First imported code should exist.');
    $eventOneId = wristband_api_insert_event([
        'integration_id' => $integrationId,
        'session_id' => $sessionId,
        'code_id' => (int) $registryOne['id'],
        'item_id' => $itemId,
        'handover_id' => $handoverId,
        'external_event_id' => 'WBT-EVENT-' . $suffix . '-1',
        'payload_hash' => hash('sha256', 'WBT-PAYLOAD-' . $suffix . '-1'),
        'code_hash' => wristband_code_hash($codeOne),
        'code_masked' => wristband_mask_code($codeOne),
        'scanned_at' => date('Y-m-d H:i:s'),
        'request_ip' => '127.0.0.1',
        'status' => 'paused',
        'resolution_reason' => 'Integration paused.',
        'raw_payload' => ['code' => $codeOne],
    ]);
    assert_wristband_workflow(
        (string) Database::scalar('SELECT state FROM wristband_codes WHERE id = :id', ['id' => (int) $registryOne['id']]) === 'available',
        'Paused evidence must not consume a code.'
    );

    wristband_resume_session($sessionId, $ownerId);
    wristband_accept_paused_event($eventOneId, $ownerId);
    assert_wristband_workflow(
        (string) Database::scalar('SELECT status FROM wristband_events WHERE id = :id', ['id' => $eventOneId]) === 'accepted',
        'Selected paused evidence should become accepted.'
    );
    assert_wristband_workflow(
        (string) Database::scalar('SELECT state FROM wristband_codes WHERE id = :id', ['id' => (int) $registryOne['id']]) === 'used',
        'Accepted evidence should consume its registry code.'
    );

    wristband_pause_session($sessionId, $ownerId, 'Second workflow test pause.');
    $registryTwo = Database::fetch('SELECT * FROM wristband_codes WHERE code_hash = :hash', ['hash' => wristband_code_hash($codeTwo)]);
    assert_wristband_workflow($registryTwo !== null, 'Second imported code should exist.');
    $eventTwoId = wristband_api_insert_event([
        'integration_id' => $integrationId,
        'session_id' => $sessionId,
        'code_id' => (int) $registryTwo['id'],
        'item_id' => $itemId,
        'handover_id' => $handoverId,
        'external_event_id' => 'WBT-EVENT-' . $suffix . '-2',
        'payload_hash' => hash('sha256', 'WBT-PAYLOAD-' . $suffix . '-2'),
        'code_hash' => wristband_code_hash($codeTwo),
        'code_masked' => wristband_mask_code($codeTwo),
        'scanned_at' => date('Y-m-d H:i:s'),
        'request_ip' => '127.0.0.1',
        'status' => 'paused',
        'resolution_reason' => 'Integration paused.',
        'raw_payload' => ['code' => $codeTwo],
    ]);
    wristband_resume_session($sessionId, $ownerId);
    wristband_discard_event($eventTwoId, $ownerId, 'Invalid test evidence.');
    assert_wristband_workflow(
        (string) Database::scalar('SELECT status FROM wristband_events WHERE id = :id', ['id' => $eventTwoId]) === 'discarded',
        'Rejected paused evidence should become discarded.'
    );
    assert_wristband_workflow(
        (string) Database::scalar('SELECT state FROM wristband_codes WHERE id = :id', ['id' => (int) $registryTwo['id']]) === 'available',
        'Discarded evidence must leave its code available.'
    );

    wristband_reverse_event($eventOneId, $ownerId, 'Rollback-only workflow test reversal.');
    assert_wristband_workflow(
        (string) Database::scalar('SELECT state FROM wristband_codes WHERE id = :id', ['id' => (int) $registryOne['id']]) === 'available',
        'Reversal should restore the accepted code to available.'
    );

    wristband_switch_session_to_manual($sessionId, $ownerId, 'Workflow test manual fallback.');
    $finalSession = Database::fetch('SELECT mode, status FROM wristband_sessions WHERE id = :id', ['id' => $sessionId]);
    assert_wristband_workflow(
        $finalSession !== null && (string) $finalSession['mode'] === 'manual_only' && (string) $finalSession['status'] === 'manual_only',
        'Session should switch permanently to Manual Only.'
    );
    assert_wristband_workflow(
        (string) Database::scalar('SELECT wristband_tracking_mode FROM handovers WHERE id = :id', ['id' => $handoverId]) === 'manual_only',
        'Manual fallback should update only the handover tracking mode.'
    );

    $movementCountAfter = (int) Database::scalar('SELECT COUNT(*) FROM inventory_movements');
    $balanceTotalAfter = (float) (Database::scalar('SELECT COALESCE(SUM(quantity), 0) FROM item_storage_balances') ?? 0);
    assert_wristband_workflow($movementCountBefore === $movementCountAfter, 'API evidence must not create inventory movements.');
    assert_wristband_workflow(abs($balanceTotalBefore - $balanceTotalAfter) < 0.0001, 'API evidence must not change storage balances.');

    echo '[wristband-workflow] PASS' . PHP_EOL;
} catch (Throwable $exception) {
    fail_wristband_workflow($exception->getMessage());
} finally {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (is_string($temporaryFile) && is_file($temporaryFile)) {
        unlink($temporaryFile);
    }
    if (function_exists('site_settings_cache_reset')) {
        site_settings_cache_reset();
    }
}
