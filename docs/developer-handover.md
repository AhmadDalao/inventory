# Inventory KONA Developer Handover

Updated: 2026-07-22

## 1. What This System Is

Inventory KONA is an internal operations system for KONA. It tracks consumable inventory, storage balances, movement logs, staff requests, handovers, supplier purchases, fixed assets, files, reports, notifications, user permissions, and audit logs.

Production URL: `https://inventory.ahmaddalao.com`

Production path: `/home/u867436826/domains/ahmaddalao.com/public_html/inventory`

Repository: `https://github.com/AhmadDalao/inventory.git`

Main branch: `main`

System data-flow and use-case diagrams: [`docs/system-diagrams.md`](system-diagrams.md)

## 2. Current Architecture

This is a plain PHP and MySQL app. It does not use Laravel. Routes are registered in `index.php`. Bootstrap, database, auth, router, view rendering, and shared helpers live under `app/`.

The refactor keeps behavior unchanged and introduces a domain loader:

- `index.php` loads `app/bootstrap.php` and `app/modules.php`.
- `app/module_manifest.php` is the explicit grouped domain module graph. It lists the focused modules by domain instead of routing through aggregate compatibility shims.
- `app/modules.php` is now a loader only. It flattens the manifest and requires each focused module.
- `app/helpers.php` still loads bootstrap-safe helpers, while permission catalogs, role defaults, request/security helpers, branding/upload options, settings schema/accessors, and presentation helpers now live under `app/support/`.
- `app/Maintenance.php` is the schema/bootstrap orchestrator. Boot setup, reusable schema helpers, schema-current checks, backfills, and permission seed routines live under `app/maintenance/`.
- Old aggregate files now only load `app/modules.php` for compatibility, or load their focused child modules when included directly by older tooling.
- Existing route handler function names are preserved.
- The current manifest contains 11 domain groups and 146 focused modules.

Do not add new code to these compatibility loaders:

- `app/controllers.php`
- `app/workflows.php`
- `app/company_assets.php`
- `app/report_presets.php`

### Frontend Asset Architecture

Frontend assets are registered in `app/modules/frontend_assets.php`. The layout adds a file-modification cache key to every registered file, so a deployment changes asset URLs without a build step.

CSS loads in this strict cascade order:

1. `assets/css/foundation.css`: variables, fonts, reset, and typography.
2. `assets/css/shell.css`: sidebar, topbar, navigation, and page containers.
3. `assets/css/components.css`: buttons, forms, dialogs, dropdowns, notifications, and shared controls.
4. `assets/css/tables.css`: tables, pagination, row action menus, and intentional table scrolling.
5. `assets/css/workflows.css`: shared request, handover, and purchase form patterns.
6. `assets/css/domains/*.css`: inventory, scan, handovers, purchases/OCR, reports, admin, settings, documentation, and assets.
7. `assets/css/themes/*.css`: Classic Warm, KONA, and Official KONA.
8. `assets/css/print.css`: print-only output.
9. `assets/css/mobile.css`: responsive rules and final mobile precedence.

Do not use CSS `@import`. Add a stylesheet to `frontend_stylesheets()` in the correct cascade position. Keep `mobile.css` last. Desktop data tables intentionally remain tables on phones and scroll inside their table wrapper; do not reintroduce stacked pseudo-table cards.

JavaScript uses native browser ES modules with no Vite, Webpack, or generated bundle:

- `assets/app.js` imports modules, registers their initializers, and starts the application after `DOMContentLoaded`.
- `assets/js/core/registry.js` owns initializer registration and safe reinitialization.
- `assets/js/core/` contains DOM, events, formatting, and HTTP/AJAX primitives.
- `assets/js/ui/` contains navigation, dialogs, media, search, comboboxes, notifications, tables, filters, and action menus.
- `assets/js/domains/` contains inventory/workflow-specific behavior such as handovers, movements, scan/manual stock, purchases, OCR, assets, reports, settings, permissions, labels, and reorder.

Every UI/domain module exports an idempotent `init(root = document)` function. It must bind only inside `root`, mark or otherwise guard initialized elements, and tolerate being called more than once. Never put one-off page initialization directly in a module body.

AJAX replacements use two stable events:

- `inventory:action-complete`: announces that a user action completed and lets affected metrics or regions refresh.
- `inventory:content-replaced`: carries the replaced root. The registry reruns all initializers against that root exactly once per element.

When adding frontend behavior:

1. Put reusable interaction in `assets/js/ui/`; put business-page behavior in `assets/js/domains/`.
2. Export `init(root = document)` and make it safe to rerun.
3. Import and register it in `assets/app.js` with a unique registry name.
4. Avoid browser-native `confirm()`; use the shared dialog module.
5. Dispatch `inventory:content-replaced` after replacing live HTML.
6. Run `node --check` on every module and `php tests/frontend_assets.php`.

## 3. Module Map

