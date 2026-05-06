# Placements — Module Specification

**Status**: DRAFT — pending user sign-off
**Stack**: PHP 8 (per HARD_RULES decisions log, 2026-02)
**Owner module of**: active deals — every assignment of a person to a client, with all its commercial terms (rates, fees, commissions, durations).
**NOT owner of**: people records, time entries, invoices. Those live in `people/`, `time/`, `accounting/`.

This SPEC is written so the module can be re-created from scratch. It is the source of truth.

Modeled after JobDiva's placement-as-first-class-citizen pattern (per HARD_RULES R-2026-04-27) and the user's real-world tracker (`Placement tracker aligned.xlsx`).

---

## 1. Purpose

A **Placement** represents one active or historical engagement: *"Person P is working at Client C through Vendor Tier(s) V1→V2→V3, billing $X, paying $Y, from date D1 to D2, with commission split S, under contract terms T."*

It is the **commercial spine** of the staffing agency. Every dollar that flows in (AR/billing) or out (AP/payroll/commissions/referral fees) is traceable back to a placement.

---

## 2. Core principles (locked by HARD_RULES)

1. **One person can have multiple active placements** (typical case: 1; multi allowed).
2. **Bill/pay rates are per placement, effective-dated, full audit history.**
3. **Rate snapshot semantics: option (b) — frozen at approval.** Posted entries (time, invoices) keep the rate that was effective when they were approved, even if the placement rate later changes. New entries use the new rate from its effective date forward.
4. **Client is a string label, not a CoreFlux entity.** No `clients` table. The placement carries `end_client_name`, vendor tier names, etc. as strings (with optional tenant-level autocomplete index for UX, but not as relational FKs).
5. **Vendor portal / fee model is first-class.** Many staffing deals route through Beeline, Fieldglass, SAP Ariba, etc., which take a percentage. The data model must capture this directly.
6. **Multi-tier vendor chain** must be supported (recruiter → prime vendor → MSP → end client).
7. **Commission splits are per placement**, can include account_manager, lead, recruiter, plus team commission, plus referral fees with their own duration.
8. **Adders / markups** (employer burden, GP adders, PTO, benefits load) are explicit columns, not buried in math.
9. **Net Margin formula must be deterministic and visible** — every component is a stored field, not derived from a formula the user can't audit.

---

## 3. Data model

### 3.1 `placements` (one row per engagement)

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `tenant_id` | BIGINT FK | |
| `person_id` | BIGINT FK→`people.id` | |
| `external_id` | VARCHAR(64) NULL | tenant ATS / spreadsheet ref |
| `status` | ENUM('draft','pending_start','active','on_hold','ended','cancelled') | |
| `start_date` | DATE | |
| `end_date` | DATE NULL | NULL = open-ended |
| `actual_end_date` | DATE NULL | set when `status='ended'` |
| `due_date` | DATE NULL | tracker field — contract renewal / extension deadline |
| `engagement_type` | ENUM('w2','1099','c2c','temp_to_perm','direct_hire','internal') | `internal` = agency's own employee (admin, recruiter, accountant) — not placed at an external client |
| `worksite_state` | VARCHAR(60) NULL | tax / labor law |
| `worksite_country` | CHAR(2) NULL | |
| `remote_policy` | ENUM('onsite','hybrid','remote') NULL | |
| `title` | VARCHAR(200) | role title at client |
| `notes` | TEXT NULL | |
| `created_by_user_id` | BIGINT FK | |
| `created_at` | DATETIME | |
| `updated_at` | DATETIME | |
| `deleted_at` | DATETIME NULL | soft delete |

Indexes: `(tenant_id, person_id, status)`, `(tenant_id, status)`, `(tenant_id, end_date)`, `(tenant_id, due_date)`.

### 3.2 `placement_client_chain` (multi-tier vendor stack)

One placement → ordered list of parties. Position 0 = end client; higher positions = intermediaries between us and them.

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `placement_id` | BIGINT FK | |
| `position` | TINYINT | 0=end client, 1=tier above us, 2=tier above that... |
| `party_name` | VARCHAR(255) | string only — no FK |
| `party_role` | ENUM('end_client','msp','prime_vendor','sub_vendor','direct') | |
| `vendor_portal_id` | BIGINT NULL FK→`tenant_vendor_portals.id` | tenant-defined portal taxonomy (Beeline, Fieldglass, etc.) |
| `portal_fee_pct` | DECIMAL(6,4) NULL | e.g. 0.0200 = 2% taken by this party |
| `portal_fee_flat` | DECIMAL(10,2) NULL | flat alternative |
| `contract_storage_object_id` | BIGINT NULL FK→`storage_objects.id` | reference to Core StorageService |

