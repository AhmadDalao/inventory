<?php
declare(strict_types=1);

// Domain module: email delivery. Function names are preserved for route/view compatibility.

// Moved from helpers.php.

function email_delivery_enabled(): bool
{
    return site_setting('email.enabled', '1') === '1';
}

function email_password_resets_enabled(): bool
{
    return email_delivery_enabled() && site_setting('email.password_resets', '1') === '1';
}

function email_workflow_alerts_enabled(): bool
{
    return email_delivery_enabled() && site_setting('email.workflow_alerts', '0') === '1';
}

function email_log_only_enabled(): bool
{
    return site_setting('email.log_only', '0') === '1' || email_transport() === 'log_only';
}

function email_transport(): string
{
    $transport = site_setting('email.transport', 'php_mail');

    return in_array($transport, ['smtp', 'php_mail', 'log_only'], true) ? $transport : 'php_mail';
}

function email_smtp_host(): string
{
    return email_header_value(site_setting('email.smtp_host', ''));
}

function email_smtp_port(): int
{
    $port = (int) site_setting('email.smtp_port', '465');

    return $port > 0 && $port <= 65535 ? $port : 465;
}

function email_smtp_encryption(): string
{
    $encryption = strtolower(trim(site_setting('email.smtp_encryption', 'ssl')));

    return in_array($encryption, ['ssl', 'tls', 'none'], true) ? $encryption : 'ssl';
}

function email_smtp_username(): string
{
    return trim(site_setting('email.smtp_username', ''));
}

function email_smtp_password(): string
{
    return (string) site_setting('email.smtp_password', '');
}

function email_smtp_timeout(): int
{
    $timeout = (int) site_setting('email.smtp_timeout', '12');

    return max(3, min(60, $timeout));
}

function email_header_value(string $value): string
{
    $value = str_replace(["\r", "\n"], ' ', trim($value));

    return preg_replace('/\s+/', ' ', $value) ?: '';
}

function email_sender_email(): string
{
    $email = trim(site_setting('email.sender_email', 'no-reply@inventory.ahmaddalao.com'));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'no-reply@inventory.ahmaddalao.com';
    }

    return $email;
}

function email_sender_name(): string
{
    $name = email_header_value(site_setting('email.sender_name', 'Inventory KONA'));

    return $name !== '' ? $name : 'Inventory KONA';
}

function email_reply_to(): string
{
    $email = trim(site_setting('email.reply_to', ''));

    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
}

function email_display_name(string $name): string
{
    $name = email_header_value($name);

    if ($name === '') {
        return '';
    }

    if (function_exists('mb_encode_mimeheader')) {
        return mb_encode_mimeheader($name, 'UTF-8', 'B', "\r\n");
    }

    return preg_match('/[^\x20-\x7E]/', $name) ? '' : $name;
}

function email_encoded_header(string $value): string
{
    $value = email_header_value($value);

    if ($value === '') {
        return '';
    }

    if (function_exists('mb_encode_mimeheader')) {
        return mb_encode_mimeheader($value, 'UTF-8', 'B', "\r\n");
    }

    return preg_match('/[^\x20-\x7E]/', $value) ? '' : $value;
}

function email_address_header(?string $name, string $email): string
{
    $displayName = $name !== null ? email_encoded_header($name) : '';

    return ($displayName !== '' ? $displayName . ' ' : '') . '<' . $email . '>';
}

function email_normalized_body(string $body): string
{
    $body = str_replace(["\r\n", "\r"], "\n", $body);
    $body = str_replace("\n", "\r\n", $body);

    return preg_replace('/^\./m', '..', $body);
}

function email_smtp_read_response($socket): array
{
    $lines = [];

    while (!feof($socket)) {
        $line = fgets($socket, 515);

        if ($line === false) {
            break;
        }

        $lines[] = rtrim($line, "\r\n");

        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }

    $lastLine = end($lines);
    $code = is_string($lastLine) ? (int) substr($lastLine, 0, 3) : 0;

    return [$code, implode("\n", $lines)];
}

function email_smtp_command($socket, string $command, array $expectedCodes): array
{
    if ($command !== '') {
        fwrite($socket, $command . "\r\n");
    }

    [$code, $response] = email_smtp_read_response($socket);

    if (!in_array($code, $expectedCodes, true)) {
        return [false, trim($response) !== '' ? $response : 'SMTP command failed: ' . $command];
    }

    return [true, $response];
}

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

