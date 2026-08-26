# Inventory KONA Mobile 1.3.3+10 Release

Release date: 2026-08-26

## Storage usage profiles

Storages now declare the reporting workflow used by their stock:

- **Wristband / guest access** uses Online, Walk-in, Event, Damage, Sport,
  School, Complimentary, No Show, and Other.
- **General operations** uses Cleaning, Operations, Maintenance, Event,
  Damage, Department Supplies, and Other.

Existing storages are migrated to Wristband so the deployed behavior does not
change unexpectedly. New storages default to General Operations. Owners can
change a storage profile from the storage create or edit page.

The selected profile is enforced by the server for website and mobile usage
movements. Flutter receives both managed catalogs during bootstrap and shows
only the reasons valid for the selected storage. Reports resolve labels from
both catalogs, and storage CSV/XLSX exports include the usage profile.

## Mobile Access controls

The Mobile Access page now manages two independent reason catalogs. Owners can
rename, reorder, enable, or disable reasons without changing their immutable
reporting codes. The API continues to return the legacy wristband list for old
installed builds while version 1.3.3 consumes the profile-specific catalogs.

## Compatibility

- Public website URLs are unchanged.
- Existing inventory quantities and movement history are unchanged.
- Existing storages retain their previous wristband reason behavior.
- Old APKs continue receiving the backward-compatible wristband catalog.
- Version 1.3.3 is required for automatic reason switching by storage profile.

## Verification evidence

- PHP lint sweep: PASS (404 PHP files plus `index.php`)
- JavaScript syntax checks: PASS
- Module boundary checks: PASS
- Frontend asset registry: PASS
- Mobile usage-reason tests: PASS
- Mobile API contract tests: PASS
- Flutter analysis: PASS
- Flutter tests: PASS (58 tests)
- Git whitespace validation: PASS
- APK signature verification: PASS

Live regression and stock-invariant results are recorded in the deployment
verification after the release is installed on production.

## Production recovery point

The newest complete production recovery set was validated before cleanup:

- SQL: `inventory-backup-20260826-105114.sql`
- Manifest: `inventory-backup-20260826-105114.manifest.json`
- Protected files: `inventory-backup-20260826-105114.files.zip`

The protected-files ZIP passed archive verification. Older duplicate production
and local backup sets were removed after this recovery point was validated.

## Android artifact

- APK: `output/mobile/inventory-kona-1.3.3+10.apk`
- SHA-256 file: `output/mobile/inventory-kona-1.3.3+10.apk.sha256`
- SHA-256: `87cbd19f0ece79637573ca90d78501b8f2b1dfe998d870576b93bc3cb96bae33`
- Signature: APK Signature Scheme v2, KONA Inventory release certificate
- Certificate SHA-256: `f1d8bbfa6207a7fd446d7c60d177a8f7b93433e3a88f03f858452da381d3366c`

