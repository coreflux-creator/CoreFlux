import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { api, useApi } from '../../../dashboard/src/lib/api';

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
    <section className="people-directory" data-testid="staffing-overview" style={{ paddingBottom: 32 }}>
      <header style={{
        position: 'sticky', top: 0, zIndex: 5,
        background: 'linear-gradient(180deg, #fff 0%, #fff 88%, rgba(255,255,255,0) 100%)',
        padding: '12px 0 14px',
        borderBottom: '1px solid #e2e8f0',
        marginBottom: 16,
      }}>
        <h1 style={{ margin: 0, fontSize: 22, fontWeight: 700,
                     color: '#0f172a', letterSpacing: '-0.01em' }}>Staffing</h1>
        <p style={{ color: '#64748b', margin: '4px 0 0', fontSize: 13 }}>
          Client-facing labor, placements, and weekly timesheets.
        </p>
      </header>

      <div style={{ display:'grid', gridTemplateColumns:'repeat(auto-fit,minmax(220px,1fr))', gap:'var(--cf-space-3)', marginBottom:'var(--cf-space-4)' }}>
        <QuickCard to="/modules/staffing/timesheets" title="My Weekly Timesheet" subtitle="Enter hours, submit the week" testId="staffing-card-timesheets" />
        <QuickCard to="/modules/placements/list" title="Placements"          subtitle="Active engagements + rates" testId="staffing-card-placements" />
        <QuickCard to="/modules/staffing/approvals"  title="Approvals Queue"     subtitle="Review submitted timesheets" testId="staffing-card-approvals" />
        <QuickCard to="/modules/staffing/settings"   title="Settings"            subtitle="Week start, contracted hours" testId="staffing-card-settings" />
      </div>

      <WeeklyMemoCard />

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
                <td style={{ fontVariantNumeric: 'tabular-nums' }}>{parseFloat(r.total_hours).toFixed(2)}</td>
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

function WeeklyMemoCard() {
  const [memo, setMemo]       = useState(null);
  const [loading, setLoading] = useState(false);
  const [err, setErr]         = useState(null);

  const generate = async () => {
    setLoading(true); setErr(null);
    try {
      const res = await api.get('/modules/staffing/api/ai_insights.php?action=weekly_memo');
      setMemo(res);
    } catch (e) {
      setErr(e.message || String(e));
    } finally { setLoading(false); }
  };

  return (
    <section style={{ marginBottom: 'var(--cf-space-4)', padding: 16, border:'1px solid var(--cf-border, #e5e7eb)', borderRadius: 8 }} data-testid="staffing-weekly-memo">
      <header style={{ display:'flex', justifyContent:'space-between', alignItems:'center', marginBottom: 'var(--cf-space-2)' }}>
        <h3 style={{ margin: 0 }}>✨ AI Weekly Memo</h3>
        <button className="btn btn--primary" onClick={generate} disabled={loading} data-testid="staffing-memo-generate">
          {loading ? 'Generating…' : memo ? 'Regenerate' : 'Generate'}
        </button>
      </header>
      {!memo && !loading && !err && (
        <p style={{ color:'var(--cf-text-muted)', fontSize:'0.9em' }}>
          Click Generate to get a 5-bullet ops memo from last week — headline numbers, top clients, margin call-outs, workflow bottlenecks, and a recommended action for next week.
        </p>
      )}
      {err && <div style={{ color:'#dc2626', fontSize:'0.9em' }} data-testid="staffing-memo-error">{err}</div>}
      {memo && (
        <div data-testid="staffing-memo-content">
          <div style={{ fontSize:'0.8em', color:'var(--cf-text-muted)', marginBottom: 8 }}>
            Week {memo.period?.start} → {memo.period?.end}
          </div>
          <div style={{ whiteSpace:'pre-wrap', lineHeight: 1.6 }}>{memo.memo}</div>
          {memo.stats?.revenue > 0 && (
            <div style={{ marginTop: 12, paddingTop: 12, borderTop: '1px solid var(--cf-border, #e5e7eb)', display:'flex', gap: 16, fontSize:'0.85em', color:'var(--cf-text-muted)', flexWrap:'wrap' }}>
              <span>Hours: <strong>{parseFloat(memo.stats.hours).toFixed(1)}</strong></span>
              <span>Revenue: <strong>${parseFloat(memo.stats.revenue).toLocaleString(undefined,{maximumFractionDigits:0})}</strong></span>
              <span>GP: <strong>${parseFloat(memo.stats.gp).toLocaleString(undefined,{maximumFractionDigits:0})}</strong></span>
            </div>
          )}
        </div>
      )}
    </section>
  );
}
