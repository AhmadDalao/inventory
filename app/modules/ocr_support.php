<?php
declare(strict_types=1);

// Domain module: OCR health checks and OCR run logging helpers.
// Function names are preserved for route/view/test compatibility.

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
