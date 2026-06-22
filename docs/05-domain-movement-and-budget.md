# Movement & Budget Workbooks, and the Audit Log

`app/Domain/Movement/*`, `app/Domain/Budget/*`, `app/Domain/Audit/*`,
`app/Http/Controllers/Api/MovementWorkbookController.php`,
`app/Http/Controllers/Api/BudgetWorkbookController.php`,
`app/Http/Controllers/Api/WorkflowActionController.php` (movement/budget actions),
`app/Policies/MovementWorkbookPolicy.php`, `BudgetWorkbookPolicy.php`.

## Movement workbooks

A **movement workbook** is a per-MDA, per-year snapshot of every staff member's current vs.
proposed salary placement — the annual exercise of working out who is due a promotion/step
increment, who's retiring, and what the resulting payroll cost looks like, before that gets
carried into a budget.

- **`MovementWorkbook`** — `mda_id, name, year, budget_year, budget_minimum_step` (the floor on
  how many steps a promotion can move someone), `status`, `generated_by/reviewed_by/approved_by`
  + matching timestamps, `summary` (JSON). `morphOne ApprovalWorkflow`. `hasMany` `MovementLine`
  and `MovementSummary`.
- **`MovementLine`** — one staff member's row: links to their current `StaffEmployment` /
  `StaffSalaryPlacement` / current `SalaryScale`, plus a *proposed* `SalaryScale`/level/step,
  `selection_state`, `eligibility_status` (e.g. "due", "blocked_by_policy"), `retirement_status`
  (e.g. "retiring", "retired"), `current_amounts`/`proposed_amounts` (JSON breakdowns, same shape
  as `SalaryCalculationService`'s output), and a `decision_trace` JSON field recording why the
  line landed where it did.
- **`MovementSummary`** — one row per `(department_id, salary_scale_id, level)` for the workbook:
  `staff_count, due_count, retiring_count, retired_count, blocked_count, current_gross_total,
  proposed_gross_total, variance_total`. This is what the department-level summary tab in the UI
  renders directly, rather than aggregating `MovementLine` on the fly.

**Status lifecycle**: `draft → reviewed → approved → locked`, with `reject` available from
`reviewed`/`approved` (→ `rejected`) and `reopen` available from `reviewed`/`approved`/`locked`/
`rejected` (→ `reopened`, which also resets the underlying `ApprovalWorkflow`'s steps back to
pending). `MovementWorkbookWorkflowService` owns every one of these transitions
(`markReviewed()`, `approve()`, `reject()`, `lock()`, `reopen()`) and routes through the same
`ApprovalWorkflowService` used by the legacy-import approval flow — each transition also writes an
`AuditLog` entry tagged `source: movement_workflow.<transition>`.

`MovementSummaryService::regenerate()` rebuilds every `MovementSummary` row for a workbook from
its `MovementLine`s (chunked 200 at a time), and is presumably called after lines are generated or
edited — i.e. the summary table is a derived cache, not a live aggregate.

**Export**: `GET /movement-workbooks/{workbook}/summary-export` and `.../detail-export` both
generate Excel files (`MovementSummaryExport`, `MovementDetailExport`), optionally filtered to one
department, and each export is logged via `AuditLogService::logExport()`.

## Budget workbooks

A **budget workbook** is the fiscal output of a movement workbook — the same staffing numbers
re-expressed as a department-level payroll budget for a year.

- **`BudgetWorkbook`** — `mda_id, movement_workbook_id` (its source), `year, status,
  generated_by/approved_by` + timestamps, `summary` (JSON). `morphOne ApprovalWorkflow`,
  `hasMany BudgetLine`.
- **`BudgetLine`** — one row per `(department_id, salary_scale_id, level)`:
  `staff_count, retiring_count, current_gross_total, proposed_gross_total, variance_total`.

**Status lifecycle**: `draft → submitted → approved → locked`, with `reject` from
`submitted`/`approved` and `reopen` from any of `submitted`/`approved`/`locked`/`rejected`.
`BudgetWorkbookWorkflowService` mirrors the movement service exactly (`submit()`, `approve()`,
`reject()`, `lock()`, `reopen()`), audit-tagged `budget_workflow.<transition>`.

Both workbook types deliberately share the same shape (a generated draft → human review →
approval → lock pipeline, backed by the same generic `ApprovalWorkflow`) — if you understand one,
you understand the other; the difference is just what the rows represent (staff movements vs.
department-level money) and budget's extra `submitted` step before `approved`.

## HTTP API

| Method | Route | Purpose |
|---|---|---|
| GET | `/movement-workbooks` | List, MDA-scoped. |
| POST | `/movement-workbooks` | Generate a new workbook for an MDA/year (via a generation service that builds the initial `MovementLine` set). |
| GET | `/movement-workbooks/{workbook}` | Detail — lines, summaries, approval workflow state. |
| GET | `/movement-workbooks/{workbook}/summary-export` | Excel summary export. |
| GET | `/movement-workbooks/{workbook}/detail-export` | Excel per-staff detail export. |
| POST | `/movement-workbooks/{workbook}/review` \| `/approve` \| `/reject` \| `/lock` \| `/reopen` | State transitions, via `WorkflowActionController::movement*`. |
| GET | `/budget-workbooks` | List, MDA-scoped. |
| POST | `/budget-workbooks` | Generate a budget workbook from a movement workbook. |
| GET | `/budget-workbooks/{budgetWorkbook}` | Detail — lines, approval workflow state. |
| POST | `/budget-workbooks/{budgetWorkbook}/submit` \| `/approve` \| `/reject` \| `/lock` \| `/reopen` | State transitions, via `WorkflowActionController::budget*`. |

Authorization for both follows the same pattern as everywhere else: a Spatie permission
(`create-movement-sheets`/`approve-movement-sheets`, `create-budgets`/`approve-budgets`) combined
with `MdaScopedPolicy::canAccessMda()`.

## Audit domain

`app/Domain/Audit/Models/AuditLog` + `app/Services/AuditLogService` (note: this lives at
`app/Services`, not under `app/Domain/Audit` — the model is domained, the service isn't).

**`AuditLog`** fields: `actor_user_id`, `event_code` (free-text, e.g. `created`, `updated`,
`deleted`, `report.exported`, or domain-specific strings like `staff.allowances.synced`),
`auditable_type`/`auditable_id` (nullable — not every audit entry is tied to one model row),
`before_values`/`after_values` (JSON), `context` (JSON — almost always includes a `source` key
identifying which code path wrote the entry), `ip_address`, `user_agent`, `occurred_at`.

**`AuditLogService`** is injected into nearly every domain service that mutates state
(`StaffUpdateService`, `StaffAllowanceService`, `StaffMediaService`, `StaffPublicationService`,
`MovementWorkbookWorkflowService`, `BudgetWorkbookWorkflowService`,
`LegacyStaffImportIssueResolutionService`, `ApprovalWorkflowService`, …) via four entry points:

- `log($eventCode, $auditable, $before, $after, $context)` — the general-purpose call.
- `logCreated($model, $context)` — empty `before`, model's current state as `after`.
- `logUpdated($model, $before, $context)` — `before` supplied by the caller (usually a
  pre-mutation `->toArray()` snapshot), `after` taken fresh from the model.
- `logDeleted($auditable, $before, $context)` — `before` supplied, empty `after`.

There is no generic "audit log viewer" endpoint wired up yet (the `view-audit-logs` permission
exists in the seeder, but no controller currently exposes a list of `AuditLog` rows) — the only
place audit data surfaces today is the `audit_summary` block inside `StaffDetailResource` (latest
5 events + a total count for that one staff record). A general audit log browser is implied by
Phase F of `execution_board.md` but not yet built.
