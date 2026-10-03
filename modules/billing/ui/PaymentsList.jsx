import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { api, useApi } from '../../../dashboard/src/lib/api';
import IdBadge from '../../../dashboard/src/components/IdBadge';

const METHODS = ['ach','wire','check','card','cash','other'];
const localDate = () => {
  const now = new Date();
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
};

export default function PaymentsList() {
  const { data, loading, error, reload } = useApi('/api/v1/billing/payments');
  const rows = data?.rows ?? [];
  const [showRecord, setShowRecord] = useState(false);
  const [allocFor, setAllocFor] = useState(null);
  const [applyFor, setApplyFor] = useState(null);
  const [refundFor, setRefundFor] = useState(null);
  const [activityFor, setActivityFor] = useState(null);
  const [correctFor, setCorrectFor] = useState(null);
  const [pwpToast, setPwpToast] = useState(null);

  const handleAllocResult = (res) => {
    // The /allocate (and /payments auto_allocate) responses now carry a
    // `pwp` array: [{ar_invoice_id, released:[{bill_id,prev_status,new_status,new_due_date}]}].
    // Surface this so AR ops sees "client paid → vendor bills released".
    const groups = res?.pwp || res?.auto_allocation?.pwp || [];
    const totalReleased = groups.reduce((s, g) => s + (g.released?.length || 0), 0);
    if (totalReleased > 0) setPwpToast({ groups, totalReleased });
    setAllocFor(null);
    setApplyFor(null);
    setRefundFor(null);
    setActivityFor(null);
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

      <div style={{ overflowX: 'auto' }}>
      <table className="data-table" style={{ minWidth: 1220 }} data-testid="billing-payments-table">
        <thead><tr><th>ID</th><th>Received</th><th>Client</th><th>Method</th><th>Reference</th><th style={{textAlign:'right'}}>Amount</th><th style={{textAlign:'right'}}>Unallocated</th><th>Status</th><th></th></tr></thead>
        <tbody>
          {rows.length === 0 && !loading && <tr><td colSpan={9} className="empty" data-testid="billing-payments-empty">No payments recorded yet.</td></tr>}
          {rows.map(p => (
            <tr key={p.id} data-testid={`billing-payment-row-${p.id}`}>
              <td style={{ whiteSpace: 'nowrap' }}><IdBadge id={p.id} prefix="RCP" /></td>
              <td style={{ whiteSpace: 'nowrap' }}>{p.received_at}</td>
              <td>{p.client_name}</td>
              <td>{p.method}</td>
              <td>{p.reference || '—'}</td>
              <td style={{textAlign:'right', whiteSpace: 'nowrap'}}>{Number(p.amount).toFixed(2)} {p.currency}</td>
              <td style={{textAlign:'right'}}><strong>{Number(p.unallocated_amount).toFixed(2)}</strong></td>
              <td>
                {p.receipt_state === 'corrected' ? 'Corrected' : p.can_apply_deposit ? 'Posted deposit' : p.receipt_state === 'posted' ? 'Posted' : 'Pending ledger'}
                {Number(p.refunded_amount) > 0 && <small style={{ display: 'block', color: 'var(--cf-text-secondary)' }}>Refunded {Number(p.refunded_amount).toFixed(2)} {p.currency}</small>}
              </td>
              <td>
                <div style={{ display: 'flex', gap: 4, flexWrap: 'wrap' }}>
                  {p.receipt_state === 'pending' && <button className="btn" onClick={() => setAllocFor(p)} data-testid={`billing-payment-allocate-${p.id}`}>Post payment</button>}
                  {p.can_apply_deposit && <button className="btn" onClick={() => setApplyFor(p)} data-testid={`billing-deposit-apply-${p.id}`}>Apply deposit</button>}
                  {p.can_refund_deposit && <button className="btn" onClick={() => setRefundFor(p)} data-testid={`billing-deposit-refund-${p.id}`}>Record refund</button>}
                  {p.has_deposit_activity && <button className="btn" onClick={() => setActivityFor(p)} data-testid={`billing-deposit-activity-${p.id}`}>Activity</button>}
                  {p.can_correct && <button className="btn" onClick={() => setCorrectFor(p)} data-testid={`billing-payment-correct-${p.id}`}>Correct</button>}
                  {p.journal_entry_id && <Link className="btn btn--ghost" to={`/modules/accounting/journal-entries/${p.journal_entry_id}`}>Journal</Link>}
                </div>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      </div>

      {showRecord && <RecordPaymentModal onClose={() => setShowRecord(false)} onSaved={handleAllocResult} />}
      {allocFor && <AllocateModal payment={allocFor} onClose={() => setAllocFor(null)} onSaved={handleAllocResult} />}
      {applyFor && <DepositApplyModal payment={applyFor} onClose={() => setApplyFor(null)} onSaved={handleAllocResult} />}
      {refundFor && <DepositRefundModal payment={refundFor} onClose={() => setRefundFor(null)} onSaved={handleAllocResult} />}
      {activityFor && <DepositActivityModal payment={activityFor} onClose={() => setActivityFor(null)} onSaved={handleAllocResult} />}
      {correctFor && <CorrectPaymentModal payment={correctFor} onClose={() => setCorrectFor(null)} onSaved={() => { setCorrectFor(null); reload(); }} />}
    </section>
  );
}

function RecordPaymentModal({ onClose, onSaved }) {
  const { data: bankData, error: bankError } = useApi('/modules/accounting/api/bank_accounts.php');
  const [search, setSearch] = useState('');
  const { data: invoiceData, error: invoiceError } = useApi(`/api/v1/billing/payments?action=eligible_invoices&q=${encodeURIComponent(search)}`);
  const [form, setForm] = useState({
    client_name: '', received_at: localDate(),
    method: 'ach', reference: '', amount: '', currency: 'USD', bank_account_id: '',
  });
  const [requestKey] = useState(() => crypto.randomUUID());
  const [mode, setMode] = useState('fifo');
  const [holdUnapplied, setHoldUnapplied] = useState(false);
  const [allocs, setAllocs] = useState({});
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const banks = bankData?.rows || [];
  const bank = banks.find(b => String(b.id) === String(form.bank_account_id));
  const eligible = (invoiceData?.rows || []).filter(i =>
    i.currency === form.currency && (!bank?.entity_id || !i.entity_id || Number(i.entity_id) === Number(bank.entity_id))
  );
  const clients = [...new Set(eligible.map(i => i.client_name))];
  const open = eligible.filter(i => i.client_name === form.client_name && i.issue_date <= form.received_at);
  const openBalance = open.reduce((sum, i) => sum + Number(i.amount_due), 0);
  const allocated = Object.values(allocs).reduce((sum, value) => sum + (Number(value) || 0), 0);
  const amount = Number(form.amount);
  const validAmount = Number.isFinite(amount) && amount > 0;
  const ready = form.client_name.trim() && form.bank_account_id && validAmount
    && (mode === 'deposit' ? true
      : mode === 'fifo' ? open.length > 0 && (holdUnapplied || amount <= openBalance + 0.005)
        : allocated > 0 && allocated <= amount + 0.005
          && (holdUnapplied || Math.abs(allocated - amount) < 0.005));

  const submit = async () => {
    setBusy(true); setError(null);
    try {
      const allocations = Object.entries(allocs)
        .filter(([, value]) => Number(value) > 0)
        .map(([invoice_id, value]) => ({ invoice_id: Number(invoice_id), amount: Number(value) }));
      const res = await api.post('/api/v1/billing/payments', {
        ...form, bank_account_id: Number(form.bank_account_id), amount,
        request_key: requestKey, auto_allocate: mode === 'fifo',
        hold_unapplied: mode === 'deposit' || holdUnapplied,
        allocations: mode === 'specific' ? allocations : [],
      });
      onSaved?.(res);
    } catch (e) { setError(e); }
    finally { setBusy(false); }
  };

  return (
    <div data-testid="billing-record-payment-modal" style={{ position: 'fixed', inset: 0, background: 'rgba(15,18,28,0.5)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 16 }} onClick={(e) => e.target === e.currentTarget && !busy && onClose()}>
      <div style={{ background: 'var(--cf-surface, #fff)', borderRadius: 8, width: 'min(700px, 100%)', maxHeight: '90vh', overflow: 'auto', padding: 24 }}>
        <h3 style={{ margin: '0 0 16px' }}>Record payment</h3>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
          <Field label="Find client or invoice"><input className="input" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search open invoices" data-testid="billing-rp-search" /></Field>
          <Field label="Client">{mode === 'deposit'
            ? <input className="input" value={form.client_name} onChange={(e) => setForm({ ...form, client_name: e.target.value })} placeholder="Customer name" data-testid="billing-rp-client-input" />
            : <select className="input" value={form.client_name} onChange={(e) => { setForm({ ...form, client_name: e.target.value }); setAllocs({}); }} data-testid="billing-rp-client-input"><option value="">Choose a client</option>{clients.map(client => <option key={client} value={client}>{client}</option>)}</select>}</Field>
          <Field label="Received" testid="billing-rp-date"><input className="input" type="date" value={form.received_at} onChange={(e) => setForm({ ...form, received_at: e.target.value })} data-testid="billing-rp-date-input" /></Field>
          <Field label="Deposit to"><select className="input" value={form.bank_account_id} onChange={(e) => { const selected = banks.find(b => String(b.id) === e.target.value); setForm({ ...form, client_name: '', bank_account_id: e.target.value, currency: selected?.currency || form.currency }); setAllocs({}); }} data-testid="billing-rp-bank"><option value="">Choose bank account</option>{banks.map(b => <option key={b.id} value={b.id}>{b.name} · {b.currency}</option>)}</select></Field>
          <Field label="Method"><select className="input" value={form.method} onChange={(e) => setForm({ ...form, method: e.target.value })} data-testid="billing-rp-method">{METHODS.map(m => <option key={m}>{m}</option>)}</select></Field>
          <Field label="Reference"><input className="input" value={form.reference} onChange={(e) => setForm({ ...form, reference: e.target.value })} data-testid="billing-rp-reference" /></Field>
          <Field label="Amount"><input className="input" type="number" step="0.01" value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} data-testid="billing-rp-amount" /></Field>
          <Field label="Currency"><input className="input" value={form.currency} readOnly data-testid="billing-rp-currency" /></Field>
        </div>
        <div style={{ display: 'flex', gap: 6, marginTop: 16 }} role="group" aria-label="Invoice allocation">
          <button className={`btn ${mode === 'fifo' ? 'btn--primary' : ''}`} onClick={() => setMode('fifo')} data-testid="billing-rp-auto-allocate">Oldest invoices</button>
          <button className={`btn ${mode === 'specific' ? 'btn--primary' : ''}`} onClick={() => setMode('specific')}>Choose invoices</button>
          <button className={`btn ${mode === 'deposit' ? 'btn--primary' : ''}`} onClick={() => { setMode('deposit'); setAllocs({}); }} data-testid="billing-rp-hold-deposit">Customer deposit</button>
        </div>
        {mode !== 'deposit' && form.client_name && <p style={{ fontSize: 13, color: 'var(--cf-text-secondary)' }}>{open.length} posted open invoice{open.length === 1 ? '' : 's'} for {form.client_name}, with {openBalance.toFixed(2)} {form.currency} available.</p>}
        {mode === 'fifo' && validAmount && form.client_name && amount > openBalance + 0.005 && !holdUnapplied && <p className="error">The receipt exceeds open invoices. Hold the remainder as a customer deposit to continue.</p>}
        {mode === 'specific' && open.length > 0 && (
          <table className="data-table" data-testid="billing-rp-invoices">
            <thead><tr><th>Invoice</th><th>Due</th><th style={{ textAlign: 'right' }}>Open</th><th>Apply</th></tr></thead>
            <tbody>{open.map(i => <tr key={i.id}><td>{i.invoice_number}</td><td>{i.due_date}</td><td style={{ textAlign: 'right' }}>{Number(i.amount_due).toFixed(2)}</td><td><input className="input" type="number" min="0" max={i.amount_due} step="0.01" value={allocs[i.id] || ''} onChange={e => setAllocs({ ...allocs, [i.id]: e.target.value })} data-testid={`billing-rp-invoice-${i.id}`} style={{ width: 110 }} /></td></tr>)}</tbody>
          </table>
        )}
        {mode === 'specific' && <p style={{ fontSize: 13 }}>Applied: {allocated.toFixed(2)} of {validAmount ? amount.toFixed(2) : '0.00'} {form.currency}</p>}
        {mode !== 'deposit' && <label style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13 }}><input type="checkbox" checked={holdUnapplied} onChange={e => setHoldUnapplied(e.target.checked)} data-testid="billing-rp-hold-remainder" />Hold any unapplied remainder as a customer deposit</label>}
        {mode === 'deposit' && <p style={{ fontSize: 13, color: 'var(--cf-text-secondary)' }}>The full amount will be recorded in the selected bank account and held as a customer deposit until you apply it to an invoice.</p>}
        {(bankError || invoiceError) && <p className="error">Could not load bank accounts or open invoices. Refresh and try again.</p>}
        {error && <p className="error" data-testid="billing-rp-error" style={{ marginTop: 12 }}>Error: {error.message}</p>}
        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 16 }}>
          <button className="btn btn--ghost" onClick={onClose} disabled={busy} data-testid="billing-rp-cancel">Cancel</button>
          <button className="btn btn--primary" onClick={submit} disabled={busy || !ready} data-testid="billing-rp-save">{busy ? 'Posting…' : 'Record and post'}</button>
        </div>
      </div>
    </div>
  );
}

