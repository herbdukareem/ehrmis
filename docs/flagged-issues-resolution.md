# Staff Flagged-Issues Resolution

This document covers two pieces of work completed together because the second depends on the
first being correct:

1. A fix for regressions introduced by an in-progress change to the legacy staff import
   normalizer (auto-creating cadre/rank during import).
2. A new end-to-end workflow that lets an officer resolve flagged staff data-quality issues
   (cadre, rank, highest qualification, date of birth, allowances) directly from the staff
   registry, without going back through the import tooling.

## 1. Background

A prior session had left uncommitted, untested changes across:

- `app/Domain/Legacy/Services/LegacyStaffImportService.php`
- `app/Domain/Legacy/Services/LegacyStaffRowNormalizer.php`
- `app/Http/Controllers/Api/StaffController.php`
- `routes/api.php`
- `resources/css/app.css`
- `resources/js/spa/views/StaffIndexView.vue`

The intent of that work was:

- During import **publish**, auto-create a `Cadre`/`Rank` row when the legacy cadre/rank name
  can't be resolved against existing data, instead of leaving the row flagged `missing_cadre` /
  `missing_rank`. The auto-create action itself is recorded as a `cadre_auto_created` /
  `rank_auto_created` warning so it can be reviewed later.
- Surface staff whose published records still carry unresolved `cadre`, `rank`, `qualification`,
  or `call_allowance` warnings, via a new `GET /api/staff/flagged-issues` endpoint and a modal
  shown on the staff registry page.

Running the existing test suite against that working tree showed **4 regressions** in
`tests/Feature/LegacyStaffImportServiceTest.php`:

- `test_invalid_dates_missing_references_and_mismatches_create_warnings`
- `test_station_aliases_are_resolved_without_new_missing_station_warnings`
- `test_rank_fallback_aligns_cadre_when_unique_scale_level_match_exists`
- `test_staff_identity_matching_prevents_duplicate_records`

### Root causes

1. **Auto-create fired too eagerly.** `normalize()` was called with
   `! $dryRun` as the `$allowCreate` flag. Staging-only runs (`dry_run = false`, `publish = false`,
   i.e. the default "review before publish" mode) are *not* dry runs, so auto-create was firing
   even when nothing was actually being published — silently creating cadre/rank rows during a
   review pass and erasing the `missing_cadre` / `missing_rank` warnings those tests expect to see.

   **Fix:** gate auto-create on `$publish` instead of `! $dryRun`, in
   `LegacyStaffImportService::import()`:

   ```php
   $normalized = $this->normalizer->normalize($legacyRow, $source, $masterRow, $publish);
   ```

2. **Auto-create ran before the existing rank/cadre fallback logic got a chance to run.**
   `LegacyStaffRowNormalizer::normalize()` resolves cadre, then rank, then (pre-existing behaviour)
   re-aligns the cadre to whatever cadre the resolved rank actually belongs to, when there's a
   unique scale+level match elsewhere in the system. The WIP change auto-created a cadre
   immediately after the first cadre lookup failed — before that fallback realignment had a
   chance to find the right cadre via the rank. This meant a row that *should* have been resolved
   through the existing rank-based fallback was instead getting a brand new, duplicate cadre.

   **Fix:** moved the `createCadre()` / `createRank()` calls to *after* the
   `$cadre = $rank->cadre;` realignment block, so auto-create is only a last resort once both the
   direct lookup and the fallback lookup have failed.

3. **An unrelated change to staff-number/dedupe-key precedence broke identity matching.** The WIP
   diff changed:

   ```php
   $staffNumber = $legacyCnoPsn ?? $legacyCno ?? $legacyPsn ?? $this->makeProvisionalStaffNumber(...);
   ```

   into a duplicate-dependent ternary that preferred `legacyCno` over `legacyCnoPsn` for
   non-duplicate rows. This isn't related to the cadre/rank/flagged-issues work and had no test
   coverage justifying it, so it was reverted to the original precedence
   (`legacyCnoPsn ?? legacyCno ?? legacyPsn`).

