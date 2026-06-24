import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { api, useApiCached, bustApiCachePrefix, prefetchApi } from '../../../dashboard/src/lib/api'; // eslint-disable-line no-unused-vars
import { useBulkSelection } from '../../../dashboard/src/lib/useBulkSelection';
import { useActiveEntity } from '../../../dashboard/src/lib/useActiveEntity';
import { useTableList, SortIndicator } from '../../../dashboard/src/lib/useTableList';
import { fmtDate } from '../../../dashboard/src/lib/formatDate';
import BillFromTimeBundleModal from './BillFromTimeBundleModal';
import BillFromTimeEntriesModal from './BillFromTimeEntriesModal';
import SuggestPaymentRunModal from './SuggestPaymentRunModal';
import IdBadge from '../../../dashboard/src/components/IdBadge';
import { QboDriftBadge, useQboDriftBadges } from '../../../dashboard/src/components/QboDriftBadge';
import ExportTemplatePicker from '../../../dashboard/src/components/ExportTemplatePicker';
import ApprovedHoursReadyTile from '../../staffing/ui/ApprovedHoursReadyTile';

const STATUS_FILTERS = ['all','pending_approval','approved','partially_paid','paid','disputed','void'];

export default function BillsList() {
  const [status, setStatus] = useState('all');
  const [showFromBundle, setShowFromBundle] = useState(false);
  const [showFromEntries, setShowFromEntries] = useState(false);
  const [showSuggestRun, setShowSuggestRun] = useState(false);
  const { activeEntityId, activeEntity } = useActiveEntity();
  const qs = new URLSearchParams();
  if (status !== 'all')    qs.set('status', status);
  if (activeEntityId)      qs.set('entity_id', String(activeEntityId));
  const path = '/modules/ap/api/bills.php' + (qs.toString() ? `?${qs}` : '');
  const { data, loading, error, reload } = useApiCached(
    path,
    { cacheKey: `ap-bills-list:${path}` }
  );
  const rows = data?.rows ?? [];
  const sel = useBulkSelection(rows.map(r => r.id));

  // Batch-fetch QBO drift snapshots for every bill in the current view.
  // The hook collapses the ids array to a stable key so we only refetch
  // when the list itself changes.
  const qboDrift = useQboDriftBadges('bill', rows.map(r => r.id));

  const {
    items, sortKey, sortDir, search, setSearch, headerProps,
  } = useTableList(rows, {
    defaultSort: { key: 'bill_date', dir: 'desc' },
    searchKeys:  ['internal_ref', 'vendor_name', 'vendor_type', 'status'],
    dateKeys:    ['bill_date', 'due_date'],
    numericKeys: ['id', 'total', 'amount_due'],
  });

  const exportSelected = () => {
    if (!sel.size) return;
    const a = document.createElement('a');
    a.href = `/modules/ap/api/export.php?type=bills&ids=${sel.ids.join(',')}`;
    a.rel  = 'noopener';
    a.click();
  };
  const buildTemplateExportHref = (tplId) => {
    const params = new URLSearchParams({ template_id: String(tplId) });
    if (status !== 'all') params.set('status', status);
    return `/api/v1/ap/bills-csv-export?${params.toString()}`;
  };

  return (
    <section data-testid="ap-bills-list">
      {activeEntity && (
        <div data-testid="ap-bills-entity-scope"
             style={{ fontSize: 12, color: '#1e40af', marginBottom: 8 }}>
          Scoped to entity <code>{activeEntity.code}</code> — switch in the header to see another.
        </div>
      )}
      <ApprovedHoursReadyTile variant="ap" onPick={() => setShowFromEntries(true)} />
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 'var(--cf-space-4)', flexWrap: 'wrap', gap: 8 }}>
        <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
          {STATUS_FILTERS.map(s => (
            <button
              key={s}
              data-testid={`ap-bills-filter-${s}`}
              onClick={() => setStatus(s)}
              style={{
                padding: '4px 10px', borderRadius: 999, border: '1px solid var(--cf-border, #e5e7eb)',
                background: status === s ? 'var(--cf-text, #111827)' : 'transparent',
                color: status === s ? '#fff' : 'var(--cf-text-secondary, #6b7280)',
                fontSize: 12, cursor: 'pointer',
              }}
            >{s.replace('_', ' ')}</button>
          ))}
        </div>
        <div style={{ display: 'flex', gap: 8 }}>
          <Link to="new" className="btn btn--primary" data-testid="ap-new-bill">+ New bill</Link>
          <button className="btn btn--ghost" onClick={() => setShowFromBundle(true)} data-testid="ap-new-from-time-bundle">
            New from time bundle
          </button>
          <button className="btn btn--ghost" onClick={() => setShowFromEntries(true)} data-testid="ap-bills-new-from-time-entries">
            New from approved hours (day-level)
          </button>
          <button className="btn btn--primary" onClick={() => setShowSuggestRun(true)}
                  style={{ background: 'linear-gradient(135deg, #059669, #2563eb)', border: 0 }}
                  data-testid="ap-bills-suggest-payment-run">
            ✨ Suggest payment run
          </button>
          <Link to="csv_import" className="btn" data-testid="ap-bills-import-csv">Import CSV</Link>
          <a className="btn" href={`/api/v1/ap/bills-csv-export${status !== 'all' ? `?status=${status}` : ''}`} data-testid="ap-bills-export-all-csv">Export all (CSV)</a>
          <ExportTemplatePicker
            dataset="ap_bills"
            buildHref={buildTemplateExportHref}
            label="Export via template"
            testid="ap-bills-export-template"
          />
        </div>
      </div>

      <div style={{ display: 'flex', gap: 8, marginBottom: 8, alignItems: 'center' }}>
        <input
          type="search"
          className="input"
          placeholder="Search ref, vendor, type…"
          value={search}
          onChange={e => setSearch(e.target.value)}
          data-testid="ap-bills-search"
          style={{ maxWidth: 320 }}
        />
        <span style={{ fontSize: 11, color: 'var(--cf-text-secondary, #6b7280)' }}
              data-testid="ap-bills-match-count">
          {items.length} of {rows.length}
        </span>
      </div>

      {sel.size > 0 && (
        <div data-testid="ap-bills-bulk-bar" style={billsBulkBar}>
          <span><strong>{sel.size}</strong> selected</span>
          <button className="btn btn--primary" onClick={exportSelected} data-testid="ap-bills-export-selected">
            Export selected (CSV)
          </button>
          <button className="btn btn--ghost" onClick={sel.clear} data-testid="ap-bills-clear-selection">Clear</button>
        </div>
      )}

      {loading && <p>Loading…</p>}
      {error && <p className="error" data-testid="ap-bills-error">Error: {error.message}</p>}

      <table className="data-table" data-testid="ap-bills-table">
        <thead>
          <tr>
            <th style={{ width: 32 }}>
              <input
                type="checkbox"
                checked={sel.allSelected}
                ref={el => { if (el) el.indeterminate = sel.someSelected; }}
                onChange={sel.toggleAll}
                disabled={!rows.length}
                data-testid="ap-bills-select-all"
              />
            </th>
            <th>ID</th>
            <th {...headerProps('internal_ref', 'ap-bills-sort')}>Ref <SortIndicator active={sortKey === 'internal_ref'} dir={sortDir} /></th>
            <th {...headerProps('vendor_name', 'ap-bills-sort')}>Vendor <SortIndicator active={sortKey === 'vendor_name'} dir={sortDir} /></th>
            <th {...headerProps('vendor_type', 'ap-bills-sort')}>Type <SortIndicator active={sortKey === 'vendor_type'} dir={sortDir} /></th>
            <th {...headerProps('bill_date', 'ap-bills-sort')}>Bill date <SortIndicator active={sortKey === 'bill_date'} dir={sortDir} /></th>
            <th {...headerProps('due_date', 'ap-bills-sort')}>Due <SortIndicator active={sortKey === 'due_date'} dir={sortDir} /></th>
            <th {...headerProps('total', 'ap-bills-sort')} style={{ cursor: 'pointer', userSelect: 'none', textAlign:'right'}}>Total <SortIndicator active={sortKey === 'total'} dir={sortDir} /></th>
            <th {...headerProps('amount_due', 'ap-bills-sort')} style={{ cursor: 'pointer', userSelect: 'none', textAlign:'right'}}>Due <SortIndicator active={sortKey === 'amount_due'} dir={sortDir} /></th>
            <th {...headerProps('status', 'ap-bills-sort')}>Status <SortIndicator active={sortKey === 'status'} dir={sortDir} /></th>
          </tr>
        </thead>
        <tbody>
          {items.length === 0 && !loading && <tr><td colSpan={10} className="empty" data-testid="ap-bills-empty">No bills yet.</td></tr>}
          {items.map(r => (
            <tr key={r.id} data-testid={`ap-bill-row-${r.id}`} style={sel.has(r.id) ? { background: 'var(--cf-surface-alt, #f9fafb)' } : null}>
              <td>
                <input
                  type="checkbox"
                  checked={sel.has(r.id)}
                  onChange={() => sel.toggle(r.id)}
                  data-testid={`ap-bill-select-${r.id}`}
                />
              </td>
              <td><IdBadge id={r.id} prefix="B" /></td>
              <td>
                <Link
                  to={`/modules/ap/bills/${r.id}`}
                  data-testid={`ap-bill-link-${r.id}`}
                  onMouseEnter={() => prefetchApi(
                    `/modules/ap/api/bill_detail.php?id=${r.id}`,
                    `ap-bill-detail:${r.id}`
                  )}
                >
                  {r.internal_ref}
                </Link>
              </td>
              <td>{r.vendor_name}</td>
              <td><span className="badge">{r.vendor_type}</span></td>
              <td>{fmtDate(r.bill_date)}</td>
              <td>{fmtDate(r.due_date)}</td>
              <td style={{ textAlign: 'right' }}>{Number(r.total).toFixed(2)} {r.currency}</td>
              <td style={{ textAlign: 'right' }}>{Number(r.amount_due).toFixed(2)}</td>
              <td><span className={`badge badge--${r.status}`}>{r.status.replace('_',' ')}</span><QboDriftBadge entry={qboDrift[r.id]} /></td>
            </tr>
          ))}
        </tbody>
      </table>

      {showFromBundle && (
        <BillFromTimeBundleModal
          onClose={() => setShowFromBundle(false)}
          onCreated={() => {
            setShowFromBundle(false);
            // New bill changes counts/filters across the AP list — bust the
            // whole prefix so sibling status tabs (Pending approval, Paid,
            // etc.) reflect the new row on next mount.
            bustApiCachePrefix('ap-bills-list:');
            reload();
          }}
        />
      )}

      {showFromEntries && (
        <BillFromTimeEntriesModal
          onClose={() => setShowFromEntries(false)}
          onCreated={() => {
            setShowFromEntries(false);
            bustApiCachePrefix('ap-bills-list:');
            reload();
          }}
        />
      )}

      {showSuggestRun && (
        <SuggestPaymentRunModal
          onClose={() => setShowSuggestRun(false)}
          onCreated={() => {
            setShowSuggestRun(false);
            bustApiCachePrefix('ap-bills-list:');
            reload();
          }}
        />
      )}
    </section>
  );
}

const billsBulkBar = {
  display: 'flex', alignItems: 'center', gap: 12, padding: '10px 14px',
  background: 'var(--cf-surface-alt, #f3f4f6)', borderRadius: 8,
  marginBottom: 12, flexWrap: 'wrap',
};
