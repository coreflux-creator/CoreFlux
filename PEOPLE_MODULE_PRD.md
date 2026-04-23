# People Module — Product Requirements

**Module id:** `people`
**Repo path:** `/modules/people/`
**Role:** CoreFlux's HR system of record. Canonical source for employee data; consumed by Payroll (comp, tax, banking) and other modules.
**Branch:** `feature/people` → `main` before Payroll work begins.

---

## 1. Goals

1. Be the authoritative store for every employee record in a tenant.
2. Capture everything Payroll needs to calculate, withhold, and pay.
3. Capture everything HR needs for day-to-day people operations (directory, org chart, onboarding paperwork).
4. Encrypt PII (SSN/SIN, bank accounts) at rest, decrypt only for authorized readers, mask in audit logs.
5. Give managers a fast, narrative-first directory experience (AI summaries, advisory only — never substituting for deterministic data).

## 2. Non-goals (for MVP)

- Benefits administration (elections, deductions) — phase 2
- Performance reviews / goals
- Recruiting / ATS
- Learning / training
- Any AI that produces numbers or auto-executes HR actions

## 3. MVP scope (sections 1–6 + 8 + 9 + 10)

### 3.1 Identity *(table: `people_employees`)*

Legal first/middle/last name, preferred name, DOB, **SSN/SIN (encrypted)**, gender, marital status, citizenship status, work authorization status, photo URL.

### 3.2 Contact *(tables: `people_addresses`, `people_phones`, `people_emergency_contacts`)*

- Addresses: home, mailing (type, street1/2, city, region, postal, country, effective dates)
- Phones: mobile, home, work (type, number, preferred)
- Personal email (work email lives in Employment)
- Emergency contacts: name, relationship, phone, priority

### 3.3 Employment *(columns on `people_employees` + `people_employment_history`)*

Employee number (tenant-unique), hire date, termination date, status (`active|on_leave|terminated|pending`), employment type (`full_time|part_time|contractor|intern|temp`), FLSA class (`exempt|non_exempt`), manager (fk → employees), department, location, job title, work email, employment_history rows for transfers/promotions.

### 3.4 Compensation *(table: `people_compensation`)*

Comp history rows with effective dates. Fields: pay type (`salary|hourly`), pay rate (stored as integer cents), pay frequency (`weekly|biweekly|semimonthly|monthly`), currency, bonus target (nullable), effective_from, effective_to, reason (promotion, merit, market, etc.). The *active* row is the one where `effective_from <= today AND (effective_to IS NULL OR effective_to > today)`.

### 3.5 Tax *(tables: `people_tax_federal`, `people_tax_state`, `people_i9`)*

- Federal W-4: filing_status, multiple_jobs, dependents_amount (cents), other_income (cents), deductions (cents), extra_withholding (cents), effective_date, form_version (2020+).
- State withholding: state_code, filing_status (state-specific), allowances, extra_withholding (cents), effective_date.
- I-9: verification status (`pending|verified|rejected`), list_a/b/c document refs, verification_date, verifier_user_id.

### 3.6 Banking *(table: `people_bank_accounts`)*

Direct deposit accounts, one employee → N accounts (ordered by priority). Fields: priority, allocation_type (`percent|remainder|fixed_amount`), allocation_value (cents if fixed, basis points if percent), account_type (`checking|savings`), **routing_number (encrypted)**, **account_number (encrypted)**, bank_name, status (`active|pending_verified|closed`), effective_from.

### 3.8 Time off / PTO *(tables: `people_leave_policies`, `people_leave_balances`, `people_leave_requests`)*

Policy defines accrual rate, cap, unit (hours/days), and category (`vacation|sick|personal|parental`). Balance tracks current accrued, used, pending. Requests link to balance and have status (`pending|approved|rejected|cancelled`), with a reviewer + review timestamps. **Accrual calculation is deterministic; AI only narrates.**

### 3.9 Documents *(table: `people_documents`, storage: local filesystem MVP, object storage later)*

Every document has tenant_id, employee_id, kind (`offer_letter|w4|i9|state_withholding|direct_deposit|termination|other`), filename, mime, size_bytes, storage_path, uploaded_by, uploaded_at, signed_at (nullable), signed_by (nullable). Upload flow is chunked to bypass proxy limits.

### 3.10 Org chart *(derived from `manager_id` on `people_employees`)*

No dedicated table — the org chart is a graph traversal on `manager_id`. API returns a tree for a given root employee or the full tenant tree.

## 4. Cross-module contract (how Payroll reads People)

Payroll **never** duplicates employee data. It extends via `payroll_profile` (in `/modules/payroll/` migrations, not here):

```
payroll_profiles (
  id, tenant_id, employee_id FK -> people_employees.id,
  pay_schedule_id, payment_method, ...
)
```

**Read interface for Payroll:**
- `people_employees` (identity + employment + active_compensation_id)
- `people_compensation` (compensation history, active row)
- `people_tax_federal`, `people_tax_state` (active rows)
- `people_bank_accounts` (active, ordered by priority)

**Scope boundary:** all reads go through `scopedQuery` or a PHP helper library we'll expose as `/modules/people/lib/employees.php` so Payroll's queries stay tenant-safe.

## 5. API surface (MVP)

All endpoints under `/modules/people/api/*.php`. All use `api_require_auth()` and scoped helpers.

