# Time — Module Specification

**Status**: DRAFT — pending user sign-off
**Stack**: PHP 8 (per HARD_RULES decisions log, 2026-02)
**Owner module of**: time entries — every billable, non-billable, and absence hour worked across all placements.
**Source-of-truth for**: AR billing, AP vendor pay, payroll runs, RevRec calculations.
**NOT owner of**: rates (lives in `placements/`), people records (lives in `people/`), invoices/payroll runs (lives in `accounting/`).

This SPEC is written so the module can be re-created from scratch. It is the source of truth.

---

## 1. Purpose

Time captures **what hours were worked, on which placement, in which category**, and produces the deterministic, audit-locked dataset that flows downstream into Payroll, AR, AP, Billing, and RevRec — **all inside CoreFlux core** (per HARD_RULES R-2026-04-27, large-scope decision).

Three intake paths feed Time:

1. **AI inbox monitoring** — tenant has its own inbox under its own subdomain; AI parses incoming timesheet emails (PDF, image, embedded, structured).
2. **Bulk uploads** — CSV / spreadsheet uploads, optionally pre-approved.
3. **Individual entries** — direct entry by the worker or admin in the React UI.

**Hard rule (HARD_RULES R-2026-04-27)**: AI may *post* time entries (with attached source docs), but downstream cash flow (AP/AR/Billing/Payroll/RevRec) **always** requires explicit human approval. AI describes; humans decide.

---

## 2. Core principles (locked by HARD_RULES)

1. **Rate snapshot at approval (option b)** — when a time entry is approved, it stores the `placement_rates.id` that was effective on its `work_date`. Subsequent rate changes do not retroactively affect the entry. Margin/billing/payroll math uses the snapshot.
2. **Standard time categories** (HARD_RULES, exact list):
   - `regular_billable`
   - `regular_nonbillable`
   - `OT_billable`
   - `OT_nonbillable`
   - `holiday`
   - `vacation`
   - `sick`
   - `bereavement`
   - `unpaid_leave`
   - Tenant may add custom categories under any top-level bucket (`billable`, `nonbillable`, `pto`, `unpaid`).
3. **Per-tenant inbox under tenant subdomain** — e.g. `time@acme.coreflux.app`. AI owns parsing.
4. **No auto-reply to senders.** Tenant_admin instead gets a "Missing Timesheets" dashboard with **two buckets**:
   - **Received-but-unreadable** — AI got an email but couldn't extract anything actionable.
   - **Expected-but-not-received** — based on active placements with expected timesheet cadences, no email has arrived for the period.
5. **Per-placement toggle** — tokenized email approval to client manager (no login needed). Bulk uploads can carry an `already_approved` flag (from clients who already signed off out of band).
6. **AI never writes downstream financial records.** AI can post draft time entries; AR/AP/payroll/RevRec require human approval at their own layer.
7. **Documents (timesheets, signed PDFs, source emails) live in S3** via Core StorageService under `time/{tenant_id}/...`.

---

## 3. Data model

### 3.1 `time_periods` (configurable per tenant — weekly default)

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `tenant_id` | BIGINT FK | |
| `period_type` | ENUM('weekly','biweekly','semimonthly','monthly') | tenant default; per-placement override allowed via `placements.timesheet_cadence` (future) |
| `start_date` | DATE | inclusive |
| `end_date` | DATE | inclusive |
| `label` | VARCHAR(40) | e.g. `2026-W08`, `2026-02-A`, `2026-02` |
| `status` | ENUM('open','locked','closed') | `closed` = no further entries accepted; AR/AP feeders read from closed periods |
| `closed_at` | DATETIME NULL | |
| `closed_by_user_id` | BIGINT NULL FK | |

Indexes: `(tenant_id, start_date, end_date)` UNIQUE, `(tenant_id, status)`.

