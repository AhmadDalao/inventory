<?php
$exportQuery = http_build_query(array_filter($filters, static fn ($value): bool => $value !== '' && $value !== null));
?>

<section class="page-head">
    <div class="page-head-copy">
        <p class="eyebrow"><?= e(site_setting('page.stocktakes_eyebrow', 'Cycle counts')) ?></p>
        <h3 class="page-head-title"><?= ui_icon('stocktakes') ?><span><?= e(site_setting('page.stocktakes', 'Stocktakes')) ?></span></h3>
    </div>
    <div class="page-actions">
        <?php if (Auth::hasPermission('stocktakes.create')): ?>
            <a class="primary-button" href="<?= e(url('/stocktakes/create')) ?>"><?= ui_icon('plus') ?><span>Create Stocktake</span></a>
        <?php endif; ?>
    </div>
</section>

<div class="live-filter-region" data-live-filter-region="stocktakes">
<section class="filter-panel">
    <form class="filter-grid" method="get" action="<?= e(url('/stocktakes')) ?>" data-live-filter-form>
        <label class="field">
            <span>Search</span>
            <input type="text" name="search" value="<?= e($filters['search']) ?>" placeholder="Stocktake, item, SKU, storage, user">
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
            <a class="ghost-button" href="<?= e(url('/stocktakes')) ?>" data-live-filter-link><?= ui_icon('back') ?><span>Reset</span></a>
        </div>
    </form>
</section>

<section class="panel data-table-shell" data-table-shell data-empty-text="No stocktakes match this search.">
    <div class="table-shell-head">
        <div class="table-heading">
            <strong><?= ui_icon('stocktakes') ?><span><?= e(site_setting('table.stocktakes', 'All Stocktakes')) ?></span></strong>
            <span class="table-count-badge" data-table-total><?= number_format(count($stocktakes)) ?></span>
        </div>
        <p class="table-shell-copy">Count real stock, review variances, and post approved corrections to the movement log.</p>
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
                <span class="sr-only">Search stocktakes</span>
                <input type="search" data-table-search placeholder="Search stocktake, item, storage, status">
            </label>
        </div>

        <?php if (Auth::hasPermission('stocktakes.export')): ?>
            <a class="ghost-button table-export-button" href="<?= e(url('/exports/stocktakes') . ($exportQuery ? '?' . $exportQuery : '')) ?>"><?= ui_icon('export') ?><span>Export CSV</span></a>
        <?php endif; ?>
    </div>

    <div class="table-wrap">
        <table class="data-table data-table-mobile">
            <thead>
            <tr>
                <th>Stocktake</th>
                <th>Storage</th>
                <th>Lines</th>
                <th>Expected</th>
                <th>Counted</th>
                <th>Variance</th>
                <th>Status</th>
                <th>Created</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if ($stocktakes === []): ?>
                <tr>
                    <td colspan="9" class="empty-cell">No stocktakes found.</td>
                </tr>
            <?php endif; ?>
            <?php foreach ($stocktakes as $stocktake): ?>
                <?php $variance = (float) $stocktake['total_variance']; ?>
                <tr>
                    <td data-label="Stocktake">
                        <a class="cell-link cell-link-compact" href="<?= e(url('/stocktakes/' . $stocktake['id'])) ?>">
                            <strong><?= e($stocktake['stocktake_number']) ?></strong>
                            <div class="tiny-copy">By <?= e((string) ($stocktake['creator_name'] ?: 'Unknown')) ?></div>
                        </a>
                    </td>
                    <td data-label="Storage">
                        <strong><?= e($stocktake['storage_name']) ?></strong>
                        <div class="tiny-copy"><?= e(storage_type_label((string) $stocktake['storage_type'])) ?></div>
                    </td>
                    <td data-label="Lines"><?= number_format((int) $stocktake['line_count']) ?></td>
                    <td data-label="Expected"><?= format_quantity($stocktake['total_expected']) ?></td>
                    <td data-label="Counted"><?= format_quantity($stocktake['total_counted']) ?></td>
                    <td data-label="Variance" class="<?= $variance < 0 ? 'danger-text' : ($variance > 0 ? 'success-text' : '') ?>"><?= format_quantity($variance) ?></td>
                    <td data-label="Status">
                        <span class="pill <?= e(status_badge_class(stocktake_status_badge_type((string) $stocktake['status']))) ?>">
                            <?= e(stocktake_status_label((string) $stocktake['status'])) ?>
                        </span>
                    </td>
                    <td data-label="Created"><?= e(format_datetime_display((string) $stocktake['created_at'])) ?></td>
                    <td data-label="Actions"><a class="text-link" href="<?= e(url('/stocktakes/' . $stocktake['id'])) ?>">Open</a></td>
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
