import React, { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { ArrowLeft, Plus, Trash2, Edit3, Save, X, Receipt, CheckCircle2, Archive } from 'lucide-react';
import { api, useApi } from '../../../dashboard/src/lib/api';
import { fmtMoney } from '../../../dashboard/src/lib/format';
import { fmtDate } from '../../../dashboard/src/lib/formatDate';

/**
 * EngagementDetail — per-engagement page at /modules/engagements/:id.
 *
 *  - Editable header (client / project / dates / total fee / notes).
 *  - Full milestone editor (add / edit / delete / reorder).
 *  - Per-milestone "Invoice", "Mark paid", "Cancel" actions.
 *  - Archive button in the header.
 */
export default function EngagementDetail() {
  const { id } = useParams();
  const navigate = useNavigate();
  const detail = useApi(`/modules/engagements/api/detail.php?id=${id}`, [id]);
  const eg = detail.data?.engagement;

  if (detail.loading) return <div data-testid="engagement-detail-loading" style={{ padding: 24 }}>Loading…</div>;
  if (detail.error || !eg) {
    return (
      <div data-testid="engagement-detail-error" style={{ padding: 24, color: '#991b1b' }}>
        {detail.error || 'Engagement not found'}
      </div>
    );
  }

  return (
    <section className="page" data-testid="engagement-detail">
      <header style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 16 }}>
        <button
          type="button"
          onClick={() => navigate('/modules/engagements')}
          data-testid="engagement-detail-back"
          style={{ background: 'transparent', border: 'none', cursor: 'pointer', display: 'flex', alignItems: 'center', gap: 4, color: '#0f172a' }}
        ><ArrowLeft size={14} /> Back to Engagements</button>
      </header>

      <EngagementHeaderCard engagement={eg} onChanged={() => detail.reload?.()} />

      <MilestonesEditor
        engagement={eg}
        onChanged={() => detail.reload?.()}
      />
    </section>
  );
}

