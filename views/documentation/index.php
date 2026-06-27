<?php
$importantSections = $importantSections ?? [];
$departmentGuides = $departmentGuides ?? [];
$sectionCount = count($sections);
$searchableCount = $sectionCount + count($importantSections) + count($departmentGuides);
$canOpenRoute = static function (string $route): bool {
    if ($route === '/dashboard') {
        return Auth::hasPermission('dashboard.view');
    }

    if ($route === '/requests') {
        return Auth::hasPermission('requests.view');
    }

    if ($route === '/handovers') {
        return Auth::hasPermission('handovers.view');
    }

    if ($route === '/files') {
        return file_library_can_access();
    }

    if (starts_with($route, '/storages')) {
        return !Auth::isStaff() && Auth::hasPermission('storages.view');
    }

    if (starts_with($route, '/items')) {
        return !Auth::isStaff() && Auth::hasPermission('items.view');
    }

    if ($route === '/movements') {
        return !Auth::isStaff() && Auth::hasPermission('movements.view');
    }

    if ($route === '/purchases') {
        return !Auth::isStaff() && Auth::hasPermission('purchases.view');
    }

    if ($route === '/suppliers') {
        return !Auth::isStaff() && Auth::hasPermission('suppliers.view');
    }

    if ($route === '/stocktakes') {
        return !Auth::isStaff() && Auth::hasPermission('stocktakes.view');
    }

    if ($route === '/reorder') {
        return !Auth::isStaff() && Auth::hasPermission('reorder.view');
    }

    if ($route === '/labels') {
        return !Auth::isStaff() && Auth::hasPermission('labels.view');
    }

    if ($route === '/users') {
        return !Auth::isStaff() && Auth::hasPermission('users.view');
    }

    if ($route === '/settings/site') {
        return !Auth::isStaff() && Auth::hasPermission('settings.view');
    }

    if ($route === '/audit-log') {
        return !Auth::isStaff() && Auth::hasPermission('audit.view');
    }

    if ($route === '/email-logs') {
        return !Auth::isStaff() && Auth::hasPermission('email_logs.view');
    }

    return false;
};
?>

<section class="page-head">
    <div class="page-head-copy">
        <p class="eyebrow"><?= e(site_setting('page.documentation_eyebrow', 'Employee training')) ?></p>
        <h3 class="page-head-title"><?= ui_icon('documentation') ?><span><?= e(site_setting('page.documentation', 'Documentation')) ?></span></h3>
    </div>
    <div class="page-actions">
        <span class="stat-chip"><?= number_format($sectionCount) ?> guides</span>
    </div>
</section>

<section class="documentation-hero panel">
    <div>
        <span class="metric-card-icon"><?= ui_icon('documentation') ?><span>System guide</span></span>
        <h3>Search the exact workflow before touching stock.</h3>
        <p>This page explains the system by feature, department, and admin role so employees know what they can do, what needs approval, and where each action changes inventory.</p>
        <div class="documentation-hero-stats">
            <span><?= number_format(count($importantSections)) ?> important sections</span>
            <span><?= number_format(count($departmentGuides)) ?> department guides</span>
            <span><?= number_format($sectionCount) ?> feature guides</span>
        </div>
    </div>
    <label class="documentation-search field">
        <span>Search documentation</span>
        <input type="search" placeholder="Search CFO, staff, approval, files, storage, purchase..." data-documentation-search>
    </label>
</section>

