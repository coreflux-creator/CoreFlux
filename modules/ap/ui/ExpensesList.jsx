import React, { useState, useMemo } from 'react';
import { Link } from 'react-router-dom';
import { api, useApi } from '../../../dashboard/src/lib/api';

const STATUSES = ['', 'draft', 'submitted', 'approved', 'rejected', 'paid'];

export default function ExpensesList() {
  const [status, setStatus] = useState('');
  const [mine, setMine]     = useState(false);
  const [selected, setSelected] = useState(() => new Set());

  const qs = new URLSearchParams();
  if (status) qs.set('status', status);
  if (mine)   qs.set('mine', '1');
  const { data, loading, error, reload } = useApi(`/modules/ap/api/expenses.php${qs.toString() ? `?${qs}` : ''}`);
  const rows = data?.rows ?? [];

  const run = async (id, action, body = {}) => {
    try { await api.post(`/modules/ap/api/expenses.php?action=${action}&id=${id}`, body); reload(); }
    catch (e) { alert(e.message); }
  };

  const allChecked = rows.length > 0 && rows.every((r) => selected.has(r.id));
  const someChecked = rows.some((r) => selected.has(r.id));
  const toggleAll = () => {
    setSelected((prev) => {
      if (allChecked) return new Set();
      const next = new Set(prev);
      rows.forEach((r) => next.add(r.id));
      return next;
    });
  };
  const toggleOne = (id) => {
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id); else next.add(id);
      return next;
    });
  };

  const exportSelected = () => {
    if (selected.size === 0) return;
    const ids = Array.from(selected).join(',');
    const url = `/modules/ap/api/expenses.php?action=export_selected&ids=${ids}`;
    window.open(url, '_blank');
  };

  const totalSelected = useMemo(() => {
    return rows.filter((r) => selected.has(r.id))
               .reduce((sum, r) => sum + Number(r.total || 0), 0);
  }, [rows, selected]);

  return (
    <section data-testid="ap-expenses-list">
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 'var(--cf-space-4)', flexWrap: 'wrap', gap: 12 }}>
        <h3 style={{ margin: 0, fontSize: 16 }}>Expense reports</h3>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
          <label style={{ fontSize: 13, color: 'var(--cf-text-secondary)' }}>
            Status&nbsp;
            <select className="input" value={status} onChange={(e) => setStatus(e.target.value)} data-testid="ap-expenses-filter-status">
              {STATUSES.map(s => <option key={s} value={s}>{s || 'all'}</option>)}
            </select>
          </label>
          <label style={{ fontSize: 13, color: 'var(--cf-text-secondary)', display: 'inline-flex', alignItems: 'center', gap: 4 }}>
            <input type="checkbox" checked={mine} onChange={(e) => setMine(e.target.checked)} data-testid="ap-expenses-filter-mine" />
            Mine only
          </label>
          <Link to="/modules/ap/expenses/new" className="btn btn--primary" data-testid="ap-expense-new">New report</Link>
        </div>
      </div>
      {loading && <p>Loading…</p>}
      {error && <p className="error">Error: {error.message}</p>}

      {selected.size > 0 && (
        <div
          data-testid="ap-expenses-bulk-bar"
          style={{
            position: 'sticky', top: 0, zIndex: 1, background: 'var(--cf-accent-light)',
            border: '1px solid var(--cf-accent)', borderRadius: 6, padding: '8px 12px',
            display: 'flex', alignItems: 'center', justifyContent: 'space-between',
            marginBottom: 8, fontSize: 13,
          }}
        >
          <span>
            <strong>{selected.size}</strong> selected · total ${totalSelected.toFixed(2)}
          </span>
          <span style={{ display: 'flex', gap: 8 }}>
            <button className="btn btn--ghost" onClick={() => setSelected(new Set())} data-testid="ap-expenses-bulk-clear">
              Clear
            </button>
            <button
              className="btn btn--primary"
              onClick={exportSelected}
              data-testid="ap-expenses-bulk-export"
            >
              Export selected to CSV
            </button>
          </span>
        </div>
      )}

      <table className="data-table" data-testid="ap-expenses-table">
        <thead>
          <tr>
            <th style={{ width: 30 }}>
              <input
                type="checkbox"
                checked={allChecked}
                ref={(el) => { if (el) el.indeterminate = !allChecked && someChecked; }}
                onChange={toggleAll}
                data-testid="ap-expenses-select-all"
              />
            </th>
            <th>#</th><th>Period</th><th>Submitter</th>
            <th style={{textAlign:'right'}}>Total</th><th>Status</th><th>Bill</th><th></th>
          </tr>
        </thead>
        <tbody>
          {rows.length === 0 && !loading && <tr><td colSpan={8} className="empty" data-testid="ap-expenses-empty">No expense reports yet.</td></tr>}
          {rows.map(r => (
            <tr key={r.id} data-testid={`ap-expense-row-${r.id}`}>
              <td>
                <input
                  type="checkbox"
                  checked={selected.has(r.id)}
                  onChange={() => toggleOne(r.id)}
                  data-testid={`ap-expense-select-${r.id}`}
                />
              </td>
              <td>#{r.id}</td>
              <td>{r.period_label}</td>
              <td>{r.submitter_user_id ? `User #${r.submitter_user_id}` : '—'}</td>
              <td style={{textAlign:'right'}}>{Number(r.total).toFixed(2)} {r.currency}</td>
              <td><StatusPill status={r.status} /></td>
              <td>{r.bill_id ? <Link to={`/modules/ap/bills/${r.bill_id}`} data-testid={`ap-expense-billref-${r.id}`}>#{r.bill_id}</Link> : '—'}</td>
              <td>
                {r.status === 'draft'     && <button className="btn btn--ghost" data-testid={`ap-expense-submit-${r.id}`} onClick={() => run(r.id, 'submit')}>Submit</button>}
                {r.status === 'submitted' && <>
                  <button className="btn btn--ghost" data-testid={`ap-expense-approve-${r.id}`} onClick={() => run(r.id, 'approve')}>Approve</button>
                  <button className="btn btn--ghost" data-testid={`ap-expense-reject-${r.id}`} onClick={() => { const reason = prompt('Rejection reason:'); if (reason) run(r.id, 'reject', { reason }); }}>Reject</button>
                </>}
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
    draft:     { bg: '#f3f4f6', fg: '#374151' },
    submitted: { bg: '#dbeafe', fg: '#1e40af' },
    approved:  { bg: '#d1fae5', fg: '#065f46' },
    rejected:  { bg: '#fee2e2', fg: '#991b1b' },
    paid:      { bg: '#ede9fe', fg: '#5b21b6' },
  };
  const c = colors[status] || colors.draft;
  return (
    <span
      data-testid={`ap-expense-status-${status}`}
      style={{
        display: 'inline-block', padding: '2px 8px', borderRadius: 999,
        background: c.bg, color: c.fg, fontSize: 11, fontWeight: 600, textTransform: 'uppercase',
      }}
    >{status}</span>
  );
}
