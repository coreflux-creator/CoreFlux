import React, { useMemo, useState } from 'react';
import { useApi } from '../../../dashboard/src/lib/api';

/**
 * Dimensional P&L — pivot the income statement along any active
 * accounting dimension (department, project, location, etc.).
 *
 * Columns are dimension values (with "(unset)" as a fall-through);
 * rows are revenue/expense accounts grouped by family. Family
 * subtotals + a single net-income row pin the bottom.
 */
export default function DimensionalPnL() {
  const today = new Date().toISOString().slice(0, 10);
  const yearStart = `${new Date().getFullYear()}-01-01`;
  const [dimKey, setDimKey] = useState('');
  const [start, setStart]   = useState(yearStart);
  const [end, setEnd]       = useState(today);

  const dimsApi = useApi('/modules/accounting/api/dimensions.php');
  const dims = (dimsApi.data?.rows || []).filter(d => d.active);

  const url = dimKey
    ? `/api/dimensional_pnl.php?dim_key=${encodeURIComponent(dimKey)}&start=${start}&end=${end}`
    : null;
  const { data, error, loading, reload } = useApi(url);

  const grouped = useMemo(() => {
    if (!data?.accounts) return null;
    const families = ['revenue', 'other_income', 'contra_revenue', 'cost_of_goods_sold', 'expense', 'other_expense'];
    const byFamily = {};
    families.forEach(f => byFamily[f] = []);
    data.accounts.forEach(a => {
      if (byFamily[a.account_type]) byFamily[a.account_type].push(a);
    });
    return families.map(f => ({ family: f, rows: byFamily[f] })).filter(g => g.rows.length > 0);
  }, [data]);

  return (
    <section data-testid="accounting-dim-pnl-page">
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end', flexWrap: 'wrap', gap: 12, marginBottom: 12 }}>
        <div>
          <h2 style={{ margin: 0 }}>Dimensional P&amp;L</h2>
          <p style={{ color: '#64748b', margin: '4px 0 0', fontSize: 13 }}>
            Slice the income statement along any active dimension — department, project, location, whatever you've defined.
          </p>
        </div>
        <button data-testid="accounting-dim-pnl-refresh" onClick={reload} className="btn btn--ghost" style={{ fontSize: 12 }}>Refresh</button>
      </header>

      <div style={{ display: 'flex', alignItems: 'flex-end', gap: 12, flexWrap: 'wrap', marginBottom: 14 }}>
        <label style={lbl}>Dimension
          <select className="input"
                  data-testid="accounting-dim-pnl-dim"
                  value={dimKey}
                  onChange={e => setDimKey(e.target.value)}>
            <option value="">— pick a dimension —</option>
            {dims.map(d => <option key={d.dim_key} value={d.dim_key}>{d.label} ({d.dim_key})</option>)}
          </select>
        </label>
        <label style={lbl}>Start
          <input type="date" className="input" value={start}
                 onChange={e => setStart(e.target.value)} data-testid="accounting-dim-pnl-start" />
        </label>
        <label style={lbl}>End
          <input type="date" className="input" value={end}
                 onChange={e => setEnd(e.target.value)} data-testid="accounting-dim-pnl-end" />
        </label>
      </div>

      {!dimKey && (
        <div data-testid="accounting-dim-pnl-empty-state" style={emptyHero}>
          Pick a dimension to slice the income statement.
        </div>
      )}
      {loading && <p data-testid="accounting-dim-pnl-loading">Loading…</p>}
      {error   && <p data-testid="accounting-dim-pnl-error" className="error">Error: {error.message}</p>}

      {data?.accounts && (
        <>
          <div data-testid="accounting-dim-pnl-summary"
               style={{ display: 'flex', gap: 24, padding: 14, background: '#f8fafc', border: '1px solid #e2e8f0', borderRadius: 10, marginBottom: 12, flexWrap: 'wrap' }}>
            <Stat label="Dimension" value={`${data.dim_label} (${data.dim_key})`} />
            <Stat label="Window" value={`${data.start} → ${data.end}`} />
            <Stat label="Buckets" value={String(data.dim_values.length)} testId="accounting-dim-pnl-bucket-count" />
            <Stat label="Accounts" value={String(data.count)} testId="accounting-dim-pnl-account-count" />
            <Stat label="Net income"
                  value={fmt(data.subtotals.net_income.total)}
                  bold
                  warn={data.subtotals.net_income.total < 0}
                  testId="accounting-dim-pnl-net-income-total" />
          </div>

          <div style={{ overflowX: 'auto' }}>
            <table className="data-table" style={{ width: '100%', minWidth: 600 }} data-testid="accounting-dim-pnl-table">
              <thead>
                <tr>
                  <th style={{ minWidth: 220 }}>Account</th>
                  {data.dim_values.map(v => (
                    <th key={v} style={{ textAlign: 'right', whiteSpace: 'nowrap' }}
                        data-testid={`accounting-dim-pnl-col-${slug(v)}`}>{v}</th>
                  ))}
                  <th style={{ textAlign: 'right' }}>Total</th>
                </tr>
              </thead>
              <tbody>
                {grouped.map(g => (
                  <React.Fragment key={g.family}>
                    <tr style={{ background: '#f8fafc' }}>
                      <td colSpan={data.dim_values.length + 2}
                          style={{ fontSize: 12, fontWeight: 600, color: '#475569', textTransform: 'uppercase', letterSpacing: 0.5 }}
                          data-testid={`accounting-dim-pnl-family-${g.family}`}>
                        {familyLabel(g.family)}
                      </td>
                    </tr>
                    {g.rows.map(a => (
                      <tr key={a.account_id} data-testid={`accounting-dim-pnl-row-${a.code}`}>
                        <td><code>{a.code}</code> {a.name}</td>
                        {data.dim_values.map(v => (
                          <td key={v} style={cell}>{fmt(a.per_value[v] || 0)}</td>
                        ))}
                        <td style={{ ...cell, fontWeight: 600 }}>{fmt(a.total)}</td>
                      </tr>
                    ))}
                    <tr style={{ background: '#f1f5f9' }} data-testid={`accounting-dim-pnl-subtotal-${g.family}`}>
                      <td style={{ fontStyle: 'italic', color: '#475569' }}>Subtotal {familyLabel(g.family)}</td>
                      {data.dim_values.map(v => (
                        <td key={v} style={{ ...cell, fontWeight: 600 }}>
                          {fmt(data.subtotals[g.family]?.per_value[v] || 0)}
                        </td>
                      ))}
                      <td style={{ ...cell, fontWeight: 700 }}>
                        {fmt(data.subtotals[g.family]?.total || 0)}
                      </td>
                    </tr>
                  </React.Fragment>
                ))}
                <tr style={{ background: '#ecfdf5', borderTop: '2px solid #10b981' }} data-testid="accounting-dim-pnl-net-income-row">
                  <td style={{ fontWeight: 700, color: '#065f46' }}>NET INCOME</td>
                  {data.dim_values.map(v => {
                    const x = data.subtotals.net_income.per_value[v] || 0;
                    return (
                      <td key={v}
                          style={{ ...cell, fontWeight: 700, color: x >= 0 ? '#065f46' : '#b91c1c' }}>
                        {fmt(x)}
                      </td>
                    );
                  })}
                  <td style={{ ...cell, fontWeight: 800, color: data.subtotals.net_income.total >= 0 ? '#065f46' : '#b91c1c' }}>
                    {fmt(data.subtotals.net_income.total)}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </>
      )}
    </section>
  );
}

