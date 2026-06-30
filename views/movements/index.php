<?php
$exportQuery = http_build_query(array_filter($filters, static fn ($value): bool => $value !== '' && $value !== null));
$isLocationScoped = !empty($filters['storage_id']);
?>

<section class="page-head">
    <div class="page-head-copy">
        <p class="eyebrow"><?= e(site_setting('page.movements_eyebrow', 'Audit Trail')) ?></p>
        <h3 class="page-head-title"><?= ui_icon('movements') ?><span><?= e(site_setting('page.movements', 'Movement Log')) ?></span></h3>
    </div>
</section>

<div class="live-filter-region" data-live-filter-region="movements">
<section class="filter-panel">
    <form class="filter-grid movement-filter-grid" method="get" action="<?= e(url('/movements')) ?>" data-live-filter-form>
        <label class="field">
            <span>Item</span>
            <select name="item_id" data-searchable-select data-searchable-placeholder="Search item, SKU, or barcode">
                <option value="">All items</option>
                <?php foreach ($items as $item): ?>
                    <option
                        value="<?= e((string) $item['id']) ?>"
                        data-search-text="<?= e(trim((string) $item['name'] . ' ' . (string) $item['sku'] . ' ' . (string) ($item['barcode'] ?? '') . ' ' . (string) ($item['unit'] ?? ''))) ?>"
                        <?= selected((string) $item['id'], (string) ($filters['item_id'] ?? '')) ?>
                    >
                        <?= e($item['name']) ?> (<?= e($item['sku']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="field">
            <span>Type</span>
            <select name="movement_type">
                <option value="">All types</option>
                <option value="usage" <?= selected('usage', $filters['movement_type']) ?>>Usage</option>
                <option value="restock" <?= selected('restock', $filters['movement_type']) ?>>Restock</option>
                <option value="transfer" <?= selected('transfer', $filters['movement_type']) ?>>Transfer</option>
                <option value="adjustment" <?= selected('adjustment', $filters['movement_type']) ?>>Adjustment</option>
            </select>
        </label>

        <label class="field">
            <span>Location</span>
            <select name="storage_id">
                <option value="">All locations</option>
                <?php foreach ($storages as $storage): ?>
                    <option value="<?= e((string) $storage['id']) ?>" <?= selected((string) $storage['id'], (string) ($filters['storage_id'] ?? '')) ?>>
                        <?= e(storage_type_label($storage['storage_type'])) ?> · <?= e($storage['name']) ?>
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
            <a class="ghost-button" href="<?= e(url('/movements')) ?>" data-live-filter-link><?= ui_icon('back') ?><span>Reset</span></a>
        </div>
    </form>
</section>

<section class="panel data-table-shell" data-table-shell data-empty-text="No movement records match this search.">
    <div class="table-shell-head">
        <div class="table-heading">
            <strong><?= ui_icon('movements') ?><span><?= e(site_setting('table.movements', 'All Movements')) ?></span></strong>
            <span class="table-count-badge" data-table-total><?= number_format(count($movements)) ?></span>
        </div>
        <p class="table-shell-copy">Search the loaded activity, page it cleanly, and export the full filtered log.</p>
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
                <span class="sr-only">Search movement log</span>
                <input type="search" data-table-search placeholder="Search item, type, location, reference, user, or notes">
            </label>
        </div>

        <?php if (Auth::hasPermission('movements.export')): ?>
            <div class="button-row">
                <?php if (movement_xlsx_thumbnail_export_enabled()): ?>
                    <a class="ghost-button table-export-button" href="<?= e(url('/exports/movements.xlsx') . ($exportQuery ? '?' . $exportQuery : '')) ?>"><?= ui_icon('items') ?><span>Export Excel</span></a>
                <?php endif; ?>
                <a class="ghost-button table-export-button" href="<?= e(url('/exports/movements') . ($exportQuery ? '?' . $exportQuery : '')) ?>"><?= ui_icon('export') ?><span>Export CSV</span></a>
            </div>
        <?php endif; ?>
    </div>

    <div class="table-wrap">
        <table class="data-table data-table-mobile">
            <thead>
            <tr>
                <th>When</th>
                <th>Item</th>
                <th>Type</th>
                <th>Quantity</th>
                <th><?= $isLocationScoped ? 'Location Change' : 'Total Change' ?></th>
                <th><?= $isLocationScoped ? 'Location Balance After' : 'Balance After' ?></th>
                <?php if ($isLocationScoped): ?>
                    <th>Location Scope</th>
                <?php endif; ?>
                <th>From</th>
                <th>To</th>
                <th>Reference</th>
                <th>By</th>
                <th>Notes</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($movements === []): ?>
                <tr>
                    <td colspan="<?= $isLocationScoped ? '12' : '11' ?>" class="empty-cell">No movement records found.</td>
                </tr>
            <?php endif; ?>
            <?php foreach ($movements as $movement): ?>
                <tr>
                    <td data-label="When"><?= e(date('M j, Y g:i A', strtotime($movement['used_at']))) ?></td>
                    <td data-label="Item">
                        <a class="cell-link cell-link-compact" href="<?= e(url('/items/' . $movement['item_id'])) ?>">
                            <strong><?= e($movement['item_name']) ?></strong>
                            <div class="tiny-copy"><?= e($movement['sku']) ?></div>
                        </a>
                    </td>
                    <td data-label="Type"><span class="pill pill-<?= e($movement['movement_type']) ?>"><?= e(ucfirst($movement['movement_type'])) ?></span></td>
                    <td data-label="Quantity"><?= format_quantity($movement['movement_quantity'] ?? abs((float) $movement['quantity_delta'])) ?> <?= e($movement['unit']) ?></td>
                    <td data-label="<?= $isLocationScoped ? 'Location Change' : 'Total Change' ?>"><?= format_quantity($isLocationScoped ? $movement['location_change'] : $movement['quantity_delta']) ?> <?= e($movement['unit']) ?></td>
                    <td data-label="<?= $isLocationScoped ? 'Location Balance After' : 'Balance After' ?>"><?= format_quantity($isLocationScoped ? $movement['location_balance_after'] : $movement['balance_after']) ?> <?= e($movement['unit']) ?></td>
                    <?php if ($isLocationScoped): ?>
                        <td data-label="Location Scope"><?= e($movement['location_scope_label']) ?></td>
                    <?php endif; ?>
                    <td data-label="From"><?= e($movement['source_storage_name'] ?: '-') ?></td>
                    <td data-label="To"><?= e($movement['destination_storage_name'] ?: '-') ?></td>
                    <td data-label="Reference"><?= e($movement['reference_code'] ?: '-') ?></td>
                    <td data-label="By"><?= e($movement['user_name'] ?: 'System') ?></td>
                    <td data-label="Notes"><?= e($movement['notes'] ?: '-') ?></td>
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
