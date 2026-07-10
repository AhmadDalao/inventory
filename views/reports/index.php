<section class="page-head">
    <div class="page-head-copy">
        <p class="eyebrow"><?= e(site_setting('page.reports_eyebrow', 'Export shortcuts')) ?></p>
        <h3 class="page-head-title"><?= ui_icon('reports') ?><span><?= e(site_setting('page.reports', 'Reports')) ?></span></h3>
    </div>
    <div class="page-actions">
        <a class="ghost-button" href="<?= e(url('/dashboard')) ?>"><?= ui_icon('dashboard') ?><span>Dashboard</span></a>
    </div>
</section>

<?php
$summaryCards = $summary['cards'] ?? [];
$selectedDate = (string) ($summaryFilters['date'] ?? date('Y-m-d'));
$selectedType = (string) ($summaryFilters['movement_type'] ?? '');
$selectedItemStatus = (string) ($summaryFilters['item_status'] ?? 'all');
$isSummaryLocationScoped = !empty($summaryFilters['storage_id']);
$dateTitle = date('M j, Y', strtotime($selectedDate));
$savedPresets = $savedPresets ?? [];
$savedPresetTypes = $savedPresetTypes ?? [];
$currentReportQuery = (string) ($currentReportQuery ?? '');
?>

<div class="live-filter-region" data-live-filter-region="reports-summary">
<?php if (!empty($canViewDailySummary) && $summary !== null): ?>
<section class="panel reports-summary-panel">
    <div class="reports-summary-head">
        <div>
            <p class="eyebrow">Daily operations</p>
            <h3>Everything That Happened On <?= e($dateTitle) ?></h3>
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
            <span>Date</span>
            <input type="date" name="date" value="<?= e($selectedDate) ?>">
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
            <strong><?= e(format_quantity($summaryCards['used_units'] ?? 0)) ?></strong>
            <span class="metric-card-note">Items consumed on this date</span>
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
            <strong><?= e(format_quantity($summaryCards['restocked_units'] ?? 0)) ?></strong>
            <span class="metric-card-note">Units added</span>
        </article>
        <article class="metric-card">
            <span class="metric-card-icon"><?= ui_icon('transfer') ?><span>Transferred</span></span>
            <strong><?= e(format_quantity($summaryCards['transferred_units'] ?? 0)) ?></strong>
            <span class="metric-card-note">Units moved between locations</span>
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
                    <p class="empty-state">No usage recorded for this date and filter.</p>
                <?php endif; ?>

                <?php foreach (($summary['usage_by_item'] ?? []) as $row): ?>
                    <?php $imageUrl = item_image_url($row['image_path'] ?? null); ?>
                    <?php $usageReasons = (array) ($row['usage_reasons'] ?? []); ?>
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
                    <p class="empty-state">No users recorded activity for this date and filter.</p>
                <?php endif; ?>

                <?php foreach (($summary['user_breakdown'] ?? []) as $row): ?>
                    <article class="summary-user-card">
                        <strong><?= e((string) $row['user_name']) ?></strong>
                        <span><?= number_format((int) $row['movement_count']) ?> movement<?= (int) $row['movement_count'] === 1 ? '' : 's' ?> · <?= number_format((int) $row['item_count']) ?> item<?= (int) $row['item_count'] === 1 ? '' : 's' ?></span>
                        <div>
                            <small>Used <?= e(format_quantity($row['used_units'] ?? 0)) ?></small>
                            <small>Restocked <?= e(format_quantity($row['restocked_units'] ?? 0)) ?></small>
                            <small>Transferred <?= e(format_quantity($row['transferred_units'] ?? 0)) ?></small>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    </div>

    <section class="summary-card">
        <div class="summary-card-head">
            <div>
                <p class="eyebrow">Timeline</p>
                <h4>Activity In Order</h4>
            </div>
            <span class="pill pill-muted"><?= number_format(count($summary['timeline'] ?? [])) ?></span>
        </div>

        <div class="summary-table-scroll">
            <table class="data-table compact-summary-table">
                <thead>
                <tr>
                    <th>Time</th>
                    <th>Item</th>
                    <th>Type</th>
                    <th>Qty</th>
                    <?php if ($isSummaryLocationScoped): ?>
                        <th>Location Change</th>
                        <th>Location Balance</th>
                    <?php endif; ?>
                    <th>From</th>
                    <th>To</th>
                    <th>By</th>
                    <th>Reference</th>
                </tr>
                </thead>
                <tbody>
                <?php if (($summary['timeline'] ?? []) === []): ?>
                    <tr>
                        <td colspan="<?= $isSummaryLocationScoped ? '10' : '8' ?>" class="empty-cell">No movement activity found for this date and filter.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach (($summary['timeline'] ?? []) as $movement): ?>
                    <?php
                    $movementQuantity = $movement['movement_quantity'] !== null && $movement['movement_quantity'] !== ''
                        ? $movement['movement_quantity']
                        : abs((float) ($movement['quantity_delta'] ?? 0));
                    ?>
                    <tr>
                        <td data-label="Time"><?= e(date('g:i A', strtotime((string) $movement['used_at']))) ?></td>
                        <td data-label="Item">
                            <strong><?= e((string) $movement['item_name']) ?></strong>
                            <div class="tiny-copy"><?= e((string) $movement['sku']) ?></div>
                        </td>
                        <td data-label="Type"><?= e(ucfirst((string) $movement['movement_type'])) ?></td>
                        <td data-label="Qty"><?= e(format_quantity($movementQuantity)) ?> <?= e((string) $movement['unit']) ?></td>
                        <?php if ($isSummaryLocationScoped): ?>
                            <td data-label="Location Change"><?= e(format_quantity($movement['location_change'])) ?> <?= e((string) $movement['unit']) ?></td>
                            <td data-label="Location Balance"><?= e(format_quantity($movement['location_balance_after'])) ?> <?= e((string) $movement['unit']) ?></td>
                        <?php endif; ?>
                        <td data-label="From"><?= e((string) ($movement['source_storage_name'] ?: '-')) ?></td>
                        <td data-label="To"><?= e((string) ($movement['destination_storage_name'] ?: '-')) ?></td>
                        <td data-label="By"><?= e((string) $movement['user_name']) ?></td>
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

