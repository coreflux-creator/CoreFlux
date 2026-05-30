import React, { useEffect, useState } from 'react';
import { api } from '../lib/api';
import { ShieldOff, Plus, Trash2, RefreshCw, AlertCircle } from 'lucide-react';

/**
 * MailSuppressionsCard — tenant Mail Settings card for managing the
 * recipient suppression list. Resend bounces / complaints auto-
 * populate this list via the webhook receiver; admins can add a
 * manual suppression for a known-bad address, or un-suppress one
 * that's recovered.
 *
 * Mounted at the bottom of MailSettingsPage so the same operator who
 * just sent a test email can see if any recipients on the deny-list
 * are causing silent drops.
 */
const REASON_TONE = {
  bounce:    { bg: '#fef2f2', fg: '#991b1b', label: 'bounce' },
  complaint: { bg: '#fef3c7', fg: '#92400e', label: 'complaint' },
  manual:    { bg: '#eef2ff', fg: '#3730a3', label: 'manual' },
  api:       { bg: '#f1f5f9', fg: '#334155', label: 'api' },
};

export default function MailSuppressionsCard() {
  const [data, setData] = useState({ total: 0, rows: [] });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);
  const [q, setQ] = useState('');
  const [showAdd, setShowAdd] = useState(false);
  const [addEmail, setAddEmail] = useState('');
  const [addNotes, setAddNotes] = useState('');
  const [flash, setFlash] = useState(null);

  const load = async () => {
    setLoading(true); setError(null);
    try {
      const params = new URLSearchParams();
      if (q.trim()) params.set('q', q.trim());
      const r = await api.get(`/api/admin/mail_suppressions.php?${params.toString()}`);
      setData(r);
    } catch (e) {
      setError(e.message || String(e));
    } finally {
      setLoading(false);
    }
  };
  useEffect(() => { load(); /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, []);

  const add = async () => {
    if (!addEmail.trim()) return;
    setBusy(true); setFlash(null);
    try {
      await api.post('/api/admin/mail_suppressions.php', {
        email: addEmail.trim(),
        reason: 'manual',
        notes:  addNotes.trim() || null,
      });
      setFlash({ kind: 'ok', msg: `Suppressed ${addEmail.trim()}` });
      setAddEmail(''); setAddNotes(''); setShowAdd(false);
      load();
    } catch (e) {
      setFlash({ kind: 'err', msg: e.message || 'Add failed' });
    } finally { setBusy(false); }
  };

  const remove = async (row) => {
    if (!window.confirm(`Un-suppress ${row.email}? CoreFlux will resume sending to this address.`)) return;
    setBusy(true); setFlash(null);
    try {
      await api.delete(`/api/admin/mail_suppressions.php?id=${row.id}`);
      setFlash({ kind: 'ok', msg: `Un-suppressed ${row.email}` });
      load();
    } catch (e) {
      setFlash({ kind: 'err', msg: e.message || 'Remove failed' });
    } finally { setBusy(false); }
  };

  return (
    <section
      data-testid="admin-mail-suppressions"
      style={{
        marginTop: 24, padding: 20, border: '1px solid #e5e7eb',
        borderRadius: 8, background: '#fff',
      }}
    >
      <header style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 12, marginBottom: 12 }}>
        <div>
          <h2 style={{ fontSize: 16, margin: 0, display: 'flex', alignItems: 'center', gap: 6 }}>
            <ShieldOff size={16} /> Suppressed recipients
          </h2>
          <p style={{ color: 'var(--cf-text-secondary)', fontSize: 12, margin: '4px 0 0' }}>
            Addresses CoreFlux will never email. Resend bounces and complaint webhooks add to this
            list automatically; remove an address here once you've confirmed it's safe to resume.
          </p>
        </div>
        <div style={{ display: 'flex', gap: 6 }}>
          <button type="button" className="btn"
                  data-testid="admin-mail-suppressions-refresh"
                  onClick={load} disabled={loading || busy}>
            <RefreshCw size={13} />
          </button>
          <button type="button" className="btn btn--primary"
                  data-testid="admin-mail-suppressions-add-btn"
                  onClick={() => setShowAdd(true)} disabled={busy}>
            <Plus size={13} style={{ marginRight: 4 }} />Add
          </button>
        </div>
      </header>

      <div style={{ display: 'flex', gap: 8, marginBottom: 12 }}>
        <input
          type="search"
          data-testid="admin-mail-suppressions-search"
          value={q}
          onChange={(e) => setQ(e.target.value)}
          onKeyDown={(e) => { if (e.key === 'Enter') load(); }}
          placeholder="Search email or notes…"
          style={{ flex: 1, padding: '6px 10px', borderRadius: 4,
                   border: '1px solid #e5e7eb', fontSize: 13 }}
        />
        <button type="button" className="btn"
                data-testid="admin-mail-suppressions-search-btn"
                onClick={load} disabled={loading}>
          Search
        </button>
      </div>

      {flash && (
        <div data-testid="admin-mail-suppressions-flash"
             style={{
               marginBottom: 12, padding: '6px 10px', borderRadius: 4, fontSize: 13,
               background: flash.kind === 'ok' ? '#dcfce7' : '#fef2f2',
               color:      flash.kind === 'ok' ? '#065f46' : '#991b1b',
             }}>
          {flash.msg}
        </div>
      )}

      {error && (
        <div data-testid="admin-mail-suppressions-error"
             style={{ color: '#b91c1c', fontSize: 13, marginBottom: 12 }}>
          {error}
        </div>
      )}

      {showAdd && (
        <div data-testid="admin-mail-suppressions-add-form"
             style={{ padding: 12, background: '#f9fafb',
                      border: '1px solid #e5e7eb', borderRadius: 6, marginBottom: 12 }}>
          <div style={{ display: 'flex', gap: 8, alignItems: 'flex-start', flexWrap: 'wrap' }}>
            <input
              type="email" required
              data-testid="admin-mail-suppressions-add-email"
              value={addEmail}
              onChange={(e) => setAddEmail(e.target.value)}
              placeholder="email@example.com"
              style={{ flex: 1, minWidth: 220, padding: '6px 10px',
                       borderRadius: 4, border: '1px solid #e5e7eb', fontSize: 13 }}
            />
            <input
              type="text"
              data-testid="admin-mail-suppressions-add-notes"
              value={addNotes}
              onChange={(e) => setAddNotes(e.target.value)}
              placeholder="Optional notes (why is this address suppressed?)"
              style={{ flex: 2, minWidth: 220, padding: '6px 10px',
                       borderRadius: 4, border: '1px solid #e5e7eb', fontSize: 13 }}
            />
            <button type="button" className="btn btn--primary"
                    data-testid="admin-mail-suppressions-add-submit"
                    onClick={add} disabled={busy || !addEmail.trim()}>
              Suppress
            </button>
            <button type="button" className="btn"
                    data-testid="admin-mail-suppressions-add-cancel"
                    onClick={() => { setShowAdd(false); setAddEmail(''); setAddNotes(''); }}>
              Cancel
            </button>
          </div>
        </div>
      )}

      {loading && <p data-testid="admin-mail-suppressions-loading">Loading…</p>}

      {!loading && data.rows.length === 0 && (
        <p data-testid="admin-mail-suppressions-empty"
           style={{ fontSize: 13, color: 'var(--cf-text-secondary)', margin: 0 }}>
          <AlertCircle size={13} style={{ marginRight: 4, verticalAlign: 'middle' }} />
          No suppressed recipients yet — your mail is reaching every inbox you've sent to.
        </p>
      )}

      {!loading && data.rows.length > 0 && (
        <table data-testid="admin-mail-suppressions-table"
               style={{ width: '100%', fontSize: 12, borderCollapse: 'collapse' }}>
          <thead>
            <tr style={{ textAlign: 'left', color: 'var(--cf-text-secondary)',
                         borderBottom: '1px solid #e5e7eb' }}>
              <th style={{ padding: '6px' }}>Email</th>
              <th style={{ padding: '6px' }}>Reason</th>
              <th style={{ padding: '6px' }}>Source</th>
              <th style={{ padding: '6px' }}>Notes</th>
              <th style={{ padding: '6px' }}>Suppressed at</th>
              <th style={{ padding: '6px' }}></th>
            </tr>
          </thead>
          <tbody>
            {data.rows.map((r) => {
              const tone = REASON_TONE[r.reason] || REASON_TONE.manual;
              return (
                <tr key={r.id}
                    data-testid={`admin-mail-suppressions-row-${r.id}`}
                    style={{ borderBottom: '1px solid #f1f5f9' }}>
                  <td style={{ padding: '6px', fontFamily: 'ui-monospace' }}>{r.email}</td>
                  <td style={{ padding: '6px' }}>
                    <span style={{
                      padding: '1px 6px', borderRadius: 999, fontSize: 11,
                      background: tone.bg, color: tone.fg, fontWeight: 600,
                    }}>
                      {tone.label}
                    </span>
                  </td>
                  <td style={{ padding: '6px', color: 'var(--cf-text-secondary)' }}>{r.source}</td>
                  <td style={{ padding: '6px', color: 'var(--cf-text-secondary)' }}>{r.notes || '—'}</td>
                  <td style={{ padding: '6px', fontFamily: 'ui-monospace', color: 'var(--cf-text-secondary)' }}>
                    {r.created_at}
                  </td>
                  <td style={{ padding: '6px', textAlign: 'right' }}>
                    <button type="button" className="btn"
                            data-testid={`admin-mail-suppressions-remove-${r.id}`}
                            onClick={() => remove(r)}
                            disabled={busy}
                            style={{ fontSize: 11, padding: '2px 6px' }}
                            title="Resume sending to this address">
                      <Trash2 size={11} />
                    </button>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      )}

      {!loading && data.total > data.rows.length && (
        <p style={{ fontSize: 11, color: 'var(--cf-text-secondary)', margin: '8px 0 0' }}>
          Showing first {data.rows.length} of {data.total}. Search to narrow the list.
        </p>
      )}
    </section>
  );
}
