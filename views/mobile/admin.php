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
    <form method="post" action="<?= e(url('/mobile-access/settings')) ?>" class="settings-accordion-body">
        <?= csrf_field() ?>
        <div class="form-grid compact-grid">
            <label class="checkbox-row"><input type="checkbox" name="enabled" value="1" <?= site_setting('mobile.enabled', '0') === '1' ? 'checked' : '' ?>><span>Enable mobile API globally</span></label>
            <label class="checkbox-row"><input type="checkbox" name="manual_restock_enabled" value="1" <?= site_setting('mobile.manual_restock_enabled', '0') === '1' ? 'checked' : '' ?>><span>Allow privileged direct restock</span></label>
            <label class="checkbox-row"><input type="checkbox" name="offline_drafts_enabled" value="1" <?= site_setting('mobile.offline_drafts_enabled', '1') === '1' ? 'checked' : '' ?>><span>Allow offline drafts</span></label>
            <label class="checkbox-row"><input type="checkbox" name="require_usage_proof" value="1" <?= site_setting('mobile.require_usage_proof', '0') === '1' ? 'checked' : '' ?>><span>Require proof for direct usage</span></label>
            <label><span>Minimum app version</span><input type="text" name="min_supported_version" value="<?= e(site_setting('mobile.min_supported_version', '1.0.0')) ?>" placeholder="1.0.0"></label>
        </div>

        <details class="settings-accordion" open>
            <summary>
                <span><strong>Wristband Usage Reasons</strong><small>Used only by storages marked Wristband / Guest Check-in.</small></span>
                <span class="table-count-badge"><?= number_format(count($usageReasons)) ?></span>
            </summary>
            <div class="settings-accordion-body">
                <div class="table-wrap">
                    <table class="data-table">
                        <thead><tr><th>Active</th><th>App label</th><th>Order</th><th>Permanent code</th></tr></thead>
                        <tbody>
                        <?php foreach ($usageReasons as $reason): ?>
                            <tr>
                                <td><input type="checkbox" name="usage_reason_active[<?= e($reason['code']) ?>]" value="1" <?= $reason['active'] ? 'checked' : '' ?> aria-label="Enable <?= e($reason['label']) ?>"></td>
                                <td>
                                    <input type="text" name="usage_reason_labels[<?= e($reason['code']) ?>]" value="<?= e($reason['label']) ?>" maxlength="60">
                                    <?php if ($reason['requires_custom_text']): ?><small>Employees must describe this reason.</small><?php endif; ?>
                                </td>
                                <td><input type="number" name="usage_reason_sort_orders[<?= e($reason['code']) ?>]" value="<?= (int) $reason['sort_order'] ?>" min="1" max="999" inputmode="numeric"></td>
                                <td><code><?= e($reason['code']) ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </details>

        <details class="settings-accordion" open>
            <summary>
                <span><strong>General Operations Reasons</strong><small>Used by cleaning, maintenance, operations, and other regular storages.</small></span>
                <span class="table-count-badge"><?= number_format(count($generalUsageReasons)) ?></span>
            </summary>
            <div class="settings-accordion-body">
                <div class="table-wrap">
                    <table class="data-table">
                        <thead><tr><th>Active</th><th>App label</th><th>Order</th><th>Permanent code</th></tr></thead>
                        <tbody>
                        <?php foreach ($generalUsageReasons as $reason): ?>
                            <tr>
                                <td><input type="checkbox" name="general_usage_reason_active[<?= e($reason['code']) ?>]" value="1" <?= $reason['active'] ? 'checked' : '' ?> aria-label="Enable <?= e($reason['label']) ?>"></td>
                                <td>
                                    <input type="text" name="general_usage_reason_labels[<?= e($reason['code']) ?>]" value="<?= e($reason['label']) ?>" maxlength="60">
                                    <?php if ($reason['requires_custom_text']): ?><small>Employees must describe this reason.</small><?php endif; ?>
                                </td>
                                <td><input type="number" name="general_usage_reason_sort_orders[<?= e($reason['code']) ?>]" value="<?= (int) $reason['sort_order'] ?>" min="1" max="999" inputmode="numeric"></td>
                                <td><code><?= e($reason['code']) ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </details>
        <button class="primary-button" type="submit">Save Mobile Settings</button>
    </form>
