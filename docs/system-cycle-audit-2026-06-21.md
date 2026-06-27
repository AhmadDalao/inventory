# Full Inventory System Cycle Audit

Date: 2026-06-21
Scope: local codebase at `/Users/ahmaddalao/Desktop/inventory`

This audit covers the current pages, workflow cycles, role paths, stock movement logic, exports, AJAX behavior, OCR/import, and mobile/UI risks. It is intentionally written before refactoring code so fixes can be batched in the right order.

## Verification Run

- Passed: PHP lint sweep with `find . -name '*.php' -print0 | xargs -0 -n1 php -l`.
- Passed: JavaScript syntax check with `node --check assets/app.js`.
- Not run: `php tests/full_regression.php`, because the default base URL points at the live domain and the test creates/deletes operational data. This test should be guarded before being used as a normal local safety check.

## Priority Summary

### P0 - Data Or Stock Risk

- The stock model still has two visible quantity sources: `items.current_quantity` and `item_storage_balances.quantity`. The code has a sync helper, but this needs invariant tests around every movement type so item totals never drift from storage balances.
- The full regression test is guarded for live usage and requires `--allow-live` when targeting the production domain.
- Arabic scanned PDF OCR is not production-grade on the current server path. The code depends on local binaries or browser OCR fallback, but reliable old scanned Arabic documents need a real OCR provider path with async review.

### P1 - Workflow Blockers Or Confirmed Bugs

- Labels are capped at 300 rows because `app/workflows.php` uses `LIMIT 300`; this explains the 301 item vs 300 labels issue.
- Collapsed sidebar hides real icons because the CSS hides all `span` elements inside nav links, and the app icons are rendered as `span.ui-icon`.
- The collapsed sidebar still shows the section label because the KONA/theme CSS injects `"General"` with `::before`.
- Purchase/reorder supplier summary boxes can render even when marked `hidden` because CSS display rules override the `hidden` attribute.
- Supplier type `Other` is only a fixed option. It needs a custom text field and storage column.
- Supplier required fields are partly handled, but validation and UI need one shared supplier component so purchase/reorder pages stop drifting.

### P2 - UI/UX And Maintainability

- `app/workflows.php`, `app/controllers.php`, `assets/app.css`, and `assets/app.js` are too large. This is now hurting safe changes.
- Purchase, reorder, and handover forms contain repeated long sections that should be cards/accordions with fields shown only when needed.
- AJAX behavior is broad and scattered. The desired pattern is action-driven refresh only after a detected create/edit/delete/approve/reject/receive/close/archive/recover/upload/export action.
- Mobile layout needs browser-level testing for dashboard, purchases, requests, handovers, reorder, items, storages, files, docs, and admin pages.

### P3 - Cleanup

- Documentation is broad and useful, but should include sharper workflow diagrams for staff, storage owners, finance, CFO, and owner.
- Exports exist across modules, but the exported fields should be standardized and documented per department.
- Theme switching exists, but the KONA style should be one clean stylesheet layer, not stacked overrides.

## Auth, Setup, Login, Logout

Current behavior:
- Setup, login, logout, session checks, password hashing, owner detection, and user status checks exist.
- User roles are owner/admin/staff, while business positions and permission sets carry the real operational controls.
- Owner has broad access. Admin/staff access is permission-driven.

Missing behavior:
- Password recovery uses reset links sent through PHP mail, with hashed reset tokens and 60-minute expiry.
- Login/logout attempts are audited through `login_attempts` and activity logs.
- No browser tests for topbar account menu, logout dropdown, and mobile session behavior.

Wrong or risky behavior:
- The UI recently moved account actions toward the topbar, but the sidebar/topbar split still needs consistency testing.
- Permission presets exist, but a full role/position matrix regression is missing.

Recommendation:
- Keep role simple: owner/admin/staff. Use positions for CFO, accountant, operations manager, storage manager, reception staff, and general admin.
- Add a permission matrix test that logs in as each default position and verifies visible routes/actions.
- Add audit rows for login/logout/security-sensitive user changes.

