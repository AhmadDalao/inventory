<?php
declare(strict_types=1);

function wristband_registry_filters(): array
{
    $state = strtolower(trim((string) ($_GET['state'] ?? 'available')));
    if (!in_array($state, ['available', 'used', 'void', 'all'], true)) {
        $state = 'available';
    }

    return [
        'search' => trim((string) ($_GET['search'] ?? '')),
        'state' => $state,
        'item_id' => max(0, (int) ($_GET['item_id'] ?? 0)),
    ];
}

function wristband_registry_rows(array $filters, int $limit = 500): array
{
    $where = [];
    $params = [];
    if (($filters['state'] ?? 'available') !== 'all') {
        $where[] = 'code.state = :state';
        $params['state'] = (string) $filters['state'];
    }
    if ((int) ($filters['item_id'] ?? 0) > 0) {
        $where[] = 'code.item_id = :item_id';
        $params['item_id'] = (int) $filters['item_id'];
    }
    if ((string) ($filters['search'] ?? '') !== '') {
        $where[] = '(code.code_masked LIKE :search OR code.code_hash = :search_hash OR item.name LIKE :search OR item.sku LIKE :search OR import.import_number LIKE :search)';
        $params['search'] = '%' . (string) $filters['search'] . '%';
        $params['search_hash'] = wristband_code_hash((string) $filters['search']);
    }

    return Database::fetchAll(
        'SELECT code.*, item.name AS item_name, item.sku, item.image_path, item.unit,
                import.import_number, import.source_filename, session.session_number,
                handover.id AS handover_id, handover.handover_number
         FROM wristband_codes code
         INNER JOIN items item ON item.id = code.item_id
         LEFT JOIN wristband_imports import ON import.id = code.import_id
         LEFT JOIN wristband_sessions session ON session.id = code.used_session_id
         LEFT JOIN handovers handover ON handover.id = session.handover_id'
         . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
         . ' ORDER BY code.id DESC LIMIT ' . max(1, min(2000, $limit)),
        $params
    );
}

function wristband_registry_counts(): array
{
    $rows = Database::fetchAll('SELECT state, COUNT(*) AS total FROM wristband_codes GROUP BY state');
    $counts = ['available' => 0, 'used' => 0, 'void' => 0, 'all' => 0];
    foreach ($rows as $row) {
        $state = (string) $row['state'];
        $counts[$state] = (int) $row['total'];
        $counts['all'] += (int) $row['total'];
    }

    return $counts;
}

function wristband_item_registry_summary(): array
{
    return Database::fetchAll(
        'SELECT item.id, item.name, item.sku, item.image_path, item.unit,
                item.current_quantity AS physical_total,
                SUM(code.state = "available") AS available_codes,
                SUM(code.state = "used") AS used_codes,
                SUM(code.state = "void") AS void_codes,
                COUNT(code.id) AS registered_codes
         FROM items item
         LEFT JOIN wristband_codes code ON code.item_id = item.id
         WHERE item.external_qr_tracking_enabled = 1
           AND item.measurement_dimension = "count"
         GROUP BY item.id
         ORDER BY item.name ASC, item.id ASC'
    );
}

function wristband_import_history(): array
{
    return Database::fetchAll(
        'SELECT import.*, item.name AS selected_item_name, item.sku AS selected_item_sku,
                user.name AS created_by_name
         FROM wristband_imports import
         LEFT JOIN items item ON item.id = import.selected_item_id
         LEFT JOIN users user ON user.id = import.created_by
         ORDER BY import.id DESC
         LIMIT 200'
    );
}

function wristband_import_csv_rows(string $path): array
{
    $maximumRows = 250000;
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('The CSV file could not be opened.');
    }
    $firstLine = (string) fgets($handle);
    rewind($handle);
    $delimiters = [',', ';', "\t", '|'];
    $delimiter = ',';
    $bestCount = 0;
    foreach ($delimiters as $candidate) {
        $count = count(str_getcsv($firstLine, $candidate));
        if ($count > $bestCount) {
            $bestCount = $count;
            $delimiter = $candidate;
        }
    }

    $rows = [];
    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        if (count($rows) >= $maximumRows) {
            fclose($handle);
            throw new RuntimeException('The import exceeds the 250,000-row safety limit. Split it into smaller files.');
        }
        $rows[] = array_map(static fn ($value): string => trim((string) $value), $row);
    }
    fclose($handle);

    return $rows;
}

