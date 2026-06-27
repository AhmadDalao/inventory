<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$options = getopt('', ['apply', 'confirm:', 'help']);

if (isset($options['help'])) {
    echo "Usage: php scripts/cleanup_project_data.php [--apply --confirm=KEEP-KONA-ONLY]\n";
    echo "Dry-run by default. Keeps KONA, KONA OFFICE MAIN, hidden system storages, real users, and items assigned to retained storages.\n";
    exit(0);
}

require $root . '/app/bootstrap.php';

function cleanup_project_quote_in(array $values): string
{
    $values = array_values(array_unique(array_map('intval', $values)));

    if ($values === []) {
        return 'NULL';
    }

    return implode(',', $values);
}

function cleanup_project_table_exists(string $table): bool
{
    return (int) Database::scalar(
        'SELECT COUNT(*)
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name',
        ['table_name' => $table]
    ) > 0;
}

function cleanup_project_count(string $sql): int
{
    return (int) Database::scalar($sql);
}

function cleanup_project_exec_count(PDO $pdo, string $sql): int
{
    $statement = $pdo->prepare($sql);
    $statement->execute();

    return $statement->rowCount();
}

function cleanup_project_safe_path(?string $relativePath): ?string
{
    $relativePath = trim((string) $relativePath);

    if ($relativePath === '' || strpos($relativePath, '..') !== false || strpos($relativePath, "\0") !== false) {
        return null;
    }

    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');

    return base_path($relativePath);
}

function cleanup_project_file_candidates(array $retainedImageNames): array
{
    $paths = [];
    $retainedImageNames = array_fill_keys(array_map('basename', $retainedImageNames), true);

    if (cleanup_project_table_exists('file_assets')) {
        $rows = Database::fetchAll(
            'SELECT relative_path, archive_path
             FROM file_assets
             WHERE NOT (
                 source_type = "item_image"
                 AND source_id IN (
                     SELECT DISTINCT item.id
                     FROM items item
                     LEFT JOIN item_storage_balances balance ON balance.item_id = item.id
                     LEFT JOIN storages balance_storage ON balance_storage.id = balance.storage_id
                     LEFT JOIN storages default_storage ON default_storage.id = item.storage_id
                     WHERE balance_storage.name IN ("KONA", "KONA OFFICE MAIN")
                        OR default_storage.name IN ("KONA", "KONA OFFICE MAIN")
                 )
             )'
        );

        foreach ($rows as $row) {
            foreach (['relative_path', 'archive_path'] as $column) {
                $path = cleanup_project_safe_path($row[$column] ?? null);

                if ($path !== null) {
                    $paths[$path] = true;
                }
            }
        }
    }

    foreach (['storage/purchases', 'storage/workflows'] as $directory) {
        $absoluteDirectory = base_path($directory);

        if (!is_dir($absoluteDirectory)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($absoluteDirectory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $fileInfo) {
            if ($fileInfo instanceof SplFileInfo && $fileInfo->isFile()) {
                $paths[$fileInfo->getPathname()] = true;
            }
        }
    }

    $itemDirectory = base_path('uploads/items');

    if (is_dir($itemDirectory)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($itemDirectory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof SplFileInfo || !$fileInfo->isFile()) {
                continue;
            }

            if (!isset($retainedImageNames[$fileInfo->getFilename()])) {
                $paths[$fileInfo->getPathname()] = true;
            }
        }
    }

    return array_keys($paths);
}

function cleanup_project_delete_files(array $paths): int
{
    $deleted = 0;

    foreach ($paths as $path) {
        if (is_file($path) && @unlink($path)) {
            $deleted++;
        }
    }

    return $deleted;
}

$apply = isset($options['apply']);
$confirm = (string) ($options['confirm'] ?? '');

if ($apply && $confirm !== 'KEEP-KONA-ONLY') {
    fwrite(STDERR, "Refusing to apply cleanup without --confirm=KEEP-KONA-ONLY\n");
    exit(1);
}

$keepStorageNames = ['KONA', 'KONA OFFICE MAIN'];
$keepStorages = Database::fetchAll(
    'SELECT id, name
     FROM storages
     WHERE name IN ("KONA", "KONA OFFICE MAIN")
       AND is_system = 0
     ORDER BY name ASC'
);
$keepStorageIds = array_map(static fn (array $row): int => (int) $row['id'], $keepStorages);

if (count($keepStorageIds) !== 2) {
    throw new RuntimeException('Expected to find exactly KONA and KONA OFFICE MAIN storages before cleanup.');
}

