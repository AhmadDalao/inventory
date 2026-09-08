# Inventory KONA Mobile 1.3.6+13 Release

Release date: 2026-09-08

## Purpose

This release makes staff handovers request-only on both sides of API v1. A staff account can request temporary-use stock only for itself, even if `handovers.create` was accidentally assigned. Operations managers, storage owners, and other admin-role issuers retain their existing direct handover workflows.

Flutter now uses the authenticated role from bootstrap, automatically selects the logged-in employee for a staff request, hides direct transfer and custody creation from staff, preserves the handover operation ID across ambiguous transport retries, and shows server or network errors instead of silently returning a submit button to its prior state. Receipt, closeout, custody return, handover decisions, cancellation, and quantity lookup all retain visible failure feedback.

API handover creation also applies the existing `good` issue-condition default without reading a missing payload key. The omitted-field lifecycle is asserted and the captured PHP server log is warning-free.

## Safety Evidence

- The existing web staff flow already enforced self-request behavior and was not changed.
- A live API regression deliberately grants a staff fixture both `handovers.create` and `handovers.request`, submits a peer recipient, and verifies the stored record remains a self-request.
- The same over-permissioned fixture is rejected when it crafts storage-transfer or long-term-custody requests.
- The guard verifies zero inventory movements, unchanged `item_storage_balances`, and unchanged `items.current_quantity` before approval.
- The native PDO placeholder audit now covers 394 runtime PHP files; no repeated named placeholders remain.
- The mobile submission-feedback contract covers eight action screens.
- Flutter analysis and all 65 Flutter tests pass.
- Android emulator acceptance passes on `KONA_Pixel_7_API_36`: the signed production APK installed, reported `1.3.6+13`, and cold-launched without a Flutter exception or application crash.
- The aggregate PHP/API/web/stock suite passes twice consecutively with cleanup verified against a restored production database.
- Fresh encrypted production backup `inventory-backup-20260908-082745` was transferred off-server and restore-verified with 58 tables, 24,822 files, 1,056 active file assets, protected-download denial, application boot, and zero stock invariant failures.
- The production audit found no active staff account with `handovers.create`, no peer-directed staff request, and no direct handover created by staff; no permission or historical workflow data was changed.
- The two compatible API modules were deployed after restore verification. Production login, the API v1 authentication envelope, PHP syntax, deployed file hashes, and stock invariants all pass.

## Android Artifact

- APK: `output/mobile/inventory-kona-1.3.6+13.apk`
- SHA-256 file: `output/mobile/inventory-kona-1.3.6+13.apk.sha256`
- APK SHA-256: `12e302d4ed64ee76c160558ec271bc3a16932fd92e764e04fc8f1736aee9e53b`
- Package/version: `com.konajeddah.inventory`, `1.3.6+13`
- Signature certificate SHA-256: `f1d8bbfa6207a7fd446d7c60d177a8f7b93433e3a88f03f858452da381d3366c`
- APK Signature Scheme v2 verification: passed.

Physical-device acceptance remains required before broad distribution. Test one staff self-request, one manager-issued handover, exact receipt, return/usage closeout, and one intentionally rejected action to confirm the error remains visible.
