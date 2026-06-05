# CoreFlux × LayerFi Sandbox Embed — PRD / Build Log

## Original problem statement
"Build this embedded accounting sandbox test." Reference spec:
`CoreFlux_LayerFi_Sandbox_Embed_Developer_Spec.docx` — embed LayerFi's sandbox
accounting product into CoreFlux as a per-tenant embedded accounting provider,
behind a provider-neutral abstraction, with a smoke test, per-tenant business
provisioning, short-lived business tokens, the embedded `@layerfi/components`
UI, RBAC, tenant isolation, audit logging and a feature flag.

## User choices (from clarification)
- Integrate into the existing **CoreFlux** codebase (PHP + MySQL backend, Vite
  React SPA module system) — endpoints inside the **accounting** module.
- Real LayerFi keys NOT provided yet → wire real LayerFi HTTP calls with an
  in-process **sandbox stub** fallback that auto-activates until
  `LAYER_CLIENT_ID` / `LAYER_CLIENT_SECRET` are set.
- Frontend must work **standalone (for testing)** AND **as a component inside
  the live dashboard SPA**. Use the real `@layerfi/components` package.

## Environment reality
- CoreFlux is PHP (PDO/MySQL) + a Vite React SPA. The Emergent container had no
  PHP/MySQL. We installed PHP 8.2 + MariaDB locally and run CoreFlux via
  `php -S` behind a thin FastAPI gateway (`/app/backend/server.py`) so the
  Emergent ingress (`/api`→8001) reaches the PHP app. The standalone React
  sandbox is served on :3000 (`/app/frontend`).

## Architecture (what was built)
Backend (PHP, CoreFlux-native):
- `core/integrations/layer/layer_config.php` — env-driven config + feature flag + stub detection.
- `core/integrations/layer/layer_client.php` — platform OAuth (client_credentials, in-mem cache), whoami, create business, business auth-token. Stub fallbacks.
- `core/integrations/layer/layer_business_service.php` — create/resolve ONE LayerFi business per tenant (idempotent), maps `tenant_layer_accounts`.
- `core/integrations/layer/layer_token_service.php` — short-lived business token.
- `core/integrations/layer/layer_audit.php` — writes `integration_audit_log` + mirrors to `audit_log`, scrubs secrets.
- `core/accounting/accounting_provider.php` — provider-neutral adapter interface + LayerAdapter.
- Endpoints (accounting module): `layer_smoke_test.php`, `layer_setup_tenant.php`, `layer_business_token.php`, `layer_status.php`, `layer_client_error.php`
  → `/api/accounting/layer-{smoke-test,setup-tenant,business-token,status,client-error}`.
- Migration `modules/accounting/migrations/022_layer_sandbox.sql` — `tenant_layer_accounts`, `integration_audit_log`.

Frontend (shared, drops into both surfaces) under `modules/accounting/ui/layer/`:
- `layerClient.js`, `LayerErrorBoundary.jsx`, `LayerIntegrationStatusCard.jsx`,
  `LayerEmbeddedAccountingPanel.jsx` (real `@layerfi/components`),
  `LayerSandboxPage.jsx`, `LayerIntegrationSettingsPage.jsx`, `LayerSandboxModule.jsx` (dashboard wrapper).
- Standalone app: `/app/frontend` (Vite) with tenant + role switcher.
- Dashboard wiring: routes `layer-sandbox` / `layer-integration` added to `AccountingModule.jsx` (feature-flagged) + sidebar entries in `dashboard/src/App.jsx`.

## Security / spec adherence
- Tenant resolved server-side from the auth context (never trusts client tenant_id).
- Platform token + client secret never returned to the browser; business tokens never persisted; audit metadata scrubbed of secrets.
- RBAC: `accounting.view` (status/token/client-error), internal-admin `coreflux.internal_sandbox` (smoke-test), `accounting.manage_integrations` (setup). Feature flag `ENABLE_LAYER_SANDBOX` → 404 when off.

