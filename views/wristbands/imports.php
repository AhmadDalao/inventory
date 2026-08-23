<?php
declare(strict_types=1);

$imports = is_array($imports ?? null) ? $imports : [];
$items = is_array($items ?? null) ? $items : [];
$canImport = Auth::hasPermission('wristbands.import');
?>
<section class="page-head wristband-page-head">
    <div class="page-head-copy">
        <p class="eyebrow">Bulk registry intake</p>
        <h3 class="page-head-title"><?= ui_icon('export') ?><span>Wristband Imports</span></h3>
        <p class="page-head-subtitle">Load CSV or XLSX codes without touching item or storage quantities.</p>
    </div>
</section>

<?php require __DIR__ . '/_nav.php'; ?>

<?php if ($canImport): ?>
<section class="panel wristband-import-panel">
    <div class="panel-heading-row">
        <div>
            <p class="eyebrow">New import</p>
            <h4>Register Codes</h4>
        </div>
        <span class="status-pill pill-warning">No stock impact</span>
    </div>
    <form method="post" action="/wristbands/imports" enctype="multipart/form-data" class="wristband-import-form" data-wristband-import-form>
        <?= csrf_field() ?>
        <fieldset class="wristband-mapping-options">
            <legend>How should rows map to items?</legend>
            <label class="wristband-choice-card">
                <input type="radio" name="mapping_mode" value="selected_item" checked data-wristband-mapping-mode>
                <span><strong>One selected item</strong><small>Every code in the file belongs to one wristband item/color.</small></span>
            </label>
            <label class="wristband-choice-card">
                <input type="radio" name="mapping_mode" value="code_sku" data-wristband-mapping-mode>
                <span><strong>Code + SKU columns</strong><small>Each row contains a code and the matching item SKU.</small></span>
            </label>
        </fieldset>
        <div class="wristband-import-fields">
            <label class="field" data-wristband-selected-item>
                <span>Wristband Item</span>
                <select name="selected_item_id">
                    <option value="0">Choose tracked item</option>
                    <?php foreach ($items as $item): ?>
                        <option value="<?= (int) $item['id'] ?>"><?= e((string) $item['name']) ?> (<?= e((string) $item['sku']) ?>)</option>
                    <?php endforeach; ?>
                </select>
                <small>Only items with External QR Tracking enabled appear here.</small>
            </label>
            <label class="field">
                <span>CSV or Excel File</span>
                <input type="file" name="wristband_file" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
                <small>Maximum 20 MB. Header aliases such as code, qr_code, wristband_code, and SKU are recognized.</small>
            </label>
        </div>
        <div class="wristband-import-guide">
            <strong>Expected columns</strong>
            <code>code</code>
            <span>or</span>
            <code>code, sku</code>
            <p>Duplicate and invalid rows are skipped. Used codes can never be re-imported as available.</p>
        </div>
        <button class="button primary" type="submit"><?= ui_icon('plus') ?><span>Import Wristband Codes</span></button>
    </form>
</section>
<?php endif; ?>

<section class="panel data-table-shell">
    <div class="table-shell-head">
        <div class="table-heading">
            <h4>Import History</h4>
            <span class="table-count-badge"><?= number_format(count($imports)) ?></span>
        </div>
        <p>Each import keeps its source hash and immutable row totals for audit.</p>
    </div>
    <div class="table-wrap wristband-table-scroll">
        <table class="data-table wristband-table">
            <thead>
            <tr>
                <th>Import</th>
                <th>Source</th>
                <th>Mapping</th>
                <th>Total</th>
                <th>Imported</th>
                <th>Duplicates</th>
                <th>Invalid</th>
                <th>By</th>
                <th>Created</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($imports === []): ?>
                <tr><td colspan="9" class="empty-cell">No wristband imports yet.</td></tr>
            <?php else: ?>
                <?php foreach ($imports as $import): ?>
                    <tr>
                        <td><strong><?= e((string) $import['import_number']) ?></strong></td>
                        <td><?= e((string) $import['source_filename']) ?></td>
                        <td>
                            <?php if ((string) $import['mapping_mode'] === 'selected_item'): ?>
                                <?= e((string) ($import['selected_item_name'] ?? 'Deleted item')) ?>
                                <small class="table-subtext"><?= e((string) ($import['selected_item_sku'] ?? '')) ?></small>
                            <?php else: ?>
                                Code + SKU
                            <?php endif; ?>
                        </td>
                        <td><?= number_format((int) $import['total_rows']) ?></td>
                        <td><strong><?= number_format((int) $import['imported_rows']) ?></strong></td>
                        <td><?= number_format((int) $import['duplicate_rows']) ?></td>
                        <td><?= number_format((int) $import['invalid_rows']) ?></td>
                        <td><?= e((string) ($import['created_by_name'] ?? 'System')) ?></td>
                        <td><?= e(format_datetime_display((string) $import['created_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
