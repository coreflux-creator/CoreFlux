import React, { useEffect, useMemo, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { useApiCached, bustApiCachePrefix, prefetchApi, api } from '../../../dashboard/src/lib/api';
import { useTableList, SortIndicator } from '../../../dashboard/src/lib/useTableList';
import { fmtDate } from '../../../dashboard/src/lib/formatDate';
import IdBadge from '../../../dashboard/src/components/IdBadge';

const STATUSES = ['', 'draft', 'pending_start', 'active', 'on_hold', 'ended', 'cancelled'];
const ETYPES   = ['', 'w2', '1099', 'c2c', 'temp_to_perm', 'direct_hire'];

// Promotion targets when bulk-activating drafts. Excludes terminal
// states (ended / cancelled) and `draft` itself — those don't make
// sense as "next step after review".
const PROMOTE_TO = ['pending_start', 'active', 'on_hold'];

export default function List() {
  const [searchParams, setSearchParams] = useSearchParams();
  const initialStatus = searchParams.get('status') ?? 'active';

  const [q, setQ]                       = useState('');
  const [status, setStatus]             = useState(initialStatus);
  const [engagementType, setETYPE]      = useState('');
  const [page, setPage]                 = useState(1);
  // Bulk-selection state — only meaningful when filtered to `draft`.
  // Stored as a Set so an O(1) toggle works on thousands of rows.
  const [selected, setSelected]         = useState(() => new Set());
  const [bulkBusy, setBulkBusy]         = useState(false);
  const [bulkResult, setBulkResult]     = useState(null);

  // Sync URL ?status= when the operator changes the dropdown — lets
  // the CsvImportPage "View N drafts" deep-link land on a filtered view
  // AND lets the operator share a URL with a colleague.
  useEffect(() => {
    const current = searchParams.get('status') ?? 'active';
    if (current !== status) {
      const next = new URLSearchParams(searchParams);
      if (status) next.set('status', status); else next.delete('status');
      setSearchParams(next, { replace: true });
    }
  }, [status]); // eslint-disable-line react-hooks/exhaustive-deps

  const path = useMemo(() => {
    const p = new URLSearchParams();
    if (q) p.set('q', q);
    if (status) p.set('status', status);
    if (engagementType) p.set('engagement_type', engagementType);
    p.set('page', String(page));
    return `/modules/placements/api/placements.php?${p.toString()}`;
  }, [q, status, engagementType, page]);

  const { data, loading, error, elapsedMs, reload } = useApiCached(
    path,
    { cacheKey: `placements-list:${path}` }
  );
  const rows = data?.rows ?? [];
  const total = data?.total ?? 0;
  const perPage = data?.per_page ?? 25;
  const lastPage = Math.max(1, Math.ceil(total / perPage));
  const isDraftView = status === 'draft';

  // Client-side sort only — server already handles q/status/type search.
  const { items, sortKey, sortDir, headerProps } = useTableList(rows, {
    defaultSort: { key: 'start_date', dir: 'desc' },
    dateKeys:    ['start_date', 'due_date', 'end_date'],
    numericKeys: ['id', 'person_id'],
  });

  // Reset selection whenever the filter / page / search changes so we
  // don't accidentally bulk-update a row the operator can no longer see.
  useEffect(() => { setSelected(new Set()); setBulkResult(null); }, [q, status, engagementType, page]);

  const toggleRow = (id) => {
    setSelected(prev => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id); else next.add(id);
      return next;
    });
  };
  const allOnPage = items.length > 0 && items.every(r => selected.has(r.id));
  const toggleAll = () => {
    setSelected(prev => {
      const next = new Set(prev);
      if (allOnPage) items.forEach(r => next.delete(r.id));
      else           items.forEach(r => next.add(r.id));
      return next;
    });
  };

  const bulkSetStatus = async (newStatus) => {
    if (selected.size === 0) return;
    if (!confirm(`Mark ${selected.size} placement${selected.size === 1 ? '' : 's'} as "${newStatus}"?`)) return;
    setBulkBusy(true); setBulkResult(null);
    try {
      const res = await api.post(
        '/modules/placements/api/placements.php?action=bulk_status',
        { ids: Array.from(selected), status: newStatus }
      );
      setBulkResult(res);
      setSelected(new Set());
      // Bulk status change affects every filtered view of the list,
      // not just the current one — invalidate the whole prefix so
      // sibling tabs (Active, On Hold, Drafts) all refresh on next mount.
      bustApiCachePrefix('placements-list:');
      reload();
    } catch (e) {
      setBulkResult({ error: e?.message || String(e) });
    } finally {
      setBulkBusy(false);
    }
  };

  return (
    <section className="people-directory" data-testid="placements-list">
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 'var(--cf-space-4)' }}>
        <div>
          <h2>Placements</h2>
          <p style={{ color: 'var(--cf-text-secondary)' }} data-testid="placements-count">
            {!data ? 'Loading…' : (
              <>
                {total} total
                {elapsedMs != null && (
                  <span data-testid="placements-rest-perf" style={{ marginLeft: 8, fontSize: 'var(--cf-text-xs)', color: '#64748b', fontWeight: 600 }}>
                    ⌁ {Math.round(elapsedMs)}ms via /api (REST)
                  </span>
                )}
              </>
            )}
          </p>
        </div>
        <div style={{ display: 'flex', gap: 'var(--cf-space-2)' }}>
          <Link to="../draft-rates" className="btn btn--ghost" data-testid="placements-draft-rates-btn" title="Review and approve draft rates across all placements">
            Draft rates queue
          </Link>
          <Link to="../list-graphql" className="btn btn--ghost" data-testid="placements-try-graphql-btn" title="Same data, fetched via the new federated GraphQL endpoint">
            ⚡ Try GraphQL (beta)
          </Link>
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

      {/* Bulk toolbar — visible only when reviewing drafts. Keeps the
          default list noise-free for the active-placements path. */}
      {isDraftView && items.length > 0 && (
        <div
          data-testid="placements-bulk-toolbar"
          style={{
            display: 'flex', alignItems: 'center', gap: 'var(--cf-space-2)',
            padding: 'var(--cf-space-2) var(--cf-space-3)',
            marginBottom: 'var(--cf-space-3)',
            background: '#f1f5f9', border: '1px solid #cbd5e1', borderRadius: 6,
          }}
        >
          <span data-testid="placements-bulk-selected-count" style={{ fontWeight: 600 }}>
            {selected.size} selected
          </span>
          <span style={{ color: '#64748b', fontSize: 12 }}>
            Promote drafts to:
          </span>
          {PROMOTE_TO.map(s => (
            <button
              key={s}
              className="btn"
              disabled={selected.size === 0 || bulkBusy}
              onClick={() => bulkSetStatus(s)}
              data-testid={`placements-bulk-to-${s}`}
            >
              {bulkBusy ? '…' : s}
            </button>
          ))}
          <button
            className="btn btn--ghost"
            disabled={selected.size === 0 || bulkBusy}
            onClick={() => setSelected(new Set())}
            data-testid="placements-bulk-clear"
          >
            Clear selection
          </button>
        </div>
      )}

      {bulkResult && (
        <div
          data-testid="placements-bulk-result"
          style={{
            padding: 'var(--cf-space-2) var(--cf-space-3)',
            marginBottom: 'var(--cf-space-3)',
            background: bulkResult.error ? '#fee2e2' : '#dcfce7',
            border: `1px solid ${bulkResult.error ? '#fca5a5' : '#86efac'}`,
            borderRadius: 6, fontSize: 13,
          }}
        >
          {bulkResult.error
            ? <>Bulk update failed: {bulkResult.error}</>
            : <>Updated <strong>{bulkResult.updated}</strong>{bulkResult.skipped ? <>, skipped {bulkResult.skipped}</> : null} → status <strong>{bulkResult.status}</strong>.</>
          }
        </div>
      )}

      {loading && <p>Loading…</p>}
      {error && <p className="error" data-testid="placements-error">Error: {error.message}</p>}

      <table className="data-table" data-testid="placements-table">
        <thead>
          <tr>
            {isDraftView && (
              <th style={{ width: 32 }}>
                <input
                  type="checkbox"
                  checked={allOnPage}
                  onChange={toggleAll}
                  data-testid="placements-bulk-select-all"
                  aria-label="Select all drafts on this page"
                />
              </th>
            )}
            <th {...headerProps('id', 'placements-sort')}>ID <SortIndicator active={sortKey === 'id'} dir={sortDir} /></th>
            <th {...headerProps('title', 'placements-sort')}>Title <SortIndicator active={sortKey === 'title'} dir={sortDir} /></th>
            <th {...headerProps('last_name', 'placements-sort')}>Person <SortIndicator active={sortKey === 'last_name'} dir={sortDir} /></th>
            <th {...headerProps('end_client_name', 'placements-sort')}>End client <SortIndicator active={sortKey === 'end_client_name'} dir={sortDir} /></th>
            <th {...headerProps('engagement_type', 'placements-sort')}>Type <SortIndicator active={sortKey === 'engagement_type'} dir={sortDir} /></th>
            <th {...headerProps('status', 'placements-sort')}>Status <SortIndicator active={sortKey === 'status'} dir={sortDir} /></th>
            <th {...headerProps('start_date', 'placements-sort')}>Start <SortIndicator active={sortKey === 'start_date'} dir={sortDir} /></th>
            <th {...headerProps('due_date', 'placements-sort')}>Due <SortIndicator active={sortKey === 'due_date'} dir={sortDir} /></th>
            <th {...headerProps('end_date', 'placements-sort')}>End <SortIndicator active={sortKey === 'end_date'} dir={sortDir} /></th>
          </tr>
        </thead>
        <tbody>
          {items.length === 0 && <tr><td colSpan={isDraftView ? 10 : 9} className="empty" data-testid="placements-empty">No placements match.</td></tr>}
          {items.map(p => (
            <tr key={p.id} data-testid={`placement-row-${p.id}`}>
              {isDraftView && (
                <td>
                  <input
                    type="checkbox"
                    checked={selected.has(p.id)}
                    onChange={() => toggleRow(p.id)}
                    data-testid={`placement-row-select-${p.id}`}
                    aria-label={`Select placement ${p.id}`}
                  />
                </td>
              )}
              <td><IdBadge id={p.id} prefix="PL" /></td>
              <td>
                <Link
                  to={`../${p.id}`}
                  onMouseEnter={() => prefetchApi(
                    `/modules/placements/api/placements.php?action=get&id=${p.id}`,
                    `placement-detail:${p.id}`
                  )}
                >
                  {p.title}
                </Link>
              </td>
              <td>
                {p.first_name ? `${p.first_name} ${p.last_name}` : '—'}
                {p.person_id ? <> <IdBadge id={p.person_id} prefix="P" /></> : null}
              </td>
              <td>{p.end_client_name || '—'}</td>
              <td>{p.engagement_type}</td>
              <td><span className={`badge badge--${p.status}`}>{p.status}</span></td>
              <td>{fmtDate(p.start_date)}</td>
              <td>{fmtDate(p.due_date)}</td>
              <td>{fmtDate(p.end_date)}</td>
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
