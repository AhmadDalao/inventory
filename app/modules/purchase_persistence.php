<?php
declare(strict_types=1);

// Compatibility loader for purchase persistence helpers.
// New code should include the focused purchase_* modules through app/module_manifest.php.

require_once __DIR__ . '/purchase_lookup.php';
require_once __DIR__ . '/purchase_line_inputs.php';
require_once __DIR__ . '/purchase_supplier_persistence.php';
require_once __DIR__ . '/purchase_drafts.php';
require_once __DIR__ . '/purchase_item_creation.php';
