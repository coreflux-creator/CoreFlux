import React, { useState } from 'react';
import { api, useApi } from '../../../dashboard/src/lib/api';
import { CheckCircle2 } from 'lucide-react';

const fmtMoney = (n) =>
  (Number(n) || 0).toLocaleString(undefined, { style: 'currency', currency: 'USD' });

/**
 * Approvals Inbox + Workflow Admin.
 *
 * Tabs:
 *   • Inbox  — bills currently awaiting THIS user's signature
 *   • Workflows  — admin: define amount-bracketed approval chains
 */
export default function Approvals() {
  const [tab, setTab] = useState('inbox');
  return (
    <section data-testid="ap-approvals">
      <div style={{ display: 'flex', gap: 12, marginBottom: 16, borderBottom: '1px solid #e5e7eb' }}>
        <button
          type="button"
          data-testid="ap-approvals-tab-inbox"
          onClick={() => setTab('inbox')}
          style={tabStyle(tab === 'inbox')}
        >
          Inbox
        </button>
        <button
          type="button"
          data-testid="ap-approvals-tab-workflows"
          onClick={() => setTab('workflows')}
          style={tabStyle(tab === 'workflows')}
        >
          Workflows
        </button>
      </div>
      {tab === 'inbox'     && <ApprovalsInbox />}
      {tab === 'workflows' && <WorkflowsAdmin />}
    </section>
  );
}

function tabStyle(active) {
  return {
    padding: '8px 14px', background: 'transparent', border: 'none',
    borderBottom: active ? '2px solid #111827' : '2px solid transparent',
    marginBottom: -1, fontWeight: active ? 600 : 400,
    color: active ? '#111827' : '#6b7280', cursor: 'pointer',
  };
}

function TabBtn({ label, val, activeTab, onClick, testid }) {
  const active = activeTab === val;
  return (
    <button
      type="button"
      data-testid={testid}
      onClick={() => onClick(val)}
      style={tabStyle(active)}
    >
      {label}
    </button>
  );
}

