<?php
$exportQuery = http_build_query(array_filter($filters, static fn ($value): bool => $value !== '' && $value !== null));
$storageFilterUrl = static function (string $status) use ($filters): string {
    $query = $filters;
    $query['status'] = $status;

    return url('/storages' . '?' . http_build_query(array_filter($query, static fn ($value): bool => $value !== '' && $value !== null)));
};
?>

<section class="page-head">
    <div class="page-head-copy">
        <p class="eyebrow"><?= e(site_setting('page.storages_eyebrow', 'Locations')) ?></p>
        <h3 class="page-head-title"><?= ui_icon('storages') ?><span><?= e(site_setting('page.storages', 'Storages')) ?></span></h3>
    </div>
    <div class="page-actions">
        <?php if (Auth::hasPermission('storages.create')): ?>
            <a class="primary-button" href="<?= e(url('/storages/create')) ?>"><?= ui_icon('plus') ?><span>Create Storage</span></a>
        <?php endif; ?>
    </div>
</section>

<div class="live-filter-region" data-live-filter-region="storages">
<section class="filter-panel">
    <form class="filter-grid" method="get" action="<?= e(url('/storages')) ?>" data-live-filter-form>
        <label class="field">
            <span>Search</span>
            <input type="text" name="search" value="<?= e($filters['search']) ?>" placeholder="Storage name or notes">
        </label>

        <label class="field">
            <span>Type</span>
            <select name="type">
                <option value="">All types</option>
                <option value="warehouse" <?= selected('warehouse', $filters['type']) ?>>Warehouse</option>
                <option value="storage" <?= selected('storage', $filters['type']) ?>>Storage</option>
            </select>
        </label>

            <label class="field">
                <span>Status</span>
                <select name="status">
                    <option value="all" <?= selected('all', $filters['status']) ?>>All</option>
                    <option value="active" <?= selected('active', $filters['status']) ?>>Active</option>
                    <option value="archived" <?= selected('archived', $filters['status']) ?>>Deleted</option>
                </select>
            </label>

        <div class="filter-actions">
            <button class="primary-button" type="submit"><?= ui_icon('filter') ?><span>Filter</span></button>
            <a class="ghost-button" href="<?= e(url('/storages')) ?>" data-live-filter-link><?= ui_icon('back') ?><span>Reset</span></a>
        </div>
    </form>

    <div class="chip-row">
        <a class="stat-chip filter-chip <?= $filters['status'] === 'all' ? 'filter-chip-active' : '' ?>" href="<?= e($storageFilterUrl('all')) ?>" data-live-filter-link>All: <?= number_format($counts['active'] + $counts['archived']) ?></a>
        <a class="stat-chip filter-chip <?= $filters['status'] === 'active' ? 'filter-chip-active' : '' ?>" href="<?= e($storageFilterUrl('active')) ?>" data-live-filter-link>Active: <?= number_format($counts['active']) ?></a>
        <a class="stat-chip filter-chip <?= $filters['status'] === 'archived' ? 'filter-chip-active' : '' ?>" href="<?= e($storageFilterUrl('archived')) ?>" data-live-filter-link>Deleted: <?= number_format($counts['archived']) ?></a>
    </div>

    <?php if (Auth::hasPermission('storages.export')): ?>
        <form class="filter-grid storage-export-picker" method="get" action="<?= e(url('/exports/storages')) ?>">
            <label class="field">
                <span>Export Storage Items</span>
                <select
                    name="storage_id"
                    data-combobox-select
                    data-combobox-class="filter-search-combobox"
                    data-combobox-placeholder="Search storage"
                    data-combobox-empty="No matching storages."
                >
                    <option value="" data-label-title="All storages" data-label-meta="Exports every storage and its items">All storages</option>
                    <?php foreach ($storageOptions as $option): ?>
                        <option
                            value="<?= e((string) $option['id']) ?>"
                            data-search-text="<?= e($option['name'] . ' ' . storage_type_label($option['storage_type']) . ' ' . ($option['owner_name'] ?? '')) ?>"
                            data-label-title="<?= e($option['name']) ?>"
                            data-label-meta="<?= e(storage_type_label($option['storage_type']) . (!empty($option['owner_name']) ? ' · Owner ' . $option['owner_name'] : '')) ?>"
                            <?= selected((string) $option['id'], (string) ($filters['storage_id'] ?? '')) ?>
                        >
                            <?= e($option['name']) ?> · <?= e(storage_type_label($option['storage_type'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="filter-actions">
                <?php if (storage_xlsx_thumbnail_export_enabled()): ?>
                    <button class="primary-button" type="submit" formaction="<?= e(url('/exports/storages.xlsx')) ?>" formmethod="get"><?= ui_icon('storages') ?><span>Export Excel</span></button>
                <?php endif; ?>
                <button class="ghost-button" type="submit" formaction="<?= e(url('/exports/storages')) ?>" formmethod="get"><?= ui_icon('export') ?><span>Export CSV</span></button>
            </div>
        </form>
    <?php endif; ?>
</section>

<section class="panel data-table-shell" data-table-shell data-empty-text="No storages match this search.">
    <div class="table-shell-head">
        <div class="table-heading">
            <strong><?= ui_icon('storages') ?><span><?= e(site_setting('table.storages', 'All Locations')) ?></span></strong>
            <span class="table-count-badge" data-table-total><?= number_format(count($storages)) ?></span>
        </div>
        <p class="table-shell-copy">Quick search, export, and scan remaining stock by warehouse or storage.</p>
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
                <span class="sr-only">Search storages</span>
                <input type="search" data-table-search placeholder="Search location name, type, notes, or status">
            </label>
        </div>

        <div class="button-row">
            <?php if (storage_xlsx_thumbnail_export_enabled()): ?>
                <a class="ghost-button table-export-button" href="<?= e(url('/exports/storages.xlsx') . ($exportQuery ? '?' . $exportQuery : '')) ?>"><?= ui_icon('storages') ?><span>Export Excel</span></a>
            <?php endif; ?>
            <a class="ghost-button table-export-button" href="<?= e(url('/exports/storages') . ($exportQuery ? '?' . $exportQuery : '')) ?>"><?= ui_icon('export') ?><span>Export CSV</span></a>
        </div>
    </div>

    <div class="table-wrap">
        <table class="data-table data-table-mobile">
            <thead>
            <tr>
                <th>Name</th>
                <th>Type</th>
                <th>Assigned Items</th>
                <th>Remaining</th>
                <th>Value</th>
                <th>Used</th>
                <th>Transfers</th>
                <th>Status</th>
                <th>Notes</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if ($storages === []): ?>
                <tr>
                    <td colspan="10" class="empty-cell">No storages found.</td>
                </tr>
            <?php endif; ?>
            <?php foreach ($storages as $storage): ?>
                <tr>
                    <td data-label="Name">
                        <a class="cell-link cell-link-compact" href="<?= e(url('/storages/' . $storage['id'])) ?>">
                            <strong><?= e($storage['name']) ?></strong>
                            <div class="tiny-copy">Updated <?= e(date('M j, Y g:i A', strtotime($storage['updated_at']))) ?></div>
                        </a>
                    </td>
                    <td data-label="Type"><?= e(storage_type_label($storage['storage_type'])) ?></td>
                    <td data-label="Assigned Items">
                        <?= number_format((int) $storage['assigned_item_count']) ?>
                        <div class="tiny-copy">With stock <?= number_format((int) $storage['stocked_item_count']) ?></div>
                    </td>
                    <td data-label="Remaining"><?= format_quantity($storage['total_quantity']) ?></td>
                    <td data-label="Value"><?= format_money($storage['total_stock_value']) ?></td>
                    <td data-label="Used"><?= format_quantity($storage['total_used']) ?></td>
                    <td data-label="Transfers">
                        In <?= format_quantity($storage['transferred_in']) ?>
                        <div class="tiny-copy">Out <?= format_quantity($storage['transferred_out']) ?></div>
                    </td>
                    <td data-label="Status">
                        <span class="pill <?= (int) $storage['is_active'] === 1 ? 'pill-active' : 'pill-muted' ?>">
                            <?= (int) $storage['is_active'] === 1 ? 'Active' : 'Deleted' ?>
                        </span>
                    </td>
                    <td data-label="Notes"><?= e($storage['notes'] ? truncate_text($storage['notes'], 72) : '-') ?></td>
                    <td data-label="Actions" class="table-actions-cell">
                        <details class="row-action-menu">
                            <summary aria-label="Storage actions"><?= ui_icon('menu') ?></summary>
                            <div class="row-action-list">
                                <a href="<?= e(url('/storages/' . $storage['id'])) ?>"><?= ui_icon('storages') ?><span>Open</span></a>
                                <?php if (Auth::hasPermission('items.view')): ?>
                                    <a href="<?= e(url('/items?storage_id=' . $storage['id'])) ?>"><?= ui_icon('items') ?><span>Items</span></a>
                                <?php endif; ?>
                                <?php if (Auth::hasPermission('storages.export')): ?>
                                    <?php if (storage_xlsx_thumbnail_export_enabled()): ?>
                                        <a href="<?= e(url('/exports/storages.xlsx?storage_id=' . $storage['id'])) ?>"><?= ui_icon('storages') ?><span>Export Excel</span></a>
                                    <?php endif; ?>
                                    <a href="<?= e(url('/exports/storages?storage_id=' . $storage['id'])) ?>"><?= ui_icon('export') ?><span>Export CSV</span></a>
                                <?php endif; ?>
                                <?php if (Auth::hasPermission('storages.edit')): ?>
                                    <a href="<?= e(url('/storages/' . $storage['id'] . '/edit')) ?>"><?= ui_icon('edit') ?><span>Edit</span></a>
                                <?php endif; ?>
                                <?php if (Auth::hasPermission('storages.copy') && Auth::hasPermission('storages.create')): ?>
                                    <a href="<?= e(url('/storages/create?copy=' . $storage['id'])) ?>"><?= ui_icon('copy_action') ?><span>Copy</span></a>
                                <?php endif; ?>
                                <?php if (Auth::hasPermission('storages.archive')): ?>
                                    <form method="post" action="<?= e(url('/storages/' . $storage['id'] . '/status')) ?>" data-live-action-form>
                                        <?= csrf_field() ?>
                                        <button class="danger-link" type="submit" data-confirm="<?= (int) $storage['is_active'] === 1 ? 'Delete this location? You can recover it later.' : 'Recover this location?' ?>">
                                            <?= ui_icon('reorder') ?><span><?= (int) $storage['is_active'] === 1 ? 'Delete' : 'Recover' ?></span>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </details>
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
