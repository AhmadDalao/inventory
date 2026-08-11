# Inventory KONA Mobile 1.1.0 Release Gate

Updated: 2026-08-11

## Scope

Version `1.1.0+3` replaces the duplicated Flutter usage-reason list with the server-owned catalog. It also removes demo cart stock, supports per-item reason overrides, stores custom Other descriptions, uses server package presets, protects storage changes, enforces proof settings, and replaces raw HTTP errors with employee-safe messages.

## Required Checks

```bash
php tests/mobile_usage_reasons.php
php tests/mobile_api_contract.php
php tests/module_boundaries.php
php tests/frontend_assets.php
php tests/full_regression.php
php tests/stock_invariants.php

cd mobile
flutter analyze
flutter test
flutter build apk --release \
  --dart-define=MOCK_MODE=false \
  --dart-define=API_BASE_URL=https://inventory.ahmaddalao.com/api/v1 \
  --dart-define=APP_VERSION=1.1.0
```

## Completed Release Evidence

Production was backed up before the API deployment on 2026-08-11:

- SQL: `inventory-backup-20260811-221000.sql`
- Protected files: `inventory-backup-20260811-221000.files.zip`
- Manifest: `inventory-backup-20260811-221000.manifest.json`
- Backup result: 17,495 files archived with no warnings.

The following checks passed against the deployed production code:

- PHP lint for all changed API, Mobile Access, and test files.
- Mobile usage-reason catalog and normalization tests.
- Mobile API contract, module-boundary, and frontend-asset tests.
- Authenticated live mobile lifecycle, including all active reasons, `Other` custom text, unknown/disabled reason rejection, idempotency, stale-balance conflict, atomic rollback, restock, token rotation, logout, and cleanup.
- Full live regression using temporary `ZZMOBILEV1120260811` data, followed by cleanup.
- Production stock invariants after cleanup.
- `flutter analyze` with no issues and 13 passing Flutter tests.
- Signed release installation and launch on a Pixel 7 Android API 36 emulator.
- Emulator UI verification at phone width: empty initial cart, all nine reasons visible, per-item School override, and server package preset `Box ×50` producing 50 units.
- Live Mobile API switch and employee grants were left at their pre-test values; lifecycle cleanup does not silently enable or disable production access.

No physical Android device was attached to the build machine. The signed APK is ready for the required 1–3 employee physical-device pilot before broad rollout.

## Signed Android Artifact

- APK: `output/mobile/inventory-kona-1.1.0+3.apk`
- Package: `com.konajeddah.inventory`
- Version: `1.1.0+3`
- Minimum Android: API 24
- Target Android: API 36
- SHA-256: `9cbdc1200de4bc7085570d8d1d2216186a41f153da454e1e5003a1394a3ba418`
- Android signature: APK Signature Scheme v2 verified, RSA 2048 signer `KONA Inventory`.

Emulator captures are stored beside the APK in `output/mobile/`, including the release login screen and the mock usage-cart acceptance screens.

## Employee Acceptance

- Usage cart starts empty.
- Online, Walk-in, Event, Damage, Sport, School, Complimentary, No Show, and Other appear in the configured order.
- Other cannot submit without a description.
- One default reason can be overridden per item.
- Repeated scans increment only the matching item.
- Item package buttons use server multipliers.
- Changing storage with stock in the cart requires confirmation and clears the cart only after approval.
- Mandatory proof blocks submission until an image is attached.
- Disabled mobile access and server failures show concise instructions, never Dio stack text.

## Rollout

1. Back up production database and files.
2. Deploy the backward-compatible PHP update first.
3. Run live API smoke, full regression, and stock invariants.
4. Confirm reason settings at `/mobile-access`.
5. Install the new APK for one to three pilot employees.
6. Monitor Mobile Access operation failures, duplicates, and conflicts.
7. Expand only after real scans and stock totals reconcile.

The old APK remains compatible during rollout, but employees need the new APK to see the server-managed catalog and per-item reason controls.
