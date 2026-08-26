<?php
declare(strict_types=1);

/**
 * Reason codes are permanent reporting identifiers. Owners may change labels,
 * ordering, and visibility, but never the codes themselves.
 */
function mobile_usage_reason_defaults(): array
{
    return [
        ['code' => 'online', 'label' => 'Online', 'active' => true, 'sort_order' => 1, 'requires_custom_text' => false],
        ['code' => 'walkin', 'label' => 'Walk-in', 'active' => true, 'sort_order' => 2, 'requires_custom_text' => false],
        ['code' => 'event', 'label' => 'Event', 'active' => true, 'sort_order' => 3, 'requires_custom_text' => false],
        ['code' => 'damage', 'label' => 'Damage', 'active' => true, 'sort_order' => 4, 'requires_custom_text' => false],
        ['code' => 'sport', 'label' => 'Sport', 'active' => true, 'sort_order' => 5, 'requires_custom_text' => false],
        ['code' => 'school', 'label' => 'School', 'active' => true, 'sort_order' => 6, 'requires_custom_text' => false],
        ['code' => 'complimentary', 'label' => 'Complimentary', 'active' => true, 'sort_order' => 7, 'requires_custom_text' => false],
        ['code' => 'no_show', 'label' => 'No Show', 'active' => true, 'sort_order' => 8, 'requires_custom_text' => false],
        ['code' => 'other', 'label' => 'Other', 'active' => true, 'sort_order' => 9, 'requires_custom_text' => true],
    ];
}

function general_usage_reason_defaults(): array
{
    return [
        ['code' => 'cleaning', 'label' => 'Cleaning', 'active' => true, 'sort_order' => 1, 'requires_custom_text' => false],
        ['code' => 'operations', 'label' => 'Operations', 'active' => true, 'sort_order' => 2, 'requires_custom_text' => false],
        ['code' => 'maintenance', 'label' => 'Maintenance', 'active' => true, 'sort_order' => 3, 'requires_custom_text' => false],
        ['code' => 'event', 'label' => 'Event', 'active' => true, 'sort_order' => 4, 'requires_custom_text' => false],
        ['code' => 'damage', 'label' => 'Damage', 'active' => true, 'sort_order' => 5, 'requires_custom_text' => false],
        ['code' => 'department_supplies', 'label' => 'Department Supplies', 'active' => true, 'sort_order' => 6, 'requires_custom_text' => false],
        ['code' => 'other', 'label' => 'Other', 'active' => true, 'sort_order' => 7, 'requires_custom_text' => true],
    ];
}

function usage_reason_defaults_for_profile(string $profile): array
{
    return normalize_storage_usage_profile($profile) === 'wristband'
        ? mobile_usage_reason_defaults()
        : general_usage_reason_defaults();
}

function usage_reason_setting_key(string $profile): string
{
    return normalize_storage_usage_profile($profile) === 'wristband'
        ? 'mobile.usage_reasons'
        : 'mobile.general_usage_reasons';
}

function mobile_usage_reason_normalize_code(string $code): string
{
    $normalized = strtolower(trim($code));
    $normalized = str_replace([' ', '-'], '_', $normalized);

    switch ($normalized) {
        case 'noshow':
        case 'no__show':
            return 'no_show';
        case 'walk_in':
        case 'walk__in':
            return 'walkin';
        default:
            return $normalized;
    }
}

function mobile_usage_reason_catalog(bool $activeOnly = false): array
{
    return usage_reason_catalog_for_profile('wristband', $activeOnly);
}

