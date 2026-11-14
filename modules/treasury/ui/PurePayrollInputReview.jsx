import React, { useEffect, useRef, useState } from 'react';
import { api, getPinnedTenantId } from '../../../dashboard/src/lib/api';
import PurePayrollResults from './PurePayrollResults';
import PurePayrollReservations from './PurePayrollReservations';

export default function PurePayrollInputReview(props) {
  return <InputReview key={String(props.tenantId)} {...props} />;
}

function InputReview({ canSend = false, tenantId }) {
  const [person, setPerson] = useState('');
  const [start, setStart] = useState('');
  const [end, setEnd] = useState('');
  const [candidates, setCandidates] = useState(null);
  const [selected, setSelected] = useState([]);
  const [review, setReview] = useState(null);
  const [confirmed, setConfirmed] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [history, setHistory] = useState(null);
  const personNumber = person.trim().toUpperCase();
  const live = useRef(true);
  const epoch = useRef(0);
  const pending = useRef(null);
  useEffect(() => { live.current = true; return () => { live.current = false; }; }, []);
  const sameCompany = () => Number(tenantId) > 0 && (!getPinnedTenantId() || String(getPinnedTenantId()) === String(tenantId));
  const current = token => live.current && token === epoch.current;
  const post = async (token, action, body) => {
    if (!sameCompany()) throw new Error('The company changed. Reopen payroll inputs in the intended company.');
    const result = await api.post(`/api/purepay_core.php?action=payroll_input_${action}`, body, { headers: { 'X-CoreFlux-Tenant-Id': String(tenantId) } });
    if (!current(token)) throw new Error('This review is no longer current.');
    if (!sameCompany()) throw new Error('The company changed. Reopen payroll inputs in the intended company.');
    if (['candidates', 'preview', 'history'].includes(action) && Number(result.tenant_id) !== Number(tenantId)) throw new Error('The response belongs to another company.');
    return result;
  };
  const run = async fn => {
    if (pending.current !== null) return;
    const token = ++epoch.current;
    pending.current = token; setBusy(true); setError('');
    try { await fn(token); }
    catch (e) {
      if (current(token)) {
        setError(e.message || 'Could not complete payroll input review.');
        if (!sameCompany()) { setReview(null); setCandidates(null); setSelected([]); setHistory(null); setConfirmed(false); }
      }
    } finally { if (current(token)) { pending.current = null; setBusy(false); } }
  };
  const clearReview = () => { setReview(null); setConfirmed(false); };
  const change = (setter, value) => {
    epoch.current++; pending.current = null; setBusy(false); setter(value);
    clearReview(); setCandidates(null); setSelected([]); setHistory(null); setError('');
  };
  const findTime = after => run(async token => {
    clearReview();
    if (!after) { setCandidates(null); setSelected([]); setHistory(null); }
    const result = await post(token, 'candidates', { person_number: personNumber, period_start: start, period_end: end, after });
    if (result.period_start !== start || result.period_end !== end || result.person.number !== personNumber) throw new Error('The time list belongs to another person or period.');
    setCandidates(previous => ({ ...result, rows: after ? [...new Map([...(previous?.rows || []), ...result.rows].map(row => [row.id, row])).values()] : result.rows }));
  });
  const select = (id, checked) => {
    if (busy || !candidates?.rows.some(row => row.id === id && row.available)) return;
    clearReview();
    setSelected(previous => checked ? [...new Set([...previous, id])].slice(0, 200) : previous.filter(value => value !== id));
  };
  const preview = () => run(async token => {
    clearReview();
    const ids = [...selected].sort((a, b) => a - b);
    const result = await post(token, 'preview', { person_number: personNumber, period_start: start, period_end: end, entry_ids: ids });
    if (result.person.number !== personNumber || result.packet.period_start !== start || result.packet.period_end !== end
      || JSON.stringify(result.selected_entry_ids) !== JSON.stringify(ids)) throw new Error('The input review does not match the selected time.');
    setReview(result);
  });
  const loadHistory = before => run(async token => {
    setHistory(await post(token, 'history', { person_number: personNumber, before }));
  });
  const receipt = action => run(async token => {
    const original = review.delivery;
    setConfirmed(false);
    try {
      const delivery = await post(token, action, { delivery_id: Number(original.id), review_hash: original.review_hash, confirmed });
      if (Number(delivery.id) !== Number(original.id) || delivery.review_hash !== original.review_hash) throw new Error('The receipt belongs to another input review.');
      setReview(previous => ({ ...previous, delivery }));
    } catch (e) {
      // A lost response may follow a committed send. Require receipt recovery, never automatic resubmission.
      if (action === 'send' && current(token) && sameCompany()) setReview(previous => ({ ...previous, delivery: { ...original, state: 'uncertain' } }));
      throw e;
    }
  });
  return <section style={{ marginTop: 20, borderTop: '1px solid var(--cf-border, #ddd)', paddingTop: 16 }} aria-label="PurePayroll input review">
    <h4>Send approved time to PurePayroll review</h4>
    <p>Choose approved time across one person’s W-2 placements within the actual payroll period. PurePayroll receives the original approved rates and source references. Receiving these inputs does not create payroll or reserve hours.</p>
    <form onSubmit={e => { e.preventDefault(); return findTime(null); }} style={{ display: 'flex', gap: 12, flexWrap: 'wrap', alignItems: 'end' }}>
      <label>Person number<input className="input" aria-label="Person number" value={person} placeholder="P-123" disabled={busy} onChange={e => change(setPerson, e.target.value)} /></label>
      <label>Period start<input className="input" aria-label="Period start" type="date" value={start} disabled={busy} onChange={e => change(setStart, e.target.value)} /></label>
      <label>Period end<input className="input" aria-label="Period end" type="date" value={end} disabled={busy} onChange={e => change(setEnd, e.target.value)} /></label>
      <button className="btn" disabled={busy || !canSend || !person.trim() || !start || !end}>Find approved time</button>
      <button className="btn" type="button" disabled={busy || !person.trim()} onClick={() => loadHistory(null)}>Input history</button>
    </form>
    {error && <p className="error" role="alert">{error}</p>}
    {candidates && <div>
      <p>{selected.length} entries selected. Keep the full payroll period; select only the time to include. Eligibility is rechecked when reviewing and sending.</p>
      {!candidates.rows.length && <p>No approved time was found for this person and period.</p>}
      <div style={{ overflowX: 'auto' }}><table className="data-table"><thead><tr><th>Include</th><th>Time / date</th><th>Placement</th><th>Hours</th><th>Availability</th></tr></thead><tbody>
        {candidates.rows.map(row => <tr key={row.id}><td><input type="checkbox" aria-label={`Include time ${row.id}`} checked={selected.includes(row.id)} disabled={busy || !canSend || !row.available || (!selected.includes(row.id) && selected.length >= 200)} onChange={e => select(row.id, e.target.checked)} /></td><td>{row.id} · {row.work_date}<br />{row.category}</td><td>{row.placement_number || 'Needs review'}</td><td>{row.hours}</td><td>{row.available ? 'Available for review' : row.reason}</td></tr>)}
      </tbody></table></div>
      {candidates.next_after && <button className="btn" disabled={busy} onClick={() => findTime(candidates.next_after)}>More approved time</button>}
      <button className="btn" disabled={busy || !canSend || !selected.length} onClick={preview}>Review selected time</button>
    </div>}
    {history && <div><p>{history.items.length} input reviews on this page.</p>{history.items.map(item => <button className="btn" disabled={busy} key={item.delivery.id} onClick={() => {
      if (Number(item.tenant_id) !== Number(tenantId) || !sameCompany()) { setError('Reopen this input in its original company.'); return; }
      setReview(item); setConfirmed(false); setCandidates(null); setSelected([]);
    }}>
      {item.packet.period_start} → {item.packet.period_end} · {item.delivery.state === 'delivered' ? 'Received for review' : item.delivery.state} · Review {item.delivery.id}
    </button>)}{history.next_before && <button className="btn" disabled={busy} onClick={() => loadHistory(history.next_before)}>Older input reviews</button>}</div>}
    {review && <div>
      <p>Provider company: <strong>{review.organization.name}</strong> · {review.person.number} · {review.packet.period_start} → {review.packet.period_end}</p>
      <p>{review.selected_entry_ids ? `${review.selected_entry_ids.length} explicitly selected time entries.` : 'Saved review of all approved time in this period.'}</p>
      <div style={{ overflowX: 'auto' }}><table className="data-table"><thead><tr><th>Placement</th><th>Provider assignment</th><th>Time / date</th><th>Hours</th><th>Frozen rate</th></tr></thead><tbody>
        {review.packet.components.map(c => <tr key={c.source.time_entry_id}><td>{c.placement.number}</td><td>{c.assignment_id}</td><td>{c.source.time_entry_id} · {c.source.work_date}<br />{c.source.category}</td><td>{c.source.hours}</td><td>{c.rate.currency} {c.rate.pay_rate} / {c.rate.pay_rate_unit} × {c.rate.multiplier}<br />Snapshot {c.rate.snapshot_id}</td></tr>)}
      </tbody></table></div>
      {review.delivery.state === 'delivered' ? <><p role="status">Inputs received for review in PurePayroll. This receipt describes intake only; check payroll results for later run status. Receipt: {review.delivery.remote_receipt_id}</p><PurePayrollReservations key={`reservation-${tenantId}-${review.delivery.id}`} delivery={review.delivery} tenantId={tenantId} /><PurePayrollResults key={review.delivery.id} delivery={review.delivery} /></>
        : ['sending', 'uncertain'].includes(review.delivery.state) ? <><p role="status">This attempt needs a confirmed receipt. Checking will not resend it.</p><button className="btn" disabled={busy} onClick={() => receipt('reconcile')}>Check input receipt</button></>
          : <><p>These hours remain available to existing payroll processes until a source reservation is confirmed.</p>
            {!review.delivery_enabled && <p>Payroll input delivery is not enabled for this environment.</p>}
            <label><input type="checkbox" aria-label="Confirm payroll input review" checked={confirmed} disabled={busy || !canSend || !review.delivery_enabled} onChange={e => setConfirmed(e.target.checked)} /> I reviewed the person, placements, period, selected hours and frozen rates.</label>
            <button className="btn btn--primary" disabled={busy || !canSend || !confirmed || !review.delivery_enabled || !['preview', 'approved'].includes(review.delivery.state)} onClick={() => receipt('send')}>Send for input review</button></>}
    </div>}
  </section>;
}
