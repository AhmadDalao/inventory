<?php
declare(strict_types=1);

// Compatibility loader for upload helpers.
// New code should include the focused upload modules through app/module_manifest.php.

require_once __DIR__ . '/upload_inputs.php';
require_once __DIR__ . '/purchase_file_uploads.php';
require_once __DIR__ . '/workflow_file_uploads.php';
require_once __DIR__ . '/item_image_uploads.php';
require_once __DIR__ . '/asset_file_uploads.php';
