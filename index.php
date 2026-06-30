<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/controllers.php';
require __DIR__ . '/app/workflows.php';

$router = new Router();

$router->get('/', static function (): void {
    if (!app_installed()) {
        redirect('/setup');
    }

    if (Auth::check()) {
        redirect('/dashboard');
    }

    redirect('/login');
});

$router->get('/setup', static function (): void {
    handle_setup_page();
});
$router->post('/setup', static function (): void {
    handle_setup_submit();
});

$router->get('/login', static function (): void {
    handle_login_page();
});
$router->post('/login', static function (): void {
    handle_login_submit();
});
$router->get('/forgot-password', static function (): void {
    handle_forgot_password_page();
});
$router->post('/forgot-password', static function (): void {
    handle_forgot_password_submit();
});
$router->get('/reset-password/{token}', static function (array $params): void {
    handle_reset_password_page($params);
});
$router->post('/reset-password/{token}', static function (array $params): void {
    handle_reset_password_submit($params);
});
$router->post('/logout', static function (): void {
    handle_logout_submit();
});

$router->get('/dashboard', static function (): void {
    handle_dashboard_page();
});
$router->get('/notifications', static function (): void {
    handle_notifications_index();
});
$router->get('/notifications/feed', static function (): void {
    handle_notifications_feed();
});
$router->post('/notifications/read-all', static function (): void {
    handle_notifications_read_all_submit();
});
$router->get('/global-search', static function (): void {
    handle_global_search();
});
$router->get('/open/{reference}', static function (array $params): void {
    handle_workflow_reference_open($params);
});

$router->get('/storages', static function (): void {
    handle_storages_index();
});
$router->get('/storages/create', static function (): void {
    handle_storages_create_page();
});
$router->post('/storages/create', static function (): void {
    handle_storages_create_submit();
});
$router->get('/storages/{id}', static function (array $params): void {
    handle_storages_show($params);
});
$router->get('/storages/{id}/edit', static function (array $params): void {
    handle_storages_edit_page($params);
});
$router->post('/storages/{id}/edit', static function (array $params): void {
    handle_storages_edit_submit($params);
});
$router->post('/storages/{id}/status', static function (array $params): void {
    handle_storages_status_submit($params);
});

$router->get('/items', static function (): void {
    handle_items_index();
});
$router->get('/items/create', static function (): void {
    handle_items_create_page();
});
$router->post('/items/create', static function (): void {
    handle_items_create_submit();
});
$router->get('/items/{id}', static function (array $params): void {
    handle_items_show($params);
});
$router->get('/items/{id}/edit', static function (array $params): void {
    handle_items_edit_page($params);
});
$router->post('/items/{id}/edit', static function (array $params): void {
    handle_items_edit_submit($params);
});
$router->post('/items/{id}/status', static function (array $params): void {
    handle_items_status_submit($params);
});
$router->post('/items/{id}/package-presets', static function (array $params): void {
    handle_item_package_preset_save_submit($params);
});
$router->post('/items/{id}/package-presets/{preset_id}/delete', static function (array $params): void {
    handle_item_package_preset_delete_submit($params);
});
$router->post('/items/{id}/locations/{storage_id}/remove', static function (array $params): void {
    handle_item_location_remove_submit($params);
});
$router->post('/items/{id}/movements', static function (array $params): void {
    handle_item_movement_submit($params);
});

