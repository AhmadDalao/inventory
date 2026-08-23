<?php
declare(strict_types=1);

$integrations = is_array($integrations ?? null) ? $integrations : [];
$globalEnabled = (bool) ($globalEnabled ?? false);
$plainKey = (string) ($plainKey ?? '');
$plainKeyIntegrationId = (int) ($plainKeyIntegrationId ?? 0);
$userId = (int) (Auth::user()['id'] ?? 0);
$isOwner = user_is_global_owner($userId);
?>
<section class="page-head wristband-page-head">
    <div class="page-head-copy">
        <p class="eyebrow">KONA booking connection</p>
        <h3 class="page-head-title"><?= ui_icon('settings') ?><span>Wristband Integrations</span></h3>
        <p class="page-head-subtitle">Connect check-in evidence by storage. Manual handover reconciliation always remains available.</p>
    </div>
</section>

<?php require __DIR__ . '/_nav.php'; ?>

<?php if ($plainKey !== ''): ?>
    <section class="panel wristband-key-panel" role="status">
        <div>
            <p class="eyebrow">Shown once</p>
            <h4>Copy The New API Key</h4>
            <p>This key belongs to integration #<?= number_format($plainKeyIntegrationId) ?>. Store it in the KONA management system now.</p>
        </div>
        <div class="wristband-key-copy">
            <input id="wristband-api-key" data-wristband-api-key type="text" readonly value="<?= e($plainKey) ?>" aria-label="New wristband API key">
            <button class="button primary" type="button" data-copy-wristband-key="#wristband-api-key"><?= ui_icon('copy_action') ?><span>Copy Key</span></button>
        </div>
    </section>
<?php endif; ?>

<section class="panel wristband-global-control">
    <div>
        <p class="eyebrow">Global safety switch</p>
        <h4><?= $globalEnabled ? 'API Audit Enabled' : 'Manual Only Globally' ?></h4>
        <p>Turning this off acknowledges API calls as paused. It does not alter codes, handovers, sessions, or stock.</p>
    </div>
    <?php if ($isOwner): ?>
        <form method="post" action="/wristbands/integrations/global">
            <?= csrf_field() ?>
            <input type="hidden" name="enabled" value="<?= $globalEnabled ? '0' : '1' ?>">
            <button class="button <?= $globalEnabled ? 'ghost danger' : 'primary' ?>" type="submit">
                <?= $globalEnabled ? 'Stop API Checking' : 'Enable API Audit' ?>
            </button>
        </form>
    <?php endif; ?>
</section>

<section class="panel wristband-api-contract">
    <div>
        <p class="eyebrow">Check-in endpoint</p>
        <h4><code>POST /api/v1/integrations/kona/wristband-checkins</code></h4>
        <p>Authenticate with <code>Authorization: Bearer &lt;api-key&gt;</code>. HTTPS is mandatory.</p>
    </div>
    <pre><code>{
  "code": "AB12CD34EF56GH78",
  "scanned_at": "2026-08-22T19:30:00+03:00",
  "external_event_id": "optional-event-id"
}</code></pre>
</section>

<section class="wristband-integration-grid">
    <?php foreach ($integrations as $integration): ?>
        <?php
        $storageId = (int) $integration['storage_id'];
        $integrationId = (int) ($integration['id'] ?? 0);
        $canControl = $isOwner || storage_is_owned_by_user($storageId, $userId);
        $enabled = $integrationId > 0 && (int) ($integration['enabled'] ?? 0) === 1;
        ?>
        <article class="panel wristband-integration-card">
            <header>
                <div>
                    <p class="eyebrow"><?= e(storage_type_label((string) $integration['storage_type'])) ?></p>
                    <h4><?= e((string) $integration['storage_name']) ?></h4>
                </div>
                <span class="status-pill <?= $enabled ? 'pill-active' : 'pill-muted' ?>"><?= $enabled ? 'Enabled' : 'Disabled' ?></span>
            </header>

            <?php if (!empty($integration['active_session_id'])): ?>
                <a class="wristband-active-session" href="/handovers/<?= (int) $integration['handover_id'] ?>">
                    <span>Active session</span>
                    <strong><?= e((string) $integration['session_number']) ?></strong>
                    <small><?= e((string) $integration['handover_number']) ?> · <?= e(ucfirst((string) $integration['session_status'])) ?></small>
                </a>
            <?php endif; ?>

            <?php if ($canControl): ?>
                <form method="post" action="/wristbands/integrations/storage/<?= $storageId ?>" class="wristband-integration-form">
                    <?= csrf_field() ?>
                    <label class="field">
                        <span>Connection Name</span>
                        <input type="text" name="name" value="<?= e((string) ($integration['name'] ?? 'KONA wristband check-in')) ?>" required>
                    </label>
                    <label class="field">
                        <span>IP Allowlist (Optional)</span>
                        <textarea name="ip_allowlist" rows="3" placeholder="One IP or CIDR per line"><?= e((string) ($integration['ip_allowlist'] ?? '')) ?></textarea>
                        <small>Blank allows any HTTPS client holding the key.</small>
                    </label>
                    <label class="wristband-toggle-row">
                        <input type="hidden" name="enabled" value="0">
                        <input type="checkbox" name="enabled" value="1"<?= $enabled ? ' checked' : '' ?>>
                        <span><strong>Enable API Audit for this storage</strong><small>Disabled calls are logged as paused and do not consume codes.</small></span>
                    </label>
                    <button class="button primary" type="submit">Save Integration</button>
                </form>
            <?php else: ?>
                <p class="muted">Only the owner or a co-owner of this storage can change its integration.</p>
            <?php endif; ?>

            <footer>
                <span>Key: <?= $integrationId > 0 && !empty($integration['api_key_prefix']) ? e((string) $integration['api_key_prefix']) . '…' : 'Not created' ?></span>
                <?php if ($isOwner && $integrationId > 0): ?>
                    <form method="post" action="/wristbands/integrations/<?= $integrationId ?>/rotate">
                        <?= csrf_field() ?>
                        <button class="button ghost" type="submit" data-confirm="Rotate this key? The previous key will stop working immediately."><?= $integration['api_key_prefix'] ? 'Rotate Key' : 'Create API Key' ?></button>
                    </form>
                <?php endif; ?>
            </footer>
        </article>
    <?php endforeach; ?>
</section>
