<section class="page-head">
    <div class="page-head-copy">
        <p class="eyebrow">Report tools</p>
        <h3 class="page-head-title"><?= ui_icon('save') ?><span>Saved Reports</span></h3>
        <p class="muted-copy">Store a report definition here without crowding the daily operations report.</p>
    </div>
    <div class="page-actions">
        <a class="ghost-button" href="<?= e(url('/reports')) ?>"><?= ui_icon('back') ?><span>Back To Reports</span></a>
    </div>
</section>

<?php
$savedPresets = $savedPresets ?? [];
$savedPresetTypes = $savedPresetTypes ?? [];
$currentReportQuery = (string) ($currentReportQuery ?? '');
$dateTitle = date('M j, Y');
?>

<section class="panel saved-report-panel">
    <div class="section-heading-row">
        <div>
            <p class="eyebrow">Saved report presets</p>
            <h3>Reusable Filters And Exports</h3>
            <p class="muted-copy">Save the filter state once, then open or export the same report without rebuilding it every day.</p>
        </div>
        <span class="pill pill-muted"><?= number_format(count($savedPresets)) ?> saved</span>
    </div>

    <details class="settings-accordion saved-report-create">
        <summary>
            <span><?= ui_icon('add') ?> Create Saved Report</span>
            <small>Daily operations, finance, usage, storage, purchases, assets, and stock movements.</small>
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
                <input type="text" name="filter_query" value="<?= e($currentReportQuery) ?>" placeholder="date=2026-07-22&amp;storage_id=1">
                <small>Use URL query format. Blank uses the default filters for the selected report type.</small>
            </label>
            <label class="field saved-report-description">
                <span>Description</span>
                <textarea name="description" rows="3" placeholder="Optional note describing why this report matters."></textarea>
            </label>
            <button class="primary-button" type="submit"><?= ui_icon('save') ?><span>Save Report</span></button>
        </form>
    </details>

    <?php if ($savedPresets === []): ?>
        <p class="empty-state">No saved reports yet. Use Create Saved Report when you need a reusable filter.</p>
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
                        <form method="post" action="<?= e(url('/reports/presets/' . (int) $preset['id'] . '/archive')) ?>" data-confirm="Archive this saved report?">
                            <?= csrf_field() ?>
                            <button class="danger-link" type="submit">Archive</button>
                        </form>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