$systemStorageIds = array_map(
    static fn (array $row): int => (int) $row['id'],
    Database::fetchAll('SELECT id FROM storages WHERE is_system = 1 ORDER BY id ASC')
);
$keepItemRows = Database::fetchAll(
    'SELECT DISTINCT item.id,
            item.name,
            item.sku,
            item.image_path,
            item.storage_id
     FROM items item
     LEFT JOIN item_storage_balances balance ON balance.item_id = item.id
     WHERE item.storage_id IN (' . cleanup_project_quote_in($keepStorageIds) . ')
        OR balance.storage_id IN (' . cleanup_project_quote_in($keepStorageIds) . ')
     ORDER BY item.name ASC'
);
$keepItemIds = array_map(static fn (array $row): int => (int) $row['id'], $keepItemRows);
$retainedImageNames = array_values(array_filter(array_map(
    static fn (array $row): string => basename((string) ($row['image_path'] ?? '')),
    $keepItemRows
)));
$fileCandidates = cleanup_project_file_candidates($retainedImageNames);
$keepStorageIdList = cleanup_project_quote_in($keepStorageIds);
$keepItemIdList = cleanup_project_quote_in($keepItemIds);
$deleteUserWhere = '(name LIKE "ZZ%" OR email LIKE "zz%@%" OR email LIKE "zz%")';

$systemBalancesToFold = Database::fetchAll(
    'SELECT balance.item_id,
            item.name AS item_name,
            item.sku AS item_sku,
            item.storage_id AS item_storage_id,
            balance.storage_id AS system_storage_id,
            storage.name AS system_storage_name,
            balance.quantity
     FROM item_storage_balances balance
     INNER JOIN items item ON item.id = balance.item_id
     INNER JOIN storages storage ON storage.id = balance.storage_id
     WHERE balance.item_id IN (' . $keepItemIdList . ')
       AND storage.is_system = 1
       AND balance.quantity > 0
     ORDER BY item.name ASC, storage.name ASC'
);

$countsBefore = [];
$tables = [
    'users',
    'storages',
    'items',
    'item_storage_balances',
    'inventory_movements',
    'item_requests',
    'item_request_lines',
    'handovers',
    'handover_lines',
    'workflow_documents',
    'suppliers',
    'purchases',
    'purchase_lines',
    'purchase_documents',
    'stocktakes',
    'stocktake_lines',
    'file_assets',
    'notifications',
    'activity_logs',
    'email_delivery_logs',
    'login_attempts',
    'password_reset_tokens',
];

foreach ($tables as $table) {
    if (cleanup_project_table_exists($table)) {
        $countsBefore[$table] = cleanup_project_count('SELECT COUNT(*) FROM `' . $table . '`');
    }
}

$plannedDeletes = [
    'workflow_documents' => cleanup_project_count('SELECT COUNT(*) FROM workflow_documents'),
    'purchase_documents' => cleanup_project_count('SELECT COUNT(*) FROM purchase_documents'),
    'purchase_lines' => cleanup_project_count('SELECT COUNT(*) FROM purchase_lines'),
    'purchases' => cleanup_project_count('SELECT COUNT(*) FROM purchases'),
    'item_request_lines' => cleanup_project_count('SELECT COUNT(*) FROM item_request_lines'),
    'item_requests' => cleanup_project_count('SELECT COUNT(*) FROM item_requests'),
    'handover_lines' => cleanup_project_count('SELECT COUNT(*) FROM handover_lines'),
    'handovers' => cleanup_project_count('SELECT COUNT(*) FROM handovers'),
    'stocktake_lines' => cleanup_project_count('SELECT COUNT(*) FROM stocktake_lines'),
    'stocktakes' => cleanup_project_count('SELECT COUNT(*) FROM stocktakes'),
    'inventory_movements' => cleanup_project_count('SELECT COUNT(*) FROM inventory_movements'),
    'notifications' => cleanup_project_count('SELECT COUNT(*) FROM notifications'),
    'suppliers' => cleanup_project_count('SELECT COUNT(*) FROM suppliers'),
    'email_delivery_logs' => cleanup_project_count('SELECT COUNT(*) FROM email_delivery_logs'),
    'activity_logs' => cleanup_project_count('SELECT COUNT(*) FROM activity_logs'),
    'login_attempts' => cleanup_project_count('SELECT COUNT(*) FROM login_attempts'),
    'password_reset_tokens' => cleanup_project_count('SELECT COUNT(*) FROM password_reset_tokens'),
    'file_assets' => cleanup_project_count('SELECT COUNT(*) FROM file_assets WHERE NOT (source_type = "item_image" AND source_id IN (' . $keepItemIdList . '))'),
    'item_storage_balances' => cleanup_project_count('SELECT COUNT(*) FROM item_storage_balances WHERE storage_id NOT IN (' . $keepStorageIdList . ') OR item_id NOT IN (' . $keepItemIdList . ')'),
    'items' => cleanup_project_count('SELECT COUNT(*) FROM items WHERE id NOT IN (' . $keepItemIdList . ')'),
    'storages' => cleanup_project_count('SELECT COUNT(*) FROM storages WHERE is_system = 0 AND id NOT IN (' . $keepStorageIdList . ')'),
    'users' => cleanup_project_count('SELECT COUNT(*) FROM users WHERE ' . $deleteUserWhere),
];

