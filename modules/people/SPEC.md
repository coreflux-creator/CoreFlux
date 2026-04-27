# People — Module Specification

**Status**: DRAFT — pending user sign-off
**Stack**: PHP 8 (per HARD_RULES decisions log, 2026-02)
**Owner module of**: the talent pool — every individual person the agency knows about, regardless of whether they are currently on a placement.
**NOT owner of**: active deals, bill/pay rates, or time entries. Those live in `placements/` and `time/`.

This SPEC is written so the module can be re-created from scratch if needed. It is the source of truth — implementation must match, and any deviation requires updating this file first.

---

## 1. Purpose

People is the staffing agency's **talent system of record**. It answers:

- Who do we know? (candidates, contractors, W2 employees, alumni)
- How do we contact them?
- What's their classification, work authorization, and skills profile?
- What's their history with us across all placements?
- What documents have they signed?

It does **not** answer "who is currently billing $X/hr to client Y?" — that's `placements/`.

---

## 2. Scope boundaries (what's in / what's out)

### In scope
- Person records (one row per human, lifetime)
- Contact info (email, phone, address, emergency contact)
- Classification (W2 / 1099 / C2C / temp / perm — single record + enum, per HARD_RULES R-2026-04-27)
- Work authorization (visa type, expiry, sponsorship needs)
- Skills, resume, recruiter notes
- Documents (offer letters, I-9, W-4, NDAs, signed contracts) — file storage references only
- Banking & tax info (W-4, direct deposit) — encrypted at rest, gated by `people.banking.view` / `people.tax.view`
- Tenant-customizable custom fields (per HARD_RULES R-2026-04-27)
- Hiring pipeline / stages (sourced → screened → submitted → placed → bench → terminated)
- Cross-placement history (read-only join from placements module)

### Out of scope (lives elsewhere)
- Bill rate, pay rate, client, end client → `placements/`
- Time entries, timesheets → `time/`
- Invoicing, payroll runs → `accounting/`, future `payroll/`
- Client/company records → **NOT a CoreFlux entity** (per HARD_RULES R-2026-04-27, client is a string label only)

---

## 3. Data model

### 3.1 Tables

#### `people`
| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `tenant_id` | BIGINT FK | RBAC scope |
| `external_id` | VARCHAR(64) NULL | tenant-supplied stable id (ATS, payroll, etc.) |
| `first_name` | VARCHAR(100) | |
| `middle_name` | VARCHAR(100) NULL | |
| `last_name` | VARCHAR(100) | |
| `preferred_name` | VARCHAR(100) NULL | |
| `email_primary` | VARCHAR(255) | unique within tenant |
| `email_secondary` | VARCHAR(255) NULL | |
| `phone_primary` | VARCHAR(40) NULL | E.164 |
| `phone_secondary` | VARCHAR(40) NULL | |
| `classification` | ENUM('w2','1099','c2c','temp','perm','candidate','alumni') | per HARD_RULES |
| `status` | ENUM('active','bench','inactive','do_not_rehire') | |
| `work_auth_status` | ENUM('citizen','green_card','h1b','opt','cpt','tn','other','unknown') | |
| `work_auth_expiry` | DATE NULL | |
| `requires_sponsorship` | BOOLEAN | |
| `dob` | DATE NULL | PII — gated by `people.pii.view` |
| `ssn_last4` | CHAR(4) NULL | PII |
| `home_address_line1` | VARCHAR(255) NULL | |
| `home_address_line2` | VARCHAR(255) NULL | |
| `home_city` | VARCHAR(120) NULL | |
| `home_state` | VARCHAR(60) NULL | |
| `home_postal_code` | VARCHAR(20) NULL | |
| `home_country` | CHAR(2) NULL | ISO-3166 |
| `linkedin_url` | VARCHAR(255) NULL | |
| `resume_storage_object_id` | BIGINT NULL FK→`storage_objects.id` | latest resume — file lives in S3 via Core StorageService |
| `recruiter_notes` | TEXT NULL | |
| `source` | VARCHAR(120) NULL | "LinkedIn", "Referral: Jane Smith", etc. |
| `referred_by_person_id` | BIGINT NULL FK→`people.id` | for referral commissions |
| `created_by_user_id` | BIGINT FK | |
| `created_at` | DATETIME | UTC |
| `updated_at` | DATETIME | UTC |
| `deleted_at` | DATETIME NULL | soft delete only |