### 3.2 `time_entries` (the atomic record)

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `tenant_id` | BIGINT FK | |
| `placement_id` | BIGINT FK→`placements.id` | |
| `person_id` | BIGINT FK→`people.id` | denormalized from placement for query speed |
| `period_id` | BIGINT FK→`time_periods.id` | |
| `work_date` | DATE | |
| `category` | ENUM('regular_billable','regular_nonbillable','OT_billable','OT_nonbillable','holiday','vacation','sick','bereavement','unpaid_leave','custom') | |
| `custom_category_id` | BIGINT NULL FK→`tenant_time_categories.id` | when category='custom' |
| `hours` | DECIMAL(6,2) | |
| `description` | VARCHAR(500) NULL | task / project notes |
| `source` | ENUM('ai_inbox','bulk_upload','manual_entry','client_portal_paste') | provenance |
| `source_ref_id` | BIGINT NULL | FK to `time_intake_events.id` for ai_inbox / bulk_upload |
| `status` | ENUM('draft','pending_review','approved','rejected','superseded') | |
| `rate_snapshot_id` | BIGINT NULL FK→`placement_rates.id` | LOCKED at approval; NULL while draft/pending |
| `approved_by_user_id` | BIGINT NULL FK | |
| `approved_at` | DATETIME NULL | the snapshot moment |
| `approved_via` | ENUM('manual','tokenized_client_email','bulk_pre_approved') NULL | how approval came in |
| `client_approver_email` | VARCHAR(255) NULL | when approved_via='tokenized_client_email' |
| `superseded_by_id` | BIGINT NULL FK→`time_entries.id` | corrections create new rows, point old at new |
| `correction_reason` | VARCHAR(500) NULL | required when superseded |
| `created_by_user_id` | BIGINT NULL FK | NULL when source='ai_inbox' |
| `created_at` | DATETIME | UTC |
| `updated_at` | DATETIME | |

Indexes: `(tenant_id, period_id, status)`, `(tenant_id, placement_id, work_date)`, `(tenant_id, person_id, work_date)`, `(status, source)` for review queue.

Constraint: a row in `approved` status MUST have `rate_snapshot_id`, `approved_by_user_id`, `approved_at` set. Once approved, `hours`, `category`, `work_date` are immutable — corrections happen via supersede.

### 3.3 `tenant_time_categories` (per-tenant additions)

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `tenant_id` | BIGINT FK | |
| `code` | VARCHAR(40) | snake_case |
| `label` | VARCHAR(120) | display |
| `parent_bucket` | ENUM('billable','nonbillable','pto','unpaid') | rolls up to one of four buckets for downstream feeders |
| `is_overtime` | BOOLEAN | drives multiplier in payroll |
| `active` | BOOLEAN | |

### 3.4 `time_intake_events` (every inbound email or bulk upload)

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `tenant_id` | BIGINT FK | |
| `intake_type` | ENUM('email','bulk_upload','client_portal','manual_paste') | |
| `subject` | VARCHAR(500) NULL | for email |
| `from_email` | VARCHAR(255) NULL | |
| `to_inbox` | VARCHAR(255) NULL | tenant's own folder address that received it (e.g. `timesheets@tenantcompany.com`) |
| `mail_provider` | ENUM('m365','google','imap','manual_paste') NULL | which connector source |
| `provider_message_id` | VARCHAR(255) NULL | RFC822 Message-ID from source mail system; for dedupe + back-link |
| `received_at` | DATETIME | |
| `raw_storage_object_id` | BIGINT NULL FK→`storage_objects.id` | full email/EML or uploaded CSV file in S3 |
| `attachments_json` | TEXT NULL | array of storage_object_ids for parsed attachments |
| `parser_status` | ENUM('queued','processing','parsed','partial','unreadable','error') | |
| `parser_error` | TEXT NULL | |
| `ai_model_used` | VARCHAR(80) NULL | e.g. `gpt-5.4`; the AI service identifier |
| `ai_extraction_json` | LONGTEXT NULL | full AI structured output for audit/replay |
| `entries_created_count` | INT | number of `time_entries` rows produced |
| `linked_placement_ids_json` | TEXT NULL | inferred or matched placements |
| `expected_period_id` | BIGINT NULL | best-guess pay period match |
| `disposition` | ENUM('pending_review','converted','dismissed','flagged_unreadable') | |
| `disposition_by_user_id` | BIGINT NULL FK | |
| `disposition_at` | DATETIME NULL | |

This table powers BOTH the AI review queue AND the "Missing Timesheets — received-but-unreadable" bucket of the dashboard.

### 3.5 `time_expected_submissions` (drives "expected-but-not-received" bucket)

