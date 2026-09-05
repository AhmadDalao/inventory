# Inventory KONA Mobile API

Updated: 2026-08-31

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
- Every accepted stock mutation returns authoritative changed balances and the latest inventory event cursor. Flutter updates the acting device from this response; it never invents the new balance.

## Enablement

Mobile access is disabled by default. **Mobile App Eligibility** in the permission checklist allows an account to be considered for mobile use, but does not enable sign-in. An owner must open `/mobile-access`, search for the employee, and configure the employee from one setup card:

1. Enable the global API switch.
2. Select the employee's direct manager.
3. Enable mobile sign-in for the employee.
4. Assign one or more allowed storages and one default storage.
5. Grant only the required mobile capabilities.
6. Review the matching website permissions. The page can add the required baseline permissions automatically.
7. Enable direct restock only for trusted users.

Enabled staff accounts must have both a manager and an assigned storage. The default storage must be one of the assigned storages. Disabling mobile access revokes the employee's active mobile device sessions.

The reporting line can also be maintained from `/users/hierarchy`, which provides a searchable directory, bulk manager assignment, desktop drag-and-drop, and touch-safe manager selectors. Storage membership remains managed from the employee or storage page; moving someone in the reporting hierarchy does not silently grant stock access.

The existing permission catalog still applies. A mobile capability does not bypass `items.view`, movement, handover, or custody permissions. Effective access is always the intersection of the website permission, Mobile Access capability, assigned storage, active account/grant/device, supported app version, workflow status, and record relationship.

The server recomputes this intersection on every protected request. Revoking a permission, storage assignment, employee grant, or device therefore blocks the next request even if the employee still has an old screen open. Bootstrap returns only effective capabilities, and handover payloads return server-computed `allowed_actions`; those fields drive the Flutter UI but never replace API authorization.

Key permission rules:

- Quantity/catalog reads require `items.view` and return assigned storages only.
- Usage requires `movements.usage` plus the Usage mobile capability.
- Direct restock requires `movements.restock`, the Restock capability, the employee direct-restock grant, and the global direct-restock setting.
- Transfers require `handovers.create` plus the Transfer capability and an assigned source storage.
- Staff requests/handovers require `handovers.request` or `handovers.create` plus the Handover capability.
- Receipt, closeout, approval, custody return, cancellation, and record reads also require the correct workflow status and user relationship.

If an employee's mobile setup is incomplete, `/me`, `/bootstrap`, and `/sync` return `mobile_setup_incomplete` with machine-readable `missing_permissions`, `requires_storage`, and `requires_manager` details. Flutter shows a short setup instruction instead of an empty storage screen or a raw HTTP exception.

### Manager Routing And Storage Roles

- `/me` and `/bootstrap` return the employee's current direct manager when one is assigned.
- Each authorized storage includes `access_role`: `owner` or `member`.
- A member can see and act only within assigned storages and granted capabilities. An owner can approve stock for that storage only when the matching website permission also exists.
- Managers receive notifications for direct-report scan in/out, usage, restock, request, and handover activity. The same observer routing is used by mobile operations and committed web Scan Center usage/refill batches. Active global Owners and relevant storage co-owners are also notified.
- Manager visibility never grants stock approval by itself. The API independently checks storage ownership on every request, receipt, transfer, closeout, or correction action.
- Request, handover, and mobile-operation records snapshot the manager id for audit/history; current access is still recalculated on each protected request.
- Changing a manager, removing a storage assignment, demoting a co-owner, or disabling a user changes the access fingerprint and takes effect on the next visible sync.
- Item lookup, quantities, selectors, workflow payloads, realtime changes, and exports are all derived from the same assigned-storage scope. A direct item id outside that scope is not returned.

The complete server authorization and Owner-resolution contract is maintained in [`team-routing-and-owner-resolution.md`](team-routing-and-owner-resolution.md).

## Authentication

- Login uses the employee email and password.
- Access tokens expire after 15 minutes.
- Refresh tokens rotate and expire after 30 days.
- Only token hashes are stored on the server.
- Tokens are stored by Flutter in Android Keystore or iOS Keychain.
- Device sessions can be revoked from Mobile Access.
- Refresh checks the global switch, employee access, revocation, account status, and minimum app version again.
- Refresh-token rotation is single-use. Reuse of an already-rotated refresh token revokes the complete device session and requires a new login.
- Keep Signed In stores rotating tokens in Android Keystore or iOS Keychain, never the employee password. When disabled, tokens stay in memory only.
- Initial login requires the password. Enabling Keep Signed In later from Settings calls `POST /me/verify-password`; secure persistence remains disabled unless the current password is verified successfully.
- Optional biometric unlock protects a persisted cold-start session; password login remains the fallback.

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

The Flutter Home screen lists every assigned storage under **My storages**, including role, item count, units, and the default marker. Selecting a storage opens Quantity Check already scoped to it. This replaces the old behavior that exposed only the default storage and made valid secondary assignments look missing.

### Usage and Restock

The app builds an empty review cart; it never inserts demo stock. Submission uses `POST /movements/batch`. Usage requires an active server-configured reason. The owner controls reason labels, display order, and active state from `/mobile-access`; reason codes stay immutable for reporting integrity. The default catalog is Online, Walk-in, Event, Damage, Sport, School, Complimentary, No Show, and Other. `Other` requires a description.

