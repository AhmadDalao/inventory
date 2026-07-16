<?php
declare(strict_types=1);

// Domain module: OpenAI OCR adapter for purchase document extraction.
// Function names are preserved for route/view/test compatibility.

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
