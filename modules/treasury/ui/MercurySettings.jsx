import React, { useState } from 'react';
import { useApi, api } from '../../../dashboard/src/lib/api';
import MercuryWebhookCard from './MercuryWebhookCard';

/**
 * Mercury connection + accounts panel — paste a tenant-owned Mercury API
 * token, probe via /accounts, persist encrypted. Mounted alongside the Plaid
 * Transfer settings under Treasury → Pay-out Rails.
 *
 * Slice 1 scope: connect / disconnect / list cached accounts. Recipients,
 * payments, and reconciliation arrive in Slices 2–4.
 */
export default function MercurySettings() {
  const status = useApi('/api/mercury_connection.php?action=status');
  const accounts = useApi('/api/mercury_accounts.php');

  const [token, setToken] = useState('');
  const [label, setLabel] = useState('');
  const [busy,  setBusy]  = useState(false);
  const [flash, setFlash] = useState(null);

  const connected = !!status.data?.connected;

  const handleConnect = async (e) => {
    e?.preventDefault?.();
    if (!token.trim()) {
      setFlash({ kind: 'error', msg: 'Paste your Mercury API token first.' });
      return;
    }
    setBusy(true);
    setFlash(null);
    try {
      const res = await api.post('/api/mercury_connection.php', {
        api_token: token.trim(),
        label:     label.trim() || null,
      });
      setFlash({
        kind: 'success',
        msg:  `Connected. Found ${res.accounts_count} account(s)${res.workspace_name ? ` in ${res.workspace_name}` : ''}.`,
      });
      setToken('');
      status.reload();
      accounts.reload();
    } catch (err) {
      setFlash({ kind: 'error', msg: err.message || String(err) });
    } finally {
      setBusy(false);
    }
  };

  const handleDisconnect = async () => {
    if (!window.confirm('Disconnect Mercury? Cached accounts and transactions remain but no new data will sync until you reconnect.')) return;
    setBusy(true);
    setFlash(null);
    try {
      await api.post('/api/mercury_connection.php?action=disconnect', {});
      setFlash({ kind: 'success', msg: 'Mercury disconnected.' });
      status.reload();
    } catch (err) {
      setFlash({ kind: 'error', msg: err.message || String(err) });
    } finally {
      setBusy(false);
    }
  };

  const handleSyncAccounts = async () => {
    setBusy(true);
    setFlash(null);
    try {
      const res = await api.post('/api/mercury_accounts.php?action=sync', {});
      setFlash({ kind: 'success', msg: `Synced ${res.count} account(s) from Mercury.` });
      accounts.reload();
    } catch (err) {
      setFlash({ kind: 'error', msg: err.message || String(err) });
    } finally {
      setBusy(false);
    }
  };

  return (
    <section data-testid="mercury-settings" style={{ maxWidth: 720, marginTop: 32 }}>
      <header style={{ marginBottom: 16 }}>
        <h3 style={{ margin: 0, fontSize: 18, fontWeight: 600 }}>Mercury Bank — Connection</h3>
        <p style={{ margin: '4px 0 0', fontSize: 13, color: 'var(--cf-text-secondary)' }}>
          Connect your tenant's Mercury workspace by pasting an API token from{' '}
          <a href="https://app.mercury.com/settings/tokens" target="_blank" rel="noopener noreferrer">
            Mercury → Settings → API Tokens
          </a>. CoreFlux stores the token AES-256-GCM encrypted and probes <code>/accounts</code> before saving.
        </p>
      </header>

      {flash && (
        <div
          data-testid={`mercury-flash-${flash.kind}`}
          style={{
            padding: '10px 14px', borderRadius: 6, marginBottom: 16,
            background: flash.kind === 'success' ? 'var(--cf-green-bg, #ecfdf5)' : 'var(--cf-red-bg, #fef2f2)',
            color:      flash.kind === 'success' ? 'var(--cf-green, #047857)'    : 'var(--cf-red, #b91c1c)',
            fontSize: 13,
          }}
        >
          {flash.msg}
        </div>
      )}

      {!connected && (
        <form
          data-testid="mercury-connect-form"
          onSubmit={handleConnect}
          className="card"
          style={{ padding: 16, border: '1px solid var(--cf-border, #e5e7eb)', borderRadius: 8 }}
        >
          <div style={{ marginBottom: 12 }}>
            <span
              className="badge"
              style={{ background: 'var(--cf-amber-bg, #fef3c7)', color: 'var(--cf-amber, #92400e)', padding: '2px 8px', borderRadius: 4, fontSize: 12 }}
            >
              Not connected
            </span>
          </div>
          <label style={{ display: 'block', marginBottom: 8, fontSize: 12, color: 'var(--cf-text-secondary)' }}>
            API token
            <input
              type="password"
              autoComplete="off"
              value={token}
              onChange={(e) => setToken(e.target.value)}
              placeholder="secret-token:..."
              className="input"
              data-testid="mercury-token-input"
              style={{ width: '100%', marginTop: 4 }}
              disabled={busy}
            />
          </label>
          <label style={{ display: 'block', marginBottom: 12, fontSize: 12, color: 'var(--cf-text-secondary)' }}>
            Label <span style={{ opacity: 0.6 }}>(optional)</span>
            <input
              type="text"
              value={label}
              onChange={(e) => setLabel(e.target.value)}
              placeholder="e.g., Ops Mercury (prod)"
              className="input"
              data-testid="mercury-label-input"
              style={{ width: '100%', marginTop: 4 }}
              disabled={busy}
            />
          </label>
          <button
            type="submit"
            className="btn btn--primary"
            disabled={busy}
            data-testid="mercury-connect-btn"
          >
            {busy ? 'Probing…' : 'Connect Mercury'}
          </button>
        </form>
      )}

      {connected && (
        <div
          data-testid="mercury-connected"
          className="card"
          style={{ padding: 16, border: '1px solid var(--cf-border, #e5e7eb)', borderRadius: 8 }}
        >
          <div style={{ marginBottom: 12 }}>
            <span className="badge badge--success" style={{ padding: '2px 8px', borderRadius: 4, fontSize: 12 }}>
              ✓ Connected
            </span>
          </div>
          <dl style={{ display: 'grid', gridTemplateColumns: 'max-content 1fr', gap: '6px 16px', margin: 0, fontSize: 13 }}>
            <dt style={{ color: 'var(--cf-text-secondary)' }}>Workspace</dt>
            <dd style={{ margin: 0 }} data-testid="mercury-workspace-name">{status.data?.workspace_name || '—'}</dd>
            <dt style={{ color: 'var(--cf-text-secondary)' }}>Label</dt>
            <dd style={{ margin: 0 }}>{status.data?.label || '—'}</dd>
            <dt style={{ color: 'var(--cf-text-secondary)' }}>Token (last 4)</dt>
            <dd style={{ margin: 0, fontFamily: 'var(--cf-mono, ui-monospace)' }} data-testid="mercury-token-last4">
              ••••{status.data?.api_token_last4 || '????'}
            </dd>
            <dt style={{ color: 'var(--cf-text-secondary)' }}>Last probe</dt>
            <dd style={{ margin: 0 }}>{status.data?.last_probe_at || '—'}</dd>
          </dl>
          {status.data?.last_probe_error && (
            <p style={{ color: 'var(--cf-red, #b91c1c)', fontSize: 12, marginTop: 8 }} data-testid="mercury-probe-error">
              Last error: {status.data.last_probe_error}
            </p>
          )}

          <div style={{ marginTop: 16, display: 'flex', gap: 8, flexWrap: 'wrap' }}>
            <button
              type="button"
              className="btn"
              onClick={handleSyncAccounts}
              disabled={busy}
              data-testid="mercury-sync-accounts-btn"
            >
              {busy ? 'Syncing…' : 'Refresh accounts'}
            </button>
            <button
              type="button"
              className="btn"
              onClick={handleDisconnect}
              disabled={busy}
              data-testid="mercury-disconnect-btn"
            >
              Disconnect
            </button>
          </div>
        </div>
      )}

      {connected && (
        <div data-testid="mercury-accounts-block" style={{ marginTop: 24 }}>
          <h4 style={{ margin: '0 0 8px', fontSize: 14, fontWeight: 600 }}>Cached accounts</h4>
          {accounts.loading && <p>Loading…</p>}
          {accounts.error && <p className="error">Error: {accounts.error.message}</p>}
          {!accounts.loading && !accounts.error && (accounts.data?.rows?.length ?? 0) === 0 && (
            <p data-testid="mercury-accounts-empty" style={{ fontSize: 13, color: 'var(--cf-text-secondary)' }}>
              No accounts cached yet. Click "Refresh accounts" to pull them from Mercury.
            </p>
          )}
          {(accounts.data?.rows?.length ?? 0) > 0 && (
            <table className="data-table" data-testid="mercury-accounts-table" style={{ width: '100%', fontSize: 13 }}>
              <thead>
                <tr>
                  <th>Nickname</th>
                  <th>Kind</th>
                  <th>Acct ••</th>
                  <th>Routing</th>
                  <th style={{ textAlign: 'right' }}>Available</th>
                  <th style={{ textAlign: 'right' }}>Current</th>
                  <th>Status</th>
                  <th>Last sync</th>
                </tr>
              </thead>
              <tbody>
                {accounts.data.rows.map((a) => (
                  <tr key={a.id} data-testid={`mercury-account-row-${a.id}`}>
                    <td>{a.nickname || '—'}</td>
                    <td>{a.kind || '—'}</td>
                    <td style={{ fontFamily: 'var(--cf-mono, ui-monospace)' }}>••{a.account_number_last4 || '????'}</td>
                    <td style={{ fontFamily: 'var(--cf-mono, ui-monospace)' }}>{a.routing_number || '—'}</td>
                    <td style={{ textAlign: 'right' }}>
                      {a.available_balance_cents != null ? `$${(a.available_balance_cents / 100).toFixed(2)}` : '—'}
                    </td>
                    <td style={{ textAlign: 'right' }}>
                      {a.current_balance_cents != null ? `$${(a.current_balance_cents / 100).toFixed(2)}` : '—'}
                    </td>
                    <td>{a.status || '—'}</td>
                    <td style={{ fontSize: 11, color: 'var(--cf-text-secondary)' }}>{a.last_synced_at || '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      )}

      {connected && <MercuryWebhookCard />}
    </section>
  );
}
