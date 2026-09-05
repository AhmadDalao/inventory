<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$suite = 'architecture-characterization';

require_once $root . '/app/modules/frontend_assets.php';
require __DIR__ . '/support/characterization.php';

$modules = characterization_module_contract($root);
$frontend = characterization_frontend_contract($root);

characterization_assert($modules === characterization_fixture($root, 'modules'), $suite, 'Module manifest snapshot changed.');
characterization_assert($frontend === characterization_fixture($root, 'frontend'), $suite, 'Frontend registry/cascade/event snapshot changed.');
characterization_assert($modules['group_count'] === 13, $suite, 'Expected 13 module groups.');
characterization_assert($modules['module_count'] === 174, $suite, 'Expected 174 eagerly loaded modules.');
characterization_assert(count($frontend['stylesheets']) === 20, $suite, 'Expected 20 stylesheets in cascade order.');
characterization_assert(count($frontend['javascript_modules']) === 34, $suite, 'Expected 34 physical JavaScript modules.');

foreach ($modules['compatibility_loaders'] as $relativePath) {
    $source = preg_replace('/\s+/', '', (string) file_get_contents($root . '/' . $relativePath));
    characterization_assert($source === "<?phpdeclare(strict_types=1);require_once__DIR__.'/modules.php';", $suite, $relativePath . ' gained logic instead of remaining an adapter.');
}

$initializerNames = array_column($frontend['initializers'], 'name');
characterization_assert(count($initializerNames) === count(array_unique($initializerNames)), $suite, 'Initializer names must be unique.');
foreach (['inventory:action-complete', 'inventory:content-replaced', 'inventory:refresh'] as $event) {
    characterization_assert(($frontend['events'][$event] ?? []) !== [], $suite, 'Frontend lifecycle event is no longer wired: ' . $event);
}

echo '[' . $suite . '] PASS' . PHP_EOL;
