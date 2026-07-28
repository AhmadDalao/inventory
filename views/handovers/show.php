<?php
$currentUser = Auth::user();
$documents = $documents ?? [];
$signoffDocuments = array_values(array_filter($documents, static fn (array $document): bool => (string) $document['document_type'] === 'signoff_pdf'));
$excelDocuments = array_values(array_filter($documents, static fn (array $document): bool => (string) $document['document_type'] === 'signoff_excel'));
$proofDocuments = array_values(array_filter($documents, static fn (array $document): bool => (string) $document['document_type'] === 'proof_image'));
$signoffDocuments = array_slice($signoffDocuments, 0, 1);
$excelDocuments = array_slice($excelDocuments, 0, 1);
$canEditHandoverLines = !empty($canEditHandoverLines);
$sourceStorages = $sourceStorages ?? [];
$editableLineItems = old('edit_line_items', array_map(static fn (array $line): array => [
    'item_id' => (string) $line['item_id'],
    'quantity' => format_quantity($line['quantity_handed']),
], $lines));
$editableLineItems = is_array($editableLineItems) && $editableLineItems !== []
    ? $editableLineItems
    : [['item_id' => '', 'quantity' => '']];
$workflowCatalogPreview = json_decode((string) ($storageCatalogJson ?? '{}'), true);
$workflowCatalogItemsById = [];

if (is_array($workflowCatalogPreview)) {
    foreach ($workflowCatalogPreview as $catalogItems) {
        if (!is_array($catalogItems)) {
            continue;
        }

        foreach ($catalogItems as $catalogItem) {
            if (!is_array($catalogItem) || empty($catalogItem['id'])) {
                continue;
            }

            $workflowCatalogItemsById[(string) $catalogItem['id']] = $catalogItem;
        }
    }
}

$workflowLinePreviewItem = static function (array $line) use ($workflowCatalogItemsById): ?array {
    $itemId = (string) ($line['item_id'] ?? '');

    return $itemId !== '' && isset($workflowCatalogItemsById[$itemId])
        ? $workflowCatalogItemsById[$itemId]
        : null;
};
$statusLabel = handover_status_label((string) $handoverRecord['status']);
$isRequestMode = (string) ($handoverRecord['handover_mode'] ?? 'direct') === 'request';
$isStorageTransfer = handover_is_storage_transfer($handoverRecord);
$isStaffCustody = handover_is_staff_custody($handoverRecord);
$custodyReturns = is_array($custodyReturns ?? null) ? $custodyReturns : [];
$custodyTotals = is_array($custodyTotals ?? null) ? $custodyTotals : [];
$custodyLineTotals = is_array($custodyLineTotals ?? null) ? $custodyLineTotals : [];
$pendingCustodyReturn = is_array($pendingCustodyReturn ?? null) ? $pendingCustodyReturn : null;
$canReportCustodyReturn = !empty($canReportCustodyReturn);
$canReviewCustodyReturn = !empty($canReviewCustodyReturn);
$isSourceOwner = handover_is_source_issuer($handoverRecord, $currentUser);
$canApproveRequest = Auth::hasPermission('handovers.approve')
    && handover_request_decision_block_reason($handoverRecord, $currentUser) === null;
$canRejectRequest = $canApproveRequest;
$canCancelHandover = handover_cancel_block_reason($handoverRecord, $currentUser) === null;
$canConfirmReceipt = handover_receipt_confirm_block_reason($handoverRecord, $currentUser) === null;
$canReportReceipt = handover_can_report_receipt($handoverRecord, $currentUser)
    && !$canConfirmReceipt;
$canClose = Auth::hasPermission('handovers.close')
    && !$isStorageTransfer
    && !$isStaffCustody
    && (string) $handoverRecord['status'] === 'delivered'
    && (
        (int) ($handoverRecord['recipient_user_id'] ?? 0) === (int) ($currentUser['id'] ?? 0)
        || ($isSourceOwner && empty($handoverRecord['recipient_user_id']))
    );
$canApproveClose = handover_close_approval_block_reason($handoverRecord, $currentUser) === null;
$canVoidRecord = workflow_void_block_reason('handover', $handoverRecord, $currentUser) === null;
$canOverrideHandoverStatus = Auth::isOwner();
$handoverStatusOptions = handover_status_options();
$usageReasonOptions = handover_usage_reason_options();
$usesOperationalReconciliation = handover_uses_operational_reconciliation($handoverRecord);
$reconciliations = is_array($reconciliations ?? null) ? $reconciliations : [];
$operationalReasonOptions = handover_operational_reason_options();
$varianceReasonOptions = handover_reconciliation_variance_reason_options();
$handoverRecoveryTargetStatus = Auth::isOwner()
    ? handover_recovery_target_status($handoverRecord, $lines)
    : null;
$handoverRecoveryBlockReason = $handoverRecoveryTargetStatus !== null
    ? handover_recovery_block_reason($handoverRecord, $lines, $currentUser)
    : null;
$canRecoverHandover = $handoverRecoveryTargetStatus !== null && $handoverRecoveryBlockReason === null;
$handoverStatus = (string) $handoverRecord['status'];
$cancelHandoverLabel = $handoverStatus === 'requested' ? 'Cancel Request' : 'Cancel Handover';
$cancelHandoverConfirm = in_array($handoverStatus, ['awaiting_receipt', 'receipt_review', 'delivered'], true)
    ? 'Cancel this handover and return reserved stock to the source storage?'
    : 'Cancel this handover request?';
$plannedTotal = 0.0;
$receivedTotal = 0.0;
$usedTotal = 0.0;
$returnedTotal = 0.0;
$unaccountedTotal = 0.0;

foreach ($lines as $line) {
    $plannedTotal += (float) $line['quantity_handed'];
    $receivedTotal += (float) $line['quantity_received'];
    $usedTotal += (float) $line['quantity_used'];
    $returnedTotal += (float) $line['quantity_returned'];
    $baseQuantity = in_array($handoverStatus, ['requested', 'awaiting_receipt'], true)
        ? (float) $line['quantity_handed']
        : (float) $line['quantity_received'];
    $unaccountedTotal += round($baseQuantity - (float) $line['quantity_used'] - (float) $line['quantity_returned'], 2);
}

$storageReceiptWasReported = $isStorageTransfer && in_array($handoverStatus, ['receipt_review', 'closed'], true);
$storageShortTotal = max(0, round($plannedTotal - $receivedTotal, 2));
$storageReturnedToSourceTotal = $storageReceiptWasReported ? $storageShortTotal : 0.0;
$storageToDestinationTotal = $storageReceiptWasReported ? $receivedTotal : 0.0;
$storageDifferenceTotal = max(0, round($plannedTotal - $storageToDestinationTotal - $storageReturnedToSourceTotal, 2));
?>

<section class="page-head">
    <div class="page-head-copy">
        <p class="eyebrow">Handover Detail</p>
        <h3 class="page-head-title"><?= ui_icon('handover') ?><span><?= e($handoverRecord['handover_number']) ?></span></h3>
    </div>
    <div class="page-actions">
        <a class="ghost-button" href="<?= e(url('/handovers')) ?>"><?= ui_icon('back') ?><span>All Handovers</span></a>
    </div>
</section>

