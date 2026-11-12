import React, { useCallback, useEffect, useRef, useState } from 'react';
import { api, getPinnedTenantId } from '../../../dashboard/src/lib/api';

const endpoint = '/modules/people/api/employment_setup.php';
const fields = { status: 'Employment status', employment_type: 'Employment type', flsa_class: 'Overtime classification',
  hire_date: 'Hire date', start_date: 'Employment start date', job_title: 'Job title', department: 'Department', location: 'Location' };
const choices = { status: { pending: 'Pending', active: 'Active' },
  employment_type: { full_time: 'Full time', part_time: 'Part time', contractor: 'Contractor', intern: 'Intern', temp: 'Temporary' },
  flsa_class: { exempt: 'Exempt', non_exempt: 'Non-exempt' } };
const display = (field, value) => choices[field]?.[value] || value || 'Not set';
const overlaps = ['employment_type', 'hire_date'];
const differs = (review, field) => review.before[field] !== review.after[field]
  || (overlaps.includes(field) && (review.person_before?.[field] ?? null) !== review.after[field]);

export default function EmploymentSetupPanel({ employeeId, onSaved }) {
  const tenantId = getPinnedTenantId();
  return <EmploymentSetupEditor key={`${tenantId}:${employeeId}`} tenantId={tenantId} employeeId={employeeId} onSaved={onSaved} />;
}

