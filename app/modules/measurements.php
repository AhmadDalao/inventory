<?php
declare(strict_types=1);

const INVENTORY_QUANTITY_PRECISION = 6;

function inventory_quantity(float $value): float
{
    return round($value, INVENTORY_QUANTITY_PRECISION);
}

function inventory_quantity_tolerance(): float
{
    return 0.0000005;
}

function inventory_measurement_dimensions(): array
{
    return [
        'count' => 'Count',
        'volume' => 'Volume',
        'mass' => 'Mass / weight',
        'length' => 'Length',
        'area' => 'Area',
        'custom' => 'Custom',
    ];
}

function normalize_inventory_measurement_dimension(mixed $value): string
{
    $dimension = strtolower(trim((string) $value));

    return array_key_exists($dimension, inventory_measurement_dimensions()) ? $dimension : 'count';
}

function inventory_unit_dimension(string $unit): ?string
{
    $unit = strtolower(trim($unit));

    $map = [
        'pcs' => 'count', 'piece' => 'count', 'pieces' => 'count', 'unit' => 'count',
        'box' => 'count', 'pack' => 'count', 'carton' => 'count', 'set' => 'count',
        'roll' => 'count', 'bottle' => 'count',
        'ml' => 'volume', 'milliliter' => 'volume', 'milliliters' => 'volume',
        'l' => 'volume', 'liter' => 'volume', 'litre' => 'volume',
        'g' => 'mass', 'gram' => 'mass', 'grams' => 'mass',
        'kg' => 'mass', 'kilogram' => 'mass', 'kilograms' => 'mass',
        'mm' => 'length', 'millimeter' => 'length', 'millimeters' => 'length',
        'cm' => 'length', 'centimeter' => 'length', 'centimeters' => 'length',
        'm' => 'length', 'meter' => 'length', 'metre' => 'length',
        'm2' => 'area', 'm²' => 'area', 'sqm' => 'area',
    ];

    return $map[$unit] ?? null;
}

function inventory_measurement_matches_unit(string $dimension, string $unit): bool
{
    $knownDimension = inventory_unit_dimension($unit);

    return $knownDimension === null || $dimension === 'custom' || $knownDimension === $dimension;
}

function inventory_item_unit_sql_expression(string $alias = 'i'): string
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias)) {
        throw new InvalidArgumentException('Invalid item table alias.');
    }

    // Custom unit labels are persisted directly in items.unit. custom_unit is
    // only a form field and is not a database column.
    return "COALESCE(NULLIF({$alias}.unit, ''), 'pcs')";
}

function inventory_proof_policies(): array
{
    return [
        'inherit' => 'Inherit global setting',
        'required' => 'Required',
        'optional' => 'Optional',
    ];
}

function normalize_inventory_proof_policy(mixed $value): string
{
    $policy = strtolower(trim((string) $value));

    return array_key_exists($policy, inventory_proof_policies()) ? $policy : 'inherit';
}

function item_canonical_unit(array $item): string
{
    $unit = trim((string) ($item['unit'] ?? 'pcs'));
    if ($unit === 'custom') {
        $unit = trim((string) ($item['custom_unit'] ?? ''));
    }

    return $unit !== '' ? $unit : 'pcs';
}

function resolve_inventory_measurement(
    array $item,
    mixed $inputQuantity,
    ?int $packagePresetId = null,
    bool $requireActivePreset = true
): array {
    if (!is_numeric_value($inputQuantity)) {
        throw new InvalidArgumentException('Enter a valid quantity.');
    }

    $enteredQuantity = inventory_quantity(quantity_value($inputQuantity));
    if ($enteredQuantity <= 0) {
        throw new InvalidArgumentException('Quantity must be greater than zero.');
    }

    $preset = null;
    $conversion = 1.0;
    if ($packagePresetId !== null) {
        $preset = Database::fetch(
            'SELECT id, item_id, label, scan_code, pieces_per_unit, is_active
             FROM item_package_presets
             WHERE id = :id AND item_id = :item_id
             LIMIT 1',
            ['id' => $packagePresetId, 'item_id' => (int) $item['id']]
        );
        if ($preset === null) {
            throw new InvalidArgumentException('That package preset does not belong to this item.');
        }
        if ($requireActivePreset && (int) ($preset['is_active'] ?? 1) !== 1) {
            throw new InvalidArgumentException('That package preset is disabled.');
        }

        $conversion = inventory_quantity((float) $preset['pieces_per_unit']);
        if ($conversion <= 0) {
            throw new RuntimeException('That package preset has an invalid conversion.');
        }
    }

    $baseQuantity = inventory_quantity($enteredQuantity * $conversion);
    if ($baseQuantity <= 0) {
        throw new InvalidArgumentException('The converted stock quantity must be greater than zero.');
    }

    return [
        'input_quantity' => $enteredQuantity,
        'package_preset_id' => $preset !== null ? (int) $preset['id'] : null,
        'package_label' => $preset !== null ? (string) $preset['label'] : null,
        'package_scan_code' => $preset !== null ? trim((string) ($preset['scan_code'] ?? '')) ?: null : null,
        'conversion' => $conversion,
        'base_quantity' => $baseQuantity,
        'base_unit' => item_canonical_unit($item),
        'measurement_dimension' => normalize_inventory_measurement_dimension($item['measurement_dimension'] ?? 'count'),
    ];
}

