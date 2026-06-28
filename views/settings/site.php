<section class="page-head">
    <div class="page-head-copy">
        <p class="eyebrow"><?= e(site_setting('page.settings_eyebrow', 'Website Control')) ?></p>
        <h3 class="page-head-title"><?= ui_icon('settings') ?><span><?= e(site_setting('page.settings', 'Website Control')) ?></span></h3>
    </div>
</section>

<section class="panel form-panel">
    <div class="panel-head">
        <div class="panel-head-copy">
            <p class="eyebrow">Dashboard Control</p>
            <h3>Editable Website Labels</h3>
        </div>
    </div>

    <p class="table-shell-copy">
        Blank fields fall back to the built-in default.
        <?php if (!($canManageSecretSettings ?? false)): ?>
            API keys and SMTP passwords are hidden because this account does not have secret-settings access.
        <?php endif; ?>
    </p>

    <?php
        $customLogoAsset = brand_custom_logo_asset();
        $customLogoName = brand_custom_logo_name();
        $logoFileLabel = $customLogoAsset !== null
            ? ($customLogoName !== '' ? $customLogoName : basename($customLogoAsset))
            : 'Using built-in KONA logo';
    ?>
    <form class="settings-logo-form" method="post" action="<?= e(url('/settings/logo')) ?>" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <div class="settings-logo-card">
            <div class="settings-logo-preview">
                <img src="<?= e(brand_logo_url()) ?>" alt="<?= e(site_brand_word()) ?> logo">
            </div>
            <div class="settings-logo-copy">
                <p class="eyebrow">Brand Logo</p>
                <h4>Website And Document Logo</h4>
                <p><?= e($logoFileLabel) ?></p>
                <small>PNG, JPG, or WebP. Max 4 MB. Used on login, sidebar, handover sheets, request sheets, and exports that include the brand logo.</small>
            </div>
            <div class="settings-logo-controls">
                <?php if (Auth::hasPermission('settings.edit')): ?>
                    <label class="settings-file-field">
                        <span>Upload logo</span>
                        <input type="file" name="brand_logo" accept="image/png,image/jpeg,image/webp">
                    </label>
                    <div class="settings-logo-actions">
                        <button class="primary-button" type="submit"><?= ui_icon('files') ?><span>Update Logo</span></button>
                        <?php if ($customLogoAsset !== null): ?>
                            <button class="ghost-button" type="submit" name="clear_brand_logo" value="1"><?= ui_icon('back') ?><span>Use Built-In Logo</span></button>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <small>You can view the active logo, but this account cannot change Website Control.</small>
                <?php endif; ?>
            </div>
        </div>
    </form>

    <?php if (!empty($ocrHealth) && is_array($ocrHealth)): ?>
        <div class="settings-health-panel">
            <div class="settings-health-head">
                <div>
                    <p class="eyebrow">OCR Health</p>
                    <h4>Purchase Document Extraction</h4>
                </div>
                <span class="status-badge badge-warning"><?= e(site_setting('ocr.mode', 'hybrid')) ?></span>
            </div>
            <div class="settings-health-grid">
                <?php foreach ($ocrHealth as $healthItem): ?>
                    <article class="settings-health-card <?= !empty($healthItem['ok']) ? 'is-ok' : 'is-missing' ?>">
                        <span class="settings-health-status"><?= !empty($healthItem['ok']) ? 'Ready' : 'Needs setup' ?></span>
                        <strong><?= e((string) ($healthItem['label'] ?? 'OCR check')) ?></strong>
                        <p><?= e((string) ($healthItem['status'] ?? 'unknown')) ?></p>
                        <small><?= e((string) ($healthItem['detail'] ?? '')) ?></small>
                    </article>
                <?php endforeach; ?>
            </div>
            <p class="tiny-copy settings-health-note"><?= e(site_setting('ocr.monthly_safety_note', 'OpenAI OCR is paid. Use it for hard scans only and review every extracted row before creating drafts.')) ?></p>
        </div>
    <?php endif; ?>

    <form class="stack-form" method="post" action="<?= e(url('/settings/site')) ?>">
        <?= csrf_field() ?>

        <div class="settings-accordion">
            <?php foreach ($settingGroups as $groupIndex => $group): ?>
                <?php $fieldCount = count($group['fields'] ?? []); ?>
                <details class="panel settings-panel settings-accordion-panel" <?= $groupIndex < 2 ? 'open' : '' ?>>
                    <summary class="settings-accordion-summary">
                        <span>
                            <span class="eyebrow">Control Group</span>
                            <strong><?= e($group['title']) ?></strong>
                            <small><?= e($group['copy']) ?></small>
                        </span>
                        <span class="settings-accordion-meta"><?= number_format($fieldCount) ?> field<?= $fieldCount === 1 ? '' : 's' ?></span>
                    </summary>

                    <div class="settings-accordion-body">
                        <div class="settings-field-grid">
                            <?php foreach ($group['fields'] as $field): ?>
                                <label class="field" data-setting-field="<?= e($field['key']) ?>">
                                    <span><?= e($field['label']) ?></span>
                                    <?php if (($field['type'] ?? 'text') === 'choice' && !empty($field['options']) && is_array($field['options'])): ?>
                                        <div class="settings-choice-list" role="radiogroup" aria-label="<?= e($field['label']) ?>">
                                            <?php foreach ($field['options'] as $optionValue => $optionLabel): ?>
                                                <label class="settings-choice">
                                                    <input
                                                        type="radio"
                                                        name="settings[<?= e($field['key']) ?>]"
                                                        value="<?= e((string) $optionValue) ?>"
                                                        <?= checked((string) $optionValue === (string) $field['value']) ?>
                                                    >
                                                    <span><?= e((string) $optionLabel) ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php elseif (($field['type'] ?? 'text') === 'select' && !empty($field['options']) && is_array($field['options'])): ?>
                                        <select name="settings[<?= e($field['key']) ?>]">
                                            <?php foreach ($field['options'] as $optionValue => $optionLabel): ?>
                                                <option value="<?= e((string) $optionValue) ?>" <?= selected((string) $optionValue, (string) $field['value']) ?>>
                                                    <?= e((string) $optionLabel) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php elseif (($field['type'] ?? 'text') === 'secret'): ?>
                                        <input
                                            type="password"
                                            name="settings[<?= e($field['key']) ?>]"
                                            value=""
                                            maxlength="<?= e((string) ($field['maxlength'] ?? 512)) ?>"
                                            placeholder="<?= e((string) ($field['placeholder'] ?? 'Paste secret value')) ?>"
                                            autocomplete="new-password"
                                            spellcheck="false"
                                        >
                                        <span class="secret-setting-status">
                                            <?= !empty($field['is_configured'])
                                                ? e('Configured' . (($field['configured_source'] ?? '') === 'environment' ? ' from .env' : ' in settings'))
                                                : 'Not configured' ?>
                                        </span>
                                        <?php if (($field['configured_source'] ?? '') === 'settings'): ?>
                                            <label class="inline-check secret-clear-check">
                                                <input type="checkbox" name="clear_settings[<?= e($field['key']) ?>]" value="1">
                                                <span>Clear saved key</span>
                                            </label>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <input
                                            type="<?= ($field['type'] ?? 'text') === 'email' ? 'email' : (($field['type'] ?? 'text') === 'number' ? 'number' : 'text') ?>"
                                            name="settings[<?= e($field['key']) ?>]"
                                            value="<?= e((string) $field['value']) ?>"
                                            maxlength="<?= e((string) ($field['maxlength'] ?? 160)) ?>"
                                            <?= ($field['type'] ?? 'text') === 'number' ? 'inputmode="numeric" min="0" step="1"' : '' ?>
                                        >
                                    <?php endif; ?>
                                    <?php if (!empty($field['help'])): ?>
                                        <small><?= e((string) $field['help']) ?></small>
                                    <?php endif; ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </details>
            <?php endforeach; ?>
        </div>

        <div class="page-actions">
            <button class="primary-button" type="submit"><?= ui_icon('settings') ?><span>Save Website Control</span></button>
            <a class="ghost-button" href="<?= e(url('/dashboard')) ?>"><?= ui_icon('dashboard') ?><span>Back To Dashboard</span></a>
        </div>
    </form>
