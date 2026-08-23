<?php
declare(strict_types=1);

$filters = is_array($filters ?? null) ? $filters : [];
$rows = is_array($rows ?? null) ? $rows : [];
$counts = is_array($counts ?? null) ? $counts : [];
$items = is_array($items ?? null) ? $items : [];
$itemSummary = is_array($itemSummary ?? null) ? $itemSummary : [];
$canManage = Auth::hasPermission('wristbands.manage');
?>
<section class="page-head wristband-page-head">
    <div class="page-head-copy">
        <p class="eyebrow">External check-in evidence</p>
        <h3 class="page-head-title"><?= ui_icon('labels') ?><span>Wristband Codes</span></h3>
        <p class="page-head-subtitle">Codes identify an item and color. Registering them never changes physical inventory.</p>
    </div>
    <?php if (Auth::hasPermission('wristbands.import')): ?>
        <a class="button primary" href="/wristbands/imports"><?= ui_icon('plus') ?><span>Import Codes</span></a>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/_nav.php'; ?>

<section class="metric-grid wristband-metric-grid" aria-label="Wristband registry totals">
    <?php foreach ([
        ['label' => 'Registered', 'value' => (int) ($counts['all'] ?? 0), 'tone' => 'dark'],
        ['label' => 'Available', 'value' => (int) ($counts['available'] ?? 0), 'tone' => 'success'],
        ['label' => 'Used', 'value' => (int) ($counts['used'] ?? 0), 'tone' => 'neutral'],
        ['label' => 'Void', 'value' => (int) ($counts['void'] ?? 0), 'tone' => 'danger'],
    ] as $metric): ?>
        <article class="metric-card wristband-metric-card is-<?= e($metric['tone']) ?>">
            <span><?= e($metric['label']) ?></span>
            <strong><?= number_format($metric['value']) ?></strong>
        </article>
    <?php endforeach; ?>
</section>

