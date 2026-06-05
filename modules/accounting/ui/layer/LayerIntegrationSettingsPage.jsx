import React, { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Activity, PlugZap, ArrowRight, Loader2 } from 'lucide-react';
import LayerIntegrationStatusCard from './LayerIntegrationStatusCard';

/**
 * LayerIntegrationSettingsPage — admin surface to set up LayerFi for the
 * current tenant: status, smoke test, and "Create LayerFi sandbox business".
 *
 * props:
 *   client    = createLayerClient(...)
 *   tenant    = { id, name }
 *   canManage = boolean   (gates the setup + smoke-test actions in the UI)
 */
export default function LayerIntegrationSettingsPage({ client, tenant, canManage = true, paths }) {
  const navigate = useNavigate();
  const P = { sandbox: '/accounting/layer-sandbox', settings: '/settings/integrations/layer', ...(paths || {}) };
  const [status, setStatus] = useState(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState('');
  const [smoke, setSmoke] = useState(null);
  const [toast, setToast] = useState(null);
  const [error, setError] = useState(null);

  const loadStatus = useCallback(async () => {
    setLoading(true); setError(null);
    try { setStatus(await client.status()); }
    catch (e) { setError(e.message); }
    finally { setLoading(false); }
  }, [client]);

  useEffect(() => { setSmoke(null); setToast(null); loadStatus(); }, [loadStatus, tenant?.id]);

  const runSmoke = async () => {
    setBusy('smoke'); setSmoke(null); setError(null);
    try { setSmoke({ ok: true, ...(await client.smokeTest()) }); }
    catch (e) { setSmoke({ ok: false, message: e.message }); }
    finally { setBusy(''); }
  };

  const runSetup = async () => {
    setBusy('setup'); setError(null);
    try {
      const r = await client.setupTenant({ legalName: `${tenant?.name || 'Tenant'} LLC`, usState: 'NC', entityType: 'LLC' });
      setToast(r.created ? 'Created a new LayerFi sandbox business for this tenant.' : 'Tenant already mapped — returned existing business.');
      await loadStatus();
    } catch (e) { setError(e.message); }
    finally { setBusy(''); }
  };

  return (
    <div className="layer-page" data-testid="layer-settings-page">
      <header className="layer-page__header">
        <div>
          <p className="layer-eyebrow">Settings · Integrations</p>
          <h1>LayerFi Sandbox Integration</h1>
          <p className="layer-sub">
            Per-tenant embedded accounting evaluation for <strong>{tenant?.name}</strong>. This is an evaluation
            sandbox only — not the permanent CoreFlux accounting engine.
          </p>
        </div>
      </header>

      {error && <div className="layer-alert layer-alert--error" data-testid="layer-settings-error">{error}</div>}
      {toast && <div className="layer-alert layer-alert--ok" data-testid="layer-settings-toast">{toast}</div>}

      <div className="layer-cols">
        <div className="layer-col">
          <h2 className="layer-section-title">Status</h2>
          {loading ? <div className="layer-embed-loading">Loading status…</div> : <LayerIntegrationStatusCard status={status} />}
        </div>

        <div className="layer-col">
          <h2 className="layer-section-title">Actions</h2>
          <div className="layer-actions">
            <button className="layer-btn layer-btn--ghost" onClick={runSmoke} disabled={!canManage || busy === 'smoke'} data-testid="layer-smoke-test-btn">
              {busy === 'smoke' ? <Loader2 className="spin" size={16} /> : <Activity size={16} />} Run smoke test
            </button>
            <button className="layer-btn layer-btn--primary" onClick={runSetup} disabled={!canManage || busy === 'setup'} data-testid="layer-setup-btn">
              {busy === 'setup' ? <Loader2 className="spin" size={16} /> : <PlugZap size={16} />}
              {status?.configured ? 'Re-resolve LayerFi business' : 'Create LayerFi sandbox business'}
            </button>
            <button className="layer-btn layer-btn--accent" onClick={() => navigate(P.sandbox)} disabled={!status?.configured} data-testid="layer-open-sandbox-btn">
              Open Layer sandbox <ArrowRight size={16} />
            </button>
          </div>

          {smoke && (
            <div className={`layer-smoke-result ${smoke.ok ? 'is-ok' : 'is-err'}`} data-testid="layer-smoke-result">
              {smoke.ok
                ? <>Connectivity OK · provider <b>{smoke.provider}</b> · env <b>{smoke.environment}</b>{smoke.stub ? ' · stub' : ''}</>
                : <>Smoke test failed: {smoke.message}</>}
            </div>
          )}
          {!canManage && <p className="layer-hint" data-testid="layer-manage-hint">You need integration-admin rights to run these actions.</p>}
        </div>
      </div>
    </div>
  );
}
