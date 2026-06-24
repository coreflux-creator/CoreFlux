import React, { useMemo, useState } from 'react';
import { GitBranch, KeyRound, Plus, RefreshCw, Route, ShieldCheck } from 'lucide-react';
import { api, useApi } from '../../../dashboard/src/lib/api';

const DEFAULT_QUESTIONS = [
  'who_owns',
  'who_approves',
  'who_reviews',
  'who_reviews_ai',
  'who_notifies',
  'who_escalates',
  'who_operates',
  'who_can_view',
];

const DEFAULT_ACTORS = ['person', 'user', 'company', 'team', 'role', 'ai_worker', 'external'];
const DEFAULT_RESPONSIBILITIES = ['owner', 'accountable', 'approver', 'reviewer', 'ai_supervisor', 'notifier', 'operator', 'viewer', 'escalation_contact'];
const DEFAULT_ACTIONS = ['view', 'create', 'edit', 'approve', 'post', 'release', 'review', 'notify', 'export'];

export default function PeopleGraph() {
  const { data: vocabData, loading: vocabLoading } = useApi('/api/v1/people/graph/vocabulary');
  const vocabulary = vocabData || {};
  const questions = Object.keys(vocabulary.resolver_questions || {}).length
    ? Object.keys(vocabulary.resolver_questions)
    : DEFAULT_QUESTIONS;
  const actorTypes = vocabulary.actor_types || DEFAULT_ACTORS;
  const responsibilityTypes = vocabulary.responsibility_types || DEFAULT_RESPONSIBILITIES;
  const permissionActions = vocabulary.permission_actions || DEFAULT_ACTIONS;

  const [objectRef, setObjectRef] = useState({
    object_module: 'payroll',
    object_type: 'run',
    object_id: '',
  });
  const [question, setQuestion] = useState('who_approves');
  const [result, setResult] = useState(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const [form, setForm] = useState({
    responsibility_type: 'approver',
    actor_type: 'user',
    actor_id: '',
    priority: 100,
  });
  const [permissionForm, setPermissionForm] = useState({
    actor_type: 'user',
    actor_id: '',
    action: 'approve',
    amount: '',
  });
  const [permissionDecision, setPermissionDecision] = useState(null);
  const [approvalForm, setApprovalForm] = useState({
    amount: '',
    source_actor_type: 'person',
    source_actor_id: '',
  });
  const [approvalResolution, setApprovalResolution] = useState(null);

  const hasObject = objectRef.object_module && objectRef.object_type && objectRef.object_id;
  const assignmentPath = useMemo(() => {
    if (!hasObject) return null;
    const params = new URLSearchParams(objectRef);
    return `/api/v1/people/graph/responsibilities?${params.toString()}`;
  }, [hasObject, objectRef]);
  const assignments = useApi(assignmentPath, { enabled: Boolean(assignmentPath) });

  const setObj = (key) => (event) => setObjectRef({ ...objectRef, [key]: event.target.value });
  const setAssignment = (key) => (event) => setForm({ ...form, [key]: event.target.value });
  const setPermission = (key) => (event) => setPermissionForm({ ...permissionForm, [key]: event.target.value });
  const setApproval = (key) => (event) => setApprovalForm({ ...approvalForm, [key]: event.target.value });

  const resolve = async () => {
    setBusy(true);
    setError(null);
    try {
      const params = new URLSearchParams({ question, ...objectRef });
      const res = await api.get(`/api/v1/people/graph/resolve?${params.toString()}`);
      setResult(res);
    } catch (err) {
      setError(err);
    } finally {
      setBusy(false);
    }
  };

  const createAssignment = async (event) => {
    event.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await api.post('/api/v1/people/graph/responsibilities', {
        ...objectRef,
        responsibility_type: form.responsibility_type,
        actor_type: form.actor_type,
        actor_id: Number(form.actor_id),
        priority: Number(form.priority || 100),
      });
      setForm({ ...form, actor_id: '' });
      await assignments.reload();
      await resolve();
    } catch (err) {
      setError(err);
    } finally {
      setBusy(false);
    }
  };

  const checkPermission = async (event) => {
    event.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const payload = {
        actor_type: permissionForm.actor_type,
        actor_id: Number(permissionForm.actor_id),
        action: permissionForm.action,
        resource_module: objectRef.object_module,
        resource_type: objectRef.object_type,
        resource_id: objectRef.object_id,
        context: permissionForm.amount ? { amount: Number(permissionForm.amount) } : {},
      };
      const res = await api.post('/api/v1/people/graph/check-permission', payload);
      setPermissionDecision(res.decision || res);
    } catch (err) {
      setError(err);
    } finally {
      setBusy(false);
    }
  };

  const resolveApprovers = async (event) => {
    event.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const payload = {
        resource_module: objectRef.object_module,
        resource_type: objectRef.object_type,
        resource_id: objectRef.object_id,
        context: approvalForm.amount ? { amount: Number(approvalForm.amount) } : {},
      };
      if (approvalForm.source_actor_id) {
        payload.source_actor_type = approvalForm.source_actor_type;
        payload.source_actor_id = Number(approvalForm.source_actor_id);
      }
      const res = await api.post('/api/v1/people/graph/resolve-approvers', payload);
      setApprovalResolution(res.approval_resolution || res);
    } catch (err) {
      setError(err);
    } finally {
      setBusy(false);
    }
  };

  const currentAssignments = assignments.data?.responsibilities || [];
  const resolvedAssignments = result?.assignments || [];

  return (
    <section data-testid="people-graph-page">
      <header style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12, marginBottom: 16, flexWrap: 'wrap' }}>
        <div>
          <h2 style={{ margin: 0, display: 'flex', alignItems: 'center', gap: 8 }}>
            <GitBranch size={20} aria-hidden="true" /> People Graph
          </h2>
          <p style={{ margin: '4px 0 0', color: 'var(--cf-text-secondary)', fontSize: 13 }}>
            Authority, responsibility, delegation, and AI supervision.
          </p>
        </div>
        <button className="btn btn--ghost" type="button" onClick={() => { assignments.reload(); if (hasObject) resolve(); }} disabled={!hasObject || busy} title="Refresh graph data" data-testid="people-graph-refresh">
          <RefreshCw size={16} aria-hidden="true" /> Refresh
        </button>
      </header>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 10, alignItems: 'end', marginBottom: 16 }}>
        <Field label="Module">
          <input className="input" value={objectRef.object_module} onChange={setObj('object_module')} data-testid="pg-object-module" />
        </Field>
        <Field label="Object type">
          <input className="input" value={objectRef.object_type} onChange={setObj('object_type')} data-testid="pg-object-type" />
        </Field>
        <Field label="Object ID">
          <input className="input" value={objectRef.object_id} onChange={setObj('object_id')} data-testid="pg-object-id" />
        </Field>
        <Field label="Question">
          <select className="input" value={question} onChange={(event) => setQuestion(event.target.value)} data-testid="pg-question">
            {questions.map((q) => <option key={q} value={q}>{q}</option>)}
          </select>
        </Field>
        <button className="btn btn--primary" type="button" onClick={resolve} disabled={!hasObject || busy || vocabLoading} title="Resolve authority" data-testid="pg-resolve">
          <ShieldCheck size={16} aria-hidden="true" /> Resolve
        </button>
      </div>

      {error && <p className="error" data-testid="people-graph-error">Error: {error.message}</p>}

      <h3 style={sectionHeading}>Resolved Actors</h3>
      <GraphTable
        empty={hasObject ? 'No resolved actors.' : 'Enter an object ID.'}
        rows={resolvedAssignments}
        testId="pg-resolved-table"
      />

      <h3 style={sectionHeading}>Authority Check</h3>
      <form onSubmit={checkPermission} style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))', gap: 10, alignItems: 'end', marginBottom: 10 }} data-testid="pg-permission-check-form">
        <Field label="Actor type">
          <select className="input" value={permissionForm.actor_type} onChange={setPermission('actor_type')} data-testid="pg-permission-actor-type">
            {actorTypes.map((type) => <option key={type} value={type}>{type}</option>)}
          </select>
        </Field>
        <Field label="Actor ID">
          <input className="input" type="number" min="1" value={permissionForm.actor_id} onChange={setPermission('actor_id')} data-testid="pg-permission-actor-id" />
        </Field>
        <Field label="Action">
          <select className="input" value={permissionForm.action} onChange={setPermission('action')} data-testid="pg-permission-action">
            {permissionActions.map((action) => <option key={action} value={action}>{action}</option>)}
          </select>
        </Field>
        <Field label="Amount">
          <input className="input" type="number" min="0" step="0.01" value={permissionForm.amount} onChange={setPermission('amount')} data-testid="pg-permission-amount" />
        </Field>
        <button className="btn btn--primary" type="submit" disabled={!hasObject || !permissionForm.actor_id || busy} title="Check authority" data-testid="pg-check-permission">
          <KeyRound size={16} aria-hidden="true" /> Check
        </button>
      </form>
      <PermissionDecision decision={permissionDecision} />

      <h3 style={sectionHeading}>Approval Resolution</h3>
      <form onSubmit={resolveApprovers} style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))', gap: 10, alignItems: 'end', marginBottom: 10 }} data-testid="pg-approval-resolution-form">
        <Field label="Amount">
          <input className="input" type="number" min="0" step="0.01" value={approvalForm.amount} onChange={setApproval('amount')} data-testid="pg-approval-amount" />
        </Field>
        <Field label="Source actor type">
          <select className="input" value={approvalForm.source_actor_type} onChange={setApproval('source_actor_type')} data-testid="pg-approval-source-type">
            {actorTypes.map((type) => <option key={type} value={type}>{type}</option>)}
          </select>
        </Field>
        <Field label="Source actor ID">
          <input className="input" type="number" min="1" value={approvalForm.source_actor_id} onChange={setApproval('source_actor_id')} data-testid="pg-approval-source-id" />
        </Field>
        <button className="btn btn--primary" type="submit" disabled={!hasObject || busy} title="Resolve approvers" data-testid="pg-resolve-approvers">
          <Route size={16} aria-hidden="true" /> Resolve
        </button>
      </form>
      <ApprovalResolution resolution={approvalResolution} />

      <h3 style={sectionHeading}>Assignments</h3>
      <form onSubmit={createAssignment} style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))', gap: 10, alignItems: 'end', marginBottom: 10 }} data-testid="pg-assignment-form">
        <Field label="Responsibility">
          <select className="input" value={form.responsibility_type} onChange={setAssignment('responsibility_type')} data-testid="pg-responsibility-type">
            {responsibilityTypes.map((type) => <option key={type} value={type}>{type}</option>)}
          </select>
        </Field>
        <Field label="Actor type">
          <select className="input" value={form.actor_type} onChange={setAssignment('actor_type')} data-testid="pg-actor-type">
            {actorTypes.map((type) => <option key={type} value={type}>{type}</option>)}
          </select>
        </Field>
        <Field label="Actor ID">
          <input className="input" type="number" min="1" value={form.actor_id} onChange={setAssignment('actor_id')} data-testid="pg-actor-id" />
        </Field>
        <Field label="Priority">
          <input className="input" type="number" min="0" value={form.priority} onChange={setAssignment('priority')} data-testid="pg-priority" />
        </Field>
        <button className="btn btn--primary" type="submit" disabled={!hasObject || !form.actor_id || busy} title="Add assignment" data-testid="pg-add-assignment">
          <Plus size={16} aria-hidden="true" /> Add
        </button>
      </form>
      <GraphTable
        empty={hasObject ? (assignments.loading ? 'Loading...' : 'No assignments.') : 'Enter an object ID.'}
        rows={currentAssignments}
        testId="pg-assignments-table"
      />
    </section>
  );
}

