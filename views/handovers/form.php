<?php
$lineItems = is_array($lineItems) && $lineItems !== [] ? $lineItems : [['item_id' => '', 'quantity' => '']];
$isStaffRequest = !empty($isStaffRequest);
$handoverPurpose = (string) ($handoverRecord['handover_purpose'] ?? ((string) ($handoverRecord['recipient_type'] ?? 'staff') === 'storage' ? 'storage_transfer' : 'temporary_use'));
$handoverPurpose = in_array($handoverPurpose, ['temporary_use', 'staff_custody', 'storage_transfer'], true) ? $handoverPurpose : 'temporary_use';
$isStorageTransfer = !$isStaffRequest && $handoverPurpose === 'storage_transfer';
$isStaffCustody = !$isStaffRequest && $handoverPurpose === 'staff_custody';
$destinationStorages = is_array($destinationStorages ?? null) ? $destinationStorages : [];
$issueConditionOptions = is_array($issueConditionOptions ?? null) ? $issueConditionOptions : handover_issue_condition_options();
$usageReasonOptions = is_array($usageReasonOptions ?? null) ? $usageReasonOptions : handover_usage_reason_options();
$workflowCatalogPreview = json_decode((string) ($storageCatalogJson ?? '{}'), true);
$workflowCatalogItemsById = [];

if (is_array($workflowCatalogPreview)) {
    foreach ($workflowCatalogPreview as $catalogItems) {
        if (!is_array($catalogItems)) {
            continue;
        }

        foreach ($catalogItems as $catalogItem) {
            if (!is_array($catalogItem) || empty($catalogItem['id'])) {
                continue;
            }

            $workflowCatalogItemsById[(string) $catalogItem['id']] = $catalogItem;
        }
    }
}

$workflowLinePreviewItem = static function (array $line) use ($workflowCatalogItemsById): ?array {
    $itemId = (string) ($line['item_id'] ?? '');

    return $itemId !== '' && isset($workflowCatalogItemsById[$itemId])
        ? $workflowCatalogItemsById[$itemId]
        : null;
};
$oldExpectedUsage = [
    'reason' => old('expected_usage_reason', []),
    'quantity' => old('expected_usage_quantity', []),
    'other' => old('expected_usage_other', []),
    'notes' => old('expected_usage_notes', []),
];
$expectedRowsForIndex = static function (int $lineIndex) use ($oldExpectedUsage): array {
    $rows = [];
    $reasons = is_array($oldExpectedUsage['reason'][$lineIndex] ?? null) ? $oldExpectedUsage['reason'][$lineIndex] : [];
    $quantities = is_array($oldExpectedUsage['quantity'][$lineIndex] ?? null) ? $oldExpectedUsage['quantity'][$lineIndex] : [];
    $others = is_array($oldExpectedUsage['other'][$lineIndex] ?? null) ? $oldExpectedUsage['other'][$lineIndex] : [];
    $notes = is_array($oldExpectedUsage['notes'][$lineIndex] ?? null) ? $oldExpectedUsage['notes'][$lineIndex] : [];
    $keys = array_unique(array_merge(array_keys($reasons), array_keys($quantities), array_keys($others), array_keys($notes)));

    foreach ($keys as $key) {
        $rows[] = [
            'reason' => (string) ($reasons[$key] ?? 'unspecified'),
            'quantity' => (string) ($quantities[$key] ?? ''),
            'other' => (string) ($others[$key] ?? ''),
            'notes' => (string) ($notes[$key] ?? ''),
        ];
    }

    return $rows !== [] ? $rows : [[
        'reason' => 'unspecified',
        'quantity' => '',
        'other' => '',
        'notes' => '',
    ]];
};

$handoverCreateEyebrow = $isStaffRequest
    ? 'Temporary Use Request'
    : ($isStorageTransfer ? 'Storage Transfer' : ($isStaffCustody ? 'Long-Term Staff Custody' : 'Temporary Issue'));
