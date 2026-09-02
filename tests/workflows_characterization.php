<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$suite = 'workflows-characterization';

require $root . '/app/bootstrap.php';
require $root . '/app/modules.php';
require __DIR__ . '/support/characterization.php';

$expected = characterization_fixture($root, 'domain');
$actual = characterization_domain_contract();
characterization_assert($actual['statuses'] === $expected['statuses'], $suite, 'Workflow/status catalog changed.');

$contracts = [
    'request' => [
        'app/modules/request_guards.php' => ['pending', 'approved', 'receipt_review', 'draft', 'cancelled'],
        'app/modules/request_inventory.php' => ['apply_inventory_movement(', 'request'],
    ],
    'handover' => [
        'app/modules/handover_permissions.php' => ['requested', 'awaiting_receipt', 'receipt_review', 'delivered', 'pending_approval'],
        'app/modules/handover_inventory.php' => ['apply_inventory_movement(', 'handover'],
        'app/modules/handover_custody_actions.php' => ['submitted', 'approved', 'rejected', 'FOR UPDATE'],
    ],
    'purchase' => [
        'app/modules/purchase_decision_rules.php' => ['draft', 'pending_approval', 'receipt_review'],
        'app/modules/purchase_completion_actions.php' => ['apply_inventory_movement(', 'completed'],
    ],
    'stocktake' => [
        'app/modules/stocktake_actions.php' => ['draft', 'pending_approval', 'approved', 'cancelled', 'apply_inventory_movement('],
        'app/modules/stocktake_support.php' => ['FOR UPDATE'],
    ],
    'asset' => [
        'app/modules/asset_custody_actions.php' => ['pending_receipt', 'assigned', 'return_requested', 'available'],
        'app/modules/asset_maintenance_actions.php' => ['maintenance', 'completed'],
    ],
];

foreach ($contracts as $domain => $files) {
    foreach ($files as $relativePath => $markers) {
        $source = (string) file_get_contents($root . '/' . $relativePath);
        foreach ($markers as $marker) {
            characterization_assert(str_contains($source, $marker), $suite, ucfirst($domain) . ' lifecycle marker is missing from ' . $relativePath . ': ' . $marker);
        }
    }
}

echo '[' . $suite . '] PASS' . PHP_EOL;
