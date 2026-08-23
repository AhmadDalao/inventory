<?php
declare(strict_types=1);

function frontend_stylesheets(): array
{
    return [
        'css/foundation.css',
        'css/shell.css',
        'css/components.css',
        'css/tables.css',
        'css/workflows.css',
        'css/domains/inventory.css',
        'css/domains/scan.css',
        'css/domains/handovers.css',
        'css/domains/wristbands.css',
        'css/domains/purchases-ocr.css',
        'css/domains/reports.css',
        'css/domains/admin.css',
        'css/domains/settings.css',
        'css/domains/documentation.css',
        'css/domains/assets.css',
        'css/themes/classic.css',
        'css/themes/kona.css',
        'css/themes/official.css',
        'css/print.css',
        'css/mobile.css',
    ];
}

function frontend_scripts(): array
{
    return [
        [
            'path' => 'app.js',
            'type' => 'module',
        ],
    ];
}
