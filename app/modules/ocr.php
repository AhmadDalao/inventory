<?php
declare(strict_types=1);

// Domain module: ocr. Function names are preserved for route/view compatibility.

// Moved from workflows.php.

function purchase_ocr_command_path(string $command): ?string
{
    $command = preg_replace('/[^a-zA-Z0-9_-]/', '', $command) ?: '';

    if ($command === '' || !function_exists('shell_exec')) {
        return null;
    }

    $result = @shell_exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null');
    $path = trim((string) $result);

    return $path !== '' ? $path : null;
}

function purchase_ocr_tesseract_language_config(string $tesseract): array
{
    static $configs = [];

    if (isset($configs[$tesseract])) {
        return $configs[$tesseract];
    }

    $output = (string) @shell_exec(escapeshellarg($tesseract) . ' --list-langs 2>/dev/null');
    $languages = array_filter(array_map('trim', explode("\n", $output)));
    $hasArabic = in_array('ara', $languages, true);
    $hasEnglish = in_array('eng', $languages, true);

    if ($hasArabic && $hasEnglish) {
        $language = 'ara+eng';
    } elseif ($hasArabic) {
        $language = 'ara';
    } else {
        $language = 'eng';
    }

    $configs[$tesseract] = [
        'language' => $language,
        'has_arabic' => $hasArabic,
    ];

    return $configs[$tesseract];
}

function purchase_ocr_health(): array
{
    $pdftotext = purchase_ocr_command_path('pdftotext');
    $pdftoppm = purchase_ocr_command_path('pdftoppm');
    $tesseract = purchase_ocr_command_path('tesseract');
    $tesseractConfig = $tesseract !== null ? purchase_ocr_tesseract_language_config($tesseract) : null;
    $openaiConfigured = purchase_ocr_openai_enabled();
    $mode = purchase_ocr_mode();

    return [
        [
            'label' => 'pdftotext',
            'status' => $pdftotext !== null ? 'available' : 'missing',
            'ok' => $pdftotext !== null,
            'detail' => $pdftotext !== null ? $pdftotext : 'Normal PDF text extraction is unavailable on this server.',
        ],
        [
            'label' => 'pdftoppm',
            'status' => $pdftoppm !== null ? 'available' : 'missing',
            'ok' => $pdftoppm !== null,
            'detail' => $pdftoppm !== null ? $pdftoppm : 'Server-side scanned PDF rendering is unavailable.',
        ],
        [
            'label' => 'tesseract',
            'status' => $tesseract !== null ? 'available' : 'missing',
            'ok' => $tesseract !== null,
            'detail' => $tesseract !== null ? $tesseract : 'Server-side image OCR is unavailable.',
        ],
        [
            'label' => 'Arabic language data',
            'status' => !empty($tesseractConfig['has_arabic']) ? 'available' : 'missing',
            'ok' => !empty($tesseractConfig['has_arabic']),
            'detail' => !empty($tesseractConfig['has_arabic']) ? 'Tesseract can read Arabic server-side.' : 'Browser OCR and OpenAI fallback handle Arabic scans better here.',
        ],
        [
            'label' => 'OpenAI OCR',
            'status' => $openaiConfigured ? 'configured' : 'not configured',
            'ok' => $openaiConfigured,
            'detail' => $openaiConfigured ? 'Mode: ' . $mode . '. Model: ' . openai_ocr_model() . '.' : 'Save an API key and keep OpenAI OCR enabled to use AI extraction.',
        ],
        [
            'label' => 'Browser OCR',
            'status' => 'available fallback',
            'ok' => true,
            'detail' => 'PDF.js + Tesseract.js can run in the browser for images and scanned PDFs.',
        ],
    ];
}

function purchase_ocr_excerpt(string $text): string
{
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?: $text);

    return mb_substr($text, 0, 3000);
}

function purchase_ocr_log_run(array $data): ?int
{
    try {
        Database::execute(
            'INSERT INTO purchase_ocr_runs (
                purchase_id,
                created_draft_purchase_id,
                source_filename,
                mime_type,
                engine,
                confidence,
                parsed_line_count,
                warnings,
                text_excerpt,
                processed_by,
                created_at
             ) VALUES (
                :purchase_id,
                :created_draft_purchase_id,
                :source_filename,
                :mime_type,
                :engine,
                :confidence,
                :parsed_line_count,
                :warnings,
                :text_excerpt,
                :processed_by,
                NOW()
             )',
            [
                'purchase_id' => $data['purchase_id'] ?? null,
                'created_draft_purchase_id' => $data['created_draft_purchase_id'] ?? null,
                'source_filename' => mb_substr((string) ($data['source_filename'] ?? ''), 0, 255),
                'mime_type' => mb_substr((string) ($data['mime_type'] ?? ''), 0, 120),
                'engine' => mb_substr((string) ($data['engine'] ?? ''), 0, 120),
                'confidence' => max(0.0, min(1.0, (float) ($data['confidence'] ?? 0))),
                'parsed_line_count' => max(0, (int) ($data['parsed_line_count'] ?? 0)),
                'warnings' => !empty($data['warnings']) ? json_encode(array_values(array_map('strval', (array) $data['warnings'])), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                'text_excerpt' => isset($data['text_excerpt']) ? purchase_ocr_excerpt((string) $data['text_excerpt']) : null,
                'processed_by' => Auth::user()['id'] ?? null,
            ]
        );

        return Database::lastInsertId();
    } catch (Throwable $exception) {
        return null;
    }
}

function purchase_ocr_update_runs_purchase(array $runIds, int $purchaseId): void
{
    $runIds = array_values(array_filter(array_map(static fn ($id): int => (int) $id, $runIds), static fn (int $id): bool => $id > 0));

    if ($runIds === []) {
        return;
    }

    $placeholders = [];
    $params = [
        'purchase_id' => $purchaseId,
        'created_draft_purchase_id' => $purchaseId,
        'processed_by' => Auth::user()['id'] ?? null,
    ];

    foreach ($runIds as $index => $runId) {
        $key = 'id' . $index;
        $placeholders[] = ':' . $key;
        $params[$key] = $runId;
    }

    try {
        Database::execute(
            'UPDATE purchase_ocr_runs
             SET purchase_id = :purchase_id,
                 created_draft_purchase_id = :created_draft_purchase_id
             WHERE id IN (' . implode(', ', $placeholders) . ')
               AND (processed_by = :processed_by OR processed_by IS NULL)',
            $params
        );
    } catch (Throwable $exception) {
        // OCR logs must never block purchase creation.
    }
}

