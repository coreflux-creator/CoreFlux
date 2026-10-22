import React, { useCallback, useEffect, useRef, useState } from 'react';
import { api } from '../../../dashboard/src/lib/api';

const ENDPOINT = '/modules/people/api/onboarding.php';
const LABELS = { requested: 'Requested', submitted: 'Awaiting review', approved: 'Approved', rejected: 'Needs correction',
  waived: 'Waived', retired: 'Retired', needs_review: 'Document changed — resubmit', expired: 'Expired', evidence_unavailable: 'Document unavailable' };
const card = { border: '1px solid #dce4ed', borderRadius: 8, padding: 16, marginBottom: 12 };
const row = { display: 'flex', gap: 12, flexWrap: 'wrap', alignItems: 'end', marginBottom: 12 };

function TaskDefinition({ task, onSave, busy }) {
  const [values, setValues] = useState(task || { title: '', instructions: '', assignee: 'worker', evidence_kind: 'attestation', required: true });
  const change = (key, value) => setValues(v => ({ ...v, [key]: value }));
  return <form onSubmit={e => { e.preventDefault(); onSave(Object.fromEntries(['title', 'instructions', 'assignee', 'evidence_kind', 'required'].map(k => [k, values[k]]))); }} style={card}>
    <div style={row}>
      <label>Task name<input required maxLength={160} value={values.title} onChange={e => change('title', e.target.value)} /></label>
      <label>Responsible party<select value={values.assignee} onChange={e => change('assignee', e.target.value)}><option value="worker">Worker</option><option value="staff">Staff</option></select></label>
      <label>Evidence<select value={values.evidence_kind} onChange={e => change('evidence_kind', e.target.value)}><option value="attestation">Recorded attestation</option><option value="document">Person document</option></select></label>
    </div>
    <label>Instructions<input required maxLength={600} style={{ width: '100%' }} value={values.instructions} onChange={e => change('instructions', e.target.value)} /></label>
    <p>Use a task description suitable for the worker portal. Keep private document details in the document vault.</p>
    <label><input type="checkbox" checked={values.required} onChange={e => change('required', e.target.checked)} /> Required for this case</label>
    <p><button type="submit" className="btn btn--primary" disabled={busy}>{task ? 'Save revised requirement' : 'Add requirement'}</button></p>
  </form>;
}

