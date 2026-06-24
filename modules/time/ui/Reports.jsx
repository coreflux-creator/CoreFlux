/**
 * Time → Reports — Reports Overhaul Pass 2 (Tier-2).
 *
 * Three views for a chosen pay period:
 *  • Utilization KPI band (Billable / Non-billable / PTO / Total).
 *  • By-placement breakdown.
 *  • By-person breakdown.
 *
 * Lifted to the shared visual language.
 */
import React, { useState, useEffect } from 'react';
import { useApi } from '../../../dashboard/src/lib/api';
import MetricCard from '../../../dashboard/src/components/MetricCard';

export default function Reports() {
  const periods = useApi('/api/v1/time/periods');
  const [periodId, setPeriodId] = useState(null);
  useEffect(() => {
    if (periods.data?.rows?.length && !periodId) setPeriodId(periods.data.rows[0].id);
  }, [periods.data, periodId]);

  const byPlacement = useApi(periodId ? `/api/v1/time/reports?type=by_placement&period_id=${periodId}` : null);
  const byPerson    = useApi(periodId ? `/api/v1/time/reports?type=by_person&period_id=${periodId}` : null);
  const util        = useApi(periodId ? `/api/v1/time/reports?type=utilization&period_id=${periodId}` : null);

  return (
    <section data-testid="time-reports" style={{ paddingBottom: 32 }}>
      <header style={stickyHeader}>
        <div style={{ flex: 1, minWidth: 240 }}>
          <h1 style={titleStyle}>Time Reports</h1>
          <p style={subStyle}>Utilization + placement + person breakdowns per pay period</p>
        </div>
        <select
          className="input"
          value={periodId || ''}
          onChange={e => setPeriodId(parseInt(e.target.value, 10))}
          data-testid="time-reports-period"
          style={{ maxWidth: 260, fontSize: 13, padding: '5px 8px' }}
        >
          <option value="">— select period —</option>
          {(periods.data?.rows ?? []).map(p => (
            <option key={p.id} value={p.id}>{p.label} ({p.start_date} → {p.end_date})</option>
          ))}
        </select>
      </header>

      {util.data && (
        <div style={kpiBand}>
          <MetricCard label="Billable" testIdPrefix="time-reports-util-billable"
                      value={`${util.data.billable_pct}%`}
                      tone="positive" />
          <MetricCard label="Non-billable" testIdPrefix="time-reports-util-nonbillable"
                      value={`${util.data.nonbillable_pct}%`}
                      tone="neutral" />
          <MetricCard label="PTO" testIdPrefix="time-reports-util-pto"
                      value={`${util.data.pto_pct}%`} />
          <MetricCard label="Total hours" testIdPrefix="time-reports-util-total"
                      value={util.data.totals?.total ?? 0}
                      format={(n) => `${Number(n).toLocaleString(undefined, { maximumFractionDigits: 1 })} hrs`} />
        </div>
      )}

      <h3 style={sectLabel}>By placement</h3>
      <table style={tableStyle} data-testid="time-reports-by-placement">
        <thead><tr>
          <th style={th}>Title</th><th style={th}>Client</th>
          <th style={thR}>Billable</th><th style={thR}>Non-bill</th>
          <th style={thR}>PTO</th><th style={thR}>Total</th>
        </tr></thead>
        <tbody>
          {(byPlacement.data?.rows ?? []).length === 0 && (
            <tr><td colSpan={6} style={emptyCell}>No data.</td></tr>
          )}
          {(byPlacement.data?.rows ?? []).map(r => (
            <tr key={r.placement_id} data-testid={`time-reports-placement-${r.placement_id}`}
                style={{ borderTop: '1px solid #f1f5f9' }}>
              <td style={td}>{r.title}</td>
              <td style={td}>{r.end_client_name || '—'}</td>
              <td style={tdR}>{r.billable}</td>
              <td style={tdR}>{r.nonbillable}</td>
              <td style={tdR}>{r.pto}</td>
              <td style={{ ...tdR, fontWeight: 700 }}>{r.total}</td>
            </tr>
          ))}
        </tbody>
      </table>

      <h3 style={{ ...sectLabel, marginTop: 24 }}>By person</h3>
      <table style={tableStyle} data-testid="time-reports-by-person">
        <thead><tr>
          <th style={th}>Name</th><th style={th}>Email</th>
          <th style={thR}>Billable</th><th style={thR}>Non-bill</th>
          <th style={thR}>PTO</th><th style={thR}>Total</th>
        </tr></thead>
        <tbody>
          {(byPerson.data?.rows ?? []).length === 0 && (
            <tr><td colSpan={6} style={emptyCell}>No data.</td></tr>
          )}
          {(byPerson.data?.rows ?? []).map(r => (
            <tr key={r.person_id} data-testid={`time-reports-person-${r.person_id}`}
                style={{ borderTop: '1px solid #f1f5f9' }}>
              <td style={td}>{r.first_name} {r.last_name}</td>
              <td style={td}>{r.email_primary}</td>
              <td style={tdR}>{r.billable}</td>
              <td style={tdR}>{r.nonbillable}</td>
              <td style={tdR}>{r.pto}</td>
              <td style={{ ...tdR, fontWeight: 700 }}>{r.total}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </section>
  );
}

const stickyHeader = {
  position: 'sticky', top: 0, zIndex: 5,
  background: 'linear-gradient(180deg, #fff 0%, #fff 88%, rgba(255,255,255,0) 100%)',
  padding: '12px 0 14px',
  borderBottom: '1px solid #e2e8f0',
  marginBottom: 16,
  display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end',
  flexWrap: 'wrap', gap: 12,
};
const titleStyle = { margin: 0, fontSize: 22, fontWeight: 700, color: '#0f172a', letterSpacing: '-0.01em' };
const subStyle   = { margin: '4px 0 0', fontSize: 13, color: '#64748b' };
const kpiBand    = { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 12, marginBottom: 20 };
const sectLabel  = { fontSize: 12, fontWeight: 700, color: '#475569',
                     textTransform: 'uppercase', letterSpacing: 0.5,
                     margin: '0 0 8px' };
const tableStyle = { width: '100%', background: '#fff', border: '1px solid #e2e8f0',
                     borderRadius: 6, borderCollapse: 'collapse', fontSize: 13 };
const th  = { textAlign: 'left', padding: '8px 10px', fontSize: 10, fontWeight: 700,
              color: '#475569', textTransform: 'uppercase', letterSpacing: 0.4,
              borderBottom: '1px solid #e2e8f0' };
const thR = { ...th, textAlign: 'right' };
const td  = { padding: '8px 10px', color: '#1e293b' };
const tdR = { ...td, textAlign: 'right', fontVariantNumeric: 'tabular-nums' };
const emptyCell = { textAlign: 'center', padding: 24, color: '#94a3b8' };
