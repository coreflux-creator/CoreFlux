# CoreFlux Module Skeleton

This is the canonical layout every CoreFlux module follows. Copy `/modules/_template/`
to `/modules/<your_module>/` and work from there.

> ⚠️ **Before adding any AI feature, read [`AI_INTEGRATION_RULES.md`](./AI_INTEGRATION_RULES.md).**
> The hard rule is: AI produces advisory narrative for humans, never values the
> app computes with. All LLM calls go through `aiAsk()`; all LLM output renders
> via `<AISuggestion />`.

## Folder layout

```
/modules/<module>/
├── manifest.php              # Module identity, sidebar actions, permissions
├── api/                      # PHP API endpoints (one file per resource)
│   └── <resource>.php
├── migrations/               # SQL schema migrations (run manually on DB for now)
│   └── 001_init.sql
└── ui/                       # React views for the SPA
    └── <ModuleName>Module.jsx
```

## The four platform primitives

All modules build on these — **never roll your own auth, tenant scoping, fetch logic, or LLM calls.**

| Concern          | Primitive                                     | File                             |
|------------------|-----------------------------------------------|----------------------------------|
| API bootstrap    | `api_require_auth`, `api_ok`, `api_error`     | `core/api_bootstrap.php`         |
| Tenant scoping   | `scopedQuery`, `scopedInsert`, `scopedUpdate` | `core/tenant_scope.php`          |
| SPA fetch client | `api.get`, `api.post`, `useApi`               | `dashboard/src/lib/api.js`       |
| AI (backend)     | `aiAsk()` — the only LLM entry point          | `core/ai_service.php`            |
| AI (frontend)    | `<AISuggestion />` — the only LLM render path | `dashboard/src/components/AISuggestion.jsx` |
| Module manifest  | returned array                                | `modules/<m>/manifest.php`       |

## Rules of the road

1. **Every API endpoint starts with:**
   ```php
   require_once __DIR__ . '/../../../core/api_bootstrap.php';
   $ctx = api_require_auth();
   ```
2. **Never hand-roll tenant filters.** Use `scopedQuery` / `scopedInsert` /
   `scopedUpdate` / `scopedDelete`. They inject `tenant_id` from the session so
   a missing filter cannot leak cross-tenant data.
3. **Every module table has `tenant_id` (NOT NULL)** and is indexed by it.
4. **All SPA calls go through `api`** from `dashboard/src/lib/api.js`. That
   gives you same-origin credentials, JSON handling, and a uniform error shape.
5. **Mount your module's routes under `/modules/<id>/*`** in `dashboard/src/App.jsx`
   (until dynamic manifest-based registration lands).
6. **Icons** live at `/assets/icons/icon-<id>.png`. Name them to match `manifest.id`.
7. **Migrations are append-only.** Use numbered files (`001_init.sql`, `002_add_x.sql`).
   The runner is not built yet — run them manually on Cloudways for now.

## Creating a new module (copy/paste checklist)

```bash
# 1. Scaffold from the template
cp -r modules/_template modules/payroll

# 2. Rename identifiers in the template files
#    - manifest.php → id/name/description
#    - ui/TemplateModule.jsx → PayrollModule
#    - migrations/001_init.sql → rename table prefixes (payroll_*)

# 3. Add the icon
cp assets/icons/icon-template.png assets/icons/icon-payroll.png
#    then replace with the real artwork

# 4. Register in core/modules.php (temporary — until manifest auto-loader ships)
#    Add 'payroll' entry to getModuleDefinitions().

# 5. Wire the React route in dashboard/src/App.jsx
#    <Route path="/modules/payroll/*" element={<PayrollModule session={session} />} />

# 6. Run the migration on the target DB
#    mysql ... < modules/payroll/migrations/001_init.sql
```

## Anti-patterns to avoid

- ❌ Direct `$pdo->query("SELECT * FROM x WHERE tenant_id = $id")` — use `scopedQuery`
- ❌ `fetch('/modules/.../api/x.php')` in a component — use the `api` client
- ❌ Putting business logic in `core/` — core stays platform-only
- ❌ Foreign keys across modules — depend on core tables only (`users`, `tenants`)
- ❌ Hardcoding tenant ids in SQL — always pull from session via the helpers
- ❌ Calling the AI sidecar directly or parsing LLM output for values — use `aiAsk()` + `<AISuggestion />` (see [`AI_INTEGRATION_RULES.md`](./AI_INTEGRATION_RULES.md))

## What's intentionally not here yet

These are tracked but deferred to keep the MVP moving:
- Manifest auto-discovery (core still reads `core/modules.php` as the source of truth)
- Migration runner (run SQL files manually for now)
- Permission enforcement (`can($perm)`) — manifests declare permissions but
  nothing validates them yet
- Dynamic `React.lazy()` module loading from session data

Each of these gets retrofitted after the first module (Payroll) lands and
validates the shape.
