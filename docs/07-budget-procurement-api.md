# Budget and Procurement API

All endpoints below use `/api/budget-procurement`. They require session authentication,
the `budget_procurement` module enabled for the target MDA, and the listed permission.
Record endpoints use MDA-scoped route binding. Related IDs are validated against the
record's MDA, even when the caller can access multiple MDAs.

List endpoints return Laravel pagination (`data`, `current_page`, `per_page`, `total`).
They accept `mda_id`, `status`, and `per_page` (1-100, default 25). Transactions do not
accept `status`. Without an MDA filter, results include accessible MDAs with this module
enabled. Detail and action responses wrap their result in `data`.

Lists also accept `search`, which searches record names, descriptions and reference
numbers while retaining MDA restrictions. GET `/options` returns enabled accessible
MDAs and lookup records for the selected `mda_id`. Budget lookups require
`view-budget-cycles`; procurement lookups require `view-procurement`; workplan lookups
additionally require `view-workplans`.

## Screens

On an existing database, install the module catalog entry, default MDA assignments and
standard-role permissions using `php artisan db:seed --class=BudgetProcurementAccessSeeder`.
This targeted seeder adds missing setup without changing explicit disabled-module
settings, custom roles, existing template customisations, or unrelated permissions.
Reload the SPA after running it so `/api/me` refreshes the navigation permissions.

The SPA navigation contains a Budget & Procurement group. Budget management starts at
`/budget-procurement/cycles`, with tabs for allocations, accounts, releases, adjustments,
commitments and the ledger. Procurement screens use `/budget-procurement/plans`,
`/requisitions`, `/vendors`, `/solicitations`, `/awards`, `/purchase-orders` and `/receipts`
under the same prefix. Records with detail endpoints open at `/{id}`; bid detail opens
from sourcing. MDA selection, search, filters and pagination are represented in the URL.
Workflow actions appear according to the current record stage and the user's permissions.

## Budget

| Method | Path | Permission |
| --- | --- | --- |
| GET | `/cycles`, `/cycles/{cycle}` | `view-budget-cycles` |
| POST | `/cycles`, `/cycles/{cycle}/submit` | `create-budget-cycles` |
| POST | `/cycles/{cycle}/approve` | `approve-budget-cycles` |
| GET | `/accounts`, `/allocations`, `/allocations/{allocation}` | `view-budget-cycles` |
| POST | `/accounts`, `/cycles/{cycle}/allocations` | `manage-budget-allocations` |
| POST | `/allocations/{allocation}/approve` | `approve-budget-cycles` |
| GET | `/releases`, `/adjustments`, `/commitments`, `/transactions` | `view-budget-cycles` |
| POST | `/allocations/{allocation}/releases`, `/releases/{release}/post` | `manage-budget-releases` |
| POST | `/allocations/{allocation}/adjustments` | `manage-budget-adjustments` |
| POST | `/adjustments/{adjustment}/approve` | `approve-budget-cycles` |

Create a cycle with `mda_id`, `year`, and optional `name`. The cycle starts in `draft`,
then moves through `submitted` to `approved`. Cycle years and account codes are unique
per MDA. Create an account with `mda_id`, `code`, `name`, `account_type`, and optionally
`parent_id`, `description`, `sort_order`.

Create an allocation under a cycle using `budget_account_id`, `title`, `approved_amount`,
and optionally `department_id`, `workplan_activity_id`, `description`. Approval posts the
allocation to the ledger. Allocation detail includes `balances`: allocated, released,
committed, actual, unreleased, available, and released available amounts.

Releases require `amount`, `release_date` (`YYYY-MM-DD`), and optional `notes`. Adjustments
require `adjustment_type` (`increase` or `decrease`), `amount`, and `reason`. They start in
draft and only affect balances after posting/approval. Closed or locked cycles cannot be
used for new postings. Commitments are created by requisition approval and liquidated by
receipt posting; their list and ledger entries are read-only through this API.

## Procurement

Every GET below requires `view-procurement`.

