<?php
declare(strict_types=1);

// Signoff document revision timestamps for regeneration checks.

function workflow_signoff_revision_timestamp(array $record, array $lines): int
{
    $timestamps = [];

    foreach ([
        'updated_at',
        'requested_at',
        'approved_at',
        'completed_at',
        'cancelled_at',
        'issued_at',
        'receipt_reported_at',
        'submitted_at',
        'request_approved_at',
        'request_rejected_at',
    ] as $field) {
        $value = (string) ($record[$field] ?? '');

        if ($value !== '') {
            $timestamps[] = strtotime($value) ?: 0;
        }
    }

    foreach ($lines as $line) {
        $value = (string) ($line['updated_at'] ?? '');

        if ($value !== '') {
            $timestamps[] = strtotime($value) ?: 0;
        }

        foreach ((array) ($line['usage_breakdowns'] ?? []) as $breakdown) {
            $breakdownUpdated = (string) ($breakdown['updated_at'] ?? '');

            if ($breakdownUpdated !== '') {
                $timestamps[] = strtotime($breakdownUpdated) ?: 0;
            }
        }

        foreach ((array) ($line['expected_usage_breakdowns'] ?? []) as $breakdown) {
            $breakdownUpdated = (string) ($breakdown['updated_at'] ?? '');

            if ($breakdownUpdated !== '') {
                $timestamps[] = strtotime($breakdownUpdated) ?: 0;
            }
        }
    }

    if ((int) ($record['id'] ?? 0) > 0
        && (string) ($record['usage_reporting_mode'] ?? '') === 'operational_summary') {
        try {
            $reconciliationUpdatedAt = Database::scalar(
                'SELECT MAX(revision_at)
                 FROM (
                     SELECT MAX(updated_at) AS revision_at
                     FROM handover_reconciliations
                     WHERE handover_id = ?
                     UNION ALL
                     SELECT MAX(e.updated_at) AS revision_at
                     FROM handover_reconciliation_entries e
                     INNER JOIN handover_reconciliations r ON r.id = e.reconciliation_id
                     WHERE r.handover_id = ?
                 ) reconciliation_revisions',
                [(int) $record['id'], (int) $record['id']]
            );

            if ($reconciliationUpdatedAt) {
                $timestamps[] = strtotime((string) $reconciliationUpdatedAt) ?: 0;
            }
        } catch (Throwable $exception) {
            // Legacy installations can generate signoff files before the migration runs.
        }
    }

    return max(0, ...$timestamps);
}

function workflow_signoff_settings_revision_timestamp(): int
{
    try {
        $value = Database::scalar(
            'SELECT MAX(updated_at)
             FROM app_settings
             WHERE setting_key IN (
                 "workflow.signoff_template",
                 "workflow.signoff_image_size",
                 "workflow.signoff_image_custom_width",
                 "workflow.signoff_image_custom_height",
                 "brand.logo_path",
                 "brand.logo_name"
             )'
        );
    } catch (Throwable $exception) {
        return 0;
    }

    return $value ? (strtotime((string) $value) ?: 0) : 0;
}