function send_inventory_smtp_email(
    string $recipientEmail,
    ?string $recipientName,
    string $subject,
    string $body
): array {
    $host = email_smtp_host();

    if ($host === '') {
        return ['ok' => false, 'error' => 'SMTP host is missing. Add it in Website Control.'];
    }

    $senderEmail = email_sender_email();
    $senderName = email_sender_name();
    $replyTo = email_reply_to();
    $port = email_smtp_port();
    $encryption = email_smtp_encryption();
    $timeout = email_smtp_timeout();
    $remote = ($encryption === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $errno = 0;
    $errstr = '';
    $socket = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);

    if (!$socket) {
        return ['ok' => false, 'error' => trim($errstr) !== '' ? $errstr : 'Could not connect to SMTP server.'];
    }

    stream_set_timeout($socket, $timeout);

    try {
        [$code, $response] = email_smtp_read_response($socket);

        if ($code !== 220) {
            return ['ok' => false, 'error' => $response ?: 'SMTP server did not accept connection.'];
        }

        $helloHost = email_header_value((string) ($_SERVER['SERVER_NAME'] ?? 'inventory.ahmaddalao.com'));
        [$ok, $error] = email_smtp_command($socket, 'EHLO ' . ($helloHost !== '' ? $helloHost : 'inventory.ahmaddalao.com'), [250]);

        if (!$ok) {
            return ['ok' => false, 'error' => $error];
        }

        if ($encryption === 'tls') {
            [$ok, $error] = email_smtp_command($socket, 'STARTTLS', [220]);

            if (!$ok) {
                return ['ok' => false, 'error' => $error];
            }

            $cryptoEnabled = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);

            if ($cryptoEnabled !== true) {
                return ['ok' => false, 'error' => 'Could not start SMTP TLS encryption.'];
            }

            [$ok, $error] = email_smtp_command($socket, 'EHLO ' . ($helloHost !== '' ? $helloHost : 'inventory.ahmaddalao.com'), [250]);

            if (!$ok) {
                return ['ok' => false, 'error' => $error];
            }
        }

        $username = email_smtp_username();
        $password = email_smtp_password();

        if ($username !== '' || $password !== '') {
            if ($username === '' || $password === '') {
                return ['ok' => false, 'error' => 'SMTP username and password must both be filled.'];
            }

            [$ok, $error] = email_smtp_command($socket, 'AUTH LOGIN', [334]);

            if (!$ok) {
                return ['ok' => false, 'error' => $error];
            }

            [$ok, $error] = email_smtp_command($socket, base64_encode($username), [334]);

            if (!$ok) {
                return ['ok' => false, 'error' => $error];
            }

            [$ok, $error] = email_smtp_command($socket, base64_encode($password), [235]);

            if (!$ok) {
                return ['ok' => false, 'error' => $error];
            }
        }

        [$ok, $error] = email_smtp_command($socket, 'MAIL FROM:<' . $senderEmail . '>', [250]);

        if (!$ok) {
            return ['ok' => false, 'error' => $error];
        }

        [$ok, $error] = email_smtp_command($socket, 'RCPT TO:<' . $recipientEmail . '>', [250, 251]);

        if (!$ok) {
            return ['ok' => false, 'error' => $error];
        }

        [$ok, $error] = email_smtp_command($socket, 'DATA', [354]);

        if (!$ok) {
            return ['ok' => false, 'error' => $error];
        }

        $headers = [
            'Date: ' . date('r'),
            'From: ' . email_address_header($senderName, $senderEmail),
            'To: ' . email_address_header($recipientName, $recipientEmail),
            'Subject: ' . (email_encoded_header($subject) ?: $subject),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'X-Mailer: Inventory KONA',
        ];

        if ($replyTo !== '') {
            $headers[] = 'Reply-To: ' . $replyTo;
        }

        fwrite($socket, implode("\r\n", $headers) . "\r\n\r\n" . email_normalized_body($body) . "\r\n.\r\n");
        [$code, $response] = email_smtp_read_response($socket);

        if ($code !== 250) {
            return ['ok' => false, 'error' => $response ?: 'SMTP server rejected the message.'];
        }

        email_smtp_command($socket, 'QUIT', [221, 250]);

        return ['ok' => true, 'error' => null];
    } finally {
        if (is_resource($socket)) {
            fclose($socket);
        }
    }
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