function purchase_ocr_extract_text_from_file(array $file, string $requestedEngine = 'auto'): array
{
    $meta = purchase_document_file_meta($file);
    $path = (string) ($file['tmp_name'] ?? '');
    $warnings = [];
    $text = '';
    $engine = null;
    $parsed = null;
    $requestedEngine = in_array($requestedEngine, ['auto', 'free', 'openai'], true) ? $requestedEngine : 'auto';
    $settingsMode = purchase_ocr_mode();
    $openaiFirst = $requestedEngine === 'openai' || ($requestedEngine === 'auto' && $settingsMode === 'openai_first');

    if ($openaiFirst && !purchase_ocr_openai_enabled()) {
        $warnings[] = 'AI OCR is not configured or not allowed by settings. Free OCR/manual review will be used instead.';
    }

    if ($openaiFirst && purchase_ocr_openai_enabled() && in_array($meta['mime_type'], ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], true)) {
        $aiResult = purchase_ocr_openai_extract_from_file($file, $meta);
        $warnings = array_merge($warnings, $aiResult['warnings'] ?? []);

        if (!empty($aiResult['engine'])) {
            $engine = (string) $aiResult['engine'];
        }

        if (is_array($aiResult['parsed'] ?? null)) {
            $parsed = $aiResult['parsed'];
        }

        if (trim((string) ($aiResult['text'] ?? '')) !== '') {
            $text = (string) $aiResult['text'];
        }

        if ($parsed !== null && (($parsed['lines'] ?? []) !== [] || trim((string) ($parsed['supplier']['name'] ?? '')) !== '')) {
            return [
                'text' => trim($text),
                'parsed' => $parsed,
                'warnings' => $warnings,
                'engine' => $engine,
            ];
        }
    }

    if ($meta['mime_type'] === 'application/pdf') {
        $pdftotext = purchase_ocr_command_path('pdftotext');

        if ($pdftotext !== null) {
            $engine = 'pdftotext';
            $text = (string) @shell_exec(escapeshellarg($pdftotext) . ' -layout -enc UTF-8 ' . escapeshellarg($path) . ' - 2>/dev/null');
        }

        if (trim($text) === '') {
            $pdftoppm = purchase_ocr_command_path('pdftoppm');
            $tesseract = purchase_ocr_command_path('tesseract');

            if ($pdftoppm !== null && $tesseract !== null) {
                $languageConfig = purchase_ocr_tesseract_language_config($tesseract);
                $engine = 'pdftoppm+tesseract';
                $prefix = rtrim(sys_get_temp_dir(), '/') . '/inventory-ocr-' . bin2hex(random_bytes(4));
                @shell_exec(escapeshellarg($pdftoppm) . ' -f 1 -l ' . (int) purchase_ocr_max_pdf_pages() . ' -r 180 -png ' . escapeshellarg($path) . ' ' . escapeshellarg($prefix) . ' 2>/dev/null');

                foreach (glob($prefix . '-*.png') ?: [] as $imagePath) {
                    $text .= "\n" . (string) @shell_exec(escapeshellarg($tesseract) . ' ' . escapeshellarg($imagePath) . ' stdout -l ' . escapeshellarg($languageConfig['language']) . ' --psm 6 2>/dev/null');
                    @unlink($imagePath);
                }

                if (!$languageConfig['has_arabic']) {
                    $warnings[] = 'Server OCR does not have Arabic language data installed. Browser OCR uses Arabic + English and may read Arabic scans better.';
                }
            }
        }

        if (trim($text) === '') {
            $warnings[] = 'PDF text extraction is not available on this server. Browser OCR can read scanned PDFs, or you can run AI extraction when configured.';
        }
    } elseif (strpos($meta['mime_type'], 'image/') === 0) {
        $tesseract = purchase_ocr_command_path('tesseract');

        if ($tesseract !== null) {
            $languageConfig = purchase_ocr_tesseract_language_config($tesseract);
            $engine = 'tesseract';
            $text = (string) @shell_exec(escapeshellarg($tesseract) . ' ' . escapeshellarg($path) . ' stdout -l ' . escapeshellarg($languageConfig['language']) . ' --psm 6 2>/dev/null');

            if (!$languageConfig['has_arabic']) {
                $warnings[] = 'Server OCR does not have Arabic language data installed. Browser OCR uses Arabic + English and may read Arabic scans better.';
            }
        } else {
            $warnings[] = 'Server image OCR is not available. Browser OCR can still process JPG/PNG/WebP files from this page.';
        }
    }

    return [
        'text' => trim($text),
        'parsed' => $parsed,
        'warnings' => $warnings,
        'engine' => $engine,
    ];
}

function purchase_ocr_ascii_digits(string $value): string
{
    return strtr($value, [
        '٠' => '0',
        '١' => '1',
        '٢' => '2',
        '٣' => '3',
        '٤' => '4',
        '٥' => '5',
        '٦' => '6',
        '٧' => '7',
        '٨' => '8',
        '٩' => '9',
        '۰' => '0',
        '۱' => '1',
        '۲' => '2',
        '۳' => '3',
        '۴' => '4',
        '۵' => '5',
        '۶' => '6',
        '۷' => '7',
        '۸' => '8',
        '۹' => '9',
        '٫' => '.',
        '٬' => ',',
        '،' => ',',
        '−' => '-',
        '–' => '-',
        '—' => '-',
    ]);
}

function purchase_ocr_clean_text_line(string $line): string
{
    $line = purchase_ocr_ascii_digits($line);
    $line = str_replace("\xc2\xa0", ' ', $line);

    return trim(preg_replace('/\s+/u', ' ', $line) ?: '');
}