$handoverCreateTitle = $isStaffRequest
    ? 'Request Handover'
    : ($isStorageTransfer ? 'Create Storage Transfer' : ($isStaffCustody ? 'Create Staff Custody' : 'Create Handover'));
$handoverNotesPlaceholder = $isStaffRequest
    ? 'Why these items are needed and where they will be used'
    : ($isStorageTransfer
        ? 'Why this stock is moving to another storage'
        : ($isStaffCustody ? 'What these items are assigned for and any care instructions' : 'Where this stock is going and why'));
$handoverLinesTitle = $isStaffRequest
    ? 'What You Need'
    : ($isStorageTransfer ? 'What You Are Transferring' : ($isStaffCustody ? 'What Staff Will Hold' : 'What You Handed Over'));
$handoverSubmitLabel = $isStaffRequest
    ? 'Send Handover Request'
    : ($isStorageTransfer ? 'Create Storage Transfer' : ($isStaffCustody ? 'Create Staff Custody' : 'Create Handover'));
?>

<section class="page-head">
    <div>
        <p class="eyebrow" data-handover-form-eyebrow><?= e($handoverCreateEyebrow) ?></p>
        <h3 data-handover-form-title><?= e($handoverCreateTitle) ?></h3>
    </div>
    <div class="page-actions">
        <a class="ghost-button" href="<?= e(url('/handovers')) ?>">Back</a>
    </div>
</section>

