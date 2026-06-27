<?php
$currentUser = Auth::user();
$documents = $documents ?? [];
$signoffDocuments = array_values(array_filter($documents, static fn (array $document): bool => (string) $document['document_type'] === 'signoff_pdf'));
$excelDocuments = array_values(array_filter($documents, static fn (array $document): bool => (string) $document['document_type'] === 'signoff_excel'));
$proofDocuments = array_values(array_filter($documents, static fn (array $document): bool => (string) $document['document_type'] === 'proof_image'));
$signoffDocuments = array_slice($signoffDocuments, 0, 1);
$excelDocuments = array_slice($excelDocuments, 0, 1);
$statusLabel = handover_status_label((string) $handoverRecord['status']);
$isRequestMode = (string) ($handoverRecord['handover_mode'] ?? 'direct') === 'request';
$isSourceOwner = Auth::isOwner()
    || (int) ($handoverRecord['source_owner_user_id'] ?? 0) === (int) ($currentUser['id'] ?? 0)
    || (int) ($handoverRecord['created_by'] ?? 0) === (int) ($currentUser['id'] ?? 0);
$canApproveRequest = Auth::hasPermission('handovers.approve')
    && handover_request_decision_block_reason($handoverRecord, $currentUser) === null;
$canRejectRequest = $canApproveRequest;
$canCancelHandover = handover_cancel_block_reason($handoverRecord, $currentUser) === null;
$canReportReceipt = handover_can_report_receipt($handoverRecord, $currentUser);
$canConfirmReceipt = Auth::hasPermission('handovers.approve')
    && handover_receipt_confirm_block_reason($handoverRecord, $currentUser) === null;
$canClose = Auth::hasPermission('handovers.close')
    && (string) $handoverRecord['status'] === 'delivered'
    && (
        (int) ($handoverRecord['recipient_user_id'] ?? 0) === (int) ($currentUser['id'] ?? 0)
        || ($isSourceOwner && empty($handoverRecord['recipient_user_id']))
    );
$canApproveClose = Auth::hasPermission('handovers.approve')
    && (string) $handoverRecord['status'] === 'pending_approval'
    && $isSourceOwner;
$canVoidRecord = workflow_void_block_reason('handover', $handoverRecord, $currentUser) === null;
$handoverRecoveryTargetStatus = Auth::hasPermission('handovers.status_override')
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
$remainingTotal = 0.0;

