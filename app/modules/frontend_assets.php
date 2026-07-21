<?php
declare(strict_types=1);

function frontend_stylesheets(): array
{
    return [
        'app.css',
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