<section class="panel form-panel">
    <form class="stack-form" method="post" action="<?= e(url('/handovers/create')) ?>">
        <?= csrf_field() ?>

        <?php if ($isStaffRequest): ?>
            <input type="hidden" name="handover_purpose" value="temporary_use">
            <div class="copy-context-card">
                <strong>Request a temporary handover</strong>
                <p>Ask the storage owner for the items you will use later. Item results show the quantity currently available in the selected source storage. Once approved, the handover becomes active and you will confirm what you actually received.</p>
            </div>
        <?php endif; ?>

        <?php if (!$isStaffRequest): ?>
            <section class="copy-context-card handover-target-switcher" data-handover-target-switcher>
                <strong>What is this handover for?</strong>
                <p>Temporary use comes back after an event. Long-term custody stays assigned to staff until partial or final returns are approved. Storage transfer relocates stock.</p>
                <div class="handover-target-options">
                    <label class="handover-target-option">
                        <input type="radio" name="handover_purpose" value="temporary_use" data-handover-target-radio <?= !$isStorageTransfer && !$isStaffCustody ? 'checked' : '' ?>>
                        <span>
                            <strong>Temporary Use</strong>
                            <small>Temporary use, then returned quantity and usage reasons are reported.</small>
                        </span>
                    </label>
                    <label class="handover-target-option">
                        <input type="radio" name="handover_purpose" value="staff_custody" data-handover-target-radio <?= $isStaffCustody ? 'checked' : '' ?>>
                        <span>
                            <strong>Long-Term Staff Custody</strong>
                            <small>Items stay assigned for weeks or months and support partial, damaged, consumed, or lost returns.</small>
                        </span>
                    </label>
                    <label class="handover-target-option">
                        <input type="radio" name="handover_purpose" value="storage_transfer" data-handover-target-radio <?= $isStorageTransfer ? 'checked' : '' ?>>
                        <span>
                            <strong>Transfer to Storage Owner</strong>
                            <small>Destination owner confirms receipt. No usage closeout is shown.</small>
                        </span>
                    </label>
                </div>
            </section>
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
                <label class="field" data-handover-staff-fields <?= $isStorageTransfer ? 'hidden' : '' ?>>
                    <span>Staff Account</span>
                    <select name="recipient_user_id" <?= $isStorageTransfer ? 'disabled' : '' ?> data-handover-recipient-user>
                        <option value=""><?= $isStaffCustody ? 'Select staff member' : 'Optional linked staff' ?></option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?= e((string) $user['id']) ?>" <?= selected((string) $user['id'], (string) ($handoverRecord['recipient_user_id'] ?? '')) ?>>
                                <?= e((string) $user['name']) ?> · <?= e(user_role_label((string) $user['role'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="field" data-handover-staff-fields <?= $isStorageTransfer ? 'hidden' : '' ?>>
                    <span>Recipient Name</span>
                    <input type="text" name="recipient_name" value="<?= e((string) ($handoverRecord['recipient_name'] ?? '')) ?>" placeholder="Reception, event desk, person name" <?= $isStorageTransfer ? 'disabled' : '' ?>>
                </label>

                <label class="field" data-handover-storage-fields <?= $isStorageTransfer ? '' : 'hidden' ?>>
                    <span>Destination Storage</span>
                    <select
                        name="destination_storage_id"
                        data-handover-destination-storage
                        data-combobox-select
                        data-combobox-class="filter-search-combobox"
                        data-combobox-placeholder="Search destination storage"
                        data-combobox-empty="No matching destination storages."
                        <?= $isStorageTransfer ? 'required' : 'disabled' ?>
                    >
                        <option value="">Select destination storage</option>
                        <?php foreach ($destinationStorages as $storage): ?>
                            <option
                                value="<?= e((string) $storage['id']) ?>"
                                data-storage-name="<?= e((string) $storage['name']) ?>"
                                data-storage-type="<?= e(storage_type_label((string) $storage['storage_type'])) ?>"
                                data-owner-name="<?= e((string) ($storage['owner_name'] ?? '')) ?>"
                                <?= selected((string) $storage['id'], (string) ($handoverRecord['destination_storage_id'] ?? '')) ?>
                            >
                                <?= e(storage_type_label((string) $storage['storage_type'])) ?> · <?= e((string) $storage['name']) ?><?= !empty($storage['owner_name']) ? ' · Owner: ' . e((string) $storage['owner_name']) : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small data-handover-destination-copy>Destination owner confirms what arrived. Same source and destination are blocked.</small>
                    <div class="handover-destination-summary" data-handover-destination-summary hidden>
                        <span><?= ui_icon('storages') ?></span>
                        <div>
                            <strong data-handover-destination-summary-name>Destination storage</strong>
                            <small data-handover-destination-summary-owner>Destination owner confirms receipt.</small>
                        </div>
                    </div>
                </label>
            <?php endif; ?>
        </div>

        <?php if (!$isStaffRequest): ?>
            <div class="copy-context-card handover-storage-transfer-note" data-handover-storage-fields <?= $isStorageTransfer ? '' : 'hidden' ?>>
                <strong>Storage transfer cycle</strong>
                <p>Stock moves from the source into the handover buffer first. Full receipt closes into the destination. Any short or extra receipt waits for source-owner approval and safely adjusts the source stock.</p>
            </div>

            <div class="copy-context-card" data-handover-custody-fields <?= $isStaffCustody ? '' : 'hidden' ?>>
                <strong>Long-term custody cycle</strong>
                <p>Stock stays physically assigned to the staff member. Partial returns can be serviceable, damaged, consumed, or lost. Damaged stock requires proof and moves to quarantine after issuer approval.</p>
                <div class="field-row">
                    <label class="field">
                        <span>Issue Condition</span>
                        <select name="issue_condition" <?= $isStaffCustody ? '' : 'disabled' ?>>
                            <?php foreach ($issueConditionOptions as $value => $label): ?>
                                <option value="<?= e((string) $value) ?>" <?= selected((string) $value, (string) ($handoverRecord['issue_condition'] ?? 'good')) ?>>
                                    <?= e((string) $label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="field">
                        <span>Expected Review / Return Date</span>
                        <input type="date" name="custody_review_date" value="<?= e((string) ($handoverRecord['custody_review_date'] ?? '')) ?>" <?= $isStaffCustody ? 'required' : 'disabled' ?>>
                    </label>
                </div>
            </div>
        <?php endif; ?>

        <div class="field-row">
            <label class="field">
                <span><?= $isStaffRequest ? 'Needed For' : 'Scheduled For' ?></span>
                <input type="date" name="scheduled_for_date" value="<?= e((string) ($handoverRecord['scheduled_for_date'] ?? '')) ?>">
            </label>
        </div>

        <label class="field">
            <span>Notes</span>
            <textarea name="notes" rows="4" placeholder="<?= e($handoverNotesPlaceholder) ?>" data-handover-notes><?= e((string) ($handoverRecord['notes'] ?? '')) ?></textarea>
        </label>

        <section
            class="workflow-line-builder"
            data-workflow-line-builder
            data-line-name-item="line_item_id[]"
            data-line-name-quantity="line_quantity[]"
            data-storage-catalog="<?= e((string) $storageCatalogJson) ?>"
            data-storage-meta="<?= e((string) $storageMetaJson) ?>"
            data-hide-availability="false"
            data-hide-item-quantity="false"
            data-locked-owner-id="<?= e(!empty($lockedRequestOwner) ? (string) $lockedRequestOwner['id'] : '') ?>"
            data-expected-usage="false"
            data-usage-reasons="<?= e(json_encode($usageReasonOptions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>"
        >
            <div class="panel-head">
                <div>
                    <p class="eyebrow">Line Items</p>
                    <h3 data-handover-lines-title><?= e($handoverLinesTitle) ?></h3>
                </div>
                <button class="ghost-button" type="button" data-add-workflow-line><?= ui_icon('plus') ?><span>Add Item</span></button>
            </div>

            <div class="table-wrap">
                <table class="data-table workflow-line-table">
                    <thead>
                    <tr>
                        <th>Item</th>
                        <th>Available</th>
                        <th>Quantity</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody data-workflow-line-body>
                    <?php foreach ($lineItems as $lineIndex => $line): ?>
                        <?php $selectedWorkflowItem = $workflowLinePreviewItem((array) $line); ?>
                        <tr data-workflow-line>
                            <td>
                                <div class="workflow-picker" data-workflow-picker>
                                    <input type="hidden" name="line_item_id[]" value="<?= e((string) ($line['item_id'] ?? '')) ?>" data-workflow-item-input required>
                                    <button class="workflow-picker-toggle" type="button" data-workflow-picker-toggle>
                                        <span class="workflow-picker-toggle-copy" data-workflow-picker-label>
                                            <?php if ($selectedWorkflowItem): ?>
                                                <span class="workflow-picker-selected">
                                                    <?php if (!empty($selectedWorkflowItem['image_url'])): ?>
                                                        <img class="workflow-picker-thumb" src="<?= e((string) $selectedWorkflowItem['image_url']) ?>" alt="<?= e((string) $selectedWorkflowItem['name']) ?>">
                                                    <?php else: ?>
                                                        <span class="workflow-picker-thumb workflow-picker-thumb-fallback"><?= e(item_initial((string) $selectedWorkflowItem['name'])) ?></span>
                                                    <?php endif; ?>
                                                    <span>
                                                        <strong><?= e((string) $selectedWorkflowItem['name']) ?></strong>
                                                        <span class="tiny-copy"><?= e((string) $selectedWorkflowItem['sku']) ?><?= !empty($selectedWorkflowItem['barcode']) ? ' · ' . e((string) $selectedWorkflowItem['barcode']) : '' ?> · <?= e((string) $selectedWorkflowItem['unit']) ?></span>
                                                    </span>
                                                </span>
                                            <?php else: ?>
                                                <span class="workflow-picker-placeholder">Select source item first</span>
                                            <?php endif; ?>
                                        </span>
                                    </button>
                                    <div class="workflow-picker-panel" data-workflow-picker-panel hidden>
                                        <input class="workflow-picker-search" type="search" placeholder="Search item" data-workflow-picker-search>
                                        <div class="workflow-picker-options" data-workflow-picker-options></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="tiny-copy" data-workflow-available>-</span>
                            </td>
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

        <button class="primary-button" type="submit" data-handover-submit-label><?= e($handoverSubmitLabel) ?></button>
    </form>
</section>