| Module | Purpose |
|---|---|
| `app/modules/core.php` | Compatibility loader for shared core modules. New shared helpers belong in the focused core files below. |
| `app/modules/core_feedback.php` | Flash error helper and installed-app redirect guard. |
| `app/modules/core_selects.php` | Shared item/storage/admin selector queries, entity id normalization, and user lookup/404 helper. |
| `app/modules/core_exports.php` | CSV and XLSX download response helpers. |
| `app/modules/core_report_filters.php` | Shared report summary SQL where-clause builder. |
| `app/modules/settings.php` | Compatibility loader for Website Control modules. New settings logic belongs in the focused settings files below. |
| `app/modules/settings_payload.php` | Website Control submitted setting normalization, secret clearing, option validation, and range validation. |
| `app/modules/settings_logo.php` | Brand logo upload validation, logo storage, logo setting persistence, and old-logo cleanup helpers. |
| `app/modules/settings_logo_actions.php` | Website logo upload/clear submit handler and audit activity. |
| `app/modules/settings_pages.php` | Website Control page render handler, grouped setting payloads, secret visibility, and OCR health panel payload. |
| `app/modules/settings_actions.php` | Website Control save submit handler and owner-only test email submit handler. |
| `app/modules/email.php` | Compatibility loader for older direct includes. Primary loading comes from `app/module_manifest.php`, which lists the focused email modules directly. |
| `app/modules/email_settings.php` | Email enablement, password reset/workflow alert flags, transport, SMTP, sender, and reply-to setting helpers. |
| `app/modules/email_headers.php` | Safe email header, display-name, address-header, and CRLF body normalization helpers. |
| `app/modules/email_smtp.php` | SMTP socket transport, SMTP command/response handling, TLS/authentication, and SMTP message send. |
| `app/modules/email_delivery.php` | PHP `mail()` transport, delivery orchestration, log-only mode, recipient validation, and email delivery log writes. |
| `app/modules/email_workflow.php` | Workflow notification email type allowlist and in-app notification email copy dispatcher. |
| `app/modules/options.php` | Compatibility loader for option catalogs. New option logic belongs in focused `option_*` modules. |
| `app/modules/option_users.php` | User role, position, initials, and position-to-access helpers. |
| `app/modules/option_suppliers.php` | Supplier type options and labels, including custom `Other` display. |
| `app/modules/option_workflows.php` | Request, handover, purchase, and stocktake status labels/badge helpers. |
| `app/modules/option_movements.php` | Movement type options and movement permission filtering. |
| `app/modules/option_assets.php` | Asset status, condition, tone, and event/action labels. |
| `app/modules/option_items.php` | Item units, barcode requirement, manual restock setting, and scan-code helpers. |
| `app/modules/option_reports.php` | Report access helper based on export permissions. |
| `app/modules/auth.php` | Compatibility loader for older direct includes. Primary loading comes from `app/module_manifest.php`, which lists focused auth modules directly. |
| `app/modules/auth_request.php` | Request IP/user-agent helpers, login throttling, password reset throttling, and login attempt audit writes. |
| `app/modules/auth_password_resets.php` | Password reset token hashing, token creation, token lookup, and reset email dispatch. |
| `app/modules/auth_pages.php` | Setup, login, forgot-password, and reset-password page render handlers. |
| `app/modules/auth_actions.php` | Setup submit, login submit, logout, forgot-password submit, and reset-password submit handlers. |
| `app/modules/users.php` | Compatibility loader for older direct includes. Primary loading comes from `app/module_manifest.php`, which lists focused user modules directly. |
| `app/modules/user_permissions.php` | User permission persistence and Auth permission cache reset after updates. |
| `app/modules/user_queries.php` | User list data, active user selectors, active staff selectors, and permission-scoped user selectors. |
| `app/modules/user_pages.php` | User index/create/edit page render handlers and form payload setup. |
| `app/modules/user_actions.php` | User create, update, disable/restore, and admin-triggered password reset submit handlers. |
| `app/modules/item_support.php` | Compatibility loader for item/catalog helpers. Primary loading comes from the focused item modules below. |
| `app/modules/item_filters.php` | Item list filters, item SQL where clauses, filtered storage quantity selects, and displayed quantity selection. |
| `app/modules/item_lookup.php` | Active item SKU/barcode lookup, copy-source lookup, and item detail lookup/404 handling. |
| `app/modules/item_history.php` | Item movement metrics, latest movement, storage balances, balance map, and AJAX response payloads. |
| `app/modules/item_uploads.php` | Item image upload normalization before persistence handlers store or duplicate images. |
| `app/modules/item_storage_assignments.php` | Storage assignment validation, item-location balance records, preferred storage selection, and assignment creation. |
| `app/modules/item_form_payloads.php` | Item create/edit form default payloads. |
| `app/modules/item_pages.php` | Item index/create/show/edit page render handlers. |
| `app/modules/items.php` | Item create/edit persistence handlers. |
| `app/modules/item_actions.php` | Item archive/recover and item-location removal handlers. |
| `app/modules/item_movements.php` | Item detail movement submit handler for usage, restock, adjustment, and transfer. |
| `app/modules/inventory.php` | Compatibility shim for older direct includes. New code should use `item_support.php` and `items.php`. |
| `app/modules/storage_support.php` | Compatibility loader for storage/location helpers. New storage logic belongs in the focused storage modules below. |
| `app/modules/storage_filters.php` | Storage list filters and shared storage SQL where clauses. |
| `app/modules/storage_ownership.php` | Storage owner lookup, owned-storage selectors, active-name checks, storage type labels, and copy-source name helpers. |
| `app/modules/storage_lookup.php` | Storage detail lookup/404 handling and summary metrics for one storage. |
| `app/modules/storage_inventory.php` | Storage item rows and storage summary list metrics. |
| `app/modules/storage_form_payloads.php` | Storage create/edit form default payloads. |
| `app/modules/storage_pages.php` | Storage index, detail, create, and edit page render handlers. |
| `app/modules/storages.php` | Storage create, edit, archive, and recover persistence handlers. |
| `app/modules/inventory_stock.php` | Stock movement posting, item storage balance writes, item quantity snapshot sync, and storage inventory clone helpers. |
| `app/modules/item_packages.php` | Item package preset labels, package conversion presets, and package preset save/delete handlers. |
| `app/modules/movements.php` | Compatibility loader for movement-log helpers and page handlers. |
| `app/modules/movement_filters.php` | Movement-log query filters and shared movement SQL where clauses. |
| `app/modules/movement_scope.php` | Location-scoped movement quantity and balance display helpers. |
| `app/modules/movement_pages.php` | Movement-log index page route handler and render payload. |
| `app/modules/dashboard.php` | Dashboard page route orchestration and final render call. |
| `app/modules/dashboard_filters.php` | Dashboard date/storage filter parsing, selected storage lookup, movement scope SQL, and filter labels. |
| `app/modules/dashboard_metrics.php` | Dashboard usage trend, storage value breakdown, workflow queues, purchase queue metrics, stocktake queue, and reorder pressure snapshot. |
| `app/modules/dashboard_payloads.php` | Dashboard staff payload, summary cards, recent movements, top usage, and low-stock item query builders. |
| `app/modules/exports.php` | Compatibility shim for older direct includes. Primary loading comes from `app/module_manifest.php`, which lists the focused export modules directly. |
| `app/modules/export_items.php` | Item CSV/XLSX exports, optional thumbnails, barcode text/images, and filtered item export rows. |
| `app/modules/export_movements.php` | Movement-log CSV/XLSX exports, location/type/date filters, thumbnails, and barcode output. |
| `app/modules/export_daily_summary.php` | Compatibility loader for older direct includes. Primary loading comes from `app/module_manifest.php`, which lists focused daily-summary export modules directly. |
| `app/modules/export_daily_summary_csv.php` | Daily operations summary CSV export handler and flat section rows. |
| `app/modules/export_daily_summary_xlsx_rows.php` | Daily operations XLSX row normalization, usage-by-reason text, barcode values, scan codes, and image paths. |
| `app/modules/export_daily_summary_xlsx_sheet.php` | Daily operations XLSX worksheet XML, headers, column widths, row heights, and image drawing hook. |
| `app/modules/export_daily_summary_xlsx_payload.php` | Daily operations XLSX ZIP package builder with thumbnails and workbook metadata. |
| `app/modules/export_daily_summary_xlsx_handler.php` | Daily operations XLSX route handler, permission check, and Website Control thumbnail guard. |
| `app/modules/export_storages.php` | Storage CSV/XLSX exports, storage item rows, values, thumbnails, and barcode output. |
| `app/modules/export_workflows.php` | User, handover, purchase, and supplier CSV exports. |
| `app/modules/suppliers.php` | Compatibility loader for older direct includes. Primary loading comes from `app/module_manifest.php`, which lists focused supplier modules directly. |
| `app/modules/supplier_queries.php` | Supplier filters, list summaries, detail lookups, purchase history, and active-name duplicate checks. |
| `app/modules/supplier_forms.php` | Supplier form payload hydration and supplier create/edit validation. |
| `app/modules/supplier_pages.php` | Supplier index, create, show, and edit page render handlers. |
| `app/modules/supplier_actions.php` | Supplier create, update, archive, and recover submit handlers. |
| `app/modules/scan_payload.php` | Scan Center item response payloads, package presets, balances, and refreshed manual-add item payloads. |
| `app/modules/scan_pages.php` | Scan Center page rendering, manual stock-add page, and scan/manual restock access guards. |
| `app/modules/scan_lookup.php` | Barcode/SKU/name/reference lookup for items, assets, and workflow references. |
| `app/modules/scan_manual_restock.php` | Manual stock-add and batch manual restock actions through immutable inventory movements. |
| `app/modules/reports.php` | Compatibility shim for older direct includes. Primary loading comes from `app/module_manifest.php`, which lists the focused report modules directly. |
| `app/modules/report_summary.php` | Compatibility loader for older direct includes. Primary loading comes from `app/module_manifest.php`, which lists focused report summary modules directly. |
| `app/modules/report_summary_filters.php` | Daily report date/storage/type/status filters and report display labels. |
| `app/modules/report_summary_data.php` | Daily operations summary cards, usage-by-item/reason, user breakdown, and movement timeline queries. |
| `app/modules/report_summary_cards.php` | Built-in report shortcut cards grouped by inventory, workflow, finance, and control areas. |
| `app/modules/report_summary_pages.php` | Reports page render handler and summary payload assembly. |
| `app/modules/report_presets.php` | Compatibility loader for older direct includes. Primary loading comes from `app/module_manifest.php`, which lists focused report preset modules directly. |
| `app/modules/report_preset_definitions.php` | Saved report preset type definitions, default filters, and view/export permission checks. |
| `app/modules/report_preset_urls.php` | Saved preset filter parsing and source/export URL generation. |
| `app/modules/report_preset_queries.php` | Permission-safe saved preset list query for the reports page. |
| `app/modules/report_preset_pages.php` | Dedicated saved report management page. The daily Reports page only links to it and never embeds its editor. |
| `app/modules/report_preset_actions.php` | Saved preset create, update, duplicate, and archive submit handlers. |
| `app/modules/notifications.php` | Compatibility shim for older direct includes. Primary loading comes from `app/module_manifest.php`, which lists the focused notification modules directly. |
| `app/modules/notifications_dispatch.php` | Notification creation, permission-based fan-out, and optional workflow email-copy dispatch. |
| `app/modules/notifications_queries.php` | Notification feed data, unread counts, list filters, type options, and entity labels. |
| `app/modules/notifications_reads.php` | Mark-all, mark-entity, and mark-entity-type read-state helpers. |
| `app/modules/notifications_pages.php` | Notifications index page and JSON feed handlers. |
| `app/modules/notifications_actions.php` | Notification read-all submit handler. |
| `app/modules/search.php` | Compatibility shim for older direct includes. Primary loading comes from `app/module_manifest.php`, which lists the focused search modules directly. |
| `app/modules/search_helpers.php` | Global search query normalization, SQL LIKE escaping, result payload formatting, text matching, and fallback URLs. |
| `app/modules/search_reference.php` | Scanned reference normalization, reference target lookup, exact reference redirects, and smart open routing for QR/barcode references. |
| `app/modules/search_pages.php` | Searchable page, settings, and documentation result builders. |
| `app/modules/search_results.php` | Global search aggregation across items, storages, movements, requests, handovers, purchases, suppliers, files, stocktakes, reorder, users, audit, and email logs. |
| `app/modules/search_handlers.php` | Global search JSON endpoint and exact workflow reference open handler. |
| `app/modules/handover_usage_reasons.php` | Usage reason options, normalization, labels, expected/actual summaries, and variance summaries. |
| `app/modules/handover_usage_breakdowns.php` | Handover expected/actual usage breakdown queries and line hydration helpers. |
| `app/modules/handover_usage_inputs.php` | Handover expected usage and actual usage split form input parsing. |
| `app/modules/handover_receipt_updates.php` | Handover active received quantity helper and receipt update validation builder. |
| `app/modules/handover_closeout_updates.php` | Handover returned-first closeout and owner approval update builders. |
| `app/modules/handover_usage_persistence.php` | Handover expected and actual usage breakdown persistence. |
| `app/modules/handover_queries.php` | Handover filters, detail queries, line queries, destination storage lists, and storage-transfer detection helpers. |
| `app/modules/handover_status.php` | Handover recovery, owner status override rules, closed-handover reversal, and receipt shortage inventory correction. |
| `app/modules/handover_permissions.php` | Handover approval, edit, cancel, receipt, and closeout permission guards. |
| `app/modules/workflow_system.php` | System workflow storages, storage owner lookup, and staff handover visibility scope. |
| `app/modules/workflow_inputs.php` | Shared workflow date normalization, storage/item picker payloads, storage metadata, and line parsing. |
| `app/modules/workflow_identity.php` | Workflow reference number generation and absolute workflow URL helpers. |
| `app/modules/workflow_stock_impact.php` | Request/handover stock impact calculation, neutral-impact checks, and audited void safety rules. |
| `app/modules/workflow_core.php` | Compatibility shim for older direct includes. New shared workflow logic belongs in the focused workflow modules above. |
| `app/modules/workflow_filters.php` | Shared SQL filter builders for purchases, files, stocktakes, suppliers, audit logs, and email logs. |
| `app/modules/handover_inventory.php` | Handover stock reservation, staff-use finalization, and storage-transfer buffer/source/destination movement logic. |
| `app/modules/handover_request_decisions.php` | Handover request approval/rejection handlers and notifications. |
| `app/modules/handover_cancellations.php` | Handover cancellation and audited void handlers. |
| `app/modules/handover_decisions.php` | Handover recovery and owner status override handlers. |
| `app/modules/signoff.php` | Loader-only module for signoff files. Do not put business logic here. |
| `app/modules/signoff_documents.php` | Workflow document labels and workflow document asset registration. |
| `app/modules/signoff_data.php` | Loader-only compatibility module for signoff data preparation. Do not put business logic here. |
| `app/modules/signoff_text.php` | PDF-safe text escaping and wrapping helpers used by signoff renderers. |
| `app/modules/signoff_meta.php` | Signoff header metadata and storage-transfer detection helpers. |
| `app/modules/signoff_rows.php` | Request/handover line normalization into renderer-ready signoff rows. |
| `app/modules/signoff_quantities.php` | Grouped quantity totals, quantity formatting, single-unit detection, and quantity sums. |
| `app/modules/signoff_usage_totals.php` | Expected/actual usage reason totals, variance summaries, and usage reconciliation rows. |
| `app/modules/signoff_reconciliation.php` | Bottom reconciliation table rows and accounting/transfer difference totals. |
| `app/modules/signoff_totals.php` | Final signoff totals payload used by PDF/XLSX renderers. |
| `app/modules/signoff_assets.php` | Loader-only compatibility module for generated signoff asset helpers. Do not put business logic here. |
| `app/modules/signoff_images.php` | Item thumbnails, official logo assets, and image processing for signoff files. |
| `app/modules/signoff_barcodes.php` | Code 128 and Code 39 barcode generation for PDF/XLSX signoff files. |
| `app/modules/signoff_qr.php` | QR matrix, PDF QR rendering, and PNG QR generation for workflow references. |
| `app/modules/signoff_xlsx.php` | Loader-only compatibility module for XLSX signoff generation. Do not put business logic here. |
| `app/modules/signoff_xlsx_cells.php` | XLSX escaping, column names, text/number/formula cells, and workbook styles. |
| `app/modules/signoff_xlsx_drawing.php` | XLSX image assets, drawing XML, drawing relationships, content types, and image placement checks. |
| `app/modules/signoff_xlsx_sheet.php` | XLSX worksheet layout, signoff rows, formulas, signatures, and reconciliation table XML. |
| `app/modules/signoff_xlsx_payload.php` | XLSX ZIP workbook payload builder, logo/QR/item image insertion, and workbook metadata. |
| `app/modules/signoff_pdf.php` | Loader-only compatibility module for PDF signoff generation. Do not put business logic here. |
| `app/modules/signoff_pdf_primitives.php` | Low-level PDF text, rectangle, line, and document builder helpers. |
| `app/modules/signoff_pdf_payload.php` | PDF signoff payload renderer, including item rows, reconciliation, signatures, logo, QR, and barcodes. |
| `app/modules/signoff_revision.php` | Signoff data and settings revision timestamp detection used to decide when generated files must be rebuilt. |
| `app/modules/signoff_persistence.php` | Public signoff persistence helpers, including `ensure_workflow_signoff_pdf()` and proof upload document registration. |
| `app/modules/requests.php` | Compatibility shim for older direct includes. Primary loading comes from `app/module_manifest.php`, which lists the focused request modules directly. |
| `app/modules/request_support.php` | Compatibility loader for older direct includes. Primary loading comes from the focused request helper modules below. |
| `app/modules/request_filters.php` | Request destination-storage options, visibility scope, filter parsing, and SQL where-clause construction. |
| `app/modules/request_lookup.php` | Request detail lookup with visibility scope enforcement. |
| `app/modules/request_queries.php` | Request line queries and request summary rows. |
| `app/modules/request_guards.php` | Request approval, receipt, cancel, recovery, and draft-submission guard rules. |
| `app/modules/request_inventory.php` | Request transit issue, receipt update parsing, and receipt-confirmation stock movements. |
| `app/modules/request_pages.php` | Request index/create/show page handlers. |
| `app/modules/request_create.php` | Request create and draft submit handlers. |
| `app/modules/request_decisions.php` | Request approval and rejection handlers. |
| `app/modules/request_receipts.php` | Request receipt report and receipt confirmation handlers. |
| `app/modules/request_status.php` | Request cancellation, recovery, and void handlers. |
| `app/modules/request_exports.php` | Request CSV export handler. |
| `app/modules/handovers.php` | Compatibility shim for older direct includes. Primary loading comes from `app/module_manifest.php`, which lists the focused handover modules directly. |
| `app/modules/handover_pages.php` | Handover index/create/show page handlers. |
| `app/modules/handover_create.php` | Handover create submit handler for staff-use and storage-transfer handovers. |
| `app/modules/handover_line_edits.php` | Requested handover line-edit submit handler before approval. |
| `app/modules/handover_receipts.php` | Handover receipt confirmation and shortage confirmation handlers. |
| `app/modules/handover_closeout.php` | Returned-first closeout submit and owner final approval handlers. |
| `app/modules/ocr_parser.php` | Purchase OCR text cleanup, Arabic/English parsing helpers, parsed result normalization, confidence flags, and catalog matching. |
| `app/modules/ocr.php` | OCR extraction, purchase OCR preview handler, browser OCR payload handling, optional OpenAI fallback orchestration, and OCR logs. |
| `app/modules/purchase_documents.php` | Purchase document type labels, purchase document queries, protected document download/delete handlers, and purchase document persistence/asset registration. |
| `app/modules/purchase_persistence.php` | Compatibility shim for older direct includes. Primary loading comes from `app/module_manifest.php`, which lists the focused purchase persistence modules directly. |
| `app/modules/purchase_lookup.php` | Purchase detail lookup with supplier, storage, requester, approver, receiver, and completion context. |
| `app/modules/purchase_line_inputs.php` | Purchase line normalization for manual draft forms and OCR/import review forms. |
| `app/modules/purchase_supplier_persistence.php` | Supplier lookup/create behavior used while saving purchase drafts. |
| `app/modules/purchase_drafts.php` | Purchase draft create/update, line persistence, document attachment, and submit-for-approval behavior. |
| `app/modules/purchase_item_creation.php` | Catalog item creation from approved purchase lines. |
| `app/modules/purchase_lifecycle.php` | Compatibility shim for older direct includes. Primary loading comes from `app/module_manifest.php`, which loads the focused purchase lifecycle modules through this shim. |
| `app/modules/purchase_decision_rules.php` | Purchase approval and final-receipt guard rules, including self-approval blocking. |
| `app/modules/purchase_approval_actions.php` | Purchase approval and rejection submit handlers. |
| `app/modules/purchase_receiving_actions.php` | Purchase received-quantity reporting and receipt-review transition. |
| `app/modules/purchase_completion_actions.php` | Purchase final receipt confirmation, restock movement posting, and weighted-average cost update. |
| `app/modules/purchase_cancellation_actions.php` | Purchase cancellation handler for draft, pending approval, and approved purchases before stock posting. |
| `app/modules/purchases.php` | Purchase create/edit/show/submit route handlers and purchase history helpers for item/storage detail pages. |
| `app/modules/files.php` | Compatibility shim for older direct includes. Primary loading comes from `app/module_manifest.php`, which lists the focused file modules directly. |
| `app/modules/file_library.php` | Protected file library pages, workflow document access/download/view handlers, and file CSV export. |
| `app/modules/file_uploads.php` | Compatibility shim for older direct includes. Primary loading comes from `app/module_manifest.php`, which lists the focused upload modules directly. |
| `app/modules/upload_inputs.php` | Generic PHP upload array normalization helpers for single, multi, and indexed uploads. |
| `app/modules/purchase_file_uploads.php` | Purchase document MIME validation, protected purchase document storage, paths, and cleanup hooks. |
| `app/modules/workflow_file_uploads.php` | Workflow proof image validation, generated PDF/XLSX signoff storage, workflow document paths, and cleanup hooks. |
| `app/modules/item_image_uploads.php` | Item image validation, upload storage, duplication, and deletion. |
| `app/modules/asset_file_uploads.php` | Asset image storage/duplication/deletion and protected asset document validation/storage. |
| `app/modules/file_asset_meta.php` | File library permissions, groups/status labels, file paths, previews, context labels, and size/mime helpers. |
| `app/modules/file_asset_registry.php` | File asset registration for item images, asset files, purchase documents, workflow signoffs, and deleted-file markers. |
| `app/modules/file_media_settings.php` | Item/asset image URL helpers and export media setting checks for thumbnails/barcodes. |
| `app/modules/stocktakes.php` | Compatibility shim for older direct includes. Primary loading comes from `app/module_manifest.php`, which lists the focused stocktake modules directly. |
| `app/modules/stocktake_support.php` | Stocktake status options, filters, summary queries, stocktake lookup, and count-line lookup helpers. |
| `app/modules/stocktake_pages.php` | Stocktake index, create, and detail page handlers. |
| `app/modules/stocktake_actions.php` | Stocktake create/count/approve/cancel actions, variance movement posting, notifications, and audit hooks. |
| `app/modules/stocktake_exports.php` | Stocktake CSV export handler and export row formatting. |
| `app/modules/suppliers.php` | Supplier CRUD, required Saudi business fields, custom supplier type, search, archive/recover, exports, and purchase linkage. |
| `app/modules/reorder.php` | Compatibility shim for older direct includes. Primary loading comes from `app/module_manifest.php`, which lists the focused reorder modules directly. |
| `app/modules/reorder_support.php` | Reorder filters and low-stock suggestion query helpers. |
| `app/modules/reorder_pages.php` | Reorder center page handler. |
| `app/modules/reorder_actions.php` | Purchase draft creation from reorder suggestions. |
| `app/modules/reorder_exports.php` | Reorder CSV export handler. |
| `app/modules/audit.php` | Compatibility shim for older direct includes. Primary loading comes from `app/module_manifest.php`, which lists the focused audit and email-log modules directly. |
| `app/modules/audit_activity.php` | Audit activity persistence, audit filters, and audit row queries. |
| `app/modules/audit_pages.php` | Audit log page handler. |
| `app/modules/audit_exports.php` | Audit CSV export handler. |
| `app/modules/email_log_support.php` | Email delivery log filters, queries, status labels, status counts, type options, and linked-entity URLs. |
| `app/modules/email_log_pages.php` | Email delivery log page handler. |
| `app/modules/email_log_exports.php` | Email delivery log CSV export handler. |
| `app/modules/labels.php` | Compatibility shim for older direct includes. Primary loading comes from `app/module_manifest.php`, which lists the focused label modules directly. |
| `app/modules/label_support.php` | Label filters, item/storage label queries, barcode/SKU scan codes, thumbnail URLs, and printable row payloads. |
| `app/modules/label_pages.php` | Label page handler. |
| `app/modules/documentation_guides.php` | Documentation landing cards and department-specific guidance content. |
| `app/modules/documentation_content.php` | In-app documentation page sections and workflow explanations. |
| `app/modules/documentation.php` | Documentation page handler, screenshot lookup, and visual-helper payloads. |
| `app/modules/asset_support.php` | Compatibility loader for older direct includes. Primary loading comes from this loader plus the focused asset helper modules below. |
| `app/modules/asset_filters.php` | Asset list filter parsing and SQL where-clause construction. |
| `app/modules/asset_queries.php` | Asset list/detail queries, counts, visibility checks, and not-found handling. |
| `app/modules/asset_forms.php` | Asset create/edit form payload assembly. |
| `app/modules/asset_identity.php` | Asset number prefixes, generated numbers, scan codes, and daily sequence helpers. |
| `app/modules/asset_financials.php` | Asset book value SQL, straight-line depreciation, warranty status, and financial summary helpers. |
| `app/modules/asset_uploads.php` | Asset image upload input detection. |
| `app/modules/asset_selects.php` | Active users, suppliers, and purchases for asset form select inputs. |
| `app/modules/asset_events.php` | Asset event timeline, maintenance records, pending custody actions, and asset file lookups. |
| `app/modules/asset_category_support.php` | Compatibility loader for older direct includes. Primary loading comes from this loader plus the focused asset-category helper modules below. |
| `app/modules/asset_category_permissions.php` | Asset category permission checks. |
| `app/modules/asset_category_filters.php` | Asset category filter parsing and category-code normalization. |
| `app/modules/asset_category_queries.php` | Asset category list, select-list, lookup, and parent-filter query helpers. |
| `app/modules/asset_category_tree.php` | Asset category tree building, path labels, display labels, and descendant lookup. |
| `app/modules/asset_category_guards.php` | Asset category not-found guard, sort-order helper, and parent-cycle prevention. |
| `app/modules/asset_category_payloads.php` | Asset category create/edit payload validation and normalization. |
| `app/modules/asset_categories.php` | Asset category index/create/edit/archive/recover/reorder handlers. |
| `app/modules/assets.php` | Fixed asset index/create/show/edit pages, asset create/edit persistence, archive/recover entry points, and form payload handling. |
| `app/modules/asset_lifecycle.php` | Compatibility loader for older direct includes. Primary loading comes from this loader plus the focused asset lifecycle modules below. |
| `app/modules/asset_status_actions.php` | Asset archive/recover and owner status override handlers. |
| `app/modules/asset_custody_actions.php` | Asset assignment, receipt confirmation, return request, and return confirmation handlers. |
| `app/modules/asset_maintenance_actions.php` | Asset maintenance ticket creation and completion handlers. |
| `app/modules/asset_document_actions.php` | Asset protected document upload handler. |
| `app/modules/asset_exports.php` | Asset CSV/XLSX export rows, thumbnail/barcode image helpers, and asset export handlers. |
| `app/modules/asset_signoff.php` | Asset custody signoff PDF/XLSX payload generation and asset signoff download handlers. |
| `app/support/permissions.php` | Permission catalog, role defaults, position defaults, and permission input sanitizing. Loaded during bootstrap through `app/helpers.php`. |
| `app/support/http.php` | Request path, URL, asset URL, security headers, download headers, redirects, flash/old input, CSRF, JSON responses, and error page helpers. Loaded during bootstrap through `app/helpers.php`. |
| `app/support/branding.php` | Brand mark/logo helpers, upload/storage directories, UI theme options, export thumbnail sizing, and signoff template/image sizing. Loaded during bootstrap through `app/helpers.php`. |
| `app/support/settings.php` | Website Control setting schema, stored setting accessors, OpenAI OCR setting helpers, grouped settings for forms, and absolute URL helper. Loaded during bootstrap through `app/helpers.php` after `app_config()` is available. |
| `app/support/presentation.php` | Formatting, UI icon SVGs, active-route helpers, initials, truncation, stock value, and Code39 barcode rendering. Loaded during bootstrap through `app/helpers.php`. |
| `app/maintenance/MaintenanceBoot.php` | Boot trait for upload-directory setup and schema sync entrypoint used by `app/Maintenance.php`. |
| `app/maintenance/MaintenanceSchemaHelpers.php` | Schema/bootstrap helper trait for setting writes, table/column checks, indexes, and foreign-key checks used by `app/Maintenance.php`. |
| `app/maintenance/MaintenanceSchemaState.php` | Schema version and current-state inspection trait used by `app/Maintenance.php` to decide whether bootstrapping can be skipped safely. |
| `app/maintenance/MaintenancePlatformSchemas.php` | Platform table setup for permissions, app settings, report presets, login attempts, password reset tokens, and email delivery logs. |
| `app/maintenance/MaintenanceInventorySchemas.php` | Storage, item barcode/image/location, item package preset, storage owner fallback, and item storage balance schema setup used by `app/Maintenance.php`. Keep inventory bootstrap changes here and stock behavior in `app/modules/inventory_stock.php`. |
| `app/maintenance/MaintenanceFileWorkflowSchemas.php` | File-library and workflow-document table setup used by `app/Maintenance.php`. Keep proof/signoff document schema changes here instead of bloating `syncSchema()`. |
| `app/maintenance/MaintenanceNotificationSchemas.php` | Notification table setup and legacy actor-column/index repair used by `app/Maintenance.php`. Keep notification schema changes here instead of bloating `syncSchema()`. |
| `app/maintenance/MaintenanceBackfills.php` | One-time/repair backfill trait for missing storage balances and file asset registration. |
| `app/maintenance/MaintenancePermissionSeeds.php` | Permission seed trait for owner/admin/staff defaults and module-specific permission grants. |