function AllocateModal({ payment, onClose, onSaved }) {
  const { data } = useApi(`/api/v1/billing/payments?action=eligible_invoices&q=${encodeURIComponent(payment.client_name)}`);
  const { data: bankData } = useApi('/modules/accounting/api/bank_accounts.php');
  const [bankAccountId, setBankAccountId] = useState('');
  const [allocs, setAllocs] = useState({});
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const banks = bankData?.rows || [];
  const bank = banks.find(b => String(b.id) === bankAccountId);
  const open = (data?.rows || []).filter(r => r.client_name === payment.client_name
    && r.currency === payment.currency
    && (!bank?.entity_id || !r.entity_id || Number(r.entity_id) === Number(bank.entity_id))
    && r.issue_date <= payment.received_at);
  const totalAlloc = Object.values(allocs).reduce((s, v) => s + (Number(v) || 0), 0);

  const set = (id, v) => setAllocs(prev => ({ ...prev, [id]: v }));

  const autoFifo = async () => {
    setBusy(true); setError(null);
    try {
      const res = await api.post(`/api/v1/billing/payments?action=post&id=${payment.id}`, { bank_account_id: Number(bankAccountId), auto: 'fifo' });
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
      if (Number(payment.unallocated_amount) > 0 && allocations.length === 0) { setError(new Error('Enter at least one allocation amount.')); setBusy(false); return; }
      const res = await api.post(`/api/v1/billing/payments?action=post&id=${payment.id}`, { bank_account_id: Number(bankAccountId), allocations });
      onSaved?.(res);
    } catch (e) { setError(e); }
    finally { setBusy(false); }
  };

  return (
    <div data-testid="billing-allocate-modal" style={{ position: 'fixed', inset: 0, background: 'rgba(15,18,28,0.5)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 16 }} onClick={(e) => e.target === e.currentTarget && !busy && onClose()}>
      <div style={{ background: 'var(--cf-surface, #fff)', borderRadius: 12, width: 'min(640px, 100%)', maxHeight: '85vh', display: 'flex', flexDirection: 'column' }}>
        <header style={{ padding: 20, borderBottom: '1px solid var(--cf-border, #e5e7eb)' }}>
          <h3 style={{ margin: '0 0 4px' }}>Post {payment.client_name}'s payment</h3>
          <p style={{ margin: 0, fontSize: 13, color: 'var(--cf-text-secondary)' }}>Apply the remaining <strong>${Number(payment.unallocated_amount).toFixed(2)}</strong> {payment.currency} to posted invoices and record the cash entry.</p>
        </header>
        <div style={{ overflow: 'auto', padding: 20, flex: 1 }}>
          <Field label="Deposit to"><select className="input" value={bankAccountId} onChange={e => setBankAccountId(e.target.value)} data-testid="billing-allocate-bank"><option value="">Choose bank account</option>{banks.filter(b => b.currency === payment.currency).map(b => <option key={b.id} value={b.id}>{b.name} · {b.currency}</option>)}</select></Field>
          {open.length === 0 && Number(payment.unallocated_amount) > 0 && <p style={{ color: 'var(--cf-text-secondary)' }} data-testid="billing-allocate-empty">No posted open invoices for this client.</p>}
          {Number(payment.unallocated_amount) === 0 && <p style={{ color: 'var(--cf-text-secondary)' }}>This payment is already fully allocated. Post it to the selected bank account and receivables.</p>}
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
          <span style={{ fontSize: 13 }}>New allocation: <strong>${totalAlloc.toFixed(2)}</strong></span>
          <div style={{ display: 'flex', gap: 8 }}>
            <button className="btn btn--ghost" onClick={onClose} disabled={busy} data-testid="billing-allocate-cancel">Cancel</button>
            {Number(payment.unallocated_amount) > 0 && <button className="btn" onClick={autoFifo} disabled={busy || !bankAccountId || open.length === 0} data-testid="billing-allocate-fifo">Post oldest first</button>}
            <button className="btn btn--primary" onClick={submit} disabled={busy || !bankAccountId || Math.abs(totalAlloc - Number(payment.unallocated_amount)) > 0.005} data-testid="billing-allocate-confirm">{busy ? 'Posting…' : 'Post payment'}</button>
          </div>
        </footer>
      </div>
    </div>
  );
}

function DepositApplyModal({ payment, onClose, onSaved }) {
  const { data: invoiceData, loading: invoiceLoading, error: invoiceError } = useApi(`/api/v1/billing/payments?action=eligible_invoices&q=${encodeURIComponent(payment.client_name)}`);
  const { data: bankData, loading: bankLoading, error: bankError } = useApi('/modules/accounting/api/bank_accounts.php');
  const [invoiceId, setInvoiceId] = useState('');
  const [amount, setAmount] = useState('');
  const [appliedAt, setAppliedAt] = useState(() => payment.received_at);
  const [requestKey] = useState(() => crypto.randomUUID());
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const bank = (bankData?.rows || []).find(b => Number(b.id) === Number(payment.bank_account_id));
  const invoices = (invoiceData?.rows || []).filter(i =>
    i.client_name === payment.client_name && i.currency === payment.currency
    && (!bank?.entity_id || !i.entity_id || Number(bank.entity_id) === Number(i.entity_id))
    && i.issue_date <= appliedAt
  );
  const invoice = invoices.find(i => String(i.id) === invoiceId);
  const value = Number(amount);
  const ready = !invoiceLoading && !bankLoading && !invoiceError && !bankError
    && appliedAt >= payment.received_at && invoice && Number.isFinite(value) && value > 0
    && value <= Number(payment.unallocated_amount) + 0.005
    && value <= Number(invoice.amount_due) + 0.005;

  const submit = async () => {
    setBusy(true); setError(null);
    try {
      const res = await api.post(`/api/v1/billing/payments?action=apply_deposit&id=${payment.id}`, {
        invoice_id: Number(invoiceId), amount: value, applied_at: appliedAt, request_key: requestKey,
      });
      onSaved?.(res);
    } catch (e) { setError(e); }
    finally { setBusy(false); }
  };

  return (
    <div data-testid="billing-apply-deposit-modal" style={{ position: 'fixed', inset: 0, background: 'rgba(15,18,28,0.5)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 16 }} onClick={e => e.target === e.currentTarget && !busy && onClose()}>
      <div style={{ background: 'var(--cf-surface, #fff)', borderRadius: 8, width: 'min(520px, 100%)', padding: 24 }}>
        <h3 style={{ marginTop: 0 }}>Apply customer deposit</h3>
        <p style={{ fontSize: 13, color: 'var(--cf-text-secondary)' }}>{payment.client_name} has {Number(payment.unallocated_amount).toFixed(2)} {payment.currency} available. Applying it reduces the deposit liability and the selected invoice balance; it does not record cash again.</p>
        <div style={{ display: 'grid', gap: 12 }}>
          <Field label="Application date"><input className="input" type="date" min={payment.received_at} value={appliedAt} onChange={e => { setAppliedAt(e.target.value); setInvoiceId(''); }} data-testid="billing-da-date" /></Field>
          <Field label="Invoice"><select className="input" value={invoiceId} onChange={e => setInvoiceId(e.target.value)} data-testid="billing-da-invoice"><option value="">Choose an open invoice</option>{invoices.map(i => <option key={i.id} value={i.id}>{i.invoice_number} · {Number(i.amount_due).toFixed(2)} {i.currency} due</option>)}</select></Field>
          <Field label="Amount to apply"><input className="input" type="number" min="0.01" step="0.01" value={amount} onChange={e => setAmount(e.target.value)} data-testid="billing-da-amount" /></Field>
        </div>
        {(invoiceLoading || bankLoading) && <p style={{ fontSize: 13 }}>Loading invoices and bank details…</p>}
        {!invoiceLoading && !bankLoading && !invoices.length && !invoiceError && !bankError && <p style={{ fontSize: 13 }}>No posted open invoices match this customer and legal entity.</p>}
        {(invoiceError || bankError) && <p className="error">Could not load open invoices or bank details. Refresh and try again.</p>}
        {error && <p className="error" data-testid="billing-da-error">Error: {error.message}</p>}
        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 16 }}>
          <button className="btn btn--ghost" onClick={onClose} disabled={busy}>Cancel</button>
          <button className="btn btn--primary" onClick={submit} disabled={busy || !ready} data-testid="billing-da-submit">{busy ? 'Applying…' : 'Apply deposit'}</button>
        </div>
      </div>
    </div>
  );
}

function DepositRefundModal({ payment, onClose, onSaved }) {
  const { data: bankData, loading, error: bankError } = useApi('/modules/accounting/api/bank_accounts.php');
  const [bankAccountId, setBankAccountId] = useState(String(payment.bank_account_id || ''));
  const [amount, setAmount] = useState(Number(payment.unallocated_amount).toFixed(2));
  const [refundedAt, setRefundedAt] = useState('');
  const [reference, setReference] = useState('');
  const [requestKey] = useState(() => crypto.randomUUID());
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const allBanks = bankData?.rows || [];
  const originalBank = allBanks.find(b => Number(b.id) === Number(payment.bank_account_id));
  const banks = allBanks.filter(b => b.status === 'active' && b.currency === payment.currency
    && originalBank && Number(b.entity_id) === Number(originalBank.entity_id));
  const value = Number(amount);
  const ready = !loading && !bankError && banks.some(b => String(b.id) === bankAccountId)
    && refundedAt >= payment.received_at && Number.isFinite(value) && value > 0
    && value <= Number(payment.unallocated_amount) + 0.005;

  const submit = async () => {
    setBusy(true); setError(null);
    try {
      const res = await api.post(`/api/v1/billing/payments?action=refund_deposit&id=${payment.id}`, {
        bank_account_id: Number(bankAccountId), amount: value,
        refunded_at: refundedAt, reference: reference.trim(), request_key: requestKey,
      });
      onSaved?.(res);
    } catch (e) { setError(e); }
    finally { setBusy(false); }
  };

  return (
    <div data-testid="billing-refund-deposit-modal" style={{ position: 'fixed', inset: 0, background: 'rgba(15,18,28,0.5)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 16 }} onClick={e => e.target === e.currentTarget && !busy && onClose()}>
      <div style={{ background: 'var(--cf-surface, #fff)', borderRadius: 8, width: 'min(520px, 100%)', maxHeight: '90vh', overflow: 'auto', padding: 24 }}>
        <h3 style={{ marginTop: 0 }}>Record customer deposit refund</h3>
        <p style={{ fontSize: 13, color: 'var(--cf-text-secondary)' }}>
          {payment.client_name} has {Number(payment.unallocated_amount).toFixed(2)} {payment.currency} unapplied.
          Record a refund that has already been sent. This posts a bank outflow and reduces the deposit liability; it does not send money.
        </p>
        <div style={{ display: 'grid', gap: 12 }}>
          <Field label="Refund date"><input className="input" type="date" min={payment.received_at} value={refundedAt} onChange={e => setRefundedAt(e.target.value)} data-testid="billing-dr-date" /></Field>
          <Field label="Paid from"><select className="input" value={bankAccountId} onChange={e => setBankAccountId(e.target.value)} data-testid="billing-dr-bank"><option value="">Choose bank account</option>{banks.map(b => <option key={b.id} value={b.id}>{b.name} · {b.currency}</option>)}</select></Field>
          <Field label="Amount"><input className="input" type="number" min="0.01" max={payment.unallocated_amount} step="0.01" value={amount} onChange={e => setAmount(e.target.value)} data-testid="billing-dr-amount" /></Field>
          <Field label="Bank or check reference"><input className="input" maxLength={255} value={reference} onChange={e => setReference(e.target.value)} data-testid="billing-dr-reference" /></Field>
        </div>
        {loading && <p style={{ fontSize: 13 }}>Loading bank accounts…</p>}
        {bankError && <p className="error">Could not load bank accounts. Refresh and try again.</p>}
        {!loading && !bankError && banks.length === 0 && <p className="error">No active bank account matches this deposit's legal entity and currency.</p>}
        {error && <p className="error" data-testid="billing-dr-error">Error: {error.message}</p>}
        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 16 }}>
          <button className="btn btn--ghost" onClick={onClose} disabled={busy}>Cancel</button>
          <button className="btn btn--primary" onClick={submit} disabled={busy || !ready} data-testid="billing-dr-submit">{busy ? 'Recording…' : 'Record refund'}</button>
        </div>
      </div>
    </div>
  );
}

function DepositActivityModal({ payment, onClose, onSaved }) {
  const { data, loading, error: loadError } = useApi(`/api/v1/billing/payments?action=deposit_activity&id=${payment.id}`);
  const [correcting, setCorrecting] = useState(null);
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const applications = data?.applications || [];
  const refunds = data?.refunds || [];
  const startCorrection = (kind, id) => { setCorrecting({ kind, id }); setReason(''); setError(null); };
  const submitCorrection = async () => {
    if (!correcting || !reason.trim()) return;
    setBusy(true); setError(null);
    try {
      const action = correcting.kind === 'application' ? 'correct_deposit_application' : 'correct_deposit_refund';
      const res = await api.post(`/api/v1/billing/payments?action=${action}&id=${correcting.id}`, { reason: reason.trim() });
      onSaved?.(res);
    } catch (e) { setError(e); }
    finally { setBusy(false); }
  };

  return (
    <div data-testid="billing-deposit-activity-modal" role="dialog" aria-modal="true" aria-label="Customer deposit activity" style={{ position: 'fixed', inset: 0, background: 'rgba(15,18,28,0.5)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 16 }} onClick={e => e.target === e.currentTarget && !busy && onClose()}>
      <div style={{ background: 'var(--cf-surface, #fff)', borderRadius: 8, width: 'min(740px, 100%)', maxHeight: '90vh', overflow: 'auto', padding: 24 }}>
        <h3 style={{ marginTop: 0 }}>Deposit activity · RCP-{payment.id}</h3>
        <p style={{ fontSize: 13, color: 'var(--cf-text-secondary)' }}>
          {payment.client_name} · {Number(payment.unallocated_amount).toFixed(2)} {payment.currency} still unapplied.
          Corrections reverse the source entry and its ledger effect; they do not move money.
        </p>
        {loading && <p>Loading activity…</p>}
        {loadError && <p className="error">Could not load deposit activity. Refresh and try again.</p>}
        {!loading && !loadError && <>
          <h4>Invoice applications</h4>
          {applications.length === 0 && <p>No invoice applications yet.</p>}
          {applications.length > 0 && <div style={{ overflowX: 'auto' }}><table className="data-table" style={{ minWidth: 570 }}>
            <thead><tr><th>Applied</th><th>Invoice</th><th>Amount</th><th>Status</th><th>Journal</th><th></th></tr></thead>
            <tbody>{applications.map(a => <tr key={a.id}>
              <td>{a.applied_at}</td><td>{a.invoice_number || `#${a.invoice_id}`}</td>
              <td>{Number(a.amount).toFixed(2)} {payment.currency}</td>
              <td>{a.reversed_at ? 'Corrected' : 'Applied'}</td>
              <td><Link to={`/modules/accounting/journal-entries/${a.journal_entry_id}`}>Original</Link>{a.reversal_je_id && <> · <Link to={`/modules/accounting/journal-entries/${a.reversal_je_id}`}>Reversal</Link></>}</td>
              <td>{!a.reversed_at && <button className="btn" onClick={() => startCorrection('application', a.id)} data-testid={`billing-da-correct-${a.id}`}>Correct</button>}</td>
            </tr>)}</tbody>
          </table></div>}
          <h4 style={{ marginTop: 20 }}>Cash refunds recorded</h4>
          {refunds.length === 0 && <p>No refunds recorded.</p>}
          {refunds.length > 0 && <div style={{ overflowX: 'auto' }}><table className="data-table" style={{ minWidth: 600 }}>
            <thead><tr><th>Refund date</th><th>Paid from</th><th>Amount</th><th>Status</th><th>Journal</th><th></th></tr></thead>
            <tbody>{refunds.map(r => <tr key={r.id}>
              <td>{r.refunded_at}</td><td>{r.bank_name || 'Bank account'}</td>
              <td>{Number(r.amount).toFixed(2)} {r.currency}</td>
              <td>{r.reversed_at ? 'Corrected' : 'Recorded'}</td>
              <td><Link to={`/modules/accounting/journal-entries/${r.journal_entry_id}`}>Original</Link>{r.reversal_je_id && <> · <Link to={`/modules/accounting/journal-entries/${r.reversal_je_id}`}>Reversal</Link></>}</td>
              <td>{!r.reversed_at && <button className="btn" onClick={() => startCorrection('refund', r.id)} data-testid={`billing-dr-correct-${r.id}`}>Correct</button>}</td>
            </tr>)}</tbody>
          </table></div>}
        </>}
        {correcting && <div style={{ marginTop: 20, borderTop: '1px solid var(--cf-border, #e5e7eb)', paddingTop: 16 }}>
          <h4 style={{ marginTop: 0 }}>Correct {correcting.kind}</h4>
          <p style={{ fontSize: 13, color: 'var(--cf-text-secondary)' }}>Use this only for an erroneous record. An actual returned payment must be recorded as a new receipt.</p>
          <Field label="Reason"><textarea className="input" rows={2} maxLength={500} value={reason} onChange={e => setReason(e.target.value)} data-testid="billing-deposit-correction-reason" /></Field>
          {error && <p className="error" data-testid="billing-deposit-correction-error">Error: {error.message}</p>}
          <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 12 }}>
            <button className="btn" onClick={() => setCorrecting(null)} disabled={busy}>Keep record</button>
            <button className="btn btn--primary" onClick={submitCorrection} disabled={busy || !reason.trim()} data-testid="billing-deposit-correction-submit">{busy ? 'Correcting…' : 'Reverse record'}</button>
          </div>
        </div>}
        <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 20 }}>
          <button className="btn btn--ghost" onClick={onClose} disabled={busy}>Close</button>
        </div>
      </div>
    </div>
  );
}

function CorrectPaymentModal({ payment, onClose, onSaved }) {
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const submit = async () => {
    setBusy(true); setError(null);
    try {
      await api.post(`/api/v1/billing/payments?action=correct&id=${payment.id}`, { reason });
      onSaved();
    } catch (e) { setError(e); }
    finally { setBusy(false); }
  };
  return (
    <div data-testid="billing-correct-payment-modal" style={{ position: 'fixed', inset: 0, background: 'rgba(15,18,28,0.5)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 16 }} onClick={e => e.target === e.currentTarget && !busy && onClose()}>
      <div style={{ background: 'var(--cf-surface, #fff)', borderRadius: 8, width: 'min(480px, 100%)', padding: 24 }}>
        <h3 style={{ marginTop: 0 }}>Correct receipt RCP-{payment.id}</h3>
        <p style={{ fontSize: 13, color: 'var(--cf-text-secondary)' }}>This reverses the ledger entry, restores the invoice balances, and reopens a matched bank line. It does not refund cash.</p>
        <Field label="Reason"><textarea className="input" value={reason} onChange={e => setReason(e.target.value)} maxLength={500} rows={3} data-testid="billing-correct-payment-reason" /></Field>
        {error && <p className="error" data-testid="billing-correct-payment-error">{error.message}</p>}
        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 16 }}>
          <button className="btn btn--ghost" onClick={onClose} disabled={busy}>Cancel</button>
          <button className="btn btn--primary" onClick={submit} disabled={busy || !reason.trim()} data-testid="billing-correct-payment-confirm">{busy ? 'Correcting…' : 'Reverse receipt'}</button>
        </div>
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
