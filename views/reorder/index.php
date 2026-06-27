<?php
$exportQuery = http_build_query(array_filter($filters, static fn ($value): bool => $value !== '' && $value !== null && $value !== false));
$selectedStorageId = $filters['storage_id'] ?? null;
$totalSuggestedValue = array_reduce($rows, static fn (float $carry, array $row): float => $carry + ((float) $row['suggested_quantity'] * (float) $row['cost_per_unit']), 0.0);
$supplierPickerRows = array_map(static function (array $supplier): array {
    return [
        'id' => (int) $supplier['id'],
        'name' => (string) $supplier['name'],
        'supplier_type' => (string) ($supplier['supplier_type'] ?? ''),
        'supplier_type_other' => (string) ($supplier['supplier_type_other'] ?? ''),
        'supplier_type_label' => supplier_type_display((string) ($supplier['supplier_type'] ?? 'product'), $supplier['supplier_type_other'] ?? null),
        'phone' => (string) ($supplier['phone'] ?? ''),
        'email' => (string) ($supplier['email'] ?? ''),
        'tax_number' => (string) ($supplier['tax_number'] ?? ''),
        'commercial_registration' => (string) ($supplier['commercial_registration'] ?? ''),
        'national_address' => (string) ($supplier['national_address'] ?? ''),
        'authorized_person' => (string) ($supplier['authorized_person'] ?? ''),
    ];
}, $suppliers);
$supplierPickerJson = json_encode($supplierPickerRows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
?>

<section class="page-head">
    <div class="page-head-copy">
        <p class="eyebrow"><?= e(site_setting('page.reorder_eyebrow', 'Low stock automation')) ?></p>
        <h3 class="page-head-title"><?= ui_icon('reorder') ?><span><?= e(site_setting('page.reorder', 'Reorder Center')) ?></span></h3>
    </div>
    <div class="page-actions">
        <?php if (Auth::hasPermission('reorder.export')): ?>
            <a class="ghost-button" href="<?= e(url('/exports/reorder') . ($exportQuery ? '?' . $exportQuery : '')) ?>"><?= ui_icon('export') ?><span>Export CSV</span></a>
        <?php endif; ?>
    </div>
</section>

<div class="live-filter-region" data-live-filter-region="reorder">
<section class="filter-panel">
    <form class="filter-grid" method="get" action="<?= e(url('/reorder')) ?>" data-live-filter-form>
        <label class="field">
            <span>Search</span>
            <input type="text" name="search" value="<?= e($filters['search']) ?>" placeholder="Item, SKU, storage">
        </label>

        <label class="field">
            <span>Storage</span>
            <select name="storage_id">
                <option value="">All storages</option>
                <?php foreach ($storages as $storage): ?>
                    <option value="<?= e((string) $storage['id']) ?>" <?= selected((string) $storage['id'], (string) ($filters['storage_id'] ?? '')) ?>>
                        <?= e(storage_type_label((string) $storage['storage_type'])) ?> · <?= e((string) $storage['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="choice-field">
            <input type="checkbox" name="include_zero_policy" value="1" <?= checked(!empty($filters['include_zero_policy'])) ?>>
            <div>
                <strong>Include zero reorder policies</strong>
                <span>Shows items where reorder level is 0 and quantity is also 0. Usually this is noise.</span>
            </div>
        </label>

        <div class="filter-actions">
            <button class="primary-button" type="submit"><?= ui_icon('filter') ?><span>Filter</span></button>
            <a class="ghost-button" href="<?= e(url('/reorder')) ?>" data-live-filter-link><?= ui_icon('back') ?><span>Reset</span></a>
        </div>
    </form>
</section>

<section class="metric-grid compact-grid">
    <article class="metric-card">
        <span class="metric-card-icon"><?= ui_icon('items') ?><span>Low Stock Lines</span></span>
        <strong><?= number_format(count($rows)) ?></strong>
        <small>Based on current storage balance</small>
    </article>
    <article class="metric-card">
        <span class="metric-card-icon"><?= ui_icon('value') ?><span>Suggested Value</span></span>
        <strong><?= format_money($totalSuggestedValue) ?></strong>
        <small>Quantity needed × current item cost</small>
    </article>
</section>

<?php if (Auth::hasPermission('reorder.create_purchase') && Auth::hasPermission('purchases.create')): ?>
    <section class="panel reorder-draft-panel">
        <div class="table-shell-head">
            <div class="table-heading">
                <strong><?= ui_icon('purchases') ?><span>Create Purchase Draft</span></strong>
            </div>
            <p class="table-shell-copy">Select the basics here. Supplier details only open when you are adding a supplier that is not saved yet.</p>
        </div>

        <form class="reorder-draft-form" method="post" action="<?= e(url('/reorder/create-purchase')) ?>" data-reorder-draft-form data-reorder-suppliers="<?= e((string) $supplierPickerJson) ?>">
            <?= csrf_field() ?>

            <div class="reorder-draft-primary">
                <label class="field">
                    <span>Storage</span>
                    <select name="storage_id" required>
                        <option value="">Pick one storage</option>
                        <?php foreach ($storages as $storage): ?>
                            <option value="<?= e((string) $storage['id']) ?>" <?= selected((string) $storage['id'], (string) ($selectedStorageId ?? '')) ?>>
                                <?= e(storage_type_label((string) $storage['storage_type'])) ?> · <?= e((string) $storage['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <div class="field reorder-supplier-field">
                    <span>Supplier</span>
                    <input type="hidden" name="supplier_id" value="" data-reorder-supplier-id>
                    <div class="purchase-picker reorder-supplier-picker" data-reorder-supplier-picker>
                        <button class="purchase-picker-toggle" type="button" data-reorder-supplier-toggle aria-expanded="false">
                            <span>
                                <strong data-reorder-supplier-label>Choose supplier</strong>
                                <small>Search saved suppliers or create a new one</small>
                            </span>
                            <span class="purchase-picker-caret" aria-hidden="true">v</span>
                        </button>
                        <div class="purchase-picker-panel" data-reorder-supplier-panel hidden>
                            <label class="purchase-picker-search">
                                <?= ui_icon('search') ?>
                                <input type="search" data-reorder-supplier-search placeholder="Search supplier name, phone, VAT, CR">
                            </label>
                            <div class="purchase-picker-options" data-reorder-supplier-options></div>
                        </div>
                    </div>
                    <div class="purchase-selected-summary reorder-selected-supplier" data-reorder-supplier-summary hidden></div>
                </div>

                <label class="field">
                    <span>Approver</span>
                    <select name="approver_user_id" required>
                        <option value="">Pick approver</option>
                        <?php foreach ($approvers as $approver): ?>
                            <option value="<?= e((string) $approver['id']) ?>"><?= e($approver['name']) ?> · <?= e(user_role_label((string) $approver['role'])) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="field reorder-currency-field">
                    <span>Currency</span>
                    <input type="text" name="currency" value="SAR" maxlength="8">
                </label>
            </div>

            <details class="reorder-new-supplier-card" data-reorder-new-supplier hidden>
                <summary>
                    <strong>New Supplier Details</strong>
                    <span>Required only when the supplier is not already saved.</span>
                </summary>
                <div class="reorder-new-supplier-grid">
                    <label class="field">
                        <span>New supplier name</span>
                        <input type="text" name="supplier_name" placeholder="Supplier legal or trading name" data-reorder-new-supplier-input disabled>
                    </label>

                    <label class="field">
                        <span>Supplier type</span>
                        <select name="supplier_type" data-reorder-new-supplier-input data-supplier-type-select disabled>
                            <?php foreach (supplier_type_options() as $type => $label): ?>
                                <option value="<?= e($type) ?>"><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="field" data-supplier-type-other-field hidden>
                        <span>Custom supplier type</span>
                        <input type="text" name="supplier_type_other" placeholder="Example: Maintenance, contractor, logistics" data-reorder-new-supplier-input data-supplier-type-other-input disabled>
                    </label>

                    <label class="field">
                        <span>Supplier phone</span>
                        <input type="text" name="supplier_phone" placeholder="Mandatory" data-reorder-new-supplier-input disabled>
                    </label>

                    <label class="field">
                        <span>Authorized person / اسم المفوض</span>
                        <input type="text" name="supplier_authorized_person" placeholder="Mandatory" data-reorder-new-supplier-input disabled>
                    </label>

                    <label class="field">
                        <span>National address / العنوان الوطني</span>
                        <input type="text" name="supplier_national_address" placeholder="Mandatory" data-reorder-new-supplier-input disabled>
                    </label>

                    <label class="field">
                        <span>Commercial Registration (CR)</span>
                        <input type="text" name="supplier_commercial_registration" placeholder="Optional" data-reorder-new-supplier-input disabled>
                    </label>

                    <label class="field">
                        <span>Supplier email</span>
                        <input type="email" name="supplier_email" placeholder="Optional" data-reorder-new-supplier-input disabled>
                    </label>

                    <label class="field">
                        <span>VAT / Tax number</span>
                        <input type="text" name="supplier_tax_number" placeholder="Optional" data-reorder-new-supplier-input disabled>
                    </label>
                </div>
            </details>

            <div class="reorder-draft-footer">
                <label class="field">
                    <span>Notes</span>
                    <input type="text" name="notes" placeholder="Optional note for this reorder draft">
                </label>

                <div class="reorder-draft-reminder">
                    <?= ui_icon('files') ?>
                    <span>The draft opens next so you can attach quote, receipt, or proof files before approval.</span>
                </div>

                <button class="primary-button" type="submit" data-confirm="Create a purchase draft from current low-stock suggestions for this storage?">Create Draft</button>
            </div>
        </form>
    </section>
<?php endif; ?>

<section class="panel data-table-shell" data-table-shell data-empty-text="No low-stock suggestions match this search.">
    <div class="table-shell-head">
        <div class="table-heading">
            <strong><?= ui_icon('reorder') ?><span><?= e(site_setting('table.reorder', 'Low Stock Suggestions')) ?></span></strong>
            <span class="table-count-badge" data-table-total><?= number_format(count($rows)) ?></span>
        </div>
        <p class="table-shell-copy">Only items at or below reorder level are shown.</p>
    </div>

    <div class="data-table-toolbar">
        <div class="table-toolbar-group">
            <label class="table-page-size">
                <span>Show</span>
                <select data-table-page-size>
                    <option value="10">10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
                <span>entries</span>
            </label>

            <label class="table-search">
                <span class="sr-only">Search reorder rows</span>
                <input type="search" data-table-search placeholder="Search item, SKU, storage">
            </label>
        </div>
    </div>

    <div class="table-wrap">
        <table class="data-table data-table-mobile">
            <thead>
            <tr>
                <th>Item</th>
                <th>Storage</th>
                <th>Current</th>
                <th>Reorder At</th>
                <th>Suggested</th>
                <th>Est. Value</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr>
                    <td colspan="6" class="empty-cell">No reorder suggestions.</td>
                </tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <?php $imageUrl = item_image_url($row['image_path'] ?? null); ?>
                <tr>
                    <td data-label="Item">
                        <a class="item-table-cell cell-link" href="<?= e(url('/items/' . $row['item_id'])) ?>">
                            <?php if ($imageUrl): ?>
                                <img class="item-thumb expandable-image" src="<?= e($imageUrl) ?>" alt="<?= e($row['item_name']) ?>" data-expand-image tabindex="0">
                            <?php else: ?>
                                <span class="item-thumb item-thumb-fallback"><?= e(item_initial($row['item_name'])) ?></span>
                            <?php endif; ?>
                            <div>
                                <strong><?= e($row['item_name']) ?></strong>
                                <div class="tiny-copy"><?= e($row['sku']) ?> · <?= e($row['unit']) ?></div>
                            </div>
                        </a>
                    </td>
                    <td data-label="Storage">
                        <a class="text-link" href="<?= e(url('/storages/' . $row['storage_id'])) ?>"><?= e($row['storage_name']) ?></a>
                        <div class="tiny-copy"><?= e(storage_type_label((string) $row['storage_type'])) ?></div>
                    </td>
                    <td data-label="Current" class="danger-text"><?= format_quantity($row['quantity']) ?> <?= e($row['unit']) ?></td>
                    <td data-label="Reorder At"><?= format_quantity($row['reorder_level']) ?> <?= e($row['unit']) ?></td>
                    <td data-label="Suggested"><strong><?= format_quantity($row['suggested_quantity']) ?> <?= e($row['unit']) ?></strong></td>
                    <td data-label="Est. Value"><?= format_money((float) $row['suggested_quantity'] * (float) $row['cost_per_unit']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="data-table-footer">
        <p class="table-results" data-table-results>Showing 0 to 0 of 0 entries</p>
        <div class="table-pagination" data-table-pagination></div>
    </div>
</section>
</div>
