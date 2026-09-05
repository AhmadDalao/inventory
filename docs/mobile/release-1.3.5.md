# Inventory KONA Mobile 1.3.5+12 Release

Release date: 2026-09-04

## Purpose

This release fixes the Flutter handover closeout action labeled **Submit for
issuer approval**. The screen previously awaited the API inside `try/finally`
without catching failures. Validation, permission, connectivity, and stale
workflow errors therefore escaped without any visible explanation, making a
valid button tap look inert.

## Mobile fix

- Closeout submission failures now remain visible in a review dialog.
- The dialog uses the existing API error mapping and preserves the server's
  actionable validation message.
- The closeout route, request payload, permissions, reconciliation rules, stock
  posting, web UI, and API v1 behavior are unchanged.

The server still requires operational reason totals to reconcile with physical
usage. A positive unaccounted difference requires a receiver discrepancy note;
the app now shows that rejection instead of silently discarding it.

## Verification

- The new widget regression failed before the fix with an uncaught `ApiFailure`
  and no dialog, reproducing the reported dead tap.
- The focused closeout regression passes twice after the fix and verifies that
  the button is enabled again after reviewing an error.
- The repository contract verifies the exact closeout endpoint and payload.
- Flutter analysis: PASS, no issues.
- Flutter full suite: PASS (64 tests).
- Android emulator acceptance: PASS on `KONA_Pixel_7_API_36`. The signed
  production APK cold-started successfully, and a same-signed release-mode mock
  run advanced the exact wristband handover from `Delivered` to
  `Pending Approval` after tapping **Submit for issuer approval**. Logcat showed
  no Flutter exception. The emulator was restored to the production APK with
  mock state cleared afterward.
- PHP and JavaScript syntax, mobile API contract, Composer validation/audit,
  and stock invariants: PASS.
- Aggregate safety baseline: PASS twice against an isolated restore running
  MariaDB 11.8.9, including the exact Flutter wristband payload, all 31 API v1
  operations, full HTTP workflows, stock invariants, and cleanup verification.
- Physical-device acceptance: pending because no Android device is connected.

Distribution remains a candidate until the physical-device check passes.

## Android artifact

- APK: `output/mobile/inventory-kona-1.3.5+12.apk`
- SHA-256 file: `output/mobile/inventory-kona-1.3.5+12.apk.sha256`
- SHA-256: `15124e38f4ab299d6bce62db4199195600ee422dd35884bbeeb42c8383209d30`
- Package/version: `com.konajeddah.inventory`, `1.3.5+12`
- Signature: APK Signature Scheme v2, KONA Inventory release certificate
- Certificate SHA-256: `f1d8bbfa6207a7fd446d7c60d177a8f7b93433e3a88f03f858452da381d3366c`
