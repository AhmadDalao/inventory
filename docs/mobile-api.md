# Inventory KONA Mobile API

Updated: 2026-08-11

The mobile API is the controlled bridge between the Flutter application and the existing Inventory KONA stock engine. The app never writes MySQL directly and never calculates final stock locally.

## Safety Model

- `item_storage_balances` remains the stock source of truth.
- All mutations call existing PHP stock/workflow functions inside database transactions.
- Negative balances are blocked on the server.
- Every mutation requires a UUID `client_operation_id`; retries return the original result instead of posting twice.
- Batch movements are atomic: all lines succeed or all lines roll back.
- Cached mobile balances are informational. Every stock line sends `expected_balance`; the server locks and compares the authoritative storage row. A stale operation receives `409 balance_changed` and must be reviewed again.
- Offline mode stores drafts only. Reconnection and server validation are mandatory before stock changes.
- Proof images use protected workflow storage.

## Enablement

Mobile access is disabled by default. An owner must open `/mobile-access` and:

1. Enable the global API switch.
2. Enable an employee.
3. Assign allowed storages and one default storage.
4. Grant only the required capabilities.
5. Enable direct restock only for trusted users.

The existing permission catalog still applies. A mobile capability does not bypass `items.view`, movement, handover, or custody permissions.

## Authentication

- Login uses the employee email and password.
- Access tokens expire after 15 minutes.
- Refresh tokens rotate and expire after 30 days.
- Only token hashes are stored on the server.
- Tokens are stored by Flutter in Android Keystore or iOS Keychain.
- Device sessions can be revoked from Mobile Access.
- Refresh checks the global switch, employee access, revocation, account status, and minimum app version again.

## Response Contract

Every response follows:

```json
{
  "data": {},
  "meta": {},
  "error": null
}
```

Errors return `data: null` and include a stable code, message, field errors, and `retryable` flag. The authoritative contract is [`openapi/mobile-api-v1.yaml`](openapi/mobile-api-v1.yaml).

## Main Workflows

### Quantity Check

`GET /items/lookup?q=...` finds an item by barcode, SKU, or name and returns balances only for assigned storages.

### Usage and Restock

The app builds an empty review cart; it never inserts demo stock. Submission uses `POST /movements/batch`. Usage requires an active server-configured reason. The owner controls reason labels, display order, and active state from `/mobile-access`; reason codes stay immutable for reporting integrity. The default catalog is Online, Walk-in, Event, Damage, Sport, School, Complimentary, No Show, and Other. `Other` requires a description.

The bootstrap payload returns `settings.usage_reasons`. The app may apply one cart-wide default reason and a different override on individual lines. Package conversions come from each item's `package_presets`, not from client-side assumptions. Legacy `noshow` input normalizes to `no_show` without rewriting historical records.

If proof is mandatory in Mobile Access, the batch is rejected before any stock changes unless a proof image is attached. Direct restock requires both the owner setting and per-employee enablement.

### Temporary Handover

The recipient confirms exact, short, or excess quantities. Exact receipt becomes active immediately; a variance waits for issuer confirmation. The recipient enters returned quantities first, the app calculates used quantities, and the issuer approves final stock posting.

### Storage Transfer

The destination owner confirms receipt. Full receipt moves buffer stock to the destination. A shortage returns missing stock to the source. It never shows usage reasons.

### Long-Term Custody

The employee confirms receipt and later submits partial serviceable, damaged, consumed, or lost returns. Damaged quantities require proof. Issuer approval moves stock to source, quarantine, usage, or loss as appropriate.

## Synchronization

`GET /sync?since=<cursor>` returns updated authorized items, archived/deleted tombstones, and current employee handover tasks. A successful response provides the next cursor in `meta.sync_cursor`.

`GET /operations/mine` returns the current employee's latest 100 server submissions. It never exposes another employee's payload or the owner-only operation audit.

## Validation Commands

```bash
php -l tests/mobile_api_contract.php
php tests/mobile_api_contract.php
php tests/ocr_parser_contract.php
php tests/module_boundaries.php
php tests/full_regression.php
php tests/stock_invariants.php
```

Run stock invariants against production only after a backup and controlled deployment.
After that backup, the authenticated mobile lifecycle can be verified with temporary prefixed records:

```bash
php tests/mobile_api_live.php \
  --base-url=https://inventory.ahmaddalao.com \
  --allow-live \
  --prefix=ZZMOBILEAPIYYYYMMDD
```

The lifecycle test temporarily enables the API for its isolated employee, verifies authentication, storage isolation, usage, idempotency, stale-balance conflicts, atomic rollback, restock, token rotation, and logout, then restores the prior mobile settings and removes its records.
