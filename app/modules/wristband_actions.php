<?php
declare(strict_types=1);

function wristband_action_user_id(): int
{
    return (int) (Auth::user()['id'] ?? 0);
}

function handle_wristband_import_submit(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('wristbands.import');
    verify_csrf();
    $file = $_FILES['wristband_file'] ?? null;
    $mode = (string) input('mapping_mode', 'selected_item');
    $itemId = max(0, (int) input('selected_item_id', 0));
    $errors = [];
    if (!in_array($mode, ['selected_item', 'code_sku'], true)) {
        $errors[] = 'Choose a valid mapping mode.';
    }
    if ($mode === 'selected_item' && $itemId <= 0) {
        $errors[] = 'Choose the wristband item for this import.';
    }
    if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $errors[] = 'Choose a CSV or XLSX file.';
    }
    $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($extension, ['csv', 'xlsx'], true)) {
        $errors[] = 'Only CSV and XLSX wristband files are supported.';
    }
    if ((int) ($file['size'] ?? 0) > 20 * 1024 * 1024) {
        $errors[] = 'The import file must be 20 MB or smaller.';
    }
    if (is_array($file) && is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
        $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
        $mimeType = $finfo ? (string) finfo_file($finfo, (string) $file['tmp_name']) : '';
        if ($finfo) {
            finfo_close($finfo);
        }
        $allowedMimes = $extension === 'csv'
            ? ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel']
            : ['application/zip', 'application/x-zip-compressed', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
        if ($mimeType !== '' && !in_array($mimeType, $allowedMimes, true)) {
            $errors[] = 'The uploaded file content does not match its CSV or XLSX extension.';
        }
    }
    if ($errors !== []) {
        flash_errors($errors);
        redirect('/wristbands/imports');
    }

    try {
        $result = wristband_import_codes(
            (string) $file['tmp_name'],
            basename((string) $file['name']),
            $extension,
            $mode,
            $itemId,
            wristband_action_user_id()
        );
        record_activity('wristband.import.completed', 'wristband_import', (int) $result['import_id'], 'Imported wristband code registry.', $result);
        flash('success', sprintf('%d codes imported. %d duplicate and %d invalid rows were skipped.', $result['imported'], $result['duplicates'], $result['invalid']));
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
             VALUES (:storage_id, :name, :enabled, :ip_allowlist, :user_id, :user_id, NOW(), NOW())',
            ['storage_id' => $storageId, 'name' => $name, 'enabled' => $enabled ? 1 : 0, 'ip_allowlist' => $allowlist !== '' ? $allowlist : null, 'user_id' => $userId]
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
             last_rotated_at = NOW(), last_rotated_by = :user_id, updated_by = :user_id, updated_at = NOW()
         WHERE id = :id',
        ['api_key_hash' => $generated['hash'], 'api_key_prefix' => $generated['prefix'], 'user_id' => wristband_action_user_id(), 'id' => $id]
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
