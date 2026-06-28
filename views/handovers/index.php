<?php
$exportQuery = http_build_query(array_filter($filters, static fn ($value): bool => $value !== '' && $value !== null));
$handoverFilterUrl = static function (string $status) use ($filters): string {
    $query = $filters;
    $query['status'] = $status;

    return url('/handovers?' . http_build_query(array_filter($query, static fn ($value): bool => $value !== '' && $value !== null)));
};
?>

<section class="page-head">
    <div class="page-head-copy">
        <p class="eyebrow"><?= e(site_setting('page.handovers_eyebrow', 'Temporary item issue')) ?></p>
        <h3 class="page-head-title"><?= ui_icon('handover') ?><span><?= e(site_setting('page.handovers', 'Handovers')) ?></span></h3>
    </div>
    <div class="page-actions">
        <?php if (Auth::hasPermission('handovers.create') || Auth::hasPermission('handovers.request')): ?>
            <a class="primary-button" href="<?= e(url('/handovers/create')) ?>"><?= ui_icon('plus') ?><span><?= Auth::isStaff() ? 'Request Handover' : 'Create Handover' ?></span></a>
        <?php endif; ?>
    </div>
</section>

<div class="live-filter-region" data-live-filter-region="handovers">
<section class="filter-panel">
    <form class="filter-grid" method="get" action="<?= e(url('/handovers')) ?>" data-live-filter-form>
        <label class="field">
            <span>Search</span>
            <input type="text" name="search" value="<?= e($filters['search']) ?>" placeholder="Number, recipient, storage, item">
        </label>

        <label class="field">
            <span>Status</span>
            <select name="status">
                <option value="all" <?= selected('all', $filters['status']) ?>>All</option>
                <option value="open" <?= selected('open', $filters['status']) ?>>Open</option>
                <option value="requested" <?= selected('requested', $filters['status']) ?>>Requested</option>
                <option value="awaiting_receipt" <?= selected('awaiting_receipt', $filters['status']) ?>>Awaiting Receipt</option>
                <option value="receipt_review" <?= selected('receipt_review', $filters['status']) ?>>Receipt Review</option>
                <option value="delivered" <?= selected('delivered', $filters['status']) ?>>Delivered</option>
                <option value="pending_approval" <?= selected('pending_approval', $filters['status']) ?>>Waiting Approval</option>
                <option value="closed" <?= selected('closed', $filters['status']) ?>>Closed</option>
                <option value="rejected" <?= selected('rejected', $filters['status']) ?>>Rejected</option>
                <option value="cancelled" <?= selected('cancelled', $filters['status']) ?>>Cancelled</option>
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
            <a class="ghost-button" href="<?= e(url('/handovers')) ?>" data-live-filter-link><?= ui_icon('back') ?><span>Reset</span></a>
        </div>
    </form>

    <div class="chip-row">
        <a class="stat-chip filter-chip <?= $filters['status'] === 'all' ? 'filter-chip-active' : '' ?>" href="<?= e($handoverFilterUrl('all')) ?>" data-live-filter-link>All: <?= number_format($counts['open'] + $counts['closed'] + $counts['rejected'] + $counts['cancelled']) ?></a>
        <a class="stat-chip filter-chip <?= $filters['status'] === 'open' ? 'filter-chip-active' : '' ?>" href="<?= e($handoverFilterUrl('open')) ?>" data-live-filter-link>Open: <?= number_format($counts['open']) ?></a>
        <a class="stat-chip filter-chip <?= $filters['status'] === 'requested' ? 'filter-chip-active' : '' ?>" href="<?= e($handoverFilterUrl('requested')) ?>" data-live-filter-link>Requested: <?= number_format($counts['requested']) ?></a>
        <a class="stat-chip filter-chip <?= $filters['status'] === 'awaiting_receipt' ? 'filter-chip-active' : '' ?>" href="<?= e($handoverFilterUrl('awaiting_receipt')) ?>" data-live-filter-link>Awaiting Receipt: <?= number_format($counts['awaiting_receipt']) ?></a>
        <a class="stat-chip filter-chip <?= $filters['status'] === 'receipt_review' ? 'filter-chip-active' : '' ?>" href="<?= e($handoverFilterUrl('receipt_review')) ?>" data-live-filter-link>Receipt Review: <?= number_format($counts['receipt_review']) ?></a>
        <a class="stat-chip filter-chip <?= $filters['status'] === 'delivered' ? 'filter-chip-active' : '' ?>" href="<?= e($handoverFilterUrl('delivered')) ?>" data-live-filter-link>Delivered: <?= number_format($counts['delivered']) ?></a>
        <a class="stat-chip filter-chip <?= $filters['status'] === 'pending_approval' ? 'filter-chip-active' : '' ?>" href="<?= e($handoverFilterUrl('pending_approval')) ?>" data-live-filter-link>Waiting Approval: <?= number_format($counts['pending_approval']) ?></a>
        <a class="stat-chip filter-chip <?= $filters['status'] === 'closed' ? 'filter-chip-active' : '' ?>" href="<?= e($handoverFilterUrl('closed')) ?>" data-live-filter-link>Closed: <?= number_format($counts['closed']) ?></a>
        <a class="stat-chip filter-chip <?= $filters['status'] === 'rejected' ? 'filter-chip-active' : '' ?>" href="<?= e($handoverFilterUrl('rejected')) ?>" data-live-filter-link>Rejected: <?= number_format($counts['rejected']) ?></a>
        <a class="stat-chip filter-chip <?= $filters['status'] === 'cancelled' ? 'filter-chip-active' : '' ?>" href="<?= e($handoverFilterUrl('cancelled')) ?>" data-live-filter-link>Cancelled: <?= number_format($counts['cancelled']) ?></a>
    </div>
