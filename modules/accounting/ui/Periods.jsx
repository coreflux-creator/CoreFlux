import React, { useState } from 'react';
import { api, useApi } from '../../../dashboard/src/lib/api';

/**
 * Periods — list with status badges + close / reopen actions.
 *
 * Lifecycle:
 *   open → soft_closed → closed
 *                      → reopened (audit-required reason)
 */
export default function Periods() {
  const { data, loading, error, reload } = useApi('/modules/accounting/api/periods.php');
  const rows = data?.rows ?? [];
  const [busy, setBusy] = useState(null);
  const [err2, setErr2] = useState(null);

  const act = async (id, action) => {
    let body = {};
    if (action === 'reopen') {
      const reason = prompt('Why are you reopening this closed period? (audit-logged)');
      if (!reason || !reason.trim()) return;
      body = { reason: reason.trim() };
    } else if (action === 'close') {
      if (!confirm('Hard-close this period? After this, no entries can be posted into it without re-opening (which is audited).')) return;
    }
    setBusy(id + ':' + action); setErr2(null);
    try {
      await api.post(`/modules/accounting/api/periods.php?action=${action}&id=${id}`, body);
      reload();
    } catch (e) { setErr2(e); }
    finally     { setBusy(null); }
  };

  return (
    <section data-testid="accounting-periods">
      <header style={{ marginBottom: 12 }}>
        <h2 style={{ margin: 0 }}>Periods</h2>
        <p style={{ margin: '4px 0 0', fontSize: 13, color: '#666' }}>
          Auto-created on first post per month. Close monthly to lock the books; reopens are audit-logged with a required reason.
        </p>
      </header>
      {loading && <p>Loading…</p>}
      {error   && <p className="error">Error: {error.message}</p>}
      {err2    && <p className="error" data-testid="accounting-periods-action-error">Error: {err2.message}</p>}
      <table className="data-table" style={{ width: '100%' }}>
        <thead><tr><th>Period</th><th>Range</th><th>Status</th><th>Closed</th><th>Reopened</th><th>Reason</th><th></th></tr></thead>
        <tbody>
          {rows.length === 0 && <tr><td colSpan={7} className="empty" data-testid="accounting-periods-empty">No periods yet — they'll be created when you post your first JE.</td></tr>}
          {rows.map((p) => (
            <tr key={p.id} data-testid={`accounting-periods-row-${p.id}`}>
              <td>P{p.period_number}</td>
              <td>{p.start_date} → {p.end_date}</td>
              <td><StatusPill status={p.status} /></td>
              <td>{p.closed_at || '—'}</td>
              <td>{p.reopened_at || '—'}</td>
              <td style={{ color: '#6b7280', fontStyle: 'italic' }}>{p.reopen_reason || '—'}</td>
              <td>
                {(p.status === 'open' || p.status === 'reopened') && (
                  <>
                    <button className="btn btn--ghost"   onClick={() => act(p.id, 'soft_close')} data-testid={`accounting-periods-soft-close-${p.id}`} disabled={busy?.startsWith(p.id+':')} style={{ fontSize: 12, marginRight: 4 }}>Soft-close</button>
                    <button className="btn btn--primary" onClick={() => act(p.id, 'close')}      data-testid={`accounting-periods-close-${p.id}`}      disabled={busy?.startsWith(p.id+':')} style={{ fontSize: 12 }}>Close</button>
                  </>
                )}
                {p.status === 'soft_closed' && (
                  <>
                    <button className="btn btn--primary" onClick={() => act(p.id, 'close')}  data-testid={`accounting-periods-close-${p.id}`}  disabled={busy?.startsWith(p.id+':')} style={{ fontSize: 12, marginRight: 4 }}>Close</button>
                    <button className="btn btn--ghost"   onClick={() => act(p.id, 'reopen')} data-testid={`accounting-periods-reopen-${p.id}`} disabled={busy?.startsWith(p.id+':')} style={{ fontSize: 12 }}>Reopen</button>
                  </>
                )}
                {p.status === 'closed' && (
                  <button className="btn btn--ghost" onClick={() => act(p.id, 'reopen')} data-testid={`accounting-periods-reopen-${p.id}`} disabled={busy?.startsWith(p.id+':')} style={{ fontSize: 12 }}>Reopen</button>
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </section>
  );
}

function StatusPill({ status }) {
  const colors = {
    open:        { bg: '#dcfce7', fg: '#065f46' },
    soft_closed: { bg: '#fef9c3', fg: '#854d0e' },
    closed:      { bg: '#fee2e2', fg: '#991b1b' },
    reopened:    { bg: '#fed7aa', fg: '#9a3412' },
    future:      { bg: '#e0e7ff', fg: '#3730a3' },
  };
  const c = colors[status] || { bg: '#e5e7eb', fg: '#374151' };
  return <span style={{ background: c.bg, color: c.fg, padding: '2px 8px', borderRadius: 12, fontSize: 11, fontWeight: 600, textTransform: 'uppercase' }} data-testid={`accounting-periods-pill-${status}`}>{status.replace('_', ' ')}</span>;
}
