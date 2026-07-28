<?php
$openCount = 0;
$overdueCount = 0;
$heldTotal = 0.0;
$damagedTotal = 0.0;

foreach ($rows as $row) {
    $totals = (array) ($row['custody_totals'] ?? []);
    $heldTotal += (float) ($totals['held'] ?? 0);
    $damagedTotal += (float) ($totals['damaged'] ?? 0);
    $openCount += !in_array((string) $row['status'], ['closed', 'cancelled'], true) ? 1 : 0;
    $overdueCount += !empty($row['is_overdue']) ? 1 : 0;
}
?>

<section class="page-head">
    <div class="page-head-copy">
        <p class="eyebrow">Long-Term Staff Custody</p>
        <h3 class="page-head-title"><?= ui_icon('handover') ?><span>Staff Custody</span></h3>
        <p class="page-head-subtitle">Track equipment held by employees, partial returns, damage, losses, and overdue reviews.</p>
    </div>
    <div class="page-actions">
        <a class="ghost-button" href="<?= e(url('/handovers')) ?>"><?= ui_icon('back') ?><span>All Handovers</span></a>
        <?php if (Auth::isOwner()): ?>
            <a class="ghost-button" href="<?= e(url('/handovers/custody/quarantine')) ?>"><?= ui_icon('flash') ?><span>Quarantine</span></a>
        <?php endif; ?>
        <?php if (Auth::hasPermission('handovers.create')): ?>
            <a class="primary-button" href="<?= e(url('/handovers/create?purpose=staff_custody')) ?>"><?= ui_icon('plus') ?><span>Issue Custody</span></a>
        <?php endif; ?>
    </div>
</section>

<form class="panel filter-grid" method="get" action="<?= e(url('/handovers/custody')) ?>" data-live-filter-form data-live-target="#custody-report-results">
    <label class="field">
        <span>Search</span>
        <input type="search" name="search" value="<?= e((string) $filters['search']) ?>" placeholder="Reference, staff, storage">
    </label>
    <label class="field">
        <span>Status</span>
        <select name="status">
            <option value="all" <?= selected($filters['status'], 'all') ?>>All statuses</option>
            <option value="open" <?= selected($filters['status'], 'open') ?>>Open</option>
            <option value="overdue" <?= selected($filters['status'], 'overdue') ?>>Overdue</option>
            <option value="closed" <?= selected($filters['status'], 'closed') ?>>Closed</option>
            <option value="cancelled" <?= selected($filters['status'], 'cancelled') ?>>Cancelled</option>
        </select>
    </label>
    <button class="primary-button" type="submit"><?= ui_icon('filter') ?><span>Filter</span></button>
    <a class="ghost-button" href="<?= e(url('/handovers/custody')) ?>"><?= ui_icon('back') ?><span>Reset</span></a>
</form>

<div id="custody-report-results" data-live-region>
    <section class="metric-grid compact-grid">
        <article class="metric-card metric-card-highlight">
            <span class="metric-card-icon"><?= ui_icon('handover') ?><span>Open Custody</span></span>
            <strong><?= number_format($openCount) ?></strong>
            <small>Employees currently holding stock</small>
        </article>
        <article class="metric-card">
            <span class="metric-card-icon"><?= ui_icon('flash') ?><span>Overdue</span></span>
            <strong><?= number_format($overdueCount) ?></strong>
            <small>Past the review date</small>
        </article>
        <article class="metric-card">
            <span class="metric-card-icon"><?= ui_icon('items') ?><span>Still Held</span></span>
            <strong><?= format_quantity($heldTotal) ?></strong>
            <small>Units assigned to staff</small>
        </article>
        <article class="metric-card">
            <span class="metric-card-icon"><?= ui_icon('movements') ?><span>Damaged</span></span>
            <strong><?= format_quantity($damagedTotal) ?></strong>
            <small>Approved quarantine outcomes</small>
        </article>
    </section>

    <section class="panel data-table-shell" data-table-shell data-empty-text="No custody handovers match this filter.">
        <div class="table-shell-head">
            <div class="table-heading">
                <strong><?= ui_icon('handover') ?><span>Custody Records</span></strong>
                <span class="table-count-badge"><?= number_format(count($rows)) ?></span>
            </div>
            <div class="page-actions">
                <a class="ghost-button" href="<?= e(url('/handovers/custody/export?' . http_build_query($filters))) ?>"><?= ui_icon('export') ?><span>Export CSV</span></a>
            </div>
        </div>

        <div class="table-wrap">
            <table class="data-table data-table-mobile">
                <thead>
                <tr>
                    <th>Handover</th>
                    <th>Staff</th>
                    <th>Source</th>
                    <th>Review Date</th>
                    <th>Still Held</th>
                    <th>Returned</th>
                    <th>Damaged / Lost</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php $totals = (array) $row['custody_totals']; ?>
                    <tr>
                        <td data-label="Handover">
                            <a class="cell-link" href="<?= e(url('/handovers/' . $row['id'])) ?>">
                                <strong><?= e($row['handover_number']) ?></strong>
                                <div class="tiny-copy"><?= e(handover_issue_condition_options()[$row['issue_condition'] ?? 'good'] ?? 'Good') ?> at issue</div>
                            </a>
                        </td>
                        <td data-label="Staff"><?= e($row['recipient_name']) ?></td>
                        <td data-label="Source"><?= e($row['source_storage_name']) ?></td>
                        <td data-label="Review Date" class="<?= !empty($row['is_overdue']) ? 'danger-text' : '' ?>">
                            <?= e((string) ($row['custody_review_date'] ?: 'Not set')) ?>
                            <?php if (!empty($row['is_overdue'])): ?><div class="tiny-copy">Overdue</div><?php endif; ?>
                        </td>
                        <td data-label="Still Held"><strong><?= format_quantity($totals['held']) ?></strong></td>
                        <td data-label="Returned"><?= format_quantity($totals['serviceable']) ?></td>
                        <td data-label="Damaged / Lost"><?= format_quantity((float) $totals['damaged'] + (float) $totals['lost']) ?></td>
                        <td data-label="Status"><span class="pill pill-<?= e((string) $row['status']) ?>"><?= e(handover_status_label((string) $row['status'])) ?></span></td>
                        <td data-label="Actions" class="table-actions-cell">
                            <a class="text-link" href="<?= e(url('/handovers/' . $row['id'])) ?>">Open</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
