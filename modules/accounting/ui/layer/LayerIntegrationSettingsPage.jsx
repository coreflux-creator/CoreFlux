import React, { useCallback, useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Activity, PlugZap, ArrowRight, Loader2, Power, Lock } from 'lucide-react';
import LayerIntegrationStatusCard from './LayerIntegrationStatusCard';
import LayerAuditTimeline from './LayerAuditTimeline';

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
  const auditRef = useRef(null);
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

  const refreshAudit = () => { try { auditRef.current && auditRef.current.reload(); } catch (e) { /* noop */ } };

  const runSmoke = async () => {
    setBusy('smoke'); setSmoke(null); setError(null);
    try { setSmoke({ ok: true, ...(await client.smokeTest()) }); }
    catch (e) { setSmoke({ ok: false, message: e.message }); }
    finally { setBusy(''); refreshAudit(); }
  };

  const runSetup = async () => {
    setBusy('setup'); setError(null);
    try {
      const r = await client.setupTenant({ legalName: `${tenant?.name || 'Tenant'} LLC`, usState: 'NC', entityType: 'LLC' });
      setToast(r.created ? 'Created a new LayerFi sandbox business for this tenant.' : 'Tenant already mapped — returned existing business.');
      await loadStatus();
    } catch (e) { setError(e.message); }
    finally { setBusy(''); refreshAudit(); }
  };

  const gov = status?.governance;
  const canToggle = !!status?.canToggle;
  const toggleOn = gov ? (gov.dbEnabled ?? gov.effective) : !!status?.allowed;

  const onToggle = async () => {
    setBusy('toggle'); setError(null); setToast(null);
    try {
      const next = !toggleOn;
      await client.setTenantEnabled(next);
      setToast(next ? `LayerFi enabled for ${tenant?.name}.` : `LayerFi disabled for ${tenant?.name}.`);
      await loadStatus();
    } catch (e) { setError(e.data?.error || e.message); }
    finally { setBusy(''); refreshAudit(); }
  };

  const notAllowed = status?.allowed === false;

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
      {status && status.allowed === false && (
        <div className="layer-alert layer-alert--error" data-testid="layer-not-allowed">
          LayerFi is enabled globally, but it is currently <strong>off for {tenant?.name}</strong> — this tenant
          stays on the native ledger.{' '}
          {gov?.envLocked
            ? <>Access is locked by the <code>LAYER_TENANT_ALLOWLIST</code> env override.</>
            : canToggle
              ? <>Flip the switch below to turn it on.</>
              : <>An internal admin can enable it from this page.</>}
        </div>
      )}

      <div className="layer-cols">
        <div className="layer-col">
          <h2 className="layer-section-title">Status</h2>
          {loading ? <div className="layer-embed-loading">Loading status…</div> : <LayerIntegrationStatusCard status={status} />}
        </div>

        <div className="layer-col">
          {(canToggle || gov?.envLocked) && (
            <div className="layer-access-card" data-testid="layer-access-toggle-card">
              <div className="layer-access-card__text">
                <h3>LayerFi for this tenant</h3>
                <p data-testid="layer-toggle-state">
                  {gov?.envLocked
                    ? 'Locked by env allowlist.'
                    : (toggleOn ? 'Enabled — embedded accounting available.' : 'Disabled — using native ledger.')}
                </p>
              </div>
              {gov?.envLocked ? (
                <span className="layer-lock" data-testid="layer-toggle-locked"><Lock size={14} /> env</span>
              ) : (
                <button
                  className={`layer-toggle ${toggleOn ? 'is-on' : ''}`}
                  onClick={onToggle}
                  disabled={busy === 'toggle' || !canToggle}
                  role="switch"
                  aria-checked={toggleOn}
                  data-testid="layer-tenant-toggle"
                >
                  <span className="layer-toggle__knob">
                    {busy === 'toggle' ? <Loader2 className="spin" size={12} /> : <Power size={12} />}
                  </span>
                </button>
              )}
            </div>
          )}

          <h2 className="layer-section-title">Actions</h2>
          <div className="layer-actions">
            <button className="layer-btn layer-btn--ghost" onClick={runSmoke} disabled={!canManage || notAllowed || busy === 'smoke'} data-testid="layer-smoke-test-btn">
              {busy === 'smoke' ? <Loader2 className="spin" size={16} /> : <Activity size={16} />} Run smoke test
            </button>
            <button className="layer-btn layer-btn--primary" onClick={runSetup} disabled={!canManage || notAllowed || busy === 'setup'} data-testid="layer-setup-btn">
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

      <div className="layer-cols-full">
        <LayerAuditTimeline ref={auditRef} client={client} />
      </div>
    </div>
  );
}
