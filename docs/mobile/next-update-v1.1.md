# Mobile v1.2 Plan: Stocktakes and Alerts

## Guided Stocktakes

- Owner assigns a storage stocktake to one or more employees.
- Mobile performs blind counting: expected quantity is hidden while scanning.
- Repeated scans increment counts and package presets convert to pieces.
- Counts remain drafts until submitted online.
- Server compares counted and expected balances.
- Owner reviews discrepancies and approves adjustment movements.
- Every variance retains employee, device, time, storage, item, and note.

## Push Alerts

- Use Firebase Cloud Messaging for Android and APNs through Firebase for iOS.
- Register and revoke push tokens per device session.
- Send reference-only notifications for receipt tasks, quantity differences, approvals, stocktake assignments, sync conflicts, and low stock.
- Never expose sensitive stock or financial data on the lock screen.
- Opening an alert fetches current server state; notification payloads are not trusted as workflow state.

## Release Gate

Start v1.2 only after the Android v1.1 pilot proves authentication, server-configured usage reasons, scanning, idempotency, offline drafts, handovers, and stock invariants in daily use.
