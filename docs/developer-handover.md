# Inventory KONA Developer Handover

Updated: 2026-07-17

## 1. What This System Is

Inventory KONA is an internal operations system for KONA. It tracks consumable inventory, storage balances, movement logs, staff requests, handovers, supplier purchases, fixed assets, files, reports, notifications, user permissions, and audit logs.

Production URL: `https://inventory.ahmaddalao.com`

Production path: `/home/u867436826/domains/ahmaddalao.com/public_html/inventory`

Repository: `https://github.com/AhmadDalao/inventory.git`

Main branch: `main`

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

Do not add new code to these compatibility loaders:

- `app/controllers.php`
- `app/workflows.php`
- `app/company_assets.php`
- `app/report_presets.php`

## 3. Module Map

| Module | Purpose |
|---|---|
| `app/modules/core.php` | Shared route helpers, CSV/XLSX response helpers, and low-level export payload builders. |
| `app/modules/settings.php` | Website Control page actions, site setting saves, logo upload, OCR settings display, and email test controls. |
| `app/modules/email.php` | PHP `mail()` delivery wrapper, email settings, reset email delivery, workflow email copies, and email log writes. |
| `app/modules/options.php` | Compatibility loader for option catalogs. New option logic belongs in focused `option_*` modules. |
| `app/modules/option_users.php` | User role, position, initials, and position-to-access helpers. |
| `app/modules/option_suppliers.php` | Supplier type options and labels, including custom `Other` display. |
| `app/modules/option_workflows.php` | Request, handover, purchase, and stocktake status labels/badge helpers. |
| `app/modules/option_movements.php` | Movement type options and movement permission filtering. |
| `app/modules/option_assets.php` | Asset status, condition, tone, and event/action labels. |
| `app/modules/option_items.php` | Item units, barcode requirement, manual restock setting, and scan-code helpers. |
| `app/modules/option_reports.php` | Report access helper based on export permissions. |
| `app/modules/auth.php` | Setup, login, logout, forgot-password, reset-password, token creation, login attempt limits, and password reset mail dispatch. |
| `app/modules/users.php` | Admin users, roles, positions, permission saving, assigned owner controls, reset links, and user exports. |
| `app/modules/item_support.php` | Item filters, lookups, storage-balance helpers, payload builders, upload normalization, and item/storage assignment helpers. |
| `app/modules/item_pages.php` | Item index/create/show/edit page render handlers. |
| `app/modules/items.php` | Item create/edit persistence handlers. |
| `app/modules/item_actions.php` | Item archive/recover and item-location removal handlers. |
| `app/modules/item_movements.php` | Item detail movement submit handler for usage, restock, adjustment, and transfer. |
| `app/modules/inventory.php` | Compatibility shim for older direct includes. New code should use `item_support.php` and `items.php`. |
| `app/modules/storage_support.php` | Storage filters, ownership helpers, storage summaries, storage detail queries, storage item rows, and copy-name helpers. |
| `app/modules/storage_pages.php` | Storage index, detail, create, and edit page render handlers. |
| `app/modules/storages.php` | Storage create, edit, archive, and recover persistence handlers. |
| `app/modules/inventory_stock.php` | Stock movement posting, item storage balance writes, item quantity snapshot sync, and storage inventory clone helpers. |
| `app/modules/item_packages.php` | Item package preset labels, package conversion presets, and package preset save/delete handlers. |
| `app/modules/movements.php` | Movement-log filters, location-scoped movement display helpers, and the movement log page handler. |
| `app/modules/dashboard.php` | Dashboard page handler and role-specific render payload assembly. |
| `app/modules/dashboard_filters.php` | Dashboard date/storage filter parsing, selected storage lookup, movement scope SQL, and filter labels. |
| `app/modules/dashboard_metrics.php` | Dashboard usage trend, storage value breakdown, workflow queues, purchase queue metrics, stocktake queue, and reorder pressure snapshot. |
| `app/modules/exports.php` | Compatibility shim for older direct includes. Primary loading comes from `app/module_manifest.php`, which lists the focused export modules directly. |
| `app/modules/export_items.php` | Item CSV/XLSX exports, optional thumbnails, barcode text/images, and filtered item export rows. |
| `app/modules/export_movements.php` | Movement-log CSV/XLSX exports, location/type/date filters, thumbnails, and barcode output. |
| `app/modules/export_daily_summary.php` | Daily operations summary CSV/XLSX exports, usage-by-reason, people, timeline, and summary image output. |
| `app/modules/export_storages.php` | Storage CSV/XLSX exports, storage item rows, values, thumbnails, and barcode output. |
| `app/modules/export_workflows.php` | User, handover, purchase, and supplier CSV exports. |
| `app/modules/scan.php` | Scan Center, barcode/SKU lookup, batch scan, package conversion, manual stock add, and scan payloads. |
| `app/modules/reports.php` | Compatibility shim for older direct includes. Primary loading comes from `app/module_manifest.php`, which lists the focused report modules directly. |
| `app/modules/report_summary.php` | Reports page, daily operations summary, usage by item/reason, storage/user activity, and report shortcut cards. |
| `app/modules/report_presets.php` | Saved report preset types, permissions, source/export URLs, create/update/duplicate/archive handlers, and filter-state persistence. |
| `app/modules/notifications.php` | Notification creation, popup/feed data, unread counts, read-all actions, sounds, and workflow notification helpers. |
| `app/modules/search_reference.php` | Scanned reference normalization, reference target lookup, exact reference redirects, and smart open routing for QR/barcode references. |
| `app/modules/search.php` | Global search page/result logic, accessible pages, documentation/settings search, and module-aware result URLs. |
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
| `app/modules/signoff_data.php` | Signoff metadata, item rows, totals, usage summaries, variance, and reconciliation table data. |
| `app/modules/signoff_assets.php` | Loader-only compatibility module for generated signoff asset helpers. Do not put business logic here. |
| `app/modules/signoff_images.php` | Item thumbnails, official logo assets, and image processing for signoff files. |
| `app/modules/signoff_barcodes.php` | Code 128 and Code 39 barcode generation for PDF/XLSX signoff files. |
| `app/modules/signoff_qr.php` | QR matrix, PDF QR rendering, and PNG QR generation for workflow references. |
| `app/modules/signoff_xlsx.php` | XLSX XML generation, workbook images/drawings, styles, formulas, and Excel signoff payloads. |
| `app/modules/signoff_pdf.php` | PDF primitives, PDF signoff rendering, and signoff revision timestamp detection. |
| `app/modules/signoff_persistence.php` | Public signoff persistence helpers, including `ensure_workflow_signoff_pdf()` and proof upload document registration. |
| `app/modules/requests.php` | Compatibility shim for older direct includes. Primary loading comes from `app/module_manifest.php`, which lists the focused request modules directly. |
| `app/modules/request_support.php` | Request filters, visibility scope, request lines, inventory issue/receipt helpers, recovery rules, and summary queries. |
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
| `app/modules/purchases.php` | Purchase lifecycle, supplier purchase forms, document requirements, approval, receiving, final confirmation, and weighted average cost. |
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
| `app/modules/stocktakes.php` | Stocktake create/count/submit/approve/cancel flows, variance movements, stocktake exports, and audit trail hooks. |
| `app/modules/suppliers.php` | Supplier CRUD, required Saudi business fields, custom supplier type, search, archive/recover, exports, and purchase linkage. |
| `app/modules/reorder.php` | Reorder center, low-stock detection, suggested restock quantities, and purchase draft creation from reorder rows. |
| `app/modules/audit.php` | Audit log filters/exports and email log filters/exports. |
| `app/modules/labels.php` | Label page data, label search, selectable label printing, barcode/SKU scan codes, and label rows. |
| `app/modules/documentation_guides.php` | Documentation landing cards and department-specific guidance content. |
| `app/modules/documentation_content.php` | In-app documentation page sections and workflow explanations. |
| `app/modules/documentation.php` | Documentation page handler, screenshot lookup, and visual-helper payloads. |
| `app/modules/asset_support.php` | Asset filters, query helpers, financial/depreciation helpers, select lists, event queries, maintenance queries, and asset file lookups. |
| `app/modules/asset_category_support.php` | Asset category filters, tree/path helpers, descendant lookup, cycle checks, and save payload normalization. |
| `app/modules/asset_categories.php` | Asset category index/create/edit/archive/recover/reorder handlers. |
| `app/modules/assets.php` | Fixed asset index/create/show/edit pages, asset create/edit persistence, archive/recover entry points, and form payload handling. |
| `app/modules/asset_lifecycle.php` | Asset custody assignment, receipt/return, maintenance, status override, and asset document upload handlers. |
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
2. Receiver confirms exact quantity received.
3. Receiver enters returned quantity first.
4. System calculates used quantity.
5. Receiver optionally splits used quantity by reason.
6. Owner reviews, corrects if needed, and approves.
7. Stock movements post on owner approval.
8. PDF/XLSX signoff is regenerated for the final record.

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

