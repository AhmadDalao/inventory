<?php
declare(strict_types=1);

// Compatibility loader for older direct includes. New logic belongs in focused request_* modules.
require_once __DIR__ . '/request_filters.php';
require_once __DIR__ . '/request_lookup.php';
require_once __DIR__ . '/request_guards.php';
require_once __DIR__ . '/request_inventory.php';
require_once __DIR__ . '/request_queries.php';
