import React, { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useApi } from '../../../dashboard/src/lib/api';

const STATUSES = ['', 'draft', 'pending_start', 'active', 'on_hold', 'ended', 'cancelled'];
const ETYPES   = ['', 'w2', '1099', 'c2c', 'temp_to_perm', 'direct_hire'];

export default function List() {
  const [q, setQ]                       = useState('');
  const [status, setStatus]             = useState('active');
  const [engagementType, setETYPE]      = useState('');
  const [page, setPage]                 = useState(1);

  const path = useMemo(() => {
    const p = new URLSearchParams();
    if (q) p.set('q', q);
    if (status) p.set('status', status);
    if (engagementType) p.set('engagement_type', engagementType);
    p.set('page', String(page));
    return `/modules/placements/api/placements.php?${p.toString()}`;
  }, [q, status, engagementType, page]);

  const { data, loading, error, reload } = useApi(path);
  const rows = data?.rows ?? [];
  const total = data?.total ?? 0;
  const perPage = data?.per_page ?? 25;
  const lastPage = Math.max(1, Math.ceil(total / perPage));

  return (
    <section className="people-directory" data-testid="placements-list">
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 'var(--cf-space-4)' }}>
        <div>
          <h2>Placements</h2>
          <p style={{ color: 'var(--cf-text-secondary)' }} data-testid="placements-count">{data ? `${total} total` : 'Loading…'}</p>
        </div>
        <div style={{ display: 'flex', gap: 'var(--cf-space-2)' }}>
          <Link to="../csv_import" className="btn" data-testid="placements-csv-btn">Import CSV</Link>
          <a href="/modules/placements/api/csv_export.php" className="btn" data-testid="placements-csv-export-btn">Export CSV</a>
          <Link to="../new"        className="btn btn--primary" data-testid="placements-new-btn">+ New Placement</Link>
        </div>
      </header>

      <div style={{ display: 'flex', gap: 'var(--cf-space-2)', marginBottom: 'var(--cf-space-3)', flexWrap: 'wrap' }}>
        <input className="input" placeholder="Search title, end-client, external id" value={q}
               onChange={e => { setQ(e.target.value); setPage(1); }} data-testid="placements-search" />
        <select className="input" value={status} onChange={e => { setStatus(e.target.value); setPage(1); }} data-testid="placements-status-filter">
          {STATUSES.map(s => <option key={s} value={s}>{s === '' ? 'All statuses' : s}</option>)}
        </select>
        <select className="input" value={engagementType} onChange={e => { setETYPE(e.target.value); setPage(1); }} data-testid="placements-etype-filter">
          {ETYPES.map(s => <option key={s} value={s}>{s === '' ? 'All types' : s}</option>)}
        </select>
        <button className="btn btn--ghost" onClick={reload} data-testid="placements-refresh">Refresh</button>
      </div>

      {loading && <p>Loading…</p>}
      {error && <p className="error" data-testid="placements-error">Error: {error.message}</p>}

      <table className="data-table" data-testid="placements-table">
        <thead><tr><th>Title</th><th>Person</th><th>End client</th><th>Type</th><th>Status</th><th>Start</th><th>Due</th><th>End</th></tr></thead>
        <tbody>
          {rows.length === 0 && <tr><td colSpan={8} className="empty" data-testid="placements-empty">No placements match.</td></tr>}
          {rows.map(p => (
            <tr key={p.id} data-testid={`placement-row-${p.id}`}>
              <td><Link to={`../${p.id}`}>{p.title}</Link></td>
              <td>{p.first_name ? `${p.first_name} ${p.last_name}` : '—'}</td>
              <td>{p.end_client_name || '—'}</td>
              <td>{p.engagement_type}</td>
              <td><span className={`badge badge--${p.status}`}>{p.status}</span></td>
              <td>{p.start_date}</td>
              <td>{p.due_date || '—'}</td>
              <td>{p.end_date || '—'}</td>
            </tr>
          ))}
        </tbody>
      </table>

      <div style={{ marginTop: 'var(--cf-space-3)', display: 'flex', gap: 'var(--cf-space-2)', alignItems: 'center' }}>
        <button className="btn" disabled={page <= 1} onClick={() => setPage(p => p - 1)} data-testid="placements-prev">Prev</button>
        <span data-testid="placements-page-indicator">Page {page} of {lastPage}</span>
        <button className="btn" disabled={page >= lastPage} onClick={() => setPage(p => p + 1)} data-testid="placements-next">Next</button>
      </div>
    </section>
  );
}
