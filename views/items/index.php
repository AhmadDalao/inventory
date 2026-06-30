<?php
$exportQuery = http_build_query(array_filter($filters, static fn ($value): bool => $value !== '' && $value !== null));
$currentListPath = '/items' . ($exportQuery ? '?' . $exportQuery : '');
$itemFilterUrl = static function (string $status) use ($filters): string {
    $query = $filters;
    $query['status'] = $status;

    return url('/items' . '?' . http_build_query(array_filter($query, static fn ($value): bool => $value !== '' && $value !== null)));
};
?>

<section class="page-head">
    <div class="page-head-copy">
        <p class="eyebrow"><?= e(site_setting('page.items_eyebrow', 'Catalog')) ?></p>
        <h3 class="page-head-title"><?= ui_icon('items') ?><span><?= e(site_setting('page.items', 'Items')) ?></span></h3>
    </div>
    <div class="page-actions">
        <?php if (Auth::hasPermission('items.create')): ?>
            <a class="primary-button" href="<?= e(url('/items/create')) ?>"><?= ui_icon('plus') ?><span>Create Item</span></a>
        <?php endif; ?>
    </div>
</section>

<div class="live-filter-region" data-live-filter-region="items">
<section class="filter-panel">
    <form class="filter-grid" method="get" action="<?= e(url('/items')) ?>" data-live-filter-form>
        <label class="field">
            <span>Search</span>
            <input type="text" name="search" value="<?= e($filters['search']) ?>" placeholder="Name, SKU, barcode, or storage">
        </label>

            <label class="field">
                <span>Status</span>
                <select name="status">
                    <option value="all" <?= selected('all', $filters['status']) ?>>All</option>
                    <option value="active" <?= selected('active', $filters['status']) ?>>Active</option>
                    <option value="archived" <?= selected('archived', $filters['status']) ?>>Deleted</option>
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

        <div class="filter-actions">
            <button class="primary-button" type="submit"><?= ui_icon('filter') ?><span>Filter</span></button>
            <a class="ghost-button" href="<?= e(url('/items')) ?>" data-live-filter-link><?= ui_icon('back') ?><span>Reset</span></a>
        </div>
    </form>

    <div class="chip-row">
        <a class="stat-chip filter-chip <?= $filters['status'] === 'all' ? 'filter-chip-active' : '' ?>" href="<?= e($itemFilterUrl('all')) ?>" data-live-filter-link>All: <?= number_format($counts['active'] + $counts['archived']) ?></a>
        <a class="stat-chip filter-chip <?= $filters['status'] === 'active' ? 'filter-chip-active' : '' ?>" href="<?= e($itemFilterUrl('active')) ?>" data-live-filter-link>Active: <?= number_format($counts['active']) ?></a>
        <a class="stat-chip filter-chip <?= $filters['status'] === 'archived' ? 'filter-chip-active' : '' ?>" href="<?= e($itemFilterUrl('archived')) ?>" data-live-filter-link>Deleted: <?= number_format($counts['archived']) ?></a>
        <?php if (!empty($selectedStorage)): ?>
            <span class="stat-chip"><?= e(storage_type_label($selectedStorage['storage_type'])) ?>: <?= e($selectedStorage['name']) ?></span>
            <span class="stat-chip">Remove here = this location only</span>
        <?php endif; ?>
    </div>
</section>

