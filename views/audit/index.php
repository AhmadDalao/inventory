<?php
$exportQuery = http_build_query(array_filter($filters, static fn ($value): bool => $value !== '' && $value !== null));
?>

<section class="page-head">
    <div class="page-head-copy">
        <p class="eyebrow"><?= e(site_setting('page.audit_eyebrow', 'Admin accountability')) ?></p>
        <h3 class="page-head-title"><?= ui_icon('audit') ?><span><?= e(site_setting('page.audit', 'Audit Log')) ?></span></h3>
    </div>
    <div class="page-actions">
        <?php if (Auth::hasPermission('audit.export')): ?>
            <a class="ghost-button" href="<?= e(url('/exports/audit') . ($exportQuery ? '?' . $exportQuery : '')) ?>"><?= ui_icon('export') ?><span>Export CSV</span></a>
        <?php endif; ?>
    </div>
</section>

<div class="live-filter-region" data-live-filter-region="audit">
<section class="filter-panel">
    <form class="filter-grid" method="get" action="<?= e(url('/audit-log')) ?>" data-live-filter-form>
        <label class="field">
            <span>Search</span>
            <input type="text" name="search" value="<?= e($filters['search']) ?>" placeholder="Summary, action, user, entity">
        </label>

        <label class="field">
            <span>Action</span>
            <select name="action">
                <option value="">All actions</option>
                <?php foreach ($actions as $action): ?>
                    <option value="<?= e((string) $action['action']) ?>" <?= selected((string) $action['action'], (string) $filters['action']) ?>><?= e((string) $action['action']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="field">
            <span>Entity</span>
            <select name="entity_type">
                <option value="">All entities</option>
                <?php foreach ($entityTypes as $entityType): ?>
                    <option value="<?= e((string) $entityType['entity_type']) ?>" <?= selected((string) $entityType['entity_type'], (string) $filters['entity_type']) ?>><?= e((string) $entityType['entity_type']) ?></option>
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
            <a class="ghost-button" href="<?= e(url('/audit-log')) ?>" data-live-filter-link><?= ui_icon('back') ?><span>Reset</span></a>
        </div>
    </form>
</section>

<section class="panel data-table-shell" data-table-shell data-empty-text="No audit events match this search.">
    <div class="table-shell-head">
        <div class="table-heading">
            <strong><?= ui_icon('audit') ?><span><?= e(site_setting('table.audit', 'System Activity')) ?></span></strong>
            <span class="table-count-badge" data-table-total><?= number_format(count($activities)) ?></span>
        </div>
        <p class="table-shell-copy">Admin actions recorded from the new operational modules.</p>
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
                <span class="sr-only">Search audit log</span>
                <input type="search" data-table-search placeholder="Search loaded activity">
            </label>
        </div>
    </div>

    <div class="table-wrap">
        <table class="data-table data-table-mobile">
            <thead>
            <tr>
                <th>Time</th>
                <th>User</th>
                <th>Action</th>
                <th>Entity</th>
                <th>Summary</th>
                <th>IP</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($activities === []): ?>
                <tr>
                    <td colspan="6" class="empty-cell">No audit activity found.</td>
                </tr>
            <?php endif; ?>
            <?php foreach ($activities as $activity): ?>
                <tr>
                    <td data-label="Time"><?= e(format_datetime_display((string) $activity['created_at'])) ?></td>
                    <td data-label="User">
                        <?= e((string) ($activity['user_name'] ?: 'System')) ?>
                        <?php if (!empty($activity['user_email'])): ?>
                            <div class="tiny-copy"><?= e((string) $activity['user_email']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td data-label="Action"><code><?= e((string) $activity['action']) ?></code></td>
                    <td data-label="Entity">
                        <?= e((string) ($activity['entity_type'] ?: '-')) ?>
                        <?php if (!empty($activity['entity_id'])): ?>
                            <div class="tiny-copy">#<?= e((string) $activity['entity_id']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td data-label="Summary"><?= e((string) $activity['summary']) ?></td>
                    <td data-label="IP"><?= e((string) ($activity['ip_address'] ?: '-')) ?></td>
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
