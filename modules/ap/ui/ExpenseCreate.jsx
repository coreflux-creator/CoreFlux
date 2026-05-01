import React, { useState } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import { api } from '../../../dashboard/src/lib/api';

const newLine = () => ({ expense_date: new Date().toISOString().slice(0, 10), category: 'meals', merchant: '', amount: '', description: '', billable_to_client_name: '', gl_expense_account_code: '' });

export default function ExpenseCreate() {
  const navigate = useNavigate();
  const [period, setPeriod] = useState('');
  const [notes, setNotes] = useState('');
  const [lines, setLines] = useState([newLine()]);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);

  const total = lines.reduce((s, l) => s + Number(l.amount || 0), 0);

  const submit = async () => {
    setBusy(true); setError(null);
    try {
      const res = await api.post('/modules/ap/api/expenses.php', {
        period_label: period, notes,
        lines: lines.filter(l => Number(l.amount) > 0).map(l => ({ ...l, amount: Number(l.amount) })),
      });
      navigate(`/modules/ap/expenses`);
    } catch (e) { setError(e); } finally { setBusy(false); }
  };

  return (
    <section data-testid="ap-expense-create">
      <Link to="/modules/ap/expenses" style={{ fontSize: 13, color: 'var(--cf-text-secondary)' }}>← Expense reports</Link>
      <h3 style={{ marginTop: 8 }}>New expense report</h3>

      <div style={{ display: 'grid', gap: 12, maxWidth: 720 }}>
        <label style={{ fontSize: 13 }}><span style={{ color: 'var(--cf-text-secondary)' }}>Period label (e.g. "Mar 2026")</span>
          <input className="input" value={period} onChange={(e) => setPeriod(e.target.value)} data-testid="ap-expense-period" style={{ marginTop: 4 }} />
        </label>
        <label style={{ fontSize: 13 }}><span style={{ color: 'var(--cf-text-secondary)' }}>Notes</span>
          <textarea className="input" rows={3} value={notes} onChange={(e) => setNotes(e.target.value)} data-testid="ap-expense-notes" style={{ marginTop: 4 }} />
        </label>
      </div>

      <h4 style={{ marginTop: 24 }}>Lines</h4>
      <table className="data-table" data-testid="ap-expense-lines">
        <thead><tr><th>Date</th><th>Category</th><th>Merchant</th><th style={{textAlign:'right'}}>Amount</th><th>Description</th><th>Billable to</th><th>GL code</th><th></th></tr></thead>
        <tbody>
          {lines.map((l, i) => (
            <tr key={i}>
              <td><input className="input" type="date" value={l.expense_date} onChange={(e) => update(i, 'expense_date', e.target.value)} data-testid={`ap-expense-line-date-${i}`} /></td>
              <td>
                <select className="input" value={l.category} onChange={(e) => update(i, 'category', e.target.value)} data-testid={`ap-expense-line-category-${i}`}>
                  <option>meals</option><option>travel</option><option>mileage</option>
                  <option>supplies</option><option>software</option><option>lodging</option>
                  <option>other</option>
                </select>
              </td>
              <td><input className="input" value={l.merchant} onChange={(e) => update(i, 'merchant', e.target.value)} data-testid={`ap-expense-line-merchant-${i}`} /></td>
              <td><input className="input" type="number" step="0.01" value={l.amount} onChange={(e) => update(i, 'amount', e.target.value)} data-testid={`ap-expense-line-amount-${i}`} style={{ textAlign: 'right' }} /></td>
              <td><input className="input" value={l.description} onChange={(e) => update(i, 'description', e.target.value)} data-testid={`ap-expense-line-desc-${i}`} /></td>
              <td><input className="input" value={l.billable_to_client_name} onChange={(e) => update(i, 'billable_to_client_name', e.target.value)} data-testid={`ap-expense-line-billto-${i}`} /></td>
              <td><input className="input" value={l.gl_expense_account_code} onChange={(e) => update(i, 'gl_expense_account_code', e.target.value)} data-testid={`ap-expense-line-gl-${i}`} /></td>
              <td><button className="btn btn--ghost" onClick={() => setLines(lines.filter((_, j) => j !== i))} data-testid={`ap-expense-line-remove-${i}`}>×</button></td>
            </tr>
          ))}
        </tbody>
      </table>
      <button className="btn btn--ghost" onClick={() => setLines([...lines, newLine()])} data-testid="ap-expense-add-line" style={{ marginTop: 8 }}>+ Add line</button>

      <div style={{ marginTop: 24, display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
        <span style={{ fontSize: 14 }}>Total: <strong data-testid="ap-expense-total">{total.toFixed(2)}</strong></span>
        <button className="btn btn--primary" onClick={submit} disabled={busy || !period || total <= 0} data-testid="ap-expense-save">{busy ? 'Saving…' : 'Save draft'}</button>
      </div>
      {error && <p className="error" data-testid="ap-expense-create-error">Error: {error.message}</p>}
    </section>
  );

  function update(i, field, val) {
    const next = [...lines];
    next[i] = { ...next[i], [field]: val };
    setLines(next);
  }
}
