<?php
declare(strict_types=1);

// Domain module: asset protected document upload handler.

function handle_assets_documents_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('assets.files');
    verify_csrf();

    $asset = find_company_asset_or_abort((int) ($params['id'] ?? 0));
    $files = $_FILES['documents'] ?? null;

    if (!is_array($files) || !isset($files['name']) || !is_array($files['name'])) {
        flash('danger', 'Choose at least one file.');
        redirect('/company-assets/' . $asset['id']);
    }

    $uploaded = 0;

    foreach ($files['name'] as $index => $name) {
        $file = [
            'name' => $files['name'][$index] ?? '',
            'type' => $files['type'][$index] ?? '',
            'tmp_name' => $files['tmp_name'][$index] ?? '',
            'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
            'size' => $files['size'][$index] ?? 0,
        ];

        if ((int) $file['error'] === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        $error = validate_asset_document_upload($file);

        if ($error !== null) {
            flash('danger', $error);
            redirect('/company-assets/' . $asset['id']);
        }

        $stored = store_asset_document($file, (string) $asset['asset_number']);
        register_asset_document_asset((int) $asset['id'], (string) $asset['asset_number'], $stored, (int) (Auth::user()['id'] ?? 0));
        $uploaded++;
    }

    if ($uploaded === 0) {
        flash('danger', 'Choose at least one file.');
        redirect('/company-assets/' . $asset['id']);
    }

    asset_event_log((int) $asset['id'], 'files_uploaded', $uploaded . ' file(s) uploaded for asset ' . $asset['asset_number'] . '.', ['count' => $uploaded]);
    flash('success', $uploaded . ' asset file(s) uploaded.');
    redirect('/company-assets/' . $asset['id']);
}
