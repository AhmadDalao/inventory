<?php
$exportQuery = http_build_query(array_filter($filters, static fn ($value): bool => $value !== '' && $value !== null));
$requestFilterUrl = static function (string $status) use ($filters): string {
    $query = $filters;
    $query['status'] = $status;

    return url('/requests?' . http_build_query(array_filter($query, static fn ($value): bool => $value !== '' && $value !== null)));
};
?>

<section class="page-head">
    <div class="page-head-copy">
        <p class="eyebrow"><?= e(site_setting('page.requests_eyebrow', 'Transfers & approvals')) ?></p>
        <h3 class="page-head-title"><?= ui_icon('requests') ?><span><?= e(site_setting('page.requests', 'Requests')) ?></span></h3>
    </div>
    <div class="page-actions">
        <?php if (Auth::hasPermission('requests.create')): ?>
            <a class="primary-button" href="<?= e(url('/requests/create')) ?>"><?= ui_icon('plus') ?><span>Create Request</span></a>
        <?php endif; ?>
    </div>
</section>

<div class="live-filter-region" data-live-filter-region="requests">
<section class="filter-panel">
    <form class="filter-grid" method="get" action="<?= e(url('/requests')) ?>" data-live-filter-form>
        <label class="field">
            <span>Search</span>
            <input type="text" name="search" value="<?= e($filters['search']) ?>" placeholder="Number, user, storage, item">
        </label>

        <label class="field">
            <span>Status</span>
            <select name="status">
                <option value="all" <?= selected('all', $filters['status']) ?>>All</option>
                <option value="open" <?= selected('open', $filters['status']) ?>>Open</option>
                <option value="draft" <?= selected('draft', $filters['status']) ?>>Draft</option>
                <option value="pending" <?= selected('pending', $filters['status']) ?>>Pending</option>
                <option value="approved" <?= selected('approved', $filters['status']) ?>>Approved</option>
                <option value="receipt_review" <?= selected('receipt_review', $filters['status']) ?>>Receipt Review</option>
                <option value="completed" <?= selected('completed', $filters['status']) ?>>Completed</option>
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
            <a class="ghost-button" href="<?= e(url('/requests')) ?>" data-live-filter-link><?= ui_icon('back') ?><span>Reset</span></a>
        </div>
    </form>

    <div class="chip-row">
        <a class="stat-chip filter-chip <?= $filters['status'] === 'all' ? 'filter-chip-active' : '' ?>" href="<?= e($requestFilterUrl('all')) ?>" data-live-filter-link>All: <?= number_format($counts['draft'] + $counts['open'] + $counts['completed'] + $counts['rejected'] + $counts['cancelled']) ?></a>
        <a class="stat-chip filter-chip <?= $filters['status'] === 'draft' ? 'filter-chip-active' : '' ?>" href="<?= e($requestFilterUrl('draft')) ?>" data-live-filter-link>Draft: <?= number_format($counts['draft']) ?></a>
        <a class="stat-chip filter-chip <?= $filters['status'] === 'open' ? 'filter-chip-active' : '' ?>" href="<?= e($requestFilterUrl('open')) ?>" data-live-filter-link>Open: <?= number_format($counts['open']) ?></a>
        <a class="stat-chip filter-chip <?= $filters['status'] === 'completed' ? 'filter-chip-active' : '' ?>" href="<?= e($requestFilterUrl('completed')) ?>" data-live-filter-link>Completed: <?= number_format($counts['completed']) ?></a>
        <a class="stat-chip filter-chip <?= $filters['status'] === 'rejected' ? 'filter-chip-active' : '' ?>" href="<?= e($requestFilterUrl('rejected')) ?>" data-live-filter-link>Rejected: <?= number_format($counts['rejected']) ?></a>
        <a class="stat-chip filter-chip <?= $filters['status'] === 'cancelled' ? 'filter-chip-active' : '' ?>" href="<?= e($requestFilterUrl('cancelled')) ?>" data-live-filter-link>Cancelled: <?= number_format($counts['cancelled']) ?></a>
    </div>
</section>

<section class="panel data-table-shell" data-table-shell data-empty-text="No requests match this search.">
    <div class="table-shell-head">
        <div class="table-heading">
            <strong><?= ui_icon('requests') ?><span><?= e(site_setting('table.requests', 'All Requests')) ?></span></strong>
            <span class="table-count-badge" data-table-total><?= number_format(count($requests)) ?></span>
        </div>
        <p class="table-shell-copy">Track pending approvals, items in transit, and completed receipts.</p>
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
                <span class="sr-only">Search requests</span>
                <input type="search" data-table-search placeholder="Search request number, item, user, or storage">
            </label>
        </div>

        <?php if (Auth::hasPermission('requests.export')): ?>
            <a class="ghost-button table-export-button" href="<?= e(url('/exports/requests') . ($exportQuery ? '?' . $exportQuery : '')) ?>"><?= ui_icon('export') ?><span>Export CSV</span></a>
        <?php endif; ?>
    </div>

    <div class="table-wrap">
        <table class="data-table data-table-mobile">
            <thead>
            <tr>
                <th>Request</th>
                <th>Route</th>
                <th>Requester</th>
                <th>Approver</th>
                <th>Items</th>
                <th>Total Qty</th>
                <th>Status</th>
                <th>Requested</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if ($requests === []): ?>
                <tr>
                    <td colspan="9" class="empty-cell">No requests found.</td>
                </tr>
            <?php endif; ?>
            <?php foreach ($requests as $request): ?>
                <?php $isIssueRequest = (string) ($request['request_mode'] ?? 'transfer') === 'issue'; ?>
                <tr>
                    <td data-label="Request">
                        <a class="cell-link cell-link-compact" href="<?= e(url('/requests/' . $request['id'])) ?>">
                            <strong><?= e($request['request_number']) ?></strong>
                            <?php if (!empty($request['needed_by_date'])): ?>
                                <div class="tiny-copy">Need by <?= e(date('M j, Y', strtotime((string) $request['needed_by_date']))) ?></div>
                            <?php endif; ?>
                        </a>
                    </td>
                    <td data-label="Route">
                        <strong><?= e($request['source_storage_name']) ?></strong>
                        <div class="tiny-copy">
                            <?= $isIssueRequest ? 'staff use request' : 'to ' . e((string) $request['destination_storage_name']) ?>
                        </div>
                    </td>
                    <td data-label="Requester"><?= e($request['requester_name']) ?></td>
                    <td data-label="Approver"><?= e($request['approver_name']) ?></td>
                    <td data-label="Items"><?= number_format((int) $request['line_count']) ?></td>
                    <td data-label="Total Qty"><?= format_quantity($request['total_requested']) ?></td>
                    <td data-label="Status"><span class="pill pill-<?= e((string) $request['status']) ?>"><?= e(request_status_label((string) $request['status'])) ?></span></td>
                    <td data-label="Requested"><?= e(format_datetime_display((string) $request['requested_at'])) ?></td>
                    <td data-label="Actions">
                        <a class="text-link" href="<?= e(url('/requests/' . $request['id'])) ?>">Open</a>
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
