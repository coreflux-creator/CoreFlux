import React, { useState } from 'react';
import { api } from '../../../dashboard/src/lib/api';

const money = value => new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(value / 100);

export default function PurePayBillLink({ billId }) {
  const [open, setOpen] = useState(false);
  const [saved, setSaved] = useState(null);
  const [remoteId, setRemoteId] = useState('');
  const [review, setReview] = useState(null);
  const [confirmed, setConfirmed] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const url = `/api/purepay_bills.php?bill_id=${billId}`;

  const run = async action => {
    setBusy(true); setError('');
    try { await action(); } catch (e) { setError(e.message); } finally { setBusy(false); }
  };
  const load = async () => {
    const data = await api.get(url);
    setSaved(data); setRemoteId(data.provider_bill_id || '');
  };
  const start = () => {
    setOpen(true); setReview(null); setConfirmed(false);
    run(load);
  };
  const preview = event => {
    event.preventDefault(); setReview(null); setConfirmed(false);
    run(async () => setReview(await api.post(`${url}&action=review`, { provider_bill_id: remoteId.trim() })));
  };
  const save = () => run(async () => {
    await api.post(`${url}&action=confirm`, {
      provider_bill_id: review.snapshot.provider.bill_id, review_hash: review.review_hash, confirmed,
    });
    setReview(null); setConfirmed(false); await load();
  });

  return <section className="card" style={{ padding: 16, marginBottom: 16 }} aria-label="PurePay bill link">
    <h3 style={{ marginTop: 0 }}>PurePay bill link</h3>
    <p>Identify the same supplier invoice in both systems. Saving a link does not reserve funds, approve a bill or send a payment.</p>
    {!open ? <button type="button" className="btn btn--ghost" onClick={start}>Review bill link</button> : <>
      {saved?.linked && <p role="status">
        Linked to <strong>{saved.provider_bill_id}</strong> in {saved.organization.name}. Reviewed {saved.reviewed_at}.
        {!saved.local_review_current && ' The Core record or connection has changed; review the link again.'}
        {' '}This is a saved identity review; current PurePay balances have not been verified.
      </p>}
      <form onSubmit={preview}>
        <label htmlFor={`purepay-bill-${billId}`}>PurePay bill ID</label>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginTop: 6 }}>
          <input id={`purepay-bill-${billId}`} className="input" required maxLength={120}
            value={remoteId} disabled={busy || saved?.linked} placeholder="Exact bill ID from PurePay"
            onChange={event => { setRemoteId(event.target.value); setReview(null); setConfirmed(false); }} />
          <button className="btn btn--ghost" disabled={busy || !remoteId.trim()}>{busy ? 'Checking…' : 'Compare bills'}</button>
        </div>
      </form>
      {review && <div style={{ marginTop: 16 }}>
        <p>PurePay company: <strong>{review.snapshot.organization.name}</strong> ({review.snapshot.organization.id})</p>
        <p>Core legal entity: <strong>{review.snapshot.core.entity_name}</strong></p>
        <div style={{ overflowX: 'auto' }}><table className="table" style={{ width: '100%' }}>
          <thead><tr><th>Field</th><th>Core</th><th>PurePay</th></tr></thead>
          <tbody>
            <tr><th>Bill</th><td>{review.snapshot.core.reference}</td><td>{review.snapshot.provider.bill_id}</td></tr>
            {[
              ['Vendor ID', 'vendor_id'], ['Vendor', 'vendor_name'], ['Remit email', 'vendor_email'],
              ['Invoice number', 'invoice_number'], ['Bill date', 'bill_date'], ['Due date', 'due_date'],
              ['Currency', 'currency'], ['Status', 'status'],
            ].map(([label, field]) => <tr key={field}><th>{label}</th><td>{review.snapshot.core[field]}</td><td>{review.snapshot.provider[field]}</td></tr>)}
            <tr><th>Original total</th><td>{money(review.snapshot.core.total_cents)}</td><td>{money(review.snapshot.provider.total_cents)}</td></tr>
          </tbody>
        </table></div>
        <label style={{ display: 'flex', gap: 8, alignItems: 'flex-start', margin: '12px 0' }}>
          <input type="checkbox" checked={confirmed} disabled={busy} onChange={event => setConfirmed(event.target.checked)} />
          These are the same supplier invoice for the displayed Core legal entity. The link cannot be reassigned here.
        </label>
        <button type="button" className="btn btn--primary" onClick={save} disabled={busy || !confirmed}>Save reviewed link</button>
      </div>}
      {error && <p className="error" role="alert">{error}</p>}
    </>}
  </section>;
}
