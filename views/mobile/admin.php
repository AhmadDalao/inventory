<section class="page-head">
    <div class="page-head-copy">
        <p class="eyebrow">Field operations</p>
        <h3 class="page-head-title"><?= ui_icon('scan') ?><span>Mobile Access</span></h3>
        <p>Control who can use the app, which storages they can see, and what actions each device may submit.</p>
    </div>
</section>

<section class="panel settings-card">
    <div class="table-shell-head">
        <div class="table-heading"><strong>Application Gate</strong></div>
        <span class="pill <?= site_setting('mobile.enabled', '0') === '1' ? 'pill-active' : 'pill-muted' ?>"><?= site_setting('mobile.enabled', '0') === '1' ? 'Enabled' : 'Disabled' ?></span>
    </div>
    <form method="post" action="<?= e(url('/mobile-access/settings')) ?>" class="form-grid">
        <?= csrf_field() ?>
        <label class="checkbox-row"><input type="checkbox" name="enabled" value="1" <?= site_setting('mobile.enabled', '0') === '1' ? 'checked' : '' ?>><span>Enable mobile API globally</span></label>
        <label class="checkbox-row"><input type="checkbox" name="manual_restock_enabled" value="1" <?= site_setting('mobile.manual_restock_enabled', '0') === '1' ? 'checked' : '' ?>><span>Allow privileged direct restock</span></label>
        <label class="checkbox-row"><input type="checkbox" name="offline_drafts_enabled" value="1" <?= site_setting('mobile.offline_drafts_enabled', '1') === '1' ? 'checked' : '' ?>><span>Allow offline drafts</span></label>
        <label class="checkbox-row"><input type="checkbox" name="require_usage_proof" value="1" <?= site_setting('mobile.require_usage_proof', '0') === '1' ? 'checked' : '' ?>><span>Require proof for direct usage</span></label>
        <label><span>Minimum app version</span><input type="text" name="min_supported_version" value="<?= e(site_setting('mobile.min_supported_version', '1.0.0')) ?>" placeholder="1.0.0"></label>
        <button class="primary-button" type="submit">Save Mobile Settings</button>
    </form>
</section>

<section class="panel">
    <div class="table-shell-head">
        <div class="table-heading"><strong><?= ui_icon('users') ?><span>Employee Access</span></strong><span class="table-count-badge"><?= number_format(count($users)) ?></span></div>
        <p class="table-shell-copy">A web permission is not enough. Mobile must also be enabled here.</p>
    </div>
    <div class="settings-accordion-stack">
        <?php foreach ($users as $mobileUser): ?>
            <?php $assignedIds = array_map('intval', array_column(Database::fetchAll('SELECT storage_id FROM user_storage_assignments WHERE user_id = :id', ['id' => $mobileUser['id']]), 'storage_id')); ?>
            <details class="settings-accordion">
                <summary>
                    <span><strong><?= e($mobileUser['name']) ?></strong><small><?= e($mobileUser['email']) ?> · <?= e(user_position_label($mobileUser['position'] ?? '', $mobileUser['role'])) ?></small></span>
                    <span class="pill <?= (int) ($mobileUser['enabled'] ?? 0) === 1 || $mobileUser['role'] === 'owner' ? 'pill-active' : 'pill-muted' ?>"><?= (int) ($mobileUser['enabled'] ?? 0) === 1 || $mobileUser['role'] === 'owner' ? 'Mobile on' : 'Mobile off' ?></span>
                </summary>
                <form method="post" action="<?= e(url('/mobile-access/users/' . $mobileUser['id'])) ?>" class="settings-accordion-body">
                    <?= csrf_field() ?>
                    <div class="form-grid compact-grid">
                        <label class="checkbox-row"><input type="checkbox" name="enabled" value="1" <?= (int) ($mobileUser['enabled'] ?? 0) === 1 || $mobileUser['role'] === 'owner' ? 'checked' : '' ?>><span>Mobile access</span></label>
                        <?php foreach (['usage' => 'Report usage', 'restock' => 'Restock', 'transfer' => 'Storage transfer', 'handover' => 'Staff handover', 'custody' => 'Long-term custody'] as $key => $label): ?>
                            <label class="checkbox-row"><input type="checkbox" name="can_<?= e($key) ?>" value="1" <?= (int) ($mobileUser['can_' . $key] ?? 0) === 1 || $mobileUser['role'] === 'owner' ? 'checked' : '' ?>><span><?= e($label) ?></span></label>
                        <?php endforeach; ?>
                        <label class="checkbox-row"><input type="checkbox" name="direct_restock_enabled" value="1" <?= (int) ($mobileUser['direct_restock_enabled'] ?? 0) === 1 || $mobileUser['role'] === 'owner' ? 'checked' : '' ?>><span>Direct restock for this user</span></label>
                    </div>
                    <div class="form-grid compact-grid">
                        <fieldset>
                            <legend>Assigned storages</legend>
                            <?php foreach ($storages as $storage): ?>
                                <label class="checkbox-row"><input type="checkbox" name="storage_ids[]" value="<?= (int) $storage['id'] ?>" <?= in_array((int) $storage['id'], $assignedIds, true) || $mobileUser['role'] === 'owner' ? 'checked' : '' ?>><span><?= e($storage['name']) ?></span></label>
                            <?php endforeach; ?>
                        </fieldset>
                        <label><span>Default storage</span><select name="default_storage_id"><option value="0">No default</option><?php foreach ($storages as $storage): ?><option value="<?= (int) $storage['id'] ?>" <?= (int) ($mobileUser['default_storage_id'] ?? 0) === (int) $storage['id'] ? 'selected' : '' ?>><?= e($storage['name']) ?></option><?php endforeach; ?></select></label>
                    </div>
                    <button class="primary-button" type="submit">Save <?= e($mobileUser['name']) ?></button>
                </form>
            </details>
        <?php endforeach; ?>
    </div>
