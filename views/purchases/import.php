<?php
$itemsJson = json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$unitOptionsJson = json_encode($unitOptions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$documentTypesJson = json_encode($documentTypes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$supplierTypeOptionsJson = json_encode(supplier_type_options(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
?>

<section class="page-head">
    <div class="page-head-copy">
        <p class="eyebrow">Supplier Documents</p>
        <h3 class="page-head-title"><?= ui_icon('document') ?><span>Bulk Import Purchases</span></h3>
    </div>
    <div class="page-actions">
        <a class="ghost-button" href="<?= e(url('/purchases')) ?>"><?= ui_icon('back') ?><span>Back</span></a>
        <a class="ghost-button" href="<?= e(url('/purchases/create')) ?>"><?= ui_icon('plus') ?><span>Single Purchase</span></a>
    </div>
</section>

<section class="panel form-panel">
    <form
        class="stack-form purchase-import-form"
        method="post"
        action="<?= e(url('/purchases/import/drafts')) ?>"
        enctype="multipart/form-data"
        data-purchase-bulk-import
        data-purchase-ocr-url="<?= e(url('/purchases/ocr-preview')) ?>"
        data-purchase-catalog="<?= e((string) $itemsJson) ?>"
        data-purchase-unit-options="<?= e((string) $unitOptionsJson) ?>"
        data-purchase-document-types="<?= e((string) $documentTypesJson) ?>"
        data-purchase-supplier-type-options="<?= e((string) $supplierTypeOptionsJson) ?>"
    >
        <?= csrf_field() ?>

        <div class="copy-context-card purchase-import-hero">
            <div>
                <strong>Turn old supplier files into purchase drafts</strong>
                <p>Upload Arabic or English quotes, price lists, receipts, or scanned PDFs. Server AI OCR handles old scans when configured, then you review the rows before creating drafts.</p>
            </div>
            <span class="status-badge badge-warning">Drafts only</span>
        </div>

        <div class="field-row">
            <label class="field">
                <span>Destination Storage</span>
                <select name="destination_storage_id" required>
                    <option value="">Select storage</option>
                    <?php foreach ($storages as $storage): ?>
                        <option value="<?= e((string) $storage['id']) ?>" <?= selected((string) $storage['id'], (string) ($defaults['destination_storage_id'] ?? '')) ?>>
                            <?= e(storage_type_label((string) $storage['storage_type'])) ?> &middot; <?= e((string) $storage['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="field">
                <span>Approver</span>
                <select name="approver_user_id" required>
                    <option value="">Select approver</option>
                    <?php foreach ($approvers as $approver): ?>
                        <option value="<?= e((string) $approver['id']) ?>" <?= selected((string) $approver['id'], (string) ($defaults['approver_user_id'] ?? '')) ?>>
                            <?= e($approver['name']) ?> &middot; <?= e(user_role_label((string) $approver['role'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>

        <div class="field-row">
            <label class="field">
                <span>Default Currency</span>
                <input type="text" name="default_currency" value="<?= e((string) ($defaults['currency'] ?? 'SAR')) ?>" maxlength="8" required>
            </label>

            <label class="field">
                <span>Default Document Type</span>
                <select name="default_document_type">
                    <?php foreach ($documentTypes as $type => $label): ?>
                        <option value="<?= e($type) ?>" <?= selected($type, (string) ($defaults['document_type'] ?? 'quote')) ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>

        <label class="field">
            <span>Documents</span>
            <input type="file" name="documents[]" multiple accept="application/pdf,image/jpeg,image/png,image/webp" data-purchase-bulk-files>
            <small class="tiny-copy">PDF, JPG, PNG, or WebP. Max 15 MB per file. AI OCR runs on the server when configured; browser OCR is the fallback.</small>
        </label>

        <label class="field">
            <span>Shared Notes</span>
            <textarea name="notes" rows="3" placeholder="Optional note added to every imported draft"><?= e((string) ($defaults['notes'] ?? '')) ?></textarea>
        </label>

        <div class="purchase-import-actions">
            <button class="primary-button" type="button" data-purchase-bulk-process><?= ui_icon('document') ?><span>Process Documents</span></button>
            <span class="purchase-ocr-status tiny-copy" data-purchase-bulk-status>Upload files, process them, then review every row before creating drafts.</span>
        </div>

        <section class="purchase-import-review" data-purchase-bulk-review>
            <div class="empty-state-card">
                <strong>No documents processed yet.</strong>
                <p>OCR is helpful, not magic. Expect to correct names, quantities, and prices on old scans.</p>
            </div>
        </section>

        <div class="button-row">
            <button class="primary-button" type="submit" data-purchase-bulk-submit disabled>Create Reviewed Drafts</button>
        </div>
    </form>
</section>
