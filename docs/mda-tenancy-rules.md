# MDA Tenancy Rules

This system is MDA-scoped. Treat these rules as mandatory whenever you add or change backend, frontend, imports, approvals, reports, or settings code.

## 1. Use `mda_id`, not `tenant_id`

- eHRMIS tenancy is based on `mda_id`.
- Do not introduce a generic `tenant_id` abstraction for MDA-owned data.
- New MDA-owned tables should store `mda_id` directly unless they are safely scoped through an MDA-owned parent.

## 2. Ordinary MDA users only access assigned MDA data

- A normal MDA user must only see, edit, approve, import, export, or report on data from their assigned MDA.
- If a user has no global access and no assigned MDA access, fail closed.

## 3. Platform Admin / Super Admin / MIS Admin can access all MDAs

- Global platform actors are allowed to work across all MDAs.
- In code, this is represented by `User::hasGlobalMdaAccess()`.

## 4. State/platform access grants all-MDA access

- Users with explicit `platform` or `state` access scopes are treated as global MDA users.
- Do not re-implement this rule ad hoc in controllers or services. Use the existing user helpers.

## 5. Explicit multi-MDA access grants only selected MDAs

- Users with explicit `mda` access scopes may access only the MDAs assigned to them.
- Use `User::accessibleMdaIds()` when you need the resolved MDA list.
- Use `User::scopeToAccessibleMdas()` when you need to constrain a query to the current user's visible MDAs.

## 6. Use `User::canAccessMda()` for record-level checks

- For record-level authorization, use `User::canAccessMda($mdaId)`.
- Policies for MDA-owned records should delegate to this helper instead of duplicating scoping logic.
- Controllers should not trust hidden UI state, route choices, or domain branding to decide access.

Example:

```php
abort_unless($request->user()->canAccessMda((int) $workbook->mda_id), 403);
```

## 7. Use visible-MDA helpers for dropdowns and options

- Dropdowns, filter options, and lookup endpoints must only return MDAs and child records the user can actually access.
- Use `Mda::query()->visibleToUser($user)` for MDA lists.
- Use `User::scopeToAccessibleMdas($query, 'mda_id')` for MDA-owned record lists.
- If a child model is scoped through a parent, constrain the parent relation to the allowed MDAs.

Example:

```php
$mdas = Mda::query()
    ->visibleToUser($user)
    ->orderBy('name')
    ->get(['id', 'code', 'name']);
```

## 8. Avoid raw queries without explicit `mda_id` filtering

- Raw queries, query builder joins, aggregates, and exports do not get Eloquent model scopes automatically.
- If the data is MDA-owned, add explicit `mda_id` filtering yourself.
- This is especially important in reports, dashboards, imports, exports, and statistics endpoints.

## 9. Be careful with `withoutGlobalScopes()`

- `withoutGlobalScopes()` is allowed only when there is a clear reason.
- If you bypass scopes for an MDA-owned model, re-apply an explicit MDA constraint in the same query.
- Never use scope bypassing as a shortcut around authorization.

Preferred pattern:

```php
$staff = Staff::query()
    ->forMda($mdaId)
    ->where('staff_number', $staffNumber)
    ->first();
```

## 10. New MDA-owned features must include tenancy tests

- Every new MDA-owned feature should ship with tenancy coverage.
- At minimum, test:
  - own-MDA access works
  - cross-MDA access is forbidden
  - global users can access all MDAs where intended
  - multi-MDA users only access assigned MDAs
  - dropdowns, filters, summaries, and exports are MDA-safe

## Practical checklist

- Does the table store `mda_id`, or is it safely scoped through an MDA-owned parent?
- Does the model use the existing MDA scope or an explicit MDA filter?
- Does the policy or controller call `canAccessMda()` for record-level access?
- Are dropdowns and option loaders limited to visible MDAs?
- Are raw queries and joins explicitly filtered by `mda_id`?
- If `withoutGlobalScopes()` is used, is the replacement MDA filter in the same query?
- Did you add tenancy tests before considering the feature complete?
