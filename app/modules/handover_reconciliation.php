<?php
declare(strict_types=1);

// Handover-level operational reconciliation. Reasons describe the whole handover,
// never an individual SKU.

function handover_operational_reason_options(): array
{
    return [
        'online' => 'Online',
        'walkin' => 'Walk-in',
        'event' => 'Event',
        'sport' => 'Sport',
        'damage' => 'Damage',
        'complimentary' => 'Complimentary',
        'noshow' => 'No Show',
        'other' => 'Other',
    ];
}

function handover_reconciliation_variance_reason_options(): array
{
    return [
        'counting_error' => 'Counting error',
        'unreported_usage' => 'Unreported usage',
        'lost_or_missing' => 'Lost or missing stock',
        'damaged_unrecorded' => 'Damage not recorded',
        'timing_difference' => 'Timing difference',
        'other' => 'Other',
    ];
}

function handover_uses_operational_reconciliation(array $handover): bool
{
    return !handover_is_storage_transfer($handover)
        && (string) ($handover['usage_reporting_mode'] ?? 'legacy_per_item') === 'operational_summary';
}

function normalize_handover_reconciliation_unit(string $unit): string
{
    $unit = trim($unit);

    return $unit !== '' ? substr($unit, 0, 40) : 'pcs';
}

function handover_reconciliation_line_groups(array $lines): array
{
    $groups = [];

    foreach ($lines as $line) {
        $unit = normalize_handover_reconciliation_unit((string) ($line['unit'] ?? 'pcs'));
        $groups[$unit][] = $line;
    }

    ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);

    return $groups;
}

function handover_reconciliations_for_handover(int $handoverId): array
{
    $headers = Database::fetchAll(
        'SELECT reconciliation.*,
                submitter.name AS submitted_by_name,
                approver.name AS approved_by_name
         FROM handover_reconciliations reconciliation
         LEFT JOIN users submitter ON submitter.id = reconciliation.submitted_by
         LEFT JOIN users approver ON approver.id = reconciliation.approved_by
         WHERE reconciliation.handover_id = :handover_id
         ORDER BY reconciliation.unit ASC',
        ['handover_id' => $handoverId]
    );

    if ($headers === []) {
        return [];
    }

    $headerIds = array_map(static fn (array $header): int => (int) $header['id'], $headers);
    $params = [];
    $placeholders = [];

    foreach ($headerIds as $index => $headerId) {
        $key = 'header_id_' . $index;
        $params[$key] = $headerId;
        $placeholders[] = ':' . $key;
    }

    $entries = Database::fetchAll(
        'SELECT *
         FROM handover_reconciliation_entries
         WHERE reconciliation_id IN (' . implode(', ', $placeholders) . ')
         ORDER BY reconciliation_id ASC, id ASC',
        $params
    );
    $entriesByHeader = [];

    foreach ($entries as $entry) {
        $entriesByHeader[(int) $entry['reconciliation_id']][(string) $entry['reason_code']] = $entry;
    }

    $result = [];

    foreach ($headers as $header) {
        $unit = normalize_handover_reconciliation_unit((string) $header['unit']);
        $header['entries'] = $entriesByHeader[(int) $header['id']] ?? [];
        $result[$unit] = $header;
    }

    return $result;
}

function build_handover_operational_line_updates(array $lines, $returnedInput): array
{
    $updates = [];
    $errors = [];

    foreach ($lines as $line) {
        $lineId = (int) $line['id'];
        $received = handover_active_quantity($line);
        $returnedRaw = is_array($returnedInput)
            ? ($returnedInput[$lineId] ?? $returnedInput[(string) $lineId] ?? null)
            : null;

        if ($returnedRaw === null || trim((string) $returnedRaw) === '' || !is_numeric_value($returnedRaw)) {
            $errors[] = $line['item_name'] . ' must have a valid returned quantity.';
            continue;
        }

        $returned = round(quantity_value($returnedRaw), 2);

        if ($returned < 0 || $returned > $received) {
            $errors[] = $line['item_name'] . ' returned quantity must be between 0 and ' . format_quantity($received) . ' ' . (string) ($line['unit'] ?? 'pcs') . '.';
            continue;
        }

        $updates[] = [
            'line_id' => $lineId,
            'item_id' => (int) $line['item_id'],
            'unit' => normalize_handover_reconciliation_unit((string) ($line['unit'] ?? 'pcs')),
            'used' => round($received - $returned, 2),
            'returned' => $returned,
            'breakdowns' => [],
        ];
    }

    return [$updates, $errors];
}