| Resource | Methods |
|---|---|
| `employees.php` | `GET` list (search/filter), `GET ?id=` detail, `POST` create, `PUT` update, `DELETE` soft-delete (terminate) |
| `addresses.php` | `GET ?employee_id`, `POST`, `PUT`, `DELETE` |
| `phones.php` | CRUD keyed on `employee_id` |
| `emergency_contacts.php` | CRUD keyed on `employee_id` |
| `employment_history.php` | `GET ?employee_id`, `POST` (new record ends previous with effective_to) |
| `compensation.php` | `GET ?employee_id`, `POST` (new record ends previous; idempotent on effective_from) |
| `tax_federal.php` / `tax_state.php` | `GET ?employee_id`, `POST` (new form, keeps history) |
| `i9.php` | `GET ?employee_id`, `POST` (verify), `PUT` (re-verify) |
| `bank_accounts.php` | `GET ?employee_id`, `POST`, `PUT`, `DELETE` (cannot drop an active account mid-pay-period — deterministic validation) |
| `leave_policies.php` | CRUD |
| `leave_balances.php` | `GET ?employee_id` (accrual calc is deterministic, runs in PHP) |
| `leave_requests.php` | `GET`, `POST`, `PUT` (approve/reject) |
| `documents.php` | `GET ?employee_id`, `POST` (chunked upload), `GET ?id=X&download=1` |
| `org_chart.php` | `GET ?root_employee_id=` returns tree JSON |
| `ai_summary.php` | AI: narrative employee summary (reads deterministic fields, returns narrative) |
| `ai_missing_fields.php` | AI: narrate what's missing before an employee can be paid (deterministic gap check → AI writes the message) |

## 6. Frontend views (SPA)

Under `/modules/people/ui/`:
- `PeopleModule.jsx` — router
- `EmployeeDirectory.jsx` — table view, search, filters (status, dept, location, type), bulk actions
- `EmployeeDetail.jsx` — tabbed: Profile / Employment / Compensation / Tax / Banking / Documents / Time Off / Org
- `EmployeeCreate.jsx` — stepped form (identity → employment → comp → tax → banking → review)
- `OrgChart.jsx` — tree visualization
- `TimeOffAdmin.jsx` — policies + pending requests

Every AI-touching view renders through `<AISuggestion />`.

## 7. Security & PII

- SSN/SIN, bank routing + account numbers: **AES-256-GCM** at rest via `core/encryption.php` (key from `/app/backend/.env` → `COREFLUX_DATA_KEY`). Column types store ciphertext (`VARBINARY(512)`) + `_last4` companion column for display.
- Masked display by default: `***-**-1234`. Full reveal is a permissioned action, audited.
- Permissions (new, declared in manifest):
  - `people.view` — read basic directory fields
  - `people.pii.view` — see full SSN / full account numbers (audited every access)
  - `people.manage` — create/update
  - `people.terminate` — soft-delete employees
  - `people.comp.view`, `people.comp.manage`
  - `people.tax.view`, `people.tax.manage`
  - `people.banking.view`, `people.banking.manage`
  - `people.docs.manage`
  - `people.timeoff.manage`
- Every *reveal* of encrypted fields writes to `people_pii_access_log` (separate from `ai_interactions`).
- `cascade_audit`: every write to identity/comp/tax/banking creates a row in `people_change_log` with before/after hashes + user_id.

## 8. AI features (advisory only, per `AI_INTEGRATION_RULES.md`)

| Feature | feature_class | Human review |
|---|---|---|
| `people.employee_summary` (directory card narrative) | `summary` | Read-only; no commit needed — but rendered via `<AISuggestion />` with no Accept (display-only) |
| `people.missing_fields` (pre-payroll gap narrative) | `narrative` | Display-only |
| `people.offer_letter_draft` | `draft` | Accept → saves as `final_content`, becomes the offer letter text saved under Documents |
| `people.one_on_one_prompt` (private manager prep) | `narrative` | Display-only (personal to manager) |

AI never produces: comp numbers, tax amounts, accrual values, eligibility decisions, or termination decisions.

## 9. Data-loss & concurrency rules

- All comp / tax / banking updates are *append-only* (history rows). Nothing is edited in place; edits create a new row with `effective_from`. UI shows "history" tabs.
- Employees are never hard-deleted — termination sets `status='terminated'` + `terminated_at`. Payroll may still need to reference them for prior periods.
- Every write goes through `scopedUpdate` or `scopedInsert` so tenant isolation is enforced.

## 10. Build order (within this branch)

1. **Schema + encryption helper** (migration + `core/encryption.php`)
2. **Manifest + module registration**
3. **`employees` CRUD + directory UI** (tab 1 only)
4. **Addresses / phones / emergency contacts + Profile tab**
5. **Employment history + Employment tab**
6. **Compensation (history-aware) + Compensation tab**
7. **Tax (federal + state) + Tax tab**
8. **Banking (encrypted) + Banking tab**
9. **Documents (chunked upload) + Documents tab**
10. **Time off + Time Off tab**
11. **Org chart API + tree view**
12. **AI features** (summary, missing_fields, offer_letter_draft)
13. **Employee create wizard**

## 11. Out of scope / deferred

- Benefits elections & deductions → phase 2
- Performance reviews, goals, feedback
- Recruiting / ATS
- LMS / training records
- Multi-company within one tenant (each legal entity gets its own tenant today)
- Internationalization beyond US/CA tax forms (phase 2)
- Sync with external HRIS (phase 3)

---

*Authoritative spec. Build log + updates go in `/app/memory/PRD.md`.*
