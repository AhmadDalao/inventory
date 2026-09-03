# Inventory KONA Mobile 1.3.4+11 Release

Release date: 2026-09-04

## Purpose

This release hardens wristband usage submission without changing the API,
stock rules, permissions, routes, schemas, or web interface.

The exact Flutter `POST /api/v1/movements/batch` payload was exercised twice
against an isolated restore of the verified production recovery set. Usage,
authoritative balance updates, sync cursors, atomic rollback, and same-operation
idempotency all passed. The API did not reproduce a general submission defect,
so no backend deployment is required for this release.

The original phone attempt was not available in an authenticated production
operation log during diagnosis. Its exact trigger therefore remains unknown;
this release addresses the concrete client failure and recovery gaps found in
the submission path rather than claiming an unverified server root cause.

## Mobile fixes

- A usage cart now keeps one `client_operation_id` across ambiguous network
  retries, preventing a lost response from becoming a second stock deduction.
- A `409 balance_changed` response reloads the catalog and rebases cart lines to
  the authoritative storage balance and current package conversion before retry.
- Submission failures remain visible in a review dialog instead of disappearing
  in a short snackbar.
- Proof attachment now offers camera or gallery and reports picker/permission
  failures instead of allowing an uncaught platform error.

## Verification evidence

- Exact Flutter wristband payload against restored data: PASS twice
- Same-ID retry without a second deduction: PASS twice
- Stock invariants: PASS
- Stock movement characterization: PASS
- Flutter focused tests: PASS (44 tests)
- Flutter analysis: PASS, no issues
- Flutter full suite: PASS (62 tests)
- PHP lint: PASS (`index.php` plus 421 files)
- JavaScript syntax checks: PASS
- Composer validation/audit: PASS; no security advisories
- Aggregate repository safety baseline: PASS twice with exact cleanup
- APK package/version metadata: PASS (`com.konajeddah.inventory`, `1.3.4+11`)
- APK signature and checksum: PASS

The aggregate wristband workflow emits the existing PHP 8.5 warning that CSV
escape parameters should be explicit. That unrelated parser warning predates
this release; the workflow and cleanup still pass, and its production code was
not changed as part of the mobile submission fix.

## Recovery point

The release was diagnosed against an isolated restore derived from the
encrypted, restore-verified recovery set
`inventory-safety-baseline-20260902T184343Z-cd126a9f`. The restore, encryption
keys, and environment credentials remain outside Git.

## Android artifact

- APK: `output/mobile/inventory-kona-1.3.4+11.apk`
- SHA-256 file: `output/mobile/inventory-kona-1.3.4+11.apk.sha256`
- SHA-256: `0c304e6d7da469c22087ce4b4f3ad4262b670a96a2cba67c441b5021e8491d99`
- Signature: APK Signature Scheme v2, KONA Inventory release certificate
- Certificate SHA-256: `f1d8bbfa6207a7fd446d7c60d177a8f7b93433e3a88f03f858452da381d3366c`
