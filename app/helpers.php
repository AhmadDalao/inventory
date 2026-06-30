<?php
declare(strict_types=1);

if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

function base_path(string $path = ''): string
{
    $base = dirname(__DIR__);

    return $path === '' ? $base : $base . '/' . ltrim($path, '/');
}

function starts_with(string $haystack, string $needle): bool
{
    if ($needle === '') {
        return true;
    }

    return strpos($haystack, $needle) === 0;
}

function app_config(?string $key = null, $default = null)
{
    global $appConfig;

    if ($key === null) {
        return $appConfig;
    }

    $value = $appConfig;

    foreach (explode('.', $key) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }

        $value = $value[$segment];
    }

    return $value;
}

function site_setting_schema(): array
{
    static $schema;

    if ($schema !== null) {
        return $schema;
    }

    $schema = [
        [
            'id' => 'branding',
            'title' => 'Branding',
            'copy' => 'Shared app name and chrome labels.',
            'fields' => [
                'app.name' => [
                    'label' => 'Dashboard name',
                    'default' => (string) app_config('app.name', 'Inventory HQ'),
                    'help' => 'Shows in the browser title, sidebar, and top-level branding.',
                    'maxlength' => 120,
                ],
                'brand.mark' => [
                    'label' => 'Sidebar mark',
                    'default' => '',
                    'help' => 'Short badge text. Leave blank to auto-build it from the dashboard name.',
                    'maxlength' => 4,
                ],
                'brand.eyebrow' => [
                    'label' => 'Sidebar eyebrow',
                    'default' => 'Inventory Control',
                    'help' => 'Small label above the main app name in the sidebar.',
                    'maxlength' => 80,
                ],
                'topbar.eyebrow' => [
                    'label' => 'Topbar eyebrow',
                    'default' => 'Live stock metrics',
                    'help' => 'Small label above the current page title.',
                    'maxlength' => 80,
                ],
            ],
        ],
        [
            'id' => 'navigation',
            'title' => 'Navigation',
            'copy' => 'Sidebar link labels.',
            'fields' => [
                'nav.dashboard' => ['label' => 'Dashboard link', 'default' => 'Dashboard', 'maxlength' => 60],
                'nav.storages' => ['label' => 'Storages link', 'default' => 'Storages', 'maxlength' => 60],
                'nav.items' => ['label' => 'Items link', 'default' => 'Items', 'maxlength' => 60],
                'nav.movements' => ['label' => 'Movement log link', 'default' => 'Movement Log', 'maxlength' => 60],
                'nav.scan' => ['label' => 'Scan link', 'default' => 'Scan Center', 'maxlength' => 60],
                'nav.requests' => ['label' => 'Requests link', 'default' => 'Requests', 'maxlength' => 60],
                'nav.handovers' => ['label' => 'Handovers link', 'default' => 'Handovers', 'maxlength' => 60],
                'nav.purchases' => ['label' => 'Purchases link', 'default' => 'Purchases', 'maxlength' => 60],
                'nav.reports' => ['label' => 'Reports link', 'default' => 'Reports', 'maxlength' => 60],
                'nav.files' => ['label' => 'Files link', 'default' => 'Files', 'maxlength' => 60],
                'nav.documentation' => ['label' => 'Documentation link', 'default' => 'Documentation', 'maxlength' => 60],
                'nav.stocktakes' => ['label' => 'Stocktakes link', 'default' => 'Stocktakes', 'maxlength' => 60],
                'nav.users' => ['label' => 'Admins link', 'default' => 'Admins', 'maxlength' => 60],
                'nav.suppliers' => ['label' => 'Suppliers link', 'default' => 'Suppliers', 'maxlength' => 60],
                'nav.reorder' => ['label' => 'Reorder link', 'default' => 'Reorder', 'maxlength' => 60],
                'nav.labels' => ['label' => 'Labels link', 'default' => 'Labels', 'maxlength' => 60],
                'nav.audit' => ['label' => 'Audit log link', 'default' => 'Audit Log', 'maxlength' => 60],
                'nav.email_logs' => ['label' => 'Email logs link', 'default' => 'Email Logs', 'maxlength' => 60],
                'nav.settings' => ['label' => 'Website control link', 'default' => 'Website Control', 'maxlength' => 60],
            ],
        ],
        [
            'id' => 'appearance',
            'title' => 'Appearance',
            'copy' => 'Visual style controls. KONA is the primary look; KONA Official uses the club font, logo, and official gold/black palette.',
            'fields' => [
                'ui.theme' => [
                    'label' => 'Interface style',
                    'default' => 'clean',
                    'help' => 'Use KONA Official when you want the club logo, DIN Arabic font, and official colors.',
                    'type' => 'select',
                    'options' => ui_theme_options(),
                    'maxlength' => 40,
                ],
            ],
        ],
        [
            'id' => 'inventory-controls',
            'title' => 'Inventory Controls',
            'copy' => 'Operational rules for catalog data entry.',
            'fields' => [
                'items.barcode_required' => [
                    'label' => 'Barcode required for items',
                    'default' => '0',
                    'help' => 'Keep No while old inventory is being cleaned up. Switch to Yes when every new item must have a real barcode.',
                    'type' => 'select',
                    'options' => [
                        '0' => 'No',
                        '1' => 'Yes',
                    ],
                    'maxlength' => 1,
                ],
                'exports.item_xlsx_thumbnails' => [
                    'label' => 'Item Excel exports with thumbnails',
                    'default' => '1',
                    'help' => 'Adds XLSX export buttons with embedded item thumbnails where item catalogs are exported. CSV stays lightweight.',
                    'type' => 'select',
                    'options' => [
                        '0' => 'No',
                        '1' => 'Yes',
                    ],
                    'maxlength' => 1,
                ],
                'exports.storage_xlsx_thumbnails' => [
                    'label' => 'Storage Excel export with item thumbnails',
                    'default' => '1',
                    'help' => 'Adds a separate XLSX export button on the Storages page with each storage and the items inside it.',
                    'type' => 'select',
                    'options' => [
                        '0' => 'No',
                        '1' => 'Yes',
                    ],
                    'maxlength' => 1,
                ],
                'exports.movement_xlsx_thumbnails' => [
                    'label' => 'Movement Excel export with thumbnails',
                    'default' => '1',
                    'help' => 'Adds an XLSX export button on Movement Log with item thumbnails, scan codes, and movement details.',
                    'type' => 'select',
                    'options' => [
                        '0' => 'No',
                        '1' => 'Yes',
                    ],
                    'maxlength' => 1,
                ],
                'exports.report_xlsx_thumbnails' => [
                    'label' => 'Report Excel exports with thumbnails',
                    'default' => '1',
                    'help' => 'Adds XLSX export buttons on Reports summaries with embedded item thumbnails where items are listed.',
                    'type' => 'select',
                    'options' => [
                        '0' => 'No',
                        '1' => 'Yes',
                    ],
                    'maxlength' => 1,
                ],
                'exports.excel_barcode_images' => [
                    'label' => 'Excel exports with scannable barcode images',
                    'default' => '1',
                    'help' => 'Adds a barcode image beside the barcode/scan code in supported Excel exports. If an item has no barcode, the SKU is used as the scan code.',
                    'type' => 'select',
                    'options' => [
                        '0' => 'No',
                        '1' => 'Yes',
                    ],
                    'maxlength' => 1,
                ],
                'exports.item_xlsx_thumbnail_size' => [
                    'label' => 'Item Excel thumbnail size',
                    'default' => 'medium',
                    'help' => 'Controls item image size in the optional Excel thumbnail export.',
                    'type' => 'select',
                    'options' => item_xlsx_thumbnail_size_options(),
                    'maxlength' => 20,
                ],
                'exports.item_xlsx_thumbnail_custom_width' => [
                    'label' => 'Custom Excel thumbnail width',
                    'default' => '120',
                    'help' => 'Used only when thumbnail size is Custom. Enter pixels, for example 120 or 220.',
                    'type' => 'number',
                    'maxlength' => 4,
                ],
                'exports.item_xlsx_thumbnail_custom_height' => [
                    'label' => 'Custom Excel thumbnail height',
                    'default' => '90',
                    'help' => 'Used only when thumbnail size is Custom. Enter pixels, for example 90 or 160.',
                    'type' => 'number',
                    'maxlength' => 4,
                ],
            ],
        ],
        [
            'id' => 'workflow-documents',
            'title' => 'Workflow Documents',
            'copy' => 'Controls request and handover sign-off PDF/Excel files.',
            'fields' => [
                'workflow.signoff_template' => [
                    'label' => 'Sign-off template',
                    'default' => 'detailed',
                    'help' => 'Built-in document template. Custom uploaded templates need a controlled placeholder format and should be added separately.',
                    'type' => 'select',
                    'options' => workflow_signoff_template_options(),
                    'maxlength' => 20,
                ],
                'workflow.handover_line_edits' => [
                    'label' => 'Edit handover request items before approval',
                    'default' => '1',
                    'help' => 'Default enabled. Allows the requester or storage owner to add/remove/change requested handover items only while the request is still waiting approval.',
                    'type' => 'select',
                    'options' => [
                        '1' => 'Enabled',
                        '0' => 'Disabled',
                    ],
                    'maxlength' => 1,
                ],
                'workflow.signoff_image_size' => [
                    'label' => 'Sign-off item image size',
                    'default' => 'large',
                    'help' => 'Controls item images inside generated request and handover PDF/Excel sign-off files.',
                    'type' => 'select',
                    'options' => workflow_signoff_image_size_options(),
                    'maxlength' => 20,
                ],
                'workflow.signoff_image_custom_width' => [
                    'label' => 'Custom image width',
                    'default' => '200',
                    'help' => 'Used only when size is Custom. Enter pixels, for example 200 or 400.',
                    'type' => 'number',
                    'maxlength' => 4,
                ],
                'workflow.signoff_image_custom_height' => [
                    'label' => 'Custom image height',
                    'default' => '200',
                    'help' => 'Used only when size is Custom. Enter pixels, for example 200 or 120.',
                    'type' => 'number',
                    'maxlength' => 4,
                ],
            ],
        ],
        [
            'id' => 'purchase-ocr',
            'title' => 'Purchase OCR',
            'copy' => 'Document extraction for Arabic and English supplier PDFs, scans, quotes, receipts, and price lists.',
            'fields' => [
                'ocr.mode' => [
                    'label' => 'OCR mode',
                    'default' => 'hybrid',
                    'help' => 'Free only never calls OpenAI. Fallback shows a Run AI button when free/browser OCR is weak. OpenAI first sends files to AI before local extraction.',
                    'type' => 'select',
                    'options' => [
                        'free_only' => 'Free only',
                        'hybrid' => 'Free + OpenAI fallback',
                        'openai_first' => 'OpenAI first',
                    ],
                    'maxlength' => 20,
                ],
                'ocr.openai_enabled' => [
                    'label' => 'Allow OpenAI OCR calls',
                    'default' => '1',
                    'help' => 'OpenAI runs only when this is Yes, a key is saved, and OCR mode allows it.',
                    'type' => 'select',
                    'options' => [
                        '1' => 'Yes',
                        '0' => 'No',
                    ],
                    'maxlength' => 1,
                ],
                'ocr.openai_api_key' => [
                    'label' => 'OpenAI API key',
                    'default' => '',
                    'help' => 'Paste a key to enable Arabic scanned PDF OCR. Leave blank to keep the saved key.',
                    'type' => 'secret',
                    'fallback_config' => 'ocr.openai_api_key',
                    'maxlength' => 512,
                ],
                'ocr.openai_model' => [
                    'label' => 'OpenAI OCR model',
                    'default' => (string) app_config('ocr.openai_model', 'gpt-5.5'),
                    'help' => 'Model used for purchase document extraction.',
                    'maxlength' => 80,
                ],
                'ocr.max_pdf_pages' => [
                    'label' => 'Max PDF pages per file',
                    'default' => '8',
                    'help' => 'Browser OCR reads this many pages from scanned PDFs to keep phones and laptops responsive.',
                    'type' => 'number',
                    'maxlength' => 2,
                ],
                'ocr.min_confidence' => [
                    'label' => 'Minimum confidence percent',
                    'default' => '70',
                    'help' => 'Rows below this score are marked Needs review. Use 70 as the sane default.',
                    'type' => 'number',
                    'maxlength' => 3,
                ],
                'ocr.monthly_safety_note' => [
                    'label' => 'Monthly safety note',
                    'default' => 'OpenAI OCR is paid. Use it for hard scans only and review every extracted row before creating drafts.',
                    'help' => 'Shown to owners as a reminder that AI OCR can cost money and still needs review.',
                    'maxlength' => 190,
                ],
            ],
        ],
        [
            'id' => 'operations',
            'title' => 'Operations Safety',
            'copy' => 'Backup and scheduled-report defaults used by CLI scripts and Hostinger cron.',
            'fields' => [
                'backup.retention_days' => [
                    'label' => 'Backup retention days',
                    'default' => '14',
                    'help' => 'How long generated backups are kept before old files are cleaned up.',
                    'maxlength' => 3,
                ],
                'backup.include_uploads' => [
                    'label' => 'Include uploaded files in backups',
                    'default' => '1',
                    'help' => 'Yes backs up item images, purchase documents, and file library assets with the SQL dump.',
                    'type' => 'select',
                    'options' => [
                        '1' => 'Yes',
                        '0' => 'No',
                    ],
                    'maxlength' => 1,
                ],
                'reports.daily_enabled' => [
                    'label' => 'Daily report cron enabled',
                    'default' => '1',
                    'help' => 'Keep enabled when Hostinger cron is configured to run scripts/daily_report.php.',
                    'type' => 'select',
                    'options' => [
                        '1' => 'Yes',
                        '0' => 'No',
                    ],
                    'maxlength' => 1,
                ],
            ],
        ],
        [
            'id' => 'email-delivery',
            'title' => 'Email Delivery',
            'copy' => 'Password recovery and optional workflow alert copies. SMTP is recommended for reliable Hostinger delivery.',
            'fields' => [
                'email.enabled' => [
                    'label' => 'Enable email delivery',
                    'default' => '1',
                    'help' => 'When disabled, the app keeps in-app notifications and records suppressed email logs.',
                    'type' => 'choice',
                    'options' => [
                        '1' => 'Yes',
                        '0' => 'No',
                    ],
                    'maxlength' => 1,
                ],
                'email.transport' => [
                    'label' => 'Mailer transport',
                    'default' => 'php_mail',
                    'help' => 'SMTP is best. PHP mail is simple but depends on server mail configuration. Log only records emails without sending.',
                    'type' => 'choice',
                    'options' => [
                        'smtp' => 'SMTP',
                        'php_mail' => 'PHP mail',
                        'log_only' => 'Log only',
                    ],
                    'maxlength' => 20,
                ],
                'email.sender_name' => [
                    'label' => 'Sender name',
                    'default' => 'Inventory KONA',
                    'help' => 'Shown as the sender display name.',
                    'maxlength' => 120,
                ],
                'email.sender_email' => [
                    'label' => 'Sender email',
                    'default' => 'no-reply@inventory.ahmaddalao.com',
                    'help' => 'Use a domain email for better delivery.',
                    'type' => 'email',
                    'maxlength' => 190,
                ],
                'email.reply_to' => [
                    'label' => 'Reply-to email',
                    'default' => '',
                    'help' => 'Optional. Replies can go to the owner or operations email.',
                    'type' => 'email',
                    'maxlength' => 190,
                ],
                'email.smtp_host' => [
                    'label' => 'SMTP host',
                    'default' => '',
                    'help' => 'Example: smtp.hostinger.com or the host shown in Hostinger Email settings.',
                    'maxlength' => 190,
                ],
                'email.smtp_port' => [
                    'label' => 'SMTP port',
                    'default' => '465',
                    'help' => 'Use 465 with SSL or 587 with TLS.',
                    'type' => 'number',
                    'maxlength' => 5,
                ],
                'email.smtp_encryption' => [
                    'label' => 'SMTP encryption',
                    'default' => 'ssl',
                    'help' => 'Use the encryption method shown by your email provider.',
                    'type' => 'choice',
                    'options' => [
                        'ssl' => 'SSL',
                        'tls' => 'TLS',
                        'none' => 'None',
                    ],
                    'maxlength' => 10,
                ],
                'email.smtp_username' => [
                    'label' => 'SMTP username',
                    'default' => '',
                    'help' => 'Usually the full mailbox address, for example no-reply@inventory.ahmaddalao.com.',
                    'maxlength' => 190,
                ],
                'email.smtp_password' => [
                    'label' => 'SMTP password',
                    'default' => '',
                    'help' => 'Mailbox password or app password. Leave blank to keep the saved password.',
                    'type' => 'secret',
                    'placeholder' => 'Paste SMTP password',
                    'maxlength' => 512,
                ],
                'email.smtp_timeout' => [
                    'label' => 'SMTP timeout seconds',
                    'default' => '12',
                    'help' => 'How long the app waits for the mail server before logging a failure.',
                    'type' => 'number',
                    'maxlength' => 3,
                ],
                'email.password_resets' => [
                    'label' => 'Password reset emails',
                    'default' => '1',
                    'help' => 'Allows users and admins to send password setup/reset links.',
                    'type' => 'choice',
                    'options' => [
                        '1' => 'Yes',
                        '0' => 'No',
                    ],
                    'maxlength' => 1,
                ],
                'email.workflow_alerts' => [
                    'label' => 'Workflow email alerts',
                    'default' => '0',
                    'help' => 'Optional email copies for important request, handover, purchase, and stocktake events.',
                    'type' => 'choice',
                    'options' => [
                        '0' => 'No',
                        '1' => 'Yes',
                    ],
                    'maxlength' => 1,
                ],
                'email.log_only' => [
                    'label' => 'Log-only override',
                    'default' => '0',
                    'help' => 'Yes records email logs without sending anything, regardless of selected transport.',
                    'type' => 'choice',
                    'options' => [
                        '0' => 'No',
                        '1' => 'Yes',
                    ],
                    'maxlength' => 1,
                ],
            ],
        ],
        [
            'id' => 'pages',
            'title' => 'Pages',
            'copy' => 'Main page titles and small eyebrow labels.',
            'fields' => [
                'page.dashboard' => ['label' => 'Dashboard page title', 'default' => 'Dashboard', 'maxlength' => 80],
                'page.dashboard_eyebrow' => ['label' => 'Dashboard eyebrow', 'default' => 'Overview', 'maxlength' => 80],
                'page.storages' => ['label' => 'Storages page title', 'default' => 'Storages', 'maxlength' => 80],
                'page.storages_eyebrow' => ['label' => 'Storages eyebrow', 'default' => 'Locations', 'maxlength' => 80],
                'page.items' => ['label' => 'Items page title', 'default' => 'Items', 'maxlength' => 80],
                'page.items_eyebrow' => ['label' => 'Items eyebrow', 'default' => 'Catalog', 'maxlength' => 80],
                'page.movements' => ['label' => 'Movement page title', 'default' => 'Movement Log', 'maxlength' => 80],
                'page.movements_eyebrow' => ['label' => 'Movement eyebrow', 'default' => 'Audit Trail', 'maxlength' => 80],
                'page.scan' => ['label' => 'Scan page title', 'default' => 'Scan Center', 'maxlength' => 80],
                'page.scan_eyebrow' => ['label' => 'Scan eyebrow', 'default' => 'Barcode workflow', 'maxlength' => 80],
                'page.requests' => ['label' => 'Requests page title', 'default' => 'Requests', 'maxlength' => 80],
                'page.requests_eyebrow' => ['label' => 'Requests eyebrow', 'default' => 'Transfers & approvals', 'maxlength' => 80],
                'page.handovers' => ['label' => 'Handovers page title', 'default' => 'Handovers', 'maxlength' => 80],
                'page.handovers_eyebrow' => ['label' => 'Handovers eyebrow', 'default' => 'Temporary item issue', 'maxlength' => 80],
                'page.purchases' => ['label' => 'Purchases page title', 'default' => 'Purchases', 'maxlength' => 80],
                'page.purchases_eyebrow' => ['label' => 'Purchases eyebrow', 'default' => 'Supplier Restocking', 'maxlength' => 80],
                'page.reports' => ['label' => 'Reports page title', 'default' => 'Reports', 'maxlength' => 80],
                'page.reports_eyebrow' => ['label' => 'Reports eyebrow', 'default' => 'Export shortcuts', 'maxlength' => 80],
                'page.files' => ['label' => 'Files page title', 'default' => 'Files', 'maxlength' => 80],
                'page.files_eyebrow' => ['label' => 'Files eyebrow', 'default' => 'Document library', 'maxlength' => 80],
                'page.documentation' => ['label' => 'Documentation page title', 'default' => 'Documentation', 'maxlength' => 80],
                'page.documentation_eyebrow' => ['label' => 'Documentation eyebrow', 'default' => 'Employee training', 'maxlength' => 80],
                'page.stocktakes' => ['label' => 'Stocktakes page title', 'default' => 'Stocktakes', 'maxlength' => 80],
                'page.stocktakes_eyebrow' => ['label' => 'Stocktakes eyebrow', 'default' => 'Cycle counts', 'maxlength' => 80],
                'page.users' => ['label' => 'Admins page title', 'default' => 'Admins', 'maxlength' => 80],
                'page.users_eyebrow' => ['label' => 'Admins eyebrow', 'default' => 'Access Control', 'maxlength' => 80],
                'page.suppliers' => ['label' => 'Suppliers page title', 'default' => 'Suppliers', 'maxlength' => 80],
                'page.suppliers_eyebrow' => ['label' => 'Suppliers eyebrow', 'default' => 'Vendor directory', 'maxlength' => 80],
                'page.reorder' => ['label' => 'Reorder page title', 'default' => 'Reorder Center', 'maxlength' => 80],
                'page.reorder_eyebrow' => ['label' => 'Reorder eyebrow', 'default' => 'Low stock automation', 'maxlength' => 80],
                'page.labels' => ['label' => 'Labels page title', 'default' => 'Labels', 'maxlength' => 80],
                'page.labels_eyebrow' => ['label' => 'Labels eyebrow', 'default' => 'Scan-ready codes', 'maxlength' => 80],
                'page.audit' => ['label' => 'Audit log page title', 'default' => 'Audit Log', 'maxlength' => 80],
                'page.audit_eyebrow' => ['label' => 'Audit log eyebrow', 'default' => 'Admin accountability', 'maxlength' => 80],
                'page.email_logs' => ['label' => 'Email logs page title', 'default' => 'Email Logs', 'maxlength' => 80],
                'page.email_logs_eyebrow' => ['label' => 'Email logs eyebrow', 'default' => 'Mailer delivery trail', 'maxlength' => 80],
                'page.settings' => ['label' => 'Website control page title', 'default' => 'Website Control', 'maxlength' => 80],
                'page.settings_eyebrow' => ['label' => 'Website control eyebrow', 'default' => 'Website Control', 'maxlength' => 80],
            ],
        ],
        [
            'id' => 'tables',
            'title' => 'Tables',
            'copy' => 'Top names for the main data tables.',
            'fields' => [
                'table.items' => ['label' => 'Items table title', 'default' => 'All Items', 'maxlength' => 80],
                'table.storages' => ['label' => 'Storages table title', 'default' => 'All Locations', 'maxlength' => 80],
                'table.movements' => ['label' => 'Movement table title', 'default' => 'All Movements', 'maxlength' => 80],
                'table.requests' => ['label' => 'Requests table title', 'default' => 'All Requests', 'maxlength' => 80],
                'table.handovers' => ['label' => 'Handovers table title', 'default' => 'All Handovers', 'maxlength' => 80],
                'table.purchases' => ['label' => 'Purchases table title', 'default' => 'Supplier Purchases', 'maxlength' => 80],
                'table.files' => ['label' => 'Files table title', 'default' => 'File Library', 'maxlength' => 80],
                'table.stocktakes' => ['label' => 'Stocktakes table title', 'default' => 'All Stocktakes', 'maxlength' => 80],
                'table.users' => ['label' => 'Admins table title', 'default' => 'All Admins', 'maxlength' => 80],
                'table.suppliers' => ['label' => 'Suppliers table title', 'default' => 'All Suppliers', 'maxlength' => 80],
                'table.reorder' => ['label' => 'Reorder table title', 'default' => 'Low Stock Suggestions', 'maxlength' => 80],
                'table.labels' => ['label' => 'Labels table title', 'default' => 'Printable Labels', 'maxlength' => 80],
                'table.audit' => ['label' => 'Audit log table title', 'default' => 'System Activity', 'maxlength' => 80],
                'table.email_logs' => ['label' => 'Email logs table title', 'default' => 'Delivery Attempts', 'maxlength' => 80],
            ],
        ],
        [
            'id' => 'dashboard',
            'title' => 'Dashboard Labels',
            'copy' => 'Cards, sections, and graph titles on the dashboard.',
            'fields' => [
                'metric.items_total' => ['label' => 'Items metric label', 'default' => 'Total Active Items', 'maxlength' => 80],
                'metric.storages_total' => ['label' => 'Storages metric label', 'default' => 'Active Storages', 'maxlength' => 80],
                'metric.warehouses_total' => ['label' => 'Warehouses metric label', 'default' => 'Active Warehouses', 'maxlength' => 80],
                'metric.units_total' => ['label' => 'Stock units metric label', 'default' => 'Total Units In Stock', 'maxlength' => 80],
                'metric.low_stock' => ['label' => 'Low stock metric label', 'default' => 'Low Stock Items', 'maxlength' => 80],
                'metric.inventory_value' => ['label' => 'Inventory value metric label', 'default' => 'Inventory Value', 'maxlength' => 80],
                'metric.used_last_30' => ['label' => 'Usage metric label', 'default' => 'Units Used', 'maxlength' => 80],
                'metric.requests_open' => ['label' => 'Requests metric label', 'default' => 'Open Requests', 'maxlength' => 80],
                'metric.handovers_open' => ['label' => 'Handovers metric label', 'default' => 'Open Handovers', 'maxlength' => 80],
                'metric.purchases_open' => ['label' => 'Purchases metric label', 'default' => 'Open Purchases', 'maxlength' => 80],
                'metric.purchase_receiving' => ['label' => 'Purchase receiving metric label', 'default' => 'Purchases Pending Receiving', 'maxlength' => 80],
                'dashboard.low_stock' => ['label' => 'Low stock panel title', 'default' => 'Low Stock Watchlist', 'maxlength' => 80],
                'dashboard.top_usage' => ['label' => 'Top usage panel title', 'default' => 'Most Used Items', 'maxlength' => 80],
                'dashboard.recent_activity' => ['label' => 'Recent activity panel title', 'default' => 'Recent Activity', 'maxlength' => 80],
                'dashboard.requests' => ['label' => 'Requests panel title', 'default' => 'Request Queue', 'maxlength' => 80],
                'dashboard.handovers' => ['label' => 'Handovers panel title', 'default' => 'Open Handovers', 'maxlength' => 80],
                'dashboard.purchases' => ['label' => 'Purchases panel title', 'default' => 'Purchase Queue', 'maxlength' => 80],
                'dashboard.notifications' => ['label' => 'Notifications panel title', 'default' => 'Notifications', 'maxlength' => 80],
                'dashboard.usage_chart' => ['label' => 'Usage chart title', 'default' => 'Usage Trend', 'maxlength' => 80],
                'dashboard.value_chart' => ['label' => 'Value chart title', 'default' => 'Value By Location', 'maxlength' => 80],
            ],
        ],
    ];

    return $schema;
}