foreach ($lines as $line) {
    $plannedTotal += (float) $line['quantity_handed'];
    $receivedTotal += (float) $line['quantity_received'];
    $usedTotal += (float) $line['quantity_used'];
    $returnedTotal += (float) $line['quantity_returned'];
    $baseQuantity = in_array($handoverStatus, ['requested', 'awaiting_receipt'], true)
        ? (float) $line['quantity_handed']
        : (float) $line['quantity_received'];
    $remainingTotal += round($baseQuantity - (float) $line['quantity_used'] - (float) $line['quantity_returned'], 2);
}
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
                    <h4><?= e($handoverRecord['recipient_name']) ?></h4>
                    <p><?= e($handoverRecord['source_storage_name']) ?></p>
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
            <div>
                <span>Received</span>
                <strong><?= format_quantity($receivedTotal) ?></strong>
            </div>
            <div>
                <span>Used</span>
                <strong><?= format_quantity($usedTotal) ?></strong>
            </div>
            <div>
                <span>Remaining</span>
                <strong><?= format_quantity($remainingTotal) ?></strong>
            </div>
        </div>

        <div class="handover-compact-grid">
            <section class="handover-info-card">
                <span>Source</span>
                <strong><?= e($handoverRecord['source_storage_name']) ?></strong>
                <small><?= e(storage_type_label((string) $handoverRecord['source_storage_type'])) ?> · Owner: <?= e((string) ($handoverRecord['source_owner_name'] ?: 'Not assigned')) ?></small>
            </section>
            <section class="handover-info-card">
                <span>Mode</span>
                <strong><?= $isRequestMode ? 'Requested by staff' : 'Direct handover' ?></strong>
                <small><?= !empty($handoverRecord['requested_at']) ? e(format_datetime_display((string) $handoverRecord['requested_at'])) : 'No request timestamp' ?></small>
            </section>
            <section class="handover-info-card">
                <span>Recipient</span>
                <strong><?= e($handoverRecord['recipient_name']) ?></strong>
                <small><?= !empty($handoverRecord['recipient_user_name']) ? e($handoverRecord['recipient_user_name']) : 'No linked account' ?></small>
            </section>
            <section class="handover-info-card">
                <span>Schedule</span>
                <strong><?= !empty($handoverRecord['scheduled_for_date']) ? e(date('M j, Y', strtotime((string) $handoverRecord['scheduled_for_date']))) : 'Not set' ?></strong>
                <small><?= $isRequestMode ? 'Requested by' : 'Created by' ?> <?= e((string) ($handoverRecord['creator_name'] ?: 'Unknown')) ?></small>
            </section>
            <section class="handover-info-card">
                <span>Approval</span>
                <strong><?= e((string) ($handoverRecord['request_approver_name'] ?: 'Not assigned')) ?></strong>
                <small>Approved by: <?= e((string) ($handoverRecord['request_approved_by_name'] ?: 'Not approved yet')) ?></small>
            </section>
            <section class="handover-info-card">
                <span>Closeout</span>
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
                        Confirm Actual Receipt
                    <?php elseif ($canConfirmReceipt): ?>
                        Review Receipt Difference
                    <?php elseif ($canApproveClose): ?>
                        Approve Return To Storage
                    <?php elseif ($canCancelHandover): ?>
                        <?= e($cancelHandoverLabel) ?>
                    <?php else: ?>
                        Usage And Return
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
                <strong>Report the exact quantity you got</strong>
                <p>If anything arrived short, submit the real number. The storage owner will confirm the shortage before this handover becomes active.</p>
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
                            $oldReceivedInput = old('line_received', []);
                            $receivedValue = is_array($oldReceivedInput) && array_key_exists((int) $line['id'], $oldReceivedInput)
                                ? (string) $oldReceivedInput[(int) $line['id']]
                                : ((string) $handoverRecord['status'] === 'receipt_review'
                                    ? format_quantity((float) $line['quantity_received'])
                                    : format_quantity((float) $line['quantity_handed']));
                            ?>
                            <tr>
                                <td><?= e($line['item_name']) ?> <span class="tiny-copy"><?= e($line['item_sku']) ?></span></td>
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
                <strong>Approve the reported shortage</strong>
                <p>The quantities below were reported by the recipient. Approving this will return the missing difference back to the source storage and activate the handover.</p>
            </div>

            <form class="stack-form" method="post" action="<?= e(url('/handovers/' . $handoverRecord['id'] . '/confirm-receipt')) ?>" data-live-action-form>
                <?= csrf_field() ?>

                <div class="table-wrap">
                    <table class="data-table workflow-close-table">
                        <thead>
                        <tr>
                            <th>Item</th>
                            <th>Planned</th>
                            <th>Reported Received</th>
                            <th>Returning</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($lines as $line): ?>
                            <?php $shortage = round((float) $line['quantity_handed'] - (float) $line['quantity_received'], 2); ?>
                            <tr>
                                <td><?= e($line['item_name']) ?> <span class="tiny-copy"><?= e($line['item_sku']) ?></span></td>
                                <td><?= format_quantity($line['quantity_handed']) ?> <?= e($line['unit']) ?></td>
                                <td><?= format_quantity($line['quantity_received']) ?> <?= e($line['unit']) ?></td>
                                <td><?= format_quantity($shortage) ?> <?= e($line['unit']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <button class="primary-button" type="submit" data-confirm="Approve the reported receipt difference and activate this handover?">Approve Receipt Difference</button>
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
        <?php elseif ($canClose): ?>
            <form class="stack-form" method="post" action="<?= e(url('/handovers/' . $handoverRecord['id'] . '/close')) ?>" enctype="multipart/form-data" data-live-action-form data-handover-close-form>
                <?= csrf_field() ?>

                <div class="table-wrap">
                    <table class="data-table workflow-close-table">
                        <thead>
                        <tr>
                            <th>Item</th>
                            <th>Received</th>
                            <th>Used</th>
                            <th>Returning</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($lines as $line): ?>
                            <tr>
                                <td><?= e($line['item_name']) ?> <span class="tiny-copy"><?= e($line['item_sku']) ?></span></td>
                                <td><?= format_quantity($line['quantity_received']) ?> <?= e($line['unit']) ?></td>
                                <td><input type="number" step="0.01" min="0" max="<?= e(format_quantity($line['quantity_received'])) ?>" name="line_used[<?= e((string) $line['id']) ?>]" value="<?= e(format_quantity($line['quantity_used'])) ?>" data-handover-used data-handover-handed="<?= e(format_quantity($line['quantity_received'])) ?>" required></td>
                                <td><input type="text" value="<?= e(format_quantity((float) $line['quantity_received'] - (float) $line['quantity_used'])) ?>" data-handover-returned readonly></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <label class="field">
                    <span>Close Notes</span>
                    <textarea name="closed_notes" rows="4" placeholder="Anything worth keeping in the record"><?= e((string) ($handoverRecord['closed_notes'] ?? '')) ?></textarea>
                </label>

                <label class="field">
                    <span>Proof Image Optional</span>
                    <input type="file" name="proof_image" accept="image/jpeg,image/png,image/webp">
                    <small>Attach a returned-items photo, signed sheet, or usage proof.</small>
                </label>

                <button class="primary-button" type="submit" data-confirm="<?= empty($handoverRecord['recipient_user_id']) ? 'Close this handover now?' : 'Submit this handover? The storage owner will review the used quantity and approve the return.' ?>"><?= empty($handoverRecord['recipient_user_id']) ? 'Close Handover' : 'Submit For Approval' ?></button>
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
            <form class="stack-form" method="post" action="<?= e(url('/handovers/' . $handoverRecord['id'] . '/approve')) ?>" data-live-action-form>
                <?= csrf_field() ?>

                <div class="table-wrap">
                    <table class="data-table workflow-close-table">
                        <thead>
                        <tr>
                            <th>Item</th>
                            <th>Received</th>
                            <th>Used</th>
                            <th>Returning</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($lines as $line): ?>
                            <tr>
                                <td><?= e($line['item_name']) ?> <span class="tiny-copy"><?= e($line['item_sku']) ?></span></td>
                                <td><?= format_quantity($line['quantity_received']) ?> <?= e($line['unit']) ?></td>
                                <td><?= format_quantity($line['quantity_used']) ?> <?= e($line['unit']) ?></td>
                                <td><?= format_quantity($line['quantity_returned']) ?> <?= e($line['unit']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

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
                    This handover is waiting for the recipient to confirm what actually arrived.
                <?php elseif ((string) $handoverRecord['status'] === 'receipt_review'): ?>
                    This handover is waiting for the storage owner to confirm the reported shortage.
                <?php elseif ((string) $handoverRecord['status'] === 'pending_approval'): ?>
                    This handover is waiting for the storage owner to approve the remaining quantity.
                <?php elseif ((string) $handoverRecord['status'] === 'rejected'): ?>
                    This handover request was rejected.
                <?php elseif ((string) $handoverRecord['status'] === 'cancelled'): ?>
                    This handover request was cancelled.
                <?php else: ?>
                    This handover is already closed.
                <?php endif; ?>
            </p>
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
                <a class="workflow-document-card" href="<?= e(url('/workflow-documents/' . $document['id'] . '/download')) ?>">
                    <span><?= ui_icon('document') ?></span>
                    <strong>Receiver sign-off PDF</strong>
                    <small><?= e($document['original_filename']) ?></small>
                    <em>Download, print, sign, and upload a signed photo if needed.</em>
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
                <th>Used</th>
                <th>Returned</th>
                <th>Remaining</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($lines as $line): ?>
                <?php
                $imageUrl = item_image_url($line['image_path'] ?? null);
                $baseQuantity = in_array((string) $handoverRecord['status'], ['requested', 'awaiting_receipt'], true)
                    ? (float) $line['quantity_handed']
                    : (float) $line['quantity_received'];
                $remaining = round($baseQuantity - (float) $line['quantity_used'] - (float) $line['quantity_returned'], 2);
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
                    <td data-label="Used"><?= format_quantity($line['quantity_used']) ?> <?= e($line['unit']) ?></td>
                    <td data-label="Returned"><?= format_quantity($line['quantity_returned']) ?> <?= e($line['unit']) ?></td>
                    <td data-label="Remaining"><?= format_quantity($remaining) ?> <?= e($line['unit']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
