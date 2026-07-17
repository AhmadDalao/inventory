<?php
declare(strict_types=1);

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
