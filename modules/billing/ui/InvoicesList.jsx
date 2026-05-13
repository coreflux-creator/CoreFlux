import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { api, useApi } from '../../../dashboard/src/lib/api';
import { useActiveEntity } from '../../../dashboard/src/lib/useActiveEntity';
import InvoiceFromTimeBundleModal from './InvoiceFromTimeBundleModal';

const STATUS_FILTERS = ['all','draft','approved','sent','partially_paid','paid','void'];

export default function InvoicesList() {
  const [status, setStatus] = useState('all');
  const [showCreate, setShowCreate] = useState(false);
  const { activeEntityId, activeEntity } = useActiveEntity();
  const qs = new URLSearchParams();
  if (status !== 'all') qs.set('status', status);
  if (activeEntityId)   qs.set('entity_id', String(activeEntityId));
  const path = '/modules/billing/api/invoices.php' + (qs.toString() ? `?${qs}` : '');
  const { data, loading, error, reload } = useApi(path);
  const rows = data?.rows ?? [];

  return (
    <section data-testid="billing-invoices-list">
      {activeEntity && (
        <div data-testid="billing-invoices-entity-scope"
             style={{ fontSize: 12, color: '#1e40af', marginBottom: 8 }}>
          Scoped to entity <code>{activeEntity.code}</code> — switch in the header to see another.
        </div>
      )}
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
          <Link to="csv_import" className="btn" data-testid="billing-invoices-import-csv">Import CSV</Link>
          <a className="btn" href={`/modules/billing/api/csv_export.php${status !== 'all' ? `?status=${status}` : ''}`} data-testid="billing-invoices-export-csv">Export CSV</a>
        </div>
      </div>

      {loading && <p>Loading…</p>}
      {error && <p className="error" data-testid="billing-invoices-error">Error: {error.message}</p>}

      <table className="data-table" data-testid="billing-invoices-table">
        <thead><tr><th>#</th><th>Client</th><th>Issue</th><th>Due</th><th style={{textAlign:'right'}}>Total</th><th style={{textAlign:'right'}}>Due</th><th>Status</th></tr></thead>
        <tbody>
          {rows.length === 0 && !loading && <tr><td colSpan={7} className="empty" data-testid="billing-invoices-empty">No invoices yet.</td></tr>}
          {rows.map(r => (
            <tr key={r.id} data-testid={`billing-invoice-row-${r.id}`}>
              <td><Link to={`/modules/billing/invoices/${r.id}`} data-testid={`billing-invoice-link-${r.id}`}>{r.invoice_number}</Link></td>
              <td>{r.client_name}</td>
              <td>{r.issue_date}</td>
              <td>{r.due_date}</td>
              <td style={{ textAlign: 'right' }}>{Number(r.total).toFixed(2)} {r.currency}</td>
              <td style={{ textAlign: 'right' }}>{Number(r.amount_due).toFixed(2)}</td>
              <td><span className={`badge badge--${r.status}`}>{r.status.replace('_',' ')}</span></td>
            </tr>
          ))}
        </tbody>
      </table>

      {showCreate && (
        <InvoiceFromTimeBundleModal
          onClose={() => setShowCreate(false)}
          onCreated={() => { setShowCreate(false); reload(); }}
        />
      )}
    </section>
  );
}