function PermissionDecision({ decision }) {
  if (!decision) return null;
  return (
    <div className="panel" data-testid="pg-permission-decision" style={{ marginBottom: 18, padding: 12 }}>
      <strong>{decision.allowed ? 'Allowed' : 'Denied'}</strong>
      <span style={{ marginLeft: 8, color: 'var(--cf-text-secondary)' }}>{decision.reason_code}</span>
      <div style={{ marginTop: 4, fontSize: 13 }}>{decision.explanation}</div>
      <div style={{ marginTop: 6, color: 'var(--cf-text-secondary)', fontSize: 12 }}>
        Grants: {decision.matched_grants?.length || 0} / Roles: {decision.matched_roles?.length || 0} / Delegations: {decision.matched_delegations?.length || 0}
      </div>
    </div>
  );
}

function ApprovalResolution({ resolution }) {
  const requirements = resolution?.requirements || [];
  if (!resolution) return null;
  return (
    <table className="data-table" data-testid="pg-approval-resolution-table" style={{ width: '100%', marginBottom: 18 }}>
      <thead>
        <tr>
          <th>Policy</th>
          <th>Strategy</th>
          <th>Minimum</th>
          <th>Approvers</th>
        </tr>
      </thead>
      <tbody>
        {requirements.length === 0 && <tr><td colSpan={4} className="empty">No approval requirements.</td></tr>}
        {requirements.map((item, index) => (
          <tr key={`${item.policy?.id || 'policy'}-${item.rule?.id || index}`}>
            <td>{item.policy?.name || item.policy?.policy_key || '-'}</td>
            <td><span className="badge">{item.rule?.approver_strategy || '-'}</span></td>
            <td>{item.rule?.minimum_approvals || 1}</td>
            <td>{(item.approvers || []).map((actor) => actor.label || `${actor.actor_type} #${actor.actor_id}`).join(', ') || '-'}</td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}

function GraphTable({ rows, empty, testId }) {
  return (
    <table className="data-table" data-testid={testId} style={{ width: '100%', marginBottom: 18 }}>
      <thead>
        <tr>
          <th>Responsibility</th>
          <th>Actor</th>
          <th>Priority</th>
          <th>Delegated from</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        {rows.length === 0 && <tr><td colSpan={5} className="empty">{empty}</td></tr>}
        {rows.map((row) => (
          <tr key={`${row.id}-${row.delegation_id || 'direct'}`}>
            <td><span className="badge">{row.responsibility_type}</span></td>
            <td>{row.actor?.label || `${row.actor_type} #${row.actor_id}`}<div style={{ color: 'var(--cf-text-secondary)', fontSize: 11 }}>{row.actor?.type || row.actor_type} #{row.actor?.id || row.actor_id}</div></td>
            <td>{row.priority ?? '100'}</td>
            <td>{row.delegated_from ? `${row.delegated_from.label} (${row.delegated_from.type} #${row.delegated_from.id})` : '-'}</td>
            <td>{row.status || 'active'}</td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}

function Field({ label, children }) {
  return (
    <label style={{ display: 'block', fontSize: 13 }}>
      <span style={{ color: 'var(--cf-text-secondary)' }}>{label}</span>
      <div style={{ marginTop: 4 }}>{children}</div>
    </label>
  );
}

const sectionHeading = {
  margin: '18px 0 8px',
  fontSize: 13,
  textTransform: 'uppercase',
  letterSpacing: 0,
  color: 'var(--cf-text-secondary)',
};
