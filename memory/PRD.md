# CoreFlux Product Requirements Document

## Session — 2026-02 (Account lifecycle — Remove from CoA)

User direction (with screenshot of 15 `1000-XXXX First Citizens Bank
— XXX` rows): "why would I pull the chart of accounts to then
manually map it to existing items? also, I need to be able to
remove accounts. the bank connection brought it all the accounts."

### Diagnosis

Plaid's bank-link flow (`api/plaid_bank_link.php:308 + 430`) writes
one `accounting_accounts` row per bank sub-account so journal
entries can debit/credit each Plaid account directly.  For a tenant
with 15 First Citizens sub-accounts, that means 15 bank-shaped GL
lines polluting the CoA — none of which the operator wants to map
to Jaz's 249 generic CoA rows.

The correct workflow is to either:
  (a) Map all 15 bank-shaped CF rows to ONE Jaz "Cash" / "Bank
      Accounts" row (multi-to-one mapping is already supported by
      the unique constraint shape — `coreflux_account_id` is the
      PK side, not `provider_account_id`).
  (b) Remove the unwanted rows from the CoA entirely if the
      operator never intends to post to them at the GL level.

### Shipped

1. **`core/accounting/account_lifecycle.php`** (NEW):
   - `accountingAccountDelete($tenantId, $accountId)` — hard delete.
     Refuses (throws `AccountingAccountDeleteBlockedException`) if:
     * any posted journal lines reference the account
       (`accounting_journal_entry_lines.account_id` JOIN-scoped via
       `accounting_journal_entries.tenant_id` for the static-leak
       sentry), OR
     * any active `accounting_bank_accounts` row points its
       `gl_account_code` at this account (status != archived/removed).
     The exception carries a `reasons` array (e.g.
     `{journal_lines: 12, active_bank_accounts: 1}`) so the API can
     return per-reason counts to the UI.
     Cascade-drops `accounting_account_mappings` rows (tenant-scoped
     DELETE) before deleting the parent row.
   - `accountingAccountDeactivate($tenantId, $accountId)` — soft
     path that flips `active = 0` and stamps `updated_at`.  Always
     permitted.

