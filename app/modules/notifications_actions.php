<?php
declare(strict_types=1);

// Notification action handlers.

function handle_notifications_read_all_submit(): void
{
    app_ready_or_redirect();
    Auth::requireLogin();
    verify_csrf();

    $user = Auth::user();

    if ($user) {
        mark_all_notifications_as_read((int) $user['id']);
    }

    flash('success', 'Notifications marked as read.');
    redirect('/notifications');
}
