<?php
declare(strict_types=1);

// Compatibility shim. Handover handlers now live in workflow-stage modules.
require_once __DIR__ . '/handover_pages.php';
require_once __DIR__ . '/handover_create.php';
require_once __DIR__ . '/handover_cancellations.php';
require_once __DIR__ . '/handover_decisions.php';
require_once __DIR__ . '/handover_receipts.php';
require_once __DIR__ . '/handover_closeout.php';