function purchase_ocr_normalize_number(string $value): float
{
    $value = trim(purchase_ocr_ascii_digits($value));

    if (preg_match('/^\d{1,3}(,\d{3})+(\.\d+)?$/', $value)) {
        $value = str_replace(',', '', $value);
    } elseif (strpos($value, ',') !== false && strpos($value, '.') === false) {
        $value = str_replace(',', '.', $value);
    } else {
        $value = str_replace(',', '', $value);
    }

    return round((float) $value, 2);
}

function purchase_ocr_normalize_unit(string $line): string
{
    $line = mb_strtolower(purchase_ocr_clean_text_line($line));
    $units = [
        'carton' => ['carton', 'ctn', 'كرتون', 'كراتين'],
        'box' => ['box', 'boxes', 'علبة', 'علب', 'صندوق'],
        'pack' => ['pack', 'pkg', 'عبوة', 'باكيت', 'حزمة'],
        'set' => ['set', 'طقم', 'مجموعة'],
        'roll' => ['roll', 'رول', 'لفة'],
        'bottle' => ['bottle', 'زجاجة', 'قارورة'],
        'kg' => ['kg', 'kilogram'],
        'g' => ['gram', ' grams ', ' g ', 'جرام', 'غرام'],
        'liter' => ['liter', 'litre', 'لتر'],
        'ml' => ['ml'],
        'meter' => ['meter', 'metre', 'متر'],
        'pcs' => ['pcs', 'piece', 'pieces', 'pc', 'qty', 'حبة', 'حبات', 'قطعة', 'قطع', 'عدد', 'كمية'],
    ];

    foreach ($units as $unit => $needles) {
        foreach ($needles as $needle) {
            if (strpos($line, $needle) !== false) {
                return $unit;
            }
        }
    }

    return 'pcs';
}

function purchase_ocr_generated_sku(string $name, int $index): string
{
    $base = strtoupper(preg_replace('/[^A-Z0-9]+/', '-', $name) ?: 'ITEM');
    $base = trim($base, '-');

    if ($base === '' || $base === 'ITEM') {
        $base = 'ITEM-' . strtoupper(substr(hash('crc32b', $name), 0, 6));
    }

    $base = substr($base, 0, 24);

    return 'OCR-' . $base . '-' . str_pad((string) $index, 2, '0', STR_PAD_LEFT);
}

function purchase_ocr_catalog_match(string $name, string $sku): ?array
{
    $params = [
        'sku' => $sku,
        'name' => $name,
    ];

    $item = Database::fetch(
        'SELECT id, name, sku, barcode, category, unit, cost_per_unit, image_path, notes
         FROM items
         WHERE is_active = 1
           AND (sku = :sku OR LOWER(name) = LOWER(:name))
         ORDER BY CASE WHEN sku = :sku_order THEN 0 ELSE 1 END, id DESC
         LIMIT 1',
        [
            'sku' => $params['sku'],
            'name' => $params['name'],
            'sku_order' => $params['sku'],
        ]
    );

    return $item ?: null;
}

function purchase_ocr_empty_result(string $textExcerpt = ''): array
{
    return [
        'supplier' => [
            'name' => '',
            'phone' => '',
            'email' => '',
            'tax_number' => '',
            'commercial_registration' => '',
            'national_address' => '',
            'authorized_person' => '',
            'supplier_type' => 'product',
            'supplier_type_other' => '',
        ],
        'purchase' => [
            'expected_date' => '',
            'currency' => 'SAR',
        ],
        'lines' => [],
        'confidence' => [
            'overall' => 0.0,
            'supplier' => 0.0,
            'purchase' => 0.0,
            'lines' => 0.0,
            'engine' => '',
        ],
        'review_flags' => [],
        'text_excerpt' => substr($textExcerpt, 0, 3000),
    ];
}

function purchase_ocr_confidence_value($value, float $default): float
{
    if (!is_numeric($value)) {
        return max(0.0, min(1.0, $default));
    }

    return max(0.0, min(1.0, (float) $value));
}

function purchase_ocr_average_confidence(array $scores, float $default): float
{
    $valid = array_values(array_filter($scores, static fn ($score): bool => is_numeric($score)));

    if ($valid === []) {
        return purchase_ocr_confidence_value(null, $default);
    }

    return purchase_ocr_confidence_value(array_sum(array_map('floatval', $valid)) / count($valid), $default);
}

function purchase_ocr_review_flag(string $flag, array &$flags): void
{
    $flag = trim($flag);

    if ($flag !== '') {
        $flags[] = $flag;
    }
}

function purchase_ocr_normalize_supplier_type(string $type, string $customType = ''): array
{
    $normalized = strtolower(trim($type));
    $customType = trim($customType);

    if (array_key_exists($normalized, supplier_type_options())) {
        return [$normalized, $normalized === 'other' ? $customType : ''];
    }

    if (preg_match('/service|خدمة|خدمات|maintenance|صيانة/i', $normalized . ' ' . $customType)) {
        return ['service', ''];
    }

    if ($customType !== '' || $normalized !== '') {
        return ['other', $customType !== '' ? $customType : trim($type)];
    }

    return ['product', ''];
}

