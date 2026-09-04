import React, { useState } from 'react';
import { api, useApi } from '../../../dashboard/src/lib/api';

export default function PurePaySettings() {
  const status = useApi('/api/purepay_connection.php?action=status');
  const [apiKey, setApiKey] = useState('');
  const [label, setLabel] = useState('');
  const [secret, setSecret] = useState('');
  const [busy, setBusy] = useState('');
  const [message, setMessage] = useState(null);

  const run = async (kind, fn) => {
    setBusy(kind); setMessage(null);
    try { await fn(); setMessage({ kind: 'success', text: kind === 'connect' ? 'Pure//Pay connected.' : kind === 'secret' ? 'Webhook secret saved.' : kind === 'disconnect' ? 'Pure//Pay disconnected.' : 'Connection probe passed.' }); await status.reload(); }
    catch (e) { setMessage({ kind: 'error', text: e.message || String(e) }); }
    finally { setBusy(''); }
  };
  const data = status.data || {};
  const money = data.wallet_balance_cents == null ? 'Not reported' : new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(data.wallet_balance_cents / 100);

  if (status.loading) return <p data-testid="purepay-settings-loading">Loading Pure//Pay…</p>;
  return (
    <section data-testid="purepay-settings" style={{ maxWidth: 820 }}>
      <header style={{ marginBottom: 18 }}>
        <p style={{ margin: 0, fontSize: 12, color: 'var(--cf-text-secondary)' }}>Admin · Integrations · Payment rails</p>
        <h2 style={{ margin: '4px 0' }}>Pure//Pay</h2>
        <p style={{ color: 'var(--cf-text-secondary)', fontSize: 13 }}>
          Release approved AP bills through the Pure//Pay wallet and receive signed settlement/failure events.
          Vendor bank onboarding remains in Pure//Pay; CoreFlux sends vendor identity, bill, and amount only.
        </p>
      </header>

      {message && <p data-testid={`purepay-message-${message.kind}`} className={message.kind === 'error' ? 'error' : 'success'}>{message.text}</p>}
      {status.error && <p className="error">{status.error.message}</p>}

      <div style={card}>
        <div style={{ display: 'flex', justifyContent: 'space-between', gap: 12 }}>
          <div><strong>API connection</strong><p style={hint}>Create a key in Pure//Pay → Settings → Developer API.</p></div>
          <span className={`badge ${data.connected ? 'badge--success' : ''}`} data-testid="purepay-connection-status">{data.connected ? 'Connected' : 'Not connected'}</span>
        </div>
        <div style={{ padding: 10, margin: '12px 0', background: '#fff7ed', color: '#9a3412', borderRadius: 6, fontSize: 12 }}>
          Pure//Pay documents that an API key grants full access to its organization. Use a dedicated key, keep it server-side, and revoke it from Pure//Pay if this connection is retired.
        </div>
        {data.connected ? (
          <>
            <dl style={facts}>
              <dt>Key</dt><dd>••••{data.api_key_last4}</dd>
              <dt>Wallet</dt><dd>{money}</dd>
              <dt>Last probe</dt><dd>{data.last_probe_at || '—'}</dd>
            </dl>
            {data.last_probe_error && <p className="error">{data.last_probe_error}</p>}
            <div style={{ display: 'flex', gap: 8 }}>
              <button className="btn" disabled={!!busy} onClick={() => run('probe', () => api.post('/api/purepay_connection.php?action=probe', {}))} data-testid="purepay-probe">{busy === 'probe' ? 'Probing…' : 'Probe connection'}</button>
              <button className="btn btn--danger" disabled={!!busy} onClick={() => window.confirm('Disconnect Pure//Pay? Existing lifecycle history is preserved.') && run('disconnect', () => api.post('/api/purepay_connection.php?action=disconnect', {}))} data-testid="purepay-disconnect">Disconnect</button>
            </div>
          </>
        ) : (
          <div style={{ display: 'grid', gap: 10 }}>
            <label>Label<input className="input" value={label} onChange={e => setLabel(e.target.value)} placeholder="AP production" data-testid="purepay-label" /></label>
            <label>API key<input className="input" type="password" autoComplete="new-password" value={apiKey} onChange={e => setApiKey(e.target.value)} placeholder="pk_live_…" data-testid="purepay-api-key" /></label>
            <button className="btn btn--primary" disabled={!!busy || !apiKey.trim()} onClick={() => run('connect', async () => { await api.post('/api/purepay_connection.php', { api_key: apiKey, label }); setApiKey(''); })} data-testid="purepay-connect">{busy === 'connect' ? 'Connecting…' : 'Connect and verify'}</button>
          </div>
        )}
      </div>

      <div style={card}>
        <strong>Signed webhooks</strong>
        <p style={hint}>In Pure//Pay → Settings → Developer API, add this HTTPS endpoint and subscribe to payment.settled and payment.failed.</p>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginBottom: 12 }}>
          <code data-testid="purepay-webhook-url" style={{ flex: 1, overflowWrap: 'anywhere', background: '#f8fafc', padding: 8, borderRadius: 4 }}>{data.delivery_url}</code>
          <button className="btn" onClick={() => navigator.clipboard?.writeText(data.delivery_url)} data-testid="purepay-copy-webhook">Copy</button>
        </div>
        <p style={hint}>Paste the <code>whsec_…</code> secret Pure//Pay shows for that endpoint. It is encrypted at rest and only its last four characters return here.</p>
        <div style={{ display: 'flex', gap: 8 }}>
          <input className="input" type="password" autoComplete="new-password" value={secret} onChange={e => setSecret(e.target.value)} placeholder={data.webhook_configured ? `Configured ••••${data.webhook_secret_last4}` : 'whsec_…'} data-testid="purepay-webhook-secret" />
          <button className="btn btn--primary" disabled={!!busy || !data.connected || !secret.trim()} onClick={() => run('secret', async () => { await api.post('/api/purepay_connection.php?action=webhook_secret', { webhook_secret: secret }); setSecret(''); })} data-testid="purepay-save-secret">{busy === 'secret' ? 'Saving…' : 'Save secret'}</button>
        </div>
      </div>

      <div style={card}>
        <div style={{ display: 'flex', justifyContent: 'space-between' }}><strong>Recent webhook receipts</strong><button className="btn btn--ghost" onClick={status.reload}>Refresh</button></div>
        {(data.recent_events || []).length === 0 ? <p style={hint}>No webhook events received yet.</p> : (
          <table className="data-table" data-testid="purepay-webhook-events"><thead><tr><th>Event</th><th>Verified</th><th>Processed</th><th>Received</th></tr></thead><tbody>
            {data.recent_events.map(e => <tr key={e.event_id}><td><code>{e.event_type || e.event_id}</code></td><td>{Number(e.verified) ? 'Yes' : `No (${e.verify_error || 'unknown'})`}</td><td>{e.processing_error || e.processed_at || '—'}</td><td>{e.created_at}</td></tr>)}
          </tbody></table>
        )}
      </div>

      <p style={hint}>Provider resources: <a href="https://purepay.online/developers" target="_blank" rel="noreferrer">API documentation</a> · <a href="https://purepay.online/settings" target="_blank" rel="noreferrer">Pure//Pay settings</a></p>
    </section>
  );
}

const card = { border: '1px solid var(--cf-border, #e5e7eb)', borderRadius: 8, padding: 16, marginBottom: 16, background: 'var(--cf-surface, #fff)' };
const hint = { margin: '5px 0 12px', color: 'var(--cf-text-secondary)', fontSize: 12 };
const facts = { display: 'grid', gridTemplateColumns: '140px 1fr', gap: 8, fontSize: 13, margin: '12px 0 16px' };

