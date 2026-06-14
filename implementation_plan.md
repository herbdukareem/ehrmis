**Database-First Implementation Plan**

This plan assumes the new eHRMIS module lives inside the existing multi-tenant Laravel 12 monolith, so every organization-owned table should carry `tenant_id`, and MDA-scoped data must never cross tenant boundaries. It is based on the inspected legacy code/schema under `legacy-system/`, where hidden filters, page-load mutations, and overloaded tables are the main risks we are eliminating.

**1. Backend Foundation**
| Area | Recommendation |
|---|---|
| Framework | Laravel 12, PHP 8.3+, MySQL 8/PostgreSQL 16 |
| Auth | Laravel Vue starter kit with session auth for web; add Sanctum only if mobile/API tokens are needed later |
| Roles/permissions | `spatie/laravel-permission`; add `mda_id` awareness in policies, not in permission names |
| Excel/CSV | `SpartnerNL/Laravel-Excel` for imports/exports |
| PDF | `barryvdh/laravel-dompdf` only for print-perfect statutory exports; prefer Excel/HTML for analytical reports |
| Audit | `spatie/laravel-activitylog` plus a first-party `audit_logs` table for domain-level before/after snapshots |
| Queue | Redis queue + Horizon; batch imports, report exports, recalculation jobs, snapshot generation |
| Storage | Laravel filesystem with `local` for private uploads/exports and optional S3 later; all staff docs private |
| DB conventions | UUID or ULID public IDs, bigint PKs internally, `tenant_id` on tenant-owned tables, soft deletes only where business-safe |
| Enums/VOs | PHP backed enums for status/workflow fields; DTOs for import rows, movement decisions, budget projections |
| Testing | Pest or PHPUnit, factories for every core model, feature tests for policies/scoping, unit tests for policy services |

Recommended package sources: Laravel starter kits, queues, Horizon, storage, testing, plus package repos for permission/activitylog/excel/pdf. Links at the end.

**2. Proposed Laravel Module Structure**
Use a modular monolith under `app/Domain` plus thin HTTP layers under `app/Http`.

```text
app/
  Domain/
    Auth/
    Organization/
    Staff/
    StaffImport/
    Salary/
    Allowance/
    Promotion/
    Retirement/
    Posting/
    Movement/
    Budget/
    Report/
    Approval/
    Audit/
    Setting/
  Http/
    Controllers/
    Requests/
    Resources/
  Policies/
  Jobs/
  DTOs/
```

| Module | Core contents |
|---|---|
| Auth | `User`, auth controllers, login/logout actions, profile service, auth tests |
| Organization | `Mda`, `Department`, `Station`, `Location`; resolution services; org policies/tests |
| Staff | `Staff`, `StaffPersonalDetail`, `StaffEmployment`, `StaffSalaryPlacement`, `StaffQualification`, `StaffStatusHistory`, `StaffDocument`; identity/employment services; staff requests/policies/tests |
| StaffImport | import batch/row models, parse/publish jobs, correction actions, approval integration, import tests |
| Salary | `SalaryScale`, `SalaryStructureRate`, `SalaryStructureRateAllowance`; placement/calculation services/tests |
| Allowance | `AllowanceType`, staff assignment model, dynamic allowance amount mapping/tests |
| Promotion | promotion policies, promotion reviews, eligibility service/tests |
| Retirement | retirement policies, retirement reviews, retirement calculator/tests |
| Posting | posting request model, approval actions, posting service/tests |
| Movement | workbook/line/summary models, generation/review/lock actions, movement tests |
| Budget | cycle/workbook/line models, generation services, budget tests |
| Report | registry, query classes, DTOs, export jobs, snapshot models/tests |
| Approval | workflow/step models, workflow service, reviewer assignment/tests |
| Audit | audit log model, log service, change trackers/tests |
| Setting | `SystemSetting`, policy toggles, import templates, report config/tests |

**3. Database Schema Design**
Every table below should include `id`, `tenant_id` where tenant-owned, timestamps, and standard FK indexes unless stated otherwise.