function ApprovalsInbox() {
  const { data, loading, reload } = useApi('/modules/ap/api/bill_approvals.php?inbox=1');
  const { data: countData, reload: reloadCount } = useApi('/modules/ap/api/bill_approvals.php?count_pending=1');
  const rows = data?.rows || [];
  const pendingCount = countData?.count ?? null;
  const [actingId, setActingId] = useState(null);
  const [decidingId, setDecidingId] = useState(null);
  const [note, setNote] = useState('');
  const [selected, setSelected] = useState(() => new Set());
  const [bulkNote, setBulkNote] = useState('');
  const [bulkBusy, setBulkBusy] = useState(false);
  const [bulkProgress, setBulkProgress] = useState({ done: 0, total: 0 });
  const [bulkResult, setBulkResult] = useState(null);
  const [err, setErr] = useState(null);

  const selectedRows = rows.filter((row) => selected.has(String(row.bill_id)));
  const allSelected = rows.length > 0 && rows.every((row) => selected.has(String(row.bill_id)));

  const toggleOne = (billId) => {
    const key = String(billId);
    setSelected((current) => {
      const next = new Set(current);
      if (next.has(key)) next.delete(key);
      else next.add(key);
      return next;
    });
    setBulkResult(null);
  };

  const toggleAll = () => {
    setSelected((current) => {
      const next = new Set(current);
      if (allSelected) rows.forEach((row) => next.delete(String(row.bill_id)));
      else rows.forEach((row) => next.add(String(row.bill_id)));
      return next;
    });
    setBulkResult(null);
  };

  const decide = async (billId, action) => {
    setErr(null);
    setBulkResult(null);
    setDecidingId(billId);
    try {
      await api.post(
        `/modules/ap/api/bill_approvals.php?action=${action}`,
        { bill_id: billId, note: note || null }
      );
      setActingId(null); setNote('');
      setSelected((current) => {
        const next = new Set(current);
        next.delete(String(billId));
        return next;
      });
      reload();
      reloadCount();
    } catch (e) { setErr(friendlyApprovalError(e)); }
    finally { setDecidingId(null); }
  };

  const approveSelected = async () => {
    const queue = selectedRows.map((row) => ({
      billId: row.bill_id,
      label: row.bill_number || row.vendor_name || `Bill ${row.bill_id}`,
    }));
    if (queue.length === 0 || bulkBusy) return;

    setErr(null);
    setBulkResult(null);
    setBulkBusy(true);
    setBulkProgress({ done: 0, total: queue.length });
    const approved = [];
    const failed = [];

    for (let index = 0; index < queue.length; index += 1) {
      const item = queue[index];
      try {
        await api.post('/modules/ap/api/bill_approvals.php?action=approve', {
          bill_id: item.billId,
          note: bulkNote || null,
        });
        approved.push(String(item.billId));
      } catch (error) {
        failed.push({ ...item, error: friendlyApprovalError(error) });
      }
      setBulkProgress({ done: index + 1, total: queue.length });
    }

    setSelected(new Set(failed.map((item) => String(item.billId))));
    setBulkResult({ approved: approved.length, failed });
    if (failed.length === 0) setBulkNote('');
    setBulkBusy(false);
    reload();
    reloadCount();
  };

  return (
    <div data-testid="ap-approvals-inbox">
      <h3 style={{ margin: '0 0 12px', fontSize: 16, display: 'flex', alignItems: 'center', gap: 8 }}>
        Awaiting your approval
        {pendingCount !== null && pendingCount > 0 && (
          <span className="badge" data-testid="ap-approvals-pending-badge" style={{ background: '#dc2626', color: '#fff' }}>{pendingCount}</span>
        )}
      </h3>
      {err && <p className="error" data-testid="ap-approvals-error">{err}</p>}
      {bulkResult && (
        <p
          className={bulkResult.failed.length > 0 ? 'error' : 'success'}
          data-testid="ap-approvals-bulk-result"
          aria-live="polite"
        >
          {bulkResult.approved > 0 && `${bulkResult.approved} bill${bulkResult.approved === 1 ? '' : 's'} approved.`}
          {bulkResult.failed.length > 0 && (
            ` ${bulkResult.failed.length} could not be approved: ${bulkResult.failed.map((item) => `${item.label} (${item.error})`).join('; ')}`
          )}
        </p>
      )}
      {loading && <p>Loading…</p>}
      {!loading && rows.length === 0 && (
        <p className="muted" data-testid="ap-approvals-inbox-empty">
          Nothing awaits you. (When AP submits bills, they'll show up here based on your role and the matching approval workflow.)
        </p>
      )}
      {rows.length > 0 && (
        <>
          {selectedRows.length > 0 && (
            <div
              data-testid="ap-approvals-bulk-bar"
              style={{
                display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap',
                padding: '10px 12px', marginBottom: 8, background: '#eff6ff',
                border: '1px solid #bfdbfe', borderRadius: 6,
              }}
            >
              <strong style={{ fontSize: 13 }}>{selectedRows.length} selected</strong>
              <input
                className="input"
                placeholder="Optional note for all selected bills"
                value={bulkNote}
                onChange={(event) => setBulkNote(event.target.value)}
                disabled={bulkBusy}
                data-testid="ap-approvals-bulk-note"
                style={{ flex: '1 1 260px', minWidth: 220 }}
              />
              <button
                type="button"
                className="btn btn--primary"
                onClick={approveSelected}
                disabled={bulkBusy}
                data-testid="ap-approvals-bulk-approve"
              >
                <CheckCircle2 size={15} aria-hidden="true" />
                {bulkBusy
                  ? `Approving ${bulkProgress.done} of ${bulkProgress.total}`
                  : `Approve selected (${selectedRows.length})`}
              </button>
            </div>
          )}
          <table className="data-table" data-testid="ap-approvals-inbox-table">
            <thead>
              <tr>
                <th style={{ width: 36 }}>
                  <input
                    type="checkbox"
                    checked={allSelected}
                    onChange={toggleAll}
                    disabled={bulkBusy}
                    aria-label="Select all pending bills"
                    data-testid="ap-approvals-select-all"
                  />
                </th>
                <th>Vendor</th><th>Invoice #</th>
                <th style={{ textAlign: 'right' }}>Amount</th>
                <th>Due</th><th>Step</th><th></th>
              </tr>
            </thead>
            <tbody>
              {rows.map((r) => (
                <React.Fragment key={r.id}>
                  <tr data-testid={`ap-approvals-row-${r.bill_id}`}>
                    <td>
                      <input
                        type="checkbox"
                        checked={selected.has(String(r.bill_id))}
                        onChange={() => toggleOne(r.bill_id)}
                        disabled={bulkBusy || decidingId === r.bill_id}
                        aria-label={`Select ${r.bill_number || r.vendor_name || `bill ${r.bill_id}`}`}
                        data-testid={`ap-approvals-select-${r.bill_id}`}
                      />
                    </td>
                    <td>{r.vendor_name}</td>
                    <td><code>{r.bill_number || '—'}</code></td>
                    <td style={{ textAlign: 'right' }}>{fmtMoney(r.amount_total)}</td>
                    <td>{r.due_date || '—'}</td>
                    <td>{r.step_no} of {r.total_steps}</td>
                    <td>
                      <button
                        type="button"
                        className="btn btn--primary"
                        data-testid={`ap-approvals-act-${r.bill_id}`}
                        onClick={() => setActingId(actingId === r.bill_id ? null : r.bill_id)}
                        disabled={bulkBusy || decidingId === r.bill_id}
                        style={{ padding: '4px 10px', fontSize: 12 }}
                      >
                        Decide
                      </button>
                    </td>
                  </tr>
                  {actingId === r.bill_id && (
                    <tr>
                      <td colSpan={7} style={{ background: '#f8fafc', padding: 12 }}>
                        <input
                          className="input"
                          placeholder="Optional note (visible on the bill)"
                          value={note}
                          onChange={(e) => setNote(e.target.value)}
                          data-testid={`ap-approvals-note-${r.bill_id}`}
                          disabled={decidingId === r.bill_id}
                          style={{ width: '100%', marginBottom: 8 }}
                        />
                        <button
                          type="button"
                          className="btn btn--primary"
                          onClick={() => decide(r.bill_id, 'approve')}
                          disabled={decidingId === r.bill_id}
                          data-testid={`ap-approvals-approve-${r.bill_id}`}
                          style={{ marginRight: 8, background: '#065f46', borderColor: '#065f46' }}
                        >
                          {decidingId === r.bill_id ? 'Saving…' : 'Approve'}
                        </button>
                        <button
                          type="button"
                          className="btn btn--ghost"
                          onClick={() => decide(r.bill_id, 'reject')}
                          disabled={decidingId === r.bill_id}
                          data-testid={`ap-approvals-reject-${r.bill_id}`}
                        >
                          Reject and dispute
                        </button>
                      </td>
                    </tr>
                  )}
                </React.Fragment>
              ))}
            </tbody>
          </table>
        </>
      )}
    </div>
  );
}

function friendlyApprovalError(error) {
  if (error?.status === 403) return 'You do not have permission to approve this bill.';
  if (error?.status === 404) return 'This bill is no longer waiting for your approval.';
  if (error?.status === 409) return 'An earlier approval step still needs to be completed.';
  return error?.message || 'The approval could not be completed. Try again.';
}

function WorkflowsAdmin() {
  const { data, loading, reload } = useApi('/modules/ap/api/approval_workflows.php');
  const { data: usersData } = useApi('/api/users');
  const users = usersData?.rows || usersData?.users || [];
  const workflows = data?.rows || [];
  const [editing, setEditing] = useState(null);  // workflow object or 'new'

  return (
    <div data-testid="ap-approvals-workflows">
      <header style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 12 }}>
        <h3 style={{ margin: 0, fontSize: 16 }}>Approval workflows</h3>
        <button
          type="button"
          className="btn btn--primary"
          onClick={() => setEditing('new')}
          data-testid="ap-approvals-new-workflow"
        >
          + New workflow
        </button>
      </header>
      <p className="muted" style={{ fontSize: 12, margin: '0 0 12px' }}>
        Each workflow has rules bracketed by amount (e.g. <em>$0–$5k → Manager</em>, <em>$5k+ → CFO</em>). When AP submits a bill, the matching default workflow's rules fan out into per-step approvals.
      </p>

      {loading && <p>Loading…</p>}
      {workflows.length === 0 && !editing && (
        <p className="empty" data-testid="ap-approvals-workflows-empty">
          No workflows yet. Click <em>New workflow</em> to define your first.
        </p>
      )}
      {workflows.map((w) => (
        <WorkflowCard key={w.id} workflow={w} users={users} onEdit={() => setEditing(w)} onSaved={reload} />
      ))}

      {editing && (
        <WorkflowEditor
          workflow={editing === 'new' ? null : editing}
          users={users}
          onCancel={() => setEditing(null)}
          onSaved={() => { setEditing(null); reload(); }}
        />
      )}
    </div>
  );
}

