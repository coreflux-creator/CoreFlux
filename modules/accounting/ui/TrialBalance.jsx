import React, { useState } from 'react';
import { useApi } from '../../../dashboard/src/lib/api';

/**
 * Trial Balance — on-read report from posted journal entries.
 */
export default function TrialBalance() {
  const [asOf, setAsOf] = useState(new Date().toISOString().slice(0, 10));
  const { data, loading, error } = useApi(`/modules/accounting/api/journal_entries.php?action=trial_balance&as_of=${asOf}`);
  const rows = data?.rows ?? [];

  const sums = rows.reduce((s, r) => {
    s.debit  += Number(r.debit)  || 0;
    s.credit += Number(r.credit) || 0;
    return s;
  }, { debit: 0, credit: 0 });

  return (
    <section data-testid="accounting-trial">
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 }}>
        <h2 style={{ margin: 0 }}>Trial Balance</h2>
        <label style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
          As of <input type="date" className="input" value={asOf} onChange={(e) => setAsOf(e.target.value)} data-testid="accounting-trial-asof" />
        </label>
      </header>
      {loading && <p>Loading…</p>}
      {error   && <p className="error">Error: {error.message}</p>}
      <table className="data-table" style={{ width: '100%' }}>
        <thead><tr><th>Code</th><th>Name</th><th>Type</th><th style={{ textAlign: 'right' }}>Debit</th><th style={{ textAlign: 'right' }}>Credit</th><th style={{ textAlign: 'right' }}>Balance</th></tr></thead>
        <tbody>
          {rows.length === 0 && <tr><td colSpan={6} className="empty" data-testid="accounting-trial-empty">No posted journal entries yet.</td></tr>}
          {rows.map((r) => (
            <tr key={r.code} data-testid={`accounting-trial-row-${r.code}`}>
              <td><code>{r.code}</code></td>
              <td>{r.name}</td>
              <td>{r.account_type}</td>
              <td style={{ textAlign: 'right' }}>{fmt(r.debit)}</td>
              <td style={{ textAlign: 'right' }}>{fmt(r.credit)}</td>
              <td style={{ textAlign: 'right', fontWeight: 600 }} data-testid={`accounting-trial-balance-${r.code}`}>{fmt(r.balance_signed)}</td>
            </tr>
          ))}
          <tr style={{ fontWeight: 700, borderTop: '2px solid #111' }}>
            <td colSpan={3}>TOTAL</td>
            <td style={{ textAlign: 'right' }} data-testid="accounting-trial-total-debit">{fmt(sums.debit)}</td>
            <td style={{ textAlign: 'right' }} data-testid="accounting-trial-total-credit">{fmt(sums.credit)}</td>
            <td style={{ textAlign: 'right', color: Math.abs(sums.debit - sums.credit) < 0.005 ? '#065f46' : '#991b1b' }} data-testid="accounting-trial-diff">
              {Math.abs(sums.debit - sums.credit) < 0.005 ? 'balanced' : `diff: ${fmt(sums.debit - sums.credit)}`}
            </td>
          </tr>
        </tbody>
      </table>
    </section>
  );
}

function fmt(n) { return Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
