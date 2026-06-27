<?php
$isEdit = ($mode ?? 'create') === 'edit';
$actionUrl = $isEdit ? url('/purchases/' . $purchase['id'] . '/edit') : url('/purchases/create');
$itemsJson = json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$suppliersJson = json_encode($suppliers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$barcodeRequired = item_barcodes_required();
$selectedSupplierId = (string) ($purchase['supplier_id'] ?? '');
$selectedSupplierName = 'Create new supplier';

foreach ($suppliers as $supplierOption) {
    if ((string) $supplierOption['id'] === $selectedSupplierId) {
        $selectedSupplierName = (string) $supplierOption['name'];
        break;
    }
}
?>

<section class="page-head">
    <div class="page-head-copy">
        <p class="eyebrow">Supplier Purchase</p>
        <h3 class="page-head-title"><?= ui_icon('purchases') ?><span><?= e($isEdit ? 'Edit Draft' : 'Create Purchase') ?></span></h3>
    </div>
    <div class="page-actions">
        <a class="ghost-button" href="<?= e($isEdit ? url('/purchases/' . $purchase['id']) : url('/purchases')) ?>"><?= ui_icon('back') ?><span>Back</span></a>
    </div>
</section>

<section class="panel form-panel">
    <form class="stack-form" method="post" action="<?= e($actionUrl) ?>" enctype="multipart/form-data" data-purchase-line-builder data-purchase-catalog="<?= e((string) $itemsJson) ?>" data-purchase-suppliers="<?= e((string) $suppliersJson) ?>" data-purchase-ocr-url="<?= e(url('/purchases/ocr-preview')) ?>">
        <?= csrf_field() ?>

        <div class="copy-context-card">
            <strong>Stock posts only after final receipt approval</strong>
            <p>Approval locks the purchase and links catalog items. Storage quantity changes only when the approver confirms the received quantities.</p>
        </div>

        <section class="purchase-form-section">
            <div class="section-heading-row">
                <div>
                    <p class="eyebrow">Supplier</p>
                    <h3>Who You Are Buying From</h3>
                </div>
            </div>

            <input type="hidden" name="supplier_id" value="<?= e($selectedSupplierId) ?>" data-purchase-supplier-id>

            <div class="purchase-picker purchase-supplier-picker" data-purchase-supplier-picker>
                <button class="purchase-picker-toggle" type="button" data-purchase-supplier-toggle aria-expanded="false">
                    <span>
                        <strong data-purchase-supplier-label><?= e($selectedSupplierName) ?></strong>
                        <small>Search by name, phone, VAT, CR, address, or authorized person</small>
                    </span>
                    <span class="purchase-picker-caret" aria-hidden="true">v</span>
                </button>
                <div class="purchase-picker-panel" data-purchase-supplier-panel hidden>
                    <label class="purchase-picker-search">
                        <?= ui_icon('search') ?>
                        <input type="search" data-purchase-supplier-search placeholder="Search suppliers">
                    </label>
                    <div class="purchase-picker-options" data-purchase-supplier-options></div>
                </div>
            </div>

            <div class="purchase-selected-summary" data-purchase-supplier-summary hidden></div>

            <div class="purchase-new-supplier" data-new-supplier-fields>
                <div class="field-row">
                    <label class="field">
                        <span>New Supplier Name</span>
                        <input type="text" name="supplier_name" value="<?= e((string) ($purchase['supplier_name'] ?? '')) ?>" placeholder="Supplier not listed" data-new-supplier-input>
                    </label>
                    <label class="field">
                        <span>Supplier Type</span>
                        <select name="supplier_type" data-new-supplier-input data-supplier-type-select>
                            <?php foreach (supplier_type_options() as $type => $label): ?>
                                <option value="<?= e($type) ?>" <?= selected($type, (string) ($purchase['supplier_type'] ?? 'product')) ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="tiny-copy">Required when creating a new supplier.</small>
                    </label>
                </div>

                <label class="field" data-supplier-type-other-field hidden>
                    <span>Custom supplier type</span>
                    <input type="text" name="supplier_type_other" value="<?= e((string) ($purchase['supplier_type_other'] ?? '')) ?>" placeholder="Example: Maintenance, contractor, logistics" data-new-supplier-input data-supplier-type-other-input>
                    <small class="tiny-copy">Required only when supplier type is Other.</small>
                </label>

                <div class="field-row">
                    <label class="field">
                        <span>Supplier Phone</span>
                        <input type="text" name="supplier_phone" value="<?= e((string) ($purchase['supplier_phone'] ?? '')) ?>" data-new-supplier-input>
                        <small class="tiny-copy">Required when creating a new supplier.</small>
                    </label>
                    <label class="field">
                        <span>Supplier Email</span>
                        <input type="email" name="supplier_email" value="<?= e((string) ($purchase['supplier_email'] ?? '')) ?>" data-new-supplier-input>
                    </label>
                </div>

                <div class="field-row">
                    <label class="field">
                        <span>Authorized Person / اسم المفوض</span>
                        <input type="text" name="supplier_authorized_person" value="<?= e((string) ($purchase['supplier_authorized_person'] ?? '')) ?>" data-new-supplier-input>
                        <small class="tiny-copy">Required when creating a new supplier.</small>
                    </label>
                    <label class="field">
                        <span>National Address / العنوان الوطني</span>
                        <input type="text" name="supplier_national_address" value="<?= e((string) ($purchase['supplier_national_address'] ?? '')) ?>" data-new-supplier-input>
                        <small class="tiny-copy">Required when creating a new supplier.</small>
                    </label>
                </div>

                <div class="field-row">
                    <label class="field">
                        <span>Commercial Registration (CR)</span>
                        <input type="text" name="supplier_commercial_registration" value="<?= e((string) ($purchase['supplier_commercial_registration'] ?? '')) ?>" placeholder="Optional" data-new-supplier-input>
                    </label>
                    <label class="field">
                        <span>VAT / Tax Number</span>
                        <input type="text" name="supplier_tax_number" value="<?= e((string) ($purchase['supplier_tax_number'] ?? '')) ?>" data-new-supplier-input>
                    </label>
                </div>

                <label class="field">
                    <span>Supplier Notes</span>
                    <textarea name="supplier_notes" rows="2" placeholder="Optional supplier notes" data-new-supplier-input><?= e((string) ($purchase['supplier_notes'] ?? '')) ?></textarea>
                </label>
            </div>
        </section>

        <div class="field-row">
            <label class="field">
                <span>Destination Storage</span>
                <select name="destination_storage_id" required>
                    <option value="">Select storage</option>
                    <?php foreach ($storages as $storage): ?>
                        <option value="<?= e((string) $storage['id']) ?>" <?= selected((string) $storage['id'], (string) ($purchase['destination_storage_id'] ?? '')) ?>>
                            <?= e(storage_type_label((string) $storage['storage_type'])) ?> · <?= e((string) $storage['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="field">
                <span>Approver</span>
                <select name="approver_user_id" required>
                    <option value="">Select approver</option>
                    <?php foreach ($approvers as $approver): ?>
                        <option value="<?= e((string) $approver['id']) ?>" <?= selected((string) $approver['id'], (string) ($purchase['approver_user_id'] ?? '')) ?>>
                            <?= e($approver['name']) ?> · <?= e(user_role_label((string) $approver['role'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>

        <div class="field-row">
            <label class="field">
                <span>Expected Date</span>
                <input type="date" name="expected_date" value="<?= e((string) ($purchase['expected_date'] ?? '')) ?>">
            </label>
            <label class="field">
                <span>Currency</span>
                <input type="text" name="currency" value="<?= e((string) ($purchase['currency'] ?? 'SAR')) ?>" maxlength="8" required>
            </label>
            <label class="field">
                <span>Document Type</span>
                <select name="document_type">
                    <?php foreach ($documentTypes as $type => $label): ?>
                        <option value="<?= e($type) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>

        <label class="field">
            <span>Purchase Documents</span>
            <input type="file" name="documents[]" multiple accept="application/pdf,image/jpeg,image/png,image/webp" data-purchase-ocr-files>
            <small class="tiny-copy">PDF, JPG, PNG, or WebP. Max 15 MB per file. Required before submit for approval.</small>
        </label>

        <div class="copy-context-card purchase-ocr-card" data-purchase-ocr-panel>
            <div>
                <strong>Extract quote / receipt details</strong>
                <p>Upload an Arabic or English supplier quote, price list, receipt, or scanned PDF. Server AI OCR reads old scans when configured, then you review before submitting.</p>
            </div>
            <div class="button-row">
                <button class="ghost-button" type="button" data-purchase-ocr-button><?= ui_icon('document') ?><span>Extract From File</span></button>
            </div>
            <div class="purchase-ocr-status tiny-copy" data-purchase-ocr-status>OCR supports Arabic + English PDF, JPG, PNG, and WebP. If server AI OCR is unavailable, browser OCR/manual review remains available.</div>
            <div class="ocr-confidence-panel" data-purchase-ocr-review hidden></div>
            <details class="purchase-ocr-text" data-purchase-ocr-text-wrap hidden>
                <summary>Extracted text preview</summary>
                <pre data-purchase-ocr-text></pre>
            </details>
        </div>

        <?php if (!empty($documents)): ?>
            <div class="copy-context-card">
                <strong>Attached files</strong>
                <p><?= count($documents) ?> protected file(s) are already attached to this draft.</p>
            </div>
        <?php endif; ?>

        <label class="field">
            <span>Notes</span>
            <textarea name="notes" rows="3" placeholder="Internal notes for approver or receiver"><?= e((string) ($purchase['notes'] ?? '')) ?></textarea>
        </label>

        <section class="workflow-line-builder purchase-line-builder">
            <div class="panel-head">
                <div>
                    <p class="eyebrow">Line Items</p>
                    <h3>What You Are Buying</h3>
                </div>
                <button class="ghost-button" type="button" data-add-purchase-line><?= ui_icon('plus') ?><span>Add Item</span></button>
            </div>

            <div class="purchase-line-list" data-purchase-line-body>
                <?php foreach ($lineRows as $index => $line): ?>
                    <?php
                    $unitState = item_unit_form_state((string) ($line['unit'] ?? 'pcs'));
                    $lineItemId = (string) ($line['item_id'] ?? '');
                    $selectedItem = null;
                    foreach ($items as $catalogItem) {
                        if ((string) $catalogItem['id'] === $lineItemId) {
                            $selectedItem = $catalogItem;
                            break;
                        }
                    }
                    $lineName = trim((string) ($line['item_name'] ?? ''));
                    if ($lineName === '') {
                        $lineName = (string) ($selectedItem['name'] ?? 'Quick-create new item');
                    }
                    $lineSku = trim((string) ($line['item_sku'] ?? ''));
                    if ($lineSku === '') {
                        $lineSku = (string) ($selectedItem['sku'] ?? '');
                    }
                    $lineImageUrl = $selectedItem['image_url'] ?? item_image_url($line['item_image_path'] ?? null);
                    ?>
                    <article class="purchase-line-card" data-purchase-line>
                        <input type="hidden" name="line_existing_image_path[]" value="<?= e((string) ($line['item_image_path'] ?? '')) ?>">
                        <input type="hidden" name="line_item_id[]" value="<?= e($lineItemId) ?>" data-purchase-item-id>

                        <div class="purchase-line-card-head">
                            <div class="purchase-line-title">
                                <span class="purchase-line-number" data-purchase-line-index><?= (int) $index + 1 ?></span>
                                <span class="purchase-line-thumb" data-purchase-item-thumb>
                                    <?php if ($lineImageUrl): ?>
                                        <img src="<?= e((string) $lineImageUrl) ?>" alt="<?= e($lineName) ?>">
                                    <?php else: ?>
                                        <?= ui_icon('items') ?>
                                    <?php endif; ?>
                                </span>
                                <span>
                                    <strong data-purchase-item-name-preview><?= e($lineName) ?></strong>
                                    <small data-purchase-item-preview><?= e($lineSku !== '' ? $lineSku : 'Select existing item or quick-create a new one') ?></small>
                                </span>
                            </div>
                            <div class="purchase-line-actions">
                                <span class="purchase-line-total" data-purchase-line-total>SAR 0.00</span>
                                <button class="text-button danger-link" type="button" data-remove-purchase-line>Remove</button>
                            </div>
                        </div>

                        <div class="purchase-line-core">
                            <div class="purchase-picker purchase-item-picker" data-purchase-item-picker>
                                <button class="purchase-picker-toggle" type="button" data-purchase-item-toggle aria-expanded="false">
                                    <span>
                                        <strong data-purchase-item-label><?= e($lineItemId !== '' ? $lineName : 'Quick-create new item') ?></strong>
                                        <small>Search by name, SKU, barcode, category, or unit</small>
                                    </span>
                                    <span class="purchase-picker-caret" aria-hidden="true">v</span>
                                </button>
                                <div class="purchase-picker-panel" data-purchase-item-panel hidden>
                                    <label class="purchase-picker-search">
                                        <?= ui_icon('search') ?>
                                        <input type="search" data-purchase-item-search placeholder="Search items">
                                    </label>
                                    <div class="purchase-picker-options" data-purchase-item-options></div>
                                </div>
                            </div>

                            <label class="field">
                                <span>Requested Qty</span>
                                <input type="number" step="0.01" min="0.01" name="line_quantity_requested[]" value="<?= e((string) ($line['quantity_requested'] ?? '')) ?>" required data-purchase-quantity>
                            </label>
                            <label class="field">
                                <span>Quoted Unit Price</span>
                                <input type="number" step="0.01" min="0" name="line_unit_cost_quoted[]" value="<?= e((string) ($line['unit_cost_quoted'] ?? '')) ?>" required data-purchase-unit-cost>
                            </label>
                        </div>

                        <details class="purchase-line-details" data-purchase-line-details <?= $lineItemId === '' ? 'open' : '' ?>>
                            <summary>Edit snapshot details</summary>
                            <div class="purchase-line-detail-grid">
                                <label class="field">
                                    <span>Item Name</span>
                                    <input type="text" name="line_item_name[]" value="<?= e((string) ($line['item_name'] ?? '')) ?>" placeholder="Item name">
                                </label>
                                <label class="field">
                                    <span>SKU</span>
                                    <input type="text" name="line_item_sku[]" value="<?= e((string) ($line['item_sku'] ?? '')) ?>" placeholder="SKU">
                                </label>
                                <label class="field">
                                    <span>Barcode</span>
                                    <input
                                        type="text"
                                        name="line_item_barcode[]"
                                        value="<?= e((string) ($line['item_barcode'] ?? '')) ?>"
                                        placeholder="<?= $barcodeRequired ? 'Barcode required for new items' : 'Barcode optional' ?>"
                                        autocomplete="off"
                                        inputmode="text"
                                    >
                                </label>
                                <label class="field">
                                    <span>Category</span>
                                    <input type="text" name="line_item_category[]" value="<?= e((string) ($line['item_category'] ?? '')) ?>" placeholder="Category">
                                </label>
                                <div class="field-row inline-field-row">
                                    <label class="field">
                                        <span>Unit</span>
                                        <select name="line_unit[]">
                                            <?php foreach ($unitOptions as $unit => $label): ?>
                                                <option value="<?= e($unit) ?>" <?= selected($unit, $unitState['unit']) ?>><?= e($label) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <label class="field">
                                        <span>Custom Unit</span>
                                        <input type="text" name="line_custom_unit[]" value="<?= e((string) $unitState['custom_unit']) ?>" placeholder="Custom unit">
                                    </label>
                                </div>
                                <label class="field">
                                    <span>Item Image</span>
                                    <input type="file" name="line_image[]" accept="image/jpeg,image/png,image/webp">
                                    <small class="tiny-copy">Used for quick-created items.</small>
                                </label>
                                <label class="field purchase-line-notes">
                                    <span>Item Notes</span>
                                    <textarea name="line_item_notes[]" rows="2" placeholder="Item notes"><?= e((string) ($line['item_notes'] ?? '')) ?></textarea>
                                </label>
                            </div>
                        </details>
                    </article>
                <?php endforeach; ?>
            </div>

            <div class="purchase-total-card">
                <span>Purchase Total</span>
                <strong data-purchase-total>SAR 0.00</strong>
            </div>
        </section>

        <div class="button-row">
            <button class="ghost-button" type="submit" name="purchase_action" value="save">Save Draft</button>
            <button class="primary-button" type="submit" name="purchase_action" value="submit">Submit For Approval</button>
        </div>
    </form>
</section>