function EmploymentSetupEditor({ tenantId, employeeId, onSaved }) {
  const [context, setContext] = useState(null), [values, setValues] = useState({});
  const [reason, setReason] = useState(''), [review, setReview] = useState(null);
  const [confirmed, setConfirmed] = useState(false), [pending, setPending] = useState(null);
  const [receipt, setReceipt] = useState(null), [history, setHistory] = useState(null);
  const [busy, setBusy] = useState(false), [error, setError] = useState(null);
  const active = useRef(false), inFlight = useRef(false), abort = useRef(null);
  const current = useCallback(() => active.current && tenantId && String(getPinnedTenantId()) === String(tenantId), [tenantId]);
  const run = useCallback(async work => {
    if (inFlight.current) return;
    if (!current()) { setContext(null); setReview(null); setError('Company changed. Reopen the employment record to continue.'); return; }
    inFlight.current = true; setBusy(true); setError(null);
    const options = { headers: { 'X-CoreFlux-Tenant-Id': String(tenantId) }, fetch: { signal: abort.current.signal } };
    try { await work(options); } catch (e) { if (current()) setError(e.message); }
    finally { inFlight.current = false; if (current()) setBusy(false); }
  }, [current, tenantId]);
  const load = useCallback(() => run(async options => {
    const data = await api.get(`${endpoint}?employee_id=${encodeURIComponent(employeeId)}`, options);
    if (current()) { setContext(data); setValues(Object.fromEntries(Object.keys(fields).map(f => [f, data.employee[f] ?? (overlaps.includes(f) ? data.person?.[f] : null) ?? '']))); }
  }), [current, employeeId, run]);
  useEffect(() => {
    active.current = true; abort.current = new AbortController(); load();
    return () => { active.current = false; abort.current.abort(); };
  }, [load]);
  const preview = () => run(async options => {
    const changes = Object.fromEntries(Object.keys(fields).filter(f => (values[f] || null) !== (context.employee[f] || null)
      || (overlaps.includes(f) && (values[f] || null) !== (context.person[f] || null))).map(f => [f, values[f] || null]));
    const data = await api.post(`${endpoint}?action=preview`, { employee_id: Number(employeeId), changes, reason: reason.trim() }, options);
    if (current()) { setReview(data); setConfirmed(false); }
  });
  const apply = () => run(async options => {
    const body = pending || { review_token: review.review_token, confirm_setup: true }; setPending(body);
    let data;
    try { data = await api.post(`${endpoint}?action=apply`, body, options); }
    catch (e) {
      if (current() && [400, 401, 403, 409, 422].includes(e.status)) { setPending(null); setReview(null); setConfirmed(false); }
      throw e;
    }
    if (current()) { setReceipt(data); setPending(null); setReview(null); onSaved?.(); }
  });
  const loadHistory = () => run(async options => {
    const data = await api.get(`${endpoint}?action=history&employee_id=${encodeURIComponent(employeeId)}`, options);
    if (current()) setHistory(data.rows);
  });
  const editable = context?.available && context.linked && context.can_manage
    && ['pending', 'active'].includes(context.employee.status) && !context.employee.terminated_at;
  const frozen = busy || !!review || !!pending;
  const changed = context && Object.keys(fields).some(f => (values[f] || null) !== (context.employee[f] || null)
    || (overlaps.includes(f) && (values[f] || null) !== (context.person?.[f] || null)));
  return <section aria-label="Employment setup" style={{ marginTop: 24 }}>
    <h3>Review employment setup</h3>
    <p>Confirm the employment facts before activating a pending record. Pay, tax, banking and assignment approvals are managed separately.</p>
    <p>Employment type and hire date are saved to both the person profile and employment record. Existing profile values fill empty fields for review.</p>
    {error && <p role="alert" className="error">{error}</p>}
    {receipt ? <p role="status">Employment setup saved. Review receipt #{receipt.id}.</p> : !context ? <p>{busy ? 'Loading employment setup…' : 'Reopen this record to load employment setup.'}</p>
      : !context.available ? <p>Employment setup is awaiting installation.</p>
        : !context.linked ? <p>Review and save the person-to-employment link from the person’s profile first.</p>
          : !context.can_manage ? <p>You can view employment. A People manager can review changes.</p>
            : !editable ? <p>Ended or suspended employment requires its own lifecycle review.</p>
              : <>
                <p>{context.person.person_number} · {context.employee.employee_number}</p>
                <div className="detail-grid">{Object.entries(fields).map(([field, label]) => <label key={field}>{label}
                  {choices[field] ? <select aria-label={label} value={values[field]} disabled={frozen} onChange={e => setValues(v => ({ ...v, [field]: e.target.value }))}>
                    {field !== 'status' && <option value="">Not set</option>}
                    {Object.entries(choices[field]).map(([value, title]) => <option key={value} value={value} disabled={field === 'status' && context.employee.status === 'active' && value === 'pending'}>{title}</option>)}
                  </select> : <input aria-label={label} type={field.endsWith('_date') ? 'date' : 'text'} value={values[field]} maxLength={field === 'job_title' ? 150 : 120} disabled={frozen} onChange={e => setValues(v => ({ ...v, [field]: e.target.value }))} />}
                </label>)}</div>
                <label>Reason for these changes <textarea aria-label="Reason for these changes" value={reason} maxLength={500} disabled={frozen} onChange={e => setReason(e.target.value)} /></label>
                <button className="btn" disabled={frozen || !changed || !reason.trim()} onClick={preview}>Review employment changes</button>
                {review && <div>
                  <h4>Confirm employment changes</h4><p>{review.person_number} · {review.employee_number}</p>
                  <table className="data-table"><thead><tr><th>Field</th><th>Employment record</th><th>Person profile</th><th>Reviewed</th></tr></thead><tbody>
                    {Object.entries(fields).filter(([f]) => differs(review, f)).map(([f, label]) => <tr key={f}><td>{label}</td><td>{display(f, review.before[f])}</td><td>{overlaps.includes(f) ? display(f, review.person_before?.[f]) : '—'}</td><td>{display(f, review.after[f])}</td></tr>)}
                  </tbody></table><p>Reason: {review.reason}</p>
                  <label><input type="checkbox" checked={confirmed} disabled={busy || !!pending} onChange={e => setConfirmed(e.target.checked)} /> I checked these employment facts.</label>
                  <div><button className="btn btn--primary" disabled={busy || !confirmed} onClick={apply}>{pending ? 'Check original save' : 'Save employment setup'}</button>
                    <button className="btn" disabled={busy || !!pending} onClick={() => { setReview(null); setConfirmed(false); }}>Change review</button></div>
                  {pending && <p>The result has not been confirmed. Check the original save to recover its result safely.</p>}
                </div>}
              </>}
    {context?.can_manage && context.available && <div style={{ marginTop: 16 }}>
      <button className="btn" disabled={busy || !!pending} onClick={loadHistory}>Show recent setup reviews</button>
      {history && <><h4>Latest 20 setup reviews</h4>{history.length ? history.map(row => <details key={row.id}><summary>#{row.id} · {row.created_at} · {row.kind === 'activation' ? 'Activated' : 'Setup updated'}</summary>
        <p>Reason: {row.reason} · Operator #{row.actor_user_id}</p><ul>{Object.entries(fields).filter(([f]) => differs(row, f)).map(([f, label]) => <li key={f}>{label}: {display(f, row.before[f])} → {display(f, row.after[f])}{overlaps.includes(f) ? `; person profile was ${display(f, row.person_before?.[f])}` : ''}</li>)}</ul>
      </details>) : <p>No setup reviews saved yet.</p>}</>}
    </div>}
  </section>;
}
