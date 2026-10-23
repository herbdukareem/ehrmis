# Executive Office release and pilot

## Install

1. Back up the application database and private storage. Use a release copy of the existing application.
2. Deploy the new code, then run the additive module migration:

   ```sh
   php artisan migrate --path=database/migrations/2026_10_22_000000_create_executive_office_tables.php
   php artisan db:seed --class=ExecutiveOfficeSeeder
   npm ci
   npm run build
   php artisan optimize:clear
   ```

   When building on a separate machine, deploy the entire rebuilt `public/build` directory with its manifest. Do not run `migrate:fresh` or the full demonstration database seeder on an existing installation.

3. As a platform administrator, enable **Executive Office** for the intended MDA through existing module access management. This module is deliberately not enabled for every MDA by the seeder.
4. As the authorised MDA administrator, open **Executive Office → Office administration**. Save the official title, reference prefix, timezone, availability, and routine-appointment policy. The head title already configured in MDA settings is suggested.
5. Assign an executive, a lead PA, other PAs as needed, and registry/front-desk users. Every assignment is dated. Recipients of assigned actions/meeting invitations receive minimum recipient access; this does not make them office coordinators.
6. Refresh affected users' sessions so their new module-navigation permission is loaded. Operational access is still checked against current assignments on every request; cached navigation permissions never substitute for membership.
7. Ensure private storage is writable. Module documents live under the private `local` disk, not `public/storage`. Downloads are authenticated and re-check parent-record access.
8. Configure Laravel's scheduler using the hosting account's normal PHP executable:

   ```cron
   * * * * * cd /absolute/path/to/application && php artisan schedule:run >> /dev/null 2>&1
   ```

   The scheduled `executive-office:reminders` command runs every minute with overlap protection. It creates in-app reminders only; it does not send email or messages. Verify a single MDA with `php artisan executive-office:reminders --mda=ID`. Use the actual numeric MDA ID.

## Pilot scenarios

Use authorised pilot accounts representing the executive, lead PA, PA, action officer, registry user, and setup administrator.

- Confirm a routine appointment; verify a sensitive/out-of-hours request requires executive authority. Attempt an overlapping confirmation. Verify a restricted diary entry shows only an unavailable time block to a PA.
- Register both an expected visitor and a walk-in, then record arrival, departure, outcome, and whether the executive received them. Check that reception cannot open meeting briefs or PA notes.
- Register an incoming letter, add a PA preparation note, submit it for decision, refer it to an officer, submit a response, return it for correction, and accept the revised response.
- Prepare an outgoing letter. Confirm a PA cannot approve it or dispatch an unapproved version. Approve it, reopen it with a reason, revise and reapprove it, then record dispatch/acknowledgement. Verify the earlier approval snapshot remains intact.
- Prepare several actions from one decision. Verify a PA creates drafts and only executive authority issues them. Check that a recipient cannot see a restricted source merely because an action is linked to it.
- Convert a confirmed appointment into a meeting. Check invitations/RSVP, attendance, minutes submission, return, approval, and subsequent action points without a duplicate diary entry.
- Prepare and publish daily briefings, download PDF packs and filtered Excel reports, and reconcile counts with the visible register. Inspect long content, page breaks, leading-zero references, and confidential notes in printed output.
- Run reminders twice. Verify no duplicates, correct deadlines/timezone, read/unread behaviour, and removal of access after membership/delegation expiry or revocation.
- Delegate limited dated authority, perform a permitted action, and verify a nondelegated decision is rejected. Revoke the grant and retry. Check historical actions identify the actor and authority used.
- Prepare a handover pack, transfer responsibilities to a replacement, and verify outgoing access ends, open responsibilities transfer, and original authorship remains unchanged.
- Archive a completed record, find it in the archive, and reopen with a reason. Try submitting an old record version from a second browser tab; it must fail instead of overwriting newer work.
- Use another MDA's test account and direct URLs to verify isolation for records, notes, private files, notifications, reports, and exports.

The pilot is complete only after the MDA's users perform these scenarios and resolve any operational issues. Automated tests do not constitute live user acceptance.

## Verification and limitations

Run the Executive Office suite plus tenancy/module/access-management regression tests and `npm run build`. The existing TenantIsolation/Billing/Subscription filters may contain no tests in this HR repository; record that accurately. Browser smoke checks use an isolated local fixture server; backend feature tests exercise real Laravel routes, database transactions, files, PDF generation, and Excel export using SQLite.

Production concurrency and query performance must also be checked on the deployment database engine with realistic volumes. Office rows serialize reference generation, diary confirmations, handover, and workflow updates; version checks reject stale submissions. SQLite tests alone cannot prove production database locking behaviour.

Calendar sync, external messaging, OCR, inter-MDA routing, multiple offices per MDA, and automatic execution of HR/budget/procurement/workplan approvals remain outside this release. Relevant existing business references can be recorded in correspondence; Executive Office decisions do not replace those modules' authorisation.

## Rollback

Disable the module for the pilot MDA first. Retain its database tables and private documents, then restore the previous application/build release if required. This preserves office records and audit history. Do not drop the module migration after real use unless a separately verified backup and explicit data-removal decision exist. Disabling the module also causes the reminder command to skip that MDA.
