import React, { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Power, Loader2, ArrowRight, AlertCircle } from 'lucide-react';
import { createLayerClient } from '../../../modules/accounting/ui/layer/layerClient';

/**
 * LayerFiToggleCard — admin-overview shortcut to the per-tenant
 * LayerFi enablement toggle. Reads `GET /api/accounting/layer-status`
 * and flips via `POST /api/accounting/layer-tenant-enablement` —
 * exactly the same wire as the full Settings page, just one click
 * deep instead of two.
 *
 * Falls back gracefully when:
 *   - the operator lacks `coreflux.internal_sandbox` → Power button
 *     disabled, deep link to full settings still rendered.
 *   - the API is unreachable → error pane + retry button.
 */
export default function LayerFiToggleCard() {
  const [status, setStatus] = useState(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const [toast, setToast] = useState(null);

  // Single shared client — module-scoped so two mounts share one fetch
  // in the rare case the card appears in more than one place.
  const client = React.useMemo(() => createLayerClient({}), []);

  const load = useCallback(async () => {
    setLoading(true); setError(null);
    try { setStatus(await client.status()); }
    catch (e) { setError(e.message || 'Failed to load LayerFi status'); }
    finally { setLoading(false); }
  }, [client]);

  useEffect(() => { load(); }, [load]);

  const gov = status?.governance;
  const canToggle = !!status?.canToggle;
  const toggleOn  = gov ? (gov.dbEnabled ?? gov.effective) : !!status?.allowed;

  const onToggle = async () => {
    setBusy(true); setError(null); setToast(null);
    try {
      const next = !toggleOn;
      await client.setTenantEnabled(next);
      setToast(next ? 'LayerFi enabled for this tenant.' : 'LayerFi disabled for this tenant.');
      await load();
    } catch (e) {
      setError(e.data?.error || e.message || 'Toggle failed');
    } finally {
      setBusy(false);
    }
  };

  return (
    <section
      data-testid="layerfi-toggle-card"
      style={{
        background: 'var(--cf-surface, white)',
        border: '1px solid var(--cf-border, #e5e7eb)',
        borderRadius: 8,
        padding: 16,
        display: 'flex', flexDirection: 'column', gap: 10,
      }}
    >
      <header style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
        <h3 style={{ margin: 0, fontSize: 14, flex: 1 }}>LayerFi sandbox — per-tenant toggle</h3>
        {!loading && (
          <span
            data-testid={`layerfi-toggle-state-${toggleOn ? 'on' : 'off'}`}
            style={{
              padding: '2px 8px', borderRadius: 999, fontSize: 11, fontWeight: 600,
              background: toggleOn ? '#dcfce7' : '#e5e7eb',
              color:      toggleOn ? '#166534' : '#374151',
            }}
          >{toggleOn ? 'enabled' : 'disabled'}</span>
        )}
      </header>

      {loading && (
        <p data-testid="layerfi-toggle-loading" style={{ fontSize: 12, color: 'var(--cf-text-secondary, #6b7280)', margin: 0 }}>
          <Loader2 size={12} className="animate-spin" style={{ verticalAlign: 'middle' }} /> Loading status…
        </p>
      )}

      {error && (
        <p
          data-testid="layerfi-toggle-error"
          className="error"
          style={{ display: 'flex', alignItems: 'center', gap: 6, margin: 0 }}
        >
          <AlertCircle size={14} /> {error}
        </p>
      )}

      {toast && (
        <p
          data-testid="layerfi-toggle-toast"
          style={{ fontSize: 12, color: '#166534', margin: 0 }}
        >{toast}</p>
      )}

      {!loading && !error && (
        <p style={{ fontSize: 12, color: 'var(--cf-text-secondary, #6b7280)', margin: 0 }}>
          Environment: <code>{gov?.environment || 'sandbox'}</code>
          {gov?.dbEnabled !== undefined && (
            <> · DB row: <code>{String(gov.dbEnabled)}</code></>
          )}
          {!canToggle && status?.allowed === false && (
            <> · <span style={{ color: '#b45309' }}>read-only (missing permission)</span></>
          )}
        </p>
      )}

      <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginTop: 4 }}>
        <button
          type="button"
          onClick={onToggle}
          disabled={loading || busy || !canToggle}
          data-testid="layerfi-toggle-button"
          style={{
            display: 'inline-flex', alignItems: 'center', gap: 6,
            padding: '6px 14px', fontSize: 12, fontWeight: 600,
            borderRadius: 6, border: '1px solid var(--cf-border, #e5e7eb)',
            background: toggleOn ? '#fee2e2' : '#dcfce7',
            color:      toggleOn ? '#991b1b' : '#166534',
            cursor: (loading || busy || !canToggle) ? 'not-allowed' : 'pointer',
            opacity: (loading || busy || !canToggle) ? 0.6 : 1,
          }}
        >
          {busy ? <Loader2 size={12} className="animate-spin" /> : <Power size={12} />}
          {toggleOn ? 'Disable' : 'Enable'}
        </button>

        <Link
          to="/settings/integrations/layer"
          data-testid="layerfi-toggle-deep-link"
          style={{
            display: 'inline-flex', alignItems: 'center', gap: 4,
            fontSize: 12, color: 'var(--cf-text-secondary, #6b7280)',
            textDecoration: 'underline', marginLeft: 'auto',
          }}
        >
          Open full settings <ArrowRight size={12} />
        </Link>
      </div>
    </section>
  );
}
