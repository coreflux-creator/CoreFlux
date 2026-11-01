import React, { useEffect, useRef, useState } from 'react';
import { api, getPinnedTenantId } from '../../../dashboard/src/lib/api';

export default function PureCustomerInputReview({ tenantId, canCreate = false, canLink = false, onChooseExisting }) {
  const [number, setNumber] = useState('');
  const [review, setReview] = useState(null);
  const [confirmed, setConfirmed] = useState(false);
  const [attempted, setAttempted] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const pending = useRef(false);
  const live = useRef(true);
  useEffect(() => { live.current = true; return () => { live.current = false; }; }, []);
  const sameCompany = () => String(getPinnedTenantId()) === String(tenantId);
  const post = async (action, body) => {
    if (!sameCompany()) throw new Error('The company changed. Reopen this client in the intended company.');
    const result = await api.post(`/api/purepay_core.php?action=customer_input_${action}`, body, { headers: { 'X-CoreFlux-Tenant-Id': String(tenantId) } });
    if (!sameCompany()) throw new Error('The company changed. Reopen this client in the intended company.');
    return result;
  };
  const perform = async action => {
    if (pending.current) return;
    pending.current = true; setBusy(true); setError('');
    try {
      if (action === 'preview') {
        setReview(null); setConfirmed(false);
        const result = await post(action, { client_number: number.trim() });
        if (live.current) { setReview(result); setAttempted(false); }
      } else {
        if (action === 'send') {
          if (!confirmed || !canCreate || !review?.delivery_enabled || !review?.can_create) throw new Error('Review and confirm customer sharing first.');
          setAttempted(true);
        }
        const result = await post(action, { delivery_id: review.delivery.id, review_hash: review.delivery.review_hash, ...(action === 'send' ? { confirmed: true } : {}) });
        if (live.current) { setReview(previous => ({ ...previous, ...result })); setConfirmed(false); }
        // Refresh by immutable client number to show the current customer, not a replay snapshot.
        if (action === 'send' && result.delivery?.state === 'delivered') {
          const current = await post('preview', { client_number: review.source.reference.number });
          if (live.current) setReview(current);
        }
      }
    } catch (e) {
      if (live.current) { setError(e.message || 'The customer outcome could not be confirmed. Check the original request.'); setConfirmed(false); }
    } finally {
      pending.current = false; if (live.current) setBusy(false);
    }
  };
  const existing = review?.existing_customer;
  const state = review?.delivery?.state;
  const maySend = !existing && !attempted && ['preview', 'approved'].includes(state) && canCreate && review?.can_create && review?.delivery_enabled;
  const mayRecover = review?.delivery && (attempted || ['sending', 'uncertain', 'delivered'].includes(state));
  return <section className="card" style={{ marginTop: 16, padding: 16 }} data-testid="purepay-customer-input">
    <h4>Share a Core client with PurePay</h4>
    <p>Review the client’s name and contact details. Existing customer IDs and confirmed Core links are retained.</p>
    <form onSubmit={event => { event.preventDefault(); perform('preview'); }}>
      <label>Core client number<input className="input" value={number} placeholder="CL-123" disabled={busy} onChange={event => { setNumber(event.target.value); setReview(null); setConfirmed(false); setAttempted(false); setError(''); }} /></label>
      <button className="btn" disabled={busy || !number.trim()}>Review client</button>
    </form>
    {error && <p role="alert" className="error">{error}</p>}
    {review && <>
      <p>PurePay company: <strong>{review.organization.name}</strong></p>
      <p><strong>{review.source.reference.number}</strong> · {review.contact.name}<br />{review.contact.email || 'No contact email'} · {review.contact.phone || 'No contact phone'}</p>
      {existing ? <>
        <p>Existing PurePay customer: <strong>{existing.customer.name}</strong> · {existing.customer.id}</p>
        {existing.identity_basis === 'core_link' ? <p>Already linked to this Core client.</p> : <>
          <p>The customer is available. Review its Core link to use it in placements.</p>
          {canLink && onChooseExisting && <button className="btn" disabled={busy} onClick={() => {
            if (!sameCompany()) { setError('The company changed. Reopen this client in the intended company.'); return; }
            onChooseExisting(existing.customer.id, review.source.reference.number);
          }}>Review Core link</button>}
        </>}
      </> : <p>No matching customer is currently visible.</p>}
      {review.delivery && <p>Sharing status: {state}. Reference {review.delivery.id}.</p>}
      {maySend && <>
        <label><input type="checkbox" checked={confirmed} disabled={busy} onChange={event => setConfirmed(event.target.checked)} /> Create this customer in {review.organization.name} with the reviewed name and contact details.</label>
        <button className="btn btn--primary" disabled={busy || !confirmed} onClick={() => perform('send')}>Create customer in PurePay</button>
      </>}
      {!existing && !maySend && !mayRecover && <p>Customer creation is unavailable with the current access or environment setup.</p>}
      {mayRecover && <button className="btn" disabled={busy} onClick={() => perform('reconcile')}>Check customer result</button>}
      {['sending', 'uncertain'].includes(state) && <p>The original request may still complete. Check its result before making another request.</p>}
    </>}
  </section>;
}
