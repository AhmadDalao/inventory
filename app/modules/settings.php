<?php
declare(strict_types=1);

// Domain module: settings. Function names are preserved for route/view compatibility.

// Moved from controllers.php.

function normalize_site_settings_payload(array $submitted, array $clearSubmitted = [], bool $allowSecrets = true): array
{
    $payload = [];
    $errors = [];
    $skipped = [];

    foreach (site_setting_definitions() as $key => $field) {
        $value = trim((string) ($submitted[$key] ?? ''));
        $maxlength = (int) ($field['maxlength'] ?? 160);
        $options = $field['options'] ?? null;
        $type = (string) ($field['type'] ?? 'text');

        if ($type === 'secret') {
            if (!$allowSecrets) {
                $skipped[] = $key;
                continue;
            }

            $clearSecret = isset($clearSubmitted[$key]) && (string) $clearSubmitted[$key] === '1';

            if ($clearSecret) {
                $payload[$key] = '';
                continue;
            }

            if ($value === '') {
                $skipped[] = $key;
                continue;
            }
        }

        if ($maxlength > 0 && strlen($value) > $maxlength) {
            $errors[] = $field['label'] . ' must be ' . $maxlength . ' characters or less.';
        }

        if (in_array($type, ['select', 'choice'], true) && is_array($options)) {
            if ($value === '') {
                $value = (string) ($field['default'] ?? '');
            }

            if (!array_key_exists($value, $options)) {
                $errors[] = $field['label'] . ' has an invalid selection.';
                $value = (string) ($field['default'] ?? '');
            }
        }

        if ($type === 'email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $errors[] = $field['label'] . ' must be a valid email address.';
        }

        if ($type === 'number' && $value !== '' && !ctype_digit($value)) {
            $errors[] = $field['label'] . ' must be a whole number.';
        }

        if ($key === 'email.smtp_port' && $value !== '') {
            $port = (int) $value;

            if ($port < 1 || $port > 65535) {
                $errors[] = 'SMTP port must be between 1 and 65535.';
            }
        }

        if (in_array($key, ['workflow.signoff_image_custom_width', 'workflow.signoff_image_custom_height'], true) && $value !== '') {
            $size = (int) $value;

            if ($size < 40 || $size > 600) {
                $errors[] = $field['label'] . ' must be between 40 and 600 pixels.';
            }
        }

        if (in_array($key, ['exports.item_xlsx_thumbnail_custom_width', 'exports.item_xlsx_thumbnail_custom_height'], true) && $value !== '') {
            $size = (int) $value;

            if ($size < 40 || $size > 500) {
                $errors[] = $field['label'] . ' must be between 40 and 500 pixels.';
            }
        }

        if ($key === 'ocr.max_pdf_pages' && $value !== '') {
            $pageCount = (int) $value;

            if ($pageCount < 1 || $pageCount > 20) {
                $errors[] = 'Max PDF pages per file must be between 1 and 20.';
            }
        }

        if ($key === 'ocr.min_confidence' && $value !== '') {
            $confidence = (int) $value;

            if ($confidence < 1 || $confidence > 95) {
                $errors[] = 'Minimum confidence percent must be between 1 and 95.';
            }
        }

        $payload[$key] = $value;
    }

    return [$payload, $errors, $skipped];
}

function brand_logo_uploaded_file_meta(array $file): array
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_OK);

    if ($error !== UPLOAD_ERR_OK) {
        $messages = [
            UPLOAD_ERR_INI_SIZE => 'Logo file is larger than the server upload limit.',
            UPLOAD_ERR_FORM_SIZE => 'Logo file is larger than the allowed form size.',
            UPLOAD_ERR_PARTIAL => 'Logo upload was interrupted. Try again.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server upload temp directory is missing.',
            UPLOAD_ERR_CANT_WRITE => 'Server could not write the uploaded logo.',
            UPLOAD_ERR_EXTENSION => 'A server extension blocked the logo upload.',
        ];

        throw new RuntimeException($messages[$error] ?? 'Logo upload failed.');
    }

    $size = (int) ($file['size'] ?? 0);

    if ($size <= 0) {
        throw new RuntimeException('Choose a logo file to upload.');
    }

    if ($size > 4 * 1024 * 1024) {
        throw new RuntimeException('Logo file is too large. Max size is 4 MB.');
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');

    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new RuntimeException('Logo upload could not be read.');
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = $finfo ? (string) finfo_file($finfo, $tmpName) : '';

    if ($finfo) {
        finfo_close($finfo);
    }

    $extensions = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    if (!isset($extensions[$mimeType])) {
        throw new RuntimeException('Logo must be a PNG, JPG, or WebP image.');
    }

    if (@getimagesize($tmpName) === false) {
        throw new RuntimeException('Logo image is invalid.');
    }

    return [
        'mime_type' => $mimeType,
        'extension' => $extensions[$mimeType],
    ];
}

