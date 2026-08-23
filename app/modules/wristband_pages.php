<?php
declare(strict_types=1);

function wristband_tracking_items(): array
{
    return Database::fetchAll(
        'SELECT id, name, sku, image_path, unit, current_quantity
         FROM items
         WHERE is_active = 1
           AND external_qr_tracking_enabled = 1 AND measurement_dimension = "count"
         ORDER BY name ASC, id ASC'
    );
}

function wristband_session_rows(): array
{
    return Database::fetchAll(
        'SELECT session.*, handover.id AS handover_id, handover.handover_number, handover.status AS handover_status,
                storage.name AS storage_name, integration.name AS integration_name,
                starter.name AS started_by_name,
                COALESCE(SUM(event.status = "accepted"), 0) AS accepted_events,
                COALESCE(SUM(event.status IN ("paused", "unknown_code", "inactive_session", "item_not_eligible", "wrong_handover")), 0) AS unresolved_events
         FROM wristband_sessions session
         INNER JOIN handovers handover ON handover.id = session.handover_id
         INNER JOIN storages storage ON storage.id = session.storage_id
         INNER JOIN wristband_integrations integration ON integration.id = session.integration_id
         LEFT JOIN users starter ON starter.id = session.started_by
         LEFT JOIN wristband_events event ON event.session_id = session.id AND event.resolved_at IS NULL
         GROUP BY session.id
         ORDER BY FIELD(session.status, "active", "paused", "manual_only", "closed"), session.id DESC
         LIMIT 300'
    );
}

function wristband_exception_rows(bool $unresolvedOnly = true): array
{
    $where = $unresolvedOnly
        ? 'WHERE event.status IN ("paused", "unknown_code", "inactive_session", "item_not_eligible", "wrong_handover")
             AND event.resolved_at IS NULL'
        : '';

    return Database::fetchAll(
        'SELECT event.*, integration.name AS integration_name, integration.storage_id, storage.name AS storage_name,
                session.session_number, session.status AS session_status,
                handover.id AS handover_id, handover.handover_number, item.name AS item_name,
                resolver.name AS resolved_by_name
         FROM wristband_events event
         INNER JOIN wristband_integrations integration ON integration.id = event.integration_id
         INNER JOIN storages storage ON storage.id = integration.storage_id
         LEFT JOIN wristband_sessions session ON session.id = event.session_id
         LEFT JOIN handovers handover ON handover.id = event.handover_id
         LEFT JOIN items item ON item.id = event.item_id
         LEFT JOIN users resolver ON resolver.id = event.resolved_by
         ' . $where . '
         ORDER BY event.id DESC
         LIMIT 500'
    );
}

function wristband_integration_rows(): array
{
    return Database::fetchAll(
        'SELECT storage.id AS storage_id, storage.name AS storage_name, storage.storage_type AS storage_type,
                integration.id, integration.name, integration.enabled, integration.api_key_prefix,
                integration.ip_allowlist, integration.last_rotated_at,
                session.id AS active_session_id, session.session_number, session.status AS session_status,
                handover.id AS handover_id, handover.handover_number
         FROM storages storage
         LEFT JOIN wristband_integrations integration ON integration.storage_id = storage.id
         LEFT JOIN wristband_sessions session
           ON session.integration_id = integration.id AND session.status IN ("active", "paused")
         LEFT JOIN handovers handover ON handover.id = session.handover_id
         WHERE storage.is_active = 1
         ORDER BY storage.name ASC, storage.id ASC'
    );
}

function handle_wristband_codes_page(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('wristbands.view');
    $filters = wristband_registry_filters();
    View::render('wristbands/index', [
        'title' => 'Wristband Codes',
        'filters' => $filters,
        'rows' => wristband_registry_rows($filters),
        'counts' => wristband_registry_counts(),
        'items' => wristband_tracking_items(),
        'itemSummary' => wristband_item_registry_summary(),
    ]);
}

function handle_wristband_imports_page(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('wristbands.view');
    View::render('wristbands/imports', [
        'title' => 'Wristband Imports',
        'imports' => wristband_import_history(),
        'storages' => wristband_import_visible_storages((int) (Auth::user()['id'] ?? 0)),
        'canEnableTracking' => Auth::hasPermission('wristbands.import') && Auth::hasPermission('items.edit'),
    ]);
}

function handle_wristband_sessions_page(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('wristbands.sessions');
    View::render('wristbands/sessions', [
        'title' => 'Wristband Sessions',
        'sessions' => wristband_session_rows(),
    ]);
}

function handle_wristband_exceptions_page(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('wristbands.exceptions');
    $unresolvedOnly = (string) ($_GET['scope'] ?? 'unresolved') !== 'all';
    View::render('wristbands/exceptions', [
        'title' => 'Wristband Exceptions',
        'rows' => wristband_exception_rows($unresolvedOnly),
        'unresolvedOnly' => $unresolvedOnly,
    ]);
}

function handle_wristband_integrations_page(): void
{
    app_ready_or_redirect();
    Auth::requirePermission('wristbands.integrations');
    $plainKey = (string) ($_SESSION['_wristband_api_key'] ?? '');
    $plainKeyIntegrationId = (int) ($_SESSION['_wristband_api_key_integration_id'] ?? 0);
    unset($_SESSION['_wristband_api_key']);
    unset($_SESSION['_wristband_api_key_integration_id']);
    View::render('wristbands/integrations', [
        'title' => 'Wristband Integrations',
        'integrations' => wristband_integration_rows(),
        'globalEnabled' => wristband_api_enabled(),
        'plainKey' => $plainKey,
        'plainKeyIntegrationId' => $plainKeyIntegrationId,
    ]);
}
