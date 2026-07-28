<?php
$handover = $handoverRecord;
$returnStatus = (string) $custodyReturn['status'];
$totalServiceable = 0.0;
$totalDamaged = 0.0;
$totalConsumed = 0.0;
$totalLost = 0.0;
$totalHeld = 0.0;

foreach ($returnLines as $line) {
    $totalServiceable += (float) $line['serviceable_quantity'];
    $totalDamaged += (float) $line['damaged_quantity'];
    $totalConsumed += (float) $line['consumed_quantity'];
    $totalLost += (float) $line['lost_quantity'];
    $totalHeld += handover_line_held_quantity($line);
}

$statusClass = match ($returnStatus) {
    'approved' => 'pill-approved',
    'submitted' => 'pill-pending',
    'rejected' => 'pill-rejected',
    default => 'pill-draft',
};
?>

<section class="page-head">
    <div class="page-head-copy">
        <p class="eyebrow">Staff Custody Return</p>
        <h3 class="page-head-title"><?= ui_icon('handover') ?><span><?= e($custodyReturn['return_number']) ?></span></h3>
        <p class="page-head-subtitle">
            Handover <?= e($handover['handover_number']) ?> · <?= e($handover['recipient_name']) ?>
        </p>
    </div>
    <div class="page-actions">
        <a class="ghost-button" href="<?= e(url('/handovers/' . $handover['id'])) ?>"><?= ui_icon('back') ?><span>Back To Custody</span></a>
    </div>
</section>

<?php if ($returnStatus === 'rejected' && !empty($custodyReturn['rejection_notes'])): ?>
    <section class="notice danger-notice">
        <strong>Issuer correction requested</strong>
        <p><?= nl2br(e((string) $custodyReturn['rejection_notes'])) ?></p>
    </section>
<?php endif; ?>

<section class="metric-grid compact-grid">
    <article class="metric-card metric-card-highlight">
        <span class="metric-card-icon"><?= ui_icon('handover') ?><span>Status</span></span>
        <strong><?= e(handover_custody_return_status_label($returnStatus)) ?></strong>
        <small>Return date <?= e((string) ($custodyReturn['return_date'] ?: 'Not set')) ?></small>
    </article>
    <article class="metric-card">
        <span class="metric-card-icon"><?= ui_icon('storages') ?><span>Serviceable</span></span>
        <strong><?= format_quantity($totalServiceable) ?></strong>
        <small>Returns to <?= e($handover['source_storage_name']) ?></small>
    </article>
    <article class="metric-card">
        <span class="metric-card-icon"><?= ui_icon('flash') ?><span>Damaged</span></span>
        <strong><?= format_quantity($totalDamaged) ?></strong>
        <small>Moves to quarantine</small>
    </article>
    <article class="metric-card">
        <span class="metric-card-icon"><?= ui_icon('movements') ?><span>Written Off</span></span>
        <strong><?= format_quantity($totalConsumed + $totalLost) ?></strong>
        <small>Consumed or lost</small>
    </article>
</section>

<section class="panel data-table-shell">
    <div class="table-shell-head">
        <div class="table-heading">
            <strong><?= ui_icon('items') ?><span>Return Outcomes</span></strong>
            <span class="table-count-badge"><?= number_format(count($returnLines)) ?></span>
        </div>
        <p class="table-shell-copy">Classify only what is being returned now. Anything not entered remains assigned to the staff member.</p>
    </div>

    <?php if ($canEditReturn): ?>
        <form class="stack-form" method="post" action="<?= e(url('/handovers/' . $handover['id'] . '/custody-returns/' . $custodyReturn['id'] . '/submit')) ?>" enctype="multipart/form-data" data-live-action-form>
            <?= csrf_field() ?>
            <div class="form-grid two-column-grid">
                <label class="field">
                    <span>Return Date</span>
                    <input type="date" name="return_date" value="<?= e((string) ($custodyReturn['return_date'] ?: date('Y-m-d'))) ?>" required>
                </label>
                <label class="field">
                    <span>Return Notes</span>
                    <input type="text" name="notes" value="<?= e((string) ($custodyReturn['notes'] ?? '')) ?>" placeholder="Optional batch note">
                </label>
            </div>
    <?php endif; ?>

    <div class="table-wrap">
        <table class="data-table data-table-mobile">
            <thead>
            <tr>
                <th>Item</th>
                <th>Still Held</th>
                <th>Serviceable</th>
                <th>Damaged</th>
                <th>Consumed</th>
                <th>Lost</th>
                <th>Evidence / Notes</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($returnLines as $line): ?>
                <?php
                $lineId = (int) $line['id'];
                $unit = (string) ($line['unit'] ?: 'pcs');
                $imageUrl = item_image_url($line['image_path'] ?? null);
                $proofs = $proofsByLine[$lineId] ?? [];
                ?>
                <tr>
                    <td data-label="Item">
                        <a class="item-table-cell cell-link" href="<?= e(url('/items/' . $line['item_id'])) ?>">
                            <?php if ($imageUrl): ?>
                                <img class="item-thumb expandable-image" src="<?= e($imageUrl) ?>" alt="<?= e($line['item_name']) ?>" data-expand-image tabindex="0">
                            <?php else: ?>
                                <span class="item-thumb item-thumb-fallback"><?= e(item_initial($line['item_name'])) ?></span>
                            <?php endif; ?>
                            <div>
                                <strong><?= e($line['item_name']) ?></strong>
                                <div class="tiny-copy"><?= e($line['item_sku']) ?> · <?= e($unit) ?></div>
                            </div>
                        </a>
                    </td>
                    <td data-label="Still Held"><strong><?= format_quantity(handover_line_held_quantity($line)) ?> <?= e($unit) ?></strong></td>
                    <?php foreach ([
                        'serviceable_quantity' => 'Serviceable',
                        'damaged_quantity' => 'Damaged',
                        'consumed_quantity' => 'Consumed',
                        'lost_quantity' => 'Lost',
                    ] as $field => $label): ?>
                        <td data-label="<?= e($label) ?>">
                            <?php if ($canEditReturn): ?>
                                <label class="field compact-field">
                                    <span class="sr-only"><?= e($label . ' ' . $line['item_name']) ?></span>
                                    <input type="number" min="0" max="<?= e((string) handover_line_held_quantity($line)) ?>" step="0.01" name="<?= e($field) ?>[<?= $lineId ?>]" value="<?= e(format_quantity($line[$field] ?? 0)) ?>">
                                </label>
                            <?php else: ?>
                                <?= format_quantity($line[$field] ?? 0) ?> <?= e($unit) ?>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                    <td data-label="Evidence / Notes">
                        <?php if ($canEditReturn): ?>
                            <label class="field compact-field">
                                <span>Damage Photo</span>
                                <input type="file" name="damage_proof[<?= $lineId ?>]" accept="image/jpeg,image/png,image/webp">
                            </label>
                            <label class="field compact-field">
                                <span class="sr-only">Line notes</span>
                                <input type="text" name="line_notes[<?= $lineId ?>]" value="<?= e((string) ($line['notes'] ?? '')) ?>" placeholder="Required for lost items">
                            </label>
                        <?php else: ?>
                            <?php if (!empty($line['notes'])): ?>
                                <p><?= e((string) $line['notes']) ?></p>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($proofs !== []): ?>
                            <div class="button-row">
                                <?php foreach ($proofs as $proof): ?>
                                    <a class="text-link" href="<?= e(url('/workflow-documents/' . $proof['id'] . '/view')) ?>" target="_blank" rel="noopener">View damage proof</a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($canEditReturn): ?>
            <div class="notice">
                <strong>Evidence rules</strong>
                <p>Damaged quantities require an image. Lost quantities require an explanation. Unreported quantities remain held by the employee.</p>
            </div>
            <button class="primary-button" type="submit" data-confirm="Submit these custody return quantities to the issuer for approval?"><?= ui_icon('handover') ?><span>Submit Return For Review</span></button>
        </form>
    <?php endif; ?>
