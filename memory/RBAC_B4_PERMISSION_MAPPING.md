# RBAC Phase B4 — Permission String → (module, action) Mapping

> **Status:** authoritative mapping for the legacy `RBAC::hasPermission()` / `RBAC::requirePermission()` callers being swept onto the new `RBACResolver` grid.
> **Produced:** 2026-02, current fork. Reviewed before the B4 sweep starts.
> **Source:** `grep -rhoE "RBAC::(hasPermission|requirePermission)\(\$user, *'[^']+'"` over `/api` + `/modules` → 108 unique permission strings, 130+ callsites.

---

## 1. Translation rules

The new resolver answers `can($user, $tenant, $module, $action, ?$subTenant, ?$persona)` with four ordered access levels:

```
none  <  read  <  write  <  admin
```

We collapse the legacy verbose permission strings into `(module_key, action)` using these rules:

### 1.1 `module_key` = the first segment

| Legacy first segment | New `module_key` | Notes |
|---|---|---|
| `accounting`        | `accounting`     | direct |
| `ap`                | `ap`             | direct |
| `billing`           | `billing`        | direct |
| `people`            | `people`         | direct |
| `placements`        | `placements`     | direct |
| `payroll`           | `payroll`        | direct |
| `time`              | `time`           | direct |
| `treasury`          | `treasury`       | direct |
| `reports`           | `reports`        | direct |
| `staffing`          | `staffing`       | direct |
| `integrations`      | `integrations`   | **NEW** module_key — add to seed |
| `ai`                | `ai`             | **NEW** module_key — add to seed |
| `tenant`            | `_platform`      | **Special.** Not a tenant-scoped module; gates platform admin operations. Keep as a legacy `RBAC::*` check until we model platform-level capabilities separately. |

### 1.2 Verb → `action`

Default rule: read everything after the first segment. Match the **terminal verb** (last segment) against this table; sub-segments like `bank.`, `je.`, `bill.` are informational, not part of action selection.

| Terminal verb / pattern | Action | Reasoning |
|---|---|---|
| `view`, `audit.view`, `consume`, `self`, `pii.audit.view` | **read** | look-only |
| `create`, `edit`, `manage` (with no admin connotation), `draft`, `submit`, `record`, `send`, `allocate`, `reconcile`, `bulk_upload`, `merge`, `assign`, `complete`, `categories.manage`, `commissions.manage`, `docs.manage`, `tax.manage`, `recurring.manage`, `custom_fields.manage`, `pipeline.substages.manage`, `corp.manage`, `referrals.manage`, `financials.manage`, `payment.manage`, `dimensions.view→manage`, `entities.view→manage`, `portal_credentials.view`, `entry.create`, `entry.manage`, `bill.create`, `expense.submit`, `expense.approve` *(approver workflow, not admin)*, `intake.*`, `period.view` | **write** | day-to-day mutation |
| `approve`, `approve_admin`, `post`, `void`, `reverse`, `execute_payment`, `terminate`, `pii.manage`, `1099.generate`, `manage_posting_rules`, `close_workflow.manage`, `period.close`, `bank.manage`, `dimensions.manage`, `entities.manage`, `intercompany.manage`, `bank.reconcile`, `tokenized_email.issue`, `tokenized_email.revoke`, `payroll.runs.approve`, `treasury.approve_payment`, `treasury.approve_transfer`, `financials.approve`, `payment.send`, `payment.create` *(if it triggers movement)*, `ai.config.manage`, `tenant.manage` | **admin** | irreversible / financially material / governance |

**Tie-breaker rule:** when in doubt between `write` and `admin`, choose `admin` for anything that *moves money*, posts to the GL, voids a record, terminates a person, or modifies cross-tenant configuration.

### 1.3 Sub-tenant scope

The legacy permission strings have no sub-tenant dimension. We pass `null` to `api_require_can()` for every translated call, which means "any sub-tenant under this tenant". Tenant admins can later tighten sub-tenant scope via `/admin/memberships` (B3 UI) without code changes.

### 1.4 Special: `expense.approve` vs `approve_admin`

`ap.expense.approve` is granted to managers (write-tier approver workflow). `ap.bills.approve_admin` requires controller-level (admin-tier). Translation:

| Legacy | New |
|---|---|
| `ap.expense.approve` | `(ap, write)` |
| `ap.bills.approve_admin` | `(ap, admin)` |
| `ap.bill.approve` | `(ap, admin)` — approving a bill releases money, treat as admin |

