<?php
declare(strict_types=1);

$authPage = $authPage ?? false;
$appName = site_setting('app.name', (string) app_config('app.name'));
$pageTitle = $title ?? $appName;
$flashes = consume_flashes();
$currentUser = (!$authPage && app_installed()) ? Auth::user() : null;
$notificationItems = $currentUser ? latest_notifications_for_user((int) $currentUser['id'], 6) : [];
$notificationUnread = $currentUser ? notification_unread_count((int) $currentUser['id']) : 0;
$bodyClasses = ($authPage ? 'auth-shell' : 'app-shell') . ' ' . ui_theme_class();

if (brand_custom_logo_asset() !== null) {
    $bodyClasses .= ' has-custom-logo';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> | <?= e($appName) ?></title>
    <link rel="stylesheet" href="<?= e(asset_url('app.css')) ?>">
</head>
<body class="<?= e($bodyClasses) ?>" data-user-role="<?= e((string) ($currentUser['role'] ?? 'guest')) ?>" data-user-id="<?= e((string) ($currentUser['id'] ?? '')) ?>">
<?php if ($authPage): ?>
    <main class="auth-wrap">
        <?php if ($flashes !== []): ?>
            <section class="flash-stack auth-flashes">
                <?php foreach ($flashes as $flashMessage): ?>
                    <div class="flash flash-<?= e($flashMessage['type']) ?>"><?= e($flashMessage['message']) ?></div>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>
        <?= $content ?>
    </main>
<?php else: ?>
    <a class="skip-link" href="#main-content">Skip to content</a>
    <div class="shell" data-shell>
        <aside class="sidebar" data-sidebar>
            <div class="sidebar-head">
                <div class="brand-block">
                    <a class="brand-mark" href="<?= e(url('/dashboard')) ?>"><?= e(site_brand_mark()) ?></a>
                    <div>
                        <p class="eyebrow"><?= e(site_setting('brand.eyebrow', 'Inventory Control')) ?></p>
                        <img class="brand-logo-official" src="<?= e(brand_logo_url()) ?>" alt="<?= e(site_brand_word()) ?>">
                        <h1><?= e(site_brand_word()) ?></h1>
                    </div>
                </div>
                <button class="sidebar-toggle" type="button" data-menu-toggle aria-label="Toggle sidebar"><?= ui_icon('back') ?></button>
            </div>

            <nav class="nav-links" aria-label="Main navigation">
                <?php if (Auth::hasPermission('dashboard.view')): ?>
                    <a class="nav-link <?= active_route('/dashboard') ?>" href="<?= e(url('/dashboard')) ?>"><?= ui_icon('dashboard') ?><span><?= e(site_setting('nav.dashboard', 'Dashboard')) ?></span></a>
                <?php endif; ?>
                <?php if (!Auth::isStaff() && Auth::hasPermission('storages.view')): ?>
                    <a class="nav-link <?= active_route('/storages', true) ?>" href="<?= e(url('/storages')) ?>"><?= ui_icon('storages') ?><span><?= e(site_setting('nav.storages', 'Storages')) ?></span></a>
                <?php endif; ?>
                <?php if (!Auth::isStaff() && Auth::hasPermission('items.view')): ?>
                    <a class="nav-link <?= active_route('/items', true) ?>" href="<?= e(url('/items')) ?>"><?= ui_icon('items') ?><span><?= e(site_setting('nav.items', 'Items')) ?></span></a>
                <?php endif; ?>
                <?php if (Auth::hasPermission('assets.view')): ?>
                    <a class="nav-link <?= active_route('/company-assets', true) ?>" href="<?= e(url('/company-assets')) ?>"><?= ui_icon('assets') ?><span><?= e(site_setting('nav.assets', 'Assets')) ?></span></a>
                <?php endif; ?>
                <?php if (!Auth::isStaff() && Auth::hasPermission('movements.view')): ?>
                    <a class="nav-link <?= active_route('/movements') ?>" href="<?= e(url('/movements')) ?>"><?= ui_icon('movements') ?><span><?= e(site_setting('nav.movements', 'Movement Log')) ?></span></a>
                <?php endif; ?>
                <?php if (!Auth::isStaff() && Auth::hasPermission('items.view')): ?>
                    <a class="nav-link <?= active_route('/scan') ?>" href="<?= e(url('/scan')) ?>"><?= ui_icon('scan') ?><span><?= e(site_setting('nav.scan', 'Scan Center')) ?></span></a>
                <?php endif; ?>
                <?php if (Auth::hasPermission('requests.view')): ?>
                    <a class="nav-link <?= active_route('/requests', true) ?>" href="<?= e(url('/requests')) ?>"><?= ui_icon('requests') ?><span><?= e(site_setting('nav.requests', 'Requests')) ?></span></a>
                <?php endif; ?>
                <?php if (Auth::hasPermission('handovers.view')): ?>
                    <a class="nav-link <?= active_route('/handovers', true) ?>" href="<?= e(url('/handovers')) ?>"><?= ui_icon('handover') ?><span><?= e(site_setting('nav.handovers', 'Handovers')) ?></span></a>
                <?php endif; ?>
                <?php if (!Auth::isStaff() && Auth::hasPermission('purchases.view')): ?>
                    <a class="nav-link <?= active_route('/purchases', true) ?>" href="<?= e(url('/purchases')) ?>"><?= ui_icon('purchases') ?><span><?= e(site_setting('nav.purchases', 'Purchases')) ?></span></a>
                <?php endif; ?>
                <?php if (!Auth::isStaff() && reports_can_access()): ?>
                    <a class="nav-link <?= active_route('/reports') ?>" href="<?= e(url('/reports')) ?>"><?= ui_icon('reports') ?><span><?= e(site_setting('nav.reports', 'Reports')) ?></span></a>
                <?php endif; ?>
                <?php if (file_library_can_access($currentUser)): ?>
                    <a class="nav-link <?= active_route('/files', true) ?>" href="<?= e(url('/files')) ?>"><?= ui_icon('files') ?><span><?= e(site_setting('nav.files', 'Files')) ?></span></a>
                <?php endif; ?>
                <a class="nav-link <?= active_route('/documentation') ?>" href="<?= e(url('/documentation')) ?>"><?= ui_icon('documentation') ?><span><?= e(site_setting('nav.documentation', 'Documentation')) ?></span></a>
                <?php if (!Auth::isStaff() && Auth::hasPermission('stocktakes.view')): ?>
                    <a class="nav-link <?= active_route('/stocktakes', true) ?>" href="<?= e(url('/stocktakes')) ?>"><?= ui_icon('stocktakes') ?><span><?= e(site_setting('nav.stocktakes', 'Stocktakes')) ?></span></a>
                <?php endif; ?>
                <?php if (!Auth::isStaff() && Auth::hasPermission('suppliers.view')): ?>
                    <a class="nav-link <?= active_route('/suppliers', true) ?>" href="<?= e(url('/suppliers')) ?>"><?= ui_icon('supplier') ?><span><?= e(site_setting('nav.suppliers', 'Suppliers')) ?></span></a>
                <?php endif; ?>
                <?php if (!Auth::isStaff() && Auth::hasPermission('reorder.view')): ?>
                    <a class="nav-link <?= active_route('/reorder') ?>" href="<?= e(url('/reorder')) ?>"><?= ui_icon('reorder') ?><span><?= e(site_setting('nav.reorder', 'Reorder')) ?></span></a>
                <?php endif; ?>
                <?php if (!Auth::isStaff() && Auth::hasPermission('labels.view')): ?>
                    <a class="nav-link <?= active_route('/labels') ?>" href="<?= e(url('/labels')) ?>"><?= ui_icon('labels') ?><span><?= e(site_setting('nav.labels', 'Labels')) ?></span></a>
                <?php endif; ?>
                <?php if (!Auth::isStaff() && Auth::hasPermission('users.view')): ?>
                    <a class="nav-link <?= active_route('/users', true) ?>" href="<?= e(url('/users')) ?>"><?= ui_icon('users') ?><span><?= e(site_setting('nav.users', 'Admins')) ?></span></a>
                <?php endif; ?>
                <?php if (!Auth::isStaff() && Auth::hasPermission('audit.view')): ?>
                    <a class="nav-link <?= active_route('/audit-log') ?>" href="<?= e(url('/audit-log')) ?>"><?= ui_icon('audit') ?><span><?= e(site_setting('nav.audit', 'Audit Log')) ?></span></a>
                <?php endif; ?>
                <?php if (!Auth::isStaff() && Auth::hasPermission('email_logs.view')): ?>
                    <a class="nav-link <?= active_route('/email-logs') ?>" href="<?= e(url('/email-logs')) ?>"><?= ui_icon('notification') ?><span><?= e(site_setting('nav.email_logs', 'Email Logs')) ?></span></a>
                <?php endif; ?>
                <?php if (!Auth::isStaff() && Auth::hasPermission('settings.view')): ?>
                    <a class="nav-link <?= active_route('/settings/site') ?>" href="<?= e(url('/settings/site')) ?>"><?= ui_icon('settings') ?><span><?= e(site_setting('nav.settings', 'Website Control')) ?></span></a>
                <?php endif; ?>
            </nav>

        </aside>
        <div class="sidebar-backdrop" data-sidebar-backdrop></div>

        <div class="main-panel">
            <header class="topbar">
                <button class="menu-button" type="button" data-menu-toggle aria-label="Open navigation"><?= ui_icon('menu') ?><span>Menu</span></button>
                <div class="topbar-title">
                    <p class="eyebrow"><?= e(site_setting('topbar.eyebrow', 'Live stock metrics')) ?></p>
                    <h2><?= e($pageTitle) ?></h2>
                </div>
                <?php if ($currentUser): ?>
                    <form class="global-search" action="<?= e(url('/global-search')) ?>" method="get" role="search" data-global-search data-global-search-url="<?= e(url('/global-search')) ?>">
                        <label>
                            <span class="sr-only">Global search</span>
                            <?= ui_icon('search') ?>
                            <input type="search" name="q" autocomplete="off" placeholder="Search inventory..." data-global-search-input>
                        </label>
                        <div class="global-search-panel" data-global-search-panel hidden>
                            <p class="global-search-hint" data-global-search-status>Type at least 2 characters.</p>
                            <div class="global-search-results" data-global-search-results></div>
                        </div>
                    </form>
                    <details class="notification-menu" data-notification-feed data-feed-url="<?= e(url('/notifications/feed')) ?>">
                        <summary class="notification-toggle">
                            <?= ui_icon('notification') ?>
                            <span>Notifications</span>
                            <?php if ($notificationUnread > 0): ?>
                                <span class="notification-badge" data-notification-badge><?= e((string) $notificationUnread) ?></span>
                            <?php endif; ?>
                        </summary>
                        <div class="notification-panel" data-notification-panel>
                            <?php if ($notificationItems === []): ?>
                                <p class="empty-state" data-notification-empty>No notifications yet.</p>
                            <?php else: ?>
                                <div data-notification-items>
                                    <?php foreach ($notificationItems as $notification): ?>
                                        <a class="notification-row" href="<?= e((string) ($notification['action_url'] ?: '#')) ?>" data-notification-id="<?= e((string) $notification['id']) ?>">
                                            <div class="notification-row-copy">
                                                <strong><?= e((string) $notification['title']) ?></strong>
                                                <?php if (!empty($notification['actor_name'])): ?>
                                                    <span class="tiny-copy">By <?= e((string) $notification['actor_name']) ?></span>
                                                <?php endif; ?>
                                                <?php if (!empty($notification['message'])): ?>
                                                    <p><?= e((string) $notification['message']) ?></p>
                                                <?php endif; ?>
                                                <span class="tiny-copy"><?= e(format_datetime_display((string) $notification['created_at'])) ?></span>
                                            </div>
                                            <?php if (empty($notification['read_at'])): ?>
                                                <span class="notification-status-dot" aria-label="Unread notification"></span>
                                            <?php endif; ?>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <div class="notification-panel-footer">
                                <div class="notification-sound-controls" aria-label="Notification sound controls">
                                    <button class="ghost-button" type="button" data-notification-sound-toggle aria-pressed="true">Sound On</button>
                                    <button class="ghost-button" type="button" data-notification-sound-test>Test</button>
                                </div>
                                <a class="ghost-button" href="<?= e(url('/notifications')) ?>"><?= ui_icon('notification') ?><span>View All Notifications</span></a>
                            </div>
                        </div>
                    </details>
                    <details class="topbar-user-menu">
                        <summary class="topbar-user" aria-label="Open account menu">
                            <span class="topbar-user-avatar"><?= e(user_initials($currentUser['name'] ?? '')) ?></span>
                            <span class="topbar-user-copy">
                                <strong><?= e((string) ($currentUser['name'] ?? '')) ?></strong>
                                <small><?= e(user_position_label($currentUser['position'] ?? '', (string) ($currentUser['role'] ?? ''))) ?></small>
                            </span>
                            <span class="topbar-user-caret" aria-hidden="true">v</span>
                        </summary>
                        <div class="topbar-user-panel">
                            <div class="topbar-user-panel-head">
                                <span class="topbar-user-avatar"><?= e(user_initials($currentUser['name'] ?? '')) ?></span>
                                <div>
                                    <strong><?= e((string) ($currentUser['name'] ?? '')) ?></strong>
                                    <span><?= e(user_position_label($currentUser['position'] ?? '', (string) ($currentUser['role'] ?? ''))) ?></span>
                                    <span class="role-chip"><?= e(user_role_label((string) ($currentUser['role'] ?? ''))) ?></span>
                                </div>
                            </div>
                            <div class="topbar-user-panel-links">
                                <a href="<?= e(url('/dashboard')) ?>"><?= ui_icon('dashboard') ?><span>Dashboard</span></a>
                                <?php if (Auth::hasPermission('users.view')): ?>
                                    <a href="<?= e(url('/users')) ?>"><?= ui_icon('users') ?><span>Admins & Permissions</span></a>
                                <?php endif; ?>
                                <?php if (Auth::hasPermission('email_logs.view')): ?>
                                    <a href="<?= e(url('/email-logs')) ?>"><?= ui_icon('notification') ?><span>Email Logs</span></a>
                                <?php endif; ?>
                                <a href="<?= e(url('/notifications')) ?>"><?= ui_icon('notification') ?><span>All Notifications</span></a>
                            </div>
                            <form method="post" action="<?= e(url('/logout')) ?>">
                                <?= csrf_field() ?>
                                <button class="topbar-logout-button" type="submit"><?= ui_icon('back') ?><span>Logout</span></button>
                            </form>
                        </div>
                    </details>
                <?php endif; ?>
            </header>

            <main class="content" id="main-content" tabindex="-1">
                <?php if ($flashes !== []): ?>
                    <section class="flash-stack">
                        <?php foreach ($flashes as $flashMessage): ?>
                            <div class="flash flash-<?= e($flashMessage['type']) ?>"><?= e($flashMessage['message']) ?></div>
                        <?php endforeach; ?>
                    </section>
                <?php endif; ?>

                <?= $content ?>
            </main>
        </div>
    </div>

    <div class="image-lightbox" data-image-lightbox hidden>
        <button class="image-lightbox-close" type="button" data-image-lightbox-close aria-label="Close image">Close</button>
        <div class="image-lightbox-backdrop" data-image-lightbox-close></div>
        <figure class="image-lightbox-dialog">
            <img class="image-lightbox-image" src="" alt="" data-image-lightbox-image>
            <figcaption class="image-lightbox-caption" data-image-lightbox-caption hidden></figcaption>
        </figure>
    </div>
<?php endif; ?>

<script src="<?= e(asset_url('app.js')) ?>" defer></script>
</body>
</html>
<?php consume_old_input(); ?>