## 4. Stock Rules

Stock correctness is the highest priority.

Source of truth:

- `item_storage_balances` is the live stock source by item and storage.
- Inventory movements are the immutable history.
- Item total quantity is synchronized from storage balances.

Rules:

- Usage subtracts from a storage balance.
- Restock adds to a storage balance.
- Transfer moves quantity between locations.
- Handovers reserve/issue stock, then final usage/return posts only after owner approval.
- Purchases add stock only after final receipt confirmation.
- Stocktakes create adjustment movements after approval.
- Assets do not affect inventory stock.

Critical handover stock split:

- Staff handovers are temporary-use workflows. They can create usage and return movements after owner approval.
- Storage-owner handovers are stock relocation workflows. They never use usage reasons and they close by moving confirmed receipt from the handover buffer into the destination storage.
- The handover buffer is an accountability location. It proves stock left the source but has not yet been finalized as used, returned, or received by the destination.

## 5. Major Workflow Cycles

### Items And Storages

Items can have SKU, barcode, image, unit, reorder level, cost, package presets, and storage assignments. Storages hold item balances. An item can exist in many storages with different quantities.

Zero quantity does not remove an item from a storage. It stays visible so teams know it needs refill.

### Movement Log

Every stock change is recorded as movement history. Movement rows should not be deleted just because an item or workflow is archived. The log exists to explain stock later.

