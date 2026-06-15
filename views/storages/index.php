<?php $exportQuery = http_build_query(array_filter($filters, static fn ($value): bool => $value !== '' && $value !== null)); ?>

<section class="page-head">
    <div>
        <p class="eyebrow">Locations</p>
        <h3>Storages</h3>
    </div>
    <div class="page-actions">
        <a class="primary-button" href="<?= e(url('/storages/create')) ?>">Create Storage</a>
    </div>
</section>

<section class="filter-panel">
    <form class="filter-grid" method="get" action="<?= e(url('/storages')) ?>">
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
                <option value="active" <?= selected('active', $filters['status']) ?>>Active</option>
                <option value="archived" <?= selected('archived', $filters['status']) ?>>Archived</option>
                <option value="all" <?= selected('all', $filters['status']) ?>>All</option>
            </select>
        </label>

        <div class="filter-actions">
            <button class="primary-button" type="submit">Filter</button>
            <a class="ghost-button" href="<?= e(url('/storages')) ?>">Reset</a>
        </div>
    </form>

    <div class="chip-row">
        <span class="stat-chip">Active: <?= number_format($counts['active']) ?></span>
        <span class="stat-chip">Archived: <?= number_format($counts['archived']) ?></span>
    </div>
</section>

<section class="panel data-table-shell" data-table-shell data-empty-text="No storages match this search.">
    <div class="table-shell-head">
        <div class="table-heading">
            <strong>All Locations</strong>
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

        <a class="ghost-button table-export-button" href="<?= e(url('/exports/storages') . ($exportQuery ? '?' . $exportQuery : '')) ?>">Export CSV</a>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
            <tr>
                <th>Name</th>
                <th>Type</th>
                <th>Active Items</th>
                <th>Remaining</th>
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
                    <td colspan="9" class="empty-cell">No storages found.</td>
                </tr>
            <?php endif; ?>
            <?php foreach ($storages as $storage): ?>
                <tr>
                    <td>
                        <strong><?= e($storage['name']) ?></strong>
                        <div class="tiny-copy">Updated <?= e(date('M j, Y g:i A', strtotime($storage['updated_at']))) ?></div>
                    </td>
                    <td><?= e(storage_type_label($storage['storage_type'])) ?></td>
                    <td><?= number_format((int) $storage['active_item_count']) ?></td>
                    <td><?= format_quantity($storage['total_quantity']) ?></td>
                    <td><?= format_quantity($storage['total_used']) ?></td>
                    <td>
                        In <?= format_quantity($storage['transferred_in']) ?>
                        <div class="tiny-copy">Out <?= format_quantity($storage['transferred_out']) ?></div>
                    </td>
                    <td>
                        <span class="pill <?= (int) $storage['is_active'] === 1 ? 'pill-active' : 'pill-muted' ?>">
                            <?= (int) $storage['is_active'] === 1 ? 'Active' : 'Archived' ?>
                        </span>
                    </td>
                    <td><?= e($storage['notes'] ? truncate_text($storage['notes'], 72) : '-') ?></td>
                    <td>
                        <div class="inline-actions">
                            <a class="text-link" href="<?= e(url('/items?storage_id=' . $storage['id'])) ?>">Items</a>
                            <a class="text-link" href="<?= e(url('/storages/' . $storage['id'] . '/edit')) ?>">Edit</a>
                            <form method="post" action="<?= e(url('/storages/' . $storage['id'] . '/status')) ?>">
                                <?= csrf_field() ?>
                                <button class="text-button danger-link" type="submit" data-confirm="<?= (int) $storage['is_active'] === 1 ? 'Archive this storage?' : 'Restore this storage?' ?>">
                                    <?= (int) $storage['is_active'] === 1 ? 'Archive' : 'Restore' ?>
                                </button>
                            </form>
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
