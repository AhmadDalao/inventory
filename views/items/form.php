<?php
$isEdit = $mode === 'edit';
$copySource = $copySource ?? null;
$isCopy = !$isEdit && $copySource !== null;
$action = $isEdit ? url('/items/' . $item['id'] . '/edit') : url('/items/create');
$unitOptions = item_unit_options();
$imageUrl = item_image_url($item['image_path'] ?? null);
$barcodeRequired = item_barcodes_required();
$barcodeValue = trim((string) ($item['barcode'] ?? ''));
$skuValue = trim((string) ($item['sku'] ?? ''));
$initialScanCode = code39_normalize($barcodeValue !== '' ? $barcodeValue : $skuValue);
$initialScanSource = $barcodeValue !== '' ? 'Barcode preview' : 'SKU fallback preview';
?>

<section class="page-head">
    <div>
        <p class="eyebrow"><?= $isEdit ? 'Maintain' : ($isCopy ? 'Copy' : 'Create') ?></p>
        <h3><?= $isEdit ? 'Edit Item' : ($isCopy ? 'Copy Item Setup' : 'New Item') ?></h3>
    </div>
    <div class="page-actions">
        <a class="ghost-button" href="<?= e($isEdit ? url('/items/' . $item['id']) : url('/items')) ?>">Back</a>
    </div>
</section>

