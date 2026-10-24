import React, { useState } from 'react';
import { api } from '../../../dashboard/src/lib/api';
import PurePayrollResults from './PurePayrollResults';

export default function PurePayrollInputReview({ canSend = false }) {
  const [person, setPerson] = useState('');
  const [start, setStart] = useState('');
  const [end, setEnd] = useState('');
  const [review, setReview] = useState(null);
  const [confirmed, setConfirmed] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [history, setHistory] = useState(null);
  const run = async fn => { setBusy(true); setError(''); try { await fn(); } catch (e) { setError(e.message || 'Could not complete payroll input review.'); } finally { setBusy(false); } };
  const change = (setter, value) => { setter(value); setReview(null); setConfirmed(false); setHistory(null); };
  const loadHistory = before => run(async () => {
    setHistory(await api.post('/api/purepay_core.php?action=payroll_input_history', { person_number: person.trim(), before }));
  });
  const receipt = action => run(async () => {
    const delivery = await api.post(`/api/purepay_core.php?action=payroll_input_${action}`, { delivery_id: Number(review.delivery.id), review_hash: review.delivery.review_hash, confirmed });
    setReview(previous => ({ ...previous, delivery })); setConfirmed(false);
  });
  return <section style={{ marginTop: 20, borderTop: '1px solid var(--cf-border, #ddd)', paddingTop: 16 }} aria-label="PurePayroll input review">
    <h4>Send approved time to PurePayroll review</h4>
    <p>Review one person’s approved time across their W-2 placements. PurePayroll receives the frozen placement rates and source references. Receiving these inputs does not create payroll or reserve hours.</p>
    <form onSubmit={e => { e.preventDefault(); run(async () => {
      setReview(null); setConfirmed(false);
      setReview(await api.post('/api/purepay_core.php?action=payroll_input_preview', { person_number: person.trim(), period_start: start, period_end: end }));
    }); }} style={{ display: 'flex', gap: 12, flexWrap: 'wrap', alignItems: 'end' }}>
      <label>Person number<input className="input" value={person} placeholder="P-123" disabled={busy} onChange={e => change(setPerson, e.target.value)} /></label>
      <label>Period start<input className="input" type="date" value={start} disabled={busy} onChange={e => change(setStart, e.target.value)} /></label>
      <label>Period end<input className="input" type="date" value={end} disabled={busy} onChange={e => change(setEnd, e.target.value)} /></label>
      <button className="btn" disabled={busy || !canSend || !person.trim() || !start || !end}>Review inputs</button>
      <button className="btn" type="button" disabled={busy || !person.trim()} onClick={() => loadHistory(null)}>Input history</button>
    </form>
    {error && <p className="error" role="alert">{error}</p>}
    {history && <div><p>{history.items.length} input reviews on this page.</p>{history.items.map(item => <button className="btn" disabled={busy} key={item.delivery.id} onClick={() => { setReview(item); setConfirmed(false); }}>
      {item.packet.period_start} → {item.packet.period_end} · {item.delivery.state === 'delivered' ? 'Received for review' : item.delivery.state} · Review {item.delivery.id}
    </button>)}{history.next_before && <button className="btn" disabled={busy} onClick={() => loadHistory(history.next_before)}>Older input reviews</button>}</div>}
    {review && <div>
      <p>Provider company: <strong>{review.organization.name}</strong> · {review.person.number} · {review.packet.period_start} → {review.packet.period_end}</p>
      <div style={{ overflowX: 'auto' }}><table className="data-table"><thead><tr><th>Placement</th><th>Provider assignment</th><th>Time / date</th><th>Hours</th><th>Frozen rate</th></tr></thead><tbody>
        {review.packet.components.map(c => <tr key={c.source.time_entry_id}><td>{c.placement.number}</td><td>{c.assignment_id}</td><td>{c.source.time_entry_id} · {c.source.work_date}<br />{c.source.category}</td><td>{c.source.hours}</td><td>{c.rate.currency} {c.rate.pay_rate} / {c.rate.pay_rate_unit} × {c.rate.multiplier}<br />Snapshot {c.rate.snapshot_id}</td></tr>)}
      </tbody></table></div>
      {review.delivery.state === 'delivered' ? <><p role="status">Inputs received for review in PurePayroll. This receipt describes intake only; check payroll results for later run status. Receipt: {review.delivery.remote_receipt_id}</p><PurePayrollResults key={review.delivery.id} delivery={review.delivery} /></>
        : ['sending', 'uncertain'].includes(review.delivery.state) ? <><p role="status">This attempt needs a confirmed receipt. Checking will not resend it.</p><button className="btn" disabled={busy} onClick={() => receipt('reconcile')}>Check input receipt</button></>
          : <><p>These hours remain available to existing payroll processes. Draft application must check current approvals and prevent duplicate use before any payroll is created.</p>
            {!review.delivery_enabled && <p>Payroll input delivery is not enabled for this environment.</p>}
            <label><input type="checkbox" checked={confirmed} disabled={busy || !canSend || !review.delivery_enabled} onChange={e => setConfirmed(e.target.checked)} /> I reviewed the person, placements, period, hours and frozen rates.</label>
            <button className="btn btn--primary" disabled={busy || !canSend || !confirmed || !review.delivery_enabled || !['preview', 'approved'].includes(review.delivery.state)} onClick={() => receipt('send')}>Send for input review</button></>}
    </div>}
  </section>;
}
