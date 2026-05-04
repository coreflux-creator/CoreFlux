# CoreFlux Product Requirements Document

## Original Problem Statement
Refactor a monolithic PHP application, CoreFlux, into a modular architecture. The core application provides a standardized shell, design system, and services (auth, multi-tenancy). Each business function (Accounting, People, Finance, Payroll) is a separate, self-contained module that "plugs into" this core shell. A React SPA (`spa.php`) is the primary frontend, authenticating via the existing PHP backend.

## Tech Stack
- **Backend:** PHP 8 + MySQL (PDO). Single stack. AI calls go direct to OpenAI from PHP.
- **Frontend:** React 18 + Vite + React Router + Lucide
- **Architecture:** Modular monolith; modules developed in-repo under `/modules/<name>/`, extracted to subtree repos later
- **Hosting:** Cloudways

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
*Last Updated: 2026-02 — Accounting Phase 1 verified + AP Phase A1 shipped (Export CSVs, Gusto-CSV roll-up, AI Receipts on Expenses, Status pill / filters on the Expenses list).*


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
*Last Updated: 2026-02 — Payroll Phase A1 shipped (Gusto CSV extract, audit CSV, AI anomaly flags on `<AISuggestion />`).*

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
