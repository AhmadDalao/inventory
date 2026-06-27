<?php
declare(strict_types=1);

$statusCode = (int) ($statusCode ?? 404);
$message = (string) ($message ?? 'Page not found.');
$actions = is_array($actions ?? null) ? $actions : [];
?>
<section class="error-page">
    <div class="error-hero-card">
        <div class="error-hero-copy">
            <span class="error-code"><?= e((string) $statusCode) ?></span>
            <p class="eyebrow">Route guard</p>
            <h1><?= e($title ?? 'Page Not Found') ?></h1>
            <p><?= e($message) ?></p>
        </div>

        <div class="error-route-card">
            <span><?= ui_icon('scan') ?></span>
            <strong>Nothing useful lives here.</strong>
            <p>The record may have been removed, archived, or the link is old. Use the buttons below instead of staying on a dead page.</p>
        </div>
    </div>

    <div class="error-actions">
        <?php foreach ($actions as $action): ?>
            <a class="<?= (($action['style'] ?? '') === 'primary') ? 'primary-button' : 'ghost-button' ?>" href="<?= e((string) $action['href']) ?>">
                <?= (($action['style'] ?? '') === 'primary') ? ui_icon('back') : ui_icon('dashboard') ?>
                <span><?= e((string) $action['label']) ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</section>
