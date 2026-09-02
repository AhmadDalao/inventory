<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$suite = 'stock-movement-characterization';

require $root . '/app/bootstrap.php';
require $root . '/app/modules.php';
require __DIR__ . '/support/characterization.php';

$pdo = Database::connection();
characterization_assert(!$pdo->inTransaction(), $suite, 'Test requires a clean database transaction.');
$ownerId = (int) Database::scalar('SELECT id FROM users WHERE role = "owner" AND is_active = 1 ORDER BY id LIMIT 1');
characterization_assert($ownerId > 0, $suite, 'An active owner is required.');

$prefix = 'ZZCHARSTOCK';
$beforeRows = (int) Database::scalar('SELECT COUNT(*) FROM items WHERE sku LIKE :prefix', ['prefix' => $prefix . '%']);
$pdo->beginTransaction();

try {
    foreach (['A', 'B'] as $suffix) {
        Database::execute(
            'INSERT INTO storages (name, storage_type, usage_profile, notes, is_system, is_active, owner_user_id, created_by, updated_by, created_at, updated_at)
             VALUES (:name, "storage", "general", :notes, 0, 1, :owner_user_id, :created_by, :updated_by, NOW(), NOW())',
            [
                'name' => $prefix . '-' . $suffix,
                'notes' => 'Rolled-back characterization storage',
                'owner_user_id' => $ownerId,
                'created_by' => $ownerId,
                'updated_by' => $ownerId,
            ]
        );
        $storageIds[] = Database::lastInsertId();
    }
    [$storageA, $storageB] = array_map('intval', $storageIds);

    Database::execute(
        'INSERT INTO items (name, sku, category, storage_id, unit, measurement_dimension, current_quantity, reorder_level, cost_per_unit, notes, is_active, created_by, updated_by, created_at, updated_at)
         VALUES (:name, :sku, "Characterization", :storage_id, "pcs", "count", 0, 0, 0, :notes, 1, :created_by, :updated_by, NOW(), NOW())',
        [
            'name' => $prefix . ' Item',
            'sku' => $prefix . '-SKU',
            'storage_id' => $storageA,
            'notes' => 'Rolled-back characterization item',
            'created_by' => $ownerId,
            'updated_by' => $ownerId,
        ]
    );
    $itemId = Database::lastInsertId();
    $item = Database::fetch('SELECT * FROM items WHERE id = :id', ['id' => $itemId]);
    characterization_assert(is_array($item), $suite, 'Could not read the test item.');

    $movementIds = [];
    $movementIds[] = apply_inventory_movement($item, 'restock', 10, null, $storageA, '2026-09-02 12:00:00', $prefix . '-R', 'restock', $ownerId, 'characterization', $itemId);
    $movementIds[] = apply_inventory_movement($item, 'usage', 3, $storageA, null, '2026-09-02 12:01:00', $prefix . '-U', 'usage', $ownerId, 'characterization', $itemId);
    $movementIds[] = apply_inventory_movement($item, 'transfer', 2, $storageA, $storageB, '2026-09-02 12:02:00', $prefix . '-T', 'transfer', $ownerId, 'characterization', $itemId);
    $movementIds[] = apply_inventory_movement($item, 'adjustment', -1, $storageB, null, '2026-09-02 12:03:00', $prefix . '-A', 'adjustment', $ownerId, 'characterization', $itemId);

    $balances = current_item_balance_map_for_update($itemId);
    characterization_assert(inventory_quantity((float) ($balances[$storageA] ?? -1)) === 5.0, $suite, 'Source balance rule changed.');
    characterization_assert(inventory_quantity((float) ($balances[$storageB] ?? -1)) === 1.0, $suite, 'Destination balance rule changed.');
    characterization_assert((float) Database::scalar('SELECT current_quantity FROM items WHERE id = :id', ['id' => $itemId]) === 6.0, $suite, 'Item snapshot is not synchronized to storage totals.');

    $movements = Database::fetchAll('SELECT movement_type, movement_quantity, quantity_delta, balance_after FROM inventory_movements WHERE item_id = :item_id ORDER BY id', ['item_id' => $itemId]);
    characterization_assert(array_column($movements, 'movement_type') === ['restock', 'usage', 'transfer', 'adjustment'], $suite, 'Movement history order/type changed.');
    characterization_assert(array_map('floatval', array_column($movements, 'quantity_delta')) === [10.0, -3.0, 0.0, -1.0], $suite, 'Movement delta rules changed.');

    $movementCount = count($movements);
    try {
        apply_inventory_movement($item, 'usage', 100, $storageA, null, '2026-09-02 12:04:00', $prefix . '-NO', 'insufficient', $ownerId, 'characterization', $itemId);
        characterization_fail($suite, 'Insufficient stock was accepted.');
    } catch (RuntimeException $exception) {
        characterization_assert(str_contains(strtolower($exception->getMessage()), 'negative'), $suite, 'Insufficient-stock failure changed.');
    }
    characterization_assert((int) Database::scalar('SELECT COUNT(*) FROM inventory_movements WHERE item_id = :item_id', ['item_id' => $itemId]) === $movementCount, $suite, 'Rejected stock created immutable history.');
    characterization_assert((float) Database::scalar('SELECT current_quantity FROM items WHERE id = :id', ['id' => $itemId]) === 6.0, $suite, 'Rejected stock changed the item snapshot.');

    $stockSource = (string) file_get_contents($root . '/app/modules/inventory_stock.php');
    characterization_assert(substr_count($stockSource, 'FOR UPDATE') >= 2, $suite, 'Item and storage row locks are missing.');
    foreach (glob($root . '/app/modules/*.php') ?: [] as $module) {
        $source = (string) file_get_contents($module);
        characterization_assert(preg_match('/\b(?:UPDATE|DELETE\s+FROM)\s+inventory_movements\b/i', $source) !== 1, $suite, 'Runtime module mutates immutable movement history: ' . basename($module));
    }
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

characterization_assert((int) Database::scalar('SELECT COUNT(*) FROM items WHERE sku LIKE :prefix', ['prefix' => $prefix . '%']) === $beforeRows, $suite, 'Test records were not rolled back.');
echo '[' . $suite . '] PASS' . PHP_EOL;