Priority:
- P1 for role-permission matrix tests.
- P2 for topbar/account UX cleanup.

Test coverage needed:
- Owner can access every module.
- CFO can access finance/files/purchases based on preset permissions.
- Accountant can view/export financial purchase data but not mutate stock unless granted.
- Staff sees only staff-safe dashboard, requests, and assigned handovers.
- Disabled users cannot log in.

## Layout, Topbar, Sidebar, Search, Notifications

Current behavior:
- Sidebar, topbar, global search, notification feed, notification popup hooks, and theme-based styling exist.
- Global search endpoint searches items, storages, suppliers, purchases, requests, handovers, files, and users where allowed.
- Notification feed exists and can be rendered without full reload.

Missing behavior:
- No CSS/DOM regression test for collapsed sidebar.
- No action-driven notification popup acceptance test.
- No keyboard navigation contract for global search results.

Wrong or risky behavior:
- Collapsed sidebar hides icon spans because icons are also spans.
- Collapsed sidebar section label remains visible.
- Some topbar icons are blank/fragile because icon rendering and CSS are not isolated.

Recommendation:
- Change collapsed CSS to hide only `.nav-text`, not all spans.
- Make `ui_icon()` render icons with a class that is never hidden by generic nav rules.
- Hide section headings and generated labels in collapsed state.
- Add a browser smoke test for sidebar open/collapsed/mobile.

Priority:
- P1 for collapsed sidebar and icon visibility.
- P2 for global search keyboard and popup polish.

Test coverage needed:
- Sidebar expanded shows icons and labels.
- Sidebar collapsed shows icons only.
- Topbar account dropdown contains profile/position/logout.
- Notification popup appears after a workflow action.
- Search can find item, SKU, barcode, supplier, storage, purchase, and request.

## Dashboard

Current behavior:
- Dashboard has metrics, charts, filters, storage/date filters, recent activity, purchase/request/handover cards, and role-specific staff behavior.
- Staff dashboard is intended to focus on what they receive or hold, not inventory totals.

Missing behavior:
- Dashboard cards need invariant checks against the same source of truth as storage balances.
- Staff-only dashboard needs a regression test to ensure no inventory-private quantities leak.
- Dashboard filters need action-driven AJAX tests instead of timer refresh tests.

Wrong or risky behavior:
- Metrics can become misleading if they use item total fields without rechecking storage balances.
- The dashboard has grown visually from multiple theme iterations.

Recommendation:
- Make storage balances the dashboard quantity source.
- Keep item totals only as a derived snapshot.
- Split dashboard metrics into owner/admin/staff datasets.
- Test dashboard filters by date/storage/status.

Priority:
- P0 for quantity metric correctness.
- P2 for visual cleanup.

Test coverage needed:
- Dashboard total quantity equals sum of `item_storage_balances`.
- Date filter changes purchase/request/handover charts.
- Storage filter scopes all storage-dependent cards.
- Staff dashboard excludes global stock totals.

## Storages

Current behavior:
- Create/edit/archive/recover, storage detail, export, storage copy, item assignment, movement history, and zero-quantity visibility are implemented.
- Storage copy can copy item definitions with zero quantities.
- Storage value and export by storage exist.

Missing behavior:
- No complete invariant test proving zero-quantity assigned items remain visible across all storages.
- No test proving removing an item from one storage does not archive/delete the catalog item or other storage balances.
- No test proving copied storage item count includes zero-quantity items.

Wrong or risky behavior:
- Earlier issues showed zero quantity was treated like absent stock in some views. This must stay a general rule, not a one-storage patch.
- Storage value depends on balance visibility and item cost accuracy.

Recommendation:
- Treat `item_storage_balances` row existence as "this item belongs to this storage", even when quantity is zero.
- Remove item from storage by soft/deleted balance relation or inactive assignment, not by deleting the catalog item.
- Add storage copy tests with zero-quantity rows.

Priority:
- P0 for item removal affecting only one storage.
- P1 for zero-quantity visibility.

