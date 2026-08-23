<?php
declare(strict_types=1);

$sessions = is_array($sessions ?? null) ? $sessions : [];
$userId = (int) (Auth::user()['id'] ?? 0);
$counts = ['active' => 0, 'paused' => 0, 'manual_only' => 0, 'closed' => 0];
foreach ($sessions as $session) {
    $status = (string) ($session['status'] ?? '');
    if (isset($counts[$status])) {
        $counts[$status]++;
    }
}
?>
<section class="page-head wristband-page-head">
    <div class="page-head-copy">
        <p class="eyebrow">Handover evidence control</p>
        <h3 class="page-head-title"><?= ui_icon('handover') ?><span>Wristband Sessions</span></h3>
        <p class="page-head-subtitle">Pause API collection or fall back to manual reconciliation without changing handover stock.</p>
    </div>
</section>

<?php require __DIR__ . '/_nav.php'; ?>

<section class="metric-grid wristband-metric-grid" aria-label="Wristband session totals">
    <?php foreach ([
        ['label' => 'Active', 'value' => $counts['active'], 'tone' => 'success'],
        ['label' => 'Paused', 'value' => $counts['paused'], 'tone' => 'warning'],
        ['label' => 'Manual Only', 'value' => $counts['manual_only'], 'tone' => 'neutral'],
        ['label' => 'Closed', 'value' => $counts['closed'], 'tone' => 'dark'],
    ] as $metric): ?>
        <article class="metric-card wristband-metric-card is-<?= e($metric['tone']) ?>">
            <span><?= e($metric['label']) ?></span>
            <strong><?= number_format((int) $metric['value']) ?></strong>
        </article>
    <?php endforeach; ?>
</section>

<section class="panel wristband-safety-note">
    <div><?= ui_icon('audit') ?></div>
    <div>
        <strong>Evidence only</strong>
        <p>API check-ins never deduct stock. The normal handover return, usage report, and owner approval remain authoritative.</p>
    </div>
</section>

<section class="panel data-table-shell">
    <div class="table-shell-head">
        <div class="table-heading">
            <h4>Tracking Sessions</h4>
            <span class="table-count-badge"><?= number_format(count($sessions)) ?></span>
        </div>
        <p>Only one active or paused API Audit session is allowed per storage.</p>
    </div>
    <div class="table-wrap wristband-table-scroll">
        <table class="data-table wristband-table wristband-session-table">
            <thead>
            <tr>
                <th>Session</th>
                <th>Handover</th>
                <th>Storage</th>
                <th>Status</th>
                <th>Accepted</th>
                <th>Exceptions</th>
                <th>Started</th>
                <th>Control</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($sessions === []): ?>
                <tr><td colspan="8" class="empty-cell">No wristband API Audit sessions yet.</td></tr>
            <?php else: ?>
                <?php foreach ($sessions as $session): ?>
                    <?php
                    $status = (string) $session['status'];
                    $canControl = wristband_user_can_control_session($session, $userId);
                    $statusClass = match ($status) {
                        'active' => 'pill-active',
                        'paused' => 'pill-warning',
                        'manual_only' => 'pill-muted',
                        default => 'pill-muted',
                    };
                    ?>
                    <tr>
                        <td><strong><?= e((string) $session['session_number']) ?></strong><small class="table-subtext"><?= e((string) ($session['integration_name'] ?? 'KONA check-in')) ?></small></td>
                        <td><a href="/handovers/<?= (int) $session['handover_id'] ?>"><?= e((string) $session['handover_number']) ?></a><small class="table-subtext"><?= e(handover_status_label((string) $session['handover_status'])) ?></small></td>
                        <td><?= e((string) $session['storage_name']) ?></td>
                        <td><span class="status-pill <?= e($statusClass) ?>"><?= e(ucwords(str_replace('_', ' ', $status))) ?></span></td>
                        <td><strong><?= number_format((int) $session['accepted_events']) ?></strong></td>
                        <td>
                            <?php if ((int) $session['unresolved_events'] > 0): ?>
                                <a class="status-pill pill-danger" href="/wristbands/exceptions"><?= number_format((int) $session['unresolved_events']) ?> unresolved</a>
                            <?php else: ?>
                                <span class="status-pill pill-active">Clear</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e(format_datetime_display((string) $session['started_at'])) ?><small class="table-subtext"><?= e((string) ($session['started_by_name'] ?? 'System')) ?></small></td>
                        <td>
                            <?php if (!$canControl || !in_array($status, ['active', 'paused'], true)): ?>
                                <span class="muted">No action</span>
                            <?php else: ?>
                                <details class="row-action-menu wristband-action-menu">
                                    <summary aria-label="Session actions"><?= ui_icon('menu') ?></summary>
                                    <div class="row-action-menu-panel wristband-action-menu-panel">
                                        <?php if ($status === 'active'): ?>
                                            <form method="post" action="/wristbands/sessions/<?= (int) $session['id'] ?>/pause" class="wristband-action-form">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="return_to" value="/wristbands/sessions">
                                                <label><span>Pause reason</span><input type="text" name="reason" required placeholder="Why API checking is paused"></label>
                                                <button class="button ghost" type="submit">Pause API Check</button>
                                            </form>
                                        <?php else: ?>
                                            <form method="post" action="/wristbands/sessions/<?= (int) $session['id'] ?>/resume">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="return_to" value="/wristbands/sessions">
                                                <button class="button primary" type="submit">Resume API Check</button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="post" action="/wristbands/sessions/<?= (int) $session['id'] ?>/manual" class="wristband-action-form">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="return_to" value="/wristbands/sessions">
                                            <label><span>Fallback reason</span><input type="text" name="reason" required placeholder="Why this session will stay manual"></label>
                                            <button class="button ghost danger" type="submit">Switch to Manual Only</button>
                                        </form>
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
