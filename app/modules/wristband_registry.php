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

function wristband_item_code_counts(int $itemId): array
{
    if ($itemId <= 0) {
        return ['registered' => 0, 'available' => 0, 'used' => 0, 'void' => 0];
    }

    try {
        $row = Database::fetch(
            'SELECT COUNT(*) AS registered,
                    COALESCE(SUM(state = "available"), 0) AS available,
                    COALESCE(SUM(state = "used"), 0) AS used,
                    COALESCE(SUM(state = "void"), 0) AS void
             FROM wristband_codes
             WHERE item_id = :item_id',
            ['item_id' => $itemId]
        );
    } catch (Throwable $exception) {
        // Keep inventory lookup available while a backward-compatible schema deploy is in progress.
        return ['registered' => 0, 'available' => 0, 'used' => 0, 'void' => 0];
    }

    return [
        'registered' => (int) ($row['registered'] ?? 0),
        'available' => (int) ($row['available'] ?? 0),
        'used' => (int) ($row['used'] ?? 0),
        'void' => (int) ($row['void'] ?? 0),
    ];
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
                user.name AS created_by_name, storage.name AS storage_name
         FROM wristband_imports import
         LEFT JOIN items item ON item.id = import.selected_item_id
         LEFT JOIN storages storage ON storage.id = import.storage_id
         LEFT JOIN users user ON user.id = import.created_by
         ORDER BY import.id DESC
         LIMIT 200'
    );
}

function wristband_import_visible_storages(int $userId): array
{
    $storageIds = user_visible_storage_ids($userId);
    if ($storageIds === []) {
        return [];
    }

    $placeholders = [];
    $params = [];
    foreach ($storageIds as $index => $storageId) {
        $key = 'storage_' . $index;
        $placeholders[] = ':' . $key;
        $params[$key] = (int) $storageId;
    }

    return Database::fetchAll(
        'SELECT id, name, storage_type
         FROM storages
         WHERE is_active = 1 AND is_system = 0
           AND id IN (' . implode(', ', $placeholders) . ')
         ORDER BY name ASC, id ASC',
        $params
    );
}

function wristband_import_uploaded_file(string $field = 'wristband_file'): array
{
    $file = $_FILES[$field] ?? null;
    if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Choose a CSV or XLSX wristband file.');
    }

    $name = basename((string) ($file['name'] ?? ''));
    $path = (string) ($file['tmp_name'] ?? '');
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($extension, ['csv', 'xlsx'], true)) {
        throw new RuntimeException('Only CSV and XLSX wristband files are supported.');
    }
    if ((int) ($file['size'] ?? 0) <= 0 || (int) $file['size'] > 20 * 1024 * 1024) {
        throw new RuntimeException('The wristband file must be between 1 byte and 20 MB.');
    }
    if ($path === '' || !is_uploaded_file($path)) {
        throw new RuntimeException('The uploaded wristband file could not be verified.');
    }

    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
    $mimeType = $finfo ? (string) finfo_file($finfo, $path) : '';
    if ($finfo) {
        finfo_close($finfo);
    }
    $allowedMimes = $extension === 'csv'
        ? ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel', 'application/octet-stream']
        : ['application/zip', 'application/x-zip-compressed', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/octet-stream'];
    if ($mimeType !== '' && !in_array($mimeType, $allowedMimes, true)) {
        throw new RuntimeException('The uploaded content does not match its CSV or XLSX extension.');
    }

    return [
        'path' => $path,
        'name' => $name,
        'extension' => $extension,
        'size' => (int) $file['size'],
        'mime_type' => $mimeType,
    ];
}

function wristband_import_sample_rows(string $mappingMode): array
{
    return $mappingMode === 'code_sku'
        ? [
            ['code', 'sku'],
            ['AB12CD34EF56GH78', 'WB-BLUE'],
            ['JK90LM12NO34PQ56', 'WB-RED'],
        ]
        : [
            ['code'],
            ['AB12CD34EF56GH78'],
            ['JK90LM12NO34PQ56'],
        ];
}