function site_setting_definitions(): array
{
    static $definitions;

    if ($definitions !== null) {
        return $definitions;
    }

    $definitions = [];

    foreach (site_setting_schema() as $group) {
        foreach ($group['fields'] as $key => $field) {
            $definitions[$key] = $field + [
                'key' => $key,
                'default' => '',
                'maxlength' => 160,
            ];
        }
    }

    return $definitions;
}

function site_setting_defaults(): array
{
    static $defaults;

    if ($defaults !== null) {
        return $defaults;
    }

    $defaults = [];

    foreach (site_setting_definitions() as $key => $field) {
        $defaults[$key] = (string) ($field['default'] ?? '');
    }

    return $defaults;
}

function site_settings_table_exists(): bool
{
    if (array_key_exists('_site_settings_table_exists', $GLOBALS)) {
        return (bool) $GLOBALS['_site_settings_table_exists'];
    }

    try {
        $GLOBALS['_site_settings_table_exists'] = (int) Database::scalar(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name',
            ['table_name' => 'app_settings']
        ) > 0;
    } catch (Throwable $exception) {
        $GLOBALS['_site_settings_table_exists'] = false;
    }

    return (bool) $GLOBALS['_site_settings_table_exists'];
}

function site_setting_stored_values(): array
{
    if (isset($GLOBALS['_site_settings_stored_values']) && is_array($GLOBALS['_site_settings_stored_values'])) {
        return $GLOBALS['_site_settings_stored_values'];
    }

    if (!site_settings_table_exists()) {
        $GLOBALS['_site_settings_stored_values'] = [];
        return [];
    }

    try {
        $rows = Database::fetchAll('SELECT setting_key, setting_value FROM app_settings');
    } catch (Throwable $exception) {
        $GLOBALS['_site_settings_stored_values'] = [];
        return [];
    }

    $values = [];

    foreach ($rows as $row) {
        $key = (string) ($row['setting_key'] ?? '');

        if ($key === '') {
            continue;
        }

        $values[$key] = (string) ($row['setting_value'] ?? '');
    }

    $GLOBALS['_site_settings_stored_values'] = $values;

    return $values;
}

function site_setting_stored_value(string $key): ?string
{
    $values = site_setting_stored_values();

    if (!array_key_exists($key, $values)) {
        return null;
    }

    return (string) $values[$key];
}

function site_settings(): array
{
    if (isset($GLOBALS['_site_settings_cache']) && is_array($GLOBALS['_site_settings_cache'])) {
        return $GLOBALS['_site_settings_cache'];
    }

    $settings = site_setting_defaults();

    if (!site_settings_table_exists()) {
        $GLOBALS['_site_settings_cache'] = $settings;
        return $settings;
    }

    try {
        foreach (site_setting_stored_values() as $key => $storedValue) {
            if (!array_key_exists($key, $settings)) {
                continue;
            }

            $value = trim((string) $storedValue);

            if ($value !== '') {
                $settings[$key] = $value;
            }
        }
    } catch (Throwable $exception) {
        $GLOBALS['_site_settings_cache'] = $settings;
        return $settings;
    }

    $GLOBALS['_site_settings_cache'] = $settings;

    return $settings;
}

function site_settings_cache_reset(): void
{
    unset($GLOBALS['_site_settings_cache'], $GLOBALS['_site_settings_table_exists'], $GLOBALS['_site_settings_stored_values']);
}

function site_setting(string $key, ?string $fallback = null): string
{
    $settings = site_settings();

    if (array_key_exists($key, $settings) && trim((string) $settings[$key]) !== '') {
        return trim((string) $settings[$key]);
    }

    if ($fallback !== null) {
        return $fallback;
    }

    return (string) (site_setting_defaults()[$key] ?? '');
}

function openai_ocr_api_key(): string
{
    $stored = site_setting_stored_value('ocr.openai_api_key');

    if ($stored !== null && trim($stored) !== '') {
        return trim($stored);
    }

    return trim((string) app_config('ocr.openai_api_key', ''));
}

function openai_ocr_model(): string
{
    $model = trim(site_setting('ocr.openai_model', (string) app_config('ocr.openai_model', 'gpt-5.5')));

    return $model !== '' ? $model : 'gpt-5.5';
}

function purchase_ocr_mode(): string
{
    $mode = site_setting('ocr.mode', 'hybrid');

    return in_array($mode, ['free_only', 'hybrid', 'openai_first'], true) ? $mode : 'hybrid';
}

function purchase_ocr_max_pdf_pages(): int
{
    $pages = (int) site_setting('ocr.max_pdf_pages', '8');

    return max(1, min(20, $pages));
}

function purchase_ocr_min_confidence(): float
{
    $percent = (int) site_setting('ocr.min_confidence', '70');
    $percent = max(1, min(95, $percent));

    return $percent / 100;
}

function openai_ocr_enabled(): bool
{
    $storedEnabled = site_setting_stored_value('ocr.openai_enabled');

    if ($storedEnabled !== null && trim($storedEnabled) !== '') {
        return trim($storedEnabled) === '1';
    }

    $storedKey = site_setting_stored_value('ocr.openai_api_key');

    if ($storedKey !== null && trim($storedKey) !== '') {
        return true;
    }

    return (bool) app_config('ocr.openai_enabled', false);
}

function absolute_url(string $path): string
{
    $configuredUrl = rtrim(trim((string) app_config('app.url', '')), '/');

    if ($configuredUrl !== '') {
        return $configuredUrl . url($path);
    }

    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $scheme = request_is_secure() ? 'https' : 'http';

    if ($host === '') {
        return url($path);
    }

    return $scheme . '://' . $host . url($path);
}

function email_delivery_enabled(): bool
{
    return site_setting('email.enabled', '1') === '1';
}

function email_password_resets_enabled(): bool
{
    return email_delivery_enabled() && site_setting('email.password_resets', '1') === '1';
}

function email_workflow_alerts_enabled(): bool
{
    return email_delivery_enabled() && site_setting('email.workflow_alerts', '0') === '1';
}

function email_log_only_enabled(): bool
{
    return site_setting('email.log_only', '0') === '1' || email_transport() === 'log_only';
}

function email_transport(): string
{
    $transport = site_setting('email.transport', 'php_mail');

    return in_array($transport, ['smtp', 'php_mail', 'log_only'], true) ? $transport : 'php_mail';
}

function email_smtp_host(): string
{
    return email_header_value(site_setting('email.smtp_host', ''));
}

function email_smtp_port(): int
{
    $port = (int) site_setting('email.smtp_port', '465');

    return $port > 0 && $port <= 65535 ? $port : 465;
}

function email_smtp_encryption(): string
{
    $encryption = strtolower(trim(site_setting('email.smtp_encryption', 'ssl')));

    return in_array($encryption, ['ssl', 'tls', 'none'], true) ? $encryption : 'ssl';
}

function email_smtp_username(): string
{
    return trim(site_setting('email.smtp_username', ''));
}

function email_smtp_password(): string
{
    return (string) site_setting('email.smtp_password', '');
}

function email_smtp_timeout(): int
{
    $timeout = (int) site_setting('email.smtp_timeout', '12');

    return max(3, min(60, $timeout));
}

function email_header_value(string $value): string
{
    $value = str_replace(["\r", "\n"], ' ', trim($value));

    return preg_replace('/\s+/', ' ', $value) ?: '';
}

function email_sender_email(): string
{
    $email = trim(site_setting('email.sender_email', 'no-reply@inventory.ahmaddalao.com'));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'no-reply@inventory.ahmaddalao.com';
    }

    return $email;
}

function email_sender_name(): string
{
    $name = email_header_value(site_setting('email.sender_name', 'Inventory KONA'));

    return $name !== '' ? $name : 'Inventory KONA';
}

function email_reply_to(): string
{
    $email = trim(site_setting('email.reply_to', ''));

    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
}

function email_display_name(string $name): string
{
    $name = email_header_value($name);

    if ($name === '') {
        return '';
    }

    if (function_exists('mb_encode_mimeheader')) {
        return mb_encode_mimeheader($name, 'UTF-8', 'B', "\r\n");
    }

    return preg_match('/[^\x20-\x7E]/', $name) ? '' : $name;
}

function email_encoded_header(string $value): string
{
    $value = email_header_value($value);

    if ($value === '') {
        return '';
    }

    if (function_exists('mb_encode_mimeheader')) {
        return mb_encode_mimeheader($value, 'UTF-8', 'B', "\r\n");
    }

    return preg_match('/[^\x20-\x7E]/', $value) ? '' : $value;
}

function email_address_header(?string $name, string $email): string
{
    $displayName = $name !== null ? email_encoded_header($name) : '';

    return ($displayName !== '' ? $displayName . ' ' : '') . '<' . $email . '>';
}

function email_normalized_body(string $body): string
{
    $body = str_replace(["\r\n", "\r"], "\n", $body);
    $body = str_replace("\n", "\r\n", $body);

    return preg_replace('/^\./m', '..', $body);
}

function email_smtp_read_response($socket): array
{
    $lines = [];

    while (!feof($socket)) {
        $line = fgets($socket, 515);

        if ($line === false) {
            break;
        }

        $lines[] = rtrim($line, "\r\n");

        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }

    $lastLine = end($lines);
    $code = is_string($lastLine) ? (int) substr($lastLine, 0, 3) : 0;

    return [$code, implode("\n", $lines)];
}

function email_smtp_command($socket, string $command, array $expectedCodes): array
{
    if ($command !== '') {
        fwrite($socket, $command . "\r\n");
    }

    [$code, $response] = email_smtp_read_response($socket);

    if (!in_array($code, $expectedCodes, true)) {
        return [false, trim($response) !== '' ? $response : 'SMTP command failed: ' . $command];
    }

    return [true, $response];
}

function send_inventory_php_mail(
    string $recipientEmail,
    ?string $recipientName,
    string $subject,
    string $body
): array {
    if (!function_exists('mail')) {
        return ['ok' => false, 'error' => 'PHP mail() is not available.'];
    }

    $senderEmail = email_sender_email();
    $senderName = email_display_name(email_sender_name());
    $replyTo = email_reply_to();
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'From: ' . ($senderName !== '' ? $senderName . ' ' : '') . '<' . $senderEmail . '>',
        'X-Mailer: Inventory KONA',
    ];

    if ($replyTo !== '') {
        $headers[] = 'Reply-To: ' . $replyTo;
    }

    $mailWarning = null;
    set_error_handler(static function (int $severity, string $message) use (&$mailWarning): bool {
        $mailWarning = $message;

        return true;
    });

    try {
        $sent = mail($recipientEmail, email_encoded_header($subject) ?: $subject, $body, implode("\r\n", $headers));
    } finally {
        restore_error_handler();
    }

    return $sent
        ? ['ok' => true, 'error' => null]
        : ['ok' => false, 'error' => $mailWarning ?: 'PHP mail() returned false.'];
}

function send_inventory_smtp_email(
    string $recipientEmail,
    ?string $recipientName,
    string $subject,
    string $body
): array {
    $host = email_smtp_host();

    if ($host === '') {
        return ['ok' => false, 'error' => 'SMTP host is missing. Add it in Website Control.'];
    }

    $senderEmail = email_sender_email();
    $senderName = email_sender_name();
    $replyTo = email_reply_to();
    $port = email_smtp_port();
    $encryption = email_smtp_encryption();
    $timeout = email_smtp_timeout();
    $remote = ($encryption === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $errno = 0;
    $errstr = '';
    $socket = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);

    if (!$socket) {
        return ['ok' => false, 'error' => trim($errstr) !== '' ? $errstr : 'Could not connect to SMTP server.'];
    }

    stream_set_timeout($socket, $timeout);

    try {
        [$code, $response] = email_smtp_read_response($socket);

        if ($code !== 220) {
            return ['ok' => false, 'error' => $response ?: 'SMTP server did not accept connection.'];
        }

        $helloHost = email_header_value((string) ($_SERVER['SERVER_NAME'] ?? 'inventory.ahmaddalao.com'));
        [$ok, $error] = email_smtp_command($socket, 'EHLO ' . ($helloHost !== '' ? $helloHost : 'inventory.ahmaddalao.com'), [250]);

        if (!$ok) {
            return ['ok' => false, 'error' => $error];
        }

        if ($encryption === 'tls') {
            [$ok, $error] = email_smtp_command($socket, 'STARTTLS', [220]);

            if (!$ok) {
                return ['ok' => false, 'error' => $error];
            }

            $cryptoEnabled = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);

            if ($cryptoEnabled !== true) {
                return ['ok' => false, 'error' => 'Could not start SMTP TLS encryption.'];
            }

            [$ok, $error] = email_smtp_command($socket, 'EHLO ' . ($helloHost !== '' ? $helloHost : 'inventory.ahmaddalao.com'), [250]);

            if (!$ok) {
                return ['ok' => false, 'error' => $error];
            }
        }

        $username = email_smtp_username();
        $password = email_smtp_password();

        if ($username !== '' || $password !== '') {
            if ($username === '' || $password === '') {
                return ['ok' => false, 'error' => 'SMTP username and password must both be filled.'];
            }

            [$ok, $error] = email_smtp_command($socket, 'AUTH LOGIN', [334]);

            if (!$ok) {
                return ['ok' => false, 'error' => $error];
            }

            [$ok, $error] = email_smtp_command($socket, base64_encode($username), [334]);

            if (!$ok) {
                return ['ok' => false, 'error' => $error];
            }

            [$ok, $error] = email_smtp_command($socket, base64_encode($password), [235]);

            if (!$ok) {
                return ['ok' => false, 'error' => $error];
            }
        }

        [$ok, $error] = email_smtp_command($socket, 'MAIL FROM:<' . $senderEmail . '>', [250]);

        if (!$ok) {
            return ['ok' => false, 'error' => $error];
        }

        [$ok, $error] = email_smtp_command($socket, 'RCPT TO:<' . $recipientEmail . '>', [250, 251]);

        if (!$ok) {
            return ['ok' => false, 'error' => $error];
        }

        [$ok, $error] = email_smtp_command($socket, 'DATA', [354]);

        if (!$ok) {
            return ['ok' => false, 'error' => $error];
        }

        $headers = [
            'Date: ' . date('r'),
            'From: ' . email_address_header($senderName, $senderEmail),
            'To: ' . email_address_header($recipientName, $recipientEmail),
            'Subject: ' . (email_encoded_header($subject) ?: $subject),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'X-Mailer: Inventory KONA',
        ];

        if ($replyTo !== '') {
            $headers[] = 'Reply-To: ' . $replyTo;
        }

        fwrite($socket, implode("\r\n", $headers) . "\r\n\r\n" . email_normalized_body($body) . "\r\n.\r\n");
        [$code, $response] = email_smtp_read_response($socket);

        if ($code !== 250) {
            return ['ok' => false, 'error' => $response ?: 'SMTP server rejected the message.'];
        }

        email_smtp_command($socket, 'QUIT', [221, 250]);

        return ['ok' => true, 'error' => null];
    } finally {
        if (is_resource($socket)) {
            fclose($socket);
        }
    }
}

