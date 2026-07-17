<?php
declare(strict_types=1);

function can_manage_asset_categories(): bool
{
    return !Auth::isStaff() && (Auth::hasPermission('assets.categories') || Auth::hasPermission('assets.edit'));
}