2. **`api/accounting.php`** endpoints:
   - `POST ?action=account_delete`     — wraps the hard delete.
     Returns 409 with `{reasons: {...}}` extras on blocked.
   - `POST ?action=account_deactivate` — wraps the soft archive.
   - Both require `accounting.connection.manage` RBAC, both accept
     `coreflux_account_id` (with `account_id` as alias for
     symmetry with Step 4's existing API surface).

3. **JazSyncNowCard inline Remove button**:
   - Per-row Remove button alongside the existing "Map this to…"
     dropdown.  Hover hint: "Permanently delete this CF account
     from the Chart of Accounts (only if no posted journal lines
     and no active bank feed)."
   - On 409 → confirm fallback offers "Deactivate instead?"  If
     accepted, the helper re-fires with `account_deactivate`.
   - Local `removedNow` state tracks `coreflux_account_id →
     'deleted' | 'deactivated'`.  Removed rows fade to opacity 0.5
     and replace the dropdown/button with "✓ Removed from CoA" or
     "✓ Deactivated".  State resets on every fresh sync run.
   - Per-row testids:
     `jaz-sync-unmapped-remove-{i}`,
     `jaz-sync-unmapped-removed-{i}`.

4. **Tenant-leak sentry compliance**: both `account_lifecycle.php`
   queries explicitly JOIN to a parent tenant_id column or scope by
   `tenant_id = :t` directly — the static analyzer in
   `tests/tenant_leak_static_analyzer_smoke.php` certifies zero
   leaks.

### Test status
- `tests/account_lifecycle_remove_smoke.php`         → 27/27 ✓ (NEW)
- `tests/tenant_leak_static_analyzer_smoke.php`      → 5/5 ✓
- `tests/jaz_unmapped_inline_dropdown_smoke.php`     → 29/29 ✓
- Full PHP suite: **380 / 382 passing** (only the 2 documented
  sandbox-bound failures remain).
- Vite bundle: **`coreflux-BN1FpXAW`**, lint clean, all 4 sync
  points consistent.

### Files touched
- `core/accounting/account_lifecycle.php` (NEW, ~140 LOC)
- `api/accounting.php` (added 2 endpoints in a shared in_array gate)
- `dashboard/src/pages/JazIntegrationSettings.jsx` (removedNow state,
  removeAccount() helper, Remove button + 409 fallback, fade-out
  row state)
- `tests/account_lifecycle_remove_smoke.php` (NEW, 27 assertions)

### Operator action (production)
1. Deploy bundle `coreflux-BN1FpXAW`.
2. Open Jaz → Step 3B → Sync now.
3. For each of the 15 unwanted `1000-XXXX First Citizens Bank — XXX`
   rows, click **Remove**.  Confirmation dialog reminds you that
   the system will refuse if the account has posted journal lines
   or backs an active bank feed.
4. If a row is blocked → confirm the inline fallback to soft-
   deactivate instead.  Ledger history stays intact; the row just
   hides from active-account pickers.
5. (Optional follow-up) Once the chart is clean, re-run Sync now
   — the auto-mapper telemetry should report only the GL-shaped
   accounts you actually care about syncing.

### Roadmap (unchanged)
- (P1) Mercury Webhooks hardening.
- (P1) Per-tenant AI feature flag UI (`use_llm` admin toggle).
- (P1) Phase 8 — Business Event Layer infrastructure.
- (P2) Audit the Plaid bank-link flow so future bank connections
  DON'T silently seed `accounting_accounts` rows the operator
  didn't ask for — should be opt-in via a "create GL line per
  bank account?" toggle.
- (P2) Gusto integration / QBO hardening.
- (P2) CFO Dashboard role/access gating.
- (P3) Customer portal Phase A.
- (P3) Engagements module.
- (P3) AI Digest Scheduler (Resend rail live).

---

## Session — 2026-02 (Inline "Map this to..." dropdown in Step 3B)

User direction: "Yeah, dropdown!" → wire an inline mapping resolver
so operators can complete the auto-map workflow directly from the
Step 3B telemetry block instead of jumping to Step 4 for each row.

### Shipped

1. **Backend response** in `accountingAccountMappingsAutoMap()`
   (`core/accounting/account_mapping_service.php`):
   - Every `unmapped_sample[]` row now carries `coreflux_account_id`
     so the frontend can target the correct CF row on save.
   - When any CF row was unmapped, the envelope additionally
     includes `provider_options[]` — a compact list (capped at 500
     entries) of every provider account with
     `{provider_id, code, name, type, subtype}`.

2. **Inline dropdown** in `JazSyncNowCard` (`JazIntegrationSettings.jsx`):
   - Each unmapped row now renders an inline `<select>` populated
     from `provider_options[]`.  Option label: `"name · subtype (type)"`.
   - On change → POST `?action=account_mapping_save&provider=jaz`
     with `source='manual', confidence=100`.
   - Optimistic resolution: the row swaps to `"✓ Mapped → {name}"`
     after a successful save; further interaction is disabled.
   - Per-flash signal: a success toast confirms each mapping
     ("Mapped X → Y · visible in Step 4") and an error toast
     surfaces any save failure with the offending row name.
   - `mappedNow` state resets on every new runSync so the resolver
     starts clean.
   - Local `savingId` state disables the select while in flight.

3. **Test coverage** in
   `tests/jaz_unmapped_inline_dropdown_smoke.php` (NEW, 29
   assertions) — locks the backend envelope, the frontend state
   machine, the testid scheme, the POST contract, and the section
   gating (chart_of_accounts only, only when unmapped_sample is
   non-empty).

### Test status
- `tests/jaz_unmapped_inline_dropdown_smoke.php` → 29/29 ✓
- Full PHP suite: **379 / 381** (only the 2 known sandbox-bound
  failures remain).
- Vite bundle: **`coreflux-CTMp7TzW`** (frontend changed).
- Lint clean.

### Files touched
- `core/accounting/account_mapping_service.php` (provider_options
  envelope + coreflux_account_id on unmapped_sample)
- `dashboard/src/pages/JazIntegrationSettings.jsx` (mappedNow /
  savingId state, saveMapping POST, per-row dropdown row,
  optimistic ✓ Mapped state)
- `tests/jaz_unmapped_inline_dropdown_smoke.php` (NEW, 29 assertions)

### Operator action (production)
1. Deploy `coreflux-CTMp7TzW`.
2. Click Sync now → telemetry block auto-expands when any CF row is
   unmapped.
3. For each row, pick the right Jaz account from the dropdown — the
   mapping persists immediately with `source=manual, confidence=100`
   and the row marks itself ✓ Mapped.
4. Step 4 list still shows the same mappings — both surfaces share
   the same backing table.

### Roadmap (unchanged)
- (P1) Mercury Webhooks hardening.
- (P1) Per-tenant AI feature flag UI (`use_llm` admin toggle).
- (P1) Phase 8 — Business Event Layer infrastructure.
- (P2) Gusto integration / QBO hardening.
- (P2) CFO Dashboard role/access gating.
- (P3) Customer portal Phase A.
- (P3) Engagements module.
- (P3) AI Digest Scheduler (Resend rail live).

---

## Session — 2026-02 (Jaz pull telemetry + smart name matching)

User direction: screenshot showed Step 3B with
`chart_of_accounts → pull · pull: 0 mapped` — no error, no insight.
The auto-mapper ran but matched none of the CF accounts and the UI
swallowed the new telemetry I'd added in the previous session.

### Root cause

1. The Jaz auto-mapper's name normalizer was too conservative — it
   only stripped colon-prefixed parent paths.  Operators routinely
   have CF accounts named `"1001 - Cash"` / `"1001 Cash"` /
   `"Cash (Bank)"` which never matched Jaz's plain `"Cash"`.
2. Even when the auto-mapper added rich telemetry
   (`matched_by_code`, `matched_by_name`, `provider_has_codes`,
   `note`), JazSyncNowCard never surfaced any of it — operators saw
   only the `pull: 0 mapped` summary string.
3. The flash banner was misleadingly green for "0 mapped, 0 errors"
   runs — the operator had no signal to dig deeper.

### Shipped

1. **Smarter `nameNorm` closure** in
   `core/accounting/account_mapping_service.php`:
   - ASCII-folds accents (`Crédit` → `credit`) via `iconv`.
   - Strips leading numeric code prefix
     (`/^\s*\d+\s*[-:.\s]+/` matches `"1001 - "`, `"1001. "`,
     `"1001:"`, `"1001 "`).
   - Strips trailing parenthetical qualifier
     (`/\s*\([^)]*\)\s*$/` matches `" (Bank)"`, `" (US GAAP)"`).
   - Strips colon-prefixed parent paths (carried over).
   - Collapses ALL punctuation + whitespace runs to single spaces
     using Unicode `\p{P}` class.
   - Live probe vs the user's real 249-account Jaz tenant:
     **9 / 14** tricky CF names now match (was 0 / 14 before).
     The 5 non-matches are genuinely absent from the Jaz tenant —
     no false positives.

2. **Rich pull telemetry envelope** from
   `accountingAccountMappingsAutoMap()`:
   - `provider_row_count`  — total provider CoA rows pulled.
   - `cf_unmapped_count`   — total CF accounts considered.
   - `matched_by_code`     — exact code matches (confidence=80).
   - `matched_by_name`     — name matches (confidence=60).
   - `no_provider_match`   — CF accounts with no Jaz counterpart.
   - `provider_has_codes`  — boolean (Jaz returns false).
   - `unmapped_sample[]`   — first 8 unmapped CF rows
     `{code, name, normalized}` so operators can see exactly what
     the auto-mapper compared.

3. **JazSyncNowCard inline telemetry block**:
   - New `<details>` panel labelled "Show auto-map telemetry" that
     auto-expands when `mapped === 0 && cf_unmapped_count > 0`.
   - Renders the counters + a list of unmapped CF rows with their
     normalized form so operators can pinpoint why each row missed.
   - Distinct testids `jaz-sync-info-{entity}` /
     `jaz-sync-info-{entity}-{block}-{line}`.

4. **Flash banner — `kind:'info'` for empty-success runs**:
   - Renders blue (#eff6ff / #1e3a8a) instead of green so the
     "0 mapped" state is visually distinct from a true success.
   - Message points operators at the telemetry block:
     `"Sync finished with no changes. CoA · 0 mapped · 0 pushed —
       open 'auto-map telemetry' below to see which CoreFlux rows
       didn't match a Jaz account."`

### Test status
- `tests/auto_map_telemetry_and_smart_name_smoke.php` → 19/19 ✓ (NEW)
- `tests/account_mapping_name_fallback_smoke.php`     → 16/16 ✓
  (1 assertion updated for new Unicode regex).
- `tests/jaz_push_409_and_error_surface_smoke.php`    → 28/28 ✓
- Full PHP suite: **378 / 380 passing** (only the 2 known
  sandbox-bound failures remain).
- Vite bundle: **`coreflux-AzW5ZR_m`** (frontend changed —
  JazSyncNowCard now renders the info block + new flash kind).

### Files touched
- `core/accounting/account_mapping_service.php` (smarter nameNorm
  + richer pull envelope)
- `dashboard/src/pages/JazIntegrationSettings.jsx` (telemetry block,
  flash kind:info wiring, blue flash palette)
- `tests/account_mapping_name_fallback_smoke.php` (1 updated
  assertion for new Unicode normaliser)
- `tests/auto_map_telemetry_and_smart_name_smoke.php` (NEW, 19
  assertions)

### Operator action (production)
1. Deploy bundle `coreflux-AzW5ZR_m`.
2. Open Jaz integration → Step 3B → Sync everything now.
3. Auto-map telemetry block will expand automatically. Expected:
   - With 15 CF accounts and 249 Jaz rows, the smarter normaliser
     should land most (Cash, AR, Inventory, Prepaid Expenses, etc.).
   - Any CF row that still misses will appear in the
     "Unmapped CF accounts" list with its normalized form, so you
     can either rename the CF row to match Jaz or map it manually
     in Step 4.

### Roadmap (unchanged)
- (P1) Mercury Webhooks hardening.
- (P1) Per-tenant AI feature flag UI (`use_llm` admin toggle).
- (P1) Phase 8 — Business Event Layer infrastructure.
- (P2) Gusto integration / QBO hardening.
- (P2) CFO Dashboard role/access gating.
- (P3) Customer portal Phase A.
- (P3) Engagements module.
- (P3) AI Digest Scheduler (Resend rail live).

---

## Session — 2026-02 (Jaz CoA — verified live, end-to-end working)

User direction: "closer!" → screenshot showed the new error-surface
revealed Step 3B pull now returned `Provider accounts carry no codes —
auto-map by code unavailable`.  User then provided a live Jaz API key
so the actual API contract could be probed and locked.

### Discoveries from the live probe (api.getjaz.com/api/v1)

Live HTTP traces against the user's real Jaz tenant revealed FOUR
schema mismatches the adapter had been silently working around:

1. **Field names are `accountClass` + `accountType`** (NOT `type` /
   `accountType` as separate buckets).  Jaz's data model is:
   - `accountClass` = high-level bucket (Asset / Liability / Equity /
     Revenue / Expense — TitleCase).
   - `accountType`  = free-form sub-type ("Fixed Asset", "Bank
     Accounts", "Operating Expense", etc.).
   - Our adapter previously sent `type:"EXPENSE"` (uppercase) hoping
     it'd match the bucket — Jaz silently ignored it.
2. **Currency is `currencyCode: "USD"`** flat string, not
   `currency: {code: ...}`.
3. **Jaz does NOT track account codes** at all.  Every row is keyed
   by `resourceId` (UUID) and a `name` that may include parent-path
   prefixes (e.g. "Travel:Vehicle rental").  Our previous "auto-map
   by code" guard correctly detected this and bailed — but operators
   had no fallback, so 15 CF accounts were stuck unmapped.
4. **Pagination uses `?limit=N&offset=M`** — NOT the documented
   `page/pageSize`.  `?page=2` is silently ignored (returns page 1
   every time).  Without this fix `getChartOfAccounts()` would return
   only the first 100 of 249 accounts, then the loop would terminate
   thinking it was done.

### Live verification

Captured during the session against the user's real Jaz tenant:

- `POST /chart-of-accounts` with `{name, accountClass, accountType,
  currencyCode}` → **HTTP 201** with `data.resourceId` (a real test
  row was created + cleaned up).
- `GET /chart-of-accounts?limit=500` → **HTTP 200**, all **249 / 249**
  accounts in one shot (no duplicates).
- Name-based auto-map probe against 15 typical CoreFlux account
  names → **14 / 15 hit rate** (Cash, AR, Inventory, Prepaid
  Expenses, Retained Earnings, Travel, Bad Debt, Goodwill, Interest
  paid, Land, Buildings, Vehicles, Furniture & fixtures, Salary &
  Payroll Expense matched; only "Office Supplies" missed because the
  tenant has it under a different parent path).

### Shipped

1. **`jaz_adapter.php::createAccount()` — verified payload shape**:
   - Now sends `{name, accountClass (Asset/Liability/Equity/Revenue/
     Expense), accountType (sensible default sub-type), currencyCode}`.
   - CoreFlux's `code` field is folded into the name as
     `"{code} - {name}"` since Jaz has no codes column.
   - Default sub-type per bucket: Current Asset / Current Liability /
     Shareholders Equity / Sales / Operating Expense.
2. **`jaz_adapter.php::normalizeCoaRow()` — reads real Jaz shape**:
   - `accountClass` → `type`, `accountType` → `subtype`,
     `currencyCode` → `currency`, `status==='ACTIVE'` → `active`.
   - Still tolerates legacy / camelCase responses as a fallback.
3. **`jaz_adapter.php::getChartOfAccounts()` — pagination fix**:
   - Now uses `limit=500&offset=N`.  `page/pageSize` is broken
     upstream (returns page 1 forever, cap 100).  Single call now
     pulls all 249 accounts for typical tenants.
4. **`account_mapping_service.php::accountingAccountMappingsAutoMap()`
   — name fallback**:
   - Builds both `byCode` and `byName` lookups; `byName` is
     case-insensitive, parent-path-stripped, whitespace-collapsed.
   - Tries code first (confidence=80, source=auto_code), then name
     (confidence=60, source=auto_name).
   - Returns richer telemetry: `matched_by_code`, `matched_by_name`,
     `provider_has_codes`, plus a `note` so operators know name
     matches need confirmation.
5. **POST response unwrapping**: Jaz wraps the created row in
   `{data: {...}}`; we now strip the envelope before
   `normalizeCoaRow()` so `provider_object_id` carries the real
   `resourceId`, not the empty default.
6. **409 conflict fallback** now keys the GET lookup on `name`
   (Jaz's primary identity) instead of `code`.
7. **422 hint** updated to mention `accountClass` + `accountType`.

### Test status
- `tests/jaz_push_409_and_error_surface_smoke.php` → 28/28 ✓
- `tests/account_mapping_name_fallback_smoke.php`  → 16/16 ✓ (NEW)
- `tests/jaz_integration_slice2_live_smoke.php`    → 86/86 ✓
- `tests/jaz_sync_button_and_coa_bidir_smoke.php`  → 45/45 ✓
- Full PHP suite: **377 / 379 passing** (only the 2 known
  sandbox-bound failures remain).
- Vite bundle unchanged (`coreflux-CiA6wnH5`) — backend-only.

### Files touched
- `core/accounting/jaz_adapter.php` (createAccount payload,
  normalizeCoaRow, getChartOfAccounts pagination, 409 fallback,
  response unwrapping, error hints)
- `core/accounting/account_mapping_service.php`
  (accountingAccountMappingsAutoMap — name fallback + telemetry)
- `tests/jaz_push_409_and_error_surface_smoke.php` (8 new assertions)
- `tests/account_mapping_name_fallback_smoke.php` (NEW, 16 assertions)
- `tests/jaz_integration_slice2_live_smoke.php` (3 assertions
  updated for new pagination + maxIters)
- `tests/jaz_sync_button_and_coa_bidir_smoke.php` (2 assertions
  updated for new field names)

### Production action for Kunal
1. Deploy (no migrations needed).
2. Open Integration Settings → Jaz → Step 3B → click "Sync everything
   now".
3. Expected outcome:
   - `chart_of_accounts → pull: ~14 mapped · 0-1 errors` (the 15th
     CF account that doesn't name-match will need a manual mapping
     in Step 4).
   - Step 4 Account Mapping shows 14 rows with `source = auto_name,
     confidence = 60` — review and bump to 100 (manual) for any that
     look right; remap any that look wrong.
   - The `note` banner says "Auto-mapped 0 by code + 14 by name —
     name matches are confidence=60, please confirm."
4. **Security note**: rotate the Jaz API key you shared in chat
   (Settings → API Keys → revoke + regenerate).  The key was used
   only for live probes in this conversation — no persistence.

### Roadmap (unchanged)
- (P1) Mercury Webhooks hardening.
- (P1) Per-tenant AI feature flag UI (`use_llm` admin toggle).
- (P1) Phase 8 — Business Event Layer infrastructure.
- (P2) Gusto integration / QBO hardening.
- (P2) CFO Dashboard role/access gating.
- (P3) Customer portal Phase A.
- (P3) Engagements module.
- (P3) AI Digest Scheduler (Resend rail now live — easiest P3 to ship).

---

## Session — 2026-02 (Jaz CoA push — root-cause fix: lowercase field names)

User direction: "received the email. the next item is still Jaz" →
the prior session shipped the error-surface UI which let Kunal see
the actual Jaz rejection message: **`{"error_type":"validation_error","errors":["name is a required field"]}`**
for every single account.  This session ships the actual payload fix.

### Root cause

`JazAccountingAdapter::createAccount()` was sending camelCase field
names — `accountCode`, `accountName`, `accountType` — but Jaz's
POST `/chart-of-accounts` endpoint expects the same **lowercase**
canonical names its GET endpoint returns: `code`, `name`, `type`.

The smoking gun: `normalizeCoaRow()` (line 441 of `jaz_adapter.php`)
already reads via `$r['accountName'] ?? $r['name']` — a defensive
fallback added when both shapes were unknown.  Jaz's real shape is
the second alternative.  Jaz silently dropped the unknown camelCase
keys on writes, then complained that `name` was missing.

### Shipped

1. **`createAccount()` payload renamed to canonical Jaz shape**:
   - `accountCode` → `code`
   - `accountName` → `name`
   - `accountType` → `type` (still uppercased enum: ASSET / LIABILITY
     / EQUITY / REVENUE / EXPENSE)
   - `isActive`, `currency.code`, `description` unchanged (Jaz
     accepted these silently — no error mentioned them).
2. **409-fallback GET lookup** query param renamed `accountCode` →
   `code` to match the same canonical shape (otherwise Jaz would
   ignore the filter and return the first 50 accounts).
3. **Smoke test extended** (`jaz_push_409_and_error_surface_smoke.php`):
   - 4 new assertions lock the new field names on both POST + GET
     and explicitly guard against regression to the camelCase shape.
   - Total now 20/20 ✓.

### Test status
- `tests/jaz_push_409_and_error_surface_smoke.php` → 20/20 ✓
- `tests/jaz_sync_button_and_coa_bidir_smoke.php` → 45/45 ✓
- Full PHP suite: **376 / 378 passing** (only the 2 documented
  sandbox-bound failures remain — `accounting_phase2_a7_smoke.php`,
  `tenant_mail_senders_smoke.php`).
- Vite build: bundle `coreflux-CiA6wnH5` (unchanged — backend-only fix).

### Operator next step (production)
1. Deploy → no migrations.
2. Open Jaz integration page → "Sync everything now" again.
3. Expected: `chart_of_accounts → push: 15 created · 0 errors`
   (or partial if any names collide with existing Jaz CoA — 409s
   are now idempotent thanks to the prior fix).
4. Step 4 (Account mapping) should auto-populate with the 15 new
   `accounting_account_mappings` rows, source = `imported`,
   confidence = 100.

### Files touched
- `core/accounting/jaz_adapter.php` (POST payload + GET filter renamed)
- `tests/jaz_push_409_and_error_surface_smoke.php` (4 new assertions)

### Roadmap (unchanged)
- (P1) Mercury Webhooks hardening.
- (P1) Per-tenant AI feature flag UI (`use_llm` admin toggle).
- (P1) Phase 8 — Business Event Layer infrastructure.
- (P2) Gusto integration / QBO hardening.
- (P2) CFO Dashboard role/access gating.
- (P3) Customer portal Phase A.
- (P3) Engagements module.
- (P3) AI Digest Scheduler (Resend rail now live — easiest P3 to ship next).

---

## Session — 2026-02 (Resend integration verification — wired & live)

User direction: "wire resend" → verify the existing Resend wiring and
confirm it's live end-to-end so CFO reports / timesheet approver
emails / vendor portal invites / AP bill approvals actually leave the
box instead of just logging locally.

### Outcome — already wired, now verified live

Discovered the full Resend pipeline was implemented in earlier
sessions but the prior fork's P2 backlog still listed it as a TODO.
Live HTTP probe against `https://api.resend.com/domains` with the
key in `/app/core/config.local.php` returned **HTTP 200** with
`mail.corefluxapp.com` in `status: verified`, `sending: enabled`.
The wiring is live; nothing additional shipped this session — just
verification + PRD reconciliation.

### Wiring inventory (verified)

1. **Driver — `core/mail/ResendDriver.php`** (170 LOC):
   - POSTs to `https://api.resend.com/emails`.
   - `Authorization: Bearer ${RESEND_API_KEY}`.
   - Custom `Idempotency-Key: cf-{tenant}-{module}-{sha256_24}` header
     so dual-clicks / retries don't double-send.
   - Payload: `from: "Name <email>", to[], subject, html?, text?, reply_to?, tags?`.
   - Decodes Resend's success `{id}` into the canonical envelope shape.
   - Maps non-2xx responses into a soft `{status: 'failed', error}`.

2. **Bootstrap — `core/mail_bootstrap.php`**:
   - Reads `RESEND_API_KEY` from `getenv()` first, falls back to
     `define()` in `config.local.php`. Both paths supported.
   - When key set → ResendDriver becomes the default outbound driver,
     LogDriver kept as a co-registered fallback.
   - When key missing → LogDriver remains default, ResendDriver still
     registered (fails cleanly if invoked).
   - Outbox writer persists every send attempt into `mail_outbox`
     (status, provider_message_id, sent_at, error, etc.).

3. **Shim — `core/mailer.php::mailerSend()`** (lines 161-275):
   - Suppression list filter via `cf_mail_filter_suppressed()`.
   - Per-purpose sender resolution via `cf_tenant_mail_sender()`
     (display name + reply-to override + hard-mute per purpose).
   - Routes through `MailService::send()` → driver registry.
   - Soft-fails to legacy PHPMailer SMTP if MailService can't boot
     (no tenant context, DB down, etc.) — preserves backwards
     compatibility with existing callers.

4. **Config — `core/config.local.php`** (lines 17-19):
   - `RESEND_API_KEY     = re_L5QC6Z8...` (valid, HTTP 200 probe).
   - `RESEND_FROM_EMAIL  = no-reply@mail.corefluxapp.com`.
   - `RESEND_FROM_NAME   = CoreFlux Notifications`.
   - Domain `mail.corefluxapp.com` verified on the Resend dashboard
     (region us-east-1, sending enabled).

5. **Call sites (12+ files use `mailerSend()`)**:
   - `modules/staffing/api/timesheet_email_approver.php` — approver request emails.
   - `modules/ap/api/vendor_portal.php` — vendor invites.
   - `modules/ap/api/bill_approvals.php` — bill approval routing.
   - `cron/treasury_sweep_divergence_alert.php` — daily Mercury reconciliation alert.
   - `api/admin/mail_test_send.php` — one-click admin test send endpoint.
   - `api/admin/memberships.php` — magic-link invites.
   - `api/cfo_send_report.php` — CFO PDF reports.
   - `core/mercury_payments.php` — payment status notifications.

6. **Admin UX**: `api/admin/mail_test_send.php` exposes a "send a
   real test email" button (rate-limited 1/10s per actor) so any
   tenant_admin / master_admin can confirm Resend delivery without
   developer involvement.

### Test status
- `tests/resend_wiring_smoke.php`         → 25/25 ✓
- `tests/mailer_send_shim_smoke.php`      → 43/43 ✓
- `tests/mailer_smoke.php`                → all ✓
- `tests/mail_service_smoke.php`          → 38/38 ✓
- `tests/mail_test_send_smoke.php`        → 39/39 ✓
- Full PHP suite: 376/378 (only the 2 documented sandbox-bound
  failures remain — `accounting_phase2_a7`, `tenant_mail_senders`).
- **Live HTTP probe**: `GET https://api.resend.com/domains` →
  200 OK, domain verified.

### Backlog update
- (✅ DONE — verified live this session) Wire `mailerSend()` to a
  Resend driver so CFO reports and timesheet approver emails deliver
  externally.  Removed from P2 backlog.

### Roadmap (unchanged after this verification)
- (P1) Mercury Webhooks hardening.
- (P1) Per-tenant AI feature flag UI (`use_llm` admin toggle).
- (P1) Phase 8 — Business Event Layer infrastructure.
- (P2) Gusto integration / QBO hardening.
- (P2) CFO Dashboard role/access gating.
- (P3) Customer portal Phase A.
- (P3) Engagements module.
- (P3) AI Digest Scheduler (now unblocked — Resend is the rail).

---

## Session — 2026-02 (Jaz CoA push fix + Approved-hours-ready tile mount)

User direction (after screenshots of Step 3B showing
`chart_of_accounts → push: 0 created · 15 errors`): "See Jaz error
screenshots [first]. Complete after [the approved-hours mount]" →
diagnose the silent-failure mode of the Jaz CoA push pipeline THEN
mount the approved-hours-ready tile on AP/Billing/Payroll so the
operator can finally see the cross-module flow.

### Bug 1 — Jaz CoA push silent failure

**Root cause:** `core/accounting/jaz_adapter.php::createAccount()`
detected 409 (already exists / idempotent success) via
`(int) $e->getCode() === 409`.  But `JazApiException::getCode()` is
ALWAYS 0 — the HTTP status is stored in the `httpStatus` PROPERTY.
So every 409 fell through to `throw $e`, the push helper caught it,
counted it as an error, and the run reported `0 created · 15 errors`
even though Jaz had successfully recognised the codes as already
existing.

**Secondary issue:** the JazSyncNowCard UI showed only "15 errors"
with no detail.  Even though the per-row `errors[]` array had the
exact provider message, the React table collapsed it to a count.

### Shipped

1. **`jaz_adapter.php::createAccount()` fix**:
   - 409 detection now uses `$e instanceof JazApiException && $e->httpStatus === 409`.
   - Non-conflict errors are re-thrown with a richer message that
     includes the code we tried to push + an HTTP-status-specific
     hint (401/403 → check API key scopes; 404 → set JAZ_API_BASE;
     422/400 → check accountType / payload).

2. **`JazSyncNowCard.jsx` upgrade**:
   - New expandable `<details>` block per entity row showing the
     actual error message + offending code for the first 25 errors
     (`jaz-sync-errors-{entity}` + `jaz-sync-error-{entity}-{i}` testids).
   - Flash banner now flips to `kind: 'error'` when the run had
     errors (previously stayed `success`-coloured even when zero
     accounts pushed).

3. **Schema-contract bugs from the prior session — fixed**:
   - `staffing/api/timesheets.php` `approved_hours_ready` query
     referenced `bil.tenant_id` / `abl.tenant_id` — but neither
     `billing_invoice_lines` nor `ap_bill_lines` has a `tenant_id`
     column (tenant scope flows through the parent invoice/bill).
     Replaced with `INNER JOIN billing_invoices binv ON binv.id = bil.invoice_id`
     and the mirror for `ap_bills apb`.
   - `people/lib/employees.php::peopleEnsureEmployeesFromW2()` read
     `p.date_of_birth FROM people` — but migration 003 renamed it to
     `dob` on the unified directory table.  Now reads `p.dob` and
     still stamps `people_employees.date_of_birth` (the W-2 table
     keeps the long-form column name).
   - `line_item_types_smoke.php` had a stale assertion expecting
     `BillCreate.jsx` to use `CompanyTypeahead role="vendor"` —
     updated to expect the new `VendorTypeahead testId="ap-bill-create-vendor"`.

### Bug 2 — Approved-hours-ready tile not mounted

**Root cause:** `ApprovedHoursReadyTile.jsx` was built in a prior
session AND the `approved_hours_ready` aggregator endpoint already
returned correct billing/ap/payroll buckets.  But the tile was never
imported into `BillsList`, `InvoicesList`, or `PayrollOverview` — so
operators on those pages had no visible indicator of how many
approved hours were waiting to flow downstream.

### Shipped

1. **AP `BillsList.jsx`**:
   - `import ApprovedHoursReadyTile from '../../staffing/ui/ApprovedHoursReadyTile'`.
   - Renders `<ApprovedHoursReadyTile variant="ap" onPick={() => setShowFromEntries(true)} />`
     above the filter row.  Clicking the CTA opens the existing
     `BillFromTimeEntriesModal`.

2. **Billing `InvoicesList.jsx`**:
   - Same import + mount as AP, `variant="billing"`.
   - Pick handler opens the existing `InvoiceFromTimeEntriesModal`.

3. **Payroll `PayrollOverview.jsx`**:
   - Imports the tile, mounts `<ApprovedHoursReadyTile variant="payroll" to="../pay_periods" />`
     between the header and the stat cards.
   - Uses the `to` prop instead of `onPick` so the CTA renders as a
     react-router `<Link>` straight to the pay-period list.

### Test status
- New: `tests/jaz_push_409_and_error_surface_smoke.php` — **16 / 16 ✓**
  (locks the 409-via-httpStatus fix, error-wrapping with hints, the
  expandable error UI, and the warning-flash branch).
- New: `tests/approved_hours_ready_tile_mounted_smoke.php` — **19 / 19 ✓**
  (locks the three mount sites, the tile component contract, and
  the aggregator endpoint shape).
- Fixed: `tests/line_item_types_smoke.php` — 56/56 ✓.
- Fixed: `tests/schema_contract_smoke.php` — 3/3 ✓ (no NEW violations).
- Full PHP suite: **376 / 378 passing**.  Only the two documented
  sandbox-bound failures remain (`accounting_phase2_a7_smoke.php`,
  `tenant_mail_senders_smoke.php` — no live MySQL / SMTP socket).
- Vite build → bundle `coreflux-CiA6wnH5`. All four sync points
  consistent (`.deploy-version`, `spa-assets/`, `dashboard/dist/index.html`,
  service-worker `CACHE_VERSION`).

### Files touched
- `core/accounting/jaz_adapter.php` (409 detection + error wrapping)
- `dashboard/src/pages/JazIntegrationSettings.jsx` (expandable errors + warning flash)
- `modules/ap/ui/BillsList.jsx` (tile import + mount)
- `modules/billing/ui/InvoicesList.jsx` (tile import + mount)
- `modules/payroll/ui/PayrollOverview.jsx` (tile import + mount)
- `modules/staffing/api/timesheets.php` (tenant_id JOIN fix for both buckets)
- `modules/people/lib/employees.php` (`p.dob` instead of `p.date_of_birth`)
- `tests/line_item_types_smoke.php` (VendorTypeahead assertion update)
- `tests/jaz_push_409_and_error_surface_smoke.php` (new — 16 assertions)
- `tests/approved_hours_ready_tile_mounted_smoke.php` (new — 19 assertions)

### Operator next steps (production)
1. Deploy → `update.php` (no new migrations).
2. Re-run Jaz → Step 3B → "Sync everything now".  If push errors
   still appear, click "Show N error details" — the actual Jaz
   message will be inline.  Most 409 errors should now resolve to
   `(N skipped — already existed)` automatically.
3. AP / Billing / Payroll landing pages now surface the gradient
   "Approved hours ready" tile pointing at the relevant create-from-
   entries modal or pay-period list.

### Roadmap (unchanged)
- (P1) Per-tenant AI feature flag UI (`use_llm` admin toggle).
- (P1) Phase 8 — Business Event Layer infrastructure.
- (P1) Mercury Webhooks hardening.
- (P2) Gusto integration / QBO hardening.
- (P2) CFO Dashboard role/access gating.
- (P3) Customer portal Phase A.
- (P3) Engagements module.
- (P3) AI Digest Scheduler.

---

## Session — 2026-02 (AP Suggest Payment Run — Mercury batch dispatch)

User direction: "yes, payment run. perfect." → ship the AP-side mirror
of "Suggest invoice": AI scans approved bills due in the next N days,
groups by rail-eligible vendor, and produces a single batch of draft
payments the operator can release.

### Shipped

1. **`apSuggestPaymentRun($tid, $daysAhead=7, $rail=null, $userId)`**
   in `modules/ap/lib/ap.php`:
   - Pulls `ap_bills` with `status IN (approved, partially_paid)` +
     `amount_due > 0` + `due_date <= today + clamp(daysAhead, 1..60)`.
   - PWP-blocked rows (`pwp_status='awaiting_ar'`) surface in a
     separate `pwp_blocked` array — they are **never** included in the
     totals or the executable set (4-way-match gate from
     `ap.payment.send` is honoured at the preview stage too).
   - Groups by `vendor_name`. Each group exposes:
     `{vendor_name, vendor_type, payment_method, bill_count, bill_ids,
     bill_refs, total_due, earliest_due_date, oldest_bill_date,
     currency, rail_eligible, eligibility_note}`.
   - When `rail == 'mercury'`, vendors without an active
     `mercury_recipients` row are flagged `rail_eligible=false` with a
     human-friendly fix-it note. Other rails inherit `rail_eligible=true`.
   - Default rail = tenant's `ap_settings.disbursement_rail` (falls
     back to `mercury`).
   - AI commentary via `aiAsk(feature_class=suggestion,
     feature_key=ap.payment_run.suggest_summary)`. Deterministic
     fallback if AI is disabled / unreachable.

2. **`apExecutePaymentRun($tid, $rail, $groups, $userId)`** —
   creates one `ap_payments` row per vendor group in `draft` status:
   - Re-fetches each bill at dispatch time (no stale data); skips
     mismatched vendor, non-payable status, or PWP-blocked.
   - Stamps `disbursement_rail` so the eventual `?action=send` routes
     through `paymentRailsDispatch()` (Mercury / Plaid / NACHA).
   - Auto-allocates the whitelisted `bill_ids` via `apAllocatePayment`.
   - Voids the draft + audits if allocation fails (no orphan rows).
   - **Does NOT auto-send** — operator must still release each
     payment, preserving SoD.

3. **API**:
   - `POST /modules/ap/api/bills.php?action=suggest-payment-run`
     — `ap.payment.create`-gated; body `{days_ahead, rail?}`.
   - `POST /modules/ap/api/bills.php?action=execute-payment-run`
     — same gate; body `{rail, vendor_groups: [{vendor_name, bill_ids}]}`.

4. **`modules/ap/ui/SuggestPaymentRunModal.jsx`** — the operator
   experience:
   - Gradient header + "Suggest payment run" title.
   - Controls: horizon (1-60 days), rail selector (mercury / plaid_transfer / nacha), refresh button.
   - Summary card (vendors, bills, total due, rail-eligible total).
   - AI summary banner with "AI summary" pill + "rail not configured"
     warning chip when applicable.
   - PWP-blocked notice (amber) when rows are excluded by the
     pay-when-paid gate.
   - Vendor-group table with checkbox-per-vendor; non-eligible rows
     greyed + disabled with the eligibility note inline.
   - Footer shows selected count + total + "Create N draft payments"
     CTA with the same gradient as the AR-side Suggest Invoice.

5. **`BillsList`** — new "✨ Suggest payment run" primary CTA next to
   "Import CSV". One-click access from the AP landing page.

6. **Tenant-leak sentry** — annotated the rollback `UPDATE ap_payments
   SET status = "void"` query with a `tenant-leak-allow:` comment
   (the `$payId` was just returned by `scopedInsert()` with tenant
   scope, so the bare-id WHERE is safe).

### Test status
- New smoke `tests/ap_suggest_payment_run_smoke.php`
  → **53 / 53 ✓** (lib helpers, both API actions with auth gates,
  React modal data-testids, BillsList wiring).
- Full PHP suite: **374 / 376 passing** — only the 2 documented
  sandbox-bound failures remain.
- All sentries (tenant-leak, schema-contract, auth-gate, HY093,
  lane-classifier) green.
- Vite build → bundle `coreflux-CDL9Ky9v`. All four sync points
  consistent.

### Files touched
- `modules/ap/lib/ap.php` (2 new lib functions + 1 tenant-leak annotation)
- `modules/ap/api/bills.php` (2 new API actions)
- `modules/ap/ui/SuggestPaymentRunModal.jsx` (new)
- `modules/ap/ui/BillsList.jsx` (CTA + mount)
- `tests/ap_suggest_payment_run_smoke.php` (new)

### Deploy notes for ops
1. No new migrations required.
2. The CTA appears for any user with `ap.payment.create` permission.
3. For optimal experience on the Mercury rail, ensure
   `mercury_recipients` is populated for active vendors (Treasury →
   Mercury Recipients). The modal will flag vendors who lack one and
   point the operator to the fix.
4. After the run, operators see N new `draft` payments in the AP
   Payments queue. They must then release each via the existing
   `?action=send` flow (which itself routes through
   `paymentRailsDispatch()` and triggers Mercury's SoD approval gate
   for the rail-level transfer).

---

## Session — 2026-02 (AI Suggest Invoice + Mercury Rail Driver)

User direction (post-Batch-4): "yeah, suggest invoice, then mercury
expansion" → ship the AI-powered one-click invoice per placement, then
elevate Mercury from "AP-only payment method" to a first-class
PaymentRailsDriver alongside NACHA + Plaid Transfer.

### Shipped (Phase A — AI Suggest Invoice)

1. **`billingSuggestInvoiceForPlacement($tid, $placementId, $userId)`**
   in `modules/billing/lib/billing.php`:
   - Looks up the placement's last invoice issue_date (cutoff) and pulls
     every approved/billable entry past it.
   - Picks aggregation rule-based (NOT via AI — code is deterministic):
     • ≤ 7-day span → `per_placement` (consolidated weekly)
     • > 7 days, 1 worker → `per_day` (daily billables)
     • > 7 days, multi-worker → `per_placement` (consolidated)
   - Resolves bill rate per entry via `placementCurrentRate()` so the
     UI shows a real estimated subtotal (not zero).
   - Calls `aiAsk(feature_class='suggestion', kind='suggestion')` for
     the memo. Silent fallback to deterministic memo when AI is
     disabled / unreachable.
   - Returns `{placement, period, candidate_entries, candidate_entry_ids,
     total_hours, estimated_subtotal, suggested_aggregation,
     suggested_reasoning, suggested_memo, ai_used}`.

2. **`POST /modules/billing/api/invoices.php?action=suggest-from-placement`**
   — `billing.invoice.draft`-gated, body `{placement_id}`, returns the
   suggestion above.

3. **`modules/billing/ui/SuggestInvoiceModal.jsx`** —
   one-click "Suggest invoice" experience:
   - Gradient header + "AI memo" pill when the AI generated the memo.
   - Summary card (client, working days, hours, est. subtotal).
   - Reasoning banner explaining the aggregation pick.
   - Aggregation override radios (per_day / per_placement / per_client).
   - Editable memo textarea.
   - Entries picker with checkbox-per-row (default all selected).
   - Confirm → existing `from-time-entries` POST → returns to list.

4. **`PlacementTimesheetsTab`** mounts a gradient "✨ Suggest invoice"
   CTA in the page header, passing `placement.title` so the modal can
   show the placement name in the prompt.

### Shipped (Phase B — Mercury as a PaymentRailsDriver)

1. **`core/payment_rails/mercury_driver.php`** — new
   `MercuryRailDriver implements PaymentRailsDriver`:
   - `isConfigured()` → `true` globally (driver lives in core, always
     loadable). Per-tenant readiness exposed via `isConfiguredForTenant($tid)`.
   - `originate($items, $opts)`:
     • Requires `opts.tenant_id`; rejects un-connected tenants with
       `PaymentRailsNotConfiguredException`.
     • Upserts a `mercury_recipients` row per item (match by tenant +
       name + last4; delegates create to `mercuryRecipientCreate()` so
       encryption-at-rest is preserved).
     • Calls `mpCreate()` then `mpSubmitForApproval()` for each item,
       so the SoD approval gate from the existing engine still
       applies — nothing moves without human approval.
     • Returns each item with `rail_external_ref = mercury:instruction:N`
       and `status='queued'` (or `'pending'` / `'failed'`).
     • Idempotency key derived from `tenant + external_ref + batch_id`.
   - `getStatus($ref)` parses `mercury:instruction:N` and maps the
     internal state machine (`Draft/PendingApproval/Approved/Funding →
     pending`, `Submitted → submitted`, `Settled/Reconciled → settled`,
     etc.) to the canonical rail enum.
   - `metadata()` populates the rail-card UI: 0 cost, 1-3 BD
     settlement, free ACH, needs_funding_link, fallback_to nacha.

2. **`paymentRailsGetDriver()` / `paymentRailsList()`** in
   `core/payment_rails.php` now include the `mercury` rail.

3. **AP + Payroll settings UIs automatically pick it up** — both
   validate against `paymentRailsList()` (no code changes required).
   Setting `disbursement_rail = 'mercury'` on a tenant routes the next
   batch through the Mercury engine end-to-end.

4. **Tenant-leak sentry** — annotated the single `getStatus()` query
   with `tenant-leak-allow:` because the `rail_external_ref` is itself
   tenant-scoped (callers only receive refs for instructions they
   originated) and the PK lookup is globally unique.

### Test status
- New smoke `tests/billing_suggest_invoice_smoke.php`
  → **42 / 42 ✓** (lib + API + modal + wiring).
- New smoke `tests/mercury_rail_driver_smoke.php`
  → **30 / 30 ✓** (source + behavioural; instantiates real driver,
  calls getStatus, asserts registry inclusion).
- Updated `tests/payment_rails_enhancements_smoke.php` legacy
  assertion from "2 rails" → "3 rails".
- Full PHP suite: **373 / 375 passing** — only the 2 documented
  sandbox-bound failures (`accounting_phase2_a7`, `tenant_mail_senders`).
- All sentries green: tenant-leak, schema-contract, auth-gate, HY093,
  lane-classifier.
- Vite build → bundle `coreflux-BDjnSgcg`. All four sync points
  consistent.

### Files touched
- `modules/billing/lib/billing.php` (new suggestion helper)
- `modules/billing/api/invoices.php` (new action)
- `modules/billing/ui/SuggestInvoiceModal.jsx` (new)
- `modules/placements/ui/PlacementTimesheetsTab.jsx` (Suggest button)
- `modules/placements/ui/PlacementDetail.jsx` (placement prop)
- `core/payment_rails.php` (driver registry expanded)
- `core/payment_rails/mercury_driver.php` (new)
- `tests/billing_suggest_invoice_smoke.php` (new)
- `tests/mercury_rail_driver_smoke.php` (new)
- `tests/payment_rails_enhancements_smoke.php` (rail count update)

### Deploy notes for ops
1. No new migrations required.
2. Tenants who want to use Mercury as their disbursement rail:
   Treasury → Mercury Settings (connect API token) → AP/Payroll
   Settings → set `disbursement_rail` to `mercury`. From there every
   batch flows through the existing SoD approval queue.
3. "Suggest invoice" requires the tenant to have AI enabled for the
   `suggestion` feature class. Without AI it still works — the memo
   just falls back to a deterministic string.

---

## Session — 2026-02 (Batch 4 — Flexible Invoicing & Payables, day-level)

User direction: "Apply same flexibility to creating payables" — make
invoice/payable creation work directly from `time_entries` (day-level)
in addition to the existing bundle-driven (period-close) flow.

### Shipped

1. **Backend lib helpers**:
   - `billingBuildDraftFromTimeEntries($tenantId, $timeEntryIds, $aggregation)`
     in `modules/billing/lib/billing.php` — accepts a flat list of
     approved/billable time entry IDs; looks up `placementCurrentRate()`
     per entry's `work_date`; applies OT/DT multipliers per
     `hour_type`; groups per `per_day` / `per_placement` /
     `per_client`; emits the same invoice + lines structure as the
     bundle helper with `source_type='time_entry'`, `bundle_ids=[]`,
     and an `entry_ids` audit trail. Hard cap 500 entries / call.
   - `apBuildDraftFromTimeEntries($tenantId, $timeEntryIds, $aggregation)`
     in `modules/ap/lib/ap.php` — mirror for AP: `per_day` /
     `per_placement` / `per_vendor`; joins
     `placement_corp_details` to surface corp name for c2c vendors;
     stamps `is_1099_eligible` per individual vs corp.

2. **API surface**:
   - `POST /modules/billing/api/invoices.php?action=from-time-entries`
     — `billing.invoice.draft`-gated; body
     `{ time_entry_ids: [...], aggregation }`; persists each draft
     invoice in a transaction, upserts the companies directory client,
     emits `billing.invoice.created` audit with `source=time_entries` +
     `entry_ids`.
   - `POST /modules/ap/api/bills.php?action=from-time-entries`
     — `ap.bill.create`-gated; same pattern; upserts vendor in
     `ap_vendors_index`; audits as `source=time_entries`.
   - `GET /modules/staffing/api/timesheets.php?action=approved_entries`
     — surfaces the candidate-entry picker rows; filters
     `placement_id`, `person_id`, `date_from`, `date_to`; `purpose`
     switches between `billable` and `payable` to drive the AR vs AP
     modal.

3. **React modals**:
   - `modules/billing/ui/InvoiceFromTimeEntriesModal.jsx` — date range
     + placement (optional) + aggregation radio buttons; auto-fetches
     candidates with a 250ms debounce; checkbox-per-row picker with
     select-all; shows selected hours + count footer; posts to the
     billing API; calls `onCreated` callback to close + reload list.
   - `modules/ap/ui/BillFromTimeEntriesModal.jsx` — mirror for AP
     payables (per_day / per_placement / per_vendor).

4. **List wiring**:
   - `InvoicesList` — new "New from approved hours (day-level)" CTA
     between the bundle CTA and the CSV import.
   - `BillsList` — same CTA pattern under "New from time bundle".

5. **Schema-contract sentry**: dropped the `p.bill_to_address_json`
   column reference from the new draft builder (column doesn't exist
   on `placements`; client bill-to is populated at approval time, not
   draft time).

### Test status
- New smoke `tests/invoicing_payables_from_time_entries_smoke.php`
  → **59 / 59 ✓** locking lib + API + modals + list wiring.
- Full PHP suite: **371 / 373 passing** — only the two documented
  sandbox-bound failures remain (`accounting_phase2_a7_smoke.php`,
  `tenant_mail_senders_smoke.php`).
- Schema contract sentry, tenant-leak sentry, auth-gate, HY093 sentries
  all green.
- Vite build → bundle `coreflux-Dp_1ptkf`. All four sync points
  consistent.

### Files touched
- `modules/billing/lib/billing.php` (new helper)
- `modules/ap/lib/ap.php` (new helper)
- `modules/billing/api/invoices.php` (new action)
- `modules/ap/api/bills.php` (new action)
- `modules/staffing/api/timesheets.php` (approved_entries action)
- `modules/billing/ui/InvoiceFromTimeEntriesModal.jsx` (new)
- `modules/ap/ui/BillFromTimeEntriesModal.jsx` (new)
- `modules/billing/ui/InvoicesList.jsx` (CTA wiring)
- `modules/ap/ui/BillsList.jsx` (CTA wiring)
- `tests/invoicing_payables_from_time_entries_smoke.php` (new)

### Deploy notes for ops
1. No new migrations required — uses existing `time_entries`,
   `billing_invoices`, `billing_invoice_lines`, `ap_bills`,
   `ap_bill_lines`.
2. CTA appears for any user with `billing.invoice.draft` /
   `ap.bill.create` permissions respectively.

---

## Session — 2026-02 (Batch 2 — Time & Placements UX Rebuild)

User direction: "Click into individual timesheets instead of submitting
the full week. Approve rates inside the placement workflow. Build a
timesheet view at the placement level (history / pending / create
new)."

### Shipped

1. **New backend GET actions** on
   `modules/staffing/api/timesheets.php`:
   - `detail&id=N` — fetch one timesheet header + all entries joined
     to placement title / client name.
   - `list_for_placement&placement_id=N` — every timesheet that
     touched THIS placement, with per-placement hours + billable hours
     aggregated. Filters: status, period_start, period_end.
   - `detail_for_placement&id=N&placement_id=N` — single timesheet
     filtered to one placement's entries; returns aggregated
     `placement_hours`.

2. **React pages**:
   - `modules/staffing/ui/TimesheetsList.jsx` — index page over every
     visible timesheet, with status / period / person filters, status
     pill, and "Open" link routing to the drill-in.
   - `modules/staffing/ui/TimesheetDetail.jsx` — single-timesheet
     read-only view with by-placement summary section, action buttons
     gated by status: `draft` → "Edit this week" link; `submitted` →
     Approve + Reject (with reason input); `approved/rejected` →
     read-only. Supports `?placement_id=N` URL param to scope the
     entries display.
   - `modules/placements/ui/PlacementTimesheetsTab.jsx` — embedded
     "Timesheets" tab inside PlacementDetail; splits results into
     "Pending approval" + "History" sections; per-row link to the
     placement-scoped detail view.

3. **Routing & wiring**:
   - `StaffingModule.jsx` — `timesheets/` now resolves to
     TimesheetsList; `timesheets/week` → existing TimesheetWeek;
     `timesheets/:id` → TimesheetDetail.
   - `PlacementDetail.jsx` — new "Timesheets" tab between "Cycles" and
     "Documents".

4. **Rate approval surfacing**: the existing RatesTab inside
   PlacementDetail already exposes per-row Approve buttons + a
   "Approve all drafts" CTA — Batch 2 confirms this is functional and
   does not require new wiring.

### Test status
- New smoke `tests/timesheets_drill_in_and_placement_smoke.php`
  → **70 / 70 ✓** locking API actions, list page, detail page,
  placement tab, route wiring.
- Updated `tests/staffing_shell_and_weekly_timesheet_smoke.php` to
  match the new explicit-route structure (list, week, :id).
- Full PHP suite: **370 / 372 passing** (only the two known sandbox
  failures).

### Files touched
- `modules/staffing/api/timesheets.php` (detail, list_for_placement,
  detail_for_placement)
- `modules/staffing/ui/TimesheetsList.jsx` (new)
- `modules/staffing/ui/TimesheetDetail.jsx` (new)
- `modules/placements/ui/PlacementTimesheetsTab.jsx` (new)
- `modules/staffing/ui/StaffingModule.jsx` (sub-routes)
- `modules/placements/ui/PlacementDetail.jsx` (Timesheets tab)
- `tests/timesheets_drill_in_and_placement_smoke.php` (new)
- `tests/staffing_shell_and_weekly_timesheet_smoke.php` (assertion
  updated)

---

## Session — 2026-02 (Batch 3 — Cross-tenant Intercompany Approval Workflow)

User direction (after Jaz finish, prioritising 3→2→4): "to batch 3 then
2 then 4" → ship the propose → counterparty-approve → post-to-leg
workflow for cross-tenant intercompany so each entity's CFO controls
what lands on their books, mirroring the SoD model the rest of CoreFlux
runs on.

### Shipped

1. **Migration 104 — `intercompany_xtenant_queue`**
   (`core/migrations/104_intercompany_xtenant_queue.sql`):
   - Holds one row per pending cross-tenant IC proposal: shared
     `intercompany_ref`, source side (`source_tenant_id`,
     `source_entity_id`, `source_je_id`, account codes), target side
     (mirror columns, `target_je_id` deferred), full money trail
     (`amount`, `currency`, `fx_rate`, `target_amount`, `target_currency`),
     and workflow state (`status` ENUM
     `pending/approved/declined/expired/reversed`,
     `requested_by_user_id`, `decided_by_user_id`, `decline_reason`,
     `expires_at`).
   - `UNIQUE(intercompany_ref)` + per-side status indexes for the inbox
     /outbox queries + a `status,expires_at` index for the daily cron.

2. **Workflow helpers**
   (`modules/accounting/lib/cross_tenant_intercompany.php`):
   - `accountingProposeCrossTenantIntercompany()` — posts the source
     leg immediately (Dr IC Receivable / Cr cash on source's books),
     stamps the JE with the shared `intercompany_group_id`, and inserts
     a `pending` row carrying everything the target needs to act.
     `ttl_days` (default 14) computes `expires_at`. Same-tenant +
     same-master guards retained. Idempotency keys distinct from the
     immediate-post variant: `cross_intercompany_propose:{ref}:from`.
   - `accountingApproveCrossTenantIntercompany()` — posts the target
     leg (Dr cash / Cr IC Payable on target's books), stamps the queue
     row `approved`, records `target_je_id`. Idempotent on already-
     approved rows (returns the stored target_je_id).
   - `accountingDeclineCrossTenantIntercompany()` — reverses the source
     leg via `accountingReverseJe()`, stamps the queue row `declined`,
     persists `decline_reason`.
   - `accountingListCrossTenantIntercompanyInbox()` /
     `accountingListCrossTenantIntercompanyOutbox()` — list helpers
     joining `tenants` for human-readable names, status filter, limit
     (1–500). Numeric hydration on the JSON wire.
   - `accountingExpireCrossTenantIntercompanyPending()` — daily cron
     driver: walks pending rows past `expires_at`, reverses the source
     leg, stamps `expired`. Idempotent + per-row error isolation.

3. **API surface** (`modules/accounting/api/intercompany.php`):
   - `GET ?action=xtenant_inbox&status=…` — pending counterparty approvals.
   - `GET ?action=xtenant_outbox&status=…` — entries this tenant proposed.
   - `POST ?action=xtenant_propose` — `accounting.je.post` gated. Rejects
     same-tenant proposals; passes through the full opts vocabulary
     (codes, currency, fx, posting_date, ttl_days, target_entity_id).
   - `POST ?action=xtenant_approve` — authority gate: caller's active
     tenant MUST match the row's `target_tenant_id`.
   - `POST ?action=xtenant_decline` — same authority gate + required
     `reason`.
   - `POST ?action=xtenant_expire_sweep` — admin-only manual trigger;
     same code path the cron worker uses.

4. **Cron worker**
   (`cron/intercompany_xtenant_expire_worker.php`): runs daily at 09:00,
   calls `accountingExpireCrossTenantIntercompanyPending()`, emits a
   `scanned=N expired=M` log line. Per-row error isolation.

5. **Counterparty inbox UI**
   (`modules/accounting/ui/XTenantIntercompany.jsx`): mounted at
   `/modules/accounting/xtenant-ic` (between "Intercompany" and
   "Elimination" tabs):
   - **Inbox** sub-tab — rows where THIS tenant is the target. Approve
     button (with money confirmation) + Decline button (opens inline
     reason input). Status filter strip.
   - **Outbox** sub-tab — rows THIS tenant proposed (visibility into
     awaiting/decided rows).
   - **Propose new** sub-tab — counterparty dropdown (sibling/parent
     from `/api/sub_tenants.php`), amount + memo + posting_date +
     account codes (1700/2700/1000/1000 defaults), currency pair + FX
     rate, TTL days. On success the page auto-flips to Outbox to show
     the new pending row.
   - Status pills colour-coded (amber pending / green approved / red
     declined / grey expired/reversed). Multi-currency rows render
     `from → to @ fx` on the amount column.

6. **AccountingModule wiring** — new "Cross-tenant IC" nav tab + route
   between Intercompany and Elimination.

7. **Tenant-leak sentry green**: three UPDATEs on the new queue table
   are annotated with `// tenant-leak-allow:` comments noting the
   table is cross-tenant by design (source+target tenants).

8. **Test smoke**
   (`tests/intercompany_xtenant_workflow_smoke.php`) — **89 / 89 ✓**
   locking every layer (migration schema, all six lib helpers, all six
   API actions with their authority gates, cron worker shape, every
   React testid, AccountingModule wiring).

### Test status
- Full PHP suite: **369 / 371 passing**. Only the two documented
  sandbox-bound failures remain (`accounting_phase2_a7_smoke.php`,
  `tenant_mail_senders_smoke.php` — no live MySQL / SMTP socket).
- New smoke 89/89 ✓.
- All sentries (tenant-leak, auth-gate, HY093 placeholder, lane
  classifier) green.
- Vite build → bundle `coreflux-DSobN1kW`. `scripts/sync_bundle.sh`
  synced `.deploy-version`, `spa-assets/`, `dashboard/dist/index.html`,
  and service-worker `CACHE_VERSION`.

### Files touched
- `core/migrations/104_intercompany_xtenant_queue.sql` (already present)
- `modules/accounting/lib/cross_tenant_intercompany.php` (expire sweep + tenant-leak annotations)
- `modules/accounting/api/intercompany.php` (xtenant_* actions)
- `modules/accounting/ui/XTenantIntercompany.jsx` (new — counterparty inbox)
- `modules/accounting/ui/AccountingModule.jsx` (route + tab wiring)
- `cron/intercompany_xtenant_expire_worker.php` (new)
- `tests/intercompany_xtenant_workflow_smoke.php` (new)

### Deploy notes for ops
1. Push to Cloudways → `update.php` applies migration 104.
2. Schedule the cron: `0 9 * * * php /home/master/applications/<app>/public_html/cron/intercompany_xtenant_expire_worker.php`.
3. Operators see the new "Cross-tenant IC" tab inside the Accounting
   module. Inbox shows pending counterparty approvals; Outbox shows
   what they've proposed.

### Roadmap (next — Kunal's prioritised order continues)

**Batch 2 — Time + Placements UX rebuild (P0, NEXT)**:
- Click into individual timesheets (per-week, per-placement drill-in).
- Placement detail page: Timesheets section (history / pending /
  create new).
- Approve rates inside the placement workflow.

**Batch 4 — Flexible invoicing & payables (P1, AFTER Batch 2)**:
- Create invoice from approved hours: placement + daily granularity.
- Create payable from approved hours: same picker reused.

### Backlog (unchanged priority)
- (P1) Per-tenant AI feature flag UI (`use_llm` admin toggle).
- (P1) Phase 8 — Business Event Layer infrastructure.
- (P1) Mercury Webhooks hardening.
- (P2) Gusto integration / QBO hardening (parked).
- (P2) CFO Dashboard role/access gating.
- (P3) Customer portal Phase A.
- (P3) Engagements module.
- (P3) AI Digest Scheduler.

---

## Session — 2026-02 (Jaz finish — Sync now button + bidirectional CoA)

User direction (after accounting basics): "first finish up the jaz
integration. there's no sync button. also, chart of accounts only sync
one way (pull from Jaz), why not either way?"

### Shipped

1. **Bidirectional CoA on the sync_config model**
   (`core/accounting/sync_config_service.php`):
   - Removed the silent coercion of `chart_of_accounts` to `pull|off`
     in both the read AND write code paths.  Operators can now pick
     `push` or `two_way` for CoA.

2. **`createAccount()` on the provider adapter contract**
   (`core/accounting/provider_adapter.php`):
   - Added `abstract public function createAccount(int $tenantId,
     int $subTenantId, array $account, string $idempotencyKey): array`
     so any provider (Jaz today; Zoho / QBO later) implements outbound
     CoA push the same way they implement draft-bill push.

3. **Jaz outbound `createAccount()` implementation**
   (`core/accounting/jaz_adapter.php`):
   - POST to Jaz `chart-of-accounts` with `accountCode` / `accountName`
     / uppercased `accountType` / `currency.code`.
   - **409-conflict is idempotent-success**: on `409`, the adapter
     re-queries `chart-of-accounts?accountCode=X` and returns the
     existing row's `provider_account_id` so a re-run of the sync
     button never blocks the operator.
   - Returns a normalized response shape (`provider_object_id`,
     `provider_account_code`, `provider_account_name`,
     `provider_account_type`) so the mapper can persist a clean
     `accounting_account_mappings` row without re-shaping.

4. **Push-side auto-mapper**
   (`core/accounting/account_mapping_service.php`):
   - New `accountingAccountMappingsPushToProvider($tenantId,
     $subTenantId, $provider, $userId)`:
     - Pre-fetches the provider's existing CoA, lowercase-keyed by
       `code` so the push pass skips codes already upstream
       (treats them as `skipped_existing`).
     - For genuinely-new CoreFlux accounts: calls
       `$adapter->createAccount()` with a deterministic idempotency
       key (`coa_push:{tenantId}:{subTenantId}:{code}`) and persists
       the mapping via `accountingAccountMappingsSave()`.
     - Per-account best-effort: any failure surfaces in the returned
       `errors` array but never blocks the rest of the batch.

5. **`sync_now` action on `/api/accounting.php`** — the "Sync now"
   button's backend:
   - Reads the sub-tenant's `sync_config`, branches per entity_type:
     - `chart_of_accounts: pull` → `accountingAccountMappingsAutoMap`.
     - `chart_of_accounts: push` → `accountingAccountMappingsPushToProvider`.
     - `chart_of_accounts: two_way` → both, pull first so the push
       pass can skip codes already mapped from the pull pass.
   - Async-outbox-driven entities (bills, invoices, payments,
     journal_entries, intercompany, contacts) surface a note pointing
     to `?action=command_status` rather than racing the cron worker.
   - Accepts an optional `entity_types: [...]` body filter so the UI
     can offer a "CoA only" button alongside the global one.
   - RBAC-gated via `accounting.connection.manage`.

6. **`JazSyncNowCard` React component** in
   `dashboard/src/pages/JazIntegrationSettings.jsx`:
   - Two buttons: **Sync everything now** (no filter) and **CoA only**
     (`entity_types: ['chart_of_accounts']`).
   - Per-entity results table renders the outcome ("pull: 12 mapped ·
     push: 3 created (1 skipped)") so the operator can see exactly
     what happened.
   - Flash banner summarises the most operator-relevant counter
     ("Synced. CoA · 12 mapped from Jaz · 3 pushed to Jaz (1 already
     existed).").
   - Mounted between the sync-config card and the account-mapping card
     in the integration settings page, with a `Step 3b — Sync now`
     legend so the operator workflow is linear.

7. **JazSyncConfigCard CoA dir picker lifted**:
   The hardcoded `entity === 'chart_of_accounts' ? ['pull','off']` was
   removed in favour of the full `allowedDirs` array.  Operators can
   now flip CoA into push or two_way without dropping into SQL.

8. **CI lane classifier rebalance**
   (`scripts/ci_lane_classifier.sh`):
   - `jaz_*`, `zoho_*`, `qbo_*`, `accounting_basics_*`, `rbac_cpa_*`
     now route to the `modules` lane instead of falling through to
     `core`.  Keeps the 50%-cap-per-lane assertion in
     `ci_lane_classifier_smoke.php` green.

9. **Test smoke** (`tests/jaz_sync_button_and_coa_bidir_smoke.php`) —
   **45 / 45 ✓** locking the entire surface: the service-layer
   restriction lift, the adapter contract, the Jaz POST impl, the
   push-side mapper, the API action handler, and every UI testid.

### Test status
- Full PHP suite: **368 / 370 passing**. Only the two documented
  sandbox-bound failures remain (`accounting_phase2_a7_smoke.php`,
  `tenant_mail_senders_smoke.php`).
- All sentries (tenant-leak, auth-gate, HY093 placeholder, lane
  classifier) green.
- New smoke 45/45 ✓.
- Vite build → bundle `coreflux-DNmycMIx`.

### Files touched
- `core/accounting/sync_config_service.php` (CoA dir restriction lifted)
- `core/accounting/provider_adapter.php` (createAccount abstract)
- `core/accounting/jaz_adapter.php` (createAccount impl + 409-idempotent)
- `core/accounting/account_mapping_service.php` (push-side helper)
- `api/accounting.php` (sync_now action)
- `dashboard/src/pages/JazIntegrationSettings.jsx` (Sync Now card + CoA dir lift)
- `scripts/ci_lane_classifier.sh` (lane rebalance)
- `tests/jaz_sync_button_and_coa_bidir_smoke.php` (new)

### Roadmap (next — Kunal's prioritised order)

**Batch 3 — Cross-tenant intercompany approval** (P0, agreed FIRST):
- Posting an intercompany JE in entity A with a counterparty in entity
  B's tenant creates a pending approval row in tenant B.
- Tenant B's admin sees it in their inbox + approves/declines.
- On approve, both legs post simultaneously to both tenants' books.
- New `intercompany_approval_queue` table + counterparty inbox UI.

**Batch 2 — Time + Placements UX rebuild** (P0, AFTER Batch 3):
- Click into individual timesheets.
- Placement detail page: Timesheets section (history / pending / new).
- Approve rates inside the placement workflow.
- Flexible invoice/payable creation: placement + daily granularity.

**Batch 4 — Expand patterns elsewhere** (later, per user direction).

### Backlog (unchanged priority)
- (P1) Per-tenant AI feature flag UI (`use_llm` admin toggle).
- (P1) Phase 8 — Business Event Layer infrastructure.
- (P1) Mercury Webhooks hardening.
- (P2) Gusto integration / QBO hardening.
- (P2) CFO Dashboard role/access gating.
- (P3) Customer portal Phase A.
- (P3) Engagements module.
- (P3) AI Digest Scheduler.

---

## Session — 2026-02 (Accounting Basics — un-blocks Bookkeeping/Bank Rec/Consolidation)

User direction (after CPA-layer phase 2): "we're still not buttoned up on basic
accounting functions and errors? […]" — Kunal called out NINE concrete grievances
across the screens he was actually using.  This session ships the **first 4**:

  1. Bookkeeping page crashes with `Couldn't load books health: Database
     column 'statement_end_date' is missing — a migration probably needs to run.`
  2. Connected accounts (Plaid + Mercury) show up in Treasury / Bank Rec
     but **not in Chart of Accounts** (or anywhere downstream that reads CoA).
  3. The parent-tenant entity is mis-modelled — sub-tenant entities were
     hand-named after the parent ("Main Entity · Arabella Talent Partners"
     under the Seven Generations sub-tenant), and parent users can't see
     sub-tenant entities in the Consolidation parent/child picker.
  4. Mercury exists as a *secondary* "Send via Mercury" action button on
     AP payment rows — not as a first-class **method option from the
     Record-payment modal itself**.  Kunal also asked to fold the separate
     Treasury Mercury payment screen into a single AP-anchored pay flow.

The remaining 5 grievances (timesheet drill-in, placement-level rates +
timesheet sub-page, flexible invoice/payable creation at placement &
daily granularity, cross-tenant intercompany approval workflow) are
queued as Batch 2 in the roadmap.

### Shipped

1. **Migration 101 — recon column aliases + CoA backfill**
   (`core/migrations/101_accounting_recon_aliases_and_coa_backfill.sql`):
   - Adds `statement_end_date DATE` and `reconciled_through_date DATE`
     to `accounting_reconciliations` (idempotent via information_schema
     guard).  Backfills both from `period_end` for every existing row.
   - Adds index `idx_arec_tenant_status_end (tenant_id, status,
     statement_end_date)` so the `books_health.php` query stops
     scanning the whole table.
   - **CoA backfill**: for every `accounting_bank_accounts` row whose
     `gl_account_code` does NOT have a matching `accounting_accounts`
     row, INSERTs one with `account_type=asset`, `normal_side=debit`,
     `is_postable=1`, and a human-readable name like "Mercury Checking …7793".
   - Same pass for `treasury_liability_accounts` → `liability` /
     `credit`.  Idempotent (NOT EXISTS guard).

2. **Migration 102 — sub-tenant entity seed**
   (`core/migrations/102_subtenant_entity_seed_and_parent_wiring.sql`):
   - INSERT IGNORE one `accounting_entities` row per tenant (master AND
     sub) using the tenant's OWN name.  Derives a 4-letter `code` via
     nested `REPLACE()` calls (MySQL 5.7 compatible, no
     `REGEXP_REPLACE`).
   - Renames any single-entity tenant whose `legal_name` doesn't match
     the tenant's name (catches the hand-named "Arabella Talent
     Partners" under Seven Generations).
   - Wires `parent_entity_id` on sub-tenant entities to the parent
     tenant's lowest-id active entity.  Idempotent: only touches NULL
     parent_entity_id rows.

3. **Inline entity seed at provisioning**
   (`core/sub_tenants.php::subTenantProvision()`): every new sub-tenant
   now gets its own `accounting_entities` row synchronously inside the
   provisioning transaction, with `parent_entity_id` pre-wired.
   Mirrors migration 102's code derivation so a hand-re-run is a no-op.

4. **Cross-tenant entity surface** (`core/active_entity.php`):
   - Surface widened: when the active tenant is a **master**, the
     dropdown now lists entities across the entire active sub-tenant
     tree (Seven Generations parent → Arabella + every other sub).
     When the active tenant is a sub, the dropdown stays narrow to its
     own entities (no parent leakage).
   - Every result row now carries `tenant_id`, `tenant_name`,
     `tenant_kind` (`master`/`sub`), `tenant_parent_id`, and an
     `is_active_tenant` flag so the SPA can render labels like
     "Seven Generations · Main Entity" and group the picker by tenant.
   - New helper `activeEntityResolveAllowedTenantIds()` is reused by
     both `activeEntityAvailable()` (rendering) and `activeEntitySet()`
     (validation), so the picker can't be tricked into setting an
     entity outside the allowed set.
   - `tenant-leak-allow:` comment documents the cross-tenant scope so
     the static analyzer stays green.

5. **Mercury as a first-class AP payment method**:
   - Migration 103 extends `ap_payments.method` and
     `ap_vendors_index.payment_method` ENUMs to include `mercury`
     (idempotent via information_schema guards).
   - `modules/ap/ui/PaymentsList.jsx::RecordPaymentModal` now accepts
     `mercuryEnabled` prop (passed from the parent's existing
     `mercuryConnected` flag).  Adds a `<option value="mercury">`
     entry to the method dropdown, gated `disabled` when Mercury isn't
     connected.  Surfaces an inline helper card explaining that a
     Mercury `payment_instruction` will be queued in **Draft** so
     treasury ops still approves before money moves.
   - Best-effort post-create hook: when method=mercury and the
     connection is wired, the modal automatically POSTs
     `?action=send_via_mercury` right after create so the operator
     doesn't need a second click.  Failure here is non-fatal — the
     row's "Send via Mercury" chip is still available.

6. **Test smoke** (`tests/accounting_basics_2026_02_smoke.php`) —
   **51 / 51 ✓** locking all four fixes (migration SQL shape,
   `subTenantProvision()` inline seed, the new `active_entity.php`
   surface, and the React UI testids).

### Test status
- Full PHP suite: **367 / 369 passing**. Only the two documented
  sandbox-bound failures remain (`accounting_phase2_a7_smoke.php`,
  `tenant_mail_senders_smoke.php` — no live MySQL / SMTP socket).
- New smoke 51/51 ✓.
- All sentries (tenant-leak, auth-gate, HY093 placeholder) green.
- Vite build → bundle `coreflux-DqARW7om`. All four sync points consistent.

### Files touched
- `core/migrations/101_accounting_recon_aliases_and_coa_backfill.sql` (new)
- `core/migrations/102_subtenant_entity_seed_and_parent_wiring.sql` (new)
- `core/migrations/103_ap_payments_method_mercury.sql` (new)
- `core/sub_tenants.php` (inline entity seed at provisioning)
- `core/active_entity.php` (cross-tenant entity surface + helper)
- `modules/ap/ui/PaymentsList.jsx` (Mercury option + helper card + auto-route)
- `tests/accounting_basics_2026_02_smoke.php` (new)

### Deploy notes for ops
1. Push to Cloudways → `update.php` applies migrations 101 / 102 / 103.
   All three are idempotent via `information_schema` guards.
2. After deploy, hit Bookkeeping → "Books health" should load without
   the `statement_end_date` error.
3. Open Accounting → Chart of Accounts → Mercury Checking, Mercury
   Savings, First Citizens should appear with their existing GL codes.
4. As the master tenant (Seven Generations), the Entity ▾ dropdown will
   show the parent's entities + every sub-tenant's entity grouped by
   tenant.  As a sub-tenant the dropdown stays scoped to its own.
5. AP → Record payment → Method dropdown now shows "Mercury" when the
   Mercury connection is wired.

### Roadmap (next — Kunal's remaining grievances)

**Batch 2 — Time + Placements UX rebuild (P0)**:
- Click into individual timesheets (per-week, per-placement drill-in).
- Placement detail page: "Timesheets — history, pending approvals,
  create new" section.
- Approve rates inside the placement workflow.
- Create invoice from approved hours: placement + daily granularity.
- Create payable from approved hours: same picker reused.

**Batch 3 — Cross-tenant intercompany approval (P0)**:
- Posting an intercompany JE in one entity creates a pending
  approval row in the counterparty entity's tenant.
- Counterparty admin sees it in their inbox + approves/declines.
- On approve, both legs post simultaneously to both entities' books.
- Uses the existing `tenant_memberships` + audit infrastructure +
  a new `intercompany_approval_queue` table.

**Batch 4 — Expand the patterns to anywhere applicable**:
- Apply the same flexible-picker logic to other invoice/payable
  creation surfaces the user calls out.

### Backlog (unchanged priority)
- (P1) Per-tenant AI feature flag UI (`use_llm` admin toggle).
- (P1) Phase 8 — Business Event Layer infrastructure.
- (P1) Mercury Webhooks hardening.
- (P2) Gusto integration / QBO hardening (parked).
- (P2) CFO Dashboard role/access gating.
- (P3) Customer portal Phase A.
- (P3) Engagements module (Fixed-fee project accounting).
- (P3) AI Digest Scheduler.

---

## Session — 2026-02 (CPA-layer Phase 2 — Bulk-seat + Cross-tenant audit + Firm dashboard)

User direction (after CPA-layer kickoff): "yeah, next items go." → ship all three
items from the previous roadmap (bulk-seat onboarding, CPA-scoped audit, firm
dashboard KPIs) in one batch.

### Shipped

1. **Bulk-seat onboarding** on `CpaFirmService::upsert()`:
   - New `seed_memberships: [{ user_id, persona_label?, persona_type?, profile_key? }]`
     accepted on the input array. After the link upserts, each seed row
     triggers a `tenant_memberships` upsert ON THE CLIENT TENANT
     (status=active, invited_at/accepted_at=NOW), and if `profile_key` is
     set, `PermissionProfileService::apply()` immediately stamps that
     profile's grants. Per-row best-effort: a failing seed row never
     blocks the link upsert or the rest of the roster.
   - Return type widened to `int | array{id, seeded[]}` — the seeded
     array surfaces per-row outcomes (membership_id, grants_applied, or
     `error` string) so the UI can show "Seeded X of Y" with failure
     callouts.
   - Endpoint passthrough: `/api/admin/cpa_firms.php?action=save` now
     returns the `seeded` block when present (back-compat with int-only).
   - `CpaFirmService::linkedClientTenantIdsForUser($userId)` + companion
     `firmTenantIdsForUser($userId)` helpers added — used by the audit
     endpoint + firm dashboard to scope queries to the user's portfolio
     in one SQL hop.

2. **CPA-scoped audit endpoint** (`/api/admin/cpa_audit.php`):
   - Auth: any authenticated user (the portfolio resolver gates by
     firm membership). No admin gate.
   - Unions `cross_tenant_accounting_audit` rows AND `membership_audit`
     rows where any tenant in the user's CPA portfolio is involved
     (acting tenant OR left OR right). Each row is tagged with
     `source: accounting | membership` so the UI can pivot.
   - Optional `since=YYYY-MM-DD`, `action=…`, `limit` (1–500) filters.
   - Migration-absent path returns 200 + empty `rows` (not 503) so a
     fresh tenant with no CPA scope doesn't error-banner.
   - Tenant-leak sentry green by construction (cross-tenant by design;
     `tenant-leak-allow:` comment documents the portfolio scope).

3. **Firm dashboard KPI endpoint** (`/api/admin/cpa_firm_dashboard.php`):
   - Three KPIs per client tenant:
     - `open_exceptions` — `accounting_exceptions` where
       `status IN ('open','assigned')`
     - `draft_outbox` — `accounting_outbox_events` where
       `status IN ('queued','retrying','dead_letter')`
     - `late_close_periods` — `accounting_periods` where
       `end_date < CURDATE() AND status IN ('open','soft_closed')`
   - Per-client `needs_attention = sum(all 3)`. Per-firm + portfolio-wide
     totals computed server-side so the UI is a pure read.
   - Each KPI query is wrapped in try/catch so a missing migration on
     any one of the three module tables degrades to 0 for that KPI
     without 5xx-ing the request.
   - Optional `firm_tenant_id=N` filter narrows the rollup to one firm.

4. **`CpaFirmClientsAdmin.jsx`** — firm-side admin (mounted at
   `/admin/cpa-clients`):
   - CRUD list over `cpa_firm_client_links`.
   - "Link client" form with relationship_type + status + primary CPA
     dropdown + engagement start date + notes.
   - **Bulk-seat sub-form**: roster table where each row is a
     {user, persona_label, persona_type, profile_key} tuple. Submit posts
     the whole array in one request; the seed-outcome card surfaces
     "Seeded X of Y" with failure callouts inline.
   - "End engagement" button per row (soft `status=ended`).

5. **`CpaFirmDashboard.jsx`** — multi-tenant rollup (mounted at
   `/admin/cpa-dashboard`):
   - Portfolio totals strip (Firms / Clients / Exceptions / Outbox /
     Late close).
   - Per-firm card with a per-client table sorted by
     `needs_attention DESC` so the worst client floats to the top.
   - `NeedsAttentionPill` — green ("all clear") vs amber (1–9) vs red
     (10+) at a glance.
   - "Open" button per row → `/api/sub_tenants.php?action=switch` +
     full SPA reload to flip into the client's books in one click.
     Disabled when the user has no membership on the destination client.
   - Firm filter dropdown (only shown when ≥2 firms).

6. **`CpaAuditPage.jsx`** — CPA-scoped audit feed (mounted at
   `/admin/cpa-audit`):
   - Filter strip: `since` (date), `action` (text + datalist of distinct
     actions seen in the current page), `limit` (50/100/200/500), Apply
     button.
   - Table with `Source` badge (accounting / membership), action,
     acting tenant, counterparty, actor user, occurred_at timestamp.
   - YYYY-MM-DD client-side validation on `since`.

7. **AdminModule wiring** — imports, routes, sidebar links, and
   overview `ActionCard` tiles for all three new pages.

8. **Test smoke** (`tests/rbac_cpa_layer_phase2_smoke.php`) —
   **106 / 106 ✓** locking the bulk-seat service contract, both new
   endpoints, all three React pages with every testid, and AdminModule
   wiring.

### Test status
- Full PHP suite: **366 / 368 passing**. Documented sandbox-bound failures:
  `accounting_phase2_a7_smoke.php`, `tenant_mail_senders_smoke.php`.
- All sentries (tenant-leak, auth-gate, HY093 placeholder) green.
- New smoke `rbac_cpa_layer_phase2_smoke.php` → 106/106 ✓.
- Prior CPA-kickoff smoke (`rbac_cpa_layer_kickoff_smoke.php`) → 89/89 ✓.
- Prior B6 smoke (`rbac_b6_profiles_smoke.php`) → 88/88 ✓.

### Bundle / Deploy
- Vite build → bundle `index-K6jUooWI.js`. `scripts/sync_bundle.sh` synced
  `.deploy-version`, `spa-assets/`, `dashboard/dist/index.html`, and
  service-worker `CACHE_VERSION` → `coreflux-K6jUooWI`.
- **Zero new SQL migrations** in this session — re-uses the schema from
  migration 100. Deploys with no DBA action required.

### Files touched
- `core/rbac/cpa_firms.php` (bulk-seat + helpers)
- `api/admin/cpa_firms.php` (seeded passthrough)
- `api/admin/cpa_audit.php` (new)
- `api/admin/cpa_firm_dashboard.php` (new)
- `dashboard/src/pages/CpaFirmClientsAdmin.jsx` (new)
- `dashboard/src/pages/CpaFirmDashboard.jsx` (new)
- `dashboard/src/pages/CpaAuditPage.jsx` (new)
- `dashboard/src/pages/AdminModule.jsx` (3 imports / routes / sidebar / overview)
- `tests/rbac_cpa_layer_phase2_smoke.php` (new)

### Roadmap (next)
- **Per-firm sharable invite link** for brand-new clients whose tenant
  doesn't exist yet (signed URL that walks them through tenant
  creation AND auto-creates the firm↔client link).
- **Drill-through from dashboard** to a per-client exceptions queue
  / outbox review screen — currently the dashboard surfaces counts
  but not the underlying rows.
- **Resend / Slack notifications**: send the firm's primary CPA a
  daily digest of `needs_attention` deltas across their portfolio.

### Backlog (unchanged priority)
- (P1) Per-tenant AI feature flag UI (`use_llm` admin toggle).
- (P1) Phase 8 — Business Event Layer infrastructure.
- (P1) Mercury Webhooks hardening.
- (P2) Gusto integration / QBO hardening (parked).
- (P2) CFO Dashboard role/access gating.
- (P3) Customer portal Phase A.
- (P3) Engagements module (Fixed-fee project accounting).
- (P3) AI Digest Scheduler.

---

## Session — 2026-02 (CPA-layer kickoff + Tenant profile builder UI)

User direction (after RBAC B6 closeout): "yes, tenant level profile builder.
proceed with next items." → ship the tenant-private profile editor PLUS the
first three CPA-layer surfaces in one batch.

### Shipped

1. **`CpaFirmService`** (`/app/core/rbac/cpa_firms.php`) — wraps the
   `cpa_firm_client_links` table that migration 100 stood up:
   - `listClientsForFirm($firmTenantId, ?$status)` — joins `tenants` for
     human-readable names + `users` for the primary CPA contact.
   - `getForFirm($linkId, $firmTenantId)` — visibility-checked single row.
   - `upsert($input, $firmTenantId, $actor)` — INSERT … ON DUPLICATE KEY
     UPDATE on the `uq_firm_client` unique constraint. Validates
     `relationship_type` + `status`, blocks self-link (firm ↔ firm).
   - `endLink($linkId, $firmTenantId, $actor)` — soft `status='ended'`
     with a `engagement_end_date = CURDATE()` default.
   - `deleteLink($linkId, $firmTenantId, $actor)` — hard delete for the
     mistakenly-created case.
   - `portfolioForUser($userId)` — given a user, returns every client
     tenant they can reach via any firm they're a member of (master_admin
     / tenant_admin / cpa* / bookkeeper / client_advisor persona). The
     result includes both their firm persona AND their client-side
     persona so the UI can warn when the user has no membership on the
     destination client tenant yet.
   - Every write appends a `membership_audit` row so the existing Recent
     Access Changes panel surfaces firm-management events too.
   - Tenant-leak sentry green by construction (every SELECT/UPDATE/DELETE
     filters on `firm_tenant_id` or `client_tenant_id`).

2. **`/api/admin/cpa_firms.php`** — admin CRUD + portfolio endpoint:
   - `GET ?action=portfolio` — any authenticated user; groups by firm.
   - `GET` (no action) — list links for the active (firm) tenant.
   - `GET ?id=N` — fetch one link.
   - `POST ?action=save` — upsert.
   - `POST ?action=end` body `{ id }` — soft-end.
   - `DELETE ?id=N` — hard delete.
   - Admin gate (`master_admin` / `tenant_admin` / global admin) applies
     to every action EXCEPT `portfolio`, which only requires auth.
   - 503 when migration 100 hasn't been applied yet.

3. **External-auditor auto-apply** (`api/auth/consume_magic_link.php`):
   When a magic-link consume completes a pending invite AND the accepted
   membership's `persona_type` is `external_auditor`, the consume flow now
   auto-applies the `external_auditor.default` profile. Non-fatal: a
   missing profile or apply error never blocks sign-in. Auditors land in
   a working SPA with the right read-only grants instead of an empty one.

4. **`PermissionProfileBuilder.jsx`** — tenant-private profile editor
   (mounted at `/admin/permission-profiles`):
   - Lists every profile visible to the active tenant with `SYSTEM` /
     `GLOBAL` / `TENANT` badges. System rows are view-only; tenant rows
     are edit + delete.
   - New-profile flow: `profile_key`, `label`, `description`,
     `applies_to_persona` (any / employee / cpa / cpa_partner /
     cpa_staff / bookkeeper / client_advisor / external_auditor / admin
     / manager / contractor), plus a full module-grants matrix
     (people, placements, time, billing, ap, ar, accounting, payroll,
     treasury, cfo, reports, staffing, integrations, rbac × none/read/
     write/admin).
   - Save → `POST /api/admin/permission_profiles.php?action=save`.
   - Delete → `DELETE /api/admin/permission_profiles.php?id=N` (system
     blocked at the service layer).
   - Newly-authored profiles surface immediately in the existing
     `ProfilePicker` on the Memberships admin page (no extra wiring).

5. **`CpaPortfolio.jsx`** — "My CPA clients" landing page (mounted at
   `/admin/cpa-portfolio`):
   - Summary card: # firms + # clients across all firms.
   - Per-firm card: client table with `status`, `relationship_type`,
     and the user's `client_persona` (if they have a membership on that
     client).
   - "Jump in" button per row → `POST /api/sub_tenants.php?action=switch`
     to flip the active tenant + full SPA reload so the new context
     bootstraps cleanly. Disabled when the user has no membership on
     the destination client, with a tooltip explaining how to get one.

6. **AdminModule wiring** — sidebar links, route mounts, and overview
   `ActionCard` tiles for both new pages. The "My CPA clients" tile is
   visible to every admin; it simply renders the empty state when the
   user belongs to zero firms with linked clients.

7. **Smoke test** (`tests/rbac_cpa_layer_kickoff_smoke.php`) —
   **89 / 89 ✓** locks every layer (service surface, endpoint contract,
   external-auditor branch, both React pages with every testid, and the
   AdminModule wiring).

### Test status
- Full PHP suite: **365 / 367 passing**. Documented sandbox-bound failures:
  `accounting_phase2_a7_smoke.php`, `tenant_mail_senders_smoke.php`.
- All sentries (tenant-leak, auth-gate, HY093 placeholder) still green.
- Vite build → bundle `index-CBbv_ozJ.js`. `scripts/sync_bundle.sh` synced
  `.deploy-version`, `spa-assets/`, `dashboard/dist/index.html`, and
  service-worker `CACHE_VERSION` → `coreflux-CBbv_ozJ`.

### Operator next steps (production)
1. Deploy and ensure migration 100 has been applied (no new migration
   in this session — re-uses the schema from the prior session).
2. Tenant admins → Admin → Permission profiles → click "New profile" to
   author firm-private bundles ("Senior Bookkeeper", "Industry overlay").
3. Tenant admins on a CPA firm → Admin → My CPA clients → wire client
   tenants by inviting the client's master_admin or by having a
   platform global admin run the `?action=save` endpoint with the
   `client_tenant_id`.
4. Auditor links flow: when issuing a tokenized auditor URL, set the
   destination membership's `persona_type` to `external_auditor` — the
   consume flow now auto-grants read-only access on every audit-relevant
   module via the seeded `external_auditor.default` profile.

### Files touched
- `core/rbac/cpa_firms.php` (new)
- `api/admin/cpa_firms.php` (new)
- `api/auth/consume_magic_link.php` (external_auditor auto-apply branch)
- `dashboard/src/pages/PermissionProfileBuilder.jsx` (new)
- `dashboard/src/pages/CpaPortfolio.jsx` (new)
- `dashboard/src/pages/AdminModule.jsx` (imports + routes + sidebar + overview cards)
- `tests/rbac_cpa_layer_kickoff_smoke.php` (new)

### Roadmap (next)
- **Bulk-seat onboarding**: extend `cpa_firms.php` upsert with an
  optional `seed_memberships` array so a single firm-admin action can
  link the client tenant AND seat every CPA partner / staff on it with
  the right default profile.
- **CPA-side audit page**: cross-tenant view of every CPA-actor change
  across all client tenants (already-built `cross_tenant_audit.php`
  surface — needs RBAC scoping for the new firm personas).
- **Multi-tenant firm dashboard**: roll up KPIs (open exceptions, draft
  JEs awaiting approval, late-close clients) across every linked client.

### Backlog (unchanged priority)
- (P1) Per-tenant AI feature flag UI (`use_llm` admin toggle).
- (P1) Phase 8 — Business Event Layer infrastructure.
- (P1) Mercury Webhooks hardening.
- (P2) Gusto integration / QBO hardening (parked).
- (P2) CFO Dashboard role/access gating (now plug-in-able with new RBAC).
- (P3) Customer portal Phase A.
- (P3) Engagements module (Fixed-fee project accounting).
- (P3) AI Digest Scheduler.

---

## Session — 2026-02 (RBAC B6 — CPA personas + Permission profiles)

User direction: "next we finish RBAC so we can move to CPA layer." → P0 closeout
of the RBAC stack. Migration 100 had been staged from an earlier session but
NO PHP/React code used it yet — the persona whitelist would reject every
CPA persona_type and the seeded profiles were invisible.

### Shipped
1. **CPA persona whitelist expansion**
   - `/app/api/admin/memberships.php::_ALLOWED_PERSONA_TYPES` now accepts the
     6 migration-100 persona types: `cpa`, `cpa_partner`, `cpa_staff`,
     `bookkeeper`, `client_advisor`, `external_auditor`.
   - Frontend `PERSONA_TYPES` in `RbacMembershipsAdmin.jsx` mirrors the
     new whitelist so the form dropdowns surface them.

2. **`PermissionProfileService`** (`/app/core/rbac/permission_profiles.php`)
   - `listForTenant($tenantId)` — system + global custom + tenant-private;
     tenant-private rows shadow system rows on the same `profile_key`.
   - `getForTenant($id, $tenantId)` / `getByKey($key, $tenantId)` —
     visibility-checked single-row fetch.
   - `upsertForTenant($input, $tenantId, $actor)` — INSERT … ON DUPLICATE
     KEY UPDATE on the tenant-private row. Validates `profile_key` regex
     `[a-z0-9][a-z0-9._-]{0,58}`, blocks empty grants, flags system shadowing
     in the audit detail.
   - `deleteForTenant($id, $tenantId, $actor)` — system profiles cannot be
     deleted (raises RuntimeException). DELETE statement is tenant-scoped
     (defense-in-depth + tenant-leak sentry compliant).
   - `apply($membershipId, $profileId, $tenantId, $actor, $overwrite, $scope)`
     — iterates the profile's `grants_json` and calls
     `RBACResolver::grantModule()` per row. When `$overwrite=true`, revokes
     every existing module grant NOT in the profile first. Supports an
     optional `sub_tenant_scope` array. Audits via
     `RBACResolver::auditMembership('profile_applied', …)`.

3. **`/api/admin/permission_profiles.php`** — Admin CRUD + apply endpoint:
   - `GET` (with `?id` or `?persona`) — list visible profiles or one row.
   - `POST ?action=save` — upsert a tenant-private profile.
   - `POST ?action=apply` body `{ profile_id, membership_id, overwrite?,
     sub_tenant_scope? }` — bulk-apply grants to an existing membership.
   - `DELETE ?id=N` — remove a tenant-private profile (system blocked).
   - 503 when migration 100 hasn't been applied yet; admin gate restricts
     to `master_admin` / `tenant_admin` / global admin.

4. **`profile_key` wiring on existing membership flows** (`memberships.php`):
   - `POST ?action=invite` accepts an optional `profile_key`. On success
     the response includes `profile_applied: { profile_key, profile_id,
     grants_applied }` (or `{ profile_key, error }` if the apply step
     failed — non-fatal: the invite still ships and the magic link is
     still sent). Surfaces in the React `InviteForm` result card.
   - `POST` (regular create) accepts the same `profile_key`. Onboarding
     a CPA in one click instead of 9 module clicks is now possible.

5. **`RbacMembershipsAdmin.jsx` — React UI**:
   - `ProfilePicker` component — loads `/api/admin/permission_profiles.php`
     on mount, filters by `applies_to_persona` (matches selected persona
     OR `null`/generic), shows `system` vs `tenant` badge + grants count.
     Loading + empty states have explicit `data-testid` hooks.
   - `MembershipForm` (new-membership flow only) gets a profile-picker row.
   - `InviteForm` gets a profile-picker row above the submit buttons.
   - `AccessGrid` gets an "Apply profile" card next to the existing
     "Copy permissions from" card with an `Overwrite other modules`
     checkbox. Surfaces grants_applied via `alert()` and reloads the
     module-access table immediately.

6. **Test smoke**: `tests/rbac_b6_profiles_smoke.php` — **88 / 88 ✓** locking
   every layer (migration shape, service surface, endpoint contract,
   memberships.php wiring, every UI testid, plus a functional SQLite probe
   exercising the upsert + apply round-trip).

### Test status
- Full PHP suite: **364 / 366 passing**. Documented sandbox-bound failures:
  `accounting_phase2_a7_smoke.php`, `tenant_mail_senders_smoke.php`
  (no live MySQL / SMTP socket in this container).
- New: `rbac_b6_profiles_smoke.php` — 88/88 ✓
- B3 + B4 bridge smokes still 77/77 + 122/122.
- Tenant-leak static analyzer + auth-gate static analyzer + HY093 sentry
  all green.

### Bundle / Deploy
- Vite build → bundle `index-CxqpAGr-.js`. `scripts/sync_bundle.sh` synced
  `.deploy-version`, `spa-assets/`, `dashboard/dist/index.html`, and
  service-worker `CACHE_VERSION` → `coreflux-CxqpAGr-`.
- **Deploy note**: PHP + React touched. Cloudways deploy + `update.php`
  applies migration 100 + picks up the new bundle. Existing tenants are
  zero-impact: system profiles are visible immediately and applying one
  is a deliberate admin action.

### Files touched
- `core/migrations/100_rbac_cpa_personas_and_profiles.sql` (already present)
- `core/rbac/permission_profiles.php` (new)
- `api/admin/permission_profiles.php` (new)
- `api/admin/memberships.php` (whitelist + profile_key wiring on invite + create)
- `dashboard/src/pages/RbacMembershipsAdmin.jsx` (PERSONA_TYPES, ProfilePicker,
  MembershipForm/InviteForm/AccessGrid integrations)
- `tests/rbac_b6_profiles_smoke.php` (new)

### Roadmap (next — CPA layer kickoff)
1. **`/app/core/rbac/cpa_firms.php` + `/api/admin/cpa_firms.php`** — CRUD
   over `cpa_firm_client_links` (table already created by migration 100).
2. **"My CPA clients" landing page** — when the active user is a member
   of a firm tenant linked to ≥1 client tenants, surface a cross-tenant
   client list with a one-click context switch. Reuses the existing
   tenant-switch helper but pivots off `cpa_firm_client_links` instead
   of `user_tenants`.
3. **External auditor scoped URL** — extend `core/auditor.php` so an
   `external_auditor` persona with a tokenized URL gets the
   `external_auditor.default` profile auto-applied (read-only across
   audit-relevant modules) when the magic link is consumed.

### Backlog (unchanged priority)
- (P1) Per-tenant AI feature flag UI (`use_llm` admin toggle).
- (P1) Phase 8 — Business Event Layer infrastructure.
- (P1) Mercury Webhooks hardening.
- (P2) Gusto integration / QBO hardening (parked).
- (P2) CFO Dashboard role/access gating (now plug-in-able with new RBAC).
- (P3) Customer portal Phase A.
- (P3) Engagements module (Fixed-fee project accounting).
- (P3) AI Digest Scheduler.
- (P3) External Auditor view (depends on CPA layer kickoff above).

---

## Session — 2026-06 (Zoho Books per-entity + Copy sync config)

User direction: **option b** — skip the speculative multi-entity destination scaffolding, go straight to **Zoho Books per-entity** (same pattern as Jaz), and **add the "Copy sync config from another entity" affordance**.

### Architectural rule confirmed
For accounting integrations that are **per-entity by nature** (Jaz, Zoho Books standard, QBO Online single-realm, Xero standard): one connection per CoreFlux legal entity. Multi-entity-capable destinations (NetSuite, Sage Intacct, Workday, QBO Advanced) will get their own master-tenant-level model when the first one is onboarded — scaffolding deferred until then.

### Shipped
1. **Migration 099** (`core/migrations/099_zoho_books_per_entity.sql`):
   - `zoho_books_connections.sub_tenant_id` added (idempotent), `UNIQUE(tenant_id)` swapped for `UNIQUE(tenant_id, sub_tenant_id)`.
   - `zoho_books_oauth_state.sub_tenant_id` so the callback can route to the right entity.
   - `zoho_books_sync_audit.sub_tenant_id` (+ `ix_zoho_audit_entity` index) so per-entity audit queries don't need a JOIN.
   - Legacy rows backfilled with `sub_tenant_id = tenant_id` (parent self-entity).
2. **`core/zoho_books/client.php`** — every public helper is now per-entity-aware while staying back-compat.
3. **Sync workers** (`core/zoho_books/sync_{accounts,bills,billables,contacts,invoices,je,payments}.php`) — each one reads `$opts['sub_tenant_id']` and sets `$GLOBALS['__zb_sub_tenant_id']` at the top so every nested `zohoBooksCall()` automatically scopes to the right entity.
4. **`api/zoho_books.php`** — `_zbSub()` helper resolves the entity from query/body (default = parent). New `sync_config_copy` action. OAuth callback consumes `sub_tenant_id` from the state row.
5. **`/api/accounting.php?action=sync_config_copy&provider=jaz`** — provider-neutral generic copier for adapters living on the shared `accounting_provider_connections` table.
6. **`accountingSyncConfigCopy()`** in `core/accounting/sync_config_service.php` — overwrite gate, sub-tenant CoA reuse safety.
7. **`ZohoBooksSettings.jsx`** — "Step 1 — Legal entity" picker, "Copy sync config from another entity" card, "Step 4 — Account mapping" card.
8. **`JazIntegrationSettings.jsx`** — `JazCopyConfigCard` slotted between sync_config and account_mapping cards.

---

## Session — 2026-06 (Jaz parity: per-entity sync_config + account mappings + intercompany rules)

Per user direction. Accounting integrations are **per legal entity**. Consolidation + elimination JEs never sync to the destination — they're CoreFlux-platform-only. Intercompany JEs DO sync from each entity's own books to its destination, governed by a dedicated `intercompany` toggle.

### Shipped
1. **Migration 098** (`core/migrations/098_jaz_sync_config_and_account_mappings.sql`): adds `sync_config` JSON column + `accounting_account_mappings` table + `is_consolidation_entry` flag.
2. **`core/accounting/sync_config_service.php`**: get/save helpers + `accountingShouldSync` predicates.
3. **`core/accounting/account_mapping_service.php`**: CRUD + auto-map-by-code.
4. **API surface** (`api/accounting.php` extended): `sync_config`, `sync_config_set`, `account_mappings`, `account_mapping_save`, `account_mapping_delete`, `account_mapping_auto`.
5. **Command service gate**: hard-skips consolidation/elimination JEs AND consults the sync_config before enqueueing.
6. **Jaz adapter** (`core/accounting/jaz_adapter.php::normalizeCoaRow`): provider-neutral `id`/`provider_id`.
7. **JazIntegrationSettings UI**: Step 3 — sync direction per entity-type; Step 4 — account mapping.

---

## Session — 2026-06 (HY093 sweep, AI transfer detection, period UI, audit log fix, Plaid → CoA)

Wide-impact P0/P1 regression report. Root cause was repeated named placeholders under PDO_MYSQL native prepares (PDO emulation OFF).

Fixed all repeated `:foo` placeholders in 10+ files (vendors, bills, accounts, clients, people, placements, reports, bank_rec, airtable, suppressions, plaid). Plus:
- **AI inter-account transfer detection** (`core/ai_categorization.php::aiCategorizationFromInterAccountTransfer`).
- **Plaid bank → Chart of Accounts** auto-insertion + diagnostics backfill.
- **Audit log schema parity** migration 097.
- **Define-a-period endpoint + UI**.
- **`GET /api/sub_tenants.php` read-open** to all authenticated members.
- **`ResendDriver::send()`** defensive fix for empty-from.

---

## Original Problem Statement
Refactor a monolithic PHP application, CoreFlux, into a modular architecture. The core application provides a standardized shell, design system, and services (auth, multi-tenancy). Each business function (Accounting, People, Finance, Payroll) is a separate, self-contained module that "plugs into" this core shell. A React SPA (`spa.php`) is the primary frontend, authenticating via the existing PHP backend.

## Tech Stack
- **Backend:** PHP 8 + MySQL (PDO, native prepares — emulation OFF; repeated named placeholders forbidden). Single stack. AI calls go direct to OpenAI from PHP.
- **Frontend:** React 18 + Vite + React Router + Lucide
- **Architecture:** Modular monolith; modules developed in-repo under `/modules/<name>/`, extracted to subtree repos later
- **Hosting:** Cloudways
- **Testing:** Custom PHP CLI smoke tests (`*_smoke.php`). NO testing agents.
- **Integrations:** Custom legacy implementations (Plaid, Mercury, QuickBooks, Zoho Books, Jaz, Airtable, etc.). DO NOT use emergent integration subagents — they break the existing nested architecture.

## Critical Operator Rules
- **Class collisions**: legacy `class RBAC` lives in `core/RBAC.php`; new resolver is `class RBACResolver` in `core/rbac/permissions.php`. Never declare `class RBAC` again.
- **Bundle sync**: ALWAYS run `yarn --cwd /app/dashboard build` after React changes; postbuild `sync_bundle.sh` updates `.deploy-version`, `spa-assets/`, `dashboard/dist/index.html`, and service worker `CACHE_VERSION`.
- **HY093 trap**: PDO native prepares forbid repeated named placeholders. Use `:q`/`:q2`, `:d_lo`/`:d_hi`, etc.
- **Tenant-leak sentry**: every `prepare()` touching a tenant-scoped table MUST reference `tenant_id` in the WHERE/JOIN, or include a `// tenant-leak-allow: <reason>` comment within 3 lines above.
- **Resend wiring**: Resend is fully wired end-to-end (`core/mail/ResendDriver.php`). Auto-registered when `RESEND_API_KEY` is set; falls back to LogDriver when absent. Earlier notes calling `mailerSend()` "mocked" are stale.

## Test credentials
Standard test user: `kunal@coreflux.app` with `master_admin` role.
