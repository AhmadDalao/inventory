<?php
declare(strict_types=1);

// Compatibility shim. Request handlers now live in focused request modules.
require_once __DIR__ . '/request_support.php';
require_once __DIR__ . '/request_pages.php';
require_once __DIR__ . '/request_create.php';
require_once __DIR__ . '/request_decisions.php';
require_once __DIR__ . '/request_receipts.php';
require_once __DIR__ . '/request_status.php';
require_once __DIR__ . '/request_exports.php';
