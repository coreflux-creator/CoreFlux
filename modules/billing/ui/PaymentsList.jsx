import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { api, useApi } from '../../../dashboard/src/lib/api';

const METHODS = ['ach','wire','check','card','cash','other'];

export default function PaymentsList() {
  const { data, loading, error, reload } = useApi('/modules/billing/api/payments.php');
  const rows = data?.rows ?? [];
  const [showRecord, setShowRecord] = useState(false);
  const [allocFor, setAllocFor] = useState(null);
  const [pwpToast, setPwpToast] = useState(null);

  const handleAllocResult = (res) => {
    // The /allocate (and /payments auto_allocate) responses now carry a
    // `pwp` array: [{ar_invoice_id, released:[{bill_id,prev_status,new_status,new_due_date}]}].
    // Surface this so AR ops sees "client paid → vendor bills released".
    const groups = res?.pwp || res?.auto_allocation?.pwp || [];
    const totalReleased = groups.reduce((s, g) => s + (g.released?.length || 0), 0);
    if (totalReleased > 0) setPwpToast({ groups, totalReleased });
    setAllocFor(null);
    setShowRecord(false);
    reload();
  };

  return (
    <section data-testid="billing-payments-list">
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 'var(--cf-space-4)' }}>
        <h3 style={{ margin: 0 }}>Payments received</h3>
        <div style={{ display: 'flex', gap: 8 }}>
          <Link to="csv_import" className="btn" data-testid="billing-payments-import-csv">Import CSV</Link>
          <a className="btn" href="/modules/billing/api/payments_csv_export.php" data-testid="billing-payments-export-csv">Export CSV</a>
          <button className="btn btn--primary" onClick={() => setShowRecord(true)} data-testid="billing-record-payment">Record payment</button>
        </div>
      </div>

      {pwpToast && (
        <div data-testid="billing-pwp-toast" role="status"
             style={{ margin: '0 0 16px', padding: '12px 16px', background: '#ecfdf5', borderLeft: '4px solid #10b981', borderRadius: 6, fontSize: 13, color: '#065f46' }}>
          <strong>Pay-When-Paid released:</strong>{' '}
          {pwpToast.totalReleased} vendor bill{pwpToast.totalReleased === 1 ? '' : 's'} freed for payment because the client invoice cleared.
          <details style={{ marginTop: 6 }}>
            <summary style={{ cursor: 'pointer' }}>See details</summary>
            <ul style={{ margin: '6px 0 0', paddingLeft: 18 }}>
              {pwpToast.groups.map(g => (
                <li key={g.ar_invoice_id} style={{ marginBottom: 4 }}>
                  AR invoice #{g.ar_invoice_id}: released {g.released.length} bill(s)
                  <ul style={{ paddingLeft: 16, marginTop: 2 }}>
                    {g.released.map(r => (
                      <li key={r.bill_id} data-testid={`billing-pwp-released-${r.bill_id}`}>
                        Bill #{r.bill_id} — was <em>{r.prev_status}</em>, now <strong>{r.new_status}</strong>, due {r.new_due_date}
                      </li>
                    ))}
                  </ul>
                </li>
              ))}
            </ul>
          </details>
          <button onClick={() => setPwpToast(null)} style={{ marginTop: 6, background: 'transparent', border: 0, color: '#065f46', cursor: 'pointer', fontSize: 12, padding: 0 }} data-testid="billing-pwp-toast-dismiss">Dismiss</button>
        </div>
      )}

      {loading && <p>Loading…</p>}
      {error && <p className="error" data-testid="billing-payments-error">Error: {error.message}</p>}

      <table className="data-table" data-testid="billing-payments-table">
        <thead><tr><th>Received</th><th>Client</th><th>Method</th><th>Reference</th><th style={{textAlign:'right'}}>Amount</th><th style={{textAlign:'right'}}>Unallocated</th><th></th></tr></thead>
        <tbody>
          {rows.length === 0 && !loading && <tr><td colSpan={7} className="empty" data-testid="billing-payments-empty">No payments recorded yet.</td></tr>}
          {rows.map(p => (
            <tr key={p.id} data-testid={`billing-payment-row-${p.id}`}>
              <td>{p.received_at}</td>
              <td>{p.client_name}</td>
              <td>{p.method}</td>
              <td>{p.reference || '—'}</td>
              <td style={{textAlign:'right'}}>{Number(p.amount).toFixed(2)} {p.currency}</td>
              <td style={{textAlign:'right'}}><strong>{Number(p.unallocated_amount).toFixed(2)}</strong></td>
              <td>
                {Number(p.unallocated_amount) > 0 && (
                  <button className="btn" onClick={() => setAllocFor(p)} data-testid={`billing-payment-allocate-${p.id}`}>Allocate</button>
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>

      {showRecord && <RecordPaymentModal onClose={() => setShowRecord(false)} onSaved={handleAllocResult} />}
      {allocFor && <AllocateModal payment={allocFor} onClose={() => setAllocFor(null)} onSaved={handleAllocResult} />}
    </section>
  );
}

function RecordPaymentModal({ onClose, onSaved }) {
  const [form, setForm] = useState({
    client_name: '', received_at: new Date().toISOString().slice(0,10),
    method: 'ach', reference: '', amount: '', currency: 'USD', notes: '',
    auto_allocate: true,
  });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);

  const submit = async () => {
    setBusy(true); setError(null);
    try {
      const res = await api.post('/modules/billing/api/payments.php', { ...form, amount: Number(form.amount) });
      onSaved?.(res);
    } catch (e) { setError(e); }
    finally { setBusy(false); }
  };

  return (
    <div data-testid="billing-record-payment-modal" style={{ position: 'fixed', inset: 0, background: 'rgba(15,18,28,0.5)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 16 }} onClick={(e) => e.target === e.currentTarget && !busy && onClose()}>
      <div style={{ background: 'var(--cf-surface, #fff)', borderRadius: 12, width: 'min(520px, 100%)', padding: 24 }}>
        <h3 style={{ margin: '0 0 16px' }}>Record payment</h3>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
          <Field label="Client" testid="billing-rp-client"><input className="input" value={form.client_name} onChange={(e) => setForm({ ...form, client_name: e.target.value })} data-testid="billing-rp-client-input" /></Field>
          <Field label="Received" testid="billing-rp-date"><input className="input" type="date" value={form.received_at} onChange={(e) => setForm({ ...form, received_at: e.target.value })} data-testid="billing-rp-date-input" /></Field>
          <Field label="Method"><select className="input" value={form.method} onChange={(e) => setForm({ ...form, method: e.target.value })} data-testid="billing-rp-method">{METHODS.map(m => <option key={m}>{m}</option>)}</select></Field>
          <Field label="Reference"><input className="input" value={form.reference} onChange={(e) => setForm({ ...form, reference: e.target.value })} data-testid="billing-rp-reference" /></Field>
          <Field label="Amount"><input className="input" type="number" step="0.01" value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} data-testid="billing-rp-amount" /></Field>
          <Field label="Currency"><input className="input" value={form.currency} onChange={(e) => setForm({ ...form, currency: e.target.value.toUpperCase() })} maxLength={3} data-testid="billing-rp-currency" /></Field>
        </div>
        <label style={{ display: 'flex', alignItems: 'center', gap: 8, marginTop: 12, fontSize: 13 }}>
          <input type="checkbox" checked={form.auto_allocate} onChange={(e) => setForm({ ...form, auto_allocate: e.target.checked })} data-testid="billing-rp-auto-allocate" />
          Auto-allocate FIFO to oldest unpaid invoices for this client
        </label>
        {error && <p className="error" data-testid="billing-rp-error" style={{ marginTop: 12 }}>Error: {error.message}</p>}
        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 16 }}>
          <button className="btn btn--ghost" onClick={onClose} disabled={busy} data-testid="billing-rp-cancel">Cancel</button>
          <button className="btn btn--primary" onClick={submit} disabled={busy || !form.client_name || !form.amount} data-testid="billing-rp-save">{busy ? 'Saving…' : 'Save'}</button>
        </div>
      </div>
    </div>
  );
}

function AllocateModal({ payment, onClose, onSaved }) {
  const { data } = useApi(`/modules/billing/api/invoices.php?client_name=${encodeURIComponent(payment.client_name)}&status=sent`);
  const [allocs, setAllocs] = useState({});
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const open = (data?.rows || []).filter(r => Number(r.amount_due) > 0);
  const totalAlloc = Object.values(allocs).reduce((s, v) => s + (Number(v) || 0), 0);

  const set = (id, v) => setAllocs(prev => ({ ...prev, [id]: v }));

  const autoFifo = async () => {
    setBusy(true); setError(null);
    try {
      const res = await api.post(`/modules/billing/api/payments.php?action=allocate&id=${payment.id}`, { auto: 'fifo' });
      onSaved?.(res);
    }
    catch (e) { setError(e); }
    finally { setBusy(false); }
  };

  const submit = async () => {
    setBusy(true); setError(null);
    try {
      const allocations = Object.entries(allocs)
        .filter(([_, v]) => Number(v) > 0)
        .map(([invoice_id, amount]) => ({ invoice_id: Number(invoice_id), amount: Number(amount) }));
      if (allocations.length === 0) { setError(new Error('Enter at least one allocation amount.')); setBusy(false); return; }
      const res = await api.post(`/modules/billing/api/payments.php?action=allocate&id=${payment.id}`, { allocations });
      onSaved?.(res);
    } catch (e) { setError(e); }
    finally { setBusy(false); }
  };

  return (
    <div data-testid="billing-allocate-modal" style={{ position: 'fixed', inset: 0, background: 'rgba(15,18,28,0.5)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 16 }} onClick={(e) => e.target === e.currentTarget && !busy && onClose()}>
      <div style={{ background: 'var(--cf-surface, #fff)', borderRadius: 12, width: 'min(640px, 100%)', maxHeight: '85vh', display: 'flex', flexDirection: 'column' }}>
        <header style={{ padding: 20, borderBottom: '1px solid var(--cf-border, #e5e7eb)' }}>
          <h3 style={{ margin: '0 0 4px' }}>Allocate payment to {payment.client_name}'s invoices</h3>
          <p style={{ margin: 0, fontSize: 13, color: 'var(--cf-text-secondary)' }}>Available to allocate: <strong>${Number(payment.unallocated_amount).toFixed(2)}</strong> {payment.currency}</p>
        </header>
        <div style={{ overflow: 'auto', padding: 20, flex: 1 }}>
          {open.length === 0 && <p style={{ color: 'var(--cf-text-secondary)' }} data-testid="billing-allocate-empty">No open invoices for this client.</p>}
          {open.length > 0 && (
            <table className="data-table" data-testid="billing-allocate-table">
              <thead><tr><th>Invoice</th><th>Due</th><th style={{textAlign:'right'}}>Amount due</th><th>Apply</th></tr></thead>
              <tbody>
                {open.map(inv => (
                  <tr key={inv.id}>
                    <td>{inv.invoice_number}</td>
                    <td>{inv.due_date}</td>
                    <td style={{textAlign:'right'}}>{Number(inv.amount_due).toFixed(2)}</td>
                    <td><input className="input" type="number" step="0.01" min="0" max={inv.amount_due} value={allocs[inv.id] || ''} onChange={(e) => set(inv.id, e.target.value)} data-testid={`billing-allocate-input-${inv.id}`} style={{ width: 100 }} /></td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
          {error && <p className="error" data-testid="billing-allocate-error" style={{ marginTop: 12 }}>Error: {error.message}</p>}
        </div>
        <footer style={{ padding: 16, borderTop: '1px solid var(--cf-border, #e5e7eb)', display: 'flex', justifyContent: 'space-between', alignItems: 'center', background: 'var(--cf-surface-alt, #f9fafb)' }}>
          <span style={{ fontSize: 13 }}>Total to allocate: <strong>${totalAlloc.toFixed(2)}</strong></span>
          <div style={{ display: 'flex', gap: 8 }}>
            <button className="btn btn--ghost" onClick={onClose} disabled={busy} data-testid="billing-allocate-cancel">Cancel</button>
            <button className="btn" onClick={autoFifo} disabled={busy || open.length === 0} data-testid="billing-allocate-fifo">Auto-FIFO</button>
            <button className="btn btn--primary" onClick={submit} disabled={busy || totalAlloc <= 0} data-testid="billing-allocate-confirm">{busy ? 'Applying…' : 'Apply'}</button>
          </div>
        </footer>
      </div>
    </div>
  );
}

function Field({ label, children }) {
  return (
    <label style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
      <span style={{ fontSize: 12, color: 'var(--cf-text-secondary)' }}>{label}</span>
      {children}
    </label>
  );
}
