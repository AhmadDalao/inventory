# Inventory KONA Mobile 1.3.1+8 Release

Release date: 2026-08-24

## Security correction

Enabling **Keep me signed in** from the mobile Settings page now requires the
employee's current password. The API verifies the password under an
authenticated, rate-limited device session before Flutter may copy the current
rotating tokens into secure device storage.

- The employee password is never stored on the device or server outside the
  existing password hash.
- A wrong password leaves persistent sign-in disabled and keeps the confirmation
  dialog open with a concise error.
- Disabling persistent sign-in remains immediate.
- Initial login continues to require email and password.
- Biometric unlock still requires an already verified persistent session.

## API contract

`POST /api/v1/me/verify-password`

```json
{
  "password": "current-password"
}
```

Successful response:

```json
{
  "data": {"verified": true},
  "meta": {},
  "error": null
}
```

The endpoint requires a valid mobile access token and allows eight attempts per
five minutes for each user, device session, and IP combination.

## Verification evidence

- PHP lint sweep: PASS
- JavaScript syntax checks: PASS
- Mobile API contract: PASS
- Frontend asset registry: PASS
- Module boundary checks: PASS
- Flutter analysis: PASS
- Flutter tests: PASS (28 tests)
- Mobile API live lifecycle: PASS
- Full live regression: PASS
- Live stock invariants: PASS
- Temporary live records: cleaned

The live lifecycle explicitly verified wrong-password rejection, successful
current-password verification, storage and permission isolation, usage reasons,
idempotent retries, stale-balance conflicts, atomic rollback, privileged restock,
token rotation, refresh-token reuse detection, device revocation, and logout.

## Production safety

Backups created before deployment:

- SQL: `inventory-backup-20260824-125628.sql`
- Manifest: `inventory-backup-20260824-125628.manifest.json`
- Protected files: `inventory-backup-20260824-125628.files.zip`
- Code rollback: `code-before-persistent-login-20260824-095853.tar.gz`

## Android artifact

- APK: `output/mobile/inventory-kona-1.3.1+8.apk`
- SHA-256 file: `output/mobile/inventory-kona-1.3.1+8.sha256`
- SHA-256: `e91e03ef0415054ebed4247a3590f56565ea4afa360e36d51a7afac9fa9bb4da`
- Signature: APK Signature Scheme v2, KONA Inventory release certificate
- Certificate SHA-256: `f1d8bbfa6207a7fd446d7c60d177a8f7b93433e3a88f03f858452da381d3366c`

This APK is required for the Settings password-confirmation interface. The
backward-compatible API endpoint was deployed first, so older installed builds
continue to operate while employees update.
