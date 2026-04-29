# Time Module — Cloudways Deploy Notes (Phase A)

Built fresh against `/app/modules/time/SPEC.md` Phase A scope. The module
depends on `people` + `placements` (both already deployed) and on
`Core\MailService` (currently using `LogDriver` — no Microsoft 365 / Gmail
credentials required for Phase A; AI inbox polling lands in Phase B).

## What's in this drop

- **4 new tables** (idempotent, `utf8mb4_unicode_ci`, additive):
  - `time_periods`, `tenant_time_categories`, `time_entries`,
    `time_downstream_feed`
- **6 SPEC-aligned API endpoints** under `/api/v1/time/*`:
  - `entries.php`, `periods.php`, `categories.php`, `reports.php`,
    `feed.php`, `csv_import.php`
- **7 React components** wired into the SPA shell:
  - `TimeModule`, `MyTime`, `ReviewQueue`, `Periods`, `Reports`,
    `Categories`, `CsvImport`
- **9 standard categories** + custom (regular_billable, regular_nonbillable,
  OT_billable, OT_nonbillable, holiday, vacation, sick, bereavement,
  unpaid_leave) with deterministic `timeBucket()` rollups (billable /
  nonbillable / pto / unpaid / custom) per SPEC §2 / §3.3
- **5 entry statuses** (`draft`, `pending_review`, `approved`, `rejected`,
  `superseded`) and **3 approval channels** (`manual`,
  `tokenized_client_email`, `bulk_pre_approved`)
- **Downstream feed** bundling (`ar`, `ap`, `payroll`, `revrec`) with
  consume / supersede flow for AR, AP and Payroll modules to pick up
- **CSV import** via the platform-wide `Core\CsvImportService` primitive
- **74 contract-level smoke tests** passing locally
  (`php /app/tests/time_spec_smoke.php`)
- **383 platform smoke tests** still green (no regressions)

## Deploy steps

### 1. Push from preview to GitHub
Use the **Save to GitHub** button in the chat input (Emergent handles the
git push — don't `git push` from this preview shell).

### 2. Run the migration on Cloudways

**Option A — via your `/update.php` runner** (preferred if it scans
`modules/*/migrations/`):
- Visit `https://yourapp.com/update.php` → "Update now"
- The runner picks up `modules/time/migrations/001_init.sql`

**Option B — manual one-time**:
```bash
mysql -u <user> -p <db> < /path/to/coreflux/modules/time/migrations/001_init.sql
```
Idempotent (`CREATE TABLE IF NOT EXISTS`) — safe to re-run.

### 3. Verify tables
```sql
SHOW TABLES LIKE 'time_%';
SHOW TABLES LIKE 'tenant_time_categories';
```
You should see exactly 4 new tables. No legacy time tables are touched.

### 4. Smoke test the UI
- Navigate to **Time** in the sidebar
- Sub-routes available:
  - `My Time` (entries grid)
  - `Review Queue` (pending_review approvals)
  - `Periods` (open / close / reopen weekly buckets, generates
    downstream feed bundles on close)
  - `Reports`
  - `Missing Timesheets`
  - `Categories` (tenant-level custom category management)
  - `CSV Import` (bulk pre-approved upload)

### 5. Permissions
The following permissions are auto-registered via the module manifest. The
`master_admin` role already includes `*`, so no role config is needed for
the initial walkthrough. For tenant admins, grant any subset of:
- `time.view`, `time.entry.self`, `time.entry.manage`
- `time.review`, `time.approve`, `time.reject`
- `time.bulk_upload`, `time.period.close`, `time.feed.consume`
- `time.dashboard.missing`, `time.categories.manage`, `time.audit.view`
- `time.tokenized_email.issue`, `time.tokenized_email.revoke`

## Phase B (next) — deferred items

- Real `M365GraphDriver` / `GmailApiDriver` for `Core\MailService` so the
  Time module can poll inboxes and parse timesheets via the LLM
- Tokenized client-approval email flow (issue + revoke endpoints exist;
  the email-send + click-through verification UI needs the real mail
  driver)
- AI parsing layer wiring (PHP cURL → OpenAI) for inbox-derived entries
- Auto-supersede + audit chain for re-parsed corrections

## Rollback

Phase A tables are additive only. To roll back:
```sql
DROP TABLE IF EXISTS time_downstream_feed;
DROP TABLE IF EXISTS time_entries;
DROP TABLE IF EXISTS tenant_time_categories;
DROP TABLE IF EXISTS time_periods;
```
Then revert the git commit. No legacy schema is mutated by this drop.
