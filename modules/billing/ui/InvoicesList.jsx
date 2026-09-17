import React, { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { api, useApiCached, bustApiCachePrefix, prefetchApi } from '../../../dashboard/src/lib/api';
import { useTableList, SortIndicator } from '../../../dashboard/src/lib/useTableList';
import { fmtDate } from '../../../dashboard/src/lib/formatDate';
import InvoiceFromTimeBundleModal from './InvoiceFromTimeBundleModal';
import InvoiceFromTimeEntriesModal from './InvoiceFromTimeEntriesModal';
import QboPaymentsCollectModal from './QboPaymentsCollectModal';
import IdBadge from '../../../dashboard/src/components/IdBadge';
import { QboDriftBadge, useQboDriftBadges } from '../../../dashboard/src/components/QboDriftBadge';
import ApprovedHoursReadyTile from '../../staffing/ui/ApprovedHoursReadyTile';
import {
  BookOpenCheck, CheckCheck, ChevronDown, ChevronLeft, ChevronRight,
  Clock3, Download, Plus, Send, Upload,
} from 'lucide-react';

const STATUS_FILTERS = ['all','draft','approved','sent','partially_paid','paid','void'];
const STATUS_LABELS = {
  all: 'All invoices', draft: 'Draft', approved: 'Approved', sent: 'Sent',
  partially_paid: 'Partially paid', paid: 'Paid', void: 'Voided',
};

export default function InvoicesList({ session }) {
  const [status, setStatus] = useState('all');
  const [showCreate, setShowCreate] = useState(false);
  const [showEntries, setShowEntries] = useState(false);
  const [collectInvoice, setCollectInvoice] = useState(null);
  const [selected, setSelected] = useState(() => new Set());
  const [bulkBusy, setBulkBusy] = useState(false);
  const [bulkResult, setBulkResult] = useState(null);
  const [searchInput, setSearchInput] = useState('');
  const [query, setQuery] = useState('');
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(50);
  const qs = new URLSearchParams();
  if (status !== 'all') qs.set('status', status);
  if (query) qs.set('q', query);
  qs.set('page', String(page));
  qs.set('per_page', String(perPage));
  const path = '/api/v1/billing/invoices' + (qs.toString() ? `?${qs}` : '');
  const { data, loading, error, reload } = useApiCached(
    path,
    { cacheKey: `billing-invoices-list:${path}` }
  );
  // The charge endpoint is tenant/master-admin gated and requires the
  // separate Intuit Payments OAuth scope. Hide the CTA when either
  // condition is absent instead of letting users discover it via 403/412.
  const user = session?.user || {};
  const canCollectViaQbo = ['master_admin', 'tenant_admin'].includes(user.global_role)
    || ['master_admin', 'tenant_admin'].includes(user.role);
  const qboStatus = useApiCached('/api/qbo/status.php?action=status', {
    enabled: canCollectViaQbo,
    cacheKey: 'qbo-status:invoice-collections',
  });
  const qboPaymentsEnabled = canCollectViaQbo
    && qboStatus.data?.connected === true
    && qboStatus.data?.payments_enabled === true
    && qboStatus.data?.payments_recaptcha_enabled === true;
  const rows = data?.rows ?? [];
  const total = Number(data?.total ?? 0);
  const totalPages = Math.max(1, Math.ceil(total / perPage));

  useEffect(() => {
    const timer = setTimeout(() => {
      setQuery(searchInput.trim());
      setPage(1);
    }, 300);
    return () => clearTimeout(timer);
  }, [searchInput]);

  useEffect(() => {
    setSelected(new Set());
    setBulkResult(null);
  }, [status, query, page, perPage]);

  useEffect(() => {
    if (page > totalPages) setPage(totalPages);
  }, [page, totalPages]);
  // Batch-fetch QBO drift snapshots so we can render a chip per row
  // without N+1 round-trips.
  const qboDrift = useQboDriftBadges('invoice', rows.map(r => r.id));

  const {
    items, sortKey, sortDir, headerProps,
  } = useTableList(rows, {
    defaultSort: { key: 'issue_date', dir: 'desc' },
    searchKeys:  [],
    dateKeys:    ['issue_date', 'due_date'],
    numericKeys: ['id', 'total', 'amount_due'],
  });
  const selectableItems = useMemo(() => items.filter((row) => (
    row.status === 'draft'
    || row.status === 'approved'
    || (['sent', 'partially_paid', 'paid'].includes(row.status) && !row.journal_entry_id)
  )), [items]);
  const selectedRows = useMemo(
    () => items.filter((row) => selected.has(row.id)),
    [items, selected]
  );
  const draftItems = useMemo(() => selectedRows.filter((row) => row.status === 'draft'), [selectedRows]);
  const postableItems = useMemo(() => selectedRows.filter((row) => (
    ['approved', 'sent', 'partially_paid', 'paid'].includes(row.status) && !row.journal_entry_id
  )), [selectedRows]);
  const sendableItems = useMemo(
    () => selectedRows.filter((row) => row.status === 'approved'),
    [selectedRows]
  );
  const allActionableSelected = selectableItems.length > 0
    && selectableItems.every((row) => selected.has(row.id));

  const toggleSelected = (id) => {
    setSelected((current) => {
      const next = new Set(current);
      if (next.has(id)) next.delete(id); else next.add(id);
      return next;
    });
  };

  const toggleAllActionable = () => {
    setSelected((current) => {
      const next = new Set(current);
      if (allActionableSelected) selectableItems.forEach((row) => next.delete(row.id));
      else selectableItems.forEach((row) => next.add(row.id));
      return next;
    });
  };

  const approveSelected = async () => {
    const ids = draftItems.map((row) => row.id);
    if (ids.length === 0) return;
    if (!confirm(
      `Approve ${ids.length} draft invoice${ids.length === 1 ? '' : 's'}?\n\n` +
      'Each invoice will still pass its normal approval policy and separation-of-duties checks. This will not send or post invoices.'
    )) return;

    setBulkBusy(true); setBulkResult(null);
    const failures = [];
    const awaiting = [];
    let succeeded = 0;
    try {
      for (const id of ids) {
        try {
          const result = await api.post(`/api/v1/billing/invoices?action=approve&id=${id}`, {});
          if (result?.approved) succeeded += 1;
          else awaiting.push({ id, reason: 'Waiting for another required approval.' });
        } catch (e) {
          failures.push({ id, reason: e?.message || String(e) });
        }
      }
      setSelected(new Set([...failures, ...awaiting].map((entry) => entry.id)));
      setBulkResult({ action: 'approval', succeeded, awaiting, failures });
      bustApiCachePrefix('billing-invoices-list:');
      await reload();
    } finally {
      setBulkBusy(false);
    }
  };

  const postSelected = async () => {
    const ids = postableItems.map((row) => row.id);
    if (ids.length === 0) return;
    if (!confirm(
      `Post ${ids.length} invoice${ids.length === 1 ? '' : 's'} to the general ledger?\n\n` +
      'CoreFlux will create balanced, idempotent journal entries. Already-posted invoices are excluded.'
    )) return;

    setBulkBusy(true); setBulkResult(null);
    const failures = [];
    let succeeded = 0;
    try {
      for (const id of ids) {
        try {
          await api.post(`/api/v1/billing/invoices?action=post&id=${id}`, {});
          succeeded += 1;
        } catch (e) {
          failures.push({ id, reason: e?.message || String(e) });
        }
      }
      setSelected(new Set(failures.map((failure) => failure.id)));
      setBulkResult({ action: 'posting', succeeded, awaiting: [], failures });
      bustApiCachePrefix('billing-invoices-list:');
      await reload();
    } finally {
      setBulkBusy(false);
    }
  };

  const sendSelected = async () => {
    const ids = sendableItems.map((row) => row.id);
    if (ids.length === 0) return;
    if (!confirm(
      `Email ${ids.length} approved invoice${ids.length === 1 ? '' : 's'} now?\n\n` +
      'Each message will use the invoice bill-to email or the client’s saved AR contact. Rows without a valid recipient will stay selected with an explanation.'
    )) return;

    setBulkBusy(true); setBulkResult(null);
    const failures = [];
    let succeeded = 0;
    try {
      for (const id of ids) {
        try {
          await api.post(`/api/v1/billing/invoices?action=send&id=${id}`, {});
          succeeded += 1;
        } catch (e) {
          failures.push({ id, reason: e?.message || String(e) });
        }
      }
      setSelected(new Set(failures.map((failure) => failure.id)));
      setBulkResult({ action: 'sending', succeeded, awaiting: [], failures });
      bustApiCachePrefix('billing-invoices-list:');
      await reload();
    } finally {
      setBulkBusy(false);
    }
  };

  return (
    <section data-testid="billing-invoices-list">
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 'var(--cf-space-4)' }}>
        <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
          {STATUS_FILTERS.map(s => (
            <button
              key={s}
              data-testid={`billing-filter-${s}`}
              onClick={() => { setStatus(s); setPage(1); }}
              style={{
                padding: '4px 10px', borderRadius: 999, border: '1px solid var(--cf-border, #e5e7eb)',
                background: status === s ? 'var(--cf-text, #111827)' : 'transparent',
                color: status === s ? '#fff' : 'var(--cf-text-secondary, #6b7280)',
                fontSize: 12, cursor: 'pointer',
              }}
            >{STATUS_LABELS[s] || s}</button>
          ))}
        </div>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap', justifyContent: 'flex-end' }}>
          <Link to="new" className="btn btn--primary" data-testid="billing-new-invoice"><Plus size={16} /> New invoice</Link>
          <details style={{ position: 'relative' }}>
            <summary className="btn btn--ghost" style={{ listStyle: 'none', cursor: 'pointer' }} data-testid="billing-create-from-time-menu">
              <Clock3 size={15} /> Create from time <ChevronDown size={14} />
            </summary>
            <div style={{ position: 'absolute', zIndex: 20, right: 0, top: 'calc(100% + 5px)', width: 230, padding: 6, background: 'var(--cf-surface)', border: '1px solid var(--cf-border)', borderRadius: 6, boxShadow: 'var(--cf-shadow-md)', display: 'grid', gap: 4 }}>
              <button className="btn btn--ghost" onClick={() => setShowCreate(true)} data-testid="billing-new-from-time-bundle">Time bundle</button>
              <button className="btn btn--ghost" onClick={() => setShowEntries(true)} data-testid="billing-new-from-time-entries">Approved hours</button>
            </div>
          </details>
          <Link to="csv_import" className="btn btn--ghost" data-testid="billing-invoices-import-csv"><Upload size={15} /> Import</Link>
          <a className="btn btn--ghost" href={`/modules/billing/api/csv_export.php${status !== 'all' ? `?status=${status}` : ''}`} data-testid="billing-invoices-export-csv"><Download size={15} /> Export</a>
        </div>
      </div>

      <div style={{ display: 'flex', gap: 8, marginBottom: 8, alignItems: 'center' }}>
        <input
          type="search"
          className="input"
          placeholder="Search invoice number or client…"
          value={searchInput}
          onChange={e => setSearchInput(e.target.value)}
          data-testid="billing-invoices-search"
          style={{ maxWidth: 320 }}
        />
        <span style={{ fontSize: 11, color: 'var(--cf-text-secondary, #6b7280)' }}
              data-testid="billing-invoices-match-count">
          {items.length} on this page · {total} total
        </span>
      </div>

      {selected.size > 0 && (
        <div className="selection-bar" data-testid="billing-invoices-bulk-bar">
          <span><strong>{selected.size}</strong> invoice{selected.size === 1 ? '' : 's'} selected</span>
          <button
            type="button"
            className="btn btn--primary"
            onClick={approveSelected}
            disabled={bulkBusy || draftItems.length === 0}
            data-testid="billing-invoices-approve-selected"
          >
            <CheckCheck size={15} aria-hidden="true" />
            Approve selected drafts ({draftItems.length})
          </button>
          <button
            type="button"
            className="btn btn--ghost"
            onClick={postSelected}
            disabled={bulkBusy || postableItems.length === 0}
            data-testid="billing-invoices-post-selected"
          >
            <BookOpenCheck size={15} aria-hidden="true" />
            Post to ledger ({postableItems.length})
          </button>
          <button
            type="button"
            className="btn btn--ghost"
            onClick={sendSelected}
            disabled={bulkBusy || sendableItems.length === 0}
            data-testid="billing-invoices-send-selected"
          >
            <Send size={15} aria-hidden="true" />
            Send ({sendableItems.length})
          </button>
          <button
            type="button"
            className="btn btn--ghost"
            onClick={() => setSelected(new Set())}
            disabled={bulkBusy}
            data-testid="billing-invoices-clear-selection"
          >
            Clear
          </button>
        </div>
      )}

      {bulkResult && (
        <div
          className={bulkResult.failures.length ? 'error' : 'success'}
          data-testid="billing-invoices-bulk-result"
          style={{ marginBottom: 10 }}
        >
          {bulkResult.succeeded} completed {bulkResult.action}.
          {bulkResult.awaiting?.length > 0 && (
            <> {bulkResult.awaiting.length} awaiting another approver.</>
          )}
          {bulkResult.failures.length > 0 && (
            <> {bulkResult.failures.length} need attention: {bulkResult.failures.map((failure) => `Invoice ${failure.id}: ${failure.reason}`).join('; ')}</>
          )}
        </div>
      )}

      {loading && <p>Loading…</p>}
      {error && <p className="error" data-testid="billing-invoices-error">Error: {error.message}</p>}

      <table className="data-table" data-testid="billing-invoices-table">
        <thead><tr>
          <th style={{ width: 34 }}>
            <input
              type="checkbox"
              checked={allActionableSelected}
              onChange={toggleAllActionable}
              disabled={selectableItems.length === 0 || bulkBusy}
              aria-label="Select all actionable invoices on this page"
              data-testid="billing-invoices-select-all-drafts"
            />
          </th>
          <th>ID</th>
          <th {...headerProps('invoice_number', 'billing-invoices-sort')}># <SortIndicator active={sortKey === 'invoice_number'} dir={sortDir} /></th>
          <th {...headerProps('client_name', 'billing-invoices-sort')}>Client <SortIndicator active={sortKey === 'client_name'} dir={sortDir} /></th>
          <th {...headerProps('issue_date', 'billing-invoices-sort')}>Issue <SortIndicator active={sortKey === 'issue_date'} dir={sortDir} /></th>
          <th {...headerProps('due_date', 'billing-invoices-sort')}>Due <SortIndicator active={sortKey === 'due_date'} dir={sortDir} /></th>
          <th {...headerProps('total', 'billing-invoices-sort')} style={{ cursor: 'pointer', userSelect: 'none', textAlign:'right'}}>Total <SortIndicator active={sortKey === 'total'} dir={sortDir} /></th>
          <th {...headerProps('amount_due', 'billing-invoices-sort')} style={{ cursor: 'pointer', userSelect: 'none', textAlign:'right'}}>Due <SortIndicator active={sortKey === 'amount_due'} dir={sortDir} /></th>
          <th {...headerProps('status', 'billing-invoices-sort')}>Status <SortIndicator active={sortKey === 'status'} dir={sortDir} /></th>
          <th style={{ textAlign: 'right' }}>Actions</th>
        </tr></thead>
        <tbody>
          {items.length === 0 && !loading && <tr><td colSpan={10} className="empty" data-testid="billing-invoices-empty">No invoices yet.</td></tr>}
          {items.map(r => {
            const collectable = qboPaymentsEnabled
              && Number(r.amount_due) > 0
              && !['paid', 'void', 'cancelled', 'draft'].includes(r.status);
            return (
            <tr key={r.id} data-testid={`billing-invoice-row-${r.id}`}>
              <td>
                {selectableItems.some((row) => row.id === r.id) && (
                  <input
                    type="checkbox"
                    checked={selected.has(r.id)}
                    onChange={() => toggleSelected(r.id)}
                    disabled={bulkBusy}
                    aria-label={`Select invoice ${r.invoice_number || r.id}`}
                    data-testid={`billing-invoice-select-${r.id}`}
                  />
                )}
              </td>
              <td><IdBadge id={r.id} prefix="INV" /></td>
              <td>
                <Link
                  to={`/modules/billing/invoices/${r.id}`}
                  data-testid={`billing-invoice-link-${r.id}`}
                  onMouseEnter={() => prefetchApi(
                    `/api/v1/billing/invoices?id=${r.id}`,
                    `billing-invoice-detail:${r.id}`
                  )}
                >
                  {r.invoice_number}
                </Link>
              </td>
              <td>{r.client_name}</td>
              <td>{fmtDate(r.issue_date)}</td>
              <td>{fmtDate(r.due_date)}</td>
              <td style={{ textAlign: 'right' }}>{Number(r.total).toFixed(2)} {r.currency}</td>
              <td style={{ textAlign: 'right' }}>{Number(r.amount_due).toFixed(2)}</td>
              <td><span className={`badge badge--${r.status}`}>{STATUS_LABELS[r.status] || r.status}</span><QboDriftBadge entry={qboDrift[r.id]} /></td>
              <td style={{ textAlign: 'right' }}>
                {collectable && (
                  <button
                    type="button"
                    className="btn btn--ghost btn--sm"
                    onClick={() => setCollectInvoice(r)}
                    data-testid={`billing-accept-payment-${r.id}`}
                    title="Accept card / ACH via QuickBooks Payments"
                    style={{ fontSize: 11, padding: '2px 8px' }}
                  >Accept payment</button>
                )}
              </td>
            </tr>
            );
          })}
        </tbody>
      </table>

      {total > 0 && (
        <div
          data-testid="billing-invoices-pagination"
          style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 12, marginTop: 10, flexWrap: 'wrap' }}
        >
          <span style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>
            Showing {(page - 1) * perPage + 1}–{Math.min(page * perPage, total)} of {total} invoices
          </span>
          <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
            <label htmlFor="billing-invoices-per-page" style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>Rows</label>
            <select
              id="billing-invoices-per-page"
              className="input"
              value={perPage}
              onChange={(event) => { setPerPage(Number(event.target.value)); setPage(1); }}
              data-testid="billing-invoices-per-page"
              style={{ width: 72, paddingBlock: 5 }}
            >
              {[25, 50, 100, 200].map((size) => <option key={size} value={size}>{size}</option>)}
            </select>
            <button
              type="button"
              className="btn btn--ghost btn--sm"
              onClick={() => setPage((current) => Math.max(1, current - 1))}
              disabled={page <= 1 || loading}
              aria-label="Previous invoice page"
              data-testid="billing-invoices-page-previous"
            ><ChevronLeft size={16} /></button>
            <span style={{ minWidth: 70, textAlign: 'center', fontSize: 12 }}>Page {page} of {totalPages}</span>
            <button
              type="button"
              className="btn btn--ghost btn--sm"
              onClick={() => setPage((current) => Math.min(totalPages, current + 1))}
              disabled={page >= totalPages || loading}
              aria-label="Next invoice page"
              data-testid="billing-invoices-page-next"
            ><ChevronRight size={16} /></button>
          </div>
        </div>
      )}

      <details style={{ marginTop: 14 }} data-testid="billing-approved-time-ready-section">
        <summary style={{ cursor: 'pointer', color: 'var(--cf-text-secondary)', fontSize: 13 }}>Approved time ready to bill</summary>
        <ApprovedHoursReadyTile variant="billing" onPick={() => setShowEntries(true)} />
      </details>

      {showCreate && (
        <InvoiceFromTimeBundleModal
          onClose={() => setShowCreate(false)}
          onCreated={() => {
            setShowCreate(false);
            // New invoice changes counts/filters across the Billing list —
            // bust the whole prefix so neighbour status tabs refresh on
            // next mount.
            bustApiCachePrefix('billing-invoices-list:');
            reload();
          }}
        />
      )}

      {showEntries && (
        <InvoiceFromTimeEntriesModal
          onClose={() => setShowEntries(false)}
          onCreated={() => {
            setShowEntries(false);
            bustApiCachePrefix('billing-invoices-list:');
            reload();
          }}
        />
      )}

      {collectInvoice && (
        <QboPaymentsCollectModal
          invoice={collectInvoice}
          environment={qboStatus.data?.environment}
          recaptchaSiteKey={qboStatus.data?.payments_recaptcha_site_key}
          onClose={() => setCollectInvoice(null)}
          onCollected={() => {
            setCollectInvoice(null);
            bustApiCachePrefix('billing-invoices-list:');
            reload();
          }}
        />
      )}
    </section>
  );
}
