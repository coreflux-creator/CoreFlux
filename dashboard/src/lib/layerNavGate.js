/**
 * LayerFi nav gating — strips the Layer Sandbox / Layer Integration nav
 * entries from the Accounting module unless the active user has the role
 * that maps to `coreflux.internal_sandbox` / `accounting.manage_integrations`.
 *
 * Backend endpoints (`/api/accounting/layer_*.php`) already enforce these
 * permissions server-side; this helper is purely a UX hygiene layer so the
 * sidebar doesn't dangle dead links for personas that will hit a 403.
 *
 * Role → permission mapping mirrors `core/rbac_config.php`:
 *   - master_admin  → '*'           (sees both)
 *   - tenant_admin  → '*'           (sees both)
 *   - admin         → 'accounting.*' (sees Layer Integration, NOT Sandbox)
 *   - everyone else → none           (sees neither)
 *
 * The Layer Sandbox toggle is platform-internal — only master/tenant admin
 * can flip a tenant in/out of the sandbox. Layer Integration management
 * (business token, audit log) lives under `accounting.manage_integrations`
 * which the admin role also holds.
 */

const SANDBOX_ROLES = new Set(['master_admin', 'tenant_admin']);
const INTEGRATION_ROLES = new Set(['master_admin', 'tenant_admin', 'admin']);

/** True when the user can see the Layer Sandbox embed nav entry. */
export function canSeeLayerSandbox(user) {
  if (!user || typeof user !== 'object') return false;
  const global = String(user.global_role || '');
  const role = String(user.role || '');
  return SANDBOX_ROLES.has(global) || SANDBOX_ROLES.has(role);
}

/** True when the user can see the Layer Integration management nav entry. */
export function canSeeLayerIntegration(user) {
  if (!user || typeof user !== 'object') return false;
  const global = String(user.global_role || '');
  const role = String(user.role || '');
  return INTEGRATION_ROLES.has(global) || INTEGRATION_ROLES.has(role);
}

/**
 * Returns a new modules list with the Layer nav entries filtered down to
 * what the active user can actually see. Non-accounting modules are passed
 * through untouched. Safe to call with an empty/missing user — returns a
 * fully-stripped list in that case.
 */
export function filterLayerNav(modules, user) {
  if (!Array.isArray(modules)) return modules;
  const showSandbox = canSeeLayerSandbox(user);
  const showIntegration = canSeeLayerIntegration(user);
  if (showSandbox && showIntegration) return modules;

  return modules.map((mod) => {
    if (!mod || mod.id !== 'accounting' || !Array.isArray(mod.actions)) return mod;
    const actions = mod.actions.filter((a) => {
      if (!a || typeof a.route !== 'string') return true;
      if (a.route === 'layer-sandbox') return showSandbox;
      if (a.route === 'layer-integration') return showIntegration;
      return true;
    });
    return { ...mod, actions };
  });
}
