import React, { useEffect, useMemo, useState } from 'react';
import { Briefcase, Plus, X, Trash2 } from 'lucide-react';
import { api, useApi } from '../../../dashboard/src/lib/api';
import { fmtMoney } from '../../../dashboard/src/lib/format';
import { fmtDate } from '../../../dashboard/src/lib/formatDate';

/**
 * EngagementsList — fixed-fee project accounting.
 *
 * Drives `/modules/engagements/api/list.php` (GET + POST). Renders a
 * status tab strip on top + a sortable table of engagements with
 * progress bars (invoiced_amount / total_fee) and milestone counts.
 *
 * Create modal posts the engagement + initial milestones in a single
 * round-trip so operators can scope a whole project in one form.
 */
export default function EngagementsList() {
  const [status, setStatus]   = useState('all');
  const [showCreate, setShowCreate] = useState(false);

  const query = status === 'all' ? '' : `?status=${status}`;
  const list  = useApi(`/modules/engagements/api/list.php${query}`);
  const counts = list.data?.counts || {};
  const rows   = list.data?.rows   || [];

  return (
    <section className="page" data-testid="engagements-list">
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <div>
          <h2 style={{ display: 'flex', alignItems: 'center', gap: 10, margin: 0 }}>
            <Briefcase size={20} />
            Engagements
          </h2>
          <p style={{ color: '#6b7280', fontSize: 13, margin: '4px 0 0' }}>
            Fixed-fee projects with milestone-based revenue recognition.
          </p>
        </div>
        <button
          type="button"
          className="btn btn--primary"
          onClick={() => setShowCreate(true)}
          data-testid="engagements-create-btn"
        ><Plus size={14} /> New engagement</button>
      </header>

      <div data-testid="engagements-status-tabs" style={{ display: 'flex', gap: 4, marginBottom: 12, borderBottom: '1px solid #e5e7eb' }}>
        {[
          ['all',       'All',       (counts.draft || 0) + (counts.active || 0) + (counts.completed || 0) + (counts.archived || 0)],
          ['draft',     'Draft',     counts.draft],
          ['active',    'Active',    counts.active],
          ['completed', 'Completed', counts.completed],
          ['archived',  'Archived',  counts.archived],
        ].map(([key, label, n]) => (
          <button
            key={key}
            type="button"
            onClick={() => setStatus(key)}
            data-testid={`engagements-tab-${key}`}
            style={{
              padding: '8px 14px', border: 'none',
              borderBottom: '2px solid ' + (status === key ? '#0f172a' : 'transparent'),
              background: 'transparent', color: status === key ? '#0f172a' : '#6b7280',
              cursor: 'pointer', fontSize: 13, fontWeight: 600,
            }}
          >{label} {Number.isFinite(n) ? <span style={{ color: '#9ca3af', fontWeight: 400 }}>({n})</span> : null}</button>
        ))}
      </div>

      {list.loading && <p data-testid="engagements-loading">Loading…</p>}
      {list.error && <p data-testid="engagements-error" style={{ color: '#991b1b' }}>{list.error}</p>}

      {!list.loading && rows.length === 0 && (
        <div data-testid="engagements-empty" style={{ padding: 32, textAlign: 'center', color: '#6b7280', fontSize: 14 }}>
          No engagements {status !== 'all' ? `in ${status}` : 'yet'}. Click <strong>New engagement</strong> to create one.
        </div>
      )}

      {rows.length > 0 && (
        <table className="data-table" data-testid="engagements-table" style={{ width: '100%' }}>
          <thead>
            <tr>
              <th>#</th>
              <th>Client</th>
              <th>Project</th>
              <th style={{ textAlign: 'right' }}>Total fee</th>
              <th style={{ textAlign: 'right' }}>Invoiced</th>
              <th style={{ textAlign: 'right' }}>Paid</th>
              <th>Progress</th>
              <th>Status</th>
              <th>Updated</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => (
              <EngagementRow key={r.id} row={r} onChanged={() => list.reload?.()} />
            ))}
          </tbody>
        </table>
      )}

      {showCreate && (
        <EngagementCreateModal
          onClose={() => setShowCreate(false)}
          onCreated={() => { setShowCreate(false); list.reload?.(); }}
        />
      )}
    </section>
  );
}

