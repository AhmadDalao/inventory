# Inventory KONA Mobile

Flutter client for Inventory KONA. The app scans and prepares operations; the PHP API remains the only authority allowed to post stock.

## Architecture

The app is organized by feature under `lib/features/` and shared infrastructure under `lib/core/`:

- `core/api`: Dio client, token refresh, and session persistence.
- `core/security`: keep-signed-in and optional biometric cold-start unlock.
- `core/sync`: foreground-only five-second differential synchronization.
- `core/data`: API/mock repositories, Drift draft queue, and providers.
- `core/logic`: reconciliation and scanner-debounce rules.
- `features/auth`: login and device registration.
- `features/inventory`: home and assigned-storage quantity lookup.
- `features/scanner`: barcode/QR capture and scan-out action selection.
- `features/movements`: usage cart and accountable scan-in.
- `features/handovers`: create, receipt, closeout, custody returns, and task details.
- `features/sync`: offline draft review, retry, conflict, discard, and employee-scoped server activity.
- `features/settings`: device/storage/session information.

Offline drafts never post stock. On retry the server rechecks permissions and balances. Every mutation carries a stable `client_operation_id`, so retrying the same draft cannot deduct twice.

Usage and refill carts support decimal canonical units and server-defined package presets. Presets include a normalized type (Individual, Pack, Box, Bag, Bottle, Container, Roll, Bundle, Carton, or Other), a server-owned label, and a conversion. Flutter previews conversions, but PHP recomputes and validates every multiplier before posting. The app keeps the entered measurement for display while balances remain canonical (`2 x 1 L bottle = 2,000 mL`, never two separate stocks). Legacy API payloads without `package_type` remain supported.

The Flutter interface never grants access by itself. Routes, navigation, and action buttons use the current bootstrap permissions/effective capabilities and each handover's server-provided `allowed_actions`. The PHP API rechecks the same action independently, so revoked permissions, storage assignments, mobile grants, and devices fail closed even from stale screens or direct deep links.

Initial sign-in always requires the employee password. If an employee later enables **Keep me signed in** from Settings, the app asks for the current password again and the API verifies it under rate limits before any session token is written to secure storage. The password is never saved; only rotating access/refresh tokens are stored in Android Keystore or iOS Keychain.

The Sync Center separates local drafts from confirmed server activity. `GET /operations/mine` is employee-scoped and never exposes another employee's submissions or owner-only request payloads.

## SDK And Dependencies

Flutter is pinned in `.fvmrc`. Install [FVM](https://fvm.app/) or use the exact stable Flutter version named there.

```bash
cd mobile
fvm flutter pub get
fvm flutter analyze
fvm flutter test
```

The committed `pubspec.lock` is authoritative. Do not casually upgrade scanner, Drift, networking, or secure-storage dependencies in a workflow change. `flutter_secure_storage` is pinned to `10.3.1`; version `11.0.0` currently requests an Android SDK target name that the official SDK manager does not install consistently.

## Mock Prototype

Mock mode uses fixture repositories and never contacts production:

```bash
flutter run -d chrome \
  --dart-define=MOCK_MODE=true \
  --dart-define=APP_VERSION=1.3.1
```

The reviewed phone/tablet captures are in `../docs/mobile/mockups/`. The approved prototype becomes the production UI; there is no separate throwaway design app.

## API-Connected Run

```bash
flutter run -d <device-id> \
  --dart-define=MOCK_MODE=false \
  --dart-define=API_BASE_URL=https://inventory.ahmaddalao.com/api/v1 \
  --dart-define=APP_VERSION=1.3.1
```

The server's Mobile API switch is disabled by default. Enable only selected pilot employees from `/mobile-access` after API deployment and live stock checks.

## Android Release Signing

The upload keystore and passwords must stay outside Git. Create `android/key.properties` locally:

```properties
storePassword=REDACTED
keyPassword=REDACTED
keyAlias=kona_inventory
storeFile=/absolute/path/to/kona-inventory-upload.jks
```

Back up both the keystore and its credentials in the owner's password manager. Losing the upload key is a release incident, not a minor inconvenience.

Build the internal APK:

```bash
flutter build apk --release \
  --dart-define=MOCK_MODE=false \
  --dart-define=API_BASE_URL=https://inventory.ahmaddalao.com/api/v1 \
  --dart-define=APP_VERSION=1.3.1
```

Output: `build/app/outputs/flutter-apk/app-release.apk`.

Current secure-session pilot artifact (generated during the release gate):

- File: `../output/mobile/inventory-kona-1.3.1+8.apk`
- Package: `com.konajeddah.inventory`
- Version: `1.3.1` (`versionCode 8`)
- Minimum Android: API 24
- SHA-256: recorded in `../output/mobile/inventory-kona-1.3.1+8.sha256` after the signed build.
- Checksum file: `../output/mobile/inventory-kona-1.3.1+8.sha256`
- Release evidence: `../docs/mobile/release-1.3.1.md`

Verify it before distribution:

```bash
shasum -a 256 ../output/mobile/inventory-kona-1.3.1+8.apk
$ANDROID_HOME/build-tools/36.0.0/apksigner verify --verbose --print-certs \
  ../output/mobile/inventory-kona-1.3.1+8.apk
```

## iOS

Use the same source with bundle ID `com.konajeddah.inventory`. A signed TestFlight build requires a complete Xcode installation, CocoaPods, and an active Apple Developer account.

## Tests Before Release

```bash
flutter analyze
flutter test
flutter build web --release --dart-define=MOCK_MODE=true
flutter build apk --release \
  --dart-define=MOCK_MODE=false \
  --dart-define=API_BASE_URL=https://inventory.ahmaddalao.com/api/v1 \
  --dart-define=APP_VERSION=1.3.1
```

Physical-device acceptance must cover repeated scans, package conversion, exact/short/excess receipt, usage, transfer, temporary handover, custody return proof, token expiry, offline draft retry, and a stale-balance conflict.

## Release And Rollback

1. Back up production database and files.
2. Deploy PHP API/schema code with the Mobile API disabled.
3. Run PHP lint, API contract tests, full regression, and stock invariants.
4. Enable one to three pilot employees and assigned storages.
5. Distribute the signed APK internally.
6. Watch `/mobile-access` operation failures, conflicts, and duplicate retries.
7. Revoke devices or disable the API immediately if stock behavior is suspicious.
8. Roll back PHP code and disable the API; mobile drafts remain local and cannot post while disabled.

See `../docs/mobile-api.md` and `../docs/openapi/mobile-api-v1.yaml` for the server contract.
