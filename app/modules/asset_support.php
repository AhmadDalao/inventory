<?php
declare(strict_types=1);

// Compatibility loader for older direct includes. New logic belongs in focused asset_* modules.
require_once __DIR__ . '/asset_filters.php';
require_once __DIR__ . '/asset_queries.php';
require_once __DIR__ . '/asset_forms.php';
require_once __DIR__ . '/asset_identity.php';
require_once __DIR__ . '/asset_financials.php';
require_once __DIR__ . '/asset_uploads.php';
require_once __DIR__ . '/asset_selects.php';
require_once __DIR__ . '/asset_events.php';