Constraint: at least one row with `position=0` (`party_role='end_client'` OR `party_role='direct'`).

### 3.3 `placement_rates` (effective-dated, append-only)

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `placement_id` | BIGINT FK | |
| `effective_from` | DATE | inclusive |
| `effective_to` | DATE NULL | exclusive; NULL = open |
| `bill_rate` | DECIMAL(10,4) | per hour to top-of-chain customer |
| `bill_rate_unit` | ENUM('hour','day','week','month','project') | hour is default |
| `pay_rate` | DECIMAL(10,4) | per hour to person/their corp |
| `pay_rate_unit` | ENUM('hour','day','week','month','project') | |
| `currency` | CHAR(3) | ISO-4217, default tenant currency |
| `ot_multiplier` | DECIMAL(4,2) | default 1.5 |
| `dt_multiplier` | DECIMAL(4,2) | default 2.0 |
| `adder_pct` | DECIMAL(6,4) NULL | GP / employer-burden adder |
| `adjusted_bill_rate` | DECIMAL(10,4) NULL | bill_rate after vendor portal fees applied (computed at approval, stored). **Fees stack additively** (sum of all chain `portal_fee_pct` values). |
| `net_to_vendor` | DECIMAL(10,4) NULL | adjusted_bill_rate − pay_rate − referral_fee/hr (stored, computed at approval). Background fee is a one-time deduction at placement start, NOT amortized into per-hour rate. |
| `background_fee_total` | DECIMAL(10,2) NULL | one-time deduction at placement start. Charged once on first invoice / first payroll cycle. Not spread across hours. |
| `approved_by_user_id` | BIGINT NULL | NULL = draft |
| `approved_at` | DATETIME NULL | freeze moment |
| `superseded_by` | BIGINT NULL FK→`placement_rates.id` | for audit |
| `created_at` | DATETIME | |

Rules:
- A rate row is **draft** until `approved_at` is set.
- Approving a new rate row sets `effective_to` on the previous active row to (new effective_from − 1 day).
- Time entries reference `placement_rates.id` they were approved against (snapshot lock per HARD_RULES).
- Editing an approved rate is disallowed. Corrections are made by approving a new rate row, optionally backdated, with a `correction_reason` audit entry.

### 3.4 `placement_commission_plans` (tenant-level reusable plans — per HARD_RULES decision)

Tenants can save commission *plans* (templates) and pick one as the tenant default. Placements either use a plan or define inline splits.

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `tenant_id` | BIGINT FK | |
| `name` | VARCHAR(120) | e.g. "Standard 60/30/10", "House Account" |
| `is_tenant_default` | BOOLEAN | exactly one TRUE per tenant (enforced) |
| `basis` | ENUM('net_margin','gross_margin','bill_rate','flat') | default `net_margin` per HARD_RULES |
| `active` | BOOLEAN | |
| `created_by_user_id` | BIGINT FK | |
| `created_at` / `updated_at` | DATETIME | |

#### `placement_commission_plan_lines` (the splits inside a plan)
| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `plan_id` | BIGINT FK | |
| `role` | ENUM('account_manager','lead','recruiter','team','other') | |
| `split_pct` | DECIMAL(6,4) | sum across plan must equal 1.0 |
| `flat_amount` | DECIMAL(10,2) NULL | when basis='flat' |
| `notes` | TEXT NULL | |

### 3.5 `placement_commissions` (per-placement, optionally derived from a plan)

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `placement_id` | BIGINT FK | |
| `plan_id` | BIGINT NULL FK→`placement_commission_plans.id` | NULL = inline custom |
| `role` | ENUM('account_manager','lead','recruiter','team','other') | |
| `user_id` | BIGINT NULL FK→`users.id` | NULL for 'team' bucket |
| `split_pct` | DECIMAL(6,4) | of margin (or as configured) |
| `basis` | ENUM('net_margin','gross_margin','bill_rate','flat') | inherits from plan or set inline; basis enum is fixed at platform level (cannot be overridden by tenant) |
| `flat_amount` | DECIMAL(10,2) NULL | when basis='flat' |
| `effective_from` | DATE | |
| `effective_to` | DATE NULL | |
| `notes` | TEXT NULL | |