| Table | Purpose / Important columns / Constraints / Legacy mapping |
|---|---|
| `mdas` | MDA master. `tenant_id`, `code`, `name`, `status`. Unique: `tenant_id+code`, `tenant_id+name`. Maps legacy `mda` usage in `staff_list`, users. |
| `departments` | Department master under MDA. `mda_id`, `code`, `name`. Unique: `mda_id+name`. Maps legacy `departments`. |
| `stations` | Posting station/facility. `mda_id`, `name`, `code`. Unique: `mda_id+name`. Maps legacy station names without forcing one station into a single department. |
| `locations` | Geopolitical/locality master. `state`, `lga`, `town`, `is_urban_center`. Index `lga`. Maps free-text legacy locations. |
| `users` | App users. Add `mda_id nullable`, `is_platform_admin`, `status`. Index `tenant_id,mda_id`. Replaces legacy `users`. |
| `roles`, `permissions`, pivots | Spatie defaults. Add team/tenant strategy if needed. |
| `staff` | Canonical staff identity. `tenant_id`, `mda_id`, `staff_number`, `payroll_number`, `legacy_cno`, `legacy_psn`, `full_name`, `surname`, `first_name`, `middle_name`, `sex`, `date_of_birth`, `status`. Unique: `tenant_id+staff_number`, partial/conditional uniqueness for payroll identifiers. Maps identity portions of `staff_list` and `master_staff_list`. |
| `staff_personal_details` | Demographics/contact. `staff_id`, `lga`, `state_of_origin`, `phone`, `email`, `address`, `marital_status`. Unique `staff_id`. |
| `staff_employments` | Employment placement/history. `staff_id`, `mda_id`, `department_id`, `station_id`, `location_id`, `cadre_id`, `rank_id`, `staff_category`, `date_first_appointment`, `date_last_promotion`, `date_confirmed`, `employment_status`, `is_current`, `effective_from`, `effective_to`. Index `staff_id,is_current`. Replaces overloaded operational fields in `staff_list`. |
| `staff_salary_placements` | Salary-grade history. `staff_id`, `salary_scale_id`, `level`, `step`, `basic_salary`, `gross_salary`, `effective_from`, `effective_to`, `source`, `is_current`. Index `staff_id,is_current`. Replaces `salary_scale`, `level`, `step`, `initial_level`, `initial_step`. |
| `staff_qualifications` | Qualification history. `staff_id`, `qualification_type_id`, `specialization`, `awarded_at`, `is_highest`, `source`. Index `staff_id,is_highest`. Maps `highest_qualification`, `qualification`, `specialization`. |
| `staff_allowance_assignments` | Explicit allowance eligibility/override. `staff_id`, `allowance_type_id`, `eligibility_source`, `is_active`, `override_amount nullable`, `effective_from`, `effective_to`. Unique active assignment by `staff_id+allowance_type_id+effective_from`. Replaces legacy yes/no flags. |
| `staff_status_histories` | Status timeline. `staff_id`, `status_type`, `reason`, `effective_from`, `effective_to`, `metadata json`. Tracks active, retired, posted, transferred, suspended. |
| `staff_documents` | Private docs. `staff_id`, `document_type`, `disk`, `path`, `original_name`, `mime_type`, `uploaded_by`. |
| `staff_import_batches` | Upload unit. `mda_id`, `uploaded_by`, `file_name`, `file_hash`, `status`, `template_version`, `row_count`, `published_at`. |
| `staff_import_rows` | Raw parsed rows. `batch_id`, `row_number`, `raw_payload json`, `normalized_payload json`, `dedupe_key`, `status`, `proposed_staff_id nullable`. Unique `batch_id+row_number`. |
| `staff_import_row_errors` | Validation issues. `row_id`, `field`, `error_code`, `message`, `severity`. |
| `staff_import_corrections` | Manual fixes. `row_id`, `field`, `old_value`, `new_value`, `reason`, `corrected_by`. |
| `staff_import_publications` | Immutable publish record. `batch_id`, `published_by`, `published_at`, `summary json`, `rollback_reference nullable`. |
| `salary_scales` | Scale master. `code` like GL/CH/CM/SG, `name`, `sort_order`. Unique `code`. Maps distinct scales from legacy. |
| `salary_structure_rates` | Pay table snapshot/reference. `salary_scale_id`, `level`, `step`, `basic_salary`, `legacy_gross_salary`, `status`, `effective_from`, `effective_to`. Unique `salary_scale_id+level+step`. Maps `staff_salary` without copying allowance columns directly. |
| `allowance_types` | Allowance catalog. `code`, `name`, `description`, `status`. Unique `code`. Includes `rural`, `teaching`, `call_doctor`, `call_pharm_lab`, `call_opt_odd`, `call_nurse_others`, `shift`, `specialty`, `hazard`, plus future codes such as `domestic`, `professional`, `responsibility`, `other`. |
| `salary_structure_rate_allowances` | Dynamic allowance amounts available for each salary rate. `salary_structure_rate_id`, `allowance_type_id`, `amount`, `status`. Unique `salary_structure_rate_id+allowance_type_id`. |
| `cadres` | Cadre master. `code`, `name`, `department_id nullable`, `salary_scale_id`, `min_level_id nullable`, `max_level_id nullable`. Unique `name`. Maps `initial_cadre/cadre`. |
| `ranks` | Rank master. `cadre_id`, `salary_scale_id`, `level`, `name`. Unique `cadre_id+name+level`. Maps `tbl_rank`. |
| `qualification_types` | Qualification catalog. `code`, `name`, `category`. Unique `code`. |
| `qualification_scale_ceilings` | Max level by qualification+scale. `qualification_type_id`, `salary_scale_id`, `max_level`. Unique `qualification_type_id+salary_scale_id`. Maps `certificate_bar`. |
| `promotion_policies` | Promotion timing/rules. `salary_scale_id`, `min_level`, `max_level`, `required_years`, `policy_type`, `description`, `status`. Maps `promotion_years`. |
| `retirement_policies` | Retirement rules. `rule_code`, `retire_by_age`, `retire_by_service_years`, `effective_from`, `effective_to`, `rule_json`. Legacy EDOR logic becomes explicit here. |
| `posting_requests` | Posting/transfer workflow. `staff_id`, `from_station_id`, `to_station_id`, `requested_by`, `reason`, `status`, `approved_by`, `approved_at`, `comment`. Maps `tbl_staff_postings`. |
| `promotion_reviews` | Manual review for promotions. `staff_id`, `review_year`, `current_placement_id`, `proposed_level_id`, `proposed_step_id`, `promotion_type`, `decision`, `notes`. |
| `retirement_reviews` | Manual review for retirement. `staff_id`, `review_year`, `computed_retirement_date`, `computed_reason`, `decision`, `notes`. |
| `approval_workflows` | Generic workflow header. `workflow_type`, `subject_type`, `subject_id`, `status`, `submitted_by`, `submitted_at`, `approved_at`. |
| `approval_steps` | Ordered reviewer steps. `workflow_id`, `step_no`, `reviewer_user_id nullable`, `reviewer_role nullable`, `status`, `comment`, `acted_at`. |
| `movement_workbooks` | Movement run header. `mda_id`, `year`, `budget_year`, `budget_step_id`, `status`, `generated_by`, `locked_at`. Unique `mda_id+year` when active. Maps `movement_sheet_workbook`. |
| `movement_lines` | Staff-level movement snapshot. `workbook_id`, `staff_id`, `current_employment_id`, `current_salary_placement_id`, `selection_state`, `eligibility_status`, `retirement_status`, `retirement_month nullable`, `promotion_type`, `current_level_id`, `proposed_level_id nullable`, `current_step_id`, `proposed_step_id nullable`, `current_amounts json`, `proposed_amounts json`, `decision_trace json`. Unique `workbook_id+staff_id`. Maps `movement_sheet_details`. |
| `movement_line_adjustments` | Specialty/manual adjustments. `movement_line_id`, `adjustment_type`, `amount`, `reason`, `created_by`. |
| `movement_summaries` | Department/scale/level snapshot totals. `workbook_id`, `department_id`, `salary_scale_id`, `salary_level_id`, counts and totals for actual/proposed Jan-Jun/Jan-Dec. Unique `workbook_id+department_id+scale+level`. Maps `movement_sheet`. |
| `budget_cycles` | Budget year master. `mda_id`, `year`, `status`, `opened_at`, `closed_at`. Unique `mda_id+year`. |
| `budget_workbooks` | Budget run header linked to approved movement workbook. `budget_cycle_id`, `movement_workbook_id`, `status`, `approved_snapshot_at`, `generated_by`. Maps `budgets` conceptually. |
| `budget_lines` | Main recurrent budget lines by dept/scale/level. `workbook_id`, `department_id`, `salary_scale_id`, `salary_level_id`, `approved_count`, `actual_count_jan_june`, `required_count`, `approved_estimate`, `actual_estimate_jan_june`, `proposed_estimate`. Unique `workbook_id+department_id+scale+level`. |
| `budget_expenditure_lines` | Detailed actual/proposed amounts by component. `workbook_id`, `department_id`, `salary_scale_id`, `salary_level_id`, `component_code`, `amount_type`, `amount`. Use instead of reviving ambiguous `budget_details`. |
| `budget_strength_lines` | Staff strength outputs. `workbook_id`, `department_id`, `salary_scale_id`, `salary_level_id`, `sex`, `count`. |
| `budget_qualification_distribution_lines` | Qualification distribution outputs. `workbook_id`, `department_id`, `salary_scale_id`, `salary_level_id`, `qualification_type_id`, `count`. |
| `report_snapshots` | Frozen report data. `report_code`, `subject_type`, `subject_id`, `filters json`, `payload json`, `generated_at`. |
| `report_exports` | Export audit. `report_code`, `format`, `filters json`, `file_path`, `exported_by`, `exported_at`. |
| `audit_logs` | First-party audit table. `actor_user_id`, `event_code`, `auditable_type`, `auditable_id`, `before json`, `after json`, `context json`, `occurred_at`. |
| `system_settings` | Typed settings. `scope_type`, `scope_id`, `key`, `value json`. Use for templates and feature toggles, not business data. |
| Notes | `Needs clarification`: whether `appointment_histories` becomes a dedicated table in phase 1 or folds into `staff_status_histories` plus a future `staff_career_events` table. I recommend a future `staff_career_events` table if appointment history is still business-critical. |

