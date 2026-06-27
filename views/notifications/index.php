<section class="page-head">
    <div class="page-head-copy">
        <p class="eyebrow">Inbox</p>
        <h3 class="page-head-title"><?= ui_icon('notification') ?><span>Notifications</span></h3>
    </div>
    <div class="page-actions">
        <?php if ((int) $unreadCount > 0): ?>
            <form method="post" action="<?= e(url('/notifications/read-all')) ?>">
                <?= csrf_field() ?>
                <button class="ghost-button" type="submit"><?= ui_icon('notification') ?><span>Mark All Read</span></button>
            </form>
        <?php endif; ?>
    </div>
</section>

<section class="filter-panel notification-filter-panel">
    <form class="filter-grid notification-filter-grid" method="get" action="<?= e(url('/notifications')) ?>">
        <label class="field">
            <span>Search</span>
            <input type="text" name="search" value="<?= e((string) ($filters['search'] ?? '')) ?>" placeholder="Title, message, user, or link">
        </label>

        <label class="field">
            <span>Status</span>
            <select name="status">
                <option value="all" <?= selected('all', (string) ($filters['status'] ?? 'all')) ?>>All notifications</option>
                <option value="unread" <?= selected('unread', (string) ($filters['status'] ?? 'all')) ?>>Unread only</option>
                <option value="read" <?= selected('read', (string) ($filters['status'] ?? 'all')) ?>>Read only</option>
            </select>
        </label>

        <label class="field">
            <span>Type</span>
            <select name="type">
                <option value="">All types</option>
                <?php foreach ($typeOptions as $typeValue => $typeLabel): ?>
                    <option value="<?= e((string) $typeValue) ?>" <?= selected((string) $typeValue, (string) ($filters['type'] ?? '')) ?>>
                        <?= e((string) $typeLabel) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <div class="filter-actions">
            <button class="primary-button" type="submit"><?= ui_icon('filter') ?><span>Filter</span></button>
            <a class="ghost-button" href="<?= e(url('/notifications')) ?>"><?= ui_icon('back') ?><span>Reset</span></a>
        </div>
    </form>

    <div class="chip-row">
        <span class="stat-chip"><?= number_format((int) $unreadCount) ?> unread</span>
        <span class="stat-chip"><?= number_format(count($notifications)) ?> shown</span>
    </div>
</section>

<section class="panel notification-log-panel">
    <div class="panel-head">
        <div class="panel-head-copy">
            <p class="eyebrow">Complete Log</p>
            <h3>All Notifications</h3>
        </div>
    </div>

    <?php if ($notifications === []): ?>
        <p class="empty-state">No notifications match this filter.</p>
    <?php else: ?>
        <div class="notification-card-grid">
            <?php foreach ($notifications as $notification): ?>
                <?php $actionUrl = (string) ($notification['action_url'] ?: '#'); ?>
                <?php
                $notificationIcon = [
                    'request' => 'requests',
                    'handover' => 'handover',
                    'purchase' => 'purchases',
                    'stocktake' => 'stocktakes',
                    'item' => 'items',
                    'storage' => 'storages',
                    'supplier' => 'supplier',
                    'file' => 'files',
                ][(string) ($notification['entity_type'] ?? '')] ?? 'notification';
                ?>
                <article class="notification-card <?= empty($notification['read_at']) ? 'notification-card-unread' : '' ?>">
                    <div class="notification-card-main">
                        <div class="notification-card-icon"><?= ui_icon($notificationIcon) ?></div>
                        <div>
                            <div class="notification-card-title-row">
                                <strong><?= e((string) $notification['title']) ?></strong>
                                <?php if (empty($notification['read_at'])): ?>
                                    <span class="pill pill-usage">New</span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($notification['message'])): ?>
                                <p><?= e((string) $notification['message']) ?></p>
                            <?php endif; ?>
                            <div class="notification-card-meta">
                                <span><?= e((string) $notification['entity_label']) ?></span>
                                <?php if (!empty($notification['actor_name'])): ?>
                                    <span>By <?= e((string) $notification['actor_name']) ?></span>
                                <?php endif; ?>
                                <span><?= e((string) $notification['created_at_display']) ?></span>
                            </div>
                        </div>
                    </div>
                    <a class="ghost-button notification-card-action" href="<?= e($actionUrl) ?>"><?= ui_icon('back') ?><span>Open</span></a>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
