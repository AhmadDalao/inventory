<?php
$isEdit = $mode === 'edit';
$action = $isEdit ? url('/company-assets/' . $asset['id'] . '/edit') : url('/company-assets/create');
$imageUrl = asset_image_url($asset['image_path'] ?? null);
$scanCode = asset_scan_code($asset);
?>

<section class="page-head">
    <div class="page-head-copy">
        <p class="eyebrow"><?= $isEdit ? 'Maintain' : 'Create' ?></p>
        <h3 class="page-head-title"><?= ui_icon('assets') ?><span><?= $isEdit ? 'Edit Asset' : 'New Asset' ?></span></h3>
    </div>
    <div class="page-actions">
        <a class="ghost-button" href="<?= e($isEdit ? url('/company-assets/' . $asset['id']) : url('/company-assets')) ?>"><?= ui_icon('back') ?><span>Back</span></a>
    </div>
</section>

<section class="panel form-panel">
    <form class="stack-form item-form" method="post" action="<?= e($action) ?>" enctype="multipart/form-data">
        <?= csrf_field() ?>

        <div class="item-form-section">
            <div class="item-form-section-head">
                <div>
                    <p class="eyebrow">Core Details</p>
                    <h4>Asset Identity</h4>
                </div>
                <span class="item-form-section-note">Assets are individual records. Inventory quantities are not affected.</span>
            </div>

            <div class="item-form-grid item-form-grid-primary">
                <label class="field">
                    <span>Name</span>
                    <input type="text" name="name" value="<?= e((string) $asset['name']) ?>" placeholder="Radio, laptop, printer" required>
                </label>

                <label class="field">
                    <span>Category / subcategory</span>
                    <select name="category_id" data-searchable-select data-searchable-placeholder="Search category, subcategory, or code">
                        <option value="">No managed category</option>
                        <?php foreach ($categories as $category): ?>
                            <option
                                value="<?= e((string) $category['id']) ?>"
                                data-search-text="<?= e(($category['path_label'] ?? $category['name']) . ' ' . ($category['code'] ?? '')) ?>"
                                <?= selected((string) $category['id'], (string) ($asset['category_id'] ?? '')) ?>
                            >
                                <?= e((string) ($category['path_label'] ?? $category['name'])) ?><?= $category['code'] ? ' - ' . e((string) $category['code']) : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (can_manage_asset_categories()): ?>
                        <small class="item-form-help"><a class="text-link" href="<?= e(url('/company-assets/categories')) ?>">Manage category hierarchy</a></small>
                    <?php endif; ?>
                </label>

                <label class="field">
                    <span>Fallback category label</span>
                    <input type="text" name="category" value="<?= e((string) $asset['category']) ?>" placeholder="Only used if no managed category is selected">
                </label>

                <label class="field">
                    <span>Model</span>
                    <input type="text" name="model" value="<?= e((string) $asset['model']) ?>" placeholder="Model name or number">
                </label>

                <label class="field">
                    <span>Serial number</span>
                    <input type="text" name="serial_number" value="<?= e((string) $asset['serial_number']) ?>" placeholder="Factory serial">
                </label>

                <label class="field">
                    <span>Barcode / asset tag</span>
                    <input type="text" name="barcode" value="<?= e((string) $asset['barcode']) ?>" placeholder="Scan or type asset tag" autocomplete="off">
                    <small class="item-form-help">Blank labels use the generated asset number.</small>
                </label>

                <?php if (!$isEdit): ?>
                    <label class="field">
                        <span>Bulk quantity</span>
                        <input type="number" name="bulk_quantity" value="<?= e((string) ($asset['bulk_quantity'] ?? 1)) ?>" min="1" max="100" step="1">
                        <small class="item-form-help">Buying 10 radios creates 10 individual assets.</small>
                    </label>
                <?php endif; ?>
            </div>

            <?php if ($isEdit): ?>
                <div class="item-form-barcode-preview">
                    <div>
                        <p class="eyebrow">Scan Reference</p>
                        <strong><?= e($scanCode) ?></strong>
                        <p class="tiny-copy">QR/barcode should store this value only. The app opens it through search or /open.</p>
                    </div>
                    <div class="barcode-box item-form-barcode-box">
                        <?= code39_svg($scanCode, 48) ?>
                        <code><?= e($scanCode) ?></code>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="item-form-section item-form-media-card">
            <div class="item-form-section-head">
                <div>
                    <p class="eyebrow">Photo</p>
                    <h4>Asset Image</h4>
                </div>
            </div>

            <label class="field item-image-field item-form-image-row">
                <span class="sr-only">Asset Image</span>
                <?php if ($imageUrl): ?>
                    <span class="item-form-preview-wrap">
                        <img class="item-form-preview expandable-image" src="<?= e($imageUrl) ?>" alt="<?= e((string) $asset['name']) ?>" data-expand-image tabindex="0">
                    </span>
                <?php else: ?>
                    <span class="item-form-image-empty">No image yet</span>
                <?php endif; ?>
                <span class="item-form-upload-stack">
                    <input type="file" name="image" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                    <small class="item-form-help">Shown on asset cards, details, files, and Excel exports.</small>
                </span>
            </label>
        </div>

	        <div class="item-form-section">
	            <div class="item-form-section-head">
	                <div>
	                    <p class="eyebrow">Custody</p>
	                    <h4>Location And Holder</h4>
	                </div>
	            </div>

	            <?php if ($isEdit): ?>
	                <input type="hidden" name="storage_id" value="<?= e((string) ($asset['storage_id'] ?? '')) ?>">
	                <input type="hidden" name="assigned_user_id" value="<?= e((string) ($asset['assigned_user_id'] ?? '')) ?>">
	                <div class="info-card soft-card">
	                    <div>
	                        <span class="tiny-copy">Current location</span>
	                        <strong><?= e($asset['storage_name'] ?? 'No storage selected') ?></strong>
	                    </div>
	                    <div>
	                        <span class="tiny-copy">Current holder</span>
	                        <strong><?= e($asset['assigned_user_name'] ?? 'Not assigned') ?></strong>
	                    </div>
	                    <p class="tiny-copy">Use the asset detail action panel for assignment, receipt confirmation, return, and transfer so custody history stays complete.</p>
	                </div>
	                <div class="item-form-grid">
	                    <label class="field">
	                        <span>Condition</span>
	                        <select name="condition_status">
	                            <?php foreach (asset_condition_options() as $value => $label): ?>
	                                <option value="<?= e($value) ?>" <?= selected($value, (string) $asset['condition_status']) ?>><?= e($label) ?></option>
	                            <?php endforeach; ?>
	                        </select>
	                    </label>
	                </div>
	            <?php else: ?>
	                <div class="item-form-grid">
	                    <label class="field">
	                        <span>Storage / location</span>
	                        <select name="storage_id">
	                            <option value="">No storage selected</option>
	                            <?php foreach ($storages as $storage): ?>
	                                <option value="<?= e((string) $storage['id']) ?>">
	                                    <?= e(storage_type_label($storage['storage_type'])) ?> - <?= e($storage['name']) ?>
	                                </option>
	                            <?php endforeach; ?>
	                        </select>
	                    </label>

	                    <label class="field">
	                        <span>Assigned user</span>
	                        <select name="assigned_user_id">
	                            <option value="">Not assigned</option>
	                            <?php foreach ($users as $user): ?>
	                                <option value="<?= e((string) $user['id']) ?>">
	                                    <?= e($user['name']) ?> - <?= e(user_role_label((string) $user['role'])) ?>
	                                </option>
	                            <?php endforeach; ?>
	                        </select>
	                        <small class="item-form-help">New assigned assets wait for recipient confirmation.</small>
	                    </label>

	                    <label class="field">
	                        <span>Condition</span>
	                        <select name="condition_status">
	                            <?php foreach (asset_condition_options() as $value => $label): ?>
	                                <option value="<?= e($value) ?>" <?= selected($value, (string) $asset['condition_status']) ?>><?= e($label) ?></option>
	                            <?php endforeach; ?>
	                        </select>
	                    </label>
	                </div>
	            <?php endif; ?>
	        </div>

        <div class="item-form-section">
            <div class="item-form-section-head">
                <div>
                    <p class="eyebrow">Purchase</p>
                    <h4>Supplier, Cost And Warranty</h4>
                </div>
            </div>

            <div class="item-form-grid">
                <label class="field">
                    <span>Supplier</span>
                    <select name="supplier_id">
                        <option value="">No supplier linked</option>
                        <?php foreach ($suppliers as $supplier): ?>
                            <option value="<?= e((string) $supplier['id']) ?>" <?= selected((string) $supplier['id'], (string) ($asset['supplier_id'] ?? '')) ?>>
                                <?= e($supplier['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="field">
                    <span>Purchase</span>
                    <select name="purchase_id">
                        <option value="">No purchase linked</option>
                        <?php foreach ($purchases as $purchase): ?>
                            <option value="<?= e((string) $purchase['id']) ?>" <?= selected((string) $purchase['id'], (string) ($asset['purchase_id'] ?? '')) ?>>
                                <?= e($purchase['purchase_number']) ?> - <?= e(purchase_status_label((string) $purchase['status'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="field">
                    <span>Purchase date</span>
                    <input type="date" name="purchase_date" value="<?= e((string) ($asset['purchase_date'] ?? '')) ?>">
                </label>

                <label class="field">
                    <span>Purchase cost</span>
                    <input type="number" min="0" step="0.01" name="purchase_cost" value="<?= e((string) $asset['purchase_cost']) ?>">
                </label>

                <label class="field">
                    <span>Warranty expiry</span>
                    <input type="date" name="warranty_expires_at" value="<?= e((string) ($asset['warranty_expires_at'] ?? '')) ?>">
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
                <textarea name="notes" rows="4" placeholder="Internal notes, warranty details, accessories, or custody notes"><?= e((string) $asset['notes']) ?></textarea>
            </label>
        </div>

        <div class="item-form-actions">
            <button class="primary-button" type="submit"><?= $isEdit ? 'Save Changes' : 'Create Asset' ?></button>
            <a class="ghost-button" href="<?= e($isEdit ? url('/company-assets/' . $asset['id']) : url('/company-assets')) ?>">Cancel</a>
        </div>
    </form>
</section>
