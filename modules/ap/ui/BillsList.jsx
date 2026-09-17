import React, { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { api, useApiCached, bustApiCachePrefix, prefetchApi } from '../../../dashboard/src/lib/api';
import { useBulkSelection } from '../../../dashboard/src/lib/useBulkSelection';
import { useActiveEntity } from '../../../dashboard/src/lib/useActiveEntity';
import { useTableList, SortIndicator } from '../../../dashboard/src/lib/useTableList';
import { fmtDate } from '../../../dashboard/src/lib/formatDate';
import BillFromTimeBundleModal from './BillFromTimeBundleModal';
import BillFromTimeEntriesModal from './BillFromTimeEntriesModal';
import SuggestPaymentRunModal from './SuggestPaymentRunModal';
import ExportTemplatePicker from '../../../dashboard/src/components/ExportTemplatePicker';
import { QboDriftBadge, useQboDriftBadges } from '../../../dashboard/src/components/QboDriftBadge';
import ApprovedHoursReadyTile from '../../staffing/ui/ApprovedHoursReadyTile';
import IdBadge from '../../../dashboard/src/components/IdBadge';
import {
  BookOpenCheck, CheckCheck, ChevronLeft, ChevronRight, CreditCard, Download,
  MoreHorizontal, Plus, Search, Upload,
} from 'lucide-react';

const STATUS_FILTERS = [
  { id: 'all', label: 'All bills', countKey: 'total_count' },
  { id: 'ready_to_pay', label: 'Ready to pay', countKey: 'ready_count' },
  { id: 'pending_approval', label: 'Awaiting approval', countKey: 'pending_count' },
  { id: 'needs_review', label: 'Needs review', countKey: 'review_count' },
  { id: 'paid', label: 'Paid', countKey: 'paid_count' },
  { id: 'void', label: 'Voided', countKey: 'void_count' },
];
const statusLabel = (value) => String(value || '—').replaceAll('_', ' ').replace(/\b\w/g, char => char.toUpperCase());

export default function BillsList() {
  const navigate = useNavigate();
  const [status, setStatus] = useState('all');
  const [showFromBundle, setShowFromBundle] = useState(false);
  const [showFromEntries, setShowFromEntries] = useState(false);
  const [showSuggestRun, setShowSuggestRun] = useState(false);
  const [searchInput, setSearchInput] = useState('');
  const [query, setQuery] = useState('');
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(50);
  const [bulkBusy, setBulkBusy] = useState(false);
  const [bulkResult, setBulkResult] = useState(null);
  const { activeEntityId, activeEntity } = useActiveEntity();
  const qs = new URLSearchParams();
  if (status !== 'all') qs.set('status', status);
  if (activeEntityId) qs.set('entity_id', String(activeEntityId));
  if (query) qs.set('q', query);
  qs.set('page', String(page));
  qs.set('per_page', String(perPage));
  const path = '/modules/ap/api/bills.php' + (qs.toString() ? `?${qs}` : '');
  const { data, loading, error, reload } = useApiCached(path, { cacheKey: `ap-bills-list:${path}` });
  const rows = data?.rows ?? [];
  const total = Number(data?.total ?? 0);
  const totalPages = Math.max(1, Math.ceil(total / perPage));
  const summary = data?.summary ?? {};
  const qboDrift = useQboDriftBadges('bill', rows.map(row => row.id));
  const sel = useBulkSelection(rows.map(row => row.id));
  const clearSelection = sel.clear;

  useEffect(() => {
    const timer = setTimeout(() => {
      setQuery(searchInput.trim());
      setPage(1);
    }, 300);
    return () => clearTimeout(timer);
  }, [searchInput]);

  useEffect(() => {
    clearSelection();
    setBulkResult(null);
  }, [status, query, page, perPage, activeEntityId, clearSelection]);

  useEffect(() => {
    if (page > totalPages) setPage(totalPages);
  }, [page, totalPages]);

  const {
    items, sortKey, sortDir, headerProps,
  } = useTableList(rows, {
    defaultSort: { key: 'bill_date', dir: 'desc' },
    searchKeys: [],
    dateKeys: ['bill_date', 'due_date'],
    numericKeys: ['id', 'total', 'amount_due'],
  });
  const selectedRows = useMemo(() => items.filter((row) => sel.has(row.id)), [items, sel.ids]); // eslint-disable-line react-hooks/exhaustive-deps
  const approvableRows = useMemo(
    () => selectedRows.filter((row) => ['pending_review', 'pending_approval'].includes(row.status)),
    [selectedRows]
  );
  const postableRows = useMemo(
    () => selectedRows.filter((row) => ['approved', 'partially_paid', 'paid'].includes(row.status) && !row.journal_entry_id),
    [selectedRows]
  );

  const exportSelected = () => {
    if (!sel.size) return;
    const anchor = document.createElement('a');
    anchor.href = `/modules/ap/api/export.php?type=bills&ids=${sel.ids.join(',')}`;
    anchor.rel = 'noopener';
    anchor.click();
  };
  const approveSelected = async () => {
    if (!approvableRows.length) return;
    if (!confirm(
      `Approve ${approvableRows.length} selected bill${approvableRows.length === 1 ? '' : 's'}?\n\n` +
      'Every bill will still pass its two-eye, approval-policy, and three-way-match controls.'
    )) return;

    setBulkBusy(true); setBulkResult(null);
    const failures = [];
    const awaiting = [];
    let succeeded = 0;
    try {
      for (const row of approvableRows) {
        try {
          const result = await api.post(`/modules/ap/api/bills.php?action=approve&id=${row.id}`, {});
          if (result?.workflow_status === 'approved') succeeded += 1;
          else awaiting.push({ id: row.id, reason: 'Waiting for another required approval.' });
        } catch (e) {
          failures.push({ id: row.id, reason: e?.message || String(e) });
        }
      }
      sel.selectMany([...failures, ...awaiting].map((entry) => entry.id));
      setBulkResult({ action: 'approval', succeeded, awaiting, failures });
      bustApiCachePrefix('ap-bills-list:');
      await reload();
    } finally {
      setBulkBusy(false);
    }
  };

  const postSelected = async () => {
    if (!postableRows.length) return;
    if (!confirm(
      `Post ${postableRows.length} selected bill${postableRows.length === 1 ? '' : 's'} to the general ledger?\n\n` +
      'CoreFlux will create balanced, idempotent journal entries. Already-posted bills are excluded.'
    )) return;

    setBulkBusy(true); setBulkResult(null);
    const failures = [];
    let succeeded = 0;
    try {
      for (const row of postableRows) {
        try {
          await api.post(`/modules/ap/api/bills.php?action=post&id=${row.id}`, {});
          succeeded += 1;
        } catch (e) {
          failures.push({ id: row.id, reason: e?.message || String(e) });
        }
      }
      sel.selectMany(failures.map((entry) => entry.id));
      setBulkResult({ action: 'posting', succeeded, awaiting: [], failures });
      bustApiCachePrefix('ap-bills-list:');
      await reload();
    } finally {
      setBulkBusy(false);
    }
  };
  const buildTemplateExportHref = (templateId) => {
    const params = new URLSearchParams({ template_id: String(templateId) });
    if (status !== 'all') params.set('status', status);
    return `/api/v1/ap/bills-csv-export?${params.toString()}`;
  };

  return (
    <section className="module-list-page ap-bills-page" data-testid="ap-bills-list">
      <div className="ap-summary-strip" aria-label="Accounts payable summary">
        <SummaryStat label="Open payables" value={formatCurrency(summary.open_amount)} sub={`${number(summary.open_count)} open bills`} tone="blue" />
        <SummaryStat label="Ready to pay" value={formatCurrency(summary.ready_amount)} sub={`${number(summary.ready_count)} payment-ready bills`} tone="green" />
        <SummaryStat label="Awaiting approval" value={number(summary.pending_count)} sub="Bills requiring approval" tone="amber" />
        <SummaryStat label="Needs review" value={number(summary.review_count)} sub="Matching or document exceptions" tone="red" />
      </div>

      {activeEntity && (
        <div className="entity-scope-note" data-testid="ap-bills-entity-scope">
          Showing <strong>{activeEntity.code}</strong>. Use the header to change entities.
        </div>
      )}

      <ApprovedHoursReadyTile variant="ap" onPick={() => setShowFromEntries(true)} />

      <div className="operational-surface ap-bills-surface">
        <div className="record-view-tabs record-view-tabs--with-actions">
          <div className="record-view-tabs__items" aria-label="Bill status">
            {STATUS_FILTERS.map(item => (
              <button
                key={item.id}
                type="button"
                data-testid={`ap-bills-filter-${item.id}`}
                onClick={() => { setStatus(item.id); setPage(1); }}
                className={status === item.id ? 'is-active' : ''}
              >
                {item.label}
                {item.countKey && <span>{number(summary[item.countKey])}</span>}
              </button>
            ))}
          </div>
          <div className="operational-heading-actions">
            <Link to="csv_import" className="btn" data-testid="ap-bills-import-csv"><Upload size={15} aria-hidden="true" /> Import</Link>
            <button className="btn" onClick={() => setShowSuggestRun(true)} data-testid="ap-bills-suggest-payment-run">
              <CreditCard size={15} aria-hidden="true" /> Payment run
            </button>
            <Link to="new" className="btn btn--primary" data-testid="ap-new-bill"><Plus size={16} aria-hidden="true" /> New bill</Link>
          </div>
        </div>

        <div className="operational-toolbar">
          <div className="directory-filter-bar__search">
            <Search size={16} aria-hidden="true" />
            <input
              type="search"
              className="input"
              placeholder="Search vendor, bill, placement or source"
              value={searchInput}
              onChange={event => setSearchInput(event.target.value)}
              data-testid="ap-bills-search"
            />
          </div>
          <span className="operational-toolbar__count" data-testid="ap-bills-match-count">{items.length} on this page · {total} total</span>
          <details className="action-overflow">
            <summary className="btn" aria-label="More bill actions"><MoreHorizontal size={16} aria-hidden="true" /> More</summary>
            <div className="action-overflow__menu">
              <button className="action-overflow__item" onClick={() => setShowFromBundle(true)} data-testid="ap-new-from-time-bundle">New from time bundle</button>
              <button className="action-overflow__item" onClick={() => setShowFromEntries(true)} data-testid="ap-bills-new-from-time-entries">New from approved hours</button>
              <a className="action-overflow__item" href={`/api/v1/ap/bills-csv-export${status !== 'all' ? `?status=${status}` : ''}`} data-testid="ap-bills-export-all-csv"><Download size={15} aria-hidden="true" /> Export all</a>
              <ExportTemplatePicker
                dataset="ap_bills"
                buildHref={buildTemplateExportHref}
                label="Export template"
                testid="ap-bills-export-template"
              />
            </div>
          </details>
        </div>

        {sel.size > 0 && (
          <div className="selection-bar" data-testid="ap-bills-bulk-bar">
            <span><strong>{sel.size}</strong> selected</span>
            <button className="btn btn--primary" onClick={approveSelected} disabled={bulkBusy || !approvableRows.length} data-testid="ap-bills-approve-selected">
              <CheckCheck size={15} aria-hidden="true" /> Approve ({approvableRows.length})
            </button>
            <button className="btn btn--ghost" onClick={postSelected} disabled={bulkBusy || !postableRows.length} data-testid="ap-bills-post-selected">
              <BookOpenCheck size={15} aria-hidden="true" /> Post to ledger ({postableRows.length})
            </button>
            <button className="btn btn--ghost" onClick={exportSelected} disabled={bulkBusy} data-testid="ap-bills-export-selected"><Download size={15} aria-hidden="true" /> Export</button>
            <button className="btn btn--ghost" onClick={sel.clear} disabled={bulkBusy} data-testid="ap-bills-clear-selection">Clear</button>
          </div>
        )}

        {bulkResult && (
          <div className={bulkResult.failures.length ? 'error' : 'success'} data-testid="ap-bills-bulk-result" style={{ margin: '0 0 10px' }}>
            {bulkResult.succeeded} completed {bulkResult.action}.
            {bulkResult.awaiting?.length > 0 && <> {bulkResult.awaiting.length} awaiting another approver.</>}
            {bulkResult.failures.length > 0 && (
              <> {bulkResult.failures.length} need attention: {bulkResult.failures.map((failure) => `Bill ${failure.id}: ${failure.reason}`).join('; ')}</>
            )}
          </div>
        )}

        {loading && <p className="operational-state">Loading...</p>}
        {error && <p className="error operational-state" data-testid="ap-bills-error">Error: {error.message}</p>}

        <div className="data-table-wrap operational-table-wrap">
          <table className="data-table operational-table" data-testid="ap-bills-table">
            <thead>
              <tr>
                <th style={{ width: 32 }}>
                  <input
                    type="checkbox"
                    checked={sel.allSelected}
                    ref={element => { if (element) element.indeterminate = sel.someSelected; }}
                    onChange={sel.toggleAll}
                    disabled={!rows.length}
                    data-testid="ap-bills-select-all"
                  />
                </th>
                <th {...headerProps('id', 'ap-bills-sort')}>ID <SortIndicator active={sortKey === 'id'} dir={sortDir} /></th>
                <th {...headerProps('vendor_name', 'ap-bills-sort')}>Vendor / Bill <SortIndicator active={sortKey === 'vendor_name'} dir={sortDir} /></th>
                <th {...headerProps('bill_date', 'ap-bills-sort')}>Bill date <SortIndicator active={sortKey === 'bill_date'} dir={sortDir} /></th>
                <th {...headerProps('due_date', 'ap-bills-sort')}>Due <SortIndicator active={sortKey === 'due_date'} dir={sortDir} /></th>
                <th {...headerProps('total', 'ap-bills-sort')} className="numeric-cell">Amount <SortIndicator active={sortKey === 'total'} dir={sortDir} /></th>
                <th {...headerProps('amount_due', 'ap-bills-sort')} className="numeric-cell">Amount due <SortIndicator active={sortKey === 'amount_due'} dir={sortDir} /></th>
                <th {...headerProps('status', 'ap-bills-sort')}>Status <SortIndicator active={sortKey === 'status'} dir={sortDir} /></th>
                <th {...headerProps('source', 'ap-bills-sort')}>Source <SortIndicator active={sortKey === 'source'} dir={sortDir} /></th>
                <th style={{ width: 36 }} aria-label="Open" />
              </tr>
            </thead>
            <tbody>
              {items.length === 0 && !loading && <tr><td colSpan={10} className="empty" data-testid="ap-bills-empty">No bills match this view.</td></tr>}
              {items.map(r => (
                <tr key={r.id} data-testid={`ap-bill-row-${r.id}`} className={sel.has(r.id) ? 'is-selected' : ''}>
                  <td><input type="checkbox" checked={sel.has(r.id)} onChange={() => sel.toggle(r.id)} data-testid={`ap-bill-select-${r.id}`} /></td>
                  <td><IdBadge id={r.id} prefix="B" /></td>
                  <td>
                    <div className="entity-cell">
                      <span className={`entity-avatar entity-avatar--${Number(r.id) % 4}`}>{initials(r.vendor_name)}</span>
                      <span>
                        <Link
                          className="entity-primary"
                          to={`/modules/ap/bills/${r.id}`}
                          data-testid={`ap-bill-link-${r.id}`}
                          onMouseEnter={() => prefetchApi(`/modules/ap/api/bill_detail.php?id=${r.id}`, `ap-bill-detail:${r.id}`)}
                        >
                          {r.vendor_name || 'Unnamed vendor'}
                        </Link>
                        <span className="entity-secondary">
                          {r.internal_ref || r.bill_number || `B-${r.id}`}{r.placement_id ? ` · PL-${r.placement_id}` : ''}
                        </span>
                      </span>
                    </div>
                  </td>
                  <td>{fmtDate(r.bill_date)}</td>
                  <td>{fmtDate(r.due_date)}</td>
                  <td className="numeric-cell">{formatCurrency(r.total, r.currency)}</td>
                  <td className="numeric-cell">
                    {formatCurrency(r.amount_due, r.currency)}
                    {Number(r.payment_reserved) > 0 && (
                      <span className="entity-secondary" title="Allocated to a draft or queued payment; not yet released">
                        {formatCurrency(r.payment_reserved, r.currency)} reserved
                      </span>
                    )}
                  </td>
                  <td><span className={`badge badge--${r.status}`}>{statusLabel(r.status)}</span><QboDriftBadge entry={qboDrift[r.id]} /></td>
                  <td><span className="source-label">{statusLabel(r.source || r.vendor_type || 'manual')}</span></td>
                  <td><Link to={`/modules/ap/bills/${r.id}`} className="row-open-link" aria-label={`Open bill ${r.internal_ref || r.id}`}><ChevronRight size={17} aria-hidden="true" /></Link></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <footer className="operational-footer">
          <span>
            {total > 0 ? `Showing ${(page - 1) * perPage + 1}–${Math.min(page * perPage, total)} of ${total} bills` : 'No bills'}
          </span>
          {total > 0 && (
            <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }} data-testid="ap-bills-pagination">
              <label htmlFor="ap-bills-per-page">Rows</label>
              <select
                id="ap-bills-per-page"
                className="input"
                value={perPage}
                onChange={(event) => { setPerPage(Number(event.target.value)); setPage(1); }}
                data-testid="ap-bills-per-page"
                style={{ width: 72, paddingBlock: 4 }}
              >
                {[25, 50, 100, 200].map((size) => <option key={size} value={size}>{size}</option>)}
              </select>
              <button type="button" className="btn btn--ghost btn--sm" onClick={() => setPage((current) => Math.max(1, current - 1))} disabled={page <= 1 || loading} aria-label="Previous bills page" data-testid="ap-bills-page-previous"><ChevronLeft size={16} /></button>
              <span>Page {page} of {totalPages}</span>
              <button type="button" className="btn btn--ghost btn--sm" onClick={() => setPage((current) => Math.min(totalPages, current + 1))} disabled={page >= totalPages || loading} aria-label="Next bills page" data-testid="ap-bills-page-next"><ChevronRight size={16} /></button>
            </span>
          )}
        </footer>
      </div>

      {showFromBundle && (
        <BillFromTimeBundleModal onClose={() => setShowFromBundle(false)} onCreated={() => { setShowFromBundle(false); bustApiCachePrefix('ap-bills-list:'); reload(); }} />
      )}
      {showFromEntries && (
        <BillFromTimeEntriesModal onClose={() => setShowFromEntries(false)} onCreated={() => { setShowFromEntries(false); bustApiCachePrefix('ap-bills-list:'); reload(); }} />
      )}
      {showSuggestRun && (
        <SuggestPaymentRunModal
          entityId={activeEntityId}
          onClose={() => setShowSuggestRun(false)}
          onCreated={(result) => {
            setShowSuggestRun(false);
            bustApiCachePrefix('ap-bills-list:');
            navigate('/modules/ap/payments', { state: { paymentRun: result } });
          }}
        />
      )}
    </section>
  );
}

function SummaryStat({ label, value, sub, tone }) {
  return (
    <div className={`ap-summary-stat ap-summary-stat--${tone}`}>
      <span>{label}</span>
      <strong>{value}</strong>
      <small>{sub}</small>
    </div>
  );
}

function number(value) {
  return Number(value || 0).toLocaleString();
}

function formatCurrency(value, currency = 'USD') {
  return Number(value || 0).toLocaleString(undefined, {
    style: 'currency', currency: currency || 'USD', minimumFractionDigits: 0, maximumFractionDigits: 2,
  });
}

function initials(value) {
  const parts = String(value || '').trim().split(/\s+/).filter(Boolean);
  return (parts.slice(0, 2).map(part => part.charAt(0)).join('') || 'V').toUpperCase();
}
