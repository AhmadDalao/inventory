<?php
declare(strict_types=1);

function flash_errors(array $errors): void
{
    foreach ($errors as $error) {
        flash('danger', $error);
    }
}

function app_ready_or_redirect(): void
{
    if (!app_installed()) {
        redirect('/setup');
    }
}