Computed/maintained per active placement per period. A row exists for every (placement, period) pair where a timesheet is expected.

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `tenant_id` | BIGINT FK | |
| `placement_id` | BIGINT FK | |
| `period_id` | BIGINT FK | |
| `expected_by` | DATETIME | when the timesheet should arrive (e.g. period_end + 2 days) |
| `status` | ENUM('expected','received','received_unreadable','not_expected') | |
| `linked_intake_event_id` | BIGINT NULL FK | |
| `linked_entries_count` | INT | |

Indexes: `(tenant_id, status, expected_by)`, `(tenant_id, placement_id, period_id)` UNIQUE.

### 3.6 `time_approval_tokens` (tokenized client email approval — per-placement opt-in)

When `placements.tokenized_email_approval_enabled = true` (default OFF per HARD_RULES), the system can email a client manager a one-tap approve/reject link without requiring login.

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `tenant_id` | BIGINT FK | |
| `placement_id` | BIGINT FK | |
| `period_id` | BIGINT FK | |
| `client_approver_email` | VARCHAR(255) | snapshot from placement.approval_contact at issuance |
| `token` | VARCHAR(96) UNIQUE | URL-safe random; long enough to brute-force-resist |
| `token_hash` | VARBINARY(64) | hashed copy stored alongside, only hash compared at verify |
| `entries_json` | LONGTEXT | snapshot of entry IDs + summary at issuance |
| `entries_total_hours` | DECIMAL(8,2) | for the email body |
| `issued_at` | DATETIME | |
| `expires_at` | DATETIME | typically 7 days |
| `responded_at` | DATETIME NULL | |
| `response` | ENUM('pending','approved','rejected','expired','revoked') | |
| `responder_ip` | VARCHAR(45) NULL | audit |
| `responder_user_agent` | VARCHAR(255) NULL | audit |
| `revoked_by_user_id` | BIGINT NULL FK | |

Security: token transmitted in URL; verified by hash compare; one-time use (status flips on response). All issuances and verifications audit-logged.

### 3.7 `time_downstream_feed` (the handoff to AR/AP/Payroll/RevRec)

One row per (period, placement) representing a "ready bundle" of approved entries. Downstream modules consume from here. Once consumed (e.g. an invoice is generated), the bundle is locked.

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `tenant_id` | BIGINT FK | |
| `period_id` | BIGINT FK | |
| `placement_id` | BIGINT FK | |
| `bundle_type` | ENUM('ar','ap','payroll','revrec') | one bundle per downstream consumer per (period, placement) |
| `entries_json` | LONGTEXT | snapshot of entry_ids + hours by category |
| `rate_snapshot_id` | BIGINT FK→`placement_rates.id` | the rate locked at approval time |
| `total_hours_billable` | DECIMAL(10,2) | |
| `total_hours_nonbillable` | DECIMAL(10,2) | |
| `total_hours_pto` | DECIMAL(10,2) | |
| `total_amount_bill` | DECIMAL(12,2) | bill_rate * billable_hours (snapshot math) |
| `total_amount_pay` | DECIMAL(12,2) | pay_rate * billable_hours (snapshot math) |
| `status` | ENUM('ready','consumed','locked','superseded') | |
| `consumed_at` | DATETIME NULL | |
| `consumed_by_module` | VARCHAR(40) NULL | e.g. `accounting.invoice`, `accounting.payroll_run` |
| `consumed_ref_id` | BIGINT NULL | id of the downstream record (invoice id, payroll run id, etc.) |
| `created_at` | DATETIME | |

Rule: a downstream module **MUST** mark consumption (`status='consumed'`, `consumed_ref_id`) when it pulls a bundle, and **MUST** request a fresh bundle if the underlying entries are corrected post-consumption. Corrections produce a new bundle with `status='superseded'` linkage.

### 3.8 Relationships diagram (ASCII)

```
time_periods 1───*  time_entries
time_entries *───1  placements (FK placement_id)
time_entries *───1  placement_rates (FK rate_snapshot_id, LOCKED at approval)
time_entries *───?  time_intake_events (FK source_ref_id)
time_entries *───?  tenant_time_categories (FK custom_category_id)

time_intake_events 1───*  storage_objects (raw email + attachments)

time_expected_submissions *───1 time_periods
time_expected_submissions *───1 placements

time_approval_tokens *───1 time_periods
time_approval_tokens *───1 placements

time_downstream_feed *───1 time_periods
time_downstream_feed *───1 placements
time_downstream_feed *───1 placement_rates  (snapshot)
```

