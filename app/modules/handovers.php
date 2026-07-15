<?php
declare(strict_types=1);

// Domain module: handovers. Function names are preserved for route/view compatibility.

// Moved from workflows.php.

function handover_request_owner_candidates_for_select(?int $selectedId = null): array
{
    $params = [];
    $conditions = ['(
        users.is_active = 1
        AND users.role IN ("owner", "admin")
        AND EXISTS (
            SELECT 1
            FROM storages storage
            WHERE storage.owner_user_id = users.id
              AND storage.is_active = 1
              AND storage.is_system = 0
        )
    )'];

    if ($selectedId !== null) {
        $conditions[] = 'users.id = :selected_id';
        $params['selected_id'] = $selectedId;
    }

    return Database::fetchAll(
        'SELECT users.id, users.name, users.email, users.role
         FROM users
         WHERE ' . implode(' OR ', $conditions) . '
         ORDER BY FIELD(users.role, "owner", "admin"), users.name ASC',
        $params
    );
}

function handover_request_assigned_owner(array $user): ?array
{
    $assignedOwnerId = normalize_entity_id($user['assigned_owner_user_id'] ?? null);

    if ($assignedOwnerId === null) {
        return null;
    }

    return Database::fetch(
        'SELECT id, name, email, role, is_active
         FROM users
         WHERE id = :id
         LIMIT 1',
        ['id' => $assignedOwnerId]
    ) ?: null;
}

function handover_source_storages_for_user(array $user, ?int $selectedId = null): array
{
    if (($user['role'] ?? '') === 'owner') {
        return all_storages_for_select($selectedId);
    }

    return storages_owned_by_user_for_select((int) $user['id'], $selectedId);
}

function handover_request_source_storages_for_staff(array $user, ?int $selectedId = null, ?int $selectedOwnerId = null): array
{
    $assignedOwnerId = normalize_entity_id($user['assigned_owner_user_id'] ?? null);
    $requiredOwnerId = $assignedOwnerId ?? $selectedOwnerId;
    $storages = all_storages_for_select($selectedId);

    return array_values(array_filter($storages, static function (array $storage) use ($requiredOwnerId, $selectedId): bool {
        if (empty($storage['owner_user_id'])) {
            return false;
        }

        if ($selectedId !== null && (int) $storage['id'] === $selectedId) {
            return true;
        }

        if ($requiredOwnerId === null) {
            return true;
        }

        return (int) $storage['owner_user_id'] === (int) $requiredOwnerId;
    }));
}

function handover_filters(): array
{
    $status = (string) query('status', 'all');

    return [
        'search' => trim((string) query('search', '')),
        'status' => in_array($status, ['open', 'requested', 'awaiting_receipt', 'receipt_review', 'delivered', 'pending_approval', 'closed', 'rejected', 'cancelled', 'all'], true) ? $status : 'all',
        'storage_id' => ctype_digit((string) query('storage_id', '')) ? (int) query('storage_id') : null,
        'date_from' => normalize_workflow_date((string) query('date_from', '')),
        'date_to' => normalize_workflow_date((string) query('date_to', '')),
    ];
}

function build_handover_where(array $filters, string $alias = 'h'): array
{
    $conditions = [];
    $params = [];

    if ($filters['status'] === 'open') {
        $conditions[] = "{$alias}.status IN ('requested', 'awaiting_receipt', 'receipt_review', 'delivered', 'pending_approval')";
    } elseif ($filters['status'] !== 'all') {
        $conditions[] = "{$alias}.status = :handover_status";
        $params['handover_status'] = $filters['status'];
    }

    if (!empty($filters['storage_id'])) {
        $conditions[] = "{$alias}.source_storage_id = :handover_storage_id";
        $params['handover_storage_id'] = (int) $filters['storage_id'];
    }

    if ($filters['search'] !== '') {
        $conditions[] = "(
            {$alias}.handover_number LIKE :handover_search_number
            OR {$alias}.recipient_name LIKE :handover_search_recipient
            OR COALESCE({$alias}.notes, '') LIKE :handover_search_notes
            OR source_storage.name LIKE :handover_search_source_storage
            OR EXISTS (
                SELECT 1
                FROM handover_lines handover_lines
                WHERE handover_lines.handover_id = {$alias}.id
                  AND (
                      handover_lines.item_name LIKE :handover_search_item_name
                      OR handover_lines.item_sku LIKE :handover_search_item_sku
                  )
            )
        )";
        $handoverSearchLike = '%' . $filters['search'] . '%';
        $params['handover_search_number'] = $handoverSearchLike;
        $params['handover_search_recipient'] = $handoverSearchLike;
        $params['handover_search_notes'] = $handoverSearchLike;
        $params['handover_search_source_storage'] = $handoverSearchLike;
        $params['handover_search_item_name'] = $handoverSearchLike;
        $params['handover_search_item_sku'] = $handoverSearchLike;
    }

    if ($filters['date_from'] !== '') {
        $conditions[] = "{$alias}.issued_at >= :handover_date_from";
        $params['handover_date_from'] = $filters['date_from'] . ' 00:00:00';
    }

    if ($filters['date_to'] !== '') {
        $conditions[] = "{$alias}.issued_at <= :handover_date_to";
        $params['handover_date_to'] = $filters['date_to'] . ' 23:59:59';
    }

    [$scopeSql, $scopeParams] = visible_handover_scope($alias);
    $where = $conditions === [] ? 'WHERE 1 = 1' : 'WHERE ' . implode(' AND ', $conditions);

    return [$where . $scopeSql, $params + $scopeParams];
}

function parse_handover_expected_usage_by_item(array $lines): array
{
    $itemIds = input('line_item_id', []);
    $reasons = input('expected_usage_reason', []);
    $quantities = input('expected_usage_quantity', []);
    $customReasons = input('expected_usage_other', []);
    $notes = input('expected_usage_notes', []);

    if (!is_array($itemIds)) {
        return [[], []];
    }

    $lineQuantityByItem = [];

    foreach ($lines as $line) {
        $itemId = (int) ($line['item_id'] ?? 0);

        if ($itemId <= 0) {
            continue;
        }

        $lineQuantityByItem[$itemId] = round(($lineQuantityByItem[$itemId] ?? 0.0) + (float) ($line['quantity'] ?? 0), 2);
    }

    $breakdownsByItem = [];
    $errors = [];

    foreach ($itemIds as $lineIndex => $rawItemId) {
        $itemId = normalize_entity_id($rawItemId);

        if ($itemId === null || !isset($lineQuantityByItem[$itemId])) {
            continue;
        }

        $lineReasons = is_array($reasons[$lineIndex] ?? null) ? $reasons[$lineIndex] : [];
        $lineQuantities = is_array($quantities[$lineIndex] ?? null) ? $quantities[$lineIndex] : [];
        $lineCustomReasons = is_array($customReasons[$lineIndex] ?? null) ? $customReasons[$lineIndex] : [];
        $lineNotes = is_array($notes[$lineIndex] ?? null) ? $notes[$lineIndex] : [];
        $rowKeys = array_unique(array_merge(
            array_keys($lineReasons),
            array_keys($lineQuantities),
            array_keys($lineCustomReasons),
            array_keys($lineNotes)
        ));

        foreach ($rowKeys as $rowKey) {
            $rawQuantity = $lineQuantities[$rowKey] ?? '';
            $rawReason = (string) ($lineReasons[$rowKey] ?? 'unspecified');
            $rawCustomReason = trim((string) ($lineCustomReasons[$rowKey] ?? ''));
            $rawNotes = trim((string) ($lineNotes[$rowKey] ?? ''));
            $hasAnyInput = trim((string) $rawQuantity) !== ''
                || trim($rawReason) !== ''
                || $rawCustomReason !== ''
                || $rawNotes !== '';

            if (!$hasAnyInput || (trim((string) $rawQuantity) === '' && $rawCustomReason === '' && $rawNotes === '')) {
                continue;
            }

            if (!is_numeric_value($rawQuantity) || quantity_value($rawQuantity) <= 0) {
                $errors[] = 'Expected usage rows need a quantity greater than zero.';
                continue;
            }

            $breakdownsByItem[$itemId][] = [
                'reason_code' => normalize_handover_usage_reason($rawReason),
                'reason_custom' => $rawCustomReason,
                'quantity' => quantity_value($rawQuantity),
                'notes' => $rawNotes,
            ];
        }
    }

    foreach ($breakdownsByItem as $itemId => $breakdowns) {
        $expectedTotal = round(array_reduce(
            $breakdowns,
            static fn (float $carry, array $breakdown): float => $carry + (float) ($breakdown['quantity'] ?? 0),
            0.0
        ), 2);

        if ($expectedTotal > round((float) ($lineQuantityByItem[$itemId] ?? 0), 2)) {
            $errors[] = 'Expected usage cannot be greater than the planned handover quantity.';
        }
    }

    return [$breakdownsByItem, $errors];
}

function handover_recovery_target_status(array $handover, array $lines): ?string
{
    $status = (string) ($handover['status'] ?? '');

    if ($status === 'rejected') {
        return 'requested';
    }

    if ($status !== 'cancelled') {
        return null;
    }

    $wasUnissuedRequest = (string) ($handover['handover_mode'] ?? 'direct') === 'request'
        && empty($handover['request_approved_at'])
        && empty($handover['request_approved_by']);

    if ($wasUnissuedRequest) {
        return 'requested';
    }

    if (!empty($handover['receipt_reported_at'])) {
        foreach ($lines as $line) {
            if (round((float) ($line['quantity_received'] ?? 0), 2) !== round((float) ($line['quantity_handed'] ?? 0), 2)) {
                return 'receipt_review';
            }
        }

        return 'delivered';
    }

    return !empty($handover['recipient_user_id']) ? 'awaiting_receipt' : 'delivered';
}

function handover_recovery_block_reason(array $handover, array $lines, ?array $user = null): ?string
{
    $user = $user ?? Auth::user();

    if ($user === null) {
        return 'Login first.';
    }

    if (!Auth::isOwner()) {
        return 'Only the owner can recover handovers.';
    }

    $targetStatus = handover_recovery_target_status($handover, $lines);

    if ($targetStatus === null) {
        return 'Only cancelled or rejected handovers can be recovered.';
    }

    if (!workflow_stock_impact_is_neutral('handover', (int) ($handover['id'] ?? 0))) {
        return 'This handover still has active stock impact. Close or cancel the stock flow before recovery.';
    }

    if ($targetStatus !== 'requested') {
        foreach ($lines as $line) {
            $plannedQuantity = round((float) ($line['quantity_handed'] ?? 0), 2);

            if ($plannedQuantity <= 0) {
                continue;
            }

            $balance = item_storage_balance_record((int) $line['item_id'], (int) $handover['source_storage_id']);

            if ($balance === null || (float) $balance['quantity'] < $plannedQuantity) {
                return $line['item_name'] . ' no longer has enough stock to recover this handover.';
            }
        }
    }

    return null;
}

function handover_lines_have_close_quantities(array $lines): bool
{
    foreach ($lines as $line) {
        if (round((float) ($line['quantity_used'] ?? 0), 2) > 0 || round((float) ($line['quantity_returned'] ?? 0), 2) > 0) {
            return true;
        }
    }

    return false;
}

function handover_usage_reason_options(): array
{
    return [
        'unspecified' => 'Unspecified',
        'walkin' => 'Walk-in',
        'online' => 'Online',
        'event' => 'Event',
        'damage' => 'Damage',
        'sport' => 'Sport',
        'school' => 'School',
        'complimentary' => 'Complimentary',
        'noshow' => 'No Show',
        'other' => 'Other',
    ];
}

function handover_usage_reason_label(string $code, string $custom = ''): string
{
    $code = normalize_handover_usage_reason($code);
    $label = handover_usage_reason_options()[$code] ?? handover_usage_reason_options()['unspecified'];
    $custom = trim($custom);

    if ($code === 'other' && $custom !== '') {
        return $label . ': ' . $custom;
    }

    return $label;
}

function handover_usage_reason_summary(array $breakdowns, string $unit = 'pcs'): string
{
    $totals = [];

    foreach ($breakdowns as $breakdown) {
        $quantity = round((float) ($breakdown['quantity'] ?? 0), 2);

        if ($quantity <= 0) {
            continue;
        }

        $label = handover_usage_reason_label(
            (string) ($breakdown['reason_code'] ?? 'unspecified'),
            (string) ($breakdown['reason_custom'] ?? '')
        );
        $key = $label . '|' . $unit;

        if (!isset($totals[$key])) {
            $totals[$key] = [
                'label' => $label,
                'unit' => $unit !== '' ? $unit : 'pcs',
                'quantity' => 0.0,
            ];
        }

        $totals[$key]['quantity'] = round($totals[$key]['quantity'] + $quantity, 2);
    }

    if ($totals === []) {
        return '';
    }

    $parts = [];

    foreach ($totals as $total) {
        $parts[] = $total['label'] . ' ' . format_quantity((float) $total['quantity']) . ' ' . $total['unit'];
    }

    return implode('; ', $parts);
}

