# Legacy Staff Import Pipeline & the Generic Approval Workflow

`app/Domain/Legacy/{Models,Services}`, `app/Domain/Approval/{Models,Services}`,
`app/Domain/Imports/*` (operational data import), `app/Http/Controllers/Api/LegacyStaffImportController.php`,
`app/Http/Controllers/Api/OperationalDataImportController.php`,
`app/Http/Controllers/Api/WorkflowActionController.php` (import-related actions),
`app/Policies/LegacyStaffImportPolicy.php` / `LegacyStaffImportRowPolicy.php`.

This is the most architecturally distinct part of the system — it's the bridge from the raw
legacy PHP/MySQL `staff_list` / `master_staff_list` tables (read through a separate `legacy` DB
connection) into canonical `Staff` records, with a human review-and-approval step in between.

## Pipeline narrative

Entry point: `LegacyStaffImportService::import(array $options)`, also invocable from the
`legacy:import-staff` console command or triggered indirectly via the operational-data-import
spreadsheet path. For each legacy row:

1. **Cross-reference** — if reading from `staff_list`, `LegacyStaffRowNormalizer::findMasterRow()`
   tries to find the matching `master_staff_list` row (by CNO+PSN, then CNO, then PSN, then
   name+DOB) to fill in gaps.
2. **Normalize** (`LegacyStaffRowNormalizer::normalize()`) — resolves every reference field (MDA,
   department, station, salary scale — with legacy alias mapping like `CONHESS` → `CH`,
   `GRADELEVEL` → `GL` — cadre, rank, qualification type), parses dates, computes expected
   retirement date and next-promotion date and flags them as warnings if they disagree with the
   legacy-supplied values, resolves allowance eligibility (shift/hazard/teaching/specialty/
   rural/domestic/call allowances — call-allowance resolution in particular has nontrivial logic
   keyed off cadre type + specialization + level + scale code), and computes a `dedupe_key`.
   Lookups are cached per-normalizer-instance to avoid repeat queries across rows.
   - If `$allowCreate` is true (only ever passed as `$publish` from the import service — see the
     regression notes in [flagged-issues-resolution.md](flagged-issues-resolution.md) for why this
     matters), an unresolvable cadre or rank name is auto-created rather than left unresolved,
     *after* the existing rank→cadre fallback-realignment logic has already had a chance to find
     a better match — auto-create is the last resort, not the first move, and is itself logged as
     a `cadre_auto_created`/`rank_auto_created` warning so it's reviewable.
3. **Validate** (`LegacyStaffRowValidator::validate()`) — adds blocking `error`-severity issues
   for missing staff identifier / MDA / full name, and non-blocking `warning`-severity issues for
   everything else unresolved (salary scale, department, station, cadre, rank, qualification,
   level/step, sex normalization failure, EDOR/next-promotion mismatches, unresolved call
   allowance).
4. **Identity match** (`LegacyStaffIdentityMatcher::match()`) — MDA-scoped lookup against the live
   `Staff` table in precedence order: `legacy_cno_psn` → `legacy_cno` → `legacy_psn` → exact
   `full_name + date_of_birth`. A match doesn't block the row, but adds a
   `matched_existing_staff` warning so a reviewer knows this row will update, not create.
5. **Stage** — the row is persisted as a `LegacyStaffImportRow` with status `invalid` (blocking
   errors present), `staged` (clean, awaiting review), or `ready_to_publish` (clean and
   `publish=true` was requested for this run); every issue becomes a `LegacyStaffImportError` row.
6. **Optional immediate publish** — if `publish=true` and the row has no blocking errors,
   `StaffPublicationService::publish()` runs immediately and the row flips to `published`.

Outside of an immediate `--publish` run, the normal path is: stage → human review/correction
(resolve mappings, ignore warnings, assign a manual staff number) → submit for approval → approve
→ publish, all batch-scoped.

## Models (`app/Domain/Legacy/Models`)

