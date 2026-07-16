<?php
declare(strict_types=1);

// Domain module: shared workflow form parsing and storage/item option payloads.

function normalize_workflow_date(string $value): string
{
    $value = trim($value);

    if ($value === '') {
        return '';
    }

    $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);

    if (!$date || $date->format('Y-m-d') !== $value) {
        return '';
    }

    return $value;
}

function workflow_storage_item_catalog(): array
{
    $rows = Database::fetchAll(
        'SELECT balances.storage_id,
                i.id AS item_id,
                i.name,
                i.sku,
                i.barcode,
                i.unit,
                i.image_path,
                balances.quantity
         FROM item_storage_balances balances
         INNER JOIN items i ON i.id = balances.item_id
         INNER JOIN storages s ON s.id = balances.storage_id
         WHERE i.is_active = 1
           AND s.is_active = 1
           AND s.is_system = 0
         ORDER BY s.name ASC, i.name ASC'
    );

    $catalog = [];

    foreach ($rows as $row) {
        $storageId = (int) $row['storage_id'];
        $catalog[$storageId][] = [
            'id' => (int) $row['item_id'],
            'name' => (string) $row['name'],
            'sku' => (string) $row['sku'],
            'barcode' => (string) ($row['barcode'] ?? ''),
            'unit' => (string) $row['unit'],
            'quantity' => (float) $row['quantity'],
            'label' => $row['name'] . ' (' . $row['sku'] . ')',
            'image_url' => item_image_url($row['image_path'] ?? null),
        ];
    }

    return $catalog;
}

function workflow_storage_meta(array $storages): array
{
    $meta = [];

    foreach ($storages as $storage) {
        $meta[(int) $storage['id']] = [
            'id' => (int) $storage['id'],
            'name' => (string) $storage['name'],
            'storage_type' => (string) $storage['storage_type'],
            'owner_user_id' => !empty($storage['owner_user_id']) ? (int) $storage['owner_user_id'] : null,
            'owner_name' => (string) ($storage['owner_name'] ?? ''),
        ];
    }

    return $meta;
}

function parse_workflow_lines(): array
{
    $itemIds = input('line_item_id', []);
    $quantities = input('line_quantity', []);

    if (!is_array($itemIds) || !is_array($quantities)) {
        return [[], ['Add at least one valid item line.']];
    }

    $lines = [];
    $errors = [];

    foreach ($itemIds as $index => $rawItemId) {
        $itemId = normalize_entity_id($rawItemId);
        $rawQuantity = $quantities[$index] ?? '';
        $quantityString = trim((string) $rawQuantity);

        if ($itemId === null && $quantityString === '') {
            continue;
        }

        if ($itemId === null) {
            $errors[] = 'Pick a valid item for every request line.';
            continue;
        }

        if (!is_numeric_value($rawQuantity) || quantity_value($rawQuantity) <= 0) {
            $errors[] = 'Each line needs a quantity greater than zero.';
            continue;
        }

        $lines[$itemId] = ($lines[$itemId] ?? 0.0) + quantity_value($rawQuantity);
    }

    $normalized = [];

    foreach ($lines as $itemId => $quantity) {
        $normalized[] = [
            'item_id' => (int) $itemId,
            'quantity' => round((float) $quantity, 2),
        ];
    }

    if ($normalized === [] && $errors === []) {
        $errors[] = 'Add at least one item line.';
    }

    return [$normalized, $errors];
}
