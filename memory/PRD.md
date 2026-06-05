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

## Backlog / Next actions
- P0: Add real LayerFi sandbox keys → re-run smoke test + confirm embedded data renders.
- P1: Build + ship the dashboard SPA bundle with the LayerFi routes (currently wired at code level).
- P1: Surface `integration_audit_log` in the existing Accounting Audit UI.
- P2: Add provider switch (QuickBooks/Xero) behind the same AccountingProvider adapter.
- P2: Token auto-refresh + per-tenant rate limiting on business-token issuance.