</section>

<?php if (Auth::isOwner()): ?>
    <section class="panel form-panel">
        <div class="panel-head">
            <div class="panel-head-copy">
                <p class="eyebrow">Email Delivery</p>
                <h3>Send Test Email</h3>
            </div>
        </div>
        <div class="mailer-guide-grid">
            <div class="mailer-guide-card">
                <strong>What you need from Hostinger</strong>
                <p>Mailbox address, SMTP host, SMTP port, encryption, username, and password.</p>
            </div>
            <div class="mailer-guide-card">
                <strong>Recommended setup</strong>
                <p>Use SMTP with a sender email on your domain. Port 465 + SSL or 587 + TLS are the normal options.</p>
            </div>
            <div class="mailer-guide-card">
                <strong>Safe behavior</strong>
                <p>If mail fails, the app logs the failure and keeps password, stock, request, handover, and purchase workflows running.</p>
            </div>
        </div>
        <form class="stack-form" method="post" action="<?= e(url('/settings/email-test')) ?>">
            <?= csrf_field() ?>
            <label class="field">
                <span>Send test to</span>
                <input type="email" name="test_email" value="<?= e((string) (Auth::user()['email'] ?? '')) ?>" required>
                <small>Uses the selected Email Delivery transport. Log-only mode creates a delivery log without sending an inbox message.</small>
            </label>
            <div class="page-actions">
                <button class="primary-button" type="submit"><?= ui_icon('notification') ?><span>Send Test Email</span></button>
            </div>
        </form>
    </section>
<?php endif; ?>
