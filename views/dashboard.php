<?php
$topUsageMax = max(array_map(static fn (array $row): float => (float) $row['total_used'], $topUsage ?: [['total_used' => 1]]));
$usageSeries = $usageTrend ?: [];
$usageMax = max(array_map(static fn (array $row): float => (float) $row['total_used'], $usageSeries ?: [['total_used' => 1]]));
$usageTotal = array_reduce($usageSeries, static fn (float $carry, array $row): float => $carry + (float) $row['total_used'], 0.0);
$usageAverage = $usageSeries === [] ? 0.0 : $usageTotal / count($usageSeries);
$usagePeak = ['label' => '-', 'total_used' => 0.0];

foreach ($usageSeries as $row) {
    if ((float) $row['total_used'] > (float) $usagePeak['total_used']) {
        $usagePeak = $row;
    }
}

$chartWidth = 680;
$chartHeight = 220;
$chartPaddingLeft = 22;
$chartPaddingRight = 20;
$chartPaddingTop = 20;
$chartPaddingBottom = 34;
$plotWidth = $chartWidth - $chartPaddingLeft - $chartPaddingRight;
$plotHeight = $chartHeight - $chartPaddingTop - $chartPaddingBottom;
$chartPoints = [];
$totalPoints = count($usageSeries);

foreach ($usageSeries as $index => $row) {
    $x = $totalPoints <= 1
        ? $chartPaddingLeft + ($plotWidth / 2)
        : $chartPaddingLeft + (($plotWidth / max(1, $totalPoints - 1)) * $index);
    $ratio = $usageMax > 0 ? ((float) $row['total_used'] / $usageMax) : 0.0;
    $y = $chartPaddingTop + $plotHeight - ($ratio * $plotHeight);

    $chartPoints[] = [
        'x' => round($x, 2),
        'y' => round($y, 2),
        'label' => $row['label'],
        'total_used' => (float) $row['total_used'],
    ];
}

$linePoints = implode(' ', array_map(
    static fn (array $point): string => $point['x'] . ',' . $point['y'],
    $chartPoints
));

$areaPoints = '';

if ($chartPoints !== []) {
    $areaPoints = 'M ' . $chartPoints[0]['x'] . ' ' . ($chartHeight - $chartPaddingBottom)
        . ' L ' . implode(' L ', array_map(
            static fn (array $point): string => $point['x'] . ' ' . $point['y'],
            $chartPoints
        ))
        . ' L ' . $chartPoints[count($chartPoints) - 1]['x'] . ' ' . ($chartHeight - $chartPaddingBottom)
        . ' Z';
}

$valueMax = max(array_map(static fn (array $row): float => (float) $row['total_value'], $storageValueBreakdown ?: [['total_value' => 1]]));
$valueTotal = array_reduce($storageValueBreakdown, static fn (float $carry, array $row): float => $carry + (float) $row['total_value'], 0.0);
$topValueStorage = $storageValueBreakdown[0] ?? null;
$isDashboardLocationScoped = !empty($filters['storage_id']);
$movementLogQuery = http_build_query(array_filter([
    'storage_id' => $filters['storage_id'],
    'date_from' => $filters['date_from'],
    'date_to' => $filters['date_to'],
], static fn ($value): bool => $value !== '' && $value !== null));
$movementLogUrl = url('/movements' . ($movementLogQuery !== '' ? '?' . $movementLogQuery : ''));
?>

