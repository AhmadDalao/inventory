# Inventory KONA Developer Handover

Updated: 2026-07-16

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
- `app/modules.php` requires all domain modules.
- `app/helpers.php` still loads bootstrap-safe helpers, while permission catalogs, role defaults, request/security helpers, branding/upload options, settings schema/accessors, and presentation helpers now live under `app/support/`.
- Old aggregate files now only load `app/modules.php` for compatibility.
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
| `app/modules/options.php` | Shared option lists and labels for statuses, units, supplier types, movement types, roles, and workflow dropdowns. |
| `app/modules/auth.php` | Setup, login, logout, forgot-password, reset-password, token creation, login attempt limits, and password reset mail dispatch. |
| `app/modules/users.php` | Admin users, roles, positions, permission saving, assigned owner controls, reset links, and user exports. |
| `app/modules/inventory.php` | Item catalog, item form handling, item/location filtering, and item movement entry points. |
| `app/modules/storages.php` | Storage filters, ownership helpers, storage summaries, storage CRUD handlers, and storage detail queries. |
| `app/modules/inventory_stock.php` | Stock movement posting, item storage balance writes, item quantity snapshot sync, and storage inventory clone helpers. |
| `app/modules/item_packages.php` | Item package preset labels, package conversion presets, and package preset save/delete handlers. |
| `app/modules/movements.php` | Movement-log filters, location-scoped movement display helpers, and the movement log page handler. |
| `app/modules/dashboard.php` | Dashboard filters, role-specific dashboard data, cards, queue snapshots, latest updates, and chart payloads. |
| `app/modules/exports.php` | CSV/XLSX exports for items, storages, movements, users, handovers, purchases, suppliers, daily summaries, thumbnails, and barcode output. |
| `app/modules/scan.php` | Scan Center, barcode/SKU lookup, batch scan, package conversion, manual stock add, and scan payloads. |
| `app/modules/reports.php` | Reports page, daily operations summary, usage by reason, storage/user activity, and saved report presets. |
| `app/modules/notifications.php` | Notification creation, popup/feed data, unread counts, read-all actions, sounds, and workflow notification helpers. |
| `app/modules/search.php` | Global search, scanned reference routing, smart reference open, and module-aware result URLs. |
| `app/modules/handover_usage.php` | Usage reason labels, expected/actual usage summaries, variance summaries, and usage breakdown query helpers. |
| `app/modules/handover_queries.php` | Handover filters, detail queries, line queries, destination storage lists, and storage-transfer detection helpers. |
| `app/modules/handover_status.php` | Handover recovery, owner status override rules, closed-handover reversal, and receipt shortage inventory correction. |
| `app/modules/handover_permissions.php` | Handover approval, edit, cancel, receipt, and closeout permission guards. |
| `app/modules/workflow_core.php` | Shared workflow scope, visibility, reference, stock impact, recovery, audit, and workflow document helpers. |
| `app/modules/handover_inventory.php` | Handover stock reservation, staff-use finalization, and storage-transfer buffer/source/destination movement logic. |
| `app/modules/signoff.php` | Signoff loader plus final PDF/XLSX persistence helpers. Keep public `ensure_workflow_signoff_pdf()` here. |
| `app/modules/signoff_documents.php` | Workflow document labels and workflow document asset registration. |
| `app/modules/signoff_data.php` | Signoff metadata, item rows, totals, usage summaries, variance, and reconciliation table data. |
| `app/modules/signoff_assets.php` | Item thumbnails, official logo assets, barcode generation, QR generation, and image processing for signoff files. |
| `app/modules/signoff_xlsx.php` | XLSX XML generation, workbook images/drawings, styles, formulas, and Excel signoff payloads. |
| `app/modules/signoff_pdf.php` | PDF primitives, PDF signoff rendering, and signoff revision timestamp detection. |
| `app/modules/requests.php` | Request lifecycle, approvals, rejection, receipt mismatch, completion, cancellation, recovery, and request line handling. |
| `app/modules/handovers.php` | Handover lifecycle, staff handovers, storage-owner transfers, expected usage, returned-first closeout, owner final review, and status actions. |
| `app/modules/ocr.php` | OCR health, purchase OCR preview/import, browser OCR payload parsing, optional OpenAI fallback, confidence warnings, and OCR logs. |
| `app/modules/purchases.php` | Purchase lifecycle, supplier purchase forms, document requirements, approval, receiving, final confirmation, and weighted average cost. |
| `app/modules/files.php` | Protected file library, workflow document access, file asset registration, item/asset/purchase image registration, and file exports. |
| `app/modules/stocktakes.php` | Stocktake create/count/submit/approve/cancel flows, variance movements, stocktake exports, and audit trail hooks. |
| `app/modules/suppliers.php` | Supplier CRUD, required Saudi business fields, custom supplier type, search, archive/recover, exports, and purchase linkage. |
| `app/modules/reorder.php` | Reorder center, low-stock detection, suggested restock quantities, and purchase draft creation from reorder rows. |
| `app/modules/audit.php` | Audit log filters/exports and email log filters/exports. |
| `app/modules/labels.php` | Label page data, label search, selectable label printing, barcode/SKU scan codes, and label rows. |
| `app/modules/documentation.php` | In-app documentation page, documentation search data, and employee guidance content. |
| `app/modules/assets.php` | Fixed assets, categories/subcategories, custody, maintenance, depreciation/book value, warranty status, files, and asset exports. |
| `app/modules/asset_signoff.php` | Asset custody signoff PDF/XLSX payload generation and asset signoff download handlers. |
| `app/support/permissions.php` | Permission catalog, role defaults, position defaults, and permission input sanitizing. Loaded during bootstrap through `app/helpers.php`. |
| `app/support/http.php` | Request path, URL, asset URL, security headers, download headers, redirects, flash/old input, CSRF, JSON responses, and error page helpers. Loaded during bootstrap through `app/helpers.php`. |
| `app/support/branding.php` | Brand mark/logo helpers, upload/storage directories, UI theme options, export thumbnail sizing, and signoff template/image sizing. Loaded during bootstrap through `app/helpers.php`. |
| `app/support/settings.php` | Website Control setting schema, stored setting accessors, OpenAI OCR setting helpers, grouped settings for forms, and absolute URL helper. Loaded during bootstrap through `app/helpers.php` after `app_config()` is available. |
| `app/support/presentation.php` | Formatting, UI icon SVGs, active-route helpers, initials, truncation, stock value, and Code39 barcode rendering. Loaded during bootstrap through `app/helpers.php`. |

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
git diff --check
```

Regression checks:

```bash
php tests/full_regression.php
php tests/stock_invariants.php
```

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

## 12. Current Refactor Boundary

This pass split backend PHP route/workflow code into domain modules and shrank the old monoliths into compatibility loaders. `app/controllers.php`, `app/workflows.php`, `app/company_assets.php`, and `app/report_presets.php` now only load `app/modules.php`.

Support helpers were separated into:

- `app/support/permissions.php`
- `app/support/http.php`
- `app/support/branding.php`
- `app/support/settings.php`
- `app/support/presentation.php`

Domain logic now lives under `app/modules/`. Keep it that way.

The signoff module was split further because PDF/XLSX generation had become too large for safe edits. `app/modules/signoff.php` now loads the focused signoff files and keeps only the public persistence helpers.

This pass did not split `assets/app.css` or `assets/app.js`.

Reason: PHP stock workflow was the high-risk area. Frontend splitting and helper/class cleanup should be a later refactor after production proves stable.

## 13. Verification Notes

Latest modular-refactor verification should include:

```bash
php -l index.php
find app views scripts tests -name '*.php' -print0 | xargs -0 -n1 php -l
node --check assets/app.js
php tests/full_regression.php --base-url=https://inventory.ahmaddalao.com --allow-live --prefix=ZZMODULARYYYYMMDD
php tests/stock_invariants.php
```

If the full regression runs on live, run `php scripts/backup.php` first and use a unique prefix. The regression creates users, storages, items, handovers, purchases, assets, stocktakes, and exports, then cleans them up.

## 14. Next Technical Improvements

Recommended order:

1. Add automated export/filter parity tests across all modules.
2. Continue mobile field-work polish for handover, scan/manual stock add, assets, and movement tables.
3. Harden OCR review for Arabic scanned PDFs with confidence warnings and optional OpenAI fallback.
4. Split `assets/app.css` and `assets/app.js` into frontend domain files.
5. Consider moving plain functions into classes only after the module split has been stable in production.