$router->get('/movements', static function (): void {
    handle_movements_index();
});
$router->get('/scan', static function (): void {
    handle_scan_index();
});
$router->get('/scan/lookup', static function (): void {
    handle_scan_lookup();
});
$router->get('/requests', static function (): void {
    handle_requests_index();
});
$router->get('/requests/create', static function (): void {
    handle_requests_create_page();
});
$router->post('/requests/create', static function (): void {
    handle_requests_create_submit();
});
$router->get('/requests/{id}', static function (array $params): void {
    handle_requests_show($params);
});
$router->post('/requests/{id}/submit', static function (array $params): void {
    handle_requests_submit_submit($params);
});
$router->post('/requests/{id}/approve', static function (array $params): void {
    handle_requests_approve_submit($params);
});
$router->post('/requests/{id}/reject', static function (array $params): void {
    handle_requests_reject_submit($params);
});
$router->post('/requests/{id}/receive', static function (array $params): void {
    handle_requests_receive_submit($params);
});
$router->post('/requests/{id}/confirm-receipt', static function (array $params): void {
    handle_requests_confirm_receipt_submit($params);
});
$router->post('/requests/{id}/cancel', static function (array $params): void {
    handle_requests_cancel_submit($params);
});
$router->post('/requests/{id}/recover', static function (array $params): void {
    handle_requests_recover_submit($params);
});
$router->post('/requests/{id}/void', static function (array $params): void {
    handle_requests_void_submit($params);
});
$router->get('/handovers', static function (): void {
    handle_handovers_index();
});
$router->get('/handovers/create', static function (): void {
    handle_handovers_create_page();
});
$router->post('/handovers/create', static function (): void {
    handle_handovers_create_submit();
});
$router->get('/handovers/{id}', static function (array $params): void {
    handle_handovers_show($params);
});
$router->post('/handovers/{id}/lines', static function (array $params): void {
    handle_handovers_lines_submit($params);
});
$router->post('/handovers/{id}/approve-request', static function (array $params): void {
    handle_handovers_approve_request_submit($params);
});
$router->post('/handovers/{id}/reject-request', static function (array $params): void {
    handle_handovers_reject_request_submit($params);
});
$router->post('/handovers/{id}/cancel', static function (array $params): void {
    handle_handovers_cancel_submit($params);
});
$router->post('/handovers/{id}/recover', static function (array $params): void {
    handle_handovers_recover_submit($params);
});
$router->post('/handovers/{id}/status-override', static function (array $params): void {
    handle_handovers_status_override_submit($params);
});
$router->post('/handovers/{id}/void', static function (array $params): void {
    handle_handovers_void_submit($params);
});
$router->post('/handovers/{id}/receive', static function (array $params): void {
    handle_handovers_receive_submit($params);
});
$router->post('/handovers/{id}/confirm-receipt', static function (array $params): void {
    handle_handovers_confirm_receipt_submit($params);
});
$router->post('/handovers/{id}/close', static function (array $params): void {
    handle_handovers_close_submit($params);
});
$router->post('/handovers/{id}/approve', static function (array $params): void {
    handle_handovers_approve_submit($params);
});
$router->get('/stocktakes', static function (): void {
    handle_stocktakes_index();
});
$router->get('/stocktakes/create', static function (): void {
    handle_stocktakes_create_page();
});
$router->post('/stocktakes/create', static function (): void {
    handle_stocktakes_create_submit();
});
$router->get('/stocktakes/{id}', static function (array $params): void {
    handle_stocktakes_show($params);
});
$router->post('/stocktakes/{id}/count', static function (array $params): void {
    handle_stocktakes_count_submit($params);
});
$router->post('/stocktakes/{id}/approve', static function (array $params): void {
    handle_stocktakes_approve_submit($params);
});
$router->post('/stocktakes/{id}/cancel', static function (array $params): void {
    handle_stocktakes_cancel_submit($params);
});
$router->get('/purchases', static function (): void {
    handle_purchases_index();
});
$router->get('/purchases/create', static function (): void {
    handle_purchases_create_page();
});
$router->post('/purchases/create', static function (): void {
    handle_purchases_create_submit();
});
$router->get('/purchases/import', static function (): void {
    handle_purchases_import_page();
});
$router->post('/purchases/import/drafts', static function (): void {
    handle_purchases_import_drafts_submit();
});
$router->post('/purchases/ocr-preview', static function (): void {
    handle_purchase_ocr_preview_submit();
});
$router->get('/purchases/{id}', static function (array $params): void {
    handle_purchases_show($params);
});
$router->get('/purchases/{id}/edit', static function (array $params): void {
    handle_purchases_edit_page($params);
});
$router->post('/purchases/{id}/edit', static function (array $params): void {
    handle_purchases_edit_submit($params);
});
$router->post('/purchases/{id}/submit', static function (array $params): void {
    handle_purchases_submit_submit($params);
});
$router->post('/purchases/{id}/approve', static function (array $params): void {
    handle_purchases_approve_submit($params);
});
$router->post('/purchases/{id}/reject', static function (array $params): void {
    handle_purchases_reject_submit($params);
});
$router->post('/purchases/{id}/receive', static function (array $params): void {
    handle_purchases_receive_submit($params);
});
$router->post('/purchases/{id}/confirm-receipt', static function (array $params): void {
    handle_purchases_confirm_receipt_submit($params);
});
$router->post('/purchases/{id}/cancel', static function (array $params): void {
    handle_purchases_cancel_submit($params);
});
$router->get('/purchases/documents/{id}/download', static function (array $params): void {
    handle_purchase_document_download($params);
});
$router->post('/purchases/documents/{id}/delete', static function (array $params): void {
    handle_purchase_document_delete_submit($params);
});
$router->get('/files', static function (): void {
    handle_files_index();
});
$router->get('/files/{id}/download', static function (array $params): void {
    handle_file_download($params);
});
$router->get('/workflow-documents/{id}/download', static function (array $params): void {
    handle_workflow_document_download($params);
});
$router->get('/documentation', static function (): void {
    handle_documentation_index();
});
$router->get('/suppliers', static function (): void {
    handle_suppliers_index();
});
$router->get('/suppliers/create', static function (): void {
    handle_suppliers_create_page();
});
$router->post('/suppliers/create', static function (): void {
    handle_suppliers_create_submit();
});
$router->get('/suppliers/{id}', static function (array $params): void {
    handle_suppliers_show($params);
});
$router->get('/suppliers/{id}/edit', static function (array $params): void {
    handle_suppliers_edit_page($params);
});
$router->post('/suppliers/{id}/edit', static function (array $params): void {
    handle_suppliers_edit_submit($params);
});
$router->post('/suppliers/{id}/status', static function (array $params): void {
    handle_suppliers_status_submit($params);
});
$router->get('/reorder', static function (): void {
    handle_reorder_index();
});
$router->post('/reorder/create-purchase', static function (): void {
    handle_reorder_create_purchase_submit();
});
$router->get('/labels', static function (): void {
    handle_labels_index();
});
$router->get('/audit-log', static function (): void {
    handle_audit_index();
});
$router->get('/email-logs', static function (): void {
    handle_email_logs_index();
});
$router->get('/reports', static function (): void {
    handle_reports_index();
});
$router->get('/exports/items', static function (): void {
    handle_export_items();
});
$router->get('/exports/items.xlsx', static function (): void {
    handle_export_items_xlsx();
});
$router->get('/exports/movements', static function (): void {
    handle_export_movements();
});
$router->get('/exports/movements.xlsx', static function (): void {
    handle_export_movements_xlsx();
});
$router->get('/exports/daily-summary', static function (): void {
    handle_export_daily_summary();
});
$router->get('/exports/storages', static function (): void {
    handle_export_storages();
});
$router->get('/exports/storages.xlsx', static function (): void {
    handle_export_storages_xlsx();
});
$router->get('/exports/requests', static function (): void {
    handle_export_requests();
});
$router->get('/exports/handovers', static function (): void {
    handle_export_handovers();
});
$router->get('/exports/purchases', static function (): void {
    handle_export_purchases();
});
$router->get('/exports/files', static function (): void {
    handle_export_files();
});
$router->get('/exports/stocktakes', static function (): void {
    handle_export_stocktakes();
});
$router->get('/exports/suppliers', static function (): void {
    handle_export_suppliers();
});
$router->get('/exports/reorder', static function (): void {
    handle_export_reorder();
});
$router->get('/exports/audit', static function (): void {
    handle_export_audit();
});
$router->get('/exports/email-logs', static function (): void {
    handle_export_email_logs();
});
$router->get('/exports/users', static function (): void {
    handle_export_users();
});

$router->get('/users', static function (): void {
    handle_users_index();
});
$router->get('/users/create', static function (): void {
    handle_users_create_page();
});
$router->post('/users/create', static function (): void {
    handle_users_create_submit();
});
$router->get('/users/{id}/edit', static function (array $params): void {
    handle_users_edit_page($params);
});
$router->post('/users/{id}/edit', static function (array $params): void {
    handle_users_edit_submit($params);
});
$router->post('/users/{id}/send-reset', static function (array $params): void {
    handle_users_send_reset_submit($params);
});
$router->post('/users/{id}/status', static function (array $params): void {
    handle_users_status_submit($params);
});

$router->get('/settings/site', static function (): void {
    handle_site_settings_page();
});
$router->post('/settings/logo', static function (): void {
    handle_site_logo_submit();
});
$router->post('/settings/site', static function (): void {
    handle_site_settings_submit();
});
$router->post('/settings/email-test', static function (): void {
    handle_site_email_test_submit();
});

$router->dispatch(request_method(), request_path());