Indexes: `(tenant_id, email_primary)` UNIQUE, `(tenant_id, status)`, `(tenant_id, classification)`, `(tenant_id, work_auth_expiry)`.

#### `people_emergency_contacts`
| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `person_id` | BIGINT FK | |
| `name` | VARCHAR(200) | |
| `relationship` | VARCHAR(60) | |
| `phone` | VARCHAR(40) | |
| `email` | VARCHAR(255) NULL | |

#### `people_skills`
| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `person_id` | BIGINT FK | |
| `skill` | VARCHAR(120) | tag-style; tenant-customizable taxonomy lives in `people_skill_taxonomy` |
| `years_experience` | DECIMAL(4,1) NULL | |
| `proficiency` | ENUM('beginner','intermediate','advanced','expert') NULL | |

#### `people_documents`
| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `person_id` | BIGINT FK | |
| `doc_type` | ENUM('resume','offer','i9','w4','w9','nda','contract','passport','visa','license','other') | |
| `storage_object_id` | BIGINT FK→`storage_objects.id` | actual file lives in AWS S3 via Core StorageService; never store S3 keys here directly |
| `signed` | BOOLEAN | |
| `signed_at` | DATETIME NULL | |
| `expires_at` | DATETIME NULL | for visas, certifications |

#### `people_banking` (encrypted)
| Column | Type | Notes |
|---|---|---|
| `person_id` | BIGINT PK FK | one row per person |
| `account_holder_name` | VARCHAR(200) | encrypted |
| `routing_number` | VARCHAR(40) | encrypted |
| `account_number` | VARCHAR(80) | encrypted |
| `account_type` | ENUM('checking','savings') | |
| `updated_at` | DATETIME | |
| `updated_by_user_id` | BIGINT FK | audit |

#### `people_tax`
| Column | Type | Notes |
|---|---|---|
| `person_id` | BIGINT PK FK | |
| `filing_status` | ENUM('single','mfj','mfs','hoh','qw') NULL | |
| `dependents` | INT NULL | |
| `additional_withholding` | DECIMAL(10,2) NULL | |
| `state` | VARCHAR(60) NULL | tax state |
| `ssn_full_ct` | VARBINARY(256) NULL | application-level encrypted (KMS); only ssn_last4 on `people` is cleartext |
| `kms_key_version` | VARCHAR(64) NULL | |
| `w4_doc_id` | BIGINT NULL FK→`people_documents.id` | |
| `updated_at` | DATETIME | |

#### `people_pipeline_stages` (hybrid model — fixed top-level enum + tenant sub-stage)
| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `person_id` | BIGINT FK | |
| `stage` | ENUM('sourced','screened','submitted','interview','offer','placed','bench','terminated','rejected') | fixed enum — cross-tenant reporting works on this |
| `substage_id` | BIGINT NULL FK→`tenant_pipeline_substages.id` | tenant-defined under each top-level stage |
| `entered_at` | DATETIME | |
| `entered_by_user_id` | BIGINT FK | |
| `note` | TEXT NULL | |
| `placement_id` | BIGINT NULL | when stage='placed', link to the placement that caused it |

#### `tenant_pipeline_substages` (per-tenant)
| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `tenant_id` | BIGINT FK | |
| `parent_stage` | ENUM(...same as above) | which top-level stage this rolls up into |
| `label` | VARCHAR(120) | tenant-defined display name |
| `order_index` | INT | sort order within the parent stage |
| `active` | BOOLEAN | soft-disable without losing history |

#### `people_custom_field_defs` (per-tenant)
| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `tenant_id` | BIGINT FK | |
| `field_key` | VARCHAR(80) | snake_case |
| `field_label` | VARCHAR(200) | |
| `field_type` | ENUM('text','number','date','boolean','select','multiselect') | |
| `options_json` | TEXT NULL | for select types |
| `required` | BOOLEAN | |
| `pii` | BOOLEAN | gates view to `people.pii.view` |

