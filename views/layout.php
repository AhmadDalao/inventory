<?php
declare(strict_types=1);

$authPage = $authPage ?? false;
$pageTitle = $title ?? app_config('app.name');
$flashes = consume_flashes();
$currentUser = (!$authPage && app_installed()) ? Auth::user() : null;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> | <?= e((string) app_config('app.name')) ?></title>
    <link rel="stylesheet" href="<?= e(asset_url('app.css')) ?>">
</head>
<body class="<?= $authPage ? 'auth-shell' : 'app-shell' ?>">
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
    <div class="shell" data-shell>
        <aside class="sidebar" data-sidebar>
            <div class="brand-block">
                <a class="brand-mark" href="<?= e(url('/dashboard')) ?>">IQ</a>
                <div>
                    <p class="eyebrow">Inventory Control</p>
                    <h1><?= e((string) app_config('app.name')) ?></h1>
                </div>
            </div>

            <nav class="nav-links">
                <a class="nav-link <?= active_route('/dashboard') ?>" href="<?= e(url('/dashboard')) ?>"><?= ui_icon('dashboard') ?><span>Dashboard</span></a>
                <a class="nav-link <?= active_route('/storages', true) ?>" href="<?= e(url('/storages')) ?>"><?= ui_icon('storages') ?><span>Storages</span></a>
                <a class="nav-link <?= active_route('/items', true) ?>" href="<?= e(url('/items')) ?>"><?= ui_icon('items') ?><span>Items</span></a>
                <a class="nav-link <?= active_route('/movements') ?>" href="<?= e(url('/movements')) ?>"><?= ui_icon('movements') ?><span>Movement Log</span></a>
                <?php if (Auth::isOwner()): ?>
                    <a class="nav-link <?= active_route('/users', true) ?>" href="<?= e(url('/users')) ?>"><?= ui_icon('users') ?><span>Admins</span></a>
                <?php endif; ?>
            </nav>

            <div class="sidebar-footer">
                <p class="muted-label">Signed in as</p>
                <strong><?= e($currentUser['name'] ?? '') ?></strong>
                <span class="role-chip"><?= e(strtoupper($currentUser['role'] ?? '')) ?></span>
                <form method="post" action="<?= e(url('/logout')) ?>">
                    <?= csrf_field() ?>
                    <button class="ghost-button full-width" type="submit">Logout</button>
                </form>
            </div>
        </aside>

        <div class="main-panel">
            <header class="topbar">
                <button class="menu-button" type="button" data-menu-toggle>Menu</button>
                <div>
                    <p class="eyebrow">Live stock metrics</p>
                    <h2><?= e($pageTitle) ?></h2>
                </div>
            </header>

            <main class="content">
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
