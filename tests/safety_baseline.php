<?php
declare(strict_types=1);

$options = getopt('', ['base-url:', 'passes::']);
if (!isset($options['base-url'])) {
    fwrite(STDERR, "Usage: php tests/safety_baseline.php --base-url=http://127.0.0.1:8080 [--passes=2]\n");
    exit(1);
}

$root = dirname(__DIR__);
$baseUrl = rtrim((string) $options['base-url'], '/');
$host = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));
$passes = max(2, min(5, (int) ($options['passes'] ?? 2)));
if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
    fwrite(STDERR, "[safety-baseline] FAIL: The aggregate baseline may run only against an isolated loopback server.\n");
    exit(1);
}

require $root . '/app/bootstrap.php';
require $root . '/app/modules.php';
require $root . '/scripts/backup_helpers.php';

function safety_fail(string $message): never
{
    fwrite(STDERR, '[safety-baseline] FAIL: ' . $message . PHP_EOL);
    exit(1);
}

function safety_table_snapshot(): array
{
    $pdo = Database::connection();
    $tables = $pdo->query(
        'SELECT TABLE_NAME AS table_name
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = "BASE TABLE"
         ORDER BY TABLE_NAME'
    )->fetchAll(PDO::FETCH_COLUMN);
    $snapshot = [];

    foreach ($tables as $table) {
        $table = (string) $table;
        $quoted = '`' . str_replace('`', '``', $table) . '`';
        $rows = $pdo->query('SELECT * FROM ' . $quoted)->fetchAll(PDO::FETCH_ASSOC);
        $normalized = array_map(static function (array $row): array {
            ksort($row);
            return $row;
        }, $rows);
        usort($normalized, static fn (array $left, array $right): int => strcmp(
            json_encode($left, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '',
            json_encode($right, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''
        ));
        $snapshot[$table] = [
            'rows' => count($normalized),
            'sha256' => hash('sha256', json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) ?: ''),
        ];
    }

    return $snapshot;
}

function safety_files_snapshot(string $root): array
{
    $files = backup_collect_source_entries(backup_required_durable_paths($root))['files'];
    $snapshot = [];
    foreach ($files as $relativePath => $absolutePath) {
        $size = filesize($absolutePath);
        $hash = hash_file('sha256', $absolutePath);
        if ($size === false || $hash === false) {
            safety_fail('Could not snapshot durable file: ' . $relativePath);
        }
        $snapshot[$relativePath] = ['bytes' => (int) $size, 'sha256' => $hash];
    }

    return $snapshot;
}

function safety_state_snapshot(string $root): array
{
    return [
        'database' => safety_table_snapshot(),
        'files' => safety_files_snapshot($root),
        'stock' => [
            'negative_balances' => (int) Database::scalar('SELECT COUNT(*) FROM item_storage_balances WHERE quantity < 0'),
            'snapshot_drift' => (int) Database::scalar('SELECT COUNT(*) FROM items item LEFT JOIN (SELECT item_id, SUM(quantity) quantity FROM item_storage_balances GROUP BY item_id) balances ON balances.item_id = item.id WHERE ABS(item.current_quantity - COALESCE(balances.quantity, 0)) > 0.000001'),
            'orphan_movements' => (int) Database::scalar('SELECT COUNT(*) FROM inventory_movements movement LEFT JOIN items item ON item.id = movement.item_id WHERE item.id IS NULL'),
        ],
    ];
}

function safety_run(array $command, string $root): void
{
    echo '[safety-baseline] RUN ' . implode(' ', array_map('escapeshellarg', $command)) . PHP_EOL;
    $process = proc_open($command, [
        0 => ['file', '/dev/null', 'r'],
        1 => STDOUT,
        2 => STDERR,
    ], $pipes, $root, getenv());
    if (!is_resource($process)) {
        safety_fail('Could not start: ' . implode(' ', $command));
    }
    $status = proc_close($process);
    if ($status !== 0) {
        safety_fail('Command exited ' . $status . ': ' . implode(' ', $command));
    }
}

$php = PHP_BINARY;
$node = (string) (getenv('NODE_BINARY') ?: trim((string) shell_exec('command -v node 2>/dev/null')));
if ($node === '' || !is_executable($node)) {
    safety_fail('Set NODE_BINARY to a working Node.js executable.');
}

$staticTests = [
    'auth_login_contract.php',
    'backup_archive.php',
    'daily_summary_export_contract.php',
    'filter_export_contract.php',
    'frontend_assets.php',
    'handover_department_snapshot_contract.php',
    'measured_inventory.php',
    'mobile_api_contract.php',
    'mobile_usage_reasons.php',
    'module_boundaries.php',
    'ocr_parser_contract.php',
    'persistent_package_wristband_contract.php',
    'report_summary_contract.php',
    'team_hierarchy.php',
    'wristband_api_contract.php',
    'wristband_code_performance.php',
    'routes_characterization.php',
    'architecture_characterization.php',
    'permissions_characterization.php',
    'workflows_characterization.php',
    'units_characterization.php',
    'schema_characterization.php',
    'stock_movement_characterization.php',
    'files_exports_characterization.php',
];

$initial = safety_state_snapshot($root);
if ($initial['stock'] !== ['negative_balances' => 0, 'snapshot_drift' => 0, 'orphan_movements' => 0]) {
    safety_fail('Initial stock invariants are not clean.');
}

for ($pass = 1; $pass <= $passes; $pass++) {
    echo '[safety-baseline] PASS ' . $pass . ' OF ' . $passes . PHP_EOL;
    foreach ($staticTests as $test) {
        safety_run([$php, $root . '/tests/' . $test], $root);
    }
    safety_run([$node, $root . '/tests/frontend_registry_characterization.mjs'], $root);
    safety_run([$php, $root . '/tests/api_v1_characterization.php', '--base-url=' . $baseUrl], $root);
    safety_run([$php, $root . '/tests/departments_regression.php', '--base-url=' . $baseUrl, '--prefix=ZZBASEDEPTP' . $pass], $root);
    safety_run([$php, $root . '/tests/mobile_api_live.php', '--base-url=' . $baseUrl, '--prefix=ZZBASEMOBILEP' . $pass], $root);
    safety_run([$php, $root . '/tests/full_regression.php', '--base-url=' . $baseUrl, '--prefix=ZZBASEFULLP' . $pass], $root);
    safety_run([$php, $root . '/tests/wristband_workflow.php', '--require-db'], $root);
    safety_run([$php, $root . '/tests/stock_invariants.php'], $root);

    $after = safety_state_snapshot($root);
    if ($after !== $initial) {
        foreach (['database', 'files', 'stock'] as $section) {
            if ($after[$section] !== $initial[$section]) {
                fwrite(STDERR, '[safety-baseline] Changed state section: ' . $section . PHP_EOL);
                if ($section === 'database') {
                    foreach (array_unique(array_merge(array_keys($initial[$section]), array_keys($after[$section]))) as $table) {
                        if (($initial[$section][$table] ?? null) !== ($after[$section][$table] ?? null)) {
                            fwrite(STDERR, '[safety-baseline] Changed table: ' . $table . PHP_EOL);
                        }
                    }
                }
            }
        }
        safety_fail('A test changed persistent state or failed cleanup in pass ' . $pass . '.');
    }
    echo '[safety-baseline] PASS ' . $pass . ' CLEANUP VERIFIED' . PHP_EOL;
}

echo '[safety-baseline] PASS (' . $passes . ' consecutive clean runs)' . PHP_EOL;