**4. Legacy-to-New Table Mapping**
| Legacy table | Migrate into | Policy/reference vs computed | Do not migrate directly | Cleanup needed |
|---|---|---|---|---|
| `master_staff_list` | `staff`, `staff_personal_details`, `staff_employments` | Treat as raw historical source where more reliable | Derived EDOR flags | Resolve duplicate identities and date quality |
| `staff_list` | `staff`, `staff_employments`, `staff_salary_placements`, `staff_qualifications`, `staff_allowance_assignments`, `staff_status_histories` | Many fields become computed or snapshot-only | Hidden-report assumptions, legacy gross fields as canonical truth | Split overloaded columns; remove retired/filter artifacts |
| `staff_salary` | `salary_structure_rates`, `salary_structure_rate_allowances`, `allowance_types` | Reference/policy | None as live staff data | Split fixed allowance columns into dynamic allowance rows |
| `movement_sheet_workbook` | `movement_workbooks` | Snapshot header | Legacy mutable flags | Normalize year/step references |
| `movement_sheet_details` | `movement_lines`, `movement_line_adjustments` | Snapshot output | Reuse as live staff truth | Clean typos, status semantics |
| `movement_sheet` | `movement_summaries` | Snapshot summary output | None as live staff truth | Recompute from imported lines if inconsistent |
| `budgets` | `budget_cycles`, `budget_workbooks` | Snapshot/run header | Mixed aggregate fields as master truth | Separate cycle from workbook |
| `budget_details` | likely `budget_expenditure_lines` only if historical detail is needed | Secondary output | Do not make canonical until business confirms usage | `Needs clarification` because legacy runtime barely uses it |
| `promotion_years` | `promotion_policies` | Policy/reference | None | Validate scale/level ranges |
| `certificate_bar` | `qualification_scale_ceilings` | Policy/reference | None | Normalize qualification names |
| `tbl_rank` | `ranks`, partly `cadres` | Reference/policy | None | Resolve cadre naming drift |
| `departments` | `departments` | Reference | None | Normalize duplicates per MDA |
| `controls` | `system_settings` for UI/process toggles only | Operational setting | Never use as hidden mutation switch | Remove page-load behavior coupling |
| `tbl_staff_postings` | `posting_requests` | Workflow history | Legacy side effects on unrelated tables | Confirm approved posting effect on current placement |
| `appointment_histories` | `staff_status_histories` or future `staff_career_events` | Historical events | Page-load seeding behavior | `Needs clarification` on long-term usage |

