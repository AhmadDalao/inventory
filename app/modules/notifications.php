<?php
declare(strict_types=1);

// Compatibility loader for direct includes. Primary loading comes from app/module_manifest.php.

require_once __DIR__ . '/notifications_dispatch.php';
require_once __DIR__ . '/notifications_queries.php';
require_once __DIR__ . '/notifications_reads.php';
require_once __DIR__ . '/notifications_pages.php';
require_once __DIR__ . '/notifications_actions.php';
