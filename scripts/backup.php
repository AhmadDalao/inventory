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

function backup_add_directory_to_zip(ZipArchive $zip, string $directory, string $archivePrefix): void
{
    if (!is_dir($directory)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo instanceof SplFileInfo || $fileInfo->isDir()) {
            continue;
        }

        $path = $fileInfo->getPathname();
        $relativePath = ltrim(str_replace('\\', '/', substr($path, strlen($directory))), '/');

        if ($relativePath === '') {
            continue;
        }

        $zip->addFile($path, rtrim($archivePrefix, '/') . '/' . $relativePath);
    }
}

function backup_cleanup_old_files(string $backupDir, int $retentionDays, array $keepFiles): array
{
    $deleted = [];
    $cutoff = time() - ($retentionDays * 86400);
    $files = glob(rtrim($backupDir, '/') . '/inventory-backup-*');

    if (!is_array($files)) {
        return $deleted;
    }

    foreach ($files as $file) {
        if (in_array($file, $keepFiles, true) || !is_file($file)) {
            continue;
        }

        $mtime = filemtime($file);

        if ($mtime !== false && $mtime < $cutoff && @unlink($file)) {
            $deleted[] = $file;
        }
    }

    return $deleted;
}

$backupDir = base_path('storage/backups');
ensure_directory_exists($backupDir);

$retentionDays = max(1, min(365, (int) site_setting('backup.retention_days', '14')));
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

if ($includeUploads) {
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            backup_add_directory_to_zip($zip, base_path('uploads'), 'uploads');
            backup_add_directory_to_zip($zip, base_path('storage/files'), 'storage/files');
            $zip->close();
            $includedFilesArchive = $zipPath;
        } else {
            $warnings[] = 'Could not create uploaded-files zip archive.';
        }
    } else {
        $warnings[] = 'ZipArchive is not installed; SQL backup was created without uploaded files.';
    }
}

$keepFiles = [$sqlPath, $manifestPath];

if ($includedFilesArchive !== null) {
    $keepFiles[] = $includedFilesArchive;
}

$deletedOldFiles = backup_cleanup_old_files($backupDir, $retentionDays, $keepFiles);

$manifest = [
    'created_at' => date('c'),
    'app_url' => app_config('app.url', ''),
    'database' => app_config('db.database', ''),
    'sql_path' => $sqlPath,
    'files_archive_path' => $includedFilesArchive,
    'retention_days' => $retentionDays,
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
    'warnings' => $warnings,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
