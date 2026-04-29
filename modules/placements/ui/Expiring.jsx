import React from 'react';
import { Link } from 'react-router-dom';
import { useApi } from '../../../dashboard/src/lib/api';

export default function Expiring() {
  const { data, loading, error } = useApi('/modules/placements/api/reports.php?type=expiring&days=30');
  const rows = data?.rows ?? [];

  return (
    <section className="people-directory" data-testid="placements-expiring">
      <h2>Expiring placements (next 30 days)</h2>
      <p style={{ color: 'var(--cf-text-secondary)' }}>By due date or end date, whichever is sooner.</p>
      {loading && <p>Loading…</p>}
      {error && <p className="error" data-testid="placements-expiring-error">Error: {error.message}</p>}
      <table className="data-table" data-testid="placements-expiring-table">
        <thead><tr><th>Title</th><th>Person</th><th>End client</th><th>Status</th><th>Due date</th><th>End date</th></tr></thead>
        <tbody>
          {rows.length === 0 && <tr><td colSpan={6} className="empty" data-testid="placements-expiring-empty">Nothing expiring soon.</td></tr>}
          {rows.map(p => (
            <tr key={p.id} data-testid={`expiring-row-${p.id}`}>
              <td><Link to={`../${p.id}`}>{p.title}</Link></td>
              <td>{p.first_name ? `${p.first_name} ${p.last_name}` : '—'}</td>
              <td>{p.end_client_name || '—'}</td>
              <td><span className={`badge badge--${p.status}`}>{p.status}</span></td>
              <td>{p.due_date || '—'}</td>
              <td>{p.end_date || '—'}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </section>
  );
}