### Requests

Requests cover user/admin item requests. The normal cycle is create, approve/reject, receive, confirm mismatch if needed, complete. Self-approval is blocked where it matters. Cancel/recover/void flows keep audit history.

### Handovers

Handovers have two target modes:

- Staff / temporary use: items are issued to a person or team, then returned quantity and usage reasons are reported.
- Storage transfer: items move from one storage to another storage owner, then the destination owner confirms what actually arrived.

Staff-use cycle:

1. Admin/storage owner creates the handover and optional expected usage plan.
2. Receiver reports the exact quantity received.
3. If every received quantity matches what was issued, the handover becomes Delivered immediately and the receiver can start usage reporting.
4. If any quantity differs, the handover enters Receipt Review. The source issuer corrects or confirms the reported quantities before it becomes Delivered.
5. Receiver enters returned quantity first.
6. System calculates used quantity as `received - returned`, then the receiver optionally splits used quantity by reason.
7. The source issuer performs the final editable review, can correct returned quantity and usage reasons, and approves the closeout.
8. Usage and return movements post only at issuer approval, then PDF/XLSX signoff is regenerated.

Storage-transfer cycle:

1. Source storage owner creates a handover with target `Transfer to Storage Owner`.
2. Stock moves from source storage into the handover buffer.
3. Destination storage owner confirms received quantities.
4. If receipt is exact, stock moves from buffer to destination storage and the handover closes.
5. If receipt is short, received stock waits in the buffer until the source owner approves the shortage; then received stock moves to destination and missing stock returns to source.

