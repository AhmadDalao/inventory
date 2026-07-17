<?php
declare(strict_types=1);

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