---

## 4. Permissions (RBAC)

| Slug | Description |
|---|---|
| `time.view` | View own entries / aggregated tenant view (gated by sub-perms) |
| `time.entry.self` | Submit own time entries (the worker themselves) |
| `time.entry.manage` | Create / edit entries on behalf of others (admin / staffing coordinator) |
| `time.review` | Work the AI/intake review queue |
| `time.approve` | Approve entries (snapshot-lock the rate). **Two-eye control: separate from `time.entry.manage`.** |
| `time.reject` | Reject entries (with reason) |
| `time.bulk_upload` | Run bulk uploads, optionally with `already_approved` flag |
| `time.tokenized_email.issue` | Issue client-approval tokens |
| `time.tokenized_email.revoke` | Revoke tokens before use |
| `time.period.close` | Close a pay period |
| `time.feed.consume` | Reserved — downstream modules use this to consume bundles (system role, not user role) |
| `time.dashboard.missing` | View Missing Timesheets dashboard |
| `time.categories.manage` | Define tenant custom categories |
| `time.audit.view` | View time audit log |

Default roles: `master_admin`, `tenant_admin`, `admin`. Worker-self permission (`time.entry.self`) granted to all users with active placements.

---

## 5. API surface

All under `/api/time/...` via central router.

### 5.1 Entries
- `GET /api/time/entries` — filters: `period_id`, `placement_id`, `person_id`, `status`, `source`, `category`. Pagination.
- `POST /api/time/entries` — create draft or pending_review entry. Body: `placement_id`, `work_date`, `category`, `hours`, `description`.
- `PATCH /api/time/entries/{id}` — only allowed in `draft` / `pending_review` / `rejected` statuses.
- `POST /api/time/entries/{id}/submit` — move draft → pending_review.
- `POST /api/time/entries/{id}/approve` — pending_review → approved; locks `rate_snapshot_id`. Gated by `time.approve`.
- `POST /api/time/entries/{id}/reject` — body `{reason}`; pending_review → rejected.
- `POST /api/time/entries/{id}/correct` — creates a new entry, marks old as `superseded`, requires `correction_reason`. New entry inherits the old `period_id` and undergoes full approval flow.

### 5.2 Bulk
- `POST /api/time/bulk/upload` — multipart with CSV/XLSX; body flags: `already_approved` (requires `time.bulk_upload` AND `time.approve`).
- `GET /api/time/bulk/{intake_id}` — status of a bulk job.

### 5.3 AI inbox / intake (read-only connector model)

CoreFlux does NOT host tenant inboxes. It reads from the tenant's own mail system (Microsoft 365 or Google Workspace) on a poll. See `/app/core/MailService.SPEC.md` for the connector primitive.

- `POST /api/time/inbox/poll` — system endpoint invoked by cron (per tenant); MailService pulls new messages from the tenant's configured `timesheets` folder, creates a `time_intake_events` row per message, stores raw EML + attachments in S3, queues parser. **Does NOT modify the source email** (no read flag, no move, no label).
- `GET /api/time/intake` — paginated list with `disposition`, `parser_status` filters.
- `GET /api/time/intake/{id}` — detail incl. AI extraction JSON, attachments, proposed entries, link back to source email metadata (message-id, sent-at, from, subject).
- `POST /api/time/intake/{id}/convert` — turn AI proposal into pending_review entries (operator may edit before converting).
- `POST /api/time/intake/{id}/dismiss` — body `{reason}`; marks event as dismissed (e.g. spam, not a timesheet).
- `POST /api/time/intake/{id}/flag-unreadable` — feeds the Missing Timesheets dashboard "received-but-unreadable" bucket.

### 5.4 Periods
- `GET /api/time/periods` — list.
- `POST /api/time/periods/{id}/close` — close period; gated by `time.period.close`. Auto-rebuilds `time_downstream_feed` bundles for all approved entries in the period.
- `POST /api/time/periods/{id}/reopen` — only if no consumed bundles exist; otherwise a corrections flow is required.

