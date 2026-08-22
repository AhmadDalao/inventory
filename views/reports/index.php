<section class="page-head">
    <div class="page-head-copy">
        <p class="eyebrow"><?= e(site_setting('page.reports_eyebrow', 'Export shortcuts')) ?></p>
        <h3 class="page-head-title"><?= ui_icon('reports') ?><span><?= e(site_setting('page.reports', 'Reports')) ?></span></h3>
    </div>
    <div class="page-actions">
        <a class="ghost-button" href="<?= e((string) ($savedReportsUrl ?? url('/reports/presets'))) ?>"><?= ui_icon('save') ?><span>Saved Reports</span></a>
        <a class="ghost-button" href="<?= e(url('/dashboard')) ?>"><?= ui_icon('dashboard') ?><span>Dashboard</span></a>
    </div>
</section>

<?php
$summaryCards = $summary['cards'] ?? [];
$selectedDateFrom = (string) ($summaryFilters['date_from'] ?? date('Y-m-d'));
$selectedDateTo = (string) ($summaryFilters['date_to'] ?? $selectedDateFrom);
$selectedType = (string) ($summaryFilters['movement_type'] ?? '');
$selectedItemStatus = (string) ($summaryFilters['item_status'] ?? 'all');
$selectedItemId = (string) ($summaryFilters['item_id'] ?? '');
$selectedDepartmentId = (string) ($summaryFilters['department_id'] ?? '');
$selectedEmployeeId = (string) ($summaryFilters['employee_id'] ?? '');
$selectedManagerId = (string) ($summaryFilters['manager_id'] ?? '');
$selectedPackagePresetId = (string) ($summaryFilters['package_preset_id'] ?? '');
$selectedReason = (string) ($summaryFilters['reason'] ?? '');
$selectedUnit = (string) ($summaryFilters['unit'] ?? '');
$isSummaryLocationScoped = !empty($summaryFilters['storage_id']);
$dateTitle = report_summary_period_label($summaryFilters);
?>