| Method | Path | Write Permission |
| --- | --- | --- |
| GET, POST | `/plans` | `manage-procurement-plans` |
| GET | `/plans/{plan}` | |
| POST | `/plans/{plan}/lines`, `/plans/{plan}/import-workplan`, `/plans/{plan}/import-allocations` | `manage-procurement-plans` |
| GET, POST | `/requisitions` | `create-requisitions` |
| GET | `/requisitions/{requisition}` | |
| POST | `/requisitions/{requisition}/lines`, `/requisitions/{requisition}/submit` | `create-requisitions` |
| POST | `/requisitions/{requisition}/review-budget` | `review-budget-availability` |
| POST | `/requisitions/{requisition}/approve` | `approve-requisitions` |
| GET, POST | `/vendors` | `manage-vendors` |
| GET | `/vendors/{vendor}`, `/solicitations`, `/solicitations/{solicitation}`, `/bids/{bid}` | |
| POST | `/requisitions/{requisition}/solicitations` | `manage-sourcing` |
| POST | `/solicitations/{solicitation}/publish`, `/solicitations/{solicitation}/close`, `/solicitations/{solicitation}/bids` | `manage-sourcing` |
| POST | `/bids/{bid}/evaluate`, `/evaluations/{evaluation}/recommend` | `evaluate-bids` |
| GET | `/awards`, `/awards/{award}` | |
| POST | `/awards/{award}/approve` | `approve-awards` |
| POST | `/awards/{award}/purchase-order` | `manage-purchase-orders` |
| GET | `/purchase-orders`, `/purchase-orders/{purchaseOrder}`, `/receipts`, `/receipts/{receipt}` | |
| POST | `/purchase-orders/{purchaseOrder}/receipts`, `/receipts/{receipt}/items`, `/receipts/{receipt}/post` | `record-procurement-receipts` |

Create a plan with `mda_id`, `year`, `title`, optional `budget_cycle_id`, `description`.
Import approved workplan activities with `workplan_id`; import approved allocations with
an optional `budget_cycle_id` (defaults to the plan cycle). Imports must match MDA/year.
Manual plan lines and requisition lines require `item_description`, `estimated_amount`;
optional fields include `quantity`, `unit`, `estimated_unit_cost`, `specification`,
`department_id`, `budget_allocation_id`, `notes`. A requisition line can also reference
`procurement_plan_line_id`. A manual plan line can reference `workplan_activity_id` and
set `procurement_method`, `planned_start_date`, `planned_end_date`.

Create a requisition with `mda_id`, `title`, optional `procurement_plan_id`,
`budget_cycle_id`, `department_id`, `description`, `justification`. Budget cycle defaults
to the linked plan cycle. Submit before approval. Approval reserves mapped budget funds.

Create a vendor with `mda_id`, `name`, optional `vendor_type`, `registration_number`,
`tax_identification_number`, `contact_person`, `email`, `phone`, `address`. Solicitations
require `procurement_method` (`direct_purchase`, `rfq`, `selective_tender`, `open_tender`,
`emergency_procurement`); optional fields are `title`, `description`, `issue_date`,
`closing_at`. Bids require `vendor_id`, `quoted_amount`, optional `valid_until`, `notes`.
Evaluated bids cannot be overwritten through the bid-receiving endpoint.

Evaluation accepts `technical_score`, `financial_score`, `total_score`, `rank`,
`recommendation_notes`, `criteria_scores`. Recommendation accepts optional `summary`.
An approved award generates one purchase order. Repeating generation returns the existing
order and preserves receipt progress. Optional PO fields: `title`, `order_date`,
`expected_delivery_date`, `terms`.

Receipts accept optional `received_date`, `delivery_note_no`, `notes`. Receipt items
require `purchase_order_item_id`, `quantity_received`, `quantity_accepted`, optional
`quantity_rejected`, `remarks`. All quantities must be accounted for; the item must belong
to the receipt's purchase order. Values are derived from the order, not supplied amounts.
Posting receipts records expenditure and updates delivery status; it does not record
supplier payment.

## Workflow Actions

All actions below are POST requests and require a non-blank `reason` (maximum 5,000
characters). Permissions and exact MDA checks also apply when the caller has multi-MDA
access. Invalid transitions return 422. No additional permissions or schema migration
are needed on existing installations.

