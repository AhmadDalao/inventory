<?php
declare(strict_types=1);

// Domain module: purchase OCR text normalization and parsing helpers. Function names are preserved for route/view compatibility.
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

