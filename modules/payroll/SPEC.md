# Payroll — Module Specification

**Status**: DRAFT — pending user sign-off
**Stack**: PHP 8 (per HARD_RULES decisions log)
**Owner module of**: W2 employee gross-to-net pay calculation, pay schedules, pay runs, pay stubs, payroll-tax accruals, direct deposit / pay card disbursement.
**NOT owner of**: 1099 / C2C contractor pay (lives in `ap/`), customer billing (`billing/`), GL postings (`accounting/`).
**Sidebar grouping**: Finance.

**Strategic note**: MVP is a basic in-house payroll engine. **Future state**: integrate with Check HQ or Gusto via API as the deterministic engine of record, reducing CoreFlux's compliance surface area. Module MUST be designed so the engine is swappable behind a single PHP interface.

---

## 1. Purpose

Payroll converts approved W2 time + employee profile data into deterministic gross-to-net pay calculations, scheduled runs, and audit-locked outputs (stubs, tax accruals, direct deposit files, GL postings).

Inputs:
- Approved time bundles from `time/` (`time_downstream_feed.bundle_type='payroll'`).
- Employee profile data from `people/` (banking, tax setup, classification = `w2` only).
- Pay schedule definitions per tenant.
- Tax rate tables (federal, state, local) — versioned by effective date.
- Benefit / deduction definitions per tenant.

Outputs:
- Pay run records (header + lines per employee).
- Pay stubs (PDF + structured payload).
- Direct deposit / NACHA file (Phase B).
- Payroll tax liability accruals + remittance schedule.
- Year-end W-2 forms.
- GL postings (gross wages, tax accruals, employer burden).

---

## 2. Core principles

1. **Deterministic. Always.** No AI in the pay calculation path. AI may be used for narrative summaries (run-level commentary, anomaly detection) but never for numbers.
2. **Engine swappable**. The MVP engine is a simple gross-to-net calculator. The interface (`PayrollEngine`) is designed so a Check HQ / Gusto adapter can be dropped in without touching the surrounding workflow.
3. **Rate snapshot semantics** carry: each pay-run line locks to the `placement_rates.id` snapshot from time entries.
4. **Approval before disbursement.** Always two-eye: build run ≠ approve run ≠ release payments.
5. **W2 only** at this module. Non-W2 worker pay is `ap/`'s job.
6. **Tax tables are versioned** — every calculation records the `tax_table_version_id` used, so re-run/replay is exact.
7. **Off-cycle runs supported** (bonuses, corrections, terminations) without breaking the regular schedule.

---

## 3. Data model

### 3.1 `payroll_schedules` (per-tenant pay schedule definitions)

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `tenant_id` | BIGINT FK | |
| `name` | VARCHAR(120) | "Bi-weekly Friday" |
| `frequency` | ENUM('weekly','biweekly','semimonthly','monthly') | |
| `pay_day_rule_json` | TEXT | e.g. `{day_of_week: "fri", offset_business_days: 0}` |
| `period_close_offset_days` | INT | days before pay date that period closes |
| `is_default` | BOOLEAN | one TRUE per tenant |
| `active` | BOOLEAN | |

### 3.2 `payroll_periods` (pay periods derived from schedules — distinct from `time_periods`)

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `tenant_id` | BIGINT FK | |
| `schedule_id` | BIGINT FK | |
| `period_start` | DATE | |
| `period_end` | DATE | |
| `pay_date` | DATE | |
| `time_period_ids_json` | TEXT | which `time_periods` rows feed this pay period (often 1:1, sometimes multi:1) |
| `status` | ENUM('upcoming','collecting','locked','run_built','approved','disbursed','closed') | |

Note: `payroll_periods` and `time_periods` are deliberately separate. Time captures hours; payroll consumes them. They CAN align 1:1 (and usually do) but a payroll period can aggregate multiple time periods if needed.

### 3.3 `payroll_profiles` (per-employee setup)

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `tenant_id` | BIGINT FK | |
| `person_id` | BIGINT FK→`people.id` | classification must be `w2` |
| `schedule_id` | BIGINT FK | |
| `pay_type` | ENUM('hourly','salary','commission_only','salary_plus_commission') | |
| `salary_annual` | DECIMAL(12,2) NULL | for salary types |
| `default_hourly_rate` | DECIMAL(10,4) NULL | fallback when no placement rate |
| `ot_rule_override` | ENUM('flsa_default','exempt','tenant_custom') | per HARD_RULES Time decision |
| `state_tax_state` | VARCHAR(60) | resident state |
| `work_state` | VARCHAR(60) | for SUI calculation |
| `federal_withholding_json` | TEXT | W-4 inputs (filing_status, dependents, additional_withholding) — pulled from `people_tax` |
| `state_withholding_json` | TEXT | state W-4 equivalent |
| `direct_deposit_routing_ct` | VARBINARY(256) NULL | encrypted; mirrors `people_banking` snapshot |
| `direct_deposit_account_ct` | VARBINARY(512) NULL | encrypted |
| `direct_deposit_account_last4` | CHAR(4) NULL | display |
| `kms_key_version` | VARCHAR(64) | |
| `effective_from` | DATE | |
| `effective_to` | DATE NULL | append-only history |
| `active` | BOOLEAN | |