- **`LegacyStaffImportBatch`** — one import run. `source_database, source_table, status,
  started_at, completed_at, summary` (JSON). Status values observed across the codebase:
  `staging`/`staged` → `submitted` → `under_review` → `approved`/`rejected` → `publishing` →
  `published`/`partially_published`. `morphOne ApprovalWorkflow`, `hasMany rows/errors`,
  `hasMany publications` (`LegacyStaffImportPublication`, an audit record per publish run with a
  summary of created/updated/skipped counts).
- **`LegacyStaffImportRow`** — one staged staff record. Carries both the raw payload and the
  normalized payload (JSON columns), every resolved reference id+name pair, `matched_staff_id`
  (identity-match result), `published_staff_id` (once published), and `status`
  (`staged`/`invalid`/`ready_to_publish`/`published`).
- **`LegacyStaffImportError`** — one issue. `field, error_code, message, severity`
  (`error`/`warning`), plus resolution tracking: `resolved_at/resolved_by`, `ignored_at/ignored_by`,
  `resolution_notes`, `resolution_context` (JSON). **Unresolved, non-ignored errors with
  `severity = 'error'` block publication**; warnings never block, they're informational unless a
  reviewer chooses to act on them.

## Services (`app/Domain/Legacy/Services`)

| Service | Role |
|---|---|
| `LegacyStaffImportService` | Pipeline orchestrator — entry point described above. |
| `LegacyStaffRowNormalizer` | Field resolution/enrichment, cadre/rank auto-create, allowance eligibility, date math (depends on `LegacyDateParser`, `PromotionPolicyService`, `RetirementPolicyService`). |
| `LegacyStaffRowValidator` | Stateless semantic validation, error-vs-warning classification. |
| `LegacyStaffIdentityMatcher` | Duplicate-prevention lookup against live `Staff`. |
| `LegacyStaffImportPublicationService` | `publishBatch()` (bulk, skips already-published/blocked rows, requires an approved workflow) and `publishRow()` (single row) — both delegate the actual write to `StaffPublicationService` in the Staff domain. |
| `LegacyStaffImportApprovalService` | Bridges a batch to the generic `Approval` domain — `submitBatch()` (validates zero blocking errors and ≥1 publishable row, creates a single-step `ApprovalWorkflow` with `reviewer_role = 'Approval Officer'`), `approveBatch()`, `rejectBatch()` — each syncs `batch.status` to the workflow's resulting status. |
| `LegacyStaffImportQueryService` | All the list/filter/paginate logic behind the review UI — batch list, batch summary aggregation, row list with a long filter set, available issue codes for a batch's filter dropdown. |
| `LegacyStaffImportReviewService` | Standalone diagnostics (`review()`): status/severity/issue-code counts and a sample of issues for a batch, independent of the approval flow — more of a QA/console tool. |
| `LegacyStaffImportIssueResolutionService` | The actual remediation actions: `applyMapping()` (remap an unresolved reference — mda/department/station/cadre/rank/qualification_type — to a chosen target id, updates the row's snapshot + normalized payload, resolves the matching error), `ignoreWarning()`, `resolveIdentifier()` (manually assign a staff number to a row that got a provisional one). |

## The generic Approval domain

`app/Domain/Approval/Models`: **`ApprovalWorkflow`** (polymorphic — `subject_type`/`subject_id`
can point at any model; `workflow_type` is a free-text discriminator like
`legacy_staff_import_publication`; status `draft → submitted → under_review → approved/rejected`,
plus `locked`) and **`ApprovalStep`** (`step_no`, `reviewer_user_id` or `reviewer_role`, `status`,
`comment`, `acted_at/by`; `isActionableBy(User)` checks either the specific assignee or the role
match).

**`ApprovalWorkflowService`** is the only thing that mutates a workflow: `submit()` (creates/resets
a workflow with its ordered steps, all pending), `approveStep()` (finds the current pending step,
checks `isActionableBy`, advances to `under_review` if more steps remain or `approved` if that was
the last one), `reject()` (marks the current step rejected, workflow → `rejected`), `lockSubject()`
(workflow → `locked`, used right before executing whatever the approval was for). Every transition
is audit-logged.

This same service backs **Movement** and **Budget** workbook approvals too (see
[05-domain-movement-and-budget.md](05-domain-movement-and-budget.md)) — it's intentionally generic
so new approvable subjects don't need a bespoke state machine. The legacy-import-specific service
(`LegacyStaffImportApprovalService`) only exists to enforce import-specific preconditions
(no blocking errors, at least one publishable row) before delegating to the generic service.

## Operational Data Import (a related but separate feature)

`app/Domain/Imports/Services/OperationalDataImportService` + `OperationalDataImportController`.
This is a **spreadsheet upload** path for bulk reference-data maintenance, reusing the *same*
normalizer/validator/identity-matcher services as the legacy DB pipeline, but reading from an
uploaded `.xlsx`/`.xls`/`.csv` instead of the legacy MySQL connection. Supported `{type}` values:

- `stations`, `highest-qualifications`, `cadres`, `ranks` — straightforward upsert-by-natural-key
  imports of reference data (each requiring `import-staff` permission and, where relevant, an
  MDA-scoped uploader to only touch their own MDA).
- `staff-list` — creates a `LegacyStaffImportBatch` exactly like the console-driven path
  (status `staged`), just sourced from the spreadsheet instead of the legacy database connection.
  This means a batch created this way flows through the *same* review/approve/publish pipeline
  described above.

Endpoints: `POST /api/operational-imports/{type}` (upload + import) and
`GET /api/operational-imports/{type}/template` (download a column-header template for that type).

## HTTP API

| Method | Route | Purpose |
|---|---|---|
| GET | `/legacy-staff-imports` | Paginated batch list, filterable by status/source_table/date range, MDA-scoped. |
| GET | `/legacy-staff-imports/{batch}` | Batch detail: summary counts, paginated/filterable rows, and `can.*` flags (submit/approve/reject/publish) for the UI to gate its action buttons. |
| GET | `/legacy-staff-imports/{batch}/rows/{row}` | Single row detail + the reference-option lists needed to resolve a mapping. |
| POST | `/legacy-staff-imports/{batch}/rows/{row}/ignore-warning` | Mark a warning ignored. |
| POST | `/legacy-staff-imports/{batch}/rows/{row}/resolve-mapping` | Remap an unresolved reference field to a chosen target. |
| POST | `/legacy-staff-imports/{batch}/rows/{row}/resolve-identifier` | Manually assign a staff number. |
| POST | `/legacy-staff-imports/{batch}/rows/{row}/publish` | Publish a single row (requires an approved batch). |
| POST | `/legacy-staff-imports/{batch}/submit` | Submit batch for approval (`WorkflowActionController::importSubmit`). |
| POST | `/legacy-staff-imports/{batch}/approve` | Approve the current step. |
| POST | `/legacy-staff-imports/{batch}/reject` | Reject (comment required). |
| POST | `/legacy-staff-imports/{batch}/publish` | Bulk-publish — dispatched as an async job (`PublishLegacyStaffImportBatch`), returns 202; the controller does not publish synchronously. |
| POST | `/operational-imports/{type}` | Spreadsheet import. |
| GET | `/operational-imports/{type}/template` | Template download. |

## Authorization

`LegacyStaffImportPolicy` (batch-level) and `LegacyStaffImportRowPolicy` (row-level, uses
`MdaScopedPolicy`) both gate on a Spatie permission **and** MDA membership
(`canAccessBatch()`/`canAccessMda()`). Publish specifically branches on global vs. own-MDA
permission: `publish-staff-imports` (global) vs. `publish-own-mda-staff-imports` (MDA Admin), and
additionally requires the batch's `ApprovalWorkflow` to already be `approved` — there is no way to
publish an unapproved batch through the API regardless of permission.

## Console command

`legacy:import-staff` — `{--dry-run} {--limit=100} {--mda=} {--include-retired} {--only-retired}
{--source=staff_list} {--publish}`. The operator-facing entry point for running the pipeline
directly against the legacy database connection outside the spreadsheet/API path.
