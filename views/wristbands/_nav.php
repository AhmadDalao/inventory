<?php
declare(strict_types=1);

$wristbandPath = request_path();
$wristbandTabs = [
    ['href' => '/wristbands', 'label' => 'Codes', 'permission' => 'wristbands.view'],
    ['href' => '/wristbands/imports', 'label' => 'Imports', 'permission' => 'wristbands.view'],
    ['href' => '/wristbands/sessions', 'label' => 'Sessions', 'permission' => 'wristbands.sessions'],
    ['href' => '/wristbands/exceptions', 'label' => 'Exceptions', 'permission' => 'wristbands.exceptions'],
    ['href' => '/wristbands/integrations', 'label' => 'Integrations', 'permission' => 'wristbands.integrations'],
];
?>
<nav class="wristband-tabs" aria-label="Wristband API Audit">
    <?php foreach ($wristbandTabs as $tab): ?>
        <?php if (!Auth::hasPermission($tab['permission'])) continue; ?>
        <?php
        $isActive = $tab['href'] === '/wristbands'
            ? $wristbandPath === '/wristbands'
            : str_starts_with($wristbandPath, $tab['href']);
        ?>
        <a class="wristband-tab<?= $isActive ? ' is-active' : '' ?>" href="<?= e($tab['href']) ?>">
            <?= e($tab['label']) ?>
        </a>
    <?php endforeach; ?>
</nav>