</section>

<?php if ($canReviewReturn): ?>
    <section class="panel">
        <div class="section-heading">
            <div>
                <p class="eyebrow">Issuer Review</p>
                <h4>Approve Stock Outcomes</h4>
            </div>
        </div>
        <p class="muted-copy">Approval posts every outcome now. Rejecting changes no stock and sends the return back to the employee.</p>
        <form class="stack-form" method="post" action="<?= e(url('/handovers/' . $handover['id'] . '/custody-returns/' . $custodyReturn['id'] . '/approve')) ?>" data-live-action-form>
            <?= csrf_field() ?>
            <label class="field">
                <span>Approval Notes</span>
                <textarea name="review_notes" rows="3" placeholder="Optional review note"></textarea>
            </label>
            <button class="primary-button" type="submit" data-confirm="Approve this return and post its stock movements?">Approve Return</button>
        </form>
        <form class="stack-form" method="post" action="<?= e(url('/handovers/' . $handover['id'] . '/custody-returns/' . $custodyReturn['id'] . '/reject')) ?>" data-live-action-form>
            <?= csrf_field() ?>
            <label class="field">
                <span>Correction Required</span>
                <textarea name="rejection_notes" rows="3" placeholder="Explain what the employee must correct" required></textarea>
            </label>
            <button class="ghost-button danger-button" type="submit" data-confirm="Reject this return without changing stock?">Reject For Correction</button>
        </form>
    </section>
<?php endif; ?>

<?php if ($returnStatus === 'approved'): ?>
    <section class="panel">
        <div class="detail-grid">
            <dl class="detail-list">
                <div>
                    <dt>Reviewed By</dt>
                    <dd><?= e((string) ($custodyReturn['reviewed_by_name'] ?? 'Issuer')) ?></dd>
                </div>
                <div>
                    <dt>Reviewed</dt>
                    <dd><?= !empty($custodyReturn['reviewed_at']) ? e(format_datetime_display((string) $custodyReturn['reviewed_at'])) : '-' ?></dd>
                </div>
                <div>
                    <dt>Review Notes</dt>
                    <dd><?= e((string) ($custodyReturn['review_notes'] ?: 'No notes.')) ?></dd>
                </div>
            </dl>
            <?php if ($canRequestReplacement): ?>
                <form class="stack-form" method="post" action="<?= e(url('/handovers/' . $handover['id'] . '/custody-returns/' . $custodyReturn['id'] . '/replacement')) ?>" data-live-action-form>
                    <?= csrf_field() ?>
                    <div class="copy-context-card">
                        <strong>Replacement Required?</strong>
                        <p>Create a linked request for damaged and lost quantities. No replacement stock moves until the normal issue approval.</p>
                    </div>
                    <button class="primary-button" type="submit" data-confirm="Create a linked replacement request?"><?= ui_icon('plus') ?><span>Request Replacement</span></button>
                </form>
            <?php elseif (!empty($custodyReturn['replacement_handover_id'])): ?>
                <div class="copy-context-card">
                    <strong>Replacement Request</strong>
                    <p><a class="text-link" href="<?= e(url('/handovers/' . $custodyReturn['replacement_handover_id'])) ?>"><?= e((string) ($custodyReturn['replacement_handover_number'] ?? 'Open linked handover')) ?></a></p>
                </div>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>
