import React, { useState } from 'react';
import { api } from '../../../dashboard/src/lib/api';

export default function PurePeopleInputReview({ canSend = false }) {
  const [person, setPerson] = useState('');
  const [placement, setPlacement] = useState('');
  const [review, setReview] = useState(null);
  const [history, setHistory] = useState(null);
  const [confirmed, setConfirmed] = useState(false);
  const [attempted, setAttempted] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const run = async fn => { setBusy(true); setError(''); try { await fn(); } catch (e) { setError(e.message || 'Person review could not be completed.'); } finally { setBusy(false); } };
  const select = value => { setReview(value); setConfirmed(false); setAttempted(false); };
  const change = (setter, value) => { setter(value); select(null); setHistory(null); };
  const loadHistory = before => run(async () => {
    setHistory(await api.post('/api/purepay_core.php?action=people_input_history', { person_number: person.trim(), before }));
  });
  const send = () => run(async () => {
    setAttempted(true);
    const delivery = await api.post('/api/purepay_core.php?action=people_input_send', {
      delivery_id: Number(review.delivery.id), review_hash: review.delivery.review_hash, confirmed });
    setReview(value => ({ ...value, delivery })); setConfirmed(false);
  });
  const status = () => run(async () => {
    const result = await api.post('/api/purepay_core.php?action=people_input_status', {
      delivery_id: Number(review.delivery.id), review_hash: review.delivery.review_hash });
    setReview(value => ({ ...value, ...result })); setConfirmed(false);
  });
  return <section style={{ marginTop: 20, borderTop: '1px solid var(--cf-border, #ddd)', paddingTop: 16 }} aria-label="PurePayroll person review">
    <h4>Send a person to PurePayroll profile review</h4>
    <p>Choose a person and their W-2 placement. Send their name and contact details for review in PurePayroll, where an operator can create an inactive employee profile and complete payroll setup.</p>
    <form onSubmit={event => { event.preventDefault(); run(async () => {
      select(null);
      select(await api.post('/api/purepay_core.php?action=people_input_preview', { person_number: person.trim(), placement_number: placement.trim() }));
    }); }} style={{ display: 'flex', gap: 12, flexWrap: 'wrap', alignItems: 'end' }}>
      <label>Person number<input className="input" value={person} placeholder="P-123" disabled={busy} onChange={event => change(setPerson, event.target.value)} /></label>
      <label>W-2 placement number<input className="input" value={placement} placeholder="PL-123" disabled={busy} onChange={event => change(setPlacement, event.target.value)} /></label>
      <button className="btn" disabled={busy || !canSend || !person.trim() || !placement.trim()}>Review person</button>
      <button type="button" className="btn" disabled={busy || !person.trim()} onClick={() => loadHistory(null)}>Person review history</button>
    </form>
    {error && <p className="error" role="alert">{error}</p>}
    {history && <div>{history.items.map(item => <button type="button" className="btn" disabled={busy} key={item.delivery.id} onClick={() => select(item)}>
      {item.packet.person.number} · {item.placement.number} · {item.delivery.state}
    </button>)}{history.next_before && <button type="button" className="btn" disabled={busy} onClick={() => loadHistory(history.next_before)}>Next page</button>}</div>}
    {review && <div style={{ marginTop: 12 }}>
      <p><strong>{review.packet.first_name} {review.packet.last_name}</strong> · {review.packet.person.number} · {review.placement.number}</p>
      <p>{review.packet.email || 'No email supplied'} · {review.packet.phone || 'No phone supplied'}</p>
      <p>Destination: <strong>{review.organization.name}</strong> · {review.delivery.state}</p>
      {!review.delivery_enabled && <p>People input delivery is not enabled in this environment.</p>}
      {['preview', 'approved'].includes(review.delivery.state) && !attempted && <>
        <label><input type="checkbox" disabled={busy} checked={confirmed} onChange={event => setConfirmed(event.target.checked)} /> I reviewed this person, W-2 placement and destination company.</label>
        <button type="button" className="btn" disabled={busy || !confirmed || !canSend || !review.delivery_enabled} onClick={send}>Send for profile review</button>
      </>}
      {(attempted || ['sending', 'uncertain', 'delivered'].includes(review.delivery.state)) && <button type="button" className="btn" disabled={busy} onClick={status}>Check provider receipt and profile</button>}
      {review.provider_review && <p role="status">{review.provider_review.state === 'created'
        ? `Employee profile confirmed: ${review.provider_review.employee_id}. Refresh provider records and review its Core link before sending payroll time.`
        : review.provider_review.state === 'recoverable' ? 'An inactive profile exists. Complete its identity link from People from Core in PurePayroll.'
          : review.provider_review.state === 'needs_review' ? 'An existing employee or identity link needs review in PurePayroll.'
            : 'Received in PurePayroll. Open People from Core to review the profile.'}</p>}
    </div>}
  </section>;
}
