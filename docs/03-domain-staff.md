# Staff Domain

`app/Domain/Staff/{Models,Services}`, `app/Http/Controllers/Api/StaffController.php` +
`StaffMediaController.php`, `app/Http/Requests/Staff/*`, `app/Http/Resources/Staff*.php`,
`app/Policies/StaffPolicy.php`.

This is the largest domain in the system and the one most actively developed (see
[flagged-issues-resolution.md](flagged-issues-resolution.md) for the most recent feature built on
top of it).

## Models

| Model | Table | Purpose |
|---|---|---|
| `Staff` | `staffs` | The canonical staff record. `mda_id`-scoped (`HasMdaScope`), soft-deletable. Fillable: `mda_id, staff_number, legacy_staff_id, legacy_master_staff_id, legacy_cno, legacy_psn, legacy_cno_psn, surname, first_name, middle_name, full_name, sex, date_of_birth, passport_path, passport_mime_type, status`. |
| `StaffPersonalDetail` | `staff_personal_details` | One-to-one biographical detail: `lga, state_of_origin, phone, email, address, marital_status, file_no`. |
| `StaffEmployment` | `staff_employments` | **Timeline**, not a single mutable row — `mda_id, department_id, station_id, location_name, cadre_id, rank_id, staff_category, initial_rank`, appointment/promotion/retirement dates, `employment_status`, `is_current`, `effective_from/to`. Only one row per staff has `is_current = true` at a time; every cadre/rank/department/station change closes the old row (`is_current = false`, `effective_to` set) and inserts a new one. |
| `StaffSalaryPlacement` | `staff_salary_placements` | Same timeline pattern as employment, but for pay: `salary_scale_id, level, step, basic_salary, gross_salary`, plus four **snapshot** columns (`basic_salary_snapshot, legacy_gross_salary_snapshot, calculated_gross_salary_snapshot, gross_difference_snapshot`) that freeze the calculation inputs/outputs at the time the placement was made, for audit/reconciliation against the legacy system's numbers. |
| `StaffQualification` | `staff_qualifications` | `qualification_type_id, qualification_name, highest_qualification_name, specialization, is_highest, source`. Only one row per staff has `is_highest = true`. |
| `StaffAllowanceAssignment` | `staff_allowance_assignments` | `allowance_type_id, is_eligible, source, effective_from/to`. Unique-ish key is `(staff_id, allowance_type_id, source)` — `source` distinguishes e.g. `legacy_import` vs `staff_management` so a manual override doesn't destroy the imported record. |
| `StaffStatusHistory` | `staff_status_histories` | Append-only log of `status` transitions (`reason`, `effective_from`, `metadata` JSON). |
| `StaffDocument` / `StaffDocumentPage` | `staff_documents` / `staff_document_pages` | A document is a container (`title, document_type, notes, uploaded_by`, optional `compiled_pdf_path`); pages are ordered, individually-stored image/PDF files. |
| `Cadre` | `cadres` | `salary_scale_id, department_id, name, legacy_department_name, status`; soft-deletable; `hasMany Rank`. |
| `Rank` | `ranks` | `cadre_id, salary_scale_id, name, level, status`; soft-deletable. |
| `QualificationType` | `qualification_types` | `code, name, status`. |
| `QualificationScaleCeiling` | `qualification_scale_ceilings` | `qualification_type_id, salary_scale_id, max_level` — caps how high a qualification allows someone to be placed on a given scale. |
| `SalaryScale` | `salary_scales` | `code, name, min_level, max_level, min_step, max_step, status` — e.g. GL, CH (CONHESS), CM (CONMESS), SG. |
| `SalaryStructureRate` | `salary_structure_rates` | The actual pay table: `(salary_scale_id, level, step) → basic_salary, legacy_gross_salary`, with `belongsToMany AllowanceType` through `SalaryStructureRateAllowance` (pivot carries `amount`). |
| `AllowanceType` | `allowance_types` | `code, name, status` — e.g. hazard, rural, call allowances. |
| `PromotionPolicy` | `promotion_policies` | `salary_scale_id, min_level, max_level, required_years, policy_type` — years-in-rank required before the next promotion is due. |

## Services

All under `app/Domain/Staff/Services`:

- **`StaffQueryService`** — `paginate()`/`applyFilters()` for the staff registry list. Supports
  `search` (name/staff number/legacy identifiers), `mda_id`, `department_id`, `station_id`,
  `cadre_id`, `rank_id`, `salary_scale_id`, `level`, `status`, and `retirement_state`
  (`active`/`retired`, checked against both `staff.status` and `employment.employment_status`).
- **`StaffUpdateService`** — the main write surface for staff records.
  - `updateStaff()` — core-record edit (name/DOB/status/personal detail); writes a
    `StaffStatusHistory` row if `status` actually changed.
  - `createEmploymentRecord()` — closes the current `StaffEmployment` and opens a new one.
  - `storeQualification()` — clears any prior `is_highest` flag, inserts the new qualification.
  - `storeStatusHistory()` — direct status change + history row, independent of `updateStaff`.
  - `resolveFlaggedIssues()` — added for the flagged-issues review workflow; applies any subset of
    date-of-birth/cadre/rank/qualification/allowance changes and marks the matching
    `LegacyStaffImportError` rows resolved. Full detail in
    [flagged-issues-resolution.md](flagged-issues-resolution.md).