**5. Source-of-Truth Design**
| Question | Answer |
|---|---|
| Canonical staff identity | `staff` |
| Employment placement | current row in `staff_employments` where `is_current = true` |
| Salary placement | current row in `staff_salary_placements` where `is_current = true` |
| Computed salary/gross values | calculate from `salary_structure_rates` + `salary_structure_rate_allowances` + active `staff_allowance_assignments`; snapshot into `movement_lines` and budget tables when needed |
| Retirement date/status | compute via `RetirementPolicyService` from `staff.date_of_birth`, `staff_employments.date_first_appointment`, store reviewed outcome in `retirement_reviews` and active status in `staff_status_histories` |
| Promotion eligibility | calculate on demand with optional persisted review in `promotion_reviews` |
| Snapshot tables | `movement_workbooks`, `movement_lines`, `movement_summaries`, `budget_workbooks`, `budget_lines`, `budget_*_lines`, `report_snapshots`; lock after approval |
| Calculate on demand | gross salary, allowance eligibility, retirement eligibility, promotion eligibility, report totals, duplicate risk flags |

**6. Staff Import Pipeline Implementation**
| Stage | Class/action | Input -> Output | Tables | Events / Audit / Tests |
|---|---|---|---|---|
| Upload | `UploadStaffImportAction` | file -> stored upload + batch | `staff_import_batches` | `StaffImportUploaded`; audit upload; test mime/size/hash |
| File validation | `ValidateStaffImportTemplateAction` | file -> template verdict | none + batch status | audit invalid template; test header mismatch |
| Batch create | `CreateStaffImportBatchAction` | metadata -> batch | `staff_import_batches` | test MDA/user linkage |
| Parse rows | `ParseStaffImportRowsJob` | batch -> raw row records | `staff_import_rows` | `StaffImportRowsParsed`; test row count and ordering |
| Raw row store | inside parse job | CSV row -> `raw_payload` | `staff_import_rows` | audit created rows |
| Row validation | `ValidateStaffImportRowsJob` | rows -> errors/status | `staff_import_row_errors` | `StaffImportValidated`; test required/date/enum rules |
| Duplicate detection | `DetectDuplicateStaffImportRowsAction` | rows -> dedupe keys | rows/errors | test same CNO, same PSN, cross-batch duplicate scenarios |
| Normalization | `NormalizeStaffImportRowsJob` | rows -> normalized payload | `staff_import_rows` | test dates, names, department/cadre mapping |
| Error reporting | `BuildStaffImportErrorSummaryAction` | batch -> summary DTO | none | test grouped error outputs |
| Correction contract | `ApplyStaffImportCorrectionAction` | correction DTO -> corrected row | `staff_import_corrections`, rows | audit before/after; test correction history |
| Approval submit | `SubmitStaffImportForApprovalAction` | batch -> workflow | `approval_workflows`, `approval_steps` | test cannot submit with blocking errors |
| Publication | `PublishStaffImportBatchAction` | approved batch -> canonical writes | `staff`, related staff tables, `staff_import_publications` | `StaffImportPublished`; audit created/updated records; feature test end-to-end |
| Rollback | `RollbackStaffImportPublicationAction` | publication -> compensating reversal | publication + affected staff history | `Needs clarification`: allow full rollback only before downstream movement/budget usage |

