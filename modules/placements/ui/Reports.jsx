/**
 * Placements → Reports — Reports Overhaul Pass 2 (Tier-2).
 *
 * Two surfaces:
 *  • Expiring (30 days) — placements due / ending soon.
 *  • Active by client    — count of active placements per end-client.
 *
 * Lifted into the shared visual language: sticky header, sharper
 * typography, hover-bordered tables, drill links remain (these are
 * already short, focused tables).
 */
import React from 'react';
import { Link } from 'react-router-dom';
import { useApi } from '../../../dashboard/src/lib/api';
import MetricCard from '../../../dashboard/src/components/MetricCard';

export default function Reports() {
  const exp = useApi('/modules/placements/api/reports.php?type=expiring&days=30');
  const abc = useApi('/modules/placements/api/reports.php?type=active_by_client');
  const expRows = exp.data?.rows ?? [];
  const abcRows = abc.data?.rows ?? [];
  const totalActive = abcRows.reduce((s, r) => s + (Number(r.active_count) || 0), 0);

  return (
    <section data-testid="placements-reports" style={{ paddingBottom: 32 }}>
      <header style={stickyHeader}>
        <div style={{ flex: 1, minWidth: 240 }}>
          <h1 style={titleStyle}>Placement Reports</h1>
          <p style={subStyle}>Expiring soon + active inventory by client</p>
        </div>
      </header>

      <div style={kpiBand}>
        <MetricCard label="Expiring (30d)" testIdPrefix="placements-rpt-kpi-expiring"
                    value={expRows.length}
                    tone={expRows.length > 0 ? 'negative' : 'positive'} />
        <MetricCard label="Active total" testIdPrefix="placements-rpt-kpi-active"
                    value={totalActive} tone="positive" />
        <MetricCard label="Clients with hires" testIdPrefix="placements-rpt-kpi-clients"
                    value={abcRows.length} />
      </div>

      <h3 style={sectLabel}>Expiring (30 days)</h3>
      {exp.loading && <p>Loading…</p>}
      {exp.error && <p className="error" data-testid="placements-reports-expiring-error">Error: {exp.error.message}</p>}
      <table style={tableStyle} data-testid="placements-reports-expiring-table">
        <thead><tr><th style={th}>Title</th><th style={th}>Person</th><th style={th}>Due/end</th></tr></thead>
        <tbody>
          {expRows.length === 0 && (
            <tr><td colSpan={3} style={emptyCell}>Nothing expiring.</td></tr>
          )}
          {expRows.map(p => (
            <tr key={p.id} data-testid={`reports-expiring-row-${p.id}`}
                style={{ borderTop: '1px solid #f1f5f9' }}>
              <td style={td}><Link to={`../${p.id}`} style={linkStyle}>{p.title}</Link></td>
              <td style={td}>{p.first_name ? `${p.first_name} ${p.last_name}` : '—'}</td>
              <td style={td}>{p.due_date || p.end_date || '—'}</td>
            </tr>
          ))}
        </tbody>
      </table>

      <h3 style={{ ...sectLabel, marginTop: 24 }}>Active by client</h3>
      {abc.loading && <p>Loading…</p>}
      {abc.error && <p className="error" data-testid="placements-reports-abc-error">Error: {abc.error.message}</p>}
      <table style={tableStyle} data-testid="placements-reports-abc-table">
        <thead><tr><th style={th}>End client</th><th style={thR}>Active count</th></tr></thead>
        <tbody>
          {abcRows.length === 0 && (
            <tr><td colSpan={2} style={emptyCell}>No active placements yet.</td></tr>
          )}
          {abcRows.map((r, i) => (
            <tr key={i} data-testid={`reports-abc-row-${i}`}
                style={{ borderTop: '1px solid #f1f5f9' }}>
              <td style={td}>{r.end_client_name}</td>
              <td style={tdR}>{r.active_count}</td>
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
const linkStyle = { color: '#1d4ed8', textDecoration: 'none', fontWeight: 500 };
