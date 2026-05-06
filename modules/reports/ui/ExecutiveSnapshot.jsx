import React, { useState } from 'react';
import { useApi } from '../../../dashboard/src/lib/api';
import PeriodSelector from './PeriodSelector';

/**
 * Executive Snapshot — printable one-page leadership summary.
 * Spec: Reports.docx §Executive Snapshot.
 */
export default function ExecutiveSnapshot() {
  const [period, setPeriod] = useState('4w');
  const { data, loading, error } = useApi(`/modules/reports/api/executive_snapshot.php?period=${period}`);

  const handlePrint = () => window.print();

  return (
    <section data-testid="reports-executive">
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 'var(--cf-space-5)', paddingBottom: 'var(--cf-space-3)', borderBottom: '1px solid var(--cf-border, #e5e7eb)' }}>
        <div>
          <h1 style={{ margin: 0, fontSize: 24 }}>Executive Snapshot</h1>
          {data && <div style={{ color: '#6b7280', fontSize: 13, marginTop: 4 }}>{data.period.label} • {data.period.from} → {data.period.to}</div>}
        </div>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
          <PeriodSelector value={period} onChange={setPeriod} testid="reports-exec-period" />
          <button className="btn btn--ghost" onClick={handlePrint} data-testid="reports-exec-print">Print / Save PDF</button>
        </div>
      </header>

      {error && <p className="error" data-testid="reports-exec-error">Failed to load: {String(error)}</p>}
      {loading && !data && <p className="empty">Loading…</p>}
      {data && (
        <div data-testid="reports-exec-snapshot" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 'var(--cf-space-4)' }}>
          <Tile label="Revenue"            value={fmtCurrency(data.snapshot.revenue)} testid="exec-revenue" />
          <Tile label="Gross Profit"       value={fmtCurrency(data.snapshot.gross_profit)} testid="exec-gp" />
          <Tile label="GP %"               value={`${data.snapshot.gross_profit_pct}%`} testid="exec-gp-pct" />
          <Tile label="Hours"              value={fmtNum(data.snapshot.hours)} testid="exec-hours" />
          <Tile label="Overtime %"         value={`${data.snapshot.ot_pct}%`} sub={`${fmtNum(data.snapshot.ot_hours)} OT hrs`} testid="exec-ot" />
          <Tile label="Spread / hr"        value={fmtCurrency(data.snapshot.spread_per_hour)} testid="exec-spread" />
          <Tile label="Active headcount"   value={fmtNum(data.snapshot.headcount_active)} testid="exec-headcount" />
          <Tile label="New starts"         value={fmtNum(data.snapshot.new_starts)} testid="exec-new-starts" />
          <Tile label="Terminations"       value={fmtNum(data.snapshot.terminations)} testid="exec-terminations" />
          <Tile label="Net headcount Δ"    value={fmtSigned(data.snapshot.net_headcount_change)} testid="exec-net" tone={data.snapshot.net_headcount_change >= 0 ? 'positive' : 'negative'} />
          <Tile label="Revenue run rate"   value={fmtCurrency(data.snapshot.revenue_run_rate_now)} sub={`Δ ${data.snapshot.revenue_run_rate_delta_pct}%`} testid="exec-rev-runrate" />
          <Tile label="GP run rate"        value={fmtCurrency(data.snapshot.gp_run_rate_now)} sub={`Δ ${data.snapshot.gp_run_rate_delta_pct}%`} testid="exec-gp-runrate" />
          <Tile label="Median approval lag" value={data.snapshot.median_approval_lag_hours == null ? '—' : `${data.snapshot.median_approval_lag_hours}h`} testid="exec-lag" />
          <Tile label="Pending review"     value={fmtNum(data.snapshot.submitted_pending)} testid="exec-pending" />
          <Tile label="Approved entries"   value={fmtNum(data.snapshot.approved)} testid="exec-approved" />
          <Tile label="Rejected entries"   value={fmtNum(data.snapshot.rejected)} testid="exec-rejected" />
        </div>
      )}
    </section>
  );
}

function Tile({ label, value, sub, testid, tone }) {
  const color = tone === 'positive' ? '#16a34a' : tone === 'negative' ? '#dc2626' : 'var(--cf-text, #111827)';
  return (
    <div data-testid={testid} style={{
      border: '1px solid var(--cf-border, #e5e7eb)', borderRadius: 8, padding: 'var(--cf-space-4)', background: 'var(--cf-surface, #fff)',
    }}>
      <div style={{ fontSize: 12, color: '#6b7280', textTransform: 'uppercase', letterSpacing: 0.4, marginBottom: 4 }}>{label}</div>
      <div style={{ fontSize: 22, fontWeight: 700, color }}>{value}</div>
      {sub && <div style={{ fontSize: 12, color: '#6b7280', marginTop: 4 }}>{sub}</div>}
    </div>
  );
}

function fmtCurrency(n) { return `$${Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 2 })}`; }
function fmtNum(n)      { return Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 2 }); }
function fmtSigned(n)   { const v = Number(n || 0); return (v > 0 ? '+' : '') + v.toLocaleString(); }