Receiver cancellation after delivery is intentionally restricted. The receiver reports issues; the storage owner decides final stock action.

### Purchases And Suppliers

Purchases handle supplier quotes, receipts, price lists, OCR review, approval, receiving, protected files, and final stock posting. OCR only fills drafts. It never changes stock directly.

### Assets

Assets are durable company property: laptops, radios, tools, equipment. They are separate from inventory items. Assets track individual records, custody, category/subcategory, condition, barcode, serial, purchase cost, depreciation, warranty, files, maintenance, and exports.

### Reports

Reports summarize daily activity, usage by item, usage by reason, users, transfers, stock movement, purchases, and assets. Filters must match export scope. Saved filter management is intentionally separated at `/reports/presets`; do not put the management editor back into the operational `/reports` page.

## 6. Permissions And Roles

Owner has full access. Admin access is controlled by permission flags and position defaults. Staff sees only the workflows assigned to them where applicable. CFO/accountant-style positions can be granted finance, report, purchase, file, and asset visibility without giving broad stock-control power.

Status override must stay limited to owner/super admin because it can change workflow state outside the normal cycle.

Default admin purchase boundaries are intentional:

- A default admin without `purchases.files` cannot delete protected purchase documents.
- A default admin without `purchases.cancel` cannot cancel a purchase draft.
- Owner accounts can perform both actions, and the regression suite verifies the denied and allowed paths separately.

