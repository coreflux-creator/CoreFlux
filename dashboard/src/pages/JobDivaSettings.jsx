import React, { useState } from 'react';
import { api, useApi } from '../lib/api';
import {
  Activity, AlertCircle, CheckCircle2, Copy, Link2,
  PlugZap, RefreshCw, ShieldCheck, XCircle,
} from 'lucide-react';

/**
 * JobDiva integration settings — Sprint 8a / Slice A1.
 *
 * Lives at /admin/integrations/jobdiva. Lets a tenant admin:
 *   • Connect (clientid + username + password [+ optional webhook secret])
 *   • Disconnect (soft — preserves audit history)
 *   • Test connection (Ping)
 *   • Run "Sync now" (no-op in A1; will trigger entity pipelines in A2+)
 *   • Copy their webhook URL for pasting into JobDiva's webhook config
 *   • View recent audit trail (last 25 actions) + recent webhook events
 */
export default function JobDivaSettings() {
  const { data, error, loading, reload } = useApi('/api/jobdiva/status.php?action=status');
  const [form, setForm] = useState({
    client_id: '', username: '', password: '', webhook_secret: '',
  });
  const [busy, setBusy] = useState({});
  const [msg, setMsg]   = useState(null);
  const [err, setErr]   = useState(null);
  const [showPwd, setShowPwd] = useState(false);

  const set = (k, v) => setForm(s => ({ ...s, [k]: v }));
  const clear = () => { setMsg(null); setErr(null); };

  const onConnect = async (e) => {
    e?.preventDefault?.();
    clear();
    if (!form.client_id || !form.username || !form.password) {
      setErr('client_id, username, and password are all required.');
      return;
    }
    setBusy(b => ({ ...b, connect: true }));
    try {
      const r = await api.post('/api/jobdiva/connect.php?action=connect', form);
      setMsg(r.ping?.ok
        ? `Connected. Round-trip ${r.ping.latency_ms}ms.`
        : `Saved credentials, but JobDiva rejected the auth: ${r.ping?.error}`);
      setForm(s => ({ ...s, password: '' }));
      reload();
    } catch (e) {
      setErr(e.message);
    } finally {
      setBusy(b => ({ ...b, connect: false }));
    }
  };

  const onPing = async () => {
    clear(); setBusy(b => ({ ...b, ping: true }));
    try {
      const r = await api.post('/api/jobdiva/ping.php?action=ping');
      setMsg(r.ok ? `Ping OK (${r.latency_ms}ms).` : `Ping failed: ${r.error}`);
      reload();
    } catch (e) { setErr(e.message); }
    finally    { setBusy(b => ({ ...b, ping: false })); }
  };

  const onSync = async () => {
    clear(); setBusy(b => ({ ...b, sync: true }));
    try {
      const r = await api.post('/api/jobdiva/sync.php?action=sync');
      setMsg(r.note || 'Sync triggered.');
      reload();
    } catch (e) { setErr(e.message); }
    finally    { setBusy(b => ({ ...b, sync: false })); }
  };

  const onDisconnect = async () => {
    if (!confirm('Disconnect JobDiva? Audit history is preserved.')) return;
    clear(); setBusy(b => ({ ...b, disconnect: true }));
    try {
      await api.post('/api/jobdiva/disconnect.php?action=disconnect');
      setMsg('Disconnected.');
      reload();
    } catch (e) { setErr(e.message); }
    finally    { setBusy(b => ({ ...b, disconnect: false })); }
  };

  const copyWebhook = () => {
    if (!data?.webhook_url) return;
    navigator.clipboard?.writeText(data.webhook_url);
    setMsg('Webhook URL copied to clipboard.');
  };

  const StatusBadge = ({ s }) => {
    const palette = {
      connected:   { bg: '#ecfdf5', fg: '#065f46', icon: CheckCircle2 },
      degraded:    { bg: '#fef3c7', fg: '#92400e', icon: AlertCircle  },
      error:       { bg: '#fef2f2', fg: '#7f1d1d', icon: XCircle      },
      disconnected:{ bg: '#f1f5f9', fg: '#475569', icon: PlugZap      },
    }[s] || { bg: '#f1f5f9', fg: '#475569', icon: PlugZap };
    const Icon = palette.icon;
    return (
      <span data-testid="jobdiva-settings-status-badge"
            style={{ display: 'inline-flex', alignItems: 'center', gap: 4,
                     padding: '3px 10px', borderRadius: 999,
                     background: palette.bg, color: palette.fg,
                     fontSize: 12, fontWeight: 600 }}>
        <Icon size={12} /> {s}
      </span>
    );
  };

  return (
    <section data-testid="jobdiva-settings-page" style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
      <header style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', flexWrap: 'wrap', gap: 12 }}>
        <div>
          <h2 style={{ display: 'flex', alignItems: 'center', gap: 8, margin: 0 }}>
            <PlugZap size={20} color="#7c3aed" /> JobDiva integration
          </h2>
          <p style={{ color: '#64748b', margin: '4px 0 0', fontSize: 13 }}>
            Tenant-level connection. Two-way sync with field-level ownership ships in the next slice — this slice wires the auth and webhook plumbing.
          </p>
        </div>
        <button data-testid="jobdiva-settings-refresh" onClick={reload} className="btn btn--ghost" style={{ fontSize: 12 }}>
          <RefreshCw size={12} style={{ marginRight: 4, verticalAlign: 'middle' }} />Refresh
        </button>
      </header>

      {loading && <p data-testid="jobdiva-settings-loading">Loading…</p>}
      {error   && <p data-testid="jobdiva-settings-error" className="error">Error: {error.message}</p>}
      {msg     && <p data-testid="jobdiva-settings-msg"  style={{ background: '#ecfdf5', border: '1px solid #a7f3d0', padding: 10, borderRadius: 8, color: '#065f46', fontSize: 13 }}>{msg}</p>}
      {err     && <p data-testid="jobdiva-settings-err"  style={{ background: '#fef2f2', border: '1px solid #fecaca', padding: 10, borderRadius: 8, color: '#7f1d1d', fontSize: 13 }}>{err}</p>}

      {/* Status / connection summary */}
      {data && (
        <div data-testid="jobdiva-settings-status-card"
             style={{ padding: 16, background: '#fff', border: '1px solid #e2e8f0', borderRadius: 12,
                      display: 'flex', flexWrap: 'wrap', gap: 24 }}>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 4, minWidth: 180 }}>
            <span style={lbl}>Status</span>
            <StatusBadge s={data.status || (data.connected ? 'connected' : 'disconnected')} />
          </div>
          {data.connected && (
            <>
              <div style={{ display: 'flex', flexDirection: 'column', gap: 4, minWidth: 160 }}>
                <span style={lbl}>Client ID</span>
                <code data-testid="jobdiva-settings-client-id" style={mono}>{data.client_id}</code>
              </div>
              <div style={{ display: 'flex', flexDirection: 'column', gap: 4, minWidth: 200 }}>
                <span style={lbl}>Username</span>
                <code data-testid="jobdiva-settings-username" style={mono}>{data.username}</code>
              </div>
              <div style={{ display: 'flex', flexDirection: 'column', gap: 4, minWidth: 200 }}>
                <span style={lbl}>Last ping</span>
                <span data-testid="jobdiva-settings-last-ping" style={mono}>{data.last_ping_at || '—'}</span>
              </div>
              <div style={{ display: 'flex', flexDirection: 'column', gap: 4, minWidth: 200 }}>
                <span style={lbl}>Last sync</span>
                <span data-testid="jobdiva-settings-last-sync" style={mono}>{data.last_sync_at || '—'}</span>
              </div>
              <div style={{ display: 'flex', flexDirection: 'column', gap: 4, minWidth: 200 }}>
                <span style={lbl}>Token expires</span>
                <span data-testid="jobdiva-settings-token-exp" style={mono}>{data.session_token_exp || '—'}</span>
              </div>
              {data.last_sync_error && (
                <div data-testid="jobdiva-settings-last-error" style={{ flexBasis: '100%', color: '#b91c1c', fontSize: 12 }}>
                  <AlertCircle size={11} style={{ marginRight: 3, verticalAlign: 'middle' }} />
                  Last error: {data.last_sync_error}
                </div>
              )}
            </>
          )}
        </div>
      )}

      {/* Webhook URL panel — visible whether connected or not so the user can
          paste it into JobDiva *before* clicking Connect. */}
      {data?.webhook_url && (
        <div data-testid="jobdiva-settings-webhook-card"
             style={{ padding: 14, background: '#faf5ff', border: '1px solid #ddd6fe', borderRadius: 10 }}>
          <strong style={{ fontSize: 13, color: '#5b21b6', display: 'flex', alignItems: 'center', gap: 6 }}>
            <Link2 size={14} /> Webhook URL (paste into JobDiva)
          </strong>
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginTop: 6 }}>
            <code data-testid="jobdiva-settings-webhook-url"
                  style={{ ...mono, flex: 1, padding: '6px 10px', background: '#fff', border: '1px solid #ddd6fe', borderRadius: 6, fontSize: 12, overflowX: 'auto', whiteSpace: 'nowrap' }}>
              {data.webhook_url}
            </code>
            <button data-testid="jobdiva-settings-webhook-copy"
                    onClick={copyWebhook} className="btn btn--ghost" style={{ fontSize: 11 }}>
              <Copy size={11} style={{ marginRight: 3, verticalAlign: 'middle' }} />Copy
            </button>
          </div>
          {data.has_webhook_secret
            ? <span data-testid="jobdiva-settings-webhook-secret-set" style={{ fontSize: 11, color: '#059669' }}>
                <ShieldCheck size={11} style={{ marginRight: 3, verticalAlign: 'middle' }} />
                Signature verification enabled
              </span>
            : <span style={{ fontSize: 11, color: '#92400e' }}>
                <AlertCircle size={11} style={{ marginRight: 3, verticalAlign: 'middle' }} />
                No webhook secret set — incoming events will be rejected. Add one in the connect form.
              </span>}
        </div>
      )}

      {/* Connect / re-connect form */}
      <form onSubmit={onConnect} data-testid="jobdiva-settings-connect-form"
            style={{ padding: 16, background: '#fff', border: '1px solid #e2e8f0', borderRadius: 12 }}>
        <h3 style={{ margin: '0 0 4px', fontSize: 15 }}>{data?.connected ? 'Re-connect / update credentials' : 'Connect JobDiva'}</h3>
        <p style={{ color: '#64748b', fontSize: 12, margin: '0 0 12px' }}>
          Tenant logs in once. The server caches the session token and silently re-authenticates whenever it expires.
        </p>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(220px,1fr))', gap: 12 }}>
          <label style={fld}>Client ID
            <input className="input" data-testid="jobdiva-settings-client-id-input"
                   value={form.client_id} onChange={e => set('client_id', e.target.value)}
                   placeholder="JobDiva client ID" autoComplete="off" />
          </label>
          <label style={fld}>Username
            <input className="input" data-testid="jobdiva-settings-username-input"
                   value={form.username} onChange={e => set('username', e.target.value)}
                   placeholder="API user" autoComplete="off" />
          </label>
          <label style={fld}>Password
            <div style={{ display: 'flex', gap: 4 }}>
              <input className="input" type={showPwd ? 'text' : 'password'}
                     data-testid="jobdiva-settings-password-input"
                     value={form.password} onChange={e => set('password', e.target.value)}
                     placeholder="••••••••" autoComplete="new-password"
                     style={{ flex: 1 }} />
              <button type="button" data-testid="jobdiva-settings-show-pwd"
                      onClick={() => setShowPwd(s => !s)} className="btn btn--ghost"
                      style={{ fontSize: 11 }}>{showPwd ? 'Hide' : 'Show'}</button>
            </div>
          </label>
          <label style={fld}>Webhook secret (optional)
            <input className="input" data-testid="jobdiva-settings-webhook-secret-input"
                   value={form.webhook_secret} onChange={e => set('webhook_secret', e.target.value)}
                   placeholder="HMAC shared secret" autoComplete="off" />
          </label>
        </div>
        <div style={{ marginTop: 14, display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          <button type="submit" className="btn btn--primary" data-testid="jobdiva-settings-connect"
                  disabled={busy.connect}>
            {busy.connect ? 'Connecting…' : (data?.connected ? 'Update credentials' : 'Connect')}
          </button>
          {data?.connected && (
            <>
              <button type="button" className="btn btn--ghost" data-testid="jobdiva-settings-ping"
                      onClick={onPing} disabled={busy.ping}>
                {busy.ping ? 'Pinging…' : 'Test connection'}
              </button>
              <button type="button" className="btn btn--ghost" data-testid="jobdiva-settings-sync"
                      onClick={onSync} disabled={busy.sync}>
                <Activity size={12} style={{ marginRight: 3, verticalAlign: 'middle' }} />
                {busy.sync ? 'Syncing…' : 'Sync now'}
              </button>
              <button type="button" className="btn btn--ghost" data-testid="jobdiva-settings-disconnect"
                      onClick={onDisconnect} disabled={busy.disconnect}
                      style={{ color: '#b91c1c' }}>
                {busy.disconnect ? 'Disconnecting…' : 'Disconnect'}
              </button>
            </>
          )}
        </div>
      </form>

      {/* Recent audit + recent webhook events */}
      {data?.recent_audit && (
        <div data-testid="jobdiva-settings-audit-card"
             style={{ padding: 16, background: '#fff', border: '1px solid #e2e8f0', borderRadius: 12 }}>
          <h3 style={{ margin: '0 0 8px', fontSize: 14 }}>Recent activity</h3>
          <table className="data-table" style={{ width: '100%' }} data-testid="jobdiva-settings-audit-table">
            <thead>
              <tr><th>When</th><th>Action</th><th>Entity</th><th>Direction</th><th>OK</th><th>Items</th></tr>
            </thead>
            <tbody>
              {data.recent_audit.length === 0 && (
                <tr><td colSpan={6} className="empty" data-testid="jobdiva-settings-audit-empty">No activity yet.</td></tr>
              )}
              {data.recent_audit.map(r => (
                <tr key={r.id} data-testid={`jobdiva-settings-audit-row-${r.id}`}>
                  <td style={{ fontSize: 12, color: '#64748b' }}>{r.occurred_at}</td>
                  <td><code>{r.action}</code></td>
                  <td>{r.entity_type || '—'}</td>
                  <td>{r.direction}</td>
                  <td>{r.ok ? <CheckCircle2 size={12} color="#059669" /> : <XCircle size={12} color="#dc2626" />}</td>
                  <td style={{ fontSize: 12 }}>
                    {r.items_processed > 0 || r.items_failed > 0 || r.items_skipped > 0
                      ? `${r.items_processed} ok · ${r.items_skipped} skip · ${r.items_failed} fail`
                      : '—'}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {data?.recent_events && data.recent_events.length > 0 && (
        <div data-testid="jobdiva-settings-webhook-events-card"
             style={{ padding: 16, background: '#fff', border: '1px solid #e2e8f0', borderRadius: 12 }}>
          <h3 style={{ margin: '0 0 8px', fontSize: 14 }}>Recent webhook events</h3>
          <table className="data-table" style={{ width: '100%' }}>
            <thead>
              <tr><th>Received</th><th>Event</th><th>Status</th><th>Sig</th><th>JD ID</th><th>Error</th></tr>
            </thead>
            <tbody>
              {data.recent_events.map(e => (
                <tr key={e.id} data-testid={`jobdiva-settings-webhook-row-${e.id}`}>
                  <td style={{ fontSize: 12, color: '#64748b' }}>{e.received_at}</td>
                  <td><code>{e.event_type}</code></td>
                  <td>{e.status}</td>
                  <td>{e.signature_ok ? <CheckCircle2 size={12} color="#059669" /> : <XCircle size={12} color="#dc2626" />}</td>
                  <td style={{ fontSize: 11, color: '#64748b' }}>{e.jd_event_id || '—'}</td>
                  <td style={{ fontSize: 11, color: '#b91c1c' }}>{e.process_error || ''}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  );
}

const lbl  = { fontSize: 11, color: '#94a3b8', textTransform: 'uppercase', letterSpacing: 0.4 };
const mono = { fontFamily: 'ui-monospace, monospace', fontSize: 13, color: '#0f172a' };
const fld  = { display: 'flex', flexDirection: 'column', gap: 4, fontSize: 12, color: '#475569' };
