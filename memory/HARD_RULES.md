# CoreFlux — Project Hard Rules

These are user-imposed hard rules. They override anything in the handoff
summary, any agent's plan, any PRD, and any auto-generated commit. If a
rule conflicts with what you read elsewhere in the repo, this file wins.

Every agent (and any future me, in a new context window) MUST read this
file before touching the codebase.

---

## R1 — DO NOT DELETE THE PRE-REACT VERSION. EVER.

The user explicitly stated:

> *"do not delete a single thing. HARD RULE. NONE OF THE PRE-REACT VERSION GETS DELETED EVER."*

Concretely, that means **the following must remain in the repository at all
times**, even if they appear unused, unreferenced, or "legacy":

- `dashboard.php` — the original PHP dashboard
- `_dashboard.php`, `_login.php`, `_index.php`, and any other `_*.php` files at the repo root
- `login.html`, `about.html`, `accounting.html`, and other static HTML pages
- `/modules/*/views/*.php` — the old PHP views inside each module folder
- `/partials/` — old PHP includes
- Any file whose mtime predates the React rebuild (before April 2026)

If a refactor *needs* a pre-React file out of the way, **rename it or move
it to `/legacy/` with a comment** — never `git rm` it. If you think a file
is truly obsolete, ASK the user before touching it. No exceptions, no
defaults, no "I'll just clean this up while I'm here."

---

## R2 — NO MODULE WORK WITHOUT EXPLICIT WRITTEN APPROVAL

The user has explicitly stated they were not yet ready to start module
work. The plan, in the user's words, has been:

> *"we were perfecting core + AI, so that when we started finalizing modules
> (starting with people) we'd be ready to wire them into core."*

Until the user writes "core is ready, start module X" in chat, **do not**:
- Build new modules
- Add new files under `/modules/<new-module>/`
- Add new module entries to `/core/modules.php`
- Add new module routes to `/dashboard/src/App.jsx`
- Add new module tiles to the sidebar / dashboard

Existing module skeletons (people, payroll, accounting, finance) may stay in
the repo (per R1) but **must not grow** without explicit approval.

---

## R3 — NO BACKEND LANGUAGE / FRAMEWORK MIGRATIONS WITHOUT APPROVAL

A previous agent built the People module's backend in PHP. The user has
since indicated that this stack choice is not what they wanted. **Do not
assume the stack for any unbuilt module.** If a module needs a backend,
ASK the user before writing a single line.

The current PHP backend stays in PHP. Removing the Python AI sidecar
(commit `1b4fa95`) was a one-time approved cleanup. No further "let's
rewrite this in X" work without explicit instruction.

---

## R4 — DECISIONS MADE IN CHAT MUST BE WRITTEN HERE

If during the course of work the user makes a decision that affects scope,
stack, architecture, or sequencing, **append it to this file as a new
rule** with the date and the user's quoted words. This is the durable
record across context windows.

---

## R5 — NO "JUST FOR YOU" UPSELL LANGUAGE

Do not nudge the user toward upgrading plans, "production-grade"
deployments, or scope expansion. Answer questions, fix problems, ship
small. Marketing-speak in chat replies is forbidden.

---

## Decisions log

