<?php
declare(strict_types=1);

function normalize_site_settings_payload(array $submitted, array $clearSubmitted = [], bool $allowSecrets = true): array
{
    $payload = [];
    $errors = [];
    $skipped = [];

    foreach (site_setting_definitions() as $key => $field) {
        $value = trim((string) ($submitted[$key] ?? ''));
        $maxlength = (int) ($field['maxlength'] ?? 160);
        $options = $field['options'] ?? null;
        $type = (string) ($field['type'] ?? 'text');

        if ($type === 'secret') {
            if (!$allowSecrets) {
                $skipped[] = $key;
                continue;
            }

            $clearSecret = isset($clearSubmitted[$key]) && (string) $clearSubmitted[$key] === '1';

            if ($clearSecret) {
                $payload[$key] = '';
                continue;
            }

            if ($value === '') {
                $skipped[] = $key;
                continue;
            }
        }

        if ($maxlength > 0 && strlen($value) > $maxlength) {
            $errors[] = $field['label'] . ' must be ' . $maxlength . ' characters or less.';
        }

        if (in_array($type, ['select', 'choice'], true) && is_array($options)) {
            if ($value === '') {
                $value = (string) ($field['default'] ?? '');
            }

            if (!array_key_exists($value, $options)) {
                $errors[] = $field['label'] . ' has an invalid selection.';
                $value = (string) ($field['default'] ?? '');
            }
        }

        if ($type === 'email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $errors[] = $field['label'] . ' must be a valid email address.';
        }

        if ($type === 'number' && $value !== '' && !ctype_digit($value)) {
            $errors[] = $field['label'] . ' must be a whole number.';
        }

        if ($key === 'email.smtp_port' && $value !== '') {
            $port = (int) $value;

            if ($port < 1 || $port > 65535) {
                $errors[] = 'SMTP port must be between 1 and 65535.';
            }
        }

        if (in_array($key, ['workflow.signoff_image_custom_width', 'workflow.signoff_image_custom_height'], true) && $value !== '') {
            $size = (int) $value;

            if ($size < 40 || $size > 600) {
                $errors[] = $field['label'] . ' must be between 40 and 600 pixels.';
            }
        }

        if (in_array($key, ['exports.item_xlsx_thumbnail_custom_width', 'exports.item_xlsx_thumbnail_custom_height'], true) && $value !== '') {
            $size = (int) $value;

            if ($size < 40 || $size > 500) {
                $errors[] = $field['label'] . ' must be between 40 and 500 pixels.';
            }
        }

        if ($key === 'ocr.max_pdf_pages' && $value !== '') {
            $pageCount = (int) $value;

            if ($pageCount < 1 || $pageCount > 20) {
                $errors[] = 'Max PDF pages per file must be between 1 and 20.';
            }
        }

        if ($key === 'ocr.min_confidence' && $value !== '') {
            $confidence = (int) $value;

            if ($confidence < 1 || $confidence > 95) {
                $errors[] = 'Minimum confidence percent must be between 1 and 95.';
            }
        }

        $payload[$key] = $value;
    }

    return [$payload, $errors, $skipped];
}
