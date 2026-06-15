<tr>
    <td data-label="When"><?= e(format_datetime_display($movement['used_at'])) ?></td>
    <td data-label="Type"><span class="pill pill-<?= e($movement['movement_type']) ?>"><?= e(ucfirst($movement['movement_type'])) ?></span></td>
    <td data-label="Quantity"><?= format_quantity($movement['movement_quantity'] ?? abs((float) $movement['quantity_delta'])) ?> <?= e($item['unit']) ?></td>
    <td data-label="Total Change"><?= format_quantity($movement['quantity_delta']) ?> <?= e($item['unit']) ?></td>
    <td data-label="Balance After"><?= format_quantity($movement['balance_after']) ?> <?= e($item['unit']) ?></td>
    <td data-label="From"><?= e($movement['source_storage_name'] ?? '-') ?></td>
    <td data-label="To"><?= e($movement['destination_storage_name'] ?? '-') ?></td>
    <td data-label="Reference"><?= e($movement['reference_code'] ?: '-') ?></td>
    <td data-label="By"><?= e($movement['user_name'] ?: 'System') ?></td>
    <td data-label="Notes"><?= e($movement['notes'] ?: '-') ?></td>
</tr>