**7. Core Domain Services**
| Service | Responsibility / Methods / Reads-Writes / Tests |
|---|---|
| `StaffIdentityService` | resolve/create canonical identity; `matchCandidate()`, `createStaff()`, `mergeDecisionPreview()`; reads `staff`, writes `staff`; test duplicate resolution |
| `StaffImportService` | orchestrates batch lifecycle; `upload()`, `parse()`, `validate()`, `submit()`, `publish()`; reads/writes import tables; test happy/error paths |
| `StaffNormalizationService` | normalize names, categories, booleans, legacy codes; writes normalized payload; test deterministic transforms |
| `DateNormalizationService` | parse/repair legacy dates; `normalizeDate()`, `deriveRetirementDates()`, `derivePromotionDate()`; pure unit tests for messy date cases |
| `DepartmentResolutionService` | resolve department from import row and cadre defaults; reads `departments`, `cadres`; test fallback behavior |
| `CadreResolutionService` | map imported cadre names to canonical cadres; reads `cadres`, `ranks`; test alias mapping |
| `QualificationCeilingService` | get max allowed level by qualification/scale; reads `qualification_scale_ceilings`; test missing ceiling handling |
| `SalaryPlacementService` | resolve current/proposed placement and pay rates; reads salary/rank/policy tables, writes `staff_salary_placements` or snapshot values; test scale/step rules |
| `SalaryCalculationService` | calculate basic salary, allowance breakdown, total allowances, gross, and legacy gross comparison; reads `salary_structure_rates`, `salary_structure_rate_allowances`, `allowance_types`, and staff assignments; snapshot into movement/budget outputs; test eligible allowance selection |
| `PromotionPolicyService` | determine promotion due/type; reads `promotion_policies`, ranks, qualifications; test normal vs advancement and edge levels |
| `RetirementPolicyService` | determine retirement date/reason/status; reads `retirement_policies`; test age/service conflicts |
| `MovementSheetGenerationService` | generate workbook and lines; writes `movement_*`; test workbook idempotency and lock rules |
| `MovementSummaryService` | aggregate lines into summaries; writes `movement_summaries`; test totals/proration |
| `BudgetGenerationService` | create budget workbook/lines from approved movement workbook; writes `budget_*`; test prior-year linking |
| `BudgetStrengthService` | generate strength breakdown lines; writes `budget_strength_lines`; test sex/scale/level grouping |
| `QualificationDistributionService` | generate qualification distributions; writes `budget_qualification_distribution_lines`; test grouping |
| `PayrollComparisonService` | compare imported payroll expectations vs configured salary/rules; output DTO/report snapshot; test mismatch reporting |
| `ReportExportService` | export report queries to xlsx/csv/pdf; writes `report_exports`; test permission and file generation |
| `AuditLogService` | domain audit wrapper; `logCreated()`, `logUpdated()`, `logWorkflowAction()`, `logExport()`; writes `audit_logs`; test before/after capture |
| `ApprovalWorkflowService` | generic workflow engine; `submit()`, `approveStep()`, `reject()`, `resubmit()`, `lockSubject()`; writes workflow tables; test transitions |

