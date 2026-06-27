<?php
$exportQuery = http_build_query(array_filter($filters, static fn ($value): bool => $value !== '' && $value !== null && $value !== 'all'));
$statusFilterUrl = static function (string $status) use ($filters): string {
    $next = $filters;
    $next['status'] = $status;

    return url('/email-logs?' . http_build_query(array_filter($next, static fn ($value): bool => $value !== '' && $value !== null && $value !== 'all')));
};
?>

<section class="page-head">
    <div class="page-head-copy">
        <p class="eyebrow"><?= e(site_setting('page.email_logs_eyebrow', 'Mailer delivery trail')) ?></p>
        <h3 class="page-head-title"><?= ui_icon('notification') ?><span><?= e(site_setting('page.email_logs', 'Email Logs')) ?></span></h3>
    </div>
    <div class="page-actions">
        <a class="ghost-button" href="<?= e(url('/settings/site')) ?>"><?= ui_icon('settings') ?><span>Email Settings</span></a>
        <?php if (Auth::hasPermission('email_logs.export')): ?>
            <a class="ghost-button" href="<?= e(url('/exports/email-logs') . ($exportQuery ? '?' . $exportQuery : '')) ?>"><?= ui_icon('export') ?><span>Export CSV</span></a>
        <?php endif; ?>
    </div>
</section>

