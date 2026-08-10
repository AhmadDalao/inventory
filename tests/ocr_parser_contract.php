<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/modules.php';

function assert_ocr_phone(string $text, string $expected, string $message): void
{
    $actual = purchase_ocr_extract_phone($text);

    if ($actual !== $expected) {
        fwrite(STDERR, $message . ' Expected ' . $expected . ', got ' . ($actual === '' ? '(empty)' : $actual) . ".\n");
        exit(1);
    }
}

$prefix = 'ZZMOBILE20260810';
$arabicFixture = implode("\n", [
    'شركة ' . $prefix . ' العربية للتوريدات',
    'الرقم الضريبي: ٣١٠١٢٣٤٥٦٧٠٠٠٠٣',
    'الهاتف: ٠٥٥١٢٣٤٥٦٧',
    'تاريخ: ٢٠٢٦/٠٦/٢٦',
]);

assert_ocr_phone($arabicFixture, '0551234567', 'Arabic phone labels must outrank unrelated MOBILE text.');
assert_ocr_phone("ACME MOBILE20260810\nPhone: +966 55 123 4567", '+966551234567', 'English phone labels must be line-scoped.');
assert_ocr_phone("VAT: 310123456700003\nCall 055-765-4321", '0557654321', 'Saudi phone fallback must not select a VAT number.');

echo "OCR parser contract checks passed.\n";