function usage_reason_catalog_for_profile(string $profile, bool $activeOnly = false): array
{
    $profile = normalize_storage_usage_profile($profile);
    $defaults = usage_reason_defaults_for_profile($profile);
    // Mobile Access stores this managed catalog outside Website Control's fixed schema.
    $stored = json_decode((string) site_setting_stored_value(usage_reason_setting_key($profile)), true);
    $storedByCode = [];

    if (is_array($stored)) {
        foreach ($stored as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $code = mobile_usage_reason_normalize_code((string) ($entry['code'] ?? ''));
            if ($code !== '') {
                $storedByCode[$code] = $entry;
            }
        }
    }

    $catalog = [];
    foreach ($defaults as $default) {
        $code = (string) $default['code'];
        $override = $storedByCode[$code] ?? [];
        $label = trim((string) ($override['label'] ?? $default['label']));
        $active = array_key_exists('active', $override)
            ? filter_var($override['active'], FILTER_VALIDATE_BOOLEAN)
            : (bool) $default['active'];
        $sortOrder = max(1, min(999, (int) ($override['sort_order'] ?? $default['sort_order'])));

        if ($activeOnly && !$active) {
            continue;
        }

        $catalog[] = [
            'code' => $code,
            'label' => $label !== '' ? substr($label, 0, 60) : (string) $default['label'],
            'active' => $active,
            'sort_order' => $sortOrder,
            'requires_custom_text' => (bool) $default['requires_custom_text'],
        ];
    }

    usort($catalog, static function (array $left, array $right): int {
        return [$left['sort_order'], $left['label'], $left['code']]
            <=> [$right['sort_order'], $right['label'], $right['code']];
    });

    return $catalog;
}

function usage_reason_catalogs(bool $activeOnly = false): array
{
    return [
        'wristband' => usage_reason_catalog_for_profile('wristband', $activeOnly),
        'general' => usage_reason_catalog_for_profile('general', $activeOnly),
    ];
}

function all_usage_reason_catalog(bool $activeOnly = true): array
{
    $combined = [];
    foreach (usage_reason_catalogs($activeOnly) as $catalog) {
        foreach ($catalog as $reason) {
            $combined[(string) $reason['code']] ??= $reason;
        }
    }

    return array_values($combined);
}

function mobile_usage_reason_codes(bool $activeOnly = true): array
{
    return array_column(mobile_usage_reason_catalog($activeOnly), 'code');
}

function usage_reason_codes_for_profile(string $profile, bool $activeOnly = true): array
{
    return array_column(usage_reason_catalog_for_profile($profile, $activeOnly), 'code');
}

function mobile_usage_reason_definition(string $code, bool $activeOnly = true): ?array
{
    return usage_reason_definition_for_profile('wristband', $code, $activeOnly);
}

function usage_reason_definition_for_profile(string $profile, string $code, bool $activeOnly = true): ?array
{
    $normalized = mobile_usage_reason_normalize_code($code);
    foreach (usage_reason_catalog_for_profile($profile, $activeOnly) as $reason) {
        if ($reason['code'] === $normalized) {
            return $reason;
        }
    }

    return null;
}

/**
 * @return array{code: string, custom_reason: ?string}
 */
function mobile_usage_reason_input(string $code, ?string $customReason, string $field = 'reason'): array
{
    return usage_reason_input_for_profile('wristband', $code, $customReason, $field);
}

/**
 * @return array{code: string, custom_reason: ?string}
 */
function usage_reason_input_for_profile(string $profile, string $code, ?string $customReason, string $field = 'reason'): array
{
    $normalized = mobile_usage_reason_normalize_code($code);
    $definition = usage_reason_definition_for_profile($profile, $normalized, true);
    if ($definition === null) {
        throw new MobileApiException(
            'validation_failed',
            'Pick an active usage reason.',
            422,
            [$field => ['Pick an active usage reason.']]
        );
    }

    $custom = trim((string) $customReason);
    if ((bool) $definition['requires_custom_text'] && $custom === '') {
        throw new MobileApiException(
            'validation_failed',
            'Describe the Other usage reason.',
            422,
            [$field . '_custom' => ['Required when Other is selected.']]
        );
    }

    return [
        'code' => $normalized,
        'custom_reason' => $normalized === 'other' ? substr($custom, 0, 160) : null,
    ];
}

/**
 * @return array{code: string, custom_reason: ?string}
 */
function usage_reason_input_for_storage(int $storageId, string $code, ?string $customReason, string $field = 'reason'): array
{
    return usage_reason_input_for_profile(
        storage_usage_profile_for_id($storageId),
        $code,
        $customReason,
        $field
    );
}

function inventory_usage_reason_label(string $code, ?string $customReason = null): string
{
    $normalized = mobile_usage_reason_normalize_code($code);
    if ($normalized === 'other' && trim((string) $customReason) !== '') {
        return trim((string) $customReason);
    }

    foreach (all_usage_reason_catalog(false) as $reason) {
        if ((string) $reason['code'] === $normalized) {
            return (string) $reason['label'];
        }
    }

    return ucwords(str_replace('_', ' ', $normalized));
}
