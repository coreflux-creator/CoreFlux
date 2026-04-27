# Core StorageService — Specification

**Status**: DRAFT — pending user sign-off
**Stack**: PHP 8 + AWS S3 (per HARD_RULES decisions log, 2026-02)
**Layer**: Core platform primitive — NOT a module. Lives in `/core/StorageService.php`.
**Consumers**: every module that stores files (People, Placements, Time, Tax, Payroll, Accounting, Finance).

This SPEC defines the single, platform-wide file storage abstraction. Modules MUST NOT call S3 (or any storage backend) directly — they go through `Core\StorageService`. This isolates the vendor decision so it can change later by editing one file.

---

## 1. Why this is a core service, not a module concern

Every module needs file storage. If each module rolls its own:
- Inconsistent path conventions → tenant-isolation bugs.
- Each module has its own AWS credentials → key-rotation nightmare.
- Audit logs scattered → SOC2 / IRS audits fail.
- Switching vendors means rewriting every module.

One service, one S3 client, one audit pathway, one place to rotate keys.

---

## 2. Backend choice

**AWS S3** for the platform (HARD_RULES locked).

- Region: tenant-default (start with one region, e.g. `us-east-1`); future per-tenant region for data-residency customers.
- Encryption at rest: SSE-KMS with platform-managed CMK at MVP. Per-tenant CMK is a Phase B option for enterprise customers.
- Encryption in transit: TLS only.
- Object Lock: enabled on retention-critical prefixes (`tax/*`, `payroll/*`, signed timesheets `time/*/signed/*`).
- Lifecycle: hot tier `Standard` for active docs; transition to `Standard-IA` after 90 days; transition to `Glacier Deep Archive` after 1 year; 7-year retention minimum on `tax/*`, `payroll/*`, `time/*/signed/*`.
- Versioning: enabled on the bucket.
- MFA Delete: enabled on the bucket (only the platform deploy user can permanently delete).

---

## 3. Path convention (LOCKED)

```
{module}/{tenant_id}/{entity_type}/{entity_id}/{slug-or-uuid}-{filename}
```

Examples:

```
people/42/person/991/resume-2026-02-15.pdf
people/42/person/991/i9-signed.pdf
placements/42/placement/318/msa-v3.pdf
placements/42/placement/318/sow-2026.pdf
time/42/timesheet/991-2026-W08/timesheet.pdf
time/42/signed/991-2026-W08/timesheet-signed.pdf
tax/42/year/2025/w2-991.pdf
payroll/42/run/2026-02-01/register.pdf
accounting/42/invoice/INV-2026-0142/invoice.pdf
```

Rules:
- `{module}` and `{tenant_id}` are MANDATORY. The service rejects any `put()` without them.
- `{tenant_id}` always appears as the second segment — enables IAM path scoping per tenant in Phase B.
- Filenames are sanitized (strip `..`, control chars; max 200 chars).
- A short slug or UUID prefix is added to filenames to prevent collision and accidental overwrites.
- Versioning at the bucket level catches accidental same-key overwrites anyway.

---

## 4. PHP API surface

### 4.1 `Core\StorageService` (singleton, instantiated in `core/api_bootstrap.php`)

```php
namespace Core;

class StorageService {
    public function put(
        string $module,
        int    $tenantId,
        string $entityType,
        string|int $entityId,
        string $filename,
        string $localPathOrStream,
        array  $opts = []          // ['mime' => ..., 'lock_until' => ISO date, 'tags' => [...]]
    ): StorageObject;              // returns id, key, version_id, size, etag, signed_url

    public function get_signed_url(
        string $key,
        int    $ttlSeconds = 300,  // default 5 min
        array  $opts = []           // ['filename_for_download' => ..., 'inline' => true]
    ): string;

    public function head(string $key): StorageObject;

    public function list(
        string $prefix,             // must be tenant-scoped
        ?string $continuationToken = null
    ): StorageList;

    public function soft_delete(string $key): void;     // marks; respects Object Lock
    public function restore_deleted(string $key, string $versionId): void;
    public function apply_legal_hold(string $key, bool $on): void;
}
```

`StorageObject` carries: `id`, `key`, `version_id`, `size_bytes`, `mime`, `etag`, `tenant_id`, `module`, `created_at`, `created_by_user_id`, `lock_until`, `legal_hold`, `tags_json`.

### 4.2 DB index (because S3 is the file store, but metadata stays in MySQL)

#### `storage_objects` (one row per logical file write)

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `tenant_id` | BIGINT FK | |
| `module` | VARCHAR(40) | `people`, `placements`, `time`, `tax`, `payroll`, ... |
| `entity_type` | VARCHAR(40) | `person`, `placement`, `timesheet`, `year`, `run`, `invoice`, ... |
| `entity_id` | VARCHAR(64) | foreign id as string (covers BIGINT and composite keys like `2026-W08`) |
| `s3_key` | VARCHAR(1024) | full S3 key |
| `s3_version_id` | VARCHAR(80) | bucket versioning id |
| `filename` | VARCHAR(255) | original filename for download |
| `mime` | VARCHAR(120) | |
| `size_bytes` | BIGINT | |
| `etag` | VARCHAR(80) | |
| `lock_until` | DATE NULL | retain-until (Object Lock) |
| `legal_hold` | BOOLEAN | |
| `created_by_user_id` | BIGINT FK | |
| `created_at` | DATETIME | |
| `soft_deleted_at` | DATETIME NULL | |
| `tags_json` | TEXT NULL | |