function handover_usage_variance_summary(array $expectedBreakdowns, array $actualBreakdowns, string $unit = 'pcs'): string
{
    $hasActual = false;
    $totals = [];
    $unit = $unit !== '' ? $unit : 'pcs';
    $collect = static function (array $breakdowns, float $multiplier) use (&$totals, &$hasActual, $unit): void {
        foreach ($breakdowns as $breakdown) {
            $quantity = round((float) ($breakdown['quantity'] ?? 0), 2);

            if ($quantity <= 0) {
                continue;
            }

            if ($multiplier > 0) {
                $hasActual = true;
            }

            $reasonCode = normalize_handover_usage_reason((string) ($breakdown['reason_code'] ?? 'unspecified'));
            $label = handover_usage_reason_label(
                $reasonCode,
                (string) ($breakdown['reason_custom'] ?? '')
            );
            $key = $label . '|' . $unit;

            if (!isset($totals[$key])) {
                $totals[$key] = [
                    'label' => $label,
                    'reason_code' => $reasonCode,
                    'unit' => $unit,
                    'quantity' => 0.0,
                ];
            }

            $totals[$key]['quantity'] = round($totals[$key]['quantity'] + ($quantity * $multiplier), 2);
        }
    };

    $collect($expectedBreakdowns, -1.0);
    $collect($actualBreakdowns, 1.0);

    if (!$hasActual) {
        return '';
    }

    $parts = [];

    foreach ($totals as $total) {
        $quantity = round((float) ($total['quantity'] ?? 0), 2);

        if (abs($quantity) < 0.01) {
            continue;
        }

        $prefix = $quantity > 0 ? '+' : '';
        $parts[] = $total['label'] . ' ' . $prefix . format_quantity($quantity) . ' ' . $total['unit'];
    }

    return $parts !== [] ? implode('; ', $parts) : 'No variance';
}

function handover_usage_breakdowns_for_lines(array $lineIds): array
{
    $lineIds = array_values(array_unique(array_filter(array_map('intval', $lineIds), static fn (int $lineId): bool => $lineId > 0)));

    if ($lineIds === []) {
        return [];
    }

    $params = [];
    $placeholders = [];

    foreach ($lineIds as $index => $lineId) {
        $key = 'line_id_' . $index;
        $placeholders[] = ':' . $key;
        $params[$key] = $lineId;
    }

    $rows = Database::fetchAll(
        'SELECT *
         FROM handover_usage_breakdowns
         WHERE handover_line_id IN (' . implode(', ', $placeholders) . ')
         ORDER BY handover_line_id ASC, id ASC',
        $params
    );
    $grouped = [];

    foreach ($rows as $row) {
        $lineId = (int) $row['handover_line_id'];
        $row['reason_code'] = normalize_handover_usage_reason((string) ($row['reason_code'] ?? ''));
        $row['reason_label'] = handover_usage_reason_label((string) $row['reason_code'], (string) ($row['reason_custom'] ?? ''));
        $row['quantity'] = round((float) ($row['quantity'] ?? 0), 2);
        $grouped[$lineId][] = $row;
    }

    return $grouped;
}

function handover_expected_usage_breakdowns_for_lines(array $lineIds): array
{
    $lineIds = array_values(array_unique(array_filter(array_map('intval', $lineIds), static fn (int $lineId): bool => $lineId > 0)));

    if ($lineIds === []) {
        return [];
    }

    $params = [];
    $placeholders = [];

    foreach ($lineIds as $index => $lineId) {
        $key = 'line_id_' . $index;
        $placeholders[] = ':' . $key;
        $params[$key] = $lineId;
    }

    $rows = Database::fetchAll(
        'SELECT *
         FROM handover_expected_usage_breakdowns
         WHERE handover_line_id IN (' . implode(', ', $placeholders) . ')
         ORDER BY handover_line_id ASC, id ASC',
        $params
    );
    $grouped = [];

    foreach ($rows as $row) {
        $lineId = (int) $row['handover_line_id'];
        $row['reason_code'] = normalize_handover_usage_reason((string) ($row['reason_code'] ?? ''));
        $row['reason_label'] = handover_usage_reason_label((string) $row['reason_code'], (string) ($row['reason_custom'] ?? ''));
        $row['quantity'] = round((float) ($row['quantity'] ?? 0), 2);
        $grouped[$lineId][] = $row;
    }

    return $grouped;
}

function handover_source_can_cover_quantities(array $handover, array $lines, string $quantityField): ?string
{
    foreach ($lines as $line) {
        $quantity = round((float) ($line[$quantityField] ?? 0), 2);

        if ($quantity <= 0) {
            continue;
        }

        $balance = item_storage_balance_record((int) $line['item_id'], (int) $handover['source_storage_id']);

        if ($balance === null || (float) $balance['quantity'] < $quantity) {
            return $line['item_name'] . ' does not have enough source stock for this status change.';
        }
    }

    return null;
}

function handover_closed_reversal_block_reason(array $handover, array $lines): ?string
{
    foreach ($lines as $line) {
        $returned = round((float) ($line['quantity_returned'] ?? 0), 2);

        if ($returned <= 0) {
            continue;
        }

        $balance = item_storage_balance_record((int) $line['item_id'], (int) $handover['source_storage_id']);

        if ($balance === null || (float) $balance['quantity'] < $returned) {
            return $line['item_name'] . ' no longer has enough returned stock in the source storage to reopen this closed handover.';
        }
    }

    return null;
}

function handover_status_override_block_reason(array $handover, array $lines, string $targetStatus, ?array $user = null): ?string
{
    $user = $user ?? Auth::user();
    $targetStatus = trim($targetStatus);
    $currentStatus = (string) ($handover['status'] ?? '');

    if ($user === null) {
        return 'Login first.';
    }

    if (!Auth::isOwner()) {
        return 'Only the owner can override handover statuses.';
    }

    if (!array_key_exists($targetStatus, handover_status_options())) {
        return 'Pick a valid handover status.';
    }

    if ($targetStatus === $currentStatus) {
        return 'This handover is already ' . handover_status_label($targetStatus) . '.';
    }

    if ($targetStatus === 'receipt_review') {
        return 'Receipt Review needs actual received quantities. Use the receipt form, or override to Delivered if everything was received.';
    }

    if ((string) ($handover['handover_mode'] ?? 'direct') !== 'request' && in_array($targetStatus, ['requested', 'rejected'], true)) {
        return 'Direct handovers do not use Requested or Rejected statuses.';
    }

    if (in_array($currentStatus, ['cancelled', 'rejected'], true)) {
        if (!workflow_stock_impact_is_neutral('handover', (int) ($handover['id'] ?? 0))) {
            return 'This handover still has active stock impact. Cancel or reverse stock before changing the status.';
        }

        if ($targetStatus === 'requested') {
            return null;
        }

        if (in_array($targetStatus, ['awaiting_receipt', 'delivered'], true)) {
            return handover_source_can_cover_quantities($handover, $lines, 'quantity_handed');
        }

        return 'Cancelled or rejected handovers can only be reopened to Requested, Awaiting Receipt, or Delivered.';
    }

    if ($currentStatus === 'closed') {
        if (!in_array($targetStatus, ['delivered', 'pending_approval'], true)) {
            return 'Closed handovers can only be reopened to Delivered or Waiting Approval.';
        }

        return handover_closed_reversal_block_reason($handover, $lines);
    }

    if ($currentStatus === 'pending_approval') {
        if (!in_array($targetStatus, ['delivered', 'closed'], true)) {
            return 'Waiting Approval can only go back to Delivered or forward to Closed.';
        }

        return null;
    }

    if ($targetStatus === 'pending_approval') {
        return 'Waiting Approval needs used and returned quantities. Use the closeout form instead.';
    }

    if ($targetStatus === 'closed') {
        if ($currentStatus !== 'delivered') {
            return 'Only Delivered handovers can be closed directly.';
        }

        return null;
    }

    if ($targetStatus === 'rejected') {
        return $currentStatus === 'requested' ? null : 'Only Requested handovers can be rejected.';
    }

    if ($targetStatus === 'cancelled') {
        if (!in_array($currentStatus, ['requested', 'awaiting_receipt', 'receipt_review', 'delivered'], true)) {
            return 'This handover cannot be cancelled from its current status.';
        }

        if ($currentStatus === 'delivered' && handover_lines_have_close_quantities($lines)) {
            return 'This handover already has usage or returned quantities. Reopen it or close it properly instead of cancelling.';
        }

        return null;
    }

    if ($targetStatus === 'requested') {
        if (!in_array($currentStatus, ['awaiting_receipt', 'receipt_review', 'delivered'], true)) {
            return 'Only active handovers can be moved back to Requested.';
        }

        if ($currentStatus === 'delivered' && handover_lines_have_close_quantities($lines)) {
            return 'Clear the usage/return closeout first. This delivered handover already has closeout quantities.';
        }

        return null;
    }

    if ($targetStatus === 'awaiting_receipt') {
        if ($currentStatus === 'requested') {
            return handover_source_can_cover_quantities($handover, $lines, 'quantity_handed');
        }

        if ($currentStatus === 'delivered') {
            if (handover_lines_have_close_quantities($lines)) {
                return 'Clear the usage/return closeout first. This delivered handover already has closeout quantities.';
            }

            foreach ($lines as $line) {
                if (round((float) ($line['quantity_received'] ?? 0), 2) !== round((float) ($line['quantity_handed'] ?? 0), 2)) {
                    return 'This delivered handover has a confirmed shortage. Reopen to Delivered, not Awaiting Receipt.';
                }
            }

            return null;
        }

        return 'Only Requested or Delivered handovers can move to Awaiting Receipt.';
    }

    if ($targetStatus === 'delivered') {
        if ($currentStatus === 'requested') {
            return handover_source_can_cover_quantities($handover, $lines, 'quantity_handed');
        }

        if (in_array($currentStatus, ['awaiting_receipt', 'receipt_review', 'delivered'], true)) {
            return null;
        }

        return 'This handover cannot be moved to Delivered from its current status.';
    }

    return null;
}

function reverse_closed_handover_inventory(array $handover, array $lines, int $performedBy): void
{
    $bufferStorageId = system_storage_id('handover_buffer');

    foreach ($lines as $line) {
        $item = find_item_or_abort((int) $line['item_id']);
        $used = round((float) ($line['quantity_used'] ?? 0), 2);
        $returned = round((float) ($line['quantity_returned'] ?? 0), 2);

        if ($used > 0) {
            apply_inventory_movement(
                $item,
                'restock',
                $used,
                null,
                $bufferStorageId,
                date('Y-m-d H:i:s'),
                (string) $handover['handover_number'],
                'Admin status override reopened closed handover and restored consumed stock to buffer.',
                $performedBy,
                'handover',
                (int) $handover['id']
            );
        }

        if ($returned > 0) {
            apply_inventory_movement(
                $item,
                'transfer',
                $returned,
                (int) $handover['source_storage_id'],
                $bufferStorageId,
                date('Y-m-d H:i:s'),
                (string) $handover['handover_number'],
                'Admin status override reopened closed handover and moved returned stock back to buffer.',
                $performedBy,
                'handover',
                (int) $handover['id']
            );
        }
    }
}

function confirm_handover_receipt_shortage_inventory(array $handover, array $lines, int $performedBy): void
{
    $bufferStorageId = system_storage_id('handover_buffer');

    foreach ($lines as $line) {
        $received = round((float) ($line['quantity_received'] ?? 0), 2);
        $planned = round((float) ($line['quantity_handed'] ?? 0), 2);
        $shortage = round($planned - $received, 2);

        if ($shortage <= 0) {
            continue;
        }

        $item = find_item_or_abort((int) $line['item_id']);

        apply_inventory_movement(
            $item,
            'transfer',
            $shortage,
            $bufferStorageId,
            (int) $handover['source_storage_id'],
            date('Y-m-d H:i:s'),
            (string) $handover['handover_number'],
            'Admin status override confirmed handover shortage and returned unreceived stock.',
            $performedBy,
            'handover',
            (int) $handover['id']
        );
    }
}

