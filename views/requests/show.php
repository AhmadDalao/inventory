<?php
$currentUser = Auth::user();
$documents = $documents ?? [];
$signoffDocuments = array_values(array_filter($documents, static fn (array $document): bool => (string) $document['document_type'] === 'signoff_pdf'));
$excelDocuments = array_values(array_filter($documents, static fn (array $document): bool => (string) $document['document_type'] === 'signoff_excel'));
$proofDocuments = array_values(array_filter($documents, static fn (array $document): bool => (string) $document['document_type'] === 'proof_image'));
$signoffDocuments = array_slice($signoffDocuments, 0, 1);
$excelDocuments = array_slice($excelDocuments, 0, 1);
$selfDecisionBlocked = Auth::hasPermission('requests.approve')
    && (string) $requestRecord['status'] === 'pending'
    && (int) $requestRecord['requester_user_id'] === (int) ($currentUser['id'] ?? 0);
$canApprove = Auth::hasPermission('requests.approve')
    && request_decision_block_reason($requestRecord, $currentUser) === null;
$canReportReceipt = request_can_report_receipt($requestRecord, $currentUser);
$canConfirmReceipt = Auth::hasPermission('requests.approve')
    && request_receipt_confirm_block_reason($requestRecord, $currentUser) === null;
$canCancel = request_cancel_block_reason($requestRecord, $currentUser) === null;
$canVoidRecord = workflow_void_block_reason('request', $requestRecord, $currentUser) === null;
$requestRecoveryTargetStatus = Auth::hasPermission('requests.status_override')
    ? request_recovery_target_status($requestRecord, $lines)
    : null;
$requestRecoveryBlockReason = $requestRecoveryTargetStatus !== null
    ? request_recovery_block_reason($requestRecord, $lines, $currentUser)
    : null;
$canRecoverRequest = $requestRecoveryTargetStatus !== null && $requestRecoveryBlockReason === null;
$isIssueRequest = (string) ($requestRecord['request_mode'] ?? 'transfer') === 'issue';
$isReceiptReview = (string) $requestRecord['status'] === 'receipt_review';
$requestTypeLabel = $isIssueRequest ? 'Staff Use Request' : 'Storage Transfer Request';
$requestedTotal = 0.0;
$approvedTotal = 0.0;
$receivedTotal = 0.0;

foreach ($lines as $line) {
    $requestedTotal += (float) $line['quantity_requested'];
    $approvedTotal += (float) $line['quantity_approved'];
    $receivedTotal += (float) $line['quantity_received'];
}
?>

<section class="page-head">
    <div class="page-head-copy">
        <p class="eyebrow">Request Detail</p>
        <h3 class="page-head-title"><?= ui_icon('requests') ?><span><?= e($requestRecord['request_number']) ?></span></h3>
    </div>
    <div class="page-actions">
        <a class="ghost-button" href="<?= e(url('/requests')) ?>"><?= ui_icon('back') ?><span>All Requests</span></a>
    </div>
</section>

