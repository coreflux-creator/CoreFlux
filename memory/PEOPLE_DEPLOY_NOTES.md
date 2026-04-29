# People Module — Cloudways Deploy Notes (Phase A SPEC alignment)

This is a quick-reference for shipping the SPEC-aligned People module to your
Cloudways MySQL database. The legacy module is preserved at
`/app/legacy/people_pre_spec_<date>/` per HARD_RULES R1, and legacy tables
(`people_employees` and friends) are NOT touched by this migration.

## What's in this drop

- New SPEC-aligned schema (12 new tables, all `IF NOT EXISTS`, additive)
- New `Core\StorageService`-backed Documents, encrypted Banking, encrypted Tax SSN
- New PII access log (`people_pii_access_log`) — surfaced via PII Access Log UI
- New person-merge endpoint with audit-logged FK re-pointing
- New React UI: Directory, Person Detail (7 tabs per SPEC §6), Hiring Pipeline,
  Document Vault, Custom Fields, PII Access Log
- 104 smoke tests passing locally; 143 platform smoke tests total green

## Deploy steps on Cloudways

### 1. Pull the latest commit
Push from this preview environment via the **Save to GitHub** button in the
chat input (don't `git push` from here directly — Emergent handles that).

### 2. Run the migration on production MySQL

Two ways:

**Option A — via your existing `/update.php` workflow** (preferred if it runs migrations):
- Visit `https://yourapp.com/update.php`
- Confirm.
- The migration runner picks up `migrations/003_spec_alignment.sql` automatically
  if your runner scans the `modules/*/migrations/` folders.

**Option B — manual (one-time)**, paste this in Cloudways MySQL phpMyAdmin or CLI:

```bash
mysql -u <user> -p <db> < /path/to/coreflux/modules/people/migrations/003_spec_alignment.sql
```

Idempotent (`CREATE TABLE IF NOT EXISTS`) — safe to re-run.

### 3. Verify tables exist
```sql
SHOW TABLES LIKE 'people%';
SHOW TABLES LIKE 'tenant_pipeline_substages';
```
You should see 12 new tables. Legacy `people_employees`, `people_addresses`,
etc. remain in place.

### 4. Test the UI

Log in as `kunal@coreflux.app` (or any `tenant_admin`/`master_admin`).

1. Sidebar → **People** → **Directory**
   - "+ Add person" → fill in first/last/email/classification → Create
   - Person opens → 7 tabs (Overview / Placements / Documents / Skills /
     Pipeline / Compliance / PII)
   - Edit Overview, save, reload → fields persist
   - Add a Skill → reload → still there
   - Append a Pipeline stage → reload → newest first
   - Click **PII** tab → "Reveal PII" → fields render → audit log entry written
2. Sidebar → **People** → **Hiring Pipeline** → click stage tabs, see counts +
   matching people
3. Sidebar → **People** → **Custom Fields** → add `years_in_industry` (number),
   `is_remote_ok` (boolean), confirm rows
4. Sidebar → **People** → **PII Access Log** → see the reveal you just did

### 5. Sanity SQL after creating a few records

```sql
SELECT id, first_name, last_name, email_primary, classification, status FROM people;
SELECT * FROM people_pii_access_log ORDER BY created_at DESC LIMIT 5;
SELECT * FROM people_pipeline_stages ORDER BY entered_at DESC LIMIT 10;
```

## What's NOT in this drop (Phase B / per SPEC §12)

- Banking + Tax detail UIs (endpoints exist; tab UI deferred — UI shows PII tab
  with PII fields only, and banking/tax can be exercised via curl/Postman if you
  need them right now)
- Cross-module `/history` join (waits on Placements module)
- Resume parsing AI
- Cross-person Document Vault aggregator (per-person browse works)
- Org chart, time off, onboarding workflows (per SPEC §12 Phase C)

## Rollback

If anything misbehaves in production:

```sql
-- Drops the 12 new tables only. Legacy people_employees etc. untouched.
DROP TABLE IF EXISTS
  people_pii_access_log,
  people_custom_field_values,
  people_custom_field_defs,
  tenant_pipeline_substages,
  people_pipeline_stages,
  people_tax,
  people_banking,
  people_documents,
  people_skill_taxonomy,
  people_skills,
  people_emergency_contacts,
  people;
```

Old SPA continues to work because the legacy `api/employees.php` etc. and the
legacy `people_employees` table are untouched. Sidebar will show a few
empty tabs until you reapply the migration.

## Files of reference

- `/app/modules/people/SPEC.md` — locked spec (do not edit without review)
- `/app/modules/people/manifest.php` — RBAC + audit events + actions
- `/app/modules/people/migrations/003_spec_alignment.sql` — additive migration
- `/app/modules/people/api/*.php` — 11 SPEC-aligned endpoints
- `/app/modules/people/lib/people.php` — cross-module read interface
- `/app/modules/people/lib/audit.php` — audit_log writer
- `/app/modules/people/ui/*.jsx` — 8 React components
- `/app/tests/people_spec_smoke.php` — 104 contract assertions
- `/app/legacy/people_pre_spec_<date>/` — preserved old code (R1)
