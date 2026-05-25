import React, { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useGql, runDiagnostics, __GRAPHQL_URL__ } from '../../../dashboard/src/lib/graphqlClient';
import { Zap, CheckCircle2, XCircle, Loader2 } from 'lucide-react';

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
// PHP's placementsList caps per_page at 200. Ask for the max so we get
// every record for a typical tenant in one round-trip. A future iteration
// can add a paginated GraphQL connection (rows + total + cursor) for tenants
// with >200 placements per status.
const MAX_FETCH = 200;

function DiagRow({ testid, label, url, result, hint404, hint401, hintNetwork }) {
  const ok = result?.ok;
  let hint = '';
  if (!ok) {
    if (result?.status === 404 && hint404) hint = hint404;
    else if (result?.status === 401 && hint401) hint = hint401;
    else if (result?.status === 0 && hintNetwork) hint = hintNetwork;
  }
  return (
    <div data-testid={testid} style={{
      display: 'flex',
      alignItems: 'flex-start',
      gap: 'var(--cf-space-2)',
      padding: 'var(--cf-space-2)',
      background: ok ? 'rgba(34,197,94,0.05)' : 'rgba(239,68,68,0.05)',
      border: `1px solid ${ok ? 'rgba(34,197,94,0.25)' : 'rgba(239,68,68,0.25)'}`,
      borderRadius: 6,
    }}>
      {ok ? <CheckCircle2 size={18} color="#16a34a" /> : <XCircle size={18} color="#dc2626" />}
      <div style={{ flex: 1 }}>
        <div style={{ fontWeight: 600 }}>{label}</div>
        <code style={{ fontSize: 'var(--cf-text-xs)', color: 'var(--cf-text-secondary)' }}>{url}</code>
        <div style={{ fontSize: 'var(--cf-text-xs)', marginTop: 2 }}>
          {ok
            ? `OK — HTTP ${result.status} in ${result.durationMs}ms`
            : `FAIL — ${result?.error || 'unknown'} (${result?.durationMs ?? '?'}ms)`}
        </div>
        {hint && <div style={{ fontSize: 'var(--cf-text-xs)', marginTop: 4, color: '#7f1d1d' }}>💡 {hint}</div>}
      </div>
    </div>
  );
}

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
    limit: MAX_FETCH,
  }), [status]);

  const { data, error, loading, elapsedMs, reload } = useGql(PLACEMENTS_QUERY, { variables });

  const [diag, setDiag] = useState(null);
  const [diagLoading, setDiagLoading] = useState(false);
  const runDiag = async () => {
    setDiagLoading(true);
    try { setDiag(await runDiagnostics()); }
    finally { setDiagLoading(false); }
  };

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
            {loading
              ? 'Loading…'
              : (
                <>
                  {total} total
                  {elapsedMs != null && (
                    <span data-testid="placements-gql-perf" style={{ marginLeft: 8, fontSize: 'var(--cf-text-xs)', color: '#7c3aed', fontWeight: 600 }}>
                      ⚡ {Math.round(elapsedMs)}ms via graphql.corefluxapp.com
                    </span>
                  )}
                </>
              )}
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

      {!loading && allRows.length >= MAX_FETCH && (
        <div data-testid="placements-gql-truncated" style={{
          padding: 'var(--cf-space-2) var(--cf-space-3)',
          background: 'rgba(245,158,11,0.10)',
          border: '1px solid rgba(245,158,11,0.35)',
          color: '#92400e',
          borderRadius: 6,
          marginBottom: 'var(--cf-space-3)',
          fontSize: 'var(--cf-text-sm)',
        }}>
          ⚠ Showing the first {MAX_FETCH} records — there may be more.
          A paginated GraphQL connection is on the roadmap. For now, narrow by status to drill in.
        </div>
      )}

      {error && (
        <div data-testid="placements-gql-error" style={{
          padding: 'var(--cf-space-3)',
          background: 'rgba(239,68,68,0.06)',
          border: '1px solid rgba(239,68,68,0.25)',
          borderRadius: 8,
          marginBottom: 'var(--cf-space-3)',
        }}>
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 'var(--cf-space-2)', flexWrap: 'wrap' }}>
            <div>
              <strong style={{ color: '#b91c1c' }}>GraphQL error</strong>
              {error.code && <code style={{ marginLeft: 8, fontSize: 'var(--cf-text-xs)', padding: '2px 6px', background: '#fee2e2', borderRadius: 4 }}>{error.code}</code>}
              <div style={{ marginTop: 4, fontSize: 'var(--cf-text-sm)', color: '#7f1d1d' }} data-testid="placements-gql-error-message">
                {error.message}
              </div>
            </div>
            <button className="btn" onClick={runDiag} disabled={diagLoading} data-testid="placements-gql-diag-run">
              {diagLoading ? <Loader2 size={14} className="cf-spin" /> : null} Run diagnostics
            </button>
          </div>

          {diag && (
            <div data-testid="placements-gql-diag-results" style={{ marginTop: 'var(--cf-space-3)', display: 'grid', gap: 'var(--cf-space-2)', fontSize: 'var(--cf-text-sm)' }}>
              <DiagRow
                testid="diag-jwt-row"
                label="JWT mint endpoint"
                url="/api/auth/issue_dashboard_jwt.php"
                result={diag.jwtMint}
                hint404="Endpoint not deployed yet — push to GitHub + redeploy PHP on Cloudways."
                hint401="No active dashboard session — log out and log back in."
              />
              <DiagRow
                testid="diag-graphql-row"
                label="GraphQL endpoint"
                url={__GRAPHQL_URL__}
                result={diag.graphql}
                hintNetwork="Browser can't reach the droplet. Check CORS origins in router.yaml include this dashboard's URL, or that DNS / cert / firewall didn't break."
                hint404="Router didn't accept POST / — likely path or method config mismatch."
              />
              <p style={{ fontSize: 'var(--cf-text-xs)', color: 'var(--cf-text-secondary)', marginTop: 4 }}>
                Both rows green = the pilot should work; if it still doesn't, the JWT_SECRET on the droplet doesn't match the one Cloudways PHP uses to sign tokens.
              </p>
            </div>
          )}
        </div>
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
