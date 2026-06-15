<tr>
    <td><?= e(format_datetime_display($movement['used_at'])) ?></td>
    <td><span class="pill pill-<?= e($movement['movement_type']) ?>"><?= e(ucfirst($movement['movement_type'])) ?></span></td>
    <td><?= format_quantity($movement['quantity_delta']) ?> <?= e($item['unit']) ?></td>
    <td><?= format_quantity($movement['balance_after']) ?> <?= e($item['unit']) ?></td>
    <td><?= e($movement['reference_code'] ?: '-') ?></td>
    <td><?= e($movement['user_name'] ?: 'System') ?></td>
    <td><?= e($movement['notes'] ?: '-') ?></td>
</tr>
