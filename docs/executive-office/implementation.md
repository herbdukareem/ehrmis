# Executive Office implementation

One office per MDA. The office owns its records. Phase completion requires working backend and UI plus verification, not just tables or placeholder pages.

| Phase | Scope | Status |
| --- | --- | --- |
| 1 | Workflows, module activation, office foundation, explicit roles, navigation | Implemented; 4 foundation tests / 18 assertions and frontend build passed |
| 2 | Team, confidentiality, private attachments, versions, notes, links, history | Implemented; foundation/record tests 7 passed / 45 assertions; frontend build passed |
| 3 | Diary, appointment policy, conflicts, visitors, reminders | Implemented; 10 Executive Office tests / 66 assertions and frontend build passed |
| 4 | Incoming/outgoing correspondence, briefs, approval, dispatch | Implemented; 2 correspondence tests / 18 assertions and frontend build passed |
| 5 | Decisions, referrals, directives, departmental responses, completion review | Implemented; 2 directive tests / 21 assertions and frontend build passed |
| 6 | Meetings, agendas, participants, attendance, minutes, linked actions | Implemented; 2 meeting tests / 18 assertions and frontend build passed |
| 7 | Role dashboards, daily briefings, printable packs, reports, notifications | Implemented; 3 reporting tests / 19 assertions and frontend build passed |
| 8 | Delegation, immediate revocation, handover, archive, reopening, concurrency | Implemented; continuity and final hardening tests passed |
| 9 | Automated verification, deployment/rollback documentation, pilot preparation | Automated checks and release documentation complete; local database installation and live MDA pilot remain pending |

## Final verification

- Executive Office suite: **29 tests passed, 193 assertions**. Covers all six record types, dated roles, confidentiality, private attachment download/preview, office isolation, workflows, version checks, exports, reminders, delegation, handover, and archive/reopen.
- Combined Executive Office/module/access-management/tenancy regression run: **55 tests passed, 342 assertions** before the final visitor/progress/preview additions. The final module suite above verifies those additions.
- Required Tenancy filter: **6 tests passed, 70 assertions**. TenantIsolation, Billing, and Subscription filters returned **no tests found**, not additional passed tests.
- Final reporting/export check after adding bounded spreadsheet resources and long-text storage: **3 tests passed, 19 assertions**.
- Executive Office browser smoke: passed for executive, lead PA, action officer, registry, and administrator; includes diary views, decisions, delegation, Excel download, and mobile layout.
- Existing navigation browser smoke: passed for desktop/mobile navigation and non-blocking background polling.
- `npm run build`: passed. PHP formatting completed for the new module; `git diff --check` passed.
- Verification uses real Laravel feature tests on isolated SQLite and browser fixtures. Production-database locking and real user acceptance remain pilot checks; they are not claimed as completed.

## Local installation status

The configured application environment is local MySQL at `127.0.0.1:3306`. Migration-status checks both inside and outside the sandbox returned connection refused, and no MySQL listener was found. No migration or seeding was applied to the working database. Start the configured local database, then follow [deployment.md](deployment.md) to apply the single additive migration and module seeder. Enable the module for the intended MDA and assign actual office users through the UI.

The module stays opt-in. No real executive, PA, delegation, visitor, correspondence, or handover was created in an existing MDA by this implementation.

## Architecture notes

The implementation reuses MDA scoping, module activation, role templates, QDS components, and the existing audit service. Its record workflow checks office-specific dated membership and delegated authority; generic module permissions alone do not approve executive decisions. Existing approval workflows in other domains are unchanged.

Records are retained when archived; there is no automatic destructive retention job. Printable packs are generated from currently authorised content and carry the strongest included note/attachment classification. Spreadsheet text is bound explicitly to prevent formula execution. See the release guide for pilot and rollback procedures.

## Workflow contract

- Executive and delegated authority are checked against current office assignments on every request. Administrator setup permission alone grants no record-content access.
- Standard records are visible to the office team and explicitly assigned recipients. Office-restricted records exclude registry and action officers. Executive-only records require executive membership or explicit active delegation.
- Office/PA notes remain within the PA team and executive. Recipient instructions are explicitly marked recipients. Executive-only notes remain restricted independently of parent visibility.
- Appointments: requested/tentative → confirmed → completed, with decline/cancel/reschedule histories. Sensitive and out-of-hours bookings require executive confirmation. Overlaps cannot be confirmed.
- Incoming correspondence: received → awaiting decision → assigned/awaiting response → completed. Outgoing: draft → awaiting decision → approved → dispatched. Edits invalidate approvals. Approval snapshots and attachments remain retrievable.
- Directives: assigned → acknowledged → in progress/blocked → response submitted → completed or returned. Submission is not acceptance.
- Meetings: draft → confirmed → held → minutes submitted → approved or returned. Approved minutes are immutable until reopened for revision.
- Visitors: expected → arrived → departed, or cancelled. Walk-ins are supported. Attendance does not expose meeting briefs.
- Briefings: draft → published. Published content is versioned and restricted to authorised office users.
- Archive preserves linked history; reopening requires a reason. Domain links do not grant access to HR, procurement, budget, or workplan records or approve those workflows.
- Reminders are in-app, idempotent, re-check visibility at delivery/read time, and never include restricted subjects in an unauthorised feed.
- Acting cover is dated and limited. Permanent handover ends outgoing access and transfers responsibility, not historical authorship.

External messaging, external calendar sync, OCR, inter-MDA routing, and multiple offices per MDA remain outside this release.