function purchase_ocr_normalize_parsed_result(array $parsed, string $fallbackText = ''): array
{
    $result = purchase_ocr_empty_result($fallbackText);
    $minimumConfidence = purchase_ocr_min_confidence();
    $supplier = is_array($parsed['supplier'] ?? null) ? $parsed['supplier'] : [];
    $purchase = is_array($parsed['purchase'] ?? null) ? $parsed['purchase'] : [];
    $confidence = is_array($parsed['confidence'] ?? null) ? $parsed['confidence'] : [];
    $reviewFlags = array_values(array_filter(array_map('strval', is_array($parsed['review_flags'] ?? null) ? $parsed['review_flags'] : [])));
    [$supplierType, $supplierTypeOther] = purchase_ocr_normalize_supplier_type(
        (string) ($supplier['supplier_type'] ?? ($supplier['type'] ?? 'product')),
        (string) ($supplier['supplier_type_other'] ?? '')
    );

    $result['supplier'] = [
        'name' => trim((string) ($supplier['name'] ?? '')),
        'phone' => trim((string) ($supplier['phone'] ?? '')),
        'email' => strtolower(trim((string) ($supplier['email'] ?? ''))),
        'tax_number' => strtoupper(trim((string) ($supplier['tax_number'] ?? ''))),
        'commercial_registration' => strtoupper(trim((string) ($supplier['commercial_registration'] ?? ''))),
        'national_address' => trim((string) ($supplier['national_address'] ?? '')),
        'authorized_person' => trim((string) ($supplier['authorized_person'] ?? '')),
        'supplier_type' => $supplierType,
        'supplier_type_other' => $supplierTypeOther,
    ];

    $supplierFilledCount = 0;
    foreach (['name', 'phone', 'tax_number', 'commercial_registration', 'national_address', 'authorized_person'] as $field) {
        if (($result['supplier'][$field] ?? '') !== '') {
            $supplierFilledCount++;
        }
    }

    $currency = strtoupper(trim((string) ($purchase['currency'] ?? 'SAR'))) ?: 'SAR';
    $result['purchase'] = [
        'expected_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($purchase['expected_date'] ?? '')) ? (string) $purchase['expected_date'] : '',
        'currency' => preg_match('/^[A-Z]{3,8}$/', $currency) ? $currency : 'SAR',
    ];

    $seen = [];
    $lines = is_array($parsed['lines'] ?? null) ? $parsed['lines'] : [];
    $lineConfidenceScores = [];

    foreach ($lines as $line) {
        if (!is_array($line)) {
            continue;
        }

        $name = trim((string) ($line['item_name'] ?? $line['name'] ?? ''));
        $sku = strtoupper(trim((string) ($line['item_sku'] ?? $line['sku'] ?? '')));
        $quantity = purchase_ocr_normalize_number((string) ($line['quantity_requested'] ?? $line['quantity'] ?? '0'));
        $unitCost = purchase_ocr_normalize_number((string) ($line['unit_cost_quoted'] ?? $line['unit_price'] ?? $line['cost'] ?? '0'));

        if ($name === '' || $quantity <= 0 || $unitCost < 0) {
            continue;
        }

        $sku = $sku !== '' ? $sku : purchase_ocr_generated_sku($name, count($result['lines']) + 1);
        $dedupeKey = strtoupper($name . '|' . $sku . '|' . $quantity . '|' . $unitCost);

        if (isset($seen[$dedupeKey])) {
            continue;
        }

        $seen[$dedupeKey] = true;
        $catalogItem = purchase_ocr_catalog_match($name, $sku);
        $lineFlags = array_values(array_filter(array_map('strval', is_array($line['review_flags'] ?? null) ? $line['review_flags'] : [])));

        if ($catalogItem) {
            $defaultLineConfidence = 0.88;
        } elseif (strpos($sku, 'OCR-') === 0) {
            $defaultLineConfidence = 0.58;
            purchase_ocr_review_flag('Generated SKU for "' . $name . '". Verify item identity before submitting.', $lineFlags);
        } else {
            $defaultLineConfidence = 0.7;
        }

        if ($unitCost <= 0) {
            purchase_ocr_review_flag('Unit price for "' . $name . '" is zero. Verify the price list row.', $lineFlags);
        }

        $lineConfidence = purchase_ocr_confidence_value($line['confidence'] ?? null, $defaultLineConfidence);
        $lineConfidenceScores[] = $lineConfidence;

        $result['lines'][] = [
            'item_id' => $catalogItem ? (int) $catalogItem['id'] : '',
            'item_name' => $catalogItem ? (string) $catalogItem['name'] : $name,
            'item_sku' => $catalogItem ? (string) $catalogItem['sku'] : $sku,
            'item_barcode' => $catalogItem ? (string) ($catalogItem['barcode'] ?? '') : normalize_item_barcode($line['item_barcode'] ?? $line['barcode'] ?? ''),
            'item_category' => $catalogItem ? (string) ($catalogItem['category'] ?? '') : trim((string) ($line['item_category'] ?? $line['category'] ?? '')),
            'unit' => $catalogItem ? (string) $catalogItem['unit'] : (trim((string) ($line['unit'] ?? '')) ?: 'pcs'),
            'quantity_requested' => format_quantity($quantity),
            'unit_cost_quoted' => format_quantity($unitCost),
            'item_notes' => $catalogItem ? (string) ($catalogItem['notes'] ?? '') : (trim((string) ($line['item_notes'] ?? $line['notes'] ?? '')) ?: 'Imported from AI OCR. Verify before submitting.'),
            'confidence' => $lineConfidence,
            'review_flags' => array_values(array_unique($lineFlags)),
        ];

        if (count($result['lines']) >= 50) {
            break;
        }
    }

    $rawText = trim((string) ($parsed['raw_text'] ?? $parsed['text_excerpt'] ?? $fallbackText));
    $result['text_excerpt'] = substr($rawText, 0, 3000);

    $supplierDefaultConfidence = $supplierFilledCount >= 3 ? 0.78 : ($supplierFilledCount > 0 ? 0.58 : 0.25);
    $purchaseDefaultConfidence = $result['purchase']['expected_date'] !== '' ? 0.76 : 0.55;
    $linesDefaultConfidence = purchase_ocr_average_confidence($lineConfidenceScores, $result['lines'] === [] ? 0.2 : 0.66);
    $result['confidence'] = [
        'overall' => purchase_ocr_confidence_value(
            $confidence['overall'] ?? null,
            purchase_ocr_average_confidence([$supplierDefaultConfidence, $purchaseDefaultConfidence, $linesDefaultConfidence], 0.5)
        ),
        'supplier' => purchase_ocr_confidence_value($confidence['supplier'] ?? ($supplier['confidence'] ?? null), $supplierDefaultConfidence),
        'purchase' => purchase_ocr_confidence_value($confidence['purchase'] ?? ($purchase['confidence'] ?? null), $purchaseDefaultConfidence),
        'lines' => purchase_ocr_confidence_value($confidence['lines'] ?? null, $linesDefaultConfidence),
        'engine' => trim((string) ($confidence['engine'] ?? '')),
    ];

    if ($result['supplier']['name'] === '') {
        purchase_ocr_review_flag('Supplier name was not detected.', $reviewFlags);
    }

    if ($result['supplier']['phone'] === '') {
        purchase_ocr_review_flag('Supplier phone was not detected. It is mandatory for new suppliers.', $reviewFlags);
    }

    if ($result['purchase']['expected_date'] === '') {
        purchase_ocr_review_flag('Expected date was not detected.', $reviewFlags);
    }

    if ($result['lines'] === []) {
        purchase_ocr_review_flag('No item rows were confidently detected.', $reviewFlags);
    }

    foreach ($result['lines'] as $line) {
        if ((float) ($line['confidence'] ?? 0) < $minimumConfidence) {
            purchase_ocr_review_flag('Low confidence line: ' . (string) $line['item_name'] . '.', $reviewFlags);
        }
    }

    if ((float) $result['confidence']['overall'] < $minimumConfidence) {
        purchase_ocr_review_flag('Overall OCR confidence is low. Review every field before creating the draft.', $reviewFlags);
    }

    $result['review_flags'] = array_values(array_unique($reviewFlags));

    return $result;
}