## Verified (curl + screenshots)
- Smoke test, status, setup (create + idempotent), business token, client-error, tenant isolation (2 distinct businesses), employee → 403, no-auth → 401, feature-flag off → 404, audit rows written, no token leakage. Embedded `@layerfi/components` mount in the standalone sandbox.

## LIVE MODE (real LayerFi sandbox keys configured)
- Real `LAYER_CLIENT_ID` / `LAYER_CLIENT_SECRET` are set in `/app/backend/.env`.
- Verified LIVE end-to-end against `sandbox.layerfi.com`:
  - OAuth client_credentials token + `/whoami` smoke test → `stub:false, ok:true`.
  - Real businesses created per tenant: tenant 1 `b1faf6e4-…`, tenant 2 `745c9127-…` (distinct → isolation), idempotent on re-run.
  - Real business token issued (expiresIn 3600).
  - Embedded `@layerfi/components` render LIVE data (default Chart of Accounts populated, Bank Transactions/Integrations live).
- SECURITY NOTE: the client secret now lives in `/app/backend/.env`. Keep it out of any public git push; rotate if exposed.

## Access gating (two layers, independent)
1. Global feature flag — `ENABLE_LAYER_SANDBOX` (backend → 404 when off) and
   `VITE_ENABLE_LAYER_SANDBOX` (frontend → routes/nav hidden, default OFF).
2. Per-tenant access — resolved in `core/integrations/layer/layer_access.php`:
   a. `LAYER_TENANT_ALLOWLIST` env → HARD lock/override (DB toggle ignored).
   b. `tenant_layer_enablement` DB row → **admin toggle, no restart needed**.
   c. `LAYER_TENANT_DEFAULT_ENABLED` → fallback for tenants with no row.
   Non-allowed tenants get 403 on setup/business-token, `allowed:false` on status.

### Admin toggle + audit UI (added 2026-06-05)
- `POST /api/accounting/layer-tenant-enablement` (internal admin) — flip a tenant
  on/off live; 409 when env-locked. Migration `023_layer_tenant_enablement.sql`.
- `GET /api/accounting/layer-audit-log` — tenant-scoped `integration_audit_log`.
- UI: a switch on the LayerFi settings page (`LayerIntegrationSettingsPage` +
  `LayerAuditTimeline.jsx`) toggles access and shows the live audit trail.
- Verified: enable→200, disable→403, employee→403, env-lock→409, UI toggle live.

### Dashboard SPA build (verified 2026-06-05)
- `dashboard/vite.config.js` aliases `@layerfi/components`; `yarn vite build`
  compiles cleanly (5774 modules) WITH LayerFi included. The committed
  `/app/spa-assets` live bundle was NOT mutated (deploy-time `yarn build` does
  the sync with the chosen flag).

### Repo hygiene (2026-06-05)
- Fixed a corrupted `.gitignore` (1,714 lines → 46) where a credential block was
  duplicated ~150× via a stray `echo -e` append. Sane version now ignores env/
  credentials, node_modules, `dist/`, vite/python caches, logs, vendor, backups.
- No merge conflict exists IN the Emergent workspace (tree clean, no markers,
  `.env` not tracked). Conflicts the user sees are in their external GitHub repo,
  caused by COMMITTED BUILD ARTIFACTS: `spa-assets/` (348 MB / 210 files),
  `app/assets/`, `dashboard/assets/`, `dashboard/dist/index.html`. Every rebuild
  changes hashed filenames + `index.html`, so merges conflict on generated files.
- Per user choice (1b): build artifacts LEFT TRACKED for now (deploy may serve the
  committed `spa-assets` bundle). Future fix to stop recurring conflicts: untrack
  them once deploy-time rebuild is confirmed.

## Backlog / Next actions
- P0: Add real LayerFi sandbox keys → re-run smoke test + confirm embedded data renders.
- P1: Build + ship the dashboard SPA bundle with the LayerFi routes (currently wired at code level).
- P1: Surface `integration_audit_log` in the existing Accounting Audit UI.
- P2: Add provider switch (QuickBooks/Xero) behind the same AccountingProvider adapter.
- P2: Token auto-refresh + per-tenant rate limiting on business-token issuance.
