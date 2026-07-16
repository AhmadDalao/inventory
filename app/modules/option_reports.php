<?php
declare(strict_types=1);

// Report access helper.

function reports_can_access(): bool
{
    foreach ([
        'items.export',
        'movements.export',
        'storages.export',
        'requests.export',
        'handovers.export',
        'assets.export',
        'purchases.export',
        'files.export',
        'stocktakes.export',
        'suppliers.export',
        'reorder.export',
        'audit.export',
        'email_logs.export',
        'users.export',
    ] as $permission) {
        if (Auth::hasPermission($permission)) {
            return true;
        }
    }

    return false;
}