</section>

<section class="panel data-table-shell" data-table-shell data-empty-text="No handovers match this search.">
    <div class="table-shell-head">
        <div class="table-heading">
            <strong><?= ui_icon('handover') ?><span><?= e(site_setting('table.handovers', 'All Handovers')) ?></span></strong>
            <span class="table-count-badge" data-table-total><?= number_format(count($handovers)) ?></span>
        </div>
        <p class="table-shell-copy">Track temporary-use requests, issued stock, used quantities, and what came back.</p>
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
                <span class="sr-only">Search handovers</span>
                <input type="search" data-table-search placeholder="Search handover number, recipient, item, or storage">
            </label>
        </div>

        <?php if (Auth::hasPermission('handovers.export')): ?>
            <a class="ghost-button table-export-button" href="<?= e(url('/exports/handovers') . ($exportQuery ? '?' . $exportQuery : '')) ?>"><?= ui_icon('export') ?><span>Export CSV</span></a>
        <?php endif; ?>
    </div>

    <div class="table-wrap">
        <table class="data-table data-table-mobile">
            <thead>
            <tr>
                <th>Handover</th>
                <th>Storage</th>
                <th>Recipient</th>
                <th>Items</th>
                <th>Planned</th>
                <th>Used</th>
                <th>Returned</th>
                <th>Status</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if ($handovers === []): ?>
                <tr>
                    <td colspan="9" class="empty-cell">No handovers found.</td>
                </tr>
            <?php endif; ?>
            <?php foreach ($handovers as $handover): ?>
                <tr>
                    <td data-label="Handover">
                        <a class="cell-link cell-link-compact" href="<?= e(url('/handovers/' . $handover['id'])) ?>">
                            <strong><?= e($handover['handover_number']) ?></strong>
                            <div class="tiny-copy"><?= e(format_datetime_display((string) $handover['issued_at'])) ?></div>
                        </a>
                    </td>
                    <td data-label="Storage"><?= e($handover['source_storage_name']) ?></td>
                    <td data-label="Recipient"><?= e($handover['recipient_name']) ?></td>
                    <td data-label="Items"><?= number_format((int) $handover['line_count']) ?></td>
                    <td data-label="Planned"><?= format_quantity($handover['total_handed']) ?></td>
                    <td data-label="Used"><?= format_quantity($handover['total_used']) ?></td>
                    <td data-label="Returned"><?= format_quantity($handover['total_returned']) ?></td>
                    <td data-label="Status"><span class="pill pill-<?= e((string) $handover['status']) ?>"><?= e(handover_status_label((string) $handover['status'])) ?></span></td>
                    <td data-label="Actions"><a class="text-link" href="<?= e(url('/handovers/' . $handover['id'])) ?>">Open</a></td>
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
