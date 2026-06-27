<?php
$currentUser = Auth::user();
$status = (string) $purchase['status'];
$canEditDraft = Auth::hasPermission('purchases.create') && $status === 'draft';
$canSubmitDraft = Auth::hasPermission('purchases.create') && $status === 'draft';
$canApprove = Auth::hasPermission('purchases.approve') && purchase_decision_block_reason($purchase, $currentUser) === null;
$canReject = $canApprove;
$canReceive = Auth::hasPermission('purchases.receive') && $status === 'approved';
$canConfirmReceipt = Auth::hasPermission('purchases.approve') && purchase_confirm_receipt_block_reason($purchase, $currentUser) === null;
$selfApprovalBlocked = Auth::hasPermission('purchases.approve')
    && in_array($status, ['pending_approval', 'receipt_review'], true)
    && (
        (int) $purchase['requester_user_id'] === (int) ($currentUser['id'] ?? 0)
        || (int) ($purchase['receiver_user_id'] ?? 0) === (int) ($currentUser['id'] ?? 0)
    );
$canCancel = Auth::hasPermission('purchases.cancel')
    && in_array($status, ['draft', 'pending_approval', 'approved'], true)
    && (
        Auth::isOwner()
        || (int) $purchase['requester_user_id'] === (int) ($currentUser['id'] ?? 0)
        || (int) $purchase['approver_user_id'] === (int) ($currentUser['id'] ?? 0)
    );
$requestedTotal = array_reduce($lines, static fn (float $carry, array $line): float => $carry + ((float) $line['quantity_requested'] * (float) $line['unit_cost_quoted']), 0.0);
$approvedTotal = array_reduce($lines, static fn (float $carry, array $line): float => $carry + ((float) $line['quantity_approved'] * (float) $line['unit_cost_approved']), 0.0);
$finalTotal = array_reduce($lines, static fn (float $carry, array $line): float => $carry + ((float) $line['quantity_final'] * (float) $line['unit_cost_approved']), 0.0);
?>

<section class="page-head">
    <div class="page-head-copy">
        <p class="eyebrow">Purchase Detail</p>
        <h3 class="page-head-title"><?= ui_icon('purchases') ?><span><?= e($purchase['purchase_number']) ?></span></h3>
    </div>
    <div class="page-actions">
        <a class="ghost-button" href="<?= e(url('/purchases')) ?>"><?= ui_icon('back') ?><span>All Purchases</span></a>
        <?php if ($canEditDraft): ?>
            <a class="primary-button" href="<?= e(url('/purchases/' . $purchase['id'] . '/edit')) ?>"><?= ui_icon('edit') ?><span>Edit Draft</span></a>
        <?php endif; ?>
    </div>
</section>