| Date       | Decision                                                                 | Source                  |
|------------|--------------------------------------------------------------------------|-------------------------|
| 2026-04-26 | Python AI sidecar removed; PHP calls OpenAI directly                     | commit `1b4fa95`        |
| 2026-04-27 | Default post-login redirect changed from `dashboard.php` to `spa.php`    | This session            |
| 2026-04-27 | HARD RULE: pre-React PHP files must never be deleted                     | User chat (verbatim)    |
| 2026-04-27 | HARD RULE: no module work without explicit approval                      | User chat               |
| 2026-04-27 | The People backend was *not* approved as PHP. Stack choice is open.      | User chat               |
| 2026-04-27 | Time becomes its own module (originally "keep in People", reversed)      | User chat               |
| 2026-04-27 | Person model: single record + classification enum (w2/1099/temp/perm)    | User chat               |
| 2026-04-27 | Client is NOT a CoreFlux entity — string label only                      | User chat               |
| 2026-04-27 | Placement is its own entity. One worker → typically 1 active placement, multiple allowed. | User chat |
| 2026-04-27 | Bill/pay rates are PER PLACEMENT, effective-dated history, full audit    | User chat               |
| 2026-04-27 | Rate snapshot semantics: (b) frozen at approval. Posted entries keep their rate even if placement rate changes. | User chat |
| 2026-04-27 | Custom fields are universal — tenant can customize their instance fully  | User chat               |
| 2026-04-27 | Time module: AI extracts from inbox / individual sheets / bulk uploads / emails | User chat        |
| 2026-04-27 | Inbox: tenant has its own inbox under its own domain                     | User chat               |
| 2026-04-27 | AI may post entries (with attached docs) but downstream cash flow (AP/AR/Billing/Payroll/RevRec) ALWAYS requires explicit human approval at that layer | User chat |
| 2026-04-27 | NO auto-reply to senders. Tenant_admin gets a "Missing Timesheets" dashboard with two buckets: received-but-unreadable / expected-but-not-received | User chat |
| 2026-04-27 | Standard time categories: regular_billable, regular_nonbillable, OT_billable, OT_nonbillable, holiday, vacation, sick, bereavement, unpaid_leave (+ tenant-customizable additions) | User chat |
| 2026-04-27 | Time entries are source-of-truth feeding Payroll, AR, AP, Billing, RevRec — ALL inside CoreFlux core (huge scope) | User chat |
| 2026-04-27 | Per-placement toggle: send tokenized email approval to client manager (no login). Bulk uploads can carry "already approved" flag. | User chat |
| 2026-04-27 | People + Placements as TWO SEPARATE MODULES side-by-side in sidebar      | User chat               |
| 2026-04-27 | Placement fields require: End Client (multi-tier), W2/IC/C2C, Bill Rate, Adder %, Pay Rate, Vendor Portal Fee, Adjusted Bill Rate, Net to Vendor, Background Fee deduction, Referral Vendor + Fee + Duration, Account Manager / Lead / Recruiter + commission splits, Team Commission, Due Date, Corp details (C2C), Net Margin formula | User shared real tracker (Placement tracker aligned.xlsx) |
| 2026-04-27 | Tier 1 core scaffolding done: ModuleRegistry, central API router, RBAC + config, smoke tests (66 tests passing). Manifest extension blocked pending per-module SPEC walk. | This session  |
| 2026-02-XX | **Backend stack for ALL business modules (People, Placements, Time, Accounting, Finance, etc.) = PHP 8.** Cloudways managed hosting does not support Python or Node services without leaving the platform; all-PHP keeps deployment, RBAC, ModuleRegistry, and API router consistent. Reverses earlier ambiguity around the People stack. | User chat (this fork) |
| 2026-02-XX | **Object storage = AWS S3 for the entire platform.** Reasons: full compliance cert stack (SOC2, HIPAA-eligible BAA, PCI, FedRAMP), native lifecycle to Glacier Deep Archive for 7-yr IRS retention on tax/payroll docs, Object Lock for immutability, KMS per-tenant-key path open. Applies to People, Placements, Time, Tax, Payroll, Accounting, Finance — every module's documents go through one Core StorageService abstraction speaking the S3 API. | User chat (this fork) |
| 2026-02-XX | **Banking / SSN / EIN / tax PII encryption = application-level (PHP openssl with KMS-managed key).** DB dump alone must be useless without KMS access. | User chat (this fork) |
| 2026-02-XX | **PII access log is visible to tenant_admin** (self-serve compliance audits — SOC2-friendly), in addition to master_admin. | User chat (this fork) |
| 2026-02-XX | **Pipeline stages = hybrid model.** Fixed top-level enum (sourced/screened/submitted/interview/offer/placed/bench/terminated/rejected) + tenant-defined sub-stages underneath. Cross-tenant reporting works on top-level; tenant flexibility on sub-stages. | User chat (this fork) |
| 2026-02-XX | **Person merge action included in MVP.** Permission-gated, audit-logged. Lets tenant_admin collapse duplicate person records, preserving history from both. | User chat (this fork) |
| 2026-02-XX | **Placements decisions locked**: (1) Default commission basis = net_margin; tenants create commission *plans*, set one as default, but cannot override the basis enum globally. (2) Background fee = one-time deduction at placement start, not amortized. (3) Vendor portal taxonomy is **tenant-defined** (per-tenant table). (4) Single tenant-wide currency for now. (5) Tokenized client email approval default = OFF for new placements. (6) Multi-tier portal fees stack **additively** (sum of percents). (7) Referral duration clock starts at placement `start_date`. (8) Backdated rate corrections allowed by `placements.financials.approve` role with mandatory `correction_reason`. (9) End-client autocomplete index per tenant (UX only, not relational FK). | User chat (this fork) |
| 2026-02-XX | **Core StorageService decisions locked**: (1) Use official `aws/aws-sdk-php` SDK. (2) Single platform-wide S3 bucket with tenant path-prefix isolation; per-tenant buckets deferred to enterprise tier. (3) AV scanning deferred to Phase B but mandatory before paying customer onboarding. (4) Default region `us-east-1`. (5) Direct browser uploads via presigned POST URLs are in MVP. | User chat (this fork) |
| 2026-02-XX | **Time module decisions locked** (10 of them): (1) Inbox = read-only connector to tenant's own mail (NOT CoreFlux-hosted inbox). (2) M365 Graph + Gmail API at MVP. (3) Folder-scoped polling: tenant creates `timesheets` folder, CoreFlux only reads that. (4) Non-destructive: never modify source email; processing status only inside CoreFlux. (5) Polling architecture, 5-min default. (6) Worker UI = weekly grid. (7) Pay periods auto-generated by monthly cron. (8) Late SLA = period_end + 4 days, per-placement override. (9) Token expiry = 7 days. (10) OT auto-split at 40 hrs/week, per-employee override. | User chat (this fork) |
| 2026-02-XX | **Time module decisions locked (cont)**: (11) Holiday calendar = platform US default + tenant customizable. (12) No grace period on consumed bundles — corrections always supersede. (13) Bulk upload = fixed canonical CSV at MVP. (14) Outbound emails sent FROM tenant's own domain (`no-reply@tenantcompany.com`, etc.). Two transport options per tenant: OAuth-send via tenant's own mail, or shared Resend with tenant domain verified. Same approach for ALL outbound across all modules. Promoted to Core MailService primitive. | User chat (this fork) |
| 2026-02-XX | **Core MailService established** as a platform primitive at `/core/MailService.php`. Combined inbound (read-only connectors: M365 Graph + Gmail) and outbound (OAuth-send or Resend). All modules MUST go through MailService — no direct SMTP/IMAP/Graph/Gmail/Resend calls in module code. | User chat (this fork) |
| 2026-02-XX | **Finance is a sidebar-grouping label, not a module.** Under it sit four real modules: `billing/` (AR/invoicing), `ap/` (vendor payables), `payroll/` (W2 payroll), `accounting/` (GL engine). Each owns its own tables, manifest, RBAC, and SPEC. They share posting protocols (subledgers → Accounting). | User chat (this fork) |
| 2026-02-XX | **Billing module = AR**: invoices, recurring services, billing rules per placement/client/service, payment tracking, AR aging, dunning schedules, credit/debit memos, tax matrix. Consumes `time_downstream_feed.bundle_type='ar'`. AI describes/humans decide. PDFs in S3, emails via MailService from tenant domain. | User chat (this fork) |
| 2026-02-XX | **AP module = vendor payables**: vendor invoice intake (via Core MailService inbox), 1099/C2C contractor pay, employee expense reports, payment runs, 1099-NEC ledger. Consumes `time_downstream_feed.bundle_type='ap'`. Vendor tax IDs encrypted at app layer. | User chat (this fork) |
| 2026-02-XX | **Payroll module = W2 only.** Engine-swappable interface (`PayrollEngine`): MVP in-house basic engine; **future Phase C: Check HQ or Gusto adapter via API**. Deterministic — AI never in calc path. Pay stubs in S3 with Object Lock 7yr per StorageService policy. Two-eye: build ≠ approve ≠ disburse. Single-state at MVP, multi-state Phase B. | User chat (this fork) |
| 2026-02-XX | **Accounting module = GL engine of record** (chart of accounts, journal entries immutable, period close, trial balance/P&L/BS/cash flow, bank rec, multi-currency at JE level). Subledgers (Billing/AP/Payroll) post into Accounting. Posting always accrual; cash-basis is reporting toggle. | User chat (this fork) |
| 2026-02-XX | **Accounting v1.0 scope expanded** (per user-supplied product brief). Multi-entity is FOUNDATIONAL (entities, groups, ownership tables, per-entity COA / fiscal calendars / periods). Allocations advanced (fixed-pct / driver-based / financial / statistical / step-down / reciprocal). Intercompany + Consolidation are v1.0 (full / proportionate / equity-method, CTA, NCI, eliminations, pre/post views). Dimensions first-class with account-required, security, allowed combos. Multi-currency with FX rate types (spot/closing/average/historical), revaluation, CTA. Period close = workflow (tasks, owners, due dates, packets), not date lock. Approvals threshold/account/entity/dimension/je_type-based with maker/checker SoD. Bank rec moved up to v1.0 (was Phase B). Audit richer (before/after diffs, IP, request metadata, cross-app request id). | User-supplied Accounting v1.0 brief |
| 2026-02-XX | **Accounting posting protocol = THE contract** for all subledgers. `POST /api/v1/accounting/journal-entries` requires `idempotency_key` for system-generated posts, `source_module`, `source_ref_type/id`, `external_ref`. Outbound HMAC-signed webhooks on `je.posted`, `je.reversed`, `period.closed`, `period.reopened`, `consolidation.complete`. No subledger writes directly to GL tables. | User-supplied Accounting v1.0 brief |
| 2026-02-XX | **Excluded from Accounting SPEC** (per user direction): the source brief's "micro-frontend UX (separate domain, custom element)" and "SFTP-only deployment" sections. Accounting is built inside the current CoreFlux core platform like any other module. | User chat (this fork) |
| 2026-02-XX | **Accounting v1.0 decisions locked**: (1) Starter COA = `generic` + `staffing_services_us`. (2) Approval rules evaluate as multi-level chain (all matching rules fire in `level` order). (3) External integrations: CSV import/export covering ALL ledgers in v1.0; QuickBooks + Wave next priorities (Phase 1.1); Xero removed from priority list. (4) Cash flow = indirect method only at v1.0; direct method deferred. | User chat (this fork) |
| 2026-02-XX | **Accounting v1.0 round 2 locks**: (5) Statistical accounts kept in COA, excluded from TB/P&L/BS, used as allocation drivers. (6) Webhook delivery retention = 7 years (IRS-aligned). (7) Reopen-period guardrails: only most-recent closed period reopenable by default; tenant_admin override w/ reason + extra approval for older. (8) Maker/checker is a tenant setting, **default OFF** for new tenants. | User chat (this fork) |
| 2026-02-XX | **Core MailService decisions locked**: (1) Inbox = read-only M365 + Gmail connectors at MVP. (2) Single shared CoreFlux Resend account; tenants verify their domain under our org. (3) UI surfaces OAuth-send daily-cap warnings (M365/Gmail ~10k/day). (4) Folder names tenant-chosen, mapped to module — NOT mandated. (5) Polling default 60 min, 1–60 configurable. (6) `mail_outbox` body retention 90 days then truncate (metadata kept). | User chat (this fork) |
| 2026-02-XX | **Billing decisions locked**: invoice numbering tenant-customizable template; customer portal Phase A (tokenized signed link + email-attached PDF + optional supporting attachments); Stripe payment acceptance Phase B; statement of account tenant-configured cadence; PO matching = soft warn; Avalara/TaxJar tax engine Phase B. | User chat (this fork) |
| 2026-02-XX | **AP decisions locked**: mail intake table per module (NOT shared at core); outbound payments via full bank integration Phase B (Plaid Transfer primary, Stripe ACH alternate); 1099 forms generated MVP, e-file Phase B; three-way match in MVP (soft warnings); card import via Plaid Phase B; vendor portal Phase B. | User chat (this fork) |
| 2026-02-XX | **Payroll decisions locked**: tax tables platform-managed centrally; full ACH/DD bank integration via Plaid; multi-state workers at MVP; reciprocal agreements auto-applied; garnishment ordering hardcoded federal (IRS levy > child support > creditor); 401k employer match deferred; Check HQ / Gusto adapter Phase B (high priority); pay card deferred (no near-term phase). | User chat (this fork) |
| 2026-02-XX | **Bank integration vendor (Plaid / Stripe / Modern Treasury / Dwolla / direct bank API) = DEFERRED** to Phase B kickoff. Code abstracts behind a `PaymentRailsDriver` interface so the choice is swappable. Applies to AP outbound payments, Payroll direct deposit, AP card import, and Accounting bank statement import. | User chat (this fork) |
| 2026-02-XX | **White-labeling is a Core platform primitive** at `/core/BrandingService.php`. Tenants get `<slug>.corefluxapp.com` subdomain (custom domains Phase B), upload a logo, and the system extracts a color palette via k-means clustering of the logo image. Tenant overrides any palette slot. Logo replaces CoreFlux logo wherever it would appear (sidebar, login, emails, PDFs); "Powered by CoreFlux" footer stays locked across all surfaces. Slug auto-suggested from tenant name, editable, changeable later with 90-day redirect grace. Subdomain-only at MVP; custom-domain (`acme.com` via CNAME + per-tenant SSL) Phase B. | User chat (this fork) |
| 2026-02-XX | **Phase 3b shipped as "skinny" MailService.** `Core\MailService` + `MailDriver` interface + `LogDriver` (dev-only no-op sender) + 4 DB tables (`tenant_mail_connections`, `tenant_mail_folders`, `mail_messages_seen`, `mail_outbox`) + 38 smoke tests. NO real provider drivers (M365 / Gmail / Resend / IMAP) wired yet — those land when Time module is built or when first outbound email is needed. Azure AD app registered (multitenant, App ID `d5d81312-...`, secret + permissions deferred until real driver is wired). | User chat (this fork) |
| 2026-02-XX | **Storage stays on AWS S3** (revisit deferred). Cloudways infra is on AWS underneath; egress to Azure Blob would double-charge. StorageService driver abstraction means the choice is reversible later via a 1-day `AzureBlobDriver` swap if hosting moves to Azure App Service. | User chat (this fork) |

---

*This file is the source of truth. Update it when the user makes a new
decision. Do not delete entries — only append.*
