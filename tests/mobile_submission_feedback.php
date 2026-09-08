<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$checks = [
    'mobile/lib/features/movements/usage_cart_screen.dart' => ['Future<void> _submit(', 'catch (error)', 'apiErrorMessage(error)'],
    'mobile/lib/features/movements/refill_cart_screen.dart' => ['Future<void> _submit(', 'catch (error)', 'apiErrorMessage(error)'],
    'mobile/lib/features/handovers/create_handover_screen.dart' => ['Future<void> _submit(', 'catch (error)', 'apiErrorMessage(error)'],
    'mobile/lib/features/handovers/handover_receipt_screen.dart' => ['Future<void> _submit(', 'catch (error)', 'apiErrorMessage(error)'],
    'mobile/lib/features/handovers/handover_closeout_screen.dart' => ['Future<void> _submit(', 'catch (error)', 'apiErrorMessage(error)'],
    'mobile/lib/features/handovers/custody_return_screen.dart' => ['Future<void> _submit(', 'catch (error)', 'apiErrorMessage(error)'],
    'mobile/lib/features/handovers/handover_detail_screen.dart' => ['Future<void> _decide(', 'Future<void> _cancel(', 'catch (error)', 'apiErrorMessage(error)'],
    'mobile/lib/features/inventory/quantity_check_screen.dart' => ['Future<void> _lookup(', 'catch (error)', 'apiErrorMessage(error)'],
];

$failures = [];
foreach ($checks as $relativePath => $markers) {
    $source = file_get_contents($root . '/' . $relativePath);
    if ($source === false) {
        $failures[] = $relativePath . ': unreadable';
        continue;
    }

    foreach ($markers as $marker) {
        if (!str_contains($source, $marker)) {
            $failures[] = $relativePath . ': missing ' . $marker;
        }
    }
}

if ($failures !== []) {
    fwrite(STDERR, "[mobile-submission-feedback] FAIL:\n- " . implode("\n- ", $failures) . PHP_EOL);
    exit(1);
}

echo '[mobile-submission-feedback] PASS (' . count($checks) . ' mobile action screens checked)' . PHP_EOL;