| Path | Permission | Allowed Stage |
| --- | --- | --- |
| `/requisitions/{requisition}/return`, `/reject` | `approve-requisitions` | submitted, budget_reviewed |
| `/requisitions/{requisition}/correct` | `create-requisitions` | draft, returned |
| `/requisitions/{requisition}/cancel` | `approve-requisitions` | draft, returned, submitted, budget_reviewed, approved |
| `/requisitions/{requisition}/close` | `approve-requisitions` | approved; all orders terminal, at least one closed |
| `/awards/{award}/reject` | `approve-awards` | recommended |
| `/plans/{plan}/amend`, `/close` | `manage-procurement-plans` | draft, approved |
| `/purchase-orders/{purchaseOrder}/amend`, `/cancel` | `manage-purchase-orders` | issued, partially_received |
| `/purchase-orders/{purchaseOrder}/close` | `manage-purchase-orders` | received; all quantities accepted |
| `/receipts/{receipt}/cancel` | `record-procurement-receipts` | draft |

Returned requisitions can be corrected and resubmitted through the existing `/submit`
endpoint. Corrections accept `title`, `description`, `justification`, and optional
`line_id` with `item_description`, `specification`, `quantity`, `unit`,
`estimated_unit_cost`, `estimated_amount`, `notes`. Use `line_id` with
`remove_line: true` to remove a draft/returned line. Budget review is invalidated and
approval performs a fresh availability check. Rejected requisitions are terminal.
Rejected awards may be recommended again; the old rejection remains in the audit log.

Plan amendments accept `title`, `description`, and optional `line_id` with the line
fields above. Used plan lines cannot be rewritten, including through re-imports.
Order amendments accept `title`, `terms`, `expected_delivery_date`, and optional
`item_id` with `quantity`, `unit_price`. Item IDs must belong to the bound parent.
Order totals cannot exceed the approved award or remaining commitments; accepted
quantities cannot be reduced and received goods cannot be repriced. Draft receipts
must be posted or cancelled first. Financial increases beyond the award require a
separate revised approval; this endpoint does not grant new spending authority.

Order cancellation also cancels its draft receipts, never posted receipts. Cancellation
and closure release only outstanding, unused commitments, retaining reservations needed
by other active orders. Pending sourcing without an order retains its envelope until
the requisition is cancelled/closed. Cancel/close existing orders before cancelling a
requisition. Requisition cancellation terminates its remaining sourcing and awards.
Posted expenditure is retained; no supplier payments are reversed. Repeating cancel or
close is idempotent. Closing a plan requires all its requisitions to be terminal.

Plan, requisition, award, purchase-order and receipt detail responses include `history`
from the existing audit log, with action, reason, actor, timestamp and before/after
snapshots. The screens show this history with expandable changes. Closed/cancelled
records cannot be amended or used to create further receipts or requisitions.

## Supplier Invoices and Payments

Apply `php artisan migrate` and rerun the additive
`php artisan db:seed --class=BudgetProcurementAccessSeeder` on existing installations.
The new navigation entries are `/budget-procurement/supplier-invoices` and
`/budget-procurement/supplier-payments`. Reload the SPA to refresh permissions.

Reads require `view-supplier-payables`, independently of `view-procurement`.
Budget officers can prepare invoices/payments; approval officers have the separate
`approve-supplier-invoices` and `approve-supplier-payments` permissions. Custom roles
and customized templates are not automatically expanded by the additive access seeder.

| Method | Path | Permission |
| --- | --- | --- |
| GET | `/supplier-invoices`, `/supplier-invoices/{invoice}`, `/supplier-payments`, `/supplier-payments/{payment}` | `view-supplier-payables` |
| POST | `/supplier-invoices`, `/supplier-invoices/{invoice}/update`, `/items`, `/remove-item`, `/submit` | `manage-supplier-invoices` |
| POST | `/supplier-invoices/{invoice}/approve`, `/return`, `/reject`, `/cancel` | `approve-supplier-invoices` |
| POST | `/supplier-invoices/{invoice}/payments`, `/supplier-payments/{payment}/submit`, `/mark-paid`, `/cancel` | `record-supplier-payments` |
| POST | `/supplier-payments/{payment}/approve`, `/reject` | `approve-supplier-payments` |