### 3.4 `payroll_runs` (a single pay-cycle execution)

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `tenant_id` | BIGINT FK | |
| `period_id` | BIGINT FK | |
| `run_type` | ENUM('regular','off_cycle_bonus','off_cycle_correction','final') | |
| `status` | ENUM('draft','built','pending_approval','approved','disbursing','disbursed','posted','reversed') | |
| `engine_version` | VARCHAR(40) | e.g. `coreflux-mvp-1.0` or `checkhq-2026Q1` |
| `tax_table_version_id` | BIGINT FK→`payroll_tax_tables.id` | |
| `gross_total` | DECIMAL(12,2) | |
| `ee_taxes_total` | DECIMAL(12,2) | employee-paid taxes (FICA, federal, state) |
| `er_taxes_total` | DECIMAL(12,2) | employer burden (FICA match, FUTA, SUTA) |
| `deductions_total` | DECIMAL(12,2) | |
| `net_total` | DECIMAL(12,2) | |
| `built_at` | DATETIME NULL | |
| `built_by_user_id` | BIGINT NULL FK | |
| `approved_at` | DATETIME NULL | |
| `approved_by_user_id` | BIGINT NULL FK | |
| `disbursed_at` | DATETIME NULL | |
| `disbursed_by_user_id` | BIGINT NULL FK | |
| `journal_entry_id` | BIGINT NULL FK | |
| `nacha_file_storage_object_id` | BIGINT NULL FK | when DD file generated |

### 3.5 `payroll_run_lines` (one row per employee per run)

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `run_id` | BIGINT FK | |
| `person_id` | BIGINT FK | |
| `profile_id` | BIGINT FK | snapshot of which profile version was used |
| `placement_id` | BIGINT NULL FK | when line ties to a single placement |
| `hours_regular` | DECIMAL(8,2) | |
| `hours_ot` | DECIMAL(8,2) | |
| `hours_holiday` / `hours_vacation` / `hours_sick` / `hours_other_pto` | DECIMAL(8,2) | |
| `gross_regular` | DECIMAL(12,2) | |
| `gross_ot` | DECIMAL(12,2) | |
| `gross_pto` | DECIMAL(12,2) | |
| `gross_other` | DECIMAL(12,2) | bonuses, commissions |
| `gross_total` | DECIMAL(12,2) | |
| `ee_fica_ss` / `ee_fica_medicare` / `ee_fed_withholding` / `ee_state_withholding` / `ee_local_withholding` | DECIMAL(10,2) | employee-paid |
| `er_fica_ss` / `er_fica_medicare` / `er_futa` / `er_suta` | DECIMAL(10,2) | employer-paid (burden) |
| `pre_tax_deductions_total` | DECIMAL(10,2) | 401k, HSA, premium-only |
| `post_tax_deductions_total` | DECIMAL(10,2) | garnishments, after-tax retirement |
| `net_pay` | DECIMAL(12,2) | |
| `disbursement_method` | ENUM('direct_deposit','check','pay_card','hold') | |
| `disbursement_status` | ENUM('pending','sent','cleared','failed','void') | |
| `pay_stub_storage_object_id` | BIGINT NULL FK | rendered PDF in S3 |
| `time_bundle_ids_json` | TEXT | which `time_downstream_feed` rows fed this line |

### 3.6 `payroll_deductions` (per-employee recurring deductions)

```
id, tenant_id, person_id, type ('401k','hsa','health_premium','dental','vision','life',
'garnishment','child_support','loan_repayment','other'),
calc_method ('flat','pct_gross','pct_net','pct_disposable'),
amount_or_pct, pre_tax (boolean), employer_match_pct (nullable),
maximum_per_year (nullable), priority_order (for garnishment stacking),
effective_from, effective_to, gl_account_code, active
```

### 3.7 `payroll_tax_tables` (versioned tax rates)

```
id, jurisdiction (federal/state/local), code (e.g. "US_FED_2026"),
effective_from, effective_to, brackets_json, fica_ss_rate, fica_ss_wage_base,
fica_medicare_rate, fica_medicare_addl_threshold, futa_rate, futa_wage_base,
suta_rate (per state), suta_wage_base, source_url, source_published_at, version
```

Updated annually; old versions retained forever for replay.

### 3.8 `payroll_tax_liabilities` (accruals → remittance schedule)

