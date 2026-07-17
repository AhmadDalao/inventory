<?php
declare(strict_types=1);

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