Create an invoice with `mda_id`, `purchase_order_id`, `invoice_no`, `invoice_date`,
`total_amount`, optional `due_date`, `notes`. Supplier and currency come from the order.
Invoice numbers are normalized to uppercase and unique per supplier/MDA. Invoice dates
cannot be in the future; due dates cannot precede invoice dates. Invoices can cover
accepted deliveries on closed or cancelled orders, because those deliveries remain payable.

Match items with `procurement_receipt_item_id`, `quantity`, optional `unit_price`.
Only posted, accepted receipts from this exact order/MDA qualify. Each receipt item
can appear once per invoice; repeating the match updates that draft line. Other active
invoices reserve the quantities/value they already claim, including drafts. Price cannot
exceed the order or receipt price. Line totals use decimal arithmetic and half-up rounding
to two places. The full declared invoice total must equal matched items before submission
and approval. This is accepted-delivery matching, not advance-payment, tax/withholding,
credit-note or multi-currency conversion accounting.

Draft/returned invoices can be updated (`invoice_no`, `invoice_date`, `total_amount`, `due_date`, `notes`, `reason`)
or have lines removed with `item_id`. Returns/rejections/cancellations require `reason`.
Approved invoices are immutable. Cancel associated payment requests first; an invoice
with paid payments cannot be cancelled. Cancelled/rejected invoices free their receipt
reservations and have no outstanding payable balance.

Create a partial payment with `amount`, `method` (`bank_transfer`, `cash`, `cheque`,
`other`), `request_key` (UUID), optional `notes`. GET `/options` supplies a new request
key for the UI; retain that same key when retrying a failed creation request. Repeating
the key with the same invoice/amount/method returns the same record. Only approved
invoices qualify. Payment amount, supplier, currency and invoice are immutable.

Payments move through `draft -> submitted -> approved -> paid`. A different authorized
officer must approve a payment than the officer who prepared it, including admins.
Submitted and approved payment requests reserve invoice balance. Rejection/cancellation
releases that reservation. `mark-paid` requires a MDA-unique `payment_reference` and
`paid_date`, no earlier than the invoice date or later than today. Retries with the same
settlement details are idempotent; paid records cannot be cancelled or overwritten.

The app records externally completed payments; it does not initiate bank transfers.
Receipt posting continues to recognize expenditure and liquidate commitments. Invoice
approval and payment settlement never post expenditure again or liquidate more budget.
Invoice/payment history uses the existing auditable before/after snapshots.

Invoice list filters include `vendor_id`, `purchase_order_id`, `payment_status`
(`unpaid`, `partially_paid`, `paid`) and `overdue=1`, in addition to normal search/status/
pagination/MDA filters. Payment lists accept `supplier_invoice_id`, `vendor_id`.
Invoice balances distinguish paid, outstanding, pending payment reservations and available
for payment. Approval alone is not payment. Payment date/reference are separately recorded.

## Reports, Dashboard and Exports

Open `/budget-procurement/reports` from **Budget & procurement > Reports & dashboard**.
No migration or new permission is required for reporting. Existing read permissions
control which dashboard sections and report tabs appear; downloading additionally
requires `export-procurement-reports`. Custom roles are not expanded automatically.

All API paths below are relative to `/api/budget-procurement`:

| Method | Path | Read permission |
| --- | --- | --- |
| GET | `/reports/options`, `/reports/dashboard` | Any of the three report read permissions |
| GET | `/reports/budget-utilization` | `view-budget-cycles` |
| GET | `/reports/procurement-tracking` | `view-procurement` |
| GET | `/reports/supplier-balances`, `/reports/payment-history` | `view-supplier-payables` |
| GET | `/reports/{reportType}/export/{format}` | Corresponding read permission **and** `export-procurement-reports` |

Filters: required `mda_id`, optional `vendor_id`, `year`, `date_from`, `date_to`.
Dates use ISO `YYYY-MM-DD`; timestamp boundaries use Africa/Lagos. By default the
period is January 1 through today, or the selected financial year. Explicit dates
and the financial year are independent filters. Periods cannot exceed ten years.
Year follows the linked budget cycle/plan; unlinked requisitions use their creation
year and unlinked orders use their order date. Suppliers must belong to the exact
selected MDA even for multi-MDA users. Disabled modules and inaccessible MDAs are
rejected. Options return only enabled accessible MDAs and permitted reports.

