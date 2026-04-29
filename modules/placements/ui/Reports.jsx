import React from 'react';
import { Link } from 'react-router-dom';
import { useApi } from '../../../dashboard/src/lib/api';

export default function Reports() {
  const exp = useApi('/modules/placements/api/reports.php?type=expiring&days=30');
  const abc = useApi('/modules/placements/api/reports.php?type=active_by_client');

  return (
    <section className="people-directory" data-testid="placements-reports">
      <h2>Reports</h2>

      <h3 style={{ marginTop: 'var(--cf-space-5)' }}>Expiring (30 days)</h3>
      {exp.loading && <p>Loading…</p>}
      {exp.error && <p className="error" data-testid="placements-reports-expiring-error">Error: {exp.error.message}</p>}
      <table className="data-table" data-testid="placements-reports-expiring-table">
        <thead><tr><th>Title</th><th>Person</th><th>Due/end</th></tr></thead>
        <tbody>
          {(exp.data?.rows ?? []).length === 0 && <tr><td colSpan={3} className="empty">Nothing expiring.</td></tr>}
          {(exp.data?.rows ?? []).map(p => (
            <tr key={p.id} data-testid={`reports-expiring-row-${p.id}`}>
              <td><Link to={`../${p.id}`}>{p.title}</Link></td>
              <td>{p.first_name ? `${p.first_name} ${p.last_name}` : '—'}</td>
              <td>{p.due_date || p.end_date || '—'}</td>
            </tr>
          ))}
        </tbody>
      </table>

      <h3 style={{ marginTop: 'var(--cf-space-5)' }}>Active by client</h3>
      {abc.loading && <p>Loading…</p>}
      {abc.error && <p className="error" data-testid="placements-reports-abc-error">Error: {abc.error.message}</p>}
      <table className="data-table" data-testid="placements-reports-abc-table">
        <thead><tr><th>End client</th><th>Active count</th></tr></thead>
        <tbody>
          {(abc.data?.rows ?? []).length === 0 && <tr><td colSpan={2} className="empty">No active placements yet.</td></tr>}
          {(abc.data?.rows ?? []).map((r, i) => (
            <tr key={i} data-testid={`reports-abc-row-${i}`}>
              <td>{r.end_client_name}</td>
              <td>{r.active_count}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </section>
  );
}
