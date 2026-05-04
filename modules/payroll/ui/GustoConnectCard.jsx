import React, { useEffect, useState } from 'react';
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

  const load = async () => {
    setState((s) => ({ ...s, loading: true }));
    try {
      const data = await api.get('/modules/payroll/api/gusto_connect.php');
      setState({ loading: false, ...data });
    } catch (e) { setErr(e.message); setState({ loading: false }); }
  };

  useEffect(() => {
    load();
    const hash = window.location.hash || '';
    const qIdx = hash.indexOf('?');
    if (qIdx >= 0) {
      const params = new URLSearchParams(hash.slice(qIdx + 1));
      if (params.has('gusto')) {
        setBounce({
          ok: params.get('gusto') === 'ok',
          reason: params.get('reason'),
          detail: params.get('detail'),
        });
      }
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
    if (!window.confirm('Disconnect Gusto? Existing payroll runs will keep their submission history. You can reconnect at any time.')) return;
    try { await api.delete('/modules/payroll/api/gusto_connect.php'); await load(); }
    catch (e) { setErr(e.message); }
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
            : `Gusto connect failed: ${bounce.reason || 'unknown'}${bounce.detail ? ` — ${bounce.detail}` : ''}`}
        </div>
      )}

      {!state.configured && (
        <p className="muted" data-testid="gusto-connect-not-configured">
          Gusto OAuth keys are not configured on this host. Add{' '}
          <code>GUSTO_CLIENT_ID</code>, <code>GUSTO_CLIENT_SECRET</code>, and{' '}
          <code>GUSTO_REDIRECT_URI</code> to <code>core/config.local.php</code> and re-deploy.
        </p>
      )}

      {state.configured && !state.connection && (
        <>
          <p>
            Connect Gusto to submit payroll runs over the API instead of the manual CSV-paste flow.
            Connecting opens a Gusto window where you sign in and authorize the {state.env} environment;
            you'll come back here automatically.
          </p>
          <p className="muted">
            <strong>Scopes:</strong> companies:read · employees:read · payrolls:read/write · pay_schedules:read · compensations:read · jobs:read.
            <br />
            <strong>Environment:</strong> <span data-testid="gusto-connect-env">{state.env}</span>
          </p>
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
              Last error: {state.connection.last_error}
            </p>
          )}
          <button
            type="button"
            className="btn btn--ghost"
            onClick={disconnect}
            data-testid="gusto-connect-disconnect-btn"
          >
            Disconnect
          </button>
        </div>
      )}

      {err && <p className="error" data-testid="gusto-connect-error">{err}</p>}
    </fieldset>
  );
}