Test coverage needed:
- Create storage A with 12 assigned items, 6 at zero. Detail shows 12.
- Copy storage A to B with no quantity. Detail and count show 12.
- Remove item from B. A still has item and history.
- Archive storage preserves movement logs.
- Recover storage restores visibility without corrupting quantities.

## Items

Current behavior:
- Create/edit/archive/recover, image upload/display, item detail, barcode field, unit picker, SKU reuse direction, movement history, labels, and storage assignments exist.
- SKU is no longer the correct global identity for stock uniqueness because the same catalog item can exist in multiple storages.

Missing behavior:
- Barcode optional/required setting must be enforced consistently in create/edit/purchase quick-create/import.
- Label printing must not hard cap at 300.
- Item location removal needs a dedicated test for removing one storage assignment only.
- Image paths need regression around uploaded item images in tables, dropdowns, detail pages, and copied storages.

Wrong or risky behavior:
- `items.current_quantity` can mislead if not synchronized after every stock-affecting action.
- `items.storage_id` still exists as a preferred/default storage, which can be confused with actual stock location.
- Labels query has `LIMIT 300`.

Recommendation:
- Define item identity as catalog data plus per-storage balance rows.
- Keep `items.current_quantity` as derived cache only.
- Rename UI copy from "current quantity" to "total across locations" where appropriate.
- Remove label hard limit or paginate/export all selected labels.

Priority:
- P0 for quantity source of truth.
- P1 for labels >300 and location removal.
- P2 for image consistency.

Test coverage needed:
- Add 301 labelable items. Labels output shows 301.
- Barcode optional mode allows blank barcode.
- Barcode required mode blocks blank barcode in item and purchase quick-create forms.
- Same SKU appears in multiple storages without duplicate blocking.
- Item image appears in item table, item detail, request picker, handover picker, and purchase picker.

## Movement Log

Current behavior:
- Movements are immutable operational history for restock, usage, transfer, adjustment, requests, handovers, purchases, and stocktakes.
- Movement logs survive archive/delete behavior.
- Movement export exists.

Missing behavior:
- Need a complete movement taxonomy document and tests for every context type.
- Need a reconciliation report: balance per item/storage equals sum of movement deltas from origin.

Wrong or risky behavior:
- If any workflow bypasses `apply_inventory_movement()`, balances can drift.
- Movement logs with context IDs need link integrity checks.

Recommendation:
- Require all stock-changing workflows to call one movement service.
- Add a reconciliation command/test that rebuilds expected balances from movements and compares to `item_storage_balances`.

Priority:
- P0 for movement/balance reconciliation.

Test coverage needed:
- Restock increases selected storage.
- Usage decreases selected storage without needing negative input.
- Transfer decreases source and increases destination.
- Purchase final receipt creates restock context.
- Stocktake approval creates adjustment context.
- Request and handover contexts generate the expected issue/transfer/return movements.

## Requests

Current behavior:
- Staff requests and admin transfer requests exist.
- Approval/rejection, received quantity mismatch, receipt confirmation, approver correction, self-approval block, notifications, and visibility restrictions are implemented.
- Staff should not select destination storage for usage requests.

Missing behavior:
- Full role matrix tests are missing for staff vs admin request creation.
- Need tests for requester-reported short receipt and approver final correction.
- Need tests that request action refreshes affected tables/cards without full reload.

Wrong or risky behavior:
- Request workflow is complex and located inside a large workflow file, which raises regression risk.
- Staff/admin request modes must stay clearly separated in UI.

Recommendation:
- Split request workflow into domain service/controller/view files.
- Keep three clear states: pending approval, receipt review, complete.
- Make request line cards show requested, approved, received, missing, and final accepted quantities.

Priority:
- P1 for receipt mismatch and self-approval regression.
- P2 for UI compaction.

Test coverage needed:
- Staff request has no destination storage field.
- Admin transfer request has destination storage field.
- Creator cannot approve own request.
- Receiver reports 98 of 100 received.
- Approver confirms 98 and 2 returns/remains correctly.
- Staff sees only created/assigned requests.

## Handovers

Current behavior:
- Direct handover, handover request, staff receipt, close, used/returned calculation, storage-owner approval, self-approval blocking, and movement posting exist.
- Staff should see only their relevant holding cards and handovers.