<section class="panel form-panel">
    <?php if ($isCopy): ?>
        <div class="copy-context-card">
            <strong>Copied from <?= e($copySource['name']) ?> (<?= e($copySource['sku']) ?>)</strong>
            <p>Keep the same SKU if you just want this item stocked in another location. Change the SKU only if this should become a separate catalog item.</p>
        </div>
    <?php endif; ?>

    <form class="stack-form item-form" method="post" action="<?= e($action) ?>" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <?php if (!$isEdit && !empty($item['copy_item_id'])): ?>
            <input type="hidden" name="copy_item_id" value="<?= e((string) $item['copy_item_id']) ?>">
        <?php endif; ?>

        <div class="item-form-shell">
            <div class="item-form-main">
                <div class="item-form-section">
                    <div class="item-form-section-head">
                        <div>
                            <p class="eyebrow">Core Details</p>
                            <h4>Item Identity</h4>
                        </div>
                        <span class="item-form-section-note">SKU and barcode stay optional where settings allow it.</span>
                    </div>

                    <div class="item-form-grid item-form-grid-primary">
                        <label class="field">
                            <span>Name</span>
                            <input type="text" name="name" value="<?= e((string) $item['name']) ?>" required>
                        </label>

                        <label class="field">
                            <span>SKU</span>
                            <input type="text" name="sku" value="<?= e((string) $item['sku']) ?>" required>
                            <small class="item-form-help">Master item code, for example CABLE-USB-C-01.</small>
                        </label>

                        <label class="field">
                            <span>Barcode<?= $barcodeRequired ? '' : ' (optional)' ?></span>
                            <input
                                type="text"
                                name="barcode"
                                value="<?= e((string) ($item['barcode'] ?? '')) ?>"
                                placeholder="Click and scan barcode"
                                autocomplete="off"
                                inputmode="text"
                                <?= $barcodeRequired ? 'required' : '' ?>
                            >
                            <small class="item-form-help"><?= $barcodeRequired ? 'Required by Website Control settings.' : 'Blank labels use SKU as the scan code.' ?></small>
                        </label>
                    </div>

                    <div class="item-form-barcode-preview" data-item-code-preview>
                        <div>
                            <p class="eyebrow" data-item-code-source><?= e($initialScanSource) ?></p>
                            <strong data-item-code-value><?= e($initialScanCode) ?></strong>
                            <p class="tiny-copy">This is what labels and scanners use. Barcode wins; SKU is used when barcode is blank.</p>
                        </div>
                        <div class="barcode-box item-form-barcode-box" data-item-code-svg>
                            <?= code39_svg($initialScanCode, 48) ?>
                            <code><?= e($initialScanCode) ?></code>
                        </div>
                    </div>
                </div>

                <div class="item-form-section item-form-media-card">
                    <div class="item-form-section-head">
                        <div>
                            <p class="eyebrow">Photo</p>
                            <h4>Item Image</h4>
                        </div>
                    </div>

                    <label class="field item-image-field item-form-image-row">
                        <span class="sr-only">Item Image</span>
                        <?php if ($imageUrl): ?>
                            <span class="item-form-preview-wrap">
                                <img
                                    class="item-form-preview expandable-image"
                                    src="<?= e($imageUrl) ?>"
                                    alt="<?= e((string) $item['name']) ?>"
                                    data-expand-image
                                    tabindex="0"
                                >
                            </span>
                        <?php else: ?>
                            <span class="item-form-image-empty">No image yet</span>
                        <?php endif; ?>
                        <span class="item-form-upload-stack">
                            <input type="file" name="image" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                            <small class="item-form-help">Shown on item cards, tables, details, exports, and handover documents.</small>
                        </span>
                    </label>
                </div>

                <?php if (!$isEdit): ?>
                    <label class="choice-field item-form-choice">
                        <input type="checkbox" name="use_existing_item" value="1" <?= checked((string) ($item['use_existing_item'] ?? '1') === '1') ?>>
                        <div>
                            <strong>If this SKU already exists, add stock to that item instead of creating a duplicate record.</strong>
                            <span>Same SKU, same item, many storage balances. Use quantity 0 to assign the location without adding stock.</span>
                        </div>
                    </label>
                <?php endif; ?>

                <div class="item-form-section">
                    <div class="item-form-section-head">
                        <div>
                            <p class="eyebrow">Classification</p>
                            <h4>Location And Unit</h4>
                        </div>
                    </div>

                    <div class="item-form-grid">
                        <label class="field">
                            <span>Category</span>
                            <input type="text" name="category" value="<?= e((string) $item['category']) ?>" placeholder="Cleaning, Office, Electrical">
                        </label>

                        <label class="field item-form-wide-field">
                            <span>Default Location</span>
                            <select name="storage_id">
                                <option value="">No default location</option>
                                <?php foreach ($storages as $storage): ?>
                                    <option value="<?= e((string) $storage['id']) ?>" <?= selected((string) $storage['id'], (string) ($item['storage_id'] ?? '')) ?>>
                                        <?= e(storage_type_label($storage['storage_type'])) ?> · <?= e($storage['name']) ?><?= (int) $storage['is_active'] === 0 ? ' (Deleted)' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="item-form-help"><?= $isEdit ? 'Default pick location only. It does not move stock.' : 'Initial stock lands here first.' ?></small>
                            <?php if ($storages === []): ?>
                                <small class="item-form-help">No active locations exist yet.</small>
                                <a class="text-link" href="<?= e(url('/storages/create')) ?>">Create Location</a>
                            <?php endif; ?>
                        </label>

                        <label class="field">
                            <span>Unit</span>
                            <select name="unit" data-unit-select>
                                <?php foreach ($unitOptions as $value => $label): ?>
                                    <option value="<?= e($value) ?>" <?= selected($value, $item['unit']) ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input
                                type="text"
                                name="custom_unit"
                                value="<?= e((string) ($item['custom_unit'] ?? '')) ?>"
                                placeholder="Enter custom unit"
                                data-custom-unit
                                <?= ($item['unit'] ?? 'pcs') === 'custom' ? '' : 'hidden' ?>
                            >
                            <small class="item-form-help">Default is pcs.</small>
                        </label>
                    </div>
                </div>

                <div class="item-form-section">
                    <div class="item-form-section-head">
                        <div>
                            <p class="eyebrow">Stock Rules</p>
                            <h4>Quantity And Cost</h4>
                        </div>
                    </div>

                    <div class="item-form-grid item-form-grid-compact">
                        <?php if ($isEdit): ?>
                            <label class="field">
                                <span>Current Quantity</span>
                                <input type="text" value="<?= e((string) $item['current_quantity']) ?> <?= e((string) $item['unit']) ?>" disabled>
                                <small class="item-form-help">Adjust stock from the item page to keep history honest.</small>
                            </label>
                        <?php else: ?>
                            <label class="field">
                                <span>Initial Quantity</span>
                                <input type="number" min="0" step="0.01" name="current_quantity" value="<?= e((string) $item['current_quantity']) ?>" required>
                                <small class="item-form-help">Use 0 to assign location without adding stock.</small>
                            </label>
                        <?php endif; ?>

                        <label class="field">
                            <span>Reorder Level</span>
                            <input type="number" min="0" step="0.01" name="reorder_level" value="<?= e((string) $item['reorder_level']) ?>" required>
                        </label>

                        <label class="field">
                            <span>Cost Per Unit</span>
                            <input type="number" min="0" step="0.01" name="cost_per_unit" value="<?= e((string) $item['cost_per_unit']) ?>" required>
                        </label>
                    </div>
                </div>

                <div class="item-form-section">
                    <div class="item-form-section-head">
                        <div>
                            <p class="eyebrow">Context</p>
                            <h4>Notes</h4>
                        </div>
                    </div>
                    <label class="field">
                        <span class="sr-only">Notes</span>
                        <textarea name="notes" rows="4" placeholder="Internal notes, model details, supplier hints, or anything the team should know"><?= e((string) $item['notes']) ?></textarea>
                    </label>
                </div>
            </div>
        </div>

        <div class="item-form-actions">
            <button class="primary-button" type="submit"><?= $isEdit ? 'Save Changes' : 'Create Item' ?></button>
            <a class="ghost-button" href="<?= e($isEdit ? url('/items/' . $item['id']) : url('/items')) ?>">Cancel</a>
        </div>
    </form>
</section>