```
id, tenant_id, period_id, run_id, jurisdiction, tax_type ('fica','fed_wh','state_wh','futa','suta','local'),
liability_amount, due_date, remittance_status ('accrued','paid','filed'),
paid_at, payment_ref, journal_entry_id
```

### 3.9 `payroll_w2_ledger` (year-end rollup, per employee per tax year)

```
id, tenant_id, tax_year, person_id,
box1_wages, box2_fed_wh, box3_ss_wages, box4_ss_tax, box5_med_wages, box6_med_tax,
box12_codes_json, box14_other_json, state_boxes_json,
w2_pdf_storage_object_id, submitted_to_ssa_at
```

---

## 4. Engine abstraction (engine swappability)

```php
namespace Modules\Payroll;

interface PayrollEngine {
    public function build_run(int $runId): RunBuildResult;
    public function recalculate_line(int $runLineId): RunLineResult;
    public function generate_pay_stub_payload(int $runLineId): PayStubData;
    public function generate_nacha_file(int $runId): string;  // returns NACHA file content
    public function generate_w2_payload(int $tenantId, int $taxYear, int $personId): W2Data;
    public function get_engine_version(): string;
}

class CorefluxMvpEngine implements PayrollEngine { ... }   // Phase A
class CheckHqEngine     implements PayrollEngine { ... }   // Phase C — adapter to Check HQ API
class GustoEngine       implements PayrollEngine { ... }   // Phase C — adapter to Gusto API
```

`payroll_runs.engine_version` records which engine produced the run. Switching engines mid-tenant is supported by swapping the configured engine for new periods only — historical runs remain bound to their engine version.

---

## 5. Permissions

| Slug | Description |
|---|---|
| `payroll.view` | View payroll dashboards |
| `payroll.schedules.manage` | Define pay schedules |
| `payroll.profiles.view` / `payroll.profiles.manage` | Per-employee setup |
| `payroll.profiles.banking.view` / `.manage` | Encrypted DD info |
| `payroll.run.build` | Build a payroll run |
| `payroll.run.approve` | Approve a built run |
| `payroll.run.disburse` | Release disbursements |
| `payroll.run.reverse` | Reverse a disbursed run |
| `payroll.run.post` | Post to GL |
| `payroll.deductions.manage` | Manage employee deductions |
| `payroll.tax.view` / `payroll.tax.manage` | Tax liabilities + remittance |
| `payroll.w2.view` / `payroll.w2.generate` | W-2 ledger + form generation |
| `payroll.reports.view` | Payroll reports |

`default_roles`: `master_admin`, `tenant_admin`, `admin`. SoD: `run.build` ≠ `run.approve` ≠ `run.disburse`.

---

## 6. API surface

### 6.1 Schedules / periods
- `GET|POST|PATCH /api/payroll/schedules`
- `GET /api/payroll/periods` — filters `schedule_id`, `status`, `from`, `to`
- `POST /api/payroll/periods/generate` — auto-generate next N periods per schedule (cron)

### 6.2 Profiles
- `GET|POST|PATCH /api/payroll/profiles`
- `GET|PUT /api/payroll/profiles/{id}/banking` — encrypted, audit-logged

### 6.3 Runs
- `POST /api/payroll/runs/build` — body `{period_id, run_type}`; pulls W2 time bundles, calls `PayrollEngine::build_run`.
- `GET /api/payroll/runs` / `GET /api/payroll/runs/{id}` (with all lines).
- `POST /api/payroll/runs/{id}/approve` — gated by `payroll.run.approve`.
- `POST /api/payroll/runs/{id}/disburse` — gated by `payroll.run.disburse`; generates DD/check files.
- `POST /api/payroll/runs/{id}/post` — calls `accounting/`.
- `POST /api/payroll/runs/{id}/reverse` — full run reversal (off-cycle correction follows).

### 6.4 Deductions / tax / W-2
- `GET|POST|PATCH /api/payroll/deductions`
- `GET /api/payroll/tax-liabilities` — `?as_of=...`
- `POST /api/payroll/tax-liabilities/{id}/mark-paid`
- `GET /api/payroll/w2/ledger?tax_year=YYYY`
- `POST /api/payroll/w2/generate?tax_year=YYYY`

### 6.5 Reports
- `GET /api/payroll/reports/labor-cost-by-placement` — total wages + burden by placement
- `GET /api/payroll/reports/burden-rate` — er-burden % of gross
- `GET /api/payroll/reports/payroll-register` — line-level register per run

---

## 7. UI / sidebar

