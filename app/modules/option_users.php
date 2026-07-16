<?php
declare(strict_types=1);

// User, role, and position option helpers.

function user_role_options(): array
{
    return [
        'admin' => 'Admin',
        'staff' => 'Staff',
    ];
}

function user_role_label(string $role): string
{
    switch ($role) {
        case 'owner':
            return 'Owner';
        case 'admin':
            return 'Admin';
        case 'staff':
            return 'Staff';
        default:
            return ucfirst($role);
    }
}

function user_position_options(): array
{
    return [
        'owner_operator' => 'Owner / General Manager',
        'cfo' => 'CFO',
        'accountant' => 'Accountant',
        'operations_manager' => 'Operations Manager',
        'storage_manager' => 'Storage Manager',
        'reception_staff' => 'Reception Staff',
        'general_admin' => 'General Admin',
        'staff' => 'Staff',
    ];
}

function user_position_label(?string $position, string $role = ''): string
{
    $position = trim((string) $position);
    $options = user_position_options();

    if ($position !== '' && isset($options[$position])) {
        return $options[$position];
    }

    if ($role === 'owner') {
        return $options['owner_operator'];
    }

    if ($role === 'admin') {
        return $options['general_admin'];
    }

    return $options['staff'];
}

function user_initials(?string $name): string
{
    $name = trim((string) $name);

    if ($name === '') {
        return 'U';
    }

    $parts = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];

    if (count($parts) === 1) {
        return strtoupper(substr((string) $parts[0], 0, 2));
    }

    return strtoupper(substr((string) $parts[0], 0, 1) . substr((string) end($parts), 0, 1));
}

function access_role_for_position(string $position): string
{
    switch ($position) {
        case 'reception_staff':
        case 'staff':
            return 'staff';
        case 'owner_operator':
        case 'cfo':
        case 'accountant':
        case 'operations_manager':
        case 'storage_manager':
        case 'general_admin':
        default:
            return 'admin';
    }
}