---

## 2. Full permission → tuple table

108 strings. Sorted by module.

### accounting
| Legacy permission | New tuple |
|---|---|
| `accounting.audit.view` | `(accounting, read)` |
| `accounting.bank.manage` | `(accounting, admin)` |
| `accounting.bank.reconcile` | `(accounting, admin)` |
| `accounting.bank.view` | `(accounting, read)` |
| `accounting.close_task.assign` | `(accounting, write)` |
| `accounting.close_task.complete` | `(accounting, write)` |
| `accounting.close_workflow.manage` | `(accounting, admin)` |
| `accounting.coa.edit` | `(accounting, write)` |
| `accounting.coa.manage` | `(accounting, admin)` |
| `accounting.coa.view` | `(accounting, read)` |
| `accounting.create_entry` | `(accounting, write)` |
| `accounting.dimensions.manage` | `(accounting, admin)` |
| `accounting.dimensions.view` | `(accounting, read)` |
| `accounting.entities.manage` | `(accounting, admin)` |
| `accounting.entities.view` | `(accounting, read)` |
| `accounting.intercompany.manage` | `(accounting, admin)` |
| `accounting.je.create` | `(accounting, write)` |
| `accounting.je.post` | `(accounting, admin)` |
| `accounting.je.reverse` | `(accounting, admin)` |
| `accounting.je.view` | `(accounting, read)` |
| `accounting.manage_posting_rules` | `(accounting, admin)` |
| `accounting.period.view` | `(accounting, read)` |
| `accounting.reports.export` | `(accounting, write)` |
| `accounting.reports.view` | `(accounting, read)` |

### ai *(NEW module_key — add to seed)*
| Legacy permission | New tuple |
|---|---|
| `ai.config.manage` | `(ai, admin)` |

### ap
| Legacy permission | New tuple |
|---|---|
| `ap.1099.generate` | `(ap, admin)` |
| `ap.1099.view` | `(ap, read)` |
| `ap.bill.approve` | `(ap, admin)` |
| `ap.bill.create` | `(ap, write)` |
| `ap.bill.post` | `(ap, admin)` |
| `ap.bill.view` | `(ap, read)` |
| `ap.bill.void` | `(ap, admin)` |
| `ap.bills.approve_admin` | `(ap, admin)` |
| `ap.expense.approve` | `(ap, write)` |
| `ap.expense.submit` | `(ap, write)` |
| `ap.export.run` | `(ap, write)` |
| `ap.payment.allocate` | `(ap, write)` |
| `ap.payment.create` | `(ap, admin)` *(creates movement)* |
| `ap.payment.send` | `(ap, admin)` *(releases funds)* |
| `ap.recurring.manage` | `(ap, write)` |
| `ap.reports.view` | `(ap, read)` |
| `ap.vendor.view_pii` | `(ap, admin)` *(PII gate)* |
| `ap.view` | `(ap, read)` |

### billing
| Legacy permission | New tuple |
|---|---|
| `billing.invoice.approve` | `(billing, admin)` |
| `billing.invoice.create` | `(billing, write)` |
| `billing.invoice.draft` | `(billing, write)` |
| `billing.invoice.send` | `(billing, admin)` |
| `billing.invoice.void` | `(billing, admin)` |
| `billing.payments.record` | `(billing, write)` |
| `billing.view` | `(billing, read)` |

### integrations *(NEW module_key — add to seed)*
| Legacy permission | New tuple |
|---|---|
| `integrations.jobdiva.manage` | `(integrations, admin)` |
| `integrations.jobdiva.view` | `(integrations, read)` |
| `integrations.qbo.manage` | `(integrations, admin)` |
| `integrations.qbo.view` | `(integrations, read)` |

### payroll
| Legacy permission | New tuple |
|---|---|
| `payroll.runs.approve` | `(payroll, admin)` |

### people
| Legacy permission | New tuple |
|---|---|
| `people.banking.manage` | `(people, admin)` *(banking PII)* |
| `people.banking.view` | `(people, admin)` *(banking PII)* |
| `people.custom_fields.manage` | `(people, write)` |
| `people.docs.manage` | `(people, write)` |
| `people.docs.view` | `(people, read)` |
| `people.graph.delegate` | `(people, admin)` *(authority delegation)* |
| `people.graph.manage` | `(people, admin)` *(authority/responsibility model)* |
| `people.graph.view` | `(people, read)` |
| `people.manage` | `(people, write)` |
| `people.merge` | `(people, admin)` |
| `people.pii.audit.view` | `(people, admin)` |
| `people.pii.manage` | `(people, admin)` |
| `people.pii.view` | `(people, admin)` *(PII gate)* |
| `people.pipeline.substages.manage` | `(people, write)` |
| `people.tax.manage` | `(people, admin)` |
| `people.tax.view` | `(people, read)` |
| `people.terminate` | `(people, admin)` |
| `people.view` | `(people, read)` |

