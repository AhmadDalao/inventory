<?php
declare(strict_types=1);

function handover_custody_report_filters(): array
{
    $status = (string) query('status', 'all');

    return [
        'search' => trim((string) query('search', '')),
        'status' => in_array($status, ['all', 'open', 'overdue', 'closed', 'cancelled'], true) ? $status : 'all',
    ];
}

function handover_custody_report_rows(array $filters): array
{
    $handoverFilters = handover_filters();
    $handoverFilters['target_type'] = 'custody';
    $handoverFilters['search'] = (string) ($filters['search'] ?? '');
    $handoverFilters['status'] = match ((string) ($filters['status'] ?? 'all')) {
        'open', 'overdue' => 'open',
        'closed' => 'closed',
        'cancelled' => 'cancelled',
        default => 'all',
    };

    $rows = [];

    foreach (handover_summary_rows($handoverFilters) as $handover) {
        $lines = handover_lines((int) $handover['id']);
        $totals = handover_custody_totals($handover, $lines);
        $reviewDate = trim((string) ($handover['custody_review_date'] ?? ''));
        $isOverdue = $reviewDate !== ''
            && $reviewDate < date('Y-m-d')
            && !in_array((string) $handover['status'], ['closed', 'cancelled'], true)
            && $totals['held'] > 0.009;

        if (($filters['status'] ?? 'all') === 'overdue' && !$isOverdue) {
            continue;
        }

        $handover['custody_totals'] = $totals;
        $handover['is_overdue'] = $isOverdue;
        $rows[] = $handover;
    }

    return $rows;
}

function handle_handover_custody_index(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('handovers.view');

    $filters = handover_custody_report_filters();

    View::render('handovers/custody_report', [
        'title' => 'Staff Custody',
        'filters' => $filters,
        'rows' => handover_custody_report_rows($filters),
    ]);
}

function handle_handover_custody_return_show(array $params): void
{
    app_ready_or_redirect();
    Auth::requirePermission('handovers.view');

    $handover = find_handover_or_abort((int) $params['id']);

    if (!handover_is_staff_custody($handover)) {
        abort(404, 'This handover is not a long-term custody record.');
    }

    $custodyReturn = find_handover_custody_return_or_abort(
        (int) $params['return_id'],
        (int) $handover['id']
    );
    $lines = handover_custody_return_lines((int) $custodyReturn['id']);
    $proofs = [];

    foreach ($lines as $line) {
        $proofs[(int) $line['id']] = handover_custody_return_proofs((int) $line['id']);
    }

    View::render('handovers/custody_return', [
        'title' => (string) $custodyReturn['return_number'],
        'handoverRecord' => $handover,
        'custodyReturn' => $custodyReturn,
        'returnLines' => $lines,
        'proofsByLine' => $proofs,
        'canEditReturn' => handover_custody_return_can_edit($custodyReturn, $handover),
        'canReviewReturn' => (string) $custodyReturn['status'] === 'submitted'
            && handover_custody_can_review_return($handover),
        'canRequestReplacement' => (string) $custodyReturn['status'] === 'approved'
            && empty($custodyReturn['replacement_handover_id'])
            && handover_custody_can_review_return($handover)
            && array_reduce(
                $lines,
                static fn (float $carry, array $line): float => $carry
                    + (float) $line['damaged_quantity']
                    + (float) $line['lost_quantity'],
                0.0
            ) > 0.009,
    ]);
}

function handle_handover_custody_quarantine(): void
{
    app_ready_or_redirect();
    Auth::requireOwner();

    View::render('handovers/custody_quarantine', [
        'title' => 'Damaged / Quarantine',
        'rows' => handover_custody_quarantine_rows(),
        'storages' => all_storages_for_select(),
    ]);
}

function handle_export_handover_custody(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('handovers.export');

    $rows = [];

    foreach (handover_custody_report_rows(handover_custody_report_filters()) as $handover) {
        $totals = $handover['custody_totals'];
        $rows[] = [
            $handover['handover_number'],
            handover_status_label((string) $handover['status']),
            $handover['source_storage_name'],
            $handover['recipient_name'],
            $handover['issue_condition'] ?? '',
            $handover['custody_review_date'] ?? '',
            format_quantity((float) $totals['issued']),
            format_quantity((float) $totals['received']),
            format_quantity((float) $totals['held']),
            format_quantity((float) $totals['serviceable']),
            format_quantity((float) $totals['damaged']),
            format_quantity((float) $totals['consumed']),
            format_quantity((float) $totals['lost']),
            !empty($handover['is_overdue']) ? 'Yes' : 'No',
            $handover['issued_at'] ?? '',
            $handover['completed_at'] ?? '',
        ];
    }

    export_csv('staff-custody-' . date('Ymd-His') . '.csv', [
        'Handover',
        'Status',
        'Source Storage',
        'Staff Member',
        'Issue Condition',
        'Review Date',
        'Issued',
        'Received',
        'Still Held',
        'Serviceable Returned',
        'Damaged',
        'Consumed / Worn Out',
        'Lost / Missing',
        'Overdue',
        'Issued At',
        'Closed At',
    ], $rows);
}