Supplier filters cannot apportion shared MDA allocations: budget reports reject
them, and the dashboard omits its budget section when a supplier is selected.
Finance-only users cannot retrieve procurement/budget summaries or export those
reports. Every tenant-owned base query and raw ledger aggregation is MDA-filtered.

Report calculations:

- Budget utilization shows closing ledger balances through `date_to`, including
  opening activity before `date_from`. Allocated includes adjustments; committed
  includes releases/liquidations; available = allocated - committed - expenditure.
  Released funds are reported separately, not added to allocations. Period flows
  and opening available balance are returned in the summary. Ledger calendar dates
  are authoritative. Budget currency is NGN.
- Procurement tracking covers requisitions raised within the selected period.
  Sourcing, approved awards and orders are counted through the cutoff. Workflow
  statuses are **current**, not historical snapshots. Delivery is the mean accepted
  fraction of order lines, capped at 100%, using posted receipts through the cutoff;
  quantities with different units are not added together. The summary weights each
  ordered line equally. Later order amendments are not reconstructed historically.
- Supplier balances include all invoices approved through the cutoff, retaining
  older liabilities irrespective of the period start. Settlements after the cutoff
  are excluded, even when an invoice is now fully paid. Actual `paid_date` governs
  settlement reporting, including backdated settlements. Aging uses the cutoff,
  not today's date. Undated invoices share the not-due bucket. Period-approved and
  period-paid activity are separately summarized. Cancelled invoices are excluded
  from their cancellation date onward; draft/rejected invoices are not payables.
- Payment history selects payments prepared, approved or settled in the period.
  It includes approval dates/names and actual settlement dates/references. Status
  is current; the settled total counts only settlements inside the selected period.
  Approved-pending totals describe the **selected activity records** pending at
  cutoff, not all opening unpaid approvals in the MDA. Payment is never counted as
  receipt expenditure a second time.

Preview accepts `page` and `per_page` (1-100, default 25). Summaries cover the entire
filtered result, not just the preview page. Money uses decimal arithmetic and is
never combined across currencies. Responses contain `columns`, `rows`, `summary`
and pagination/filter metadata. The UI disables export while filters are unapplied.

Export formats are `xlsx` and `pdf`. Both use the same report calculations and
include filter metadata and calculation basis. Export ignores preview pagination;
limits are 10,000 Excel rows and 1,000 PDF rows. Exceeding a limit returns 422 and
requires narrower filters; records are never silently truncated. Excel identifiers
and user text are explicitly bound as strings to prevent formula execution. Zero
values are retained and amounts beyond Excel's precision are preserved as text.
PDF uses landscape A3 with repeated column headers and page numbers. Successful
exports create `report.exported` audit events containing MDA, filters, format and
row count. The exports download directly; they are not placed on public storage.

Verification: reporting feature tests are in `BudgetProcurementApiTest`; the
browser smoke test exercises all five report views, pagination, unapplied-filter
locking, MDA changes, downloads/error handling and mobile/restricted-access states.
Set `BP_REPORT_ARTIFACTS=1` when running the export test to retain example PDF/XLSX
files under private `storage/app/private/ui-checks/budget-procurement` for visual QA.

## In-App Notifications and Reminders

Open `/budget-procurement/notifications` or the header bell. Apply `php artisan migrate`
on deployment; the additive `procurement_alert_reads` table stores only per-user read
state. The migration has been applied to the local database. No new role grants or
permission seeding are required.

Alerts are a live operational inbox, derived from current records when requested.
They do not require cron, a queue worker, mail configuration or a broadcast server.
Resolved conditions automatically disappear; the existing record audit history remains
the permanent history of returns/rejections. These are in-app alerts only, not emails
or bank/payment execution.

| Method | API path relative to `/api/budget-procurement` | Purpose |
| --- | --- | --- |
| GET | `/notifications` | Paginated current alerts, categories, counts and enabled accessible MDAs |
| GET | `/notifications/summary` | Selected-MDA unread badge and total counts |
| POST | `/notifications/read` | Set personal read/unread state for `keys` (1-100 alert IDs) with boolean `read` |
| POST | `/notifications/read-all` | Mark all currently visible alerts in the selected filters as read, across all pages |