function purchase_ocr_merge_parsed_results(array $base, array $documents): array
{
    $merged = purchase_ocr_normalize_parsed_result($base, (string) ($base['text_excerpt'] ?? ''));
    $lineKeys = [];
    $confidenceScores = [(float) ($merged['confidence']['overall'] ?? 0)];
    $reviewFlags = is_array($merged['review_flags'] ?? null) ? $merged['review_flags'] : [];

    foreach ($merged['lines'] as $line) {
        $lineKeys[strtoupper((string) $line['item_name'] . '|' . (string) $line['item_sku'] . '|' . (string) $line['quantity_requested'] . '|' . (string) $line['unit_cost_quoted'])] = true;
    }

    foreach ($documents as $document) {
        if (!is_array($document)) {
            continue;
        }

        $normalized = purchase_ocr_normalize_parsed_result($document, (string) ($document['text_excerpt'] ?? $document['raw_text'] ?? ''));
        $confidenceScores[] = (float) ($normalized['confidence']['overall'] ?? 0);
        $reviewFlags = array_merge($reviewFlags, is_array($normalized['review_flags'] ?? null) ? $normalized['review_flags'] : []);

        foreach (['supplier', 'purchase'] as $section) {
            foreach ($normalized[$section] as $key => $value) {
                if (($merged[$section][$key] ?? '') === '' || ($section === 'supplier' && $key === 'supplier_type' && ($merged[$section][$key] ?? '') === 'product')) {
                    $merged[$section][$key] = $value;
                }
            }
        }

        foreach ($normalized['lines'] as $line) {
            $key = strtoupper((string) $line['item_name'] . '|' . (string) $line['item_sku'] . '|' . (string) $line['quantity_requested'] . '|' . (string) $line['unit_cost_quoted']);

            if (isset($lineKeys[$key])) {
                continue;
            }

            $lineKeys[$key] = true;
            $merged['lines'][] = $line;

            if (count($merged['lines']) >= 50) {
                break 2;
            }
        }

        if (!empty($normalized['text_excerpt'])) {
            $merged['text_excerpt'] = trim((string) $merged['text_excerpt'] . "\n\n" . (string) $normalized['text_excerpt']);
            $merged['text_excerpt'] = substr($merged['text_excerpt'], 0, 3000);
        }
    }

    $lineConfidenceScores = array_map(static fn (array $line): float => (float) ($line['confidence'] ?? 0.6), $merged['lines']);
    $merged['confidence']['overall'] = purchase_ocr_confidence_value(max($confidenceScores), 0.6);
    $merged['confidence']['lines'] = purchase_ocr_average_confidence($lineConfidenceScores, $merged['lines'] === [] ? 0.2 : 0.66);
    $merged['review_flags'] = array_values(array_unique(array_filter(array_map('strval', $reviewFlags))));

    return $merged;
}

function purchase_ocr_openai_enabled(): bool
{
    return openai_ocr_enabled()
        && openai_ocr_api_key() !== ''
        && function_exists('curl_init');
}

function purchase_ocr_openai_schema(): array
{
    $stringField = ['type' => 'string'];
    $numberField = ['type' => 'number'];

    return [
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => [
            'supplier' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'name' => $stringField,
                    'phone' => $stringField,
                    'email' => $stringField,
                    'tax_number' => $stringField,
                    'commercial_registration' => $stringField,
                    'national_address' => $stringField,
                    'authorized_person' => $stringField,
                    'supplier_type' => $stringField,
                    'supplier_type_other' => $stringField,
                    'confidence' => $numberField,
                ],
                'required' => ['name', 'phone', 'email', 'tax_number', 'commercial_registration', 'national_address', 'authorized_person', 'supplier_type', 'supplier_type_other', 'confidence'],
            ],
            'purchase' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'expected_date' => $stringField,
                    'currency' => $stringField,
                    'confidence' => $numberField,
                ],
                'required' => ['expected_date', 'currency', 'confidence'],
            ],
            'lines' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'item_name' => $stringField,
                        'item_sku' => $stringField,
                        'item_barcode' => $stringField,
                        'item_category' => $stringField,
                        'unit' => $stringField,
                        'quantity_requested' => $stringField,
                        'unit_cost_quoted' => $stringField,
                        'item_notes' => $stringField,
                        'confidence' => $numberField,
                        'review_flags' => [
                            'type' => 'array',
                            'items' => $stringField,
                        ],
                    ],
                    'required' => ['item_name', 'item_sku', 'item_barcode', 'item_category', 'unit', 'quantity_requested', 'unit_cost_quoted', 'item_notes', 'confidence', 'review_flags'],
                ],
            ],
            'confidence' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'overall' => $numberField,
                    'supplier' => $numberField,
                    'purchase' => $numberField,
                    'lines' => $numberField,
                    'engine' => $stringField,
                ],
                'required' => ['overall', 'supplier', 'purchase', 'lines', 'engine'],
            ],
            'review_flags' => [
                'type' => 'array',
                'items' => $stringField,
            ],
            'raw_text' => $stringField,
            'warnings' => [
                'type' => 'array',
                'items' => $stringField,
            ],
        ],
        'required' => ['supplier', 'purchase', 'lines', 'confidence', 'review_flags', 'raw_text', 'warnings'],
    ];
}

