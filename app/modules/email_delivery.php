<?php
declare(strict_types=1);

function send_inventory_php_mail(
    string $recipientEmail,
    ?string $recipientName,
    string $subject,
    string $body
): array {
    if (!function_exists('mail')) {
        return ['ok' => false, 'error' => 'PHP mail() is not available.'];
    }

    $senderEmail = email_sender_email();
    $senderName = email_display_name(email_sender_name());
    $replyTo = email_reply_to();
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'From: ' . ($senderName !== '' ? $senderName . ' ' : '') . '<' . $senderEmail . '>',
        'X-Mailer: Inventory KONA',
    ];

    if ($replyTo !== '') {
        $headers[] = 'Reply-To: ' . $replyTo;
    }

    $mailWarning = null;
    set_error_handler(static function (int $severity, string $message) use (&$mailWarning): bool {
        $mailWarning = $message;

        return true;
    });

    try {
        $sent = mail($recipientEmail, email_encoded_header($subject) ?: $subject, $body, implode("\r\n", $headers));
    } finally {
        restore_error_handler();
    }

    return $sent
        ? ['ok' => true, 'error' => null]
        : ['ok' => false, 'error' => $mailWarning ?: 'PHP mail() returned false.'];
}

function record_email_delivery_log(
    string $emailType,
    string $recipientEmail,
    ?string $recipientName,
    string $subject,
    string $status,
    ?string $errorMessage = null,
    ?int $userId = null,
    ?string $entityType = null,
    ?int $entityId = null
): void {
    try {
        Database::execute(
            'INSERT INTO email_delivery_logs (
                user_id,
                email_type,
                recipient_email,
                recipient_name,
                subject,
                status,
                entity_type,
                entity_id,
                error_message,
                created_at
             ) VALUES (
                :user_id,
                :email_type,
                :recipient_email,
                :recipient_name,
                :subject,
                :status,
                :entity_type,
                :entity_id,
                :error_message,
                NOW()
             )',
            [
                'user_id' => $userId,
                'email_type' => substr($emailType, 0, 80),
                'recipient_email' => substr($recipientEmail, 0, 190),
                'recipient_name' => $recipientName !== null && trim($recipientName) !== '' ? substr(trim($recipientName), 0, 190) : null,
                'subject' => substr(email_header_value($subject), 0, 190),
                'status' => in_array($status, ['sent', 'failed', 'suppressed'], true) ? $status : 'failed',
                'entity_type' => $entityType !== null && $entityType !== '' ? substr($entityType, 0, 80) : null,
                'entity_id' => $entityId,
                'error_message' => $errorMessage !== null && trim($errorMessage) !== '' ? substr(trim($errorMessage), 0, 255) : null,
            ]
        );
    } catch (Throwable $exception) {
        // Email logging must not block login, stock, or approval workflows.
    }
}

function send_inventory_email(
    string $recipientEmail,
    ?string $recipientName,
    string $subject,
    string $body,
    string $emailType,
    ?int $userId = null,
    ?string $entityType = null,
    ?int $entityId = null,
    bool $force = false
): array {
    $recipientEmail = strtolower(trim($recipientEmail));
    $recipientName = $recipientName !== null ? trim($recipientName) : null;
    $subject = email_header_value($subject);
    $status = 'failed';
    $errorMessage = null;

    if (!$force && !email_delivery_enabled()) {
        $status = 'suppressed';
        $errorMessage = 'Email delivery is disabled.';
        record_email_delivery_log($emailType, $recipientEmail, $recipientName, $subject, $status, $errorMessage, $userId, $entityType, $entityId);

        return ['ok' => false, 'status' => $status, 'error' => $errorMessage];
    }

    if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        $errorMessage = 'Recipient email is invalid.';
        record_email_delivery_log($emailType, $recipientEmail, $recipientName, $subject, $status, $errorMessage, $userId, $entityType, $entityId);

        return ['ok' => false, 'status' => $status, 'error' => $errorMessage];
    }

    if ($subject === '') {
        $subject = 'Inventory notification';
    }

    if (email_log_only_enabled()) {
        $status = 'suppressed';
        $errorMessage = 'Log-only test mode is enabled.';
        record_email_delivery_log($emailType, $recipientEmail, $recipientName, $subject, $status, $errorMessage, $userId, $entityType, $entityId);

        return ['ok' => true, 'status' => $status, 'error' => $errorMessage];
    }

    $delivery = email_transport() === 'smtp'
        ? send_inventory_smtp_email($recipientEmail, $recipientName, $subject, $body)
        : send_inventory_php_mail($recipientEmail, $recipientName, $subject, $body);

    if (!empty($delivery['ok'])) {
        $status = 'sent';
        record_email_delivery_log($emailType, $recipientEmail, $recipientName, $subject, $status, null, $userId, $entityType, $entityId);

        return ['ok' => true, 'status' => $status, 'error' => null];
    }

    $errorMessage = (string) ($delivery['error'] ?? 'Email delivery failed.');
    record_email_delivery_log($emailType, $recipientEmail, $recipientName, $subject, $status, $errorMessage, $userId, $entityType, $entityId);

    return ['ok' => false, 'status' => $status, 'error' => $errorMessage];
}
