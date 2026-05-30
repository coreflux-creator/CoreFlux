/**
 * Overtime Watch — Reports Overhaul Pass 2 (Tier-2).
 *
 * OT exposure + cost leakage. Wraps the existing
 * /modules/reports/api/overtime_watch.php response in ReportShell with
 * a 6-KPI band (OT hrs, total hrs, OT%, OT revenue, OT cost, OT margin)
 * plus three drill tables (weekly OT, top employees, top clients).
 *
 * Spec: Reports.docx §Overtime Watch.
 */
import React, { useState } from 'react';
import { useApi } from '../../../dashboard/src/lib/api';
import PeriodSelector from './PeriodSelector';
import ReportShell from '../../../dashboard/src/components/ReportShell';
import MetricCard from '../../../dashboard/src/components/MetricCard';
import { fmtMoney } from '../../../dashboard/src/lib/format';

export default function OvertimeWatch() {
  const [period, setPeriod] = useState('4w');
  const { data, loading, error } = useApi(
    `/modules/reports/api/overtime_watch.php?period=${period}`
  );

  const t = data?.totals || {};
  const otSeries = (data?.weekly_ot || []).map(w => ({ week: w.week_start, amount: Number(w.ot_pct) || 0 }));

  return (
    <ReportShell
      title="Overtime Watch"
      subtitle={data
        ? `${data.period.label} · ${data.period.from} → ${data.period.to}`
        : 'OT exposure and cost leakage'}
      testIdPrefix="rpt-ot"
      customControls={(
        <PeriodSelector value={period} onChange={setPeriod} testid="rpt-ot-period" />
      )}
      kpis={data && (
        <>
          <MetricCard label="OT hours"
                      testIdPrefix="rpt-ot-kpi-hours"
                      value={t.ot_hours} format={(n) => Number(n).toLocaleString(undefined, { maximumFractionDigits: 1 })}
                      sparkline={otSeries}
                      tone={Number(t.ot_pct) > 15 ? 'negative' : 'neutral'} />
          <MetricCard label="Total hours"
                      testIdPrefix="rpt-ot-kpi-total-hours"
                      value={t.total_hours}
                      format={(n) => Number(n).toLocaleString(undefined, { maximumFractionDigits: 1 })} />
          <MetricCard label="OT %"
                      testIdPrefix="rpt-ot-kpi-pct"
                      value={`${t.ot_pct ?? '—'}%`}
                      tone={Number(t.ot_pct) > 15 ? 'negative' : 'positive'} />
          <MetricCard label="OT revenue"
                      testIdPrefix="rpt-ot-kpi-revenue"
                      value={t.ot_revenue} format={fmtMoney} tone="positive" />
          <MetricCard label="OT cost"
                      testIdPrefix="rpt-ot-kpi-cost"
                      value={t.ot_cost} format={fmtMoney} inverse />
          <MetricCard label="OT margin"
                      testIdPrefix="rpt-ot-kpi-margin"
                      value={t.ot_margin} format={fmtMoney}
                      tone={Number(t.ot_margin) >= 0 ? 'positive' : 'negative'} />
        </>
      )}
    >
      {error && <p className="error" data-testid="rpt-ot-error">Failed: {String(error)}</p>}
      {loading && !data && <p data-testid="rpt-ot-loading">Loading…</p>}

      {data && (
        <>
          <Block title="Weekly OT %" testIdPrefix="rpt-ot-weekly">
            <table data-testid="rpt-ot-weekly-table" style={tableStyle}>
              <thead><tr>
                <th style={th}>Week start</th>
                <th style={thR}>OT hrs</th>
                <th style={thR}>Total hrs</th>
                <th style={thR}>OT %</th>
              </tr></thead>
              <tbody>
                {data.weekly_ot.length === 0 && (
                  <tr><td colSpan="4" style={emptyStyle} data-testid="rpt-ot-weekly-empty">No data.</td></tr>
                )}
                {data.weekly_ot.map(w => {
                  const hot = Number(w.ot_pct) > 15;
                  return (
                    <tr key={w.week_start}
                        data-testid={`rpt-ot-week-${w.week_start}`}
                        style={{ borderTop: '1px solid #f1f5f9' }}>
                      <td style={td}>{w.week_start}</td>
                      <td style={tdR}>{w.ot_hours}</td>
                      <td style={tdR}>{w.total_hours}</td>
                      <td style={{ ...tdR, color: hot ? '#dc2626' : '#0f172a', fontWeight: hot ? 700 : 400 }}>{w.ot_pct}%</td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </Block>

          <div style={twoColStyle}>
            <Block title="Top employees by OT %" testIdPrefix="rpt-ot-employees">
              <table data-testid="rpt-ot-employees-table" style={tableStyle}>
                <thead><tr>
                  <th style={th}>Worker</th>
                  <th style={thR}>Total hrs</th>
                  <th style={thR}>OT hrs</th>
                  <th style={thR}>OT %</th>
                </tr></thead>
                <tbody>
                  {data.top_employees.length === 0 && (
                    <tr><td colSpan="4" style={emptyStyle}>No OT this period.</td></tr>
                  )}
                  {data.top_employees.map(e => (
                    <tr key={e.employee_id}
                        data-testid={`rpt-ot-emp-${e.employee_id}`}
                        style={{ borderTop: '1px solid #f1f5f9' }}>
                      <td style={td}>{e.worker || '(unknown)'}</td>
                      <td style={tdR}>{e.total_hours}</td>
                      <td style={tdR}>{e.ot_hours}</td>
                      <td style={tdR}>{e.ot_pct}%</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </Block>
            <Block title="Top clients by OT hours" testIdPrefix="rpt-ot-clients">
              <table data-testid="rpt-ot-clients-table" style={tableStyle}>
                <thead><tr>
                  <th style={th}>Client</th>
                  <th style={thR}>OT hrs</th>
                  <th style={thR}>OT margin</th>
                </tr></thead>
                <tbody>
                  {data.top_clients.length === 0 && (
                    <tr><td colSpan="3" style={emptyStyle}>No OT this period.</td></tr>
                  )}
                  {data.top_clients.map((c, idx) => (
                    <tr key={c.client + idx}
                        data-testid={`rpt-ot-client-${idx}`}
                        style={{ borderTop: '1px solid #f1f5f9' }}>
                      <td style={td}>{c.client}</td>
                      <td style={tdR}>{c.ot_hours}</td>
                      <td style={tdR}>{fmtMoney(c.ot_margin)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </Block>
          </div>
        </>
      )}
    </ReportShell>
  );
}

function Block({ title, testIdPrefix, children }) {
  return (
    <div>
      <h3 style={sectLabel} data-testid={`${testIdPrefix}-title`}>{title}</h3>
      {children}
    </div>
  );
}

const sectLabel = {
  margin: '0 0 8px', fontSize: 12, fontWeight: 700,
  color: '#475569', textTransform: 'uppercase', letterSpacing: 0.5,
};
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
const emptyStyle = { textAlign: 'center', padding: 16, color: '#94a3b8' };
const twoColStyle = {
  display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(380px, 1fr))',
  gap: 20,
};
