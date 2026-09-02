<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$suite = 'units-characterization';

require $root . '/app/bootstrap.php';
require $root . '/app/modules.php';
require __DIR__ . '/support/characterization.php';

$expected = characterization_fixture($root, 'domain');
$actual = characterization_domain_contract();
characterization_assert($actual['measurement'] === $expected['measurement'], $suite, 'Measurement/proof contract changed.');

$unitCases = [
    'pcs' => 'count', 'box' => 'count',
    'ml' => 'volume', 'l' => 'volume',
    'g' => 'mass', 'kg' => 'mass',
    'mm' => 'length', 'cm' => 'length', 'm' => 'length',
    'm2' => 'area', 'm²' => 'area', 'sqm' => 'area',
];
foreach ($unitCases as $unit => $dimension) {
    characterization_assert(inventory_unit_dimension($unit) === $dimension, $suite, $unit . ' no longer maps to ' . $dimension . '.');
    characterization_assert(inventory_measurement_matches_unit($dimension, $unit), $suite, $unit . ' is rejected by its canonical dimension.');
}
characterization_assert(!inventory_measurement_matches_unit('mass', 'ml'), $suite, 'Invalid cross-dimension units must be rejected.');
characterization_assert(item_canonical_unit(['unit' => 'custom', 'custom_unit' => 'tray']) === 'tray', $suite, 'Custom canonical unit changed.');
characterization_assert(inventory_quantity(1.23456789) === 1.234568, $suite, 'Canonical quantity precision changed.');

echo '[' . $suite . '] PASS' . PHP_EOL;