function record_email_delivery_log(
    string $emailType,
    string $recipientEmail,
    ?string $recipientName,
    string $subject,
    string $status,
    ?string $errorMessage = null,
    ?int $userId = null,
    ?string $entityType = null,
    ?int $entityId = null
): void {
    try {
        Database::execute(
            'INSERT INTO email_delivery_logs (
                user_id,
                email_type,
                recipient_email,
                recipient_name,
                subject,
                status,
                entity_type,
                entity_id,
                error_message,
                created_at
             ) VALUES (
                :user_id,
                :email_type,
                :recipient_email,
                :recipient_name,
                :subject,
                :status,
                :entity_type,
                :entity_id,
                :error_message,
                NOW()
             )',
            [
                'user_id' => $userId,
                'email_type' => substr($emailType, 0, 80),
                'recipient_email' => substr($recipientEmail, 0, 190),
                'recipient_name' => $recipientName !== null && trim($recipientName) !== '' ? substr(trim($recipientName), 0, 190) : null,
                'subject' => substr(email_header_value($subject), 0, 190),
                'status' => in_array($status, ['sent', 'failed', 'suppressed'], true) ? $status : 'failed',
                'entity_type' => $entityType !== null && $entityType !== '' ? substr($entityType, 0, 80) : null,
                'entity_id' => $entityId,
                'error_message' => $errorMessage !== null && trim($errorMessage) !== '' ? substr(trim($errorMessage), 0, 255) : null,
            ]
        );
    } catch (Throwable $exception) {
        // Email logging must not block login, stock, or approval workflows.
    }
}

function send_inventory_email(
    string $recipientEmail,
    ?string $recipientName,
    string $subject,
    string $body,
    string $emailType,
    ?int $userId = null,
    ?string $entityType = null,
    ?int $entityId = null,
    bool $force = false
): array {
    $recipientEmail = strtolower(trim($recipientEmail));
    $recipientName = $recipientName !== null ? trim($recipientName) : null;
    $subject = email_header_value($subject);
    $status = 'failed';
    $errorMessage = null;

    if (!$force && !email_delivery_enabled()) {
        $status = 'suppressed';
        $errorMessage = 'Email delivery is disabled.';
        record_email_delivery_log($emailType, $recipientEmail, $recipientName, $subject, $status, $errorMessage, $userId, $entityType, $entityId);

        return ['ok' => false, 'status' => $status, 'error' => $errorMessage];
    }

    if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        $errorMessage = 'Recipient email is invalid.';
        record_email_delivery_log($emailType, $recipientEmail, $recipientName, $subject, $status, $errorMessage, $userId, $entityType, $entityId);

        return ['ok' => false, 'status' => $status, 'error' => $errorMessage];
    }

    if ($subject === '') {
        $subject = 'Inventory notification';
    }

    if (email_log_only_enabled()) {
        $status = 'suppressed';
        $errorMessage = 'Log-only test mode is enabled.';
        record_email_delivery_log($emailType, $recipientEmail, $recipientName, $subject, $status, $errorMessage, $userId, $entityType, $entityId);

        return ['ok' => true, 'status' => $status, 'error' => $errorMessage];
    }

    $delivery = email_transport() === 'smtp'
        ? send_inventory_smtp_email($recipientEmail, $recipientName, $subject, $body)
        : send_inventory_php_mail($recipientEmail, $recipientName, $subject, $body);

    if (!empty($delivery['ok'])) {
        $status = 'sent';
        record_email_delivery_log($emailType, $recipientEmail, $recipientName, $subject, $status, null, $userId, $entityType, $entityId);

        return ['ok' => true, 'status' => $status, 'error' => null];
    }

    $errorMessage = (string) ($delivery['error'] ?? 'Email delivery failed.');
    record_email_delivery_log($emailType, $recipientEmail, $recipientName, $subject, $status, $errorMessage, $userId, $entityType, $entityId);

    return ['ok' => false, 'status' => $status, 'error' => $errorMessage];
}

function workflow_email_notification_types(): array
{
    return [
        'request_created',
        'request_approved',
        'request_rejected',
        'request_receipt_review',
        'request_completed',
        'request_receipt_confirmed',
        'handover_requested',
        'handover_created',
        'handover_request_approved',
        'handover_request_rejected',
        'handover_receipt_review',
        'handover_received',
        'handover_delivery_confirmed',
        'handover_waiting_approval',
        'handover_closed',
        'purchase_submitted',
        'purchase_approved',
        'purchase_rejected',
        'purchase_receipt_reported',
        'purchase_completed',
        'stocktake_pending_approval',
        'stocktake_approved',
    ];
}

function send_workflow_notification_email(
    int $userId,
    string $notificationType,
    string $title,
    ?string $message = null,
    ?string $actionUrl = null,
    ?string $entityType = null,
    ?int $entityId = null
): void {
    if (!email_workflow_alerts_enabled() || !in_array($notificationType, workflow_email_notification_types(), true)) {
        return;
    }

    $user = Database::fetch(
        'SELECT id, name, email, is_active
         FROM users
         WHERE id = :id
         LIMIT 1',
        ['id' => $userId]
    );

    if (!$user || (int) ($user['is_active'] ?? 0) !== 1 || trim((string) ($user['email'] ?? '')) === '') {
        return;
    }

    $bodyLines = [
        $title,
        '',
        trim((string) $message) !== '' ? trim((string) $message) : 'Open Inventory KONA for the full details.',
    ];

    if ($actionUrl !== null && trim($actionUrl) !== '') {
        $bodyLines[] = '';
        $bodyLines[] = 'Open details: ' . absolute_url($actionUrl);
    }

    $bodyLines[] = '';
    $bodyLines[] = 'This is an email copy of an in-app notification.';

    send_inventory_email(
        (string) $user['email'],
        (string) $user['name'],
        $title,
        implode("\n", $bodyLines),
        'workflow_' . $notificationType,
        (int) $user['id'],
        $entityType,
        $entityId
    );
}

function site_setting_is_secret(string $key): bool
{
    $definitions = site_setting_definitions();

    return isset($definitions[$key]) && (string) ($definitions[$key]['type'] ?? 'text') === 'secret';
}

function site_setting_groups(array $values = [], bool $includeSecrets = true): array
{
    $groups = site_setting_schema();

    foreach ($groups as &$group) {
        $fields = [];

        foreach ($group['fields'] as $key => $field) {
            $value = (string) ($values[$key] ?? site_setting($key, (string) ($field['default'] ?? '')));
            $isSecret = ($field['type'] ?? 'text') === 'secret';

            if ($isSecret && !$includeSecrets) {
                continue;
            }

            if ($isSecret) {
                $fallback = isset($field['fallback_config'])
                    ? trim((string) app_config((string) $field['fallback_config'], ''))
                    : '';
                $stored = site_setting_stored_value($key);
                $effective = $stored !== null && trim($stored) !== '' ? trim($stored) : $fallback;
                $value = '';
                $field['is_configured'] = $effective !== '';
                $field['configured_source'] = $stored !== null && trim($stored) !== '' ? 'settings' : ($fallback !== '' ? 'environment' : '');
                $field['placeholder'] = $effective !== ''
                    ? (string) ($field['configured_placeholder'] ?? 'Configured. Leave blank to keep current value.')
                    : (string) ($field['placeholder'] ?? 'Paste secret value');
            }

            $fields[] = $field + [
                'key' => $key,
                'value' => $value,
            ];
        }

        $group['fields'] = $fields;
    }
    unset($group);

    return $groups;
}

function site_brand_mark(): string
{
    $customMark = strtoupper(trim(site_setting('brand.mark', '')));

    if ($customMark !== '') {
        return substr($customMark, 0, 4);
    }

    $name = site_setting('app.name', (string) app_config('app.name', 'Inventory HQ'));
    $parts = preg_split('/[^a-z0-9]+/i', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];

    if ($parts === []) {
        return 'IQ';
    }

    if (count($parts) === 1) {
        return strtoupper(substr($parts[0], 0, 2));
    }

    $mark = '';

    foreach ($parts as $part) {
        $mark .= strtoupper(substr($part, 0, 1));

        if (strlen($mark) >= 2) {
            break;
        }
    }

    return $mark !== '' ? $mark : 'IQ';
}

function site_brand_word(): string
{
    $name = trim(site_setting('app.name', (string) app_config('app.name', 'Inventory HQ')));

    if (stripos($name, 'kona') !== false) {
        return 'KONA';
    }

    return $name !== '' ? $name : 'Inventory';
}

function kona_official_logo_asset(): string
{
    return 'brand/kona-logo-official.png';
}

function kona_official_logo_url(): string
{
    return asset_url(kona_official_logo_asset());
}

function kona_official_logo_path(): string
{
    return base_path('assets/' . kona_official_logo_asset());
}

function brand_logo_upload_directory(): string
{
    return base_path('assets/brand/uploads');
}

function brand_custom_logo_asset(): ?string
{
    $asset = trim((string) site_setting_stored_value('brand.logo_path'));

    if ($asset === '') {
        return null;
    }

    $asset = ltrim(str_replace('\\', '/', $asset), '/');

    if (!starts_with($asset, 'brand/uploads/')) {
        return null;
    }

    if (!is_file(base_path('assets/' . $asset))) {
        return null;
    }

    return $asset;
}

function brand_custom_logo_name(): string
{
    return trim((string) site_setting_stored_value('brand.logo_name'));
}

function brand_logo_asset(): string
{
    return brand_custom_logo_asset() ?? kona_official_logo_asset();
}

function brand_logo_url(): string
{
    return asset_url(brand_logo_asset());
}

function brand_logo_path(): string
{
    return base_path('assets/' . brand_logo_asset());
}

function ui_theme_options(): array
{
    return [
        'clean' => 'KONA',
        'classic' => 'Classic Warm',
        'official' => 'KONA Official',
    ];
}

function ui_theme_class(): string
{
    $theme = site_setting('ui.theme', 'clean');

    if (!array_key_exists($theme, ui_theme_options())) {
        $theme = 'clean';
    }

    if ($theme === 'official') {
        return 'theme-clean theme-official';
    }

    return 'theme-' . $theme;
}

function workflow_signoff_image_size_options(): array
{
    return [
        'small' => 'Small - 54 x 54',
        'medium' => 'Medium - 90 x 90',
        'large' => 'Large - 140 x 110',
        'extra_large' => 'Extra Large - 200 x 150',
        'custom' => 'Custom',
    ];
}

function item_xlsx_thumbnail_size_options(): array
{
    return [
        'small' => 'Small - 72 x 54',
        'medium' => 'Medium - 120 x 90',
        'large' => 'Large - 180 x 135',
        'extra_large' => 'Extra Large - 240 x 180',
        'custom' => 'Custom',
    ];
}

function workflow_signoff_template_options(): array
{
    return [
        'detailed' => 'Detailed With Images',
        'compact' => 'Compact Table',
    ];
}

function workflow_signoff_template(): string
{
    $template = site_setting('workflow.signoff_template', 'detailed');
    $options = workflow_signoff_template_options();

    return array_key_exists($template, $options) ? $template : 'detailed';
}

function handover_line_edits_enabled(): bool
{
    return site_setting('workflow.handover_line_edits', '1') === '1';
}

function workflow_signoff_image_size_presets(): array
{
    return [
        'small' => ['width' => 54, 'height' => 54],
        'medium' => ['width' => 90, 'height' => 90],
        'large' => ['width' => 140, 'height' => 110],
        'extra_large' => ['width' => 200, 'height' => 150],
    ];
}

function item_xlsx_thumbnail_size_presets(): array
{
    return [
        'small' => ['width' => 72, 'height' => 54],
        'medium' => ['width' => 120, 'height' => 90],
        'large' => ['width' => 180, 'height' => 135],
        'extra_large' => ['width' => 240, 'height' => 180],
    ];
}

function item_xlsx_thumbnail_export_size(): array
{
    $preset = site_setting('exports.item_xlsx_thumbnail_size', 'medium');
    $presets = item_xlsx_thumbnail_size_presets();

    if ($preset === 'custom') {
        $width = (int) site_setting('exports.item_xlsx_thumbnail_custom_width', '120');
        $height = (int) site_setting('exports.item_xlsx_thumbnail_custom_height', '90');
    } else {
        $size = $presets[$preset] ?? $presets['medium'];
        $width = (int) $size['width'];
        $height = (int) $size['height'];
    }

    return [
        'width' => max(40, min(500, $width)),
        'height' => max(40, min(400, $height)),
    ];
}

function workflow_signoff_document_image_size(string $target = 'excel'): array
{
    $preset = site_setting('workflow.signoff_image_size', 'large');
    $presets = workflow_signoff_image_size_presets();

    if ($preset === 'custom') {
        $width = (int) site_setting('workflow.signoff_image_custom_width', '200');
        $height = (int) site_setting('workflow.signoff_image_custom_height', '200');
    } else {
        $size = $presets[$preset] ?? $presets['large'];
        $width = (int) $size['width'];
        $height = (int) $size['height'];
    }

    $width = max(40, min(600, $width));
    $height = max(40, min(600, $height));

    if ($target === 'pdf') {
        $scale = min(1, 240 / $width, 200 / $height);
        $width = max(40, (int) floor($width * $scale));
        $height = max(40, (int) floor($height * $scale));
    } elseif ($target === 'excel') {
        $width = max(40, min(500, $width));
        $height = max(40, min(400, $height));
    }

    return [
        'width' => $width,
        'height' => $height,
    ];
}

function user_role_options(): array
{
    return [
        'admin' => 'Admin',
        'staff' => 'Staff',
    ];
}

function user_role_label(string $role): string
{
    switch ($role) {
        case 'owner':
            return 'Owner';
        case 'admin':
            return 'Admin';
        case 'staff':
            return 'Staff';
        default:
            return ucfirst($role);
    }
}

function user_position_options(): array
{
    return [
        'owner_operator' => 'Owner / General Manager',
        'cfo' => 'CFO',
        'accountant' => 'Accountant',
        'operations_manager' => 'Operations Manager',
        'storage_manager' => 'Storage Manager',
        'reception_staff' => 'Reception Staff',
        'general_admin' => 'General Admin',
        'staff' => 'Staff',
    ];
}

function user_position_label(?string $position, string $role = ''): string
{
    $position = trim((string) $position);
    $options = user_position_options();

    if ($position !== '' && isset($options[$position])) {
        return $options[$position];
    }

    if ($role === 'owner') {
        return $options['owner_operator'];
    }

    if ($role === 'admin') {
        return $options['general_admin'];
    }

    return $options['staff'];
}

function user_initials(?string $name): string
{
    $name = trim((string) $name);

    if ($name === '') {
        return 'U';
    }

    $parts = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];

    if (count($parts) === 1) {
        return strtoupper(substr((string) $parts[0], 0, 2));
    }

    return strtoupper(substr((string) $parts[0], 0, 1) . substr((string) end($parts), 0, 1));
}

function supplier_type_options(): array
{
    return [
        'product' => 'Product',
        'service' => 'Service',
        'other' => 'Other',
    ];
}

function supplier_type_label(?string $type): string
{
    $type = trim((string) $type);
    $options = supplier_type_options();

    return $options[$type] ?? 'Product';
}

function supplier_type_display(?string $type, ?string $customType = null): string
{
    $type = trim((string) $type);
    $customType = trim((string) $customType);

    if ($type === 'other' && $customType !== '') {
        return $customType;
    }

    return supplier_type_label($type);
}

function access_role_for_position(string $position): string
{
    switch ($position) {
        case 'reception_staff':
        case 'staff':
            return 'staff';
        case 'owner_operator':
        case 'cfo':
        case 'accountant':
        case 'operations_manager':
        case 'storage_manager':
        case 'general_admin':
        default:
            return 'admin';
    }
}

function request_status_label(string $status): string
{
    switch ($status) {
        case 'draft':
            return 'Draft';
        case 'pending':
            return 'Pending';
        case 'approved':
            return 'Approved';
        case 'receipt_review':
            return 'Receipt Review';
        case 'completed':
            return 'Completed';
        case 'rejected':
            return 'Rejected';
        case 'cancelled':
            return 'Cancelled';
        default:
            return ucwords(str_replace('_', ' ', $status));
    }
}

function handover_status_label(string $status): string
{
    switch ($status) {
        case 'requested':
            return 'Requested';
        case 'awaiting_receipt':
            return 'Awaiting Receipt';
        case 'receipt_review':
            return 'Receipt Review';
        case 'delivered':
            return 'Delivered';
        case 'pending_approval':
            return 'Waiting Approval';
        case 'closed':
            return 'Closed';
        case 'rejected':
            return 'Rejected';
        case 'cancelled':
            return 'Cancelled';
        default:
            return ucwords(str_replace('_', ' ', $status));
    }
}

function handover_status_options(): array
{
    return [
        'requested' => 'Requested',
        'awaiting_receipt' => 'Awaiting Receipt',
        'receipt_review' => 'Receipt Review',
        'delivered' => 'Delivered',
        'pending_approval' => 'Waiting Approval',
        'closed' => 'Closed',
        'rejected' => 'Rejected',
        'cancelled' => 'Cancelled',
    ];
}

function purchase_status_label(string $status): string
{
    switch ($status) {
        case 'draft':
            return 'Draft';
        case 'pending_approval':
            return 'Waiting Approval';
        case 'approved':
            return 'Approved';
        case 'receipt_review':
            return 'Receipt Review';
        case 'completed':
            return 'Completed';
        case 'rejected':
            return 'Rejected';
        case 'cancelled':
            return 'Cancelled';
        default:
            return ucwords(str_replace('_', ' ', $status));
    }
}

function purchase_status_badge_type(string $status): string
{
    switch ($status) {
        case 'approved':
        case 'completed':
            return 'success';
        case 'pending_approval':
        case 'receipt_review':
            return 'warning';
        case 'rejected':
        case 'cancelled':
            return 'danger';
        case 'draft':
        default:
            return 'muted';
    }
}

function stocktake_status_label(string $status): string
{
    switch ($status) {
        case 'draft':
            return 'Draft';
        case 'pending_approval':
            return 'Waiting Approval';
        case 'approved':
            return 'Approved';
        case 'cancelled':
            return 'Cancelled';
        default:
            return ucwords(str_replace('_', ' ', $status));
    }
}

function stocktake_status_badge_type(string $status): string
{
    switch ($status) {
        case 'approved':
            return 'success';
        case 'pending_approval':
            return 'warning';
        case 'cancelled':
            return 'danger';
        case 'draft':
        default:
            return 'muted';
    }
}

