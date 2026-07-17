<?php
declare(strict_types=1);

// Compatibility loader for direct includes. Primary loading comes from app/module_manifest.php.

require_once __DIR__ . '/scan_payload.php';
require_once __DIR__ . '/scan_pages.php';
require_once __DIR__ . '/scan_lookup.php';
require_once __DIR__ . '/scan_manual_restock.php';