## 7. Local Setup

1. Copy `.env.example` to `.env`.
2. Fill database credentials.
3. Start the app:

```bash
php -S 127.0.0.1:8080 router.php
```

4. Open `http://127.0.0.1:8080`.

If the local DB is unavailable, do not assume stock tests are clean. Run stock invariant checks on live after a backup.

## 8. Test Commands

Static checks:

```bash
php -l index.php
find app views scripts tests -name '*.php' -print0 | xargs -0 -n1 php -l
node --check assets/app.js
find assets/js -name '*.js' -print0 | xargs -0 -n1 node --check
php tests/module_boundaries.php
php tests/frontend_assets.php
php tests/backup_archive.php
git diff --check
```

Regression checks:

```bash
php tests/full_regression.php
php tests/stock_invariants.php
```

Responsive browser matrix:

```bash
NODE_PATH=/Users/ahmaddalao/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules \
BASE_URL=https://inventory.ahmaddalao.com \
INVENTORY_EMAIL=owner@example.com \
INVENTORY_PASSWORD='password' \
CAPTURE_SCREENSHOTS=1 \
OUTPUT_DIR=storage/test-screenshots/responsive \
node tests/responsive_ui_smoke.js
```

This checks 21 authenticated pages at 390px, 430px, 768px, 1024px, 1440px, and 1920px. It fails on page-level horizontal overflow, clipped visible controls, broken same-origin resources, browser console errors, and an unusable mobile sidebar. Dense tables are allowed to scroll inside their own wrapper; the page itself is not.

Module boundary check:

```bash
php tests/module_boundaries.php
```

This confirms the old aggregate files are still compatibility loaders only, every file listed in `app/module_manifest.php` exists, and shim modules have not started defining business logic again.

Live workflow testing should use temporary prefixed records and must be done only after backup.

## 9. Deployment Flow

Production path:

```bash
/home/u867436826/domains/ahmaddalao.com/public_html/inventory
```

Safe deployment flow:

