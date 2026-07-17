<?php
declare(strict_types=1);

// Notification page and feed handlers.

function handle_notifications_feed(): void
{
    app_ready_or_redirect();
    Auth::requireLogin();

    $user = Auth::user();

    if (!$user) {
        json_response([
            'ok' => false,
            'message' => 'Not authenticated.',
        ], 401);
    }

    json_response(array_merge([
        'ok' => true,
    ], notification_feed_payload((int) $user['id'], 8)));
}

function handle_notifications_index(): void
{
    app_ready_or_redirect();
    Auth::requireLogin();

    $user = Auth::user();

    if (!$user) {
        redirect('/login');
    }

    $filters = notification_filters();
    $userId = (int) $user['id'];

    View::render('notifications/index', [
        'title' => 'Notifications',
        'filters' => $filters,
        'notifications' => notifications_for_user($userId, $filters),
        'typeOptions' => notification_type_options($userId),
        'unreadCount' => notification_unread_count($userId),
    ]);
}