function EngagementHeaderCard({ engagement, onChanged }) {
  const [editing, setEditing] = useState(false);
  const [form, setForm] = useState({
    client_name:  engagement.client_name,
    project_name: engagement.project_name,
    description:  engagement.description || '',
    total_fee:    engagement.total_fee || 0,
    currency:     engagement.currency || 'USD',
    start_date:   engagement.start_date || '',
    end_date:     engagement.end_date || '',
    notes:        engagement.notes || '',
  });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);

  const isArchived = engagement.status === 'archived';

  const save = async () => {
    setBusy(true); setError(null);
    try {
      await api.patch(`/modules/engagements/api/detail.php?id=${engagement.id}`, form);
      setEditing(false);
      onChanged?.();
    } catch (e) { setError(e.message || 'Save failed'); }
    finally { setBusy(false); }
  };

  const archive = async () => {
    if (!window.confirm('Archive this engagement? It will become read-only.')) return;
    setBusy(true);
    try {
      await api.delete(`/modules/engagements/api/detail.php?id=${engagement.id}`);
      onChanged?.();
    } catch (e) { setError(e.message || 'Archive failed'); }
    finally { setBusy(false); }
  };

  const pct = engagement.total_fee > 0
    ? Math.min(100, (Number(engagement.invoiced_amount) / Number(engagement.total_fee)) * 100) : 0;
  const paidPct = engagement.total_fee > 0
    ? Math.min(100, (Number(engagement.paid_amount) / Number(engagement.total_fee)) * 100) : 0;

  return (
    <div data-testid="engagement-detail-header" style={{ background: '#fff', border: '1px solid #e2e8f0', borderRadius: 8, padding: 20, marginBottom: 20 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 12, marginBottom: 12 }}>
        <div style={{ flex: 1 }}>
          {editing ? (
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10 }}>
              <Field label="Client name">
                <input value={form.client_name} onChange={(e) => setForm({...form, client_name: e.target.value})} data-testid="engagement-edit-client" style={inputStyle} />
              </Field>
              <Field label="Project name">
                <input value={form.project_name} onChange={(e) => setForm({...form, project_name: e.target.value})} data-testid="engagement-edit-project" style={inputStyle} />
              </Field>
              <Field label="Total fee">
                <input type="number" step="0.01" value={form.total_fee} onChange={(e) => setForm({...form, total_fee: parseFloat(e.target.value) || 0})} data-testid="engagement-edit-totalfee" style={inputStyle} />
              </Field>
              <Field label="Currency">
                <input value={form.currency} onChange={(e) => setForm({...form, currency: e.target.value.toUpperCase()})} maxLength={3} data-testid="engagement-edit-currency" style={inputStyle} />
              </Field>
              <Field label="Start date">
                <input type="date" value={form.start_date || ''} onChange={(e) => setForm({...form, start_date: e.target.value})} data-testid="engagement-edit-startdate" style={inputStyle} />
              </Field>
              <Field label="End date">
                <input type="date" value={form.end_date || ''} onChange={(e) => setForm({...form, end_date: e.target.value})} data-testid="engagement-edit-enddate" style={inputStyle} />
              </Field>
              <div style={{ gridColumn: '1 / -1' }}>
                <Field label="Description (visible on invoices)">
                  <textarea value={form.description} onChange={(e) => setForm({...form, description: e.target.value})} data-testid="engagement-edit-description" rows={2} style={inputStyle} />
                </Field>
              </div>
              <div style={{ gridColumn: '1 / -1' }}>
                <Field label="Notes (internal)">
                  <textarea value={form.notes} onChange={(e) => setForm({...form, notes: e.target.value})} data-testid="engagement-edit-notes" rows={2} style={inputStyle} />
                </Field>
              </div>
            </div>
          ) : (
            <>
              <h1 data-testid="engagement-header-project" style={{ margin: 0, fontSize: 20 }}>{engagement.project_name}</h1>
              <p style={{ margin: '4px 0 0', color: '#6b7280', fontSize: 13 }}>
                <strong data-testid="engagement-header-client">{engagement.client_name}</strong>
                {engagement.start_date && (<> · {fmtDate(engagement.start_date)} → {engagement.end_date ? fmtDate(engagement.end_date) : '∞'}</>)}
              </p>
              {engagement.description && <p style={{ margin: '8px 0 0', fontSize: 13 }}>{engagement.description}</p>}
            </>
          )}
        </div>
        <div style={{ display: 'flex', gap: 8, alignItems: 'flex-start' }}>
          <StatusPill status={engagement.status} />
          {!isArchived && !editing && (
            <button type="button" onClick={() => setEditing(true)} data-testid="engagement-detail-edit" style={btnGhost}>
              <Edit3 size={12} /> Edit
            </button>
          )}
          {editing && (
            <>
              <button type="button" onClick={save} disabled={busy} data-testid="engagement-detail-save" style={btnPrimary}>
                <Save size={12} /> {busy ? 'Saving…' : 'Save'}
              </button>
              <button type="button" onClick={() => setEditing(false)} data-testid="engagement-detail-cancel-edit" style={btnGhost}>
                <X size={12} /> Cancel
              </button>
            </>
          )}
          {!isArchived && !editing && (
            <button type="button" onClick={archive} disabled={busy} data-testid="engagement-detail-archive" style={btnGhost}>
              <Archive size={12} /> Archive
            </button>
          )}
        </div>
      </div>

      {error && <div data-testid="engagement-detail-error-flash" style={{ padding: '6px 10px', borderRadius: 4, background: '#fee2e2', color: '#991b1b', fontSize: 12, marginBottom: 8 }}>{error}</div>}

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 12, fontSize: 13 }}>
        <Stat label="Total fee"     value={fmtMoney(engagement.total_fee,       engagement.currency)} testid="engagement-stat-total" />
        <Stat label="Invoiced"      value={fmtMoney(engagement.invoiced_amount, engagement.currency)} testid="engagement-stat-invoiced" />
        <Stat label="Paid"          value={fmtMoney(engagement.paid_amount,     engagement.currency)} testid="engagement-stat-paid" />
        <Stat label="Outstanding"   value={fmtMoney(Math.max(0, engagement.invoiced_amount - engagement.paid_amount), engagement.currency)} testid="engagement-stat-outstanding" />
      </div>

      <div style={{ marginTop: 12 }}>
        <div style={{ position: 'relative', height: 10, background: '#e5e7eb', borderRadius: 4, overflow: 'hidden' }}>
          <div style={{ position: 'absolute', inset: 0, width: `${pct}%`, background: '#fbbf24' }} />
          <div style={{ position: 'absolute', top: 0, left: 0, width: `${paidPct}%`, background: '#22c55e', height: '100%' }} />
        </div>
        <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 10, color: '#6b7280', marginTop: 4 }}>
          <span>{Math.round(pct)}% invoiced</span>
          <span>{Math.round(paidPct)}% paid</span>
        </div>
      </div>
    </div>
  );
}

