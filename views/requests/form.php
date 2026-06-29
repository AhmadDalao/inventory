<?php
$lineItems = is_array($lineItems) && $lineItems !== [] ? $lineItems : [['item_id' => '', 'quantity' => '']];
?>

<section class="page-head">
    <div>
        <p class="eyebrow">Inventory Request</p>
        <h3>Create Request</h3>
    </div>
    <div class="page-actions">
        <a class="ghost-button" href="<?= e(url('/requests')) ?>">Back</a>
    </div>
</section>

<section class="panel form-panel">
    <form class="stack-form" method="post" action="<?= e(url('/requests/create')) ?>">
        <?= csrf_field() ?>

        <?php if ($isStaffRequest): ?>
            <div class="copy-context-card">
                <strong>Staff request mode</strong>
                <p>Pick the storage you need items from, ask for the quantity, and the storage owner handles approval. You do not need to choose a destination storage.</p>
            </div>
        <?php endif; ?>

        <div class="field-row">
            <label class="field">
                <span>Source Storage</span>
                <select name="source_storage_id" data-workflow-storage required>
                    <option value="">Select source</option>
                    <?php foreach ($sourceStorages as $storage): ?>
                        <option value="<?= e((string) $storage['id']) ?>" <?= selected((string) $storage['id'], (string) ($requestRecord['source_storage_id'] ?? '')) ?>>
                            <?= e(storage_type_label((string) $storage['storage_type'])) ?> · <?= e((string) $storage['name']) ?><?= !empty($storage['owner_name']) ? ' · Owner: ' . e((string) $storage['owner_name']) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <?php if (!$isStaffRequest): ?>
                <label class="field">
                    <span>Destination Storage</span>
                    <select name="destination_storage_id" required>
                        <option value="">Select destination</option>
                        <?php foreach ($destinationStorages as $storage): ?>
                            <option value="<?= e((string) $storage['id']) ?>" <?= selected((string) $storage['id'], (string) ($requestRecord['destination_storage_id'] ?? '')) ?>>
                                <?= e(storage_type_label((string) $storage['storage_type'])) ?> · <?= e((string) $storage['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>

            <div class="field workflow-owner-field">
                <span>Approver</span>
                <div class="workflow-owner-card" data-request-owner-card>
                    <strong data-request-owner-name>Select a source storage</strong>
                    <span class="tiny-copy" data-request-owner-copy>The storage owner will approve this request.</span>
                </div>
            </div>
        </div>

        <div class="field-row">
            <label class="field">
                <span>Need By Date</span>
                <input type="date" name="needed_by_date" value="<?= e((string) ($requestRecord['needed_by_date'] ?? '')) ?>">
            </label>
        </div>

        <label class="field">
            <span>Notes</span>
            <textarea name="notes" rows="4" placeholder="Why these items are needed"><?= e((string) ($requestRecord['notes'] ?? '')) ?></textarea>
        </label>

        <section
            class="workflow-line-builder"
            data-workflow-line-builder
            data-line-name-item="line_item_id[]"
            data-line-name-quantity="line_quantity[]"
            data-storage-catalog="<?= e((string) $storageCatalogJson) ?>"
            data-storage-meta="<?= e((string) $storageMetaJson) ?>"
            data-hide-availability="<?= $isStaffRequest ? 'true' : 'false' ?>"
            data-hide-item-quantity="<?= $isStaffRequest ? 'true' : 'false' ?>"
        >
            <div class="panel-head">
                <div>
                    <p class="eyebrow">Line Items</p>
                    <h3>What You Need</h3>
                </div>
                <button class="ghost-button" type="button" data-add-workflow-line><?= ui_icon('plus') ?><span>Add Item</span></button>
            </div>

            <div class="table-wrap">
                <table class="data-table workflow-line-table">
                    <thead>
                    <tr>
                        <th>Item</th>
                        <?php if (!$isStaffRequest): ?>
                            <th>Available</th>
                        <?php endif; ?>
                        <th>Quantity</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody data-workflow-line-body>
                    <?php foreach ($lineItems as $line): ?>
                        <tr data-workflow-line>
                            <td>
                                <div class="workflow-picker" data-workflow-picker>
                                    <input type="hidden" name="line_item_id[]" value="<?= e((string) ($line['item_id'] ?? '')) ?>" data-workflow-item-input required>
                                    <button class="workflow-picker-toggle" type="button" data-workflow-picker-toggle>
                                        <span class="workflow-picker-toggle-copy" data-workflow-picker-label><?= !empty($line['item_id']) ? 'Saved item' : 'Select source item first' ?></span>
                                    </button>
                                    <div class="workflow-picker-panel" data-workflow-picker-panel hidden>
                                        <input class="workflow-picker-search" type="search" placeholder="Search item" data-workflow-picker-search>
                                        <div class="workflow-picker-options" data-workflow-picker-options></div>
                                    </div>
                                </div>
                            </td>
                            <?php if (!$isStaffRequest): ?>
                                <td>
                                    <span class="tiny-copy" data-workflow-available>-</span>
                                </td>
                            <?php endif; ?>
                            <td>
                                <input type="number" step="0.01" min="0.01" name="line_quantity[]" value="<?= e((string) ($line['quantity'] ?? '')) ?>" required>
                            </td>
                            <td>
                                <button class="text-button danger-link" type="button" data-remove-workflow-line>Remove</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <div class="form-actions">
            <button class="ghost-button" type="submit" name="request_action" value="draft">Save Draft</button>
            <button class="primary-button" type="submit" name="request_action" value="submit">Submit Request</button>
        </div>
    </form>
</section>
