<?php
declare(strict_types=1);

function handle_web_inventory_sync(): void
{
    app_ready_or_redirect();
    Auth::requireLogin();

    json_response([
        'ok' => true,
        'cursor' => inventory_latest_event_cursor(),
    ]);
}
