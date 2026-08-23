# Inventory KONA

Internal inventory, handover, purchasing, asset, and reporting system for KONA operations.

This is no longer a tiny CRUD app. The current application tracks consumable stock by storage, movement history, requests, handovers, supplier purchases, fixed assets, wristband API audit evidence, files, reports, notifications, roles, permissions, and audit logs.

## Developer Handover

Start here:

- [Developer handover](docs/developer-handover.md)
- [Team routing, storage authority, and Owner resolution](docs/team-routing-and-owner-resolution.md)
- [Mobile application guide](mobile/README.md)
- [Mobile API reference](docs/mobile-api.md)
- [Mobile OpenAPI contract](docs/openapi/mobile-api-v1.yaml)
- [Realtime data-flow guide](docs/realtime-data-flow.md)
- [KONA wristband API audit guide](docs/wristband-api.md)
- [KONA wristband OpenAPI contract](docs/openapi/wristband-api-v1.yaml)
- [Security and incident guide](docs/security.md)
- [Mobile mockup review](docs/mobile/mockups/README.md)
- [Mobile v1.3 measured-inventory release evidence](docs/mobile/release-1.3.0.md)
- [System data-flow and use-case diagrams](docs/system-diagrams.md)
- [Developer handover Word report](output/doc/inventory-kona-developer-handover-2026-08-22.docx)
- [Complete system Word report](output/doc/inventory-kona-complete-system-report-2026-08-22.docx)
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
find assets/js -name '*.js' -print0 | xargs -0 -n1 node --check
node --check assets/app.js
php tests/module_boundaries.php
php tests/frontend_assets.php
php tests/mobile_api_contract.php
php tests/measured_inventory.php
php tests/wristband_api_contract.php
php tests/wristband_code_performance.php
php tests/wristband_workflow.php
NODE_PATH=/path/to/playwright/node_modules BASE_URL=https://inventory.ahmaddalao.com INVENTORY_EMAIL=owner@example.com INVENTORY_PASSWORD='password' node tests/responsive_ui_smoke.js
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

The compatibility loaders exist only for older direct includes. New code should use the focused files already listed in `app/module_manifest.php` or loaded by focused compatibility loaders, such as `core_feedback.php`, `core_selects.php`, `core_exports.php`, `core_report_filters.php`, `settings_payload.php`, `settings_logo.php`, `settings_pages.php`, `settings_actions.php`, `item_filters.php`, `item_lookup.php`, `item_history.php`, `item_uploads.php`, `item_storage_assignments.php`, `item_form_payloads.php`, `storage_filters.php`, `storage_ownership.php`, `team_access.php`, `storage_lookup.php`, `storage_inventory.php`, `storage_form_payloads.php`, `movement_filters.php`, `movement_scope.php`, `movement_pages.php`, `item_pages.php`, `item_actions.php`, `item_movements.php`, `request_filters.php`, `request_lookup.php`, `request_queries.php`, `request_guards.php`, `request_inventory.php`, `request_create.php`, `handover_closeout.php`, `workflow_inputs.php`, `workflow_stock_impact.php`, `workflow_filters.php`, `purchase_documents.php`, `purchase_drafts.php`, `purchase_line_inputs.php`, `purchase_item_creation.php`, `purchase_decision_rules.php`, `purchase_approval_actions.php`, `purchase_receiving_actions.php`, `purchase_completion_actions.php`, `purchase_cancellation_actions.php`, `report_summary_filters.php`, `report_preset_actions.php`, `dashboard_filters.php`, `dashboard_metrics.php`, `export_items.php`, `export_daily_summary_csv.php`, `export_daily_summary_xlsx_rows.php`, `export_daily_summary_xlsx_payload.php`, `option_items.php`, `option_workflows.php`, `search_helpers.php`, `search_pages.php`, `search_results.php`, `search_handlers.php`, `upload_inputs.php`, `purchase_file_uploads.php`, `workflow_file_uploads.php`, `item_image_uploads.php`, `asset_file_uploads.php`, `asset_filters.php`, `asset_queries.php`, `asset_forms.php`, `asset_identity.php`, `asset_financials.php`, `asset_uploads.php`, `asset_selects.php`, `asset_events.php`, `asset_status_actions.php`, `asset_custody_actions.php`, `asset_maintenance_actions.php`, `asset_document_actions.php`, `asset_category_permissions.php`, `asset_category_filters.php`, `asset_category_queries.php`, `asset_category_tree.php`, `asset_category_guards.php`, or `asset_category_payloads.php`.

The signoff domain follows the same rule: keep `app/modules/signoff.php`, `app/modules/signoff_data.php`, `app/modules/signoff_assets.php`, `app/modules/signoff_xlsx.php`, and `app/modules/signoff_pdf.php` as loaders only. Put signoff metadata in `signoff_meta.php`, row normalization in `signoff_rows.php`, quantity helpers in `signoff_quantities.php`, usage/variance totals in `signoff_usage_totals.php`, reconciliation-table data in `signoff_reconciliation.php`, final totals in `signoff_totals.php`, PDF text helpers in `signoff_text.php`, PDF primitives in `signoff_pdf_primitives.php`, PDF rendering in `signoff_pdf_payload.php`, signoff revision detection in `signoff_revision.php`, Excel rendering in `signoff_xlsx_cells.php`, `signoff_xlsx_drawing.php`, `signoff_xlsx_sheet.php`, and `signoff_xlsx_payload.php`, image/logo work in `signoff_images.php`, barcode work in `signoff_barcodes.php`, QR work in `signoff_qr.php`, workflow document rows in `signoff_documents.php`, and PDF/XLSX/proof persistence in `signoff_persistence.php`.

Run `php tests/module_boundaries.php` after backend refactors. It fails if old aggregate files grow logic again or if `app/module_manifest.php` points at missing modules.

When changing database bootstrapping, keep `app/Maintenance.php` as the orchestrator and put boot setup, reusable helpers, schema-current checks, backfills, and seed routines in `app/maintenance/`. Do not bury workflow behavior there.

Frontend assets are loaded through `app/modules/frontend_assets.php`. CSS is split into ordered foundation, shell, component, table, workflow, domain, theme, print, and mobile files. JavaScript uses native ES modules: `assets/app.js` is only the bootstrap entry, while reusable UI and domain behavior lives under `assets/js/`. See the developer handover before adding or moving frontend code.