Indexes: `(tenant_id, module, entity_type, entity_id)`, `(s3_key)` UNIQUE.

Module FKs (`people_documents.file_path`, `placement_documents.file_path`, etc.) become `storage_object_id BIGINT FK→storage_objects.id` going forward. The actual S3 path is never stored on module tables — modules dereference through `storage_objects`.

---

## 5. Audit events

`storage.put`, `storage.signed_url_issued`, `storage.head`, `storage.soft_delete`, `storage.restored`, `storage.legal_hold_changed`.

Every signed-URL issuance is logged with `actor_user_id`, `key`, `ttl`, `purpose` (free-text supplied by caller, e.g. `"resume_view"`). Critical for SOC2 / PII access reports.

---

## 6. Multi-tenancy + isolation

- The service ALWAYS prefixes `{module}/{tenant_id}/...` from arguments — callers cannot bypass.
- `list()` rejects prefixes that don't begin with `{module}/{tenant_id}/`.
- `get_signed_url()` cross-checks `s3_key` against `storage_objects` row's `tenant_id` and the active session's `tenant_id` before issuing.
- `master_admin` cross-tenant reads are allowed but every issuance is audit-logged with `cross_tenant=true`.

---

## 7. Lifecycle policies (S3 bucket-level, applied on bucket creation)

| Prefix pattern | Transition | Final disposition |
|---|---|---|
| `tax/*` | 90 days → IA → 365 days → Glacier Deep Archive | Object Lock 7 years |
| `payroll/*` | 90 days → IA → 365 days → Glacier Deep Archive | Object Lock 7 years |
| `time/*/signed/*` | 365 days → Glacier Deep Archive | Object Lock 7 years |
| `time/*/raw/*` | 90 days → IA | delete after 2 years (raw email attachments etc.) |
| `placements/*` | 365 days → IA | retain (no auto-delete) |
| `people/*` | 365 days → IA | retain (no auto-delete) |
| `accounting/*` | 90 days → IA → 365 days → Glacier | retain 10 years |

Tenant termination flow (Phase B): bucket prefix `tenants/_terminated/{tenant_id}/{date}/` — copy-on-terminate, then delete originals after legal hold period.

---

## 8. RBAC

`storage.read`, `storage.write`, `storage.delete`, `storage.legal_hold` — but in practice these are **proxied** through module permissions. Modules MUST check their own permission (e.g. `people.docs.view`) before calling `get_signed_url`. The StorageService trusts callers within the PHP process; it's an internal API, not a public one.

The `storage_objects` table records who actually called `put` / `get_signed_url`, so even buggy module code is audit-traceable.

---

## 9. Encryption

- **In transit**: TLS to S3, signed URLs over HTTPS only.
- **At rest**: SSE-KMS with platform CMK at MVP.
- **Application-level encryption** (separate from S3 encryption) is reserved for high-PII fields stored in MySQL (banking, SSN, EIN). The StorageService itself does NOT double-encrypt file payloads — KMS at the bucket is sufficient for files.

---

## 10. Configuration / secrets

`/app/.env` (NOT in repo):

```
STORAGE_DRIVER=s3
STORAGE_S3_BUCKET=coreflux-prod
STORAGE_S3_REGION=us-east-1
STORAGE_S3_ACCESS_KEY_ID=...
STORAGE_S3_SECRET_ACCESS_KEY=...
STORAGE_S3_KMS_KEY_ID=alias/coreflux-platform
STORAGE_SIGNED_URL_DEFAULT_TTL=300
```

Local dev: `STORAGE_DRIVER=local` swaps in a `LocalDriver` writing to `/app/storage/_dev/` with the same path convention. Same API, no S3 calls in tests.

---

## 11. Decisions locked (resolved in spec sign-off)

1. ✅ **PHP S3 SDK** — official `aws/aws-sdk-php` (full Amazon SDK). Best docs, best examples, no edge cases on KMS / presigned POST.
2. ✅ **Bucket layout** — single platform-wide bucket (e.g. `coreflux-prod`) with tenant isolation enforced via path prefixes (`{module}/{tenant_id}/...`). Per-tenant buckets deferred to a future enterprise tier.
3. ✅ **Anti-virus scanning** — deferred to Phase B. **Must be in place before any paying customer onboards** (SOC2 control). Wire-up plan: AWS Lambda + ClamAV on PutObject events; quarantine prefix → clean prefix on pass.
4. ✅ **Default region** — `us-east-1`. Per-tenant region routing deferred to enterprise tier.
5. ✅ **Direct browser uploads via presigned POST URLs** — included in MVP. PHP issues short-lived presigned POSTs; React uploads directly to S3; PHP confirms via a "finalize" endpoint that the object exists, then writes the `storage_objects` row.

---

## 12. MVP cut list

**Phase A:**
- `Core\StorageService` with `put`, `get_signed_url`, `head`, `soft_delete`
- `storage_objects` table + migrations
- S3 bucket setup script (Terraform or shell — TBD)
- Local driver for dev/test
- Audit events wired
- Module integration: People documents, Placements documents

**Phase B:**
- Lifecycle policies applied
- Object Lock + Legal Hold
- Direct presigned browser uploads
- AV scanning
- Per-tenant CMK (enterprise)

**Phase C:**
- Per-tenant region routing
- Cross-region replication for DR

---

*This SPEC is binding once signed off. Update before code changes.*
