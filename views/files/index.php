<?php
$exportQuery = http_build_query(array_filter($filters, static fn ($value): bool => $value !== '' && $value !== null));
$activeCount = (int) ($counts['all']['file_count'] ?? 0);
$activeSize = (float) ($counts['all']['total_size'] ?? 0);
?>

<section class="page-head">
    <div class="page-head-copy">
        <p class="eyebrow"><?= e(site_setting('page.files_eyebrow', 'Document library')) ?></p>
        <h3 class="page-head-title"><?= ui_icon('files') ?><span><?= e(site_setting('page.files', 'Files')) ?></span></h3>
    </div>
    <div class="page-actions">
        <?php if (file_library_can_export()): ?>
            <a class="ghost-button" href="<?= e(url('/exports/files') . ($exportQuery ? '?' . $exportQuery : '')) ?>"><?= ui_icon('export') ?><span>Export CSV</span></a>
        <?php endif; ?>
    </div>
</section>

<div class="live-filter-region" data-live-filter-region="files">
<section class="metric-grid metric-grid-compact">
    <article class="metric-card">
        <span class="metric-card-icon"><?= ui_icon('files') ?><span>Tracked Files</span></span>
        <strong><?= number_format($activeCount) ?></strong>
        <p>Available protected copies.</p>
    </article>
    <article class="metric-card">
        <span class="metric-card-icon"><?= ui_icon('document') ?><span>Total Size</span></span>
        <strong><?= e(format_file_size($activeSize)) ?></strong>
        <p>Current active file library size.</p>
    </article>
    <article class="metric-card">
        <span class="metric-card-icon"><?= ui_icon('items') ?><span>Item Images</span></span>
        <strong><?= number_format((int) ($counts['item_image']['file_count'] ?? 0) + (int) ($counts['purchase_line_image']['file_count'] ?? 0)) ?></strong>
        <p>Catalog and purchase-line images.</p>
    </article>
    <article class="metric-card">
        <span class="metric-card-icon"><?= ui_icon('purchases') ?><span>Workflow Docs</span></span>
        <strong><?= number_format((int) ($counts['purchase_document']['file_count'] ?? 0) + (int) ($counts['workflow_proof']['file_count'] ?? 0) + (int) ($counts['workflow_pdf']['file_count'] ?? 0) + (int) ($counts['workflow_excel']['file_count'] ?? 0)) ?></strong>
        <p>Purchases, proof images, PDFs, and sign-off sheets.</p>
    </article>
</section>

<section class="filter-panel">
    <form class="filter-grid" method="get" action="<?= e(url('/files')) ?>" data-live-filter-form>
        <label class="field">
            <span>Search</span>
            <input type="text" name="search" value="<?= e($filters['search']) ?>" placeholder="Filename, item, supplier, purchase, uploader">
        </label>

        <label class="field">
            <span>Type</span>
            <select name="group">
                <?php foreach ($groups as $groupValue => $groupLabel): ?>
                    <option value="<?= e($groupValue) ?>" <?= selected($groupValue, $filters['group']) ?>><?= e($groupLabel) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="field">
            <span>Status</span>
            <select name="status">
                <?php foreach ($statuses as $statusValue => $statusLabel): ?>
                    <option value="<?= e($statusValue) ?>" <?= selected($statusValue, $filters['status']) ?>><?= e($statusLabel) ?></option>
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
            <a class="ghost-button" href="<?= e(url('/files')) ?>" data-live-filter-link><?= ui_icon('back') ?><span>Reset</span></a>
        </div>
    </form>

    <div class="chip-row">
        <?php foreach ($groups as $groupValue => $groupLabel): ?>
            <?php
            $chipQuery = $filters;
            $chipQuery['group'] = $groupValue;
            $chipUrl = url('/files?' . http_build_query(array_filter($chipQuery, static fn ($value): bool => $value !== '' && $value !== null)));
            $chipCount = (int) ($counts[$groupValue]['file_count'] ?? 0);
            ?>
            <a class="stat-chip filter-chip <?= $filters['group'] === $groupValue ? 'filter-chip-active' : '' ?>" href="<?= e($chipUrl) ?>" data-live-filter-link>
                <?= e($groupLabel) ?>: <?= number_format($chipCount) ?>
            </a>
        <?php endforeach; ?>
    </div>
</section>

