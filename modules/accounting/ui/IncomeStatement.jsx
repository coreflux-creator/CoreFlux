import React, { useState } from 'react';
import { useApi } from '../../../dashboard/src/lib/api';

/**
 * Income Statement (P&L) — revenue and expense activity for a date range.
 * Runs against posted JEs only (matches Trial Balance discipline).
 */
export default function IncomeStatement() {
  const today = new Date().toISOString().slice(0, 10);
  const yearStart = `${today.slice(0, 4)}-01-01`;
  const [from, setFrom] = useState(yearStart);
  const [to, setTo]     = useState(today);
  const { data, loading, error } = useApi(`/modules/accounting/api/reports.php?type=income_statement&from=${from}&to=${to}`);

  return (
    <section data-testid="accounting-pnl">
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12, gap: 8, flexWrap: 'wrap' }}>
        <h2 style={{ margin: 0 }}>Income Statement</h2>
        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
          <label>From <input type="date" className="input" value={from} onChange={(e) => setFrom(e.target.value)} data-testid="accounting-pnl-from" /></label>
          <label>To   <input type="date" className="input" value={to}   onChange={(e) => setTo(e.target.value)}   data-testid="accounting-pnl-to"   /></label>
        </div>
      </header>
      {loading && <p>Loading…</p>}
      {error   && <p className="error">Error: {error.message}</p>}
      {data && (
        <>
          <Section title="Revenue" rows={data.revenue} total={data.total_revenue} testIdPrefix="accounting-pnl-revenue" />
          <Section title="Expenses" rows={data.expense} total={data.total_expense} testIdPrefix="accounting-pnl-expense" />
          <table className="data-table" style={{ width: '100%', marginTop: 16, borderTop: '2px solid #111' }}>
            <tbody>
              <tr style={{ fontWeight: 700, fontSize: 16 }}>
                <td>Net income</td>
                <td style={{ textAlign: 'right', color: data.net_income >= 0 ? '#065f46' : '#991b1b' }} data-testid="accounting-pnl-net-income">
                  {fmt(data.net_income)}
                </td>
              </tr>
            </tbody>
          </table>
        </>
      )}
    </section>
  );
}

function Section({ title, rows, total, testIdPrefix }) {
  return (
    <div style={{ marginBottom: 12 }}>
      <h3 style={{ margin: '12px 0 4px', fontSize: 14, textTransform: 'uppercase', color: '#374151' }}>{title}</h3>
      <table className="data-table" style={{ width: '100%' }}>
        <thead><tr><th>Code</th><th>Name</th><th style={{ textAlign: 'right' }}>Amount</th></tr></thead>
        <tbody>
          {rows.length === 0 && <tr><td colSpan={3} className="empty" data-testid={`${testIdPrefix}-empty`}>No activity</td></tr>}
          {rows.map((r) => (
            <tr key={r.code} data-testid={`${testIdPrefix}-row-${r.code}`}>
              <td><code>{r.code}</code></td>
              <td>{r.name}</td>
              <td style={{ textAlign: 'right' }}>{fmt(r.amount)}</td>
            </tr>
          ))}
          <tr style={{ fontWeight: 600, background: '#f9fafb' }}>
            <td colSpan={2} style={{ textAlign: 'right' }}>Total {title.toLowerCase()}</td>
            <td style={{ textAlign: 'right' }} data-testid={`${testIdPrefix}-total`}>{fmt(total)}</td>
          </tr>
        </tbody>
      </table>
    </div>
  );
}

function fmt(n) { return Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