4. One test's expectation was **legitimately outdated**, not a bug. With auto-create now correctly
   gated and ordered, `test_rank_fallback_aligns_cadre_when_unique_scale_level_match_exists`
   publishes (`publish = true`), and the *base* fixture data includes a row with an unresolvable
   `UNKNOWN CADRE` / `UNKNOWN RANK` (used elsewhere to test the warning path with
   `publish = false`). Now that publishing legitimately auto-creates that row's cadre/rank, the
   `missing_rank` counter correctly drops from `1` to `0`, and `cadre_auto_created` /
   `rank_auto_created` correctly become `1`. The test assertion was updated to reflect this.

### Verification

- `tests/Feature/LegacyStaffImportServiceTest.php` — 12/12 passing.
- Full suite — 123 passing; the only 2 failures (`AuthenticationTest`) are pre-existing and fail
  identically on the unmodified baseline (`git stash` + run), so they're unrelated to this work.

## 2. Flagged-Issues Resolution Workflow

### What it does

On the staff registry page (`/staff`), if any staff have unresolved `cadre`, `rank`,
`qualification`, or `call_allowance` warnings attached to their import history, a "Needs review"
modal opens automatically listing them. Each entry now has an **Edit** button that:

1. Closes the flagged-issues modal.
2. Opens a second modal pre-filled with that staff member's current cadre, rank, highest
   qualification, date of birth, and allowance eligibility.
3. On save, applies the changes to the live staff record and marks the corresponding import
   warnings resolved.
4. Re-fetches the flagged-issues list — a staff member whose flagged fields are now resolved
   disappears from the list automatically. If other staff remain flagged, the review modal
   reopens; otherwise it stays closed.

### Backend

**New endpoint:** `PUT /api/staff/{staff}/flagged-issues` → `StaffController::resolveFlaggedIssue`
(route name `api.staff.flagged-issues.resolve`).

**Request validation** — `app/Http/Requests/Staff/ResolveStaffFlaggedIssueRequest.php`:

| Field | Rules |
|---|---|
| `date_of_birth` | nullable, date |
| `cadre_id` | nullable, integer, must exist in `cadres` |
| `rank_id` | nullable, integer, must exist in `ranks` |
| `qualification_type_id` | nullable, integer, must exist in `qualification_types` |
| `allowances` | nullable array of `{allowance_type_id, is_eligible}` |

Authorization reuses the existing `StaffPolicy::update` ability (same MDA-scoping rule as the
core staff update endpoint), so a user can only resolve issues for staff within their MDA unless
they have global access.

**New service method:** `StaffUpdateService::resolveFlaggedIssues(Staff $staff, array $updates, User $user): Staff`

For each field actually provided in `$updates`:

- `date_of_birth` → written directly onto the `Staff` row.
- `cadre_id` / `rank_id` → if either differs from the staff's current employment, a **new**
  `StaffEmployment` row is created via the existing `createEmploymentRecord()` (which closes the
  prior `is_current` row), carrying over every other employment attribute (department, station,
  appointment dates, etc.) unchanged. This preserves the existing "employment is a timeline, not
  an overwritten field" pattern used elsewhere in the module.
- `qualification_type_id` → a new `StaffQualification` row is created via the existing
  `storeQualification()`, marked `is_highest = true` (clearing the flag on any prior
  qualification), `source = 'staff_management'`.
- `allowances` → delegated to the existing `StaffAllowanceService::syncAssignments()`, the same
  service the standalone allowances editor on the staff detail page uses. This also recomputes the
  staff's current gross salary.

After applying whichever of the above were provided, it resolves the matching
`LegacyStaffImportError` rows: any unresolved, non-ignored error on that staff's import rows whose
`field` is `cadre`, `rank`, `qualification`, or `call_allowance` — limited to the fields that were
actually part of this update — gets `resolved_at`, `resolved_by`, and a resolution note set. This
is what makes the staff member drop out of `GET /api/staff/flagged-issues` afterward, since that
endpoint's query is `whereHas('importRows.errors', fn ($q) => $q->whereNull('resolved_at')->whereNull('ignored_at')->whereIn('field', $flaggedFields))`.

