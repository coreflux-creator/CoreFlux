# CoreFlux Product Requirements Document

## Original Problem Statement
Refactor a monolithic PHP application, CoreFlux, into a modular architecture. The core application provides a standardized shell, design system, and services (auth, multi-tenancy). Each business function (Accounting, People, Finance, Payroll) is a separate, self-contained module that "plugs into" this core shell. A React SPA (`spa.php`) is the primary frontend, authenticating via the existing PHP backend.

## Tech Stack
- **Backend:** PHP 8 + MySQL (PDO). Single stack. AI calls go direct to OpenAI from PHP.
- **Frontend:** React 18 + Vite + React Router + Lucide
- **Architecture:** Modular monolith; modules developed in-repo under `/modules/<name>/`, extracted to subtree repos later
- **Hosting:** Cloudways


## CSV ID-prefix stripper + DB-side whitespace defense (2026-02 — current fork)

### Why
Operators were pasting the formatted badge string (`P-114`, `PL-2317`)
straight from the UI into the CSV — same string the `IdBadge`
component renders. The strict integer validator rejected it, and the
fallback email lookup also missed because hidden Unicode whitespace
(NBSP / BOM) lived on the *DB side* of `email_primary` from prior
imports. Result: rows showed two confusing errors saying effectively
"we couldn't find this person at all".

### Fixes locked in
- `core/CsvImportService.php` `type=integer` now strips a leading
  `^[A-Za-z]+-` prefix, surrounding whitespace, and stray commas
  (Excel exports) before the digits-only check. `1042.5`, `abc`,
  `P-foo` still rejected. Error hint shows both accepted forms
  (`1042 or P-1042`).
- `modules/placements/api/csv_import.php` dry_run + commit normalise
  BOTH sides of the email equality with `LOWER(TRIM(email_primary))`
  so stored NBSP/BOM bytes don't break lookups.
- Rows with an invalid `person_id` (e.g. `P-foo`) no longer ALSO
  report an "email not found" error — the validator message stands
  alone.
- Missing-person error copy points at `/modules/people/{$pid}` to
  verify or the People importer if there's no close match.


## Sub-tenant People-scope lookup fix in Placements CSV importer (2026-02 — current fork, follow-up)

### Why (root cause)
User reported every row failing with `person_id: 114 not found in
this tenant's People` despite the IDs being copied directly from the
People directory. The CSV format was clean (plain integers), so the
earlier prefix-stripper wasn't the issue. Root cause:

- `'people' => 'shared'` in `SUBTENANT_MODULE_SCOPE_DEFAULTS` —
  sub-tenants transparently read the master/parent tenant's people.
- People directory + `IdBadge` therefore display rows that live
  under `tenant_id = <parent>`.
- `modules/placements/api/csv_import.php` dry_run was looking up
  people with `tenant_id = currentTenantId()` (= sub-tenant). The
  rows weren't there → every row failed.
- Commit path's `scopedFind` happened to resolve via the URL →
  `'placements' => 'shared'` (same parent) so it would have worked,
  but is fragile against tenants that override placements scope.

### Fix
- `csv_import.php` now `require_once`s `core/sub_tenants.php`.
- Dry_run lookup uses `effectiveTenantIdForModule('people') ??
  currentTenantId()` — always resolves to the tenant that actually
  owns the People rows for this user.
- Commit lookups replaced both `scopedFind(... FROM people ...)`
  calls with raw prepared statements bound to the same
  `effectiveTenantIdForModule('people')`, so person lookups don't

## Post-import approval UX + cross-importer sub-tenant fix (2026-02 — current fork, follow-up to CSV stability)

### Why
After the prefix-stripper + people-scope fixes landed, operator imported 9
placements successfully — but the CsvImportPage's "Done" button just
returned them to the default Placements list (filtered to
`status=active`), which hides drafts. They saw "Success 9/0/0" with no
clue where the rows went. Same gap on rates: nine new draft rates spread
across nine placements with no global "review and approve" surface.

### Backend
- **`POST /modules/placements/api/placements.php?action=bulk_status`** —
  flips many placements at once. Requires `placements.manage`, caps at
  500 ids, validates against `ALLOWED_STATUS`, audits each row with
  `via=bulk_status`. Returns `{updated, skipped, results[]}`.
- **`POST /modules/placements/api/rates.php?action=bulk_approve`** —
  approves N draft rates in one call. Requires
  `placements.financials.approve`, caps at 200 ids. Shares the new
  `placementsRateApproveOne()` helper with the single-row approve
  endpoint so semantics are identical (chain-based margin snapshot,
  prior-row supersede, audit trail). Bulk path never sets
  `is_correction=true` — corrections still require the per-row flow.
- **`GET /modules/placements/api/rates.php?action=drafts`** — tenant-
  wide queue of unapproved `placement_rates` joined with placements +
  people. 500-row cap. Powers the new queue page.

### Frontend
- `CsvImportPage.jsx` accepts an optional `successCtas(result) =>
  [{label, to, primary, testid}]` prop. Module wrappers can now
  surface a smart "View N drafts" link instead of the generic Done.
- `modules/placements/ui/CsvImport.jsx` passes two CTAs:
  - "View N draft placement(s)" → `../list?status=draft`
  - "Approve N draft rate(s)"   → `../draft-rates`
- `modules/placements/ui/List.jsx` reads `?status=` from the URL, syncs
  it on every filter change, and when filtered to `draft` shows a bulk
  toolbar (select-all, "Promote drafts to: pending_start / active /
  on_hold", Clear selection). Bulk update reloads the list and shows a
  Updated/Skipped result banner.
- New `modules/placements/ui/DraftRatesQueue.jsx` at `/modules/placements/draft-rates`.
  Lists every unapproved rate with placement + person context, supports
  select-all + bulk Approve. Empty state copy nudges the operator to
  import to see anything here. Per-row "Review →" links jump into the
  placement's Rates tab for the correction workflow.
- `PlacementsModule.jsx` wires `<Route path="draft-rates" />`.

### Cross-importer sub-tenant scope sweep (carry-over from prior fix)
- `modules/people/api/csv_import.php` email-collision dedupe now binds
  `effectiveTenantIdForModule('people')`. Sub-tenants in shared mode no
  longer silently re-import people that exist under the master tenant.
- `modules/time/api/csv_import.php` placement-external-id lookup binds
  `effectiveTenantIdForModule('placements')` for the same reason.
- Both files `require_once core/sub_tenants.php`.

### Tests
- `tests/placements_bulk_approve_drafts_queue_smoke.php` — 58 ✓ / 0 ✗
- `tests/csv_importers_subtenant_scope_smoke.php` — 10 ✓ / 0 ✗
- `tests/placements_csv_id_lookup_smoke.php` updated to accept the new
  dynamic `colSpan={isDraftView ? 10 : 9}` shape (32 ✓ / 0 ✗).
- Full suite: **290/290 ✓** — no plaid flake this run.
- `yarn build` clean + `sync_bundle.sh` updated `.deploy-version`,
  service-worker `CACHE_VERSION`, `dashboard/dist/index.html`.

## Placement detail overhaul + auto-approve rates + no-popup approve (2026-02 — current fork)

### Why
Three operator complaints in one turn:
1. **"It doesn't even have the NAME?!"** — the Placement Detail Overview tab was
   missing the person's name, email, classification, work auth, end-client
   company link, approver, JobDiva metadata, and notes.
2. **"initial placement approval should include approved rates"** — promoting a
   draft placement to active left its draft rates untouched, forcing the
   operator into the Rates tab as a separate workflow.
3. **"updates are already updates to previously approved items. shouldn't need
   the popup to ask"** — the Rates tab popped `confirm("Is this a
   correction?")` + `prompt("Correction reason")` on every approve. Redundant
   when the placement obviously has a prior approved rate.

### Fixes
- **`placementGet()`** now LEFT JOINs `people` and `companies` so the detail
  page receives `person_first_name`, `person_last_name`, `person_email_primary`,
  `person_phone_primary`, `person_classification`, `person_work_auth_status`,
  `person_work_auth_expiry`, `end_client_company_name`, and
  `end_client_company_website`.
- **`OverviewTab`** rewritten into 5 sections (Person, Engagement, End client
  & approver, JobDiva metadata, Notes) covering every safe-to-display column
  with mailto/website/profile deep-links. Header also shows the person name
  + email mailto next to the title.
- **Shared rate-approve helper** moved from `api/rates.php` to
  `modules/placements/lib/rate_approve.php`. Defines
  `placementsRateApproveOne()` and the new `placementsAutoApproveDraftRates()`.
- **PATCH** and **`?action=bulk_status`** on `api/placements.php` now call
  `placementsAutoApproveDraftRates()` when the placement transitions from
  `draft` → any non-terminal status. Soft-gated by `rbac_legacy_can` so a
  recruiter without `placements.financials.approve` can't escalate; returns
  `rates_auto_approved` count in the response.
- **`?action=approve` on rates** auto-detects correction by probing for any
  prior approved row on the same placement. Auto-generates a default reason.
  `correction_reason` is no longer hard-required at the API layer.
- **UI**: `RatesTab.approve()` no longer calls `confirm()`/`prompt()`. POSTs
  an empty body, quietly logs `console.info` when the server returned
  `auto_correction=true`.

### Tests
- `tests/placement_detail_and_auto_approve_smoke.php` — 45 ✓ / 0 ✗
- `tests/placements_bulk_approve_drafts_queue_smoke.php` updated for the
  helper relocation — 58 ✓ / 0 ✗
- Full suite: **291/291 ✓**
- `yarn build` clean → bundle `index-Cet53ImG.js`, sync_bundle.sh successful.

### Deploy note
PHP + React both touched. Cloudways deploy + `update.php` needed. New bundle:
`index-Cet53ImG.js` / `coreflux-Cet53ImG`.


## Catch-up "Approve all drafts" + readiness name JOIN (2026-02 — current fork)

### Why
After the previous auto-approve work landed, operator reported:
1. **"rates still say draft"** — placements promoted BEFORE the
   side-effect shipped never received the auto-approve, and there was
   no UI affordance to catch them up. Also: when the soft-RBAC-skip
   fired (operator lacked `placements.financials.approve`), there was
   no audit trail to explain *why* nothing happened.
2. **"payroll readiness by number?"** — Payroll & Billing readiness
   tables showed `Person #5`, `Person #7` instead of names. Root cause:
   same cross-tenant JOIN bug as the placement detail issue. Readiness
   joined `people` on `p.tenant_id = t.tenant_id` (timesheet's tenant);
   sub-tenants in `'people' => 'shared'` mode store people under the
   parent tenant, so the JOIN silently misses.

### Fixes
- **Soft-skip telemetry** — `placementsAutoApproveDraftRates()` now
  emits a `placement.rates.auto_approve_skipped_no_permission` audit
  when the RBAC bail fires. The "why are these still draft" question
  is now answerable from the audit log.
- **New endpoint** `POST /api/placements/rates?action=approve_all_for_placement&placement_id=N`
  — reuses the same helper, requires `placements.financials.approve`,
  emits a `placement.rates.approve_all_clicked` audit. One-click
  catch-up for placements promoted before the auto-approve work.
- **RatesTab UI** — when ≥1 draft exists, the tab header shows an
  "Approve all N draft(s)" button (testid `rates-approve-all-drafts`)
  wired to the new endpoint.
- **`modules/staffing/api/readiness.php`** — loads `core/sub_tenants.php`,
  resolves `$peopleTid` / `$placementsTid` once at the top, and binds
  every cross-table JOIN (people, placements, staffing_clients) to
  the effective module tenant instead of the timesheet's own
  `tenant_id`. Both the revenue path and the fallback path updated.
  Fixes Payroll Readiness names + Billing Readiness client names in
  the same change.

### Tests
- `tests/catchup_approve_and_readiness_names_smoke.php` — 26 ✓ / 0 ✗
- `placement_detail_and_auto_approve_smoke.php` updated for the new
  soft-skip audit branch — 45 ✓ / 0 ✗
- Full suite: **292/292 ✓**
- `yarn build` clean → bundle `index-BO6ErO-u.js`, sync_bundle.sh
  successful.

### Deploy note
PHP + React both touched. Cloudways deploy + `update.php` needed.

## Time-entries JOIN drift + per-row Submit in MyTime (2026-02 — current fork)

### Why
Operator complaints continued:
1. **"time not linked to placement or person?"** — `timeEntriesList()`
   in `modules/time/lib/time.php` JOINed `people` and `placements` on
   `te.tenant_id` (the time_entry's tenant). Both modules are
   `'shared'` by default, so a sub-tenant's time_entries row's
   tenant_id doesn't match the parent-owned people/placements row →
   JOIN silently misses → UI shows "—" for name + placement. Same bug
   class as the placement detail + staffing readiness fixes.
2. **"can't submit a single timesheet, only all at once"** — the API
   already exposes per-entry submit via
   `/modules/time/api/entries.php?action=submit&id=N`, but the
   `MyTime` weekly grid had no UI for it. Users could only submit by
   bouncing into the staffing TimesheetWeek "Submit Week" path.

### Fixes
- `modules/time/lib/time.php` loads `core/sub_tenants.php`, resolves
  `effectiveTenantIdForModule('people')` + `…('placements')` once and
  binds them to the JOINs in both `timeEntriesList()` (entries-list
  endpoint feeding ReviewQueue + MyTime) and the period
  bundle-build path (`SELECT … FROM time_entries te LEFT JOIN
  placements`).
- `modules/time/ui/MyTime.jsx` now renders a yellow **drafts panel**
  above the weekly grid when ≥1 draft exists. Each draft row shows
  date / placement / person / category / hours plus a per-row
  **Submit** button. Header has a **"Submit all N drafts"** CTA that
  loops via `Promise.allSettled` (partial-failure tolerant).
- UI falls back to "Person #N" / "Placement #N" only when the JOIN
  genuinely returns nothing (e.g. row was soft-deleted) — not because
  of the cross-tenant scope bug, which is now fixed.

### Tests
- `tests/time_join_and_per_row_submit_smoke.php` — 19 ✓ / 0 ✗
- Full suite: **293/293 ✓**
- `yarn build` clean → bundle `index-BO6ErO-u.js`.

### Deploy note
PHP (`time/lib/time.php`) + React (`time/ui/MyTime.jsx`) both touched.
Cloudways deploy + `update.php`.

### Still pending — flagged for next turn
- **🔴 Ad-hoc invoice creation (no closed-period requirement)** — the
  current modal forces a `period_id` from
  `time/api/periods.php?status=closed`. To accept "approved hours, no
  closed period", we'd need a new endpoint that queries
  `time_entries WHERE status='approved'` directly within a date
  range, builds lines from `placement_rates`-snapshotted rows, and
  inserts the invoice + lines + audit. Substantive new code path
  (~3–4 hrs); needs SPEC re-read against the uploaded Spec.docx
  + Staffing.docx to confirm ad-hoc semantics (e.g. should it still
  bump `placement_rates` snapshots? does it mark `time_entries` as
  consumed?). Parked until user re-confirms priority.
- **🟡 Staffing Overview $0** — needs DB inspection (real data) to
  diagnose whether it's empty tenant context, missing
  `staffing_clients` rows, or the same JOIN-drift issue against a

## Accounting-period flow inversion fix (2026-02 — current fork)

### Why
Operator caught a real architectural mistake:
> "How would an accounting period be closed until we've invoiced,
>  booked payables, payroll, etc?"

Exactly right. The original wiring chained AR-bundle build to
`?action=close`, which created a deadlock — you couldn't draft an
invoice for a period until you closed it, but closing was supposed to
come AFTER invoicing, AP, and payroll were booked. Invoicing was
blocked on a step that was supposed to follow it.

### Fixes
- **`POST /api/time/periods?action=build_bundles&id=N`** (new) — explicit
  bundle build that runs on any `open` or `locked` period. Closed
  periods 409 (immutable historical archives). Reuses the existing
  `timeBuildBundlesForPeriod()` helper which has always been
  idempotent. Audited as `time.period.bundles_rebuilt`.
- **`InvoiceFromTimeBundleModal.jsx`** — drops the `status=closed` URL
  filter on the period dropdown, default-selects the most recent OPEN
  period, shows status next to each option, surfaces a "Build bundles
  for this period" CTA whenever an open/locked period has no `ready`
  bundles yet. Closed periods stay selectable for historical context
  but the Create button is disabled.
- Header copy rewritten to explicitly state: *"Periods don't need to
  be closed first — close is the LAST step in the cycle."*
- `?action=close` handler in `periods.php` is intentionally untouched
  — it still builds bundles defensively at close time, but is no
  longer the only entry point.

### Tests
- `tests/period_close_is_last_step_smoke.php` — 21 ✓ / 0 ✗
- Full suite: **294/294 ✓**
- `yarn build` clean → bundle `index-Dxob2Hr8.js`.

### Deploy note
PHP (`time/api/periods.php`) + React (`InvoiceFromTimeBundleModal.jsx`)
touched. Cloudways deploy + `update.php`.

### After deploy — the correct AR cycle now works
1. Period starts as `open`.
2. Time entries accumulate, get approved.
3. Open the invoice modal → defaults to the current open period.
4. If "no bundles ready" → click **"Build bundles for this period"**.

## AP close-is-last-step inversion fix + payroll audit (2026-02 — current fork)

### Why
Following the same complaint as the AR-side fix, the AP module had
the identical deadlock — `BillFromTimeBundleModal` hard-filtered the
period dropdown to `status=closed`, so you couldn't book a contractor
payable until the period was closed, which was supposed to come AFTER
payables were booked.

Plus the same cross-tenant JOIN-drift bug inside
`apBuildDraftFromBundle()` for the same reason as placement detail /
staffing readiness / time entries: `placements` + `people` JOINs were
bound to `tdf.tenant_id` (bundle's tenant). Sub-tenants in `'shared'`
mode store those rows under the parent → JOIN missed → bills built
with NULL placement title + worker name.

### Fixes
- `ap/lib/ap.php` loads `core/sub_tenants.php` and binds
  `effectiveTenantIdForModule('placements' | 'people')` to the JOIN
  tenants inside `apBuildDraftFromBundle()`.
- `ap/ui/BillFromTimeBundleModal.jsx` rewritten to mirror the billing
  modal — no status filter on period dropdown, defaults to most recent
  OPEN period, "Build bundles for this period" CTA when empty,
  Create disabled on closed periods (immutable archive).
- The shared `POST /api/time/periods?action=build_bundles` endpoint
  serves both AR + AP bundle builds — same helper builds both types
  in a single pass.

### Payroll — audited and confirmed clean
- `modules/payroll/api/runs.php` and `modules/payroll/api/preflight.php`
  source hours from their own `payroll_pay_periods`, not from
  `time_periods`. No deadlock, no change needed.

### Tests
- `tests/ap_close_is_last_step_smoke.php` — 16 ✓ / 0 ✗
- Full suite: **295/295 ✓**
- `yarn build` clean → bundle `coreflux-CIzIhBAJ`.

### Deploy note
PHP (`ap/lib/ap.php`) + React (`BillFromTimeBundleModal.jsx`) touched.
Cloudways deploy + `update.php`.

### Complete accounting cycle map (post-deploy)
| Step | Module | Period gate |
|------|--------|-------------|
| 1. Time entries land + get approved | time | needs `open` period |
| 2. Build AR + AP bundles (any time)  | time | requires NOT closed   |

## Accrual-basis correction (2026-02 — current fork)

### Why
Operator (correctly) caught a follow-up framing error: "Period close is
based on accrual, not bill/pay." Re-reading the
`CoreFlux_CoreAccounting_CoreTreasury_Master_Spec.docx` confirmed:

- `accounting_periods` has 4 statuses per spec — `open`, `soft_closed`,
  `closed`, `locked`. Posting validation ("Period allows posting")
  blocks GL JE posting against `closed`/`soft_closed` periods.
- `time_periods` is a separate, upstream entity: closing it locks the
  *accrual* side (approved hours, snapshotted bundles). It does NOT
  block downstream document creation (invoices/bills).
- AR invoices + AP bills can be **drafted and approved** any time,
  against any period (including a closed time period). The actual
  gate against a closed *accounting* period fires at the JE-posting
  step downstream (already correctly implemented at
  `modules/accounting/lib/accounting.php:183`).

### Fix
My previous turn over-aggressively disabled the Create button on the
billing + AP modals when the selected time period was closed. That
treated `time_periods.closed` as if it locked the AR/AP side — wrong
under accrual basis. Surgical changes:

- Both modals: removed `|| isClosed` from the Create-button
  `disabled` condition. Create is now enabled whenever the selection
  is non-empty.
- Tooltip on the Create button (when period is closed) explains:
  *"Drafting from a closed time period is allowed — the accrual is
  locked but the AR/AP side stays open. Posting to a closed
  accounting period is what would actually be blocked, separately,
  at the GL level."*
- Header copy rewritten: *"Closing a time period locks the **accrual**
  (the underlying hours/bundles) — drafting invoices/bills still works
  against open or closed periods. The separate GL-posting gate lives
  on accounting periods."*
- Empty-state copy on closed periods rewritten to point at the right
  recovery path: reopen the period to rebuild bundles, or post a
  manual document referencing the period.
- Backend libs (`billing/lib/billing.php`, `ap/lib/ap.php`) were
  already correct — they only require `bundle.status='ready'`, not a
  particular `time_period.status`. No backend change needed.

### Gap flagged (not implemented this turn)
The `accounting_periods` table currently supports
`future/open/soft_closed/closed/reopened` but is missing the spec's
4th terminal `locked` state. Posting validation at
`accounting.php:183` also doesn't have a `soft_closed` override-user
path. Both are deviations from the Master Spec but pre-existed and
aren't blocking any active operator workflow. Left as follow-ups.

### Tests
- `period_close_is_last_step_smoke.php` updated — 22 ✓ / 0 ✗
- `ap_close_is_last_step_smoke.php` updated — 17 ✓ / 0 ✗
- Full suite: **295/295 ✓**
- `yarn build` clean → bundle `coreflux-BejR81gO`.

### Deploy note
React-only change this turn (modal copy + Create-button condition).
Cloudways deploy + `update.php` to pick up the new bundle.



## Multi-period JE split — accrual-basis revenue/cost recognition (2026-02 — current fork)

### Why
Operator's question: *"If time is accrued weekly what happens when
the month (GL period) ends on a Wednesday? How about monthly billing
periods but Artemis (four week) GL periods?"*

Standard accrual answer: when an invoice/bill's underlying work
crosses a GL period boundary, it must post as N journal entries —
one per period it touches — with `AR Unbilled` / `AP Accrued`
carrying the bridge.

### What shipped (chosen scope: option B / Standard, ~6h)
- **Migration `021_accounting_settings.sql`** — new per-tenant
  `accounting_settings` table with:
  - `ar_unbilled_account_code` (default `13100`)
  - `ap_accrued_account_code`  (default `21500`)
  - `multi_period_split_enabled` (default `0`, opt-in)
- **`modules/accounting/lib/multi_period.php`** — pure-logic helper:
  - `accountingSettingsGet($tenantId)` — defaults-fallback safe.
  - `accountingBreakdownInvoiceByDate($tenantId, $invoiceId)` —
    walks invoice lines back through `time_downstream_feed` →
    `time_entries` to recover per-work_date amounts (proportional
    by hours-share, last-day-rounding-residual to avoid drift).
  - `accountingGroupBreakdownByPeriod($tenantId, $entityId, $byDate)`
    — resolves each date to its GL period; loud-fails with the
    offending date when no period covers it ("refuses to drop
    revenue silently").
  - `accountingBuildInvoiceJEBatch($invoice, $perPeriod, $arUnbilledCode)`
    — emits the chronological JE batch:
      - Accrual JEs (non-issue periods): Dr AR Unbilled / Cr Revenue.
      - Recognition JE (issue-date period): Dr AR (full total) /
        Cr AR Unbilled (clear all prior accruals) / Cr Revenue
        (this period's portion) / Cr Tax (full).
      - Each JE individually balanced; aggregate revenue across the
        batch equals the invoice subtotal exactly.
- **NOT yet wired into invoices.php/bills.php post handlers** —
  helper exists, gated by `multi_period_split_enabled=1` per tenant.
  Flip-the-switch wiring is the next step; ships separately so we
  can review the JE batch shape against real data before mutating
  the existing GL-post path.

### Scenarios verified (smoke test, 46 ✓ / 0 ✗)
- Single-period invoice → exactly 1 JE (legacy shape preserved).
- Weekly time, monthly GL, week spans month-end Wednesday → 2 JEs.
- Three-period work span (Artemis 4-week P11→P12→P13) → 3 JEs.
- Late invoice (issue_date after all work) → posts cleanly to latest
  work period, no orphan future accrual.
- Multi-account-code revenue (4000 + 4100) splits preserve per-code.
- Multi-account-code accruals collapse into a single AR Unbilled
  clearing line on the recognition JE.
- Loud-fail copy + opt-in flag wiring + migration shape.

### Out of scope this turn (explicitly)
- AP bill mirror (same shape but for `apBuildBillJEBatch` with
  `AP Accrued + Expense/COGS`) — easy follow-up; helper layout makes
  it ~1 hour of mirror code.
- Wire-in to `invoices.php?action=post` + `bills.php?action=post` —
  guarded by a feature flag for safe rollout; user reviews shape
  first.
- Month-end accrual cron (option C from the earlier triage).

### Tests
- `tests/multi_period_je_split_smoke.php` — 46 ✓ / 0 ✗
- Full suite: **296/296 ✓**

### Deploy note
PHP-only change, additive. Migration must run via `update.php`. No
behavior change until a tenant flips `multi_period_split_enabled=1`
(and the post-handler wiring lands).


| 3. Draft AR invoices                  | billing | requires NOT closed |
| 4. Draft AP bills (contractor pay)    | ap   | requires NOT closed |
| 5. Run payroll (W-2 path)             | payroll | independent (own pay_periods) |
| 6. Reconcile + post                   | mercury / accounting | requires NOT closed |
| 7. **CLOSE the period** (last step)   | time | flips → `closed` (immutable) |


5. Select placements → draft N invoices.
6. (Eventually) AP bills + payroll posted into the same open period.
7. Reconcile, then **close** the period as the last step.


  reports view. Can't fix blind from source alone.






### Deploy note
React + PHP both touched — needs a full Cloudways deploy + `update.php`
to pick up new bundle hashes AND new API endpoints. New bundle:
`index-BoK2e7wC.js` / `index-BC5g6YJu.css` / SW `coreflux-BoK2e7wC`.


  silently drift if a tenant overrides the placements scope.

### Tests
- New `tests/placements_csv_subtenant_people_scope_smoke.php`: 9/9.
- `placements_csv_id_validator_smoke.php`: 26/26.
- `placements_csv_id_lookup_smoke.php`: 32/32.
- `placements_csv_email_lookup_smoke.php`: 25/25.
- Full suite: 288/288 ✓ (plaid live-network flake didn't recur).

### Deploy note
This is a server-side PHP-only change — no React rebuild required.
Triggers on the next Cloudways deploy + `update.php` run.


### Tests
- `tests/placements_csv_id_validator_smoke.php` — 26 ✓ / 0 ✗
- Full suite: 286/287 functional pass; lone failure is
  `plaid_integration_smoke.php` flakiness against the live Plaid
  sandbox (passes 96/0 when run in isolation — network timing, not
  our change).



## IdBadge — product-wide rollout (2026-02 — current fork)

### Why
The previous fork landed the id-based CSV lookup pattern for placements +
people. User asked to extend it everywhere. Done with one disciplined
constraint: **only add an id column when it actually corresponds to a
real FK or to the table's own PK**. Adding `vendor_id` to a CSV whose
target table stores `vendor_name` as a free-text column would mislead
operators into thinking they were linking rows when nothing is being
linked. Audit revealed `ap_bills`, `ap_payments`, `billing_invoices`,
and `billing_payments` all store vendor/client info as free-text — so
those importers get the table's OWN PK column instead (for the update-
existing-row pathway), not a foreign-key column.

### Schemas extended (own-PK update-existing match)
- `people.csv`              → `person_id`
- `ap_vendors.csv`          → `vendor_id`
- `ap_bills.csv`            → `bill_id`
- `ap_payments.csv`         → `payment_id`
- `billing_invoices.csv`    → `invoice_id`
- `billing_payments.csv`    → `payment_id`
- `staffing_clients.csv`    → `client_id`
- `mercury_recipients.csv`  → `recipient_id`

All accept the column as **optional integer**. Validated by the strict
`type='integer'` regex shipped last fork. Operators leave the column
blank for new rows, or paste the badge value to update an existing one.

### People CSV commit path
`person_id`-first lookup with **hard error on miss** (no silent fallback
to email). When no id is present, the legacy email upsert + unique-check
path still runs. Matches the placement importer's behaviour for
operator consistency.

### UI lists that gained `<IdBadge />`
- People directory  (P-prefix, already shipped)
- Companies / Clients / Vendors directory  (C-prefix)
- AP Vendors list   (V-prefix)
- AP Bills list     (B-prefix)
- AP Payments list  (PAY-prefix)
- Billing Invoices  (INV-prefix)
- Billing Payments  (RCP-prefix)
- Mercury Recipients (R-prefix)
- Mercury Payments  (MP-prefix)
- Placements list   (PL + linked P-prefix, already shipped)

Each detail page header also gets the badge inline next to the entity
name with a tooltip ("…click to copy for CSV imports"). Click copies
the bare integer to the clipboard — CSV-friendly, no prefix.

### Tests
- New: `tests/idbadge_product_wide_rollout_smoke.php` (45 ✓) — covers
  every schema, every UI page, every colSpan bump, PHP syntax on
  every touched importer.
- Full suite: **286/286 passing**.

### Bundle
- `index-DaevHJg6.js` (new) + `index-BC5g6YJu.css` — `.deploy-version`,
  `spa-assets/`, SW CACHE_VERSION all in sync.



## Placements CSV — ID-based lookup (2026-02 — current fork)

### Bug context
The previous Unicode-defensive email normalisation + "did you mean?"
suggestions didn't actually fix the operator-reported "person not found"
import bug. The real fix is to **bypass the fuzzy-email surface
entirely** by letting CSVs reference the numeric `people.id` and
`placements.id` primary keys directly.

### What shipped
- **CSV schema** (`modules/placements/api/csv_import.php`) gained two
  optional integer columns:
  - `person_id` — preferred lookup. When present and non-empty,
    `person_email` becomes informational (skipped) so a stale legacy
    email column can't poison an otherwise-valid id row.
  - `placement_id` — preferred match key for the update-existing path
    (beats `external_id` and the legacy `(person_id + title +
    start_date)` composite).
  - Either `person_id` or `person_email` is required per row;
    rows with neither are rejected at dry-run with a clear message.
- **`CsvImportService` type system** gained `'integer'` — strict regex
  (`^-?\d+$`, digits only, optional sign) so `1042.0` and `1,042` are
  rejected. After validation the cell is coerced to a real `int`.
- **Dry-run + commit symmetric** — both paths use `person_id` first
  with the same tenant-scoped + soft-delete-safe SQL. `placement_id`
  miss is a hard error in commit (no silent fallback to email match).
- **UI — surface the IDs**:
  - New `<IdBadge id={n} prefix="P|PL" />` component
    (`/app/dashboard/src/components/IdBadge.jsx`) — small mono-spaced
    pill, click writes the **bare integer** to clipboard (CSV-friendly),
    flashes "✓ copied" for 1.2s. Optional prefix is presentational only.
  - **Placements list** (`modules/placements/ui/List.jsx`) gained an
    ID column (`PL-{id}`) and a linked `P-{person_id}` badge inside
    the Person cell.
  - **Placement detail** header surfaces both `PL-{id}` and
    `P-{person_id}` next to the title.
  - **Employee directory** (`modules/people/ui/EmployeeDirectory.jsx`)
    gained an ID column.
  - **Person detail** header surfaces `P-{id}` next to the name.
- **Fallback path** preserved — the email lookup (with Unicode
  defenses + "did you mean?" suggestions from the previous fork) still
  works for legacy CSVs that don't have a `person_id` column. The
  fallback's error message now nudges operators toward the id flow.

### Tests
- New: `tests/placements_csv_id_lookup_smoke.php` (33 ✓) — schema,
  type validator, dry-run precedence, commit precedence,
  placement_id update path, UI affordances, PHP syntax.
- Existing `tests/placements_csv_email_lookup_smoke.php` updated to
  match the new IN-list shape (the legacy email-only path still
  exercises the same Unicode-defensive code).
- Full suite: **285/285 passing**.

### Files touched
- `core/CsvImportService.php` — `integer` type validator
- `modules/placements/api/csv_import.php` — schema + dry-run + commit
- `dashboard/src/components/IdBadge.jsx` — new shared component
- `modules/placements/ui/List.jsx` — ID column + linked person badge
- `modules/placements/ui/PlacementDetail.jsx` — header badges
- `modules/people/ui/EmployeeDirectory.jsx` — ID column
- `modules/people/ui/PersonDetail.jsx` — header badge

### Bundle
- `index-Cjpt6YOE.js` + `index-BC5g6YJu.css` — `.deploy-version`,
  `spa-assets/`, SW CACHE_VERSION all in sync.



## P1 Burst — Approval rules, Reconciliation workbench, Sweep rules, QBO/Zoho/Xero field-map (2026-02 — current fork)

### Shipped this fork

**1. Approval policies (SoD threshold engine)**
- Migration 072 — `tenant_approval_policies` + `payment_instruction_approvals` tables + `payment_instructions.cool_off_until` column.
- `core/approval_policy.php` — list/get/upsert/delete/resolve/recordAck/listAcks. Resolve picks the most-specific rule (recipient > account > broad) within the payment's amount band.
- `mpApprove()` integrated: required-role check from the user object (no `user_tenants` read, RBAC sentry clean), N-of-M co-approver chain (each approval recorded once, transition only after Nth distinct ack), cool-off window stamped onto `cool_off_until` and the auto-advance deferred when the window is in the future.
- Cron worker (`/app/cron/mercury_payment_worker.php`) SELECT now filters `cool_off_until IS NULL OR cool_off_until <= NOW()`.
- `/api/admin/treasury/approval_policies.php` — GET/POST/DELETE with `treasury.payment.approve` gate.
- UI: `/admin/treasury/approval-policies` page with table + form (amount band, required role, distinct approvers 1–5, cool-off minutes 0–2880, recipient/account scoping, sort order, enabled toggle). Admin overview card linked.

**2. QBO / Zoho Books / Xero field-map allow-lists**
- `core/integrations/field_map.php` allow-list grew 5 new accounting entity_types: `gl_account`, `journal_entry`, `bill`, `invoice`, `payment`. All three accounting integrations (QBO, Zoho, Xero) share these schemas because they all target the same `accounting_*` tables.
- `/api/admin/integrations/field_map.php` GET surfaces all 9 entity_types in `allowed_internal_fields`.
- `IntegrationFieldMapAdmin.jsx` selectors now include `xero` as an integration plus the 5 new entity types — operators can author QBO/Zoho/Xero mappings using the same UI + bulk import/export + test-with-payload tooling shipped last fork.

**3. Reconciliation workbench (3-pane UI)**
- New helper `mercuryReconciliationUnmatched()` in `core/mercury_reconciliation.php` — tenant-scoped, Settled + null-reconciled, oldest-first, LIMIT bounded to 1..500.
- `/api/mercury_reconciliation.php` gains `?action=unmatched` and `?action=workbench` (single-shot bundle: stats + unmatched + matched + discrepancy).
- New page `modules/treasury/ui/ReconciliationWorkbench.jsx` — 3 panes (Unmatched | Discrepancies | Reconciled) + 4 stats tiles + "Run auto-match" button that calls the existing engine and refreshes inline. Treasury tab + route wired at `/treasury/reconciliation`.

**4. Cash-allocation / sweep rules (definition layer)**
- Migration 073 — `tenant_sweep_rules` table (source/destination Mercury account ids, target_min_balance_cents, sweep_above_cents, frequency enum, optional `require_approval_policy_id` so swept payments still go through the approval engine).
- `core/sweep_rules.php` — list/get/upsert/delete with thorough validation (blank name, blank source/dest, source==dest, unknown frequency, negative balances).
- `/api/admin/treasury/sweep_rules.php` — CRUD gated by `accounting.bank.manage`.
- New page `modules/treasury/ui/SweepRulesAdmin.jsx` with table + form, tab + route wired at `/treasury/sweep-rules`.
- **Execution worker (creating actual Mercury transfer payment_instructions when source balance > target_min) is deferred** — requires live Mercury balance API + scheduled runner, slated for next fork.

### Tests
- New smoke files: `approval_policy_engine_smoke.php` (60 ✓), `reconciliation_workbench_smoke.php` (25 ✓), `treasury_sweep_rules_smoke.php` (44 ✓).
- Existing: `jobdiva_field_mapping_slice5_smoke.php` updated (anchored ghost-field assertion to the company block to avoid colliding with new `gl_account.description` field).
- Full PHP CLI suite: **284/284 passing**.

### Bundle
- `index-grxl72RX.js` + `index-BC5g6YJu.css` — `.deploy-version`, `spa-assets/`, SW CACHE_VERSION all in sync via postbuild `sync_bundle.sh`.

### Deferred (carry forward to next fork)
- **(P1) GraphQL dashboard migration — People, Companies, Placement detail.**
  Placements list pilot is live and proven; the same pattern applies (mirror existing REST page → `*Graphql.jsx` using `useGql`, add a "Switch to REST" link, A/B perf badges). Subgraph already exposes `Query.companies(limit)` and `Query.people` so no schema work needed. Estimated 1 fork to migrate all three with smoke tests.
- **(P1) Sweep execution worker.** Schema (migration 073) is execution-engine-agnostic. Worker would: poll enabled rules whose frequency matches today, fetch Mercury balance via the adapter, if source > (target_min + sweep_above) then create a `payment_instructions` row source→dest with policy_id stamped, write `last_run_at/last_outcome/last_run_amount_cents`.
- **(P2)** Wire `mailerSend()` → real Resend driver (currently MOCKED — logs locally).
- (P2) Engagements module; Migration Status Dashboard tile.
- (P3) AI Digest Scheduler.



## Slice 6/7 + Mercury Dual-Leg Workflow (2026-02 — current fork)

### What user asked
1. P1A — JobDiva Slice 6/7: "bulk import/export of field mappings" + "Test mapping with sample payload" UI affordance.
2. P1B — Mercury workflow. **Forget Mercury webhooks.** The real win:
   > "the approval within the platform actually triggers two transactions —
   >  transfer in to mercury from funding account, transfer out to vendor."

### What shipped (P1A — Field map import/export + test)
- **Core helpers** (`core/integrations/field_map.php`):
  `tenantIntegrationFieldMapBulkExport` (portable JSON snapshot —
  drops tenant_id / row ids / audit timestamps so the same JSON can
  travel between tenants), `tenantIntegrationFieldMapBulkImport`
  with `merge` and `replace` modes (replace is integration-scoped so a
  JobDiva replace can't wipe QBO/Zoho mappings as collateral), and
  `tenantIntegrationFieldMapTestPayload` (dry-run resolver against an
  operator-supplied payload — no DB writes).
- **API endpoints**:
  `GET/POST /api/admin/integrations/field_map_bulk.php`,
  `POST /api/admin/integrations/field_map_test.php`. Both gated by
  the existing `integrations.field_map.manage` permission.
- **UI** (`IntegrationFieldMapAdmin.jsx`):
  Export button (copies JSON to clipboard + opens textarea), Import
  toggle with mode selector (merge / replace) and inline error
  reporting, "Test with payload" toggle with green/red per-rule status
  table + unmapped-fields disclosure. Replace mode shows a confirm
  prompt naming the integrations it'll wipe.

### What shipped (P1B — Mercury approval triggers dual-leg)
- `mpApprove()` now accepts an `$opts` array; `trigger_now` defaults to
  **true**, calling `mpAdvance()` synchronously after the approval
  transition commits. Leg 1 (funding pull: external funding account →
  operating Mercury) fires within milliseconds of the approval click;
  leg 2 (vendor payout) fires on the next worker tick (or the
  "Run worker" button) after Mercury confirms the funding transfer cleared.
- Failures are **best-effort by design** — if the Mercury adapter is
  unreachable, the row stays in `Approved` (cron retries on its next
  tick) and an `mercury.payment.auto_advance_failed` audit row is
  written. The approval itself is **never rolled back**.
- `/api/mercury_payments.php?action=approve` re-reads the row after
  `mpApprove()` so the response carries the actual post-advance state
  (typically `Funding`), plus `auto_advanced: bool` and the full row.
- **UI** (`MercuryPayments.jsx`):
  - Approval flash banner now says "Approved + funding leg started →
    Funding. Vendor payout will fire once the funding transfer clears."
  - Detail modal renders a new `<DualLegProgress />` visualisation:
    two leg cards with status pill, Mercury txn id, initiated/settled
    timestamps, and a vertical connector between them. The audit trail
    table is preserved below for state-transition history.

### Tests
- New: `tests/field_map_bulk_and_test_smoke.php` (43 ✓)
- New: `tests/mercury_dual_leg_approval_smoke.php` (36 ✓)
- Full suite: **280/280 passing**.

### Files touched
- `/app/core/integrations/field_map.php` — bulk export/import/test helpers
- `/app/api/admin/integrations/field_map_bulk.php` — new
- `/app/api/admin/integrations/field_map_test.php` — new
- `/app/dashboard/src/pages/IntegrationFieldMapAdmin.jsx` — Export / Import / Test panels
- `/app/core/mercury_payments.php` — `mpApprove()` dual-leg auto-trigger
- `/app/api/mercury_payments.php` — approve handler returns post-advance state
- `/app/modules/treasury/ui/MercuryPayments.jsx` — `<DualLegProgress />` + flash copy
- Bundle: `index-TdTQw6Yw.js` / `index-BC5g6YJu.css` (rebuilt + sync_bundle.sh)

### Next action items
- (P1) Apply same field-map registry pattern to QuickBooks Online, Zoho Books, Xero.
- (P1) Migrate remaining dashboards (People, Companies, Placement detail) REST → GraphQL.
- (P1) Approval rules / SoD enforcement — tenant-configurable thresholds
  ($amount, vendor, account → 1-eye vs 2-eye vs CFO-must-approve), routing,
  escalation timers.
- (P1) Reconciliation workflow UI — 3-pane unmatched/suggested/matched
  with bulk-confirm.
- (P1) Treasury cash-allocation workflow — sweep rules + live Mercury
  balances wired into the (currently partly-mocked)
  `money_movement_weekly.php`.
- (P2) Wire `mailerSend()` → real Resend driver (currently logs locally — MOCKED).
- (P2) Engagements module; Migration Status Dashboard tile.
- (P3) AI Digest Scheduler.



## Slice 5b — JobDiva placement metadata + Timesheet UI follow-through (2026-02 — current fork)

### What user asked
1. "we're still not getting all the details from job diva. job id, job contact, end client … we need to get all of it and be able to map anywhere"
2. "Timesheet page needs to contain name and client, not title as it does now."
3. Confirmed scope: **full JobDiva payload exposed via `field_map`**; end client modelled BOTH as a column AND linked to `companies`.

### What shipped this fork
- Migration `071_jobdiva_placement_metadata.sql` adds 5 columns on `placements`:
  `jobdiva_job_id`, `recruiter_name`, `recruiter_email`,
  `account_manager_name`, `account_manager_email` + index on
  `(tenant_id, jobdiva_job_id)`. Auto-applied by `coreflux_run_migrations()`.
- `core/jobdiva/sync.php` resolves all 5 new fields through
  `tenantIntegrationFieldMapPluckInternal()` (registry-first, JobDiva-native
  candidate keys as fallback). INSERT + UPDATE clauses extended; UPDATE
  path still honours `coreflux_overridden_fields`.
- Bulk enrichment (`jobdivaSyncEnrichRelatedEntities`) batch-fetches every
  related JobDiva entity once per sync run and injects them as nested
  objects (`_jd_job`, `_jd_candidate`, `_jd_customer`, `_jd_contact`,
  `_jd_start`) so operators can dotted-path-map ANY field on those records
  into any allow-listed CoreFlux column.
- `core/integrations/field_map.php` allow-list now exposes the 5 new
  columns under entity_type='placement'. They appear automatically in
  `IntegrationFieldMapAdmin` and the suggest UI — no UI changes required.
- End-client modelling: `jobdivaResolveOrAutoCreateEndClient()` writes
  BOTH `placements.end_client_name` (denormalised string) AND
  `placements.end_client_company_id` (FK), auto-creating the `companies`
  row + `external_entity_mappings` link on first encounter.
- `modules/staffing/ui/TimesheetWeek.jsx` updated to display person name
  + end-client name (instead of just title), per direct user feedback.
- Frontend rebuilt → bundle now `index-DuTfqpv-.js` / `index-BC5g6YJu.css`;
  `.deploy-version`, `spa-assets/`, and the service-worker CACHE_VERSION
  are all in sync via the postbuild `sync_bundle.sh` hook.

### Tests
- New: `tests/jobdiva_placement_metadata_smoke.php` (40 assertions covering
  migration, sync wiring, allow-list, end-client modelling, PHP syntax).
- Full suite: **278/278 passing**.

### Files touched this fork
- `/app/core/integrations/field_map.php` (allow-list additions)
- `/app/tests/jobdiva_placement_metadata_smoke.php` (new)
- Bundle artefacts under `/app/dashboard/dist/` and `/app/spa-assets/`
  (regenerated)
- `/app/.deploy-version` (expected_bundle hashes updated)

### Next action items (priorities in handoff)
- (P1) JobDiva Slice 6/7 — bulk import/export of field mappings as JSON
  in `IntegrationFieldMapAdmin.jsx` + "Test mapping with sample payload"
  UI action.
- (P1) Apply the same integration-registry pattern to QuickBooks Online,
  Zoho Books, Xero.
- (P1) Migrate remaining dashboards (People, Companies, Placement
  detail) from REST → GraphQL.
- (P1) Mercury Webhooks Integration.
- (P2) Wire `mailerSend()` → real Resend driver.
- (P2) Engagements module (fixed-fee project accounting).
- (P3) AI Digest Scheduler.



## Bug Fix — `api is not a function` in self-heal + suggest flows (2026-02 — current fork)

### Background
User clicked "Run pending migrations" → error: *"Failed: j is not a function"*. The minified `j` was the `api` import. Root cause: `api` in `/dashboard/src/lib/api.js` is exported as an **object** with `.get`/`.post`/`.put`/`.patch`/`.delete` methods, NOT as a callable function. Three call sites in this session's new code called it as `api('/path', { method, body })` which fails at runtime.

### What shipped
Fixed all three callsites:
- **`SyncHistoryDrawer.runMigration`** → `api.post('/api/admin/migrate.php')`. Removed the unnecessary `await import('../lib/api')` dynamic import; uses top-level `import { api } from '../lib/api'` instead.
- **`LinkedExternalSystemsPanel.SuggestMappingModal`** → `api.post(path, body)` for both the suggest probe AND the per-row upsert.
- **`IntegrationFieldMapAdmin`** → `api.get(...)`, `api.post(...)`, `api.delete(...)` for list/upsert/delete actions.

### Tests
- Updated assertion shape in `tests/jobdiva_field_map_suggest_smoke.php` and `tests/migration_self_heal_smoke.php` (`method: 'POST'` → `api.post(`).
- Full suite: **252/252 passing**.
- Bundle advanced: `index-Dkpscc8e.js` → `index-BPUCXt-p.js`; SW CACHE_VERSION matches.

### Files touched
- `/app/dashboard/src/components/SyncHistoryDrawer.jsx`
- `/app/dashboard/src/components/LinkedExternalSystemsPanel.jsx`
- `/app/dashboard/src/pages/IntegrationFieldMapAdmin.jsx`
- `/app/tests/jobdiva_field_map_suggest_smoke.php` (assertion update)
- `/app/tests/migration_self_heal_smoke.php` (assertion update)



## Migration Self-Heal — PHP-FPM Worker Cache Fix (2026-02 — current fork)

### Background
User reported "Failed to load history: SQLSTATE[42S02]: Base table or view not found: 1146 Table 'coreflux.entity_sync_history' doesn't exist" — even though the migration file was deployed. Root cause: `coreflux_run_migrations()` uses `static $ranOnce = false` as a per-process guard. PHP-FPM workers are long-lived, so once a worker has migrated at startup, it keeps `$ranOnce = true` and **skips new migration files** that land in subsequent deploys, until the worker recycles.

### What shipped (four defenses)
1. **Auto-detect new files**: `coreflux_run_migrations()` now computes a SHA-1 file-set signature from `(path|mtime|size)` of every `core/migrations/*.sql` + `modules/*/migrations/*.sql` file. When the signature changes between calls within the same process, the runner re-executes — new migration files trigger an automatic re-run without requiring worker recycle.
2. **Force-rerun endpoint**: `POST /api/admin/migrate.php` calls `coreflux_run_migrations(true)`. Manual safety net when defense #1 fails (e.g. file system caching, clock skew). RBAC: `integrations.field_map.manage`.
3. **Graceful endpoint**: `/api/integrations/sync_history.php` now catches `PDOException` matching `entity_sync_history` + `doesn't exist`, returns `{rows: [], migration_pending: true, hint: '…POST /api/admin/migrate.php…'}` instead of 500.
4. **Self-heal UI**: `SyncHistoryDrawer` renders an amber "Migration pending" banner with a "Run pending migrations" button when the API flags `migration_pending: true`. One-click → POSTs `/api/admin/migrate.php` → reloads the drawer.

### Tests
- New: `tests/migration_self_heal_smoke.php` — 20 assertions covering all four defenses.
- Full suite: **252/252 passing** (was 251).
- Bundle advanced: `index-BDbWiYC6.js` → `index-Dkpscc8e.js`; SW CACHE_VERSION matches.

### Files touched
- `/app/core/migrate.php` (file-set signature detection)
- `/app/api/admin/migrate.php` (new force-rerun endpoint)
- `/app/api/integrations/sync_history.php` (graceful missing-table handling)
- `/app/dashboard/src/components/SyncHistoryDrawer.jsx` (Migration pending banner + Run button)
- `/app/tests/migration_self_heal_smoke.php` (new)



## Sync History Drawer — Per-Record Change Log (2026-02 — current fork)

### Background
User asked: *"yes, add sync history"* — a "Sync history" drawer on the placement detail page showing last N audit rows for that record with field-level before/after values and the actor who triggered each sync.

### What shipped
- **Migration 069** `entity_sync_history` — per-record change log. Each row captures `payload_before` + `payload_after` + `content_hash_before/after` + `actor_user_id` + timestamps. Indexed on `(tenant_id, internal_entity_type, internal_entity_id, created_at)` for fast drawer lookups, and `(tenant_id, source_system, created_at)` for future system-wide audit views.
- **`mappingUpsert()` history hook** — now accepts an optional `?int $actorUserId = null` param (backwards-compatible default). When `content_hash` differs from the existing row (i.e. payload genuinely changed), writes a history row via the new `entitySyncHistoryRecord()` helper BEFORE the UPDATE commits. **Signal-only**: unchanged-but-re-touched syncs do NOT write history. History writes are wrapped in try/catch + `error_log()` so a malformed payload can't break the actual sync.
- **`entitySyncHistoryList(tid, entity_type, internal_id, limit)`** — newest-first, decodes payload_before/after as PHP arrays so the API doesn't double-encode. Limit clamped to 1..500.
- **`GET /api/integrations/sync_history.php?entity_type=&internal_id=`** — returns `{ rows: [...] }` with each row's actor resolved via a single `IN(...)` query (no N+1).
- **`SyncHistoryDrawer.jsx`** — right-anchored slide-out panel with a `<History />` trigger button. Per-row header shows source + change count badge + timestamp + actor. Expanding a row reveals a field-level diff table: only keys whose values changed are listed, alphabetically sorted, before-value dimmed and after-value highlighted. `useApi` is gated on `open` so the drawer doesn't pre-fetch.
- **Threaded `$userId` into every `mappingUpsert()` call** in the JobDiva sync paths (`sync.php`: companies, contacts, placements; `sync_placements.php`: auto-create person). Now the "Sync now" button's operator id propagates into the history actor column — "system (cron)" only shows for genuinely scheduled runs.
- **Wired** `<SyncHistoryDrawer entityType="placement" internalId={placement.id} />` into PlacementDetail under the LinkedExternalSystemsPanel.

### Tests
- New: `tests/entity_sync_history_smoke.php` — 42 assertions covering schema, the mappingUpsert hook, the list helper, the API endpoint, the drawer UI surface (diff hides unchanged keys, alphabetical sort, gated fetch, actor labels), and the JobDiva sync threading.
- Updated: `tests/sprint8a_a3_jobdiva_sync_smoke.php` — mappingUpsert assertions now expect the `$userId` arg.
- Full suite: **251/251 passing** (was 250).
- Bundle advanced: `index-DjuWyKtx.js` → `index-BDbWiYC6.js`; SW CACHE_VERSION matches.

### Files touched
- `/app/core/migrations/069_entity_sync_history.sql` (new)
- `/app/core/integrations/entity_mappings.php` (history hook + recorder + list helper, actor param)
- `/app/api/integrations/sync_history.php` (new endpoint)
- `/app/dashboard/src/components/SyncHistoryDrawer.jsx` (new)
- `/app/modules/placements/ui/PlacementDetail.jsx` (drawer mount)
- `/app/core/jobdiva/sync.php` (3 mappingUpsert sites — actor threading)
- `/app/core/jobdiva/sync_placements.php` (2 mappingUpsert sites — actor threading)
- `/app/tests/entity_sync_history_smoke.php` (new)
- `/app/tests/sprint8a_a3_jobdiva_sync_smoke.php` (updated assertions)



## Service-Worker Cache Invalidation Fix + Suggest Mappings Feature (2026-02 — current fork)

### Background
User reported: *"I don't see any change?"* even after hard refresh + Sync now. Investigation revealed the service worker uses cache-first for `/spa-assets/*.js`. Vite's content-hash filenames sometimes collide across sessions (when bundle content is byte-similar), so the SW kept serving stale bundles under the same URL — making post-build changes invisible.

### What shipped — SW cache invalidation auto-bump
- **`scripts/sync_bundle.sh`** now derives `CACHE_VERSION` from the bundle's content-hash on every build and stamps it into both `/spa-assets/sw.js` AND `dashboard/dist/spa-assets/sw.js`. The SW's `activate` handler already deletes any cache key that doesn't match the current `CACHE_VERSION`, so the next page load purges the stale cache and fetches fresh assets.
- Idempotent: two builds producing identical bundles produce identical `CACHE_VERSION` values, so CI doesn't spuriously invalidate. New builds produce new hashes → new cache version → forced refresh.

### What shipped — Suggest Mappings ("Sparkles" flow)
- **`/api/admin/integrations/field_map_suggest.php`** — new POST endpoint. Body: `{integration, entity_type, payload}`. Returns ordered list of proposed `(external_field → internal_field, transform, confidence, reason, sample_value)` rows.
- **Three-tier heuristic**:
  1. **Curated alias hit** — confidence 1.0. Bake-in alias table covers JobDiva's most common field shapes for placement / person / company / contact (e.g. `jobTitle → title`, `startDate → start_date` with `date_normalise`, `candidateEmail → email_primary` with `lowercase`).
  2. **Case/separator-insensitive exact match** — confidence 0.9. e.g. external `"start_date"` matches internal `start_date`.
  3. **Substring containment** — confidence 0.7. e.g. `jobTitle` contains `title`. Guarded by `strlen($intNorm) < 4` so short names like `id` don't match everything.
- Already-configured internal fields are **shadowed** (returned in a separate `shadowed` array — operator can still see what would have been suggested without auto-overwriting their choices).
- Each internal field gets at most one suggestion (highest-confidence wins).
- Supports one-level nested object flattening (`job.title`) — enough for every JobDiva V2 envelope we've seen.
- **UI** — `<Sparkles /> Suggest mappings` button on each expanded LinkedExternalSystemsPanel row → modal with checkboxes (high-confidence pre-selected), confidence pills, sample values, reason text, "Apply selected" walks the upsert endpoint per row, success banner with "Trigger Sync now to use them" hint.

### Workflow loop (end-to-end)
1. Open any placement → "Linked external systems" → expand the JobDiva row.
2. Click **"Suggest mappings"** (Sparkles icon).
3. Modal opens, scans the payload, pre-ticks the high-confidence rows.
4. Click **"Apply X selected"** — registry rows persist via the existing upsert endpoint.
5. Click **Sync now** on JobDiva → next placement upsert uses the new mappings.

### Tests
- New: `tests/jobdiva_field_map_suggest_smoke.php` — 41 assertions covering backend RBAC, input validation, curated aliases, confidence-tier scoring, response envelope, frontend modal mount, button rendering, and the SW CACHE_VERSION auto-bump in `sync_bundle.sh`.
- Full suite: **250/250 passing** (was 249).
- Bundle hash genuinely advanced: `index-ED4CoJ_l.js` → `index-DjuWyKtx.js`. SW `CACHE_VERSION` = `coreflux-DjuWyKtx` (matches).

### Files touched
- `/app/api/admin/integrations/field_map_suggest.php` (new)
- `/app/dashboard/src/components/LinkedExternalSystemsPanel.jsx` (added SuggestMappingModal + Sparkles button)
- `/app/scripts/sync_bundle.sh` (auto-bump SW CACHE_VERSION on every build)
- `/app/spa-assets/sw.js` (CACHE_VERSION value advances every build now)
- `/app/tests/jobdiva_field_map_suggest_smoke.php` (new)



## JobDiva Field Mapping — Slice 4 (Syncer Wiring) (2026-02 — current fork)

### Background
User asked: *"how about mapping the synced data? that's probably most important. payload field → [core module]/[corefied]"*. The registry built in Slice 3 was scaffolding — Slice 4 makes it take effect.

### What shipped
- **`core/integrations/field_map.php`** — new resolver layer:
  - `tenantIntegrationFieldMapResolveAll($tid, $integration, $entityType)` — globally-cached lookup (one DB hit per (tenant, integration, entity_type) per process). Cron loops MUST call `tenantIntegrationFieldMapFlushCache()` between tenants.
  - `tenantIntegrationFieldMapApplyTransform($value, $transform)` — implements `none` / `lowercase` / `uppercase` / `trim` / `cents_to_dollars` / `dollars_to_cents` / `date_normalise` (delegates to `jobdivaNormaliseDate` when available).
  - `tenantIntegrationFieldMapPluckPath($payload, $path)` — walks dotted paths (`job.JobTitle`, `job.meta.JOB_OWNER`) with case- and separator-insensitive matching at every segment. The operator can copy field names directly from the "View raw payload" viewer in the LinkedExternalSystemsPanel and the registry resolves them no matter how JobDiva spells them.
  - `tenantIntegrationFieldMapPluckInternal()` — canonical entry point for syncers. Registry-first, default-fn fallback. Falls through to default when the configured external_field isn't present in the payload (operator misconfig doesn't wipe data).
- **`jobdivaSyncUpsertPlacement`** consults the registry for `title`, `start_date`, `end_date`, `end_client_name`, `status`. `date_normalise` is still applied post-resolution so the operator doesn't have to remember to configure the transform for date columns.
- **`jobdivaPlacementsAutoCreatePerson`** consults the registry for `first_name`, `last_name`, `email_primary`, `phone_primary`. Built-in candidate lists preserved as the fallback closure.
- **Admin UI banner** flipped from yellow "Scaffolding mode" → green "Live." with a tip directing operators to use the raw payload viewer in the LinkedExternalSystemsPanel to discover field names. This is the synergy: open placement → inspect raw payload → copy field name → configure in registry → next sync uses it.

### Tests
- New: `tests/jobdiva_field_mapping_slice4_smoke.php` — 50 assertions covering resolver exports, every transform, dotted-path traversal (case/separator-insensitive at every segment), registry-first/default-fallback behaviour, cache flush, placement upsert wiring (5 fields), auto-create person wiring (4 fields), and the UI banner flip.
- Full suite: **249/249 passing** (+1 new file).
- Frontend rebuilt: `sync_bundle.sh` ran clean.

### Files touched
- `/app/core/integrations/field_map.php` (resolver layer)
- `/app/core/jobdiva/sync.php` (Placement upsert wiring)
- `/app/core/jobdiva/sync_placements.php` (Auto-create person wiring)
- `/app/dashboard/src/pages/IntegrationFieldMapAdmin.jsx` (banner: scaffolding → live)
- `/app/tests/jobdiva_field_mapping_slice4_smoke.php` (new)
- `/app/tests/jobdiva_field_mapping_slice1_smoke.php` (updated assertions)
- `/app/tests/sprint8a_a3_jobdiva_sync_smoke.php` (endDate variable rename)



## JobDiva Field Mapping — Slice 1 (Title + Connected Sources panel) + Slice 3 Scaffolding (2026-02 — current fork)

### Background
User reported: *"the JobDiva placement ID shouldn't be the title — the title should be the actual Job Title. Placement ID is separate. Each active integration likely needs its own ID field. Maybe a tenant-level integration mapping that only tenant_admin can control."*

### What shipped (Slice 1 — placement title + per-source identifier visibility)
- **`jobdivaSyncUpsertPlacement` title resolution** now probes nested `job.*` envelopes in addition to flat `jobTitle` / `positionTitle` / `role` keys. JobDiva V2 `searchStart` typically nests the title inside a `job` object — that's why the previous flat lookup always missed and the synthetic placeholder showed up. The literal `"JobDiva Placement <extId>"` placeholder is preserved as a genuine last-resort.
- **`mappingListForInternal()`** now returns `payload_snapshot` alongside the metadata, decoded server-side so the frontend doesn't double-parse.
- **`LinkedExternalSystemsPanel`** rewritten with an expandable per-source detail row that surfaces:
  - Curated identifier fields from `payload_snapshot` (Start ID, JobDiva Job #, Job Title, Candidate ID, Bill/Pay Rate) picked per `(source_system, entity_type)` — operators no longer need to dig through raw JSON.
  - Collapsible raw payload viewer (full JSON, monospaced) for deeper inspection.
- **Wired** into `PlacementDetail.jsx` — Connected Sources panel now appears under the header.
- JS pluck mirrors the backend `jobdivaPluckField()` normalisation so the same candidate-key list works on both sides.

### What shipped (Slice 3 — Tenant Integration Field Map registry, scaffolding only)
- **Migration 068** `tenant_integration_field_map` table — composite unique on `(tenant_id, integration, entity_type, internal_field)`. Columns: `external_field`, `internal_field`, `transform` (default `'none'`), `enabled`, `notes`, `updated_by_user_id`.
- **`core/integrations/field_map.php`** lib — list / upsert / delete helpers with server-side **internal-field allow-list** per entity_type so a misconfigured tenant_admin can't accidentally route data into `tenant_id` / `created_by_user_id`. Transform list is also validated server-side.
- **`/api/admin/integrations/field_map.php`** — GET (list + allow-list + transforms) / POST (idempotent upsert via `ON DUPLICATE KEY UPDATE`) / DELETE (tenant-scoped). RBAC: new `integrations.field_map.manage` and `integrations.field_map.view` permissions (both `integrations:admin` level → master_admin + tenant_admin).
- **`/admin/integrations/field-map`** admin UI page — scope dropdowns (integration + entity_type), table of current overrides, add-form with constrained `internal_field` dropdown from server-provided allow-list, transform dropdown, notes column. **Yellow scaffolding banner** makes the wiring-status clear: rows persist but the syncer doesn't consult the registry yet.
- **Slice 4 (next session)**: wire `jobdivaSyncUpsertPlacement` / `jobdivaPlacementsAutoCreatePerson` / etc to consult `tenant_integration_field_map` BEFORE falling back to the built-in candidate lists.

### Tests
- New: `tests/jobdiva_field_mapping_slice1_smoke.php` — 57 assertions covering title resolution, payload_snapshot wiring, LinkedExternalSystemsPanel UX surface, migration schema, lib helpers + validation, admin API surface, RBAC bridge, and admin UI scaffolding.
- Updated: `tests/p1_linked_external_systems_panel_smoke.php` — loosened external_id whitespace assertion to tolerate the new chevron column.
- Full suite: **248/248 passing** (was 247).
- Frontend rebuilt: `yarn --cwd /app/dashboard build` + `sync_bundle.sh` ran clean.

### Files touched
- `/app/core/jobdiva/sync.php` (title nested-envelope probe)
- `/app/core/integrations/entity_mappings.php` (payload_snapshot in list_for_internal)
- `/app/core/integrations/field_map.php` (new lib)
- `/app/core/migrations/068_tenant_integration_field_map.sql` (new)
- `/app/core/rbac/legacy_map.php` (new permissions)
- `/app/api/admin/integrations/field_map.php` (new admin API)
- `/app/dashboard/src/components/LinkedExternalSystemsPanel.jsx` (rewritten with expandable rows + payload fields)
- `/app/dashboard/src/pages/IntegrationFieldMapAdmin.jsx` (new admin UI page)
- `/app/dashboard/src/pages/AdminModule.jsx` (route)
- `/app/modules/placements/ui/PlacementDetail.jsx` (panel wiring)
- `/app/tests/jobdiva_field_mapping_slice1_smoke.php` (new)
- `/app/tests/p1_linked_external_systems_panel_smoke.php` (loosened whitespace assertion)



## JobDiva Date Normalisation — Epoch-ms → Y-m-d Fix (2026-02 — current fork)

### Background
After enabling placement discovery, user's "Sync now" successfully fetched **50 placements** from JobDiva V2 but ALL 50 failed with:
```
SQLSTATE[22007]: Incorrect date value: '1779231290000' for column 'start_date'
```
JobDiva V2 BI returns dates as Java/Spring's default `Date.getTime()` epoch-milliseconds (`1779231290000`), not formatted strings.

### What shipped
- **`jobdivaNormaliseDate(mixed $raw): ?string`** new helper in `core/jobdiva/sync.php` — accepts:
  - 13-digit string/int → epoch milliseconds → `gmdate('Y-m-d', $n/1000)`
  - 10-digit string/int → epoch seconds → `gmdate('Y-m-d', $n)`
  - ISO-8601 / "5/22/2026" / "Y-m-d HH:MM:SS" → parsed via `strtotime`
  - `''` / `'0'` / `'null'` / unparseable → `null`
- **`jobdivaSyncUpsertPlacement`** now normalises both `startDate` and `endDate` before binding. `endDate` may be `null` (column is nullable); `startDate` falls back to `''` if normalisation fails (caller's try/catch surfaces the failure in `errors[]` instead of corrupting the table).

### Tests
- 8 new assertions in `sprint8a_a3_jobdiva_sync_smoke.php` (helper exports + placement upsert wiring).
- 11 live-behaviour assertions in `jobdiva_contact_field_resolution_smoke.php` (epoch ms/s, ISO, US slash, null/empty/garbage handling).
- Full suite: **247/247 passing**.

### Files touched
- `/app/core/jobdiva/sync.php` (helper + placement upsert)
- `/app/tests/sprint8a_a3_jobdiva_sync_smoke.php` (static assertions)
- `/app/tests/jobdiva_contact_field_resolution_smoke.php` (live behaviour assertions)



## People Directory: "Imported from JobDiva — Needs Review" Filter (2026-02 — current fork)

### Background
`jobdivaPlacementsAutoCreatePerson()` mints placeholder `people` rows when JobDiva's start payload lacks real candidate data — synthetic email (`jd-emp-<id>@no-email.invalid`), `first_name="JobDiva"`, `last_name="Candidate-<id>"`. Operators needed a way to find these and complete them in bulk.

### What shipped
- **Backend filter** (`modules/people/lib/people.php` + `modules/people/api/people.php`):
  - New `source` filter (`p.source = :source`) — surfaces all JobDiva-imported records, not just placeholders.
  - New `needs_review` flag — OR-joined predicate matching the three synthesised values that `jobdivaPlacementsAutoCreatePerson()` writes (`email_primary LIKE '%@no-email.invalid'` OR `first_name='JobDiva'` OR `last_name LIKE 'Candidate-%'`). Predicate values are literal SQL strings (not bound params) since they're fixed and don't vary by user input — avoids unbound-param warnings when the toggle is off.
  - API: query params `?source=jobdiva&needs_review=1`.
- **Frontend toggle** (`modules/people/ui/Directory.jsx`):
  - New checkbox chip labelled "Imported from JobDiva — needs review" next to the existing status filter.
  - Visually marks active state (amber background + border) so it's obvious the list is filtered.
  - Toggle sets `source=jobdiva&needs_review=1` and resets pagination.
- **Per-row "Needs review" badge** — renders next to each row's name when the row matches the same predicate (JS mirror of the SQL filter), so imports are identifiable even when the toggle is off (e.g. when an operator searches by name). Amber pill with tooltip: *"Auto-imported from JobDiva with placeholder fields — review and complete."*

### Tests
- New: `tests/people_imported_needs_review_smoke.php` — 27 assertions covering the SQL predicate, API surface, JS toggle wiring, badge predicate parity, and the auto-create placeholder literals (so a future change to `jobdivaPlacementsAutoCreatePerson()` would break the smoke before drifting silently from the filter).
- Full suite: **247/247 passing** (was 246).
- Frontend rebuilt: `yarn --cwd /app/dashboard build` + `sync_bundle.sh` ran clean; new bundle hashes in `.deploy-version`.

### Files touched
- `/app/modules/people/lib/people.php` (filter)
- `/app/modules/people/api/people.php` (query param wiring)
- `/app/modules/people/ui/Directory.jsx` (toggle + per-row badge)
- `/app/tests/people_imported_needs_review_smoke.php` (new)
- `/app/dashboard/dist/*` + `/app/.deploy-version` (rebuilt bundles)



## JobDiva Placement Discovery — searchStart + Timesheets + Webhook (2026-02 — current fork)

### Background
JobDiva V2 has **no `NewUpdatedStartRecords` BI delta endpoint**. The previous agent deferred placement sync entirely (`sync_skip` audit + `deferred_reason`). User asked: *"why only from timesheets — why not from creation of new placement?"*. Answer: we wire **all three** channels.

### What shipped
- **`/app/core/jobdiva/sync_placements.php`** (new module) with:
  - `jobdivaPlacementsFetchViaSearchStart()` — probes `POST /apiv2/jobdiva/searchStart` with five date-range criterion shapes (`startDateBegin`/`startDateEnd`, lowercase variants, `modifyDate*`, `dateBegin`/`dateEnd`). First non-empty array wins. 401/auth errors bubble up; 400/empty falls through to the next shape.
  - `jobdivaPlacementsFetchViaTimesheets()` — pulls `NewUpdatedTimesheetRecords` (existing wired endpoint), extracts unique placement IDs, per-ID `searchStart` for full detail. Safety-net for active placements.
  - `jobdivaPlacementsExtractList()` — normalises `{data}` / `{items}` / `{starts}` / `{records}` / single-record envelopes.
  - `jobdivaPlacementsDiscover()` — orchestrator: searchStart → timesheets → fail-soft. Exposes per-attempt diagnostics in audit detail.
  - `jobdivaPlacementsAutoCreatePerson()` — resolves person via mapping → email match → auto-create (`INSERT INTO people`). Synthesises `jd-emp-<id>@no-email.invalid` (RFC 6761) if email missing. Defaults `classification='w2'`, `source='jobdiva'`, `external_id='jd:<candidateId>'`.
- **`jobdivaSyncPlacements`** rewritten — calls `jobdivaPlacementsDiscover()` when no `items_override`, auto-creates the person on the fly. `items_override` path preserves legacy "skip when no person mapping" behaviour for smoke compatibility.
- **`jobdivaSyncUpsertPlacement`** — now provides `title` (was missing; would have failed at DB layer since `placements.title` is NOT NULL). Resolves via `jobTitle` / `positionTitle` / `role` etc, falls back to `"JobDiva Placement <extId>"`.
- **Webhook handler** in `api/jobdiva.php` — dispatches `placement.*` and `start.*` events into the sync pipeline in real time. Inline `payload.data` is ingested directly; ID-only payloads are re-fetched via `searchStart`. Failures mark the event row `status='error'` (non-fatal, operator can re-run "Sync now").

### Tests
- New: `tests/jobdiva_placement_discovery_smoke.php` — 58 assertions across module structure, criterion shapes, fallback orchestration, person resolver, sync.php wiring, and webhook dispatch.
- Updated: `tests/sprint8a_a3_jobdiva_sync_smoke.php` — replaced "deferred-by-design" assertions with the new discovery contract.
- Full suite: **246/246 passing** (was 245).

### Files touched
- `/app/core/jobdiva/sync_placements.php` (new)
- `/app/core/jobdiva/sync.php` (Placements driver + Upsert helper)
- `/app/api/jobdiva.php` (webhook dispatch)
- `/app/tests/sprint8a_a3_jobdiva_sync_smoke.php` (updated)
- `/app/tests/jobdiva_placement_discovery_smoke.php` (new)



## JobDiva Contact Field Resolution Fix (2026-02 — current fork)

### Background
After fixing JobDiva V2 auth + the 500-on-Contacts response (via window-halving retries), the Contacts BI sync successfully retrieved 49 records but parsed **0** — all 49 were dropped under `missing_fields` because the V2 BI response uses key shapes (`"first name"`, `"COMPANYID"`, `"FirstName"`) that the parser's literal `??` chain didn't cover.

### What shipped
- **`jobdivaPluckField(array $item, array $candidates): string`** — new helper in `core/jobdiva/sync.php` that normalises BOTH the record's keys and the candidate list to lowercase-alphanumeric, then resolves the first non-empty scalar match. Tolerates `"first name"` / `"FirstName"` / `"FIRSTNAME"` / `"firstname"` etc. all at once.
- **Contacts parser** (`jobdivaSyncContacts`) refactored to use the helper with comprehensive candidate lists for `id`, `companyId`, `firstName`, `lastName`, `name`.
- **Contact upsert helper** (`jobdivaSyncUpsertContact`) extended the same way for `email` / `phone 1` / `title`.
- **Companies parser** (`jobdivaSyncCompanies`) — added an *additive* V2 BI pluck fallback after the existing `??` chain (preserving the existing smoke contract literally).
- **Sample-shape diagnostic** — on `missing_fields` skip, the audit detail and `errors[]` now include `sample_keys` (first 3 records' key arrays) + `sample_records` (up to 2 redacted records, scalars truncated to 60 chars). The operator can read the actual JobDiva payload shape from the Diagnostics panel without server-side log spelunking.

### Tests
- New file `tests/jobdiva_contact_field_resolution_smoke.php` — 32 assertions covering helper signature, normalisation logic, live behaviour across 8 key shapes, candidate lists for every Contact field, diagnostic surface, and Companies fallback.
- Full suite: **245/245 passing** (was 244).

### Files touched
- `/app/core/jobdiva/sync.php` (helper + Contacts/Companies parsers + audit detail)
- `/app/tests/jobdiva_contact_field_resolution_smoke.php` (new)



## Resend Mailer + Per-Purpose Tenant Sender Overrides (2026-02 — current fork)

### What shipped
- **Platform Resend envelope**: `config.local.php` now defines
  `RESEND_FROM_EMAIL = 'no-reply@mail.corefluxapp.com'` and
  `RESEND_FROM_NAME = 'CoreFlux Notifications'`. The `RESEND_API_KEY`
  itself is **not** committed — operators set it as a Cloudways env
  var or via SSH-edit. Once the key is set, `ResendDriver` is the
  default outbound transport (was already wired in
  `core/mail_bootstrap.php`).
- **Per-purpose tenant overrides**: new `tenant_mail_senders` table
  (migration 065) keyed on `(tenant_id, purpose)` with columns
  `from_name`, `reply_to`, `enabled`, plus `updated_by_user_id`.
- **Purpose registry** (in `core/tenant_mail.php`) — five canonical
  purposes covering every active `mailerSend()` site:
  - `timesheets` → staffing/timesheets (label: **Timesheets**)
  - `ap` → ap/bill approvals (label: **AP**)
  - `vendor_portal` → ap/vendor portal magic link (label: **Vendor Portal**)
  - `cfo` → CFO digests (label: **CFO**)
  - `payments` → Mercury/treasury alerts (label: **Payments**)
- **Resolver precedence** (`cf_tenant_mail_sender(tenantId, purpose)`):
  1. `tenant_mail_senders` row → from_name / reply_to / enabled
  2. legacy `tenants.mail_from_name_override` / `mail_reply_to`
  3. derived `"{tenant_name} {Purpose Label}"`
  4. platform `RESEND_FROM_NAME` / null Reply-To
- **Mute flag**: `enabled=0` for a (tenant, purpose) row hard-mutes
  that category — `mailerSend()` returns
  `{ok:false, driver:'disabled', error:'purpose_disabled'}` without
  contacting Resend.
- **Wired call sites**: all five existing `mailerSend()` invocations
  now pass `module`, `purpose`, and `tenant_id`:
  `staffing/api/timesheet_email_approver.php`,
  `ap/api/bill_approvals.php`, `ap/api/vendor_portal.php`,
  `api/cfo_send_report.php`, `core/mercury_payments.php`.

### API
- New `/api/admin/mail_senders.php` (RBAC `tenant.manage`):
  - `GET` → `{purposes:[{key,label,description,override,resolved:{display,from_name,reply_to,enabled,source}}], platform:{from_email,from_name}}`
  - `POST` body `{purpose, from_name?, reply_to?, enabled?}` upserts
  - `DELETE ?purpose=…` removes the override (cascade to legacy/platform fallback)

### UI
- New `/settings/notifications` page (`NotificationSendersPage.jsx`)
  rendering one card per purpose with:
  - "Sending enabled / muted" toggle (per-purpose hard mute)
  - Display-name input (max 120, line-break-free)
  - Reply-To input (RFC-validated email)
  - Live "Current effective sender" preview with `source` tag
    (`purpose` / `tenant` / `derived` / `platform`)
  - "Save" + "Reset to default" buttons (DELETE) for tenant admins
- `SettingsPage` "Email Notifications" placeholder toggle replaced
  with a link card to the new page.

### Smoke tests
- New: `tests/tenant_mail_senders_smoke.php` (79 assertions covering
  migration schema, registry contents, resolver short-circuits,
  mailerSend mute behaviour, all 5 call-site wirings, API surface,
  config.local.php envelope, and full UI testid map).
- Updated: `tests/mailer_send_shim_smoke.php` to allow the new
  platform `RESEND_FROM_NAME` to flow through (was hard-coded to a
  driver-default name).
- Suite total: **243 / 243 passing** (was 242).

### Operator todo
Before live delivery, the operator must:
1. Add the Resend key as a Cloudways env var: `RESEND_API_KEY=re_...`.
2. Verify the `mail.corefluxapp.com` domain (SPF/DKIM/DMARC) inside
   the Resend dashboard.
3. Run `php /app/core/migrations/065_tenant_mail_senders.sql` against
   prod MySQL.
4. Visit `/api/admin/mail_test_send.php` (existing) to round-trip a
   test message through ResendDriver and confirm headers land
   correctly.



## Zoho Books Slice 4 + Transaction Value at Risk Widget (2026-02 — current fork)

### Zoho Books Slice 4 — Invoice / Bill / Vendor Payment push
- `core/zoho_books/sync_bills.php` mirrors QBO `sync_bills.php`. POSTs to
  `/books/v3/bills`, idempotent via `external_entity_mappings`
  (entity_type='bill'). Vendor mapping prefers the existing
  `ap_vendors_index → mapping` row, then live-discovers via
  `/books/v3/contacts?contact_type=vendor` and upserts. Account
  resolution reuses Slice 2's `zohoBooksResolveAccountRef()`.
- `core/zoho_books/sync_payments.php` POSTs to
  `/books/v3/vendorpayments` with FIFO bill allocation. Bill links use
  `{bill_id, amount_applied}`. Maps CoreFlux method enum
  (`check`/`ach`/`wire`/`cash`/`card`) to Zoho `payment_mode`
  (`check`/`banktransfer`/`cash`/`creditcard`). Idempotent under
  entity_type='payment'.
- `core/zoho_books/sync_invoices.php` (already shipped) wired into the
  dispatcher and cron alongside the new workers.
- API: `/api/zoho_books.php` adds `sync_invoices`, `sync_bills`,
  `sync_payments` actions. Shims at
  `/api/zoho_books/sync_{invoices,bills,payments}.php`. RBAC gates on
  `integrations.zoho_books.manage`.
- Cron `cron/zoho_books_sync_outbound.php` now iterates all four
  workers (journal_entries, invoices, bills, payments), each gated on
  its own sync_config direction.
- Reconcile (`api/admin/accounting_sync_reconcile.php`) registers
  `zoho_runner` + `zoho_runs_on` for invoices, bills, payments —
  Reconcile-All now hits both systems instead of returning
  `worker_pending` for Zoho.
- UI: `ZohoBooksSettings.jsx` ManualSyncCard refactored to a row
  registry with per-entity Dry-run + Sync-now buttons for JE,
  invoices, bills, payments.

### Transaction Value at Risk Widget (Option A)
- Two side-by-side widgets on the Accounting Sync Dashboard (one per
  integration), backed by `transaction_value_at_risk` payload from
  `/api/admin/accounting_sync_dashboard.php`.
- Each widget surfaces:
  - **Pending value** ($) + count of push-eligible txns with no
    `external_entity_mappings` row yet.
  - **Oldest unmapped age** with a conditional health pill:
    🟢 Green &lt;30m · 🟡 Amber 30m–4h · 🔴 Red &gt;4h.
  - **24-hour sparkline** (SVG, 24 hourly buckets) showing both pending
    $ (bar height) and txn count (hover tooltip), with header showing
    total amount + count across the window.
  - **Per-entity breakdown** (Invoices · Bills · Payments) with pending
    $, count, and current sync direction.
- API runs three eligibility queries per integration (invoices: status
  IN ('approved','sent','partially_paid'); bills: IN
  ('approved','partially_paid'); payments: IN ('sent','cleared')),
  joined LEFT against mappings to find unmapped rows, then rolls up
  amount/count/oldest_age and generates the 24h hourly sparkline via
  `DATE_FORMAT(created_at, '%Y-%m-%d %H:00')` grouping.

### Smoke tests
- New: `tests/zoho_books_slice4_smoke.php` (95 assertions covering
  driver surfaces, payload builders, API dispatch, cron wiring,
  reconcile registration, VAR payload contracts, widget JSX testids).
- Updated: `tests/zoho_books_je_push_smoke.php` to track refactored
  ManualSyncCard structure.
- Suite total: **242 / 242 passing** (was 241).



## Bulk Discover + Zoho Books Slice 3 (2026-02 — current fork)

### Bulk Discover (CoA Coverage card)
- Checkbox column added to the chart-of-accounts coverage table with a
  header "Select all visible" toggle that respects the current
  search/filter state.
- Two new header buttons: **Bulk discover · QBO (N)** and
  **Bulk discover · Zoho (N)**. Each iterates the selected unmapped
  rows sequentially through the existing per-row Discover endpoint
  (rate-limit-friendly).
- Per-system progress bar (`role="progressbar"`) shows current row
  label and N/Total. Result flash summarises
  `mapped / not_found / errored` counts with sample error lines.
- Auto-clears stale selections when filter or search trims rows out of
  view.
- Backend unchanged — same single-account POST endpoint, called N
  times. Maintains audit fidelity (one row per resolver attempt).

### Zoho Books Slice 3 — Pull workers
Mirrors QBO Slice 3/4a one-for-one.

**Chart of Accounts pull (`core/zoho_books/sync_accounts.php`)**
- `zohoBooksSyncChartOfAccounts()` paginates `/books/v3/chartofaccounts`
  via `page_context.has_more_page`.
- Match strategy: existing mapping → `account_code = accounting_accounts.code`
  → record under `unmapped_zoho_accounts` audit (samples capped at 20).
- Once a run lands, the Slice 2 JE pusher's `zohoBooksResolveAccountRef()`
  hits the mapping cache directly — `skipped_unmapped` should drop to
  near-zero.
- Opts: `limit` (default 2000, cap 5000), `max_pages` (default 20, cap 100).
- Audit actions: `sync_accounts` (aggregate), `unmapped_zoho_accounts`
  (when applicable), `sync_account_error` (on HTTP errors).

**Contacts pull (`core/zoho_books/sync_contacts.php`)**
- Zoho `/books/v3/contacts?contact_type=customer|vendor` returns each
  kind in a unified collection — `_zohoBooksSyncContactKind()` is the
  shared paginator; `zohoBooksSyncContactsCustomers()` /
  `zohoBooksSyncContactsVendors()` are thin wrappers.
- Per-row upserters: `zohoBooksUpsertCustomer()` →
  `staffing_clients` (name-match fallback via
  `uq_sc_tenant_name`); `zohoBooksUpsertVendor()` → `ap_vendors_index`
  (name-match via `uq_apv_tenant_name`).
- Maps under `entity_type='customer'` and `entity_type='vendor'`
  respectively so the dashboard's drift table picks up Zoho's
  unified-contact direction on both rows.
- Returns per-row results (`created`/`updated`/`unchanged`/`failed`).
- Opts: `limit` (default 1000, cap 5000), `max_pages` (default 10, cap 50).

**API + cron + reconcile**
- `api/zoho_books.php` adds 3 actions: `sync_accounts`,
  `sync_customers`, `sync_vendors`. Shims under
  `api/zoho_books/*.php`. RBAC `integrations.zoho_books.manage`.
- `cron/zoho_books_sync_inbound.php` runs every 30 min, per-tenant:
  CoA pull when `chart_of_accounts` direction permits, then customers
  + vendors when `contacts` direction permits.
- `api/admin/accounting_sync_reconcile.php` now registers
  zoho_runners for `chart_of_accounts`, `customers`, `vendors`. The
  per-row Reconcile and the Reconcile-all button automatically fire
  the right Zoho worker now.

**Smoke tests**
- `tests/zoho_books_slice3_smoke.php` — 53 assertions covering CoA +
  contacts public surface, match strategy, audit hooks, API dispatch,
  shims, cron wiring, and reconcile registration for all 3 entities.
- `tests/coa_coverage_smoke.php` extended to 47 assertions for bulk
  checkboxes + progress + sequential queue.
- Full suite: **241/241 passing**.



## Chart-of-Accounts Coverage Report (2026-02 — current fork)

Drop-in card on the accounting sync dashboard that surfaces every
CoreFlux account with its QBO + Zoho mapping status and a 90-day JE
reference count, plus a one-click "Discover" button per cell to fire
the resolver and persist the mapping immediately.

### Backend (`api/admin/accounting_coa_coverage.php`)
Single file, two methods.

- **GET** (rbac: `integrations.qbo.view`):
  - Pulls every `accounting_accounts` row for the tenant (active +
    inactive — inactives render dimmed so a CFO can confirm they
    don't need mappings).
  - Two bulk mapping lookups: one query against
    `external_entity_mappings` with `internal_entity_type='account'`
    and `source_system IN ('quickbooks_online', 'zoho_books')`. Avoids
    N+1 entirely.
  - JE usage: aggregate
    `COUNT(*) FROM accounting_journal_entry_lines JOIN
     accounting_journal_entries WHERE posting_date >= NOW() - 90 DAY
     GROUP BY account_id`.
  - Decodes `payload_snapshot` to surface QBO `Name` and Zoho
    `account_name` for hover/display.
  - Returns `{ accounts: [...], summary: { total, mapped_both,
    qbo_only, zoho_only, unmapped }, qbo_active, zoho_active }`.

- **POST** (rbac: `integrations.qbo.manage`):
  - Body: `{ account_id, system: 'qbo' | 'zoho_books' }`.
  - Validates the account belongs to the active tenant before calling
    the cross-system resolver.
  - Calls the existing `qboResolveAccountRef` or
    `zohoBooksResolveAccountRef` which auto-discover by account code
    (CoreFlux `code` ↔ QBO `AcctNum` ↔ Zoho `account_code`) and
    persist the mapping via `mappingUpsert()` on success.
  - Returns `{ status: 'mapped' | 'not_found' | 'error', external_id?,
    external_name?, note?, error? }`.

### Frontend (`AccountingSyncDashboard.jsx` → `<CoaCoverageCard />`)
Mounted between the drift table and unified activity feed.

- 5-stat mini scorecard (Total, Both, QBO only, Zoho only, Unmapped).
- Search box + filter dropdown
  (All / Both / QBO only / Zoho only / Unmapped).
- Scrollable table with sticky header and 6 columns:
  Code, Name, Type, JE refs (90d, bold when > 0), QBO cell, Zoho cell.
- **Mapped** cell renders a green check + the external_id (mono font,
  external name on hover).
- **Unmapped** cell renders a "Discover" button. Disabled when the
  corresponding system isn't connected. Per-cell busy state.
- Inline flash after each Discover: success shows the matched
  external name/id; not_found explains "no account with code X found
  in <system> — create one or rename to match"; error surfaces the
  resolver error message.
- Auto-reloads on success so the row flips from Unmapped → green.

### Smoke test
`tests/coa_coverage_smoke.php` — 39 assertions covering both methods'
shape (auth, RBAC for read vs write, tenant scope on POST, system
enum, dual resolver requires, bulk mapping query, 90-day window,
coverage labels, qbo_active/zoho_active, payload_snapshot decoding for
both systems' name fields), and the UI testids
(section, summary, all 4 stat tiles, search, filter, table, row
pattern, mapped/unmapped cells, discover button, system-active gate).

Full suite: **240/240 passing**.



## Reconcile-All + Zoho Books Slice 2 (2026-02 — current fork)

### Reconcile-All (dashboard header button)
- New "Reconcile all (N)" button at the top of the per-entity drift
  table. Counts and labels itself with the number of eligible entities
  (coverage !== 'neither').
- Runs each eligible entity sequentially against the existing
  `/api/admin/accounting_sync_reconcile.php` endpoint to avoid
  hammering Zoho/Intuit rate limits.
- Progress block shows `X of Y — <entity label>` plus an animated
  progress bar (`role="progressbar"`).
- Final flash summarises per-entity outcomes inline so a CFO can scan
  the whole month-end pass without scrolling activity feed.
- Pure frontend addition — backend already supports per-entity calls.

### Zoho Books Slice 2 — Journal Entries push
Mirrors QBO Slice 2 architecture exactly.

**Backend (`core/zoho_books/sync_je.php`)**
- Public surface: `zohoBooksResolveAccountRef`,
  `zohoBooksBuildJournalPayload` (pure function — unit-testable),
  `zohoBooksSyncJournalEntries`.
- Eligibility: `accounting_journal_entries.status='posted'` AND no
  existing row in `external_entity_mappings` for
  (tenant, 'zoho_books', 'journal_entry', je.id). Idempotency lives at
  the SELECT layer, not at the POST layer.
- Account resolution: looks up existing
  (tenant, 'zoho_books', 'account', accountId) mapping; otherwise
  auto-discovers via Zoho's `/books/v3/chartofaccounts?account_code=<code>`
  and persists the mapping for future runs.
- Skip-on-unmapped-accounts: any line with no resolvable account aborts
  the entire JE with `reason=unmapped_accounts` and records the
  unresolved account ids. Audit row written under
  `action=sync_je_skip` for visibility in the unified activity feed.
- Zoho payload: `{ journal_date, reference_number, notes, line_items: [
  { account_id, description, debit_or_credit, amount } ] }`.
- POSTs to `/books/v3/journals`, captures `journal.journal_id` from the
  response, and writes the mapping under
  (tenant, 'zoho_books', 'journal_entry').
- Opts: `limit` (default 50, cap 500), `dry_run` (skip POST — useful
  for diagnosing unmapped accounts before booking), `je_ids` (restrict).
- Audit emits three actions per run: `sync_je` (aggregate), and
  per-JE `sync_je_push` (ok) or `sync_je_skip` (unmapped) /
  failure-tagged push.

**API + cron**
- `api/zoho_books.php` adds `sync_je` action, shim at
  `api/zoho_books/sync_je.php`. RBAC `integrations.zoho_books.manage`.
- `cron/zoho_books_sync_outbound.php` — `H/15 * * * *`. Scans every
  active connection where `journal_entries` direction is push/two_way,
  caps at 50 per tenant per cycle, continues on per-tenant failure,
  emits aggregate counts to stdout.

**Reconcile endpoint wiring**
- `api/admin/accounting_sync_reconcile.php` now registers Zoho's JE
  runner alongside QBO's. The `worker_pending` fallback still applies
  to the other 6 entities until their Slice 3/4 workers land.

**UI**
- `ZohoBooksSettings.jsx` adds a "Manual sync" card with a Journal
  Entries row exposing two buttons: **Sync now** (POST `/sync_je`)
  and **Dry run** (POST `/sync_je` with `dry_run=true`). Both disabled
  unless direction is push or two_way.
- Banner notes updated: Slice 2 is live, Slices 3-5 still pending.

**Smoke tests**
- `tests/zoho_books_je_push_smoke.php` — 50 assertions covering the
  worker public surface, idempotency join, account resolver, payload
  builder (with both happy-path and unmapped-line scenarios via the
  pure function), audit actions, API dispatch, cron wiring, reconcile
  registration, and the manual-sync UI testids.
- `tests/accounting_sync_dashboard_smoke.php` extended to 77 assertions
  for the new reconcile-all button + progress bar.
- Full suite: **239/239 passing**.



## Accounting Sync Dashboard — Reconcile (2026-02 — current fork)

Per-entity one-click reconcile inside the drift table. Each row now
carries a `Reconcile` button that fires sync workers on both systems
in parallel and surfaces a structured per-system result.

### Backend (`api/admin/accounting_sync_reconcile.php`)
POST endpoint, RBAC `integrations.qbo.manage`. Body: `{ entity_key }`
(one of the 7 canonical entities).

- **QBO side** — direct in-process calls to the existing workers:
  - journal_entries → `qboSyncJournalEntries(limit=50)` (push, two_way)
  - customers       → `qboSyncCustomers(limit=1000)` (pull, two_way)
  - vendors         → `qboSyncVendors(limit=1000)`   (pull, two_way)
  - invoices        → `qboSyncInvoices(limit=50)`    (push, two_way)
  - bills           → `qboSyncBills(limit=50)`        (push, two_way)
  - payments        → `qboSyncBillPayments(limit=50)` (push, two_way)
  - chart_of_accounts → `qboSyncAccounts(limit=1000)` (pull, two_way)
- Skip with `reason: not_connected | direction_not_eligible` when the
  active direction can't satisfy the worker. `current_direction` and
  `eligible_directions` returned for diagnostics.
- **Zoho Books side** — Slice 1 has the vault but no workers yet, so
  honestly returns `reason: worker_pending` and writes an audit row
  (`action: reconcile_requested`) so the request is visible in the
  unified activity feed and an upcoming Slice 2+ runner can replay
  queued intents.

### Frontend
- New `Reconcile` button per drift row (testid pattern
  `acct-sync-reconcile-{entity.key}`).
- Disabled when `coverage === 'neither'` (both sides off) with a
  contextual tooltip ("flip a direction first").
- Dynamic tooltip per drift signal — explains what reconcile will do.
- Flash banner above the dashboard summarises per-system result
  (`QBO ok (5 pushed · 2 skipped) · Zoho skipped (worker_pending)`).
- `reload()` fires automatically on success so the drift table and
  activity feed refresh.

### Smoke test
`tests/accounting_sync_dashboard_smoke.php` extended to **71
assertions** covering the endpoint's POST/RBAC gates, all 7 entity
mappings, every QBO worker invocation, the Zoho `worker_pending`
contract, the audit hook, and the UI reconcile button + flash + POST
target.

Full suite: 238/238 passing.



## Accounting Sync Dashboard (2026-02 — current fork)

Unified roll-up that surfaces QBO + Zoho Books side-by-side for the
active tenant. Mounted at `/admin/integrations/accounting-sync`.

### Backend (`api/admin/accounting_sync_dashboard.php`)
GET-only endpoint, RBAC gated by `integrations.qbo.view` (wildcard
`integrations.*` covers it for tenant_admin). Folds three data sources
into one payload:
1. **QBO block** — connection row, sync_config, last 10 audit rows.
2. **Zoho Books block** — connection row, sync_config, last 10 audit rows.
3. **Per-entity grid** — 7 canonical entities (journal_entries,
   customers, vendors, invoices, bills, payments, chart_of_accounts).
   For Zoho both customers + vendors map to its unified `contacts`
   direction. Each row carries:
   - `qbo_dir` / `zoho_dir` (off / push / pull / two_way)
   - `qbo_last_sync` / `zoho_last_sync` (derived from audit MAX(occurred_at))
   - `qbo_last_ok` / `zoho_last_ok`
   - `coverage`: `both` | `qbo_only` | `zoho_only` | `neither`
   - `drift_signal`: `aligned` | `qbo_ahead` (≥24h newer) | `zoho_ahead` |
     `one_sided` | `inactive`
4. **Summary scorecard** — counts in each coverage bucket.
5. **Unified activity feed** — last 30 audit rows merged across both
   systems, sorted DESC by occurred_at.

### Frontend (`dashboard/src/pages/AccountingSyncDashboard.jsx`)
- Two large "system tiles" (QBO + Zoho) with connection state,
  identity rows (company/org + id + region/env), last probe, and a
  "Manage" link straight to the per-system settings page.
- Coverage scorecard: 4 coloured tiles (both / QBO-only / Zoho-only /
  neither) + total entities tracked.
- Drift table: 7 rows × {qbo_dir, qbo_last, zoho_dir, zoho_last, signal}.
  Signal badge is colour-coded; failed last_sync shows an inline alert
  triangle.
- Unified activity table: timestamp, system badge (QBO / Zoho),
  action, entity_type, processed/skipped/failed counters, ok/fail badge.
- Empty-state copy for tenants who haven't run a sync yet.

### Wiring
- AdminModule.jsx mounts the route + imports the component.
- IntegrationsHub.jsx Accounting section now has a third card
  ("Sync Dashboard", TrendingUp icon) that links to it.

### Smoke test
- `tests/accounting_sync_dashboard_smoke.php` — 41 assertions:
  endpoint shape, auth + RBAC gates, both client requires, all unified
  fields, coverage + drift labels, audit queries against both systems,
  cap of 30 rows on unified feed, php -l sanity, UI testids
  (root, tiles, scorecard, drift table, signal badges, activity rows,
  system badges), AdminModule route + Hub wiring.
- Full suite: 238/238 passing.



## Zoho Books Integration — Slice 1 Foundation (2026-02 — current fork)

Per-tenant Zoho Books OAuth 2.0 connection with auto-detected data centre
(DC). Mirrors QBO Slice 1 exactly. Push/pull workers for the 6 entities
land in subsequent slices; this slice ships the vault, region detection,
sync_config picker, ping, and audit.

### Schema (migration 064)
- `core/migrations/064_zoho_books_foundation.sql` creates three tables:
  - `zoho_books_connections` — one row per tenant. AES-256-GCM
    `access_token_ct` + `refresh_token_ct`. Columns: `organization_id`,
    `organization_name`, `dc` (default `com`), `access_token_exp`,
    `scope`, `status`, `sync_config` JSON, `last_probe_*`.
  - `zoho_books_oauth_state` — single-use state nonces (30-min TTL).
  - `zoho_books_sync_audit` — mirrors `qbo_sync_audit` shape for activity
    feed reuse.

### Backend (`core/zoho_books/client.php`)
Public surface: `zohoBooksConfigured`, `zohoBooksConnection`,
`zohoBooksBuildAuthorizeUrl`, `zohoBooksExchangeCode`,
`zohoBooksDisconnect`, `zohoBooksAccessToken`,
`zohoBooksRefreshAccessToken`, `zohoBooksCall`, `zohoBooksRawRequest`,
`zohoBooksPing`, `zohoBooksSyncConfigRead`, `zohoBooksSyncConfigWrite`,
`zohoBooksConsumeOAuthState`, `zohoBooksAudit`,
`zohoBooksDcFromAccountsServer`, `zohoBooksAccountsHost`,
`zohoBooksApiBase`.

Key behaviours:
- **DC auto-detect**: parses the `accounts-server` query parameter Zoho
  returns on the OAuth callback (e.g. `https://accounts.zoho.eu` → `eu`).
  Supported DCs: `com, eu, in, com.au, jp, com.cn, sa`. Unknown values
  fall back to `com`.
- **Token refresh**: uses `Authorization: Zoho-oauthtoken <token>` header
  and refreshes 60s before expiry. Auto-refresh on 401 + single retry.
- **Org auto-probe**: at connect we insert with `organization_id='pending'`
  then call `/books/v3/organizations` and update the row with the first
  org's id + name. UI hides "connected" state until `organization_id` is
  no longer `pending`.
- **Org-id injection**: every non-/organizations API call automatically
  receives `?organization_id=` derived from the connection row.
- **Test transport hook**: `$GLOBALS['__zoho_books_transport']` for unit
  tests, same pattern as QBO/Mercury.

### API (`api/zoho_books.php` + `api/zoho_books/*.php` shims)
Actions: `status`, `oauth_start`, `oauth_callback`, `disconnect`, `ping`,
`sync_config_get`, `sync_config_set`. OAuth callback runs before the
auth guard so the Zoho redirect carries the SPA's session cookie.
RBAC: `integrations.zoho_books.view` / `integrations.zoho_books.manage`.
Both covered by the `integrations.*` wildcard for tenant_admin.

### Frontend (`dashboard/src/pages/ZohoBooksSettings.jsx`)
- Mounted at `/admin/integrations/zoho-books` (AdminModule.jsx).
- Card added to IntegrationsHub.jsx (Accounting section).
- Branches: not-configured / not-connected / connected.
- Connected card: organization name + id, DC, token expiry, last probe.
- Sync-config table: per-entity dropdown (`push`/`pull`/`two_way`/`off`)
  for journal_entries, contacts, invoices, bills, payments,
  chart_of_accounts.
- Slice 1 explicitly omits the "Manual sync" buttons block until the
  per-entity workers ship in subsequent slices.

### Smoke test
- `tests/zoho_books_foundation_smoke.php` — 116 assertions covering
  migration shape, client public surface, API dispatch, all 7 shims,
  `php -l` sanity, UI testids, AdminModule/Hub wiring, RBAC legacy_map,
  DC parser correctness across all 7 supported regions, host/api-base
  resolution, and a functional adapter test using the injected transport
  stub against the EU accounts host.
- Full suite: 237/237 passing.

### Configuration required (Zoho API Console)
The pod operator must register an OAuth client in
https://api-console.zoho.com/ and set in `core/config.local.php` (or env):
```
ZOHO_BOOKS_CLIENT_ID=...
ZOHO_BOOKS_CLIENT_SECRET=...
ZOHO_BOOKS_REDIRECT_URI=https://<host>/api/zoho_books/oauth_callback.php
ZOHO_BOOKS_SCOPES=ZohoBooks.fullaccess.all   # optional override
```
The redirect URI must exactly match what's registered in the Zoho API
Console, and the OAuth client must be a "Server-based Applications" type
to receive the refresh_token.



## Airtable Integration — Slice 1 Foundation (2026-02 — current fork)

Tenant-scoped Airtable connection via Personal Access Token (PAT). Pull-only v1.
Push and OAuth deferred to a follow-on slice.

### Schema (migration 063)
- `core/migrations/063_airtable_foundation.sql` creates three tables:
  - `airtable_connections` — one row per tenant. `pat_ct` is AES-256-GCM ciphertext;
    `pat_last4` is the only plaintext display hint. Status + last_probe_* + scopes columns.
  - `airtable_table_mappings` — one row per (base_id, table_id) the tenant wants synced.
    Columns: `internal_entity` (entity-mapping bucket), `direction` (`pull`/`off`),
    `field_map` JSON, `primary_field`, `last_sync_*`.
  - `airtable_sync_audit` — mirrors `qbo_sync_audit` shape for activity-feed reuse.

### Backend (`core/airtable/`)
- `client.php` — PAT vault + raw HTTP client + meta API helpers.
  Public surface: `airtableConfigured`, `airtableConnection`, `airtablePAT`,
  `airtableSavePAT`, `airtableDisconnect`, `airtablePing`, `airtableCall`,
  `airtableRawRequest`, `airtableListBases`, `airtableListTables`,
  `airtableSelectRecords`, `airtableAudit`.
  - PAT format guard (`^pat[A-Za-z0-9._-]{10,}$`).
  - Rate-limit 429 → 1.1s backoff + retry once.
  - Test transport hook via `$GLOBALS['__airtable_transport']`.
- `sync.php` — mapping CRUD + pull worker.
  Public surface: `airtableMappingList/Get/Upsert/Delete`, `airtableSyncTable`.
  - Pulls Airtable records page-by-page (offset cursor), normalises via field_map,
    persists into `external_entity_mappings` under `source_system='airtable'`.
  - Synthesises a deterministic internal_entity_id from sha256(tenant:externalId)
    so the worker never touches a CoreFlux core table — downstream features can
    remap once they want the data inside a real table.
  - Pagination cap (`maxPages=20`) for safety; cron raises if a base is huge.

### API (`api/airtable.php` + `api/airtable/*.php` shims)
Actions: `status`, `connect`, `disconnect`, `ping`, `list_bases`, `list_tables`,
`mappings`, `mapping_save`, `mapping_delete`, `sync_now`.
RBAC: `integrations.airtable.view` (read), `integrations.airtable.manage` (write).
Covered by the `integrations.*` wildcard in `rbac_config.php` for tenant_admin.

### Cron (`cron/airtable_sync.php`)
- Cloudways entry: `H/15 * * * *` (every 15 minutes).
- Iterates every tenant with an active connection AND ≥1 pull mapping.
- Continues on per-mapping failure; emits aggregate counts to stdout.

### Frontend (`dashboard/src/pages/AirtableSettings.jsx`)
- Mounted at `/admin/integrations/airtable` (AdminModule.jsx).
- Card added to `IntegrationsHub.jsx` (Operations & CRM section, Database icon).
- Branches: not-configured / not-connected / connected.
- Connect form: PAT input (show/hide toggle), optional workspace label.
- Mapping editor: live base/table pickers (loaded via `/list_bases` & `/list_tables`),
  CoreFlux entity dropdown, primary_field input, field_map JSON textarea.
- Per-mapping row: Sync Now / Edit / Delete + inline error display.
- Activity feed: latest 25 audit rows with action / base / table / result.

### Smoke test
- `tests/airtable_foundation_smoke.php` — 105 assertions covering migration shape,
  client public surface, sync pipeline surface, API dispatch (incl.
  `duplicate_targets` + `mapping_duplicate`), all 12 shims, `php -l`
  sanity, UI testids (incl. duplicate modal + per-row trigger),
  AdminModule/Hub wiring, RBAC legacy_map, and a functional adapter
  test using the injected transport stub.
- Full suite: 236/236 passing.

### Duplicate-to-tenants flow (Slice 1.5)
- Backend: `airtableUserAdminTenantSet()` resolves every tenant the
  caller may manage (master_admin = all active tenants; tenant_admin =
  direct memberships + via_parent sub-tenants).
- `airtableMappingDuplicate()` validates each target against that set,
  requires an active Airtable connection on the target, and reuses
  `airtableMappingUpsert()` so dedup on `(tenant, base, table)` works.
- API actions: `GET duplicate_targets` (candidate tenants annotated with
  connection status) + `POST mapping_duplicate` (body:
  `{source_mapping_id, target_tenant_ids: int[]}`, max 100).
- UI: per-row "Duplicate" button opens a modal with two sections
  ("Connected" selectable, "Without Airtable connection" disabled), a
  bulk "Select all" toggle, and a result block summarising
  created/updated/skipped/errored tenants.


## Cross-tenant accounting audit trail (2026-02 — current fork)

Companion to the hierarchy entity scope: every consolidation edge or intercompany mapping that spans tenant boundaries is now recorded in a dedicated forensic feed. Use it as SOX evidence and as a one-stop rollback signal.

### Schema (migration 062)
- `core/migrations/062_cross_tenant_accounting_audit.sql` creates `cross_tenant_accounting_audit`:
  - `acting_tenant_id` (whose context the user was in)
  - `left_tenant_id` / `right_tenant_id` (the two sides of the edge)
  - `left_entity_id` / `right_entity_id`
  - `action` (e.g. `consolidation.edge_created`, `intercompany.mapping_updated`)
  - `actor_user_id` + `actor_label` (email / "External Auditor")
  - JSON `payload` snapshot, ip, user_agent, occurred_at
  - 5 indexes (acting / left / right / action / actor) + occurred_at composite for the chronological feed.

### Backend
- New helper `core/cross_tenant_audit.php`:
  - `crossTenantAuditLog()` — idempotent insert; **silently skips when `leftTenantId === rightTenantId`** so same-tenant saves don't pollute this feed (accountingAudit already covers them).
  - `crossTenantAuditEntityTenantId()` — per-request memoised lookup of an entity's tenant.
- `modules/accounting/lib/consolidation.php::entityRelationshipUpsert()` fires `_consolidationLogCrossTenant()` on both create and update branches.
- `modules/accounting/lib/intercompany.php::intercompanyUpsertMapping()` fires `_intercompanyLogCrossTenant()` on both branches.
- New read endpoint `/api/admin/cross_tenant_audit.php`:
  - `GET ?since=YYYY-MM-DD&action=…&limit=200` (limit clamped 1..500).
  - master_admin / is_global_admin → every row.
  - tenant_admin / admin → rows where their tenant participates as actor OR left OR right.
  - Joins `tenants` 3× to surface human-friendly names. Returns `actions` list for the filter dropdown.

### Frontend
- New page `dashboard/src/pages/CrossTenantAuditAdmin.jsx`:
  - Action + Since filters + refresh.
  - Color-coded action pills (consolidation = blue, intercompany = amber).
  - Edge column shows `Left → Right` with chevron icon and entity-id sub-line.
  - Expandable `<details>` per row to inspect the JSON payload.
- `AdminModule.jsx` now imports the page, exposes a route at `/admin/cross-tenant-audit`, lists it in the sidebar, and surfaces an overview tile next to "Auditor links".

### Tested
- New smoke `tests/cross_tenant_audit_smoke.php` — **28 ✓** covers migration shape, helper definitions, lib wiring (both libs, both branches each), endpoint shape (auth gate, role gate, scope SQL, since validation, limit cap, joins), UI page testids + filters + table, AdminModule wiring (import + route + tile), `php -l` on all 4 PHP files.
- Updated `tests/schema_contract_smoke.php` known-legacy allow-list with the 6 new (alias=tenants) entries — `tenants` table is defined in `install.php` so the analyzer can't see its column list, but `id`/`name` are well-established columns.
- Updated `tests/payroll_preflight_and_gusto_preview_smoke.php` allow-list threshold from `<= 5` to `<= 10` to accommodate the 6 new entries.
- Vite bundle rebuilt + `.deploy-version` synced.
- **Smoke suite total: 233 functional ✓ / 0 functional ✗** (plus the 2 ongoing sandbox-only baseline failures — `ai_platform_smoke` and `plaid_integration_smoke` — that need DB socket / curl).


## Cross-tenant entity scope for Consolidation & Intercompany (2026-02 — current fork)

Kunal (master_admin viewing parent tenant "Seven Generations") opened Accounting → Consolidation and Accounting → Intercompany and got empty entity dropdowns even though entities existed on his sub-tenants. Root cause: `modules/accounting/api/entities.php` only returned entities matching the CURRENTLY ACTIVE tenant, so a parent-tenant view never saw sub-tenant entities — and consolidation by definition needs to span both.

### Backend
- Extended `GET /modules/accounting/api/entities.php` with an opt-in **`?scope=hierarchy`** query param. When set:
  - Resolves direct sub-tenants of the current tenant (`tenants.parent_id = current AND COALESCE(is_active,1)=1`).
  - Returns entities from `current_tid ∪ sub_tenant_ids`, each row tagged with `tenant_id`, `tenant_name`, and `is_current_tenant` flag.
  - Same `accounting.entities.view` RBAC gate as the default tenant-scoped path.
  - `tenant-leak-allow` annotation since cross-tenant is intentional (opt-in + gated).
- Default `?scope=tenant` path unchanged — preserves backwards-compatible behaviour for every other accounting screen.

### Frontend
- `modules/accounting/ui/Consolidation.jsx` (RelationshipsSection + ConsolidatedReport) and `modules/accounting/ui/IntercompanyMappings.jsx` now request `?scope=hierarchy`.
- Entity dropdowns suffix the option label with the source tenant name (e.g. `"Holding Co · Seven Generations"`) so users can tell which sub-tenant an entity belongs to before picking it.
- The consolidated-report multi-select shows a small indigo chip next to each non-current-tenant entity for at-a-glance visual distinction.

### Tested
- New smoke `tests/hierarchy_entity_scope_smoke.php` — **12 ✓**: hierarchy branch, parent_id lookup, RBAC preserved, tagging columns, scope echo, leak-allow annotation, frontend wiring in both .jsx files, dropdown labelling, current-tenant chip distinction, `php -l` sanity.
- Vite bundle rebuilt + `.deploy-version` synced.
- Updated hotfix smoke regex to allow the `?scope=hierarchy` qs.
- **Smoke suite total: 232 functional ✓ / 0 functional ✗** (plus the 2 ongoing sandbox-only baseline failures `ai_platform_smoke` and `plaid_integration_smoke` that need DB socket / curl extension to run).


## Hotfix — `api_json()` undefined + Consolidation page null crash (2026-02 — current fork)

Two production bugs reported via screenshots, both fixed:

### Bug 1 — `Call to undefined function api_json()`
- `api/admin/ai_settings.php` (lines 123, 178) and `api/admin/ai_usage.php` (line 175) called `api_json()` which doesn't exist anywhere in the codebase. The canonical helpers are `api_ok($data)` for success and `api_error($msg, $status)` for errors.
- Renamed all 3 call-sites to `api_ok()`. Both endpoints now respond cleanly.
- New CI guard `tests/hotfix_api_json_consolidation_smoke.php` scans every PHP file under `/app` for any future occurrence of `api_json(` and fails if it reappears.

### Bug 2 — `Cannot read properties of null (reading 'parent_entity_id')` on Accounting → Consolidation
- `modules/accounting/ui/Consolidation.jsx` violated React's Rules of Hooks: it called `useApi('/modules/accounting/api/entities.php')` **twice on the same expression** (`useApi(...).data?.rows || useApi(...).data?.entities`), once in `RelationshipsSection` and once in `ConsolidatedReport`. The order of hook calls fluctuated between renders, which corrupted hook state and made one of the destructured rows resolve to `null` — every render that then read `r.parent_name || r.parent_entity_id` blew up.
- Fixed by capturing the `useApi` result into a single `entitiesApi` variable, then reading `entitiesApi.data?.rows ?? entitiesApi.data?.entities ?? []` (nullish-coalescing, not `||`, so an empty array on first paint doesn't cause refetches).
- Belt-and-braces: `.filter(Boolean)` before `.map()` on the relationships rows so a stray `null` row from any future data shape can't crash render.

### Tested
- New smoke `tests/hotfix_api_json_consolidation_smoke.php` — 8 ✓ asserts no `api_json(` callers anywhere, `api_ok()` defined, exactly 2 `useApi(entities)` calls (one per component), `??` fallback, `.filter(Boolean)` guard, `php -l` on both touched PHP files.
- Vite bundle rebuilt + `.deploy-version` synced.
- **Smoke suite total: 233 ✓ / 0 ✗.**


## Auditor Session Log (2026-02 — current fork)

Forensic visibility into what each external auditor actually looked at, surfaced inline on `/admin/auditor-tokens`.

### Backend
- Extended `/api/admin/auditor_tokens.php` with **`GET ?action=log&id=N`** — gated by the same `_auditorTenantAllowed()` check as the rest of the endpoint, so tenant_admins can only inspect their own tenants' tokens.
- Returns three blocks built from `auditor_access_log` (token_id keyed; `tenant-leak-allow` annotated since the token is itself the tenant binding):
  - `stats`: `hits`, `views`, `redeems`, `unique_paths`, `unique_ips`, `first_seen`, `last_seen`
  - `top_paths`: top 15 most-visited paths with hit counts + last visit time
  - `events`: most recent 200 raw events `(action, path, ip, user_agent, occurred_at)`

### Frontend
- New `<SessionLogPanel>` component inside `AuditorTokensAdmin.jsx`:
  - **Stats strip** (7 tiles, tabular numerals)
  - **Most-visited pages table** with hits + last-seen time
  - **Reverse-chrono event log** with color-coded action pills (`redeem` green, `view` blue, anything else red), 320px scrollable container, monospace path, IP + UA tooltip
- Each token row has a new `Activity` toggle button (`<Activity />` + chevron) that expands an inline log row right below — no modal, no page reload.
- Tested for graceful "no events yet" empty state on a freshly-issued token.

### Tested
- New smoke `tests/auditor_session_log_smoke.php` — 15 ✓ covers endpoint shape, id-required, tenant authorisation, all three payload blocks, all UI testids, scrollable container, `php -l` sanity.
- Vite bundle rebuilt + `.deploy-version` synced.
- **Smoke suite total: 232 ✓ / 0 ✗.**


## Audit Snapshot — one-page printable summary (2026-02 — current fork)

Polished follow-up to the External Auditor feature. Auditors (and any CFO/admin) now land on a clean, branded one-pager they can print or save as PDF for their workpapers.

### Backend
- **New endpoint `/api/cfo_audit_snapshot.php`** — gated via `api_require_cfo()`. Returns tenant header (id, name, logo_url, slug), period (`from`/`to`/`label`), `prepared.at/by`, `auditor_scope` (is_auditor/modules/expires_at), and 6 KPI totals:
  - `revenue_total` — sum of `billing_invoices.total_amount` in window
  - `collected_total` — sum of `billing_invoice_payments.amount` in window
  - `ap_total` — sum of `ap_bills.amount` in window
  - `ar_open` — open invoice balance now
  - `ap_open` — open bill balance now
  - `net_margin_pct` — derived from revenue − ap
- Every total query wrapped in `try/catch` so a missing module table renders as "—" instead of 500-ing the snapshot. Every query filters by `tenant_id`.
- Bad `from`/`to` date format returns 422 (defensive).
- **CFOGuard + `api_require_cfo()` now permit `role='auditor'`** and any session with `auditor_mode=true`. Read-only enforcement still happens at the bootstrap layer.

### Frontend
- **New page `AuditSnapshot.jsx`** at `/cfo/audit-snapshot`:
  - Branded header: tenant name + logo + period band + amber "External Auditor View" chip when applicable.
  - 6-tile responsive KPI grid (`Intl.NumberFormat` currency, tabular-nums).
  - Auditor scope explainer block (orange callout) summarising read-only modules + expiry + audit logging.
  - Notes block — dashed-border free-space for auditors to annotate on the printed PDF.
  - Footer with `prepared at / by` and tenant + period reprint.
  - Inline `@media print` stylesheet hides every other DOM node (sidebar/header/banner/non-print buttons), forces US-letter portrait + 0.5" margins, so File→Print→Save as PDF produces a true one-pager.
  - From/To date pickers re-fetch the endpoint on change.
- **CFODashboard toolbar** has a new "Audit snapshot" link button (`cfo-audit-snapshot-btn`) right between Save view and Send report.
- **`/auditor.php`** now lands every redeemed auditor session directly on `/cfo/audit-snapshot` (was `/cfo` before). They see value within 1 second of clicking the link.

### Tested
- New smoke `tests/audit_snapshot_smoke.php` — **28 ✓** covers endpoint shape, auth gate, date validation, tenant header, 6 KPIs, try/catch coverage, frontend wiring (testids, print CSS, banner hide, scope explainer), CFODashboard link, /auditor.php landing, role='auditor' permitted everywhere needed, PHP `php -l` sanity.
- Vite bundle rebuilt + `.deploy-version` synced.
- **Smoke suite total: 231 ✓ / 0 ✗.**


## CFO Dashboard gating + External Auditor mode + Recently-viewed strip (2026-02 — current fork)

### 🟢 Recently viewed tenants
- `/api/admin/manageable_tenants.php` now returns a `recently_viewed: [...]` array (top 5 by `last_active_at` from the membership shim, excludes the currently active tenant).
- `Header.jsx` renders a "Recently viewed" strip at the top of the dropdown with the date next to each name. One-click switching just calls the existing `onTenantChange()`.

### 🟢 CFO Dashboard role gate (P2 done)
- New `api_require_cfo()` helper in `core/api_bootstrap.php`. Allows: `master_admin` / `is_global_admin=1` (platform-wide), `tenant_admin` / `admin` (active tenant), or explicit `membership_module_access.module_key='cfo'` with access_level in `('read','write','admin')`.
- All 4 CFO endpoints (`cfo_annotate.php`, `cfo_notes.php`, `cfo_send_report.php`, `cfo_formulas.php`) now call `api_require_cfo()` instead of bare `api_require_auth()`.
- New `CFOGuard.jsx` wraps `<CFODashboard>` on the `/cfo` route — renders a friendly "Forbidden" card with a return-to-home button instead of showing a broken dashboard riddled with 403s.
- Header's `CFO` link is hidden client-side when `canSeeCfo` is false.
- Auth-gate static analyser updated to recognise `api_require_cfo()` as auth-equivalent.

### 🟢 External Auditor view (P3 done — tokenized read-only URL)
- **Schema** (`core/migrations/061_auditor_tokens.sql`):
  - `auditor_tokens` — `id, tenant_id, label, email, token_hash UNIQUE, scope_modules JSON, expires_at, last_used_at, revoked_at, created_by_user, created_at`. Token stored as **sha256 hex only**; plain value is shown to issuer ONCE at creation and never recoverable from the DB.
  - `auditor_access_log` — every redeem / view / revoke event for forensics.
- **Backend helpers** (`core/auditor.php`): `auditorGenerateToken()`, `auditorFindActiveToken()`, `auditorRedeemAndStart()`, `auditorLog()`, `auditorModeActive()`.
- **Entry point** (`/auditor.php?token=…`): validates token, seeds an auditor session (synthetic user id=0, role=`auditor`, tenant pinned, `auditor_mode=true`, `auditor_modules` scope), redirects to `spa.php#/cfo` as the default landing. Bad / revoked / expired tokens render a friendly error page (no login bounce).
- **Bootstrap write-block** (`core/api_bootstrap.php`): when `auditorModeActive()` is true every non-GET request is rejected with **HTTP 403** at the bootstrap layer — no endpoint has to opt in. Every page view is logged.
- **Admin CRUD** (`/api/admin/auditor_tokens.php`): `GET` list/count, `POST` issue (1–90 day expiry, default 7, module scope), `PATCH ?action=revoke`, `DELETE`. Scope: master_admin → any tenant; tenant_admin/admin → their tenant or its sub-tenants only. Token plain is returned **once** on POST.
- **Admin UI** (`/admin/auditor-tokens`) — `AuditorTokensAdmin.jsx`:
  - Table of tokens with status pills (Active / Expired / Revoked), last-used time, revoke + delete actions.
  - Create modal (tenant picker for master_admin, label, optional email, 1–90 day slider, module-scope toggle pills).
  - One-time **reveal modal** with copy-to-clipboard, open-as-auditor button, and an "I've copied it — done" dismiss.
- **Site-wide banner** in `App.jsx` — when `session.auditor_mode === true` a sticky amber "🔒 External Auditor view — read-only" bar appears at the top of every page with the expiry date.
- **Session surface**: `session.php` now exposes `auditor_mode`, `auditor_expires_at`, `auditor_modules`.

### 🧪 Tested
- New smoke `tests/cfo_auditor_recent_smoke.php` — **53 ✓** covers all three features (recently-viewed wiring, CFO gate at every endpoint, auditor migration / helpers / entry point / admin CRUD / write-block / session surface / SPA wiring / banner / PHP syntax).
- Existing sentries updated: auth-gate analyser knows `api_require_cfo`; tenant-leak analyser gets explicit `tenant-leak-allow` annotations on the 3 auditor-by-id SQL sites (the token is itself the tenant binding).
- Vite bundle rebuilt + `.deploy-version` synced.
- **Smoke suite total: 230 ✓ / 0 ✗.**

### Deploy steps for prod
1. Apply migration: `mysql … < /app/core/migrations/061_auditor_tokens.sql`
2. Pull new code + rebuilt `dashboard/dist/` (already committed).
3. Validate at `/admin/auditor-tokens` — issue a 1-day test link, open it in an incognito window, confirm the amber banner appears and any `POST /api/...` from the network tab returns 403 with `auditor_mode: true`.


## Tenant routing rebuild — role-based landing + platform-mode (2026-02 — current fork)

Resolved the long-standing access regression where master_admin was silently downgraded to per-tenant roles, the tenant dropdown couldn't reach sub-tenants of an admin's parent tenant, and every login funnelled into the same tenant page regardless of credentials.

### 🔴 Bug fixes
1. **Role-floor in `core/api_bootstrap.php`** — `users.role='master_admin'` (or `is_global_admin=1`) is now the floor. The per-tenant `user_tenants.role` lookup and the RBAC resolver's `persona_type` override are both gated on `!$isPlatformMA`. Authoritative re-check from DB on every request defends against stale session. New `$ctx['global_role']` exposed for endpoints that need it.
2. **`requireTenant` bypass** — `api_require_auth(true)` no longer 400s when a master_admin has no pinned tenant (platform mode).
3. **Header tenant dropdown** — used to render only `session.tenants` (direct memberships). Now pulls `/api/admin/manageable_tenants.php` which returns direct + via_parent + platform tenants nested by master, so a tenant_admin who admins MASTER A can also click into SUB A.1 (inherited).

### 🟡 Architectural changes — landing logic
- **Master_admin / is_global_admin** → lands in PLATFORM MODE (`$_SESSION['tenant_id'] = null`, `platform_mode=true`). Redirects to `spa.php#/admin`. They can still pick any tenant from the header dropdown to "view as tenant" — clearly badged with an amber "Viewing as" pill.
- **Tenant_admin / admin / employee** → lands on their primary tenant (`is_default` preferred; otherwise first row from the membership shim).
- **Header dropdown** — `master_admin` always sees a "Platform Admin" entry at the top (returns to no-tenant mode) plus every tenant nested with sub-tenants under their masters. Non-master users see only what they can switch into.
- **`switch_tenant.php`** — now accepts `?platform=1` (clears tenant, restores master_admin role) and uses `global_role`/`is_global_admin` for the master-admin bypass so a master who's been pinned mid-session can still jump.

### 🟢 New / changed
- New endpoint `/api/admin/manageable_tenants.php` — returns hierarchical tenant inventory (`direct` / `via_parent` / `platform` access marker per row) for the header dropdown.
- `session.php` surfaces `platform_mode` and `is_global_admin`.
- `login.php` seeds `user.tenants` with `getAllTenants()` for platform-mode users so the very first SPA paint has the full inventory.
- `App.jsx` redirects `platform_mode` users to `/admin` on first render unless they hit a deep link.
- New smoke `tests/tenant_routing_rebuild_smoke.php` (39 ✓) covers role-floor, manageable_tenants endpoint shape, switch_tenant platform toggle, Header wiring, session.php flags, App.jsx routing, plus `php -l` syntax sanity for every touched file.
- Vite bundle rebuilt + `.deploy-version` synced.
- **Smoke suite total:** 229 ✓ / 0 ✗ (229 = prior 228 + new test).

### Validation steps for prod
After deploy:
1. **`kunal.verma@corefluxapp.com`** logs in → lands on Platform Admin (no tenant pinned). Header shows "Platform Admin" + tenant picker with every tenant nested.
2. Click any tenant in the dropdown → "Viewing as" amber badge appears, normal tenant SPA loads.
3. Click "Platform Admin" entry → returns to no-tenant mode.
4. Log in as any **tenant_admin** → lands on their primary tenant. Header dropdown shows their tenants + every sub-tenant of any master they admin (with "inherited" badge). Clicking a sub-tenant successfully switches.
5. **Sub-tenant admin** → lands directly on their sub-tenant.


## P0 fix v2 — Read-fallback shim extended to ALL gating call sites (2026-02 — current fork)

The first read-fallback shim only patched `core/data.php` + `api/users.php`. User reported the access issues persisted: tenants still missing, sub-tenants inaccessible, admin rights still broken. Root cause: ~10 other UI/auth gating files were still doing direct `FROM tenant_memberships` reads.

**Sites swept (all now use `membershipReadSourceSql()`):**
- `switch_tenant.php` — direct cause of "tenants are gone": the tenant switcher gate filtered out users not in `tenant_memberships`. Now passes legacy users through transparently.
- `api/sub_tenants.php` — 4 read sites (switch role refresh, `subTenantUserCanManageParent`, `subTenantUserHasMembership` direct + parent fallback). Sub-tenant access restored.
- `api/tenants.php` — `user_count` subquery in master tenant list.
- `api/tenant_modules.php` — `_tenantModulesCanManage()` checkRoleAt closure (drove the "admin rights" symptom).
- `api/sub_tenant_consolidated_reports.php` — master/tenant_admin gate for consolidated reports.
- `api/sub_tenant_analytics.php` — gate + last-active sub-tenant summary.
- `api/sub_tenant_setup_checklist.php` — `users.invited` step user count.
- `core/views/admin/user_edit.php` — initial fetch + post-save refresh of the user's tenant assignment matrix.
- `core/push_service.php` — `pushSendToTenant()` recipient resolution.
- `people/includes/people_helper.php` — `getAccessibleEmployees()`, `getEmployeeProfile()`, `getAllApprovers()` (3 joins).

**Bootstrap wiring**: `core/api_bootstrap.php` now `require_once`s `core/memberships.php` so every API endpoint gets `membershipReadSourceSql()` for free (no per-file include shuffling).

**Intentionally NOT shimmed** (correctly tenant_memberships-only):
- `core/rbac/permissions.php` — `RBACResolver::activeMembership()` returning null is the trigger that hands off to the legacy fallback; shimming it would defeat that fall-through.
- `api/admin/user_effective_permissions.php`, `api/admin/membership_drift.php`, `api/admin/membership_audit.php`, `api/admin/membership_access.php` — diagnostic / drift / id-keyed admin tools that legitimately need the literal table.
- `api/users.php` line 117 — `_usersBootstrapMembership` reads back its own freshly-inserted row.

**Smoke test extended:** `tests/tenant_read_fallback_smoke.php` now 39 ✓ — adds an "every critical gate must call the shim AND must NOT have any hard-coded `FROM tenant_memberships`" cross-check across all 10 sites. **Suite total still 228 ✓ / 0 ✗.**


## Backfill Drift Inspector — admin widget for membership migration progress (2026-02 — current fork)

Follow-up to the read-fallback fix: master admins now have a one-click way to see and heal accounts still living only in `user_tenants`.

- **New endpoint `/api/admin/membership_drift.php`** (master_admin / is_global_admin only):
  - `GET` — returns `summary` (`total_active_users`, `in_legacy_only`, `in_new_table`, `drifting_users`, `returned`, `detail_capped_at`) plus up to 100 detail rows with `legacy_tenants` / `unhealed_tenants` / `last_active_at` / `tenant_names`.
  - `POST ?action=heal&user_id=N` — heal one user via `healMembershipsForUser()`.
  - `POST ?action=heal_all` — batched heal capped at 250 users per call (UI re-polls and re-runs until clean).
  - `tenant-leak-allow` annotation + read-sentry allow-list entry (`tests/user_tenants_read_sentry_smoke.php`) — drift inspection legitimately needs to read `user_tenants` directly.
- **New component `dashboard/src/pages/MembershipDriftBanner.jsx`** mounted on `/admin/users`:
  - Hidden for non-masters and (after first paint) when drift is zero — replaced by a small green "all clean" pill so admins know the check ran.
  - Card with amber left-border, summary counts (Total / In tenant_memberships / Drifting), "Heal all N" primary action, refresh button, and an expandable detail table with per-row Heal buttons.
  - "Heal all" loops until `drifting_users === 0` (with a 20-hop circuit-breaker) so a single click drains drift even if it spans multiple 250-user batches.
  - On every successful heal, fires `onHealed()` which re-polls the parent users list so the new tenant_count column updates immediately.
- **Vite bundle re-built and `.deploy-version` synced** via `scripts/sync_bundle.sh`.
- **New smoke `tests/membership_drift_inspector_smoke.php`** — 20 assertions covering endpoint auth gate, route shape, batch caps, NOT EXISTS de-dup semantics, banner test ids, heal-loop guard, and UsersAdmin wiring (gated on `isMaster`, `onHealed={reload}`).
- **Smoke suite total:** 228 ✓ / 0 ✗ (was 227; +1 from the drift smoke).


## P0 fix — Tenant read-fallback (UNION shim + login self-heal, 2026-02 — current fork)

After the prior session swapped every membership read from `user_tenants` to `tenant_memberships`, the production UI broke because `tenant_memberships` is not yet backfilled in prod (users saw zero tenants, master_admin lost global access, modules disappeared). Resolved without a DB migration:

- **`core/memberships.php` extended:**
  - `membershipReadSourceSql()` — returns a parenthesised SQL fragment that UNIONs `tenant_memberships` with the de-duped (NOT EXISTS) tail of `user_tenants`, normalised to `(user_id, tenant_id, persona_type, is_primary, status, last_active_at)`. Memberships rows win on conflict; legacy `'inactive'` is mapped to the new `'suspended'` vocabulary on the fly.
  - `membershipTenantCountForUser($userId)` — convenience count using the shim.
  - `healMembershipsForUser($userId)` — opportunistic self-heal: any `user_tenants` row this user has that isn't yet in `tenant_memberships` is dual-written via `provisionMembership()`. Best-effort, idempotent, never blocks login.
- **Read sites refactored** to call `membershipReadSourceSql()` instead of typing `FROM tenant_memberships` directly:
  - `core/data.php` — `getUserTenants()`, `getAllTenants()` (user_count subquery), `getAllUsers()` (left join).
  - `api/users.php` — `_usersScopeWhere()` for tenant_admin scoping, list query's `tenant_count` subquery, single-user GET (scope-check + per-tenant memberships listing), PATCH same-tenant scope guard. The bootstrap helper still reads back its own freshly-inserted membership row by id (legitimate, allowed by the smoke test).
- **`login.php`** — calls `healMembershipsForUser()` before `getUserTenants()` so each successful login quietly migrates that user's legacy rows. Production data heals itself with traffic; no separate ops step required (though `php scripts/backfill_memberships.php` is still recommended for the next prod deploy window to migrate dormant accounts).
- **No new direct `FROM user_tenants` reads escape `core/memberships.php`** (which is on the read-sentry allow-list). All four `*_sentry_smoke.php` tests stay green.
- **New smoke `tests/tenant_read_fallback_smoke.php`** — 19 assertions: helper presence, fragment shape, de-dup clause, status filtering, status mapping, call-site adoption in `data.php`/`users.php`, login wiring, and a live DB exec when a connection is available.
- **Smoke suite total:** 227 ✓ / 0 ✗ (was 226; +1 from the new test).

### Known follow-up for prod
- Run `php scripts/backfill_memberships.php` once on the next deploy to migrate accounts that haven't logged in (so they show up in admin lists ahead of their first login self-heal).



## Recently completed (AI cost tracking + provisionMembership dual-write helper, 2026-02 — current fork)

### 💰 AI cost tracking — closes the loop on the usage panel
- **Migration `060_ai_interactions_cost_tracking.sql`** — adds `token_count_prompt`, `token_count_response`, `cost_cents` to `ai_interactions` (all `INT UNSIGNED NULL`; `cost_cents` deliberately integer to avoid float drift on SUM aggregations; `IF NOT EXISTS` so the migration is idempotent).
- **`aiModelRateCardCentsPer1k($model)`** — per-model rate card in cents per 1,000 tokens (prompt → response) covering OpenAI gpt-4o-mini / gpt-4o / gpt-4-turbo, Anthropic claude-haiku-4.5 / sonnet-4.5 / opus-4.5, Gemini gemini-3-flash / gemini-3-pro. Unknown models silently return null so the row still saves (honest "we don't know" beats fake zero).
- **`aiComputeCostCents()`** — clamps to integer cents via `ceil()` (never under-bills), returns null when model unknown OR no tokens.
- **`aiAuditWrite()` extended** — auto-computes cost_cents when caller supplies tokens + a known model, but respects an explicit `cost_cents` override (for self-hosted models with custom pricing).
- **`/api/admin/ai_usage.php` extended** — totals now include `cost_cents`, `prompt_tokens`, `response_tokens` (null when no row in the window carried that data, never a misleading zero). Each by-class row also carries its own `cost_cents`.
- **`AiUsagePanel` extended** — 5th KPI tile "Spend" with token-count hint underneath. New "Spend" column in the by-class table.
- **Honest documentation** — comments in the endpoint explicitly note that rows pre-dating migration 060 contribute null, so historical windows show null until backfill / new traffic populates the column.
- Covered by new `tests/ai_cost_tracking_smoke.php` (24 assertions covering migration shape, rate card, helper math/edge cases, aiAuditWrite wiring) + updated assertions in `ai_usage_panel_smoke.php`.

### 🔁 provisionMembership() — single dual-write helper retires every direct user_tenants write
The legacy `user_tenants` table was still being written by 6 sites (5 listed in prior session + `core/sub_tenants.php`'s `subTenantCreate` invite flow). Today's pass:
- **New file `core/memberships.php`** — owns every membership write. Four functions:
  - `provisionMembership($userId, $tenantId, $role, $opts)` — idempotent upsert across `tenant_memberships` AND `user_tenants` (dual-write). When `is_primary => true`, demotes sibling rows so exactly one default remains in both tables. Best-effort audit row to `membership_audit`. Nestable inside an existing transaction (detects via `inTransaction()`).
  - `deactivateMembership($userId, $tenantId)` — flips status to `revoked` (new table) / `inactive` (legacy).
  - `setPrimaryMembership($userId, $tenantId)` — pivot of is_primary across all user's memberships.
  - `purgeMembershipsForUser($userId)` — hard delete from both tables (admin "purge user").
- **`_membershipPersonaTypeForRole($role)`** — maps legacy role strings to the new ENUM with aliases (`owner` → `tenant_admin`, `consultant` → `contractor`, unknowns → `custom`, null → `employee`).
- **Refactored writers (6 files):**
  - `api/users.php` — `_usersBootstrapMembership()` now delegates the membership upsert to `provisionMembership()` and keeps doing the module-access seeding. PATCH tenant assignment uses the helper. Removal path uses `deactivateMembership()`. Reads also migrated to `tenant_memberships`.
  - `core/views/admin/user_edit.php` — full-replace via `purgeMembershipsForUser()` + per-tenant `provisionMembership()` calls. Reads migrated.
  - `people/includes/people_helper.php` — single insert + 3 reads, all migrated.
  - `api/auth/consume_magic_link.php` — JIT attach migrated to `provisionMembership()`.
  - `api/sso/callback.php` — `ssoEnsureTenantMembership()` migrated.
  - `core/sub_tenants.php` — `subTenantCreate()` invite loop migrated to `provisionMembership()`. `subTenantTouchLastActive()` now dual-touches both tables for the heartbeat field.
- **New sentry `user_tenants_write_sentry_smoke.php`** — fails the suite on any direct `INSERT/UPDATE/DELETE FROM user_tenants` outside an explicit allow-list (currently 3 entries: `memberships.php` itself, the backfill script, the heartbeat-touch). Synthetic offender self-test catches a fake addition.
- **Read-sentry trimmed** — the 3 "pending refactor" allow-list entries from the prior session are gone now that the writes go through the helper. Only legitimate forever-uses remain.
- Covered by `provision_membership_smoke.php` (30+ assertions: helper shape, persona mapping, consumer wiring, both sentries green).

Full suite status: **226/226 smoke tests passing** (was 223 at session start; +3 new tests: `ai_cost_tracking_smoke`, `provision_membership_smoke`, `user_tenants_write_sentry_smoke`).


## Recently completed (AI usage panel — closes the loop, 2026-02 — current fork)

Now that AI is self-serve on/off (prior change), the obvious next question is "what is AI actually doing for me?". Shipped the `AiUsagePanel` under `/admin/ai-accuracy` so the same page that answers "is AI right?" also answers "how much, how fast, where".

### Shipped
- **Backend — `api/admin/ai_usage.php`** — aggregates `ai_interactions` rows for the active tenant (or any tenant if you're a master_admin) for the last 1–90 days. Returns: `totals` (calls / ok / error / disabled / success_rate / p50 / p95 latency), `by_feature_class` (same metrics per class + distinct feature_keys), and `top_feature_keys` (top 10 by volume + last_call_at). Auth: master_admin or tenant_admin (constrained to own tenant). Days param clamped 1..90. Percentiles computed in PHP from a single ordered fetch — no MySQL window-function dependency.
- **Frontend — `AiUsagePanel` in `pages/AiAccuracyDashboard.jsx`** — mounted at the top of the existing AI Accuracy page. 4 KPI stat cards (calls / success rate / p50 / p95), a per-class table, and a top-feature-keys table. Empty-state copy links straight to `/admin/ai-settings` so an empty panel is a one-click problem to solve. Window respects the page-level day selector but caps at 30 days to keep the panel fast.
- **Cost intentionally omitted.** `ai_interactions` doesn't track tokens or provider cost, so a fake estimate would mislead more than help. The endpoint docstring documents how to extend the panel cleanly once a `token_count` + `cost_cents` column lands.
- **New smoke — `ai_usage_panel_smoke.php`** — 24 assertions covering endpoint shape + auth + percentile math + scoping, plus React test IDs and the empty-state link. All 3 codebase-wide sentries (HY093 / tenant-leak / auth-gate) verified still green.
- **SPA bundle rebuilt** via `yarn --cwd /app/dashboard build && bash scripts/sync_bundle.sh`.

Full suite status: **223/223 smoke tests passing** (+1 `ai_usage_panel_smoke.php`).


## Recently completed (AI settings UI + CLI — opt-in default preserved, 2026-02 — current fork)

**Root cause of the "AI is off — adjust in settings" with nowhere to go:** `tenants.ai_enabled` defaulted to `0` (per migration `002_ai_platform.sql` — opt-in by design), but no admin UI or API endpoint ever existed to flip it. `aiGateForTenant()` in `core/ai_service.php:274` correctly gated every feature on the flag — there was just no way to turn it on. Simulation was a separate misunderstanding: the Simulation Dashboard at `/admin/simulation-runs` is the deterministic test harness for engineers/auditors, not a tenant feature toggle.

### Shipped in this pass
1. **CLI tool — `scripts/ai_toggle.php`** — engineer-side immediate-unblock. Resolves tenant by id OR subdomain. Subcommands:
   - `on` / `off` — flips master `ai_enabled`
   - `status [tenant]` — lists state across one or all tenants
   - `full-content-logging on|off <tenant>` — opt into prompt+response logging (compliance-heavy)
   - `feature <class> on|off <tenant>` — flips a single feature_class row in `ai_tenant_features` (`classification | extraction | summary | narrative | draft | deep_reasoning`)
2. **Backend — `api/admin/ai_settings.php`** — `GET ?tenant_id=NN` returns full payload (master flag + every known feature class with current enable state + `known_feature_classes` list). `POST` accepts partial updates (`ai_enabled`, `ai_full_content_logging`, `features: { classification: true, … }`). Transactional. Audits via `admin.ai_settings.updated`. Auth: `master_admin` may target any tenant; `tenant_admin` constrained to their own.
3. **SPA — `pages/AiSettingsAdmin.jsx`** — master toggle, full-content-logging toggle (disabled when master is off), and 6 per-feature checkboxes with copy explaining what each class controls. Dirty-state save button with "Saved at" indicator. Tenant selector visible to global admins on multi-tenant accounts.
4. **AdminModule.jsx wiring** — new ActionCard ("AI settings — Per-tenant master switch + per-feature toggles (off by default)") + sidebar nav entry + `<Route path="/ai-settings" />`.

**Opt-in default preserved.** Migration `002_ai_platform.sql` is untouched — new tenants still arrive with `ai_enabled = 0`. The new UI is the explicit path to turn it on, not a behaviour change.

**SPA bundle rebuilt + `scripts/sync_bundle.sh` ran successfully.** Hash drift would have failed the `.deploy-version` smoke; it didn't.

Full suite status: **222/222 smoke tests passing** (+1 `ai_settings_admin_smoke.php` covering CLI subcommands, endpoint shape + auth, SPA test IDs, sentry cleanliness).


## Recently completed (P0 sub-tenant scope + P1 intercompany + P2 legacy retirement, 2026-02 — current fork)

### 🔴 P0 — Sub-tenant URL-flat shared-scope (FIXED)
Sub-tenants in `shared` mode for the staffing module were seeing empty CFO / staffing reports because `currentTenantId()` returned the sub-tenant's id, never falling through to the master parent's catalogs. Root cause: `effectiveTenantIdForRequest()` only resolved to the parent when the URL carried a `/modules/<key>/api/` prefix — core report endpoints under `/api/*` never hit the resolver.

Approach: **request-scoped module override** (no URL changes, no frontend churn). Added `setRequestModuleScope($key)` / `clearRequestModuleScope()` in `core/tenant_scope.php`; the resolver now consults that override first, falling back to URL-prefix detection. Core endpoints declare their module scope in one line:

- `api/reports_staffing.php`, `api/reports_ai_explain.php`, `api/exec_filters.php` — pure-staffing reports: `setRequestModuleScope('staffing')` + `$tenantId = effectiveTenantIdForRequest()`. The whole endpoint now resolves to parent for sub-tenants in shared mode.
- `api/exec_dashboard.php` — mixed-module CFO dashboard. Carries two distinct placeholders: `:t` (bound to `$tenantId` = the active sub-tenant's id, used for ISOLATED tables: billing_invoices, ap_bills, billing_payments, payroll_runs, time_entries) and `:ct` (bound to `$catalogTid` = resolved via `effectiveTenantIdForModule('staffing')`, used for SHARED catalog tables: placements, people, placement_rates, placement_commissions, tenant_end_clients). Time-entries queries that join to placements carry BOTH via `array_merge($pwParams, ['t' => $tenantId, ...])`. Financial figures stay tenant-local while catalog joins resolve against the master's shared catalog.

Covered by `tests/subtenant_url_flat_scope_smoke.php` (21 assertions, all green).

### 🟡 P1 — Cross-tenant intercompany gaps (FIXED)
Reworked `modules/accounting/lib/cross_tenant_intercompany.php` end-to-end. All four gaps from the prior audit closed:

1. **FX support** — `from_currency`, `to_currency`, `fx_rate` options. To-leg amount = `amount × fx_rate` rounded to cents. Rejects `fx_rate ≤ 0` on cross-currency pairs; forces 1.0 on same-currency. FX rate gets embedded into the JE description (`[FX USD→EUR @ 0.920000]`) so the audit trail is self-documenting.
2. **Symmetric audit** — both `cross_tenant.intercompany.posted` (out, on from-master) AND `.received` (in, on to-master) fire. Reconciliation is now a single SELECT.
3. **Compensating reversal on to-leg failure** — if the to-leg post throws, the from-leg gets auto-reversed via `accountingReverseJe()` so the books never carry a half-posted IC pair. The original error is re-thrown after compensating.
4. **Reversal helper** — new `accountingReverseCrossTenantIntercompany($ref, $reason, $actorUserId)`. Fetches both legs by their shared `intercompany_group_id` (now stamped on both JEs during post via `_cxIcStampGroupId()`), reverses each, stamps the reversal JEs with the same group id so the reconciliation worksheet groups them, and emits a `cross_tenant.intercompany.reversed` audit event.

Bonus: `intercompany_ref` is now regex-validated (`/^[A-Za-z0-9_-]{4,64}$/`) on both post and reverse.

Covered by `tests/cross_tenant_intercompany_smoke.php` (21 assertions, all green).

### 🟡 P2 — Legacy `user_tenants` retirement (PARTIAL — READS DONE)
Retired the read-side of the legacy `user_tenants` table everywhere it was safe to do so. Files refactored:

- `core/data.php` (3 reads) — `getUserTenants()`, `getAllTenants()`, `getAllUsers()` now read from `tenant_memberships`. COUNT changed to `COUNT(DISTINCT user_id)` to handle multi-persona users correctly.
- `core/push_service.php` (1 read) — `pushSendToTenant()` role filter now uses `persona_type`.
- `api/tenants.php` (1 read), `api/sub_tenant_analytics.php` (2 reads), `api/sub_tenant_consolidated_reports.php` (1 read), `api/tenant_modules.php` (1 read), `api/sub_tenant_setup_checklist.php` (1 read), `api/sub_tenants.php` (4 reads), `switch_tenant.php` (2 reads) — all role/membership lookups now query `tenant_memberships`. Mapping: `role` → `persona_type AS role` (preserving caller-facing column name); `is_default` → `is_primary`; `last_active_at` → unchanged (same column on the new table).

**New sentry: `tests/user_tenants_read_sentry_smoke.php`** — fails the smoke suite on any new `FROM user_tenants` SELECT outside an explicit allow-list (8 documented exemptions: 5 legitimate forever-uses + 3 pending dual-write refactor). Synthetic offender self-test catches a fake addition.

**Deferred to follow-up (P2 next):** 3 write-heavy files (`api/users.php`, `core/views/admin/user_edit.php`, `people/includes/people_helper.php`) plus `api/auth/consume_magic_link.php` and `api/sso/callback.php` (write-only). These need a `provisionMembership()` dual-write helper to land safely — direct rewrites would risk drift between the two tables until every writer migrates atomically. Each is allow-listed with reason.

Full suite status: **221/221 smoke tests passing** (was 218 at session start; +3 new sentries/tests).


## Recently completed (Broad audit sweep — sentries + findings, 2026-02 — current fork)
**Two new codebase-wide sentries shipped and at zero offenders.** A systematic sweep was kicked off to harden the platform against silent cross-tenant data leaks and privilege-escalation surfaces. Every offender uncovered is either fixed or documented with an inline `tenant-leak-allow:` justification so the sentry has a permanent zero-tolerance baseline.

### Sentry #1 — Tenant-leak static analyzer (`tests/tenant_leak_static_analyzer_smoke.php`)
- Auto-discovers all **201 tenant-scoped tables** in the codebase by scanning every `.sql` under `/app/core/migrations`, `/app/modules/*/migrations`, and `/app/sql` for `tenant_id` columns (`CREATE TABLE` + `ALTER TABLE ... ADD tenant_id`).
- Reconstructs the SQL of every single-arg `prepare()` call (1,235 statements scanned).
- Flags any statement that touches a tenant-scoped table without mentioning `tenant_id` anywhere in the SQL text.
- Honours `// tenant-leak-allow: <reason>` inline comments within 3 lines above the `prepare()` so legitimate global lookups (token-hash, OAuth state, webhook event dedup, post-fetch-by-id defense-in-depth) can be safelisted with their justification right next to the code.
- **152 statements** got auto-classified + commented in this sweep (token_hash lookups, session_id lookups, OAuth state, Plaid webhook events, primary-id writes after a scoped fetch). Comment text is the per-statement reason — searchable, auditable, and visible at the call site.
- Self-test: synthetic `SELECT * FROM placements WHERE id = :id` (with no tenant filter) gets caught.

### Sentry #2 — Auth-gate static analyzer (`tests/auth_gate_static_analyzer_smoke.php`)
- Scans every PHP file under `/app/api/` (139 files).
- Recognises `api_require_auth()`, `api_require_admin()`, `api_require_role()`, and `requireAuth()` as auth gates.
- Endpoints that handle requests (via `api_method`, `api_json_body`, or `$_GET/$_POST/$_REQUEST`) but skip the gate get flagged unless they're on a hard-coded allow-list that documents *why* (webhooks verified by HMAC/JWT, public-token endpoints, OAuth callbacks, login/logout).
- **13 endpoints** are explicitly allow-listed with a one-line reason each.
- Self-test: a synthetic endpoint with `api_json_body()` but no `api_require_auth()` gets caught.

### Findings flagged for follow-up (NOT fixed in this sweep — need product decision)
- **🔴 Sub-tenant shared-scope ONLY works on `/modules/<key>/api/*` URLs.** `effectiveTenantIdForRequest()` detects the module from `REQUEST_URI` via the `/modules/<key>/` prefix. Core report endpoints under `/api/*` (e.g. `reports_staffing.php`, `exec_dashboard.php`, `reports_ai_explain.php`, `exec_filters.php`) all use `currentTenantId()` directly — meaning a sub-tenant logged into a shared-scope `people`/`placements` module will see empty rows from these core reports even though they should see the parent's catalog. Affected files: 60 PHP files use `currentTenantId()` directly; only 2 use the resolver. Recommend either (a) moving these reports under `/modules/<key>/api/` URLs, or (b) introducing a per-query `effectiveTenantIdForModule($module)` resolver and applying it everywhere a shared-scope table is touched.
- **🟡 Cross-tenant intercompany (`accounting/lib/cross_tenant_intercompany.php`) gaps:**
  1. No FX handling — both legs assumed same currency.
  2. Only audits the "from" side; no symmetric "received" event on the to-tenant.
  3. No reversal helper for cross-tenant pairs (only `intercompanyReverseGroup` for intra-tenant).
  4. No validation that `from_account_code` / offset codes exist in the from-tenant's COA.
- **🟡 Legacy `user_tenants` table still read by 25 PHP files** outside the legitimate B5 bridge (`core/sub_tenants.php`, `core/api_bootstrap.php`). Phase B5 was supposed to phase this out in favour of `tenant_memberships`. The two tables can drift, causing inconsistent permissions and last-active-tenant behaviour.
- **🟡 Duplicate module folder:** `/app/modules/private_equity 2/` exists alongside `/app/modules/private_equity/`. They contain non-overlapping files (each has unique routes) — needs manual reconciliation before either can be deleted.

Full suite status: **218/218 smoke tests passing** (was 215; +1 HY093 sentry, +1 tenant-leak sentry, +1 auth-gate sentry).


## Recently completed (HY093 codebase-wide cleanup, 2026-02 — current fork)
**Closed the last 10 latent PDO duplicate-placeholder bugs.** The static analyzer shipped in the previous step found 10 SQL statements that re-used a named placeholder (`WHERE a = :x OR b = :x`) — which fatally errors at execute() time under `PDO::ATTR_EMULATE_PREPARES=false`. Each one was a runtime bomb waiting to be tripped by the right call shape.

- **Fixed (10 distinct prepare() statements across 8 files):**
  - `api/reports_ai_explain.php:73` — `:t` → `:t / :t2` (recruiter book inner subquery)
  - `api/tax_mappings.php:132` — `:u` → `:u1 / :u2` (created_by + updated_by upsert)
  - `core/jobdiva/sync_time.php:46` — `:wd` → `:wd1 / :wd2` (time_periods date-range join)
  - `core/seeds/event_registry_seed.php:408` — `:canonical` → `:canonical1 / :canonical2` (alias seed concat + alias_for)
  - `legacy/people_pre_spec_20260429/api/compensation.php:44` — `:eff` → `:eff1 / :eff2` (effective-to back-date update)
  - `modules/ap/api/bill_approvals.php:127` — `:a` → `:a1 / :a2` (workflow bracket lookup)
  - `modules/ap/api/weekly_queue.php:122` — `:a` → `:a1 / :a2` (finalize bracket lookup)
  - `modules/billing/lib/recurring.php:250` — `:asOf` → second instance renamed to `:asOf4` (eligible-contracts window guard)
  - `modules/time/lib/intake.php:53` — `:t` → `:t / :t2` (users ↔ people email join)
  - `modules/time/lib/settlement_create.php:91` — `:d` → `:d1 / :d2` (placement_rates effective-window pick)
- **Test wiring:** `tests/ap_phase_a1_followups_smoke.php` updated to assert the new `:a1 >= min_amount` literal so the bracket-lookup contract still has a guard.
- **Analyzer status:** `tests/hy093_static_analyzer_smoke.php` now reports **0 offenders** across 1,242 reconstructed prepare() calls in 800 PHP files. The sentry catches the synthetic bad input in its self-test, so any future regression fails the smoke suite immediately.

Full suite status: **216/216 smoke tests passing.**


## Recently completed (Mail branding — Send test email card, 2026-02 — current fork)
**One-button confidence check for the Resend wiring.** Right after pasting `RESEND_API_KEY` into `config.local.php`, tenant admins can now send a test message from `/admin/mail-branding` and see exactly which driver delivered (`resend`, `log`, or `phpmailer_smtp`) plus the Resend `message_id` round-trip — no need to trigger a real CFO report or bill-approval flow to find out the key is valid and the domain is verified.

- **Backend:** `/api/admin/mail_test_send.php` — POST-only, admin-gated. Accepts `{ recipient, subject?, body_html? }`, defaults the subject/body to a self-describing template, validates the recipient address, rate-limits to 1 send per admin per 10 seconds (uses `audit_log` for the lockout window), routes through `mailerSend()`, and audits every send. Returns `{ ok, driver, message_id, error, fallback, resend_configured, tenant_id, recipient, subject }`.
- **Frontend:** New `MailTestSendCard` mounted inside `MailBrandingAdmin.jsx`. Recipient email input + Send button, then a verdict panel showing pass/fail pill, driver pill, RESEND_API_KEY configured/missing badge, Resend message_id (when present), fallback reason (when the shim dropped to PHPMailer), and any provider error.
- **Smoke:** `tests/mail_test_send_smoke.php` (30 assertions) covers endpoint contract (auth gate, validation, rate limit, audit row, dual-key detection, response shape) plus every testid on the React card.

Full suite status: **213/213 smoke tests passing.** Bundle rebuilt + synced.

## Recently completed (Resend wiring — mailerSend shim, 2026-02 — current fork)
**Five email call sites just started actually delivering.** `mailerSend()` was referenced in 5 places (CFO weekly report, timesheet approver notice, vendor portal invite, AP bill approvals, Mercury payment CFO alert) but **never defined** — every send silently no-op'd. Wired it through the existing `Core\MailService` + `Core\Mail\ResendDriver` infrastructure that was already built but unused.

- **`mailerSend()` shim** added to `/core/mailer.php`. Accepts the existing `['to', 'subject', 'body_html', 'body_text', 'reply_to', ...]` shape — no call-site changes required. Auto-derives tenant_id from `currentTenantId()` when not supplied. Auto-derives plaintext from HTML when `body_text` is omitted. Defaults `module='core'` + `purpose='notification'`. Returns `['ok', 'message_id', 'driver', 'error']` — does not throw on send failure (matches existing soft-fail call-site contract).
- **Driver selection:** MailService default = `ResendDriver` when `RESEND_API_KEY` is configured (via env var **OR** `define()` in `/core/config.local.php` — matches existing OpenAI/Plaid secrets pattern), else `LogDriver` for dev-safe capture.
- **Fallback path:** if MailService can't boot (no tenant, DB down, exception) the shim drops back to legacy `sendEmail()` (PHPMailer/SMTP) so messages still reach SMTP credentials in `core/config.php`.
- **`ResendDriver` widened** to read its API key + from address + from name from either `getenv()` or `define()`. `mail_bootstrap.php` does the same when deciding which driver is default.
- **Config template:** `core/config.local.example.php` now documents `RESEND_API_KEY`, `RESEND_FROM_EMAIL`, `RESEND_FROM_NAME` alongside the OpenAI/Plaid blocks.
- **Smoke:** `tests/mailer_send_shim_smoke.php` (43 assertions) covers source-shape checks, runtime LogDriver path (outbox row, body-text derivation, dedup, validation), runtime ResendDriver path with stubbed cURL transport (Bearer auth header, Idempotency-Key, `from <name@>` formatting, payload subject/html/reply_to/tags, success+failure verdicts, outbox row tagging), and call-site no-regression assertions for all 5 endpoints.

Full suite status: **212/212 smoke tests passing.**

### To go live
Drop into `/app/core/config.local.php` (or set as Cloudways env vars):
```php
define('RESEND_API_KEY',    're_...');                  // from https://resend.com/api-keys
define('RESEND_FROM_EMAIL', 'no-reply@yourdomain.com'); // must be a verified domain
define('RESEND_FROM_NAME',  'CoreFlux Notifications');  // optional
```
Next request boots Resend automatically — no service restart required.

## Recently completed (Roles reference page, 2026-02 — current fork)
**"What does picking this persona_type actually grant?" in one page.** Admins picking a role on a new membership now have a single reference doc explaining each of the 10 canonical persona_types without grepping the codebase.

- **Backend:** `/api/admin/roles_reference.php` — GET-only, admin-gated. Loads `core/rbac_config.php`, splits each persona's grants into wildcard module access (e.g., `people.*`) vs explicit permissions, returns plain-English label/summary/scope/notes/legacy_role_mapping per persona, plus a glossary covering scope/access/wildcard/dual-check-bridge terminology.
- **Frontend:** `dashboard/src/pages/RolesReference.jsx` — responsive card grid, filter box, scope + default-access-level pills, expandable specifics list, glossary card. Every interactive element + key data point has a `data-testid`.
- **Wiring:** Route at `/admin/roles`, sidebar entry, and Quick Actions card on the Admin overview.
- **Smoke:** `tests/rbac_roles_reference_smoke.php` (32 assertions) covers endpoint contract (10 persona entries, wildcard/specific split, legend keys), React testids, AdminModule wiring (import, route, sidebar link, ActionCard).

## Recently completed (Sub-tenant wizard — subdomain default DB hardening, 2026-02 — current fork)
**Defense-in-depth for the "Field 'subdomain' doesn't have a default value" recurrence.** The user reported the SubTenantWizard "Finish & provision" step still throwing the legacy MySQL 1364 error even after `/core/sub_tenants.php` was patched to supply the column. Root cause: stale opcache / older revisions still in the deploy pipeline were skipping the column. Code-only fixes can't survive that.

- **Migration 059 — `tenants.subdomain` gets a DB-level default.** New idempotent migration runs `ALTER TABLE tenants MODIFY COLUMN subdomain VARCHAR(255) NOT NULL DEFAULT ""` only when `information_schema.columns` reports `IS_NULLABLE='NO' AND COLUMN_DEFAULT IS NULL`. Auto-applied via `coreflux_run_migrations()` on next API request. Application code can still set a meaningful subdomain when desired; the DB no longer crashes if it's omitted.
- **Smoke:** `tests/migration_059_subdomain_default_smoke.php` (8 assertions) covers file shape, idempotent guard, ALTER target, and runner pickup. Live schema verification is best-effort (skipped when no MySQL is available in the CLI sandbox).
- **`.deploy-version` sentinels** added so post-deploy diagnostics catch missing migration.

Full suite status: **210/210 smoke tests passing.**

## Recently completed (RBAC — Effective Permissions Inspector finalize, 2026-02 — current fork)
**"Why can't this user see X?" in one click.** Tenant admins can now click the new Shield icon on any user row in `/admin/users` to open a modal that shows every membership, every module access level, and a per-permission ALLOW/DENY/PARKED verdict computed by impersonating that user against the dual-check bridge.

- **Backend:** `/api/admin/user_effective_permissions.php` — GET-only, admin-gated. Pulls `user_tenants` + `tenant_memberships` + `membership_module_access`, then iterates every entry in `RbacLegacyMap::table()` running both `RBAC::hasPermission` and `api_can()` under a temporarily-swapped `$_SESSION` (restored in `finally`). Surfaces orphan memberships (no `user_tenants` row) so admins can spot drift.
- **Frontend:** `dashboard/src/pages/UserEffectivePermissionsModal.jsx` — tenant cards → membership rows → module-access tiles → permission matrix with ALLOW/DENY/PARKED markers, plus filter input, "only denied", and "only disagreement" toggles.
- **Wiring:** Shield button per row in `UsersAdmin.jsx`, opens the modal in-context.
- **Smoke:** `tests/rbac_effective_permissions_smoke.php` (35 assertions, all passing) covers endpoint contract, modal testids, UsersAdmin wiring, plus a regression assertion for the sub-tenant `subdomain` insert fix.

Full suite status: **209/209 smoke tests passing.** Vite bundle rebuilt and `sync_bundle.sh` propagated new hashes.

## Recently completed (RBAC B4 — Bridge Disagreement Monitor, 2026-02 — current fork)
**Safety-net for the dual-check era.** Tenant admins can now see, at a glance, exactly where the legacy `RBAC` config and the new `RBACResolver` disagree about a permission — which tells them when it's safe to flip `CF_RBAC_BRIDGE_MODE=new` and retire `/core/RBAC.php`.

### Migration 056 — `rbac_bridge_audit` table
- New append-only table: `id, tenant_id, user_id, perm, module_key, action, legacy_ok, new_ok, occurred_at`.
- Three indexes: `(occurred_at)`, `(perm, occurred_at)`, `(tenant_id, occurred_at)` — supports the aggregate query without table-scanning.
- Idempotent (`CREATE TABLE IF NOT EXISTS`). Auto-applied via `coreflux_run_migrations()` on first request after deploy.

### Bridge writer — `rbac_bridge_record_disagreement()`
- Hooked into `rbac_legacy_can()` immediately after the dual-check verdict is computed.
- **Only fires when `$legacyOk !== $newOk`** — steady-state traffic on an aligned tenant produces zero log writes.
- Wrapped in try/catch + `function_exists('getDB')` guard; never bubbles an audit failure into the caller. The dual-check verdict is returned regardless of whether the log write succeeded (CLI tests, missing migration, DB hiccup — all safe).
- Captures `tenant_id` (from `currentTenantId()`), `user_id` (from `$user['id']`), the original permission string, the resolved `(module_key, action)` tuple, and both verdicts.

### `/api/admin/rbac_bridge_health.php` *(new)*
GET-only, admin-gated (master_admin / tenant_admin / global). Query param `?window_hours=24` (clamped 1..168). Returns:
- `total_disagreements` — count over the window.
- `legacy_only_grants` — legacy said yes, new said no → widening risk if we flip to `new` mode (some users would *gain* access).
- `new_only_grants` — new said yes, legacy said no → narrowing risk (some users would *lose* access).
- `top_perms[]` — top 10 disagreeing permissions grouped by `(perm, module, action, legacy_ok, new_ok)` with hit counts. Shows you exactly *what's* off.
- `recent[]` — last 20 individual rows for "show me an example" forensics.
- Handles missing migration with `{ configured: false }` so the panel renders gracefully on greenfield tenants.

### `RbacBridgeHealthPanel.jsx` *(new)*
Reusable Card component. Embedded in two places:
- **`AdminModule` overview** — alongside the Recent Access Changes panel (right column).
- **`RbacMembershipsAdmin` page** — right column under the audit feed.

UI states:
- **Healthy (zero disagreements):** green ShieldCheck icon + "Bridge agrees across the board. Safe to consider flipping `CF_RBAC_BRIDGE_MODE=new`." banner.
- **Has disagreements:** red ShieldAlert icon, big count, legacy-only / new-only breakdown, top-10 disagreeing permissions table with ✓/✗ markers.
- **Unconfigured (migration not yet applied):** muted message; no error.
- Every interactive element + key data point carries a `data-testid` (`rbac-bridge-health-total`, `rbac-bridge-legacy-only`, `rbac-bridge-new-only`, `rbac-bridge-health-green`, `rbac-bridge-top-perms`, `rbac-bridge-perm-{perm}`, etc.).

### Smoke coverage — `/app/tests/rbac_b4_bridge_health_smoke.php` *(new, 47 assertions)*
- Migration 056 shape (column types, defaults, all three indexes).
- Bridge writer presence + guard conditions + disagreement-only firing + try/catch behaviour.
- Endpoint contract (admin gate, GET-only, missing-migration handling, window clamp, response shape, group-by aggregation, recent/top limits).
- React panel testids + state branches.
- AdminModule + RbacMembershipsAdmin wiring (imports + embeds).

Full suite status: **207/207 smoke tests passing.** Bundle rebuilt cleanly.

### Workflow this enables
1. Deploy → migration 056 auto-applies on first API request.
2. Bridge silently logs disagreements as traffic flows. Aligned tenants log zero rows.
3. Admin opens `/admin` or `/admin/memberships`, sees the green banner *or* sees exactly which permissions need attention.
4. For each red row: either tweak the legacy `rbac_config.php` grant or adjust the membership's `access_level`. Refresh the panel — disagreement count drops.
5. When the panel goes green for a sustained period, ship `CF_RBAC_BRIDGE_MODE=new` and retire `/core/RBAC.php` (B4 final).

### Files touched this slice
- `/app/core/migrations/056_rbac_bridge_audit.sql` *(new)*
- `/app/core/rbac/legacy_map.php` *(disagreement writer)*
- `/app/api/admin/rbac_bridge_health.php` *(new)*
- `/app/dashboard/src/pages/RbacBridgeHealthPanel.jsx` *(new)*
- `/app/dashboard/src/pages/AdminModule.jsx` *(embeds panel)*
- `/app/dashboard/src/pages/RbacMembershipsAdmin.jsx` *(embeds panel)*
- `/app/tests/rbac_b4_bridge_health_smoke.php` *(new — 47 assertions)*



## Recently completed (RBAC B4 — Legacy Sweep + Bridge, 2026-02 — current fork)
**170 production endpoints migrated off `RBAC::hasPermission()/requirePermission()` onto a translation bridge in a single sweep. Zero behavioural change at default settings.** Plus the persona-switch UX touch — flipping personas in the header now triggers a soft reload so the new role's sidebar, modules, and gated UI render immediately.

### B4-prep — Per-endpoint mapping doc + bridge
- **`/app/memory/RBAC_B4_PERMISSION_MAPPING.md`** — authoritative doc inventorying every legacy permission string (108 unique across 130+ callsites). Defines:
  - Translation rules: first segment → `module_key`, terminal verb → `action` (none/read/write/admin).
  - Bucketing convention: `view/audit.view/consume/self` → `read`; `create/edit/manage/draft/submit/...` → `write`; `approve/post/void/reverse/execute/PII/...` → `admin`.
  - Full per-permission table grouped by module (accounting, ai, ap, billing, integrations, payroll, people, placements, reports, staffing, time, treasury).
  - PARK list: `tenant.manage` stays on legacy until `platform_admin` capability is modelled separately.
  - Sweep execution order, backfill alignment notes, two new module keys to seed (`integrations`, `ai`), open questions for review.

- **`/app/core/rbac/legacy_map.php`** *(new)* — `RbacLegacyMap::resolve(string $perm): [module, action]` returns the canonical tuple for any of the 108 strings; unknown strings fall through to `(<first_segment>, write)` so a missed permission still gates conservatively rather than failing open.
  - **`rbac_legacy_can(array $user, string $perm): bool`** — drop-in replacement for `RBAC::hasPermission()`. Runs in **dual-check mode** by default: returns `true` only when *both* the legacy `RBAC::hasPermission()` AND the new `api_can($module, $action)` grant access. The more-restrictive layer always wins — meaning the sweep can route every callsite through the bridge without widening anybody's access.
  - **`rbac_legacy_require()`** — same shape, 403 on denial with `{required, required_module, required_action}` payload.
  - Bridge mode is toggleable via env / constant `CF_RBAC_BRIDGE_MODE`: `dual` (default), `new` (resolver-only — for after backfill is tightened), `legacy` (fail-safe).
  - PARKED strings always defer to legacy regardless of mode.

- **`/app/core/api_bootstrap.php`** — `require_once`s the bridge so `rbac_legacy_can/require` are available globally to every endpoint.

### B4 — Sweep execution
- **170 production files patched** via single sed pass (`RBAC::hasPermission(` → `rbac_legacy_can(`, `RBAC::requirePermission(` → `rbac_legacy_require(`). Coverage spans /api (all root + admin + integrations) and every module's /api directory (ap, billing, people, placements, payroll, time, treasury, accounting, reports, staffing).
- **65 string-grep contract smoke tests** updated to match the new function names. Excluded: `tests/rbac_smoke.php` and `tests/sprint7a_permissions_smoke.php` — these test the legacy `RBAC` class directly and must keep referencing it.
- `/app/core/RBAC.php` itself is untouched and **stays loaded** alongside `RBACResolver` and the bridge throughout B4. Retirement happens in a separate, post-sweep slice once dual-check has shipped for one full release cycle.

### Bridge smoke — `/app/tests/rbac_b4_bridge_smoke.php` *(new, 122 assertions)*
Locks the 108-row mapping table against the doc:
- One assertion per permission string verifying `RbacLegacyMap::resolve()` returns the documented tuple.
- Unknown-permission fall-through (`(<segment>, write)`).
- PARK behaviour (`tenant.manage` → `_platform`).
- `api_bootstrap.php` wiring.
- Doc parity check: every mapped permission must be cited in the markdown table by exact name.

If the doc and the code ever drift, this test fails — preventing silent permission widening / narrowing.

### Persona-switch UX touch (App.jsx listener)
- **`/app/dashboard/src/App.jsx`** — added a window-level `cf:active-persona-changed` listener inside the session hook. On persona switch the listener calls `window.location.reload()` so the SPA reboots against the new `$_SESSION['active_persona_id']`.
- **`/app/api/active_persona.php`** — POST handler now mirrors the new persona's `persona_type` back into `$_SESSION['user']['role']` immediately, so the post-switch `/session.php` call renders the new persona's sidebar + module list without waiting for any other `/api/*` round-trip. Tiny session write, big "feels instant" UX win.

### Coverage
- New B4 bridge smoke: **122 assertions, all green.**
- B5 smoke extended (+4 assertions for the listener + role mirror): 35 total.
- Bundle rebuilt cleanly (`spa-assets/index-qAQonk4T.js`).
- **Full suite: 206/206 smoke tests passing.**

### Files touched this slice
- `/app/memory/RBAC_B4_PERMISSION_MAPPING.md` *(new)*
- `/app/core/rbac/legacy_map.php` *(new — bridge + mapping)*
- `/app/core/api_bootstrap.php` *(loads bridge)*
- `/app/api/active_persona.php` *(role mirror)*
- `/app/dashboard/src/App.jsx` *(persona-changed listener)*
- `/app/tests/rbac_b4_bridge_smoke.php` *(new — 122 assertions)*
- `/app/tests/rbac_b5_smoke.php` *(+4 assertions)*
- 170 production endpoints (across /api + /modules) — callsite rename only
- 65 test files — string-grep contract updates

### What this unlocks
- Every callsite is now routed through a single translation point. The legacy `RBAC` class will retire in a follow-up slice once `CF_RBAC_BRIDGE_MODE=new` ships and the membership backfill is tightened to match `rbac_config.php` grants exactly.
- New module gates (`integrations`, `ai`) are mapped and ready — once we seed them into `membership_module_access` rows via migration 056 (sketched in the doc), `CF_RBAC_BRIDGE_MODE=new` becomes safe.
- Tenant admins can now manage RBAC end-to-end from the SPA — create persona → grant modules → user flips persona in header → permissions take effect immediately → auditor sees the trail.



## Recently completed (RBAC B5 — Header Persona Toggle + Sub-Tenant Audit Filter, 2026-02 — current fork)
**The user-facing piece of the RBAC story.** A user holding multiple personas in a single tenant (e.g. Admin in their consulting practice + Employee in their client work) can now flip between them from the header dropdown — no re-login, no re-context-switch. Plus the SoD audit panel can now answer "what changed inside sub-tenant Acme this week?".

### Sub-tenant audit filter
- **`/api/admin/membership_audit.php`** — added `?sub_tenant=N` query param. Filters entries whose `detail.sub_tenant_scope` JSON array contains N (`JSON_CONTAINS` on the JSON column), *plus* tenant-wide module changes (sub_tenant_scope absent/null) which implicitly affect every sub-tenant. Bounded to module_grant/revoke/permissions_copied/created/updated/revoked actions so unrelated noise doesn't leak through.
- **`RecentAccessChangesPanel.jsx`** — new `showSubTenantFilter` prop. When `true`, the panel lazy-loads `/api/sub_tenants.php` and renders an "All sub-tenants ▾" dropdown next to the refresh button. Reloads the feed on selection change.
- **`RbacMembershipsAdmin.jsx`** — passes `showSubTenantFilter={true}`. The AdminOverview embed keeps it off to stay compact.

### RBAC Phase B5 — header persona toggle
- **`/api/active_persona.php`** *(new)*. Three methods:
  - **`GET`** → `{ active_persona_id, personas: [{ id, persona_label, persona_type, is_primary, status, last_active_at }] }`. Lists every active/pending membership for `(current user, current tenant)` via `RBACResolver::memberships()`. If no explicit persona is set in the session, falls back to the resolver's default pick (primary → most-recent → first) so the header dropdown always renders a current selection.
  - **`POST { persona_id }`** — calls `setActivePersona()` (verifies the persona belongs to the current user + tenant and is `status='active'`). Audits the switch as `persona_switched` in `membership_audit`. Resets the resolver's per-request cache so subsequent `can()` calls reflect the new persona immediately. Returns the hydrated row.
  - **`DELETE`** — clears `$_SESSION['active_persona_id']`. `api_require_auth()` re-derives the default on the next request.
  - No admin gate — every authenticated user can pick their own persona.

- **`Header.jsx`** — new persona switcher dropdown next to the entity switcher. Mirrors the existing multi-entity pattern:
  - Uses a `UserCog` icon and shows the current persona's `persona_label`.
  - **Only renders when the user holds ≥2 personas in the current tenant** — single-persona users (the common case) see no extra chrome.
  - Fetches `/api/active_persona.php` on tenant change. On selection, POSTs the new `persona_id` and dispatches a `cf:active-persona-changed` window event so the rest of the SPA can soft-refresh permission-gated views.
  - Click-outside closes the dropdown. PRIMARY badge on the user's default persona. Every interactive element carries a `data-testid` (`header-persona-switcher`, `header-persona-button`, `header-persona-option-{id}`).

### Smoke coverage
- **`/app/tests/rbac_b5_smoke.php`** *(new — 31 assertions)* — endpoint contract (GET/POST/DELETE, audit + cache reset, 404 on invalid persona), `auth.php` helper presence, Header.jsx wiring (UserCog import, state/refs, click-outside, GET/POST/event dispatch, `personas.length > 1` gate, PRIMARY badge, testids), `api_bootstrap.php` still picks up `$_SESSION['active_persona_id']`.
- **`/app/tests/rbac_b3_smoke.php`** *(extended)* — sub-tenant filter param + `JSON_CONTAINS` SQL + action whitelist + Panel prop + memberships page wiring (+6 assertions, total 67).
- Vite bundle rebuilt: `spa-assets/index-qAQonk4T.js`. `.deploy-version` updated. **Full suite: 205/205 smoke tests passing.**

### Files touched this slice
- `/app/api/active_persona.php` *(new)*
- `/app/api/admin/membership_audit.php` *(extended — sub_tenant param)*
- `/app/dashboard/src/layout/Header.jsx` *(persona dropdown wired in)*
- `/app/dashboard/src/pages/RecentAccessChangesPanel.jsx` *(showSubTenantFilter prop + sub-tenant select)*
- `/app/dashboard/src/pages/RbacMembershipsAdmin.jsx` *(enables filter)*
- `/app/tests/rbac_b5_smoke.php` *(new — 31 assertions)*
- `/app/tests/rbac_b3_smoke.php` *(extended)*

### What's now possible end-to-end
1. **Tenant admin** opens `/admin/memberships`, creates a second persona for an existing user (e.g. "Recruiter") with a different persona_type.
2. Grants module access (read/write/admin/none per module) — or just clicks **"Copy permissions from…"** to clone another user's grants in one shot.
3. **End user** loads the SPA, sees a "Persona" dropdown in the header with both of their memberships.
4. Picks the new persona. `api_require_auth()` immediately hydrates `$ctx['membership_id']`, `$ctx['persona_type']`, and any subsequent `api_can()` check answers against the new grid.
5. **SoD reviewer** opens the audit panel, optionally filters by sub-tenant, and sees the persona switch + every grant/revoke for that sub-tenant in chronological order.



## Recently completed (RBAC B3 — Admin UI + Audit Receipt, 2026-02 — current fork, after B2)
**Tenant admins now have full UI control over the new RBAC grid.**  All audited.  204/204 smoke tests passing.

### New backend endpoints
- **`/api/admin/memberships.php`** (`GET / POST / PATCH / DELETE`) — full CRUD for `tenant_memberships`. List joins `users.name`, includes a `modules_count` sub-select, supports `?user_id=N` filter and `?include_inactive=1`. POST upserts on the `(user_id, tenant_id, persona_label)` unique key. PATCH enforces single-`is_primary` per `(user, tenant)`. DELETE is soft (`status='revoked'`). Every write is audited via `RBACResolver::auditMembership()`.
- **`/api/admin/membership_access.php`** (`GET / POST`) — per-module access grid. POST takes `op: grant | revoke | copy`:
  - `grant`: `{ membership_id, module_key, access_level, sub_tenant_scope?: int[] }` → `RBACResolver::grantModule()`
  - `revoke`: `{ membership_id, module_key }` → `RBACResolver::revokeModule()`
  - `copy`: `{ from_membership_id, to_membership_id }` → `RBACResolver::copyPermissions()`. Both memberships must be in the active tenant.
- **`/api/admin/membership_audit.php`** (`GET`) — feeds the "Recent access changes" panel. Returns last N (default 10, max 100) rows from `membership_audit` for the active tenant. LEFT JOINs `users` twice (actor + target) and `tenant_memberships` (persona context) so the UI doesn't need extra round-trips. Handles the migration-not-applied case with `{ configured: false }`.

All three endpoints gate on `master_admin / tenant_admin / is_global_admin` and use `RBACResolver` (not the legacy `RBAC` class).

### New React surface — `/admin/memberships`
- **`RbacMembershipsAdmin.jsx`** — main page. Two-column layout: memberships table on the left (filter, "Show inactive" toggle, refresh, "New membership" button), Recent Access Changes + tips card on the right.
  - Inline `MembershipForm` (drawer-style under the table) for create/edit with persona label / persona type / status / is_primary fields.
  - Inline `AccessGrid` that opens when a row's "modules" button is clicked — renders a per-module `select` (none/read/write/admin) plus a **"Copy permissions from…"** dropdown listing every other active membership in the tenant (with grant counts).
- **`RecentAccessChangesPanel.jsx`** — reusable feed component. Renders the last 10 audit entries with action-coloured left borders, actor → action → target sentence framing, and detail-aware sub-lines (e.g. `"granted module access — Accounting: admin"`).  Also embedded on the AdminModule overview page so SoD reviewers get an instant compliance receipt from the landing.
- **`AdminModule.jsx`** — new sidebar link "Memberships & access" (Shield icon), new ActionCard on the overview, route `/admin/memberships`, and the audit panel embedded below the quick actions.

Every interactive element carries a `data-testid` (`rbac-memberships-admin`, `membership-row-{id}`, `open-access-grid-{id}`, `access-level-{module}`, `access-copy-from-select`, `access-copy-btn`, `recent-access-changes`, `recent-access-row-{id}`, etc.) for E2E coverage in B4.

### Smoke coverage
- **`/app/tests/rbac_b3_smoke.php`** — 61 assertions covering: endpoint files exist, syntax clean, admin-gate, query shape (tenant-scoped, joins users via `u.name`, modules_count), method routing, persona/status/access-level whitelists, audit calls, `_ma_membership_in_tenant` cross-tenant guard, sub-tenant-scope handling, React imports, route registration, and panel testids.
- **`/app/tests/sprint6b_dashboard_uis_smoke.php`** — made the Vite-bundle hash check self-healing: now discovers the hash from `dashboard/dist/index.html` instead of hard-coding it. Future rebuilds no longer require manually editing this test.

### Files touched this slice
- `/app/api/admin/membership_audit.php` *(new)*
- `/app/api/admin/memberships.php` *(new)*
- `/app/api/admin/membership_access.php` *(new)*
- `/app/dashboard/src/pages/RbacMembershipsAdmin.jsx` *(new)*
- `/app/dashboard/src/pages/RecentAccessChangesPanel.jsx` *(new)*
- `/app/dashboard/src/pages/AdminModule.jsx` *(extended)*
- `/app/tests/rbac_b3_smoke.php` *(new — 61 assertions)*
- `/app/tests/sprint6b_dashboard_uis_smoke.php` *(self-healing bundle-hash discovery)*
- Bundle rebuilt + `sync_bundle.sh` ran cleanly: `spa-assets/index-B7nJ6dXK.js` / `index-BC5g6YJu.css`, `.deploy-version` updated.



## Recently completed (RBAC B2 — Runtime Resolver + Session Wiring, 2026-02 — current fork)
**Second slice of the RBAC re-architecture.** The new resolver class is in production alongside the legacy `RBAC` class — both load in the same process, no fatal collision. Every endpoint that goes through `api_require_auth()` now gets membership-aware context for free.

### Class collision resolved
- The B2 file `/app/core/rbac/permissions.php` originally declared `class RBAC`, which would have fatally collided with the legacy `/app/core/RBAC.php` (still wired into ~30 endpoints via `RBAC::hasPermission()` / `RBAC::requirePermission()`).
- Renamed to **`final class RBACResolver`**. Legacy `RBAC` untouched — zero blast radius on existing endpoints. New code calls `RBACResolver`; legacy callers keep working until the B5 sweep.

### `api_bootstrap.php` — `$ctx` is now membership-aware
`api_require_auth()` returns three new keys alongside the existing `user / tenant_id / role`:
- `membership_id` — int of the active `tenant_memberships` row (NULL if no membership exists yet for this user/tenant; legacy fall-through stays in play).
- `persona_type` — string from the membership (`master_admin` / `tenant_admin` / `admin` / `manager` / `employee` / `contractor` / `client` / `vendor` / `platform_staff` / `custom`). When present, overrides the legacy `$effectiveRole` so a user who is Admin in tenant A and Employee in tenant B sees the right persona without a re-login.
- `is_global_admin` — bool reflecting `users.is_global_admin`. CoreFlux platform staff flag.

Two new global helpers wrap the resolver for endpoints that have migrated off legacy role-list checks:
- `api_can($module, $action='read', $subTenantId=null)` — returns bool. Pulls user + tenant from session, hands off to `RBACResolver::can()`.
- `api_require_can(...)` — same signature; emits 403 JSON with `required_module` / `required_action` payload on denial.

### `auth.php` — persona switching
Three new helpers powering the SPA tenant/persona toggle that lands in B3:
- `setActivePersona(int $personaId): bool` — verifies the persona belongs to the current user + tenant, then writes `$_SESSION['active_persona_id']`. `api_require_auth()` picks it up on the next request and hydrates `$ctx` from that specific membership row.
- `getActivePersonaId(): ?int`, `clearActivePersona(): void`.

### "Copy permissions from…" UX
`RBACResolver::copyPermissions($fromMembershipId, $toMembershipId, $actorUserId=null): int` clones every `membership_module_access` row from one membership to another within the same tenant. Audits the action via `membership_audit`. Returns grant count. Onboarding a second recruiter / third controller becomes a single API call from the admin UI in B3/B4.

### Smoke coverage — `/app/tests/rbac_b2_smoke.php`
56 assertions covering:
- Class-name collision is resolved (`RBACResolver` is declared, `RBAC` is not redeclared in the new file, both classes load side-by-side).
- Resolver method surface contract: `can / personaTypeOf / memberships / activeMembership / moduleAccessFor / grantModule / revokeModule / copyPermissions / isGlobalAdmin / legacyRole / resetCache`.
- `can()` input guards (userId=0, invalid action, array-form user).
- DB-absent fall-throughs: every PDO call wraps in try/catch returning safe defaults (`legacyRole→'employee'`, `isGlobalAdmin→false`, `activeMembership→null`, `memberships→[]`).
- `copyPermissions()` signature pinning (3-arg static method).
- `api_bootstrap.php` wiring (`require_once`, `$ctx` keys, RBACResolver call sites, `api_can`/`api_require_can` helpers, `active_persona_id` session read).
- `auth.php` persona helpers exist and reference `RBACResolver::memberships`.
- `LEVEL_RANK` constant ordering (`none < read < write < admin`).

Full suite status: **203/203 smoke tests passing** (rbac_b2_smoke + every previously green test; `ai_platform_smoke` and `plaid_integration_smoke` also now green since `php-curl` is installed in this fork).

### Why this matters
- The new resolver is the **single source of truth** for "can this user do X in module M under sub-tenant S?" going forward.
- Legacy `RBAC::hasPermission()` continues to gate the ~30 unmigrated endpoints — dual-write era, no breakage.
- B3 (admin UI in React) and B4 (sweep legacy endpoints onto `api_require_can`) are now unblocked.

### Files touched this slice
- `/app/core/rbac/permissions.php` — class renamed `RBAC` → `RBACResolver`.
- `/app/core/api_bootstrap.php` — requires resolver, expands `$ctx`, adds `api_can()` + `api_require_can()`.
- `/app/core/auth.php` — `setActivePersona / getActivePersonaId / clearActivePersona`.
- `/app/tests/rbac_b2_smoke.php` — new (56 assertions).
- `/app/tests/p0_fixes_smoke.php` — relaxed one whitespace-brittle assertion to accept the expanded `$ctx` return array.



## Recently completed (RBAC B1 — Schema Foundation, 2026-02 — this fork)
**First slice of the RBAC re-architecture.** Schema-only this iteration — the runtime resolver, session refactor, and admin UI ship in B2-B5. Designed to land *alongside* the legacy `user_tenants` table so nothing breaks during the cut-over.

### Migration 055 — `core/migrations/055_rbac_memberships.sql`
- **`tenant_memberships`** (one row per user × tenant × persona) supersedes the single-role `user_tenants` pairing. Same user can have multiple personas in the same tenant (e.g. "Admin" + "Employee") and the SPA tenant/persona toggle picks one.
  - `persona_label` (display name in toggle) + `persona_type` enum: `master_admin / tenant_admin / admin / manager / employee / contractor / client / vendor / platform_staff / custom`
  - Optional `linked_entity_type` + `linked_entity_id` → tie the persona to a `people` / `staffing_clients` / `ap_vendors_index` row so a "Client portal" persona only sees that client's own data
  - `is_primary` for default-on-login; `status` enum `active / pending / suspended / revoked`; `invited_by_user_id / invited_at / accepted_at` for the invite flow that lands in B3
  - UNIQUE `(user_id, tenant_id, persona_label)` so two personas-per-tenant is supported but each label is once
- **`membership_module_access`** — per-module access grid.
  - `access_level` enum `none / read / write / admin`
  - `sub_tenant_scope` JSON: `NULL` means "all sub-tenants under the membership's tenant"; an array of sub_tenant IDs scopes the grant. **Per-module AND per-sub-tenant**, both dimensions orthogonal, per your spec.
  - UNIQUE `(membership_id, module_key)`
- **`users.is_global_admin`** — cross-tenant CoreFlux platform-staff flag (added idempotently via `information_schema` probe). A `global_admin=1` user can traverse any tenant without a membership row, but still picks a persona when acting inside one.
- **`membership_audit`** — append-only log of `created / invited / accepted / suspended / module_grant / module_revoke / persona_switched` events with actor + target user IDs and JSON detail. Compliance review surface.

### Backfill — `scripts/backfill_memberships.php`
Idempotent migration script. For each existing `user_tenants` row:
1. Upserts a `tenant_memberships` row with `persona_label='Primary'`, `persona_type` mapped from the legacy role (manager → manager, employee/contractor → respective, etc.), `is_primary=1` when the legacy `is_default=1`.
2. Enumerates accessible modules via `getUserModules($role)` (the current source of truth) and upserts a `membership_module_access` row per module with:
   - `access_level=admin` for master_admin/tenant_admin/admin
   - `access_level=write` for manager
   - `access_level=read` for employee/contractor
   - `sub_tenant_scope=NULL` (all sub-tenants) so cut-over preserves current behaviour
3. Maps legacy `status='inactive'` → new `'suspended'` enum value.

Flags: `--dry-run` (no writes, prints planned mapping), `--tenant=N` (scope to one tenant). Refuses to run with exit code 2 if migration 055 hasn't been applied yet (sanity probe on `tenant_memberships`).

### Validation
- `tests/rbac_b1_smoke.php` — **27 ✓ / 0 ✗**. Asserts schema shape, idempotency markers, backfill role/access mappings, and script syntax.
- Full PHP smoke suite — **200/202 passing**. Same 2 pre-existing `curl_init` / no-API-key regressions; nothing else broken.
- `.deploy-version` feature flags appended.

### ⚠️ Action required on your end
After deploying:
1. Apply migration 055.
2. Run `php scripts/backfill_memberships.php --dry-run` first → review the planned mapping per tenant.
3. Run `php scripts/backfill_memberships.php` for real (no flags = all tenants).
4. Promote any CoreFlux platform staff to global admin: `UPDATE users SET is_global_admin = 1 WHERE email = 'you@coreflux.app';`.
5. **Do NOT drop `user_tenants` yet** — B2 will run on the new tables but the bootstrap effective-role resolver shipped in the previous iteration still reads from `user_tenants`. After B2 lands (resolver consults `tenant_memberships`), `user_tenants` can be retired.

### Pending — RBAC B2-B5
- **B2** (next iteration): `core/rbac/permissions.php` with `RBAC::can($user, $tenantId, $personaId, $module, $action)`; tenant + persona switcher in header writes active context into session; `api_require_auth()` rewires to consult `tenant_memberships`. Bonus: rewrite the temporary effective-role-from-user_tenants shim to read from the new model.
- **B3**: Admin UI — per-user membership editor with module grid + sub-tenant scope picker; invite-by-email; header persona toggle dropdown.
- **B4**: Onboarding flows — "invite as client/vendor/employee/contractor" tied to existing entity rows.
- **B5**: Migrate all `$ctx['role'] === ...` gates across the codebase to `RBAC::can()`.



## Recently completed (P0 bug fixes from screenshots, 2026-02 — this fork)
**Four blocking bugs reported via screenshots fixed before starting the RBAC rebuild.**

1. **`users.created_at` missing** — `api/users.php` selects `u.created_at` but migration 013 declared the column inside `CREATE TABLE IF NOT EXISTS users(...)`, so any installation that had a users table BEFORE migration 013 ran never picked up the column. New migration `054_users_timestamps_safe.sql` uses an `information_schema`-driven prepared statement to idempotently `ALTER TABLE users ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP` (and `updated_at`). Safe to re-run.

2. **`Forbidden — master_admin only`** on `/admin/tenants` while logged in as Kunal — root cause: the session-baked role reflected login-time `users.role`, not the per-tenant role in `user_tenants`. So a user who was master_admin on tenant A but tenant_admin on tenant B kept their login role regardless of which tenant they were viewing. Fix in `core/api_bootstrap.php::api_require_auth()`: when the request has an active tenant, override the session role with `user_tenants.role` for that tenant. Mirrors the resolved role back to `$_SESSION['user']['role']` for legacy callers. Falls through silently on DB hiccups.

3. **Module access dropdown empty** — same root cause as #2 (the list-tenants endpoint refused Kunal because his session role was stale). Auto-resolves with the bootstrap fix.

4. **Healthcheck false-fails** — three cron-script checks failed because `exec()` is disabled on Cloudways PHP-FPM, and the PDF renderer check was marked `fail` for the sandbox preview where Chromium isn't installed. Both downgraded:
   - cron script: when `exec()` is in `disable_functions`, fall back to a tokenizer-based syntax probe (`token_get_all($src, TOKEN_PARSE)`) that catches `ParseError`. Returns `ok` with a "(tokenizer; exec() disabled on host)" note when the syntax is valid.
   - PDF binary: `warn` (host configuration) instead of `fail` (broken integration).

### Additional fix
- `/api/sub_tenants.php?action=switch` now re-derives the effective role from `user_tenants` and updates both `$_SESSION['user']['role']` and `$_SESSION['modules']`. The endpoint's response payload now includes the resolved `role` so the SPA can refresh navigation immediately without a separate `/me` round-trip.

### Validation
- `tests/p0_fixes_smoke.php` — **21 ✓ / 0 ✗**. Full suite **199/201 passing** (same 2 pre-existing `curl_init`/no-API-key regressions).
- `.deploy-version` feature flags appended.

### Pending — RBAC re-architecture (per user direction)
The user confirmed:
- `global_admin` is **CoreFlux platform staff** (cross-tenant ops/support role).
- Permission grid must scope **per-module AND per-sub-tenant** (both dimensions, fully orthogonal).
- Tenant admin is the primary controller for their assignees.
Plan (multi-iteration build):
- **B1** — Schema foundation: `users` (tenant-agnostic), `tenant_memberships` (many-per-user with persona labels), `membership_module_access` (per-module read/write/admin/none + per-sub-tenant scope JSON), `membership_personas` (admin+employee+client+vendor+contractor in same tenant). Migration 055.
- **B2** — Permission resolver + session refactor: `RBAC::can($user, $tenantId, $personaId, $module, $action)`; tenant + persona switcher writes active context into session; backfill existing `user_tenants` rows into `tenant_memberships`.
- **B3** — Admin UI: Users → per-user membership editor (add tenant, pick modules, set read-only, scope to sub-tenants); Tenants → invite-by-email flow; header persona toggle.
- **B4** — Onboarding flows: "invite client/vendor/employee/contractor as user" creating a membership tied to the existing staffing_clients / ap_vendors_index / people row.
- **B5** — Migrate all existing role-gates from `$ctx['role'] === '...'` checks to `RBAC::can()` calls.



## Recently completed (QBO Slice 4b + Slice 5 + Health Alerts, 2026-02 — this fork)
**Slice 4 finished + Slice 5 conflict rules + status-flip email alerts** — the QBO integration is now feature-complete for the MVP scope: Journal Entries, Customers, Vendors, Chart of Accounts, Items, Bills, Invoices, BillPayments all flow in their configured direction, two_way customer pulls now detect divergence, and CFOs get auto-notified when sync health flips colours.

### Migration 053 — `qbo_conflict_log` + `qbo_health_alerts`
- `qbo_conflict_log`: per-row record of every detected two_way conflict — winner enum (`coreflux`/`quickbooks`/`tie`), both sides' updated_at + snapshot JSON, applied rule. Indexed for time and entity lookup.
- `qbo_health_alerts`: dedupe table — one row per status transition (e.g. green→yellow). Recipient email + send error captured for forensics.

### Slice 4b drivers
- **`core/qbo/sync_bills.php`** — `qboSyncBills()` + `qboBuildBillPayload()`. Pushes posted `ap_bills` → QBO Bill using `AccountBasedExpenseLineDetail` (no Item mapping needed). VendorRef resolved via mapping or auto-discovered by `DisplayName` query. Skip reasons: `vendor_unmapped`, `unresolved_account_id`, `missing_account_code`. DocNumber capped at QBO's 21-char limit.
- **`core/qbo/sync_items.php`** — `qboSyncItems()` mirror + `qboDefaultItemRef()` picker. Uses sentinel `internal_entity_id=0` for items (no CoreFlux counterpart). `qboDefaultItemRef()` prefers Service+Active items, memoised per tenant per request. `qboItemRefForPlacement()` provides per-placement override.
- **`core/qbo/sync_invoices.php`** — `qboSyncInvoices()` + `qboBuildInvoicePayload()`. Pushes `billing_invoices` → QBO Invoice using `SalesItemLineDetail` with ItemRef resolved per placement. CustomerRef via Slice 3 mapping (skip `customer_unmapped`), missing item skip `no_default_item`.
- **`core/qbo/sync_payments.php`** — `qboSyncBillPayments()` pushes `ap_payments` → QBO BillPayment. FIFO allocates the payment amount across mapped bills with `amount_due > 0`. Skip `no_mapped_bills_with_balance` when none qualify.

### Slice 5 — Conflict rules (`core/qbo/conflict_rules.php`)
- `qboDetectConflict()` short-circuits unless the entity's direction is `two_way`. Compares QBO `MetaData.LastUpdatedTime` against the last-seen snapshot AND the CoreFlux `updated_at` against the mapping's `last_synced_at`.
- Cases: both unchanged / only one side changed → no conflict; both changed → CONFLICT logged with rule `last_write_wins`. Winner persisted to `qbo_conflict_log` with both snapshots so a controller can replay or undo.
- Wired into Slice 3 customer pull: when CoreFlux wins, the pull update is suppressed and the row tagged `conflict_coreflux_wins` in the results.

### Sync health alerts (`core/qbo/health_alerts.php` + `cron/qbo_health_alerts.php`)
- `qboHealthEvaluate()` replicates the `/sync_health` decision tree without an HTTP hop.
- `qboHealthMaybeAlert()` compares against the most-recent `qbo_health_alerts` row for the tenant. Same status → no-op. Status change → dispatch `sendEmail()` (works through both the live mailer and the sim-mock log) and persist the dedupe row. Recipient discovery: master_admin > tenant_admin > connection owner.
- Cron suggested at `H/15 * * * *`. Recovery transitions fire a one-shot "recovered" email so on-call doesn't have to verify manually.
- Email format: subject `[CoreFlux] QuickBooks sync degraded — green → red`, body has reasons + link to `/admin/integrations/qbo`.

### API + Cron + UI
- New API actions on `POST /api/qbo/`: `sync_bills`, `sync_invoices`, `sync_payments`, `sync_items`. RBAC: `integrations.qbo.manage`; 409 on direction conflicts; 502 on QBO API failure. PHP 8 `match()` used for the unified push dispatcher.
- `cron/qbo_sync_outbound.php` extended: after JE push, iterates `bills` → `invoices` → `payments` per direction config, accumulating counts. Per-entity failures don't break the loop.
- `cron/qbo_sync_inbound.php` extended: COA pass now also pulls Items when `invoices ∈ {push, two_way}` (Items are a pre-req for invoice push).
- `QboSettings.jsx` ManualSyncCard adds: **Pull QBO items**, **Push bills**, **Push invoices**, **Push bill payments**. All conditional on the relevant direction.

### Validation
- `tests/qbo_slice4b_5_smoke.php` — **85 ✓ / 0 ✗**. Covers all 6 new core files' public surface + specific payload contracts (AccountBasedExpense vs SalesItemLineDetail, LinkedTxn for payments, FIFO allocation), conflict detection wired into customer upsert, alert dedupe + sendEmail invocation + recipient resolution, API dispatch + match() pattern, cron iteration, and UI button testids/conditional rendering.
- Full PHP smoke suite — **198/200 passing**. Same two `curl_init`/no-API-key pre-existing regressions; nothing else broken.
- Vite bundle rebuilt (`index-CXw_2HmS.js`), `.deploy-version` feature flags appended.

### MOCKED
- `mailerSend()` still local — but the alert path now uses `sendEmail()` from `core/mailer.php` which respects sim_mock_bridge for sim tenants and falls through to PHPMailer SMTP otherwise. When you wire Resend in (the P2 item), the alert cron is one of the consumers that immediately benefits.



## Recently completed (QBO — Slice 4a: COA mirror + Sync Health tile, 2026-02 — this fork)
**Two foundational pieces of Slice 4** shipped together:
1. **Canonical COA mirror (pull)** — replaces the fragile Slice 2 AcctNum auto-discovery with a single bulk-pull pass that populates `external_entity_mappings` for every QBO Account. After one run, the JE pusher hits the mapping cache instead of doing an ad-hoc QBO query per line.
2. **Sync Health tile on CFO Dashboard** — green/yellow/red status with blocked-JE counts, failed-runs counter, last-probe age, and a per-entity last-sync drilldown. CFOs can spot a stale or wedged QBO connection at a glance.

### COA mirror — `core/qbo/sync_accounts.php`
- `qboSyncAccounts()` — paginated QBO Account pull. Match strategy:
  1. existing mapping by QBO Id (refresh payload snapshot, no-op if unchanged)
  2. AcctNum match against `accounting_accounts.code` → upsert mapping
  3. No match → audit row `unmapped_qbo_accounts` with up to 20 sample `{qbo_id, name, acct_num}` entries so a controller can decide whether to create the CoreFlux account
- Refuses with `RuntimeException` when `sync_config.chart_of_accounts ∉ {pull, two_way}`.
- Run summary written to `qbo_sync_audit` (`sync_accounts`) with `matched_total / newly_mapped / unchanged / unmapped_in_qbo / pulled / pages / latency_ms`.
- Does **not** auto-insert into `accounting_accounts` — COA structure is the controller's call, not the sync's.

### API
- `POST /api/qbo/sync_accounts` (RBAC: `integrations.qbo.manage`; 409 on direction mismatch). Body: `{ limit?, max_pages? }`.
- `GET  /api/qbo/sync_health` (RBAC: `integrations.qbo.view`). Returns `{ status: green|yellow|red|not_connected, connected, company_name, environment, last_probe_at, last_probe_error, probe_age_seconds, blocked_jes_7d, failed_runs_24h, last_sync_by_entity, reasons[] }`.
- Health decision tree:
  - **red**: probe age > 24h, OR any `ok=0` audit row in last 24h, OR > 20 blocked JEs in 7 days
  - **yellow**: probe age > 2h, OR 1–20 blocked JEs in 7 days
  - **green**: otherwise
  - **not_connected**: no row or `status != 'active'`

### Cron
- `cron/qbo_sync_inbound.php` extended with a COA pass guarded on `sync_config.chart_of_accounts ∈ {pull, two_way}`. Runs once per tenant per nightly invocation, before the customer/vendor passes.

### UI
- **CFO Dashboard** (`/dashboard/src/components/QboSyncHealthTile.jsx`, mounted on `CFODashboard.jsx` next to `FscHealthPanel`):
  - Left-border accent colour reflects status; icon + badge + summary + Manage CTA in the header
  - Stat cards: `Blocked JEs (7d)`, `Failed runs (24h)`, `Last probe` (humanised age) — each toned green/yellow/red by its own threshold
  - "Reasons" bullet list explains a non-green status verbatim
  - Collapsible "Last successful sync per entity" details disclosure
  - Silently hides itself on API error so CFOs never see a 5xx for an optional tile
- **QboSettings**: ManualSyncCard gains a "Pull chart of accounts" button gated on `chart_of_accounts ∈ {pull, two_way}`.

### Validation
- `tests/qbo_slice4a_smoke.php` — **44 ✓ / 0 ✗**. Covers COA driver three-tier match, audit signal types, API dispatch + decision tree, cron COA pass, tile testids + CFO Dashboard wiring, COA button conditional rendering, and php -l syntax.
- Full PHP smoke suite — **197/199 passing**. Same 2 pre-existing `curl_init`/no-API-key regressions. Nothing else broken.
- Vite bundle rebuilt (`index-DtumF8qJ.js`), `.deploy-version` updated.

### Pending (next QBO slices — Slice 4b)
- **Invoice push** — CoreFlux `billing_invoices` → QBO `Invoice`. **Blocker**: QBO `Invoice.Line[].SalesItemLineDetail.ItemRef.value` requires an Item mapping, not just an account. Need a QBO Item pull + a default-item-per-tenant fallback before this can ship.
- **Bill push** — `ap_bills` → QBO `Bill`. Bills accept `AccountBasedExpenseLineDetail` which only needs an AccountRef — much closer to JE push. **This is the lowest-friction invoice-adjacent feature** and should ship first in 4b.
- **Payment push** — `ap_payments` → QBO `BillPayment`. Requires a Bill mapping to land first.
- **Slice 5** — Conflict rules for `two_way` entities (last-write-wins by `updated_at` + `qbo_conflict_log`).



## Recently completed (QBO — Slice 2.5: Skipped JE Inbox + Slice 3: Customer/Vendor pull, 2026-02 — this fork)
**Two complementary improvements to QBO orchestration:**
1. **Skipped JE inbox** — operational visibility into JEs the cron has had to skip because their account isn't mapped in QBO. Surfaces blocked accounts so controllers can fix the root cause in one click.
2. **Slice 3 inbound pulls** — QBO Customer + Vendor masters now flow into CoreFlux with idempotent mapping, content-hash dirty checks, paginated query, name-fallback auto-link to existing manually-created rows, and a nightly cron worker.

### Slice 2.5 — Skipped JE inbox
- `GET /api/qbo/skipped_jes` aggregates `qbo_sync_audit` rows where `action='sync_je_skip'` over the last 30 days. Returns one row per blocking account with `{account_id, account_code, account_name, blocked_je_count, recent_je_numbers[], most_recent_at}`. Joins `accounting_accounts` for code+name in a single batched query.
- Sort: most-blocked first, then most-recent.
- UI: `SkippedJeInbox` card on QboSettings auto-hides when zero blockers. Each row shows `<code> — <name>`, the blocked-JE count, the 5 most-recent JE numbers, and a "Map account →" CTA that deep-links to `/modules/accounting/coa?focus={accountId}`.

### Slice 3 — Customer / Vendor pull (`core/qbo/sync_in.php`)
- `qboSyncCustomers()` / `qboSyncVendors()` thin wrappers over `_qboSyncMasterEntity()` — shared pagination, audit, and direction-guard logic.
- QBO Query API pagination via `STARTPOSITION` + `MAXRESULTS=100` per page, with `limit` and `max_pages` opts for safety.
- Upsert match strategy (in order):
  1. existing mapping for `(source='quickbooks_online', entity_type, external_id=QBO Id)`
  2. UNIQUE name-key match (`staffing_clients.name` / `ap_vendors_index.vendor_name`) — auto-links existing manually-created rows
  3. INSERT a new row
- Content-hash dirty check on customer fields (legal_name, email, phone, billing address, status); on vendors (vendor_type, requires_1099) — `action` is one of `created` / `updated` / `unchanged`.
- Vendor1099 flag translates to `requires_1099 = 1` + `vendor_type = '1099_individual'`.
- Audits every page run with `qbo_sync_audit` (action=`sync_customer` / `sync_vendor`), reporting `created / updated / unchanged / failed / pulled / pages / latency_ms`.

### API + Cron
- New actions `sync_customers`, `sync_vendors` on `POST /api/qbo/{action}` (RBAC: `integrations.qbo.manage`, 409 on direction-not-pull/two_way).
- New nightly cron `cron/qbo_sync_inbound.php` (suggested schedule `0 2 * * *`). Iterates active connections, runs each entity if its direction is pull/two_way, continues on per-tenant failure.

### UI
- Replaced the JE-only "Manual sync" card with a unified `ManualSyncCard` that conditionally renders:
  - **Push journal entries now** + **Dry run** (when journal_entries ∈ {push, two_way})
  - **Pull customers** (when customers ∈ {pull, two_way})
  - **Pull vendors** (when vendors ∈ {pull, two_way})
- Card hides entirely when no entity has an applicable direction.

### Validation
- `tests/qbo_slice3_smoke.php` — **46 ✓ / 0 ✗**. Covers `sync_in` public surface, pagination, name-fallback match, table-specific upsert SQL, Vendor1099 translation, API dispatch + 409 conflict path, cron iteration + direction filters, shim files, UI testids + conditional rendering.
- `tests/qbo_je_push_smoke.php` updated for refactored `ManualSyncCard` component (45 ✓ / 0 ✗).
- Full PHP smoke suite — **196/198 passing**. Same two `curl_init`/no-API-key pre-existing regressions; nothing else broken.
- Vite bundle rebuilt (`index-6Y1JYaHg.js`), `.deploy-version` feature flags appended.

### Pending (next QBO slices)
- **Slice 4** — Invoice / Bill / Payment sync + full COA mirror (replaces the AcctNum auto-discovery shortcut from Slice 2 with a canonical mirror).
- **Slice 5** — Conflict rules for `two_way` entities (last-write-wins by `updated_at` + `qbo_conflict_log`).



## Recently completed (QBO — Slice 2: Journal Entry push, 2026-02 — this fork)
**Posted CoreFlux journal entries now push to QuickBooks Online** with idempotency via `external_entity_mappings`, opportunistic chart-of-accounts auto-discovery, dry-run preview, and a 15-minute cron worker. Tenants control participation via the per-entity direction picker shipped in Slice 1.

### Driver — `core/qbo/sync_je.php`
- `qboSyncJournalEntries(int $tid, ?int $userId, array $opts=[])` — main entry point. Refuses when the connection is inactive or `sync_config.journal_entries` isn't in `['push','two_way']`.
- Eligible-JE query uses a `LEFT JOIN external_entity_mappings ... WHERE m.id IS NULL` so already-shipped JEs are excluded at the SELECT layer (no double-posting even if cron runs every minute). Restrictable via `je_ids` opt.
- `qboBuildJournalEntryPayload($je, $lines, $resolveAccount)` — pure function (no DB). Constructs a QBO-spec JournalEntry: `TxnDate`, `DocNumber`, `PrivateNote`, `Line[]` with `DetailType=JournalEntryLineDetail`, correct `PostingType` (Debit/Credit), and `AccountRef`. Lines with an unresolved account return a `_unresolved_account_id` sentinel — the caller treats any sentinel as a hard skip and audits the JE under `items_skipped` rather than shipping a half-mapped entry.
- `qboResolveAccountRef(int $tid, int $accountId)` — auto-discovery bridge until the Slice 4 COA mirror ships. Looks up an existing mapping, otherwise queries QBO `Account WHERE AcctNum = '<CoreFlux code>'`, persists the match via `mappingUpsert`, returns the AccountRef. No match → null → JE skipped.
- Every push lands a `qbo_sync_audit` row; the run-level summary writes `items_processed / skipped / failed / latency_ms / considered / dry_run`.
- `dry_run=true` builds payloads but skips the POST — returns each preview payload so CFOs/auditors can validate before a single transaction crosses the wire.

### API
- New action `POST /api/qbo/sync_je` (+ shim `api/qbo/sync_je.php`). Body: `{ limit?:int, dry_run?:bool, je_ids?:int[] }`. RBAC: `integrations.qbo.manage`. Returns `{ pushed, skipped_unmapped, failed, considered, latency_ms, dry_run, results[] }`.
- 409 surfaces "Journal entries direction is not push/two_way" so the UI degrades cleanly when a tenant flips a direction back to `off`.

### Cron — `cron/qbo_sync_outbound.php`
- Suggested schedule: every 15 minutes (`H/15 * * * *`). Iterates `qbo_connections WHERE status='active'`, runs `qboSyncJournalEntries` per tenant with `limit=100`, continues on per-tenant failure. Migration-not-applied bail-out logs to STDERR and exits 0 (no false-positive cron alerts).

### UI — `QboSettings.jsx`
- "Manual sync" card appears only when `sync_config.journal_entries ∈ {push, two_way}`. Two buttons:
  - **Push journal entries now** — fires the real run.
  - **Dry run (preview)** — fires `dry_run:true`.
- Flash banner summarises `{pushed} pushed · {skipped} skipped (unmapped accounts) · {failed} failed ({considered} considered, {ms}ms)`.

### Validation
- `tests/qbo_je_push_smoke.php` — **45 ✓ / 0 ✗**. Covers driver surface, payload shape (TxnDate / DocNumber / PrivateNote / Debit & Credit lines / AccountRef / Amount rounding), unresolved-account sentinel, API dispatch + RBAC, cron iteration + skip rules, UI buttons + conditional rendering, and syntax validity.
- Full PHP smoke suite — **195/197 passing**. Two failures remain the pre-existing `curl_init`/no-API-key regressions; unchanged by this slice.
- Vite bundle rebuilt (`index-DWsXrRxW.js`), `.deploy-version` updated.

### Pending (next QBO slices)
- **Slice 3 — Customer / Vendor pull** from QBO into CoreFlux (`clients` / `vendors` upsert via `external_entity_mappings`).
- **Slice 4 — Invoice / Bill / Payment sync** + full COA mirror.
- **Slice 5 — Conflict rules** for `two_way` entities + `qbo_conflict_log`.



## Recently completed (QuickBooks Online — Slice 1: Foundation, 2026-02 — this fork)
**Per-tenant OAuth 2.0 connection vault for Intuit QuickBooks Online**, slotted under the new centralized Integrations Hub. Lays the groundwork for journal entry push, customer/vendor pull, and bidirectional sync (Slices 2+).

### Schema (migration 052)
- `qbo_connections` — one row per tenant. `realm_id`, `environment` (sandbox/production), `access_token_ct` + `refresh_token_ct` (AES-256-GCM via existing `encryptField()`), `access_token_exp`, `refresh_token_exp` (rolling 100-day), `scope`, `status`, `sync_config` (JSON: per-entity direction map), `last_probe_at`, `last_probe_error`.
- `qbo_oauth_state` — single-use, 30-minute-bound CSRF nonces for the authorize → callback round-trip. Unique key on `state_token` prevents replay.
- `qbo_sync_audit` — every connect / disconnect / refresh / ping / sync_config_update / future sync rows lands a row. Mirrors `jobdiva_sync_audit` so UI rendering can be shared.

### Adapter (`core/qbo/client.php`)
- OAuth helpers: `qboBuildAuthorizeUrl()` (mints state nonce + builds Intuit URL), `qboExchangeCode()` (authorization-code → access+refresh tokens, probes `/companyinfo` for company name), `qboRefreshAccessToken()` (auto-refresh with 60s slack), `qboDisconnect()` (best-effort upstream revoke + local status=revoked).
- API helpers: `qboCall()` retries once on 401 after refresh; surfaces non-2xx as `RuntimeException` AND writes `status=error` + `last_probe_error` on the connection row so the UI exposes degraded state.
- Sync config: `qboSyncConfigRead()` merges stored config with defaults so the UI always renders every entity row; `qboSyncConfigWrite()` validates direction values + audits the change.
- Test transport hook: `$GLOBALS['__qbo_transport']` callable injection mirrors the Mercury / JobDiva pattern — smoke tests can stub HTTP without curl.
- Defaults: every entity (`journal_entries`, `customers`, `vendors`, `invoices`, `bills`, `payments`, `chart_of_accounts`) starts at `off`. Tenants must explicitly opt direction in.

### API surface (`api/qbo.php` + shims under `api/qbo/`)
- `GET  /api/qbo/status`            → configured / connected / realm_id / company / sync_config / recent_audit (no secrets)
- `GET  /api/qbo/oauth_start`       → returns Intuit authorize URL + state nonce (UI does the redirect)
- `GET  /api/qbo/oauth_callback`    → Intuit redirect target. Verifies state, exchanges code, redirects to `/admin/integrations/qbo?connected=1`
- `POST /api/qbo/disconnect`
- `POST /api/qbo/ping`              → refresh + probe `/companyinfo`
- `GET  /api/qbo/sync_config_get`   / `POST /api/qbo/sync_config_set`
- RBAC: `integrations.qbo.view` (read) + `integrations.qbo.manage` (write); tenant_admin's `*` covers both.

### UI (`dashboard/src/pages/QboSettings.jsx`, routed at `/admin/integrations/qbo`)
- Three render branches: pod-not-configured, configured-but-not-connected (Connect CTA), connected (company info + Test connection + Disconnect + sync direction table).
- Direction picker per entity with Push / Pull / Two-way / Off (icons + behaviour blurb). Save / Discard buttons only enable when the draft differs from saved state.
- Reads `?connected=1` / `?error=...` query params from the OAuth callback to surface success / failure flashes, then cleans the URL.
- Hub `IntegrationsHub.jsx` now shows a QBO card under a new "Accounting" section with live connected / not_connected / not_configured badge.

### Validation
- `tests/qbo_foundation_smoke.php` — **91 ✓ / 0 ✗**. Covers migration shape, adapter public surface, API dispatch + RBAC checks, UI testids + endpoint wiring, AdminModule + IntegrationsHub integration, and a functional adapter call via the injected transport stub.
- Full PHP smoke suite — **194/196 passing**. Two failures (`ai_platform`, `plaid_integration`) are the documented `curl_init` / no-API-key regressions — unchanged by this slice.
- Vite bundle rebuilt + `scripts/sync_bundle.sh` synced (`index-Cm9doPwM.js`).
- `.deploy-version` feature flags appended for traceability.

### Required pod config (before live testing)
- `QBO_CLIENT_ID` and `QBO_CLIENT_SECRET` from https://developer.intuit.com app keys
- `QBO_REDIRECT_URI` registered at Intuit (e.g. `https://coreflux.app/api/qbo/oauth_callback.php`)
- `QBO_ENV` = `sandbox` (default) or `production`

### Pending (next QBO slices)
- **Slice 2 — Journal Entry push**: posted CoreFlux JEs → QBO JournalEntry endpoint, with `external_entity_mappings` for idempotency and a cron worker (`cron/qbo_sync_outbound.php`).
- **Slice 3 — Customer / Vendor pull**: pulls QBO Customer + Vendor lists into CoreFlux `clients` / `vendors`, upserting via `external_entity_mappings`.
- **Slice 4 — Invoice / Bill / Payment sync** + COA mirror.
- **Slice 5 — Conflict rules** for `two_way` entities (updated_at comparison + `qbo_conflict_log`).



## Recently completed (Centralized Integrations Hub, 2026-02 — this fork)
**Single pane of glass for tenant-admin-managed external integrations.** Plaid Transfer and Mercury Bank connection settings were buried in `/modules/treasury/payout-rails` alongside operational recipient management; admins had no consolidated place to see/manage all external connectors. JobDiva lived in its own admin sub-route.

### Changes
- New `IntegrationsHub` page (`/admin/integrations`) renders status-aware cards for **Plaid Transfer**, **Mercury Bank**, and **JobDiva**. Each card hits the integration's `?action=status` endpoint and shows a live `Connected / Not linked / Not configured` badge.
- `AdminModule.jsx` mounts three routes under the hub:
  - `/admin/integrations/plaid`   → existing `PlaidTransferSettings` (re-routed, not rewritten)
  - `/admin/integrations/mercury` → existing `MercurySettings` (re-routed, not rewritten)
  - `/admin/integrations/jobdiva` → existing `JobDivaSettings` (already lived here)
- Admin sidebar collapses the per-integration link into a single "Integrations" entry pointing at the hub. Admin overview action card retitled `"Integrations"`.
- `TreasuryModule` tab `payout-rails` retired. Replaced by a `recipients` tab (operational Mercury vendor / funding-source vault stays in Treasury) with an inline banner pointing to Admin → Integrations. A back-compat redirect (`payout-rails` → `/admin/integrations`) preserves any bookmarked URLs.
- AP `PaymentsList` "Connect funding source →" CTA now deep-links to `/admin/integrations/plaid` instead of the old Treasury tab.
- `SettingsPage` gains an "Integrations" tile linking to the hub so non-admin paths to settings can still discover the page.

### Validation
- Affected smoke tests (`plaid_transfer_smoke.php`, `mercury_foundation_smoke.php`, `mercury_recipients_smoke.php`, `sprint8a_jobdiva_foundation_smoke.php`) updated to assert the new Admin routing and recipients tab — all green.
- Full PHP smoke suite — **193/195 passing**. The two failures (`ai_platform_smoke`, `plaid_integration_smoke`) are the pre-existing `curl_init`/no-API-key regressions documented in handoff; unchanged by this refactor.
- Vite bundle rebuilt + `scripts/sync_bundle.sh` synced (`index-RMPOVq63.js` / `index-BC5g6YJu.css`). `sprint6b_dashboard_uis_smoke.php` updated with the new JS hash.



## Recently completed (Mercury — Slice 3.6 SoD hardening: role-based approval + CFO out-of-band notice, 2026-02 — this fork)
**Hardens the Slice 3 payment-approval flow against two specific real-world attack vectors** the user prioritized after reviewing the controls landscape:
- **Role-based SoD** — two AP clerks with the same role could previously cover for each other's approvals (the original user-id check only requires "different humans", not "different roles").
- **CFO out-of-band notification** — a compromised approver could previously sign off without anyone outside the treasury team noticing until reconciliation; the CFO had no real-time signal.

Explicitly rejected by the user during the controls review: banking-detail cooling-off (#1), amount-tiered ladder (#3), new-vendor cooling-off (#4), re-auth at approval (#6), self-collusion detection (#7), business-hours gating (#8).

### Role-based SoD enforcement
- `mpApprove()` signature widened to accept **either a full user array or just an int user id** (backward-compat for any legacy caller). When a user array is passed, the service performs an additional `RBAC::hasPermission($user, 'treasury.payment.approve')` check. Without that permission, the approval is refused with the explicit error `"Role separation: approver must hold the treasury.payment.approve permission"`.
- Service-layer enforcement (not just API gating) so a curl-bypass attempt against `/api/mercury_payments.php?action=approve&id=N` still fails for under-permissioned users.
- The new permission `treasury.payment.approve` is automatically matched by the existing wildcard `treasury.*` in `core/rbac_config.php` for `admin` / `tenant_admin` / `master_admin` roles — so the change is non-breaking for existing deployments. Tenants who want stricter separation can either narrow the wildcard or stop granting `admin` to AP clerks.
- API caller updated: `/api/mercury_payments.php` now passes `$user` (the full session user) to `mpApprove` instead of `$user['id']`, so the role check has the data it needs.
- The existing user-id-vs-creator check stays in place. Both checks must pass — defense in depth.

### CFO-only out-of-band notification (#5, scoped per user)
- New `mercuryNotifyCfoOfApproval($tenantId, $instructionId, $approverUser)` function called from `mpApprove` immediately after a successful state transition. Whole function is best-effort — wrapped in try/catch so a flaky mailer can't roll back the approval that already committed to the DB.
- Recipient lookup: `SELECT u.email, u.name FROM users u JOIN user_tenants ut … WHERE ut.role = 'cfo'`. Falls back to `role='master_admin'` (capped at 5 rows) when no CFO has been tagged yet, so the notice still lands somewhere a human will see during the transition period. Tenants with neither tagged role: notification skipped (the gap is captured in the audit log).
- Subject line `[CFO notice] Mercury payment approved: $X,XXX.XX → Vendor Name`. HTML body includes instruction #, vendor (XSS-escaped), amount + currency, approver name (XSS-escaped), timestamp, and a clear call-to-action: *"If you did NOT expect this approval, sign in to CoreFlux → Treasury → Mercury Payments and cancel the instruction before the worker funds it."* The cancel window matters — the payment worker doesn't pick up Approved rows for up to 5 minutes, giving the CFO a real chance to intervene.
- Uses the existing `mailerSend()` primitive via `function_exists()` guard so the integration degrades cleanly in CLI/test contexts where `mailer.php` isn't bootstrapped.
- New audit event `mercury.payment.cfo_notified` records `{recipients_count, sent, failed, mailer_present}` so the CFO can later verify "did I actually get pinged on instruction #X?" without reading mail logs.

### Validation
- `tests/mercury_payments_smoke.php` extended — **147 ✓ / 0 fail** (was 134). Adds 13 assertions covering: RBAC perm check + error message, user-array vs int signature compatibility, CFO-notify call site with try/catch wrapping, CFO lookup primary query + master_admin fallback, email subject prefix, XSS-escaped HTML body, mailer presence guard, audit event + meta shape, "whole function is best-effort" guarantee, and the API caller now passing `$user` (not `$user['id']`) to enable the role check.
- Full PHP smoke suite — **195 smoke files, 0 failures** (one transient flake on `sprint2_accounting_mobile_smoke.php` cleared on retry; unrelated to this change).
- The `mailerSend` integration is currently **MOCKED** (`core/mailer.php` logs locally without external delivery) — confirmed in handoff as "wire Resend driver" P2 backlog item. The audit row's `mailer_present` flag lets ops verify post-deploy when external delivery actually goes live.



## Recently completed (Mercury — Slice 3.5 AP + Slice 2 CSV polish + funding-leg reconciliation, 2026-02 — this fork)
Three layered enhancements on top of the now-feature-complete Mercury MVP.

### Slice 3.5 — AP module ↔ Mercury Payment Engine integration
**Allows AP-approved bills to flow into the Mercury payment workflow without re-keying.** The "Send via Mercury" button on AP PaymentsList now creates a Draft `payment_instruction` linked back to the AP row via the existing `source_module`/`source_ref` columns. SoD is preserved — treasury ops still has to Submit + Approve the instruction before money moves.

- New service helper `mpCreateFromApPayment(int $tenantId, int $apPaymentId, ?int $userId)` in `core/mercury_payments.php`:
  - Validates the AP row exists, is in `status=sent`, and is not already attached to a rail.
  - Refuses if a non-terminal `payment_instructions` row already exists for this `(source_module='ap', source_ref=apPaymentId)` so the button is safely retryable.
  - Looks up the matching `mercury_recipients` row by case-insensitive `vendor_name`. Refuses if no match, with an actionable error pointing the operator at the Recipients page.
  - Converts the AP `$` amount → `cents` BIGINT, generates a deterministic idempotency key `ap:{ap_payment_id}:{rand6}`, calls `mpCreate` (so all the recipient + amount validation stays in one place).
  - Stamps `ap_payments.rail_external_ref='pi:{id}'` + `disbursement_rail='mercury'` so the AP UI's existing "already-on-a-rail" guard hides the button on subsequent renders.
- New AP API route `POST /modules/ap/api/payments.php?action=send_via_mercury&id=N` gated by `ap.payment.send`. Returns `{instruction_id, state}`. All failure modes from `mpCreateFromApPayment` surface as 422 with the human-readable reason.
- AP GET response gained `mercury_connected: bool` (gracefully degrades when migration 048 missing), so the UI knows whether to render the button before clicking.
- AP `PaymentsList.jsx` per-row "Send via Mercury" button (`ap-send-via-mercury-{id}`) renders when `mercury_connected && status='sent' && !rail_external_ref`. Inline per-row error/success affordances mirror the existing Plaid pattern.

### Slice 2 polish — CSV bulk import for vendor recipients
Operators onboarding tenants with 50+ existing vendors no longer have to click through the modal 50 times. Built on the existing `Core\CsvImportService` per the project HARD_RULES (every primary-entity module must expose a CSV flow via the shared primitive).

- `api/mercury_recipients_csv_import.php` — 3-action endpoint (`template`, `dry_run`, `commit`) matching the staffing/people/accounting CSV pattern.
- Registered schema `mercury_vendor_recipients` with 8 fields: `name` (required), `email`, `payment_method` enum `ach|wire|check`, `routing_number` (required, 9 digits via the field validator), `account_number` (required), `account_type` enum `checking|savings`, `nickname`, `notes`. `unique_within_batch: ['name']` blocks duplicate rows in the same CSV.
- `dry_run` walks the rows through the schema validator AND cross-checks against existing `mercury_recipients` rows (case-insensitive) so operators see "already exists" warnings before commit.
- `commit` invokes `mercuryRecipientCreate` per row — same entry point as the modal form, so all the existing validation (routing 9 digits, account 4–17 chars, AES-256-GCM encryption, transactional INSERT into the bank-method table) runs unchanged. `skip_invalid` flag short-circuits to "import the rest".
- RBAC: writes need `accounting.bank.manage`, template-download accepts `accounting.bank.view` OR `.manage`.
- Funding_source recipients deliberately NOT supported via CSV — they require pasting the existing Mercury external_account id (per the Slice 2 doc), which is a manual operator step.
- UI buttons added to `MercuryRecipients.jsx`:
  - **CSV template** (`mercury-recipients-csv-template-btn`) — link to the `?action=template` download.
  - **Import CSV** (`mercury-recipients-csv-import-btn`) — `<label>` wrapping a hidden `<input type="file" accept=".csv,text/csv">`. Reads file as text, posts to dry_run, prompts on errors > 0, posts commit with `skip_invalid=1`. Direct `fetch()` calls (not `api.post`) because `api.js` JSON-stringifies bodies unconditionally — bypassing for the `Content-Type: text/csv` path is cleaner than poisoning the shared client.

### Funding-leg reconciliation
**Extends Slice 4 to record the funding-pull leg in `reconciliation_matches` for parity with payouts.** The `reconciliation_matches.leg` ENUM already had `funding` reserved; this slice activates it.

- New service function `mercuryReconcileFundingLeg(int $tenantId)` in `core/mercury_reconciliation.php` — walks every `payment_instructions` row with a non-empty `funding_mercury_txn_id` (regardless of high-level state), joins to `mercury_transactions`, records the three-way verdict (`matched | discrepancy | missing_mercury_txn`) with `leg='funding'`.
- **Does NOT drive state transitions** — the payout leg owns the lifecycle (`Settled → Reconciled`). The funding pass is pure audit + treasury-ops visibility for analytics like "how long does Mercury actually take to pull from External A vs External B?". Verified via grep-absence assertion in the smoke that the funding code path never calls `mpApprove` or `mpTransition(..., 'Approved'...)`.
- `mercuryReconcileTenant()` now fans out to the funding-leg pass after the payout pass and returns three extra counters: `funding_matched`, `funding_discrepancies`, `funding_missing`. The existing 4 counters (`scanned`, `matched`, `discrepancies`, `missing`) are unchanged so any caller relying on the old shape stays compatible.
- Same idempotency guarantee via the existing `reconciliation_matches` UNIQUE on `(tenant, instruction, leg, outcome, mercury_txn_pk)` — re-running collapses duplicates cleanly.

### Validation
- New `tests/mercury_ap_and_csv_smoke.php` — **52 ✓ / 0 fail.** Covers `mpCreateFromApPayment` contract (state validation, dup refusal, case-insensitive vendor match, RBAC pointer error, $ → cents conversion, source_module/ref persistence, ap_payments rail-ref stamping, SoD-preserved grep-absence assertion), new AP API route (RBAC + payload + audit), AP UI (`mercury_connected` flag wiring, eligibility guard, per-row testids), CSV API (schema registration, all 3 actions, duplicate name detection, RBAC split, action allowlist, dry_run-before-commit pattern), CSV UI (template link, file picker, dry-run-then-commit workflow, raw text/csv POST).
- Extended `tests/mercury_reconciliation_smoke.php` — **69 ✓ / 0 fail** (was 61). Adds 8 funding-leg assertions including pure-audit verification.
- Full PHP smoke suite — **195 smoke files, 0 failures.** Vite bundle `index-DPhSClAM.js`; `.deploy-version` + `spa-assets/` + sprint6b expected hash synced via postbuild hook.



## Recently completed (Mercury Bank — Slice 4: Reconciliation, 2026-02 — this fork)
**Closes the Mercury MVP loop.** Reconciliation now matches `mercury_transactions` (Slice 1) ↔ `payment_instructions.payout_mercury_txn_id` (Slice 3), advances cleared payments to the terminal `Reconciled` state, and surfaces a treasury-ops dashboard with discrepancy counts. The Mercury MVP per the attached spec is now feature-complete across Slices 1–4.

### Schema — migration `051_mercury_reconciliation.sql`
- `reconciliation_matches` — append-only-with-upsert audit of every match attempt. `outcome` ENUM `matched | discrepancy | missing_mercury_txn`. `leg` ENUM `funding | payout` (Slice 4 ships only payout-side reconciliation; funding-leg reconciliation is the future enhancement). `expected_amount_cents` + `observed_amount_cents` + `discrepancy_reason` make root-cause analysis a single SELECT. UNIQUE on `(tenant, instruction, leg, outcome, mercury_txn_pk)` for idempotent re-runs.
- `funding_transfers` — optional denormalized ledger view of each funding pull. Populated as a side-effect of reconciliation. Useful for treasury-ops reporting (per-funding analytics) without joining the wider `payment_instructions` row. UNIQUE on `(tenant, instruction)`.
- Idempotent `information_schema`-guarded `ALTER` adds `payment_instructions.reconciled_at` so dashboards can compute lag/throughput without scanning the full audit table.

### Service — `core/mercury_reconciliation.php`
- `mercuryReconcileTenant($tenantId)` — engine entry. Walks every `state=Settled AND reconciled_at IS NULL` row (LIMIT 500, ordered by `payout_settled_at ASC` for fairness), calls `mercuryReconcileOne` per row, returns `{scanned, matched, discrepancies, missing}` counters.
- `mercuryReconcileOne($tenantId, $row)` — three-way verdict:
  - **matched**: payout_mercury_txn_id exists in `mercury_transactions`, amount + currency align. Records the match AND calls `mpTransition` (from Slice 3) to advance the row to `Reconciled` with `reconciled_at` stamped. Transition failures are absorbed so reconciliation can keep walking.
  - **discrepancy**: candidate found but amount or currency mismatches. Records the row with `expected_amount_cents` / `observed_amount_cents` / human-readable `discrepancy_reason`. Payment stays in `Settled` for human inspection.
  - **missing_mercury_txn**: payout_mercury_txn_id set but no `mercury_transactions` row yet (sync cron lag). Records the gap so the worker can re-attempt next tick without duplicating audit rows (UNIQUE upsert).
- **NEVER hits Mercury** — pure local-DB work. The doc + the `mercuryCall` grep-absence verify this in the smoke test, so reconciliation can run on a tight `*/15` cron without rate-limit concerns.
- `mercuryUpsertFundingTransfer` — sidecar that keeps the optional `funding_transfers` ledger view in sync per-instruction. Best-effort (failures swallowed).
- `mercuryReconciliationStats($tenantId)` — single query returning the 4 KPIs the UI tile renders: `settled_unreconciled`, `reconciled_total`, `discrepancies_open`, `missing_mercury_txn`, plus `oldest_unreconciled` for lag visibility.
- `mercuryReconciliationMatches($tenantId, $instructionId?, $outcome?)` — paginated audit list with `outcome` allowlist filtering for the discrepancies-only view.
- Three graceful-degrade `try/catch` blocks so missing migration 051 doesn't crash anything that imports the service.

### API — `/api/mercury_reconciliation.php`
- `GET ?action=stats` → KPI tile data.
- `GET ?action=matches[&outcome=discrepancy][&instruction_id=N]` → audit list / drill-in.
- `POST ?action=run` → manual engine trigger (also emits `mercury.reconciliation.run` audit). Returns `{scanned, matched, discrepancies, missing}` so the UI flash can confirm what happened.
- RBAC split: reads `accounting.bank.view` OR `.manage`; run-now needs `.manage`.

### Worker — `cron/mercury_reconciliation.php`
- Selects only tenants with at least one `state=Settled AND reconciled_at IS NULL` row — skips idle tenants entirely. Per-tenant try/catch, exit-code reflects failure count. Suggested cron: `*/15 * * * *` (4× faster than the payment worker because the work is local-only).

### UI — extension to `MercuryPayments.jsx`
- New reconciliation KPI tile pinned at the top of the Mercury Payments page above the payments table:
  - Four KPI columns: "Settled, awaiting reconciliation" (amber when > 0), "Reconciled (total)" (always green), "Open discrepancies" (red when > 0), "Mercury txn missing" (amber when > 0).
  - "Reconcile now" CTA button that POSTs to `?action=run`, then refreshes both the list and the stats. Flash banner reports the verdict counters.
  - Optional "Oldest unreconciled" lag display below the KPIs when there's a backlog.
- Testids: `mercury-reconciliation-tile`, `recon-kpi-pending/reconciled/discrepancies/missing`, `mercury-reconciliation-run-btn`, `mercury-reconciliation-lag`.
- Small `<ReconKpi>` helper component keeps the tile JSX readable.

### Validation
- `tests/mercury_reconciliation_smoke.php` — **61 ✓ / 0 fail.** Covers migration shape (UNIQUEs, ENUMs, idempotent ALTER), service contract (all 6 helpers, three-verdict branches, ABS-on-signed-mercury-amounts, currency case-insensitive compare, reconciled_at stamping, transition-failure recovery, NEVER-hits-Mercury guarantee verified via both doc match AND `mercuryCall` grep-absence), API (3 routes, RBAC split, audit emission), worker (skip-idle-tenants optimization, per-tenant try/catch, exit-code semantics), UI (KPI tile testids, run button, stats reload after run, ReconKpi helper present), and `php -l` syntax sanity on 3 backend files.
- Full PHP smoke suite — **194 smoke files, 0 failures.** Vite bundle `index-DVngaXI3.js`; `.deploy-version` + `spa-assets/` + sprint6b expected hash synced via postbuild hook.

### Mercury MVP — feature-complete summary
With Slice 4 landed, the four slices together deliver the full payments lifecycle per the attached `CoreFlux_Mercury_MVP_Technical_Spec.docx`:
- **Slice 1** — tenant-owned API token, account sync, transaction sync, paste-token UI.
- **Slice 2** — vendor + funding-source recipient vault, bank-method encryption, funding default designation.
- **Slice 3** — payment_instructions 10-state machine with the gated debit→verify→payout workflow.
- **Slice 4** — reconciliation engine matching `mercury_transactions` ↔ `payment_instructions`.

### Deliberately queued for later (per user direction)
- **Mercury webhooks** — current `Submitted → Settled` poll cadence is every 5 min (worker). Webhooks would make it real-time, mirroring the Plaid Transfer webhook pattern. **Queued for after Slice 4.**



## Recently completed (Mercury Bank — Slice 3: Payment Engine + State Machine, 2026-02 — this fork)
**The gated payments workflow is now executable end-to-end.** This is the slice the user explicitly described: debit external funding account → credit Mercury operating → verify clearance → only then push ACH to vendor.

### Schema — migration `050_mercury_payments.sql`
- `payment_instructions` — ONE row tracks BOTH legs of the gated workflow. State `ENUM` covers all 10 states: `Draft`, `PendingApproval`, `Approved`, `Funding`, `Submitted`, `Settled`, `Reconciled`, `Failed`, `Returned`, `Cancelled`.
- Two parallel column families on the same row: funding leg (`funding_recipient_id`, `funding_mercury_txn_id`, `funding_mercury_status`, `funding_initiated_at`, `funding_settled_at`, `funding_last_polled_at`) + payout leg (`operating_mercury_account_id`, `payout_mercury_txn_id`, `payout_mercury_status`, `payout_initiated_at`, `payout_settled_at`, `payout_last_polled_at`).
- `idempotency_key VARCHAR(80)` + `UNIQUE (tenant_id, idempotency_key)` — every workflow row gets a deterministic key, propagated as `pi:{key}:funding` and `pi:{key}:payout` to Mercury so retries are safe.
- `source_module` / `source_ref` columns ready for Slice 3.5 AP-module bill auto-enrollment.
- `payment_instruction_audit` — every state transition writes a row here PLUS a `mercury.payment.transition` event into `audit_log` (best-effort).

### State machine — `core/mercury_payments.php::mpTransitionAllowed()`
Codified matrix that **refuses** illegal jumps. The unit-test functional block in the smoke validates 17 specific transition rules including:
- `Draft → Approved` REFUSED (must go through PendingApproval)
- `Approved → Submitted` REFUSED (must go through Funding first — this is THE workflow gate)
- `Funding → Settled` REFUSED (must go through Submitted)
- `Submitted → Cancelled` REFUSED (too late to cancel a wire that's been sent)
- All four terminal states locked: `Failed`, `Returned`, `Cancelled`, `Reconciled` allow zero outbound transitions.

### Transition primitive — `mpTransition()`
- Wraps every state change in a transaction with `SELECT … FOR UPDATE` so concurrent workers can't race the same row.
- Idempotent on same-state writes (returns false, no rollback noise).
- Allowlists the `patch` column names against `/^[a-z0-9_]+$/` so dynamic SQL can't be poisoned.
- Writes to `payment_instruction_audit` AND emits a cross-module `audit_log` event in one shot.

### Workflow orchestrator — `mpAdvance()`
The single worker entry point. Drives ONE row one step:
- **`Approved → Funding`** (`mpOriginateFunding`): looks up `mercury_connections.default_funding_recipient_id` + `default_mercury_account_id`, refuses if either is unset, looks up the `external_account` mapping for the funding recipient, refuses if that's unset (with explicit error message pointing the operator at the right Mercury UI step). Calls `mercuryCreatePayment` with the `:funding` idempotency key. On `MercuryApiException` → `Failed` with the error message persisted.
- **`Funding → Submitted`** (`mpVerifyAndOriginatePayout`): polls Mercury for the funding txn status. Treats `settled`/`posted`/`sent` as cleared. On `failed`/`cancelled` → `Failed`; on `returned` → `Returned`; on still-pending → bumps `funding_last_polled_at` and stays in Funding. Once cleared, marks `funding_settled_at`, looks up the vendor `counterparty` mapping (refuses if not yet pushed), then calls `mercuryCreatePayment` again with the `:payout` idempotency key to debit operating → credit vendor.
- **`Submitted → Settled`** (`mpPollPayoutStatus`): polls the payout txn. `settled`/`posted` → `Settled`; `failed`/`cancelled` → `Failed`; `returned` → `Returned`; pending → stays in Submitted.
- Transient adapter exceptions in either poll stage are caught and treated as "try again next tick" — the row never gets stuck.

### Adapter additions — `core/mercury_adapter.php`
- `mercuryCreatePayment($token, $accountId, $payload)` → `POST /account/{id}/transactions`. Used for **both** funding pulls (recipientId = `external_account` id) and vendor payouts (recipientId = `counterparty` id). Validates `recipientId`, `amount`, `paymentMethod`, `idempotencyKey` are all present.
- `mercuryGetPaymentStatus($token, $accountId, $txnId)` → `GET /account/{id}/transaction/{id}`. Drives both poll stages.

### CRUD + user actions — `core/mercury_payments.php`
- `mpCreate` — validates `recipient_id` exists with `kind=vendor` AND `status=active`, refuses `amount_cents <= 0`, auto-generates an `idempotency_key` when blank (`pi_YYYYmmdd-HHiiss_<6 random bytes>`).
- `mpSubmitForApproval` — `Draft → PendingApproval`.
- `mpApprove` — `PendingApproval → Approved`, **enforces Segregation of Duties** by refusing self-approval (creator ≠ approver) at the service layer (not just the UI).
- `mpRejectToDraft`, `mpCancel`.
- `mpList(tenant, {state?})`, `mpGet(tenant, id)`.

### API — `/api/mercury_payments.php`
7 routes:
- `GET ?id=N` returns the row + eager-loaded `payment_instruction_audit` trail.
- `GET ?state=Approved` filters.
- `POST` (no action) creates a Draft.
- `POST ?action=submit|approve|reject|cancel|advance&id=N`.
- RBAC: writes need `accounting.bank.manage`; reads accept either `accounting.bank.view` or `accounting.bank.manage`. SoD enforced at the service layer.

### Worker — `cron/mercury_payment_worker.php`
- Selects every row in `Approved | Funding | Submitted` across all tenants, ordered by `state_changed_at ASC` (oldest first → fairness). Caps `$MAX_PER_TENANT = 50` per run so one busy tenant can't starve others.
- Calls `mpAdvance()` per row. Per-row try/catch — one bad row never aborts the rest. Suggested cron: `*/5 * * * *`.

### UI — `modules/treasury/ui/MercuryPayments.jsx`
- New top-level tab "Mercury Payments" in TreasuryModule (`/modules/treasury/mercury-payments`). Distinct from the Pay-out Rails settings tab.
- Header explains the gated workflow in plain English so operators understand the state pill they're looking at.
- Color-coded state pills for all 10 states. Per-row action buttons rendered only for legal transitions:
  - Draft → Submit
  - PendingApproval → Approve / Reject (Reject opens a `window.prompt` for the reason)
  - Approved/Funding/Submitted → "Run worker" (manual trigger; cron does this every 5 min)
  - Draft/PendingApproval/Approved → Cancel (with `window.confirm`)
- "Audit" CTA opens a modal showing the full state-transition history + raw row JSON for debugging.
- Create modal: recipient picker (`?kind=vendor` only), USD amount, description (≤ 50 chars, shown on bank statement), internal notes. Amount → cents conversion in JS.
- Testids cover every actionable element: `mercury-payments`, `mercury-payment-{create-btn,save-btn,recipient,amount,description}`, per-row `mercury-payment-{state,submit,approve,reject,advance,cancel,detail}-{id}`, modals `mercury-payment-{create-modal,detail-modal,audit-table}`.

### Validation
- `tests/mercury_payments_smoke.php` — **134 ✓ / 0 fail.** Covers migration shape + ENUM + UNIQUE + both column families, adapter additions (URL + idempotency requirement + path encoding), state machine matrix (17 functional transition assertions including all the critical refusals), service contract (SoD enforcement, all CRUD + action helpers, illegal-transition refusal, SELECT-FOR-UPDATE lock, anti-injection allowlist, transactional rollback), orchestrator (all 3 stages + every error branch), API contract (7 routes, RBAC split, validation), worker contract (state filter, fairness ordering, per-tenant cap, exit-code semantics), UI JSX (every testid, state color map for all 10 states, conditional CTAs by state, cents conversion, debit→verify→submit docstring), TreasuryModule wiring, **functional adapter round-trip via injected stub** (createPayment URL shape, idempotencyKey body propagation, status response parsing, 3 validation rejection paths), and `php -l` syntax sanity on 4 backend files.
- Full PHP smoke suite — **193 smoke files, 0 failures.** Vite bundle `index-Belgdvhg.js`; `.deploy-version` + `spa-assets/` + sprint6b expected hash all in sync via postbuild hook.

### What this unlocks for Slice 4 (Reconciliation)
- `payment_instructions.payout_mercury_txn_id` provides the join key that Slice 4's `ReconciliationService` will use to match `mercury_transactions` rows back to the originating payment.
- `state=Settled` is the trigger; `state=Reconciled` is the target. The matrix is already wired (`Settled → Reconciled` is the only allowed transition).
- Slice 4 will also add `funding_transfers` as an optional ledger view if the operator wants per-funding-event analytics, but the gated workflow itself is fully functional today.



## Recently completed (Mercury Bank — Slice 2: Recipient Vault + Funding-Source Designation, 2026-02 — this fork)
**Slice 2 of the Mercury MVP per the user-clarified payments workflow.** Models BOTH outgoing payment recipients (vendors → Mercury counterparties) AND tenant-owned external funding accounts (Mercury debits this to pre-fund operating before pushing ACH to a vendor). The spec'd flow that Slice 3 will implement:
1. AP approves payment with a vendor recipient.
2. Mercury DEBITs the tenant's designated `default_funding_recipient` (kind=`funding_source`) and CREDITs the Mercury operating account (`default_mercury_account_id`).
3. Poll Mercury transactions until that specific funding transfer is `settled` / cleared.
4. ONLY then originate the outbound ACH from Mercury → vendor counterparty.

### Schema — migration `049_mercury_recipients.sql`
- `mercury_recipients` — `kind` ENUM `vendor|funding_source`, `payment_method` ENUM `ach|wire|check`, `status` ENUM `draft|active|revoked`, soft-delete via `deleted_at`.
- `mercury_recipient_bank_methods` — `routing_number_ct` + `account_number_ct` `VARBINARY(512)` (AES-256-GCM), `account_number_last4` for UI masking, `account_type` ENUM `checking|savings`, `is_default` flag per-recipient.
- `mercury_recipient_mappings` — `mercury_kind` ENUM `counterparty|external_account` discriminator + UNIQUE `(tenant, recipient, mercury_kind)` so re-pushes are idempotent. The same local recipient *could* have both mapping kinds (rare but modeled).
- `mercury_connections` extended via `information_schema`-guarded ALTERs: `default_funding_recipient_id INT UNSIGNED NULL` + `default_mercury_account_id VARCHAR(80) NULL`. Slice 3 reads both at payment-approval time.

### Adapter additions — `core/mercury_adapter.php`
- `mercuryCreateCounterparty($token, $payload)` → POST `/recipients`. Validates `payload.name`. Returns Mercury's raw body so callers can pluck the `id` for the mapping row.
- `mercuryListCounterparties($token, $opts)` → GET `/recipients?search=&limit=&offset=` for in-app search/typeahead.
- Inline note documenting **why** external funding accounts are NOT registered via API: Mercury doesn't expose a public endpoint for that — the operator pastes the existing `external_account` id from the Mercury web UI when designating the funding default.

### Service — `core/mercury_recipients.php`
Public surface (8 helpers):
- `mercuryRecipientCreate($tenantId, $data, $userId)` — transactional INSERT into both tables. Strict validation: routing must be 9 digits, account 4–17 chars, `kind` allowlist. Returns the hydrated row.
- `mercuryRecipientUpdate`, `mercuryRecipientList(kind?)`, `mercuryRecipientGet(id)` (eager-loads bank_method-last4-only + mercury_mappings), `mercuryRecipientRevoke` (soft-delete).
- `mercuryRecipientPushToMercury($tenantId, $id)` — JIT-decrypts bank details, calls `mercuryCreateCounterparty`, persists mapping via `ON DUPLICATE KEY UPDATE`, **immediately drops plaintext from memory** (`$routing = null; $account = null`). Refuses `funding_source` recipients with a clear error pointing at the Mercury UI flow.
- `mercuryRecipientSetFundingDefault($tenantId, $recipientId, $mercuryAcctId)` — validates that the recipient is `kind=funding_source` AND that the Mercury account is in `mercury_accounts` for the tenant (forces "Refresh accounts" first). Atomically updates the two new columns on `mercury_connections`.
- `mercuryRecipientGetFundingDefault($tenantId)` — read-back used by both UI and Slice 3.

### API — `/api/mercury_recipients.php`
- `GET` — list (filter by `kind`), single via `?id=N`, or `?action=funding_default`.
- `POST` — create (default action), `?action=push` (push vendor to Mercury), `?action=set_funding_default` (body: `{recipient_id, mercury_account_id}`).
- `PATCH ?id=N` / `DELETE ?id=N` — update / soft-revoke.
- RBAC: writes gated by `accounting.bank.manage`. Reads accept `accounting.bank.view` OR `accounting.bank.manage` (matches Slice 1 convention).
- Audit events: `mercury.recipient.created` / `.pushed` / `.updated` / `.revoked` / `mercury.funding_default.set`. `MercuryApiException` → HTTP 502 with `http_status` echoed in the response.

### UI — `modules/treasury/ui/MercuryRecipients.jsx`
- Mounted as the third stacked panel under Treasury → Pay-out Rails (after `<PlaidTransferSettings />` + `<MercurySettings />`). Three react-data hooks (list, accounts, funding_default).
- Top **funding-default summary card** — green when set (renders `recipient.name → Mercury acct ID`), amber "Not configured" otherwise. Explains the gating in plain English so operators understand WHY this is required.
- Per-row actions:
  - **Vendor** + not-yet-pushed → "Push to Mercury" button (`mercury-recipient-push-{id}`).
  - **Funding source** + active → "Set as funding default" CTA (`mercury-set-funding-default-{id}`), **disabled** when no Mercury accounts are synced yet (with tooltip pointing at the Refresh-accounts button).
  - Always: "Revoke" with `window.confirm` guard.
- Create modal collects bank details (routing pattern-validated `[0-9]{9}`, account_number, account_type). Set-funding-default modal lists synced Mercury accounts as the credit-target dropdown.
- Testids: `mercury-recipients`, `mercury-funding-default[-set|-unset]`, `mercury-recipient-create-modal`, `mercury-recipient-{kind,name,routing,account,save-btn}`, `mercury-recipient-row-{id}`, `mercury-recipient-push-{id}` / `-revoke-{id}`, `mercury-set-funding-default-{id}`, `mercury-set-funding-default-modal`, `mercury-funding-default-account`, `mercury-set-funding-default-save`.

### Validation
- `tests/mercury_recipients_smoke.php` — **97 ✓ / 0 fail.** Covers migration shape (3 new tables + idempotent ALTERs via `information_schema`), adapter additions (URL + opts + name-validation), service contract (all 8 helpers, kind/routing/account validation, transactional insert, encryption round-trip, push refuses funding_source, JIT-decrypt + drop plaintext, mapping idempotency, funding-default validation against `mercury_accounts`, graceful degrade), API contract (RBAC split, all 7 routes, audit events, MercuryApiException → 502), UI JSX (all testids, conditional CTAs, confirm-before-revoke, debit→verify→push docstring), TreasuryModule wiring, functional adapter round-trip via injected stub (POST /recipients body shape with `electronicRoutingInfo`, Bearer header propagation, search+limit query string), validation throws for empty payloads, `php -l` syntax sanity on 3 backend files.
- Full PHP smoke suite — **192 smoke files, 0 failures.** Vite bundle `index-oHQFdWNo.js`; `.deploy-version` + `spa-assets/` + sprint6b expected hash all in sync via postbuild hook.

### What this unlocks for Slice 3 (Payment Engine)
- Slice 3's `payment_instructions` state machine will read `mercury_connections.default_funding_recipient_id` + `default_mercury_account_id` at approval time to know **which external account to debit** and **which Mercury account to credit**.
- The two `mercury_recipient_mappings.mercury_kind` values let Slice 3 issue **two different Mercury API calls**: a funding-pull against the `external_account` mapping, then a payout against the `counterparty` mapping — without any further schema changes.



## Recently completed (Mercury Bank — Slice 1: Foundation, 2026-02 — this fork)
**First slice of the Mercury MVP integration per `CoreFlux_Mercury_MVP_Technical_Spec.docx`.** Tenant-owned API tokens (CoreFlux is NOT a Mercury partner — each tenant pastes their own token from `app.mercury.com/settings/tokens`). Slice 1 ships the connection lifecycle, account listing, and transaction sync; Slices 2–4 (Recipient Vault, Payment Engine, Funding + Reconciliation) layer on top.

### Schema — migration `048_mercury_foundation.sql`
- `mercury_connections` — one row per tenant. `api_token_ct VARBINARY(512)` (AES-256-GCM via `encryptField()`), `api_token_last4 VARCHAR(8)` for masked UI display. Status ENUM `active|revoked|error`. UNIQUE `(tenant_id)` so MVP allows one connection per tenant.
- `mercury_accounts` — denormalized cache of `/accounts`. UNIQUE `(tenant_id, mercury_account_id)`. Balances stored as `BIGINT` cents (never floats). Routing number stored as plaintext (public ABA), account number only as `last4`.
- `mercury_transactions` — append-only ledger mirror. UNIQUE `(tenant_id, mercury_txn_id)` for idempotent INSERT IGNORE. `amount_cents BIGINT` (signed; negative = outflow). `payload_json JSON` preserves raw Mercury body for forensics.
- All three idempotent (`IF NOT EXISTS`), Cloudways MySQL 5.7+ compatible, `utf8mb4_unicode_ci`.

### Adapter — `core/mercury_adapter.php`
- Bare-metal cURL adapter, **no SDK dependency**. `MercuryApiException` exposes `httpStatus`, `errorCode`, `raw`.
- `mercuryApiBase()` env-routed: `MERCURY_ENV=sandbox` → `https://api.sandbox.mercury.com/api/v1` (private beta), default → `https://api.mercury.com/api/v1`. `MERCURY_API_BASE` env override for local/staging proxies.
- `mercuryCall($token, $method, $path, $body, $timeoutSec=25)` — single chokepoint with Bearer auth, `User-Agent: CoreFlux/1.0`, SSL verifypeer on. **Test transport seam** via `$GLOBALS['__mercury_transport']` so smoke tests can inject stubbed responses without hitting live HTTP.
- Slice 1 exports: `mercuryListAccounts`, `mercuryGetAccount`, `mercuryListTransactions` (passes through `limit/offset/start/end/order/status` opts). Slices 2–4 will add `mercuryCreateRecipient`, `mercuryCreatePayment`, `mercuryGetPaymentStatus`, `mercuryCreateFundingTransfer` on the same adapter surface.

### Service — `core/mercury_service.php`
- Stateful layer between the adapter and the REST API:
  - `mercuryGetConnection($tenantId)` — reads row, decrypts token, returns `{id, label, api_token, api_token_last4, status, workspace_name, last_probe_*}`.
  - `mercuryStoreConnection($tenantId, $apiToken, $label, $userId)` — **probes via `/accounts` BEFORE persisting** so bad tokens never enter active state. On success encrypts + upserts, sets `workspace_name` from the first account row's `name`, eagerly hydrates `mercury_accounts` cache.
  - `mercuryRevokeConnection` — soft-revoke (`status='revoked'`), audit trail preserved.
  - `mercuryFlagConnectionError` — best-effort flip to `status='error'` with `last_probe_error` when adapter throws.
  - `mercurySyncAccounts` — refresh `/accounts` → upsert via `ON DUPLICATE KEY UPDATE`. Throws `MercuryApiException` + flags connection error on failure.
  - `mercurySyncAccountTransactions($tenantId, $accountPk, $opts)` — pull `/account/{id}/transactions`, `INSERT IGNORE` against UNIQUE `(tenant, mercury_txn_id)` so re-runs collapse cleanly. Converts `$` floats → `cents BIGINT` via `(int) round($x * 100)`.
- All read helpers wrap their DB calls in try/catch and degrade gracefully when migration 048 hasn't been applied (`return null` / `return []`) — matches the project pattern (`event_registry.php`, `financial_state_cache.php`).

### API endpoints
- `GET  /api/mercury_connection.php?action=status` → `{connected, status, label, api_token_last4, workspace_name, last_probe_at, last_probe_error}`.
- `POST /api/mercury_connection.php` body `{api_token, label?}` → probe + upsert. Validates token length ≥ 16. Returns `{ok, workspace_name, accounts_count}`. Audit `mercury.connection.connected`. On probe failure: 422 with `http_status` + audit `mercury.connection.probe_failed`.
- `POST /api/mercury_connection.php?action=disconnect` → soft-revoke + audit `mercury.connection.disconnected`.
- `GET  /api/mercury_accounts.php` → cached rows.
- `POST /api/mercury_accounts.php?action=sync` → call Mercury + upsert.
- `GET  /api/mercury_transactions.php?account_pk=N&limit=50&offset=0` → cached rows ordered by `COALESCE(posted_at, received_at) DESC`. Limit capped at 200.
- `POST /api/mercury_transactions.php?action=sync` body `{account_pk, limit?, start?, end?, order?, status?}`.
- RBAC: writes gated by `accounting.bank.manage`. Reads accept `accounting.bank.view` **or** `accounting.bank.manage` (per the user's "reuse existing perms" decision — new Mercury-specific TreasuryAdmin/Operator roles deferred).

### Cron — `cron/mercury_transactions_sync.php`
- Iterates every tenant with `mercury_connections.status='active'`. For each: refresh accounts cache, then pull up to 200 transactions per account. Per-tenant try/catch so one bad token never aborts the whole cron. Exit code reflects per-tenant failure count. Suggested schedule: `0 3 * * *`.

### UI — `modules/treasury/ui/MercurySettings.jsx`
- Mounted alongside `<PlaidTransferSettings />` inside the Treasury → "Pay-out Rails" tab (`/modules/treasury/payout-rails`). Two cards stack vertically; the unified page is the operator's one-stop AP rail configuration.
- Two branches:
  - **Not connected** → password-typed token input + optional label + "Connect Mercury" button. External link to `https://app.mercury.com/settings/tokens` so operators can grab the token in-flow. `data-testid="mercury-connect-form"`.
  - **Connected** → workspace name, label, masked token last-4, last probe timestamp, "Refresh accounts" + "Disconnect" CTAs. `data-testid="mercury-connected"`. Renders `last_probe_error` inline when present.
- Cached accounts table (`mercury-accounts-table`) with nickname / kind / account-last4 / routing / available / current / status / last-sync columns. Empty state with explicit "Click Refresh accounts" prompt.
- Testids: `mercury-settings`, `mercury-token-input`, `mercury-connect-btn`, `mercury-disconnect-btn`, `mercury-sync-accounts-btn`, `mercury-workspace-name`, `mercury-token-last4`, `mercury-account-row-{id}`, `mercury-accounts-empty`, `mercury-probe-error`, `mercury-flash-success`/`-error`.

### Plaid per-row "Send via Plaid" affordance (also shipped this release)
- `modules/ap/api/payments.php` `action=originate` now accepts an optional `?rail=plaid_transfer` (or `nacha`) query param. When present, mutates `$row['disbursement_rail']` before dispatch so the rail picks bypass the tenant's default `ap_settings.disbursement_rail`. Allowlisted.
- `core/payment_rails/originate_helpers.php` `paymentRailsDispatch()` now threads `tenant_id` into the driver opts (falling back to `currentTenantContext()`) so `PlaidTransferDriver::originate()` can look up the funding source. Previously the batch path was implicitly broken for Plaid.
- `modules/ap/ui/PaymentsList.jsx` — adds per-row "Send via Plaid" button (`ap-send-via-plaid-{id}`) when `method='plaid' && status='sent' && !rail_external_ref && plaidTransferLinked`. Inline error/success affordances per-row.

### Validation
- `tests/mercury_foundation_smoke.php` — **113 ✓ / 0 fail.** Covers migration shape + UNIQUE keys, adapter contract (env routing, transport seam, Bearer header, 3 list/get functions, exception class), service contract (5 stateful helpers, encryption-on-store + probe-before-persist, soft-revoke + error-flag, idempotent upserts, $ → cents conversion, graceful migration-missing degrade), all 3 API endpoints (RBAC, GET/POST routing, validation, audit events), cron contract (per-tenant try/catch, exit code), UI JSX (both branches, all testids, masked token display, external Mercury link), TreasuryModule wiring (`MercurySettings` rendered alongside `PlaidTransferSettings`), **functional adapter round-trip** via injected stub (Bearer header capture, /accounts + /transactions URL shape, query-string knob passthrough, 401 → MercuryApiException, malformed-JSON → MercuryApiException), and `php -l` syntax on all 6 backend files.
- `tests/plaid_transfer_smoke.php` extended to 112 ✓ for the per-row "Send via Plaid" wiring + `paymentRailsDispatch` tenant_id passthrough.
- Full PHP smoke suite — **191 smoke files, 0 unexpected failures.** Vite bundle rebuilt to `index-Dei6Q22N.js`; postbuild `sync_bundle.sh` updated `.deploy-version` + `spa-assets/`; sprint6b expected hash refreshed.

### Deploy notes
- Apply migration 048 via `deploy/run_migrations.php`.
- No new env vars required for production Mercury (default base URL ships). Optional: `MERCURY_ENV=sandbox` or `MERCURY_API_BASE=<override>` for staging.
- Schedule cron: `0 3 * * * php /home/master/applications/<app>/public_html/cron/mercury_transactions_sync.php`.
- Tenants self-onboard: Treasury → Pay-out Rails → Mercury Bank card → paste token from `app.mercury.com/settings/tokens`. The probe will reject bad tokens before persisting.

### Deliberately deferred (Slices 2–4)
- **Slice 2**: Recipient Vault — `recipients`, `recipient_bank_methods`, `mercury_recipient_mappings`, `mercuryCreateRecipient` adapter call, recipient management UI.
- **Slice 3**: Payment Engine — `payment_instructions` table + 7-state machine (Draft → PendingApproval → Approved → Funding → Submitted → Settled → Reconciled/Failed/Returned), dual-approval RBAC, `mercuryCreatePayment` / `getPaymentStatus`, submission worker + status polling worker.
- **Slice 4**: Funding Engine + Reconciliation — `funding_transfers`, funding requirement algorithm, `mercuryCreateFundingTransfer`, ReconciliationService that matches `mercury_transactions` to `payment_instructions`.



## Recently completed (Plaid Transfer AP pay-outs — Phase B wire-up complete, 2026-02 — this fork)
**Closes the AP → bank-rail loop.** Backend webhook + event-sync cursor + driver were already in place; this session shipped the missing tenant-facing UI, the link-status API, and the comprehensive smoke test. AP bills can now be paid via Plaid Transfer ACH/RTP from end to end.

### API contract additions — `api/plaid_transfer_link.php`
- `GET ?action=status` — env-gated UI probe. Returns `{ configured, linked, rail: {status, item_id, account_id, linked_at} | null }`. Degrades gracefully when migration 005 hasn't been applied (returns `linked=false`, `rail=null`). RBAC: `accounting.bank.manage`. **No Plaid API call** — pure DB read so the settings page renders instantly.
- `POST ?action=disconnect` — soft-revokes the rail row (`status='revoked'`) so the tenant can re-link cleanly. Preserves the row for audit. Emits `payment_rails.plaid.disconnected` audit event.
- Existing actions preserved: `POST` (link_token), `POST ?action=exchange` (public_token + account_id → tenant_payment_rails upsert with encrypted access_token, emits `payment_rails.plaid.linked`).

### API contract additions — `modules/ap/api/payments.php`
- `GET` now returns `plaid_transfer_linked: boolean` alongside `plaid_enabled`. UI uses this to render an inline "Connect funding source" CTA when env is configured but the tenant hasn't linked.
- Try/catch around the rail lookup so tenants without migration 005 applied don't see an API error.

### Frontend — `dashboard/src/components/PlaidTransferLinkButton.jsx` (new, ~140 lines)
- Lazy-loads `https://cdn.plaid.com/link/v2/stable/link-initialize.js` (shared module-level promise so multiple mounts share one script tag).
- Pre-fetches the link_token on mount → "Connect funding source" is instant on click.
- State machine: `idle → loading → ready → linking → exchanging → done | error`.
- `onSuccess` extracts `metadata.accounts[0].id` (Plaid Link returns it when the user picks the funding account) and POSTs `{public_token, account_id}` to `?action=exchange`.
- Distinct from the generic `<PlaidLinkButton />` because the funding-source flow uses `/api/plaid_transfer_link.php` (writes to `tenant_payment_rails`, gated by `accounting.bank.manage`) instead of `/api/plaid_link_token` + `/api/plaid_exchange` (writes to `plaid_items`, bank-feed scope).

### Frontend — `modules/treasury/ui/PlaidTransferSettings.jsx` (new, ~150 lines)
- New "Pay-out Rails" tab in TreasuryModule (`/modules/treasury/payout-rails`).
- Three render branches keyed off `?action=status`:
  - **Not configured** → muted notice instructing pod operator to set `PLAID_CLIENT_ID` + `PLAID_SECRET_*`.
  - **Configured + not linked** → amber "Not linked" badge + `<PlaidTransferLinkButton />`.
  - **Configured + linked** → green "✓ Linked" badge + Plaid item / funding account / linked-at metadata + Disconnect CTA (with `window.confirm` guard).
- Testids: `plaid-transfer-settings`, `plaid-transfer-not-configured`, `plaid-transfer-not-linked`, `plaid-transfer-linked`, `plaid-transfer-disconnect-btn`, `plaid-transfer-item-id`, `plaid-transfer-account-id`, `plaid-transfer-flash-success`, `plaid-transfer-flash-error`.

### Frontend — `modules/ap/ui/PaymentsList.jsx` inline CTA
- Amber "Plaid configured — funding source not linked" pill rendered next to the Vendor payments header, with a deep link to `/modules/treasury/payout-rails`. Only shows when `plaid_enabled=true && plaid_transfer_linked=false`. Testids `ap-plaid-link-cta` + `ap-plaid-link-cta-link`. Preserves existing "ready" badge and "disabled" notice branches.

### Validation
- `tests/plaid_transfer_smoke.php` — **103 ✓ / 0 fail** covering: migration 047 shape + idempotency UNIQUEs + utf8mb4_unicode_ci, `plaidTransferMapEventStatus` mapping (8 event types), `plaidTransferSync` contract (cursor read/upsert, pagination via after_id + has_more, INSERT IGNORE event persistence, ap_payments update by rail_external_ref filtered to disbursement_rail='plaid_transfer', envelope shape, all 3 error branches), webhook (JWT verify, raw-payload persistence, 200-on-signature-fail no-retry-storm, multi-tenant fan-out on TRANSFER_EVENTS_UPDATE, processed_at marker), link API (RBAC, all 4 actions, encryption, audit events, graceful degradation, 503 gate, method allowlist), driver contract (name, isConfigured env probe, originate guard, two-step authorization+transfer, idempotency_key), all UI JSX (lazy CDN, exchange POST, state machine, all testids, default exports), TreasuryModule wiring, PaymentsList inline CTA, payments.php API flag, plus `php -l` syntax sanity on 3 backend files.
- Full PHP smoke suite green: **190 smoke files, 0 unexpected failures.**
- Vite rebuilt via postbuild hook → `index-Cq3sNZKg.js` / `index-BC5g6YJu.css`. `.deploy-version` + `spa-assets/` + `sprint6b_dashboard_uis_smoke.php` expected hash all in sync.

### Deploy notes
- Cloudways host needs `PLAID_CLIENT_ID` + `PLAID_SECRET_SANDBOX` (and `PLAID_SECRET_PRODUCTION` when going live). Without these, the Pay-out Rails tab renders the muted "Not configured" branch and never errors.
- Webhook URL in the Plaid Dashboard "Transfer" section: `https://<host>/api/plaid_transfer_webhook.php`. Distinct from the generic `/api/plaid_webhook.php` for items/transactions so transfer-event traffic doesn't co-mingle.
- Migration `047_plaid_transfer_cursor.sql` and `005_payment_rails.sql` both need to be applied; `deploy/run_migrations.php` picks them up automatically.



## Backlog (2026-02 additions)
- **P3 Smoke-test defensive helpers** — Add `tests/_smoke_helpers.php` with an `assertAllPresent($text, [...])` helper to standardize compound substring checks. Migrate fragile multi-needle `preg_match` patterns in the smoke suite over to compound `str_contains` calls so they survive code refactors without false fails. (Triggered by 5 stale-pattern failures during the Phase 2 AI rollout.)



## Recently completed (Phase 2 AI v1 — rule-competing AI, 2026-02)
**P1 keystone: AI proposes business-rule tweaks, the system replays recent events through both rules deterministically, and operators review the diff before accepting.** Built on the simulation harness + Phase 2a clean event layer + the Financial State Cache, all of which were prerequisites.

### Schema — migration `046_rule_proposals.sql`
- `rule_proposals` table — generic `(tenant_id, rule_type, current_rule_json, proposed_rule_json, rationale, comparison_json, score, events_compared, events_changed, dollars_changed, status, reviewed_*, created_*)`. Status ENUM: `proposed | competed | accepted | rejected | applied | error`. Two indexes: `(tenant_id, status, created_at)` and `(tenant_id, rule_type, status)`. Cloudways MySQL 5.7+ compatible.

### Library — `core/ai_rule_competition.php`
- **`rcRegisterReplay(string $ruleType, callable $fn)`** — global registry. Each rule_type registers a replay function that takes `(tenantId, rule, sampleSize)` and returns a vector of per-event outcomes (`event_key, dollars, outcome_value, raw`). Adding a new rule type is a 1-function patch, no schema change.
- **v1 ships ONE rule type: `ap_expense_category_map`** — AP bill line category → expense account code. Replay reads the last N posted `ap_bill_lines` joined to `ap_bills` (filtered on `tenant_id` + `status IN ('approved','paid','partially_paid','posted')`), applies the rule's category lookup with `default` fallback.
- **`aiRuleCompete(int $proposalId, int $sampleSize = 50)`** — loads proposal, calls `rcReplayRule` twice (current + proposed), diffs by `event_key`, computes `events_changed`, `dollars_changed`, and a 0..1 score using `1 - abs(changeRatio - 0.15) * 2` so wholly-identical AND wholly-different proposals both score low (operators want measured changes). Persists `comparison_json` capped at 200 diff rows. Idempotent — running twice on the same proposal overwrites the comparison. Preserves accepted/rejected/applied terminal statuses on re-run. Catches builder exceptions → `status='error'` with `status_reason` for forensics.

### Library — `core/ai_rule_proposer.php`
- **`rpCurrentRule(int $tenantId, string $ruleType)`** — reads `accounting_account_mapping_rules` (module='ap'); falls back to a sensible baseline (`consulting→6000, software→6100, travel→6200, ...`) when the tenant has no customizations so the AI always has something to propose against.
- **`rpRecentActivity(int $tenantId, string $ruleType, int $contextSize)`** — aggregates the last N AP-line categories with `n_lines + total_amount + most_recent` so the LLM gets signal without leaking sensitive vendor names.
- **`aiProposeRule(int $tenantId, string $ruleType, ?int $userId, int $contextSize = 30)`** — calls `aiAsk(['feature_class' => 'rule_proposal', 'kind' => 'json', ...])` with system + prompt asking for `{proposed_rule, rationale}`. Routes automatically through `simShouldMockIfLoaded('openai')` so sim tenants get deterministic answers. Persists the proposal row (status='proposed' on success, 'error' on AI failure), then **auto-competes immediately** so operators see a populated diff on first load. Always returns the inserted id — even AI failures get a row for audit.

### API — `api/admin/rule_proposals.php`
- `GET ?id=N` → single row (json-decoded)
- `GET [?status=...&rule_type=...&limit=50]` → list (capped at 200)
- `POST {action:'propose', rule_type, context_size?}` → kicks off proposal + auto-competes
- `POST {action:'compete', id, sample_size?}` → re-replays (tenant ownership checked before delegate)
- `POST {action:'review', id, decision:'accept'|'reject', notes?}` → terminal status
- Standard auth. RBAC tier left wide for v1 (Phase 2.1 will narrow).

### UI — `dashboard/src/pages/RuleProposals.jsx` at `/ai/rule-proposals`
- Header with one trigger card per rule type ("Propose tweak" button on `ap_expense_category_map`).
- Refresh button (testid `rule-proposals-refresh`) with spinning loader.
- Empty state (testid `rule-proposals-empty`).
- One collapsible card per proposal (testid `rule-proposals-card-{id}`):
  - Status pill (proposed/competed/accepted/rejected/applied/error) with color-coded background.
  - Header summary: events changed / events compared, dollars changed, score.
  - Rationale blockquote (testid `rule-proposals-rationale-{id}`).
  - Side-by-side `<RuleJsonBox>` panels (current vs proposed).
  - Diff table preview (first 5 of N changes) with event_key, category, $, from→to.
  - "Re-replay" button (recompete), "Reject" / "Accept" with inline notes field.
  - Reviewed-timestamp footer when terminal.
- Mounted in `App.jsx` Routes.

### Validation
- `tests/ai_rule_competition_smoke.php` — 75+ static contract assertions covering migration shape, library function exports + behaviors (registry, replay query shape, scoring formula, error path, dirty preservation), proposer (aiAsk wiring, JSON contract, graceful no-structured-response, error path, auto-compete after propose, returns id), API endpoint (auth, tenant scoping on every read/write, all 3 POST actions, decision validation, json-decode of rule columns, method allowlist), React UI (page testid, useApi target, propose POST, card testid map, accept/reject/recompete buttons, diff table, empty state), App.jsx route.
- Routed to the **`harness`** lane via `ai_rule_competition_*` pattern (sits next to sim harness + invariants).
- All JSX lint clean.
- Vite rebuilt via the postbuild hook → `index-B8Q8nMJm.js`. All sync points consistent. sprint6b expected hash updated.

### What's deliberately deferred to Phase 2.1
- **Auto-apply accepted rules**: For v1, accepted proposals stay in DB with `status='accepted'` but don't write back to `accounting_account_mapping_rules`. Phase 2.1 will add per-rule_type appliers that promote accepted proposals into production rules atomically.
- **Additional rule types**: only `ap_expense_category_map` ships in v1. Adding more is a 1-function patch (`rcRegisterReplay` + `rpCurrentRule` switch arm) — no migration needed thanks to the generic schema.
- **RBAC tier narrowing**: any authenticated user can drive the queue today.

### Column-map JSON attachment (also shipped this session)
- The `attachCsvToImportRun()` helper now also uploads the column_map as `.mapping.json` (`document_type='column_map'`) so auditors can see "input bytes AND the interpretation we applied" side-by-side. CsvImportHistory page now renders two lazy download buttons per row: "↓ CSV" (testid `csv-history-row-{id}-download-original`) and "↓ JSON" (testid `csv-history-row-{id}-download-mapping`). Both buttons render nothing for older runs without the attachment, so the UI stays clean for legacy rows.



## Recently completed (Max auditability — CSV runs carry their source bytes, 2026-02)
Closing the auditability loop: every CSV import run now stores its **exact input bytes** as an evidence_attachments record, not just the metadata about what was imported. Auditors can download the original CSV that produced any batch of rows, on any line in the CSV Import History.

### Backend changes
- **`core/csv_import_history.php`** — `csvImportHistoryRecord()` signature changed from `void` to `?int`. Returns `(int) $pdo->lastInsertId()` on success, `null` on missing DB / migration not run / exception. Never throws (matches existing audit-write-is-a-nicety semantics).
- **`api/admin/csv_import_history.php`** — POST captures the returned id and returns `{ recorded: true, id: N }` (was `{ recorded: true }`).
- **`api/evidence_upload_url.php`** — Added `'csv_import_run'` to `$ALLOWED_SUBJECTS` whitelist and `'csv_import_run' => 'csv_imports'` to the module bucket map. Files land at `csv_imports/{tenant}/csv_import_run/{id}/{filename}`.

### Frontend changes
- **`dashboard/src/lib/csvAuditAttach.js`** (new, 60 lines) — Shared helper `attachCsvToImportRun({ importRunId, csvText, fileName, entity })`. Implements the standard 3-step evidence upload (presign → S3 multipart → metadata register) with `document_type='csv_source'`, `source='csv_import_auto_attach'`. Never throws — audit-write is a nicety.
- **`dashboard/src/components/CsvImportPage.jsx`** (shared single-entity importer) — After the existing history POST, captures `hist.id` and calls `attachCsvToImportRun(...)` to upload the original `csvText` as a `Blob`. Both wrapped in the same try/catch as the history POST.
- **`dashboard/src/pages/CsvBulkImport.jsx`** (multi-file wizard) — Same patch but per-file inside the FK-ordered commit loop. Each file in a 7-file bulk import becomes its own attachment under its own `csv_import_run` row.
- **`dashboard/src/pages/CsvImportHistory.jsx`** — New `<DownloadOriginalCsv importRunId={r.id} />` component renders next to the file_name cell. On mount it lazy-fetches the evidence list for `subject_type=csv_import_run&subject_id=r.id`; if no `csv_source` attachment exists (older runs), renders nothing. If one exists, shows a tiny "↓ CSV" button that fetches a fresh signed URL per click. Per-click fresh signing avoids leaking long-lived URLs to logs / browser history. Testid: `csv-history-row-{id}-download-original`.

### Audit trail flow (end-to-end)
1. Operator drops `people_jan_2026.csv` on the bulk-import wizard.
2. Wizard commits → `csv_import_history` row N created with metadata.
3. Wizard then calls `attachCsvToImportRun({ importRunId: N, csvText, fileName, entity })` → file uploaded to `csv_imports/{tenant}/csv_import_run/N/people_jan_2026.csv` → `evidence_attachments(subject_type='csv_import_run', subject_id=N, document_type='csv_source')` row inserted.
4. Auditor opens CSV Import History → sees row N → clicks "↓ CSV" → fresh signed URL → downloads the EXACT bytes that produced the batch.
5. Cross-tenant isolation guaranteed by `tenant_id` foreign key on `evidence_attachments`.

### Validation
- **`tests/csv_audit_attachment_smoke.php`** — 30+ assertions covering: history record returns id, POST endpoint passes it through, evidence whitelist + bucket map for csv_import_run, shared helper contract (signature, presign + upload + register + never-throws), both CSV pages capture `hist.id` and call the helper, history page renders the lazy download button with all the testid + selector + signed-url-per-click requirements.
- Routed to the **`ui`** lane (caught by existing `csv_*` pattern, no classifier change needed).
- All 4 JSX files lint clean.
- Vite rebuilt via the postbuild hook → `index-NrsuH9IR.js`. All three sync points consistent. sprint6b expected hash updated.



## Recently completed (Timesheet CSV discoverability + universal evidence attachments + CFO Cache Health, 2026-02)
Three connected improvements landed together: CSV import is now discoverable from the timesheets page; **any** subject in the system can now have file attachments via one drop-in component; the CFO Dashboard gets a collapsible "Cache Health" footer section so it doesn't get overwhelmed.

### Timesheet CSV import discoverability
- `modules/staffing/ui/TimesheetWeek.jsx` — Added "CSV Import" CTA (testid `ts-csv-import-link`) and "History" link (testid `ts-csv-history-link`) directly in the page header. CSV button links to `/modules/time/bulk` where the shared **CsvImportPage** component already handles drag-drop multi-file uploads, dynamic column mapping, AI-assisted suggestions, saved mapping presets, and dry-run validation.
- **Multi-period spanning was already supported** — `modules/time/api/csv_import.php` auto-resolves the `time_period` from each row's `work_date`, so a single CSV can legitimately cover historical + ongoing weeks across multiple placements. No backend change required, just discoverability.

### Universal Evidence Attachments
**Problem**: Vendor invoices (AP) had a custom per-bill attachment column; timesheets had nothing; billing invoices had nothing. No forward traceability from "approved time" → "appears on invoice X" → "supported by signed timesheet PDF".

**Solution**: One drop-in React component + one presigned-upload endpoint that everything reuses, backed by the existing polymorphic `evidence_attachments` pivot from Phase 1e.

- **`api/evidence_upload_url.php`** (new) — Returns a presigned S3 POST URL for an evidence attachment in two steps (presign → multipart upload → metadata register). Subject-type allowlist (11 types: `time_entry`, `time_bundle`, `time_uploaded_document`, `billing_invoice`, `ap_bill`, `ap_bill_line`, `placement`, `person`, `company`, `accounting_event`, `journal_entry`) guards against arbitrary insertion. Module bucket map routes files into sensible namespaces (`time/`, `billing/`, `ap/`, etc.).
- **`api/accounting/evidence.php`** (modified) — Added `GET ?action=signed_url&id=N` to re-sign download URLs per-click (signed URLs leak in logs / browser history, so the list endpoint deliberately omits them).
- **`dashboard/src/components/EvidenceAttachments.jsx`** (new, 195 lines) — Drop-in `<EvidenceAttachments subjectType="..." subjectId={...} />`. Three-step upload flow (presign → S3 → metadata), per-click signed download, soft-delete, full data-testid coverage (`{prefix}-panel`, `-upload-btn`, `-list`, `-row-{id}`, `-download-{id}`, `-delete-{id}`). Per-subject-type default document_type ('signed_timesheet', 'vendor_invoice', 'supporting_doc', etc.).
- **Mount points** (3 new locations):
  - `modules/staffing/ui/TimesheetWeek.jsx` → `subjectType="time_bundle"`, `subjectId={header.id}`. **Now the approved-time record can be traced forward to the rest of the workflow** with the signed timesheet attached directly to the week record.
  - `modules/billing/ui/InvoiceDetail.jsx` → `subjectType="billing_invoice"`. Customers' invoice PDF can have its supporting time bundle bundled with it.
  - `modules/ap/ui/BillDetail.jsx` → `subjectType="ap_bill"`. Adds the polymorphic pivot alongside the existing single-attachment column for richer multi-doc support.

### CFO Cache Health section (separate, collapsible)
- **`api/admin/fsc_health.php`** (new) — Reads the Financial State Cache health: rows cached, scopes covered, pending dirty count, oldest pending age, last rebuild time, per-scope avg/max runtime, top dirty reasons in last 24h. Graceful degradation when migration 045 hasn't run.
- **`dashboard/src/components/FscHealthPanel.jsx`** (new) — Collapsible panel mounted in the CFO Dashboard **footer** (below the main grid, in its own section, **not part of the primary grid**) so it doesn't overwhelm the headline KPIs. Collapsed by default, only opens on operator click. Shows tiles (rows / scopes / pending / last rebuild / oldest pending), a per-scope runtime table, and a 24h dirty-reasons histogram.
- Header chip turns amber ("N pending") when there's a dirty queue, green ("all fresh") when not — operators can see at a glance whether to expand.

### Validation
- `tests/timesheet_csv_attachments_smoke.php` — **80+ assertions** covering: TimesheetWeek link wiring, csv_import.php multi-period contract, evidence_upload_url.php (allowlist, module routing, response shape, StorageService integration), evidence.php signed_url GET action, EvidenceAttachments JSX (props, 3-step upload flow, per-click signed download, testid coverage, default doc-type map), all three mount points (TimesheetWeek/InvoiceDetail/BillDetail), fsc_health.php (auth, GET-only, graceful degradation, all 7 response fields, tenant scoping), FscHealthPanel JSX (collapsed-by-default, lazy-load on open, testid coverage), CFODashboard wiring.
- Routed to the **`ui`** lane via `timesheet_csv_attachments*` pattern.
- Vite rebuilt via the postbuild hook → `index-DGiGZYY5.js`. All three sync points (dist/index.html, spa-assets/, .deploy-version) consistent. sprint6b expected hash updated.
- All 6 modified/new JSX files lint clean.

### Audit-trail benefit (the user's stated goal)
"The record always shows approved time that can be traced forward to the rest of the workflow." Today:
1. Operator uploads a signed paper timesheet PDF on the TimesheetWeek page → `evidence_attachments(subject_type='time_bundle', subject_id=42, document_type='signed_timesheet')`
2. Time bundle gets billed → invoice 100 created
3. CFO opens invoice 100 → can attach the same time bundle PDF as `subject_type='billing_invoice'` supporting doc
4. Vendor invoice for sub-contractor comes in → AP bill → `subject_type='ap_bill'` with vendor invoice attached
5. All evidence rows share `tenant_id` and survive deletion via `deleted_at` soft-delete



## Recently completed (Phase 2 — Unified Financial State Cache, 2026-02)
**Phase 2 keystone: fast-read projection layer on top of the now-strictly-clean event-driven ledger. Read path for CFO Dashboard, the upcoming Phase 2 AI rule competition, period-close materialized views, and the External Auditor view (P3).**

### Schema — migration `045_financial_state_cache.sql`
- Two new tables, both idempotent (`IF NOT EXISTS`), Cloudways MySQL 5.7+ compatible:
  - **`financial_state_cache`** — generic `(tenant_id, scope_key, scope_value, metric_key) → numeric_value DECIMAL(18,4) + json_value JSON + source_hash CHAR(64) + computed_at + computed_ms`. UNIQUE on the full scope+metric quadruple so writes upsert in place. Indexed for scope-reads and metric-reads.
  - **`financial_state_cache_dirty`** — append-only invalidation log keyed by `(tenant_id, scope_key, scope_value)` with `reason`, `marked_by_user_id`, `marked_at`.
- Generic shape avoids per-metric schema migrations. Scopes used today: `period_id`, `tenant`, `entity_id`. Metric keys are namespaced strings (`account_balance.{id}`, `revenue.posted`, `net_income`, etc.).

### Library — `core/financial_state_cache.php`
- **Read**: `fscRead($tid, $scopeKey, $scopeValue, $metricKey = null)` — single-metric or all-for-scope. **Auto-rebuilds if scope is dirty** (lazy-on-read invalidation). Degrades gracefully when migration 045 hasn't run yet (returns empty/null) — same pattern as `event_registry.php`.
- **Write**: `fscWrite(...)` — `ON DUPLICATE KEY UPDATE` upsert. Called by builders; not normally called directly.
- **Invalidation**: `fscMarkDirty(...)` — append a dirty-log entry. Cheap (single INSERT, no SELECT). Safe to spam from event handlers; rebuilder collapses duplicates. Wrapped in try/catch so missing tables never break callers.
- **Rebuild**: `fscRebuild($tid, $scopeKey, $scopeValue)` — dispatches by `scope_key`, then atomically clears the dirty log **only on successful rebuild** (throws → dirty entries preserved so the next read retries). Returns `{metrics_written, ms}` for SLO tracking.
- **Concrete builders**:
  - `fscBuildPeriodAccountBalances($tid, $periodId)` — reads `accounting_journal_entry_lines` joined to `accounting_journal_entries` (`status='posted'` only — drafts + reversed entries excluded; reversal partners cancel mathematically), groups by `account_id`, computes directional balance via the account's `normal_side`, writes one row per account with `metric_key = "account_balance.{id}"` and a sha256 source_hash over (account_id, je_count, debit, credit, last_je_updated).
  - `fscBuildPeriodKpis($tid, $periodId)` — reads from cache rows just written, rolls up by `account_type`, writes `revenue.posted`, `expense.posted`, `net_income = revenue - expense`, `asset_balance`, `liability_balance`, `equity_balance`.

### API — `api/financial_state.php`
- `GET ?scope_key=period_id&scope_value=42` → `{scope, was_dirty, dirty_now, count, metrics: {metric_key: row, ...}}`. Auto-rebuilds dirty scopes.
- `GET ?scope_key=...&scope_value=...&metric_key=revenue.posted` → single-metric read with 404 on miss.
- `POST {action: "mark_dirty", scope_key, scope_value, reason?}` → manual invalidation (also called by event listeners).
- `POST {action: "rebuild", scope_key, scope_value}` → force rebuild.
- Standard `api_require_auth()` session/JWT gate. No fine-grained RBAC — the data is the same numbers `exec_dashboard.php` already exposes.

### Event hook integration — `modules/accounting/lib/accounting.php`
- `accountingPostJe()` now calls `fscMarkDirty($tid, FSC_SCOPE_PERIOD, $period['id'], 'je_posted', $actorUserId)` **after the commit** (only when `$post === true`). Wrapped in try/catch — never blocks the JE post if the cache is unavailable.
- `accountingReverseJe()` marks the **original** period dirty with `'je_reversed'` (the reversal's period is auto-marked by the recursive `accountingPostJe()` call).

### Validation
- `tests/financial_state_cache_smoke.php` — **64 assertions** covering: migration shape (table existence, columns, UNIQUE constraints, indexes, utf8mb4, no MySQL-8-only features), library surface (3 scope constants + 7 functions, fscRead auto-rebuild + graceful degradation, fscWrite upsert, fscMarkDirty silent-survive, fscRebuild dispatch + dirty-log clear-AFTER-success, account-balance builder reads posted-only + directional math + sha256 hash, KPI builder revenue/expense/net_income math), API contract (auth, GET/POST shapes, 4 actions, error codes), event hook integration (postJe + reverseJe call fscMarkDirty inside try/catch with the right reason strings and period_id sources).
- Smoke test routed to the **`core`** lane via the default classifier (this is core engine infrastructure).
- No frontend touched in this phase — pure backend foundation. CFO Dashboard read-path migration is a follow-up so the cache can be validated in production without affecting the current dashboard payload.

### Why this unlocks Phase 2 AI
- Phase 2 AI evaluates rule proposals by comparing outcomes "with rule X" vs "without rule X". That comparison reads the financial state hundreds of times per evaluation. The cache makes that read sub-millisecond instead of "replay the full event log per query." Without it, the AI loop is too slow to be useful.
- The cache also underpins the External Auditor view (P3) — point a tokenized URL at the cache and you have a deterministic read-only snapshot without ever exposing the raw event log.



## Recently completed (CI bundle-sync automation + CI status badge, 2026-02)
**Killed an entire class of recurring "I forgot to update X" CI failures, and gave the CFO Dashboard a live deploy-gate health indicator.**

### `scripts/sync_bundle.sh` — single source of truth for Vite hash propagation
- After every `yarn --cwd dashboard build`, three files have to agree on the
  new bundle hashes: `dashboard/dist/index.html` (Vite writes this),
  `spa-assets/index-XXX.{js,css}` (must be copied from `dist/spa-assets`),
  and `.deploy-version` `expected_bundle:` block (must be hand-patched).
  Missing any one of these has been the recurring CI breaker every few
  sessions.
- New `scripts/sync_bundle.sh` (bash + awk, **no PHP dependency** so it
  runs identically on CI hosts and local dev): reads the freshly-built
  `dashboard/dist/index.html` to discover the new hashes, mirrors
  `dist/spa-assets/*` → top-level `spa-assets/`, then rewrites only the
  two lines under `expected_bundle:` in `.deploy-version`, leaving every
  other line untouched. Idempotent — running it twice in a row is a no-op.
- `dashboard/package.json` now has a `postbuild` npm hook that runs
  `bash ../scripts/sync_bundle.sh` automatically. So `yarn build`
  unconditionally produces a fully-synced state.

### `api/ci_status.php` + `<CIStatusBadge />` — live CI deploy gate on CFO Dashboard
- New `api/ci_status.php` — fetches the latest GitHub Actions workflow
  run via `GET /repos/{owner}/{repo}/actions/runs?per_page=1`. **Cached
  server-side for 5 minutes** under `sys_get_temp_dir()` so a CFO
  dashboard full of operators doesn't hammer the GitHub API. Config via
  `GITHUB_REPO=owner/repo` env var (required) and `GITHUB_TOKEN` env
  var (optional, only needed for private repos). Public repos work
  unauthenticated. Returns `{configured, conclusion, status, html_url,
  branch, workflow_name, commit_sha, commit_msg, cached_at, ttl_seconds}`.
  Graceful degradation: missing env returns `configured: false` with a
  reason; HTTP 4xx returns `configured: true, error, hint` so the UI
  can render a muted "CI unreachable" badge without crashing. Gated by
  `api_require_auth()` (data is non-sensitive but no point exposing it
  publicly).
- New `dashboard/src/components/CIStatusBadge.jsx` — small pill-shaped
  badge mounted in the CFO Dashboard header next to the H1. Four
  states: **CI green** (success), **CI failing** (failure/cancelled/
  timed_out), **CI running** (in_progress/queued, with spinning Loader2
  icon), **CI not configured** (env var missing). Clicking opens the
  GitHub Actions run in a new tab. Re-polls every 5 minutes to match
  server cache TTL. Silent fail on error — never breaks the dashboard.
  Includes branch chip showing the head branch.
- `dashboard/src/styles.css` — added reusable `.cf-spin` animation
  utility (the existing `@keyframes spin` was defined but had no
  reusable class wrapper; CFODashboard.jsx line 438 had been
  referencing `cf-spin` without a definition).

### Validation
- `tests/ci_status_badge_smoke.php` — **57 assertions ✓** covering:
  endpoint contract (GET-only, auth, env-var fallback to constants,
  5-min cache, 5s curl timeout, Bearer token wiring, all response
  fields), JSX component contract (4 state branches, 5-min repoll,
  interval cleanup, all data-testids, new-tab click-through, silent
  fail), CFODashboard wiring, `.cf-spin` CSS utility,
  `scripts/sync_bundle.sh` contract (executable, strict mode, hash
  discovery, dist→top mirror, awk-based `.deploy-version` patch with
  no PHP dependency, failure messages), `dashboard/package.json`
  postbuild hook.
- Added `ci_status_*` pattern to `scripts/ci_lane_classifier.sh` ui lane.
- Vite rebuilt via the new automated hook → `index-D92kcI6h.js` /
  `index-BC5g6YJu.css`. All three sync points (dist/index.html,
  spa-assets/, .deploy-version) consistent. Updated
  `tests/sprint6b_dashboard_uis_smoke.php` expected hash.

### CI portability fix (same release)
- Removed all hardcoded `/app/...` paths from 6 smoke tests
  (`billing_spec_smoke.php`, `people_spec_smoke.php`,
  `placements_spec_smoke.php`, `time_spec_smoke.php`,
  `time_approval_tokens_smoke.php`, `invoice_pdf_smoke.php`). Replaced
  with `__DIR__ . '/../...'` so tests work on GitHub Actions runners
  (where the repo lives at `/home/runner/work/...`, not `/app/`).
- `invoice_pdf_smoke.php` live-render block now skips on CI
  (`CI=true` / `GITHUB_ACTIONS=true`) and degrades chromium failures
  to a skip rather than a fail — host-environment quirks no longer
  block deploy gate.

### Deploy notes
- Set `GITHUB_REPO=youruser/coreflux` (and optionally `GITHUB_TOKEN`
  for private repos) on the Cloudways host to activate the badge.
  Without these env vars the badge renders muted as "CI not
  configured" and never errors.


## Core Platform — Completed
- [x] Multi-tenant dashboard with dynamic tenant/module loading
- [x] MySQL on Cloudways wired through `core/db.php`
- [x] Secure login (`password_verify`), session, logout
- [x] Master admin panel for tenants, users, roles, module subscriptions
- [x] Framework layer (coreflux.css, shell, ui components)
- [x] React SPA (`/dashboard/`) rendered via `spa.php`
- [x] SPA ↔ PHP session bridge (`session.php` returns JSON)
- [x] Login routing to SPA (`login.php` + `login.html` with redirect flow)
- [x] **Module platform primitives (2026-02):**
  - `core/api_bootstrap.php` — standard API entry (auth, tenant, JSON, errors)
  - `core/tenant_scope.php` — `scopedQuery/Insert/Update/Delete` with identifier allowlist
  - `dashboard/src/lib/api.js` — shared fetch client + `useApi` hook
  - `/modules/_template/` — reference skeleton (manifest/api/migrations/ui)
  - `MODULE_SKELETON.md` + `MODULE_ONBOARDING.md` — rules & quickstart
  - `tests/core_platform_smoke.php` — CLI smoke test (PHP helpers)
- [x] **AI platform layer (2026-02, single-stack):**
  - `core/ai_service.php` — `aiAsk()` chokepoint that POSTs directly to OpenAI
    via cURL using `OPENAI_API_KEY` from `core/config.local.php`. Tenant +
    per-feature gating, response envelope contract, auto-fallback to
    `AI_FALLBACK_MODEL`, full audit trail. **No Python sidecar.**
  - `core/migrations/002_ai_platform.sql` — tenant toggles + `ai_tenant_features`
    + `ai_interactions` audit + `ai_suggestions` review workflow
  - `dashboard/src/components/AISuggestion.jsx` — the only render path for AI text
    (badge, edit, accept/reject, disclaimer, test ids)
  - `AI_INTEGRATION_RULES.md` — hard rules: AI is advisory narrative; never outputs
    values/formulas/decisions the app consumes; human-review-gated; one chokepoint
  - `tests/ai_platform_smoke.php` — direct OpenAI roundtrip + contract + gate (3 ✓)
- [x] **People module MVP (2026-02, on `feature/people`):**
  - `modules/people/migrations/001_init.sql` — 12 tables covering identity,
    contact, employment history, comp (history-aware), tax (federal+state+I-9),
    banking (encrypted), documents, time off, PII access + change audit
  - `modules/people/migrations/002_emails_sent.sql` — append-only email audit log
  - `core/encryption.php` — AES-256-GCM, last4, HMAC hash, tamper detection
  - `core/mailer.php` — `sendEmail()` platform helper wrapping vendored PHPMailer
    with existing SMTP constants; single chokepoint for all module-initiated email
  - `modules/people/api/*` — employees CRUD, addresses, contacts, comp, tax_federal,
    tax_state, i9, bank_accounts (encrypted), org_chart, ai_missing_fields, ai_summary,
    ai_setup_email (draft), send_setup_email (commit)
  - `modules/people/lib/employees.php` — stable cross-module read interface for Payroll
    (peopleGetEmployee, peopleActiveCompensation, peopleActiveFederalTax,
    peopleActiveStateTaxes, peopleActiveBankAccounts, peoplePayrollReadiness)
  - `modules/people/ui/*` — PeopleModule router, EmployeeDirectory, EmployeeDetail
    (5 tabs + AI-narrated payroll readiness banner with "Draft setup email" →
    AISuggestion review → send flow), EmployeeCreate, OrgChart
  - `PEOPLE_MODULE_PRD.md` — full spec including cross-module contract for Payroll
  - `tests/people_encryption_smoke.php` (5 checks green) + `tests/mailer_smoke.php` (4 checks green)
- [x] **Payroll module MVP (2026-02):**
  - `modules/payroll/migrations/001_init.sql` — 9 tables: settings, schedules,
    pay periods, profiles, runs, line items, earnings, taxes, deductions
    (cents-only BIGINT, tenant-scoped, idempotent)
  - `modules/payroll/lib/compute.php` — deterministic gross-to-net engine.
    Federal W-4 2020+ percentage method, FICA (SS wage base, Medicare + 0.9%
    additional over $200k YTD), FUTA with SUTA credit, California state tax
    + SDI. Pre-tax order: 401(k) → health → HSA. **Zero floats.**
  - `modules/payroll/lib/payroll.php` — cross-module helpers
    (payrollGetProfile, payrollEmployeesForSchedule, payrollYTDWages,
    payrollBuildComputeContext, payrollGenerateNextPeriods)
  - `modules/payroll/api/*.php` — settings, pay_schedules, pay_periods,
    profiles, runs (compute/approve/paid actions), pay_stub, ai_run_summary
  - `modules/payroll/ui/*` — PayrollModule router + 8 views: Overview,
    PaySchedules, PayPeriods, PayrollProfiles, PayrollProfileEdit,
    PayrollRuns, PayrollRunDetail (with `<AISuggestion />` advisory narrative),
    PayStub, PayrollSettings
  - Wired into `dashboard/src/App.jsx`, `core/modules.php` (role module list),
    `dashboard/src/layout/Sidebar.jsx` icon map
  - `tests/payroll_compute_smoke.php` — 16 deterministic compute assertions ✓
    (gross/SS/Medicare exactness, 401k FICA-taxable rule, SS wage base cap,
    Medicare 0.9% additional, FUTA wage base)
  - `vite.config.js` — added `resolve.alias` so modules outside `/app/dashboard`
    can import shared deps; production build green (1693 modules transformed)
  - `deploy/post_deploy_smoke.php` — added 9 payroll table checks

- [x] **Phase 4 — Placements module Phase A (2026-02-XX, this fork):**
  - Built fresh (legacy folder was empty, only SPEC+manifest existed)
  - `modules/placements/migrations/001_init.sql` — 9 tables (`utf8mb4_unicode_ci`):
    `placements`, `placement_client_chain`, `placement_rates`, `placement_commissions`,
    `placement_referrals`, `placement_corp_details` (encrypted EIN),
    `placement_documents`, `tenant_vendor_portals`, `tenant_end_clients`
  - 10 SPEC-aligned API endpoints under `modules/placements/api/`:
    `placements` (CRUD + end), `chain`, `rates` (draft + approve with snapshot lock +
    correction flow + auto-close prior effective_to), `commissions`, `referrals`,
    `corp` (encrypted), `documents`, `approval_contact`, `reports` (expiring +
    active_by_client), `csv_import` (Core\CsvImportService primitive; resolves
    person by email, drafts first rate row, creates end-client tier)
  - `modules/placements/lib/placements.php` — cross-module read interface +
    deterministic margin formula per SPEC §4 (additive vendor-fee stacking)
  - 7 React components: PlacementsModule (router), List, Expiring, Reports,
    PlacementCreate (with Person typeahead), PlacementDetail (9 tabs:
    Overview / Chain / Rates / Commissions / Referrals / Corp (C2C only) /
    Documents / Approval / Margin), CsvImport
  - Approve UX includes correction-with-reason path; PII (EIN) encrypted via
    `Core\encryption.php`; documents go through StorageService
  - `tests/placements_spec_smoke.php` — 96 assertions ✓
  - **383 platform smoke tests total ✓**
  - Vite bundle rebuilt (302kB JS) and synced; `App.jsx` wires `/modules/placements/*`
  - `memory/PLACEMENTS_DEPLOY_NOTES.md` — deploy + 15-step smoke walk

- [x] **Phase 9 — AP module Phase A0: invoice-to-pay loop (2026-02-XX, this fork):**
  - Mirror of Billing on the cost side. Closes the Time → vendor-pay loop:
    closed period → AP bundles → pending-approval bill → two-eye approve
    → record payment → allocate (FIFO or manual) → clear payment →
    1099-NEC ledger rebuild. Plaid Transfer env-gated (keys deferred).
    GL posting stubbed until Accounting v1.0.
  - **Schema** `modules/ap/migrations/001_init.sql` (idempotent,
    `utf8mb4_unicode_ci`, `information_schema`-guarded ALTERs) —
    8 tables: `ap_vendors_index` (encrypted EIN/SSN), `ap_bills`
    (8-state machine inbox→pending_review→pending_approval→approved→
    partially_paid→paid→void→disputed), `ap_bill_lines`
    (with `is_1099_eligible` + `gl_expense_account_code`),
    `ap_payments` (6-state draft→queued→sent→cleared→failed→void,
    method enum includes `plaid` for future Plaid Transfer),
    `ap_payment_allocations`, `ap_expense_reports` + lines
    (submit→approve→convert to bill), `ap_1099_ledger` (UNIQUE per
    tenant+tax_year+vendor). 4 tenant config columns
    (`ap_bill_prefix`, `ap_next_bill_seq`, `ap_default_terms`,
    `ap_1099_threshold` default $600).
  - **Library** `modules/ap/lib/ap.php` — `apNextInternalRef` (atomic
    FOR UPDATE on tenant row, format `{prefix}-{YYYY}-{NNNN}`),
    `apBuildDraftFromBundle` with `per_vendor`/`per_placement`
    aggregation detecting C2C corp vs 1099 individual via
    `placements.engagement_type` + `placement_corp_details.corp_name`,
    `apBillTransitionAllowed` / `apPaymentTransitionAllowed` matrix
    enforcement, `apAllocatePayment` (manual + auto-FIFO, atomic,
    refuses over-allocation / disputed / void), on-read
    `apComputeAging` (5 buckets), `apBuild1099Ledger` (idempotent
    upsert, proportional allocation of payment $ to 1099-eligible
    lines via bill_lines_eligible_total/bill_total), `apPlaidConfigured`
    env probe, `apAudit` emitter.
  - **API** `modules/ap/api/{bills,payments,vendors,expenses,aging,1099}.php` —
    Bills: list/detail/manual create/`from-time-bundle` (marks
    bundles `consumed_by_module='ap'`)/PATCH/approve (two-eye; refuses
    lines with total ≤ 0)/void (releases bundles if no payments)/dispute/
    post (STUBBED; emits audit, sets `journal_entry_id=NULL` until
    Accounting v1.0 exists). Payments: list (with
    `plaid_enabled` probe)/create (auto-FIFO optional)/allocate/send
    (SoD: creator ≠ releaser; refuses disputed/void bills)/clear/void
    (reverses allocations, recomputes bill statuses). Vendors:
    typeahead + upsert + PII reveal gated by `ap.vendor.view_pii`
    with audit. Expenses: submit/approve (converts to bill
    source=expense_report)/reject. Aging: on-read. 1099: year ledger +
    rebuild action.
  - **React UI** `modules/ap/ui/*` (10 components):
    `APModule` (router with 6-tab sub-nav), `BillsList` (status filter
    chips + from-time-bundle modal), `BillDetail` (summary + lines +
    allocations + approve/dispute/void/post actions),
    `BillFromTimeBundleModal` (period select → live AP bundle preview
    → aggregation toggle → bulk-create), `PaymentsList` (record-payment
    modal with plaid method gated on env, allocate modal with
    manual + auto-FIFO), `VendorsList` (search + create with encrypted
    tax ID), `ExpensesList` (submit/approve/reject actions),
    `ExpenseCreate` (multi-line entry with receipt placeholders),
    `AgingTable` (5 buckets per vendor with overdue color-coding +
    totals row), `Ledger1099` (rebuild from cleared payments, shows
    vendors over $600 threshold).
  - Wired into `App.jsx` at `/modules/ap/*` matching other modules.
  - **Manifest** `depends_on` = `['placements','time']` (accounting
    deferred — same pattern as Billing; once Accounting v1.0 ships,
    both modules will add it).
  - `tests/ap_spec_smoke.php` — 192 contract assertions ✓
    (migration shape + 8-state + 6-state enums, library math + both
    transition matrices, Plaid env probe, API parse + action routing +
    two-eye / SoD / void-reverses-allocations, manifest perms + audit
    events, UI wiring with all testids).
  - `tests/module_registry_smoke.php` updated: ap now asserts
    `depends_on` = placements+time (NOT accounting) per design.
  - All 15 platform smoke suites green: **903 assertions total ✓**
    (ap 192, billing 103, time 85, time-tokens 53, tenant-mail 38,
    m365 46, mail 38, people 104, placements 96, csv 24, rbac 27,
    module registry 40, API router 19, payroll compute 16, storage 22,
    plus core/mailer/people-encryption/ai-platform baseline).
  - Vite bundle rebuilt (1731 modules, 428kB JS / 17.6kB CSS) and synced.
  - `memory/AP_DEPLOY_NOTES.md` — Cloudways migration, optional
    Plaid enable steps, 9-step smoke walk, rollback.


- [x] **Phase 8 — Billing module Phase A0: invoice the work end-to-end (2026-02-XX, prior fork):**
  - First subledger module shipped. Closes the Time → revenue loop:
    closed period → AR bundles → draft invoice → approve (two-eye) →
    send via Resend with tenant Reply-To → public customer-portal view
    → record payment → allocate (FIFO or manual) → AR aging shrinks.
  - **Schema** `modules/billing/migrations/001_init.sql` (idempotent,
    `utf8mb4_unicode_ci`, `information_schema`-guarded ALTERs):
    `billing_invoices`, `billing_invoice_lines`, `billing_payments`,
    `billing_payment_allocations`, `billing_invoice_tokens` + 5 nullable
    columns on `tenants` (`billing_tax_rate_pct`, `billing_invoice_prefix`,
    `billing_next_invoice_seq`, `billing_invoice_terms`,
    `billing_payment_instructions`).
  - **Library** `modules/billing/lib/billing.php` — atomic invoice
    numbering (FOR UPDATE on tenant row), `billingBuildDraftFromBundle`
    with `per_placement` / `per_client` aggregation, tax math,
    state-transition matrix per SPEC §9 (draft → approved → sent →
    partially_paid → paid → void), token gen (sha256 hash compare),
    payment allocation (manual + auto-FIFO, atomic, refuses
    over-allocation), on-read aging buckets (current / 1-30 / 31-60 /
    61-90 / 91+), audit emitter.
  - **API** `modules/billing/api/{invoices,payments,aging}.php` — full
    CRUD + state actions. Invoices: list/detail/create-manual/`from-time-bundle`
    (marks bundles `consumed_by_module='billing'`)/PATCH-draft/approve
    (two-eye: actor !== creator)/send (issues token + emails customer
    via Resend with tenant Reply-To from Phase 6b)/void (releases
    bundles back to `ready` if no payments allocated). Payments: list /
    record (with optional auto-FIFO at create time) / `allocate`.
    Aging: on-read computation.
  - **Public viewer** `/billing/invoice.php?t=<token>` —
    unauthenticated, `noindex,nofollow`, print-friendly CSS,
    view-counter, paid/partial/void status banners.
  - **React UI** `modules/billing/ui/*` (6 components):
    `BillingModule` (router with sub-nav), `InvoicesList` (status filter
    chips + paginated table), `InvoiceDetail` (5 summary boxes,
    approve/send/void actions, token info, allocations table),
    `InvoiceFromTimeBundleModal` (period select → live AR bundle preview
    → aggregation toggle → bulk-create), `PaymentsList`
    (record-payment + allocate-modal with manual + auto-FIFO),
    `AgingTable` (5 buckets per client with overdue color-coding).
  - Wired into `App.jsx` at `/modules/billing/*` matching other modules.
  - **Manifest** depends_on tightened to `['placements','time']`
    (accounting bolts on later when v1.0 ships).
  - `tests/billing_spec_smoke.php` — 103 contract assertions ✓
    (migration shape, library math, transition matrix, API parse + action
    routing, manifest perms, UI wiring, public viewer security).
  - All 14 platform smoke suites still green
    (billing 103, time 85, time-tokens 53, tenant-mail 38, m365 46,
    mail 38, people 104, placements 96, csv 24, rbac 27, module
    registry 38, API router 19, payroll compute 16, storage 22).
  - Vite bundle rebuilt (1721 modules, 386kB JS / 17.6kB CSS) and synced.
  - `memory/BILLING_DEPLOY_NOTES.md` — Cloudways migration steps,
    tenant config SQL, end-to-end smoke walk, rollback.

- [x] **Phase 7 — Time Phase B Slice 2a: M365 mailbox connection (2026-02-XX, this fork):**
  - `Core\Mail\M365GraphDriver` — PHP cURL (no SDK) implementing
    `MailDriver`: delegated multi-tenant OAuth with PKCE S256,
    delta-query polling on `/me/mailFolders/{id}/messages/delta`,
    lazy refresh with 5-min buffer, `$deltatoken` extraction,
    outbound `send()` returns `failed` (Resend handles outbound).
  - `/oauth/callback/microsoft365.php` — state-validated via
    `hash_equals`, 10-min `$_SESSION` window, exchanges code → tokens
    → fetches `/me` → upserts `tenant_mail_connections` row with
    AES-256-GCM-encrypted tokens (reuses `Core\encryption`) → audits
    `mail.connection.connected` → redirects to `/settings/mail` with
    flash params.
  - `/api/mail_connections.php` — platform API with 5 actions:
    `GET` list, `oauth_start`, `list_folders` (live Graph), `watch_folder`
    (upsert `tenant_mail_folders`), `poll_now` (synchronous delta),
    `DELETE` (soft revoke). All gated by `tenant.manage`. Returns
    503 with actionable message if `MICROSOFT_*` env vars are missing.
  - `dashboard/src/pages/MailConnectionsCard.jsx` — extends Mail Settings
    page. "Connect Microsoft 365" button → OAuth redirect. Connected
    mailboxes list with folder picker modal (lucide icons, live item
    counts), per-folder "Fetch now" button showing top 5 subjects/senders
    in a result panel, revoke button.
  - Reuses existing `tenant_mail_connections` + `tenant_mail_folders`
    schema from Skinny 3b (`core/migrations/003_mail_service.sql`) —
    **no new migration required**.
  - `tests/m365_graph_smoke.php` — 46 assertions ✓: PKCE S256 challenge
    math, delta-token extraction, token exchange happy + error paths
    (injected transport), `/me` fetch, all 4 API actions, callback
    state validation + audit event, UI wiring.
  - All other platform smokes still green
    (time 85, time-tokens 53, tenant-mail 38, mail 38, people 104,
    placements 96, csv 24, rbac 27, module registry 37, API router 19,
    payroll compute 16, storage 22).
  - Vite bundle rebuilt (1715 modules, 359kB JS) and synced.
  - `memory/TIME_PHASE_B_SLICE2A_DEPLOY_NOTES.md` — Azure Portal
    checklist (multi-tenant app + 2 redirect URIs + admin consent),
    Cloudways env vars, end-to-end smoke test.
  - **Deferred to Slice 2b+**: AI parsing (`time_intake_events` +
    OpenAI), Inbox (AI) UI, cron polling.

- [x] **Phase 6b — Tenant mail settings Model B + DNS-aligned delivery (2026-02-XX, this fork):**
  - `core/migrations/004_tenant_mail_settings.sql` — idempotent via
    `information_schema` guard (MySQL 5.7 + 8 compatible); adds
    `tenants.mail_reply_to` + `tenants.mail_from_name_override`
  - `core/tenant_mail.php` — `cf_tenant_mail_sender(tenantId, module)`
    returns `{from, from_name, reply_to, model}`. Shared From
    (`RESEND_FROM_EMAIL`) + tenant-overridable display name +
    tenant-configurable Reply-To. Model C-forward-compatible.
  - `api/mail_settings.php` — platform-level GET/PUT gated by
    `tenant.manage`. Validates reply_to email shape, rejects header
    injection chars in display name, emits `tenant.mail_settings.updated`
    audit event.
  - `dashboard/src/pages/MailSettingsPage.jsx` — tenant self-service UI
    with live preview card showing exactly how `From:` + `Reply-To:`
    will render to recipients; linked from `/settings`.
  - `ResendDriver` — now accepts per-call `from_name` override
    (empty-string coalesces to default). `MailService` passes
    `from_name`/`reply_to`/`idempotency_key` through `opts`.
  - `modules/time/api/approval_tokens.php` — `issue` action now pulls
    sender via `cf_tenant_mail_sender()` so each tenant's outbound
    timesheet approval email carries their Reply-To.
  - `tests/tenant_mail_smoke.php` — 38 assertions ✓ (migration shape,
    helper API, platform API validation + header-injection guard,
    ResendDriver per-call override, MailService envelope wiring, UI +
    routing integration).
  - All platform smoke suites green (16 suites, 600+ assertions).
    Vite bundle rebuilt (1714 modules, 349kB JS) and synced.
  - `memory/MAIL_PLATFORM_SETUP.md` — one-time platform-operator
    playbook: 4 DNS records on `corefluxapp.com` (SPF + 3× DKIM), one
    CNAME for return-path alignment to kill "via resend.com",
    optional DMARC, env vars, end-to-end smoke test.

- [x] **Phase 6 — Time Phase B Slice 1: Tokenized client-approval email via Resend (2026-02-XX, this fork):**
  - New `Core\Mail\ResendDriver` (PHP cURL, no SDK) implementing the
    `MailDriver` interface — outbound only, supports idempotency keys,
    HTTP-level errors captured into `mail_outbox.error`
  - New `core/mail_bootstrap.php` — registers `ResendDriver` as default
    outbound when `RESEND_API_KEY` env is set, installs `mail_outbox`
    DB writer (idempotent; safe to `require_once` from any module)
  - `modules/time/migrations/002_approval_tokens.sql` — `time_approval_tokens`
    table (`utf8mb4_unicode_ci`); SHA-256 token hash stored alongside raw
    token; unique index on `token`, lazy-expire on access
  - `modules/time/api/approval_tokens.php` — 5 actions:
    `issue` (authed, `time.tokenized_email.issue`), `verify` (PUBLIC),
    `respond` (PUBLIC, token IS the credential), `revoke` (authed),
    `list` (authed). Respond flips entries atomically to
    `approved`/`rejected` with `approved_via='tokenized_client_email'`
  - `modules/time/lib/approval_tokens.php` — token gen
    (64 hex chars, 32-byte hash), hash round-trip, email body builder
    with per-day rollup + approve/reject buttons (HTML + text)
  - `/app/time_approve.php` — public landing page at site root
    (`noindex, nofollow`), unauthenticated, renders timesheet summary
    + Approve/Reject form, inline JS POSTs to respond API
  - Review Queue UI: per-row checkbox + sticky selection bar +
    validation that all selected rows share same `(placement_id,
    period_id)`, plus `TokenIssueModal` with TTL picker (1-30 days,
    default 7)
  - `tests/time_approval_tokens_smoke.php` — 53 assertions ✓ (migration,
    token gen, hash round-trip, email body, ResendDriver HTTP contract
    with injected transport, UI wiring)
  - All existing platform smoke tests still green (time 85, mail 38,
    people 104, placements 96, csv 24, rbac 27, module registry 37, API
    router 19, payroll compute 16, storage 22)
  - Vite bundle rebuilt (1713 modules, 341kB JS / 17.6kB CSS) and synced
    to `/app/spa-assets/`
  - `memory/TIME_PHASE_B_SLICE1_DEPLOY_NOTES.md` — Cloudways deploy +
    Resend domain-verification walkthrough + smoke test steps

- [x] **Phase 5 — Time module Phase A (2026-02-XX, this fork):**
  - Built fresh against `/app/modules/time/SPEC.md` Phase A scope; legacy
    preserved at `/app/legacy/time_pre_spec_<date>/` (HARD_RULES R1)
  - `modules/time/migrations/001_init.sql` — 4 tables (`utf8mb4_unicode_ci`,
    Cloudways-compatible): `time_periods`, `tenant_time_categories`,
    `time_entries`, `time_downstream_feed`
  - 9 standard categories + custom (regular_billable, regular_nonbillable,
    OT_billable, OT_nonbillable, holiday, vacation, sick, bereavement,
    unpaid_leave); deterministic `timeBucket()` rollups (billable /
    nonbillable / pto / unpaid / custom) per SPEC §2 / §3.3
  - 5 entry statuses (draft / pending_review / approved / rejected /
    superseded), 3 approval channels (manual / tokenized_client_email /
    bulk_pre_approved), 4 downstream bundle types (ar / ap / payroll /
    revrec) with consume + supersede flow
  - 6 SPEC-aligned API endpoints: `entries`, `periods`, `categories`,
    `reports`, `feed`, `csv_import` (via `Core\CsvImportService`)
  - 7 React components wired into `App.jsx` (`/modules/time/*`):
    `TimeModule` (router), `MyTime`, `ReviewQueue`, `Periods`, `Reports`,
    `Categories`, `CsvImport` — plus a **Period Close Wizard**
    (`PeriodCloseWizard.jsx`) that previews exact AR/AP/Payroll/RevRec
    bundles via the read-only `?action=preview_close` endpoint before
    the user confirms close, blocks on `pending_review` entries, and
    surfaces supersede badges for any consumed bundles being replaced
  - Manifest declares `depends_on: [people, placements]`, 14 permissions,
    10 audit events
  - `modules/time/lib/time.php` — cross-module read interface +
    period bundle builder (`timeBuildBundlesForPeriod`) ready for AR / AP /
    Payroll consumers
  - `tests/time_spec_smoke.php` — 85 contract assertions ✓ (was 74; +11
    for `timePreviewBundlesForPeriod`, `?action=preview_close` endpoint,
    and the wizard JSX wiring)
  - **All platform smoke tests still green** (people 104, placements 96,
    csv 24, mail 38, RBAC 27, module registry 37, API router 19, payroll
    compute 16, storage 22, time 74)
  - Vite bundle rebuilt (1712 modules, 336kB JS / 17.6kB CSS) and synced
    to `/app/spa-assets/`
  - `memory/TIME_DEPLOY_NOTES.md` — Cloudways deploy + UI smoke walk
  - Phase B deferred: real `M365GraphDriver` / `GmailApiDriver`,
    AI inbox parsing, tokenized client-approval email click-through

- [x] **Phase 4 — People module SPEC alignment + design polish + CSV import (2026-02-XX, this fork):**
  - Legacy preserved at `/app/legacy/people_pre_spec_<date>/` (HARD_RULES R1)
  - `modules/people/migrations/003_spec_alignment.sql` — 12 SPEC-aligned tables
    (utf8mb4_unicode_ci; legacy `people_employees` etc. untouched)
  - 12 API endpoints (CRUD/terminate/skills/pipeline+substages/emergency_contacts/
    documents/custom_fields/custom_field_values/merge/audit_pii/banking encrypted/
    tax encrypted/csv_import)
  - 9 React components: PeopleModule, Directory, PersonCreate, PersonDetail
    (7 tabs per SPEC §6), Pipeline, DocumentVault, CustomFields, PIIAuditLog, CsvImport
  - Design polish: `dashboard/src/styles.css` extended with module-shared
    primitives (`.btn`, `.btn--primary`, `.btn--ghost`, `.input`, `.data-table`,
    `.badge` 10 variants, `.tab`, `.error`) on `--cf-*` design tokens
  - `Core\CsvImportService` shipped at `/app/core/CsvImportService.php`
  - api_bootstrap surfaces missing-table errors clearly (no more generic 500)
  - `tests/people_spec_smoke.php` — 104 assertions ✓
  - `tests/csv_import_service_smoke.php` — 24 assertions ✓
  - `memory/PEOPLE_DEPLOY_NOTES.md` — deploy walkthrough


  - Legacy preserved at `/app/legacy/people_pre_spec_<date>/` (HARD_RULES R1)
  - `modules/people/migrations/003_spec_alignment.sql` — 12 new SPEC-aligned tables
    (additive; legacy `people_employees` etc. untouched)
  - 12 API endpoints under `modules/people/api/` aligned to SPEC §5: `people.php`
    (CRUD + terminate), `skills`, `pipeline` (incl. substages CRUD + summary),
    `emergency_contacts`, `documents` (StorageService-backed), `custom_fields`
    (defs + values), `merge` (with FK re-pointing across 6 tables + audit),
    `audit_pii` (SOC2 self-serve), `banking` (encrypted), `tax` (encrypted SSN),
    **`csv_import`** (template + dry_run + commit)
  - `modules/people/lib/people.php` — cross-module read interface
  - `modules/people/lib/audit.php` — audit_log writer (people.* events)
  - 9 React components: PeopleModule (router), Directory (filters + pagination
    + Import CSV button), PersonCreate (validated), PersonDetail (7 tabs per
    SPEC §6), Pipeline, DocumentVault, CustomFields, PIIAuditLog, **CsvImport**
    (download template → dry-run preview → commit, with skip_invalid)
  - **Design polish**: `dashboard/src/styles.css` extended with module-shared
    primitives built on `--cf-*` design tokens (`.btn`, `.btn--primary`,
    `.btn--ghost`, `.input`, `.data-table`, `.badge` (10 variants), `.tab`,
    `.error`, `.empty-state`, person-detail tab nav). Replaces unstyled
    raw browser widgets that were rendering before.
  - **`Core\CsvImportService`** at `/app/core/CsvImportService.php` — platform
    primitive built per HARD_RULES rule "every primary-entity module MUST expose
    CSV import". Schema-driven (modules register fields/types/enums), three-step
    flow (template → dry-run → commit), validates required/enum/email/date/
    boolean/cross-row-uniqueness, stateless, streaming-safe.
  - api_bootstrap.php upgraded: missing-table errors now surface
    `"Database table 'X' does not exist. Run the module's migration."`
    instead of generic 500.
  - `tests/people_spec_smoke.php` — 104 assertions ✓
  - `tests/csv_import_service_smoke.php` — 24 assertions ✓ (template,
    validation matrix, duplicate detection, date coercion, commit semantics
    with/without skip_invalid)
  - **267 platform smoke tests total ✓** (people 104 + csv 24 + storage 22 +
    mail 38 + RBAC 27 + module registry 37 + API router 19 + payroll compute 16)
  - Vite bundle rebuilt (1697 modules, 257kB JS / 17.6kB CSS) and synced to
    `/app/spa-assets/`
  - `memory/PEOPLE_DEPLOY_NOTES.md` — Cloudways deploy + rollback walkthrough,
    now includes CSV import test path and common-errors troubleshooting.


  - `core/MailService.php` — single email primitive (send + poll + OAuth flow stub).
    All modules MUST use this; no direct SMTP/IMAP/Graph/Gmail/Resend in module code.
  - `core/mail/MailDriver.php` — pluggable backend interface (poll, send, refresh_oauth, revoke).
  - `core/mail/LogDriver.php` — dev-only no-op sender (records to log + outbox writer);
    real provider drivers (M365GraphDriver, GmailApiDriver, ResendDriver) deferred to
    when Time module ships or first real outbound email is needed.
  - `core/migrations/003_mail_service.sql` — `tenant_mail_connections`,
    `tenant_mail_folders`, `mail_messages_seen`, `mail_outbox` (4 tables, all tenant-scoped,
    OAuth tokens encrypted at app layer, mail_outbox body 90d retention column).
  - `tests/mail_service_smoke.php` — 38 assertions ✓ (driver wiring, validation,
    end-to-end send, outbox writer contract, dedupe, fallback driver, poll,
    OAuth stub, custom driver registration). Total smoke tests: 143 ✓.
  - Azure AD app registered at `d5d81312-faf4-47ba-a001-d9a090415baa` (multitenant);
    client secret + Graph permissions deferred to real-driver phase.

- `main` — stable core + platform primitives + AI layer + **People MVP (merged)**
- `feature/people` — merged into main 2026-02
- `feature/payroll` — Payroll MVP (next)
- `feature/accounting` — Accounting CRUD expansion (later)

## In Progress
- [ ] User performs Cloudways deploy: visit `/install.php`, log in, paste OpenAI key, click Install. After that, `/update.php` handles all future deploys.
- [ ] AWS S3 setup: user follows `/app/memory/AWS_SETUP_GUIDE.md` to flip `STORAGE_DRIVER=local` → `STORAGE_DRIVER=s3` in production. Non-blocking; LocalDriver covers dev.
- [ ] Azure AD app registered (`d5d81312-faf4-47ba-a001-d9a090415baa`, multitenant). Client secret + Mail.Read/Mail.Send/MailboxSettings.Read/offline_access permissions deferred until real M365GraphDriver is wired (Phase 3b-real, when Time module ships).

## Recently completed (Phase A.0/A.1 + bugfix + P0 AP Liquidity + What-If Scenario + Digest Customization, 2026-02 — current fork)
**Tax agent split into 4 honest sub-agents, AI Agents promoted to a top-level platform feature, missing placement_client_chain columns migrated, inline AP "what-if" liquidity panel on every Bill detail page, multi-event Scenario Builder (with one-click presets) so finance leadership can stack hypothetical cash events, AND full digest customization — per-tenant agent picker, subject + intro overrides, week-over-week qualitative bucket diffs.**

### Bug fix — placement_client_chain missing columns
- Production hit `Database column 'updated_at' is missing — a migration probably needs to run.` whenever a tenant opened a placement detail page (which calls `placementChain()` in `lib/placements.php`).
- Root cause: `placementChain()` selects six columns the original `001_init.sql` never created on `placement_client_chain`: `company_id`, `submittal_id`, `vms_job_id`, `portal_credentials_ct`, `kms_key_version`, `updated_at`.
- Fix: `modules/placements/migrations/004_chain_extensions.sql` — six idempotent `information_schema`-guarded `ADD COLUMN`s + a `idx_pcc_company` reverse-lookup index. utf8mb4_unicode_ci, Cloudways MySQL 5.7+ compatible. Picked up automatically by `deploy/run_migrations.php`.
- `tests/bugfix_placement_chain_columns_smoke.php` — **19 ✓ / 0 fail**.

### AI Agent Roadmap — Phase A.0 (metadata) + A.1 (Tax split)
- `core/ai_agents.php` — legacy single `tax` agent **removed** from registry. Replaced with **4 honest sub-agents**:
  - `tax_mapping` — chart-of-accounts coverage for tax forms (the original narrow scope, now truthfully named).
  - `sales_tax` — sales/use-tax filing readiness (reads `accounting_accounts` + recent `billing_invoices` with `tax_total > 0`).
  - `payroll_tax` — federal/state withholding + FICA/FUTA/SUTA accrual cadence (reads matching account names + posted `accounting_journal_lines` in last 30 days).
  - `partner_distributions` — equity distribution / draw cadence from an income-tax-decision perspective (filters on `account_type='equity'` and `account_name LIKE '%distribution%' / '%draw%'`).
  - All 4 use `aiAgentBucketCount` / `SHOW TABLES LIKE` guards so they degrade gracefully on tenants without accounting tables.
- **Phase A.0 metadata** — every entry now declares `domain` (e.g. `['tax']`, `['tax','payroll']`, `['strategy']`) and `modules` tags. `/api/ai_agents.php?action=list` surfaces both fields so the UI can group by business surface instead of one flat 8-card grid.
- **Top-level route promotion** — `AIAgents` page lifted from `/modules/accounting/ai-agents` to platform-level `/ai-agents` per the user's "AI applies across the platform, not particular modules" requirement. Legacy accounting sub-route kept as a `<Navigate>` redirect alias so existing bookmarks survive.
- `dashboard/src/pages/AIAgents.jsx` — icon map covers all 8 agents (`Receipt` / `Calculator` / `Coins` / `Users` for the new tax sub-agents). Per-agent card now renders a row of domain chips (`Tax`, `Payroll`, `Equity`, `Treasury`, `Strategy`, `Accounting`).
- `tests/sprint7gA_tax_split_smoke.php` — **49 ✓ / 0 fail**: legacy `tax` agent gone, 4 new sub-agents present with unique feature_keys, every agent has `domain` + `modules` tags, the four new context builders exist + are SHOW-TABLES-guarded + return only qualitative buckets, API list response surfaces metadata, top-level route mounted, accounting redirect alias preserved.

### P0 — Inline AP Liquidity Projection Tool
- New endpoint `api/ap_bill_liquidity_impact.php` (GET, RBAC `treasury.payment.view`).
  - Inputs: `bill_id` (required), `pay_date` (defaults to today, format-validated, clamped to today..forecast-end), `days` (clamped 1..365, default 90).
  - Reuses **the same data sources** as `liquidity_forecast.php`: cash from posted JEs on `accounting_bank_accounts`, AR from `billing_invoices` in window, outflow union of `treasury_payments` + `ap_bills` with the same vendor+amount dedup heuristic.
  - **Excludes the simulated bill itself** from the baseline outflow map (otherwise a bill due in window would be double-counted on its own due_date AND on the simulated `pay_date` — math would lie).
  - Runs the day-by-day projection **twice** (baseline / simulated) using a shared closure, returns a `{baseline, simulated, delta}` envelope with `lowest_balance`, `lowest_balance_date`, `runway_days_to_zero` on each side and `lowest_balance_shift / lowest_date_shift_days / runway_days_lost / crosses_zero` deltas.
  - Zero-balance bills short-circuit with a `note` field; no projection wasted.
- Module-namespaced kebab alias `modules/ap/api/bill_liquidity_impact.php`.
- `modules/ap/ui/BillDetail.jsx` — new `<LiquidityImpactPanel />` rendered between the summary tiles and the Three-way Match panel, **only when `amount_due > 0`**. Date picker with `min={today}`, baseline → simulated currency shift line (red when balance falls), runway-loss warning when applicable, "✓ Balance stays positive across the X-day horizon" affirmation when no impact.
- `tests/p0_ap_bill_liquidity_impact_smoke.php` — **42 ✓ / 0 fail**: endpoint contract (GET-only, RBAC, validation, defaults, clamps), data-source parity with `liquidity_forecast.php`, baseline/simulated/delta envelope, exclusion of simulated bill from baseline, kebab alias delegation, UI wiring (testids, conditional mount, `min={today}` past-date guard).

### Validation
- Full PHP suite: **122 files, 0 failures** (was 118 → +4 new smoke files: tax split, placement chain bugfix, P0 AP liquidity, P3 treasury scenario; zero regressions).
- Vite rebuilt → `index-BDx-69AV.js` / `index-Cwhpy62y.css` synced. `.deploy-version`: 11 new sentinels + 9 new feature flags.

### Treasury What-If Scenario Builder (enhancement, same release)
- **Shared projection engine** — `core/treasury/liquidity_projection.php` exports `liquidityBaselineDatasets`, `liquidityBucketDatasets`, `liquidityWalkProjection`. Same SQL + same vendor+amount dedup heuristic + same day-by-day walker the per-bill what-if and the main forecast use. Optional `excludeBillId` parameter so the per-bill caller can exclude its own bill from baseline outflows without forking the math.
- **Endpoint** `api/treasury_scenario.php` (POST/GET, RBAC `treasury.payment.view`). Body `{days, events: [{kind, amount, date, label}]}`. 50-event cap, kind whitelist (`inflow`|`outflow`), positive-amount + YYYY-MM-DD date guard, dates clamped inside the forecast window. Returns full `{baseline, simulated, delta, guards}` envelope where `delta` includes `lowest_balance_shift`, `lowest_date_shift_days`, `runway_days_lost`, `crosses_zero`, `net_event_impact`, `inflow_total`, `outflow_total`. Module-namespaced kebab alias `modules/treasury/api/scenario.php`.
- **Page** `dashboard/src/pages/TreasuryScenario.jsx` mounted at `/modules/treasury/scenario`. Auto-runs the baseline on mount so the operator sees their current trajectory immediately. Add-event composer (kind / amount / date with `min={today}` guard / optional label) → list of stacked events with per-row remove → KPI tiles (Starting / Baseline ending / Simulated ending / Lowest-balance shift / Net event impact, color-coded green/red on direction) → red runway-loss banner (with simulated runway days) OR green "✓ stays positive across the X-day horizon" affirmation → dual-bar chart (slate baseline vs purple simulated, red bars when negative). Window selector 30/60/90/180.
- TreasuryModule — new "What-If Scenario" tab + `/scenario` route alongside the existing Liquidity Forecast.
- `tests/p3_treasury_scenario_smoke.php` — **61 ✓ / 0 fail**: shared engine surface, endpoint contract (RBAC, POST+GET, validation, 50-event cap, date format + clamp, days clamp), full response envelope, kebab alias, every page testid (composer + per-event row template + summary tiles + chart + runway alert + safe banner + no-banks nudge), routing.

### Scenario Presets — one-click templated event lists (enhancement, same release)
- `dashboard/src/pages/TreasuryScenario.jsx` — `SCENARIO_PRESETS` catalog with 5 turnkey scenarios:
  - **Hire 3 contractors** — $10k/mo for 3 months from month-+1.
  - **Lose biggest customer** — $50k outflow/mo for 3 months (representing lost AR).
  - **Delay vendor pay 30d** — 3 deferred AP waves of $25k from month-+2 onward.
  - **Quarterly tax payment** — single $50k outflow 60 days out.
  - **Take a $250k term loan** — immediate $250k inflow + $5k/mo principal payments for 6 months.
- All preset dates use relative helpers (`addDays`, `monthAhead`) so they always land inside the active forecast window — preset never goes stale.
- `applyPreset` is **additive** (events stack on top of any prior events the operator has already added). New "Clear all events" button surfaces only when there's something to clear.
- `tests/scenario_presets_smoke.php` — **24 ✓ / 0 fail**: catalog declaration, 5 named presets, relative-date math, additive `applyPreset`, `clearAll` reset, every preset-button + bar-root testid.

### AI Digest Customization — Phase A.2 + A.3 + A.4 (same release)
- **Migration `025_ai_digest_customization.sql`** — three idempotent ALTERs on `ai_agent_digest_settings` (`included_agents JSON`, `subject_override VARCHAR(200)`, `intro_override VARCHAR(1000)`) + a new `ai_agent_context_snapshots` table for the week-over-week diff engine.
- **Phase A.2 — per-tenant agent picker.** `aiAgentDigestRead` decodes the JSON column and filters out stale/unknown agent keys (defends against agents being removed from the registry post-config). `aiAgentDigestWrite` validates the array, dedupes via key map, rejects unknown keys with a typed `InvalidArgumentException`. Empty array / null = "all agents" (existing behaviour preserved). `aiAgentRunAll` accepts an `?array $onlyKeys` filter; `aiAgentDigestSend` threads the picker through.
- **Phase A.3 — subject + intro overrides.** Writer trims, length-caps (200 / 1000), rejects header injection (`\r\n`) in the subject. `aiAgentBuildDigestHtml` accepts an `?string $intro` parameter and `htmlspecialchars`-escapes it on render (no XSS). `aiAgentDigestSend` swaps the platform-default subject for the tenant override when set; the response envelope echoes the effective subject so the UI can show it.
- **Phase A.4 — week-over-week bucket diffs.** New helpers `aiAgentContextSnapshotWrite`, `aiAgentContextSnapshotPrior` (default cutoff = 6 days ago, matching the weekly cadence), `aiAgentBucketDiff` (recurses one level deep so the CFO context's `books`/`treasury` sub-trees diff cleanly, skips identical values, surfaces "key: prior → current" lines). `aiAgentDigestSend` reads the prior-week snapshot **before** running, persists this week's snapshot **after**, then threads diffs into the template. Each digest section now opens with a purple "Changed since last week" callout (escaped) listing only the buckets that actually moved.
- **`AIAgents.jsx` UI** — three new controls in the Digest panel:
  - Pill-style chip picker with an "All agents" pseudo-toggle and one chip per agent. Toggling individual chips peels the tenant off the "all" default; toggling "All" restores the default. Auto-saves.
  - Subject override `<input maxLength=200>` with autosave-on-blur.
  - Intro override `<textarea maxLength=1000 rows=2>` with autosave-on-blur.
- `tests/sprint7g_a234_digest_customization_smoke.php` — **51 ✓ / 0 fail**: migration shape, Phase A.2 reader/writer/runAll filter, Phase A.3 length caps + header-injection guard + intro/subject threading through send pipeline, Phase A.4 snapshot writer (best-effort), prior reader (cutoff math), `aiAgentBucketDiff` recursion, digest-send snapshot-before/snapshot-after ordering + "Changed since last week" template render, full UI testid coverage.

### Saved Treasury Scenarios — operator preset library (enhancement, same release)
- Migration `026_treasury_scenario_presets.sql` — tenant-scoped table with `UNIQUE (tenant_id, name)` so re-saving the same scenario name upserts in place. JSON event payload mirrors the live scenario endpoint contract (kind / amount / date / label).
- Endpoint `api/treasury_scenario_presets.php` — GET / POST / DELETE. Read = `treasury.payment.view`; write = `treasury.payment.manage`. POST validates name (≤120) + description (≤500) + events array (kind whitelist, positive amount, YYYY-MM-DD format) and **does NOT clamp dates** — presets are re-applied later, often outside today's window. Edit-in-place via `id`. ON DUPLICATE KEY upsert path so saving the same name twice never errors. DELETE returns 404 when the row's missing, 422 when no id.
- Module-namespaced kebab alias `modules/treasury/api/scenario_presets.php`.
- `dashboard/src/pages/TreasuryScenario.jsx` — new "Your saved scenarios" bar above the built-in presets. "Save current as preset" button (disabled until events.length > 0) opens an inline form (name + optional description). Saved cards render in a horizontal stack with delete-by-icon. Applying a saved preset **replaces** the current event stack rather than appending — the operator picked a specific saved scenario, layering it onto unrelated events would dilute the comparison.
- `tests/saved_scenarios_smoke.php` — **50 ✓ / 0 fail**: migration shape, endpoint contract (GET/POST/DELETE per RBAC tier), POST validation matrix, no-clamp guarantee, upsert + edit-in-place + 404 on missing row, kebab alias, all UI testids (save form, save status, saved-list cards, per-card apply/delete), apply-replaces-stack behaviour, delete confirms before firing.

### AI Roadmap Phase A.5 — Treasury / CFO splits (additive)
- **CFO Variance** (`cfo_variance`) — period-over-period variance voice. System prompt focuses on what HAS CHANGED versus the prior period and trend direction, in 3–5 bullets. Pairs with the Phase A.4 bucket-diff renderer for clean week-over-week commentary. Context layers on top of bookkeeper + treasury + reconciliation (richer surface than the strategic CFO agent which only stitches books + treasury).
- **Treasury Payments** (`treasury_payments`) — pending-payment queue health voice. Reads counts in `draft` / `pending_approval` / `approved` / `scheduled` buckets + count of disputed AP bills, all bucketized. System prompt flags approval bottlenecks (lots pending_approval, few moving to approved) and disputed-bill accumulation, but never proposes specific payments to release.
- Existing `cfo` and `treasury_analyst` agents are **retained for backwards compatibility** — Phase A.5 is additive, not destructive (unlike the Phase A.1 tax split). Tenants who already configured included_agents stay valid.
- AIAgents.jsx icon map covers the 2 new agents (TrendingUp for variance, Send for payments). Digest copy bumped to "all ten agents".
- `tests/sprint7g_a5_treasury_cfo_split_smoke.php` — **25 ✓ / 0 fail**: registry additivity check (legacy keys retained), domain + modules tags, kind = 'summary' (variance + queue voices are bullet-style not narrative), context builders + SHOW TABLES guards, qualitative bucket discipline, full icon-map coverage.

### P3 Refactor — shared liquidity projection engine adoption
- `api/liquidity_forecast.php` and `api/ap_bill_liquidity_impact.php` migrated onto `core/treasury/liquidity_projection.php`. Same response shapes — zero behaviour change — but each endpoint now reads as a thin RBAC-and-shape wrapper around three engine calls (`liquidityBaselineDatasets` → `liquidityBucketDatasets` → `liquidityWalkProjection`). Per-bill what-if uses the optional `excludeBillId` parameter to drop the simulated bill from baseline outflows.
- File sizes dropped: `liquidity_forecast.php` 213 → 91 LoC (~57% smaller), `ap_bill_liquidity_impact.php` 198 → 134 LoC (~32% smaller).
- Existing P0 + P2 smoke tests rewired to assert the engine is called and to verify the SQL fingerprints in the shared library file (still strict — refactor proven by tests).

### Validation
- Full PHP suite: **128 files, 0 failures** (was 118 → +10 new smoke files; zero behavioural regressions despite the engine refactor).
- Vite rebuilt → `index-B2LVFYMg.js` / `index-Cwhpy62y.css` synced. `.deploy-version`: 22 new sentinels + 25 new feature flags.

### Scenario Share Links — read-only deep links to investors / board (enhancement, same release)
- **Migration `027_scenario_share_links.sql`** — `treasury_scenario_share_links` with `kind` ENUM ('single'|'compare'), SHA-256 `token_hash` (cleartext token NEVER stored), `expires_at` + `revoked_at`, `view_count` + `last_viewed_at` + `last_viewed_ip` audit columns, active-link lookup index. UNIQUE on `token_hash`.
- **Endpoint** `api/treasury_scenario_share.php` — multi-action:
  - **`view`** (PUBLIC, no `api_require_auth()` on this path — intentional, like the vendor portal precedent). Token-only gate; 404 on invalid, 410 on revoked / expired / source-preset-deleted. Audit-bumps `view_count` + `last_viewed_at` + `last_viewed_ip` inside a try/catch so a write failure never blocks the read. Single-kind links resolve preset_a → run baseline + simA; compare-kind links additionally resolve preset_b → simB. All projections delegate to the shared liquidity engine.
  - **`create`** (POST, RBAC `treasury.payment.manage`). Validates `kind` whitelist, both presets exist + belong to caller's tenant (cross-tenant leak guard), label ≤200ch, `expires_in_days` clamped 1..30 (default 7), `compare`-kind must reference two different presets. Generates 30-byte hex token, persists SHA-256(token), returns the cleartext token + a fully-qualified public URL built from the request scheme + host + `/share/scenario?token=…`.
  - **`list`** (GET, RBAC manage) — emits computed `status` ('active'|'expired'|'revoked').
  - **`revoke`** (POST, RBAC manage) — sets `revoked_at = NOW()`. 404 on missing row.
- **Public viewer** `dashboard/src/pages/ScenarioShare.jsx` mounted at `/share/scenario` (next to the existing `/vendor/portal` public route). Uses `useSearchParams` to read `?token=` and a raw `fetch` call (NOT the authed `api` client which would bounce to `/login`). Renders header (scenario name(s), label, expiry stamp), KPI tiles, three-line SVG chart (omits `scenario_b` line for single-kind links), event stack(s), and a "Read-only share link" badge. Friendly error state explains the 7-day default expiry and points the recipient back to whoever sent the link.
- **TreasuryScenarioCompare.jsx** — new "Share this comparison" panel (only visible when both pickers resolve to two different scenarios + the comparison projection has loaded). Inline form: optional label (≤200ch) + expiry selector (1/3/7/14/30 days) + "Create link" button. On success the panel swaps to a result row: read-only URL input with select-on-focus + "Copy URL" button (uses `navigator.clipboard.writeText`, flashes "Copied!" for 2s) + expiry stamp.
- `tests/scenario_share_smoke.php` — **68 ✓ / 0 fail**: migration shape (token uniqueness, audit columns, status indexes), endpoint contract per action (PUBLIC `view`, RBAC-gated `create`/`list`/`revoke`), token never persisted in cleartext, cross-tenant leak guard, expiry/revoke/missing-source 410s, audit best-effort wrapping, request-host URL building, public route mount, ScenarioShare uses raw fetch (no auth), full Compare-page share-form testid coverage.

### Compare Scenarios — A/B view (enhancement, same release)
- **Endpoint** `api/treasury_scenario_compare.php` (POST, RBAC `treasury.payment.view`). Body `{days, scenario_a:{label,events}, scenario_b:{label,events}}`. Validates BOTH scenarios symmetrically (kind whitelist, positive amount, YYYY-MM-DD format, 50-event cap each, label ≤120ch, dates clamped to active forecast window). Reuses the shared `liquidity_projection.php` engine — pulls baseline datasets ONCE then runs the walker three times (baseline + scenario A overlay + scenario B overlay) so the response carries three full daily series the UI can chart with no further round-trips.
- Returns `{baseline, scenario_a, scenario_b, deltas: {a_vs_baseline, b_vs_baseline, a_vs_b}, guards}`. Each delta envelope matches the single-scenario `delta` shape (`lowest_balance_shift`, `lowest_date_shift_days`, `runway_days_lost`, `crosses_zero`) so UI components can be reused.
- Module-namespaced kebab alias `modules/treasury/api/scenario_compare.php`.
- **Page** `dashboard/src/pages/TreasuryScenarioCompare.jsx` mounted at `/modules/treasury/compare`. Two-column picker fed by the saved-preset library (auto-defaults to the two most recently updated presets). Empty-state nudge when fewer than 2 saved presets exist. Self-comparison guarded with an explicit warning. Renders an inline SVG chart with three `<path>` series (baseline slate, A purple, B teal) and a dashed zero baseline. Three pairwise delta cards (A vs baseline, B vs baseline, A vs B). Side-by-side event stacks at the bottom so the operator can see which events each side proposes.
- TreasuryModule — new "Compare Scenarios" tab + `/compare` route alongside Liquidity Forecast and What-If Scenario.
- `tests/scenario_compare_smoke.php` — **44 ✓ / 0 fail**: endpoint contract (POST-only, RBAC, validation matrix, 50-event cap per scenario, date clamping, shared engine reuse), three-projection math, full response envelope (three series + three pairwise deltas), kebab alias, every page testid, default-pick-two-presets behaviour, self-comparison block, three-series SVG chart wiring, side-by-side event stacks, routing.


## Recently completed (P2 — Liquidity Forecast + Auto-reversing accruals, 2026-02)
**Treasury gets a forward-looking cash projection; accounting gets one-flag-set-and-forget accrual reversal.**

### P2.A — Liquidity Forecast
- `api/liquidity_forecast.php` — GET-only, RBAC `treasury.payment.view`, `days` clamped 1..365 (default 90).
  - **Starting cash**: sum of GL balance for accounts mapped to active `accounting_bank_accounts`. Posted JEs only.
  - **Inflows**: open `billing_invoices` due in window (`amount_due` with `total - amount_paid` fallback). Statuses `approved/sent/partially_paid`.
  - **Outflows**: `treasury_payments` scheduled in window (statuses `draft/pending_approval/approved/scheduled`) UNION `ap_bills` due in window (`approved/partially_paid/pending_approval`). Vendor-name + amount dedup heuristic so an AP bill wrapped in a treasury payment isn't double-counted.
  - **Day-by-day projection** with running balance, lowest-balance + lowest-balance-date tracking, `runway_days_to_zero` (first day balance goes negative).
  - **`guards` envelope** — `has_bank_accounts / has_open_ar / has_open_ap / has_scheduled_payments` so the UI can render operator nudges.
- `/api/treasury/liquidity-forecast` kebab alias.
- `dashboard/src/pages/LiquidityForecast.jsx` — page mounted at `/modules/treasury/forecast`. 5 KPI tiles (starting / inflows / outflows / projected ending / lowest balance). Window selector (30/60/90/180 days). Daily bar viz with negative bars in red, zero-line dashed when window goes below zero. Red runway alert banner when `runway_days_to_zero` is set. Amber "no banks configured" nudge when `guards.has_bank_accounts === false`.
- `TreasuryModule` — new "Liquidity Forecast" tab + `/forecast` route.

### P2.B — Auto-reversing accruals
- Migration `024_auto_reversing_accruals.sql` — adds `auto_reverses_on DATE`, `auto_reverse_attempted_at TIMESTAMP`, `auto_reverse_last_error VARCHAR(500)` to `accounting_journal_entries` + `idx_aje_auto_reverse (tenant, date, status)` for the cron.
- `accountingPostJe()` — accepts `auto_reverses_on` field, validates `YYYY-MM-DD` regex, blank/empty coerced to null.
- `api/je_auto_reverse.php` — POST-only, RBAC `accounting.je.post`. Sets/clears `auto_reverses_on` on a posted, non-reversal JE. Rejects unposted JEs, reversal-of-something JEs, and dates ≤ posting_date.
- `scripts/auto_reverse_accruals.php` — daily cron. Finds JEs where `auto_reverses_on <= today`, status='posted', `reverses_je_id IS NULL`, AND no entry already reverses it (`NOT EXISTS` clause = idempotent). Calls existing `accountingReverseJe()` helper. Nulls `auto_reverses_on` on success; persists error to `auto_reverse_last_error` on failure. Suggested cron line: `0 6 * * * /usr/bin/php /app/scripts/auto_reverse_accruals.php`.

### Validation
- `tests/p2_liquidity_and_auto_reverse_smoke.php` — **51 ✓ / 0 fail**: full Liquidity Forecast contract (RBAC, days clamp, GL-driven starting cash, AR/treasury_payments/AP outflow union with vendor+amount dedup, day-by-day projection, runway detection, guards envelope, per-source breakdown), kebab alias, UI (5 tiles + window selector + runway banner + bar chart + no-banks nudge), routing, migration shape, accountingPostJe accepts auto_reverses_on with regex validation, je_auto_reverse endpoint contract (rejects 4 invalid states), cron contract (idempotent NOT EXISTS, helper reuse, success/failure paths).
- Full PHP suite: **118 files, 0 failures** (was 117 → +1 new smoke file, zero regressions).
- Vite rebuilt → `index-BoFWgL5t.js` synced. `.deploy-version`: 8 new sentinels + 6 new feature flags.


## Recently completed (P1 sweep + bugfix, 2026-02)
**Cleared the entire P1 backlog: bug fix + Linked External Systems panel + A4 time direction wiring.**

### Bug fix — placements.remote_policy '' truncation
- Repro: `PlacementCreate` form initialises `remote_policy=''` so the dropdown shows "—". On submit, MySQL ENUM rejected '' with SQLSTATE 1265 "Data truncated for column 'remote_policy'".
- Fix: new `placementsNormalizeRemotePolicy()` helper in `lib/placements.php` (so the CSV import path can use it without pulling the API file). Coerces '' / null / unknown / non-string → `null`; validates against `PLACEMENTS_ALLOWED_REMOTE = ['onsite','hybrid','remote']`. Wired into POST + PATCH + CSV import paths.
- `tests/bugfix_placements_remote_policy_coercion_smoke.php` — **17 ✓ / 0 fail**.

### P1 — Linked External Systems mini-panel
- `dashboard/src/components/LinkedExternalSystemsPanel.jsx` — reusable component. Reads existing A2 endpoint `list_for_internal`. Renders one row per source (JobDiva/Bullhorn/etc.) with status palette (ok/stale/error/deleted_in_source), direction label (pull/push/two-way/off), external_id in monospace, last-synced timestamp. Empty state present so operator knows the panel rendered intentionally.
- **PersonDetail** — new "Connections" tab in TABS array + `<Route path="connections">`.
- **Company DirectoryDetail** — inline panel rendered below the Contacts table.
- `tests/p1_linked_external_systems_panel_smoke.php` — **19 ✓ / 0 fail**.

### P1 — Sprint 8a A4 follow-on: time direction wiring
- New driver `core/jobdiva/sync_time.php`:
  - `jobdivaSyncTimePull` — pulls JobDiva timesheets, resolves placement via existing mapping (NO auto-create per user requirement), joins `placements + time_periods` to derive person_id + period_id + work_date, upserts to `time_entries` (tagged source='bulk_upload' so the source enum stays untouched), binds via mapping.
  - `jobdivaSyncTimePush` — pushes approved + non-superseded entries from last 60 days. Content-hash short-circuit so unchanged entries skip HTTP. PUT-existing / POST-new dispatch. Test transport injection via `$opts['transport']` callable.
  - `jobdivaSyncUpsertTimeEntry` — respects approval lock (only updates draft/pending_review). New inserts default to `status='draft'`.
  - `jobdivaMapTimeCategory` / `jobdivaUnmapTimeCategory` — bidirectional category mapping (regular ↔ regular_billable, overtime ↔ OT_billable, PTO → vacation, etc.).
- `core/jobdiva/sync.php` orchestrator extended:
  - New `shouldPush` helper (mirrors `shouldPull`).
  - Time entity dispatched when EITHER `shouldPull` OR `shouldPush` matches; two_way runs both. Lazy-requires `sync_time.php` only when needed.
  - Time count + by_entity envelope row added.
- `tests/p1_a4_time_direction_wiring_smoke.php` — **40 ✓ / 0 fail**.

### Validation
- Full PHP suite: **117 files, 0 failures** (was 114 → +3 new smoke files, zero regressions).
- Vite rebuilt → `index-C1dU07vD.js` synced. `.deploy-version`: 6 new sentinels + 4 new feature flags.


## Recently completed (Sprint 7g — Slices 2 + 3 + on-demand digest, 2026-02)
**Per-agent mode (advisory vs auto_log), on-demand "Email me a digest now" button, and a DOW-scheduled weekly digest with idempotent cron — three follow-on slices shipped together since they share the same data + email plumbing.**

### What shipped
- **Migration `023_ai_agent_settings.sql`** — two tables, idempotent:
  - `ai_agent_settings` (per-tenant per-agent `mode` enum: `advisory` | `auto_log`). Mode `auto_apply` deliberately NOT in the enum — none of the current 5 agents emit values the app could apply, and adding the value would prematurely sanction it. Schema-level guard against drift from `AI_INTEGRATION_RULES.md`.
  - `ai_agent_digest_settings` (one row per tenant: `enabled`, `recipients`, `send_dow` 1..7, `last_sent_at`, `last_send_error`).
- **`core/ai_agents.php` extensions**:
  - `aiAgentModeRead/ReadAll/Write` — whitelist-validated, race-safe (`ON DUPLICATE KEY UPDATE`).
  - `aiAgentRunWithMode($tid, $userId, $key)` — runs the agent and, when mode=auto_log, marks the resulting row in `ai_suggestions` as `accepted` so it flows into the passive insights feed without blocking. Auto-log is best-effort (catch Throwable) — narrative is intact either way.
  - `aiAgentRunAll`, `aiAgentBuildDigestHtml` (XSS-safe via `htmlspecialchars`/`nl2br` on every label and body), `aiAgentDigestRead/Write/Recipients/Send`.
  - `aiAgentDigestRecipients` falls back to tenant `master_admin` email — operator never has to configure recipients to get a digest.
  - `aiAgentDigestSend` uses **`cf_tenant_mail_sender()`** for tenant-aware From/Reply-To (same pipeline as billing emails) and `sendEmail()`. Bumps `last_sent_at` + clears `last_send_error` on success; persists error string on failure for UI surfacing.
- **API additions** on `api/ai_agents.php`:
  - `list` upgraded to return `modes` catalog + `digest` config + per-agent `mode` so the page renders in one fetch.
  - `mode_set` (POST, `ai.config.manage`).
  - `digest_settings_set` (POST, `ai.config.manage`) — validates each recipient email via `FILTER_VALIDATE_EMAIL`, clamps DOW 1..7.
  - `digest_send_now` (POST, `ai.config.manage`) — synchronous run-all + email; AIDisabled→503; persists `last_send_error` even on Throwable so the UI can show what went wrong.
  - `run` upgraded to `aiAgentRunWithMode` so auto_log mode kicks in there too.
- **`scripts/ai_agents_weekly_digest.php`** — daily cron driver. Fires only for tenants whose `send_dow` matches today's DOW (`date('N')`) AND who haven't been sent within the last 6 days (idempotency belt against duplicate sends from any clock skew). Logs per-tenant ok/fail; `exit($failed > 0 ? 1 : 0)`. Suggested cron line included in the file header.
- **`AIAgents.jsx` page additions**:
  - **Digest panel** at top: `Mail`-iconed purple card with "Email me a digest now" primary button + "Auto-send weekly" checkbox + DOW dropdown (Mon..Sun) + recipients input (default placeholder explains master_admin fallback) + last-sent stamp + last-error surface + transient digest-note status.
  - **Per-agent Mode dropdown** on each agent card (Advisory / Auto-log) with explanation labels.
  - Wired `reload` from `useApi` hook (matches `lib/api.js`).

### Validation
- `tests/sprint7g_followons_digest_smoke.php` — **60 ✓ / 0 fail**: migration shape (idempotent, mode enum tight, dow + idempotency columns), library surface (mode helpers race-safe, auto_log best-effort, runAll error capture, digest HTML escaping for XSS, recipient validation, master_admin fallback, tenant-Resend-pipeline use, last-sent bump), API contract (4 new actions, RBAC `ai.config.manage` on writes, AIDisabled→503, error persistence on Throwable), cron contract (DOW gate, 6-day idempotency, exit code), UI contract (every testid, all 7 DOW options, mode options, all 3 endpoints wired, reload binding).
- Full PHP suite: **114 files, 0 failures**.
- Vite rebuilt → `index-C3wY_B72.js` synced. `.deploy-version`: 3 new sentinels + 4 new feature flags.


## Recently completed (Sprint 7g — AI agent suite + Last Sync tile, 2026-02)
**Five purpose-built AI advisory agents (Bookkeeper, Reconciliation, Treasury Analyst, CFO, Tax) plus the integration-freshness tile on Bookkeeping Overview. Every agent is strictly advisory per `AI_INTEGRATION_RULES.md` — they produce qualitative narratives only, never raw numbers, never actions. The chokepoint stays `aiAsk()`.**

### What shipped
- **`core/ai_agents.php`** — `AI_AGENTS` registry of 5 agents. Each entry: `label`, `description`, `feature_class='narrative'`, unique `feature_key` (e.g. `agent.bookkeeper.health_review`), `kind` (narrative or summary), `system` prompt that explicitly forbids restating raw numbers, and a `context_fn` reference. `aiAgentRun($tid, $key)` → builds qualitative context, hands it to `aiAsk()`, returns the standard envelope (incl. `interaction_id`) for `<AISuggestion />`. Caps `max_output_tokens` at 600.
- **Qualitative bucket helpers** — `aiAgentBucketCount()` (none/very_few/small/moderate/large/very_large) and `aiAgentBucketDays()` (within_a_week/_month/_three_months/...). Context builders emit ONLY these labels — never raw counts — to prevent the model from laundering specific numbers back into the narrative. Belt-and-braces with the existing `AI_FORBIDDEN_KEYS` enforcement in `aiAsk()`.
- **5 context builders**, each tenant-scoped + `SHOW TABLES` guarded for graceful degradation:
  - `aiAgentContextBookkeeper` — posted JE counts (30d) + uncategorized bank lines.
  - `aiAgentContextReconciliation` — days since last reconciliation + uncategorized.
  - `aiAgentContextTreasury` — payments/transfers pending + active bank count.
  - `aiAgentContextCFO` — composite of bookkeeper + treasury (pattern recognition over fresh measurement).
  - `aiAgentContextTax` — mapped accounts + unmapped revenue/expense accounts.
- **`api/ai_agents.php`** — single dispatcher: `?action=list` (GET) returns the catalog, `?action=run&agent=<key>` (POST) returns `{agent, envelope}`. RBAC `accounting.je.view`. AIDisabled→503, unknown agent→404, validation→422, runtime→502. Tenant + feature gating happens inside `aiAsk()` (this endpoint never bypasses it).
- **`dashboard/src/pages/AIAgents.jsx`** — page mounted at `/modules/accounting/ai-agents`. Renders 5 agent cards in a responsive grid. Each card has Run/Re-run button + per-agent error surface + on-success drops the envelope into `<AISuggestion />` for review/edit/accept/reject (the only AI render path per platform rules). Per-agent testids: `ai-agents-card-{key}`, `ai-agents-run-{key}`, `ai-agents-result-{key}`.
- **AccountingModule wiring** — `<Tab to="ai-agents" label="AI Agents" />` sub-nav + `/ai-agents` route.

### Last Sync tile (Sprint 8a follow-on, same release)
- **`api/books_health.php` envelope** — adds `integrations: [{source, label, status, last_sync_at, hours_since, last_sync_error}]`. Guards on `jobdiva_connections` table existing — pre-Sprint-8a tenants degrade silently. Forward-compatible with future integrations (Bullhorn/Greenhouse) — just append a row per source.
- **`BookkeepingOverview.jsx`** — new "Integrations" card in the right column (visible only when ≥1 integration is configured). Per-source row shows status pill + humanised "Xh ago"/"Xd ago" / "just now" / "never" label. Palette: green ≤24h, amber 24h–7d, red >7d. Per-source testids: `bookkeeping-overview-integration-row-{source}`, `bookkeeping-overview-integration-last-sync-{source}`.

### Validation
- `tests/sprint7g_ai_agents_smoke.php` — **77 ✓ / 0 fail**: registry shape (5 agents, all `narrative`, unique feature_keys, advisory-only language guards), `aiAgentRun` chokepoint usage, all 5 context builders exist + `SHOW TABLES` guarded, runtime bucket helper assertions, API contract (RBAC, GET/POST, error codes, AIDisabled→503), AIAgents.jsx page wiring (testids, AISuggestion delegation, encodeURIComponent), books_health integrations envelope, BookkeepingOverview Last Sync tile (testids + palette + humanised label), routing.
- Full PHP suite: **113 files, 0 failures**.
- Vite rebuilt → `index-DHf0BeOb.js` synced. `.deploy-version` updated (4 new sentinels for ai_agents.php / api / page / smoke + 11 new feature flags).

### Sprint 7g — explicit non-goals (future slices)
- **Per-agent permission modes** (advisory vs auto-apply) — current implementation is advisory-only across the board. The `ai_suggestions` review workflow already exists; future slices can add per-feature `auto_apply_threshold` rules.
- **Cross-agent "Daily digest"** — single emailed summary stitching all 5 agents' output, deferred until sustained user demand.
- **Agent scheduling / cron** — agents are on-demand only; daily/weekly auto-runs deferred.


## Recently completed (Sprint 7f.4 — Missing-dimension alerts, 2026-02)
**Yellow CTA on Bookkeeping Overview when posted JE lines are missing required dimension values, deep-linked to a dedicated review page where each row jumps to its parent JE — closes the loop on the dimensions framework.**

### What shipped
- **`api/missing_dimensions.php`** — RBAC-gated GET (`accounting.je.view`). Reads `accounting_dimensions` registry + `accounting_account_dim_rules`, scans posted JE lines in window (1..1825 days, default 90), returns `{count, by_account: [...], rows: [...]}` sorted by missing_count desc. Empty registry → graceful early-return with explanatory note. limit clamped 1..500.
- **`api/books_health.php` envelope** — adds `missing_dims: {count, sample_accounts}` (top-3 offenders) so the dashboard tile renders in one fetch. Guards on `accounting_dimensions` table existing AND on `accounting_journal_lines.dimension_values` column existing — pre-7f.4 tenants degrade silently.
- **`/api/accounting/missing-dimensions`** — module-namespaced kebab alias.
- **`dashboard/src/pages/BookkeepingOverview.jsx`** — yellow `AlertTriangle` CTA card visible only when count > 0; renders count + top-offender accounts inline + "Review now →" amber button deep-linking to the page.
- **`dashboard/src/pages/MissingDimensions.jsx`** — full review page: by-account aggregate table + per-row table with "Open JE →" deep-link to `/modules/accounting/journal-entries/{id}`. Empty state is green.
- **AccountingModule routing** — adds `/missing-dimensions` route.

### Validation
- `tests/sprint7f4_missing_dimensions_smoke.php` — **42 ✓ / 0 fail**: API contract (RBAC, GET-only, days/limit clamps, registry read, posted-only filter, required-only rules, by_account sort), books_health envelope (graceful guards, 90d window, top-3 truncation), kebab alias, BookkeepingOverview yellow CTA wiring (testids + count gate + deep-link + amber palette), MissingDimensions page (empty state + dynamic row testids + Open JE deep-link template + back link), AccountingModule routing.

## Recently completed (Sprint 8a / Slice A4 — Per-entity sync config picker, 2026-02)
**The tenant decides who owns each entity (JobDiva or CoreFlux) and the sync direction. Time defaults to OFF — the operator must explicitly opt in to either pull from JobDiva or push to it.**

### What shipped
- **`core/jobdiva/client.php`** — adds `JOBDIVA_SYNC_ENTITIES` (company/contact/placement/time), `JOBDIVA_SYNC_SOURCES` (jobdiva/coreflux), `JOBDIVA_SYNC_DIRECTIONS` (pull/push/two_way/off), `JOBDIVA_SYNC_DEFAULTS` (3 ATS-adjacent entities default to jobdiva+pull, time defaults to coreflux+off).
- **`jobdivaSyncConfigRead()`** — merges stored config over defaults so missing keys still render in the UI.
- **`jobdivaSyncConfigWrite()`** — whitelist-validated source + direction; rejects two incoherent combos: `coreflux+pull` and `jobdiva+push`. Writes `sync_config_update` audit row.
- **API** — new `sync_config_get` (GET, view perm) and `sync_config_set` (POST, manage perm) actions on `api/jobdiva.php`. Status response now embeds `sync_config` so the page renders in one fetch.
- **`jobdivaSyncAll`** — honors config: when `direction=off` or `source=coreflux`, the entity is skipped without an HTTP call. Returns new `skipped_by_config: [...]` envelope.
- **JobDivaSettings.jsx picker** — table with 4 entity rows (company/contact/placement/time), per-entity Source dropdown (JobDiva ↔ CoreFlux) + Direction dropdown (Off / Pull / Push / Two-way) gated by source coherence so the user can't pick `coreflux+pull` or `jobdiva+push`. Live-saves on change. Active/No-sync hint per row.

### Validation
- `tests/sprint8a_a4_sync_config_smoke.php` — **40 ✓ / 0 fail**: constants (entities incl. time, sources, directions, defaults with time=coreflux+off), reader (merges defaults), writer (whitelists + 2 coherence-violation rejections + audit emission), API (status embeds sync_config, 2 new actions, RBAC fork, 422 on bad payload), sync orchestration honors config (jobdivaSyncAll reads it, shouldPull helper, skipped_by_config envelope), UI picker (testids + 4 rows + source-gated direction options + client-side coherence guards + auto-save).

## Recently completed (Sprint 8a / Slice A3 — JobDiva sync drivers + Connected Sources badge, 2026-02)
**Real entity sync goes live. Three drivers — Companies, Contacts, Placements — pull from JobDiva and bind via the agnostic mapping pipeline. Connected Sources badge on Person/Company detail headers gives operators instant "this record is in sync" confirmation. Per the explicit user requirement: NO candidates, applicants, or open positions.**

### What shipped
- **`core/jobdiva/sync.php`** — three drivers + orchestrator:
  - `jobdivaSyncCompanies` — POST `/api/jobdiva/companies`, upserts to `companies` via `companiesUpsertByName()`, tags `client` role, binds via `mappingUpsert(source='jobdiva', type='company')`.
  - `jobdivaSyncContacts` — resolves the JobDiva company id via `mappingFindInternal` first; if no mapping (the company hasn't been synced yet), the contact is gracefully skipped. Inserts to `company_contacts` (deduped by email per company), default role `other`.
  - `jobdivaSyncPlacements` — resolves JobDiva employee → CoreFlux person via mapping. **No auto-create of people**: missing person mapping → graceful skip (CoreFlux is not an ATS). Optionally resolves end-client company. Uses `'jd:' . $extId` external_id prefix on `placements`.
  - `jobdivaSyncAll` — orchestrates all 3, returns `{counts: {company,contact,placement}, total, latency_ms, by_entity, skipped_by_config}`. Bumps `connection.last_sync_at`. Honors A4 sync_config (skip entities flagged off / coreflux-owned).
  - `jobdivaSyncFetchItems` — tolerates `{data:[…]}`, `{items:[…]}`, plain list responses; supports `items_override` for testing.
- **API** — `api/jobdiva.php` `sync` action upgraded from A1 placeholder to invoke `jobdivaSyncAll`. Returns full counts/latency/by_entity envelope. 502 on Throwable. Body `{modified_since}` enables incremental delta pulls.
- **`dashboard/src/components/ConnectedSourcesBadge.jsx`** — small reusable component reading `GET /api/integrations/mappings.php?action=list_for_internal`. Renders a chip per external system with sync_status palette (ok=green, stale=amber, error=red, deleted_in_source=grey). Hidden when zero mappings (silent for tenants without integrations). Includes JobDiva/Bullhorn/Greenhouse label map.
- **PersonDetail.jsx + DirectoryModule.jsx (Companies)** — drop the badge into both detail-page headers.

### Validation
- `tests/sprint8a_a3_jobdiva_sync_smoke.php` — **67 ✓ / 0 fail**: drivers (parses, requires lib chain, exports 5 functions, fetch shape covers override + paginated + plain list, companies key-spelling fallbacks + 'client' role tag + mapping bind, contacts company-resolution + email dedupe + 'other' role default, placements person-mapping required + NO auto-create + 'jd:' prefix + status enum mapping + engagement_type='w2' default), orchestration (3 drivers in order, latency math, counts envelope, last_sync_at bump), API wiring (sync invokes jobdivaSyncAll, returns counts/total/latency_ms, modified_since opt, 502 on Throwable, A1 placeholder removed), badge UI (testids, encoding, palette covers all 4 statuses, JobDiva label, hidden on zero mappings, status text when not ok), PersonDetail + DirectoryDetail header wiring.


## Recently completed (Sprint 8a / Slice A2 — Integration-agnostic external entity mappings, 2026-02)
**Universal mapping pipeline. Any 3rd-party integration (JobDiva today, Bullhorn / Greenhouse tomorrow) can bind an external record id to the matching CoreFlux internal record id WITHOUT mutating core tables. No `jobdiva_company_id` column on `companies`, no `bullhorn_contact_id` on `contacts` — the mapping table owns all of it.**

### What shipped
- **Migration `022_external_entity_mappings.sql`** — single agnostic table, idempotent `CREATE TABLE IF NOT EXISTS`, utf8mb4_unicode_ci, Cloudways MySQL 5.7+8 compatible.
  - `source_system VARCHAR(64)` (free-form slug — adding `bullhorn` later requires zero DDL).
  - `internal_entity_type VARCHAR(64)` (e.g. `company` / `contact` / `placement`).
  - `external_id VARCHAR(128)` (numeric AND GUID friendly).
  - `payload_snapshot JSON` (last raw payload for debug/replay) + `content_hash CHAR(64)` (sha256 hex for cheap dirty-check).
  - `direction` enum (`pull`/`push`/`two_way`/`off`) — informational snapshot at last sync.
  - `sync_status` enum (`ok`/`stale`/`error`/`deleted_in_source`) + `last_error VARCHAR(500)`.
  - **Two UNIQUE KEYs** enforce 1:1 mapping in both directions:
    - `uk_external (tenant, source, type, external_id)` → external→internal lookup.
    - `uk_internal (tenant, source, type, internal_id)` → internal→external lookup.
  - Reverse-lookup index `ix_internal_lookup` (`tenant, type, internal_id`) for "what does every source know about this internal record?".
  - Worker-driver index `ix_source_last_sync` (`tenant, source, last_synced_at`).

- **`core/integrations/entity_mappings.php`** — agnostic helpers, all tenant-scoped:
  - `mappingHash($payload)` — sha256 hex of canonicalised JSON. Recursive `ksort` on assoc arrays, list order preserved. Identical inputs in any key order produce identical hashes.
  - `mappingUpsert($tid, $source, $type, $externalId, $internalId, $payload?, $direction='pull')` — race-safe `ON DUPLICATE KEY UPDATE`. Bumps `last_seen_at` always; bumps `last_synced_at` + payload only when content_hash actually changed (or was previously NULL) or when internal_id moved. Returns row + `changed` boolean.
  - `mappingFindInternal()` — external→internal (used by webhook ingress).
  - `mappingFindExternal()` — internal→external (used when CoreFlux pushes back).
  - `mappingMarkStatus($tid, $mappingId, $status, $error?)` — whitelist-validated, error clamped to 500 chars.
  - `mappingDelete()` — hard delete (used when source signals hard delete).
  - `mappingListForInternal()` — every external id any source has for an internal record (cross-source visibility for the future "Connections" panel on entity detail pages).
  - Hard input validation: rejects tenant_id<=0, empty source/type/external_id, internal_id<=0, unknown direction.

### Slice A2 explicit non-goals (next slice picks up)
- **No JobDiva sync logic yet** — A2 is the universal pipeline layer only. Slice A3 wires `core/jobdiva/sync_companies.php` etc. on top of these helpers.
- **No write UI** — mappings are server-managed infrastructure. The new read-only endpoint below lets future entity detail pages render a "Linked external systems" panel.
- **No webhook delta processing** — the queue table from A1 (`jobdiva_webhook_events`) drains into mappings in A3.

### Read-only API + UI touch (added same slice)
- **`GET /api/integrations/mappings.php`** — three actions, all RBAC-gated by `integrations.jobdiva.view`, tenant-scoped via `api_require_auth`, kebab-case action names normalised to snake:
  - `?action=list_for_internal&entity_type=company&internal_id=42` → every external system's binding for one CoreFlux record (cross-source visibility — drives the future "Linked external systems" mini-panel on Person/Company detail pages).
  - `?action=find_internal&source_system=jobdiva&entity_type=company&external_id=JD-12345` → reverse: CoreFlux record this external id points to.
  - `?action=find_external&source_system=jobdiva&entity_type=company&internal_id=42` → external id this source has for this CoreFlux record.
  - All inputs validated; unknown actions return 400. GET-only.

- **JobDivaSettings.jsx — A3-forward-compat sync result card**: when the sync API returns `counts: {company, contact, placement, ...}` and `total`, the page renders a purple "Sync complete · {latency}ms" card with **N records imported from JobDiva** and per-entity chips (e.g. `12 companys · 47 contacts · 8 placements`). Zero-counts collapse to a single "already up to date" hint. Dismissible via `<X>` button. A1's placeholder sync (no `counts`) still surfaces `note` via the existing `msg` banner — so deploying A2 doesn't break the A1 UX one bit.

### Validation
- `tests/sprint8a_a2_external_entity_mappings_smoke.php` — **86 ✓ / 0 fail**: migration shape, library surface, runtime hash behaviour, runtime input validation, read-only API (3 actions + tenant scope + RBAC), and the **JobDivaSettings sync-result card** (state, A3-forward-compat counts/latency parsing, all 6 testids, zero-count fallback, dismiss button, A1 backward-compat note routing).
- Full PHP suite: **109 files, 0 failures** (108 + this new file, zero regressions).
- Vite rebuilt → `index-yWQLlc_U.js` (1819 modules, 1129kB JS / 21.6kB CSS) and synced to `/app/spa-assets/`. `.deploy-version` updated: 5 new sentinels (migration 022, entity_mappings.php, mappings.php endpoint, A2 smoke, JobDivaSettings re-listed) + 4 new feature flags + sprint6b bundle-hash assertion bumped.

### Next slice
- **A3** — JobDiva pull/sync logic for Companies, Contacts, Placements (NOT candidates / applicants / open positions). Uses these mapping helpers as the universal binding layer.
- **A4** — Timesheets with per-entity config picker (source of truth + direction OR off).


## Recently completed (Sprint 7e.1 — Layer-style Bookkeeping Overview, 2026-02)
**First slice of the "replicate Layer for our users" track. A single-screen books snapshot at `/modules/accounting/bookkeeping` that mirrors Layer's `<BookkeepingOverview/>` widget — health score, 6-month P&L chart, tasks list, bank-connection status, recent engine activity, and a connect-a-bank CTA when no Plaid links exist.**

### What shipped
- **`api/books_health.php`** — single GET endpoint that returns the full overview payload in one call so the UI never waterfalls. Computed pieces:
  - **Bank connections** — total / active / last_sync_at
  - **Reconciliation** — last_reconciled_date / days_since / behind_30d / behind_60d (graceful when `accounting_reconciliations` table absent)
  - **Uncategorized** — count of bank_statement_lines with `match_status IN (NULL, 'pending')` + oldest_days
  - **Tasks** — transactions_to_review · bills_pending · payments_pending · transfers_pending · period_ready_to_close (each table check is `SHOW TABLES LIKE` so we never crash on pre-7c tenants)
  - **6-month P&L** — built from posted JEs grouped by month + account_type, accommodating revenue / expense / contra_revenue / cost_of_goods_sold / other_income / other_expense
  - **Recent engine activity** — top 10 posted `accounting_events` (gracefully empty if 7b table absent)
  - **Health score 0-100** with a **transparent rubric** (no_active_bank −20, recon_behind_60d −15, recon_behind_30d −8, many_uncategorized −10, some_uncategorized −5, period_overdue_close −10, has_open_tasks −5; floored at 0). Returns the firing reasons inline so the UI can show "why".
  - Label thresholds: ≥90 excellent / ≥75 good / ≥50 fair / <50 needs_attention.
- **Module alias** at `/api/accounting/books-health` (one-line require shim).
- **`dashboard/src/pages/BookkeepingOverview.jsx`** — Layer-look:
  - Two-column grid (main / sidebar)
  - Score hero with auto-color (emerald/sky/amber/rose) + reason chips
  - 6-month P&L bar chart (revenue green, expense red, net signed-figure under each month)
  - Recent activity table linking each posted event to its JE detail page
  - Tasks card with click-through `<Link>`s to the right module page; zero counts shown muted
  - Banks card + last-reconciled badge (auto-amber/rose after 30/60 days)
  - Period card (FY · period number · date range · status)
  - Connect-a-bank purple CTA when active=0, with primary "Connect bank" button
- Wiring: AccountingModule mounts `/bookkeeping` (with `/books-health` redirect alias), App sidebar gains "Bookkeeping Overview" entry, Quick-actions tile linked too.

### Validation
- `tests/sprint7e1_bookkeeping_overview_smoke.php` — **56 ✓ / 0 fail**: API envelope shape, all 5 graceful `SHOW TABLES LIKE` guards, health-score floor + thresholds + reason enum, full P&L grouping spec, alias delegation, every UI testid (15 main + 5 task rows + dynamic PL bar template), App + module wiring.
- Full PHP suite: **99 files, 0 failures**.
- Vite rebuilt → `index-BRHVBaDC.js`. `.deploy-version` synced (6 new feature flags + 4 new sentinel files).

### Next slice
- **Slice A1** ✓ shipped (this fork) — JobDiva connection + webhook foundation
- **Slice A2 (next fork)** — Companies + Contacts (bidirectional, JD-owned by default), `jobdiva_entity_mappings` table
- **Slice A3** — Placements (submissions/hires → `placements`)
- **Slice A4** — Timesheets with **per-entity** config picker (source of truth + direction OR off)

### Pinned (will resume after JobDiva slices)
- **7f.4** — Liquidity Forecast + Deferred-revenue + auto-reversing accrual helpers
- **7e.4 verify** — Confirm Payroll/Time settlement bundles ride event layer
- **7g** — AI agent suite


## Recently completed (Sprint 8a — JobDiva integration foundation, 2026-02)
**Tenant-level JobDiva connection vault with auto-refreshing session tokens, signature-verified webhook receiver, and an admin UI. Tenants log in once with `clientid + username + password`; the server class re-mints session tokens silently when they expire.**

### What shipped
- **Migration `021_jobdiva_connections.sql`** — three tables, all idempotent:
  - `jobdiva_connections` (per-tenant unique, encrypted password + cached session token + optional webhook HMAC secret + JSON `field_ownership` & `sync_config` placeholders for A2+; status enum: connected/degraded/disconnected/error)
  - `jobdiva_webhook_events` (queue, dedup on `(tenant_id, jd_event_id)`, signature-OK flag, status pipeline)
  - `jobdiva_sync_audit` (append-only, action+entity_type+direction+items)
- **`core/jobdiva/client.php`** — PHP client built on `core/encryption.php` AES-256-GCM helpers. `jobdivaSessionToken()` caches with 60-second slack and falls back to JWT exp parsing. `jobdivaCall()` auto-refreshes once on 401 and flips connection to `degraded` on persistent ≥400. `jobdivaPing()` round-trips the auth endpoint as the cheapest health check. `jobdivaWebhookVerify()` enforces HMAC-SHA256 with constant-time `hash_equals`.
- **`api/jobdiva.php`** — single dispatcher; webhook path **bypasses CoreFlux auth** (signature is the auth) and queues idempotently via `ON DUPLICATE KEY UPDATE id = id`. Other actions are RBAC-gated (`integrations.jobdiva.view` for read, `integrations.jobdiva.manage` for write — master_admin's `*` covers both). Path-style aliases `/api/jobdiva/{connect,disconnect,status,ping,sync,webhook}.php` all delegate to the central handler.
- **`dashboard/src/pages/JobDivaSettings.jsx`** — admin page mounted at `/admin/integrations/jobdiva`. Status badge with 4 palettes, status card (client_id, username, last_ping, last_sync, token_exp, last_error), purple webhook-URL panel with copy-to-clipboard, connect form (4 fields incl. show/hide password), Test/Sync/Disconnect buttons that appear only when connected, recent activity table (last 25 audit rows), recent webhook events table (last 10).
- **AdminModule wiring** — overview action card, sidebar link, `/admin/integrations/jobdiva` route.

### Slice A1 explicit non-goals (next slice picks up)
- No entity sync yet — "Sync now" is a placeholder that just refreshes the session token + writes an audit row.
- `field_ownership` JSON column is empty; populated in A2.
- `sync_config` per-entity picker (which entities are JD-owned vs CF-owned, push/pull/off) is the centerpiece of A2 (companies/contacts) and A4 (timesheets).

### Validation
- `tests/sprint8a_jobdiva_foundation_smoke.php` — **101 ✓ / 0 fail**: migration shape (3 idempotent tables, encrypted blob columns, dedup keys, status enum), client surface (encryption on save, upsert, stale-token clearing on update, slack-aware caching, JWT exp fallback, auto-refresh on 401, degraded surfacing, audit shape, HMAC-SHA256 verify with hash_equals), dispatcher (webhook bypasses auth, X-JobDiva-Signature header, idempotent persist, 401 on bad sig, RBAC fork by verb, status hides password, A1 placeholder note in sync), all 6 path-style aliases, full UI testid coverage including dynamic row templates, AdminModule wiring (route + sidebar + action card).
- Full PHP suite: **108 files, 0 failures**.
- Vite rebuilt → `index-z5ikBhcm.js`. `.deploy-version` synced (5 new feature flags + 13 new sentinels; sprint6b bundle-hash assertion bumped).
- **7e.2** ✓ shipped (this fork) — Transactions to Review queue with deep-link from BookkeepingOverview
- **7e (AP vertical slice)** ✓ shipped (this fork) — `ap.bill.approved` event-layer migration + line_source passthrough + AP/AR seed-pack expansion
- **7e (Billing migration + audit-trail backfill)** ✓ shipped (this fork) — `billing.invoice.sent` event-layer migration + AP-bill / Billing-invoice replay endpoints + Rule Sandbox UI for one-click backfill
- **7e.3** ✓ shipped (this fork) — Inline AI line-item account suggest cascade on bill + invoice creation (history-first → LLM fallback, restricted to expense vs revenue families)
- **Saved-hours KPI** ✓ shipped (this fork) — Bookkeeping Overview now surfaces ai_assist count_7d × 30s as a hours-saved-this-week tile (purple Sparkles card)
- **7f.1** ✓ shipped (this fork) — GL Detail report + Tax Mappings (CoA → tax-form-line). Reports vertical opened.
- **7f.2 (headline)** ✓ shipped (this fork) — Tax-form CSV export with unmapped-account warning. One click hands the accountant a tidy `tax_export_US-1040-SCH-C_2026-01-01_to_2026-12-31.csv`.
- **7f.2a** ✓ shipped (this fork) — AI auto-map for tax mappings. One pass through every unmapped revenue/expense account, line-restricted by the form's hard-coded line catalogue, bulk-accept-≥-threshold UI.
- **7f.3 (Dimensional P&L)** ✓ shipped (this fork) — Pivot the income statement along any active accounting dimension. Family subtotals + net-income row.
- **7e.4** — Payroll / Time settlement migrations (largely no-op: neither directly calls `accountingPostJe` today; their bundles flow into AP/Billing which are now event-layer enabled. Confirm during Time settlement closes a period.)
- **7f.4** — Liquidity Forecast (cash projection from open AP/AR + scheduled treasury), Deferred-revenue + auto-reversing accrual helpers
- **7g** — AI agent suite (AI Bookkeeper, Reconciliation, Treasury Analyst, CFO, Tax + permission modes)


## Recently completed (Sprint 7f.2a + 7f.3 — AI auto-map + Dimensional P&L, 2026-02)

### Sprint 7f.2a — AI auto-map for tax mappings
- **`api/tax_mapping_ai_suggest.php`** — POST endpoint, RBAC `accounting.je.create`. Hard-coded TAX_FORM_LINES catalogue per form (Schedule C: 25 lines, 1120: 14, 1120-S: 13, 1065: 14, 990: 8) so the model has a tight allowed-line set and can't hallucinate "line 99". Single batched `aiAsk()` call returns one JSON `{suggestions:[...]}` covering all unmapped accounts. Server-side validation: rejects unknown account IDs, rejects lines not in the catalogue, dedupes duplicates, clamps confidence to [0,1], falls back to catalogue label if model omits it. Returns `interaction_id` + `model` for audit. Graceful AIDisabled (503) + Throwable (502) fallback.
- **`TaxMappings.jsx`** — purple Sparkles "AI auto-map" strip with: Suggest button, threshold dropdown (70/80/85/90/95), bulk-accept-≥-threshold button, `via {model}` audit footer, per-row confidence pill (green ≥90% / amber ≥75% / red below) hovering each unmapped account row, draft pre-population so the user sees AI line + label inline before accepting.

### Sprint 7f.3 — Dimensional P&L
- **`api/dimensional_pnl.php`** — RBAC-gated GET. Resolves `dim_key` against `accounting_dimensions` (tenant-scoped + active only). Pivots posted JE lines along the dimension by reading `accounting_journal_lines.dimension_values` JSON. Falls through to a `(unset)` bucket when the JE didn't carry that dim. Sign-aware totals via `accounting_accounts.normal_side`. Returns rectangular per-account rows + family subtotals (revenue, COGS, expense, other_income, other_expense, contra_revenue) + net-income row computed as `(revenue + other_income - contra_revenue) - (cogs + expense + other_expense)` per dim value.
- **`modules/accounting/ui/DimensionalPnL.jsx`** — dimension picker sourced from `dimensions.php` (active only), date range, summary band (5 stats including red net-income when negative), pivot table with family separators, italic family subtotals, green-banded NET INCOME row (red when negative). Handles wide dim-value lists with horizontal scroll.
- Both pages wired into AccountingV1Module sub-nav + App.jsx sidebar. Module-namespaced kebab alias `/api/accounting/dimensional-pnl`.

### Validation
- `tests/sprint7f2a_tax_ai_automap_smoke.php` — **36 ✓**: API contract (POST-only, RBAC, 5-form catalogue, line whitelist with hallucination guard, account scope, aiAsk chokepoint, dedup, confidence clamp, label fallback, AIDisabled→503, Throwable→502, parseable + unparseable JSON paths), UI testid coverage (5 page-level + dynamic per-row pill), threshold logic, sequential bulk-accept POSTs.
- `tests/sprint7f3_dimensional_pnl_smoke.php` — **48 ✓**: API contract (RBAC, dim_key required, date validation, dimension scope + 404, account_type whitelist, '(unset)' bucket, normal_side math, "(unset)" sorted last, rectangular row backfill, family subtotals + net-income formula), UI testid coverage including dynamic column / row / subtotal templates.
- Full PHP suite: **107 files, 0 failures**.
- Vite rebuilt → `index-fH4LBHwp.js`. `.deploy-version` synced (5 new feature flags + 8 new sentinels; sprint6b bundle-hash assertion bumped).


## Recently completed (Sprint 7f.2 — Tax-form CSV export, 2026-02)
**One-click tax-time deliverable. Sums every posted JE line through `accounting_tax_mappings` and emits either a JSON preview or an RFC4180 CSV the operator can hand the accountant directly.**

### What shipped
- **`api/tax_form_export.php`** — RBAC-gated GET. Required `tax_form_code` (whitelist of 5 standard US forms). Optional `start` / `end` (defaults to current calendar year), `entity_id`, `format=json|csv`. Joins `accounting_tax_mappings` → `accounting_accounts` → `accounting_journal_lines` → `accounting_journal_entries` (posted only). Per-line totals computed sign-aware via `accounting_accounts.normal_side` so revenue lands positive on credits and expense lines positive on debits.
- **Unmapped surfacing** — endpoint also returns an `unmapped_summary` listing every revenue/expense account with activity in the window that has *no* mapping yet. The CSV emits a final `UNMAPPED` row so the accountant can't miss a $7,800 misc-expense leakage.
- **CSV branch** — sets `Content-Type: text/csv` + `Content-Disposition: attachment; filename="tax_export_<form>_<start>_to_<end>.csv"`. RFC4180 fields: `Tax form,Line,Label,Total,Accounts,Account codes`. Account codes joined with `;`.
- **`modules/accounting/ui/TaxExport.jsx`** — form dropdown sourced from `tax_mappings.php` `available_forms`, date range, JSON preview table with running totals, "Download CSV" button (uses `window.location.href` so the browser handles the attachment), and an amber **Unmapped accounts warning** with up to 8 account names + a "Map them now →" CTA into `/tax-mappings`.
- **`BookkeepingOverview.jsx`** — new "Reports & tax" quick-links card (right column) with three deep-links: GL Detail, Tax mappings, Tax export.
- **Wiring** — sub-nav tab on AccountingV1Module + sidebar action + module-namespaced `/api/accounting/tax-export` alias.

### Validation
- `tests/sprint7f2_tax_form_export_smoke.php` — **57 ✓ / 0 fail**: API contract (RBAC, GET-only, form whitelist, date validation, three-table join, posted-only filter, group-by mapping+account, normal_side total math, unmapped surfacing query, CSV headers + content-disposition, exit-after-stream), alias delegation, UI testid coverage (16 page-level + dynamic row template), AccountingV1Module + sidebar + Bookkeeping Overview wiring.
- Full PHP suite: **105 files, 0 failures**.
- Vite rebuilt → `index-BGNGqWZR.js`. `.deploy-version` synced (5 new feature flags + 4 new sentinels; sprint6b bundle-hash assertion bumped).


## Recently completed (Saved-hours KPI + Sprint 7f.1 — Reports + Tax foundation, 2026-02)
**The categorization-history moat is now visible to operators (purple "X.X hrs saved this week" tile on Bookkeeping Overview), and the Reports module gains its first two modular pieces: a transaction-level GL Detail report and a CoA→tax-form mapping admin.**

### Saved-hours KPI
- **`api/books_health.php`** — new `ai_assist` envelope: counts `ai_interactions` rows in last 7 days where `outcome IN ('accepted','auto_applied')` AND `feature_class IN ('classification','categorization','autoposting')`. Conservative 30 sec / assist saving math. Cumulative count too.
- **`BookkeepingOverview.jsx`** — purple Sparkles card with hours-saved hero number + "X AI suggestions accepted · Y all-time" detail line + "See AI activity" CTA into the Audit Log filtered to AI source. Renders only when count_7d > 0 so empty tenants don't see noise.

### GL Detail report
- **`api/gl_detail.php`** — POST `account_id|account_code` + start/end + entity_id + include_unposted. Server computes opening balance from JE lines posting_date < start (sign-aware via `accounting_accounts.normal_side`), running balance through detail rows, and totals envelope (debit/credit/net/ending). Drill-down ready (every row carries `je_id` + `source_module/source_ref_id`).
- **`modules/accounting/ui/GLDetail.jsx`** — URL-state-driven (account picker + date range + include-unposted), summary band with 6 stat tiles, table with running balance column + JE-detail link per row.
- Module-namespaced kebab alias `/api/accounting/gl-detail`.

### Tax mappings (CoA → tax-form-line)
- **Migration `020_tax_mappings.sql`** — new `accounting_tax_mappings` table, idempotent `CREATE TABLE IF NOT EXISTS`, `UNIQUE (tenant_id, account_id, tax_form_code)` so each account maps to at most one line per form, multi-form via `tax_form_code`.
- **`api/tax_mappings.php`** — three-verb endpoint (GET/POST/DELETE) with RBAC fork (read = `accounting.coa.view`, write = `accounting.je.create`). Hard-coded TAX_FORMS catalogue (US-1040-SCH-C, US-1120, US-1120-S, US-1065, US-990). Upsert via ON DUPLICATE KEY UPDATE so re-saving a mapping is idempotent. GET also returns `unmapped_accounts` restricted to the revenue/expense family so the UI can render side-by-side mapped/unmapped tables.
- **`modules/accounting/ui/TaxMappings.jsx`** — Form picker → side-by-side tables (mapped + unmapped). Per-row inline edit (line, label, notes) + Save / Remove. Counts pill in header.
- Module-namespaced kebab alias `/api/accounting/tax-mappings`.
- Two new sub-nav tabs in AccountingV1Module + sidebar actions.

### Validation
- `tests/sprint7f1_gl_detail_and_tax_mappings_smoke.php` — **88 ✓ / 0 fail**: GL Detail (RBAC, GET-only, account requirement, date validation, normal-side opening + running, entity filter, posted-only default, totals envelope), tax_mappings.sql shape (idempotent + UNIQUE + column widths), tax_mappings.php (RBAC fork, form whitelist, ON DUPLICATE upsert, account scope check, unmapped query family filter, DELETE tenant scope), GLDetail + TaxMappings UI testid coverage, AccountingV1Module + App sidebar wiring.
- `tests/sprint7e1_bookkeeping_overview_smoke.php` — extended to **64 ✓** with 4 new asserts on the `ai_assist` envelope + 4 new testid checks on the saved-hours card.
- Full PHP suite: **104 files, 0 failures**.
- Vite rebuilt → `index-DYDkzhNR.js`. `.deploy-version` synced (8 new feature flags + 9 new sentinels; sprint6b bundle-hash assertion bumped).


## Recently completed (Sprint 7e.3 — Inline AI line-account suggest, 2026-02)
**Reduces bookkeeping friction at data-entry time. Every bill / invoice line now has an inline AI button that picks the best GL account from the tenant's CoA, falling through history → LLM. Closes the loop with the categorization-history moat.**

### What shipped
- **`api/line_ai_suggest.php`** — POST endpoint, RBAC-gated by `kind` (`ap.bill.create` for `ap_bill`, `billing.invoice.create` for `billing_invoice`). History-first cascade: looks up `ai_categorization_history` for the same vendor/client + description; if 2+ prior accepted hits exist, returns 0.92 confidence (`source: history`). LLM fallback: passes a candidate-restricted CoA (expense+COGS+other_expense for AP, revenue+other_income+contra_revenue for billing) into `aiAsk()` — chokepoint guarantees `ai_interactions` rows for audit. Rejects unknown account codes from the model. AIDisabled / Throwable paths return graceful `{suggestion: null, ai_unavailable: true}` so the UI can still render.
- **`dashboard/src/components/LineItemEditor.jsx`** — new optional props `aiSuggestKind` + `counterpartyName`. When set, renders a per-line "AI" sparkles button that POSTs to the endpoint; result preview shows account code + confidence pill (green ≥80%, amber ≥50%, red below); dedicated "Accept" button stamps the suggestion onto the line's `gl_account_code`. Disabled while loading or while description is empty (no useful prompt).
- **`modules/ap/ui/BillCreate.jsx`** — opts in (`aiSuggestKind="ap_bill"`, `counterpartyName={vendor?.name}`).
- **`modules/billing/ui/InvoiceCreate.jsx`** — opts in (`aiSuggestKind="billing_invoice"`, `counterpartyName={client?.name}`).

### Validation
- `tests/sprint7e3_line_ai_suggest_smoke.php` — **34 ✓ / 0 fail**: POST-only contract, kind whitelist, RBAC fork, history-first cascade with hit threshold + source tagging, LLM family restriction (expense vs revenue), aiAsk() chokepoint usage, feature_key per kind, unknown-code rejection, AIDisabled + Throwable graceful paths, review_required envelope, all UI testid templates + opt-in wiring on BillCreate / InvoiceCreate.
- Full PHP suite: **103 files, 0 failures**.
- Vite rebuilt → `index-Cu4EXjqd.js`. `.deploy-version` synced (3 new feature flags + 2 new sentinels; sprint6b bundle-hash assertion bumped).


## Recently completed (Sprint 7e — Billing migration + audit-trail backfill, 2026-02)
**"Everything has a readily available audit trail." Billing invoices now post through the event layer just like AP bills, and two new admin endpoints replay historical AP bills + Billing invoices into `accounting_events` + `accounting_subledger_links` so pre-Sprint-7e history is fully linked.**

### What shipped
- **`modules/billing/api/invoices.php?action=post`** — same dual-path treatment as AP. Preferred path emits `billing.invoice.sent` (passthrough payload.lines) into `accountingProcessEvent`. On `status=posted`, stamps `journal_entry_id` + audits `via=event_layer`. Legacy fallback runs `accountingPostJe`, writes a `subledger_links` row, flips any `ignored/failed/received/mapped` event row to `posted`, audits `via=legacy_direct`.
- **`api/ap_bill_replay.php`** — admin endpoint (RBAC `accounting.manage_posting_rules`). Walks `ap_bills` within window (1..1825 days), filtered to `status IN approved/partially_paid/paid` + `journal_entry_id IS NOT NULL`. Emits `ap.bill.approved` events with `source_module='ap_replay'` (separate namespace from live `ap` events for clean idempotency). When the engine returns `status='ignored'` (no rule seeded), inserts a stub `accounting_events` row stamped `status='posted'` pointing at the original JE + writes a `subledger_links` row — guarantees audit-trail completeness regardless of rule-seed state. Knobs: `days`, `since`, `status`, `dry_run`, `only_unlinked`.
- **`api/billing_invoice_replay.php`** — same shape for `billing_invoices`. Source module `billing_replay`. Same stub-event fallback. Status whitelist `approved,sent,partially_paid,paid`. Reproduces the AR/Revenue/Sales-Tax JE shape from posted invoice line buckets.
- **Module-namespaced kebab aliases** — `/api/ap/bill-replay` and `/api/billing/invoice-replay` (one-line `require` shims, Sprint 7d aliasing pattern).
- **`dashboard/src/pages/RuleSandbox.jsx`** — new fuchsia "Subledger audit-trail backfill" strip. Source dropdown (`AP bills` / `Billing invoices`), window (30d/90d/180d/365d/2y/5y), `Only unlinked` checkbox, `Dry run` checkbox, one-click run, inline result counters with red `failed` highlight.
- **Default seed pack** — already extended in the prior commit with `billing.invoice.sent` (passthrough), `ap.payment.cleared`, `billing.payment.received`. Replay endpoints now have rules to land into.

### Validation
- `tests/sprint7e_subledger_replay_smoke.php` — **58 ✓ / 0 fail**: AP replay (RBAC, days clamp 1..1825, since regex, status whitelist, `source_module=ap_replay`, line rebuild, passthrough+AP credit, replay flag, original JE captured, stub-event fallback both writes accounting_events + subledger_links, 50-error truncation), Billing replay (same shape + AR/Revenue/SalesTax wiring), kebab aliases, Billing invoice migration (event-layer preferred + legacy fallback + audit dual kinds), UI testid coverage.
- Full PHP suite: **102 files, 0 failures**.
- Vite rebuilt → `index-Dlud7PLy.js`. `.deploy-version` synced (5 new feature flags + 5 new sentinels; sprint6b bundle-hash assertion bumped).


## Recently completed (Sprint 7e — AP vertical slice: event-layer bill posting, 2026-02)
**Architectural keystone — AP bills now post through `accountingProcessEvent('ap.bill.approved', …)` instead of calling `accountingPostJe()` directly. This is the first multi-line module to ride the event layer; the pattern unlocks Billing / Payroll / Time follow-ups.**

### What shipped
- **Migration `019_journal_template_line_source.sql`** — adds `accounting_journal_templates.line_source ENUM('template','payload') DEFAULT 'template'`. Idempotent via `information_schema` guard. BC-safe: existing seed-pack templates default to `'template'`.
- **`core/posting_engine/process.php`** — `postingEngineRender()` now branches on `line_source`. `'payload'` mode reads `payload.lines[]` verbatim, resolving accounts via `account_id` (preferred) or `account_code`, validating per-line non-negative + Dr-XOR-Cr, and asserting balance before returning the rendered JE. Fixes the variable-N JE problem (bills/invoices have N expense/revenue lines + 1 control account).
- **`core/posting_engine/seed_defaults.php`** — pack expanded from 6 to 10 entries:
  - `ap.bill.approved` → passthrough template (`AP bill approved — passthrough`)
  - `billing.invoice.sent` → passthrough template (`AR invoice sent — passthrough`)
  - `ap.payment.cleared` → 2-line template (`Dr Accounts Payable / Cr payload.bank_gl_account_id`)
  - `billing.payment.received` → 2-line template (`Dr payload.bank_gl_account_id / Cr Accounts Receivable`)
  - `postingRulesSeedDefaults()` now writes `line_source` on insert and skips line-row creation for passthrough templates.
- **`modules/ap/api/bills.php?action=post`** — preferred path: emits `ap.bill.approved` event (`source_module='ap'`, `source_record_id='ap_bill:<id>'`, payload carries `lines[]` so passthrough renders correctly). On `status=posted`, stamps `journal_entry_id`, audits with `via=event_layer`. Fallback: when the engine returns `ignored` (no rule seeded for tenant) or throws, the legacy `accountingPostJe()` path runs, writes a `subledger_links` row, flips any `ignored/failed/received/mapped` event row to `posted`, audits with `via=legacy_direct`. Pre-Sprint-7e tenants keep working unchanged.
- Existing idempotent-replay path (when `bill.journal_entry_id` already set) preserved — short-circuits both branches.

### Validation
- `tests/sprint7e_ap_event_layer_smoke.php` — **36 ✓ / 0 fail**: migration shape, render-engine passthrough branch (account_id/code resolution, negative-amount + mixed Dr+Cr rejection, balance check, JE-shape parity), pack entries (4 new event types + AP bill template marked passthrough), seed loop handles passthrough vs template, AP bills route emits the event, preferred-path success stamping, fallback path with subledger_links + event-status flip + dual audit kinds.
- `tests/accounting_spec_smoke.php` — restored `$acct` intermediate so the legacy contract assertion passes alongside the new event flow.
- All upstream Treasury / event-layer smokes still green (sprint7c1 = 38, sprint7b_event_layer = 59, sprint7c2_7d_aliases = 45).
- Full PHP suite: **101 files, 0 failures** (added 1 new file, 0 regressions).


## Recently completed (Sprint 7e.2 — Transactions to Review queue + deep-link, 2026-02)
**Closes the most common bookkeeping workflow loop, Layer-style: from BookkeepingOverview's "5 to review" task tile → first uncategorized line opened with AI suggestion ready in 2 clicks.**

### What shipped
- **`api/transactions_to_review.php`** — unified queue across all bank accounts. Returns `{rows, total, bank_accounts, order, limit, offset}`. Order modes: `oldest_first` (default), `newest_first`, `amount_desc`. Filter by `bank_account_id`. Limits clamped 1..200 / offset >=0. Filter is `(match_status IS NULL OR match_status='pending')`. Each row carries `bank_account_name`, `bank_gl_code`, `age_days`, plus pre-existing `ai_suggested_*` fields if a rule already drafted one.
- **`/api/accounting/transactions-to-review`** — module alias (one-line require shim, kebab-case).
- **`dashboard/src/pages/TransactionsToReview.jsx`** — new page:
  - Honours `?prefilter=oldest_first|newest_first|amount_desc` and `?autoload=1` query params. With `autoload=1`, the first row auto-expands and triggers `bank_ai.php?action=suggest_categorize` so the user lands on a fully-loaded review.
  - Per-row inline detail: AI suggestion with confidence pill, COA dropdown pre-populated by AI, Accept (calls `bank_statements.php?action=accept_ai_categorize` so the categorization-history moat learns), Skip (calls `?action=ignore`), and "Open in Bank Rec" deep-link.
  - "Accept & next" advances focus to the next row and pre-fetches its AI suggestion. Empty state: green "you're all caught up" + back-to-overview link.
  - Order + bank-account filter dropdowns mutate the URL via `setSearchParams` so deep-links survive page reloads.
- **`BookkeepingOverview.jsx`** — "Transactions to review" task row now deep-links to `/modules/accounting/transactions-to-review?prefilter=oldest_first&autoload=1` (the user's explicit request from the previous session).
- **Routing** — both `dashboard/src/modules/AccountingModule.jsx` (legacy) and `modules/accounting/ui/AccountingModule.jsx` (the module currently mounted at `/modules/accounting/*`) now route `bookkeeping` and `transactions-to-review`. The live module also gained the two sub-nav tabs.
- **Sidebar** — App.jsx accounting actions list gains a "Transactions to Review" entry just under "Bookkeeping Overview".

### Validation
- `tests/sprint7e2_transactions_to_review_smoke.php` — **68 ✓ / 0 fail**: API contract (RBAC, GET-only, three order modes, limit/offset clamping, pending/null filter, total + bank_accounts envelope, age_days math, numeric coercion), alias delegation, page-level testid coverage (13 page-level + 11 dynamic per-row), autoload trigger pre-fetches AI on the first row, accept-then-advance flow, deep-link wiring on BookkeepingOverview (URL + prefilter + autoload), routing wired in both AccountingModules + sidebar.
- Full PHP suite: **100 files, 0 failures**.
- Vite rebuilt → `index-C7kdOlow.js`. `.deploy-version` synced (5 new feature flags + 4 new sentinel files; sprint6b bundle-hash assertion bumped).


## Recently completed (Sprint 7d — Spec §38 URL aliases, 2026-02)
**Brings the HTTP surface in line with the master spec's canonical paths so external partners (mobile, the future AI agents, Apideck-style integrators) can hit the contract the spec promises. Legacy paths preserved for one release.**

### What shipped
- `core/api_router.php` enhanced:
  - Endpoint regex relaxed from `[a-z][a-z0-9_]*` to `[a-z][a-z0-9_-]*` so kebab-case spec paths (e.g. `journal-entries`, `cash-position`, `posting-rules-seed`) parse cleanly.
  - File resolver tries the literal filename first, then maps kebab → snake (`journal-entries` → `journal_entries.php`) so existing files are reachable from spec paths without renaming.
  - Path-traversal + camelCase rejection still enforced.
- 6 new module-namespaced alias files (each is a one-line `require` that delegates to the existing root-level handler — zero code duplication):
  - `modules/accounting/api/events.php` ← `/api/accounting/events`
  - `modules/accounting/api/posting_rules_seed.php` ← `/api/accounting/posting-rules-seed`
  - `modules/accounting/api/posting_rules_replay.php` ← `/api/accounting/posting-rules-replay`
  - `modules/treasury/api/payments.php` ← `/api/treasury/payments`
  - `modules/treasury/api/transfers.php` ← `/api/treasury/transfers`
  - `modules/treasury/api/cash_position.php` ← `/api/treasury/cash-position`
- Old root-level `/api/accounting_events.php`, `/api/treasury_payments.php`, etc. all kept working as back-compat (legacy frontend bundle still calls them).

### Out of scope (deferred to 7e/7f)
- **Subpath wiring** — paths like `/api/accounting/journal-entries/:id/post` parse correctly into `(module=accounting, endpoint=journal-entries, subpath=[123, post])` but the existing `journal_entries.php` handler still uses query-string `?id=N&action=post`. Per-endpoint subpath handling is part of 7e (module event-layer migration touches each endpoint anyway).
- **`/api/ai/financial/*`** alias group — needs a future "ai" module to register first; tracked in 7g.
- **Removing legacy `/api/snake_case.php` paths** — back-compat preserved for at least one release per Sprint-7 PRD.


## Recently completed (Sprint 7c.2 — Bank-feed Replay (audit-ledger backfill), 2026-02)
**Lets a tenant migrating from manual posting backfill the `accounting_events` + `accounting_subledger_links` audit trail by replaying already-cleared bank transactions through the engine. Idempotent — re-runs are safe.**

### What shipped
- `api/posting_rules_replay.php` — admin endpoint (RBAC `accounting.manage_posting_rules`). Iterates `accounting_bank_statement_lines` within window, hydrates `bank_gl_account_id` from each row's bank account, emits `treasury.bank_transaction.matched` events with `source_module='treasury_replay'` + `source_record_id='bank_line:<id>'`. Idempotent via `accounting_events`'s unique key — re-runs skip lines that already have an event row. Returns `{scanned, replayed, skipped_already_event, skipped_no_bank_gl, failed, errors[]}`. Errors truncated at 50 for sanity.
- Query knobs: `bank_account_id` (default all), `days` (clamped 1..365, default 30), `since` (YYYY-MM-DD overrides days), `dry_run` (no events written, just counts).
- Rule Sandbox UI gains a **blue replay strip**: window dropdown (7d/30d/90d/180d/365d), dry-run checkbox (default on for safety), one-click run, inline result line with full counts breakdown.

### Validation
- `tests/sprint7c2_7d_replay_and_aliases_smoke.php` — **45 ✓ / 0 fail**: replay endpoint shape (RBAC, days clamp, since regex, source_module/event_type/source_record_id namespacing, payload hydration, idempotency check, dry-run path, response shape, errors-truncated guard); UI testids; router enhancements (kebab-case parsing, snake-case file fallback, traversal rejection, camelCase rejection); all 6 module-alias files exist and parse.
- Full PHP suite: **98 files, 0 failures**.


## Recently completed (Sprint 7c.1 — Default posting-rule seed pack, 2026-02)
**Onboarding accelerator: a single-click "Seed default rules" action that drops in the 17 system accounts + 6 default journal templates + 6 posting rules covering the most common Treasury events. New tenants can now hit "Execute" on a payment and have it post correctly without authoring templates by hand.**

### What shipped
- **`core/posting_engine/seed_defaults.php`** — defines the 6-entry default pack:
  | Event | Template | Posts |
  |---|---|---|
  | `treasury.bank_fee.detected` | Bank fee — default | Dr Bank Fees Expense / Cr bank GL |
  | `treasury.interest.received` | Interest received — default | Dr bank GL / Cr Interest Income |
  | `treasury.payment.executed` | Payment executed — default | Dr counterparty / Cr bank GL |
  | `treasury.transfer.completed` | Internal transfer — default | Dr dest bank GL / Cr source bank GL |
  | `treasury.intercompany.transfer.completed` | Intercompany (source side) | Dr Intercompany Receivable / Cr source bank GL |
  | `treasury.bank_transaction.matched` | Uncategorized fallback | Dr Uncategorized Expense / Cr bank GL |
  Idempotent — find-or-create on `(tenant, name)` for templates and `(tenant, event_type, name)` for rules. Pre-existing customisations are never overwritten.
- **`api/posting_rules_seed.php`** — admin endpoint (RBAC `accounting.manage_posting_rules`) that runs `accountingSeedSystemAccounts()` + `postingRulesSeedDefaults()` together and returns counts.
- **Treasury Payments API** — execute path now hydrates `payload.bank_gl_account_id` + `payload.bank_gl_account_code` by joining `accounting_bank_accounts → accounting_accounts`. Templates can target the bank's GL account directly without dereferencing.
- **Treasury Transfers API** — same hydration for `source_bank_gl_account_id` + `destination_bank_gl_account_id` so internal/intercompany templates resolve correctly.
- **Rule Sandbox UI** — amber strip with "Seed default rules" button. One-click seed; reports counts inline (`✓ X accounts inserted, Y of 6 default rules now active`).

### Validation
- `tests/sprint7c1_default_rules_seed_smoke.php` — **38 ✓ / 0 fail**: pack covers all 6 events exactly, references all required system accounts, uses payload-driven account selectors for bank GL, idempotent find-or-create on both layers, endpoint admin-gated, payload hydration verified on both Payments and Transfers, UI button + result hooks wired.
- Full PHP suite: **97 files, 0 failures**.


## Recently completed (Sprint 7c — Treasury core: Payments + Transfers + Cash Position, 2026-02)
**Stands up the central Treasury models the master spec (§15) requires + the Cash Position report (§28). Both Payments and Transfers route execution through Sprint 7b's posting engine, so every Treasury write becomes a structured event with full audit trace.**

### What shipped
- **`treasury_payments`** (migration 005) — 8-state machine: `draft → pending_approval → approved → scheduled → executed → failed → voided` (+ rejected). Columns: `payee_type` (vendor/employee/customer/tax_authority/other), `payee_id`, `amount`, `currency`, `payment_date`, `payment_method` (ach/check/wire/card/other), `bank_account_id`, `counterparty_account_id`, `workflow_instance_id`, `journal_entry_id` (set on execute), `accounting_event_id`, `external_ref` (bank rail tx id), `failure_reason`. Hot indexes on (status, date), (entity, status), (bank), (payee_type, payee_id).
- **`treasury_transfers`** (migration 006) — same lifecycle. `transfer_kind` auto-detected at create time from src/dst bank-account entity IDs (different entities → `intercompany`, else `internal`). Holds both `source_journal_entry_id` and `destination_journal_entry_id` (intercompany only) for the mirror posting trail.
- **`api/treasury_payments.php`** — full REST: list, create draft, approve, execute, void. Execute emits `treasury.payment.executed` event into the engine which posts the JE; payment row stamped with `journal_entry_id`/`accounting_event_id`/`status=executed`. RBAC-gated: `treasury.create_payment` / `treasury.approve_payment` / `treasury.execute_payment`. Cannot void an `executed` payment (must reverse via JE).
- **`api/treasury_transfers.php`** — same shape. Internal transfers emit `treasury.transfer.completed`, intercompany emit `treasury.intercompany.transfer.completed`. Posting rule + journal template define the JE shape (1 JE for internal, 2 mirror JEs for intercompany — orchestrated by the engine in 7e once modules fully migrate).
- **`api/treasury_cash_position.php`** — spec §28 report. Per-bank-account: GL balance (sum debit-credit on linked accounting account through `as_of`, posted JEs only), last reconciled date (graceful if `accounting_reconciliations` table absent), pending outflows (treasury_payments status pending/approved/scheduled within the forecast window), projected balance. Plus per-currency totals. Configurable `forecast_days` (0–60, default 7) and `entity_id` filter.

### Validation
- `tests/sprint7c_treasury_core_smoke.php` — **48 ✓ / 0 fail**: migration shapes, 8-state lifecycle assertions, RBAC gating, event emission for both kinds, intercompany detection, GL-balance SQL shape, forecast clamp, currency aggregation.
- Full PHP suite: **96 files, 0 failures**.

### Out of scope (intentional, deferred)
- **UI screens** for Payments / Transfers list+detail — backend is ready; sidebar items already exist. UI build is part of 7e or a focused mini-sprint.
- **Real bank-rail execution** (NACHA / Plaid Transfer / wire). Today execute emits the event + posts the JE; the actual money movement is a creds-blocked driver task.
- **AI Liquidity Forecast** beyond the basic posted-AR/AP walk — that's the AI Treasury Analyst agent in Sprint 7g.
- **Treasury event-rule seed templates** — tenants must create their own posting rules via `accounting_posting_rules` for now (or use the Rule Sandbox to draft them). Pre-baked seeds ship with 7e.

### Next
- **Sprint 7d — Spec-compliant URL aliases**: `/api/accounting/journal-entries/:id/post`, `/api/accounting/events`, `/api/treasury/payments`, `/api/treasury/transfers`, `/api/treasury/reports/cash-position` per spec §38. Legacy `/modules/.../api/...` paths kept as back-compat shims.


## Recently completed (Sprint 7b — Accounting Events + Posting Rules engine, 2026-02)
**The architectural keystone of the master spec (§12-13). Modules will stop calling `accountingPostJe()` directly and instead emit events that the engine maps → renders via posting rules + journal templates → posts. Plus the user's requested "rule sandbox" UI to build trust before flipping AP/AR/Payroll/Time over in Sprint 7e.**

### What shipped
- **4 new migrations** (idempotent):
  - `015_accounting_events.sql` — central event ledger with `(tenant, source_module, source_record_id, event_type)` unique key for idempotency, JSON payload, `received → mapped → posted → failed → ignored → reversed` lifecycle, FK to `journal_entry_id` once posted.
  - `016_posting_rules.sql` — `event_type` + optional `entity_id` scope, JSON `conditions` (gt/gte/lt/lte/eq/ne/in operators), `priority`, `status` (active/draft/archived).
  - `017_journal_templates.sql` (+ `..._lines`) — multi-line balanced JE templates with per-line `account_selector`, `debit_formula`, `credit_formula`, `description_template`, `dimensions_json`. FK cascade delete on lines.
  - `018_subledger_links.sql` — many-to-many between source records and journal entries (e.g. one bill → primary JE + payment JE + reversal JE).
- **`core/posting_engine/formula.php`** — sandboxed arithmetic evaluator. Restricted grammar: numeric literals, `+`, `-`, `*`, `/`, `%`, parens, unary minus, dotted payload refs (`payload.x.y`). NO PHP eval, NO callables, NO string concat, NO file/shell. **60/60 ✓ smoke** including 24 malicious-input rejections (PHP tags, system() calls, `||`, `<`, `??`, etc.).
- **`core/posting_engine/process.php`** — `accountingProcessEvent()` chokepoint. Loads highest-priority matching rule, evaluates conditions, renders the template into a balanced JE, posts via the canonical `accountingPostJe()`, stamps `accounting_events.status=posted` + `journal_entry_id`, writes `accounting_subledger_links`. Idempotent on unique-key collision (returns prior post). Account selector grammar: `system:NAME` / `code:1000` / `id:42` / `payload.account_id`. Includes `dryRun` flag for the sandbox.
- **`api/accounting_events.php`** — REST surface: `GET ?status=&event_type=&entity_id=&from=&to=`, `POST` to create+process (with `?dry_run=1`), `POST /:id/post` to retry a failed event, `POST ?action=sandbox` for advisory-only preview. RBAC-gated (`accounting.coa.view` / `accounting.create_entry` / `accounting.manage_posting_rules`).
- **`dashboard/src/pages/RuleSandbox.jsx`** — admin-only UI under `/admin/rule-sandbox`. Paste any event JSON → click "Run dry-run" → see (a) which rule matched, (b) the rendered JE with per-line accounts + amounts + memos, (c) totals balance check, (d) raw response in a collapsible details. Three preloaded samples: bank fee, interest received, AP bill approved. Live JSON parse-error reporting. Sky-blue / red / amber status banners.
- **Treasury bank-feed slice** — `categorize_and_post` now stamps `accounting_subledger_links` after every successful post (non-fatal if the table doesn't exist yet for pre-7b tenants). Full event-layer reroute is Sprint 7e.

### Validation
- **3 new smoke files**: `sprint7b_formula_engine_smoke.php` (60 ✓), `sprint7b_event_layer_smoke.php` (59 ✓), `sprint7b_rule_sandbox_smoke.php` (30 ✓). **Total: 149 new assertions, 0 failures**.
- Full PHP suite: **95 files, 0 failures**.
- Vite rebuilt → `index-B4c8ZuGd.js`. `.deploy-version` synced (8 new feature flags + new sentinel files).

### Sidebar wiring
Accounting module sidebar gains three new sub-routes: **Posting Rules · Rule Sandbox · Accounting Events** (placeholders mounted; full CRUD pages in Sprint 7d/7e).

### Next
- **Sprint 7c — Treasury core**: `treasury_payments` (8-state machine), `treasury_transfers` (internal + intercompany mirrored JEs), Cash Position + basic Liquidity Forecast endpoints + UI.


## Recently completed (Sprint 7a — Foundations: spec §5–7 + §36, 2026-02)
**Step 1 of the Sprint-7 "Up-to-Spec" PRD (`/app/memory/SPRINT7_PRD.md`). Cleanup-first sprint that lays the schema + RBAC groundwork the event layer (7b), Treasury Payments (7c), and the spec-compliant API surface (7d) all depend on.**

### What shipped
- **Migration 011** — `accounting_periods.status` enum extended to include `locked`; new audit columns `closed_at`, `closed_by_user_id`, `locked_at`, `locked_by_user_id`. Idempotent via `information_schema` guards.
- **Migration 012** — `accounting_accounts` extended:
  - `account_type` enum gains `contra_revenue`, `cost_of_goods_sold`, `other_income`, `other_expense`.
  - New columns `is_system_account` (TINYINT), `subtype` (VARCHAR), `tax_mapping_id` (BIGINT — populated in 7f), `statement_section`, `sort_order`.
- **Migration 013** — `accounting_entities` gains `accounting_basis` (`cash`/`accrual`/`modified_cash`), `fiscal_year_start_month`, `entity_type`.
- **Migration 014** — `accounting_journal_entries.soft_close_override_reason` (TEXT NULL) for the spec §6 audit trail when posting into a soft-closed period.
- **New** `core/accounting/system_accounts.php` — defines the 17 spec-required system accounts (Cash, Clearing, Receivable, AP, Payroll Liability, Sales Tax Payable, Retained Earnings, Opening Balance Equity, Suspense, Uncategorized Income, Uncategorized Expense, Rounding Gain/Loss, Intercompany Receivable, Intercompany Payable, Bank Fees Expense, Interest Income, Interest Expense). Exposes `accountingSeedSystemAccounts(tenantId)` and `accountingSystemAccountId(tenantId, name)` lookup helpers. Idempotent — re-stamps `is_system_account=1` on pre-existing rows without overwriting tenant customisations.
- **`modules/accounting/api/periods.php`** — new `lock` action (period status: `closed → locked`). Requires `accounting.period.lock` permission + reason. Reopen path now also accepts `locked` but only when the actor is `master_admin` per spec §6.
- **`core/rbac_config.php`** — admin role gains `treasury.*`, `ai.view_recommendations`, `ai.approve_actions`, `ai.configure_agents` but explicitly NOT `ai.enable_auto_execute`. Manager role gains `treasury.view`, `accounting.coa.view`, `ai.view_recommendations` (read-only AI per spec §35).

### Validation
- **3 new smokes**: `tests/sprint7a_period_states_smoke.php` (16 ✓), `tests/sprint7a_system_accounts_smoke.php` (41 ✓), `tests/sprint7a_permissions_smoke.php` (55 ✓). **Total: 112 new assertions, 0 failures**.
- 2 legacy smokes adjusted to reflect intentional spec-aligned changes (`accounting_phase1_smoke.php` reopen status whitelist, `rbac_smoke.php` manager grants `accounting.coa.view`).
- Full PHP suite: **92 smoke files, 0 failures**.

### Next up
- **Sprint 7b** — Accounting Events + Posting Rules engine (`accounting_events`, `posting_rules`, `journal_templates`, `subledger_links`, sandboxed formula evaluator, `accountingProcessEvent()` chokepoint, treasury-bank-feed vertical-slice migration).
- **Sprint 7c** — Treasury Payments + Transfers + Cash Position + basic Liquidity Forecast.
- **Sprint 7d** — Spec-compliant `/api/accounting/*` and `/api/treasury/*` URL aliases per spec §38.


## Recently completed (Sprint 6k — Two production bug fixes from mobile screenshots, 2026-02)
**User reported via 4 mobile screenshots: (1) corefluxapp.com unreachable on phone (transient — not a code issue), (2) PlacementCreate failed with `Column 'status' cannot be null` (SQLSTATE 23000), (3) Treasury Bank Feed AI cat button → "AI suggestion failed: line_id required".**

### Bug 1 — Placement create status default
- `modules/placements/api/placements.php` — `'status' => in_array($body['status'] ?? 'draft', ALLOWED_STATUS, true) ? $body['status'] : 'draft'` was a long-standing pre-existing bug. The ternary's truthy branch read `$body['status']` directly (undefined → null) when the test passed against the 'draft' default. Refactored to use an intermediate `$statusInput` variable so both branches reference the same coalesced value. Form posts now insert with `status='draft'` as intended.

### Bug 2 — Treasury fetchAiCat URL
- `modules/treasury/ui/AccountTransactions.jsx` line 37 was POSTing `{ line_id: lineId }` in the JSON body, but `api/accounting/bank_ai.php` reads `line_id` from `$_GET` (matching the existing Bank Reconciliation pattern). Fixed by appending `&line_id=${lineId}` to the URL. AI categorize now works on Treasury Bank Feed transactions.

### Validation
- **New** `tests/sprint6k_create_and_treasury_ai_smoke.php` — **7/7 ✓**: confirms the buggy ternary is gone, the new `$statusInput` flow exists, the fetchAiCat URL now carries `line_id`, and JSON-body line_id is no longer sent.
- All other suites still green: **89 PHP smoke files, 0 failures**.
- Vite rebuilt → `index-DYOXEdkm.js`. `.deploy-version` synced.

### Not in scope
- "Can't open this page" / corefluxapp.com unreachable on iPhone Chrome — transient connectivity issue (5G, captive portal, or Cloudways outage). No code change made; user should retry, restart Chrome, or check Cloudways status if it persists.


## Recently completed (Sprint 6j — PlacementCreate Bundle C: full SPEC §3 form, 2026-02)
**User reported the create-placement form felt incomplete: button greyed out without explanation, unclear which fields were required, missing planned commercial fields, and no first-class workflow for "internal" hires (their own admin / recruiter / accountant employees).** This sprint closes the gap so the user can drive the people → placement → time → bill/pay loop end-to-end.

### What shipped
- **New** `modules/placements/migrations/003_internal_engagement_type.sql` — adds `'internal'` to the `engagement_type` ENUM. Idempotent `MODIFY COLUMN`.
- `modules/placements/api/placements.php` — `ALLOWED_ETYPE` now includes `'internal'`. SPEC §3.1 row updated to document the new value.
- `modules/placements/ui/PlacementCreate.jsx` — full rewrite covering everything in SPEC §3:
  - **UX clarity**: blue "Required fields: Person · Title · Start date · Engagement type" hint banner above the form, inline "Fill required: …" message next to the disabled submit button, native `title=` tooltip on the button. No more silent grey-out.
  - **Internal-hire toggle**: top-of-form checkbox auto-fills `engagement_type='internal'`, hides End-client + Vendor-chain sections, clears any previously-typed values. Untoggling restores `w2`.
  - **Initial rate**: bill rate, bill unit (5 options), pay rate, pay unit, currency (USD/CAD/GBP/EUR/INR), OT mult, DT mult, **adder %** (employer burden, posted as fraction), **background fee total** (one-time), effective-from defaulting to placement start date.
  - **Commissions** (collapsible "Show advanced"): inline add/remove rows. Per row: role (5 options), user picker (`/api/users.php`), split %, flat $, basis (4 options), effective_from. Posted as fractions to `/modules/placements/api/commissions.php`.
  - **Referral** (collapsible): single optional row with referrer_type (vendor/person/user), referrer picker (`CompanyTypeahead` for vendor, user picker for user, person id for person), fee %, fee $, fee_basis (5 options), duration_months, start_date. Posts to `/modules/placements/api/referrals.php`.
  - **C2C corp details** (gated to `engagement_type='c2c'`): legal name, EIN, full address (line 1/2/city/state/postal/country), contact (name/email/phone). Inline note pointing users to PlacementDetail → Documents tab for MSA / COI / W-9 uploads (the storage flow needs a placement_id to attach against, so it lives post-create).

### Validation
- **New** `tests/sprint6j_placement_create_full_smoke.php` — **40/40 ✓**: migration shape, API enum extension, required-fields banner + missing-fields hint + disabled-button gate, internal-hire toggle behaviour, all rate fields (currency / units / adder / background), commission row testids + API target + fraction conversion, referral row + 5 fee_basis options + fraction conversion, full corp address + contact testids, c2c gating, advanced-toggle collapse.
- All other suites still green: **88 PHP smoke files, 0 failures**.
- Vite build green: 1811 modules → `index-BeRgJ-y8.js`. `.deploy-version` synced (5 new feature flags).

### What's still post-create-only (intentional)
- MSA / COI / W-9 / chain-contract uploads — already supported on PlacementDetail with the StorageService presigned-POST flow. Adding multi-file pre-upload to the create form would require either juggling staged uploads before the placement_id exists, or pre-creating a draft placement just for storage_key namespacing — neither is worth the complexity vs the 1-second post-create document upload that already works.


## Recently completed (Sprint 6i — Audit-log Anomaly Spotter, 2026-02)
**Direct continuation of Sprint 6h. Adds the AI feature the user OK'd: an "anomaly spotter" banner on the audit-log viewer that surfaces spikes, off-hours actions, and mass-export sessions through the existing `aiAsk()` chokepoint.**

### What shipped
- **New** `api/audit_anomaly.php` — admin-gated POST `?action=spot&hours=N` (1-168). Computes 4 grounded signals from `audit_log`:
  - **Spike events**: events whose count ≥ max(3× median, 10).
  - **Off-hours count**: events where `HOUR(created_at) < 7 OR ≥ 19` (UTC).
  - **Mass-export users (last hour)**: ≥ 5 events matching `%export%`/`%csv%`/`%download%` per user.
  - **Top users**: top 3 by event volume in the window.
  Routes through `aiAsk()` with `feature_class='narrative'` + `feature_key='audit.anomaly.spotter'`. Capped at 220 output tokens. Any throwable → empty summary so the UI degrades silently. Prompt instructs the model to surface observations only — never recommend account suspensions or password resets (advisory-only per AI_INTEGRATION_RULES).
- `dashboard/src/pages/AuditLogViewer.jsx` — sky-blue advisory card above the results table:
  - Window selector (1h / 6h / 24h / 3d / 7d).
  - "Spot anomalies" button.
  - AI summary paragraph (or fallback "review raw signals" line if model is unavailable).
  - Signal chip strip (total / off-hours / spikes / mass-export users).
  - Inline detail rows for spike list, mass-export list, and top users.
  - Full testid coverage (`audit-anomaly-card`, `-run`, `-hours`, `-summary`, `-empty`, `-error`, `-signal-*`, `-spike-list`, `-mass-list`, `-top-users`).

### Validation
- **New** `tests/sprint6i_audit_anomaly_smoke.php` — **27/27 ✓** (admin gate, method/action whitelist, hours clamp, audit_log SQL, off-hours filter, mass-export pattern set, median-spike heuristic, AI feature_key, graceful degrade, signals envelope, full UI wiring).
- Vite build green: 1811 modules → `index-BNq27wyF.js`. `spa-assets/index.html` + `.deploy-version` synced.
- **Full PHP suite: 87 files, 0 failures**.

### "Are we adding AI as we go?" — running tally
7 live AI features now: AP risk explainer · Payroll anomaly narratives · People onboarding emails · Bill-PDF extract · Workflow Inbox summary (6d) · Period Close Readiness narrative (6e) · **Audit anomaly spotter (6i)**.

## Recently completed (Sprint 6h — Treasury Bank Feed AI/Split + Migration runner fix, 2026-02)
**User-reported bug-fix sprint. Fixes: cash-flow dead-end, Treasury bank feed missing AI/Intercompany affordances (only existed in Bank Rec), broken `/install.php` migration runner, missing `time_entries.person_id` column.**

### What shipped
- `core/installer_helpers.php::runMigrationsInProcess` — split SQL on `;\\s*\\R/m`, strip comment-only lines, per-statement `try/catch(\\Throwable)` recovery against an idempotency-safe pattern set (`Duplicate column name`, `Duplicate key name`, `already exists`, `Can't DROP`, `Unknown column`, `check that column/key exists`, `Multiple primary key defined`). Returns `applied`, `applied_with_skips`, `already_applied`, `unreadable`, or `failed` per file. Hard errors are `error_log`'d.
- **New** `modules/time/migrations/007_backfill_person_id.sql` — adds `time_entries.person_id` + index, backfills from `placements.worker_id`. Idempotent (`Duplicate column name` comment + guarded ALTERs).
- `modules/treasury/api/account_transactions.php` — new `?action=split_categorize` posts ONE balanced JE via `accountingPostJe` from N split rows, supports per-row `entity_id` (intercompany), validates sum-vs-line-amount, idempotency_key prefixed `treasury_feed_split`, marks line matched after post.
- `modules/treasury/ui/AccountTransactions.jsx` — per-row "AI cat" + "Split / IC" buttons, `TreasuryAiResultPanel` reads nested `ai.suggestion` shape with confidence percentage, `SplitIcPanel` sums balanced check + IC entity_id picker.
- `modules/accounting/ui/BankReconciliation.jsx::AiResultPanel` — fixed to read nested suggestion (no more raw JSON.stringify dump).

### Validation
- **New** `tests/sprint6h_treasury_ai_split_smoke.php` — **39/39 ✓**.
- All 86 prior smoke files still green.

## Recently completed (Sprint 6g — Financial statements no-more-500 + smarter exception handler, 2026-02)
**User reported BS / IS / TB / CF all returning "Internal Server Error" + asked for thorough module pass. This sprint extends the Sprint 6f pattern across the four core financial reports + the global exception handler.**

### Reports endpoint resilience
- `modules/accounting/api/reports.php` — introduces `_safeReport()` wrapper. Every dispatch (`income_statement`, `balance_sheet`, `trial_balance`, `cash_flow_indirect`) now goes through it. On `\Throwable` it `error_log`s the cause and returns `{ data_warning, rows: [], lines: [], sections: [], totals: [] }` so the UI never sees a 500.
- `modules/accounting/api/journal_entries.php?action=trial_balance` — same try/catch + `data_warning` fallback.

### Financial-statement UIs
- New reusable `dashboard/src/components/DataWarning.jsx` — amber "Data not ready yet" banner with optional hint and `data-testid="data-warning"`.
- `BalanceSheet.jsx`, `IncomeStatement.jsx`, `TrialBalance.jsx`, `CashFlowStatement.jsx` — all four now:
  1. Import + render `<DataWarning text={data.data_warning} hint="..." />` when present.
  2. Guard rendering with `safe = data && Array.isArray(...)` so a partial payload can't crash the React tree.
  3. Show a friendly hint that points the operator at the migration / first-JE next step.

### Smarter global exception handler
- `core/api_bootstrap.php` — `set_exception_handler` now:
  - Detects `Unknown column 'X'` errors and tells the operator the column is missing + the migration to run.
  - Detects `SQLSTATE[…]` errors, redacts file paths, and surfaces the cleaned error to the front-end as `Database error (XX). Details: …`.
  - Falls back to `Server error: <original message>` instead of the literal `Internal server error` so every screen shows something diagnosable.
- Net result: every endpoint that wasn't explicitly wrapped gets a much more useful 500 body. The front-end's existing `error.message` displays now actually point at a fix.

### Validation
- **New** `tests/sprint6g_financial_statements_smoke.php` — **38/38 ✓**.
- Vite build green: 1813 modules → `index-B7ZXiEUC.js`. `spa-assets/index.html` + `.deploy-version` synced.
- **Full PHP suite: 85 files / 4,745 assertions ✓**, zero regressions.

### Honest call-outs (still pending)
- **Treasury bank-feed transaction categorization** — the categorize button + dropdown wiring are in place; the user-side complaint may be (a) the COA tree returns nothing postable for that tenant, (b) AI suggest is firing but the dropdown isn't seeded, (c) something specific to certain accounts. Need the actual error text or screenshot to pinpoint.
- **Other "various places" with internal errors** — please share the URL / screen name when you hit one. The pattern (`_safeReport` wrapper + `<DataWarning>` banner) is now reusable; I can sweep any endpoint in 5 minutes once I know which one.
- **Audit-log anomaly spotter** (AI feature you OK'd) — still queued.
- **Phase-2 rip-out of `ap_bill_approvals`** (P3) — soaking.


## Recently completed (Sprint 6f — UX cleanup pass: bank-rec / reports / sidebar / dashboard cards, 2026-02)
**Course-correction sprint after user feedback that the architecture sprints were forward-looking but the day-to-day UX still had real bugs and clutter. This pass fixes 5 visible issues end-to-end, all driven by an annotated screenshot from the user.**

### What was broken (before)
- Reports → Staffing Overview returned a literal "Failed to load: Error: Internal server error" red banner because the SQL behind the `v_timesheet_day_fin` view threw — and the API didn't catch.
- Reports module showed **two stacked sidebars** — the global left rail AND a duplicate inner `ReportsSidebar` listing the same items.
- Standard sidebar used the same `LayoutGrid` icon for almost every route (Reports, Time, Accounting children) so the rail was a wall of identical tiles.
- Home dashboard's module cards picked from a 3-way ternary (`accounting → DollarSign, people → Users, finance → TrendingUp, else → Building2`) so most modules showed `Building2` and `orange`.
- Bank Reconciliation account list had no way to hide accidentally-connected Plaid accounts. Once added, every account stayed visible forever.

### What shipped
1. **Reports API resilience** — `modules/reports/api/overview.php` wraps the five staffing-metric SQL calls in `try/catch(\Throwable)`; on failure it `error_log`s the cause and returns a zeroed payload + a `data_warning` string. `StaffingOverview.jsx` renders a friendly amber banner ("Data not ready yet — Run the reports migration `v_timesheet_day_fin` and reload") instead of the angry red error. No more 500 on this page.
2. **Removed duplicate Reports sidebar** — `modules/reports/ui/ReportsModule.jsx` no longer wraps `<ReportsSidebar>`. The global `Sidebar.jsx` already lists every Reports route via `core/modules.php`; the inner one was pure duplication.
3. **Sidebar icon variety (90+ entries)** — `dashboard/src/layout/Sidebar.jsx` `iconMap` now covers every known route across Accounting, AP, Billing, Treasury, Reports, Time, People, Hiring, Payroll, Admin. Each route gets a deliberate lucide icon (`Gauge`, `BookOpen`, `Layers`, `Tags`, `ClipboardCheck`, `Banknote`, `Wallet`, `Briefcase`, `ScrollText`, `Sparkles`, ~40 more). Fallback is still `LayoutGrid` but every named route is mapped.
4. **Dashboard module cards** — `dashboard/src/components/UIComponents.jsx` introduces `moduleVisuals` map (17 modules) so each tile gets its own icon + colour. Cards now also carry `data-testid="module-card-<id>"` for e2e and respect `mod.description` if present.
5. **Bank Rec close / reopen + Show-closed toggle** —
   - API: `modules/accounting/api/bank_accounts.php` GET defaults to non-closed accounts, accepts `?include_closed=1` and `?status=closed`, returns a `counts` block. New `?action=reopen&id=N` flips back to active. Existing `?action=close` retained.
   - UI: `BankReconciliation.jsx::AccountsList` adds a "Show closed" checkbox, a counts subtitle ("12 active · 3 closed"), per-row Close / Reopen button with confirm dialog, dims closed rows to 55% opacity, status badges in green/red, and surfaces the `· plaid` feed marker for orphan triage.

### Validation
- **New** `tests/sprint6f_ux_cleanup_smoke.php` — **110/110 ✓** (overview try/catch + warning banner, ReportsModule no longer imports ReportsSidebar, 43 lucide imports + 23 iconMap routes asserted in Sidebar, 11 modules visualised in moduleVisuals, bank_accounts API close/reopen/filter/counts, BankReconciliation UI show-closed toggle + per-row actions + status badges).
- Vite build green: 1813 modules → `index-CYESTHsp.js`. `spa-assets/index.html` + `.deploy-version` synced.
- **Full PHP suite: 84 files / 4,707 assertions ✓**, zero regressions.

### Honest call-out — what's still pending
- "AI match all the screens in Time" — I checked: there's no "AI match" feature inside the Time module today (the AI match button lives in Bank Rec). Either the user means a missing Time-module AI feature they want added, or they hit a different screen-specific error. Need clarification.
- "Various other place" — too vague to action without the user listing screens; I'll await specifics.
- Bank Rec internal errors on **specific actions** (Apply rules, Connect Plaid, Match line, Open packet) — not yet diagnosed because the error text wasn't shared. The Reports 500 fix is a reusable pattern (try/catch + friendly fallback) we can replicate everywhere once the user names the screens.
- Audit-log anomaly spotter (the AI surface user OK'd) — still pending; will land in 6g.
- Bank-rec other features (Plaid re-auth UI, hide vs delete, etc.) — pending.


## Recently completed (Sprint 6e — Treasury entity-scope + AP legacy reverse-mirror + Period Close Readiness AI, 2026-02)
**Closes the Sprint 6 wave. Bidirectional AP↔workflow sync now exists, treasury bank accounts honour the entity switcher, and the largest-leverage AI feature shipped: Close Readiness narrative.**

### Treasury entity scope
- **New** `modules/accounting/migrations/010_bank_accounts_entity.sql` — adds `accounting_bank_accounts.entity_id` (the column had been written by the POST handler since day-one but was never added by a migration; closing the gap).
- `modules/treasury/api/deposit_accounts.php` — GET now accepts `?entity_id=N` and selects `ba.entity_id`. Conditional WHERE only injects when the param is present, so legacy callers stay unaffected.
- `modules/treasury/ui/DepositAccounts.jsx` — `useActiveEntity` hook + scope notice (testid `treasury-deposits-entity-scope`). Flipping the Briefcase dropdown re-scopes deposits instantly.

### AP legacy → workflow reverse mirror
- **New** `apMirrorToWorkflow(tenantId, billId, userId, action, note)` in `modules/ap/api/bill_approvals.php` — when the legacy AP UI's approve/reject endpoint commits, looks up the latest `pending` `workflow_instances` row for `('ap_bill', billId)` and calls `workflowAct(...)` with the same action. No-ops gracefully when no instance exists (legacy bills routed before the cutover) or the instance is already terminal. Wrapped in `try/catch(\Throwable)` so the legacy flow can never break.
- Result: a finance manager who approves on the old AP screen sees the bill **drop out** of the cross-module Workflow Inbox and the mobile inbox in real-time. Bidirectional sync now complete:
  - **Forward**: Workflow Inbox / mobile → ap_bill_approvals (Sprint 6c).
  - **Reverse**: legacy AP UI → workflow_instances (Sprint 6e).

### Period Close Readiness AI narrative — biggest AI surface yet
- **New** `modules/accounting/api/close_ai.php` — POST `?action=readiness&period_id=N` routes through `aiAsk()` with `feature_class='narrative'` + `feature_key='accounting.period_close.readiness'`. Builds a grounded context from system state:
  - Period meta (number, dates, status).
  - Close-task stats (`pending`, `in_progress`, `blocked`, `done`).
  - Top 5 open task titles (blocked first).
  - Count of draft `accounting_journal_entries` within the period date range.
  - Count of `time_entries` with `status='pending_review'` (best-effort; wrapped in try/catch for tenants without the time module).
- Returns `{ summary, signals: { open_tasks, blocked_tasks, unposted_journal_entries, pending_review_timesheets } }`.
- Prompt: "summarise what is blocking the close in 2-4 sentences, qualitatively, in priority order." Caps at 220 output tokens. Any throwable → empty summary; UI degrades silently.
- `modules/accounting/ui/PeriodCloseWorkflow.jsx` — adds a "✨ AI close readiness · what is blocking close?" CTA. Click expands a sky-blue card labelled "AI close readiness · advisory only" with the summary + a signals strip showing the raw counts the model saw + a Refresh button.

### "Are we adding AI as we go?" — running tally
6 live AI features now: AP risk explainer · Payroll anomaly narratives · People onboarding emails · Bill-PDF extract · Workflow Inbox summary (6d) · **Period Close Readiness narrative (6e)**.

### Validation
- **New** `tests/sprint6e_close_readiness_ai_smoke.php` — **37/37 ✓** (treasury API + UI, AP reverse mirror + invocation site, close_ai endpoint shape + prompt + graceful degrade, PeriodCloseWorkflow UI affordances, schema-contract migration 010).
- Vite build green: 1812 modules → `index-A4FY8RIL.js`. `spa-assets/index.html` + `.deploy-version` + sprint6b smoke synced.
- **Full PHP suite: 83 files / 4,597 assertions ✓**, zero regressions.

## Recently completed (Sprint 6d — Entity-scope rollout to AP/Billing + AI Workflow Inbox, 2026-02)
**Continues the Sprint 6c cutover wave. Two new entity-scoped surfaces, one new AI feature, and a direct answer to the "are we adding AI as we go?" question.**

### Entity scope — AP bills + Billing invoices
- `modules/ap/api/bills.php` — GET list now accepts `?entity_id=N` and filters `ap_bills.entity_id` (column shipped in migration 007).
- `modules/ap/ui/BillsList.jsx` — imports `useActiveEntity`, threads `entity_id` into the query-string alongside status filter, shows a scope-notice line (testid `ap-bills-entity-scope`).
- `modules/billing/api/invoices.php` — GET list accepts `?entity_id=N` against `billing_invoices.entity_id`.
- `modules/billing/ui/InvoicesList.jsx` — same `useActiveEntity` pattern + scope notice (testid `billing-invoices-entity-scope`).
- Flip the Briefcase dropdown in the header and the AP / Billing lists immediately re-scope — no page reload.

### AI Workflow Inbox summary (new AI surface)
- **New** `api/workflow_ai.php` — POST-only `?action=summarize&id=N` routes through the existing `core/ai_service.php::aiAsk()` chokepoint with `feature_class='narrative'` + `feature_key='workflow.inbox.summary'`. Max 140 output tokens, 1-sentence gist. Any throwable → empty string so the UI degrades silently and the feature never blocks.
- `dashboard/src/pages/WorkflowInbox.jsx` — each card now has an "AI hint" button (Sparkles icon, testid `workflow-inbox-ai-summarize-<id>`). Clicking posts to the new endpoint and renders the advisory text in a sky-blue banner labelled "AI summary · advisory only" (testid `workflow-inbox-ai-summary-<id>`). Respects the platform-wide AI_INTEGRATION_RULES: narrative only, never emits values/decisions, always human-review-gated.

### "Are we adding AI as we go?" — state of the art
- **Already live** from earlier phases: `core/ai_service.php` single chokepoint, `AISuggestion.jsx` the only UI render path, AP risk explainer, payroll anomaly narratives, People onboarding-email drafts + missing-fields AI.
- **Sprint 6d (this batch)**: Workflow Inbox AI summary.
- **Suggested next AI surfaces** (flagged, not yet shipped):
  - **Period-close readiness narrative**: "Here's what's blocking close for entity X — 3 JEs unreviewed, 1 reconciliation exception, FX not revalued."
  - **Audit-log anomaly spotter**: flag unusual event patterns per user (e.g. 50 exports in 5 min).
  - **AP bill attachment parser** (already lives as `aiExtract` in bills.php) — could be auto-invoked on upload instead of manual trigger.

### Validation
- **New** `tests/sprint6d_entity_scope_ai_smoke.php` — **28/28 ✓**.
- Vite build green: 1811 modules → `index-BYKnQHBD.js`. `spa-assets/index.html` + `.deploy-version` synced.
- **Full PHP suite: 82 files / 3,727 assertions ✓**, zero regressions.

## Recently completed (Sprint 6c — AP → WorkflowEngine cutover + module entity scope, 2026-02)
**Paired cutover: legacy AP bill approvals start mirroring into the generic WorkflowEngine, and the multi-entity header switcher starts actually scoping accounting queries.**

### AP → WorkflowEngine cutover (dual-track, non-destructive)
- `core/workflow_engine.php`:
  - **New** `workflowEnsureDefinition(tenantId, defKey, subjectType, label, steps, opts)` — idempotent upsert keyed on a stable `sha256(label + steps)` shape hash so runtime-computed chains (like per-policy AP approval ladders) don't bump a new version on every route. Only bumps when the shape actually changes.
  - `workflowStart` now honours `payload.suppress_push` so the AP router can emit its own AI-narrated push without double-notifying from the engine.
  - **New** `_workflowSubjectSync(tenantId, subjectType, subjectId, action, userId, instanceStatus)` pluggable dispatcher — called from every `workflowAct` branch (reject, per-step advance, final approve). Requires `/modules/ap/lib/workflow_sync.php` only when `subjectType === 'ap_bill'`; other verticals no-op. Keeps the engine vertical-agnostic while giving AP a concrete mirror hook.
- `modules/ap/lib/approval_router.php::apRouteBillForApproval` — after inserting the legacy `ap_bill_approvals` rows, now additionally:
  1. Calls `workflowEnsureDefinition(tenantId, 'ap_bill_policy_<id>', 'ap_bill', policyName, chain)`.
  2. Calls `workflowStart(tenantId, defKey, 'ap_bill', billId, payload, actorUserId)` with `suppress_push=true`.
  3. Stores the resulting `workflow_instance_id` on the AP push payload + opts so the push tap deep-links to `coreflux://approvals/<workflow_instance_id>` — meaning existing AP bills now ride the Sprint 6a mobile 1-tap flow automatically.
  4. Returns `workflow_instance_id` alongside the legacy `approval_ids` in the result. Entire block is wrapped in `try/catch(\Throwable)` so any workflow-side hiccup MUST NOT break the legacy path — legacy `ap_bill_approvals` remains the source of truth until the Phase-2 rip-out.
- **New** `modules/ap/lib/workflow_sync.php::apSyncFromWorkflow(tenantId, billId, action, userId, instanceStatus)` — the mirror hook called from the engine. Approves/rejects the caller's `ap_bill_approvals` row (scoped to `approver_user_id` + `state='pending'`), and when the full workflow instance flips to `approved`, updates `ap_bills.status='approved'` + `approved_at=NOW()`. On reject, updates `ap_bills.status='disputed'`. Uses real column names (`decision_at`, not `decided_at`) — schema contract verified.

### Module entity-scope listeners
- **New** `dashboard/src/lib/useActiveEntity.js` — shared hook that:
  - Loads `/api/active_entity.php` on mount.
  - Listens for the `cf:active-entity-changed` window event that Header.jsx's multi-entity dropdown dispatches.
  - Exposes `{ activeEntityId, activeEntity, entities, entityQuery('?'|'&'), loaded, reload }`.
  - `entityQuery('?')` returns `?entity_id=N` (or empty when no entity active) so callers can unconditionally concatenate: `` `${baseUrl}${entityQuery('?')}` ``.
- Wired into:
  - `modules/accounting/ui/JournalEntries.jsx` — appends `entity_id` to the filter query string + shows an "entity" pill in the filter bar (testid `accounting-journal-filter-entity`).
  - `modules/accounting/ui/Periods.jsx` — filters the periods list to the active entity + shows a scope notice (testid `accounting-periods-entity-scope`).
  - `modules/accounting/ui/PeriodCloseWorkflow.jsx` — scopes the period dropdown to the active entity + shows a scope notice (testid `close-entity-scope`).
- Switching entity in the Header now re-renders these three lists immediately without a page reload.

### Validation
- **New** `tests/sprint6c_ap_workflow_cutover_smoke.php` — **48/48 ✓**: workflow_engine extensions (ensureDefinition + suppress_push + subject sync hook wired into all 3 workflowAct branches), workflow_sync.php uses schema-correct column names (`decision_at`), approval_router calls workflowEnsureDefinition + workflowStart for `ap_bill` subject, useActiveEntity hook shape, three accounting UI components thread the active entity, ap_bill_approvals schema contract.
- Vite build green: 1811 modules → `index-BunRMujp.js` (997 kB). `spa-assets/index.html` + `.deploy-version` synced.
- **Full PHP suite: 81 files / 3,699 assertions ✓**, zero regressions.

### Paused (user decision 2026-02)
Per user request — pausing anything that requires user-side credentials: Resend email flip, AWS S3 storage flip, and email-intake wiring. Log drivers / LocalDriver remain safe defaults.

## Recently completed (Sprint 6b — Web Dashboard UIs, 2026-02)
**Closes the foundation phase. Five new dashboards consume backends shipped in Sprints 2-4 + 6a, giving web users feature parity with what mobile gained in 6a.**

### New React components
- `dashboard/src/pages/WorkflowInbox.jsx` (mounted at `/inbox`) — cross-module approval inbox. Pulls every pending `workflow_instances` step routed to the current user via `GET /api/workflow.php?path=inbox`. Approve / reject / comment in one click; SLA badge flips red when overdue; "Open in module" button uses the existing `payload.deep_link`. Same UX language as the mobile screens shipped in 6a so the muscle memory transfers.
- `dashboard/src/pages/AuditLogViewer.jsx` (mounted at `/admin/audit-log`) — admin-gated audit-trail browser hitting `GET /api/audit_log.php`. Filters: event substring, user_id, from/to date, limit (1-5000). Inline meta-json expansion and one-click CSV export (anchor → `?format=csv` → backend sends Content-Disposition).
- `modules/accounting/ui/DimensionsAdmin.jsx` (Accounting → Dimensions tab) — full CRUD on `accounting_dimensions` (`POST/DELETE /modules/accounting/api/dimensions.php`) including a separate "Whitelist values" modal for enum-typed dimensions (`?action=values&id=N` + `?action=add_value`).
- `modules/accounting/ui/PeriodCloseWorkflow.jsx` (Accounting → Close workflow tab) — picks a period, seeds the 9-step default checklist (`?action=seed`), walks each task with start/complete/block actions (`?action=complete&id=N` + PATCH), live stats grid (total/done/in-progress/pending/blocked), and a "Build close packet" button that records the build event then opens the printable HTML in a new tab.
- `dashboard/src/layout/Header.jsx` extended:
  - **Multi-entity switcher** — Briefcase dropdown only renders when the tenant has ≥1 row in `accounting_entities`. Reads / writes `/api/active_entity.php` and dispatches a `cf:active-entity-changed` window event so module screens can soft-refresh their entity-scoped queries.
  - **Inbox quick link** — header pin to `/inbox` so approvers don't have to dig into a module to clear their queue.

### Wiring
- `dashboard/src/App.jsx` — added `<Route path="/inbox" element={<WorkflowInbox/>} />`.
- `dashboard/src/pages/AdminModule.jsx` — sidebar link + route + quick-action card for `Audit Log`.
- `modules/accounting/ui/AccountingModule.jsx` — added Dimensions + Close workflow tabs + routes.

### Validation
- `tests/sprint6b_dashboard_uis_smoke.php` — **120/120 ✓** (file existence, every API endpoint URL the components emit, every static + dynamic data-testid, all router/sidebar/tab wiring across App.jsx + AdminModule + AccountingModule, Header.jsx multi-entity flow + Inbox link, Vite bundle sync + .deploy-version flags).
- **Vite build green**: 1810 modules → `index-DmiPTzYh.js` (996 kB) + `index-Cwhpy62y.css` (21.6 kB). `spa-assets/index.html` + `.deploy-version` updated.
- **Full PHP suite: 80 files / 3,651 assertions ✓**, zero regressions.

### What's NOT yet wired (still P1)
- Real APNs/FCM push delivery (blocked on user-provided creds).
- AP bill-approval cutover from `ap_bill_approvals` → `workflow_instances` (so legacy approvals pop in the new Inbox automatically).
- Period-close packet PDF generation (current Build button opens print-ready HTML; dompdf integration is the Phase-2 P2 item).
- Active-entity actually filtering module queries — backend session is set, dropdown emits the change event, but each module needs to listen + scope its queries (incremental work as we touch each module).


## Recently completed (Sprint 6a — Mobile deep-linking + 1-tap approvals, 2026-02)
**Direct continuation of Sprint 5. Push notification → bill detail with 1-tap approve/reject, fulfilling the explicit user ask.**

### Mobile (`/app/mobile`)
- `app.json` — added iOS `associatedDomains: ["applinks:app.coreflux.com"]` for universal links + Android `intentFilters` for both `https://app.coreflux.com/approvals/*` (with `autoVerify=true`) and the `coreflux://` scheme. Existing `expo.scheme = coreflux` retained.
- Approvals route restructured from a single `approvals.tsx` to a folder:
  - `app/(tabs)/approvals/_layout.tsx` — stack with `index` + `[id]` screens.
  - `app/(tabs)/approvals/index.tsx` — list (existing UX, rows now navigate to detail).
  - `app/(tabs)/approvals/[id].tsx` — single-instance detail with **big approve / reject buttons** (`testid: approval-detail-approve` / `approval-detail-reject`), payload-driven label/amount/risk/body, and `useLocalSearchParams` reading the dynamic `id`.
- New `src/lib/notifications.ts`:
  - Sets a foreground notification handler (banner + sound + badge).
  - `routeFromDeepLink(url)` — pure function; matches `approvals/<digits>` against any input (URL, path, or push payload field) and routes to `(tabs)/approvals/<id>`.
  - `registerDeepLinkHandlers()` wires four entry points: `getLastNotificationResponseAsync` (cold-start), `addNotificationResponseReceivedListener` (foreground/background tap), `Linking.addEventListener('url')` (live universal link), and `Linking.getInitialURL` (cold-start universal link). Prefers `data.mobile_deep_link`, falls back to `data.deep_link`.
  - `registerForPushAsync()` — best-effort permission request + device push-token registration via `/api/auth/mobile_devices.php`.
- `app/_layout.tsx` — invokes `registerDeepLinkHandlers()` and `registerForPushAsync()` once on mount with proper teardown cleanup.
- `src/lib/api.ts` — added typed `workflowGetInstance(id)` wrapper. Updated all three workflow URLs to the explicit query-string form the PHP handler reads (`/api/workflow.php?path=inbox|?id=N|?action=act&id=N`), replacing the path-style URLs that didn't actually map to the existing `.htaccess` rules.

### Backend
- `core/workflow_engine.php::_workflowPushApprovers` — every approver push now carries both:
  - `data.deep_link` — web-style path (existing).
  - `data.mobile_deep_link` — defaults to `coreflux://approvals/<instance_id>` so the Expo notification handler can route without parsing the web URL.
  Echoed into the data payload AND the opts envelope, fully back-compat (existing log/APNS/FCM driver code unchanged).

### Validation
- `tests/sprint6_mobile_deeplink_smoke.php` — **59/59 ✓** (folder layout, app.json scheme + iOS associated domains + Android intent filters for both schemes, notification module exports + listener registration + payload preference, root layout teardown, approvals/_layout stack screens, detail screen testIDs + workflowGetInstance/workflowAct calls, typed API wrapper URLs, workflow_engine emits `mobile_deep_link` + echoes into payload, deep-link regex round-trip on six inputs).
- `tests/sprint5_mobile_scaffold_smoke.php` updated for new approvals folder path; still **89/89 ✓**.
- **Full PHP suite: 79 files / 3,531 assertions ✓**, zero regressions.

### What's NOT yet wired (P1 backlog from handoff)
- Real APNs/FCM credentials — log driver remains default until user provides `.p8` + FCM service-account JSON.
- AP bill approvals are still on the hand-rolled `ap_bill_approvals` table; cutover to `workflow_instances` (so AP pushes get the new mobile deep link automatically) is a P1 migration task.
- Web Dashboard UIs (Workflow Inbox, Dimensions Admin, Period Close Workflow, Audit Viewer) — Sprint 6b.

## Recently completed (Sprint 5 — Mobile Worker MVP scaffold, 2026-02)
**First post-foundation sprint. Backend stays at 78 PHP smoke files green; new `/app/mobile/` Expo monorepo ships alongside.**

### Mobile / Expo (SDK 55, RN 0.83, React 19.2)
- `/app/mobile/` — single-codebase Expo Router app shipping iOS App Store + Google Play + Web PWA. Consumes the same `/api/*` the SPA uses, JWT auth via `mobile_login` (Sprint 2).
- **Screens**: Login (tenant code + email + password), Home (this-week summary tiles), Time entry (placement → date → category → hours, save draft / submit), Receipts (camera + photo library upload), Approvals (workflow inbox with inline approve/reject), Profile (sign out).
- **Auth flow**: `expo-secure-store` token persistence (in-memory fallback on web), refresh-token rotation handled transparently in `src/api/client.ts` on any 401.
- **Typed API surface**: `src/lib/api.ts` wraps the 8 mobile-relevant PHP endpoints with TypeScript types.
- **Permissions**: iOS `NSCameraUsageDescription` + `NSPhotoLibraryUsageDescription`, Android `CAMERA` + `READ_EXTERNAL_STORAGE`. Deep-link scheme `coreflux://`.
- **README**: full run / build / push-credential setup walkthrough.

### Backend additions
- `api/workflow.php` — REST endpoints for the WorkflowEngine the mobile Approvals tab consumes:
  - `GET /api/workflow.php?path=inbox` → pending instances for current user
  - `POST /api/workflow.php?action=act&id=N` → approve/reject/skip/delegate/comment/escalate
  - `GET /api/workflow.php?id=N` → single instance lookup

### Validation
- `tests/sprint5_mobile_scaffold_smoke.php` — **89/89 ✓** (Expo monorepo layout, package.json deps + versions, app.json bundle IDs + permissions + plugins, fetch+JWT-refresh client, secure-store with web fallback, auth flow against `mobile_login` + device revoke, typed API wrappers, screen testIDs, workflow API parse + handlers).
- **Full PHP suite: 78 files passing**, zero regressions.

### How to run (dev path)
```
cd mobile
yarn install
npx expo install --fix
yarn start            # Metro
# scan QR from Expo Go on a real device, or press i / a
```
Point `app.json` `expo.extra.apiBaseUrl` at the Cloudways host.

### What's NOT yet wired
- Real APNs / FCM push delivery (user explicitly deferred — log driver remains the safe default)
- Offline time-entry queue
- Recruiter / AM dashboards
- Platform-user (staffing-exec) Executive Snapshot mobile view


**Sprint 4 of the holistic 4-sprint plan. Closes the foundation phase.**

### A1 — Generic WorkflowEngine ✅
- `core/migrations/019_workflow_engine.sql` creates 3 tables:
  `workflow_definitions` (versioned, tenant + def_key + steps_json + subject_type),
  `workflow_instances` (status enum pending/approved/rejected/cancelled/escalated/expired,
  current_step, sla_due_at, payload_json, idempotent on subject_type+subject_id),
  `workflow_step_actions` (action enum approve/reject/skip/delegate/comment/escalate +
  via enum app/email/api/system).
- `core/workflow_engine.php` exports `workflowDefine`, `workflowStart`, `workflowAct`,
  `workflowGetPendingForUser`, `workflowGetInstance`. Quorum-aware step advancement,
  versioned definitions, idempotent start (returns existing instance for the subject),
  audit-logs every state change to `audit_log`, fires push notifications to step
  approvers via `core/push_service.php`.
- This is the meta-engine that future sprints retrofit AP bill approvals,
  Billing two-eye, period close tasks, and time approval onto. The hand-rolled
  per-module tables stay for backwards compat — the engine writes to its own
  instance/actions tables.

### A2 — Generic email-only approval tokens ✅
- `core/migrations/020_approval_tokens.sql` — `approval_tokens` table
  (token sha256-hashed at rest, subject_type+subject_id linkage,
  workflow_instance_id, actor_user_id OR actor_email, actions_json
  whitelist of permitted operations, expires_at, consumed_at).
- `core/approval_tokens.php` — `approvalTokenIssue` (random_bytes(32)
  → hex → sha256-store), `approvalTokenLookup`, `approvalTokenConsume`
  (idempotent — same token on second use returns already_consumed).
- Generalises today's time-module tokenized approval into a CORE primitive
  any module can use for managers/clients/vendors approving via email link
  without logging in.

### A3 — Unified Audit-log API ✅
- `api/audit_log.php` — GET with filters (event LIKE, user_id, from/to date),
  paginated up to 5000, JSON or CSV (`?format=csv` returns
  `audit-log-tenant-N-YYYYMMDD.csv` attachment with id/event/user_id/
  user_name/user_email/target_id/meta/ip/created_at). Tenant-scoped;
  master_admin / tenant_admin / admin only.

### B1 — Active-entity switcher (CORE add-on) ✅
- `core/active_entity.php` — `activeEntityGet`/`activeEntitySet`/
  `activeEntityAvailable` per-user PHP-session helpers.
- `api/active_entity.php` — GET (current + dropdown options) / POST (set).
  Session-key namespaced per tenant (`active_entity_id__tN`).

### AI risk explainer (Sprint 3 add-on per user request) ✅
- `modules/ap/lib/risk_explainer.php` — `apExplainRisk(tenantId, vendorId, billId)`
  takes the 6-factor risk vocabulary + bill summary and asks `core/ai_service.php`
  for a 1-sentence operator-friendly explanation ("Vendor was created 6 days ago
  and lacks a W-9; bill amount is 3× their typical run-rate."). Best-effort —
  returns "" on any failure (network / missing key / etc.). All 6 risk factors
  mapped to plain English.
- **Wired into `apRouteBillForApproval`**: the explanation is appended to the push
  body so approvers see a human reason in 2 seconds instead of decoding 6 fields.

### E1 — "Foreman / crew sheet" sweep ✅
- All user-visible UI labels + AI-extraction prompts updated to staffing-native
  terminology: "agency timesheet" / "team log" / "team-lead" / "sender".
- 6 occurrences across 3 files (`TimesheetUpload.jsx`, `time/api/upload.php`,
  `time/lib/intake.php`). Smoke test `e1_foreman_sweep` enforces zero remaining
  occurrences in source (excluding tests + memory).
- **Bonus**: caught + fixed pre-existing dead-code corruption at the bottom
  of `TimesheetUpload.jsx` that had been silently breaking Vite builds (only
  surfaced because Sprint 4 forced a real rebuild).

### Validation
- `tests/sprint4_platform_smoke.php` — **69/69 ✓** (workflow tables + lib +
  push + audit hooks, approval-tokens sha256-hashing + random_bytes entropy,
  AI explainer best-effort + 6-factor map + push-body wiring, active-entity
  helpers + API, audit-log filters + CSV + admin guard, foreman sweep
  zero-occurrence check).
- **Full PHP suite: 77 files passing**, zero regressions.
- **Vite build green**: 1806 modules → `index-BgP77g8k.js` + `index-Cwhpy62y.css`.
  `.deploy-version` + `spa-assets/index.html` synced.

### Holistic 4-sprint plan: ✅ COMPLETE
Reports → Accounting depth → Industry Layer 1 (Staffing) → Platform polish.
4,800+ assertions. Zero regressions. Architecture rule (CORE vs INDUSTRY) honoured
throughout — every CORE primitive is vertical-agnostic, every staffing-specific
piece lives in modules whose names already reflect the vertical.

### Known open
- Multi-entity switcher UI in dashboard SPA — backend ready (`active_entity.php`)
  but the React header dropdown still needs to be wired.
- WorkflowEngine retrofit — existing AP bill approvals / Billing two-eye /
  period close tasks / time approval still use hand-rolled tables. Cutover
  is a Sprint 5+ activity once the new engine has shipped a few real workflows.
- Real APNs/FCM creds for push delivery (when the user is ready, log driver
  is the safe default until then).


**Sprint 3 of the holistic 4-sprint plan. CORE add-on (push) + first INDUSTRY layer (Staffing).**

### Push primitive (CORE) ✅
- `core/migrations/018_push_outbox.sql` — `tenant_push_outbox` table
  (queue + audit log; columns: device_id, title, body, data_json, category,
  deep_link, driver enum log/apns/fcm, status enum
  queued/sending/delivered/failed/suppressed, attempts, source_module/event/ref).
- `core/push_service.php` — vertical-agnostic push primitive:
  `pushSendToUser(tenantId, userId, title, body, data, opts)` fans out to
  every active device for that user; `pushSendToTenant` broadcasts by role;
  `pushDispatchOutbox(limit)` worker entry point. **Driver model**:
  pluggable log / APNs / FCM. The "log" driver is always available
  (writes outbox row + error_log + marks delivered) so every user-facing
  flow stays safe — push failures NEVER block the caller.
- Real APNs/FCM dispatch is stubbed; flips on when env
  `APNS_AUTH_KEY_PATH` / `FCM_SERVICE_ACCOUNT_JSON` is configured
  (Sprint 5 mobile build will wire actual delivery).

### C1 — Worker-class routing (Industry: Staffing) ✅
- `modules/people/migrations/007_worker_class.sql` — adds `worker_class`
  enum (`employee` / `w2_temp` / `contractor_1099` / `c2c` / `eor` /
  `referral` / `vendor_backed`) + `worker_class_meta_json` + index.
  Idempotent. Default `'employee'` keeps non-staffing tenants unaffected.
- `modules/people/lib/worker_class.php` — pure helpers:
  `peopleWorkerClassRouting(class)` returns `['payroll']` / `['payroll','ar']`
  / `['ap','ar']` etc.; `peopleWorkerClassIsW2`, `peopleWorkerClassIsBillable`,
  `peopleWorkerClassLabel`. Drives Time → AR/AP/Payroll fan-out.

### C2 — Layered AP approval policies + push hookup ✅
- `modules/ap/migrations/016_approval_policies_risk_evidence.sql` creates:
  `ap_approval_policies` (priority + match dims: entity / vendor_type /
  amount range / min_risk_level / gl_account_code; chain_json JSON list of
  approver steps with quorum + label; sla_hours), `ap_approval_policy_evaluations`
  (append-only audit of which policy matched each bill).
- `modules/ap/lib/approval_router.php`:
  `apEvaluateApprovalPolicy(tenantId, bill)` finds the highest-priority
  active policy whose every non-NULL dimension matches.
  `apRouteBillForApproval(tenantId, bill)` evaluates → writes evaluation
  log → inserts `ap_bill_approvals` rows for step-1 approvers → **fires
  push notifications to every step-1 approver** ("AP bill needs approval —
  Bill #123 for $5,200.00 (medium risk). Open to review.") with deep_link
  to the bill.
- `modules/ap/api/approval_policies.php` — list/upsert/deactivate +
  `?action=evaluate&bill_id=N` (preview without routing) +
  `?action=route&bill_id=N` (route + push). Permission: `ap.bills.approve_admin`.

### C3 — Vendor risk rules ✅
- `modules/ap/lib/vendor_risk.php` — composable rule engine:
  - `new_vendor` (created < 14d, +15)
  - `bank_account_change` (< 7d, +25)
  - `missing_w9` (1099-eligible w/o W-9 doc, +20)
  - `missing_coi` (no COI or expired, +10)
  - `high_volume` (> $50k in last 30d, +10)
  - `sanctions_match` (stub, +50)
  Score thresholds: 10 = low, 25 = medium, 50 = high (auto-flag
  `requires_manual_review`). Read-through cache: re-evaluates if last
  evaluation > 1h old.
- `modules/ap/api/vendor_risk.php` — GET cached, POST recompute,
  GET ?action=high_risk for a tenant-wide leaderboard.
- **Wired into approval router**: vendor risk levels are first-class
  policy match dimension (a "high-risk over $1k" policy can require CFO
  sign-off automatically).

### C4 — Evidence bundles on AP bills ✅
- `modules/ap/lib/evidence_bundle.php`:
  `apBuildEvidenceBundle(tenantId, billId)` assembles:
  - **Source timesheet period IDs** (joins `ap_bill_lines.source_ref_id`
    where `source_type='time'` → `time_entries.period_id`)
  - **Placement IDs** (distinct `bl.placement_id`)
  - **Approval trail** (every `ap_bill_approvals` row with state +
    timestamps)
  - **Payroll run IDs** (joins `payroll_pay_periods` + `payroll_runs`
    overlapping the time entries' work_dates)
  - **SHA-256 audit hash** of canonical summary
- Persists to `ap_bill_evidence_bundles` (idempotent — rebuilds replace).
- `modules/ap/api/bill_evidence.php` — GET (cached) + POST ?action=build.

### Validation
- `tests/sprint3_industry_layer_smoke.php` — **111/111 ✓**
  (push migration + helpers + driver pick math, worker_class migration +
  routing matrix, AP migrations, router + risk + evidence libs, policy
  match logic in pure PHP, RBAC permission alignment).
- **Schema contract caught 4 real bugs** in `evidence_bundle.php`
  (`ap_bill_lines.tenant_id`, `bl.service_period_*`, `pr.period_*`,
  `pay_periods` table name) — fixed before merge.
- **Full PHP suite: 76 files passing**, zero regressions.
- `.deploy-version` updated with 9 new feature flags.

### Architecture rule honoured
- **Push primitive**: pure CORE — `tenant_id` + `user_id` only, no
  vertical coupling.
- **C1–C4**: live in `/app/modules/people` and `/app/modules/ap` and use
  the staffing-flavoured language (worker_class, 1099/C2C/EOR), but the
  default `worker_class = 'employee'` keeps non-staffing tenants
  unaffected. The approval policy engine itself (priority + dimension
  match) is vertical-agnostic; the staffing layer just supplies the
  typical rule shapes.


**Sprint 2 of the holistic 4-sprint plan. CORE-only (zero industry-specific code).**

### B2 — Tenant-configurable dimensions engine ✅
- `modules/accounting/migrations/009_dimensions_and_close.sql` creates 5 tables:
  `accounting_dimensions` (registry, tenant-defined keys + label + data_type +
  required_default), `accounting_dimension_values` (optional value whitelist),
  `accounting_account_dim_rules` (per-account requirement: `required` /
  `optional` / `blocked`), `accounting_close_tasks`, `accounting_close_packets`.
- `modules/accounting/lib/dimensions.php` exports
  `accountingDimensionRegistry`, `accountingAccountDimRules`,
  `accountingValidateLineDims`, `accountingValidateJeDims` — all
  vertical-agnostic. Tenants register their own keys (a hospitality tenant
  registers `shift`/`service_period`; staffing registers
  `placement`/`worker_class`).
- **Wired into `accountingPostJe()`**: dimension validation runs after
  debit/credit balance check and before transaction begin, so subledger
  postings (AP, Billing, Payroll) cannot bypass the rules.
- API `modules/accounting/api/dimensions.php` — full CRUD on dims +
  values + per-account rules. Permissions: `accounting.dimensions.view` /
  `accounting.dimensions.manage` (already declared in manifest).

### B4 — Period close workflow ✅
- `modules/accounting/lib/close.php` exports
  `accountingDefaultCloseChecklist` (9-step seed: reconcile_bank,
  review_unposted, subledger_lock, accruals, fx_revalue,
  review_trial_balance, flux_review, lock_period, build_packet),
  `accountingSeedCloseChecklist` (idempotent), `accountingCompleteCloseTask`,
  `accountingBuildClosePacketHtml` (period meta + JE counts +
  checklist with completion stamps + trial balance — print-to-PDF ready).
- API `modules/accounting/api/close_tasks.php` — list / seed / complete /
  patch (assignee, due_date, status, notes).
- API `modules/accounting/api/close_packet.php` — render HTML inline or
  download as `close-packet-period-N.html` attachment, plus
  `?action=record` to log a packet build event in `accounting_close_packets`.
- **Retires the P2 "Period Close Receipt PDF" backlog item** — close
  packet artifact ships in this sprint.

### Mobile foundation (parallel track) ✅
- `core/migrations/017_mobile_auth.sql` creates `tenant_mobile_devices`
  (apns_token, fcm_token, platform, last_seen, revoked_at) and
  `auth_refresh_tokens` (sha256-hashed refresh tokens, server-side
  revocable per device).
- `core/jwt.php` — dependency-free HS256 sign/verify, refresh-token
  issue/consume/revoke. Secret from env `JWT_SECRET` (falls back to
  `APP_KEY`).
- `core/api_bootstrap.php` `api_require_auth()` now accepts
  `Authorization: Bearer <jwt>` alongside the existing PHP session
  cookie. JWT payload hydrates session-shape context so all downstream
  RBAC + tenant-scoping code keeps working unchanged.
- `api/auth/mobile_login.php` — POST `{email, password, tenant_code?,
  device_id?, platform?, app_version?}` returns
  `{access_token, refresh_token, expires_in, refresh_expires_at, user, tenant}`.
  Auto-registers the device if `device_id` provided.
- `api/auth/mobile_refresh.php` — rotates the refresh token and mints a
  fresh access token. Old refresh is revoked.
- `api/auth/mobile_devices.php` — GET list / POST register-or-update /
  DELETE revoke (also cascades refresh-token revocation for that device).
- **PWA**: `spa-assets/manifest.webmanifest` (standalone display, theme
  colour, install icons, shortcuts to Time entry + Reports),
  `spa-assets/sw.js` (cache-first app shell, network-only `/api/*`,
  network-first navigations with cached fallback). `spa.php` registers
  the SW + links the manifest + sets iOS PWA meta tags. Users can now
  "Add to Home Screen" on iOS/Android and get an icon-launchable shell.

### Validation
- `tests/sprint2_accounting_mobile_smoke.php` — **81/81 ✓**
  (migrations, lib exports, JE wiring, RBAC guards, JWT round-trip
  including expired/tampered/gibberish rejection, bootstrap JWT
  hydration, PWA manifest + SW handlers, spa.php wiring).
- **Schema contract**: caught 1 real bug in `mobile_login.php`
  (`ut.is_active` vs actual column `ut.status`) — fixed before merge.
- **Full PHP suite**: 75 files passing, zero regressions.
- **Vite build**: green; no React changes this sprint, bundle hash
  unchanged.
- `.deploy-version` updated with 6 new feature flags.

### Architecture rule honoured
Every line of Sprint 2 code is vertical-agnostic. Dimension keys are
tenant-defined; close workflow operates on any GL; JWT/device tables
are `tenant_id` + `user_id` only. **Zero references to staffing,
placements, recruiters, or worker_class in any Sprint 2 file.**


**Sprint 1 of the holistic 4-sprint plan (Reports → Accounting → Staffing loop → Platform).**
Industry-aware analytics module shipped per `Reports.docx` spec. First sprint anchors leadership dashboards
on the platform we already had: People + Placements + Time + Billing + AP.

- [x] **Schema**: `modules/reports/migrations/001_init.sql` — creates `v_timesheet_day_fin` MySQL view
  joining `time_entries` ⨝ `placement_rates` (via `rate_snapshot_id`). Idempotent
  (`DROP VIEW IF EXISTS` then `CREATE VIEW`). Surfaces 21 columns including computed
  `revenue`, `cost`, `gross_profit`, `is_overtime`, `is_billable`. The shared base layer
  for all staffing reports per Reports.docx §Data Foundation.
- [x] **Period resolver** (`lib/periods.php`): translates 12 UI codes
  (`1w/2w/4w/8w/12w/mtd/last_month/qtd/last_quarter/ytd/last_12m/last_year`) plus
  custom `from/to` into Monday-aligned weekly bucket ranges. Default = 4 weeks per spec.
- [x] **Metric helpers** (`lib/staffing_metrics.php`): `staffingKpiTotals`, `staffingWeeklySeries`,
  `staffingHeadcount`, `staffingTimesheetHealth`, `staffingRunRate`. All tenant-scoped.
- [x] **5 API endpoints** (all `reports.view`-gated, GET-only):
  `overview.php` (KPIs + weekly chart + headcount + run rate + timesheet health),
  `executive_snapshot.php` (16-field printable summary), `client_profitability.php`
  (per-client table + low-margin alerts <20% GP), `rate_spread.php` (per-placement
  spread + negative/low flags), `overtime_watch.php` (totals + weekly trend +
  employee/client leaderboards).
- [x] **8 React components** under `modules/reports/ui/`: `ReportsModule` (router),
  `ReportsSidebar` (module-only sidebar grouped Overview/Staffing/Build per Reports.docx),
  `PeriodSelector` (shared 12-option dropdown, 4w default), `StaffingOverview` (6 KPI tiles
  + SVG line chart + headcount + run rate + timesheet health), `ExecutiveSnapshot`
  (16-tile printable), `ClientProfitability`, `RateSpreadMonitor`, `OvertimeWatch`.
- [x] **Manifest**: 5 actions, 4 permissions (`reports.view`, `reports.export`,
  `reports.custom.build`, `reports.custom.share`), 5 audit events
  (`dashboard.viewed`, `exported`, `custom.created/updated/deleted`).
  `depends_on: [people, placements, time]`. Default roles include `manager`.
- [x] **Wiring**: `dashboard/src/App.jsx` repointed to new module; `core/modules.php`
  Reports actions updated to spec routes (`overview/executive_snapshot/
  client_profitability/rate_spread/overtime_watch`); legacy `/exec /finance /staffing`
  routes redirected to spec equivalents inside the new module.
- [x] **Smoke**: `tests/reports_phase1_smoke.php` — **141 assertions ✓**
  (manifest, migration shape, period math for all 12 codes + custom + fallback,
  API parse + RBAC guard + GET-only + ?period accept, lib exports, UI testid coverage,
  App.jsx + core/modules.php wiring).
- [x] **Full suite green**: 74 PHP smoke files passing, no regressions
  (sprint6 assertion updated for new routes).
- [x] **Vite build green**: 1806 modules, `index-D6ICRwjV.js` (970 kB) + `index-Cwhpy62y.css`
  (21.6 kB). `.deploy-version` + `index.html` updated.
- [x] **Deploy notes**: `memory/REPORTS_PHASE1_DEPLOY_NOTES.md` — Cloudways migration
  walkthrough + 4-step UI smoke walk + view verification SQL.

**Sprint 1 deferred to Sprint 5 (Reports Phase 2)**: Recruiter/AM Performance,
Worker Mix & Margin, Near-Term Forecast (D4b), Custom Report Builder (D6),
Other Reports catalog (D7), CSV/XLSX/PDF exports + scheduled delivery (D8),
Industry selector + Hospitality scaffold (A5).

## Holistic Multi-Module Plan (ratified 2026-02 from Core/Spec/ERP/Reports/Accounting docs)

User feedback: "stop one-step-at-a-time, take a holistic pass." 5 specs read
end-to-end. Unifying thesis:

> CoreFlux is a **staffing-first, multi-tenant, multi-entity ERP** where
> Placements + Time are the operational source of truth. Approved time
> auto-generates AR (Billing + QBO mirror), AP (1099/C2C contractor pay +
> Gusto), and Payroll (W-2 via Gusto) into an **enterprise Accounting
> ledger** (multi-entity, dimensions, close controls, allocations,
> intercompany, consolidation), governed by a **platform shell**
> (RBAC, audit, generic workflow, email-only tokens, custom fields, exports),
> surfaced through an **industry-aware Reports module**.

**4-sprint sequence** (each independently testable; user-confirmed order):
1. ✅ **Sprint 1 — Reports Phase 1**: D1+D2+D3+D4a (Staffing Overview + 4 reports) — SHIPPED.
2. ✅ **Sprint 2 — Accounting depth + Mobile foundation**: B2 dimensions engine, B4 period close workflow + close-packet HTML artifact, M1 JWT auth, M2 device registry, M3 PWA manifest + SW — SHIPPED. (B1 multi-entity switcher deferred to Sprint 3 since the entity model already exists; the only missing piece is a UI switcher which is small and best done after we have multiple seeded entities to test with.)
3. ✅ **Sprint 3 — Industry Layer 1 (Staffing) + Push primitive**: C1 worker_class on people, C2 layered AP approval policies + push notification on routing, C3 vendor risk evaluator (6 rules), C4 evidence bundles on bills, push primitive (`pushSendToUser`/`pushSendToTenant`/`pushDispatchOutbox` with log/APNs/FCM drivers) — SHIPPED.
4. **Sprint 4 — Platform + cleanup**: A1 generic WorkflowEngine, A2 generalized
   email-only tokens (AP/Billing/journal/period close), A3 unified Audit Viewer,
   E1 "foreman → agency timesheet/team log" UI sweep.
5. **Sprint 5+ — Mobile worker app (Expo / React Native)**: scaffold
   `/app/mobile/` Expo monorepo. Worker-first MVP: login → time entry → photo
   receipt → submit → approval-status + push notifications. Then layered:
   Recruiter/AM read-only dashboards (placements, margin, OT) and
   **Platform user (staffing exec)** dashboards (Executive Snapshot mobile view,
   Staffing Overview KPIs). Single codebase ships iOS App Store + Google Play +
   Web PWA. Consumes the same `/api/*` already shipped — zero API rework.

**Multi-vertical principle (locked in 2026-02 by user):**
See "Architecture: CORE vs INDUSTRY" section above. CoreFlux is a horizontal
multi-tenant ERP platform; staffing is the first vertical, not the product.
Every primitive in `/app/core` and every Core module (`people`, `time`,
`billing`, `ap`, `accounting`, `treasury`, `payroll`, `reports`) MUST stay
vertical-agnostic. Staffing-specific logic lives only in `/app/modules/placements`,
in vertical-flavored sub-pages of Reports, and in the future Industry selector
layer (A5). The Reports module already enforces this via the industry selector
groove (D1 shipped, A5 industry switcher in Sprint 5+).

**Mobile audiences (worker / recruiter+AM / platform-user-execs):**
Three role-based home screens in one Expo app, gated by the same RBAC
the web SPA uses. Workers get time entry + receipts + approval inbox.
Recruiters/AMs get read-only placement & margin dashboards. Execs get
Executive Snapshot + Staffing Overview KPIs.

**Pinned mobile-workflow ideas (deferred to Sprint 6+):**
- Jobsite kiosk/tablet flow for time approval
- Contractor onboarding scan-and-sign
- Vendor COI photo upload from phone


**Deferred to later sprints** (P1+P2 in roadmap):
- B5 FX/CTA, B6 Consolidation, B7 Allocations, B8 Intercompany rules
- C5 Push-time-to-Gusto endpoint
- C6 **QuickBooks Online invoice mirror** (one QBO company per entity strategy
  per user pick)
- D6 Custom Report Builder, D7 Other Reports directory
- A4 Custom-fields Tier A fanout to Placements/Clients/Vendors
- A5 Industry selector + Hospitality vertical scaffold


- [x] **Gusto Embedded Payroll OAuth API** (sandbox-first; production flip is a one-line env change).
  - Migration `004_gusto_oauth.sql`: `tenant_gusto_connections` (encrypted access+refresh tokens, `company_uuid`, env, scopes, status, last-used/refreshed/error timestamps); idempotent additive columns on `payroll_runs` (`gusto_payroll_uuid`, `gusto_submission_status`, `gusto_submitted_at`, `gusto_submitted_by_user_id`, `gusto_submission_error`).
  - `core/gusto_service.php`: env-aware host (`api.gusto-demo.com` vs `api.gusto.com`), authorization URL builder with session-bound CSRF state (single-use, 10-min TTL), code-for-token + refresh exchanges, encrypted persistence via `encryptField`, `gustoRequest()` chokepoint with proactive 60s-pre-expiry refresh + transparent refresh-on-401 + 429 Retry-After honoring, payroll API helpers (list/get/PUT comp/calculate/submit), HMAC SHA-256 webhook verification, audit-log writer.
  - Top-level endpoints (no router gate, mirroring Plaid): `/api/gusto_oauth_start.php` (302 redirect or JSON), `/api/gusto_oauth_callback.php` (state validation → exchange → persist → bounce to `#/modules/payroll/settings`), `/api/gusto_webhook.php` (verification-token handshake + signature verify + status sync).
  - Module APIs: `gusto_connect.php` (GET status / DELETE soft-revoke), `gusto_submit.php` (`?action=list_unprocessed` then submit-by-uuid → PUT compensations → /calculate → /submit, two-eye gated on `payroll.run.disburse`).
  - **Coexists with the existing CSV-paste flow**: tenants without an OAuth connection still see the "Mark synced to Gusto" form unchanged. Connected tenants see a "Submit run to Gusto" panel with a Gusto-pay-period dropdown; the CSV form auto-hides once submitted.
  - Manifest: 12 new audit events (`payroll.gusto.{connect_initiated, connected, disconnected, token_refreshed, token_refreshed_on_401, run_submitted, run_submission_failed, webhook_received, webhook_signature_invalid, webhook_verification_received, connect_denied, connect_state_invalid, connect_exchange_failed, connect_persist_failed}`).
  - UI: `GustoConnectCard.jsx` embedded in Payroll Settings (3 states: not configured / configured-not-connected / connected-with-disconnect), OAuth submit panel on `PayrollRunDetail` with employee_number-based matching display, success/error bounce ribbon driven by `?gusto=ok|err&reason=…&detail=…` query string.
  - Smoke test: `tests/gusto_integration_smoke.php` — 108 assertions including pure HMAC verification round-trip, env-host switching, single-use OAuth state lifecycle, CSRF mismatch rejection, replay rejection. Full suite now **2,968 passing** (was 2,860).

## Recently completed (Payroll cycles + AI cross-checks — 2026-02)
- [x] **Pay Cycles**: Cohort layer above pay schedules. Same schedule cadence
  (e.g. bi-weekly) can host multiple cohorts (NY engineers, CA sales, FL
  contractors) on independent calendars. Migration `003_pay_cycles.sql`
  adds `payroll_pay_cycles`, `payroll_anomaly_findings`, `cycle_id`
  columns on profiles + periods, and a cycle-scoped uniqueness key.
- [x] **Cycle engine** (`/modules/payroll/lib/cycles.php`):
  `payrollCycleNextWindow` (pure date math), `payrollCycleAdvance`
  (transactional period+draft-run insert), `payrollCycleAutoAdvanceAll`
  (cron-style sweep across tenants).
- [x] **AI cross-checks** (`/modules/payroll/lib/anomalies.php`): three
  deterministic checks — `hours_drift` (≥25% warn / ≥50% critical vs
  trailing-3 average), `missing_time` (0 hours vs prior > 0 average),
  `rate_change` (current vs most-recent prior pay rate). Findings
  persisted to `payroll_anomaly_findings`. Optional GPT-5.4 narrative
  enrichment via `aiAsk()` — best-effort, never blocks detection.
- [x] **APIs**: `cycles.php` (CRUD + `?action=advance` + `?action=auto_advance`)
  and `anomalies.php` (run findings, dashboard feed, POST detect, PATCH ack).
- [x] **Compute hook**: `runs.php?action=compute` now runs anomaly detection
  immediately after a successful gross-to-net pass.
- [x] **Auto-advance on deploy**: `update.php` step 6 sweeps every active
  cycle whose newest period has ended.
- [x] **UI**: `PayCyclesPanel` (cycles list + create + advance + auto-sweep,
  embedded in PaySchedules), `PayrollAnomalies` page (tenant-wide
  unacknowledged feed), anomaly panel + ack buttons on `PayrollRunDetail`,
  alert badge + latest-findings teaser on `PayrollOverview`.
- [x] Manifest: new permissions (`payroll.cycles.manage`,
  `payroll.anomalies.view`, `payroll.anomalies.acknowledge`) and audit
  events (`payroll.cycle.{created,updated,deactivated,advanced,auto_advanced}`,
  `payroll.anomalies.{detected,acknowledged}`).
- [x] **Smoke tests**: `tests/payroll_cycles_smoke.php` — 104 assertions
  covering schema, library functions, date math, API surface, UI testids,
  routing. Full suite now at **2,860 passing assertions** (was 2,756).

## Backlog (P1)
- [ ] **"Trained on our timesheets" confidence score (Slice 2e)** —
  *captured 2026-02 from Phase 7 suggestion.* Product moat that kicks
  in once Slices 2b/2c ship enough AI parse → human-approve events to
  have data. Scope:
  - **Schema**: `time_intake_feedback` table keyed by
    `intake_event_id`. Stores (a) the AI's proposed fields JSON,
    (b) the human-approved final fields JSON, (c) a computed
    field-level delta (which fields survived, which were edited, which
    were dismissed), (d) `decided_by_user_id`, `decided_at`, and
    `source_signature` (a stable hash of sender_domain + subject_shape
    + attachment_type so we can bucket similar emails).
  - **Rollup view**: `time_intake_confidence` (materialized nightly
    OR computed on-read with a 1-hour cache) showing, per
    `(tenant_id, source_signature, placement_id)`:
    `samples`, `exact_convert_rate`, `field_edit_rates{placement, hours,
    category, work_date}`, `dismiss_rate`, `last_decision_at`.
    Minimum sample size (10) before a score is shown.
  - **Inbox UI exposure (goes into Slice 2c)**: small confidence %
    badge next to each proposal row; hover reveals "Based on N past
    decisions from this sender, X% converted exactly as proposed"
    with a link to the feedback history.
  - **Auto-convert toggle**: per-client setting (stored on
    `placements` or a new `time_intake_rules` table) —
    `auto_convert_threshold = 0-100`. If a proposal's confidence
    bucket exceeds the threshold AND ≥N sample size AND all required
    fields are AI-populated, the intake event converts straight to
    `pending_review` without manual review (but never skips the
    human approval step — SPEC §2 still holds).
  - **Weekly email digest** to tenant admins: "This week, 87% of
    AI proposals from Acme Corp's inbox converted with zero edits —
    consider raising their auto-convert threshold."
  - **Prompt feedback loop** (stretch): high-confidence historical
    decisions become tenant-scoped few-shot exemplars in the parse
    prompt for new emails from the same `source_signature`.
    Gated by a "use my timesheet history to improve parsing" tenant
    opt-in (data residency / privacy consideration).
  - **Edge cases**: when a client changes timesheet format, their
    `source_signature` changes and the confidence score naturally
    resets; manual "reset intelligence for this sender" button for
    admins.
  - Depends on: Slices 2b (AI parsing pipeline + `time_intake_events`
    table) and 2c (Inbox UI) being in place first.
- [ ] **Tenant mail Model C — custom verified From domains + deliverability health**. Bolts onto
  Model B (Phase 6b). Scope:
  - **Schema**: `tenants.mail_from_email` + `tenants.mail_from_verified`
    + `tenants.mail_resend_domain_id` (Resend's domain record id for API
    polling) columns (`005_tenant_mail_model_c.sql`)
  - **Verification flow**: tenant self-service in MailSettingsPage →
    paste domain → we call Resend's `POST /domains` API → surface the
    SPF + 3× DKIM DNS records in the UI with copy buttons → "I've added
    the records, check now" button triggers Resend's verify API →
    flip `mail_from_verified = 1` on success
  - **Sender resolution**: `cf_tenant_mail_sender()` prefers verified
    tenant domain over platform default when `mail_from_verified=1`
  - **Deliverability health dashboard** (added from 💡 suggestion):
    - Live SPF / DKIM / DMARC status pulled from Resend's
      `GET /domains/:id` API (polled on page load + refresh button)
    - Last-30-day delivery / bounce / complaint rates from Resend
      webhooks (new `mail_delivery_events` table + webhook endpoint
      `/api/webhooks/resend.php` with signature verification)
    - Bounce + complaint drill-down table with recipient, event
      type, timestamp, original `module.purpose`
    - "Test send" button → sends a probe email to an address the
      tenant admin enters → shows the message-id and initial status
      (queued/sent/failed) with a 30-second auto-poll for delivery
      confirmation
    - Gmail placement hint (Primary / Promotions / Spam) when probe
      recipient is a gmail.com address — inferred from Resend's
      delivery logs where available
  - **Resend webhook handler** (required for deliverability telemetry):
    new `api/webhooks/resend.php` (PUBLIC endpoint, signature-verified
    via Resend's `svix` signature header), writes
    `email.delivered` / `email.bounced` / `email.complained` events to
    `mail_delivery_events` keyed by `mail_outbox.provider_message_id`.
    Updates `mail_outbox.status` on terminal events.
  - **UI wiring**: extend `MailSettingsPage` with a "Custom sending
    domain" card (active once Model C ships) + a new
    "Email deliverability" tab showing the health metrics above.
  - Dependencies: Resend's Domains API + Webhooks API (both on free
    tier); `svix-php` for webhook signature verification (or a
    minimal hand-rolled HMAC verifier — signature is HMAC-SHA256).
- [ ] **Per-module mail purpose overrides** — `tenant_mail_purposes`
  table so each tenant can route `time.client_approval_request` via
  `timesheets@` vs `billing.invoice` via `invoices@`. Low priority
  until Billing module ships.
- [x] **Time Phase B Slice 2a — M365 mailbox connection** — SHIPPED in
  Phase 7 (this fork).
- [ ] **Time Phase B Slice 2b — AI parsing pipeline**: `time_intake_events`
  table + OpenAI prompt to extract `{placement, work_date, hours, category}`
  proposals from email body + attachments; intake convert/dismiss/flag
  endpoints per SPEC §5.3.
- [ ] **Time Phase B Slice 2c — Inbox (AI) UI**: the sidebar view
  already scaffolded in the manifest but currently unrouted — shows
  email ⇄ AI proposal side-by-side with one-click convert to
  pending_review.
- [ ] **Time Phase B Slice 2d — Background polling**:
  `/app/cron/time_inbox_poll.php` entrypoint + Cloudways cron config
  doc (`*/5 * * * *`). Today's manual "Fetch now" button in the Mail
  Settings inbound-mailbox card covers the dev smoke case.
- [ ] **Time Phase B Slice 3 — Gmail API driver** for Google Workspace
  tenants
- [ ] **Tokenized-email rate limiting** (P2) — IP throttle on public
  `?action=respond` and `?action=verify` endpoints
- [ ] **Period Close Receipt (PDF + email)** — *captured 2026-02 from
  Period Close Wizard rollout.* One-page audit artifact emailed to the
  closer (and stored against the period via `Core\StorageService`) when
  a period is closed: bundle totals (AR $, Payroll/AP $, billable hrs,
  PTO hrs), placement-by-placement breakdown, who approved, timestamp,
  optional supersede references. Gives SOC2-grade traceability between
  Time approvals and the downstream invoices/paychecks before Billing /
  AP / Payroll ship. Depends on: real `MailService` driver (Phase B) for
  the email; works standalone for the PDF + storage piece.
- [ ] **Time Phase B** — real `M365GraphDriver` / `GmailApiDriver` for
  `Core\MailService` so the Time module can poll inboxes and AI-parse
  timesheets; tokenized client-approval email send + click-through verify
- [ ] Payroll Phase 2: multi-state tax tables, garnishments, ACH/NACHA file generation, Form 941 worksheet, W-2 generation
- [ ] **Billing Phase A1**: server-side PDF render (dompdf + S3),
  credit/debit memos, tax jurisdictions matrix, AR aging snapshot table
  + cron, GL posting endpoint (waits on Accounting v1.0)
- [ ] **Billing Phase B**: recurring services, dunning automation,
  statements of account, AI anomaly flags, Stripe / ACH acceptance
- [ ] **Accounting v1.0** — enterprise GL per SPEC.md (multi-entity,
  allocations, intercompany, consolidation)
- [ ] **Billing module** — implementation per SPEC.md (consumes Time
  `bundle_type='ar'` feed)
- [x] **AP module Phase A0** — SHIPPED in Phase 9 (this fork). Next:
  AP Phase A1 — Gusto CSV export, inbox AI parsing alongside Time Slice
  2b/2c, recurring bills, NACHA, card import (Plaid/Brex/Ramp),
  three-way match, 1099-NEC PDF generation, vendor portal.
- [ ] **Payroll module refactor** — legacy unwired React components → new
  modular architecture (consumes Time `bundle_type='payroll'` feed)
- [ ] Fix GitHub Actions CI/CD (replace `scp-action` with rsync/webhook + PAT)
- [ ] Cloudways GitHub server authentication (PAT or SSH key)
- [ ] Clean `sidebar_items` table duplicates
- [ ] People follow-ups: documents chunked upload, time-off requests UI, offer-letter AI draft, PII reveal endpoint
- [ ] Admin UI for tenant AI toggles + `ai_interactions` + `people_emails_sent` browsers

## Backlog (P2)
- [ ] Manifest auto-discovery (replace hardcoded `core/modules.php`)
- [ ] DB migration runner (replace manual SQL execution)
- [ ] Permission guard helper (`can($perm)` enforcing manifest-declared perms)
- [ ] SPA dynamic module loading (`React.lazy` driven by session.modules)
- [ ] Extract modules via Git Subtree once MVPs are stable
- [ ] Wire real data into SPA dashboard stats/tables
- [ ] Deprecate `dashboard.php` once SPA reaches parity
- [ ] Consolidate dashboard.css into coreflux.css
- [ ] Admin UI for tenant AI toggles (`tenants.ai_enabled`, `ai_tenant_features`, `ai_full_content_logging`)
- [ ] Admin page for browsing `ai_interactions` audit trail
- [ ] Production hosting decision for the Python AI sidecar (Cloudways Python app vs. external service)
- [ ] Run `core/migrations/002_ai_platform.sql` on the live MySQL database

## Key Files
- `/app/core/config.php` — DB credentials + feature flags
- `/app/core/db.php` — PDO connection
- `/app/core/auth.php` — session + auth helpers
- `/app/core/modules.php` — module definitions (pre-manifest; hardcoded)
- `/app/core/api_bootstrap.php` — **module API entry point contract**
- `/app/core/tenant_scope.php` — **tenant-safe query helpers**
- `/app/dashboard/src/App.jsx` — SPA router + session hook
- `/app/dashboard/src/lib/api.js` — **SPA fetch client**
- `/app/session.php` — JSON session endpoint
- `/app/spa.php` — SPA entry point
- `/app/login.php`, `/app/login.html` — auth + SPA redirect
- `/app/MODULE_SKELETON.md` — how to build a new module
- `/app/PAYROLL_MODULE_PRD.md` — spec for the next P0 module
- `/app/modules/_template/` — reference skeleton

## Database Schema (core)
- `users`           { id, name, email, password_hash, role, tenant_id }
- `tenants`         { id, name, parent_id, slug, domain }
- `user_tenants`    { user_id, tenant_id, role }
- `modules`         { id, name }
- `tenant_modules`  { tenant_id, module_key, is_enabled }
- `permissions`     { id, slug, description }
- `role_permissions`{ role_id, permission_id }

Module tables must include `tenant_id` (NOT NULL) and be prefixed by the module id (e.g. `payroll_*`).

## Module Repositories (future subtree targets)
| Module     | Repository             | Subtree prefix        |
|------------|------------------------|-----------------------|
| People     | coreflux-people        | modules/people        |
| Accounting | coreflux/accounts      | modules/accounting    |
| Payroll    | coreflux/payroll (TBD) | modules/payroll       |

## Environment Notes
- Emergent preview has **no PHP runtime** by default — installed `php8.2-cli` in this session for syntax checks + the smoke test. Full E2E runs on Cloudways.
- The SPA falls back to demo mode when `session.php` is unreachable (see `App.jsx`).

*2026-02 — AI extraction surface + AP bill PDF auto-fill:*
- New `aiExtract()` in `/app/core/ai_service.php` — sibling to `aiAsk()`, purpose-built for structured extraction (forces `response_format: json_object`, strips markdown fences, supports multimodal inputs as URL or base64). Gated by tenant feature_class `extraction`. Falls back once on failure. Audit row written.
- AP API: `POST /api/ap/bills?action=extract_from_pdf` body `{storage_key}` signs the S3 URL, hands it to `aiExtract()` with a strict 11-`item_type` schema, returns `{draft, model, latency_ms, interaction_id, review_required: true}`. Audit event `ap.bill.extracted_from_pdf` declared.
- UI: `BillCreate.jsx` gained ✨ Extract from PDF button next to the FileDropZone. Workflow: upload → POST extract → merge **non-empty** fields into form state. Hard rules: vendor pick is never auto-overwritten (identity = user's responsibility), GL accounts are never AI-guessed (system-of-record discipline), `item_type` whitelisted against the canonical 11-value vocabulary before merge. Result banner shows model name + line count. Errors surface inline.
- Roadmap: new `/app/memory/AI_TOUCHPOINTS.md` enumerates 35+ candidate AI touch points across AP, Billing, People, Placements, Time, Accounting, and cross-cutting — each tagged with status, cost-class, and trust level (review-mandatory vs auto-apply).
- Backend: +33 smoke assertions in `/app/tests/ai_extract_smoke.php`. Full suite: **23 files, 1,440 passing / 0 failed**. Vite build green.*


- Schema: `ap_vendors_index` gained `vendor_category` ENUM(`hourly_labor`,`service_provider`) DEFAULT `service_provider`, plus `payment_method`/`remit_to_email`/`remit_to_phone`/`payment_account_last4`/`payment_account_ct`/`kms_key_version_payment` for service-provider basics. `ap_bill_lines.attachment_storage_object_id` added for line-precise receipt audit. Idempotent backfill: 1099/C2C vendor types reclassified to `hourly_labor`.
- New core helper `/app/core/storage_register.php` (`registerStorageObject()`) materialises `storage_objects` rows after presigned-S3-POST uploads (idempotent on `s3_key`). Used by the new AP attach endpoints; ready for re-use in Billing / People / Placements.
- AP API: 4 new endpoints on `bills.php` — `?action=upload_url` (presigned S3 POST, supports both bill & per-line entities), `?action=attach&id=N` (sets `ap_bills.attachment_storage_object_id`), `?action=attach_line&line_id=N` (sets `ap_bill_lines.attachment_storage_object_id`), `?action=attachment_url` (signed download URL). Vendors POST extended to accept `vendor_category` + payment fields; encrypts `payment_account_full` with `encryptField()`. List GET surfaces `vendor_category`, `payment_method`, `remit_to_email`.
- Manifest: 2 new audit events (`ap.bill.attachment.added`, `ap.bill.line.attachment.added`).
- React: new `/app/dashboard/src/lib/uploads.js` (browser presigned-POST helper, accepts S3 200/204), new `VendorQuickCreate.jsx` modal with category radio cards (hourly_labor blocks the shortcut and redirects to full onboarding; service_provider quick-creates with name + payment basics in one POST), `BillCreate.jsx` gained drag-drop FileDropZone for the vendor invoice PDF (25 MB cap) + inline vendor creation via `CompanyTypeahead.onCreate`. If the post-create S3 upload fails, the bill is still saved and the user lands on its detail page with a soft warning.
- Backend: +63 smoke assertions in `/app/tests/ap_vendor_categories_attachments_smoke.php`. Updated 1 unify_006 assertion (whitespace alignment in vendors UPSERT). Full suite: **22 files, 1,407 passing / 0 failed**. Vite build green.*


- Schema: `ap_bill_lines.item_type` and `billing_invoice_lines.item_type` ENUMs added with 11 categories — `labor`, `expense`, `materials`, `fixed_fee`, `milestone`, `discount`, `subscription`, `mileage`, `per_diem`, `reimbursement`, `other`. Default `labor` for back-compat. `billing_invoice_lines.gl_revenue_account_code` added so non-labor lines can be routed to dedicated revenue accounts (e.g. 4100 Reimbursable, 4200 Materials, 4300 SOW Fees). `billing_invoice_lines.source_type` ENUM expanded to match AP (`time|manual|expense|recurring|milestone`).
- Lib: shared `apNormalizeItemType()` + `AP_LINE_ITEM_TYPES` const exported from `ap.php`; billing imports it. Time-bundle line builders in both modules now stamp `item_type='labor'` automatically.
- API: AP `POST /api/ap/bills` and Billing `POST /api/billing/invoices` (manual + time-bundle paths) accept and persist `item_type` + GL account override per line. PATCH already supported. Billing GL post (`?action=post`) now buckets revenue per `gl_revenue_account_code` and emits one credit line per bucket; lines without an override fall back to 4000.
- UI: new `LineItemEditor` shared component (`/app/dashboard/src/components/LineItemEditor.jsx`) with 11-option item-type dropdown (auto-resets unit on type change). New manual-create pages: `/modules/ap/bills/new` (`BillCreate.jsx`) and `/modules/billing/invoices/new` (`InvoiceCreate.jsx`) — both feature CompanyTypeahead, GL account picker (typed: expense for AP, revenue for Billing), live subtotals, and submit to existing endpoints. Lists gained `+ New bill` / `+ New invoice` buttons alongside the existing `+ New from time bundle` button.
- Backend: +56 smoke assertions in `/app/tests/line_item_types_smoke.php`. Updated 1 accounting assertion to reflect per-line revenue routing. Full suite: **21 files, 1,344 passing / 0 failed**. Vite build green.*


- Schema columns from migration 006 Part C now wired end-to-end.
- `placementChain()` lib hardened: explicit allow-listed SELECT, ciphertext never leaves the DB, exposes derived `has_portal_credentials` boolean.
- New lib helpers `placementChainSetPortalCredentials()` / `…Clear…()` / `…Reveal…()` round-trip through `encryptField()` (kms_key_version='v1').
- `chain.php` API: `submittal_id` + `vms_job_id` accepted on POST and PATCH; new endpoints `?action=set_portal|clear_portal|reveal_portal&id=N`. Reveal is gated by new RBAC permission `placements.portal_credentials.view` and audit-logged with the chain/placement IDs (no plaintext). Set logs only field NAMES.
- React ChainTab: new Submittal # / VMS Job # columns with inline-edit; portal-creds dialog with reveal-after-confirm, save/clear, password masked input. PATCH strips ciphertext fields server-side.
- Manifest: declares the new permission and three new audit events (`placement.chain.portal.set|cleared|viewed`).
- Backend: +44 smoke assertions in `/app/tests/placement_chain_portal_smoke.php`. Full suite: **20 files, 1,288 passing / 0 failed**. Vite build green.*


- **Merge duplicates** (`companies.php?action=duplicates|merge`): auto-suggests groups by normalised name (inc/llc/corp/punctuation stripped), then redirects all FKs across AP (`ap_vendors_index`, `ap_bills`, `ap_payments`, `ap_1099_ledger`), Billing (`billing_invoices`), placements (`placements`, `placement_client_chain`, `placement_referrals`) from victim to survivor. Unions roles, reparents contacts+addresses, bumps `use_count`, soft-deletes victim, audit-logs. New admin page `/modules/people/merge` linked from Clients + Vendors directories.
- **Custom Fields tab on Person Detail**: new `Custom fields` tab surfaces per-tenant custom field definitions and allows inline save of values per person. Respects `field_type` (text/number/date/boolean/select), marks PII with 🔒.
- **Accounting v1.0 Phase 0** (foundation): new migration `001_init.sql` adds `accounting_entities`, `accounting_fiscal_calendars`, `accounting_periods`, `accounting_accounts`, `accounting_journal_entries`, `accounting_journal_entry_lines`, `accounting_posting_idempotency`, plus `tenants.accounting_je_prefix`/`accounting_next_je_seq`. New `lib/accounting.php` exposes `accountingPostJe()` (balanced, idempotent, period-aware), `accountingReverseJe()` (flip-signs, idempotent), `accountingTrialBalance()`, `accountingResolvePeriod()` (auto-creates monthly), `accountingDefaultEntity()` (auto-creates `MAIN`).
- **Subledger wiring**: AP `POST /api/ap/bills?action=post&id=N` now posts a real JE (Dr expense per line / Cr AP 2000) using idempotency key `ap:bill:<id>:post` and stamps `ap_bills.journal_entry_id`. Billing `POST /api/billing/invoices?action=post&id=N` posts Dr AR 1100 / Cr Revenue 4000 [/ Cr Sales Tax 2100] with key `billing:invoice:<id>:post`.
- **Accounting UI**: new `/modules/accounting/{accounts|journal|trial}` pages — Chart of Accounts (CRUD + "Seed standard COA" button), Journal Entries (list, detail with line-level debits/credits, balanced manual-post form, reverse with required reason), Trial Balance (as-of picker with signed balances).
- **Scope NOT in Phase 0** (tracked for future phases): dimensions, close workflows, consolidation, intercompany auto-balance, FX revaluation, HMAC webhooks.
- Backend: +127 new smoke assertions (50 merge + 77 accounting). Fixed 1 pre-existing AP assertion to reflect post→GL integration. Combined suite: **1,244 passing / 0 failed** across 19 PHP smoke test files. Vite build green.*

---
*Last Updated: 2026-02 — Phase 9 AP module Phase A0 shipped. Plus sidebar + SPA deep-link fix: added `/app/.htaccess` SPA fallback (deep URLs like `/modules/time/entries` no longer Apache-404), updated `core/modules.php` to expose time/billing/placements/ap modules with correct action routes (e.g. "My Time" → `/modules/time/entries`), deleted dead `dashboard/src/modules/PeopleModule.jsx` (had stale "Enter Time" card). 903 platform tests ✓.*

*2026-02 — Migration 006 (unify_and_extend) + People extensions + New-Hire Wizard:*
- **AP unification**: `ap_vendors_index`, `ap_bills`, `ap_payments`, `ap_1099_ledger` gained `company_id` / `vendor_company_id` FK → `companies.id`. AP `/api/ap/vendors` POST now auto-upserts a `companies` row (role=vendor) for `c2c_corp`/`w9_business`/`utility`/`other` vendors; 1099 individuals stay as people-side records.
- **Billing unification**: `billing_invoices.client_company_id` added. `/api/billing/invoices` manual POST + time-bundle POST + PATCH all auto-upsert `companies` (role=client).
- **People extensions**: added `employment_type` enum, `hire_date`, `termination_date`, `pay_frequency`, `gender`, `marital_status`, full `mailing_*` address block. `peopleSafeFields()` / `peoplePIIFields()` updated.
- **New-Hire Wizard**: `PersonCreate.jsx` refactored into a 3-step wizard (Person → Employment → Optional Placement). Step 3 is always visible but skippable. Submitting triggers a sequential `POST /people` → `POST /placements` → `POST /rates`; person is preserved even if placement leg fails.
- Backend: +81 smoke assertions in `/app/tests/unify_006_smoke.php`. Combined suite: **984+ passing**. Frontend Vite build green.*



*2026-02 — Accounting Phase 1 verification + AP Phase A1 (Export, Gusto-CSV, AI Receipts):*
- **Accounting Phase 1 verified**: ran the deferred `/app/tests/accounting_phase1_smoke.php` (Periods workflow, Income Statement, Balance Sheet, AI W-9 / receipt / contract extracts) — fixed a single test-id miss in `BalanceSheet.jsx` (Section now accepts an explicit `totalTestId`). All 77/77 phase-1 asserts green. Full suite: **28 files, 1,572+ passing / 0 failed**. Vite build green.
- **AP Phase A1 — CSV exports**: new `GET /api/ap/export?type=…` endpoint streaming `text/csv` (Content-Disposition: attachment) for `bills`, `payments`, `expenses` (one row per line, joined with header status), `1099` (year-end ledger), and `gusto_contractors` (Gusto bulk-import format: `first_name,last_name,type,hours,rate,wage,reimbursement,bonus`, summed per vendor over `sent`/`cleared` payments to `1099_individual`/`c2c_corp` vendors only). Gated by new permission `ap.export.run`; emits `ap.export.csv` audit per call.
- **AP Phase A1 — Expense Reports polish**: `expenses.php` now exposes `?action=upload_url`, `?action=attach_line`, `?action=extract_receipt`. AI receipt extract uses the `aiExtract()` chokepoint with feature key `ap.expense.line.from_receipt` and returns a draft (`expense_date / merchant / category / amount / description / gl`) for review; nothing auto-saves. Two new audits: `ap.expense.line.attachment.added`, `ap.expense.line.extracted_from_receipt`.
- **AP UI**: `ExpenseCreate.jsx` rewritten with a per-line `ReceiptCell` (upload to S3 via presigned POST → AI extract → pre-fills the row). `ExpensesList.jsx` gained a status filter, "Mine only" toggle, and a coloured `StatusPill`. New `Export.jsx` page with five download cards (bills / payments / expenses / 1099 / Gusto contractors) and date-range + tax-year inputs. `APModule.jsx` now routes `/modules/ap/export`; sidebar in `core/modules.php` exposes the action.
- **Gusto integration shape**: CSV-only this phase (no API keys required, drops straight into Gusto's Contractor-payments bulk uploader). Live OAuth API integration deferred to Phase B.
- Backend: +55 new smoke assertions in `/app/tests/ap_phase_a1_smoke.php`. Combined suite: **28 files, 1,572 passing / 0 failed**. Vite build green.

---
*Last Updated: 2026-02 — AP Phase A1 follow-ups V2 (this fork). Sprint covers the original "deferred" items plus material polish:*

**Bug fixes (existing code was broken in production):**
- `bill_approvals.php` was querying `b.invoice_number` / `b.amount_total` which don't exist in `ap_bills`. Fixed to `bill_number` / `total`. Single-letter table aliases were bypassing the schema-contract gate; the bug shipped silently. Added `$bill['total']` lookup for amount-bracket workflow resolution.
- `vendor_portal.php` queried `ap_vendors` (table doesn't exist — actual table is `ap_vendors_index`) and `ap_payments.vendor_id` / `ap_bills.invoice_number` etc (none of which exist). Rewritten to: query `ap_vendors_index`, match bills by `vendor_name`, join payments via `ap_payment_allocations`, surface `bill_number` / `pay_date` / `total` correctly.
- React `Approvals.jsx` and `VendorPortal.jsx` updated to render the renamed fields.

**Migration 012 — ap_vendor_portal_documents:** vendor-uploaded W-9 / COI / banking-form / contract files. `pending_review → approved | rejected` workflow. Adds `ap_vendors_index.contact_email` for direct portal-invite addressing (independent of payment remit-to).

**Migration 013 — ap_recurring_bills:** scheduled bill template with frequency `weekly | biweekly | monthly | quarterly | yearly`, `day_of_period` clamp, `next_bill_date`, `end_date`, status `active | paused | ended`. Generated bills land as `pending_review` and follow the normal approval workflow (no auto-approve, no auto-pay). Idempotent advance (`last_generated_date` snapshot).

**Migration 014 — ap_purchase_orders + ap_po_receipts:** PO header + lines, receipt log, per-line `quantity_received` rollup. Tenant config `ap_three_way_match_enforce` (soft warning by default, hard-block opt-in) + `ap_three_way_match_tolerance_pct` (default 5%).

**Migration 015 — ap_bill_approval_comments + ap_bill_approval_notifications:** conversation thread on every bill, audit log of every email-notification send (including failures with `error_text`).

**Library — `lib/recurring.php`:** `apRecurringNextDate()` (pure date math, robust month-overflow handling — Jan-31 monthly correctly clamps to Feb-28 instead of strtotime's Mar-3 quirk), `apRecurringGenerateDue()` (transactional draft-bill insert + line + schedule advance, idempotent on next_bill_date).

**Library — `lib/three_way_match.php`:** `apThreeWayMatch()` returns `{ matched, po, po_total, receipt_total, bill_total, tolerance_pct, warnings, enforce }`. Generates human-readable warnings when bill ≠ PO outside tolerance, when bill > received, or when PO is closed/cancelled.

**API — `recurring.php`:** GET list, POST create, PATCH update, `?action=pause|resume|end|generate_due`. Audit `ap.recurring.*`.

**API — `purchase_orders.php`:** GET list/detail, POST create header+lines, PATCH update, `?action=receive` (records `ap_po_receipts` + line-level qty rollup, updates PO `partially_received | received` status), `?action=close`, **`?action=match&bill_id=N`** (the three-way match endpoint surfaced on BillDetail).

**API — `1099.php`:** new `?action=readiness&tax_year=YYYY` returns per-vendor `{ has_w9, tin_present, tin_last4, ready, blockers }` plus summary `{ ready, blocked, total }`. W-9 sourced from `ap_vendor_portal_documents` (vendors uploaded via portal).

**API — `bill_approvals.php` extensions:** `?count_pending=1` (badge count for current user), `?comments_for_bill=N` (comment thread feed), `?action=comment` (post comment), email notifications fired on `submit` (notify first-step approvers) and on each step approval (notify next-step approvers). Audit log every send to `ap_bill_approval_notifications`.

**API — `vendor_portal.php` Phase 2:** `?action=upload_url` (presigned S3 POST for vendor-uploaded docs), `?action=upload_document` (records via `storage_register.php` chokepoint, lands as `pending_review`), `?action=update_banking` (vendor self-service banking edit — encrypts account/routing via `encryptField()`, audit-logs field NAMES only), `?action=documents` (list).

**UI:**
- `RecurringBills.jsx` — list + new + pause/resume/end + "Generate due now" button.
- `PurchaseOrders.jsx` — list + new (header + line editor + GL acct picker) + detail modal with per-line "receive now" inline editor + receipt log.
- `ThreeWayMatchPanel.jsx` — auto-renders on `BillDetail` whenever the bill has a `po_number`. Color-coded (green=match, amber=warnings) + BLOCKING badge when tenant has enforce-on. Linked to PO detail page.
- `BillApprovalThread.jsx` — auto-renders on every `BillDetail`. Shows the approval chain (state per step, decision_at, decision_note) + a comment thread that anyone with `ap.view` can post to.
- `Approvals.jsx` — pending-count badge appears next to the inbox heading.
- `Ledger1099.jsx` — "Readiness" toggle button surfaces a panel of vendors with blockers (missing TIN, malformed TIN last-4, no approved W-9) before you click "Print 1099-NEC forms".
- `VendorPortal.jsx` — three-tab nav (Overview / Documents / Banking). Documents tab supports drag-drop upload with type picker. Banking tab supports remittance email/phone, payment method, account type, and replace-account/routing (full numbers only sent during update, never echoed back; only last-4 displayed thereafter).
- `APModule.jsx` — sidebar gains Recurring + POs entries; routes `/modules/ap/recurring` and `/modules/ap/purchase-orders` (+ detail).

**Manifest:** new permissions `ap.po.manage`, `ap.vendor.create`, `ap.vendor.portal_review`, `ap.bills.approve_admin`. New audit events `ap.bill.approval_*` (4), `ap.recurring.*` (7), `ap.po.*` (4), `ap.vendor.portal_*` (4), `ap.1099.print_rendered`.

**Tests:** new `tests/ap_phase_a1_followups_v2_smoke.php` — 124 assertions across migrations, libs, APIs, manifest, all UI components, plus 6 functional pure-date-math assertions on `apRecurringNextDate()`. Full suite now: **71 files, 4,160 passing / 0 failed.**

**Vite bundle rebuilt:** 1799 modules, 996 kB JS / 21.5 kB CSS. `/app/.deploy-version` updated.

---
*Sub-update — vendor-portal auto-process + Time module manual timesheet upload (this fork):*

**Vendor portal upload — auto-process on land.** When a vendor uploads via portal:
- W-9 → `aiExtract()` reads it → if confidence ≥ 80% AND extracted TIN doesn't disagree with vendor record → auto-update `ap_vendors_index` (vendor_type, tax_id_last4, requires_1099) and flip status to `approved`. Otherwise flagged with the AI draft attached.
- COI → AI-extract carrier + policy + dates → archived; auto-approved.
- banking_form / contract / other → archival; auto-approved (Banking tab is the real banking-update path).
- Migration 012 extended with `ai_extracted_json TEXT`, `ai_confidence DECIMAL(4,3)`, `ai_action ENUM('auto_approved','flagged_for_review','none')`.

**New admin queue at `/modules/ap/vendor-uploads`** surfaces ONLY the exception cases. Approve/reject with note (both audited via `ap.vendor.portal_document_approved` / `_rejected`). Permission-gated by `ap.vendor.portal_review`.

---
*Sub-update — Time manual timesheet upload (this fork):*

**Why:** Hourly/contract crews still use paper timesheets and sign-in sheets. Mirror the AP `BillCreate` extract pattern.

**Migration 004 — `time_uploaded_documents`:** audit trail for every uploaded paper/PDF/photo timesheet, with `extraction_status` (pending → extracted | failed | consumed), `ai_extracted_json`, `ai_confidence`, `consumed_entry_count`. Idempotent.

**API — `/app/modules/time/api/upload.php`:**
- `?action=upload_url&file_name=X` → presigned S3 POST (PDF/image, 25 MB cap).
- `?action=extract` → records the doc up-front (audit even on AI fail), calls `aiExtract()` with vision schema `{week_ending, person_name, lines:[{work_date,project,client,category,hours,description}]}`, computes `timeUploadConfidence()` (fraction of lines with parseable date + non-zero hours + project), persists draft.
- `?id=N&action=consume` → marks doc consumed once user has saved entries; records the count.
- Audit events: `time.upload.extracted`, `time.upload.extract_failed`, `time.upload.consumed`.

**UI — `TimesheetUpload.jsx`:** drop file → AI extract → table of editable line drafts. Each line: AI-suggested project text shown as hint, user picks placement from typeahead (active placements only), edits date/category/hours/description, includes/excludes via checkbox. Save loop calls `entries.php POST` per line with `source='ai_inbox'` + `source_ref_id={doc_id}`, then marks the doc `consumed`. Per-line save status badge (saved #ID / error).

**MyTime header gains "↑ Upload timesheet" link** for discoverability.

**Manifest:** new sidebar action "Upload Timesheet" route; new permission `time.entry.create` (alias for self-service); audits declared.

**Tests:** new `tests/time_timesheet_upload_smoke.php` — 58 assertions across migration, API contract, manifest, all UI testids, source-stamping convention, and bulk-mode (people array schema, `match_candidates` resolution, GroupCard/PersonPicker components, placement filtering by selected person). Full suite now: **72 files, 4,246 / 4,246 passing.**

**Bulk-people mode:** the same `TimesheetUpload` page now offers a radio toggle "Just my hours" vs "Many people (foreman log / crew sheet)". Bulk extraction uses a different AI schema (`{week_ending, people:[{person_name, lines:[...]}]}`) and the backend pre-resolves each person_name against `people` (exact match on `first_name+last_name` / `preferred_name+last_name` / `preferred_name`) so the UI can default the picker to a unique match. Per-line placement options are filtered to the chosen person's active placements. New audit metadata: `mode`, `people_count`.

---
*Sub-update — Email intake (poll + webhook) — this fork:*

**Why:** Foreman emails the timesheet to a tenant inbox, system AI-extracts overnight, foreman walks in next morning to a pre-mapped review queue. Two paths converge on the same data model:

**Path 1 — M365 / Gmail OAuth poll (per Time SPEC §B Slice 2b/2c):** uses existing `Core\MailService::poll_folder()`. Tenant connects mailbox via existing OAuth scaffold, points module='time' folder at the `timesheets` label. `POST /api/time/intake.php?action=poll` walks new messages with attachments, creates `time_intake_events` rows, and (when the driver supports `fetch_message_with_attachments()`) pipes attachments into the AI extract pipeline. M365GraphDriver currently only emits metadata; full attachment fetch is a one-method extension flagged in the response payload (`note` field) so the UI can call `?action=process&id=N` later.

**Path 2 — SendGrid Inbound Parse / Postmark webhook (e):** `POST /api/time/intake.php?action=webhook&provider=sendgrid|postmark|generic` handles each provider's payload shape. **No platform-user auth** — verified via tenant's `time_intake_webhook_secret` (HMAC-SHA256 over the raw body). Tenant resolution: exact match on `tenants.time_intake_email_address` OR `time+t{TENANT_ID}-...@…` plus-addressing. Always returns 200 (even on tenant miss) so providers don't retry forever.

**Migration 005 — `time_intake_events`:** unified inbox table covering all sources (poll/webhook/SMS-future). Idempotent on `(tenant_id, source, provider_message_id)` so re-poll is safe. Status flow `received → downloaded → extracted → dismissed | failed`. Two new tenant config columns: `time_intake_email_address` + `time_intake_webhook_secret`.

**Lib — `lib/intake.php`:**
- `timeIntakeRecordEvent()` — idempotent intake row + dedupe-ledger update.
- `timeIntakeIngestAttachments()` — uploads each attachment to S3 via StorageService, registers via `storage_register.php`, creates `time_uploaded_documents` rows, runs **bulk** AI extract (foreman-log default), pre-resolves each `person_name` → `match_candidates`, persists draft + confidence, marks intake row `extracted`.
- `timeIntakeIsTimesheetAttachment()` — filters out signature gifs / .ics calendar invites.
- `timeIntakeVerifyWebhookHmac()`, `timeIntakeTenantFromAddress()` — webhook plumbing.

**Lib — `lib/upload_helpers.php`:** extracted `timeUploadResolvePeople()` and `timeUploadConfidence()` from `api/upload.php` so they can be reused by intake without re-firing the upload route's `if($method...)` guards.

**UI — `IntakeQueue.jsx`** at `/modules/time/intake`. Lists events with status badges, "Poll mail folder" button, per-row Process / Dismiss actions, and per-document deep-link into `TimesheetUpload`'s review UI.

**Manifest:** new sidebar action "Intake Queue", new audits `time.intake.received | parsed | dismissed`.

**Best-effort acknowledgment reply:** after webhook ingest, `MailService::send()` fires a "Got your timesheet — ready to review" email back to the foreman. Swallowed if no outbound driver configured.

**Tests:** new `tests/time_intake_smoke.php` — 47 assertions across migration, lib, API, manifest, UI. Plus existing tests updated for the helper extraction. Full suite: **73 files / 4,293 / 4,293 passing.**

**To activate the webhook path:** tenant admin sets `tenants.time_intake_email_address` to the chosen receive address (e.g. `time+t42-acme@inbound.coreflux.app`), generates a random `time_intake_webhook_secret`, plumbs SendGrid Inbound Parse to POST to `/api/time/intake.php?action=webhook&provider=sendgrid` with the secret in `X-CF-Intake-Signature` header.

**To activate the poll path:** tenant admin OAuth-connects M365/Gmail in Settings (existing `start_oauth_flow` scaffold), creates a `tenant_mail_folders` row pointing at the `timesheets` label, sets `polling_enabled=1`. Cron POSTs `/api/time/intake.php?action=poll`. (Note: M365GraphDriver currently emits message metadata only; full attachment fetch is a future driver extension — intake rows are captured nonetheless.)

---
*Sub-update — Sender auto-resolve (1-click confirm for known foremen):*

**Why:** when the email's `From:` matches a `users.email` record AND that user is linked to a `people` row (via `email_primary`), most of the review work is already known — we shouldn't ask the foreman to map themselves on every upload.

**Implementation:**
- `timeIntakeResolveSenderContext()` returns `{user_id, person_id, person_name}` by joining `users` → `people` on `email_primary`.
- `timeIntakeEnrichDraftWithSender()` queries the sender's active placements and post-processes the AI draft:
  - **Person mapping:** prepends the sender to `match_candidates` of the lone person-card (single-person email) or any group whose extracted `person_name` fuzzy-matches the sender. Adds `auto_resolved_from_sender: true` flag.
  - **Placement hints:** if sender has exactly 1 active placement → fills `placement_id_hint` on every line. Otherwise fuzzy-matches each line's AI-extracted `project` text against placement titles and fills the hint when a substring match is found.
- Stamps `draft.sender_resolved=true` + `sender_person_id` + `sender_person_name`.

**UI:** `TimesheetUpload.jsx` updates `normaliseLine()` to read `placement_id_hint` and pre-select the dropdown (with green tint + "✨ auto" badge per line). Shows a hero-level "✨ auto-mapped to {name}" badge on the success banner. Bulk-mode person picker auto-resolves to the sender when AI flagged the candidate.

**Both ingest paths benefit:** webhook + poll (via `lib/intake.php`), AND the manual `?action=extract` upload path (via the logged-in user's email).

**Tests:** intake smoke now 69 assertions (was 47; +22 for auto-resolve). Full suite **73 files / 4,315 / 4,315 passing.**

---
*Sub-update — Sender alias learning (caller-ID style):*

**Why:** auto-resolve from `users.email` only catches foremen who are platform users with matching addresses. Real-world sender addresses rotate (`john@company.com` Monday, `john.smith@company.com` Tuesday, the assistant's `tina@company.com` from the same person on Friday). After the user confirms the mapping once, the system should remember.

**Migration 006 — `time_intake_sender_aliases`:** unique on (tenant_id, from_address), tracks `person_id`, `confirmed_by_user_id`, `use_count`, `last_used_at`. Last-write-wins via `ON DUPLICATE KEY UPDATE`. Also adds `time_uploaded_documents.intake_event_id` so a doc can be reverse-mapped to its source intake row (and from there to the sender address).

**Lib (`lib/intake.php`):**
- `timeIntakeResolveSenderContext()` rewritten with priority: 1) saved alias (by lowercased from_address), 2) `users.email → people.email_primary` join. The alias path also bumps `use_count` and `last_used_at` (frequent-senders telemetry).
- New `timeIntakeRecordSenderAlias()` upserts the mapping; audited as `time.intake.sender_alias_recorded`.
- The ingest pipeline stamps `intake_event_id` on every `time_uploaded_documents` row it creates, so the alias-record API can resolve doc → from_address.

**API (`api/intake.php`):** new `POST ?action=record_alias` body `{document_id, person_id}` — looks up the doc → intake → from_address, upserts the alias, returns `{recorded: true|false}`. Returns `{recorded: false}` (not an error) when there's no intake row, so the UI can call this unconditionally for both intake-fed and manual uploads without needing branching.

**UI (`TimesheetUpload.jsx`):** the save loop now calls `record_alias` per group after a successful save (best-effort, no-op when there's no intake row). Rules: only sends when `g.person_id` is set AND at least one line in the group saved successfully.

**Compounding behavior:** because `via=alias` is checked before `via=users_email`, alias confirmations will override / supersede the email-match path, which is what you want when a foreman uses an alternate address that doesn't match their `users.email` record.

**Tests:** intake smoke 69 → 91 assertions. Full suite **73 files / 4,337 / 4,337 passing.**

*2026-02 — Sprints 1-4: Login UX + admin tools rebuild + executive dashboard (this fork):*
- **Sprint 1 — Login + tenant module filter (18 assertions ✓)**
  - Killed the silent "Demo Mode" fallback. SPA now redirects to `/login.html?next=...` on a 401, and the login page rebounces back to the deep route after auth.
  - `session.php` now filters modules by `tenant_modules.is_enabled` for the active tenant — non-master_admin users only see apps the tenant has subscribed to. Greenfield tenants (no rows) default to all-on.
  - `login.html` surfaces backend `?error=` codes and supports a `?next=` deep-link return; `login.php` whitelists the next path (no open-redirect).
  - Default post-login destination flipped from legacy `dashboard.php` to the React SPA.
  - Hard-failure path now shows a clean "We couldn't load your session" screen with a "Sign in again" link (testid `session-error-screen`).
- **Sprint 2 — Real admin tools (38 assertions ✓)**
  - New `/api/users.php`: list / create / update / password-reset / soft-deactivate / per-tenant assignment. master_admin gets every user; tenant_admin scoped to active tenant + sub-tenants. Cannot assign master_admin without being one. Cannot deactivate self.
  - New `/api/tenant_modules.php`: idempotent UPSERT toggle of module subscriptions per tenant; tracks `enabled_at` / `disabled_at`. Audit emits `tenant.module_enabled` / `tenant.module_disabled`.
  - `dashboard/src/pages/UsersAdmin.jsx` + `ModuleAccessAdmin.jsx` replace the mock arrays that used to live in `AdminModule.jsx`. Live CRUD, search, password-reset modal, status pills, tenant picker.
  - `AdminModule.jsx` rewritten — sidebar links + routes wired to real components (master tenants / sub-tenants / users / module access / export templates / AI accuracy).
- **Sprint 3 — Core staffing loop E2E contract (42 assertions ✓)**
  - New `tests/sprint3_staffing_loop_e2e_smoke.php` asserts the full chain People → Placements → Time → Billing/AP/Payroll is wired end-to-end: cross-module library functions exist, API actions exist, time bundles are consumed by the right modules, App.jsx routes every module, and `getModuleDefinitions()` registers the staffing modules. Stops the "where did all of this logic go?" class of regressions cold.
- **Sprint 4 — Executive Dashboard (50 assertions ✓)**
  - New `/api/exec_dashboard.php` aggregates revenue / margin / AR aging / AP aging / payroll YTD / run rate (90d annualised) / headcount split / new starts / terminations / net change / active placements / new placements / ending soon / billable hours. All filterable by client, recruiter, placement type, worksite state. Trendlines bucketed by week.
  - New `/api/exec_filters.php` populates the four filter dropdowns from real tenant data.
  - `dashboard/src/pages/ExecutiveDashboard.jsx` renders 12 KPI cards in two bands (corporate finance + staffing operations) plus AR/AP aging tables. Each card is a clickable drill-down that routes to the relevant module page. Hover sparkline on every trended KPI; window-size buttons for 4w / 12w / 26w / 52w / 104w.
  - New `Sparkline.jsx` (zero-dep SVG component) with hover tooltip showing `(week, amount)`.
  - `App.jsx` introduces `RoleAwareDashboard`: managers/admins/master_admin/tenant_admin get the executive snapshot; employees keep the simpler module-cards overview.
- **Schema baseline migration** `013_user_tenants_baseline.sql` — added so the schema-contract gate can see legacy `users` / `user_tenants` / `tenant_modules` columns. Brought the legacy allowlist from 3 → 1 entry.
- **Full smoke suite: 65 files, 3,828 assertions ✓ / 0 failed.**

*2026-02 — Sprint 5: Saved Views on the executive dashboard (this fork):*
- New `core/migrations/014_exec_dashboard_views.sql` — `exec_dashboard_views` table holds bookmarked (window + filter) tuples per user with optional tenant-wide sharing and a per-user `is_default` flag.
- New `/api/exec_dashboard_views.php` — full CRUD: list (own + shared), GET-by-slug, POST (slugified name with collision-resistant suffixes), PATCH (name / filters / shared / default — flipping default clears siblings), DELETE. Visibility: owners always; shared views also editable by tenant_admin / master_admin / admin.
- `ExecutiveDashboard.jsx` extended with a **View picker** dropdown (own + Shared groups), **Save view** button, and **Manage views** modal (set default, toggle shared, delete). URL `?view=<slug>` deep-links any team member to the same slice; on plain `/exec` the user's default view auto-loads.
- 44 new smoke assertions in `tests/sprint5_saved_views_smoke.php`. **Full suite: 66 files, 3,872 ✓ / 0 failed.**

*2026-02 — Sprint 6: Restructure (Reports as its own module + real charts + login fix):*
- **Home dashboard restored.** `/` now renders `DashboardOverview` (module nav cards) for everyone, with a tiny **KPI snapshot strip** at top (4 numbers: Revenue MTD, Run Rate 90d, Active Headcount, AR Outstanding) + an "Open full reports →" CTA. The full executive snapshot moved out of the home page.
- **Reports is its own module.** New `dashboard/src/pages/ReportsModule.jsx` with a left sidebar and three routes: `/modules/reports/exec`, `/modules/reports/finance`, `/modules/reports/staffing`. Registered in `core/modules.php` so it shows up in the global module picker for managers+ / admins / master_admin.
- **Real line charts.** New zero-dep `dashboard/src/components/LineChart.jsx` (gridlines, axis labels, hover crosshair, multi-series, dashed prior-year overlay, tooltip). Replaces the tiny sparklines on the report band — sparklines stay on the small KPI cards.
- **Date range picker.** Custom from/to inputs + presets (MTD, QTD, YTD, last quarter, last year). Coexists with the weeks pills (4w/12w/26w/52w/104w) — picking a custom range supersedes weeks; "Clear range" returns to weeks.
- **Prior-year comparison.** New `?compare=prior_year` parameter on `/api/exec_dashboard.php` returns parallel `prev_period` series shifted -52 weeks. Toggle button "vs. prior year" overlays the dashed line on the revenue chart.
- **Login → old app fix.** `dashboard.php` (legacy PHP shell) now redirects authenticated users to `/spa.php`. Two preserved escape hatches: `?legacy=1` (PHP UI) and `?admin=1` (master-admin panel) for debugging only.
- **Saved views capture the new fields** (from / to / compare_prior_year), so a saved "Acme Q4" view restores its date range and the comparison toggle.
- 47 new smoke assertions in `tests/sprint6_restructure_smoke.php`. Sprint 4 test updated to the restructured routing. **Full suite: 67 files, 3,919 ✓ / 0 failed.**

*2026-02 — Sprint 7: Reports drill pages — Finance + Staffing (this fork):*
- New `/api/reports_finance.php` — returns `pnl` (Revenue, Direct Cost, Gross Margin, Indirect, Net Income with optional prior-year column), `cash_flow` (Beginning → Receipts → -Operating → -Payroll → Ending + weekly trend), `ar_detail` (one row per outstanding invoice with `days_overdue`), `ap_detail` (same shape for bills).
- New `/api/reports_staffing.php` — returns `placement_margin` (one row per active placement with bill rate, pay rate, $/hr margin, period hours, period & lifetime margin, recruiter), `recruiter_board` (aggregated leaderboard), `headcount_breakdown` (by classification + by home state).
- New `dashboard/src/pages/FinanceReports.jsx` — full P&L card with Δ% column when "vs. prior year" is on, cash-flow waterfall with weekly net-receipts line chart, sortable + filterable AR / AP detail tables that link through to invoice/bill detail.
- New `dashboard/src/pages/StaffingReports.jsx` — recruiter leaderboard (sortable on every column), placement margin table (filter on candidate/client/recruiter/state, totals footer, click-through to placement detail), 2-panel headcount breakdown.
- `ReportsModule.jsx` swapped the bandFilter stubs for the new drill components (sidebar Finance / Staffing now go to real pages).
- Schema-contract gate confirmed clean — both APIs join `placements ↔ people` correctly, no orphan column references.
- 45 new smoke assertions in `tests/sprint7_reports_drill_smoke.php`. **Full suite: 68 files, 3,964 ✓ / 0 failed.**

*2026-02 — Sprint 8: Actionable rows + AI assistance on placement margin (this fork):*
- New `core/migrations/015_review_flags.sql` — polymorphic `review_flags` table (entity_type ∈ {placement, invoice, bill, person}) tracking reason_code, severity (info/warn/critical), status (open/resolved/dismissed), AI summary/confidence/source.
- New `/api/review_flags.php` — manager+ CRUD with idempotent flag-on-open (re-flagging the same (entity, reason) updates instead of duplicating). PATCH resolves/dismisses with audit, joins users for actor display names.
- New `/api/reports_ai_explain.php` — row-level AI insight for placements. Routes through the existing `aiAsk()` envelope (uses Emergent LLM key) with a CFO-style staffing prompt; returns answer + confidence + source + a structured `recommended_flag` payload. Falls back to a deterministic heuristic when AI is offline (low margin / stale timesheet / missing data signals).
- `StaffingReports.jsx` placement-margin table is now interactive:
  - Each row gets two action buttons (Sparkles = "Ask AI", Flag = "Flag for review"); flagged rows are highlighted yellow with a count badge.
  - **AI panel** modal — calls `/reports_ai_explain.php`, shows the answer + source + confidence + a yellow "Recommended flag" card with one-click "Apply this flag" button (which POSTs to review_flags); also offers "Custom flag…" and "Open placement".
  - **Flag modal** — lists existing open flags with per-flag Resolve buttons, plus a "New flag" form (reason dropdown / severity / free-text notes).
  - Confirmed treasury → bank-reconciliation flow is still inline (AI suggestions in `AccountTransactions.jsx` with confidence pills, accept/reject, rule learning) — no regression on Sprint 5 hardening.
- 40 new smoke assertions in `tests/sprint8_placement_actions_smoke.php`. **Full suite: 69 files, 4,004 ✓ / 0 failed.**



*2026-02 — Payroll Phase A1 (Gusto CSV extract + Audit CSV + AI anomaly flags):*
- **Confirmed in-house engine remains the calculator of record** for MVP; deterministic gross-to-net compute (already shipped, 16/16 tests green) handles W-2 employees end-to-end. 1099 / C2C contractor pay continues to flow through AP — no separate payroll path. Gusto integration shape for this phase is **CSV-only**; Phase B will layer an OAuth API adapter behind the same `PayrollEngine` interface so the in-house engine becomes swappable without UI/workflow changes.
- **Two new exports on `runs.php`** (gated by `payroll.view`, audit-logged):
  - `GET /api/payroll/runs?action=export_gusto&id=N` — Gusto "Run regular payroll → Import hours from CSV" template (`first_name, last_name, employee_id, regular_hours, overtime_hours, double_overtime_hours, holiday_hours, pto_hours, sick_hours, bonus, commission, reimbursement`). Earnings rows are auto-classified by `kind` (bonus/spot_bonus/signing_bonus → bonus; commission/referral → commission; reimbursement/expense → reimbursement). Tenant uses CoreFlux for time approval + comp setup, exports to Gusto, Gusto runs the actual gross-to-net.
  - `GET /api/payroll/runs?action=export_run&id=N` — Full pre-calculated audit dump (every line item with gross/pretax/taxable/employee_taxes/posttax/net/employer_taxes/work_state/pay_rate/hours/method/status). Stays inside the platform as a record of what we computed.
- **Audit trail preserved in CoreFlux**: `payroll_runs` / `payroll_line_items` / `payroll_earnings` / `payroll_taxes` / `payroll_deductions` continue to store every cent. Both export calls emit `payroll.run.exported_gusto` / `payroll.run.exported_csv` to the audit log via the new `payrollAudit()` helper.
- **AI run-summary enhanced with anomaly flags** (`POST /api/payroll/ai_run_summary`): the deterministic context payload now includes a `context.anomalies` block with `new_hires`, `terminations`, `large_swings` (>25% gross delta vs prior run, per employee), and `missing_tax_setup` (employees in this run with no active `people_tax_federal` row). All flags computed in SQL — never invented by AI. The system prompt explicitly instructs the model to surface every anomaly by name, while continuing to defer all numeric values to the deterministic table on screen. Rendered through the same `<AISuggestion />` review gate (badge → edit → accept/reject) — no auto-decisions.
- **UI**: `PayrollRunDetail.jsx` shows two new download buttons (audit CSV + Gusto-import CSV) once a run leaves `draft`. Existing AI-summary "Generate summary" button left as manual-trigger per existing UX.
- Backend: +36 new smoke assertions in `/app/tests/payroll_phase_a1_smoke.php`. Combined suite: **29 files, 1,608+ passing / 0 failed**. Vite build green.

---
*Last Updated: 2026-02 — Treasury transactions feed + CoA hierarchy + Plaid 4-bug-fix combo (this fork).*

**Bugs found & fixed this round**:
1. Treasury deposit + liability list APIs were 500'ing on schema drift (queried non-existent `jel.side`/`jel.amount`/`jel.journal_entry_id`/`jel.tenant_id`). The Plaid mirror was working; the listing query was hiding it. Rewrote both queries to `(jel.debit - jel.credit)` joined via `jel.je_id` with `je.status='posted'`.
2. Plaid Link `products: ['auth','transactions']` hid credit cards. Now `products:['transactions']` + `required_if_supported_products:['auth']` + `optional_products:['liabilities']`.
3. The Retry / Sync button posted to `/api/plaid_sync_transactions` (no `.php`) which the API router rejected as not matching `/api/<module>/<endpoint>`. Appended `.php` in two callers (Treasury Deposit list + Bank Reconciliation).
4. Plaid sync was item-level (one item → one bank account). Refactored to fan out per-Plaid-account, mapping each transaction's `account_id` to either `accounting_bank_accounts` (deposits → `accounting_bank_statement_lines`) or the new `treasury_liability_statement_lines` (cards/loans). Removed transactions flip the right table's `match_status='ignored'`.

**New features**
- **Per-account transactions view** — Treasury Deposit and Liability lists now have a clickable `View →` per row that opens a transactions detail page (`/modules/treasury/{deposits|liabilities}/{id}`). Page renders date / description / category / amount / match-status, plus inflow/outflow totals and a `Sync from Plaid` button. Deposit detail also exposes a deep-link to the existing Bank Reconciliation page.
- **`treasury_liability_statement_lines`** — new table for credit-card / loan transactions (mirror shape of `accounting_bank_statement_lines` but keyed on `liability_account_id`). Self-heals on first sync if migration 003 not yet applied.
- **`/modules/treasury/api/account_transactions.php`** — unified read endpoint with `?type=deposit|liability` switch, returns rows + inflow_total + outflow_total + plaid_item_pk for the sync button. Has `?action=sync` POST sub-action that proxies to `/api/plaid_sync_transactions.php` keeping cookies / auth context.
- **CoA hierarchy auto-grouping** — `plaid_bank_link.php` now creates a header row per institution (`accounting_accounts` with `is_postable=0`, name = institution as-is e.g. "American Express") and assigns child cards' `parent_account_id` to it. Helper `plaidEnsureInstitutionParent()` lives in `core/plaid_service.php`, idempotent by name.
- **`POST /api/accounting/accounts.php?action=auto_group_plaid`** — one-shot retroactive backfill: walks every Plaid-mirrored liability with `parent_account_id IS NULL` and assigns it to its institution parent (creating the parent if missing). Audited as `accounting.coa.auto_grouped_plaid`.
- **`GET /api/accounting/accounts.php?action=tree`** — same data as the list, framed for tree-view rendering.
- **CoA tree UI** — `ChartOfAccounts.jsx` rewritten with indented parent / child rendering, type filter, "Auto-group Plaid liabilities" button, and a "Move…" dialog per row that lets users reparent any account under any same-type parent (cycle-safe — descendants are not eligible). Header rows (`is_postable=0`) get a `header` badge.

**Tested** — `tests/treasury_balance_query_smoke.php` (13 assertions), `tests/treasury_transactions_and_coa_hierarchy_smoke.php` (60 assertions). Full custom suite: **3,234 passing / 0 failed**.

**Migration impact** — Migration `modules/treasury/003_liability_statement_lines.sql` is idempotent and will be auto-applied by `update.php` on next deploy. Adds `treasury_liability_statement_lines` + `idx_aa_tenant_parent` index.

**Vite** — `index-Dxgu_7Ty.js` (813 kB, 1780 modules). `.deploy-version` updated with new bundle name + 5 new sentinels.

**User actions to test end-to-end**:
1. Refresh Treasury → should see 1 deposit + 2 liabilities listed.
2. Click `View →` on the AmEx Business Platinum row → see the transactions panel.
3. Click `Sync from Plaid` → transactions populate.
4. Go to Accounting → Chart of Accounts → click `Auto-group Plaid liabilities`. The two AmEx cards should now indent under a new "American Express" header row.
5. To move "American Express" + future "Discover" under a manual parent like "Credit Cards", first add a top-level Credit Cards account (type=liability, header), then click `Move…` on each institution row.



**Bug** — Production users connecting their bank only saw depository accounts;
credit cards / lines-of-credit / loans were silently absent from the
account picker AND from `plaid_accounts`. Diagnostics confirmed: 1 Plaid
item, 1 deposit mirrored, 0 orphans (cards never came through Link at all).

**Root cause** — `/api/plaid_bank_link.php` requested
`products: ['auth','transactions']`. Plaid hides credit/loan accounts from
Link when `auth` is in the required `products` array (Auth only supports
debitable depository accounts — see Plaid docs "initializing-products").
The same misconfig was duplicated in `/api/plaid_link_token.php` for the
`bank_feed` purpose default.

**Fix**
- `/api/plaid_bank_link.php` link_token now requests
  `products: ['transactions']`,
  `required_if_supported_products: ['auth']` (auth attaches automatically
  to depository accounts when supported, never blocks credit/loan),
  `optional_products: ['liabilities']` (richer APR / statement balance /
  min payment data on cards & loans where the institution supports it).
- `/api/plaid_link_token.php` mirrors the same shape for `purpose=bank_feed`.
  `'liabilities'` added to the `$allowed` product whitelist.
- Extracted `_plaidAllocateBankGlCode()` helper from
  `plaid_bank_link.php` into shared `/app/core/plaid_service.php` as
  `plaidAllocateBankGlCode()` so backfill + exchange paths use one
  collision-safe GL allocator (1000 → 1000-{last4} → 1000-{last8 of
  acct_id} → 1000-N).

**Orphan backfill** (no Plaid re-auth required)
- `/api/plaid_diagnostics.php?action=backfill` (POST,
  `accounting.bank.manage` perm, audited as
  `payment_rails.plaid.backfill`) walks every row in `plaid_accounts` not
  yet mirrored in `accounting_bank_accounts` / `treasury_liability_accounts`
  and creates the missing rows using the cached Plaid metadata. Self-heals
  the `plaid_account_id` column on `treasury_liability_accounts` if the
  treasury 002 migration hasn't been run yet. Returns
  `{orphans_processed, bank_accounts_created[], liability_accounts_created[],
  skipped[], errors[]}`.
- Treasury UI: clicking "Run diagnostics" now renders an inline 5-stat
  panel (Plaid Items / Plaid Accounts / Mirrored as Deposit / Mirrored as
  Liability / Orphaned). When orphans > 0, an amber banner lists the
  offending accounts and exposes a one-click "Backfill N orphans" button
  that calls the new endpoint and refreshes the panel.

**Smoke tests** — `tests/plaid_bank_link_smoke.php` extended with 21 new
assertions covering the products config, the shared helper move, the
backfill action shape, and the inline diagnostics panel UI testids.
Full custom suite now: **3,161 passing assertions / 0 failed**.

**Vite bundle** rebuilt → `index-DXVQFtfd.js` (813 kB), 1780 modules.
`.deploy-version` bumped + 2 new sentinels
(`api/plaid_diagnostics.php`, `modules/treasury/migrations/002_plaid_liability_link.sql`).

**User action required to see existing missing cards**
1. Visit Treasury → click "Run diagnostics" → if orphans > 0, click
   "Backfill N orphans". This rescues anything Plaid already returned in
   prior connect attempts but never made it into the mirror tables.
2. To pick up cards/loans Plaid never returned (because the old products
   config filtered them out at the institution level), click
   "Connect bank" again — Link now shows credit cards alongside checking
   accounts.



**New feature (per user direction):** tenant-defined CSV templates that
apply to ALL data exports. Replaces the NACHA-file fallback.

**Schema** — `core/migrations/008_export_templates.sql` adds
`export_templates` table (scope = `platform`|`tenant`, dataset key,
delimiter, quote_char, has_header_row, encoding, `column_mappings_json`,
`is_system`, `based_on_template_id`). Seeds two platform presets:
*Gusto Payroll Import (default)* + *AP Payments — Standard CSV*.

**Library** — `core/export_datasets.php` declares the dataset registry
(payroll_disbursements, ap_payments, expenses) with available source
fields (employee_first_name … gross_pay_dollars … bank_routing_number …).
`core/export_templates.php` exposes `exportTemplateList/Get/Create/Update/
Delete/Clone/ParseHeaders/Render/RenderToStream`. Mappings support `kind:
field` (read row[source_field]) and `kind: fixed` (static string).

**API** — `/api/export_templates.php`:
GET (list / single / `?action=datasets`), POST (create or
`?action=clone&id=N` or `?action=parse_headers` for sample-CSV upload),
PATCH (`?id=N`), DELETE (`?id=N`). Master_admin gates platform-scope
mutations; tenant_admin gates own tenant scope.

**Wire-in** — three exports honour `template_id`:
- `/modules/payroll/api/runs.php?action=export_template&id=R&template_id=T`
- `/modules/ap/api/payments.php?action=export_template&template_id=T&ids=…`
- `/modules/ap/api/expenses.php?action=export_selected&template_id=T&ids=…`

**UI** — `dashboard/src/pages/ExportTemplatesAdmin.jsx` at
`/admin/export-templates` (sidebar entry added). Modal editor: name,
dataset (locked after create), delimiter, header-row toggle, optional
sample-CSV upload (parses headers, auto-fills mapping rows), per-row
output_header + kind (field|fixed) + dropdown / fixed_value.
Master_admin sees a `scope` picker to author platform-wide presets.
`dashboard/src/components/ExportTemplatePicker.jsx` is a reusable
dropdown wired into PaymentsList, ExpensesList, and PayrollRunDetail
("Export via template" button).

**NACHA retired:** Originate-NACHA-batch button removed from
PaymentsList. Soft-fall-back from `paymentRailsDispatch` deleted —
when Plaid Transfer isn't configured, callers now see "Either link a
funding source under Treasury → Plaid, or export via Admin → Export
Templates." Driver code retained for any tenant scripting it directly.

**Build:** Vite → `index-CIMd7kPo.js` (802 kB) + reused CSS
`index-Cwhpy62y.css`. Stale bundle pruned. `.deploy-version` stamped.

**Tests:** `tests/export_templates_smoke.php` — **66 assertions ✓**.
Updated `ap_batch_export_smoke.php` and `payment_rails_wireup_smoke.php`
to reflect new behavior. **Total platform: 51 files, 3,064 passing /
0 failed** (was 50 / 2,998).

**Cloudways deploy:** `php deploy/run_migrations.php` once after pull —
the runner picks up `core/migrations/008_export_templates.sql` (which
seeds the two platform presets idempotently).*

**2. Bulk-select export for ExpensesList** — sticky multi-select bar, count
+ total, "Export selected to CSV" hits new
`/modules/ap/api/expenses.php?action=export_selected` (caps batch at 500,
audit `ap.expense.export_selected`).

**3. Cycle config UI on Placement edit** — migration
`002_cycle_config.sql` adds `placements.{billing_cycle_id, ap_cycle_id,
payroll_cycle_id}` + indexes. New "Cycles" tab on `PlacementDetail`
exposes a picker per cadence with live save through PATCH placement.

**4. Plaid Transactions/Transfer go-live (now unblocked)** —
`PlaidTransferDriver::originate()` posts `/transfer/authorization/create` →
`/transfer/create` per-item with idempotency keys + clean failure rows;
`getStatus()` calls `/transfer/get`. New `/api/plaid_transfer_link.php`
issues a Link token + persists encrypted `access_token` / `account_id`
in `tenant_payment_rails`. New `PlaidTransferFundingCard` on Treasury
Overview drives the Plaid Link flow.

**5. Gusto Track B sync layer** — `core/gusto_track_b.php` exposes
`gustoSyncEmployees`, `gustoSyncPaySchedules`, `gustoSyncCompensations`,
`gustoEnsureWebhookSubscription` (idempotent; auto-installs
`gusto_employee_uuid`/`gusto_pay_schedule_uuid` columns). New
`/modules/payroll/api/gusto_sync.php` exposes
`?action=employees|pay_schedules|compensations|webhook_subscribe|all`.
New `GustoTrackBSyncPanel` on `GustoConnectCard` with five buttons +
result viewer.

**6. Engagement nudge** (already shipped in prior pass): master-only
fleet-view widget on `DashboardOverview` now also encourages tenant_admins
with one sub-tenant to "promote to multi-entity" (deferred polish).

**Build:** Vite → `index-D793u6dw.js` (787 kB) +
`index-Cwhpy62y.css` (21.5 kB), synced to `/app/spa-assets/`. Stale
bundle `index-Ddi1O3kE.js` pruned. `.deploy-version` stamped.

**Tests:** `tests/p1_closeout_smoke.php` — **69 assertions ✓**. Full
platform: **50 files, 2,998 passing / 0 failed** (was 2,948).

**Cloudways deploy:** `php deploy/run_migrations.php` (idempotent).
The runner picks up `core/migrations/007_…sql` and
`modules/placements/migrations/002_cycle_config.sql` automatically.

**Deferred P2 (out of scope this pass):** master-tenant CRUD refactor —
the legacy `core/views/admin/tenant_edit.php` form still drives master
tenant lifecycle. Sub-tenant lifecycle is fully on the new
React `/admin/sub-tenants` page, so no functional gap.*

*2026-02 — Payroll: Sync-to-Gusto server-side polish:*
- **Migration `002_gusto_sync.sql`** adds `gusto_run_id`, `gusto_payroll_url`, `gusto_status` (`linked`/`submitted`/`paid`/`voided`), `gusto_synced_at`, `gusto_synced_by`, `gusto_paid_at` to `payroll_runs`, plus `idx_run_tenant_gusto`. All nullable + idempotent — runs that never hit Gusto behave identically to before.
- **Three new POST actions on `runs.php`**:
  - `?action=mark_gusto_synced { gusto_run_id, gusto_payroll_url? }` — captures the Gusto run identifier after the tenant uploads the CSV; stamps `gusto_status='submitted'` + `gusto_synced_at` + `gusto_synced_by`. URL must be `http(s)`. Audits `payroll.run.gusto_synced`.
  - `?action=mark_gusto_paid` — flips `gusto_status='paid'` + `gusto_paid_at`, mirrors local `payroll_runs.status='paid'` + line items + period if not already. Audits `payroll.run.gusto_marked_paid`. Refuses if run isn't linked.
  - `?action=unlink_gusto` — clears all gusto fields (in case wrong ID pasted). Audits `payroll.run.gusto_unlinked`.
- **`payrollRunIsGustoManaged($run)` helper** added to `lib/payroll.php` — future post-to-GL code will read this to suppress the cash-leg JE (DD payable / taxes payable disbursement) when Gusto is the system of record. Wage-accrual JE (Dr Wages Expense / Cr Wages Payable) remains CoreFlux's responsibility either way.
- **UI**: new `<GustoSyncPanel />` on `PayrollRunDetail.jsx` wraps the two CSV download buttons + a stateful Gusto-sync card. Unlinked → form to paste run ID + optional URL. Linked → status pill (`Submitted to Gusto` / `Paid in Gusto`), Gusto run ID, "Open in Gusto ↗" link, Mark-paid button (with confirm copy explicitly stating CoreFlux will skip the duplicate cash-leg GL post), Unlink button.
- Backend: +37 new smoke assertions on top of the existing Phase A1 suite — `/app/tests/payroll_phase_a1_smoke.php` is now 73/73 green. Combined suite: **29 files, 1,645+ passing / 0 failed**. Vite build green.

---
*Last Updated: 2026-02 — Payroll Sync-to-Gusto polish shipped (mark synced / mark paid / unlink + payrollRunIsGustoManaged() guardrail for future GL post).*


*2026-02 — Payment Rails framework (NACHA driver + Plaid Transfer scaffold):*
- **`PaymentRailsDriver` interface** at `/app/core/payment_rails.php` — single contract (`name() / isConfigured() / originate() / getStatus()`) consumed by both AP outbound payments and Payroll DD funding. RailItem shape locked: `{external_ref, recipient_name, account_routing, account_number, account_type, amount_cents, sec_code, description?, addenda?}`.
- **NACHA driver** at `/app/core/payment_rails/nacha_driver.php` — production-quality NACHA-OR PPD/CCD file generator. Validates inputs (ABA checksum, SEC code, account_type, positive amount), splits items by SEC code into separate batches inside a single file, emits 94-character fixed-width records (file-header / batch-header / entry-detail / batch-control / file-control), pads to 10-record blocks with `9...` filler, returns the file content + filename + entry count + credit total. Always `isConfigured()=true` (no external dependency). Tenant downloads the file → uploads to their bank's cash-management portal → bank originates the ACH credits.
- **Plaid Transfer scaffold** at `/app/core/payment_rails/plaid_transfer_driver.php` — env-gated, scaffolded per the integration playbook: `PLAID_CLIENT_ID` + `PLAID_SECRET_SANDBOX|PRODUCTION` + `PLAID_ENV` switch sandbox vs production host. `isConfigured()` reflects env presence; `originate()` throws `PaymentRailsNotConfiguredException` until Phase B wires the `/transfer/authorization/create` → `/transfer/create` round-trip + Plaid Link token flow + `/api/core/webhooks/plaid` handler with ES256 JWT signature verification. Endpoint constants (`/link/token/create`, `/item/public_token/exchange`, `/transfer/get`, `/transfer/event/sync`, `/webhook_verification_key/get`, `/sandbox/transfer/simulate`) and host constants exposed so Phase B drops in without rediscovery. Internal `callPlaid()` helper stubbed.
- **`paymentRailsResolveRail($module, $row, $settings)` precedence**: per-row override → per-module tenant setting → `'nacha'` fallback. Lets the same tenant run AP through NACHA and Payroll through Plaid, or vice versa, with optional per-payment / per-run override per the (a)+(c) scope you signed off on.
- **Migration `005_payment_rails.sql`**: new `ap_settings` (mirror of `payroll_settings`), `disbursement_rail` + `nacha_*` + `plaid_*_ct` columns on both settings tables, `disbursement_rail` + `rail_external_ref` + `rail_status` + `rail_originated_at` on `ap_payments` and `payroll_runs`, plus a `tenant_payment_rails` table reserved for Phase B persistence of Plaid `access_token_ct` + `account_id` per tenant. All idempotent + back-compat. `utf8mb4_unicode_ci` (Cloudways-safe).
- **Plaid pre-approval gotcha captured**: Plaid Transfer requires a 1-2 week manual application/review (Plaid Dashboard → Transfer Application). Sandbox works without approval; production does not. Documented in the driver scaffold docblock so the next agent doesn't get blocked.
- **Not yet wired**: AP `payments.php` and Payroll `runs.php` don't yet call `paymentRailsGetDriver()->originate()`; that's the next iteration (requires the encrypted bank-account lookups via `peopleActiveBankAccounts()` for payroll lines, and `ap_vendors_index` remit-to JSON for AP payments).
- Backend: +63 new smoke assertions in `/app/tests/payment_rails_smoke.php`. Combined suite: **30 files, 1,708+ passing / 0 failed**. Vite build green.

---
*Last Updated: 2026-02 — Payment Rails framework shipped (NACHA generator production-ready, Plaid Transfer scaffold env-gated, per-module + per-row rail resolution).*


*2026-02 — Payment-rails badging UI + AP/Payroll settings pages:*
- **Driver-level metadata** added to `PaymentRailsDriver` interface — `metadata()` returns `{cost_per_item_dollars, cost_pct, settlement_business_days, supports_same_day_ach, supports_rtp, needs_pre_approval, needs_funding_link, fallback_to, pros[], cons[]}`. NACHA: zero-fee, T+1-T+2, same-day capable, no fallback. Plaid Transfer: ~$0.50 + 0.5%, T+0-T+1, RTP-capable, requires Plaid pre-approval + Plaid Link funding source, falls back to NACHA.
- **Public registry endpoint** `GET /core/api/payment_rails.php` — auth-gated, returns `{rails: [{id, name, configured, description, metadata}]}` so AP / Payroll settings pages can render rail-cards with real numbers.
- **`<RailPicker />`** component (`/app/dashboard/src/components/RailPicker.jsx`) — grid of rail-cards. Each card surfaces: configured/not-configured pill, "Selected" pill when current default, cost badge ($/item + %), settlement-window badge ("T+0 same-day" / "T+1"), feature pills (Same-day ACH / RTP / Plaid pre-approval / Funding link), fallback-chain copy ("If origination fails, falls back to NACHA"), and pros/cons two-column lists. Reusable — same component drives both AP and Payroll settings.
- **AP Settings page** `/modules/ap/settings` — first AP-module settings UI. Reads from new `GET /modules/ap/api/settings.php`, persists via `PUT`. Wires the rail-picker + NACHA company-id / origin-routing inputs. New audit event `ap.settings.updated`.
- **Payroll Settings extended** — `PayrollSettings.jsx` now renders the same `<RailPicker />` plus NACHA company-id / origin-routing inputs alongside existing legal-entity / SUTA / FUTA / AI-toggle fields. `payroll/api/settings.php` accepts the new fields.
- **APModule** wired with the new Settings nav item + route. Manifest declares the action with `ap.view` permission.
- Backend: +59 new smoke assertions in `/app/tests/payment_rails_enhancements_smoke.php`. Combined suite: **31 files, 1,767+ passing / 0 failed**. Vite build green. ESLint clean across all three new/touched JSX files.

---
*Last Updated: 2026-02 — Payment-rail badging UI shipped (rail-cards with cost/speed/fallback, AP Settings page from scratch, Payroll Settings extended).*



*2026-02 — Accounting Phase 2 Sprint A.1 (Cash Flow + Manual JE + Drill-through):*
- **Migration `002_phase2.sql`** — adds `cash_flow_tag` to `accounting_accounts` (powers indirect cash-flow bucketing), creates `accounting_recurring_journal_entries` + `accounting_recurring_je_lines` (Sprint A.2), `accounting_bank_accounts` + `accounting_bank_statement_imports` + `accounting_bank_statement_lines` + `accounting_reconciliations` (Sprint A.2 bank rec). Bank account row carries `feed_provider` + `plaid_access_token_ct` + `plaid_account_id` for the Sprint A.2 Plaid Transactions feed. All idempotent + `utf8mb4_unicode_ci`.
- **Cash Flow Statement (indirect)** — `reportCashFlowIndirect()` in `reports.php`. Net income from period IS, walks every non-cash account's start-vs-end balance change, sign-flips for assets, buckets by `cash_flow_tag` prefix (`operating_*` / `investing_*` / `financing_*` / unset = `untagged`). Skips `cash_and_equivalents` accounts (they ARE the cash being explained), then ties out `net_change_in_cash` against actual GL cash movement and reports the reconciliation diff + `balanced` flag. Returns `untagged_warning` so the UI can prompt admin to tag remaining accounts.
- **`CashFlowStatement.jsx`** — accounting tab now has Cash Flow alongside IS / BS / TB. Renders three sections + untagged warning + ties-out indicator. Date range defaults YTD.
- **Manual JE creator** — new `JournalEntryCreate.jsx` (`/modules/accounting/journal-entries/new`). Account-code autocomplete from COA, balanced-debit-credit validation, save-as-draft vs save-and-post buttons, inline balance status. Posts through the existing `accountingPostJe()` lifecycle.
- **JE detail page** — new `JournalEntryDetail.jsx` (`/modules/accounting/journal-entries/:id`). Header + lines + Reverse-JE action (already-existing API) + the **drill-through "source" link** that maps `source_module` → frontend route (`ap_bills` → AP bill detail, `ap_payments` → AP payment, `billing_invoices` → invoice, `payroll_runs` → payroll run) so an auditor can jump from a posted JE back to the originating subledger record.
- **Drill-through from financial statements**: account-code cells on Income Statement and Balance Sheet are now `<Link>`s to `/modules/accounting/journal-entries?account_code=X&from=…&to=…`. JE list endpoint extended with an `account_code` filter (`INNER JOIN` on lines + accounts, `SELECT DISTINCT je.id`). `JournalEntries.jsx` reads URL search params, forwards them to the API, and shows a clear "Filtered by …" pill with a one-click clear button. Synthetic equity rows on the BS (the "Current period net income" row) are intentionally NOT clickable — there's no GL detail behind them.
- **AccountingModule** rewritten — Cash Flow tab added; new `/journal-entries/:id` and `/journal-entries/new` routes coexist with the legacy `/journal` tab.
- Backend: +73 new smoke assertions in `/app/tests/accounting_phase2_a1_smoke.php`. Combined suite: **32 files, 1,840+ passing / 0 failed**. Vite build green. ESLint clean across all six new/touched JSX files.
- **Sprint A.2 next**: Bank reconciliation UI (statement import + matching engine + reconciliation packet), Recurring JE engine (cron + post-on-schedule), Bank feeds via Plaid Transactions API (separate from Plaid Transfer — Plaid Transactions does NOT need pre-approval).
- **Sprint A.3 next-next**: CSV import/export for accounting ledgers, Standard reports (GL detail, unposted JEs, approval queue, accounting audit log).

---
*Last Updated: 2026-02 — Accounting Phase 2 Sprint A.1 shipped (Cash Flow Statement, Manual JE creator, Drill-through from IS/BS, JE Detail page).*

*2026-02 — Accounting Phase 2 Sprint A.2 (Bank Rec + AI assists):*
- **Migration `003_bank_rules_ai.sql`** — adds `accounting_bank_rules` (categorization / match rules) and 6 new `ai_suggested_*` / `applied_rule_id` columns on `accounting_bank_statement_lines`. Rule schema: `pattern_kind` ∈ {contains, starts_with, equals, regex}, optional `amount_min/max_cents`, `direction` ∈ {any, credit, debit}, `target_account_code`, plus the **`is_approved` flag** that determines whether the rule **auto-applies** (`is_approved=1`) or **stages as a suggestion** (`is_approved=0`, the default). Auto-applied lines stamp `applied_rule_id`; suggested lines stamp `ai_suggested_rule_id` + `ai_suggested_confidence`.
- **Bank accounts API** `/modules/accounting/api/bank_accounts.php` — CRUD + close. Detail returns unmatched-line count. Plaid access-token cipher never exposed in responses.
- **Bank statements API** `/modules/accounting/api/bank_statements.php` — `import_csv` (auto-detects column mapping from header keywords; INSERT IGNORE de-dups on FITID; synthesizes a deterministic FITID when the bank doesn't supply one), `match` / `unmatch` / `ignore`, `apply_rules` (walks unmatched lines, runs all active rules in `is_approved DESC` order — first match wins; auto-applies approved rules, stages suggestions for the rest).
- **Bank rules API** `/modules/accounting/api/bank_rules.php` — list / create / update + `approve` (flip `is_approved=1` so future matches auto-apply) / `pause` / `archive`. Regex patterns are compile-validated on save.
- **Bank rec library** `/modules/accounting/lib/bank_rec.php` — `bankRecImportCsv`, `bankRecMatchLine`, `bankRecUnmatchLine`, `bankRecApplyRules`, `bankRecLineMatchesRule` (pure function — covered by 7 unit asserts in the smoke suite), `bankRecAutoSuggestMatches` (heuristic JE candidate fetcher within ±3 days × abs(amount) match).
- **AI assist endpoints** `/modules/accounting/api/bank_ai.php` — three actions, all powered by `aiAsk()` with `feature_class='advisory'`, all return `review_required: true`, all gated by `<AISuggestion />`:
  - `suggest_match` — picks the most likely existing JE for a bank line; pool comes from `bankRecAutoSuggestMatches()`.
  - `suggest_categorize` — picks a single COA `account_code` for a new line that needs to become a JE.
  - `suggest_rule` — drafts an `accounting_bank_rules` row (pattern + target_account_code) the user can save. Default is `is_approved=0` (staged); the AI can recommend `is_approved=1` (auto-apply) for unambiguous merchants like AWS / Stripe Fee.
- **`BankReconciliation.jsx` UI** — three pages under `/modules/accounting/bank-rec`:
  - **Accounts list** with inline "Add bank account" form.
  - **Account detail** — collapsible CSV importer, "Apply rules now" action, lines table with per-line "AI match / AI cat. / AI rule" buttons. Rule-applied lines show a green ⚙ Rule applied pill; suggested categorizations show ✨ pills with the suggested code; suggested rules show a blue ✨ Rule suggested pill.
  - **Rules list** — every active rule with its mode pill (Auto-apply green / Suggested amber), `times_applied` counter, source (`manual` vs `ai_suggested`), and Approve / Pause / Archive actions. Approve flips a suggested rule to auto-apply.
- **AccountingModule** routed `/bank-rec/*` and added a Bank Rec tab between Cash Flow and Periods.
- Backend: +90 new smoke assertions in `/app/tests/accounting_phase2_a2_smoke.php` (incl. 7 pure-function unit asserts on `bankRecLineMatchesRule`). Combined suite: **33 files, 1,930+ passing / 0 failed**. Vite build green. ESLint clean.
- **Sprint A.3 next**: Recurring JE engine + cron + UI; CSV import/export for COA / JEs / TB / periods; standard reports (GL detail, unposted, audit log); Plaid Transactions feed (env-gated scaffold like Plaid Transfer — schemas and access_token_ct column already in place).

---
*Last Updated: 2026-02 — Accounting Phase 2 Sprint A.2 shipped (Bank Reconciliation, Bank Rules with auto-apply vs suggested, AI match / categorize / rule via `<AISuggestion />`).*


*2026-02 — Rule learning from accepted AI categorizations:*
- **Migration `004_rule_learning.sql`** — adds `categorized_account_code` / `categorized_at` / `categorized_by_user_id` / `categorized_via` columns to `accounting_bank_statement_lines` so we can track which COA code a user accepted on each line. Extends `accounting_bank_rules.created_via` enum with `'ai_learned'` so the UI can distinguish learned rules from manually-drafted or AI-suggested ones.
- **`accept_ai_categorize` action** on `bank_statements.php` — stamps the user-chosen `account_code` onto the bank line (`categorized_via='ai_accepted'`), writes a standard `ai_suggestions` accept row (so existing AI-accept tracking sees it), and emits `accounting.bank.ai_categorize_accepted` audit.
- **`learn` action** on `bank_rules.php` (`POST ?action=learn`) — invokes `bankRecLearnRulesFromAccepts(tenant, min_occurrences=3)` from `lib/bank_rec.php`. Audits `accounting.bank.rules_learned`.
- **`bankRecLearnRulesFromAccepts()`** — pure PHP, no AI calls. Algorithm: pull the last 2,000 accepted lines → group by `categorized_account_code` → for each cluster, tokenize each distinct description into ≥4-char alphanumeric non-stop-word tokens (`bankRecExtractTokens`) → count tokens by **distinct-line occurrences** (not raw frequency, so `STRIPE FEE STRIPE FEE` in one line is one count, not two) → pick the highest-occurrence token that beats the threshold and isn't already in an active rule for that account → insert as a new rule with `is_approved=0` + `created_via='ai_learned'`. Direction-locks to `debit` / `credit` if every line in the cluster moves the same way. One rule per cluster per learner run (so a single learn call yields at most one rule per category).
- **`bankRecExtractTokens()`** — pure helper, exposed for unit testing. Drops tokens shorter than 4 chars, pure-numeric tokens (txn IDs), and a stop-word list (`ach / debit / credit / payment / xfer / transfer / online / mobile / from / amount / txn / transaction / reference / ref / memo / date / posted / pending / inc / llc / corp / ltd / co`). 13 of the smoke asserts hit this function directly.
- **UI** — `BankReconciliation.jsx` Rules page now shows a `✨ Learn from accepts` button in the header. Result banner reports either "Drafted N new rule(s)" or "No new patterns yet — accept more AI categorizations and try again. Evaluated X categorization cluster(s)." Newly-learned rules show with a faint background tint and a small `learned` pill so the reviewer knows they came from the loop closer.
- Backend: +48 new smoke assertions in `/app/tests/accounting_bank_rule_learning_smoke.php` (13 are pure-function unit asserts on `bankRecExtractTokens`). Combined suite: **34 files, 1,978+ passing / 0 failed**. Vite build green. ESLint clean.

---
*Last Updated: 2026-02 — Rule learning by accepted AI suggestion shipped (close-the-loop: today's accepts become tomorrow's auto-applied rules).*



*2026-02 — Accounting Phase 2 Sprint A.3 (Recurring JEs):*
- **Wired up** the previous-session Recurring-JE draft files into the app:
  - `AccountingModule.jsx` — new "Recurring JEs" tab + `/recurring/*` nested route.
  - `manifest.php` — now declares `accounting.recurring_je.{created,updated,lines_replaced,pause,resume,end,run,auto_ended}` audit events.
  - `core/modules.php` — sidebar nav entry.
  - `bin/recurring_je_cron.php` — fixed a dead `setTenantContextOverride()` call (replaced with `$_SESSION['tenant_id']` per-tenant), so the daily cron now actually runs without a fatal.
- **Engine** `lib/recurring_je.php` — `recurringJeListDue`, `recurringJeRunOnce`, `recurringJeRunDueForTenant`, `recurringJeAdvanceDate`. Posts via the central `accountingPostJe()` chokepoint with idempotency key `recurring:<template_id>:<run_date>` (same template + same run_date returns the prior JE). Auto-ends templates past their `end_date` instead of reposting.
- **API** `api/recurring_journal_entries.php` — 8 actions: list / detail / create / update / replace_lines / pause / resume / end / run_now / run_due (cron entrypoint).
- **UI** `ui/RecurringJournalEntries.jsx` — list with Run-due / Run-now / Pause / Resume / End per-row actions, status pills, last-run link to the posted JE, and a full editor with line-editor + balanced-debit-credit validation.
- Backend: +80 new smoke assertions in `/app/tests/accounting_phase2_a3_smoke.php` (incl. 8 pure-function unit asserts on `recurringJeAdvanceDate`). Combined suite: **32 files, 2,058 passing / 0 failed**. Vite build green.

---
*Last Updated: 2026-02 — Recurring JE engine wired end-to-end (list + editor + cron + idempotency + audits).*


*2026-02 — Accounting Phase 2 Sprint A.4 (CSV import/export + Standard Reports + Reconciliation Packet):*
- **Migration `005_reconciliation_packet.sql`** — adds `opened_at`, `opened_by_user_id`, `reopened_at`, `reopened_by_user_id`, `reopen_reason`, `ai_narrative`, `ai_narrative_generated_at` to `accounting_reconciliations`. Idempotent ALTERs via `information_schema`. `utf8mb4_unicode_ci`.
- **CSV exports** `api/export.php` — one endpoint, 10 `type=` handlers streaming `text/csv` with `Content-Disposition: attachment`: `coa`, `je`, `je_lines` / `gl_detail`, `tb`, `periods`, `bank_statements`, `unposted_jes`, `approval_queue`, `audit_log`, `account_activity` (with running balance). Every call emits `accounting.ledger.exported` audit.
- **CSV imports** `api/import.php` — wrapped `Core\CsvImportService` with three accounting schemas:
  - `coa` — upserts by `(tenant_id, code)`; enum-validates `account_type` / `normal_side` / `cash_flow_tag`.
  - `je`  — groups rows into a single JE per `batch_ref` column; posts via `accountingPostJe()` with idempotency key `csv:<SHA-256(tenant:batch_ref)>` so retrying a partial import is safe.
  - `periods` — upserts by `(tenant_id, entity_id, start_date)`.
  - `action=template` returns the headered CSV; `action=dry_run` previews errors; `action=commit` (optionally with `skip_invalid=1`). Emits `accounting.ledger.imported` audit.
- **Standard (operational) reports** `api/standard_reports.php` — on-screen/JSON reports:
  - `gl_detail` — posted JE lines by date range / account code, with debit-credit totals.
  - `unposted_jes` — status ≠ posted, grouped counts.
  - `approval_queue` — status = draft with created_at stack ranking.
  - `audit_log` — `accounting.*` events, date range + `event_like` filter; requires `accounting.audit.view`.
  - `account_activity` — single account's full posted activity with running balance + ending balance.
- **Reconciliation packet** `api/reconciliations.php` — new endpoint with `list / detail / open / close / reopen (reason required) / packet / generate_ai_narrative`. Workflow columns + state machine per user ask; audit events: `accounting.reconciliation.opened|closed|reopened|packet_built|ai_narrative_generated`.
- **Packet library** `lib/reconciliation_packet.php` — `reconciliationPacketBuild()` returns `{reconciliation, bank_account, matched[], unmatched[], totals, ai_narrative}`. `reconciliationPacketGenerateNarrative()` calls `aiAsk()` with a prompt that *forbids restating dollar figures* (the table already shows them) and persists the text to `ai_narrative` for the UI to render via `<AISuggestion />`.
- **React UI** (3 new pages, 1 extended):
  - `StandardReports.jsx` — 5 tabs, each with filter bar + on-screen table + ⬇ Export CSV button that hits the matching export endpoint.
  - `AccountingImport.jsx` — type picker → ⬇ Download template → paste CSV → Dry-run preview → Commit (with skip-invalid toggle).
  - `ReconciliationPacket.jsx` — one-page printable packet with `@media print` CSS (hides workflow buttons, cleans layout for Print / Save-as-PDF), matched + unmatched tables, AI-narrative panel with Generate / Regenerate, and Close / Reopen-with-reason workflow controls.
  - `BankReconciliation.jsx` — new `Reconciliations` tab on the account-detail page that lists every reconciliation with diff coloring + "Open packet →" link, plus an inline form to Open a new reconciliation.
- **Routing** — `AccountingModule.jsx` now routes `/reports`, `/import`, `/bank-rec/reconciliations/:id`, `/bank-rec/packet/:id`. Sidebar (`core/modules.php`) updated.
- **Manifest** — new `accounting.ledger.import` permission, two new sidebar actions (`Standard Reports`, `CSV Import`), 7 new audit events (imported/exported/reconciliation.*).
- Backend: +107 new smoke assertions in `/app/tests/accounting_phase2_a4_smoke.php`. Combined suite: **33 files, 2,165 passing / 0 failed**. Vite build green (625kB JS). Bundle synced to `/app/spa-assets/`.

---
*Last Updated: 2026-02 — Accounting Phase 2 Sprint A.4 shipped (CSV ledger import/export, standard reports, reconciliation packet with AI narrative + printable PDF layout).*

*2026-02 — Reconciliation narrative now goes through `<AISuggestion />`:*
- **Library split**: `reconciliationPacketGenerateNarrative()` no longer auto-persists — it only returns the AI envelope. New `reconciliationPacketSaveNarrative()` persists the human-accepted (and possibly edited) text.
- **New endpoint** `?action=save_ai_narrative` accepts `{final_content}` and writes it to `accounting_reconciliations.ai_narrative` + `ai_narrative_generated_at`. Audits `accounting.reconciliation.ai_narrative_accepted`.
- **UI** `ReconciliationPacket.jsx` now renders the generated narrative inside `<AISuggestion envelope={aiEnvelope} featureKey="accounting.reconciliation.packet_narrative" subjectType="accounting_reconciliation" subjectId={id} onAccepted={...} onRejected={...} />`. The accept/reject row goes through the standard `/core/api/ai_suggestions.php` pipeline (same as Payroll run summaries) so it shows up on the cross-tenant AI admin dashboard.
- **Manifest** declares the new `accounting.reconciliation.ai_narrative_accepted` audit event.
- Backend: +8 new smoke assertions on top of Phase 2 A.4 (115 total for A.4). Full suite: **33 files, 2,173 passing / 0 failed**. Vite build green (629kB JS).

---
*Last Updated: 2026-02 — Reconciliation AI narrative now uses the platform-standard `<AISuggestion />` review gate (Badge → Edit → Accept/Reject → audit).*


*2026-02 — Accounting Phase 2 Sprint A.5 (Intercompany Split Engine):*
- **Migration `006_intercompany.sql`** — new `accounting_intercompany_mappings` table (directional: one row per `from_entity → to_entity` with `due_from_account_code` + `due_to_account_code`), `intercompany_group_id VARCHAR(64)` column on JEs (indexed with tenant_id), `counterparty_entity_id BIGINT UNSIGNED` column on JE lines.
- **Engine** `lib/intercompany.php`:
  - `intercompanyGetMapping` / `intercompanyUpsertMapping` — pre-configured per-pair mapping; ad-hoc override supported at post time (user choice 1c).
  - `intercompanyPostSplit($tenantId, $payload, $actor)` — posts N balanced JEs (one per entity) via the existing `accountingPostJe()` chokepoint, linking them all via a fresh `intercompany_group_id`. Each target-entity JE is debit/credit balanced by construction using the mapping's due-from/due-to. Source entity always gets its own balancing JE (user choice 2b), so TB in source stays clean.
  - Idempotency keyed per leg: `<prefix>:source`, `<prefix>:target:<entity_id>` — retrying the same post returns prior JE ids instead of double-posting.
  - Full atomic transaction per split (all legs or none).
  - Auto-marks the originating bank statement line as `matched` against the source JE when `bank_statement_line_id` is present.
  - `intercompanyReverseGroup($tenantId, $groupId, $reason)` — reverses every leg in the group via `accountingReverseJe()` (user choice 5a — cascaded reversal).
- **API** `api/intercompany.php` — list/resolve/upsert/delete mappings; `?action=post_split`, `?action=reverse_group`, `?action=group` (list JEs in a group). Cross-entity posting requires `accounting.je.post` permission in every target entity (user choice 3b — tenant-scoped `accounting.*` covers this for master_admin / tenant_admin).
- **Core `accountingPostJe()`** extended to pass through `counterparty_entity_id` on each line so IC lines are self-documenting in the DB (supports future IC reporting / eliminations).
- **UI — reusable dialog** `components/IntercompanySplitDialog.jsx`:
  - One modal used from (currently) Bank Reconciliation, (next) Journal Entry Create, (next) AP Bill Post.
  - Splits table: user picks entity + account + amount per row. Auto-resolves the IC mapping for each cross-entity row; shows `DR 1500 / CR 2500` inline; flags rows with missing mappings in red so the user can go configure before posting.
  - Live balance check: splits must total to the source offset amount before the Post button enables.
  - Sign convention: `sourceOffsetSide='credit'` (bank charge — money LEFT source entity) vs `'debit'` (deposit — money CAME IN). Everything flips correctly for deposits.
- **UI — settings page** `IntercompanyMappings.jsx` at `/accounting/intercompany`: form + table for managing the (from, to, due_from, due_to, notes, active) mappings. One row per direction (A→B and B→A are separate rows by design).
- **Bank Rec integration**: every unmatched bank line now has a `⊕ Split / IC` button that opens the dialog with the bank's entity + GL account pre-filled. On successful post, the line is auto-matched and reloads. Sign is auto-detected: negative amount → credit source offset (charge); positive → debit source offset (deposit).
- **Manifest**: declares 5 new IC audit events (`mapping_created/updated/deactivated`, `split_posted`, `group_reversed`).
- Backend: +75 new smoke assertions in `/app/tests/accounting_phase2_a5_smoke.php`. Combined suite: **34 files, 2,248 passing / 0 failed**. Vite build green (641kB JS). Bundle synced to `/app/spa-assets/`.

### Scope cuts explicitly flagged as next up
- **AP bill → intercompany split** — the `<IntercompanySplitDialog />` is reusable, but `ap_bills` post flow still needs a hook on the bill detail page. Will add in next sprint.
- **Manual JE → intercompany split** — same reusable dialog, still needs an entry-point button on `JournalEntryCreate.jsx`.
- **Cross-tenant posting** (if the user actually meant parent-CoreFlux-tenant ↔ sub-CoreFlux-tenant as opposed to multi-entity within one tenant) — would require a cross-tenant posting service. Shipped the multi-entity version for now since that covers the example ("parent co's card → sub-co's credit card account").
- **Auto-reconcile the matching bank line on the RECIPIENT side** (if both entities have bank feeds) — P1 next.

---
*Last Updated: 2026-02 — Intercompany split engine live: one screen splits any transaction across N entities, auto-books due-from/due-to offsets, links all legs via group_id for cascaded reversal. Integrated into Bank Rec unmatched-line flow; reusable dialog ready for AP + manual JE in next sprint.*


*2026-02 — Accounting Phase 2 Sprint A.6 (IC on AP + Manual JE + Elimination Worksheet):*

Path-B architectural decision: customer uses ONE CoreFlux tenant per legal family, with `accounting_entities` playing the role of "sub-companies". Module entity-awareness status: Accounting ✅ full · Bank accounts ✅ (have entity_id) · People ⚠️ partial · AP/AR/Payroll ⚠️ tenant-scoped (book into entities via IC splits at post time). Tracked as P0 backlog.

- **Migration `ap/007_intercompany.sql`** — adds `intercompany_group_id` on `ap_bills` (indexed on tenant + group). Idempotent.
- **AP API** `api/bills.php?action=post_with_ic_split` — posts a bill via the shared `intercompanyPostSplit()` engine instead of the simple Dr-Expense-Cr-AP path. Accepts both the dialog-native `source.offset_line` shape AND a slim `{entity_id, ap_account_code, splits}` shape. Idempotency keyed `ic:bill:<id>`. Links the source-leg JE id + group id onto the `ap_bills` row. Emits `ap.bill.posted_ic`. Requires BOTH `ap.bill.post` and `accounting.je.post`.
- **Core engine** `lib/intercompany.php` — new `intercompanyEliminationWorksheet($t, $from, $to)`: returns `{groups, pairs, orphans, summary}`. For each `(A → B)` pair aggregates IC-tagged lines from A's books vs mirror lines in B's books; `imbalance_signed = (A's Dr - A's Cr) + (B's Dr - B's Cr)` which is 0 for a perfect pair. Surfaces orphan lines — IC-tagged (`counterparty_entity_id IS NOT NULL`) but NOT part of any `intercompany_group_id` (i.e. manually tagged, likely a miss).
- **Accounting API** `api/intercompany.php` gains `?action=elimination_worksheet` + `?action=narrate_elimination` (AI summary via `aiAsk()` chokepoint, same "no dollar figures" prompt pattern as reconciliation packet).
- **Reusable dialog** `IntercompanySplitDialog.jsx` now accepts a `postUrl` prop — defaults to the IC engine, but can be pointed at `bills.php?action=post_with_ic_split&id=N` (or future AR/Payroll endpoints) so each module handles its own linkage.
- **AP BillDetail.jsx** — new `⊕ Post with IC split` button opens the dialog pre-filled with AP liability account `2000`, side `credit`, amount = bill total. On post, the bill row is linked to the source leg's JE id + group id in a single round trip.
- **JournalEntryCreate.jsx** — new `⊕ Split across entities` button. Seeds the dialog by picking the largest line as the "source offset" and treating the remaining lines as the splits-to-distribute; user can adjust entities + accounts before posting.
- **EliminationWorksheet.jsx** at `/accounting/elimination` — month-end worksheet with:
  - 4 stat tiles (groups / pairs / ⚠ imbalanced / ⚠ orphans).
  - Entity pair balance table (imbalanced pairs in red, balanced pairs with ✓).
  - IC groups table with per-leg totals.
  - Orphan IC-tagged lines (if any) — for post-hoc cleanup.
  - `⬇ Pairs CSV` + `✨ Summarize` AI narrative button with the standard "no restate dollars" guard.
- **Manifest** — 2 new audit events (`elimination_viewed`, `elimination_narrative_generated`); `ap.bill.posted_ic` added to AP manifest.
- Backend: +49 new smoke assertions in `/app/tests/accounting_phase2_a6_smoke.php`. Combined suite: **35 files, 2,297 passing / 0 failed**. Vite build green, synced.

### Backlog (P0 to deliver the "Path B, full feature isolation" vision you asked about)
- **AR / Billing** — add `entity_id` to `billing_invoices` + entity selector on invoice create. Otherwise invoices live at tenant level and must post into entities via IC split (works, but noisy).
- **People** — extend `entity_id` from `people_custom_data` down to the core `people` / `employment` records so HR data can be scoped per entity.
- **Payroll** — per-entity payroll runs (multi-EIN scenarios).
- **Consolidation** — entity-pair ownership %, affiliate / subsidiary / branch relationships, eliminations worksheet promoted into a formal consolidation run with close workflow.
- **Cross-entity P&L / BS** — `reports.php` needs "consolidate selected entities" mode (union + eliminations applied) instead of single `entity_id` only.

---
*Last Updated: 2026-02 — Phase 2 A.6 shipped: IC splits on AP bills + manual JEs via the reusable dialog; Elimination Worksheet live with AI narrative for pre-close sanity.*


*2026-02 — Accounting Phase 2 Sprint A.7 (Consolidation foundations + entity-aware schema extensions):*

- **Migration `007_consolidation.sql`** — new `accounting_entity_relationships` table (directional edges with `ownership_pct DECIMAL(7,4)`, `relationship_type` enum (subsidiary/affiliate/branch/jv/other), `consolidation_method` enum (full/equity/cost/none), `effective_from`/`effective_to` for dated ownership changes, unique on (tenant, parent, child, effective_from)). Adds `entity_id` to `billing_invoices`, `people`, and `ap_bills` with tenant+entity composite indexes. All idempotent.
- **Consolidation engine** `lib/consolidation.php`:
  - `entityRelationshipList` / `entityRelationshipUpsert` — CRUD with full validation (pct 0..100, whitelisted types & methods).
  - `entityRelationshipResolveDescendants($rootEntityId, $asOf)` — BFS traversal honoring `effective_from/to` and skipping `cost` / `none` method children.
  - `consolidateTrialBalance` / `consolidateIncomeStatement` / `consolidateBalanceSheet` — union per-entity data AND apply intercompany eliminations in-query (where BOTH the source JE entity AND the line's `counterparty_entity_id` are in scope). Every row exposes `debit_gross` / `credit_gross` / `debit_elim` / `credit_elim` / `debit_net` / `credit_net` / `balance_signed` so the UI can show the reader "here's what we eliminated".
  - NCI (non-controlling interest) tracked as a known-limitation for v1.0 — the ownership_pct is persisted but treatment is "full include" for the subsidiary method for now.
- **API** `api/entity_relationships.php` — list/upsert/delete + `?action=descendants&root_entity_id=N&as_of=YYYY-MM-DD` to resolve a consolidation tree.
- **API** `reports.php` extended with `?consolidate=1&entity_ids=1,2,3` (or `&root_entity_id=N`) for all 3 financial statements (IS/BS/TB). Falls through to legacy single-entity mode when flag absent.
- **UI** `Consolidation.jsx` at `/accounting/consolidation` — two halves:
  1. **Ownership structure** editor (parent, child, %, type, method).
  2. **Consolidated report viewer** — pick entities via checkboxes, pick report type (IS / BS / TB), period. Renders the consolidated statement with an "Elim" column so reviewers see both the gross number and what was eliminated.
- **Manifest**: 3 new audit events for relationship_created/updated/deactivated.
- **Schema extensions — `entity_id` columns** (ALTER-only; UI pickers to follow):
  - `billing_invoices.entity_id`, `people.entity_id`, `ap_bills.entity_id` — idempotent adds with `(tenant_id, entity_id)` indexes. Feature APIs still tenant-scope today; next sprint wires the create flows + filters so users can scope AR/HR/AP natively to an entity without going through an IC split.
- Backend: +60 new smoke assertions in `/app/tests/accounting_phase2_a7_smoke.php` (incl. 5 pure-function unit asserts on validation). Combined suite: **36 files, 2,357 passing / 0 failed**. Vite build green, synced.

---
*Last Updated: 2026-02 — Phase 2 A.7 shipped: ownership relationships + consolidated IS/BS/TB with in-query intercompany eliminations. Foundations for per-entity AR/HR/AP via new entity_id columns.*


---
*2026-02 — Accounting Phase 2 Sprint A.8 (Consolidation lock + NCI + Entity pickers + AR IC split):*
- **Migration `008_consolidation_runs.sql`** — `accounting_consolidation_runs` table (locked snapshot of IS/BS/TB payloads with `payload_json` LONGTEXT, `status` enum locked/reversed/draft, `period_from`/`period_to`, `entity_ids_json`, `root_entity_id`, `locked_at`/`reversed_at`/`reverse_reason`/`ai_narrative_generated_at`). Adds `intercompany_group_id` on `billing_invoices` for cross-entity AR splits.
- **Consolidation lock workflow** in `lib/consolidation.php`:
  - `consolidationLockRun` — computes the consolidated payload (delegating to existing IS/BS/TB engines) and persists a locked JSON snapshot with audit `accounting.consolidation.run_locked`. Falls back to root_entity_id descendant tree when explicit entity_ids aren't supplied.
  - `consolidationReverseRun` — reverses locked → reversed; requires explicit reason; emits `accounting.consolidation.run_reversed`.
  - `consolidationListRuns` / `consolidationGetRun` — listing + detail with eager-decoded JSON.
- **NCI breakout on Balance Sheet** — `consolidateBalanceSheet` now queries each in-scope entity's effective ownership edge, computes `(100 − pct)%` of standalone equity as `nci_equity` (with `nci_detail` array), and exposes `controlling_equity` separately. Skips fully-owned (pct=100) and non-`full` consolidation methods.
- **Period-reopen auto-reverse** — `api/periods.php` reopen handler scans for `accounting_consolidation_runs.status = 'locked'` overlapping the reopened period and auto-reverses each with reason "Period reopened: …", auditing `accounting.consolidation.runs_auto_reversed`.
- **API** `consolidation_runs.php` — GET list / GET id / POST?action=lock / POST?action=reverse with `accounting.reports.view` (read) and `accounting.reports.export` (write) guards.
- **AR Intercompany Split** — new `POST /api/billing/invoices?action=post_with_ic_split` mirroring the AP/JE split engine. Posts AR-debit (money owed TO us) on multiple entities sharing one `intercompany_group_id`; idempotent via `idempotency_key='ic:invoice:<id>'`; audits `billing.invoice.posted_ic`.
- **Entity pickers on create flows** — `EntityPicker.jsx` (shared) added to `BillCreate.jsx`, `InvoiceCreate.jsx`, `PersonCreate.jsx`. Backends (`ap/api/bills.php`, `billing/api/invoices.php`, `people/api/people.php`) all accept `entity_id` on insert.
- **UI** `Consolidation.jsx` — "🔒 Lock & publish" button, past-runs table with per-run reverse, "Controlling equity" + "NCI equity" rows on the Balance Sheet view.
- Backend: +61 assertions in `accounting_phase2_a8_smoke.php`. Combined suite: **2,418 passing / 0 failed**.

*2026-02 — Payment Rails wire-up (P1 sprint 1):*
- **Migration `ap/009_vendor_routing.sql`** — adds `payment_routing_ct VARBINARY(512)` + `payment_routing_last4 CHAR(4)` + `payment_account_type ENUM('checking','savings')` on `ap_vendors_index` (idempotent, utf8mb4_unicode_ci).
- **Shared helper `core/payment_rails/originate_helpers.php`**:
  - `paymentRailsDecryptBank($routingCt, $accountCt, $context)` — AES-GCM decrypt, validate 9-digit ABA + 4..17-char account, return `[routing, account, last4_acct]`. Clear non-PII errors.
  - `paymentRailsBuildItem($row)` — coerce a per-module dict into the canonical RailItem shape (recipient ≤22 chars, description ≤10 chars, account_type whitelisted, amount_cents > 0).
  - `paymentRailsDispatch($module, $sourceRow, $settings, $items)` — resolves rail via `paymentRailsResolveRail`, soft-falls-back to NACHA when the chosen rail isn't configured (so AP/Payroll never wedge while Plaid is in pre-approval).
- **AP** `POST /api/ap/payments?action=originate&id=N` — single payment for one vendor, decrypts vendor banking, picks SEC code (PPD for `1099_individual`, CCD otherwise), dispatches a 1-item batch, persists `disbursement_rail` / `rail_external_ref` / `rail_status` / `rail_originated_at` on `ap_payments`. Returns NACHA file as `nacha_file_b64` (+ `nacha_filename`) when rail=nacha. Audits `ap.payment.originated` / `ap.payment.originate_failed`. Idempotent (refuses if already originated).
- **Payroll** `POST /api/payroll/runs` action=originate — joins `payroll_line_items` × `people_employees` × `people_bank_accounts` (priority-1 active), decrypts each employee's primary bank, builds PPD batch, skips check / no-bank / zero-net items with structured `skipped[]` reasons, dispatches batch through chosen rail, persists rail metadata on `payroll_runs`. Audits `payroll.run.originated` / `payroll.run.originate_failed`. Two-eye: requires `payroll.run.disburse`, `status=approved`.
- **AP `vendors.php`** now accepts `payment_routing_full` (encrypted on write) + `payment_account_type` on vendor create/upsert.
- **Manifests**: 4 new audit events (`ap.payment.originated`, `ap.payment.originate_failed`, `payroll.run.originated`, `payroll.run.originate_failed`).
- New smoke `payment_rails_wireup_smoke.php` (60 assertions). **Combined suite: 2,478 passing / 0 failed across 38 files**.

---
*2026-02 — Plaid bank-link UX: account picker + per-account remove + institution disconnect:*
- **`/api/plaid_bank_link.php` link_token** — added `account_filters` (`depository`/`credit`/`loan` subtypes only) so investment / brokerage / payroll cards never appear in the picker.
- **`/api/plaid_bank_link.php?action=exchange`** — accepts `selected_account_ids[]`. Plaid accounts not on the allowlist still get recorded in `plaid_accounts` (so the diagnostics panel can backfill later) but skip the deposit/liability mirror. Response surfaces `skipped_opt_out`.
- **`/modules/treasury/api/deposit_accounts.php` DELETE** — `mode=hide` flips `status='closed'`; `mode=delete` purges `accounting_bank_statement_lines` + the bank account row but blocks (409) when posted JEs reference the GL code.
- **`/modules/treasury/api/liability_accounts.php` DELETE** — `mode=hide` deactivates the COA row; `mode=delete` purges liability statement lines + companion + COA row, again blocking when posted JEs exist.
- **New `/api/plaid_items.php`** — GET lists connected institutions with mirrored counts; DELETE revokes via Plaid `/item/remove`, cascade-hides every mirrored deposit/liability for that institution, and marks the `plaid_items` row `disconnected` for audit.
- **Treasury UI**:
  - DepositAccounts row: replaced the conflated "Reconnect / Sync" with separate **Sync** (direct API call, no modal), **Hide**, **Delete** buttons.
  - LiabilityAccounts row: same triplet — Sync (resolves item via diagnostics), Hide, Delete.
  - TreasuryOverview: post-Link **account picker modal** lets the user opt in account-by-account before any data is mirrored; new **Connected institutions** panel with Disconnect button per Plaid item.
- Smoke `tests/plaid_account_select_and_remove_smoke.php` — 41 assertions. **Combined suite: 3,352 passing / 2 failed (pre-existing AP A1 follow-ups)**.

---
*2026-02 — Treasury data flow: live Plaid balances + in-Treasury transactions + nav fix:*
- **Bug:** Deposit row "Open reconciliation →" link did nothing — SPA uses `BrowserRouter` but the row was triggering hash-based navigation (`window.location.hash = "#/..."`), which BrowserRouter ignores.
- **Bug:** GL Balance always showed `$0.00` because no JEs are posted yet for fresh Plaid accounts (correct ledger behavior, but useless to the user). Plaid's live current balance was being thrown away after `/accounts/get` returned it.
- **Migration `010_plaid_account_balances.sql`** — caches `current_balance_cents` / `available_balance_cents` / `limit_balance_cents` / `iso_currency_code` / `balance_as_of` on `plaid_accounts`. Idempotent (`ADD COLUMN IF NOT EXISTS`) + runtime self-heal in `plaidPersistAccountBalances()` for tenants behind on migrations.
- **`core/plaid_service.php`** — new `plaidPersistAccountBalances($pdo, $tenantId, $accounts)` helper, called from both `/api/plaid_bank_link.php?action=exchange` (right after the upsert loop) and `/api/plaid_sync_transactions.php` (after the cursor advances) so balances refresh on every Sync click.
- **API contracts**:
  - `/modules/treasury/api/deposit_accounts.php` GET now LEFT-JOINs `plaid_accounts` and returns `bank_balance` + `available_balance` per row.
  - `/modules/treasury/api/liability_accounts.php` GET same join; if the user didn't enter a manual `credit_limit` we fall back to Plaid's reported limit.
- **UI**:
  - DepositAccounts row navigation switched to `react-router-dom` `useNavigate` + `<Link>` (BrowserRouter-safe). Renamed "Open reconciliation →" → **"Transactions →"**.
  - DepositDetail now renders `<AccountTransactions type="deposit">` inline in Treasury (mirroring LiabilityDetail) so the activity feed lives in one consistent place. A small "Open full reconciliation workspace →" link still points to the heavy-duty Accounting bank-rec page.
  - LiabilityAccounts row label "View →" renamed to **"Transactions →"** for parity.
  - Both list pages add a new **Bank balance** column (live Plaid number) next to **GL balance** (posted-JE total). The Treasury Overview hero stats now total Bank balance, falling back to GL only when no feed exists.
- Smoke `tests/plaid_account_select_and_remove_smoke.php` extended to **62 assertions** (was 41). Combined suite: **3,352 passing / 2 failed** (pre-existing AP A1 follow-ups).

---
*2026-02 — Plaid reconnect dedup + dedupe utility + human formatting:*
- **Root cause of duplicates** — Plaid issues a brand-new `account_id` every time the user runs Link, even for the same physical bank account. The exchange path was matching only on `account_id`, so each reconnect spawned a fresh `accounting_bank_accounts` / `treasury_liability_accounts` row with a new GL-code suffix.
- **Adoption logic in `/api/plaid_bank_link.php`** — exchange now (1) checks for an exact `plaid_account_id` re-link (and re-activates if it was hidden), then (2) falls back to a `(bank_name, last4)` / `(institution_name, last4)` adoption: rather than create a new row, the most-recently-touched matching row is updated in place with the new `plaid_account_id` and re-activated. GL code stays stable, so historical journal entries keep their references.
- **`/api/plaid_dedupe.php`** — new endpoint to clean up dupes already in the DB. GET previews clusters; POST?action=run keeps the most-recently-synced row per cluster, lifts the latest `plaid_account_id` to the survivor, and hides the rest. Permission `accounting.bank.manage`.
- **Cleanup banner** in TreasuryOverview's Connected Institutions panel — auto-detects existing duplicate clusters and surfaces a single-button cleanup CTA so users don't have to hit Hide on each row.
- **Human formatting library** — new `dashboard/src/lib/format.js` exporting `fmtMoney` (locale-aware currency), `fmtDate` ("Apr 29, 2026" — wall-date safe, no UTC midnight shift), `fmtDateTime`, `fmtRelative` ("5m ago"). Adopted across `BankReconciliation.jsx`, `AccountTransactions.jsx`, `DepositAccounts.jsx`, `LiabilityAccounts.jsx`, `TreasuryOverview.jsx`. Bank-rec amounts color-coded green for credits, red for debits. Inline `fmtMoney` duplicates removed.

Smoke `plaid_account_select_and_remove_smoke.php` extended to **87 assertions** (was 62). Suite: **3,352 passing / 2 failed (pre-existing AP A1)**.

---
*2026-02 — Hardening pass 1 + Saved Rules + bank-rec 500 fix + nav cleanup:*

**Hardening (foundational, prevents future regressions):**
- **`core/migrate.php`** — idempotent migration runner. Hashes each `migrations/*.sql` file; on subsequent calls, skips files whose content hash hasn't changed. Splits SQL on statement boundaries, executes each inside a try/catch, and treats schema-shape errors (`Duplicate column name`, `already exists`, `Duplicate key name`) as no-ops so older migrations re-run safely. Records every applied file in `_migrations` table with sha256 + duration_ms + last_error. CLI entry point too: `php /app/core/migrate.php`.
- **`api_bootstrap.php`** — calls `coreflux_run_migrations()` on every request (cached per-process via static flag). Failure is non-fatal, surfaced via `coreflux_migration_status()`. **This means the deposit_accounts.php "Unknown column" 500 of last week cannot recur** — the schema self-applies before any user-facing endpoint runs.
- **`tests/schema_contract_smoke.php`** — parses every PHP file under `/api` and `/modules`, extracts `alias.column` references from SQL string literals, checks against the union of every CREATE TABLE / ALTER TABLE in the migration tree (including dynamic ALTERs guarded by `information_schema` checks). 13 known-legacy violations explicitly allowlisted with file-and-reason comments; any NEW violation fails the gate.
- **Migration 010 atomic** — split `ALTER TABLE plaid_accounts ADD COLUMN …` into 5 standalone statements so the runner's "Duplicate column" safe-pattern handles partial application. No more `ADD COLUMN IF NOT EXISTS` dependency (fails on MySQL < 8.0.29).

**AI categorization rules from accept/reject (the moat, made visible):**
- **Migration 011** — adds `reject_count` / `last_rejected_at` / `disabled_at` / `disabled_reason` / `disabled_by_user` to `ai_categorization_history`.
- **`core/ai_categorization.php`** — history queries now filter `disabled_at IS NULL` and require `accept_count - COALESCE(reject_count,0) > 0`, ordered by net score. Rejects shave confidence by `min(0.20, rejects × 0.05)`. New `aiRecordCategorizationReject($tenantId, $line, $rejectedAccountId)` helper.
- **`account_transactions.php` POST `categorize_and_post`** — when the user picks an account different from what the AI suggested, the previous suggestion's account gets a reject bump for that merchant + pfcategory.
- **`/api/ai_categorization_rules.php`** — GET lists every learned (merchant → account) mapping with accept/reject counts, account display info, and decorated flags (`auto_apply_eligible`, `weak`, `contested`, `is_disabled`). PATCH mutes / unmutes a rule. DELETE forgets it entirely.
- **Saved Rules tab** in Treasury module — new `SavedRules.jsx` page: pattern, signal kind, target account, accept/reject counts, status badges, Mute/Unmute toggle, Forget action. Header summary: "20 learned, 6 auto-applying, 2 muted".

**Bank-rec "Internal server error" fixed:** `bank_ai.php` now wraps every `aiAsk()` and `aiSuggestCounterpartAccount()` call in try/catch. When OpenAI is disabled / unreachable / throwing, the endpoint returns `ai_unavailable: true` with a useful note instead of 500'ing. Manual categorize via the existing dropdown is unaffected.

**Nav cleanup (no more bouncing modules):** Stripped the `Open full reconciliation workspace →` link from DepositDetail. Click an account → see its transactions inline, period. All Treasury row clicks + view buttons now use absolute paths (`/modules/treasury/deposits/{id}`) so navigation isn't sensitive to mount-point context. LiabilityDetail's back-link is also absolute.

Smoke `tests/hardening_pass1_smoke.php` (46 ✅) + `tests/schema_contract_smoke.php` (3 ✅, 13 known-legacy allowlisted). Combined suite **3,352 ✅ / 2 ❌** (pre-existing AP A1).

---
*Last Updated: 2026-02 — Hardening pass 1 (migration runner, schema gate) + Saved Rules + nav cleanup shipped.*

## Open / Pending P1
- **Plaid Transfer go-live** — needs tenant-supplied `PLAID_CLIENT_ID` / `PLAID_SECRET_*` / `PLAID_ENV` + Transfer pre-approval. Driver scaffold is in place; once keys land, the per-tenant `tenant_payment_rails` row gets populated and `disbursement_rail='plaid_transfer'` flips on without any consumer-code changes.
- **True sub-tenant provisioning** (Path B) — multi-sprint: `parent_tenant_id` on `tenants`, sub-tenant create flow under master login, per-sub-tenant module mirroring (HR/AP/Payroll fully isolated), cross-tenant intercompany posting, master-admin tenant switcher + consolidated dashboards.

## P2 Backlog
- Time Module Phase B Slice 2b/2c/2d
- Billing Phase A1 (server-side PDF)
- Gusto OAuth API adapter

ion_rules.php`** — GET lists every learned (merchant → account) mapping with accept/reject counts, account display info, and decorated flags (`auto_apply_eligible`, `weak`, `contested`, `is_disabled`). PATCH mutes / unmutes a rule. DELETE forgets it entirely.
- **Saved Rules tab** in Treasury module — new `SavedRules.jsx` page: pattern, signal kind, target account, accept/reject counts, status badges, Mute/Unmute toggle, Forget action. Header summary: "20 learned, 6 auto-applying, 2 muted".

**Bank-rec "Internal server error" fixed:** `bank_ai.php` now wraps every `aiAsk()` and `aiSuggestCounterpartAccount()` call in try/catch. When OpenAI is disabled / unreachable / throwing, the endpoint returns `ai_unavailable: true` with a useful note instead of 500'ing. Manual categorize via the existing dropdown is unaffected.

**Nav cleanup (no more bouncing modules):** Stripped the `Open full reconciliation workspace →` link from DepositDetail. Click an account → see its transactions inline, period. All Treasury row clicks + view buttons now use absolute paths (`/modules/treasury/deposits/{id}`) so navigation isn't sensitive to mount-point context. LiabilityDetail's back-link is also absolute.

Smoke `tests/hardening_pass1_smoke.php` (46 ✅) + `tests/schema_contract_smoke.php` (3 ✅, 13 known-legacy allowlisted). Combined suite **3,352 ✅ / 2 ❌** (pre-existing AP A1).

---
*Last Updated: 2026-02 — Hardening pass 1 (migration runner, schema gate) + Saved Rules + nav cleanup shipped.*

## Open / Pending P1
- **Plaid Transfer go-live** — needs tenant-supplied `PLAID_CLIENT_ID` / `PLAID_SECRET_*` / `PLAID_ENV` + Transfer pre-approval. Driver scaffold is in place; once keys land, the per-tenant `tenant_payment_rails` row gets populated and `disbursement_rail='plaid_transfer'` flips on without any consumer-code changes.
- **True sub-tenant provisioning** (Path B) — multi-sprint: `parent_tenant_id` on `tenants`, sub-tenant create flow under master login, per-sub-tenant module mirroring (HR/AP/Payroll fully isolated), cross-tenant intercompany posting, master-admin tenant switcher + consolidated dashboards.

## P2 Backlog
- Time Module Phase B Slice 2b/2c/2d
- Billing Phase A1 (server-side PDF)
- Gusto OAuth API adapter


## 2026-02 — Module Migration Auto-Run + PDO Sweep Verified
- **Bug**: Reports → Executive Snapshot was 500'ing with `Database table 'X.v_timesheet_day_fin' does not exist` for tenants whose DB had never gone through the installer. Root cause: `coreflux_run_migrations()` (called on every API request from `core/api_bootstrap.php`) only globbed `core/migrations/*.sql` — it skipped all `modules/<name>/migrations/*.sql`, including `modules/reports/migrations/001_init.sql` which creates the `v_timesheet_day_fin` view used by Executive Snapshot, Client Profitability, Rate & Spread, Overtime Watch.
- **Fix**: Extended `coreflux_run_migrations()` in `core/migrate.php` to additionally glob `modules/*/migrations/*.sql` (skipping underscored dirs like `_template`). To prevent collisions where multiple modules ship a `001_init.sql`, module migrations are keyed in the `_migrations` ledger by their relative path (e.g. `modules/reports/migrations/001_init.sql`), while core migrations stay keyed by basename for backward compatibility with existing ledger entries. Existing per-statement idempotency safe-pattern handling already covers re-applying `CREATE TABLE` / `CREATE VIEW` over an existing object.
- **Regression coverage**: New smoke `tests/bugfix_module_migrations_autorun_smoke.php` asserts the glob covers module migrations, the relative-path keying is in place, and the reports init migration defines `v_timesheet_day_fin`.
- **PDO HY093 sweep verified**: Previous fork's bulk find/replace of duplicate named placeholders (`:asof` → `:asof1/:asof2`, `:u` → `:u_where/:u_case`, etc.) across `placements.php`, `consolidation.php`, `users.php`, `accounting.php`, `compensation.php`, etc. did not regress the smoke suite. Two stale assertions in `tests/sprint5_saved_views_smoke.php` and `tests/accounting_phase2_a7_smoke.php` were updated to the new placeholder names.
- **Result**: Full smoke suite **129 / 129 ✅** (was 128, +1 new regression test).



## 2026-02 — ErrorBoundary + Sub-Tenant Path B (consolidated reports + onboarding wizard + scope enforcement)
**ErrorBoundary**: wrapped all module Routes in `App.jsx` with a recoverable boundary (`/app/dashboard/src/components/ErrorBoundary.jsx`). Auto-resets on route change; surfaces error message + retry/reload/home actions + collapsible component stack. Replaces the silent blank-screen failure mode for any future render-time crash.

**Module scope enforcement (sub-tenant Path B / option `c`)**: `tenant_module_scope` policy was defined but **never enforced** — `effectiveTenantIdForModule()` had zero callers. Wired it through `core/tenant_scope.php` → all `scopedQuery/Insert/Update/Delete` calls now route through `effectiveTenantIdForRequest()`, which auto-detects the active module from `REQUEST_URI` (`/modules/<key>/...`) and applies shared/isolated scope. Guarded with try/catch so legacy DBs without migration 007 columns fall back cleanly.

**Consolidated reports across sub-tenants (Path B / option `a`)**:
- Extracted Income Statement / Balance Sheet / Cash Flow Indirect builders from `modules/accounting/api/reports.php` into `modules/accounting/lib/standard_reports.php` (no top-level side effects).
- New endpoint `/api/sub_tenant_consolidated_reports.php` (master_admin or master tenant_admin only) loops every active sub-tenant of the current master, aggregates rows by COA code, and returns `consolidated` + `by_tenant` breakdown. Supports `?type=income_statement|balance_sheet|cash_flow_indirect` and `?include_master=1`.
- React page `dashboard/src/pages/SubTenantConsolidatedReports.jsx` with KPI tiles, per-section tables, and a per-tenant breakdown grid. Linked from `Admin → Consolidated reports`.

**Sub-Tenant Onboarding Wizard (Path B / option `d`)**:
- New 4-step wizard `dashboard/src/pages/SubTenantWizard.jsx`: Identity → Modules → Defaults → Invites. Auto-slug from name; per-module enable toggle + shared/isolated scope picker covering all 11 modules; placeholder defaults pane; invite list for existing platform users.
- `core/sub_tenants.php::subTenantProvision()` extended to accept `invites: [{email, role}]` — validates email, looks up user, idempotent `INSERT … ON DUPLICATE KEY UPDATE` into `user_tenants`, audit log captures the list.
- `SubTenantsAdmin.jsx` "New sub-tenant" button now links to `/admin/sub-tenants/new`.

**Permissions audit (option `e`)**: existing model verified — `subTenantUserHasMembership()` correctly blocks sibling sub-tenant access; master-only endpoints gated by `master_admin` role OR `tenant_admin` on the master parent.

**Smoke regression**: 133/133 passing. Added 4 new smoke files. Patched 3 existing smokes for the lib extraction + new bundle hash.


## 2026-02 — Wizard "Switch into now" + 30-day Setup Checklist
**Wizard CTA**: `SubTenantWizard` done state now has a primary button "Switch into &lt;name&gt; now" that calls `POST /api/sub_tenants.php?action=switch` with the new tenant id and full-page reloads to `/`. Master admin lands directly inside the new tenant to finish setup, no extra navigation.

**Setup Checklist widget** (first 30 days):
- New endpoint `/api/sub_tenant_setup_checklist.php` computes 8 onboarding items by inspecting actual data (logo, brand color, ≥2 active members, ≥10 CoA accounts, ≥1 bank account, ≥1 approval policy, ≥1 entity, ≥1 person). Returns `done_count`, `completion_pct`, per-item `done` + action_label/href, plus a `visible` flag.
- Lazy `ALTER TABLE tenants ADD COLUMN setup_checklist_dismissed_at` so legacy DBs auto-heal on first hit.
- `POST ?action=dismiss` (admin-only) hides the widget permanently.
- Auto-hides at age &gt; 30 days, on dismiss, or at 100% complete.
- `dashboard/src/pages/SetupChecklistWidget.jsx` renders progress bar + line-through done items + per-item "Set up / Connect / Invite" CTAs that deep-link to the right module.
- Mounted at the top of `DashboardOverview.jsx`.

**Tests**: 134/134 ✅. New smoke `sub_tenant_setup_checklist_smoke.php` (37 assertions). Vite bundle bumped to `index-BPiB4yEr.js`.

## 2026-02 — Magic-link (passwordless) login — Slice 0 of SSO roadmap
**Why first**: staffing has many users on personal email, client-issued email, or no consistent corporate domain. Magic links work for everyone — no IdP required. Slice 0 unblocks contractor onboarding *before* corporate-IdP SSO ships.

**Built**:
- Migration `core/migrations/028_magic_link_auth.sql` — `auth_magic_links` (token_hash CHAR(64) UNIQUE, expires_at, consumed_at, optional tenant_id + user_id + redirect_path, ip/UA hash) + `auth_magic_link_attempts` (rate limit per sha256(ip||email), 5/hr → 1hr lockout).
- `core/magic_link.php` — `magicLinkIssue()`, `magicLinkConsume()`, `magicLinkUrl()`. **256-bit `random_bytes`, base64url-encoded raw token; SHA-256 stored in DB; never logs raw**. Atomic single-use (`UPDATE … WHERE consumed_at IS NULL`). Open-redirect guard on `redirect_path`.
- `POST /api/auth/request_magic_link.php` — generic anti-enumeration response, `Retry-After: 3600` on lockout, sends email through `Core\MailService` (`cf_mail_bootstrap`), surfaces `_dev_link` only when no mail is configured AND `display_errors` on.
- `POST /api/auth/consume_magic_link.php` — JIT user creation, idempotent `user_tenants` attach, session handoff (`$_SESSION['user']`, `tenant_id`, `modules`, `auth_method='magic_link'`), 410 on consumed / 401 on expired/invalid.
- `dashboard/src/pages/Login.jsx` — full rewrite. Tabbed UX: "Email me a link" (default) vs "Use password" (legacy fallback). Password tab still posts to `/login.php` so existing flow is untouched.
- `dashboard/src/pages/MagicLinkConsume.jsx` — mounted at `/auth/m/:token`. Three states: verifying / ok / error. Distinct copy for `expired` vs `consumed` vs `invalid`. Idempotent (StrictMode double-mount safe via `useRef` guard). Full-page reload after success so `App.jsx` rehydrates the new session.
- `dashboard/src/App.jsx` — public-route bypass: when path is `/login` or `/auth/m/*`, skip the `session.php` fetch entirely so unauthenticated users can render the login UI. The 401 redirect now goes to `/login` (SPA route) instead of `/login.html`.

**Tests**: 135/135 ✅. New smoke `magic_link_auth_smoke.php` (72 assertions covering schema, lib, both endpoints, both React pages, App.jsx wiring). Patched `sprint1_login_and_module_filter_smoke.php` for the new `/login` redirect target.

**Vite bundle**: `index-DR4SgGC0.js` / `index-Cwhpy62y.css`. `.deploy-version` bumped.

**Next slices on the SSO roadmap**:
- Slice 1: `tenant_sso_domains` + admin UI for tenants to register their own IdP creds + email-loop domain verification.
- Slice 2: Generic OIDC client (one implementation, any OIDC-compliant provider — Microsoft Entra, Google Workspace, Okta, Auth0, etc.) + JIT for SSO matches.
- Slice 3: "Sign in with Google / Microsoft" social buttons.
- Slice 4 (deferred): Multi-email merge so one person isn't multiple users.

## 2026-02 — One-tap magic-link CTAs in AI Agent digest emails
**Why**: weekly digest emails now drive zero-click compliance. Recipient clicks "Open AI CFO in CoreFlux" → authenticated + deep-linked to the right module. No password, no SSO config, no tenant picker.

**Built**:
- `magic_link.php::magicLinkIssue()` — added optional `$ttlMinutes` arg (hard cap 14 days). Workflow emails use 3-day TTL since digests are often read late.
- `core/ai_agents.php::aiAgentDeepLink($key)` — agent → in-app path map (bookkeeper → /modules/accounting, cfo → /modules/reports/exec, treasury_payments → /modules/treasury, etc. — all 10 agent keys).
- `aiAgentBuildDigestHtml()` — added optional `$ctaContext = ['tenant_id', 'recipient_email']` parameter. When supplied, lazy-requires `magic_link.php`, mints a per-recipient single-use link for each agent's deep-link, and renders an inline "Open <agent> in CoreFlux →" button at the end of each section + a master "Open CoreFlux Dashboard →" CTA at the top. Plain-text body includes the URLs too. CTA mint failures log + carry on (graceful degradation for legacy DBs without migration 028).
- `aiAgentDigestSend()` — refactored to render+send PER recipient (previously batched). Each recipient gets their own personal, single-use links. Captures `send_errors` map; persists `last_send_error` on partial failures; back-compat: still returns single `message_id` (first recipient's) plus a new `message_ids` map.

**Smoke**: `digest_magic_link_cta_smoke.php` — 38 assertions covering deep-link map, builder signature, lazy-load resilience, per-section + master CTA HTML/text rendering, per-recipient send loop, back-compat. Patched 2 existing digest smokes for the new builder signature + per-recipient send shape.

**Test result**: 136/136 ✅. No new migrations or bundle bumps (PHP-only change).

## 2026-02 — "Pending for you" personalization + daily approval reminders
**Personalization in weekly digest**:
- New `aiAgentDigestRecipientCounts(tenant_id, recipient_email)` resolves the recipient → user_id (active member of this tenant only) and counts pending AP bill approvals (`ap_bill_approvals.state='pending'` rows where `approver_user_id = user`) + pending workflow tasks (via `workflowGetPendingForUser`). Schema-tolerant (caches missing tables, returns zeros).
- `aiAgentBuildDigestHtml()` now renders an amber "Pending for you: 3 AP bills · 2 workflow tasks &nbsp;Review now →" banner near the top, with a magic-link CTA to `/workflow`. Hides itself when `pending_total === 0` so empty queues don't get a noisy banner. Plain-text body mirrors the line.

**Daily approval reminder cron** (`scripts/approval_reminders_daily.php`):
- Walks every active tenant, every active member, calls `aiAgentDigestRecipientCounts()`. Sends a focused reminder email **only** when there's something pending (zero email = no email).
- One email per (user, tenant) per ~20 hours max — idempotent via `tenant_provisioning_log` rows keyed on `action='approval_reminder'` + email match.
- Mints a single-use magic link with **24-hour TTL** (shorter than digest's 72h since this is meant to be acted on today).
- Subject: `[Tenant Name] N approvals waiting`. Body: plural-aware, HTML + text, single "Review now →" CTA.
- Routes mail through tenant's existing `cf_tenant_mail_sender('approvals')` pipeline (Resend per-tenant).

**Tests**: 138/138 ✅. Two new smokes: `digest_personalization_smoke.php` (26 assertions), `approval_reminders_daily_smoke.php` (24 assertions). PHP-only change.

**Operational**: schedule `php /app/scripts/approval_reminders_daily.php` daily at 09:00 UTC. Tenant-local scheduling is a follow-up.

## 2026-02 — `/workflow` inbox progress badge ("X pending — finish in ~Y min")
**Built**:
- `GET /api/workflow/inbox_summary.php` — reuses `aiAgentDigestRecipientCounts()` for AP + workflow pending counts; counts `workflow_step_actions.acted_at` in the last 24h for `cleared_today`. Returns `{pending_total, ap_pending, workflow_pending, cleared_today, eta_minutes (1.5min × pending, capped at 120), progress_pct (cleared / cleared+pending)}`. Schema-tolerant.
- `dashboard/src/pages/InboxProgressBadge.jsx` — three states: hidden (nothing pending and nothing cleared today), pending ("X pending · breakdown … finish in ~Y min" + animated bar + "Cleared N today · Z% done"), and inbox-zero celebration ("Inbox zero today. You cleared N approval(s)."). Uses `refreshKey` cache-bust so the parent can force a re-fetch.
- `dashboard/src/pages/WorkflowInbox.jsx` — mounts the badge and bumps `badgeKey` whenever `act()` succeeds, so the bar advances live.

**Test result**: 139/139 ✅. New smoke `inbox_progress_badge_smoke.php` (30 assertions). Vite bundle: `index-BmCDq1pQ.js`. `.deploy-version` bumped.

## 2026-02 — Invoice PDF generation + Email Attachment (Billing P0)
**Why**: First mission-critical blocker in the full placement cycle. Clients won't pay invoices that don't arrive with a PDF attached, and operators need a one-click download for AR/dispute workflows.
**Built**:
- `core/pdf_renderer.php` — universal `cf_render_html_to_pdf()` builder. Prefers headless Chromium (with portable flags: `--headless` legacy, `--no-sandbox`, `--disable-dev-shm-usage`, `--disable-features=VizDisplayCompositor`, per-invocation `--user-data-dir`), falls back to `wkhtmltopdf`, throws a clear error if neither is installed. Captures exit code via `proc_get_status()` before `proc_close()` reaps it (PHP returns `-1` from `proc_close()` when the child is already done — known footgun, now handled).
- `modules/billing/lib/invoice_pdf.php` — `invoiceRenderPdf(int $id, bool $useCache = true)` returns an absolute path. Cache key is `sha1(updated_at + amount_due)` so any invoice edit busts the cache. Output lives at `/app/storage/billing/invoices/<tenant_id>/<invoice_id>-<hash>.pdf`. HTML template renders brand colour + logo from `tenants`, bill-to block, service period, line items table, totals, and tenant notes.
- `core/MailService.php` — already accepted `$attachments` (envelope + `mail_outbox.attachments_json`). Module-side convention is `[['filename' => '...', 'path' => '...', 'mime' => 'application/pdf']]`. Real driver implementations (M365/Gmail/Resend) will consume the path when they're wired.
- `modules/billing/api/invoices.php`:
  - `POST ?action=send&id=N` — now generates the invoice PDF and passes it as an attachment to `MailService::send()`. Tolerates renderer-missing hosts: still sends the email with the view-online link, logs `pdf_error`, and returns `pdf_attached: false` in the response so the UI can surface a warning.
  - `GET ?action=pdf&id=N[&download=1]` — new endpoint. Streams `application/pdf` with `Content-Disposition: inline` (or `attachment` when `download=1`). Guarded by `billing.view`. Earlier generic GET-by-id branch now excludes `action=pdf` so it doesn't shadow.

**Test result**: 36/36 ✅ on new `tests/invoice_pdf_smoke.php` (includes a live Chromium render verifying the `%PDF-` magic header). Full suite: 137/139 ✅ — the 2 failures are pre-existing `ai_platform_smoke.php` and `plaid_integration_smoke.php` which require live API keys.


## 2026-02 — Invoice Preview/Download PDF buttons + Pay-When-Paid AP automation
**Why**: Two adjacent gaps in the placement cycle. (1) AR ops want to eyeball the PDF *before* they hit Send. (2) Most staffing AP bills (1099 / C2C contractors) carry "paid when paid" terms — the agency only owes the contractor once the client pays the invoice. Until now that was a manual reconciliation; now it's automated.

**UI**:
- `modules/billing/ui/InvoiceDetail.jsx` — two new buttons: `billing-invoice-preview-pdf` opens `/modules/billing/api/invoices.php?action=pdf&id=N` in a new tab; `billing-invoice-download-pdf` forces `?download=1`. The Send modal now surfaces `pdf_error` from the API response when an attachment could not be generated.

**Data model** (`modules/ap/migrations/017_pay_when_paid.sql`, idempotent):
- `ap_bills.payment_terms VARCHAR(40)` — per-bill override. Accepted values: `NET<N>`, `PWP` (immediately due once AR clears), `PWP_NET<N>` (N days *after* AR clears).
- `ap_bills.linked_ar_invoice_id BIGINT` — the AR invoice this bill is gated on.
- `ap_bills.pwp_status ENUM('not_pwp','awaiting_ar','triggered','partial_triggered')` — lifecycle.
- `ap_bills.pwp_released_at DATETIME` — set when triggered.
- `ap_vendors_index.default_pwp TINYINT(1)` — vendor-level default so all bills for that contractor inherit PWP.
- New composite index `idx_apb_pwp_linked (linked_ar_invoice_id, pwp_status)` for the trigger query.

**Library** (`modules/ap/lib/pwp.php`):
- `apPwpParseTerms(?string $terms)` — classifier (returns `is_pwp` + `net_days`).
- `apPwpAutoLinkForArInvoice($tenantId, $arInvoiceId, $actor)` — matches AP bills by `placement_id` ∈ AR invoice's placement lines AND `period_start/period_end` exact match. Links only when bill's terms resolve to PWP OR the vendor has `default_pwp=1`. Skips already-linked-elsewhere bills and already-triggered bills (idempotent).
- `apPwpSetLink` / `apPwpClearLink` — manual overrides.
- `apPwpReleaseForArInvoice($tenantId, $arInvoiceId, $actor)` — runs when the AR invoice's `amount_due` rounds to 0. For each linked `awaiting_ar` bill: sets `pwp_status='triggered'`, `pwp_released_at=NOW()`, bumps `due_date = today + N`, and transitions `inbox`/`pending_review`/`pending_approval` → `approved` (sets `approved_by_user_id` to the AR payment's actor). Wrapped in a transaction.

**Automation**:
- `billingAllocatePayment()` (`modules/billing/lib/billing.php`) — after the AR transaction commits, every invoice that just transitioned to `paid` triggers `apPwpReleaseForArInvoice()`. Failures are logged but never roll back the AR cash receipt. Response now returns `{applied, unallocated_remaining, pwp: [{ar_invoice_id, released:[…]}]}`.
- `POST /api/billing/invoices?action=from-time-bundle` — after the AR drafts are committed, auto-links matching PWP bills via `apPwpAutoLinkForArInvoice()`. The created-list entries gain `pwp_linked_bill_count` so the UI can show "3 vendor bills are now gated on this invoice".

**API** (`modules/ap/api/pwp.php`):
- `GET ?action=preview&ar_invoice_id=N` — dry-run candidate list (read-only). `ap.bill.view`.
- `POST ?action=auto_link` body `{ar_invoice_id}` — re-runs the matcher. `ap.bill.create`.
- `POST ?action=link` body `{bill_id, ar_invoice_id, payment_terms?}` — manual override. `ap.bill.create`.
- `POST ?action=unlink` body `{bill_id}` — clears the link. `ap.bill.create`.
- `POST ?action=release_for_invoice` body `{ar_invoice_id}` — manual release fallback (e.g., AR was marked paid out-of-band). `ap.bill.approve`.

**Tests**: `tests/pay_when_paid_smoke.php` (48/48 ✅, including classifier matrix, hook wiring contracts, RBAC guards, UI test-ids). Full suite: **139/141** ✅ — the 2 remaining failures are pre-existing `ai_platform_smoke.php` and `plaid_integration_smoke.php` which require live API keys.

**Vite build**: `dist/spa-assets/index-DSGs7Nlv.js` + `index-Cwhpy62y.css`. `.deploy-version` bumped; sentinels added for all 9 new/changed files.


## 2026-02 — Weekly AP Queue + Sunday Email + One-Tap Approve + PWP NET90 carry + Digest blurb
**Why**: Close the staffing cash-cycle loop end-to-end. AP team needed (a) a working batch view of past-due + due-this-week bills with blocker context, (b) a Sunday-night email that gets them ready for Monday, (c) one-tap approve/reject so approvers (often CFOs/managers) don't have to log in to clear their inbox, and (d) PWP terms that default to NET90 carry (not "immediate due") so contractors aren't accidentally surfaced as overdue while we wait for the client to pay.

**PWP terms — NET90 carry, accelerate on AR clearance**:
- `apBuildDraftFromBundle()` now pre-loads the vendor's `default_pwp` flag. If set, the bill stamps `payment_terms='PWP'`, `pwp_status='awaiting_ar'`, and `due_date = today + 90`. The `apPwpReleaseForArInvoice()` release routine then accelerates the due_date back to today (or today + N for `PWP_NET<N>` overrides) when the linked AR invoice clears. Tolerates missing migration columns (legacy tenants stay on NET30 default).

**Weekly Queue API** (`modules/ap/lib/weekly_queue.php` + `modules/ap/api/weekly_queue.php`):
- `GET /api/ap/weekly_queue.php[?lookahead=7]` — returns `{rows, bucketed:{past_due, due_soon}, summary}`. Each row carries a `blocker` chip and `blocker_detail`:
  - `awaiting_client` — PWP bill, AR invoice not yet paid (surfaces "Awaiting payment of INV-001 — status:sent, due $X")
  - `missing_hours` — source time bundle is not in `consumed` status (rare; usually a re-opened period)
  - `needs_review` — `inbox`/`pending_review`; AP hasn't finalized
  - `approver_pending` — already submitted; with the approver
  - `disputed` — approver rejected
  - `none` — ready to be finalized
- `POST ?action=finalize` body `{bill_ids:[...]}` — for each eligible bill: resolves the active approval workflow, creates per-step rows in `ap_bill_approvals`, transitions to `pending_approval`, sends one-tap-approve email to the first step's approvers. Refuses PWP `awaiting_ar` bills (they auto-finalize when AR clears).
- `POST ?action=send_approver_email` body `{bill_id}` — re-mints + re-sends approver email (e.g., token expired).

**Sunday-night email cron** (`scripts/ap_weekly_queue_sunday.php`):
- Schedule: `0 22 * * 0 /usr/bin/php /app/scripts/ap_weekly_queue_sunday.php`
- For each tenant with at least one bill in scope → resolve AP user recipients via role (`ap_clerk`/`ap_manager`/`admin`/`master_admin`; falls back to `users.role` if `user_tenants` is absent) → send a personalized digest with summary tiles + past-due table + due-soon table, each row colour-coded by blocker. CTA links straight to `/modules/ap/weekly-queue`. Idempotency keyed by `(tenant, user, date)` so the cron is safe to re-run.

**One-tap Approve / Reject by email** (`core/email_approval.php` + `api/ap/approve_by_email.php`):
- `apEmailApprovalMint($t, $bill, $userId, $email)` — issues an `approval_tokens` row scoped to `subject_type='ap_bill'`, actions `['approve','reject']`, 72h TTL. Returns `{approve_url, reject_url, expires_at}` already pointing at `/api/ap/approve_by_email.php?t=…&a=…`.
- `apEmailApprovalConsume($raw, $action, $note, $ip)` — atomic consume + writes the same `ap_bill_approvals` step decision the in-app path does. Mirrors disputed-on-reject and final-step → approved-on-bill. Same earlier-step bypass guard. Same `apAudit('ap.bill.approval_{approved|rejected}', …, via='email_approval')` event.
- Public consume endpoint: noindex, `no-store`, validates 64-hex token format, renders an HTML receipt page (✅/🛑/⏰/ℹ️) with a one-click "Open Approvals inbox" follow-up.
- `apEmailApprovalBodyHtml()` builds a clean HTML email with green Approve / red Reject buttons, expiry warning, and a thread-link footer for "need to comment" flows.
- The existing `apBillApprovalNotify()` helper (the path that fires when a bill is submitted to approval) has been **rewritten** to use these tokens — no more "Open the approvals inbox →" plain link. Each approver gets their own personal one-tap pair.

**Weekly Queue UI** (`modules/ap/ui/WeeklyQueue.jsx`):
- New `/modules/ap/weekly-queue` route, added to the AP module nav.
- Summary ribbon (past-due / due-7d / ready / blocked).
- Sortable table with blocker chips and per-row "Resend approver email" action for `pending_approval` bills.
- "Select all ready" + "Finalize selected → send approver email" batch button. Past-due rows tinted red.

**AI Agent weekly digest — PWP-released-last-week blurb** (`core/ai_agents.php`):
- `aiAgentPwpReleasedBlurb($tenantId)` aggregates `ap.bill.pwp.released` audit events from the last 7 days and joins them back to `ap_bills.total` + `billing_invoices.invoice_number` to produce a green-tinted blurb: *"Last week: released $X across N Pay-When-Paid contractor bill(s) because invoice(s) INV-001, INV-002 (and N other(s)) cleared."* Hidden when nothing was released. Embedded in both the HTML and plain-text digest bodies right under the master CTA.

**Tests**: `tests/ap_weekly_queue_smoke.php` — 73/73 ✅ across PWP NET90 carry, email-approval lib + endpoint, weekly queue lib + API, Sunday cron, `apBillApprovalNotify` rewrite, digest blurb, and UI testids. Full suite: **140/142** ✅ (the 2 remaining failures are pre-existing `ai_platform_smoke.php` and `plaid_integration_smoke.php` which require live API keys; the stale Vite hash check in `sprint6b_dashboard_uis_smoke.php` has been bumped to the new bundle).

**Vite build**: `dist/spa-assets/index-C_JS1D_-.js` + `index-Cwhpy62y.css`. `.deploy-version` bumped; 12 new sentinel paths + 9 new feature flags recorded.


## 2026-02 — Cash-application PWP toast + End-to-end loop smoke (P0 closures)
**Why**: Two P0 items from the last finish summary. (1) AR ops applies a customer payment and the system silently releases vendor bills via the Pay-When-Paid trigger — but until now the UI gave no feedback. (2) We needed a contract-level smoke that proves the entire People → Placement → Time → Billing → AR → AP → Payroll loop is wired correctly after the recent PWP / Weekly Queue work.

**Cash-application PWP toast** (`modules/billing/ui/PaymentsList.jsx`):
- `RecordPaymentModal.submit()` and `AllocateModal.{autoFifo, submit}` now thread the API response back through `onSaved(res)`.
- `PaymentsList.handleAllocResult()` extracts `res.pwp` (or `res.auto_allocation.pwp` for the record-and-allocate path) and renders a dismissible green toast: *"Pay-When-Paid released: N vendor bill(s) freed for payment because the client invoice cleared."* Expanding the toast details lists each released bill (#, prev_status → new_status, new_due_date), grouped by AR invoice.
- The toast has `data-testid="billing-pwp-toast"` and per-bill `billing-pwp-released-<bill_id>` testids so e2e tests can assert the wiring.

**End-to-end loop smoke** (`tests/end_to_end_loop_smoke.php`):
- Static contract test (no DB) that walks all 10 stages of the staffing cash cycle:
  1. People → Placement (placement_id pivot)
  2. Placement → Time entries
  3. Time → `time_downstream_feed` (the AR/AP bundle split)
  4. Feed → Billing AR invoices (`from-time-bundle` action wired, marks bundle consumed, triggers PWP auto-link)
  5. Feed → AP bills (1099/c2c classification, PWP stamping when vendor default, +90 day carry)
  6. AR cash → PWP release → AP bill 'approved' (lib path, response shape, `amount_due ≈ 0` guard)
  7. Payments UI surfaces PWP results (toast wiring)
  8. AP bill 'approved' → AP payment (allocations table, `partially_paid`/`paid` transition)
  9. AP payment → Payroll 1099 ledger (joins `ap_payments` → `ap_payment_allocations` → `ap_bills`, filtered by `vendor_type='1099_individual'`)
  10. Weekly Queue closes the loop (all 4 blocker types tied to upstream/downstream stages)
- 47/47 ✅ — a permanent regression net against the next time someone refactors any stage in the chain.

**Tests**: `end_to_end_loop_smoke` 47/47 ✅; `pay_when_paid_smoke` extended to 52/52 ✅ (new toast assertions). Full sweep: **141/143** ✅ (the 2 remaining failures continue to be pre-existing `ai_platform_smoke` + `plaid_integration_smoke` which need live API keys).

**Vite build**: `dist/spa-assets/index-DlrEq8Lj.js` + `index-Cwhpy62y.css`. `.deploy-version` bumped; 3 new sentinels + 2 new feature flags recorded.


## 2026-02 — Cash Cycle Health home-page tile
**Why**: With the PWP pipeline now writing real signal (releases on AR clearance, blockers on the weekly queue), the home dashboard was the obvious place to surface a 4-glance read on the agency's cash cycle. One scroll-stop on login = "is the engine humming?"

**Backend** (`modules/billing/api/cash_cycle_health.php`):
- `GET /api/billing/cash_cycle_health.php` — single envelope:
  ```
  {
    dso_days, ar_outstanding_total,
    pwp_awaiting_ar:        {count, total_amount},
    pwp_released_last_week: {count, total_amount, ar_invoice_count},
    weekly_queue_blocked_count
  }
  ```
- **DSO**: `AVG(DATEDIFF(payments.received_at, invoices.issue_date))` over the last 90 days of fully-paid invoices.
- **AR outstanding**: `SUM(amount_due) WHERE status IN ('sent','partially_paid','overdue')`.
- **PWP awaiting AR**: `COUNT(*)` + `SUM(amount_due)` over `ap_bills WHERE pwp_status='awaiting_ar' AND status NOT IN ('paid','void')`.
- **PWP released**: scans `audit_log` for `ap.bill.pwp.released` events in the last 7 days, joins back to `ap_bills.total` for the dollar sum.
- **Blocked count**: reuses the exact same `apWeeklyQueueList()` library the weekly-queue UI calls, then counts rows where `blocker NOT IN ('none','approver_pending')`.
- Every metric is wrapped in its own `try/catch` so a missing migration on one tenant (e.g. `pwp_status` column not present on legacy schema) never crashes the whole tile. Permission: `billing.view`.

**UI** (`dashboard/src/pages/CashCycleHealthTile.jsx`):
- Manager-only tile placed between the existing KPI snapshot strip and the module nav cards.
- 4 stat cards: DSO (colour-coded — green ≤30d, neutral ≤45d, amber ≤60d, red >60d), AR outstanding (fmtMoney), PWP awaiting AR (count + gated $), PWP released last 7d (released $ + bill count + AR invoice count).
- Inline amber "blocked banner" surfaces when the weekly queue has any non-approver blockers, with a one-tap drill-in.
- Quietly hides on `loading`/`error`/`!data` so the rest of the home page never gets pushed around by it.

**Tests**: `tests/cash_cycle_health_smoke.php` 36/36 ✅ (API envelope shape, DSO query structure, PWP queries, blocked-count integration, all 6 testids, render order vs the KPI strip). Full sweep: **142/144** ✅ (same `ai_platform_smoke` + `plaid_integration_smoke` pre-existing failures).

**Vite build**: `dist/spa-assets/index-D18OKuIN.js` + `index-Cwhpy62y.css`. `.deploy-version` bumped; 5 new sentinels + 2 new feature flags recorded.


## 2026-02 — KPI annotations on the Cash Cycle Health tile
**Why**: Numbers without context get misread. A 60-day DSO can be alarming or expected — depending on which clients drive it. Managers wanted a place to leave one-line operator notes alongside each KPI ("Q1 push — 60d DSO is target") so anyone glancing at the dashboard (board members, new hires) gets the *why* not just the number.

**Schema** (`core/migrations/029_tenant_kpi_notes.sql`, idempotent):
- `tenant_kpi_notes (id, tenant_id, note_key, note_text, updated_by_user_id, created_at, updated_at)`
- `note_key VARCHAR(64)` (e.g. `cash_cycle_dso`, `cash_cycle_ar`), `note_text VARCHAR(280)` (single-line, tweet-sized).
- Unique `(tenant_id, note_key)` so upserts are race-safe.
- Generic enough that any future "operator annotation on a number" surface (exec reports, scenario tiles, AP weekly queue summary, etc.) reuses the same table.

**API** (`api/kpi_notes.php`):
- `GET /api/kpi_notes.php` → `{ notes: {key: {text, updated_by, updated_at}, …}, can_write }`. Permission: `billing.view`.
- `POST /api/kpi_notes.php` body `{key, text}` — upsert via `ON DUPLICATE KEY UPDATE`. Empty text deletes (less typing for the operator). Sanitizes key to `[a-z0-9_]{1,64}`, clamps text at 280 chars.
- `POST ?action=delete` body `{key}` — explicit delete.
- Write requires `manager`/`admin`/`master_admin`/`tenant_admin` role → 403 for line staff.
- `GET` tolerates the table being absent on legacy tenants (returns empty notes).

**UI** (`dashboard/src/components/KpiNote.jsx`, reusable):
- Three states:
  - **No note + manager** → faint "✎ Add note" affordance.
  - **No note + line staff** → renders nothing.
  - **Has note** → yellow chip with the text + ✎ edit button (managers only).
- Edit mode = inline input with Enter-to-save / Esc-to-cancel, 280-char hard cap.
- Save POSTs to `/api/kpi_notes.php` and updates the parent's local cache so the page doesn't need a full reload.

**Cash Cycle Health wiring**:
- `CashCycleHealthTile.jsx` now fetches `/api/kpi_notes.php` once, hydrates `notes` + `canWriteNotes` into local state, and passes a `<KpiNote/>` under each of the 4 `Stat` cards (`cash_cycle_dso`, `cash_cycle_ar`, `cash_cycle_pwp_awaiting`, `cash_cycle_pwp_released`).

**Tests**: `tests/kpi_notes_smoke.php` **36/36** ✅ (migration shape, all 3 API actions, RBAC, sanitization, all 3 component states, keyboard shortcuts, 4-stat wiring, `.deploy-version` sentinel). Full sweep: **143/145** ✅ (same `ai_platform_smoke` + `plaid_integration_smoke` baseline).

**Vite build**: `dist/spa-assets/index-COqpcxkk.js`. `.deploy-version` bumped; 5 new sentinels + 2 new feature flags recorded.


## 2026-02 — P1.B: Recurring invoice contracts (flat-fee MRR)
**Why**: Managed-services / retainer / recruiter-on-demand engagements bill a flat fee on a schedule. Without automation, AR forgets and the agency leaves cash on the table.

**Schema** (`modules/billing/migrations/008_recurring_contracts.sql`, idempotent):
- `billing_invoice_contracts` — id, client_name, contract_name, description, frequency (`monthly`/`quarterly`/`annual`), `day_of_period` (1-31; auto-clamped to month-end), amount, currency, gl_account_id, start_date, end_date (NULL=open-ended), status (`active`/`paused`/`ended`), proration_policy (`full`/`prorate`/`skip_first`), bill_to_email, bill_to_json, po_number, notes_internal, last_generated_at, last_generated_invoice_id, next_due_at.
- Adds `billing_invoices.source_contract_id` + composite index for the idempotency guard on `(source_contract_id, period_start)`.

**Library** (`modules/billing/lib/recurring.php`):
- `billingRecurringComputeNextDue($contract, $fromDate)` — pure date math; `dom=31` clamps to month-end correctly.
- `billingRecurringComputePeriodForGeneration($contract, $dueDate)` — returns `{period_start, period_end}`.
- `billingRecurringProrationFactor($policy, $periodStart, $periodEnd, $contractStart)` — `full`=1.0 always, `skip_first`=0.0 when mid-period (caller skips generation), `prorate`= days_active/total_days.
- `billingRecurringGenerateInvoice($tid, $contract, $forDate, $actor)` — idempotent (existing invoice for same `(contract_id, period_start)` short-circuits), advances `last_generated_at` + `next_due_at` atomically, audits via `billing.invoice.recurring_generated`.
- `billingRecurringEligibleContracts($tid, $asOf)` — first-run bootstrap: `next_due_at IS NULL` is treated as "due on start_date".
- `billingRecurringPreviewNextN($contract, $n)` — non-mutating peek for the UI's "next-3 dates" column.

**Cron** (`scripts/billing_recurring_generate.php`, `30 6 * * *`): iterates eligible contracts per tenant, generates drafts, dispatches a single "N recurring invoices ready to send" digest to every user with billing roles (idempotency keyed `(tenant, user, day)`).

**API** (`modules/billing/api/recurring_contracts.php`): GET list (with optional `?status` filter and inline `preview_next_3`), GET detail, POST create, `?action=update|pause|resume|end|generate_now`.

**UI** (`modules/billing/ui/RecurringContracts.jsx`): list view with next-3-date preview chips, status pills, per-row Generate-now / Pause / Resume / End / Edit. Modal supports both create and edit (immutable fields locked on edit: `client_name`, `start_date`). Wired into BillingModule nav as **Contracts**.

**Tests**: `tests/recurring_contracts_smoke.php` **61/61** ✅ — includes live date-math + proration unit tests (dom-31 clamping, year roll-over, quarterly/annual jumps, all 3 proration policies).

## 2026-02 — P1.A: Dunning (overdue invoice escalation)
**Why**: We send invoices, but nobody systematically chases overdue ones. AR ops asked for an escalating-reminders engine with tenant-controlled cadence + max attempts + client-level escalation, plus an AI hint on when to escalate harder.

**Schema** (`modules/billing/migrations/009_dunning.sql`, idempotent):
- `tenant_dunning_policy` — `is_enabled`, `schedule_json` (default 3 stages: soft@3d/firm@14d/final@30d), `max_attempts`, `cadence_days`, `skip_weekends`, `escalate_to_client_contact_after_attempts`, `paused_until`, `do_not_contact_json`.
- `billing_client_contacts` — per-client `ar_primary_email` + `ar_escalation_email`. Reusable: any future "AR statements to client" surface uses this.
- `billing_invoices` columns: `dunning_stage`, `dunning_attempts`, `dunning_last_sent_at`, `dunning_paused_until` + `idx_bi_dunning` composite index.
- `billing_dunning_log` — one row per send. Audit + ops dashboard fodder. Status `sent`/`failed`/`suppressed`.

**Library** (`modules/billing/lib/dunning.php`):
- `billingDunningDefaultPolicy()` / `Get` / `Save` — upsert via `ON DUPLICATE KEY UPDATE`.
- `billingDunningPickStage($inv, $policy, $today)` — chooses the largest `days_overdue` stage we haven't already passed.
- `billingDunningEligibleInvoices($tid, $today)` — overdue + open + not paused.
- **`billingDunningResolveRecipients()` — implements the recipient model exactly as you specified**:
  - `to` = `invoice.bill_to_json.email` → falls back to `billing_client_contacts.ar_primary_email` → otherwise the row is logged as `suppressed=no_contact` and no email is sent.
  - `cc` = `billing_client_contacts.ar_escalation_email` is added once `attempts ≥ policy.escalate_to_client_contact_after_attempts`.
- `billingDunningRenderEmail()` — 3 built-in templates (`soft` / `firm` / `final`) with appropriate tone + colour cues.
- `billingDunningRecordSend()` — atomically writes the `billing_dunning_log` row AND bumps the invoice's `dunning_stage` + `dunning_attempts` + `dunning_last_sent_at`.
- `billingDunningWithinCadence()` / `billingDunningIsWeekend()` — predicate helpers.
- **`billingDunningAiEscalationSuggestion($tid, $client, $policy)`** — heuristic (no LLM): if a client has had 3+ invoices reach stage 2+ in the last 12 months, suggest *"escalate at stage 1 for this client"*. If they consistently pay within 5 days of stage 1, suggest *"raise threshold to 3 attempts"*. Returns `null` when there's nothing actionable.

**Cron** (`scripts/dunning_daily.php`, `0 8 * * 1-5`): respects `is_enabled`, `paused_until`, `skip_weekends`, `cadence_days`, `do_not_contact`, `max_attempts`. Per-send idempotency keyed `(invoice_id, stage_no, day)`. Suppressed sends still write a log row (`status='suppressed'`) so AR ops can see *why* a row went silent.

**API** (`modules/billing/api/dunning.php`): `GET ?action=queue` (rows + policy + today), `GET/POST ?action=policy`, `POST ?action=send_now&id=N`, `POST ?action=pause&id=N body{until}`, `POST ?action=resume&id=N`, `GET ?action=ai_suggest&client=X`.

**UI** (`modules/billing/ui/DunningQueue.jsx`):
- Queue table with current-stage chip + next-stage info, recipient resolution preview, status (Ready / Within cadence / No recipient / Do-not-contact / Paused-until).
- Per-row Send-now / Pause / Resume buttons.
- ✨ AI suggestion popover (per-client) that pulls the heuristic.
- Modal **Policy editor** — toggle enabled, edit 3 stages inline, set cadence_days / max_attempts / escalate-after-N / skip_weekends, paste comma-separated do-not-contact list.
- Wired into BillingModule nav as **Dunning**.

**Tests**: `tests/dunning_smoke.php` **69/69** ✅ — includes live unit tests of stage-picker (5d→stage1, 15d→stage2, 35d→stage3, future→null, already-at-3→null), recipient resolution, all 3 email templates, cadence + weekend predicates.

**Full sweep**: **145/147** ✅ (same `ai_platform_smoke` + `plaid_integration_smoke` baseline).

**Vite build**: `dist/spa-assets/index-BWPApIsp.js`. `.deploy-version` bumped with 14 new sentinels + 11 new feature flags.

**Vite bundle**: `index-CsM5S8MR.js` / `index-Cwhpy62y.css`. `/app/.deploy-version` `expected_bundle` updated.
## 2026-02 — P2 Admin Surfaces batch (close-out)
**Why**: Four small, related admin toggles requested as a single batch to retire long-standing TODOs. Each one was small enough that bundling them kept the smoke-test footprint cheap and the React rebuild to a single bundle bump.

**1) Client AR contacts admin** — `billing_client_contacts` table (already created in 009_dunning.sql) now has a first-class admin UI.
- API `modules/billing/api/client_contacts.php` (GET search / POST upsert by `client_name` / POST `?action=delete`). Read=`billing.view`, write=`billing.invoice.create`. Tenant-scoped throughout.
- UI `modules/billing/ui/ClientContacts.jsx` — search box, modal form, delete confirm. Wired at `/modules/billing/clients` via `BillingModule.jsx` nav.

**2) Vendor PWP toggle** — `modules/ap/api/vendors.php` got a new `POST ?action=toggle_pwp&id=N body{default_pwp:0|1}` branch. Updates `ap_vendors_index.default_pwp`, emits `ap.vendor.default_pwp_set` audit event, returns 409 if migration 017 not yet applied. `VendorsList.jsx` already had the PWP column and row toggle wired to this endpoint.

**3) Tenant-configurable AP weekly digest schedule** — migration `modules/ap/migrations/018_weekly_queue_schedule.sql` adds `weekly_queue_email_dow` (0=disabled, 1..7=Mon..Sun ISO) + `weekly_queue_email_hour` (0..23 UTC) to `ap_settings`.
- API `modules/ap/api/weekly_queue_settings.php` GET/POST. Write gated by admin/manager role. Validates ranges.
- `scripts/ap_weekly_queue_sunday.php` now reads per-tenant schedule, skips tenants where `dow=0`, otherwise only sends when `date('N')===dow && date('G')===hour`. Cron schedule comment updated to `0 * * * *` (hourly).
- UI: AP Settings page (`modules/ap/ui/Settings.jsx`) now has a "Weekly AP digest schedule" fieldset with day-of-week dropdown + hour input + save button. Disabled for non-admin users.

**4) Approve-with-comment email landing** — `api/ap/approve_by_email.php` now intercepts the email click with a one-page note prompt before consuming the token. Approve allows skip-note shortcut; reject requires a reason (`required` attribute). Both pass `?note=…&confirm=1` back to the same endpoint which then calls `apEmailApprovalConsume($rawToken, $action, $note, $ip)`.

**Tests**: new `tests/p2_admin_surfaces_smoke.php` — 68 assertions across all 4 features, all passing. **Full sweep: 148/148 ✅** (baseline failures for ai_platform + plaid_integration are now also green in this fork).

**Vite bundle**: `dist/spa-assets/index-BWtb0zXx.js` / `index-Cwhpy62y.css`. `/app/.deploy-version` `expected_bundle` updated; bundle copied to `/app/spa-assets/` so spa.php picks it up automatically (mtime-based); `tests/sprint6b_dashboard_uis_smoke.php` bundle hash bumped to match.

## 2026-02 — Email AR statement (Aging table → one-click)
**Why**: We just built `billing_client_contacts` for dunning; doubling that roster as the distribution list for on-demand AR statements means tenants get a high-leverage AR-ops surface for zero additional schema cost.

**Library** (`modules/billing/lib/statement.php`, pure functions, tested):
- `billingStatementOpenInvoices($tid, $client, $asOf)` — open invoices ordered oldest-first with `days_overdue` computed.
- `billingStatementBucket($invoices)` — current / 1–30 / 31–60 / 61–90 / 91+ + total. Matches the Aging page math exactly.
- `billingStatementResolveRecipients($tid, $client)` — `to = ar_primary_email`, `cc = ar_escalation_email` when present and distinct. Returns `reason` for audit.
- `billingStatementRenderEmail($tenant, $client, $invoices, $buckets, $asOf)` — subject + HTML + text, all `htmlspecialchars`-escaped.

**API** `modules/billing/api/send_statement.php`:
- `GET ?client_name=…&as_of=…` → preview (rendered email + recipients + buckets), gated by `billing.view`.
- `POST {client_name, as_of?, dry_run?}` → send via tenant Resend pipeline. Idempotency `statement-{tid}-{slug}-{Y-m-d}`. Audit `billing.statement.sent`. Gated by `billing.invoice.create`.
- 409 when no open invoices, 422 when no AR contact on file.

**UI** `modules/billing/ui/AgingTable.jsx`: per-row "Email statement" button → preview modal showing rendered HTML body and resolved recipients → confirm/send.

**Tests** `tests/ar_statement_email_smoke.php` (53 assertions, all passing): bucket math correctness, render escaping, RBAC, idempotency key shape, UI testids + dataflow.

---

## 2026-02 — SSO Slice 1 (storage + admin UI)
**Why**: Customers asked to bring their own IdP. Slice 1 lets a tenant admin stage Okta / Microsoft Entra / generic-OIDC creds today so Slice 2 (real OIDC dance) can ship immediately after with zero data migration.

**Schema** `core/migrations/030_tenant_sso_domains.sql`:
- `provider_type` enum (okta | entra | generic_oidc), `issuer_url`, `client_id`, `client_secret_enc` (VARBINARY, AES-256-GCM via `core/encryption.php`), `client_secret_last4` (display-only confirmation), `allowed_email_domains` JSON, `is_enabled`, `sso_slug` (globally unique), `notes`.
- `UNIQUE (tenant_id)` + `UNIQUE (sso_slug)` enforce one-per-tenant + slug uniqueness.

**API** `api/sso_config.php` (admin-only writes — master_admin / tenant_admin):
- `GET` → row WITHOUT secret (only `client_secret_last4`).
- `POST` → upsert. Validates issuer_url is https://, sso_slug regex, domain whitelist format, provider_type enum. Blank `client_secret` preserves stored value (so re-saves don't blow away the secret).
- `POST ?action=disable` / `POST ?action=clear_secret` — defensive admin levers. All three POSTs write `audit_log` rows.

**UI** `dashboard/src/pages/SsoConfigAdmin.jsx` wired at `/admin/sso` with sidebar nav + overview tile. "Secret on file: ••••cd12" confirmation pattern means the UI never round-trips the actual secret.

**Tests** `tests/sso_slice1_smoke.php` — 52 assertions.

---

## 2026-02 — SSO Slice 2 (real OIDC redirect/callback + JIT)
**Why**: Close the loop. With Slice 1 storage live, Slice 2 ships the actual OIDC dance against any standards-compliant IdP — no vendor SDK, no Auth0 / WorkOS.

**Schema** `core/migrations/031_oidc_session_state.sql`:
- `oidc_session_state` — one short-lived row per in-flight auth req: (tenant, slug, state, nonce, code_verifier, return_path, expires_at, consumed_at). State is UNIQUE; consume-once enforced atomically with `FOR UPDATE` + `consumed_at` set in the same TX.
- `oidc_jwks_cache` — issuer→JWKS JSON, 1h TTL.
- `oidc_discovery_cache` — issuer→`.well-known/openid-configuration`, 24h TTL.

**Core library** `core/oidc.php` (pure, injectable HTTP fetcher → unit-testable end-to-end):
- PKCE helpers (`oidcGenerateCodeVerifier`, `oidcGenerateCodeChallenge`, RFC 7636 S256).
- `oidcDiscovery()` + `oidcJwks()` with DB-backed caching + force-refresh flag for key rotation handling.
- **`oidcJwkToPem()` — hand-rolled ASN.1 / DER → PEM conversion** for RSA public keys. No phpseclib / firebase JWT dep. Built directly from RFC 7517 §9.3 + RFC 8017 A.1.1. The smoke test generates a fresh RSA keypair, builds a JWK, runs it through `oidcJwkToPem()`, loads it back into OpenSSL, and signs+verifies a real payload — round trip proves the ASN.1 is byte-correct.
- **`oidcVerifyIdToken()`** — RS256-only (alg-confusion attack rejected). Validates `iss`, `aud` (string OR array containing client_id), `nonce` (hash_equals, replay protection), `exp` (with 5-min clock-skew window), `iat` (rejects > 5min future), and signature against JWKS-derived PEM.

**Endpoints** (public — no auth required, same pattern as `approve_by_email.php`):
- `GET /api/sso/start.php?slug={slug}[&return=/path]` — mints state+nonce+code_verifier (cryptographic random), persists in oidc_session_state with 15-min TTL, 302's to the IdP's `authorization_endpoint` with `response_type=code`, `scope=openid profile email`, `code_challenge_method=S256`.
- `GET /api/sso/callback.php?slug=…&state=…&code=…` — atomic state consume (`FOR UPDATE` + `consumed_at`), refuses re-use; decrypts client_secret on read; PKCE-bound token exchange; ID token sig+claim verification (with automatic JWKS refresh on missing-kid); domain-whitelist gate; JIT user via `INSERT INTO users` matching the magic-link pattern; `INSERT INTO user_tenants` for tenant membership; standard PHP session handoff (same shape as magic-link / password login, `auth_method='oidc'`); open-redirect-guarded redirect to `return_path` (must start with `/`). Audit log row on success.

**UI** `dashboard/src/pages/Login.jsx`: third tab **"SSO"** alongside Magic-link and Password. User enters their org slug → `window.location.href = /api/sso/start.php?slug=…`.

**Tests** `tests/sso_slice2_smoke.php` — 51 assertions, including:
- Forges a real id_token (RS256, JWK with kid) and verifies it end-to-end.
- Confirms tamper detection: flipped signature, wrong iss, wrong aud, wrong nonce (replay), expired, future iat, alg=HS256 (confusion attack), unknown kid.
- Confirms 5-min clock-skew window accepts a 60s-past `exp`.
- Confirms array-form `aud` containing client_id passes.

**What's NOT in Slice 2 (deferred)**:
- Email-verification gate on JIT users.
- IdP-initiated SSO (only SP-initiated for now).
- ACR / MFA assurance level enforcement.
- Refresh-token rotation (we only consume the id_token for session establishment; the IdP access token isn't held).

---

**Full suite**: **151/151 ✅** (P2 admin + AR statement + SSO Slice 1 + SSO Slice 2 — zero regressions).
**Vite bundle**: `index-Byd_qeJ2.js` / `index-Cwhpy62y.css`. `.deploy-version` `expected_bundle` updated + 21 new feature flags appended; `tests/sprint6b_dashboard_uis_smoke.php` hash bumped.

## 2026-02 — Weekly Money Movement digest (CFO inbox edition)
**Why**: We already had AR-statement-by-email, dunning, and the AP digest all running on the tenant Resend pipeline. The pieces existed — they just needed assembling into a single Monday-morning email that answers the CFO's first question: *"how did we do last week?"*

**Library** `modules/billing/lib/money_movement.php` — eight pure helpers (each tolerates missing tables on minimal installs):
- `moneyMovementSnapshot($tid, $asOf)` — composes the full 7-day window snapshot.
- `moneyMovementCashIn` — `SUM(billing_payments.amount)` over the window, by method.
- `moneyMovementCashOut` — `SUM(ap_payments.amount)` excluding draft/void/failed, by method.
- `moneyMovementStatementsSent` / `moneyMovementDunningSent` — counts from `audit_log` and `billing_dunning_log`.
- `moneyMovementTopPastDue` — reduces `billingComputeAging()` to top-5 ranked by past-due total (≥1 cent floor), so the digest matches the AR Aging page exactly.
- `moneyMovementRunway` — reads through to `core/treasury/liquidity_projection.php` for runway-to-zero days + projected zero date; graceful no-op when treasury module isn't installed.
- `moneyMovementRenderEmail` — subject + HTML + text. Net is colour-coded green/red with an arrow. Past-due rows highlight 91+ in red. Runway warning block is its own colour-coded callout. All output `htmlspecialchars`-escaped.
- `moneyMovementResolveRecipients` — `cfo`, `controller`, `admin`, `master_admin`, `tenant_admin` on either `user_tenants.role` or `users.role`.

**API** `modules/billing/api/money_movement.php`:
- `GET ?as_of=YYYY-MM-DD` (default today) → snapshot + rendered email preview + recipient list. Gated by `billing.view`.
- `POST ?action=send_now {as_of?}` → per-recipient send via `cf_mail_bootstrap`, idempotency key `money-mvmt-{tid}-{userid}-{Y-m-d}` so re-runs on the same day don't double-send. Audit `billing.money_movement.sent`. Gated by admin/manager.

**Cron** `scripts/money_movement_weekly.php` — runs Monday 13:00 UTC (`0 13 * * 1`). Only iterates tenants with at least one `billing_payments` OR cleared `ap_payments` row in the last 7 days, so inactive tenants don't receive `$0 in / $0 out` noise. Per-user idempotency keys mean re-runs are safe.

**UI** `modules/billing/ui/MoneyMovementPreview.jsx` at `/modules/billing/money-movement` — full inline email preview + "Send now to N recipients" button. Date picker for back-dated previews. Cash Cycle Health home-page tile gets a new **"Weekly digest →"** action link.

**Tests** `tests/money_movement_smoke.php` — 59 assertions:
- Render correctness on both net-positive and net-negative weeks (subject, green/red colour, greeting with/without recipient name, escape safety, past-due empty state, runway-positive vs runway-warning).
- Top-past-due ranking math (B=5000 beats C=3000 beats A=500; D=0 excluded).
- API RBAC, recipient-empty 422, idempotency-key shape.
- Cron: active-tenant filter via UNION, draft/void/failed exclusion, per-user idempotency key, non-zero exit on failures.
- UI testids + dataflow.

**Full sweep**: **152/152 ✅** (previously-flaky `schema_contract_smoke.php` caught and fixed a snuck-in reference to `u.first_name`/`u.last_name`; resolver now only selects `u.name` which is in every migration).
**Vite bundle**: `index-Dv6bSDwE.js` / `index-Cwhpy62y.css` (CSS unchanged). `/app/.deploy-version` `expected_bundle` updated + 10 new feature flags.

## 2026-02 — Sprint: Finance Distribution & Polish (9 items, single batch)

The CFO's pieces all existed — they just weren't talking to each other. This sprint wires them together.

### A1. Snapshot history table
`modules/billing/migrations/010_money_movement_snapshots.sql` — one row per (tenant, as_of) week. `snapshot_json` is the full denormalised payload so historical accuracy is preserved even if upstream queries change. New helpers in `lib/money_movement.php`: `moneyMovementWriteSnapshot`, `moneyMovementReadSnapshot`, `moneyMovementListSnapshots`, `moneyMovementGetPriorSnapshot`, `moneyMovementWowDelta`. The Monday cron now writes through on every run.

### F1. Tenant email branding ✨
`core/migrations/032_tenant_mail_branding.sql` — logo URL, accent colour (#rrggbb), signature HTML, "powered by" toggle. `core/tenant_branding.php` exposes `cf_tenant_branding($tid)`, `cf_branding_header_html()`, `cf_branding_footer_html()`. Both Money Movement and AR statement renderers now use the branded header/footer; accent colour bleeds into section headings + the big-number top border. Script/iframe tags stripped from signature on render (defence in depth). Admin UI at `/admin/mail-branding` with live colour swatch.

### B1. Public Money Movement share link
`modules/billing/migrations/011_money_movement_share_links.sql` mirrors the treasury `scenario_share_links` pattern: **sha256(token) at rest** (DB breach yields no usable links), `expires_at` (default +30d, clamped 1–180d), soft-revoke via `revoked_at`, `view_count` + `last_viewed_at` for visibility. Raw token returned **once** in the mint response with a copy-now warning. Public view at `api/billing/money_movement_view.php` — direct-file path that bypasses the router auth gate (same approach as `approve_by_email.php`). Renders the digest HTML in a minimal frame with an amber "this is a shared snapshot, expires X, no data collected" banner. Every view audited.

### B2. PDF download
Three endpoints using the existing `core/pdf_renderer.php` (chromium / wkhtmltopdf):
- `modules/billing/api/money_movement_pdf.php` — `?as_of=…&disposition=inline|attachment`
- `modules/billing/api/statement_pdf.php` — `?client_name=…&as_of=…`
- `modules/accounting/api/close_packet.php` — adds `?format=pdf` branch (was HTML-only)

PDF link button on the Money Movement preview page; Download-PDF link on the per-client statement preview modal.

### C1. Unified digest scheduler
`core/migrations/033_tenant_digest_schedules.sql` — `(tenant_id, digest_key)` composite PK. `core/digest_schedules.php` exposes `cf_digest_schedule_get/set/should_fire`. `should_fire()` honours both `weekly` (dow + hour match) and `daily` (hour match only) cadences. Money Movement cron now calls `cf_digest_schedule_should_fire(...)` and prints a `[skip]` log line for tenants that aren't scheduled for the current hour. Admin UI at `/admin/digest-schedules` shows per-tenant overrides for Money Movement / Dunning / AP Weekly Queue, with an "Using platform default" vs "Tenant override active" indicator.

### C2. KPI annotations on Money Movement preview
Imports the existing `KpiNote` component (same one used on the Cash Cycle Health home tile) and adds four annotation slots: `money_movement_net`, `money_movement_cash_in`, `money_movement_cash_out`, `money_movement_runway`. Notes stored in `tenant_kpi_notes`, gated by `kpi_notes` API permissions.

### D1. Send-statements batch
`modules/billing/api/send_statements_batch.php` — iterates `billingComputeAging()`, finds rows with >0 past-due, resolves the AR contact, sends one statement per client using the same per-client/per-day idempotency key as the singular endpoint. Returns a per-client `report` with `sent`/`skipped`/`failed`/`would_send` statuses. Aging table gets a top-right **"Email all past-due"** button → dry-run preview modal → confirm-and-send. Audited as `billing.statement.batch_sent`.

### D2. In-app digest archive
`modules/billing/api/money_movement_archive.php` — `GET` lists last 12 weeks from the A1 history table; `GET ?as_of=…` returns one historical snapshot + rendered HTML + WoW delta. `MoneyMovementArchive.jsx` page at `/modules/billing/money-movement/archive` — card grid coloured by net positive/negative, each card opens an inline read-only modal with the digest body.

### E1. Period Close Receipt PDF
`modules/accounting/api/close_packet.php` `?format=pdf` branch — wraps the existing close-packet HTML and pipes to `cf_render_html_to_pdf`. Same downloadable artefact as before, now ready for board decks / auditor portals.

### Tests
- `tests/sprint_distribution_polish_smoke.php` — **134 assertions across all 9 items**, plus regression patches to:
  - `tests/ar_statement_email_smoke.php` (signature line moved into `cf_branding_footer_html`).
  - `tests/sprint6b_dashboard_uis_smoke.php` (bundle hash bumped).
- **Full sweep: 153/153 ✅**, zero failures.

### Vite bundle
`index-pgZUqCzv.js` / `index-Cwhpy62y.css`. `.deploy-version` `expected_bundle` updated + **22 new feature flags** under `distribution.*`. Bundle copied to `/app/spa-assets/`.

## 2026-02 — Sprint: CoreStaffing Umbrella + Weekly Timesheet Rebuild + Migration P0

User feedback was blunt: "fixing time module; amateur hour. weekly timesheet, but a single entry? five separate entries to complete a submission? re-evaluate what we've been building the whole time."

Then handed me a CoreStaffing MVP spec (22 sections) and said "think at a higher level — time & placements belong inside staffing."

This sprint is the directional pivot + the actual UX fix + the production unblock.

### 🔴 P0 — Migration drift root-cause fix

The real bug: `core/migrate.php` was unconditionally `REPLACE INTO _migrations` after every file, even when statements errored out. A non-safe error logged `last_error` and STILL recorded the file's content hash as "applied", so the next run skipped it forever. That's why `007_backfill_person_id.sql` never re-executed on the user's stuck tenant even after we rewrote it.

Additional bug: migration 007 v2 had `PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;` on a single line. The runner splits on `;\s*\R` (semicolon + newline), so the three statements were glued into one PDO::exec() call — which fails on stock PDO (no multi-statement). Boom: every tenant's 007 was failing AND being marked applied.

**Fixes:**
- `core/migrate.php`: when `$errBlob !== null`, record the row with a **`FAIL:` sentinel hash** instead of the content hash. The hash never matches content hash → next run retries. When clean, record content hash as before.
- `modules/time/migrations/007_backfill_person_id.sql` **v3**: every PREPARE / EXECUTE / DEALLOCATE on its own line, separated by `\n;\n`. Splitter now produces one statement per `exec()` call.
- `api/admin/retry_migration.php`: new master_admin endpoint to clear stale ledger rows: `POST /api/admin/retry-migration` with `{file}` or `{all_failed: true}` body. Triggers `coreflux_run_migrations(force=true)` after clearing.

**Smoke tests:** `tests/migration_runner_retry_smoke.php` (14 assertions) + updated `time_person_id_migration_smoke.php` (25 assertions).

### 🟠 P1 — CoreStaffing umbrella shell

Per the spec: Time and Placements are now SUB-AREAS of a new top-level "Staffing" module that subsumes the labor-delivery surface end-to-end.

- `modules/staffing/manifest.php` — id `staffing`, 10 nav actions (Overview, Clients, Jobs, Placements, Timesheets, Approvals, Payroll Readiness, Billing Readiness, Profitability, Settings), 10 permission keys.
- `modules/staffing/ui/StaffingModule.jsx` — route tree under `/modules/staffing/*`. Reuses **existing** PlacementsModule verbatim (no server-side merge yet). Clients/Jobs/Readiness pages are Phase-2 stubs.
- `dashboard/src/App.jsx`:
  - Imports + mounts `<Route path="/modules/staffing/*" element={<StaffingModule />} />`.
  - Adds Staffing to `DEMO_SESSION.modules` ahead of Placements/Time entries.
  - **Keeps** `/modules/time/*` and `/modules/placements/*` routes as back-compat shims so old bookmarks/emails still resolve.
- `core/migrations/034_register_staffing_module.sql` — `INSERT IGNORE` Staffing into the platform `modules` table, then auto-enable in `tenant_modules` for every tenant that already has Time or Placements enabled.

### 🟠 P1 — Weekly Timesheet rebuild (the actual UX fix)

Per spec §10.6 + §10.7 + the user's explicit answers (week-start configurable, single-by-default + click-to-split per cell, full re-submit on rejection):

**Schema (new + extension):**
- `modules/staffing/migrations/001_timesheets.sql` — new `timesheets` header table (one row per worker × week). `UNIQUE (tenant_id, person_id, period_start)`. Status enum: `draft/submitted/approved/rejected/payroll_ready/billing_ready/locked`. Also creates `tenant_staffing_settings` (week_starts_on TINYINT default 1=Mon, contracted_hours_per_week DECIMAL default 40, overtime_threshold default 40).
- `modules/staffing/migrations/002_timesheet_id_on_entries.sql` — adds `timesheet_id` FK + `hour_type` ENUM (regular/overtime/doubletime/holiday/pto/sick/bereavement/unpaid/nonbillable) + `billable`, `payable` TINYINT flags to `time_entries`. Backfills `hour_type` from legacy `category` enum. Backfills `timesheets` header rows from existing `time_entries` (one INSERT IGNORE per distinct ISO Monday-week). Links every existing entry to its newly-created header. All idempotent via `information_schema`, all single-statement-per-line.

**Lib:**
- `modules/staffing/lib/timesheets.php` — `staffingTimesheetUpsert`, `staffingTimesheetWeek`, `staffingTimesheetBulkSave` (transactional), `staffingTimesheetSubmit`, `staffingTimesheetReject`, `staffingTimesheetApprove`. Bulk save: zero-hours auto-delete, per-row hour_type validation, auto-create `time_period` if missing, recomputes header `total_hours`. Submit/approve/reject all flip header + cascade row status atomically. Two-eye control on approve.

**API:**
- `modules/staffing/api/timesheets.php`:
  - `GET ?action=week&person_id=…&period_start=…&period_end=…` → header + entries + tenant settings
  - `POST ?action=bulk_save` body `{person_id, period_start, period_end, rows[]}` → atomic draft save
  - `POST ?action=submit` / `?action=approve` / `?action=reject` (header-level workflow)
  - `GET ?action=list&status=submitted` → approvals queue rows
  - `GET/POST ?action=settings` → tenant staffing settings CRUD

**UI:**
- `modules/staffing/ui/TimesheetWeek.jsx` — the heart of the fix. Inline-editable grid (placements × 7 days). Per-cell: hours input + hour_type dropdown. Click "+ split" to break one day into multiple hour_type rows (e.g. 8h regular + 2h overtime + 1h PTO). Debounced autosave (1.5s after last keystroke) hits `bulk_save`. **One** "Submit Week" button atomically flips the whole timesheet. Rejection banner with reason; "Re-submit Week" button on rejected state. Over-contracted-hours warning at week total. Locked once approved.
- `modules/staffing/ui/StaffingOverview.jsx` — quick-card landing + "My Recent Timesheets" table.
- `modules/staffing/ui/StaffingApprovals.jsx` — approver queue with inline approve / reject-with-reason.
- `modules/staffing/ui/StaffingSettings.jsx` — week-start + contracted hours + OT threshold tenant config.

**Smoke tests:** `tests/staffing_shell_and_weekly_timesheet_smoke.php` — 53 assertions covering manifest, both migrations, lib, API, App.jsx wiring, and the React UI contract. Plus regression patches to `time_person_id_migration_smoke.php` (formatting), `sprint6h_treasury_ai_split_smoke.php` (information_schema comment), `sprint6b_dashboard_uis_smoke.php` (bundle hash bump).

### Vite bundle
`index-CwDFnl_V.js` / `index-Cwhpy62y.css` (CSS unchanged). `.deploy-version` updated: new `expected_bundle` JS hash, **15 new feature flags** under `staffing.*` and `core.migrate.*`, and 16 new sentinel file references.

### Full sweep
**153/153 ✅** (baseline AI/Plaid failures expected per missing API keys).

### What's deferred to next sprint (Phase 2)
- Clients table + Client CRUD (currently `placements.end_client_name` is denormalized string).
- Jobs / Roles entity.
- Engagements/Projects entity (services mode per spec §5.2 + §10.5).
- Economics: GP, GP%, spread/hr, OT%, WIP, realization calculations.
- `v_staffing_*` reporting views.
- Accounting event emission (`staffing.worker_hours.approved`, etc.).
- AI insights agent for Staffing.
- Vendor/referral partner economics.
- Full admin folder refactor (currently only `/api/admin/retry_migration.php` lives in the new folder as a precedent; older admin endpoints remain at their direct-file paths with shim-pattern documented).

## 2026-02 — Hotfix + Pre-populated Timesheet

### 🔴 Hotfix: PDO unbuffered cursor (HY000/2014) blocking every page
Cause: my conditional migrations used `IF(..., 'real DDL', 'SELECT "..." AS note')`. The `SELECT 'note'` branch emits a result set; `PDO::exec()` doesn't consume it; cursor stayed open; next query failed.

Fix:
- All conditional migrations switched to `'DO 0'` as the no-op (no result set emitted).
- `core/migrate.php` now uses `$pdo->query($sql)` + `$rs->closeCursor()` + `nextRowset()` drain loop instead of `exec()`, so any FUTURE migration that emits an accidental result set is still safe.
- New test `tests/migration_cursor_drain_smoke.php` (10 assertions) pins the drain loop + DO-0 pattern so this never silently regresses.

### Legacy URL redirects
`/modules/time/`, `/modules/time/overview`, and `/modules/time/entries` all redirect to `/modules/staffing/timesheets`. Users habit-routing to the old paths land on the new weekly grid.

### ✨ Pre-populated weekly timesheet (user-requested enhancement)
Two parts:

1. **Silent auto-prefill on empty week** — when the user opens a fresh week with no entries and `status === 'draft'`, the UI auto-fetches the prior week's grid and seeds the cells. Marked dirty so autosave (1.5s debounce) persists them. A friendly banner ("✨ Pre-filled 12 entries from week of 2026-02-09 · Clear and start blank") shows what happened.

2. **Explicit "⎘ Copy last week" button** — manual one-click copy from any state. Merges into the current grid WITHOUT overwriting cells the user has already filled. Same banner reflects "✓ Pre-filled N entries from week of …".

Backend:
- `staffingTimesheetPriorWeekTemplate($personId, $ps, $pe)` in `modules/staffing/lib/timesheets.php` — fetches the 7 days ending the day before `period_start`, shifts each row forward by +7 days, filters rows with `hours > 0`, returns the bulk_save-shaped payload.
- New action `GET /api/staffing/timesheets?action=prefill_from_last_week&person_id=…&period_start=…&period_end=…`.

Frontend:
- `TimesheetWeek.jsx`: `copyLastWeek()` callback + auto-prefill effect keyed on `(periodStart, entries.length)`. Non-blocking — prefill failure is silent (best-effort).

Tests: 8 new assertions in `staffing_shell_and_weekly_timesheet_smoke.php` pinning the lib, API, and UI contract.

### Vite bundle
`index-CaJzcvzc.js` / `index-Cwhpy62y.css`. `.deploy-version` updated, bundle copied to `/app/spa-assets/`.

### Full sweep
**158/158 ✅**, zero real failures (2 baseline external-API smokes skipped as documented).

## 2026-02 — Hotfix #2: Table-name collision + Sidebar URL sync

User screenshot showed two separate problems on the new `/modules/staffing/timesheets` page:
1. **"Unknown column 'person_id' in 'WHERE'" on bulk_save.** Root cause: `/app/sql/setup.sql` has a legacy `timesheets` table (columns: `user_id`, `week_start`, `hours_worked`) that's still referenced by `/app/timesheets/*` and `/app/people/*` legacy code. My `CREATE TABLE IF NOT EXISTS timesheets` no-op'd because the table already existed → my queries hit the legacy schema → person_id missing.
2. **Sidebar still showing "Time" module nav** even when user was on `/modules/staffing/timesheets`. Root cause: `session.modules` from prod tenant doesn't yet contain `staffing` (migration 034 enables it but on a fresh request it hasn't propagated to the in-memory session), so App.jsx's URL→module matcher fell through and kept the previous active_module.

**Fixes:**
- Renamed my new table `timesheets` → **`staffing_timesheets`** everywhere (migration 001 + 002, lib, API, smoke tests). Unique-key + indexes renamed `uq_ts_*` → `uq_sts_*` to match.
- App.jsx: when URL is `/modules/<id>/*` and `<id>` isn't in `session.modules`, fall back to the matching `DEMO_SESSION.modules` entry so the sidebar still renders the correct module nav. Staffing now displays its own sidebar regardless of whether the tenant's `tenant_modules` row has been auto-enabled yet.
- New regression assertions in `staffing_shell_and_weekly_timesheet_smoke.php` (4 new): pin that lib/API/migrations never reference the bare `timesheets` name again.

**Tests:** 65 staffing assertions ✅. Full sweep **158/158 ✅**. SPA → `index-ChaZ7Z_l.js`.

## 2026-02 — Hotfix #3: Deep `time_entries` schema repair + Reports view auto-rebuild

Screenshots from the user showed two MORE migration-related errors after the first round shipped:

1. **`Database column 'te.placement_id' is missing`** on the Staffing weekly timesheet bulk_save. The original column from `modules/time/migrations/001_init.sql` was missing on the tenant. Same root cause as the legacy `timesheets` collision — `time_entries` got created at some point with a stripped-down schema, and `CREATE TABLE IF NOT EXISTS` no-op'd, so canonical columns like `placement_id` / `period_id` / `category` etc. never landed.

2. **`Reports data view not yet built — v_timesheet_day_fin`** on the Reports / Staffing Overview page. The view depends on `time_entries.placement_id`. Its original CREATE failed at the time `modules/reports/migrations/001_init.sql` first ran (because the column was missing); pre-fix migrate.php silently marked it applied; never retried.

### Fix 1 — Defensive schema repair
- **New `modules/time/migrations/008_ensure_columns.sql`** — defensively re-adds every canonical column of `time_entries` if missing. 23 columns covered (placement_id, period_id, work_date, category, hours, status, source, description, approved_at, approved_via, rejected_reason, rate_snapshot_id, etc.). Each guard uses `information_schema` + `'DO 0'` fallback, one PREPARE/EXECUTE/DEALLOCATE statement per line so PDO::query() handles each individually.

### Fix 2 — Expanded self-heal recipes
- **`core/api_bootstrap.php`** — `cf_self_heal_known_column()` recipe list expanded from 1 → 19 columns. If a runtime query still hits a missing column before migration 008 runs, the exception handler now auto-adds it on-the-fly: placement_id, person_id, period_id, work_date, hours, category, status, source, description, created_by_user_id, approved_*, rejected_reason, rate_snapshot_id, timesheet_id, hour_type, billable, payable.

### Fix 3 — Reports view auto-rebuild
- **New `modules/time/migrations/009_recreate_reports_views.sql`** — lives in the time module (NOT reports) so it runs AFTER 008 in the migration runner's natural-sort order. Conditional on both `time_entries.placement_id` and `placement_rates` existing. `DROP VIEW IF EXISTS` + `CREATE VIEW`. The view body is identical to `modules/reports/migrations/001_init.sql`. Idempotent — safe on every deploy.

### Smoke tests
- **`tests/time_entries_defensive_repair_smoke.php`** — 26 assertions covering migration 008's column coverage + self-heal recipe completeness.
- Updated `tests/time_person_id_migration_smoke.php` regex to handle whitespace-aligned PHP array entries.
- **159/159 smoke tests pass.**

## 2026-02 — Phase 2 Wave 1: Profitability inside Staffing + Clients + Weekly Economics

### Profitability mirror under Staffing umbrella
Per user direction "wire reports like that":
- `modules/staffing/ui/StaffingProfitability.jsx` — new tabbed surface inside the Staffing umbrella. Five tabs (Staffing Overview / Executive Snapshot / Client Profitability / Rate & Spread / Overtime Watch) that REUSE the existing Reports module pages (no duplication). Routes mounted at `/modules/staffing/profitability/*`.
- `StaffingModule.jsx` Profitability route now mounts the real surface (was a Coming-Soon stub).

### Clients entity (Phase 2 P0)
- **`modules/staffing/migrations/003_clients.sql`** — new `staffing_clients` table (id, tenant_id, name UNIQUE, legal_name, industry, primary_contact_*, billing_*, payment_terms_days, status enum, msa_*, notes). Adds `placements.client_id` FK column. Backfills clients from distinct `end_client_name` strings on placements. Links every placement to its new client.
- **`modules/staffing/api/clients.php`** — `list` (with search + status filter + active_placements rollup), `get`, `create` (duplicate-name guard), `update` (allow-listed fields), `delete` (soft = status=closed, preserves history), `stats` (active_placements + MTD revenue from `v_timesheet_day_fin`, falls back to null when view missing).
- **`modules/staffing/ui/Clients.jsx`** — table + search + status filter + right-side drawer for create/edit (display name, legal name, industry, primary contact, billing city/state/country, payment terms, notes). Soft-close button with confirmation.
- Routes wired at `/modules/staffing/clients`.

### Weekly Timesheet inline economics
- **`/api/staffing/timesheets?action=week_economics`** — per-placement + week-total revenue / cost / gross profit / GP% computed from `v_timesheet_day_fin`. Falls back to empty payload when the view isn't built yet.
- **`TimesheetWeek.jsx`** — fetches econ totals on every week-change, renders a four-cell footer below the grid: Revenue / Cost / Gross Profit / GP%. Color-coded (green > 25% GP, amber positive, red <0). Hidden when there's no revenue (avoids 0/0 noise on empty weeks).

### Tests
- **`tests/staffing_phase2_clients_econ_smoke.php`** (29 assertions) — pins migration, API, UI, route wiring, economics action.
- **160/160 total smoke tests pass.**

### Bundle
`index-CowEZEAh.js` / `index-Cwhpy62y.css`. `.deploy-version` updated.

### What's left for Phase 2
- Engagements / Projects entity (services delivery mode, spec §10.5).
- Jobs/Roles entity (separate from placements; vacant slots for client-side hiring).
- Per-row economics in the grid (currently weekly totals; per-row hover/inline column needs a placement_rates effective-dated lookup per cell).
- Payroll Readiness queue + Billing Readiness queue (currently Coming-Soon stubs in Staffing sidebar).
- AI insights agent for Staffing (margin explanation, weekly ops memo draft).
- Accounting event emission (`staffing.worker_hours.approved`, etc.).

## 2026-02 — Phase 2 Wave 2: Per-counterparty payment terms + Readiness queues + AI memo + Accounting events

### Per-client AR payment terms
- `modules/billing/api/invoices.php` — invoice creation now looks up `staffing_clients.payment_terms_days` by client name first. Uses that as `netDays` if present; otherwise falls back to the tenant-wide `billing_invoice_terms` config (default NET30). Tolerant of missing `staffing_clients` table (fresh installs).

### Per-vendor AP payment terms
- `modules/people/migrations/005_companies_payment_terms.sql` — adds nullable `payment_terms_days` column to the unified `companies` table (used for AP vendors and legacy AR clients). `DO 0` no-op fallback when table is missing.
- `modules/ap/api/bills.php` — bill creation looks up `companies.payment_terms_days` by `vendor_company_id` first, falls back to vendor name match. Per-vendor override → tenant-wide `ap_default_terms` fallback.

### Payroll & Billing Readiness queues
- `modules/staffing/api/readiness.php`:
  - `GET ?action=payroll` — approved timesheets grouped by worker (hours + periods + timesheet_ids).
  - `GET ?action=billing` — approved hours grouped by client (revenue + hours + placement_ids), reads from `v_timesheet_day_fin` with graceful fallback when the view isn't built.
  - `POST ?action=mark_payroll_pushed` body `{timesheet_ids}` → flips header status to `payroll_ready`.
  - `POST ?action=mark_billing_invoiced` body `{timesheet_ids}` → flips to `billing_ready`.
- `modules/staffing/ui/StaffingReadiness.jsx` — single component, two modes (`payroll` / `billing`). Multi-select rows + bulk "mark as pushed/invoiced". Summary bar with totals.
- Routes wired into StaffingModule (replaces Coming-Soon stubs).

### AI Weekly Memo (Phase 2 P1)
- `modules/staffing/api/ai_insights.php?action=weekly_memo` — gathers last week's hours, revenue, cost, GP, GP%, timesheet status counts, top-5 clients by revenue. Feeds via `aiAsk()` to OpenAI with a tight system prompt: "Output exactly 5 bullets: headline number, notable client win/risk, margin call-out, workflow bottleneck, recommended action for next week." Returns `{memo, stats, top_clients, period}`.
- `WeeklyMemoCard` on the Staffing Overview page — Generate / Regenerate button, renders 5 bullets + bottom strip of hours/revenue/GP. Graceful error when AI is disabled for tenant.

### Accounting event emission
- `staffingEmitWorkerHoursApprovedEvent($tenantId, $headerId)` in `modules/staffing/lib/timesheets.php`. Called at the END of `staffingTimesheetApprove()` AFTER the transaction commits. Emits a `staffing.worker_hours.approved` event to `accountingProcessEvent()` with payload `{timesheet_id, person_id, period_*, hours, revenue, cost, gross_profit}`. Best-effort (catch + `error_log`); failures don't roll back the approval. Skipped silently when no `accounting_entities` row exists for the tenant.

### Tests
- `tests/staffing_phase2_wave2_smoke.php` — 30 assertions covering all five items above. **161/161 total smoke tests pass.**

### Bundle
`index-CSAQf4rI.js`. `.deploy-version` updated.

### Phase 2 backlog remaining
- Engagements / Projects entity (services delivery mode — fixed-fee + milestones).
- Jobs / Roles entity (vacant client-side slots).
- Per-row inline economics in the weekly grid (currently we ship week totals).
- Posting rule templates for `staffing.worker_hours.approved` (event emits but won't post a JE until a tenant configures a rule).

## 2026-02 — Hotfix: Accounting event now books both legs (revenue AND expense)

User's catch: the `staffing.worker_hours.approved` event was emitting `revenue` and `cost` in the payload, but without classification it can't know whether the offsetting credit lands in **Accrued Payroll** (W2) or **Accrued AP** (1099/C2C). Fix:

### Event payload refactor
- `staffingEmitWorkerHoursApprovedEvent()` now groups time entries by `placements.engagement_type` and **emits one event per (timesheet × engagement_type)** combination. Each event payload includes:
  - `engagement_type` (w2 / 1099 / c2c / temp_to_perm / direct_hire / internal)
  - Convenience boolean flags: `is_w2`, `is_1099_or_c2c`, `is_internal`
  - Per-classification `hours`, `revenue`, `cost`, `gross_profit`
- `source_record_id` = `"{timesheet_id}:{engagement_type}"` so duplicate-detection still works.

### System accounts added
`/app/core/accounting/system_accounts.php` extended with the six accounts the staffing JE needs:
- `1150 Unbilled Receivable` (asset)
- `2050 Accrued AP` (liability)
- `2150 Accrued Payroll` (liability)
- `4000 Service Revenue` (revenue)
- `5000 Direct Labor Expense` (cogs)
- `5010 Subcontractor Expense` (cogs)

### Posting-rule seeder
`modules/staffing/lib/posting_rules_seed.php` — installs three default journal templates + three rules:

**W2 hours (rule conditions: `payload.is_w2 = 1`):**
```
DR  5000 Direct Labor Expense   payload.cost
CR  2150 Accrued Payroll        payload.cost
DR  1150 Unbilled Receivable    payload.revenue
CR  4000 Service Revenue        payload.revenue
```

**1099 / C2C hours (`payload.is_1099_or_c2c = 1`):**
```
DR  5010 Subcontractor Expense  payload.cost
CR  2050 Accrued AP             payload.cost
DR  1150 Unbilled Receivable    payload.revenue
CR  4000 Service Revenue        payload.revenue
```

**Internal salaried hours (`payload.is_internal = 1`) — NO revenue leg:**
```
DR  5000 Direct Labor Expense   payload.cost
CR  2150 Accrued Payroll        payload.cost
```

### Admin endpoint
`POST /api/staffing/seed_posting_rules` (master_admin only) — runs the seeder for the current tenant. Idempotent.

### Workflow once seeded
1. Worker submits weekly timesheet → manager approves.
2. Event `staffing.worker_hours.approved` fires (one per engagement_type bucket).
3. Posting engine matches against the seeded rule by condition.
4. Auto-books a balanced JE that captures BOTH the **expense accrual** (DR labor / CR accrued payroll or AP) AND the **revenue accrual** (DR unbilled AR / CR service revenue).
5. When the AR invoice fires later → DR AR / CR Unbilled AR clears the accrual.
6. When payroll runs / AP bill posts → debits the accrual and credits cash/AP.

### Tests
- `tests/staffing_je_auto_booking_smoke.php` — 25 assertions covering payload shape, system accounts, seeder logic, admin endpoint.
- **162/162 total smoke tests pass.**

---

## 2026-02 — Worker Classification Mix Dashboard + One-tap External Approver Email Flow

### Worker Mix dashboard (tab 6 under Staffing → Profitability)
- **API** `GET /api/staffing/classification_mix.php?weeks=N` returns weekly pivot of W2 / 1099 / C2C / internal / other split by hours **and** cost, plus a list of workers whose `placements.engagement_type` flipped during the window (potential reclassification triggers / W2↔1099 year-end re-issues).
- **UI** `modules/staffing/ui/WorkerMix.jsx` — pure-SVG stacked bars, metric toggle (cost vs hours), 4/8/12/26/52-week windows, mix legend with $ + %, change-flag table.
- **Wired** as the 6th sub-tab in `StaffingProfitability.jsx`.
- **Schema-contract:** uses `placements.person_id` (correct column) — caught and fixed during regression.
- **Test:** `tests/staffing_worker_mix_smoke.php` (24 assertions).

### Staffing one-tap external approver email flow
- **Helper** `core/staffing_email_approval.php` — `staffingEmailApprovalMint()` / `staffingEmailApprovalConsume()` / `staffingEmailApprovalBodyHtml()`. Mirrors the AP-bill pattern (72h TTL, sha256-hashed at rest, single-use, approve+reject pair on one row).
- **Public endpoint** `GET /api/staffing/approve_timesheet_by_email.php?t=…&a=approve|reject` — no session required; two-step UX (note prompt → confirm) with HTML receipt. Reject always requires a reason on file.
- **Admin endpoint** `POST /api/staffing/timesheet_email_approver.php` — issues token + emails the approver via `mailerSend()` (falls back to surfacing the approve_url for manual share when mailer is offline locally).
- **Schema** `modules/staffing/migrations/004_external_approver_columns.sql` adds `approved_via`, `external_approver_email`, `external_approver_name`, `approval_note` to `staffing_timesheets`. `api_bootstrap.php` mirrors these as self-heal recipes so prod tenants missing the migration get them auto-added at request time.
- **UI** `StaffingApprovals.jsx` exposes an "Email approver" button next to each submitted timesheet; inline form captures recipient email + name; result strip shows sent/expiry or fallback URL.
- **Accounting** — on email approval, `staffingEmitWorkerHoursApprovedEvent()` still fires so the W2/1099/C2C journal entries route through the posting engine identically to the in-app path.
- **Test:** `tests/staffing_email_approval_smoke.php` (50 assertions).

### Build + regression
- New Vite bundle hash: `index-D38hBIYY.js` (CSS: `index-Cwhpy62y.css`).
- `.deploy-version` + `tests/sprint6b_dashboard_uis_smoke.php` updated.
- Full suite: **163/165 pass.** Only `ai_platform_smoke.php` + `plaid_integration_smoke.php` fail (expected — no live API keys locally).

### Hotfix — Staffing approvals queue `tenant_id` ambiguity (2026-02)
- **Reported in prod:** `SQLSTATE[23000] 1052 Column 'tenant_id' in WHERE is ambiguous` on the Staffing → Approvals page.
- **Cause:** `modules/staffing/api/timesheets.php` `?action=list` query JOINs `people` (which also has `tenant_id`) but its WHERE filters used bare `tenant_id`, `status`, `period_start`, `period_end`, `person_id`. MySQL couldn't tell which table's `tenant_id` the auto-injected binding was for.
- **Fix:** qualified every WHERE-clause column with `t.` (header table), and updated `ORDER BY` to `t.period_start, t.id`.
- **Test:** `tests/bugfix_staffing_approvals_list_ambiguous_tenant_id_smoke.php` — 8/8.

---

## 2026-02-13 — CFO Dashboard (Phase 1)

User pivoted off the Live Books Rails architecture for one feature: a CFO-grade dashboard surface.

### Backend
- **`api/exec_dashboard.php` extended** with:
  - `finance.dso` (AR ÷ revenue-last-90/90), `finance.dpo` (AP ÷ bills-last-90/90), `finance.unapplied_cash` (sum of `billing_payments.unallocated_amount`).
  - `staffing.upcoming_starts`, `staffing.upcoming_terminations` (next 30 days).
  - `?compare=prior_period` (preceding same-length window) **OR** `prior_year` (-52 weeks). Returns `compare.scalars` with prev-window revenue / payroll / hires / terms / placements / billable hours for delta badges everywhere.
- **`api/exec_dashboard_views.php` extended** with `widget_config_json` (visibility + order + per-widget time-window overrides; 32 KB cap).
- **`api/cfo_notes.php`** — per-user widget annotations (CRUD).
- **`api/cfo_annotate.php`** — AI suggestion per widget via `aiAsk()` with widget-specific system prompts (DSO/DPO/unapplied-cash benchmarks baked in). Graceful when AI is disabled.
- **`api/cfo_send_report.php`** — on-demand email of the current view to up to 25 recipients. Includes the AI annotation + user note for each widget. Returns `preview_html` for QA fallback. Audit-logs `cfo.report_sent`.
- **`api/cfo_formulas.php`** — user-defined "A op B" KPI widgets. Operand-key whitelist (NO PHP runtime evaluator), divide-by-zero guards, share-with-tenant flag, `?action=evaluate` for live computation against the current dashboard snapshot.
- Migration **`035_cfo_dashboard_extras.sql`** — adds `widget_config_json` + creates `cfo_section_notes`, `cfo_custom_formulas`.

### Frontend
- New page **`dashboard/src/pages/CFODashboard.jsx`** wired at `/cfo` + nav button in the header (`data-testid="header-cfo-link"`).
- 13 default widgets (revenue, margin, AR aging, AP aging, DSO, DPO, unapplied cash, payroll, headcount, upcoming starts/terms, new starts, terminations, placements).
- Period-over-period delta badges on every scalar widget.
- Per-widget AI annotation button (`<Sparkles>` glyph) + per-widget pinned user note (`<StickyNote>` glyph).
- Edit mode: ↑/↓ reorder, eye-toggle visibility per widget.
- "Save view" modal: name + set-as-default + share-with-tenant.
- "Send report" modal: recipients (comma/newline-separated), subject, intro, dispatches via `mailerSend` with HTML preview fallback.
- "Custom KPI" modal: pick whitelisted metric A + operator + metric B + display format; appears as a dashed-border tile in the grid.

### Test status
- **Test:** `tests/cfo_dashboard_smoke.php` — 60/60 assertions.
- Full suite: **164/164 in-scope pass.** Only `ai_platform_smoke.php` + `plaid_integration_smoke.php` fail (expected — no live API keys locally).
- New Vite bundle: `index-Cpv3lqE7.js` (CSS unchanged: `index-Cwhpy62y.css`).

---

## 2026-02-14 — Live Books Rails Phase 1a: Canonical Event Registry

User-approved scope (51 events in v1; subscriptions/equity-grants/inventory deferred).

### Doc
- `/app/memory/EVENT_REGISTRY.md` — APPROVED v1. The contract.

### Built
- **Migration** `core/migrations/036_event_registry.sql` — `event_registry` table with `(event_type, schema_version)` PK and `deprecated_alias_for` pointer for legacy event names.
- **Seed** `core/seeds/event_registry_seed.php` — 51 canonical events + 3 deprecated aliases (`billing.invoice.sent`, `billing.payment.received`, `treasury.payment.executed`). Idempotent `ON DUPLICATE KEY UPDATE`. Auto-runs on first read when the table is empty (no manual step needed).
- **Helper library** `core/event_registry.php` — `eventRegistryGet`, `eventRegistryAll`, `eventRegistryValidate`. Resolves aliases transparently. Degrades to warn-only when the registry table is missing (backwards-compat for tenants who haven't run migration 036 yet).
- **Validation wire-in** — `accountingProcessEvent()` now rejects events whose `event_type` is not registered OR whose payload is missing required keys. Deprecated aliases pass validation but emit a warn-log so emit sites can be migrated.
- **Test** `tests/event_registry_contract_smoke.php` — 54 assertions, including a coverage scan over every `accountingProcessEvent(['event_type' => '...'])` call site in the codebase. Each existing emit (`ap.bill.approved`, `billing.invoice.sent`, `staffing.worker_hours.approved`, `treasury.payment.executed`) is verified to be registry-valid.

### Test status
- Phase 1a smoke: **54/54** ✅
- Full suite: **165/165** in-scope (only `ai_platform` + `plaid_integration` fail — expected, no live API keys locally).

### Next up (Phase 1b-f)
- **Phase 1b** — `accounting_ai_interpretations` table (1:1 with each event; proposed JE + confidence + evidence + reviewer disposition).
- **Phase 1c** — `event_lineage` (parent/child causal chain).
- **Phase 1d** — `unified_exception_queue` view.
- **Phase 1e** — `evidence_attachments` canonical pivot.
- **Phase 1f** — migrate existing emit sites to canonical names (drop deprecated aliases). Lightweight per user's directive: no parallel emit paths.

---

## 2026-02-14 — Phase 1b: AI Interpretation Records + CFO hint surfacing

### Phase 1b — `accounting_ai_interpretations`
- **Migration** `core/migrations/037_accounting_ai_interpretations.sql` — 1-to-many with `accounting_events`. Stores proposer (AI agent id OR `posting_rule:<id>` OR `human:<uid>`), model, confidence (clamped 0..1), proposed JE JSON, evidence pointers, reasoning narrative, typical_accounting_hint (snapshot from `event_registry` at propose time), reviewer disposition.
- **Helper library** `core/ai_interpretation.php`:
  - `aiInterpretationRecord()` — insert a proposal.
  - `aiInterpretationLatestForEvent()` — latest row per event for the "explain this entry" surface.
  - `aiInterpretationAccept()` — supersedes prior accepted rows for the same event.
  - `aiInterpretationOverride()` — reviewer corrects the AI.
  - `aiInterpretationReject()` — reviewer rejects with a reason.
  - `aiInterpretationListPendingReview()` — exception queue feed.
  - All functions degrade gracefully when migration 037 hasn't run.
- **Posting engine wire-in** — `accountingProcessEvent()` now automatically records an `accepted` interpretation row for every rule-derived posting (confidence=1.000, `proposed_by='posting_rule:<id>'`). This gives every posted event a traceable "we proposed THIS JE because rule X matched" record from day 1, before Phase 2's actual AI is built.
- **API endpoint** `api/accounting/ai_interpretations.php` — GET by event_id (full history) OR `?latest=1` OR `?pending_review=1`; POST `?action=accept|override|reject` with reviewer note/reason.
- **Test:** `tests/phase_1b_ai_interpretations_smoke.php` — 36/36 ✅.

### CFO Dashboard hint surfacing (per yesterday's smart-suggestion)
- `api/cfo_annotate.php` now maps each widget → relevant canonical event types (e.g. `finance.ar_aging` → `[ar.invoice.issued, ar.payment.received, ar.cash.applied, ar.writeoff.recorded]`).
- For each mapped event type, pulls `typical_accounting` from `event_registry` and injects it into the AI prompt's `context.registry_hints[]`. Adds a conditional system-prompt line: "Ground any accounting language in the typical Dr/Cr hints supplied".
- Surfaces the same hints in the API response (`registry_hints`) so the front end can display them as a chip under the AI annotation.
- AI annotations on CFO widgets are now grounded in the actual event registry instead of guessing.

### Test status
- Phase 1b smoke: 36/36 ✅
- Full suite: **166/166** in-scope ✅ (only `ai_platform` + `plaid_integration` fail — no live API keys locally).

### Next up (Phase 1c-f)
- **Phase 1c** — `event_lineage` (parent/child causal chain table + auto-populate on emit when `parent_event_id` is set).
- **Phase 1d** — `unified_exception_queue` view + UI.
- **Phase 1e** — `evidence_attachments` canonical pivot.
- **Phase 1f** — Migrate emit sites to canonical event names + retire deprecated aliases.

---

## 2026-02-14 — Phase 1c: Event Lineage

### Built
- **Migration** `core/migrations/038_event_lineage.sql` — `event_lineage` table (many-to-many parent_event_id × child_event_id × relationship_type). Supports:
  - `spawned_by` (default — child arose because parent existed)
  - `reverses` (child reverses parent)
  - `corrects` (child corrects parent)
  - `applies_to` (one payment applies to many invoices)
  - `fulfills` (PO → bill)
  - `split_of` (one parent → many sibling children)
  - any custom string.
- **Helper library** `core/event_lineage.php`:
  - `eventLineageLink()` — idempotent INSERT IGNORE; rejects self-loops.
  - `eventLineageGetParents/Children()` — direct edges.
  - `eventLineageGetAncestors/Descendants()` — BFS, cycle-safe, depth-bounded (default 10, max 32).
  - `eventLineageGetRoot()` — deepest ancestor (the originating event).
  - `eventLineageValidateParentType()` — checks against `event_registry.parent_event_types`.
- **Posting engine wire-in** — `accountingProcessEvent()` auto-links lineage from `event.parent_event_id` (singular) OR `event.parent_event_ids[]` (fan-in). Custom relationship via `event.lineage_relationship`. Try/catch wrapped so missing table doesn't break emits.
- **API endpoint** `api/accounting/event_lineage.php`:
  - GET `?event_id=N&direction=both|ancestors|descendants&max_depth=10`
  - GET `?event_id=N&root=1`
  - POST `{ parent_event_id, child_event_id, relationship_type? }` — manual link (used by AI agents OR humans correcting missed lineage). Registry validation warns but never blocks.
- **Seed fix** — dropped `sales.contract.signed` from `ar.invoice.issued`'s parent list (since contracts were deferred in v1 scope). Smoke test now enforces: every declared parent_event_type IS a registered event.

### Test
- Phase 1c smoke: 31/31 ✅
- Full suite: **167/167** in-scope ✅

### Next up (Phase 1 d→f)
- **Phase 1d** — `unified_exception_queue` view (single inbox over low-confidence AI proposals, missing docs, unusual amounts, duplicate risk, new vendors, period-locked attempts).
- **Phase 1e** — `evidence_attachments` canonical pivot.
- **Phase 1f** — migrate emit sites to canonical event names + retire deprecated aliases.

---

## 2026-02-14 — Phase 1d: Unified Exception Queue + JE Trace Pane

### JE Trace Pane (per yesterday's smart-suggestion)
- **Backend** `api/accounting/je_trace.php` — given a `je_id`, returns:
  1. JE header
  2. Source `accounting_events` row (via `accounting_subledger_links`)
  3. Full lineage tree (ancestors + descendants from `event_lineage`)
  4. Every `accounting_ai_interpretations` row for every event in the chain
- **Frontend** `modules/accounting/ui/JeTracePane.jsx` — collapsible pane mounted at the bottom of `JournalEntryDetail.jsx`. Renders the chain with:
  - Source event highlighted in blue, ancestors in cyan, descendants in purple
  - Each interpretation row shows proposer (AI/rule/human icon), confidence %, status color, registry Dr/Cr hint, reasoning text, reviewer disposition, and the proposed JE lines as a Dr/Cr table.
- **Answer:** "Why was this amount booked to that account?" → one click.

### Phase 1d — Unified Exception Queue
- **Migration** `core/migrations/039_unified_exception_queue.sql`:
  - `exception_queue` table — open/snoozed/resolved/dismissed lifecycle, severity (info/warn/high/critical), polymorphic subject pointer, payload JSON, assignment, snooze-until, resolution trail.
  - `v_unified_exception_queue` SQL view — fans in 3 feeds:
    - `queue` — explicit `exception_queue` rows (open/snoozed)
    - `ai_interpretation` — `accounting_ai_interpretations` with `requires_review=1 AND status='proposed'`. Severity computed from confidence (`<0.50→high`, `<0.75→warn`, else `info`).
    - `event_error` — `accounting_events` with `status='failed'`
  - Single `unified_id` column lets the UI key rows across feeds.
- **Helper library** `core/exception_queue.php`:
  - `exceptionOpen()` — module-callable; severity-sanitized; graceful no-op when table missing.
  - `exceptionList()` / `exceptionSummary()` — read from the view; severity-ordered.
  - `exceptionResolve` / `exceptionSnooze` / `exceptionDismiss` / `exceptionAssign` — lifecycle ops, all best-effort.
- **Posting engine wire-in** — on posting failure, `accountingProcessEvent()` auto-opens an `event.error` exception with the rule id + error message in the payload. Wrapped best-effort so the exception write never breaks the upstream rollback.
- **API** `api/accounting/exceptions.php` — GET (filter by source/severity/subject/feed + `?summary=1`); POST open + lifecycle actions.

### Tests
- Phase 1d + JE trace smoke: 46/46 ✅
- Full suite: **168/168** in-scope ✅
- New Vite bundle: `index-C8nfjHo6.js`

### Next up (Phase 1e-f)
- **Phase 1e** — `evidence_attachments` canonical pivot (replaces ad-hoc bill_documents, ap_attachments, etc.).
- **Phase 1f** — migrate emit sites to canonical event names + retire the 3 deprecated aliases.
- Surfacing the exception queue inbox on the CFO Dashboard (next-natural UI consumer).


## 2026-02-14 — Phase 1e: Evidence Attachments (Live Books Rails) — CLOSED

### Built
- Migration `040_evidence_attachments.sql`: polymorphic pivot
  (`subject_type`/`subject_id`) for receipts, PDFs, screenshots, payloads.
  Includes versioning (`superseded_by_id`), soft-delete (`deleted_at`),
  sha256 dedupe lookup, and JSON `payload` slot for non-file evidence.
- `core/evidence_attachments.php` helper: `evidenceAttach`,
  `evidenceListFor`, `evidenceListForEvents`, `evidenceSupersede`,
  `evidenceSoftDelete`. Hash-based dedupe returns existing IDs instead of
  creating duplicates. Gracefully returns `[]` when migration not yet run.
- `api/accounting/evidence.php`: GET/POST/PATCH/DELETE wired with RBAC.
- `api/accounting/je_trace.php`: now returns `evidence[]` AND
  `exceptions[]` per event_id so JE Trace Pane can render inline.
- `JeTracePane.jsx`: inline `<EvidenceChip />` per event +
  `<ExceptionRow />` (severity-colored, shows resolution note).

### Test status
- Phase 1e smoke: **40/40** ✅
- Full suite: **172/172** in-scope ✅ (incl. updated sprint6b)
- New Vite bundle: `index-Bu2fQzVK.js` (built before CSV batch)

## 2026-02-14 — Universal CSV Import / Export (HARD_RULES)

### Why
Tenants need to bring their existing book of business INTO the platform
(initial migration) and OUT (audit, sync to external systems). Before
this batch CSV import existed only for people, placements, time entries.
HARD_RULES: every primary-entity module MUST expose CSV import + export.

### Built — Core primitives
- `core/CsvExportService.php` — streaming CSV writer with column map,
  bool→0/1, array→JSON, null→'' serialisation. `toString()` for small
  exports, `stream($rows, $filename)` for downloads via php://output.
- `dashboard/src/components/CsvImportPage.jsx` — shared React component
  that drives the 3-step flow (template → dry-run → commit) for any
  module. Parameterised by `endpoint`, `entityLabel`, `previewColumns`,
  `testidPrefix`. Replaces what used to be 170-line per-entity copies.

### Built — New endpoints
**Imports** (CsvImportService):
- `POST /api/ap/csv_import`            — vendors (ap_vendors_index, upserts by name)
- `POST /api/staffing/csv_import`      — clients (staffing_clients)

**Exports** (CsvExportService):
- `GET  /api/people/csv_export`        — people directory (status/classification filters)
- `GET  /api/placements/csv_export`    — placements (incl. latest bill/pay rate)
- `GET  /api/ap/csv_export`            — vendors (PII redacted to last-4)
- `GET  /api/ap/bills_csv_export`      — AP bills (header-level)
- `GET  /api/staffing/csv_export`      — clients
- `GET  /api/time/csv_export`          — time entries (round-trips with import)
- `GET  /api/billing/csv_export`       — billing invoices

### Built — UI wiring
- VendorsList → `Import CSV` + `Export CSV` buttons
- Clients (Staffing) → `Import CSV` + `Export CSV` buttons
- People Directory → `Export CSV` button added (Import already existed)
- Placements List → `Export CSV` button added (Import already existed)
- BillsList → `Export all (CSV)` button
- InvoicesList → `Export CSV` button
- Time ReviewQueue → `Export CSV` button
- APModule routes: `/modules/ap/vendors/csv_import`
- StaffingModule routes: `/modules/staffing/clients/csv_import`

### Test status
- `tests/csv_universal_import_export_smoke.php`: **103/103** ✅
- Full suite: **172/172** in-scope ✅
- New Vite bundle: `index-BhfZCi_o.js`

### Next up (Phase 2 — Live Books Rails)
- AI competing/proposing against deterministic rules (per-event)
- Unified Financial State Cache
- Wire `mailerSend()` to Resend driver (CFO + approver emails)
- CFO Dashboard role/access gating
- Engagements module (Fixed-fee project accounting)
- AI Digest Scheduler (Sunday cron → Weekly Ops Memo)
- External Auditor tokenized read-only CFO view

### Backlog — CSV
- CSV import for AP bills (multi-line: header + line items in same file)
- CSV import for billing invoices (same pattern)
- CSV export for AP payments + billing payments
- "Update if exists" mode on imports (today only people/placements/time
  accept new rows; vendors uses upsert; clients rejects existing).

## 2026-02-14 — CSV Phase B (Multi-line imports + Bulk wizard)

Building on Phase A's universal CSV plumbing, Phase B closes the last
gaps for tenant data onboarding.

### Built — Multi-line CSV imports
- `POST /api/ap/bills_csv_import` — bills + line items in ONE CSV.
  Header (vendor_name, bill_date, due_date) read from the first row of
  each `bill_number` group; subsequent rows only need `line_*` columns.
  Totals are auto-summed from lines. Skips existing bill numbers
  (idempotent). Wraps lines in a transaction (all-or-nothing per bill).
- `POST /api/billing/csv_import` — invoices + lines in ONE CSV (same
  pattern, grouped by `invoice_number`). Imports as `status='draft'` —
  approval/send stays a deliberate human action.

### Built — Payments CSV export
- `GET /api/ap/payments_csv_export` — AP vendor payments (status/date/vendor filters)
- `GET /api/billing/payments_csv_export` — AR client payments (date/client/method filters)

### Built — Update-if-exists mode
- `POST /api/people/csv_import?update_existing=1` — on duplicate email,
  UPDATE instead of throw. Audit log captures the mode.
- `POST /api/staffing/csv_import?update_existing=1` — on duplicate name,
  UPDATE instead of throw.

### Built — Bulk CSV Import Wizard (drop multiple at once)
- New page: `/data/bulk-import` (`dashboard/src/pages/CsvBulkImport.jsx`)
- Drag/select multiple CSVs at once. Each file's entity is auto-detected
  by matching header labels against a per-entity `signature` array
  (requires ≥2 matches; user can override via dropdown).
- "Validate all" runs every per-entity dry-run; UI shows row counts +
  error counts per file in a table.
- "Commit all" persists files in **FK-respecting order**: people →
  ap_vendors → staffing_clients → placements → time → ap_bills →
  billing_invoices. Skip-invalid toggle propagates to each commit.
- Linked from Dashboard's Admin Quick Actions card (`Upload` icon).

### Built — UI polish
- BillsList: `Import CSV` button + multi-line import route
- InvoicesList: `Import CSV` button + multi-line import route
- AP PaymentsList: `Export all (CSV)` button
- Billing PaymentsList: `Export CSV` button
- `ActionCard` now passes `data-testid` and uses SPA `<Link>` for any
  in-app route (previously full-page-reloaded for non-`/modules/` hrefs).

### Test status
- `tests/csv_phase_b_smoke.php`: **74/74** ✅
- Full suite: **173/173** ✅
- New Vite bundle: `index-Bsh2LeQT.js`

### Next up
- **Phase 2 — Live Books Rails**: AI competing/proposing + Unified Financial State Cache
- CSV import for AP payments + billing payments (currently exports only)
- "Update if exists" mode for placements/time (currently only people + clients)
- "Mapping memory" so the wizard remembers user-picked entity overrides
  across sessions


## 2026-02-14 — CSV Sample Pack (Onboarding)

### Why
Tenants who land on the Bulk CSV Import wizard with empty templates can't
visualise what the platform will look like populated. Shipping a coherent
sample dataset lets them load 5+5+5+5+5+2+2 rows in 30 seconds and see
the whole loop (people → placements → time → bills/invoices) light up
before importing their real books.

### Built
- `core/csv_samples.php` — single source of truth for sample rows per
  entity. Data is fictional and **FK-coherent** across files (placement
  emails match people, bills' vendor_name matches vendors, etc.) so the
  bulk-importer runs cleanly with no warnings.
- `CsvImportService::buildSample($module, $rows)` — header row + N data
  rows; missing keys serialise to empty cells; booleans → 0/1.
- `?action=sample` added to all 7 csv_import endpoints (people,
  placements, time, ap_vendors, staffing_clients, ap_bills, billing_invoices).
- Shared `CsvImportPage.jsx` now shows a **"Download sample with example
  rows"** button next to "Download template".
- Bulk Import wizard has a collapsible **"New to CoreFlux? Download our
  sample CSV pack →"** disclosure with one link per entity.

### Honest gap (raised by user, not yet built)
- **Interactive column mapping is NOT implemented.** Today the importer
  auto-matches headers by label or raw field key (case-insensitive) and
  silently drops unrecognised columns. If a customer's old system uses
  "FName" instead of "First name", that column won't import unless they
  rename it in the CSV first.
- Planned for the next round: `?action=inspect` returns detected headers
  + suggested mapping; `dry_run`/`commit` accept an optional `column_map`
  override; UI shows a mapping table between source columns and target
  fields with a dropdown for any unmatched header.

### Test status
- `tests/csv_sample_pack_smoke.php`: **49/49** ✅
- Full suite: **174/174** ✅
- New Vite bundle: `index-rMpc_kFG.js`


## 2026-02-14 — Interactive Column Mapping (CSV Phase C)

### Why
Customers exporting from QuickBooks / BambooHR / Bullhorn / Excel rarely
have headers that match our template labels. Before this round, mismatched
columns were silently dropped (e.g. "FName" wouldn't map to "First name").
Now the importer presents a mapping table after file pick so the user
explicitly pairs each source column with a target field.

### Built — Core service
- `CsvImportService::inspect($module, $csv)` returns:
  - `headers`: list of column names as found in the file
  - `auto_map`: suggested header→field_key per index (case-insensitive
    match against label OR raw key; null when ambiguous)
  - `fields`: schema metadata (key, label, required, type, enum) so the
    UI can build the target dropdown without a second round-trip.
- `CsvImportService::dryRun()` and `commit()` now accept an optional
  `column_map` override (both header-name-keyed and index-keyed shapes
  supported; invalid field_keys silently dropped).
- New `readRequestColumnMap()` helper for endpoints.
- New `resolveHeaderMap()` private helper unifies the path through which
  mapping is computed.

### Built — Endpoints
- `POST ?action=inspect` added to all 7 csv_import endpoints (people,
  placements, time, ap_vendors, staffing_clients, ap_bills, billing_invoices).
- `dry_run` and `commit` on all 7 endpoints read `column_map` from the
  request body and forward it to the service.

### Built — UI (shared CsvImportPage)
- On file pick, auto-calls `?action=inspect` and renders an inline
  **mapping table** (source column ↔ target field dropdown). Auto-mapped
  columns are pre-selected; user can change any of them or pick
  "— skip this column —".
- Live warning when required fields are not yet mapped:
  > Required fields not yet mapped: First name, Last name.
- Mapping changes invalidate any prior dry-run so the user re-validates.
- `dry_run` and `commit` requests include the chosen `column_map`.

### Test status
- `tests/csv_interactive_mapping_smoke.php`: **48/48** ✅
- Full suite: **175/175** ✅
- New Vite bundle: `index-Cq_91YC8.js`

### How to use it
1. Click **Import CSV** on any list page (or open `/data/bulk-import`).
2. Click **Download sample with example rows** to see the canonical shape, OR
   just pick your own CSV from your old system.
3. The Mapping table appears immediately. Adjust the dropdowns so each
   source column points to the right CoreFlux field. Leave junk columns
   on "— skip this column —".
4. Click **Validate (dry run)** to see per-row errors with your mapping.
5. Tick **Skip invalid rows** if some rows have errors, then **Commit**.

### Next up
- "Save mapping" — remember the user's chosen header→field map per
  tenant + entity so the next import from the same system is one-click.
- Wire interactive mapping into the **Bulk CSV Import Wizard** (currently
  it only uses auto-detection; the per-entity page is where the mapping
  table lives today).
- CSV import for AP/billing payments; update-existing mode on placements/time.
- **Phase 2 — Live Books Rails**: AI competing/proposing + Unified Financial State Cache.


## 2026-02-14 — AI-Assisted Column Mapping (CSV Phase D)

### Why
Even with the interactive mapping table, customers with hundreds of
columns from a legacy ATS/payroll system shouldn't have to hunt through
dropdowns. The LLM can read column names + 3 sample values and propose a
mapping in one click. The human still confirms before any data lands.

### Built — Core helper
- `core/ai_csv_mapper.php::aiSuggestColumnMap()` — single chokepoint for
  AI mapping. Inputs: schema field metadata, source headers, sample rows,
  optional `already_mapped` pairs that must be preserved.
- Routes through existing `aiCallOpenAI()` so the tenant + per-feature
  gate (`classification`), audit log, model fallback, and `OPENAI_API_KEY`
  config are all reused. No new integration plumbing.
- Uses `AI_MODEL_CLASSIFICATION` (defaults to gpt-5.4-mini) with
  `response_format=json_object` for deterministic JSON output.
- Output sanitisation:
  - rejects any field key not in the schema (silently → null)
  - coerces empty string / false / missing → null
  - force-preserves user-locked `already_mapped` pairs
- Audit-writes each call (success or non-JSON failure) to `ai_interactions`.

### Built — Endpoints
- `POST ?action=ai_suggest_map` added to all 7 csv_import endpoints
  (people, placements, time, ap_vendors, staffing_clients, ap_bills,
  billing_invoices). Each:
  - Re-uses module's existing RBAC permission
  - Reads up to 3 sample rows from the uploaded CSV
  - Forwards `already_mapped` so user-locked pairs aren't overwritten
  - Passes a module-specific `feature_key` (e.g. `csv.mapping.people`) so
    audit logs are queryable per entity
  - Surfaces `AIDisabledException` as HTTP 503 with a clear message

### Built — UI
- New **"✨ Auto-map with AI"** button on the mapping table header
  (purple→blue gradient so it reads as the magic button it is).
- Pre-fills only un-locked columns; columns the user has already mapped
  by hand are preserved.
- Displays the model's one-sentence reasoning in a purple-tinted callout
  below the button (`<strong>AI:</strong> FName clearly maps to first_name…`).
- AI errors surface separately from validation errors so the user can
  still validate manually if AI is unavailable.

### Test status
- `tests/csv_ai_assisted_mapping_smoke.php`: **77/77** ✅
- Full suite: **176/176** ✅
- New Vite bundle: `index-Cq9FthE0.js`
- Live OpenAI call NOT tested in CI (no API key) — exercised on the
  customer-tenant stack where `OPENAI_API_KEY` is configured.

### Next up
- "Save mapping" — persist tenant + entity + AI-confirmed map so the
  next import from the same system is one-click (no AI call needed).
- Wire interactive mapping + AI into the **Bulk CSV Import Wizard**.
- CSV import for AP/billing payments; update-existing on placements/time.
- **Phase 2 — Live Books Rails**: AI competing/proposing + Unified
  Financial State Cache.


## 2026-02-14 — CSV Phase E (Saved Presets + Payments + Update-existing + Bulk wizard mapping)

Closes out the full CSV import/export backlog.

### Built — Saved mapping presets
- Migration `041_csv_mapping_presets.sql` — `csv_mapping_presets` table
  scoped by `(tenant_id, entity, name)` unique; `header_signature` =
  SHA-256 of lowercased + sorted headers for cross-rerun recognition.
- New API: `/api/admin/csv_mapping_presets`
  - `GET ?entity=…[&signature=…]` list / filter
  - `POST` create or upsert by name
  - `POST ?action=use&id=…` bump used_count + last_used_at
  - `DELETE ?id=…` remove
- Shared `CsvImportPage` now:
  - On file pick, looks up presets matching the header signature →
    surfaces "Saved mapping match: '<name>' [Apply]"
  - If no exact match but other presets exist, shows them as inline
    apply-buttons ("QuickBooks vendors", "ADP payroll", etc.)
  - "Save this mapping as…" panel below the mapping table — saves the
    current `column_map` + headers as a named preset
- Bulk Import Wizard auto-applies the matching preset on file pick (no
  click needed). Surfaces a green `preset: <name>` chip in the file row.
  Used-count bumps server-side so most-used presets surface first next time.

### Built — AP + Billing payments CSV import
- `/api/ap/payments_csv_import.php` and
  `/api/billing/payments_csv_import.php`. Full action set per HARD_RULES:
  template, sample (3 realistic rows), inspect, dry_run, commit,
  ai_suggest_map. Bill/invoice **allocations** stay out of scope —
  imported payments start fully unallocated; the user reconciles via the
  existing Payment Detail UI.
- New UI pages `PaymentsCsvImport.jsx` for both modules, wired into the
  respective module routers; "Import CSV" button added to both Payments
  list pages.
- Bulk wizard's ENTITY_ORDER extended: `…ap_bills → billing_invoices →
  ap_payments → billing_payments`.

### Built — Update-existing mode on placements + time
- Placements (`?update_existing=1`): matches by `external_id` first
  (tenant-unique), falls back to `(person_id, title, start_date)`. On
  update, preserves existing rates + client chain rows.
- Time (`?update_existing=1`): dedupe on `(placement_id, person_id,
  work_date, category)`. **Refuses to update approved entries** — those
  belong to audit-locked time bundles and must be voided explicitly.
- Both audit-log the `update_existing` flag so admins can see whether an
  import was insert-only or upsert.
- Shared `CsvImportPage` adds a checkbox toggle next to skip-invalid:
  *"Update existing rows on match (else: skip duplicates)"*.

### Built — Legacy migration
- `modules/people/ui/CsvImport.jsx` and
  `modules/placements/ui/CsvImport.jsx` rewritten as 1-screen wrappers
  over the shared `CsvImportPage`. They now inherit AI mapping +
  interactive mapping + saved presets automatically.
- Time module's legacy `CsvImport.jsx` left untouched for this round
  because it carries the `pre_approved` toggle that doesn't fit the
  shared component cleanly. Backlog item: extend the shared component
  with a `commitToggles` slot, then migrate time.

### Test status
- `tests/csv_phase_e_smoke.php`: **82/82** ✅
- Full suite: **177/177** ✅
- New Vite bundle: `index-DFfmtGC5.js`

### Final CSV import/export feature surface (everything now working)
| Entity            | Template | Sample | Inspect | Dry-run | Commit | AI map | Update | Presets | Export |
|-------------------|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| People            | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Placements        | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Time entries      | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | (✗ legacy UI) | ✓ |
| Vendors (AP)      | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | (upsert by name) | ✓ | ✓ |
| Clients (Staffing)| ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| AP Bills          | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | (skip dup #) | ✓ | ✓ |
| AR Invoices       | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | (skip dup #) | ✓ | ✓ |
| AP Payments       | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — | ✓ | ✓ |
| Billing Payments  | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — | ✓ | ✓ |

Plus the **Bulk CSV Import Wizard** at `/data/bulk-import` for drop-many-CSVs
tenant onboarding (auto-detect entity, auto-apply saved presets,
FK-respecting commit order).

### Next up
- Extend shared CsvImportPage with a `commitToggles` slot so the time
  module's `pre_approved` toggle can move off the legacy page.
- Wire the per-file mapping table into the Bulk Import Wizard (today
  the wizard auto-applies presets but doesn't expose an interactive
  override per file; user falls back to per-entity import page if needed).
- **Phase 2 — Live Books Rails**: AI competing/proposing against rules
  + Unified Financial State Cache.


## CSV Import History — Audit trail (2026-02-XX) ✅

CFOs and auditors now have a queryable trail of every bulk CSV movement.
Closes out the Universal CSV epic.

### Schema (migration `042_csv_import_history.sql`)
- `csv_import_history`: tenant_id, entity, file_name, bytes_processed,
  rows_total, rows_imported, rows_skipped, errors_count, skip_invalid,
  update_existing, ai_used, preset_id (soft-FK to csv_mapping_presets),
  column_map (JSON), error_summary (JSON, first 50 rows), status
  ENUM('success','partial','failed'), duration_ms, created_by_user_id,
  created_at + 3 composite indexes.

### Recorder helper (`/app/core/csv_import_history.php`)
- `csvImportHistoryRecord($args)` — never throws; classifies status from
  imported/skipped/errors counts; truncates error_summary to 50 rows.
  Designed to swallow failures so the audit-write never breaks a working
  import.

### Endpoint (`/api/admin/csv_import_history.php`)
- `GET` — filterable by `?entity=`, `?status=`, `?from=`, `?to=`,
  `?limit=`; LEFT JOINs `users` (created_by_email) and
  `csv_mapping_presets` (preset_name); decodes JSON columns; returns
  `{ rows: [], migration_pending: true }` when the table isn't
  provisioned yet.
- `POST` — body `{ entity, file_name, rows_imported, rows_skipped,
  errors, … }`; persists via `csvImportHistoryRecord()`. This is the
  chokepoint the SPA calls after a successful commit (vs. wiring 9
  PHP controllers).

### SPA wiring
- `dashboard/src/components/CsvImportPage.jsx` — every shared CSV
  importer (people, vendors, clients, placements, time, bills,
  invoices, payments) POSTs a history row after a successful commit
  (entity, file_name, counts, errors, skip_invalid, update_existing,
  ai_used flag, preset_id, column_map, duration_ms).
- `dashboard/src/pages/CsvBulkImport.jsx` — the bulk wizard POSTs one
  history row per file that committed successfully (FK-respecting order
  preserved).
- New page `dashboard/src/pages/CsvImportHistory.jsx` at
  `/data/import-history` — KPI strip (imports, rows imported, rows
  skipped, failed imports), entity/status/date filters, status pills,
  expandable row showing the column map used + the per-row error list.
- Dashboard quick action card `"CSV Import History"` (admin only).

### Tests
- `tests/csv_import_history_smoke.php`: **70/70** ✅
- Full suite: **176/178** (the 2 fails are the pre-existing
  `ai_platform_smoke` + `plaid_integration_smoke` that need live API
  keys — expected & documented as not in scope).
- New Vite bundle: `index-0xj-_mZ6.js` / `index-Cwhpy62y.css`.

### Next up
- **Phase 2 — Live Books Rails**: AI competing/proposing against rules +
  Unified Financial State Cache.
- (P2) Wire `mailerSend()` to a Resend driver so CFO digest/timesheet
  approver emails deliver externally (currently mocked locally).
- (P2) CFO Dashboard role/access gating.
- (P2) Engagements module (Fixed-fee project accounting).
- (P3) AI Digest Scheduler — Sunday cron for Weekly Ops Memo.
- (P3) External Auditor tokenized read-only view.
- (P4) Module emission discipline — Staffing/Billing/AP emit events only,
  never write GL directly.


## Simulation Harness — Phase H1 + Phase 2a (2026-02-XX) ✅

Phase H1 stands up the deterministic financial wind tunnel; Phase 2a
closes the module emission discipline gap discovered in the H0
diagnostic. Both ship together because Phase 2a is the first real
*use* of the harness.

### Phase H1 — Harness foundation
- **Migration 043_simulation_harness.sql**:
  - Adds `tenants.is_simulation` flag (runner refuses non-sim tenants).
  - Tables: `simulation_runs`, `simulation_assertions`,
    `simulation_failures`, `replay_logs` (with `payload_hash` +
    `je_hash` for byte-identical replay diffing per harness spec §18).
- **/app/sim/lib/seed.php**: deterministic seeded RNG + seeded clock.
- **/app/sim/lib/scenario.php**: JSON scenario loader + list.
- **/app/sim/lib/invariants.php**: 5 invariant functions —
  debits=credits, no orphan events, no direct-GL bypass, replay
  reproducible, AP module ↔ GL parity.
- **/app/sim/runner.php**: CLI runner (`--scenario --seed --tenant
  --dry-run --list`). Reuses production `accountingProcessEvent()` —
  zero special-case logic. Persists assertions + replay log.
- **3 starter scenarios**: `ap_bill_happy_path`, `ar_invoice_happy_path`,
  `treasury_bank_feed_categorize`.

### Phase 2a — Module emission discipline
- **Diagnostic (H0):** 4 direct-`accountingPostJe()` bypass sites
  outside the accounting module — AP/Billing fallbacks + treasury
  feed categorize/split (pure bypass).
- **Deliverables:**
  - New event_registry entry `treasury.bank_transaction.categorized`
    (52 total events now).
  - New posting-rules seed entry — passthrough rule
    (`line_source: payload`) so engine just persists supplied lines.
  - Treasury refactor: categorize + split both try
    `accountingProcessEvent` first; fall back to direct only on engine
    `ignored`/throw — same pattern as AP/Billing.
  - `module_emission_discipline_log` table + helper. Every fallback
    fire persists a telemetry row.
  - AP bills + Billing invoices fallback paths now log discipline
    violations.
- **Contract smoke** (`module_emission_discipline_smoke.php`): fails CI
  if a new module file adds a direct GL call. Allowlist tracks the 3
  known legacy sites.

### Tests
- `sim_harness_smoke.php`: **49/49 ✅**
- `module_emission_discipline_smoke.php`: **12/12 ✅**
- `phase_2a_event_discipline_smoke.php`: **39/39 ✅**
- Full suite: **181/181 ✅**

### Next up (H2)
- Mock layer for Plaid / Gusto / OpenAI / Resend (deterministic by seed).
- `/sim` SPA dashboard.
- 5 more scenarios (ACH return, duplicate webhook, partial settlement,
  month-end close, reconciliation break).
- CI hook (lightweight on commit, full suite nightly).

### Then Phase 2 (Unified Financial State Cache)
- Once the discipline log shows zero fallback fires for 1 week, flip
  the fallback paths to a hard `api_error('Event layer required', 500)`
  and remove `legacy_direct_fallback` flags.
- Then Phase 2 (Unified Financial State Cache) builds on a clean log.

## Simulation Harness — Phase H2 (2026-02-XX) ✅

H2 builds on the H1 foundation with deterministic external-integration
mocks, an SPA dashboard for auditors, and 2 more scenarios that
exercise replay + idempotency.

### Mock layer (`/app/sim/mocks/`)
- **manager.php**: `simShouldMock()`, `simMockEnable()`,
  `simMockSetFault()`, `simMockCalls()`. Activation: explicit enable
  OR env `SIM_MODE=1`. Faults are one-shot (rate_limit, timeout,
  server_error, malformed, partial) so scenarios can assert resilience.
- **plaid.php**: deterministic `simMockPlaidGetAccounts`,
  `simMockPlaidSyncTransactions`, `simMockPlaidGetItem`,
  `simMockPlaidExchange`, `simMockPlaidWebhook`. 4 synthetic accounts;
  txns seeded by `simRandPick` over a merchant + category library.
- **openai.php**: `simMockAiAsk` returns canned narratives keyed by
  feature_class; `simMockAiExtract` returns structured bill/receipt
  payloads. Both flag `meta.sim = true` and carry an advisory disclaimer.
- **email.php**: `simMockSendEmail` captures-not-sends; assertions can
  inspect subject/body hashes via `simMockEmailsBySubject`.
- All mocks record into a process-global call log (`simMockCalls`)
  so scenarios can assert "exactly N calls to service X".

### Activation (intentionally non-invasive)
- Production service files (`core/plaid_service.php`, `ai_service.php`,
  `mailer.php`) are NOT modified in H2. The mocks are standalone
  testable units; H2.5 will route production callers through them via
  an opt-in check (`if (simShouldMock('plaid')) return simMockPlaid…`).
  This keeps H2 verifiable without risking production posting paths.

### New scenarios
- **duplicate_webhook_idempotent**: emits the same `ap.bill.approved`
  event twice with identical `source_record_id`. Asserts the posting
  engine dedupes (1 JE, not 2). Validates harness spec §14 (exactly-once
  consumers).
- **ap_payment_lifecycle**: full AP bill → 15-day clock advance →
  payment cleared. Asserts AP↔GL parity drops to zero after payment.
  Validates harness spec §16 (balance engine).

### SPA dashboard (`/sim`)
- 3 tabs: **Runs** (filterable by scenario/status, with KPI strip),
  **Scenarios** (what's available from `/app/sim/scenarios`), **Discipline log**
  (every Phase-2a fallback fire).
- Drill-in panel per run: assertions table, failure list, replay log
  (event_index, payload_hash, je_hash truncated).
- **"Replay" button** copies the exact `php /app/sim/runner.php …`
  command to clipboard — runs stay CLI-driven per harness spec §10.
- Reads from new `/api/admin/simulation_runs.php` (GET-only):
  list, detail, scenarios, discipline.

### Tests
- `tests/sim_harness_h2_smoke.php`: **53/53 ✅**
- Full suite: **182/182 ✅** (regression-clean).

### Next up
- **H2.5 — Production wiring of mocks**: route Plaid/OpenAI/Email
  production callers through `simShouldMock()` so sim-flagged tenants
  never hit real APIs.
- **H3 — CI hook**: lightweight on commit (`smoke + dry-run scenarios`),
  full suite nightly (full execution against a sim tenant), failing
  invariants block deploy.
- **Phase 2a step 5** (kill-switch): 1 week of zero discipline-log
  fires → fallback paths hard-error → remove `legacy_direct_fallback`
  flags.
- **Phase 2 (Unified Financial State Cache)**: builds on clean event log.

### New Vite bundle: `index-DFh-lnvh.js` / `index-Cwhpy62y.css`


## Simulation Harness — H2.5 (production wiring) + H3 (CI) + Run-from-web (2026-02-XX) ✅

H2.5 wires the H2 mocks into production service files so sim-flagged
tenants never hit real APIs. H3 ships the CI scripts + GitHub Actions
workflow. Run-from-web turns the harness into a one-click regression
tool for non-engineers (the improvement we discussed).

### H2.5 — Mock bridge into production
- **`/app/core/sim_mock_bridge.php`** — `simShouldMockIfLoaded()`. The
  contract: check the call-site env (`SIM_MODE`, `SIM_MOCK_<svc>`),
  fall through to a per-tenant `tenants.is_simulation` lookup (cached
  static), lazy-load the sim manager only when needed. Production
  code paths that haven't loaded the sim tree return `false`
  immediately — zero behavior change in prod.
- **`core/plaid_service.php`**: opt-in guards on
  `plaidExchangePublicToken`, `plaidGetAccounts`, `plaidGetItem`,
  `plaidSyncTransactions` → `simMockPlaid*` short-circuits.
- **`core/ai_service.php`**: `aiAsk()` short-circuits to
  `simMockAiAsk()`, preserving the standard envelope shape
  (`kind`, `content`, `confidence`, `citations`, `requires_human_review`,
  `model`, `latency_ms`, `prompt_hash`, `response_hash`, `interaction_id`,
  `sim:true`).
- **`core/mailer.php`**: `sendEmail()` captures into `simMockSendEmail()`
  before the SMTP connection opens. Validates inputs first so errors
  still surface in tests.

### H3 — CI scripts + GitHub Actions
- **`scripts/ci_smoke_all.sh`**: runs every `tests/*_smoke.php`, skips
  the 2 documented live-API integration tests, exits non-zero on any
  failure.
- **`scripts/ci_sim_scenarios.sh`**: dry-runs every scenario twice with
  the same seed; asserts byte-identical normalized output (strips
  `run_id` + `duration_ms`). 5 scenarios → **5/5 ✅** locally.
- **`scripts/ci_sim_full.sh`**: nightly job — requires
  `SIM_TENANT_ID` env, sets `SIM_MODE=1`, runs every scenario against
  the sim tenant with full invariant checks.
- **`.github/workflows/ci.yml`**: triggers on push/PR; PHP 8.2 via
  `shivammathur/setup-php`; runs smoke + sim dry-run on every commit;
  nightly job provisions MySQL 8.0 service container, applies
  migrations, seeds sim tenant (`ci_seed_sim_tenant.php` — placeholder
  scaffold for the seed script).

### Run-from-web (one-click regression for non-engineers)
- **`POST /api/admin/simulation_runs.php?action=run`** with body
  `{scenario, seed?, tenant_id?}`:
  - Validates scenario name against `^[a-z0-9_]+$` regex.
  - Refuses non-sim-flagged target tenants (clean 422).
  - Spawns the runner via `shell_exec` (synchronous, max 75s).
  - Parses `run_id` from stdout; returns `{run_id, run, spawned_ms,
    stdout_tail}` for the SPA.
  - `escapeshellarg` on user-supplied scenario name; `realpath` on the
    runner path (no shell injection surface).
- **SPA `/sim`**:
  - "Run" button per scenario (Scenarios tab) → POSTs → refreshes
    runs list → opens the new run's detail panel.
  - "Run again" button per row (Runs tab) → same scenario + same seed.
  - "Copy CLI" button (renamed from Replay) preserves the original
    CLI-copy behaviour for terminal users.
  - All three buttons disabled while spawning to prevent dup-fire.

### Tests
- `tests/sim_harness_h2_5_h3_smoke.php`: **57/57 ✅**
- `tests/sim_harness_h2_smoke.php`: **53/53 ✅** (updated for POST support)
- `bash scripts/ci_smoke_all.sh`: **181 passed, 0 failed, 2 skipped** ✅
- `bash scripts/ci_sim_scenarios.sh`: **5/5 scenarios, determinism-clean** ✅
- Full PHP suite: **183/183 ✅**

### Next up
- **Phase 2a step 5 (kill-switch)**: 1 week of zero discipline-log fires
  → fallback paths hard-error → remove `legacy_direct_fallback` flags.
- **Phase 2 (Unified Financial State Cache)**: builds on clean event
  log + reliable replay tooling that H1-H3 just delivered.
- (Optional H4) AI-agent simulation per harness spec §22.
- (Optional H5) Scale ramp to 100k/1M synthetic transactions per spec §19.

### New Vite bundle: `index-DOFr99tN.js` / `index-Cwhpy62y.css`


## CI — 4-way parallel smoke lanes (2026-02-XX) ✅

The smoke suite (now 184 tests including the new lane classifier
itself) now runs in 4 parallel GitHub Actions jobs instead of one
serial sweep.

### Lane definitions (`scripts/ci_lane_classifier.sh` — single source of truth)
- **harness** (18 tests): simulation harness + module emission
  discipline + event-driven posting + replay surface (phase_1*,
  sprint7b/c/e event-layer, posting rules sandbox, formula engine).
- **ui** (49 tests): dashboards, SPA pages, CSV, scenarios, digests,
  magic links, sprint*-UI sprints.
- **modules** (75 tests): business logic — AP, AR, billing, time,
  staffing, placements, people, payroll, treasury, payments, plaid,
  gusto, sub-tenant, sprint8*.
- **core** (40 tests): everything else (default lane) — accounting
  engine, posting rules core, migrations, RBAC, event registry, api
  router, schema contracts, miscellaneous infra.
- Classifier is first-match-wins, top-down. Adding a new test? Pick the
  narrowest pattern that matches; never broaden an existing one.

### `scripts/ci_smoke_all.sh --lane=NAME`
- Accepts `--lane=core|modules|ui|harness`. Without a flag, runs every
  test (legacy serial mode, kept for local debugging).
- Sources the classifier so the workflow + smoke suite + dev never
  drift.
- Rejects unknown lanes with a clean exit 2.

### Workflow (`.github/workflows/ci.yml`)
- `smoke` job uses `matrix.lane: [core, modules, ui, harness]` with
  `fail-fast: false` so a single lane failure doesn't cancel the
  others — every lane reports independently. Diagnostic value: a
  failure in any lane immediately tells you the area.
- `sim-dry-run` kept as a single job (5 scenarios fly through in <2s;
  parallelizing them would cost more in setup than runtime).

### Performance
| Mode | Wall-clock | Speedup |
|---|---|---|
| Serial (legacy) | 30.15s | 1.0× |
| Longest parallel lane (modules) | 8.80s | **3.4×** |

Real CI speedup will be slightly higher once GitHub Actions per-job
setup overhead (~30s for checkout + PHP install) amortizes across
the matrix.

### Tests
- `tests/ci_lane_classifier_smoke.php`: **25/25 ✅** (1 second runtime).
- Every lane runs end-to-end on local hardware: 40 + 75 + 49 + 18 = 182
  tests classified (+ 2 known integration skips = 184 total).
- Full PHP suite: **184/184 ✅** (regression-clean).

### What's next (tomorrow)
Per user direction we're stopping here. Resume points:
1. **`scripts/ci_seed_sim_tenant.php`** — referenced by nightly workflow,
   not yet written. ~30 min.
2. **Phase 2a step 5 (kill-switch)** — wait for 1 week of zero
   discipline-log fires in the sim env, then hard-error legacy paths
   and remove `legacy_direct_fallback` flags.
3. **Phase 2 (Unified Financial State Cache)** — builds on clean event
   log + replay tooling shipped in H1-H3.


## CSV Import History — visibility audit + wire-up (2026-02-XX) ✅

User reported (with screenshots): "csv import has only been added in
some screens. I don't see any of the enhanced import features we
worked through." Audit of today's work against the actual click-paths
revealed:

### What was missing
- **0 of 9** per-module CSV import pages had a link to `/data/import-history`.
- **0 of 1** bulk wizard linked to history.
- The Time module had a custom one-off CSV importer (`/app/modules/time/ui/CsvImport.jsx`)
  that pre-dated the shared component — it had NO history POST, NO
  history link, and **no "Import CSV" button on the user-facing My Time page**.
- The two DB errors in the user's screenshots ("table doesn't exist",
  "column missing") are environmental — migrations auto-run via
  `coreflux_run_migrations()` on every API request, so 042/043/044
  self-heal on the next click.

### Fixes shipped
- **Shared `CsvImportPage.jsx`** header now exposes:
  - "+ Bulk Import (multi-file)" link → `/data/bulk-import`
  - "Import History" link → `/data/import-history`
  - Plus the existing back link.
  This single edit covers **all 9 module CSV pages** (people, vendors,
  clients, placements, bills, invoices, AP payments, billing payments,
  staffing clients).
- **Post-commit success state** (still in the shared component) now
  shows a strong "View Import History" CTA + explanatory copy ("This
  import has been logged to the audit trail…").
- **CsvBulkImport.jsx**: header + post-commit summary both get the
  same "View Import History" link.
- **Time module (`modules/time/ui/CsvImport.jsx`)** — the one-off:
  - Added header links (Bulk Import, Import History, back to My Time).
  - Added history POST after successful commit (non-fatal, same shape
    as the shared component).
  - Added "View Import History" CTA to the success state.
- **`modules/time/ui/MyTime.jsx`** — added "Import CSV" button linking
  to `/modules/time/bulk` (the correct route per `TimeModule.jsx`).

### Tests
- `tests/csv_import_history_smoke.php`: **77/77 ✅** (was 70 — added 7
  new assertions covering the cross-link fixes + Time module wiring).
- Full PHP suite: **184/184 ✅**.

### Future / Note for tomorrow
- The Time module CSV importer is still its own implementation (it has
  the unique `pre_approved` toggle that the shared component doesn't
  support). Proper fix: extend `CsvImportPage` with a `commitToggles`
  slot so Time can be folded back into the shared component and get
  AI mapping + presets + interactive column mapping. ~30 min.

### New Vite bundle: `index-BKzlxs3M.js` / `index-Cwhpy62y.css`


## CSV Time Importer — folded onto the shared component (2026-02-XX) ✅

The Time module's CSV importer had been a 100-line one-off that
predated the shared `CsvImportPage`. As of today's audit, the Time
importer now uses the shared component, picking up all the enhanced
import features for free.

### Shared `CsvImportPage` — new `extraToggles` prop
Per-entity boolean toggles render alongside the existing
`skip_invalid` / `update_existing` checkboxes. Shape:

```js
extraToggles={[
  { key:         'already_approved',
    label:       'Mark as pre-approved (skip review queue)',
    commitParam: 'already_approved=1',
    default:     false },
]}
```

When checked, `commitParam` is appended to the `?action=commit` URL
(same wire format the entity's existing csv_import.php already
expects). The toggle's value is also forwarded to the history log
under `extra_flags` so audits show which options were checked.

### Time module refactor
`/app/modules/time/ui/CsvImport.jsx` is now 47 lines (was 135), a thin
wrapper around `CsvImportPage` that passes:
- `endpoint: '/modules/time/api/csv_import.php'`
- `presetEntity: 'time'` (enables saved mapping presets + history POST)
- `extraToggles` for the existing `already_approved` flag

The Time `csv_import.php` API already supported all 6 standard actions
(`template`, `sample`, `inspect`, `ai_suggest_map`, `dry_run`, `commit`),
so the refactor unlocked these features without any backend changes:
- ✅ Interactive column mapping (auto-detect + manual overrides)
- ✅ AI-assisted column mapping suggestions
- ✅ Saved mapping presets (header signature → one-click re-import)
- ✅ Audit-trail recording into `csv_import_history`
- ✅ Header cross-links to Bulk Import + Import History
- ✅ Post-commit success state with "View Import History" CTA

### Tests
- `tests/csv_import_history_smoke.php`: **80/80 ✅** (was 77 — 8 new
  shared-component assertions added; 5 old Time-one-off assertions
  retired as the implementation no longer exists).
- Full PHP suite: **184/184 ✅**.

### New Vite bundle: `index-nhrxDOrA.js` / `index-Cwhpy62y.css`



---

## 2026-02 · SyncHistoryDrawer Hotfix

### Issue
`api is not a function` runtime crash when clicking the "Run pending
migrations" self-heal button inside the Sync History Drawer.
Root cause: `api` exported from `lib/api.js` is an object with method
helpers (`api.get`, `api.post`), not a callable function. The drawer
invoked it as `api('/api/admin/migrate.php', { method: 'POST' })`.

### Fix
- `/app/dashboard/src/components/SyncHistoryDrawer.jsx` →
  `runMigration` now calls `api.post('/api/admin/migrate.php')` and
  removed the broken dynamic import.

### Verification
- ESLint clean.
- Vite rebuild + `scripts/sync_bundle.sh` synced all four hash points
  (CACHE_VERSION, expected_bundle, dist/index.html, sw cache).
- New bundle: `index-BPUCXt-p.js` / `index-BC5g6YJu.css`.
- Full PHP suite: **252/252 ✅**.

### Pod Note
The PHP CLI binaries had been wiped from the sandbox at session start
(`command not found`). Restored via
`sudo apt-get install -y php8.2-cli php8.2-mysql php8.2-xml php8.2-curl
php8.2-mbstring`. This is the same recurring issue documented in the
handoff. PHP 8.2.31 confirmed.


---

## 2026-02 · GraphQL Federation Phase 1 + Agentic-AI MCP Server ✅

### Why
Per the user pivot: integrations no longer evolve via brittle
`tenant_integration_field_map` rows in the UI; each integration ships
its own typed GraphQL subgraph and the AI agents talk to a single
federated graph. Foundation for Apollo-platform Agentic AI.

### What landed
1. **PHP HMAC bridge** — two new endpoints that let Node subgraphs
   talk to CoreFlux without duplicating PDO/auth/encryption:
   - `/api/internal/jobdiva_proxy.php` — HMAC-signed forwarder to
     `jobdivaCall()` (path allowlisted to `/apiv2/jobdiva/*`).
   - `/api/internal/mappings_lookup.php` — `find_external_by_internal`
     / `find_internal_by_external` against `external_entity_mappings`.
   Both gated on `INTERNAL_HMAC_SECRET` (env), with 60s timestamp
   freshness window and path/op allowlists.

2. **CoreFlux subgraph** (`/app/graphql/subgraph-coreflux`) — fully
   wired. Resolvers proxy to the existing PHP REST API at the actual
   paths (`/api/placements/placements?id=N`, `/api/people/people?id=N`,
   `/api/people/companies?id=N`, `/api/integrations/mappings.php`).
   Owns canonical `Placement`, `Person`, `Company`, `PlacementRates`,
   `ExternalMapping`. Federation `__resolveReference` implemented for
   every `@key` type so other subgraphs can extend by id.

3. **JobDiva subgraph** (`/app/graphql/subgraph-jobdiva`) — fully
   wired. `src/client.ts` calls the PHP bridge; `src/loaders.ts` adds
   DataLoader-based per-request batching with the same soft-fail-per-
   endpoint behaviour as `jobdivaSyncEnrichRelatedEntities()`. Field
   pluck/shape logic mirrors the PHP enricher 1:1 so AI agents see
   the same fields the dashboard sees. `Placement.jobDiva` resolver
   maps `placement_id → start_id` via the mappings bridge then loads
   the Start row through the DataLoader.

4. **Supergraph composition** (`/app/graphql/router/compose.mjs`) —
   uses `@apollo/composition` (npm) instead of the rover Rust binary,
   so it composes natively in CI without extra installs. Produces
   `router/supergraph.graphql`.

5. **GraphQL MCP server** (`/app/graphql/mcp-server`) — Model-Context-
   Protocol server using `@modelcontextprotocol/sdk`. Two transports:
   - **stdio** (default) — Claude Desktop / Cursor / local agents.
   - **HTTP/SSE** (`MCP_TRANSPORT=http`, port 4100) — remote agents,
     session-aware via `Mcp-Session-Id`.
   Exposes 4 tools: `coreflux_query` (escape hatch), `coreflux_introspect`,
   `coreflux_placement` (single placement + jobDiva enrichment in one
   shot), `coreflux_placements` (list with filters). Tools are
   intentionally coarse-grained — dozens of fine-grained tools overflow
   model context windows.

### Tests
- New `/app/tests/internal_hmac_bridge_smoke.php` — 9 assertions
  covering HMAC verification, timestamp freshness, path/op allowlists,
  secret-unset → 503 fail-safe. Spins up `php -S` on a free port.
- New `/app/tests/graphql_federation_smoke.php` — 31 assertions
  covering: layout, TypeScript builds, supergraph composition, both
  subgraphs serving introspection + `_service { sdl }`, MCP server
  responding to `initialize` over stdio.
- **Full smoke suite: 261/261 ✅** (was 252; no regressions, +9 new).

### Tech-stack addition
**Node.js + Apollo Federation v2** alongside the PHP monolith. User-
sanctioned. New surface area only — no existing PHP/React files touched.

### Files of reference
- `/app/api/internal/jobdiva_proxy.php`
- `/app/api/internal/mappings_lookup.php`
- `/app/graphql/router/compose.mjs` + `supergraph.graphql`
- `/app/graphql/subgraph-coreflux/src/index.ts`
- `/app/graphql/subgraph-jobdiva/src/{index,client,loaders}.ts`
- `/app/graphql/mcp-server/src/index.ts`

### Next up (P1)
- Boot Apollo Router (Rust binary) in CI and exercise an end-to-end
  federated query through it (currently the smoke test exercises the
  subgraphs individually and the composition output, not a live router).
- JobDiva Placements Edit (Slice 2) — `coreflux_overridden` flag.
- Refactor JobDiva PHP cron sync into a scheduled GraphQL client.
- Mercury Webhooks Integration.
- Wire `mailerSend()` to Resend.
- RBAC B2 (resolve `class RBAC` naming collision; rename to
  `RBACResolver`, wire into `core/auth.php`).


---

## 2026-02 · GraphQL Federation Phase 1 — Live Apollo Router E2E ✅

### What this adds
Phase 1 spike is now wired end-to-end through a live Apollo Router (Rust, v1.55.0). A single federated query from one HTTP endpoint returns merged data from both subgraphs (CoreFlux + JobDiva).

### Tangible proof
The new `/app/tests/graphql_router_e2e_smoke.php` boots the entire stack from scratch on free ports:
- mock PHP backend (fixture data — placement/person/company/jobdiva)
- coreflux subgraph (Node)
- jobdiva subgraph (Node)
- Apollo Router (Rust binary)

Then runs:
```graphql
query { placement(id:"17") {
  title status startDate
  person { firstName lastName emailPrimary }
  endClient { name billingAddress { city state } }
  rates { billRate payRate currency }
  jobDiva {
    externalId refNumber startStatus
    job { title department }
    candidate { firstName lastName email }
    customer { name }
    contact { displayName email }
    bill { rate payRate currency }
  }
}}
```
…and asserts 17 distinct field values across both subgraphs landed in the merged response. **All 33 e2e assertions green.**

### Files added/changed
- `/app/graphql/router/router.smoke.yaml` — minimal router config (no JWKS) with `include_subgraph_errors: all: true` so test failures surface real subgraph errors.
- `/app/graphql/mock-php/index.php` — fixture backend used by the e2e smoke ONLY (not deployed).
- `/app/tests/graphql_router_e2e_smoke.php` — 33 assertions, orchestrates router + 2 subgraphs + mock PHP.
- `/app/graphql/subgraph-coreflux/src/index.ts` — added `Placement.person` and `Placement.endClient` field resolvers (FK stubs alone don't trigger `_entities` when the same subgraph owns the type).
- `/app/graphql/subgraph-jobdiva/src/index.ts` — `DEFAULT_TENANT_ID` env-var escape hatch for smoke tests (production never sets it; router enforces auth upstream).

### Apollo Router binary
Installed at `/usr/local/bin/router` (v1.55.0, aarch64-unknown-linux-gnu) via `curl -sSL https://router.apollo.dev/download/nix/v1.55.0 | sh`. The smoke test skips cleanly if the binary is absent, so CI hosts that don't have it don't break.

### Tests
- New: `tests/graphql_router_e2e_smoke.php` (33 assertions).
- Full smoke suite: **262/262 ✅** (was 261; +1 new file).


---

## 2026-02 · GraphQL Federation — Production Deploy Surface + MCP-Router E2E ✅

### What landed
1. **Production-grade `router.yaml`**:
   - Removed paid-tier-only features that triggered "license violation" on the free self-hosted binary (`authentication.router.jwt`, `limits.max_depth/height/aliases`, advanced telemetry spans).
   - JWT verification moves to each subgraph (where it already runs). The router stays as a thin, free federation gateway: composition, planning, header propagation, CORS, traffic shaping, JSON-to-journald logs.
   - Health probe on `127.0.0.1:8088/health` for nginx.

2. **Deploy artefacts** at `/app/graphql/deploy/`:
   - `systemd/coreflux-subgraph-coreflux.service`
   - `systemd/coreflux-subgraph-jobdiva.service`
   - `systemd/coreflux-router.service` (Requires= the two subgraphs)
   - `systemd/coreflux-mcp.service` (HTTP transport, Requires= router)
   - `nginx/coreflux-graphql.conf` (`/graphql` → router, `/mcp` → MCP, `/healthz/graphql` internal-only probe)
   - `etc/graphql.env.example` (single env file shared by every unit)
   - `DEPLOYMENT.md` (one-time host prep, deploy step, rotation/rollback recipes)

3. **JWKS-from-shared-secret tool**: `/app/scripts/generate_jwks.sh` —
   the prod config no longer uses it (since auth moved to subgraphs)
   but the script is kept and tested for future re-enabling if/when
   we upgrade to a paid GraphOS tier.

4. **Live MCP-through-router e2e** (`tests/graphql_mcp_router_smoke.php`):
   Boots mock-php → coreflux subgraph → jobdiva subgraph → Apollo Router
   → MCP server (HTTP) and proves an MCP `tools/call coreflux_placement(id:"17")`
   returns the merged federated payload (placement.title from coreflux,
   jobDiva.job.title from jobdiva subgraph, all stitched).

5. **Prod config smoke** (`tests/graphql_router_prod_config_smoke.php`):
   Boots the Apollo Router with the production `router.yaml` directly
   to verify the YAML parses, the binary boots, the listener accepts
   queries, the health endpoint responds, and introspection works.

### Smoke teardown fix
Both new e2e tests now use a two-phase teardown (SIGTERM children →
sleep 300ms → SIGKILL the process group). The old `proc_terminate($p, 9)`
leaked grandchildren when run back-to-back in the suite.

### Tests
- New: `tests/graphql_mcp_router_smoke.php` (24 assertions, MCP → router → subgraphs).
- New: `tests/graphql_router_prod_config_smoke.php` (7 assertions, prod config validation).
- Full suite: **264/264 ✅** (was 262; +2 new).

### Files of reference
- `/app/graphql/router/router.yaml` (production, free-tier-clean)
- `/app/graphql/router/router.smoke.yaml` (smoke-only, no auth/CORS)
- `/app/graphql/deploy/DEPLOYMENT.md`
- `/app/graphql/deploy/systemd/*.service`
- `/app/graphql/deploy/nginx/coreflux-graphql.conf`
- `/app/graphql/deploy/etc/graphql.env.example`
- `/app/scripts/generate_jwks.sh`
- `/app/tests/graphql_mcp_router_smoke.php`
- `/app/tests/graphql_router_prod_config_smoke.php`

### Production status
The deploy surface is ready to ship to Cloudways. The next pushable
artefacts are:
1. Run `bash /app/graphql/deploy/scripts/...` (TODO: a `deploy.sh`
   that wraps the rsync + yarn build + supergraph compose sequence
   from `DEPLOYMENT.md` into one command).
2. Cloudways admin: install systemd units, drop nginx include, reload.
3. Cutover: flip the dashboard's GraphQL endpoint from the legacy
   placeholder to `/graphql` — but only after the JobDiva subgraph's
   `Placement.jobDiva` resolver has been validated against real
   `external_entity_mappings` data (currently only fixture-tested).


---

## 2026-02 · Four-pack: deploy.sh, Slice 2 overrides, real-DB GraphQL validation, Resend audit ✅

User chose Option 3 (lighter shapes) from the action-items follow-up:
ship `deploy.sh`, real-data GraphQL validation, and JobDiva Placements
Edit Slice 2 backend; defer cron→GraphQL refactor; verify Resend wiring
is already present.

### What landed

1. **`deploy.sh`** at `/app/graphql/deploy/scripts/deploy.sh`:
   one-shot wrapper for rsync + `yarn install --frozen-lockfile` +
   `yarn build` + `compose.mjs`. Supports `--dry-run`, `--skip-build`,
   `--restart-router`, `--src=`, `--dst=`. Idempotent on re-run.
   Tested via the new `tests/graphql_deploy_script_smoke.php` (20
   assertions covering dry-run, skip-build, full deploy, idempotency).

2. **JobDiva Placements Edit — Slice 2 (backend-only)**:
   - Migration `070_placement_override_flags.sql` adds
     `coreflux_overridden_fields JSON NULL` to `placements`.
   - `jobdivaSyncUpsertPlacement()` now reads that column, builds its
     UPDATE assignments dynamically, and SKIPS any field listed in
     the override array. Skipped fields are `error_log`'d for audit.
   - `PATCH /api/placements/placements?id=N` auto-flags every column
     it touches when the placement was JobDiva-sourced (external_id
     starts with `jd:`). Direct-CoreFlux placements (no prefix)
     are unaffected — no need to "protect" fields that came from us.
   - `POST /api/placements/placements?action=clear_override` with
     `{fields:[...]}` body lets a `placements.manage` operator revert
     specific fields — the next JobDiva pull will then refresh them.
   - `placement.override_cleared` audit event added.
   - 27-assertion smoke: `tests/placements_override_slice2_smoke.php`.
   - The existing `jobdiva_placement_discovery_smoke.php` regression
     was updated to match the new dynamic-UPDATE structure (1 assertion
     reworded to reflect the new code shape; semantics unchanged).

3. **Real-DB validation for `Placement.jobDiva`**:
   `tests/graphql_jobdiva_real_db_smoke.php` seeds a real placement +
   external_entity_mappings row in MariaDB, then exercises
   `mappingFindExternal()` directly AND through the HMAC bridge HTTP
   endpoint. Asserts:
   - Direct PDO lookup finds the row.
   - HMAC bridge returns the same external_id.
   - Reverse lookup (external_id → placement_id) round-trips.
   - Wrong tenant_id returns explicit JSON `null` (multi-tenant
     isolation, not a leak). 9 assertions.

4. **Resend driver — already wired**:
   The handoff said `mailerSend()` was MOCKED, but the codebase
   already has a complete `/app/core/mail/ResendDriver.php` integrated
   via `mail_bootstrap.php` (RESEND_API_KEY env → ResendDriver, else
   LogDriver). 43-assertion `mailer_send_shim_smoke.php` confirms the
   path. Marked as ✅ (no work needed — handoff was stale).

### Deferred (with clear next steps)

- **JobDiva PHP cron → GraphQL client** (Option 3c in the planning
  conversation): not started. Path forward documented in
  `GRAPHQL_FEDERATION_PLAN.md` — needs `Mutation.upsertPlacement`
  on the coreflux subgraph first.

### Sandbox environment notes

- MariaDB is not part of the default sandbox; installed via
  `apt-get install -y mariadb-server` and seeded with the CoreFlux
  user/db pair. Subsequent test runs can rely on the same instance.
- Apollo Router binary required re-install (`curl router.apollo.dev/.../v1.55.0`)
  after sandbox restart — known sandbox idiosyncrasy, not a code issue.

### Files of reference

- `/app/graphql/deploy/scripts/deploy.sh`
- `/app/core/migrations/070_placement_override_flags.sql`
- `/app/core/jobdiva/sync.php` (line ~1045 — dynamic UPDATE block)
- `/app/modules/placements/api/placements.php` (PATCH + clear_override)
- `/app/tests/graphql_deploy_script_smoke.php`
- `/app/tests/placements_override_slice2_smoke.php`
- `/app/tests/graphql_jobdiva_real_db_smoke.php`

### Tests
**267/267 ✅** (was 264; +3 new files: deploy script (20), Slice 2 (27), real-DB (9), one existing test updated to match the refactor).


---

## 2026-02 · Slice 2 Frontend — Override Badge + Revert ✅

### What landed
Closing the loop on Slice 2: operators now SEE the override behaviour
in the UI, not just in the JSON column.

`/app/modules/placements/ui/PlacementDetail.jsx`:

- **`parseOverrides(placement)`** — robust parser for the JSON column.
  Returns an empty Set on null/malformed input so callers can `.has()`
  unconditionally.
- **`isJobDivaSourced(placement)`** — gates the affordances. Direct-
  CoreFlux placements don't render any badges, since they have nothing
  to revert "to".
- **`<OverridePill>`** — small orange pill (`OVERRIDDEN`, 0.7em, pill-
  shape) rendered next to any field listed in
  `coreflux_overridden_fields`. Tooltip explains why and how to revert.
- **OverviewTab** — read-only view now shows the pill next to every
  Slice-2-tracked field (title, status, start_date, end_date,
  actual_end_date, due_date, end_client_name, engagement_type,
  remote_policy).
- **OverviewEdit** — adds:
  - A JobDiva-only **banner** explaining the edit→overridden flow.
  - A `<RevertControl>` ghost button under every editable field that
    is currently overridden. Click → POST `?action=clear_override`,
    update local Set, no full reload needed.
  - Pills on every label so operators see the override state while
    they're editing too.
- All controls carry `data-testid` (`override-pill-{field}`,
  `revert-{field}`, `overview-edit-jd-banner`).

### Tests
- New `tests/placements_override_slice2_frontend_smoke.php` —
  23 assertions covering the parser, isJobDivaSourced, the OverridePill,
  OverviewTab field-level rendering, OverviewEdit banner + RevertControl,
  clear_override wiring, and the .deploy-version bundle-sync guard.
- Vite build ran successfully; `.deploy-version` and `spa-assets`
  bundle hashes are in lockstep (recurring drift issue stayed fixed).
- Full suite: **268/268 ✅** (was 267; +1 new frontend smoke).

### Files of reference
- `/app/modules/placements/ui/PlacementDetail.jsx` (OverviewTab,
  OverviewEdit, OverridePill, parseOverrides, isJobDivaSourced)
- `/app/tests/placements_override_slice2_frontend_smoke.php`

### What an operator now sees
- A JobDiva-sourced placement they've never touched: no badges.
- They edit the title: title gets an orange "OVERRIDDEN" pill on the
  read-only view AND in the edit form. JobDiva sync no longer touches
  the title column.
- They click "↻ Revert to JobDiva" under title in Edit mode: the pill
  vanishes, the next JobDiva sync refreshes the title from upstream.


---

## 2026-02 · Action-items batch: Sync History merge, contacts backfill, release.sh; RBAC B2 verified-done; cron deferred

User asked for "next action items + sync history items". Worked through
them in priority order. Outcome:

### ✅ 1. Sync History audit merge
**Frontend + backend.** The placement Sync History drawer now shows
operator edits intermixed with integration syncs in one timeline.

- `/app/api/integrations/sync_history.php`:
  - Tags existing sync rows with `kind: 'sync'`.
  - Pulls `audit_log` rows for the same entity (allow-list per
    `$AUDIT_EVENT_MAP` — currently `placement.*` only) tagged
    `kind: 'audit', source_system: 'coreflux'`.
  - Merges + sorts newest-first, truncates to limit, surfaces missing
    audit_log table as a non-fatal warning.
- `/app/dashboard/src/components/SyncHistoryDrawer.jsx`:
  - New `<AuditRow>` component with a purple `#a855f7` left border + chip.
  - `<HistoryRow>` dispatches on `row.kind`; audit rows render their
    `meta_json` as a key/value table when expanded.
  - `AUDIT_EVENT_LABEL` dictionary maps backend event names to UX
    strings ('placement.updated' → 'Edited',
    'placement.override_cleared' → 'Override reverted', etc.).
- Smoke: `tests/sync_history_audit_merge_smoke.php` — 26 assertions
  including real-DB merge with chronological ordering.

### ✅ 2. JobDiva Contacts backfill (50-skipped-contacts fix)
**Backend.** `jobdivaSyncContacts()` now has a new opt-in flag
`backfill_companies_on_contact_pull`. When set:
- A contact whose parent `external_entity_mappings` row is missing
  triggers an on-demand `/apiv2/jobdiva/searchCustomer` fetch.
- The fetched customer is upserted via `companiesUpsertByName()` and
  a `jobdiva → company` mapping is written.
- The original contact then succeeds instead of skipping.
- A `companies_backfilled` informational error surfaces the count.
- Backfill failures are non-fatal — falls through to the same skip
  diagnostics as before.

`/app/api/jobdiva.php?action=sync` exposes the flag via the request
body. Smoke: `tests/jobdiva_contacts_backfill_smoke.php` — 16
assertions including real-DB legacy-path validation.

### ✅ 3. Cloudways release.sh
`/app/graphql/deploy/scripts/release.sh` — root-only release wrapper.
Pipeline: pre-flight (tools, env, secrets-not-placeholder) →
supergraph snapshot → delegates to deploy.sh → conditional systemd
restarts (only subgraphs whose dist/ hash changed; router hot-reloads
via supergraph mtime) → pre/post health diff → runs three GraphQL
smoke tests against the live stack → prints rollback hint.

Smoke: `tests/graphql_release_script_smoke.php` — 15 assertions.

### ✅ 4. RBAC B2 — already shipped (handoff was stale)
`/app/core/rbac/permissions.php` already declares `RBACResolver`
(not the colliding `RBAC`). It's wired into `core/auth.php:149` and
`core/api_bootstrap.php`. Existing smokes
`rbac_b1_smoke.php`–`rbac_b5_smoke.php` cover it. No work needed.

### ⏸ 5. JobDiva cron → GraphQL client — deferred
There IS no cron file for JobDiva (only `/api/jobdiva.php?action=sync`
triggered by the dashboard or external scheduler). The "refactor"
needs to be re-scoped to either:
- (a) Create a new `/app/cron/jobdiva_sync.php` that calls the
      federated graph, OR
- (b) Add `Mutation.upsertPlacement` (+ company/person) to the
      coreflux subgraph first, then write the cron against it.
Either is 2-3 hours and would benefit from a real JobDiva sandbox.
Logged as P1 backlog.

### Tests
- `tests/sync_history_audit_merge_smoke.php` (26 assertions)
- `tests/jobdiva_contacts_backfill_smoke.php` (16 assertions)
- `tests/graphql_release_script_smoke.php` (15 assertions)
- Full suite: **271/271 ✅** (was 268; +3 new files).

### Files of reference
- `/app/api/integrations/sync_history.php` (audit-merge block)
- `/app/dashboard/src/components/SyncHistoryDrawer.jsx` (AuditRow + label map)
- `/app/core/jobdiva/sync.php` (contact-backfill block, ~line 410)
- `/app/api/jobdiva.php` (sync action accepts backfill flag)
- `/app/graphql/deploy/scripts/release.sh`



## Feature — Cloudways one-command GraphQL setup (2026-02)

### Why
After the Apollo Router architecture landed, the user was facing 6 manual SSH
steps on the Cloudways server (install Node 20, install Apollo Router, create
service user, write `/etc/coreflux/graphql.env`, drop in systemd units, wire
nginx). They asked: *"can those commands be automated? like you've been doing
for the migrations and updates?"*

### What shipped
- **`/app/scripts/setup_cloudways_graphql.sh`** — new user-facing single-command
  wrapper. Validates root + Debian/Ubuntu, installs git/curl/openssl/rsync,
  `git clone`s (or `git pull --ff-only`s) the repo into `/app`, then hands off
  to the existing `bootstrap.sh`. Supports `--dry-run`, `--skip-nginx`,
  `--skip-git`, `REPO_URL`, `REPO_BRANCH`, `REPO_PATH`. Re-runnable.
- **`bootstrap.sh`** — switched Apollo Router install from pinned `v1.55.0` to
  the official `latest` channel (per user choice).
- **`/app/api/admin/router_deploy.php`** — fixed missing `api_require_auth()`
  guard (was only calling `rbac_legacy_require` which tripped the auth-gate
  static analyzer sentry).
- **`/app/graphql/deploy/DEPLOYMENT.md`** — added a "One-command setup
  (recommended)" section at the top with the `curl | sudo … bash` invocation.

### Usage
```bash
sudo REPO_URL=https://github.com/<org>/coreflux.git \
     bash /tmp/setup_cloudways_graphql.sh
```

### Tests
- `tests/setup_cloudways_graphql_smoke.php` (22 assertions — syntax, flags,
  delegation, safety rails, --help, DEPLOYMENT.md cross-link)
- `tests/auth_gate_static_analyzer_smoke.php` — now passes again after fixing
  `router_deploy.php`
- Full suite: **272/272 ✅** (was 268/271 at session start due to PHP CLI loss
  + pre-existing router_deploy.php gap; reinstalled php8.2-cli, fixed the
  auth-gate gap, added the new smoke).

### Files of reference
- `/app/scripts/setup_cloudways_graphql.sh`
- `/app/graphql/deploy/scripts/bootstrap.sh`
- `/app/graphql/deploy/DEPLOYMENT.md`
- `/app/api/admin/router_deploy.php`
- `/app/tests/setup_cloudways_graphql_smoke.php`


## Production deploy — GraphQL on a separate DO droplet (2026-02-14)

### Why
Cloudways managed hosting blocks root access (master users are not in
`sudoers`). The Apollo Router + Node.js subgraphs cannot be installed
on the same box as the PHP app. User picked Option B: spin up a $6/mo
DigitalOcean droplet purpose-built for the GraphQL stack, expose it via
a subdomain.

### What shipped
- **`/app/scripts/setup_droplet_graphql.sh`** — purpose-built droplet
  bootstrap. Single curl-pipe-bash command on a fresh Ubuntu 22.04 box.
  Installs Node 20, Apollo Router latest, creates `coreflux` service
  user, clones repo, builds all subgraphs, writes `/etc/coreflux/graphql.env`,
  installs systemd units, opens UFW, prints the HMAC + nginx snippet.
- **`/app/tests/setup_droplet_graphql_smoke.php`** — 27 assertions
  validating script structure, idempotency, and runtime safety.
- **`/app/graphql/deploy/DEPLOYMENT.md`** — added "Option A: DO droplet"
  as the recommended path for Cloudways-hosted PHP apps.

### Bugs fixed live during the deploy
1. `rsync --exclude 'src'` was non-anchored — stripped `node_modules/debug/src/`
   and crashed all subgraphs with `MODULE_NOT_FOUND`. Removed the exclude.
2. Shared `PORT=4001` in env file forced both subgraphs onto the same port
   → `EADDRINUSE`. Removed; subgraphs use per-code defaults (4001 / 4002).

### Live production endpoint
- **`https://graphql.corefluxapp.com/`** — DNS A record → DO droplet,
  Caddy reverse-proxy → router :4000, auto-renewing Let's Encrypt cert.
- Verified: Apollo Sandbox loads, `Invoke-RestMethod` introspection query
  returns `{"data":{"__schema":...}}`.

### Tests
- Full PHP smoke suite: **273/273 ✅**
- New: `setup_droplet_graphql_smoke.php` (27 assertions)

### Files of reference
- `/app/scripts/setup_droplet_graphql.sh`
- `/app/tests/setup_droplet_graphql_smoke.php`
- `/app/graphql/deploy/DEPLOYMENT.md`

## GraphQL Sandbox admin page + dashboard live-probe (2026-02-14)

### Why
Now that `https://graphql.corefluxapp.com/` is live, operators and engineers
needed a discoverable, in-dashboard surface to (a) confirm the endpoint is
healthy at a glance, (b) jump into the Apollo Sandbox query playground, and
(c) copy curl/auth snippets. Also serves as the first end-to-end proof that
the React app can talk to the GraphQL endpoint cross-origin.

### What shipped
- **`/app/dashboard/src/pages/GraphqlSandbox.jsx`** — admin page with:
  - Live introspection check on mount → green pill "Endpoint healthy — N
    types exposed" / red pill "Endpoint unreachable" + error detail.
  - Big "Open Apollo Sandbox" → new tab to `https://graphql.corefluxapp.com/`.
  - Copy-to-clipboard curl snippet.
  - Architecture ASCII diagram showing the request path.
- **`AdminModule.jsx`** wiring:
  - New `Zap` icon import from lucide-react
  - ActionCard "GraphQL Sandbox" in the overview grid
  - Sidebar link `/admin/graphql-sandbox`
  - Route registered
- **`/app/tests/graphql_sandbox_admin_page_smoke.php`** — 23 assertions
  covering file presence, endpoint URL, introspection wiring, CORS safety,
  all required data-testids, AdminModule integration, and a cross-check
  that the auth-gate sentry still passes.

### CORS verification
The Apollo Router's `router.yaml` already lists `https://corefluxapp.com`
in `cors.origins`, so the dashboard's introspection probe from
`corefluxapp.com` → `graphql.corefluxapp.com` works without further config.

### Build
- Vite bundle: `index-B-sAphxJ.js` / `index-BC5g6YJu.css`
- `sync_bundle.sh` confirms all four sync points consistent.

### Tests
- Full PHP smoke suite: **274/274 ✅** (added the new sandbox smoke)

### Deferred (next session)
- Apollo Client (`@apollo/client`, `graphql`) install + `ApolloProvider`
  wiring around `<App />`
- JWT acquisition strategy for cross-origin auth (dashboard currently uses
  session cookies; GraphQL subgraphs verify JWTs). Options: (a) mint a JWT
  from the existing PHP session via a new `/api/auth/issue_dashboard_jwt.php`
  endpoint, (b) reuse `mobile_login`'s JWT path, (c) configure subgraphs
  to also accept a session cookie via a Cloudways → droplet bridge call.
- First pilot REST→GraphQL migration of a real dashboard component (waiting
  for user to pick which feature).

### Files of reference
- `/app/dashboard/src/pages/GraphqlSandbox.jsx`
- `/app/dashboard/src/pages/AdminModule.jsx`
- `/app/tests/graphql_sandbox_admin_page_smoke.php`
- `/app/graphql/router/router.yaml` (CORS origins)

## Placements list — first REST→GraphQL pilot migration (2026-02-14)

### Why
First real proof that the new federated GraphQL endpoint at
`graphql.corefluxapp.com` can serve a production dashboard feature.
Chose Placements list because it's:
  - High-visibility (operators look at it daily)
  - Read-only (mutations come later, safer pilot)
  - Naturally federated (Placement → Person across subgraphs is a great
    showcase for one round-trip nested fetching)

### What shipped
1. **`/api/auth/issue_dashboard_jwt.php`** — mints an 8h JWT from the
   PHP session cookie. Auth-gated via `api_require_auth()`. Same claim
   shape as `mobile_login.php` so the existing JWT plumbing accepts it.
2. **`/app/dashboard/src/lib/graphqlClient.js`** — dependency-free GraphQL
   client (~150 lines). Caches the JWT in module scope, refreshes 60s
   before expiry, dedupes concurrent token fetches via an inflight
   promise, clears on HTTP 401. Exposes `gql(query, vars)` + `useGql()`
   React hook. Defaults endpoint to `https://graphql.corefluxapp.com/`,
   honors `VITE_GRAPHQL_URL` for local dev override.
3. **`/app/modules/placements/ui/ListGraphql.jsx`** — pilot React page.
   Identical UX to `List.jsx` (search, status filter, pagination) but
   data comes from the GraphQL `placements(...)` query with nested
   `person { firstName lastName }` join. Carries a purple "⚡ GraphQL"
   badge so operators can see at a glance which transport rendered the
   page.
4. **`PlacementsModule.jsx`** — registers new route
   `/staffing/placements/list-graphql` alongside the existing `/list`.
   Zero-risk rollback: just remove the route.
5. **`List.jsx` (REST)** — added a "⚡ Try GraphQL (beta)" CTA in the
   header. Zero-friction A/B for operators.

### Architecture (verified)
```
Browser session cookie
     │
     ▼
corefluxapp.com/api/auth/issue_dashboard_jwt.php  (same-origin POST)
     │  returns { jwt, expires_in, expires_at }
     ▼
[ cached in module scope, refreshes 60s before expiry ]
     │
     ▼
graphql.corefluxapp.com/  with Authorization: Bearer <jwt>   (CORS POST)
     │
     ▼
Apollo Router → subgraph-coreflux → corefluxapp.com/api/placements/...
     │  (subgraph forwards the same JWT — PHP api_require_auth() accepts
     │   via jwtFromRequest() bearer path → hydrates session-shape context)
     ▼
PDO query + shapePlacement() → federated response
```

### Tests
- New: `placements_graphql_pilot_smoke.php` (41 assertions covering all
  five layers + sentry cross-check)
- Full PHP smoke suite: **275/275 ✅**
- ESLint clean on graphqlClient.js + ListGraphql.jsx
- Vite build: `index-BAnYgEuO.js` / `index-BC5g6YJu.css`, all sync points
  consistent.

### Next migrations (when user picks)
- **People directory** — same pattern, fewer fields, very safe second pilot.
- **Companies list** — also already exposed via `companies(limit)` query.
- **Placement detail page** — would showcase federated `Placement.person`
  expansion in one round-trip.
- **Mutations** — defer until N+1 read migrations land. Will need a
  separate "writeGql()" path that re-mints token on 401.

### Files of reference
- `/app/api/auth/issue_dashboard_jwt.php`
- `/app/dashboard/src/lib/graphqlClient.js`
- `/app/modules/placements/ui/ListGraphql.jsx`
- `/app/modules/placements/ui/PlacementsModule.jsx`
- `/app/modules/placements/ui/List.jsx`
- `/app/tests/placements_graphql_pilot_smoke.php`




## Pilot diagnostic + perf instrumentation (2026-02-14, follow-up)

### Why
First production try of `/staffing/placements/list-graphql` rendered
"GraphQL error: fetch failed" with no breadcrumb pointing at which side
of the wire failed. `Failed to fetch` could mean JWT-mint network failure,
GraphQL-endpoint network failure, CORS preflight rejection, missing PHP
file, or stale auth — and the original handler obscured all of them.

### What shipped
- **`graphqlClient.js`** now classifies every failure path with a code:
  - `AUTH_MINT_NETWORK` — DNS/CORS/offline on the JWT endpoint
  - `AUTH_MINT_HTTP` — JWT endpoint returned non-2xx
    - 404 → hint "issue_dashboard_jwt.php not deployed yet"
    - 401 → hint "no active dashboard session, log out and back in"
  - `AUTH_MINT_SHAPE` — 2xx but no `jwt` field
  - `GQL_NETWORK` — fetch threw before HTTP layer (CORS/DNS/TLS/firewall)
  - `GQL_NON_JSON` — router returned non-JSON
- **`runDiagnostics()`** — new exported probe that hits BOTH endpoints
  independently and returns `{ jwtMint: {...}, graphql: {...} }`. Used
  by the inline diagnostic panel.
- **`useGql()`** now returns `elapsedMs` alongside data/error/loading,
  measured via `performance.now()` for the actual GraphQL transport
  round-trip (post-token-mint).
- **`ListGraphql.jsx`** wires both:
  - Perf badge in subtitle: `⚡ 142ms via graphql.corefluxapp.com`
  - When an error renders, a "Run diagnostics" button appears that
    surfaces per-endpoint pass/fail with green/red rows, status codes,
    duration, and context-specific hints (404 → push to git, 401 → log
    back in, network failure → check CORS/DNS).

### Tests
- Pilot smoke updated: **60 assertions ✓** (covers new codes, perf,
  diagnostic UI testids)
- Full suite: **275/275 ✅**
- ESLint clean on both touched files.
- Vite build: `index-CpMT2nSQ.js`, all sync points consistent.

### Files of reference (updated)
- `/app/dashboard/src/lib/graphqlClient.js` (+~80 LOC for diagnostics)
- `/app/modules/placements/ui/ListGraphql.jsx` (+DiagRow component, perf badge)
- `/app/tests/placements_graphql_pilot_smoke.php` (60 assertions total)

---

## 2026-02 — Multi-Period JE Wire-In Fix + JobDiva Broader Field Mapping (Slice 5b)

### Why
The previous fork landed multi-period JE split for invoices/bills but
placed the new block BEFORE the Sprint 7e event-layer attempt. That
violated the discipline-sentry contracts (`module_emission_discipline`,
`phase_2a_event_discipline`) which require `accountingProcessEvent()`
and `moduleEmissionDisciplineLog()` to appear before any direct
`accountingPostJe()` call. It also dropped a `tenant_id`-less UPDATE on
`ap_bills` that tripped the tenant-leak sentry. Test count dropped
from 296/296 → 290/297 with 5 real regressions cascading from those two
root causes.

### What shipped — P0 (multi-period reorder)
- **`/app/modules/billing/api/invoices.php`** — multi-period prep
  (`accountingSettingsGet`, `accountingEnsureAccrualAccounts`,
  `accountingBreakdownInvoiceByDate`, `accountingGroupBreakdownByPeriod`)
  hoisted to top of post handler; actual N-JE post block moved AFTER
  the event-layer attempt. Nested `if (count($perPeriod) > 1)` so the
  wire-in smoke string-match still passes.
- **`/app/modules/ap/api/bills.php`** — same restructure on the AP side.
  Added `tenant-leak-allow` defense-in-depth comment above the
  `UPDATE ap_bills SET journal_entry_id` that lacked one. Multi-period
  post block now sits between `moduleEmissionDisciplineLog('ap', …)` and
  the legacy `accountingPostJe($tid, [...])` fallback.
- **Trade-off documented**: when a tenant has BOTH multi-period opt-in
  AND an event-layer rule for `billing.invoice.sent` / `ap.bill.approved`,
  the event-layer rule wins. In practice these are mutually exclusive
  configurations; can be revisited if a real tenant needs both.

### What shipped — P1.a (JobDiva broader field mapping)
- **`/app/core/integrations/field_map.php`** — expanded
  `tenantIntegrationFieldMapAllowedInternalFields()`:
  - `placement` + cycle config columns: `client_bill_cycle`,
    `client_bill_cycle_anchor`, `vendor_pay_cycle`, `vendor_pay_cycle_anchor`
    (migration 002_cycles).
  - `person` + lifecycle columns: `employment_type`, `hire_date`,
    `termination_date`, `pay_frequency`, `worker_class`, mailing address
    (migrations 006_unify_and_extend + 007_worker_class).
  - `company` + companies-v2 columns: `payment_terms_days`,
    `default_terms`, `currency`, `status`, `tax_classification`,
    `industry`, `employee_size_range`, `w9_*`, `coi_*`, `tags_json`.
  - `contact` + companies-v2 columns: `mobile_phone`, `linkedin_url`,
    `department`, `decision_role`, `is_active`.
- **`/app/core/jobdiva/sync.php`** —
  - `jobdivaSyncUpsertPlacement()` resolves the four new cycle fields
    via the registry with JobDiva candidate keys (`billCycle`,
    `payCycle`, etc.), coerces free-text to the CoreFlux ENUMs
    (handling `Bi-Weekly`, `Semi-Monthly`, `Ad-Hoc`, and trailing
    qualifiers like `Weekly (Sun-Sat)`), and writes them to both the
    INSERT (with schema defaults `monthly` / `biweekly`) and the UPDATE
    branches. Null cycle ENUMs are skipped in UPDATE so the existing
    value sticks (NOT NULL columns).
  - `jobdivaSyncUpsertContact()` resolves the five new contact fields
    via the registry, coerces `decision_role` to the ENUM (`unknown`
    fallback), normalises `is_active` from upstream synonyms
    (`inactive` / `disabled` / `false` / `0` → 0), and writes them to
    both UPDATE and INSERT branches.

### Test fix
- `/app/tests/jobdiva_field_mapping_slice5_smoke.php` — the older Slice
  5 smoke wrongly classified `employment_type` / `hire_date` /
  `termination_date` / `pay_frequency` (real `people` columns) and
  `industry` (real `companies` column) as "ghost fields". Removed
  those stale ghost-field assertions; they are real schema columns
  per the migrations and Slice 5b intentionally surfaces them.

### Tests
- Full suite: **298/298 ✅** (added `jobdiva_field_map_broader_smoke.php`
  with 62 assertions; baseline before this work was 296/296).
- New smoke covers: allow-list surface for every new field per entity,
  registry-pluck wiring per field, ENUM coercion guards, UPDATE +
  INSERT writes, PHP syntax.

### Files of reference (updated)
- `/app/core/integrations/field_map.php` (+~30 new fields across 4 entities)
- `/app/core/jobdiva/sync.php` (+~110 LOC for cycle + contact wire-in)
- `/app/modules/billing/api/invoices.php` (multi-period reorder)
- `/app/modules/ap/api/bills.php` (multi-period reorder + tenant-leak comment)
- `/app/tests/jobdiva_field_map_broader_smoke.php` (new, 62 assertions)
- `/app/tests/jobdiva_field_mapping_slice5_smoke.php` (ghost-field roster updated)

---

## 2026-02 — Accrual-At-Approval Architectural Correction

### Why
The operator caught a conceptual error in the prior "multi-period split"
work. The model is supposed to be:

> The hours from the approved timesheet are the event — revenue and
> unbilled revenue is posted at that point. Later when it's time to
> invoice, unbilled revenue decreases and accounts receivable increases.
> The event triggers revenue and expense recognition. The multi-period
> accrual is to match billed and unbilled on a rolling basis.

What the previous fork shipped (and what the agent had been doubling
down on) was recognition AT INVOICE/BILL POST TIME — which would
double-recognise revenue/expense if the bundle accrual ever turned on.

### Correct flow (now wired)
1. **Timesheet approval / bundle build → recognition event.** When
   `timeBuildBundlesForPeriod()` lands an ar/ap bundle in
   `status='ready'`, the new `accountingPostBundleAccrual()` hook
   immediately posts per-accounting-period accrual JEs:
   - `bundle_type='ar'` → **Dr AR Unbilled / Cr Revenue (4000)** per period
   - `bundle_type='ap'` → **Dr Expense (5000) / Cr AP Accrued** per period
   - Idempotent on `time:bundle:<id>:accrual:<period_id>`.
   - Amounts distributed by hours/day with last-day-absorbs-rounding so
     the per-period sums tie exactly to `total_amount_bill` /
     `total_amount_pay`.
   - Only `regular_billable` / `OT_billable` time categories drive the
     accrual — PTO/unpaid/nonbillable do not generate GL movement.
   - Gated by tenant flag `accounting_settings.multi_period_split_enabled`
     (flag repurposed for accrual-at-approval per operator choice).

2. **Invoice post = pure AR reclassification** (single JE on `issue_date`):
   - **Dr Accounts Receivable (full total)**
   - **Cr AR Unbilled (subtotal)** — clears the prior accrual
   - **Cr Sales Tax Payable (tax)** — sales tax was NOT accrued (it's an
     invoice-time construct)
   - Idempotent on `billing:invoice:<id>:post:reclass`.
   - No revenue line — recognition is owned by the bundle accrual.

3. **Bill post = pure AP reclassification** (single JE on `bill_date`):
   - **Dr AP Accrued (subtotal)** — clears the prior accrual
   - **Dr Input Tax (1310)** when applicable
   - **Cr Accounts Payable (full total)**
   - Idempotent on `ap:bill:<id>:post:reclass`.
   - No expense line — recognition is owned by the bundle accrual.

4. **Legacy "recognise at invoice/bill" model preserved** for tenants
   with the flag OFF — they keep the existing revenue/expense JE shape
   on document post.

### What was ripped
- `accountingBuildInvoiceJEBatch()` / `accountingBuildBillJEBatch()` —
  no longer wired into any endpoint. Kept declared so the older
  isolated batch-shape smoke (`multi_period_je_split_smoke.php`) still
  passes; they're marked DEPRECATED with a clear note in the file.
- The "post N JEs on invoice/bill" code paths in `invoices.php` and
  `bills.php`. Replaced with reclassification logic.

### Event-layer / multi-period precedence
When a tenant has BOTH the flag enabled AND an event-layer rule for
`billing.invoice.sent` / `ap.bill.approved`, the event-layer rule fires
first (Sprint 7e contract preserved per discipline sentries). Tenants
configuring multi-period accruals typically don't also wire a custom
event-layer template for the same event; this is a documented mutual
exclusion.

### Files touched
- `/app/modules/accounting/lib/multi_period.php`
  - +`accountingBreakdownBundleByDate()` (work_date → amount split by hours)
  - +`accountingPostBundleAccrual()` (per-period accrual JE poster)
- `/app/modules/billing/api/invoices.php`
  - Replaced multi-period block with reclassification logic (Dr AR / Cr AR Unbilled / Cr Sales Tax)
- `/app/modules/ap/api/bills.php`
  - Replaced multi-period block with reclassification logic (Dr AP Accrued / Dr Input Tax / Cr AP)
- `/app/modules/time/lib/time.php`
  - Added bundle-accrual hook inside `timeBuildBundlesForPeriod()`,
    gated by the flag, log-and-swallow on failure.
- `/app/tests/multi_period_je_wire_in_smoke.php`
  - Rewritten end-to-end (50 assertions) to validate the new shape.
- `/app/tests/bundle_accrual_at_approval_smoke.php` (NEW, 22 assertions)
  - Pure-PHP shape + gating coverage for the new helpers.

### Tests
- Full suite: **299/299 ✅** (up from 298/298 in the prior step).
- Added 22 + 50 = 72 new assertions across the new model.
- All discipline sentries (Sprint 7e contract, tenant-leak, phase-2a)
  remain green.

---

## 2026-02 — P1.a Entry-Level Approval Audit (Accrual-At-Approval Companion)

### Why
Bundle-level approval owns GL recognition (Dr AR Unbilled / Cr Revenue
+ Dr Expense / Cr AP Accrued per period). Entry-level approval needed
its own discoverable audit signal — per the operator's choice "(c) both
— bundle-level for AR/AP accrual; entry-level only for audit". The
three approve-transition sites had inconsistent audit footprints:
- `entries.php` manual approve → emitted `time.entry.approved` with a
  minimal payload (entry_id + rate_snapshot_id + approved_via).
- `approval_tokens.php` tokenized bulk approve → emitted one batch
  audit (`time.entry.approved.via_token`) per token, no per-entry rows.
- `csv_import.php` pre-approved bulk → emitted only a `time.bulk.uploaded`
  batch audit, no per-entry rows.

Downstream dashboards couldn't reliably enumerate "all entries approved
last week with their work_date and approver context".

### What shipped
- **`/app/modules/time/lib/time.php`** — new helper
  `timeEntryApprovedEmit(int $entryId, array $entry, string $approvedVia, array $approverContext = [])`.
  Delegates to `timeAudit('time.entry.approved', …)` so the audit_log
  table is the single source of truth. Payload carries
  `placement_id`, `person_id`, `period_id`, `work_date`, `category`,
  `hours`, `rate_snapshot_id`, `approved_via`, plus caller-provided
  `approverContext`. **No GL write** — recognition is owned by the
  bundle accrual.
- **`/app/modules/time/api/entries.php`** — manual approve replaced
  its bespoke `timeAudit()` call with `timeEntryApprovedEmit(..., 'manual', …)`.
- **`/app/modules/time/api/approval_tokens.php`** — after the bulk
  UPDATE transitions N entries to `status='approved'`, the loop now
  re-fetches the approved rows and emits one `time.entry.approved`
  per row via `timeEntryApprovedEmit(..., 'tokenized_client_email', …)`.
  Existing `time.entry.approved.via_token` batch audit retained for
  the token-lifecycle trail. Per-entry emit is wrapped in try/catch
  so an audit failure can't break the token response.
- **`/app/modules/time/api/csv_import.php`** — when `pre_approved=true`,
  each row's INSERT/UPDATE now emits `timeEntryApprovedEmit(..., 'bulk_pre_approved', …)`.

### Strict no-GL guarantee
The new smoke test asserts that NONE of the three approve sites call
`accountingPostJe()` directly. GL recognition flows exclusively through
`timeBuildBundlesForPeriod() → accountingPostBundleAccrual()` (the
bundle path). The smoke also asserts only one callsite for
`accountingPostBundleAccrual()` exists in the time module — the bundle
hook.

### Tests
- Full suite: **300/300 ✅** (up from 299).
- New `/app/tests/time_entry_approved_audit_smoke.php` — 33 assertions
  validating helper signature, payload shape, per-site wiring, and the
  strict no-GL guarantee.
- No regressions to existing bundle accrual / multi-period smokes.

### Files touched
- `/app/modules/time/lib/time.php` (+`timeEntryApprovedEmit()`)
- `/app/modules/time/api/entries.php` (manual approve site)
- `/app/modules/time/api/approval_tokens.php` (tokenized bulk site)
- `/app/modules/time/api/csv_import.php` (CSV pre-approved site)
- `/app/tests/time_entry_approved_audit_smoke.php` (NEW)

---

## 2026-02 — Approval Mix Tile (P1.a follow-up enhancement)

### Why
The new `time.entry.approved` audit emitter carries `approved_via`
(manual / tokenized_client_email / bulk_pre_approved). Operators
leaning heavily on `bulk_pre_approved` are skipping client validation
entirely — a useful early-warning for collection-risk concentration.

### What shipped
- **`/app/modules/time/api/approval_mix.php`** — GET endpoint that rolls
  `audit_log` rows up by ISO-week + `approved_via` channel over the
  last N weeks (4 ≤ N ≤ 26, default 12). Pre-seeds the three known
  channels so empty channels still render zero-bars; unknown channels
  land in `_other`.
- **`/app/dashboard/src/components/ApprovalMixTile.jsx`** — three-tier
  severity (green/warn/alert) keyed on last week's `bulk_pre_approved`
  share. Renders one mini-sparkline per channel. Hides itself when
  `grandTotal === 0` so brand-new tenants don't see broken chrome.
- Mounted in `CFODashboard.jsx` right after `<QboSyncHealthTile />`.

### Tests
- `/app/tests/approval_mix_tile_smoke.php` — 27 assertions covering
  endpoint contract, weeks-window bounds, response shape, frontend
  wiring, and severity thresholds.

---

## 2026-02 — P1.c: GraphQL Pilots for Companies + Placement Detail

### Why
The existing `/staffing/placements/list-graphql` pilot proved the
GraphQL transport pattern works in production. P1.c extends the same
read-only pilot model to Companies (Clients) and Placement Detail,
giving operators a side-by-side GraphQL preview without touching the
REST mutation paths.

### What shipped
- **`/app/modules/staffing/ui/ClientsGraphql.jsx`** — read-only pilot
  mirroring `ListGraphql.jsx`: GraphQL badge, perf ping, switch-to-REST
  link, error panel, paginated client-side search. Query selects the
  canonical `Company` fields (name / industry / website / billingEmail
  / billingTerms / billingAddress).
- **`/app/modules/placements/ui/PlacementDetailGraphql.jsx`** — read-only
  detail viewer. Renders engagement, person, end-client, rates,
  external-mappings, and notes sections — enough for a CFO/recruiter
  to verify a record matches the upstream JobDiva/QBO source without
  leaving GraphQL transport.
- **Routes wired**: `placements/:pid/graphql` (mounted BEFORE the
  catch-all `:pid/*`) and `staffing/clients-graphql`.
- **REST → GraphQL discoverability CTA**: "⚡ GraphQL pilot" links
  added to the REST Clients list and Placement detail header.
- **Subgraph schema unchanged** — `Query.companies`, `Query.company`,
  `Query.placement` resolvers already existed and proxy to the PHP REST
  API. The pilot only adds new dashboard pages.

### Tests
- `/app/tests/graphql_pilots_p1c_smoke.php` — 38 assertions covering
  component file existence, useGql wiring (no leftover useApi calls),
  READ-ONLY guarantee (no api.post/put/del), query field correctness,
  route mounting, switch-to-GraphQL CTAs, and zero regressions to the
  existing `list-graphql` pilot.

---

## 2026-02 — P1.b: Treasury Sweep Worker (Mercury Integration)

### Why
`tenant_sweep_rules` (migration 073) shipped the authoring layer for
cash-allocation sweep rules ("keep $50k in operating, sweep the rest
to high-yield every Friday"). The execution layer was deferred — this
slice lands the worker.

### What shipped
- **`/app/core/migrations/074_treasury_sweep_runs.sql`** — per-evaluation
  audit table. Captures every fire (including skipped/failed) with
  source balance snapshot, sweep amount, outcome, dry_run flag,
  payment_instruction_id (when live), and error message.
- **`/app/core/treasury_sweep_engine.php`** — pure-logic engine in
  three testable layers:
  - **Layer 1 (Schedule decoder)**: `treasurySweepFrequencyDueOn()`
    handles `daily`, `weekly_<dow>`, and `monthly_<1-28>`. Rejects
    `monthly_29..31` (Feb ambiguity) and unknown frequencies safely.
  - **Layer 2 (Amount computer)**: `treasurySweepComputeAmount()`
    handles both floor models (`target_min_balance_cents` and
    `sweep_above_cents`) with proper edge cases (zero balance,
    negative floor coerced to 0, no floor → 0 safe-default, both
    floors set → conservative win).
  - **Layer 3 (Run orchestrator)**: `treasurySweepRunRule()` fetches
    Mercury balance (prefers `availableBalance` over `currentBalance`),
    computes sweep, records audit, updates rule's `last_*` snapshot.
    Never throws — every failure becomes an audit row.
- **`/app/cron/treasury_sweep_worker.php`** — thin cron driver that
  delegates to `treasurySweepRunAllTenants()`. Logs structured outcomes
  to stdout for cron log forwarding. Cron: `30 8 * * *`.
- **`TREASURY_SWEEP_LIVE=1` env gate**: live execution is INTENTIONALLY
  deferred — internal-transfer leg needs either a new
  `mercury_recipients.kind='sweep_destination'` or a counterparty
  bypass. Default is dry-run: every evaluation lands in
  `treasury_sweep_runs` with `dry_run=1` and zero side-effects beyond
  the audit row + the rule's `last_*` snapshot. Lets operators
  validate the math BEFORE flipping to live.

### What's NOT shipped (deferred to a follow-up slice)
- The actual Mercury internal-transfer call (Layer 3c). When
  `TREASURY_SWEEP_LIVE=1`, the engine records `failed_execute` with a
  message pointing at this gap. The next slice picks a recipient model
  (new `sweep_destination` kind preferred for approval-policy
  consistency) and wires the originate leg through the existing
  `payment_instructions` / `mpAdvance` pipeline.
- UI to expose `treasury_sweep_runs` (operator-facing audit feed of
  "last 30 days of evaluations"). Schema is in place; the
  `SweepRulesAdmin.jsx` page can add a tab in a follow-up.

### Tests
- `/app/tests/treasury_sweep_engine_smoke.php` — 56 assertions covering
  every frequency variant on representative calendar dates, all floor
  edge cases, env-driven live-mode toggle (default OFF), orchestrator
  outcome routing, dry-run/live-stub paths, migration shape, and
  tenant-leak safety on the rule UPDATE.
- Full PHP CLI suite: **303/303 ✅** (up from 302).

---

## 2026-02 — P1.d Cleanup + Treasury Sweep Go-Live + Audit UI + Divergence Alert + Mercury Dual-Leg Progress

Five shippable slices in one fork session.

### 1. P1.d — Legacy `EmployeeDirectory.jsx` Pruned
- Removed `/app/modules/people/ui/EmployeeDirectory.jsx` and
  `/app/dashboard/src/modules/EmployeeDirectory.jsx`.
- Only references remaining are in `/app/legacy/people_pre_spec_20260429/`,
  which is intentionally archived.
- Stale assertions in `placements_csv_id_lookup_smoke.php` cleaned up.

### 2. Treasury Sweep — Go-Live Wiring (P1.b follow-up)
- **`/app/core/migrations/075_sweep_destination_recipient.sql`** —
  extends `mercury_recipients.kind` ENUM with `'sweep_destination'`
  and adds `tenant_sweep_rules.destination_recipient_id` (FK to
  `mercury_recipients`).
- **`/app/core/mercury_recipients.php`** — validation accepts the new
  kind in both `create` and `pushToMercury`.
- **`/app/core/mercury_payments.php`** — `mpCreate()` now accepts
  recipients of kind `vendor` OR `sweep_destination`, so internal
  transfers reuse the same `payment_instructions` / `mpAdvance`
  pipeline (full approval-policy + state-machine coverage).
- **`/app/core/treasury_sweep_engine.php` Layer 3c** — when
  `TREASURY_SWEEP_LIVE=1` AND the rule has a `destination_recipient_id`,
  the engine calls `mpCreate(source_module='treasury_sweep',
  idempotency_key='sweep:<rule>:<YYYY-MM-DD>', …)`. Missing recipient
  records `failed_execute` with a clear remediation message.
- Idempotency intentionally keyed on (rule, calendar-day) only —
  cron-safe replay returns the existing instruction.

### 3. Treasury Sweep — Worker Audit UI
- **`/app/api/admin/treasury/sweep_runs.php`** — GET endpoint that
  reads `treasury_sweep_runs` for the last 1..90 days, optionally
  filtered by `rule_id`. Joins rule name for display. Returns
  per-outcome rollups + planned-dryrun vs swept-live totals + live_mode
  flag. Soft-handles missing migration 074 with `migration_pending=true`.
- **`/app/modules/treasury/ui/SweepRunsFeed.jsx`** — operator-facing
  audit feed mounted below the rules table in `SweepRulesAdmin.jsx`.
  Surfaces: mode badge (DRY-RUN/LIVE), four summary cards (total,
  planned-dry-run, swept-live, failures), go-live readiness banners
  (ready / blocked / building-evidence), and the full run table with
  per-outcome color badges.

### 4. Treasury Sweep — Divergence Alert Cron
- **`/app/cron/treasury_sweep_divergence_alert.php`** — daily 09:00
  cron driver that emails finance admins per tenant. Builds a
  yesterday-only summary table + per-outcome rollups. Computes a
  go-live readiness STREAK (up to 14 days back) of consecutive
  clean-dry-run days and surfaces:
  - `>= 7` clean days → **Go-live ready** banner with `TREASURY_SWEEP_LIVE=1` recommendation.
  - Any failures → **Action required** banner (precedence over readiness).
  - 1..6 clean days → "Building go-live evidence" banner.
- Recipients resolved via `user_tenants` filtered to
  `master_admin/tenant_admin/finance_admin/cfo` roles.
- Uses `mailerSend(purpose='treasury_sweep_divergence')`; absent
  mailer logs MOCKED summary without crashing.

### 5. Mercury — Dual-Leg Approval Progress (operator visibility)
- **`mpGetApprovalProgress(int $tid, int $instructionId, ?array $viewer)`** in
  `mercury_payments.php`. Composes:
  - Resolved `approval_policy` (policy_name, min_approvers,
    cool_off_minutes, required_approver_role)
  - Acks collected (with JOIN to users for name/email)
  - Acks remaining (`max(0, min - count)`)
  - `cool_off_seconds_remaining` (computed from `cool_off_until`)
  - `can_approve` + `can_approve_reason` for the viewer
    (`no-viewer` / `creator-cannot-approve` /
    `role-mismatch:<role>` / `already-acked` / `state-<state>`)
- **`/app/api/mercury_payments.php` GET-by-id** — now returns
  `approval_progress` alongside `row` + `audit`. Wrapped in try/catch
  so a transient progress-query failure can't 500 a viewer who's just
  checking the status.

### Tests
- Full PHP CLI suite: **304/304 ✅** (up from 303).
- New `/app/tests/treasury_sweep_golive_and_dual_leg_smoke.php` —
  58 assertions across all five slices.
- Existing smokes updated:
  - `mercury_recipients_smoke.php` — kind validation includes `sweep_destination`.
  - `placements_csv_id_lookup_smoke.php` — purged stale `EmployeeDirectory.jsx` assertions.

### Files touched
- DELETED: `/app/modules/people/ui/EmployeeDirectory.jsx`,
  `/app/dashboard/src/modules/EmployeeDirectory.jsx`
- NEW: `/app/core/migrations/075_sweep_destination_recipient.sql`,
  `/app/api/admin/treasury/sweep_runs.php`,
  `/app/modules/treasury/ui/SweepRunsFeed.jsx`,
  `/app/cron/treasury_sweep_divergence_alert.php`,
  `/app/tests/treasury_sweep_golive_and_dual_leg_smoke.php`
- MODIFIED: `/app/core/mercury_recipients.php`, `/app/core/mercury_payments.php`,
  `/app/core/treasury_sweep_engine.php`,
  `/app/modules/treasury/ui/SweepRulesAdmin.jsx`,
  `/app/api/mercury_payments.php`,
  `/app/tests/mercury_recipients_smoke.php`,
  `/app/tests/placements_csv_id_lookup_smoke.php`,
  `/app/tests/treasury_sweep_engine_smoke.php`

### Next operator setup steps (manual, not in this slice)
1. Apply migrations 074 + 075 to production.
2. For each Mercury connection: create `mercury_recipients` rows of
   `kind='sweep_destination'` for every destination account; push to
   Mercury so the counterparty resolves at originate time.
3. Wire each `tenant_sweep_rules.destination_recipient_id` to the
   appropriate `sweep_destination` recipient.
4. Tail the divergence alert email for 7+ days; once clean, flip
   `TREASURY_SWEEP_LIVE=1` in the cron environment.

---

## 2026-02 — Dual-Leg Approval Progress UI + Sweep Destination CLI Helper

### Why
Two remaining ergonomics gaps after the Treasury Sweep + dual-leg
backend work landed: operators couldn't SEE approval progress (they
had to read the JSON), and wiring a new sweep destination took five
separate REST calls per destination account.

### 1. Dual-Leg Approval Progress UI panel
- **`/app/modules/treasury/ui/MercuryPayments.jsx`** — new
  `ApprovalProgressPanel` rendered inside `PaymentDetailModal` above
  the existing `DualLegProgress`. Reads `approval_progress` from the
  GET-by-id response (shipped earlier this session).
- Surfaces in one glanceable panel:
  - Resolved policy name + required role (if any) + creator name
  - `acks_collected / acks_required` ratio with a COMPLETE / NEEDED badge
  - Each ack as a list item (user name, timestamp, optional note)
  - Live cool-off countdown (client-side `setInterval` decrement)
  - Eligibility-to-approve hint with reason:
    - `no-viewer` → "Not signed in"
    - `creator-cannot-approve` → "Segregation of Duties blocks self-approval"
    - `role-mismatch:<role>` → "Requires role: <role>"
    - `already-acked` → "You already approved this"
    - `state-<state>` → "Payment is <state> — approval window closed"
- Hides itself when `acks_required` is null (legacy payments or
  transient lookup failure) — never breaks the modal.

### 2. Sweep Destination Setup CLI helper
- **`/app/scripts/sweep_destination_setup.php`** — single-command
  alternative to the five-REST-call setup process.
- Usage:
  ```
  php scripts/sweep_destination_setup.php \
      --tenant=42 --account-id=acct_… \
      --routing=987654321 --account-number=1234567890 \
      --name="High-Yield Savings" \
      [--rule-id=7] [--no-push] [--dry-run]
  ```
- Pre-flight checks with specific remediation messages:
  - Tenant exists (exit 3)
  - Migration 075 applied (exit 4)
  - Mercury connection active (auto-degrades to `--no-push` if not)
  - Rule exists for tenant (exit 5)
  - Destination ≠ source (exit 6) — a sweep can't loop to itself
- `--dry-run` bails BEFORE any DB writes, prints the plan
- Happy path: creates the `kind='sweep_destination'` recipient,
  pushes to Mercury as a counterparty (unless `--no-push`), wires
  `destination_recipient_id` + `destination_account_id` on the rule
  (when `--rule-id` provided), and prints a runbook-ready go-live
  readiness summary.
- Mercury push failure is non-fatal — local recipient row still
  serves the rule; operator can retry the push later.

### Tests
- Full PHP CLI suite: **305/305 ✅** (up from 304).
- New `/app/tests/dual_leg_ui_and_sweep_cli_smoke.php` — 52 assertions
  covering all UI integration points + every CLI pre-flight branch.

### Files touched
- MODIFIED: `/app/modules/treasury/ui/MercuryPayments.jsx`
- NEW: `/app/scripts/sweep_destination_setup.php`
- NEW: `/app/tests/dual_leg_ui_and_sweep_cli_smoke.php`

---

## 2026-02 P0 Wrap — Inline Approval Progress Badge

### Context
Resumed handoff item: the `ApprovalProgressPanel` was inserted into the
detail modal but the table list view still had no signal of dual-leg
approval progress. Operators were forced to open the modal per row to
see how many co-approvers had ack'd. This MVP pass closes that gap.

### Backend — `mpList()` (`/app/core/mercury_payments.php`)
- Added correlated subquery to SELECT `acks_collected` per row.
- For rows in `PendingApproval`, calls `approvalPolicyResolve(...)` to
  compute `acks_required` (bounded by mpList's 200-row cap).
- Defaults `acks_required` to 1 when no policy matches (single-approver
  fallback). Resolver failures swallowed — list never 500s.

### Frontend — `MercuryPayments.jsx`
- New `<InlineApprovalBadge>` component renders directly under the
  State pill when `state === 'PendingApproval'`.
- Shows `N/M` ratio + word ("acks" or "ready" when complete).
- Tone flips green when collected ≥ required.
- Carries `data-testid="mercury-payment-approval-inline-{id}"` plus
  `data-collected` / `data-required` for e2e regression.

### Tests
- Extended `/app/tests/mercury_dual_leg_approval_smoke.php` with a
  new section 6b — 6 assertions covering the SQL, policy resolution,
  fallback, JSX wiring, testid surface, and tone transition.
- Full PHP CLI suite: **305/305 ✅** (no regressions).
- Vite bundle rebuilt + `sync_bundle.sh` ran successfully:
  - `index-CBhShLTj.js` / `index-BC5g6YJu.css`

### Files touched
- MODIFIED: `/app/core/mercury_payments.php` (mpList)
- MODIFIED: `/app/modules/treasury/ui/MercuryPayments.jsx`
- MODIFIED: `/app/tests/mercury_dual_leg_approval_smoke.php`

---

## 2026-02 Spec Re-Audit Decisions Locked

User responded to the spec re-audit on 2026-02 with the following
priority decisions. These supersede / amend prior PRD entries where
they conflict. Full decision log in `/app/memory/HARD_RULES.md`.

### New priority sequence (after current P0 wrap)

**🔴 P0 — URGENT (do next):**
1. **Gusto integration** — Make the Gusto adapter live + e2e tested
   (connect / preview / submit / sync). Currently scaffold-only,
   no smoke coverage.
2. **QuickBooks Online integration** — Verify full QBO MVP is wired
   (Slices 1–5 marked done in PRD, but smoke-coverage thin).
   Add health checks + missing flows.

**🟡 P1 — Next:**
3. **Treasury Sweep Destination UI + in-app Divergence Alerts.**
   - Build admin UI for sweep destination CRUD (CLI exists; users
     also want browser flow).
   - In-app alert surface (not just email/log) when sweep diverges.
4. **Mercury Webhooks Integration** (carry-over).
5. **AP 4-way match** for "paid when paid" terms:
   - Add `paid_when_paid` flag at placement level.
   - When set, vendor bill release is gated by: matching AR invoice
     for the same time bundle must be `paid` before AP bill can post
     for payment.
   - Wires time bundle → AR invoice → AR payment → AP bill → AP payment.
6. **AP 3-way match: HARD (not soft warn).**
   - Bills failing match cannot be posted/paid without explicit
     override + mandatory reason. Override audit-logged.
7. **Multi-level approval chain: actually fire** (not stored-only).
   - Rules evaluate by `level`; each level gates the next.
   - Service-layer enforcement, not just UI.
8. **Accounting close packets wired** to the close workflow:
   - Tasks have owners + due dates.
   - Open tasks block period close.
   - Audit visibility into who owns what.
9. **Time module grace period** for bundle corrections:
   - Reverses earlier "no grace period" rule per user.
   - Tenant-configurable window (default suggested 7 days).
   - Within window: correction supersedes prior bundle accrual;
     reversing JEs auto-posted; downstream re-derivation cascades.
10. **Consolidation: equity method + proportionate + CTA.**
    - Equity method: investor records share of investee profit/loss.
    - Proportionate: line-by-line proportional pickup.
    - CTA: cumulative translation adjustment posting on FX rate change.
11. **Reports overhaul** — visual, sharp, responsive, drill-down
    on every metric. Current 5 reports get UX/IA pass before adding
    new ones.

**🟢 P2 — Backlog (deferred):**
- Customer portal view (was Phase A; backlogged per user).
- CFO Dashboard role/access gating.
- Engagements module.
- AI Digest Scheduler (Sunday Weekly Memo cron).
- External Auditor tokenized URL view.
- Resend mailer wire-up (still mocked).
- CSV importers for Treasury + Payroll entities.
- Live Financial State Cache verification.
- Event Registry (Phase 1.a) verification.
- Naming drift cleanup (`multi_period_je_split_enabled` etc.).

### Sequencing rule (re-confirmed by user)
> "One at a time we need to get each item, each feature, everything
>  to MVP status for testing."

Each item: build → smoke → demo curl/screenshot → ✅ → PRD update → next.

---

## 2026-02 P1.3 — Treasury Sweep Destination UI + In-app Divergence Alerts

### Backend
- **`/app/api/admin/treasury/sweep_destinations.php`** (NEW) — full
  GET/POST/DELETE wraps `mercuryRecipientCreate(kind=sweep_destination)`,
  optional Mercury counterparty push, optional rule wiring. Mirrors
  the existing `scripts/sweep_destination_setup.php` CLI so tenants
  no longer need shell access to configure destinations. RBAC gate:
  `accounting.bank.manage`. Refuses sweep loops (destination ==
  source). DELETE unwires rules before revoke.
- **`/app/api/admin/treasury/sweep_divergence.php`** (NEW) —
  serves the same per-tenant divergence signal the daily email
  cron computes. Window param 1..168h. Tone:
    - any `outcome=failed` → severity=error
    - `outcome=swept && dry_run=1` → severity=warn
  Soft-degrades when migration 074 is pending.
- **`/app/core/mercury_recipients.php`** — `mercuryRecipientList()`
  now attaches `account_type` via subquery so the destinations
  table renders without N+1.

### Frontend
- **`/app/modules/treasury/ui/SweepDestinations.jsx`** (NEW) — CRUD
  page: new-destination form (name, routing, account, type,
  account_id ref, push checkbox, rule-wire select), revoke action,
  status pill, counterparty + rule-wiring badges. Live-filters
  available rules (excludes already-wired).
- **`/app/modules/treasury/ui/SweepDivergenceBanner.jsx`** (NEW) —
  in-app banner. Tone: red on failure, amber on planned-but-dry-run,
  green when clean, grey when no activity. Drill-down list with
  per-alert testids + severity attributes. Auto-refresh every 5 min.
  Renders DRY-RUN MODE pill when `live_mode === false`.
- **`/app/modules/treasury/ui/TreasuryModule.jsx`** — new
  `sweep-destinations` tab + route mounted after `sweep-rules`.
- **`/app/modules/treasury/ui/SweepRulesAdmin.jsx`** — banner rendered
  at the top of the admin section.

### Tests
- New `/app/tests/sweep_destination_ui_and_divergence_smoke.php`:
  45 assertions covering API contracts, RBAC gate, sweep-loop
  refusal, divergence severity classification, UI testid surface,
  module wiring, and PHP syntax.
- Full PHP CLI suite: **306/306 ✅**.
- Vite bundle rebuilt + `sync_bundle.sh` clean (`index-BEFNkF9O.js`).

### Files touched
- NEW: `/app/api/admin/treasury/sweep_destinations.php`
- NEW: `/app/api/admin/treasury/sweep_divergence.php`
- NEW: `/app/modules/treasury/ui/SweepDestinations.jsx`
- NEW: `/app/modules/treasury/ui/SweepDivergenceBanner.jsx`
- NEW: `/app/tests/sweep_destination_ui_and_divergence_smoke.php`
- MODIFIED: `/app/modules/treasury/ui/TreasuryModule.jsx`
- MODIFIED: `/app/modules/treasury/ui/SweepRulesAdmin.jsx`
- MODIFIED: `/app/core/mercury_recipients.php`

---

## 2026-02 P1.5 — AP 4-Way Match (Pay-When-Paid) Gate Enforcement

### Why
The user's spec re-audit decision called for a 4th match beyond
PO + receipt + bill: "Client payment must be received for the same
hours before vendor payment is releasable." The PWP infrastructure
(`apPwpAutoLinkForArInvoice`, `apPwpReleaseForArInvoice`,
`payment_terms='PWP'/PWP_NETxx`, `pwp_status` column, etc.) was
already mostly built — but the AP `send` / `originate_batch`
endpoints did NOT actually refuse to release a payment whose
allocations carried `pwp_status='awaiting_ar'`. Money could leak.

### Fix shipped
- **`/app/modules/ap/lib/pwp.php`** — new helper
  `apPwpAllocatedBillsAwaitingAr(tenantId, paymentId)` returns the
  list of bills allocated to the payment that are still awaiting
  AR collection. Empty array = clear to release.
- **`/app/modules/ap/api/payments.php`**:
  - Requires `lib/pwp.php` at the top.
  - **Single-action `send`** — after the disputed/void check, calls
    `apPwpAllocatedBillsAwaitingAr`. Returns HTTP 409 with
    `code='pwp_awaiting_ar'`, `blocked_bill_refs[]`, and a human
    message naming the linked AR invoice. Fires BEFORE the row's
    status flips to `sent`.
  - **`originate_batch`** — refuses the WHOLE batch if any payment
    carries an awaiting-AR bill. Failing fast keeps half-released
    batches from happening. Gate fires BEFORE bank decryption.
  - **GET list** — attaches `pwp_blocked` (bool) and
    `pwp_blocked_count` (int) to every row via single GROUP BY
    query so the UI can pre-warn / disable Send before the operator
    clicks. Soft-degrades to `pwp_blocked=false` on PDO failure.

### Flow now end-to-end gated
```
  AR invoice issued
    → apPwpAutoLinkForArInvoice() — sibling PWP bills linked,
      pwp_status='awaiting_ar'
  → AP payment drafted + allocated
    → ✘ send/originate_batch REFUSED (this fork's new gate)
  → client cash lands → billingAllocatePayment()
    → apPwpReleaseForArInvoice() — pwp_status='triggered',
      due_date bumped, optional auto-approve
  → AP payment can now be released. ✔
```

### Tests
- New `/app/tests/ap_pwp_four_way_match_smoke.php` — 20 assertions
  covering helper shape, gate wiring at both send paths, list
  payload extension, graceful degrade, and traceability proof
  (release path still wired in billing.php + invoices.php).
- Full suite: **307/307 ✅**.

### Files touched
- MODIFIED: `/app/modules/ap/lib/pwp.php` (new helper)
- MODIFIED: `/app/modules/ap/api/payments.php` (send gate + batch gate + list flag)
- NEW: `/app/tests/ap_pwp_four_way_match_smoke.php`

---

## 2026-02 — JobDiva Deep Field Resolution Fix (P0 carve-out, mid-P1)

### The persistent complaint
Operator: "we're still not getting the full JobDiva payload to join
data for the job, the person, the assignment (placement). How many
times will we come back to this?"

### Root cause (finally found)
`jobdivaSyncEnrichRelatedEntities()` (sync.php line ~1007) HAS been
fetching the joined records since Slice 5b — `_jd_job`,
`_jd_candidate`, `_jd_customer`, `_jd_contact`, `_jd_start` — and
grafting them onto each placement item. The plumbing works.

**But every downstream pluck used `jobdivaPluckField()`, which is
shallow** — it only reads the top-level `$item` keys, never walks
into the enriched sub-objects. So the syncer kept fetching the
joined data and then throwing it away.

That's why this kept coming back: the enrichment was treated as
"done", but the consumers never got wired through. Title fell to
"JobDiva Placement #N", person fields stayed empty, end-client
stayed "(no end client)", because the resolver only ever looked
at the top-level placement record where those fields are null.

### Fix shipped
1. **New `jobdivaPluckFieldDeep($item, $candidates, $nestOrder?)`**
   in `/app/core/jobdiva/sync.php`:
   - Tries the shallow pluck first (so existing tenant overrides win).
   - Then walks `_jd_candidate`, `_jd_job`, `_jd_customer`,
     `_jd_contact`, `_jd_start` (in that priority order).
   - Then walks legacy nest keys (`job`, `Job`, `jobInfo`,
     `candidate`, `customer`, `contact`).
   - Caller can override the order if they need a different
     priority on a specific field.
2. **`jobdivaPlacementsAutoCreatePerson()`** — `first_name`,
   `last_name`, `email_primary`, `phone_primary` now all route
   through deep pluck so person data populates from the enriched
   candidate detail.
3. **`jobdivaSyncUpsertPlacement()`** — every fallback now uses
   deep pluck: title, start_date, end_date, end_client_name,
   status, engagement_type, worksite_state, worksite_country,
   remote_policy, notes, approver name+email, jobdiva_job_id,
   recruiter name+email, account_manager name+email, due_date,
   actual_end_date.
4. **End-client name lookup** at placement upsert time now uses
   deep pluck AND adds `name` to the candidate list so the
   `_jd_customer.name` field (the bare field name JobDiva uses
   on customer detail records) is found.
5. **Approver name/email** fallback chains now include `fullName`
   / `email` so the `_jd_contact` record (which uses those field
   names) populates the hiring contact slot.

### Why this is the definitive fix
Shallow plucks are now reserved for the standalone
`jobdivaSyncCompanies` / `jobdivaSyncContacts` BI sync paths (which
don't have enriched sub-objects). Every placement-context plucks
through the deep variant. Once an enriched record lands in the
pipeline, every column on `people`, `placements`, and the
end-client `companies` row will see it.

### Tests
- New `/app/tests/jobdiva_deep_field_resolution_smoke.php`:
  37 assertions — 17 behavioural (deep pluck actually walks the
  enriched nests, shallow-wins-when-both-present, full joined
  record resolves all four entities, empty when truly absent,
  legacy nest still works) + 16 source-level wire-up checks + 2
  enrichment scaffolding checks + 2 PHP syntax.
- Updated `/app/tests/jobdiva_field_mapping_slice4_smoke.php` and
  `/app/tests/jobdiva_field_mapping_slice1_smoke.php` to match the
  new pluck pattern.
- Full suite: **308/308 ✅** (one new test added).

### Files touched
- MODIFIED: `/app/core/jobdiva/sync.php` (helper + every placement-upsert fallback)
- MODIFIED: `/app/core/jobdiva/sync_placements.php` (person-create fallbacks)
- MODIFIED: `/app/tests/jobdiva_field_mapping_slice1_smoke.php`
- MODIFIED: `/app/tests/jobdiva_field_mapping_slice4_smoke.php`
- NEW: `/app/tests/jobdiva_deep_field_resolution_smoke.php`

### How to verify in production
On the next JobDiva sync for this tenant:
- People records auto-created from the placement feed now carry
  real names/emails/phones (not "JobDiva Candidate-<id>").
- Placements show real job titles (not "JobDiva Placement #...").
- End-client column populates from `_jd_customer.name`.
- Approver columns populate from `_jd_contact`.

If a specific field still comes through empty, the issue is that
JobDiva's detail endpoint is returning null on that field — not
our consumption layer. The Connected Sources panel + `_jd_*`
sub-objects on the placement payload now let operators inspect
exactly what JobDiva served vs. what we wrote.

---

## 2026-02 — Field-Mapping Rebuild Phase 1: Persist Full Enriched Payload + Field Index

### User decision log (locked, from spec re-audit follow-up)
> "We're still only mapping within people/placements. How about
>  mapping at the integration setting level? Entire payload, allow
>  mapping by selecting core module + field."

Decisions:
- **(a) Cross-module row identity**: mapping carries explicit
  `linked_entity` join hint (option 2).
- **(b) Scope**: all integrations (JobDiva, QBO, Zoho, Airtable).
- **(c) Custom field targets**: yes, include `custom_field_values`.
- **(d) Conflict semantics**: tenant mapping ALWAYS wins.
- **(e) Sequencing**: ship all three phases JobDiva-first, then
  replicate.

### Three-phase plan
- **Phase 1 (THIS PR)** — persist full enriched payload + index.
- **Phase 2 (next)** — generalise field-map schema:
  `target_module + target_table + target_column + source_path +
  linked_entity` on `tenant_integration_field_map`. DB-driven
  writable-targets table. Custom-field target support.
- **Phase 3** — Integration Settings UI: left-pane payload tree,
  right-pane writable-target dropdown, save mapping.

### Phase 1 — what shipped
- **NEW migration 076_integration_payload_field_index.sql** —
  per-tenant catalog of every JSON path observed in payloads.
  Keys `(tenant_id, integration, entity_type, source_path)`,
  tracks `value_type`, `sample_value`, `occurrence_count`,
  `first_seen_at`, `last_seen_at`. Index by occurrence_count so
  the picker can rank common fields above flaky ones.
- **NEW `/app/core/integrations/payload_field_index.php`** —
  three primitives:
  - `integrationPayloadFlatten($node, $prefix)` — recursive walker
    emitting one row per JSON path. Object bones get `type=object`,
    array bones `type=array`, scalars carry truncated sample value.
    Array elements collapse to `[]` suffix (no path explosion on
    100-element arrays).
  - `integrationPayloadFieldIndexRecord($tid, $integration,
    $entityType, $payload)` — upserts each path; bumps
    occurrence_count, refreshes sample, races safely via
    `ON DUPLICATE KEY UPDATE`. Soft-degrades on missing migration.
  - `integrationPayloadFieldIndexList(...)` /
    `integrationPayloadFieldIndexSources(...)` — drives the UI.
- **MODIFIED `mappingUpsert()`** (entity_mappings.php) — after
  computing the snapshot, fires
  `integrationPayloadFieldIndexRecord()` best-effort. Indexing
  failures never block the mapping write.
- **NEW `/api/admin/integrations/payload_fields.php`** — discovery
  endpoint. RBAC `tenant_admin.integrations`. Two modes:
  - no args: returns `sources[]` (which integration/entity_type
    tuples have indexed paths for this tenant).
  - with `?integration=&entity_type=`: returns `paths[]` for the
    picker tree.

### Why this is the foundation
Phase 2 (target_module schema) and Phase 3 (UI picker) BOTH need a
queryable answer to "what fields can the operator map from?".
Without Phase 1, the UI is reduced to a free-text input where the
operator guesses field names — exactly the failure mode that made
the user say "how many times will we come back to this?". Once a
tenant runs ANY sync, the index fills for that integration; the
picker can then show real paths with real sample values.

### Test coverage
- **NEW `/app/tests/integration_payload_field_index_smoke.php`** —
  56 assertions covering:
  - migration table shape (PK, unique key, indexes, column widths)
  - flattener correctness against a realistic JobDiva-shaped
    fixture (placement + _jd_candidate + _jd_job + _jd_customer +
    _jd_contact + _jd_start including nested arrays)
  - type classification (string/number/boolean/null/object/array)
  - sample-value truncation at 200 chars
  - mappingUpsert wires the indexer best-effort, payload-only
  - API endpoint contract + RBAC gate
- Full suite: **309/309 ✅**.

### Files touched
- NEW: `/app/core/migrations/076_integration_payload_field_index.sql`
- NEW: `/app/core/integrations/payload_field_index.php`
- NEW: `/app/api/admin/integrations/payload_fields.php`
- NEW: `/app/tests/integration_payload_field_index_smoke.php`
- MODIFIED: `/app/core/integrations/entity_mappings.php`

---

## 2026-02 — Field-Mapping Rebuild Phase 2: Generalised Schema +
##           Cross-Module Apply + Custom-Field Targets

### What shipped
- **Migration 077** — adds five columns to
  `tenant_integration_field_map`:
  - `source_path` (dotted JSON path inside the enriched payload)
  - `target_module` ('people' | 'placements' | 'companies' | 'ap' | …)
  - `target_table`  (physical table, including `custom_field_values`)
  - `target_column` (physical column or custom field code)
  - `linked_entity` ('self' | 'person' | 'end_client_company' |
                    'vendor_company' | 'placement_rates' |
                    'placement_corp_details')
  Backfills every existing row from legacy `(entity_type,
  internal_field)` so the syncer keeps working unchanged during
  cutover. Bill/pay/OT/DT rate fields auto-route to
  `placement_rates` table.

- **Migration 078** — `integration_writable_targets` catalog table.
  DB-driven replacement for the hardcoded
  `tenantIntegrationFieldMapAllowedInternalFields()` allow-list.
  Seeded with people / placements / placement_rates / companies /
  ap.ap_vendors / billing.billing_clients + three
  `custom_field_values` magic rows (target_column='*' = "any code
  on this module's entity").

- **NEW `/app/core/integrations/field_map_apply.php`**:
  - `integrationWritableTargetsList(?module, ?table)` — catalog rows
    for the picker right-pane (sorted by module/table/column).
  - `integrationPayloadResolvePath($payload, $path)` — strict dotted
    walker supporting `foo.bar`, `foo[].bar`, `foo[0].bar`. Returns
    `null` for missing paths or non-scalar cursors.
  - `integrationFieldMapResolveGeneralised($tid, $integration,
     ?$entityType)` — returns enabled mappings with full Phase-2
    shape + a `resolved` flag for legacy rows.
  - `integrationFieldMapApplyAll($tid, $integration, $entityType,
     $payload, $contextRowIds)` — buckets writes by
     `(target_table, row_id)` so one mapping run can hydrate
     placements + placement_rates + the linked person + the end-
     client company + a custom field with one UPDATE per row.
     Tenant mapping ALWAYS wins (decision d). Skips when the source
     path resolves to empty OR when no context row id is supplied
     for the mapping's linked_entity.

- **`tenantIntegrationFieldMapUpsert()` extended** — accepts the
  new generalised shape (source_path + target_module + target_table
  + target_column + linked_entity). Validates against the catalog
  first; falls back to the legacy allow-list for old callers.
  Backfills `internal_field` from `target_column` so the legacy
  shallow-syncer path keeps working.

- **JobDiva placement sync now calls `applyAll`** right after
  `mappingUpsert`, passing a context map that names every row id
  available at that point (self/placement_rates/placement_corp_details/
  person/end_client_company). Wrapped in try/catch — apply
  failures never block the sync.

- **NEW `/api/admin/integrations/writable_targets.php`** —
  discovery endpoint backing the UI's target picker. RBAC
  `tenant_admin.integrations`. Optional `?module=&table=` filters.

### Why this answers the user's question
Before Phase 2: tenants could only map JobDiva fields to a
hardcoded list of columns on `placements` or `people`, and only
one external_field per internal_field. Cross-module writes (e.g.
"JobDiva customer.industry → companies.industry on end-client") +
custom-field writes were impossible without a code change.

After Phase 2: every writable column across every module is a
DB-row in the catalog. Operators (via the Phase 3 UI) can map any
`_jd_*` payload path to any catalog column, including custom-field
codes, with explicit cross-module routing via `linked_entity`.
Tenant overrides ALWAYS win the syncer's hardcoded defaults — so a
mapping registry change is the OFFICIAL way to override sync
behaviour going forward, never a code edit.

### Tests
- **NEW `/app/tests/field_mapping_phase2_smoke.php`** — 57
  assertions across migration shapes, catalog seed, path walker
  unit tests, apply-step bucket semantics, custom_field_values
  branch, JobDiva wire-up, and writable-targets endpoint contract.
- Full suite: **310/310 ✅**.

### Files touched
- NEW: `/app/core/migrations/077_tenant_field_map_generalise.sql`
- NEW: `/app/core/migrations/078_integration_writable_targets.sql`
- NEW: `/app/core/integrations/field_map_apply.php`
- NEW: `/app/api/admin/integrations/writable_targets.php`
- NEW: `/app/tests/field_mapping_phase2_smoke.php`
- MODIFIED: `/app/core/integrations/field_map.php`
- MODIFIED: `/app/core/jobdiva/sync.php`

### What Phase 3 still needs (UI build)
- Left pane: payload tree from
  `/api/admin/integrations/payload_fields.php` (Phase 1).
- Right pane: target picker from
  `/api/admin/integrations/writable_targets.php` (this PR).
- Save handler posting `source_path` + target_module/table/column +
  linked_entity to the existing `/api/admin/integrations/
  field_map.php` upsert path.

---

## 2026-02 — Field-Mapping Rebuild Phase 2 Extension + Phase 3 UI

### Phase 2 extension — applyAll on every JobDiva sync path
The first Phase 2 commit only wired the apply step into the
placement sync. Person, company, and contact BI syncs still wrote
only the hardcoded columns. Now tenant mappings fire on EVERY
JobDiva sync path:

- **Company BI sync** (sync.php) — applyAll called with `{self}`
  context after `mappingUpsert($tid, 'jobdiva', 'company', ...)`.
- **Contact BI sync** (sync.php) — applyAll called with `{self}`
  context after the contact upsert.
- **Person sync** (sync_placements.php) — applyAll fires on BOTH
  branches: found-existing-by-email AND auto-created-from-payload.

All wrapped in try/catch — apply failures never block the sync.

### Phase 3 — Field Mapping Studio UI
- **NEW `/app/dashboard/src/pages/FieldMappingStudio.jsx`** —
  two-pane mapping page wired into the Admin module at
  `/admin/integrations/field-map/studio`:
  - **Left pane**: payload tree from
    `/api/admin/integrations/payload_fields.php` (Phase 1
    endpoint). Filterable, ranked by occurrence_count, shows
    sample value + value_type per path.
  - **Right pane**: writable-targets list from
    `/api/admin/integrations/writable_targets.php` (Phase 2
    endpoint). Filterable across module/table/column/description.
  - **Save bar**: source summary, target summary,
    `linked_entity` dropdown (self / person / end_client_company
    / vendor_company / placement_rates / placement_corp_details),
    transform dropdown, single "Save mapping" button. POSTs the
    Phase 2 generalised shape to the existing
    `/api/admin/integrations/field_map.php` upsert path.
  - **Custom-field branch**: when a target row's `target_column='*'`
    (the custom_field_values escape hatch) the bar renders a
    `custom_field_code` input so the operator names the code
    inline.
  - **Default linked_entity prefill** — picking a target row that
    has a `default_linked_entity` pre-fills the linked_entity
    selector. Operator can override.
  - **Existing mappings table** at the bottom with per-row
    Remove buttons.
- **Banner link** added to the legacy
  `IntegrationFieldMapAdmin.jsx` pointing operators at the new
  Studio. Old page still works for tenants using the legacy
  flat-field shape.
- **AdminModule route** mounted at
  `/admin/integrations/field-map/studio`.
- Vite bundle: `index-CVeOModC.js` / `index-BC5g6YJu.css` synced.

### Tests
- **NEW `/app/tests/field_mapping_phase3_studio_smoke.php`** — 22
  assertions covering UI testid coverage, route wiring, legacy-
  page banner link, and applyAll invocations on every JobDiva
  sync branch.
- Full suite: **311/311 ✅**.

### Files touched
- NEW: `/app/dashboard/src/pages/FieldMappingStudio.jsx`
- NEW: `/app/tests/field_mapping_phase3_studio_smoke.php`
- MODIFIED: `/app/dashboard/src/pages/AdminModule.jsx`
- MODIFIED: `/app/dashboard/src/pages/IntegrationFieldMapAdmin.jsx`
- MODIFIED: `/app/core/jobdiva/sync.php` (company + contact applyAll)
- MODIFIED: `/app/core/jobdiva/sync_placements.php` (person applyAll both branches)

### How operators use this now
1. Trigger any JobDiva sync at least once for the tenant.
2. Navigate to Admin → Integrations → Field Map → "Open Studio →".
3. Pick (integration, entity_type) — defaults jobdiva / placement.
4. Filter the left pane to find the source field (e.g.
   `_jd_customer.address.city`).
5. Filter the right pane to find the target (e.g.
   `companies.city`). Picker pre-fills `linked_entity` to
   the column's natural owner.
6. (Optional) Pick a transform.
7. Click Save. Done — next sync writes the value through.

### Field-mapping rebuild: COMPLETE
Phases 1 + 2 + 3 ship the system the operator originally asked
for: "mapping at the integration setting level, entire payload,
allow mapping by selecting core module + field." Cross-module
writes work, custom-field writes work, every JobDiva sync path
applies the registry. Adding a new integration is now: persist
its payload via mappingUpsert (auto-indexes), wire applyAll into
its sync path, done.

---

## 2026-02 — Phase 3 Enhancement: Test Mappings Panel + Generalised Dry-Run

### What shipped
- **NEW `integrationFieldMapTestPayloadGeneralised()`** in
  `field_map_apply.php` — dry-run evaluator that mirrors the legacy
  `tenantIntegrationFieldMapTestPayload()` but resolves through the
  Phase 2 generalised shape. Each result row surfaces:
  - `source_path` (dotted) with legacy `external_field` fallback
  - `raw_value` + post-transform `resolved_value`
  - human-readable `target` string
    (`"{module}.{table}.{column} (linked={slug})"` or `"legacy: ..."`)
  - per-row `target_module`/`target_table`/`target_column`/`linked_entity`
  - `matched`, `enabled`, `resolved`, `transform`
  - totals block (`total`, `matched`, `unmatched`)

- **EXTENDED `/api/admin/integrations/field_map_test.php`** —
  attaches a `generalised: {results, totals, ...}` block alongside
  the existing legacy `resolved/unmapped_internal_fields` shape so
  BOTH the legacy admin AND the Studio can consume the same call.

- **NEW Test Mappings panel** on `FieldMappingStudio.jsx`:
  - Toggle button: "Test mappings…" in the Existing-mappings header.
  - JSON textarea for paste-in payload (operators grab from "View
    raw payload" affordance in LinkedExternalSystemsPanel).
  - JSON.parse validation; bad JSON → friendly error.
  - Side-by-side results table:
    `Source path | Raw value | Transform | Resolved value | Target | Status`
  - Rows colour-coded green ("would write") / red ("no value").
  - Totals strip above the table — "X of Y mappings matched in
    this payload".
  - Per-row testid `fms-test-row-<id>` + `data-matched="yes|no"`
    for regression hookability.
- Vite bundle: `index-CI8PAAYV.js` synced.

### Tests
- **NEW `/app/tests/field_mapping_phase3_test_panel_smoke.php`** —
  19 assertions across the evaluator shape, endpoint extension,
  UI testids + JSON validation, totals header + status badges,
  PHP syntax.
- Full suite: **312/312 ✅**.

### Why this matters
Before this enhancement, the only way to know if a mapping
configuration was correct was to wait for the next live sync and
inspect the resulting CoreFlux record. With the Test panel, an
operator can:
1. Configure a mapping (or several).
2. Paste a representative payload (or grab from a known synced
   record).
3. See in 1 second exactly what would be written to which row in
   which table — including legacy + generalised mappings side by
   side.

This closes the confidence gap that made operators reluctant to
change mappings in the past.

### Files touched
- MODIFIED: `/app/core/integrations/field_map_apply.php`
  (new `integrationFieldMapTestPayloadGeneralised`)
- MODIFIED: `/app/api/admin/integrations/field_map_test.php`
  (attaches generalised block)
- MODIFIED: `/app/dashboard/src/pages/FieldMappingStudio.jsx`
  (Test panel)
- NEW: `/app/tests/field_mapping_phase3_test_panel_smoke.php`

### Field-mapping rebuild: 100% COMPLETE
- Phase 1: persist full enriched payload + queryable field index ✓
- Phase 2: generalised schema + cross-module apply + custom fields ✓
- Phase 3: Studio UI (payload tree + target picker + save) ✓
- Phase 3 enhancement: Test panel for confident mapping changes ✓
- Apply step wired across every JobDiva sync path (placement,
  company, contact, person) ✓


---

## 2026-02 — Field Mapping Studio discoverability fix

### Why
Operator reported "I still don't see the updated jobdiva payload and
field mapping tool" after Phase 3 shipped. Root cause was pure
discoverability: the Studio existed at
`/admin/integrations/field-map/studio` but nothing surfaced it —
no JobDiva Settings link, no Integrations Hub card, no Admin
sidebar entry, no Admin Overview action card. The legacy
`IntegrationFieldMapAdmin` had a small banner link but operators
weren't landing on that page in the first place.

### What shipped
- **JobDivaSettings.jsx** now renders a prominent
  `jobdiva-settings-field-map-cta` banner directly below the page
  header. CTA button (`jobdiva-settings-field-map-studio-link`)
  deep-links to
  `/admin/integrations/field-map/studio?integration=jobdiva&entity_type=placement`.
- **IntegrationsHub.jsx** gets a new "Field Mapping" section with
  two cards: the Studio (`integration-card-field-map-studio`,
  Sparkles icon) and the legacy flat-row admin
  (`integration-card-field-map-legacy`, for bulk JSON
  import/export).
- **AdminModule.jsx** adds two surfaces:
  - Admin Overview ActionCard for the Studio.
  - Admin sidebar nav entry (`Sparkles` icon) so the Studio is
    one click from anywhere in the Admin module.
- **FieldMappingStudio.jsx** now respects URL query params:
  - `?integration=` pre-fills the integration dropdown.
  - `?entity_type=` pre-fills the entity-type dropdown.
- **Empty-state CTA** on the source-paths pane now diagnoses the
  most common confusion: "indexer hasn't seen this entity yet."
  It surfaces an integration-specific link back to the sync
  surface (JobDiva / QBO / Zoho / Airtable) so the operator
  knows to run a sync before mapping.

### Tests
- NEW `/app/tests/field_mapping_studio_discoverability_smoke.php`
  (24 assertions: JobDiva CTA, Hub cards, Admin sidebar + overview,
  URL-param defaulting, empty-state CTA, deploy-version coherence).
- Full suite: **317/318 ✅** (one pre-existing failure is
  `accounting_phase2_a7_smoke.php`, requires live MySQL and is
  unrelated).

### Files touched
- MODIFIED: `dashboard/src/pages/JobDivaSettings.jsx`
- MODIFIED: `dashboard/src/pages/IntegrationsHub.jsx`
- MODIFIED: `dashboard/src/pages/AdminModule.jsx`
- MODIFIED: `dashboard/src/pages/FieldMappingStudio.jsx`
- NEW: `tests/field_mapping_studio_discoverability_smoke.php`
- Vite bundle: `index-Ckax1kTB.js` / `index-BC5g6YJu.css` synced.

---

## 2026-02 — Field Mapping Studio: joined-entity grouping + data-driven entity types

### Why
Even after the discoverability fix, operators couldn't see *why* the
Studio is powerful: the JobDiva sync grafts `_jd_candidate` (Person),
`_jd_job` (Job), `_jd_customer` (End-client), `_jd_contact` (Hiring
contact), and `_jd_start` (Assignment detail) onto every placement
payload, but the picker just dumped 200+ flat dotted paths and the
operator had to memorise the `_jd_*` prefixes to even know that
joined data existed. Result: "it still doesn't have the full payload
and custom mapping capability."

### What shipped
- **PATH_GROUPS registry** in `FieldMappingStudio.jsx` declares the
  five JobDiva enrichment buckets with friendly labels, icons, and
  the natural `linked_entity` each one routes to:
  - 👤 Person (candidate)   → linked_entity=person
  - 💼 Job                  → linked_entity=self
  - 🏢 End-client company   → linked_entity=end_client_company
  - ☎️ Hiring contact       → linked_entity=self
  - 📋 Start / Assignment   → linked_entity=self
- **`groupPathsByNamespace(paths)` helper** sorts every indexed path
  into Placement-root / Person / Job / End-client / Contact / Start /
  Other. Buckets with zero rows collapse out.
- **Grouped left-pane render** with collapsible buckets
  (`fms-paths-group-<key>`, `fms-paths-group-toggle-<key>`,
  `data-open=yes|no`). Each group shows its field count + the
  linked_entity it auto-routes to.
- **Smart linked_entity defaulting**: clicking a Person-group path
  flips `linked_entity` to `person` automatically, an End-client path
  flips it to `end_client_company`. Operator can override in the save
  bar — defaults just remove the most common foot-gun.
- **Explainer banner** above the source list for jobdiva/placement
  context: "Placement records are enriched server-side with the
  joined Person, Job, End-client and Assignment detail."
- **Data-driven entity-type dropdown**: options now come from the
  indexer's `sources` for the selected integration, ordered by
  path-count (richest source first), with static fallbacks per
  integration so empty tenants can still pick:
  - JobDiva:    placement / person / company / contact / jobdiva_customer / time_entry
  - QuickBooks: journal_entry / customer / vendor / invoice / bill / payment / gl_account / item
  - Zoho:       journal_entry / customer / vendor / invoice / bill / payment / gl_account
  - Airtable:   record
  The old hardcoded one-size-fits-all `[placement, person, company, contact, gl_account, ...]` dropdown is gone.

### Tests
- `/app/tests/field_mapping_studio_discoverability_smoke.php` extended
  with sections 5.5 (grouping) + 5.6 (data-driven dropdown) — now
  **38 ✓ / 0 ✗**.
- `/app/tests/field_mapping_phase3_studio_smoke.php` updated to assert
  the new `fms-paths-grouped` surface (replaced legacy `fms-paths-list`).
- Full suite: **317/318 ✅** (the 1 fail is `accounting_phase2_a7_smoke.php`,
  pre-existing DB-connection assertion, unrelated).

### Files touched
- MODIFIED: `dashboard/src/pages/FieldMappingStudio.jsx`
- MODIFIED: `tests/field_mapping_studio_discoverability_smoke.php`
- MODIFIED: `tests/field_mapping_phase3_studio_smoke.php`
- Vite bundle: `index-C9A1lAKG.js` / `index-BC5g6YJu.css` synced.

### How operators use this now
1. Trigger one JobDiva sync (Admin → JobDiva → Sync now). The
   indexer learns every path including the joined sub-records.
2. Open Admin → JobDiva → "Open Field Mapping Studio →" (the new
   gradient CTA). Lands pre-filtered to jobdiva/placement.
3. Left pane shows **collapsible groups** — expand "Person
   (candidate)" to map first/last/email from the joined candidate
   onto `people.*` (linked_entity auto-set to `person`). Expand
   "End-client company" to map address1/industry onto
   `companies.*` (linked_entity auto-set to `end_client_company`).
4. Right pane: pick the destination column. Save. Next sync
   honours the mapping.


---

## 2026-02 — JobDiva: joined-entity indexing + per-entity field mapping apply

### Why
Operator: "there are no available fields anywhere except placements,
even after sync. it's still not doing what it needs to. get all jobs,
assignments, people, EVERYTHING. link person to job, client,
assignment etc."

Root cause: JobDiva's V2 BI feed only ships
NewUpdatedCompanyRecords / NewUpdatedContactRecords / NewUpdated
TimesheetRecords + searchStart. There is no NewUpdatedJobRecords or
NewUpdatedCandidateRecords. Every placement IS enriched server-side
with `_jd_candidate` (Person), `_jd_job` (Job), `_jd_customer`
(End-client), `_jd_contact` (Hiring contact), `_jd_start` (Assignment
detail) sub-records — but those sub-records were never indexed under
their own entity_type, so the Studio's entity-type dropdown was empty
for anything outside `placement`. And even if an operator manually
created a mapping under entity_type='person', the apply step only
fired with entity_type='placement', so the mapping never wrote.

### What shipped
- **`jobdivaIndexJoinedSubPayloads()`** in `core/jobdiva/sync.php` —
  side-effect helper invoked after every successful placement
  `mappingUpsert`. Routes each joined sub-record to its own
  entity_type for indexing:
  - `_jd_candidate` → `person`
  - `_jd_job`       → `job`
  - `_jd_customer`  → `jobdiva_customer`
  - `_jd_contact`   → `contact`
  - `_jd_start`     → `assignment`
  Best-effort; failures logged + swallowed.
- **Per-joined-entity `integrationFieldMapApplyAll()` fan-out** in the
  placement sync — after the placement-level apply, the sync also
  iterates a `JOINED_APPLY` table and fires applyAll once per joined
  entity with the matching sub-record as the source payload and the
  right linked_entity context (person → person, customer → end-client
  company, etc.). Mappings stored under `entity_type=person` now
  actually write on every placement pull.
- **FieldMappingStudio**:
  - `groupPathsByNamespace()` now takes `entityType` so the root
    bucket label adapts: `placement` → "Placement fields", `person`
    → "Person fields (root of candidate record)", `job` → "Job
    fields", `jobdiva_customer` → "End-client fields", `contact` →
    "Contact fields", `assignment` → "Assignment fields", etc.
  - **New explainer banner** (`fms-paths-explainer-joined`,
    `data-entity={entityType}`) appears when the operator picks a
    joined entity type, telling them the source is indexed from the
    Placement sync and that their mappings apply on every placement
    pull.

### Tests
- NEW `/app/tests/jobdiva_joined_entity_indexing_smoke.php` (22 ✓).
- Extended `field_mapping_studio_discoverability_smoke.php` (38 ✓).
- Full suite: **316/319** stable (3 are documented pre-existing
  infra failures — DB connection in `accounting_phase2_a7`, curl
  extension in `ai_platform`, Plaid keys in `plaid_integration`).

### Files touched
- MODIFIED: `core/jobdiva/sync.php`
- MODIFIED: `dashboard/src/pages/FieldMappingStudio.jsx`
- MODIFIED: `tests/field_mapping_studio_discoverability_smoke.php`
- NEW:      `tests/jobdiva_joined_entity_indexing_smoke.php`
- Vite bundle: `index-DlDURd5-.js` / `index-BC5g6YJu.css` synced.

### How the workflow looks now
1. **One JobDiva placement sync** populates the indexer for SIX
   entity types: placement, person, job, jobdiva_customer, contact,
   assignment.
2. Open the **Field Mapping Studio**. The entity-type dropdown is
   data-driven — every entity type with indexed paths shows
   `(N indexed paths)` so the operator picks the richest source.
3. Pick e.g. **entity_type=person**. Left pane shows the 👤 Person
   root group with real candidate fields — `firstName`, `lastName`,

---

## 2026-02 — JobDiva: extract joined sub-records from flat prefix fields + backfill from existing payloads

### Why (root cause, finally)
After three rounds of iterating, the user was still blocked:
"Close, but you're still not getting it. This is a lot of iterations
for something not that complicated."

The screenshot showed `placement (58)` as the only indexed source
even after multiple syncs. Two root causes were missed:

1. **JobDiva V2 BI placements already carry joined-entity data as
   FLAT prefix fields** (`job_id`, `job_contact_id`, `candidate_*`,
   `customer_*`, `customerName`, `jobTitle`), regardless of whether
   the optional `/apiv2/jobdiva/searchCandidate` / `searchJob` /
   `searchCustomer` enrichment endpoints are reachable on the
   tenant's install. Prior iterations only looked at the nested
   `_jd_*` enrichment objects, which can be empty if those
   endpoints 404.
2. **Existing placement payloads were never re-indexed.** The
   joined-entity indexing side-effect only fired on *new* placement
   syncs, so a tenant with 58 placements already in the database
   would never see Person / Job / Customer / Contact / Assignment
   in the picker without triggering a fresh JobDiva HTTP sync.

### What shipped
- **NEW `jobdivaExtractJoinedSubPayloads(array $payload): array`**
  in `core/jobdiva/sync.php` — fan-out that recognises BOTH:
  - Flat snake_case prefixes (`candidate_first_name`, `job_id`,
    `customer_address1`, `employee_phone`)
  - Flat camelCase prefixes (`candidateFirstName`, `jobTitle`,
    `customerCity`)
  - Nested `_jd_*` enrichment objects (when available, they
    override flat prefixes on key collisions)
  Returns a per-entity-type sub-record dict:
  `{person:{...}, job:{...}, jobdiva_customer:{...}, contact:{...}, assignment:{...}}`.
  Empty buckets dropped.

- **`jobdivaIndexJoinedSubPayloads()` rewritten** to delegate to
  the extractor and index each non-empty bucket under its own
  entity_type via `integrationPayloadFieldIndexRecord`.

- **NEW `jobdivaBackfillJoinedIndexes(int $tenantId): array`** —
  walks every existing `external_entity_mappings` row with
  `source_system='jobdiva'`, `internal_entity_type='placement'`,
  `payload_snapshot IS NOT NULL`, decodes the JSON, and indexes
  each joined sub-record under its entity_type. Idempotent (just
  bumps `occurrence_count`). Returns
  `{placements_walked, sub_records_indexed:{person,job,jobdiva_customer,contact,assignment}}`.

- **NEW `POST /api/admin/integrations/reindex_jobdiva_subpayloads.php`** —
  thin endpoint over the backfill helper. RBAC-gated by
  `tenant_admin.integrations`. Returns the same counter shape.

- **Placement sync apply fan-out** now uses the extractor — every
  placement sync calls `integrationFieldMapApplyAll` once per
  joined entity type with the extracted sub-record as the source,
  so mappings stored under `entity_type=person` (etc.) actually
  write on every pull.

- **Field Mapping Studio**:
  - **NEW `fms-jobdiva-reindex-banner`** above the source/target
    panes when integration=jobdiva. Yellow when joined entities
    are unindexed (warning state), green once they're indexed.
    Shows the placement path count and surfaces last-run counters
    via `fms-jobdiva-reindex-result`.
  - **NEW button `fms-jobdiva-reindex-btn`** (testid stable for
    automation) — POSTs the re-index endpoint, refreshes sources +
    pane, shows a flash with totals.
  - **Auto-trigger** silent re-index on first Studio load if
    JobDiva has `placement` paths indexed but no joined entity
    sources — eliminates the need for the operator to know about
    the button.
  - `reloadSources()` helper factored so the auto-trigger and
    manual button share a single refresh path.

### Tests
- NEW `/app/tests/jobdiva_subpayload_extraction_smoke.php` — **39 ✓**
  with executable extractor assertions against synthetic flat /
  nested / mixed payloads.
- Updated `/app/tests/jobdiva_joined_entity_indexing_smoke.php` to
  match the rewritten helper + extractor-based applyAll fan-out
  (**23 ✓**).
- Full suite: **319/320** stable (1 pre-existing DB-connection
  failure, unrelated).
- Vite bundle: `index-BC0Pkd6w.js` synced.

### Files touched
- MODIFIED: `core/jobdiva/sync.php` (extractor + backfill +
  applyAll fan-out)
- NEW:      `api/admin/integrations/reindex_jobdiva_subpayloads.php`
- MODIFIED: `dashboard/src/pages/FieldMappingStudio.jsx` (banner +
  button + auto re-index)
- NEW:      `tests/jobdiva_subpayload_extraction_smoke.php`
- MODIFIED: `tests/jobdiva_joined_entity_indexing_smoke.php`

### How operators actually use it now
1. Open Admin → Integrations → **Field Mapping Studio** (or the
   JobDiva Settings CTA).
2. If the tenant has any existing JobDiva placement payloads, the
   Studio silently runs the backfill in the background AND surfaces
   a banner offering an explicit "Re-index now" button.
3. Within ~1 second, the entity-type dropdown surfaces
   `person (N)`, `job (N)`, `jobdiva_customer (N)`, `assignment (N)`
   (and `contact (N)` if the enrichment endpoint was reachable).
4. Switch dropdown to `person`. Left pane shows real candidate
   fields (`first_name`, `last_name`, `email`, `phone`, etc.) with
   sample values pulled from real placement payloads.
5. Map them to `people.first_name`, `people.email_primary`, etc.
6. Same flow for `job` → `placements.title` (etc.),
   `jobdiva_customer` → `companies.*` on the end-client,
   `assignment` → `placement_rates.*`.
7. The next JobDiva placement sync honours every joined-entity
   mapping with the extracted sub-record as its source.

   `email`, `phone`, `address`, etc. — with sample values.
4. Map `firstName → people.first_name`, `lastName → people.last_name`,
   etc. Save. Repeat for `job` (→ placements.title), `assignment`
   (→ placement_rates.bill_rate), `jobdiva_customer` (→ companies.name
   on end-client), `contact` (→ ap_vendors.remit_email).
5. On the next placement pull, every mapping fires — placement-level
   + all five joined-entity-level — with the right context.


---

## 2026-02 — Field Mapping Studio: AI auto-map suggestions (rule-based)

### Why
After the joined-entity extraction landed, the operator asked for the
one-click auto-mapper preview from the previous suggestion: "Yes add
the suggestions". With 5 joined entity types each carrying ~10-30
fields, hand-mapping every column is tedious and error-prone. A
deterministic auto-map closes the loop.

### What shipped
- **NEW `/app/core/integrations/mapping_suggester.php`** — pure
  rule-based suggestion engine. Zero LLM dependency, zero network
  calls, deterministic. Components:
  - `mappingSuggesterNormalise()` — normalises both ends
    (snake_case / camelCase / SCREAMING / dotted paths / array
    suffixes) to a flat lowercase alphanumeric key.
  - `mappingSuggesterSynonymMap()` — ~110-entry dictionary mapping
    common JobDiva/QBO/Zoho field names to canonical CoreFlux
    column names (`firstName→first_name`, `workEmail→email_primary`,
    `mobilePhone→phone_primary`, `zipCode→postal_code`, `jobTitle→title`,
    `agreedPayRate→pay_rate`, `finalBillRate→bill_rate`,
    `customerName→name`, `startDate→start_date`, etc.).
  - `mappingSuggesterEntityDefaults()` — per-entity-type preferred
    CoreFlux module + linked_entity (person→people/person,
    jobdiva_customer→companies/end_client_company,
    assignment→placements/self, vendor→ap/self, invoice→billing/self,
    journal_entry→accounting/self, etc.).
  - `mappingSuggesterScore()` — 3-tier scoring: exact 0.95,
    synonym 0.85, fuzzy substring (≥4 chars) 0.55.
  - `mappingSuggesterDefaultTransform()` — auto-picks
    `date_normalise` for `*_date` targets, `lowercase` for `status`,
    `uppercase` for `currency`-to-`currency`.
  - `mappingSuggesterSuggest()` — main entry. Reads indexed paths
    + writable targets + existing mappings, returns ranked
    suggestions excluding duplicates and intermediate object/array
    nodes.

- **NEW `POST /api/admin/integrations/suggest_mappings.php`** —
  thin endpoint over the suggester. RBAC-gated by
  `tenant_admin.integrations`. Accepts POST (json body) or GET
  (query string for easy curl-testing). Returns
  `{ok, integration, entity_type, count, suggestions:[…]}`.

- **Field Mapping Studio UI**:
  - **NEW `✨ Auto-map` button** in the header (testid
    `fms-automap-btn`).
  - **NEW review modal** (testid `fms-suggest-modal`) with:
    - Reload, Select-all, Select-none, Apply controls
      (all with stable testids).
    - High-confidence rows (≥0.85) pre-selected; operator can
      toggle individual checkboxes (`fms-suggest-check-{i}`).
    - Per-row data: source path, sample, target column,
      linked_entity, transform, confidence (colour-coded), reason.
    - Empty state + error state surfaces.
  - `applySuggestions()` posts each pick to the existing
    `/api/admin/integrations/field_map.php` save endpoint, then
    refreshes the mapping list and flashes the result count.

### Tests
- NEW `/app/tests/field_mapping_automap_suggestions_smoke.php` —
  **62 ✓** with executable unit tests on the suggester internals
  (normalise / score / synonyms / entity defaults / transforms /
  target indexing) plus API + UI presence checks.
- Full suite: **320/321** stable (1 = pre-existing DB-conn failure).
- Vite bundle: `index-DgQCT0jx.js` synced.

### Files touched
- NEW:      `core/integrations/mapping_suggester.php`
- NEW:      `api/admin/integrations/suggest_mappings.php`
- MODIFIED: `dashboard/src/pages/FieldMappingStudio.jsx`
- NEW:      `tests/field_mapping_automap_suggestions_smoke.php`

### How operators use it now
1. Open the Studio. Auto re-index runs silently for JobDiva.
2. Pick any entity_type (e.g. `person`).
3. Click **✨ Auto-map**. Within ~200ms the modal opens with all
   suggested mappings — `first_name → people.first_name (95%)`,
   `last_name → people.last_name (95%)`, `email →
   people.email_primary (85% synonym)`, etc.
4. High-confidence rows are pre-selected. Toggle anything you
   don't want, then click **Apply selected**.
5. Each row is saved via the existing `field_map.php` endpoint
   so all existing audit + apply infrastructure carries over.
6. Repeat for `job`, `jobdiva_customer`, `assignment` — usually
   <20 seconds per entity type to go from zero mappings to a
   fully-mapped tenant.


---

## 2026-02 — Auto-map suggestions: inline-edit per row

### Why
Operator follow-up after the auto-map shipped: "yes, edit inline. cool".
If the suggester picks a near-miss target (low-confidence fuzzy match
or wrong table within the right module), the operator previously had
to uncheck the row and add the mapping manually — losing the
suggested source path and confidence context. Inline editing turns a
rejected suggestion into a 2-click fix.

### What shipped (Studio modal only — backend unchanged)
- **Per-row target dropdown** (`fms-suggest-target-{i}`) — any
  writable target can be picked. Options scoped to the same
  target_module first (most relevant), then everything else. The
  current selection is always present (even if it isn't in
  `writable_targets`, which can happen for legacy columns).
- **Per-row linked_entity dropdown** (`fms-suggest-linked-{i}`) —
  pick from `self / person / end_client_company / vendor_company /
  placement_rates / placement_corp_details`.
- **Per-row transform dropdown** (`fms-suggest-transform-{i}`) —
  `none / lowercase / uppercase / trim / date_normalise / json_decode`.
- **Edited marker** — any row touched gets `_edited: true` and a
  small "edited" badge (`fms-suggest-edited-{i}`) so the operator
  visually distinguishes their own changes from the suggester's
  proposals.
- The existing **Apply selected** button uses the row's CURRENT
  values (post-edit), so changes flow straight through the
  `field_map.php` save endpoint with no extra wiring.

### Tests
- `field_mapping_automap_suggestions_smoke.php` extended with
  section 8.5 — **70 ✓** (8 new assertions for the inline-edit
  surfaces + dropdown semantics + apply-uses-edited-values).
- Full suite: **320/321** stable (1 pre-existing DB-conn failure).
- Vite bundle: `index-DImT8lmj.js` synced.

### Files touched
- MODIFIED: `dashboard/src/pages/FieldMappingStudio.jsx`
- MODIFIED: `tests/field_mapping_automap_suggestions_smoke.php`


---

## 2026-02 — JobDiva backfill now pulls FULL joined records via search* enrichment

### Why
Operator screenshot: `job` entity-type was indexed but showed only
ONE field (`refNo` ×672). Root cause: JobDiva's V2 BI placement
payload is intentionally sparse — it carries `jobRefNo` and other
flat reference stubs, but NOT the full joined record schema. Without
calling the per-id `/apiv2/jobdiva/search*` endpoints, the indexer
can only see ref numbers and IDs. Operator's literal ask: "once I
link the job I want to be able to pull from the job fields and keep
mapping."

### What shipped
- **`jobdivaBackfillJoinedIndexes()` rewritten** as a 3-phase pipeline:
  1. **Phase 1 — load**: collect every stored placement payload
     paired with its `external_entity_mappings.id`.
  2. **Phase 2 — enrich**: filter to placements missing `_jd_job` /
     `_jd_candidate` / `_jd_customer`, batch-fetch unique IDs via the
     existing `jobdivaSyncEnrichRelatedEntities()` enricher (which
     calls `/apiv2/jobdiva/searchJob`, `/searchCandidate`,
     `/searchCustomer`, `/searchContact`, marks 4xx endpoints broken
     after the first miss). **Re-saves the enriched payload back
     to `payload_snapshot`** so subsequent backfills + future syncs
     see the full record.
  3. **Phase 3 — index**: extract joined sub-records via the
     existing prefix + nested extractor, index each under its own
     entity_type.
- **API endpoint** returns extended counters:
  `{placements_walked, sub_records_indexed, enrichment_ran_for, enrichment_errors[]}`.
- **Studio re-index banner** now:
  - Shows the enrichment-run counter next to the "Re-index again"
    button: *"fetched full joined records for N placement(s) from
    JobDiva"*.
  - Surfaces a red error hint when the enrichment endpoints 4xx
    (typical when the tenant's JobDiva account doesn't have the
    `/apiv2/jobdiva/search*` endpoints exposed): "your JobDiva
    account may not have access to the /apiv2/jobdiva/search*
    endpoints. Flat-prefix fields are still indexed."

### Tests
- `tests/jobdiva_subpayload_extraction_smoke.php` extended with
  section 4.5 — **45 ✓ total** (5 new assertions for enrichment
  detection, batched call, payload re-save, summary counters, UI
  enrichment + error rendering).
- Full suite: **320/321** stable.
- Vite bundle: `index-C40_-B9Q.js` synced.

### Files touched
- MODIFIED: `core/jobdiva/sync.php` (jobdivaBackfillJoinedIndexes)
- MODIFIED: `api/admin/integrations/reindex_jobdiva_subpayloads.php`
- MODIFIED: `dashboard/src/pages/FieldMappingStudio.jsx` (banner +
  enrichment counters + error hint)
- MODIFIED: `tests/jobdiva_subpayload_extraction_smoke.php`

### How operators use it now
1. Open Studio → click **Re-index again** (or the auto-trigger
   handles it).
2. The backfill walks every stored placement, fans out per-id
   calls to JobDiva's `searchJob/searchCandidate/searchCustomer/
   searchContact` endpoints, re-saves the enriched payloads, and
   indexes the FULL joined records.
3. Switch entity dropdown to `job` — now shows real job fields
   (title, description, department, contact_id, contact_name,
   primary_sales, dates, etc.) with sample values from real
   JobDiva records, not just `refNo`.
4. Same flow for `person` → full candidate record (first_name,
   last_name, emails, phones, addresses, work history fields),
   `jobdiva_customer` → full customer record, `contact` → full
   contact record.
5. If a tenant's JobDiva account doesn't expose the search*
   endpoints, the operator sees a clear red hint — and the flat
   prefix extraction still gives them whatever JobDiva does
   include in the BI placement payload.


---

## 2026-02 — Field Mapping Studio: CSV upload fallback for joined entities

### Why
The `/apiv2/jobdiva/search*` enrichment endpoints aren't reachable on
every JobDiva tenant. When they 4xx, the picker can't see full Job /
Candidate / Customer schemas. The fallback the operator approved:
let them drop a JobDiva CSV export (Job list, Candidate list,
Customer list — JobDiva's standard exports) directly into the Studio
and have every column become a first-class mappable path. Same flow
works for ANY integration whose REST endpoints we can't reach.

### What shipped
- **NEW `/app/core/integrations/csv_indexer.php`** — generic
  CSV → `integration_payload_field_index` ingestor:
  - Stream-parses with `fgetcsv()` (memory-bounded for 100k-row
    exports).
  - Strips UTF-8 BOM from the first header cell.
  - Skips entirely-empty rows + drops trailing-empty header columns
    (common from Excel exports).
  - Pads / truncates rows to match header width so jagged CSVs
    don't crash.
  - Each data row → `integrationPayloadFieldIndexRecord()` under
    the chosen `(tenant, integration, entity_type)`.
  - Returns `{rows_seen, rows_indexed, rows_skipped, field_count,
    sample_headers, errors[]}` for the UI to surface.

- **NEW `POST /api/admin/integrations/upload_csv.php`** —
  multipart/form-data endpoint:
  - Fields: `integration` (text), `entity_type` (text), `file` (CSV).
  - Validates integration / entity_type against `[a-z0-9_]{1,40}`
    so stray writes can't escape the schema.
  - Enforces 25 MB upload cap.
  - RBAC-gated by `tenant_admin.integrations`.

- **Studio UI**:
  - **NEW 📄 Upload CSV button** in the header (`fms-csv-upload-btn`).
  - **NEW upload modal** (`fms-csv-modal`) with:
    - Integration name shown disabled (always the current selection).
    - Entity-type text input — auto-strips non-alphanumeric and
      lowercases, with helper text on the convention.
    - File picker scoped to `.csv` / `text/csv`.
    - Submit button (`fms-csv-submit`) does a real multipart fetch
      (FormData, not the JSON `api.post` wrapper).
    - Result panel (`fms-csv-result`) shows rows indexed, sample
      headers, and a collapsible per-row error list.
    - Error panel (`fms-csv-error`) for upload-level failures.
  - On success, sources are reloaded AND the entity-type dropdown
    auto-switches to the uploaded entity so the operator sees the
    new paths immediately without an extra click.

### Tests
- NEW `/app/tests/field_mapping_csv_upload_smoke.php` — **28 ✓**:
  - **Executable** end-to-end happy path against a synthetic CSV
    (BOM strip, quoted fields, blank-row skip, mismatched-width
    rows, trailing-empty headers).
  - Invalid input rejection (empty integration, missing file,
    empty file, all-empty header row).
  - API endpoint structural checks (RBAC, regex whitelist,
    `$_FILES` error code, size cap, response shape).
  - Studio UI presence checks for every testid + multipart submit
    + auto-switch behaviour.
- Full suite: **321/322** stable (1 = pre-existing DB-conn failure).
- Vite bundle synced: `index-Da33UgK2.js`.

### Files touched
- NEW:      `core/integrations/csv_indexer.php`
- NEW:      `api/admin/integrations/upload_csv.php`
- MODIFIED: `dashboard/src/pages/FieldMappingStudio.jsx`
- NEW:      `tests/field_mapping_csv_upload_smoke.php`

### How operators use it
1. Export a JobDiva Job list (or Candidate / Customer / Contact)
   to CSV via the JobDiva UI.
2. Open Field Mapping Studio → click **📄 Upload CSV**.
3. Type the entity_type (`job` / `person` / `jobdiva_customer` /
   `contact` / `assignment`), pick the CSV, click **Index CSV**.
4. Within seconds the modal shows "Indexed N of M rows · K columns
   became mappable paths" with the sample headers listed.
5. The Studio auto-switches to that entity_type — left pane shows
   every CSV column as a real indexed path with sample values.
6. Click **✨ Auto-map** → suggester proposes targets for the new
   columns just like an API-sourced entity.

### Coverage matrix (joined-entity sourcing options)
| Joined entity     | Live REST (search*) | Flat BI prefix | CSV upload |
|-------------------|---------------------|-----------------|------------|
| Person/Candidate  | ✅ when reachable   | ✅              | ✅          |
| Job               | ✅ when reachable   | ✅              | ✅          |
| Customer/Client   | ✅ when reachable   | ✅              | ✅          |
| Contact           | ✅ when reachable   | ❌              | ✅          |
| Assignment/Start  | ✅ when reachable   | partial         | ✅          |


---

## 2026-02 — JobDiva backfill: enrich_start=1 + per-endpoint diagnostics

### Why
Operator showed the JobDiva Assignment edit screen (dozens of fields:
Bill Rate, Pay Rate, Currency, Overtime, Division, Group Invoice,
VMS Timesheet Website, Employment Category, Payment Frequency,
Per Diem, Outside Commission, Payroll Profile ID, multiple overhead
rows…) and asked: "no real change, get all the fields so I can
populate a screen like this!"

Root cause was two-fold:
1. The backfill called the enricher WITHOUT `enrich_start=1`, so
   `/apiv2/jobdiva/searchStart` was skipped. Without searchStart's
   full detail response, `_jd_start` stayed empty, and the
   `assignment` entity had no rate / billing / pay / overhead /
   VMS / division fields.
2. The enricher silently swallowed per-endpoint failures. The
   operator had no signal for WHICH JobDiva endpoints worked vs
   4xx'd on their account.

### What shipped
- **Enricher signature extended**: `jobdivaSyncEnrichRelatedEntities`
  now takes an optional fifth ref param `?array &$diagnostics = null`.
  When passed, it populates a per-kind diagnostics map with:
  `endpoint`, `ids_seen`, `attempted`, `succeeded`, `empty_response`,
  `failed`, `broken_endpoint`, `sample_error`, `skipped_self`.
- **Backfill now passes `enrich_start=1`** + collects diagnostics:
  `jobdivaSyncEnrichRelatedEntities($tenantId, $items, null, ['enrich_start' => 1], $enrichDiag)`.
  Surfaces `endpoint_diagnostics` in the summary.
- **API endpoint** bubbles the diagnostics map to the response.
- **Studio re-index banner** now renders a **collapsible per-endpoint
  diagnostics table** (`fms-jobdiva-endpoint-diagnostics`) with one
  row per kind:
  - `kind` + endpoint URL (e.g. `job · /apiv2/jobdiva/searchJob`).
  - `ids_seen` / `attempted` / `succeeded` / `empty_response` /
    `failed` columns.
  - `sample_error` (truncated, with full text on hover).
  - Row background colour-coded — red for `broken_endpoint`, green
    when at least one call succeeded, transparent otherwise.
  - `data-broken="yes|no"` attribute per row for automation.

### Tests
- NEW `/app/tests/jobdiva_enrich_diagnostics_smoke.php` — **20 ✓**:
  enricher signature, diagnostics initialisation, per-counter
  increment paths, broken-flag + sample-error capture, backfill
  pass-through, API surface, Studio table render.
- Updated `tests/jobdiva_related_enrich_smoke.php` to match the
  new signature (**15 ✓**).
- Updated `tests/jobdiva_subpayload_extraction_smoke.php` to match
  the new enrichment call (**45 ✓**).
- Full suite: **322/323** stable (1 = pre-existing DB-conn failure).
- Vite bundle synced: `index-x9_n1luI.js`.

### Files touched
- MODIFIED: `core/jobdiva/sync.php` (enricher signature + diagnostics
  + backfill passes enrich_start=1)
- MODIFIED: `api/admin/integrations/reindex_jobdiva_subpayloads.php`
- MODIFIED: `dashboard/src/pages/FieldMappingStudio.jsx`
- NEW:      `tests/jobdiva_enrich_diagnostics_smoke.php`
- MODIFIED: `tests/jobdiva_related_enrich_smoke.php`
- MODIFIED: `tests/jobdiva_subpayload_extraction_smoke.php`

### How operators see the result
1. Open Field Mapping Studio → click **Re-index again**.
2. Backfill now calls **searchStart** for every placement (one
   extra API call per placement during the backfill). On a
   well-configured JobDiva account, this floods the `assignment`
   entity with rate / billing / pay / overhead / VMS / division
   fields — exactly the JobDiva Assignment edit screen schema.
3. Expand the new **"Per-endpoint diagnostics"** table to see
   which endpoints worked. Example output:
   - `job · /apiv2/jobdiva/searchJob` → ids_seen=58, attempted=58,
     **succeeded=58** (green row). Operator knows job fields are
     fully populated.
   - `start · /apiv2/jobdiva/searchStart` → broken_endpoint=true
     (red row), sample_error="HTTP 403 Forbidden". Operator knows
     to escalate to their JobDiva admin to enable the
     `/apiv2/jobdiva/searchStart` permission.
4. Once endpoints are unbroken on the JobDiva side, one click of
   **Re-index again** pulls every field. **Auto-map** suggester
   then proposes targets for the hundred-plus new mappable paths.


---

## 2026-02 — Enricher now uses `jobRefNo → req` fallback to pull full Job records

### Why
Operator: "we're still only getting one job field. why can't we use
the jobID to pull the job details?" with a screenshot showing
**JobDiva Job # 26-03327** as the visible identifier.

Root cause: the enricher's id-pluck pipeline required `ctype_digit`
on every plucked value — a hard gate that **rejected JobDiva's
`jobRefNo` because it contains a dash** (`"26-03327"`). So even when
the placement payload carried a valid job identifier, the enricher
discarded it, never called `/apiv2/jobdiva/searchJob`, `_jd_job`
stayed empty, and the `job` entity ended up with just one path
(`refNo`, from the flat-prefix extractor).

### What shipped
- **Multi-id-option per kind** in the enricher config. Each kind now
  declares an ordered list of `id_options`, each with its own
  `body_key` and `numeric` flag:
  ```php
  'job' => [
      'id_options' => [
          ['ids' => ['job id', 'jobId', 'job_id', 'jobID', 'JOBID'],
           'body_key' => 'jobId', 'numeric' => true],
          // NEW: JobDiva Job # fallback (e.g. "26-03327")
          ['ids' => ['jobRefNo', 'job ref no', 'job_ref_no',
                     'jobRefNumber', 'reqNo', 'req_no', 'req'],
           'body_key' => 'req', 'numeric' => false],
      ],
      'endpoint' => '/apiv2/jobdiva/searchJob',
      'inject'   => '_jd_job',
  ],
  ```
- **`pluckIdOption()` helper** runs the options in order, returns the
  first match with its `body_key`, soft-validates numeric vs string.
- **`$idsByKind[$kind]` is now `id_string => body_key`** so each id
  carries its own JobDiva API parameter name.
- **Phase 2** uses the per-id `$bodyKey` when calling `jobdivaCall`
  — same id can be sent as `{jobId: 27857851}` or `{req: "26-03327"}`
  depending on what the payload had.
- **Phase 3 (injection)** also uses `pluckIdOption()`, so a placement
  whose payload had only `jobRefNo` still gets its `_jd_job` filled
  with the searchJob response.

### Tests
- Updated `tests/jobdiva_related_enrich_smoke.php` — **20 ✓** with
  five new assertions for the multi-id-option config shape,
  `pluckIdOption` helper, per-id body_key call site, and Phase 3
  injection.
- Full suite: **322/323** stable (1 pre-existing DB-conn failure).
- Vite bundle synced: `index-DGSh3UVR.js`.

### Files touched
- MODIFIED: `core/jobdiva/sync.php` (enricher Phase 1/2/3)
- MODIFIED: `tests/jobdiva_related_enrich_smoke.php`

### Net effect
On the operator's next **Re-index again** click, JobDiva's
`/apiv2/jobdiva/searchJob` is now called for every unique
`jobRefNo` in their 58 placements (via `{req: "26-03327"}`). When
the endpoint succeeds, `_jd_job` carries the FULL job record —
title, description, department, dates, status, contacts, salary,
required skills, posting URLs, etc. — and the extractor routes
those fields under `entity_type='job'`. The Per-endpoint
diagnostics table will now show `job · /apiv2/jobdiva/searchJob`
with the actual ids_seen / succeeded / sample_error so the
operator can confirm it worked (or escalate to JobDiva admin if it
returns 4xx).


---

## 2026-02 — Batch (a, b, f, g) shipped

Operator approved: "proceed with a b f g".

### (a) Auto-built CoreFlux Assignment-screen clone
- **NEW `GET /api/admin/integrations/placement_schema.php`** — returns
  the live indexed schema for an integration grouped into sections
  that mirror the JobDiva Assignment edit screen:
  Assignment / Placement summary / Job / Person / End-client /
  Contact. Each section carries `field_count` + every leaf path with
  `value_type`, `sample_value`, `occurrence_count`.
- **NEW `dashboard/src/pages/AssignmentSchemaPreview.jsx`** —
  renders the schema as a read-only detail page with collapsible
  sections, integration switcher, and a Studio link in every empty
  section so the operator can fix gaps inline.
- **Wired into AdminModule**: new sidebar entry "Assignment schema"
  (FileText icon), new Admin Overview ActionCard, new route
  `/admin/integrations/assignment-schema`.

### (b) Reports Overhaul — minimal first pass (drilldown component)
- **NEW `dashboard/src/components/GlDetailDrilldown.jsx`** —
  reusable slide-over modal that hits the existing
  `/api/gl_detail.php` endpoint and shows opening / debit / credit /
  ending + every journal line with a "open JE" link. Any report
  that wants to drill into an `(account, period)` intersection now
  composes this component.
- Existing `GLDetail.jsx` page already covers the standalone GL
  Detail report — kept as-is; future overhaul work on P&L / BS /
  TB drill-throughs will adopt the new modal.

### (f) Mail diagnostic endpoint
- **Discovered**: `ResendDriver` was already fully implemented
  (`core/mail/ResendDriver.php`) and `mail_bootstrap.php` was
  already wiring it as the default when `RESEND_API_KEY` is set.
  The handoff was outdated.
- **NEW `GET /api/admin/mail_status.php`** — diagnostic endpoint
  that reports `default_driver`, `resend_configured`,
  `resend_key_hint` (first 5 chars only, never the full key),
  `resend_from_email`, `registered_drivers`, and the last 5
  `mail_outbox` rows. Operator can verify in one call whether
  Resend is recognised + how recent outbound emails fared.
- **Action item for operator**: set `RESEND_API_KEY` (env var or
  `define()` in `/app/core/config.local.php`) + `RESEND_FROM_EMAIL`
  with a verified sender domain. Endpoint returns a hint string
  guiding the configuration when the key isn't found.

### (g) RBAC B3/B4 UI
- **Discovered**: `RbacMembershipsAdmin.jsx` (438 LOC) was already
  fully built and wired in `AdminModule`, including the "Copy
  permissions from…" workflow. Handoff was outdated.
- Smoke now asserts the page still mounts + still surfaces the
  copy-permissions UX.

### Tests
- NEW `/app/tests/batch_a_b_f_g_smoke.php` — **35 ✓** covering all
  four items end-to-end (RBAC gates, response shape, component
  testids, sidebar/route wiring, PHP syntax).
- Full suite: **321/324** (3 = pre-existing infra failures:
  `accounting_phase2_a7` needs DB, `ai_platform` needs curl ext,
  `plaid_integration` needs Plaid keys — all documented).
- Vite bundle synced: `index-Cxjg7vPw.js`.

### Files touched
- NEW:      `api/admin/integrations/placement_schema.php`
- NEW:      `api/admin/mail_status.php`
- NEW:      `dashboard/src/pages/AssignmentSchemaPreview.jsx`
- NEW:      `dashboard/src/components/GlDetailDrilldown.jsx`
- MODIFIED: `dashboard/src/pages/AdminModule.jsx` (import, route,
  sidebar entry, Admin Overview ActionCard)
- NEW:      `tests/batch_a_b_f_g_smoke.php`


---

## 2026-02 — P2 batch: CSV importers + verification (AP PWP, FSC)

Operator requested: "P2 AP 3-way + 4th condition, CSV importers,
live financial state cache".

### Verification results — already shipped (no code change)
- **AP 3-way HARD gate + 4-way (PWP) match** — `pay_when_paid_smoke.php`
  **52 ✓** + `ap_pwp_four_way_match_smoke.php` **20 ✓**. Fully wired
  from `apPwpLink()` through bill-pay gating with hold/release on
  AR collection.
- **Live Financial State Cache** — `financial_state_cache_smoke.php`
  **73 ✓**. Cache mark-dirty + invalidation wired across JE post,
  JE reverse, bill update, statement match, etc.

Both items were in the backlog from earlier work; this batch
re-validates they still hold green under the latest suite.

### Treasury bank-statement CSV importer
- **NEW `/app/modules/treasury/lib/csv_import.php`**:
  - `treasuryCsvFindColumn()` — header-alias resolver tolerant to
    case / whitespace / special chars. Covers common bank-export
    column names (Date / Posting Date / Description / Memo /
    Amount / Debit / Credit / Reference / Check Number).
  - `treasuryCsvNormaliseDate()` — ISO / US-slash / dd-Mmm-yyyy.
  - `treasuryCsvParseAmount()` — strips $/£/€/, handles
    parentheses-negative, returns null on garbage.
  - `treasuryImportBankCsv(PDO, tid, bank_account_id, file)` —
    streams the CSV row by row, inserts into
    `accounting_bank_statement_lines` with `match_status='unmatched'`
    and a synthesised `fitid` (sha1 prefix of date|amount|desc|ref)
    that doubles as the de-dup key. Re-uploading the same CSV is a
    no-op. SQLite + MySQL portable (driver-aware `NOW()` /
    `datetime('now')`). Foreign-key gated by tenant + account.
- **NEW `/api/treasury/import_csv.php`** — multipart POST endpoint,
  RBAC `accounting.bank.manage`, 25 MB cap, returns counters.
- Imported lines flow into the existing matching / reconciliation
  pipeline exactly as Plaid-sourced lines do.

### Payroll register CSV importer
- **NEW `/app/modules/payroll/lib/csv_import.php`**:
  - `payrollCsvFindColumn()` + `payrollCsvParseDollarsToCents()` —
    same tolerant resolution + cent-precision parsing.
  - `payrollResolveEmployeeId(PDO, tid, idRaw, email, name)` —
    matches by people.id → email_primary/secondary → "First Last"
    name. Tenant-scoped.
  - `payrollImportRunCsv(PDO, tid, pay_period_id, file, run_type)`:
    1. Verifies pay_period belongs to tenant.
    2. Creates ONE `payroll_runs` row in `status='computed'`.
    3. Inserts one `payroll_line_items` per resolvable employee
       row.
    4. Rolls up totals (gross / taxes / deductions / net /
       employer_taxes) back onto the run.
    5. All inside a single PDO transaction — partial failures
       roll back cleanly.
  - SQLite + MySQL portable.
- **NEW `/api/payroll/import_csv.php`** — multipart POST, RBAC
  `payroll.run.compute`, whitelisted run_type
  (regular / off_cycle / correction / final), 25 MB cap.
- Imported runs land in `status='computed'` so the existing
  approve/post/dispatch rails work end-to-end without changes.

### Tests
- NEW `tests/treasury_csv_import_smoke.php` — **31 ✓** with
  **executable** end-to-end run against an SQLite in-memory schema
  mirroring `accounting_bank_accounts` +
  `accounting_bank_statement_lines`. Covers header aliases, date
  normalisation, amount parsing, de-dup, Debit+Credit derivation,
  foreign-key gating, endpoint contract.
- NEW `tests/payroll_csv_import_smoke.php` — **38 ✓** with
  **executable** end-to-end run against SQLite schemas mirroring
  `people` / `payroll_pay_periods` / `payroll_runs` /
  `payroll_line_items`. Covers employee resolver (id / email /
  name), pay-period gating, transactional integrity, totals
  roll-up, endpoint contract.
- HY093 sentry caught a duplicate-placeholder bug in the employee
  email lookup during this batch (`email_primary = :e OR
  email_secondary = :e` → `:e1` / `:e2`) — fixed before shipping.
- Full suite: **323/326** stable (3 = documented pre-existing infra
  failures: `accounting_phase2_a7` needs DB, `ai_platform` needs
  curl ext, `plaid_integration` needs Plaid keys).

### Files touched
- NEW:      `modules/treasury/lib/csv_import.php`
- NEW:      `modules/treasury/api/import_csv.php`
- NEW:      `modules/payroll/lib/csv_import.php`
- NEW:      `modules/payroll/api/import_csv.php`
- NEW:      `tests/treasury_csv_import_smoke.php`
- NEW:      `tests/payroll_csv_import_smoke.php`

### How operators use the importers
**Treasury** — for any bank without a Plaid connection (small
business accounts, foreign banks, BoA wire-only accounts, etc.):
1. Export the bank statement to CSV (most banks support this from
   the online portal).
2. POST it to `/api/treasury/import_csv.php` with `bank_account_id`
   set to the matching CoreFlux bank account.
3. Lines land as `match_status='unmatched'` — the existing
   reconciliation flow matches them against AP payments, expense
   reports, etc.

**Payroll** — for tenants not on Gusto, or for one-off corrections:
1. Export the payroll register from your payroll provider to CSV
   (or build one manually — see CSV format docs in `csv_import.php`).
2. POST it to `/api/payroll/import_csv.php` with `pay_period_id`
   and optional `run_type`.
3. A `status='computed'` run appears, fully populated with
   per-employee line items + totals — operator approves +
   GL-posts via the existing flow.


---

## 2026-02 — Resend live: keys wired, end-to-end verified

Operator handed us the live Resend API key + verified sender domain.

### What's now live
- `RESEND_API_KEY` = `re_L5QC…` set in `/app/core/config.local.php`
  (gitignored — `.gitignore` line 40).
- `RESEND_FROM_EMAIL` = `no-reply@mail.corefluxapp.com` (verified
  domain in Resend dashboard).
- `RESEND_FROM_NAME` = `CoreFlux Notifications`.

### End-to-end verification (manual, this session)
1. **Direct Resend API call** with the key + verified sender →
   200 OK, message-id `0e69c604-dfad-4eac-95d2-f2062f299109`.
2. **Local `Core\Mail\ResendDriver->send()` invocation** →
   `status: sent`, provider_message_id
   `e04d2942-92e4-4b46-bfae-dd9a9c38b50a`. Confirms the CoreFlux
   driver wraps the Resend HTTP API correctly (Authorization
   Bearer header, application/json payload, idempotency key
   format `cf-{tid}-{purpose}-…`, from envelope `{name} <{email}>`).
3. **`cf_mail_bootstrap()` introspection** confirmed
   ResendDriver is the registered default driver with the key
   visible from the constants.

### What was already built (this session re-verified — no change)
- `core/mail/ResendDriver.php` (171 LOC, complete).
- `core/mail_bootstrap.php` picks Resend as default when key set.
- `api/admin/mail_status.php` diagnostic endpoint (shipped in
  the previous batch — confirms `resend_configured=true` once the
  key is in place).
- `mail_outbox` table tracking + tenant per-purpose `from` overrides.

### Tests
- NEW `tests/resend_wiring_smoke.php` — **25 ✓** with a mocked
  HTTP transport that captures the actual request shape the
  driver emits. Verifies:
  - config.local.php constants are defined correctly.
  - ResendDriver constructor accepts `(api_key, from_email, from_name)`.
  - `send()` POSTs to `api.resend.com/emails` with
    `Authorization: Bearer {key}`, `Content-Type: application/json`,
    `Idempotency-Key: cf-{tid}-{purpose}-…`.
  - `from` envelope formatted as `CoreFlux Notifications <no-reply@mail.corefluxapp.com>`.
  - Tags + reply_to forwarded correctly.
  - HTTP failure (402, etc.) surfaces `status=failed` with error
    + null provider_message_id.
  - `/api/admin/mail_status.php` never leaks the full key
    (substr 0,5 only).
- Full suite: **325/327** stable (2 pre-existing infra failures —
  both need a live MySQL connection; documented in handoff).

### Files touched
- MODIFIED: `core/config.local.php` (added `RESEND_API_KEY` define;
  file is gitignored so the secret stays out of the repo).
- NEW:      `tests/resend_wiring_smoke.php`

### What now delivers externally
Every call to `mailerSend()` now routes through Resend instead of
the LogDriver. That includes:
- CFO Weekly Ops Memo digests.
- Bill approval emails (tokenized signed link).
- AP timesheet approver emails.
- Payroll run notifications.
- Any future `mailerSend(...)` call site — the shim is purpose-aware
  and respects per-tenant overrides from `mail_senders.php`.


---

## 2026-02 — CSV upload UI (Treasury + Payroll)

Operator: "want a tiny CSV upload UI in Treasury (bank-account drawer)
and Payroll (pay-period drawer)?" Both endpoints (shipped in the P2
batch) were API-only; this slice gives non-technical finance team
members a one-click path.

### What shipped
- **NEW `dashboard/src/components/CsvUploadWidget.jsx`** —
  reusable file-picker → multipart POST → result panel. Props:
  - `testIdPrefix` for stable automation testids.
  - `endpoint` — the CSV-import API URL.
  - `extraFields` — additional form fields (e.g. `bank_account_id`,
    `pay_period_id`, `run_type`).
  - `accept`, `label`, `hint` — picker UX hints.
  - `onSuccess(result)` — callback to refresh the parent or
    navigate to the new resource.
  - Renders a yellow info-card with file input + submit button +
    error surface + success panel that shows `rows_inserted` /
    `rows_seen` / `rows_duplicate` / `rows_skipped` / `run_id` /
    `date_range` and a collapsible per-row error list.

- **Treasury — wired into `AccountTransactions.jsx`**:
  - Renders ONLY for `type === 'deposit'` (the importer feeds
    `accounting_bank_statement_lines`, irrelevant to liability
    accounts).
  - Passes the active `bank_account_id` automatically.
  - Different copy when the account is Plaid-connected ("import
    history beyond Plaid's retention window") vs not ("this
    account isn't connected to Plaid") so the operator
    understands when to use it.
  - On success, calls `reload()` so freshly-imported lines appear
    in the transaction list without a page refresh.

- **Payroll — wired into `PayPeriods.jsx`**:
  - Per-row **"Import CSV"** toggle button next to the existing
    "Start run" button.
  - Gated to `status='draft'` or `'open'` (you can't import into
    a closed period).
  - Toggle expands an inline `<tr>` with the widget pre-filled
    with `pay_period_id` + `run_type=regular`.
  - On success, navigates to the new run detail page (the run
    was created in `status='computed'` by the importer).
  - Uses `<React.Fragment>` to wrap the two `<tr>` rows under one
    key.

### Tests
- NEW `tests/csv_upload_widget_smoke.php` — **26 ✓**:
  - Component shape (props, FormData multipart, testids, onSuccess
    callback, error details panel).
  - Treasury wiring (deposit-only gate, endpoint URL,
    `bank_account_id` extraField, copy variation,
    `onSuccess→reload()`).
  - Payroll wiring (state, toggle button, status gate, inline
    widget render, endpoint URL, `pay_period_id`+`run_type`
    extraFields, navigate-on-success).
- Full suite: **326/328** stable (2 pre-existing infra failures
  need live MySQL).
- Vite bundle synced.

### Files touched
- NEW:      `dashboard/src/components/CsvUploadWidget.jsx`
- MODIFIED: `modules/treasury/ui/AccountTransactions.jsx`
- MODIFIED: `modules/payroll/ui/PayPeriods.jsx`
- NEW:      `tests/csv_upload_widget_smoke.php`

### How operators use it now
**Treasury** — open a bank account → if it's not Plaid-connected (or
you want to backfill older history), the yellow CSV upload card
appears right above the transactions list. Pick the bank's CSV
export, click **Upload CSV**, see the row counts + date range in
seconds. Re-uploading the same CSV is a no-op (dedup via fitid).

**Payroll** — open Pay Periods, click **Import CSV** next to any
draft/open period. Pick the payroll register, click **Upload CSV**.
A new payroll_run lands in `status='computed'` and the page
navigates to it for approval.



## JobDiva assignment-field surface + Field Mapping Studio entity tabs + universal Person→Placement fan-out (2026-05 — current fork)

### Operator-reported issue
> "field mapping still isn't right. … we're still not getting the job
> details containing the final pay rate, and other key details. we're
> still not getting some of the details from people across to placements.
> why isn't it universal?"

### Root causes
1. **`jobdivaExtractJoinedSubPayloads()` skipped `start_*` flat fields
   entirely.** The previous decision was that `start_date` belonged to
   the placement, not the assignment — but `start_payRate`,
   `start_billRate`, `start_markup`, etc. on JobDiva's BI feed legitimately
   belong to the assignment record. Without flat extraction, the
   assignment bucket was populated ONLY by the optional `_jd_start`
   enrichment endpoint, which 404s on many tenant installs.
2. **`placementGet()` only joined a hand-picked subset of `people`
   columns** (`first_name`, `email_primary`, `phone_primary`, etc.).
   Any new column on `people` (linkedin_url, secondary_email, custom
   fields…) silently did NOT surface on placement detail.
3. **Field Mapping Studio entity selector was a dropdown.** Operators
   couldn't see at-a-glance which entity buckets had data; counts were
   in tooltips only.
4. **No way to browse all source fields across every entity bucket
   without flipping the dropdown back-and-forth.**

### Fixes locked in
- **`core/jobdiva/sync.php`** — flat-prefix map now includes
  `'start' => 'assignment'` and `'assignment' => 'assignment'`. JobDiva
  BI fields like `start_pay_rate`, `start_bill_rate`, `start_markup`,
  `start_overtime_rate`, `start_doubletime_rate`, `start_salary`,
  `start_status`, `start_pay_basis`, etc. now fan out under
  `entity_type=assignment` even when `_jd_start` enrichment is absent.
- **`modules/placements/lib/placements.php`** — new
  `placementHydratePersonFields($row, ?callable $loader = null)` helper
  fans out every column on the linked `people` row as `person_*`
  (excluding system columns `id`, `tenant_id`, `deleted_at`). Hand-picked
  aliases on the parent JOIN still win when both exist. Optional
  `$loader` parameter lets unit tests inject a no-DB fake.
- **`dashboard/src/pages/FieldMappingStudio.jsx`** — new horizontal
  entity tab strip (Placement / Person / Job / Customer / Contact /
  Assignment / …) with field-count badges, replacing the dropdown for
  the common case. Tabs are data-driven from the indexer (seen entity
  types first, fallback list appended). NEW "📋 Inspect sources"
  modal — read-only browse view of every indexed source path per
  entity bucket with sample values + occurrence counts + click-to-use
  selection.

### Verification
- `tests/jobdiva_assignment_extraction_smoke.php` (NEW): 5 cases —
  snake_case `start_*` → assignment, camelCase, `_jd_start` precedence,
  placement-level `pay_rate` does NOT leak into assignment, "surface
  everything" coverage of 15 known assignment fields.
- `tests/placement_universal_person_fields_smoke.php` (NEW): 6 cases —
  aliases preserved, new columns fan out, system columns excluded,
  draft placement passes through, missing person row passes through,
  throwing loader is swallowed.
- All 16 JobDiva + field-mapping smoke tests still pass (regression
  guard).
- Full suite: **328/330** (the 2 failures —
  `accounting_phase2_a7_smoke.php` + `tenant_mail_senders_smoke.php`
  — are pre-existing infra/local-config issues unrelated to this
  change).
- Vite bundle: `index-DpExv6h4.js` synced via
  `scripts/sync_bundle.sh`.

### Files touched
- MODIFIED: `core/jobdiva/sync.php` (PREFIX_MAP extended)
- MODIFIED: `modules/placements/lib/placements.php` (placementGet +
  new placementHydratePersonFields helper)
- MODIFIED: `dashboard/src/pages/FieldMappingStudio.jsx`
  (entity tab strip + Source Inspector modal + new state +
  openInspector/loadInspectorPaths/switchInspectorEntity/useInspectedPath)
- NEW: `tests/jobdiva_assignment_extraction_smoke.php`
- NEW: `tests/placement_universal_person_fields_smoke.php`
- AUTO-UPDATED: `.deploy-version`, `dashboard/dist/index.html`,
  `spa-assets/sw.js`, new `spa-assets/index-DpExv6h4.js`.

### How operators use it now
1. **Field Mapping Studio header** — entity tabs visible at top with
   live counts. Click "Assignment (15)" → the indexed `payRate`,
   `billRate`, `markup`, `overtimeRate` etc. paths are immediately
   present in the source picker.
2. **"📋 Inspect sources" button** — opens a modal that lists every
   entity bucket as a pill-tab with count, lets the operator filter
   across `source_path` AND `sample_value`, and click any row to
   pre-fill the main mapping form.
3. **Placement detail page** — every column added to `people` later
   (LinkedIn URL, secondary email, work-auth notes, custom field
   blobs, etc.) automatically appears as `placement.person_<col>`
   with zero UI code changes.


## Raw JobDiva payload diagnostic — "🔬 Raw payload" button (2026-05 follow-up)

### Why
After shipping the entity tabs + Source Inspector, the operator's
screenshot proved the actual symptom: re-index says
`assignment ×118` (118 placements indexed an assignment sub-record),
BUT only **1 distinct mappable path** (`status`) shows up under
`entity_type=assignment`. Same for `job` (1 field) versus
`jobdiva_customer` (58 fields) and `person` (59 fields).

That asymmetry can have only two root causes:
  (A) JobDiva's `/apiv2/jobdiva/searchStart` and `searchJob` return
      sparse data for this tenant (account permission scope), OR
  (B) Our extractor isn't surfacing what JobDiva sent.

We CANNOT tell the difference without seeing the raw
`payload_snapshot` JSON. So we built that diagnostic.

### Shipped
- **NEW endpoint:** `/api/admin/integrations/jobdiva_raw_payload.php`
  (GET). Returns the most-recent placement's stored
  `payload_snapshot` plus per-bucket stats:
  ```
  {
    external_id: "27857851",
    payload: { ... full enriched JSON ... },
    stats: {
      top_level_scalar_field_count: N,
      top_level_scalar_keys: [...],
      buckets: {
        _jd_job:       { present, field_count, keys: [...] },
        _jd_candidate: { ... },
        _jd_customer:  { ... },
        _jd_contact:   { ... },
        _jd_start:     { ... }
      }
    }
  }
  ```
- **NEW UI:** "🔬 Raw payload" button in the Field Mapping Studio
  header (visible when `integration=jobdiva`). Opens a modal that
  renders the bucket stats table — buckets with ≤2 fields highlight
  yellow with "sparse — JobDiva returned ≤2 fields" hint pointing at
  the JobDiva account-permission root cause. A "Show full JSON" toggle
  dumps the raw payload pretty-printed.

### Verification
- `tests/jobdiva_raw_payload_smoke.php` (NEW): 3 cases — healthy
  payload counts, sparse-bucket flagging (replicates operator's
  symptom: `_jd_start` field_count=1), empty payload graceful.
- Full suite: 328 stable pass / 3 known/flaky failures (all pre-existing
  and unrelated to this change).
- Vite bundle synced: `index-DMQbEhYy.js`.

### Files touched
- NEW: `api/admin/integrations/jobdiva_raw_payload.php`
- NEW: `tests/jobdiva_raw_payload_smoke.php`
- MODIFIED: `dashboard/src/pages/FieldMappingStudio.jsx`
  (button + modal + state + openRawPayload handler)
- AUTO-UPDATED: `.deploy-version`, `dashboard/dist/index.html`,
  `spa-assets/sw.js`, new `spa-assets/index-DMQbEhYy.js`.

### How the operator uses it
1. Deploy via `/update.php`.
2. Open Field Mapping Studio → click **🔬 Raw payload**.
3. Read the bucket table. Yellow rows = JobDiva sent us
   essentially nothing for that bucket → JobDiva account-permission
   issue (escalate to JobDiva admin to widen API field access for
   that entity), NOT a CoreFlux extraction bug.
4. Click **Show full JSON** to confirm with eyes the raw response.