function apply_handover_status_override(array $handover, array $lines, string $targetStatus, int $performedBy, string $notes = ''): void
{
    $currentStatus = (string) ($handover['status'] ?? '');
    $noteColumn = in_array($targetStatus, ['requested', 'rejected'], true) ? 'request_decision_notes' : 'closed_notes';
    $existingNote = (string) ($handover[$noteColumn] ?? '');
    $actor = Auth::user();
    $overrideNote = trim(
        $existingNote .
        "\n\nStatus override by " . (string) (($actor['name'] ?? null) ?: 'Admin') . ' on ' . date('Y-m-d H:i:s') .
        ': ' . handover_status_label($currentStatus) . ' -> ' . handover_status_label($targetStatus) .
        ($notes !== '' ? '. ' . $notes : '.')
    );

    if ($currentStatus === 'requested' && in_array($targetStatus, ['awaiting_receipt', 'delivered'], true)) {
        issue_handover_inventory($handover, $lines, $performedBy);
    } elseif (in_array($currentStatus, ['cancelled', 'rejected'], true) && in_array($targetStatus, ['awaiting_receipt', 'delivered'], true)) {
        issue_handover_inventory($handover, $lines, $performedBy);
    } elseif (in_array($currentStatus, ['awaiting_receipt', 'receipt_review', 'delivered'], true) && in_array($targetStatus, ['requested', 'cancelled'], true)) {
        cancel_handover_inventory($handover, $lines, $performedBy);
    } elseif ($currentStatus === 'receipt_review' && $targetStatus === 'delivered') {
        confirm_handover_receipt_shortage_inventory($handover, $lines, $performedBy);
    } elseif ($currentStatus === 'closed' && in_array($targetStatus, ['delivered', 'pending_approval'], true)) {
        reverse_closed_handover_inventory($handover, $lines, $performedBy);
    } elseif ($currentStatus === 'pending_approval' && $targetStatus === 'closed') {
        $lineUpdates = array_map(static function (array $line): array {
            return [
                'line_id' => (int) $line['id'],
                'item_id' => (int) $line['item_id'],
                'used' => round((float) ($line['quantity_used'] ?? 0), 2),
                'returned' => round((float) ($line['quantity_returned'] ?? 0), 2),
                'breakdowns' => (array) ($line['usage_breakdowns'] ?? []),
            ];
        }, $lines);
        finalize_handover_inventory($handover, $lineUpdates, $performedBy);
    } elseif ($currentStatus === 'delivered' && $targetStatus === 'closed') {
        $lineUpdates = array_map(static function (array $line): array {
            $received = round((float) (($line['quantity_received'] ?? 0) ?: ($line['quantity_handed'] ?? 0)), 2);

            return [
                'line_id' => (int) $line['id'],
                'item_id' => (int) $line['item_id'],
                'used' => 0.0,
                'returned' => $received,
                'breakdowns' => [],
            ];
        }, $lines);

        foreach ($lineUpdates as $update) {
            Database::execute(
                'UPDATE handover_lines
                 SET quantity_used = 0,
                     quantity_returned = :quantity_returned,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'quantity_returned' => $update['returned'],
                    'id' => $update['line_id'],
                ]
            );
        }

        Database::execute('DELETE FROM handover_usage_breakdowns WHERE handover_id = :handover_id', ['handover_id' => (int) $handover['id']]);
        finalize_handover_inventory($handover, $lineUpdates, $performedBy);
    }

    if ($targetStatus === 'delivered') {
        if (in_array($currentStatus, ['closed', 'pending_approval'], true)) {
            Database::execute(
                'UPDATE handover_lines
                 SET quantity_received = CASE WHEN quantity_received > 0 THEN quantity_received ELSE quantity_handed END,
                     quantity_used = 0,
                     quantity_returned = 0,
                     updated_at = NOW()
                 WHERE handover_id = :handover_id',
                ['handover_id' => (int) $handover['id']]
            );
            Database::execute('DELETE FROM handover_usage_breakdowns WHERE handover_id = :handover_id', ['handover_id' => (int) $handover['id']]);
        } else {
            Database::execute(
                'UPDATE handover_lines
                 SET quantity_received = CASE WHEN quantity_received > 0 THEN quantity_received ELSE quantity_handed END,
                     updated_at = NOW()
                 WHERE handover_id = :handover_id',
                ['handover_id' => (int) $handover['id']]
            );
        }
    } elseif ($targetStatus === 'awaiting_receipt') {
        Database::execute(
            'UPDATE handover_lines
             SET quantity_received = 0,
                 quantity_used = 0,
                 quantity_returned = 0,
                 updated_at = NOW()
             WHERE handover_id = :handover_id',
            ['handover_id' => (int) $handover['id']]
        );
        Database::execute('DELETE FROM handover_usage_breakdowns WHERE handover_id = :handover_id', ['handover_id' => (int) $handover['id']]);
    } elseif ($targetStatus === 'requested') {
        Database::execute(
            'UPDATE handover_lines
             SET quantity_received = 0,
                 quantity_used = 0,
                 quantity_returned = 0,
                 updated_at = NOW()
             WHERE handover_id = :handover_id',
            ['handover_id' => (int) $handover['id']]
        );
        Database::execute('DELETE FROM handover_usage_breakdowns WHERE handover_id = :handover_id', ['handover_id' => (int) $handover['id']]);
    }

    $actorIdSql = (string) max(0, $performedBy);
    $timestampSql = [
        'requested' => 'receipt_reported_at = NULL, submitted_at = NULL, submitted_by = NULL, approved_at = NULL, approved_by = NULL, completed_at = NULL, completed_by = NULL, request_rejected_at = NULL, cancelled_at = NULL',
        'awaiting_receipt' => 'request_approved_at = COALESCE(request_approved_at, NOW()), request_approved_by = COALESCE(request_approved_by, ' . $actorIdSql . '), issued_at = COALESCE(issued_at, NOW()), receipt_reported_at = NULL, submitted_at = NULL, submitted_by = NULL, approved_at = NULL, approved_by = NULL, completed_at = NULL, completed_by = NULL, request_rejected_at = NULL, cancelled_at = NULL',
        'delivered' => 'request_approved_at = COALESCE(request_approved_at, NOW()), request_approved_by = COALESCE(request_approved_by, ' . $actorIdSql . '), issued_at = COALESCE(issued_at, NOW()), receipt_reported_at = COALESCE(receipt_reported_at, NOW()), submitted_at = NULL, submitted_by = NULL, approved_at = NULL, approved_by = NULL, completed_at = NULL, completed_by = NULL, request_rejected_at = NULL, cancelled_at = NULL',
        'pending_approval' => 'submitted_at = COALESCE(submitted_at, NOW()), submitted_by = COALESCE(submitted_by, ' . $actorIdSql . '), approved_at = NULL, approved_by = NULL, completed_at = NULL, completed_by = NULL, cancelled_at = NULL',
        'closed' => 'submitted_at = COALESCE(submitted_at, NOW()), submitted_by = COALESCE(submitted_by, ' . $actorIdSql . '), approved_at = NOW(), approved_by = ' . $actorIdSql . ', completed_at = NOW(), completed_by = ' . $actorIdSql . ', cancelled_at = NULL',
        'rejected' => 'request_rejected_at = NOW(), cancelled_at = NULL',
        'cancelled' => 'cancelled_at = NOW()',
    ][$targetStatus];

    $executeParams = [
        'status' => $targetStatus,
        'status_notes' => $overrideNote !== '' ? $overrideNote : null,
        'updated_by' => $performedBy,
        'id' => (int) $handover['id'],
    ];

    Database::execute(
        'UPDATE handovers
         SET status = :status,
             ' . $noteColumn . ' = :status_notes,
             ' . $timestampSql . ',
             updated_by = :updated_by,
             updated_at = NOW()
         WHERE id = :id',
        $executeParams
    );
}

function find_handover_or_abort(int $handoverId): array
{
    [$scopeSql, $scopeParams] = visible_handover_scope('h');
    $handover = Database::fetch(
        'SELECT h.*,
                source_storage.name AS source_storage_name,
                source_storage.storage_type AS source_storage_type,
                source_storage.owner_user_id AS source_owner_user_id,
                creator.name AS creator_name,
                request_approver.name AS request_approver_name,
                request_approved_by_user.name AS request_approved_by_name,
                completer.name AS completed_by_name,
                submitter.name AS submitted_by_name,
                approver.name AS approved_by_name,
                recipient.name AS recipient_user_name,
                recipient.email AS recipient_user_email,
                source_owner.name AS source_owner_name
         FROM handovers h
         INNER JOIN storages source_storage ON source_storage.id = h.source_storage_id
         LEFT JOIN users creator ON creator.id = h.created_by
         LEFT JOIN users request_approver ON request_approver.id = h.approver_user_id
         LEFT JOIN users request_approved_by_user ON request_approved_by_user.id = h.request_approved_by
         LEFT JOIN users submitter ON submitter.id = h.submitted_by
         LEFT JOIN users approver ON approver.id = h.approved_by
         LEFT JOIN users completer ON completer.id = h.completed_by
         LEFT JOIN users recipient ON recipient.id = h.recipient_user_id
         LEFT JOIN users source_owner ON source_owner.id = source_storage.owner_user_id
         WHERE h.id = :id' . $scopeSql . '
         LIMIT 1',
        ['id' => $handoverId] + $scopeParams
    );

    if (!$handover) {
        abort(404, 'Handover not found.');
    }

    return $handover;
}

function handover_lines(int $handoverId): array
{
    $lines = Database::fetchAll(
        'SELECT handover_line.*,
                i.image_path,
                i.barcode AS item_barcode
         FROM handover_lines handover_line
         INNER JOIN items i ON i.id = handover_line.item_id
         WHERE handover_line.handover_id = :handover_id
         ORDER BY handover_line.item_name ASC, handover_line.id ASC',
        ['handover_id' => $handoverId]
    );

    return hydrate_handover_lines_expected_usage_breakdowns(hydrate_handover_lines_usage_breakdowns($lines));
}

function handover_request_decision_block_reason(array $handover, ?array $user = null): ?string
{
    $user = $user ?? Auth::user();

    if ($user === null) {
        return 'Login first.';
    }

    if ((string) ($handover['status'] ?? '') !== 'requested') {
        return 'Only pending handover requests can be approved or rejected.';
    }

    if ((string) ($handover['handover_mode'] ?? 'direct') !== 'request') {
        return 'Only requested handovers use this approval step.';
    }

    if ((int) ($handover['created_by'] ?? 0) === (int) ($user['id'] ?? 0)) {
        return 'You cannot approve or reject your own handover request.';
    }

    if (!Auth::isOwner() && (int) ($handover['approver_user_id'] ?? 0) !== (int) ($user['id'] ?? 0)) {
        return 'This handover request is assigned to a different owner.';
    }

    return null;
}

function handover_line_edit_block_reason(array $handover, ?array $user = null): ?string
{
    $user = $user ?? Auth::user();

    if ($user === null) {
        return 'Login first.';
    }

    if (!handover_line_edits_enabled()) {
        return 'Handover request item editing is disabled in Website Control.';
    }

    if ((string) ($handover['status'] ?? '') !== 'requested') {
        return 'Handover items can only be edited before approval or delivery.';
    }

    if ((string) ($handover['handover_mode'] ?? 'direct') !== 'request') {
        return 'Direct handovers cannot be edited after creation. Create another handover if more items are needed.';
    }

    $userId = (int) ($user['id'] ?? 0);
    $isRequester = (int) ($handover['created_by'] ?? 0) === $userId;
    $isStorageOwner = (int) ($handover['source_owner_user_id'] ?? 0) === $userId
        || (int) ($handover['approver_user_id'] ?? 0) === $userId;

    if (!$isRequester && !$isStorageOwner && !Auth::isOwner()) {
        return 'Only the requester, storage owner, or owner can edit requested handover items.';
    }

    if (!Auth::hasAnyPermission(['handovers.request', 'handovers.create', 'handovers.approve'])) {
        return 'You do not have permission to edit requested handover items.';
    }

    return null;
}

function handover_request_cancel_block_reason(array $handover, ?array $user = null): ?string
{
    return handover_cancel_block_reason($handover, $user);
}

function handover_cancel_block_reason(array $handover, ?array $user = null): ?string
{
    $user = $user ?? Auth::user();

    if ($user === null) {
        return 'Login first.';
    }

    $status = (string) ($handover['status'] ?? '');

    if (!in_array($status, ['requested', 'awaiting_receipt', 'receipt_review', 'delivered'], true)) {
        return 'This handover cannot be cancelled at this stage. Use the active closeout or approval flow instead.';
    }

    $userId = (int) ($user['id'] ?? 0);
    $isRequester = (int) ($handover['created_by'] ?? 0) === $userId;
    $isRecipient = (int) ($handover['recipient_user_id'] ?? 0) === $userId;
    $isStorageOwner = (int) ($handover['source_owner_user_id'] ?? 0) === $userId
        || (int) ($handover['approver_user_id'] ?? 0) === $userId;
    $isOwner = Auth::isOwner();

    if (!$isRequester && !$isRecipient && !$isStorageOwner && !$isOwner && !Auth::hasAnyPermission(['handovers.request', 'handovers.approve', 'handovers.create', 'handovers.close'])) {
        return 'You do not have permission to cancel handovers.';
    }

    if ($status === 'requested') {
        if (!$isRequester && !$isStorageOwner && !$isOwner) {
            return 'Only the requester, storage owner, or owner can cancel this handover request.';
        }
    } else {
        if ($isRecipient && !$isStorageOwner && !$isOwner) {
            return 'Receivers cannot cancel issued handovers. Report the received quantity or return usage for storage owner review.';
        }

        if (!$isStorageOwner && !$isOwner) {
            return 'Only the storage owner or owner can cancel an issued handover.';
        }
    }

    if ($status === 'delivered') {
        foreach (handover_lines((int) ($handover['id'] ?? 0)) as $line) {
            if (round((float) ($line['quantity_used'] ?? 0), 2) > 0 || round((float) ($line['quantity_returned'] ?? 0), 2) > 0) {
                return 'This handover already has usage or return quantities. Submit the closeout for owner approval instead of cancelling.';
            }
        }
    }

    return null;
}

function handover_active_quantity(array $line): float
{
    return round((float) $line['quantity_received'], 2);
}

function handover_can_report_receipt(array $handover, ?array $user = null): bool
{
    $user = $user ?? Auth::user();

    if ($user === null || !Auth::hasPermission('handovers.close')) {
        return false;
    }

    if (!in_array((string) ($handover['status'] ?? ''), ['awaiting_receipt', 'receipt_review'], true)) {
        return false;
    }

    return (int) ($handover['recipient_user_id'] ?? 0) === (int) ($user['id'] ?? 0);
}

function handover_receipt_confirm_block_reason(array $handover, ?array $user = null): ?string
{
    $user = $user ?? Auth::user();

    if ($user === null) {
        return 'Login first.';
    }

    if ((string) ($handover['status'] ?? '') !== 'receipt_review') {
        return 'Only handovers waiting on receipt review can be confirmed.';
    }

    if (!Auth::isOwner()
        && (int) ($handover['source_owner_user_id'] ?? 0) !== (int) ($user['id'] ?? 0)
        && (int) ($handover['created_by'] ?? 0) !== (int) ($user['id'] ?? 0)) {
        return 'Only the storage owner can confirm the reported receipt quantity.';
    }

    return null;
}

function build_handover_receipt_updates(array $lines, $receivedInput): array
{
    $errors = [];
    $updates = [];
    $hasVariance = false;

    foreach ($lines as $line) {
        $lineId = (int) $line['id'];
        $receivedValue = is_array($receivedInput) ? ($receivedInput[$lineId] ?? '') : '';

        if (!is_numeric_value($receivedValue) || quantity_value($receivedValue) < 0) {
            $errors[] = 'Received quantity must be zero or more for every handover line.';
            continue;
        }

        $handed = round((float) $line['quantity_handed'], 2);
        $received = round(quantity_value($receivedValue), 2);

        if ($received > $handed) {
            $errors[] = $line['item_name'] . ' cannot receive more than the planned handover quantity.';
            continue;
        }

        $updates[] = [
            'line_id' => $lineId,
            'item_id' => (int) $line['item_id'],
            'handed' => $handed,
            'received' => $received,
            'shortage' => round($handed - $received, 2),
        ];

        if ($received !== $handed) {
            $hasVariance = true;
        }
    }

    return [$updates, $errors, $hasVariance];
}

