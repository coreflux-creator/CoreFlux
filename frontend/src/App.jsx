import React, { useEffect, useMemo, useState } from 'react';
import { BrowserRouter, Routes, Route, NavLink, Navigate } from 'react-router-dom';
import { Layers, PlugZap, Building2, ShieldCheck } from 'lucide-react';
import { createLayerClient } from '../../modules/accounting/ui/layer/layerClient';
import LayerIntegrationSettingsPage from '../../modules/accounting/ui/layer/LayerIntegrationSettingsPage';
import LayerSandboxPage from '../../modules/accounting/ui/layer/LayerSandboxPage';

const ENABLED = String(import.meta.env.VITE_ENABLE_LAYER_SANDBOX) === 'true';

const TENANTS = [
  { id: 1, name: 'Acme Corp (Sandbox)' },
  { id: 2, name: 'Beta Industries (Sandbox)' },
];
const ROLES = [
  { key: 'master_admin', label: 'Internal Admin' },
  { key: 'tenant_admin', label: 'Tenant Admin' },
  { key: 'employee', label: 'View-only (no accounting.view)' },
];

export default function App() {
  const [tenantId, setTenantId] = useState(1);
  const [role, setRole] = useState('master_admin');
  const [token, setToken] = useState(null);
  const [authError, setAuthError] = useState(null);

  const tenant = TENANTS.find((t) => t.id === tenantId);
  const canManage = role === 'master_admin' || role === 'tenant_admin';

  useEffect(() => {
    let on = true;
    setToken(null);
    setAuthError(null);
    (async () => {
      try {
        const res = await fetch(`/api/dev/token?tenant_id=${tenantId}&role=${role}`);
        const d = await res.json();
        if (!res.ok) throw new Error(d.error || 'auth failed');
        if (on) setToken(d.token);
      } catch (e) {
        if (on) setAuthError(e.message);
      }
    })();
    return () => { on = false; };
  }, [tenantId, role]);

  const client = useMemo(
    () => createLayerClient({ baseUrl: '', getAuthHeaders: () => (token ? { Authorization: `Bearer ${token}` } : {}) }),
    [token]
  );

  if (!ENABLED) {
    return (
      <div className="layer-disabled" data-testid="layer-feature-disabled">
        <Layers size={28} />
        <h2>LayerFi sandbox is disabled</h2>
        <p>Set <code>VITE_ENABLE_LAYER_SANDBOX=true</code> to enable this evaluation module.</p>
      </div>
    );
  }

  return (
    <BrowserRouter>
      <div className="cf-shell">
        <aside className="cf-side">
          <div className="cf-brand">
            <span className="cf-brand__mark"><Layers size={18} /></span>
            <div>
              <strong>CoreFlux</strong>
              <span>LayerFi Sandbox</span>
            </div>
          </div>
          <nav className="cf-nav">
            <NavLink to="/settings/integrations/layer" className="cf-nav__link" data-testid="nav-settings">
              <PlugZap size={16} /> Integration Settings
            </NavLink>
            <NavLink to="/accounting/layer-sandbox" className="cf-nav__link" data-testid="nav-sandbox">
              <Building2 size={16} /> Layer Sandbox
            </NavLink>
          </nav>
          <div className="cf-side__foot">
            <div className="cf-pill"><ShieldCheck size={13} /> evaluation only</div>
            <p>Embedded accounting per-tenant via the real <code>@layerfi/components</code>.</p>
          </div>
        </aside>

        <div className="cf-main">
          <header className="cf-top">
            <div className="cf-switch">
              <label className="cf-field">
                <span>Tenant</span>
                <select
                  value={tenantId}
                  onChange={(e) => setTenantId(Number(e.target.value))}
                  data-testid="tenant-switcher"
                >
                  {TENANTS.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                </select>
              </label>
              <label className="cf-field">
                <span>Acting as</span>
                <select value={role} onChange={(e) => setRole(e.target.value)} data-testid="role-switcher">
                  {ROLES.map((r) => <option key={r.key} value={r.key}>{r.label}</option>)}
                </select>
              </label>
            </div>
            <div className="cf-top__right">
              <span className={`cf-auth ${token ? 'is-ok' : authError ? 'is-err' : ''}`} data-testid="auth-indicator">
                {token ? 'session ready' : authError ? 'auth error' : 'authenticating…'}
              </span>
            </div>
          </header>

          <main className="cf-content">
            {!token && !authError && <div className="layer-embed-loading">Authenticating demo session…</div>}
            {authError && <div className="layer-alert layer-alert--error" data-testid="app-auth-error">Auth error: {authError}</div>}
            {token && (
              <Routes>
                <Route path="/settings/integrations/layer" element={<LayerIntegrationSettingsPage client={client} tenant={tenant} canManage={canManage} />} />
                <Route path="/accounting/layer-sandbox" element={<LayerSandboxPage client={client} tenant={tenant} />} />
                <Route path="*" element={<Navigate to="/settings/integrations/layer" replace />} />
              </Routes>
            )}
          </main>
        </div>
      </div>
    </BrowserRouter>
  );
}
