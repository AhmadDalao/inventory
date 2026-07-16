<?php
declare(strict_types=1);

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
