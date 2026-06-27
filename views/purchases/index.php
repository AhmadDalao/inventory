<?php
$exportQuery = http_build_query(array_filter($filters, static fn ($value): bool => $value !== '' && $value !== null));
?>

<section class="page-head">
    <div class="page-head-copy">
        <p class="eyebrow"><?= e(site_setting('page.purchases_eyebrow', 'Supplier Restocking')) ?></p>
        <h3 class="page-head-title"><?= ui_icon('purchases') ?><span><?= e(site_setting('page.purchases', 'Purchases')) ?></span></h3>
    </div>
    <div class="page-actions">
        <?php if (Auth::hasPermission('purchases.create')): ?>
            <a class="ghost-button" href="<?= e(url('/purchases/import')) ?>"><?= ui_icon('document') ?><span>Bulk Import</span></a>
            <a class="primary-button" href="<?= e(url('/purchases/create')) ?>"><?= ui_icon('plus') ?><span>Create Purchase</span></a>
        <?php endif; ?>
    </div>
</section>

<div class="live-filter-region" data-live-filter-region="purchases">
<section class="filter-panel">
    <form class="filter-grid" method="get" action="<?= e(url('/purchases')) ?>" data-live-filter-form>
        <label class="field">
            <span>Search</span>
            <input type="text" name="search" value="<?= e($filters['search']) ?>" placeholder="PO number, supplier, storage">
        </label>

        <label class="field">
            <span>Status</span>
            <select name="status">
                <?php foreach ($statuses as $statusValue => $statusLabel): ?>
                    <option value="<?= e($statusValue) ?>" <?= selected($statusValue, $filters['status']) ?>><?= e($statusLabel) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="field">
            <span>Supplier</span>
            <select name="supplier_id">
                <option value="">All suppliers</option>
                <?php foreach ($suppliers as $supplier): ?>
                    <option value="<?= e((string) $supplier['id']) ?>" <?= selected((string) $supplier['id'], (string) ($filters['supplier_id'] ?? '')) ?>><?= e($supplier['name']) ?></option>
                <?php endforeach; ?>
            </select>
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

        <label class="field">
            <span>From</span>
            <input type="date" name="date_from" value="<?= e($filters['date_from']) ?>">
        </label>

        <label class="field">
            <span>To</span>
            <input type="date" name="date_to" value="<?= e($filters['date_to']) ?>">
        </label>

        <div class="filter-actions">
            <button class="primary-button" type="submit"><?= ui_icon('filter') ?><span>Filter</span></button>
            <a class="ghost-button" href="<?= e(url('/purchases')) ?>" data-live-filter-link><?= ui_icon('back') ?><span>Reset</span></a>
        </div>
    </form>
</section>

<section class="panel data-table-shell" data-table-shell data-empty-text="No purchases match this search.">
    <div class="table-shell-head">
        <div class="table-heading">
            <strong><?= ui_icon('purchases') ?><span><?= e(site_setting('table.purchases', 'Supplier Purchases')) ?></span></strong>
            <span class="table-count-badge" data-table-total><?= number_format(count($purchases)) ?></span>
        </div>
        <p class="table-shell-copy">Track quotes, approvals, receiving, final stock posting, and proof files.</p>
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
                <span class="sr-only">Search purchases</span>
                <input type="search" data-table-search placeholder="Search purchase, supplier, storage, status">
            </label>
        </div>

        <?php if (Auth::hasPermission('purchases.export')): ?>
            <a class="ghost-button table-export-button" href="<?= e(url('/exports/purchases') . ($exportQuery ? '?' . $exportQuery : '')) ?>"><?= ui_icon('export') ?><span>Export CSV</span></a>
        <?php endif; ?>
    </div>

    <div class="table-wrap">
        <table class="data-table data-table-mobile">
            <thead>
            <tr>
                <th>Purchase</th>
                <th>Supplier</th>
                <th>Storage</th>
                <th>Lines</th>
                <th>Approved Value</th>
                <th>Received Value</th>
                <th>Files</th>
                <th>Status</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if ($purchases === []): ?>
                <tr>
                    <td colspan="9" class="empty-cell">No purchases found.</td>
                </tr>
            <?php endif; ?>
            <?php foreach ($purchases as $purchase): ?>
                <tr>
                    <td data-label="Purchase">
                        <a class="cell-link cell-link-compact" href="<?= e(url('/purchases/' . $purchase['id'])) ?>">
                            <strong><?= e($purchase['purchase_number']) ?></strong>
                            <div class="tiny-copy"><?= e(format_datetime_display((string) $purchase['created_at'])) ?></div>
                        </a>
                    </td>
                    <td data-label="Supplier"><?= e($purchase['supplier_name']) ?></td>
                    <td data-label="Storage">
                        <strong><?= e($purchase['storage_name']) ?></strong>
                        <div class="tiny-copy"><?= e(storage_type_label((string) $purchase['storage_type'])) ?></div>
                    </td>
                    <td data-label="Lines"><?= number_format((int) $purchase['line_count']) ?></td>
                    <td data-label="Approved Value"><?= e($purchase['currency']) ?> <?= number_format((float) $purchase['approved_total'], 2) ?></td>
                    <td data-label="Received Value"><?= e($purchase['currency']) ?> <?= number_format((float) $purchase['received_total'], 2) ?></td>
                    <td data-label="Files"><?= number_format((int) $purchase['document_count']) ?></td>
                    <td data-label="Status">
                        <span class="pill pill-<?= e((string) $purchase['status']) ?>"><?= e(purchase_status_label((string) $purchase['status'])) ?></span>
                    </td>
                    <td data-label="Actions"><a class="text-link" href="<?= e(url('/purchases/' . $purchase['id'])) ?>">Open</a></td>
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
