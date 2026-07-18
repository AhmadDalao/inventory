<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$options = getopt('', ['dry-run', 'help']);

if (isset($options['help'])) {
    echo "Usage: php scripts/backup.php [--dry-run]\n";
    echo "Creates a SQL backup and, when enabled, a zip archive of uploads and protected files.\n";
    exit(0);
}

if (isset($options['dry-run'])) {
    echo json_encode([
        'ok' => true,
        'mode' => 'dry-run',
        'backup_dir' => $root . '/storage/backups',
        'message' => 'No database connection or file writes were attempted.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

require $root . '/app/bootstrap.php';
require $root . '/scripts/backup_helpers.php';

function backup_quote_identifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function backup_quote_value(PDO $pdo, $value): string
{
    if ($value === null) {
        return 'NULL';
    }

    return $pdo->quote((string) $value);
}

$backupDir = base_path('storage/backups');
ensure_directory_exists($backupDir);

$retentionDays = max(1, min(365, (int) site_setting('backup.retention_days', '14')));
$maxSets = max(2, min(100, (int) site_setting('backup.max_sets', '30')));
$includeUploads = site_setting('backup.include_uploads', '1') === '1';
$timestamp = date('Ymd-His');
$baseName = 'inventory-backup-' . $timestamp;
$sqlPath = $backupDir . '/' . $baseName . '.sql';
$manifestPath = $backupDir . '/' . $baseName . '.manifest.json';
$zipPath = $backupDir . '/' . $baseName . '.files.zip';
$pdo = Database::connection();
$tableRows = Database::fetchAll(
    'SELECT TABLE_NAME AS table_name
     FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
     ORDER BY TABLE_NAME ASC'
);

$handle = fopen($sqlPath, 'wb');

if ($handle === false) {
    throw new RuntimeException('Could not create backup SQL file.');
}

$tableCounts = [];

fwrite($handle, "-- Inventory backup created " . date('c') . "\n");
fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");

foreach ($tableRows as $tableRow) {
    $tableName = (string) ($tableRow['table_name'] ?? '');

    if ($tableName === '') {
        continue;
    }

    $quotedTable = backup_quote_identifier($tableName);
    $createRow = Database::fetch('SHOW CREATE TABLE ' . $quotedTable);
    $createStatement = (string) ($createRow['Create Table'] ?? '');
    $count = (int) Database::scalar('SELECT COUNT(*) FROM ' . $quotedTable);
    $tableCounts[$tableName] = $count;

    fwrite($handle, "\n-- Table {$tableName}\n");
    fwrite($handle, 'DROP TABLE IF EXISTS ' . $quotedTable . ";\n");

    if ($createStatement !== '') {
        fwrite($handle, $createStatement . ";\n\n");
    }

    $statement = $pdo->query('SELECT * FROM ' . $quotedTable);

    if (!$statement instanceof PDOStatement) {
        continue;
    }

    while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
        $columns = array_map('backup_quote_identifier', array_keys($row));
        $values = array_map(static function ($value) use ($pdo): string {
            return backup_quote_value($pdo, $value);
        }, array_values($row));

        fwrite($handle, 'INSERT INTO ' . $quotedTable . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ");\n");
    }
}

fwrite($handle, "\nSET FOREIGN_KEY_CHECKS=1;\n");
fclose($handle);

$warnings = [];
$includedFilesArchive = null;
$filesArchivedCount = 0;
$filesArchiveBytes = 0;
$deletedOldFiles = backup_cleanup_old_sets($backupDir, $retentionDays, $maxSets, [$baseName]);

if ($includeUploads) {
    $archiveResult = backup_create_files_archive(
        $zipPath,
        [
            'uploads' => base_path('uploads'),
            'storage/files' => base_path('storage/files'),
        ],
        [
            'app_url' => app_config('app.url', ''),
            'database' => app_config('db.database', ''),
            'sql_backup' => basename($sqlPath),
        ]
    );

    $filesArchivedCount = (int) ($archiveResult['files_count'] ?? 0);
    $filesArchiveBytes = (int) ($archiveResult['archive_bytes'] ?? 0);

    if (!empty($archiveResult['ok'])) {
        $includedFilesArchive = $zipPath;
    } elseif (!empty($archiveResult['warning'])) {
        $warnings[] = (string) $archiveResult['warning'];
    }
}

$manifest = [
    'created_at' => date('c'),
    'app_url' => app_config('app.url', ''),
    'database' => app_config('db.database', ''),
    'sql_path' => $sqlPath,
    'files_archive_path' => $includedFilesArchive,
    'files_archived_count' => $filesArchivedCount,
    'files_archive_bytes' => $filesArchiveBytes,
    'retention_days' => $retentionDays,
    'max_backup_sets' => $maxSets,
    'include_uploads' => $includeUploads,
    'table_counts' => $tableCounts,
    'deleted_old_files' => $deletedOldFiles,
    'warnings' => $warnings,
];

file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

echo json_encode([
    'ok' => true,
    'sql_path' => $sqlPath,
    'manifest_path' => $manifestPath,
    'files_archive_path' => $includedFilesArchive,
    'files_archived_count' => $filesArchivedCount,
    'files_archive_bytes' => $filesArchiveBytes,
    'deleted_old_files_count' => count($deletedOldFiles),
    'warnings' => $warnings,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
