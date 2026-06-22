**Execution Board**

This board converts the current HRMIS backlog into executable phases and starts with the first practical module: Staff Management.

Reference:
- See [docs/mda-tenancy-rules.md](docs/mda-tenancy-rules.md) before changing any MDA-owned feature, query, dropdown, import, report, or policy.

**Phase A: Staff Management Module**

Goal:
- Make imported staff data fully usable through real pages, routes, services, and policies.

Tasks:
1. Extend staff salary placements with calculation snapshot columns.
2. Add `StaffPolicy` and register it.
3. Add staff query/update/salary placement/allowance services.
4. Add staff controllers, requests, and resources.
5. Add authenticated staff routes.
6. Add staff index, show, edit, and create pages.
7. Add reusable staff UI subcomponents.
8. Add feature and service tests.
9. Run backend tests and frontend build.

Acceptance criteria:
- Staff index supports search, filters, pagination, and MDA scoping.
- Staff detail shows bio, employment, salary, qualifications, allowances, status history, import metadata, and audit summary.
- Staff edit updates are audited.
- Salary placement updates use `SalaryCalculationService` and close old current rows.
- Allowance sync uses `allowance_type_id` only.

**Phase B: Import Operations UI**

Tasks:
1. Add import batch list and detail pages.
2. Add staged row review screen.
3. Add row warning/error breakdown screen.
4. Add manual correction workflow.
5. Add publish screen and publication history.

Acceptance criteria:
- Non-technical users can stage, review, correct, and publish imports without artisan commands.

**Phase C: Approval Workflow**

Tasks:
1. Add `approval_workflows` and `approval_steps`.
2. Add reusable approval service.
3. Integrate imports, movement, budgets, and postings later.

Acceptance criteria:
- Review and approval state changes are no longer hardcoded per module.

**Phase D: HR Workflows**

Tasks:
1. Promotion review module.
2. Retirement review module.
3. Posting/transfer module.
4. Staff document handling.

Acceptance criteria:
- HR operational actions can be performed through the application with audit coverage.

**Phase E: Reporting**

Tasks:
1. Staff roster and filters.
2. Retirement due and promotion eligibility reports.
3. Payroll comparison and import exception reports.
4. Movement and budget exports.
5. Audit and export history reports.

Acceptance criteria:
- Common operational and statutory outputs are available without direct database access.

**Phase F: Budget Expansion and Production Hardening**

Tasks:
1. Budget cycles and prior-year baselines.
2. Detailed expenditure lines.
3. Strength and qualification distributions.
4. User/role administration UI.
5. Settings and audit UI.
6. Queueing and long-running job hardening.
7. Tenant hardening verification.
8. Data quality closure for remaining rank and EDOR anomalies.

Acceptance criteria:
- The system is production-ready, operationally complete, and supportable.
