<?php
declare(strict_types=1);

function password_reset_token_hash(string $token): string
{
    return hash('sha256', $token);
}

function create_password_reset_token(array $user, ?int $requestedByUserId = null): string
{
    $token = bin2hex(random_bytes(32));

    Database::execute(
        'UPDATE password_reset_tokens
         SET used_at = NOW()
         WHERE user_id = :user_id
           AND used_at IS NULL',
        ['user_id' => (int) $user['id']]
    );

    Database::execute(
        'INSERT INTO password_reset_tokens (
            user_id,
            requested_by_user_id,
            token_hash,
            request_ip,
            user_agent,
            expires_at,
            used_at,
            created_at
         ) VALUES (
            :user_id,
            :requested_by_user_id,
            :token_hash,
            :request_ip,
            :user_agent,
            DATE_ADD(NOW(), INTERVAL 60 MINUTE),
            NULL,
            NOW()
         )',
        [
            'user_id' => (int) $user['id'],
            'requested_by_user_id' => $requestedByUserId,
            'token_hash' => password_reset_token_hash($token),
            'request_ip' => auth_request_ip(),
            'user_agent' => auth_request_user_agent() !== '' ? auth_request_user_agent() : null,
        ]
    );

    return $token;
}

function find_valid_password_reset_token(string $token): ?array
{
    if (strlen($token) < 32 || strlen($token) > 160) {
        return null;
    }

    return Database::fetch(
        'SELECT reset_token.*,
                users.name AS user_name,
                users.email AS user_email,
                users.is_active AS user_is_active
         FROM password_reset_tokens reset_token
         INNER JOIN users ON users.id = reset_token.user_id
         WHERE reset_token.token_hash = :token_hash
           AND reset_token.used_at IS NULL
           AND reset_token.expires_at >= NOW()
           AND users.is_active = 1
         LIMIT 1',
        ['token_hash' => password_reset_token_hash($token)]
    );
}

function send_password_reset_email(array $user, string $token, ?int $requestedByUserId = null): array
{
    $recipientEmail = (string) ($user['email'] ?? $user['user_email'] ?? '');
    $recipientName = (string) ($user['name'] ?? $user['user_name'] ?? '');
    $resetUrl = absolute_url('/reset-password/' . rawurlencode($token));
    $subject = 'Reset your Inventory KONA password';
    $body = implode("\n", [
        'Password reset requested for Inventory KONA.',
        '',
        'Open this link within 60 minutes:',
        $resetUrl,
        '',
        'If you did not request this, ignore this email. Your current password stays unchanged.',
    ]);

    if (!email_password_resets_enabled()) {
        record_email_delivery_log(
            'password_reset',
            $recipientEmail,
            $recipientName,
            $subject,
            'suppressed',
            'Password reset emails are disabled.',
            (int) ($user['id'] ?? $user['user_id'] ?? 0) ?: null,
            'user',
            (int) ($user['id'] ?? $user['user_id'] ?? 0) ?: null
        );

        return ['ok' => false, 'status' => 'suppressed', 'error' => 'Password reset emails are disabled.'];
    }

    return send_inventory_email(
        $recipientEmail,
        $recipientName,
        $subject,
        $body,
        'password_reset',
        (int) ($user['id'] ?? $user['user_id'] ?? 0) ?: null,
        'user',
        (int) ($user['id'] ?? $user['user_id'] ?? 0) ?: null
    );
}
