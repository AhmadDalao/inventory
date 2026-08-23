<?php
declare(strict_types=1);

$rows = is_array($rows ?? null) ? $rows : [];
$unresolvedOnly = (bool) ($unresolvedOnly ?? true);
$userId = (int) (Auth::user()['id'] ?? 0);
$discardableStatuses = ['paused', 'unknown_code', 'inactive_session', 'item_not_eligible', 'wrong_handover'];
?>
<section class="page-head wristband-page-head">
    <div class="page-head-copy">
        <p class="eyebrow">Manual evidence review</p>
        <h3 class="page-head-title"><?= ui_icon('audit') ?><span>Wristband Exceptions</span></h3>
        <p class="page-head-subtitle">Resolve paused and invalid events without silently applying them after API checking resumes.</p>
    </div>
</section>

<?php require __DIR__ . '/_nav.php'; ?>

<section class="panel filter-panel wristband-exception-filter">
    <div class="chip-row">
        <a class="stat-chip filter-chip<?= $unresolvedOnly ? ' filter-chip-active' : '' ?>" href="/wristbands/exceptions?scope=unresolved">Unresolved</a>
        <a class="stat-chip filter-chip<?= !$unresolvedOnly ? ' filter-chip-active' : '' ?>" href="/wristbands/exceptions?scope=all">Complete History</a>
    </div>
    <p>Paused events require a deliberate accept or discard decision. Nothing is replayed automatically.</p>
</section>

<section class="panel data-table-shell">
    <div class="table-shell-head">
        <div class="table-heading">
            <h4><?= $unresolvedOnly ? 'Unresolved Events' : 'API Event History' ?></h4>
            <span class="table-count-badge"><?= number_format(count($rows)) ?></span>
        </div>
        <p>Codes are masked. The API payload stored for audit never includes the plaintext wristband code.</p>
    </div>
    <div class="table-wrap wristband-table-scroll">
        <table class="data-table wristband-table wristband-exception-table">
            <thead>
            <tr>
                <th>Received</th>
                <th>Code</th>
                <th>Status</th>
                <th>Storage / Session</th>
                <th>Item / Handover</th>
                <th>Resolution</th>
                <th>Action</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="7" class="empty-cell">No wristband events match this view.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $status = (string) $row['status'];
                    $canControl = user_is_global_owner($userId)
                        || ((int) ($row['storage_id'] ?? 0) > 0 && storage_is_owned_by_user((int) $row['storage_id'], $userId));
                    $canAccept = $canControl && $status === 'paused' && (string) ($row['session_status'] ?? '') === 'active' && empty($row['resolved_at']);
                    $canDiscard = $canControl && in_array($status, $discardableStatuses, true) && empty($row['resolved_at']);
                    $canReverse = user_is_global_owner($userId) && Auth::hasPermission('wristbands.reverse') && $status === 'accepted';
                    $statusClass = $status === 'accepted' ? 'pill-active' : (in_array($status, ['discarded', 'reversed', 'duplicate'], true) ? 'pill-muted' : 'pill-danger');
                    ?>
                    <tr>
                        <td><?= e(format_datetime_display((string) $row['received_at'])) ?><small class="table-subtext"><?= e((string) ($row['request_ip'] ?? '')) ?></small></td>
                        <td><code class="wristband-code-mask"><?= e((string) $row['code_masked']) ?></code><small class="table-subtext"><?= e((string) ($row['external_event_id'] ?? 'No external ID')) ?></small></td>
                        <td><span class="status-pill <?= e($statusClass) ?>"><?= e(ucwords(str_replace('_', ' ', $status))) ?></span></td>
                        <td><strong><?= e((string) $row['storage_name']) ?></strong><small class="table-subtext"><?= e((string) ($row['session_number'] ?? 'No active session')) ?></small></td>
                        <td>
                            <?= e((string) ($row['item_name'] ?? 'Not matched')) ?>
                            <?php if (!empty($row['handover_number'])): ?>
                                <small class="table-subtext"><a href="/handovers/<?= (int) $row['handover_id'] ?>"><?= e((string) $row['handover_number']) ?></a></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($row['resolved_at'])): ?>
                                <?= e((string) ($row['resolution_reason'] ?? 'Resolved')) ?>
                                <small class="table-subtext"><?= e((string) ($row['resolved_by_name'] ?? 'System')) ?> · <?= e(format_datetime_display((string) $row['resolved_at'])) ?></small>
                            <?php else: ?>
                                <span class="muted">Awaiting review</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!$canAccept && !$canDiscard && !$canReverse): ?>
                                <span class="muted">No action</span>
                            <?php else: ?>
                                <details class="row-action-menu wristband-action-menu">
                                    <summary aria-label="Exception actions"><?= ui_icon('menu') ?></summary>
                                    <div class="row-action-menu-panel wristband-action-menu-panel">
                                        <?php if ($canAccept): ?>
                                            <form method="post" action="/wristbands/events/<?= (int) $row['id'] ?>/accept">
                                                <?= csrf_field() ?>
                                                <button class="button primary" type="submit">Accept Into Session</button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($canDiscard): ?>
                                            <form method="post" action="/wristbands/events/<?= (int) $row['id'] ?>/discard" class="wristband-action-form">
                                                <?= csrf_field() ?>
                                                <label><span>Discard reason</span><input type="text" name="reason" required placeholder="Why this event is invalid"></label>
                                                <button class="button ghost danger" type="submit">Discard Event</button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($canReverse): ?>
                                            <form method="post" action="/wristbands/events/<?= (int) $row['id'] ?>/reverse" class="wristband-action-form">
                                                <?= csrf_field() ?>
                                                <label><span>Reversal reason</span><input type="text" name="reason" required placeholder="Why accepted evidence is reversed"></label>
                                                <button class="button ghost danger" type="submit">Reverse Accepted Event</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </details>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