Missing behavior:
- Need short receipt confirmation like requests, where staff confirms exact delivered quantity.
- Need tests that used quantity auto-calculates returned quantity live.
- Need tests for storage-owner approval of closed handover.
- Need UI test for dropdown collapse after selecting an item.

Wrong or risky behavior:
- Handover forms are too long and expose too much at once.
- Dropdown/picker state can remain open after item selection or clicking away.

Recommendation:
- Shorten handover request and direct handover into compact cards.
- Use one shared searchable item picker that closes on select, blur, and escape.
- Make status steps explicit: requested, approved, delivered, receipt review, active, waiting approval, closed.

Priority:
- P1 for quantity confirmation and close approval correctness.
- P2 for picker and form length.

Test coverage needed:
- Staff requests handover from assigned storage owner.
- Staff cannot request from unrelated owner when assignment exists.
- Direct admin handover appears for selected staff.
- Staff confirms actual received quantity.
- Used quantity changes returned quantity instantly.
- Staff close moves to waiting approval.
- Storage owner approval returns remaining quantity and closes.

## Purchases, Receiving, Documents, OCR

Current behavior:
- Purchases support suppliers, destination storage, approver, line items, documents, approval, receiving, final confirmation, protected documents, quick-create items, weighted average cost, purchase exports, and dashboard/storage/item history links.
- Approval links/creates items but does not add stock.
- Final receipt confirmation creates restock movements and updates cost.
- Self-approval is blocked.

Missing behavior:
- Production-grade Arabic scanned PDF OCR is missing.
- OCR/import needs async provider fallback and human review queue.
- Purchase form supplier picker and item cards need shared reusable components.
- Need tests for protected document access by role.
- Need tests for OCR exact supplier match and unknown supplier create mode.

Wrong or risky behavior:
- Server OCR depends on local `pdftotext`, `pdftoppm`, and `tesseract`; if missing, extraction degrades.
- Browser OCR fallback is not reliable for old scanned Arabic PDFs and large document batches.
- Purchase and reorder supplier summary visibility can be broken by CSS overriding `hidden`.

Recommendation:
- Add provider-based OCR service: AI OCR provider first for PDFs/images/Arabic, local extraction fallback second, manual entry fallback always.
- Store OCR confidence and extracted raw text per document.
- Add a review screen before creating purchase lines from OCR.
- Keep stock posting only on final receipt approval.

Priority:
- P0 for OCR production path if old Arabic documents are core data import.
- P1 for protected file access tests and supplier picker bugs.
- P2 for purchase form compaction.

Test coverage needed:
- Draft cannot submit without at least one proof file.
- Rejected purchase changes no stock.
- Approved purchase changes no stock before receipt.
- Final receipt posts only confirmed quantity.
- Short receipt posts only final accepted quantity.
- Weighted average cost recalculates correctly.
- Protected document download is blocked without permission.
- Arabic scanned PDF uses provider fallback and creates reviewable line suggestions.

## Suppliers

Current behavior:
- Supplier directory exists with name, type, phone/email, VAT/tax number, CR, authorized person, national address, notes, active/deleted status, show/edit/export, and purchase linkage.
- Required fields requested by the user are mostly represented in UI/schema except custom type text.

Missing behavior:
- `Other` supplier type needs an actual custom text field.
- Need duplicate handling that allows reuse after soft delete, or restore prompt.
- Need stronger searchable picker reuse across purchases/reorder.
- Need supplier recovery tests.

Wrong or risky behavior:
- Fixed enum `other` loses what the supplier actually is.
- Supplier form behavior is repeated in multiple pages, so bugs appear in one place but not the other.

Recommendation:
- Add `supplier_type_other` nullable column.
- When type is `other`, require custom type text.
- Build one supplier picker partial/component for purchases and reorder.
- For duplicates, include active and deleted conflict handling with a recover option.

Priority:
- P1 for custom other and duplicate/recover behavior.
- P2 for UI component refactor.

