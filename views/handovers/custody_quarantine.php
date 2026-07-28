<?php
$totalQuarantined = 0.0;
$remainingTotal = 0.0;

foreach ($rows as $row) {
    $totalQuarantined += (float) $row['damaged_quantity'];
    $remainingTotal += handover_custody_available_quarantine_quantity($row);
}
?>

<section class="page-head">
    <div class="page-head-copy">
        <p class="eyebrow">Controlled Stock</p>
        <h3 class="page-head-title"><?= ui_icon('flash') ?><span>Damaged / Quarantine</span></h3>
        <p class="page-head-subtitle">Damaged items stay physically traceable here until the owner returns them to service or writes them off.</p>
    </div>
    <div class="page-actions">
        <a class="ghost-button" href="<?= e(url('/handovers/custody')) ?>"><?= ui_icon('back') ?><span>Staff Custody</span></a>
    </div>
</section>

<section class="metric-grid compact-grid">
    <article class="metric-card metric-card-highlight">
        <span class="metric-card-icon"><?= ui_icon('items') ?><span>Open Lines</span></span>
        <strong><?= number_format(count($rows)) ?></strong>
        <small>Awaiting disposition</small>
    </article>
    <article class="metric-card">
        <span class="metric-card-icon"><?= ui_icon('movements') ?><span>Received Damaged</span></span>
        <strong><?= format_quantity($totalQuarantined) ?></strong>
        <small>Approved custody returns</small>
    </article>
    <article class="metric-card">
        <span class="metric-card-icon"><?= ui_icon('flash') ?><span>Still Quarantined</span></span>
        <strong><?= format_quantity($remainingTotal) ?></strong>
        <small>Not repaired or disposed</small>
    </article>
</section>

<section class="panel data-table-shell">
    <div class="table-shell-head">
        <div class="table-heading">
            <strong><?= ui_icon('items') ?><span>Quarantine Lines</span></strong>
            <span class="table-count-badge"><?= number_format(count($rows)) ?></span>
        </div>
        <p class="table-shell-copy">Only owner-approved actions can move stock out of this hidden system location.</p>
    </div>

    <div class="table-wrap">
        <table class="data-table data-table-mobile">
            <thead>
            <tr>
                <th>Item</th>
                <th>Custody Record</th>
                <th>Employee</th>
                <th>Damaged</th>
                <th>Available</th>
                <th>Return To Service</th>
                <th>Dispose / Write Off</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <?php
                $available = handover_custody_available_quarantine_quantity($row);
                $imageUrl = item_image_url($row['image_path'] ?? null);
                ?>
                <tr>
                    <td data-label="Item">
                        <a class="item-table-cell cell-link" href="<?= e(url('/items/' . $row['item_id'])) ?>">
                            <?php if ($imageUrl): ?>
                                <img class="item-thumb expandable-image" src="<?= e($imageUrl) ?>" alt="<?= e($row['item_name']) ?>" data-expand-image tabindex="0">
                            <?php else: ?>
                                <span class="item-thumb item-thumb-fallback"><?= e(item_initial($row['item_name'])) ?></span>
                            <?php endif; ?>
                            <div>
                                <strong><?= e($row['item_name']) ?></strong>
                                <div class="tiny-copy"><?= e($row['item_sku']) ?> · <?= e($row['unit']) ?></div>
                            </div>
                        </a>
                    </td>
                    <td data-label="Custody Record">
                        <a class="text-link" href="<?= e(url('/handovers/' . $row['handover_id'])) ?>"><?= e($row['handover_number']) ?></a>
                        <div class="tiny-copy"><?= e($row['return_number']) ?></div>
                    </td>
                    <td data-label="Employee"><?= e($row['recipient_name']) ?></td>
                    <td data-label="Damaged"><?= format_quantity($row['damaged_quantity']) ?> <?= e($row['unit']) ?></td>
                    <td data-label="Available"><strong><?= format_quantity($available) ?> <?= e($row['unit']) ?></strong></td>
                    <td data-label="Return To Service">
                        <form class="stack-form compact-action-form" method="post" action="<?= e(url('/handovers/custody/quarantine/' . $row['id'] . '/return-to-service')) ?>" data-live-action-form>
                            <?= csrf_field() ?>
                            <label class="field compact-field">
                                <span class="sr-only">Destination</span>
                                <select name="destination_storage_id" required>
                                    <option value="">Destination storage</option>
                                    <?php foreach ($storages as $storage): ?>
                                        <option value="<?= e((string) $storage['id']) ?>"><?= e(storage_type_label((string) $storage['storage_type']) . ' · ' . $storage['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="field compact-field">
                                <span class="sr-only">Quantity</span>
                                <input type="number" name="quantity" min="0.01" max="<?= e((string) $available) ?>" step="0.01" placeholder="Qty" required>
                            </label>
                            <label class="field compact-field">
                                <span class="sr-only">Repair reason</span>
                                <input type="text" name="reason" placeholder="Repair / inspection note" required>
                            </label>
                            <button class="ghost-button" type="submit" data-confirm="Return this quantity to active service?">Return</button>
                        </form>
                    </td>
                    <td data-label="Dispose / Write Off">
                        <form class="stack-form compact-action-form" method="post" action="<?= e(url('/handovers/custody/quarantine/' . $row['id'] . '/dispose')) ?>" data-live-action-form>
                            <?= csrf_field() ?>
                            <label class="field compact-field">
                                <span class="sr-only">Quantity</span>
                                <input type="number" name="quantity" min="0.01" max="<?= e((string) $available) ?>" step="0.01" placeholder="Qty" required>
                            </label>
                            <label class="field compact-field">
                                <span class="sr-only">Disposal reason</span>
                                <input type="text" name="reason" placeholder="Mandatory disposal reason" required>
                            </label>
                            <button class="ghost-button danger-button" type="submit" data-confirm="Permanently write off this quarantined quantity?">Dispose</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($rows === []): ?>
                <tr><td colspan="7" class="empty-state">No damaged custody items are waiting for disposition.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
