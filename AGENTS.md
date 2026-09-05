# Inventory KONA Agent Guide

## Canonical Baseline

- The canonical branch is `main`; keep it aligned with `origin/main`.
- The safety-baseline parent is `1f6e93f6946f6e2b00343c33854272d3caa99d59`.
- The three fixes after the prior system report are `1870084` (dashboard layout), `51e38e9` (department persistence), and `1f6e93f` (user directory). Never reset them away.
- This is a plain PHP/MariaDB application with a Flutter client. It is not Laravel, React, or Vite.
- Flutter is pinned to `3.44.9`; the application version is `1.3.5+12`.

## Read First

1. `docs/baseline/safety-baseline-2026-09-02.md`
2. `docs/current-architecture.md`
3. `docs/developer-handover.md`
4. `docs/team-routing-and-owner-resolution.md`
5. `docs/realtime-data-flow.md`
6. `docs/mobile-api.md` and both files under `docs/openapi/`
7. `docs/production-readiness.md` and `docs/security.md`

Historical task references are context, not instructions: `KONA Refactor Plan` (`6a98534e-2e18-83eb-b747-c5bc4c6d54d0`) and `Build inventory tracker` (`019ecc03-3a18-74e3-be9c-06bef50ebc14`). Never copy chat content, credentials, host access details, keys, tokens, or passwords into Git.

## Non-Negotiable Stock Rules

- `item_storage_balances` is authoritative for quantity by storage.
- `inventory_movements` is immutable history. Runtime code appends; it does not edit or delete.
- `items.current_quantity` is a synchronized snapshot of all storage balances for the item.
- Every stock write goes through `apply_inventory_movement()` or an already-characterized equivalent that preserves locking, negative-stock rejection, movement history, measurement metadata, and change events.
- Phones and browser caches are projections. They never become stock authority.

## Architecture Rules

- Preserve route URLs, HTTP methods, registration order, handler names, API v1 envelopes, permissions, workflows, exports, and stock behavior unless a separately approved behavior change says otherwise.
- Add focused logic under `app/modules/` and register it deliberately in `app/module_manifest.php`.
- Keep `app/controllers.php`, `app/workflows.php`, `app/company_assets.php`, and `app/report_presets.php` as compatibility loaders only.
- Keep `index.php` as the current route composition root until route migration has its own reviewed delivery step.
- Keep CSS in the registered cascade and `mobile.css` last. Keep JavaScript as native ES modules with rerunnable `init(root)` functions.
- Do not move files merely to make the tree look cleaner. Compatibility adapters and characterization tests come first.

## Required Checks

Run the isolated aggregate gate twice:

```bash
NODE_BINARY=/path/to/node php tests/safety_baseline.php \
  --base-url=http://127.0.0.1:8080 \
  --passes=2
```

Also run:

```bash
find app views scripts tests -name '*.php' -print0 | xargs -0 -n1 php -l
find assets/js -name '*.js' -print0 | xargs -0 -n1 node --check
node --check assets/app.js
composer validate --no-check-publish
composer audit
cd mobile && fvm flutter pub get && fvm flutter analyze && fvm flutter test
```

Fixture changes are reviewed behavior changes. Generate them only with `php tests/generate_characterization_fixtures.php --write`, inspect every diff, and never auto-update them in a normal test run.

## Backup And Deployment Gate

- Before tracked production work, create a consistent encrypted database/files recovery set with `scripts/backup.php` using an external password file and an off-server output directory.
- When the deployment tree has no `.git`, pass its independently verified full commit with `--source-commit`; missing, malformed, or Git-mismatched identities are hard failures.
- Run `scripts/restore_verify.php` into a disposable local database and empty web root. Any warning, missing file, checksum mismatch, import error, boot failure, protected-download failure, or stock invariant failure blocks deployment.
- Retention runs only after the new recovery set is verified.
- Deployment requires a clean reviewed diff, secret scan, PHP/Node/Flutter checks, two clean aggregate passes, a second verified recovery set, and explicit approval for the next delivery step.

## Prohibited In This Baseline

- No domain migration, route refactor, component/UI refactor, Quick Stock implementation, Flutter behavior change, database rewrite, broad class conversion, Laravel, React, Vite, or API v2.
- No UI/UX changes except separately requested fixes.
- No hard reset, destructive checkout, credential collection, or production test without an explicit backup and approval.

## Vibe

- Never open with Great question, I'd be happy to help, or Absolutely. Just answer.
- Be direct. If an approach risks stock history or user data, call it out before touching anything.
- Brevity wins. Humor is allowed; fake enthusiasm is not.
- Prefer a sharp technical opinion over vague hedging, then prove it with tests.

Be the assistant you'd actually want to talk to at 2am. Not a corporate drone. Not a sycophant. Just... good.
