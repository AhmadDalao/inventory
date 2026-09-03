# Inventory KONA Current Architecture

Updated: 2026-09-02

This document describes the application at the Step 1-2 safety baseline. It is an as-built map, not the target modularization design.

## Baseline Inventory

| Area | Current count |
|---|---:|
| Routes in `index.php` | 264 |
| `/api/v1` routes | 31 |
| Route methods | 117 GET, 147 POST |
| Manifest groups | 13 |
| Eagerly loaded modules | 171 |
| Physical `app/modules/*.php` files | 277 |
| PHP views | 64 |
| JavaScript modules under `assets/js` | 34 |
| Registered CSS files | 20 |
| Dart files under `mobile/lib` | 44 |
| Dart files excluding generated build/cache output | 51 |
| Database tables | 58 |

The route, API, module, frontend, domain, and schema inventories are locked under `tests/fixtures/characterization/`.

## Request Bootstrap And Routing

Apache routes non-file requests through `.htaccess` to `index.php`; local development uses `router.php`, which serves existing files directly and forwards everything else to `index.php`.

`index.php` is both composition root and route table. It requires `app/bootstrap.php` and `app/modules.php`, registers all routes in source order, then dispatches the current request. `Router` stores routes in registration order and uses first-match dispatch. Dynamic `{name}` segments match one non-slash segment. Route order is therefore behavior, not formatting.

`app/bootstrap.php` performs this sequence on every request:

1. Load `app/helpers.php`, Composer autoload when present, `.env`, and `config/app.php`.
2. Set timezone, error policy, security headers, session name, and cookie attributes.
3. Load `Database`, `Auth`, `Installer`, `Maintenance`, `View`, and `Router`.
4. Run `Maintenance::boot()`.
5. Attempt persistent-cookie authentication through `Auth::restoreFromPersistentCookie()`.

`Maintenance::boot()` can create or upgrade schema and seed permissions before route dispatch. Its current schema marker is `2026-08-26-storage-usage-profiles-v1`. That coupling is convenient but makes bootstrap changes high risk.

## PHP Module Graph

`app/module_manifest.php` is the ordered dependency graph. `app/modules.php` flattens its 13 groups, rejects duplicates/missing files, and requires 171 modules in order. The groups are:

1. `core`
2. `inventory`
3. `exports`
4. `reports_and_search`
5. `handover_usage`
6. `workflow_core`
7. `requests`
8. `wristbands`
9. `handovers`
10. `purchases_and_ocr`
11. `files_and_operations`
12. `assets`
13. `mobile_api`

The modules expose procedural functions in the global namespace. Load order is a real dependency: later files call functions and constants created by earlier files. There is no service container or autoloaded domain layer.

`app/support/` contains bootstrap-safe catalogs and helpers, including permissions, defaults, settings schema/access, and presentation/security support. `app/maintenance/` contains schema checks, table/index/foreign-key creation, backfills, and permission seeds. Business workflow logic belongs in `app/modules/`, not maintenance.

The compatibility files `app/controllers.php`, `app/workflows.php`, `app/company_assets.php`, and `app/report_presets.php` only require `app/modules.php`. Older focused loader modules also remain because tests and tools may include them directly.

## Inventory And Stock Flow

The stock model has three deliberately different records:

- `item_storage_balances` is the authoritative current balance per item and storage.
- `inventory_movements` is append-only history for restock, usage, transfer, and adjustment.
- `items.current_quantity` is the synchronized sum of all storage balances for the item.

`apply_inventory_movement()` in `app/modules/inventory_stock.php` is the central write path. It validates type, quantity, actor, and source/destination requirements; starts a transaction when the caller does not already own one; locks the item and its balance rows with `FOR UPDATE`; rejects a negative source balance; persists per-storage balances; calls `sync_item_inventory_snapshot()`; appends the movement; records measurement/department metadata; and appends `inventory_change_events` for affected storages. A transfer changes two storage balances but has a zero total delta.

Web item actions, Scan Center, mobile mutations, requests, handover issue/receipt/closeout, purchase receiving, stocktakes, storage copy, and custody replacement ultimately depend on this model. Those callers add permission, workflow status, proof, expected-balance, and idempotency rules before posting.