export function OnboardingTask({ task, personId, documents, permissions, busy, command }) {
  const [mode, setMode] = useState('');
  const [documentId, setDocumentId] = useState('');
  const [acknowledged, setAcknowledged] = useState(false);
  const [decision, setDecision] = useState('approve');
  const [reason, setReason] = useState('');
  const [expires, setExpires] = useState('');
  const active = task.state !== 'retired';
  function run(action, fields = {}) { command(action, { task_id: task.id, ...fields }); }
  return <article style={card} data-testid="onboarding-task">
    <div style={{ ...row, justifyContent: 'space-between' }}><strong>{task.title}</strong><span>{LABELS[task.effective_state] || task.effective_state}</span></div>
    <p>{task.instructions}</p>
    <p>{task.required ? 'Required' : 'Optional'} · {task.assignee === 'worker' ? 'Worker responsibility' : 'Staff responsibility'} · {task.evidence_kind === 'document' ? 'Document evidence' : 'Attestation'}</p>
    {task.evidence && <p>Recorded by Core staff #{task.evidence.actor_user_id} on {task.evidence.submitted_at}.
      {task.evidence.document_id && <> Document #{task.evidence.document_id}. <a href={`/modules/people/${personId}/documents`}>Open document vault</a></>}
    </p>}
    {task.review && <p>Review by staff #{task.review.actor_user_id}: {task.review.reason} ({task.review.reviewed_at}){task.review.expires_at ? ` · Valid through ${task.review.expires_at}` : ''}</p>}
    {active && <div style={row}>
      {permissions.manage && <><button disabled={busy} onClick={() => setMode('submit')}>Record evidence</button><button disabled={busy} onClick={() => setMode('edit')}>Edit requirement</button>
        <button disabled={busy} onClick={() => setMode('reopen')}>Reopen</button><button disabled={busy} onClick={() => setMode('retire_task')}>Retire requirement</button></>}
      {permissions.review && <button disabled={busy} onClick={() => { setDecision(task.state === 'submitted' ? 'approve' : 'waive'); setMode('review'); }}>Review / waive</button>}
    </div>}
    {mode === 'edit' && <TaskDefinition task={task} busy={busy} onSave={values => run('revise_task', values)} />}
    {mode === 'submit' && <form onSubmit={e => { e.preventDefault(); run('submit', { acknowledged, ...(task.evidence_kind === 'document' ? { document_id: Number(documentId) } : {}) }); }}>
      {task.evidence_kind === 'document' && <label>Person document<select required value={documentId} onChange={e => setDocumentId(e.target.value)}><option value="">Choose a document</option>{documents.map(d => <option key={d.id} value={d.id}>#{d.id} · {d.doc_type} · {d.file_name || 'Document'}{d.expires_at ? ` · expires ${d.expires_at}` : ''}</option>)}</select></label>}
      <p>This records a staff submission for review. It does not record the worker signing in or signing a document.</p>
      <label><input required type="checkbox" checked={acknowledged} onChange={e => setAcknowledged(e.target.checked)} /> I checked the instructions and supporting evidence.</label>
      <p><button className="btn btn--primary" disabled={busy || !acknowledged}>Submit for review</button></p>
    </form>}
    {['review', 'reopen', 'retire_task'].includes(mode) && <form onSubmit={e => { e.preventDefault(); run(mode === 'review' ? 'review' : mode, { reason, ...(mode === 'review' ? { decision, expires_at: expires || null } : {}) }); }}>
      {mode === 'review' && <div style={row}><label>Decision<select value={decision} onChange={e => setDecision(e.target.value)}>
        {task.state === 'submitted' && <><option value="approve">Approve evidence</option><option value="reject">Return for correction</option></>}<option value="waive">Waive requirement</option>
      </select></label><label>Valid through (optional)<input type="date" value={expires} onChange={e => setExpires(e.target.value)} /></label></div>}
      <label>Reason<input required maxLength={500} value={reason} onChange={e => setReason(e.target.value)} /></label>
      <p>{mode === 'retire_task' ? 'Retiring preserves history and requires the requirement list to be confirmed again.' : mode === 'reopen' ? 'Reopening clears the current evidence and review. History is preserved.' : 'Review the evidence before recording your decision. A waiver remains visible in the case history.'}</p>
      <button className="btn btn--primary" disabled={busy}>Save {mode === 'review' ? 'review' : mode === 'reopen' ? 'reopened task' : 'retirement'}</button>
    </form>}
    {mode && <p><button disabled={busy} onClick={() => setMode('')}>Close task form</button></p>}
  </article>;
}

export function OnboardingCaseView({ value, documents = [], permissions = {}, busy, command }) {
  const [adding, setAdding] = useState(false);
  return <div>
    <header style={card}><h3>{value.person_number} · {value.placement_number}</h3>
      <p role="status"><strong>{value.readiness === 'complete' ? 'Confirmed requirements complete' : value.readiness === 'requirements_unconfirmed' ? 'Requirement list needs confirmation' : 'Requirements outstanding'}</strong> · {value.satisfied_count} of {value.required_count} required tasks satisfied</p>
      <p>Case status checked {value.observed_at}. Payroll authorization is evaluated separately.</p>
      <p>Portal status: this case has not been published to Quanta.</p>
      {value.confirmed_by_user_id && <p>Requirement list confirmed by staff #{value.confirmed_by_user_id} on {value.confirmed_at}.</p>}
      {value.source_changed && value.confirmed_plan_version !== null && <p>Placement setup changed since the last confirmation. Review the requirements and evidence again.</p>}
      <p><a href={`/modules/placements/${value.placement_id}/overview`}>Review placement setup</a></p>
      {permissions.review && (value.source_changed || value.confirmed_plan_version !== value.plan_version) && <button disabled={busy || !value.required_count} onClick={() => command('confirm_plan')}>Confirm this is the full requirement list</button>}
    </header>
    {!value.tasks.length && <p>Add the employer’s requirements for this placement. An empty case is not complete.</p>}
    {value.tasks.map(task => <OnboardingTask key={`${task.id}:${task.version}`} task={task} personId={value.person_id} documents={documents} permissions={permissions} busy={busy} command={command} />)}
    {permissions.manage && <><button disabled={busy || value.tasks.length >= 25} onClick={() => setAdding(v => !v)}>{adding ? 'Close new requirement' : 'Add requirement'}</button>
      {adding && <TaskDefinition key={value.version} busy={busy} onSave={fields => command('add_task', fields)} />}</>}
    <details style={{ marginTop: 24 }}><summary>Case history</summary>
      {value.history_has_more && <p>Showing the latest 100 changes. Earlier history remains retained.</p>}
      <ol>{(value.history || []).map(e => <li key={e.case_version}>Revision {e.case_version} · {e.action.replaceAll('_', ' ')} · staff #{e.actor_user_id} · {e.created_at}{e.details?.reason ? ` · ${e.details.reason}` : e.details?.review?.reason ? ` · ${e.details.review.reason}` : ''}</li>)}</ol>
    </details>
  </div>;
}

export default function OnboardingTab({ personId }) {
  const [catalog, setCatalog] = useState(null), [value, setValue] = useState(null), [placementId, setPlacementId] = useState('');
  const [busy, setBusy] = useState(false), [error, setError] = useState(''), [pending, setPending] = useState(null);
  const epoch = useRef(0);
  const invalidateRequests = useCallback(() => { epoch.current++; }, []);
  const load = useCallback(async (caseId = null) => {
    const token = ++epoch.current; setBusy(true); setError(''); setValue(null); setCatalog(null);
    try {
      const list = await api.get(`${ENDPOINT}?person_id=${personId}`);
      const selected = caseId || list.cases?.[0]?.id;
      const detail = selected ? await api.get(`${ENDPOINT}?case_id=${selected}`) : null;
      if (token !== epoch.current) return;
      setCatalog(list); setValue(detail?.case || null);
    } catch (e) { if (token === epoch.current) { setCatalog(null); setError(e.message); } }
    finally { if (token === epoch.current) setBusy(false); }
  }, [personId]);
  useEffect(() => { setPending(null); load(); return invalidateRequests; }, [load, invalidateRequests]);
  async function send(body) {
    const token = ++epoch.current; setBusy(true); setError(''); setPending(body);
    try {
      const result = await api.post(ENDPOINT, body);
      if (token !== epoch.current) return;
      setPending(null); setValue(result.case);
      const list = await api.get(`${ENDPOINT}?person_id=${personId}`);
      if (token === epoch.current) setCatalog(list);
    } catch (e) {
      if (token !== epoch.current) return;
      setError(e.message);
      if ([400, 401, 403, 404, 409, 415, 422].includes(e.status)) { setPending(null); setValue(null); }
    } finally { if (token === epoch.current) setBusy(false); }
  }
  function command(action, fields = {}) {
    if (busy || pending) return;
    send({ action, request_id: crypto.randomUUID(), ...(action === 'create' ? { person_id: Number(personId), placement_id: Number(placementId) } : { case_id: value.id, expected_version: value.version }), ...fields });
  }
  const disabled = busy || Boolean(pending);
  return <section data-testid="onboarding-tab"><h2>Onboarding</h2>
    <p>Define the employer’s requirements, record evidence and review each task for this placement.</p>
    {error && <p role="alert" className="error">{error}</p>}
    {pending && !busy && <p>The last change is not yet confirmed. <button onClick={() => send(pending)}>Retry the same change</button></p>}
    <button disabled={disabled} onClick={() => load(value?.id)}>Reload cases and documents</button>
    {busy && <p>Loading…</p>}
    {catalog && <>
      {catalog.cases.length > 0 && <p><label>Onboarding case<select disabled={disabled} value={value?.id || ''} onChange={e => load(e.target.value)}><option value="" disabled>Choose a case</option>
        {catalog.cases.map(c => <option key={c.id} value={c.id}>{catalog.placements.find(p => Number(p.id) === Number(c.placement_id))?.placement_number || `Placement ${c.placement_id}`}</option>)}
      </select></label></p>}
      {catalog.permissions.manage && <form onSubmit={e => { e.preventDefault(); command('create'); }} style={row}>
        <label>Start a case for another placement<select required value={placementId} disabled={disabled} onChange={e => setPlacementId(e.target.value)}><option value="">Choose a placement</option>
          {catalog.placements.filter(p => !catalog.cases.some(c => Number(c.placement_id) === Number(p.id))).map(p => <option key={p.id} value={p.id}>{p.placement_number} · {p.title} · {p.status}</option>)}
        </select></label><button disabled={disabled || !placementId}>Create onboarding case</button>
      </form>}
      {catalog.documents_has_more && <p>Showing the latest 100 documents. Use the document vault for older items.</p>}
      {value && <OnboardingCaseView key={value.id} value={value} documents={catalog.documents} permissions={catalog.permissions} busy={disabled} command={command} />}
    </>}
  </section>;
}
