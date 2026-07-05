import React, { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useGql } from '../../../dashboard/src/lib/graphqlClient';
import { useTableList, SortIndicator } from '../../../dashboard/src/lib/useTableList';
import { Zap } from 'lucide-react';

/**
 * ClientsGraphql — PILOT migration of the Clients (Companies) list from
 * REST → GraphQL. Read-only — mutations stay on the existing REST page.
 *
 * Architecturally identical to ./Clients.jsx's read path except for the
 * data fetch. Server-side filtering hasn't landed on the GraphQL schema
 * yet (Query.companies(limit) is the only filter); the search box and
 * status filter both run client-side over the fetched window.
 *
 * Mirrors the pattern established by /app/modules/placements/ui/ListGraphql.jsx.
 */

const MAX_FETCH = 200;
const PER_PAGE  = 25;

// Same status values the REST `Clients.jsx` page exposes.
const STATUS_OPTIONS = ['', 'active', 'prospect', 'on_hold', 'inactive', 'closed'];

const COMPANIES_QUERY = `
  query DashboardCompanies($limit: Int!) {
    companies(limit: $limit) {
      id
      name
      status
      industry
      website
      phone
      billingEmail
      billingTerms
      billingAddress { city state country }
    }
  }
`;

export default function ClientsGraphql() {
  const [q, setQ] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [page, setPage] = useState(1);

  const variables = useMemo(() => ({ limit: MAX_FETCH }), []);
  const { data, error, loading, elapsedMs, reload } =
    useGql(COMPANIES_QUERY, { variables });

  const allRows = useMemo(() => (data?.companies ?? []).map(c => ({
    ...c,
    location: [c.billingAddress?.city, c.billingAddress?.state, c.billingAddress?.country]
      .filter(Boolean).join(', '),
  })), [data?.companies]);
  const filtered = useMemo(() => {
    let rows = allRows;
    if (statusFilter) {
      rows = rows.filter(c => c.status === statusFilter);
    }
    if (q.trim()) {
      const needle = q.trim().toLowerCase();
      rows = rows.filter(c => {
        const fields = [
          c.name, c.status, c.industry, c.billingEmail, c.location,
          c.billingAddress?.city, c.billingAddress?.state,
        ].filter(Boolean).map(s => String(s).toLowerCase());
        return fields.some(f => f.includes(needle));
      });
    }
    return rows;
  }, [allRows, q, statusFilter]);

  const {
    items: sortedRows,
    sortKey,
    sortDir,
    headerProps,
  } = useTableList(filtered, {
    defaultSort: { key: 'name', dir: 'asc' },
    searchKeys: ['name', 'industry', 'billingEmail', 'location', 'status'],
    numericKeys: ['id'],
  });

  const total = sortedRows.length;
  const lastPage = Math.max(1, Math.ceil(total / PER_PAGE));
  const rows = sortedRows.slice((page - 1) * PER_PAGE, page * PER_PAGE);

  return (
    <section className="people-directory" data-testid="clients-list-gql">
      <header style={{
        display: 'flex',
        justifyContent: 'space-between',
        alignItems: 'flex-start',
        marginBottom: 'var(--cf-space-4)',
        gap: 'var(--cf-space-3)',
        flexWrap: 'wrap',
      }}>
        <div>
          <h2 style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
            Clients
            <span
              data-testid="clients-gql-badge"
              style={{
                display: 'inline-flex', alignItems: 'center', gap: 4,
                padding: '2px 8px',
                fontSize: 'var(--cf-text-xs)', fontWeight: 600,
                background: 'rgba(124,58,237,0.12)', color: '#7c3aed',
                borderRadius: 999,
              }}
            >
              <Zap size={12} /> GraphQL
            </span>
          </h2>
          <p style={{ color: 'var(--cf-text-secondary)' }} data-testid="clients-gql-count">
            {loading ? 'Loading…' : (
              <>
                {total} total
                {elapsedMs != null && (
                  <span
                    data-testid="clients-gql-perf"
                    style={{ marginLeft: 8, fontSize: 'var(--cf-text-xs)', color: '#7c3aed', fontWeight: 600 }}
                  >
                    ⚡ {Math.round(elapsedMs)}ms via graphql.corefluxapp.com
                  </span>
                )}
              </>
            )}
          </p>
        </div>
        <div style={{ display: 'flex', gap: 'var(--cf-space-2)' }}>
          <Link to="../clients" className="btn" data-testid="clients-gql-switch-rest">Switch to REST</Link>
          <button className="btn btn--ghost" onClick={reload} data-testid="clients-gql-refresh">Refresh</button>
        </div>
      </header>

      <div style={{ display: 'flex', gap: 'var(--cf-space-2)', marginBottom: 'var(--cf-space-3)', flexWrap: 'wrap' }}>
        <input
          className="input"
          placeholder="Search name, industry, email, city…"
          value={q}
          onChange={e => { setQ(e.target.value); setPage(1); }}
          data-testid="clients-gql-search"
        />
        <select
          className="input"
          value={statusFilter}
          onChange={e => { setStatusFilter(e.target.value); setPage(1); }}
          data-testid="clients-gql-status-filter"
        >
          {STATUS_OPTIONS.map(s => (
            <option key={s} value={s}>{s === '' ? 'All statuses' : s}</option>
          ))}
        </select>
      </div>

      {!loading && allRows.length >= MAX_FETCH && (
        <div data-testid="clients-gql-truncated" style={{
          padding: 'var(--cf-space-2) var(--cf-space-3)',
          background: 'rgba(245,158,11,0.10)',
          border: '1px solid rgba(245,158,11,0.35)',
          color: '#92400e',
          borderRadius: 6,
          marginBottom: 'var(--cf-space-3)',
          fontSize: 'var(--cf-text-sm)',
        }}>
          ⚠ Showing the first {MAX_FETCH} companies — there may be more.
          Paginated GraphQL connections are on the roadmap; for now, narrow the search.
        </div>
      )}

      {error && (
        <div data-testid="clients-gql-error" style={{
          padding: 'var(--cf-space-3)',
          background: 'rgba(239,68,68,0.06)',
          border: '1px solid rgba(239,68,68,0.25)',
          borderRadius: 8,
          marginBottom: 'var(--cf-space-3)',
        }}>
          <strong style={{ color: '#b91c1c' }}>GraphQL error</strong>
          {error.code && (
            <code
              style={{ marginLeft: 8, fontSize: 'var(--cf-text-xs)', padding: '2px 6px', background: '#fee2e2', borderRadius: 4 }}
            >{error.code}</code>
          )}
          <div
            style={{ marginTop: 4, fontSize: 'var(--cf-text-sm)', color: '#7f1d1d' }}
            data-testid="clients-gql-error-message"
          >{error.message}</div>
        </div>
      )}

      <table className="data-table" data-testid="clients-gql-table">
        <thead>
          <tr>
            <th {...headerProps('name', 'clients-gql-sort')}>Name <SortIndicator active={sortKey === 'name'} dir={sortDir} /></th>
            <th {...headerProps('status', 'clients-gql-sort')}>Status <SortIndicator active={sortKey === 'status'} dir={sortDir} /></th>
            <th {...headerProps('industry', 'clients-gql-sort')}>Industry <SortIndicator active={sortKey === 'industry'} dir={sortDir} /></th>
            <th {...headerProps('website', 'clients-gql-sort')}>Website <SortIndicator active={sortKey === 'website'} dir={sortDir} /></th>
            <th {...headerProps('phone', 'clients-gql-sort')}>Phone <SortIndicator active={sortKey === 'phone'} dir={sortDir} /></th>
            <th {...headerProps('billingEmail', 'clients-gql-sort')}>Billing email <SortIndicator active={sortKey === 'billingEmail'} dir={sortDir} /></th>
            <th {...headerProps('billingTerms', 'clients-gql-sort')}>Terms <SortIndicator active={sortKey === 'billingTerms'} dir={sortDir} /></th>
            <th {...headerProps('location', 'clients-gql-sort')}>Location <SortIndicator active={sortKey === 'location'} dir={sortDir} /></th>
          </tr>
        </thead>
        <tbody>
          {!loading && rows.length === 0 && (
            <tr><td colSpan={8} className="empty" data-testid="clients-gql-empty">No companies match.</td></tr>
          )}
          {rows.map(c => (
            <tr key={c.id} data-testid={`client-gql-row-${c.id}`}>
              <td>{c.name || '(unnamed)'}</td>
              <td>{c.status || '-'}</td>
              <td>{c.industry || '—'}</td>
              <td>
                {c.website
                  ? <a href={c.website} target="_blank" rel="noreferrer">{c.website.replace(/^https?:\/\//, '')}</a>
                  : '—'}
              </td>
              <td>{c.phone || '—'}</td>
              <td>{c.billingEmail || '—'}</td>
              <td>{c.billingTerms || '—'}</td>
              <td>
                {[c.billingAddress?.city, c.billingAddress?.state, c.billingAddress?.country]
                  .filter(Boolean).join(', ') || '—'}
              </td>
            </tr>
          ))}
        </tbody>
      </table>

      <div style={{ marginTop: 'var(--cf-space-3)', display: 'flex', gap: 'var(--cf-space-2)', alignItems: 'center' }}>
        <button className="btn" disabled={page <= 1} onClick={() => setPage(p => p - 1)} data-testid="clients-gql-prev">Prev</button>
        <span data-testid="clients-gql-page-indicator">Page {page} of {lastPage}</span>
        <button className="btn" disabled={page >= lastPage} onClick={() => setPage(p => p + 1)} data-testid="clients-gql-next">Next</button>
      </div>
    </section>
  );
}
