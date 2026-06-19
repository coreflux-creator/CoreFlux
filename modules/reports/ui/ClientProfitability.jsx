/**
 * Client Profitability — Reports Overhaul Pass 2 (Tier-2).
 *
 * Per-client margin breakdown. Wraps the existing
 * /api/v1/reports/client-profitability response in the
 * shared ReportShell with a KPI band (totals + alert count + median
 * GP%) and a ComparisonTable for the per-client breakdown.
 *
 * Spec: Reports.docx §Client Profitability.
 */
import React, { useState } from 'react';
import { useApi } from '../../../dashboard/src/lib/api';
import PeriodSelector from './PeriodSelector';
import ReportShell from '../../../dashboard/src/components/ReportShell';
import MetricCard from '../../../dashboard/src/components/MetricCard';
import { AlertTriangle } from 'lucide-react';
import { fmtMoney } from '../../../dashboard/src/lib/format';

export default function ClientProfitability() {
  const [period, setPeriod] = useState('4w');
  const { data, loading, error } = useApi(
    `/api/v1/reports/client-profitability?period=${period}`
  );

  const rows = data?.rows || [];

  // Aggregate totals from the per-client rows for the KPI band.
  const totals = rows.reduce((acc, r) => ({
    revenue: acc.revenue + (Number(r.revenue) || 0),
    cost:    acc.cost    + (Number(r.cost)    || 0),
    gp:      acc.gp      + (Number(r.gross_profit) || 0),
    hours:   acc.hours   + (Number(r.hours)   || 0),
  }), { revenue: 0, cost: 0, gp: 0, hours: 0 });
  const blendedGpPct = totals.revenue > 0 ? (totals.gp / totals.revenue * 100) : 0;
  const flaggedCount = (data?.alerts || []).length;

  return (
    <ReportShell
      title="Client Profitability"
      subtitle={data
        ? `${data.period.label} · ${data.period.from} → ${data.period.to}`
        : 'Per-client margin breakdown'}
      testIdPrefix="rpt-clientprof"
      customControls={(
        <PeriodSelector value={period} onChange={setPeriod} testid="rpt-clientprof-period" />
      )}
      kpis={data && (
        <>
          <MetricCard label="Revenue"
                      testIdPrefix="rpt-clientprof-kpi-revenue"
                      value={totals.revenue} format={fmtMoney} tone="positive" />
          <MetricCard label="Gross profit"
                      testIdPrefix="rpt-clientprof-kpi-gp"
                      value={totals.gp} format={fmtMoney}
                      tone={blendedGpPct >= 20 ? 'positive' : 'negative'} />
          <MetricCard label="Blended GP %"
                      testIdPrefix="rpt-clientprof-kpi-gp-pct"
                      value={`${blendedGpPct.toFixed(1)}%`}
                      tone={blendedGpPct >= 20 ? 'positive' : 'negative'} />
          <MetricCard label="Clients flagged"
                      testIdPrefix="rpt-clientprof-kpi-flagged"
                      value={flaggedCount}
                      tone={flaggedCount > 0 ? 'negative' : 'positive'} />
        </>
      )}
    >
      {error && <p className="error" data-testid="rpt-clientprof-error">Failed: {String(error)}</p>}
      {loading && !data && <p data-testid="rpt-clientprof-loading">Loading…</p>}

      {data && data.alerts && data.alerts.length > 0 && (
        <div data-testid="rpt-clientprof-alerts" style={alertStyle}>
          <AlertTriangle size={18} color="#b45309" />
          <span><strong>{data.alerts.length}</strong> client{data.alerts.length > 1 ? 's' : ''} with GP &lt; 20% — review pricing.</span>
        </div>
      )}

      {data && (
        <table data-testid="rpt-clientprof-table" style={tableStyle}>
          <thead>
            <tr>
              <th style={th}>Client</th>
              <th style={thR}>Revenue</th>
              <th style={thR}>Pay cost</th>
              <th style={thR}>GP</th>
              <th style={thR}>GP %</th>
              <th style={thR}>Hours</th>
              <th style={thR}>OT %</th>
              <th style={thR}>Spread/hr</th>
              <th style={thR}>Avg bill</th>
              <th style={thR}>Avg pay</th>
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 && (
              <tr><td colSpan="10" style={emptyStyle}
                     data-testid="rpt-clientprof-empty">No data in this range.</td></tr>
            )}
            {rows.map((r, idx) => {
              const low = Number(r.gross_profit_pct) < 20;
              return (
                <tr key={r.client + idx}
                    data-testid={`rpt-clientprof-row-${idx}`}
                    style={{ borderTop: '1px solid #f1f5f9' }}>
                  <td style={td}>{r.client}</td>
                  <td style={tdR}>{fmtMoney(r.revenue)}</td>
                  <td style={tdR}>{fmtMoney(r.cost)}</td>
                  <td style={tdR}>{fmtMoney(r.gross_profit)}</td>
                  <td style={{ ...tdR, color: low ? '#dc2626' : '#0f172a', fontWeight: low ? 700 : 400 }}>
                    {r.gross_profit_pct}%
                  </td>
                  <td style={tdR}>{Number(r.hours || 0).toLocaleString(undefined, { maximumFractionDigits: 2 })}</td>
                  <td style={tdR}>{r.ot_pct}%</td>
                  <td style={tdR}>{fmtMoney(r.spread_per_hour)}</td>
                  <td style={tdR}>{fmtMoney(r.avg_bill_rate)}</td>
                  <td style={tdR}>{fmtMoney(r.avg_pay_rate)}</td>
                </tr>
              );
            })}
          </tbody>
        </table>
      )}
    </ReportShell>
  );
}

const tableStyle = {
  width: '100%', background: '#fff', border: '1px solid #e2e8f0',
  borderRadius: 6, borderCollapse: 'collapse', fontSize: 13,
};
const th = {
  textAlign: 'left', padding: '8px 10px', fontSize: 10, fontWeight: 700,
  color: '#475569', textTransform: 'uppercase', letterSpacing: 0.4,
  borderBottom: '1px solid #e2e8f0',
};
const thR = { ...th, textAlign: 'right' };
const td  = { padding: '8px 10px', color: '#1e293b' };
const tdR = { ...td, textAlign: 'right', fontVariantNumeric: 'tabular-nums' };
const emptyStyle = { textAlign: 'center', padding: 24, color: '#94a3b8' };
const alertStyle = {
  background: '#fef3c7', border: '1px solid #f59e0b',
  borderLeft: '3px solid #f59e0b', borderRadius: 4,
  padding: '10px 14px', display: 'flex', alignItems: 'center', gap: 8, fontSize: 13,
};
