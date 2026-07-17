<?php
declare(strict_types=1);

require_once __DIR__ . '/permission_catalog.php';
require_once __DIR__ . '/permission_presets.php';

function permission_keys(): array
{
    $keys = [];

    foreach (permission_catalog() as $group) {
        foreach ($group['permissions'] as $key => $label) {
            $keys[] = $key;
        }
    }

    return $keys;
}

function permission_groups_for_form(array $selectedKeys = []): array
{
    $selectedMap = array_fill_keys($selectedKeys, true);
    $groups = permission_catalog();

    foreach ($groups as &$group) {
        $permissions = [];

        foreach ($group['permissions'] as $key => $copy) {
            $permissions[] = [
                'key' => $key,
                'copy' => $copy,
                'checked' => isset($selectedMap[$key]),
            ];
        }

        $group['permissions'] = $permissions;
    }
    unset($group);

    return $groups;
}

function sanitize_permission_input(array $permissions): array
{
    $valid = array_fill_keys(permission_keys(), true);
    $normalized = [];

    foreach ($permissions as $permission) {
        $key = trim((string) $permission);

        if ($key !== '' && isset($valid[$key])) {
            $normalized[$key] = true;
        }
    }

    return array_keys($normalized);
}
