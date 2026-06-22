# Authentication, Authorization & Organization

## Authentication: session-based, not token-based

The SPA authenticates against Laravel's normal cookie/session guard via `statefulApi()`
(configured in `bootstrap/app.php`) — there is **no Sanctum token, no Bearer header**. Axios is
configured with `withXSRFToken: true` and sends the session cookie automatically; Laravel's CSRF
cookie is what protects mutating requests.

**Login** — `POST /api/login` (`SpaAuthController::login`, no `auth` middleware, guarded by
`guest`):
1. `LoginRequest::authenticate()` checks credentials against the `users` table.
2. Domain-aware access check, via the request-scoped `DomainContext` (set by
   `ResolveDomainContext` middleware, see below):
   - If the request came in on an **MDA's own domain** (`$context->isMdaDomain()`): the user must
     either have platform access (`hasPlatformAccess()`) or explicit access to that MDA
     (`canAccessMda($context->mda()->id)`), or they're logged out immediately and get a 403.
   - If the request came in on the **platform domain**: the user must have platform access, or
     the platform setting must have `allow_platform_login = true`, or they're logged out and get
     a 403.
3. On success, the session ID is regenerated (fixation protection) and a plain `{message}` JSON
   is returned — no user payload here; the SPA fetches that separately via `/api/me`.

**Logout** — `POST /api/logout`: guard logout, session invalidate, token regenerate.

**`GET /api/me`** (`CurrentUserContextController`) — returns the full current-user context the
SPA needs to render: id/name/email/user_type/status, role names, the flattened permission list,
the user's assigned MDA, `has_global_access`, access scopes, and branding. The SPA calls this once
per session bootstrap (see [06-frontend-spa.md](06-frontend-spa.md)) and treats a 401 here as
"not logged in" rather than an error.

**`GET /api/public-context`** (`PublicContextController`, no auth required) — returns just
branding (platform or MDA, depending on which domain resolved) so the login screen can show the
right logo/state name before anyone is authenticated.

## `ResolveDomainContext` middleware

Applied globally to both the `web` and `api` middleware groups in `bootstrap/app.php`. On every
request it:
1. Looks up (or lazily creates, defaulting to Niger State / "eHRMIS") the single `PlatformSetting`
   row, keyed by `state_code = 'NG-NI'`.
2. Looks up an `Mda` whose `MdaSetting.domain` matches the current request host.
3. Stores both on a request-scoped `App\Support\DomainContext` singleton, which is what
   `SpaAuthController` and `PublicContextController` consult.

In other words: which MDA's branding/login-rules apply is determined by **which hostname the
request came in on**, not by anything in the session. An MDA admin's portal can be configured to
live on its own subdomain with its own logo and its own "is platform login allowed" rule.

## `EnsureUserHasMdaAccess` middleware (`ensure.mda` alias)

Applied to the `mdas` / `departments` / `stations` / `locations` route group only. Aborts 401 if
unauthenticated, 403 if the user has neither global MDA access nor an assigned `mda_id`. This is
a guard against a half-provisioned user account (a role but no MDA) silently seeing nothing or
erroring deeper in the stack — it fails fast and explicitly.

## The `User` model and MDA scoping primitives

`app/Models/User.php` (not under `app/Domain`):

- Fillable: `mda_id, name, email, email_verified_at, password, user_type, status, last_login_at, last_login_ip`.
- `user_type` is cast to a `UserType` backed enum: `super_admin`, `mis_admin`, `mda_admin`,
  `hr_officer`, `budget_officer`, `payroll_auditor`, `report_viewer`, `approval_officer` — one
  value per seeded role, though nothing enforces that a user's `user_type` matches their actual
  Spatie role assignment; they're tracked independently. `status` casts to `RecordStatus`
  (`active` / `inactive`).
- Uses `Spatie\Permission\Traits\HasRoles` — `getRoleNames()`, `getAllPermissions()`,
  `syncRoles()`, `can()` all come from that trait.
- `mda()` — `belongsTo(Mda::class)`, the user's primary/home MDA.
- `accessScopes()` — `hasMany(UserAccessScope::class)`. A user can have zero or more access-scope
  rows beyond their home MDA; each row has a `scope_type` of `platform`, `state`, or `mda` (plus
  `mda_id` when scope_type is `mda`).
- **`hasGlobalMdaAccess(): bool`** — true if `user_type` is `super_admin`/`mis_admin`, OR the user
  has any `platform`/`state` access-scope row. This is the master "can see everything" check used
  throughout query scoping (`Staff`, `Department`, `Station`, `MovementWorkbook`,
  `BudgetWorkbook`, etc. all branch on this).
- **`hasPlatformAccess(): bool`** — same idea but only true for `platform`-scoped access
  specifically (used by `SpaAuthController` and `SettingsController` for platform-setting writes).
