<?php
declare(strict_types=1);

// Compatibility shim. Shared workflow helpers now live in focused workflow modules.
require_once __DIR__ . '/workflow_system.php';
require_once __DIR__ . '/workflow_inputs.php';
require_once __DIR__ . '/workflow_identity.php';
require_once __DIR__ . '/workflow_stock_impact.php';