function delete_brand_custom_logo_asset(?string $asset): void
{
    $asset = $asset === null ? '' : ltrim(str_replace('\\', '/', $asset), '/');

    if ($asset === '' || !starts_with($asset, 'brand/uploads/')) {
        return;
    }

    $path = base_path('assets/' . $asset);

    if (is_file($path)) {
        @unlink($path);
    }
}

function save_brand_logo_setting(string $key, ?string $value, ?int $userId): void
{
    if ($value === null || trim($value) === '') {
        Database::execute('DELETE FROM app_settings WHERE setting_key = :setting_key', [
            'setting_key' => $key,
        ]);
        return;
    }

    Database::execute(
        'INSERT INTO app_settings (setting_key, setting_value, updated_by, updated_at)
         VALUES (:setting_key, :setting_value, :updated_by, NOW())
         ON DUPLICATE KEY UPDATE
            setting_value = VALUES(setting_value),
            updated_by = VALUES(updated_by),
            updated_at = VALUES(updated_at)',
        [
            'setting_key' => $key,
            'setting_value' => trim($value),
            'updated_by' => $userId,
        ]
    );
}

function store_brand_logo_upload(array $file): array
{
    $meta = brand_logo_uploaded_file_meta($file);
    ensure_directory_exists(brand_logo_upload_directory());

    $originalName = basename((string) ($file['name'] ?? 'logo.' . $meta['extension']));
    $baseName = pathinfo($originalName, PATHINFO_FILENAME);
    $filename = date('YmdHis') . '-' . slugify_filename($baseName !== '' ? $baseName : 'logo') . '-' . substr(bin2hex(random_bytes(5)), 0, 10) . '.' . $meta['extension'];
    $destination = brand_logo_upload_directory() . '/' . $filename;

    if (!move_uploaded_file((string) ($file['tmp_name'] ?? ''), $destination)) {
        throw new RuntimeException('Could not save the logo file.');
    }

    return [
        'asset' => 'brand/uploads/' . $filename,
        'original_name' => $originalName !== '' ? $originalName : 'logo.' . $meta['extension'],
    ];
}

function handle_site_logo_submit(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('settings.edit');
    verify_csrf();

    $user = Auth::user();
    $userId = isset($user['id']) ? (int) $user['id'] : null;
    $oldAsset = brand_custom_logo_asset();
    $clearLogo = input('clear_brand_logo', '') === '1';
    $file = uploaded_file('brand_logo');

    if ($file === null && !$clearLogo) {
        flash('danger', 'Choose a logo file or use Clear custom logo.');
        redirect('/settings/site');
    }

    try {
        if ($file !== null) {
            $stored = store_brand_logo_upload($file);
            save_brand_logo_setting('brand.logo_path', $stored['asset'], $userId);
            save_brand_logo_setting('brand.logo_name', $stored['original_name'], $userId);

            if ($oldAsset !== null && $oldAsset !== $stored['asset']) {
                delete_brand_custom_logo_asset($oldAsset);
            }

            site_settings_cache_reset();
            if (function_exists('record_activity')) {
                record_activity('settings.logo_updated', 'settings', null, 'Updated website logo', [
                    'file' => $stored['original_name'],
                ]);
            }
            flash('success', 'Website logo updated.');
            redirect('/settings/site');
        }

        save_brand_logo_setting('brand.logo_path', null, $userId);
        save_brand_logo_setting('brand.logo_name', null, $userId);
        delete_brand_custom_logo_asset($oldAsset);
        site_settings_cache_reset();

        if (function_exists('record_activity')) {
            record_activity('settings.logo_cleared', 'settings', null, 'Cleared custom website logo');
        }

        flash('success', 'Custom logo cleared. The official KONA logo is active again.');
    } catch (Throwable $exception) {
        flash('danger', 'Could not update logo. ' . $exception->getMessage());
    }

    redirect('/settings/site');
}