### placements
| Legacy permission | New tuple |
|---|---|
| `placements.commissions.manage` | `(placements, admin)` |
| `placements.commissions.view` | `(placements, read)` |
| `placements.corp.manage` | `(placements, write)` |
| `placements.corp.view` | `(placements, read)` |
| `placements.docs.manage` | `(placements, write)` |
| `placements.docs.view` | `(placements, read)` |
| `placements.financials.approve` | `(placements, admin)` |
| `placements.financials.manage` | `(placements, admin)` |
| `placements.financials.view` | `(placements, read)` |
| `placements.manage` | `(placements, write)` |
| `placements.portal_credentials.view` | `(placements, admin)` *(secret material)* |
| `placements.referrals.manage` | `(placements, write)` |
| `placements.terminate` | `(placements, admin)` |
| `placements.view` | `(placements, read)` |

### reports
| Legacy permission | New tuple |
|---|---|
| `admin.export_templates.manage` | `(reports, admin)` |
| `reports.custom.build` | `(reports, write)` |
| `reports.custom.share` | `(reports, admin)` |
| `reports.export` | `(reports, write)` |
| `reports.view` | `(reports, read)` |

### staffing
| Legacy permission | New tuple |
|---|---|
| `staffing.billing.manage` | `(staffing, write)` |
| `staffing.billing.view` | `(staffing, read)` |
| `staffing.payroll.manage` | `(staffing, write)` |
| `staffing.payroll.view` | `(staffing, read)` |
| `staffing.reports.view` | `(staffing, read)` |
| `staffing.settings.manage` | `(staffing, admin)` |
| `staffing.time.approve` | `(staffing, admin)` |
| `staffing.time.create` | `(staffing, write)` |
| `staffing.time.reject` | `(staffing, admin)` |
| `staffing.time.submit` | `(staffing, write)` |
| `staffing.time.view` | `(staffing, read)` |
| `staffing.view` | `(staffing, read)` |

### tenant *(NOT migrated — platform gate)*
| Legacy permission | Status |
|---|---|
| `tenant.manage` | **PARK.** Keep `RBAC::hasPermission($user, 'tenant.manage')` for now. Will be modelled as `is_global_admin` + a future `platform_admin` capability check after B4 closes. ~5 callsites. |

### time
| Legacy permission | New tuple |
|---|---|
| `time.approve` | `(time, admin)` |
| `time.bulk_upload` | `(time, write)` |
| `time.categories.manage` | `(time, write)` |
| `time.entry.create` | `(time, write)` |
| `time.entry.manage` | `(time, write)` |
| `time.entry.self` | `(time, read)` *(self-service look)* |
| `time.feed.consume` | `(time, read)` |
| `time.period.close` | `(time, admin)` |
| `time.reject` | `(time, admin)` |
| `time.review` | `(time, write)` |
| `time.tokenized_email.issue` | `(time, admin)` |
| `time.tokenized_email.revoke` | `(time, admin)` |
| `time.view` | `(time, read)` |

### treasury
| Legacy permission | New tuple |
|---|---|
| `treasury.approve_payment` | `(treasury, admin)` |
| `treasury.approve_transfer` | `(treasury, admin)` |
| `treasury.create_payment` | `(treasury, admin)` *(releases funds path)* |
| `treasury.create_transfer` | `(treasury, write)` |
| `treasury.execute_payment` | `(treasury, admin)` |
| `treasury.payment.manage` | `(treasury, admin)` |
| `treasury.payment.view` | `(treasury, read)` |
| `treasury.view_bank_balances` | `(treasury, read)` |

---

## 3. Sweep execution plan

### 3.1 Helper (zero-risk shim)

Drop a one-shot translator into `/app/core/rbac/legacy_bridge.php`:

```php
function rbac_legacy_can(array $user, string $legacyPerm): bool {
    [$module, $action] = RbacLegacyMap::resolve($legacyPerm);
    if ($module === '_platform') {
        // PARK — keep legacy behaviour until platform_admin lands.
        return RBAC::hasPermission($user, $legacyPerm);
    }
    return api_can($module, $action);
}
```

