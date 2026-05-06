import React, { useState } from 'react';
import { useApi } from '../../../dashboard/src/lib/api';
import PeriodSelector from './PeriodSelector';
import { AlertTriangle } from 'lucide-react';

/**
 * Client Profitability — per-client margin breakdown.
 * Spec: Reports.docx §Client Profitability.
 */
export default function ClientProfitability() {
  const [period, setPeriod] = useState('4w');
  const { data, loading, error } = useApi(`/modules/reports/api/client_profitability.php?period=${period}`);

  return (
    <section data-testid="reports-client-profitability">
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 'var(--cf-space-5)', paddingBottom: 'var(--cf-space-3)', borderBottom: '1px solid var(--cf-border, #e5e7eb)' }}>
        <div>
          <h1 style={{ margin: 0, fontSize: 24 }}>Client Profitability</h1>
          {data && <div style={{ color: '#6b7280', fontSize: 13, marginTop: 4 }}>{data.period.label} • {data.period.from} → {data.period.to}</div>}
        </div>
        <PeriodSelector value={period} onChange={setPeriod} testid="reports-client-period" />
      </header>

      {error && <p className="error" data-testid="reports-client-error">Failed: {String(error)}</p>}
      {loading && !data && <p className="empty">Loading…</p>}

      {data && data.alerts && data.alerts.length > 0 && (
        <div data-testid="reports-client-alerts" style={{
          background: '#fef3c7', border: '1px solid #f59e0b', borderRadius: 8,
          padding: 'var(--cf-space-3)', marginBottom: 'var(--cf-space-4)',
          display: 'flex', alignItems: 'center', gap: 8, fontSize: 13,
        }}>
          <AlertTriangle size={18} color="#b45309" />
          <span><strong>{data.alerts.length}</strong> client{data.alerts.length > 1 ? 's' : ''} with GP &lt; 20% — review pricing.</span>
        </div>
      )}

      {data && (
        <table className="data-table" data-testid="reports-client-table" style={{ width: '100%', background: 'var(--cf-surface, #fff)', border: '1px solid var(--cf-border, #e5e7eb)', borderRadius: 8 }}>
          <thead>
            <tr>
              <th>Client</th>
              <th style={tdRight}>Revenue</th>
              <th style={tdRight}>Pay Cost</th>
              <th style={tdRight}>GP</th>
              <th style={tdRight}>GP %</th>
              <th style={tdRight}>Hours</th>
              <th style={tdRight}>OT %</th>
              <th style={tdRight}>Spread/hr</th>
              <th style={tdRight}>Avg Bill</th>
              <th style={tdRight}>Avg Pay</th>
            </tr>
          </thead>
          <tbody>
            {data.rows.length === 0 && (
              <tr><td colSpan="10" className="empty" style={{ textAlign: 'center', padding: 24 }}>No data in this range.</td></tr>
            )}
            {data.rows.map((r, idx) => (
              <tr key={r.client + idx} data-testid={`reports-client-row-${idx}`}>
                <td>{r.client}</td>
                <td style={tdRight}>{fmtCurrency(r.revenue)}</td>
                <td style={tdRight}>{fmtCurrency(r.cost)}</td>
                <td style={tdRight}>{fmtCurrency(r.gross_profit)}</td>
                <td style={{ ...tdRight, color: r.gross_profit_pct < 20 ? '#dc2626' : 'inherit', fontWeight: r.gross_profit_pct < 20 ? 600 : 400 }}>{r.gross_profit_pct}%</td>
                <td style={tdRight}>{fmtNum(r.hours)}</td>
                <td style={tdRight}>{r.ot_pct}%</td>
                <td style={tdRight}>{fmtCurrency(r.spread_per_hour)}</td>
                <td style={tdRight}>{fmtCurrency(r.avg_bill_rate)}</td>
                <td style={tdRight}>{fmtCurrency(r.avg_pay_rate)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </section>
  );
}

const tdRight = { textAlign: 'right' };
function fmtCurrency(n) { return `$${Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 2 })}`; }
function fmtNum(n)      { return Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 2 }); }
