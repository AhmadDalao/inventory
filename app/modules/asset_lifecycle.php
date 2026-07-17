<?php
declare(strict_types=1);

// Compatibility loader for older direct includes. New asset lifecycle logic
// belongs in the focused modules below.
require_once __DIR__ . '/asset_status_actions.php';
require_once __DIR__ . '/asset_custody_actions.php';
require_once __DIR__ . '/asset_maintenance_actions.php';
require_once __DIR__ . '/asset_document_actions.php';