### 5.5 Tokenized client approval
- `POST /api/time/approval-tokens/issue` — body `{period_id, placement_id, entry_ids[]}`; validates placement has `tokenized_email_approval_enabled=true`; creates token, sends email via configured provider. Gated by `time.tokenized_email.issue`.
- `POST /api/time/approval-tokens/{token}/respond` — **PUBLIC** endpoint (no auth); body `{action: 'approve'|'reject', note}`. Verifies hash, marks entries via `approved_via='tokenized_client_email'`. Captures IP + user agent. One-time use.
- `POST /api/time/approval-tokens/{id}/revoke` — gated by `time.tokenized_email.revoke`.

### 5.6 Missing Timesheets dashboard
- `GET /api/time/dashboard/missing` — returns two buckets: `received_unreadable` (from `time_intake_events` where `disposition='flagged_unreadable'`) and `expected_not_received` (from `time_expected_submissions` where `status='expected' AND expected_by < now()`). Gated by `time.dashboard.missing`.

### 5.7 Downstream feed (consumed by AR/AP/Payroll/RevRec)
- `GET /api/time/feed?bundle_type=ar&period_id=...` — system endpoint for downstream modules.
- `POST /api/time/feed/{id}/consume` — body `{consumed_by_module, consumed_ref_id}`; flips status. Gated by `time.feed.consume` (system role).

### 5.8 Reports
- `GET /api/time/reports/by-placement` — period totals per placement.
- `GET /api/time/reports/by-person` — period totals per person.
- `GET /api/time/reports/utilization` — billable / nonbillable / PTO ratios.
- `GET /api/time/reports/missing` — exportable version of dashboard.

---

## 6. UI / sidebar actions

Manifest actions:

| Route | Label | Permission |
|---|---|---|
| `entries` | My Time | `time.entry.self` |
| `review` | Review Queue | `time.review` |
| `inbox` | Inbox (AI) | `time.review` |
| `bulk` | Bulk Upload | `time.bulk_upload` |
| `missing` | Missing Timesheets | `time.dashboard.missing` |
| `periods` | Pay Periods | `time.period.close` |
| `reports` | Reports | `time.view` |

### Detail screens
1. **My Time** — calendar view of own entries by week.
2. **Review Queue** — pending_review entries grouped by source (ai_inbox / bulk_upload / manual_entry); inline approve / reject / edit.
3. **Inbox** — `time_intake_events` list; click into one to see raw email, attachments, AI extraction, proposed entries; convert / dismiss / flag-unreadable.
4. **Missing Timesheets** — two-tab dashboard (received-unreadable / expected-not-received).
5. **Period** — entries grouped by placement → person; bulk approve in-period; close period when ready.
6. **Token Issuance** — per-placement panel showing pending entries → "Send for client approval" button (only enabled if placement has the toggle ON).
7. **Reports** — utilization, by-placement, by-person.

---

## 7. AI usage (CORE to this module)

### 7.1 What AI does
- **Email triage** — classify inbound email as timesheet vs. not.
- **Extraction** — parse PDF / image / spreadsheet / inline-text into structured entries: `{placement_match_signal, work_date, category, hours, description}` per row.
- **Placement matching** — match the worker (by sender email, name, signature) and the client/end-client to one of their active placements. Confidence score returned.
- **Period inference** — infer which `time_periods.id` the timesheet belongs to.
- **Anomaly flagging** — hours > 80/week, work_date outside placement window, negative hours, duplicates.