Test coverage needed:
- Product supplier validates mandatory fields.
- Service supplier validates mandatory fields.
- Other supplier requires custom text.
- Soft-deleted supplier name can be reused or restored through a clear flow.
- Supplier search finds name, phone, email, VAT, CR, address, and authorized person.

## Reorder Center

Current behavior:
- Reorder page shows low-stock lines, suggested quantity/value, storage filters, export, and purchase draft creation.
- It can create purchase drafts based on low-stock suggestions.

Missing behavior:
- Item count correctness tests for zero and non-zero reorder policy rows.
- Supplier form visibility should match purchase form behavior exactly.
- Need tests for include/exclude zero reorder policies.

Wrong or risky behavior:
- Current create purchase draft form is too dense.
- Supplier summary hidden behavior can be broken by CSS.
- Suggested value depends on current cost accuracy.

Recommendation:
- Use the same supplier picker and compact draft card as purchases.
- Make low-stock count equal actual visible filtered lines.
- Keep zero reorder policies opt-in, not default noise.

Priority:
- P1 for count correctness and supplier visibility.
- P2 for form cleanup.

Test coverage needed:
- Low-stock count matches table rows.
- Include zero reorder policies changes count predictably.
- Draft purchase creates lines from selected low-stock suggestions.
- Existing supplier hides new supplier fields.
- New supplier validates mandatory fields.

## Stocktakes

Current behavior:
- Stocktake create, show, count, approve, cancel, variance creation, and exports exist.
- Approval creates adjustment movements.
- Self-approval is blocked for non-owner paths.

Missing behavior:
- Need tests proving count approval reconciles only selected storage.
- Need cancellation audit test.
- Need mobile count-entry view test.

Wrong or risky behavior:
- If stocktake adjustment bypasses a central movement invariant, totals can drift.

Recommendation:
- Keep stocktake adjustment through central movement service.
- Add stocktake reconciliation tests with before/after balances.

Priority:
- P0 for movement correctness.
- P2 for mobile count UI.

Test coverage needed:
- Create stocktake from storage with zero and non-zero items.
- Count variance posts adjustment movement after approval.
- Cancelled stocktake posts no movement.
- Non-owner creator cannot approve own stocktake.

## Files Library

Current behavior:
- Centralized file library exists with protected downloads, archive copies, source module tracking, permissions, and export.
- Owner/admin/CFO style access is supported by permission checks.

Missing behavior:
- Need file ownership/source tests across purchase docs and future module files.
- Need virus/file-type policy documentation.
- Backup retention and uploaded-file inclusion are controlled from Website Control, and `scripts/backup.php` creates SQL, manifest, and optional file zip backups.

Wrong or risky behavior:
- If any module links directly to public uploads, protected file policy is bypassed.

Recommendation:
- All uploaded business files should create a `file_assets` row and be served through one protected download route.
- Add a report by source module, uploader, supplier, purchase, and date.

Priority:
- P1 for protected download tests.
- P3 for retention policy.

Test coverage needed:
- Purchase proof file appears in Files.
- Unauthorized staff cannot download restricted file.
- CFO/admin/owner can browse allowed files.
- Archived/deleted source record does not remove file audit row.

## Documentation

Current behavior:
- Documentation sections exist for pages and core workflows.
- Department guides exist for owner, CFO/finance, accountant, operations, storage manager, reception/staff, and admin/access control.

Missing behavior:
- Needs visual workflow diagrams for requests, handovers, purchases, stocktakes, and reorder.
- Needs searchable documentation test.
- Needs role-based "what you can do" summaries linked from dashboard.

Wrong or risky behavior:
- Documentation can become stale quickly because it is manually maintained.

Recommendation:
- Add concise diagrams and step cards per department.
- Tie docs to permission names so new roles map to the right guidance.

Priority:
- P2 for department onboarding clarity.

Test coverage needed:
- Documentation page loads.
- Search finds each module.
- Staff sees staff-safe docs.
- Owner/admin sees all docs.

## Admins, Users, Roles, Departments, Permissions

