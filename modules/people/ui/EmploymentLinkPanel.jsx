import React, { useCallback, useEffect, useRef, useState } from 'react';
import { api, getPinnedTenantId } from '../../../dashboard/src/lib/api';

const endpoint = '/modules/people/api/employment_link.php';
const employeeLabel = e => `${e.employee_number} · ${e.legal_first_name} ${e.legal_last_name}${e.work_email ? ` · ${e.work_email}` : ''} · ${e.status}`;

export default function EmploymentLinkPanel({ personId }) {
  const tenantId = getPinnedTenantId();
  return <EmploymentLinkEditor key={`${tenantId}:${personId}`} tenantId={tenantId} personId={personId} />;
}

function EmploymentLinkEditor({ tenantId, personId }) {
  const [context, setContext] = useState(null);
  const [query, setQuery] = useState('');
  const [selected, setSelected] = useState('');
  const [reason, setReason] = useState('');
  const [review, setReview] = useState(null);
  const [confirmed, setConfirmed] = useState(false);
  const [pending, setPending] = useState(null);
  const [receipt, setReceipt] = useState(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const active = useRef(false);
  const inFlight = useRef(false);
  const abort = useRef(null);
  const current = useCallback(() => active.current && tenantId && String(getPinnedTenantId()) === String(tenantId), [tenantId]);
  const run = useCallback(async work => {
    if (inFlight.current) return;
    if (!current()) { setContext(null); setReview(null); setError('Company changed. Reopen this person to continue.'); return; }
    inFlight.current = true; setBusy(true); setError(null);
    const options = { headers: { 'X-CoreFlux-Tenant-Id': String(tenantId) }, fetch: { signal: abort.current.signal } };
    try { await work(options); }
    catch (e) { if (current()) setError(e.message); }
    finally { inFlight.current = false; if (current()) setBusy(false); }
  }, [current, tenantId]);
  const load = useCallback((search = '') => run(async options => {
    const data = await api.get(`${endpoint}?person_id=${encodeURIComponent(personId)}&q=${encodeURIComponent(search)}`, options);
    if (current()) { setContext(data); setSelected(''); }
  }), [current, personId, run]);

  useEffect(() => {
    active.current = true; abort.current = new AbortController(); load();
    return () => { active.current = false; abort.current.abort(); };
  }, [load]);

  const preview = () => run(async options => {
    const data = await api.post(`${endpoint}?action=preview`, {
      person_id: Number(personId), employee_id: selected === 'new' ? null : Number(selected), reason: reason.trim(),
    }, options);
    if (current()) { setReview(data); setConfirmed(false); }
  });
  const apply = () => run(async options => {
    const body = pending || { review_token: review.review_token, confirm_link: true };
    setPending(body);
    try {
      const data = await api.post(`${endpoint}?action=apply`, body, options);
      if (current()) { setReceipt(data.link); setPending(null); setReview(null); }
    } catch (e) {
      if (current() && [400, 401, 403, 409, 422].includes(e.status)) { setPending(null); setReview(null); setConfirmed(false); }
      throw e;
    }
  });
  const frozen = busy || !!pending || !!review;
  return <section aria-label="Employment record" style={{ marginTop: 24, paddingTop: 16, borderTop: '1px solid #ddd' }}>
    <h3>Employment record</h3>
    <p>A saved link keeps this person connected to the correct employment record when names or contact details change.</p>
    {error && <p role="alert" className="error">{error}</p>}
    {receipt ? <p role="status">Employment link saved for {context?.person?.person_number}. <a href={`/modules/people/employment/${receipt.employee_id}`}>Open employment record</a></p>
      : context?.link ? <><p>{context.person.person_number} is linked to {employeeLabel(context.employee)}.</p><a href={`/modules/people/employment/${context.employee.id}`}>Open employment record</a><p>Reason: {context.link.reason}</p></>
        : context && !context.available ? <p>Employment linking is awaiting installation.</p>
          : context && !context.can_manage ? <p>No employment link is saved. Ask a People administrator to review this person.</p>
            : context ? <>
              <p>No employment link is saved for {context.person.person_number}. Choose the existing record after checking its identity, or create a pending record.</p>
              <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                <label>Find an employment record <input aria-label="Find an employment record" value={query} maxLength={120} disabled={frozen} onChange={e => setQuery(e.target.value)} /></label>
                <button className="btn" disabled={frozen} onClick={() => load(query)}>Search employment records</button>
              </div>
              {!context.choices_complete && <p>Showing up to 500 records. Search by number, name or email to narrow the list.</p>}
              <label>Employment record <select aria-label="Employment record" value={selected} disabled={frozen} onChange={e => setSelected(e.target.value)}>
                <option value="">Choose a record</option>
                {(context.choices || []).map(e => <option key={e.id} value={String(e.id)}>{employeeLabel(e)}</option>)}
                <option value="new">Create a new pending employment record</option>
              </select></label>
              <label style={{ display: 'block', marginTop: 12 }}>Reason for this link <textarea aria-label="Reason for this link" value={reason} maxLength={500} disabled={frozen} onChange={e => setReason(e.target.value)} /></label>
              <button className="btn" disabled={frozen || !selected || !reason.trim()} onClick={preview}>Review employment link</button>
              {review && <div style={{ marginTop: 16 }}>
                <p><strong>{review.person.person_number} · {review.person.first_name} {review.person.last_name}</strong>{review.person.email_primary ? ` · ${review.person.email_primary}` : ''}</p>
                <p>{review.creates_employee ? 'Create a pending employment record. Employment type and overtime classification will need review before payroll setup.' : `Link to ${employeeLabel(review.employee)}.`}</p>
                <p>Reason: {review.reason}</p>
                <label><input type="checkbox" checked={confirmed} disabled={busy || !!pending} onChange={e => setConfirmed(e.target.checked)} /> I checked that these records identify the same person.</label>
                <div style={{ display: 'flex', gap: 8, marginTop: 12 }}>
                  <button className="btn btn--primary" disabled={busy || !confirmed} onClick={apply}>{pending ? 'Check original link' : 'Save employment link'}</button>
                  <button className="btn" disabled={busy || !!pending} onClick={() => { setReview(null); setConfirmed(false); }}>Change selection</button>
                </div>
                {pending && <p>The result has not been confirmed. Check the original link to safely recover its saved result.</p>}
              </div>}
            </> : <p>{busy ? 'Loading employment record…' : 'Choose a company and reopen this person.'}</p>}
  </section>;
}
