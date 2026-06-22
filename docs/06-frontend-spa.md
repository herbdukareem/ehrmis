# Frontend SPA

`resources/js/spa/**`, `resources/js/app.js`, `resources/views/spa.blade.php`.

## Boot sequence

1. `routes/web.php`'s catch-all (`Route::view('/{path?}', 'spa')->where('path', '^(?!api|storage|up).*$')`)
   serves the same Blade view for every non-API path. The handful of named auth routes
   (`/login`, `/dashboard`, `/forgot-password`, `/reset-password/{token}`, plus `/verify-email`,
   `/confirm-password`, `/profile` behind `auth` middleware) are *also* just `Route::view(..., 'spa')`
   — they exist only so those URLs have stable, named server-side routes (e.g. for password-reset
   email links); the actual login form/etc. is rendered client-side by Vue Router once the page
   loads.
2. `resources/js/app.js` calls `loadPublicContext()` (→ `GET /api/public-context`, unauthenticated)
   to populate branding before anything renders, then mounts the root Vue app with the router
   installed.
3. The root component renders `BrandedLoader` (shown while `appState.pendingRequests > 0`) and
   either a guest layout or `CivicShell` (the authenticated app chrome — sidebar nav, topbar,
   permission-gated nav items, logout) wrapping the active routed view.

## State: plain `reactive()`, not Pinia

There is **no Pinia or Vuex dependency** in `package.json` — `resources/js/spa/stores/*.js` are
just Vue 3 `reactive()` objects exported as singletons and imported wherever needed. There are two:

- **`stores/auth.js`** — `auth = reactive({ user: null, ready: false })`.
  - `loadSession()` — `GET /api/me`; on success sets `auth.user`; a `401` is treated as "not
    logged in" (sets `user = null`, does **not** rethrow); any other error rethrows. Always sets
    `ready = true` in a `finally`.
  - `signIn(credentials)` — `POST /api/login`, then `loadSession()` to populate `auth.user`
    (login itself returns no user payload, see [02-auth-and-access.md](02-auth-and-access.md)).
  - `signOut()` — `POST /api/logout`, then clears `auth.user` locally.
  - `can(permission)` — checks `auth.user.permissions` array membership; this is what gates nav
    items and form fields throughout the views.
- **`stores/app.js`** — `appState = reactive({ pendingRequests: 0, branding: {...} })`.
  `loadPublicContext()` populates `branding` from `/api/public-context`. `pendingRequests` is
  incremented/decremented by the Axios interceptors below.

## API client (`lib/api.js`)

A single Axios instance, `baseURL: '/api'`, `withXSRFToken: true` (Laravel's CSRF cookie handling
— no manual token plumbing needed), `Accept: application/json`. A request interceptor increments
`appState.pendingRequests`; both the success and error response interceptors decrement it
(clamped at 0) — this is what drives the global loading overlay. `apiMessage(error, fallback)` is
the shared helper for turning an Axios error into a user-facing string: prefers
`response.data.message`, falls back to the first validation error in `response.data.errors`, then
the caller-supplied fallback text.

## Router (`router.js`)

Vue Router 4, `createWebHistory()`. Every view is lazy-loaded (`() => import(...)`), so each route
is its own JS chunk (visible in the Vite build output as `StaffIndexView-*.js`, etc.).

| Path | Name | View |
|---|---|---|
| `/login` | `login` | `LoginView` (`meta.guest`) |
| `/forgot-password` | `password.request` | `PasswordAccessView` (`mode: 'forgot'`, `meta.guest`) |
| `/reset-password/:token` | `password.reset` | `PasswordAccessView` (`mode: 'reset'`, `meta.guest`) |
| `/` | — | redirect → `/dashboard` |
| `/dashboard` | `dashboard` | `DashboardView` |
| `/staff` | `staff.index` | `StaffIndexView` |
| `/staff/:id` | `staff.show` | `StaffShowView` |
| `/staff/:id/edit` | `staff.edit` | `StaffEditView` |
| `/legacy-staff-imports` | `imports.index` | `ImportIndexView` |
| `/legacy-staff-imports/:id` | `imports.show` | `ImportShowView` |
| `/legacy-staff-imports/:batchId/rows/:rowId` | `imports.rows.show` | `ImportRowView` |
| `/movement-workbooks` | `movement.index` | `MovementIndexView` |
| `/movement-workbooks/:id` | `movement.show` | `MovementShowView` |
| `/budget-workbooks` | `budgets.index` | `BudgetIndexView` |
| `/budget-workbooks/:id` | `budgets.show` | `BudgetShowView` |
| `/reports` | `reports` | `ReportsView` |
| `/settings` | `settings` | `SettingsView` |
| `/access-management` | `access-management` | `AccessManagementView` |
| `*` (anything else) | — | redirect → `/dashboard` |