<div class="live-filter-region" data-live-filter-region="email-logs">
    <section class="metric-grid metric-grid-compact">
        <a class="metric-card metric-card-link <?= $filters['status'] === 'all' ? 'metric-card-active' : '' ?>" href="<?= e($statusFilterUrl('all')) ?>" data-live-filter-link>
            <span class="metric-card-icon"><?= ui_icon('notification') ?><span>Total Attempts</span></span>
            <strong><?= number_format((int) ($counts['all'] ?? 0)) ?></strong>
            <p>Emails recorded by the system.</p>
        </a>
        <a class="metric-card metric-card-link <?= $filters['status'] === 'sent' ? 'metric-card-active' : '' ?>" href="<?= e($statusFilterUrl('sent')) ?>" data-live-filter-link>
            <span class="metric-card-icon"><?= ui_icon('notification') ?><span>Sent</span></span>
            <strong><?= number_format((int) ($counts['sent'] ?? 0)) ?></strong>
            <p>Accepted by the selected mailer.</p>
        </a>
        <a class="metric-card metric-card-link <?= $filters['status'] === 'failed' ? 'metric-card-active' : '' ?>" href="<?= e($statusFilterUrl('failed')) ?>" data-live-filter-link>
            <span class="metric-card-icon"><?= ui_icon('audit') ?><span>Failed</span></span>
            <strong><?= number_format((int) ($counts['failed'] ?? 0)) ?></strong>
            <p>Needs SMTP/settings review.</p>
        </a>
        <a class="metric-card metric-card-link <?= $filters['status'] === 'suppressed' ? 'metric-card-active' : '' ?>" href="<?= e($statusFilterUrl('suppressed')) ?>" data-live-filter-link>
            <span class="metric-card-icon"><?= ui_icon('settings') ?><span>Suppressed</span></span>
            <strong><?= number_format((int) ($counts['suppressed'] ?? 0)) ?></strong>
            <p>Disabled, log-only, or intentionally not sent.</p>
        </a>
    </section>

    <section class="filter-panel">
        <form class="filter-grid" method="get" action="<?= e(url('/email-logs')) ?>" data-live-filter-form>
            <label class="field">
                <span>Search</span>
                <input type="text" name="search" value="<?= e((string) $filters['search']) ?>" placeholder="Recipient, subject, type, user, error">
            </label>

            <label class="field">
                <span>Status</span>
                <select name="status">
                    <option value="all" <?= selected('all', (string) $filters['status']) ?>>All statuses</option>
                    <option value="sent" <?= selected('sent', (string) $filters['status']) ?>>Sent</option>
                    <option value="failed" <?= selected('failed', (string) $filters['status']) ?>>Failed</option>
                    <option value="suppressed" <?= selected('suppressed', (string) $filters['status']) ?>>Suppressed</option>
                </select>
            </label>

            <label class="field">
                <span>Email Type</span>
                <select name="email_type">
                    <option value="">All types</option>
                    <?php foreach ($typeOptions as $typeOption): ?>
                        <option value="<?= e((string) $typeOption['email_type']) ?>" <?= selected((string) $typeOption['email_type'], (string) $filters['email_type']) ?>>
                            <?= e((string) $typeOption['email_type']) ?> (<?= number_format((int) $typeOption['count']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="field">
                <span>From</span>
                <input type="date" name="date_from" value="<?= e((string) $filters['date_from']) ?>">
            </label>

            <label class="field">
                <span>To</span>
                <input type="date" name="date_to" value="<?= e((string) $filters['date_to']) ?>">
            </label>

            <div class="filter-actions">
                <button class="primary-button" type="submit"><?= ui_icon('filter') ?><span>Filter</span></button>
                <a class="ghost-button" href="<?= e(url('/email-logs')) ?>" data-live-filter-link><?= ui_icon('back') ?><span>Reset</span></a>
            </div>
        </form>
    </section>

    <section class="panel data-table-shell" data-table-shell data-empty-text="No email logs match this search.">
        <div class="table-shell-head">
            <div class="table-heading">
                <strong><?= ui_icon('notification') ?><span><?= e(site_setting('table.email_logs', 'Delivery Attempts')) ?></span></strong>
                <span class="table-count-badge" data-table-total><?= number_format(count($logs)) ?></span>
            </div>
            <p class="table-shell-copy">Password reset, setup, test email, and optional workflow email attempts are recorded here.</p>
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
                    <span class="sr-only">Search loaded email logs</span>
                    <input type="search" data-table-search placeholder="Search loaded email logs">
                </label>
            </div>

            <?php if (Auth::hasPermission('email_logs.export')): ?>
                <a class="ghost-button table-export-button" href="<?= e(url('/exports/email-logs') . ($exportQuery ? '?' . $exportQuery : '')) ?>"><?= ui_icon('export') ?><span>Export CSV</span></a>
            <?php endif; ?>
        </div>

        <div class="table-wrap">
            <table class="data-table data-table-mobile">
                <thead>
                <tr>
                    <th>Time</th>
                    <th>Status</th>
                    <th>Type</th>
                    <th>Recipient</th>
                    <th>Subject</th>
                    <th>Source</th>
                    <th>Error</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($logs === []): ?>
                    <tr>
                        <td colspan="7" class="empty-cell">No email delivery logs found.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($logs as $log): ?>
                    <?php $entityUrl = email_log_entity_url($log['entity_type'] ?? null, $log['entity_id'] ?? null); ?>
                    <tr>
                        <td data-label="Time"><?= e(format_datetime_display((string) $log['created_at'])) ?></td>
                        <td data-label="Status"><span class="pill <?= e(email_log_status_class((string) $log['status'])) ?>"><?= e(email_log_status_label((string) $log['status'])) ?></span></td>
                        <td data-label="Type"><code><?= e((string) $log['email_type']) ?></code></td>
                        <td data-label="Recipient">
                            <?= e((string) $log['recipient_email']) ?>
                            <?php if (!empty($log['recipient_name'])): ?>
                                <div class="tiny-copy"><?= e((string) $log['recipient_name']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td data-label="Subject">
                            <?= e((string) $log['subject']) ?>
                            <?php if (!empty($log['user_name'])): ?>
                                <div class="tiny-copy">Linked user: <?= e((string) $log['user_name']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td data-label="Source">
                            <?php if (!empty($log['entity_type'])): ?>
                                <?= e((string) $log['entity_type']) ?><?= !empty($log['entity_id']) ? ' #' . e((string) $log['entity_id']) : '' ?>
                                <?php if ($entityUrl !== ''): ?>
                                    <div><a class="text-link" href="<?= e($entityUrl) ?>">Open source</a></div>
                                <?php endif; ?>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td data-label="Error"><?= e((string) ($log['error_message'] ?: '-')) ?></td>
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
