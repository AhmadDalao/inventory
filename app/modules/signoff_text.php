<?php
declare(strict_types=1);

// PDF-safe text helpers used by signoff renderers.

function workflow_pdf_escape(string $value): string
{
    $value = preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '?', $value) ?? '';

    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
}

function workflow_pdf_wrapped_lines(string $text, int $maxLength = 88): array
{
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');

    if ($text === '') {
        return [''];
    }

    return explode("\n", wordwrap($text, $maxLength, "\n", true));
}