function wristband_import_sample_xlsx(string $mappingMode): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive is required to generate the Excel example.');
    }

    $rows = wristband_import_sample_rows($mappingMode);
    $sheetRows = '';
    foreach ($rows as $rowIndex => $row) {
        $cells = '';
        foreach ($row as $columnIndex => $value) {
            $column = workflow_xlsx_column($columnIndex + 1);
            $cells .= workflow_xlsx_cell($column . ($rowIndex + 1), (string) $value, $rowIndex === 0 ? 2 : 0);
        }
        $sheetRows .= '<row r="' . ($rowIndex + 1) . '">' . $cells . '</row>';
    }
    $maximumColumn = $mappingMode === 'code_sku' ? 'B' : 'A';
    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<dimension ref="A1:' . $maximumColumn . count($rows) . '"/>'
        . '<cols><col min="1" max="1" width="30" customWidth="1"/>'
        . ($mappingMode === 'code_sku' ? '<col min="2" max="2" width="22" customWidth="1"/>' : '')
        . '</cols><sheetData>' . $sheetRows . '</sheetData></worksheet>';

    $temporary = tempnam(sys_get_temp_dir(), 'wristband-sample-');
    if ($temporary === false) {
        throw new RuntimeException('Could not create the Excel example.');
    }
    $zip = new ZipArchive();
    if ($zip->open($temporary, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($temporary);
        throw new RuntimeException('Could not create the Excel example.');
    }
    $zip->addFromString('[Content_Types].xml', workflow_xlsx_content_types_xml([]));
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>');
    $zip->addFromString('docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>Inventory KONA</Application></Properties>');
    $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>Wristband Import Example</dc:title><dc:creator>Inventory KONA</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('c') . '</dcterms:created></cp:coreProperties>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Wristband Codes" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $zip->addFromString('xl/styles.xml', workflow_xlsx_styles_xml());
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    $zip->close();

    $bytes = file_get_contents($temporary);
    @unlink($temporary);
    if (!is_string($bytes) || $bytes === '') {
        throw new RuntimeException('Could not build the Excel example.');
    }

    return $bytes;
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

function wristband_import_candidate_items(int $storageId): array
{
    if ($storageId <= 0) {
        return [];
    }

    return Database::fetchAll(
        'SELECT item.id, item.name, item.sku, item.image_path, item.unit,
                item.external_qr_tracking_enabled,
                balance.quantity AS storage_quantity,
                COALESCE(code_totals.registered_codes, 0) AS registered_codes,
                COALESCE(code_totals.available_codes, 0) AS available_codes
         FROM item_storage_balances balance
         INNER JOIN items item ON item.id = balance.item_id
         LEFT JOIN (
             SELECT item_id, COUNT(*) AS registered_codes,
                    SUM(state = "available") AS available_codes
             FROM wristband_codes
             GROUP BY item_id
         ) code_totals ON code_totals.item_id = item.id
         WHERE balance.storage_id = :storage_id
           AND item.is_active = 1
           AND item.measurement_dimension = "count"
         ORDER BY item.name ASC, item.sku ASC, item.id ASC',
        ['storage_id' => $storageId]
    );
}

function wristband_import_item_for_storage(int $itemId, int $storageId): ?array
{
    if ($itemId <= 0 || $storageId <= 0) {
        return null;
    }

    return Database::fetch(
        'SELECT item.*, balance.quantity AS storage_quantity,
                (SELECT COUNT(*) FROM wristband_codes code WHERE code.item_id = item.id) AS registered_codes
         FROM items item
         INNER JOIN item_storage_balances balance
           ON balance.item_id = item.id AND balance.storage_id = :storage_id
         WHERE item.id = :item_id
           AND item.is_active = 1
           AND item.measurement_dimension = "count"
         LIMIT 1',
        ['item_id' => $itemId, 'storage_id' => $storageId]
    );
}

function wristband_import_items_by_sku(int $storageId = 0, bool $includeTrackingDisabled = false): array
{
    $rows = $storageId > 0
        ? wristband_import_candidate_items($storageId)
        : Database::fetchAll(
            'SELECT id, sku, external_qr_tracking_enabled
             FROM items
             WHERE is_active = 1 AND measurement_dimension = "count"
             ORDER BY id ASC'
        );
    $map = [];
    foreach ($rows as $item) {
        if (!$includeTrackingDisabled && (int) ($item['external_qr_tracking_enabled'] ?? 0) !== 1) {
            continue;
        }
        $sku = strtoupper(trim((string) ($item['sku'] ?? '')));
        if ($sku !== '') {
            $map[$sku] ??= [];
            $map[$sku][] = (int) $item['id'];
        }
    }

    return $map;
}

function wristband_existing_codes_by_hash(array $hashes): array
{
    $result = [];
    foreach (array_chunk(array_values(array_unique($hashes)), 500) as $chunk) {
        if ($chunk === []) {
            continue;
        }
        $placeholders = [];
        $params = [];
        foreach ($chunk as $index => $hash) {
            $key = 'hash_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = (string) $hash;
        }
        foreach (Database::fetchAll(
            'SELECT id, item_id, code_hash, state FROM wristband_codes
             WHERE code_hash IN (' . implode(', ', $placeholders) . ')',
            $params
        ) as $row) {
            $result[(string) $row['code_hash']] = $row;
        }
    }

    return $result;
}

function wristband_import_preflight(
    string $path,
    string $extension,
    string $mappingMode,
    int $selectedItemId,
    int $storageId = 0,
    bool $allowTrackingEnable = false
): array {
    if (!in_array($mappingMode, ['selected_item', 'code_sku'], true)) {
        throw new RuntimeException('Choose a valid wristband mapping mode.');
    }
    if ($storageId <= 0 || !storage_exists_for_assignment($storageId)) {
        throw new RuntimeException('Choose an active storage before importing codes.');
    }

    $rows = wristband_import_file_rows($path, $extension);
    if ($rows === []) {
        throw new RuntimeException('The import file contains no rows.');
    }
    [$codeColumn, $skuColumn, $startRow] = wristband_import_resolve_columns($rows, $mappingMode);
    $candidateItems = wristband_import_candidate_items($storageId);
    $candidateItemMap = [];
    foreach ($candidateItems as $candidateItem) {
        $candidateItemMap[(int) $candidateItem['id']] = $candidateItem;
    }
    $skuMap = [];
    if ($mappingMode === 'code_sku') {
        foreach ($candidateItems as $candidateItem) {
            $sku = strtoupper(trim((string) ($candidateItem['sku'] ?? '')));
            if ($sku !== '') {
                $skuMap[$sku] ??= [];
                $skuMap[$sku][] = (int) $candidateItem['id'];
            }
        }
    }
    $selectedItem = null;
    if ($mappingMode === 'selected_item') {
        $selectedItem = $candidateItemMap[$selectedItemId] ?? null;
        if ($selectedItem === null) {
            throw new RuntimeException('Choose an active count-based item assigned to the selected storage.');
        }
        if ((int) $selectedItem['external_qr_tracking_enabled'] !== 1 && !$allowTrackingEnable) {
            throw new RuntimeException('Enable external QR tracking explicitly before importing codes for this item.');
        }
    }

    $stats = [
        'total' => 0,
        'valid' => 0,
        'duplicates' => 0,
        'invalid' => 0,
        'unknown_skus' => 0,
        'conflicts' => 0,
    ];
    $candidates = [];
    $preview = [];
    $seen = [];
    $trackingItemIds = [];

    for ($index = $startRow, $count = count($rows); $index < $count; $index++) {
        $row = $rows[$index];
        if (count(array_filter($row, static fn ($value): bool => trim((string) $value) !== '')) === 0) {
            continue;
        }
        $stats['total']++;
        $rowNumber = $index + 1;
        $rawCode = trim((string) ($row[$codeColumn] ?? ''));
        $normalized = wristband_normalize_code($rawCode);
        $sku = $mappingMode === 'code_sku'
            ? strtoupper(trim((string) ($row[$skuColumn ?? 1] ?? '')))
            : strtoupper(trim((string) ($selectedItem['sku'] ?? '')));
        $itemId = $mappingMode === 'selected_item' ? $selectedItemId : 0;
        $status = 'pending';
        $message = '';

        if (strlen($normalized) < 6 || strlen($normalized) > 128) {
            $status = 'invalid';
            $message = 'Code must contain 6 to 128 letters or numbers.';
            $stats['invalid']++;
        } elseif ($mappingMode === 'code_sku') {
            $matches = $skuMap[$sku] ?? [];
            if ($sku === '' || $matches === []) {
                $status = 'unknown_sku';
                $message = 'SKU is not assigned to this storage.';
                $stats['unknown_skus']++;
            } elseif (count($matches) !== 1) {
                $status = 'conflict';
                $message = 'SKU matches more than one item in this storage.';
                $stats['conflicts']++;
            } else {
                $itemId = (int) $matches[0];
                $candidateItem = $candidateItemMap[$itemId] ?? null;
                if ($candidateItem === null) {
                    $status = 'unknown_sku';
                    $message = 'SKU is not an eligible count item in this storage.';
                    $stats['unknown_skus']++;
                } elseif ((int) $candidateItem['external_qr_tracking_enabled'] !== 1 && !$allowTrackingEnable) {
                    $status = 'conflict';
                    $message = 'External QR tracking is disabled for this item.';
                    $stats['conflicts']++;
                }
            }
        }

        $hash = $normalized !== '' ? wristband_code_hash($normalized) : '';
        if ($status === 'pending' && isset($seen[$hash])) {
            $status = 'duplicate';
            $message = 'Duplicate code inside this file.';
            $stats['duplicates']++;
        }
        if ($status === 'pending') {
            $seen[$hash] = true;
            $candidates[] = [
                'row_number' => $rowNumber,
                'item_id' => $itemId,
                'sku' => $sku,
                'hash' => $hash,
                'masked' => wristband_mask_code($normalized),
            ];
            $trackingItemIds[$itemId] = true;
        }
        if (count($preview) < 100 && $status !== 'pending') {
            $preview[] = [
                'row_number' => $rowNumber,
                'code' => $normalized !== '' ? wristband_mask_code($normalized) : '(blank)',
                'sku' => $sku,
                'status' => $status,
                'message' => $message,
            ];
        }
    }

    $existing = wristband_existing_codes_by_hash(array_column($candidates, 'hash'));
    $validRows = [];
    foreach ($candidates as $candidate) {
        $existingCode = $existing[(string) $candidate['hash']] ?? null;
        if ($existingCode !== null) {
            $sameItem = (int) $existingCode['item_id'] === (int) $candidate['item_id'];
            $status = $sameItem ? 'duplicate' : 'conflict';
            $message = $sameItem
                ? 'Code is already registered for this item.'
                : 'Code is already registered to another item.';
            $stats[$sameItem ? 'duplicates' : 'conflicts']++;
            if (count($preview) < 100) {
                $preview[] = [
                    'row_number' => (int) $candidate['row_number'],
                    'code' => (string) $candidate['masked'],
                    'sku' => (string) $candidate['sku'],
                    'status' => $status,
                    'message' => $message,
                ];
            }
            continue;
        }
        $validRows[] = [
            'item_id' => (int) $candidate['item_id'],
            'hash' => (string) $candidate['hash'],
            'masked' => (string) $candidate['masked'],
            'row_number' => (int) $candidate['row_number'],
            'sku' => (string) $candidate['sku'],
        ];
        $stats['valid']++;
        if (count($preview) < 100) {
            $preview[] = [
                'row_number' => (int) $candidate['row_number'],
                'code' => (string) $candidate['masked'],
                'sku' => (string) $candidate['sku'],
                'status' => 'valid',
                'message' => 'Ready to import.',
            ];
        }
    }
    usort($preview, static fn (array $left, array $right): int => $left['row_number'] <=> $right['row_number']);

    return [
        'stats' => $stats,
        'valid_rows' => $validRows,
        'preview' => $preview,
        'storage_id' => $storageId,
        'mapping_mode' => $mappingMode,
        'selected_item_id' => $mappingMode === 'selected_item' ? $selectedItemId : 0,
        'tracking_item_ids' => array_map('intval', array_keys($trackingItemIds)),
        'has_issues' => ($stats['duplicates'] + $stats['invalid'] + $stats['unknown_skus'] + $stats['conflicts']) > 0,
    ];
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

function wristband_import_codes(
    string $path,
    string $filename,
    string $extension,
    string $mappingMode,
    int $selectedItemId,
    int $userId,
    int $storageId = 0,
    bool $enableTracking = false,
    ?array $preflight = null,
    bool $strict = false
): array {
    $preflight ??= wristband_import_preflight(
        $path,
        $extension,
        $mappingMode,
        $selectedItemId,
        $storageId,
        $enableTracking
    );
    $stats = $preflight['stats'];
    $stats['imported'] = 0;
    $prepared = $preflight['valid_rows'];
    if ($strict && ($preflight['has_issues'] || $prepared === [])) {
        throw new RuntimeException('The wristband file must contain only valid, unique codes before stock can be added.');
    }

    $pdo = Database::connection();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $importNumber = wristband_new_reference('WBI');
        if ($enableTracking && $preflight['tracking_item_ids'] !== []) {
            foreach ($preflight['tracking_item_ids'] as $trackingItemId) {
                Database::execute(
                    'UPDATE items SET external_qr_tracking_enabled = 1, updated_by = :updated_by, updated_at = NOW()
                     WHERE id = :id',
                    ['updated_by' => $userId, 'id' => (int) $trackingItemId]
                );
            }
        }
        Database::execute(
            'INSERT INTO wristband_imports
                (import_number, source_filename, source_sha256, mapping_mode, selected_item_id, storage_id, total_rows,
                 imported_rows, duplicate_rows, invalid_rows, summary_json, created_by, created_at)
             VALUES
                (:import_number, :source_filename, :source_sha256, :mapping_mode, :selected_item_id, :storage_id, :total_rows,
                 0, 0, :invalid_rows, NULL, :created_by, NOW())',
            [
                'import_number' => $importNumber,
                'source_filename' => $filename,
                'source_sha256' => hash_file('sha256', $path),
                'mapping_mode' => $mappingMode,
                'selected_item_id' => $mappingMode === 'selected_item' ? $selectedItemId : null,
                'storage_id' => $storageId > 0 ? $storageId : null,
                'total_rows' => $stats['total'],
                'invalid_rows' => $stats['invalid'] + $stats['unknown_skus'] + $stats['conflicts'],
                'created_by' => $userId,
            ]
        );
        $importId = Database::lastInsertId();
        foreach (array_chunk($prepared, 500) as $batch) {
            $inserted = wristband_insert_code_batch($batch, $importId);
            $stats['imported'] += $inserted;
            $stats['duplicates'] += count($batch) - $inserted;
        }
        if ($strict && $stats['imported'] !== count($prepared)) {
            throw new RuntimeException('A wristband code was registered by another request. Nothing was changed; run preflight again.');
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
                'invalid_rows' => $stats['invalid'] + $stats['unknown_skus'] + $stats['conflicts'],
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

    return $stats + [
        'import_id' => $importId,
        'import_number' => $importNumber,
        'storage_id' => $storageId,
        'preview' => $preflight['preview'],
    ];
}
