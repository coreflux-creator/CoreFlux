import React from 'react';
import { Link } from 'react-router-dom';
import { useApi } from '../../../dashboard/src/lib/api';

/**
 * Staffing Overview — quick links + week-at-a-glance.
 * Phase 1 lightweight version. Phase 2 will populate KPI cards from the
 * staffing analytics views (`v_staffing_*`).
 */
export default function StaffingOverview({ session }) {
  // Pull my own draft / submitted timesheets for the last 4 weeks.
  const since = new Date(); since.setDate(since.getDate() - 28);
  const personId = session?.user?.person_id || session?.user?.id || 1;
  const list = useApi(`/modules/staffing/api/timesheets.php?action=list&person_id=${personId}&period_start=${since.toISOString().slice(0,10)}`);
  const rows = list.data?.rows ?? [];

  return (
    <section className="people-directory" data-testid="staffing-overview">
      <header style={{ marginBottom: 'var(--cf-space-4)' }}>
        <h2>Staffing</h2>
        <p style={{ color:'var(--cf-text-secondary)' }}>Client-facing labor, placements, and weekly timesheets.</p>
      </header>

      <div style={{ display:'grid', gridTemplateColumns:'repeat(auto-fit,minmax(220px,1fr))', gap:'var(--cf-space-3)', marginBottom:'var(--cf-space-4)' }}>
        <QuickCard to="/modules/staffing/timesheets" title="My Weekly Timesheet" subtitle="Enter hours, submit the week" testId="staffing-card-timesheets" />
        <QuickCard to="/modules/staffing/placements" title="Placements"          subtitle="Active engagements + rates" testId="staffing-card-placements" />
        <QuickCard to="/modules/staffing/approvals"  title="Approvals Queue"     subtitle="Review submitted timesheets" testId="staffing-card-approvals" />
        <QuickCard to="/modules/staffing/settings"   title="Settings"            subtitle="Week start, contracted hours" testId="staffing-card-settings" />
      </div>

      <h3 style={{ marginBottom: 'var(--cf-space-2)' }}>My Recent Timesheets</h3>
      {list.loading && <p>Loading…</p>}
      {!list.loading && rows.length === 0 && <p className="empty" data-testid="staffing-overview-empty">No timesheets yet. Start your first week →</p>}
      {rows.length > 0 && (
        <table className="data-table" data-testid="staffing-overview-recent">
          <thead><tr><th>Week</th><th>Hours</th><th>Status</th><th></th></tr></thead>
          <tbody>
            {rows.map(r => (
              <tr key={r.id} data-testid={`staffing-ts-row-${r.id}`}>
                <td>{r.period_start} → {r.period_end}</td>
                <td>{parseFloat(r.total_hours).toFixed(2)}</td>
                <td><code>{r.status}</code></td>
                <td><Link to="/modules/staffing/timesheets">Open →</Link></td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </section>
  );
}

function QuickCard({ to, title, subtitle, testId }) {
  return (
    <Link to={to} className="card" data-testid={testId} style={{ display:'block', padding: 16, border:'1px solid var(--cf-border, #e5e7eb)', borderRadius: 8, textDecoration:'none', color:'inherit', transition:'transform 0.15s, border-color 0.15s' }}
          onMouseEnter={(e)=>{ e.currentTarget.style.borderColor='var(--cf-primary, #2563eb)'; e.currentTarget.style.transform='translateY(-1px)'; }}
          onMouseLeave={(e)=>{ e.currentTarget.style.borderColor='var(--cf-border, #e5e7eb)'; e.currentTarget.style.transform='translateY(0)'; }}>
      <div style={{ fontWeight: 600, marginBottom: 4 }}>{title}</div>
      <div style={{ fontSize:'0.85em', color:'var(--cf-text-secondary)' }}>{subtitle}</div>
    </Link>
  );
}