$result = [
    'ok' => true,
    'mode' => $apply ? 'apply' : 'dry-run',
    'keep_storage_names' => $keepStorageNames,
    'keep_storage_ids' => $keepStorageIds,
    'system_storage_ids_kept' => $systemStorageIds,
    'keep_item_count' => count($keepItemIds),
    'keep_items' => array_map(static function (array $row): array {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'sku' => (string) $row['sku'],
            'image_path' => (string) ($row['image_path'] ?? ''),
        ];
    }, $keepItemRows),
    'system_balances_to_fold_back' => $systemBalancesToFold,
    'files_to_delete' => count($fileCandidates),
    'counts_before' => $countsBefore,
    'planned_deletes' => $plannedDeletes,
];

if (!$apply) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

$pdo = Database::connection();
$pdo->beginTransaction();
$deletedRows = [];

try {
    foreach ($systemBalancesToFold as $row) {
        $itemId = (int) $row['item_id'];
        $quantity = (float) $row['quantity'];
        $destinationStorageId = in_array((int) $row['item_storage_id'], $keepStorageIds, true)
            ? (int) $row['item_storage_id']
            : $keepStorageIds[0];

        if ($quantity <= 0) {
            continue;
        }

        $statement = $pdo->prepare(
            'INSERT INTO item_storage_balances (item_id, storage_id, quantity, created_at, updated_at)
             VALUES (:item_id, :storage_id, :quantity, NOW(), NOW())
             ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity), updated_at = NOW()'
        );
        $statement->execute([
            'item_id' => $itemId,
            'storage_id' => $destinationStorageId,
            'quantity' => $quantity,
        ]);
    }

    foreach ([
        'workflow_documents',
        'purchase_documents',
        'purchase_lines',
        'purchases',
        'item_request_lines',
        'item_requests',
        'handover_lines',
        'handovers',
        'stocktake_lines',
        'stocktakes',
        'inventory_movements',
        'notifications',
        'suppliers',
        'email_delivery_logs',
        'activity_logs',
        'login_attempts',
        'password_reset_tokens',
    ] as $table) {
        if (cleanup_project_table_exists($table)) {
            $deletedRows[$table] = cleanup_project_exec_count($pdo, 'DELETE FROM `' . $table . '`');
        }
    }

    if (cleanup_project_table_exists('file_assets')) {
        $deletedRows['file_assets'] = cleanup_project_exec_count(
            $pdo,
            'DELETE FROM file_assets
             WHERE NOT (source_type = "item_image" AND source_id IN (' . $keepItemIdList . '))'
        );
    }

    $deletedRows['item_storage_balances'] = cleanup_project_exec_count(
        $pdo,
        'DELETE FROM item_storage_balances
         WHERE storage_id NOT IN (' . $keepStorageIdList . ')
            OR item_id NOT IN (' . $keepItemIdList . ')'
    );
    $deletedRows['items'] = cleanup_project_exec_count($pdo, 'DELETE FROM items WHERE id NOT IN (' . $keepItemIdList . ')');

    foreach ($keepItemRows as $itemRow) {
        $itemId = (int) $itemRow['id'];
        $storageId = in_array((int) $itemRow['storage_id'], $keepStorageIds, true)
            ? (int) $itemRow['storage_id']
            : (int) Database::scalar(
                'SELECT storage_id
                 FROM item_storage_balances
                 WHERE item_id = :item_id
                   AND storage_id IN (' . $keepStorageIdList . ')
                 ORDER BY quantity DESC, storage_id ASC
                 LIMIT 1',
                ['item_id' => $itemId]
            );

        if ($storageId <= 0) {
            $storageId = $keepStorageIds[0];
        }

        $statement = $pdo->prepare(
            'INSERT INTO item_storage_balances (item_id, storage_id, quantity, created_at, updated_at)
             VALUES (:item_id, :storage_id, 0, NOW(), NOW())
             ON DUPLICATE KEY UPDATE updated_at = updated_at'
        );
        $statement->execute([
            'item_id' => $itemId,
            'storage_id' => $storageId,
        ]);

        $statement = $pdo->prepare(
            'UPDATE items
             SET storage_id = :storage_id,
                 current_quantity = (
                     SELECT COALESCE(SUM(balance.quantity), 0)
                     FROM item_storage_balances balance
                     WHERE balance.item_id = items.id
                       AND balance.storage_id IN (' . $keepStorageIdList . ')
                 ),
                 updated_at = NOW()
             WHERE id = :item_id'
        );
        $statement->execute([
            'storage_id' => $storageId,
            'item_id' => $itemId,
        ]);
    }

    $deletedRows['storages'] = cleanup_project_exec_count(
        $pdo,
        'DELETE FROM storages WHERE is_system = 0 AND id NOT IN (' . $keepStorageIdList . ')'
    );
    $deletedRows['users'] = cleanup_project_exec_count($pdo, 'DELETE FROM users WHERE ' . $deleteUserWhere);

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    throw $exception;
}

$countsAfter = [];

foreach ($tables as $table) {
    if (cleanup_project_table_exists($table)) {
        $countsAfter[$table] = cleanup_project_count('SELECT COUNT(*) FROM `' . $table . '`');
    }
}

$result['deleted_rows'] = $deletedRows;
$result['deleted_files'] = cleanup_project_delete_files($fileCandidates);
$result['counts_after'] = $countsAfter;

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
