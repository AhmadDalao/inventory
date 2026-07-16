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
php tests/full_regression.php
php tests/stock_invariants.php
```

If local MySQL is unavailable, run stock invariants on the live server after a backup.

## Current Architecture

Routes still live in `index.php`. Domain functions are loaded through the explicit module graph in `app/modules.php` and organized under `app/modules/`. Bootstrap-safe shared support code, such as permission catalogs, role defaults, request/security helpers, branding/upload options, settings schema/accessors, and presentation helpers, lives under `app/support/`.

The old aggregate files remain as compatibility loaders:

- `app/controllers.php`
- `app/workflows.php`
- `app/company_assets.php`
- `app/report_presets.php`

Do not add new logic to those compatibility files. Put new backend logic in the correct module.

The compatibility loaders exist only for older direct includes. New code should use the focused files already listed in `app/modules.php`, such as `request_create.php`, `handover_closeout.php`, `workflow_filters.php`, `report_summary.php`, `report_presets.php`, `export_items.php`, or `file_uploads.php`.