<section class="detail-grid handover-detail-grid">
    <article class="panel detail-summary">
        <div class="detail-hero">
            <div class="detail-hero-main">
                <div class="item-hero-image item-hero-image-fallback">H</div>
                <div>
                    <span class="pill pill-<?= e((string) $handoverRecord['status']) ?>"><?= e($statusLabel) ?></span>
                    <h4><?= e($isStorageTransfer ? (string) ($handoverRecord['destination_storage_name'] ?? 'Destination storage') : (string) $handoverRecord['recipient_name']) ?></h4>
                    <p>
                        <?php if ($isStorageTransfer): ?>
                            <?= e((string) $handoverRecord['source_storage_name']) ?> → <?= e((string) ($handoverRecord['destination_storage_name'] ?? 'Destination storage')) ?>
                        <?php else: ?>
                            <?= e($handoverRecord['source_storage_name']) ?>
                        <?php endif; ?>
                    </p>
                    <p class="tiny-copy">
                        <?= (string) $handoverRecord['status'] === 'requested' ? 'Requested ' : 'Issued ' ?>
                        <?= e(format_datetime_display((string) ((string) $handoverRecord['status'] === 'requested' && !empty($handoverRecord['requested_at']) ? $handoverRecord['requested_at'] : $handoverRecord['issued_at']))) ?>
                    </p>
                </div>
            </div>
        </div>

        <div class="handover-value-strip">
            <div>
                <span>Planned</span>
                <strong><?= format_quantity($plannedTotal) ?></strong>
            </div>
            <?php if ($isStorageTransfer): ?>
                <div>
                    <span>To Destination</span>
                    <strong><?= format_quantity($storageToDestinationTotal) ?></strong>
                </div>
                <div>
                    <span>Returning To Source</span>
                    <strong><?= format_quantity($storageReturnedToSourceTotal) ?></strong>
                </div>
                <div>
                    <span>Difference</span>
                    <strong><?= format_quantity($storageDifferenceTotal) ?></strong>
                </div>
            <?php elseif ($isStaffCustody): ?>
                <div>
                    <span>Received</span>
                    <strong><?= format_quantity((float) ($custodyTotals['received'] ?? $receivedTotal)) ?></strong>
                </div>
                <div>
                    <span>Still Held</span>
                    <strong><?= format_quantity((float) ($custodyTotals['held'] ?? 0)) ?></strong>
                </div>
                <div>
                    <span>Returned / Processed</span>
                    <strong><?= format_quantity(
                        (float) ($custodyTotals['serviceable'] ?? 0)
                        + (float) ($custodyTotals['damaged'] ?? 0)
                        + (float) ($custodyTotals['consumed'] ?? 0)
                        + (float) ($custodyTotals['lost'] ?? 0)
                    ) ?></strong>
                </div>
            <?php else: ?>
                <div>
                    <span>Received</span>
                    <strong><?= format_quantity($receivedTotal) ?></strong>
                </div>
                <div>
                    <span>Used</span>
                    <strong><?= format_quantity($usedTotal) ?></strong>
                </div>
                <div>
                    <span>Returned</span>
                    <strong><?= format_quantity($returnedTotal) ?></strong>
                </div>
            <?php endif; ?>
        </div>

        <div class="handover-compact-grid">
            <section class="handover-info-card">
                <span>Source</span>
                <strong><?= e($handoverRecord['source_storage_name']) ?></strong>
                <small><?= e(storage_type_label((string) $handoverRecord['source_storage_type'])) ?> · Owner: <?= e((string) ($handoverRecord['source_owner_name'] ?: 'Not assigned')) ?></small>
            </section>
            <section class="handover-info-card">
                <span>Mode</span>
                <strong><?= $isStorageTransfer ? 'Storage transfer' : ($isStaffCustody ? 'Long-term staff custody' : ($isRequestMode ? 'Requested by staff' : 'Direct handover')) ?></strong>
                <small><?= $isStorageTransfer
                    ? 'Destination owner confirms receipt'
                    : ($isStaffCustody
                        ? 'Partial condition-based returns'
                        : (!empty($handoverRecord['requested_at']) ? e(format_datetime_display((string) $handoverRecord['requested_at'])) : 'No request timestamp')) ?></small>
            </section>
            <section class="handover-info-card">
                <span><?= $isStorageTransfer ? 'Destination Owner' : 'Recipient' ?></span>
                <strong><?= e($isStorageTransfer ? (string) ($handoverRecord['destination_owner_name'] ?? $handoverRecord['recipient_name']) : (string) $handoverRecord['recipient_name']) ?></strong>
                <small><?= $isStorageTransfer ? e((string) ($handoverRecord['destination_storage_name'] ?? 'Destination not set')) : (!empty($handoverRecord['recipient_user_name']) ? e($handoverRecord['recipient_user_name']) : 'No linked account') ?></small>
            </section>
            <?php if ($isStorageTransfer): ?>
            <section class="handover-info-card">
                <span>Destination</span>
                <strong><?= e((string) ($handoverRecord['destination_storage_name'] ?? 'Not set')) ?></strong>
                <small><?= e(storage_type_label((string) ($handoverRecord['destination_storage_type'] ?? 'storage'))) ?> · Owner: <?= e((string) ($handoverRecord['destination_owner_name'] ?: 'Not assigned')) ?></small>
            </section>
            <?php endif; ?>
            <section class="handover-info-card">
                <span>Schedule</span>
                <strong><?= !empty($handoverRecord['scheduled_for_date']) ? e(date('M j, Y', strtotime((string) $handoverRecord['scheduled_for_date']))) : 'Not set' ?></strong>
                <small><?= $isRequestMode ? 'Requested by' : 'Created by' ?> <?= e((string) ($handoverRecord['creator_name'] ?: 'Unknown')) ?></small>
            </section>
            <?php if ($isStaffCustody): ?>
            <section class="handover-info-card">
                <span>Issue Condition</span>
                <strong><?= e(handover_issue_condition_options()[(string) ($handoverRecord['issue_condition'] ?? 'good')] ?? ucfirst((string) ($handoverRecord['issue_condition'] ?? 'good'))) ?></strong>
                <small>Condition recorded when custody began</small>
            </section>
            <section class="handover-info-card">
                <span>Review / Return Date</span>
                <strong><?= !empty($handoverRecord['custody_review_date']) ? e(date('M j, Y', strtotime((string) $handoverRecord['custody_review_date']))) : 'Not set' ?></strong>
                <small><?= !empty($handoverRecord['custody_review_date']) && (string) $handoverRecord['custody_review_date'] < date('Y-m-d') && (float) ($custodyTotals['held'] ?? 0) > 0.009 ? 'Overdue review' : 'Scheduled custody review' ?></small>
            </section>
            <?php endif; ?>
            <section class="handover-info-card">
                <span>Approval</span>
                <strong><?= e((string) ($handoverRecord['request_approver_name'] ?: 'Not assigned')) ?></strong>
                <small>Approved by: <?= e((string) ($handoverRecord['request_approved_by_name'] ?: 'Not approved yet')) ?></small>
            </section>
            <section class="handover-info-card">
                <span><?= $isStorageTransfer ? 'Receipt' : 'Closeout' ?></span>
                <strong><?= !empty($handoverRecord['receipt_reported_at']) ? e(format_datetime_display((string) $handoverRecord['receipt_reported_at'])) : 'Receipt not reported' ?></strong>
                <small>Submitted: <?= e((string) ($handoverRecord['submitted_by_name'] ?: 'Not submitted')) ?> · Final: <?= e((string) ($handoverRecord['approved_by_name'] ?: 'Not approved')) ?></small>
            </section>
        </div>

        <details class="handover-note-drawer">
            <summary>Notes And Handover History</summary>
            <div class="handover-note-grid">
                <section>
                    <span>Notes</span>
                    <p><?= nl2br(e((string) ($handoverRecord['notes'] ?: 'No notes.'))) ?></p>
                </section>
                <section>
                    <span>Request Decision Notes</span>
                    <p><?= nl2br(e((string) ($handoverRecord['request_decision_notes'] ?: 'No request decision notes yet.'))) ?></p>
                </section>
                <section>
                    <span>Receipt Notes</span>
                    <p><?= nl2br(e((string) ($handoverRecord['receipt_notes'] ?: 'No receipt notes yet.'))) ?></p>
                </section>
                <section>
                    <span>Close Notes</span>
                    <p><?= nl2br(e((string) ($handoverRecord['closed_notes'] ?: 'Not closed yet.'))) ?></p>
                </section>
            </div>
        </details>
    </article>

    <article class="panel handover-action-panel">
        <div class="panel-head">
            <div>
                <p class="eyebrow">Next Step</p>
                <h3>
                    <?php if ($canApproveRequest): ?>
                        Review Handover Request
                    <?php elseif ($canReportReceipt): ?>
                        <?= $isStorageTransfer ? 'Confirm Storage Receipt' : 'Confirm Actual Receipt' ?>
                    <?php elseif ($canConfirmReceipt): ?>
                        <?= $isStorageTransfer ? 'Review Transfer Receipt' : 'Issuer Receipt Review' ?>
                    <?php elseif ($isStaffCustody): ?>
                        <?php if ($pendingCustodyReturn && (string) ($pendingCustodyReturn['status'] ?? '') === 'submitted' && $canReviewCustodyReturn): ?>
                            Review Custody Return
                        <?php elseif ($pendingCustodyReturn && $canReportCustodyReturn): ?>
                            Continue Custody Return
                        <?php elseif ($canReportCustodyReturn && (float) ($custodyTotals['held'] ?? 0) > 0.009): ?>
                            Return Custody Items
                        <?php else: ?>
                            Custody Status
                        <?php endif; ?>
                    <?php elseif ($canApproveClose): ?>
                        Approve Return To Storage
                    <?php elseif ($canCancelHandover): ?>
                        <?= e($cancelHandoverLabel) ?>
                    <?php else: ?>
                        <?= $isStorageTransfer ? 'Transfer Status' : 'Usage And Return' ?>
                    <?php endif; ?>
                </h3>
            </div>
        </div>

        <?php if ($canApproveRequest): ?>
            <div class="copy-context-card">
                <strong>Approve or reject this staff request</strong>
                <p>Approving will reserve the stock and notify the staff member to confirm what actually arrived. Reject it if this temporary-use request should not move forward.</p>
            </div>

            <form class="stack-form" method="post" action="<?= e(url('/handovers/' . $handoverRecord['id'] . '/approve-request')) ?>" data-live-action-form>
                <?= csrf_field() ?>

                <label class="field">
                    <span>Approval Notes</span>
                    <textarea name="request_decision_notes" rows="4" placeholder="Optional note for the requester"><?= e((string) ($handoverRecord['request_decision_notes'] ?? '')) ?></textarea>
                </label>

                <button class="primary-button" type="submit" data-confirm="Approve this handover request and reserve the stock?">Approve Request</button>
            </form>

            <?php if ($canRejectRequest): ?>
                <form class="stack-form" method="post" action="<?= e(url('/handovers/' . $handoverRecord['id'] . '/reject-request')) ?>" data-live-action-form>
                    <?= csrf_field() ?>

                    <label class="field">
                        <span>Rejection Notes</span>
                        <textarea name="request_decision_notes" rows="4" placeholder="Why this request is being rejected"><?= e((string) ($handoverRecord['request_decision_notes'] ?? '')) ?></textarea>
                    </label>

                    <button class="ghost-button danger-button" type="submit" data-confirm="Reject this handover request?">Reject Request</button>
                </form>
            <?php endif; ?>

            <?php if ($canCancelHandover): ?>
                <div class="copy-context-card">
                    <strong>Cancel instead</strong>
                    <p>Use this if the request should stop completely instead of being approved or rejected.</p>
                </div>

                <form class="stack-form" method="post" action="<?= e(url('/handovers/' . $handoverRecord['id'] . '/cancel')) ?>" data-live-action-form>
                    <?= csrf_field() ?>
                    <label class="field">
                        <span>Cancel Note Optional</span>
                        <textarea name="cancel_notes" rows="3" placeholder="Optional reason, typo, wrong handover, or no longer needed"></textarea>
                    </label>
                    <button class="ghost-button danger-button" type="submit" data-confirm="<?= e($cancelHandoverConfirm) ?>"><?= e($cancelHandoverLabel) ?></button>
                </form>
            <?php endif; ?>
        <?php elseif ($canReportReceipt): ?>
            <div class="copy-context-card">
                <strong><?= $isStorageTransfer ? 'Confirm what arrived into destination storage' : 'Report the exact quantity you got' ?></strong>
                <p><?= $isStorageTransfer ? 'Full receipt closes the transfer and moves stock into the destination storage. Short receipt waits for source owner confirmation.' : 'Confirm the exact quantity received. A full receipt starts usage reporting immediately; only a difference waits for issuer review.' ?></p>
            </div>

            <form class="stack-form" method="post" action="<?= e(url('/handovers/' . $handoverRecord['id'] . '/receive')) ?>" enctype="multipart/form-data" data-live-action-form>
                <?= csrf_field() ?>

                <div class="table-wrap">
                    <table class="data-table workflow-close-table">
                        <thead>
                        <tr>
                            <th>Item</th>
                            <th>Planned</th>
                            <th>Received</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($lines as $line): ?>
                            <?php
                            $lineImageUrl = item_image_url($line['image_path'] ?? null);
                            $oldReceivedInput = old('line_received', []);
                            $receivedValue = is_array($oldReceivedInput) && array_key_exists((int) $line['id'], $oldReceivedInput)
                                ? (string) $oldReceivedInput[(int) $line['id']]
                                : ((string) $handoverRecord['status'] === 'receipt_review'
                                    ? format_quantity((float) $line['quantity_received'])
                                    : format_quantity((float) $line['quantity_handed']));
                            ?>
                            <tr>
                                <td>
                                    <div class="item-table-cell">
                                        <?php if ($lineImageUrl): ?>
                                            <img class="item-thumb expandable-image" src="<?= e($lineImageUrl) ?>" alt="<?= e($line['item_name']) ?>" data-expand-image tabindex="0">
                                        <?php else: ?>
                                            <span class="item-thumb item-thumb-fallback"><?= e(item_initial((string) $line['item_name'])) ?></span>
                                        <?php endif; ?>
                                        <div>
                                            <strong><?= e($line['item_name']) ?></strong>
                                            <span class="tiny-copy"><?= e($line['item_sku']) ?> · <?= e($line['unit']) ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td><?= format_quantity($line['quantity_handed']) ?> <?= e($line['unit']) ?></td>
                                <td>
                                    <input
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        max="<?= e(format_quantity($line['quantity_handed'])) ?>"
                                        name="line_received[<?= e((string) $line['id']) ?>]"
                                        value="<?= e($receivedValue) ?>"
                                        required
                                    >
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <label class="field">
                    <span>Receipt Notes</span>
                    <textarea name="receipt_notes" rows="4" placeholder="Mention shortages, damaged items, or anything off."><?= e((string) old('receipt_notes', (string) ($handoverRecord['receipt_notes'] ?? ''))) ?></textarea>
                </label>

                <label class="field">
                    <span>Proof Image Optional</span>
                    <input type="file" name="proof_image" accept="image/jpeg,image/png,image/webp">
                    <small>Upload a delivery photo, signed paper, or item proof if needed.</small>
                </label>

                <button class="primary-button" type="submit"><?= (string) $handoverRecord['status'] === 'receipt_review' ? 'Update Receipt Report' : 'Submit Receipt Report' ?></button>
            </form>

            <?php if ($canCancelHandover): ?>
                <div class="copy-context-card">
                    <strong>Wrong items or wrong recipient?</strong>
                    <p>Cancel the handover instead of confirming receipt. Reserved stock will go back to the source storage.</p>
                </div>

                <form class="stack-form" method="post" action="<?= e(url('/handovers/' . $handoverRecord['id'] . '/cancel')) ?>" data-live-action-form>
                    <?= csrf_field() ?>
                    <label class="field">
                        <span>Cancel Note Optional</span>
                        <textarea name="cancel_notes" rows="3" placeholder="Optional reason, wrong items, wrong receiver, or cancelled event"></textarea>
                    </label>
                    <button class="ghost-button danger-button" type="submit" data-confirm="<?= e($cancelHandoverConfirm) ?>"><?= e($cancelHandoverLabel) ?></button>
                </form>
            <?php endif; ?>
        <?php elseif ($canConfirmReceipt): ?>
            <div class="copy-context-card">
                <strong><?= $isStorageTransfer ? 'Review and confirm the transfer receipt' : 'Review and confirm what the receiver actually got' ?></strong>
                <p><?= $isStorageTransfer ? 'Check every received quantity before closing the transfer. Confirmed stock moves to the destination and the difference returns to source.' : 'The receiver reported these quantities. Correct any number that is wrong, then confirm. The receiver will report returned stock and usage next.' ?></p>
            </div>

            <form class="stack-form" method="post" action="<?= e(url('/handovers/' . $handoverRecord['id'] . '/confirm-receipt')) ?>" data-live-action-form data-handover-receipt-review>
                <?= csrf_field() ?>

                <div class="table-wrap">
                    <table class="data-table workflow-close-table">
                        <thead>
                        <tr>
                            <th>Item</th>
                            <th>Planned</th>
                            <th>Receiver Reported</th>
                            <th>Issuer Confirmed</th>
                            <th><?= $isStorageTransfer ? 'Short / Returning To Source' : 'Unreceived / Returning To Source' ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($lines as $line): ?>
                            <?php
                            $lineImageUrl = item_image_url($line['image_path'] ?? null);
                            $shortage = round((float) $line['quantity_handed'] - (float) $line['quantity_received'], 2);
                            $oldConfirmedInput = old('line_received', []);
                            $confirmedValue = is_array($oldConfirmedInput) && array_key_exists((int) $line['id'], $oldConfirmedInput)
                                ? (string) $oldConfirmedInput[(int) $line['id']]
                                : format_quantity((float) $line['quantity_received']);
                            ?>
                            <tr>
                                <td>
                                    <div class="item-table-cell">
                                        <?php if ($lineImageUrl): ?>
                                            <img class="item-thumb expandable-image" src="<?= e($lineImageUrl) ?>" alt="<?= e($line['item_name']) ?>" data-expand-image tabindex="0">
                                        <?php else: ?>
                                            <span class="item-thumb item-thumb-fallback"><?= e(item_initial((string) $line['item_name'])) ?></span>
                                        <?php endif; ?>
                                        <div>
                                            <strong><?= e($line['item_name']) ?></strong>
                                            <span class="tiny-copy"><?= e($line['item_sku']) ?> · <?= e($line['unit']) ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td><?= format_quantity($line['quantity_handed']) ?> <?= e($line['unit']) ?></td>
                                <td><?= format_quantity($line['quantity_received']) ?> <?= e($line['unit']) ?></td>
                                <td>
                                    <input
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        max="<?= e(format_quantity($line['quantity_handed'])) ?>"
                                        name="line_received[<?= e((string) $line['id']) ?>]"
                                        value="<?= e($confirmedValue) ?>"
                                        aria-label="Issuer confirmed received quantity for <?= e($line['item_name']) ?>"
                                        data-handover-receipt-confirmed
                                        data-handover-planned="<?= e(format_quantity($line['quantity_handed'])) ?>"
                                        required
                                    >
                                </td>
                                <td><strong data-handover-receipt-difference><?= format_quantity($shortage) ?></strong> <?= e($line['unit']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <button class="primary-button" type="submit" data-confirm="<?= $isStorageTransfer ? 'Confirm these transfer quantities and close the transfer?' : 'Confirm these received quantities and let the receiver report usage?' ?>"><?= $isStorageTransfer ? 'Confirm Transfer Receipt' : 'Confirm Receipt And Continue' ?></button>
            </form>

            <?php if ($canCancelHandover): ?>
                <div class="copy-context-card">
                    <strong>Cancel instead</strong>
                    <p>Use this if the reported receipt is wrong enough that the handover should stop and reserved stock should return to source.</p>
                </div>

                <form class="stack-form" method="post" action="<?= e(url('/handovers/' . $handoverRecord['id'] . '/cancel')) ?>" data-live-action-form>
                    <?= csrf_field() ?>
                    <label class="field">
                        <span>Cancel Note Optional</span>
                        <textarea name="cancel_notes" rows="3" placeholder="Optional reason, typo, wrong handover, or no longer needed"></textarea>
                    </label>
                    <button class="ghost-button danger-button" type="submit" data-confirm="<?= e($cancelHandoverConfirm) ?>"><?= e($cancelHandoverLabel) ?></button>
                </form>
            <?php endif; ?>
        <?php elseif ($isStaffCustody): ?>
            <div class="copy-context-card">
                <strong>Long-term staff custody</strong>
                <p>Items remain assigned to the staff member until an issuer-approved return records them as serviceable, damaged, consumed, or lost.</p>
            </div>

            <?php if ($pendingCustodyReturn): ?>
                <?php
                $pendingReturnStatus = (string) ($pendingCustodyReturn['status'] ?? 'draft');
                $pendingReturnUrl = url('/handovers/' . (int) $handoverRecord['id'] . '/custody-returns/' . (int) $pendingCustodyReturn['id']);
                ?>
                <section class="handover-info-card">
                    <span>Current Return</span>
                    <strong><?= e((string) $pendingCustodyReturn['return_number']) ?></strong>
                    <small><?= e(handover_custody_return_status_label($pendingReturnStatus)) ?></small>
                </section>

                <?php if ($pendingReturnStatus === 'submitted' && $canReviewCustodyReturn): ?>
                    <a class="primary-button" href="<?= e($pendingReturnUrl) ?>"><?= ui_icon('approve') ?><span>Review Quantities And Evidence</span></a>
                <?php elseif (in_array($pendingReturnStatus, ['draft', 'rejected'], true) && $canReportCustodyReturn): ?>
                    <a class="primary-button" href="<?= e($pendingReturnUrl) ?>"><?= ui_icon('edit') ?><span>Continue Return Report</span></a>
                <?php else: ?>
                    <a class="ghost-button" href="<?= e($pendingReturnUrl) ?>"><?= ui_icon('open') ?><span>Open Return Record</span></a>
                    <p class="muted-copy">This return is waiting for the source issuer to review it.</p>
                <?php endif; ?>
            <?php elseif ((float) ($custodyTotals['held'] ?? 0) > 0.009 && $canReportCustodyReturn): ?>
                <form class="stack-form" method="post" action="<?= e(url('/handovers/' . (int) $handoverRecord['id'] . '/custody-returns')) ?>">
                    <?= csrf_field() ?>
                    <p class="muted-copy">Create a partial return. You can return some items now and keep the rest assigned.</p>
                    <button class="primary-button" type="submit"><?= ui_icon('handover') ?><span>Start Partial Return</span></button>
                </form>
            <?php elseif ((float) ($custodyTotals['held'] ?? 0) <= 0.009): ?>
                <p class="empty-state">Nothing remains with the staff member. This custody handover is complete.</p>
            <?php else: ?>
                <p class="empty-state">The assigned staff member can report a partial return. The source issuer will review it before stock changes.</p>
            <?php endif; ?>

            <div class="button-row">
                <a class="ghost-button" href="<?= e(url('/handovers/custody')) ?>"><?= ui_icon('reports') ?><span>Staff Custody Report</span></a>
                <?php if (Auth::isOwner()): ?>
                    <a class="ghost-button" href="<?= e(url('/handovers/custody/quarantine')) ?>"><?= ui_icon('archive') ?><span>Damaged / Quarantine</span></a>
                <?php endif; ?>
            </div>
        <?php elseif ($canClose): ?>
            <form class="stack-form" method="post" action="<?= e(url('/handovers/' . $handoverRecord['id'] . '/close')) ?>" enctype="multipart/form-data" data-live-action-form data-handover-close-form <?= $usesOperationalReconciliation ? 'data-handover-operational-form' : '' ?>>
                <?= csrf_field() ?>

                <div class="copy-context-card handover-usage-help">
                    <strong><?= $usesOperationalReconciliation ? 'Returned Stock And Operational Totals' : 'Actual Usage Report' ?></strong>
                    <p><?= $usesOperationalReconciliation
                        ? 'Enter returned quantities first. Used stock calculates automatically, then complete one operational summary for each unit.'
                        : 'Type how many pieces came back. The system calculates used quantity automatically, then the storage owner reviews and approves the final stock posting.' ?></p>
                </div>

                <?php if ($usesOperationalReconciliation): ?>
                    <?php
                    $operationalApprovalMode = false;
                    $operationalLines = $lines;
                    $operationalReconciliations = $reconciliations;
                    require __DIR__ . '/_operational_reconciliation.php';
                    ?>
                <?php else: ?>
                <div class="handover-close-cards">
                    <?php foreach ($lines as $lineIndex => $line): ?>
                        <?php
                        $lineBreakdowns = (array) ($line['usage_breakdowns'] ?? []);
                        $expectedUsageSummary = trim((string) ($line['expected_usage_reason_summary'] ?? ''));
                        $lineImageUrl = item_image_url($line['image_path'] ?? null);
                        $lineHasExistingUsage = round((float) ($line['quantity_used'] ?? 0), 2) > 0;
                        $lineReturningQuantity = round((float) $line['quantity_received'] - (float) $line['quantity_used'], 2);

                        if ($lineBreakdowns === []) {
                            $lineBreakdowns[] = [
                                'reason_code' => 'unspecified',
                                'reason_custom' => '',
                                'quantity' => '',
                                'notes' => '',
                            ];
                        }

                        foreach ($lineBreakdowns as $breakdown) {
                            if (round((float) ($breakdown['quantity'] ?? 0), 2) > 0) {
                                $lineHasExistingUsage = true;
                                break;
                            }
                        }
                        ?>
                        <details class="handover-close-card" data-handover-close-line open>
                            <summary class="handover-close-card-summary">
                                <div class="handover-close-summary-title">
                                    <?php if ($lineImageUrl): ?>
                                        <img class="item-thumb handover-close-summary-thumb expandable-image" src="<?= e($lineImageUrl) ?>" alt="<?= e($line['item_name']) ?>" data-expand-image tabindex="0">
                                    <?php else: ?>
                                        <span class="item-thumb item-thumb-fallback handover-close-summary-thumb"><?= e(item_initial((string) $line['item_name'])) ?></span>
                                    <?php endif; ?>
                                    <div>
                                        <strong><?= e($line['item_name']) ?></strong>
                                        <small><?= e($line['item_sku']) ?> · <?= e($line['unit']) ?></small>
                                    </div>
                                </div>

                                <div class="handover-close-summary-stats">
                                    <span class="handover-close-chip"><?= ui_icon('items') ?><strong><?= format_quantity($line['quantity_received']) ?></strong> received</span>
                                    <span class="handover-close-chip"><?= ui_icon('movements') ?><strong data-handover-card-used><?= format_quantity($line['quantity_used']) ?></strong> used</span>
                                    <span class="handover-close-chip"><?= ui_icon('handover') ?><strong data-handover-card-returned><?= format_quantity($lineReturningQuantity) ?></strong> returning</span>
                                </div>

                                <span class="handover-close-toggle" aria-hidden="true">
                                    <span class="toggle-open">Collapse</span>
                                    <span class="toggle-closed">Open</span>
                                </span>
                            </summary>

                            <div class="handover-close-card-body">
                                <?php if ($expectedUsageSummary !== ''): ?>
                                    <div class="handover-usage-summary-chip">Expected: <?= e($expectedUsageSummary) ?></div>
                                <?php endif; ?>
                                <div class="handover-close-card-head">
                                    <div class="handover-close-metric">
                                        <span><?= ui_icon('items') ?>Received</span>
                                        <strong><?= format_quantity($line['quantity_received']) ?> <?= e($line['unit']) ?></strong>
                                    </div>
                                    <label class="field handover-return-field">
                                        <span><?= ui_icon('handover') ?>Returned Qty</span>
                                        <input
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            max="<?= e(format_quantity($line['quantity_received'])) ?>"
                                            name="line_returned[<?= e((string) $line['id']) ?>]"
                                            value="<?= e(format_quantity($lineReturningQuantity)) ?>"
                                            data-handover-returned
                                        >
                                        <small>Used is calculated as received minus returned.</small>
                                    </label>
                                </div>

                                <div class="handover-usage-editor" data-handover-usage-editor>
                                    <input type="hidden" name="line_used[<?= e((string) $line['id']) ?>]" value="<?= e(format_quantity($line['quantity_used'])) ?>" data-handover-used data-handover-handed="<?= e(format_quantity($line['quantity_received'])) ?>">
                                    <div class="handover-usage-title">
                                        <strong><?= ui_icon('reports') ?>Actual Usage</strong>
                                        <small>Optional: split the calculated used quantity by reason. If you leave it blank, usage is saved as Unspecified.</small>
                                    </div>
                                    <div class="handover-reason-quick-buttons" data-handover-reason-quick-buttons>
                                        <?php foreach (['online', 'walkin', 'event', 'damage', 'sport', 'school', 'complimentary', 'noshow', 'other'] as $quickReason): ?>
                                            <button class="ghost-button compact-button" type="button" data-handover-usage-quick-reason="<?= e($quickReason) ?>"><?= e($usageReasonOptions[$quickReason] ?? ucfirst($quickReason)) ?></button>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="handover-usage-list" data-handover-usage-list>
                                        <?php foreach ($lineBreakdowns as $breakdown): ?>
                                            <?php $selectedReason = normalize_handover_usage_reason((string) ($breakdown['reason_code'] ?? 'unspecified')); ?>
                                            <div class="handover-usage-row" data-handover-usage-row>
                                                <label class="handover-usage-field handover-usage-field-reason">
                                                    <span><?= ui_icon('filter') ?>Reason</span>
                                                    <select name="line_usage_reason[<?= e((string) $line['id']) ?>][]" aria-label="Usage reason" data-handover-usage-reason>
                                                        <?php foreach ($usageReasonOptions as $reasonCode => $reasonLabel): ?>
                                                            <option value="<?= e($reasonCode) ?>" <?= $selectedReason === $reasonCode ? 'selected' : '' ?>><?= e($reasonLabel) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </label>
                                                <label class="handover-usage-field handover-usage-field-quantity">
                                                    <span><?= ui_icon('movements') ?>Reason Qty</span>
                                                    <input type="number" step="0.01" min="0" max="<?= e(format_quantity($line['quantity_received'])) ?>" name="line_usage_quantity[<?= e((string) $line['id']) ?>][]" value="<?= e((string) ($breakdown['quantity'] ?? '')) ?>" placeholder="Optional split" aria-label="Usage reason quantity" data-handover-usage-quantity>
                                                </label>
                                                <label class="handover-usage-field handover-usage-other-field" data-handover-usage-other-field <?= $selectedReason === 'other' ? '' : 'hidden' ?>>
                                                    <span><?= ui_icon('edit') ?>Other Reason</span>
                                                    <input type="text" name="line_usage_other[<?= e((string) $line['id']) ?>][]" value="<?= e((string) ($breakdown['reason_custom'] ?? '')) ?>" placeholder="Type reason" aria-label="Other usage reason" data-handover-usage-other <?= $selectedReason === 'other' ? '' : 'hidden' ?>>
                                                </label>
                                                <label class="handover-usage-field handover-usage-field-note">
                                                    <span><?= ui_icon('document') ?>Note</span>
                                                    <input type="text" name="line_usage_notes[<?= e((string) $line['id']) ?>][]" value="<?= e((string) ($breakdown['notes'] ?? '')) ?>" placeholder="Optional note" aria-label="Usage note">
                                                </label>
                                                <button class="ghost-button compact-button handover-usage-remove" type="button" data-remove-handover-usage><span>Remove</span></button>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="handover-usage-summary">
                                        <span class="handover-used-total-pill">Used <strong data-handover-used-total><?= e(format_quantity($line['quantity_used'])) ?></strong> <?= e($line['unit']) ?></span>
                                        <span class="danger-copy" data-handover-usage-warning hidden>Returned must be valid and reason totals must match calculated used quantity.</span>
                                        <button class="ghost-button compact-button handover-add-usage" type="button" data-add-handover-usage><?= ui_icon('plus') ?><span>Add Usage Split</span></button>
                                    </div>
                                    <template data-handover-usage-template>
                                        <div class="handover-usage-row" data-handover-usage-row>
                                            <label class="handover-usage-field handover-usage-field-reason">
                                                <span><?= ui_icon('filter') ?>Reason</span>
                                                <select name="line_usage_reason[<?= e((string) $line['id']) ?>][]" aria-label="Usage reason" data-handover-usage-reason>
                                                    <?php foreach ($usageReasonOptions as $reasonCode => $reasonLabel): ?>
                                                        <option value="<?= e($reasonCode) ?>"><?= e($reasonLabel) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </label>
                                            <label class="handover-usage-field handover-usage-field-quantity">
                                                <span><?= ui_icon('movements') ?>Reason Qty</span>
                                                <input type="number" step="0.01" min="0" max="<?= e(format_quantity($line['quantity_received'])) ?>" name="line_usage_quantity[<?= e((string) $line['id']) ?>][]" placeholder="Optional split" aria-label="Usage reason quantity" data-handover-usage-quantity>
                                            </label>
                                            <label class="handover-usage-field handover-usage-other-field" data-handover-usage-other-field hidden>
                                                <span><?= ui_icon('edit') ?>Other Reason</span>
                                                <input type="text" name="line_usage_other[<?= e((string) $line['id']) ?>][]" placeholder="Type reason" aria-label="Other usage reason" data-handover-usage-other hidden>
                                            </label>
                                            <label class="handover-usage-field handover-usage-field-note">
                                                <span><?= ui_icon('document') ?>Note</span>
                                                <input type="text" name="line_usage_notes[<?= e((string) $line['id']) ?>][]" placeholder="Optional note" aria-label="Usage note">
                                            </label>
                                            <button class="ghost-button compact-button handover-usage-remove" type="button" data-remove-handover-usage><span>Remove</span></button>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </details>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <label class="field">
                    <span>Close Notes</span>
                    <textarea name="closed_notes" rows="4" placeholder="Anything worth keeping in the record"><?= e((string) ($handoverRecord['closed_notes'] ?? '')) ?></textarea>
                </label>

                <label class="field">
                    <span>Proof Image Optional</span>
                    <input type="file" name="proof_image" accept="image/jpeg,image/png,image/webp">
                    <small>Attach a returned-items photo, signed sheet, or usage proof.</small>
                </label>

                <button class="primary-button" type="submit" data-confirm="<?= empty($handoverRecord['recipient_user_id']) ? 'Close this handover now?' : 'Submit this handover? The storage owner will review the returned quantity and approve the calculated usage.' ?>"><?= empty($handoverRecord['recipient_user_id']) ? 'Close Handover' : 'Submit For Approval' ?></button>
            </form>

            <?php if ($canCancelHandover): ?>
                <div class="copy-context-card">
                    <strong>Wrong items or wrong receiver?</strong>
                    <p>If no usage was recorded yet, cancel this handover and return the active quantity to source storage.</p>
                </div>

                <form class="stack-form" method="post" action="<?= e(url('/handovers/' . $handoverRecord['id'] . '/cancel')) ?>" data-live-action-form>
                    <?= csrf_field() ?>
                    <label class="field">
                        <span>Cancel Note Optional</span>
                        <textarea name="cancel_notes" rows="3" placeholder="Optional reason, typo, wrong handover, or no longer needed"></textarea>
                    </label>
                    <button class="ghost-button danger-button" type="submit" data-confirm="<?= e($cancelHandoverConfirm) ?>"><?= e($cancelHandoverLabel) ?></button>
                </form>
            <?php endif; ?>
        <?php elseif ($canApproveClose): ?>
            <form class="stack-form" method="post" action="<?= e(url('/handovers/' . $handoverRecord['id'] . '/approve')) ?>" data-live-action-form data-handover-approval-form <?= $usesOperationalReconciliation ? 'data-handover-operational-form' : '' ?>>
                <?= csrf_field() ?>

                <div class="copy-context-card">
                    <strong>Owner Final Review</strong>
                    <p><?= $usesOperationalReconciliation
                        ? 'Correct returned quantities or operational totals before closing. Stock posts only after this approval.'
                        : 'Correct returned quantity and actual usage reasons before closing. Stock posts only after this approval.' ?></p>
                </div>

                <?php if ($usesOperationalReconciliation): ?>
                    <?php
                    $operationalApprovalMode = true;
                    $operationalLines = $lines;
                    $operationalReconciliations = $reconciliations;
                    require __DIR__ . '/_operational_reconciliation.php';
                    ?>
                <?php else: ?>
                <div class="handover-approval-cards">
                    <?php foreach ($lines as $line): ?>
                        <?php
                            $receivedQuantity = round((float) ($line['quantity_received'] ?? 0), 2);
                            $usedQuantity = round((float) ($line['quantity_used'] ?? 0), 2);
                            $returnedQuantity = round((float) ($line['quantity_returned'] ?? max(0, $receivedQuantity - $usedQuantity)), 2);
                            $expectedUsageSummary = trim((string) ($line['expected_usage_reason_summary'] ?? ''));
                            $usageSummary = trim((string) ($line['usage_reason_summary'] ?? ''));
                            $varianceSummary = trim((string) ($line['usage_variance_summary'] ?? ''));
                            $lineImageUrl = item_image_url($line['image_path'] ?? null);
                            $approvalUsageRows = array_values(array_filter((array) ($line['usage_breakdowns'] ?? []), static fn (array $breakdown): bool => round((float) ($breakdown['quantity'] ?? 0), 2) > 0));
                            if ($approvalUsageRows === []) {
                                $approvalUsageRows[] = [
                                    'reason_code' => 'unspecified',
                                    'reason_custom' => '',
                                    'quantity' => '',
                                    'notes' => '',
                                ];
                            }
                        ?>
                        <section class="handover-approval-card" data-handover-approval-line>
                            <div class="handover-approval-card-head">
                                <div class="handover-approval-item-title">
                                    <?php if ($lineImageUrl): ?>
                                        <img class="item-thumb handover-close-summary-thumb expandable-image" src="<?= e($lineImageUrl) ?>" alt="<?= e($line['item_name']) ?>" data-expand-image tabindex="0">
                                    <?php else: ?>
                                        <span class="item-thumb item-thumb-fallback handover-close-summary-thumb"><?= e(item_initial((string) $line['item_name'])) ?></span>
                                    <?php endif; ?>
                                    <div>
                                        <strong><?= e($line['item_name']) ?></strong>
                                        <small><?= e($line['item_sku']) ?> · <?= e($line['unit']) ?></small>
                                    </div>
                                </div>
                                <div class="handover-approval-chip-stack">
                                    <span class="handover-usage-summary-chip <?= $expectedUsageSummary === '' ? 'is-muted' : '' ?>">Expected: <?= $expectedUsageSummary !== '' ? e($expectedUsageSummary) : 'No plan' ?></span>
                                    <span class="handover-usage-summary-chip <?= $usageSummary === '' ? 'is-muted' : '' ?>">Staff Actual: <?= $usageSummary !== '' ? e($usageSummary) : 'No reason submitted' ?></span>
                                    <span class="handover-usage-summary-chip <?= $varianceSummary === '' ? 'is-muted' : '' ?>">Variance: <?= $varianceSummary !== '' ? e($varianceSummary) : 'Not available yet' ?></span>
                                </div>
                            </div>

                            <div class="handover-approval-metrics">
                                <span><strong><?= format_quantity($receivedQuantity) ?></strong> received</span>
                                <span><strong><?= format_quantity($usedQuantity) ?></strong> staff used</span>
                                <span><strong><?= format_quantity($returnedQuantity) ?></strong> staff returning</span>
                            </div>

                            <div class="handover-approval-confirm-grid">
                                <label class="field">
                                    <span>Confirmed Returned</span>
                                    <input
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        max="<?= e(format_quantity($receivedQuantity)) ?>"
                                        name="line_returned[<?= e((string) $line['id']) ?>]"
                                        value="<?= e(format_quantity($returnedQuantity)) ?>"
                                        data-handover-approval-returned
                                        data-handover-received="<?= e(format_quantity($receivedQuantity)) ?>"
                                    >
                                    <small>If they returned 20 instead of 28, write 20 here.</small>
                                </label>

                                <div class="handover-approval-final">
                                    <span>Final Used</span>
                                    <strong data-handover-approval-used><?= format_quantity(max(0, $receivedQuantity - $returnedQuantity)) ?></strong>
                                    <small><?= e($line['unit']) ?></small>
                                </div>
                            </div>

                            <p class="danger-copy" data-handover-approval-warning hidden>Returned quantity cannot be more than received.</p>
                            <div
                                class="handover-usage-editor handover-approval-usage-editor"
                                data-handover-usage-editor
                                data-handover-approval-usage-editor
                                data-handover-approval-target-used="<?= e(format_quantity(max(0, $receivedQuantity - $returnedQuantity))) ?>"
                            >
                                <div class="handover-usage-title">
                                    <strong><?= ui_icon('movements') ?>Owner Final Usage</strong>
                                    <small>Reason totals must equal Final Used. This is what goes into movement logs and final sign-off files.</small>
                                </div>
                                <div class="handover-reason-quick-buttons" data-handover-reason-quick-buttons>
                                    <?php foreach (['online', 'walkin', 'event', 'damage', 'sport', 'school', 'complimentary', 'noshow', 'other'] as $quickReason): ?>
                                        <button class="ghost-button compact-button" type="button" data-handover-usage-quick-reason="<?= e($quickReason) ?>"><?= e($usageReasonOptions[$quickReason] ?? ucfirst($quickReason)) ?></button>
                                    <?php endforeach; ?>
                                </div>
                                <div class="handover-usage-list" data-handover-usage-list>
                                    <?php foreach ($approvalUsageRows as $breakdown): ?>
                                        <?php
                                            $reasonCode = normalize_handover_usage_reason((string) ($breakdown['reason_code'] ?? 'unspecified'));
                                            $isOtherReason = $reasonCode === 'other';
                                        ?>
                                        <div class="handover-usage-row" data-handover-usage-row>
                                            <label class="handover-usage-field handover-usage-field-reason">
                                                <span><?= ui_icon('filter') ?>Reason</span>
                                                <select name="line_usage_reason[<?= e((string) $line['id']) ?>][]" aria-label="Usage reason" data-handover-usage-reason>
                                                    <?php foreach ($usageReasonOptions as $reasonOptionCode => $reasonLabel): ?>
                                                        <option value="<?= e($reasonOptionCode) ?>" <?= $reasonCode === $reasonOptionCode ? 'selected' : '' ?>><?= e($reasonLabel) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </label>
                                            <label class="handover-usage-field handover-usage-field-quantity">
                                                <span><?= ui_icon('movements') ?>Used Qty</span>
                                                <input type="number" step="0.01" min="0" max="<?= e(format_quantity($receivedQuantity)) ?>" name="line_usage_quantity[<?= e((string) $line['id']) ?>][]" value="<?= e((string) ($breakdown['quantity'] ?? '')) ?>" placeholder="0" aria-label="Used quantity" data-handover-usage-quantity>
                                            </label>
                                            <label class="handover-usage-field handover-usage-other-field" data-handover-usage-other-field <?= $isOtherReason ? '' : 'hidden' ?>>
                                                <span><?= ui_icon('edit') ?>Other Reason</span>
                                                <input type="text" name="line_usage_other[<?= e((string) $line['id']) ?>][]" value="<?= e((string) ($breakdown['reason_custom'] ?? '')) ?>" placeholder="Type reason" aria-label="Other usage reason" data-handover-usage-other <?= $isOtherReason ? '' : 'hidden' ?>>
                                            </label>
                                            <label class="handover-usage-field handover-usage-field-note">
                                                <span><?= ui_icon('document') ?>Note</span>
                                                <input type="text" name="line_usage_notes[<?= e((string) $line['id']) ?>][]" value="<?= e((string) ($breakdown['notes'] ?? '')) ?>" placeholder="Optional note" aria-label="Usage note">
                                            </label>
                                            <button class="ghost-button compact-button handover-usage-remove" type="button" data-remove-handover-usage><span>Remove</span></button>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="handover-usage-summary">
                                    <span class="handover-used-total-pill">Reason total <strong data-handover-approval-reason-total><?= e(format_quantity($usedQuantity)) ?></strong> <?= e($line['unit']) ?></span>
                                    <span class="danger-copy" data-handover-approval-usage-warning hidden>Reason totals must match Final Used.</span>
                                    <button class="ghost-button compact-button handover-add-usage" type="button" data-add-handover-usage><?= ui_icon('plus') ?><span>Add Actual Usage</span></button>
                                </div>
                                <template data-handover-usage-template>
                                    <div class="handover-usage-row" data-handover-usage-row>
                                        <label class="handover-usage-field handover-usage-field-reason">
                                            <span><?= ui_icon('filter') ?>Reason</span>
                                            <select name="line_usage_reason[<?= e((string) $line['id']) ?>][]" aria-label="Usage reason" data-handover-usage-reason>
                                                <?php foreach ($usageReasonOptions as $reasonOptionCode => $reasonLabel): ?>
                                                    <option value="<?= e($reasonOptionCode) ?>"><?= e($reasonLabel) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label class="handover-usage-field handover-usage-field-quantity">
                                            <span><?= ui_icon('movements') ?>Used Qty</span>
                                            <input type="number" step="0.01" min="0" max="<?= e(format_quantity($receivedQuantity)) ?>" name="line_usage_quantity[<?= e((string) $line['id']) ?>][]" placeholder="0" aria-label="Used quantity" data-handover-usage-quantity>
                                        </label>
                                        <label class="handover-usage-field handover-usage-other-field" data-handover-usage-other-field hidden>
                                            <span><?= ui_icon('edit') ?>Other Reason</span>
                                            <input type="text" name="line_usage_other[<?= e((string) $line['id']) ?>][]" placeholder="Type reason" aria-label="Other usage reason" data-handover-usage-other hidden>
                                        </label>
                                        <label class="handover-usage-field handover-usage-field-note">
                                            <span><?= ui_icon('document') ?>Note</span>
                                            <input type="text" name="line_usage_notes[<?= e((string) $line['id']) ?>][]" placeholder="Optional note" aria-label="Usage note">
                                        </label>
                                        <button class="ghost-button compact-button handover-usage-remove" type="button" data-remove-handover-usage><span>Remove</span></button>
                                    </div>
                                </template>
                            </div>
                        </section>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <label class="field">
                    <span>Approval Notes</span>
                    <textarea name="closed_notes" rows="4" placeholder="Optional approval note"><?= e((string) ($handoverRecord['closed_notes'] ?? '')) ?></textarea>
                </label>

                <button class="primary-button" type="submit" data-confirm="Approve this handover closeout? Remaining stock will go back into the source storage.">Approve And Close</button>
            </form>
        <?php elseif ($canCancelHandover): ?>
            <div class="copy-context-card">
                <strong><?= e($cancelHandoverLabel) ?></strong>
                <p>Use this when the request is no longer needed, the wrong items were sent, or the wrong person received it.</p>
            </div>

            <form class="stack-form" method="post" action="<?= e(url('/handovers/' . $handoverRecord['id'] . '/cancel')) ?>" data-live-action-form>
                <?= csrf_field() ?>
                <label class="field">
                    <span>Cancel Note Optional</span>
                    <textarea name="cancel_notes" rows="3" placeholder="Optional reason, typo, wrong handover, or no longer needed"></textarea>
                </label>
                <button class="ghost-button danger-button" type="submit" data-confirm="<?= e($cancelHandoverConfirm) ?>"><?= e($cancelHandoverLabel) ?></button>
            </form>
        <?php else: ?>
            <p class="empty-state">
                <?php if ((string) $handoverRecord['status'] === 'requested'): ?>
                    This handover request is waiting for the storage owner to approve or reject it.
                <?php elseif ((string) $handoverRecord['status'] === 'awaiting_receipt'): ?>
                    <?= $isStorageTransfer ? 'This storage transfer is waiting for the destination storage owner to confirm what arrived.' : 'This handover is waiting for the recipient to confirm what actually arrived.' ?>
                <?php elseif ((string) $handoverRecord['status'] === 'receipt_review'): ?>
                    <?= $isStorageTransfer ? 'This storage transfer is waiting for the source storage owner to approve the reported shortage.' : 'This handover has a reported receipt difference and is waiting for the issuer to review it.' ?>
                <?php elseif ((string) $handoverRecord['status'] === 'pending_approval'): ?>
                    This handover is waiting for the storage owner to approve the remaining quantity.
                <?php elseif ((string) $handoverRecord['status'] === 'rejected'): ?>
                    This handover request was rejected.
                <?php elseif ((string) $handoverRecord['status'] === 'cancelled'): ?>
                    This handover request was cancelled.
                <?php else: ?>
                    <?= $isStorageTransfer ? 'This storage transfer is already closed.' : 'This handover is already closed.' ?>
                <?php endif; ?>
            </p>
        <?php endif; ?>

        <?php if ($canOverrideHandoverStatus): ?>
            <div class="copy-context-card">
                <strong>Admin Status Override</strong>
                <p>Change the workflow status directly. Stock-impact changes are still checked, so unsafe jumps will be blocked instead of corrupting inventory.</p>
            </div>

            <form class="stack-form" method="post" action="<?= e(url('/handovers/' . $handoverRecord['id'] . '/status-override')) ?>" data-live-action-form>
                <?= csrf_field() ?>
                <label class="field">
                    <span>New Status</span>
                    <select name="target_status" required>
                        <?php foreach ($handoverStatusOptions as $statusValue => $statusText): ?>
                            <option value="<?= e($statusValue) ?>" <?= $statusValue === $handoverStatus ? 'selected' : '' ?>>
                                <?= e($statusText) ?><?= $statusValue === $handoverStatus ? ' (current)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="field">
                    <span>Override Note Optional</span>
                    <textarea name="status_notes" rows="3" placeholder="Optional note for why this status was changed"></textarea>
                </label>
                <button class="primary-button" type="submit" data-confirm="Change this handover status? Stock checks will run before saving.">Change Status</button>
            </form>
        <?php endif; ?>

        <?php if ($canRecoverHandover): ?>
            <div class="copy-context-card">
                <strong>Status Control</strong>
                <p>Recover this handover and reopen it as <?= e(handover_status_label((string) $handoverRecoveryTargetStatus)) ?>. Stock will be reissued only when that status needs it.</p>
            </div>

            <form class="stack-form" method="post" action="<?= e(url('/handovers/' . $handoverRecord['id'] . '/recover')) ?>" data-live-action-form>
                <?= csrf_field() ?>
                <label class="field">
                    <span>Recovery Note Optional</span>
                    <textarea name="status_notes" rows="3" placeholder="Optional note for why this handover is being reopened"></textarea>
                </label>
                <button class="primary-button" type="submit" data-confirm="Recover this handover as <?= e(handover_status_label((string) $handoverRecoveryTargetStatus)) ?>?">Recover Handover</button>
            </form>
        <?php elseif ($handoverRecoveryTargetStatus !== null && $handoverRecoveryBlockReason !== null): ?>
            <div class="copy-context-card">
                <strong>Status Control Blocked</strong>
                <p><?= e($handoverRecoveryBlockReason) ?></p>
            </div>
        <?php endif; ?>

        <?php if ($canVoidRecord): ?>
            <div class="copy-context-card">
                <strong>Owner audit cleanup</strong>
                <p>This record has no remaining stock impact. Mark it void to stop the workflow while keeping the handover, lines, files, and movement history visible.</p>
            </div>

            <form class="stack-form" method="post" action="<?= e(url('/handovers/' . $handoverRecord['id'] . '/void')) ?>" data-live-action-form>
                <?= csrf_field() ?>
                <label class="field">
                    <span>Type Handover Number</span>
                    <input type="text" name="void_confirm" placeholder="<?= e($handoverRecord['handover_number']) ?>" required>
                </label>
                <label class="field">
                    <span>Void Reason</span>
                    <textarea name="void_notes" rows="3" placeholder="Why this record is being voided" required></textarea>
                </label>
                <button class="ghost-button danger-button" type="submit" data-confirm="Mark this handover void and keep the audit trail?">Mark Void / Keep Record</button>
            </form>
        <?php endif; ?>
    </article>
</section>

<?php if ($isStaffCustody): ?>
    <section class="panel">
        <div class="panel-head">
            <div>
                <p class="eyebrow">Custody History</p>
                <h3>Partial Returns And Outcomes</h3>
                <p class="muted-copy">Approved rows are permanent. Rejected rows remain visible without changing stock.</p>
            </div>
            <span class="count-badge"><?= count($custodyReturns) ?></span>
        </div>

        <?php if ($custodyReturns): ?>
            <div class="table-wrap">
                <table class="data-table data-table-mobile">
                    <thead>
                    <tr>
                        <th>Return</th>
                        <th>Date</th>
                        <th>Serviceable</th>
                        <th>Damaged</th>
                        <th>Consumed</th>
                        <th>Lost</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($custodyReturns as $custodyReturn): ?>
                        <tr>
                            <td data-label="Return"><strong><?= e((string) $custodyReturn['return_number']) ?></strong></td>
                            <td data-label="Date"><?= e(!empty($custodyReturn['return_date']) ? date('M j, Y', strtotime((string) $custodyReturn['return_date'])) : 'Draft') ?></td>
                            <td data-label="Serviceable"><?= format_quantity((float) ($custodyReturn['serviceable_total'] ?? 0)) ?></td>
                            <td data-label="Damaged"><?= format_quantity((float) ($custodyReturn['damaged_total'] ?? 0)) ?></td>
                            <td data-label="Consumed"><?= format_quantity((float) ($custodyReturn['consumed_total'] ?? 0)) ?></td>
                            <td data-label="Lost"><?= format_quantity((float) ($custodyReturn['lost_total'] ?? 0)) ?></td>
                            <td data-label="Status"><span class="pill"><?= e(handover_custody_return_status_label((string) $custodyReturn['status'])) ?></span></td>
                            <td><a class="text-button" href="<?= e(url('/handovers/' . (int) $handoverRecord['id'] . '/custody-returns/' . (int) $custodyReturn['id'])) ?>">Open</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="empty-state">No custody returns have been reported yet.</p>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if ($canEditHandoverLines): ?>
    <section class="panel form-panel">
        <form class="stack-form" method="post" action="<?= e(url('/handovers/' . $handoverRecord['id'] . '/lines')) ?>">
            <?= csrf_field() ?>

            <div class="panel-head">
                <div>
                    <p class="eyebrow">Before Approval</p>
                    <h3>Edit Requested Items</h3>
                    <p class="muted-copy">Use this for typo fixes or adding missing items before the storage owner approves. After approval, create a new handover instead.</p>
                </div>
            </div>

            <select class="sr-only" name="source_storage_id" data-workflow-storage aria-hidden="true" tabindex="-1">
                <?php foreach ($sourceStorages as $storage): ?>
                    <option value="<?= e((string) $storage['id']) ?>" selected>
                        <?= e(storage_type_label((string) $storage['storage_type'])) ?> · <?= e((string) $storage['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <section
                class="workflow-line-builder"
                data-workflow-line-builder
                data-line-name-item="line_item_id[]"
                data-line-name-quantity="line_quantity[]"
                data-storage-catalog="<?= e((string) ($storageCatalogJson ?? '{}')) ?>"
                data-storage-meta="<?= e((string) ($storageMetaJson ?? '{}')) ?>"
                data-hide-availability="false"
                data-hide-item-quantity="false"
            >
                <div class="panel-head">
                    <div>
                        <p class="eyebrow">Line Items</p>
                        <h3>Requested Stock</h3>
                    </div>
                    <button class="ghost-button" type="button" data-add-workflow-line><?= ui_icon('plus') ?><span>Add Item</span></button>
                </div>

                <div class="table-wrap">
                    <table class="data-table workflow-line-table">
                        <thead>
                        <tr>
                            <th>Item</th>
                            <th>Available</th>
                            <th>Quantity</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody data-workflow-line-body>
                        <?php foreach ($editableLineItems as $line): ?>
                            <?php $selectedWorkflowItem = $workflowLinePreviewItem((array) $line); ?>
                            <tr data-workflow-line>
                                <td>
                                    <div class="workflow-picker" data-workflow-picker>
                                        <input type="hidden" name="line_item_id[]" value="<?= e((string) ($line['item_id'] ?? '')) ?>" data-workflow-item-input required>
                                        <button class="workflow-picker-toggle" type="button" data-workflow-picker-toggle>
                                            <span class="workflow-picker-toggle-copy" data-workflow-picker-label>
                                                <?php if ($selectedWorkflowItem): ?>
                                                    <span class="workflow-picker-selected">
                                                        <?php if (!empty($selectedWorkflowItem['image_url'])): ?>
                                                            <img class="workflow-picker-thumb" src="<?= e((string) $selectedWorkflowItem['image_url']) ?>" alt="<?= e((string) $selectedWorkflowItem['name']) ?>">
                                                        <?php else: ?>
                                                            <span class="workflow-picker-thumb workflow-picker-thumb-fallback"><?= e(item_initial((string) $selectedWorkflowItem['name'])) ?></span>
                                                        <?php endif; ?>
                                                        <span>
                                                            <strong><?= e((string) $selectedWorkflowItem['name']) ?></strong>
                                                            <span class="tiny-copy"><?= e((string) $selectedWorkflowItem['sku']) ?><?= !empty($selectedWorkflowItem['barcode']) ? ' · ' . e((string) $selectedWorkflowItem['barcode']) : '' ?> · <?= e((string) $selectedWorkflowItem['unit']) ?></span>
                                                        </span>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="workflow-picker-placeholder">Select source item first</span>
                                                <?php endif; ?>
                                            </span>
                                        </button>
                                        <div class="workflow-picker-panel" data-workflow-picker-panel hidden>
                                            <input class="workflow-picker-search" type="search" placeholder="Search item" data-workflow-picker-search>
                                            <div class="workflow-picker-options" data-workflow-picker-options></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="tiny-copy" data-workflow-available>-</span>
                                </td>
                                <td>
                                    <input type="number" step="0.01" min="0.01" name="line_quantity[]" value="<?= e((string) ($line['quantity'] ?? '')) ?>" required>
                                </td>
                                <td>
                                    <button class="text-button danger-link" type="button" data-remove-workflow-line>Remove</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <button class="primary-button" type="submit" data-confirm="Update requested handover items before approval?">Save Requested Items</button>
        </form>
    </section>
<?php endif; ?>

<section class="panel workflow-documents-panel">
    <div class="panel-head">
        <div>
            <p class="eyebrow">Attachments</p>
            <h3>Proof And Signature Sheet</h3>
        </div>
        <?php if ($signoffDocuments || $excelDocuments): ?>
            <div class="button-row">
                <?php if ($excelDocuments): ?>
                    <a class="ghost-button" href="<?= e(url('/workflow-documents/' . $excelDocuments[0]['id'] . '/download')) ?>"><?= ui_icon('export') ?><span>Download Excel Sheet</span></a>
                <?php endif; ?>
                <?php if ($signoffDocuments): ?>
                    <a class="ghost-button" href="<?= e(url('/workflow-documents/' . $signoffDocuments[0]['id'] . '/view')) ?>" target="_blank" rel="noopener"><?= ui_icon('document') ?><span>View Sign-Off PDF</span></a>
                    <a class="ghost-button" href="<?= e(url('/workflow-documents/' . $signoffDocuments[0]['id'] . '/download')) ?>"><?= ui_icon('document') ?><span>Download Sign-Off PDF</span></a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="workflow-document-grid">
        <?php if ($excelDocuments): ?>
            <?php foreach ($excelDocuments as $document): ?>
                <a class="workflow-document-card" href="<?= e(url('/workflow-documents/' . $document['id'] . '/download')) ?>">
                    <span><?= ui_icon('export') ?></span>
                    <strong>Excel workbook sign-off sheet</strong>
                    <small><?= e($document['original_filename']) ?></small>
                    <em>Editable XLSX with item images, SKU, and quantities.</em>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if ($signoffDocuments): ?>
            <?php foreach ($signoffDocuments as $document): ?>
                <a class="workflow-document-card" href="<?= e(url('/workflow-documents/' . $document['id'] . '/view')) ?>" target="_blank" rel="noopener">
                    <span><?= ui_icon('document') ?></span>
                    <strong>Receiver sign-off PDF</strong>
                    <small><?= e($document['original_filename']) ?></small>
                    <em>Preview in browser, print, sign, or download if needed.</em>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php foreach ($proofDocuments as $document): ?>
            <a class="workflow-document-card" href="<?= e(url('/workflow-documents/' . $document['id'] . '/download')) ?>">
                <span><?= ui_icon('files') ?></span>
                <strong><?= e(workflow_document_stage_label((string) $document['stage'])) ?></strong>
                <small><?= e($document['original_filename']) ?></small>
                <em><?= e(format_datetime_display((string) $document['created_at'])) ?> · <?= e((string) ($document['uploaded_by_name'] ?: 'Unknown uploader')) ?></em>
            </a>
        <?php endforeach; ?>

        <?php if (!$signoffDocuments && !$excelDocuments && !$proofDocuments): ?>
            <p class="empty-state">No workflow attachments yet.</p>
        <?php endif; ?>
    </div>
</section>

<section class="panel">
    <div class="panel-head">
        <div>
            <p class="eyebrow">Items</p>
            <h3>Handover Lines</h3>
        </div>
    </div>

    <div class="table-wrap">
        <table class="data-table data-table-mobile">
            <thead>
            <tr>
                <th>Item</th>
                <th>SKU</th>
                <th>Planned</th>
                <th>Received</th>
                <th><?= $isStorageTransfer ? 'To Destination' : ($isStaffCustody ? 'Consumed / Lost' : 'Used') ?></th>
                <th><?= $isStorageTransfer ? 'Returning To Source' : ($isStaffCustody ? 'Returned / Quarantined' : 'Returned') ?></th>
                <th><?= $isStaffCustody ? 'Still Held' : 'Difference / Unaccounted' ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($lines as $line): ?>
                <?php
                $imageUrl = item_image_url($line['image_path'] ?? null);
                $expectedUsageSummary = trim((string) ($line['expected_usage_reason_summary'] ?? ''));
                $usageSummary = trim((string) ($line['usage_reason_summary'] ?? ''));
                $varianceSummary = trim((string) ($line['usage_variance_summary'] ?? ''));
                $baseQuantity = in_array((string) $handoverRecord['status'], ['requested', 'awaiting_receipt'], true)
                    ? (float) $line['quantity_handed']
                    : (float) $line['quantity_received'];
                if ($isStorageTransfer) {
                    $lineReceiptWasReported = in_array((string) $handoverRecord['status'], ['receipt_review', 'closed'], true);
                    $lineShortage = max(0, round((float) $line['quantity_handed'] - (float) $line['quantity_received'], 2));
                    $lineToDestination = $lineReceiptWasReported ? round((float) $line['quantity_received'], 2) : 0.0;
                    $lineReturningToSource = $lineReceiptWasReported ? $lineShortage : 0.0;
                    $unaccounted = max(0, round((float) $line['quantity_handed'] - $lineToDestination - $lineReturningToSource, 2));
                } elseif ($isStaffCustody) {
                    $lineCustody = (array) ($custodyLineTotals[(int) $line['id']] ?? []);
                    $lineToDestination = (float) ($lineCustody['consumed_total'] ?? 0) + (float) ($lineCustody['lost_total'] ?? 0);
                    $lineReturningToSource = (float) ($lineCustody['serviceable_total'] ?? 0) + (float) ($lineCustody['damaged_total'] ?? 0);
                    $unaccounted = max(0, round((float) $line['quantity_received'] - $lineToDestination - $lineReturningToSource, 2));
                } else {
                    $lineToDestination = 0.0;
                    $lineReturningToSource = 0.0;
                    $unaccounted = round($baseQuantity - (float) $line['quantity_used'] - (float) $line['quantity_returned'], 2);
                }
                ?>
                <tr>
                    <td data-label="Item">
                        <?php if (Auth::hasPermission('items.view')): ?>
                            <a class="item-table-cell cell-link" href="<?= e(url('/items/' . $line['item_id'])) ?>">
                        <?php else: ?>
                            <div class="item-table-cell">
                        <?php endif; ?>
                            <?php if ($imageUrl): ?>
                                <img class="item-thumb expandable-image" src="<?= e($imageUrl) ?>" alt="<?= e($line['item_name']) ?>" data-expand-image tabindex="0">
                            <?php else: ?>
                                <span class="item-thumb item-thumb-fallback"><?= e(item_initial((string) $line['item_name'])) ?></span>
                            <?php endif; ?>
                            <div>
                                <strong><?= e($line['item_name']) ?></strong>
                                <div class="tiny-copy"><?= e($line['unit']) ?></div>
                                <?php if ($expectedUsageSummary !== '' || $usageSummary !== '' || $varianceSummary !== ''): ?>
                                    <div class="handover-line-usage-chips">
                                        <?php if ($expectedUsageSummary !== ''): ?>
                                            <span class="handover-usage-summary-chip">Expected: <?= e($expectedUsageSummary) ?></span>
                                        <?php endif; ?>
                                        <?php if ($usageSummary !== ''): ?>
                                            <span class="handover-usage-summary-chip">Actual: <?= e($usageSummary) ?></span>
                                        <?php endif; ?>
                                        <?php if ($varianceSummary !== ''): ?>
                                            <span class="handover-usage-summary-chip">Variance: <?= e($varianceSummary) ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php if (Auth::hasPermission('items.view')): ?>
                            </a>
                        <?php else: ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td data-label="SKU"><?= e($line['item_sku']) ?></td>
                    <td data-label="Planned"><?= format_quantity($line['quantity_handed']) ?> <?= e($line['unit']) ?></td>
                    <td data-label="Received"><?= format_quantity($line['quantity_received']) ?> <?= e($line['unit']) ?></td>
                    <?php if ($isStorageTransfer || $isStaffCustody): ?>
                        <td data-label="<?= $isStorageTransfer ? 'To Destination' : 'Consumed / Lost' ?>"><?= format_quantity($lineToDestination) ?> <?= e($line['unit']) ?></td>
                        <td data-label="<?= $isStorageTransfer ? 'Returning To Source' : 'Returned / Quarantined' ?>"><?= format_quantity($lineReturningToSource) ?> <?= e($line['unit']) ?></td>
                    <?php else: ?>
                        <td data-label="Used"><?= format_quantity($line['quantity_used']) ?> <?= e($line['unit']) ?></td>
                        <td data-label="Returned"><?= format_quantity($line['quantity_returned']) ?> <?= e($line['unit']) ?></td>
                    <?php endif; ?>
                    <td data-label="<?= $isStaffCustody ? 'Still Held' : 'Difference / Unaccounted' ?>"><?= format_quantity($unaccounted) ?> <?= e($line['unit']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
