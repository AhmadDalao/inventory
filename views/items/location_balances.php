<section class="panel" data-location-balances>
    <div class="panel-head">
        <div>
            <p class="eyebrow">Remaining</p>
            <h3>Location Balances</h3>
        </div>
    </div>

    <div class="table-wrap">
        <table class="data-table data-table-mobile">
            <thead>
            <tr>
                <th>Location</th>
                <th>Type</th>
                <th>Remaining</th>
                <th>Used</th>
                <th>Transferred In</th>
                <th>Transferred Out</th>
                <th>Stock Value</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if ($balances === []): ?>
                <tr>
                    <td colspan="8" class="empty-cell">No stock is assigned to any location yet.</td>
                </tr>
            <?php endif; ?>
            <?php foreach ($balances as $balance): ?>
                <tr>
                    <td data-label="Location">
                        <strong><?= e($balance['name']) ?></strong>
                        <?php if ((int) $balance['is_active'] === 0): ?>
                            <div class="tiny-copy">Deleted location</div>
                        <?php endif; ?>
                    </td>
                    <td data-label="Type"><?= e(storage_type_label($balance['storage_type'])) ?></td>
                    <td data-label="Remaining"><?= format_quantity($balance['quantity']) ?> <?= e($item['unit']) ?></td>
                    <td data-label="Used"><?= format_quantity($balance['total_used']) ?> <?= e($item['unit']) ?></td>
                    <td data-label="Transferred In"><?= format_quantity($balance['transferred_in']) ?> <?= e($item['unit']) ?></td>
                    <td data-label="Transferred Out"><?= format_quantity($balance['transferred_out']) ?> <?= e($item['unit']) ?></td>
                    <td data-label="Stock Value"><?= format_money(stock_value($balance['quantity'], $item['cost_per_unit'])) ?></td>
                    <td data-label="Actions">
                        <?php if ((int) $item['is_active'] === 1 && (int) $balance['is_active'] === 1 && Auth::hasPermission('items.remove_from_storage')): ?>
                            <form method="post" action="<?= e(url('/items/' . $item['id'] . '/locations/' . $balance['storage_id'] . '/remove')) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="return_to" value="<?= e('/items/' . $item['id']) ?>">
                                <button
                                    class="text-button danger-link"
                                    type="submit"
                                    data-confirm="Remove <?= e($item['name']) ?> from <?= e($balance['name']) ?> only? Other storages keep their quantities."
                                >
                                    Remove From Storage
                                </button>
                            </form>
                        <?php else: ?>
                            <span class="tiny-copy">-</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
