<?php
declare(strict_types=1);

function handover_purpose_value(array $handover): string
{
    $purpose = (string) ($handover['handover_purpose'] ?? '');

    if (in_array($purpose, ['temporary_use', 'staff_custody', 'storage_transfer'], true)) {
        return $purpose;
    }

    return (string) ($handover['recipient_type'] ?? 'staff') === 'storage'
        || !empty($handover['destination_storage_id'])
        ? 'storage_transfer'
        : 'temporary_use';
}

function handover_is_staff_custody(array $handover): bool
{
    return handover_purpose_value($handover) === 'staff_custody';
}

function handover_issue_condition_options(): array
{
    return [
        'new' => 'New',
        'good' => 'Good',
        'fair' => 'Fair',
        'worn' => 'Worn but usable',
    ];
}

function handover_line_held_quantity(array $line): float
{
    $received = (float) ($line['quantity_received'] ?? 0);

    if ($received <= 0) {
        $received = (float) ($line['quantity_handed'] ?? 0);
    }

    return max(0.0, round(
        $received
        - (float) ($line['quantity_used'] ?? 0)
        - (float) ($line['quantity_returned'] ?? 0),
        2
    ));
}

function handover_custody_return_line_total(array $line): float
{
    return round(
        (float) ($line['serviceable_quantity'] ?? 0)
        + (float) ($line['damaged_quantity'] ?? 0)
        + (float) ($line['consumed_quantity'] ?? 0)
        + (float) ($line['lost_quantity'] ?? 0),
        2
    );
}

function handover_custody_return_number(): string
{
    return next_workflow_number('CRN', 'handover_custody_returns', 'return_number');
}

function handover_custody_returns(int $handoverId): array
{
    return Database::fetchAll(
        'SELECT custody_return.*,
                submitter.name AS submitted_by_name,
                reviewer.name AS reviewed_by_name,
                creator.name AS created_by_name,
                replacement.handover_number AS replacement_handover_number,
                COALESCE(line_totals.serviceable_quantity, 0) AS serviceable_quantity,
                COALESCE(line_totals.damaged_quantity, 0) AS damaged_quantity,
                COALESCE(line_totals.consumed_quantity, 0) AS consumed_quantity,
                COALESCE(line_totals.lost_quantity, 0) AS lost_quantity
         FROM handover_custody_returns custody_return
         LEFT JOIN users submitter ON submitter.id = custody_return.submitted_by
         LEFT JOIN users reviewer ON reviewer.id = custody_return.reviewed_by
         LEFT JOIN users creator ON creator.id = custody_return.created_by
         LEFT JOIN handovers replacement ON replacement.id = custody_return.replacement_handover_id
         LEFT JOIN (
             SELECT custody_return_id,
                    SUM(serviceable_quantity) AS serviceable_quantity,
                    SUM(damaged_quantity) AS damaged_quantity,
                    SUM(consumed_quantity) AS consumed_quantity,
                    SUM(lost_quantity) AS lost_quantity
             FROM handover_custody_return_lines
             GROUP BY custody_return_id
         ) line_totals ON line_totals.custody_return_id = custody_return.id
         WHERE custody_return.handover_id = :handover_id
         ORDER BY custody_return.created_at DESC, custody_return.id DESC',
        ['handover_id' => $handoverId]
    );
}

function find_handover_custody_return_or_abort(int $returnId, ?int $handoverId = null): array
{
    $params = ['id' => $returnId];
    $handoverCondition = '';

    if ($handoverId !== null) {
        $handoverCondition = ' AND custody_return.handover_id = :handover_id';
        $params['handover_id'] = $handoverId;
    }

    $row = Database::fetch(
        'SELECT custody_return.*,
                h.handover_number,
                h.handover_purpose,
                h.status AS handover_status,
                h.source_storage_id,
                h.recipient_user_id,
                h.created_by AS handover_created_by,
                source_storage.owner_user_id AS source_owner_user_id
         FROM handover_custody_returns custody_return
         INNER JOIN handovers h ON h.id = custody_return.handover_id
         INNER JOIN storages source_storage ON source_storage.id = h.source_storage_id
         WHERE custody_return.id = :id' . $handoverCondition . '
         LIMIT 1',
        $params
    );

    if (!$row) {
        abort(404, 'Custody return not found.');
    }

    return $row;
}

