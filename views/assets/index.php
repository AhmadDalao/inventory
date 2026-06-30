<?php
$exportQuery = http_build_query(array_filter($filters, static fn ($value): bool => $value !== '' && $value !== null));
$pageTitle = Auth::isStaff() ? 'My Assets' : site_setting('page.assets', 'Assets');
$pageEyebrow = Auth::isStaff() ? 'Assigned property' : site_setting('page.assets_eyebrow', 'Company property');
$assetFilterUrl = static function (string $status) use ($filters): string {
    $query = $filters;
    $query['status'] = $status;

    return url('/assets?' . http_build_query(array_filter($query, static fn ($value): bool => $value !== '' && $value !== null)));
};
?>

<section class="page-head">
    <div class="page-head-copy">
        <p class="eyebrow"><?= e($pageEyebrow) ?></p>
        <h3 class="page-head-title"><?= ui_icon('assets') ?><span><?= e($pageTitle) ?></span></h3>
    </div>
    <div class="page-actions">
        <?php if (Auth::hasPermission('assets.create') && !Auth::isStaff()): ?>
            <a class="primary-button" href="<?= e(url('/assets/create')) ?>"><?= ui_icon('plus') ?><span>Create Asset</span></a>
        <?php endif; ?>
    </div>
</section>

<div class="live-filter-region" data-live-filter-region="assets">
    <section class="filter-panel">
        <form class="filter-grid" method="get" action="<?= e(url('/assets')) ?>" data-live-filter-form>
            <label class="field">
                <span>Search</span>
                <input type="text" name="search" value="<?= e($filters['search']) ?>" placeholder="Asset number, name, barcode, serial">
            </label>

            <label class="field">
                <span>Status</span>
                <select name="status">
                    <option value="all" <?= selected('all', $filters['status']) ?>>All statuses</option>
                    <?php foreach (asset_status_options() as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= selected($value, $filters['status']) ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="field">
                <span>Condition</span>
                <select name="condition">
                    <option value="all" <?= selected('all', $filters['condition']) ?>>All conditions</option>
                    <?php foreach (asset_condition_options() as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= selected($value, $filters['condition']) ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <?php if (!Auth::isStaff()): ?>
                <label class="field">
                    <span>Location</span>
                    <select name="storage_id">
                        <option value="">All locations</option>
                        <?php foreach ($storages as $storage): ?>
                            <option value="<?= e((string) $storage['id']) ?>" <?= selected((string) $storage['id'], (string) ($filters['storage_id'] ?? '')) ?>>
                                <?= e(storage_type_label($storage['storage_type'])) ?> - <?= e($storage['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="field">
                    <span>Assigned to</span>
                    <select name="assigned_user_id">
                        <option value="">Anyone</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?= e((string) $user['id']) ?>" <?= selected((string) $user['id'], (string) ($filters['assigned_user_id'] ?? '')) ?>>
                                <?= e($user['name']) ?> - <?= e(user_role_label((string) $user['role'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="field">
                    <span>Record</span>
                    <select name="active">
                        <option value="all" <?= selected('all', $filters['active']) ?>>All records</option>
                        <option value="active" <?= selected('active', $filters['active']) ?>>Active only</option>
                        <option value="archived" <?= selected('archived', $filters['active']) ?>>Deleted only</option>
                    </select>
                </label>
            <?php endif; ?>

            <div class="filter-actions">
                <button class="primary-button" type="submit"><?= ui_icon('filter') ?><span>Filter</span></button>
                <a class="ghost-button" href="<?= e(url('/assets')) ?>" data-live-filter-link><?= ui_icon('back') ?><span>Reset</span></a>
            </div>
        </form>

        <div class="chip-row">
            <a class="stat-chip filter-chip <?= $filters['status'] === 'all' ? 'filter-chip-active' : '' ?>" href="<?= e($assetFilterUrl('all')) ?>" data-live-filter-link>All: <?= number_format($counts['total']) ?></a>
            <a class="stat-chip filter-chip <?= $filters['status'] === 'available' ? 'filter-chip-active' : '' ?>" href="<?= e($assetFilterUrl('available')) ?>" data-live-filter-link>Available: <?= number_format($counts['available']) ?></a>
            <a class="stat-chip filter-chip <?= $filters['status'] === 'assigned' ? 'filter-chip-active' : '' ?>" href="<?= e($assetFilterUrl('assigned')) ?>" data-live-filter-link>Assigned: <?= number_format($counts['assigned']) ?></a>
            <span class="stat-chip">Maintenance: <?= number_format($counts['maintenance']) ?></span>
            <span class="stat-chip">Value: <?= e(format_money($counts['value'])) ?></span>
        </div>
    </section>

    <section class="metric-grid">
        <article class="metric-card metric-card-dark">
            <span>Total Assets</span>
            <strong><?= number_format($counts['active']) ?></strong>
            <small>Active records</small>
        </article>
        <article class="metric-card">
            <span>Assigned</span>
            <strong><?= number_format($counts['assigned']) ?></strong>
            <small>With staff or pending receipt</small>
        </article>
        <article class="metric-card">
            <span>Available</span>
            <strong><?= number_format($counts['available']) ?></strong>
            <small>Ready to issue</small>
        </article>
        <article class="metric-card">
            <span>Maintenance</span>
            <strong><?= number_format($counts['maintenance']) ?></strong>
            <small>Repair or service</small>
        </article>
    </section>

    <section class="panel data-table-shell" data-table-shell data-empty-text="No assets match this search.">
        <div class="table-shell-head">
            <div class="table-heading">
                <strong><?= ui_icon('assets') ?><span><?= e($pageTitle) ?></span></strong>
                <span class="table-count-badge" data-table-total><?= number_format(count($assets)) ?></span>
            </div>
            <p class="table-shell-copy">Track serials, custody, condition, warranty, files, and maintenance.</p>
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
                    <span class="sr-only">Search assets</span>
                    <input type="search" data-table-search placeholder="Search asset number, barcode, serial, location, user">
                </label>
            </div>

            <?php if (Auth::hasPermission('assets.export') && !Auth::isStaff()): ?>
                <div class="button-row">
                    <a class="ghost-button table-export-button" href="<?= e(url('/exports/assets.xlsx') . ($exportQuery ? '?' . $exportQuery : '')) ?>"><?= ui_icon('items') ?><span>Export Excel</span></a>
                    <a class="ghost-button table-export-button" href="<?= e(url('/exports/assets') . ($exportQuery ? '?' . $exportQuery : '')) ?>"><?= ui_icon('export') ?><span>Export CSV</span></a>
                </div>
            <?php endif; ?>
        </div>

        <div class="table-wrap">
            <table class="data-table data-table-mobile">
                <thead>
                <tr>
                    <th>Asset</th>
                    <th>Scan Code</th>
                    <th>Serial</th>
                    <th>Custody</th>
                    <th>Status</th>
                    <th>Condition</th>
                    <th>Value</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php if ($assets === []): ?>
                    <tr>
                        <td colspan="8" class="empty-cell">No assets found.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($assets as $asset): ?>
                    <?php $imageUrl = asset_image_url($asset['image_path'] ?? null); ?>
                    <tr>
                        <td data-label="Asset">
                            <a class="item-table-cell cell-link" href="<?= e(url('/assets/' . $asset['id'])) ?>">
                                <?php if ($imageUrl): ?>
                                    <img class="item-thumb expandable-image" src="<?= e($imageUrl) ?>" alt="<?= e($asset['name']) ?>" data-expand-image tabindex="0">
                                <?php else: ?>
                                    <span class="item-thumb item-thumb-fallback"><?= e(asset_initial($asset['name'])) ?></span>
                                <?php endif; ?>
                                <div>
                                    <strong><?= e($asset['name']) ?></strong>
                                    <div class="tiny-copy"><?= e($asset['asset_number']) ?><?= $asset['model'] ? ' - ' . e($asset['model']) : '' ?></div>
                                </div>
                            </a>
                        </td>
                        <td data-label="Scan Code"><code><?= e(asset_scan_code($asset)) ?></code></td>
                        <td data-label="Serial"><?= e($asset['serial_number'] ?: 'Not set') ?></td>
                        <td data-label="Custody">
                            <strong><?= e($asset['assigned_user_name'] ?: ($asset['storage_name'] ?: 'Unassigned')) ?></strong>
                            <div class="tiny-copy"><?= $asset['assigned_user_name'] ? 'Assigned user' : e($asset['storage_name'] ? storage_type_label((string) $asset['storage_type']) : 'No location') ?></div>
                        </td>
                        <td data-label="Status"><span class="pill <?= e(asset_status_tone((string) $asset['status'])) ?>"><?= e(asset_status_label((string) $asset['status'])) ?></span></td>
                        <td data-label="Condition"><?= e(asset_condition_label((string) $asset['condition_status'])) ?></td>
                        <td data-label="Value"><?= e(format_money($asset['purchase_cost'])) ?></td>
                        <td data-label="Actions">
                            <div class="inline-actions">
                                <a class="text-link" href="<?= e(url('/assets/' . $asset['id'])) ?>">Open</a>
                                <?php if (Auth::hasPermission('assets.edit') && !Auth::isStaff()): ?>
                                    <a class="text-link" href="<?= e(url('/assets/' . $asset['id'] . '/edit')) ?>">Edit</a>
                                <?php endif; ?>
                                <?php if (Auth::hasPermission('assets.archive') && !Auth::isStaff()): ?>
                                    <form method="post" action="<?= e(url('/assets/' . $asset['id'] . '/status')) ?>" data-live-action-form>
                                        <?= csrf_field() ?>
                                        <button class="text-button danger-link" type="submit" data-confirm="<?= (int) $asset['is_active'] === 1 ? 'Archive this asset?' : 'Recover this asset?' ?>">
                                            <?= (int) $asset['is_active'] === 1 ? 'Archive' : 'Recover' ?>
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
