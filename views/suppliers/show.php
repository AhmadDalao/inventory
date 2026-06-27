<?php
$completedValue = array_reduce($purchaseHistory, static fn (float $carry, array $purchase): float => $carry + (float) $purchase['total_value'], 0.0);
?>

<section class="page-head">
    <div class="page-head-copy">
        <p class="eyebrow"><?= e(site_setting('page.suppliers_eyebrow', 'Vendor directory')) ?></p>
        <h3 class="page-head-title"><?= ui_icon('supplier') ?><span><?= e($supplier['name']) ?></span></h3>
    </div>
    <div class="page-actions">
        <a class="ghost-button" href="<?= e(url('/suppliers')) ?>"><?= ui_icon('back') ?><span>Back</span></a>
        <?php if (Auth::hasPermission('suppliers.edit')): ?>
            <a class="primary-button" href="<?= e(url('/suppliers/' . $supplier['id'] . '/edit')) ?>"><?= ui_icon('edit') ?><span>Edit</span></a>
        <?php endif; ?>
    </div>
</section>

<section class="metric-grid compact-grid">
    <article class="metric-card">
        <span class="metric-card-icon"><?= ui_icon('document') ?><span>Purchases</span></span>
        <strong><?= number_format(count($purchaseHistory)) ?></strong>
        <small>Latest linked records</small>
    </article>
    <article class="metric-card">
        <span class="metric-card-icon"><?= ui_icon('value') ?><span>Completed Value</span></span>
        <strong><?= format_money($completedValue) ?></strong>
        <small>From completed purchase history</small>
    </article>
</section>

<section class="panel">
    <div class="detail-grid">
        <dl class="detail-list">
            <div>
                <dt>Status</dt>
                <dd><span class="pill <?= (int) $supplier['is_active'] === 1 ? 'pill-active' : 'pill-muted' ?>"><?= (int) $supplier['is_active'] === 1 ? 'Active' : 'Archived' ?></span></dd>
            </div>
            <div>
                <dt>Supplier Type</dt>
                <dd><?= e(supplier_type_display($supplier['supplier_type'] ?? 'product', $supplier['supplier_type_other'] ?? null)) ?></dd>
            </div>
            <div>
                <dt>Authorized Person</dt>
                <dd><?= e((string) ($supplier['authorized_person'] ?: 'Not set')) ?></dd>
            </div>
            <div>
                <dt>Phone</dt>
                <dd><?= e((string) ($supplier['phone'] ?: 'Not set')) ?></dd>
            </div>
            <div>
                <dt>Email</dt>
                <dd><?= e((string) ($supplier['email'] ?: 'Not set')) ?></dd>
            </div>
            <div>
                <dt>VAT / Tax Number</dt>
                <dd><?= e((string) ($supplier['tax_number'] ?: 'Not set')) ?></dd>
            </div>
            <div>
                <dt>Commercial Registration (CR)</dt>
                <dd><?= e((string) ($supplier['commercial_registration'] ?: 'Not set')) ?></dd>
            </div>
            <div>
                <dt>National Address</dt>
                <dd><?= e((string) ($supplier['national_address'] ?: 'Not set')) ?></dd>
            </div>
            <div>
                <dt>Created</dt>
                <dd><?= e(format_datetime_display((string) $supplier['created_at'])) ?><?= $supplier['creator_name'] ? ' by ' . e((string) $supplier['creator_name']) : '' ?></dd>
            </div>
        </dl>

        <div class="copy-context-card">
            <strong>Notes</strong>
            <p><?= e((string) ($supplier['notes'] ?: 'No supplier notes yet.')) ?></p>
        </div>
    </div>
</section>

<section class="panel data-table-shell" data-table-shell data-empty-text="No purchases for this supplier.">
    <div class="table-shell-head">
        <div class="table-heading">
            <strong><?= ui_icon('purchases') ?><span>Purchase History</span></strong>
            <span class="table-count-badge" data-table-total><?= number_format(count($purchaseHistory)) ?></span>
        </div>
        <p class="table-shell-copy">Trace received stock back to this supplier.</p>
    </div>

    <div class="data-table-toolbar">
        <div class="table-toolbar-group">
            <label class="table-page-size">
                <span>Show</span>
                <select data-table-page-size>
                    <option value="10">10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                </select>
                <span>entries</span>
            </label>
            <label class="table-search">
                <span class="sr-only">Search purchases</span>
                <input type="search" data-table-search placeholder="Search purchase, status, storage">
            </label>
        </div>
    </div>

    <div class="table-wrap">
        <table class="data-table data-table-mobile">
            <thead>
            <tr>
                <th>Purchase</th>
                <th>Storage</th>
                <th>Quantity</th>
                <th>Value</th>
                <th>Status</th>
                <th>Date</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($purchaseHistory === []): ?>
                <tr>
                    <td colspan="6" class="empty-cell">No purchases found.</td>
                </tr>
            <?php endif; ?>
            <?php foreach ($purchaseHistory as $purchase): ?>
                <tr>
                    <td data-label="Purchase"><a class="text-link" href="<?= e(url('/purchases/' . $purchase['id'])) ?>"><?= e($purchase['purchase_number']) ?></a></td>
                    <td data-label="Storage"><?= e($purchase['storage_name']) ?></td>
                    <td data-label="Quantity"><?= format_quantity($purchase['total_quantity']) ?></td>
                    <td data-label="Value"><?= e($purchase['currency']) ?> <?= number_format((float) $purchase['total_value'], 2) ?></td>
                    <td data-label="Status"><span class="pill pill-<?= e((string) $purchase['status']) ?>"><?= e(purchase_status_label((string) $purchase['status'])) ?></span></td>
                    <td data-label="Date"><?= e(format_datetime_display((string) $purchase['created_at'])) ?></td>
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