function WorkflowCard({ workflow, users, onEdit }) {
  const userById = new Map(users.map((u) => [u.id, u]));
  return (
    <div
      data-testid={`ap-approvals-workflow-${workflow.id}`}
      style={{ padding: 12, border: '1px solid #e5e7eb', borderRadius: 8, marginBottom: 8 }}
    >
      <div style={{ display: 'flex', justifyContent: 'space-between' }}>
        <strong>{workflow.name}</strong>
        <div>
          {workflow.is_default ? <span className="badge badge--active">default</span> : null}
          {!workflow.is_active ? <span className="badge">inactive</span> : null}
          <button
            type="button"
            className="btn btn--ghost"
            onClick={onEdit}
            style={{ padding: '2px 8px', fontSize: 12, marginLeft: 8 }}
            data-testid={`ap-approvals-workflow-edit-${workflow.id}`}
          >
            Edit
          </button>
        </div>
      </div>
      <ul style={{ margin: '8px 0 0', paddingLeft: 20, fontSize: 12 }}>
        {(workflow.rules || []).map((r) => (
          <li key={r.id}>
            Step {r.step_no}: ${Number(r.min_amount).toLocaleString()}
            {r.max_amount !== null ? ` – $${Number(r.max_amount).toLocaleString()}` : '+'}
            {' → '}
            <strong>{userById.get(r.approver_user_id)?.name
              || userById.get(r.approver_user_id)?.email
              || `user #${r.approver_user_id}`}</strong>
          </li>
        ))}
        {(workflow.rules || []).length === 0 && <li className="muted">(no rules)</li>}
      </ul>
    </div>
  );
}