function handle_site_settings_page(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('settings.view');

    $values = old('site_settings', site_settings());

    View::render('settings/site', [
        'title' => site_setting('page.settings', 'Website Control'),
        'settingGroups' => site_setting_groups(is_array($values) ? $values : [], Auth::hasPermission('settings.secrets')),
        'canManageSecretSettings' => Auth::hasPermission('settings.secrets'),
        'ocrHealth' => function_exists('purchase_ocr_health') ? purchase_ocr_health() : null,
    ]);
}

function handle_site_settings_submit(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('settings.edit');
    verify_csrf();

    $submitted = input('settings', []);
    $clearSubmitted = input('clear_settings', []);

    if (!is_array($submitted)) {
        $submitted = [];
    }

    if (!is_array($clearSubmitted)) {
        $clearSubmitted = [];
    }

    [$payload, $errors, $skipped] = normalize_site_settings_payload($submitted, $clearSubmitted, Auth::hasPermission('settings.secrets'));

    flash_old_input(['site_settings' => $payload]);

    if ($errors !== []) {
        flash_errors($errors);
        redirect('/settings/site');
    }

    $defaults = site_setting_defaults();
    $user = Auth::user();
    $pdo = Database::connection();

    try {
        $pdo->beginTransaction();

        foreach ($payload as $key => $value) {
            $default = (string) ($defaults[$key] ?? '');

            if ($value === '' || $value === $default) {
                Database::execute('DELETE FROM app_settings WHERE setting_key = :setting_key', [
                    'setting_key' => $key,
                ]);
                continue;
            }

            Database::execute(
                'INSERT INTO app_settings (setting_key, setting_value, updated_by, updated_at)
                 VALUES (:setting_key, :setting_value, :updated_by, NOW())
                 ON DUPLICATE KEY UPDATE
                    setting_value = VALUES(setting_value),
                    updated_by = VALUES(updated_by),
                    updated_at = VALUES(updated_at)',
                [
                    'setting_key' => $key,
                    'setting_value' => $value,
                    'updated_by' => $user['id'] ?? null,
                ]
            );
        }

        if ($pdo->inTransaction()) {
            $pdo->commit();
        }

        site_settings_cache_reset();
        consume_old_input();
        if (function_exists('record_activity')) {
            record_activity('settings.updated', 'settings', null, 'Updated website control settings', [
                'changed_keys' => array_keys($payload),
                'skipped_secret_keys' => $skipped,
            ]);
        }
        flash('success', 'Website control updated.');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', 'Could not save website control. ' . $exception->getMessage());
    }

    redirect('/settings/site');
}

function handle_site_email_test_submit(): void
{
    app_ready_or_redirect();
    Auth::requireOwner();
    verify_csrf();

    $user = Auth::user();
    $recipientEmail = strtolower(trim((string) input('test_email', (string) ($user['email'] ?? ''))));

    if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        flash('danger', 'Use a valid email address for the test.');
        redirect('/settings/site');
    }

    $result = send_inventory_email(
        $recipientEmail,
        (string) ($user['name'] ?? ''),
        'Inventory KONA test email',
        "This is a test email from Inventory KONA.\n\nIf this reached your inbox, PHP mail is working on the server.",
        'test_email',
        $user ? (int) $user['id'] : null,
        'settings',
        null,
        true
    );

    if (function_exists('record_activity')) {
        record_activity('settings.email_test', 'settings', null, 'Sent email delivery test to ' . $recipientEmail, [
            'status' => $result['status'] ?? 'unknown',
            'error' => $result['error'] ?? null,
        ]);
    }

    if (($result['status'] ?? '') === 'sent') {
        flash('success', 'Test email sent. Check the inbox.');
    } elseif (($result['status'] ?? '') === 'suppressed') {
        flash('warning', 'Test email logged but not sent: ' . ($result['error'] ?? 'suppressed'));
    } else {
        flash('danger', 'Test email failed: ' . ($result['error'] ?? 'unknown error'));
    }

    redirect('/settings/site');
}