function purchase_ocr_openai_response_text(array $response): string
{
    if (isset($response['output_text']) && is_string($response['output_text'])) {
        return $response['output_text'];
    }

    foreach (($response['output'] ?? []) as $output) {
        if (!is_array($output)) {
            continue;
        }

        foreach (($output['content'] ?? []) as $content) {
            if (is_array($content) && isset($content['text']) && is_string($content['text'])) {
                return $content['text'];
            }
        }
    }

    return '';
}

function purchase_ocr_openai_extract_from_file(array $file, array $meta): array
{
    if (!purchase_ocr_openai_enabled()) {
        return [
            'parsed' => null,
            'text' => '',
            'warnings' => function_exists('curl_init') ? ['AI OCR is not configured. Add an OpenAI API key in Website Control to enable server-side scanned PDF OCR.'] : ['PHP cURL is not available, so AI OCR cannot run.'],
            'engine' => null,
        ];
    }

    $path = (string) ($file['tmp_name'] ?? '');
    $bytes = is_file($path) ? file_get_contents($path) : false;

    if ($bytes === false || $bytes === '') {
        return [
            'parsed' => null,
            'text' => '',
            'warnings' => ['Could not read the uploaded file for AI OCR.'],
            'engine' => null,
        ];
    }

    $mimeType = (string) $meta['mime_type'];
    $filename = basename((string) ($file['name'] ?? 'purchase-document'));
    $dataUrl = 'data:' . $mimeType . ';base64,' . base64_encode($bytes);
    $filePart = $mimeType === 'application/pdf'
        ? [
            'type' => 'input_file',
            'filename' => $filename !== '' ? $filename : 'purchase-document.pdf',
            'file_data' => $dataUrl,
        ]
        : [
            'type' => 'input_image',
            'image_url' => $dataUrl,
        ];
    $model = openai_ocr_model();
    $payload = [
        'model' => $model,
        'input' => [[
            'role' => 'user',
            'content' => [
                [
                    'type' => 'input_text',
                    'text' => 'Extract supplier purchase data from this Arabic/English quote, price list, receipt, or proof document. Return only data visible in the document. Use empty strings for unknown fields. Currency defaults to SAR when the document is Saudi. Supplier type must be product, service, or other. If supplier type is other, write the real custom type in supplier_type_other. Extract line items with item name, SKU/barcode if visible, unit, requested quantity, and unit price. Do not include totals, VAT, discounts, or heading rows as items. Return confidence values from 0 to 1 for supplier, purchase, each line, and overall confidence. Add review_flags for low-quality scans, unclear Arabic text, uncertain quantities, uncertain prices, generated/missing SKU, missing phone, missing authorized person, missing national address, or any field that needs human review.',
                ],
                $filePart,
            ],
        ]],
        'text' => [
            'format' => [
                'type' => 'json_schema',
                'name' => 'inventory_purchase_document',
                'strict' => true,
                'schema' => purchase_ocr_openai_schema(),
            ],
        ],
        'max_output_tokens' => 4000,
    ];

    $ch = curl_init('https://api.openai.com/v1/responses');

    if ($ch === false) {
        return [
            'parsed' => null,
            'text' => '',
            'warnings' => ['Could not initialize AI OCR request.'],
            'engine' => null,
        ];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . openai_ocr_api_key(),
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 120,
    ]);

    $rawResponse = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($rawResponse === false || $status < 200 || $status >= 300) {
        $message = $curlError !== '' ? $curlError : ('OpenAI OCR returned HTTP ' . $status);
        $decodedError = is_string($rawResponse) ? json_decode($rawResponse, true) : null;

        if (is_array($decodedError) && isset($decodedError['error']['message'])) {
            $message = (string) $decodedError['error']['message'];
        }

        return [
            'parsed' => null,
            'text' => '',
            'warnings' => ['AI OCR failed: ' . $message],
            'engine' => null,
        ];
    }

    $response = json_decode((string) $rawResponse, true);

    if (!is_array($response)) {
        return [
            'parsed' => null,
            'text' => '',
            'warnings' => ['AI OCR returned an unreadable response.'],
            'engine' => null,
        ];
    }

    $text = trim(purchase_ocr_openai_response_text($response));
    $decoded = json_decode($text, true);

    if (!is_array($decoded) && preg_match('/\{.*\}/s', $text, $match)) {
        $decoded = json_decode($match[0], true);
    }

    if (!is_array($decoded)) {
        return [
            'parsed' => null,
            'text' => $text,
            'warnings' => ['AI OCR returned text but it was not valid structured JSON.'],
            'engine' => 'openai:' . $model,
        ];
    }

    $normalized = purchase_ocr_normalize_parsed_result($decoded, (string) ($decoded['raw_text'] ?? ''));

    return [
        'parsed' => $normalized,
        'text' => (string) $normalized['text_excerpt'],
        'warnings' => array_values(array_filter(array_map('strval', is_array($decoded['warnings'] ?? null) ? $decoded['warnings'] : []))),
        'engine' => 'openai:' . $model,
    ];
}

function purchase_ocr_extract_date(string $text): string
{
    $text = purchase_ocr_ascii_digits($text);

    if (preg_match('/\b(20\d{2})[-\/.](\d{1,2})[-\/.](\d{1,2})\b/', $text, $match)) {
        return sprintf('%04d-%02d-%02d', (int) $match[1], (int) $match[2], (int) $match[3]);
    }

    if (preg_match('/\b(\d{1,2})[-\/.](\d{1,2})[-\/.](20\d{2})\b/', $text, $match)) {
        return sprintf('%04d-%02d-%02d', (int) $match[3], (int) $match[2], (int) $match[1]);
    }

    return '';
}

function purchase_ocr_is_summary_or_heading(string $line): bool
{
    return (bool) preg_match(
        '/\b(total|subtotal|vat|tax|discount|balance|amount due|grand total|invoice|quote|quotation|receipt|date|description\s+qty)\b|(?:الإجمالي|الاجمالي|المجموع|المبلغ|ضريبة|الضريبة|خصم|الرصيد|فاتورة|عرض\s+سعر|إيصال|ايصال|تاريخ|الوصف|البيان|الكمية|السعر)/iu',
        $line
    );
}