<section class="detail-grid handover-detail-grid request-detail-grid">
    <article class="panel detail-summary">
        <div class="detail-hero">
            <div class="detail-hero-main">
                <div class="item-hero-image item-hero-image-fallback">R</div>
                <div>
                    <span class="pill pill-<?= e((string) $requestRecord['status']) ?>"><?= e(request_status_label((string) $requestRecord['status'])) ?></span>
                    <h4><?= e($requestTypeLabel) ?></h4>
                    <p>
                        <?= e($requestRecord['requester_name']) ?>
                        <?php if ($isIssueRequest): ?>
                            requested items from <?= e($requestRecord['source_storage_name']) ?>
                        <?php else: ?>
                            requested stock from <?= e($requestRecord['source_storage_name']) ?> to <?= e($requestRecord['destination_storage_name']) ?>
                        <?php endif; ?>
                    </p>
                    <p class="tiny-copy">Requested <?= e(format_datetime_display((string) $requestRecord['requested_at'])) ?></p>
                </div>
            </div>
        </div>

        <div class="handover-value-strip">
            <div>
                <span>Requested</span>
                <strong><?= format_quantity($requestedTotal) ?></strong>
            </div>
            <div>
                <span>Approved</span>
                <strong><?= format_quantity($approvedTotal) ?></strong>
            </div>
            <div>
                <span>Received</span>
                <strong><?= format_quantity($receivedTotal) ?></strong>
            </div>
            <div>
                <span>Lines</span>
                <strong><?= number_format(count($lines)) ?></strong>
            </div>
        </div>

        <div class="handover-compact-grid">
            <section class="handover-info-card">
                <span>Source</span>
                <strong><?= e($requestRecord['source_storage_name']) ?></strong>
                <small><?= e(storage_type_label((string) $requestRecord['source_storage_type'])) ?></small>
            </section>
            <section class="handover-info-card">
                <span>Destination</span>
                <strong><?= $isIssueRequest ? 'Staff use' : e((string) $requestRecord['destination_storage_name']) ?></strong>
                <small><?= $isIssueRequest ? 'No storage destination' : e(storage_type_label((string) $requestRecord['destination_storage_type'])) ?></small>
            </section>
            <section class="handover-info-card">
                <span>Requester</span>
                <strong><?= e($requestRecord['requester_name']) ?></strong>
                <small><?= e($requestRecord['requester_email']) ?></small>
            </section>
            <section class="handover-info-card">
                <span>Approver</span>
                <strong><?= e($requestRecord['approver_name']) ?></strong>
                <small><?= e($requestRecord['approver_email']) ?></small>
            </section>
            <section class="handover-info-card">
                <span>Need By</span>
                <strong><?= !empty($requestRecord['needed_by_date']) ? e(date('M j, Y', strtotime((string) $requestRecord['needed_by_date']))) : 'Not set' ?></strong>
                <small><?= e($requestTypeLabel) ?></small>
            </section>
            <section class="handover-info-card">
                <span>Receipt</span>
                <strong><?= !empty($requestRecord['receipt_reported_at']) ? e(format_datetime_display((string) $requestRecord['receipt_reported_at'])) : 'Not reported' ?></strong>
                <small>Status: <?= e(request_status_label((string) $requestRecord['status'])) ?></small>
            </section>
        </div>

        <details class="handover-note-drawer">
            <summary>Notes And Request History</summary>
            <div class="handover-note-grid">
                <section>
                    <span>Notes</span>
                    <p><?= nl2br(e((string) ($requestRecord['notes'] ?: 'No notes.'))) ?></p>
                </section>
                <section>
                    <span>Decision Notes</span>
                    <p><?= nl2br(e((string) ($requestRecord['decision_notes'] ?: 'No decision notes yet.'))) ?></p>
                </section>
                <section>
                    <span>Receipt Notes</span>
                    <p><?= nl2br(e((string) ($requestRecord['receipt_notes'] ?: 'No receipt notes yet.'))) ?></p>
                </section>
            </div>
        </details>
    </article>

    <article class="panel handover-action-panel">
        <div class="panel-head">
            <div>
                <p class="eyebrow">Actions</p>
                <h3>Next Step</h3>
            </div>
        </div>

        <div class="workflow-action-stack">
            <?php if ($selfDecisionBlocked): ?>
                <div class="copy-context-card">
                    <strong>Self-approval is blocked</strong>
                    <p>You created this request, so someone else has to approve or reject it.</p>
                </div>
            <?php endif; ?>

            <?php if ($canApprove): ?>
                <form class="stack-form" method="post" action="<?= e(url('/requests/' . $requestRecord['id'] . '/approve')) ?>" data-live-action-form>
                    <?= csrf_field() ?>
                    <label class="field">
                        <span>Approval Note</span>
                        <textarea name="decision_notes" rows="3" placeholder="Optional note for the requester"></textarea>
                    </label>
                    <button class="primary-button" type="submit">Approve Request</button>
                </form>

                <form class="stack-form" method="post" action="<?= e(url('/requests/' . $requestRecord['id'] . '/reject')) ?>" data-live-action-form>
                    <?= csrf_field() ?>
                    <label class="field">
                        <span>Rejection Note</span>
                        <textarea name="decision_notes" rows="3" placeholder="Tell the requester why this is rejected"></textarea>
                    </label>
                    <button class="ghost-button danger-link" type="submit">Reject Request</button>
                </form>
            <?php endif; ?>

            <?php if ($canReportReceipt): ?>
                <div class="copy-context-card">
                    <strong><?= $isReceiptReview ? 'Receipt report is waiting for approval' : 'Report exact received quantities' ?></strong>
                    <p><?= $isReceiptReview ? 'Update the received quantities below if something changed. The approver still has to confirm the final numbers.' : 'Enter what actually arrived. If anything is short, the remainder stays in transit until the approver confirms the return.' ?></p>
                </div>
            <?php endif; ?>

            <?php if ($canConfirmReceipt): ?>
                <form method="post" action="<?= e(url('/requests/' . $requestRecord['id'] . '/confirm-receipt')) ?>" data-live-action-form>
                    <?= csrf_field() ?>
                    <button class="primary-button" type="submit" data-confirm="Approve the reported received quantities and close this request?">Approve Receipt Report</button>
                </form>
            <?php endif; ?>

            <?php if ($canCancel): ?>
                <form class="stack-form" method="post" action="<?= e(url('/requests/' . $requestRecord['id'] . '/cancel')) ?>" data-live-action-form>
                    <?= csrf_field() ?>
                    <label class="field">
                        <span>Cancel Note Optional</span>
                        <textarea name="decision_notes" rows="3" placeholder="Optional reason, typo, wrong request, or no longer needed"></textarea>
                    </label>
                    <button class="ghost-button danger-link" type="submit" data-confirm="Cancel this request?<?= in_array((string) $requestRecord['status'], ['approved', 'receipt_review'], true) ? ' Reserved stock will be moved back out of transit.' : '' ?>">Cancel Request</button>
                </form>
            <?php endif; ?>

            <?php if ($canRecoverRequest): ?>
                <div class="copy-context-card">
                    <strong>Status Control</strong>
                    <p>Recover this request and reopen it as <?= e(request_status_label((string) $requestRecoveryTargetStatus)) ?>. Stock will be re-reserved only when that status needs it.</p>
                </div>

                <form class="stack-form" method="post" action="<?= e(url('/requests/' . $requestRecord['id'] . '/recover')) ?>" data-live-action-form>
                    <?= csrf_field() ?>
                    <label class="field">
                        <span>Recovery Note Optional</span>
                        <textarea name="status_notes" rows="3" placeholder="Optional note for why this request is being reopened"></textarea>
                    </label>
                    <button class="primary-button" type="submit" data-confirm="Recover this request as <?= e(request_status_label((string) $requestRecoveryTargetStatus)) ?>?">Recover Request</button>
                </form>
            <?php elseif ($requestRecoveryTargetStatus !== null && $requestRecoveryBlockReason !== null): ?>
                <div class="copy-context-card">
                    <strong>Status Control Blocked</strong>
                    <p><?= e($requestRecoveryBlockReason) ?></p>
                </div>
            <?php endif; ?>

        <?php if ($canVoidRecord): ?>
            <div class="copy-context-card">
                <strong>Owner audit cleanup</strong>
                <p>This record has no remaining stock impact. Mark it void to stop the workflow while keeping the request, lines, files, and history visible.</p>
            </div>

                <form class="stack-form" method="post" action="<?= e(url('/requests/' . $requestRecord['id'] . '/void')) ?>" data-live-action-form>
                    <?= csrf_field() ?>
                    <label class="field">
                        <span>Type Request Number</span>
                        <input type="text" name="void_confirm" placeholder="<?= e($requestRecord['request_number']) ?>" required>
                    </label>
                <label class="field">
                    <span>Void Reason</span>
                    <textarea name="void_notes" rows="3" placeholder="Why this record is being voided" required></textarea>
                </label>
                <button class="ghost-button danger-link" type="submit" data-confirm="Mark this request void and keep the audit trail?">Mark Void / Keep Record</button>
            </form>
        <?php endif; ?>

            <?php if (!$canApprove && !$canReportReceipt && !$canConfirmReceipt && !$canCancel && !$canRecoverRequest && !$canVoidRecord): ?>
                <p class="empty-state">No action is available on this request right now.</p>
            <?php endif; ?>
        </div>
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
                    <strong>Request sign-off PDF</strong>
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
            <p class="eyebrow">Requested Items</p>
            <h3>Line Items</h3>
        </div>
    </div>

    <?php if ($canReportReceipt): ?>
        <form class="stack-form" method="post" action="<?= e(url('/requests/' . $requestRecord['id'] . '/receive')) ?>" enctype="multipart/form-data" data-live-action-form>
            <?= csrf_field() ?>
            <div class="table-wrap">
                <table class="data-table data-table-mobile">
                    <thead>
                    <tr>
                        <th>Item</th>
                        <th>SKU</th>
                        <th>Requested</th>
                        <th>Approved</th>
                        <th>Received</th>
                        <?php if (!$isIssueRequest && !Auth::isStaff()): ?>
                            <th>Available Now</th>
                        <?php endif; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($lines as $line): ?>
                        <?php
                        $imageUrl = item_image_url($line['image_path'] ?? null);
                        $oldReceivedInput = old('line_received', []);
                        $receivedValue = is_array($oldReceivedInput) && array_key_exists((int) $line['id'], $oldReceivedInput)
                            ? (string) $oldReceivedInput[(int) $line['id']]
                            : ($isReceiptReview
                                ? format_quantity((float) $line['quantity_received'])
                                : ((float) $line['quantity_received'] > 0 ? format_quantity((float) $line['quantity_received']) : format_quantity((float) $line['quantity_approved'])));
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
                            <td data-label="Requested"><?= format_quantity($line['quantity_requested']) ?> <?= e($line['unit']) ?></td>
                            <td data-label="Approved"><?= format_quantity($line['quantity_approved']) ?> <?= e($line['unit']) ?></td>
                            <td data-label="Received">
                                <input
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    max="<?= e((string) $line['quantity_approved']) ?>"
                                    name="line_received[<?= e((string) $line['id']) ?>]"
                                    value="<?= e($receivedValue) ?>"
                                    required
                                >
                            </td>
                            <?php if (!$isIssueRequest && !Auth::isStaff()): ?>
                                <td data-label="Available Now"><?= format_quantity($line['source_available_now']) ?> <?= e($line['unit']) ?></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <label class="field">
                <span>Receipt Note</span>
                <textarea name="receipt_notes" rows="4" placeholder="Add context if anything arrived short or damaged."><?= e((string) old('receipt_notes', (string) ($requestRecord['receipt_notes'] ?? ''))) ?></textarea>
            </label>

            <label class="field">
                <span>Proof Image Optional</span>
                <input type="file" name="proof_image" accept="image/jpeg,image/png,image/webp">
                <small>Upload a delivery photo, signed paper, or item proof if needed.</small>
            </label>

            <button class="primary-button" type="submit"><?= $isReceiptReview ? 'Update Receipt Report' : 'Submit Receipt Report' ?></button>
        </form>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table data-table-mobile">
                <thead>
                <tr>
                    <th>Item</th>
                    <th>SKU</th>
                    <th>Requested</th>
                    <th>Approved</th>
                    <th>Received</th>
                    <?php if (!$isIssueRequest && !Auth::isStaff()): ?>
                        <th>Available Now</th>
                    <?php endif; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($lines as $line): ?>
                    <?php $imageUrl = item_image_url($line['image_path'] ?? null); ?>
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
                        <td data-label="Requested"><?= format_quantity($line['quantity_requested']) ?> <?= e($line['unit']) ?></td>
                        <td data-label="Approved"><?= format_quantity($line['quantity_approved']) ?> <?= e($line['unit']) ?></td>
                        <td data-label="Received"><?= format_quantity($line['quantity_received']) ?> <?= e($line['unit']) ?></td>
                        <?php if (!$isIssueRequest && !Auth::isStaff()): ?>
                            <td data-label="Available Now"><?= format_quantity($line['source_available_now']) ?> <?= e($line['unit']) ?></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