Rule: per role+effective_window the sum of `split_pct` across all rows must equal 1.0 (validated at save).

### 3.6 `placement_referrals`

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `placement_id` | BIGINT FK | |
| `referrer_type` | ENUM('vendor','person','user') | |
| `referrer_vendor_name` | VARCHAR(255) NULL | string, not FK |
| `referrer_person_id` | BIGINT NULL FK→`people.id` | |
| `referrer_user_id` | BIGINT NULL FK→`users.id` | |
| `fee_pct` | DECIMAL(6,4) NULL | of bill_rate or net |
| `fee_flat` | DECIMAL(10,2) NULL | per-hour or one-time depending on `fee_basis` |
| `fee_basis` | ENUM('per_hour','per_invoice','one_time','pct_bill','pct_margin') | |
| `duration_months` | INT NULL | how long the referral fee applies |
| `start_date` | DATE | |
| `end_date` | DATE NULL | computed from duration_months if NULL |
| `notes` | TEXT NULL | |

### 3.7 `placement_corp_details` (for C2C engagements)

One row per placement when `engagement_type='c2c'`.

| Column | Type | Notes |
|---|---|---|
| `placement_id` | BIGINT PK FK | |
| `corp_legal_name` | VARCHAR(255) | |
| `corp_ein_ct` | VARBINARY(256) | application-level encrypted (KMS), per HARD_RULES |
| `corp_ein_last4` | CHAR(4) | cleartext for masked display only |
| `kms_key_version` | VARCHAR(64) | for rewrap on rotation |
| `corp_address_line1` | VARCHAR(255) | |
| `corp_address_line2` | VARCHAR(255) NULL | |
| `corp_city` | VARCHAR(120) | |
| `corp_state` | VARCHAR(60) | |
| `corp_postal_code` | VARCHAR(20) | |
| `corp_country` | CHAR(2) | |
| `corp_contact_name` | VARCHAR(200) | |
| `corp_contact_email` | VARCHAR(255) | |
| `corp_contact_phone` | VARCHAR(40) NULL | |
| `msa_storage_object_id` | BIGINT NULL FK→`storage_objects.id` | via Core StorageService (S3) |
| `coi_storage_object_id` | BIGINT NULL FK→`storage_objects.id` | certificate of insurance |
| `coi_expiry` | DATE NULL | |
| `w9_storage_object_id` | BIGINT NULL FK→`storage_objects.id` | |

### 3.8 `placement_documents`

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `placement_id` | BIGINT FK | |
| `doc_type` | ENUM('msa','sow','work_order','rate_sheet','timesheet_template','poc','noc','other') | |
| `storage_object_id` | BIGINT FK→`storage_objects.id` | actual file in S3 via Core StorageService |
| `effective_from` | DATE NULL | |
| `effective_to` | DATE NULL | |

### 3.9 `placement_custom_field_defs` / `..._values`

Same shape as `people_custom_field_defs` / `..._values` (see People SPEC §3.1).

### 3.10 Approval client contact (for time approvals — see Time SPEC)

| Column | Type | Notes |
|---|---|---|
| `placement_id` | BIGINT PK FK | |
| `client_approver_name` | VARCHAR(200) NULL | |
| `client_approver_email` | VARCHAR(255) NULL | tokenized email approval target |
| `tokenized_email_approval_enabled` | BOOLEAN | per-placement toggle (per HARD_RULES). **Default = OFF for new placements.** |
| `bulk_uploads_can_be_pre_approved` | BOOLEAN | "already approved" flag from bulk uploads (per HARD_RULES) |

### 3.11 Relationships diagram

```
placements 1───*  placement_client_chain    (ordered)
placement_client_chain *───1 tenant_vendor_portals  (per-tenant taxonomy)
placements 1───*  placement_rates           (effective-dated, append-only)
placements *───? placement_commission_plans (optional; tenant-level templates)
placements 1───*  placement_commissions     (per-placement splits)
placements 1───*  placement_referrals
placements 1───1  placement_corp_details    (C2C only, EIN encrypted at app layer)
placements 1───*  placement_documents       (FK→storage_objects, files in S3)
placements 1───*  placement_custom_field_values
placements *───1  people                    (FK person_id)
placements 1───*  time.entries              (cross-module; rate_snapshot_id FK→placement_rates.id)

tenant_end_clients (denormalized typeahead index, NOT relational)
```