<?php if (!empty($isStaffDashboard)): ?>
    <section class="page-head">
        <div class="page-head-copy">
            <p class="eyebrow">My Day</p>
            <h3 class="page-head-title"><?= ui_icon('dashboard') ?><span><?= e(site_setting('page.dashboard', 'Dashboard')) ?></span></h3>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head">
            <div>
                <p class="eyebrow">Assigned Items</p>
                <h3>Your Handovers</h3>
            </div>
        </div>

        <?php if (($staffCards ?? []) === []): ?>
            <p class="empty-state">No handovers are assigned to you right now.</p>
        <?php else: ?>
            <div class="staff-card-grid">
                <?php foreach ($staffCards as $card): ?>
                    <?php $imageUrl = item_image_url($card['image_path'] ?? null); ?>
                    <?php
                    $baseQuantity = in_array((string) $card['status'], ['awaiting_receipt', 'receipt_review'], true)
                        ? (float) $card['quantity_handed']
                        : (float) $card['quantity_received'];
                    $remaining = round($baseQuantity - (float) $card['quantity_used'] - (float) $card['quantity_returned'], 2);
                    ?>
                    <a class="staff-item-card" href="<?= e(url('/handovers/' . $card['id'])) ?>">
                        <div class="staff-item-card-head">
                            <?php if ($imageUrl): ?>
                                <img class="item-thumb" src="<?= e($imageUrl) ?>" alt="<?= e($card['item_name']) ?>">
                            <?php else: ?>
                                <span class="item-thumb item-thumb-fallback"><?= e(item_initial((string) $card['item_name'])) ?></span>
                            <?php endif; ?>
                            <div>
                                <strong><?= e((string) $card['item_name']) ?></strong>
                                <span class="tiny-copy"><?= e((string) $card['item_sku']) ?></span>
                            </div>
                            <span class="pill pill-<?= e((string) $card['status']) ?>"><?= e(handover_status_label((string) $card['status'])) ?></span>
                        </div>
                        <div class="staff-item-card-metrics">
                            <?php if ((string) $card['status'] === 'awaiting_receipt'): ?>
                                <span><strong><?= format_quantity((float) $card['quantity_handed']) ?></strong> <?= e((string) $card['unit']) ?> waiting for your receipt confirmation</span>
                            <?php elseif ((string) $card['status'] === 'receipt_review'): ?>
                                <span><strong><?= format_quantity((float) $card['quantity_received']) ?></strong> <?= e((string) $card['unit']) ?> reported, waiting for owner confirmation</span>
                            <?php else: ?>
                                <span><strong><?= format_quantity($remaining) ?></strong> <?= e((string) $card['unit']) ?> remaining</span>
                            <?php endif; ?>
                            <span>From <?= e((string) $card['source_storage_name']) ?></span>
                            <span><?= !empty($card['scheduled_for_date']) ? e(date('M j, Y', strtotime((string) $card['scheduled_for_date']))) : e(format_datetime_display((string) $card['issued_at'])) ?></span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
<?php else: ?>

<section class="page-head">
    <div class="page-head-copy">
        <p class="eyebrow"><?= e(site_setting('page.dashboard_eyebrow', 'Overview')) ?></p>
        <h3 class="page-head-title"><?= ui_icon('dashboard') ?><span><?= e(site_setting('page.dashboard', 'Dashboard')) ?></span></h3>
    </div>
</section>