Current behavior:
- Users, positions, departments, permissions matrix, default presets, archive/recover, and exports exist.
- Positions include Owner/General Manager, CFO, Accountant, Operations Manager, Storage Manager, Reception Staff, General Admin, and Staff.

Missing behavior:
- Need self-permission safety tests.
- Need permission preset documentation in UI.
- Need department assignment reports.

Wrong or risky behavior:
- The word "role" can be confused with "position". In the code, role controls broad account type; position explains job; permissions control actual access.

Recommendation:
- Keep this model. Do not add hard-coded roles for every job title.
- Add UI copy explaining role vs position vs permission.
- Add "clone permissions from position" and "reset to preset" actions if not already complete.

Priority:
- P1 for permission safety tests.
- P2 for UI clarity.

Test coverage needed:
- Owner cannot remove own owner safety access.
- Admin cannot grant permissions they do not have unless owner.
- Staff cannot open admin routes.
- CFO/accountant defaults match intended finance scope.

## Settings And Website Control

Current behavior:
- Site settings, theme switching, dashboard naming, barcode requirement direction, OCR/settings direction, and UI preferences exist or are partially represented.
- KONA is the preferred visual direction.

Missing behavior:
- Barcode requirement setting must be enforced in every entry path.
- OCR provider settings need a real provider configuration screen.
- Theme switching needs a clean KONA/default implementation, not layered overrides.

Wrong or risky behavior:
- Multiple theme override layers make visual bugs harder to reason about.

Recommendation:
- Consolidate KONA into the base theme.
- Keep Classic Warm as optional fallback.
- Add settings tests for barcode required/optional and OCR provider disabled/enabled modes.

Priority:
- P1 for barcode enforcement.
- P2 for theme cleanup.

Test coverage needed:
- Barcode optional allows blank barcode in item and purchase quick-create.
- Barcode required blocks blank barcode in both paths.
- Theme change persists after reload.
- OCR disabled forces manual import mode.

## Labels

Current behavior:
- Labels page and export/rendering logic exists.
- It uses item/storage data and barcode/SKU fields.

Missing behavior:
- No pagination/selection behavior for large label sets.
- No test over 300 labels.

Wrong or risky behavior:
- Hard `LIMIT 300` caps output and silently drops item 301+.

Recommendation:
- Replace hard limit with pagination, explicit selected IDs, or a configurable/export-all flow.
- Show total selected and total generated labels.

Priority:
- P1.

Test coverage needed:
- 301 eligible items produce 301 labels.
- Filtered labels count matches selected filter.
- Barcode/blank barcode rendering respects settings.

## Audit Log And Exports

Current behavior:
- Activity/audit log exists.
- CSV exports exist for items, movements, storages, requests, handovers, purchases, files, stocktakes, suppliers, reorder, audit, and users.

Missing behavior:
- Need export field contracts by module.
- Need tests proving deleted/archived records remain represented in audit exports.
- Need permission tests for every export route.

Wrong or risky behavior:
- Export routes can drift from page filters if not sharing query builders.
- Audit coverage depends on each workflow remembering to log.

Recommendation:
- Centralize export query builders per domain.
- Define export columns in documentation.
- Add permission tests for all export routes.

Priority:
- P1 for permission/export correctness.
- P3 for export docs.

Test coverage needed:
- Each export route requires proper permission.
- Purchase export includes supplier, storage, status, quantities, prices, users, and attached files.
- Storage export includes all items per storage, including zero-quantity assigned items.
- Audit export includes archive/recover and workflow decision actions.

## Frontend, AJAX, Mobile

Current behavior:
- `assets/app.js` handles global search, notifications, live forms, AJAX movements, table shells, workflow pickers, purchase OCR/import, and UI actions.
- The desired model is action-driven refresh, similar to Livewire behavior, not blind polling.

Missing behavior:
- Need a registry of components and action events.
- Need mobile browser tests.
- Need a shared combobox/picker component for suppliers/items.

Wrong or risky behavior:
- The JS file is monolithic and has duplicated OCR paths.
- CSS hidden/display conflicts are causing real UI bugs.

