import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { useApi } from '../../../dashboard/src/lib/api';

/**
 * Balance Sheet — Assets / Liabilities / Equity as of a date.
 * Includes a synthetic "current period net income" line in equity if the
 * P&L hasn't been swept to retained earnings yet, so the sheet balances.
 */
export default function BalanceSheet() {
  const [asOf, setAsOf] = useState(new Date().toISOString().slice(0, 10));
  const { data, loading, error } = useApi(`/modules/accounting/api/reports.php?type=balance_sheet&as_of=${asOf}`);
  return (
    <section data-testid="accounting-balance">
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 }}>
        <h2 style={{ margin: 0 }}>Balance Sheet</h2>
        <label style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
          As of <input type="date" className="input" value={asOf} onChange={(e) => setAsOf(e.target.value)} data-testid="accounting-balance-asof" />
        </label>
      </header>
      {loading && <p>Loading…</p>}
      {error   && <p className="error">Error: {error.message}</p>}
      {data && (
        <>
          <Section title="Assets"      rows={data.assets}      total={data.total_assets}      testIdPrefix="accounting-balance-assets"      totalTestId="accounting-balance-assets-total" asOf={asOf} />
          <Section title="Liabilities" rows={data.liabilities} total={data.total_liabilities} testIdPrefix="accounting-balance-liabilities" totalTestId="accounting-balance-liabilities-total" asOf={asOf} />
          <Section title="Equity"      rows={data.equity}      total={data.total_equity}      testIdPrefix="accounting-balance-equity"      totalTestId="accounting-balance-equity-total" asOf={asOf} />
          <table className="data-table" style={{ width: '100%', marginTop: 16, borderTop: '2px solid #111' }}>
            <tbody>
              <tr style={{ fontWeight: 600 }}>
                <td>Liabilities + Equity</td>
                <td style={{ textAlign: 'right' }} data-testid="accounting-balance-le">{fmt(data.liabilities_plus_equity)}</td>
              </tr>
              <tr style={{ fontWeight: 700, fontSize: 16 }}>
                <td>Difference</td>
                <td
                  style={{ textAlign: 'right', color: data.balanced ? '#065f46' : '#991b1b' }}
                  data-testid="accounting-balance-diff"
                >
                  {data.balanced ? 'balanced' : fmt(data.total_assets - data.liabilities_plus_equity)}
                </td>
              </tr>
            </tbody>
          </table>
        </>
      )}
    </section>
  );
}

function Section({ title, rows, total, testIdPrefix, totalTestId, asOf }) {
  return (
    <div style={{ marginBottom: 12 }}>
      <h3 style={{ margin: '12px 0 4px', fontSize: 14, textTransform: 'uppercase', color: '#374151' }}>{title}</h3>
      <table className="data-table" style={{ width: '100%' }}>
        <thead><tr><th>Code</th><th>Name</th><th style={{ textAlign: 'right' }}>Amount</th></tr></thead>
        <tbody>
          {rows.length === 0 && <tr><td colSpan={3} className="empty" data-testid={`${testIdPrefix}-empty`}>None</td></tr>}
          {rows.map((r) => (
            <tr key={r.code + (r.synthetic ? '-syn' : '')} data-testid={`${testIdPrefix}-row-${r.code}`} style={r.synthetic ? { fontStyle: 'italic', color: '#6b7280' } : undefined}>
              <td>
                {r.synthetic
                  ? <code>{r.code}</code>
                  : <Link
                      to={`/modules/accounting/journal-entries?account_code=${encodeURIComponent(r.code)}&to=${asOf}`}
                      data-testid={`${testIdPrefix}-drill-${r.code}`}
                      style={{ textDecoration: 'none' }}
                    ><code>{r.code}</code></Link>}
              </td>
              <td>{r.name}{r.synthetic ? ' *' : ''}</td>
              <td style={{ textAlign: 'right' }}>{fmt(r.amount)}</td>
            </tr>
          ))}
          <tr style={{ fontWeight: 600, background: '#f9fafb' }}>
            <td colSpan={2} style={{ textAlign: 'right' }}>Total {title.toLowerCase()}</td>
            <td style={{ textAlign: 'right' }} data-testid={totalTestId || `${testIdPrefix}-total`}>{fmt(total)}</td>
          </tr>
        </tbody>
      </table>
    </div>
  );
}

function fmt(n) { return Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