- **`StaffAllowanceService`** — `syncAssignments()` upserts eligibility by
  `(staff_id, allowance_type_id, source)`, skipping no-op writes, then recomputes the current
  salary placement's snapshot. `effectiveAssignments()` returns the allowances actually in effect
  today (date-bounded, `staff_management`-sourced rows preferred over `legacy_import` ones when
  both exist for the same allowance type).
- **`StaffSalaryPlacementService`** — `createPlacement()`: closes the prior current placement,
  calls `SalaryCalculationService` with the staff's current eligible allowance codes, writes the
  new placement with all four snapshot columns populated.
- **`SalaryCalculationService`** — stateless calculator. `getRate(scaleCode, level, step)` looks up
  `SalaryStructureRate` (normalizing scale code aliases — e.g. `GRADELEVEL` → `GL`).
  `calculateGrossForPlacement(scaleCode, level, step, eligibleAllowanceCodes)` returns
  `{basic_salary, allowance_breakdown, total_allowances, calculated_gross, legacy_gross_salary, gross_difference}`.
  This is the single source of truth for "what should this person be paid" and is called from
  placement creation, allowance sync recomputation, and the legacy publication service.
- **`PromotionPolicyService`** — `getRequiredYears()` / `calculateNextPromotionDate()` /
  `isPromotionDue()`, driven by `PromotionPolicy` rows.
- **`QualificationCeilingService`** — `getMaxLevelFor()` / `canMoveToLevel()`, driven by
  `QualificationScaleCeiling` rows.
- **`RetirementPolicyService`** — `calculateRetirementByAge()` (DOB + 60 years),
  `calculateRetirementByService()` (date of first appointment + 35 years),
  `calculateExpectedRetirementDate()` (earlier of the two) — standard Nigerian civil-service
  retirement rules.
- **`StaffMediaService`** — passport photo storage (replaces any prior file), multi-page document
  storage, document deletion (cleans up all page files + compiled PDF), and
  `compileDocumentPdf()` (renders an image-only document's pages into one PDF via dompdf, base64
  embedding each page).
- **`StaffPublicationService`** — the landing point for the legacy import pipeline; see
  [04-domain-legacy-import.md](04-domain-legacy-import.md). Find-or-creates a `Staff` row by
  `(mda_id, staff_number)` and fans out into employment/placement/qualification/allowance/status-
  history writes plus an audit log entry tagged `source: legacy_staff_import`.

## HTTP API

`StaffController`:

| Method | Route | Purpose |
|---|---|---|
| GET | `/staff` | Paginated, filtered list (`StaffQueryService`). |
| GET | `/staff/{staff}` | Full detail (`StaffDetailResource`) — bio, current employment/placement, qualifications, allowances, status history, documents, import metadata, audit summary, salary summary. |
| PUT | `/staff/{staff}` | Core-record edit; MDA-membership double-checked against the *submitted* `mda_id`, not just the existing record. |
| PUT | `/staff/{staff}/allowances` | Allowance eligibility sync + recompute. |
| GET | `/staff/flagged-issues` | Staff with unresolved `cadre`/`rank`/`qualification`/`call_allowance` import warnings, MDA-scoped. |
| PUT | `/staff/{staff}/flagged-issues` | Resolve flagged issues (see dedicated doc). |
| GET | `/staff/options` | Reference data for every staff form: MDAs, departments, stations, cadres, ranks, salary scales, qualification types, allowance types, status list. |

`StaffMediaController`:

| Method | Route | Purpose |
|---|---|---|
| POST | `/staff/{staff}/passport` | Upload passport photo (image, ≤5MB). |
| GET | `/staff/{staff}/passport` | Stream the stored passport file. |
| POST | `/staff/{staff}/documents` | Upload a multi-page document (≤30 pages, ≤10MB each; optional PDF compile if all pages are images). |
| GET | `/staff/{staff}/documents/{document}/pages/{page}` | Stream a single page. |
| GET | `/staff/{staff}/documents/{document}/compiled-pdf` | Stream the compiled PDF, if one exists. |
| DELETE | `/staff/{staff}/documents/{document}` | Delete a document and all its page files. |

All of the above require `view-staff`/`update-staff` per `StaffPolicy` plus MDA membership.

## Authorization

`StaffPolicy` (uses `MdaScopedPolicy`):

- `viewAny` → `view-staff` permission only (MDA filtering happens at the query level, not here).
- `view`/`update`/`delete` → matching permission (`view-staff`/`update-staff`/`delete-staff`) **and**
  `canAccessMda($staff->mda_id)`.
- `create` → `create-staff` permission only.

## Salary calculation, end to end

1. A rate is looked up for `(salary_scale_code, level, step)` in `SalaryStructureRate`, with its
   allowance pivot rows preloaded.
2. The staff's currently-eligible allowance codes are gathered (from
   `StaffAllowanceService::effectiveAssignments()`).
3. `SalaryCalculationService::calculateGrossForPlacement()` sums only the rate's allowance amounts
   whose code is in that eligible set, on top of the rate's `basic_salary`, to get
   `calculated_gross`. The rate's own `legacy_gross_salary` is carried through unchanged for
   comparison, and `gross_difference = calculated_gross - legacy_gross_salary` is computed so HR
   can see exactly where the new calculation diverges from the old system's number.
4. Every time a `StaffSalaryPlacement` is (re)written — whether from a fresh placement, an
   allowance sync, or a legacy-import publish — these four numbers are frozen into the snapshot
   columns at that moment, so historical placements remain individually inspectable even after
   later recalculation logic changes.