<section class="detail-grid purchase-detail-grid">
    <article class="panel detail-summary">
        <div class="detail-hero">
            <div class="detail-hero-main">
                <div class="item-hero-image item-hero-image-fallback"><?= ui_icon('supplier') ?></div>
                <div>
                    <span class="pill pill-<?= e($status) ?>"><?= e(purchase_status_label($status)) ?></span>
                    <h4><?= e($purchase['supplier_name']) ?></h4>
                    <p>Restocking into <?= e($purchase['storage_name']) ?>.</p>
                    <p class="tiny-copy">Created <?= e(format_datetime_display((string) $purchase['created_at'])) ?></p>
                </div>
            </div>
        </div>

        <div class="purchase-value-strip">
            <div>
                <span>Quoted</span>
                <strong><?= e($purchase['currency']) ?> <?= number_format($requestedTotal, 2) ?></strong>
            </div>
            <div>
                <span>Approved</span>
                <strong><?= e($purchase['currency']) ?> <?= number_format($approvedTotal, 2) ?></strong>
            </div>
            <div>
                <span>Final</span>
                <strong><?= e($purchase['currency']) ?> <?= number_format($finalTotal, 2) ?></strong>
            </div>
            <div>
                <span>Expected</span>
                <strong><?= !empty($purchase['expected_date']) ? e(date('M j, Y', strtotime((string) $purchase['expected_date']))) : 'Not set' ?></strong>
            </div>
        </div>

        <div class="purchase-compact-grid">
            <section class="purchase-info-card">
                <span>Supplier</span>
                <strong><?= e($purchase['supplier_name']) ?></strong>
                <small><?= e(supplier_type_display($purchase['supplier_type'] ?? 'product', $purchase['supplier_type_other'] ?? null)) ?></small>
            </section>
            <section class="purchase-info-card">
                <span>Contact</span>
                <strong><?= e($purchase['supplier_phone'] ?: 'Not set') ?></strong>
                <small><?= e($purchase['supplier_email'] ?: 'No email') ?></small>
            </section>
            <section class="purchase-info-card">
                <span>VAT / CR</span>
                <strong><?= e($purchase['supplier_tax_number'] ?: 'No VAT') ?></strong>
                <small><?= e($purchase['supplier_commercial_registration'] ?: 'No CR') ?></small>
            </section>
            <section class="purchase-info-card">
                <span>Authorized</span>
                <strong><?= e($purchase['supplier_authorized_person'] ?: 'Not set') ?></strong>
                <small><?= e($purchase['supplier_national_address'] ?: 'No national address') ?></small>
            </section>
            <section class="purchase-info-card">
                <span>Destination</span>
                <strong><?= e($purchase['storage_name']) ?></strong>
                <small><?= e(storage_type_label((string) $purchase['storage_type'])) ?></small>
            </section>
            <section class="purchase-info-card">
                <span>People</span>
                <strong><?= e($purchase['requester_name']) ?></strong>
                <small>Approver: <?= e($purchase['approver_name']) ?> · Receiver: <?= e($purchase['receiver_name'] ?: 'Not reported') ?></small>
            </section>
        </div>

        <details class="purchase-note-drawer">
            <summary>Notes And Decision History</summary>
            <div class="purchase-note-grid">
                <section>
                    <span>Notes</span>
                    <p><?= nl2br(e((string) ($purchase['notes'] ?: 'No notes.'))) ?></p>
                </section>
                <section>
                    <span>Decision Notes</span>
                    <p><?= nl2br(e((string) ($purchase['decision_notes'] ?: 'No decision notes yet.'))) ?></p>
                </section>
                <section>
                    <span>Receipt Notes</span>
                    <p><?= nl2br(e((string) ($purchase['receipt_notes'] ?: 'No receipt notes yet.'))) ?></p>
                </section>
            </div>
        </details>
    </article>

    <article class="panel purchase-action-panel">
        <div class="panel-head">
            <div>
                <p class="eyebrow">Actions</p>
                <h3>Next Step</h3>
            </div>
        </div>

        <div class="workflow-action-stack">
            <?php if ($selfApprovalBlocked): ?>
                <div class="copy-context-card">
                    <strong>Self-approval is blocked</strong>
                    <p>You created or reported this purchase, so another approver must handle the decision.</p>
                </div>
            <?php endif; ?>

            <?php if ($canSubmitDraft): ?>
                <form method="post" action="<?= e(url('/purchases/' . $purchase['id'] . '/submit')) ?>" data-live-action-form>
                    <?= csrf_field() ?>
                    <button class="primary-button" type="submit">Submit For Approval</button>
                </form>
            <?php endif; ?>

            <?php if ($canApprove): ?>
                <form class="stack-form" method="post" action="<?= e(url('/purchases/' . $purchase['id'] . '/approve')) ?>" data-live-action-form>
                    <?= csrf_field() ?>
                    <div class="copy-context-card">
                        <strong>Approval does not add stock</strong>
                        <p>Adjust approved quantities or prices below. Stock is added only after receipt confirmation.</p>
                    </div>
                    <?php foreach ($lines as $line): ?>
                        <div class="field-row">
                            <label class="field">
                                <span><?= e($line['item_name']) ?> approved qty</span>
                                <input type="number" step="0.01" min="0" name="approved_quantity[<?= e((string) $line['id']) ?>]" value="<?= e(format_quantity($line['quantity_requested'])) ?>">
                            </label>
                            <label class="field">
                                <span><?= e($line['item_name']) ?> unit price</span>
                                <input type="number" step="0.01" min="0" name="approved_unit_cost[<?= e((string) $line['id']) ?>]" value="<?= e(format_quantity($line['unit_cost_quoted'])) ?>">
                            </label>
                        </div>
                    <?php endforeach; ?>
                    <label class="field">
                        <span>Approval Note</span>
                        <textarea name="decision_notes" rows="3"></textarea>
                    </label>
                    <button class="primary-button" type="submit">Approve Purchase</button>
                </form>

                <form class="stack-form" method="post" action="<?= e(url('/purchases/' . $purchase['id'] . '/reject')) ?>" data-live-action-form>
                    <?= csrf_field() ?>
                    <label class="field">
                        <span>Rejection Note</span>
                        <textarea name="decision_notes" rows="3"></textarea>
                    </label>
                    <button class="ghost-button danger-link" type="submit">Reject Purchase</button>
                </form>
            <?php endif; ?>

            <?php if ($canReceive): ?>
                <form class="stack-form" method="post" action="<?= e(url('/purchases/' . $purchase['id'] . '/receive')) ?>" enctype="multipart/form-data" data-live-action-form>
                    <?= csrf_field() ?>
                    <div class="copy-context-card">
                        <strong>Report exact received quantities</strong>
                        <p>If supplier delivered less than approved, write the exact number received. The approver confirms the final stock.</p>
                    </div>
                    <?php foreach ($lines as $line): ?>
                        <label class="field">
                            <span><?= e($line['item_name']) ?> received qty</span>
                            <input type="number" step="0.01" min="0" max="<?= e((string) $line['quantity_approved']) ?>" name="received_quantity[<?= e((string) $line['id']) ?>]" value="<?= e(format_quantity($line['quantity_approved'])) ?>" required>
                        </label>
                    <?php endforeach; ?>
                    <div class="field-row">
                        <label class="field">
                            <span>Document Type</span>
                            <select name="document_type">
                                <?php foreach ($documentTypes as $type => $label): ?>
                                    <option value="<?= e($type) ?>" <?= selected($type, 'receipt') ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="field">
                            <span>Receipt / Proof Files</span>
                            <input type="file" name="documents[]" multiple accept="application/pdf,image/jpeg,image/png,image/webp">
                        </label>
                    </div>
                    <label class="field">
                        <span>Receipt Note</span>
                        <textarea name="receipt_notes" rows="3"></textarea>
                    </label>
                    <button class="primary-button" type="submit">Submit Receipt Report</button>
                </form>
            <?php endif; ?>

            <?php if ($canConfirmReceipt): ?>
                <form class="stack-form" method="post" action="<?= e(url('/purchases/' . $purchase['id'] . '/confirm-receipt')) ?>" data-live-action-form>
                    <?= csrf_field() ?>
                    <div class="copy-context-card">
                        <strong>Final confirmation adds stock</strong>
                        <p>These final quantities create restock movements, update storage balances, and recalculate weighted average cost.</p>
                    </div>
                    <?php foreach ($lines as $line): ?>
                        <label class="field">
                            <span><?= e($line['item_name']) ?> final received qty</span>
                            <input type="number" step="0.01" min="0" max="<?= e((string) $line['quantity_approved']) ?>" name="final_quantity[<?= e((string) $line['id']) ?>]" value="<?= e(format_quantity($line['quantity_received'])) ?>" required>
                        </label>
                    <?php endforeach; ?>
                    <button class="primary-button" type="submit" data-confirm="Confirm receipt and add stock to storage?">Confirm Receipt And Stock</button>
                </form>
            <?php endif; ?>

            <?php if ($canCancel): ?>
                <form method="post" action="<?= e(url('/purchases/' . $purchase['id'] . '/cancel')) ?>" data-live-action-form>
                    <?= csrf_field() ?>
                    <button class="ghost-button danger-link" type="submit" data-confirm="Cancel this purchase? Stock will not change.">Cancel Purchase</button>
                </form>
            <?php endif; ?>

            <?php if (!$canSubmitDraft && !$canApprove && !$canReceive && !$canConfirmReceipt && !$canCancel): ?>
                <p class="empty-state">No action is available on this purchase right now.</p>
            <?php endif; ?>
        </div>
    </article>
