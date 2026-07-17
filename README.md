# Inventory KONA

Internal inventory, handover, purchasing, asset, and reporting system for KONA operations.

This is no longer a tiny CRUD app. The current application tracks consumable stock by storage, movement history, requests, handovers, supplier purchases, fixed assets, files, reports, notifications, roles, permissions, and audit logs.

## Developer Handover

Start here:

- [Developer handover](docs/developer-handover.md)
- Production URL: `https://inventory.ahmaddalao.com`
- Live app path: `/home/u867436826/domains/ahmaddalao.com/public_html/inventory`
- Main branch: `main`
- GitHub remote: `https://github.com/AhmadDalao/inventory.git`

## Local Setup

1. Copy `.env.example` to `.env`.
2. Fill in MySQL credentials.
3. Run with PHP:

```bash
php -S 127.0.0.1:8080 router.php
```

4. Open `http://127.0.0.1:8080`.

## Checks

```bash
php -l index.php
find app views scripts tests -name '*.php' -print0 | xargs -0 -n1 php -l
node --check assets/app.js
php tests/module_boundaries.php
php tests/frontend_assets.php
php tests/full_regression.php
php tests/stock_invariants.php
```

If local MySQL is unavailable, run stock invariants on the live server after a backup.

## Current Architecture

Routes still live in `index.php`. Domain functions are organized under `app/modules/`, grouped in `app/module_manifest.php`, and loaded by `app/modules.php`. Bootstrap-safe shared support code, such as permission catalogs, role defaults, request/security helpers, branding/upload options, settings schema/accessors, and presentation helpers, lives under `app/support/`. Maintenance boot setup, schema helpers, schema-current checks, platform schemas, inventory schemas, file/workflow document schemas, notification schemas, one-time backfills, and permission seed routines live under `app/maintenance/`.

The old aggregate files remain as compatibility loaders:

- `app/controllers.php`
- `app/workflows.php`
- `app/company_assets.php`
- `app/report_presets.php`

Do not add new logic to those compatibility files. Put new backend logic in the correct module.

The compatibility loaders exist only for older direct includes. New code should use the focused files already listed in `app/module_manifest.php`, such as `item_filters.php`, `item_lookup.php`, `item_history.php`, `item_uploads.php`, `item_storage_assignments.php`, `item_form_payloads.php`, `item_pages.php`, `item_actions.php`, `item_movements.php`, `request_create.php`, `handover_closeout.php`, `workflow_inputs.php`, `workflow_stock_impact.php`, `workflow_filters.php`, `purchase_documents.php`, `purchase_drafts.php`, `purchase_line_inputs.php`, `purchase_item_creation.php`, `report_summary_filters.php`, `report_preset_actions.php`, `dashboard_filters.php`, `dashboard_metrics.php`, `export_items.php`, `export_daily_summary_csv.php`, `export_daily_summary_xlsx_rows.php`, `export_daily_summary_xlsx_payload.php`, `option_items.php`, `option_workflows.php`, `search_helpers.php`, `search_pages.php`, `search_results.php`, `search_handlers.php`, `upload_inputs.php`, `purchase_file_uploads.php`, `workflow_file_uploads.php`, `item_image_uploads.php`, or `asset_file_uploads.php`.

The signoff domain follows the same rule: keep `app/modules/signoff.php`, `app/modules/signoff_data.php`, and `app/modules/signoff_assets.php` as loaders only. Put signoff metadata in `signoff_meta.php`, row normalization in `signoff_rows.php`, quantity helpers in `signoff_quantities.php`, usage/variance totals in `signoff_usage_totals.php`, reconciliation-table data in `signoff_reconciliation.php`, final totals in `signoff_totals.php`, PDF text helpers in `signoff_text.php`, PDF rendering in `signoff_pdf.php`, Excel rendering in `signoff_xlsx.php`, image/logo work in `signoff_images.php`, barcode work in `signoff_barcodes.php`, QR work in `signoff_qr.php`, workflow document rows in `signoff_documents.php`, and PDF/XLSX/proof persistence in `signoff_persistence.php`.

Run `php tests/module_boundaries.php` after backend refactors. It fails if old aggregate files grow logic again or if `app/module_manifest.php` points at missing modules.

When changing database bootstrapping, keep `app/Maintenance.php` as the orchestrator and put boot setup, reusable helpers, schema-current checks, backfills, and seed routines in `app/maintenance/`. Do not bury workflow behavior there.

Frontend assets are loaded through `app/modules/frontend_assets.php`. Keep the base desktop/global layer in `assets/app.css`, asset list/form/category styling in `assets/css/assets.css`, the mobile/sidebar/table/dropdown override layer in `assets/css/mobile.css`, and shared behavior in `assets/app.js` until the JS is split safely.
