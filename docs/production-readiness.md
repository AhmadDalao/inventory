# Production Readiness

This app is now usable as an MVP, but production safety depends on backups, audit visibility, and scheduled reporting being run consistently.

## Backups

Run this from Hostinger cron:

```bash
php /home/u867436826/domains/ahmaddalao.com/public_html/inventory/scripts/backup.php
```

The backup script creates:

- `storage/backups/inventory-backup-YYYYMMDD-HHMMSS.sql`
- `storage/backups/inventory-backup-YYYYMMDD-HHMMSS.manifest.json`
- `storage/backups/inventory-backup-YYYYMMDD-HHMMSS.files.zip` when file backups are enabled and `ZipArchive` is installed

The SQL dump is the database restore source. The zip file contains uploaded item images, purchase documents, and protected file-library assets.

Retention and file inclusion are controlled from `Website Control > Operations Safety`.

## Daily Reports

Run this from Hostinger cron:

```bash
php /home/u867436826/domains/ahmaddalao.com/public_html/inventory/scripts/daily_report.php
```

The report script creates:

- `storage/reports/daily-inventory-report-YYYY-MM-DD.json`
- `storage/reports/daily-inventory-report-YYYY-MM-DD.csv`

The report includes active items, active storages, stock units, inventory value, low-stock lines, open requests, open handovers, open purchases, open stocktakes, daily usage, daily restock quantity, top usage items, and pending purchases.

The `Reports` page in the app provides preset CSV shortcuts for the most common exports: item catalog, storage value, usage, transfers, requests, handovers, purchases, suppliers, files, stocktakes, audit, email logs, and users. It does not create new data; it reuses the existing permission-checked exports.

## Barcode Scanner Workflow

`Scan Center` supports three low-cost scanning paths:

- Hardware barcode scanners that type into the scan field and press Enter.
- Manual barcode/SKU/item-name lookup.
- Browser camera scanning when the device supports `BarcodeDetector`.

Quick usage/restock actions from Scan Center post through the existing item movement endpoint, so the same stock validation, permissions, AJAX response, and movement logs are used. If camera scanning is not supported by the browser, use a hardware scanner or manual entry.

## OCR Review

Purchase OCR now returns confidence scores and review flags for supplier data, purchase metadata, and line items. Low-confidence rows are warnings, not approvals. The user must still review supplier fields, quantities, unit prices, generated SKUs, and mandatory supplier data before creating or submitting a purchase.

## Documentation Screenshots

The in-app Documentation page shows a visual guide for every feature section and tracks the section currently being read.

To replace a generated visual guide with a real screenshot, add an image here:

```text
assets/docs/screenshots/{section-slug}.png
assets/docs/screenshots/{section-slug}.webp
assets/docs/screenshots/{section-slug}.jpg
```

Example: `assets/docs/screenshots/purchases.png` appears inside the Purchases documentation section automatically. The section slug is the same as the anchor after `/documentation#doc-`.

## Login Audit

Every login attempt is written to `login_attempts`.

Successful logins and logouts are also written to the main audit log as `auth.login` and `auth.logout`.

Failed login attempts are throttled after repeated failures from the same email or IP in a short window. The browser message stays generic so attackers do not learn whether an account exists.

## Email And Password Recovery

The system supports cost-free email delivery for password reset/setup emails and optional workflow email copies.

Email settings are controlled from `Website Control > Email Delivery`. SMTP is the recommended production transport because it uses a real mailbox and is more reliable than raw PHP `mail()`.

- Password reset links expire after 60 minutes.
- Reset tokens are stored as hashes, not plain text.
- Admins with user edit access can send reset/setup links from the Admins page.
- Workflow emails are optional. In-app notifications remain the source of truth.
- Log-only mode records emails without sending them, which is useful for testing delivery safely.
- SMTP requires host, port, encryption, username, password, sender name, and sender email.
- PHP `mail()` remains available as a fallback, but delivery depends on Hostinger server mail configuration.
- Email delivery attempts are reviewable from `Email Logs` and exportable by users with email log permissions.

## Restore Test

At least once per week, download the latest SQL backup and verify it can be restored into a temporary local or hosting database. This project does not require a staging website. A backup you never test is just a lucky charm with a filename.

Minimum restore check:

```bash
php -l index.php
php tests/stock_invariants.php
```

For live changes, keep the current cycle: create a full live folder backup, deploy, run live PHP lint, run full regression with `--allow-live`, then run `php tests/stock_invariants.php`.

## Mobile Screenshot Checks

Use the mobile screenshot harness when changing layout-heavy screens:

```bash
NODE_PATH=/Users/ahmaddalao/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules \
BASE_URL=https://inventory.ahmaddalao.com \
INVENTORY_EMAIL=owner@example.com \
INVENTORY_PASSWORD='password' \
node tests/mobile_ui_screenshots.js
```

Screenshots are saved to `storage/test-screenshots/mobile`. The script captures dashboard, scan center, reports, items, storages, requests, handovers, purchases, reorder, files, and notifications with a phone-sized viewport.
