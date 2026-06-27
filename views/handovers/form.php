<?php
$lineItems = is_array($lineItems) && $lineItems !== [] ? $lineItems : [['item_id' => '', 'quantity' => '']];
$isStaffRequest = !empty($isStaffRequest);
?>

<section class="page-head">
    <div>
        <p class="eyebrow"><?= $isStaffRequest ? 'Temporary Use Request' : 'Temporary Issue' ?></p>
        <h3><?= $isStaffRequest ? 'Request Handover' : 'Create Handover' ?></h3>
    </div>
    <div class="page-actions">
        <a class="ghost-button" href="<?= e(url('/handovers')) ?>">Back</a>
    </div>
</section>

<section class="panel form-panel">
    <form class="stack-form" method="post" action="<?= e(url('/handovers/create')) ?>">
        <?= csrf_field() ?>

        <?php if ($isStaffRequest): ?>
            <div class="copy-context-card">
                <strong>Request a temporary handover</strong>
                <p>Ask the storage owner for the items you will use later. Once approved, the handover becomes active and you will confirm what you actually received.</p>
            </div>
        <?php endif; ?>

        <div class="field-row">
            <?php if ($isStaffRequest): ?>
                <?php if (!empty($lockedRequestOwner)): ?>
                    <div class="field workflow-owner-field">
                        <span>Assigned Owner</span>
                        <div class="workflow-owner-card">
                            <strong><?= e((string) $lockedRequestOwner['name']) ?></strong>
                            <span class="tiny-copy">This staff account can request handovers only from this storage owner.</span>
                        </div>
                    </div>
                <?php else: ?>
                    <label class="field">
                        <span>Ask From</span>
                        <select name="request_owner_user_id" data-workflow-owner-select required>
                            <option value="">Select storage owner</option>
                            <?php foreach ($ownerCandidates as $ownerCandidate): ?>
                                <option value="<?= e((string) $ownerCandidate['id']) ?>" <?= selected((string) $ownerCandidate['id'], (string) ($handoverRecord['request_owner_user_id'] ?? '')) ?>>
                                    <?= e((string) $ownerCandidate['name']) ?> · <?= e(user_role_label((string) $ownerCandidate['role'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php endif; ?>
            <?php endif; ?>

            <label class="field">
                <span>Source Storage</span>
                <select name="source_storage_id" data-workflow-storage required>
                    <option value=""><?= $isStaffRequest ? 'Select source storage' : 'Select source' ?></option>
                    <?php foreach ($sourceStorages as $storage): ?>
                        <option value="<?= e((string) $storage['id']) ?>" <?= selected((string) $storage['id'], (string) ($handoverRecord['source_storage_id'] ?? '')) ?>>
                            <?= e(storage_type_label((string) $storage['storage_type'])) ?> · <?= e((string) $storage['name']) ?><?= !empty($storage['owner_name']) ? ' · Owner: ' . e((string) $storage['owner_name']) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <?php if (!$isStaffRequest): ?>
                <label class="field">
                    <span>Staff Account</span>
                    <select name="recipient_user_id">
                        <option value="">Optional linked staff</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?= e((string) $user['id']) ?>" <?= selected((string) $user['id'], (string) ($handoverRecord['recipient_user_id'] ?? '')) ?>>
                                <?= e((string) $user['name']) ?> · <?= e(user_role_label((string) $user['role'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="field">
                    <span>Recipient Name</span>
                    <input type="text" name="recipient_name" value="<?= e((string) ($handoverRecord['recipient_name'] ?? '')) ?>" placeholder="Reception, event desk, person name">
                </label>
            <?php endif; ?>
        </div>

        <div class="field-row">
            <label class="field">
                <span><?= $isStaffRequest ? 'Needed For' : 'Scheduled For' ?></span>
                <input type="date" name="scheduled_for_date" value="<?= e((string) ($handoverRecord['scheduled_for_date'] ?? '')) ?>">
            </label>
        </div>

        <label class="field">
            <span>Notes</span>
            <textarea name="notes" rows="4" placeholder="<?= $isStaffRequest ? 'Why these items are needed and where they will be used' : 'Where this stock is going and why' ?>"><?= e((string) ($handoverRecord['notes'] ?? '')) ?></textarea>
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
            data-locked-owner-id="<?= e(!empty($lockedRequestOwner) ? (string) $lockedRequestOwner['id'] : '') ?>"
        >
            <div class="panel-head">
                <div>
                    <p class="eyebrow">Line Items</p>
                    <h3><?= $isStaffRequest ? 'What You Need' : 'What You Handed Over' ?></h3>
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

        <button class="primary-button" type="submit"><?= $isStaffRequest ? 'Send Handover Request' : 'Create Handover' ?></button>
    </form>
</section>
