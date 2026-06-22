# Architecture

## Tech stack

| Layer | Choice |
|---|---|
| Backend framework | Laravel 12, PHP ^8.2 |
| Frontend | Vue 3 (^3.4) + Vue Router 4, plain Vue `reactive()` for app-level state (no Pinia/Vuex dependency is installed) |
| HTTP client | Axios |
| Build | Vite 6 |
| CSS | Tailwind CSS 3, plus a hand-written `civic-*` utility/component class set in `resources/css/app.css` |
| Auth | Laravel session auth (`statefulApi()`), not Sanctum tokens |
| Roles/permissions | `spatie/laravel-permission` ^8 |
| Activity log package | `spatie/laravel-activitylog` ^4 is installed but the app uses its **own** first-party `AuditLog` model/service (see [05](05-domain-movement-and-budget.md#audit-domain)) for domain audit trails — the Spatie package does not appear to be the thing wired into business logic |
| Excel import/export | `maatwebsite/excel` ^3 |
| PDF | `barryvdh/laravel-dompdf` ^3 (used for compiling staff document pages into a single PDF) |
| Database | Whatever Laravel's default connection is configured to (MySQL/Postgres per `.env`); the **legacy** source data is read through a separate `legacy` database connection (SQLite in tests) |

## High-level shape

This is a **modular monolith**: business logic lives under `app/Domain/<Name>/{Models,Services}`,
thin HTTP controllers live under `app/Http/Controllers/Api/*`, and the frontend is a single Vue
SPA served by one Blade shell. There is no microservice boundary anywhere in this codebase.

```
app/
  Domain/
    Approval/      generic multi-step approval workflow (subject-agnostic)
    Audit/         AuditLog model + AuditLogService, injected everywhere
    Budget/        BudgetWorkbook / BudgetLine
    Imports/       OperationalDataImportService (spreadsheet imports of reference data + staff)
    Legacy/        legacy staff import pipeline (Batch/Row/Error/Publication + services)
    Movement/      MovementWorkbook / MovementLine / MovementSummary
    Organization/  Mda, Department, Station, Location, MdaSetting, PlatformSetting
    Staff/         Staff + employment/salary/qualification/allowance/document models & services
  Http/
    Controllers/Api/   the only controllers actually wired into routes (see below)
    Controllers/Auth/  Laravel breeze-style auth controllers (password reset, email verify, etc.)
    Requests/          FormRequest validation classes, one per mutating endpoint
    Resources/         JsonResource classes shaping API responses
    Middleware/        ResolveDomainContext, EnsureUserHasMdaAccess
  Policies/        one policy per authorizable model, several composing Policies\Concerns\MdaScopedPolicy
  Models/          User, UserAccessScope (framework-level, not under app/Domain)
resources/
  js/spa/          the entire Vue application (see 06-frontend-spa.md)
  css/app.css      Tailwind + hand-rolled "civic-*" component classes
  views/spa.blade.php   the one Blade view that boots the SPA
routes/
  web.php          a handful of Breeze auth routes + a catch-all that serves the SPA shell
  api.php          every endpoint the SPA actually calls (session-authenticated, not token)
```

## Request lifecycle

1. Any browser request to a path that isn't `/api/*`, `/storage/*`, or `/up` is served the same
   Blade view (`spa.blade.php`) by the catch-all route in `routes/web.php`. Vue Router then takes
   over client-side routing entirely — there are no server-rendered pages for the app proper
   (only the handful of Breeze-style `Route::view(...)` auth routes — login/forgot-password/etc.
   — which are *also* just the same `spa` view; Vue's own router renders the actual login form).
2. The SPA's `app.js` entry point loads public branding (`GET /api/public-context`), then mounts
   the Vue app.
3. `router.js`'s global guard calls `GET /api/me` once per session bootstrap to determine whether
   a user is authenticated, then gates navigation accordingly (see
   [06-frontend-spa.md](06-frontend-spa.md)).
4. All subsequent data fetching goes through `routes/api.php`, authenticated via Laravel's
   cookie/session guard (`auth` middleware), with two domain-aware middleware layers applied
   globally (`ResolveDomainContext`) or to a subset of routes (`ensure.mda`) — see
   [02-auth-and-access.md](02-auth-and-access.md).
5. Controllers are intentionally thin: they validate via a `FormRequest`, call into a
   `Domain\*\Services\*` class for the actual business logic, and shape the response via a
   `Http\Resources\*` class. Almost no business logic lives in controllers.

## Multi-tenancy model

The system is multi-tenant by **MDA** (Ministry/Department/Agency), not by a generic `tenant_id`
column as the original `implementation_plan.md` proposed — the as-built system scopes by
`mda_id` directly on tenant-owned tables (`Department`, `Station`, `Staff`, `Staff*` child
tables via their parent, `MovementWorkbook`, `BudgetWorkbook`, `User`). See
[02-auth-and-access.md](02-auth-and-access.md) for the full mechanics (`HasMdaScope` trait,
`MdaScope` global scope, `User::hasGlobalMdaAccess()` / `canAccessMda()`).

## Dead / unused code

These exist in the tree but are **not reachable** — they're not referenced by any route file or
service provider. Confirmed by grepping for each class name outside its own file:

- `app/Http/Controllers/Staff/*.php` (`StaffController`, `StaffEmploymentController`,
  `StaffQualificationController`, `StaffSalaryPlacementController`,
  `StaffAllowanceAssignmentController`, `StaffStatusHistoryController`) — superseded by
  `app/Http/Controllers/Api/StaffController.php` + `StaffMediaController.php`, which *are* wired
  into `routes/api.php`.
- `app/Http/Controllers/LegacyStaffImport*.php` (root namespace: `LegacyStaffImportController`,
  `LegacyStaffImportApprovalDecisionController`, `LegacyStaffImportApprovalSubmissionController`,
  `LegacyStaffImportPublicationController`, `LegacyStaffImportRowController`,
  `LegacyStaffImportRowMappingController`, `LegacyStaffImportRowPublicationController`,
  `LegacyStaffImportWarningController`) — superseded by
  `app/Http/Controllers/Api/LegacyStaffImportController.php` +
  `app/Http/Controllers/Api/WorkflowActionController.php`.
- `app/Http/Controllers/MovementWorkbookPageController.php` and
  `app/Http/Controllers/BudgetWorkbookPageController.php` — superseded by the `Api\*` workbook
  controllers.

These all look like an earlier, server-rendered (Blade-page-per-action) iteration of the same
features that was abandoned in favor of the API + SPA approach, and never deleted. Don't spend
time trying to trace a route to them — there isn't one. If/when doing cleanup, these are safe to
delete after a final grep confirms no new route was added since this was written.

## Testing

PHPUnit (not Pest) under `tests/Feature` and `tests/Unit`, run via `php artisan test`. Feature
tests use `RefreshDatabase` and a shared fixture-building pattern (e.g.
`tests/Concerns/BuildsLegacyStaffImportFixtures.php`, and per-test `setUpStaffFixtures()` helpers)
rather than model factories for most domain objects. As of this writing the suite is ~123 passing
tests; two `AuthenticationTest` cases fail in this environment for reasons unrelated to any
feature work (a "Session store not set on request" error on the logout test) and fail identically
on a clean checkout, so they're a pre-existing environment quirk, not a regression signal.
