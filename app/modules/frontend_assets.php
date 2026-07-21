<?php
declare(strict_types=1);

function frontend_stylesheets(): array
{
    return [
        'css/foundation.css',
        'css/themes/clean-material.css',
        'css/themes/clean-console.css',
        'css/themes/kona.css',
        'css/compatibility.css',
        'css/print.css',
        'css/themes/official.css',
        'css/assets.css',
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