- **`canAccessMda(int $mdaId): bool`** — true if `hasGlobalMdaAccess()`, or `mda_id` matches, or
  there's an explicit `mda`-scoped access-scope row for that MDA. This is the one to reach for
  when authorizing access to a *specific* MDA's resource.
- **`scopeVisibleTo(Builder, User)`** — query scope: returns everything for globally-scoped
  users, an empty result for a user with no `mda_id` at all, otherwise filters to the user's MDA.

## `MdaScopedPolicy` trait

`app/Policies/Concerns/MdaScopedPolicy.php` is mixed into most resource policies
(`StaffPolicy`, `DepartmentPolicy`, `StationPolicy`, `MovementWorkbookPolicy`,
`BudgetWorkbookPolicy`, `LegacyStaffImportRowPolicy`, …). It exposes one helper,
`canAccessMda(User $user, ?int $mdaId)`, which is effectively the same check as
`User::canAccessMda()` and is used inside each policy's `view`/`update`/`delete` methods alongside
a Spatie permission check (e.g. `StaffPolicy::update` = `$user->can('update-staff') &&
$this->canAccessMda($user, $staff->mda_id)`).

## `HasMdaScope` trait + `MdaScope` global scope

Models that are MDA-owned (`Department`, `Station`) use a `HasMdaScope` trait
(`app/Models/Concerns/HasMdaScope.php`) which boots a global scope (`App\Scopes\MdaScope`) on the
model. That scope:
- Skips filtering entirely if the authenticated user `hasGlobalMdaAccess()`.
- Returns an empty result if the user has no `mda_id`.
- Otherwise adds `where('mda_id', $user->mda_id)` to every query automatically.

This means controllers generally don't need to remember to scope these models manually — the
model itself won't leak cross-MDA data to a non-global user. `Staff` does **not** use this trait
directly but is scoped the same way via its own `HasMdaScope` usage (see
[03-domain-staff.md](03-domain-staff.md)). Note this is an Eloquent **global scope**, so anywhere
the codebase needs to deliberately bypass it (e.g. legacy-import identity matching across the
whole table, or an admin listing across MDAs) it must call `::withoutGlobalScopes()` explicitly —
several services do this, so don't assume scoping is automatic everywhere you see a bare query.

## Roles & permissions (seeded)

`database/seeders/RolesAndPermissionsSeeder.php` seeds **33 permissions** via
`spatie/laravel-permission`, then 7 roles as named permission bundles. This is the authoritative
list — re-run the seeder (or read the file) before assuming a permission exists; nothing else in
the codebase defines permissions dynamically.

**All permissions**, grouped by area:

- MDAs: `view-mdas`, `create-mdas`, `update-mdas`, `delete-mdas`
- Departments: `view-departments`, `create-departments`, `update-departments`, `delete-departments`
- Staff: `view-staff`, `create-staff`, `update-staff`, `delete-staff`
- Staff imports: `import-staff`, `approve-staff-imports`, `view-staff-imports`,
  `review-staff-imports`, `resolve-staff-import-issues`, `publish-staff-imports`,
  `publish-own-mda-staff-imports`
- Movement: `view-movement-sheets`, `create-movement-sheets`, `approve-movement-sheets`
- Budgets: `view-budgets`, `create-budgets`, `approve-budgets`
- Reporting: `view-reports`, `export-reports`
- Audit: `view-audit-logs`
- Settings: `manage-settings`, `manage-platform-settings`, `manage-mda-settings`
- Access control: `manage-roles`, `manage-users`

**Roles** (as literally defined in the seeder — `syncPermissions`, so this is exact, not additive):

| Role | Permissions |
|---|---|
| **Super Admin** | all 33 |
| **MIS Admin** | all 33 except `manage-settings` |
| **MDA Admin** | `view-departments`, `create-departments`, `update-departments`, `view-staff`, `create-staff`, `update-staff`, `delete-staff`, `import-staff`, `view-staff-imports`, `review-staff-imports`, `resolve-staff-import-issues`, `publish-own-mda-staff-imports`, `view-movement-sheets`, `view-budgets`, `view-reports`, `manage-users`, `manage-mda-settings` |
| **HR Officer** | `view-staff`, `create-staff`, `update-staff`, `delete-staff`, `import-staff`, `view-staff-imports`, `review-staff-imports`, `view-reports` |
| **Budget Officer** | `view-movement-sheets`, `create-movement-sheets`, `view-budgets`, `create-budgets`, `view-reports` |
| **Payroll Auditor** | `view-staff`, `view-budgets`, `view-reports`, `export-reports` |
| **Report Viewer** | `view-reports`, `export-reports` |
| **Approval Officer** | `approve-staff-imports`, `view-staff-imports`, `review-staff-imports`, `publish-staff-imports`, `approve-movement-sheets`, `approve-budgets`, `view-reports` |

