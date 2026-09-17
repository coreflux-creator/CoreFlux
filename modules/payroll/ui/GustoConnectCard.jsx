import React, { useEffect, useState } from 'react';
import { Settings2, Unplug, X } from 'lucide-react';
import { Link } from 'react-router-dom';
import { api } from '../../../dashboard/src/lib/api';

/**
 * Gusto Connect card — embedded in PayrollSettings.
 *
 * Three states:
 *   1. Not configured on this host → muted message (no button)
 *   2. Configured + not connected   → "Connect Gusto" button (top-level redirect)
 *   3. Connected                    → company name + status pill + Disconnect
 *
 * Reads ?gusto=ok|err&reason=... from the OAuth callback bounce so we can
 * show a one-shot success/error message right after connecting.
 */
export default function GustoConnectCard() {
  const [state, setState] = useState({ loading: true });
  const [err, setErr] = useState(null);
  const [bounce, setBounce] = useState(null);
  const [showManual, setShowManual] = useState(false);
  const [manualForm, setManualForm] = useState({
    company_uuid: '', company_name: '', access_token: '', refresh_token: '',
  });
  const [manualBusy, setManualBusy] = useState(false);
  const [confirmDisconnect, setConfirmDisconnect] = useState(false);

  const load = async () => {
    setState((s) => ({ ...s, loading: true }));
    try {
      const data = await api.get('/modules/payroll/api/gusto_connect.php');
      setState({ loading: false, ...data });
    } catch { setErr('Could not load the Gusto connection. Try again.'); setState({ loading: false }); }
  };

  useEffect(() => {
    load();
    const params = new URLSearchParams(window.location.search);
    if (params.has('gusto')) {
      setBounce({
        ok: params.get('gusto') === 'ok',
        reason: params.get('reason'),
        detail: params.get('detail'),
      });

      ['gusto', 'reason', 'detail', 'connection_id'].forEach((key) => params.delete(key));
      const query = params.toString();
      window.history.replaceState(
        window.history.state,
        '',
        `${window.location.pathname}${query ? `?${query}` : ''}${window.location.hash}`,
      );
    }
  }, []);

  const connect = () => {
    window.location.href = '/api/gusto_oauth_start.php';
  };

  const submitManual = async () => {
    setManualBusy(true); setErr(null);
    try {
      await api.post('/modules/payroll/api/gusto_connect.php', manualForm);
      setShowManual(false);
      setManualForm({ company_uuid: '', company_name: '', access_token: '', refresh_token: '' });
      await load();
    } catch (e2) { setErr(e2.message); } finally { setManualBusy(false); }
  };

  const disconnect = async () => {
    try {
      await api.delete('/modules/payroll/api/gusto_connect.php');
      setConfirmDisconnect(false);
      await load();
    } catch {
      setErr('Could not disconnect Gusto. Try again.');
    }
  };

  if (state.loading) {
    return <fieldset data-testid="gusto-connect-card"><legend>Gusto integration</legend><p className="muted">Loading…</p></fieldset>;
  }

  return (
    <fieldset data-testid="gusto-connect-card">
      <legend>Gusto integration</legend>

      {bounce && (
        <div
          className={`alert ${bounce.ok ? 'alert--ok' : 'alert--err'}`}
          data-testid={bounce.ok ? 'gusto-connect-bounce-ok' : 'gusto-connect-bounce-err'}
          style={{ marginBottom: 12 }}
        >
          {bounce.ok
            ? 'Gusto connected successfully.'
            : "We couldn't connect Gusto. Try again; if it keeps happening, ask a workspace administrator to review the connection."}
        </div>
      )}

      {!state.configured && (
        <div className="operational-state" data-testid="gusto-connect-not-configured" style={{ padding: 12 }}>
          <strong>Gusto isn't enabled for this workspace yet.</strong>
          <p className="muted" style={{ margin: '6px 0 0' }}>
            A workspace administrator can finish the connection setup. You can still run payroll in CoreFlux and export the results.
          </p>
          <Link className="btn btn--ghost btn--sm" to="/admin/integrations" style={{ marginTop: 10 }}>
            <Settings2 size={14} aria-hidden="true" /> Open Connections
          </Link>
        </div>
      )}

      {state.configured && !state.connection && (
        <>
          <p>
            Connect Gusto to keep employee setup and payroll information in sync. You'll sign in with Gusto and return here automatically.
          </p>
          {state.env === 'sandbox' && <p className="muted" data-testid="gusto-connect-env">Sandbox connection</p>}
          <button
            type="button"
            className="btn btn--primary"
            onClick={connect}
            data-testid="gusto-connect-btn"
          >
            Connect Gusto ({state.env})
          </button>

          {state.env === 'sandbox' && (
            <div style={{ marginTop: 16, paddingTop: 12, borderTop: '1px dashed var(--cf-border, #cbd5e1)' }}>
              <button
                type="button"
                className="btn btn--ghost"
                onClick={() => setShowManual((v) => !v)}
                data-testid="gusto-connect-manual-toggle"
              >
                {showManual ? 'Cancel' : 'Or paste demo tokens manually (sandbox)'}
              </button>
              {showManual && (
                <div
                  data-testid="gusto-connect-manual-form"
                  style={{ display: 'flex', flexDirection: 'column', gap: 8, marginTop: 12 }}
                >
                  <p className="muted" style={{ fontSize: 12, margin: 0 }}>
                    From the Gusto Developer Portal → <em>Demo Partner Managed Companies</em> → click
                    <em> Show Tokens</em> on a demo company, then paste the values here. This skips OAuth
                    for sandbox testing.
                  </p>
                  <label>
                    <span>Company UUID</span>
                    <input
                      required type="text"
                      value={manualForm.company_uuid}
                      onChange={(e) => setManualForm({ ...manualForm, company_uuid: e.target.value })}
                      placeholder="4b448395-fd3f-45e8-bf40-d9b6b3747737"
                      data-testid="gusto-connect-manual-company-uuid"
                    />
                  </label>
                  <label>
                    <span>Company name (optional)</span>
                    <input
                      type="text"
                      value={manualForm.company_name}
                      onChange={(e) => setManualForm({ ...manualForm, company_name: e.target.value })}
                      placeholder="Thunderhawk Technology Partners LLC"
                      data-testid="gusto-connect-manual-company-name"
                    />
                  </label>
                  <label>
                    <span>Access token</span>
                    <input
                      required type="text"
                      value={manualForm.access_token}
                      onChange={(e) => setManualForm({ ...manualForm, access_token: e.target.value })}
                      data-testid="gusto-connect-manual-access-token"
                      autoComplete="off" spellCheck={false}
                    />
                  </label>
                  <label>
                    <span>Refresh token</span>
                    <input
                      required type="text"
                      value={manualForm.refresh_token}
                      onChange={(e) => setManualForm({ ...manualForm, refresh_token: e.target.value })}
                      data-testid="gusto-connect-manual-refresh-token"
                      autoComplete="off" spellCheck={false}
                    />
                  </label>
                  <button
                    type="button"
                    className="btn btn--primary"
                    onClick={submitManual}
                    disabled={manualBusy || !manualForm.company_uuid || !manualForm.access_token || !manualForm.refresh_token}
                    data-testid="gusto-connect-manual-submit"
                    style={{ alignSelf: 'flex-start' }}
                  >
                    {manualBusy ? 'Saving…' : 'Save sandbox connection'}
                  </button>
                </div>
              )}
            </div>
          )}
        </>
      )}

      {state.configured && state.connection && (
        <div data-testid="gusto-connect-connected">
          <p style={{ marginTop: 0 }}>
            <strong data-testid="gusto-connect-company-name">
              {state.connection.company_name || `Gusto company ${state.connection.company_uuid.slice(0, 8)}…`}
            </strong>
            {' · '}
            <span className="badge badge--active" data-testid="gusto-connect-status">
              {state.connection.status} ({state.connection.env})
            </span>
          </p>
          <p className="muted" data-testid="gusto-connect-meta">
            Connected {state.connection.connected_at}
            {state.connection.last_used_at ? ` · last used ${state.connection.last_used_at}` : ''}
            {state.connection.last_refreshed_at ? ` · last refreshed ${state.connection.last_refreshed_at}` : ''}
          </p>
          {state.connection.last_error && (
            <p className="error" data-testid="gusto-connect-last-error">
              Gusto needs attention. Try reconnecting; if the problem continues, ask a workspace administrator to review the connection.
            </p>
          )}
          {!confirmDisconnect ? (
            <button
              type="button"
              className="btn btn--ghost"
              onClick={() => setConfirmDisconnect(true)}
              data-testid="gusto-connect-disconnect-btn"
            >
              <Unplug size={15} aria-hidden="true" /> Disconnect
            </button>
          ) : (
            <div className="operational-state" data-testid="gusto-connect-disconnect-confirm" style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap', padding: 10 }}>
              <span style={{ fontSize: 13 }}>Disconnect Gusto? Existing payroll runs will keep their history.</span>
              <button type="button" className="btn btn--danger btn--sm" onClick={disconnect}>
                <Unplug size={14} aria-hidden="true" /> Disconnect Gusto
              </button>
              <button type="button" className="btn btn--ghost btn--sm" onClick={() => setConfirmDisconnect(false)} title="Cancel">
                <X size={14} aria-hidden="true" />
              </button>
            </div>
          )}
          <GustoTrackBSyncPanel />
        </div>
      )}

      {err && <p className="error" data-testid="gusto-connect-error">{err}</p>}
    </fieldset>
  );
}