function handover_close_nested_values(array $usageInput, string $key, int $lineId): array
{
    $values = $usageInput[$key] ?? [];

    if (!is_array($values)) {
        return [];
    }

    $lineValues = $values[$lineId] ?? $values[(string) $lineId] ?? [];

    if (!is_array($lineValues)) {
        return $lineValues !== '' ? [$lineValues] : [];
    }

    return array_values($lineValues);
}

function parse_handover_usage_input_rows(array $line, array $usageInput): array
{
    $errors = [];
    $lineId = (int) $line['id'];
    $quantityRows = handover_close_nested_values($usageInput, 'quantity', $lineId);
    $reasonRows = handover_close_nested_values($usageInput, 'reason', $lineId);
    $otherRows = handover_close_nested_values($usageInput, 'other', $lineId);
    $noteRows = handover_close_nested_values($usageInput, 'notes', $lineId);
    $rowCount = max(count($quantityRows), count($reasonRows), count($otherRows), count($noteRows));
    $breakdowns = [];
    $hasUsageRows = false;
    $used = 0.0;

    for ($index = 0; $index < $rowCount; $index++) {
        $quantityRaw = trim((string) ($quantityRows[$index] ?? ''));
        $reasonRaw = trim((string) ($reasonRows[$index] ?? ''));
        $otherRaw = trim((string) ($otherRows[$index] ?? ''));
        $noteRaw = trim((string) ($noteRows[$index] ?? ''));
        $reasonCode = normalize_handover_usage_reason($reasonRaw);
        $hasMeaningfulReason = $reasonRaw !== '' && $reasonCode !== 'unspecified';
        $hasMeaningfulQuantity = $quantityRaw !== ''
            && (
                !is_numeric_value($quantityRaw)
                || quantity_value($quantityRaw) < 0
                || round(quantity_value($quantityRaw), 2) > 0
            );
        $hasRowData = $hasMeaningfulQuantity || $hasMeaningfulReason || $otherRaw !== '' || $noteRaw !== '';

        if (!$hasRowData) {
            continue;
        }

        $hasUsageRows = true;

        if ($quantityRaw === '') {
            $errors[] = $line['item_name'] . ' has a usage reason without a quantity.';
            continue;
        }

        if (!is_numeric_value($quantityRaw) || quantity_value($quantityRaw) < 0) {
            $errors[] = 'Usage reason quantities must be zero or more for every line.';
            continue;
        }

        $quantity = round(quantity_value($quantityRaw), 2);

        if ($quantity <= 0) {
            continue;
        }

        $breakdowns[] = [
            'reason_code' => $reasonCode,
            'reason_custom' => $reasonCode === 'other' ? $otherRaw : '',
            'quantity' => $quantity,
            'notes' => $noteRaw,
        ];
        $used = round($used + $quantity, 2);
    }

    return [
        'breakdowns' => $breakdowns,
        'errors' => $errors,
        'has_usage_rows' => $hasUsageRows,
        'used' => $used,
    ];
}

function build_handover_close_updates(array $lines, $returnedInput, array $usageInput = [], $usedFallbackInput = []): array
{
    $errors = [];
    $updates = [];

    foreach ($lines as $line) {
        $lineId = (int) $line['id'];
        $handed = handover_active_quantity($line);
        $returnedRaw = is_array($returnedInput)
            ? ($returnedInput[$lineId] ?? $returnedInput[(string) $lineId] ?? null)
            : null;
        $usedFallbackRaw = is_array($usedFallbackInput)
            ? ($usedFallbackInput[$lineId] ?? $usedFallbackInput[(string) $lineId] ?? null)
            : null;

        if ($returnedRaw !== null && trim((string) $returnedRaw) !== '') {
            if (!is_numeric_value($returnedRaw) || quantity_value($returnedRaw) < 0) {
                $errors[] = $line['item_name'] . ' must have a valid returned quantity.';
                continue;
            }

            $returned = round(quantity_value($returnedRaw), 2);

            if ($returned > $handed) {
                $errors[] = $line['item_name'] . ' cannot return more than the confirmed received quantity.';
                continue;
            }

            $used = round($handed - $returned, 2);
        } elseif ($usedFallbackRaw !== null && trim((string) $usedFallbackRaw) !== '') {
            if (!is_numeric_value($usedFallbackRaw) || quantity_value($usedFallbackRaw) < 0) {
                $errors[] = 'Used quantity must be zero or more for every line.';
                continue;
            }

            $used = round(quantity_value($usedFallbackRaw), 2);
            $returned = round($handed - $used, 2);
        } else {
            $errors[] = $line['item_name'] . ' must have a returned quantity.';
            continue;
        }

        $parsedUsage = parse_handover_usage_input_rows($line, $usageInput);
        $errors = array_merge($errors, $parsedUsage['errors']);
        $breakdowns = $parsedUsage['breakdowns'];
        $hasUsageRows = (bool) $parsedUsage['has_usage_rows'];
        $breakdownUsed = round((float) $parsedUsage['used'], 2);

        if ($hasUsageRows && abs($breakdownUsed - $used) >= 0.01) {
            $errors[] = $line['item_name'] . ' usage reasons must total ' . format_quantity($used) . ' ' . (string) ($line['unit'] ?? 'pcs') . ' after returned quantity is entered.';
            continue;
        }

        if ($used > $handed) {
            $errors[] = $line['item_name'] . ' cannot use more than the confirmed received quantity.';
            continue;
        }

        if (!$hasUsageRows && $used > 0) {
            $breakdowns[] = [
                'reason_code' => 'unspecified',
                'reason_custom' => '',
                'quantity' => $used,
                'notes' => '',
            ];
        }

        $updates[] = [
            'line_id' => $lineId,
            'item_id' => (int) $line['item_id'],
            'used' => $used,
            'returned' => $returned,
            'breakdowns' => $breakdowns,
        ];
    }

    return [$updates, $errors];
}

function handover_adjust_breakdowns_for_approval(array $line, float $confirmedUsed): array
{
    $existing = array_values(array_filter((array) ($line['usage_breakdowns'] ?? []), static function (array $breakdown): bool {
        return round((float) ($breakdown['quantity'] ?? 0), 2) > 0;
    }));
    $existingTotal = round(array_reduce($existing, static function (float $carry, array $breakdown): float {
        return $carry + round((float) ($breakdown['quantity'] ?? 0), 2);
    }, 0.0), 2);

    if (abs($existingTotal - $confirmedUsed) < 0.01) {
        return $existing;
    }

    if ($confirmedUsed <= 0) {
        return [];
    }

    $adjustmentNote = 'Owner approval adjustment after confirming returned quantity.';

    if ($existingTotal <= 0) {
        return [[
            'reason_code' => 'unspecified',
            'reason_custom' => '',
            'quantity' => $confirmedUsed,
            'notes' => $adjustmentNote,
        ]];
    }

    if ($confirmedUsed > $existingTotal) {
        $existing[] = [
            'reason_code' => 'unspecified',
            'reason_custom' => '',
            'quantity' => round($confirmedUsed - $existingTotal, 2),
            'notes' => $adjustmentNote,
        ];

        return $existing;
    }

    $remaining = $confirmedUsed;
    $trimmed = [];

    foreach ($existing as $breakdown) {
        if ($remaining <= 0) {
            break;
        }

        $originalQuantity = round((float) ($breakdown['quantity'] ?? 0), 2);
        $quantity = min($originalQuantity, $remaining);

        if ($quantity <= 0) {
            continue;
        }

        $breakdown['quantity'] = round($quantity, 2);

        if ($quantity < $originalQuantity) {
            $notes = trim((string) ($breakdown['notes'] ?? ''));
            $breakdown['notes'] = $notes !== '' ? $notes . ' ' . $adjustmentNote : $adjustmentNote;
        }

        $trimmed[] = $breakdown;
        $remaining = round($remaining - $quantity, 2);
    }

    return $trimmed;
}

function build_handover_approval_updates(array $lines, $returnedInput, array $usageInput = []): array
{
    $errors = [];
    $updates = [];

    foreach ($lines as $line) {
        $lineId = (int) $line['id'];
        $received = handover_active_quantity($line);
        $returnedRaw = is_array($returnedInput)
            ? ($returnedInput[$lineId] ?? $returnedInput[(string) $lineId] ?? $line['quantity_returned'])
            : $line['quantity_returned'];

        if (!is_numeric_value($returnedRaw) || quantity_value($returnedRaw) < 0) {
            $errors[] = $line['item_name'] . ' must have a valid confirmed return quantity.';
            continue;
        }

        $returned = round(quantity_value($returnedRaw), 2);

        if ($returned > $received) {
            $errors[] = $line['item_name'] . ' cannot return more than the confirmed received quantity.';
            continue;
        }

        $used = round($received - $returned, 2);
        $parsedUsage = parse_handover_usage_input_rows($line, $usageInput);
        $errors = array_merge($errors, $parsedUsage['errors']);
        $breakdowns = handover_adjust_breakdowns_for_approval($line, $used);

        if ((bool) $parsedUsage['has_usage_rows']) {
            $breakdownUsed = round((float) $parsedUsage['used'], 2);

            if (abs($breakdownUsed - $used) >= 0.01) {
                $errors[] = $line['item_name'] . ' usage breakdown must total ' . format_quantity($used) . ' ' . (string) ($line['unit'] ?? 'pcs') . ' after your confirmed return.';
                continue;
            }

            $breakdowns = $parsedUsage['breakdowns'];
        }

        $updates[] = [
            'line_id' => $lineId,
            'item_id' => (int) $line['item_id'],
            'used' => $used,
            'returned' => $returned,
            'breakdowns' => $breakdowns,
        ];
    }

    return [$updates, $errors];
}

function issue_handover_inventory(array $handover, array $lines, int $performedBy): void
{
    $bufferStorageId = system_storage_id('handover_buffer');

    foreach ($lines as $line) {
        $plannedQuantity = round((float) ($line['quantity_handed'] ?? 0), 2);

        if ($plannedQuantity <= 0) {
            continue;
        }

        $item = find_item_or_abort((int) $line['item_id']);
        $balance = item_storage_balance_record((int) $line['item_id'], (int) $handover['source_storage_id']);

        if ($balance === null || (float) $balance['quantity'] < $plannedQuantity) {
            throw new RuntimeException($line['item_name'] . ' no longer has enough stock to issue this handover.');
        }

        apply_inventory_movement(
            $item,
            'transfer',
            $plannedQuantity,
            (int) $handover['source_storage_id'],
            $bufferStorageId,
            date('Y-m-d H:i:s'),
            (string) $handover['handover_number'],
            'Issued for handover to ' . $handover['recipient_name'] . '.',
            $performedBy,
            'handover',
            (int) $handover['id']
        );
    }
}

function finalize_handover_inventory(array $handover, array $lineUpdates, int $performedBy): void
{
    $bufferStorageId = system_storage_id('handover_buffer');

    foreach ($lineUpdates as $update) {
        $item = find_item_or_abort((int) $update['item_id']);
        $usageSummary = handover_usage_reason_summary((array) ($update['breakdowns'] ?? []), (string) ($item['unit'] ?? 'pcs'));

        if ($update['used'] > 0) {
            apply_inventory_movement(
                $item,
                'usage',
                (float) $update['used'],
                $bufferStorageId,
                null,
                date('Y-m-d H:i:s'),
                (string) $handover['handover_number'],
                'Consumed during handover.' . ($usageSummary !== '' ? ' Usage: ' . $usageSummary . '.' : ''),
                $performedBy,
                'handover',
                (int) $handover['id']
            );
        }

        if ($update['returned'] > 0) {
            apply_inventory_movement(
                $item,
                'transfer',
                (float) $update['returned'],
                $bufferStorageId,
                (int) $handover['source_storage_id'],
                date('Y-m-d H:i:s'),
                (string) $handover['handover_number'],
                'Returned from handover back into storage.',
                $performedBy,
                'handover',
                (int) $handover['id']
            );
        }
    }
}

function handover_summary_rows(array $filters): array
{
    [$where, $params] = build_handover_where($filters);

    return Database::fetchAll(
        "SELECT h.*,
                source_storage.name AS source_storage_name,
                source_storage.storage_type AS source_storage_type,
                creator.name AS creator_name,
                COALESCE(line_totals.line_count, 0) AS line_count,
                COALESCE(line_totals.total_handed, 0) AS total_handed,
                COALESCE(line_totals.total_used, 0) AS total_used,
                COALESCE(line_totals.total_returned, 0) AS total_returned
         FROM handovers h
         INNER JOIN storages source_storage ON source_storage.id = h.source_storage_id
         LEFT JOIN users creator ON creator.id = h.created_by
         LEFT JOIN (
             SELECT handover_id,
                    COUNT(*) AS line_count,
                    COALESCE(SUM(quantity_handed), 0) AS total_handed,
                    COALESCE(SUM(quantity_used), 0) AS total_used,
                    COALESCE(SUM(quantity_returned), 0) AS total_returned
             FROM handover_lines
             GROUP BY handover_id
         ) line_totals ON line_totals.handover_id = h.id
         {$where}
         ORDER BY h.issued_at DESC, h.id DESC
         LIMIT 250",
        $params
    );
}