function EngagementRow({ row, onChanged }) {
  const pct = row.total_fee > 0 ? Math.min(100, (Number(row.invoiced_amount) / Number(row.total_fee)) * 100) : 0;
  const paidPct = row.total_fee > 0 ? Math.min(100, (Number(row.paid_amount) / Number(row.total_fee)) * 100) : 0;
  return (
    <tr data-testid={`engagement-row-${row.id}`}>
      <td>{row.id}</td>
      <td>{row.client_name}</td>
      <td>{row.project_name}</td>
      <td style={{ textAlign: 'right' }}>{fmtMoney(row.total_fee, row.currency)}</td>
      <td style={{ textAlign: 'right' }}>{fmtMoney(row.invoiced_amount, row.currency)}</td>
      <td style={{ textAlign: 'right' }}>{fmtMoney(row.paid_amount, row.currency)}</td>
      <td style={{ minWidth: 140 }}>
        <div style={{ position: 'relative', height: 8, background: '#e5e7eb', borderRadius: 4, overflow: 'hidden' }}>
          <div style={{
            position: 'absolute', inset: 0, width: `${pct}%`,
            background: '#fbbf24',
          }} />
          <div style={{
            position: 'absolute', top: 0, left: 0, width: `${paidPct}%`,
            background: '#22c55e', height: '100%',
          }} />
        </div>
        <span style={{ fontSize: 10, color: '#6b7280' }}>{Math.round(pct)}% invoiced · {Math.round(paidPct)}% paid</span>
      </td>
      <td>
        <span
          data-testid={`engagement-status-${row.id}`}
          style={{
            padding: '2px 8px', borderRadius: 999, fontSize: 11, fontWeight: 600,
            background: statusColor(row.status).bg, color: statusColor(row.status).fg,
          }}
        >{row.status}</span>
      </td>
      <td style={{ fontSize: 11, color: '#6b7280' }}>{fmtDate(row.updated_at)}</td>
    </tr>
  );
}

function statusColor(status) {
  switch (status) {
    case 'active':    return { bg: '#dbeafe', fg: '#1e40af' };
    case 'completed': return { bg: '#d1fae5', fg: '#065f46' };
    case 'archived':  return { bg: '#f3f4f6', fg: '#6b7280' };
    default:          return { bg: '#fef3c7', fg: '#92400e' };
  }
}

