# Security And Incident Guide

Updated: 2026-08-14

## Security Boundaries

- Production secrets belong only in the server `.env`. They must never be committed or embedded in Flutter/APK files.
- HTTPS is mandatory. Android cleartext networking is disabled.
- Browser sessions use secure cookies, session regeneration, CSRF validation, CSP, HSTS, clickjacking protection, and permission-checked routes.
- Uploads are permission checked, size limited, and validated using detected MIME type rather than trusting the filename.
- Production errors return safe messages and must not reveal SQL, stack traces, tokens, or filesystem paths.

## Mobile Sessions

- Access token lifetime: 15 minutes.
- Rotating refresh token lifetime: 30 days.
- Server stores token hashes, not raw tokens.
- Keep Signed In stores rotating tokens in Android Keystore or iOS Keychain and never stores passwords.
- Disabling Keep Signed In keeps tokens in memory only.
- Optional biometric unlock protects a persisted cold-start session. Password login remains available.
- Reuse of a rotated refresh token is treated as theft/replay and revokes the entire device session.
- Owner can revoke devices from Mobile Access.

## Authorization

Mobile access is the intersection of:

1. Active employee account.
2. Website permission.
3. Matching mobile capability.
4. Assigned storage.
5. Active mobile grant.
6. Unrevoked device session.
7. Supported app version.
8. Valid workflow status and user relationship.

Flutter hides unauthorized actions, but PHP performs the authoritative check on every request. Removing a permission, storage, grant, device, or account access takes effect on the next request/sync.

## Abuse Controls

- Login, refresh, synchronization, and mutations are rate limited by user/device/IP context.
- Every mobile mutation carries an idempotency operation ID.
- Every stock change creates an immutable movement and synchronization event.
- Mobile operation logs record employee, device, app version, storage, operation ID, status, and safe failure details.
- Privileged workflow corrections create audit entries.

## Credential Rotation

Credentials that have been shared in chat, email, screenshots, or support tickets must be considered exposed. Rotate them from the Hostinger control panel:

1. Database password; update production `.env` immediately.
2. FTP account password.
3. SSH/hosting password or, preferably, replace password access with a restricted SSH key.
4. Any API keys used by OCR or email services.
5. Revoke all mobile device sessions and browser sessions after rotation.

Do not place replacement values in Git, documentation, Flutter `--dart-define`, or an APK.

## Release Security Gate

Before release:

```bash
composer audit
git diff --check
php tests/mobile_api_contract.php
php tests/frontend_assets.php
php tests/module_boundaries.php
php tests/full_regression.php --base-url=https://inventory.ahmaddalao.com --allow-live --prefix=ZZSECURITYYYYYMMDD
php tests/stock_invariants.php
```

Also verify that `.env`, `mobile/android/key.properties`, `.jks`, and `.keystore` files are not tracked. Build signing credentials remain outside the repository.

## Incident Response

If a stock or authentication anomaly appears:

1. Disable the global Mobile API switch.
2. Revoke the affected devices and employee mobile grants.
3. Preserve mobile operation, audit, movement, web-server, and database evidence.
4. Run stock invariants before attempting correction.
5. Restore only from a verified backup when a transactional correction cannot explain the ledger.
6. Rotate affected credentials or tokens.
7. Correct through audited stock/workflow operations; never hard-edit balances to make a number look right.