function staff_dashboard_handover_cards(int $userId): array
{
    return Database::fetchAll(
        'SELECT h.id,
                h.handover_number,
                h.status,
                h.scheduled_for_date,
                h.issued_at,
                h.closed_notes,
                source_storage.name AS source_storage_name,
                source_storage.storage_type AS source_storage_type,
                handover_line.item_id,
                handover_line.item_name,
                handover_line.item_sku,
                handover_line.unit,
                handover_line.quantity_handed,
                handover_line.quantity_received,
                handover_line.quantity_used,
                handover_line.quantity_returned,
                i.image_path
         FROM handovers h
         INNER JOIN handover_lines handover_line ON handover_line.handover_id = h.id
         INNER JOIN storages source_storage ON source_storage.id = h.source_storage_id
         INNER JOIN items i ON i.id = handover_line.item_id
         WHERE h.recipient_user_id = :user_id
           AND h.status IN ("awaiting_receipt", "receipt_review", "delivered", "pending_approval")
           AND (
               CASE
                   WHEN h.status IN ("awaiting_receipt", "receipt_review") THEN handover_line.quantity_handed
                   ELSE handover_line.quantity_received
               END - handover_line.quantity_used - handover_line.quantity_returned
           ) > 0
         ORDER BY COALESCE(h.scheduled_for_date, DATE(h.issued_at)) ASC, h.issued_at DESC, handover_line.item_name ASC
         LIMIT 24',
        ['user_id' => $userId]
    );
}

function handle_handovers_index(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('handovers.view');

    $user = Auth::user();

    if ($user) {
        mark_notifications_for_entity_type_as_read((int) $user['id'], 'handover');
    }

    $filters = handover_filters();
    redirect_exact_workflow_reference_search((string) $filters['search'], ['handover']);
    $handovers = handover_summary_rows($filters);

    [$handoverScopeSql, $handoverScopeParams] = visible_handover_scope('h');
    $counts = [
        'open' => (int) Database::scalar("SELECT COUNT(*) FROM handovers h WHERE h.status IN ('requested', 'awaiting_receipt', 'receipt_review', 'delivered', 'pending_approval')" . $handoverScopeSql, $handoverScopeParams),
        'requested' => (int) Database::scalar("SELECT COUNT(*) FROM handovers h WHERE h.status = 'requested'" . $handoverScopeSql, $handoverScopeParams),
        'awaiting_receipt' => (int) Database::scalar("SELECT COUNT(*) FROM handovers h WHERE h.status = 'awaiting_receipt'" . $handoverScopeSql, $handoverScopeParams),
        'receipt_review' => (int) Database::scalar("SELECT COUNT(*) FROM handovers h WHERE h.status = 'receipt_review'" . $handoverScopeSql, $handoverScopeParams),
        'delivered' => (int) Database::scalar("SELECT COUNT(*) FROM handovers h WHERE h.status = 'delivered'" . $handoverScopeSql, $handoverScopeParams),
        'pending_approval' => (int) Database::scalar("SELECT COUNT(*) FROM handovers h WHERE h.status = 'pending_approval'" . $handoverScopeSql, $handoverScopeParams),
        'closed' => (int) Database::scalar("SELECT COUNT(*) FROM handovers h WHERE h.status = 'closed'" . $handoverScopeSql, $handoverScopeParams),
        'rejected' => (int) Database::scalar("SELECT COUNT(*) FROM handovers h WHERE h.status = 'rejected'" . $handoverScopeSql, $handoverScopeParams),
        'cancelled' => (int) Database::scalar("SELECT COUNT(*) FROM handovers h WHERE h.status = 'cancelled'" . $handoverScopeSql, $handoverScopeParams),
    ];

    View::render('handovers/index', [
        'title' => site_setting('page.handovers', 'Handovers'),
        'filters' => $filters,
        'handovers' => $handovers,
        'counts' => $counts,
        'storages' => all_storages_for_select($filters['storage_id']),
    ]);
}

