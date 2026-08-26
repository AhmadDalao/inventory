<?php
declare(strict_types=1);

function storage_usage_profile_values(): array
{
    return ['general', 'wristband'];
}

function normalize_storage_usage_profile(?string $profile, string $fallback = 'general'): string
{
    $normalized = strtolower(trim((string) $profile));

    return in_array($normalized, storage_usage_profile_values(), true) ? $normalized : $fallback;
}

function storage_usage_profile_label(string $profile): string
{
    return normalize_storage_usage_profile($profile) === 'wristband'
        ? 'Wristband / Guest Check-in'
        : 'General Operations';
}

function storage_usage_profile_description(string $profile): string
{
    return normalize_storage_usage_profile($profile) === 'wristband'
        ? 'Uses guest-entry reasons such as Online, Walk-in, Complimentary, and No Show.'
        : 'Uses operational reasons such as Cleaning, Operations, Maintenance, and Department Supplies.';
}

function storage_usage_profile_for_id(int $storageId): string
{
    static $profiles = [];

    if ($storageId <= 0) {
        return 'general';
    }

    if (isset($profiles[$storageId])) {
        return $profiles[$storageId];
    }

    $profile = Database::scalar(
        'SELECT usage_profile FROM storages WHERE id = :id LIMIT 1',
        ['id' => $storageId]
    );

    $profiles[$storageId] = normalize_storage_usage_profile(is_string($profile) ? $profile : null);

    return $profiles[$storageId];
}
