# CoreFlux Product Requirements Document

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
