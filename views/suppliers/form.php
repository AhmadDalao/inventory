<section class="page-head">
    <div class="page-head-copy">
        <p class="eyebrow"><?= e(site_setting('page.suppliers_eyebrow', 'Vendor directory')) ?></p>
        <h3 class="page-head-title"><?= ui_icon('supplier') ?><span><?= $mode === 'edit' ? 'Edit Supplier' : 'Create Supplier' ?></span></h3>
    </div>
    <div class="page-actions">
        <a class="ghost-button" href="<?= e(url('/suppliers')) ?>"><?= ui_icon('back') ?><span>Back</span></a>
    </div>
</section>

<section class="panel">
    <form class="stack-form" method="post" action="<?= e($mode === 'edit' ? url('/suppliers/' . $supplier['id'] . '/edit') : url('/suppliers/create')) ?>">
        <?= csrf_field() ?>

        <div class="field-row">
            <label class="field">
                <span>Supplier name</span>
                <input type="text" name="name" value="<?= e((string) $supplier['name']) ?>" required>
            </label>

            <label class="field">
                <span>Supplier type</span>
                <select name="supplier_type" required data-supplier-type-select>
                    <?php foreach (supplier_type_options() as $type => $label): ?>
                        <option value="<?= e($type) ?>" <?= selected($type, (string) ($supplier['supplier_type'] ?? 'product')) ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>

        <label class="field" data-supplier-type-other-field hidden>
            <span>Custom supplier type</span>
            <input type="text" name="supplier_type_other" value="<?= e((string) ($supplier['supplier_type_other'] ?? '')) ?>" placeholder="Example: Maintenance, contractor, logistics" data-supplier-type-other-input>
            <small class="tiny-copy">Required only when supplier type is Other.</small>
        </label>

        <div class="field-row">
            <label class="field">
                <span>Authorized person name / اسم المفوض</span>
                <input type="text" name="authorized_person" value="<?= e((string) ($supplier['authorized_person'] ?? '')) ?>" required>
            </label>

            <label class="field">
                <span>Phone number</span>
                <input type="text" name="phone" value="<?= e((string) $supplier['phone']) ?>" required>
            </label>
        </div>

        <label class="field">
            <span>National address / العنوان الوطني</span>
            <input type="text" name="national_address" value="<?= e((string) ($supplier['national_address'] ?? '')) ?>" required>
        </label>

        <div class="field-row">
            <label class="field">
                <span>Commercial Registration (CR) / رقم السجل التجاري</span>
                <input type="text" name="commercial_registration" value="<?= e((string) ($supplier['commercial_registration'] ?? '')) ?>" placeholder="Optional">
            </label>

            <label class="field">
                <span>Email</span>
                <input type="email" name="email" value="<?= e((string) $supplier['email']) ?>" placeholder="Optional">
            </label>
        </div>

        <label class="field">
            <span>VAT / Tax number</span>
            <input type="text" name="tax_number" value="<?= e((string) $supplier['tax_number']) ?>" placeholder="Optional">
        </label>

        <label class="field">
            <span>Notes</span>
            <textarea name="notes" rows="5" placeholder="Payment terms, contact person, delivery notes"><?= e((string) $supplier['notes']) ?></textarea>
        </label>

        <button class="primary-button" type="submit"><?= $mode === 'edit' ? 'Save Supplier' : 'Create Supplier' ?></button>
    </form>
</section>
