<?php
declare(strict_types=1);

function workflow_email_notification_types(): array
{
    return [
        'request_created',
        'request_approved',
        'request_rejected',
        'request_receipt_review',
        'request_completed',
        'request_receipt_confirmed',
        'handover_requested',
        'handover_created',
        'handover_request_approved',
        'handover_request_rejected',
        'handover_receipt_review',
        'handover_received',
        'handover_delivery_confirmed',
        'handover_waiting_approval',
        'handover_closed',
        'purchase_submitted',
        'purchase_approved',
        'purchase_rejected',
        'purchase_receipt_reported',
        'purchase_completed',
        'stocktake_pending_approval',
        'stocktake_approved',
    ];
}

function send_workflow_notification_email(
    int $userId,
    string $notificationType,
    string $title,
    ?string $message = null,
    ?string $actionUrl = null,
    ?string $entityType = null,
    ?int $entityId = null
): void {
    if (!email_workflow_alerts_enabled() || !in_array($notificationType, workflow_email_notification_types(), true)) {
        return;
    }

    $user = Database::fetch(
        'SELECT id, name, email, is_active
         FROM users
         WHERE id = :id
         LIMIT 1',
        ['id' => $userId]
    );

    if (!$user || (int) ($user['is_active'] ?? 0) !== 1 || trim((string) ($user['email'] ?? '')) === '') {
        return;
    }

    $bodyLines = [
        $title,
        '',
        trim((string) $message) !== '' ? trim((string) $message) : 'Open Inventory KONA for the full details.',
    ];

    if ($actionUrl !== null && trim($actionUrl) !== '') {
        $bodyLines[] = '';
        $bodyLines[] = 'Open details: ' . absolute_url($actionUrl);
    }

    $bodyLines[] = '';
    $bodyLines[] = 'This is an email copy of an in-app notification.';

    send_inventory_email(
        (string) $user['email'],
        (string) $user['name'],
        $title,
        implode("\n", $bodyLines),
        'workflow_' . $notificationType,
        (int) $user['id'],
        $entityType,
        $entityId
    );
}
