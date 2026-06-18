import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { api, useApiCached, bustApiCachePrefix, prefetchApi } from '../../../dashboard/src/lib/api';
import { useActiveEntity } from '../../../dashboard/src/lib/useActiveEntity';
import { useTableList, SortIndicator } from '../../../dashboard/src/lib/useTableList';
import { fmtDate } from '../../../dashboard/src/lib/formatDate';
import InvoiceFromTimeBundleModal from './InvoiceFromTimeBundleModal';
import InvoiceFromTimeEntriesModal from './InvoiceFromTimeEntriesModal';
import QboPaymentsCollectModal from './QboPaymentsCollectModal';
import IdBadge from '../../../dashboard/src/components/IdBadge';
import { QboDriftBadge, useQboDriftBadges } from '../../../dashboard/src/components/QboDriftBadge';
import ApprovedHoursReadyTile from '../../staffing/ui/ApprovedHoursReadyTile';

const STATUS_FILTERS = ['all','draft','approved','sent','partially_paid','paid','void'];

export default function InvoicesList() {
  const [status, setStatus] = useState('all');
  const [showCreate, setShowCreate] = useState(false);
  const [showEntries, setShowEntries] = useState(false);
  const [collectInvoice, setCollectInvoice] = useState(null);
  const { activeEntityId, activeEntity } = useActiveEntity();
  const qs = new URLSearchParams();
  if (status !== 'all') qs.set('status', status);
  if (activeEntityId)   qs.set('entity_id', String(activeEntityId));
  const path = '/modules/billing/api/invoices.php' + (qs.toString() ? `?${qs}` : '');
  const { data, loading, error, reload } = useApiCached(
    path,
    { cacheKey: `billing-invoices-list:${path}` }
  );
  const rows = data?.rows ?? [];
  // Batch-fetch QBO drift snapshots so we can render a chip per row
  // without N+1 round-trips.
  const qboDrift = useQboDriftBadges('invoice', rows.map(r => r.id));

  const {
    items, sortKey, sortDir, search, setSearch, headerProps,
  } = useTableList(rows, {
    defaultSort: { key: 'issue_date', dir: 'desc' },
    searchKeys:  ['invoice_number', 'client_name', 'status'],
    dateKeys:    ['issue_date', 'due_date'],
    numericKeys: ['id', 'total', 'amount_due'],
  });

  return (
    <section data-testid="billing-invoices-list">
      {activeEntity && (
        <div data-testid="billing-invoices-entity-scope"
             style={{ fontSize: 12, color: '#1e40af', marginBottom: 8 }}>
          Scoped to entity <code>{activeEntity.code}</code> — switch in the header to see another.
        </div>
      )}
      <ApprovedHoursReadyTile variant="billing" onPick={() => setShowEntries(true)} />
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 'var(--cf-space-4)' }}>
        <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
          {STATUS_FILTERS.map(s => (
            <button
              key={s}
              data-testid={`billing-filter-${s}`}
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
          <Link to="new" className="btn btn--primary" data-testid="billing-new-invoice">+ New invoice</Link>
          <button className="btn btn--ghost" onClick={() => setShowCreate(true)} data-testid="billing-new-from-time-bundle">
            New from time bundle
          </button>
          <button className="btn btn--ghost" onClick={() => setShowEntries(true)} data-testid="billing-new-from-time-entries">
            New from approved hours (day-level)
          </button>
          <Link to="csv_import" className="btn" data-testid="billing-invoices-import-csv">Import CSV</Link>
          <a className="btn" href={`/modules/billing/api/csv_export.php${status !== 'all' ? `?status=${status}` : ''}`} data-testid="billing-invoices-export-csv">Export CSV</a>
        </div>
      </div>

      <div style={{ display: 'flex', gap: 8, marginBottom: 8, alignItems: 'center' }}>
        <input
          type="search"
          className="input"
          placeholder="Search invoice #, client, status…"
          value={search}
          onChange={e => setSearch(e.target.value)}
          data-testid="billing-invoices-search"
          style={{ maxWidth: 320 }}
        />
        <span style={{ fontSize: 11, color: 'var(--cf-text-secondary, #6b7280)' }}
              data-testid="billing-invoices-match-count">
          {items.length} of {rows.length}
        </span>
      </div>

      {loading && <p>Loading…</p>}
      {error && <p className="error" data-testid="billing-invoices-error">Error: {error.message}</p>}

      <table className="data-table" data-testid="billing-invoices-table">
        <thead><tr>
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
          {items.length === 0 && !loading && <tr><td colSpan={9} className="empty" data-testid="billing-invoices-empty">No invoices yet.</td></tr>}
          {items.map(r => {
            const collectable = Number(r.amount_due) > 0 && !['paid', 'void', 'cancelled', 'draft'].includes(r.status);
            return (
            <tr key={r.id} data-testid={`billing-invoice-row-${r.id}`}>
              <td><IdBadge id={r.id} prefix="INV" /></td>
              <td>
                <Link
                  to={`/modules/billing/invoices/${r.id}`}
                  data-testid={`billing-invoice-link-${r.id}`}
                  onMouseEnter={() => prefetchApi(
                    `/modules/billing/api/invoice_detail.php?id=${r.id}`,
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
              <td><span className={`badge badge--${r.status}`}>{r.status.replace('_',' ')}</span><QboDriftBadge entry={qboDrift[r.id]} /></td>
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
