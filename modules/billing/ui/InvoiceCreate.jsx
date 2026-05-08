import React, { useState } from 'react';
import EntityPicker from '../../../dashboard/src/components/EntityPicker';
import { Link, useNavigate } from 'react-router-dom';
import { api, useApi } from '../../../dashboard/src/lib/api';
import LineItemEditor, { blankLine } from '../../../dashboard/src/components/LineItemEditor';
import CompanyTypeahead from '../../people/ui/CompanyTypeahead';

/**
 * Manual Billing invoice creator — supports any item_type. Time-bundle-driven
 * invoices go through InvoiceFromTimeBundleModal instead.
 */
export default function InvoiceCreate() {
  const nav = useNavigate();
  const accountsApi = useApi('/modules/accounting/api/accounts.php?type=revenue&active=1');
  const revenueAccounts = accountsApi.data?.rows ?? [];

  const [client, setClient]   = useState(null);
  const [issueDate, setIssue] = useState(new Date().toISOString().slice(0, 10));
  const [dueDate, setDue]     = useState('');
  const [poNumber, setPo]     = useState('');
  const [taxPct, setTaxPct]   = useState(0);
  const [entityId, setEntityId] = useState(null);
  const [notesInt, setNotesInt] = useState('');
  const [notesExt, setNotesExt] = useState('');
  const [lines, setLines]     = useState([blankLine('fixed_fee')]);

  const [busy, setBusy] = useState(false);
  const [err, setErr]   = useState(null);

  const subtotal = lines.reduce((s, l) => s + (Number(l.quantity) || 0) * (Number(l.unit_price) || 0), 0);
  const taxTotal = subtotal * ((Number(taxPct) || 0) / 100);
  const total    = subtotal + taxTotal;

  const submit = async (e) => {
    e.preventDefault(); setBusy(true); setErr(null);
    try {
      if (!client) throw new Error('Pick a client');
      const payload = {
        entity_id: entityId,
        client_name: client.name,
        client_company_id: client.id,
        issue_date: issueDate,
        due_date: dueDate || null,
        po_number: poNumber || null,
        notes_internal: notesInt || null,
        notes_external: notesExt || null,
        tax_rate_pct: Number(taxPct) || 0,
        lines: lines
          .filter((l) => l.description && (Number(l.quantity) || 0) !== 0 && l.unit_price !== '')
          .map((l) => ({
            item_type: l.item_type,
            description: l.description,
            quantity: Number(l.quantity) || 0,
            unit: l.unit || 'each',
            unit_price: Number(l.unit_price) || 0,
            gl_revenue_account_code: l.gl_account_code || null,
          })),
      };
      if (payload.lines.length === 0) throw new Error('Add at least one line item');
      const res = await api.post('/modules/billing/api/invoices.php', payload);
      nav(`../invoices/${res.id}`);
    } catch (e2) { setErr(e2); }
    finally     { setBusy(false); }
  };

  return (
    <section data-testid="billing-invoice-create">
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: 12 }}>
        <div>
          <h2 style={{ margin: 0 }}>New invoice</h2>
          <p style={{ margin: '4px 0 0', color: '#666', fontSize: 13 }}>
            Manual invoice — supports any item type. For time-tracked labor across multiple placements, use <strong>+ New from time bundle</strong> on the invoices list.
          </p>
        </div>
        <Link to="../invoices" className="btn btn--ghost" data-testid="billing-invoice-create-back">← Back</Link>
      </header>

      <form onSubmit={submit}>
        <div style={{ display: 'grid', gridTemplateColumns: '2fr 1fr 1fr 1fr', gap: 12, marginBottom: 16 }}>
          <Field label="Client *">
            <CompanyTypeahead
              role="client"
              value={client}
              onChange={setClient}
              testId="billing-invoice-create-client"
              placeholder="Search clients…"
            />
          </Field>
          <Field label="Issue date *">
            <input type="date" className="input" value={issueDate} onChange={(e) => setIssue(e.target.value)} data-testid="billing-invoice-create-issue" required />
          </Field>
          <Field label="Due date">
            <input type="date" className="input" value={dueDate} onChange={(e) => setDue(e.target.value)} data-testid="billing-invoice-create-due" />
          </Field>
          <Field label="PO #">
            <input className="input" value={poNumber} onChange={(e) => setPo(e.target.value)} data-testid="billing-invoice-create-po" />
          </Field>
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 12, marginBottom: 16 }}>
          <Field label="Tax rate %">
            <input type="number" step="0.001" className="input" value={taxPct} onChange={(e) => setTaxPct(e.target.value)} data-testid="billing-invoice-create-tax" />
          </Field>
          <div>
            <EntityPicker value={entityId} onChange={setEntityId} testId="billing-invoice-create-entity" label="Issuing entity" />
          </div>
        </div>

        <h3 style={{ margin: '16px 0 8px' }}>Line items</h3>
        <LineItemEditor
          testIdPrefix="billing-invoice"
          lines={lines}
          onChange={setLines}
          glLabel="Revenue GL"
          glField="gl_revenue_account_code"
          accounts={revenueAccounts}
          aiSuggestKind="billing_invoice"
          counterpartyName={client?.name || ''}
        />

        <div style={{ marginTop: 16, display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end', flexWrap: 'wrap', gap: 12 }}>
          <div style={{ flex: 1, minWidth: 280, display: 'flex', flexDirection: 'column', gap: 8 }}>
            <Field label="Internal notes (not on invoice)">
              <textarea className="input" rows={2} value={notesInt} onChange={(e) => setNotesInt(e.target.value)} data-testid="billing-invoice-create-notes-internal" />
            </Field>
            <Field label="Notes shown on invoice">
              <textarea className="input" rows={2} value={notesExt} onChange={(e) => setNotesExt(e.target.value)} data-testid="billing-invoice-create-notes-external" />
            </Field>
          </div>
          <table data-testid="billing-invoice-create-totals" style={{ minWidth: 260, fontSize: 14 }}>
            <tbody>
              <tr><td>Subtotal</td><td style={{ textAlign: 'right' }}>{fmt(subtotal)}</td></tr>
              <tr><td>Tax</td><td style={{ textAlign: 'right' }}>{fmt(taxTotal)}</td></tr>
              <tr style={{ fontWeight: 700, fontSize: 16 }}><td>Total</td><td style={{ textAlign: 'right' }} data-testid="billing-invoice-create-total">{fmt(total)}</td></tr>
            </tbody>
          </table>
        </div>

        {err && <p className="error" data-testid="billing-invoice-create-error">Error: {err.message}</p>}

        <div style={{ marginTop: 20, display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
          <Link to="../invoices" className="btn btn--ghost" data-testid="billing-invoice-create-cancel">Cancel</Link>
          <button type="submit" className="btn btn--primary" data-testid="billing-invoice-create-submit" disabled={busy}>
            {busy ? 'Creating…' : 'Create draft invoice'}
          </button>
        </div>
      </form>
    </section>
  );
}

function Field({ label, children, style }) {
  return (
    <label style={{ display: 'flex', flexDirection: 'column', ...style }}>
      <span style={{ fontSize: '0.85em', color: '#555', marginBottom: 4 }}>{label}</span>
      {children}
    </label>
  );
}

function fmt(n) { return Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
