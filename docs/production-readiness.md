# Production Readiness

This app is now usable as an MVP, but production safety depends on backups, audit visibility, and scheduled reporting being run consistently.

## Realtime Mobile And Website Stock

Stock-sensitive pages and active mobile clients use a monotonic inventory event cursor. The acting client receives authoritative balances immediately after a mutation; other visible clients update within five seconds. Hidden/background clients stop polling. Reports remain authoritative even when a screen has not completed its latest poll.

Run `scripts/daily_report.php` regularly; it also removes synchronization events older than the 90-day retention window. Clients with an expired cursor safely reload bootstrap data.

See `realtime-data-flow.md` and `security.md` before changing sync, authentication, device, or stock code.

## Backups

Run the backup from the deployment environment with a private owner-only recovery key outside the repository and an absolute off-server destination outside the application root:

```bash
php scripts/backup.php \
  --password-file=/secure/path/recovery-key \
  --output-dir=/off-server/inventory-backups
```

Each recovery set contains:

- `inventory-backup-YYYYMMDD-HHMMSS.database.zip`, an AES-256 encrypted consistent SQL snapshot
- `inventory-backup-YYYYMMDD-HHMMSS.files.zip`, an AES-256 encrypted application and durable-files archive
- `inventory-backup-YYYYMMDD-HHMMSS.manifest.json`, containing commit/runtime/schema/table/file evidence but no secrets
- `inventory-backup-YYYYMMDD-HHMMSS.sha256`, covering all three artifacts

The files archive includes source/configuration plus every durable location: `uploads/`, `assets/brand/uploads/`, `storage/assets`, `storage/purchases`, `storage/workflows`, `storage/files`, `storage/audit`, and `storage/reports`. Plaintext SQL is removed before success is reported.

The script verifies every encrypted entry and active `file_assets` path before applying retention. Any warning or missing path fails the run. Website Control may set retention limits, but it cannot weaken required durable-path coverage.

If the deployed tree has no `.git`, add `--source-commit=40_CHAR_SHA` using the independently verified deployment commit. Do not guess from an old marker; the backup command rejects missing, malformed, and Git-mismatched commit identities.

## Daily Reports

Run this from the deployment scheduler:

```bash
php /path/to/inventory/scripts/daily_report.php
```

The report script creates:

- `storage/reports/daily-inventory-report-YYYY-MM-DD.json`
- `storage/reports/daily-inventory-report-YYYY-MM-DD.csv`

The report includes active items, active storages, stock units, inventory value, low-stock lines, open requests, open handovers, open purchases, open stocktakes, daily usage, daily restock quantity, top usage items, and pending purchases.

The `Reports` page in the app provides preset CSV/Excel shortcuts for the most common exports: item catalog, storage value, usage, transfers, requests, handovers, purchases, suppliers, files, stocktakes, audit, email logs, and users. It does not create new data; it reuses the existing permission-checked exports.

Storage item exports can be generated from the Storages page with the searchable storage picker or from a specific storage detail page. Pick one storage when accounting needs only that location, or leave all storages selected for the grouped export.

Handover signoff files now use returned-first closeout. Staff enters what came back, the app calculates used quantity, optional usage reasons explain the used amount, and the owner/admin final review posts stock. The signoff PDF/XLSX keeps item rows simple and moves expected/actual/variance/returned/difference reconciliation to a bottom table.

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

Restore-verify every pre-deployment recovery set in an empty temporary web root and disposable local MariaDB database. A backup you never test is just a lucky charm with a filename.

Required restore command:

```bash
php scripts/restore_verify.php \
  --manifest=/off-server/inventory-backups/inventory-backup-YYYYMMDD-HHMMSS.manifest.json \
  --password-file=/secure/path/recovery-key \
  --restore-root=/isolated/empty/web-root \
  --database=inventory_restore_YYYYMMDD \
  --db-user=local_restore_user
```

The verifier checks checksums, encrypted extraction, SQL import, table counts, schema, active file paths, protected-download denial, application boot, and stock invariants. Any warning or mismatch is a hard stop. Before production deployment, create and verify a second recovery set, then run live PHP lint, the approved regression command, and `php tests/stock_invariants.php` after deployment.

## Mobile Screenshot Checks

Use the mobile screenshot harness when changing layout-heavy screens:

```bash
NODE_PATH=/path/to/node_modules \
BASE_URL=https://inventory.ahmaddalao.com \
INVENTORY_EMAIL=owner@example.com \
INVENTORY_PASSWORD='password' \
node tests/mobile_ui_screenshots.js
```

Screenshots are saved to `storage/test-screenshots/mobile`. The script captures dashboard, scan center, reports, items, storages, requests, handovers, purchases, reorder, files, and notifications with a phone-sized viewport.