Optional filters: `mda_id`, `category` (`approvals`, `corrections`, `invoices`,
`deliveries`, `budget`), `unread=1`, `page`, `per_page` (maximum 100, default 25).
Read-state requests never accept another user's ID. The server recomputes visibility
before writing and returns 404 for forged, resolved or no-longer-permitted alert IDs.
Every request checks the user's MDA access and the module's enabled state. Omitting
MDA chooses the first enabled accessible MDA, including for users whose primary MDA
has the module disabled. Counts always describe the selected MDA, never another MDA.

Visibility and calculation rules:

- Pending requisitions (`submitted`/`budget_reviewed`), recommended awards and
  submitted invoices/payments require the corresponding record read permission AND
  its approval permission. A payment's preparer never receives its approval reminder.
- Returned/rejected records require record read permission, plus ownership or the
  relevant preparation/management permission. Reasons are taken from scoped workflow
  audit entries, with stored rejection reasons as a legacy fallback. New submissions,
  recommendations and returns get distinct alert identities via audit IDs, including
  transitions within the same timestamp second.
- Overdue invoices require `view-supplier-payables`. Only approved invoices with a
  due date before today in Africa/Lagos and a positive unpaid balance qualify. Partial
  payments reduce the displayed outstanding amount; approved-but-unsettled payments
  do not. Fully settled/cancelled invoices stop producing overdue alerts.
- Incomplete deliveries require `view-procurement`. Active issued/partially-received
  orders qualify only while an order line has unaccepted quantity. Only accepted
  quantities on posted receipts count. Different units are not summed. Orders past
  their expected delivery date are flagged overdue; undated/future orders remain
  informational incomplete-delivery alerts. Fully accepted, cancelled and closed
  orders disappear.
- Low budget requires `view-budget-cycles`. Approved allocations on writable cycles
  qualify when available funds are at or below the configured percentage of ledger
  allocation. Available = allocated - commitments - expenditure, using the same
  calculation as reports. Releases are not added to allocations. Closed/locked cycles
  and non-positive allocations are excluded. Exhausted funds are urgent; newer ledger
  activity refreshes the alert identity. Amount and threshold comparisons use decimals.

The header badge and open inbox refresh every minute while visible; the inbox also
refreshes on tab visibility changes, and the bell on window focus. Notification
background failures never replace an unrelated working screen with a global error.
The inbox supports category filtering, all/unread views, marking read/unread, filtered
mark-all-read, pagination and links to the original record with its MDA context.
Read status is persisted independently for each user and MDA and does not change any
approval, payment or ledger state.

Defaults in `config/procurement_alerts.php`:

```dotenv
PROCUREMENT_LOW_BUDGET_PERCENT=10
PROCUREMENT_REMINDER_DAYS=3
```

Read unresolved approvals, returned records, overdue invoices, incomplete deliveries
and low-budget alerts become unread reminders after the configured number of days
since last being marked read (minimum one day). Rejected terminal records do not
repeat. Unread alerts are not duplicated on each poll. Resolved alerts do not reappear
as reminders. Refresh the configuration cache when changing environment settings.

`ProcurementNotificationsTest` covers visibility, maker/approver separation, audit
reasons, repeated submissions/returns, per-user state, reminders, Lagos due dates,
partial payments, accepted deliveries, budget thresholds, disabled modules, multi-MDA
access and forged read requests. The browser smoke test covers the inbox, header
badge, read/unread controls, filters, pagination, record links and mobile layout.

## Documents

- POST `/{targetType}/{targetId}/documents`: `targetType` is `vendors`, `solicitations`,
  or `bids`. Vendor documents require `manage-vendors`; other targets require
  `manage-sourcing`.
- GET `/documents/{document}/download`: requires `view-procurement`; streams a stored
  file or returns `data.external_url` for a link.
- DELETE `/documents/{document}`: requires the target's write permission.

Attachments require `title` and either `file` (maximum 10 MB) or `external_url` (HTTP/S).
Optional fields: `document_type`, `notes`. Files are stored on the private local disk.

Actor IDs, workflow status, approval timestamps, financial source IDs and scoring fields
outside the evaluation endpoint are server-controlled. Validation errors return 422;
permission/module failures return 403; inaccessible record IDs return 404.
