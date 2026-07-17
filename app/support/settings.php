<?php
declare(strict_types=1);

require_once __DIR__ . '/settings_schema.php';

function site_setting_definitions(): array
{
    static $definitions;

    if ($definitions !== null) {
        return $definitions;
    }

    $definitions = [];

    foreach (site_setting_schema() as $group) {
        foreach ($group['fields'] as $key => $field) {
            $definitions[$key] = $field + [
                'key' => $key,
                'default' => '',
                'maxlength' => 160,
            ];
        }
    }

    return $definitions;
}

function site_setting_defaults(): array
{
    static $defaults;

    if ($defaults !== null) {
        return $defaults;
    }

    $defaults = [];

    foreach (site_setting_definitions() as $key => $field) {
        $defaults[$key] = (string) ($field['default'] ?? '');
    }

    return $defaults;
}

function site_settings_table_exists(): bool
{
    if (array_key_exists('_site_settings_table_exists', $GLOBALS)) {
        return (bool) $GLOBALS['_site_settings_table_exists'];
    }

    try {
        $GLOBALS['_site_settings_table_exists'] = (int) Database::scalar(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name',
            ['table_name' => 'app_settings']
        ) > 0;
    } catch (Throwable $exception) {
        $GLOBALS['_site_settings_table_exists'] = false;
    }

    return (bool) $GLOBALS['_site_settings_table_exists'];
}

function site_setting_stored_values(): array
{
    if (isset($GLOBALS['_site_settings_stored_values']) && is_array($GLOBALS['_site_settings_stored_values'])) {
        return $GLOBALS['_site_settings_stored_values'];
    }

    if (!site_settings_table_exists()) {
        $GLOBALS['_site_settings_stored_values'] = [];
        return [];
    }

    try {
        $rows = Database::fetchAll('SELECT setting_key, setting_value FROM app_settings');
    } catch (Throwable $exception) {
        $GLOBALS['_site_settings_stored_values'] = [];
        return [];
    }

    $values = [];

    foreach ($rows as $row) {
        $key = (string) ($row['setting_key'] ?? '');

        if ($key === '') {
            continue;
        }

        $values[$key] = (string) ($row['setting_value'] ?? '');
    }

    $GLOBALS['_site_settings_stored_values'] = $values;

    return $values;
}

function site_setting_stored_value(string $key): ?string
{
    $values = site_setting_stored_values();

    if (!array_key_exists($key, $values)) {
        return null;
    }

    return (string) $values[$key];
}

function site_settings(): array
{
    if (isset($GLOBALS['_site_settings_cache']) && is_array($GLOBALS['_site_settings_cache'])) {
        return $GLOBALS['_site_settings_cache'];
    }

    $settings = site_setting_defaults();

    if (!site_settings_table_exists()) {
        $GLOBALS['_site_settings_cache'] = $settings;
        return $settings;
    }

    try {
        foreach (site_setting_stored_values() as $key => $storedValue) {
            if (!array_key_exists($key, $settings)) {
                continue;
            }

            $value = trim((string) $storedValue);

            if ($value !== '') {
                $settings[$key] = $value;
            }
        }
    } catch (Throwable $exception) {
        $GLOBALS['_site_settings_cache'] = $settings;
        return $settings;
    }

    $GLOBALS['_site_settings_cache'] = $settings;

    return $settings;
}

function site_settings_cache_reset(): void
{
    unset($GLOBALS['_site_settings_cache'], $GLOBALS['_site_settings_table_exists'], $GLOBALS['_site_settings_stored_values']);
}

function site_setting(string $key, ?string $fallback = null): string
{
    $settings = site_settings();

    if (array_key_exists($key, $settings) && trim((string) $settings[$key]) !== '') {
        return trim((string) $settings[$key]);
    }

    if ($fallback !== null) {
        return $fallback;
    }

    return (string) (site_setting_defaults()[$key] ?? '');
}

function openai_ocr_api_key(): string
{
    $stored = site_setting_stored_value('ocr.openai_api_key');

    if ($stored !== null && trim($stored) !== '') {
        return trim($stored);
    }

    return trim((string) app_config('ocr.openai_api_key', ''));
}

function openai_ocr_model(): string
{
    $model = trim(site_setting('ocr.openai_model', (string) app_config('ocr.openai_model', 'gpt-5.5')));

    return $model !== '' ? $model : 'gpt-5.5';
}

function purchase_ocr_mode(): string
{
    $mode = site_setting('ocr.mode', 'hybrid');

    return in_array($mode, ['free_only', 'hybrid', 'openai_first'], true) ? $mode : 'hybrid';
}

function purchase_ocr_max_pdf_pages(): int
{
    $pages = (int) site_setting('ocr.max_pdf_pages', '8');

    return max(1, min(20, $pages));
}

function purchase_ocr_min_confidence(): float
{
    $percent = (int) site_setting('ocr.min_confidence', '70');
    $percent = max(1, min(95, $percent));

    return $percent / 100;
}

function openai_ocr_enabled(): bool
{
    $storedEnabled = site_setting_stored_value('ocr.openai_enabled');

    if ($storedEnabled !== null && trim($storedEnabled) !== '') {
        return trim($storedEnabled) === '1';
    }

    $storedKey = site_setting_stored_value('ocr.openai_api_key');

    if ($storedKey !== null && trim($storedKey) !== '') {
        return true;
    }

    return (bool) app_config('ocr.openai_enabled', false);
}

function absolute_url(string $path): string
{
    $configuredUrl = rtrim(trim((string) app_config('app.url', '')), '/');

    if ($configuredUrl !== '') {
        return $configuredUrl . url($path);
    }

    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $scheme = request_is_secure() ? 'https' : 'http';

    if ($host === '') {
        return url($path);
    }

    return $scheme . '://' . $host . url($path);
}

function site_setting_is_secret(string $key): bool
{
    $definitions = site_setting_definitions();

    return isset($definitions[$key]) && (string) ($definitions[$key]['type'] ?? 'text') === 'secret';
}

function site_setting_groups(array $values = [], bool $includeSecrets = true): array
{
    $groups = site_setting_schema();

    foreach ($groups as &$group) {
        $fields = [];

        foreach ($group['fields'] as $key => $field) {
            $value = (string) ($values[$key] ?? site_setting($key, (string) ($field['default'] ?? '')));
            $isSecret = ($field['type'] ?? 'text') === 'secret';

            if ($isSecret && !$includeSecrets) {
                continue;
            }

            if ($isSecret) {
                $fallback = isset($field['fallback_config'])
                    ? trim((string) app_config((string) $field['fallback_config'], ''))
                    : '';
                $stored = site_setting_stored_value($key);
                $effective = $stored !== null && trim($stored) !== '' ? trim($stored) : $fallback;
                $value = '';
                $field['is_configured'] = $effective !== '';
                $field['configured_source'] = $stored !== null && trim($stored) !== '' ? 'settings' : ($fallback !== '' ? 'environment' : '');
                $field['placeholder'] = $effective !== ''
                    ? (string) ($field['configured_placeholder'] ?? 'Configured. Leave blank to keep current value.')
                    : (string) ($field['placeholder'] ?? 'Paste secret value');
            }

            $fields[] = $field + [
                'key' => $key,
                'value' => $value,
            ];
        }

        $group['fields'] = $fields;
    }
    unset($group);

    return $groups;
}
