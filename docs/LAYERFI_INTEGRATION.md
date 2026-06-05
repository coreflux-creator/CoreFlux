# LayerFi → CoreFlux integration playbook (surgical, no full merge)

Your GitHub `main` is the source of truth. The Emergent workspace is an **older
snapshot** of CoreFlux that *also* contains the new LayerFi integration. Do **not**
merge the whole Emergent branch into `main` — it would overwrite your newer
`modules/people/` code (and other files) with stubs.

Instead, take **only** the LayerFi files from the Emergent branch
(`conflict_040626_2242` — substitute the real name if different) and hand-apply a
handful of tiny wiring edits. Everything below runs on your local clone.

---

## 0. Get unstuck (if mid-merge)

```bash
git merge --abort          # cancels the stuck merge, returns to a clean main
git checkout main
git fetch origin
```

---

## 1. Pull ONLY the new LayerFi files from the conflict branch

These paths are brand-new (they don't exist in your repo), so this can't clobber
anything. Run from the repo root:

```bash
BR=origin/conflict_040626_2242     # <-- change if your branch name differs

git checkout "$BR" -- \
  core/integrations/layer \
  modules/accounting/api/layer_smoke_test.php \
  modules/accounting/api/layer_setup_tenant.php \
  modules/accounting/api/layer_business_token.php \
  modules/accounting/api/layer_status.php \
  modules/accounting/api/layer_client_error.php \
  modules/accounting/api/layer_audit_log.php \
  modules/accounting/api/layer_tenant_enablement.php \
  modules/accounting/ui/layer \
  modules/accounting/migrations/022_layer_sandbox.sql \
  modules/accounting/migrations/023_layer_tenant_enablement.sql
```

`git checkout <branch> -- <paths>` copies just those files into your working tree
without merging history. Nothing else moves.

> **Verify before adding:** `core/accounting/accounting_provider.php` may also be
> needed (provider-neutral adapter). Only pull it if your repo doesn't already
> have its own version:
> ```bash
> git cat-file -e HEAD:core/accounting/accounting_provider.php 2>/dev/null \
>   && echo "EXISTS on main — review before overwriting" \
>   || git checkout "$BR" -- core/accounting/accounting_provider.php
> ```

> **Migration numbering:** if your repo already uses `022_*.sql` / `023_*.sql`
> for something else, rename these two to the next free numbers (e.g.
> `024_layer_sandbox.sql`, `025_layer_tenant_enablement.sql`) before committing.

---

## 2. Three small wiring edits (hand-apply to YOUR versions)

### a) `dashboard/src/App.jsx`

Add the feature flag near the top (after the imports):

```js
// LayerFi sandbox embed — nav + routes only appear when the build sets
// VITE_ENABLE_LAYER_SANDBOX === 'true'. Default OFF keeps the native ledger
// as the sole accounting surface.
const LAYER_SANDBOX_ENABLED =
  typeof import.meta !== 'undefined' &&
  String(import.meta.env?.VITE_ENABLE_LAYER_SANDBOX) === 'true';
```

In the `accounting` module's `actions` array (right after the `Audit Log` item):

```js
        ...(LAYER_SANDBOX_ENABLED ? [
          { name: 'Layer Sandbox (Embed)', route: 'layer-sandbox' },
          { name: 'Layer Integration',    route: 'layer-integration' },
        ] : []),
```

### b) `modules/accounting/ui/AccountingModule.jsx`

> Note: `modules/accounting` is a **git subtree** (remote `accounting-module`).
> Editing the file in-place works now; push the change to the subtree repo later
> via `scripts/update-modules.sh` if you keep that workflow.

Add the import (top of file, with the other imports):

```js
import LayerSandboxModule from './layer/LayerSandboxModule';
```

Add the same flag:

```js
const LAYER_SANDBOX_ENABLED =
  typeof import.meta !== 'undefined' &&
  String(import.meta.env?.VITE_ENABLE_LAYER_SANDBOX) === 'true';
```

Inside the module's `<Routes>` block, add the two feature-flagged routes:

```jsx
        {LAYER_SANDBOX_ENABLED && (
          <Route path="layer-sandbox" element={<LayerSandboxModule session={session} view="sandbox" />} />
        )}
        {LAYER_SANDBOX_ENABLED && (
          <Route path="layer-integration" element={<LayerSandboxModule session={session} view="settings" />} />
        )}
```

### c) `dashboard/vite.config.js`

Install the package and add the alias so `modules/` files resolve it:

```bash
yarn --cwd dashboard add @layerfi/components
```

In `resolve.alias` add:

```js
      '@layerfi/components': path.resolve(__dirname, 'node_modules/@layerfi/components'),
```

---

## 3. Backend routing — nothing to wire

`api/index.php` → `core/api_router.php` resolves `/api/<module>/<endpoint>` to
`modules/<module>/api/<endpoint>.php` and converts kebab → snake
(`layer-smoke-test` → `layer_smoke_test.php`). Dropping the new `layer_*.php`
files into `modules/accounting/api/` is all that's required. The module-level
gate (`accounting.view`) already applies; the files enforce their own finer RBAC.

**RBAC permissions used (make sure these exist in your roles):**
- `accounting.view` — status / business-token / client-error / audit-log
- `coreflux.internal_sandbox` — smoke-test (internal admin only)
- `accounting.manage_integrations` — setup-tenant / tenant-enablement

---

## 4. Environment variables

**PHP backend host** (your server env or config):

```
ENABLE_LAYER_SANDBOX=true
LAYER_ENV=sandbox
LAYER_CLIENT_ID=<your sandbox client id>
LAYER_CLIENT_SECRET=<your sandbox client secret>
# optional:
LAYER_BUSINESS_TOKEN_TTL_SECONDS=3600
LAYER_TENANT_DEFAULT_ENABLED=false
LAYER_TENANT_ALLOWLIST=
LAYER_API_BASE_URL=...   LAYER_AUTH_URL=...   LAYER_OAUTH_SCOPE=...
```
When `ENABLE_LAYER_SANDBOX` is unset/false the endpoints 404 — safe default.

**Dashboard build** (must be set at `yarn build` time, not runtime):

```
VITE_ENABLE_LAYER_SANDBOX=true
```
Leave it OFF to ship the bundle with LayerFi hidden.

---

## 5. Migrations + build + deploy

```bash
php deploy/run_migrations.php          # creates tenant_layer_accounts,
                                       # integration_audit_log, tenant_layer_enablement
VITE_ENABLE_LAYER_SANDBOX=true yarn --cwd dashboard build   # rebuilds spa-assets/ (+sync_bundle.sh)
git add core/integrations/layer modules/accounting/api/layer_*.php \
        modules/accounting/ui/layer modules/accounting/migrations \
        dashboard/src/App.jsx modules/accounting/ui/AccountingModule.jsx \
        dashboard/vite.config.js spa-assets index.html .deploy-version
git commit -m "feat(accounting): embed LayerFi sandbox (flagged off by default)"
git push origin main
```

Your native ledger is untouched: LayerFi is additive and hidden unless both
`ENABLE_LAYER_SANDBOX` (backend) and `VITE_ENABLE_LAYER_SANDBOX` (build) are true.

---

## Smoke test after deploy

```bash
# as an internal admin session:
curl -s https://<host>/api/accounting/layer-smoke-test   # {"ok":true,"stub":false,...}
curl -s https://<host>/api/accounting/layer-status        # {"enabled":true,"allowed":...}
```