</section>

<section class="panel">
    <div class="table-shell-head">
        <div class="table-heading"><strong><?= ui_icon('users') ?><span>Employee Access</span></strong><span class="table-count-badge"><?= number_format(count($users)) ?></span></div>
        <p class="table-shell-copy">Manager, app actions, and storage visibility are controlled together here.</p>
    </div>
    <div class="mobile-access-guide">
        <div><?= ui_icon('users') ?><span><strong>1. Reporting</strong><small>Assign the manager who receives staff activity alerts.</small></span></div>
        <div><?= ui_icon('storages') ?><span><strong>2. Storage scope</strong><small>Pick exactly which storages appear in the app.</small></span></div>
        <div><?= ui_icon('settings') ?><span><strong>3. Capabilities</strong><small>Enable only the actions this employee may submit.</small></span></div>
    </div>
    <label class="mobile-access-search">
        <?= ui_icon('search') ?>
        <input type="search" placeholder="Search employee, email, manager, role, or storage" data-mobile-user-search autocomplete="off">
    </label>
    <div class="settings-accordion-stack" data-mobile-user-list>
        <?php foreach ($users as $mobileUser): ?>
            <?php
            $assignmentRows = $assignmentsByUser[(int) $mobileUser['id']] ?? [];
            $assignedIds = array_map('intval', array_column($assignmentRows, 'storage_id'));
            $ownedIds = array_map('intval', array_column(array_filter($assignmentRows, static fn (array $row): bool => ($row['access_role'] ?? 'member') === 'owner'), 'storage_id'));
            $setup = $mobileUser['mobile_setup'] ?? ['enabled' => false, 'ready' => false, 'issues' => [], 'missing_permissions' => [], 'required_permissions' => []];
            $isOwner = ($mobileUser['role'] ?? '') === 'owner';
            $assignedNames = array_values(array_filter(array_map(static fn (array $row): string => trim((string) ($row['storage_name'] ?? '')), $assignmentRows)));
            $storageSummary = $isOwner ? 'All active storages' : ($assignedNames === [] ? 'No storage assigned' : implode(', ', $assignedNames));
            $searchText = implode(' ', [
                $mobileUser['name'] ?? '',
                $mobileUser['email'] ?? '',
                $mobileUser['role'] ?? '',
                $mobileUser['position'] ?? '',
                $mobileUser['manager_name'] ?? '',
                $storageSummary,
            ]);
            $openForSetup = !empty($setup['enabled']) && empty($setup['ready']);
            ?>
            <details class="settings-accordion-panel mobile-access-user" id="mobile-user-<?= (int) $mobileUser['id'] ?>" data-mobile-user-card data-search-text="<?= e(strtolower($searchText)) ?>" <?= $openForSetup ? 'open' : '' ?>>
                <summary class="settings-accordion-summary">
                    <span>
                        <strong><?= e($mobileUser['name']) ?></strong>
                        <small><?= e($mobileUser['email']) ?> · <?= e(user_position_label($mobileUser['position'] ?? '', $mobileUser['role'])) ?></small>
                        <small><?= e($mobileUser['manager_name'] ? 'Manager: ' . $mobileUser['manager_name'] : ($isOwner ? 'Owner account' : 'Manager not assigned')) ?> · <?= e($storageSummary) ?></small>
                    </span>
                    <span class="settings-accordion-meta mobile-setup-status <?= !empty($setup['ready']) ? 'is-ready' : (!empty($setup['enabled']) ? 'is-warning' : 'is-off') ?>">
                        <?= !empty($setup['ready']) ? 'Ready' : (!empty($setup['enabled']) ? 'Needs setup' : 'Mobile off') ?>
                    </span>
                </summary>
                <form method="post" action="<?= e(url('/mobile-access/users/' . $mobileUser['id'])) ?>" class="settings-accordion-body mobile-access-form" data-mobile-access-form>
                    <?= csrf_field() ?>
                    <div class="mobile-access-identity-grid">
                        <?php if (!$isOwner): ?>
                            <label>
                                <span>Reports to manager<?= ($mobileUser['role'] ?? '') === 'staff' ? ' *' : '' ?></span>
                                <select name="manager_user_id">
                                    <option value="">No manager</option>
                                    <?php foreach ($managers as $manager): ?>
                                        <?php if ((int) $manager['id'] === (int) $mobileUser['id']) { continue; } ?>
                                        <option value="<?= (int) $manager['id'] ?>" <?= (int) ($mobileUser['manager_user_id'] ?? 0) === (int) $manager['id'] ? 'selected' : '' ?>>
                                            <?= e($manager['name']) ?> · <?= e(user_position_label($manager['position'] ?? '', $manager['role'])) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small>Requests and mobile stock activity notify this manager and the owner.</small>
                            </label>
                        <?php else: ?>
                            <div class="mobile-access-owner-note"><?= ui_icon('users') ?><span><strong>Owner account</strong><small>Owners have automatic access to every active storage and mobile capability.</small></span></div>
                            <?php foreach (['enabled', 'can_usage', 'can_restock', 'can_transfer', 'can_handover', 'can_custody', 'direct_restock_enabled'] as $ownerFlag): ?>
                                <input type="hidden" name="<?= e($ownerFlag) ?>" value="1">
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <a class="ghost-button mobile-access-edit-user" href="<?= e(url('/users/' . $mobileUser['id'] . '/edit')) ?>"><?= ui_icon('edit') ?><span>Open full user access</span></a>
                    </div>

                    <?php if (!$isOwner): ?>
                        <div class="mobile-capability-grid">
                            <label class="mobile-capability-card is-primary"><input type="checkbox" name="enabled" value="1" <?= (int) ($mobileUser['enabled'] ?? 0) === 1 ? 'checked' : '' ?>><span><?= ui_icon('scan') ?><strong>Mobile access</strong><small>Allow this employee to sign in.</small></span></label>
                            <?php foreach (['usage' => ['Report usage', 'Consume stock with a reason.'], 'restock' => ['Restock', 'Add stock when permitted.'], 'transfer' => ['Storage transfer', 'Move stock between assigned storages.'], 'handover' => ['Staff handover', 'Issue or request temporary stock.'], 'custody' => ['Long-term custody', 'Track durable inventory held by staff.']] as $key => [$label, $copy]): ?>
                                <label class="mobile-capability-card"><input type="checkbox" name="can_<?= e($key) ?>" value="1" <?= (int) ($mobileUser['can_' . $key] ?? 0) === 1 ? 'checked' : '' ?>><span><?= ui_icon($key === 'transfer' ? 'movements' : ($key === 'handover' || $key === 'custody' ? 'handover' : 'items')) ?><strong><?= e($label) ?></strong><small><?= e($copy) ?></small></span></label>
                            <?php endforeach; ?>
                            <label class="mobile-capability-card"><input type="checkbox" name="direct_restock_enabled" value="1" <?= (int) ($mobileUser['direct_restock_enabled'] ?? 0) === 1 ? 'checked' : '' ?>><span><?= ui_icon('plus') ?><strong>Direct restock</strong><small>Bypass receiving workflow for trusted users.</small></span></label>
                        </div>
                    <?php endif; ?>

                    <div class="mobile-storage-scope">
                        <div class="mobile-storage-scope-head">
                            <span><?= ui_icon('storages') ?><span><strong>Storage visibility</strong><small>The app exposes only checked storages.</small></span></span>
                            <span class="pill pill-muted" data-mobile-storage-count><?= $isOwner ? number_format(count($storages)) : number_format(count($assignedIds)) ?> assigned</span>
                        </div>
                        <?php if ($isOwner): ?>
                            <p class="mobile-storage-owner-copy">Owner access automatically includes every active non-system storage.</p>
                        <?php elseif ($storages === []): ?>
                            <p class="empty-cell">Create an active storage before enabling this employee.</p>
                        <?php else: ?>
                            <div class="mobile-storage-choice-grid">
                            <?php foreach ($storages as $storage): ?>
                                <?php $isOwnedStorage = in_array((int) $storage['id'], $ownedIds, true); ?>
                                <label class="mobile-storage-choice">
                                    <input type="checkbox" name="storage_ids[]" value="<?= (int) $storage['id'] ?>" data-mobile-storage-option <?= in_array((int) $storage['id'], $assignedIds, true) ? 'checked' : '' ?> <?= $isOwnedStorage ? 'disabled' : '' ?>>
                                    <span><?= ui_icon('storages') ?><span><strong><?= e($storage['name']) ?></strong><small><?= e(ucfirst((string) ($storage['storage_type'] ?? 'storage'))) ?><?= $isOwnedStorage ? ' · Co-owner' : '' ?></small></span></span>
                                </label>
                                <?php if ($isOwnedStorage): ?><input type="hidden" name="storage_ids[]" value="<?= (int) $storage['id'] ?>"><?php endif; ?>
                            <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <label class="mobile-default-storage">
                            <span>Default storage</span>
                            <select name="default_storage_id" data-mobile-default-storage>
                                <option value="0">No default</option>
                                <?php foreach ($storages as $storage): ?>
                                    <?php $storageSelected = $isOwner || in_array((int) $storage['id'], $assignedIds, true); ?>
                                    <option value="<?= (int) $storage['id'] ?>" <?= (int) ($mobileUser['default_storage_id'] ?? 0) === (int) $storage['id'] ? 'selected' : '' ?> <?= !$storageSelected ? 'disabled' : '' ?>><?= e($storage['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small>Quantity Check, Scan In, and Scan Out open here first.</small>
                        </label>
                    </div>

                    <?php if (!$isOwner): ?>
                        <label class="checkbox-row mobile-permission-sync"><input type="checkbox" name="apply_required_permissions" value="1" checked><span><strong>Add required web permissions automatically</strong><small>Mobile Access can narrow permissions, never expand beyond this saved checklist.</small></span></label>
                    <?php endif; ?>

                    <?php if (!empty($setup['issues'])): ?>
                        <div class="mobile-setup-warning">
                            <strong>Setup required</strong>
                            <ul><?php foreach ($setup['issues'] as $issue): ?><li><?= e($issue) ?></li><?php endforeach; ?></ul>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($setup['required_permissions'])): ?>
                        <details class="mobile-required-permissions">
                            <summary>Required permission checklist · <?= number_format(count($setup['required_permissions'])) ?></summary>
                            <div><?php foreach ($setup['required_permissions'] as $permission): ?><span class="pill <?= in_array($permission, $setup['missing_permissions'] ?? [], true) ? 'pill-warning' : 'pill-active' ?>" title="<?= e(mobile_admin_permission_label($permission, $permissionLabels)) ?>"><?= e($permission) ?></span><?php endforeach; ?></div>
                        </details>
                    <?php endif; ?>
                    <button class="primary-button" type="submit"><?= ui_icon('settings') ?><span>Save mobile setup for <?= e($mobileUser['name']) ?></span></button>
                </form>
            </details>
        <?php endforeach; ?>
        <p class="empty-cell" data-mobile-user-empty hidden>No employees match this search.</p>
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
