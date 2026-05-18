import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { api, useApi } from '../../../dashboard/src/lib/api';
import { useBulkSelection } from '../../../dashboard/src/lib/useBulkSelection';
import ExportTemplatePicker from '../../../dashboard/src/components/ExportTemplatePicker';

export default function PaymentsList() {
  const { data, loading, error, reload } = useApi('/modules/ap/api/payments.php');
  const rows = data?.rows ?? [];
  const plaidEnabled = !!data?.plaid_enabled;
  const plaidTransferLinked = !!data?.plaid_transfer_linked;
  const [showRecord, setShowRecord] = useState(false);
  const [showAllocate, setShowAllocate] = useState(null); // payment row
  const [batching, setBatching]   = useState(false);
  const [batchErr, setBatchErr]   = useState(null);
  const [batchInfo, setBatchInfo] = useState(null);

  const sel = useBulkSelection(rows.map(r => r.id));

  // Per-row Plaid Transfer origination state (id → 'busy' | { error } | { ok })
  const [plaidRow, setPlaidRow] = useState({});

  const sendViaPlaid = async (p) => {
    setPlaidRow(s => ({ ...s, [p.id]: 'busy' }));
    try {
      const res = await api.post(`/modules/ap/api/payments.php?action=originate&id=${p.id}&rail=plaid_transfer`, {});
      setPlaidRow(s => ({ ...s, [p.id]: { ok: true, ref: res.rail_external_ref || res.batch_id } }));
      reload();
    } catch (e) {
      setPlaidRow(s => ({ ...s, [p.id]: { error: e.message || String(e) } }));
    }
  };
  // Eligibility for the per-row "Send via Plaid" button — must be a sent
  // payment with method=plaid that hasn't been originated yet, and the
  // tenant must have linked a funding source.
  const plaidEligible = (p) =>
    plaidTransferLinked &&
    p.method === 'plaid' &&
    p.status === 'sent' &&
    !p.rail_external_ref;

  // Eligibility for NACHA batch: ach|plaid method, status draft|queued|sent (without rail_external_ref).
  const isOriginatable = (p) =>
    ['ach','plaid'].includes(p.method) &&
    (['draft','queued'].includes(p.status) || (p.status === 'sent' && !p.rail_external_ref));

  const eligibleSelected = rows.filter(p => sel.has(p.id) && isOriginatable(p));

  const exportSelected = () => {
    if (!sel.size) return;
    const a = document.createElement('a');
    a.href = `/modules/ap/api/export.php?type=payments&ids=${sel.ids.join(',')}`;
    a.rel  = 'noopener';
    a.click();
  };

  const originateBatch = async () => {
    if (!eligibleSelected.length) return;
    setBatching(true); setBatchErr(null); setBatchInfo(null);
    try {
      const res = await api.post('/modules/ap/api/payments.php?action=originate_batch', {
        ids: eligibleSelected.map(p => p.id),
      });
      // Trigger NACHA file download from the base64 payload.
      if (res?.nacha_file_b64) {
        const bin = atob(res.nacha_file_b64);
        const arr = new Uint8Array(bin.length);
        for (let i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
        const blob = new Blob([arr], { type: 'application/octet-stream' });
        const url  = URL.createObjectURL(blob);
        const a    = document.createElement('a');
        a.href = url; a.download = res.nacha_filename || 'ap-batch.ach'; a.click();
        URL.revokeObjectURL(url);
      }
      setBatchInfo(res);
      sel.clear();
      reload();
    } catch (e) { setBatchErr(e); }
    finally { setBatching(false); }
  };

  return (
    <section data-testid="ap-payments-list">
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 'var(--cf-space-4)', flexWrap: 'wrap', gap: 8 }}>
        <div>
          <h3 style={{ margin: 0, fontSize: 16 }}>Vendor payments</h3>
          <p style={{ margin: '4px 0 0', fontSize: 12, color: 'var(--cf-text-secondary)' }}>
            {plaidEnabled
              ? (plaidTransferLinked
                  ? <><span className="badge badge--success">Plaid Transfer ready</span> — ACH rail available.</>
                  : (
                      <span data-testid="ap-plaid-link-cta">
                        <span className="badge" style={{ background: 'var(--cf-amber-bg, #fef3c7)', color: 'var(--cf-amber, #92400e)', padding: '2px 8px', borderRadius: 4 }}>
                          Plaid configured — funding source not linked
                        </span>
                        {' '}
                        <Link to="/modules/treasury/payout-rails" data-testid="ap-plaid-link-cta-link" style={{ marginLeft: 6 }}>
                          Connect funding source →
                        </Link>
                      </span>
                    )
                )
              : <span data-testid="ap-plaid-disabled-notice">Plaid Transfer not configured — manual payment methods only (set PLAID_CLIENT_ID / PLAID_SECRET_SANDBOX to enable).</span>}
          </p>
        </div>
        <div style={{ display: 'flex', gap: 8 }}>
          <Link to="csv_import" className="btn" data-testid="ap-payments-import-csv">Import CSV</Link>
          <a className="btn" href="/modules/ap/api/payments_csv_export.php" data-testid="ap-payments-export-all-csv">Export all (CSV)</a>
          <button className="btn btn--primary" onClick={() => setShowRecord(true)} data-testid="ap-record-payment">Record payment</button>
        </div>
      </div>

      {/* Bulk-actions toolbar — appears whenever any row is checked */}
      {sel.size > 0 && (
        <div data-testid="ap-payments-bulk-bar" style={bulkBar}>
          <span><strong>{sel.size}</strong> selected</span>
          <ExportTemplatePicker
            dataset="ap_payments"
            buildHref={(tplId) => `/modules/ap/api/payments.php?action=export_template&template_id=${tplId}&ids=${sel.ids.join(',')}`}
            disabled={!sel.size}
            label="Export via template"
            testid="ap-payments-export-template"
          />
          <button className="btn btn--ghost" onClick={exportSelected} data-testid="ap-payments-export-selected">
            Raw CSV
          </button>
          <button className="btn btn--ghost" onClick={sel.clear} data-testid="ap-payments-clear-selection">Clear</button>
        </div>
      )}
      {batchErr && <p className="error" data-testid="ap-payments-batch-error">Batch failed: {batchErr.message}</p>}
      {batchInfo && (
        <p data-testid="ap-payments-batch-success" style={{ background: '#ecfdf5', color: '#065f46', padding: 8, borderRadius: 6, fontSize: 13 }}>
          ✓ Batch originated — rail={batchInfo.rail}, batch_id={batchInfo.batch_id}, items={batchInfo.item_count}, total=${Number(batchInfo.amount_total).toFixed(2)}
          {batchInfo.nacha_filename && <> · file: <code>{batchInfo.nacha_filename}</code></>}
        </p>
      )}

      {loading && <p>Loading…</p>}
      {error && <p className="error">Error: {error.message}</p>}

      <table className="data-table" data-testid="ap-payments-table">
        <thead>
          <tr>
            <th style={{ width: 32 }}>
              <input
                type="checkbox"
                checked={sel.allSelected}
                ref={el => { if (el) el.indeterminate = sel.someSelected; }}
                onChange={sel.toggleAll}
                data-testid="ap-payments-select-all"
                disabled={!rows.length}
              />
            </th>
            <th>#</th><th>Vendor</th><th>Date</th><th>Method</th><th>Ref</th>
            <th style={{textAlign:'right'}}>Amount</th>
            <th style={{textAlign:'right'}}>Unallocated</th>
            <th>Status</th><th>Rail</th><th></th>
          </tr>
        </thead>
        <tbody>
          {rows.length === 0 && !loading && <tr><td colSpan={11} className="empty" data-testid="ap-payments-empty">No payments yet.</td></tr>}
          {rows.map(p => (
            <tr key={p.id} data-testid={`ap-payment-row-${p.id}`} style={sel.has(p.id) ? { background: 'var(--cf-surface-alt, #f9fafb)' } : null}>
              <td>
                <input
                  type="checkbox"
                  checked={sel.has(p.id)}
                  onChange={() => sel.toggle(p.id)}
                  data-testid={`ap-payment-select-${p.id}`}
                />
              </td>
              <td>#{p.id}</td>
              <td>{p.vendor_name}</td>
              <td>{p.pay_date}</td>
              <td>{p.method}</td>
              <td>{p.reference || '—'}</td>
              <td style={{textAlign:'right'}}>{Number(p.amount).toFixed(2)} {p.currency}</td>
              <td style={{textAlign:'right'}}>{Number(p.unallocated_amount).toFixed(2)}</td>
              <td><span className={`badge badge--${p.status}`}>{p.status}</span></td>
              <td style={{ fontSize: 11, color: 'var(--cf-text-secondary)' }}>
                {p.disbursement_rail
                  ? <><span className="badge">{p.disbursement_rail}</span> {p.rail_status || ''}</>
                  : '—'}
              </td>
              <td>
                {Number(p.unallocated_amount) > 0 && (
                  <button className="btn btn--ghost" data-testid={`ap-allocate-open-${p.id}`} onClick={() => setShowAllocate(p)}>Allocate</button>
                )}
                {plaidEligible(p) && (
                  <button
                    className="btn btn--primary"
                    style={{ marginLeft: 6 }}
                    onClick={() => sendViaPlaid(p)}
                    disabled={plaidRow[p.id] === 'busy'}
                    data-testid={`ap-send-via-plaid-${p.id}`}
                    title="Originate this payment as a Plaid Transfer ACH"
                  >
                    {plaidRow[p.id] === 'busy' ? 'Sending…' : 'Send via Plaid'}
                  </button>
                )}
                {plaidRow[p.id] && plaidRow[p.id].error && (
                  <div data-testid={`ap-send-via-plaid-error-${p.id}`} style={{ fontSize: 11, color: 'var(--cf-red, #b91c1c)', marginTop: 4 }}>
                    {plaidRow[p.id].error}
                  </div>
                )}
                {plaidRow[p.id] && plaidRow[p.id].ok && (
                  <div data-testid={`ap-send-via-plaid-ok-${p.id}`} style={{ fontSize: 11, color: 'var(--cf-green, #047857)', marginTop: 4 }}>
                    ✓ Originated ({plaidRow[p.id].ref})
                  </div>
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>

      {showRecord && <RecordPaymentModal onClose={() => setShowRecord(false)} onCreated={() => { setShowRecord(false); reload(); }} plaidEnabled={plaidEnabled} />}
      {showAllocate && <AllocateModal payment={showAllocate} onClose={() => setShowAllocate(null)} onDone={() => { setShowAllocate(null); reload(); }} />}
    </section>
  );
}

const bulkBar = {
  display: 'flex', alignItems: 'center', gap: 12, padding: '10px 14px',
  background: 'var(--cf-surface-alt, #f3f4f6)', borderRadius: 8,
  marginBottom: 12, flexWrap: 'wrap',
};

function RecordPaymentModal({ onClose, onCreated, plaidEnabled }) {
  const [form, setForm] = useState({
    vendor_name: '', pay_date: new Date().toISOString().slice(0, 10), method: 'ach',
    amount: '', reference: '', currency: 'USD', auto_allocate: true, notes: '',
  });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const submit = async () => {
    setBusy(true); setError(null);
    try {
      await api.post('/modules/ap/api/payments.php', { ...form, amount: Number(form.amount) });
      onCreated?.();
    } catch (e) { setError(e); } finally { setBusy(false); }
  };
  return (
    <div data-testid="ap-record-payment-modal" style={modalOverlay} onClick={(e) => e.target === e.currentTarget && !busy && onClose?.()}>
      <div style={modalBox}>
        <header style={modalHeader}><h3 style={{ margin: 0 }}>Record payment</h3></header>
        <div style={{ padding: 20, display: 'grid', gap: 12 }}>
          <Field label="Vendor name"><input className="input" value={form.vendor_name} onChange={(e) => setForm({ ...form, vendor_name: e.target.value })} data-testid="ap-pay-vendor" /></Field>
          <Field label="Pay date"><input className="input" type="date" value={form.pay_date} onChange={(e) => setForm({ ...form, pay_date: e.target.value })} data-testid="ap-pay-date" /></Field>
          <Field label="Method">
            <select className="input" value={form.method} onChange={(e) => setForm({ ...form, method: e.target.value })} data-testid="ap-pay-method">
              <option value="ach">ACH</option>
              <option value="wire">Wire</option>
              <option value="check">Check</option>
              <option value="card">Card</option>
              <option value="cash">Cash</option>
              <option value="plaid" disabled={!plaidEnabled}>Plaid Transfer{plaidEnabled ? '' : ' (not configured)'}</option>
              <option value="other">Other</option>
            </select>
          </Field>
          <Field label="Amount"><input className="input" type="number" step="0.01" value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} data-testid="ap-pay-amount" /></Field>
          <Field label="Reference (check #, wire ref)"><input className="input" value={form.reference} onChange={(e) => setForm({ ...form, reference: e.target.value })} data-testid="ap-pay-reference" /></Field>
          <label style={{ display: 'inline-flex', gap: 6, fontSize: 14 }}>
            <input type="checkbox" checked={form.auto_allocate} onChange={(e) => setForm({ ...form, auto_allocate: e.target.checked })} data-testid="ap-pay-auto-allocate" />
            Auto-allocate FIFO to oldest unpaid bills for this vendor
          </label>
          {error && <p className="error">Error: {error.message}</p>}
        </div>
        <footer style={modalFooter}>
          <button className="btn btn--ghost" onClick={onClose} data-testid="ap-pay-cancel">Cancel</button>
          <button className="btn btn--primary" onClick={submit} disabled={busy || !form.vendor_name || !form.amount} data-testid="ap-pay-save">{busy ? 'Saving…' : 'Record payment'}</button>
        </footer>
      </div>
    </div>
  );
}

function AllocateModal({ payment, onClose, onDone }) {
  const [mode, setMode] = useState('fifo');
  const [manualLines, setManualLines] = useState([{ bill_id: '', amount: '' }]);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const submit = async () => {
    setBusy(true); setError(null);
    try {
      const body = mode === 'fifo'
        ? { auto: 'fifo' }
        : { allocations: manualLines.filter(l => l.bill_id && l.amount).map(l => ({ bill_id: Number(l.bill_id), amount: Number(l.amount) })) };
      await api.post(`/modules/ap/api/payments.php?action=allocate&id=${payment.id}`, body);
      onDone?.();
    } catch (e) { setError(e); } finally { setBusy(false); }
  };
  return (
    <div data-testid="ap-allocate-modal" style={modalOverlay} onClick={(e) => e.target === e.currentTarget && !busy && onClose?.()}>
      <div style={modalBox}>
        <header style={modalHeader}>
          <h3 style={{ margin: 0 }}>Allocate payment #{payment.id}</h3>
          <p style={{ margin: '4px 0 0', fontSize: 13, color: 'var(--cf-text-secondary)' }}>Unallocated: {Number(payment.unallocated_amount).toFixed(2)} {payment.currency} · Vendor: {payment.vendor_name}</p>
        </header>
        <div style={{ padding: 20, display: 'grid', gap: 12 }}>
          <label style={{ display: 'flex', gap: 16 }}>
            <span style={{ display: 'inline-flex', gap: 6 }}>
              <input type="radio" checked={mode === 'fifo'} onChange={() => setMode('fifo')} data-testid="ap-allocate-fifo" /> Auto-FIFO (oldest first)
            </span>
            <span style={{ display: 'inline-flex', gap: 6 }}>
              <input type="radio" checked={mode === 'manual'} onChange={() => setMode('manual')} data-testid="ap-allocate-manual" /> Manual
            </span>
          </label>
          {mode === 'manual' && (
            <div>
              {manualLines.map((l, i) => (
                <div key={i} style={{ display: 'flex', gap: 6, marginBottom: 6 }}>
                  <input className="input" placeholder="Bill id" value={l.bill_id} onChange={(e) => { const next = [...manualLines]; next[i].bill_id = e.target.value; setManualLines(next); }} data-testid={`ap-alloc-bill-${i}`} />
                  <input className="input" placeholder="Amount" value={l.amount} onChange={(e) => { const next = [...manualLines]; next[i].amount = e.target.value; setManualLines(next); }} data-testid={`ap-alloc-amount-${i}`} />
                </div>
              ))}
              <button className="btn btn--ghost" onClick={() => setManualLines([...manualLines, { bill_id: '', amount: '' }])} data-testid="ap-alloc-add-line">+ Add line</button>
            </div>
          )}
          {error && <p className="error">Error: {error.message}</p>}
        </div>
        <footer style={modalFooter}>
          <button className="btn btn--ghost" onClick={onClose} data-testid="ap-alloc-cancel">Cancel</button>
          <button className="btn btn--primary" onClick={submit} disabled={busy} data-testid="ap-alloc-confirm">{busy ? 'Allocating…' : 'Allocate'}</button>
        </footer>
      </div>
    </div>
  );
}

const Field = ({ label, children }) => (
  <label style={{ display: 'block', fontSize: 13 }}>
    <span style={{ color: 'var(--cf-text-secondary)' }}>{label}</span>
    <div style={{ marginTop: 4 }}>{children}</div>
  </label>
);
const modalOverlay = { position: 'fixed', inset: 0, background: 'rgba(15,18,28,0.5)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 16 };
const modalBox = { background: 'var(--cf-surface, #fff)', borderRadius: 12, width: 'min(560px, 100%)', maxHeight: '90vh', display: 'flex', flexDirection: 'column' };
const modalHeader = { padding: 20, borderBottom: '1px solid var(--cf-border, #e5e7eb)' };
const modalFooter = { padding: 16, borderTop: '1px solid var(--cf-border, #e5e7eb)', display: 'flex', justifyContent: 'flex-end', gap: 8, background: 'var(--cf-surface-alt, #f9fafb)' };