function handle_handovers_create_page(): void
{
    app_ready_or_redirect();
    Auth::requireLogin();

    if (Auth::isStaff()) {
        Auth::requirePermission('handovers.request');
    } else {
        Auth::requirePermission('handovers.create');
    }

    $currentUser = Auth::user() ?? [];
    $selectedSourceStorageId = normalize_entity_id(old('source_storage_id', ''));
    $selectedRecipientUserId = normalize_entity_id(old('recipient_user_id', ''));
    $selectedRequestOwnerId = normalize_entity_id(old('request_owner_user_id', ''));
    $lockedRequestOwner = Auth::isStaff() ? handover_request_assigned_owner($currentUser) : null;
    $sourceStorages = Auth::isStaff()
        ? handover_request_source_storages_for_staff($currentUser, $selectedSourceStorageId, $selectedRequestOwnerId)
        : handover_source_storages_for_user($currentUser, $selectedSourceStorageId);

    View::render('handovers/form', [
        'title' => Auth::isStaff() ? 'Request Handover' : 'Create Handover',
        'handoverRecord' => [
            'source_storage_id' => old('source_storage_id', ''),
            'request_owner_user_id' => old('request_owner_user_id', $lockedRequestOwner ? (string) $lockedRequestOwner['id'] : ''),
            'recipient_name' => Auth::isStaff() ? (string) ($currentUser['name'] ?? '') : old('recipient_name', ''),
            'recipient_user_id' => Auth::isStaff() ? (string) ($currentUser['id'] ?? '') : old('recipient_user_id', ''),
            'scheduled_for_date' => old('scheduled_for_date', ''),
            'notes' => old('notes', ''),
        ],
        'lineItems' => old('line_items', [['item_id' => '', 'quantity' => '']]),
        'sourceStorages' => $sourceStorages,
        'users' => Auth::isStaff() ? [] : active_staff_users_for_select($selectedRecipientUserId),
        'ownerCandidates' => Auth::isStaff() && !$lockedRequestOwner ? handover_request_owner_candidates_for_select($selectedRequestOwnerId) : [],
        'lockedRequestOwner' => $lockedRequestOwner,
        'isStaffRequest' => Auth::isStaff(),
        'storageCatalogJson' => json_encode(workflow_storage_item_catalog(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'storageMetaJson' => json_encode(workflow_storage_meta($sourceStorages), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
}

function handle_handovers_create_submit(): void
{
    app_ready_or_redirect();
    Auth::requireLogin();

    if (Auth::isStaff()) {
        Auth::requirePermission('handovers.request');
    } else {
        Auth::requirePermission('handovers.create');
    }

    verify_csrf();

    $user = Auth::user();
    $isStaffRequest = Auth::isStaff();
    [$lines, $lineErrors] = parse_workflow_lines();
    [$expectedUsageByItem, $expectedUsageErrors] = parse_handover_expected_usage_by_item($lines);
    $payload = [
        'source_storage_id' => normalize_entity_id(input('source_storage_id')),
        'request_owner_user_id' => normalize_entity_id(input('request_owner_user_id')),
        'recipient_name' => $isStaffRequest ? trim((string) ($user['name'] ?? '')) : trim((string) input('recipient_name')),
        'recipient_user_id' => $isStaffRequest ? (int) ($user['id'] ?? 0) : normalize_entity_id(input('recipient_user_id')),
        'scheduled_for_date' => normalize_workflow_date(trim((string) input('scheduled_for_date'))),
        'notes' => trim((string) input('notes')),
    ];

    flash_old_input([
        'source_storage_id' => (string) ($payload['source_storage_id'] ?? ''),
        'request_owner_user_id' => (string) ($payload['request_owner_user_id'] ?? ''),
        'recipient_name' => $payload['recipient_name'],
        'recipient_user_id' => (string) ($payload['recipient_user_id'] ?? ''),
        'scheduled_for_date' => $payload['scheduled_for_date'],
        'notes' => $payload['notes'],
        'line_items' => array_map(static fn (array $line): array => [
            'item_id' => (string) $line['item_id'],
            'quantity' => format_quantity($line['quantity']),
        ], $lines),
        'expected_usage_reason' => input('expected_usage_reason', []),
        'expected_usage_quantity' => input('expected_usage_quantity', []),
        'expected_usage_other' => input('expected_usage_other', []),
        'expected_usage_notes' => input('expected_usage_notes', []),
    ]);

    $errors = array_merge($lineErrors, $expectedUsageErrors);

    if (!$payload['source_storage_id'] || !storage_exists_for_assignment($payload['source_storage_id'])) {
        $errors[] = 'Pick a valid source storage.';
    } elseif (!$isStaffRequest && !Auth::isOwner() && !storage_is_owned_by_user((int) $payload['source_storage_id'], (int) ($user['id'] ?? 0))) {
        $errors[] = 'You can only create handovers from storages you own.';
    }

    if ($payload['recipient_name'] === '' && !$payload['recipient_user_id']) {
        $errors[] = 'Enter a recipient name or choose a user.';
    }

    $sourceOwner = $payload['source_storage_id'] ? storage_owner_record((int) $payload['source_storage_id']) : null;
    $assignedRequestOwnerId = $isStaffRequest ? normalize_entity_id($user['assigned_owner_user_id'] ?? null) : null;
    $expectedRequestOwnerId = $assignedRequestOwnerId ?? $payload['request_owner_user_id'];
    $recipientUser = null;

    if ($isStaffRequest) {
        if (!$sourceOwner || empty($sourceOwner['owner_user_id']) || (int) ($sourceOwner['owner_is_active'] ?? 0) !== 1) {
            $errors[] = 'This storage needs an active owner before a handover request can be sent.';
        }

        if ($expectedRequestOwnerId === null) {
            $errors[] = 'Pick who you are requesting this handover from.';
        }

        if ($expectedRequestOwnerId !== null && $sourceOwner && (int) ($sourceOwner['owner_user_id'] ?? 0) !== (int) $expectedRequestOwnerId) {
            $errors[] = 'Pick a storage owned by the selected handover approver.';
        }
    }

    if ($payload['recipient_user_id']) {
        $recipientUser = Database::fetch(
            'SELECT id, name, role, is_active
             FROM users
             WHERE id = :id
             LIMIT 1',
            ['id' => $payload['recipient_user_id']]
        );

        if (!$recipientUser || (int) ($recipientUser['is_active'] ?? 0) !== 1) {
            $errors[] = 'Pick a valid active recipient user.';
        } elseif (($recipientUser['role'] ?? '') !== 'staff') {
            $errors[] = 'Handovers can only be assigned to staff accounts.';
        } elseif ($payload['recipient_name'] === '') {
            $payload['recipient_name'] = (string) $recipientUser['name'];
        }
    }

    $itemsById = [];

    foreach ($lines as $line) {
        $item = Database::fetch(
            'SELECT i.*
             FROM items i
             WHERE i.id = :id
               AND i.is_active = 1
             LIMIT 1',
            ['id' => $line['item_id']]
        );

        if (!$item) {
            $errors[] = 'One of the selected items no longer exists.';
            continue;
        }

        $balance = item_storage_balance_record((int) $item['id'], (int) $payload['source_storage_id']);

        if ($balance === null) {
            $errors[] = $item['name'] . ' is not assigned to the selected source storage.';
            continue;
        }

        if (!$isStaffRequest && (float) $balance['quantity'] < (float) $line['quantity']) {
            $errors[] = $item['name'] . ' does not have enough stock for this handover.';
            continue;
        }

        $itemsById[(int) $item['id']] = $item;
    }

    if ($errors !== []) {
        flash_errors($errors);
        redirect('/handovers/create');
    }

    $handoverNumber = next_workflow_number('HDO', 'handovers', 'handover_number');
    $initialStatus = $isStaffRequest
        ? 'requested'
        : ($payload['recipient_user_id'] ? 'awaiting_receipt' : 'delivered');
    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        Database::execute(
            'INSERT INTO handovers (
                handover_number,
                source_storage_id,
                approver_user_id,
                recipient_name,
                recipient_user_id,
                handover_mode,
                status,
                scheduled_for_date,
                notes,
                request_decision_notes,
                receipt_notes,
                closed_notes,
                requested_at,
                issued_at,
                request_approved_at,
                request_rejected_at,
                receipt_reported_at,
                cancelled_at,
                created_by,
                request_approved_by,
                updated_by,
                created_at,
                updated_at
             ) VALUES (
                :handover_number,
                :source_storage_id,
                :approver_user_id,
                :recipient_name,
                :recipient_user_id,
                :handover_mode,
                :status,
                :scheduled_for_date,
                :notes,
                NULL,
                NULL,
                NULL,
                :requested_at,
                NOW(),
                NULL,
                NULL,
                NULL,
                NULL,
                :created_by,
                NULL,
                :updated_by,
                NOW(),
                NOW()
             )',
            [
                'handover_number' => $handoverNumber,
                'source_storage_id' => (int) $payload['source_storage_id'],
                'approver_user_id' => $sourceOwner['owner_user_id'] ?? null,
                'recipient_name' => $payload['recipient_name'],
                'recipient_user_id' => $payload['recipient_user_id'],
                'handover_mode' => $isStaffRequest ? 'request' : 'direct',
                'status' => $initialStatus,
                'scheduled_for_date' => $payload['scheduled_for_date'] !== '' ? $payload['scheduled_for_date'] : null,
                'notes' => $payload['notes'] !== '' ? $payload['notes'] : null,
                'requested_at' => $isStaffRequest ? date('Y-m-d H:i:s') : null,
                'created_by' => (int) $user['id'],
                'updated_by' => (int) $user['id'],
            ]
        );

        $handoverId = Database::lastInsertId();
        $expectedUsageUpdates = [];

        foreach ($lines as $line) {
            $item = $itemsById[(int) $line['item_id']];

            Database::execute(
                'INSERT INTO handover_lines (
                    handover_id,
                    item_id,
                    item_name,
                    item_sku,
                    unit,
                    quantity_handed,
                    quantity_received,
                    quantity_used,
                    quantity_returned,
                    created_at,
                    updated_at
                 ) VALUES (
                    :handover_id,
                    :item_id,
                    :item_name,
                    :item_sku,
                    :unit,
                    :quantity_handed,
                    :quantity_received,
                    0,
                    0,
                    NOW(),
                    NOW()
                 )',
                [
                    'handover_id' => $handoverId,
                    'item_id' => (int) $item['id'],
                    'item_name' => $item['name'],
                    'item_sku' => $item['sku'],
                    'unit' => $item['unit'],
                    'quantity_handed' => $line['quantity'],
                    'quantity_received' => $payload['recipient_user_id'] ? 0 : $line['quantity'],
                ]
            );

            $lineId = Database::lastInsertId();

            if (!empty($expectedUsageByItem[(int) $item['id']])) {
                $expectedUsageUpdates[] = [
                    'line_id' => $lineId,
                    'item_id' => (int) $item['id'],
                    'breakdowns' => $expectedUsageByItem[(int) $item['id']],
                ];
            }
        }

        save_handover_expected_usage_breakdowns($handoverId, $expectedUsageUpdates, (int) $user['id']);

        if (!$isStaffRequest) {
            issue_handover_inventory([
                'id' => $handoverId,
                'handover_number' => $handoverNumber,
                'source_storage_id' => (int) $payload['source_storage_id'],
                'recipient_name' => $payload['recipient_name'],
            ], array_map(static function (array $line) use ($itemsById): array {
                $item = $itemsById[(int) $line['item_id']];

                return [
                    'item_id' => (int) $item['id'],
                    'item_name' => (string) $item['name'],
                    'quantity_handed' => (float) $line['quantity'],
                ];
            }, $lines), (int) $user['id']);
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/handovers/create');
    }

    if ($isStaffRequest && !empty($sourceOwner['owner_user_id'])) {
        create_notification(
            (int) $sourceOwner['owner_user_id'],
            'handover_requested',
            'New handover request ' . $handoverNumber,
            ($user['name'] ?? 'Staff') . ' requested a temporary handover from ' . ($sourceOwner['storage_name'] ?? 'your storage') . '.',
            url('/handovers/' . $handoverId),
            'handover',
            $handoverId,
            (int) ($user['id'] ?? 0)
        );
    } elseif ($payload['recipient_user_id']) {
        create_notification(
            (int) $payload['recipient_user_id'],
            'handover_created',
            'New handover ' . $handoverNumber,
            'Confirm the actual received quantity before you start using these items.',
            url('/handovers/' . $handoverId),
            'handover',
            $handoverId,
            (int) ($user['id'] ?? 0)
        );
    }

    consume_old_input();
    flash('success', $isStaffRequest ? 'Handover request created.' : 'Handover created.');
    redirect('/handovers/' . $handoverId);
}

function handle_handovers_show(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('handovers.view');

    $handover = find_handover_or_abort((int) $params['id']);
    $user = Auth::user();

    if ($user) {
        mark_notifications_for_entity_as_read((int) $user['id'], 'handover', (int) $handover['id']);
    }

    $lines = handover_lines((int) $handover['id']);

    try {
        ensure_workflow_signoff_pdf('handover', $handover, $lines);
    } catch (Throwable $exception) {
        // The workflow page must stay usable even if attachment generation fails.
    }

    $sourceStorage = Database::fetch(
        'SELECT s.id,
                s.name,
                s.storage_type,
                s.owner_user_id,
                owner.name AS owner_name
         FROM storages s
         LEFT JOIN users owner ON owner.id = s.owner_user_id
         WHERE s.id = :id
         LIMIT 1',
        ['id' => (int) $handover['source_storage_id']]
    );
    $lineEditBlockReason = handover_line_edit_block_reason($handover, $user);

    View::render('handovers/show', [
        'title' => $handover['handover_number'],
        'handoverRecord' => $handover,
        'lines' => $lines,
        'documents' => workflow_documents('handover', (int) $handover['id']),
        'canEditHandoverLines' => $lineEditBlockReason === null,
        'lineEditBlockReason' => $lineEditBlockReason,
        'sourceStorages' => $sourceStorage ? [$sourceStorage] : [],
        'storageCatalogJson' => json_encode(workflow_storage_item_catalog(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'storageMetaJson' => json_encode(workflow_storage_meta($sourceStorage ? [$sourceStorage] : []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
}

function handle_handovers_lines_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requireLogin();
    verify_csrf();

    $handover = find_handover_or_abort((int) $params['id']);
    $user = Auth::user();
    $blockReason = handover_line_edit_block_reason($handover, $user);

    if ($blockReason !== null) {
        flash('danger', $blockReason);
        redirect('/handovers/' . $handover['id']);
    }

    [$lines, $lineErrors] = parse_workflow_lines();
    flash_old_input([
        'edit_line_items' => array_map(static fn (array $line): array => [
            'item_id' => (string) $line['item_id'],
            'quantity' => format_quantity($line['quantity']),
        ], $lines),
    ]);

    $errors = $lineErrors;
    $sourceStorageId = (int) ($handover['source_storage_id'] ?? 0);
    $itemsById = [];

    if ($sourceStorageId <= 0 || !storage_exists_for_assignment($sourceStorageId)) {
        $errors[] = 'The source storage is no longer available.';
    }

    foreach ($lines as $line) {
        $item = Database::fetch(
            'SELECT i.*
             FROM items i
             WHERE i.id = :id
               AND i.is_active = 1
             LIMIT 1',
            ['id' => $line['item_id']]
        );

        if (!$item) {
            $errors[] = 'One of the selected items no longer exists.';
            continue;
        }

        if (item_storage_balance_record((int) $item['id'], $sourceStorageId) === null) {
            $errors[] = $item['name'] . ' is not assigned to ' . ($handover['source_storage_name'] ?? 'the source storage') . '.';
            continue;
        }

        $itemsById[(int) $item['id']] = $item;
    }

    if ($errors !== []) {
        flash_errors($errors);
        redirect('/handovers/' . $handover['id']);
    }

    $previousLines = handover_lines((int) $handover['id']);
    $previousLineIds = array_map(static fn (array $line): int => (int) $line['id'], $previousLines);
    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        if ($previousLineIds !== []) {
            Database::execute(
                'DELETE FROM handover_usage_breakdowns
                 WHERE handover_id = :handover_id
                   AND handover_line_id IN (' . implode(',', $previousLineIds) . ')',
                ['handover_id' => (int) $handover['id']]
            );
        }

        Database::execute(
            'DELETE FROM handover_lines
             WHERE handover_id = :handover_id',
            ['handover_id' => (int) $handover['id']]
        );

        foreach ($lines as $line) {
            $item = $itemsById[(int) $line['item_id']];

            Database::execute(
                'INSERT INTO handover_lines (
                    handover_id,
                    item_id,
                    item_name,
                    item_sku,
                    unit,
                    quantity_handed,
                    quantity_received,
                    quantity_used,
                    quantity_returned,
                    created_at,
                    updated_at
                 ) VALUES (
                    :handover_id,
                    :item_id,
                    :item_name,
                    :item_sku,
                    :unit,
                    :quantity_handed,
                    0,
                    0,
                    0,
                    NOW(),
                    NOW()
                 )',
                [
                    'handover_id' => (int) $handover['id'],
                    'item_id' => (int) $item['id'],
                    'item_name' => $item['name'],
                    'item_sku' => $item['sku'],
                    'unit' => $item['unit'],
                    'quantity_handed' => $line['quantity'],
                ]
            );
        }

        Database::execute(
            'UPDATE handovers
             SET updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'updated_by' => (int) ($user['id'] ?? 0),
                'id' => (int) $handover['id'],
            ]
        );

        record_activity('handover.lines_updated', 'handover', (int) $handover['id'], 'Updated requested handover items ' . $handover['handover_number'], [
            'old_lines' => array_map(static fn (array $line): array => [
                'item_id' => (int) $line['item_id'],
                'quantity' => (float) $line['quantity_handed'],
            ], $previousLines),
            'new_lines' => array_map(static fn (array $line): array => [
                'item_id' => (int) $line['item_id'],
                'quantity' => (float) $line['quantity'],
            ], $lines),
        ]);

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/handovers/' . $handover['id']);
    }

    $recipientIds = array_values(array_unique(array_filter([
        (int) ($handover['created_by'] ?? 0),
        (int) ($handover['approver_user_id'] ?? 0),
    ], static fn (int $recipientId): bool => $recipientId > 0 && $recipientId !== (int) ($user['id'] ?? 0))));

    foreach ($recipientIds as $recipientId) {
        create_notification(
            $recipientId,
            'handover_lines_updated',
            'Handover request ' . $handover['handover_number'] . ' updated',
            ($user['name'] ?? 'A user') . ' changed the requested item lines before approval.',
            url('/handovers/' . $handover['id']),
            'handover',
            (int) $handover['id'],
            (int) ($user['id'] ?? 0)
        );
    }

    try {
        $updatedHandover = find_handover_or_abort((int) $handover['id']);
        ensure_workflow_signoff_pdf('handover', $updatedHandover, handover_lines((int) $handover['id']));
    } catch (Throwable $exception) {
        // Attachment regeneration should not block the saved edit.
    }

    consume_old_input();
    flash('success', 'Requested handover items updated.');
    redirect('/handovers/' . $handover['id']);
}

function handle_handovers_approve_request_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('handovers.approve');
    verify_csrf();

    $handover = find_handover_or_abort((int) $params['id']);
    $user = Auth::user();
    $decisionBlockReason = handover_request_decision_block_reason($handover, $user);

    if ($decisionBlockReason !== null) {
        flash('danger', $decisionBlockReason);
        redirect('/handovers/' . $handover['id']);
    }

    $decisionNotes = trim((string) input('request_decision_notes'));
    $lines = handover_lines((int) $handover['id']);
    $initialStatus = !empty($handover['recipient_user_id']) ? 'awaiting_receipt' : 'delivered';
    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        issue_handover_inventory($handover, $lines, (int) $user['id']);

        Database::execute(
            'UPDATE handovers
             SET status = :status,
                 request_decision_notes = :request_decision_notes,
                 request_approved_at = NOW(),
                 request_approved_by = :request_approved_by,
                 issued_at = NOW(),
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'status' => $initialStatus,
                'request_decision_notes' => $decisionNotes !== '' ? $decisionNotes : null,
                'request_approved_by' => (int) $user['id'],
                'updated_by' => (int) $user['id'],
                'id' => (int) $handover['id'],
            ]
        );

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/handovers/' . $handover['id']);
    }

    if (!empty($handover['recipient_user_id'])) {
        create_notification(
            (int) $handover['recipient_user_id'],
            'handover_request_approved',
            'Handover request ' . $handover['handover_number'] . ' approved',
            'Your request is approved. Confirm the actual received quantity once you get the items.',
            url('/handovers/' . $handover['id']),
            'handover',
            (int) $handover['id'],
            (int) ($user['id'] ?? 0)
        );
    }

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => 'Handover request approved.',
            'redirect_url' => url('/handovers/' . $handover['id']),
        ]);
    }

    flash('success', 'Handover request approved.');
    redirect('/handovers/' . $handover['id']);
}

function handle_handovers_reject_request_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('handovers.approve');
    verify_csrf();

    $handover = find_handover_or_abort((int) $params['id']);
    $user = Auth::user();
    $decisionBlockReason = handover_request_decision_block_reason($handover, $user);

    if ($decisionBlockReason !== null) {
        flash('danger', $decisionBlockReason);
        redirect('/handovers/' . $handover['id']);
    }

    $decisionNotes = trim((string) input('request_decision_notes'));

    Database::execute(
        'UPDATE handovers
         SET status = "rejected",
             request_decision_notes = :request_decision_notes,
             request_rejected_at = NOW(),
             updated_by = :updated_by,
             updated_at = NOW()
         WHERE id = :id',
        [
            'request_decision_notes' => $decisionNotes !== '' ? $decisionNotes : null,
            'updated_by' => (int) $user['id'],
            'id' => (int) $handover['id'],
        ]
    );

    if (!empty($handover['recipient_user_id'])) {
        create_notification(
            (int) $handover['recipient_user_id'],
            'handover_request_rejected',
            'Handover request ' . $handover['handover_number'] . ' rejected',
            $decisionNotes !== '' ? $decisionNotes : 'The storage owner rejected this handover request.',
            url('/handovers/' . $handover['id']),
            'handover',
            (int) $handover['id'],
            (int) ($user['id'] ?? 0)
        );
    }

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => 'Handover request rejected.',
            'redirect_url' => url('/handovers/' . $handover['id']),
        ]);
    }

    flash('success', 'Handover request rejected.');
    redirect('/handovers/' . $handover['id']);
}

