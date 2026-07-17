<?php
declare(strict_types=1);

// Compatibility loader for older direct includes. New purchase lifecycle logic
// belongs in the focused modules below.
require_once __DIR__ . '/purchase_decision_rules.php';
require_once __DIR__ . '/purchase_approval_actions.php';
require_once __DIR__ . '/purchase_receiving_actions.php';
require_once __DIR__ . '/purchase_completion_actions.php';
require_once __DIR__ . '/purchase_cancellation_actions.php';
