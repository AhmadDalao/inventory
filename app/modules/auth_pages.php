<?php
declare(strict_types=1);

function handle_setup_page(): void
{
    $status = Installer::status();

    if ($status['installed']) {
        redirect('/login');
    }

    View::render('auth/setup', [
        'title' => 'Install Inventory HQ',
        'authPage' => true,
        'status' => $status,
    ]);
}

function handle_login_page(): void
{
    if (!app_installed()) {
        redirect('/setup');
    }

    if (Auth::check()) {
        redirect('/dashboard');
    }

    View::render('auth/login', [
        'title' => 'Login',
        'authPage' => true,
    ]);
}

function handle_forgot_password_page(): void
{
    if (!app_installed()) {
        redirect('/setup');
    }

    if (Auth::check()) {
        redirect('/dashboard');
    }

    View::render('auth/forgot_password', [
        'title' => 'Forgot Password',
        'authPage' => true,
    ]);
}

function handle_reset_password_page(array $params): void
{
    if (!app_installed()) {
        redirect('/setup');
    }

    if (Auth::check()) {
        redirect('/dashboard');
    }

    $token = (string) ($params['token'] ?? '');
    $resetRecord = find_valid_password_reset_token($token);

    View::render('auth/reset_password', [
        'title' => 'Reset Password',
        'authPage' => true,
        'token' => $token,
        'resetRecord' => $resetRecord,
    ]);
}
