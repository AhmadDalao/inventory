# Inventory KONA Mobile 1.3.0+7 Release

Updated: 2026-08-22

## Scope

- Canonical measurement dimensions: count, volume, mass, length, area, and custom.
- Decimal package conversion controlled by admins.
- Separate measured Usage and Refill Storage carts.
- Optional package barcode scanning.
- Global plus per-item proof requirements for usage and refill.
- Protected proof links on accepted movements.
- Managed departments with immutable movement department/manager snapshots.
- Storage-detail owner/staff assignment using the shared access service.
- Department, manager, employee, package, unit, reason, item, storage, and date report filters.
- Dashboard totals grouped by compatible canonical unit.

## Accounting Contract

The server is the only conversion and stock authority. Flutter sends `input_quantity` and an optional `package_preset_id`. PHP validates the preset and stores only converted canonical quantity in balances while preserving the entered representation in movement metadata.

Legacy APK requests containing `quantity` remain valid canonical-unit submissions.

## Release Gate

```bash
php tests/measured_inventory.php
php tests/mobile_api_contract.php
php tests/module_boundaries.php
php tests/frontend_assets.php
php tests/full_regression.php --base-url=https://inventory.ahmaddalao.com --allow-live --prefix=ZZMEASURED20260822
php tests/stock_invariants.php
cd mobile
flutter analyze
flutter test
```

Production deployment requires database/files backup first. After deployment, rerun stock invariants before enabling measured refill for pilot employees.

## Artifact

- APK: `output/mobile/inventory-kona-1.3.0+7.apk`
- SHA-256: `output/mobile/inventory-kona-1.3.0+7.sha256`
- SHA-256 value: `d909ab81125c34137054619680ea5ffffe15078a3c8ce6d737f41bf8e4ca46da`
- Package: `com.konajeddah.inventory`
- Version code: `7`
- Android signature: v2 verified
- Production web runtime: PHP `8.3.30`
- Pre-deployment SQL backup: `storage/backups/inventory-backup-20260822-071221.sql`
- Pre-deployment manifest: `storage/backups/inventory-backup-20260822-071221.manifest.json`
- Pre-deployment protected-files archive: `storage/backups/inventory-backup-20260822-071221.files.zip`

The signed APK is the pilot artifact. Do not rebuild it with a different upload key or distribute a newer binary without updating the checksum and this release record.
