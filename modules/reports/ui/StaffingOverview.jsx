import React, { useState } from 'react';
import { useApi } from '../../../dashboard/src/lib/api';
import PeriodSelector from './PeriodSelector';
import { TrendingUp, TrendingDown } from 'lucide-react';

/**
 * StaffingOverview — Reports module landing dashboard.
 * Spec: Reports.docx §Staffing Overview Dashboard.
 *   • 6 KPI tiles (Revenue, GP, GP%, Hours, OT%, Spread/hr)
 *   • Weekly Revenue + GP chart (SVG line chart, no external lib)
 *   • Headcount tiles (Active / New starts / Terminations / Net change)
 *   • Run Rate comparison (last week annualized vs first week annualized)
 *   • Timesheet Health summary
 */
export default function StaffingOverview() {
  const [period, setPeriod] = useState('4w');
  const { data, loading, error } = useApi(`/modules/reports/api/overview.php?period=${period}`);

  return (
    <section data-testid="reports-overview">
      <header style={headerStyle}>
        <h1 style={{ margin: 0, fontSize: 24 }}>Reports — Staffing Overview</h1>
        <div style={{ display: 'flex', gap: 12, alignItems: 'center' }}>
          <span style={{ fontSize: 13, color: 'var(--cf-text-muted, #6b7280)' }}>Time period</span>
          <PeriodSelector value={period} onChange={setPeriod} testid="reports-overview-period" />
        </div>
      </header>

      {error && <p className="error" data-testid="reports-overview-error">Failed to load: {String(error)}</p>}
      {loading && !data && <p className="empty">Loading dashboard…</p>}
      {data && (
        <>
          <SectionLabel testid="reports-overview-kpis-label">Period totals — {data.period.label} ({data.period.from} → {data.period.to})</SectionLabel>
          <div style={kpiGrid} data-testid="reports-overview-kpis">
            <Kpi label="Revenue"        value={fmtCurrency(data.kpis.revenue)}       testid="kpi-revenue" />
            <Kpi label="Gross Profit"   value={fmtCurrency(data.kpis.gross_profit)}  testid="kpi-gp" />
            <Kpi label="GP %"           value={`${data.kpis.gross_profit_pct}%`}     testid="kpi-gp-pct" />
            <Kpi label="Hours"          value={fmtNumber(data.kpis.hours)}           testid="kpi-hours" />
            <Kpi label="Overtime %"     value={`${data.kpis.ot_pct}%`}               testid="kpi-ot-pct" sub={`${fmtNumber(data.kpis.ot_hours)} OT hrs`} />
            <Kpi label="Spread / hr"    value={fmtCurrency(data.kpis.spread_per_hour)} testid="kpi-spread" />
          </div>

          <SectionLabel testid="reports-overview-weekly-label">Weekly Revenue &amp; GP</SectionLabel>
          <WeeklyTrend rows={data.weekly_series} testid="reports-overview-weekly" />

          <div style={twoCol}>
            <div>
              <SectionLabel testid="reports-overview-headcount-label">Headcount</SectionLabel>
              <div style={miniGrid} data-testid="reports-overview-headcount">
                <Kpi label="Active"        value={fmtNumber(data.headcount.active)}       testid="kpi-headcount-active" />
                <Kpi label="New starts"    value={fmtNumber(data.headcount.new_starts)}   testid="kpi-headcount-new" />
                <Kpi label="Terminations"  value={fmtNumber(data.headcount.terminations)} testid="kpi-headcount-term" />
                <Kpi label="Net change"    value={fmtSignedNumber(data.headcount.net_change)} testid="kpi-headcount-net" tone={data.headcount.net_change >= 0 ? 'positive' : 'negative'} />
              </div>
            </div>
            <div>
              <SectionLabel testid="reports-overview-runrate-label">Run rate (annualized)</SectionLabel>
              <div style={miniGrid} data-testid="reports-overview-runrate">
                <Kpi label="Revenue (now)"        value={fmtCurrency(data.run_rate.revenue_run_rate_now)}      testid="kpi-runrate-rev-now" />
                <Kpi label="Revenue (start)"      value={fmtCurrency(data.run_rate.revenue_run_rate_baseline)} testid="kpi-runrate-rev-base" />
                <Kpi label="GP (now)"             value={fmtCurrency(data.run_rate.gp_run_rate_now)}           testid="kpi-runrate-gp-now" />
                <Kpi
                  label="Revenue Δ"
                  value={`${data.run_rate.revenue_run_rate_delta_pct >= 0 ? '+' : ''}${data.run_rate.revenue_run_rate_delta_pct}%`}
                  testid="kpi-runrate-delta"
                  tone={data.run_rate.revenue_run_rate_delta_pct >= 0 ? 'positive' : 'negative'}
                  IconRight={data.run_rate.revenue_run_rate_delta_pct >= 0 ? TrendingUp : TrendingDown}
                />
              </div>
            </div>
          </div>

          <SectionLabel testid="reports-overview-health-label">Timesheet health</SectionLabel>
          <div style={miniGrid} data-testid="reports-overview-health">
            <Kpi label="Median approval lag" value={data.timesheet_health.median_approval_lag_hours == null ? '—' : `${data.timesheet_health.median_approval_lag_hours}h`} testid="kpi-health-lag" />
            <Kpi label="Pending review"      value={fmtNumber(data.timesheet_health.submitted_pending)} testid="kpi-health-pending" />
            <Kpi label="Approved"            value={fmtNumber(data.timesheet_health.approved)}          testid="kpi-health-approved" />
            <Kpi label="Rejected"            value={fmtNumber(data.timesheet_health.rejected)}          testid="kpi-health-rejected" />
          </div>
        </>
      )}
    </section>
  );
}

