<?php
declare(strict_types=1);

// Domain module: OCR extraction, OpenAI fallback orchestration, and preview handlers.
// Function names are preserved for route/view compatibility.
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