<section class="panel data-table-shell" data-table-shell data-empty-text="No items match this search.">
    <div class="table-shell-head">
        <div class="table-heading">
            <strong><?= ui_icon('items') ?><span><?= e(site_setting('table.items', 'All Items')) ?></span></strong>
            <span class="table-count-badge" data-table-total><?= number_format(count($items)) ?></span>
        </div>
        <p class="table-shell-copy">Search the current result set, page through it, and export the filtered catalog.</p>
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
                <span class="sr-only">Search items</span>
                <input type="search" data-table-search placeholder="Search items, SKU, barcode, category, unit, or location">
            </label>
        </div>

        <div class="button-row">
            <?php if (item_xlsx_thumbnail_export_enabled()): ?>
                <a class="ghost-button table-export-button" href="<?= e(url('/exports/items.xlsx') . ($exportQuery ? '?' . $exportQuery : '')) ?>"><?= ui_icon('items') ?><span>Export Excel</span></a>
            <?php endif; ?>
            <a class="ghost-button table-export-button" href="<?= e(url('/exports/items') . ($exportQuery ? '?' . $exportQuery : '')) ?>"><?= ui_icon('export') ?><span>Export CSV</span></a>
        </div>
    </div>

    <div class="table-wrap">
        <table class="data-table data-table-mobile">
            <thead>
            <tr>
                <th>Item</th>
                <th>SKU</th>
                <th>Barcode</th>
                <th>Locations</th>
                <th>Current</th>
                <th>Reorder</th>
                <th>Status</th>
                <th>Last Movement</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if ($items === []): ?>
                <tr>
                    <td colspan="9" class="empty-cell">No items found.</td>
                </tr>
            <?php endif; ?>
            <?php foreach ($items as $item): ?>
                <?php $currentQuantity = item_display_quantity($item); ?>
                <?php $isLow = $currentQuantity <= (float) $item['reorder_level']; ?>
                <?php $imageUrl = item_image_url($item['image_path'] ?? null); ?>
                <tr>
                    <td data-label="Item">
                        <a class="item-table-cell cell-link" href="<?= e(url('/items/' . $item['id'])) ?>">
                            <?php if ($imageUrl): ?>
                                <img
                                    class="item-thumb expandable-image"
                                    src="<?= e($imageUrl) ?>"
                                    alt="<?= e($item['name']) ?>"
                                    data-expand-image
                                    tabindex="0"
                                >
                            <?php else: ?>
                                <span class="item-thumb item-thumb-fallback"><?= e(item_initial($item['name'])) ?></span>
                            <?php endif; ?>

                            <div>
                                <strong><?= e($item['name']) ?></strong>
                                <div class="tiny-copy"><?= e($item['unit']) ?></div>
                            </div>
                        </a>
                    </td>
                    <td data-label="SKU"><?= e($item['sku']) ?></td>
                    <td data-label="Barcode">
                        <?php if (normalize_item_barcode($item['barcode'] ?? '') !== ''): ?>
                            <code><?= e((string) $item['barcode']) ?></code>
                        <?php else: ?>
                            <span class="tiny-copy">Uses SKU label</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Locations">
                        <?php if ((int) ($item['location_count'] ?? 0) === 0): ?>
                            <span class="tiny-copy">No assigned locations</span>
                        <?php else: ?>
                            <strong><?= number_format((int) $item['location_count']) ?> assigned location<?= (int) ($item['location_count'] ?? 0) === 1 ? '' : 's' ?></strong>
                            <div class="tiny-copy"><?= e(truncate_text($item['storage_summary'] ?: '', 52)) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="<?= $isLow ? 'danger-text' : '' ?>" data-label="Current">
                        <?= format_quantity($currentQuantity) ?> <?= e($item['unit']) ?>
                        <?php if ($selectedStorage): ?>
                            <div class="tiny-copy">in <?= e($selectedStorage['name']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td data-label="Reorder"><?= format_quantity($item['reorder_level']) ?> <?= e($item['unit']) ?></td>
                    <td data-label="Status">
                        <span class="pill <?= (int) $item['is_active'] === 1 ? 'pill-active' : 'pill-muted' ?>">
                            <?= (int) $item['is_active'] === 1 ? 'Active' : 'Deleted' ?>
                        </span>
                    </td>
                    <td data-label="Last Movement"><?= $item['last_movement_at'] ? e(date('M j, Y g:i A', strtotime($item['last_movement_at']))) : 'Never' ?></td>
                    <td data-label="Actions">
                        <div class="inline-actions">
                            <a class="text-link" href="<?= e(url('/items/' . $item['id'])) ?>">Open</a>
                            <?php if (Auth::hasPermission('items.edit')): ?>
                                <a class="text-link" href="<?= e(url('/items/' . $item['id'] . '/edit')) ?>">Edit</a>
                            <?php endif; ?>
                            <?php if (Auth::hasPermission('items.copy') && Auth::hasPermission('items.create')): ?>
                                <a class="text-link" href="<?= e(url('/items/create?copy=' . $item['id'])) ?>">Copy</a>
                            <?php endif; ?>
                            <?php if (!empty($selectedStorage) && (int) $item['is_active'] === 1 && Auth::hasPermission('items.remove_from_storage')): ?>
                                <form method="post" action="<?= e(url('/items/' . $item['id'] . '/locations/' . $selectedStorage['id'] . '/remove')) ?>" data-live-action-form>
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="return_to" value="<?= e($currentListPath) ?>">
                                    <button class="text-button danger-link" type="submit" data-confirm="Remove <?= e($item['name']) ?> from <?= e($selectedStorage['name']) ?> only? Other storages keep their quantities.">
                                        Remove Here
                                    </button>
                                </form>
                            <?php endif; ?>
                            <?php if (Auth::hasPermission('items.archive')): ?>
                                <form method="post" action="<?= e(url('/items/' . $item['id'] . '/status')) ?>" data-live-action-form>
                                    <?= csrf_field() ?>
                                    <button class="text-button danger-link" type="submit" data-confirm="<?= (int) $item['is_active'] === 1 ? 'Archive this shared item? This affects every storage that still has it.' : 'Recover this item?' ?>">
                                        <?= (int) $item['is_active'] === 1 ? 'Archive' : 'Recover' ?>
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
