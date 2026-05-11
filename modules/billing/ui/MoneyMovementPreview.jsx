import React, { useEffect, useState } from 'react';
import { api } from '../../../dashboard/src/lib/api';
import KpiNote from '../../../dashboard/src/components/KpiNote';

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
  const [links, setLinks] = useState([]);
  const [newLink, setNewLink] = useState(null);  // {raw_token, public_url, expires_in_days}
  const [linkBusy, setLinkBusy] = useState(false);
  const [showPdfBusy, setShowPdfBusy] = useState(false);

  const loadLinks = async () => {
    try {
      const r = await api.get('/modules/billing/api/money_movement_share_links.php');
      setLinks(r.rows || []);
    } catch (_) { /* link table maybe not migrated */ }
  };

  const load = async (date) => {
    setLoading(true); setError(null);
    try {
      const d = await api.get(`/modules/billing/api/money_movement.php?as_of=${encodeURIComponent(date)}`);
      setData(d);
    } catch (e) { setError(e.message); }
    finally { setLoading(false); }
  };

  useEffect(() => { load(asOf); loadLinks(); }, [asOf]);

  const send = async () => {
    if (!confirm(`Send the digest to ${data?.recipients?.length || 0} recipient(s) now?`)) return;
    setSending(true); setToast(null);
    try {
      const res = await api.post('/modules/billing/api/money_movement.php?action=send_now', { as_of: asOf });
      setToast({ kind: 'ok', text: `Sent to ${res.sent} recipient${res.sent === 1 ? '' : 's'}${res.failed ? `, ${res.failed} failed` : ''}.` });
    } catch (e) { setToast({ kind: 'err', text: e.message }); }
    finally { setSending(false); }
  };

  const mintLink = async (label) => {
    setLinkBusy(true); setToast(null); setNewLink(null);
    try {
      const r = await api.post('/modules/billing/api/money_movement_share_links.php', { as_of: asOf, label: label || null, ttl_days: 30 });
      setNewLink(r);
      loadLinks();
    } catch (e) { setToast({ kind: 'err', text: e.message }); }
    finally { setLinkBusy(false); }
  };

  const revokeLink = async (id) => {
    if (!confirm('Revoke this share link immediately?')) return;
    try {
      await api.post(`/modules/billing/api/money_movement_share_links.php?action=revoke&id=${id}`, {});
      loadLinks();
    } catch (e) { setToast({ kind: 'err', text: e.message }); }
  };

  const downloadPdf = async () => {
    setShowPdfBusy(true);
    try {
      window.open(`/modules/billing/api/money_movement_pdf.php?as_of=${encodeURIComponent(asOf)}`, '_blank');
    } finally { setTimeout(() => setShowPdfBusy(false), 800); }
  };

  const recipients = data?.recipients || [];
  const snap = data?.snapshot || {};
  const net  = (snap?.cash_in?.total || 0) - (snap?.cash_out?.total || 0);

  // KPI notes — load once. We cache the full map then pass per-key into KpiNote.
  const [notes, setNotes] = useState({});
  const [canEditNotes, setCanEditNotes] = useState(false);
  useEffect(() => {
    api.get('/api/kpi_notes.php').then((d) => {
      setNotes(d.notes || {});
      setCanEditNotes(!!d.can_write);
    }).catch(() => { /* harmless if endpoint absent */ });
  }, []);
  const onNoteSaved = (k, text) => setNotes((n) => ({ ...n, [k]: { text, updated_at: new Date().toISOString() } }));

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
            <div style={{ marginTop: 8, paddingTop: 8, borderTop: '1px dashed #e5e7eb' }}>
              <KpiNote noteKey="money_movement_net" note={notes.money_movement_net} canWrite={canEditNotes} onSaved={onNoteSaved} />
            </div>
          </div>

          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: 12, marginBottom: 14 }} data-testid="money-movement-kpi-notes">
            <div style={{ background: '#f8fafc', borderRadius: 6, padding: 10 }}>
              <div style={{ fontSize: 11, color: 'var(--cf-text-secondary)' }}>CASH IN — ${Number(snap?.cash_in?.total || 0).toLocaleString()}</div>
              <KpiNote noteKey="money_movement_cash_in" note={notes.money_movement_cash_in} canWrite={canEditNotes} onSaved={onNoteSaved} />
            </div>
            <div style={{ background: '#f8fafc', borderRadius: 6, padding: 10 }}>
              <div style={{ fontSize: 11, color: 'var(--cf-text-secondary)' }}>CASH OUT — ${Number(snap?.cash_out?.total || 0).toLocaleString()}</div>
              <KpiNote noteKey="money_movement_cash_out" note={notes.money_movement_cash_out} canWrite={canEditNotes} onSaved={onNoteSaved} />
            </div>
            <div style={{ background: '#f8fafc', borderRadius: 6, padding: 10 }}>
              <div style={{ fontSize: 11, color: 'var(--cf-text-secondary)' }}>RUNWAY</div>
              <KpiNote noteKey="money_movement_runway" note={notes.money_movement_runway} canWrite={canEditNotes} onSaved={onNoteSaved} />
            </div>
          </div>

          <div
            style={{ border: '1px solid var(--cf-border, #e5e7eb)', borderRadius: 8, padding: 4, marginBottom: 16, background: '#fff' }}
            data-testid="money-movement-html"
            dangerouslySetInnerHTML={{ __html: data.email?.html || '' }}
          />

          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, flexWrap: 'wrap' }}>
            <button
              className="btn btn--ghost"
              onClick={downloadPdf}
              disabled={showPdfBusy}
              data-testid="money-movement-pdf"
            >
              {showPdfBusy ? 'Opening…' : 'Download PDF'}
            </button>
            <button
              className="btn btn--ghost"
              onClick={() => mintLink(prompt('Optional label (e.g., "Q1 board prep"):') || null)}
              disabled={linkBusy}
              data-testid="money-movement-share-link-mint"
            >
              {linkBusy ? 'Minting…' : 'Create share link'}
            </button>
            <button
              className="btn btn--primary"
              onClick={send}
              disabled={sending || recipients.length === 0}
              data-testid="money-movement-send"
            >
              {sending ? 'Sending…' : `Send now to ${recipients.length} recipient${recipients.length === 1 ? '' : 's'}`}
            </button>
          </div>

          {newLink && (
            <div data-testid="money-movement-share-link-new"
                 style={{ marginTop: 16, padding: 12, background: '#ecfdf5', border: '1px solid #6ee7b7', borderRadius: 8, fontSize: 13 }}>
              <strong>Share link created</strong> — copy it now; for security we won't show it again.
              <div style={{ marginTop: 6, padding: 8, background: '#fff', borderRadius: 4, fontFamily: 'monospace', fontSize: 12, wordBreak: 'break-all' }}>
                {window.location.origin}{newLink.public_url}
              </div>
              <p style={{ margin: '8px 0 0', color: 'var(--cf-text-secondary)' }}>
                Expires in {newLink.expires_in_days} days. Anyone with this URL sees a read-only snapshot — no login required.
              </p>
            </div>
          )}

          {links.length > 0 && (
            <div style={{ marginTop: 20 }}>
              <h3 style={{ fontSize: 14, margin: '0 0 8px' }}>Active share links</h3>
              <table className="data-table" data-testid="money-movement-share-links-table" style={{ fontSize: 12 }}>
                <thead>
                  <tr><th>Label</th><th>Snapshot date</th><th>Views</th><th>Expires</th><th></th></tr>
                </thead>
                <tbody>
                  {links.map((l) => (
                    <tr key={l.id} data-testid={`money-movement-share-link-row-${l.id}`}>
                      <td>{l.label || <em style={{ color: '#94a3b8' }}>untitled</em>}</td>
                      <td>{l.as_of}</td>
                      <td>{l.view_count || 0}</td>
                      <td style={{ color: l.revoked_at ? '#dc2626' : undefined }}>
                        {l.revoked_at ? 'revoked' : l.expires_at}
                      </td>
                      <td style={{ textAlign: 'right' }}>
                        {!l.revoked_at && (
                          <button className="btn btn--ghost" style={{ fontSize: 11 }}
                                  onClick={() => revokeLink(l.id)}
                                  data-testid={`money-movement-share-link-revoke-${l.id}`}>
                            Revoke
                          </button>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </>
      )}
    </section>
  );
}
