<?php
$imageUrl = asset_image_url($asset['image_path'] ?? null);
$scanCode = asset_scan_code($asset);
$currentUserId = (int) (Auth::user()['id'] ?? 0);
$isHolder = (int) ($asset['assigned_user_id'] ?? 0) === $currentUserId;
$canManage = Auth::hasPermission('assets.assign') && !Auth::isStaff();
$canMaintain = Auth::hasPermission('assets.maintenance') && !Auth::isStaff();
$canOverride = Auth::hasPermission('assets.status_override') && !Auth::isStaff();
?>

<section class="page-head">
    <div class="page-head-copy">
        <p class="eyebrow">Asset Detail</p>
        <h3 class="page-head-title"><?= ui_icon('assets') ?><span><?= e($asset['asset_number']) ?></span></h3>
    </div>
    <div class="page-actions">
        <a class="ghost-button" href="<?= e(url('/company-assets')) ?>"><?= ui_icon('back') ?><span>All Assets</span></a>
        <?php if (Auth::hasPermission('assets.edit') && !Auth::isStaff()): ?>
            <a class="primary-button" href="<?= e(url('/company-assets/' . $asset['id'] . '/edit')) ?>">Edit Asset</a>
        <?php endif; ?>
    </div>
</section>

<section class="detail-grid">
    <article class="panel detail-summary">
        <div class="detail-hero item-summary-hero">
            <div class="detail-hero-main">
                <?php if ($imageUrl): ?>
                    <img class="item-hero-image expandable-image" src="<?= e($imageUrl) ?>" alt="<?= e($asset['name']) ?>" data-expand-image tabindex="0">
                <?php else: ?>
                    <div class="item-hero-image item-hero-image-fallback"><?= e(asset_initial($asset['name'])) ?></div>
                <?php endif; ?>

                <div class="item-summary-copy">
                    <span class="pill <?= e(asset_status_tone((string) $asset['status'])) ?>"><?= e(asset_status_label((string) $asset['status'])) ?></span>
                    <h4><?= e($asset['name']) ?></h4>
                    <p><?= e($asset['category'] ?: 'No category') ?><?= $asset['model'] ? ' - ' . e($asset['model']) : '' ?></p>
                    <p class="tiny-copy">
                        Serial: <?= e($asset['serial_number'] ?: 'Not set') ?>
                        - Condition: <?= e(asset_condition_label((string) $asset['condition_status'])) ?>
                    </p>
                </div>
            </div>
            <div class="align-right item-summary-stock">
                <strong class="stock-number"><?= e(format_money($asset['purchase_cost'])) ?></strong>
                <span>asset value</span>
                <span class="tiny-copy"><?= (int) $asset['is_active'] === 1 ? 'Active record' : 'Deleted record' ?></span>
            </div>
        </div>

        <div class="item-summary-stats">
            <article class="item-summary-stat item-summary-stat-main">
                <span>Current Status</span>
                <strong><?= e(asset_status_label((string) $asset['status'])) ?></strong>
            </article>
            <article class="item-summary-stat">
                <span>Holder</span>
                <strong><?= e($asset['assigned_user_name'] ?: 'None') ?></strong>
            </article>
            <article class="item-summary-stat">
                <span>Location</span>
                <strong><?= e($asset['storage_name'] ?: 'Not set') ?></strong>
            </article>
            <article class="item-summary-stat">
                <span>Warranty</span>
                <strong><?= e($asset['warranty_expires_at'] ?: 'Not set') ?></strong>
            </article>
        </div>

        <section class="item-detail-barcode">
            <div>
                <span class="eyebrow">Scan Reference</span>
                <strong><?= e($scanCode) ?></strong>
                <p class="tiny-copy">Search or scan this reference. Asset QR should store only this code.</p>
            </div>
            <div class="barcode-box item-detail-barcode-box">
                <?= code39_svg($scanCode, 48) ?>
                <code><?= e($scanCode) ?></code>
            </div>
        </section>

        <details class="item-summary-more" open>
            <summary>Asset details</summary>
            <dl class="detail-list item-summary-detail-list">
                <div><dt>Barcode / Tag</dt><dd><?= e($asset['barcode'] ?: 'Uses asset number') ?></dd></div>
                <div><dt>Supplier</dt><dd><?= e($asset['supplier_name'] ?: 'Not linked') ?></dd></div>
                <div><dt>Purchase</dt><dd><?= e($asset['purchase_number'] ?: 'Not linked') ?></dd></div>
                <div><dt>Purchase Date</dt><dd><?= e($asset['purchase_date'] ?: 'Not set') ?></dd></div>
                <div><dt>Created By</dt><dd><?= e($asset['creator_name'] ?: 'Unknown') ?></dd></div>
                <div><dt>Updated By</dt><dd><?= e($asset['updater_name'] ?: 'Unknown') ?></dd></div>
                <div class="item-summary-notes"><dt>Notes</dt><dd><?= nl2br(e($asset['notes'] ?: 'No notes.')) ?></dd></div>
            </dl>
        </details>

        <?php if (Auth::hasPermission('assets.archive') && !Auth::isStaff()): ?>
            <div class="item-summary-actions">
                <form method="post" action="<?= e(url('/company-assets/' . $asset['id'] . '/status')) ?>">
                    <?= csrf_field() ?>
                    <button class="ghost-button" type="submit" data-confirm="<?= (int) $asset['is_active'] === 1 ? 'Archive this asset?' : 'Recover this asset?' ?>">
                        <?= (int) $asset['is_active'] === 1 ? 'Archive Asset' : 'Recover Asset' ?>
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </article>

    <article class="panel movement-panel">
        <div class="panel-head">
            <div>
                <p class="eyebrow">Custody</p>
                <h3>Next Action</h3>
            </div>
        </div>

        <?php if ((string) $asset['status'] === 'pending_receipt' && ($isHolder || $canManage)): ?>
            <form class="stack-form" method="post" action="<?= e(url('/company-assets/' . $asset['id'] . '/confirm-receipt')) ?>">
                <?= csrf_field() ?>
                <p class="tiny-copy">Confirm you received this exact asset and it is now in your custody.</p>
                <button class="primary-button" type="submit">Confirm Receipt</button>
            </form>
        <?php elseif (in_array((string) $asset['status'], ['assigned'], true) && ($isHolder || $canManage)): ?>
            <form class="stack-form" method="post" action="<?= e(url('/company-assets/' . $asset['id'] . '/request-return')) ?>">
                <?= csrf_field() ?>
                <label class="field">
                    <span>Return notes</span>
                    <textarea name="notes" rows="3" placeholder="Optional reason or condition notes"></textarea>
                </label>
                <button class="primary-button" type="submit">Request Return</button>
            </form>
        <?php elseif ((string) $asset['status'] === 'return_requested' && $canManage): ?>
            <form class="stack-form" method="post" action="<?= e(url('/company-assets/' . $asset['id'] . '/confirm-return')) ?>">
                <?= csrf_field() ?>
                <div class="field-row">
                    <label class="field">
                        <span>Return to storage</span>
                        <select name="storage_id">
                            <?php foreach ($storages as $storage): ?>
                                <option value="<?= e((string) $storage['id']) ?>" <?= selected((string) $storage['id'], (string) ($asset['storage_id'] ?? '')) ?>>
                                    <?= e(storage_type_label($storage['storage_type'])) ?> - <?= e($storage['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="field">
                        <span>Condition now</span>
                        <select name="condition_status">
                            <?php foreach (asset_condition_options() as $value => $label): ?>
                                <option value="<?= e($value) ?>" <?= selected($value, (string) $asset['condition_status']) ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <label class="field">
                    <span>Return notes</span>
                    <textarea name="notes" rows="3" placeholder="Optional return notes"></textarea>
                </label>
                <button class="primary-button" type="submit">Confirm Return</button>
            </form>
        <?php else: ?>
            <p class="empty-state">No action is waiting for you right now.</p>
        <?php endif; ?>

        <?php if ($canManage): ?>
            <details class="package-preset-editor">
                <summary>Assign or move asset</summary>
                <form class="stack-form" method="post" action="<?= e(url('/company-assets/' . $asset['id'] . '/assign')) ?>">
                    <?= csrf_field() ?>
                    <label class="field">
                        <span>Assign to user</span>
                        <select name="assigned_user_id">
                            <option value="">No user - keep in storage</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?= e((string) $user['id']) ?>" <?= selected((string) $user['id'], (string) ($asset['assigned_user_id'] ?? '')) ?>>
                                    <?= e($user['name']) ?> - <?= e(user_role_label((string) $user['role'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="field">
                        <span>Storage / location</span>
                        <select name="storage_id">
                            <option value="">No storage selected</option>
                            <?php foreach ($storages as $storage): ?>
                                <option value="<?= e((string) $storage['id']) ?>" <?= selected((string) $storage['id'], (string) ($asset['storage_id'] ?? '')) ?>>
                                    <?= e(storage_type_label($storage['storage_type'])) ?> - <?= e($storage['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="field">
                        <span>Notes</span>
                        <textarea name="notes" rows="3" placeholder="Optional custody notes"></textarea>
                    </label>
                    <button class="primary-button" type="submit">Save Custody Change</button>
                </form>
            </details>
        <?php endif; ?>

        <?php if ($canMaintain): ?>
            <details class="package-preset-editor">
                <summary>Open maintenance</summary>
                <form class="stack-form" method="post" action="<?= e(url('/company-assets/' . $asset['id'] . '/maintenance')) ?>">
                    <?= csrf_field() ?>
                    <label class="field">
                        <span>Title</span>
                        <input type="text" name="title" placeholder="Repair screen, annual service" required>
                    </label>
                    <div class="field-row">
                        <label class="field">
                            <span>Vendor</span>
                            <select name="supplier_id">
                                <option value="">No vendor</option>
                                <?php foreach ($suppliers as $supplier): ?>
                                    <option value="<?= e((string) $supplier['id']) ?>"><?= e($supplier['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="field">
                            <span>Due date</span>
                            <input type="date" name="due_date">
                        </label>
                    </div>
                    <label class="field">
                        <span>Estimated cost</span>
                        <input type="number" name="cost" min="0" step="0.01" value="0">
                    </label>
                    <label class="field">
                        <span>Notes</span>
                        <textarea name="notes" rows="3"></textarea>
                    </label>
                    <button class="primary-button" type="submit">Open Maintenance</button>
                </form>
            </details>
        <?php endif; ?>

        <?php if ($canOverride): ?>
            <details class="package-preset-editor">
                <summary>Owner status override</summary>
                <form class="stack-form" method="post" action="<?= e(url('/company-assets/' . $asset['id'] . '/override-status')) ?>">
                    <?= csrf_field() ?>
                    <div class="field-row">
                        <label class="field">
                            <span>Status</span>
                            <select name="status">
                                <?php foreach (asset_status_options() as $value => $label): ?>
                                    <option value="<?= e($value) ?>" <?= selected($value, (string) $asset['status']) ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="field">
                            <span>Condition</span>
                            <select name="condition_status">
                                <?php foreach (asset_condition_options() as $value => $label): ?>
                                    <option value="<?= e($value) ?>" <?= selected($value, (string) $asset['condition_status']) ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>
                    <div class="field-row">
                        <label class="field">
                            <span>Assigned user</span>
                            <select name="assigned_user_id">
                                <option value="">No user</option>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?= e((string) $user['id']) ?>" <?= selected((string) $user['id'], (string) ($asset['assigned_user_id'] ?? '')) ?>>
                                        <?= e($user['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="field">
                            <span>Storage</span>
                            <select name="storage_id">
                                <option value="">No storage</option>
                                <?php foreach ($storages as $storage): ?>
                                    <option value="<?= e((string) $storage['id']) ?>" <?= selected((string) $storage['id'], (string) ($asset['storage_id'] ?? '')) ?>>
                                        <?= e($storage['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>
                    <label class="field">
                        <span>Reason / notes</span>
                        <textarea name="notes" rows="3" placeholder="Why this override is needed"></textarea>
                    </label>
                    <button class="ghost-button" type="submit" data-confirm="Override this asset status? This will be audited.">Save Override</button>
                </form>
            </details>
        <?php endif; ?>
    </article>
</section>

<section class="panel">
    <div class="table-shell-head">
        <div class="table-heading">
            <strong><?= ui_icon('files') ?><span>Signed Proof Sheets</span></strong>
        </div>
        <p class="table-shell-copy">Download a current asset custody sheet with image, serial, barcode, QR reference, condition, holder, and signature lines.</p>
    </div>

    <div class="file-card-grid">
        <a class="file-card" href="<?= e(url('/company-assets/' . $asset['id'] . '/signoff.xlsx')) ?>">
            <span class="file-card-icon"><?= ui_icon('export') ?></span>
            <strong>Excel asset sign-off sheet</strong>
            <span>Editable XLSX with asset photo, QR reference, scan barcode, and custody fields.</span>
        </a>
        <a class="file-card" href="<?= e(url('/company-assets/' . $asset['id'] . '/signoff.pdf')) ?>">
            <span class="file-card-icon"><?= ui_icon('files') ?></span>
            <strong>PDF asset sign-off sheet</strong>
            <span>Print, sign, scan, or attach as proof for asset custody.</span>
        </a>
    </div>
</section>

<section class="panel">
    <div class="table-shell-head">
        <div class="table-heading">
            <strong><?= ui_icon('files') ?><span>Files And Proof</span></strong>
            <span class="table-count-badge"><?= number_format(count($files)) ?></span>
        </div>
        <p class="table-shell-copy">Invoices, warranty cards, signed forms, repair proof, and photos.</p>
    </div>

    <?php if (Auth::hasPermission('assets.files') && !Auth::isStaff()): ?>
        <form class="stack-form" method="post" action="<?= e(url('/company-assets/' . $asset['id'] . '/documents')) ?>" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <label class="field">
                <span>Upload asset files</span>
                <input type="file" name="documents[]" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp">
            </label>
            <button class="ghost-button" type="submit">Upload Files</button>
        </form>
    <?php endif; ?>

    <div class="file-card-grid">
        <?php if ($files === []): ?>
            <p class="empty-state">No asset files uploaded.</p>
        <?php endif; ?>
        <?php foreach ($files as $file): ?>
            <a class="file-card" href="<?= e(url('/files/' . $file['id'] . '/download')) ?>">
                <span class="file-card-icon"><?= ui_icon('files') ?></span>
                <strong><?= e($file['display_name']) ?></strong>
                <span><?= e($file['original_filename']) ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</section>

<section class="detail-grid">
    <article class="panel">
        <div class="table-shell-head">
            <div class="table-heading">
                <strong><?= ui_icon('reorder') ?><span>Maintenance</span></strong>
                <span class="table-count-badge"><?= number_format(count($maintenanceRecords)) ?></span>
            </div>
        </div>
        <div class="timeline-list">
            <?php if ($maintenanceRecords === []): ?>
                <p class="empty-state">No maintenance records.</p>
            <?php endif; ?>
            <?php foreach ($maintenanceRecords as $record): ?>
                <article class="timeline-card">
                    <div>
                        <strong><?= e($record['title']) ?></strong>
                        <p><?= e(ucfirst(str_replace('_', ' ', (string) $record['status']))) ?><?= $record['supplier_name'] ? ' - ' . e($record['supplier_name']) : '' ?></p>
                        <span><?= e($record['due_date'] ?: $record['created_at']) ?></span>
                    </div>
                    <strong><?= e(format_money($record['cost'])) ?></strong>
                    <?php if ($canMaintain && !in_array((string) $record['status'], ['completed', 'cancelled'], true)): ?>
                        <form method="post" action="<?= e(url('/company-assets/' . $asset['id'] . '/maintenance/' . $record['id'] . '/complete')) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="condition_status" value="<?= e((string) $asset['condition_status']) ?>">
                            <input type="hidden" name="cost" value="<?= e((string) $record['cost']) ?>">
                            <input type="hidden" name="notes" value="<?= e((string) ($record['notes'] ?? '')) ?>">
                            <button class="text-button" type="submit">Complete</button>
                        </form>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    </article>

    <article class="panel">
        <div class="table-shell-head">
            <div class="table-heading">
                <strong><?= ui_icon('audit') ?><span>Asset Timeline</span></strong>
                <span class="table-count-badge"><?= number_format(count($events)) ?></span>
            </div>
        </div>
        <div class="timeline-list">
            <?php if ($events === []): ?>
                <p class="empty-state">No asset events yet.</p>
            <?php endif; ?>
            <?php foreach ($events as $event): ?>
                <article class="timeline-card">
                    <div>
                        <strong><?= e($event['summary']) ?></strong>
                        <p><?= e($event['user_name'] ?: 'System') ?> - <?= e($event['event_type']) ?></p>
                        <span><?= e(date('M j, Y g:i A', strtotime((string) $event['created_at']))) ?></span>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </article>
</section>
