<?php
declare(strict_types=1);

// Compatibility loader for direct includes. Primary loading comes from app/module_manifest.php.

require_once __DIR__ . '/reorder_support.php';
require_once __DIR__ . '/reorder_pages.php';
require_once __DIR__ . '/reorder_actions.php';
require_once __DIR__ . '/reorder_exports.php';