<div class="live-filter-region" data-live-filter-region="reports-summary">
<?php if (!empty($canViewDailySummary) && $summary !== null): ?>
<section class="panel reports-summary-panel">
    <div class="reports-summary-head">
        <div>
            <p class="eyebrow">Daily operations</p>
            <h3>Everything That Happened <?= e($dateTitle) ?></h3>
            <p class="muted-copy">Usage, restocks, transfers, adjustments, who did them, and which items were affected.</p>
        </div>
        <div class="report-summary-actions">
            <a class="ghost-button" href="<?= e((string) $summary['movement_url']) ?>"><?= ui_icon('movements') ?><span>Open Movement Log</span></a>
            <?php if (Auth::hasPermission('movements.export')): ?>
                <a class="ghost-button" href="<?= e((string) $summary['export_url']) ?>"><?= ui_icon('export') ?><span>Export CSV</span></a>
                <?php if (report_xlsx_thumbnail_export_enabled()): ?>
                    <a class="primary-button" href="<?= e((string) $summary['export_xlsx_url']) ?>"><?= ui_icon('items') ?><span>Export Excel</span></a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <form class="filter-grid reports-summary-filter" method="get" action="<?= e(url('/reports')) ?>" data-live-filter-form>
        <label class="field">
            <span>From</span>
            <input type="date" name="date_from" value="<?= e($selectedDateFrom) ?>">
        </label>

        <label class="field">
            <span>To</span>
            <input type="date" name="date_to" value="<?= e($selectedDateTo) ?>">
        </label>

        <label class="field">
            <span>Location</span>
            <select name="storage_id">
                <option value="">All locations</option>
                <?php foreach ($storages as $storage): ?>
                    <option value="<?= e((string) $storage['id']) ?>" <?= selected((string) $storage['id'], (string) ($summaryFilters['storage_id'] ?? '')) ?>>
                        <?= e(storage_type_label($storage['storage_type'])) ?> · <?= e($storage['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="field">
            <span>Movement Type</span>
            <select name="movement_type">
                <option value="">All movement types</option>
                <option value="usage" <?= selected('usage', $selectedType) ?>>Usage</option>
                <option value="restock" <?= selected('restock', $selectedType) ?>>Restock</option>
                <option value="transfer" <?= selected('transfer', $selectedType) ?>>Transfer</option>
                <option value="adjustment" <?= selected('adjustment', $selectedType) ?>>Adjustment</option>
            </select>
        </label>

        <label class="field">
            <span>Item Status</span>
            <select name="item_status">
                <option value="all" <?= selected('all', $selectedItemStatus) ?>>All items</option>
                <option value="active" <?= selected('active', $selectedItemStatus) ?>>Active items</option>
                <option value="deleted" <?= selected('deleted', $selectedItemStatus) ?>>Deleted items</option>
            </select>
        </label>

        <label class="field">
            <span>Item</span>
            <select name="item_id">
                <option value="">All items</option>
                <?php foreach ($items as $item): ?>
                    <option value="<?= e((string) $item['id']) ?>" <?= selected((string) $item['id'], $selectedItemId) ?>>
                        <?= e((string) $item['name']) ?> (<?= e((string) $item['sku']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="field">
            <span>Department</span>
            <select name="department_id">
                <option value="">All departments</option>
                <?php foreach ($departments as $department): ?>
                    <option value="<?= e((string) $department['id']) ?>" <?= selected((string) $department['id'], $selectedDepartmentId) ?>><?= e((string) $department['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="field">
            <span>Employee</span>
            <select name="employee_id">
                <option value="">All employees</option>
                <?php foreach ($employees as $employee): ?>
                    <option value="<?= e((string) $employee['id']) ?>" <?= selected((string) $employee['id'], $selectedEmployeeId) ?>><?= e((string) $employee['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="field">
            <span>Manager</span>
            <select name="manager_id">
                <option value="">All managers</option>
                <?php foreach ($managers as $manager): ?>
                    <option value="<?= e((string) $manager['id']) ?>" <?= selected((string) $manager['id'], $selectedManagerId) ?>><?= e((string) $manager['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="field">
            <span>Package</span>
            <select name="package_preset_id">
                <option value="">All packages</option>
                <?php foreach ($packagePresets as $preset): ?>
                    <option value="<?= e((string) $preset['id']) ?>" <?= selected((string) $preset['id'], $selectedPackagePresetId) ?>>
                        <?= e((string) $preset['item_name']) ?> · <?= e((string) $preset['label']) ?> = <?= e(format_quantity($preset['pieces_per_unit'] ?? 0)) ?> <?= e((string) $preset['base_unit']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="field">
            <span>Usage Reason</span>
            <select name="reason">
                <option value="">All reasons</option>
                <?php foreach ($usageReasons as $reason): ?>
                    <option value="<?= e((string) $reason['code']) ?>" <?= selected((string) $reason['code'], $selectedReason) ?>><?= e((string) $reason['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="field">
            <span>Canonical Unit</span>
            <select name="unit">
                <option value="">All units</option>
                <?php foreach ($units as $unit): ?>
                    <option value="<?= e((string) $unit['value']) ?>" <?= selected((string) $unit['value'], $selectedUnit) ?>><?= e((string) $unit['value']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <div class="filter-actions">
            <button class="primary-button" type="submit"><?= ui_icon('filter') ?><span>Filter</span></button>
            <a class="ghost-button" href="<?= e(url('/reports')) ?>" data-live-filter-link><?= ui_icon('back') ?><span>Reset</span></a>
        </div>
    </form>

    <div class="reports-summary-context">
        <span><?= e((string) $summary['storage_label']) ?></span>
        <span><?= e(report_summary_movement_label($selectedType)) ?></span>
        <span><?= e(report_summary_item_status_label($selectedItemStatus)) ?></span>
    </div>

    <div class="metric-grid reports-summary-metrics">
        <article class="metric-card metric-card-active">
            <span class="metric-card-icon"><?= ui_icon('movements') ?><span>Used Units</span></span>
            <strong><?= e(report_summary_unit_totals_text($summaryCards['used_totals'] ?? [])) ?></strong>
            <span class="metric-card-note">Grouped by compatible canonical unit</span>
        </article>
        <article class="metric-card">
            <span class="metric-card-icon"><?= ui_icon('items') ?><span>Items Touched</span></span>
            <strong><?= number_format((int) ($summaryCards['item_count'] ?? 0)) ?></strong>
            <span class="metric-card-note">Unique items moved</span>
        </article>
        <article class="metric-card">
            <span class="metric-card-icon"><?= ui_icon('audit') ?><span>Movements</span></span>
            <strong><?= number_format((int) ($summaryCards['movement_count'] ?? 0)) ?></strong>
            <span class="metric-card-note">Total movement rows</span>
        </article>
        <article class="metric-card">
            <span class="metric-card-icon"><?= ui_icon('users') ?><span>People</span></span>
            <strong><?= number_format((int) ($summaryCards['user_count'] ?? 0)) ?></strong>
            <span class="metric-card-note">Users who recorded activity</span>
        </article>
        <article class="metric-card">
            <span class="metric-card-icon"><?= ui_icon('supplier') ?><span>Restocked</span></span>
            <strong><?= e(report_summary_unit_totals_text($summaryCards['restocked_totals'] ?? [])) ?></strong>
            <span class="metric-card-note">Added, grouped by canonical unit</span>
        </article>
        <article class="metric-card">
            <span class="metric-card-icon"><?= ui_icon('transfer') ?><span>Transferred</span></span>
            <strong><?= e(report_summary_unit_totals_text($summaryCards['transferred_totals'] ?? [])) ?></strong>
            <span class="metric-card-note">Moved, grouped by canonical unit</span>
        </article>
    </div>

    <div class="reports-summary-columns">
        <section class="summary-card">
            <div class="summary-card-head">
                <div>
                    <p class="eyebrow">Usage By Item</p>
                    <h4>What Was Used</h4>
                </div>
                <span class="pill pill-muted"><?= number_format(count($summary['usage_by_item'] ?? [])) ?></span>
            </div>

            <div class="summary-list">
                <?php if (($summary['usage_by_item'] ?? []) === []): ?>
                    <p class="empty-state">No usage recorded for this date range and filter.</p>
                <?php endif; ?>

                <?php foreach (($summary['usage_by_item'] ?? []) as $row): ?>
                    <?php $imageUrl = item_image_url($row['image_path'] ?? null); ?>
                    <?php $usageReasons = (array) ($row['usage_reasons'] ?? []); ?>
                    <?php $proofFiles = report_summary_proof_file_entries($row['proof_files'] ?? null); ?>
                    <article class="summary-item-row">
                        <?php if ($imageUrl): ?>
                            <img class="item-thumb expandable-image" src="<?= e($imageUrl) ?>" alt="<?= e($row['item_name']) ?>" data-expand-image tabindex="0">
                        <?php else: ?>
                            <span class="item-thumb item-thumb-fallback"><?= e(item_initial((string) $row['item_name'])) ?></span>
                        <?php endif; ?>
                        <div>
                            <strong><?= e((string) $row['item_name']) ?></strong>
                            <span><?= e((string) $row['sku']) ?> · <?= e((string) $row['unit']) ?></span>
                            <?php if ($usageReasons !== []): ?>
                                <div class="summary-usage-tags" aria-label="Usage reasons">
                                    <?php foreach ($usageReasons as $reason): ?>
                                        <span class="summary-usage-tag">
                                            Used <?= e((string) $reason['label']) ?> · <?= e(format_quantity($reason['quantity'] ?? 0)) ?> <?= e((string) ($reason['unit'] ?? $row['unit'])) ?>
                                        </span>
                                        <?php if (trim((string) ($reason['notes'] ?? '')) !== ''): ?>
                                            <span class="summary-usage-note">Note: <?= e(truncate_text((string) $reason['notes'], 64)) ?></span>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <?php if (trim((string) ($row['entered_measurements'] ?? '')) !== ''): ?>
                                <small>Entered: <?= e(truncate_text((string) $row['entered_measurements'], 120)) ?></small>
                            <?php endif; ?>
                            <small>Department: <?= e(truncate_text((string) ($row['departments'] ?: 'Unassigned'), 70)) ?> · Manager: <?= e(truncate_text((string) ($row['managers'] ?: 'Unassigned'), 70)) ?></small>
                            <?php if ($proofFiles !== []): ?>
                                <div class="summary-proof-links">
                                    <?php foreach ($proofFiles as $proof): ?>
                                        <a href="<?= e(url('/files/' . $proof['id'] . '/download')) ?>"><?= ui_icon('files') ?><span><?= e(truncate_text($proof['name'], 42)) ?></span></a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <small><?= e(truncate_text((string) ($row['users'] ?: 'System'), 70)) ?></small>
                        </div>
                        <em><?= e(format_quantity($row['used_quantity'] ?? 0)) ?></em>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="summary-card">
            <div class="summary-card-head">
                <div>
                    <p class="eyebrow">People</p>
                    <h4>Who Used Or Moved Stock</h4>
                </div>
                <span class="pill pill-muted"><?= number_format(count($summary['user_breakdown'] ?? [])) ?></span>
            </div>

            <div class="summary-user-grid">
                <?php if (($summary['user_breakdown'] ?? []) === []): ?>
                    <p class="empty-state">No users recorded activity for this date range and filter.</p>
                <?php endif; ?>

                <?php foreach (($summary['user_breakdown'] ?? []) as $row): ?>
                    <article class="summary-user-card">
                        <strong><?= e((string) $row['user_name']) ?></strong>
                        <span><?= e((string) ($row['department_name'] ?: 'Unassigned')) ?> · Manager: <?= e((string) ($row['manager_name'] ?: 'Unassigned')) ?></span>
                        <span><?= number_format((int) $row['movement_count']) ?> movement<?= (int) $row['movement_count'] === 1 ? '' : 's' ?> · <?= number_format((int) $row['item_count']) ?> item<?= (int) $row['item_count'] === 1 ? '' : 's' ?></span>
                        <div>
                            <small>Used <?= e(report_summary_unit_totals_text($row['usage_totals'] ?? [])) ?></small>
                            <small>Restocked <?= e(report_summary_unit_totals_text($row['restock_totals'] ?? [])) ?></small>
                            <small>Transferred <?= e(report_summary_unit_totals_text($row['transfer_totals'] ?? [])) ?></small>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    </div>

    <section class="summary-card summary-operational-usage">
        <div class="summary-card-head">
            <div>
                <p class="eyebrow">Operational Usage</p>
                <h4>Handover Reconciliation</h4>
                <p class="muted-copy">Operational totals belong to the whole handover. Exact SKU quantities remain in the item usage table below.</p>
            </div>
            <div class="report-summary-actions">
                <span class="pill pill-muted"><?= number_format(count($summary['operational_usage'] ?? [])) ?></span>
                <?php if (Auth::hasPermission('movements.export')): ?>
                    <a class="ghost-button" href="<?= e((string) $summary['operational_export_url']) ?>"><?= ui_icon('export') ?><span>Operational CSV</span></a>
                    <?php if (report_xlsx_thumbnail_export_enabled()): ?>
                        <a class="primary-button" href="<?= e((string) $summary['operational_export_xlsx_url']) ?>"><?= ui_icon('items') ?><span>Operational Excel</span></a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="summary-table-scroll">
            <table class="data-table compact-summary-table">
                <thead>
                <tr>
                    <th>Date / Time</th>
                    <th>Handover</th>
                    <th>Unit</th>
                    <th>Issued</th>
                    <th>Received</th>
                    <th>Online</th>
                    <th>Walk-in</th>
                    <th>Event</th>
                    <th>Sport</th>
                    <th>Damage</th>
                    <th>Complimentary</th>
                    <th>No Show</th>
                    <th>Other</th>
                    <th>Returned</th>
                    <th>Physical Used</th>
                    <th>Operational Used</th>
                    <th>Difference</th>
                    <th>Receiver</th>
                    <th>Approver</th>
                    <th>Source Storage</th>
                    <th>Notes</th>
                </tr>
                </thead>
                <tbody>
                <?php if (($summary['operational_usage'] ?? []) === []): ?>
                    <tr>
                        <td colspan="21" class="empty-cell">No approved operational handover reconciliation was found for this date range and filter.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach (($summary['operational_usage'] ?? []) as $row): ?>
                    <?php
                    $difference = (float) ($row['difference_total'] ?? 0);
                    $activityAt = trim((string) ($row['activity_at'] ?? ''));
                    $operationalNotes = array_filter([
                        trim((string) ($row['discrepancy_notes'] ?? '')),
                        trim((string) ($row['variance_reason_label'] ?? '')),
                        trim((string) ($row['variance_notes'] ?? '')),
                    ], static fn (string $value): bool => $value !== '');
                    ?>
                    <tr>
                        <td data-label="Date / Time">
                            <strong><?= e(date('M j, Y', strtotime((string) $row['activity_date']))) ?></strong>
                            <?php if ($activityAt !== ''): ?>
                                <div class="tiny-copy"><?= e(date('g:i:s A', strtotime($activityAt))) ?></div>
                            <?php endif; ?>
                        </td>
                        <td data-label="Handover">
                            <a href="<?= e(url('/handovers/' . (int) $row['handover_id'])) ?>">
                                <?= e((string) $row['handover_number']) ?>
                            </a>
                        </td>
                        <td data-label="Unit"><?= e((string) $row['unit']) ?></td>
                        <td data-label="Issued"><?= e(format_quantity($row['issued_total'] ?? 0)) ?></td>
                        <td data-label="Received"><?= e(format_quantity($row['received_total'] ?? 0)) ?></td>
                        <td data-label="Online"><?= e(format_quantity($row['online_quantity'] ?? 0)) ?></td>
                        <td data-label="Walk-in"><?= e(format_quantity($row['walkin_quantity'] ?? 0)) ?></td>
                        <td data-label="Event"><?= e(format_quantity($row['event_quantity'] ?? 0)) ?></td>
                        <td data-label="Sport"><?= e(format_quantity($row['sport_quantity'] ?? 0)) ?></td>
                        <td data-label="Damage"><?= e(format_quantity($row['damage_quantity'] ?? 0)) ?></td>
                        <td data-label="Complimentary"><?= e(format_quantity($row['complimentary_quantity'] ?? 0)) ?></td>
                        <td data-label="No Show"><?= e(format_quantity($row['noshow_quantity'] ?? 0)) ?></td>
                        <td data-label="Other"><?= e(format_quantity($row['other_quantity'] ?? 0)) ?></td>
                        <td data-label="Returned"><?= e(format_quantity($row['returned_total'] ?? 0)) ?></td>
                        <td data-label="Physical Used"><?= e(format_quantity($row['physical_used_total'] ?? 0)) ?></td>
                        <td data-label="Operational Used"><?= e(format_quantity($row['operational_used_total'] ?? 0)) ?></td>
                        <td data-label="Difference">
                            <span class="pill <?= abs($difference) < 0.009 ? 'pill-approved' : 'pill-danger' ?>">
                                <?= e(format_quantity($difference)) ?>
                            </span>
                        </td>
                        <td data-label="Receiver"><?= e((string) $row['receiver_name']) ?></td>
                        <td data-label="Approver"><?= e((string) $row['approver_name']) ?></td>
                        <td data-label="Source Storage"><?= e((string) $row['source_storage_name']) ?></td>
                        <td data-label="Notes"><?= e($operationalNotes !== [] ? implode(' · ', $operationalNotes) : '-') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="summary-card summary-daily-usage">
        <div class="summary-card-head">
            <div>
                <p class="eyebrow">Usage By Day</p>
                <h4>What Each Item Used Each Day</h4>
                <p class="muted-copy">Daily item totals stay separate inside the selected range, with the latest usage timestamp and reason breakdown.</p>
            </div>
            <div class="report-summary-actions">
                <span class="pill pill-muted"><?= number_format(count($summary['usage_by_day'] ?? [])) ?></span>
                <?php if (Auth::hasPermission('movements.export')): ?>
                    <a class="ghost-button" href="<?= e((string) $summary['usage_export_url']) ?>"><?= ui_icon('export') ?><span>Usage CSV</span></a>
                    <?php if (report_xlsx_thumbnail_export_enabled()): ?>
                        <a class="primary-button" href="<?= e((string) $summary['usage_export_xlsx_url']) ?>"><?= ui_icon('items') ?><span>Usage Excel</span></a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="summary-table-scroll summary-daily-usage-scroll">
            <table class="data-table compact-summary-table">
                <thead>
                <tr>
                    <th>Date / Time</th>
                    <th>Item</th>
                    <th>Used</th>
                    <th>Entered / Package</th>
                    <th>Breakdown / Notes</th>
                    <th>Staff</th>
                    <th>Department / Manager</th>
                    <th>Approver</th>
                    <th>Location</th>
                    <th>Proof</th>
                    <th>Reference</th>
                </tr>
                </thead>
                <tbody>
                <?php if (($summary['usage_by_day'] ?? []) === []): ?>
                    <tr>
                        <td colspan="11" class="empty-cell">No usage was recorded for this date range and filter.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach (($summary['usage_by_day'] ?? []) as $row): ?>
                    <?php
                    $imageUrl = item_image_url($row['image_path'] ?? null);
                    $usageReasons = (array) ($row['usage_reasons'] ?? []);
                    $lastActivity = trim((string) ($row['last_activity_at'] ?? ''));
                    $proofFiles = report_summary_proof_file_entries($row['proof_files'] ?? null);
                    ?>
                    <tr>
                        <td data-label="Date / Time">
                            <strong><?= e(date('M j, Y', strtotime((string) $row['usage_date']))) ?></strong>
                            <?php if ($lastActivity !== ''): ?>
                                <div class="tiny-copy"><?= e(date('g:i:s A', strtotime($lastActivity))) ?></div>
                            <?php endif; ?>
                        </td>
                        <td data-label="Item">
                            <div class="summary-table-item">
                                <?php if ($imageUrl): ?>
                                    <img class="item-thumb expandable-image" src="<?= e($imageUrl) ?>" alt="<?= e((string) $row['item_name']) ?>" data-expand-image tabindex="0">
                                <?php else: ?>
                                    <span class="item-thumb item-thumb-fallback"><?= e(item_initial((string) $row['item_name'])) ?></span>
                                <?php endif; ?>
                                <div>
                                    <strong><?= e((string) $row['item_name']) ?></strong>
                                    <div class="tiny-copy"><?= e((string) $row['sku']) ?> · <?= e((string) $row['unit']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td data-label="Used"><strong><?= e(format_quantity($row['used_quantity'] ?? 0)) ?> <?= e((string) $row['unit']) ?></strong></td>
                        <td data-label="Entered / Package">
                            <?php if (trim((string) ($row['entered_measurements'] ?? '')) !== ''): ?>
                                <strong><?= e(truncate_text((string) $row['entered_measurements'], 100)) ?></strong>
                                <?php if (trim((string) ($row['packages'] ?? '')) !== ''): ?>
                                    <div class="tiny-copy"><?= e(truncate_text((string) $row['packages'], 80)) ?></div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="tiny-copy">Base unit entry</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Breakdown / Notes">
                            <?php if ($usageReasons !== []): ?>
                                <div class="summary-usage-tags">
                                    <?php foreach ($usageReasons as $reason): ?>
                                        <span class="summary-usage-tag">
                                            <?= e((string) $reason['label']) ?> · <?= e(format_quantity($reason['quantity'] ?? 0)) ?> <?= e((string) ($reason['unit'] ?? $row['unit'])) ?>
                                        </span>
                                        <?php if (trim((string) ($reason['notes'] ?? '')) !== ''): ?>
                                            <span class="summary-usage-note">Note: <?= e(truncate_text((string) $reason['notes'], 72)) ?></span>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php elseif (trim((string) ($row['notes_list'] ?? '')) !== ''): ?>
                                <span class="tiny-copy"><?= e(truncate_text((string) $row['notes_list'], 140)) ?></span>
                            <?php else: ?>
                                <span class="tiny-copy">Unspecified</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Staff"><?= e(truncate_text((string) ($row['staff_name'] ?: 'System'), 80)) ?></td>
                        <td data-label="Department / Manager">
                            <strong><?= e(truncate_text((string) ($row['department_name'] ?: 'Unassigned'), 70)) ?></strong>
                            <div class="tiny-copy">Manager: <?= e(truncate_text((string) ($row['manager_name'] ?: 'Unassigned'), 70)) ?></div>
                        </td>
                        <td data-label="Approver"><?= e(truncate_text((string) ($row['approver_name'] ?: '-'), 80)) ?></td>
                        <td data-label="Location"><?= e(truncate_text((string) ($row['usage_location'] ?: 'Unassigned'), 90)) ?></td>
                        <td data-label="Proof">
                            <?php if ($proofFiles === []): ?>
                                <span class="tiny-copy">None</span>
                            <?php else: ?>
                                <div class="summary-proof-links">
                                    <?php foreach ($proofFiles as $proof): ?>
                                        <a href="<?= e(url('/files/' . $proof['id'] . '/download')) ?>"><?= ui_icon('files') ?><span><?= e(truncate_text($proof['name'], 32)) ?></span></a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td data-label="Reference"><?= e(truncate_text((string) ($row['references_list'] ?: '-'), 70)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="summary-card">
        <div class="summary-card-head">
            <div>
                <p class="eyebrow">Timeline</p>
                <h4>Activity In Order</h4>
                <p class="muted-copy">Newest activity first, with the exact date and timestamp recorded by the movement log.</p>
            </div>
            <div class="report-summary-actions">
                <span class="pill pill-muted"><?= number_format(count($summary['timeline'] ?? [])) ?></span>
                <?php if (Auth::hasPermission('movements.export')): ?>
                    <a class="ghost-button" href="<?= e((string) $summary['export_url']) ?>"><?= ui_icon('export') ?><span>Export Timeline</span></a>
                <?php endif; ?>
            </div>
        </div>

        <div class="summary-table-scroll">
            <table class="data-table compact-summary-table">
                <thead>
                <tr>
                    <th>Date / Time</th>
                    <th>Item</th>
                    <th>Type</th>
                    <th>Qty</th>
                    <th>Entered / Package</th>
                    <?php if ($isSummaryLocationScoped): ?>
                        <th>Location Change</th>
                        <th>Location Balance</th>
                    <?php endif; ?>
                    <th>From</th>
                    <th>To</th>
                    <th>By</th>
                    <th>Department / Manager</th>
                    <th>Proof</th>
                    <th>Reference</th>
                </tr>
                </thead>
                <tbody>
                <?php if (($summary['timeline'] ?? []) === []): ?>
                    <tr>
                        <td colspan="<?= $isSummaryLocationScoped ? '13' : '11' ?>" class="empty-cell">No movement activity found for this date range and filter.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach (($summary['timeline'] ?? []) as $movement): ?>
                    <?php
                    $movementQuantity = $movement['movement_quantity'] !== null && $movement['movement_quantity'] !== ''
                        ? $movement['movement_quantity']
                        : abs((float) ($movement['quantity_delta'] ?? 0));
                    $timelineProofs = report_summary_proof_file_entries($movement['proof_files'] ?? null);
                    ?>
                    <tr>
                        <td data-label="Date / Time">
                            <strong><?= e(date('M j, Y', strtotime((string) $movement['used_at']))) ?></strong>
                            <div class="tiny-copy"><?= e(date('g:i:s A', strtotime((string) $movement['used_at']))) ?></div>
                        </td>
                        <td data-label="Item">
                            <div class="summary-table-item">
                                <?php $timelineImageUrl = item_image_url($movement['image_path'] ?? null); ?>
                                <?php if ($timelineImageUrl): ?>
                                    <img class="item-thumb expandable-image" src="<?= e($timelineImageUrl) ?>" alt="<?= e((string) $movement['item_name']) ?>" data-expand-image tabindex="0">
                                <?php else: ?>
                                    <span class="item-thumb item-thumb-fallback"><?= e(item_initial((string) $movement['item_name'])) ?></span>
                                <?php endif; ?>
                                <div>
                                    <strong><?= e((string) $movement['item_name']) ?></strong>
                                    <div class="tiny-copy"><?= e((string) $movement['sku']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td data-label="Type"><?= e(ucfirst((string) $movement['movement_type'])) ?></td>
                        <td data-label="Qty"><?= e(format_quantity($movementQuantity)) ?> <?= e((string) $movement['unit']) ?></td>
                        <td data-label="Entered / Package">
                            <?php if ($movement['input_quantity'] !== null && $movement['input_quantity'] !== ''): ?>
                                <strong><?= e(format_quantity($movement['input_quantity'])) ?> × <?= e((string) ($movement['package_label'] ?: $movement['base_unit'])) ?></strong>
                                <div class="tiny-copy"><?= e(format_quantity($movement['base_quantity'] ?? $movementQuantity)) ?> <?= e((string) ($movement['base_unit'] ?: $movement['unit'])) ?></div>
                            <?php else: ?>
                                <span class="tiny-copy">Base unit entry</span>
                            <?php endif; ?>
                        </td>
                        <?php if ($isSummaryLocationScoped): ?>
                            <td data-label="Location Change"><?= e(format_quantity($movement['location_change'])) ?> <?= e((string) $movement['unit']) ?></td>
                            <td data-label="Location Balance"><?= e(format_quantity($movement['location_balance_after'])) ?> <?= e((string) $movement['unit']) ?></td>
                        <?php endif; ?>
                        <td data-label="From"><?= e((string) ($movement['source_storage_name'] ?: '-')) ?></td>
                        <td data-label="To"><?= e((string) ($movement['destination_storage_name'] ?: '-')) ?></td>
                        <td data-label="By"><?= e((string) $movement['user_name']) ?></td>
                        <td data-label="Department / Manager">
                            <strong><?= e((string) ($movement['department_name'] ?: 'Unassigned')) ?></strong>
                            <div class="tiny-copy">Manager: <?= e((string) ($movement['manager_name'] ?: 'Unassigned')) ?></div>
                        </td>
                        <td data-label="Proof">
                            <?php if ($timelineProofs === []): ?>
                                <span class="tiny-copy">None</span>
                            <?php else: ?>
                                <div class="summary-proof-links">
                                    <?php foreach ($timelineProofs as $proof): ?>
                                        <a href="<?= e(url('/files/' . $proof['id'] . '/download')) ?>"><?= ui_icon('files') ?><span><?= e(truncate_text($proof['name'], 32)) ?></span></a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td data-label="Reference"><?= e((string) ($movement['reference_code'] ?: '-')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</section>
<?php endif; ?>
</div>

<section class="panel reports-hero-panel">
    <div>
        <p class="eyebrow">Preset reports</p>
        <h3>Pick the answer, download the CSV</h3>
        <p>These cards reuse existing exports, so permissions and filters stay consistent with the source modules.</p>
    </div>
    <div class="reports-hero-stat">
        <span>Available Presets</span>
        <strong><?= number_format(array_sum(array_map('count', $groups))) ?></strong>
    </div>
</section>

<?php if ($groups === []): ?>
    <section class="panel">
        <p class="empty-state">No report presets are available for your current permissions.</p>
    </section>
<?php endif; ?>

<?php foreach ($groups as $groupName => $cards): ?>
    <section class="reports-group">
        <div class="section-heading-row">
            <div>
                <p class="eyebrow">Report Group</p>
                <h3><?= e((string) $groupName) ?></h3>
            </div>
        </div>
        <div class="reports-card-grid">
            <?php foreach ($cards as $card): ?>
                <article class="report-preset-card">
                    <div class="report-preset-head">
                        <span class="report-preset-icon"><?= ui_icon((string) $card['icon']) ?></span>
                        <span class="pill pill-muted"><?= e((string) $card['badge']) ?></span>
                    </div>
                    <h4><?= e((string) $card['title']) ?></h4>
                    <p><?= e((string) $card['copy']) ?></p>
                    <div class="report-preset-actions">
                        <?php if (!empty($card['download_url'])): ?>
                            <a class="primary-button" href="<?= e((string) $card['download_url']) ?>"><?= ui_icon('export') ?><span>Download CSV</span></a>
                        <?php endif; ?>
                        <a class="ghost-button" href="<?= e((string) $card['source_url']) ?>">Open Source</a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
<?php endforeach; ?>