function permission_catalog(): array
{
    return [
        'dashboard' => [
            'label' => 'Dashboard',
            'permissions' => [
                'dashboard.view' => 'Open the dashboard and live metrics.',
            ],
        ],
        'storages' => [
            'label' => 'Storages',
            'permissions' => [
                'storages.view' => 'Open storage and warehouse pages.',
                'storages.create' => 'Create new storages and warehouses.',
                'storages.edit' => 'Edit storage details.',
                'storages.archive' => 'Delete and recover storages.',
                'storages.copy' => 'Copy storages and their item setup.',
                'storages.export' => 'Export storage reports.',
            ],
        ],
        'items' => [
            'label' => 'Items',
            'permissions' => [
                'items.view' => 'Open item pages and catalog tables.',
                'items.create' => 'Create items or reuse shared SKUs.',
                'items.edit' => 'Edit item details and images.',
                'items.archive' => 'Archive and recover shared items.',
                'items.copy' => 'Copy item setup.',
                'items.remove_from_storage' => 'Remove an item from one storage only.',
                'items.export' => 'Export item reports.',
            ],
        ],
        'movements' => [
            'label' => 'Movements',
            'permissions' => [
                'movements.view' => 'Open the movement log.',
                'movements.create' => 'Create all manual movement log types.',
                'movements.usage' => 'Record item usage only.',
                'movements.restock' => 'Record manual restocks only.',
                'movements.transfer' => 'Transfer stock between storages only.',
                'movements.adjustment' => 'Post manual stock adjustments only.',
                'movements.export' => 'Export movement history.',
            ],
        ],
        'requests' => [
            'label' => 'Requests',
            'permissions' => [
                'requests.view' => 'Open item request pages.',
                'requests.create' => 'Create requests for items.',
                'requests.approve' => 'Approve or reject requests.',
                'requests.receive' => 'Confirm item receipt.',
                'requests.cancel' => 'Cancel pending or in-progress requests.',
                'requests.export' => 'Export request reports.',
            ],
        ],
        'handovers' => [
            'label' => 'Handovers',
            'permissions' => [
                'handovers.view' => 'Open handover pages.',
                'handovers.create' => 'Create handovers from a storage.',
                'handovers.request' => 'Request a temporary handover from a storage owner.',
                'handovers.close' => 'Confirm received quantities and submit used quantities on delivered handovers.',
                'handovers.approve' => 'Approve requested handovers, receipt variances, and closeout details before stock returns to storage.',
                'handovers.export' => 'Export handover reports.',
            ],
        ],
        'purchases' => [
            'label' => 'Purchases',
            'permissions' => [
                'purchases.view' => 'Open supplier purchase pages and restock approvals.',
                'purchases.create' => 'Create supplier purchase drafts and submit them for approval.',
                'purchases.approve' => 'Approve, reject, and finalize supplier purchases.',
                'purchases.receive' => 'Report exact received quantities.',
                'purchases.cancel' => 'Cancel draft or in-progress purchases.',
                'purchases.export' => 'Export supplier purchase reports.',
                'purchases.files' => 'Download and manage protected supplier documents.',
            ],
        ],
        'files' => [
            'label' => 'Files',
            'permissions' => [
                'files.view' => 'Open the central file library for uploaded documents and images.',
                'files.download' => 'Download files from the central file library.',
                'files.manage' => 'Manage protected file records when delete or restore actions are available.',
                'files.export' => 'Export the file library index.',
            ],
        ],
        'stocktakes' => [
            'label' => 'Stocktakes',
            'permissions' => [
                'stocktakes.view' => 'Open cycle count and stocktake pages.',
                'stocktakes.create' => 'Create stocktakes and enter counted quantities.',
                'stocktakes.approve' => 'Approve stocktake variances and post adjustment movements.',
                'stocktakes.cancel' => 'Cancel draft or waiting stocktakes.',
                'stocktakes.export' => 'Export stocktake reports.',
            ],
        ],
        'suppliers' => [
            'label' => 'Suppliers',
            'permissions' => [
                'suppliers.view' => 'Open the supplier directory and purchase history.',
                'suppliers.create' => 'Create supplier records.',
                'suppliers.edit' => 'Edit supplier records.',
                'suppliers.archive' => 'Archive and recover suppliers.',
                'suppliers.export' => 'Export supplier reports.',
            ],
        ],
        'reorder' => [
            'label' => 'Reorder',
            'permissions' => [
                'reorder.view' => 'Open low-stock reorder suggestions.',
                'reorder.create_purchase' => 'Create purchase drafts from reorder suggestions.',
                'reorder.export' => 'Export low-stock reorder suggestions.',
            ],
        ],
        'labels' => [
            'label' => 'Labels',
            'permissions' => [
                'labels.view' => 'Open printable item and storage labels.',
            ],
        ],
        'audit' => [
            'label' => 'Audit Log',
            'permissions' => [
                'audit.view' => 'Open the admin activity audit log.',
                'audit.export' => 'Export admin activity.',
            ],
        ],
        'email_logs' => [
            'label' => 'Email Logs',
            'permissions' => [
                'email_logs.view' => 'Open password reset, test email, and workflow email delivery logs.',
                'email_logs.export' => 'Export email delivery attempts.',
            ],
        ],
        'users' => [
            'label' => 'Users',
            'permissions' => [
                'users.view' => 'Open the access control screen.',
                'users.create' => 'Create admin or staff accounts.',
                'users.edit' => 'Edit users, roles, and passwords.',
                'users.disable' => 'Disable or restore users.',
                'users.permissions' => 'Manage privilege checklists.',
                'users.export' => 'Export the user list.',
            ],
        ],
        'settings' => [
            'label' => 'Settings',
            'permissions' => [
                'settings.view' => 'Open website control settings.',
                'settings.edit' => 'Save website control settings.',
                'settings.secrets' => 'View and save API keys, SMTP passwords, and other sensitive settings.',
            ],
        ],
    ];
}

function permission_keys(): array
{
    $keys = [];

    foreach (permission_catalog() as $group) {
        foreach ($group['permissions'] as $key => $label) {
            $keys[] = $key;
        }
    }

    return $keys;
}

function movement_type_options(): array
{
    return [
        'usage' => 'Usage',
        'restock' => 'Restock',
        'transfer' => 'Transfer',
        'adjustment' => 'Adjustment',
    ];
}

function movement_type_permission(string $movementType): ?string
{
    switch ($movementType) {
        case 'usage':
            return 'movements.usage';
        case 'restock':
            return 'movements.restock';
        case 'transfer':
            return 'movements.transfer';
        case 'adjustment':
            return 'movements.adjustment';
        default:
            return null;
    }
}

function can_create_movement_type(string $movementType): bool
{
    $permission = movement_type_permission($movementType);

    if ($permission === null) {
        return false;
    }

    return Auth::hasPermission('movements.create') || Auth::hasPermission($permission);
}

function movement_type_options_for_user(?array $allowedTypes = null): array
{
    $types = movement_type_options();

    if ($allowedTypes !== null) {
        $allowedMap = array_fill_keys($allowedTypes, true);
        $types = array_filter(
            $types,
            static fn (string $type): bool => isset($allowedMap[$type]),
            ARRAY_FILTER_USE_KEY
        );
    }

    return array_filter(
        $types,
        static fn (string $type): bool => can_create_movement_type($type),
        ARRAY_FILTER_USE_KEY
    );
}

function permission_groups_for_form(array $selectedKeys = []): array
{
    $selectedMap = array_fill_keys($selectedKeys, true);
    $groups = permission_catalog();

    foreach ($groups as &$group) {
        $permissions = [];

        foreach ($group['permissions'] as $key => $copy) {
            $permissions[] = [
                'key' => $key,
                'copy' => $copy,
                'checked' => isset($selectedMap[$key]),
            ];
        }

        $group['permissions'] = $permissions;
    }
    unset($group);

    return $groups;
}

function default_permissions_for_role(string $role): array
{
    if ($role === 'owner') {
        return permission_keys();
    }

    if ($role === 'staff') {
        return [
            'dashboard.view',
            'requests.view',
            'requests.create',
            'requests.receive',
            'requests.cancel',
            'handovers.view',
            'handovers.request',
            'handovers.close',
        ];
    }

    return [
        'dashboard.view',
        'storages.view',
        'storages.create',
        'storages.edit',
        'storages.archive',
        'storages.copy',
        'storages.export',
        'items.view',
        'items.create',
        'items.edit',
        'items.archive',
        'items.copy',
        'items.remove_from_storage',
        'items.export',
        'movements.view',
        'movements.create',
        'movements.usage',
        'movements.restock',
        'movements.transfer',
        'movements.adjustment',
        'movements.export',
        'requests.view',
        'requests.create',
        'requests.approve',
        'requests.receive',
        'requests.cancel',
        'requests.export',
        'handovers.view',
        'handovers.create',
        'handovers.close',
        'handovers.approve',
        'handovers.export',
        'purchases.view',
        'purchases.create',
        'purchases.receive',
        'purchases.export',
        'files.view',
        'files.download',
        'files.export',
        'stocktakes.view',
        'stocktakes.create',
        'stocktakes.approve',
        'stocktakes.cancel',
        'stocktakes.export',
        'suppliers.view',
        'suppliers.create',
        'suppliers.edit',
        'suppliers.archive',
        'suppliers.export',
        'reorder.view',
        'reorder.create_purchase',
        'reorder.export',
        'labels.view',
        'audit.view',
        'audit.export',
        'email_logs.view',
        'email_logs.export',
    ];
}

function default_permissions_for_position(string $position): array
{
    switch ($position) {
        case 'owner_operator':
            return permission_keys();

        case 'cfo':
            return [
                'dashboard.view',
                'storages.view',
                'storages.export',
                'items.view',
                'items.export',
                'movements.view',
                'movements.export',
                'requests.view',
                'requests.export',
                'handovers.view',
                'handovers.export',
                'purchases.view',
                'purchases.create',
                'purchases.approve',
                'purchases.receive',
                'purchases.cancel',
                'purchases.export',
                'purchases.files',
                'files.view',
                'files.download',
                'files.export',
                'suppliers.view',
                'suppliers.create',
                'suppliers.edit',
                'suppliers.export',
                'reorder.view',
                'reorder.create_purchase',
                'reorder.export',
                'audit.view',
                'audit.export',
                'email_logs.view',
                'email_logs.export',
            ];

        case 'accountant':
            return [
                'dashboard.view',
                'storages.view',
                'storages.export',
                'items.view',
                'items.export',
                'movements.view',
                'movements.export',
                'requests.view',
                'requests.export',
                'handovers.view',
                'handovers.export',
                'purchases.view',
                'purchases.create',
                'purchases.receive',
                'purchases.export',
                'purchases.files',
                'files.view',
                'files.download',
                'files.export',
                'suppliers.view',
                'suppliers.create',
                'suppliers.edit',
                'suppliers.export',
                'reorder.view',
                'reorder.export',
                'audit.view',
                'email_logs.view',
                'email_logs.export',
            ];

        case 'operations_manager':
            return [
                'dashboard.view',
                'storages.view',
                'storages.create',
                'storages.edit',
                'storages.archive',
                'storages.copy',
                'storages.export',
                'items.view',
                'items.create',
                'items.edit',
                'items.archive',
                'items.copy',
                'items.remove_from_storage',
                'items.export',
                'movements.view',
                'movements.create',
                'movements.usage',
                'movements.restock',
                'movements.transfer',
                'movements.adjustment',
                'movements.export',
                'requests.view',
                'requests.create',
                'requests.approve',
                'requests.receive',
                'requests.cancel',
                'requests.export',
                'handovers.view',
                'handovers.create',
                'handovers.request',
                'handovers.close',
                'handovers.approve',
                'handovers.export',
                'purchases.view',
                'purchases.create',
                'purchases.approve',
                'purchases.receive',
                'purchases.cancel',
                'purchases.export',
                'purchases.files',
                'files.view',
                'files.download',
                'files.export',
                'stocktakes.view',
                'stocktakes.create',
                'stocktakes.approve',
                'stocktakes.cancel',
                'stocktakes.export',
                'suppliers.view',
                'suppliers.create',
                'suppliers.edit',
                'suppliers.archive',
                'suppliers.export',
                'reorder.view',
                'reorder.create_purchase',
                'reorder.export',
                'labels.view',
                'audit.view',
                'audit.export',
                'email_logs.view',
                'email_logs.export',
            ];

        case 'storage_manager':
            return [
                'dashboard.view',
                'storages.view',
                'storages.create',
                'storages.edit',
                'storages.copy',
                'storages.export',
                'items.view',
                'items.create',
                'items.edit',
                'items.copy',
                'items.remove_from_storage',
                'items.export',
                'movements.view',
                'movements.create',
                'movements.usage',
                'movements.restock',
                'movements.transfer',
                'movements.adjustment',
                'movements.export',
                'requests.view',
                'requests.create',
                'requests.approve',
                'requests.receive',
                'requests.cancel',
                'requests.export',
                'handovers.view',
                'handovers.create',
                'handovers.request',
                'handovers.close',
                'handovers.approve',
                'handovers.export',
                'purchases.view',
                'purchases.receive',
                'files.view',
                'files.download',
                'files.export',
                'stocktakes.view',
                'stocktakes.create',
                'stocktakes.approve',
                'stocktakes.cancel',
                'stocktakes.export',
                'reorder.view',
                'labels.view',
            ];

        case 'reception_staff':
            return [
                'dashboard.view',
                'requests.view',
                'requests.create',
                'requests.receive',
                'requests.cancel',
                'handovers.view',
                'handovers.request',
                'handovers.close',
            ];

        case 'staff':
            return default_permissions_for_role('staff');

        case 'general_admin':
        default:
            return default_permissions_for_role('admin');
    }
}

function sanitize_permission_input(array $permissions): array
{
    $valid = array_fill_keys(permission_keys(), true);
    $normalized = [];

    foreach ($permissions as $permission) {
        $key = trim((string) $permission);

        if ($key !== '' && isset($valid[$key])) {
            $normalized[$key] = true;
        }
    }

    return array_keys($normalized);
}

function request_method(): string
{
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    return $method === 'HEAD' ? 'GET' : $method;
}

function request_path(): string
{
    static $path;

    if ($path !== null) {
        return $path;
    }

    $rawPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $basePath = (string) app_config('app.base_path', '');

    if ($basePath !== '' && $basePath !== '/' && starts_with($rawPath, $basePath)) {
        $rawPath = substr($rawPath, strlen($basePath)) ?: '/';
    }

    $normalized = '/' . trim($rawPath, '/');
    $path = $normalized === '//' ? '/' : rtrim($normalized, '/');

    return $path === '' ? '/' : $path;
}

function url(string $path = '/'): string
{
    $basePath = rtrim((string) app_config('app.base_path', ''), '/');
    $normalized = '/' . ltrim($path, '/');

    if ($normalized === '/index.php') {
        $normalized = '/';
    }

    if ($normalized === '/') {
        return $basePath === '' ? '/' : $basePath;
    }

    return ($basePath === '' ? '' : $basePath) . $normalized;
}

function asset_url(string $path): string
{
    $relativePath = 'assets/' . ltrim($path, '/');
    $assetUrl = url('/' . $relativePath);
    $assetPath = base_path($relativePath);

    if (!is_file($assetPath)) {
        return $assetUrl;
    }

    return $assetUrl . '?v=' . filemtime($assetPath);
}

function request_is_secure(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    $forwardedProto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));

    if ($forwardedProto === 'https') {
        return true;
    }

    $forwardedSsl = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')));

    return $forwardedSsl === 'on';
}

function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('X-Permitted-Cross-Domain-Policies: none');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Permissions-Policy: camera=(self), microphone=(), geolocation=(), payment=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdn.jsdelivr.net 'wasm-unsafe-eval'; worker-src 'self' blob: https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self' https://cdn.jsdelivr.net blob: data:; object-src 'none'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");

    if (request_is_secure()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

function safe_redirect_target(?string $target, string $fallback = '/'): string
{
    $target = preg_replace('/[\x00-\x1F\x7F]+/', '', trim((string) $target));

    if ($target === '') {
        return $fallback;
    }

    if (starts_with($target, '//')) {
        return $fallback;
    }

    $path = (string) parse_url($target, PHP_URL_PATH);
    $query = (string) parse_url($target, PHP_URL_QUERY);
    $fragment = (string) parse_url($target, PHP_URL_FRAGMENT);
    $host = (string) parse_url($target, PHP_URL_HOST);

    if ($host !== '') {
        $requestHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));

        if ($requestHost === '' || strtolower($host) !== $requestHost) {
            return $fallback;
        }
    }

    if ($path === '') {
        $path = '/';
    }

    $basePath = rtrim((string) app_config('app.base_path', ''), '/');

    if ($basePath !== '' && starts_with($path, $basePath)) {
        $path = substr($path, strlen($basePath)) ?: '/';
    }

    $safe = '/' . ltrim($path, '/');

    if ($query !== '') {
        $safe .= '?' . $query;
    }

    if ($fragment !== '') {
        $safe .= '#' . $fragment;
    }

    return $safe;
}

function safe_download_filename(string $filename, string $fallback = 'download'): string
{
    $filename = basename(str_replace('\\', '/', $filename));
    $filename = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', '', $filename));
    $filename = str_replace(['"', "'", ';'], '', $filename);

    if ($filename === '' || $filename === '.' || $filename === '..') {
        $filename = $fallback;
    }

    return substr($filename, 0, 180);
}

function content_disposition_attachment(string $filename): string
{
    $filename = safe_download_filename($filename);
    $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?: 'download';

    return 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($filename);
}

function send_download_headers(string $mimeType, string $filename, int $contentLength): void
{
    header('Content-Type: ' . ($mimeType !== '' ? $mimeType : 'application/octet-stream'));
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: ' . content_disposition_attachment($filename));
    header('Content-Transfer-Encoding: binary');
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');

    if ($contentLength >= 0) {
        header('Content-Length: ' . $contentLength);
    }
}

function csv_safe_cell($value): string
{
    if ($value === null) {
        return '';
    }

    $text = (string) $value;

    if ($text !== '' && preg_match('/^[=+\-@\t\r\n]/', $text) === 1) {
        return "'" . $text;
    }

    return $text;
}

function redirect(string $path = '/'): never
{
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));

    if (strpos($accept, 'application/json') !== false) {
        $flashes = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);

        $lastFlash = end($flashes) ?: null;
        $hasDanger = false;

        foreach ($flashes as $flashMessage) {
            if (($flashMessage['type'] ?? '') === 'danger') {
                $hasDanger = true;
                break;
            }
        }

        json_response([
            'ok' => !$hasDanger,
            'message' => $lastFlash['message'] ?? ($hasDanger ? 'Action failed.' : 'Saved.'),
            'messages' => $flashes,
            'redirect_url' => url($path),
        ], $hasDanger ? 422 : 200);
    }

    header('Location: ' . url($path));
    exit;
}

function redirect_to_referer(string $fallback = '/'): never
{
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    $target = safe_redirect_target($referer, $fallback);

    if ($target !== $fallback) {
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));

        if (strpos($accept, 'application/json') !== false) {
            $flashes = $_SESSION['_flash'] ?? [];
            unset($_SESSION['_flash']);

            $lastFlash = end($flashes) ?: null;
            $hasDanger = false;

            foreach ($flashes as $flashMessage) {
                if (($flashMessage['type'] ?? '') === 'danger') {
                    $hasDanger = true;
                    break;
                }
            }

            json_response([
                'ok' => !$hasDanger,
                'message' => $lastFlash['message'] ?? ($hasDanger ? 'Action failed.' : 'Saved.'),
                'messages' => $flashes,
                'redirect_url' => url($target),
            ], $hasDanger ? 422 : 200);
        }

        header('Location: ' . url($target));
        exit;
    }

    redirect($fallback);
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = [
        'type' => $type,
        'message' => $message,
    ];
}

function consume_flashes(): array
{
    $messages = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);

    return $messages;
}

function old(string $key, $default = '')
{
    return $_SESSION['_old'][$key] ?? $default;
}

function flash_old_input(array $values): void
{
    $_SESSION['_old'] = $values;
}

function consume_old_input(): void
{
    unset($_SESSION['_old']);
}

function input(string $key, $default = '')
{
    return $_POST[$key] ?? $_GET[$key] ?? $default;
}

function query(string $key, $default = '')
{
    return $_GET[$key] ?? $default;
}

function request_wants_json(): bool
{
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));

    return strpos($accept, 'application/json') !== false || $requestedWith === 'xmlhttprequest';
}