</section>

<section class="panel">
    <div class="panel-head">
        <div>
            <p class="eyebrow">Line Items</p>
            <h3>Pricing And Receiving</h3>
        </div>
    </div>

    <div class="table-wrap">
        <table class="data-table data-table-mobile">
            <thead>
            <tr>
                <th>Item</th>
                <th>SKU</th>
                <th>Barcode</th>
                <th>Unit</th>
                <th>Requested</th>
                <th>Approved</th>
                <th>Received</th>
                <th>Final</th>
                <th>Quoted Price</th>
                <th>Approved Price</th>
                <th>Final Total</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($lines as $line): ?>
                <?php $imageUrl = item_image_url($line['catalog_image_path'] ?: ($line['item_image_path'] ?? null)); ?>
                <tr>
                    <td data-label="Item">
                        <?php if (!empty($line['item_id']) && Auth::hasPermission('items.view')): ?>
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
                                <div class="tiny-copy"><?= !empty($line['item_id']) ? 'Catalog item' : 'Pending quick-create' ?></div>
                            </div>
                        <?php if (!empty($line['item_id']) && Auth::hasPermission('items.view')): ?>
                            </a>
                        <?php else: ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td data-label="SKU"><?= e($line['item_sku']) ?></td>
                    <td data-label="Barcode"><?= normalize_item_barcode($line['item_barcode'] ?? '') !== '' ? e((string) $line['item_barcode']) : 'Not set' ?></td>
                    <td data-label="Unit"><?= e($line['unit']) ?></td>
                    <td data-label="Requested"><?= format_quantity($line['quantity_requested']) ?></td>
                    <td data-label="Approved"><?= format_quantity($line['quantity_approved']) ?></td>
                    <td data-label="Received"><?= format_quantity($line['quantity_received']) ?></td>
                    <td data-label="Final"><?= format_quantity($line['quantity_final']) ?></td>
                    <td data-label="Quoted Price"><?= e($purchase['currency']) ?> <?= number_format((float) $line['unit_cost_quoted'], 2) ?></td>
                    <td data-label="Approved Price"><?= e($purchase['currency']) ?> <?= number_format((float) $line['unit_cost_approved'], 2) ?></td>
                    <td data-label="Final Total"><?= e($purchase['currency']) ?> <?= number_format((float) $line['quantity_final'] * (float) $line['unit_cost_approved'], 2) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel">
    <div class="panel-head">
        <div>
            <p class="eyebrow">Protected Files</p>
            <h3>Documents</h3>
        </div>
    </div>

    <?php if ($documents === []): ?>
        <p class="empty-state">No quote, price list, receipt, or proof files attached yet.</p>
    <?php else: ?>
        <div class="document-grid">
            <?php foreach ($documents as $document): ?>
                <article class="document-card">
                    <span class="metric-card-icon"><?= ui_icon('document') ?><span><?= e($documentTypes[$document['document_type']] ?? ucfirst((string) $document['document_type'])) ?></span></span>
                    <strong><?= e($document['original_filename']) ?></strong>
                    <span class="tiny-copy"><?= number_format(((float) $document['file_size']) / 1024, 1) ?> KB · uploaded <?= e(format_datetime_display((string) $document['created_at'])) ?></span>
                    <div class="button-row">
                        <?php if (Auth::hasPermission('purchases.files')): ?>
                            <a class="ghost-button" href="<?= e(url('/purchases/documents/' . $document['id'] . '/download')) ?>">Download</a>
                            <?php if ($status === 'draft'): ?>
                                <form method="post" action="<?= e(url('/purchases/documents/' . $document['id'] . '/delete')) ?>" data-live-action-form>
                                    <?= csrf_field() ?>
                                    <button class="text-button danger-link" type="submit">Delete</button>
                                </form>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="tiny-copy">You need purchase file access to download this file.</span>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
