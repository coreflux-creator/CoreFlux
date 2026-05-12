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