function handle_handovers_cancel_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requireLogin();
    verify_csrf();

    $handover = find_handover_or_abort((int) $params['id']);
    $user = Auth::user();
    $cancelBlockReason = handover_cancel_block_reason($handover, $user);

    if ($cancelBlockReason !== null) {
        flash('danger', $cancelBlockReason);
        redirect('/handovers/' . $handover['id']);
    }

    $cancelNotes = trim((string) input('cancel_notes', (string) input('request_decision_notes')));

    $lines = handover_lines((int) $handover['id']);
    $requestDecisionNotes = (string) ($handover['request_decision_notes'] ?? '');
    $closedNotes = (string) ($handover['closed_notes'] ?? '');

    if ($cancelNotes !== '') {
        if ((string) ($handover['status'] ?? '') === 'requested') {
            $requestDecisionNotes = $cancelNotes;
        } else {
            $closedNotes = $cancelNotes;
        }
    }

    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        cancel_handover_inventory($handover, $lines, (int) ($user['id'] ?? 0));

        Database::execute(
            'UPDATE handovers
             SET status = "cancelled",
                 request_decision_notes = :request_decision_notes,
                 closed_notes = :closed_notes,
                 cancelled_at = NOW(),
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'request_decision_notes' => $requestDecisionNotes !== '' ? $requestDecisionNotes : null,
                'closed_notes' => $closedNotes !== '' ? $closedNotes : null,
                'updated_by' => (int) ($user['id'] ?? 0),
                'id' => (int) $handover['id'],
            ]
        );

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/handovers/' . $handover['id']);
    }

    $notificationUserIds = array_values(array_unique(array_filter([
        (int) ($handover['created_by'] ?? 0),
        (int) ($handover['recipient_user_id'] ?? 0),
        (int) ($handover['approver_user_id'] ?? 0),
        (int) ($handover['source_owner_user_id'] ?? 0),
    ], static fn (int $id): bool => $id > 0 && $id !== (int) ($user['id'] ?? 0))));

    foreach ($notificationUserIds as $notificationUserId) {
        create_notification(
            $notificationUserId,
            'handover_cancelled',
            'Handover ' . $handover['handover_number'] . ' cancelled',
            ($user['name'] ?? 'Someone') . ' cancelled this handover.' . ($cancelNotes !== '' ? ' ' . $cancelNotes : ''),
            url('/handovers/' . $handover['id']),
            'handover',
            (int) $handover['id'],
            (int) ($user['id'] ?? 0)
        );
    }

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => 'Handover cancelled.',
            'redirect_url' => url('/handovers/' . $handover['id']),
        ]);
    }

    flash('success', 'Handover cancelled.');
    redirect('/handovers/' . $handover['id']);
}

function handle_handovers_recover_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requireOwner();
    verify_csrf();

    $handover = find_handover_or_abort((int) $params['id']);
    $user = Auth::user();
    $lines = handover_lines((int) $handover['id']);
    $targetStatus = handover_recovery_target_status($handover, $lines);
    $blockReason = handover_recovery_block_reason($handover, $lines, $user);

    if ($targetStatus === null || $blockReason !== null) {
        flash('danger', $blockReason ?? 'This handover cannot be recovered.');
        redirect('/handovers/' . $handover['id']);
    }

    $notes = trim((string) input('status_notes'));
    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        if ($targetStatus !== 'requested') {
            issue_handover_inventory($handover, $lines, (int) ($user['id'] ?? 0));
        }

        $noteColumn = $targetStatus === 'requested' ? 'request_decision_notes' : 'closed_notes';
        $existingNotes = (string) ($handover[$noteColumn] ?? '');
        $recoveryNote = trim(
            $existingNotes .
            "\n\nRecovered by " . (string) ($user['name'] ?? 'Admin') . ' on ' . date('Y-m-d H:i:s') .
            ($notes !== '' ? ': ' . $notes : '.')
        );

        Database::execute(
            'UPDATE handovers
             SET status = :status,
                 ' . $noteColumn . ' = :status_notes,
                 cancelled_at = NULL,
                 request_rejected_at = NULL,
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'status' => $targetStatus,
                'status_notes' => $recoveryNote !== '' ? $recoveryNote : null,
                'updated_by' => (int) ($user['id'] ?? 0),
                'id' => (int) $handover['id'],
            ]
        );

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/handovers/' . $handover['id']);
    }

    record_activity('handover.recovered', 'handover', (int) $handover['id'], 'Recovered handover ' . $handover['handover_number'], [
        'handover_id' => (int) $handover['id'],
        'handover_number' => (string) $handover['handover_number'],
        'from_status' => (string) $handover['status'],
        'to_status' => $targetStatus,
        'notes' => $notes,
    ]);

    $notificationUserIds = array_values(array_unique(array_filter([
        (int) ($handover['created_by'] ?? 0),
        (int) ($handover['recipient_user_id'] ?? 0),
        (int) ($handover['approver_user_id'] ?? 0),
        (int) ($handover['source_owner_user_id'] ?? 0),
    ], static fn (int $id): bool => $id > 0 && $id !== (int) ($user['id'] ?? 0))));

    foreach ($notificationUserIds as $notificationUserId) {
        create_notification(
            $notificationUserId,
            'handover_recovered',
            'Handover ' . $handover['handover_number'] . ' recovered',
            ($user['name'] ?? 'Admin') . ' reopened this handover as ' . handover_status_label($targetStatus) . '.',
            url('/handovers/' . $handover['id']),
            'handover',
            (int) $handover['id'],
            (int) ($user['id'] ?? 0)
        );
    }

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => 'Handover recovered as ' . handover_status_label($targetStatus) . '.',
            'redirect_url' => url('/handovers/' . $handover['id']),
        ]);
    }

    flash('success', 'Handover recovered as ' . handover_status_label($targetStatus) . '.');
    redirect('/handovers/' . $handover['id']);
}

function handle_handovers_status_override_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requireOwner();
    verify_csrf();

    $handover = find_handover_or_abort((int) $params['id']);
    $user = Auth::user();
    $lines = handover_lines((int) $handover['id']);
    $targetStatus = trim((string) input('target_status'));
    $notes = trim((string) input('status_notes'));
    $blockReason = handover_status_override_block_reason($handover, $lines, $targetStatus, $user);

    if ($blockReason !== null) {
        if (request_wants_json()) {
            json_response([
                'ok' => false,
                'message' => $blockReason,
            ], 422);
        }

        flash('danger', $blockReason);
        redirect('/handovers/' . $handover['id']);
    }

    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        apply_handover_status_override($handover, $lines, $targetStatus, (int) ($user['id'] ?? 0), $notes);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if (request_wants_json()) {
            json_response([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        flash('danger', $exception->getMessage());
        redirect('/handovers/' . $handover['id']);
    }

    record_activity('handover.status_override', 'handover', (int) $handover['id'], 'Changed handover status ' . $handover['handover_number'], [
        'handover_id' => (int) $handover['id'],
        'handover_number' => (string) $handover['handover_number'],
        'from_status' => (string) $handover['status'],
        'to_status' => $targetStatus,
        'notes' => $notes,
    ]);

    $notificationUserIds = array_values(array_unique(array_filter([
        (int) ($handover['created_by'] ?? 0),
        (int) ($handover['recipient_user_id'] ?? 0),
        (int) ($handover['approver_user_id'] ?? 0),
        (int) ($handover['source_owner_user_id'] ?? 0),
    ], static fn (int $id): bool => $id > 0 && $id !== (int) ($user['id'] ?? 0))));

    foreach ($notificationUserIds as $notificationUserId) {
        create_notification(
            $notificationUserId,
            'handover_status_override',
            'Handover ' . $handover['handover_number'] . ' status changed',
            ($user['name'] ?? 'Admin') . ' changed this handover from ' . handover_status_label((string) $handover['status']) . ' to ' . handover_status_label($targetStatus) . '.',
            url('/handovers/' . $handover['id']),
            'handover',
            (int) $handover['id'],
            (int) ($user['id'] ?? 0)
        );
    }

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => 'Handover status changed to ' . handover_status_label($targetStatus) . '.',
            'redirect_url' => url('/handovers/' . $handover['id']),
        ]);
    }

    flash('success', 'Handover status changed to ' . handover_status_label($targetStatus) . '.');
    redirect('/handovers/' . $handover['id']);
}

function handle_handovers_void_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requireOwner();
    verify_csrf();

    $handover = find_handover_or_abort((int) $params['id']);
    $user = Auth::user();
    $blockReason = workflow_void_block_reason('handover', $handover, $user);

    if ($blockReason !== null) {
        flash('danger', $blockReason);
        redirect('/handovers/' . $handover['id']);
    }

    $confirm = trim((string) input('void_confirm'));
    $notes = trim((string) input('void_notes'));
    $handoverNumber = (string) $handover['handover_number'];

    if ($confirm !== $handoverNumber) {
        flash('danger', 'Type the handover number exactly to mark it void.');
        redirect('/handovers/' . $handover['id']);
    }

    if ($notes === '') {
        flash('danger', 'Void reason is required.');
        redirect('/handovers/' . $handover['id']);
    }

    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        $noteColumn = (string) ($handover['status'] ?? '') === 'requested' ? 'request_decision_notes' : 'closed_notes';
        $existingNote = (string) ($handover[$noteColumn] ?? '');
        $voidNote = trim(
            $existingNote .
            "\n\nVoided by " . (string) ($user['name'] ?? 'Owner') . ' on ' . date('Y-m-d H:i:s') . ': ' . $notes
        );

        Database::execute(
            'UPDATE handovers
             SET status = "cancelled",
                 ' . $noteColumn . ' = :void_notes,
                 cancelled_at = COALESCE(cancelled_at, NOW()),
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'void_notes' => $voidNote,
                'updated_by' => (int) ($user['id'] ?? 0),
                'id' => (int) $handover['id'],
            ]
        );

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/handovers/' . $handover['id']);
    }

    record_activity('handover.voided', 'handover', (int) $handover['id'], 'Marked handover void ' . $handoverNumber, [
        'handover_id' => (int) $handover['id'],
        'handover_number' => $handoverNumber,
        'reason' => $notes,
    ]);

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => 'Handover marked void and kept for audit.',
            'redirect_url' => url('/handovers/' . $handover['id']),
        ]);
    }

    flash('success', 'Handover marked void and kept for audit.');
    redirect('/handovers/' . $handover['id']);
}

function handle_handovers_receive_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('handovers.close');
    verify_csrf();

    $handover = find_handover_or_abort((int) $params['id']);
    $user = Auth::user();

    if (!handover_can_report_receipt($handover, $user)) {
        flash('danger', 'Only the assigned recipient can report received quantities.');
        redirect('/handovers/' . $handover['id']);
    }

    $lines = handover_lines((int) $handover['id']);
    $receiptNotes = trim((string) input('receipt_notes'));
    [$receiptUpdates, $receiptErrors, $hasVariance] = build_handover_receipt_updates($lines, input('line_received'));
    $proofFile = uploaded_file('proof_image');
    $proofError = validate_workflow_proof_upload($proofFile);

    if ($proofError !== null) {
        if (request_wants_json()) {
            json_response([
                'ok' => false,
                'message' => $proofError,
            ], 422);
        }

        flash('danger', $proofError);
        redirect('/handovers/' . $handover['id']);
    }

    if ($receiptErrors !== []) {
        $message = implode(' ', array_unique($receiptErrors));

        if (request_wants_json()) {
            json_response([
                'ok' => false,
                'message' => $message,
            ], 422);
        }

        flash_errors($receiptErrors);
        redirect('/handovers/' . $handover['id']);
    }

    $pdo = Database::connection();
    $storedProof = null;

    try {
        if ($proofFile !== null) {
            $storedProof = store_workflow_proof_document($proofFile, 'handover', (string) $handover['handover_number'], 'receipt_report');
        }
    } catch (Throwable $exception) {
        if (request_wants_json()) {
            json_response([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        flash('danger', $exception->getMessage());
        redirect('/handovers/' . $handover['id']);
    }

    $pdo->beginTransaction();

    try {
        foreach ($receiptUpdates as $update) {
            Database::execute(
                'UPDATE handover_lines
                 SET quantity_received = :quantity_received,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'quantity_received' => (float) $update['received'],
                    'id' => (int) $update['line_id'],
                ]
            );
        }

        Database::execute(
            'UPDATE handovers
             SET status = :status,
                 receipt_notes = :receipt_notes,
                 receipt_reported_at = NOW(),
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'status' => $hasVariance ? 'receipt_review' : 'delivered',
                'receipt_notes' => $receiptNotes !== '' ? $receiptNotes : null,
                'updated_by' => (int) $user['id'],
                'id' => (int) $handover['id'],
            ]
        );

        if ($storedProof !== null) {
            create_workflow_document_record(
                'handover',
                (int) $handover['id'],
                (string) $handover['handover_number'],
                'proof_image',
                'receipt_report',
                $storedProof,
                (int) $user['id']
            );
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($storedProof !== null) {
            delete_workflow_document_file((string) $storedProof['stored_filename']);
        }

        flash('danger', $exception->getMessage());
        redirect('/handovers/' . $handover['id']);
    }

    if (!empty($handover['source_owner_user_id'])) {
        create_notification(
            (int) $handover['source_owner_user_id'],
            $hasVariance ? 'handover_receipt_review' : 'handover_received',
            $hasVariance
                ? 'Handover ' . $handover['handover_number'] . ' needs receipt review'
                : 'Handover ' . $handover['handover_number'] . ' was received',
            $hasVariance
                ? ($user['name'] ?? 'Recipient') . ' reported the actual received quantity and is waiting for your confirmation.'
                : ($user['name'] ?? 'Recipient') . ' confirmed the delivered quantity and can now track usage.',
            url('/handovers/' . $handover['id']),
            'handover',
            (int) $handover['id'],
            (int) ($user['id'] ?? 0)
        );
    }

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => $hasVariance
                ? 'Receipt report saved. Waiting for the storage owner to confirm the shortage.'
                : 'Receipt confirmed. You can now track usage and returns.',
            'redirect_url' => url('/handovers/' . $handover['id']),
        ]);
    }

    flash('success', $hasVariance
        ? 'Receipt report saved. Waiting for the storage owner to confirm the shortage.'
        : 'Receipt confirmed. You can now track usage and returns.');
    redirect('/handovers/' . $handover['id']);
}