#### `people_custom_field_values`
| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `person_id` | BIGINT FK | |
| `field_def_id` | BIGINT FK | |
| `value_text` | TEXT NULL | |
| `value_number` | DECIMAL(20,6) NULL | |
| `value_date` | DATE NULL | |
| `value_boolean` | BOOLEAN NULL | |

### 3.2 Relationships diagram (ASCII)

```
people 1───*  people_emergency_contacts
people 1───*  people_skills
people 1───*  people_documents
people 1───1  people_banking      (encrypted)
people 1───1  people_tax
people 1───*  people_pipeline_stages
people 1───*  people_custom_field_values  *───1  people_custom_field_defs
people 1───*  placements.placements      (cross-module FK; READ ONLY from People)
```

---

## 4. Permissions (RBAC)

Permissions slugs registered via `manifest.php`:

| Slug | Description |
|---|---|
| `people.view` | List + view non-PII fields |
| `people.manage` | Create / edit person records (non-PII, non-comp) |
| `people.terminate` | Set status to `inactive` / `do_not_rehire` |
| `people.pii.view` | View DOB, SSN last 4, home address |
| `people.pii.manage` | Edit PII |
| `people.comp.view` | (Reserved — actual comp lives in placements) |
| `people.comp.manage` | (Reserved) |
| `people.tax.view` | View W-4 / tax setup |
| `people.tax.manage` | Edit tax setup |
| `people.banking.view` | View masked banking |
| `people.banking.manage` | Edit banking |
| `people.docs.view` | View document list |
| `people.docs.manage` | Upload / replace / delete documents |
| `people.timeoff.manage` | (Deferred — out of scope until W2 employee module added) |
| `people.custom_fields.manage` | Define custom fields for the tenant |
| `people.merge` | Merge two duplicate person records (collapses both into one, preserves history) |
| `people.pipeline.substages.manage` | Edit tenant-defined pipeline sub-stages |
| `people.pii.audit.view` | View the PII access log for own tenant (SOC2 self-serve) |

Default roles for module access (manifest `default_roles`): `master_admin`, `tenant_admin`, `admin`.

---

## 5. API surface

All routes pass through `/api/index.php` and resolve via `core/api_router.php` → `/modules/people/api/<endpoint>.php`.

### 5.1 List / search
- `GET /api/people` — filters: `q`, `classification`, `status`, `work_auth_expiry_before`, `skill`, `pipeline_stage`, `page`, `per_page`. Returns paginated list with safe fields only.
- `GET /api/people/{id}` — full record minus PII unless `people.pii.view`.
- `GET /api/people/{id}/history` — joined view of placements + pipeline stages.

### 5.2 Mutate
- `POST /api/people` — create. Required: first_name, last_name, email_primary, classification.
- `PATCH /api/people/{id}` — partial update.
- `POST /api/people/{id}/terminate` — sets status, requires reason; gated by `people.terminate`.
- `POST /api/people/merge` — body `{primary_person_id, duplicate_person_id, field_resolutions}`. Collapses duplicate into primary, re-points all FKs (placements, documents, pipeline, custom fields), soft-deletes the duplicate, audit-logs every collision. Gated by `people.merge`.
- `GET /api/people/audit/pii` — paginated PII access log for current tenant (gated by `people.pii.audit.view`). Filters: `actor_user_id`, `target_person_id`, `event_type`, `since`, `until`.

### 5.3 Sub-resources
- `GET|POST|PATCH|DELETE /api/people/{id}/skills`
- `GET|POST|PATCH|DELETE /api/people/{id}/documents` — uploads via signed URL
- `GET|PUT /api/people/{id}/banking` — gated, audit-logged on every read
- `GET|PUT /api/people/{id}/tax`
- `GET|POST /api/people/{id}/pipeline` — append-only stages
- `GET|POST /api/people/{id}/emergency-contacts`
- `GET|POST|PATCH|DELETE /api/people/custom-fields` (tenant-scoped)

### 5.4 Read-only joins (from other modules)
- `GET /api/people/{id}/placements` — proxies to `placements/` with FK filter
- `GET /api/people/{id}/timesheets` — proxies to `time/` (last N weeks)

---

## 6. UI / sidebar actions

Updated manifest actions:

