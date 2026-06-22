# EHRMIS (new-system) Documentation

This `docs/` folder documents the Laravel + Vue rewrite of the Niger State HRMIS, located at
the repository root (`new-system/`). It reflects the codebase as of June 2026 and is meant to be
the orientation point for anyone picking up the project — what exists, how it's wired together,
and what's still aspirational vs. actually built.

For the original planning documents (still useful for *intended* future scope), see the
repository root:

- `implementation_plan.md` — the original database-first architecture plan.
- `execution_board.md` — phased task breakdown (Phase A–F) for build-out, used to track
  what's done vs. outstanding at a feature level.

## Reading order

1. **[01-architecture.md](01-architecture.md)** — tech stack, module layout, request lifecycle,
   and a list of dead/unused code worth knowing about before you go looking for it.
2. **[02-auth-and-access.md](02-auth-and-access.md)** — login flow, the `User` model, roles &
   permissions, MDA-based multi-tenancy, and the Organization domain (Mda/Department/Station/
   Location) that tenancy is built on.
3. **[03-domain-staff.md](03-domain-staff.md)** — the Staff domain: models, salary calculation,
   document/media handling, the staff registry API.
4. **[04-domain-legacy-import.md](04-domain-legacy-import.md)** — the legacy-data import
   pipeline (normalize → validate → match → stage → approve → publish) and the generic Approval
   workflow it's built on.
5. **[05-domain-movement-and-budget.md](05-domain-movement-and-budget.md)** — Movement and
   Budget workbooks, and the cross-cutting Audit log.
6. **[06-frontend-spa.md](06-frontend-spa.md)** — the Vue SPA: routing, state, API client,
   views, components.

There is also **[flagged-issues-resolution.md](flagged-issues-resolution.md)**, a narrower
feature-level doc covering one specific change (the staff flagged-issues review/edit workflow)
in more implementation depth than the domain docs below go into.

## Known gaps (not yet built)

Per `execution_board.md`, the following phases are not implemented yet as of this writing:
HR workflows beyond staff CRUD (promotion/retirement/posting review modules, Phase D),
the reporting module (`ReportsView.vue` is a placeholder, Phase E), and most of Phase F
(budget cycle/prior-year baselines, detailed expenditure lines, queueing hardening, full
settings/audit UI). Treat anything described in those phases as a plan, not a fact, unless a
domain doc below says otherwise.