<section class="panel wristband-summary-panel">
    <div class="panel-heading-row">
        <div>
            <p class="eyebrow">Physical stock vs registered codes</p>
            <h4>Tracked Items</h4>
        </div>
        <span class="table-count-badge"><?= number_format(count($itemSummary)) ?></span>
    </div>
    <div class="table-wrap wristband-table-scroll">
        <table class="data-table wristband-table">
            <thead>
            <tr>
                <th>Item</th>
                <th>Physical Total</th>
                <th>Available Codes</th>
                <th>Used Codes</th>
                <th>Void</th>
                <th>Registered</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($itemSummary === []): ?>
                <tr><td colspan="6" class="empty-cell">No item has external QR tracking enabled yet.</td></tr>
            <?php else: ?>
                <?php foreach ($itemSummary as $item): ?>
                    <?php $imageUrl = item_image_url($item['image_path'] ?? null); ?>
                    <tr>
                        <td>
                            <a class="table-item-link" href="/items/<?= (int) $item['id'] ?>">
                                <?php if ($imageUrl !== null): ?>
                                    <img class="item-thumb expandable-image" src="<?= e($imageUrl) ?>" alt="<?= e((string) $item['name']) ?>" data-expand-image tabindex="0">
                                <?php else: ?>
                                    <span class="item-thumb item-thumb-fallback"><?= e(item_initial((string) $item['name'])) ?></span>
                                <?php endif; ?>
                                <span><strong><?= e((string) $item['name']) ?></strong><small><?= e((string) $item['sku']) ?> · <?= e((string) $item['unit']) ?></small></span>
                            </a>
                        </td>
                        <td><?= e(format_quantity($item['physical_total'] ?? 0)) ?> <?= e((string) $item['unit']) ?></td>
                        <td><strong><?= number_format((int) ($item['available_codes'] ?? 0)) ?></strong></td>
                        <td><?= number_format((int) ($item['used_codes'] ?? 0)) ?></td>
                        <td><?= number_format((int) ($item['void_codes'] ?? 0)) ?></td>
                        <td><?= number_format((int) ($item['registered_codes'] ?? 0)) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel filter-panel wristband-filter-panel">
    <form method="get" action="/wristbands" class="filter-grid wristband-filter-grid">
        <label class="field">
            <span>Search</span>
            <input type="search" name="search" value="<?= e((string) ($filters['search'] ?? '')) ?>" placeholder="Masked code, item, SKU, or import">
        </label>
        <label class="field">
            <span>State</span>
            <select name="state">
                <?php foreach (['available' => 'Available', 'used' => 'Used', 'void' => 'Void', 'all' => 'All states'] as $value => $label): ?>
                    <option value="<?= e($value) ?>"<?= (string) ($filters['state'] ?? '') === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="field">
            <span>Item</span>
            <select name="item_id">
                <option value="0">All tracked items</option>
                <?php foreach ($items as $item): ?>
                    <option value="<?= (int) $item['id'] ?>"<?= (int) ($filters['item_id'] ?? 0) === (int) $item['id'] ? ' selected' : '' ?>>
                        <?= e((string) $item['name']) ?> (<?= e((string) $item['sku']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="filter-actions">
            <button class="button primary" type="submit"><?= ui_icon('filter') ?><span>Filter</span></button>
            <a class="button ghost" href="/wristbands"><?= ui_icon('back') ?><span>Reset</span></a>
        </div>
    </form>
</section>

<section class="panel data-table-shell wristband-registry-panel">
    <div class="table-shell-head">
        <div class="table-heading">
            <h4>Code Registry</h4>
            <span class="table-count-badge"><?= number_format(count($rows)) ?></span>
        </div>
        <p>Only masked values are displayed. Full wristband codes are never stored in plaintext.</p>
    </div>
    <div class="table-wrap wristband-table-scroll">
        <table class="data-table wristband-table wristband-code-table">
            <thead>
            <tr>
                <th>Code</th>
                <th>Item</th>
                <th>State</th>
                <th>Import</th>
                <th>Used In</th>
                <th>Registered</th>
                <?php if ($canManage): ?><th>Action</th><?php endif; ?>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="<?= $canManage ? 7 : 6 ?>" class="empty-cell">No wristband codes match these filters.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $state = (string) $row['state'];
                    $pillClass = $state === 'available' ? 'pill-active' : ($state === 'used' ? 'pill-muted' : 'pill-danger');
                    ?>
                    <tr>
                        <td><code class="wristband-code-mask"><?= e((string) $row['code_masked']) ?></code></td>
                        <td>
                            <a class="table-item-link compact" href="/items/<?= (int) $row['item_id'] ?>">
                                <strong><?= e((string) $row['item_name']) ?></strong>
                                <small><?= e((string) $row['sku']) ?></small>
                            </a>
                        </td>
                        <td><span class="status-pill <?= e($pillClass) ?>"><?= e(ucfirst($state)) ?></span></td>
                        <td>
                            <?php if (!empty($row['import_number'])): ?>
                                <a href="/wristbands/imports"><?= e((string) $row['import_number']) ?></a>
                            <?php else: ?>
                                <span class="muted">Manual</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($row['handover_number'])): ?>
                                <a href="/handovers/<?= (int) $row['handover_id'] ?>" class="wristband-reference-text"><?= e((string) $row['handover_number']) ?></a>
                            <?php elseif (!empty($row['session_number'])): ?>
                                <?= e((string) $row['session_number']) ?>
                            <?php else: ?>
                                <span class="muted">Not used</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e(format_datetime_display((string) $row['created_at'])) ?></td>
                        <?php if ($canManage): ?>
                            <td>
                                <?php if ($state === 'available'): ?>
                                    <form method="post" action="/wristbands/codes/<?= (int) $row['id'] ?>/state" class="wristband-inline-action">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="state" value="void">
                                        <input type="text" name="reason" placeholder="Void reason" required aria-label="Reason for voiding this code">
                                        <button class="button ghost danger" type="submit">Void</button>
                                    </form>
                                <?php elseif ($state === 'void'): ?>
                                    <form method="post" action="/wristbands/codes/<?= (int) $row['id'] ?>/state">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="state" value="available">
                                        <button class="button ghost" type="submit">Restore</button>
                                    </form>
                                <?php else: ?>
                                    <span class="muted">Immutable</span>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if (count($rows) >= 500): ?>
        <p class="panel-note">Showing the newest 500 matches. Narrow the filters to find a specific record.</p>
    <?php endif; ?>
</section>