<div class="documentation-layout" data-documentation-root>
    <aside class="documentation-nav panel">
        <div class="table-heading">
            <strong><?= ui_icon('search') ?><span>Search Results</span></strong>
            <span class="table-count-badge" data-documentation-count><?= number_format($searchableCount) ?></span>
        </div>
        <p class="documentation-nav-note" data-documentation-status>
            Showing important sections, department guides, and full feature guides.
        </p>
        <div class="documentation-reader" data-documentation-reader>
            <p class="eyebrow">Currently reading</p>
            <strong data-documentation-current-title>Start with any guide</strong>
            <span data-documentation-current-meta>Scroll the manual to track your place.</span>
            <div class="documentation-progress" aria-hidden="true">
                <span data-documentation-progress></span>
            </div>
        </div>
        <div class="documentation-nav-list">
            <?php foreach ($sections as $section): ?>
                <a href="#doc-<?= e($section['slug']) ?>" data-documentation-nav-link><?= ui_icon($section['icon']) ?><span><?= e($section['title']) ?></span></a>
            <?php endforeach; ?>
        </div>
    </aside>

    <section class="documentation-stack">
        <p class="empty-state documentation-empty" data-documentation-empty hidden>No documentation sections match your search.</p>

        <?php if ($importantSections): ?>
            <section class="documentation-group">
                <div class="documentation-section-heading">
                    <div>
                        <p class="eyebrow">Start here</p>
                        <h3>Important Sections</h3>
                    </div>
                    <span>Fast paths for the workflows people actually use every day.</span>
                </div>
                <div class="documentation-important-grid">
                    <?php foreach ($importantSections as $important): ?>
                        <?php
                        $tags = $important['tags'] ?? [];
                        $searchText = strtolower(trim(implode(' ', [
                            $important['title'],
                            $important['summary'],
                            implode(' ', $tags),
                        ])));
                        ?>
                        <a
                            class="documentation-important-card panel"
                            href="#<?= e($important['anchor']) ?>"
                            data-documentation-section
                            data-documentation-text="<?= e($searchText) ?>"
                        >
                            <span class="documentation-card-icon"><?= ui_icon($important['icon']) ?></span>
                            <strong><?= e($important['title']) ?></strong>
                            <span><?= e($important['summary']) ?></span>
                            <span class="documentation-pill-row">
                                <?php foreach ($tags as $tag): ?>
                                    <em><?= e($tag) ?></em>
                                <?php endforeach; ?>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($departmentGuides): ?>
            <section class="documentation-group">
                <div class="documentation-section-heading">
                    <div>
                        <p class="eyebrow">Who does what</p>
                        <h3>Department / Role Guide</h3>
                    </div>
                    <span>Use this to train employees and assign permissions without guessing.</span>
                </div>
                <div class="documentation-department-grid">
                    <?php foreach ($departmentGuides as $guide): ?>
                        <?php
                        $roles = $guide['roles'] ?? [];
                        $responsibilities = $guide['responsibilities'] ?? [];
                        $pages = $guide['pages'] ?? [];
                        $searchText = strtolower(trim(implode(' ', [
                            $guide['department'],
                            implode(' ', $roles),
                            implode(' ', $responsibilities),
                            implode(' ', $pages),
                            $guide['handoff'] ?? '',
                        ])));
                        ?>
                        <article
                            class="documentation-department-card panel"
                            data-documentation-section
                            data-documentation-text="<?= e($searchText) ?>"
                        >
                            <header>
                                <span class="documentation-card-icon"><?= ui_icon($guide['icon']) ?></span>
                                <div>
                                    <p class="eyebrow"><?= e(implode(' / ', $roles)) ?></p>
                                    <h4><?= e($guide['department']) ?></h4>
                                </div>
                            </header>
                            <ul class="documentation-list">
                                <?php foreach ($responsibilities as $responsibility): ?>
                                    <li><?= e($responsibility) ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <div class="documentation-page-tags">
                                <?php foreach ($pages as $page): ?>
                                    <span><?= e($page) ?></span>
                                <?php endforeach; ?>
                            </div>
                            <p><?= e($guide['handoff']) ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <section class="documentation-group">
            <div class="documentation-section-heading">
                <div>
                    <p class="eyebrow">Full manual</p>
                    <h3>Feature Guides</h3>
                </div>
                <span>Every page, rule, workflow, report, and stock-control boundary.</span>
            </div>

            <?php foreach ($sections as $section): ?>
                <?php
                $visual = documentation_visual_for_section($section);
                $searchText = strtolower(trim(implode(' ', [
                    $section['title'],
                    $section['audience'],
                    $section['summary'],
                    implode(' ', $section['features']),
                    implode(' ', $section['steps']),
                    implode(' ', $section['rules']),
                ])));
                ?>
                <article
                    class="documentation-card panel"
                    id="doc-<?= e($section['slug']) ?>"
                    data-documentation-section
                    data-documentation-track-section
                    data-documentation-title="<?= e($section['title']) ?>"
                    data-documentation-audience="<?= e($section['audience']) ?>"
                    data-documentation-text="<?= e($searchText) ?>"
                >
                    <header class="documentation-card-head">
                        <div class="page-head-copy">
                            <p class="eyebrow"><?= e($section['audience']) ?></p>
                            <h3 class="page-head-title"><?= ui_icon($section['icon']) ?><span><?= e($section['title']) ?></span></h3>
                        </div>
                        <?php if (!empty($section['route']) && $canOpenRoute((string) $section['route'])): ?>
                            <a class="ghost-button" href="<?= e(url($section['route'])) ?>">Open Page</a>
                        <?php endif; ?>
                    </header>

                    <p class="documentation-summary"><?= e($section['summary']) ?></p>

                    <figure class="documentation-screen">
                        <div class="documentation-screen-frame">
                            <?php if (!empty($visual['screenshot_url'])): ?>
                                <img src="<?= e($visual['screenshot_url']) ?>" alt="<?= e($section['title']) ?> screenshot">
                            <?php else: ?>
                                <div class="documentation-screen-mock" role="img" aria-label="<?= e($section['title']) ?> screen preview">
                                    <div class="documentation-screen-bar">
                                        <span></span>
                                        <span></span>
                                        <span></span>
                                        <em><?= e($visual['route'] !== '' ? $visual['route'] : '/documentation') ?></em>
                                    </div>
                                    <div class="documentation-screen-body">
                                        <aside>
                                            <span><?= ui_icon($visual['icon']) ?></span>
                                            <strong><?= e($visual['title']) ?></strong>
                                            <small><?= e($section['audience']) ?></small>
                                        </aside>
                                        <main>
                                            <div class="documentation-screen-title">
                                                <strong><?= e($visual['title']) ?></strong>
                                                <span>Guide preview</span>
                                            </div>
                                            <div class="documentation-screen-cards">
                                                <?php foreach (array_slice($visual['steps'], 0, 3) as $index => $step): ?>
                                                    <span>
                                                        <em><?= number_format($index + 1) ?></em>
                                                        <?= e($step) ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>
                                            <div class="documentation-screen-table">
                                                <span></span>
                                                <span></span>
                                                <span></span>
                                            </div>
                                        </main>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                        <figcaption>
                            <strong>Screen guide</strong>
                            <span>Use this visual to recognize the page before following the steps below.</span>
                        </figcaption>
                    </figure>

                    <div class="documentation-columns">
                        <div class="documentation-block">
                            <h4>Features</h4>
                            <ul class="documentation-list">
                                <?php foreach ($section['features'] as $feature): ?>
                                    <li><?= e($feature) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>

                        <div class="documentation-block">
                            <h4>How To Use It</h4>
                            <ol class="documentation-list documentation-list-numbered">
                                <?php foreach ($section['steps'] as $step): ?>
                                    <li><?= e($step) ?></li>
                                <?php endforeach; ?>
                            </ol>
                        </div>
                    </div>

                    <div class="documentation-rules">
                        <h4>Rules That Matter</h4>
                        <ul class="documentation-list">
                            <?php foreach ($section['rules'] as $rule): ?>
                                <li><?= e($rule) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>
    </section>
</div>