Recommendation:
- Split JS by domain: core UI, search/notifications, tables, inventory actions, workflow pickers, purchases/OCR.
- Introduce shared picker behavior with close-on-select/click-away/escape.
- Refresh only affected sections after successful action responses.

Priority:
- P1 for picker/hidden bugs.
- P2 for modularization and mobile polish.

Test coverage needed:
- Handover item dropdown closes after selection and click-away.
- Purchase supplier picker hides/shows correct sections.
- AJAX update fires after create/edit/delete/approve/reject/receive/close/archive/recover/upload.
- No timer refresh changes page without an action.

## Backend Refactor Direction

Current behavior:
- Core and workflow logic are functional but spread across very large files.
- `app/workflows.php` contains many unrelated domains.
- `app/controllers.php` contains central inventory logic plus broad controller behavior.

Missing behavior:
- No domain boundaries.
- No service-level invariant tests.

Wrong or risky behavior:
- Large monolithic files increase regression risk. A small UI request can accidentally touch stock logic.

Recommendation:
- Split backend by domain:
  - `app/Domains/Inventory`
  - `app/Domains/Storages`
  - `app/Domains/Items`
  - `app/Domains/Requests`
  - `app/Domains/Handovers`
  - `app/Domains/Purchases`
  - `app/Domains/Suppliers`
  - `app/Domains/Stocktakes`
  - `app/Domains/Files`
  - `app/Domains/Labels`
  - `app/Domains/Users`
  - `app/Domains/Settings`
  - `app/Domains/Exports`
- Start by extracting pure services while keeping routes stable.

Priority:
- P2 after confirmed P0/P1 issues are fixed.

Test coverage needed:
- Existing route smoke tests still pass after each extraction.
- Stock invariant tests pass after inventory service extraction.

## Recommended Fix Order

1. Add safety guard to `tests/full_regression.php` so it cannot mutate the live domain by accident.
2. Add stock invariant tests: balances are source of truth, item totals are derived, movements are immutable history.
3. Fix labels hard limit over 300.
4. Fix collapsed sidebar icon/section-label CSS.
5. Fix supplier summary hidden CSS and consolidate supplier picker behavior.
6. Add supplier custom `Other` text field and validation.
7. Add role/permission matrix regression tests.
8. Add workflow tests for zero-quantity storage visibility, storage copy, item location removal, request receipt mismatch, handover close approval, purchase final receipt, and protected files.
9. Add provider-based Arabic scanned PDF OCR path with review queue.
10. Compact purchase, reorder, and handover forms into action-specific cards/accordions.
11. Split monolithic backend/JS/CSS files by domain.
12. Run browser mobile tests and then live verification after backup.

## Immediate Test Backlog

- Labels over 300: create/select 301 labelable items and assert 301 generated labels.
- Zero-quantity visibility: assigned zero-quantity items remain visible in storage detail and counts.
- Storage copy: copied storage includes all assignments with zero quantity.
- Item location removal: removing from one storage does not remove other storage assignments or movement logs.
- Self-approval blocking: requests, handovers, purchases, and stocktakes.
- Receipt mismatch: requester/receiver reports less than approved and approver confirms final quantity.
- Handover return approval: used quantity auto-calculates returned quantity and owner approval posts return.
- Supplier required fields: name, type, phone, authorized person, national address; CR/email optional.
- Supplier custom other: type `Other` requires custom text.
- Barcode setting: optional vs required across all item creation paths.
- Protected files: unauthorized users cannot download purchase/supplier proof files.
- OCR fallback: scanned Arabic PDF creates provider-backed review data, not silent bad rows.
- Mobile: dashboard, purchases, handovers, requests, reorder, items, storages, files, admin, docs.

## Final Take

The system has the right major modules now: inventory, storages, items, requests, handovers, purchases, suppliers, reorder, stocktakes, files, docs, users, permissions, settings, audit, labels, and exports. The real risk is no longer missing big features. The real risk is reliability under daily use: stock invariants, role safety, scanned document import, and UI state bugs caused by monolithic CSS/JS.

Fix the P0/P1 issues first. UI polish and domain refactoring should follow, but only after the data-cycle safety tests are in place.