function inventory_base_measurement(array $item, float $baseQuantity): array
{
    return [
        'input_quantity' => inventory_quantity(abs($baseQuantity)),
        'package_preset_id' => null,
        'package_label' => null,
        'package_scan_code' => null,
        'conversion' => 1.0,
        'base_quantity' => inventory_quantity(abs($baseQuantity)),
        'base_unit' => item_canonical_unit($item),
        'measurement_dimension' => normalize_inventory_measurement_dimension($item['measurement_dimension'] ?? 'count'),
    ];
}

function inventory_actor_department_snapshot(int $userId, ?int $overrideDepartmentId = null): array
{
    $user = Database::fetch(
        'SELECT employee.id,
                employee.department_id,
                department.name AS department_name,
                department.code AS department_code,
                employee.manager_user_id,
                manager.name AS manager_name
         FROM users employee
         LEFT JOIN departments department ON department.id = employee.department_id
         LEFT JOIN users manager ON manager.id = employee.manager_user_id
         WHERE employee.id = :id
         LIMIT 1',
        ['id' => $userId]
    ) ?: [];

    if ($overrideDepartmentId !== null) {
        $department = Database::fetch(
            'SELECT id, name, code
             FROM departments
             WHERE id = :id AND is_active = 1 AND deleted_at IS NULL
             LIMIT 1',
            ['id' => $overrideDepartmentId]
        );
        if ($department === null) {
            throw new InvalidArgumentException('That department is not available.');
        }
        $user['department_id'] = (int) $department['id'];
        $user['department_name'] = (string) $department['name'];
        $user['department_code'] = (string) $department['code'];
    }

    $departmentId = normalize_entity_id($user['department_id'] ?? null);
    $departmentCode = strtoupper(trim((string) ($user['department_code'] ?? '')));
    if (site_setting('departments.require_assignment', '0') === '1'
        && ($departmentId === null || $departmentCode === 'UNASSIGNED')) {
        throw new RuntimeException('Assign this employee to a department before recording stock activity.');
    }

    return [
        'department_id' => $departmentId,
        'department_name' => trim((string) ($user['department_name'] ?? '')) ?: 'Unassigned',
        'manager_user_id' => normalize_entity_id($user['manager_user_id'] ?? null),
        'manager_name' => trim((string) ($user['manager_name'] ?? '')) ?: null,
    ];
}

function record_inventory_movement_measurement(
    int $movementId,
    array $item,
    float $baseQuantity,
    int $performedBy,
    ?array $measurement = null,
    ?int $overrideDepartmentId = null
): void {
    $measurement ??= inventory_base_measurement($item, $baseQuantity);
    $actor = inventory_actor_department_snapshot($performedBy, $overrideDepartmentId);

    Database::execute(
        'INSERT INTO inventory_movement_measurement_details (
            movement_id, input_quantity, package_preset_id, package_label,
            package_scan_code, conversion_multiplier, base_quantity, base_unit,
            measurement_dimension, reason_code, custom_reason, department_id, department_name,
            manager_user_id, manager_name, created_at
         ) VALUES (
            :movement_id, :input_quantity, :package_preset_id, :package_label,
            :package_scan_code, :conversion_multiplier, :base_quantity, :base_unit,
            :measurement_dimension, :reason_code, :custom_reason, :department_id, :department_name,
            :manager_user_id, :manager_name, NOW()
         )
         ON DUPLICATE KEY UPDATE movement_id = VALUES(movement_id)',
        [
            'movement_id' => $movementId,
            'input_quantity' => $measurement['input_quantity'],
            'package_preset_id' => $measurement['package_preset_id'] ?? null,
            'package_label' => $measurement['package_label'] ?? null,
            'package_scan_code' => $measurement['package_scan_code'] ?? null,
            'conversion_multiplier' => $measurement['conversion'] ?? 1,
            'base_quantity' => inventory_quantity((float) ($measurement['base_quantity'] ?? abs($baseQuantity))),
            'base_unit' => (string) ($measurement['base_unit'] ?? item_canonical_unit($item)),
            'measurement_dimension' => normalize_inventory_measurement_dimension($measurement['measurement_dimension'] ?? ($item['measurement_dimension'] ?? 'count')),
            'reason_code' => isset($measurement['reason_code']) && trim((string) $measurement['reason_code']) !== ''
                ? mobile_usage_reason_normalize_code((string) $measurement['reason_code'])
                : null,
            'custom_reason' => isset($measurement['custom_reason']) && trim((string) $measurement['custom_reason']) !== ''
                ? mb_substr(trim((string) $measurement['custom_reason']), 0, 160)
                : null,
            'department_id' => $actor['department_id'],
            'department_name' => $actor['department_name'],
            'manager_user_id' => $actor['manager_user_id'],
            'manager_name' => $actor['manager_name'],
        ]
    );
}

