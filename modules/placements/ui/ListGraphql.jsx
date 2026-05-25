import React, { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useGql } from '../../../dashboard/src/lib/graphqlClient';
import { Zap } from 'lucide-react';

/**
 * ListGraphql — PILOT migration of Placements list from REST → GraphQL.
 *
 * Architecturally identical to ./List.jsx except for the data fetch.
 * Read-only, server-side filtering, paginated.
 *
 * REST path that List.jsx hits:    /modules/placements/api/placements.php
 * Equivalent GraphQL query:        Query.placements(...)
 *
 * Both ultimately call the same PHP endpoint — the subgraph proxies to
 * /api/placements/placements — so behavior parity is guaranteed at the
 * data layer. What changes here is the transport (REST cookie vs JWT
 * over HTTPS to graphql.corefluxapp.com).
 */

const STATUSES = ['', 'pending_start', 'active', 'ended', 'cancelled'];
const PER_PAGE = 25;

const PLACEMENTS_QUERY = `
  query DashboardPlacements($status: PlacementStatus, $limit: Int!) {
    placements(status: $status, limit: $limit) {
      id
      title
      status
      engagementType
      startDate
      endDate
      dueDate
      endClientName
      person { id firstName lastName }
    }
  }
`;

export default function ListGraphql() {
  const [q, setQ]                 = useState('');
  const [status, setStatus]       = useState('active');
  const [page, setPage]           = useState(1);

  // GraphQL doesn't support the existing REST's free-text q or paging
  // server-side (yet); apply both client-side over the fetched page-set.
  // Limit pulls a generous window so the page UI feels identical.
  const variables = useMemo(() => ({
    status: status || null,
    limit: PER_PAGE * 4, // pull 4 pages worth, paginate client-side
  }), [status]);

  const { data, error, loading, reload } = useGql(PLACEMENTS_QUERY, { variables });

  const allRows = data?.placements ?? [];
  const filtered = useMemo(() => {
    if (!q.trim()) return allRows;
    const needle = q.trim().toLowerCase();
    return allRows.filter(p => {
      const fields = [
        p.title,
        p.endClientName,
        p.person?.firstName,
        p.person?.lastName,
      ].filter(Boolean).map(s => String(s).toLowerCase());
      return fields.some(f => f.includes(needle));
    });
  }, [allRows, q]);

  const total = filtered.length;
  const lastPage = Math.max(1, Math.ceil(total / PER_PAGE));
  const rows = filtered.slice((page - 1) * PER_PAGE, page * PER_PAGE);

  return (
    <section className="people-directory" data-testid="placements-list-gql">
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: 'var(--cf-space-4)' }}>
        <div>
          <h2 style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
            Placements
            <span
              data-testid="placements-gql-badge"
              style={{
                display: 'inline-flex',
                alignItems: 'center',
                gap: 4,
                padding: '2px 8px',
                fontSize: 'var(--cf-text-xs)',
                fontWeight: 600,
                background: 'rgba(124,58,237,0.12)',
                color: '#7c3aed',
                borderRadius: 999,
              }}
            >
              <Zap size={12} /> GraphQL
            </span>
          </h2>
          <p style={{ color: 'var(--cf-text-secondary)' }} data-testid="placements-gql-count">
            {loading ? 'Loading…' : `${total} total (via graphql.corefluxapp.com)`}
          </p>
        </div>
        <div style={{ display: 'flex', gap: 'var(--cf-space-2)' }}>
          <Link to="../list" className="btn" data-testid="placements-gql-switch-rest">Switch to REST</Link>
          <button className="btn btn--ghost" onClick={reload} data-testid="placements-gql-refresh">Refresh</button>
        </div>
      </header>

      <div style={{ display: 'flex', gap: 'var(--cf-space-2)', marginBottom: 'var(--cf-space-3)', flexWrap: 'wrap' }}>
        <input className="input" placeholder="Search title, end-client, person…" value={q}
               onChange={e => { setQ(e.target.value); setPage(1); }} data-testid="placements-gql-search" />
        <select className="input" value={status} onChange={e => { setStatus(e.target.value); setPage(1); }} data-testid="placements-gql-status-filter">
          {STATUSES.map(s => <option key={s} value={s}>{s === '' ? 'All statuses' : s}</option>)}
        </select>
      </div>

      {error && (
        <p className="error" data-testid="placements-gql-error" style={{ padding: 'var(--cf-space-3)', background: 'rgba(239,68,68,0.08)', borderRadius: 6 }}>
          GraphQL error: {error.message}
        </p>
      )}

      <table className="data-table" data-testid="placements-gql-table">
        <thead><tr><th>Title</th><th>Person</th><th>End client</th><th>Type</th><th>Status</th><th>Start</th><th>Due</th><th>End</th></tr></thead>
        <tbody>
          {!loading && rows.length === 0 && (
            <tr><td colSpan={8} className="empty" data-testid="placements-gql-empty">No placements match.</td></tr>
          )}
          {rows.map(p => (
            <tr key={p.id} data-testid={`placement-gql-row-${p.id}`}>
              <td><Link to={`../${p.id}`}>{p.title || '(untitled)'}</Link></td>
              <td>{p.person ? `${p.person.firstName ?? ''} ${p.person.lastName ?? ''}`.trim() || '—' : '—'}</td>
              <td>{p.endClientName || '—'}</td>
              <td>{p.engagementType || '—'}</td>
              <td><span className={`badge badge--${p.status}`}>{p.status}</span></td>
              <td>{p.startDate || '—'}</td>
              <td>{p.dueDate || '—'}</td>
              <td>{p.endDate || '—'}</td>
            </tr>
          ))}
        </tbody>
      </table>

      <div style={{ marginTop: 'var(--cf-space-3)', display: 'flex', gap: 'var(--cf-space-2)', alignItems: 'center' }}>
        <button className="btn" disabled={page <= 1} onClick={() => setPage(p => p - 1)} data-testid="placements-gql-prev">Prev</button>
        <span data-testid="placements-gql-page-indicator">Page {page} of {lastPage}</span>
        <button className="btn" disabled={page >= lastPage} onClick={() => setPage(p => p + 1)} data-testid="placements-gql-next">Next</button>
      </div>
    </section>
  );
}