Each storage returns a `usage_profile` of `wristband` or `general`. The bootstrap payload returns both catalogs in `settings.usage_reason_catalogs`; Flutter selects the catalog that matches the active source storage. The legacy `settings.usage_reasons` field remains the wristband catalog for older APKs during rollout. Wristband storages use Online, Walk-in, Event, Damage, Sport, School, Complimentary, No Show, and Other. General storages use Cleaning, Operations, Maintenance, Event, Damage, Department Supplies, and Other. The server validates the submitted reason against the source storage profile, so a stale or modified client cannot mix the catalogs.

The app may apply one cart-wide default reason and a different override on individual lines. Package conversions come from each item's `package_presets`, not from client-side assumptions. Legacy `noshow` input normalizes to `no_show` without rewriting historical records.

If proof is mandatory in Mobile Access, the batch is rejected before any stock changes unless a proof image is attached. Direct restock requires both the owner setting and per-employee enablement.

### Measured Inventory And Refill

Every item owns one canonical stock unit and one compatible measurement dimension. Examples are `roll` for toilet paper, `mL` for liquid soap, `g` for powder, and `mm` or `m` for pipe/material. Balances and negative-stock checks use only that canonical unit.

Admins define reusable package presets on the item. A preset includes a normalized package type, display label, conversion multiplier, optional scan code, and active state. Supported types are Individual, Pack, Box, Bag, Bottle, Container, Roll, Bundle, Carton, and Other. Only Other accepts a custom label. Employees may select a preset but cannot submit their own multiplier. For example, `2 x 1 L bottle` is validated by PHP and stored as `2,000 mL`; reports retain both representations.

The API returns `package_type` alongside the legacy `label`, `multiplier`, `scan_code`, and `active` fields. Older clients may continue using the label and multiplier. New clients should use the normalized type for consistent presentation while still displaying the server-owned label.

New movement lines may send:

```json
{
  "item_id": 15,
  "storage_id": 10,
  "input_quantity": 2,
  "package_preset_id": 7,
  "expected_balance": 7250,
  "reason": "school"
}
```

Legacy clients may continue sending `quantity`; it is interpreted as canonical-unit quantity. Mutation responses return the server-approved input quantity, package, conversion, canonical quantity, authoritative balance, employee department snapshot, and realtime cursor.

Flutter provides separate Usage and Refill review carts. Refill remains restricted by assigned storage, `movements.restock`, mobile restock capability, the employee direct-restock grant, and the global direct-restock switch. Repeated scans increment only the same item/package combination.

Usage and refill proof are controlled independently by global defaults plus each item's `Inherit`, `Required`, or `Optional` policy. If one cart line requires proof, the entire batch is rejected atomically until a protected image is attached. The resulting file is linked to every movement in that submission.

### Department Attribution

Users may be assigned to a managed department and a direct manager. Accepted movements snapshot department and manager names/IDs at submission time, so historical reports do not change when an employee later transfers teams. The optional `departments.require_assignment` setting blocks new operational movements for employees without a department; it is disabled by default and existing users begin under `Unassigned`.

Mobile bootstrap and sync expose only assigned storages. Co-owner/member changes from the storage detail page alter the access fingerprint and take effect on the next visible sync. The API repeats the storage-scope check for every lookup and mutation; hiding a storage in Flutter is not the security boundary.

### Temporary Handover

The recipient confirms exact, short, or excess quantities. Exact receipt becomes active immediately; a variance waits for issuer confirmation. The recipient enters returned quantities first, the app calculates used quantities, and the issuer approves final stock posting.

### Storage Transfer

The destination owner confirms receipt. Full receipt moves buffer stock to the destination. A shortage returns missing stock to the source. It never shows usage reasons.

### Long-Term Custody

The employee confirms receipt and later submits partial serviceable, damaged, consumed, or lost returns. Damaged quantities require proof. Issuer approval moves stock to source, quarantine, usage, or loss as appropriate.

## Synchronization

`GET /sync?after=<event_id>` reads a monotonic server event ledger and returns only authorized changed items, current assigned-storage balances, archived/deleted tombstones, workflow tasks, permissions, capabilities, and storage scope. A response includes `meta.next_cursor`, `meta.latest_cursor`, `meta.has_more`, and an `access_fingerprint`.

Flutter polls every five seconds only while the app is visible and once immediately after returning to the foreground. It paginates until `has_more` is false. The website uses the same visible-page timing for stock-sensitive pages. Hidden/background clients stop polling.

Events are written in the same database transaction as the stock or workflow change. The acting client also receives `balance_updates` and `sync_cursor` in every accepted mutation response, so its screen updates immediately without waiting for the next poll.

Events older than 90 days may be removed by the daily maintenance job. If a cursor is older than retained history, sync returns `full_resync_required: true` and `reason: cursor_expired`; Flutter safely reloads `/bootstrap`.

If another user changed the balance after a draft was prepared, a mutation returns `409 balance_changed` with machine-readable `item_id`, `storage_id`, `expected_balance`, and `current_balance`. The draft must be reviewed and reconfirmed; the client cannot overwrite the server.

Permission, storage-assignment, device, account, global API, and minimum-version changes alter the access fingerprint. Flutter reloads bootstrap or signs out as appropriate on the next visible sync.

`GET /operations/mine` returns the current employee's latest 100 server submissions. It never exposes another employee's payload or the owner-only operation audit.

## Validation Commands

```bash
php -l tests/mobile_api_contract.php
php tests/mobile_api_contract.php
php tests/mobile_usage_reasons.php
php tests/measured_inventory.php
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
