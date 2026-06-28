<?php
$exportQuery = http_build_query(array_filter($filters, static fn ($value): bool => $value !== '' && $value !== null));
$supplierFilterUrl = static function (string $status) use ($filters): string {
    $query = $filters;
    $query['status'] = $status;

    return url('/suppliers?' . http_build_query(array_filter($query, static fn ($value): bool => $value !== '' && $value !== null)));
};
?>

<section class="page-head">
    <div class="page-head-copy">
        <p class="eyebrow"><?= e(site_setting('page.suppliers_eyebrow', 'Vendor directory')) ?></p>
        <h3 class="page-head-title"><?= ui_icon('supplier') ?><span><?= e(site_setting('page.suppliers', 'Suppliers')) ?></span></h3>
    </div>
    <div class="page-actions">
        <?php if (Auth::hasPermission('suppliers.create')): ?>
            <a class="primary-button" href="<?= e(url('/suppliers/create')) ?>"><?= ui_icon('plus') ?><span>Create Supplier</span></a>
        <?php endif; ?>
    </div>
</section>

<div class="live-filter-region" data-live-filter-region="suppliers">
<section class="filter-panel">
    <form class="filter-grid" method="get" action="<?= e(url('/suppliers')) ?>" data-live-filter-form>
        <label class="field">
            <span>Search</span>
            <input type="text" name="search" value="<?= e($filters['search']) ?>" placeholder="Supplier, type, phone, email, CR, address">
        </label>

        <label class="field">
            <span>Status</span>
            <select name="status">
                <option value="all" <?= selected('all', $filters['status']) ?>>All</option>
                <option value="active" <?= selected('active', $filters['status']) ?>>Active</option>
                <option value="archived" <?= selected('archived', $filters['status']) ?>>Archived</option>
            </select>
        </label>

        <div class="filter-actions">
            <button class="primary-button" type="submit"><?= ui_icon('filter') ?><span>Filter</span></button>
            <a class="ghost-button" href="<?= e(url('/suppliers')) ?>" data-live-filter-link><?= ui_icon('back') ?><span>Reset</span></a>
        </div>
    </form>

    <div class="chip-row">
        <a class="stat-chip filter-chip <?= $filters['status'] === 'all' ? 'filter-chip-active' : '' ?>" href="<?= e($supplierFilterUrl('all')) ?>" data-live-filter-link>All: <?= number_format($counts['active'] + $counts['archived']) ?></a>
        <a class="stat-chip filter-chip <?= $filters['status'] === 'active' ? 'filter-chip-active' : '' ?>" href="<?= e($supplierFilterUrl('active')) ?>" data-live-filter-link>Active: <?= number_format($counts['active']) ?></a>
        <a class="stat-chip filter-chip <?= $filters['status'] === 'archived' ? 'filter-chip-active' : '' ?>" href="<?= e($supplierFilterUrl('archived')) ?>" data-live-filter-link>Archived: <?= number_format($counts['archived']) ?></a>
    </div>
</section>

<section class="panel data-table-shell" data-table-shell data-empty-text="No suppliers match this search.">
    <div class="table-shell-head">
        <div class="table-heading">
            <strong><?= ui_icon('supplier') ?><span><?= e(site_setting('table.suppliers', 'All Suppliers')) ?></span></strong>
            <span class="table-count-badge" data-table-total><?= number_format(count($suppliers)) ?></span>
        </div>
        <p class="table-shell-copy">Supplier profile, authorized contact, commercial records, purchase count, and completed purchase value.</p>
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
                <span class="sr-only">Search suppliers</span>
                <input type="search" data-table-search placeholder="Search supplier, type, phone, email, CR">
            </label>
        </div>

        <?php if (Auth::hasPermission('suppliers.export')): ?>
            <a class="ghost-button table-export-button" href="<?= e(url('/exports/suppliers') . ($exportQuery ? '?' . $exportQuery : '')) ?>"><?= ui_icon('export') ?><span>Export CSV</span></a>
        <?php endif; ?>
    </div>

    <div class="table-wrap">
        <table class="data-table data-table-mobile">
            <thead>
            <tr>
                <th>Supplier</th>
                <th>Type</th>
                <th>Contact</th>
                <th>CR / VAT</th>
                <th>Purchases</th>
                <th>Completed Value</th>
                <th>Last Purchase</th>
                <th>Status</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if ($suppliers === []): ?>
                <tr>
                    <td colspan="9" class="empty-cell">No suppliers found.</td>
                </tr>
            <?php endif; ?>
            <?php foreach ($suppliers as $supplier): ?>
                <tr>
                    <td data-label="Supplier">
                        <a class="cell-link cell-link-compact" href="<?= e(url('/suppliers/' . $supplier['id'])) ?>">
                            <strong><?= e($supplier['name']) ?></strong>
                            <div class="tiny-copy"><?= e($supplier['authorized_person'] ? 'Authorized: ' . $supplier['authorized_person'] : 'Authorized person not set') ?></div>
                        </a>
                    </td>
                    <td data-label="Type"><?= e(supplier_type_display($supplier['supplier_type'] ?? 'product', $supplier['supplier_type_other'] ?? null)) ?></td>
                    <td data-label="Contact">
                        <?= e((string) ($supplier['phone'] ?: '-')) ?>
                        <div class="tiny-copy"><?= e((string) ($supplier['email'] ?: 'No email')) ?></div>
                    </td>
                    <td data-label="CR / VAT">
                        <?= e((string) ($supplier['commercial_registration'] ?: '-')) ?>
                        <div class="tiny-copy">VAT <?= e((string) ($supplier['tax_number'] ?: '-')) ?></div>
                    </td>
                    <td data-label="Purchases">
                        <strong><?= number_format((int) $supplier['purchase_count']) ?></strong>
                        <div class="tiny-copy">Completed <?= number_format((int) $supplier['completed_count']) ?></div>
                    </td>
                    <td data-label="Completed Value"><?= format_money($supplier['total_value']) ?></td>
                    <td data-label="Last Purchase"><?= $supplier['last_purchase_at'] ? e(format_datetime_display((string) $supplier['last_purchase_at'])) : 'Never' ?></td>
                    <td data-label="Status">
                        <span class="pill <?= (int) $supplier['is_active'] === 1 ? 'pill-active' : 'pill-muted' ?>">
                            <?= (int) $supplier['is_active'] === 1 ? 'Active' : 'Archived' ?>
                        </span>
                    </td>
                    <td data-label="Actions">
                        <div class="inline-actions">
                            <a class="text-link" href="<?= e(url('/suppliers/' . $supplier['id'])) ?>">Open</a>
                            <?php if (Auth::hasPermission('suppliers.edit')): ?>
                                <a class="text-link" href="<?= e(url('/suppliers/' . $supplier['id'] . '/edit')) ?>">Edit</a>
                            <?php endif; ?>
                            <?php if (Auth::hasPermission('suppliers.archive')): ?>
                                <form method="post" action="<?= e(url('/suppliers/' . $supplier['id'] . '/status')) ?>" data-live-action-form>
                                    <?= csrf_field() ?>
                                    <button class="text-button danger-link" type="submit" data-confirm="<?= (int) $supplier['is_active'] === 1 ? 'Archive this supplier?' : 'Recover this supplier?' ?>">
                                        <?= (int) $supplier['is_active'] === 1 ? 'Archive' : 'Recover' ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
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
