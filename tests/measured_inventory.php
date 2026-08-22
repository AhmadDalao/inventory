<?php
declare(strict_types=1);

// Focused, database-free coverage for the canonical-unit boundary. Integration
// tests cover persistence; this test keeps conversion and proof rules honest.

final class Database
{
    /** @var array<int, array<string, mixed>> */
    public static array $presets = [];

    public static function fetch(string $sql, array $params = []): ?array
    {
        if (!str_contains($sql, 'FROM item_package_presets')) {
            return null;
        }

        $preset = self::$presets[(int) ($params['id'] ?? 0)] ?? null;
        if ($preset === null || (int) $preset['item_id'] !== (int) ($params['item_id'] ?? 0)) {
            return null;
        }

        return $preset;
    }
}

/** @var array<string, string> */
$measuredInventorySettings = [];

function is_numeric_value(mixed $value): bool
{
    return is_numeric($value);
}

function quantity_value(mixed $value): float
{
    return (float) $value;
}

function normalize_entity_id(mixed $value): ?int
{
    $id = filter_var($value, FILTER_VALIDATE_INT);

    return $id !== false && $id > 0 ? $id : null;
}

function site_setting(string $key, string $default = ''): string
{
    global $measuredInventorySettings;

    return $measuredInventorySettings[$key] ?? $default;
}

function mobile_usage_reason_normalize_code(string $value): string
{
    return strtolower(trim($value));
}

require_once __DIR__ . '/../app/modules/measurements.php';

function measured_inventory_fail(string $message): never
{
    fwrite(STDERR, '[measured-inventory] FAIL: ' . $message . PHP_EOL);
    exit(1);
}

function measured_inventory_assert_close(float $actual, float $expected, string $message): void
{
    if (abs($actual - $expected) > inventory_quantity_tolerance()) {
        measured_inventory_fail($message . " (expected {$expected}, got {$actual})");
    }
}

$volumeItem = [
    'id' => 15,
    'name' => 'Floor soap',
    'unit' => 'ml',
    'measurement_dimension' => 'volume',
    'usage_proof_policy' => 'inherit',
    'refill_proof_policy' => 'inherit',
];
$rollItem = [
    'id' => 16,
    'name' => 'Toilet paper',
    'unit' => 'roll',
    'measurement_dimension' => 'count',
    'usage_proof_policy' => 'optional',
    'refill_proof_policy' => 'required',
];
$massItem = [
    'id' => 17,
    'name' => 'Cleaning powder',
    'unit' => 'g',
    'measurement_dimension' => 'mass',
    'usage_proof_policy' => 'required',
    'refill_proof_policy' => 'optional',
];

Database::$presets = [
    7 => ['id' => 7, 'item_id' => 15, 'label' => '1 L bottle', 'scan_code' => 'SOAP-1L', 'pieces_per_unit' => 1000, 'is_active' => 1],
    8 => ['id' => 8, 'item_id' => 15, 'label' => '250 mL bottle', 'scan_code' => 'SOAP-250', 'pieces_per_unit' => 250, 'is_active' => 1],
    9 => ['id' => 9, 'item_id' => 16, 'label' => '24-roll box', 'scan_code' => 'ROLL-24', 'pieces_per_unit' => 24, 'is_active' => 1],
    10 => ['id' => 10, 'item_id' => 17, 'label' => '5 kg bag', 'scan_code' => 'POWDER-5K', 'pieces_per_unit' => 5000, 'is_active' => 1],
    11 => ['id' => 11, 'item_id' => 15, 'label' => 'Old bottle', 'scan_code' => 'SOAP-OLD', 'pieces_per_unit' => 1000, 'is_active' => 0],
];

measured_inventory_assert_close(
    (float) resolve_inventory_measurement($volumeItem, 2, 7)['base_quantity'],
    2000,
    'Two 1 L bottles must become 2,000 mL'
);
measured_inventory_assert_close(
    (float) resolve_inventory_measurement($volumeItem, 3, 8)['base_quantity'],
    750,
    'Three 250 mL bottles must become 750 mL'
);
measured_inventory_assert_close(
    (float) resolve_inventory_measurement($rollItem, 2, 9)['base_quantity'],
    48,
    'Two 24-roll boxes must become 48 rolls'
);
measured_inventory_assert_close(
    (float) resolve_inventory_measurement($massItem, 1.5, 10)['base_quantity'],
    7500,
    'One and a half 5 kg bags must become 7,500 g'
);

try {
    resolve_inventory_measurement($volumeItem, 1, 11);
    measured_inventory_fail('Disabled package presets must be rejected.');
} catch (InvalidArgumentException $exception) {
    if (!str_contains($exception->getMessage(), 'disabled')) {
        throw $exception;
    }
}

try {
    resolve_inventory_measurement($rollItem, 1, 7);
    measured_inventory_fail('A package preset from another item must be rejected.');
} catch (InvalidArgumentException $exception) {
    if (!str_contains($exception->getMessage(), 'does not belong')) {
        throw $exception;
    }
}

if (!inventory_measurement_matches_unit('volume', 'ml')
    || inventory_measurement_matches_unit('mass', 'ml')
    || !inventory_measurement_matches_unit('custom', 'ml')) {
    measured_inventory_fail('Measurement dimensions must reject incompatible canonical units.');
}

$measuredInventorySettings = [
    'proof.usage_default' => 'required',
    'proof.refill_default' => 'optional',
];
if (!inventory_operation_requires_proof([$volumeItem], 'usage')) {
    measured_inventory_fail('Inherited usage proof must follow the global required setting.');
}
if (inventory_operation_requires_proof([$rollItem], 'usage')) {
    measured_inventory_fail('An item-level optional policy must override the global usage requirement.');
}
if (!inventory_operation_requires_proof([$rollItem], 'refill')) {
    measured_inventory_fail('An item-level required refill proof policy must be enforced.');
}
if (!inventory_operation_requires_proof([$volumeItem, $massItem], 'usage')) {
    measured_inventory_fail('One required item must make the whole submitted batch require proof.');
}

echo '[measured-inventory] PASS' . PHP_EOL;
