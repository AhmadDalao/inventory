<?php
declare(strict_types=1);

$finalSignoffRows = workflow_signoff_rows('handover', $lines, $handoverRecord);
$finalSignoffTotals = workflow_signoff_totals('handover', $finalSignoffRows, $handoverRecord);
$finalReconciliationRows = (array) ($finalSignoffTotals['reconciliation_table_rows'] ?? []);
$finalReconciliationTitle = $isStorageTransfer
    ? 'Storage Transfer Reconciliation'
    : ($isStaffCustody
        ? 'Custody Reconciliation'
        : ($usesOperationalReconciliation ? 'Operational Reconciliation' : 'Final Handover Reconciliation'));
$finalReconciliationEyebrow = $isStorageTransfer
    ? 'Destination accounting'
    : ($isStaffCustody ? 'Custody accounting' : 'Final usage');
$finalReconciliationDescription = $isStorageTransfer
    ? 'Final quantities accepted by the destination and returned or adjusted at the source.'
    : ($isStaffCustody
        ? 'Final returned, quarantined, consumed, lost, and still-held quantities.'
        : 'The approved physical quantities and operational totals recorded when this handover closed.');
$finalHasExpectedColumns = false;
$finalDifferenceLabels = [];

foreach ($finalReconciliationRows as $finalReconciliationRow) {
    if (($finalReconciliationRow['expected'] ?? '') !== '' || ($finalReconciliationRow['difference'] ?? '') !== '') {
        $finalHasExpectedColumns = true;
    }

    if ((string) ($finalReconciliationRow['type'] ?? '') === 'difference' && is_numeric($finalReconciliationRow['actual'] ?? null)) {
        $differenceValue = (float) $finalReconciliationRow['actual'];

        if (abs($differenceValue) >= 0.01) {
            $differenceUnit = trim((string) ($finalReconciliationRow['unit'] ?? ''));
            $finalDifferenceLabels[] = format_quantity($differenceValue) . ($differenceUnit !== '' ? ' ' . $differenceUnit : '');
        }
    }
}

$finalHasDifference = $finalDifferenceLabels !== [];

$finalReconciliationValue = static function ($value, string $unit): string {
    if ($value === '' || $value === null) {
        return '—';
    }

    if (!is_numeric($value)) {
        return trim((string) $value) !== '' ? (string) $value : '—';
    }

    $formatted = format_quantity((float) $value);

    return $unit !== '' ? $formatted . ' ' . $unit : $formatted;
};
?>

<?php if ($finalReconciliationRows !== []): ?>
<section class="panel handover-final-reconciliation" data-handover-final-reconciliation>
    <div class="panel-head handover-final-reconciliation-head">
        <div>
            <p class="eyebrow"><?= e($finalReconciliationEyebrow) ?></p>
            <h3><?= e($finalReconciliationTitle) ?></h3>
            <p><?= e($finalReconciliationDescription) ?></p>
        </div>
        <span class="handover-reconciliation-state <?= !$finalHasDifference ? 'is-reconciled' : 'is-difference' ?>">
            <?= !$finalHasDifference
                ? 'Reconciled'
                : 'Difference ' . e(implode(' · ', $finalDifferenceLabels)) ?>
        </span>
    </div>

    <div class="table-wrap handover-final-reconciliation-table-wrap">
        <table class="data-table handover-final-reconciliation-table">
            <thead>
            <tr>
                <th>Type</th>
                <?php if ($finalHasExpectedColumns): ?>
                    <th>Expected</th>
                    <th>Actual</th>
                    <th>Variance</th>
                <?php else: ?>
                    <th>Quantity</th>
                <?php endif; ?>
                <th>Notes</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($finalReconciliationRows as $finalReconciliationRow): ?>
                <?php
                $rowType = (string) ($finalReconciliationRow['type'] ?? '');
                $rowUnit = trim((string) ($finalReconciliationRow['unit'] ?? ''));
                $rowIsDifference = $rowType === 'difference';
                $rowDifference = $finalReconciliationRow['difference'] ?? '';
                ?>
                <?php if ($rowType === 'unit_header'): ?>
                    <tr class="handover-final-reconciliation-unit-row">
                        <th colspan="<?= $finalHasExpectedColumns ? '5' : '3' ?>"><?= e((string) ($finalReconciliationRow['label'] ?? '')) ?></th>
                    </tr>
                <?php else: ?>
                    <tr class="<?= $rowIsDifference ? 'handover-final-reconciliation-difference-row' : '' ?>">
                        <th scope="row"><?= e((string) ($finalReconciliationRow['label'] ?? '')) ?></th>
                        <?php if ($finalHasExpectedColumns): ?>
                            <td><?= e($finalReconciliationValue($finalReconciliationRow['expected'] ?? '', $rowUnit)) ?></td>
                            <td><?= e($finalReconciliationValue($finalReconciliationRow['actual'] ?? '', $rowUnit)) ?></td>
                            <td class="<?= is_numeric($rowDifference) && abs((float) $rowDifference) >= 0.01 ? 'is-variance' : '' ?>">
                                <?= e($finalReconciliationValue($rowDifference, $rowUnit)) ?>
                            </td>
                        <?php else: ?>
                            <td><?= e($finalReconciliationValue($finalReconciliationRow['actual'] ?? '', $rowUnit)) ?></td>
                        <?php endif; ?>
                        <td><?= e((string) (($finalReconciliationRow['notes'] ?? '') ?: '—')) ?></td>
                    </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="handover-final-reconciliation-audit">
        <div>
            <span>Submitted by</span>
            <strong><?= e((string) ($handoverRecord['submitted_by_name'] ?: 'Not recorded')) ?></strong>
            <small><?= !empty($handoverRecord['submitted_at']) ? e(format_datetime_display((string) $handoverRecord['submitted_at'])) : 'Time not recorded' ?></small>
        </div>
        <div>
            <span>Approved by</span>
            <strong><?= e((string) ($handoverRecord['approved_by_name'] ?: 'Not recorded')) ?></strong>
            <small><?= !empty($handoverRecord['approved_at']) ? e(format_datetime_display((string) $handoverRecord['approved_at'])) : 'Time not recorded' ?></small>
        </div>
    </div>
</section>
<?php endif; ?>
