<?php
declare(strict_types=1);

// Compatibility loader for global search helpers and handlers.
// New code should include the focused search modules through app/module_manifest.php.

require_once __DIR__ . '/search_helpers.php';
require_once __DIR__ . '/search_reference.php';
require_once __DIR__ . '/search_pages.php';
require_once __DIR__ . '/search_results.php';
require_once __DIR__ . '/search_handlers.php';
