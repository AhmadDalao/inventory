# KONA Wristband API Audit Guide

Updated: 2026-08-23

## Purpose

The wristband integration records KONA check-in evidence against a normal temporary-use handover. It does **not** deduct stock, close a handover, or replace staff reconciliation.

The accounting boundary is deliberate:

1. The source storage issues wristbands through the existing handover workflow.
2. KONA check-ins identify registered wristband codes and add hidden API evidence.
3. Staff still confirm receipt, returns, damage, and operational usage.
4. The issuer compares physical usage, staff reporting, and API evidence.
5. Existing final handover approval posts stock exactly once.

Never change the integration into direct source-storage deduction. That would create a second stock authority and eventually double-count usage.

## Pages And Permissions

The `Wristbands` navigation group contains:

- `/wristbands`: registered codes and state counts.
- `/wristbands/imports`: CSV/XLSX imports and import history.
- `/wristbands/sessions`: handover-linked API Audit sessions.
- `/wristbands/exceptions`: paused, unknown, duplicate, ineligible, and manually resolved events.
- `/wristbands/integrations`: global and per-storage controls, API key rotation, and IP restrictions.

Permissions are split into `wristbands.view`, `wristbands.import`, `wristbands.manage`, `wristbands.sessions`, `wristbands.exceptions`, `wristbands.integrations`, `wristbands.reverse`, and `wristbands.evidence`. Owner accounts receive all wristband permissions. Storage owners may pause or resume sessions for storages they own when their permissions allow it.

## Registry And Imports

Every external code is permanently mapped to one eligible count-based item/color. The database stores a normalized SHA-256 code hash and a masked display value, not the full wristband code.

Supported imports:

- Select one eligible item, then upload a file containing a `code` column.
- Upload `code + SKU`; each SKU must resolve to an active eligible item.
- CSV and XLSX are supported.

Code states:

| State | Meaning |
|---|---|
| `available` | Registered and not accepted by an API Audit session. |
| `used` | Accepted as evidence by a session. It remains in history. |
| `void` | Disabled by an audited owner/admin action. |

Imports never change item quantities or storage balances. Used codes are never hard-deleted and are hidden from the default Available filter.

## Modes And Controls

| Mode | Behavior |
|---|---|
| Manual Only | Existing handover reconciliation runs without API evidence. |
| API Audit | Valid distinct check-ins become hidden evidence for the linked handover. |
| Paused | The session remains open, but incoming events are logged without using codes or changing counters. |

Controls exist at three levels:

1. Global owner switch: enables or disables the wristband API for the whole system.
2. Storage integration switch: enables or disables one authorized storage endpoint.
3. Session controls: Pause, Resume, or permanently Switch to Manual Only.

Disabling or pausing API checking never cancels, alters, or closes the handover. Manual reconciliation remains available immediately.

## Starting A Session

API Audit is available only when all of these are true:

- The handover purpose is temporary staff use.
- The source storage has an enabled wristband integration.
- The selected item is active, count-based, and has external QR tracking enabled.
- An eligible registered code exists for the selected wristband item.
- No overlapping active/paused session already exists for the storage.

The handover create page lets the issuer choose Manual Only or API Audit. Storage transfers and long-term custody never use wristband API evidence.

## Check-In API

Endpoint:

```text
POST /api/v1/integrations/kona/wristband-checkins
```

The machine-readable API contract is maintained at [`docs/openapi/wristband-api-v1.yaml`](openapi/wristband-api-v1.yaml).

Authentication uses either:

```text
X-KONA-API-Key: kona_wb_...
```

or:

```text
Authorization: Bearer kona_wb_...
```

Request:

```json
{
  "code": "AB12CD34EF56GH78",
  "scanned_at": "2026-08-22T19:30:00+03:00",
  "external_event_id": "optional-event-id"
}
```

Successful evidence response:

```json
{
  "data": {
    "event_id": 125,
    "status": "accepted",
    "duplicate": false,
    "session": "WBS-20260823193000-ABCD",
    "code": "AB12********GH78",
    "item_id": 15
  },
  "meta": {},
  "error": null
}
```

The integration is idempotent. Repeating the same external event returns the existing event. Reusing an external event ID with different data returns `409 idempotency_conflict`.

## Paused Or Disabled Events

When the global switch, storage integration, or session is paused/disabled, the API returns HTTP `202` with `integration_paused`.

The system then:

- Records the event in Exceptions.
- Does not mark the code used.
- Does not increment the accepted API counter.
- Does not create an inventory movement.
- Does not change the handover status.

After resuming, an authorized owner may accept selected paused events or discard them with an audited reason. Nothing is applied automatically. Codes accepted before a pause remain used unless an authorized owner performs an audited reversal.

## Final Reconciliation

After staff submit their report, Owner Final Review may show:

- Confirmed received quantity.
- Returned quantity.
- Physical used quantity.
- Staff operational totals.
- Distinct API check-ins.
- Tracking mode and paused periods.
- Unresolved exceptions.
- Staff-versus-API variance.

Staff do not see hidden API evidence before submitting their report. A non-zero API variance requires acknowledgement and a note, but evidence never overwrites staff values or stock quantities.

## Security

- HTTPS is mandatory outside localhost.
- API keys are shown once, stored hashed, and can be rotated immediately.
- Each key is bound to one storage integration.
- Optional IPv4/CIDR allowlisting can restrict the KONA sender.
- The endpoint is rate-limited to 120 events per integration/IP per minute.
- Full codes are not stored in API logs or audit descriptions.
- Every switch, rotation, pause, resume, mode change, event, exception, resolution, and reversal is audited.

## Deployment And Pilot

The production default is **global API disabled**. Deployment must not enable integrations or sessions automatically.

Pilot sequence:

1. Back up database and protected files.
2. Deploy schema, modules, routes, assets, and documentation.
3. Run lint, contract tests, full regression, and stock invariants.
4. Import 100 pilot codes for one count-based wristband item.
5. Enable one storage integration and rotate/copy its API key securely.
6. Create one temporary-use handover in API Audit mode.
7. Compare API evidence with staff reconciliation for two weeks.
8. Keep Manual Only available until the booking funnel and check-in source are proven reliable.

## Tests

```bash
php tests/wristband_api_contract.php
php tests/wristband_code_performance.php
php tests/wristband_workflow.php
php tests/full_regression.php
php tests/stock_invariants.php
```

The performance test registers 10,000 normalized codes and verifies uniqueness and lookup behavior without changing stock. The workflow test exercises import, pause, resume, manual acceptance, discard, reversal, and Manual Only fallback inside one transaction, then rolls everything back after proving that movement rows and balance totals did not change.

## Troubleshooting

| Result | Meaning / action |
|---|---|
| `invalid_api_key` | Rotate or correct the per-storage key. |
| `ip_not_allowed` | Correct the allowlist or sender IP. |
| `integration_paused` | Check global, storage, and session switches; resolve events manually if needed. |
| `unknown_code` | Import and map the code before accepting it. |
| `item_not_eligible` | Confirm active count-based item and external QR tracking. |
| `wrong_handover` | The code belongs to an item not issued in the active handover. |
| `idempotency_conflict` | The sender reused an external event ID for different data. Fix the sender. |
| `rate_limited` | Respect `Retry-After`; investigate retry storms. |
