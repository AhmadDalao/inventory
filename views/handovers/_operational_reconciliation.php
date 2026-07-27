<?php
$operationalApprovalMode = !empty($operationalApprovalMode);
$operationalLines = is_array($operationalLines ?? null) ? $operationalLines : [];
$operationalReconciliations = is_array($operationalReconciliations ?? null) ? $operationalReconciliations : [];
$operationalReasonOptions = is_array($operationalReasonOptions ?? null)
    ? $operationalReasonOptions
    : handover_operational_reason_options();
$varianceReasonOptions = is_array($varianceReasonOptions ?? null)
    ? $varianceReasonOptions
    : handover_reconciliation_variance_reason_options();
$operationalLineGroups = handover_reconciliation_line_groups($operationalLines);
$oldReturned = old('line_returned', []);
$oldReconciliationRows = handover_reconciliation_post_rows(old('reconciliation', []));
?>

<section class="handover-operational-workspace" data-handover-operational-workspace>
    <div class="copy-context-card">
        <strong><?= $operationalApprovalMode ? 'Owner Final Reconciliation' : 'Operational Reconciliation' ?></strong>
        <p>
            <?= $operationalApprovalMode
                ? 'Correct returned quantities or operational totals before approval. Stock posts only after this review.'
                : 'Enter what came back. Used stock is calculated automatically, then reconcile it against the final operational totals.' ?>
        </p>
    </div>

    <div class="table-wrap handover-operational-items-wrap">
        <table class="data-table handover-operational-items">
            <thead>
            <tr>
                <th>Item</th>
                <th>Received</th>
                <th>Returned</th>
                <th>Used</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($operationalLines as $line): ?>
                <?php
                $lineId = (int) $line['id'];
                $received = handover_active_quantity($line);
                $savedReturned = round((float) ($line['quantity_returned'] ?? 0), 2);

                if (!$operationalApprovalMode && round((float) ($line['quantity_used'] ?? 0), 2) <= 0 && $savedReturned <= 0) {
                    $savedReturned = $received;
                }

                $returnedValue = is_array($oldReturned) && array_key_exists($lineId, $oldReturned)
                    ? (string) $oldReturned[$lineId]
                    : format_quantity($savedReturned);
                $usedValue = max(0, round($received - (float) $returnedValue, 2));
                $lineImageUrl = item_image_url($line['image_path'] ?? null);
                $unit = normalize_handover_reconciliation_unit((string) ($line['unit'] ?? 'pcs'));
                ?>
                <tr
                    data-handover-operational-line
                    data-handover-unit="<?= e($unit) ?>"
                    data-handover-received="<?= e(format_quantity($received)) ?>"
                >
                    <td>
                        <div class="item-table-cell">
                            <?php if ($lineImageUrl): ?>
                                <img class="item-thumb expandable-image" src="<?= e($lineImageUrl) ?>" alt="<?= e($line['item_name']) ?>" data-expand-image tabindex="0">
                            <?php else: ?>
                                <span class="item-thumb item-thumb-fallback"><?= e(item_initial((string) $line['item_name'])) ?></span>
                            <?php endif; ?>
                            <div>
                                <strong><?= e($line['item_name']) ?></strong>
                                <span class="tiny-copy"><?= e($line['item_sku']) ?> · <?= e($unit) ?></span>
                            </div>
                        </div>
                    </td>
                    <td><strong><?= format_quantity($received) ?></strong> <?= e($unit) ?></td>
                    <td>
                        <input
                            type="number"
                            step="0.01"
                            min="0"
                            max="<?= e(format_quantity($received)) ?>"
                            name="line_returned[<?= e((string) $lineId) ?>]"
                            value="<?= e($returnedValue) ?>"
                            aria-label="Returned quantity for <?= e($line['item_name']) ?>"
                            data-handover-operational-returned
                            required
                        >
                    </td>
                    <td>
                        <strong data-handover-operational-used><?= format_quantity($usedValue) ?></strong>
                        <?= e($unit) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php foreach ($operationalLineGroups as $unit => $unitLines): ?>
        <?php
        $savedReconciliation = (array) ($operationalReconciliations[$unit] ?? []);
        $inputRow = (array) ($oldReconciliationRows[$unit] ?? []);
        $savedEntries = (array) ($savedReconciliation['entries'] ?? []);
        $unitReceived = array_sum(array_map(static fn (array $line): float => handover_active_quantity($line), $unitLines));
        $unitReturned = 0.0;

        foreach ($unitLines as $line) {
            $lineId = (int) $line['id'];
            $lineReturned = is_array($oldReturned) && array_key_exists($lineId, $oldReturned)
                ? (float) $oldReturned[$lineId]
                : (float) ($line['quantity_returned'] ?? 0);

            if (!$operationalApprovalMode && round((float) ($line['quantity_used'] ?? 0), 2) <= 0 && $lineReturned <= 0) {
                $lineReturned = handover_active_quantity($line);
            }

            $unitReturned += $lineReturned;
        }

        $reasonValues = [];

        foreach ($operationalReasonOptions as $reasonCode => $reasonLabel) {
            $reasonValues[$reasonCode] = isset($inputRow['reasons'][$reasonCode])
                ? (string) $inputRow['reasons'][$reasonCode]
                : format_quantity((float) ($savedEntries[$reasonCode]['quantity'] ?? 0));
        }

        $online = (float) ($reasonValues['online'] ?? 0);
        $noShow = (float) ($reasonValues['noshow'] ?? 0);
        $operationalUsed = round(
            $online
            - $noShow
            + (float) ($reasonValues['walkin'] ?? 0)
            + (float) ($reasonValues['event'] ?? 0)
            + (float) ($reasonValues['sport'] ?? 0)
            + (float) ($reasonValues['damage'] ?? 0)
            + (float) ($reasonValues['complimentary'] ?? 0)
            + (float) ($reasonValues['other'] ?? 0),
            2
        );
        $physicalUsed = round($unitReceived - $unitReturned, 2);
        $difference = round($physicalUsed - $operationalUsed, 2);
        $discrepancyNotes = (string) ($inputRow['discrepancy_notes'] ?? $savedReconciliation['discrepancy_notes'] ?? '');
        $varianceReason = (string) ($inputRow['variance_reason_code'] ?? $savedReconciliation['variance_reason_code'] ?? '');
        $varianceNotes = (string) ($inputRow['variance_notes'] ?? $savedReconciliation['variance_notes'] ?? '');
        ?>
        <section class="handover-reconciliation-panel" data-handover-reconciliation data-handover-unit="<?= e($unit) ?>">
            <input type="hidden" name="reconciliation[<?= e($unit) ?>][unit]" value="<?= e($unit) ?>">

            <div class="handover-reconciliation-head">
                <div>
                    <p class="eyebrow">Operational Totals</p>
                    <h4><?= e($unit) ?> Reconciliation</h4>
                </div>
                <span class="handover-reconciliation-state <?= abs($difference) < 0.01 ? 'is-reconciled' : 'is-difference' ?>" data-handover-reconciliation-state>
                    <?= abs($difference) < 0.01 ? 'Reconciled' : 'Difference ' . format_quantity($difference) . ' ' . e($unit) ?>
                </span>
            </div>

            <div class="handover-reconciliation-reasons">
                <?php foreach ($operationalReasonOptions as $reasonCode => $reasonLabel): ?>
                    <label class="field">
                        <span><?= e($reasonLabel) ?></span>
                        <input
                            type="number"
                            step="0.01"
                            min="0"
                            name="reconciliation[<?= e($unit) ?>][reasons][<?= e($reasonCode) ?>]"
                            value="<?= e($reasonValues[$reasonCode]) ?>"
                            data-handover-reconciliation-reason="<?= e($reasonCode) ?>"
                        >
                    </label>
                <?php endforeach; ?>
            </div>

            <div class="handover-reconciliation-totals">
                <div><span>Confirmed Received</span><strong data-handover-reconciliation-received><?= format_quantity($unitReceived) ?></strong></div>
                <div><span>Total Returned</span><strong data-handover-reconciliation-returned><?= format_quantity($unitReturned) ?></strong></div>
                <div><span>Physical Used</span><strong data-handover-reconciliation-physical><?= format_quantity($physicalUsed) ?></strong></div>
                <div><span>Operational Used</span><strong data-handover-reconciliation-operational><?= format_quantity($operationalUsed) ?></strong></div>
                <div class="<?= abs($difference) < 0.01 ? 'is-reconciled' : 'is-difference' ?>" data-handover-reconciliation-difference-card>
                    <span>Difference</span>
                    <strong data-handover-reconciliation-difference><?= format_quantity($difference) ?></strong>
                </div>
            </div>

            <label class="field handover-reconciliation-discrepancy">
                <span>Receiver Discrepancy Note</span>
                <textarea
                    name="reconciliation[<?= e($unit) ?>][discrepancy_notes]"
                    rows="3"
                    placeholder="Required when Difference is positive"
                    data-handover-reconciliation-discrepancy
                ><?= e($discrepancyNotes) ?></textarea>
                <small>Explain any physically used stock that is not covered by the operational totals.</small>
            </label>

            <?php if ($operationalApprovalMode): ?>
                <div class="handover-reconciliation-approval">
                    <label class="field">
                        <span>Audited Variance Reason</span>
                        <select name="reconciliation[<?= e($unit) ?>][variance_reason_code]" data-handover-reconciliation-variance-reason>
                            <option value="">Select only when Difference is positive</option>
                            <?php foreach ($varianceReasonOptions as $reasonCode => $reasonLabel): ?>
                                <option value="<?= e($reasonCode) ?>" <?= $varianceReason === $reasonCode ? 'selected' : '' ?>><?= e($reasonLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="field">
                        <span>Approval Variance Note</span>
                        <textarea name="reconciliation[<?= e($unit) ?>][variance_notes]" rows="3" placeholder="Required when approving a positive Difference" data-handover-reconciliation-variance-note><?= e($varianceNotes) ?></textarea>
                    </label>
                </div>
            <?php endif; ?>

            <p class="danger-copy" data-handover-reconciliation-warning <?= abs($difference) < 0.01 ? 'hidden' : '' ?>>
                Difference must be reviewed. Negative Difference cannot be submitted; positive Difference requires the required notes.
            </p>
        </section>
    <?php endforeach; ?>
</section>
