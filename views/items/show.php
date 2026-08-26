<?php
$imageUrl = item_image_url($item['image_path'] ?? null);
$stockValue = stock_value($item['current_quantity'], $item['cost_per_unit']);
$defaultLocationLabel = !empty($item['default_storage_name'])
    ? storage_type_label((string) ($item['default_storage_type'] ?? 'storage')) . ': ' . $item['default_storage_name']
    : 'No default location';
$balanceMapJson = json_encode(item_balance_map($balances), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$itemScanCode = item_scan_code($item);
$itemHasBarcode = normalize_item_barcode($item['barcode'] ?? '') !== '';
$itemScanSourceLabel = $itemHasBarcode ? 'Item barcode' : 'SKU label';
$packagePresets = $packagePresets ?? [];
$activePackagePresets = array_values(array_filter(
    $packagePresets,
    static fn (array $preset): bool => (int) ($preset['is_active'] ?? 1) === 1
));
$usageReasons = $usageReasons ?? mobile_usage_reason_catalog(true);
$usageReasonCatalogs = $usageReasonCatalogs ?? usage_reason_catalogs(true);
$departmentOptions = $departmentOptions ?? [];
$canonicalUnit = item_canonical_unit($item);
$measurementDimension = normalize_inventory_measurement_dimension($item['measurement_dimension'] ?? 'count');
$usageProofRequired = inventory_operation_requires_proof([$item], 'usage');
$refillProofRequired = inventory_operation_requires_proof([$item], 'refill');
$stockPositions = $stockPositions ?? item_stock_positions($balances);
$isStorageScoped = $isStorageScoped ?? false;
?>

<section class="page-head">
    <div>
        <p class="eyebrow">Item Detail</p>
        <h3><?= e($item['name']) ?></h3>
    </div>
    <div class="page-actions">
        <a class="ghost-button" href="<?= e(url('/items')) ?>">All Items</a>
        <?php if (Auth::hasPermission('items.copy') && Auth::hasPermission('items.create')): ?>
            <a class="ghost-button" href="<?= e(url('/items/create?copy=' . $item['id'])) ?>"><?= ui_icon('copy_action') ?><span>Copy Item</span></a>
        <?php endif; ?>
        <?php if (Auth::hasPermission('items.edit')): ?>
            <a class="primary-button" href="<?= e(url('/items/' . $item['id'] . '/edit')) ?>">Edit Item</a>
        <?php endif; ?>
    </div>
</section>

<section class="detail-grid">
    <article
        class="panel detail-summary"
        data-item-summary
        data-unit="<?= e($item['unit']) ?>"
        data-current-quantity="<?= e((string) $item['current_quantity']) ?>"
        data-cost-per-unit="<?= e((string) $item['cost_per_unit']) ?>"
        data-balance-map="<?= e((string) $balanceMapJson) ?>"
    >
        <div class="detail-hero item-summary-hero">
            <div class="detail-hero-main">
                <?php if ($imageUrl): ?>
                    <img
                        class="item-hero-image expandable-image"
                        src="<?= e($imageUrl) ?>"
                        alt="<?= e($item['name']) ?>"
                        data-expand-image
                        tabindex="0"
                    >
                <?php else: ?>
                    <div class="item-hero-image item-hero-image-fallback"><?= e(item_initial($item['name'])) ?></div>
                <?php endif; ?>

                <div class="item-summary-copy">
                    <span class="pill <?= (int) $item['is_active'] === 1 ? 'pill-active' : 'pill-muted' ?>">
                        <?= (int) $item['is_active'] === 1 ? 'Active' : 'Deleted' ?>
                    </span>
                    <h4><?= e($item['sku']) ?></h4>
                    <p><?= e($item['category'] ?: 'No category set') ?> · <?= e($defaultLocationLabel) ?></p>
                    <p class="tiny-copy item-summary-meta">
                        Barcode: <?= normalize_item_barcode($item['barcode'] ?? '') !== '' ? e((string) $item['barcode']) : 'Not set' ?>
                        · <?= (int) ($item['location_count'] ?? 0) ?> location<?= (int) ($item['location_count'] ?? 0) === 1 ? '' : 's' ?>
                        <?php if (!empty($item['location_summary'])): ?>
                            · <?= e($item['location_summary']) ?>
                        <?php endif; ?>
                    </p>
                </div>
            </div>
            <div class="align-right item-summary-stock">
                <strong class="stock-number" data-stock-number><?= format_quantity($item['current_quantity']) ?></strong>
                <span data-stock-unit><?= e($item['unit']) ?> <?= $isStorageScoped ? 'in assigned storages' : 'on hand' ?></span>
                <span class="tiny-copy" data-stock-value-label><?= format_money($stockValue) ?> stock value</span>
            </div>
        </div>

        <div class="item-summary-stats item-stock-position-stats">
            <article class="item-summary-stat item-summary-stat-main">
                <span><?= $isStorageScoped ? 'Available In Assigned Storages' : 'Available In Active Storages' ?></span>
                <strong data-stock-position="available_active"><?= format_quantity($stockPositions['available_active']) ?> <?= e($item['unit']) ?></strong>
            </article>
            <?php if (!$isStorageScoped): ?>
                <article class="item-summary-stat">
                    <span>Held By Staff</span>
                    <strong data-stock-position="held_by_staff"><?= format_quantity($stockPositions['held_by_staff']) ?> <?= e($item['unit']) ?></strong>
                </article>
                <article class="item-summary-stat">
                    <span>Damaged / Quarantined</span>
                    <strong data-stock-position="damaged_quarantine"><?= format_quantity($stockPositions['damaged_quarantine']) ?> <?= e($item['unit']) ?></strong>
                </article>
                <article class="item-summary-stat">
                    <span>Total Physical Quantity</span>
                    <strong data-stock-position="total_physical"><?= format_quantity($stockPositions['total_physical']) ?> <?= e($item['unit']) ?></strong>
                </article>
            <?php endif; ?>
        </div>

        <div class="item-summary-stats">
            <article class="item-summary-stat item-summary-stat-main">
                <span>Reorder Level</span>
                <strong><?= format_quantity($item['reorder_level']) ?> <?= e($item['unit']) ?></strong>
            </article>
            <article class="item-summary-stat">
                <span>Total Used</span>
                <strong data-total-used><?= format_quantity($historyMetrics['total_used']) ?> <?= e($item['unit']) ?></strong>
            </article>
            <article class="item-summary-stat">
                <span>Total Added</span>
                <strong data-total-added><?= format_quantity($historyMetrics['total_added']) ?> <?= e($item['unit']) ?></strong>
            </article>
            <article class="item-summary-stat">
                <span>Total Transferred</span>
                <strong data-total-transferred><?= format_quantity($historyMetrics['total_transferred'] ?? 0) ?> <?= e($item['unit']) ?></strong>
            </article>
            <article class="item-summary-stat">
                <span>Movements</span>
                <strong data-movement-count><?= number_format((int) $historyMetrics['movement_count']) ?></strong>
            </article>
            <article class="item-summary-stat">
                <span>Stock Value</span>
                <strong data-stock-value-metric><?= format_money($stockValue) ?></strong>
            </article>
        </div>

        <section class="item-detail-barcode">
            <div>
                <span class="eyebrow"><?= e($itemScanSourceLabel) ?></span>
                <strong><?= e($itemScanCode) ?></strong>
                <p class="tiny-copy"><?= $itemHasBarcode ? 'This uses the saved item barcode.' : 'No barcode is saved yet, so labels scan the SKU.' ?></p>
            </div>
            <div class="barcode-box item-detail-barcode-box">
                <?= code39_svg($itemScanCode, 48) ?>
                <code><?= e($itemScanCode) ?></code>
            </div>
            <a class="ghost-button" href="<?= e(url('/labels?type=items&search=' . rawurlencode($itemScanCode))) ?>">
                <?= ui_icon('labels') ?><span>Print Label</span>
            </a>
        </section>

        <section class="item-package-presets">
            <div class="item-package-presets-head">
                <div>
                    <span class="eyebrow">Scan Packaging</span>
                    <h4>Package Presets</h4>
                    <p class="tiny-copy">Use these in Scan Center when one scan means a box, bag, pack, page bundle, or any container with many <?= e($item['unit']) ?>.</p>
                </div>
                <span class="pill pill-muted"><?= count($packagePresets) ?> preset<?= count($packagePresets) === 1 ? '' : 's' ?></span>
            </div>

            <?php if ($packagePresets !== []): ?>
                <div class="package-preset-grid">
                    <?php foreach ($packagePresets as $preset): ?>
                        <article class="package-preset-card <?= (int) $preset['is_default'] === 1 ? 'is-default' : '' ?> <?= (int) $preset['is_active'] !== 1 ? 'is-disabled' : '' ?>">
                            <div>
                                <strong><?= e($preset['label']) ?></strong>
                                <span><?= e($preset['pieces_per_unit']) ?> <?= e($item['unit']) ?> each</span>
                                <?php if ($preset['scan_code'] !== ''): ?><small>Scan: <?= e($preset['scan_code']) ?></small><?php endif; ?>
                            </div>
                            <?php if ((int) $preset['is_default'] === 1): ?>
                                <em>Default</em>
                            <?php elseif ((int) $preset['is_active'] !== 1): ?>
                                <em>Disabled</em>
                            <?php endif; ?>
                            <?php if (Auth::hasPermission('items.edit')): ?>
                                <div class="package-preset-actions">
                                    <?php if ((int) $preset['is_default'] !== 1): ?>
                                        <form method="post" action="<?= e(url('/items/' . $item['id'] . '/package-presets')) ?>">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="preset_id" value="<?= e((string) $preset['id']) ?>">
                                            <input type="hidden" name="label" value="<?= e($preset['label']) ?>">
                                            <input type="hidden" name="package_type" value="<?= e($preset['package_type']) ?>">
                                            <?php if ($preset['package_type'] === 'other'): ?><input type="hidden" name="custom_label" value="<?= e($preset['label']) ?>"><?php endif; ?>
                                            <input type="hidden" name="scan_code" value="<?= e($preset['scan_code']) ?>">
                                            <input type="hidden" name="pieces_per_unit" value="<?= e((string) $preset['pieces_per_unit_raw']) ?>">
                                            <input type="hidden" name="is_default" value="1">
                                            <input type="hidden" name="is_active" value="1">
                                            <button class="text-link" type="submit">Make default</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ((int) $preset['is_active'] === 1): ?>
                                        <form method="post" action="<?= e(url('/items/' . $item['id'] . '/package-presets/' . $preset['id'] . '/delete')) ?>">
                                            <?= csrf_field() ?>
                                            <button class="text-link danger-link" type="submit" data-confirm="Disable this package preset? Existing movement history will be preserved.">Disable</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ((int) $preset['is_active'] !== 1): ?>
                                        <form method="post" action="<?= e(url('/items/' . $item['id'] . '/package-presets')) ?>">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="preset_id" value="<?= e((string) $preset['id']) ?>">
                                            <input type="hidden" name="label" value="<?= e($preset['label']) ?>">
                                            <input type="hidden" name="package_type" value="<?= e($preset['package_type']) ?>">
                                            <?php if ($preset['package_type'] === 'other'): ?><input type="hidden" name="custom_label" value="<?= e($preset['label']) ?>"><?php endif; ?>
                                            <input type="hidden" name="scan_code" value="<?= e($preset['scan_code']) ?>">
                                            <input type="hidden" name="pieces_per_unit" value="<?= e((string) $preset['pieces_per_unit_raw']) ?>">
                                            <input type="hidden" name="is_active" value="1">
                                            <button class="text-link" type="submit">Enable</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="empty-state">No package presets yet. Scans will default to individual <?= e($item['unit']) ?>.</p>
            <?php endif; ?>

            <?php if (Auth::hasPermission('items.edit')): ?>
                <details class="package-preset-editor">
                    <summary>Add package preset</summary>
                    <form class="package-preset-form" method="post" action="<?= e(url('/items/' . $item['id'] . '/package-presets')) ?>" data-package-preset-form>
                        <?= csrf_field() ?>
                        <label class="field">
                            <span>Package type</span>
                            <select name="package_type" data-package-type required>
                                <?php foreach (item_package_type_options() as $type => $typeLabel): ?>
                                    <option value="<?= e($type) ?>"><?= e($typeLabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="field" data-custom-package-label hidden>
                            <span>Custom package label</span>
                            <input type="text" name="custom_label" maxlength="60" placeholder="Page bundle, drum, crate">
                        </label>
                        <label class="field">
                            <span data-package-contains-label>One Individual contains</span>
                            <input type="number" name="pieces_per_unit" step="0.000001" min="0.000001" placeholder="100" required>
                            <small><?= e($item['unit']) ?> per package.</small>
                        </label>
                        <label class="field">
                            <span>Package barcode (optional)</span>
                            <input type="text" name="scan_code" maxlength="120" placeholder="Scan or type package barcode">
                            <small>Scanning this code selects this exact conversion.</small>
                        </label>
                        <input type="hidden" name="is_active" value="1">
                        <label class="checkbox-card package-default-toggle">
                            <input type="checkbox" name="is_default" value="1">
                            <span>Use as default package in Scan Center</span>
                        </label>
                        <button class="primary-button" type="submit">Save Preset</button>
                    </form>
                </details>
            <?php endif; ?>
        </section>

        <details class="item-summary-more">
            <summary>More item details</summary>
            <dl class="detail-list item-summary-detail-list">
                <div>
                    <dt>Cost Per Unit</dt>
                    <dd><?= format_money($item['cost_per_unit']) ?></dd>
                </div>
                <div>
                    <dt>Barcode</dt>
                    <dd><?= normalize_item_barcode($item['barcode'] ?? '') !== '' ? e((string) $item['barcode']) : 'Not set' ?></dd>
                </div>
                <div>
                    <dt>Default Location</dt>
                    <dd><?= e($defaultLocationLabel) ?></dd>
                </div>
                <div>
                    <dt>Created By</dt>
                    <dd><?= e($item['creator_name'] ?: 'Unknown') ?></dd>
                </div>
                <div>
                    <dt>Updated By</dt>
                    <dd><?= e($item['updater_name'] ?: 'Unknown') ?></dd>
                </div>
                <div class="item-summary-notes">
                    <dt>Notes</dt>
                    <dd><?= nl2br(e($item['notes'] ?: 'No notes.')) ?></dd>
                </div>
            </dl>
        </details>

        <?php if (Auth::hasPermission('items.archive')): ?>
            <div class="item-summary-actions">
                <form method="post" action="<?= e(url('/items/' . $item['id'] . '/status')) ?>">
                    <?= csrf_field() ?>
                    <button class="ghost-button" type="submit" data-confirm="<?= (int) $item['is_active'] === 1 ? 'Archive this shared item? This affects every storage that still has it.' : 'Recover this item?' ?>">
                        <?= (int) $item['is_active'] === 1 ? 'Archive Item' : 'Recover Item' ?>
                    </button>
                </form>
                <?php if ((int) $item['is_active'] === 1): ?>
                    <p class="tiny-copy">Archive is global. Remove from one place in Location Balances.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </article>

    <article class="panel movement-panel">
        <div class="panel-head">
            <div>
                <p class="eyebrow">Track Stock</p>
                <h3>Log Movement</h3>
            </div>
        </div>

        <?php $movementTypeOptions = $movementTypeOptions ?? movement_type_options_for_user(); ?>
        <?php if ((int) $item['is_active'] === 0): ?>
            <p class="empty-state">This item is deleted. Recover it if you want new movement entries.</p>
        <?php elseif ($movementTypeOptions === []): ?>
            <p class="empty-state">You can view history here, but you do not have permission to create new movement logs.</p>
        <?php else: ?>
            <form
                class="stack-form movement-form"
                method="post"
                enctype="multipart/form-data"
                action="<?= e(url('/items/' . $item['id'] . '/movements')) ?>"
                data-movement-form
                data-base-unit="<?= e($canonicalUnit) ?>"
                data-measurement-dimension="<?= e($measurementDimension) ?>"
                data-usage-proof-required="<?= $usageProofRequired ? '1' : '0' ?>"
                data-refill-proof-required="<?= $refillProofRequired ? '1' : '0' ?>"
                data-usage-reason-catalogs="<?= e((string) json_encode($usageReasonCatalogs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>"
            >
                <?= csrf_field() ?>
                <div class="movement-feedback" data-movement-feedback hidden></div>

                <div class="movement-form-grid movement-form-grid-locations">
                    <label class="field">
                        <span>Movement Type</span>
                        <select name="movement_type" data-movement-type>
                            <?php foreach ($movementTypeOptions as $movementType => $movementLabel): ?>
                                <option value="<?= e((string) $movementType) ?>"><?= e((string) $movementLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="field" data-source-field>
                        <span data-source-label>From Location</span>
                        <select name="source_storage_id" data-source-storage>
                            <option value="">Select location</option>
                            <?php foreach ($storages as $storage): ?>
                                <option value="<?= e((string) $storage['id']) ?>" data-usage-profile="<?= e(normalize_storage_usage_profile((string) ($storage['usage_profile'] ?? 'wristband'))) ?>">
                                    <?= e(storage_type_label($storage['storage_type'])) ?> · <?= e($storage['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="field" data-destination-field hidden>
                        <span data-destination-label>To Location</span>
                        <select name="destination_storage_id" data-destination-storage>
                            <option value="">Select location</option>
                            <?php foreach ($storages as $storage): ?>
                                <option value="<?= e((string) $storage['id']) ?>" data-usage-profile="<?= e(normalize_storage_usage_profile((string) ($storage['usage_profile'] ?? 'wristband'))) ?>">
                                    <?= e(storage_type_label($storage['storage_type'])) ?> · <?= e($storage['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>

                <div class="movement-form-grid movement-form-grid-details">
                    <label class="field">
                        <span>Entered Quantity</span>
                        <input type="number" step="any" name="input_quantity" placeholder="Type 2 boxes or 750 mL" data-quantity-input required>
                        <small data-quantity-hint>Enter a positive amount. The selected package converts it to <?= e($canonicalUnit) ?>.</small>
                    </label>

                    <label class="field" data-package-field>
                        <span>Unit / Package</span>
                        <select name="package_preset_id" data-package-preset>
                            <option value="" data-conversion="1">Base unit · <?= e($canonicalUnit) ?></option>
                            <?php foreach ($activePackagePresets as $preset): ?>
                                <option value="<?= e((string) $preset['id']) ?>" data-conversion="<?= e((string) $preset['pieces_per_unit']) ?>">
                                    <?= e((string) $preset['label']) ?> · ×<?= format_quantity((float) $preset['pieces_per_unit']) ?> <?= e($canonicalUnit) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small>Only admin-defined conversions are available.</small>
                    </label>

                    <label class="field">
                        <span>Date and Time</span>
                        <input type="datetime-local" name="used_at" value="<?= e(date('Y-m-d\TH:i')) ?>" required>
                    </label>

                    <label class="field">
                        <span>Reference</span>
                        <input type="text" name="reference_code" placeholder="Invoice, order, note">
                    </label>
                </div>

                <div class="movement-form-grid" data-usage-reason-field hidden>
                    <label class="field">
                        <span>Usage Reason</span>
                        <select name="usage_reason" data-usage-reason>
                            <option value="">Pick reason</option>
                            <?php foreach ($usageReasons as $reason): ?>
                                <option value="<?= e((string) $reason['code']) ?>" data-requires-custom="<?= !empty($reason['requires_custom_text']) ? '1' : '0' ?>">
                                    <?= e((string) $reason['label']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="field" data-custom-reason-field hidden>
                        <span>Describe Other</span>
                        <input type="text" name="custom_reason" maxlength="160" placeholder="What was it used for?" data-custom-reason>
                    </label>
                </div>

                <?php if ($departmentOptions !== []): ?>
                    <label class="field">
                        <span>Department Attribution</span>
                        <select name="department_id">
                            <option value="">Employee's assigned department</option>
                            <?php foreach ($departmentOptions as $department): ?>
                                <option value="<?= e((string) $department['id']) ?>"><?= e((string) $department['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small>Override only when this operation belongs to a different department.</small>
                    </label>
                <?php endif; ?>

                <label class="field" data-movement-proof-field hidden>
                    <span>Proof Image <strong data-proof-requirement></strong></span>
                    <input type="file" name="proof_image" accept="image/jpeg,image/png,image/webp" capture="environment" data-movement-proof>
                    <small>Attach one protected photo for this stock operation.</small>
                </label>

                <label class="field">
                    <span>Notes</span>
                    <textarea name="notes" rows="4" placeholder="Why this moved, who used it, what changed"></textarea>
                </label>

                <section class="movement-preview-grid">
                    <article class="preview-card">
                        <span>Projected Change</span>
                        <strong data-preview-delta>0 <?= e($item['unit']) ?></strong>
                    </article>
                    <article class="preview-card preview-card-primary">
                        <span>Projected On Hand</span>
                        <strong data-preview-balance><?= format_quantity($item['current_quantity']) ?> <?= e($item['unit']) ?></strong>
                    </article>
                    <article class="preview-card">
                        <span data-preview-source-label>Source After</span>
                        <strong data-preview-source>-</strong>
                    </article>
                    <article class="preview-card">
                        <span data-preview-destination-label>Destination After</span>
                        <strong data-preview-destination>-</strong>
                    </article>
                    <article class="preview-card preview-card-wide">
                        <span>Projected Stock Value</span>
                        <strong data-preview-value><?= format_money($stockValue) ?></strong>
                    </article>
                </section>

                <button class="primary-button" type="submit" data-movement-submit>Save Movement</button>
            </form>
        <?php endif; ?>
    </article>
</section>

<?php View::partial('items/location_balances', ['item' => $item, 'balances' => $balances]); ?>

<?php if (!empty($purchaseHistory)): ?>
    <section class="panel">
        <div class="panel-head">
            <div>
                <p class="eyebrow">Supplier Trace</p>
                <h3>Purchase History</h3>
            </div>
            <a class="text-link" href="<?= e(url('/purchases?search=' . urlencode((string) $item['sku']))) ?>">Open purchases</a>
        </div>

        <div class="mini-list">
            <?php foreach ($purchaseHistory as $purchase): ?>
                <a class="mini-row" href="<?= e(url('/purchases/' . $purchase['id'])) ?>">
                    <div>
                        <strong><?= e($purchase['purchase_number']) ?></strong>
                        <span><?= e($purchase['supplier_name']) ?> · <?= e($purchase['storage_name']) ?></span>
                    </div>
                    <div class="align-right">
                        <strong><?= format_quantity($purchase['quantity_final']) ?> <?= e($item['unit']) ?></strong>
                        <span><?= e($purchase['currency']) ?> <?= number_format((float) $purchase['unit_cost_approved'], 2) ?> · <?= e(purchase_status_label((string) $purchase['status'])) ?></span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<section class="panel">
    <div class="panel-head">
        <div>
            <p class="eyebrow">History</p>
            <h3>Movement Log</h3>
        </div>
    </div>

    <div class="table-wrap">
        <table class="data-table data-table-mobile">
            <thead>
            <tr>
                <th>When</th>
                <th>Type</th>
                <th>Quantity</th>
                <th>Total Change</th>
                <th>Balance After</th>
                <th>From</th>
                <th>To</th>
                <th>Reference</th>
                <th>By</th>
                <th>Notes</th>
            </tr>
            </thead>
            <tbody data-history-body>
            <?php if ($history === []): ?>
                <tr>
                    <td colspan="10" class="empty-cell">No movement history yet.</td>
                </tr>
            <?php endif; ?>
            <?php foreach ($history as $movement): ?>
                <?php View::partial('items/history_row', ['movement' => $movement, 'item' => $item]); ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
