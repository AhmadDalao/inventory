<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/controllers.php';

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
$router->post('/logout', static function (): void {
    handle_logout_submit();
});

$router->get('/dashboard', static function (): void {
    handle_dashboard_page();
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
$router->post('/items/{id}/movements', static function (array $params): void {
    handle_item_movement_submit($params);
});

$router->get('/movements', static function (): void {
    handle_movements_index();
});
$router->get('/exports/items', static function (): void {
    handle_export_items();
});
$router->get('/exports/movements', static function (): void {
    handle_export_movements();
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
$router->post('/users/{id}/status', static function (array $params): void {
    handle_users_status_submit($params);
});

$router->dispatch(request_method(), request_path());