**8. Movement Sheet Implementation Plan**
Flow:
1. `CreateMovementWorkbookAction` creates one draft workbook per `mda_id + year`.
2. `SelectMovementPopulationAction` loads active staff in MDA from canonical staff tables, never from prior movement tables.
3. `EvaluateMovementLineAction` computes one line per staff using current employment, salary placement, qualification ceiling, promotion policy, and retirement policy.
4. `DeterminePromotionTypeAction` returns `none`, `normal`, `advancement`, `special_adjustment`, or `retirement_blocked`. `Needs clarification`: preserve the legacy GL 10 to 12 jump and Nursing Officer level-6 advancement only after policy sign-off.
5. `ApplyCadreBarAction` caps proposed level to cadre/rank max.
6. `ApplyQualificationBarAction` caps proposed level to qualification ceiling.
7. `DetectRetirementInYearAction` sets retirement decision and month when retirement date falls inside workbook year.
8. `CalculateProposedPlacementAction` resolves proposed level/step and rate row.
9. `CalculateMovementAmountsAction` snapshots current and proposed basic/allowance/gross.
10. `ApplyMovementAdjustmentAction` adds budget specialty/manual adjustments into `movement_line_adjustments`.
11. `SaveMovementLinesJob` upserts immutable draft lines.
12. `GenerateMovementSummariesJob` creates summary rows by department/scale/level.
13. Manual reviewers toggle `selection_state` only for eligible lines.
14. Approval workflow locks workbook and prevents regeneration unless explicitly reopened.
15. Regeneration allowed only in draft/rejected state; approved workbooks create a new revision, not in-place mutation.

