import React, { useEffect, useMemo, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { useApiCached, bustApiCachePrefix, prefetchApi, api } from '../../../dashboard/src/lib/api';
import { useTableList, SortIndicator } from '../../../dashboard/src/lib/useTableList';
import { fmtDate } from '../../../dashboard/src/lib/formatDate';
import IdBadge from '../../../dashboard/src/components/IdBadge';
import ExportTemplatePicker from '../../../dashboard/src/components/ExportTemplatePicker';
import BulkEditBar from '../../../dashboard/src/components/BulkEditBar';
import { DatabaseZap, Pencil } from 'lucide-react';

const STATUSES = ['', 'draft', 'pending_start', 'active', 'on_hold', 'ended', 'cancelled'];
const ETYPES   = ['', 'w2', '1099', 'c2c', 'temp_to_perm', 'direct_hire', 'internal'];

export default function List() {
  const [searchParams, setSearchParams] = useSearchParams();
  const initialStatus = searchParams.get('status') ?? 'active';

  const [q, setQ]                       = useState('');
  const [status, setStatus]             = useState(initialStatus);
  const [engagementType, setETYPE]      = useState('');
  const [endClientCompanyId, setEndClientCompanyId] = useState(searchParams.get('end_client_company_id') || '');
  const [page, setPage]                 = useState(1);
  const [sort, setSort]                 = useState({ key: 'start_date', dir: 'desc' });
  const [selected, setSelected]         = useState(() => new Set());
  const [bulkBusy, setBulkBusy]         = useState(false);
  const [bulkResult, setBulkResult]     = useState(null);

  // Keep the operational view shareable, including client drill-throughs.
  useEffect(() => {
    const current = searchParams.get('status') ?? 'active';
    const currentClient = searchParams.get('end_client_company_id') ?? '';
    if (current !== status || currentClient !== endClientCompanyId) {
      const next = new URLSearchParams(searchParams);
      if (status) next.set('status', status); else next.delete('status');
      if (endClientCompanyId) next.set('end_client_company_id', endClientCompanyId); else next.delete('end_client_company_id');
      setSearchParams(next, { replace: true });
    }
  }, [status, endClientCompanyId]); // eslint-disable-line react-hooks/exhaustive-deps

  const path = useMemo(() => {
    const p = new URLSearchParams();
    if (q) p.set('q', q);
    if (status) p.set('status', status);
    if (engagementType) p.set('engagement_type', engagementType);
    if (endClientCompanyId) p.set('end_client_company_id', endClientCompanyId);
    if (sort.key) p.set('sort', sort.key);
    if (sort.dir) p.set('dir', sort.dir);
    p.set('page', String(page));
    return `/modules/placements/api/placements.php?${p.toString()}`;
  }, [q, status, engagementType, endClientCompanyId, page, sort]);

  const { data, loading, error, elapsedMs, reload } = useApiCached(
    path,
    { cacheKey: `placements-list:${path}` }
  );
  const rows = data?.rows ?? [];
  const total = data?.total ?? 0;
  const perPage = data?.per_page ?? 25;
  const lastPage = Math.max(1, Math.ceil(total / perPage));
  const clientsPath = '/modules/staffing/api/clients.php?action=list&status=active&limit=500&sort=name&dir=asc';
  const { data: clientsData } = useApiCached(clientsPath, { cacheKey: 'staffing-clients:active-options' });
  const activeClients = useMemo(() => clientsData?.rows ?? [], [clientsData?.rows]);
  const buildTemplateExportHref = (tplId) => {
    const params = new URLSearchParams({ template_id: String(tplId) });
    if (status) params.set('status', status);
    if (engagementType) params.set('engagement_type', engagementType);
    return `/api/v1/placements/csv-export?${params.toString()}`;
  };

  // Client-side sort only — server already handles q/status/type search.
  const { items, sortKey, sortDir, headerProps } = useTableList(rows, {
    defaultSort: { key: 'start_date', dir: 'desc' },
    sort,
    onSortChange: next => { setSort(next); setPage(1); },
    dateKeys:    ['start_date', 'due_date', 'end_date'],
    numericKeys: ['id', 'person_id'],
  });

  // Reset selection whenever the filter / page / search changes so we
  // don't accidentally bulk-update a row the operator can no longer see.
  useEffect(() => { setSelected(new Set()); setBulkResult(null); }, [q, status, engagementType, endClientCompanyId, page]);

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

  const bulkFields = useMemo(() => [
    {
      key: 'status', label: 'Status', type: 'select', placeholder: 'Choose status',
      options: STATUSES.filter(Boolean).map(value => ({ value, label: value.replaceAll('_', ' ') })),
    },
    {
      key: 'engagement_type', label: 'Worker type', type: 'select', placeholder: 'Choose worker type',
      options: ETYPES.filter(Boolean).map(value => ({ value, label: value.replaceAll('_', ' ') })),
    },
    {
      key: 'end_client_company_id', label: 'End client', type: 'select', placeholder: 'Choose client',
      options: activeClients.filter(client => client.company_id).map(client => ({ value: client.company_id, label: client.name })),
    },
    {
      key: 'remote_policy', label: 'Remote policy', type: 'select', placeholder: 'Choose policy',
      options: ['onsite', 'hybrid', 'remote'].map(value => ({ value, label: value })),
    },
    { key: 'worksite_state', label: 'Worksite state', type: 'text', placeholder: 'State or province' },
    { key: 'worksite_country', label: 'Worksite country', type: 'text', placeholder: 'Country code' },
  ], [activeClients]);

  const bulkUpdate = async (field, value, label) => {
    if (selected.size === 0) return;
    if (!confirm(`Change ${label.toLowerCase()} for ${selected.size} selected placement${selected.size === 1 ? '' : 's'}?`)) return;
    setBulkBusy(true); setBulkResult(null);
    try {
      const res = field === 'status'
        ? await api.post('/modules/placements/api/placements.php?action=bulk_status', { ids: Array.from(selected), status: value })
        : await api.post('/modules/placements/api/placements.php?action=bulk_update', { ids: Array.from(selected), field, value });
      setBulkResult({ ...res, field, value, label });
      if (field === 'status') setSelected(new Set());
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
          <Link
            to="../jobdiva-reconciliation"
            className="btn btn--ghost"
            data-testid="placements-jobdiva-reconciliation-btn"
            title="Preview and apply exact Start-ID placement reconciliation"
          >
            <DatabaseZap size={15} aria-hidden="true" style={{ marginRight: 6 }} />
            Reconcile JobDiva
          </Link>
          <Link to="../draft-rates" className="btn btn--ghost" data-testid="placements-draft-rates-btn" title="Review and approve draft rates across all placements">
            Draft rates queue
          </Link>
          <Link to="../list-graphql" className="btn btn--ghost" data-testid="placements-try-graphql-btn" title="Same data, fetched via the new federated GraphQL endpoint">
            ⚡ Try GraphQL (beta)
          </Link>
          <Link to="../csv_import" className="btn" data-testid="placements-csv-btn">Import CSV</Link>
          <a href="/api/v1/placements/csv-export" className="btn" data-testid="placements-csv-export-btn">Export CSV</a>
          <ExportTemplatePicker
            dataset="placements_directory"
            buildHref={buildTemplateExportHref}
            label="Export via template"
            testid="placements-export-template"
          />
          <Link to="../new"        className="btn btn--primary" data-testid="placements-new-btn">+ New Placement</Link>
        </div>
      </header>

      <div style={{ display: 'flex', gap: 'var(--cf-space-2)', marginBottom: 'var(--cf-space-3)', flexWrap: 'wrap' }}>
        <input className="input" type="search" placeholder="Search any placement field…" value={q}
               onChange={e => { setQ(e.target.value); setPage(1); }} data-testid="placements-search" />
        <select className="input" value={status} onChange={e => { setStatus(e.target.value); setPage(1); }} data-testid="placements-status-filter">
          {STATUSES.map(s => <option key={s} value={s}>{s === '' ? 'All statuses' : s}</option>)}
        </select>
        <select className="input" value={engagementType} onChange={e => { setETYPE(e.target.value); setPage(1); }} data-testid="placements-etype-filter">
          {ETYPES.map(s => <option key={s} value={s}>{s === '' ? 'All types' : s}</option>)}
        </select>
        <select className="input" value={endClientCompanyId} onChange={e => { setEndClientCompanyId(e.target.value); setPage(1); }} data-testid="placements-client-filter">
          <option value="">All end clients</option>
          {activeClients.filter(client => client.company_id).map(client => <option key={client.id} value={client.company_id}>{client.name}</option>)}
        </select>
        <button className="btn btn--ghost" onClick={reload} data-testid="placements-refresh">Refresh</button>
      </div>

      <BulkEditBar
        count={selected.size}
        noun="placement"
        fields={bulkFields}
        busy={bulkBusy}
        onApply={bulkUpdate}
        onClear={() => setSelected(new Set())}
        testid="placements-bulk"
      />

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
            : <>Updated <strong>{bulkResult.updated}</strong>{bulkResult.skipped ? <>, skipped {bulkResult.skipped}</> : null}{bulkResult.failed ? <>, failed {bulkResult.failed}</> : null} · {bulkResult.label} set to <strong>{String(bulkResult.value).replaceAll('_', ' ')}</strong>.</>
          }
        </div>
      )}

      {loading && <p>Loading…</p>}
      {error && <p className="error" data-testid="placements-error">Error: {error.message}</p>}

      <div style={{ overflowX: 'auto' }}>
      <table className="data-table" data-testid="placements-table">
        <thead>
          <tr>
            <th style={{ width: 32 }}>
              <input
                type="checkbox"
                checked={allOnPage}
                onChange={toggleAll}
                data-testid="placements-bulk-select-all"
                aria-label="Select all placements on this page"
              />
            </th>
            <th {...headerProps('id', 'placements-sort')}>ID <SortIndicator active={sortKey === 'id'} dir={sortDir} /></th>
            <th {...headerProps('title', 'placements-sort')}>Title <SortIndicator active={sortKey === 'title'} dir={sortDir} /></th>
            <th {...headerProps('last_name', 'placements-sort')}>Person <SortIndicator active={sortKey === 'last_name'} dir={sortDir} /></th>
            <th {...headerProps('end_client_name', 'placements-sort')}>End client <SortIndicator active={sortKey === 'end_client_name'} dir={sortDir} /></th>
            <th {...headerProps('engagement_type', 'placements-sort')}>Type <SortIndicator active={sortKey === 'engagement_type'} dir={sortDir} /></th>
            <th {...headerProps('status', 'placements-sort')}>Status <SortIndicator active={sortKey === 'status'} dir={sortDir} /></th>
            <th {...headerProps('start_date', 'placements-sort')}>Start <SortIndicator active={sortKey === 'start_date'} dir={sortDir} /></th>
            <th {...headerProps('due_date', 'placements-sort')}>Due <SortIndicator active={sortKey === 'due_date'} dir={sortDir} /></th>
            <th {...headerProps('end_date', 'placements-sort')}>End <SortIndicator active={sortKey === 'end_date'} dir={sortDir} /></th>
            <th style={{ width: 44 }} aria-label="Edit" />
          </tr>
        </thead>
        <tbody>
          {items.length === 0 && <tr><td colSpan={11} className="empty" data-testid="placements-empty">No placements match.</td></tr>}
          {items.map(p => (
            <tr key={p.id} data-testid={`placement-row-${p.id}`}>
              <td>
                <input
                  type="checkbox"
                  checked={selected.has(p.id)}
                  onChange={() => toggleRow(p.id)}
                  data-testid={`placement-row-select-${p.id}`}
                  aria-label={`Select placement ${p.id}`}
                />
              </td>
              <td><Link to={`../${p.id}`}><IdBadge id={p.id} prefix="PL" /></Link></td>
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
                {p.person_id ? (
                  <Link to={`/modules/people/${p.person_id}`} data-testid={`placement-person-link-${p.id}`}>
                    {p.first_name ? `${p.first_name} ${p.last_name}` : `Person ${p.person_id}`} <IdBadge id={p.person_id} prefix="P" />
                  </Link>
                ) : '—'}
              </td>
              <td>
                {p.client_id ? (
                  <Link to={`/modules/staffing/clients?client_id=${p.client_id}`} data-testid={`placement-client-link-${p.id}`}>
                    {p.end_client_display_name || p.end_client_name || `Client ${p.client_id}`}
                  </Link>
                ) : (p.end_client_display_name || p.end_client_name || '—')}
              </td>
              <td>{p.engagement_type}</td>
              <td><span className={`badge badge--${p.status}`}>{p.status}</span></td>
              <td>{fmtDate(p.start_date)}</td>
              <td>{fmtDate(p.due_date)}</td>
              <td>{fmtDate(p.end_date)}</td>
              <td>
                <Link
                  to={`../${p.id}`}
                  className="btn btn--ghost"
                  title={`Edit ${p.title || `placement ${p.id}`}`}
                  aria-label={`Edit ${p.title || `placement ${p.id}`}`}
                  data-testid={`placement-edit-${p.id}`}
                  style={{ width: 32, height: 32, padding: 0, display: 'inline-grid', placeItems: 'center' }}
                >
                  <Pencil size={15} aria-hidden="true" />
                </Link>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      </div>

      <div style={{ marginTop: 'var(--cf-space-3)', display: 'flex', gap: 'var(--cf-space-2)', alignItems: 'center' }}>
        <button className="btn" disabled={page <= 1} onClick={() => setPage(p => p - 1)} data-testid="placements-prev">Prev</button>
        <span data-testid="placements-page-indicator">Page {page} of {lastPage}</span>
        <button className="btn" disabled={page >= lastPage} onClick={() => setPage(p => p + 1)} data-testid="placements-next">Next</button>
      </div>
    </section>
  );
}