const cell = { textAlign: 'right', fontFamily: 'ui-monospace, monospace', fontSize: 12, whiteSpace: 'nowrap' };
const lbl = { display: 'flex', flexDirection: 'column', gap: 4, fontSize: 12, color: '#475569' };
const emptyHero = { padding: 36, textAlign: 'center', color: '#64748b', background: '#f8fafc', border: '1px dashed #e2e8f0', borderRadius: 10 };

function familyLabel(f) {
  return ({
    revenue: 'Revenue',
    other_income: 'Other income',
    contra_revenue: 'Contra revenue',
    cost_of_goods_sold: 'Cost of goods sold',
    expense: 'Operating expenses',
    other_expense: 'Other expenses',
  })[f] || f;
}
function Stat({ label, value, bold, warn, testId }) {
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 2, minWidth: 120 }}>
      <span style={{ fontSize: 11, color: '#94a3b8', textTransform: 'uppercase', letterSpacing: 0.4 }}>{label}</span>
      <span data-testid={testId}
            style={{ fontFamily: 'ui-monospace, monospace', fontSize: 13, fontWeight: bold ? 700 : 400, color: warn ? '#b91c1c' : '#0f172a' }}>
        {value}
      </span>
    </div>
  );
}
function fmt(n) {
  return Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function slug(s) {
  return String(s).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'unset';
}
