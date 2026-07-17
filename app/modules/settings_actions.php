<?php
declare(strict_types=1);

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
