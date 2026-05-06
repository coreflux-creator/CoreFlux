import React, { useState } from 'react';
import { useApi } from '../../../dashboard/src/lib/api';
import PeriodSelector from './PeriodSelector';

/**
 * Rate & Spread Monitor — per-placement profitability table.
 * Spec: Reports.docx §Rate & Spread Monitor.
 *   Flags negative-spread + low-spread placements.
 */
export default function RateSpreadMonitor() {
  const [period, setPeriod] = useState('4w');
  const { data, loading, error } = useApi(`/modules/reports/api/rate_spread.php?period=${period}`);

  return (
    <section data-testid="reports-rate-spread">
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 'var(--cf-space-5)', paddingBottom: 'var(--cf-space-3)', borderBottom: '1px solid var(--cf-border, #e5e7eb)' }}>
        <div>
          <h1 style={{ margin: 0, fontSize: 24 }}>Rate &amp; Spread Monitor</h1>
          {data && <div style={{ color: '#6b7280', fontSize: 13, marginTop: 4 }}>{data.period.label} • {data.period.from} → {data.period.to}</div>}
        </div>
        <PeriodSelector value={period} onChange={setPeriod} testid="reports-rate-spread-period" />
      </header>

      {error && <p className="error" data-testid="reports-rate-spread-error">Failed: {String(error)}</p>}
      {loading && !data && <p className="empty">Loading…</p>}

      {data && (
        <table className="data-table" data-testid="reports-rate-spread-table" style={{ width: '100%', background: 'var(--cf-surface, #fff)', border: '1px solid var(--cf-border, #e5e7eb)', borderRadius: 8 }}>
          <thead>
            <tr>
              <th>Placement</th>
              <th>Worker</th>
              <th>Client</th>
              <th>Type</th>
              <th style={tdR}>Eff. Bill</th>
              <th style={tdR}>Eff. Pay</th>
              <th style={tdR}>Spread/hr</th>
              <th style={tdR}>GP %</th>
              <th style={tdR}>Revenue</th>
              <th style={tdR}>Hours</th>
              <th>Flag</th>
            </tr>
          </thead>
          <tbody>
            {data.rows.length === 0 && (
              <tr><td colSpan="11" className="empty" style={{ textAlign: 'center', padding: 24 }}>No placements with hours in this range.</td></tr>
            )}
            {data.rows.map((r) => (
              <tr key={r.placement_id} data-testid={`reports-rate-spread-row-${r.placement_id}`}>
                <td>{r.title}</td>
                <td>{r.worker || '—'}</td>
                <td>{r.client || '—'}</td>
                <td>{r.engagement_type}</td>
                <td style={tdR}>${r.effective_bill_rate}</td>
                <td style={tdR}>${r.effective_pay_rate}</td>
                <td style={{ ...tdR, color: r.spread_per_hour < 0 ? '#dc2626' : (r.spread_per_hour < 10 ? '#b45309' : 'inherit'), fontWeight: r.spread_per_hour < 10 ? 600 : 400 }}>${r.spread_per_hour}</td>
                <td style={tdR}>{r.gross_profit_pct}%</td>
                <td style={tdR}>${Number(r.revenue).toLocaleString(undefined, { maximumFractionDigits: 2 })}</td>
                <td style={tdR}>{r.hours}</td>
                <td>{r.flag ? <FlagBadge flag={r.flag} /> : <span style={{ color: '#6b7280' }}>—</span>}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </section>
  );
}

const tdR = { textAlign: 'right' };

function FlagBadge({ flag }) {
  const map = {
    negative_spread: { bg: '#fee2e2', fg: '#991b1b', label: 'Negative spread' },
    low_spread:      { bg: '#fef3c7', fg: '#92400e', label: 'Low spread' },
  };
  const m = map[flag] || { bg: '#e5e7eb', fg: '#374151', label: flag };
  return <span style={{ background: m.bg, color: m.fg, padding: '2px 8px', borderRadius: 12, fontSize: 11, fontWeight: 600 }}>{m.label}</span>;
}
