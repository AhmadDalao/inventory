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

Record the following after the backed-up deployment:

- Backup SQL/manifest/files archive paths.
- Deployed commit.
- Live PHP lint result.
- Live schema maintenance result.
- Live mobile API lifecycle result and cleanup prefix.
- Full regression result and cleanup prefix.
- Stock invariants before and after live tests.
- API global switch and pilot employee scope.

## APK Gate

- Expected file: `output/mobile/inventory-kona-1.2.0+5.apk`
- Expected checksum: `output/mobile/inventory-kona-1.2.0+5.sha256`
- Package: `com.konajeddah.inventory`
- Minimum Android: API 24
- Network security: Android cleartext traffic disabled

The APK is ready for pilot distribution only after signature verification, SHA-256 generation, production API smoke tests, live stock invariants, and a physical Android scan-in/scan-out test.

## Pilot Acceptance

Use one to three employees and selected storages. Confirm:

1. Usage on device A updates device A immediately and device B/web within five seconds.
2. Restock on device B updates device A/web.
3. Duplicate operation IDs deduct only once.
4. Concurrent deductions cannot create negative stock.
5. Stale drafts receive `409 balance_changed` with the current balance.
6. Permission/storage/device revocation takes effect on the next sync.
7. Movement, mobile operation, event, audit, report, and export evidence agree.