function purchase_ocr_parse_text(string $text): array
{
    $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
    $lines = array_values(array_filter(array_map(static function (string $line): string {
        return purchase_ocr_clean_text_line($line);
    }, explode("\n", $text)), static fn (string $line): bool => $line !== ''));
    $normalizedText = purchase_ocr_ascii_digits($text);

    $supplierName = '';
    foreach ($lines as $line) {
        if (purchase_ocr_is_summary_or_heading($line) || preg_match('/\b(price list|tax|vat)\b|(?:قائمة\s+أسعار|الرقم\s+الضريبي|ضريبي|سجل\s+تجاري)/iu', $line)) {
            continue;
        }

        if (strlen($line) >= 3) {
            $supplierName = substr($line, 0, 120);
            break;
        }
    }

    $email = '';
    if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $normalizedText, $match)) {
        $email = strtolower($match[0]);
    }

    $phone = '';
    if (preg_match('/(?:phone|tel|mobile|هاتف|جوال|موبايل|تليفون|تلفون)\D{0,20}(\+?\d[\d\s().-]{6,}\d)/iu', $normalizedText, $match)) {
        $phone = trim($match[1]);
    } elseif (preg_match('/(?:\+?\d[\d\s().-]{7,}\d)/', $normalizedText, $match)) {
        $phone = trim($match[0]);
    }

    $taxNumber = '';
    if (preg_match('/\b(?:VAT|TAX|TRN|CR|TIN)\s*(?:No\.?|Number|#|:)?\s*([A-Z0-9\-\s]{5,})/i', $normalizedText, $match)
        || preg_match('/(?:الرقم\s+الضريبي|رقم\s+ضريبي|ضريبة\s+القيمة\s+المضافة|السجل\s+التجاري|سجل\s+تجاري|الرقم\s+الموحد)\D{0,25}([A-Z0-9\-\s]{5,})/iu', $normalizedText, $match)
    ) {
        $taxNumber = strtoupper(preg_replace('/[^A-Z0-9\-]/i', '', $match[1]) ?: '');
    }

    $currency = 'SAR';
    if (preg_match('/\b(AED|USD|EUR|GBP|SAR)\b/i', $normalizedText, $match)) {
        $currency = strtoupper($match[1]);
    } elseif (preg_match('/(?:ر\.?\s*س|ريال|سعودي)/u', $text)) {
        $currency = 'SAR';
    } elseif (preg_match('/(?:د\.?\s*إ|درهم|اماراتي|إماراتي)/u', $text)) {
        $currency = 'AED';
    }

    $parsedLines = [];
    $seen = [];

    foreach ($lines as $rawLine) {
        $line = trim(str_replace(['SAR', 'ر.س', 'ر س', 'ريال', 'سعودي'], ' ', purchase_ocr_clean_text_line($rawLine)));

        if ($line === '' || purchase_ocr_is_summary_or_heading($line)) {
            continue;
        }

        preg_match_all('/(?<![A-Z0-9])(?:\d{1,3}(?:,\d{3})*(?:\.\d+)?|\d+(?:[\.,]\d+)?)(?![A-Z0-9])/i', $line, $matches, PREG_OFFSET_CAPTURE);

        if (count($matches[0]) < 2) {
            continue;
        }

        $numbers = [];
        foreach ($matches[0] as $match) {
            $numbers[] = [
                'raw' => $match[0],
                'value' => purchase_ocr_normalize_number($match[0]),
                'offset' => (int) $match[1],
            ];
        }

        $priceIndex = count($numbers) >= 3 ? count($numbers) - 2 : count($numbers) - 1;
        $quantityIndex = count($numbers) >= 3 ? max(0, $priceIndex - 1) : 0;
        $quantity = $numbers[$quantityIndex]['value'];
        $unitCost = $numbers[$priceIndex]['value'];

        if ($quantity <= 0 || $unitCost < 0) {
            continue;
        }

        $namePart = trim(substr($line, 0, $numbers[$quantityIndex]['offset']));
        $namePart = preg_replace('/^\d+\s+/', '', $namePart) ?: $namePart;
        $namePart = trim(preg_replace('/\b(qty|quantity|unit|price|amount|total|item|description)\b|(?:الكمية|كمية|الوحدة|وحدة|السعر|المبلغ|الإجمالي|الاجمالي|الصنف|البند|الوصف|البيان)/iu', ' ', $namePart) ?: '');
        $namePart = trim(preg_replace('/\s+/u', ' ', $namePart) ?: '');

        if ($namePart === '' || strlen($namePart) < 2) {
            continue;
        }

        $sku = '';
        if (preg_match('/\b([A-Z0-9]+-[A-Z0-9-]+|[A-Z]{2,}\d[A-Z0-9-]*)\b/i', $namePart, $skuMatch)) {
            $sku = strtoupper($skuMatch[1]);
            $namePart = trim(str_replace($skuMatch[1], '', $namePart));
        }

        $name = trim($namePart) !== '' ? trim($namePart) : 'Imported Item';
        $sku = $sku !== '' ? $sku : purchase_ocr_generated_sku($name, count($parsedLines) + 1);
        $key = strtoupper($name . '|' . $sku . '|' . $quantity . '|' . $unitCost);

        if (isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $catalogItem = purchase_ocr_catalog_match($name, $sku);

        $parsedLines[] = [
            'item_id' => $catalogItem ? (int) $catalogItem['id'] : '',
            'item_name' => $catalogItem ? (string) $catalogItem['name'] : $name,
            'item_sku' => $catalogItem ? (string) $catalogItem['sku'] : $sku,
            'item_barcode' => $catalogItem ? (string) ($catalogItem['barcode'] ?? '') : '',
            'item_category' => $catalogItem ? (string) ($catalogItem['category'] ?? '') : '',
            'unit' => $catalogItem ? (string) $catalogItem['unit'] : purchase_ocr_normalize_unit($rawLine),
            'quantity_requested' => format_quantity($quantity),
            'unit_cost_quoted' => format_quantity($unitCost),
            'item_notes' => $catalogItem ? (string) ($catalogItem['notes'] ?? '') : 'Imported from document OCR. Verify before submitting.',
        ];

        if (count($parsedLines) >= 50) {
            break;
        }
    }

    return [
        'supplier' => [
            'name' => $supplierName,
            'phone' => $phone,
            'email' => $email,
            'tax_number' => $taxNumber,
            'commercial_registration' => '',
            'national_address' => '',
            'authorized_person' => '',
            'supplier_type' => 'product',
            'supplier_type_other' => '',
        ],
        'purchase' => [
            'expected_date' => purchase_ocr_extract_date($text),
            'currency' => $currency,
        ],
        'lines' => $parsedLines,
        'text_excerpt' => substr($text, 0, 3000),
    ];
}