function handle_handovers_confirm_receipt_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('handovers.approve');
    verify_csrf();

    $handover = find_handover_or_abort((int) $params['id']);
    $user = Auth::user();
    $receiptConfirmBlockReason = handover_receipt_confirm_block_reason($handover, $user);

    if ($receiptConfirmBlockReason !== null) {
        flash('danger', $receiptConfirmBlockReason);
        redirect('/handovers/' . $handover['id']);
    }

    $lines = handover_lines((int) $handover['id']);
    $bufferStorageId = system_storage_id('handover_buffer');
    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        foreach ($lines as $line) {
            $received = round((float) $line['quantity_received'], 2);
            $planned = round((float) $line['quantity_handed'], 2);
            $shortage = round($planned - $received, 2);

            if ($shortage <= 0) {
                continue;
            }

            $item = find_item_or_abort((int) $line['item_id']);

            apply_inventory_movement(
                $item,
                'transfer',
                $shortage,
                $bufferStorageId,
                (int) $handover['source_storage_id'],
                date('Y-m-d H:i:s'),
                (string) $handover['handover_number'],
                'Unreceived handover quantity returned to source storage.',
                (int) $user['id'],
                'handover',
                (int) $handover['id']
            );
        }

        Database::execute(
            'UPDATE handovers
             SET status = "delivered",
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'updated_by' => (int) $user['id'],
                'id' => (int) $handover['id'],
            ]
        );

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/handovers/' . $handover['id']);
    }

    if (!empty($handover['recipient_user_id'])) {
        create_notification(
            (int) $handover['recipient_user_id'],
            'handover_delivery_confirmed',
            'Handover ' . $handover['handover_number'] . ' is ready',
            'The reported received quantity was confirmed. You can now track usage and returns.',
            url('/handovers/' . $handover['id']),
            'handover',
            (int) $handover['id'],
            (int) ($user['id'] ?? 0)
        );
    }

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => 'Receipt discrepancy approved. The handover is now active.',
            'redirect_url' => url('/handovers/' . $handover['id']),
        ]);
    }

    flash('success', 'Receipt discrepancy approved. The handover is now active.');
    redirect('/handovers/' . $handover['id']);
}

function handle_handovers_close_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('handovers.close');
    verify_csrf();

    $handover = find_handover_or_abort((int) $params['id']);
    $user = Auth::user();
    $isSourceOwner = Auth::isOwner()
        || (int) ($handover['source_owner_user_id'] ?? 0) === (int) ($user['id'] ?? 0)
        || (int) ($handover['created_by'] ?? 0) === (int) ($user['id'] ?? 0);
    $isRecipient = (int) ($handover['recipient_user_id'] ?? 0) === (int) ($user['id'] ?? 0);

    if (($handover['status'] ?? '') !== 'delivered') {
        flash('danger', 'Only delivered handovers can be submitted.');
        redirect('/handovers/' . $handover['id']);
    }

    $returnedInput = input('line_returned', []);
    $usedInput = input('line_used', []);
    $usageInput = [
        'quantity' => input('line_usage_quantity', []),
        'reason' => input('line_usage_reason', []),
        'other' => input('line_usage_other', []),
        'notes' => input('line_usage_notes', []),
    ];
    $closedNotes = trim((string) input('closed_notes'));
    $lines = handover_lines((int) $handover['id']);
    [$lineUpdates, $errors] = build_handover_close_updates($lines, $returnedInput, $usageInput, $usedInput);
    $proofFile = uploaded_file('proof_image');
    $proofError = validate_workflow_proof_upload($proofFile);

    if (!$isRecipient && !$isSourceOwner) {
        $errors[] = 'Only the recipient or storage owner can submit this handover.';
    }

    if ($proofError !== null) {
        $errors[] = $proofError;
    }

    if ($errors !== []) {
        if (request_wants_json()) {
            json_response([
                'ok' => false,
                'message' => $errors[0],
            ], 422);
        }

        flash_errors($errors);
        redirect('/handovers/' . $handover['id']);
    }

    $pdo = Database::connection();
    $storedProof = null;

    try {
        if ($proofFile !== null) {
            $storedProof = store_workflow_proof_document($proofFile, 'handover', (string) $handover['handover_number'], 'closeout_report');
        }
    } catch (Throwable $exception) {
        if (request_wants_json()) {
            json_response([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        flash('danger', $exception->getMessage());
        redirect('/handovers/' . $handover['id']);
    }

    $pdo->beginTransaction();

    try {
        foreach ($lineUpdates as $update) {
            Database::execute(
                'UPDATE handover_lines
                 SET quantity_used = :quantity_used,
                     quantity_returned = :quantity_returned,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'quantity_used' => $update['used'],
                    'quantity_returned' => $update['returned'],
                    'id' => $update['line_id'],
                ]
            );
        }

        save_handover_usage_breakdowns((int) $handover['id'], $lineUpdates, (int) $user['id']);

        if ($isSourceOwner && empty($handover['recipient_user_id'])) {
            finalize_handover_inventory($handover, $lineUpdates, (int) $user['id']);

            Database::execute(
                'UPDATE handovers
                 SET status = "closed",
                     closed_notes = :closed_notes,
                     submitted_at = COALESCE(submitted_at, NOW()),
                     submitted_by = COALESCE(submitted_by, :submitted_by),
                     approved_at = NOW(),
                     approved_by = :approved_by,
                     completed_at = NOW(),
                     completed_by = :completed_by,
                     updated_by = :updated_by,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'closed_notes' => $closedNotes !== '' ? $closedNotes : null,
                    'submitted_by' => (int) $user['id'],
                    'approved_by' => (int) $user['id'],
                    'completed_by' => (int) $user['id'],
                    'updated_by' => (int) $user['id'],
                    'id' => (int) $handover['id'],
                ]
            );
        } else {
            Database::execute(
                'UPDATE handovers
                 SET status = "pending_approval",
                     closed_notes = :closed_notes,
                     submitted_at = NOW(),
                     submitted_by = :submitted_by,
                     updated_by = :updated_by,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'closed_notes' => $closedNotes !== '' ? $closedNotes : null,
                    'submitted_by' => (int) $user['id'],
                    'updated_by' => (int) $user['id'],
                    'id' => (int) $handover['id'],
                ]
            );
        }

        if ($storedProof !== null) {
            create_workflow_document_record(
                'handover',
                (int) $handover['id'],
                (string) $handover['handover_number'],
                'proof_image',
                'closeout_report',
                $storedProof,
                (int) $user['id']
            );
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($storedProof !== null) {
            delete_workflow_document_file((string) $storedProof['stored_filename']);
        }

        flash('danger', $exception->getMessage());
        redirect('/handovers/' . $handover['id']);
    }

    if ($isSourceOwner && empty($handover['recipient_user_id'])) {
        if (request_wants_json()) {
            json_response([
                'ok' => true,
                'message' => 'Handover closed.',
                'redirect_url' => url('/handovers/' . $handover['id']),
            ]);
        }

        flash('success', 'Handover closed.');
        redirect('/handovers/' . $handover['id']);
    }

    if (!empty($handover['source_owner_user_id'])) {
        create_notification(
            (int) $handover['source_owner_user_id'],
            'handover_waiting_approval',
            'Handover ' . $handover['handover_number'] . ' is waiting for approval',
            ($user['name'] ?? 'Someone') . ' submitted used quantities and the remaining stock is waiting for your approval.',
            url('/handovers/' . $handover['id']),
            'handover',
            (int) $handover['id'],
            (int) ($user['id'] ?? 0)
        );
    }

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => 'Handover submitted for approval.',
            'redirect_url' => url('/handovers/' . $handover['id']),
        ]);
    }

    flash('success', 'Handover submitted for approval.');
    redirect('/handovers/' . $handover['id']);
}

function handle_handovers_approve_submit(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('handovers.approve');
    verify_csrf();

    $handover = find_handover_or_abort((int) $params['id']);
    $user = Auth::user();
    $isSourceOwner = Auth::isOwner()
        || (int) ($handover['source_owner_user_id'] ?? 0) === (int) ($user['id'] ?? 0)
        || (int) ($handover['created_by'] ?? 0) === (int) ($user['id'] ?? 0);

    if (!$isSourceOwner) {
        flash('danger', 'Only the storage owner can approve this handover.');
        redirect('/handovers/' . $handover['id']);
    }

    if (($handover['status'] ?? '') !== 'pending_approval') {
        flash('danger', 'Only handovers waiting for approval can be approved.');
        redirect('/handovers/' . $handover['id']);
    }

    $closedNotes = trim((string) input('closed_notes', (string) ($handover['closed_notes'] ?? '')));
    $lines = handover_lines((int) $handover['id']);
    $usageInput = [
        'quantity' => input('line_usage_quantity', []),
        'reason' => input('line_usage_reason', []),
        'other' => input('line_usage_other', []),
        'notes' => input('line_usage_notes', []),
    ];
    [$lineUpdates, $errors] = build_handover_approval_updates($lines, input('line_returned', []), $usageInput);

    if ($errors !== []) {
        if (request_wants_json()) {
            json_response([
                'ok' => false,
                'message' => $errors[0],
            ], 422);
        }

        flash_errors($errors);
        redirect('/handovers/' . $handover['id']);
    }

    $pdo = Database::connection();
    $pdo->beginTransaction();

    try {
        foreach ($lineUpdates as $update) {
            Database::execute(
                'UPDATE handover_lines
                 SET quantity_used = :quantity_used,
                     quantity_returned = :quantity_returned,
                     updated_at = NOW()
                 WHERE id = :id',
                [
                    'quantity_used' => $update['used'],
                    'quantity_returned' => $update['returned'],
                    'id' => $update['line_id'],
                ]
            );
        }

        save_handover_usage_breakdowns((int) $handover['id'], $lineUpdates, (int) $user['id']);
        finalize_handover_inventory($handover, $lineUpdates, (int) $user['id']);

        Database::execute(
            'UPDATE handovers
             SET status = "closed",
                 closed_notes = :closed_notes,
                 approved_at = NOW(),
                 approved_by = :approved_by,
                 completed_at = NOW(),
                 completed_by = :completed_by,
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'closed_notes' => $closedNotes !== '' ? $closedNotes : null,
                'approved_by' => (int) $user['id'],
                'completed_by' => (int) $user['id'],
                'updated_by' => (int) $user['id'],
                'id' => (int) $handover['id'],
            ]
        );

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', $exception->getMessage());
        redirect('/handovers/' . $handover['id']);
    }

    try {
        $updatedHandover = find_handover_or_abort((int) $handover['id']);
        ensure_workflow_signoff_pdf('handover', $updatedHandover, handover_lines((int) $handover['id']));
    } catch (Throwable $exception) {
        // Attachment regeneration should not block an already approved closeout.
    }

    if (!empty($handover['recipient_user_id'])) {
        create_notification(
            (int) $handover['recipient_user_id'],
            'handover_closed',
            'Handover ' . $handover['handover_number'] . ' approved',
            'The used quantity was accepted and the remaining stock was returned to the storage.',
            url('/handovers/' . $handover['id']),
            'handover',
            (int) $handover['id'],
            (int) ($user['id'] ?? 0)
        );
    }

    if (request_wants_json()) {
        json_response([
            'ok' => true,
            'message' => 'Handover approved and closed.',
            'redirect_url' => url('/handovers/' . $handover['id']),
        ]);
    }

    flash('success', 'Handover approved and closed.');
    redirect('/handovers/' . $handover['id']);
}

function handle_export_handovers(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('handovers.export');

    $filters = handover_filters();
    if (trim((string) query('status', '')) === '') {
        $filters['status'] = 'all';
    }
    $handovers = handover_summary_rows($filters);
    $rows = [];

    foreach ($handovers as $handover) {
        foreach (handover_lines((int) $handover['id']) as $line) {
            $baseQuantity = in_array((string) ($handover['status'] ?? ''), ['requested', 'awaiting_receipt'], true)
                ? round((float) ($line['quantity_handed'] ?? 0), 2)
                : round((float) ($line['quantity_received'] ?? 0), 2);
            $remainingQuantity = max(0, round($baseQuantity - (float) ($line['quantity_used'] ?? 0) - (float) ($line['quantity_returned'] ?? 0), 2));

            $rows[] = [
                $handover['handover_number'],
                (string) ($handover['handover_mode'] ?? 'direct') === 'request' ? 'Request' : 'Direct',
                handover_status_label((string) $handover['status']),
                $handover['source_storage_name'],
                $handover['recipient_name'],
                $handover['requested_at'] ?: '',
                $handover['issued_at'],
                $handover['request_approved_at'] ?: '',
                $handover['request_rejected_at'] ?: '',
                $handover['receipt_reported_at'] ?: '',
                $handover['completed_at'] ?: '',
                $line['item_name'],
                $line['item_sku'],
                $line['unit'],
                format_quantity($line['quantity_handed']),
                format_quantity($line['quantity_received']),
                format_quantity($line['quantity_used']),
                format_quantity($line['quantity_returned']),
                format_quantity($remainingQuantity),
                (string) ($line['expected_usage_reason_summary'] ?? ''),
                (string) ($line['usage_reason_summary'] ?? ''),
                (string) ($line['usage_variance_summary'] ?? ''),
                $handover['notes'] ?: '',
                $handover['request_decision_notes'] ?: '',
                $handover['receipt_notes'] ?: '',
                $handover['closed_notes'] ?: '',
            ];
        }
    }

    export_csv('handovers-export-' . date('Ymd-His') . '.csv', [
        'Handover Number',
        'Mode',
        'Status',
        'Source Storage',
        'Recipient',
        'Requested At',
        'Issued At',
        'Request Approved At',
        'Request Rejected At',
        'Receipt Reported At',
        'Completed At',
        'Item',
        'SKU',
        'Unit',
        'Planned Quantity',
        'Received Quantity',
        'Used Quantity',
        'Returned Quantity',
        'Remaining Quantity',
        'Expected Usage Reasons',
        'Usage Reasons',
        'Usage Variance',
        'Notes',
        'Request Decision Notes',
        'Receipt Notes',
        'Closed Notes',
    ], $rows);
}
