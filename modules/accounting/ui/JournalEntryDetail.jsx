import React, { useState } from 'react';
import { Link, useParams, useNavigate } from 'react-router-dom';
import { api, useApi } from '../../../dashboard/src/lib/api';
import JeTracePane from './JeTracePane';

/**
 * Journal Entry detail.
 * Renders header + lines + reverse-entry action.
 * The "Source" cell links back to the originating subledger record (AP bill,
 * billing invoice, payroll run) when source_module / source_ref_id are set,
 * giving the drill-through path required by the SPEC.
 */
export default function JournalEntryDetail() {
  const { id } = useParams();
  const navigate = useNavigate();
  const { data, loading, error, reload } = useApi(`/modules/accounting/api/journal_entries.php?id=${id}`);
  const [busy, setBusy]     = useState(false);
  const [actionErr, setErr] = useState(null);

  const reverse = async () => {
    const reason = window.prompt('Reason for reversal? (required)');
    if (!reason) return;
    setBusy(true); setErr(null);
    try {
      const res = await api.post(`/modules/accounting/api/journal_entries.php?action=reverse&id=${id}`, { reason });
      reload();
      window.alert(`Reversal posted. New JE #${res.je_id}.`);
      navigate(`/modules/accounting/journal-entries/${res.je_id}`);
    } catch (e) { setErr(e.message); }
    finally { setBusy(false); }
  };

  if (loading) return <p>Loading…</p>;
  if (error)   return <p className="error">Error: {error.message}</p>;
  const { entry, lines = [] } = data || {};
  if (!entry) return <p>Not found.</p>;

  return (
    <section data-testid="accounting-je-detail">
      <Link to="/modules/accounting/journal-entries" style={{ fontSize: 13, color: 'var(--cf-text-secondary)' }}>← Journal entries</Link>
      <h2 style={{ marginTop: 8 }}>JE #{entry.je_number} <StatusPill status={entry.status} /></h2>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 12, marginTop: 16, fontSize: 13 }}>
        <Field label="Posting date" value={entry.posting_date} testid="accounting-je-detail-posting-date" />
        <Field label="Currency"     value={entry.currency} />
        <Field label="Total debit"  value={fmt(entry.total_debit)}  testid="accounting-je-detail-total-debit" />
        <Field label="Total credit" value={fmt(entry.total_credit)} testid="accounting-je-detail-total-credit" />
        <Field label="Source"       value={renderSource(entry)}     testid="accounting-je-detail-source" />
        <Field label="Memo"         value={entry.memo || '—'}       testid="accounting-je-detail-memo" />
      </div>

      <h3 style={{ marginTop: 24 }}>Lines</h3>
      <table className="data-table" data-testid="accounting-je-detail-lines">
        <thead><tr><th>Account</th><th>Description</th><th style={{ textAlign: 'right' }}>Debit</th><th style={{ textAlign: 'right' }}>Credit</th></tr></thead>
        <tbody>
          {lines.map((l, i) => (
            <tr key={l.id || i} data-testid={`accounting-je-detail-line-${i}`}>
              <td><code style={{ fontSize: 11 }}>{l.account_code}</code> {l.account_name}</td>
              <td>{l.description || '—'}</td>
              <td style={{ textAlign: 'right' }}>{fmt(l.debit)}</td>
              <td style={{ textAlign: 'right' }}>{fmt(l.credit)}</td>
            </tr>
          ))}
        </tbody>
      </table>

      {entry.status === 'posted' && (
        <div style={{ marginTop: 16, display: 'flex', gap: 8 }}>
          <button className="btn btn--ghost" onClick={reverse} disabled={busy} data-testid="accounting-je-reverse">
            {busy ? 'Reversing…' : 'Post reversal'}
          </button>
        </div>
      )}
      {actionErr && <p className="error" data-testid="accounting-je-detail-error">{actionErr}</p>}

      <JeTracePane jeId={entry.id} />
    </section>
  );
}

function renderSource(je) {
  if (!je.source_module || je.source_module === 'manual') return 'Manual entry';
  const src = je.source_module;
  const id  = je.source_ref_id;
  // Map source_module → frontend route. Falls through to plain text if unmapped.
  const links = {
    'ap_bills':         id ? `/modules/ap/bills/${id}` : null,
    'ap_payments':      id ? `/modules/ap/payments/${id}` : null,
    'billing_invoices': id ? `/modules/billing/invoices/${id}` : null,
    'payroll_runs':     id ? `/modules/payroll/runs/${id}` : null,
  };
  const path = links[src] || links[je.source_ref_type];
  const label = `${src}#${id ?? '?'}`;
  return path
    ? <Link to={path} data-testid="accounting-je-source-link">{label} ↗</Link>
    : <code style={{ fontSize: 11 }}>{label}</code>;
}

function StatusPill({ status }) {
  const colors = {
    draft:    { bg: '#f3f4f6', fg: '#374151' },
    posted:   { bg: '#d1fae5', fg: '#065f46' },
    reversed: { bg: '#fef3c7', fg: '#92400e' },
    voided:   { bg: '#fee2e2', fg: '#991b1b' },
  };
  const c = colors[status] || colors.draft;
  return (
    <span data-testid={`accounting-je-status-${status}`} style={{ display: 'inline-block', marginLeft: 8, padding: '2px 8px', borderRadius: 999, background: c.bg, color: c.fg, fontSize: 11, fontWeight: 600, textTransform: 'uppercase' }}>{status}</span>
  );
}

function Field({ label, value, testid }) {
  return (
    <div>
      <div style={{ fontSize: 11, color: 'var(--cf-text-secondary)', textTransform: 'uppercase' }}>{label}</div>
      <div data-testid={testid} style={{ marginTop: 2 }}>{value}</div>
    </div>
  );
}

function fmt(n) { return Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
