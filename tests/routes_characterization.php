<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require __DIR__ . '/support/characterization.php';

$suite = 'routes-characterization';
$expected = characterization_fixture($root, 'routes');
$actual = characterization_routes($root . '/index.php');

characterization_assert($actual === $expected, $suite, 'Ordered method/path/handler snapshot changed. Review route order and regenerate intentionally.');
characterization_assert(count($actual) === 270, $suite, 'Expected exactly 270 web and API routes.');

$seen = [];
foreach ($actual as $index => $route) {
    characterization_assert((int) $route['position'] === $index + 1, $suite, 'Route positions must remain contiguous.');
    characterization_assert(in_array($route['method'], ['GET', 'POST'], true), $suite, 'Unexpected HTTP method at route position ' . ($index + 1) . '.');
    $key = $route['method'] . ' ' . $route['path'];
    characterization_assert(!isset($seen[$key]), $suite, 'Duplicate route registration: ' . $key);
    $seen[$key] = true;
}

echo '[' . $suite . '] PASS (270 ordered routes)' . PHP_EOL;
