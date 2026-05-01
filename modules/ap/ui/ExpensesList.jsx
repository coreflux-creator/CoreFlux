import React from 'react';
import { Link } from 'react-router-dom';
import { api, useApi } from '../../../dashboard/src/lib/api';

export default function ExpensesList() {
  const { data, loading, error, reload } = useApi('/modules/ap/api/expenses.php');
  const rows = data?.rows ?? [];

  const run = async (id, action, body = {}) => {
    try { await api.post(`/modules/ap/api/expenses.php?action=${action}&id=${id}`, body); reload(); }
    catch (e) { alert(e.message); }
  };

  return (
    <section data-testid="ap-expenses-list">
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 'var(--cf-space-4)' }}>
        <h3 style={{ margin: 0, fontSize: 16 }}>Expense reports</h3>
        <Link to="/modules/ap/expenses/new" className="btn btn--primary" data-testid="ap-expense-new">New report</Link>
      </div>
      {loading && <p>Loading…</p>}
      {error && <p className="error">Error: {error.message}</p>}

      <table className="data-table" data-testid="ap-expenses-table">
        <thead><tr><th>#</th><th>Period</th><th>Submitter</th><th style={{textAlign:'right'}}>Total</th><th>Status</th><th>Bill</th><th></th></tr></thead>
        <tbody>
          {rows.length === 0 && !loading && <tr><td colSpan={7} className="empty" data-testid="ap-expenses-empty">No expense reports yet.</td></tr>}
          {rows.map(r => (
            <tr key={r.id} data-testid={`ap-expense-row-${r.id}`}>
              <td>#{r.id}</td>
              <td>{r.period_label}</td>
              <td>{r.submitter_user_id ? `User #${r.submitter_user_id}` : '—'}</td>
              <td style={{textAlign:'right'}}>{Number(r.total).toFixed(2)} {r.currency}</td>
              <td><span className={`badge badge--${r.status}`}>{r.status}</span></td>
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
