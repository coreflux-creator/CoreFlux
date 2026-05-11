import React, { useEffect, useState } from 'react';
import { api } from '../../../dashboard/src/lib/api';

/**
 * Money Movement digest — preview + send-now page.
 *
 * Same email body that the Monday-morning cron sends, rendered inline
 * with a "Send to CFO inbox now" button for finance leads who want
 * to fire it off out-of-cycle (board meeting prep, week-in-review, etc.).
 */
export default function MoneyMovementPreview() {
  const [asOf, setAsOf] = useState(new Date().toISOString().slice(0, 10));
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [sending, setSending] = useState(false);
  const [toast, setToast] = useState(null);

  const load = async (date) => {
    setLoading(true); setError(null);
    try {
      const d = await api.get(`/modules/billing/api/money_movement.php?as_of=${encodeURIComponent(date)}`);
      setData(d);
    } catch (e) { setError(e.message); }
    finally { setLoading(false); }
  };

  useEffect(() => { load(asOf); }, [asOf]);

  const send = async () => {
    if (!confirm(`Send the digest to ${data?.recipients?.length || 0} recipient(s) now?`)) return;
    setSending(true); setToast(null);
    try {
      const res = await api.post('/modules/billing/api/money_movement.php?action=send_now', { as_of: asOf });
      setToast({ kind: 'ok', text: `Sent to ${res.sent} recipient${res.sent === 1 ? '' : 's'}${res.failed ? `, ${res.failed} failed` : ''}.` });
    } catch (e) { setToast({ kind: 'err', text: e.message }); }
    finally { setSending(false); }
  };

  const recipients = data?.recipients || [];
  const snap = data?.snapshot || {};
  const net  = (snap?.cash_in?.total || 0) - (snap?.cash_out?.total || 0);

  return (
    <section data-testid="money-movement-preview" style={{ maxWidth: 920, margin: '0 auto' }}>
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 18, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <h2 style={{ margin: 0 }}>Weekly money movement digest</h2>
          <p style={{ margin: '4px 0 0', fontSize: 13, color: 'var(--cf-text-secondary)' }}>
            Preview the digest the Monday-morning cron will send. Same renderer, same recipient list.
          </p>
        </div>
        <label style={{ fontSize: 12 }}>
          Week ending&nbsp;
          <input
            type="date" className="input" value={asOf}
            onChange={(e) => setAsOf(e.target.value)}
            data-testid="money-movement-asof" style={{ marginLeft: 4 }}
          />
        </label>
      </header>

      {loading && <p>Loading…</p>}
      {error && <p className="error" data-testid="money-movement-error">Error: {error}</p>}
      {toast && (
        <p
          className={toast.kind === 'ok' ? 'success' : 'error'}
          data-testid={`money-movement-${toast.kind === 'ok' ? 'sent' : 'err'}`}
          style={{ background: toast.kind === 'ok' ? '#f0fdf4' : '#fef2f2', padding: 10, borderRadius: 6, fontSize: 13 }}
        >
          {toast.text}
        </p>
      )}

      {data && (
        <>
          <div style={{ background: '#f8fafc', borderRadius: 8, padding: 14, marginBottom: 14, fontSize: 13 }}>
            <div data-testid="money-movement-recipients">
              <strong>Recipients:</strong>{' '}
              {recipients.length === 0
                ? <em style={{ color: '#dc2626' }}>None resolved — assign admin/manager/CFO/controller role to a user with email.</em>
                : recipients.map((r) => `${r.name?.trim() || r.email}`).join(', ')}
            </div>
            <div style={{ marginTop: 6 }}>
              <strong>Net this week:</strong>{' '}
              <span style={{ color: net >= 0 ? '#16a34a' : '#dc2626', fontWeight: 600 }} data-testid="money-movement-net">
                {net >= 0 ? '+' : '−'}${Math.abs(net).toLocaleString(undefined, { maximumFractionDigits: 0 })}
              </span>
            </div>
          </div>

          <div
            style={{ border: '1px solid var(--cf-border, #e5e7eb)', borderRadius: 8, padding: 4, marginBottom: 16, background: '#fff' }}
            data-testid="money-movement-html"
            dangerouslySetInnerHTML={{ __html: data.email?.html || '' }}
          />

          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
            <button
              className="btn btn--primary"
              onClick={send}
              disabled={sending || recipients.length === 0}
              data-testid="money-movement-send"
            >
              {sending ? 'Sending…' : `Send now to ${recipients.length} recipient${recipients.length === 1 ? '' : 's'}`}
            </button>
          </div>
        </>
      )}
    </section>
  );
}
