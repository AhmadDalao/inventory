<?php
declare(strict_types=1);

// Domain module: workflow_core. Function names are preserved for route/view compatibility.

// Moved from workflows.php.

function system_storage_blueprints(): array
{
    return [
        'request_transit' => [
            'name' => 'System Request Transit',
            'storage_type' => 'storage',
            'notes' => 'Internal buffer for approved requests that are still in transit.',
        ],
        'handover_buffer' => [
            'name' => 'System Handover Buffer',
            'storage_type' => 'storage',
            'notes' => 'Internal buffer for open handovers before used or returned stock is finalized.',
        ],
    ];
}

function system_storage_id(string $key): int
{
    $blueprints = system_storage_blueprints();

    if (!isset($blueprints[$key])) {
        throw new RuntimeException('Unknown system storage key.');
    }

    $existing = Database::fetch(
        'SELECT id
         FROM storages
         WHERE system_key = :system_key
         LIMIT 1',
        ['system_key' => $key]
    );

    if ($existing) {
        return (int) $existing['id'];
    }

    $definition = $blueprints[$key];

    Database::execute(
        'INSERT INTO storages (
            name,
            system_key,
            storage_type,
            notes,
            is_system,
            is_active,
            created_by,
            updated_by,
            created_at,
            updated_at
         ) VALUES (
            :name,
            :system_key,
            :storage_type,
            :notes,
            1,
            1,
            NULL,
            NULL,
            NOW(),
            NOW()
         )
         ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            storage_type = VALUES(storage_type),
            notes = VALUES(notes),
            is_system = 1,
            is_active = 1,
            updated_at = NOW()',
        [
            'name' => $definition['name'],
            'system_key' => $key,
            'storage_type' => $definition['storage_type'],
            'notes' => $definition['notes'],
        ]
    );

    $storage = Database::fetch(
        'SELECT id
         FROM storages
         WHERE system_key = :system_key
         LIMIT 1',
        ['system_key' => $key]
    );

    if (!$storage) {
        throw new RuntimeException('Could not create system storage.');
    }

    return (int) $storage['id'];
}

function storage_owner_record(int $storageId): ?array
{
    $owner = Database::fetch(
        'SELECT storage.id,
                storage.name AS storage_name,
                owner_user.id AS owner_user_id,
                owner_user.name AS owner_name,
                owner_user.email AS owner_email,
                owner_user.role AS owner_role,
                owner_user.is_active AS owner_is_active
         FROM storages storage
         LEFT JOIN users owner_user ON owner_user.id = storage.owner_user_id
         WHERE storage.id = :id
           AND storage.is_active = 1
           AND storage.is_system = 0
         LIMIT 1',
        ['id' => $storageId]
    );

    return $owner ?: null;
}

function visible_handover_scope(string $alias = 'h'): array
{
    $user = Auth::user();

    if ($user === null || Auth::isOwner() || !Auth::isStaff()) {
        return ['', []];
    }

    return [
        " AND ({$alias}.created_by = :handover_scope_created_by_user_id OR {$alias}.recipient_user_id = :handover_scope_recipient_user_id)",
        [
            'handover_scope_created_by_user_id' => (int) $user['id'],
            'handover_scope_recipient_user_id' => (int) $user['id'],
        ],
    ];
}

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

function next_workflow_number(string $prefix, string $table, string $column): string
{
    do {
        $candidate = strtoupper($prefix) . '-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(2)));
        $exists = (int) Database::scalar(
            'SELECT COUNT(*)
             FROM ' . $table . '
             WHERE ' . $column . ' = :value',
            ['value' => $candidate]
        ) > 0;
    } while ($exists);

    return $candidate;
}

function workflow_stock_impact(string $contextType, int $contextId): array
{
    if (!in_array($contextType, ['request', 'handover'], true) || $contextId <= 0) {
        return [];
    }

    $rows = Database::fetchAll(
        'SELECT item_id,
                movement_type,
                movement_quantity,
                quantity_delta,
                source_storage_id,
                destination_storage_id
         FROM inventory_movements
         WHERE context_type = :context_type
           AND context_id = :context_id
         ORDER BY id ASC',
        [
            'context_type' => $contextType,
            'context_id' => $contextId,
        ]
    );
    $impact = [];
    $addImpact = static function (int $itemId, ?int $storageId, float $delta) use (&$impact): void {
        $key = $itemId . ':' . (int) ($storageId ?? 0);
        $impact[$key] = [
            'item_id' => $itemId,
            'storage_id' => $storageId,
            'quantity_delta' => round(($impact[$key]['quantity_delta'] ?? 0.0) + $delta, 2),
        ];
    };

    foreach ($rows as $row) {
        $itemId = (int) ($row['item_id'] ?? 0);
        $type = (string) ($row['movement_type'] ?? '');
        $quantity = round((float) ($row['movement_quantity'] ?? 0), 2);

        if ($itemId <= 0 || $quantity <= 0) {
            continue;
        }

        if ($type === 'transfer') {
            $addImpact($itemId, isset($row['source_storage_id']) ? (int) $row['source_storage_id'] : null, -$quantity);
            $addImpact($itemId, isset($row['destination_storage_id']) ? (int) $row['destination_storage_id'] : null, $quantity);
        } elseif ($type === 'usage') {
            $addImpact($itemId, isset($row['source_storage_id']) ? (int) $row['source_storage_id'] : null, -$quantity);
        } elseif ($type === 'restock') {
            $addImpact($itemId, isset($row['destination_storage_id']) ? (int) $row['destination_storage_id'] : null, $quantity);
        } elseif ($type === 'adjustment') {
            $addImpact($itemId, isset($row['source_storage_id']) ? (int) $row['source_storage_id'] : null, round((float) ($row['quantity_delta'] ?? 0), 2));
        }
    }

    return array_values(array_filter(
        $impact,
        static fn (array $row): bool => abs((float) ($row['quantity_delta'] ?? 0)) > 0.009
    ));
}

function workflow_stock_impact_is_neutral(string $contextType, int $contextId): bool
{
    return workflow_stock_impact($contextType, $contextId) === [];
}

function workflow_void_block_reason(string $contextType, array $record, ?array $user = null): ?string
{
    $user = $user ?? Auth::user();

    if ($user === null) {
        return 'Login first.';
    }

    if (!Auth::isOwner()) {
        return 'Owner access is required to remove workflow records.';
    }

    if (!in_array($contextType, ['request', 'handover'], true)) {
        return 'This workflow type cannot be voided.';
    }

    if (!workflow_stock_impact_is_neutral($contextType, (int) ($record['id'] ?? 0))) {
        return 'This record still has stock impact. Cancel or reverse the stock first, then mark it void.';
    }

    return null;
}

function workflow_absolute_url(string $path): string
{
    $baseUrl = rtrim((string) app_config('app.url', ''), '/');

    if ($baseUrl === '') {
        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $scheme = (!empty($_SERVER['HTTPS']) && (string) $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $baseUrl = $host !== '' ? $scheme . '://' . $host : 'https://inventory.ahmaddalao.com';
    }

    return $baseUrl . url($path);
}
