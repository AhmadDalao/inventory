# Safety Baseline - 2026-09-02

## Scope

This is Delivery Sequence Steps 1 and 2 only. It adds recovery tooling, current-system documentation, characterization fixtures/tests, and cleanup enforcement. It does not migrate domains, routes, components, UI, Flutter behavior, or production business behavior.

The verified parent is `1f6e93f6946f6e2b00343c33854272d3caa99d59` on `main`, equal to `origin/main` when work began. The retained post-report fixes are `1870084` (dashboard layout), `51e38e9` (department persistence), and `1f6e93f` (user directory). No reset or revert was used.

Historical context is referenced only by title/identifier: `KONA Refactor Plan` (`6a98534e-2e18-83eb-b747-c5bc4c6d54d0`) and `Build inventory tracker` (`019ecc03-3a18-74e3-be9c-06bef50ebc14`).

## Pre-Edit Recovery Gate

A recovery set was created and verified before tracked edits:

- Recovery set: `inventory-safety-baseline-20260902T184343Z-cd126a9f`
- Database archive: 414,896 bytes; SHA-256 `114c1690b7b06194d537755f369d3362c11b2499f4f68e5fa6fe58b93c59441b`
- Files archive: 2,898,935,001 bytes; SHA-256 `3400f61f52a0b6673cd73d535fe76c751dd48f3d29474bba92c9d43558a6d769`
- Files verified: 24,708 archive files, 24,872 ZIP entries, 3,950,965,898 source bytes
- Database verified: 58 tables, 763 columns, 417 index rows, 174 foreign keys
- Active file assets verified: 999, with no unresolved path after recovery
- Stock verified: zero negative balances, zero item snapshot drift, zero orphan movements, 917 movement-history rows
- Runtime captured: PHP 8.3.30 and MariaDB 11.8.8
- Restore checks: checksums, encrypted-entry read/CRC, clean extraction, SQL import, table counts, schema, app boot, login page, protected-download denial, durable paths, and stock invariants
- Warnings: none

The recovery key is external to Git. The recovery set includes source/configuration plus `uploads/`, `assets/brand/uploads/`, `storage/assets`, `storage/purchases`, `storage/workflows`, `storage/files`, `storage/audit`, and `storage/reports`.

Two stale registered source paths were reconstructed from valid archive copies during the pre-edit recovery. The deployed commit marker reported `337ef96` while the checked application files contained the three later fixes through `1f6e93f`; that mismatch is a deployment-risk finding, not a reason to discard newer fixes.

## Hardened Recovery Gate

The completed Step 1-2 tooling produced and independently restored a second off-server recovery set:

- Recovery set: `inventory-backup-20260902-201823`
- Source identity: explicit verified parent `1f6e93f6946f6e2b00343c33854272d3caa99d59`; the isolated source copy had no `.git`, so the manifest records `commit_source: explicit`
- Database archive: 415,851 bytes; SHA-256 `9054f3bba3210ae01f907c7a6fce86d6f55b4445582816670343a6f582534475`
- Files archive: 2,927,083,431 bytes; SHA-256 `775a6cd586e4b1361aecd05f1653f3813f80b260833b217fba853bcde299a14a`
- Source content: 24,529 files and 4,034,394,216 bytes
- Manifest SHA-256: `d617dd742bbe2036e2b130027e0287b404a993af1f7bfdece82f752a93da8545`
- Runtime captured: PHP 8.5.2 and MariaDB 11.8.9
- Database/file checks: 58 tables and all 999 active `file_assets` resolved
- Restore checks: encrypted checksums, full extraction, required empty directories, SQL import, exact table counts/schema, application boot, protected-download denial, and stock invariants
- Restored stock: zero negative balances, zero item snapshot drift, and zero orphan movements
- Warnings: none

The first hardened restore attempt correctly stopped before database import because the extractor did not recreate empty durable directories. The helper and regression test were fixed to preserve and verify the complete directory graph. The superseded recovery-set artifacts were removed by retention, and only the corrected set above counts as acceptance evidence.

## Pre-Change Tests

- All 411 PHP files passed syntax checks.
- All 37 checked JavaScript entry/module/test files passed syntax checks before the local Homebrew Node linkage changed; final checks use a known-good Node runtime.
- Composer validation passed with existing warnings for no license and exact version constraints; Composer audit reported no advisories.
- Existing module boundaries, frontend assets, API contracts, measured inventory, packages, wristbands, OCR, exports, reports, hierarchy, department persistence, full HTTP regression, and stock invariant tests passed.
- Flutter `3.44.9`: dependency resolution passed, analysis reported no issues, and all 58 tests passed.
- The production-equivalent database tests use MariaDB 11.8. MySQL 9 was rejected as a substitute because it rejects a valid production MariaDB `DISTINCT`/`ORDER BY` query.

