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
