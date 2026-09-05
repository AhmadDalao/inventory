<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$suite = 'schema-characterization';

require $root . '/app/bootstrap.php';
require __DIR__ . '/support/characterization.php';

$expected = characterization_fixture($root, 'schema');
$actual = characterization_schema_contract(Database::connection());
characterization_assert($actual === $expected, $suite, 'Tables, columns, indexes, or foreign keys changed. Review and regenerate intentionally.');

$columns = [];
foreach ($actual['columns'] as $column) {
    $columns[$column['table_name']][$column['column_name']] = true;
}
$critical = [
    'item_storage_balances' => ['item_id', 'storage_id', 'quantity'],
    'inventory_movements' => ['item_id', 'movement_type', 'quantity_delta', 'balance_after', 'source_storage_id', 'destination_storage_id'],
    'items' => ['current_quantity', 'measurement_dimension', 'usage_proof_policy', 'refill_proof_policy'],
    'file_assets' => ['relative_path', 'archive_path', 'deleted_at'],
    'position_templates' => ['code', 'name', 'access_role', 'default_department_id', 'is_active', 'archived_at'],
    'position_template_permissions' => ['position_template_id', 'permission_key'],
];
foreach ($critical as $table => $requiredColumns) {
    foreach ($requiredColumns as $column) {
        characterization_assert(isset($columns[$table][$column]), $suite, 'Critical schema column is missing: ' . $table . '.' . $column);
    }
}

echo '[' . $suite . '] PASS (' . count($actual['columns']) . ' columns, ' . count($actual['indexes']) . ' index rows, ' . count($actual['foreign_keys']) . ' foreign keys)' . PHP_EOL;