function EngagementCreateModal({ onClose, onCreated }) {
  const [client, setClient]    = useState('');
  const [project, setProject]  = useState('');
  const [totalFee, setTotalFee] = useState('');
  const [currency, setCurrency] = useState('USD');
  const [milestones, setMilestones] = useState([{ name: '', amount: '', target_date: '' }]);
  const [busy, setBusy]    = useState(false);
  const [error, setError]  = useState(null);

  const computedTotal = useMemo(() => {
    return milestones.reduce((s, m) => s + (parseFloat(m.amount) || 0), 0);
  }, [milestones]);

  const addMilestone = () => setMilestones([...milestones, { name: '', amount: '', target_date: '' }]);
  const removeMilestone = (idx) => setMilestones(milestones.filter((_, i) => i !== idx));
  const updateMilestone = (idx, key, val) => {
    const next = [...milestones];
    next[idx] = { ...next[idx], [key]: val };
    setMilestones(next);
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError(null);
    if (!client.trim() || !project.trim()) {
      setError('Client and project name are required.');
      return;
    }
    setBusy(true);
    try {
      const validMs = milestones
        .filter((m) => (m.name || '').trim().length > 0)
        .map((m) => ({
          name: m.name.trim(),
          amount: parseFloat(m.amount) || 0,
          target_date: m.target_date || null,
        }));
      await api.post('/modules/engagements/api/list.php', {
        client_name:  client.trim(),
        project_name: project.trim(),
        total_fee:    parseFloat(totalFee) || computedTotal,
        currency,
        milestones:   validMs,
      });
      onCreated();
    } catch (err) {
      setError(err.message || 'Create failed');
    } finally {
      setBusy(false);
    }
  };

  return (
    <div
      data-testid="engagements-create-modal-backdrop"
      onClick={onClose}
      style={{ position: 'fixed', inset: 0, background: 'rgba(15,23,42,0.5)',
               display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 50 }}
    >
      <div
        data-testid="engagements-create-modal"
        onClick={(e) => e.stopPropagation()}
        style={{ background: '#fff', borderRadius: 8, padding: 24, width: 560, maxWidth: '92vw',
                 maxHeight: '92vh', overflowY: 'auto',
                 boxShadow: '0 24px 56px rgba(15,23,42,0.18)' }}
      >
        <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 }}>
          <h3 style={{ margin: 0, fontSize: 18 }}>New engagement</h3>
          <button type="button" onClick={onClose} data-testid="engagements-create-cancel"
                  style={{ background: 'transparent', border: 'none', cursor: 'pointer', padding: 4 }}>
            <X size={16} />
          </button>
        </header>

        <form onSubmit={handleSubmit}>
          <Row>
            <Field label="Client name" required>
              <input
                value={client}
                onChange={(e) => setClient(e.target.value)}
                data-testid="engagements-create-client"
                required
                style={inputStyle}
              />
            </Field>
            <Field label="Project name" required>
              <input
                value={project}
                onChange={(e) => setProject(e.target.value)}
                data-testid="engagements-create-project"
                required
                style={inputStyle}
              />
            </Field>
          </Row>

          <Row>
            <Field label={`Total fee (auto-fills from milestones: ${computedTotal.toFixed(2)})`}>
              <input
                type="number"
                step="0.01"
                value={totalFee}
                placeholder={computedTotal.toFixed(2)}
                onChange={(e) => setTotalFee(e.target.value)}
                data-testid="engagements-create-totalfee"
                style={inputStyle}
              />
            </Field>
            <Field label="Currency">
              <input
                value={currency}
                onChange={(e) => setCurrency(e.target.value.toUpperCase())}
                maxLength={3}
                data-testid="engagements-create-currency"
                style={inputStyle}
              />
            </Field>
          </Row>

          <fieldset style={{ marginTop: 12, padding: 12, border: '1px solid #e5e7eb', borderRadius: 6 }}>
            <legend style={{ padding: '0 6px', fontSize: 13, fontWeight: 600 }}>Milestones</legend>
            {milestones.map((m, idx) => (
              <div key={idx} data-testid={`engagements-create-milestone-${idx}`}
                   style={{ display: 'grid', gridTemplateColumns: '2fr 1fr 1fr auto', gap: 8, marginBottom: 8 }}>
                <input
                  placeholder="Milestone name"
                  value={m.name}
                  onChange={(e) => updateMilestone(idx, 'name', e.target.value)}
                  data-testid={`engagements-create-milestone-name-${idx}`}
                  style={inputStyle}
                />
                <input
                  type="number"
                  step="0.01"
                  placeholder="Amount"
                  value={m.amount}
                  onChange={(e) => updateMilestone(idx, 'amount', e.target.value)}
                  data-testid={`engagements-create-milestone-amount-${idx}`}
                  style={inputStyle}
                />
                <input
                  type="date"
                  value={m.target_date || ''}
                  onChange={(e) => updateMilestone(idx, 'target_date', e.target.value)}
                  data-testid={`engagements-create-milestone-date-${idx}`}
                  style={inputStyle}
                />
                <button
                  type="button"
                  onClick={() => removeMilestone(idx)}
                  disabled={milestones.length === 1}
                  data-testid={`engagements-create-milestone-remove-${idx}`}
                  style={{ background: 'transparent', border: 'none', cursor: 'pointer',
                           opacity: milestones.length === 1 ? 0.4 : 1 }}
                ><Trash2 size={14} /></button>
              </div>
            ))}
            <button
              type="button"
              onClick={addMilestone}
              data-testid="engagements-create-milestone-add"
              style={{ ...btnGhost, fontSize: 12, padding: '4px 10px' }}
            ><Plus size={12} /> Add milestone</button>
          </fieldset>

          {error && (
            <div data-testid="engagements-create-error"
                 style={{ padding: '6px 10px', borderRadius: 6, background: '#fee2e2',
                          color: '#991b1b', fontSize: 12, marginTop: 12 }}>{error}</div>
          )}

          <footer style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 16 }}>
            <button type="button" onClick={onClose} data-testid="engagements-create-cancel-2"
                    style={btnGhost}>Cancel</button>
            <button type="submit" disabled={busy} data-testid="engagements-create-submit"
                    style={{ ...btnPrimary, opacity: busy ? 0.6 : 1 }}>
              {busy ? 'Creating…' : 'Create engagement'}
            </button>
          </footer>
        </form>
      </div>
    </div>
  );
}

function Row({ children }) { return <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>{children}</div>; }
function Field({ label, required, children }) {
  return (
    <label style={{ display: 'block', marginBottom: 10 }}>
      <span style={{ display: 'block', fontSize: 12, fontWeight: 600, marginBottom: 4 }}>
        {label} {required && <span style={{ color: '#ef4444' }}>*</span>}
      </span>
      {children}
    </label>
  );
}

const inputStyle = {
  width: '100%', padding: '6px 10px', borderRadius: 6,
  border: '1px solid #d1d5db', fontSize: 13, boxSizing: 'border-box',
};
const btnPrimary = {
  padding: '8px 16px', borderRadius: 6, border: 'none',
  background: '#0f172a', color: '#fff', cursor: 'pointer', fontWeight: 600, fontSize: 13,
};
const btnGhost = {
  padding: '8px 16px', borderRadius: 6,
  border: '1px solid #d1d5db', background: '#fff', color: '#374151', cursor: 'pointer', fontSize: 13,
  display: 'inline-flex', alignItems: 'center', gap: 4,
};
