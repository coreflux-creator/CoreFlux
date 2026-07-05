import React, { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useApi } from '../../../dashboard/src/lib/api';
import { useTableList, SortIndicator } from '../../../dashboard/src/lib/useTableList';

export default function Expiring() {
  const { data, loading, error } = useApi('/modules/placements/api/reports.php?type=expiring&days=30');
  const [status, setStatus] = useState('');
  const rows = data?.rows ?? [];
  const statuses = useMemo(() => Array.from(new Set(rows.map(r => r.status).filter(Boolean))).sort(), [rows]);
  const filtered = useMemo(() => {
    if (!status) return rows;
    return rows.filter(r => r.status === status);
  }, [rows, status]);
  const {
    items,
    sortKey,
    sortDir,
    search,
    setSearch,
    headerProps,
  } = useTableList(filtered, {
    defaultSort: { key: 'due_date', dir: 'asc' },
    searchKeys: ['title', 'first_name', 'last_name', 'end_client_name', 'status'],
    dateKeys: ['due_date', 'end_date'],
    numericKeys: ['id'],
  });

  return (
    <section className="people-directory" data-testid="placements-expiring">
      <h2>Expiring placements (next 30 days)</h2>
      <p style={{ color: 'var(--cf-text-secondary)' }}>By due date or end date, whichever is sooner.</p>
      <div style={{ display: 'flex', gap: 'var(--cf-space-2)', marginBottom: 'var(--cf-space-3)', flexWrap: 'wrap' }}>
        <input
          className="input"
          placeholder="Search title, person, client..."
          value={search}
          onChange={e => setSearch(e.target.value)}
          data-testid="placements-expiring-search"
        />
        <select className="input" value={status} onChange={e => setStatus(e.target.value)} data-testid="placements-expiring-status-filter">
          <option value="">All statuses</option>
          {statuses.map(s => <option key={s} value={s}>{s}</option>)}
        </select>
      </div>
      {loading && <p>Loading…</p>}
      {error && <p className="error" data-testid="placements-expiring-error">Error: {error.message}</p>}
      <table className="data-table" data-testid="placements-expiring-table">
        <thead>
          <tr>
            <th {...headerProps('title', 'placements-expiring-sort')}>Title <SortIndicator active={sortKey === 'title'} dir={sortDir} /></th>
            <th {...headerProps('last_name', 'placements-expiring-sort')}>Person <SortIndicator active={sortKey === 'last_name'} dir={sortDir} /></th>
            <th {...headerProps('end_client_name', 'placements-expiring-sort')}>End client <SortIndicator active={sortKey === 'end_client_name'} dir={sortDir} /></th>
            <th {...headerProps('status', 'placements-expiring-sort')}>Status <SortIndicator active={sortKey === 'status'} dir={sortDir} /></th>
            <th {...headerProps('due_date', 'placements-expiring-sort')}>Due date <SortIndicator active={sortKey === 'due_date'} dir={sortDir} /></th>
            <th {...headerProps('end_date', 'placements-expiring-sort')}>End date <SortIndicator active={sortKey === 'end_date'} dir={sortDir} /></th>
          </tr>
        </thead>
        <tbody>
          {items.length === 0 && <tr><td colSpan={6} className="empty" data-testid="placements-expiring-empty">Nothing expiring soon.</td></tr>}
          {items.map(p => (
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
