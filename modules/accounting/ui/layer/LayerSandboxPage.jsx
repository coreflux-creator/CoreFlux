import React, { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { RefreshCw, Settings2, Loader2, ShieldAlert } from 'lucide-react';
import LayerEmbeddedAccountingPanel from './LayerEmbeddedAccountingPanel';

/**
 * LayerSandboxPage — requests a short-lived business token for the current
 * tenant and mounts the embedded LayerFi accounting UI. The token is held in
 * React state only.
 *
 * props:
 *   client = createLayerClient(...)
 *   tenant = { id, name }
 */
export default function LayerSandboxPage({ client, tenant, paths }) {
  const navigate = useNavigate();
  const P = { sandbox: '/accounting/layer-sandbox', settings: '/settings/integrations/layer', ...(paths || {}) };
  const [session, setSession] = useState(null);
  const [loading, setLoading] = useState(true);
  const [notConfigured, setNotConfigured] = useState(false);
  const [notAllowed, setNotAllowed] = useState(false);
  const [error, setError] = useState(null);

  const loadToken = useCallback(async () => {
    setLoading(true); setError(null); setNotConfigured(false); setNotAllowed(false); setSession(null);
    try {
      const r = await client.businessToken();
      setSession({
        businessId: r.businessId,
        businessAccessToken: r.businessAccessToken,
        environment: r.environment,
        stub: typeof r.businessAccessToken === 'string' && r.businessAccessToken.startsWith('stub-'),
      });
    } catch (e) {
      if (e.status === 404) setNotConfigured(true);
      else if (e.status === 403) setNotAllowed(true);
      else setError(e.message);
    } finally {
      setLoading(false);
    }
  }, [client]);

  useEffect(() => { loadToken(); }, [loadToken, tenant?.id]);

  return (
    <div className="layer-page" data-testid="layer-sandbox-page">
      <header className="layer-page__header">
        <div>
          <p className="layer-eyebrow">Accounting · Embedded</p>
          <h1>Layer Accounting Sandbox</h1>
          <p className="layer-sub">Embedded LayerFi accounting for <strong>{tenant?.name}</strong>.</p>
        </div>
        <div className="layer-page__actions">
          <button className="layer-btn layer-btn--ghost" onClick={loadToken} data-testid="layer-refresh-token-btn">
            <RefreshCw size={15} /> Refresh session
          </button>
          <button className="layer-btn layer-btn--ghost" onClick={() => navigate(P.settings)} data-testid="layer-goto-settings-btn">
            <Settings2 size={15} /> Settings
          </button>
        </div>
      </header>

      {loading && <div className="layer-embed-loading" data-testid="layer-token-loading"><Loader2 className="spin" size={16} /> Requesting business token…</div>}

      {!loading && notConfigured && (
        <div className="layer-empty" data-testid="layer-not-configured">
          <ShieldAlert size={26} />
          <h3>LayerFi is not configured for this tenant yet</h3>
          <p>An integration admin needs to create the LayerFi sandbox business first.</p>
          <button className="layer-btn layer-btn--primary" onClick={() => navigate(P.settings)} data-testid="layer-go-configure-btn">
            Go to integration settings
          </button>
        </div>
      )}

      {!loading && notAllowed && (
        <div className="layer-empty" data-testid="layer-not-allowed-sandbox">
          <ShieldAlert size={26} />
          <h3>LayerFi is not enabled for {tenant?.name}</h3>
          <p>This tenant is not on the LayerFi allowlist. It continues to use the native CoreFlux ledger.</p>
          <button className="layer-btn layer-btn--ghost" onClick={() => navigate(P.settings)} data-testid="layer-allowlist-settings-btn">
            View integration settings
          </button>
        </div>
      )}

      {!loading && error && <div className="layer-alert layer-alert--error" data-testid="layer-sandbox-error">{error}</div>}

      {!loading && session && (
        <LayerEmbeddedAccountingPanel session={session} onClientError={(p) => client.reportClientError(p)} />
      )}
    </div>
  );
}