function handover_reconciliation_post_rows($input): array
{
    if (!is_array($input)) {
        return [];
    }

    $rows = [];

    foreach ($input as $row) {
        if (!is_array($row)) {
            continue;
        }

        $unit = normalize_handover_reconciliation_unit((string) ($row['unit'] ?? 'pcs'));
        $rows[$unit] = $row;
    }

    return $rows;
}

function calculate_handover_reconciliation(
    string $unit,
    array $lines,
    array $lineUpdates,
    array $inputRow,
    bool $approvalMode
): array {
    $errors = [];
    $updatesByLine = [];

    foreach ($lineUpdates as $update) {
        $updatesByLine[(int) $update['line_id']] = $update;
    }

    $issued = 0.0;
    $received = 0.0;
    $returned = 0.0;

    foreach ($lines as $line) {
        $lineId = (int) $line['id'];
        $issued += round((float) ($line['quantity_handed'] ?? 0), 2);
        $received += handover_active_quantity($line);
        $returned += round((float) ($updatesByLine[$lineId]['returned'] ?? $line['quantity_returned'] ?? 0), 2);
    }

    $entries = [];

    foreach (handover_operational_reason_options() as $reasonCode => $reasonLabel) {
        $raw = $inputRow['reasons'][$reasonCode] ?? $inputRow[$reasonCode] ?? 0;

        if ($raw === '' || $raw === null) {
            $raw = 0;
        }

        if (!is_numeric_value($raw) || quantity_value($raw) < 0) {
            $errors[] = $reasonLabel . ' must be zero or more for ' . $unit . '.';
            continue;
        }

        $entries[$reasonCode] = [
            'reason_code' => $reasonCode,
            'quantity' => round(quantity_value($raw), 2),
            'notes' => null,
        ];
    }

    $online = (float) ($entries['online']['quantity'] ?? 0);
    $noShow = (float) ($entries['noshow']['quantity'] ?? 0);

    if ($noShow > $online) {
        $errors[] = 'No Show cannot exceed Online for ' . $unit . '.';
    }

    $physicalUsed = round($received - $returned, 2);
    $operationalUsed = round(
        $online
        - $noShow
        + (float) ($entries['walkin']['quantity'] ?? 0)
        + (float) ($entries['event']['quantity'] ?? 0)
        + (float) ($entries['sport']['quantity'] ?? 0)
        + (float) ($entries['damage']['quantity'] ?? 0)
        + (float) ($entries['complimentary']['quantity'] ?? 0)
        + (float) ($entries['other']['quantity'] ?? 0),
        2
    );
    $difference = round($physicalUsed - $operationalUsed, 2);
    $discrepancyNotes = trim((string) ($inputRow['discrepancy_notes'] ?? ''));
    $varianceReason = trim((string) ($inputRow['variance_reason_code'] ?? ''));
    $varianceNotes = trim((string) ($inputRow['variance_notes'] ?? ''));

    if ($difference < -0.009) {
        $errors[] = 'Operational usage exceeds physical used stock by ' . format_quantity(abs($difference)) . ' ' . $unit . '. Correct the operational totals before submitting.';
    } elseif ($difference > 0.009 && $discrepancyNotes === '') {
        $errors[] = 'Explain the positive Difference for ' . $unit . ' before submitting.';
    }

    if ($approvalMode && $difference > 0.009) {
        if (!array_key_exists($varianceReason, handover_reconciliation_variance_reason_options())) {
            $errors[] = 'Select an audited variance reason for the positive Difference in ' . $unit . '.';
        }

        if ($varianceNotes === '') {
            $errors[] = 'Add an approval note for the positive Difference in ' . $unit . '.';
        }
    }

    return [[
        'unit' => $unit,
        'issued_total' => round($issued, 2),
        'received_total' => round($received, 2),
        'returned_total' => round($returned, 2),
        'physical_used_total' => $physicalUsed,
        'operational_used_total' => $operationalUsed,
        'difference_total' => $difference,
        'discrepancy_notes' => $discrepancyNotes,
        'variance_reason_code' => $varianceReason,
        'variance_notes' => $varianceNotes,
        'entries' => $entries,
    ], $errors];
}

function build_handover_reconciliation_payloads(
    array $lines,
    array $lineUpdates,
    $input,
    bool $approvalMode = false
): array {
    $inputRows = handover_reconciliation_post_rows($input);
    $payloads = [];
    $errors = [];

    foreach (handover_reconciliation_line_groups($lines) as $unit => $unitLines) {
        if (!isset($inputRows[$unit])) {
            $errors[] = 'Complete the operational reconciliation for ' . $unit . '.';
            continue;
        }

        [$payload, $unitErrors] = calculate_handover_reconciliation(
            $unit,
            $unitLines,
            $lineUpdates,
            $inputRows[$unit],
            $approvalMode
        );
        $payloads[] = $payload;
        $errors = array_merge($errors, $unitErrors);
    }

    return [$payloads, $errors];
}

