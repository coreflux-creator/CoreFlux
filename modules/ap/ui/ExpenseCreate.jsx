import React, { useState } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import { api } from '../../../dashboard/src/lib/api';
import { uploadFileViaPresignedPost } from '../../../dashboard/src/lib/uploads';

const newLine = () => ({
  expense_date: new Date().toISOString().slice(0, 10),
  category: 'meals', merchant: '', amount: '', description: '',
  billable_to_client_name: '', gl_expense_account_code: '',
  storage_key: null, attachment_filename: null, mime: null, size_bytes: null,
  extract_busy: false, extract_error: null,
});

export default function ExpenseCreate() {
  const navigate = useNavigate();
  const [period, setPeriod] = useState('');
  const [notes, setNotes] = useState('');
  const [lines, setLines] = useState([newLine()]);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);

  const total = lines.reduce((s, l) => s + Number(l.amount || 0), 0);

  const submit = async () => {
    setBusy(true); setError(null);
    try {
      const payload = lines.filter(l => Number(l.amount) > 0).map(l => ({
        expense_date: l.expense_date, category: l.category, merchant: l.merchant,
        amount: Number(l.amount), currency: 'USD', description: l.description,
        billable_to_client_name: l.billable_to_client_name,
        gl_expense_account_code: l.gl_expense_account_code,
      }));
      await api.post('/modules/ap/api/expenses.php', { period_label: period, notes, lines: payload });
      navigate(`/modules/ap/expenses`);
    } catch (e) { setError(e); } finally { setBusy(false); }
  };

  // Upload a receipt image/PDF to S3 (without an expense-line id yet — we use a
  // pseudo line_id of 0, the storage_key encodes the tenant scope).
  const onUpload = async (i, file) => {
    if (!file) return;
    update(i, 'extract_busy', true); update(i, 'extract_error', null);
    try {
      // We don't have a line_id yet (lines are created on save). Use the bills
      // upload_url endpoint with a phony line_id once draft is saved? Simpler:
      // we expose upload_url with line_id=-1 reserved for "draft scratch" and
      // re-key on save. For MVP we let the user save the draft first.
      const meta = await uploadFileViaPresignedPost(
        `/modules/ap/api/bills.php?action=upload_url&line_id=0&file_name=${encodeURIComponent(file.name)}`,
        file
      );
      update(i, 'storage_key', meta.storage_key);
      update(i, 'attachment_filename', meta.filename);
      update(i, 'mime', meta.mime);
      update(i, 'size_bytes', meta.size_bytes);
    } catch (e) {
      update(i, 'extract_error', e.message || String(e));
    } finally {
      update(i, 'extract_busy', false);
    }
  };

  const onExtract = async (i) => {
    const l = lines[i];
    if (!l.storage_key) { update(i, 'extract_error', 'Upload a receipt first'); return; }
    update(i, 'extract_busy', true); update(i, 'extract_error', null);
    try {
      const res = await api.post('/modules/ap/api/expenses.php?action=extract_receipt', { storage_key: l.storage_key });
      const d = res?.draft || {};
      const next = [...lines];
      next[i] = {
        ...next[i],
        expense_date: d.expense_date || next[i].expense_date,
        merchant:     d.merchant     ?? next[i].merchant,
        category:     d.category     || next[i].category,
        amount:       d.amount != null ? String(d.amount) : next[i].amount,
        description:  d.description  ?? next[i].description,
        gl_expense_account_code: d.gl_expense_account_code ?? next[i].gl_expense_account_code,
      };
      setLines(next);
    } catch (e) {
      update(i, 'extract_error', e.message || String(e));
    } finally {
      update(i, 'extract_busy', false);
    }
  };

  return (
    <section data-testid="ap-expense-create">
      <Link to="/modules/ap/expenses" style={{ fontSize: 13, color: 'var(--cf-text-secondary)' }}>← Expense reports</Link>
      <h3 style={{ marginTop: 8 }}>New expense report</h3>

      <div style={{ display: 'grid', gap: 12, maxWidth: 720 }}>
        <label style={{ fontSize: 13 }}><span style={{ color: 'var(--cf-text-secondary)' }}>Period label (e.g. "Mar 2026")</span>
          <input className="input" value={period} onChange={(e) => setPeriod(e.target.value)} data-testid="ap-expense-period" style={{ marginTop: 4 }} />
        </label>
        <label style={{ fontSize: 13 }}><span style={{ color: 'var(--cf-text-secondary)' }}>Notes</span>
          <textarea className="input" rows={3} value={notes} onChange={(e) => setNotes(e.target.value)} data-testid="ap-expense-notes" style={{ marginTop: 4 }} />
        </label>
      </div>

      <h4 style={{ marginTop: 24 }}>Lines</h4>
      <table className="data-table" data-testid="ap-expense-lines">
        <thead><tr><th>Date</th><th>Category</th><th>Merchant</th><th style={{textAlign:'right'}}>Amount</th><th>Description</th><th>Billable to</th><th>GL code</th><th>Receipt</th><th></th></tr></thead>
        <tbody>
          {lines.map((l, i) => (
            <tr key={i}>
              <td><input className="input" type="date" value={l.expense_date} onChange={(e) => update(i, 'expense_date', e.target.value)} data-testid={`ap-expense-line-date-${i}`} /></td>
              <td>
                <select className="input" value={l.category} onChange={(e) => update(i, 'category', e.target.value)} data-testid={`ap-expense-line-category-${i}`}>
                  <option>meals</option><option>travel</option><option>mileage</option>
                  <option>supplies</option><option>software</option><option>lodging</option>
                  <option>other</option>
                </select>
              </td>
              <td><input className="input" value={l.merchant} onChange={(e) => update(i, 'merchant', e.target.value)} data-testid={`ap-expense-line-merchant-${i}`} /></td>
              <td><input className="input" type="number" step="0.01" value={l.amount} onChange={(e) => update(i, 'amount', e.target.value)} data-testid={`ap-expense-line-amount-${i}`} style={{ textAlign: 'right' }} /></td>
              <td><input className="input" value={l.description} onChange={(e) => update(i, 'description', e.target.value)} data-testid={`ap-expense-line-desc-${i}`} /></td>
              <td><input className="input" value={l.billable_to_client_name} onChange={(e) => update(i, 'billable_to_client_name', e.target.value)} data-testid={`ap-expense-line-billto-${i}`} /></td>
              <td><input className="input" value={l.gl_expense_account_code} onChange={(e) => update(i, 'gl_expense_account_code', e.target.value)} data-testid={`ap-expense-line-gl-${i}`} /></td>
              <td>
                <ReceiptCell
                  line={l}
                  onUpload={(f) => onUpload(i, f)}
                  onExtract={() => onExtract(i)}
                  index={i}
                />
              </td>
              <td><button className="btn btn--ghost" onClick={() => setLines(lines.filter((_, j) => j !== i))} data-testid={`ap-expense-line-remove-${i}`}>×</button></td>
            </tr>
          ))}
        </tbody>
      </table>
      <button className="btn btn--ghost" onClick={() => setLines([...lines, newLine()])} data-testid="ap-expense-add-line" style={{ marginTop: 8 }}>+ Add line</button>

      <div style={{ marginTop: 24, display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
        <span style={{ fontSize: 14 }}>Total: <strong data-testid="ap-expense-total">{total.toFixed(2)}</strong></span>
        <button className="btn btn--primary" onClick={submit} disabled={busy || !period || total <= 0} data-testid="ap-expense-save">{busy ? 'Saving…' : 'Save draft'}</button>
      </div>
      {error && <p className="error" data-testid="ap-expense-create-error">Error: {error.message}</p>}
    </section>
  );

  function update(i, field, val) {
    setLines((prev) => {
      const next = [...prev];
      next[i] = { ...next[i], [field]: val };
      return next;
    });
  }
}

function ReceiptCell({ line, onUpload, onExtract, index }) {
  const inputRef = React.useRef(null);
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
      <input
        ref={inputRef}
        type="file"
        accept="image/*,application/pdf"
        style={{ display: 'none' }}
        data-testid={`ap-expense-line-receipt-input-${index}`}
        onChange={(e) => onUpload(e.target.files?.[0])}
      />
      {!line.storage_key && (
        <button
          className="btn btn--ghost"
          data-testid={`ap-expense-line-receipt-upload-${index}`}
          onClick={() => inputRef.current?.click()}
          disabled={line.extract_busy}
        >
          {line.extract_busy ? 'Uploading…' : 'Upload'}
        </button>
      )}
      {line.storage_key && (
        <>
          <span style={{ fontSize: 11, color: '#065f46' }} data-testid={`ap-expense-line-receipt-name-${index}`}>{line.attachment_filename}</span>
          <button
            className="btn btn--ghost"
            data-testid={`ap-expense-line-receipt-extract-${index}`}
            onClick={onExtract}
            disabled={line.extract_busy}
          >
            {line.extract_busy ? 'Extracting…' : 'AI extract'}
          </button>
        </>
      )}
      {line.extract_error && <span style={{ fontSize: 11, color: '#991b1b' }} data-testid={`ap-expense-line-receipt-error-${index}`}>{line.extract_error}</span>}
    </div>
  );
}