| Route | Label | Permission |
|---|---|---|
| `dashboard` | Payroll Dashboard | `payroll.view` |
| `schedules` | Pay Schedules | `payroll.schedules.manage` |
| `periods` | Pay Periods | `payroll.run.build` |
| `profiles` | Employee Setup | `payroll.profiles.manage` |
| `runs` | Pay Runs | `payroll.run.build` |
| `tax` | Tax Liabilities | `payroll.tax.view` |
| `w2` | W-2 Ledger | `payroll.w2.view` |
| `reports` | Reports | `payroll.reports.view` |

---

## 8. Audit events

`payroll.schedule.*` (created/updated/deactivated)
`payroll.profile.*` (created/updated/banking_viewed/banking_updated)
`payroll.run.*` (built/approved/disbursed/posted/reversed/voided)
`payroll.deduction.*`
`payroll.tax.*` (liability_accrued/paid/filed)
`payroll.w2.*` (ledger_built/form_generated/submitted)

`payroll.profile.banking_viewed` is critical PII access — surfaces in PII access log per tenant_admin (per HARD_RULES).

---

## 9. Validation rules

- Run cannot build if any feeding `time_downstream_feed` row of `bundle_type='payroll'` is not `ready` or already `consumed`.
- Run cannot approve while any line has `gross_total < 0` (use a separate negative correction run instead).
- Disbursement blocked unless run `status='approved'`.
- Posting blocked if accounting period closed.
- Tax table version required at build time; missing → block.
- W-4 / state W-4 must be on file (`people_tax`) before first run for an employee.

---

## 10. Multi-tenancy

- All tables filter by `tenant_id`.
- Banking encrypted at app layer (KMS) per HARD_RULES.
- Pay stubs stored in S3 under `payroll/{tenant_id}/run/{run_id}/stub_{person_id}.pdf` with Object Lock per StorageService SPEC.
- Outbound stub-delivery emails via Core MailService.

---

## 11. Decisions inherited / locked

1. ✅ Deterministic engine; AI never in the calc path.
2. ✅ Engine-swappable interface — MVP in-house, future Check HQ / Gusto.
3. ✅ Rate snapshot via `placement_rates.id`.
4. ✅ Pay stubs in S3, retained 7 yrs Object Lock.
5. ✅ Banking encrypted at app layer.
6. ✅ Two-eye: build ≠ approve ≠ disburse.
7. ✅ Off-cycle runs supported.
8. ✅ **Posts to Accounting via standardized protocol** (`POST /api/v1/accounting/journal-entries`). Each pay run produces one or more JEs (gross wages dr / net pay cr / tax accruals / employer burden / deductions). Required: `entity_id`, `idempotency_key` (e.g. `payroll.run.post.{run_id}`), `source_module='payroll'`, `source_ref_type/id`, `dimensions` per line (department, location, placement, employee).
9. ✅ Multi-entity: every payroll run, profile, and period scoped to an `entity_id`.

---

## 12. Open questions

1. **Tax tables source of truth** — manual annual update by tenant_admin, or platform-managed (CoreFlux maintains `payroll_tax_tables` centrally and pushes updates to all tenants)? Recommend platform-managed — tenants don't want to touch tax tables.
2. **Direct deposit / NACHA file** — MVP generates the file; tenant uploads to their bank manually. Or full bank integration? Recommend MVP file-only.
3. **Multi-state worker support** — workers who work across state lines (e.g. NY resident working in NJ). Required for MVP, or limit to single-state at MVP? Recommend single-state at MVP, multi-state in Phase B.
4. **Reciprocal agreements** — automatically apply NJ/PA, NJ/NY type reciprocity rules? Recommend Phase B.
5. **Garnishment ordering** — IRS levy > child support > creditor garnishments. Hardcode the priority or tenant-configurable? Recommend hardcoded federal default; tenant can re-order at their own risk.
6. **Employer 401k match** — is this in MVP or deferred?
7. **MVP timing for Check HQ / Gusto integration** — is this Phase B or Phase C? (Affects how much we invest in the in-house engine.)
8. **Pay card** disbursement option — in MVP or deferred?

---

## 13. MVP cut list

**Phase A (basic in-house engine):**
- `payroll_schedules`, `payroll_periods`, `payroll_profiles`, `payroll_runs`, `payroll_run_lines`
- Basic gross-to-net for hourly + salary, single-state
- FICA (SS + Medicare), federal withholding, single state withholding
- FUTA + SUTA accruals
- Pay stub PDF rendering
- Run build → approve → mark disbursed (no NACHA yet)
- GL post via Accounting
- Audit + RBAC

**Phase B:**
- Multi-state workers + reciprocal agreements
- Garnishments + complex deductions
- Tax liability remittance tracking
- NACHA file generation
- W-2 ledger + form PDF generation
- AI run-summary narrative (advisory only)

**Phase C:**
- Check HQ adapter — `CheckHqEngine implements PayrollEngine`
- Gusto adapter alternative
- Pay card disbursement
- Tax e-file integration

---

*Binding once signed off.*
