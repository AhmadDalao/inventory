# Inventory KONA Mobile 1.2.1+6

Release date: 2026-08-21

## Scope

- Unified manager, storage, default-storage, capability, and permission controls on `/mobile-access`.
- Staff mobile access now requires an active manager and at least one assigned storage.
- Disabling mobile access revokes active device sessions.
- `/me`, `/bootstrap`, and `/sync` return an actionable `mobile_setup_incomplete` response instead of silently returning no storages.
- Flutter Home lists every assigned storage with role, item count, unit count, and default marker.
- Selecting a storage opens Quantity Check scoped to that location.
- Employee-facing API errors remain short and do not expose Dio internals.

## Artifact

- APK: `output/mobile/inventory-kona-1.2.1+6.apk`
- Checksum: `output/mobile/inventory-kona-1.2.1+6.sha256`
- Package: `com.konajeddah.inventory`
- Version name: `1.2.1`
- Version code: `6`
- Minimum Android: API 24

## Local Verification

- PHP lint sweep: passed.
- JavaScript syntax checks: passed.
- Mobile API contract: passed.
- Module boundary checks: passed.
- Frontend asset registry checks: passed.
- Flutter analyze: passed with no issues.
- Flutter tests: 24 passed.
- Signed release APK build: passed.

## Production Safety

A production database/files backup was created before deployment. Production PHP lint, API contract checks, stock invariants, and smoke checks must pass before pilot distribution. The APK must be reinstalled because this release changes Flutter screens and version metadata.
