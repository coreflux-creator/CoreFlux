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

