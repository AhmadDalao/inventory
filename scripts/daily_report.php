<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$options = getopt('', ['date::', 'dry-run', 'force', 'help']);

if (isset($options['help'])) {
    echo "Usage: php scripts/daily_report.php [--date=YYYY-MM-DD] [--force] [--dry-run]\n";
    echo "Creates a JSON and CSV operational inventory report under storage/reports.\n";
    exit(0);
}

if (isset($options['dry-run'])) {
    echo json_encode([
        'ok' => true,
        'mode' => 'dry-run',
        'report_dir' => $root . '/storage/reports',
        'message' => 'No database connection or file writes were attempted.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

require $root . '/app/bootstrap.php';

if (site_setting('reports.daily_enabled', '1') !== '1' && !isset($options['force'])) {
    echo json_encode([
        'ok' => true,
        'skipped' => true,
        'message' => 'Daily reports are disabled in Website Control.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$date = trim((string) ($options['date'] ?? date('Y-m-d')));
$dateObject = DateTimeImmutable::createFromFormat('Y-m-d', $date);

if (!$dateObject || $dateObject->format('Y-m-d') !== $date) {
    fwrite(STDERR, "Invalid --date. Use YYYY-MM-DD.\n");
    exit(1);
}

$reportDir = base_path('storage/reports');
ensure_directory_exists($reportDir);

$start = $date . ' 00:00:00';
$end = $date . ' 23:59:59';

$metrics = [
    'date' => $date,
    'generated_at' => date('c'),
    'active_items' => (int) Database::scalar('SELECT COUNT(*) FROM items WHERE is_active = 1'),
    'active_storages' => (int) Database::scalar('SELECT COUNT(*) FROM storages WHERE is_active = 1 AND is_system = 0'),
    'stock_units' => (float) Database::scalar(
        'SELECT COALESCE(SUM(balance.quantity), 0)
         FROM item_storage_balances balance
         INNER JOIN items item ON item.id = balance.item_id AND item.is_active = 1
         INNER JOIN storages storage ON storage.id = balance.storage_id AND storage.is_active = 1'
    ),
    'inventory_value' => (float) Database::scalar(
        'SELECT COALESCE(SUM(balance.quantity * item.cost_per_unit), 0)
         FROM item_storage_balances balance
         INNER JOIN items item ON item.id = balance.item_id AND item.is_active = 1
         INNER JOIN storages storage ON storage.id = balance.storage_id AND storage.is_active = 1'
    ),
    'low_stock_lines' => (int) Database::scalar(
        'SELECT COUNT(*)
         FROM item_storage_balances balance
         INNER JOIN items item ON item.id = balance.item_id AND item.is_active = 1
         INNER JOIN storages storage ON storage.id = balance.storage_id AND storage.is_active = 1
         WHERE item.reorder_level > 0
           AND balance.quantity <= item.reorder_level'
    ),
    'open_requests' => (int) Database::scalar(
        'SELECT COUNT(*) FROM item_requests WHERE status IN ("pending", "approved", "receipt_review")'
    ),
    'open_handovers' => (int) Database::scalar(
        'SELECT COUNT(*) FROM handovers WHERE status IN ("requested", "awaiting_receipt", "receipt_review", "delivered", "pending_approval")'
    ),
    'open_purchases' => (int) Database::scalar(
        'SELECT COUNT(*) FROM purchases WHERE status IN ("draft", "pending_approval", "approved", "receipt_review")'
    ),
    'open_stocktakes' => (int) Database::scalar(
        'SELECT COUNT(*) FROM stocktakes WHERE status IN ("draft", "pending_approval")'
    ),
    'usage_units_for_day' => (float) Database::scalar(
        'SELECT COALESCE(SUM(ABS(quantity_delta)), 0)
         FROM inventory_movements
         WHERE movement_type = "usage"
           AND used_at BETWEEN :start_at AND :end_at',
        [
            'start_at' => $start,
            'end_at' => $end,
        ]
    ),
    'restock_units_for_day' => (float) Database::scalar(
        'SELECT COALESCE(SUM(movement_quantity), 0)
         FROM inventory_movements
         WHERE movement_type = "restock"
           AND used_at BETWEEN :start_at AND :end_at',
        [
            'start_at' => $start,
            'end_at' => $end,
        ]
    ),
];

$topUsage = Database::fetchAll(
    'SELECT item.name,
            item.sku,
            item.unit,
            COALESCE(SUM(ABS(movement.quantity_delta)), 0) AS used_quantity
     FROM inventory_movements movement
     INNER JOIN items item ON item.id = movement.item_id
     WHERE movement.movement_type = "usage"
       AND movement.used_at BETWEEN :start_at AND :end_at
     GROUP BY item.id, item.name, item.sku, item.unit
     ORDER BY used_quantity DESC, item.name ASC
     LIMIT 10',
    [
        'start_at' => $start,
        'end_at' => $end,
    ]
);

$pendingPurchases = Database::fetchAll(
    'SELECT purchase.purchase_number,
            purchase.status,
            supplier.name AS supplier_name,
            storage.name AS storage_name,
            purchase.created_at
     FROM purchases purchase
     INNER JOIN suppliers supplier ON supplier.id = purchase.supplier_id
     INNER JOIN storages storage ON storage.id = purchase.destination_storage_id
     WHERE purchase.status IN ("draft", "pending_approval", "approved", "receipt_review")
     ORDER BY purchase.created_at DESC
     LIMIT 10'
);

$report = [
    'metrics' => $metrics,
    'top_usage' => $topUsage,
    'pending_purchases' => $pendingPurchases,
];

$baseName = 'daily-inventory-report-' . $date;
$jsonPath = $reportDir . '/' . $baseName . '.json';
$csvPath = $reportDir . '/' . $baseName . '.csv';

file_put_contents($jsonPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

$csv = fopen($csvPath, 'wb');

if ($csv === false) {
    throw new RuntimeException('Could not create report CSV.');
}

fputcsv($csv, ['Metric', 'Value']);

foreach ($metrics as $key => $value) {
    fputcsv($csv, [$key, $value]);
}

fputcsv($csv, []);
fputcsv($csv, ['Top Usage Item', 'SKU', 'Unit', 'Used Quantity']);

foreach ($topUsage as $row) {
    fputcsv($csv, [
        $row['name'] ?? '',
        $row['sku'] ?? '',
        $row['unit'] ?? '',
        $row['used_quantity'] ?? 0,
    ]);
}

fputcsv($csv, []);
fputcsv($csv, ['Pending Purchase', 'Status', 'Supplier', 'Storage', 'Created At']);

foreach ($pendingPurchases as $row) {
    fputcsv($csv, [
        $row['purchase_number'] ?? '',
        $row['status'] ?? '',
        $row['supplier_name'] ?? '',
        $row['storage_name'] ?? '',
        $row['created_at'] ?? '',
    ]);
}

fclose($csv);

echo json_encode([
    'ok' => true,
    'json_path' => $jsonPath,
    'csv_path' => $csvPath,
    'metrics' => $metrics,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