Legacy field replacement:
| Legacy | New design |
|---|---|
| `status` | `selection_state` enum: `excluded`, `included`, `locked` |
| `movement_status` | `eligibility_status` enum: `not_due`, `due`, `blocked_by_policy`, `retiring` |
| `willRetire` | `retirement_status` enum or boolean in decision trace |
| `retirment_month` | `retirement_month` tinyint nullable |
| `from_` | `current_level_id` |
| `to_` | `proposed_level_id` |
| `proposed_*` | snapshot JSON or explicit columns on `movement_lines` |
| `total_basic_jan_june` | stored aggregate columns on `movement_summaries` |
| `total_proposed_basic` | stored aggregate columns on `movement_summaries` |

**9. Recurrent Budget Implementation Plan**
Flow:
1. `CreateBudgetCycleAction` opens yearly cycle per MDA.
2. `CreateBudgetWorkbookAction` links cycle to one approved movement workbook.
3. `ResolvePriorYearBudgetBaselineAction` pulls prior approved budget or prior approved movement summary if no budget exists.
4. `GenerateBudgetLinesAction` groups approved movement summaries by department/scale/level.
5. `GenerateBudgetExpenditureLinesAction` stores component-level approved/actual/proposed amounts.
6. `GenerateBudgetStrengthLinesAction` stores staff-strength outputs.
7. `GenerateQualificationDistributionLinesAction` stores qualification mix outputs.
8. Approval workflow locks the workbook.
9. Exports read locked snapshot tables only.

`budget_details` handling: do not recreate it as-is in phase 1. Treat it as a legacy artifact. Use `budget_expenditure_lines` for explicit amount components. If historical imports reveal useful detail in `budget_details`, import it only as archival snapshot data.

**10. Report Architecture**
| Layer | Design |
|---|---|
| Registry | `ReportRegistry` maps report code to query class, DTO, filters, permissions, export formats |
| Query classes | One query object per report, no business logic in controllers |
| DTOs | Stable row DTOs for grid/export parity |
| Filters | typed filter objects: year, MDA, department, cadre, status, scale, level |
| Columns | declarative column definitions per report |
| Formats | HTML table, CSV, XLSX, PDF |
| Live vs snapshot | live for operational views; snapshot for movement, budget, approved approval outputs |
| Permissions | report-specific policy checks plus MDA scoping |
| Export audit | every export writes `report_exports` and `audit_logs` |

Required reports:
| Report | Type |
|---|---|
| Staff roster | Live |
| Active staff by MDA/department/cadre/level | Live |
| Retired staff list | Live |
| Retirement due this month/year/next year | Live |
| Duplicate/import exception report | Live |
| Promotion eligibility report | Live |
| Allowance eligibility report | Live |
| Payroll comparison/mismatch report | Live |
| Posting request register | Live |
| Movement workbook detail | Snapshot |
| Movement summary by department/scale/level | Snapshot |
| Budget recurrent estimate | Snapshot |
| Budget strength | Snapshot |
| Qualification distribution | Snapshot |
| Audit trail report | Live |
| Export history report | Live |

**11. Approval and Audit Architecture**
Use a generic state machine: `draft -> submitted -> under_review -> approved -> locked` and `draft/submitted/under_review -> rejected -> resubmitted`. Each workflow action stores reviewer, comment, timestamp, and optional diff summary. Approved movement/budget/report snapshots become immutable. Every important action should write both activity log and first-party `audit_logs`, including before/after values for imports, corrections, approvals, staff placement changes, export generation, and lock/unlock events.