function MilestonesEditor({ engagement, onChanged }) {
  const isArchived = engagement.status === 'archived';
  const [showAdd, setShowAdd] = useState(false);
  return (
    <div data-testid="engagement-detail-milestones" style={{ background: '#fff', border: '1px solid #e2e8f0', borderRadius: 8, padding: 16 }}>
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 }}>
        <h2 style={{ margin: 0, fontSize: 15, fontWeight: 700 }}>Milestones</h2>
        {!isArchived && (
          <button type="button" onClick={() => setShowAdd(!showAdd)} data-testid="engagement-detail-add-milestone" style={btnPrimary}>
            <Plus size={12} /> {showAdd ? 'Cancel' : 'Add milestone'}
          </button>
        )}
      </header>
      {showAdd && (
        <AddMilestoneRow engagementId={engagement.id} onClose={() => setShowAdd(false)} onCreated={() => { setShowAdd(false); onChanged?.(); }} />
      )}
      {engagement.milestones.length === 0 && !showAdd ? (
        <div data-testid="engagement-detail-milestones-empty" style={{ padding: 16, textAlign: 'center', color: '#6b7280', fontSize: 13 }}>
          No milestones yet. {!isArchived && 'Click "Add milestone" to scope the first deliverable.'}
        </div>
      ) : (
        <table className="data-table" style={{ width: '100%', fontSize: 13 }} data-testid="engagement-detail-milestones-table">
          <thead>
            <tr><th>#</th><th>Name</th><th style={{ textAlign: 'right' }}>Amount</th><th>Target</th><th>Status</th><th>Invoice</th><th style={{ textAlign: 'right' }}>Actions</th></tr>
          </thead>
          <tbody>
            {engagement.milestones.map((m) => (
              <MilestoneRow key={m.id} milestone={m} engagementId={engagement.id} archived={isArchived} onChanged={onChanged} />
            ))}
          </tbody>
        </table>
      )}
    </div>
  );
}