### 7.2 What AI MUST NOT do (HARD_RULES)
- Approve entries.
- Skip the review queue. Every AI-extracted entry lands in `pending_review`.
- Auto-reply to senders. (Period — there is no auto-reply path. Senders learn the status implicitly via the tenant's response or via the Missing Timesheets workflow.)
- Touch `time_downstream_feed`.
- Modify `placement_rates`.

### 7.3 Provider
- Direct PHP cURL to OpenAI (per HARD_RULES — no Python sidecar).
- Model: configurable per tenant; default `gpt-5.4`. The model id used is recorded on every `time_intake_events` row for auditability and replay.
- Tenant supplies their own OpenAI API key (HARD_RULES).
- Prompts versioned in `/core/prompts/time/*.txt`; prompt version recorded with each AI invocation.

### 7.4 Replay-ability
- Raw email + attachments stored permanently in S3 under `time/{tenant}/raw/`.
- AI extraction JSON stored permanently on `time_intake_events.ai_extraction_json`.
- Combined: any AI decision is reproducible / re-runnable with a different model / prompt version.

---

## 8. Audit events

- `time.entry.created` / `.updated` / `.submitted` / `.approved` / `.rejected` / `.superseded`
- `time.entry.approved` carries `rate_snapshot_id`, `approved_via`, optional `client_approver_email`
- `time.intake.received` (every inbound email or upload)
- `time.intake.parsed` / `.unreadable` / `.error`
- `time.intake.converted` / `.dismissed` / `.flagged_unreadable`
- `time.bulk.uploaded` (carries `entries_count`, `pre_approved` flag)
- `time.token.issued` / `.responded` (with IP, UA) / `.revoked` / `.expired`
- `time.period.opened` / `.closed` / `.reopened`
- `time.feed.bundle_built` / `.consumed` / `.superseded`
- `time.category.created` / `.updated` / `.deactivated`

Every event includes `tenant_id`, `actor_user_id` (or `system` for AI/cron), `meta_json`.

---

## 9. Validation rules

- `hours` must be `> 0` for any non-leave category; `>= 0` for leave categories (rare 0-hour holiday recordings allowed).
- `work_date` must fall within the period's `start_date`/`end_date` and within the placement's active window.
- A time entry on a placement requires that placement to have an approved rate covering `work_date` BEFORE approval is allowed (snapshot needs something to snapshot).
- Sum of `hours` per (person_id, work_date) capped at 24 across all entries (validation, not constraint).
- Bulk uploads with `already_approved=true` require `time.approve` permission, not just `time.bulk_upload`.
- Tokenized email approval requires `placements.tokenized_email_approval_enabled=true` AND `placement_approval_contact.client_approver_email IS NOT NULL`.
- A period cannot be closed while any entry is in `pending_review`.
- A period cannot be reopened if any `time_downstream_feed` row in it is `consumed`.

---

## 10. Multi-tenancy + isolation

- Every query filters by `tenant_id`.
- **Inbox connector**: CoreFlux does NOT host tenant inboxes. Each tenant authorizes CoreFlux (OAuth) against their own Microsoft 365 or Google Workspace account, with the connector configured to read only one named folder (e.g. `timesheets`). Originals are never modified. Detail in `/app/core/MailService.SPEC.md`.
- **Outbound email**: sent from the tenant's own domain (e.g. `no-reply@tenantcompany.com`), via either (a) tenant's OAuth-connected mail account or (b) shared Resend with tenant domain verified. Configured per tenant in MailService.
- Tenant-supplied OpenAI API key is required for AI features to work for that tenant. Without a key, AI inbox / extraction is disabled; bulk + manual still work.

---

## 11. Decisions locked (all from HARD_RULES decisions log + this spec sign-off)

1. ✅ Rate snapshot at approval (option b — frozen).
2. ✅ Standard categories list (9 entries) + tenant additions under fixed buckets.
3. ✅ AI may post entries; downstream cash flow always requires explicit human approval.
4. ✅ No auto-reply to senders.
5. ✅ Missing Timesheets dashboard with two buckets: received-unreadable / expected-not-received.
6. ✅ Per-placement toggle for tokenized client email approval (default OFF).
7. ✅ Bulk uploads can carry `already_approved` flag (gated by `time.approve`).
8. ✅ Tenant has its own inbox under tenant subdomain.
9. ✅ AI provider: direct PHP cURL to OpenAI, no Python.

### Locked in this spec sign-off
10. ✅ **Inbox model = read-only connector** (NOT CoreFlux-hosted inbox). Tenant retains email in their own mail system. Originals stay in their inbox and remain searchable in Outlook/Gmail forever; CoreFlux marks processing status inside CoreFlux only.
11. ✅ **Mail providers supported at MVP**: Microsoft 365 (Graph API) AND Google Workspace (Gmail API). Both required at launch.
12. ✅ **Folder-based filtering**: tenant creates a dedicated `timesheets` folder/label in their mail system; CoreFlux only reads that folder. (Future module — invoices — will use a separate `invoices` folder via the same connector.)
13. ✅ **Non-destructive processing**: CoreFlux does NOT modify, move, label, or mark-as-read the source email. All processing status (`pending_review`, `converted`, `unreadable`) is tracked exclusively inside CoreFlux on `time_intake_events`.
14. ✅ **Polling architecture** (not webhooks/push). Connector polls per-tenant on a configurable interval (default 5 min). Simpler ops, no webhook secret management, fine for staffing-agency volume.
15. ✅ **Worker UI = weekly grid** (paper-timesheet mental model). Calendar view deferred.
16. ✅ **Pay periods auto-generated** by monthly cron (8 weeks ahead per tenant).
17. ✅ **Late SLA**: tenant default = period_end + **4 calendar days**, per-placement override allowed.
18. ✅ **Token expiry default**: 7 days.
19. ✅ **OT auto-split** at 40 hrs/week, with **per-employee override** (salary-exempt, custom rules, state-specific overrides).
20. ✅ **Holiday calendar**: platform default (US federal) + tenant customizable.
21. ✅ **No grace period** on consumed bundles — corrections always create a supersede.
22. ✅ **Bulk upload format**: fixed canonical CSV at MVP. Column-mapping wizard deferred.
23. ✅ **Outbound emails (token approvals, notifications, future invoices)**: sent FROM the tenant's own domain (e.g. `timesheets@tenantcompany.com`, `no-reply@tenantcompany.com`). Two transport options per tenant: (a) via tenant's own OAuth-connected mail account (Microsoft Graph / Gmail), or (b) via shared Resend account with the tenant's domain verified there. Tenant chooses at config time. **This becomes a Core MailService primitive**, not a Time-module concern.

---

## 12. Open questions (need user input before implementation)

1. **Email ingestion provider** — who handles the `*@<tenant>.coreflux.app` inbound email and webhooks it to us? Recommend **Postmark Inbound** or **AWS SES Inbound** (S3 + SNS → webhook). Pricing differs ($1.25/1k emails Postmark; $0.10/1k SES).
2. **Worker self-entry UI** — calendar view, weekly grid, both? Recommend weekly grid (matches paper-timesheet mental model).
3. **Period defaults** — does the platform auto-generate `time_periods` rows N weeks ahead per tenant? Recommend yes, monthly cron.
4. **`expected_by` SLA** — how long after period_end before a timesheet is "late"? Tenant config? Recommend tenant default + per-placement override; default = period_end + 2 days.
5. **Token expiry default** — currently 7 days. Confirm.
6. **Token email branding** — sent from tenant subdomain (`approvals@acme.coreflux.app`) or platform domain? Tenants probably want their own — needs DKIM / SPF setup per tenant.
7. **Holiday calendar** — does the platform manage US federal holidays auto-prefilled per worker, or tenant-managed? Recommend platform-supplied US calendar + tenant overrides.
8. **OT calculation** — automatic split of hours > 40/week into OT category, or worker/admin enters OT manually? Recommend automatic at approval time, with an override.
9. **Time correction grace period** — once a `time_downstream_feed` row is `consumed` (e.g. invoice generated), corrections must reverse-and-rebill. Should there be a grace window (e.g. 24h) where corrections still update the same bundle? Recommend NO grace — once consumed, corrections always create supersede.
10. **Bulk upload format** — CSV with fixed columns, or tenant-defined column mapping? Recommend fixed canonical CSV with a "map your columns" wizard.

---

## 13. MVP cut list

**Phase A (ship first):**
- `time_periods`, `time_entries`, `tenant_time_categories`
- Manual entry + review queue + approve/reject/correct
- Period close + downstream feed bundle build
- RBAC + audit
- Basic reports (by placement / by person)

**Phase B:**
- Bulk upload (CSV) with optional `already_approved`
- Tokenized client email approval (issuance, response endpoint, revoke)
- Missing Timesheets — `expected_but_not_received` half (computed from active placements)

**Phase C:**
- AI inbox parser + intake events + review queue UI
- Missing Timesheets — `received_but_unreadable` half (depends on AI)
- OT auto-split, holiday calendar
- Anomaly flagging

**Phase D:**
- Per-tenant DKIM/SPF for token-approval emails
- AI prompt versioning UI
- Replay tooling (re-run AI on intake event with newer model)

---

*This SPEC is binding once signed off. Update before code changes.*
