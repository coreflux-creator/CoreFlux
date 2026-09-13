import React, { useEffect, useState } from 'react';
import EntityPicker from '../../../dashboard/src/components/EntityPicker';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { api, useApi } from '../../../dashboard/src/lib/api';
import LineItemEditor, { blankLine } from '../../../dashboard/src/components/LineItemEditor';
import CompanyTypeahead from '../../people/ui/CompanyTypeahead';
import { useActiveEntity } from '../../../dashboard/src/lib/useActiveEntity';

/**
 * Manual Billing invoice creator — supports any item_type. Time-bundle-driven
 * invoices go through InvoiceFromTimeBundleModal instead.
 */
export default function InvoiceCreate() {
  const nav = useNavigate();
  const { id } = useParams();
  const isEdit = Boolean(id);
  const { activeEntityId, loaded: activeEntityLoaded } = useActiveEntity();
  const accountsApi = useApi('/modules/accounting/api/accounts.php?type=revenue&active=1');
  const itemsApi = useApi('/modules/billing/api/items.php?active=1&per_page=500');
  const invoiceApi = useApi(isEdit ? `/api/v1/billing/invoices?id=${id}` : null, { enabled: isEdit });
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
  const [hydrated, setHydrated] = useState(false);
  const [entityInitialized, setEntityInitialized] = useState(false);

  const [busy, setBusy] = useState(false);
  const [err, setErr]   = useState(null);

  useEffect(() => {
    if (!isEdit || hydrated || !invoiceApi.data?.invoice) return;
    const invoice = invoiceApi.data.invoice;
    const savedLines = invoiceApi.data.lines ?? [];
    setClient({ id: invoice.client_company_id, name: invoice.client_name });
    setIssue(invoice.issue_date || '');
    setDue(invoice.due_date || '');
    setPo(invoice.po_number || '');
    setEntityId(invoice.entity_id || null);
    setNotesInt(invoice.notes_internal || '');
    setNotesExt(invoice.notes_external || '');
    setTaxPct(savedLines.reduce((rate, line) => Math.max(rate, Number(line.tax_rate_pct) || 0), 0));
    setLines(savedLines.length ? savedLines.map((line) => ({
      catalog_item_id: line.catalog_item_id || null,
      catalog_item_code: line.catalog_item_code || null,
      catalog_item_name: line.catalog_item_name || null,
      item_type: line.item_type || 'other',
      description: line.description || '',
      quantity: line.quantity,
      unit: line.unit || 'each',
      unit_price: line.unit_price,
      gl_account_code: line.gl_revenue_account_code || '',
      taxable: Number(line.tax_rate_pct) > 0,
    })) : [blankLine('fixed_fee')]);
    setHydrated(true);
  }, [hydrated, invoiceApi.data, isEdit]);

  useEffect(() => {
    if (entityInitialized || !activeEntityLoaded || (isEdit && !hydrated)) return;
    if (entityId) {
      setEntityInitialized(true);
      return;
    }
    setEntityId(activeEntityId ?? null);
    setEntityInitialized(true);
  }, [activeEntityId, activeEntityLoaded, entityId, entityInitialized, hydrated, isEdit]);

  const subtotal = lines.reduce((s, l) => s + (Number(l.quantity) || 0) * (Number(l.unit_price) || 0), 0);
  const taxableSubtotal = lines.reduce((sum, line) => (
    Object.prototype.hasOwnProperty.call(line, 'taxable') && line.taxable === false
      ? sum
      : sum + (Number(line.quantity) || 0) * (Number(line.unit_price) || 0)
  ), 0);
  const taxTotal = taxableSubtotal * ((Number(taxPct) || 0) / 100);
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
            catalog_item_id: l.catalog_item_id || null,
            item_type: l.item_type,
            description: l.description,
            quantity: Number(l.quantity) || 0,
            unit: l.unit || 'each',
            unit_price: Number(l.unit_price) || 0,
            gl_revenue_account_code: l.gl_account_code || null,
            ...(Object.prototype.hasOwnProperty.call(l, 'taxable') ? { taxable: l.taxable } : {}),
          })),
      };
      if (payload.lines.length === 0) throw new Error('Add at least one line item');
      if (isEdit) {
        await api.patch(`/api/v1/billing/invoices?id=${id}`, payload);
        nav(`/modules/billing/invoices/${id}`);
      } else {
        const res = await api.post('/api/v1/billing/invoices', payload);
        nav(`/modules/billing/invoices/${res.id}`);
      }
    } catch (e2) { setErr(e2); }
    finally     { setBusy(false); }
  };

  if (isEdit && invoiceApi.loading && !hydrated) return <p>Loading...</p>;
  if (isEdit && invoiceApi.error) return <p className="error">Error: {invoiceApi.error.message}</p>;

  return (
    <section data-testid="billing-invoice-create">
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: 12 }}>
        <div>
          <h2 style={{ margin: 0 }}>{isEdit ? 'Edit invoice' : 'New invoice'}</h2>
        </div>
        <Link to="/modules/billing/invoices" className="btn btn--ghost" data-testid="billing-invoice-create-back">← Back</Link>
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
            <EntityPicker
              value={entityId}
              onChange={setEntityId}
              testId="billing-invoice-create-entity"
              label="Issuing entity"
              required
              allowNone={false}
            />
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
          catalogItems={itemsApi.data?.rows ?? []}
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
          <Link to="/modules/billing/invoices" className="btn btn--ghost" data-testid="billing-invoice-create-cancel">Cancel</Link>
          <button type="submit" className="btn btn--primary" data-testid="billing-invoice-create-submit" disabled={busy}>
            {busy ? 'Saving...' : (isEdit ? 'Save draft' : 'Create draft invoice')}
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