with `RbacLegacyMap::resolve(string $perm): [string $module, string $action]` returning the table above. This lets us migrate callsites one file at a time with a single-line edit:

```php
// BEFORE
RBAC::requirePermission($user, 'ap.bill.approve');

// AFTER
api_require_can('ap', 'admin');  // or via the bridge if we want graduated risk
```

### 3.2 Migration order (lowest blast radius first)

1. **Pure-view endpoints first** (`*.view`) — ~38 callsites. Down-grade risk: read-only failure is recoverable.
2. **Module CRUD** (`*.create`, `*.manage`) — ~40 callsites.
3. **Approval / posting / void** (admin-tier) — ~30 callsites. Highest risk — must verify the actor still has the right level after the translation.
4. **`tenant.manage`** — PARKED (see table). Do not touch in this sweep.

After each batch, run:
```
yarn --cwd /app/dashboard build
bash -c "for f in /app/tests/*_smoke.php; do php -d zend.assertions=1 \$f; done"
```

### 3.3 Backfill alignment

`scripts/backfill_memberships.php` currently grants `access_level=admin` for `master_admin/tenant_admin/admin` roles, `write` for `manager`, `read` for `employee/contractor`. After the sweep, these levels must satisfy every `api_require_can()` call above. Spot-checks:

- `(payroll, admin)` — gated by `admin`+ today. ✅ matches.
- `(people, admin)` (banking/PII) — gated by `admin`+ today. ✅ matches.
- `(treasury, admin)` (approve_payment) — gated by `admin`+ today. ✅ matches.
- `(time, write)` (entry.manage) — `manager` gets `write`. ✅ matches.

No backfill changes required.

### 3.4 New module seeds

Two module keys (`integrations`, `ai`) are not currently in `getUserModules()` enumeration. Backfill them into `membership_module_access` for existing memberships:

```sql
INSERT IGNORE INTO membership_module_access
   (membership_id, module_key, access_level, granted_by_user_id)
SELECT tm.id, 'integrations',
       CASE WHEN tm.persona_type IN ('master_admin','tenant_admin','admin') THEN 'admin'
            WHEN tm.persona_type = 'manager'                                THEN 'read'
            ELSE 'none' END,
       tm.invited_by_user_id
  FROM tenant_memberships tm WHERE tm.status='active';

INSERT IGNORE INTO membership_module_access
   (membership_id, module_key, access_level, granted_by_user_id)
SELECT tm.id, 'ai',
       CASE WHEN tm.persona_type IN ('master_admin','tenant_admin','admin') THEN 'admin'
            ELSE 'none' END,
       tm.invited_by_user_id
  FROM tenant_memberships tm WHERE tm.status='active';
```

Wrap in a forward migration (`056_rbac_b4_seed_new_modules.sql`) once we begin the sweep.

### 3.5 Test coverage gates

- All 205 existing smoke tests must remain green at every step.
- Add `/app/tests/rbac_b4_bridge_smoke.php` to lock the `RbacLegacyMap::resolve` table against this doc (one assertion per row above so the doc and code can never drift).
- After each batch, spot-test 3 real endpoints with `curl` + a `tenant_admin` cookie to confirm 200 responses.

### 3.6 Out of scope for B4

- Sub-tenant scoping refinement (kept as `null` for every translated call).
- Retirement of `/app/core/RBAC.php` — happens **after** every callsite is migrated; the legacy class stays loaded throughout the sweep so any miss falls back gracefully.
- The `tenant.manage` PARK — separate slice once we model `platform_admin`.

---

## 4. Open questions for review

| # | Question | Default if no answer |
|---|---|---|
| 1 | Should `placements.portal_credentials.view` be `admin` (secret material) or `write` (recruiter daily op)? | `admin` — secret material |
| 2 | Should `ap.payment.create` be `admin` (about to move money) or `write` (just creates a draft)? | `admin` — safer; can downgrade later |
| 3 | Should `accounting.coa.edit` and `accounting.coa.manage` collapse into one level, or stay as `write` vs `admin`? | Keep both — `edit=write`, `manage=admin` |
| 4 | When should the `tenant.manage` PARK be addressed? Suggest a P2 follow-up after B4 closes. | Defer to post-B4 backlog |

Sign-off needed from a tenant_admin reviewer before kicking off the sweep batches.