function MilestoneRow({ milestone, engagementId, archived, onChanged }) {
  const [editing, setEditing] = useState(false);
  const [form, setForm] = useState({
    name: milestone.name, amount: milestone.amount,
    target_date: milestone.target_date || '',
    description: milestone.description || '',
  });
  const [busy, setBusy] = useState(false);
  const [flash, setFlash] = useState(null);

  const patch = async (body) => {
    setBusy(true); setFlash(null);
    try {
      await api.patch(`/modules/engagements/api/milestones.php?id=${milestone.id}`, body);
      setEditing(false);
      onChanged?.();
    } catch (e) { setFlash({ kind: 'error', msg: e.message }); }
    finally { setBusy(false); }
  };

  const invoiceNow = async () => {
    if (!window.confirm(`Generate a draft invoice for "${milestone.name}" (${fmtMoney(milestone.amount, 'USD')})?`)) return;
    setBusy(true); setFlash(null);
    try {
      const res = await api.post(`/modules/engagements/api/invoice_milestone.php?milestone_id=${milestone.id}`);
      setFlash({ kind: 'success', msg: `Invoice ${res.invoice?.invoice_number} created.` });
      onChanged?.();
    } catch (e) { setFlash({ kind: 'error', msg: e.message }); }
    finally { setBusy(false); }
  };

  const canInvoice  = !archived && ['pending','ready_to_invoice'].includes(milestone.status) && Number(milestone.amount) > 0;
  const canMarkReady = !archived && milestone.status === 'pending';
  const canMarkPaid  = !archived && milestone.status === 'invoiced';
  const canCancel    = !archived && !['paid','cancelled'].includes(milestone.status);

  if (editing) {
    return (
      <tr data-testid={`milestone-edit-row-${milestone.id}`}>
        <td colSpan={7} style={{ background: '#f9fafb', padding: 10 }}>
          <div style={{ display: 'grid', gridTemplateColumns: '2fr 1fr 1fr', gap: 8 }}>
            <input value={form.name} onChange={(e) => setForm({...form, name: e.target.value})} placeholder="Name" data-testid={`milestone-edit-name-${milestone.id}`} style={inputStyle} />
            <input type="number" step="0.01" value={form.amount} onChange={(e) => setForm({...form, amount: parseFloat(e.target.value) || 0})} data-testid={`milestone-edit-amount-${milestone.id}`} style={inputStyle} />
            <input type="date" value={form.target_date} onChange={(e) => setForm({...form, target_date: e.target.value})} data-testid={`milestone-edit-date-${milestone.id}`} style={inputStyle} />
          </div>
          <textarea value={form.description} onChange={(e) => setForm({...form, description: e.target.value})} placeholder="Description" rows={2} data-testid={`milestone-edit-description-${milestone.id}`} style={{ ...inputStyle, marginTop: 8 }} />
          <div style={{ display: 'flex', gap: 6, justifyContent: 'flex-end', marginTop: 8 }}>
            <button type="button" onClick={() => setEditing(false)} data-testid={`milestone-edit-cancel-${milestone.id}`} style={btnGhost}><X size={12} /> Cancel</button>
            <button type="button" onClick={() => patch(form)} disabled={busy} data-testid={`milestone-edit-save-${milestone.id}`} style={btnPrimary}><Save size={12} /> {busy ? 'Saving…' : 'Save'}</button>
          </div>
        </td>
      </tr>
    );
  }

  return (
    <tr data-testid={`milestone-detail-row-${milestone.id}`}>
      <td>{milestone.sort_order + 1}</td>
      <td>
        <strong>{milestone.name}</strong>
        {milestone.description && <div style={{ fontSize: 10, color: '#9ca3af' }}>{milestone.description}</div>}
        {flash && <div style={{ marginTop: 4, padding: '2px 6px', borderRadius: 3, fontSize: 10, background: flash.kind === 'success' ? '#d1fae5' : '#fee2e2', color: flash.kind === 'success' ? '#065f46' : '#991b1b' }} data-testid={`milestone-flash-${milestone.id}`}>{flash.msg}</div>}
      </td>
      <td style={{ textAlign: 'right' }}>{fmtMoney(milestone.amount, 'USD')}</td>
      <td>{milestone.target_date ? fmtDate(milestone.target_date) : '—'}</td>
      <td><MilestoneStatusPill status={milestone.status} testid={`milestone-detail-status-${milestone.id}`} /></td>
      <td>{milestone.invoice_id ? <Link to={`/modules/billing/invoices/${milestone.invoice_id}`} data-testid={`milestone-detail-invoice-link-${milestone.id}`}>#{milestone.invoice_id}</Link> : '—'}</td>
      <td style={{ textAlign: 'right' }}>
        {!archived && (
          <button type="button" onClick={() => setEditing(true)} data-testid={`milestone-detail-edit-${milestone.id}`} style={{ ...smallBtn, marginRight: 4 }}><Edit3 size={11} /></button>
        )}
        {canMarkReady && (
          <button type="button" onClick={() => patch({ status: 'ready_to_invoice' })} disabled={busy} data-testid={`milestone-mark-ready-${milestone.id}`} style={{ ...smallBtn, marginRight: 4 }}>
            <CheckCircle2 size={11} /> Ready
          </button>
        )}
        {canInvoice && (
          <button type="button" onClick={invoiceNow} disabled={busy} data-testid={`milestone-detail-invoice-btn-${milestone.id}`} style={{ ...smallBtn, marginRight: 4 }}>
            <Receipt size={11} /> Invoice
          </button>
        )}
        {canMarkPaid && (
          <button type="button" onClick={() => patch({ status: 'paid' })} disabled={busy} data-testid={`milestone-detail-markpaid-${milestone.id}`} style={{ ...smallBtn, marginRight: 4 }}>
            <CheckCircle2 size={11} /> Paid
          </button>
        )}
        {canCancel && (
          <button type="button" onClick={() => { if (window.confirm('Cancel this milestone?')) patch({ status: 'cancelled' }); }} disabled={busy} data-testid={`milestone-detail-cancel-${milestone.id}`} style={smallBtn}>
            <Trash2 size={11} />
          </button>
        )}
      </td>
    </tr>
  );
}

function AddMilestoneRow({ engagementId, onClose, onCreated }) {
  const [form, setForm] = useState({ name: '', amount: 0, target_date: '', description: '' });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);

  const create = async () => {
    if (!form.name.trim()) { setError('Name required'); return; }
    setBusy(true); setError(null);
    try {
      await api.post(`/modules/engagements/api/milestones.php?engagement_id=${engagementId}`, {
        name: form.name.trim(),
        amount: parseFloat(form.amount) || 0,
        target_date: form.target_date || null,
        description: form.description || null,
      });
      onCreated?.();
    } catch (e) { setError(e.message); }
    finally { setBusy(false); }
  };

  return (
    <div data-testid="engagement-detail-milestone-add-row" style={{ background: '#f9fafb', padding: 12, marginBottom: 12, borderRadius: 6 }}>
      <div style={{ display: 'grid', gridTemplateColumns: '2fr 1fr 1fr auto', gap: 8 }}>
        <input value={form.name} onChange={(e) => setForm({...form, name: e.target.value})} placeholder="Milestone name" data-testid="engagement-detail-add-name" style={inputStyle} autoFocus />
        <input type="number" step="0.01" value={form.amount} onChange={(e) => setForm({...form, amount: e.target.value})} placeholder="Amount" data-testid="engagement-detail-add-amount" style={inputStyle} />
        <input type="date" value={form.target_date} onChange={(e) => setForm({...form, target_date: e.target.value})} data-testid="engagement-detail-add-date" style={inputStyle} />
        <button type="button" onClick={create} disabled={busy} data-testid="engagement-detail-add-submit" style={btnPrimary}>{busy ? '…' : 'Add'}</button>
      </div>
      <textarea value={form.description} onChange={(e) => setForm({...form, description: e.target.value})} placeholder="Description (optional)" rows={2} data-testid="engagement-detail-add-description" style={{ ...inputStyle, marginTop: 8 }} />
      {error && <div data-testid="engagement-detail-add-error" style={{ marginTop: 6, color: '#991b1b', fontSize: 11 }}>{error}</div>}
    </div>
  );
}