function handle_purchase_ocr_preview_submit(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('purchases.create');
    verify_csrf();

    $manualText = trim((string) input('ocr_text', ''));
    $requestedEngine = trim((string) input('ocr_engine', 'auto'));
    $requestedEngine = in_array($requestedEngine, ['auto', 'free', 'openai'], true) ? $requestedEngine : 'auto';
    $canRunAi = purchase_ocr_openai_enabled() && purchase_ocr_mode() !== 'free_only';
    $warnings = [];
    $engines = [];
    $text = '';
    $parsedDocuments = [];
    $ocrRunIds = [];

    if ($manualText !== '') {
        $text = $manualText;
        $engines[] = 'browser';
        $manualParsed = purchase_ocr_normalize_parsed_result(purchase_ocr_parse_text($manualText), $manualText);
        $runId = purchase_ocr_log_run([
            'source_filename' => trim((string) input('ocr_source_name', 'Browser OCR text')),
            'mime_type' => 'text/plain',
            'engine' => 'browser',
            'confidence' => (float) ($manualParsed['confidence']['overall'] ?? 0),
            'parsed_line_count' => count($manualParsed['lines'] ?? []),
            'warnings' => $manualParsed['review_flags'] ?? [],
            'text_excerpt' => $manualText,
        ]);

        if ($runId !== null) {
            $ocrRunIds[] = $runId;
        }
    } else {
        $files = uploaded_files('documents');

        if ($files === []) {
            json_response([
                'ok' => false,
                'message' => 'Select at least one quote, price list, or receipt file first.',
            ], 422);
        }

        foreach ($files as $file) {
            $error = validate_purchase_document_upload($file);

            if ($error !== null) {
                json_response([
                    'ok' => false,
                    'message' => $error,
                ], 422);
            }

            $result = purchase_ocr_extract_text_from_file($file, $requestedEngine);
            $text .= "\n" . (string) $result['text'];
            $warnings = array_merge($warnings, $result['warnings']);
            $documentParsed = null;

            if (is_array($result['parsed'] ?? null)) {
                $parsedDocuments[] = $result['parsed'];
                $documentParsed = $result['parsed'];
            } elseif (trim((string) ($result['text'] ?? '')) !== '') {
                $documentParsed = purchase_ocr_normalize_parsed_result(purchase_ocr_parse_text((string) $result['text']), (string) $result['text']);
            }

            if (!empty($result['engine'])) {
                $engines[] = (string) $result['engine'];
            }

            $runId = purchase_ocr_log_run([
                'source_filename' => (string) ($file['name'] ?? ''),
                'mime_type' => (string) (purchase_document_file_meta($file)['mime_type'] ?? ''),
                'engine' => (string) ($result['engine'] ?? ($requestedEngine === 'openai' ? 'openai' : 'none')),
                'confidence' => is_array($documentParsed) ? (float) ($documentParsed['confidence']['overall'] ?? 0) : 0.0,
                'parsed_line_count' => is_array($documentParsed) ? count($documentParsed['lines'] ?? []) : 0,
                'warnings' => array_values(array_unique(array_merge($result['warnings'] ?? [], is_array($documentParsed) ? ($documentParsed['review_flags'] ?? []) : []))),
                'text_excerpt' => is_array($documentParsed) ? (string) ($documentParsed['text_excerpt'] ?? '') : (string) ($result['text'] ?? ''),
            ]);

            if ($runId !== null) {
                $ocrRunIds[] = $runId;
            }
        }
    }

    $text = trim($text);

    if ($text === '' && $parsedDocuments === []) {
        json_response([
            'ok' => false,
            'message' => purchase_ocr_openai_enabled()
                ? 'No readable purchase data was found. Review the scan quality or type the lines manually.'
                : 'No readable text was found. Configure AI OCR for scanned PDFs, use JPG/PNG/WebP browser OCR, or type the lines manually.',
            'needs_browser_ocr' => true,
            'can_run_ai' => $canRunAi,
            'ocr_mode' => purchase_ocr_mode(),
            'ocr_run_ids' => $ocrRunIds,
            'warnings' => array_values(array_unique($warnings)),
        ], 422);
    }

    $parsed = $text !== '' ? purchase_ocr_parse_text($text) : purchase_ocr_empty_result();
    $parsed = purchase_ocr_merge_parsed_results($parsed, $parsedDocuments);
    $lineCount = count($parsed['lines']);
    $parsed['confidence']['engine'] = implode('+', array_values(array_unique($engines)));
    $reviewFlags = array_values(array_unique(array_filter(array_map('strval', $parsed['review_flags'] ?? []))));

    json_response([
        'ok' => true,
        'message' => $lineCount > 0
            ? 'Extracted ' . $lineCount . ' possible line item' . ($lineCount === 1 ? '' : 's') . '. Review before submitting.'
            : 'Text was extracted, but no item rows were confidently detected. Review the text and add lines manually.',
        'engine' => implode('+', array_values(array_unique($engines))),
        'warnings' => array_values(array_unique(array_merge($warnings, $reviewFlags))),
        'review_flags' => $reviewFlags,
        'ocr_mode' => purchase_ocr_mode(),
        'can_run_ai' => $canRunAi,
        'min_confidence' => purchase_ocr_min_confidence(),
        'ocr_run_ids' => $ocrRunIds,
        'parsed' => $parsed,
    ]);
}
