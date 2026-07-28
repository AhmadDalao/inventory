<?php
declare(strict_types=1);

// Signoff header metadata helpers.

function workflow_signoff_meta(string $workflowType, array $record): array
{
    $numberKey = $workflowType === 'handover' ? 'handover_number' : 'request_number';
    $title = $workflowType === 'handover' ? 'Handover Sign-Off Sheet' : 'Request Sign-Off Sheet';
    $workflowNumber = (string) ($record[$numberKey] ?? 'Workflow');

    if ($workflowType === 'handover') {
        if (function_exists('handover_is_storage_transfer') && handover_is_storage_transfer($record)) {
            return [
                'title' => $title,
                'number' => $workflowNumber,
                'open_reference' => $workflowNumber,
                'open_label' => 'Scan/Search reference',
                'party_label' => 'Destination Owner',
                'party_value' => (string) (($record['destination_owner_name'] ?? '') ?: ($record['recipient_name'] ?? '')),
                'source_label' => 'Source',
                'source_value' => (string) ($record['source_storage_name'] ?? ''),
                'target_label' => 'Destination',
                'target_value' => (string) ($record['destination_storage_name'] ?? 'Not set'),
                'mode_label' => 'Mode',
                'mode_value' => 'Storage transfer',
            ];
        }

        if (workflow_signoff_is_staff_custody($workflowType, $record)) {
            return [
                'title' => 'Staff Custody Sign-Off Sheet',
                'number' => $workflowNumber,
                'open_reference' => $workflowNumber,
                'open_label' => 'Scan/Search reference',
                'party_label' => 'Staff Member',
                'party_value' => (string) ($record['recipient_name'] ?? ''),
                'source_label' => 'Source',
                'source_value' => (string) ($record['source_storage_name'] ?? ''),
                'target_label' => 'Review Date',
                'target_value' => (string) (($record['custody_review_date'] ?? '') ?: 'Not set'),
                'mode_label' => 'Mode',
                'mode_value' => 'Long-term staff custody',
            ];
        }

        return [
            'title' => $title,
            'number' => $workflowNumber,
            'open_reference' => $workflowNumber,
            'open_label' => 'Scan/Search reference',
            'party_label' => 'Recipient',
            'party_value' => (string) ($record['recipient_name'] ?? ''),
            'source_label' => 'Source',
            'source_value' => (string) ($record['source_storage_name'] ?? ''),
            'target_label' => 'Scheduled',
            'target_value' => (string) ($record['scheduled_for_date'] ?? 'Not set'),
            'mode_label' => 'Mode',
            'mode_value' => (string) (($record['handover_mode'] ?? 'direct') === 'request' ? 'Requested handover' : 'Direct handover'),
        ];
    }

    return [
        'title' => $title,
        'number' => $workflowNumber,
        'open_reference' => $workflowNumber,
        'open_label' => 'Scan/Search reference',
        'party_label' => 'Requester',
        'party_value' => (string) ($record['requester_name'] ?? ''),
        'source_label' => 'Source',
        'source_value' => (string) ($record['source_storage_name'] ?? ''),
        'target_label' => 'Destination',
        'target_value' => (string) ($record['destination_storage_name'] ?? 'Staff issue/use'),
        'mode_label' => 'Type',
        'mode_value' => (string) (($record['request_mode'] ?? 'transfer') === 'issue' ? 'Staff use request' : 'Storage transfer'),
    ];
}

function workflow_signoff_is_storage_transfer(string $workflowType, array $record): bool
{
    return $workflowType === 'handover'
        && function_exists('handover_is_storage_transfer')
        && handover_is_storage_transfer($record);
}

function workflow_signoff_is_staff_custody(string $workflowType, array $record): bool
{
    return $workflowType === 'handover'
        && function_exists('handover_is_staff_custody')
        && handover_is_staff_custody($record);
}