| Route | Label | Permission |
|---|---|---|
| `directory` | Directory | `people.view` |
| `pipeline` | Hiring Pipeline | `people.view` |
| `documents` | Document Vault | `people.docs.view` |
| `custom_fields` | Custom Fields | `people.custom_fields.manage` |

`org_chart`, `time_off`, `onboarding` from current manifest are **deferred** (not in MVP) — leave commented out in manifest until explicitly approved.

### Detail page tabs
1. Overview — name, contact, classification, status
2. Placements — read-only list from `placements/`
3. Documents — uploaded files
4. Skills & Resume
5. Pipeline history
6. Compliance — work auth, expiries
7. PII (gated) — DOB, SSN last 4, address, banking, tax

---

## 7. Audit events

Every mutation emits `audit_log` rows via core `audit_logger`:

- `people.created`
- `people.updated` (diff in `meta_json`)
- `people.terminated`
- `people.pii.viewed` — every read of PII tab
- `people.banking.viewed` / `people.banking.updated`
- `people.tax.updated`
- `people.document.uploaded` / `people.document.deleted`
- `people.pipeline.stage_added`
- `people.custom_field.defined` / `people.custom_field.value_set`
- `people.merged` — meta_json carries `primary_id`, `duplicate_id`, `field_resolutions`, `re_pointed_fk_counts`
- `people.pipeline.substage.created` / `.updated` / `.deactivated`

---

## 8. AI usage (deferred)

People itself does not use AI in MVP. Future scope:
- Resume parsing → skills auto-tag (review queue, AI describes / human decides per HARD_RULES).
- Duplicate person detection (suggest merge — never auto-merge).

All AI outputs go to a queue; humans approve before any record write. **No AI-direct writes ever.**

---

## 9. Validation rules

- `email_primary` unique per tenant; case-insensitive compare.
- A person cannot be `placed` (pipeline) without an active placement record in `placements/`.
- Setting status to `do_not_rehire` requires `people.terminate` AND a reason.
- Banking updates require re-authentication (step-up) — TBD with auth module.
- Document uploads max 25 MB; types: pdf, png, jpg, docx.

---

## 10. Multi-tenancy

- Every query MUST filter on `tenant_id` via `core/tenant_context`.
- No cross-tenant person merge. If a person joins another tenant, that tenant gets a separate row.
- `master_admin` can read across tenants for support, but every cross-tenant read is audit-logged.

---

## 11. Decisions locked (resolved in spec sign-off)

1. ✅ **Object storage** — AWS S3 via Core StorageService. People documents reference `storage_object_id`, not raw S3 keys. See `/app/core/StorageService.SPEC.md`.
2. ✅ **Encryption at rest** for banking, SSN, EIN — **application-level** (PHP openssl + KMS-managed CMK). DB dump alone is useless. Cleartext never on disk or in logs.
3. ✅ **PII access logging** — visible to **tenant_admin** for self-serve SOC2 audits, plus master_admin. New permission `people.pii.audit.view`. New endpoint `GET /api/people/audit/pii`.
4. ✅ **Pipeline stages** — **hybrid**: fixed top-level enum (cross-tenant reporting) + tenant-defined sub-stages under each. Permission `people.pipeline.substages.manage`.
5. ✅ **Person merge** — included in MVP. Permission `people.merge`. Endpoint `POST /api/people/merge`. Audit event `people.merged` with full diff.

---

## 12. MVP cut list

**Phase A (ship first):**
- `people` table + CRUD
- Directory list + detail
- Skills, documents, pipeline
- Audit logging
- Custom fields

**Phase B (after Placements ships):**
- Banking, tax (encrypted)
- PII gating UI
- Cross-module `/history` join
- Resume parsing AI

**Phase C (later):**
- Org chart, time off, onboarding workflows
- Person merge / dedupe

---

*This SPEC is binding once signed off. Any change requires a PR-style update to this file before code changes.*
Audit logging
- Custom fields

**Phase B (after Placements ships):**
- Banking, tax (encrypted)
- PII gating UI
- Cross-module `/history` join
- Resume parsing AI

**Phase C (later):**
- Org chart, time off, onboarding workflows
- Person merge / dedupe

---

*This SPEC is binding once signed off. Any change requires a PR-style update to this file before code changes.*