function handover_custody_return_lines(int $returnId): array
{
    return Database::fetchAll(
        'SELECT return_line.*,
                handover_line.item_name,
                handover_line.item_sku,
                handover_line.unit,
                handover_line.quantity_handed,
                handover_line.quantity_received,
                handover_line.quantity_used,
                handover_line.quantity_returned,
                item.image_path,
                item.barcode AS item_barcode,
                COALESCE(proof_totals.proof_count, 0) AS proof_count,
                COALESCE(disposition_totals.returned_to_service_quantity, 0) AS returned_to_service_quantity,
                COALESCE(disposition_totals.disposed_quantity, 0) AS disposed_quantity
         FROM handover_custody_return_lines return_line
         INNER JOIN handover_lines handover_line ON handover_line.id = return_line.handover_line_id
         INNER JOIN items item ON item.id = return_line.item_id
         LEFT JOIN (
             SELECT custody_return_line_id, COUNT(*) AS proof_count
             FROM handover_custody_return_proofs
             GROUP BY custody_return_line_id
         ) proof_totals ON proof_totals.custody_return_line_id = return_line.id
         LEFT JOIN (
             SELECT custody_return_line_id,
                    SUM(CASE WHEN action_type = "return_to_service" THEN quantity ELSE 0 END) AS returned_to_service_quantity,
                    SUM(CASE WHEN action_type = "dispose" THEN quantity ELSE 0 END) AS disposed_quantity
             FROM handover_quarantine_dispositions
             GROUP BY custody_return_line_id
         ) disposition_totals ON disposition_totals.custody_return_line_id = return_line.id
         WHERE return_line.custody_return_id = :return_id
         ORDER BY handover_line.item_name ASC, return_line.id ASC',
        ['return_id' => $returnId]
    );
}

function handover_custody_return_proofs(int $returnLineId): array
{
    return Database::fetchAll(
        'SELECT workflow_document.*
         FROM handover_custody_return_proofs return_proof
         INNER JOIN workflow_documents workflow_document ON workflow_document.id = return_proof.workflow_document_id
         WHERE return_proof.custody_return_line_id = :return_line_id
         ORDER BY workflow_document.created_at DESC, workflow_document.id DESC',
        ['return_line_id' => $returnLineId]
    );
}

function handover_custody_totals(array $handover, array $lines): array
{
    $totals = [
        'issued' => 0.0,
        'received' => 0.0,
        'held' => 0.0,
        'serviceable' => 0.0,
        'damaged' => 0.0,
        'consumed' => 0.0,
        'lost' => 0.0,
    ];

    foreach ($lines as $line) {
        $totals['issued'] += (float) ($line['quantity_handed'] ?? 0);
        $totals['received'] += (float) ($line['quantity_received'] ?? 0);
        $totals['held'] += handover_line_held_quantity($line);
    }

    $approved = Database::fetch(
        'SELECT COALESCE(SUM(return_line.serviceable_quantity), 0) AS serviceable,
                COALESCE(SUM(return_line.damaged_quantity), 0) AS damaged,
                COALESCE(SUM(return_line.consumed_quantity), 0) AS consumed,
                COALESCE(SUM(return_line.lost_quantity), 0) AS lost
         FROM handover_custody_return_lines return_line
         INNER JOIN handover_custody_returns custody_return ON custody_return.id = return_line.custody_return_id
         WHERE custody_return.handover_id = :handover_id
           AND custody_return.status = "approved"',
        ['handover_id' => (int) ($handover['id'] ?? 0)]
    ) ?: [];

    foreach (['serviceable', 'damaged', 'consumed', 'lost'] as $key) {
        $totals[$key] = (float) ($approved[$key] ?? 0);
    }

    return array_map(static fn (float $value): float => round($value, 2), $totals);
}

function handover_custody_line_totals(int $handoverId): array
{
    $rows = Database::fetchAll(
        'SELECT return_line.handover_line_id,
                COALESCE(SUM(return_line.serviceable_quantity), 0) AS serviceable_total,
                COALESCE(SUM(return_line.damaged_quantity), 0) AS damaged_total,
                COALESCE(SUM(return_line.consumed_quantity), 0) AS consumed_total,
                COALESCE(SUM(return_line.lost_quantity), 0) AS lost_total
         FROM handover_custody_return_lines return_line
         INNER JOIN handover_custody_returns custody_return
            ON custody_return.id = return_line.custody_return_id
         WHERE custody_return.handover_id = :handover_id
           AND custody_return.status = "approved"
         GROUP BY return_line.handover_line_id',
        ['handover_id' => $handoverId]
    );
    $totals = [];

    foreach ($rows as $row) {
        $totals[(int) $row['handover_line_id']] = [
            'serviceable_total' => round((float) $row['serviceable_total'], 2),
            'damaged_total' => round((float) $row['damaged_total'], 2),
            'consumed_total' => round((float) $row['consumed_total'], 2),
            'lost_total' => round((float) $row['lost_total'], 2),
        ];
    }

    return $totals;
}

