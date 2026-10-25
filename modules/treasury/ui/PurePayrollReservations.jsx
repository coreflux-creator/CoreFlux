import React, { useEffect, useRef, useState } from 'react';
import { api, getPinnedTenantId } from '../../../dashboard/src/lib/api';

const labels = { available: 'Available for draft review', allocating: 'Draft preparation in progress', allocated: 'Allocated to a payroll draft', cancelling: 'Cancellation in progress', released: 'Cancellation confirmed' };
const requestId = () => Array.from(crypto.getRandomValues(new Uint8Array(32)), v => v.toString(16).padStart(2, '0')).join('');

export default function PurePayrollReservations({ delivery, tenantId }) {
  const [history, setHistory] = useState(null);
  const [receipt, setReceipt] = useState(null);
  const [confirmation, setConfirmation] = useState(null);
  const [confirmed, setConfirmed] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const pending = useRef(false);
  const retainedRequest = useRef(null);
  const live = useRef(true);
  useEffect(() => { live.current = true; return () => { live.current = false; }; }, []);
  const context = { delivery_id: Number(delivery.id), review_hash: delivery.review_hash };
  const sameCompany = () => Number(tenantId) > 0 && (!getPinnedTenantId() || String(getPinnedTenantId()) === String(tenantId));
  const post = (action, body = {}) => {
    if (!sameCompany()) throw new Error('The company changed. Reopen this input in the intended company.');
    return api.post(`/api/purepay_core.php?action=payroll_reservation_${action}`, { ...context, ...body }, { headers: { 'X-CoreFlux-Tenant-Id': String(tenantId) } });
  };
  const load = async before => {
    const result = await post('history', { before });
    if (Number(result.tenant_id) !== Number(tenantId) || Number(result.delivery_id) !== Number(delivery.id)) throw new Error('Reservation history belongs to another input.');
    if (live.current) setHistory(result);
  };
  const perform = async (action, authority = null) => {
    if (pending.current) return;
    pending.current = true; setBusy(true); setError('');
    try {
      if (!sameCompany()) throw new Error('The company changed. Reopen this input in the intended company.');
      if (action === 'history') await load(authority);
      else {
        if (action !== 'reconcile' && !confirmed) throw new Error('Confirm the reviewed reservation action first.');
        if (action === 'reserve' && !retainedRequest.current) retainedRequest.current = requestId();
        const result = await post(action, action === 'reserve'
          ? { request_id: retainedRequest.current, confirmed: true }
          : { authority_id: authority, confirmed: action !== 'reconcile' });
        if (action === 'release' && result.authority?.state === 'released') retainedRequest.current = null;
        if (live.current) { setReceipt(result.provider_receipt || null); setConfirmation(null); setConfirmed(false); }
        await load(null);
      }
    } catch (e) {
      if (live.current) { setError(`${e.message || 'Reservation action could not be confirmed.'} Check the original reservation before continuing.`); setConfirmation(null); setConfirmed(false); setReceipt(null); }
      // A response can be lost after commit. Recover local history; never repeat a mutation automatically.
      if (action !== 'history' && sameCompany()) { try { await load(null); } catch { /* Preserve the last known local history. */ } }
    } finally { pending.current = false; if (live.current) setBusy(false); }
  };
  const reviewAction = (action, authority = null) => { setConfirmation({ action, authority }); setConfirmed(false); setReceipt(null); };
  return <section aria-label="Payroll source reservations" style={{ marginTop: 16, borderTop: '1px solid var(--cf-border, #ddd)', paddingTop: 12 }}>
    <h4>Reserve source hours for a payroll draft</h4>
    <p>Reserve the approved hours before draft preparation in PurePayroll. While reserved, payroll source hours and pay rates cannot be changed or used twice. This does not approve or pay payroll.</p>
    <button className="btn" type="button" disabled={busy} onClick={() => perform('history')}>View reservations</button>
    {error && <p role="alert" className="error">{error}</p>}
    {history && <>
      <p>Provider company: <strong>{history.organization.name}</strong></p>
      {!history.changes_enabled && <p>Reservation changes are not enabled for this environment or connection. Existing receipts can still be checked.</p>}
      {!history.items.length && <p>No saved reservations on this page.</p>}
      {history.items.map((a, index) => <div key={a.id} style={{ marginTop: 12 }}>
        <p><strong>Reservation {index + 1}: {a.state === 'held' ? 'Hours reserved in Core' : 'Released in Core'}</strong> · {a.components} time entries · {a.created_at}</p>
        <p>Reference {a.id}. Provider status is unverified until you check its receipt.</p>
        <button className="btn" type="button" disabled={busy} onClick={() => perform('reconcile', a.id)}>Check provider receipt</button>
        {a.state === 'held' && history.changes_enabled && <>
          <button className="btn" type="button" disabled={busy} onClick={() => reviewAction('send', a.id)}>Review sharing reservation</button>
          {history.can_release && <button className="btn" type="button" disabled={busy} onClick={() => reviewAction('release', a.id)}>Review cancellation</button>}
        </>}
      </div>)}
      {history.next_before && <button className="btn" type="button" disabled={busy} onClick={() => perform('history', history.next_before)}>Older reservations</button>}
      {history.changes_enabled && history.has_held === false && <button className="btn" type="button" disabled={busy} onClick={() => reviewAction('reserve')}>Review reserving hours</button>}
    </>}
    {confirmation && <div role="group" aria-label="Confirm source reservation action">
      <p>{confirmation.action === 'reserve' ? 'Reserve the hours in this received input. Core will recheck current approvals, rates and source evidence before locking them.'
        : confirmation.action === 'send' ? 'Share this exact reservation with the reviewed PurePayroll company so its staff can prepare a held draft. Repeating this action reuses the original reservation.'
          : 'Cancel this reservation in PurePayroll. Any cancellable held draft will be voided. Core releases the hours only after verifying permanent cancellation; an unknown result keeps them reserved.'}</p>
      <label><input type="checkbox" checked={confirmed} disabled={busy} onChange={e => setConfirmed(e.target.checked)} /> I reviewed the company, input and reservation action.</label>
      <button className="btn btn--primary" type="button" disabled={busy || !confirmed} onClick={() => perform(confirmation.action, confirmation.authority)}>
        {confirmation.action === 'reserve' ? 'Reserve hours' : confirmation.action === 'send' ? 'Share reservation' : 'Confirm cancellation'}
      </button>
      <button className="btn" type="button" disabled={busy} onClick={() => { setConfirmation(null); setConfirmed(false); }}>Back</button>
    </div>}
    {receipt && <div role="status"><p>{labels[receipt.state] || receipt.state} · {receipt.payroll_created ? 'Provider reports a payroll draft/run exists.' : 'No payroll created by this reservation.'}</p>
      {receipt.allocation && <p>Run {receipt.allocation.run_id} · {receipt.allocation.run_state}</p>}
      <p>{receipt.release_confirmed ? 'Permanent provider cancellation confirmed. Check the local reservation above to confirm Core has released the hours.' : 'Source remains reserved. A provider receipt does not confirm payment or tax remittance.'}</p>
    </div>}
  </section>;
}