</section>

<section class="panel data-table-shell">
    <div class="table-shell-head"><div class="table-heading"><strong>Registered Devices</strong><span class="table-count-badge"><?= number_format(count($devices)) ?></span></div></div>
    <div class="table-wrap"><table class="data-table"><thead><tr><th>User</th><th>Device</th><th>Platform</th><th>Version</th><th>Last Seen</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach ($devices as $device): ?><tr><td><?= e($device['user_name']) ?></td><td><?= e($device['device_name'] ?: $device['device_uuid']) ?></td><td><?= e($device['platform']) ?></td><td><?= e($device['app_version']) ?></td><td><?= e($device['last_seen_at'] ?: 'Never') ?></td><td><span class="pill <?= $device['revoked_at'] ? 'pill-muted' : 'pill-active' ?>"><?= $device['revoked_at'] ? 'Revoked' : 'Active' ?></span></td><td><?php if (!$device['revoked_at']): ?><form method="post" action="<?= e(url('/mobile-access/devices/' . $device['id'] . '/revoke')) ?>"><?= csrf_field() ?><button class="ghost-button" type="submit" data-confirm="Revoke this device immediately?">Revoke</button></form><?php endif; ?></td></tr><?php endforeach; ?>
    <?php if ($devices === []): ?><tr><td colspan="7" class="empty-cell">No mobile devices registered.</td></tr><?php endif; ?>
    </tbody></table></div>
</section>

<section class="panel data-table-shell">
    <div class="table-shell-head"><div class="table-heading"><strong>Mobile Operation Log</strong><span class="table-count-badge"><?= number_format(count($operations)) ?></span></div></div>
    <div class="table-wrap"><table class="data-table"><thead><tr><th>When</th><th>User</th><th>Operation</th><th>Status</th><th>Entity</th><th>Error</th></tr></thead><tbody>
    <?php foreach ($operations as $operation): ?><tr><td><?= e($operation['created_at']) ?></td><td><?= e($operation['user_name']) ?></td><td><?= e($operation['operation_type']) ?></td><td><span class="pill <?= $operation['status'] === 'succeeded' ? 'pill-active' : ($operation['status'] === 'conflict' ? 'pill-warning' : 'pill-muted') ?>"><?= e($operation['status']) ?></span></td><td><?= e(trim((string) ($operation['entity_type'] ?? '') . ' ' . (string) ($operation['entity_id'] ?? ''))) ?></td><td><?= e($operation['error_message'] ?? '') ?></td></tr><?php endforeach; ?>
    <?php if ($operations === []): ?><tr><td colspan="6" class="empty-cell">No mobile operations yet.</td></tr><?php endif; ?>
    </tbody></table></div>
</section>