function Field({ label, children }) {
  return (
    <label style={{ display: 'block' }}>
      <span style={{ display: 'block', fontSize: 11, color: '#6b7280', marginBottom: 4 }}>{label}</span>
      {children}
    </label>
  );
}

function Stat({ label, value, testid }) {
  return (
    <div data-testid={testid} style={{ padding: 10, background: '#f8fafc', borderRadius: 6 }}>
      <div style={{ fontSize: 11, color: '#6b7280' }}>{label}</div>
      <div style={{ fontSize: 15, fontWeight: 700, marginTop: 2 }}>{value}</div>
    </div>
  );
}

function StatusPill({ status }) {
  const colors = {
    draft:     { bg: '#fef3c7', fg: '#92400e' },
    active:    { bg: '#dbeafe', fg: '#1e40af' },
    completed: { bg: '#d1fae5', fg: '#065f46' },
    archived:  { bg: '#f3f4f6', fg: '#6b7280' },
  }[status] || { bg: '#fef3c7', fg: '#92400e' };
  return (
    <span data-testid="engagement-detail-status" style={{ padding: '4px 12px', borderRadius: 999, fontSize: 11, fontWeight: 600, background: colors.bg, color: colors.fg }}>{status}</span>
  );
}

function MilestoneStatusPill({ status, testid }) {
  const colors = {
    pending:          { bg: '#fef3c7', fg: '#92400e' },
    ready_to_invoice: { bg: '#dbeafe', fg: '#1e40af' },
    invoiced:         { bg: '#e0e7ff', fg: '#3730a3' },
    paid:             { bg: '#d1fae5', fg: '#065f46' },
    cancelled:        { bg: '#f3f4f6', fg: '#6b7280' },
  }[status] || { bg: '#fef3c7', fg: '#92400e' };
  return (
    <span data-testid={testid} style={{ padding: '2px 8px', borderRadius: 999, fontSize: 10, fontWeight: 600, background: colors.bg, color: colors.fg }}>{status.replace(/_/g, ' ')}</span>
  );
}

const inputStyle = { width: '100%', padding: '6px 10px', borderRadius: 4, border: '1px solid #d1d5db', fontSize: 13, boxSizing: 'border-box' };
const btnPrimary = { padding: '5px 12px', borderRadius: 4, border: 'none', background: '#0f172a', color: '#fff', cursor: 'pointer', fontSize: 12, fontWeight: 600, display: 'inline-flex', alignItems: 'center', gap: 4 };
const btnGhost   = { padding: '5px 12px', borderRadius: 4, border: '1px solid #d1d5db', background: '#fff', color: '#374151', cursor: 'pointer', fontSize: 12, display: 'inline-flex', alignItems: 'center', gap: 4 };
const smallBtn   = { padding: '3px 8px', borderRadius: 4, border: '1px solid #d1d5db', background: '#fff', color: '#374151', cursor: 'pointer', fontSize: 11, fontWeight: 600, display: 'inline-flex', alignItems: 'center', gap: 3 };
