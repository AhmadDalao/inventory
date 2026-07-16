<?php
declare(strict_types=1);

$moduleFiles = [
    __DIR__ . '/modules/core.php',
    __DIR__ . '/modules/settings.php',
    __DIR__ . '/modules/email.php',
    __DIR__ . '/modules/options.php',
    __DIR__ . '/modules/auth.php',
    __DIR__ . '/modules/users.php',
    __DIR__ . '/modules/inventory.php',
    __DIR__ . '/modules/dashboard.php',
    __DIR__ . '/modules/exports.php',
    __DIR__ . '/modules/scan.php',
    __DIR__ . '/modules/reports.php',
    __DIR__ . '/modules/notifications.php',
    __DIR__ . '/modules/search.php',
    __DIR__ . '/modules/handover_usage.php',
    __DIR__ . '/modules/handover_queries.php',
    __DIR__ . '/modules/workflow_core.php',
    __DIR__ . '/modules/signoff.php',
    __DIR__ . '/modules/requests.php',
    __DIR__ . '/modules/handovers.php',
    __DIR__ . '/modules/ocr.php',
    __DIR__ . '/modules/purchases.php',
    __DIR__ . '/modules/files.php',
    __DIR__ . '/modules/stocktakes.php',
    __DIR__ . '/modules/suppliers.php',
    __DIR__ . '/modules/reorder.php',
    __DIR__ . '/modules/audit.php',
    __DIR__ . '/modules/labels.php',
    __DIR__ . '/modules/documentation.php',
    __DIR__ . '/modules/assets.php',
];

foreach ($moduleFiles as $moduleFile) {
    require_once $moduleFile;
}
