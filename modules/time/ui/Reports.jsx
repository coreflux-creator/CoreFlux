import React, { useState } from 'react';
import { useApi } from '../../../dashboard/src/lib/api';

export default function Reports() {
  const periods = useApi('/modules/time/api/periods.php');
  const [periodId, setPeriodId] = useState(null);
  React.useEffect(() => {
    if (periods.data?.rows?.length && !periodId) setPeriodId(periods.data.rows[0].id);
  }, [periods.data, periodId]);

  const byPlacement = useApi(periodId ? `/modules/time/api/reports.php?type=by_placement&period_id=${periodId}` : null);
  const byPerson    = useApi(periodId ? `/modules/time/api/reports.php?type=by_person&period_id=${periodId}` : null);
  const util        = useApi(periodId ? `/modules/time/api/reports.php?type=utilization&period_id=${periodId}` : null);

  return (
    <section className="people-directory" data-testid="time-reports">
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 'var(--cf-space-3)' }}>
        <h2>Time Reports</h2>
        <select className="input" value={periodId || ''} onChange={e => setPeriodId(parseInt(e.target.value, 10))} data-testid="time-reports-period" style={{ maxWidth: '260px' }}>
          <option value="">— select period —</option>
          {(periods.data?.rows ?? []).map(p => <option key={p.id} value={p.id}>{p.label} ({p.start_date} → {p.end_date})</option>)}
        </select>
      </header>

      {util.data && (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: 'var(--cf-space-3)', marginBottom: 'var(--cf-space-5)' }}>
          <Card label="Billable"     value={`${util.data.billable_pct}%`}    sub={`${util.data.totals?.billable ?? 0} hrs`}    t="time-reports-util-billable" />
          <Card label="Non-billable" value={`${util.data.nonbillable_pct}%`} sub={`${util.data.totals?.nonbillable ?? 0} hrs`} t="time-reports-util-nonbillable" />
          <Card label="PTO"          value={`${util.data.pto_pct}%`}         sub={`${util.data.totals?.pto ?? 0} hrs`}         t="time-reports-util-pto" />
          <Card label="Total"        value={`${util.data.totals?.total ?? 0} hrs`} t="time-reports-util-total" />
        </div>
      )}

      <h3>By placement</h3>
      <table className="data-table" data-testid="time-reports-by-placement">
        <thead><tr><th>Title</th><th>Client</th><th>Billable</th><th>Non-bill</th><th>PTO</th><th>Total</th></tr></thead>
        <tbody>
          {(byPlacement.data?.rows ?? []).length === 0 && <tr><td colSpan={6} className="empty">No data.</td></tr>}
          {(byPlacement.data?.rows ?? []).map(r => (
            <tr key={r.placement_id} data-testid={`time-reports-placement-${r.placement_id}`}>
              <td>{r.title}</td><td>{r.end_client_name || '—'}</td>
              <td>{r.billable}</td><td>{r.nonbillable}</td><td>{r.pto}</td><td><strong>{r.total}</strong></td>
            </tr>
          ))}
        </tbody>
      </table>

      <h3 style={{ marginTop: 'var(--cf-space-5)' }}>By person</h3>
      <table className="data-table" data-testid="time-reports-by-person">
        <thead><tr><th>Name</th><th>Email</th><th>Billable</th><th>Non-bill</th><th>PTO</th><th>Total</th></tr></thead>
        <tbody>
          {(byPerson.data?.rows ?? []).length === 0 && <tr><td colSpan={6} className="empty">No data.</td></tr>}
          {(byPerson.data?.rows ?? []).map(r => (
            <tr key={r.person_id} data-testid={`time-reports-person-${r.person_id}`}>
              <td>{r.first_name} {r.last_name}</td><td>{r.email_primary}</td>
              <td>{r.billable}</td><td>{r.nonbillable}</td><td>{r.pto}</td><td><strong>{r.total}</strong></td>
            </tr>
          ))}
        </tbody>
      </table>
    </section>
  );
}
const Card = ({ label, value, sub, t }) => (
  <div style={{ background: 'var(--cf-surface)', padding: 'var(--cf-space-4)', borderRadius: 'var(--cf-radius-lg)', border: '1px solid var(--cf-border)' }} data-testid={t}>
    <div style={{ fontSize: '0.85em', color: 'var(--cf-text-secondary)' }}>{label}</div>
    <div style={{ fontSize: '1.5em', fontWeight: 600 }}>{value}</div>
    {sub && <div style={{ fontSize: '0.85em', color: 'var(--cf-text-muted)' }}>{sub}</div>}
  </div>
);