---

## 4. Net Margin formula (deterministic, stored components)

For a given `placement_rates` row, on a per-hour basis:

```
total_portal_fee_pct = SUM(placement_client_chain.portal_fee_pct)   -- ADDITIVE per HARD_RULES
total_portal_fee_flat_per_hour = SUM(portal_fee_flat) / billable_hours_in_period

adjusted_bill_rate
    = bill_rate * (1 - total_portal_fee_pct) - total_portal_fee_flat_per_hour

net_to_vendor (us)
    = adjusted_bill_rate
      - pay_rate
      - referral_fee_per_hour                       -- only while referral active

gross_margin_per_hour = adjusted_bill_rate - pay_rate
net_margin_per_hour   = net_to_vendor

commission_pool_per_hour = net_margin_per_hour * commission_basis_factor

-- Background fee is a ONE-TIME deduction (per HARD_RULES), applied once at:
--   * first invoice (AR) on the bill side, or
--   * first payroll cycle (AP) on the pay side,
-- per tenant config. NOT amortized into the per-hour rate.
```

`adjusted_bill_rate` and `net_to_vendor` are **computed at the moment of rate approval** (using the additive sum of all chain `portal_fee_pct` values then in effect) and **stored** on the `placement_rates` row. Time entries lock to the rate row by id, so margins are reproducible historically.

---

## 5. Permissions (RBAC)

| Slug | Description |
|---|---|
| `placements.view` | List + view |
| `placements.manage` | Create / edit non-financial fields |
| `placements.financials.view` | View rates, fees, margin |
| `placements.financials.manage` | Create/draft rate rows |
| `placements.financials.approve` | Approve rate rows (snapshot lock) |
| `placements.commissions.view` | View commission splits |
| `placements.commissions.manage` | Edit commission splits |
| `placements.referrals.manage` | Edit referral records |
| `placements.docs.view` / `placements.docs.manage` | Documents |
| `placements.terminate` | Set status=ended/cancelled |
| `placements.corp.view` / `placements.corp.manage` | C2C corp details |
| `placements.custom_fields.manage` | Tenant custom fields |

`default_roles`: `master_admin`, `tenant_admin`, `admin`.
`placements.financials.approve` should be a **separate role grant** (typically only finance / tenant_admin) to enforce two-eye control on rate snapshots.

---

## 6. API surface

All under `/api/placements/...` via central router.

### 6.1 Core
- `GET /api/placements` — filters: `q`, `status`, `person_id`, `end_client`, `start_after`, `end_before`, `due_before`, `vendor_portal`, `engagement_type`.
- `GET /api/placements/{id}` — full record + nested chain, current rate, commissions, referrals.
- `POST /api/placements` — create (draft).
- `PATCH /api/placements/{id}`
- `POST /api/placements/{id}/end` — set `status='ended'`, `actual_end_date`.

### 6.2 Sub-resources
- `GET|POST|PATCH /api/placements/{id}/chain` — vendor tiers.
- `GET|POST /api/placements/{id}/rates` — list + draft new rate.
- `POST /api/placements/{id}/rates/{rate_id}/approve` — snapshot lock, requires `placements.financials.approve`.
- `GET|POST|PATCH|DELETE /api/placements/{id}/commissions`
- `GET|POST|PATCH|DELETE /api/placements/{id}/referrals`
- `GET|PUT /api/placements/{id}/corp` — C2C details
- `GET|POST|DELETE /api/placements/{id}/documents`
- `GET|PUT /api/placements/{id}/approval-contact`

### 6.3 Reports
- `GET /api/placements/reports/active-by-client` — group by `end_client_name`.
- `GET /api/placements/reports/expiring` — `due_date` or `end_date` within N days.
- `GET /api/placements/reports/margin` — net margin by placement / period.
- `GET /api/placements/reports/commissions` — payout report by user/role/period.

---

## 7. UI / sidebar actions

Manifest actions:

| Route | Label | Permission |
|---|---|---|
| `list` | Active Placements | `placements.view` |
| `expiring` | Expiring Soon | `placements.view` |
| `new` | New Placement | `placements.manage` |
| `commissions` | Commissions | `placements.commissions.view` |
| `referrals` | Referrals | `placements.referrals.manage` |
| `reports` | Reports | `placements.financials.view` |