// -- Reusable sub-pieces (kept inline for the dashboard, exported for reuse if needed)
const headerStyle = {
  display: 'flex', justifyContent: 'space-between', alignItems: 'center',
  marginBottom: 'var(--cf-space-5)', paddingBottom: 'var(--cf-space-3)',
  borderBottom: '1px solid var(--cf-border, #e5e7eb)',
};
const kpiGrid = {
  display: 'grid',
  gridTemplateColumns: 'repeat(auto-fit, minmax(170px, 1fr))',
  gap: 'var(--cf-space-3)',
  marginBottom: 'var(--cf-space-5)',
};
const miniGrid = {
  display: 'grid',
  gridTemplateColumns: 'repeat(auto-fit, minmax(140px, 1fr))',
  gap: 'var(--cf-space-3)',
  marginBottom: 'var(--cf-space-5)',
};
const twoCol = { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(380px, 1fr))', gap: 'var(--cf-space-5)' };

function Kpi({ label, value, sub, testid, tone, IconRight }) {
  const color = tone === 'positive' ? '#16a34a' : tone === 'negative' ? '#dc2626' : 'var(--cf-text, #111827)';
  return (
    <div data-testid={testid} style={{
      background: 'var(--cf-surface, #fff)',
      border: '1px solid var(--cf-border, #e5e7eb)',
      borderRadius: 8,
      padding: 'var(--cf-space-4)',
    }}>
      <div style={{ fontSize: 12, color: 'var(--cf-text-muted, #6b7280)', marginBottom: 6, textTransform: 'uppercase', letterSpacing: 0.4 }}>{label}</div>
      <div style={{ display: 'flex', alignItems: 'baseline', gap: 6, color }}>
        <div style={{ fontSize: 22, fontWeight: 700 }}>{value}</div>
        {IconRight && <IconRight size={18} />}
      </div>
      {sub && <div style={{ fontSize: 12, color: 'var(--cf-text-muted, #6b7280)', marginTop: 4 }}>{sub}</div>}
    </div>
  );
}

function SectionLabel({ children, testid }) {
  return (
    <h3 data-testid={testid} style={{
      fontSize: 14, fontWeight: 600, textTransform: 'uppercase', letterSpacing: 0.5,
      color: 'var(--cf-text-muted, #6b7280)',
      margin: '0 0 var(--cf-space-3) 0',
    }}>{children}</h3>
  );
}

function WeeklyTrend({ rows, testid }) {
  if (!rows || rows.length === 0) {
    return <p className="empty" data-testid={`${testid}-empty`}>No weekly data in this range.</p>;
  }
  const w = 760, h = 220, pad = 32;
  const maxRev = Math.max(1, ...rows.map(r => r.revenue));
  const xs = (i) => pad + (i * (w - 2 * pad)) / Math.max(1, rows.length - 1);
  const yRev = (v) => h - pad - (v / maxRev) * (h - 2 * pad);
  const yGp  = (v) => h - pad - (Math.max(0, v) / maxRev) * (h - 2 * pad);
  const pathRev = rows.map((r, i) => `${i === 0 ? 'M' : 'L'}${xs(i)},${yRev(r.revenue)}`).join(' ');
  const pathGp  = rows.map((r, i) => `${i === 0 ? 'M' : 'L'}${xs(i)},${yGp(r.gross_profit)}`).join(' ');

  return (
    <div data-testid={testid} style={{ background: 'var(--cf-surface, #fff)', border: '1px solid var(--cf-border, #e5e7eb)', borderRadius: 8, padding: 'var(--cf-space-4)', marginBottom: 'var(--cf-space-5)' }}>
      <svg width="100%" viewBox={`0 0 ${w} ${h}`} role="img" aria-label="Weekly revenue and gross profit">
        <line x1={pad} y1={h - pad} x2={w - pad} y2={h - pad} stroke="#e5e7eb" />
        <line x1={pad} y1={pad} x2={pad} y2={h - pad} stroke="#e5e7eb" />
        <path d={pathRev} fill="none" stroke="#2563eb" strokeWidth="2" data-testid="reports-weekly-rev-line" />
        <path d={pathGp}  fill="none" stroke="#16a34a" strokeWidth="2" data-testid="reports-weekly-gp-line" />
        {rows.map((r, i) => (
          <g key={r.week_start}>
            <circle cx={xs(i)} cy={yRev(r.revenue)} r="3" fill="#2563eb" />
            <circle cx={xs(i)} cy={yGp(r.gross_profit)} r="3" fill="#16a34a" />
          </g>
        ))}
        {rows.map((r, i) => (
          i % Math.max(1, Math.floor(rows.length / 8)) === 0 ? (
            <text key={`x-${r.week_start}`} x={xs(i)} y={h - pad + 14} fontSize="10" textAnchor="middle" fill="#6b7280">
              {r.week_start.slice(5)}
            </text>
          ) : null
        ))}
      </svg>
      <div style={{ display: 'flex', gap: 16, marginTop: 8, fontSize: 12, color: '#6b7280' }}>
        <span><span style={{ display: 'inline-block', width: 10, height: 10, background: '#2563eb', marginRight: 6, borderRadius: 2 }} />Revenue</span>
        <span><span style={{ display: 'inline-block', width: 10, height: 10, background: '#16a34a', marginRight: 6, borderRadius: 2 }} />Gross Profit</span>
      </div>
    </div>
  );
}

function fmtCurrency(n) { return `$${Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 2, minimumFractionDigits: 0 })}`; }
function fmtNumber(n)   { return Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 2 }); }
function fmtSignedNumber(n) { const v = Number(n || 0); return (v > 0 ? '+' : '') + v.toLocaleString(); }