## Characterization Added

- Exact ordered route fixture: position, HTTP method, path, and handler or normalized inline-closure hash for all 264 routes.
- API v1 fixture and tests for all 31 operations, documented response codes, common success/error envelopes, authentication denial, sync metadata, conflicts, balance updates, and idempotency markers.
- Permission catalogs, role/position defaults, mobile grants, storage/file visibility, approval guards, and server-side denial markers.
- Request, handover, custody, purchase, stocktake, asset, and wristband status/side-effect contracts, backed by the full HTTP lifecycle regression.
- Transactional restock, usage, transfer, adjustment, row locking, insufficient-stock rejection, per-storage balances, synchronized item total, ordered movement history, and runtime immutability checks.
- Count/volume/mass/length/area mappings, canonical precision, custom units, package conversion behavior, invalid combinations, and proof policies.
- File path confinement, idempotent registration, immutable archive copies, deletion markers, protected storage, upload cleanup, export headers, and complete-result export guards.
- Exact 58-table column/index/foreign-key fixture.
- Exact stylesheet order, module/initializer registry, compatibility adapters, and `inventory:action-complete`, `inventory:content-replaced`, and `inventory:refresh` wiring.
- Aggregate two-pass runner that hashes every table and every required durable file before and after each pass.

## Cleanup Defects Found And Fixed

Characterization initially exposed test-only residue: department change events, mobile rate limits and refresh-token history, mobile notifications, full-regression OCR/change-event rows, generated sign-off archives, and restored setting metadata. Test teardown now removes its own records/files and restores setting value, owner, and timestamp exactly. No application behavior changed.

Backup characterization also exposed the empty-directory restore defect described above and a missing-commit risk for deployment trees without `.git`. Archive metadata now preserves the directory graph, extraction recreates it, and `--source-commit` provides a validated full-SHA fallback while rejecting malformed or Git-mismatched identities.

## Post-Change Acceptance

- Aggregate safety suite: passed twice consecutively with exact database and durable-file cleanup after each pass.
- Ordered routes: 264 passed.
- API v1: 31 operations passed static and unauthenticated HTTP envelope checks.
- Schema: 763 columns, 417 index rows, and 174 foreign keys matched exactly.
- Full web regression, mobile lifecycle, wristband workflow, stock invariants, backup archive, permissions, workflow, units, files/exports, modules, and frontend contracts passed in both aggregate runs.
- PHP syntax: all 426 checked PHP files passed.
- JavaScript syntax: all 38 checked JavaScript/MJS files passed with the selected Node runtime.
- Composer: validation passed with the existing no-license/exact-version warnings; audit found no known advisories.
- Flutter `3.44.9` / Dart `3.12.2`: dependency resolution passed, analysis reported no issues, and all 58 tests passed. Application version remains `1.3.3+10`.
- Hardened recovery: the final set passed complete archive validation and an independent restore with zero warnings.
- The staged secret/scope scan and push result are recorded in the delivery report for the commit containing this note.

## Known Gaps And Risks

- Physical-device Flutter checks, camera/scanner hardware, biometric prompts, Android signing, iOS/TestFlight signing, SMTP delivery, and production cron execution cannot be fully characterized in the isolated local harness.
- Browser screenshot smoke tests are not part of this no-UI-change phase; DOM/cascade registrations are locked, but visual pixel equivalence is not.
- Concurrency is characterized through row-lock markers, conflicts, duplicate-operation behavior, and transaction tests; there is no multi-process deadlock/load test.
- PHP 8.5 emits deprecations for omitted CSV escape parameters in wristband import parsing. Production PHP 8.3 is unaffected; the runtime code was intentionally not changed in this phase.
- The production scheduler was not changed in this phase. Before activating the hardened command, verify production has ZipArchive AES-256 support and configure the external private key/off-server destination outside Git.
- The request-time maintenance architecture, global function namespace, first-match route table, and CSS cascade remain refactor risks.

## Commit Identity

Commit message: `Establish inventory refactor safety baseline`.

The immutable commit hash is reported after creation and push. A file cannot contain its own Git object hash because changing the file changes that hash; resolve it with `git log -1 --format=%H -- docs/baseline/safety-baseline-2026-09-02.md`.