**12. First Migration Batch**
| Order | File name suggestion | Tables | Main notes |
|---|---|---|---|
| 1 | `2026_06_09_000001_create_mdas_table.php` | `mdas` | `tenant_id`, code/name uniques |
| 2 | `...000002_create_departments_stations_locations_tables.php` | `departments`, `stations`, `locations` | org FKs and uniques |
| 3 | `...000003_update_users_for_mda_scope.php` | `users` columns | add `tenant_id`, `mda_id`, admin flags |
| 4 | `...000004_create_permission_tables.php` | spatie tables | use package migration |
| 5 | `...000005_create_salary_reference_tables.php` | `salary_scales`, `salary_levels`, `salary_steps`, `salary_structure_rates` | effective-date indexes |
| 6 | `...000006_create_cadres_ranks_qualification_policy_tables.php` | `cadres`, `ranks`, `qualification_types`, `qualification_scale_ceilings`, `promotion_policies`, `retirement_policies`, `allowance_types`, `allowance_rules` | reference/policy FKs |
| 7 | `...000007_create_staff_core_tables.php` | `staff`, `staff_personal_details`, `staff_employments`, `staff_salary_placements`, `staff_qualifications`, `staff_allowance_assignments`, `staff_status_histories`, `staff_documents` | identity and current-row indexes |
| 8 | `...000008_create_staff_import_tables.php` | import batch/row/error/correction/publication tables | batch+row uniques |
| 9 | `...000009_create_workflow_and_audit_tables.php` | `posting_requests`, `promotion_reviews`, `retirement_reviews`, `approval_workflows`, `approval_steps`, `audit_logs` | workflow subject polymorphism |
| 10 | `...000010_create_movement_tables.php` | `movement_workbooks`, `movement_lines`, `movement_line_adjustments`, `movement_summaries` | workbook+staff unique |
| 11 | `...000011_create_budget_tables.php` | `budget_cycles`, `budget_workbooks`, `budget_lines`, `budget_expenditure_lines`, `budget_strength_lines`, `budget_qualification_distribution_lines` | workbook grouping uniques |
| 12 | `...000012_create_report_and_settings_tables.php` | `report_snapshots`, `report_exports`, `system_settings` | report/export indexes |

**13. First Coding Milestone**
Commands:
```bash
composer create-project laravel/laravel .
composer require spatie/laravel-permission spatie/laravel-activitylog spartnernl/laravel-excel barryvdh/laravel-dompdf
php artisan breeze:install vue
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
php artisan vendor:publish --provider="Spatie\Activitylog\ActivitylogServiceProvider" --tag="activitylog-migrations"
php artisan migrate
```

Files to create first:
- `app/Domain/Organization/Models/Mda.php`
- `app/Domain/Auth/Policies/MdaScopePolicy.php`
- `app/Domain/Audit/Models/AuditLog.php`
- `app/Http/Middleware/EnsureMdaScope.php`
- `database/migrations/*mdas*`
- `database/migrations/*update_users_for_mda_scope*`
- `tests/Feature/Auth/MdaScopingTest.php`
- `tests/Feature/Audit/AuditLogTest.php`

Acceptance criteria:
- User belongs to exactly one tenant and optionally one MDA.
- Non-platform users can only query their own MDA data.
- Platform admin can cross MDA only in explicit admin routes.
- `mdas` table exists and links to users.
- Audit log table exists and records a sample action.
- Vue shell/auth loads successfully, but no business pages are required yet.

Tests to write first:
- login works
- user cannot access another MDA’s records
- platform admin can access admin-scoped route
- creating/updating an MDA-scoped record writes audit log
- policies reject missing-tenant or missing-MDA leakage

Common mistakes to avoid:
- putting MDA scoping only in controllers instead of policies/query scopes
- storing computed gross as canonical staff truth
- reviving legacy hidden filters like “exclude retired” inside helpers
- mutating staff state from report page loads
- using one table for raw import, canonical staff, and report snapshots

**Needs clarification before phase 2**
- Whether MDA is the primary business scope or a subdivision inside tenant organizations in this repo.
- Whether `master_staff_list` should be imported as historical-only or used to backfill canonical staff dates.
- Whether the Nursing Officer advancement rule is valid policy or legacy workaround.
- Whether the GL level jump at promotion to 11/12 is still valid.
- Whether approved posting updates only station/department or also salary/employment history.
- Whether `appointment_histories` must remain a first-class feature in phase 1.

Sources for current package/framework recommendations:
- Laravel starter kits: https://laravel.com/docs/12.x/starter-kits
- Laravel authentication: https://laravel.com/docs/12.x/authentication
- Laravel queues: https://laravel.com/docs/12.x/queues
- Laravel Horizon: https://laravel.com/docs/12.x/horizon
- Laravel testing: https://laravel.com/docs/12.x/testing
- Spatie permission: https://github.com/spatie/laravel-permission
- Spatie activitylog: https://github.com/spatie/laravel-activitylog
- Laravel Excel: https://github.com/SpartnerNL/Laravel-Excel
- Laravel DOMPDF: https://github.com/barryvdh/laravel-dompdf

If you want, I can turn this next into a phase-by-phase build backlog, or directly draft the first migration set and model map without touching the frontend.
