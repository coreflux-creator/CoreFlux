import React, { useEffect, useState, useCallback } from 'react';
import { api } from '../lib/api';

/**
 * <JazIntegrationSettings /> — operator-facing config for the
 * provider-neutral accounting backend (Slice 1). Per spec §24,
 * surfaces:
 *
 *   • Entity (sub-tenant) picker — accounting backend is per-entity
 *   • Connect / disconnect / rotate-key
 *   • Credential status (last4 only, never the plaintext)
 *   • Scope summary (permissions, shadow user) — once Phase 0 lands
 *   • Last validation + error
 *   • A loud "not_implemented_yet" banner while Jaz partner diligence
 *     is pending, so operators understand reads will be empty.
 *
 * The page is provider-aware via the ?provider= query param (defaults
 * to 'jaz') — the same component will swap behind a provider tab when
 * QBO/Xero adapters land.
 */
export default function JazIntegrationSettings() {
  const [subTenants, setSubTenants] = useState([]);
  const [subTenantId, setSubTenantId] = useState(null);
  const [connection, setConnection] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [flash, setFlash] = useState(null);
  const [apiKey, setApiKey] = useState('');
  const [orgId, setOrgId] = useState('');
  const [baseCurrency, setBaseCurrency] = useState('USD');
  const [busy, setBusy] = useState(false);

  // Load sub-tenants (legal entities). Falls back to current tenant
  // when none exist so first-time tenants aren't blocked from connecting.
  useEffect(() => {
    let mounted = true;
    api.get('/api/sub_tenants.php')
      .then(r => {
        if (!mounted) return;
        // sub_tenants endpoint returns the list directly OR wrapped in {rows} / {sub_tenants}
        const rows = Array.isArray(r) ? r : (r.rows || r.sub_tenants || r.tenants || []);
        setSubTenants(rows);
        if (rows.length > 0) setSubTenantId(rows[0].id);
      })
      .catch(() => mounted && setSubTenants([]));
    return () => { mounted = false; };
  }, []);

  const reload = useCallback(async () => {
    if (!subTenantId) { setLoading(false); return; }
    setLoading(true); setError(null);
    try {
      const r = await api.get(`/api/accounting.php?action=status&sub_tenant_id=${subTenantId}&provider=jaz`);
      setConnection(r.connection || null);
      if (r.connection?.provider_org_id) setOrgId(r.connection.provider_org_id);
      if (r.connection?.base_currency)   setBaseCurrency(r.connection.base_currency);
    } catch (e) { setError(e.message || 'Failed to load'); }
    finally { setLoading(false); }
  }, [subTenantId]);
  useEffect(() => { reload(); }, [reload]);

  const handleConnect = async (e) => {
    e.preventDefault();
    if (!subTenantId) return setError('Pick a legal entity first.');
    if (apiKey.length < 16) return setError('API key must be at least 16 characters.');
    setBusy(true); setError(null);
    try {
      await api.post('/api/accounting.php?action=connect&provider=jaz', {
        sub_tenant_id: subTenantId,
        api_key: apiKey,
        provider_org_id: orgId || undefined,
        base_currency: baseCurrency,
      });
      setApiKey('');
      setFlash({ kind: 'success', msg: 'Credentials stored. Click Validate to probe the connection.' });
      await reload();
    } catch (e) { setError(e.message || 'Connect failed'); }
    finally { setBusy(false); }
  };

  const handleValidate = async () => {
    setBusy(true); setError(null);
    try {
      const r = await api.post('/api/accounting.php?action=validate&provider=jaz', {
        sub_tenant_id: subTenantId,
      });
      setFlash({
        kind: r.ok ? 'success' : 'error',
        msg: r.ok
          ? `Validation status: ${r.status}`
          : `Validation failed: ${r.connection?.last_validation_error || 'unknown'}`,
      });
      await reload();
    } catch (e) { setError(e.message || 'Validate failed'); }
    finally { setBusy(false); }
  };

  const handleDisconnect = async () => {
    if (!confirm('Disconnect Jaz for this entity? Credentials will be wiped.')) return;
    setBusy(true); setError(null);
    try {
      await api.post('/api/accounting.php?action=disconnect&provider=jaz', { sub_tenant_id: subTenantId });
      setFlash({ kind: 'success', msg: 'Disconnected. You can re-connect any time.' });
      await reload();
    } catch (e) { setError(e.message || 'Disconnect failed'); }
    finally { setBusy(false); }
  };

  // Slice 2 — Phase 1 live wiring now ships. Reads + writes hit
  // Jaz directly; the "partner diligence pending" banner only shows
  // when validate explicitly reports the legacy not_implemented_yet
  // marker (kept for forward-compat with future not-yet-wired methods).
  const notReady = connection?.api_scope_summary?.not_implemented_yet === true;

  return (
    <section data-testid="jaz-integration-settings" style={{ padding: 'var(--cf-space-3, 1rem)' }}>
      <header style={{ marginBottom: 16 }}>
        <h2 style={{ margin: 0, fontSize: 20, fontWeight: 600 }}>Jaz.ai — Accounting backend</h2>
        <p style={{ margin: '4px 0 0', fontSize: 13, color: '#64748b' }}>
          When enabled per legal entity, Jaz becomes CoreFlux's accounting backend
          (chart of accounts, GL, trial balance, posting). CoreFlux still owns the UI,
          workflows, approvals, and audit trail.
        </p>
      </header>

      {error && <div className="error" data-testid="jaz-error" style={{ marginBottom: 12 }}>{error}</div>}
      {flash && (
        <div data-testid={`jaz-flash-${flash.kind}`}
             style={{
               padding: '8px 12px', borderRadius: 6, marginBottom: 12,
               background: flash.kind === 'success' ? '#ecfdf5' : '#fef2f2',
               color:      flash.kind === 'success' ? '#047857' : '#b91c1c',
               fontSize: 13,
             }}>{flash.msg}</div>
      )}

      <fieldset style={fieldsetStyle}>
        <legend style={legendStyle}>Step 1 — Legal entity</legend>
        <p style={{ margin: '0 0 8px', fontSize: 12, color: '#64748b' }}>
          Connect Jaz <strong>per entity</strong>. Each sub-tenant gets its own Jaz
          organisation + API key so the books stay separate.
        </p>
        {subTenants.length === 0 ? (
          <p style={{ fontSize: 12, color: '#92400e' }} data-testid="jaz-no-entities">
            No sub-tenants exist yet. Provision a legal entity in Admin → Sub-tenants first.
          </p>
        ) : (
          <select data-testid="jaz-entity-select"
                  value={subTenantId || ''}
                  onChange={(e) => setSubTenantId(parseInt(e.target.value, 10) || null)}
                  className="input" style={{ minWidth: 260 }}>
            {subTenants.map(st => (
              <option key={st.id} value={st.id}>{st.name || st.subdomain || `Entity ${st.id}`}</option>
            ))}
          </select>
        )}
      </fieldset>

      {!loading && subTenantId && (
        <>
          <fieldset style={fieldsetStyle}>
            <legend style={legendStyle}>Step 2 — Credentials</legend>
            {connection ? (
              <div style={statusCardStyle} data-testid="jaz-current-status">
                <div style={{ fontSize: 14, fontWeight: 600 }}>
                  Status: <code data-testid="jaz-connection-status">{connection.connection_status}</code>
                  {connection.credential_last4 && (
                    <span style={{ marginLeft: 12, color: '#64748b', fontWeight: 400 }}>
                      Key ends with <code>…{connection.credential_last4}</code>
                    </span>
                  )}
                </div>
                {connection.provider_org_id && (
                  <div style={{ fontSize: 12, color: '#64748b', marginTop: 4 }}>
                    Jaz org: <code>{connection.provider_org_id}</code>
                  </div>
                )}
                {connection.last_validated_at && (
                  <div style={{ fontSize: 12, color: '#64748b', marginTop: 4 }}>
                    Last validated: {connection.last_validated_at}
                  </div>
                )}
                {connection.last_validation_error && (
                  <div style={{ fontSize: 12, color: '#b91c1c', marginTop: 4 }}>
                    Last error: {connection.last_validation_error}
                  </div>
                )}
                <div style={{ marginTop: 12, display: 'flex', gap: 8 }}>
                  <button type="button" className="btn"
                          onClick={handleValidate} disabled={busy}
                          data-testid="jaz-validate-btn">
                    {busy ? 'Validating…' : 'Validate'}
                  </button>
                  <button type="button" className="btn btn--ghost"
                          onClick={handleDisconnect} disabled={busy}
                          data-testid="jaz-disconnect-btn">
                    Disconnect
                  </button>
                </div>
              </div>
            ) : (
              <p style={{ fontSize: 13, color: '#64748b', marginBottom: 8 }}
                 data-testid="jaz-not-connected">
                Not connected yet for this entity. Paste a Jaz API key below to begin.
              </p>
            )}

            <form onSubmit={handleConnect}
                  style={{ marginTop: 12, display: 'flex', flexDirection: 'column', gap: 8 }}>
              <label style={{ fontSize: 12, fontWeight: 600 }}>
                Jaz API key {connection && '(paste new to rotate)'}
                <input type="password" className="input"
                       value={apiKey}
                       onChange={(e) => setApiKey(e.target.value)}
                       placeholder="jaz_…"
                       data-testid="jaz-api-key-input"
                       style={{ display: 'block', width: '100%', marginTop: 4,
                                fontFamily: 'ui-monospace, monospace' }} />
              </label>
              <div style={{ display: 'flex', gap: 12 }}>
                <label style={{ flex: 1, fontSize: 12, fontWeight: 600 }}>
                  Jaz organisation id (optional)
                  <input className="input" value={orgId}
                         onChange={(e) => setOrgId(e.target.value)}
                         placeholder="org_…"
                         data-testid="jaz-org-input"
                         style={{ display: 'block', width: '100%', marginTop: 4 }} />
                </label>
                <label style={{ width: 120, fontSize: 12, fontWeight: 600 }}>
                  Base currency
                  <input className="input" value={baseCurrency}
                         maxLength={3}
                         onChange={(e) => setBaseCurrency(e.target.value.toUpperCase())}
                         data-testid="jaz-currency-input"
                         style={{ display: 'block', width: '100%', marginTop: 4 }} />
                </label>
              </div>
              <button type="submit" className="btn btn--primary"
                      disabled={busy || apiKey.length < 16}
                      data-testid="jaz-connect-btn"
                      style={{ alignSelf: 'flex-start' }}>
                {busy ? 'Saving…' : connection ? 'Rotate key' : 'Connect Jaz'}
              </button>
            </form>
          </fieldset>

          {notReady && connection && (
            <fieldset data-testid="jaz-diligence-banner"
                      style={{ ...fieldsetStyle,
                               background: '#fffbeb',
                               border: '1px solid #fcd34d' }}>
              <legend style={{ ...legendStyle, color: '#b45309' }}>Partner diligence pending</legend>
              <p style={{ margin: 0, fontSize: 13, color: '#78350f' }}>
                Jaz endpoint-level API contracts are gated behind partner diligence
                (spec §2). Connections persist + the adapter contract is wired
                end-to-end, but live reads will return <code>not_implemented_yet</code>
                placeholders and writes will fail-safe to the outbox dead-letter queue
                until the contract is published. The moment that lands, only
                <code> core/accounting/jaz_adapter.php</code> changes.
              </p>
            </fieldset>
          )}
        </>
      )}
    </section>
  );
}

const fieldsetStyle = {
  border: '1px solid #e2e8f0', borderRadius: 8,
  padding: '12px 16px 16px', marginBottom: 16,
};
const legendStyle = {
  fontSize: 11, fontWeight: 700, color: '#475569',
  textTransform: 'uppercase', letterSpacing: 0.4,
  padding: '0 6px',
};
const statusCardStyle = {
  padding: 12, background: '#f8fafc',
  border: '1px solid #e2e8f0', borderRadius: 6,
};