function save_handover_reconciliations(
    int $handoverId,
    array $payloads,
    int $performedBy,
    bool $approved = false
): void {
    foreach ($payloads as $payload) {
        Database::execute(
            'INSERT INTO handover_reconciliations (
                handover_id,
                unit,
                issued_total,
                received_total,
                returned_total,
                physical_used_total,
                operational_used_total,
                difference_total,
                discrepancy_notes,
                variance_reason_code,
                variance_notes,
                submitted_by,
                approved_by,
                submitted_at,
                approved_at,
                created_at,
                updated_at
             ) VALUES (
                :handover_id,
                :unit,
                :issued_total,
                :received_total,
                :returned_total,
                :physical_used_total,
                :operational_used_total,
                :difference_total,
                :discrepancy_notes,
                :variance_reason_code,
                :variance_notes,
                :submitted_by,
                :approved_by,
                NOW(),
                :approved_at,
                NOW(),
                NOW()
             )
             ON DUPLICATE KEY UPDATE
                issued_total = VALUES(issued_total),
                received_total = VALUES(received_total),
                returned_total = VALUES(returned_total),
                physical_used_total = VALUES(physical_used_total),
                operational_used_total = VALUES(operational_used_total),
                difference_total = VALUES(difference_total),
                discrepancy_notes = VALUES(discrepancy_notes),
                variance_reason_code = VALUES(variance_reason_code),
                variance_notes = VALUES(variance_notes),
                submitted_by = COALESCE(submitted_by, VALUES(submitted_by)),
                approved_by = VALUES(approved_by),
                submitted_at = COALESCE(submitted_at, NOW()),
                approved_at = VALUES(approved_at),
                updated_at = NOW()',
            [
                'handover_id' => $handoverId,
                'unit' => (string) $payload['unit'],
                'issued_total' => (float) $payload['issued_total'],
                'received_total' => (float) $payload['received_total'],
                'returned_total' => (float) $payload['returned_total'],
                'physical_used_total' => (float) $payload['physical_used_total'],
                'operational_used_total' => (float) $payload['operational_used_total'],
                'difference_total' => (float) $payload['difference_total'],
                'discrepancy_notes' => $payload['discrepancy_notes'] !== '' ? (string) $payload['discrepancy_notes'] : null,
                'variance_reason_code' => $payload['variance_reason_code'] !== '' ? (string) $payload['variance_reason_code'] : null,
                'variance_notes' => $payload['variance_notes'] !== '' ? (string) $payload['variance_notes'] : null,
                'submitted_by' => $performedBy,
                'approved_by' => $approved ? $performedBy : null,
                'approved_at' => $approved ? date('Y-m-d H:i:s') : null,
            ]
        );

        $reconciliationId = (int) Database::scalar(
            'SELECT id
             FROM handover_reconciliations
             WHERE handover_id = :handover_id
               AND unit = :unit
             LIMIT 1',
            [
                'handover_id' => $handoverId,
                'unit' => (string) $payload['unit'],
            ]
        );

        Database::execute(
            'DELETE FROM handover_reconciliation_entries
             WHERE reconciliation_id = :reconciliation_id',
            ['reconciliation_id' => $reconciliationId]
        );

        foreach ((array) ($payload['entries'] ?? []) as $entry) {
            Database::execute(
                'INSERT INTO handover_reconciliation_entries (
                    reconciliation_id,
                    reason_code,
                    quantity,
                    notes,
                    created_at,
                    updated_at
                 ) VALUES (
                    :reconciliation_id,
                    :reason_code,
                    :quantity,
                    :notes,
                    NOW(),
                    NOW()
                 )',
                [
                    'reconciliation_id' => $reconciliationId,
                    'reason_code' => (string) $entry['reason_code'],
                    'quantity' => (float) $entry['quantity'],
                    'notes' => !empty($entry['notes']) ? (string) $entry['notes'] : null,
                ]
            );
        }
    }
}

function clear_legacy_handover_usage_breakdowns(int $handoverId): void
{
    Database::execute(
        'DELETE FROM handover_usage_breakdowns WHERE handover_id = :handover_id',
        ['handover_id' => $handoverId]
    );
    Database::execute(
        'DELETE FROM handover_expected_usage_breakdowns WHERE handover_id = :handover_id',
        ['handover_id' => $handoverId]
    );
}
