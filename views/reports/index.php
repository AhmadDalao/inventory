<section class="page-head">
    <div class="page-head-copy">
        <p class="eyebrow"><?= e(site_setting('page.reports_eyebrow', 'Export shortcuts')) ?></p>
        <h3 class="page-head-title"><?= ui_icon('reports') ?><span><?= e(site_setting('page.reports', 'Reports')) ?></span></h3>
    </div>
    <div class="page-actions">
        <a class="ghost-button" href="<?= e(url('/dashboard')) ?>"><?= ui_icon('dashboard') ?><span>Dashboard</span></a>
    </div>
</section>

<section class="panel reports-hero-panel">
    <div>
        <p class="eyebrow">Preset reports</p>
        <h3>Pick the answer, download the CSV</h3>
        <p>These cards reuse existing exports, so permissions and filters stay consistent with the source modules.</p>
    </div>
    <div class="reports-hero-stat">
        <span>Available Presets</span>
        <strong><?= number_format(array_sum(array_map('count', $groups))) ?></strong>
    </div>
</section>

<?php if ($groups === []): ?>
    <section class="panel">
        <p class="empty-state">No report presets are available for your current permissions.</p>
    </section>
<?php endif; ?>

<?php foreach ($groups as $groupName => $cards): ?>
    <section class="reports-group">
        <div class="section-heading-row">
            <div>
                <p class="eyebrow">Report Group</p>
                <h3><?= e((string) $groupName) ?></h3>
            </div>
        </div>
        <div class="reports-card-grid">
            <?php foreach ($cards as $card): ?>
                <article class="report-preset-card">
                    <div class="report-preset-head">
                        <span class="report-preset-icon"><?= ui_icon((string) $card['icon']) ?></span>
                        <span class="pill pill-muted"><?= e((string) $card['badge']) ?></span>
                    </div>
                    <h4><?= e((string) $card['title']) ?></h4>
                    <p><?= e((string) $card['copy']) ?></p>
                    <div class="report-preset-actions">
                        <?php if (!empty($card['download_url'])): ?>
                            <a class="primary-button" href="<?= e((string) $card['download_url']) ?>"><?= ui_icon('export') ?><span>Download CSV</span></a>
                        <?php endif; ?>
                        <a class="ghost-button" href="<?= e((string) $card['source_url']) ?>">Open Source</a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
<?php endforeach; ?>
