import React, { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { api, useApi } from '../../../dashboard/src/lib/api';
import LineItemEditor, { blankLine } from '../../../dashboard/src/components/LineItemEditor';
import CompanyTypeahead from '../../people/ui/CompanyTypeahead';

/**
 * Manual AP bill creator — supports any item_type (labor, expense, materials,
 * fixed-fee, milestone, discount, subscription, mileage, per-diem,
 * reimbursement, other). Time-bundle-driven bills go through
 * BillFromTimeBundleModal instead.
 */
export default function BillCreate() {
  const nav = useNavigate();
  const accountsApi = useApi('/modules/accounting/api/accounts.php?type=expense&active=1');
  const expenseAccounts = accountsApi.data?.rows ?? [];

  const [vendor, setVendor]       = useState(null);
  const [vendorType, setVendorType] = useState('w9_business');
  const [billNumber, setBillNumber] = useState('');
  const [billDate, setBillDate]   = useState(new Date().toISOString().slice(0, 10));
  const [dueDate, setDueDate]     = useState('');
  const [poNumber, setPoNumber]   = useState('');
  const [taxPct, setTaxPct]       = useState(0);
  const [notes, setNotes]         = useState('');
  const [lines, setLines]         = useState([blankLine('expense')]);

  const [busy, setBusy] = useState(false);
  const [err, setErr]   = useState(null);

  const subtotal = lines.reduce((s, l) => s + (Number(l.quantity) || 0) * (Number(l.unit_price) || 0), 0);
  const taxTotal = subtotal * ((Number(taxPct) || 0) / 100);
  const total    = subtotal + taxTotal;

  const submit = async (e) => {
    e.preventDefault(); setBusy(true); setErr(null);
    try {
      if (!vendor) throw new Error('Pick a vendor');
      const payload = {
        vendor_name: vendor.name,
        vendor_company_id: vendor.id,
        vendor_type: vendorType,
        bill_number: billNumber || null,
        bill_date: billDate,
        due_date: dueDate || null,
        po_number: poNumber || null,
        notes_internal: notes || null,
        tax_rate_pct: Number(taxPct) || 0,
        lines: lines
          .filter((l) => l.description && (Number(l.quantity) || 0) !== 0 && l.unit_price !== '')
          .map((l) => ({
            item_type: l.item_type,
            description: l.description,
            quantity: Number(l.quantity) || 0,
            unit: l.unit || 'each',
            unit_price: Number(l.unit_price) || 0,
            gl_expense_account_code: l.gl_account_code || null,
            is_1099_eligible: vendorType === '1099_individual',
          })),
      };
      if (payload.lines.length === 0) throw new Error('Add at least one line item');
      const res = await api.post('/modules/ap/api/bills.php', payload);
      nav(`../bills/${res.id}`);
    } catch (e2) { setErr(e2); }
    finally     { setBusy(false); }
  };

  return (
    <section data-testid="ap-bill-create">
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: 12 }}>
        <div>
          <h2 style={{ margin: 0 }}>New AP bill</h2>
          <p style={{ margin: '4px 0 0', color: '#666', fontSize: 13 }}>
            Manual bill — supports any item type. For time-tracked labor across multiple placements, use <strong>+ New from time bundle</strong> on the bills list.
          </p>
        </div>
        <Link to="../bills" className="btn btn--ghost" data-testid="ap-bill-create-back">← Back</Link>
      </header>

      <form onSubmit={submit}>
        <div style={{ display: 'grid', gridTemplateColumns: '2fr 1fr 1fr 1fr', gap: 12, marginBottom: 16 }}>
          <Field label="Vendor *">
            <CompanyTypeahead
              role="vendor"
              value={vendor}
              onChange={setVendor}
              testId="ap-bill-create-vendor"
              placeholder="Search vendors…"
            />
          </Field>
          <Field label="Vendor type">
            <select className="input" value={vendorType} onChange={(e) => setVendorType(e.target.value)} data-testid="ap-bill-create-vendor-type">
              <option value="w9_business">W-9 business</option>
              <option value="c2c_corp">C2C / corp-to-corp</option>
              <option value="1099_individual">1099 individual</option>
              <option value="utility">Utility</option>
              <option value="other">Other</option>
            </select>
          </Field>
          <Field label="Bill #">
            <input className="input" value={billNumber} onChange={(e) => setBillNumber(e.target.value)} data-testid="ap-bill-create-number" placeholder="vendor's invoice #" />
          </Field>
          <Field label="PO #">
            <input className="input" value={poNumber} onChange={(e) => setPoNumber(e.target.value)} data-testid="ap-bill-create-po" />
          </Field>
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 12, marginBottom: 16 }}>
          <Field label="Bill date *">
            <input type="date" className="input" value={billDate} onChange={(e) => setBillDate(e.target.value)} data-testid="ap-bill-create-date" required />
          </Field>
          <Field label="Due date">
            <input type="date" className="input" value={dueDate} onChange={(e) => setDueDate(e.target.value)} data-testid="ap-bill-create-due" />
          </Field>
          <Field label="Tax rate %">
            <input type="number" step="0.001" className="input" value={taxPct} onChange={(e) => setTaxPct(e.target.value)} data-testid="ap-bill-create-tax" />
          </Field>
        </div>

        <h3 style={{ margin: '16px 0 8px' }}>Line items</h3>
        <LineItemEditor
          testIdPrefix="ap-bill"
          lines={lines}
          onChange={setLines}
          glLabel="Expense GL"
          glField="gl_expense_account_code"
          accounts={expenseAccounts}
        />

        <div style={{ marginTop: 16, display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end', flexWrap: 'wrap', gap: 12 }}>
          <Field label="Internal notes" style={{ flex: 1, minWidth: 280 }}>
            <textarea className="input" rows={2} value={notes} onChange={(e) => setNotes(e.target.value)} data-testid="ap-bill-create-notes" />
          </Field>
          <table data-testid="ap-bill-create-totals" style={{ minWidth: 260, fontSize: 14 }}>
            <tbody>
              <tr><td>Subtotal</td><td style={{ textAlign: 'right' }}>{fmt(subtotal)}</td></tr>
              <tr><td>Tax</td><td style={{ textAlign: 'right' }}>{fmt(taxTotal)}</td></tr>
              <tr style={{ fontWeight: 700, fontSize: 16 }}><td>Total</td><td style={{ textAlign: 'right' }} data-testid="ap-bill-create-total">{fmt(total)}</td></tr>
            </tbody>
          </table>
        </div>

        {err && <p className="error" data-testid="ap-bill-create-error">Error: {err.message}</p>}

        <div style={{ marginTop: 20, display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
          <Link to="../bills" className="btn btn--ghost" data-testid="ap-bill-create-cancel">Cancel</Link>
          <button type="submit" className="btn btn--primary" data-testid="ap-bill-create-submit" disabled={busy}>
            {busy ? 'Creating…' : 'Create bill'}
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
