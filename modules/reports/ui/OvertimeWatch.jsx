import React, { useState } from 'react';
import { useApi } from '../../../dashboard/src/lib/api';
import PeriodSelector from './PeriodSelector';

/**
 * Overtime Watch — OT exposure + cost leakage.
 * Spec: Reports.docx §Overtime Watch.
 */
export default function OvertimeWatch() {
  const [period, setPeriod] = useState('4w');
  const { data, loading, error } = useApi(`/modules/reports/api/overtime_watch.php?period=${period}`);

  return (
    <section data-testid="reports-overtime">
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 'var(--cf-space-5)', paddingBottom: 'var(--cf-space-3)', borderBottom: '1px solid var(--cf-border, #e5e7eb)' }}>
        <div>
          <h1 style={{ margin: 0, fontSize: 24 }}>Overtime Watch</h1>
          {data && <div style={{ color: '#6b7280', fontSize: 13, marginTop: 4 }}>{data.period.label} • {data.period.from} → {data.period.to}</div>}
        </div>
        <PeriodSelector value={period} onChange={setPeriod} testid="reports-overtime-period" />
      </header>

      {error && <p className="error" data-testid="reports-overtime-error">Failed: {String(error)}</p>}
      {loading && !data && <p className="empty">Loading…</p>}

      {data && (
        <>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(170px, 1fr))', gap: 'var(--cf-space-3)', marginBottom: 'var(--cf-space-5)' }} data-testid="reports-overtime-totals">
            <Tile label="OT Hours"     value={fmtNum(data.totals.ot_hours)}      testid="ot-hours" />
            <Tile label="Total Hours"  value={fmtNum(data.totals.total_hours)}   testid="ot-total-hours" />
            <Tile label="OT %"         value={`${data.totals.ot_pct}%`}          testid="ot-pct" />
            <Tile label="OT Revenue"   value={fmtCurrency(data.totals.ot_revenue)} testid="ot-revenue" />
            <Tile label="OT Cost"      value={fmtCurrency(data.totals.ot_cost)}    testid="ot-cost" />
            <Tile label="OT Margin"    value={fmtCurrency(data.totals.ot_margin)}  testid="ot-margin" />
          </div>

          <h3 style={sectLabel}>Weekly OT %</h3>
          <table className="data-table" data-testid="reports-overtime-weekly-table" style={tblStyle}>
            <thead><tr><th>Week start</th><th style={r}>OT hrs</th><th style={r}>Total hrs</th><th style={r}>OT %</th></tr></thead>
            <tbody>
              {data.weekly_ot.length === 0 && <tr><td colSpan="4" className="empty" style={{ textAlign: 'center', padding: 24 }}>No data.</td></tr>}
              {data.weekly_ot.map(w => (
                <tr key={w.week_start} data-testid={`ot-week-${w.week_start}`}>
                  <td>{w.week_start}</td>
                  <td style={r}>{w.ot_hours}</td>
                  <td style={r}>{w.total_hours}</td>
                  <td style={{ ...r, color: w.ot_pct > 15 ? '#dc2626' : 'inherit', fontWeight: w.ot_pct > 15 ? 600 : 400 }}>{w.ot_pct}%</td>
                </tr>
              ))}
            </tbody>
          </table>

          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(380px, 1fr))', gap: 'var(--cf-space-5)', marginTop: 'var(--cf-space-5)' }}>
            <div>
              <h3 style={sectLabel}>Top employees by OT %</h3>
              <table className="data-table" data-testid="reports-overtime-employees-table" style={tblStyle}>
                <thead><tr><th>Worker</th><th style={r}>Total hrs</th><th style={r}>OT hrs</th><th style={r}>OT %</th></tr></thead>
                <tbody>
                  {data.top_employees.length === 0 && <tr><td colSpan="4" className="empty" style={{ textAlign: 'center', padding: 16 }}>No OT this period.</td></tr>}
                  {data.top_employees.map(e => (
                    <tr key={e.employee_id} data-testid={`ot-emp-${e.employee_id}`}>
                      <td>{e.worker || '(unknown)'}</td>
                      <td style={r}>{e.total_hours}</td>
                      <td style={r}>{e.ot_hours}</td>
                      <td style={r}>{e.ot_pct}%</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <div>
              <h3 style={sectLabel}>Top clients by OT hours</h3>
              <table className="data-table" data-testid="reports-overtime-clients-table" style={tblStyle}>
                <thead><tr><th>Client</th><th style={r}>OT hrs</th><th style={r}>OT margin</th></tr></thead>
                <tbody>
                  {data.top_clients.length === 0 && <tr><td colSpan="3" className="empty" style={{ textAlign: 'center', padding: 16 }}>No OT this period.</td></tr>}
                  {data.top_clients.map((c, idx) => (
                    <tr key={c.client + idx} data-testid={`ot-client-${idx}`}>
                      <td>{c.client}</td>
                      <td style={r}>{c.ot_hours}</td>
                      <td style={r}>{fmtCurrency(c.ot_margin)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </>
      )}
    </section>
  );
}

const r = { textAlign: 'right' };
const sectLabel = { fontSize: 14, fontWeight: 600, textTransform: 'uppercase', letterSpacing: 0.5, color: 'var(--cf-text-muted, #6b7280)', margin: '0 0 var(--cf-space-3) 0' };
const tblStyle = { width: '100%', background: 'var(--cf-surface, #fff)', border: '1px solid var(--cf-border, #e5e7eb)', borderRadius: 8 };

function Tile({ label, value, testid }) {
  return (
    <div data-testid={testid} style={{ background: 'var(--cf-surface, #fff)', border: '1px solid var(--cf-border, #e5e7eb)', borderRadius: 8, padding: 'var(--cf-space-4)' }}>
      <div style={{ fontSize: 12, color: '#6b7280', textTransform: 'uppercase', letterSpacing: 0.4, marginBottom: 4 }}>{label}</div>
      <div style={{ fontSize: 22, fontWeight: 700 }}>{value}</div>
    </div>
  );
}

function fmtCurrency(n) { return `$${Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 2 })}`; }
function fmtNum(n)      { return Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 2 }); }