<div class="live-filter-region" data-live-filter-region="dashboard">
<section class="filter-panel">
    <form class="filter-grid dashboard-filter-grid" method="get" action="<?= e(url('/dashboard')) ?>" data-live-filter-form>
        <label class="field">
            <span>Storage</span>
            <select name="storage_id">
                <option value="">All storages</option>
                <?php foreach ($storages as $storage): ?>
                    <option value="<?= e((string) $storage['id']) ?>" <?= selected((string) $storage['id'], (string) ($filters['storage_id'] ?? '')) ?>>
                        <?= e(storage_type_label($storage['storage_type'])) ?> · <?= e($storage['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="field">
            <span>From</span>
            <input type="date" name="date_from" value="<?= e($filters['date_from']) ?>">
        </label>

        <label class="field">
            <span>To</span>
            <input type="date" name="date_to" value="<?= e($filters['date_to']) ?>">
        </label>

        <div class="filter-actions">
            <button class="primary-button" type="submit"><?= ui_icon('filter') ?><span>Filter</span></button>
            <a class="ghost-button" href="<?= e(url('/dashboard')) ?>" data-live-filter-link><?= ui_icon('back') ?><span>Reset</span></a>
        </div>
    </form>

    <div class="chip-row">
        <span class="stat-chip"><?= e($filterLabels['storage']) ?></span>
        <span class="stat-chip"><?= e($filterLabels['date']) ?></span>
    </div>
</section>

<section class="metric-grid">
    <article class="metric-card">
        <span class="metric-card-icon"><?= ui_icon('items') ?><span><?= e(site_setting('metric.items_total', 'Total Active Items')) ?></span></span>
        <strong><?= number_format($metrics['items_total']) ?></strong>
    </article>
    <article class="metric-card">
        <span class="metric-card-icon"><?= ui_icon('storages') ?><span><?= e(site_setting('metric.storages_total', 'Active Storages')) ?></span></span>
        <strong><?= number_format($metrics['storages_total']) ?></strong>
    </article>
    <article class="metric-card">
        <span class="metric-card-icon"><?= ui_icon('storages') ?><span><?= e(site_setting('metric.warehouses_total', 'Active Warehouses')) ?></span></span>
        <strong><?= number_format($metrics['warehouses_total']) ?></strong>
    </article>
    <article class="metric-card">
        <span class="metric-card-icon"><?= ui_icon('dashboard') ?><span><?= e(site_setting('metric.units_total', 'Total Units In Stock')) ?></span></span>
        <strong><?= format_quantity($metrics['units_total']) ?></strong>
    </article>
    <article class="metric-card">
        <span class="metric-card-icon"><?= ui_icon('flash') ?><span><?= e(site_setting('metric.low_stock', 'Low Stock Items')) ?></span></span>
        <strong><?= number_format($metrics['low_stock']) ?></strong>
    </article>
    <article class="metric-card">
        <span class="metric-card-icon"><?= ui_icon('value') ?><span><?= e(site_setting('metric.inventory_value', 'Inventory Value')) ?></span></span>
        <strong><?= format_money($metrics['inventory_value']) ?></strong>
    </article>
    <article class="metric-card metric-card-wide">
        <span class="metric-card-icon"><?= ui_icon('movements') ?><span><?= e(site_setting('metric.used_last_30', 'Units Used In Last 30 Days')) ?></span></span>
        <strong><?= format_quantity($metrics['used_last_30']) ?></strong>
        <span class="metric-card-note"><?= e($filterLabels['date']) ?></span>
    </article>
    <?php if (Auth::hasPermission('requests.view')): ?>
        <article class="metric-card">
            <span class="metric-card-icon"><?= ui_icon('requests') ?><span><?= e(site_setting('metric.requests_open', 'Open Requests')) ?></span></span>
            <strong><?= number_format((int) ($workflowSnapshot['open_requests'] ?? 0)) ?></strong>
        </article>
    <?php endif; ?>
    <?php if (Auth::hasPermission('handovers.view')): ?>
        <article class="metric-card">
            <span class="metric-card-icon"><?= ui_icon('handover') ?><span><?= e(site_setting('metric.handovers_open', 'Open Handovers')) ?></span></span>
            <strong><?= number_format((int) ($workflowSnapshot['open_handovers'] ?? 0)) ?></strong>
        </article>
    <?php endif; ?>
    <?php if (Auth::hasPermission('purchases.view')): ?>
        <article class="metric-card">
            <span class="metric-card-icon"><?= ui_icon('purchases') ?><span><?= e(site_setting('metric.purchases_open', 'Open Purchases')) ?></span></span>
            <strong><?= number_format((int) ($workflowSnapshot['open_purchases'] ?? 0)) ?></strong>
        </article>
        <article class="metric-card metric-card-wide">
            <span class="metric-card-icon"><?= ui_icon('supplier') ?><span><?= e(site_setting('metric.purchase_receiving', 'Purchases Pending Receiving')) ?></span></span>
            <strong><?= number_format((int) ($workflowSnapshot['pending_purchase_receiving'] ?? 0)) ?></strong>
            <span class="metric-card-note"><?= number_format((int) ($workflowSnapshot['pending_purchase_approvals'] ?? 0)) ?> waiting approval</span>
        </article>
    <?php endif; ?>
    <?php if (Auth::hasPermission('stocktakes.view')): ?>
        <article class="metric-card">
            <span class="metric-card-icon"><?= ui_icon('stocktakes') ?><span>Open Stocktakes</span></span>
            <strong><?= number_format((int) ($operationalSnapshot['open_stocktakes'] ?? 0)) ?></strong>
            <span class="metric-card-note"><?= number_format((int) ($operationalSnapshot['pending_stocktake_approvals'] ?? 0)) ?> waiting approval</span>
        </article>
    <?php endif; ?>
    <?php if (Auth::hasPermission('reorder.view')): ?>
        <article class="metric-card metric-card-wide">
            <span class="metric-card-icon"><?= ui_icon('reorder') ?><span>Reorder Pressure</span></span>
            <strong><?= number_format((int) ($operationalSnapshot['reorder_lines'] ?? 0)) ?></strong>
            <span class="metric-card-note"><?= format_money((float) ($operationalSnapshot['reorder_value'] ?? 0)) ?> suggested value</span>
        </article>
    <?php endif; ?>
</section>

<section class="dashboard-graph-grid">
    <article class="panel chart-panel">
        <div class="panel-head">
            <div class="panel-head-copy">
                <p class="eyebrow">Usage</p>
                <h3><?= e(site_setting('dashboard.usage_chart', '7 Day Usage Trend')) ?></h3>
            </div>
            <span class="subtle-label"><?= e($filterLabels['trend']) ?></span>
        </div>

        <?php if ($chartPoints === []): ?>
            <p class="empty-state">No usage data yet.</p>
        <?php else: ?>
            <div class="trend-chart-shell">
                <div class="trend-chart">
                    <svg viewBox="0 0 <?= $chartWidth ?> <?= $chartHeight ?>" role="img" aria-label="Usage trend for the last seven days">
                        <?php for ($step = 0; $step <= 4; $step += 1): ?>
                            <?php $gridY = $chartPaddingTop + (($plotHeight / 4) * $step); ?>
                            <line class="trend-chart-grid" x1="<?= e((string) $chartPaddingLeft) ?>" y1="<?= e((string) round($gridY, 2)) ?>" x2="<?= e((string) ($chartWidth - $chartPaddingRight)) ?>" y2="<?= e((string) round($gridY, 2)) ?>"></line>
                        <?php endfor; ?>

                        <?php if ($areaPoints !== ''): ?>
                            <path class="trend-chart-area" d="<?= e($areaPoints) ?>"></path>
                            <polyline class="trend-chart-line" points="<?= e($linePoints) ?>"></polyline>
                        <?php endif; ?>

                        <?php foreach ($chartPoints as $point): ?>
                            <circle class="trend-chart-dot" cx="<?= e((string) $point['x']) ?>" cy="<?= e((string) $point['y']) ?>" r="5"></circle>
                        <?php endforeach; ?>
                    </svg>
                </div>

                <div class="trend-chart-labels">
                    <?php foreach ($chartPoints as $point): ?>
                        <div class="trend-chart-label">
                            <strong><?= e($point['label']) ?></strong>
                            <span><?= format_quantity($point['total_used']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="chart-stat-row">
            <article class="chart-stat">
                <span>Total used</span>
                <strong><?= format_quantity($usageTotal) ?></strong>
            </article>
            <article class="chart-stat">
                <span>Average per day</span>
                <strong><?= format_quantity($usageAverage) ?></strong>
            </article>
            <article class="chart-stat">
                <span>Peak day</span>
                <strong><?= $usagePeak['total_used'] > 0 ? e($usagePeak['label']) . ' · ' . format_quantity($usagePeak['total_used']) : 'None yet' ?></strong>
            </article>
        </div>
    </article>

    <article class="panel chart-panel">
        <div class="panel-head">
            <div class="panel-head-copy">
                <p class="eyebrow">Value</p>
                <h3><?= e(site_setting('dashboard.value_chart', 'Value By Location')) ?></h3>
            </div>
            <span class="subtle-label"><?= e($selectedStorage ? $filterLabels['storage'] : 'All storages') ?></span>
        </div>

        <?php if ($storageValueBreakdown === []): ?>
            <p class="empty-state">No active locations yet.</p>
        <?php else: ?>
            <div class="value-bars">
                <?php foreach ($storageValueBreakdown as $storage): ?>
                    <?php
                    $ratio = $valueMax > 0 ? ((float) $storage['total_value'] / $valueMax) : 0.0;
                    $height = (float) $storage['total_value'] > 0 ? max(14, (int) round($ratio * 100)) : 8;
                    ?>
                    <a class="value-bar-card" href="<?= e(url('/storages/' . $storage['id'])) ?>">
                        <span class="value-bar-column">
                            <span class="value-bar-fill" style="height: <?= e((string) $height) ?>%"></span>
                        </span>
                        <strong><?= e($storage['name']) ?></strong>
                        <span class="tiny-copy"><?= e(storage_type_label($storage['storage_type'])) ?></span>
                        <span class="tiny-copy"><?= format_money($storage['total_value']) ?> · <?= format_quantity($storage['total_quantity']) ?> units</span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="chart-stat-row">
            <article class="chart-stat">
                <span>Total visible value</span>
                <strong><?= format_money($valueTotal) ?></strong>
            </article>
            <article class="chart-stat">
                <span>Top location</span>
                <strong><?= $topValueStorage ? e($topValueStorage['name']) : 'None yet' ?></strong>
            </article>
            <article class="chart-stat">
                <span>Top location value</span>
                <strong><?= $topValueStorage ? format_money($topValueStorage['total_value']) : format_money(0) ?></strong>
            </article>
        </div>
    </article>
</section>

<section class="panel-grid">
    <article class="panel">
        <div class="panel-head">
            <div>
                <p class="eyebrow">Action</p>
                <h3><?= e(site_setting('dashboard.low_stock', 'Low Stock Watchlist')) ?></h3>
            </div>
            <a class="text-link" href="<?= e(url('/items')) ?>">View all items</a>
        </div>

        <?php if ($lowStockItems === []): ?>
            <p class="empty-state">Nothing is low right now. Miracles happen.</p>
        <?php else: ?>
            <div class="mini-list">
                <?php foreach ($lowStockItems as $item): ?>
                    <a class="mini-row" href="<?= e(url('/items/' . $item['id'])) ?>">
                        <div>
                            <strong><?= e($item['name']) ?></strong>
                            <span><?= e($item['sku']) ?> · <?= number_format((int) $item['location_count']) ?> location<?= (int) $item['location_count'] === 1 ? '' : 's' ?></span>
                        </div>
                        <div class="align-right">
                            <strong><?= format_quantity($item['current_quantity']) ?> <?= e($item['unit']) ?></strong>
                            <span>Reorder at <?= format_quantity($item['reorder_level']) ?></span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>

    <article class="panel">
        <div class="panel-head">
            <div>
                <p class="eyebrow">Usage</p>
                <h3><?= e(site_setting('dashboard.top_usage', 'Most Used Items')) ?></h3>
            </div>
            <span class="subtle-label"><?= e($filterLabels['date']) ?></span>
        </div>

        <?php if ($topUsage === []): ?>
            <p class="empty-state">No usage recorded yet.</p>
        <?php else: ?>
            <div class="usage-bars">
                <?php foreach ($topUsage as $usage): ?>
                    <?php $width = $topUsageMax > 0 ? max(8, (int) round(((float) $usage['total_used'] / $topUsageMax) * 100)) : 8; ?>
                    <div class="usage-row">
                        <div class="usage-meta">
                            <strong><?= e($usage['name']) ?></strong>
                            <span>Across <?= number_format((int) $usage['location_count']) ?> location<?= (int) $usage['location_count'] === 1 ? '' : 's' ?></span>
                            <span><?= format_quantity($usage['total_used']) ?> <?= e($usage['unit']) ?></span>
                        </div>
                        <div class="usage-bar-track">
                            <div class="usage-bar-fill" style="width: <?= $width ?>%"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>
</section>

<section class="panel-grid workflow-panel-grid">
    <?php if (Auth::hasPermission('requests.view')): ?>
        <article class="panel workflow-card-panel">
            <div class="panel-head">
                <div>
                    <p class="eyebrow">Requests</p>
                    <h3><?= e(site_setting('dashboard.requests', 'Request Queue')) ?></h3>
                </div>
                <a class="text-link" href="<?= e(url('/requests')) ?>">Open requests</a>
            </div>

            <?php if (empty($workflowSnapshot['recent_requests'])): ?>
                <p class="empty-state">No open requests right now.</p>
            <?php else: ?>
                <div class="mini-list workflow-card-list">
                    <?php foreach ($workflowSnapshot['recent_requests'] as $request): ?>
                        <?php $isIssueRequest = (string) ($request['request_mode'] ?? 'transfer') === 'issue'; ?>
                        <a class="mini-row workflow-mini-card" href="<?= e(url('/requests/' . $request['id'])) ?>">
                            <div>
                                <strong><?= e($request['request_number']) ?></strong>
                                <span>
                                    <?= e($request['requester_name']) ?> · <?= e($request['source_storage_name']) ?>
                                    <?= $isIssueRequest ? ' · staff use request' : ' to ' . e((string) $request['destination_storage_name']) ?>
                                </span>
                            </div>
                            <div class="align-right">
                                <strong><?= format_quantity($request['total_requested']) ?></strong>
                                <span class="pill pill-<?= e((string) $request['status']) ?>"><?= e(request_status_label((string) $request['status'])) ?></span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </article>
    <?php endif; ?>

    <?php if (Auth::hasPermission('handovers.view')): ?>
        <article class="panel workflow-card-panel">
            <div class="panel-head">
                <div>
                    <p class="eyebrow">Handovers</p>
                    <h3><?= e(site_setting('dashboard.handovers', 'Open Handovers')) ?></h3>
                </div>
                <a class="text-link" href="<?= e(url('/handovers')) ?>">Open handovers</a>
            </div>

            <?php if (empty($workflowSnapshot['recent_handovers'])): ?>
                <p class="empty-state">No open handovers right now.</p>
            <?php else: ?>
                <div class="mini-list workflow-card-list">
                    <?php foreach ($workflowSnapshot['recent_handovers'] as $handover): ?>
                        <a class="mini-row workflow-mini-card" href="<?= e(url('/handovers/' . $handover['id'])) ?>">
                            <div>
                                <strong><?= e($handover['handover_number']) ?></strong>
                                <span><?= e($handover['recipient_name']) ?> · <?= e($handover['source_storage_name']) ?></span>
                            </div>
                            <div class="align-right">
                                <strong><?= format_quantity($handover['total_handed']) ?></strong>
                                <span class="pill pill-<?= e((string) $handover['status']) ?>"><?= e(handover_status_label((string) $handover['status'])) ?></span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </article>
    <?php endif; ?>

    <?php if (Auth::hasPermission('purchases.view')): ?>
        <article class="panel workflow-card-panel">
            <div class="panel-head">
                <div>
                    <p class="eyebrow">Purchases</p>
                    <h3><?= e(site_setting('dashboard.purchases', 'Purchase Queue')) ?></h3>
                </div>
                <a class="text-link" href="<?= e(url('/purchases')) ?>">Open purchases</a>
            </div>

            <?php if (empty($workflowSnapshot['recent_purchases'])): ?>
                <p class="empty-state">No purchase activity yet.</p>
            <?php else: ?>
                <div class="mini-list workflow-card-list">
                    <?php foreach ($workflowSnapshot['recent_purchases'] as $purchase): ?>
                        <a class="mini-row workflow-mini-card" href="<?= e(url('/purchases/' . $purchase['id'])) ?>">
                            <div>
                                <strong><?= e($purchase['purchase_number']) ?></strong>
                                <span><?= e($purchase['supplier_name']) ?> · <?= e($purchase['storage_name']) ?></span>
                            </div>
                            <div class="align-right">
                                <strong><?= e($purchase['currency']) ?> <?= number_format((float) $purchase['total_value'], 2) ?></strong>
                                <span class="pill pill-<?= e((string) $purchase['status']) ?>"><?= e(purchase_status_label((string) $purchase['status'])) ?></span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </article>
    <?php endif; ?>

    <?php if (Auth::hasPermission('stocktakes.view')): ?>
        <article class="panel workflow-card-panel">
            <div class="panel-head">
                <div>
                    <p class="eyebrow">Counts</p>
                    <h3>Stocktake Queue</h3>
                </div>
                <a class="text-link" href="<?= e(url('/stocktakes')) ?>">Open stocktakes</a>
            </div>

            <?php if (empty($operationalSnapshot['recent_stocktakes'])): ?>
                <p class="empty-state">No open stocktakes right now.</p>
            <?php else: ?>
                <div class="mini-list workflow-card-list">
                    <?php foreach ($operationalSnapshot['recent_stocktakes'] as $stocktake): ?>
                        <?php $variance = (float) $stocktake['total_variance']; ?>
                        <a class="mini-row workflow-mini-card" href="<?= e(url('/stocktakes/' . $stocktake['id'])) ?>">
                            <div>
                                <strong><?= e($stocktake['stocktake_number']) ?></strong>
                                <span><?= e($stocktake['storage_name']) ?> · <?= e(format_datetime_display((string) $stocktake['created_at'])) ?></span>
                            </div>
                            <div class="align-right">
                                <strong class="<?= $variance < 0 ? 'danger-text' : ($variance > 0 ? 'success-text' : '') ?>"><?= format_quantity($variance) ?></strong>
                                <span class="pill pill-<?= e((string) $stocktake['status']) ?>"><?= e(stocktake_status_label((string) $stocktake['status'])) ?></span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </article>
    <?php endif; ?>

    <article class="panel workflow-card-panel">
        <div class="panel-head">
            <div>
                <p class="eyebrow">Inbox</p>
                <h3><?= e(site_setting('dashboard.notifications', 'Notifications')) ?></h3>
            </div>
            <a class="text-link" href="<?= e(url('/notifications')) ?>">View all</a>
        </div>

        <?php if ($dashboardNotifications === []): ?>
            <p class="empty-state">Nothing new right now.</p>
        <?php else: ?>
            <div class="mini-list workflow-card-list dashboard-notification-list">
                <?php foreach ($dashboardNotifications as $notification): ?>
                    <a class="mini-row workflow-mini-card" href="<?= e((string) ($notification['action_url'] ?: '#')) ?>">
                        <div>
                            <strong><?= e((string) $notification['title']) ?></strong>
                            <?php if (!empty($notification['actor_name'])): ?>
                                <span class="tiny-copy">By <?= e((string) $notification['actor_name']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($notification['message'])): ?>
                                <span><?= e((string) $notification['message']) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="align-right">
                            <span class="tiny-copy"><?= e(format_datetime_display((string) $notification['created_at'])) ?></span>
                            <?php if (empty($notification['read_at'])): ?>
                                <span class="pill pill-usage">New</span>
                            <?php endif; ?>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>
</section>

<section class="panel">
    <div class="panel-head">
        <div>
            <p class="eyebrow">Timeline</p>
            <h3><?= e(site_setting('dashboard.recent_activity', 'Recent Activity')) ?></h3>
        </div>
        <a class="text-link" href="<?= e($movementLogUrl) ?>">Open full log</a>
    </div>

    <?php if ($recentActivity === []): ?>
        <p class="empty-state">No movements yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table data-table-mobile">
                <thead>
                <tr>
                    <th>When</th>
                    <th>Item</th>
                    <th>Type</th>
                    <th>Quantity</th>
                    <th><?= $isDashboardLocationScoped ? 'Location Change' : 'Total Change' ?></th>
                    <th><?= $isDashboardLocationScoped ? 'Location Balance' : 'Balance' ?></th>
                    <th>Route</th>
                    <th>By</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($recentActivity as $movement): ?>
                    <tr>
                        <td data-label="When"><?= e(date('M j, Y g:i A', strtotime($movement['used_at']))) ?></td>
                        <td data-label="Item">
                            <a class="cell-link cell-link-compact" href="<?= e(url('/items/' . $movement['item_id'])) ?>">
                                <strong><?= e($movement['item_name']) ?></strong>
                                <div class="tiny-copy"><?= e($movement['sku']) ?></div>
                            </a>
                        </td>
                        <td data-label="Type"><span class="pill pill-<?= e($movement['movement_type']) ?>"><?= e(ucfirst($movement['movement_type'])) ?></span></td>
                        <td data-label="Quantity"><?= format_quantity($movement['movement_quantity'] ?? abs((float) $movement['quantity_delta'])) ?> <?= e($movement['unit']) ?></td>
                        <td data-label="<?= $isDashboardLocationScoped ? 'Location Change' : 'Total Change' ?>"><?= format_quantity($isDashboardLocationScoped ? $movement['location_change'] : $movement['quantity_delta']) ?> <?= e($movement['unit']) ?></td>
                        <td data-label="<?= $isDashboardLocationScoped ? 'Location Balance' : 'Balance' ?>"><?= format_quantity($isDashboardLocationScoped ? $movement['location_balance_after'] : $movement['balance_after']) ?> <?= e($movement['unit']) ?></td>
                        <td data-label="Route">
                            <?= e($movement['source_storage_name'] ?: '-') ?>
                            <div class="tiny-copy"><?= e($movement['destination_storage_name'] ?: '-') ?></div>
                            <?php if ($isDashboardLocationScoped): ?>
                                <div class="tiny-copy"><?= e($movement['location_scope_label']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td data-label="By"><?= e($movement['user_name'] ?: 'System') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
</div>
<?php endif; ?>
