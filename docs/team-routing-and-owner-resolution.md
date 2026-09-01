# Team Routing, Storage Authority, And Owner Resolution

This document is the maintained contract for staff reporting lines, shared storage ownership, assigned-storage visibility, notifications, approvals, and exceptional Owner corrections.

## Core Rule

Identity, observation, and stock authority are separate concerns:

- A **manager** observes direct-report activity and receives alerts.
- A **storage member** can see and use only assigned storages within their permissions.
- A **storage co-owner** can approve stock workflows for that storage when the matching permission exists.
- A **global Owner** can resolve exceptional workflow states through audited, purpose-aware stock operations.

Manager assignment alone never grants stock approval. A permission checkbox alone never bypasses storage assignment. Raw status changes never bypass inventory movements.

## Data Model

| Record | Purpose |
|---|---|
| `users.manager_user_id` | One direct manager for an employee. Self-management and reporting loops are rejected. |
| `user_storage_assignments` | Many-to-many storage access with `member` or `owner` authority and one optional default storage. |
| `storages.owner_user_id` | Compatibility primary owner; not the complete ownership list. |
| Workflow `manager_user_id` snapshots | Historical manager routing on requests, handovers, and mobile operations. |
| Notifications and audit rows | Immutable evidence of who acted, who observed, and which exceptional resolution occurred. |

## Authorization Chain

Every protected web or mobile action must pass this chain on the server:

1. Active authenticated account.
2. Required feature permission.
3. Assigned storage scope, unless the user is a global Owner or has `storages.view_all` for read access.
4. Required workflow relationship, such as requester, recipient, direct manager, source owner, or destination owner.
5. Storage `owner` authority for stock approval where the workflow requires it.
6. Valid purpose-aware workflow state.
7. Mobile grant, device, capability, and supported-version checks when called through `/api/v1`.
8. Stock validation, row locking, and negative-balance protection before mutation.

The browser and Flutter app may hide unavailable controls, but that is usability only. The server repeats every authorization check.

## Assigned-Storage Visibility

Users without global storage visibility receive a storage-scoped catalog:

- Item lists, item counts, quantities, storage names, item detail, history, edit/copy actions, selectors, direct movement entry, and exports are limited to assigned storages.
- Direct item URLs outside the assigned scope return `404` instead of revealing that another storage contains the item.
- Search and filters cannot use an unassigned storage id to bypass scope.
- Exports use the same scope and quantity calculation as the visible page.
- The default storage must be one of the user's active assignments.
- System buffers, quarantine locations, supplier costs, and global physical totals stay hidden unless separately authorized.

An item is a shared catalog identity, but its visible balance is always calculated from the caller's permitted storages.

## Manager Routing

Managers receive deduplicated notifications for direct-report requests, handovers, scan in/out, usage, restock, transfer, and other mobile stock activity. Managers can open related records only when they have the relevant view permission.

The reporting line is maintained at `/users/hierarchy`. The default directory supports search, manager/department/mobile filters, row selection, and transactional bulk assignment. The optional compact tree retains desktop drag-and-drop, while every row also has a touch-safe manager selector. All paths call the same `team.manage`-protected action, reject self-management and cycles, update the compatibility routing field, and write an audit record for each changed employee.

Manager routing is observational:

- It does not grant receipt confirmation.
- It does not grant request or handover approval.
- It does not grant direct stock movement.
- It does not expose unrelated storage balances.

If the same manager is also a co-owner of the affected storage and has the required permission, approval comes from storage ownership, not from being the manager.

## Shared Storage Ownership

A storage may have several owners and members:

- `member`: scoped visibility and permitted operational actions.
- `owner`: member access plus storage-level approval authority when the required workflow permission exists.

Source and destination ownership are checked independently. A source owner cannot automatically confirm destination receipt, and a destination owner cannot alter source stock outside the workflow.

## Notification Routing

Workflow notifications are deduplicated and sent to the relevant combination of:

- Direct participants.
- The acting employee's manager.
- Managers of related staff participants.
- Source and destination storage co-owners.
- Every active global Owner.

The actor is excluded from their own notification. Historical workflow rows retain the manager snapshot even if the employee later changes manager.

Committed web Scan Center usage/refill batches use the same observer routing as mobile stock operations. Notification failure is logged after the inventory transaction commits; it must never roll back a valid stock movement.

## Administration Surfaces

- `/users/hierarchy`: searchable staff directory, bulk manager assignment, and optional reporting tree.
- User create/edit: manager, department, assigned storages, default storage, and website permissions.
- Storage detail/edit: co-owners and staff membership for that location.
- `/mobile-access`: mobile enablement, default storage, capabilities, direct-restock grant, devices, and operation diagnostics.

These controls intentionally overlap in presentation but not in authority. Manager assignment routes work; storage membership scopes stock; website permissions authorize features; Mobile Access can narrow mobile behavior but cannot expand either of the first two.

## Global Owner Resolution

Owner Resolution exists for operational mistakes and stuck workflows. It is not a free-form status dropdown.

Owner-only actions may recover, reopen, safely close, cancel, or void a request or handover only through its purpose-aware stock service. Every resolution must:

- Recalculate current workflow stock impact.
- Reverse or post inventory through normal movement functions.
- Preserve movement rows, files, proofs, and workflow history.
- Refuse any operation that would make a location negative.
- Record the Owner, previous state, new state, reason, quantities, and timestamp.
- Notify affected participants, managers, and storage owners.

Temporary use, storage transfer, and long-term custody have different valid transitions. Generic closing is forbidden when custody evidence, destination receipt, or return approval is still required.

## Feature Extension Checklist

When adding a workflow or mobile action:

1. Define who initiates, observes, receives, approves, and resolves it.
2. Decide which storage assignments are visible and which `owner` role is authoritative.
3. Add server-side list scope and direct-record guard checks.
4. Apply the same scope to selectors, totals, AJAX payloads, exports, and realtime sync.
5. Route notifications through the shared observer helpers.
6. Keep manager observation separate from approval authority.
7. Add global Owner resolution only through audited stock-safe operations.
8. Add regression tests for unassigned direct URLs, filter bypass, export leakage, unauthorized approval, duplicate submission, and stock invariants.
9. Update this document, the developer handover, mobile API reference, in-app documentation, and diagrams.

## Required Regression Coverage

- Manager sees a direct report's record but cannot approve without storage ownership.
- Multiple storage co-owners receive relevant alerts and can approve only their storage.
- Members cannot see unassigned items, quantities, storage names, direct URLs, selectors, or exports.
- `storages.view_all` expands read scope but does not grant stock approval.
- Manager, storage assignment, permission, device, or account revocation takes effect on the next protected request/sync.
- Owner resolution preserves audit history and stock invariants for every handover purpose.
- Bulk assignment, drag/drop, and touch manager assignment reject reporting cycles and preserve every active user in the hierarchy view.
- Web and mobile usage/refill notify the direct manager, relevant storage co-owners, and global Owners exactly once per accepted operation.