1. Confirm local branch and remote.
2. Run local static checks.
3. Commit and push to GitHub.
4. SSH to production.
5. Create a live backup.
6. Deploy changed files.
7. Run live PHP lint.
8. Run live stock invariants.
9. Smoke-check key routes.

Never deploy a stock workflow refactor without a backup and a live invariant check.

## 10. Backup And Rollback

Before production deploy, backup the current app folder and database. Keep the backup path in the deployment notes.

Rollback is simple if the database did not change:

1. Restore the backed-up app files.
2. Re-run PHP lint.
3. Smoke-check login/dashboard.
4. Run stock invariants.

If the database changed, restore both files and SQL from the same backup set. Do not mix old files with a newer schema unless the migration plan explicitly says it is safe.

## 11. How To Add A Feature Safely

1. Put backend logic in the right `app/modules/*.php` domain file.
2. Keep public URLs stable unless a route change is intentional.
3. Add UI under `views/`.
4. If stock moves, use the existing movement/balance functions.
5. Add audit/notification hooks when a user should know about the change.
6. Add export support if the data is operationally important.
7. Run lint, JS check, regression, and stock invariants.

Do not bypass `item_storage_balances`. That is how inventory systems become fiction.

Database bootstrapping belongs in `app/Maintenance.php`, but boot setup, reusable schema helpers, schema-current checks, platform schemas, file/workflow document schemas, backfills, and seed routines belong in `app/maintenance/`. Workflow behavior does not belong in maintenance files.

## 12. Current Refactor Boundary

This pass split backend PHP route/workflow code into domain modules and shrank the old monoliths into compatibility loaders. `app/controllers.php`, `app/workflows.php`, `app/company_assets.php`, and `app/report_presets.php` now only load `app/modules.php`.

Latest split checkpoint:

- Asset lifecycle support is split under `app/modules/asset_*_actions.php`, with `app/modules/asset_lifecycle.php` kept as the compatibility loader.
- Asset list filters live in `app/modules/asset_filters.php`.
- Asset query/detail helpers live in `app/modules/asset_queries.php`.
- Asset form payloads live in `app/modules/asset_forms.php`.
- Asset identity and scan-code helpers live in `app/modules/asset_identity.php`.
- Asset depreciation, warranty, and book-value helpers live in `app/modules/asset_financials.php`.
- Asset upload, select-list, event, maintenance, and file lookup helpers live in `app/modules/asset_uploads.php`, `app/modules/asset_selects.php`, `app/modules/asset_events.php`, and `app/modules/asset_maintenance_actions.php`.
- Asset category support is split under `app/modules/asset_category_*.php`, with `app/modules/asset_category_support.php` kept as the compatibility loader.
- Request filters/scope live in `app/modules/request_filters.php`.
- Request detail lookup lives in `app/modules/request_lookup.php`.
- Request line and list queries live in `app/modules/request_queries.php`.
- Request lifecycle guard rules live in `app/modules/request_guards.php`.
- Request transit and receipt stock movement helpers live in `app/modules/request_inventory.php`.
- Documentation landing cards and department guides live in `app/modules/documentation_guides.php`.
- Long documentation page sections live in `app/modules/documentation_content.php`.
- OCR parsing helpers live in `app/modules/ocr_parser.php`.
- Scanned reference lookup lives in `app/modules/search_reference.php`.
- Permissions/settings/report preset/auth/email log schema setup lives in `app/maintenance/MaintenancePlatformSchemas.php`.
- Storage/item inventory schema setup lives in `app/maintenance/MaintenanceInventorySchemas.php`.
- File-library and workflow-document schema setup lives in `app/maintenance/MaintenanceFileWorkflowSchemas.php`.
- Notification schema setup lives in `app/maintenance/MaintenanceNotificationSchemas.php`.
- `app/modules/assets.php`, `app/modules/documentation.php`, `app/modules/ocr.php`, and the focused `search_*` modules now stay focused on route/page orchestration, engine orchestration, or global result composition.

`app/module_manifest.php` now lists the focused module files by domain group, and `app/modules.php` only loads that manifest. The aggregate module files `app/modules/requests.php`, `app/modules/handovers.php`, `app/modules/files.php`, `app/modules/file_uploads.php`, `app/modules/exports.php`, `app/modules/reports.php`, and `app/modules/search.php` remain only for older direct includes. They are not the place for new business logic.

The report module was split because daily summaries, shortcut cards, saved preset definitions, saved preset CRUD, and daily-summary export rendering are different responsibilities. Use `report_summary_filters.php` for filter parsing and labels, `report_summary_data.php` for daily summary queries, `report_summary_cards.php` for built-in shortcuts, `report_summary_pages.php` for page rendering, `report_preset_definitions.php` for preset types and permissions, `report_preset_urls.php` for filter and URL serialization, `report_preset_queries.php` for listing presets, and `report_preset_actions.php` for create/update/duplicate/archive actions. Daily summary exports are also split: CSV rows in `export_daily_summary_csv.php`, XLSX data rows in `export_daily_summary_xlsx_rows.php`, worksheet XML in `export_daily_summary_xlsx_sheet.php`, XLSX package generation in `export_daily_summary_xlsx_payload.php`, and the route handler in `export_daily_summary_xlsx_handler.php`.

Shared workflow helpers were split out of `app/modules/workflow_core.php`. Put form parsing and picker payload changes in `workflow_inputs.php`, stock impact checks in `workflow_stock_impact.php`, system storage helpers in `workflow_system.php`, reference/URL helpers in `workflow_identity.php`, and cross-module SQL filter changes in `workflow_filters.php`.

Purchase document storage and protected file actions were moved out of the purchase lifecycle and workflow core. Put purchase document query/download/delete/persistence changes in `app/modules/purchase_documents.php`; purchase draft persistence in `purchase_drafts.php`; line parsing in `purchase_line_inputs.php`; supplier save/link behavior in `purchase_supplier_persistence.php`; catalog item creation from lines in `purchase_item_creation.php`; guard-rule changes in `purchase_decision_rules.php`; approval/rejection handlers in `purchase_approval_actions.php`; receiving reports in `purchase_receiving_actions.php`; final stock posting and weighted-average cost changes in `purchase_completion_actions.php`; and cancellation changes in `purchase_cancellation_actions.php`.

Support helpers were separated into:

- `app/support/permissions.php`
- `app/support/http.php`
- `app/support/branding.php`
- `app/support/settings.php`
- `app/support/presentation.php`

Domain logic now lives under `app/modules/`. Keep it that way.

The signoff module was split further because PDF/XLSX generation had become too large for safe edits. `app/modules/signoff.php` loads the signoff domain and `app/modules/signoff_data.php` loads the focused data-preparation files. Public persistence helpers live in `app/modules/signoff_persistence.php`.

Frontend assets now load through `app/modules/frontend_assets.php`. `views/layout.php` reads that registry instead of hard-coding assets. CSS is fully organized into the strict cascade documented above; the retired compatibility stylesheet and temporary theme passes are gone. JavaScript is split into core, shared UI, and domain modules, while `assets/app.js` remains a small native-module bootstrap. New frontend behavior must follow the registry contract rather than growing the entry file again.