Everything runs inside a single DB transaction.

**`/api/staff/options` was extended** with two new lists the edit modal needs that weren't
previously exposed:

- `qualification_types` — `{id, code, name}`
- `allowance_types` — `{id, code, name}`

(`cadres` and `ranks` were already present and are reused as-is.)

### Frontend

All changes are in `resources/js/spa/views/StaffIndexView.vue`, no new component file was added
since the modal is small and tightly coupled to the flagged-issues list it's launched from.

- `openEditModal(staffSummary)` — closes the flagged modal, fetches the full staff detail
  (`GET /staff/{id}`) so the form can be pre-filled with current values, and opens the edit modal.
- `saveIssueResolution()` — sends only the fields the form holds to
  `PUT /staff/{id}/flagged-issues` (allowances are always sent in full since the endpoint expects
  the complete desired eligibility set, matching the existing allowances-editor convention), then
  reloads the flagged-issues list and the staff table, and decides whether to reopen the review
  modal based on whether anything is still flagged.
- `closeEditModal()` — used for the explicit "Close" button / overlay click; reopens the flagged
  modal if there's still something to review, otherwise leaves both modals closed.
- New markup: an edit button per flagged-staff row (`.civic-flagged-staff-row`), and a second
  overlay modal with date-of-birth/cadre/rank/qualification fields plus allowance checkboxes,
  styled with two small additions to `resources/css/app.css`.

### Tests

Added to `tests/Feature/StaffModuleTest.php`, reusing the module's existing fixture (which already
seeded a `call_allowance_unresolved` warning on `staffA`):

- `test_flagged_issues_lists_staff_with_unresolved_warnings` — confirms the existing
  `GET /api/staff/flagged-issues` endpoint surfaces the seeded warning.
- `test_resolving_flagged_issues_updates_staff_and_removes_it_from_the_list` — exercises the full
  loop: submits a cadre, rank, qualification, date-of-birth, and allowance change in one request,
  asserts each landed in the database (`staff_employments`, `staff_qualifications`,
  `staff_allowance_assignments`), asserts the `legacy_staff_import_errors` row was marked resolved
  by the acting user, then re-calls `GET /api/staff/flagged-issues` and asserts the list is now
  empty.

### Files touched

```
app/Domain/Legacy/Services/LegacyStaffImportService.php       fix: gate auto-create on $publish
app/Domain/Legacy/Services/LegacyStaffRowNormalizer.php        fix: reorder auto-create after fallback; revert staff_number precedence
app/Domain/Staff/Services/StaffUpdateService.php               new: resolveFlaggedIssues()
app/Http/Controllers/Api/StaffController.php                   new: resolveFlaggedIssue(); options() gains qualification_types/allowance_types
app/Http/Requests/Staff/ResolveStaffFlaggedIssueRequest.php     new
routes/api.php                                                 new: PUT /staff/{staff}/flagged-issues
resources/js/spa/views/StaffIndexView.vue                       new: edit modal + wiring
resources/css/app.css                                           new: .civic-flagged-staff-row
tests/Feature/LegacyStaffImportServiceTest.php                  fix: updated one outdated assertion
tests/Feature/StaffModuleTest.php                               new: 2 tests
```

### Known limitations / follow-ups

- The edit modal only offers cadre/rank options already returned by `/staff/options`, which are
  scoped to departments the acting user can see. An MDA-scoped officer resolving a staff member in
  their own MDA will always have the right options; a global-access user editing across MDAs will
  see the full list, which is correct but unfiltered by the staff member's own MDA/department —
  worth tightening if cross-MDA edits become common.
- Resolving an issue does not currently re-run `SalaryCalculationService` placement-snapshot
  recompute beyond what `StaffAllowanceService::syncAssignments()` already does for allowances; a
  cadre/rank change alone does not touch the salary placement record. If cadre/rank changes are
  meant to also re-price salary, that's a separate, not-yet-requested change.
