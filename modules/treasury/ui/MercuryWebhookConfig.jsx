import React, { useEffect, useState } from 'react';
import { api } from '../../../dashboard/src/lib/api';

/**
 * <MercuryWebhookConfig /> — operator-facing config for Mercury's push
 * webhook integration. Surfaces:
 *
 *   • the per-tenant delivery URL operators paste into Mercury's
 *     dashboard (Settings → Developers → Webhooks).
 *   • a paste field for the signing secret Mercury hands back when
 *     the webhook is created. Stored AES-256-GCM; only last4 returned.
 *   • current endpoint status + last_event_at + recent events feed
 *     with per-event verification + outcome badges.
 *
 * Why this matters: without webhooks the worker advances payment
 * state by polling Mercury every cron tick (~1 min latency). With
 * webhooks the funding leg's `settled` event fires the vendor payout
 * within seconds.
 */
export default function MercuryWebhookConfig() {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [savingSecret, setSavingSecret] = useState(false);
  const [secretInput, setSecretInput] = useState('');
  const [flash, setFlash] = useState(null);

  const reload = async () => {
    setLoading(true); setError(null);
    try {
      const r = await api.get('/api/admin/treasury/mercury_webhook.php');
      setData(r);
    } catch (e) { setError(e.message || 'Failed to load'); }
    finally { setLoading(false); }
  };
  useEffect(() => { reload(); }, []);

  const handleSaveSecret = async (e) => {
    e.preventDefault();
    if (secretInput.length < 16) {
      setError('Signing secret must be at least 16 characters');
      return;
    }
    setSavingSecret(true); setError(null);
    try {
      await api.post('/api/admin/treasury/mercury_webhook.php', {
        signing_secret: secretInput,
      });
      setSecretInput('');
      setFlash({ kind: 'success', msg: 'Webhook signing secret saved.' });
      await reload();
    } catch (e) { setError(e.message || 'Save failed'); }
    finally { setSavingSecret(false); }
  };

  const togglePaused = async () => {
    const next = data?.endpoint?.status !== 'paused';
    try {
      await api.patch('/api/admin/treasury/mercury_webhook.php', { paused: next });
      await reload();
      setFlash({ kind: 'success', msg: next ? 'Webhook paused.' : 'Webhook resumed.' });
    } catch (e) { setError(e.message || 'Toggle failed'); }
  };

  const copyToClipboard = (text) => {
    if (navigator.clipboard) {
      navigator.clipboard.writeText(text).then(() =>
        setFlash({ kind: 'success', msg: 'Copied to clipboard.' })
      );
    }
  };

  const endpoint     = data?.endpoint;
  const deliveryUrl  = data?.delivery_url;
  const recentEvents = data?.recent_events || [];

  return (
    <section data-testid="mercury-webhook-config" style={{ padding: 'var(--cf-space-3, 1rem)' }}>
      <header style={{ marginBottom: 16 }}>
        <h2 style={{ margin: 0, fontSize: 18, fontWeight: 600 }}>Mercury Webhooks</h2>
        <p style={{ margin: '4px 0 0', fontSize: 13, color: '#64748b' }}>
          Mercury pushes <code>transaction.updated</code> events here whenever a
          payment changes state. The payment worker still polls as a safety net,
          but webhooks collapse the funding-cleared → vendor-payout delay from
          ~1 minute to seconds.
        </p>
      </header>

      {error && (
        <div data-testid="mercury-webhook-error" className="error" style={{ marginBottom: 12 }}>
          {error}
        </div>
      )}
      {flash && (
        <div data-testid={`mercury-webhook-flash-${flash.kind}`}
             style={{
               padding: '8px 12px', borderRadius: 6, marginBottom: 12,
               background: flash.kind === 'success' ? '#ecfdf5' : '#fef2f2',
               color:      flash.kind === 'success' ? '#047857' : '#b91c1c',
               fontSize: 13,
             }}>
          {flash.msg}
        </div>
      )}

      {loading && <p data-testid="mercury-webhook-loading">Loading…</p>}

      {!loading && (
        <>
          {/* Delivery URL — operator pastes this into Mercury web UI */}
          <fieldset style={fieldsetStyle}>
            <legend style={legendStyle}>Step 1 — Delivery URL</legend>
            <p style={{ margin: '0 0 8px', fontSize: 12, color: '#64748b' }}>
              In Mercury (Settings → Developers → Webhooks → New endpoint),
              paste this URL. Subscribe to <code>transaction.created</code> and{' '}
              <code>transaction.updated</code>.
            </p>
            <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
              <code data-testid="mercury-webhook-delivery-url"
                    style={{
                      flex: 1, padding: '6px 10px',
                      background: '#f8fafc', border: '1px solid #e2e8f0',
                      borderRadius: 4, fontSize: 12,
                      fontFamily: 'ui-monospace, monospace',
                      wordBreak: 'break-all',
                    }}>
                {deliveryUrl}
              </code>
              <button type="button" className="btn"
                      onClick={() => copyToClipboard(deliveryUrl)}
                      data-testid="mercury-webhook-copy-url">
                Copy
              </button>
            </div>
          </fieldset>

          {/* Signing secret paste */}
          <fieldset style={fieldsetStyle}>
            <legend style={legendStyle}>Step 2 — Signing secret</legend>
            <p style={{ margin: '0 0 8px', fontSize: 12, color: '#64748b' }}>
              Mercury reveals the secret <strong>once</strong> when you create
              the endpoint. Paste it here so we can verify every incoming
              event. Stored AES-256-GCM encrypted; only the last four chars are
              ever displayed.
            </p>
            {endpoint ? (
              <div style={{
                padding: '8px 12px', background: '#f0fdf4',
                border: '1px solid #bbf7d0', borderRadius: 6,
                marginBottom: 10, fontSize: 13,
              }}>
                <strong style={{ color: '#166534' }}>
                  Endpoint active — secret ends with <code>…{endpoint.signing_secret_last4}</code>.
                </strong>
                {endpoint.last_event_at && (
                  <div style={{ fontSize: 11, color: '#64748b', marginTop: 4 }}>
                    Last event received: <code>{endpoint.last_event_at}</code>
                  </div>
                )}
                {endpoint.last_error && (
                  <div style={{ fontSize: 11, color: '#b91c1c', marginTop: 4 }}>
                    Last error: {endpoint.last_error}
                  </div>
                )}
                <div style={{ marginTop: 8, display: 'flex', gap: 8 }}>
                  <button type="button" className="btn btn--ghost"
                          onClick={togglePaused}
                          data-testid="mercury-webhook-toggle-pause">
                    {endpoint.status === 'paused' ? 'Resume' : 'Pause'}
                  </button>
                  <span style={{ fontSize: 11, color: '#64748b', alignSelf: 'center' }}>
                    Status: <code>{endpoint.status}</code>
                  </span>
                </div>
              </div>
            ) : (
              <p style={{ fontSize: 12, color: '#92400e' }}>
                No signing secret stored yet — paste it below to activate the receiver.
              </p>
            )}
            <form onSubmit={handleSaveSecret}
                  style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
              <input
                type="password"
                className="input"
                value={secretInput}
                onChange={(e) => setSecretInput(e.target.value)}
                placeholder={endpoint ? 'Paste new secret to rotate' : 'whsec_…'}
                data-testid="mercury-webhook-secret-input"
                style={{ flex: 1, fontFamily: 'ui-monospace, monospace' }}
              />
              <button type="submit" className="btn btn--primary"
                      disabled={savingSecret || secretInput.length < 16}
                      data-testid="mercury-webhook-secret-save">
                {savingSecret ? 'Saving…' : endpoint ? 'Rotate secret' : 'Save secret'}
              </button>
            </form>
          </fieldset>

          {/* Recent events feed */}
          <fieldset style={fieldsetStyle}>
            <legend style={legendStyle}>Recent events</legend>
            {recentEvents.length === 0 ? (
              <p data-testid="mercury-webhook-events-empty"
                 style={{ color: '#64748b', fontSize: 13 }}>
                No events received yet. Once Mercury starts pushing, the most
                recent 50 will show up here.
              </p>
            ) : (
              <table className="data-table" data-testid="mercury-webhook-events-table"
                     style={{ width: '100%', fontSize: 12 }}>
                <thead>
                  <tr style={{ color: '#64748b', textAlign: 'left' }}>
                    <th style={{ padding: '4px 8px' }}>Received</th>
                    <th style={{ padding: '4px 8px' }}>Type</th>
                    <th style={{ padding: '4px 8px' }}>Resource</th>
                    <th style={{ padding: '4px 8px' }}>Verified</th>
                    <th style={{ padding: '4px 8px' }}>Outcome</th>
                    <th style={{ padding: '4px 8px' }}>PI</th>
                  </tr>
                </thead>
                <tbody>
                  {recentEvents.map(ev => (
                    <tr key={ev.event_id} data-testid={`mercury-webhook-event-${ev.event_id}`}>
                      <td style={{ padding: '4px 8px', whiteSpace: 'nowrap' }}>{ev.received_at}</td>
                      <td style={{ padding: '4px 8px' }}>
                        <code>{ev.event_type}</code>
                      </td>
                      <td style={{ padding: '4px 8px',
                                   fontFamily: 'ui-monospace, monospace',
                                   maxWidth: 200, overflow: 'hidden',
                                   textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}
                          title={ev.resource_id || ''}>
                        {ev.resource_id || '—'}
                      </td>
                      <td style={{ padding: '4px 8px' }}>
                        {ev.verified ? (
                          <span style={{ color: '#166534' }}>✓</span>
                        ) : (
                          <span style={{ color: '#b91c1c' }} title={ev.verify_error || ''}>
                            ✗ {ev.verify_error || ''}
                          </span>
                        )}
                      </td>
                      <td style={{ padding: '4px 8px' }}>
                        {ev.processing_outcome ? (
                          <span style={{
                            padding: '1px 6px', borderRadius: 4, fontSize: 11,
                            background: ev.processing_outcome === 'advanced' ? '#dcfce7' :
                                        ev.processing_outcome === 'no_match' ? '#fef3c7' :
                                        ev.processing_outcome === 'error'    ? '#fee2e2' :
                                                                                '#f1f5f9',
                            color:       ev.processing_outcome === 'advanced' ? '#166534' :
                                         ev.processing_outcome === 'no_match' ? '#92400e' :
                                         ev.processing_outcome === 'error'    ? '#991b1b' :
                                                                                 '#475569',
                          }}>{ev.processing_outcome}</span>
                        ) : '—'}
                      </td>
                      <td style={{ padding: '4px 8px',
                                   fontFamily: 'ui-monospace, monospace' }}>
                        {ev.payment_instruction_id ? `PI-${ev.payment_instruction_id}` : '—'}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </fieldset>
        </>
      )}
    </section>
  );
}

const fieldsetStyle = {
  border: '1px solid #e2e8f0', borderRadius: 8,
  padding: '12px 16px 16px', marginBottom: 16,
};
const legendStyle = {
  fontSize: 11, fontWeight: 700, color: '#475569',
  textTransform: 'uppercase', letterSpacing: 0.4,
  padding: '0 6px',
};