## 13. Responsive Layout Contract

Responsive behavior is intentionally split into six viewport classes in `assets/css/mobile.css`:

| Viewport | Width | Expected behavior |
|---|---:|---|
| Compact phone | Up to 430px | Tight shell/panel spacing, single-column workflow controls, full-width primary actions where needed. |
| Phone | 431px to 760px | Single-column field workflow with slightly wider gutters. |
| Tablet | 761px to 1024px | Two-column form/filter grids where readable; action rows wrap instead of clipping. |
| Compact desktop | 1025px to 1360px | Desktop information density with controlled gutters and drawer navigation where required. |
| Standard desktop | 1361px to 1599px | Full desktop navigation and balanced content spacing. |
| Wide desktop | 1600px and above | Content is centered and capped at 1840px so cards and forms do not stretch into nonsense. |

Rules that must remain true:

- `body`, `.app-shell`, `.main-panel`, and `.content` must not create page-level horizontal overflow.
- Operational data tables remain real tables at every width. Below 1361px their own wrapper provides touch-friendly horizontal scrolling.
- Sidebar navigation scrolls inside the viewport when its links exceed the available height.
- Search/combobox panels and action menus overlay content; opening one must not increase the surrounding row height.
- Closed notification and account popovers must use `display: none`; otherwise they remain rendered off-canvas and silently widen phone pages.
- New responsive rules belong in `assets/css/mobile.css`, which remains last in the stylesheet registry.
- Any new route with authenticated UI must be added to `tests/responsive_ui_smoke.js`.

## 14. Future KONA URL And Domain Cutover

Moving from `https://inventory.ahmaddalao.com` to a KONA-owned URL is an infrastructure/configuration cutover, not a rewrite. Public route paths and database records should stay unchanged.

### Required changes

1. Create the KONA domain or subdomain in Hostinger and point its DNS to the production host.
2. Configure its document root to the deployed application folder, or deploy a verified copy of the same Git commit to the new document root.
3. Issue and verify HTTPS before login credentials or protected documents are used.
4. Change `APP_URL` in production `.env` to the final HTTPS URL. `config/app.php` derives the application base path from this value.
5. If the folder path changes, update Hostinger cron commands for `scripts/backup.php` and `scripts/daily_report.php`.
6. Preserve the production database and the complete `storage/`/upload tree together. Protected purchase, handover, asset, and file-library documents depend on those files and permissions.
7. In Website Control, update sender/reply-to email, SMTP identity, logo/branding, and any text that still names the old inventory domain.
8. Review hard-coded fallback identities in `app/modules/email_settings.php`, `app/modules/email_smtp.php`, `app/modules/workflow_identity.php`, and `app/support/settings_schema.php`. Normal requests use the configured host, but fallbacks should match the KONA domain before the old host is retired.
9. Update `.env.example`, `README.md`, production-readiness commands, monitoring links, saved bookmarks, and external documentation after the cutover succeeds.
10. Run login, password reset, global search/reference open, protected document download, PDF/XLSX generation, exports, OCR, email test, responsive matrix, full regression, and stock invariants on the new URL.
11. Keep the old URL available during validation. Add a permanent redirect only after the new domain passes all tests and current sessions have been considered.

### Do not change

- Do not rename routes such as `/items`, `/handovers`, `/requests`, `/purchases`, `/company-assets`, or `/reports`.
- Do not change SKU, barcode, handover, request, purchase, or asset references. QR codes store stable references rather than domain-bound URLs.
- Do not create a second live database unless an intentional data migration is planned. Two writable databases would split stock history.
- Do not copy only application code and forget protected uploads or generated signoff files.
- Do not change stock movement logic, permissions, or workflow statuses as part of the domain move.

### Cutover verification commands

```bash
php -l index.php
php tests/frontend_assets.php
php tests/module_boundaries.php
php tests/full_regression.php --base-url=https://NEW-KONA-DOMAIN --allow-live --prefix=ZZDOMAINYYYYMMDD
php tests/stock_invariants.php
```

Create a complete backup before cutover and retain the old app path until the new host has passed the same checks.

## 15. Verification Notes

Verified production responsive checkpoint on July 21, 2026:

- Application checkpoint: `44de744` (`Harden responsive layouts across viewport classes`), deployed from `main`.
- Backup SQL: `storage/backups/inventory-backup-20260721-141450.sql`.
- Backup manifest: `storage/backups/inventory-backup-20260721-141450.manifest.json`.
- Backup files archive: `storage/backups/inventory-backup-20260721-141450.files.zip`.
- Backup files archive size: approximately 1.6 GB; SQL size: approximately 1.7 MB.
- PHP lint passed across 348 PHP files. Every JavaScript module syntax check, `git diff --check`, module boundaries, and frontend asset tests passed.
- Responsive smoke passed 21 authenticated routes across six viewport sizes: 126 page/viewport combinations with zero page-level horizontal overflow.
- Live regression prefixes `ZZRESPBASE20260721` and `ZZRESPAFTER20260721` passed and cleaned their temporary records.
- Stock invariants passed before the live regression and again after cleanup.
- The live regression covered auth, users, permissions, settings, dashboard, inventory, scan/manual stock, requests, staff handovers, storage-transfer handovers, exact staff receipt flow, issuer final review, purchases, protected documents, suppliers, OCR, reorder, assets, stocktakes, labels, files, reports, exports, audit, and email logs.
- Responsive screenshots are stored under `storage/test-screenshots/responsive-after-20260721/` for compact phone, large phone, tablet portrait, tablet landscape, desktop, and wide desktop.

Use this verification sequence for the next deployment:

```bash
php -l index.php
find app views scripts tests -name '*.php' -print0 | xargs -0 -n1 php -l
node --check assets/app.js
find assets/js -name '*.js' -print0 | xargs -0 -n1 node --check
php tests/module_boundaries.php
php tests/frontend_assets.php
php tests/backup_archive.php
NODE_PATH=/path/to/node_modules BASE_URL=https://inventory.ahmaddalao.com INVENTORY_EMAIL=owner@example.com INVENTORY_PASSWORD='password' node tests/responsive_ui_smoke.js
php tests/full_regression.php --base-url=https://inventory.ahmaddalao.com --allow-live --prefix=ZZMODULARYYYYMMDD
php tests/stock_invariants.php
```

If the full regression runs on live, run `php scripts/backup.php` first and use a unique prefix. The regression creates users, storages, items, handovers, purchases, assets, stocktakes, and exports, then cleans them up.

## 16. Next Technical Improvements

Recommended order:

1. Continue mobile field-work polish for handover, scan/manual stock add, assets, and movement tables without changing the intentional mobile table-scroll rule.
2. Keep extending export/filter parity tests whenever a filter or export changes.
3. Harden OCR review for Arabic scanned PDFs with confidence warnings and optional OpenAI fallback.
4. Exercise saved report presets with real daily operations, finance, storage-owner, purchase, and asset workflows.
5. Keep CSS and JavaScript modules focused; split a domain file again when it starts owning unrelated behavior rather than waiting for another monolith.