function inventory_measurement_from_payload(array $item, array $payload): array
{
    $hasInputQuantity = array_key_exists('input_quantity', $payload)
        && $payload['input_quantity'] !== null
        && $payload['input_quantity'] !== '';
    $inputQuantity = $hasInputQuantity ? $payload['input_quantity'] : ($payload['quantity'] ?? null);
    $packagePresetId = normalize_entity_id($payload['package_preset_id'] ?? null);

    try {
        return resolve_inventory_measurement($item, $inputQuantity, $packagePresetId);
    } catch (InvalidArgumentException $exception) {
        throw $exception;
    }
}

function inventory_measurement_response(array $measurement): array
{
    return [
        'input_quantity' => inventory_quantity((float) ($measurement['input_quantity'] ?? 0)),
        'package_preset_id' => normalize_entity_id($measurement['package_preset_id'] ?? null),
        'package_label' => $measurement['package_label'] ?? null,
        'package_scan_code' => $measurement['package_scan_code'] ?? null,
        'conversion' => inventory_quantity((float) ($measurement['conversion'] ?? 1)),
        'base_quantity' => inventory_quantity((float) ($measurement['base_quantity'] ?? 0)),
        'base_unit' => (string) ($measurement['base_unit'] ?? 'pcs'),
        'measurement_dimension' => normalize_inventory_measurement_dimension($measurement['measurement_dimension'] ?? 'count'),
        'reason_code' => isset($measurement['reason_code']) && trim((string) $measurement['reason_code']) !== ''
            ? mobile_usage_reason_normalize_code((string) $measurement['reason_code'])
            : null,
        'custom_reason' => isset($measurement['custom_reason']) && trim((string) $measurement['custom_reason']) !== ''
            ? trim((string) $measurement['custom_reason'])
            : null,
    ];
}

function register_inventory_operation_proof(
    array $storedProof,
    array $movementIds,
    string $sourceType,
    int $sourceId,
    string $reference,
    int $uploadedBy,
    string $documentRole,
    string $contextType = 'mobile_operation'
): ?int {
    $storedFilename = basename((string) ($storedProof['stored_filename'] ?? ''));
    if ($storedFilename === '') {
        return null;
    }

    $relativePath = file_asset_relative_path('storage/workflows', $storedFilename);
    register_file_asset([
        'source_type' => $sourceType,
        'source_id' => $sourceId,
        'context_type' => $contextType,
        'context_id' => $sourceId,
        'display_name' => trim($reference . ' · ' . ucfirst(str_replace('_', ' ', $documentRole))),
        'original_filename' => (string) ($storedProof['original_filename'] ?? $storedFilename),
        'stored_filename' => $storedFilename,
        'relative_path' => $relativePath,
        'mime_type' => (string) ($storedProof['mime_type'] ?? 'application/octet-stream'),
        'file_size' => (int) ($storedProof['file_size'] ?? 0),
        'file_group' => 'workflow_proof',
        'uploaded_by' => $uploadedBy,
    ]);

    $fileAssetId = normalize_entity_id(Database::scalar(
        'SELECT id FROM file_assets WHERE relative_path = :relative_path AND deleted_at IS NULL LIMIT 1',
        ['relative_path' => $relativePath]
    ));
    if ($fileAssetId === null) {
        throw new RuntimeException('The proof image was stored but could not be indexed.');
    }

    foreach (array_values(array_unique(array_filter(array_map('intval', $movementIds)))) as $movementId) {
        Database::execute(
            'INSERT IGNORE INTO inventory_movement_documents (movement_id, file_asset_id, document_role, created_at)
             VALUES (:movement_id, :file_asset_id, :document_role, NOW())',
            [
                'movement_id' => $movementId,
                'file_asset_id' => $fileAssetId,
                'document_role' => $documentRole,
            ]
        );
    }

    return $fileAssetId;
}

function inventory_operation_requires_proof(array $items, string $operationType): bool
{
    $operationType = $operationType === 'refill' ? 'refill' : 'usage';
    $column = $operationType . '_proof_policy';
    $defaultKey = 'proof.' . $operationType . '_default';
    $legacyUsageRequired = $operationType === 'usage' && site_setting('mobile.require_usage_proof', '0') === '1';
    $defaultRequired = site_setting($defaultKey, $legacyUsageRequired ? 'required' : 'optional') === 'required';

    foreach ($items as $item) {
        $policy = normalize_inventory_proof_policy($item[$column] ?? 'inherit');
        if ($policy === 'required' || ($policy === 'inherit' && $defaultRequired)) {
            return true;
        }
    }

    return false;
}
