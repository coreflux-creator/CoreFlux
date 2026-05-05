import React, { useState } from 'react';
import { Power, RefreshCw, Building2 } from 'lucide-react';
import { api, useApi } from '../lib/api';
import { Card } from '../components/UIComponents';

/**
 * ModuleAccessAdmin — toggles `tenant_modules.is_enabled` per tenant.
 * Replaces the static `ModulesPage` mock that lived in AdminModule.jsx.
 *
 * `master_admin` can switch the dropdown to any tenant. `tenant_admin`
 * is locked to their active tenant and its sub-tenants.
 */
export default function ModuleAccessAdmin({ session }) {
  const isMaster = session?.user?.global_role === 'master_admin';
  const [tenantId, setTenantId] = useState(session?.tenant_id || null);

  // Tenant picker source — master_admin gets every tenant, otherwise membership list.
  const tenantsApi = useApi(isMaster ? '/api/tenants.php' : null);
  const tenantOptions = isMaster
    ? (tenantsApi.data?.tenants || []).map(t => ({ id: t.id, name: t.name }))
    : (session?.tenants || []).map(t => ({ id: t.id, name: t.name }));

  const url = tenantId ? `/api/tenant_modules.php?tenant_id=${tenantId}` : null;
  const { data, loading, error, reload } = useApi(url);
  const [busyKey, setBusyKey] = useState(null);

  const onToggle = async (mod) => {
    setBusyKey(mod.module_key);
    try {
      await api.patch(`/api/tenant_modules.php?tenant_id=${tenantId}`, {
        module_key: mod.module_key,
        is_enabled: !mod.is_enabled,
      });
      reload();
    } catch (e) { alert(e.message || 'Toggle failed'); }
    finally { setBusyKey(null); }
  };

  return (
    <div data-testid="module-access-admin">
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 'var(--cf-space-6)' }}>
        <div>
          <h1 style={{ fontSize: 'var(--cf-text-2xl)', fontWeight: 700, marginBottom: 4 }}>Module access</h1>
          <p style={{ color: 'var(--cf-text-secondary)', fontSize: 14 }}>
            Toggle which apps each tenant can see. Disabled modules disappear from the sidebar
            on the next session refresh.
          </p>
        </div>
        <button className="btn btn--ghost" onClick={reload} data-testid="modules-refresh">
          <RefreshCw size={16} /> Refresh
        </button>
      </div>

      <Card style={{ marginBottom: 16 }}>
        <label style={{ display: 'flex', gap: 12, alignItems: 'center' }}>
          <Building2 size={16} />
          <span style={{ fontWeight: 500 }}>Tenant:</span>
          <select className="input" value={tenantId || ''}
                  onChange={(e) => setTenantId(parseInt(e.target.value, 10) || null)}
                  data-testid="modules-tenant-picker"
                  style={{ minWidth: 240 }}>
            <option value="">— Select a tenant —</option>
            {tenantOptions.map(t => (
              <option key={t.id} value={t.id}>{t.name}</option>
            ))}
          </select>
        </label>
      </Card>

      {!tenantId && <Card><p>Pick a tenant to view its module subscriptions.</p></Card>}
      {tenantId && loading && <Card><p>Loading…</p></Card>}
      {tenantId && error && <Card><p style={{ color: '#b91c1c' }}>{error.message || String(error)}</p></Card>}

      {tenantId && data?.modules && (
        <Card>
          <table className="data-table" data-testid="modules-table">
            <thead>
              <tr>
                <th style={{ width: '20%' }}>Module</th>
                <th>Description</th>
                <th style={{ width: 130, textAlign: 'center' }}>Status</th>
                <th style={{ width: 120, textAlign: 'right' }}>Action</th>
              </tr>
            </thead>
            <tbody>
              {data.modules.map(m => (
                <tr key={m.module_key} data-testid={`modules-row-${m.module_key}`}>
                  <td style={{ fontWeight: 500 }}>{m.name}</td>
                  <td style={{ color: 'var(--cf-text-secondary)' }}>{m.description}</td>
                  <td style={{ textAlign: 'center' }}>
                    <span className={`badge badge--${m.is_enabled ? 'success' : 'muted'}`}
                          data-testid={`modules-status-${m.module_key}`}>
                      {m.is_enabled ? 'Enabled' : 'Disabled'}
                    </span>
                  </td>
                  <td style={{ textAlign: 'right' }}>
                    <button className={`btn btn--${m.is_enabled ? 'ghost' : 'primary'}`}
                            onClick={() => onToggle(m)}
                            disabled={busyKey === m.module_key}
                            data-testid={`modules-toggle-${m.module_key}`}>
                      <Power size={14} /> {m.is_enabled ? 'Disable' : 'Enable'}
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          <p style={{ marginTop: 12, fontSize: 12, color: 'var(--cf-text-secondary)' }}>
            Tip: tenants with no rows yet are treated as "all modules on" so a freshly
            provisioned tenant works without you needing to walk every toggle.
          </p>
        </Card>
      )}
    </div>
  );
}
