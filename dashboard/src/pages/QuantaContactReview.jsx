import React, { useEffect, useRef, useState } from 'react';
import { api, getPinnedTenantId } from '../lib/api';
import QuantaContactReturns from './QuantaContactReturns';

const labels = { first_name: 'First name', last_name: 'Last name', email_primary: 'Contact email', phone_primary: 'Phone' };
const endpoint = '/api/quanta.php?action=';
const stateLabel = value => ({ pending_review: 'Awaiting review', accepted: 'Accepted in Core', rejected: 'Rejected in Core', source_decided: 'Decision received by Quanta' })[value] || value;
const display = value => value == null || value === '' ? 'Empty' : value;

export default function QuantaContactReview({ workspaceId, people = [] }) {
  const [person, setPerson] = useState('');
  const [baseline, setBaseline] = useState(null);
  const [page, setPage] = useState(null);
  const [review, setReview] = useState(null);
  const [result, setResult] = useState(null);
  const [reason, setReason] = useState('');
  const [confirmed, setConfirmed] = useState(false);
  const [baselineConfirmed, setBaselineConfirmed] = useState(false);
  const [pending, setPending] = useState(null);
  const [baselinePending, setBaselinePending] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const alive = useRef(false), inFlight = useRef(false), sequence = useRef(0);
  useEffect(() => { alive.current = true; sequence.current++; return () => { alive.current = false; }; }, []);
  const sameWorkspace = () => /^[1-9][0-9]*$/.test(String(workspaceId || ''))
    && (!getPinnedTenantId() || String(getPinnedTenantId()) === String(workspaceId));
  const run = async (action, body, accept, failed) => {
    if (inFlight.current) return;
    if (!sameWorkspace()) { setError('The workspace changed. Refresh before continuing.'); return; }
    inFlight.current = true; const request = ++sequence.current; setBusy(true); setError('');
    try {
      const data = await api.post(endpoint + action, body, { timeoutMs: 120000, headers: { 'X-CoreFlux-Tenant-Id': String(workspaceId) } });
      if (alive.current && request === sequence.current && sameWorkspace()) accept(data);
    } catch (e) {
      if (alive.current && request === sequence.current && sameWorkspace()) { failed?.(); setError(e.message || 'The request could not be confirmed.'); }
    } finally { if (alive.current && request === sequence.current) { inFlight.current = false; setBusy(false); } }
  };
  const load = cursor => run('profile_review_list', { cursor: cursor || null }, data => setPage(previous => ({ ...data,
    rows: cursor && previous ? [...previous.rows, ...data.rows.filter(row => !previous.rows.some(old => old.change_id === row.change_id))] : data.rows })));
  const open = row => {
    setReview(null); setResult(null); setReason(''); setConfirmed(false);
    run('profile_review_preview', { person_id: row.person_id, change_id: row.change_id }, setReview);
  };
  const decide = (decision, retry) => {
    const request = retry || { review_token: review.review_token, decision, reason: reason.trim(), confirm_decision: true };
    setPending(request); setConfirmed(false);
    run('profile_review_apply', request, data => { setResult(data); setPending(null); });
  };
  const checkDecision = () => run('profile_review_status', { review_token: pending.review_token }, data => {
    if (data.state === 'decided') { setResult(data.result); setPending(null); }
    else setError('Core has no saved decision for this request. Retry the same decision. If its details changed, refresh the page and review the request again.');
  });
  const baselineBody = () => ({ delivery_id: Number(baseline.delivery.id), review_hash: baseline.delivery.review_hash });

  return <section style={{ marginTop: 28, paddingTop: 22, borderTop: '1px solid var(--cf-border)' }} aria-label="Contact change requests">
    <h2 style={{ fontSize: 18 }}>Contact change requests</h2>
    <p>Workers request name, contact email and phone changes in Quanta. Review the original details and current Core record before applying a change.</p>
    <details><summary>Share contact details for worker review</summary>
      <p>Share a current Core snapshot so a linked worker can request corrections. Sign-in credentials are managed separately.</p>
      <label>Core person <select aria-label="Core contact person" value={person} disabled={busy || baselinePending} onChange={e => { setPerson(e.target.value); setBaseline(null); setBaselineConfirmed(false); }}>
        <option value="">Choose a person</option>{people.map(p => <option key={p.id} value={p.id}>{p.person_number} · {p.name}</option>)}
      </select></label>{' '}
      <button type="button" className="btn btn-secondary" disabled={busy || !person || baselinePending} onClick={() => {
        setBaseline(null); setBaselineConfirmed(false); run('profile_baseline_preview', { person_id: Number(person) }, setBaseline);
      }}>Preview contact details</button>
      {baseline && <div><dl>{Object.entries(labels).map(([key, label]) => <div key={key}><dt>{label}</dt><dd>{display(baseline.profile[key])}</dd></div>)}</dl>
        {baseline.status === 'unchanged' && <p role="status">Quanta already has this Core snapshot.</p>}
        {baseline.delivery && <>
          <p role="status">{baseline.delivery.state === 'delivered' ? 'Contact snapshot confirmed by Quanta.' : 'Review these details before sharing.'}</p>
          {baseline.delivery_enabled && ['preview', 'approved'].includes(baseline.delivery.state) && !baselinePending && <>
            <label><input type="checkbox" disabled={busy} checked={baselineConfirmed} onChange={e => setBaselineConfirmed(e.target.checked)} /> I reviewed these contact details.</label>{' '}
            <button type="button" className="btn btn-primary" disabled={busy || !baselineConfirmed} onClick={() => {
              setBaselinePending(true); setBaselineConfirmed(false); run('profile_baseline_send', { ...baselineBody(), confirm_profile: true }, data => {
                setBaseline(value => ({ ...value, delivery: data })); setBaselinePending(false);
              });
            }}>Share contact snapshot</button>
          </>}
          {!baseline.delivery_enabled && <p>Contact publication is not enabled for this pilot.</p>}
          <button type="button" className="btn btn-secondary" disabled={busy} onClick={() => run('profile_baseline_status', baselineBody(), data => {
            setBaseline(value => ({ ...value, delivery: data.delivery })); setBaselinePending(['sending', 'uncertain'].includes(data.delivery.state));
            if (data.message) setError(data.message);
          })}>Check publication receipt</button>
        </>}
      </div>}
    </details>
    <button type="button" className="btn btn-secondary" disabled={busy || !!pending} onClick={() => load()}>Refresh contact requests</button>
    {error && <p role="alert">{error}</p>}
    {page && !page.rows.length && <p>No requests available for review on this page.</p>}
    {!!page?.unavailable_count && <p>{page.unavailable_count} request(s) need identity or source verification before they can be displayed.</p>}
    {page?.rows.map(row => <article key={row.change_id} style={{ padding: '12px 0', borderBottom: '1px solid var(--cf-border)' }}>
      <strong>{row.person_number} · {row.person_name}</strong><p>{row.changed_fields.map(key => labels[key]).join(', ')} · {stateLabel(row.state)} · {row.created_at}</p>
      <button type="button" className="btn btn-secondary" disabled={busy || !!pending || row.state !== 'pending_review'} onClick={() => open(row)}>Review request</button>
    </article>)}
    {page?.next_cursor && <button type="button" className="btn btn-secondary" disabled={busy || !!pending} onClick={() => load(page.next_cursor)}>More requests</button>}
    {review && <div style={{ marginTop: 20 }}>
      <h3>Review contact changes · {review.reference.number}</h3>
      <table className="table" style={{ width: '100%', textAlign: 'left' }}><thead><tr><th>Field</th><th>Originally shared</th><th>Worker requested</th><th>Core now</th></tr></thead>
        <tbody>{Object.entries(labels).map(([key, label]) => <tr key={key}><th>{label}{review.comparison.conflicts[key] && ' · Conflict'}</th>
          <td>{display(review.proposal.baseline.profile[key])}</td><td>{display(review.proposal.proposed[key])}</td><td>{display(review.current[key])}</td></tr>)}</tbody></table>
      {review.comparison.state === 'conflict' && <p role="alert">Core and the worker changed the same field. Review the discrepancy and reject this request with guidance for a new request.</p>}
      {result ? <p role="status">{stateLabel(result.decision)}. {result.changed_fields.length ? 'Core contact details were updated.' : 'No Core contact values changed.'} The decision is awaiting return to Quanta.</p>
        : pending ? <div><p>The decision needs confirmation. Check its saved result before changing it.</p>
          <button type="button" className="btn btn-secondary" disabled={busy} onClick={checkDecision}>Check decision receipt</button>{' '}
          <button type="button" className="btn btn-secondary" disabled={busy} onClick={() => decide(null, pending)}>Retry same decision</button>
        </div> : <>
          {!review.apply_enabled && <p>Contact decisions are not enabled for this pilot.</p>}
          <label>Review note (required for rejection)<textarea value={reason} maxLength={1000} disabled={busy} onChange={e => setReason(e.target.value)} /></label>
          <label style={{ display: 'block' }}><input type="checkbox" checked={confirmed} disabled={busy} onChange={e => setConfirmed(e.target.checked)} /> I reviewed the requested changes against Core.</label>
          <button type="button" className="btn btn-primary" disabled={busy || !confirmed || !review.apply_enabled || review.comparison.state === 'conflict'} onClick={() => decide('accepted')}>Accept in Core</button>{' '}
          <button type="button" className="btn btn-secondary" disabled={busy || !confirmed || !review.apply_enabled || !reason.trim()} onClick={() => decide('rejected')}>Reject with note</button>
        </>}
    </div>}
    <QuantaContactReturns key={workspaceId} workspaceId={workspaceId} />
  </section>;
}
