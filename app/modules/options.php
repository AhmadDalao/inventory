<?php
declare(strict_types=1);

// Compatibility loader for option catalogs. Keep option logic in focused option_* modules.

foreach ([
    'option_users',
    'option_suppliers',
    'option_workflows',
    'option_movements',
    'option_assets',
    'option_items',
    'option_reports',
] as $optionModule) {
    require_once __DIR__ . '/' . $optionModule . '.php';
}
