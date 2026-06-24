import React, { useEffect, useState } from 'react';
import { useApi, api } from '../../../dashboard/src/lib/api';

/**
 * MercuryWebhookCard — operator-facing config for the Mercury webhook
 * delivery endpoint.
 *
 *   - Shows the canonical delivery URL the operator pastes into
 *     Mercury's webhook UI (https://app.mercury.com → Webhooks).
 *   - Lets them paste / rotate the signing secret (stored AES-GCM
 *     encrypted on the backend; only last 4 chars echo back).
 *   - Surfaces recent events with verification + processing outcome
 *     so an operator can confirm Mercury is reaching the pod.
 *
 * Endpoint: /api/admin/treasury/mercury_webhook.php (GET / POST / PATCH).
 */
export default function MercuryWebhookCard() {
  const cfg = useApi('/api/admin/treasury/mercury_webhook.php');
  const [secret, setSecret] = useState('');
  const [url, setUrl]       = useState('');
  const [endpointId, setEndpointId] = useState('');
  const [busy, setBusy]     = useState(false);
  const [flash, setFlash]   = useState(null);

  useEffect(() => {
    if (cfg.data?.endpoint) {
      setUrl(cfg.data.endpoint.url || '');
      setEndpointId(cfg.data.endpoint.mercury_endpoint_id || '');
    }
  }, [cfg.data?.endpoint?.url, cfg.data?.endpoint?.mercury_endpoint_id]);

  const deliveryUrl = cfg.data?.delivery_url || '';
  const endpoint    = cfg.data?.endpoint;
  const events      = cfg.data?.recent_events || [];

  const handleSave = async (e) => {
    e?.preventDefault?.();
    if (!secret.trim()) {
      setFlash({ kind: 'error', msg: 'Paste the signing secret you got from Mercury before saving.' });
      return;
    }
    setBusy(true);
    setFlash(null);
    try {
      await api.post('/api/admin/treasury/mercury_webhook.php', {
        signing_secret:      secret.trim(),
        url:                 url.trim() || undefined,
        mercury_endpoint_id: endpointId.trim() || undefined,
      });
      setFlash({ kind: 'success', msg: 'Webhook secret stored. Mercury can now hit the pod.' });
      setSecret('');
      cfg.reload();
    } catch (err) {
      setFlash({ kind: 'error', msg: err.message || 'Save failed.' });
    } finally {
      setBusy(false);
    }
  };

  const handlePause = async (paused) => {
    setBusy(true);
    try {
      await api.patch('/api/admin/treasury/mercury_webhook.php', { paused });
      cfg.reload();
    } catch (err) {
      setFlash({ kind: 'error', msg: err.message || 'Update failed.' });
    } finally {
      setBusy(false);
    }
  };

  const copy = () => {
    if (!deliveryUrl) return;
    navigator.clipboard?.writeText(deliveryUrl).then(
      () => setFlash({ kind: 'success', msg: 'Delivery URL copied to clipboard.' }),
      () => setFlash({ kind: 'error', msg: 'Couldn’t copy. Select the URL manually.' })
    );
  };

  return (
    <section
      data-testid="mercury-webhook-card"
      style={{
        marginTop: 24, padding: 16, borderRadius: 8,
        background: '#f8fafc', border: '1px solid #e2e8f0',
      }}
    >
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 }}>
        <div>
          <h4 style={{ margin: 0, fontSize: 14, fontWeight: 600 }}>Mercury webhook delivery</h4>
          <p style={{ margin: '2px 0 0', fontSize: 11, color: '#6b7280' }}>
            Mercury posts payment status updates here. Without this configured, the pod relies on the 30-min reconciliation cron and loses real-time updates.
          </p>
        </div>
        {endpoint && (
          <span
            data-testid="mercury-webhook-status"
            style={{
              padding: '4px 10px', borderRadius: 999, fontSize: 11, fontWeight: 600,
              background: endpoint.paused ? '#fee2e2' : '#d1fae5',
              color:      endpoint.paused ? '#991b1b' : '#065f46',
            }}
          >{endpoint.paused ? 'PAUSED' : 'ACTIVE'}</span>
        )}
      </header>

      <div style={{ fontSize: 12, marginBottom: 10 }}>
        <div style={{ fontWeight: 600, marginBottom: 4 }}>Delivery URL (paste into Mercury → Settings → Webhooks)</div>
        <div style={{ display: 'flex', gap: 8 }}>
          <code
            data-testid="mercury-webhook-delivery-url"
            style={{
              flex: 1, padding: '6px 10px', borderRadius: 4, background: '#fff',
              border: '1px solid #d1d5db', fontFamily: 'ui-monospace, monospace', fontSize: 11,
              overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap',
            }}
          >{deliveryUrl || '(loading…)'}</code>
          <button
            type="button"
            onClick={copy}
            data-testid="mercury-webhook-copy-url"
            style={smallBtn}
          >Copy</button>
        </div>
      </div>

      <form onSubmit={handleSave} style={{ display: 'grid', gap: 8, marginBottom: 12 }}>
        <label style={{ display: 'block', fontSize: 12 }}>
          <span style={{ display: 'block', fontWeight: 600, marginBottom: 4 }}>
            Signing secret (Mercury → Webhook settings)
          </span>
          <input
            type="password"
            value={secret}
            onChange={(e) => setSecret(e.target.value)}
            data-testid="mercury-webhook-secret"
            placeholder={endpoint?.signing_secret_last4
              ? `••••${endpoint.signing_secret_last4} (paste to rotate)`
              : 'whsec_...'}
            style={inputStyle}
          />
        </label>
        <label style={{ display: 'block', fontSize: 12 }}>
          <span style={{ display: 'block', fontWeight: 600, marginBottom: 4 }}>Mercury endpoint ID (optional)</span>
          <input
            type="text"
            value={endpointId}
            onChange={(e) => setEndpointId(e.target.value)}
            data-testid="mercury-webhook-endpoint-id"
            placeholder="wh_..."
            style={inputStyle}
          />
        </label>
        <div style={{ display: 'flex', gap: 8 }}>
          <button
            type="submit"
            disabled={busy}
            data-testid="mercury-webhook-save"
            style={{ ...btnPrimary, opacity: busy ? 0.6 : 1 }}
          >{busy ? 'Saving…' : 'Save secret'}</button>
          {endpoint && (
            <button
              type="button"
              onClick={() => handlePause(!endpoint.paused)}
              disabled={busy}
              data-testid="mercury-webhook-pause-toggle"
              style={btnGhost}
            >{endpoint.paused ? 'Resume' : 'Pause'} deliveries</button>
          )}
        </div>
      </form>

      {flash && (
        <div
          data-testid="mercury-webhook-flash"
          style={{
            padding: '6px 10px', borderRadius: 4, marginBottom: 8, fontSize: 12,
            background: flash.kind === 'success' ? '#d1fae5' : '#fee2e2',
            color:      flash.kind === 'success' ? '#065f46' : '#991b1b',
          }}
        >{flash.msg}</div>
      )}

      <details>
        <summary
          data-testid="mercury-webhook-events-toggle"
          style={{ fontSize: 12, fontWeight: 600, cursor: 'pointer' }}
        >Recent events ({events.length})</summary>
        {events.length === 0 ? (
          <p data-testid="mercury-webhook-events-empty" style={{ fontSize: 12, color: '#6b7280' }}>
            No events received yet.
          </p>
        ) : (
          <table className="data-table" data-testid="mercury-webhook-events-table" style={{ width: '100%', fontSize: 11, marginTop: 8 }}>
            <thead>
              <tr>
                <th>Received</th>
                <th>Event</th>
                <th>Verified</th>
                <th>Outcome</th>
                <th>PI #</th>
              </tr>
            </thead>
            <tbody>
              {events.slice(0, 10).map((ev, i) => (
                <tr key={ev.event_id || i} data-testid={`mercury-webhook-event-${i}`}>
                  <td style={{ fontFamily: 'ui-monospace, monospace' }}>{ev.received_at || '—'}</td>
                  <td>{ev.event_type || ev.operation_type || '—'}</td>
                  <td>{ev.verified ? '✓' : '✗'} {ev.verify_error ? <code style={{ fontSize: 10 }}>{ev.verify_error}</code> : null}</td>
                  <td>{ev.processing_outcome || '(pending)'}</td>
                  <td>{ev.payment_instruction_id || '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </details>
    </section>
  );
}

const inputStyle = {
  width: '100%', padding: '6px 10px', borderRadius: 4,
  border: '1px solid #d1d5db', fontSize: 12, boxSizing: 'border-box',
};
const btnPrimary = {
  padding: '6px 14px', borderRadius: 6, border: 'none',
  background: '#0f172a', color: '#fff', cursor: 'pointer', fontWeight: 600, fontSize: 12,
};
const btnGhost = {
  padding: '6px 14px', borderRadius: 6,
  border: '1px solid #d1d5db', background: '#fff', color: '#374151', cursor: 'pointer', fontSize: 12,
};
const smallBtn = {
  padding: '4px 10px', borderRadius: 4,
  border: '1px solid #d1d5db', background: '#fff', color: '#374151', cursor: 'pointer', fontSize: 11,
};
