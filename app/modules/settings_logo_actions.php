<?php
declare(strict_types=1);

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