function WorkflowEditor({ workflow, users, onCancel, onSaved }) {
  const [name, setName]         = useState(workflow?.name || '');
  const [isDefault, setDefault] = useState(workflow?.is_default ? 1 : 0);
  const [rules, setRules]       = useState(workflow?.rules?.map((r) => ({
    step_no: r.step_no, min_amount: r.min_amount, max_amount: r.max_amount, approver_user_id: r.approver_user_id,
  })) || [{ step_no: 1, min_amount: 0, max_amount: '', approver_user_id: '' }]);
  const [busy, setBusy] = useState(false);
  const [err, setErr]   = useState(null);

  const updateRule = (i, patch) => setRules((rs) => rs.map((r, idx) => idx === i ? { ...r, ...patch } : r));
  const addRule = () => setRules((rs) => [...rs, { step_no: rs.length + 1, min_amount: 0, max_amount: '', approver_user_id: '' }]);
  const removeRule = (i) => setRules((rs) => rs.filter((_, idx) => idx !== i));

  const save = async () => {
    setBusy(true); setErr(null);
    try {
      const body = {
        name, is_default: isDefault, is_active: 1,
        rules: rules.map((r) => ({
          step_no: Number(r.step_no) || 1,
          min_amount: Number(r.min_amount) || 0,
          max_amount: r.max_amount === '' || r.max_amount === null ? null : Number(r.max_amount),
          approver_user_id: Number(r.approver_user_id),
        })).filter((r) => r.approver_user_id > 0),
      };
      if (workflow) {
        await api.patch(`/modules/ap/api/approval_workflows.php?id=${workflow.id}`, body);
      } else {
        await api.post('/modules/ap/api/approval_workflows.php', body);
      }
      onSaved();
    } catch (e) { setErr(e.message); }
    finally { setBusy(false); }
  };

  return (
    <div
      data-testid="ap-approvals-workflow-editor"
      style={{
        position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.4)',
        display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 50,
      }}
      onClick={onCancel}
    >
      <div onClick={(e) => e.stopPropagation()}
           style={{ background: '#fff', padding: 20, borderRadius: 8, minWidth: 600, maxWidth: 800, maxHeight: '90vh', overflowY: 'auto' }}>
        <h3 style={{ margin: '0 0 12px' }}>{workflow ? 'Edit workflow' : 'New workflow'}</h3>
        <input className="input" placeholder="Name (e.g. Standard bills)" value={name} onChange={(e) => setName(e.target.value)} data-testid="ap-approvals-editor-name" style={{ width: '100%', marginBottom: 8 }} />
        <label style={{ display: 'flex', alignItems: 'center', gap: 6, marginBottom: 12, fontSize: 12 }}>
          <input type="checkbox" checked={!!isDefault} onChange={(e) => setDefault(e.target.checked ? 1 : 0)} data-testid="ap-approvals-editor-default" />
          Use as default workflow (only one default per tenant)
        </label>
        <h4 style={{ margin: '8px 0', fontSize: 13 }}>Rules</h4>
        {rules.map((r, i) => (
          <div key={i} style={{ display: 'flex', gap: 6, marginBottom: 6, alignItems: 'center' }}>
            <input className="input" type="number" min="1" placeholder="Step" value={r.step_no} onChange={(e) => updateRule(i, { step_no: e.target.value })} style={{ width: 60 }} />
            <input className="input" type="number" step="0.01" placeholder="Min $" value={r.min_amount} onChange={(e) => updateRule(i, { min_amount: e.target.value })} style={{ width: 100 }} />
            <input className="input" type="number" step="0.01" placeholder="Max $ (blank = no cap)" value={r.max_amount ?? ''} onChange={(e) => updateRule(i, { max_amount: e.target.value })} style={{ width: 140 }} />
            <select className="input" value={r.approver_user_id || ''} onChange={(e) => updateRule(i, { approver_user_id: e.target.value })} style={{ flex: 1 }} data-testid={`ap-approvals-editor-rule-approver-${i}`}>
              <option value="">— Pick approver —</option>
              {users.map((u) => <option key={u.id} value={u.id}>{u.name || u.email}</option>)}
            </select>
            <button type="button" className="btn btn--ghost" onClick={() => removeRule(i)} style={{ padding: '2px 8px' }}>×</button>
          </div>
        ))}
        <button type="button" className="btn btn--ghost" onClick={addRule} data-testid="ap-approvals-editor-add-rule" style={{ marginBottom: 12, fontSize: 12 }}>
          + Add rule
        </button>

        {err && <p className="error">{err}</p>}
        <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
          <button type="button" className="btn btn--ghost"   onClick={onCancel}>Cancel</button>
          <button type="button" className="btn btn--primary" onClick={save} disabled={busy || !name} data-testid="ap-approvals-editor-save">
            {busy ? 'Saving…' : 'Save'}
          </button>
        </div>
      </div>
    </div>
  );
}