Reports summarize daily activity, usage by item, usage by reason, users, transfers, stock movement, purchases, assets, and saved report presets. Filters must match export scope.

## 6. Permissions And Roles

Owner has full access. Admin access is controlled by permission flags and position defaults. Staff sees only the workflows assigned to them where applicable. CFO/accountant-style positions can be granted finance, report, purchase, file, and asset visibility without giving broad stock-control power.

Status override must stay limited to owner/super admin because it can change workflow state outside the normal cycle.

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
php tests/module_boundaries.php
php tests/frontend_assets.php
git diff --check
```

Regression checks:

```bash
php tests/full_regression.php
php tests/stock_invariants.php
```

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

- Asset lifecycle actions live in `app/modules/asset_lifecycle.php`.
- Asset category helper logic lives in `app/modules/asset_category_support.php`.
- Documentation landing cards and department guides live in `app/modules/documentation_guides.php`.
- Long documentation page sections live in `app/modules/documentation_content.php`.
- OCR parsing helpers live in `app/modules/ocr_parser.php`.
- Scanned reference lookup lives in `app/modules/search_reference.php`.
- Permissions/settings/report preset/auth/email log schema setup lives in `app/maintenance/MaintenancePlatformSchemas.php`.
- Storage/item inventory schema setup lives in `app/maintenance/MaintenanceInventorySchemas.php`.
- File-library and workflow-document schema setup lives in `app/maintenance/MaintenanceFileWorkflowSchemas.php`.
- Notification schema setup lives in `app/maintenance/MaintenanceNotificationSchemas.php`.
- `app/modules/assets.php`, `app/modules/documentation.php`, `app/modules/ocr.php`, and `app/modules/search.php` now stay focused on route/page orchestration, engine orchestration, or global result composition.

`app/module_manifest.php` now lists the focused module files by domain group, and `app/modules.php` only loads that manifest. The aggregate module files `app/modules/requests.php`, `app/modules/handovers.php`, `app/modules/files.php`, `app/modules/exports.php`, and `app/modules/reports.php` remain only for older direct includes. They are not the place for new business logic.

The report module was split because daily operations summaries and saved preset CRUD are different responsibilities. Use `app/modules/report_summary.php` for report page and summary data changes. Use `app/modules/report_presets.php` for saved preset definitions, permissions, URLs, and create/update/archive actions.

Shared workflow helpers were split out of `app/modules/workflow_core.php`. Put form parsing and picker payload changes in `workflow_inputs.php`, stock impact checks in `workflow_stock_impact.php`, system storage helpers in `workflow_system.php`, reference/URL helpers in `workflow_identity.php`, and cross-module SQL filter changes in `workflow_filters.php`.

Purchase document storage and protected file actions were moved out of the purchase lifecycle and workflow core. Put purchase document query/download/delete/persistence changes in `app/modules/purchase_documents.php`; purchase draft persistence in `purchase_drafts.php`; line parsing in `purchase_line_inputs.php`; supplier save/link behavior in `purchase_supplier_persistence.php`; catalog item creation from lines in `purchase_item_creation.php`; and purchase approval/receiving changes in `app/modules/purchases.php`.

Support helpers were separated into:

- `app/support/permissions.php`
- `app/support/http.php`
- `app/support/branding.php`
- `app/support/settings.php`
- `app/support/presentation.php`

Domain logic now lives under `app/modules/`. Keep it that way.

The signoff module was split further because PDF/XLSX generation had become too large for safe edits. `app/modules/signoff.php` now loads the focused signoff files only; public persistence helpers live in `app/modules/signoff_persistence.php`.

Frontend assets now load through `app/modules/frontend_assets.php`. `views/layout.php` reads that registry instead of hard-coding one stylesheet and one script. Keep base desktop/global CSS in `assets/app.css`, asset list/form/category styling in `assets/css/assets.css`, mobile/sidebar/table/dropdown overrides in `assets/css/mobile.css`, and shared behavior in `assets/app.js` until the JavaScript can be split safely.

## 13. Verification Notes

Latest modular-refactor verification should include:

```bash
php -l index.php
find app views scripts tests -name '*.php' -print0 | xargs -0 -n1 php -l
node --check assets/app.js
php tests/module_boundaries.php
php tests/frontend_assets.php
php tests/full_regression.php --base-url=https://inventory.ahmaddalao.com --allow-live --prefix=ZZMODULARYYYYMMDD
php tests/stock_invariants.php
```

If the full regression runs on live, run `php scripts/backup.php` first and use a unique prefix. The regression creates users, storages, items, handovers, purchases, assets, stocktakes, and exports, then cleans them up.

## 14. Next Technical Improvements

Recommended order:

1. Add automated export/filter parity tests across all modules.
2. Continue mobile field-work polish for handover, scan/manual stock add, assets, and movement tables.
3. Harden OCR review for Arabic scanned PDFs with confidence warnings and optional OpenAI fallback.
4. Split `assets/app.js` into frontend domain files after the PHP and CSS split stay stable in production.
5. Consider moving plain functions into classes only after the module split has been stable in production.
