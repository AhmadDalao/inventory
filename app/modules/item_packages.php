<?php
declare(strict_types=1);

function normalize_package_preset_label($value): string
{
    $label = trim((string) $value);
    $label = preg_replace('/\s+/u', ' ', $label) ?: '';

    return mb_substr($label, 0, 60);
}

function item_package_presets(int $itemId): array
{
    $rows = Database::fetchAll(
        'SELECT presets.*,
                created_user.name AS created_by_name,
                updated_user.name AS updated_by_name
         FROM item_package_presets presets
         LEFT JOIN users created_user ON created_user.id = presets.created_by
         LEFT JOIN users updated_user ON updated_user.id = presets.updated_by
         WHERE presets.item_id = :item_id
         ORDER BY presets.is_default DESC, presets.label ASC',
        ['item_id' => $itemId]
    );

    return array_map(static function (array $preset): array {
        return [
            'id' => (int) $preset['id'],
            'item_id' => (int) $preset['item_id'],
            'label' => (string) $preset['label'],
            'pieces_per_unit' => format_quantity($preset['pieces_per_unit']),
            'pieces_per_unit_raw' => (float) $preset['pieces_per_unit'],
            'is_default' => (int) $preset['is_default'],
            'created_by_name' => $preset['created_by_name'] ?? null,
            'updated_by_name' => $preset['updated_by_name'] ?? null,
        ];
    }, $rows);
}

function item_package_preset_record(int $itemId, int $presetId): ?array
{
    return Database::fetch(
        'SELECT *
         FROM item_package_presets
         WHERE item_id = :item_id
           AND id = :id
         LIMIT 1',
        [
            'item_id' => $itemId,
            'id' => $presetId,
        ]
    );
}

function ensure_item_package_default(int $itemId): void
{
    $defaultExists = (int) Database::scalar(
        'SELECT COUNT(*)
         FROM item_package_presets
         WHERE item_id = :item_id
           AND is_default = 1',
        ['item_id' => $itemId]
    );

    if ($defaultExists > 0) {
        return;
    }

    $firstPresetId = Database::scalar(
        'SELECT id
         FROM item_package_presets
         WHERE item_id = :item_id
         ORDER BY id ASC
         LIMIT 1',
        ['item_id' => $itemId]
    );

    if ($firstPresetId) {
        Database::execute(
            'UPDATE item_package_presets
             SET is_default = 1,
                 updated_at = NOW()
             WHERE id = :id',
            ['id' => (int) $firstPresetId]
        );
    }
}

function handle_item_package_preset_save_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('items.edit');
    verify_csrf();

    $item = find_item_or_abort((int) $params['id']);
    require_current_user_item_visibility((int) $item['id']);
    $user = Auth::user();
    $presetId = normalize_entity_id(input('preset_id'));
    $label = normalize_package_preset_label(input('label'));
    $piecesPerUnit = quantity_value(input('pieces_per_unit'));
    $isDefault = input('is_default') === '1';
    $errors = [];

    if ($label === '') {
        $errors[] = 'Package label is required.';
    }

    if (!is_numeric_value(input('pieces_per_unit')) || $piecesPerUnit <= 0) {
        $errors[] = 'Pieces per package must be greater than zero.';
    }

    if ($presetId !== null && item_package_preset_record((int) $item['id'], $presetId) === null) {
        $errors[] = 'That package preset no longer exists.';
    }

    $duplicateParams = [
        'item_id' => (int) $item['id'],
        'label' => $label,
    ];
    $duplicateSql = 'SELECT id
         FROM item_package_presets
         WHERE item_id = :item_id
           AND LOWER(label) = LOWER(:label)';

    if ($presetId !== null) {
        $duplicateSql .= ' AND id != :preset_id';
        $duplicateParams['preset_id'] = $presetId;
    }

    $duplicateSql .= ' LIMIT 1';
    $duplicate = Database::fetch($duplicateSql, $duplicateParams);

    if ($duplicate !== null) {
        $errors[] = 'This item already has a package preset with that label.';
    }

    if ($errors !== []) {
        flash_errors($errors);
        redirect('/items/' . $item['id']);
    }

    try {
        Database::connection()->beginTransaction();

        if ($isDefault) {
            Database::execute(
                'UPDATE item_package_presets
                 SET is_default = 0,
                     updated_by = :updated_by,
                     updated_at = NOW()
                 WHERE item_id = :item_id',
                [
                    'item_id' => (int) $item['id'],
                    'updated_by' => (int) $user['id'],
                ]
            );
        }

        if ($presetId !== null) {
            Database::execute(
                'UPDATE item_package_presets
                 SET label = :label,
                     pieces_per_unit = :pieces_per_unit,
                     is_default = :is_default,
                     updated_by = :updated_by,
                     updated_at = NOW()
                 WHERE id = :id
                   AND item_id = :item_id',
                [
                    'label' => $label,
                    'pieces_per_unit' => $piecesPerUnit,
                    'is_default' => $isDefault ? 1 : 0,
                    'updated_by' => (int) $user['id'],
                    'id' => $presetId,
                    'item_id' => (int) $item['id'],
                ]
            );
        } else {
            $hasPresets = (int) Database::scalar(
                'SELECT COUNT(*) FROM item_package_presets WHERE item_id = :item_id',
                ['item_id' => (int) $item['id']]
            ) > 0;

            Database::execute(
                'INSERT INTO item_package_presets (
                    item_id,
                    label,
                    pieces_per_unit,
                    is_default,
                    created_by,
                    updated_by,
                    created_at,
                    updated_at
                 ) VALUES (
                    :item_id,
                    :label,
                    :pieces_per_unit,
                    :is_default,
                    :created_by,
                    :updated_by,
                    NOW(),
                    NOW()
                 )',
                [
                    'item_id' => (int) $item['id'],
                    'label' => $label,
                    'pieces_per_unit' => $piecesPerUnit,
                    'is_default' => ($isDefault || !$hasPresets) ? 1 : 0,
                    'created_by' => (int) $user['id'],
                    'updated_by' => (int) $user['id'],
                ]
            );
        }

        ensure_item_package_default((int) $item['id']);
        Database::connection()->commit();
    } catch (Throwable $exception) {
        if (Database::connection()->inTransaction()) {
            Database::connection()->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/items/' . $item['id']);
    }

    flash('success', 'Package preset saved.');
    redirect('/items/' . $item['id']);
}

function handle_item_package_preset_delete_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('items.edit');
    verify_csrf();

    $item = find_item_or_abort((int) $params['id']);
    require_current_user_item_visibility((int) $item['id']);
    $presetId = normalize_entity_id($params['preset_id'] ?? null);

    if ($presetId === null || item_package_preset_record((int) $item['id'], $presetId) === null) {
        flash('danger', 'That package preset no longer exists.');
        redirect('/items/' . $item['id']);
    }

    Database::execute(
        'DELETE FROM item_package_presets
         WHERE id = :id
           AND item_id = :item_id',
        [
            'id' => $presetId,
            'item_id' => (int) $item['id'],
        ]
    );

    ensure_item_package_default((int) $item['id']);
    flash('success', 'Package preset removed.');
    redirect('/items/' . $item['id']);
}