<section class="panel data-table-shell" data-table-shell data-empty-text="No files match this search.">
    <div class="table-shell-head">
        <div class="table-heading">
            <strong><?= ui_icon('files') ?><span><?= e(site_setting('table.files', 'File Library')) ?></span></strong>
            <span class="table-count-badge" data-table-total><?= number_format(count($files)) ?></span>
        </div>
        <p class="table-shell-copy">Every uploaded image, purchase document, proof image, and sign-off PDF gets a protected tracking copy here.</p>
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
                <span class="sr-only">Search files</span>
                <input type="search" data-table-search placeholder="Search this file list">
            </label>
        </div>

        <?php if (file_library_can_export()): ?>
            <a class="ghost-button table-export-button" href="<?= e(url('/exports/files') . ($exportQuery ? '?' . $exportQuery : '')) ?>"><?= ui_icon('export') ?><span>Export CSV</span></a>
        <?php endif; ?>
    </div>

    <div class="table-wrap">
        <table class="data-table data-table-mobile">
            <thead>
            <tr>
                <th>File</th>
                <th>Type</th>
                <th>Source</th>
                <th>Uploaded By</th>
                <th>Size</th>
                <th>Uploaded</th>
                <th>Status</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if ($files === []): ?>
                <tr>
                    <td colspan="8" class="empty-cell">No files found.</td>
                </tr>
            <?php endif; ?>
            <?php foreach ($files as $file): ?>
                <?php
                $previewUrl = file_asset_preview_url($file);
                $contextUrl = file_asset_context_url($file);
                $exists = file_asset_exists($file);
                $isDeleted = !empty($file['deleted_at']);
                ?>
                <tr>
                    <td data-label="File">
                        <div class="item-table-cell">
                            <?php if ($previewUrl): ?>
                                <img class="item-thumb expandable-image" src="<?= e($previewUrl) ?>" alt="<?= e($file['display_name']) ?>" data-expand-image tabindex="0">
                            <?php else: ?>
                                <span class="item-thumb item-thumb-fallback"><?= ui_icon(starts_with((string) $file['mime_type'], 'image/') ? 'items' : 'document') ?></span>
                            <?php endif; ?>
                            <div>
                                <strong><?= e($file['display_name']) ?></strong>
                                <div class="tiny-copy"><?= e($file['original_filename']) ?></div>
                            </div>
                        </div>
                    </td>
                    <td data-label="Type">
                        <strong><?= e(file_asset_source_label((string) $file['source_type'])) ?></strong>
                        <div class="tiny-copy"><?= e((string) $file['mime_type']) ?></div>
                    </td>
                    <td data-label="Source">
                        <?php if ($contextUrl): ?>
                            <a class="cell-link cell-link-compact" href="<?= e($contextUrl) ?>">
                                <strong><?= e(file_asset_context_label($file)) ?></strong>
                                <?php if (!empty($file['supplier_name']) || !empty($file['storage_name'])): ?>
                                    <div class="tiny-copy"><?= e(trim((string) ($file['supplier_name'] ?? '') . (!empty($file['storage_name']) ? ' · ' . $file['storage_name'] : ''))) ?></div>
                                <?php endif; ?>
                            </a>
                        <?php else: ?>
                            <span><?= e(file_asset_context_label($file)) ?></span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Uploaded By">
                        <?= e($file['uploaded_by_name'] ?: 'System') ?>
                        <?php if (!empty($file['uploaded_by_email'])): ?>
                            <div class="tiny-copy"><?= e($file['uploaded_by_email']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td data-label="Size"><?= e(format_file_size($file['file_size'])) ?></td>
                    <td data-label="Uploaded"><?= e(format_datetime_display((string) $file['created_at'])) ?></td>
                    <td data-label="Status">
                        <?php if (!$exists): ?>
                            <span class="pill pill-danger">Missing copy</span>
                        <?php elseif ($isDeleted): ?>
                            <span class="pill pill-muted">Source deleted</span>
                        <?php else: ?>
                            <span class="pill pill-active">Available</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Actions">
                        <?php if ($exists && file_library_can_download()): ?>
                            <a class="text-link" href="<?= e(url('/files/' . $file['id'] . '/download')) ?>">Download</a>
                        <?php else: ?>
                            <span class="tiny-copy">No download</span>
                        <?php endif; ?>
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
