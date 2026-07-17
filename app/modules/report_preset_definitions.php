<?php
declare(strict_types=1);

// Saved report preset type definitions and permission checks.

function saved_report_preset_types(): array
{
    return [
        'daily_operations' => [
            'label' => 'Daily operations',
            'icon' => 'reports',
            'source_path' => '/reports',
            'export_csv_path' => '/exports/daily-summary',
            'export_xlsx_path' => '/exports/daily-summary.xlsx',
            'view_permission' => 'movements.view',
            'export_permission' => 'movements.export',
            'default_filters' => ['date' => date('Y-m-d')],
        ],
        'finance' => [
            'label' => 'Finance purchases',
            'icon' => 'purchases',
            'source_path' => '/purchases',
            'export_csv_path' => '/exports/purchases',
            'export_xlsx_path' => '',
            'view_permission' => 'purchases.view',
            'export_permission' => 'purchases.export',
            'default_filters' => ['status' => 'all'],
        ],
        'usage_by_reason' => [
            'label' => 'Usage by reason',
            'icon' => 'movements',
            'source_path' => '/reports',
            'export_csv_path' => '/exports/daily-summary',
            'export_xlsx_path' => '/exports/daily-summary.xlsx',
            'view_permission' => 'movements.view',
            'export_permission' => 'movements.export',
            'default_filters' => ['date' => date('Y-m-d'), 'movement_type' => 'usage'],
        ],
        'storage_owner' => [
            'label' => 'Storage owner summary',
            'icon' => 'storages',
            'source_path' => '/storages',
            'export_csv_path' => '/exports/storages',
            'export_xlsx_path' => '/exports/storages.xlsx',
            'view_permission' => 'storages.view',
            'export_permission' => 'storages.export',
            'default_filters' => ['status' => 'active'],
        ],
        'purchases' => [
            'label' => 'Purchases',
            'icon' => 'purchases',
            'source_path' => '/purchases',
            'export_csv_path' => '/exports/purchases',
            'export_xlsx_path' => '',
            'view_permission' => 'purchases.view',
            'export_permission' => 'purchases.export',
            'default_filters' => ['status' => 'all'],
        ],
        'assets' => [
            'label' => 'Assets',
            'icon' => 'assets',
            'source_path' => '/company-assets',
            'export_csv_path' => '/exports/assets',
            'export_xlsx_path' => '/exports/assets.xlsx',
            'view_permission' => 'assets.view',
            'export_permission' => 'assets.export',
            'default_filters' => ['active' => 'all'],
        ],
        'stock_movements' => [
            'label' => 'Stock movements',
            'icon' => 'movements',
            'source_path' => '/movements',
            'export_csv_path' => '/exports/movements',
            'export_xlsx_path' => '/exports/movements.xlsx',
            'view_permission' => 'movements.view',
            'export_permission' => 'movements.export',
            'default_filters' => [],
        ],
        'requests' => [
            'label' => 'Requests',
            'icon' => 'requests',
            'source_path' => '/requests',
            'export_csv_path' => '/exports/requests',
            'export_xlsx_path' => '',
            'view_permission' => 'requests.view',
            'export_permission' => 'requests.export',
            'default_filters' => ['status' => 'all'],
        ],
        'handovers' => [
            'label' => 'Handovers',
            'icon' => 'handover',
            'source_path' => '/handovers',
            'export_csv_path' => '/exports/handovers',
            'export_xlsx_path' => '',
            'view_permission' => 'handovers.view',
            'export_permission' => 'handovers.export',
            'default_filters' => ['status' => 'all'],
        ],
    ];
}

function saved_report_preset_type(string $type): ?array
{
    $types = saved_report_preset_types();

    return $types[$type] ?? null;
}

function saved_report_can_view_type(string $type): bool
{
    $definition = saved_report_preset_type($type);

    if ($definition === null) {
        return false;
    }

    return Auth::hasPermission((string) $definition['view_permission'])
        || Auth::hasPermission((string) $definition['export_permission']);
}

function saved_report_can_export_type(string $type): bool
{
    $definition = saved_report_preset_type($type);

    return $definition !== null && Auth::hasPermission((string) $definition['export_permission']);
}