function wristband_import_xlsx_rows(string $path): array
{
    $maximumXmlBytes = 64 * 1024 * 1024;
    $maximumRows = 250000;
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Excel import requires the ZipArchive PHP extension.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('The Excel file could not be opened.');
    }

    foreach (['xl/sharedStrings.xml', 'xl/worksheets/sheet1.xml'] as $entryName) {
        $entry = $zip->statName($entryName);
        if (is_array($entry) && (int) ($entry['size'] ?? 0) > $maximumXmlBytes) {
            $zip->close();
            throw new RuntimeException('The Excel file expands beyond the 64 MB import safety limit. Split it into smaller files.');
        }
    }

    $sharedStrings = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if (is_string($sharedXml) && $sharedXml !== '') {
        $xml = simplexml_load_string($sharedXml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        if ($xml !== false) {
            foreach ($xml->si as $entry) {
                $parts = [];
                if (isset($entry->t)) {
                    $parts[] = (string) $entry->t;
                }
                foreach ($entry->r as $run) {
                    $parts[] = (string) $run->t;
                }
                $sharedStrings[] = implode('', $parts);
            }
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if (!is_string($sheetXml) || $sheetXml === '') {
        throw new RuntimeException('The first Excel worksheet could not be read.');
    }
    $sheet = simplexml_load_string($sheetXml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
    if ($sheet === false) {
        throw new RuntimeException('The Excel worksheet is invalid.');
    }

    $rows = [];
    foreach ($sheet->sheetData->row as $rowNode) {
        if (count($rows) >= $maximumRows) {
            throw new RuntimeException('The import exceeds the 250,000-row safety limit. Split it into smaller files.');
        }
        $row = [];
        foreach ($rowNode->c as $cell) {
            $reference = (string) $cell['r'];
            preg_match('/^[A-Z]+/', $reference, $matches);
            $letters = (string) ($matches[0] ?? 'A');
            $column = 0;
            for ($i = 0, $length = strlen($letters); $i < $length; $i++) {
                $column = ($column * 26) + (ord($letters[$i]) - 64);
            }
            $column--;
            $type = (string) $cell['t'];
            $value = '';
            if ($type === 's') {
                $value = (string) ($sharedStrings[(int) $cell->v] ?? '');
            } elseif ($type === 'inlineStr') {
                $value = (string) ($cell->is->t ?? '');
                if ($value === '') {
                    $parts = [];
                    foreach ($cell->is->r as $run) {
                        $parts[] = (string) $run->t;
                    }
                    $value = implode('', $parts);
                }
            } else {
                $value = (string) ($cell->v ?? '');
            }
            $row[$column] = trim($value);
        }
        if ($row !== []) {
            $maxColumn = max(array_keys($row));
            $normalized = [];
            for ($index = 0; $index <= $maxColumn; $index++) {
                $normalized[] = (string) ($row[$index] ?? '');
            }
            $rows[] = $normalized;
        }
    }

    return $rows;
}

function wristband_import_file_rows(string $path, string $extension): array
{
    return $extension === 'csv'
        ? wristband_import_csv_rows($path)
        : wristband_import_xlsx_rows($path);
}

function wristband_import_header_map(array $row): array
{
    $map = [];
    foreach ($row as $index => $value) {
        $key = strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', trim((string) $value)));
        $map[$key] = (int) $index;
    }

    return $map;
}

function wristband_import_resolve_columns(array $rows, string $mappingMode): array
{
    $header = wristband_import_header_map($rows[0] ?? []);
    $codeAliases = ['code', 'qr_code', 'qrcode', 'wristband_code', 'wristband', 'scan_code'];
    $skuAliases = ['sku', 'item_sku', 'item_code', 'product_sku'];
    $codeColumn = null;
    $skuColumn = null;
    foreach ($codeAliases as $alias) {
        if (isset($header[$alias])) {
            $codeColumn = $header[$alias];
            break;
        }
    }
    foreach ($skuAliases as $alias) {
        if (isset($header[$alias])) {
            $skuColumn = $header[$alias];
            break;
        }
    }
    $hasHeader = $codeColumn !== null || $skuColumn !== null;
    $codeColumn ??= 0;
    if ($mappingMode === 'code_sku') {
        $skuColumn ??= 1;
    }

    return [$codeColumn, $skuColumn, $hasHeader ? 1 : 0];
}

function wristband_import_items_by_sku(): array
{
    $map = [];
    foreach (Database::fetchAll(
        'SELECT id, sku FROM items
         WHERE deleted_at IS NULL AND is_active = 1
           AND external_qr_tracking_enabled = 1 AND measurement_dimension = "count"'
    ) as $item) {
        $sku = strtoupper(trim((string) $item['sku']));
        if ($sku !== '') {
            $map[$sku] = (int) $item['id'];
        }
    }

    return $map;
}

function wristband_insert_code_batch(array $codes, int $importId): int
{
    if ($codes === []) {
        return 0;
    }

    $values = [];
    $params = [];
    foreach ($codes as $index => $code) {
        $values[] = '(:item_id_' . $index . ', :import_id_' . $index . ', :code_hash_' . $index . ', :code_masked_' . $index . ', "available", NOW(), NOW())';
        $params['item_id_' . $index] = (int) $code['item_id'];
        $params['import_id_' . $index] = $importId;
        $params['code_hash_' . $index] = (string) $code['hash'];
        $params['code_masked_' . $index] = (string) $code['masked'];
    }

    $statement = Database::connection()->prepare(
        'INSERT IGNORE INTO wristband_codes
            (item_id, import_id, code_hash, code_masked, state, created_at, updated_at)
         VALUES ' . implode(', ', $values)
    );
    $statement->execute($params);

    return $statement->rowCount();
}

function wristband_import_codes(string $path, string $filename, string $extension, string $mappingMode, int $selectedItemId, int $userId): array
{
    $rows = wristband_import_file_rows($path, $extension);
    if ($rows === []) {
        throw new RuntimeException('The import file contains no rows.');
    }
    [$codeColumn, $skuColumn, $startRow] = wristband_import_resolve_columns($rows, $mappingMode);
    $skuMap = $mappingMode === 'code_sku' ? wristband_import_items_by_sku() : [];
    if ($mappingMode === 'selected_item') {
        $eligibleItem = Database::fetch(
            'SELECT id FROM items
             WHERE id = :id AND deleted_at IS NULL AND is_active = 1
               AND external_qr_tracking_enabled = 1 AND measurement_dimension = "count"
             LIMIT 1',
            ['id' => $selectedItemId]
        );
        if ($eligibleItem === null) {
            throw new RuntimeException('Choose an active count-based item with external QR tracking enabled.');
        }
    }
    $stats = ['total' => 0, 'imported' => 0, 'duplicates' => 0, 'invalid' => 0];
    $prepared = [];
    $seen = [];

    for ($index = $startRow, $count = count($rows); $index < $count; $index++) {
        $row = $rows[$index];
        if (count(array_filter($row, static fn ($value): bool => trim((string) $value) !== '')) === 0) {
            continue;
        }
        $stats['total']++;
        $normalized = wristband_normalize_code((string) ($row[$codeColumn] ?? ''));
        $itemId = $selectedItemId;
        if ($mappingMode === 'code_sku') {
            $sku = strtoupper(trim((string) ($row[$skuColumn ?? 1] ?? '')));
            $itemId = (int) ($skuMap[$sku] ?? 0);
        }
        if (strlen($normalized) < 6 || strlen($normalized) > 128 || $itemId <= 0) {
            $stats['invalid']++;
            continue;
        }
        $hash = wristband_code_hash($normalized);
        if (isset($seen[$hash])) {
            $stats['duplicates']++;
            continue;
        }
        $seen[$hash] = true;
        $prepared[] = ['item_id' => $itemId, 'hash' => $hash, 'masked' => wristband_mask_code($normalized)];
    }

    $pdo = Database::connection();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $importNumber = wristband_new_reference('WBI');
        Database::execute(
            'INSERT INTO wristband_imports
                (import_number, source_filename, source_sha256, mapping_mode, selected_item_id, total_rows,
                 imported_rows, duplicate_rows, invalid_rows, summary_json, created_by, created_at)
             VALUES
                (:import_number, :source_filename, :source_sha256, :mapping_mode, :selected_item_id, :total_rows,
                 0, 0, :invalid_rows, NULL, :created_by, NOW())',
            [
                'import_number' => $importNumber,
                'source_filename' => $filename,
                'source_sha256' => hash_file('sha256', $path),
                'mapping_mode' => $mappingMode,
                'selected_item_id' => $mappingMode === 'selected_item' ? $selectedItemId : null,
                'total_rows' => $stats['total'],
                'invalid_rows' => $stats['invalid'],
                'created_by' => $userId,
            ]
        );
        $importId = Database::lastInsertId();
        foreach (array_chunk($prepared, 500) as $batch) {
            $inserted = wristband_insert_code_batch($batch, $importId);
            $stats['imported'] += $inserted;
            $stats['duplicates'] += count($batch) - $inserted;
        }
        Database::execute(
            'UPDATE wristband_imports
             SET imported_rows = :imported_rows,
                 duplicate_rows = :duplicate_rows,
                 invalid_rows = :invalid_rows,
                 summary_json = :summary_json
             WHERE id = :id',
            [
                'imported_rows' => $stats['imported'],
                'duplicate_rows' => $stats['duplicates'],
                'invalid_rows' => $stats['invalid'],
                'summary_json' => json_encode($stats, JSON_UNESCAPED_SLASHES),
                'id' => $importId,
            ]
        );
        if ($ownsTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    return $stats + ['import_id' => $importId, 'import_number' => $importNumber];
}
