<?php
declare(strict_types=1);

function wristband_action_user_id(): int
{
    return (int) (Auth::user()['id'] ?? 0);
}

function wristband_import_request_context(): array
{
    $mappingMode = strtolower(trim((string) input('mapping_mode', 'selected_item')));
    $storageId = max(0, (int) input('storage_id', 0));
    $selectedItemId = max(0, (int) input('selected_item_id', 0));
    $enableTracking = (string) input('enable_external_qr_tracking', '0') === '1';
    $userId = wristband_action_user_id();

    if (!in_array($mappingMode, ['selected_item', 'code_sku'], true)) {
        throw new RuntimeException('Choose a valid wristband mapping mode.');
    }
    if ($storageId <= 0 || !in_array($storageId, user_visible_storage_ids($userId), true)) {
        throw new RuntimeException('Choose a storage you are allowed to access.');
    }
    if ($mappingMode === 'selected_item' && $selectedItemId <= 0) {
        throw new RuntimeException('Choose the wristband item for this import.');
    }
    if ($enableTracking && (!Auth::hasPermission('items.edit') || !Auth::hasPermission('wristbands.import'))) {
        throw new RuntimeException('You do not have permission to enable external QR tracking.');
    }

    return [
        'mapping_mode' => $mappingMode,
        'storage_id' => $storageId,
        'selected_item_id' => $selectedItemId,
        'enable_tracking' => $enableTracking,
        'user_id' => $userId,
    ];
}

function handle_wristband_import_items(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('wristbands.view');
    $storageId = max(0, (int) ($_GET['storage_id'] ?? 0));
    if ($storageId <= 0 || !in_array($storageId, user_visible_storage_ids(wristband_action_user_id()), true)) {
        json_response(['ok' => false, 'message' => 'Choose a storage you are allowed to access.'], 403);
    }

    $items = array_map(static function (array $item): array {
        return [
            'id' => (int) $item['id'],
            'name' => (string) $item['name'],
            'sku' => (string) $item['sku'],
            'unit' => (string) $item['unit'],
            'image_url' => item_image_url((string) ($item['image_path'] ?? '')),
            'storage_quantity' => (float) $item['storage_quantity'],
            'registered_codes' => (int) $item['registered_codes'],
            'available_codes' => (int) $item['available_codes'],
            'tracking_enabled' => (int) $item['external_qr_tracking_enabled'] === 1,
        ];
    }, wristband_import_candidate_items($storageId));

    json_response(['ok' => true, 'items' => $items]);
}

function handle_wristband_import_preflight_submit(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('wristbands.import');
    verify_csrf();

    try {
        $context = wristband_import_request_context();
        $file = wristband_import_uploaded_file();
        $preflight = wristband_import_preflight(
            (string) $file['path'],
            (string) $file['extension'],
            (string) $context['mapping_mode'],
            (int) $context['selected_item_id'],
            (int) $context['storage_id'],
            (bool) $context['enable_tracking']
        );
        json_response([
            'ok' => true,
            'clean' => !$preflight['has_issues'] && (int) $preflight['stats']['valid'] > 0,
            'stats' => $preflight['stats'],
            'preview' => $preflight['preview'],
            'message' => $preflight['has_issues']
                ? 'Fix every duplicate, invalid code, unknown SKU, or item conflict before importing.'
                : sprintf('%d unique codes are ready to import.', (int) $preflight['stats']['valid']),
        ]);
    } catch (Throwable $exception) {
        json_response(['ok' => false, 'message' => $exception->getMessage()], 422);
    }
}

function wristband_import_sample_mode(): string
{
    $mappingMode = strtolower(trim((string) ($_GET['mapping_mode'] ?? 'selected_item')));

    return in_array($mappingMode, ['selected_item', 'code_sku'], true) ? $mappingMode : 'selected_item';
}

function handle_wristband_import_sample_csv(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('wristbands.view');
    $mappingMode = wristband_import_sample_mode();
    $stream = fopen('php://temp', 'w+b');
    if ($stream === false) {
        throw new RuntimeException('Could not create the CSV example.');
    }
    fwrite($stream, "\xEF\xBB\xBF");
    foreach (wristband_import_sample_rows($mappingMode) as $row) {
        fputcsv($stream, $row);
    }
    rewind($stream);
    $bytes = stream_get_contents($stream);
    fclose($stream);
    if (!is_string($bytes)) {
        throw new RuntimeException('Could not create the CSV example.');
    }
    send_download_headers('text/csv; charset=utf-8', 'wristband-import-' . str_replace('_', '-', $mappingMode) . '-example.csv', strlen($bytes));
    echo $bytes;
    exit;
}

function handle_wristband_import_sample_xlsx(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('wristbands.view');
    $mappingMode = wristband_import_sample_mode();
    $bytes = wristband_import_sample_xlsx($mappingMode);
    send_download_headers(
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'wristband-import-' . str_replace('_', '-', $mappingMode) . '-example.xlsx',
        strlen($bytes)
    );
    echo $bytes;
    exit;
}

