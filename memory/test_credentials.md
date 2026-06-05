# Test Credentials & Access — CoreFlux LayerFi Sandbox

## App URLs
- Preview: https://64f26f5e-ae8a-4fdd-8d69-21dbdfae4220.preview.emergentagent.com
- Standalone UI routes: `/settings/integrations/layer`, `/accounting/layer-sandbox`
- Backend API base (same origin): `/api/...` (FastAPI gateway → PHP CoreFlux)

## Auth model (sandbox harness)
There is no login UI in the standalone evaluation. Short-lived JWTs are minted
by the gateway for two demo tenants and three roles:

GET `/api/dev/token?tenant_id=<1|2>&role=<master_admin|tenant_admin|employee>`
→ `{ token, user, tenant }`. Pass as `Authorization: Bearer <token>`.

### Demo tenants
- tenant_id 1 → "Acme Corp (Sandbox)"
- tenant_id 2 → "Beta Industries (Sandbox)"

### Demo users / roles
- master_admin → admin@coreflux.demo  (internal admin: can smoke-test + setup)
- tenant_admin → tadmin@coreflux.demo (tenant admin: can setup + view)
- employee     → viewer@coreflux.demo (no `accounting.view` → 403 on layer endpoints)

In the standalone UI use the **Tenant** and **Acting as** switchers (top bar,
data-testid `tenant-switcher` / `role-switcher`).

## Database (local MariaDB in this container)
- socket: /var/run/mysqld/mysqld.sock
- db: grcudkpvcd  user: grcudkpvcd  pass: 7DgX7F4RPz  (from core/config.php)

## LayerFi credentials
- STUB MODE (no real keys). To go live, set in `/app/backend/.env`:
  `LAYER_CLIENT_ID=...` and `LAYER_CLIENT_SECRET=...` then `sudo supervisorctl restart backend`.
