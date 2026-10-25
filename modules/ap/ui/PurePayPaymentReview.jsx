import React, { useEffect, useState } from 'react';
import { api, getPinnedTenantId } from '../../../dashboard/src/lib/api';

const money = cents => new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(cents / 100);
const stateLabels = {
  unreserved: 'Waiting for reservation', held: 'Reserved; not paid', released: 'Cancelled before payment',
  executing: 'Checking payment attempt', submitted: 'Payment in progress', completed: 'Payment result received',
  needs_review: 'Needs review', prepared: 'Ready to continue', settled: 'Settled', cancelled: 'Cancelled',
  requested: 'Requested', resume: 'Resume payment', release: 'Cancel unexecuted bills', reconcile: 'Refresh results',
};
const label = value => stateLabels[value] || String(value || 'Unknown').replaceAll('_', ' ');

export default function PurePayPaymentReview({ paymentId, onClose, onChanged }) {
  const [review, setReview] = useState(null);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const [reason, setReason] = useState('');
  const [confirmAction, setConfirmAction] = useState(null);
  const [notice, setNotice] = useState('');
  const [tenantId] = useState(getPinnedTenantId);
  const path = `/modules/ap/api/payments.php?id=${paymentId}`;
  useEffect(() => {
    let active = true;
    api.get(`${path}&action=purepay_review`, tenantId ? { headers: { 'X-CoreFlux-Tenant-Id': tenantId } } : {}).then(data => { if (active) setReview(data.review); })
      .catch(e => { if (active) setError(e.message); });
    return () => { active = false; };
  }, [path, tenantId]);
  async function act(action) {
    const pinned = getPinnedTenantId();
    if (pinned && String(pinned) !== String(review.tenant_id)) {
      setError('The company changed. Close this review and reopen the payment in the intended company.'); return;
    }
    const options = { headers: { 'X-CoreFlux-Tenant-Id': String(review.tenant_id) } };
    setBusy(true); setError(''); setNotice('');
    try {
      const result = await api.post(`${path}&action=purepay_${action}`, {
        review_hash: review.review_hash,
        reason: action === 'reconcile' ? 'Operator refreshed original payment results.' : reason.trim(),
      }, options);
      setReview(result.review); setConfirmAction(null); setReason('');
      setNotice(result.review.operation?.outcome === 'needs_review'
        ? 'Some results still need review. Keep the payment on hold until they are resolved.'
        : action === 'release' ? 'Cancellation recorded. Review the bill results below before voiding the Core payment.'
          : action === 'resume' ? 'The original request was resumed. Review each bill’s result below.' : 'Saved results refreshed from PurePay.');
      onChanged?.();
    } catch (e) {
      setError(`${e.message} Reload the review before deciding what to do next.`);
      setConfirmAction(null);
      // A lost response may follow a committed action. Never automatically retry it.
      try { const result = await api.get(`${path}&action=purepay_review`, options); setReview(result.review); } catch { /* Keep the displayed evidence. */ }
    } finally { setBusy(false); }
  }
  return <div style={overlay}>
    <section role="dialog" aria-modal="true" aria-label="PurePay payment review" style={box}>
      <header style={header}>
        <div><h2 style={{ margin: 0 }}>PurePay payment review</h2><p style={{ marginBottom: 0 }}>Original supplier bills and delivery history</p></div>
        <button type="button" className="btn btn--ghost" onClick={onClose} disabled={busy} aria-label="Close payment review">Close</button>
      </header>
      <div style={{ padding: 24 }}>
        {error && <p role="alert" className="error">{error}</p>}
        {notice && <p role="status">{notice}</p>}
        {!review && !error && <p>Loading payment results…</p>}
        {review && <>
          <p>{review.company_name}</p>
          <p><strong>{review.vendor_name} · {money(review.amount_cents)}</strong> · {label(review.status)}</p>
          <p>These are saved results. Refresh to check PurePay for updates. Refreshing does not start or resend a payment.</p>
          {review.reason && <p>{review.reason}</p>}
          {review.release_requested && <p><strong>Cancellation requested.</strong> This payment cannot resume. An attempt already in progress must be reconciled.</p>}
          <div style={{ overflowX: 'auto' }}><table className="table" style={{ width: '100%' }}>
            <thead><tr><th>Supplier bill</th><th>Amount</th><th>Delivery</th><th>Last checked</th></tr></thead>
            <tbody>{review.bills.map(bill => <tr key={bill.bill_id}>
              <td>{bill.invoice_number || `Bill ${bill.bill_id}`}</td><td>{money(bill.amount_cents)}</td>
              <td>{bill.needs_review ? 'Needs review' : label(bill.state)}
                {bill.payment_status !== 'unknown' && <div>{label(bill.payment_status)}</div>}</td>
              <td>{bill.state === 'unreserved' ? 'No confirmed result' : bill.checked_at || '—'}</td>
            </tr>)}</tbody>
          </table></div>
          {confirmAction ? <div style={{ border: '1px solid var(--cf-border, #ced7e0)', borderRadius: 8, padding: 16, marginTop: 20 }}>
            <h3>{confirmAction === 'resume' ? 'Resume the original payment?' : 'Cancel unexecuted bills?'}</h3>
            <p>{confirmAction === 'resume'
              ? `This can send up to ${money(review.amount_cents)} through PurePay using the original bill allocations. Completed attempts are not sent again.`
              : 'This permanently prevents this request from resuming. Bills already attempted stay held for review. The Core payment and accounting entries are not voided by this action.'}</p>
            <label htmlFor="purepay-operation-reason">Reason</label>
            <textarea id="purepay-operation-reason" value={reason} maxLength={500} disabled={busy}
              onChange={event => setReason(event.target.value)} style={{ width: '100%', minHeight: 80, margin: '8px 0' }} />
            <button type="button" className="btn btn--primary" disabled={busy || !reason.trim()} onClick={() => act(confirmAction)}>
              {busy ? 'Working…' : confirmAction === 'resume' ? 'Resume payment' : 'Confirm cancellation'}</button>{' '}
            <button type="button" className="btn btn--ghost" disabled={busy} onClick={() => { setConfirmAction(null); setReason(''); }}>Back</button>
          </div> : <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8, marginTop: 20 }}>
            <button type="button" className="btn btn--ghost" disabled={busy || !review.can_reconcile} onClick={() => act('reconcile')}>{busy ? 'Checking…' : 'Refresh results'}</button>
            {review.can_resume && <button type="button" className="btn btn--primary" disabled={busy} onClick={() => setConfirmAction('resume')}>Review resume</button>}
            {review.can_release && <button type="button" className="btn btn--ghost" disabled={busy} onClick={() => setConfirmAction('release')}>{review.release_requested ? 'Finish cancellation' : 'Cancel unexecuted bills'}</button>}
          </div>}
          {!!review.history.length && <details style={{ marginTop: 24 }}><summary>Operator history</summary>
            <ul>{review.history.map(event => <li key={`${event.operation_id}-${event.phase}`} style={{ margin: '8px 0' }}>
              {label(event.action)} · {label(event.phase)} · {event.recorded_at}<br />{event.reason}
            </li>)}</ul>
          </details>}
        </>}
      </div>
    </section>
  </div>;
}

const overlay = { position: 'fixed', inset: 0, zIndex: 1000, background: 'rgba(15,23,42,.4)', display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20 };
const box = { width: 900, maxWidth: '100%', maxHeight: '90vh', overflowY: 'auto', borderRadius: 12, background: 'var(--cf-surface, white)', boxShadow: '0 16px 60px #0003' };
const header = { display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 16, padding: 24, borderBottom: '1px solid var(--cf-border, #ced7e0)' };