function handle_wristband_import_submit(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('wristbands.import');
    verify_csrf();

    try {
        $context = wristband_import_request_context();
        $file = wristband_import_uploaded_file();
        $preflight = wristband_import_preflight(
            (string) $file['path'],
            (string) $file['extension'],
            (string) $context['mapping_mode'],
            (int) $context['selected_item_id'],
            (int) $context['storage_id'],
            (bool) $context['enable_tracking']
        );
        $result = wristband_import_codes(
            (string) $file['path'],
            (string) $file['name'],
            (string) $file['extension'],
            (string) $context['mapping_mode'],
            (int) $context['selected_item_id'],
            (int) $context['user_id'],
            (int) $context['storage_id'],
            (bool) $context['enable_tracking'],
            $preflight,
            true
        );
        record_activity('wristband.import.completed', 'wristband_import', (int) $result['import_id'], 'Imported wristband code registry.', $result);
        flash('success', sprintf('%d unique wristband codes imported. No rows were skipped.', $result['imported']));
    } catch (Throwable $exception) {
        flash('danger', $exception->getMessage());
    }
    redirect('/wristbands/imports');
}

function handle_wristband_code_state_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('wristbands.manage');
    verify_csrf();
    $id = max(0, (int) ($params['id'] ?? 0));
    $state = (string) input('state');
    $reason = trim((string) input('reason'));
    $code = Database::fetch('SELECT * FROM wristband_codes WHERE id = :id LIMIT 1', ['id' => $id]);
    if ($code === null || (string) $code['state'] === 'used') {
        flash('danger', 'Used wristband codes are permanent and cannot be changed here.');
        redirect('/wristbands');
    }
    if (!in_array($state, ['available', 'void'], true) || ($state === 'void' && $reason === '')) {
        flash('danger', 'Voiding a code requires a reason.');
        redirect('/wristbands');
    }
    Database::execute(
        'UPDATE wristband_codes
         SET state = :state, void_reason = :void_reason, void_by = :void_by, void_at = :void_at, updated_at = NOW()
         WHERE id = :id',
        [
            'state' => $state,
            'void_reason' => $state === 'void' ? $reason : null,
            'void_by' => $state === 'void' ? wristband_action_user_id() : null,
            'void_at' => $state === 'void' ? date('Y-m-d H:i:s') : null,
            'id' => $id,
        ]
    );
    record_activity('wristband.code.' . $state, 'wristband_code', $id, 'Changed wristband code state.', ['state' => $state, 'reason' => $reason]);
    flash('success', 'Wristband code updated.');
    redirect('/wristbands');
}

function handle_wristband_global_toggle_submit(): void
{
    app_ready_or_redirect();
    Auth::requireOwner();
    verify_csrf();
    $enabled = (string) input('enabled', '0') === '1';
    wristband_set_api_enabled($enabled, wristband_action_user_id());
    flash('success', $enabled ? 'Wristband API checking enabled globally.' : 'Wristband API checking stopped globally. Manual reconciliation remains available.');
    redirect('/wristbands/integrations');
}

function handle_wristband_integration_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('wristbands.integrations');
    verify_csrf();
    $storageId = max(0, (int) ($params['storage_id'] ?? 0));
    $userId = wristband_action_user_id();
    if (!user_is_global_owner($userId) && !storage_is_owned_by_user($storageId, $userId)) {
        flash('danger', 'You cannot control this storage integration.');
        redirect('/wristbands/integrations');
    }
    $enabled = (string) input('enabled', '0') === '1';
    $name = trim((string) input('name')) ?: 'KONA wristband check-in';
    $allowlist = trim((string) input('ip_allowlist'));
    $existing = wristband_integration_for_storage($storageId);
    if ($existing === null) {
        Database::execute(
            'INSERT INTO wristband_integrations
             (storage_id, name, enabled, ip_allowlist, created_by, updated_by, created_at, updated_at)
             VALUES (:storage_id, :name, :enabled, :ip_allowlist, :created_by, :updated_by, NOW(), NOW())',
            [
                'storage_id' => $storageId,
                'name' => $name,
                'enabled' => $enabled ? 1 : 0,
                'ip_allowlist' => $allowlist !== '' ? $allowlist : null,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]
        );
        $integrationId = Database::lastInsertId();
    } else {
        $integrationId = (int) $existing['id'];
        Database::execute(
            'UPDATE wristband_integrations SET name = :name, enabled = :enabled, ip_allowlist = :ip_allowlist, updated_by = :user_id, updated_at = NOW() WHERE id = :id',
            ['name' => $name, 'enabled' => $enabled ? 1 : 0, 'ip_allowlist' => $allowlist !== '' ? $allowlist : null, 'user_id' => $userId, 'id' => $integrationId]
        );
    }
    record_activity($enabled ? 'wristband.integration.enabled' : 'wristband.integration.disabled', 'wristband_integration', $integrationId, 'Updated storage wristband integration.', ['storage_id' => $storageId]);
    flash('success', $enabled ? 'Storage API checking enabled.' : 'Storage API checking disabled. Active handovers can continue manually.');
    redirect('/wristbands/integrations');
}

