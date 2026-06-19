import React, { useState } from 'react';
import EntityPicker from '../../../dashboard/src/components/EntityPicker';
import { Link, useNavigate } from 'react-router-dom';
import { api, useApi } from '../../../dashboard/src/lib/api';
import { uploadFileViaPresignedPost } from '../../../dashboard/src/lib/uploads';
import LineItemEditor, { blankLine, ITEM_TYPES } from '../../../dashboard/src/components/LineItemEditor';
import CompanyTypeahead from '../../people/ui/CompanyTypeahead';
import VendorTypeahead from './VendorTypeahead';
import VendorQuickCreate from './VendorQuickCreate';

const ITEM_TYPE_FALLBACK = ITEM_TYPES.map((t) => t.value);
const ACCOUNTING_ACCOUNTS_API = '/api/v1/accounting/accounts';

/**
 * Manual AP bill creator — supports any item_type (labor, expense, materials,
 * fixed-fee, milestone, discount, subscription, mileage, per-diem,
 * reimbursement, other). Time-bundle-driven bills go through
 * BillFromTimeBundleModal instead.
 */
export default function BillCreate() {
  const nav = useNavigate();
  const accountsApi = useApi(`${ACCOUNTING_ACCOUNTS_API}?type=expense&active=1`);
  const expenseAccounts = accountsApi.data?.rows ?? [];

  const [vendor, setVendor]       = useState(null);
  const [showCreateVendor, setShowCreateVendor] = useState(false);
  const [pendingVendorName, setPendingVendorName] = useState('');
  const [vendorType, setVendorType] = useState('w9_business');
  const [billNumber, setBillNumber] = useState('');
  const [billDate, setBillDate]   = useState(new Date().toISOString().slice(0, 10));
  const [dueDate, setDueDate]     = useState('');
  const [poNumber, setPoNumber]   = useState('');
  const [taxPct, setTaxPct]       = useState(0);
  const [entityId, setEntityId]   = useState(null);
  const [notes, setNotes]         = useState('');
  const [lines, setLines]         = useState([blankLine('expense')]);

  const [busy, setBusy] = useState(false);
  const [err, setErr]   = useState(null);

  // Vendor invoice PDF — staged here, attached after the bill is created.
  const [pendingFile, setPendingFile]       = useState(null);
  const [pendingFileError, setPendingFileError] = useState(null);

  const stageFile = (file) => {
    setPendingFileError(null);
    if (!file) { setPendingFile(null); return; }
    if (file.size > 25 * 1024 * 1024) {
      setPendingFileError(new Error('File exceeds 25 MB. Compress or split before uploading.'));
      return;
    }
    setPendingFile(file);
  };

  // ── AI extraction ───────────────────────────────────────────────────
  const [extracting, setExtracting] = useState(false);
  const [extractError, setExtractError] = useState(null);
  const [extractResult, setExtractResult] = useState(null);

  const extractFromPdf = async () => {
    if (!pendingFile) { setExtractError(new Error('Drop a PDF first')); return; }
    setExtracting(true); setExtractError(null); setExtractResult(null);
    try {
      // Upload first (needs to be in S3 for the LLM to fetch).
      const uploaded = await uploadFileViaPresignedPost(
        `/modules/ap/api/bills.php?action=upload_url&file_name=${encodeURIComponent(pendingFile.name)}`,
        pendingFile
      );
      const res = await api.post('/modules/ap/api/bills.php?action=extract_from_pdf', { storage_key: uploaded.storage_key });
      const d = res.draft || {};

      // Merge non-empty fields. We never overwrite the vendor pick (that's
      // tied to the companies directory) — the user must confirm.
      if (d.bill_number)  setBillNumber(d.bill_number);
      if (d.bill_date)    setBillDate(d.bill_date);
      if (d.due_date)     setDueDate(d.due_date);
      if (d.po_number)    setPoNumber(d.po_number);
      if (d.notes)        setNotes(d.notes);
      if (Array.isArray(d.lines) && d.lines.length > 0) {
        setLines(d.lines.map((l) => ({
          item_type:        ITEM_TYPE_FALLBACK.includes(l.item_type) ? l.item_type : 'other',
          description:      l.description || '',
          quantity:         l.quantity ?? 1,
          unit:             l.unit || 'each',
          unit_price:       l.unit_price ?? '',
          gl_account_code:  '', // never let AI guess GL — user picks
        })));
      }
      setExtractResult({ vendor_name: d.vendor_name, total: d.total, lineCount: (d.lines || []).length, model: res.model, latency: res.latency_ms });
    } catch (e) { setExtractError(e); }
    finally     { setExtracting(false); }
  };

  const subtotal = lines.reduce((s, l) => s + (Number(l.quantity) || 0) * (Number(l.unit_price) || 0), 0);
  const taxTotal = subtotal * ((Number(taxPct) || 0) / 100);
  const total    = subtotal + taxTotal;

  const submit = async (e) => {
    e.preventDefault(); setBusy(true); setErr(null);
    try {
      if (!vendor) throw new Error('Pick a vendor');
      const payload = {
        entity_id: entityId,
        vendor_name: vendor.name,
        // vendor.id is ap_vendors_index.id; vendor.company_id is companies.id
        // (null for 1099 individuals). Send only company_id — server will
        // upsert if missing for non-individual types.
        vendor_company_id: vendor.company_id || null,
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

      // If the user staged a vendor-invoice PDF, upload it and attach it
      // to the freshly-created bill. We do this AFTER the bill exists so
      // failed S3 uploads don't block bill creation.
      if (pendingFile) {
        try {
          const uploaded = await uploadFileViaPresignedPost(
            `/modules/ap/api/bills.php?action=upload_url&id=${res.id}&file_name=${encodeURIComponent(pendingFile.name)}`,
            pendingFile
          );
          await api.post(`/modules/ap/api/bills.php?action=attach&id=${res.id}`, uploaded);
        } catch (uploadErr) {
          // Bill is still saved — surface a soft warning by routing with
          // an error param. The detail page can read it and show a banner.
          nav(`../bills/${res.id}?attach_error=${encodeURIComponent(uploadErr.message)}`);
          return;
        }
      }
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
            <VendorTypeahead
              value={vendor}
              onChange={(v) => {
                setVendor(v);
                if (v?.vendor_type) setVendorType(v.vendor_type);
              }}
              onCreate={(typedName) => { setPendingVendorName(typedName); setShowCreateVendor(true); }}
              testId="ap-bill-create-vendor"
              placeholder="Search vendors (1099 individuals, c2c corps, w9 vendors)…"
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
          <div style={{ gridColumn: 'span 2' }}>
            <EntityPicker value={entityId} onChange={setEntityId} testId="ap-bill-create-entity" label="Home entity (bill owner)" />
          </div>
        </div>

        <Field label="Vendor invoice PDF (optional)">
          <FileDropZone
            file={pendingFile}
            onFile={stageFile}
            error={pendingFileError}
            testIdPrefix="ap-bill-create-attachment"
          />
          {pendingFile && (
            <div style={{ marginTop: 8, display: 'flex', alignItems: 'center', gap: 8 }}>
              <button
                type="button"
                className="btn btn--ghost"
                onClick={extractFromPdf}
                disabled={extracting}
                data-testid="ap-bill-create-extract"
                title="Use AI to read the PDF and pre-fill bill #, dates, and line items. You'll review every field before saving."
              >
                {extracting ? '✨ Extracting…' : '✨ Extract from PDF'}
              </button>
              {extractResult && (
                <span data-testid="ap-bill-create-extract-result" style={{ fontSize: 12, color: '#065f46' }}>
                  Pre-filled {extractResult.lineCount} line{extractResult.lineCount === 1 ? '' : 's'}{extractResult.vendor_name ? ` (vendor: ${extractResult.vendor_name})` : ''}{extractResult.model ? ` · ${extractResult.model}` : ''} — review every field before saving.
                </span>
              )}
              {extractError && (
                <span data-testid="ap-bill-create-extract-error" style={{ fontSize: 12, color: '#991b1b' }}>
                  Extract failed: {extractError.message}
                </span>
              )}
            </div>
          )}
        </Field>

        <h3 style={{ margin: '16px 0 8px' }}>Line items</h3>
        <LineItemEditor
          testIdPrefix="ap-bill"
          lines={lines}
          onChange={setLines}
          glLabel="Expense GL"
          glField="gl_expense_account_code"
          accounts={expenseAccounts}
          aiSuggestKind="ap_bill"
          counterpartyName={vendor?.name || ''}
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

      {showCreateVendor && (
        <VendorQuickCreate
          initialName={pendingVendorName}
          onCancel={() => setShowCreateVendor(false)}
          onCreated={(v) => {
            // VendorQuickCreate returns ap_vendors_index payload with
            // company_id (may be null for 1099 individual). Preserve both
            // so the bill submit can route the correct foreign key.
            setVendor({
              id: v.id || null,
              name: v.vendor_name || v.name,
              vendor_type: v.vendor_type,
              company_id: v.company_id || null,
              tax_id_last4: v.tax_id_last4 || null,
            });
            if (v.vendor_type) setVendorType(v.vendor_type);
            setShowCreateVendor(false);
          }}
        />
      )}
    </section>
  );
}

function FileDropZone({ file, onFile, error, testIdPrefix }) {
  const [drag, setDrag] = useState(false);
  return (
    <div
      data-testid={testIdPrefix}
      onDragOver={(e) => { e.preventDefault(); setDrag(true); }}
      onDragLeave={() => setDrag(false)}
      onDrop={(e) => {
        e.preventDefault(); setDrag(false);
        const f = e.dataTransfer.files?.[0];
        if (f) onFile(f);
      }}
      style={{
        padding: 12, border: `2px dashed ${drag ? '#2563eb' : '#d1d5db'}`,
        borderRadius: 6, background: drag ? '#eff6ff' : '#f9fafb',
        display: 'flex', alignItems: 'center', gap: 10, transition: 'border-color 120ms',
      }}
    >
      {file ? (
        <>
          <span style={{ flex: 1, fontSize: 14 }} data-testid={`${testIdPrefix}-filename`}>📎 {file.name} <span style={{ color: '#888' }}>({Math.round(file.size / 1024)} KB)</span></span>
          <button type="button" className="btn btn--ghost" onClick={() => onFile(null)} data-testid={`${testIdPrefix}-clear`}>Remove</button>
        </>
      ) : (
        <>
          <span style={{ flex: 1, fontSize: 13, color: '#6b7280' }}>Drop a PDF here, or</span>
          <label className="btn btn--ghost" style={{ cursor: 'pointer' }} data-testid={`${testIdPrefix}-pick-label`}>
            Pick file
            <input
              type="file"
              accept="application/pdf,image/*"
              onChange={(e) => onFile(e.target.files?.[0] || null)}
              data-testid={`${testIdPrefix}-input`}
              style={{ display: 'none' }}
            />
          </label>
        </>
      )}
      {error && <span style={{ color: '#991b1b', fontSize: 12 }} data-testid={`${testIdPrefix}-error`}>{error.message}</span>}
    </div>
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
