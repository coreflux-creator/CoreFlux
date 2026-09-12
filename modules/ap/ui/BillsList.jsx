import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { useApiCached, bustApiCachePrefix, prefetchApi } from '../../../dashboard/src/lib/api';
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
  ChevronRight, CreditCard, Download, MoreHorizontal, Plus, Search, Upload,
} from 'lucide-react';

const STATUS_FILTERS = [
  { id: 'all', label: 'All bills', countKey: 'total_count' },
  { id: 'ready_to_pay', label: 'Ready to pay', countKey: 'ready_count' },
  { id: 'pending_approval', label: 'Awaiting approval', countKey: 'pending_count' },
  { id: 'needs_review', label: 'Needs review', countKey: 'review_count' },
  { id: 'paid', label: 'Paid', countKey: 'paid_count' },
  { id: 'void', label: 'Voided', countKey: 'void_count' },
];

export default function BillsList() {
  const [status, setStatus] = useState('all');
  const [showFromBundle, setShowFromBundle] = useState(false);
  const [showFromEntries, setShowFromEntries] = useState(false);
  const [showSuggestRun, setShowSuggestRun] = useState(false);
  const { activeEntityId, activeEntity } = useActiveEntity();
  const qs = new URLSearchParams();
  if (status !== 'all') qs.set('status', status);
  if (activeEntityId) qs.set('entity_id', String(activeEntityId));
  const path = '/modules/ap/api/bills.php' + (qs.toString() ? `?${qs}` : '');
  const { data, loading, error, reload } = useApiCached(path, { cacheKey: `ap-bills-list:${path}` });
  const rows = data?.rows ?? [];
  const summary = data?.summary ?? {};
  const qboDrift = useQboDriftBadges('bill', rows.map(row => row.id));
  const sel = useBulkSelection(rows.map(row => row.id));

  const {
    items, sortKey, sortDir, search, setSearch, headerProps,
  } = useTableList(rows, {
    defaultSort: { key: 'bill_date', dir: 'desc' },
    searchKeys: ['internal_ref', 'bill_number', 'vendor_name', 'vendor_type', 'status', 'source', 'placement_id'],
    dateKeys: ['bill_date', 'due_date'],
    numericKeys: ['id', 'total', 'amount_due'],
  });

  const exportSelected = () => {
    if (!sel.size) return;
    const anchor = document.createElement('a');
    anchor.href = `/modules/ap/api/export.php?type=bills&ids=${sel.ids.join(',')}`;
    anchor.rel = 'noopener';
    anchor.click();
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
                onClick={() => setStatus(item.id)}
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
              value={search}
              onChange={event => setSearch(event.target.value)}
              data-testid="ap-bills-search"
            />
          </div>
          <span className="operational-toolbar__count" data-testid="ap-bills-match-count">{items.length} of {rows.length}</span>
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
            <button className="btn btn--primary" onClick={exportSelected} data-testid="ap-bills-export-selected">Export selected</button>
            <button className="btn btn--ghost" onClick={sel.clear} data-testid="ap-bills-clear-selection">Clear</button>
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
                  <td className="numeric-cell">{formatCurrency(r.amount_due, r.currency)}</td>
                  <td><span className={`badge badge--${r.status}`}>{String(r.status).replaceAll('_', ' ')}</span><QboDriftBadge entry={qboDrift[r.id]} /></td>
                  <td><span className="source-label">{String(r.source || r.vendor_type || 'manual').replaceAll('_', ' ')}</span></td>
                  <td><Link to={`/modules/ap/bills/${r.id}`} className="row-open-link" aria-label={`Open bill ${r.internal_ref || r.id}`}><ChevronRight size={17} aria-hidden="true" /></Link></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <footer className="operational-footer">
          <span>Showing {items.length} of {data?.total ?? rows.length} bills</span>
          <span>Amounts reflect the active entity and bill status.</span>
        </footer>
      </div>

      {showFromBundle && (
        <BillFromTimeBundleModal onClose={() => setShowFromBundle(false)} onCreated={() => { setShowFromBundle(false); bustApiCachePrefix('ap-bills-list:'); reload(); }} />
      )}
      {showFromEntries && (
        <BillFromTimeEntriesModal onClose={() => setShowFromEntries(false)} onCreated={() => { setShowFromEntries(false); bustApiCachePrefix('ap-bills-list:'); reload(); }} />
      )}
      {showSuggestRun && (
        <SuggestPaymentRunModal onClose={() => setShowSuggestRun(false)} onCreated={() => { setShowSuggestRun(false); bustApiCachePrefix('ap-bills-list:'); reload(); }} />
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
