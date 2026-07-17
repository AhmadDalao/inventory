<?php
declare(strict_types=1);

function email_header_value(string $value): string
{
    $value = str_replace(["\r", "\n"], ' ', trim($value));

    return preg_replace('/\s+/', ' ', $value) ?: '';
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
