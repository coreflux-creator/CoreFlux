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

  const load = async () => {
    setState((s) => ({ ...s, loading: true }));
    try {
      const data = await api.get('/modules/payroll/api/gusto_connect.php');
      setState({ loading: false, ...data });
    } catch (e) { setErr(e.message); setState({ loading: false }); }
  };

  useEffect(() => {
    load();
    // Parse bounce params from OAuth callback redirect (uses hash routing).
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
    // Top-level navigation so we leave the SPA and come back via the callback.
    window.location.href = '/api/gusto_oauth_start.php';
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