Notice the deliberate split between `publish-staff-imports` (global — only Approval Officer and
the two admin roles via the "all" bundles) and `publish-own-mda-staff-imports` (MDA Admin only) —
this is the mechanism that lets an MDA Admin publish their own import batch without being able to
publish anyone else's.

## `AccessManagementController`

Backs the `/access-management` SPA view (Super Admin / MIS Admin / MDA Admin tooling for managing
who can do what).

- **`GET /api/access-management`** — returns: `users` (scoped to the acting user's visibility —
  a non-global MDA Admin only sees their own MDA's users), `roles` (Super Admin/MIS Admin rows are
  hidden from non-platform-access viewers so an MDA Admin can't even see those roles exist to
  assign), `permissions`, `mdas` visible to the user, a `can_manage_roles` flag, and the
  3 valid `scope_types`.
- **`PUT /api/access-management/roles/{role}`** — requires `manage-roles` *and* platform access;
  validates the incoming permission list against the real `permissions` table, then
  `syncPermissions()` and clears the Spatie permission cache.
- **`PUT /api/access-management/users/{managedUser}`** — requires `manage-users` and either
  platform access or `canAccessMda($managedUser->mda_id)`; validates roles + `scope_type` +
  (`state_code` or `mda_id` depending on scope); blocks a non-platform-access actor from granting
  `platform` scope or assigning the Super Admin/MIS Admin roles; blocks assigning a user into an
  MDA the actor can't access; transactionally syncs roles, replaces the user's access-scope rows,
  and updates `mda_id` if the new scope is MDA-level.

## `SettingsController`

Backs the `/settings` SPA view.

- **`GET /api/settings`** — returns the single `PlatformSetting` (only if the caller has
  `manage-platform-settings` and platform access), the list of MDAs visible to the caller (with
  `setting.headRank`/`setting.headStaff` preloaded), and the full `Rank` list (for the head-rank
  picker).
- **`POST /api/settings/platform`** — `manage-platform-settings` + platform access required;
  upserts the one `PlatformSetting` row (state code/name, platform name/acronym, default domain,
  support email/phone, `allow_platform_login`, logo upload).
- **`POST /api/settings/mdas/{mda}`** — `manage-mda-settings` + `canAccessMda($mda->id)` required;
  updates `Mda.name`/`code` plus the associated `MdaSetting` (acronym, domain — must be globally
  unique since `ResolveDomainContext` keys off it, logo, signature, phone, email, head rank/staff,
  head title, and `vision_html`/`mission_html`, which are sanitized down to a small allowed-tag
  whitelist before saving).
- **`GET /api/settings/mdas/{mda}/eligible-heads`** — given a `rank_id`, returns staff in that MDA
  currently employed at that rank, as candidates for the "head of MDA" field on `MdaSetting`.

## Organization domain

`app/Domain/Organization/Models/`:

- **`Mda`** — top-level tenant (a Ministry/Department/Agency). Fields: `code`, `name`,
  `description`, `status`; soft-deletable. `hasMany` Department/Station/User, `hasOne`
  `MdaSetting`. `Mda::visibleToUser($user)` returns all MDAs for a globally-scoped user, or just
  the user's own MDA otherwise — used by every "list MDAs for a dropdown" call site.
- **`Department`**, **`Station`** — both `mda_id`-scoped via `HasMdaScope` (see above), each with
  `code`/`name`/`description`/`status`, soft-deletable.
- **`Location`** — a flat geographic reference (`state`, `lga`, `ward`, `town`,
  `is_urban_center`), **not** MDA-scoped — every authenticated user sees the full list. Used for
  staff/station geography rather than tenancy.
- **`MdaSetting`** — one-to-one with `Mda`: branding/contact fields, `head_rank_id`/`head_staff_id`
  (the official head-of-MDA), `vision_html`/`mission_html`.
- **`PlatformSetting`** — singleton-ish row (keyed by `state_code`) holding platform-wide
  branding, created lazily by `ResolveDomainContext` if missing.

**Controllers** (`MdaController`, `DepartmentController`, `StationController`,
`LocationController`) are each a single `index()` action behind `ensure.mda` middleware and a
matching `viewAny` policy check (`MdaPolicy`, `DepartmentPolicy`, `StationPolicy`,
`LocationPolicy` — the last has no MDA scoping check at all, consistent with `Location` not being
tenant-owned). `DepartmentController`/`StationController` let a globally-scoped caller pass
`?mda_id=` to look at a specific MDA; everyone else implicitly gets their own MDA's rows via the
`HasMdaScope` global scope.
