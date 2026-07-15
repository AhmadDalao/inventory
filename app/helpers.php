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
                'nav.assets' => ['label' => 'Assets link', 'default' => 'Assets', 'maxlength' => 60],
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
                'scan.manual_restock_enabled' => [
                    'label' => 'Scan Center manual stock add',
                    'default' => '1',
                    'help' => 'Allows inventory admins to add existing catalog items into a selected storage from Scan Center without scanning a barcode. New items must still be created from Items first.',
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
                'exports.asset_xlsx_thumbnails' => [
                    'label' => 'Asset Excel exports with thumbnails',
                    'default' => '1',
                    'help' => 'Adds XLSX export buttons for company assets with embedded asset photos and barcode images.',
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
                    'default' => 'reconciliation',
                    'help' => 'Reconciliation keeps item rows simple and moves expected/actual differences to the bottom. Use Detailed legacy only if you want the old style.',
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
                'page.assets' => ['label' => 'Assets page title', 'default' => 'Assets', 'maxlength' => 80],
                'page.assets_eyebrow' => ['label' => 'Assets eyebrow', 'default' => 'Company property', 'maxlength' => 80],
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
                'metric.assets_total' => ['label' => 'Assets metric label', 'default' => 'Company Assets', 'maxlength' => 80],
                'metric.assets_assigned' => ['label' => 'Assigned assets metric label', 'default' => 'Assigned Assets', 'maxlength' => 80],
                'metric.assets_maintenance' => ['label' => 'Maintenance assets metric label', 'default' => 'Assets In Maintenance', 'maxlength' => 80],
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

function asset_upload_directory(): string
{
    return base_path('uploads/assets');
}

function asset_document_upload_directory(): string
{
    return base_path('storage/assets');
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
        'reconciliation' => 'Reconciliation',
        'detailed' => 'Detailed legacy',
        'compact' => 'Compact legacy table',
    ];
}

function workflow_signoff_template(): string
{
    $template = site_setting('workflow.signoff_template', 'reconciliation');
    $options = workflow_signoff_template_options();

    return array_key_exists($template, $options) ? $template : 'reconciliation';
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
        'assets' => [
            'label' => 'Assets',
            'permissions' => [
                'assets.view' => 'Open company asset pages and assigned asset cards.',
                'assets.create' => 'Create individual or bulk company asset records.',
                'assets.edit' => 'Edit asset profile, serial, barcode, warranty, and purchase details.',
                'assets.categories' => 'Create, edit, recover, and arrange asset category hierarchy.',
                'assets.archive' => 'Archive and recover asset records.',
                'assets.assign' => 'Assign, transfer, receive, and return asset custody.',
                'assets.maintenance' => 'Create and close asset maintenance records.',
                'assets.status_override' => 'Override asset status with an audit trail.',
                'assets.export' => 'Export company asset reports.',
                'assets.files' => 'Upload and download asset proof, warranty, invoice, and repair files.',
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
            'assets.view',
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
        'assets.view',
        'assets.create',
        'assets.edit',
        'assets.categories',
        'assets.assign',
        'assets.maintenance',
        'assets.export',
        'assets.files',
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
                'assets.view',
                'assets.export',
                'assets.files',
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
                'assets.view',
                'assets.export',
                'assets.files',
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
                'assets.view',
                'assets.create',
                'assets.edit',
                'assets.categories',
                'assets.archive',
                'assets.assign',
                'assets.maintenance',
                'assets.export',
                'assets.files',
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
                'assets.view',
                'assets.create',
                'assets.edit',
                'assets.categories',
                'assets.assign',
                'assets.maintenance',
                'assets.export',
                'assets.files',
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
                'assets.view',
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

function content_disposition_inline(string $filename): string
{
    $filename = safe_download_filename($filename);
    $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?: 'preview';

    return 'inline; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($filename);
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

function send_inline_file_headers(string $mimeType, string $filename, int $contentLength): void
{
    header('Content-Type: ' . ($mimeType !== '' ? $mimeType : 'application/octet-stream'));
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: ' . content_disposition_inline($filename));
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

function item_initial(?string $value): string
{
    $value = trim((string) $value);

    if ($value === '') {
        return 'I';
    }

    return strtoupper(substr($value, 0, 1));
}

function asset_initial(?string $value): string
{
    $value = trim((string) $value);

    if ($value === '') {
        return 'A';
    }

    return strtoupper(substr($value, 0, 1));
}

function ui_icon(string $name): string
{
    $icons = [
        'dashboard' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 13h7V4H4zm9 7h7V11h-7zM4 20h7v-5H4zm9-9h7V4h-7z"/></svg>',
        'storages' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7.5 12 3l9 4.5-9 4.5z"/><path d="M3 12l9 4.5 9-4.5"/><path d="M3 16.5 12 21l9-4.5"/></svg>',
        'items' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7.5 12 3l8 4.5v9L12 21l-8-4.5z"/><path d="M12 12 20 7.5"/><path d="M12 12v9"/><path d="M12 12 4 7.5"/></svg>',
        'assets' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 7h12v12H6z"/><path d="M9 7V5a3 3 0 0 1 6 0v2"/><path d="M9 12h6"/><path d="M9 16h4"/></svg>',
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