### Detail page tabs
1. Overview — person, dates, title, worksite, status
2. Chain — vendor tiers and portal fees
3. Rates — current + history (approved + draft)
4. Commissions — splits per role
5. Referrals — fees + duration
6. Corp (C2C only)
7. Documents — MSA, SOW, work orders
8. Time — read-only feed from `time/`
9. Margin — computed from current rate row
10. Custom Fields

---

## 8. Audit events

- `placement.created`
- `placement.updated`
- `placement.status_changed`
- `placement.ended`
- `placement.chain.updated`
- `placement.rate.drafted`
- `placement.rate.approved` — **critical**, captures the snapshot moment
- `placement.rate.superseded`
- `placement.commission.added` / `.updated` / `.removed`
- `placement.referral.added` / `.updated`
- `placement.financials.viewed` (gated)
- `placement.corp.viewed` / `.updated`
- `placement.document.uploaded` / `.deleted`
- `placement.approval_contact.updated`

Every event includes `tenant_id`, `actor_user_id`, `placement_id`, `meta_json`.

---

## 9. AI usage in Placements (deferred)

MVP: none. Placement data is too consequential for AI writes.

Future:
- Rate sheet OCR — AI describes proposed rate row → human approves.
- Anomaly flag — "this placement's margin is 40% below tenant average" → review queue.
- Renewal nudges — "due_date in 14 days" — deterministic, not AI.

**Hard rule**: AI never touches `placement_rates`, `placement_commissions`, or `placement_referrals` directly. Always queue → human approval.

---

## 10. Validation rules

- `person_id` must reference a person in same `tenant_id`.
- Cannot set `status='active'` without an approved rate row covering `start_date`.
- `placement_corp_details` row required when `engagement_type='c2c'`.
- Commission splits per role+window must sum to 1.0.
- `placement_client_chain` must have exactly one row at `position=0`.
- Approving a rate that overlaps an existing approved rate auto-closes the older row's `effective_to`.
- `end_date` (planned) and `actual_end_date` (real) are independent fields.
- `due_date` should not be after `end_date` when both set.

---

## 11. Multi-tenancy

- All tables filter by `tenant_id`.
- Cross-tenant placement movement = new placement + close old.
- Master admin reads logged.

---

## 12. Decisions locked (resolved in spec sign-off)

1. ✅ **Default commission basis = `net_margin`.** Tenants create reusable **commission plans** (`placement_commission_plans`), pick one as tenant default, but the basis enum itself is fixed at platform level (no per-tenant override of the enum values).
2. ✅ **Background fee = one-time deduction** at placement start (first invoice or first payroll cycle, per tenant config). Not amortized. `background_fee_amort_hours` removed from schema.
3. ✅ **Vendor portal taxonomy = tenant-defined.** New `tenant_vendor_portals` table; chain rows reference it by FK instead of a fixed enum.
4. ✅ **Currency = single tenant-wide.** Per-placement currency deferred.
5. ✅ **Tokenized client email approval default = OFF** for new placements. Tenants opt in per placement.
6. ✅ **Multi-tier portal fees stack ADDITIVELY.** `total_portal_fee_pct = SUM(chain.portal_fee_pct)`. Documented in §4 formula.
7. ✅ **Referral duration clock starts at placement `start_date`.** Documented on `placement_referrals.start_date`.
8. ✅ **Backdated rate corrections allowed** by `placements.financials.approve` role. Mandatory `correction_reason` field captured in audit event `placement.rate.approved` (with `is_correction=true`).
9. ✅ **End-client autocomplete index per tenant** (`tenant_end_clients`). UX-only — does not change client-as-string-label rule (HARD_RULES R-2026-04-27).

---

## 13. MVP cut list

**Phase A (ship first):**
- `placements`, `placement_client_chain`, `placement_rates`, `placement_documents`, `placement_corp_details`, `placement_commissions`, `placement_referrals`
- CRUD + draft/approve flow on rates
- List, detail, expiring report
- Audit logging
- RBAC enforcement

**Phase B:**
- Reports (margin, commissions)
- Custom fields
- Approval contact wiring (consumed by `time/`)

**Phase C:**
- AI-assisted rate sheet OCR
- Anomaly detection on margin

---

*This SPEC is binding once signed off. Update this file before writing code.*