Measured inventory stores canonical quantity in the item unit. Package presets multiply entered quantities into canonical units. Dimensions are count, volume, mass, length, area, or custom. Invalid known unit/dimension combinations, disabled presets, another item's preset, zero quantities, and missing required proof fail before commit.

## Workflow Dependencies

- Requests depend on requester/approver/storage visibility, immutable movement posting, receipt variance rules, proofs, sign-off documents, notifications, and audit events.
- Handovers depend on source ownership, recipient relationships, issue/receipt/closeout state, usage reconciliation, wristband evidence, custody-return review, proofs, and stock posting.
- Purchases depend on supplier records, self-approval restrictions, OCR review, destination storage, receiving variance, weighted cost updates, documents, and restock movements.
- Stocktakes lock current storage quantity, require approval for posting, and create adjustment movements rather than rewriting history.
- Company assets have their own status/custody/maintenance lifecycle and file/sign-off records; they do not use consumable stock balances.
- Notifications and `inventory_change_events` mirror business actions. They are not substitutes for the authoritative workflow or movement rows.

Permission checks are layered. Browser handlers use `Auth::requireLogin()`, `Auth::requireOwner()`, or `Auth::requirePermission()`, then domain guards apply ownership, manager, storage, requester, recipient, approver, and current-status restrictions. Mobile routes additionally require an enabled device session, minimum app version, mobile-access grants, effective capability, and assigned-storage scope.

## Views

`View::render()` loads PHP templates under `views/` inside `views/layout.php`. Handlers build arrays and views still call global helpers for URLs, formatting, settings, permission display, and some lookups. There are 64 PHP view files. The layout renders registered assets, navigation, topbar, notifications, flash messages, and the page template.

Views are server-rendered. Partial page updates return HTML fragments or JSON from existing handlers; there is no client-side router or component framework. This means route handlers, view variable names, DOM hooks, and JavaScript initializers are coupled contracts.

## JavaScript

`assets/app.js` is the sole registered browser entrypoint. It imports 32 initializer modules and registers them by unique name in `assets/js/core/registry.js`. The repository has 34 JavaScript modules under `assets/js` across `core`, `ui`, and `domains`.

Initializers accept a root node and must tolerate repeat execution after partial HTML replacement. The registry catches one initializer failure so unrelated modules continue. Key integration events are:

- `inventory:action-complete` after a successful user action.
- `inventory:content-replaced` after live HTML replacement, carrying the replaced root.
- `inventory:refresh` to request stock-sensitive refresh work.

Visible pages poll the monotonic inventory event endpoint every five seconds; hidden pages pause. There is no Vite/Webpack build. Browser modules are served directly and deployment relies on cache revalidation plus file-modification query keys.

## CSS Cascade

`frontend_stylesheets()` owns the exact 20-file order:

1. Foundation, shell, shared components, tables, and workflows.
2. Domain files for inventory, scan, handovers, wristbands, purchases/OCR, reports, admin, settings, documentation, and assets.
3. Classic, KONA, and Official KONA theme overrides.
4. Print rules.
5. `mobile.css` last.

This is a cascade contract. Moving a rule or stylesheet can change existing UI without changing markup. No CSS build step exists.

## API V1

There are 30 mobile operations documented in `docs/openapi/mobile-api-v1.yaml` plus one KONA wristband integration operation in `docs/openapi/wristband-api-v1.yaml`. Every response uses top-level `data`, `meta`, and `error` keys.

The mobile API implements password login, refresh rotation/reuse detection, logout, current-user/password verification, bootstrap, differential sync, employee operation history, assigned storages/items, lookup/item detail, usage/restock/batch mutation, handovers, receipts, closeout/approval/cancellation, and custody returns.

Mutation requests use `client_operation_id` for idempotency and `expected_balance` for optimistic conflict detection. Server responses return authoritative balance updates and sync cursors. A `409 balance_changed` response does not post partial stock. Batch operations are atomic. API queries are scoped server-side; hiding a Flutter control is not authorization.