<section class="panel saved-report-panel">
    <div class="section-heading-row">
        <div>
            <p class="eyebrow">Saved report presets</p>
            <h3>Reusable Filters And Exports</h3>
            <p class="muted-copy">Save the filter state once, then open or export the same report without rebuilding it every day.</p>
        </div>
        <span class="pill pill-muted"><?= number_format(count($savedPresets)) ?> saved</span>
    </div>

    <details class="settings-accordion saved-report-create" open>
        <summary>
            <span><?= ui_icon('add') ?> Create saved preset</span>
            <small>Daily operations, finance, usage by reason, storage owner, purchases, assets, and stock movements.</small>
        </summary>
        <form class="saved-report-form" method="post" action="<?= e(url('/reports/presets')) ?>">
            <?= csrf_field() ?>
            <label class="field">
                <span>Name</span>
                <input type="text" name="name" value="Daily operations <?= e($dateTitle) ?>" maxlength="160" required>
            </label>
            <label class="field">
                <span>Report type</span>
                <select name="report_type">
                    <?php foreach ($savedPresetTypes as $typeKey => $typeDefinition): ?>
                        <?php if (!saved_report_can_view_type((string) $typeKey)) { continue; } ?>
                        <option value="<?= e((string) $typeKey) ?>"><?= e((string) $typeDefinition['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span>Export format</span>
                <select name="export_format">
                    <option value="csv">CSV</option>
                    <option value="xlsx">Excel when available</option>
                </select>
            </label>
            <label class="field">
                <span>Visibility</span>
                <select name="visibility">
                    <option value="shared">Shared with permitted admins</option>
                    <option value="private">Private to me</option>
                </select>
            </label>
            <label class="field saved-report-filter-query">
                <span>Filter query</span>
                <input type="text" name="filter_query" value="<?= e($currentReportQuery) ?>" placeholder="date=2026-07-10&storage_id=1">
                <small>Use URL query format. Blank uses the default filters for the selected type.</small>
            </label>
            <label class="field saved-report-description">
                <span>Description</span>
                <textarea name="description" rows="3" placeholder="Optional note for why this report matters."></textarea>
            </label>
            <button class="primary-button" type="submit"><?= ui_icon('save') ?><span>Save Preset</span></button>
        </form>
    </details>

    <?php if ($savedPresets === []): ?>
        <p class="empty-state">No saved report presets yet.</p>
    <?php else: ?>
        <div class="saved-report-grid">
            <?php foreach ($savedPresets as $preset): ?>
                <?php
                $definition = saved_report_preset_type((string) $preset['report_type']) ?? [];
                $presetUrls = saved_report_preset_urls($preset);
                $filters = json_decode((string) ($preset['filters_json'] ?? '{}'), true);
                $filterQuery = is_array($filters) ? http_build_query($filters) : '';
                ?>
                <article class="saved-report-card">
                    <div class="report-preset-head">
                        <span class="report-preset-icon"><?= ui_icon((string) ($definition['icon'] ?? 'reports')) ?></span>
                        <span class="pill pill-muted"><?= e((string) ($definition['label'] ?? $preset['report_type'])) ?></span>
                    </div>
                    <h4><?= e((string) $preset['name']) ?></h4>
                    <?php if (trim((string) ($preset['description'] ?? '')) !== ''): ?>
                        <p><?= e((string) $preset['description']) ?></p>
                    <?php endif; ?>
                    <div class="saved-report-meta">
                        <span><?= e(strtoupper((string) $preset['export_format'])) ?></span>
                        <span><?= e(ucfirst((string) $preset['visibility'])) ?></span>
                        <?php if (!empty($preset['creator_name'])): ?>
                            <span>By <?= e((string) $preset['creator_name']) ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if ($filterQuery !== ''): ?>
                        <code class="saved-report-query"><?= e($filterQuery) ?></code>
                    <?php endif; ?>
                    <div class="report-preset-actions">
                        <a class="ghost-button" href="<?= e((string) $presetUrls['source_url']) ?>">Open Source</a>
                        <?php if (!empty($presetUrls['export_url'])): ?>
                            <a class="primary-button" href="<?= e((string) $presetUrls['export_url']) ?>"><?= ui_icon('export') ?><span>Export <?= e((string) $presetUrls['export_label']) ?></span></a>
                        <?php endif; ?>
                    </div>
                    <details class="saved-report-edit">
                        <summary>Edit</summary>
                        <form class="saved-report-form saved-report-form-compact" method="post" action="<?= e(url('/reports/presets/' . (int) $preset['id'] . '/edit')) ?>">
                            <?= csrf_field() ?>
                            <label class="field">
                                <span>Name</span>
                                <input type="text" name="name" value="<?= e((string) $preset['name']) ?>" maxlength="160" required>
                            </label>
                            <label class="field">
                                <span>Report type</span>
                                <select name="report_type">
                                    <?php foreach ($savedPresetTypes as $typeKey => $typeDefinition): ?>
                                        <?php if (!saved_report_can_view_type((string) $typeKey)) { continue; } ?>
                                        <option value="<?= e((string) $typeKey) ?>" <?= selected((string) $typeKey, (string) $preset['report_type']) ?>><?= e((string) $typeDefinition['label']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="field">
                                <span>Format</span>
                                <select name="export_format">
                                    <option value="csv" <?= selected('csv', (string) $preset['export_format']) ?>>CSV</option>
                                    <option value="xlsx" <?= selected('xlsx', (string) $preset['export_format']) ?>>Excel when available</option>
                                </select>
                            </label>
                            <label class="field">
                                <span>Visibility</span>
                                <select name="visibility">
                                    <option value="shared" <?= selected('shared', (string) $preset['visibility']) ?>>Shared</option>
                                    <option value="private" <?= selected('private', (string) $preset['visibility']) ?>>Private</option>
                                </select>
                            </label>
                            <label class="field saved-report-filter-query">
                                <span>Filter query</span>
                                <input type="text" name="filter_query" value="<?= e($filterQuery) ?>">
                            </label>
                            <label class="field saved-report-description">
                                <span>Description</span>
                                <textarea name="description" rows="3"><?= e((string) ($preset['description'] ?? '')) ?></textarea>
                            </label>
                            <button class="ghost-button" type="submit"><?= ui_icon('save') ?><span>Update</span></button>
                        </form>
                    </details>
                    <div class="saved-report-admin-actions">
                        <form method="post" action="<?= e(url('/reports/presets/' . (int) $preset['id'] . '/duplicate')) ?>">
                            <?= csrf_field() ?>
                            <button class="ghost-button" type="submit"><?= ui_icon('copy') ?><span>Duplicate</span></button>
                        </form>
                        <form method="post" action="<?= e(url('/reports/presets/' . (int) $preset['id'] . '/archive')) ?>" data-confirm="Archive this saved preset?">
                            <?= csrf_field() ?>
                            <button class="danger-link" type="submit">Archive</button>
                        </form>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

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
