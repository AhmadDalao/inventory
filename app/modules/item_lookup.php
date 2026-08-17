<?php
declare(strict_types=1);

function active_item_by_sku(string $sku, ?int $ignoreId = null): ?array
{
    $sku = strtoupper(trim($sku));

    if ($sku === '') {
        return null;
    }

    $sql = 'SELECT * FROM items WHERE sku = :sku AND is_active = 1';
    $params = ['sku' => $sku];

    if ($ignoreId !== null) {
        $sql .= ' AND id != :ignore_id';
        $params['ignore_id'] = $ignoreId;
    }

    $sql .= ' ORDER BY id ASC LIMIT 1';

    return Database::fetch($sql, $params);
}

function active_item_sku_exists(string $sku, ?int $ignoreId = null): bool
{
    return active_item_by_sku($sku, $ignoreId) !== null;
}

function active_item_by_barcode(string $barcode, ?int $ignoreId = null): ?array
{
    $barcode = normalize_item_barcode($barcode);

    if ($barcode === '') {
        return null;
    }

    $sql = 'SELECT * FROM items WHERE barcode = :barcode AND is_active = 1';
    $params = ['barcode' => $barcode];

    if ($ignoreId !== null) {
        $sql .= ' AND id != :ignore_id';
        $params['ignore_id'] = $ignoreId;
    }

    $sql .= ' ORDER BY id ASC LIMIT 1';

    return Database::fetch($sql, $params);
}

function active_item_barcode_exists(string $barcode, ?int $ignoreId = null): bool
{
    return active_item_by_barcode($barcode, $ignoreId) !== null;
}

function requested_item_copy_source(): ?array
{
    $copyItemId = normalize_entity_id(input('copy_item_id', input('copy', old('copy_item_id'))));

    if ($copyItemId === null) {
        return null;
    }

    require_current_user_item_visibility($copyItemId);

    return find_item_or_abort($copyItemId);
}

function find_item_or_abort(int $itemId): array
{
    $item = Database::fetch(
        'SELECT i.*,
                default_storage.name AS default_storage_name,
                default_storage.storage_type AS default_storage_type,
                creator.name AS creator_name,
                updater.name AS updater_name,
                (
                    SELECT COUNT(*)
                    FROM item_storage_balances balances
                    WHERE balances.item_id = i.id
                ) AS location_count,
                (
                    SELECT GROUP_CONCAT(location_storage.name ORDER BY location_balances.quantity DESC, location_storage.name ASC SEPARATOR ", ")
                    FROM item_storage_balances location_balances
                    INNER JOIN storages location_storage ON location_storage.id = location_balances.storage_id
                    WHERE location_balances.item_id = i.id
                ) AS location_summary
         FROM items i
         LEFT JOIN storages default_storage ON default_storage.id = i.storage_id
         LEFT JOIN users creator ON creator.id = i.created_by
         LEFT JOIN users updater ON updater.id = i.updated_by
         WHERE i.id = :id
         LIMIT 1',
        ['id' => $itemId]
    );

    if (!$item) {
        abort(404, 'Item not found.');
    }

    return $item;
}
