# Inventory KONA Mobile 1.2.0 Release Gate

Updated: 2026-08-14

## Scope

Version `1.2.0+5` adds server-confirmed near-realtime stock, foreground-only five-second synchronization, cursor-based tombstones/workflow tasks, authoritative mutation balances, secure persistent sessions, optional biometric unlock, refresh-token reuse detection, and stronger API auditing/rate limits.

## Local Evidence

- PHP syntax sweep: passed.
- JavaScript module checks: passed.
- Frontend asset contract: passed.
- Module boundaries: passed.
- Mobile API contract: passed.
- Composer security audit: no advisories.
- Flutter analysis: passed with no issues.
- Flutter tests: passed, including delta merge and machine-readable balance conflict behavior.
- Pixel 7 API 36 emulator mock workflow: passed without clipping or horizontal overflow.
- Local stock invariants: not runnable because local MySQL is unavailable; production validation is mandatory after backup.

## Production Gate

- Backup created before schema/API work:
  - `storage/backups/inventory-backup-20260814-125750.sql`
  - `storage/backups/inventory-backup-20260814-125750.manifest.json`
  - `storage/backups/inventory-backup-20260814-125750.files.zip`
- Live PHP 8.3 lint: passed.
- Live schema maintenance: passed; realtime event and mobile token-history tables are present.
- Live mobile API lifecycle: passed using temporary prefixed records; cleanup completed.
- Differential cursor sync: passed, including authorized event delivery and forbidden-storage isolation.
- Idempotency, stale-balance `409`, atomic rollback, all usage reasons, token rotation/reuse detection, device revocation, and logout: passed.
- Full regression: passed.
- Stock invariants before and after live tests: passed.
- Temporary test users, storages, operations, settings, and events: removed.
- Mobile access remains controlled by the global switch, per-employee access, assigned storages, permissions, devices, and minimum-version setting.

## APK Gate

- Expected file: `output/mobile/inventory-kona-1.2.0+5.apk`
- Checksum file: `output/mobile/inventory-kona-1.2.0+5.sha256`
- SHA-256: `b37af9f70c328fd3f81d27a4ef3421547c1b278609b1d5aed7c607570f93d7fc`
- Signature: Android Signature Scheme v2 verified, one RSA 2048-bit KONA Inventory signer.
- Package: `com.konajeddah.inventory`
- Minimum Android: API 24
- Network security: Android cleartext traffic disabled

The APK passed signature verification, checksum generation, production API smoke tests, and live stock invariants. It is ready for a controlled pilot; physical Android scan-in/scan-out remains the final field acceptance test.

## Security Evidence

- HTTPS redirect, HSTS, CSP, frame denial, MIME sniffing protection, secure/HttpOnly/SameSite session cookies: verified live.
- Android cleartext networking: disabled.
- Composer audit: no known security advisories.
- Tracked source scan: no production database, hosting, OpenAI, token, or signing secrets found.
- Release signing key: stored outside the repository.
- Required owner action: rotate the database, FTP/SSH, and hosting credentials that were previously shared, then revoke old hosting/mobile sessions. Code cannot safely perform control-panel credential rotation.

## Pilot Acceptance

Use one to three employees and selected storages. Confirm:

1. Usage on device A updates device A immediately and device B/web within five seconds.
2. Restock on device B updates device A/web.
3. Duplicate operation IDs deduct only once.
4. Concurrent deductions cannot create negative stock.
5. Stale drafts receive `409 balance_changed` with the current balance.
6. Permission/storage/device revocation takes effect on the next sync.
7. Movement, mobile operation, event, audit, report, and export evidence agree.
