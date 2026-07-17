<?php
declare(strict_types=1);

// Compatibility loader for older direct includes. New logic belongs in focused asset_category_* modules.
require_once __DIR__ . '/asset_category_permissions.php';
require_once __DIR__ . '/asset_category_filters.php';
require_once __DIR__ . '/asset_category_queries.php';
require_once __DIR__ . '/asset_category_tree.php';
require_once __DIR__ . '/asset_category_guards.php';
require_once __DIR__ . '/asset_category_payloads.php';