function GustoTrackBSyncPanel() {
  const [busy, setBusy] = React.useState(null);
  const [result, setResult] = React.useState(null);
  const [err, setErr] = React.useState(null);

  const run = async (action, label) => {
    setBusy(action); setErr(null); setResult(null);
    try {
      const data = await api.post(`/modules/payroll/api/gusto_sync.php?action=${action}`, {});
      setResult({ label, data });
    } catch (e) {
      setErr(e.message);
    } finally {
      setBusy(null);
    }
  };

  return (
    <div data-testid="gusto-track-b-panel" style={{ marginTop: 16, paddingTop: 16, borderTop: '1px solid var(--cf-border)' }}>
      <h4 style={{ marginTop: 0 }}>Gusto synchronization</h4>
      <p className="muted" style={{ fontSize: 13 }}>
        Keep employees, schedules, compensation, and payroll status aligned with Gusto.
        Re-running a sync updates existing records instead of creating duplicates.
      </p>
      <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8 }}>
        <button onClick={() => run('employees', 'Employees')} disabled={!!busy}
                className="btn btn--ghost" data-testid="gusto-sync-employees-btn">
          {busy === 'employees' ? 'Syncing…' : 'Sync employees'}
        </button>
        <button onClick={() => run('pay_schedules', 'Pay schedules')} disabled={!!busy}
                className="btn btn--ghost" data-testid="gusto-sync-pay-schedules-btn">
          {busy === 'pay_schedules' ? 'Syncing…' : 'Sync pay schedules'}
        </button>
        <button onClick={() => run('compensations', 'Compensations')} disabled={!!busy}
                className="btn btn--ghost" data-testid="gusto-sync-compensations-btn">
          {busy === 'compensations' ? 'Syncing…' : 'Sync compensations'}
        </button>
        <button onClick={() => run('webhook_subscribe', 'Webhook subscription')} disabled={!!busy}
                className="btn btn--ghost" data-testid="gusto-sync-webhook-btn">
          {busy === 'webhook_subscribe' ? 'Setting up…' : 'Set up status updates'}
        </button>
        <button onClick={() => run('all', 'Full sync')} disabled={!!busy}
                className="btn btn--primary" data-testid="gusto-sync-all-btn">
          {busy === 'all' ? 'Syncing all data…' : 'Sync all payroll data'}
        </button>
      </div>
      {err && <p className="error" data-testid="gusto-track-b-error">{err}</p>}
      {result && (
        <div className="alert alert--ok" data-testid="gusto-track-b-result" style={{ marginTop: 12 }}>
          <strong>{result.label} completed.</strong>
          {result.data?.message ? ` ${result.data.message}` : ''}
        </div>
      )}
    </div>
  );
}