function handle_wristband_rotate_key_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requireOwner();
    verify_csrf();
    $id = max(0, (int) ($params['id'] ?? 0));
    $integration = Database::fetch('SELECT * FROM wristband_integrations WHERE id = :id LIMIT 1', ['id' => $id]);
    if ($integration === null) {
        flash('danger', 'Integration not found.');
        redirect('/wristbands/integrations');
    }
    $generated = wristband_generate_api_key();
    Database::execute(
        'UPDATE wristband_integrations
         SET api_key_hash = :api_key_hash, api_key_prefix = :api_key_prefix,
             last_rotated_at = NOW(), last_rotated_by = :last_rotated_by, updated_by = :updated_by, updated_at = NOW()
         WHERE id = :id',
        [
            'api_key_hash' => $generated['hash'],
            'api_key_prefix' => $generated['prefix'],
            'last_rotated_by' => wristband_action_user_id(),
            'updated_by' => wristband_action_user_id(),
            'id' => $id,
        ]
    );
    $_SESSION['_wristband_api_key'] = (string) $generated['plain'];
    $_SESSION['_wristband_api_key_integration_id'] = $id;
    record_activity('wristband.integration.key_rotated', 'wristband_integration', $id, 'Rotated wristband integration API key.');
    flash('success', 'API key rotated. Copy it now; it will not be shown again.');
    redirect('/wristbands/integrations');
}

function handle_wristband_session_pause_submit(array $params): void
{
    wristband_session_action($params, 'pause');
}

function handle_wristband_session_start_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('wristbands.sessions');
    verify_csrf();
    $handoverId = max(0, (int) ($params['id'] ?? 0));
    $handover = Database::fetch(
        'SELECT id, source_storage_id FROM handovers WHERE id = :id LIMIT 1',
        ['id' => $handoverId]
    );
    if ($handover === null) {
        flash('danger', 'Handover not found.');
        redirect('/handovers');
    }
    $userId = wristband_action_user_id();
    if (!user_is_global_owner($userId)
        && !storage_is_owned_by_user((int) $handover['source_storage_id'], $userId)) {
        flash('danger', 'You cannot start API Audit for this storage.');
        redirect('/handovers/' . $handoverId);
    }
    try {
        wristband_start_session_for_handover($handoverId, (int) $handover['source_storage_id'], $userId);
        flash('success', 'Wristband API Audit started. Stock is still controlled by normal handover approval.');
    } catch (Throwable $exception) {
        flash('danger', $exception->getMessage());
    }
    redirect('/handovers/' . $handoverId);
}

function handle_wristband_session_resume_submit(array $params): void
{
    wristband_session_action($params, 'resume');
}

function handle_wristband_session_manual_submit(array $params): void
{
    wristband_session_action($params, 'manual');
}

function wristband_session_action(array $params, string $action): void
{
    app_ready_or_redirect();
    Auth::requirePermission('wristbands.sessions');
    verify_csrf();
    $id = max(0, (int) ($params['id'] ?? 0));
    try {
        if ($action === 'pause') {
            wristband_pause_session($id, wristband_action_user_id(), trim((string) input('reason')));
        } elseif ($action === 'resume') {
            wristband_resume_session($id, wristband_action_user_id());
        } else {
            wristband_switch_session_to_manual($id, wristband_action_user_id(), trim((string) input('reason')));
        }
        flash('success', $action === 'manual' ? 'Session switched permanently to Manual Only.' : 'Wristband session updated.');
    } catch (Throwable $exception) {
        flash('danger', $exception->getMessage());
    }
    $returnTo = trim((string) input('return_to', '/wristbands/sessions'));
    if ($returnTo !== '/wristbands/sessions' && preg_match('#^/handovers/[1-9][0-9]*$#', $returnTo) !== 1) {
        $returnTo = '/wristbands/sessions';
    }
    redirect($returnTo);
}

function handle_wristband_event_accept_submit(array $params): void
{
    wristband_event_action($params, 'accept');
}

function handle_wristband_event_discard_submit(array $params): void
{
    wristband_event_action($params, 'discard');
}

function handle_wristband_event_reverse_submit(array $params): void
{
    wristband_event_action($params, 'reverse');
}

function wristband_event_action(array $params, string $action): void
{
    app_ready_or_redirect();
    Auth::requirePermission($action === 'reverse' ? 'wristbands.reverse' : 'wristbands.exceptions');
    verify_csrf();
    $id = max(0, (int) ($params['id'] ?? 0));
    $reason = trim((string) input('reason'));
    try {
        if ($action === 'accept') {
            wristband_accept_paused_event($id, wristband_action_user_id());
        } elseif ($action === 'discard') {
            wristband_discard_event($id, wristband_action_user_id(), $reason);
        } else {
            wristband_reverse_event($id, wristband_action_user_id(), $reason);
        }
        flash('success', 'Wristband event updated.');
    } catch (Throwable $exception) {
        flash('danger', $exception->getMessage());
    }
    redirect('/wristbands/exceptions');
}
