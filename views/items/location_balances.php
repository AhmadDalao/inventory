<section class="panel" data-location-balances>
    <div class="panel-head">
        <div>
            <p class="eyebrow">Remaining</p>
            <h3>Location Balances</h3>
        </div>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
            <tr>
                <th>Location</th>
                <th>Type</th>
                <th>Remaining</th>
                <th>Used</th>
                <th>Transferred In</th>
                <th>Transferred Out</th>
                <th>Stock Value</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($balances === []): ?>
                <tr>
                    <td colspan="7" class="empty-cell">No stock is assigned to any location yet.</td>
                </tr>
            <?php endif; ?>
            <?php foreach ($balances as $balance): ?>
                <tr>
                    <td>
                        <strong><?= e($balance['name']) ?></strong>
                        <?php if ((int) $balance['is_active'] === 0): ?>
                            <div class="tiny-copy">Archived location</div>
                        <?php endif; ?>
                    </td>
                    <td><?= e(storage_type_label($balance['storage_type'])) ?></td>
                    <td><?= format_quantity($balance['quantity']) ?> <?= e($item['unit']) ?></td>
                    <td><?= format_quantity($balance['total_used']) ?> <?= e($item['unit']) ?></td>
                    <td><?= format_quantity($balance['transferred_in']) ?> <?= e($item['unit']) ?></td>
                    <td><?= format_quantity($balance['transferred_out']) ?> <?= e($item['unit']) ?></td>
                    <td><?= format_money(stock_value($balance['quantity'], $item['cost_per_unit'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