The wristband endpoint authenticates an integration key, optionally enforces IP rules, hashes codes, locks sessions/evidence rows, detects duplicate/conflicting external events, and records evidence. It does not post stock directly; handover reconciliation remains the stock boundary.

## Flutter Client

The Flutter application is under `mobile/`, pinned by `mobile/.fvmrc` to Flutter `3.44.9`, and versioned `1.3.4+11`. `mobile/lib` contains 44 Dart files organized into shared core infrastructure and feature folders.

Core code owns Dio/API access, secure token storage, Drift-backed local drafts, Riverpod providers, foreground differential sync, and scanner/reconciliation rules. Features cover authentication, inventory/storage lookup, scanning, usage/restock, handovers/custody, sync/conflict review, and settings.

Offline entries are drafts only. The client never edits authoritative balances locally and never stores the employee password. It sends stable operation IDs, expected balances, canonical/package input, proof, and workflow intent; PHP revalidates all of it. Usage retries preserve the same operation ID after an ambiguous transport failure, while a definite balance conflict reloads and rebases the cart to the authoritative storage quantity before confirmation.

## Tests

The safety baseline combines three layers:

- Static contracts for module boundaries, frontend assets, route order, API markers/OpenAPI, permissions, workflow states, exports, and schema.
- Transactional characterizations for stock, package/unit conversion, proof/file archive behavior, negative-stock rejection, and cleanup.
- HTTP lifecycle regression for auth, CRUD, permissions, requests, handovers, custody, purchases, stocktakes, assets, exports, departments, and mobile API behavior.

`tests/safety_baseline.php` runs the relevant suite twice against loopback only. It snapshots every database table and every required durable file before testing, then requires an exact match after each pass. Fixture generation is an explicit review action, never part of a normal test run.

## Backup And Deployment

`scripts/backup.php` creates a repeatable-read consistent SQL snapshot, encrypts the database and full application/durable files as AES-256 ZIP archives, verifies every entry, writes a manifest and SHA-256 file, removes plaintext SQL, and only then applies retention. The password comes from an external file; it is never accepted as a literal argument or written to the manifest.

`scripts/restore_verify.php` verifies the checksums and encryption, extracts into an empty local web root, imports into an explicitly named disposable local database, compares table counts and schema hash, resolves active `file_assets`, checks protected storage and unauthenticated download denial, boots the app, and checks stock invariants.

Deployment remains an external operation. Git does not store credentials, server access instructions, backup passwords, signing keys, or environment values. Before deployment, require a second verified off-server recovery set, clean diff/secret scan, all checks, and explicit approval.

## Known Risks

- `index.php` is an 823-line route composition root and first-match order is fragile.
- Global procedural functions and eager manifest loading create hidden load-order dependencies.
- Schema migration on request bootstrap increases blast radius and complicates read-only startup.
- The full regression is broad and valuable but monolithic, slow, and dependent on a realistic MariaDB fixture.
- CSS relies on a large ordered cascade, while views and native modules share implicit DOM contracts.
- MariaDB is canonical. At least one valid inventory query is rejected by MySQL 9 because their `DISTINCT`/`ORDER BY` rules differ.
- PHP 8.5 reports missing explicit CSV escape-argument deprecations in wristband import parsing; production PHP 8.3 does not yet emit them.
- The production deployment marker observed during backup was older than the code actually present. Deployment verification must compare code, Git commit, and marker instead of trusting the marker alone.
- Two historical active file paths required recovery from their archive copies. The hardened backup now treats either missing active path as a hard failure.

## Historical Context

Relevant prior task references: `KONA Refactor Plan` (`6a98534e-2e18-83eb-b747-c5bc4c6d54d0`) and `Build inventory tracker` (`019ecc03-3a18-74e3-be9c-06bef50ebc14`).

Relevant modularization commits are `1ef1247`, `8049bd2`, `6731282`, `6e242c4`, `933ab86`, `45e4d7b`, `f59a1cf`, `e29e977`, `54dd437`, `40c35fc`, `e9716a6`, `d2926d8`, `2900fbb`, `8f1749f`, and `8590535`. Use Git history for their exact changes; do not infer unfinished work from a task title.
