/**
 * Rate & Spread Monitor — Reports Overhaul Pass 2 (Tier-2).
 *
 * Per-placement profitability table flagging negative-spread and
 * low-spread placements. Wrapped in ReportShell with a KPI band
 * (active placements, avg spread/hr, flagged count, total revenue).
 *
 * Spec: Reports.docx §Rate & Spread Monitor.
 */
import React, { useState } from 'react';
import { useApi } from '../../../dashboard/src/lib/api';
import PeriodSelector from './PeriodSelector';
import ReportShell from '../../../dashboard/src/components/ReportShell';
import MetricCard from '../../../dashboard/src/components/MetricCard';
import { fmtMoney } from '../../../dashboard/src/lib/format';

export default function RateSpreadMonitor() {
  const [period, setPeriod] = useState('4w');
  const { data, loading, error } = useApi(
    `/api/v1/reports/rate-spread?period=${period}`
  );

  const rows = data?.rows || [];
  const flagged = rows.filter(r => !!r.flag);
  const totRevenue = rows.reduce((s, r) => s + (Number(r.revenue) || 0), 0);
  const totHours   = rows.reduce((s, r) => s + (Number(r.hours)   || 0), 0);
  const totSpread  = rows.reduce((s, r) => s + (Number(r.spread_per_hour) || 0) * (Number(r.hours) || 0), 0);
  const blendedSpread = totHours > 0 ? totSpread / totHours : 0;

  return (
    <ReportShell
      title="Rate & Spread Monitor"
      subtitle={data
        ? `${data.period.label} · ${data.period.from} → ${data.period.to}`
        : 'Per-placement profitability with flagging'}
      testIdPrefix="rpt-spread"
      customControls={(
        <PeriodSelector value={period} onChange={setPeriod} testid="rpt-spread-period" />
      )}
      kpis={data && (
        <>
          <MetricCard label="Active placements"
                      testIdPrefix="rpt-spread-kpi-placements"
                      value={rows.length}
                      format={(n) => Number(n).toLocaleString()} />
          <MetricCard label="Blended spread / hr"
                      testIdPrefix="rpt-spread-kpi-spread"
                      value={blendedSpread} format={fmtMoney}
                      tone={blendedSpread >= 10 ? 'positive' : 'negative'} />
          <MetricCard label="Flagged"
                      testIdPrefix="rpt-spread-kpi-flagged"
                      value={flagged.length}
                      tone={flagged.length > 0 ? 'negative' : 'positive'} />
          <MetricCard label="Revenue"
                      testIdPrefix="rpt-spread-kpi-revenue"
                      value={totRevenue} format={fmtMoney} tone="positive" />
        </>
      )}
    >
      {error && <p className="error" data-testid="rpt-spread-error">Failed: {String(error)}</p>}
      {loading && !data && <p data-testid="rpt-spread-loading">Loading…</p>}

      {data && (
        <table data-testid="rpt-spread-table" style={tableStyle}>
          <thead>
            <tr>
              <th style={th}>Placement</th>
              <th style={th}>Worker</th>
              <th style={th}>Client</th>
              <th style={th}>Type</th>
              <th style={thR}>Eff. bill</th>
              <th style={thR}>Eff. pay</th>
              <th style={thR}>Spread/hr</th>
              <th style={thR}>GP %</th>
              <th style={thR}>Revenue</th>
              <th style={thR}>Hours</th>
              <th style={th}>Flag</th>
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 && (
              <tr><td colSpan="11" style={emptyStyle} data-testid="rpt-spread-empty">
                No placements with hours in this range.
              </td></tr>
            )}
            {rows.map((r) => {
              const sp = Number(r.spread_per_hour);
              const spColor = sp < 0 ? '#dc2626' : (sp < 10 ? '#b45309' : '#0f172a');
              return (
                <tr key={r.placement_id}
                    data-testid={`rpt-spread-row-${r.placement_id}`}
                    style={{ borderTop: '1px solid #f1f5f9' }}>
                  <td style={td}>{r.title}</td>
                  <td style={td}>{r.worker || '—'}</td>
                  <td style={td}>{r.client || '—'}</td>
                  <td style={td}>{r.engagement_type}</td>
                  <td style={tdR}>{fmtMoney(r.effective_bill_rate)}</td>
                  <td style={tdR}>{fmtMoney(r.effective_pay_rate)}</td>
                  <td style={{ ...tdR, color: spColor, fontWeight: sp < 10 ? 700 : 400 }}>
                    {fmtMoney(r.spread_per_hour)}
                  </td>
                  <td style={tdR}>{r.gross_profit_pct}%</td>
                  <td style={tdR}>{fmtMoney(r.revenue)}</td>
                  <td style={tdR}>{r.hours}</td>
                  <td style={td}>{r.flag ? <FlagBadge flag={r.flag} /> : <span style={{ color: '#94a3b8' }}>—</span>}</td>
                </tr>
              );
            })}
          </tbody>
        </table>
      )}
    </ReportShell>
  );
}

function FlagBadge({ flag }) {
  const map = {
    negative_spread: { bg: '#fee2e2', fg: '#991b1b', label: 'Negative spread' },
    low_spread:      { bg: '#fef3c7', fg: '#92400e', label: 'Low spread'      },
  };
  const m = map[flag] || { bg: '#e5e7eb', fg: '#374151', label: flag };
  return (
    <span data-testid={`rpt-spread-flag-${flag}`}
          style={{ background: m.bg, color: m.fg,
                   padding: '2px 8px', borderRadius: 12,
                   fontSize: 11, fontWeight: 600 }}>
      {m.label}
    </span>
  );
}

const tableStyle = {
  width: '100%', background: '#fff', border: '1px solid #e2e8f0',
  borderRadius: 6, borderCollapse: 'collapse', fontSize: 13,
};
const th  = { textAlign: 'left', padding: '8px 10px', fontSize: 10, fontWeight: 700,
              color: '#475569', textTransform: 'uppercase', letterSpacing: 0.4,
              borderBottom: '1px solid #e2e8f0' };
const thR = { ...th, textAlign: 'right' };
const td  = { padding: '8px 10px', color: '#1e293b' };
const tdR = { ...td, textAlign: 'right', fontVariantNumeric: 'tabular-nums' };
const emptyStyle = { textAlign: 'center', padding: 24, color: '#94a3b8' };
