<?php
declare(strict_types=1);

// Purchase line normalization for manual drafts and OCR/import review forms.

function normalize_purchase_lines_from_request(array &$storedImages): array
{
    $itemIds = input('line_item_id', []);
    $names = input('line_item_name', []);
    $skus = input('line_item_sku', []);
    $barcodes = input('line_item_barcode', []);
    $categories = input('line_item_category', []);
    $units = input('line_unit', []);
    $customUnits = input('line_custom_unit', []);
    $quantities = input('line_quantity_requested', []);
    $costs = input('line_unit_cost_quoted', []);
    $notes = input('line_item_notes', []);
    $existingImages = input('line_existing_image_path', []);

    if (!is_array($names)) {
        return [[], ['Add at least one purchase line.']];
    }

    $lines = [];
    $errors = [];

    foreach ($names as $index => $rawName) {
        $itemId = normalize_entity_id($itemIds[$index] ?? null);
        $name = trim((string) $rawName);
        $sku = strtoupper(trim((string) ($skus[$index] ?? '')));
        $barcode = normalize_item_barcode($barcodes[$index] ?? '');
        $category = trim((string) ($categories[$index] ?? ''));
        $selectedUnit = trim((string) ($units[$index] ?? 'pcs'));
        $customUnit = trim((string) ($customUnits[$index] ?? ''));
        $unit = resolve_item_unit($selectedUnit, $customUnit);
        $quantityRaw = $quantities[$index] ?? '';
        $costRaw = $costs[$index] ?? '';
        $lineNotes = trim((string) ($notes[$index] ?? ''));

        if ($itemId === null && $name === '' && $sku === '' && trim((string) $quantityRaw) === '' && trim((string) $costRaw) === '') {
            continue;
        }

        $imagePath = null;

        if ($itemId !== null) {
            $item = Database::fetch(
                'SELECT id, name, sku, barcode, category, unit, cost_per_unit, image_path, notes
                 FROM items
                 WHERE id = :id AND is_active = 1
                 LIMIT 1',
                ['id' => $itemId]
            );

            if (!$item) {
                $errors[] = 'Pick a valid active item for every selected catalog line.';
                continue;
            }

            $name = (string) $item['name'];
            $sku = (string) $item['sku'];
            $barcode = normalize_item_barcode($item['barcode'] ?? '');
            $category = (string) ($item['category'] ?? '');
            $unit = (string) $item['unit'];
            $imagePath = $item['image_path'] ?: null;
            $lineNotes = $lineNotes !== '' ? $lineNotes : (string) ($item['notes'] ?? '');
        } else {
            if ($name === '' || $sku === '') {
                $errors[] = 'New purchase lines need an item name and SKU.';
                continue;
            }

            if ($unit === '') {
                $errors[] = 'Pick a unit for each new item.';
                continue;
            }

            if (item_barcodes_required() && $barcode === '') {
                $errors[] = 'New purchase lines need a barcode because barcode is required in Website Control.';
                continue;
            }

            if ($barcode !== '' && active_item_barcode_exists($barcode)) {
                $errors[] = 'A purchase line barcode already belongs to an active item. Select that existing item instead.';
                continue;
            }

            $lineImage = uploaded_file_at('line_image', (int) $index);
            $imageError = validate_item_image_upload($lineImage);

            if ($imageError !== null) {
                $errors[] = $imageError;
                continue;
            }

            if ($lineImage !== null) {
                $imagePath = store_item_image($lineImage, $name);
                $storedImages[] = $imagePath;
            } elseif (is_array($existingImages) && !empty($existingImages[$index])) {
                $imagePath = basename((string) $existingImages[$index]);
            }
        }

        if (!is_numeric_value($quantityRaw) || quantity_value($quantityRaw) <= 0) {
            $errors[] = 'Each purchase line needs a quantity greater than zero.';
            continue;
        }

        if (!is_numeric_value($costRaw) || quantity_value($costRaw) < 0) {
            $errors[] = 'Each purchase line needs a valid quoted unit price.';
            continue;
        }

        $lines[] = [
            'item_id' => $itemId,
            'item_name' => $name,
            'item_sku' => $sku,
            'item_barcode' => $barcode !== '' ? $barcode : null,
            'item_category' => $category !== '' ? $category : null,
            'unit' => $unit !== '' ? $unit : 'pcs',
            'item_image_path' => $imagePath,
            'item_notes' => $lineNotes !== '' ? $lineNotes : null,
            'quantity_requested' => round(quantity_value($quantityRaw), 2),
            'unit_cost_quoted' => round(quantity_value($costRaw), 2),
        ];
    }

    if ($lines === [] && $errors === []) {
        $errors[] = 'Add at least one purchase line.';
    }

    return [$lines, $errors];
}