function csrf_token(): string
{
    if (!isset($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $token = $_POST['_token'] ?? '';

    if (!hash_equals((string) ($_SESSION['_csrf'] ?? ''), (string) $token)) {
        abort(419, 'Invalid CSRF token.');
    }
}

function error_title_for_status(int $statusCode): string
{
    if ($statusCode === 404) {
        return 'Page Not Found';
    }

    if ($statusCode === 403) {
        return 'Access Blocked';
    }

    if ($statusCode === 419) {
        return 'Session Expired';
    }

    return 'Something Needs Attention';
}

function error_module_target_for_message(string $message): ?array
{
    $normalized = strtolower($message);

    if (trim($normalized) === 'page not found.') {
        return null;
    }

    $targets = [
        'stocktake' => ['path' => '/stocktakes', 'label' => 'Back To Stocktakes', 'permission' => 'stocktakes.view', 'admin_only' => true],
        'handover' => ['path' => '/handovers', 'label' => 'Back To Handovers', 'permission' => 'handovers.view', 'admin_only' => false],
        'request' => ['path' => '/requests', 'label' => 'Back To Requests', 'permission' => 'requests.view', 'admin_only' => false],
        'purchase' => ['path' => '/purchases', 'label' => 'Back To Purchases', 'permission' => 'purchases.view', 'admin_only' => true],
        'supplier' => ['path' => '/suppliers', 'label' => 'Back To Suppliers', 'permission' => 'suppliers.view', 'admin_only' => true],
        'storage' => ['path' => '/storages', 'label' => 'Back To Storages', 'permission' => 'storages.view', 'admin_only' => true],
        'item' => ['path' => '/items', 'label' => 'Back To Items', 'permission' => 'items.view', 'admin_only' => true],
        'file' => ['path' => '/files', 'label' => 'Back To Files', 'permission' => 'files.view', 'admin_only' => true],
        'workflow document' => ['path' => '/files', 'label' => 'Back To Files', 'permission' => 'files.view', 'admin_only' => true],
        'user' => ['path' => '/users', 'label' => 'Back To Admins', 'permission' => 'users.view', 'admin_only' => true],
    ];

    foreach ($targets as $needle => $target) {
        if (strpos($normalized, $needle) !== false) {
            return $target;
        }
    }

    return null;
}

function error_target_allowed(array $target): bool
{
    if (!app_installed() || !Auth::check()) {
        return false;
    }

    if (!empty($target['admin_only']) && Auth::isStaff()) {
        return false;
    }

    $permission = (string) ($target['permission'] ?? '');

    return $permission === '' || Auth::hasPermission($permission);
}

function error_redirect_target(int $statusCode, string $message): ?array
{
    if ($statusCode !== 404 || request_method() !== 'GET' || request_wants_json()) {
        return null;
    }

    $target = error_module_target_for_message($message);

    if ($target === null) {
        return null;
    }

    if (!error_target_allowed($target)) {
        $target = ['path' => '/dashboard', 'label' => 'Back To Dashboard', 'permission' => 'dashboard.view', 'admin_only' => false];
    }

    if (!error_target_allowed($target)) {
        return null;
    }

    if (request_path() === (string) $target['path']) {
        return null;
    }

    return $target;
}

function error_page_actions(int $statusCode, string $message): array
{
    $actions = [];
    $target = error_module_target_for_message($message);

    if ($target !== null && error_target_allowed($target)) {
        $actions[] = [
            'href' => url((string) $target['path']),
            'label' => (string) $target['label'],
            'style' => 'primary',
        ];
    }

    if (app_installed() && Auth::check() && Auth::hasPermission('dashboard.view')) {
        $actions[] = [
            'href' => url('/dashboard'),
            'label' => 'Back To Dashboard',
            'style' => $actions === [] ? 'primary' : 'ghost',
        ];
    } elseif (app_installed()) {
        $actions[] = [
            'href' => url('/login'),
            'label' => 'Back To Login',
            'style' => 'primary',
        ];
    }

    return $actions;
}

function render_standalone_error_page(int $statusCode, string $message): never
{
    $title = error_title_for_status($statusCode);
    $primaryHref = app_installed() ? url('/login') : url('/setup');
    $primaryLabel = app_installed() ? 'Back To Login' : 'Run Setup';

    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . e($title) . '</title><style>body{font-family:ui-sans-serif,system-ui,sans-serif;background:#f7f3eb;color:#111;display:grid;place-items:center;min-height:100vh;margin:0;padding:24px}.card{width:min(720px,100%);background:#fff;padding:36px;border:1px solid #eadfce;border-radius:28px;box-shadow:0 24px 70px rgba(29,24,17,.10)}.code{display:inline-flex;padding:8px 12px;border-radius:999px;background:#fff3cf;color:#8a5a09;font-weight:800;letter-spacing:.08em;text-transform:uppercase}h1{font-size:clamp(34px,6vw,64px);line-height:.95;margin:18px 0 12px}p{color:#726b61;font-size:18px;line-height:1.6;margin:0 0 24px}.actions{display:flex;gap:12px;flex-wrap:wrap}a{display:inline-flex;align-items:center;justify-content:center;min-height:48px;padding:0 20px;border-radius:14px;text-decoration:none;font-weight:800}.primary{background:#e7b64a;color:#1f1608}.ghost{border:1px solid #eadfce;color:#5f4328}</style></head><body><section class="card"><span class="code">' . e((string) $statusCode) . '</span><h1>' . e($title) . '</h1><p>' . e($message) . '</p><div class="actions"><a class="primary" href="' . e($primaryHref) . '">' . e($primaryLabel) . '</a></div></section></body></html>';
    exit;
}

function abort(int $statusCode, string $message): never
{
    if (request_wants_json()) {
        json_response([
            'ok' => false,
            'message' => $message,
            'status' => $statusCode,
        ], $statusCode);
    }

    $redirectTarget = error_redirect_target($statusCode, $message);

    if ($redirectTarget !== null) {
        flash('warning', $message);
        redirect((string) $redirectTarget['path']);
    }

    http_response_code($statusCode);

    if (app_installed() && Auth::check()) {
        View::render('errors/show', [
            'title' => error_title_for_status($statusCode),
            'statusCode' => $statusCode,
            'message' => $message,
            'actions' => error_page_actions($statusCode, $message),
        ]);
        exit;
    }

    render_standalone_error_page($statusCode, $message);
    exit;
}

function json_response(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function format_quantity($value): string
{
    $number = (float) ($value ?? 0);
    $formatted = number_format($number, 2, '.', '');

    return rtrim(rtrim($formatted, '0'), '.') ?: '0';
}

function format_money($value): string
{
    return 'SAR ' . number_format((float) ($value ?? 0), 2);
}

function format_datetime_display(string $value): string
{
    return date('M j, Y g:i A', strtotime($value));
}

function quantity_value($value): float
{
    $normalized = str_replace(',', '', trim((string) $value));

    return $normalized === '' ? 0.0 : (float) $normalized;
}

function is_numeric_value($value): bool
{
    $normalized = str_replace(',', '', trim((string) $value));

    if ($normalized === '') {
        return false;
    }

    return is_numeric($normalized);
}

function active_route(string $path, bool $startsWith = false): string
{
    $current = request_path();

    if ($startsWith) {
        return starts_with($current, $path) ? 'is-active' : '';
    }

    return $current === $path ? 'is-active' : '';
}

function status_badge_class(string $type): string
{
    switch ($type) {
        case 'success':
            return 'badge-success';
        case 'warning':
            return 'badge-warning';
        case 'danger':
            return 'badge-danger';
        case 'info':
            return 'badge-info';
        default:
            return 'badge-muted';
    }
}

function selected($value, $current): string
{
    return (string) $value === (string) $current ? 'selected' : '';
}

function checked(bool $value): string
{
    return $value ? 'checked' : '';
}

function slugify_filename(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?: 'item';

    return trim($value, '-') ?: 'item';
}

function item_unit_options(): array
{
    return [
        'pcs' => 'Pieces (pcs)',
        'box' => 'Box',
        'pack' => 'Pack',
        'carton' => 'Carton',
        'set' => 'Set',
        'roll' => 'Roll',
        'bottle' => 'Bottle',
        'kg' => 'Kilogram (kg)',
        'g' => 'Gram (g)',
        'liter' => 'Liter',
        'ml' => 'Milliliter (ml)',
        'meter' => 'Meter',
        'custom' => 'Custom',
    ];
}

function is_known_unit(string $unit): bool
{
    return array_key_exists($unit, item_unit_options()) && $unit !== 'custom';
}

function item_unit_form_state(?string $storedUnit): array
{
    $storedUnit = trim((string) $storedUnit);

    if ($storedUnit === '' || is_known_unit($storedUnit)) {
        return [
            'unit' => $storedUnit !== '' ? $storedUnit : 'pcs',
            'custom_unit' => '',
        ];
    }

    return [
        'unit' => 'custom',
        'custom_unit' => $storedUnit,
    ];
}

function resolve_item_unit(string $selectedUnit, string $customUnit): string
{
    $selectedUnit = trim($selectedUnit);
    $customUnit = trim($customUnit);

    if ($selectedUnit === 'custom') {
        return $customUnit;
    }

    if (is_known_unit($selectedUnit)) {
        return $selectedUnit;
    }

    return '';
}

function item_barcodes_required(): bool
{
    return site_setting('items.barcode_required', '0') === '1';
}

function normalize_item_barcode($value): string
{
    $barcode = trim((string) $value);
    $barcode = preg_replace('/[\x00-\x1F\x7F]+/', '', $barcode) ?: '';

    return mb_substr($barcode, 0, 120);
}

function item_scan_code(array $item): string
{
    $barcode = normalize_item_barcode($item['barcode'] ?? '');

    return $barcode !== '' ? $barcode : (string) ($item['sku'] ?? '');
}

function reports_can_access(): bool
{
    foreach ([
        'items.export',
        'movements.export',
        'storages.export',
        'requests.export',
        'handovers.export',
        'purchases.export',
        'files.export',
        'stocktakes.export',
        'suppliers.export',
        'reorder.export',
        'audit.export',
        'email_logs.export',
        'users.export',
    ] as $permission) {
        if (Auth::hasPermission($permission)) {
            return true;
        }
    }

    return false;
}

function item_upload_directory(): string
{
    return base_path('uploads/items');
}

function purchase_upload_directory(): string
{
    return base_path('storage/purchases');
}

function workflow_upload_directory(): string
{
    return base_path('storage/workflows');
}

function file_archive_directory(): string
{
    return base_path('storage/files');
}

function ensure_directory_exists(string $path): void
{
    if (is_dir($path)) {
        return;
    }

    if (!mkdir($path, 0755, true) && !is_dir($path)) {
        throw new RuntimeException('Could not create upload directory.');
    }
}

function uploaded_file(string $key): ?array
{
    if (!isset($_FILES[$key]) || !is_array($_FILES[$key])) {
        return null;
    }

    if ((int) ($_FILES[$key]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    return $_FILES[$key];
}

function uploaded_files(string $key): array
{
    if (!isset($_FILES[$key]) || !is_array($_FILES[$key])) {
        return [];
    }

    $file = $_FILES[$key];
    $names = $file['name'] ?? [];

    if (!is_array($names)) {
        return uploaded_file($key) ? [uploaded_file($key)] : [];
    }

    $files = [];
    $count = count($names);

    for ($index = 0; $index < $count; $index++) {
        $error = (int) ($file['error'][$index] ?? UPLOAD_ERR_NO_FILE);

        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        $files[] = [
            'name' => $file['name'][$index] ?? '',
            'type' => $file['type'][$index] ?? '',
            'tmp_name' => $file['tmp_name'][$index] ?? '',
            'error' => $error,
            'size' => $file['size'][$index] ?? 0,
        ];
    }

    return $files;
}

function uploaded_file_at(string $key, int $index): ?array
{
    if (!isset($_FILES[$key]) || !is_array($_FILES[$key]) || !is_array($_FILES[$key]['name'] ?? null)) {
        return null;
    }

    $error = (int) ($_FILES[$key]['error'][$index] ?? UPLOAD_ERR_NO_FILE);

    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    return [
        'name' => $_FILES[$key]['name'][$index] ?? '',
        'type' => $_FILES[$key]['type'][$index] ?? '',
        'tmp_name' => $_FILES[$key]['tmp_name'][$index] ?? '',
        'error' => $error,
        'size' => $_FILES[$key]['size'][$index] ?? 0,
    ];
}

function purchase_document_mime_extensions(): array
{
    return [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
}

function validate_purchase_document_upload(?array $file): ?string
{
    if ($file === null) {
        return null;
    }

    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($error !== UPLOAD_ERR_OK) {
        return 'Document upload failed. Use PDF, JPG, PNG, or WebP under 15 MB.';
    }

    $size = (int) ($file['size'] ?? 0);

    if ($size <= 0 || $size > 15 * 1024 * 1024) {
        return 'Purchase documents must be smaller than 15 MB.';
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');

    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        return 'Uploaded purchase document is invalid.';
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = $finfo ? (string) finfo_file($finfo, $tmpName) : '';

    if ($finfo && PHP_VERSION_ID < 80500) {
        finfo_close($finfo);
    }

    if (!array_key_exists($mimeType, purchase_document_mime_extensions())) {
        return 'Purchase documents must be PDF, JPG, PNG, or WebP.';
    }

    if (starts_with($mimeType, 'image/') && @getimagesize($tmpName) === false) {
        return 'Uploaded purchase image is invalid.';
    }

    return null;
}

function purchase_document_file_meta(array $file): array
{
    $tmpName = (string) ($file['tmp_name'] ?? '');
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = $finfo ? (string) finfo_file($finfo, $tmpName) : '';

    if ($finfo && PHP_VERSION_ID < 80500) {
        finfo_close($finfo);
    }

    $extensions = purchase_document_mime_extensions();

    if (!isset($extensions[$mimeType])) {
        throw new RuntimeException('Unsupported purchase document type.');
    }

    return [
        'mime_type' => $mimeType,
        'extension' => $extensions[$mimeType],
    ];
}

function store_purchase_document(array $file, string $purchaseNumber): array
{
    $meta = purchase_document_file_meta($file);
    ensure_directory_exists(purchase_upload_directory());

    $originalName = basename((string) ($file['name'] ?? 'document'));
    $filename = date('YmdHis') . '-' . slugify_filename($purchaseNumber . '-' . pathinfo($originalName, PATHINFO_FILENAME)) . '-' . substr(bin2hex(random_bytes(5)), 0, 10) . '.' . $meta['extension'];
    $destination = purchase_upload_directory() . '/' . $filename;

    if (!move_uploaded_file((string) ($file['tmp_name'] ?? ''), $destination)) {
        throw new RuntimeException('Could not save the purchase document.');
    }

    return [
        'original_filename' => $originalName !== '' ? $originalName : 'document.' . $meta['extension'],
        'stored_filename' => $filename,
        'mime_type' => $meta['mime_type'],
        'file_size' => (int) ($file['size'] ?? 0),
    ];
}

function purchase_document_path(string $storedFilename): string
{
    return purchase_upload_directory() . '/' . basename($storedFilename);
}

function delete_purchase_document_file(?string $storedFilename): void
{
    $storedFilename = trim((string) $storedFilename);

    if ($storedFilename === '') {
        return;
    }

    $path = purchase_document_path($storedFilename);

    if (is_file($path)) {
        unlink($path);
    }

    $deletedBy = class_exists('Auth') && Auth::user() ? (int) Auth::user()['id'] : null;
    mark_file_asset_deleted_by_relative_path(file_asset_relative_path('storage/purchases', $storedFilename), $deletedBy);
}

function workflow_document_mime_extensions(): array
{
    return [
        'application/pdf' => 'pdf',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.ms-office' => 'xls',
        'text/html' => 'xls',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
}

function validate_workflow_proof_upload(?array $file): ?string
{
    if ($file === null) {
        return null;
    }

    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($error !== UPLOAD_ERR_OK) {
        return 'Proof image upload failed. Use JPG, PNG, or WebP under 10 MB.';
    }

    $size = (int) ($file['size'] ?? 0);

    if ($size <= 0 || $size > 10 * 1024 * 1024) {
        return 'Proof image must be smaller than 10 MB.';
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');

    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        return 'Uploaded proof image is invalid.';
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = $finfo ? (string) finfo_file($finfo, $tmpName) : '';

    if ($finfo && PHP_VERSION_ID < 80500) {
        finfo_close($finfo);
    }

    if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        return 'Proof image must be JPG, PNG, or WebP.';
    }

    if (@getimagesize($tmpName) === false) {
        return 'Uploaded proof image is invalid.';
    }

    return null;
}

function workflow_document_file_meta(array $file): array
{
    $tmpName = (string) ($file['tmp_name'] ?? '');
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = $finfo ? (string) finfo_file($finfo, $tmpName) : '';

    if ($finfo && PHP_VERSION_ID < 80500) {
        finfo_close($finfo);
    }

    $extensions = workflow_document_mime_extensions();

    if (!isset($extensions[$mimeType])) {
        throw new RuntimeException('Unsupported workflow document type.');
    }

    return [
        'mime_type' => $mimeType,
        'extension' => $extensions[$mimeType],
    ];
}

function store_workflow_proof_document(array $file, string $workflowType, string $workflowNumber, string $stage): array
{
    $meta = workflow_document_file_meta($file);
    ensure_directory_exists(workflow_upload_directory());

    $originalName = basename((string) ($file['name'] ?? 'proof'));
    $filename = date('YmdHis') . '-' . slugify_filename($workflowType . '-' . $workflowNumber . '-' . $stage . '-' . pathinfo($originalName, PATHINFO_FILENAME)) . '-' . substr(bin2hex(random_bytes(5)), 0, 10) . '.' . $meta['extension'];
    $destination = workflow_upload_directory() . '/' . $filename;

    if (!move_uploaded_file((string) ($file['tmp_name'] ?? ''), $destination)) {
        throw new RuntimeException('Could not save the workflow proof image.');
    }

    return [
        'original_filename' => $originalName !== '' ? $originalName : 'proof.' . $meta['extension'],
        'stored_filename' => $filename,
        'mime_type' => $meta['mime_type'],
        'file_size' => (int) ($file['size'] ?? 0),
    ];
}

function store_workflow_pdf_document(string $pdfBytes, string $workflowType, string $workflowNumber, string $stage): array
{
    ensure_directory_exists(workflow_upload_directory());

    $filename = date('YmdHis') . '-' . slugify_filename($workflowType . '-' . $workflowNumber . '-' . $stage . '-signoff-img-v10') . '-' . substr(bin2hex(random_bytes(5)), 0, 10) . '.pdf';
    $destination = workflow_upload_directory() . '/' . $filename;

    if (file_put_contents($destination, $pdfBytes) === false) {
        throw new RuntimeException('Could not save the workflow sign-off PDF.');
    }

    return [
        'original_filename' => strtoupper($workflowType) . '-' . $workflowNumber . '-signoff.pdf',
        'stored_filename' => $filename,
        'mime_type' => 'application/pdf',
        'file_size' => filesize($destination) ?: strlen($pdfBytes),
    ];
}

function store_workflow_excel_document(string $sheetBytes, string $workflowType, string $workflowNumber, string $stage): array
{
    ensure_directory_exists(workflow_upload_directory());

    $filename = date('YmdHis') . '-' . slugify_filename($workflowType . '-' . $workflowNumber . '-' . $stage . '-signoff-sheet-img-v10') . '-' . substr(bin2hex(random_bytes(5)), 0, 10) . '.xlsx';
    $destination = workflow_upload_directory() . '/' . $filename;

    if (file_put_contents($destination, $sheetBytes) === false) {
        throw new RuntimeException('Could not save the workflow sign-off sheet.');
    }

    return [
        'original_filename' => strtoupper($workflowType) . '-' . $workflowNumber . '-signoff-sheet.xlsx',
        'stored_filename' => $filename,
        'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'file_size' => filesize($destination) ?: strlen($sheetBytes),
    ];
}

function workflow_document_path(string $storedFilename): string
{
    return workflow_upload_directory() . '/' . basename($storedFilename);
}

function delete_workflow_document_file(?string $storedFilename): void
{
    $storedFilename = trim((string) $storedFilename);

    if ($storedFilename === '') {
        return;
    }

    $path = workflow_document_path($storedFilename);

    if (is_file($path)) {
        unlink($path);
    }

    $deletedBy = class_exists('Auth') && Auth::user() ? (int) Auth::user()['id'] : null;
    mark_file_asset_deleted_by_relative_path(file_asset_relative_path('storage/workflows', $storedFilename), $deletedBy);
}

function validate_item_image_upload(?array $file): ?string
{
    if ($file === null) {
        return null;
    }

    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($error !== UPLOAD_ERR_OK) {
        return 'Image upload failed. Try a JPG, PNG, or WebP under 5 MB.';
    }

    $size = (int) ($file['size'] ?? 0);

    if ($size <= 0 || $size > 5 * 1024 * 1024) {
        return 'Image must be smaller than 5 MB.';
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');

    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        return 'Uploaded image is invalid.';
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = $finfo ? (string) finfo_file($finfo, $tmpName) : '';

    if ($finfo && PHP_VERSION_ID < 80500) {
        finfo_close($finfo);
    }

    if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        return 'Image must be JPG, PNG, or WebP.';
    }

    return null;
}

function store_item_image(array $file, string $itemName): string
{
    $tmpName = (string) ($file['tmp_name'] ?? '');
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = $finfo ? (string) finfo_file($finfo, $tmpName) : '';

    if ($finfo && PHP_VERSION_ID < 80500) {
        finfo_close($finfo);
    }

    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    if (!isset($extensions[$mimeType])) {
        throw new RuntimeException('Unsupported image type.');
    }

    ensure_directory_exists(item_upload_directory());

    $filename = date('YmdHis') . '-' . slugify_filename($itemName) . '-' . substr(bin2hex(random_bytes(5)), 0, 10) . '.' . $extensions[$mimeType];
    $destination = item_upload_directory() . '/' . $filename;

    if (!move_uploaded_file($tmpName, $destination)) {
        throw new RuntimeException('Could not save the uploaded image.');
    }

    return $filename;
}

function duplicate_item_image(?string $imagePath, string $itemName): ?string
{
    $imagePath = trim((string) $imagePath);

    if ($imagePath === '') {
        return null;
    }

    $sourcePath = item_upload_directory() . '/' . basename($imagePath);

    if (!is_file($sourcePath)) {
        return null;
    }

    ensure_directory_exists(item_upload_directory());

    $extension = strtolower((string) pathinfo($sourcePath, PATHINFO_EXTENSION));
    $extension = $extension !== '' ? $extension : 'jpg';
    $filename = date('YmdHis') . '-' . slugify_filename($itemName) . '-' . substr(bin2hex(random_bytes(5)), 0, 10) . '.' . $extension;
    $destination = item_upload_directory() . '/' . $filename;

    if (!copy($sourcePath, $destination)) {
        throw new RuntimeException('Could not reuse the copied image.');
    }

    return $filename;
}

function delete_item_image(?string $imagePath): void
{
    $imagePath = trim((string) $imagePath);

    if ($imagePath === '') {
        return;
    }

    $fullPath = item_upload_directory() . '/' . basename($imagePath);

    if (is_file($fullPath)) {
        unlink($fullPath);
    }

    $deletedBy = class_exists('Auth') && Auth::user() ? (int) Auth::user()['id'] : null;
    mark_file_asset_deleted_by_relative_path(file_asset_relative_path('uploads/items', $imagePath), $deletedBy);
}

function file_asset_group_options(): array
{
    return [
        'all' => 'All files',
        'item_image' => 'Item images',
        'purchase_document' => 'Purchase documents',
        'purchase_line_image' => 'Purchase line images',
        'workflow_proof' => 'Workflow proof images',
        'workflow_pdf' => 'Workflow sign-off PDFs',
        'workflow_excel' => 'Workflow sign-off sheets',
    ];
}

function file_asset_group_label(string $group): string
{
    $groups = file_asset_group_options();

    return $groups[$group] ?? ucwords(str_replace('_', ' ', $group));
}

function file_asset_status_options(): array
{
    return [
        'all' => 'All statuses',
        'active' => 'Available',
        'deleted' => 'Deleted',
    ];
}

function file_library_can_access(?array $user = null): bool
{
    $user = $user ?? Auth::user();

    if (!$user) {
        return false;
    }

    if ((string) ($user['role'] ?? '') === 'staff') {
        return false;
    }

    return in_array((string) ($user['role'] ?? ''), ['owner', 'admin'], true)
        || (string) ($user['position'] ?? '') === 'cfo'
        || Auth::hasPermission('files.view');
}

function file_library_can_download(?array $user = null): bool
{
    if (!file_library_can_access($user)) {
        return false;
    }

    $user = $user ?? Auth::user();

    return in_array((string) ($user['role'] ?? ''), ['owner', 'admin'], true)
        || (string) ($user['position'] ?? '') === 'cfo'
        || Auth::hasPermission('files.download');
}

function file_library_can_export(?array $user = null): bool
{
    if (!file_library_can_access($user)) {
        return false;
    }

    $user = $user ?? Auth::user();

    return in_array((string) ($user['role'] ?? ''), ['owner', 'admin'], true)
        || (string) ($user['position'] ?? '') === 'cfo'
        || Auth::hasPermission('files.export');
}

function file_library_can_manage(?array $user = null): bool
{
    $user = $user ?? Auth::user();

    if (!$user || (string) ($user['role'] ?? '') === 'staff') {
        return false;
    }

    return (string) ($user['role'] ?? '') === 'owner' || Auth::hasPermission('files.manage');
}

function file_asset_relative_path(string $directory, string $storedFilename): string
{
    return trim($directory, '/') . '/' . basename($storedFilename);
}

function file_asset_absolute_path(array $asset): string
{
    $archivePath = trim((string) ($asset['archive_path'] ?? ''));

    if ($archivePath !== '' && is_file(base_path($archivePath))) {
        return base_path($archivePath);
    }

    return base_path((string) ($asset['relative_path'] ?? ''));
}

function file_asset_exists(array $asset): bool
{
    $path = file_asset_absolute_path($asset);

    return $path !== base_path() && is_file($path);
}

function file_asset_mime_type(string $path, string $fallback = 'application/octet-stream'): string
{
    if (!is_file($path)) {
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        if ($extension === 'pdf') {
            return 'application/pdf';
        }

        if (in_array($extension, ['jpg', 'jpeg'], true)) {
            return 'image/jpeg';
        }

        if ($extension === 'png') {
            return 'image/png';
        }

        if ($extension === 'webp') {
            return 'image/webp';
        }

        return $fallback;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = $finfo ? (string) finfo_file($finfo, $path) : '';

    if ($finfo && PHP_VERSION_ID < 80500) {
        finfo_close($finfo);
    }

    return $mimeType !== '' ? $mimeType : $fallback;
}

function file_asset_size(string $path, int $fallback = 0): int
{
    if (!is_file($path)) {
        return $fallback;
    }

    $size = filesize($path);

    return $size === false ? $fallback : (int) $size;
}

function format_file_size($bytes): string
{
    $bytes = max(0, (float) $bytes);

    if ($bytes >= 1024 * 1024) {
        return number_format($bytes / (1024 * 1024), 2) . ' MB';
    }

    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    }

    return number_format($bytes, 0) . ' B';
}

function file_asset_archive_copy(string $sourceRelativePath): ?string
{
    $sourceRelativePath = trim($sourceRelativePath);

    if ($sourceRelativePath === '') {
        return null;
    }

    $sourcePath = base_path($sourceRelativePath);

    if (!is_file($sourcePath)) {
        return null;
    }

    ensure_directory_exists(file_archive_directory());

    $extension = strtolower((string) pathinfo($sourcePath, PATHINFO_EXTENSION));
    $extension = $extension !== '' ? $extension : 'bin';
    $baseName = pathinfo($sourcePath, PATHINFO_FILENAME);
    $filename = date('YmdHis') . '-' . slugify_filename($baseName !== '' ? $baseName : 'file') . '-' . substr(bin2hex(random_bytes(5)), 0, 10) . '.' . $extension;
    $destination = file_archive_directory() . '/' . $filename;

    if (!copy($sourcePath, $destination)) {
        return null;
    }

    return file_asset_relative_path('storage/files', $filename);
}

function file_asset_source_label(string $sourceType): string
{
    switch ($sourceType) {
        case 'item_image':
            return 'Item image';
        case 'purchase_document':
            return 'Purchase document';
        case 'purchase_line_image':
            return 'Purchase line image';
        case 'workflow_proof':
            return 'Workflow proof image';
        case 'workflow_pdf':
            return 'Workflow sign-off PDF';
        case 'workflow_excel':
            return 'Workflow sign-off sheet';
        default:
            return ucwords(str_replace('_', ' ', $sourceType));
    }
}

function file_asset_context_label(array $asset): string
{
    if (!empty($asset['handover_number'])) {
        return (string) $asset['handover_number'];
    }

    if (!empty($asset['request_number'])) {
        return (string) $asset['request_number'];
    }

    if (!empty($asset['purchase_number'])) {
        return (string) $asset['purchase_number'];
    }

    if (!empty($asset['item_name'])) {
        return trim((string) $asset['item_name'] . (!empty($asset['item_sku']) ? ' · ' . $asset['item_sku'] : ''));
    }

    if (!empty($asset['context_type']) && !empty($asset['context_id'])) {
        return ucwords(str_replace('_', ' ', (string) $asset['context_type'])) . ' #' . (int) $asset['context_id'];
    }

    return 'General upload';
}

function file_asset_context_url(array $asset): ?string
{
    if (($asset['context_type'] ?? '') === 'purchase' && !empty($asset['context_id'])) {
        return url('/purchases/' . (int) $asset['context_id']);
    }

    if (($asset['context_type'] ?? '') === 'handover' && !empty($asset['context_id'])) {
        return url('/handovers/' . (int) $asset['context_id']);
    }

    if (($asset['context_type'] ?? '') === 'request' && !empty($asset['context_id'])) {
        return url('/requests/' . (int) $asset['context_id']);
    }

    if (($asset['context_type'] ?? '') === 'item' && !empty($asset['context_id'])) {
        return url('/items/' . (int) $asset['context_id']);
    }

    if (($asset['source_type'] ?? '') === 'item_image' && !empty($asset['source_id'])) {
        return url('/items/' . (int) $asset['source_id']);
    }

    return null;
}

function file_asset_preview_url(array $asset): ?string
{
    if (!starts_with((string) ($asset['mime_type'] ?? ''), 'image/')) {
        return null;
    }

    $relativePath = (string) ($asset['relative_path'] ?? '');

    if (!starts_with($relativePath, 'uploads/items/')) {
        return null;
    }

    if (!file_asset_exists($asset)) {
        return null;
    }

    return url('/uploads/items/' . rawurlencode(basename($relativePath)));
}

function register_file_asset(array $asset): void
{
    if (!site_settings_table_exists()) {
        return;
    }

    try {
        $tableExists = (int) Database::scalar(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = :table_name',
            ['table_name' => 'file_assets']
        );
    } catch (Throwable $exception) {
        return;
    }

    if ($tableExists === 0) {
        return;
    }

    $relativePath = trim((string) ($asset['relative_path'] ?? ''));

    if ($relativePath === '') {
        return;
    }

    $archivePath = trim((string) ($asset['archive_path'] ?? ''));

    if ($archivePath === '') {
        $existingArchivePath = Database::scalar(
            'SELECT archive_path
             FROM file_assets
             WHERE relative_path = :relative_path
             LIMIT 1',
            ['relative_path' => $relativePath]
        );

        if (is_string($existingArchivePath) && trim($existingArchivePath) !== '' && is_file(base_path((string) $existingArchivePath))) {
            $archivePath = (string) $existingArchivePath;
        } else {
            $archivePath = file_asset_archive_copy($relativePath) ?? '';
        }
    }

    Database::execute(
        'INSERT INTO file_assets (
            source_type,
            source_id,
            context_type,
            context_id,
            display_name,
            original_filename,
            stored_filename,
            relative_path,
            archive_path,
            mime_type,
            file_size,
            file_group,
            uploaded_by,
            created_at,
            updated_at
         ) VALUES (
            :source_type,
            :source_id,
            :context_type,
            :context_id,
            :display_name,
            :original_filename,
            :stored_filename,
            :relative_path,
            :archive_path,
            :mime_type,
            :file_size,
            :file_group,
            :uploaded_by,
            COALESCE(:created_at, NOW()),
            NOW()
         )
         ON DUPLICATE KEY UPDATE
            source_type = VALUES(source_type),
            source_id = VALUES(source_id),
            context_type = VALUES(context_type),
            context_id = VALUES(context_id),
            display_name = VALUES(display_name),
            original_filename = VALUES(original_filename),
            stored_filename = VALUES(stored_filename),
            mime_type = VALUES(mime_type),
            file_size = CASE WHEN VALUES(file_size) > 0 THEN VALUES(file_size) ELSE file_size END,
            archive_path = CASE WHEN COALESCE(archive_path, "") != "" THEN archive_path ELSE VALUES(archive_path) END,
            file_group = VALUES(file_group),
            uploaded_by = COALESCE(VALUES(uploaded_by), uploaded_by),
            deleted_at = NULL,
            deleted_by = NULL,
            updated_at = NOW()',
        [
            'source_type' => (string) ($asset['source_type'] ?? 'general'),
            'source_id' => isset($asset['source_id']) ? (int) $asset['source_id'] : null,
            'context_type' => $asset['context_type'] ?? null,
            'context_id' => isset($asset['context_id']) ? (int) $asset['context_id'] : null,
            'display_name' => trim((string) ($asset['display_name'] ?? 'File')) ?: 'File',
            'original_filename' => trim((string) ($asset['original_filename'] ?? basename($relativePath))) ?: basename($relativePath),
            'stored_filename' => trim((string) ($asset['stored_filename'] ?? basename($relativePath))) ?: basename($relativePath),
            'relative_path' => $relativePath,
            'archive_path' => $archivePath !== '' ? $archivePath : null,
            'mime_type' => trim((string) ($asset['mime_type'] ?? 'application/octet-stream')) ?: 'application/octet-stream',
            'file_size' => max(0, (int) ($asset['file_size'] ?? 0)),
            'file_group' => trim((string) ($asset['file_group'] ?? 'general')) ?: 'general',
            'uploaded_by' => isset($asset['uploaded_by']) ? (int) $asset['uploaded_by'] : null,
            'created_at' => $asset['created_at'] ?? null,
        ]
    );
}

function register_item_image_asset(int $itemId, string $imagePath, string $displayName, ?int $userId = null, ?string $createdAt = null): void
{
    $filename = basename($imagePath);

    if ($filename === '') {
        return;
    }

    $relativePath = file_asset_relative_path('uploads/items', $filename);
    $absolutePath = base_path($relativePath);

    register_file_asset([
        'source_type' => 'item_image',
        'source_id' => $itemId,
        'context_type' => 'item',
        'context_id' => $itemId,
        'display_name' => $displayName !== '' ? $displayName : 'Item image',
        'original_filename' => $filename,
        'stored_filename' => $filename,
        'relative_path' => $relativePath,
        'mime_type' => file_asset_mime_type($absolutePath, 'image/jpeg'),
        'file_size' => file_asset_size($absolutePath),
        'file_group' => 'item_image',
        'uploaded_by' => $userId,
        'created_at' => $createdAt,
    ]);
}

function register_purchase_line_image_asset(int $lineId, int $purchaseId, string $imagePath, string $displayName, ?int $userId = null, ?string $createdAt = null): void
{
    $filename = basename($imagePath);

    if ($filename === '') {
        return;
    }

    $relativePath = file_asset_relative_path('uploads/items', $filename);
    $absolutePath = base_path($relativePath);

    register_file_asset([
        'source_type' => 'purchase_line_image',
        'source_id' => $lineId,
        'context_type' => 'purchase',
        'context_id' => $purchaseId,
        'display_name' => $displayName !== '' ? $displayName : 'Purchase line image',
        'original_filename' => $filename,
        'stored_filename' => $filename,
        'relative_path' => $relativePath,
        'mime_type' => file_asset_mime_type($absolutePath, 'image/jpeg'),
        'file_size' => file_asset_size($absolutePath),
        'file_group' => 'purchase_line_image',
        'uploaded_by' => $userId,
        'created_at' => $createdAt,
    ]);
}

function register_purchase_document_asset(int $documentId, int $purchaseId, string $purchaseNumber, array $document, ?int $userId = null, ?string $createdAt = null): void
{
    $filename = basename((string) ($document['stored_filename'] ?? ''));

    if ($filename === '') {
        return;
    }

    $relativePath = file_asset_relative_path('storage/purchases', $filename);
    $absolutePath = base_path($relativePath);
    $documentType = (string) ($document['document_type'] ?? 'proof');

    register_file_asset([
        'source_type' => 'purchase_document',
        'source_id' => $documentId,
        'context_type' => 'purchase',
        'context_id' => $purchaseId,
        'display_name' => trim($purchaseNumber . ' · ' . file_asset_source_label($documentType)) ?: 'Purchase document',
        'original_filename' => (string) ($document['original_filename'] ?? $filename),
        'stored_filename' => $filename,
        'relative_path' => $relativePath,
        'mime_type' => (string) ($document['mime_type'] ?? file_asset_mime_type($absolutePath)),
        'file_size' => (int) ($document['file_size'] ?? file_asset_size($absolutePath)),
        'file_group' => 'purchase_document',
        'uploaded_by' => $userId,
        'created_at' => $createdAt,
    ]);
}

function register_workflow_document_asset(int $documentId, string $workflowType, int $workflowId, string $workflowNumber, array $document, ?int $userId = null, ?string $createdAt = null): void
{
    $filename = basename((string) ($document['stored_filename'] ?? ''));

    if ($filename === '') {
        return;
    }

    $documentType = (string) ($document['document_type'] ?? 'proof_image');
    if ($documentType === 'signoff_pdf') {
        $fileGroup = 'workflow_pdf';
        $sourceType = 'workflow_pdf';
    } elseif ($documentType === 'signoff_excel') {
        $fileGroup = 'workflow_excel';
        $sourceType = 'workflow_excel';
    } else {
        $fileGroup = 'workflow_proof';
        $sourceType = 'workflow_proof';
    }
    $relativePath = file_asset_relative_path('storage/workflows', $filename);
    $absolutePath = base_path($relativePath);

    register_file_asset([
        'source_type' => $sourceType,
        'source_id' => $documentId,
        'context_type' => $workflowType,
        'context_id' => $workflowId,
        'display_name' => trim($workflowNumber . ' · ' . file_asset_source_label($sourceType)) ?: 'Workflow document',
        'original_filename' => (string) ($document['original_filename'] ?? $filename),
        'stored_filename' => $filename,
        'relative_path' => $relativePath,
        'mime_type' => (string) ($document['mime_type'] ?? file_asset_mime_type($absolutePath)),
        'file_size' => (int) ($document['file_size'] ?? file_asset_size($absolutePath)),
        'file_group' => $fileGroup,
        'uploaded_by' => $userId,
        'created_at' => $createdAt,
    ]);
}

function mark_file_asset_deleted_by_relative_path(string $relativePath, ?int $deletedBy = null): void
{
    $relativePath = trim($relativePath);

    if ($relativePath === '') {
        return;
    }

    try {
        Database::execute(
            'UPDATE file_assets
             SET deleted_at = COALESCE(deleted_at, NOW()),
                 deleted_by = COALESCE(:deleted_by, deleted_by),
                 updated_at = NOW()
             WHERE relative_path = :relative_path',
            [
                'deleted_by' => $deletedBy,
                'relative_path' => $relativePath,
            ]
        );
    } catch (Throwable $exception) {
        return;
    }
}

function item_image_url(?string $imagePath): ?string
{
    $imagePath = trim((string) $imagePath);

    if ($imagePath === '') {
        return null;
    }

    $fullPath = item_upload_directory() . '/' . basename($imagePath);

    if (!is_file($fullPath)) {
        return null;
    }

    return url('/uploads/items/' . rawurlencode(basename($imagePath)));
}

function item_xlsx_thumbnail_export_enabled(): bool
{
    return site_setting('exports.item_xlsx_thumbnails', '1') === '1';
}

function storage_xlsx_thumbnail_export_enabled(): bool
{
    return site_setting('exports.storage_xlsx_thumbnails', '1') === '1';
}

function movement_xlsx_thumbnail_export_enabled(): bool
{
    return site_setting('exports.movement_xlsx_thumbnails', '1') === '1';
}

function report_xlsx_thumbnail_export_enabled(): bool
{
    return site_setting('exports.report_xlsx_thumbnails', '1') === '1';
}

function excel_export_barcode_images_enabled(): bool
{
    return site_setting('exports.excel_barcode_images', '1') === '1';
}

function item_initial(?string $value): string
{
    $value = trim((string) $value);

    if ($value === '') {
        return 'I';
    }

    return strtoupper(substr($value, 0, 1));
}

function documentation_sections(): array
{
    return [
        [
            'slug' => 'dashboard',
            'title' => 'Dashboard',
            'icon' => 'dashboard',
            'audience' => 'Owner, Admin, CFO, Accountant, Operations, Storage Managers, Staff',
            'route' => '/dashboard',
            'summary' => 'The landing page for live inventory health, usage trends, pending work, and staff assignments.',
            'features' => [
                'Shows active item counts, storage counts, warehouse counts, total stock units, low-stock items, inventory value, usage, open requests, open handovers, and purchase queue metrics.',
                'Date filters change dashboard charts and usage cards without leaving the page.',
                'Storage filter narrows the dashboard to one storage or warehouse; leaving it blank shows every active storage.',
                'Charts show usage trends and value by location so managers can see where stock is being consumed or held.',
                'Staff users see a simplified dashboard focused on items assigned or handed over to them.',
                'Notification panel shows recent workflow events and plays a sound when new events arrive.',
            ],
            'steps' => [
                'Use the storage filter when you need to review one warehouse or storage area.',
                'Use date from and date to when checking usage for a shift, event day, week, or month.',
                'Open low-stock cards when deciding what needs refill or purchase.',
                'Open request, handover, or purchase cards when something is waiting for action.',
            ],
            'rules' => [
                'Dashboard numbers are summaries; the movement log and item detail pages remain the source of truth for exact history.',
                'Staff dashboard intentionally hides management metrics.',
            ],
        ],
        [
            'slug' => 'global-search',
            'title' => 'Global Search',
            'icon' => 'search',
            'audience' => 'Owner, Admin, CFO, Accountant, Operations, Storage Managers, Staff',
            'route' => '/dashboard',
            'summary' => 'The top search bar finds allowed pages and records from one place without opening each module first.',
            'features' => [
                'Search from the topbar by item name, SKU, storage, request number, handover number, purchase number, supplier, file name, admin, audit activity, or documentation topic.',
                'Results update with AJAX as you type after at least 2 characters.',
                'Use keyboard arrows to move between results and press Enter to open the selected result.',
                'Results are grouped by module such as Pages, Items, Storages, Requests, Handovers, Purchases, Files, Stocktakes, Reorder, Admins, Audit, and Documentation.',
                'If no exact result is selected, submitting the search opens the best fallback page for that user.',
            ],
            'steps' => [
                'Click the Search anything field in the topbar.',
                'Type a SKU, item name, request number, supplier, file name, or documentation topic.',
                'Click a result or use arrow keys and Enter.',
                'Use the target page filters if you need deeper filtering after opening a result.',
            ],
            'rules' => [
                'Global search follows the same permissions as the sidebar and pages.',
                'Staff do not see private admin inventory results or item quantities through global search.',
                'Search is action-based AJAX; it runs when the user types or submits, not by passive polling.',
            ],
        ],
        [
            'slug' => 'storages',
            'title' => 'Storages And Warehouses',
            'icon' => 'storages',
            'audience' => 'Owner, Admin, Operations, Storage Managers',
            'route' => '/storages',
            'summary' => 'Create and manage locations that hold inventory. A warehouse can hold many items, and a storage can hold the same items with different quantities.',
            'features' => [
                'Create unlimited warehouses and storages.',
                'Assign an owner/admin to each storage so requests and handovers go to the right person.',
                'Open a storage to see every assigned item, including items with 0 quantity that need refill.',
                'Storage value is calculated from item quantity multiplied by cost per unit.',
                'Copy a storage setup and choose whether copied items start with 0 quantity.',
                'Delete is soft delete: archived storages can be recovered from the deleted filter.',
                'Export storage reports with every item inside each storage and its quantity/value.',
            ],
            'steps' => [
                'Create warehouse or storage from Storages > Create Storage.',
                'Pick the storage type, owner, and notes.',
                'Open a storage to review assigned items and remaining quantity.',
                'Use Copy when another location should use the same item setup.',
                'Use Deleted filter when you need to recover a removed storage.',
            ],
            'rules' => [
                'Removing an item from one storage must not remove it from every storage.',
                '0 quantity means refill needed, not deleted.',
                'A storage can show an item even when quantity is 0 if the item is assigned there.',
            ],
        ],
        [
            'slug' => 'items',
            'title' => 'Items Catalog',
            'icon' => 'items',
            'audience' => 'Owner, Admin, Operations, Storage Managers',
            'route' => '/items',
            'summary' => 'The shared item catalog: name, SKU, barcode, unit, cost, image, reorder level, and current quantity across locations.',
            'features' => [
                'Create items with SKU, optional real barcode, unit, category, reorder level, cost, notes, and optional image.',
                'Barcode can be typed or filled by clicking the barcode field and scanning with a hardware scanner.',
                'Use units such as pcs by default, or pick another unit when needed.',
                'Upload item images for clarity; thumbnails appear in tables and can expand on click.',
                'Same SKU can exist across storages through shared item assignments and separate storage balances.',
                'Copy an item setup to avoid retyping name, SKU, notes, unit, cost, and image details.',
                'Archive is soft delete and can be recovered.',
                'Export filtered item reports.',
            ],
            'steps' => [
                'Create a new item from Items > Create Item.',
                'If the SKU already exists, add it to a selected storage instead of creating a duplicate catalog record.',
                'Use quantity 0 when assigning an item to a storage that needs tracking but is not stocked yet.',
                'Open an item to see locations, movement history, purchase history, and refill context.',
            ],
            'rules' => [
                'SKU is the stock keeping identifier. It can be shared across storages because quantity belongs to storage balances.',
                'Barcode is the real scannable code printed on an item. If barcode is blank, printable labels fall back to SKU.',
                'Owners can make item barcode mandatory from Website Control > Inventory Controls.',
                'Category is only a grouping label; it should not control stock behavior.',
                'Do not archive an item that is still assigned to storages; remove the specific storage assignment first.',
            ],
        ],
        [
            'slug' => 'item-detail-movements',
            'title' => 'Item Detail And Stock Movements',
            'icon' => 'movements',
            'audience' => 'Owner, Admin, Operations, Storage Managers',
            'route' => '/movements',
            'summary' => 'Movement forms and logs explain exactly how quantities changed per item and location.',
            'features' => [
                'Create usage, restock, transfer, and adjustment movements.',
                'Usage subtracts quantity automatically; employees type 100, not -100.',
                'Restock adds stock to a destination storage.',
                'Transfer moves stock from one storage to another and updates both balances.',
                'Adjustment sets or corrects a balance when a physical count requires correction.',
                'Movement log shows source storage, destination storage, quantities, balances, reference code, notes, user, and date.',
                'AJAX updates refresh visible quantities and history after supported actions.',
            ],
            'steps' => [
                'Open an item and choose the movement type.',
                'Pick source and destination storage depending on movement type.',
                'Enter the positive quantity used, added, moved, or adjusted.',
                'Add reference and notes when the movement comes from an event, supplier receipt, handover, or correction.',
            ],
            'rules' => [
                'Usage is always entered as a positive number and the system subtracts it.',
                'Transfers must not create or destroy stock; they move quantity between locations.',
                'Movement history does not disappear when an item is archived.',
            ],
        ],
        [
            'slug' => 'scan-center',
            'title' => 'Scan Center',
            'icon' => 'scan',
            'audience' => 'Owner, Admin, Operations, Storage Managers',
            'route' => '/scan',
            'summary' => 'Scan Center is the fast barcode/SKU workflow for finding an item and posting common stock actions without browsing the catalog.',
            'features' => [
                'Accepts hardware scanner input in the search field.',
                'Uses browser camera barcode scanning when the device supports BarcodeDetector.',
                'Looks up active items by barcode, SKU, item name, or location.',
                'Shows item image, SKU, barcode, current quantity, stock value, and per-location balances.',
                'Can post quick usage or restock movements through the same stock movement endpoint used by item detail pages.',
                'Links to the item page and printable labels when deeper review is needed.',
            ],
            'steps' => [
                'Open Scan Center.',
                'Click the scan field and scan with a hardware scanner, or use Start Camera Scan on supported phones.',
                'Select the matched item.',
                'Pick movement type, storage, quantity, and notes if you need to post quick usage or restock.',
                'Use Open Item when the movement needs transfer, adjustment, or deeper history review.',
            ],
            'rules' => [
                'Scanner movements use existing permission checks and movement logs.',
                'Usage is entered as a positive number and the app subtracts it.',
                'Camera scanning depends on browser support; hardware scanner and manual barcode entry always remain available.',
            ],
        ],
        [
            'slug' => 'requests',
            'title' => 'Requests',
            'icon' => 'requests',
            'audience' => 'Owner, Admin, Storage Managers, Staff',
            'route' => '/requests',
            'summary' => 'Requests are used when someone asks another storage owner/admin for items, or staff ask for items they need to use.',
            'features' => [
                'Staff can request item quantities without seeing private storage quantities.',
                'Admins can request transfers from one storage owner to another destination storage.',
                'Approver receives notification and can approve or reject.',
                'Requester confirms received quantity, including short receipt such as receiving 98 instead of 100.',
                'Approver confirms receipt variance so stock is corrected and returned when needed.',
                'Request status moves through pending, approved, receipt review, completed, rejected, or cancelled.',
                'Export request reports.',
            ],
            'steps' => [
                'Create a request and select the item lines and quantities needed.',
                'Staff should ask from the assigned storage owner when one is configured.',
                'Approver reviews requested quantities and approves or rejects.',
                'Receiver enters exact received quantities.',
                'Approver confirms variances before the request becomes complete.',
            ],
            'rules' => [
                'Users cannot approve their own request.',
                'Staff requests do not need destination storage because staff ask for use, not storage transfer.',
                'Short receipt must be recorded honestly so the system corrects the remaining stock.',
            ],
        ],
        [
            'slug' => 'handovers',
            'title' => 'Handovers',
            'icon' => 'handover',
            'audience' => 'Owner, Admin, Storage Managers, Reception, Staff',
            'route' => '/handovers',
            'summary' => 'Handovers track temporary items given to someone for a shift, event, reception desk, or same-day use.',
            'features' => [
                'Storage owner/admin can create a handover to staff.',
                'Staff can request a handover for items they need later that day.',
                'Recipient confirms exact received quantity before use.',
                'Used quantity automatically calculates returned quantity.',
                'Staff close the handover after use; status waits for storage owner approval.',
                'Approver confirms returned quantities and closes the handover.',
                'Movement logs record issued, used, and returned stock impact.',
                'Export handover reports.',
            ],
            'steps' => [
                'Create or request a handover and add line items.',
                'Recipient confirms what they actually received.',
                'Recipient enters used quantity after the shift or event.',
                'System calculates returned quantity.',
                'Storage owner approves the closeout so remaining stock returns correctly.',
            ],
            'rules' => [
                'Handover is temporary; request is for asking/transfer workflow.',
                'Staff cannot close a handover directly into final closed status; owner/admin approval is required.',
                'If received quantity is wrong, it must enter receipt review before stock is corrected.',
            ],
        ],
        [
            'slug' => 'purchases',
            'title' => 'Purchases And Receiving',
            'icon' => 'purchases',
            'audience' => 'Owner, CFO, Accountant, Operations, Admin',
            'route' => '/purchases',
            'summary' => 'Purchases track supplier quotes, price lists, receipts, approvals, receiving, and final restocking.',
            'features' => [
                'Create supplier purchase drafts for a destination storage.',
                'Attach quote, price list, receipt, proof image, or scanned PDF before submitting.',
                'Bulk import can process old supplier files and create purchase drafts.',
                'OCR/import uses configured server AI OCR for old Arabic/English scanned PDFs, then falls back to local/browser extraction and manual review.',
                'OCR confidence badges flag low-confidence supplier fields, dates, and line items before draft creation.',
                'Approver can adjust quantities and prices before approval.',
                'Approval does not add stock yet.',
                'Receiver reports exact received quantity and uploads receipt/proof if needed.',
                'Final confirmation creates restock movements, updates storage balances, item total quantity, and weighted average cost.',
                'Self approval and self receipt confirmation are blocked.',
                'Export purchase reports with supplier, storage, status, line details, totals, users, and file names.',
            ],
            'steps' => [
                'Create purchase draft or use Bulk Import.',
                'Select supplier, destination storage, approver, currency, expected date, and line items.',
                'Attach at least one proof document before submitting.',
                'Approver reviews and approves or rejects.',
                'Receiver enters what arrived.',
                'Approver confirms final received quantities to add stock.',
            ],
            'rules' => [
                'Stock increases only after final receipt confirmation, not at approval.',
                'Use receipt review for shortages, overages, or questionable deliveries.',
                'Treat low OCR confidence as a review warning, not as approved inventory data.',
                'Quick-created purchase items appear in inventory only when the purchase workflow confirms them.',
            ],
        ],
        [
            'slug' => 'reports',
            'title' => 'Reports',
            'icon' => 'reports',
            'audience' => 'Owner, CFO, Accountant, Operations, Admin',
            'route' => '/reports',
            'summary' => 'Reports shows a daily operations summary plus export presets for the business reports used most often.',
            'features' => [
                'Daily summary answers what happened on one date: used quantities, touched items, users, locations, and movement timeline.',
                'Groups export shortcuts by Inventory, Workflow, Finance, Supplier, Files, and Audit.',
                'Keeps reports permission-aware so users only see presets they can export.',
                'Provides date, status, and storage preset links where the underlying module supports filters.',
                'Links back to the source pages for review before export.',
            ],
            'steps' => [
                'Open Reports from the sidebar.',
                'Use the Daily Operations filter to pick a date, location, and movement type.',
                'Review usage by item, who moved stock, and the activity timeline.',
                'Export Summary when you need a day-end CSV.',
                'Pick the report card that matches the question.',
                'Use Download CSV for the preset export or Open Source Page to review records first.',
                'Use the target module filters when you need a more specific report.',
            ],
            'rules' => [
                'Reports do not create new data; they reuse existing movements and exports.',
                'Movement logs remain the source of truth for stock history.',
                'CSV exports are permission-checked the same way as the source pages.',
            ],
        ],
        [
            'slug' => 'suppliers',
            'title' => 'Suppliers',
            'icon' => 'supplier',
            'audience' => 'Owner, CFO, Accountant, Operations, Admin',
            'route' => '/suppliers',
            'summary' => 'Supplier directory stores vendor details and purchase history.',
            'features' => [
                'Create supplier records with type, custom type when Other is selected, phone, national address, authorized person, optional email, optional CR, optional VAT/tax number, notes, and active/deleted status.',
                'Supplier pages show linked purchase history.',
                'Archived suppliers can be recovered.',
                'Purchases can reuse existing suppliers or quick-create suppliers from purchase forms.',
                'Export supplier reports.',
            ],
            'steps' => [
                'Create suppliers before purchase if you know the details.',
                'Open supplier detail to see purchase history and status.',
                'Archive suppliers that should not be used for new purchases.',
            ],
            'rules' => [
                'Supplier type, phone, national address, and authorized person are mandatory for new supplier records. If type is Other, write the actual custom type.',
                'Supplier records should be reused rather than duplicated with slightly different spelling.',
                'Supplier documents live under Purchases and Files, not inside supplier notes.',
            ],
        ],
        [
            'slug' => 'files',
            'title' => 'Files',
            'icon' => 'files',
            'audience' => 'Owner, Admin, CFO, Accountant, Storage Managers with file permissions',
            'route' => '/files',
            'summary' => 'Central protected file library for uploaded item images and purchase documents.',
            'features' => [
                'Indexes uploaded item images, copied item images, purchase-line images, supplier quotes, price lists, receipts, and proof files.',
                'Keeps a protected archive copy for tracking.',
                'Search by filename, item, SKU, supplier, purchase number, storage, and uploader.',
                'Filter by type, status, and upload date.',
                'Download through permission-checked routes.',
                'Export file index as CSV.',
            ],
            'steps' => [
                'Open Files from the sidebar.',
                'Use filters to narrow by purchase documents or item images.',
                'Open the linked source when you need purchase or item context.',
                'Download when proof is needed for accounting or review.',
            ],
            'rules' => [
                'Files are restricted; staff do not browse the central file library.',
                'Deleting an original draft document marks the source as deleted but keeps the archive record for audit.',
                'Use purchases for supplier proof; do not store supplier proof only in notes.',
            ],
        ],
        [
            'slug' => 'stocktakes',
            'title' => 'Stocktakes',
            'icon' => 'stocktakes',
            'audience' => 'Owner, Admin, Operations, Storage Managers',
            'route' => '/stocktakes',
            'summary' => 'Stocktakes are controlled physical counts used to correct inventory balances.',
            'features' => [
                'Create a count for one storage.',
                'Enter counted quantity per item.',
                'Variance shows difference between system quantity and counted quantity.',
                'Approval posts adjustment movements to correct balances.',
                'Cancel draft or waiting stocktakes when the count is wrong or no longer needed.',
                'Export stocktake reports.',
            ],
            'steps' => [
                'Create stocktake for a storage.',
                'Count items physically and enter counted quantities.',
                'Review variances carefully.',
                'Approver confirms to post adjustment movements.',
            ],
            'rules' => [
                'Stocktake is for correcting reality; do not use it instead of normal usage, transfer, request, handover, or purchase workflows.',
                'Approval is required before stocktake corrections affect inventory.',
            ],
        ],
        [
            'slug' => 'reorder',
            'title' => 'Reorder Center',
            'icon' => 'reorder',
            'audience' => 'Owner, CFO, Operations, Storage Managers',
            'route' => '/reorder',
            'summary' => 'Reorder center finds items below reorder level and can create purchase drafts.',
            'features' => [
                'Shows low-stock suggestions by item and storage.',
                'Filters suggestions by storage.',
                'Suggested quantity is based on current quantity versus reorder level.',
                'Can create purchase drafts from low-stock suggestions.',
                'Export reorder suggestions.',
            ],
            'steps' => [
                'Open Reorder Center and filter by storage if needed.',
                'Review low-stock suggestions and estimated value.',
                'Pick supplier and approver, then create purchase draft.',
                'Attach supplier proof before submitting the draft.',
            ],
            'rules' => [
                'Reorder suggestions are guidance, not automatic stock changes.',
                'Stock still enters inventory only through purchase receiving or manual restock movement.',
            ],
        ],
        [
            'slug' => 'labels',
            'title' => 'Labels',
            'icon' => 'labels',
            'audience' => 'Owner, Admin, Operations, Storage Managers',
            'route' => '/labels',
            'summary' => 'Printable labels help identify items and storages quickly.',
            'features' => [
                'Generate item labels with item names, SKU, unit, category, image where available, and code.',
                'Generate storage labels with storage name and type.',
                'Search and filter label lists before printing.',
                'Use browser print to print labels.',
            ],
            'steps' => [
                'Open Labels.',
                'Choose items or storages.',
                'Search/filter the labels you want.',
                'Print from the browser.',
            ],
            'rules' => [
                'Labels help physical operations but do not change inventory data.',
                'Use clear item names and SKUs before printing labels.',
            ],
        ],
        [
            'slug' => 'admins-users',
            'title' => 'Admins, Users, Roles, And Positions',
            'icon' => 'users',
            'audience' => 'Owner and admins with user permissions',
            'route' => '/users',
            'summary' => 'Access control manages login accounts, business positions, permissions, and staff ownership.',
            'features' => [
                'Create Owner, Admin, and Staff access levels.',
                'Assign business positions such as CFO, Accountant, Operations Manager, Storage Manager, Reception Staff, General Admin, and Staff.',
                'Position presets apply recommended permissions.',
                'Permissions can be customized per user.',
                'Staff can be assigned to a storage owner so their requests go to the right person.',
                'Admins can send password reset/setup emails to active users.',
                'Disable or restore user accounts.',
                'Export admin/user list.',
            ],
            'steps' => [
                'Create user with name, email, position, access level, and password.',
                'Use position defaults unless the user needs a custom permission set.',
                'Review permissions before saving.',
                'Use Send Reset when a user forgets their password or needs a setup link.',
                'Disable accounts instead of deleting login history.',
            ],
            'rules' => [
                'Role controls access level; position describes the real job.',
                'Owner has all permissions.',
                'Staff should only see the simplified workflows they need.',
                'Do not give approval permissions to users who approve their own work.',
            ],
        ],
        [
            'slug' => 'website-control',
            'title' => 'Website Control',
            'icon' => 'settings',
            'audience' => 'Owner and admins with settings permissions',
            'route' => '/settings/site',
            'summary' => 'Website Control changes dashboard name, page labels, table names, navigation names, and interface style.',
            'features' => [
                'Rename dashboard, sidebar links, page titles, table titles, and dashboard metric labels.',
                'Switch interface style between KONA and Classic Warm.',
                'Control brand mark and sidebar eyebrow.',
                'Control cost-free email settings for password reset emails and optional workflow alert copies.',
                'Use SMTP, PHP mail, or log-only email mode.',
                'Send a test email to confirm Hostinger mail delivery.',
                'Add or replace the OpenAI API key used for purchase document OCR without editing project files.',
                'Enable or disable server-side AI OCR for Arabic and English scanned supplier documents.',
                'Save settings without code changes.',
            ],
            'steps' => [
                'Open Website Control.',
                'Edit the label or style you want changed.',
                'Use Email Delivery to add SMTP host, port, encryption, username, password, sender details, and alert rules.',
                'Use Send Test Email after changing sender or SMTP settings.',
                'Paste an OpenAI key under Purchase OCR if scanned supplier PDFs should be extracted on the server.',
                'Save Website Control.',
                'Use Classic Warm if the KONA style does not fit your workflow.',
            ],
            'rules' => [
                'Website Control changes wording, look, barcode rules, email delivery, and OCR configuration; it does not directly change stock quantities.',
                'Password reset tokens expire after 60 minutes and are stored hashed.',
                'SMTP is recommended for production; PHP mail remains a fallback.',
                'Email workflow alerts are optional; in-app notifications remain the source of truth.',
                'OpenAI keys are masked after saving. Blank means keep the current saved key.',
                'Keep labels short so the sidebar and tables stay readable on phones.',
            ],
        ],
        [
            'slug' => 'email-logs',
            'title' => 'Email Logs',
            'icon' => 'notification',
            'audience' => 'Owner, CFO, Accountant, admins with email log permissions',
            'route' => '/email-logs',
            'summary' => 'Email Logs show every password reset, setup, test email, and workflow email delivery attempt.',
            'features' => [
                'Review sent, failed, and suppressed email attempts.',
                'Filter by recipient, subject, email type, status, and date range.',
                'Open linked workflow records when the email belongs to a request, handover, purchase, stocktake, supplier, item, storage, or user.',
                'Export email delivery attempts for audit review.',
                'Use failed rows to diagnose SMTP host, port, encryption, username, password, and sender issues.',
            ],
            'steps' => [
                'Open Email Logs from the sidebar or global search.',
                'Use status cards to jump between sent, failed, and suppressed emails.',
                'Search by recipient or subject when checking a password reset or workflow alert.',
                'Open the linked source if the email belongs to a workflow record.',
                'Export CSV when finance or management needs a delivery trail.',
            ],
            'rules' => [
                'Email logs are sensitive because they include recipient addresses and delivery errors.',
                'Failed email does not stop inventory workflows; in-app notifications remain the source of truth.',
                'Suppressed means the app intentionally did not send, usually because email is disabled or log-only mode is active.',
                'Fix SMTP settings from Website Control, then send a test email and confirm the new log row.',
            ],
        ],
        [
            'slug' => 'audit-log',
            'title' => 'Audit Log',
            'icon' => 'audit',
            'audience' => 'Owner, CFO, Operations, Admins with audit access',
            'route' => '/audit-log',
            'summary' => 'Audit Log tracks important admin actions and system events for accountability.',
            'features' => [
                'Search system activity by action, entity, user, and date.',
                'Filter by action type and entity type.',
                'Review metadata for context where available.',
                'Export audit activity.',
            ],
            'steps' => [
                'Open Audit Log.',
                'Filter by date when investigating a specific period.',
                'Search by user, entity, or action keyword.',
                'Export when management or finance needs a record.',
            ],
            'rules' => [
                'Audit log supports accountability; it is not a replacement for inventory movement history.',
                'Do not delete operational records just to hide mistakes. Correct them with the correct workflow.',
            ],
        ],
        [
            'slug' => 'exports',
            'title' => 'Exports And Reports',
            'icon' => 'export',
            'audience' => 'Owner, CFO, Accountant, Operations, Admins with export access',
            'route' => '/exports',
            'summary' => 'Exports turn filtered operational data into CSV files for accounting, reporting, and review.',
            'features' => [
                'Export items, storages, movements, requests, handovers, purchases, files, stocktakes, suppliers, reorder suggestions, audit log, and users.',
                'Most exports respect current page filters.',
                'Storage exports include each storage and the items inside it with quantity and value.',
                'Purchase exports include supplier, storage, status, lines, quantities, prices, totals, users, and files.',
                'File exports include original filename, source, context, uploader, size, and archive path.',
            ],
            'steps' => [
                'Filter the page first.',
                'Click Export CSV.',
                'Open the CSV in Excel, Numbers, or Google Sheets.',
            ],
            'rules' => [
                'Exports are snapshots at download time.',
                'Sensitive exports should only be shared with people who need them.',
            ],
        ],
        [
            'slug' => 'notifications-live-updates',
            'title' => 'Notifications And Live Updates',
            'icon' => 'notification',
            'audience' => 'All logged-in users',
            'route' => '/notifications',
            'summary' => 'Notifications and AJAX updates refresh screens after user actions without constant reloads or background table polling.',
            'features' => [
                'Notification menu shows recent workflow activity and refreshes when opened or after an action completes.',
                'Full Notifications page lists the complete log as cards with filters and direct open links.',
                'New unread notifications show a popup toast when the app detects them during an action-triggered notification refresh.',
                'Notification sound plays when a refreshed notification check detects new unread work after browser audio is unlocked.',
                'Requests, handovers, purchases, filters, and key action forms update with AJAX where supported.',
                'Filters instantly refresh result regions only when the user changes a filter, searches, submits, or clicks a filter chip.',
                'Live action forms show feedback and update content after actions.',
            ],
            'steps' => [
                'Keep the app open during operations.',
                'Open All Notifications from the bell or account menu to browse the complete log.',
                'Use notification menu to jump to approvals or reviews.',
                'Use filters normally; supported pages update without a full reload after your input.',
            ],
            'rules' => [
                'The app does not silently refresh tables in the background; it updates after a detected user action.',
                'Notifications tell you what needs attention; the detail page is where the final decision happens.',
            ],
        ],
        [
            'slug' => 'login-security',
            'title' => 'Login And Security',
            'icon' => 'settings',
            'audience' => 'All logged-in users',
            'route' => '/login',
            'summary' => 'Login protects the inventory system and controls what each employee can see or do.',
            'features' => [
                'Users log in with email and password.',
                'Users can request a reset link from the login page.',
                'Password reset links expire after 60 minutes and can only be used once.',
                'Disabled users cannot log in.',
                'Owner, Admin, and Staff roles control broad access.',
                'Permissions control exact features such as create, edit, approve, export, or file download.',
                'Business position controls default permission presets but permissions remain the final control.',
            ],
            'steps' => [
                'Use your assigned email and password.',
                'Use Forgot Password if you need a reset link.',
                'Log out when using shared devices.',
                'Ask an owner/admin if you need access to a page required for your job.',
            ],
            'rules' => [
                'Do not share login accounts.',
                'Do not use someone else account to approve your own request, purchase, or handover.',
                'Approvals should be done by the responsible owner/admin, not the requester.',
            ],
        ],
    ];
}

function documentation_important_sections(): array
{
    return [
        [
            'title' => 'Staff Daily Flow',
            'icon' => 'handover',
            'summary' => 'What staff should request, receive, use, return, and close without seeing private stock totals.',
            'anchor' => 'doc-handovers',
            'tags' => ['Staff', 'Requests', 'Handovers', 'Received quantity', 'Used quantity'],
        ],
        [
            'title' => 'Manager Approval Flow',
            'icon' => 'requests',
            'summary' => 'Where owners/admins approve requests, handovers, purchases, receipt differences, and closeouts.',
            'anchor' => 'doc-requests',
            'tags' => ['Approvals', 'No self approval', 'Receipt review', 'Closeout'],
        ],
        [
            'title' => 'Purchasing And Receiving',
            'icon' => 'purchases',
            'summary' => 'Supplier quotes, price lists, receipts, OCR drafts, final receiving, and weighted cost updates.',
            'anchor' => 'doc-purchases',
            'tags' => ['CFO', 'Accountant', 'Supplier proof', 'Restock'],
        ],
        [
            'title' => 'Stock And Storage',
            'icon' => 'storages',
            'summary' => 'How storage balances, warehouses, transfers, 0-quantity refill items, and movements connect.',
            'anchor' => 'doc-storages',
            'tags' => ['Warehouse', 'Storage', 'Transfers', 'Refill'],
        ],
        [
            'title' => 'Files And Proof',
            'icon' => 'files',
            'summary' => 'Protected document library for receipts, supplier files, item images, and purchase proof.',
            'anchor' => 'doc-files',
            'tags' => ['Files', 'Receipts', 'Images', 'Audit'],
        ],
        [
            'title' => 'Reports And Exports',
            'icon' => 'export',
            'summary' => 'CSV exports for inventory, storage, usage, purchases, files, audit, stocktakes, and users.',
            'anchor' => 'doc-exports',
            'tags' => ['CFO', 'Reports', 'CSV', 'Accounting'],
        ],
        [
            'title' => 'Access Control',
            'icon' => 'users',
            'summary' => 'Owner/admin/staff access, business positions, permissions, and assigned storage owners.',
            'anchor' => 'doc-admins-users',
            'tags' => ['Owner', 'Admin', 'CFO', 'Accountant', 'Staff'],
        ],
        [
            'title' => 'Password Recovery And Email',
            'icon' => 'notification',
            'summary' => 'Cost-free SMTP or PHP mail for reset links, admin setup links, test email, and optional workflow alert copies.',
            'anchor' => 'doc-settings-website-control',
            'tags' => ['Password reset', 'Email', 'SMTP', 'PHP mail', 'Notifications', 'Website Control'],
        ],
    ];
}

function documentation_department_guides(): array
{
    return [
        [
            'department' => 'Owner / General Management',
            'icon' => 'dashboard',
            'roles' => ['Owner'],
            'responsibilities' => [
                'Controls every module, user, permission, setting, export, and audit view.',
                'Reviews high-risk approvals and keeps the system rules clean.',
                'Uses dashboard, reports, audit, files, and website control to monitor the business.',
            ],
            'pages' => ['Dashboard', 'Admins', 'Website Control', 'Audit Log', 'Files', 'Exports'],
            'handoff' => 'Owner grants access, reviews exceptions, and should not be used as a shared login.',
        ],
        [
            'department' => 'CFO / Finance',
            'icon' => 'value',
            'roles' => ['CFO', 'Finance Admin'],
            'responsibilities' => [
                'Approves purchase value, supplier proof, receipt differences, and finance exports.',
                'Uses protected Files to review quotes, price lists, receipts, and proof of purchase.',
                'Tracks inventory value, weighted cost changes, and supplier purchase history.',
            ],
            'pages' => ['Purchases', 'Suppliers', 'Files', 'Reports', 'Audit Log', 'Dashboard'],
            'handoff' => 'Finance approves money and proof; receiving still confirms what physically arrived.',
        ],
        [
            'department' => 'Accountant',
            'icon' => 'document',
            'roles' => ['Accountant', 'Finance User'],
            'responsibilities' => [
                'Creates or reviews supplier purchases, uploads receipts, and exports purchase records.',
                'Checks attached files and supplier information before finance reporting.',
                'Does not need operational delete controls unless explicitly granted.',
            ],
            'pages' => ['Purchases', 'Suppliers', 'Files', 'Exports'],
            'handoff' => 'Accountant prepares and records; approver confirms before stock value changes.',
        ],
        [
            'department' => 'Operations Manager',
            'icon' => 'chart',
            'roles' => ['Operations Manager', 'Admin'],
            'responsibilities' => [
                'Monitors stock health, usage, handovers, requests, stocktakes, reorder needs, and labels.',
                'Fixes operational flow issues without bypassing approval rules.',
                'Coordinates storage owners and staff during events or daily operations.',
            ],
            'pages' => ['Dashboard', 'Storages', 'Items', 'Requests', 'Handovers', 'Stocktakes', 'Reorder', 'Labels'],
            'handoff' => 'Operations owns workflow quality; storage owners own their physical balances.',
        ],
        [
            'department' => 'Storage Manager / Warehouse Owner',
            'icon' => 'storages',
            'roles' => ['Storage Manager', 'Warehouse Owner', 'Admin'],
            'responsibilities' => [
                'Owns one or more storage locations and approves items leaving or returning.',
                'Reviews storage item balances, 0-quantity refill items, transfers, requests, and handovers.',
                'Confirms returned quantity before temporary handovers become closed.',
            ],
            'pages' => ['Storages', 'Items', 'Movement Log', 'Requests', 'Handovers', 'Stocktakes'],
            'handoff' => 'Storage owner approval is what protects stock from silent loss.',
        ],
        [
            'department' => 'Reception / Staff',
            'icon' => 'users',
            'roles' => ['Staff', 'Reception Staff'],
            'responsibilities' => [
                'Requests items needed for work and confirms exactly what was received.',
                'Uses handovers for temporary items, records used quantity, and returns the remainder.',
                'Sees a simplified dashboard focused on assigned work, not private inventory totals.',
            ],
            'pages' => ['Dashboard', 'Requests', 'Handovers', 'Documentation'],
            'handoff' => 'Staff reports reality; admins approve and correct the stock impact.',
        ],
        [
            'department' => 'Admin / Access Control',
            'icon' => 'settings',
            'roles' => ['Owner', 'General Admin'],
            'responsibilities' => [
                'Creates users, assigns positions, applies permission presets, and adjusts custom permissions.',
                'Manages website labels and interface style from Website Control.',
                'Uses documentation to train employees on the exact workflows they should follow.',
            ],
            'pages' => ['Admins', 'Website Control', 'Documentation', 'Audit Log'],
            'handoff' => 'Admin access is powerful; give the least permissions that still let the person do the job.',
        ],
    ];
}

function documentation_section_count(): int
{
    return count(documentation_sections());
}

function documentation_screenshot_url(string $slug): ?string
{
    $safeSlug = preg_replace('/[^a-z0-9\-]/', '', strtolower($slug));

    if ($safeSlug === '') {
        return null;
    }

    foreach (['png', 'webp', 'jpg', 'jpeg'] as $extension) {
        $relativePath = 'docs/screenshots/' . $safeSlug . '.' . $extension;

        if (is_file(base_path('assets/' . $relativePath))) {
            return asset_url($relativePath);
        }
    }

    return null;
}

function documentation_visual_for_section(array $section): array
{
    $steps = [];

    foreach (($section['steps'] ?? []) as $step) {
        $step = trim((string) $step);

        if ($step !== '') {
            $steps[] = $step;
        }

        if (count($steps) >= 3) {
            break;
        }
    }

    if ($steps === []) {
        foreach (($section['features'] ?? []) as $feature) {
            $feature = trim((string) $feature);

            if ($feature !== '') {
                $steps[] = $feature;
            }

            if (count($steps) >= 3) {
                break;
            }
        }
    }

    return [
        'screenshot_url' => documentation_screenshot_url((string) ($section['slug'] ?? '')),
        'route' => (string) ($section['route'] ?? ''),
        'title' => (string) ($section['title'] ?? 'System screen'),
        'icon' => (string) ($section['icon'] ?? 'documentation'),
        'steps' => $steps,
    ];
}

function ui_icon(string $name): string
{
    $icons = [
        'dashboard' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 13h7V4H4zm9 7h7V11h-7zM4 20h7v-5H4zm9-9h7V4h-7z"/></svg>',
        'storages' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7.5 12 3l9 4.5-9 4.5z"/><path d="M3 12l9 4.5 9-4.5"/><path d="M3 16.5 12 21l9-4.5"/></svg>',
        'items' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7.5 12 3l8 4.5v9L12 21l-8-4.5z"/><path d="M12 12 20 7.5"/><path d="M12 12v9"/><path d="M12 12 4 7.5"/></svg>',
        'movements' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 7h11"/><path d="m14 4 4 3-4 3"/><path d="M17 17H6"/><path d="m10 14-4 3 4 3"/></svg>',
        'scan' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8V5a1 1 0 0 1 1-1h3"/><path d="M16 4h3a1 1 0 0 1 1 1v3"/><path d="M20 16v3a1 1 0 0 1-1 1h-3"/><path d="M8 20H5a1 1 0 0 1-1-1v-3"/><path d="M7 12h10"/><path d="M9 9v6"/><path d="M12 9v6"/><path d="M15 9v6"/></svg>',
        'requests' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 6h12"/><path d="M8 12h12"/><path d="M8 18h12"/><path d="M4 6h.01"/><path d="M4 12h.01"/><path d="M4 18h.01"/></svg>',
        'handover' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 12h8"/><path d="m12 8 4 4-4 4"/><path d="M4 7h7a3 3 0 0 1 0 6H9"/><path d="M20 17h-7a3 3 0 0 1 0-6h2"/></svg>',
        'purchases' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h12l2 5H4z"/><path d="M5 8v12h14V8"/><path d="M9 12h6"/><path d="M9 16h6"/></svg>',
        'reports' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 19V5"/><path d="M5 19h14"/><path d="M9 16v-5"/><path d="M13 16V8"/><path d="M17 16v-3"/></svg>',
        'files' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h6l2 2h8v11H4z"/><path d="M4 6v13"/><path d="M8 12h8"/><path d="M8 16h5"/></svg>',
        'documentation' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h9a4 4 0 0 1 4 4v12H9a4 4 0 0 0-4-4z"/><path d="M5 4v12"/><path d="M9 8h5"/><path d="M9 12h6"/></svg>',
        'stocktakes' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 3h6l1 2h3v16H5V5h3z"/><path d="m8 12 2 2 4-5"/><path d="M8 18h8"/></svg>',
        'supplier' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7h11v10H3z"/><path d="M14 10h4l3 3v4h-7z"/><circle cx="7" cy="19" r="2"/><circle cx="18" cy="19" r="2"/></svg>',
        'document' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3h7l5 5v13H7z"/><path d="M14 3v5h5"/><path d="M10 13h6"/><path d="M10 17h6"/></svg>',
        'reorder' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 7h14"/><path d="M7 7l1 12h8l1-12"/><path d="M9 11h6"/><path d="M12 3v4"/><path d="m9 4 3-2 3 2"/></svg>',
        'labels' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h6v6H4z"/><path d="M14 5h6v6h-6z"/><path d="M4 15h6v4H4z"/><path d="M14 15h2"/><path d="M18 15h2"/><path d="M14 19h6"/></svg>',
        'audit' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h12v18H6z"/><path d="M9 7h6"/><path d="M9 11h6"/><path d="M9 15h3"/><path d="m15 16 1.5 1.5L20 14"/></svg>',
        'notification' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10 21a2 2 0 0 0 4 0"/></svg>',
        'users' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 19a4 4 0 0 0-8 0"/><circle cx="12" cy="10" r="3"/><path d="M20 19a4 4 0 0 0-3-3.87"/><path d="M17 7.13A3 3 0 0 1 17 13"/></svg>',
        'settings' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 8.5A3.5 3.5 0 1 0 12 15.5A3.5 3.5 0 1 0 12 8.5Z"/><path d="M19.4 15a1 1 0 0 0 .2 1.1l.1.1a2 2 0 0 1 0 2.8 2 2 0 0 1-2.8 0l-.1-.1a1 1 0 0 0-1.1-.2 1 1 0 0 0-.6.9V20a2 2 0 0 1-4 0v-.2a1 1 0 0 0-.6-.9 1 1 0 0 0-1.1.2l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1 1 0 0 0 .2-1.1 1 1 0 0 0-.9-.6H4a2 2 0 0 1 0-4h.2a1 1 0 0 0 .9-.6 1 1 0 0 0-.2-1.1l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1 1 0 0 0 1.1.2h.1a1 1 0 0 0 .6-.9V4a2 2 0 0 1 4 0v.2a1 1 0 0 0 .6.9 1 1 0 0 0 1.1-.2l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1 1 0 0 0-.2 1.1v.1a1 1 0 0 0 .9.6H20a2 2 0 0 1 0 4h-.2a1 1 0 0 0-.9.6z"/></svg>',
        'plus' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14"/><path d="M5 12h14"/></svg>',
        'export' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/></svg>',
        'filter' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16"/><path d="M7 12h10"/><path d="M10 18h4"/></svg>',
        'menu' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16"/><path d="M4 12h16"/><path d="M4 17h16"/></svg>',
        'search' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6"/><path d="m20 20-4.2-4.2"/></svg>',
        'edit' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m4 20 4.5-1 9-9a2.1 2.1 0 0 0-3-3l-9 9z"/><path d="m13 6 5 5"/></svg>',
        'copy_action' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M6 15H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v1"/></svg>',
        'back' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 12H5"/><path d="m12 5-7 7 7 7"/></svg>',
        'value' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2v20"/><path d="M17 6.5A4.5 4.5 0 0 0 12.5 4h-1A4.5 4.5 0 0 0 7 8.5c0 2 1.3 3.2 5 4 3.7.8 5 2 5 4A4.5 4.5 0 0 1 12.5 21h-1A4.5 4.5 0 0 1 7 18.5"/></svg>',
        'flash' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M13 2 4 14h6l-1 8 9-12h-6z"/></svg>',
        'chart' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 19h16"/><path d="M7 15V9"/><path d="M12 15V5"/><path d="M17 15v-3"/></svg>',
    ];

    $markup = $icons[$name] ?? $icons['flash'];

    return '<span class="ui-icon ui-icon-' . e($name) . '">' . $markup . '</span>';
}

function stock_value($quantity, $costPerUnit): float
{
    return (float) $quantity * (float) $costPerUnit;
}

function app_installed(): bool
{
    return Installer::status()['installed'];
}

function truncate_text(?string $value, int $length = 100): string
{
    $value = trim((string) $value);

    if (mb_strlen($value) <= $length) {
        return $value;
    }

    return rtrim(mb_substr($value, 0, $length - 1)) . '...';
}

function code39_normalize(string $value): string
{
    $value = strtoupper(trim($value));
    $value = preg_replace('/[^0-9A-Z .\-\/+$%]/', '-', $value) ?: '';

    return trim($value, '-') ?: 'INV';
}

function code39_svg(string $value, int $height = 56): string
{
    $patterns = [
        '0' => 'nnnwwnwnn', '1' => 'wnnwnnnnw', '2' => 'nnwwnnnnw', '3' => 'wnwwnnnnn',
        '4' => 'nnnwwnnnw', '5' => 'wnnwwnnnn', '6' => 'nnwwwnnnn', '7' => 'nnnwnnwnw',
        '8' => 'wnnwnnwnn', '9' => 'nnwwnnwnn', 'A' => 'wnnnnwnnw', 'B' => 'nnwnnwnnw',
        'C' => 'wnwnnwnnn', 'D' => 'nnnnwwnnw', 'E' => 'wnnnwwnnn', 'F' => 'nnwnwwnnn',
        'G' => 'nnnnnwwnw', 'H' => 'wnnnnwwnn', 'I' => 'nnwnnwwnn', 'J' => 'nnnnwwwnn',
        'K' => 'wnnnnnnww', 'L' => 'nnwnnnnww', 'M' => 'wnwnnnnwn', 'N' => 'nnnnwnnww',
        'O' => 'wnnnwnnwn', 'P' => 'nnwnwnnwn', 'Q' => 'nnnnnnwww', 'R' => 'wnnnnnwwn',
        'S' => 'nnwnnnwwn', 'T' => 'nnnnwnwwn', 'U' => 'wwnnnnnnw', 'V' => 'nwwnnnnnw',
        'W' => 'wwwnnnnnn', 'X' => 'nwnnwnnnw', 'Y' => 'wwnnwnnnn', 'Z' => 'nwwnwnnnn',
        '-' => 'nwnnnnwnw', '.' => 'wwnnnnwnn', ' ' => 'nwwnnnwnn', '$' => 'nwnwnwnnn',
        '/' => 'nwnwnnnwn', '+' => 'nwnnnwnwn', '%' => 'nnnwnwnwn', '*' => 'nwnnwnwnn',
    ];
    $value = '*' . code39_normalize($value) . '*';
    $narrow = 2;
    $wide = 5;
    $gap = $narrow;
    $x = 0;
    $bars = '';

    foreach (str_split($value) as $character) {
        $pattern = $patterns[$character] ?? $patterns['-'];

        foreach (str_split($pattern) as $index => $widthKey) {
            $width = $widthKey === 'w' ? $wide : $narrow;

            if ($index % 2 === 0) {
                $bars .= '<rect x="' . $x . '" y="0" width="' . $width . '" height="' . $height . '"/>';
            }

            $x += $width;
        }

        $x += $gap;
    }

    return '<svg class="barcode-svg" viewBox="0 0 ' . $x . ' ' . $height . '" role="img" aria-label="' . e(trim($value, '*')) . '" xmlns="http://www.w3.org/2000/svg">' . $bars . '</svg>';
}