function handover_custody_can_report_return(array $handover, ?array $user = null): bool
{
    $user = $user ?? Auth::user();

    return $user !== null
        && handover_is_staff_custody($handover)
        && (string) ($handover['status'] ?? '') === 'delivered'
        && (int) ($handover['recipient_user_id'] ?? 0) === (int) ($user['id'] ?? 0)
        && Auth::hasPermission('handovers.custody_return');
}

function handover_custody_can_review_return(array $handover, ?array $user = null): bool
{
    $user = $user ?? Auth::user();

    return $user !== null
        && handover_is_staff_custody($handover)
        && handover_is_source_issuer($handover, $user)
        && (Auth::isOwner() || Auth::hasPermission('handovers.custody_approve'));
}

function handover_custody_has_pending_return(int $handoverId): bool
{
    return (int) Database::scalar(
        'SELECT COUNT(*)
         FROM handover_custody_returns
         WHERE handover_id = :handover_id
           AND status = "submitted"',
        ['handover_id' => $handoverId]
    ) > 0;
}

function handover_custody_quarantine_rows(): array
{
    $quarantineStorageId = system_storage_id('damaged_quarantine');

    return Database::fetchAll(
        'SELECT return_line.*,
                custody_return.return_number,
                custody_return.handover_id,
                h.handover_number,
                h.recipient_name,
                item.name AS item_name,
                item.sku AS item_sku,
                item.unit,
                item.image_path,
                balance.quantity AS quarantine_balance,
                COALESCE(disposition_totals.processed_quantity, 0) AS processed_quantity
         FROM handover_custody_return_lines return_line
         INNER JOIN handover_custody_returns custody_return ON custody_return.id = return_line.custody_return_id
         INNER JOIN handovers h ON h.id = custody_return.handover_id
         INNER JOIN items item ON item.id = return_line.item_id
         LEFT JOIN item_storage_balances balance
            ON balance.item_id = return_line.item_id
           AND balance.storage_id = :quarantine_storage_id
         LEFT JOIN (
             SELECT custody_return_line_id, SUM(quantity) AS processed_quantity
             FROM handover_quarantine_dispositions
             GROUP BY custody_return_line_id
         ) disposition_totals ON disposition_totals.custody_return_line_id = return_line.id
         WHERE custody_return.status = "approved"
           AND return_line.damaged_quantity > COALESCE(disposition_totals.processed_quantity, 0)
         ORDER BY custody_return.reviewed_at DESC, return_line.id DESC',
        ['quarantine_storage_id' => $quarantineStorageId]
    );
}

function handover_custody_available_quarantine_quantity(array $returnLine): float
{
    return max(0.0, round(
        (float) ($returnLine['damaged_quantity'] ?? 0)
        - (float) ($returnLine['processed_quantity'] ?? 0),
        2
    ));
}

function handover_custody_return_status_label(string $status): string
{
    return [
        'draft' => 'Draft',
        'submitted' => 'Waiting for issuer review',
        'approved' => 'Approved',
        'rejected' => 'Needs correction',
        'cancelled' => 'Cancelled',
    ][$status] ?? ucwords(str_replace('_', ' ', $status));
}

function handover_custody_outcome_label(string $outcome): string
{
    return [
        'serviceable' => 'Serviceable',
        'damaged' => 'Damaged',
        'consumed' => 'Consumed / worn out',
        'lost' => 'Lost / missing',
        'held' => 'Still held',
    ][$outcome] ?? ucwords(str_replace('_', ' ', $outcome));
}

function handover_custody_return_can_edit(array $custodyReturn, array $handover, ?array $user = null): bool
{
    $user = $user ?? Auth::user();

    return $user !== null
        && in_array((string) ($custodyReturn['status'] ?? ''), ['draft', 'rejected'], true)
        && handover_custody_can_report_return($handover, $user);
}
