import React, { useEffect, useMemo, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { useApiCached, bustApiCachePrefix, prefetchApi, api } from '../../../dashboard/src/lib/api';
import { useTableList, SortIndicator } from '../../../dashboard/src/lib/useTableList';
import { fmtDate } from '../../../dashboard/src/lib/formatDate';
import ExportTemplatePicker from '../../../dashboard/src/components/ExportTemplatePicker';
import BulkEditBar from '../../../dashboard/src/components/BulkEditBar';
import IdBadge from '../../../dashboard/src/components/IdBadge';
import {
  ChevronRight, DatabaseZap, Download, FileSpreadsheet, Plus,
  MoreHorizontal, RefreshCw, Search, Upload, Zap,
} from 'lucide-react';

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
    <section className="people-directory directory-page" data-testid="placements-list">
      <header className="directory-page__header workspace-page-header">
        <div>
          <span className="directory-page__eyebrow">People / staffing operations</span>
          <h2>Placements</h2>
          <p className="directory-page__description" data-testid="placements-count">
            Your active engagements, delivery economics, and upcoming renewals.
            {data && <span className="sr-only"> {total} matching placement{total === 1 ? '' : 's'}.</span>}
            {elapsedMs != null && (
              <span className="sr-only" data-testid="placements-rest-perf">
                Loaded in {Math.round(elapsedMs)} milliseconds
              </span>
            )}
          </p>
        </div>
        <div className="directory-page__actions">
          <Link
            to="../jobdiva-reconciliation"
            className="btn"
            data-testid="placements-jobdiva-reconciliation-btn"
            title="Preview and apply exact Start-ID placement reconciliation"
          >
            <DatabaseZap size={16} aria-hidden="true" />
            Reconcile JobDiva
          </Link>
          <Link to="../csv_import" className="btn" data-testid="placements-csv-btn"><Upload size={15} aria-hidden="true" /> Import</Link>
          <details className="action-overflow">
            <summary className="btn" aria-label="More placement actions"><MoreHorizontal size={16} aria-hidden="true" /> More</summary>
            <div className="action-overflow__menu">
              <Link to="../draft-rates" className="action-overflow__item" data-testid="placements-draft-rates-btn" title="Review and approve draft rates across all placements">
                <FileSpreadsheet size={15} aria-hidden="true" /> Draft rates queue
              </Link>
              <Link to="../list-graphql" className="action-overflow__item" data-testid="placements-try-graphql-btn" title="Same data, fetched via the new federated GraphQL endpoint">
                <Zap size={15} aria-hidden="true" /> GraphQL view
              </Link>
              <a href="/api/v1/placements/csv-export" className="action-overflow__item" data-testid="placements-csv-export-btn"><Download size={15} aria-hidden="true" /> Export CSV</a>
              <ExportTemplatePicker
                dataset="placements_directory"
                buildHref={buildTemplateExportHref}
                label="Templates"
                testid="placements-export-template"
              />
            </div>
          </details>
          <Link to="../new" className="btn btn--primary" data-testid="placements-new-btn"><Plus size={16} aria-hidden="true" /> New placement</Link>
        </div>
      </header>

      <div className="page-kpi-strip" aria-label="Placement summary">
        <PageKpi label={status === 'active' ? 'Active' : 'Matching placements'} value={total} />
        <PageKpi label="W-2 on this page" value={items.filter(row => row.engagement_type === 'w2').length} />
        <PageKpi label="C2C on this page" value={items.filter(row => row.engagement_type === 'c2c').length} />
        <PageKpi label="Ending in 30 days" value={items.filter(row => isWithinThirtyDays(row.end_date)).length} tone="amber" />
      </div>

      <div className="operational-surface placements-surface">
      <nav className="record-view-tabs" aria-label="Placement views">
        <button type="button" className={status === 'active' ? 'is-active' : ''} onClick={() => { setStatus('active'); setPage(1); }}>
          Active {status === 'active' && <span>{total}</span>}
        </button>
        <button type="button" className={status === 'pending_start' ? 'is-active' : ''} onClick={() => { setStatus('pending_start'); setPage(1); }}>
          Starting soon {status === 'pending_start' && <span>{total}</span>}
        </button>
        <Link to="../expiring">Ending soon</Link>
        <button type="button" className={status === '' ? 'is-active' : ''} onClick={() => { setStatus(''); setPage(1); }}>
          All placements {status === '' && <span>{total}</span>}
        </button>
      </nav>

      <div className="directory-filter-bar directory-filter-bar--inline">
        <div className="directory-filter-bar__search">
          <Search size={17} aria-hidden="true" />
          <input className="input" type="search" placeholder="Search person, role, client or ID" value={q}
                 onChange={e => { setQ(e.target.value); setPage(1); }} data-testid="placements-search" />
        </div>
        <div className="directory-filter-bar__filters">
          <select className="input" value={status} onChange={e => { setStatus(e.target.value); setPage(1); }} aria-label="Placement status" data-testid="placements-status-filter">
            {STATUSES.map(s => <option key={s} value={s}>{s === '' ? 'All statuses' : s.replaceAll('_', ' ')}</option>)}
          </select>
          <select className="input" value={engagementType} onChange={e => { setETYPE(e.target.value); setPage(1); }} aria-label="Worker type" data-testid="placements-etype-filter">
            {ETYPES.map(s => <option key={s} value={s}>{s === '' ? 'All worker types' : s.replaceAll('_', ' ')}</option>)}
          </select>
          <select className="input" value={endClientCompanyId} onChange={e => { setEndClientCompanyId(e.target.value); setPage(1); }} aria-label="End client" data-testid="placements-client-filter">
            <option value="">All end clients</option>
            {activeClients.filter(client => client.company_id).map(client => <option key={client.id} value={client.company_id}>{client.name}</option>)}
          </select>
          <button className="btn btn--ghost btn--icon" onClick={reload} data-testid="placements-refresh" title="Refresh placements" aria-label="Refresh placements">
            <RefreshCw size={17} aria-hidden="true" />
          </button>
        </div>
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

      <div className="data-table-wrap operational-table-wrap">
      <table className="data-table operational-table" data-testid="placements-table">
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
            <th {...headerProps('last_name', 'placements-sort')}>Person <SortIndicator active={sortKey === 'last_name'} dir={sortDir} /></th>
            <th {...headerProps('title', 'placements-sort')}>Engagement / Client <SortIndicator active={sortKey === 'title'} dir={sortDir} /></th>
            <th {...headerProps('engagement_type', 'placements-sort')}>Type <SortIndicator active={sortKey === 'engagement_type'} dir={sortDir} /></th>
            <th {...headerProps('status', 'placements-sort')}>Status <SortIndicator active={sortKey === 'status'} dir={sortDir} /></th>
            <th {...headerProps('bill_rate', 'placements-sort')} className="numeric-cell">Bill rate <SortIndicator active={sortKey === 'bill_rate'} dir={sortDir} /></th>
            <th {...headerProps('pay_rate', 'placements-sort')} className="numeric-cell">Loaded cost <SortIndicator active={sortKey === 'pay_rate'} dir={sortDir} /></th>
            <th {...headerProps('margin', 'placements-sort')} className="numeric-cell">Margin <SortIndicator active={sortKey === 'margin'} dir={sortDir} /></th>
            <th {...headerProps('start_date', 'placements-sort')}>Starts <SortIndicator active={sortKey === 'start_date'} dir={sortDir} /></th>
            <th {...headerProps('due_date', 'placements-sort')}>Due <SortIndicator active={sortKey === 'due_date'} dir={sortDir} /></th>
            <th {...headerProps('end_date', 'placements-sort')}>Ends <SortIndicator active={sortKey === 'end_date'} dir={sortDir} /></th>
            <th style={{ width: 36 }} aria-label="Open" />
          </tr>
        </thead>
        <tbody>
          {items.length === 0 && <tr><td colSpan={12} className="empty" data-testid="placements-empty">No placements match.</td></tr>}
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
              <td>
                {p.person_id ? (
                  <div className="entity-cell">
                    <span className={`entity-avatar entity-avatar--${Number(p.id) % 4}`}>{initials(p.first_name, p.last_name)}</span>
                    <span>
                      <Link className="entity-primary" to={`/modules/people/${p.person_id}`} data-testid={`placement-person-link-${p.id}`}>
                        {p.first_name ? `${p.first_name} ${p.last_name}` : `Person ${p.person_id}`}
                      </Link>
                      <span className="entity-secondary">
                        <IdBadge id={p.id} prefix="PL" />{' '}
                        <IdBadge id={p.person_id} prefix="P" />
                      </span>
                    </span>
                  </div>
                ) : '—'}
              </td>
              <td>
                <Link
                  className="entity-primary"
                  to={`../${p.id}`}
                  onMouseEnter={() => prefetchApi(
                    `/modules/placements/api/placements.php?action=get&id=${p.id}`,
                    `placement-detail:${p.id}`
                  )}
                >
                  {p.title || 'Untitled placement'}
                </Link>
                {p.client_id ? (
                  <Link className="entity-secondary entity-secondary--link" to={`/modules/staffing/clients?client_id=${p.client_id}`} data-testid={`placement-client-link-${p.id}`}>
                    {p.end_client_display_name || p.end_client_name || `Client ${p.client_id}`}
                  </Link>
                ) : <span className="entity-secondary">{p.end_client_display_name || p.end_client_name || 'No client linked'}</span>}
              </td>
              <td><span className={`badge badge--${p.engagement_type}`}>{p.engagement_type}</span></td>
              <td><span className={`badge badge--${p.status}`}>{String(p.status || '').replaceAll('_', ' ')}</span></td>
              <td className="numeric-cell">{formatRate(p.current_invoice_rate)}</td>
              <td className="numeric-cell">{formatRate(p.current_loaded_cost)}</td>
              <td className={`numeric-cell ${marginTone(p.current_margin_pct)}`}>
                {formatMargin(p.current_margin_pct)}
              </td>
              <td>{fmtDate(p.start_date)}</td>
              <td>{fmtDate(p.due_date)}</td>
              <td>{fmtDate(p.end_date)}</td>
              <td>
                <Link
                  to={`../${p.id}`}
                  className="row-open-link"
                  title={`Edit ${p.title || `placement ${p.id}`}`}
                  aria-label={`Edit ${p.title || `placement ${p.id}`}`}
                  data-testid={`placement-edit-${p.id}`}
                >
                  <ChevronRight size={17} aria-hidden="true" />
                </Link>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      </div>

      <div className="table-pagination">
        <button className="btn" disabled={page <= 1} onClick={() => setPage(p => p - 1)} data-testid="placements-prev">Prev</button>
        <span data-testid="placements-page-indicator">Page {page} of {lastPage}</span>
        <button className="btn" disabled={page >= lastPage} onClick={() => setPage(p => p + 1)} data-testid="placements-next">Next</button>
      </div>
      </div>
    </section>
  );
}

function PageKpi({ label, value, tone = 'blue' }) {
  return (
    <div className={`page-kpi page-kpi--${tone}`}>
      <span>{label}</span>
      <strong>{Number(value || 0).toLocaleString()}</strong>
    </div>
  );
}

function isWithinThirtyDays(value) {
  if (!value) return false;
  const date = new Date(`${value}T00:00:00`);
  const today = new Date();
  const days = (date.getTime() - today.getTime()) / 86400000;
  return days >= 0 && days <= 30;
}

function initials(firstName, lastName) {
  return `${String(firstName || '').charAt(0)}${String(lastName || '').charAt(0)}`.toUpperCase() || 'P';
}

function formatRate(value) {
  if (value === null || value === undefined || value === '') return '—';
  return Number(value).toLocaleString(undefined, { style: 'currency', currency: 'USD', minimumFractionDigits: 2 });
}

function formatMargin(value) {
  if (value === null || value === undefined || value === '') return '—';
  return `${(Number(value) * 100).toFixed(1)}%`;
}

function marginTone(value) {
  if (value === null || value === undefined || value === '') return '';
  return Number(value) < 0 ? 'margin-negative' : 'margin-positive';
}