function normalize_purchase_import_lines(int $documentIndex, int $displayNumber): array
{
    $names = purchase_import_nested_array('line_item_name', $documentIndex);

    if ($names === []) {
        return [[], ['Document ' . $displayNumber . ' needs at least one item row.']];
    }

    $lines = [];
    $errors = [];

    foreach ($names as $lineIndex => $rawName) {
        $lineIndex = (int) $lineIndex;
        $itemId = normalize_entity_id(purchase_import_nested_value('line_item_id', $documentIndex, $lineIndex));
        $name = trim((string) $rawName);
        $sku = strtoupper(purchase_import_nested_value('line_item_sku', $documentIndex, $lineIndex));
        $barcode = normalize_item_barcode(purchase_import_nested_value('line_item_barcode', $documentIndex, $lineIndex));
        $category = purchase_import_nested_value('line_item_category', $documentIndex, $lineIndex);
        $selectedUnit = purchase_import_nested_value('line_unit', $documentIndex, $lineIndex, 'pcs');
        $customUnit = purchase_import_nested_value('line_custom_unit', $documentIndex, $lineIndex);
        $unit = resolve_item_unit($selectedUnit, $customUnit);
        $quantityRaw = purchase_import_nested_value('line_quantity_requested', $documentIndex, $lineIndex);
        $costRaw = purchase_import_nested_value('line_unit_cost_quoted', $documentIndex, $lineIndex);
        $lineNotes = purchase_import_nested_value('line_item_notes', $documentIndex, $lineIndex);
        $imagePath = null;

        if ($itemId === null && $name === '' && $sku === '' && $quantityRaw === '' && $costRaw === '') {
            continue;
        }

        if ($itemId !== null) {
            $item = Database::fetch(
                'SELECT id, name, sku, barcode, category, unit, cost_per_unit, image_path, notes
                 FROM items
                 WHERE id = :id AND is_active = 1
                 LIMIT 1',
                ['id' => $itemId]
            );

            if (!$item) {
                $errors[] = 'Document ' . $displayNumber . ', line ' . ($lineIndex + 1) . ': pick a valid active catalog item.';
                continue;
            }

            $name = (string) $item['name'];
            $sku = (string) $item['sku'];
            $barcode = normalize_item_barcode($item['barcode'] ?? '');
            $category = (string) ($item['category'] ?? '');
            $unit = (string) $item['unit'];
            $imagePath = $item['image_path'] ?: null;
            $lineNotes = $lineNotes !== '' ? $lineNotes : (string) ($item['notes'] ?? '');
        } else {
            if ($name === '' || $sku === '') {
                $errors[] = 'Document ' . $displayNumber . ', line ' . ($lineIndex + 1) . ': new items need a name and SKU.';
                continue;
            }

            if ($unit === '') {
                $errors[] = 'Document ' . $displayNumber . ', line ' . ($lineIndex + 1) . ': pick a unit.';
                continue;
            }

            if (item_barcodes_required() && $barcode === '') {
                $errors[] = 'Document ' . $displayNumber . ', line ' . ($lineIndex + 1) . ': new items need a barcode because barcode is required in Website Control.';
                continue;
            }

            if ($barcode !== '' && active_item_barcode_exists($barcode)) {
                $errors[] = 'Document ' . $displayNumber . ', line ' . ($lineIndex + 1) . ': barcode already belongs to an active item. Select that existing item instead.';
                continue;
            }
        }

        if (!is_numeric_value($quantityRaw) || quantity_value($quantityRaw) <= 0) {
            $errors[] = 'Document ' . $displayNumber . ', line ' . ($lineIndex + 1) . ': quantity must be greater than zero.';
            continue;
        }

        if (!is_numeric_value($costRaw) || quantity_value($costRaw) < 0) {
            $errors[] = 'Document ' . $displayNumber . ', line ' . ($lineIndex + 1) . ': unit price must be valid.';
            continue;
        }

        $lines[] = [
            'item_id' => $itemId,
            'item_name' => $name,
            'item_sku' => $sku,
            'item_barcode' => $barcode !== '' ? $barcode : null,
            'item_category' => $category !== '' ? $category : null,
            'unit' => $unit !== '' ? $unit : 'pcs',
            'item_image_path' => $imagePath,
            'item_notes' => $lineNotes !== '' ? $lineNotes : null,
            'quantity_requested' => round(quantity_value($quantityRaw), 2),
            'unit_cost_quoted' => round(quantity_value($costRaw), 2),
        ];
    }

    if ($lines === [] && $errors === []) {
        $errors[] = 'Document ' . $displayNumber . ' needs at least one valid item row.';
    }

    return [$lines, $errors];
}
