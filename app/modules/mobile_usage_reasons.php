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
    $defaults = mobile_usage_reason_defaults();
    // Mobile Access stores this managed catalog outside Website Control's fixed schema.
    $stored = json_decode((string) site_setting_stored_value('mobile.usage_reasons'), true);
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

function mobile_usage_reason_codes(bool $activeOnly = true): array
{
    return array_column(mobile_usage_reason_catalog($activeOnly), 'code');
}

function mobile_usage_reason_definition(string $code, bool $activeOnly = true): ?array
{
    $normalized = mobile_usage_reason_normalize_code($code);
    foreach (mobile_usage_reason_catalog($activeOnly) as $reason) {
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
    $normalized = mobile_usage_reason_normalize_code($code);
    $definition = mobile_usage_reason_definition($normalized, true);
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