**Global guard** (`router.beforeEach`): awaits `loadSession()` if the session hasn't been checked
yet (`!auth.ready`); bounces an already-authenticated user away from a `meta.guest` route back to
`/dashboard`; bounces an unauthenticated user from any non-guest route to `/login` with a
`?redirect=` query param pointing back at the page they wanted; sets `document.title` from a
per-route-name lookup table plus the branding acronym.

Note there is **no per-route permission `meta`** — route access is purely "logged in or not";
finer-grained gating (e.g. hiding the "Access management" nav link, or disabling a save button) is
done inside each view/component via `auth.can('some-permission')`, not at the router level.

## Shared components (`components/`)

- **`CivicShell.vue`** — the authenticated app chrome: sidebar nav (items filtered by
  `auth.can(...)`), topbar with current user identity, logout action.
- **`PageHeading.vue`** — eyebrow/title/description header block with an action slot, used at the
  top of nearly every view.
- **`DataTable.vue`** — generic table with scoped-slot column rendering and an empty state.
- **`StatusPill.vue`** — colored badge for status enum values.
- **`LoadingBlock.vue`** — skeleton/placeholder shown while a view's initial data is loading.
- **`BrandedLoader.vue`** — full-page overlay tied to `appState.pendingRequests`.
- **`AppTabs.vue`** — tab strip used by multi-tab views (Dashboard, Movement/Budget show pages).
- **`DonutChart.vue`**, **`HorizontalBarChart.vue`** — small canvas/SVG chart components used on
  the dashboard (gender split, department/cadre distributions, salary scale breakdown).
- **`RichTextEditor.vue`** — minimal WYSIWYG used for `MdaSetting.vision_html`/`mission_html` in
  `SettingsView`.
- **`CameraCaptureModal.vue`** — webcam capture flow, used by `StaffMediaPanel` for passport
  photos.
- **`StaffMediaPanel.vue`** — the passport + multi-page-document tab on `StaffShowView`.

## Views

`StaffIndexView`, `StaffShowView`, and `StaffEditView` are covered in
[03-domain-staff.md](03-domain-staff.md) and [flagged-issues-resolution.md](flagged-issues-resolution.md)
in more depth; summarized here for completeness only.

- **`DashboardView`** — `GET /dashboard`. Executive overview: headcount, retirement windows,
  department/salary-scale/gender distributions, cadre allowance eligibility, a 10-year retirement
  trend (history + projection). Tabbed (Organization / Salary & Gender / Cadres & Allowances /
  Retirement Trends).
- **`LoginView`** — calls `auth.signIn()`; shows `apiMessage()` on failure; redirects to the
  `?redirect=` target or `/dashboard` on success.
- **`PasswordAccessView`** — `mode: 'forgot'` posts to `/forgot-password` (email only);
  `mode: 'reset'` posts to `/reset-password` (email + token + new password).
- **`StaffIndexView`** — staff registry list/search/filter; also owns the "flagged issues" review
  modal and its edit sub-modal (see the dedicated doc).
- **`StaffShowView`** — full staff detail, including the inline allowance-eligibility editor and a
  documents/passport tab (`StaffMediaPanel`).
- **`StaffEditView`** — core-record edit form (name, DOB, status, personal detail) — does **not**
  cover cadre/rank/qualification/allowances, which live in the flagged-issues edit modal or are
  otherwise not yet exposed as a standalone edit UI outside that workflow.
- **`ImportIndexView`** — `GET /legacy-staff-imports` batch list with status/source filters, plus
  the operational-data spreadsheet upload UI (type picker, template download link, file submit).
- **`ImportShowView`** — one batch: summary metrics, filterable/paginated row table, and the
  submit/approve/reject/publish action buttons, each gated by the `can.*` flags the API returns
  for that batch.
- **`ImportRowView`** — one row: normalized placement fields, raw payload, and the three issue-
  resolution actions (resolve identifier, resolve mapping, ignore warning) plus a publish button
  when the row is clean.
- **`MovementIndexView`** — list + a creation form (MDA, movement year, budget year, minimum step)
  gated by `create-movement-sheets`.
- **`MovementShowView`** — workbook detail with Detail (per-staff, grouped by department, with
  per-department export buttons) and Summary (per scale/level establishment counts) tabs, plus the
  review/approve/reject/lock/reopen action buttons.
- **`BudgetIndexView`** — simple workbook list.
- **`BudgetShowView`** — workbook detail (budget lines by department/scale/level) plus
  submit/approve/reject/lock/reopen actions.
- **`SettingsView`** — platform settings (Super Admin/MIS Admin only) and per-MDA settings
  (branding, head-of-MDA picker backed by the eligible-heads endpoint, vision/mission rich text).
- **`AccessManagementView`** — two-pane user/role administration UI backed by
  `AccessManagementController` (see [02-auth-and-access.md](02-auth-and-access.md)).
- **`ReportsView`** — placeholder only; the reporting module (Phase E of `execution_board.md`) is
  not built yet.
